"""Face enrollment and verification.

Everything here runs on this server — no cloud API ever sees a student's face.

Why a burst of frames and not one photo: a printed photo, a face on another
phone's screen, or a pre-recorded video all defeat single-shot matching. The
server picks a random pose sequence per attempt (e.g. turn → centre → the other
side), and the burst must perform it step by step, in order, in the frames that
belong to each step. A still image cannot turn its head, a fixed pair of photos
cannot satisfy a sequence it does not know, and a video recorded earlier cannot
know the sequence chosen a second ago. It raises the bar against casual replay
only: a modified client that fabricates frames to order from a few photos of
the student can still satisfy any sequence (see the RUNBOOK's threat model).

Two challenge protocols exist (marking.liveness_protocol picks one per student;
the choice is stored with the challenge, so an attempt is always judged by the
protocol it was issued under):
  v1 — centre / turn either way / turn the OTHER way, judged on the model's yaw
  v2 — centre, then LEFT, RIGHT and a BLINK in random order; sides are named
       and judged from keypoint geometry, the blink from the eye aspect ratio
       (see "Liveness v2" below)

Layers, in order of what they stop:
  quality      — too dark / too far / no face / more than one face
  pose         — the random challenge sequence must actually be performed
  texture      — screen-replay detection (moire, flat specular response)
  identity     — EVERY usable frame must match the student's enrolled template,
                 and all frames must be the same person as each other
  cross-match  — no frame may resemble a *different* enrolled student more; this
                 is what catches "A holding B's phone and using A's own face"
"""

from __future__ import annotations

import asyncio
import functools
import itertools
import json
import logging
import os
import secrets
import threading
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager
from dataclasses import dataclass, field
from typing import Sequence

# ---------------------------------------------------------------------------
# Upload limits. A crafted JPEG only a few KB long can declare a 65535x65535
# frame; decoding that allocates ~12 GB and takes the whole backend down. The
# phone sends frames at most 720 px wide (attendance.js grabFrame), so these
# leave generous headroom while keeping one decoded frame under ~9 MB.
# ---------------------------------------------------------------------------
MAX_FRAME_BYTES = 3_000_000
MAX_FRAME_SIDE = 1920
MAX_FRAME_PIXELS = 1920 * 1440

# OpenCV's own ceiling, as a second line behind probe_image_size(). It is read
# once when cv2 loads, so it has to be in the environment before the import.
os.environ.setdefault("OPENCV_IO_MAX_IMAGE_PIXELS", str(MAX_FRAME_PIXELS))

import cv2  # noqa: E402
import numpy as np  # noqa: E402

from .config import get_settings  # noqa: E402

log = logging.getLogger(__name__)

# Pose labels recorded for each analysed frame (evidence/forensics).
POSE_CENTRE, POSE_LEFT, POSE_RIGHT = "centre", "left", "right"

# What a challenge may ASK for:
#   centre — look straight at the camera
#   turn   — turn to either side (the first turn of the sequence)
#   other  — turn to the side OPPOSITE the previous turn
#
# Asking for a named side does not work: whether a given physical direction
# reads as +yaw or -yaw depends on the un-mirrored camera image and the model's
# Euler convention, so "turn LEFT" was scored as 'right' and 78% of real
# attempts failed POSE_CHALLENGE_FAILED (attempts 4-8 in production). 'other' is
# relative — the opposite sign of the turn already performed in this burst — so
# it is immune to that convention, yet a burst must still show BOTH sides.
POSE_TURN = "turn"
POSE_OTHER = "other"
CHALLENGE_STEPS = (POSE_CENTRE, POSE_TURN, POSE_OTHER)
YAW_TURN_MIN = 15.0     # a turn must be at least this pronounced
YAW_CENTRE_MAX = 12.0   # ...and 'centre' must be genuinely centred

# The client takes exactly this many frames per challenge step (a pair, a beat
# apart), and frame i belongs to step i // FRAMES_PER_STEP. Pinning the count
# is what lets the server check each step in ITS OWN frames: with a free-form
# burst, one pre-made set of photos (centre, left, centre, right, …) contains
# every possible sequence as a subsequence and the randomness buys nothing.
FRAMES_PER_STEP = 2

# A human needs a moment per step to move and hold still; anything quicker is a
# script posting frames it already had. Deliberately lenient (the live guide
# can capture an already-held pose in well under a second).
MIN_SECONDS_PER_STEP = 0.4

# Every pair of usable frames in one burst must be at least this similar. The
# same face across a ±30° head turn sits well above 0.5 with ArcFace; two
# different people rarely clear 0.3. Tunable via att_settings.
DEFAULT_CONSISTENCY_THRESHOLD = 0.35

# ---------------------------------------------------------------------------
# Liveness v2: named sides judged from geometry, and a blink
# ---------------------------------------------------------------------------
#
# v1 could not name a side because the model's yaw SIGN depends on conventions
# (see YAW_TURN_MIN above). v2 names it — "turn your head to your LEFT" — and
# judges it from plain keypoint geometry instead, which has no convention:
#
#   The frames are the RAW camera image: attendance.js draws the video into a
#   canvas, and only the on-screen preview is mirrored (by CSS). A person facing
#   the camera has their LEFT on the image's RIGHT. Turning the head to their
#   left swings the nose tip towards their left — the image's right — while the
#   eyes stay either side of it. So the nose tip's offset from the eye
#   midpoint, measured along the line from the image-left eye to the image-right
#   eye and divided by the eye distance, is POSITIVE for the student's LEFT and
#   NEGATIVE for their RIGHT (turn_ratio below).
#
#   Measuring along the eye line rather than the image's x axis makes it immune
#   to head roll: a turned AND tilted face (Monica in insightface's t1.jpg —
#   yaw -37°, roll -29°) reads -0.33 along the eye line but -0.04, "centre", on
#   the x axis. The eyes are ordered by image position, not by which keypoint
#   the detector calls "left", so not even the keypoint order is a convention.
#
#   Verified on a real photograph, not reasoned alone: Joey in t1.jpg has
#   clearly turned to HIS right (nose towards the image's left, his left ear in
#   view); the detector's keypoints give -0.47 (right) and the model's yaw
#   -55° — the sign v1's _pose_label would have called "left". Pinned by
#   tests/test_liveness_v2.py.
#
# Scale: the nose tip sits ~0.4 eye-distances in front of the eyes, so a head
# turned by θ reads ≈ 0.4·tan θ. Measured against the landmark model's yaw on
# the six faces of t1.jpg and their mirror images: 0.31–0.57 · tan θ. The v1
# limits therefore translate to 0.4·tan 15° ≈ 0.11 and 0.4·tan 12° ≈ 0.085.
# The phone's guide (public/js/liveness.js) computes the same ratio from its own
# (MediaPipe) landmarks. On the same t1.jpg crops it agreed in sign on all 12
# faces and ran 1.15-1.6x larger, ±0.04 near zero, so the phone only captures
# at turn ≥ 0.24 and centre ≤ 0.06 in its units — inside these with margin.
POSE_BLINK = "blink"
POSE_FLASH = "flash"
TURN_RATIO_MIN = 0.11
CENTRE_RATIO_MAX = 0.085

# --- blink ---
# A blink step uploads two frames: eyes closed (the phone keeps the very frame
# its tracker judged shut, at the peak of the blink) and eyes open just after.
#
# The server judges them with the SAME model the phone used: MediaPipe's Face
# Landmarker (face_landmarker.task, IMAGE mode, CPU), whose eyeBlinkLeft /
# eyeBlinkRight blendshapes are 0 for a wide-open eye and approach 1 shut. On
# the frames the browser harness uploaded, the server's scores matched the
# phone's to within 0.03 (shut 0.64/0.52, open 0.21/0.10); looking down at a
# phone can lift an open eye to ~0.4, squinting to ~0.6. So, on the mean of
# both eyes (att_settings in brackets):
#   seen      closed >= 0.45 (blink_closed_min), open <= 0.40 (blink_open_max)
#             and closed - open >= 0.25 (blink_min_diff)
#   not seen  closed < 0.30 and within 0.10 of open: the "shut" frame is as
#             open as the open one — a hard BLINK_CHALLENGE_FAILED. The phone
#             only ever sends, as the shut one, the exact frame its tracker
#             scored >= 0.50 (and 0.30 above the student's open eyes), so a
#             real blink cannot land here through capture timing.
#   unclear   anything else (office review, unless the identity match clears
#             review_skip_match — see judge_burst)
# Production attempt #41 (pilot) is why: its "shut" frame was not shut, and the
# old measures below could not say so either way.
DEFAULT_BLINK_CLOSED_MIN = 0.45
DEFAULT_BLINK_OPEN_MAX = 0.40
DEFAULT_BLINK_MIN_DIFF = 0.25
BLINK_NOT_SEEN_MAX = 0.30
BLINK_NOT_SEEN_DIFF = 0.10

# Fallback when the mediapipe package or its model is missing: two measures of
# the closed frame compared with the student's OWN open eyes in this burst —
# relative, because open eyes differ a lot between people, and with glasses,
# squints and smiles:
#   ear  — eye aspect ratio from the 68 landmarks (lid height / eye width).
#   dark — the fraction of the eye box darker than the cheek below it: the
#          iris and pupil when open, only the lash line when shut.
# The landmark model barely sees eyelids. On insightface's t1.jpg, faces with
# their eyes painted shut kept 78-98% of their open EAR, and two people
# squinting read like open eyes; the dark fraction fell to 21-57% of open. It
# can also RISE: a shut eye with a heavy lash line, eyeliner or dark lids is
# darker than the open one (a test edit with dark-painted lids read 1.9x). So
# the dark fraction counts as changed in either direction. Either measure
# changing clearly counts as a blink, both staying put is a clear "no blink"
# (fail), and anything between goes to office review rather than failing a
# student on a measure this model cannot make well.
BLINK_EAR_CLOSED = 0.72          # seen: closed EAR <= 72% of the open EAR, or
BLINK_DARK_CHANGE = 0.60         #       dark fraction <= 60% of open, or >= 1/0.60 of it
BLINK_EAR_OPEN = 0.92            # not seen: EAR >= 92% of open AND dark within
BLINK_DARK_SAME = 0.85           #           85%..1/0.85 of open

# --- colour flash (screen illumination) — OFF by default ---
# Taken out of the marking sequence after the pilot (it added ~3 s, and the
# office light left it "too weak to judge": attempt #41 had correlation 0.90
# but signal 0.0077). It stays here, dormant, behind att_settings
# flash_enabled='1' — which adds it back as one more randomly placed step.
#
# A looping or live-relayed video of the student can perform turns and blinks
# on cue. It cannot make the student's face reflect colours the phone's screen
# shows at random a moment ago. During the flash step the phone fills the
# screen with FLASH_COUNT server-chosen colours, each for a server-chosen
# 340-500 ms (under 3 changes a second: WCAG 2.3.1's flash limit), and uploads
# one frame per colour. check_flash() verifies the skin's colour shift from one
# flash to the next follows the commanded shift. The palette is six strongly
# different hues; the sequence never repeats a colour back to back.
#
# Seven colours because chance agreement falls with every frame: over 20,000
# random draws, a face following the WRONG sequence (or pure noise) reached the
# 0.75 correlation threshold 1.5% (0.7%) of the time with seven colours, 2.3%
# (1.3%) with six. A face following the right one scored 0.79-1.00 in the
# synthetic tests (tests/test_liveness_v2.py), even with the camera's white
# balance jumping ±10% per channel between frames. ~3 s in all.
FLASH_COUNT = 7
FLASH_MIN_MS, FLASH_MAX_MS = 340, 500
FLASH_PALETTE = {                # letter -> sRGB the phone paints
    "R": (255, 48, 48), "G": (48, 220, 72), "B": (48, 96, 255),
    "Y": (255, 214, 10), "M": (232, 48, 232), "C": (32, 216, 240),
}
# Defaults for the att_settings knobs (marking.thresholds reads them):
# Synthetic frames: a face lit by 5% of the screen's colour moves ~0.012-0.017
# (RMS of log chromaticity between flashes), white-balance jitter alone up to
# ~0.007 — so below 0.008 the response is too weak to tell from the camera
# adjusting itself. Real values from the office are in each attempt's detail.
DEFAULT_FLASH_MIN_SIGNAL = 0.008       # below this: too weak to judge -> review
DEFAULT_FLASH_MIN_CORRELATION = 0.75   # pass needs at least this correlation
DEFAULT_ANTISPOOF_REVIEW = 0.90        # MiniFASNet spoof probability -> office review

# An attempt held back ONLY for a soft liveness reason (blink unclear, flash
# too weak, the anti-spoof model's suspicion) whose identity match is at least
# this goes through instead of to review. 0.65 is far above the 0.42 match
# threshold — two different people essentially never score it — and the hard
# checks (named side, a definite no-blink, cross-match, mixed faces, replay,
# timing) have all passed by then. Pilot attempt #41 matched at 0.89.
DEFAULT_REVIEW_SKIP_MATCH = 0.65
SOFT_REVIEW_REASONS = ("blink_unclear", "flash_weak", "flash_incomplete", "antispoof")

LIVENESS_V1, LIVENESS_V2 = 1, 2
V2_PREFIX = "v2:"
V2_STEPS = (POSE_CENTRE, POSE_LEFT, POSE_RIGHT, POSE_BLINK, POSE_FLASH)
# What a v2 challenge can be: 'centre' first (an easy start, and the
# identity/open-eye anchor), then ONE named turn — left or right, drawn at
# random — and a blink, in a random order: 4 sequences, 6 frames, a few
# seconds for a person. The pilot asked for exactly this ("randomly ask the
# user to blink and turn to a specific direction once"); the first version
# also asked for the other side and a colour flash and took too long.
# With flash_enabled='1' the flash joins as a third randomly placed step (12).
SEQUENCES_V2: tuple[tuple[str, ...], ...] = tuple(
    (POSE_CENTRE,) + rest
    for side in (POSE_LEFT, POSE_RIGHT)
    for rest in itertools.permutations((side, POSE_BLINK))
)
SEQUENCES_V2_FLASH: tuple[tuple[str, ...], ...] = tuple(
    (POSE_CENTRE,) + rest
    for side in (POSE_LEFT, POSE_RIGHT)
    for rest in itertools.permutations((side, POSE_BLINK, POSE_FLASH))
)

_rng = secrets.SystemRandom()

_model_lock = threading.Lock()
_model = None


def get_model():
    """Lazily load InsightFace once per process (~1s, ~400MB RSS)."""
    global _model
    if _model is None:
        with _model_lock:
            if _model is None:
                from insightface.app import FaceAnalysis

                s = get_settings()
                # Load ONLY the modules we actually read. By default buffalo_l
                # also loads genderage and landmark_2d_106, which nothing here
                # uses — five resident ONNX models plus their inference arenas
                # pushed a multi-frame enrollment past the container's memory
                # limit, and Docker killed the process mid-request (the student
                # saw "service is not responding").
                #   detection      -> bbox + kps        (quality, framing)
                #   landmark_3d_68 -> face.pose         (the yaw the gate uses)
                #   recognition    -> normed_embedding  (identity, cross-match)
                app = FaceAnalysis(
                    name=s.model_name,
                    root=s.model_root,
                    providers=["CPUExecutionProvider"],
                    allowed_modules=["detection", "landmark_3d_68", "recognition"],
                )
                app.prepare(ctx_id=-1, det_size=(s.det_size, s.det_size))
                _model = app
                log.info("face model %s ready", s.model_name)
    return _model


# ---------------------------------------------------------------------------
# Running the engine without stalling the web server
# ---------------------------------------------------------------------------
#
# Detection + landmarks + embedding is ~0.2-0.5 s of CPU per frame. Run inline
# in an async handler it froze the event loop, so during the 09:00 rush every
# request on the worker — netcheck, the office dashboard, healthz — waited
# behind whoever was being verified. The work now runs on a small dedicated
# pool; waiting for a slot happens on the event loop (costs no thread), and a
# request that cannot get one within the queue timeout is told to retry rather
# than piling up behind the PHP forwarder's 120 s curl timeout.

class EngineBusy(Exception):
    """No face-engine slot freed up within the queue timeout."""


_engine_pool: ThreadPoolExecutor | None = None
_engine_slots: asyncio.Semaphore | None = None


def _engine() -> tuple[ThreadPoolExecutor, asyncio.Semaphore]:
    # Only ever called from the event loop thread, so no lock is needed.
    global _engine_pool, _engine_slots
    if _engine_pool is None or _engine_slots is None:
        workers = max(1, int(get_settings().face_workers))
        _engine_pool = ThreadPoolExecutor(max_workers=workers, thread_name_prefix="face")
        _engine_slots = asyncio.Semaphore(workers)
    return _engine_pool, _engine_slots


@asynccontextmanager
async def engine_slot(timeout: float | None = None):
    """Hold one face-engine slot for the body of the `async with`."""
    _, slots = _engine()
    wait = get_settings().face_queue_timeout if timeout is None else timeout
    try:
        async with asyncio.timeout(wait):
            await slots.acquire()
    except TimeoutError:
        raise EngineBusy() from None
    try:
        yield
    finally:
        slots.release()


async def run_in_engine(fn, *args, **kwargs):
    """Run CPU-bound face work on the engine pool (call inside engine_slot)."""
    pool, _ = _engine()
    loop = asyncio.get_running_loop()
    return await loop.run_in_executor(pool, functools.partial(fn, *args, **kwargs))


# ---------------------------------------------------------------------------
# Challenge
# ---------------------------------------------------------------------------

def _valid_sequence(steps: tuple[str, ...]) -> bool:
    turns = [s for s in steps if s != POSE_CENTRE]
    return (POSE_CENTRE in steps and len(turns) >= 2 and turns[0] == POSE_TURN
            and all(t == POSE_OTHER for t in turns[1:])
            and not any(a == b == POSE_CENTRE for a, b in zip(steps, steps[1:])))


# Every sequence a challenge can be: 3 or 4 steps, containing a centre step and
# both sides (a 'turn', then 'other'), never two centres in a row. Drawn
# UNIFORMLY from this list — building a sequence step by step made some
# sequences 16% likely, which is a better bet for anyone re-rolling challenges.
SEQUENCES: tuple[tuple[str, ...], ...] = tuple(
    seq for n in (3, 4) for seq in itertools.product(CHALLENGE_STEPS, repeat=n)
    if _valid_sequence(seq)
)


def new_challenge(rng=None) -> list[str]:
    """A fresh random pose sequence for one marking attempt, e.g. [turn,
    centre, other] or [centre, turn, other, centre], chosen with a CSPRNG.

    Replay is blocked by the single-use, short-lived challenge token bound to
    the student and device, and re-rolling for an easy sequence by the daily
    and per-minute issue caps (marking.issue_challenge). The sequence stops a
    fixed pair of photos or a recorded clip. It does NOT stop a client that
    fabricates frames to order — see the RUNBOOK's threat model.
    """
    return list((rng or _rng).choice(SEQUENCES))


def parse_challenge(spec: str) -> list[str] | None:
    """The stored comma-joined sequence, or None if it is not one we issue."""
    steps = [s for s in (spec or "").split(",") if s]
    if not steps or any(s not in CHALLENGE_STEPS for s in steps):
        return None
    return steps


@dataclass(frozen=True)
class Challenge:
    """One issued challenge: its protocol, its steps and, for a v2 flash step,
    the colours (FLASH_PALETTE letters) and how long each is shown."""
    protocol: int
    steps: tuple[str, ...]
    flash_colors: tuple[str, ...] = ()
    flash_ms: tuple[int, ...] = ()

    def frame_counts(self) -> list[int]:
        """Frames the phone sends for each step, in step order: a pair for a
        pose or a blink, one per colour for the flash."""
        return [len(self.flash_colors) if s == POSE_FLASH else FRAMES_PER_STEP for s in self.steps]

    def step_of_frame(self) -> list[int]:
        """Which step each uploaded frame belongs to (frames arrive in order)."""
        return [i for i, n in enumerate(self.frame_counts()) for _ in range(n)]

    def min_seconds(self) -> float:
        """The least time a person could take: the per-step floor for every
        pose/blink step plus most of the flash's own display time."""
        poses = sum(1 for s in self.steps if s != POSE_FLASH)
        return MIN_SECONDS_PER_STEP * poses + 0.8 * sum(self.flash_ms) / 1000.0

    def spec(self) -> str:
        """How it is stored (att_challenges.challenge, att_attempts.
        challenge_spec — both VARCHAR(100)). v1 keeps its historical bare form,
        so rows written before v2 read back unchanged; v2 is prefixed, so the
        protocol an attempt was issued under travels with it, e.g.
        v2:centre,flash,left,blink,right|RGBYMCR|380,450,410,500,360,470,340
        (68 characters at most)."""
        body = ",".join(self.steps)
        if self.protocol != LIVENESS_V2:
            return body
        if POSE_FLASH in self.steps:
            body += "|" + "".join(self.flash_colors) + "|" + ",".join(str(ms) for ms in self.flash_ms)
        return V2_PREFIX + body

    def flash_rgb(self) -> list[tuple[int, int, int]]:
        return [FLASH_PALETTE[c] for c in self.flash_colors]


def new_flash(rng=None) -> tuple[tuple[str, ...], tuple[int, ...]]:
    """FLASH_COUNT palette colours, never the same twice in a row, each shown
    for a random FLASH_MIN_MS..FLASH_MAX_MS (10 ms steps)."""
    rng = rng or _rng
    letters = sorted(FLASH_PALETTE)
    colors: list[str] = []
    while len(colors) < FLASH_COUNT:
        c = rng.choice(letters)
        if not colors or c != colors[-1]:
            colors.append(c)
    ms = tuple(10 * rng.randint(FLASH_MIN_MS // 10, FLASH_MAX_MS // 10) for _ in colors)
    return tuple(colors), ms


def new_challenge_v2(rng=None, *, flash: bool = False) -> Challenge:
    """A v2 challenge, e.g. centre → blink → right, drawn uniformly with a
    CSPRNG (so the side and the order are each a coin toss). The same
    single-use token, timing floor and issue caps as v1 apply
    (main.mark_challenge, marking.issue_challenge). `flash` (att_settings
    flash_enabled) adds the dormant colour flash as a third step."""
    rng = rng or _rng
    steps = tuple(rng.choice(SEQUENCES_V2_FLASH if flash else SEQUENCES_V2))
    colors, ms = new_flash(rng) if flash else ((), ())
    return Challenge(LIVENESS_V2, steps, colors, ms)


def parse_challenge_spec(spec: str) -> Challenge | None:
    """The challenge a stored spec describes, or None if it is not one we
    issue (unknown steps, a sequence outside the v2 set, a malformed flash)."""
    spec = spec or ""
    if not spec.startswith(V2_PREFIX):
        steps = parse_challenge(spec)
        return Challenge(LIVENESS_V1, tuple(steps)) if steps else None
    parts = spec[len(V2_PREFIX):].split("|")
    steps = tuple(s for s in parts[0].split(",") if s)
    if steps not in SEQUENCES_V2 and steps not in SEQUENCES_V2_FLASH:
        return None
    if POSE_FLASH not in steps:
        return Challenge(LIVENESS_V2, steps) if len(parts) == 1 else None
    if len(parts) != 3:
        return None
    colors = tuple(parts[1])
    try:
        ms = tuple(int(v) for v in parts[2].split(","))
    except ValueError:
        return None
    if (len(colors) != FLASH_COUNT or len(ms) != FLASH_COUNT
            or any(c not in FLASH_PALETTE for c in colors)
            or any(a == b for a, b in zip(colors, colors[1:]))
            or any(not FLASH_MIN_MS <= v <= FLASH_MAX_MS for v in ms)):
        return None
    return Challenge(LIVENESS_V2, steps, colors, ms)


@dataclass
class FrameAnalysis:
    ok: bool
    reason: str | None = None
    embedding: np.ndarray | None = None
    yaw: float = 0.0
    pitch: float = 0.0
    face_ratio: float = 0.0
    sharpness: float = 0.0
    brightness: float = 0.0
    texture_score: float = 0.0
    det_score: float = 0.0
    pose: str = POSE_CENTRE
    # v2: the convention-free turn (turn_ratio; + = the student's LEFT), the
    # side it reads as, eye openness (EAR and dark fraction, None if not
    # measured), the face box, and the skin / background colour (mean RGB) the
    # flash check compares.
    turn: float = 0.0
    side: str = POSE_CENTRE
    ear: float | None = None
    eye_dark: float | None = None
    bbox: tuple[float, float, float, float] | None = None
    skin_rgb: tuple[float, float, float] | None = None
    bg_rgb: tuple[float, float, float] | None = None


@dataclass
class VerifyResult:
    verdict: str                       # pass | pending | fail
    fail_code: str | None = None
    message: str = ""
    match_score: float = 0.0
    liveness_score: float = 0.0
    pose_ok: bool = False
    crossmatch_student_id: int | None = None
    crossmatch_score: float = 0.0
    detail: dict = field(default_factory=dict)


# ---------------------------------------------------------------------------
# Decoding (header-checked first)
# ---------------------------------------------------------------------------

# The phone's canvas.toBlob('image/jpeg') writes a baseline JPEG: one SOF0
# frame header and ONE interleaved scan.
#   - Scan count is what makes a JPEG a CPU bomb: every scan is another pass
#     over the whole frame, and a 3 MB progressive file can carry tens of
#     thousands of tiny refinement scans (~40 s to decode, so five such frames
#     hold every face-engine slot). Baseline must have exactly one scan. A
#     Huffman progressive file (SOF2) is tolerated only up to a normal
#     encoder's ~10 scans, in case some browser's encoder ever writes one —
#     refusing it outright would lock that browser's users out of marking.
#   - Lossless, hierarchical and arithmetic-coded variants are refused, and PNG
#     is never produced by the client, so it is refused too.
_JPEG_BASELINE = (0xC0, 0xC1)                  # SOF0 baseline, SOF1 extended sequential
_JPEG_PROGRESSIVE = 0xC2
_MAX_PROGRESSIVE_SCANS = 16
_JPEG_OTHER_SOF = {0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF}
_MAX_SEGMENTS = 64      # a canvas JPEG has ~8 (APP0, DQT, SOF, 4x DHT, SOS)
_MAX_FILL = 16          # 0xFF fill bytes allowed before one marker (encoders write none)


def _probe_jpeg(data: bytes) -> tuple[int, int] | None:
    """(width, height) of an acceptable JPEG, from its headers alone.

    Walks the marker segments up to the first scan (at most _MAX_SEGMENTS, so
    the Python loop is bounded however the file is built), takes the size from
    the frame header — libjpeg sizes its output from that same header — and
    counts the scans after it with bytes.count (C speed; inside entropy-coded
    data 0xFF is always stuffed, so FF DA can only be a scan marker). Anything
    unusual — stray bytes, a scan before the frame header, standalone markers,
    a truncated segment — returns None.
    """
    n = len(data)
    i = 2
    size = None
    progressive = False
    for _ in range(_MAX_SEGMENTS):
        if i >= n or data[i] != 0xFF:
            return None
        j = i + 1
        while j < n and data[j] == 0xFF:           # fill bytes before a marker
            j += 1
            if j - i > _MAX_FILL:
                return None
        if j >= n:
            return None
        marker = data[j]
        i = j + 1
        if marker == 0x01 or 0xD0 <= marker <= 0xD9:   # TEM / RSTn / SOI / EOI here: not a canvas JPEG
            return None
        if i + 2 > n:
            return None
        seglen = int.from_bytes(data[i:i + 2], "big")
        if seglen < 2 or i + seglen > n:
            return None
        if marker in _JPEG_OTHER_SOF:
            return None
        if marker in _JPEG_BASELINE or marker == _JPEG_PROGRESSIVE:
            if size is not None or seglen < 8:
                return None
            height = int.from_bytes(data[i + 3:i + 5], "big")
            width = int.from_bytes(data[i + 5:i + 7], "big")
            size = (width, height)
            progressive = marker == _JPEG_PROGRESSIVE
        elif marker == 0xDA:                           # the first scan
            if size is None:
                return None
            scans = 1 + data.count(b"\xff\xda", i + seglen)
            if scans > (_MAX_PROGRESSIVE_SCANS if progressive else 1):
                return None
            return size
        i += seglen
    return None


def probe_image_size(data: bytes) -> tuple[int, int] | None:
    """(width, height) read from the header alone, or None if the bytes are
    not a baseline JPEG we are willing to hand to a decoder."""
    if data[:3] == b"\xff\xd8\xff":
        return _probe_jpeg(data)
    return None


def frame_problem(data: bytes) -> str | None:
    """Why this upload must not be decoded, or None when it is safe to.
    Pure header inspection — cheap enough to run on the event loop."""
    if not data:
        return "EMPTY_FRAME"
    if len(data) > MAX_FRAME_BYTES:
        return "FRAME_TOO_LARGE"
    size = probe_image_size(data)
    if size is None:
        return "BAD_FRAME"
    width, height = size
    if width <= 0 or height <= 0:
        return "BAD_FRAME"
    if width > MAX_FRAME_SIDE or height > MAX_FRAME_SIDE or width * height > MAX_FRAME_PIXELS:
        return "FRAME_TOO_LARGE"
    return None


def decode_image(data: bytes) -> np.ndarray | None:
    if frame_problem(data) is not None:
        return None
    buf = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(buf, cv2.IMREAD_COLOR)
    if img is None:
        return None
    h, w = img.shape[:2]
    if w > MAX_FRAME_SIDE or h > MAX_FRAME_SIDE:       # never trust the header alone
        return None
    return img


def _yaw_from_pose(face) -> tuple[float, float]:
    """InsightFace gives pose as (pitch, yaw, roll) when the model provides it;
    fall back to landmark geometry when it doesn't."""
    pose = getattr(face, "pose", None)
    if pose is not None and len(pose) >= 2:
        return float(pose[1]), float(pose[0])

    kps = getattr(face, "kps", None)
    if kps is None or len(kps) < 5:
        return 0.0, 0.0
    # kps: left eye, right eye, nose, left mouth, right mouth
    left_eye, right_eye, nose = kps[0], kps[1], kps[2]
    eye_centre_x = (left_eye[0] + right_eye[0]) / 2.0
    eye_span = max(abs(right_eye[0] - left_eye[0]), 1e-3)
    # Nose displacement from the eye midpoint, scaled to a rough degree figure.
    return float((nose[0] - eye_centre_x) / eye_span * 60.0), 0.0


def _texture_score(img: np.ndarray, bbox) -> float:
    """Cheap screen-replay signal.

    A face on an LCD/OLED re-photographed by a phone loses high-frequency skin
    detail and gains regular pixel-grid structure. We score the crop's
    high-frequency energy: real skin under real light sits high, a replayed
    screen sits low. Not a silver bullet on its own — it is one vote among
    several, and the pose challenge is the layer that does the heavy lifting.
    """
    x1, y1, x2, y2 = [int(max(v, 0)) for v in bbox[:4]]
    crop = img[y1:y2, x1:x2]
    if crop.size == 0:
        return 0.0
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.resize(gray, (128, 128))
    lap_var = float(cv2.Laplacian(gray, cv2.CV_64F).var())

    # Normalised into roughly 0..1; 150+ variance is comfortably real skin.
    hf = min(lap_var / 150.0, 1.0)

    # Colour spread: screens compress chroma, real faces keep more variety.
    hsv = cv2.cvtColor(cv2.resize(crop, (128, 128)), cv2.COLOR_BGR2HSV)
    sat_std = float(np.std(hsv[:, :, 1])) / 64.0
    return float(np.clip(0.7 * hf + 0.3 * min(sat_std, 1.0), 0.0, 1.0))


def _pose_label(yaw: float) -> str:
    if yaw <= -YAW_TURN_MIN:
        return POSE_LEFT
    if yaw >= YAW_TURN_MIN:
        return POSE_RIGHT
    if abs(yaw) <= YAW_CENTRE_MAX:
        return POSE_CENTRE
    return "between"


def turn_ratio(kps) -> float:
    """Which way, and how far, the head is turned — from the five detection
    keypoints (two eyes, nose, two mouth corners) of a RAW, un-mirrored frame.

    The nose tip's offset from the eye midpoint, projected onto the line from
    the image-left eye to the image-right eye, in eye distances. Positive means
    the student turned to THEIR LEFT, negative to their right (see "Liveness
    v2" at the top of this file for why, and for the scale). public/js/
    liveness.js computes the same quantity from the phone's landmarks."""
    pts = np.asarray(kps, dtype=np.float64)
    a, b, nose = pts[0, :2], pts[1, :2], pts[2, :2]
    if a[0] > b[0]:                     # order the eyes by image position
        a, b = b, a
    span = float(np.linalg.norm(b - a))
    if span < 1e-6:
        return 0.0
    axis = (b - a) / span
    return float(np.dot(nose - (a + b) / 2.0, axis) / span)


def side_from_ratio(ratio: float) -> str:
    """The pose a turn_ratio reads as, in the STUDENT's terms."""
    if ratio >= TURN_RATIO_MIN:
        return POSE_LEFT
    if ratio <= -TURN_RATIO_MIN:
        return POSE_RIGHT
    if abs(ratio) <= CENTRE_RATIO_MAX:
        return POSE_CENTRE
    return "between"


def eye_aspect_ratio(eye) -> float:
    """Soukupová & Čech's EAR for one eye: six points in the 68-landmark order
    (corner, two upper-lid points, the other corner, two lower-lid points).
    Lid-to-lid height over twice the corner-to-corner width — ~0.25-0.35 open,
    falling towards zero as the lids close. Only x, y are used."""
    p = np.asarray(eye, dtype=np.float64)[:, :2]
    width = float(np.linalg.norm(p[0] - p[3]))
    if width < 1e-6:
        return 0.0
    height = float(np.linalg.norm(p[1] - p[5]) + np.linalg.norm(p[2] - p[4]))
    return height / (2.0 * width)


def eye_aspect_ratios(landmarks_68) -> tuple[float, float]:
    """EAR of both eyes from a 68-point landmark set (iBUG 300-W numbering:
    points 36-41 are one eye and 42-47 the other)."""
    lm = np.asarray(landmarks_68, dtype=np.float64)
    return eye_aspect_ratio(lm[36:42]), eye_aspect_ratio(lm[42:48])


def eye_darkness(gray: np.ndarray, landmarks_68) -> float | None:
    """How much of each eye is iris/pupil: the fraction of a box between the
    eye corners (68-point 36/39 and 42/45 — corners, which the landmark model
    places well even when it misses the lids) darker than 60% of the cheek just
    below. An open eye shows its dark iris; a shut one shows skin and a lash
    line. Compared only within one burst (same light, same face), never
    against a fixed value. None when the eyes are too small to measure."""
    lm = np.asarray(landmarks_68, dtype=np.float64)
    h, w = gray.shape[:2]
    vals = []
    for c0, c1 in ((36, 39), (42, 45)):
        a, b = lm[c0, :2], lm[c1, :2]
        span = float(np.linalg.norm(b - a))
        if span < 6:
            continue
        cx, cy = (a + b) / 2.0
        box = gray[max(0, int(cy - 0.25 * span)):min(h, int(cy + 0.25 * span) + 1),
                   max(0, int(cx - 0.45 * span)):min(w, int(cx + 0.45 * span) + 1)]
        cheek = gray[max(0, int(cy + 0.6 * span)):min(h, int(cy + 0.9 * span) + 1),
                     max(0, int(cx - 0.3 * span)):min(w, int(cx + 0.3 * span) + 1)]
        if box.size < 12 or cheek.size < 12:
            continue
        vals.append(float(np.mean(box < 0.6 * float(np.median(cheek)))))
    return float(np.mean(vals)) if vals else None


def _patch_mean(img: np.ndarray, cx: float, cy: float, half_w: float, half_h: float) -> np.ndarray | None:
    h, w = img.shape[:2]
    patch = img[max(0, int(cy - half_h)):min(h, int(cy + half_h) + 1),
                max(0, int(cx - half_w)):min(w, int(cx + half_w) + 1)].reshape(-1, 3).astype(np.float64)
    # Clipped highlights and crushed shadows carry no colour information.
    keep = (patch.max(axis=1) < 250) & (patch.max(axis=1) > 8)
    patch = patch[keep]
    return patch if len(patch) >= 9 else None


def skin_and_background(img: np.ndarray, kps, bbox) -> tuple[tuple, tuple] | tuple[None, None]:
    """Mean RGB (of a BGR image) of the face's skin — both cheeks and the
    forehead, placed from the keypoints — and of the background beside and
    above the head, for the flash check. The background is the frame's own reference: whatever the
    camera's auto-exposure and white balance do between two frames, they do to
    the whole frame, so skin relative to background cancels them, while the
    phone's screen — a hand's length from the face, metres from the wall —
    lights the skin far more than the background."""
    pts = np.asarray(kps, dtype=np.float64)[:, :2]
    a, b = pts[0], pts[1]
    ma, mb = pts[3], pts[4]
    if a[0] > b[0]:
        a, b = b, a
    if ma[0] > mb[0]:
        ma, mb = mb, ma
    span = float(np.linalg.norm(b - a))
    if span < 8:
        return None, None
    axis = (b - a) / span
    down = np.array([-axis[1], axis[0]])
    if np.dot(pts[2] - (a + b) / 2.0, down) < 0:
        down = -down
    mid = (a + b) / 2.0
    patches = [
        _patch_mean(img, *((a + ma) / 2.0 - 0.12 * span * axis), 0.15 * span, 0.15 * span),
        _patch_mean(img, *((b + mb) / 2.0 + 0.12 * span * axis), 0.15 * span, 0.15 * span),
        _patch_mean(img, *(mid - 0.55 * span * down), 0.25 * span, 0.12 * span),
    ]
    skin = [p for p in patches if p is not None]
    if not skin:
        return None, None

    h, w = img.shape[:2]
    x1, y1, x2, y2 = [float(v) for v in bbox[:4]]
    pad = 0.3 * (x2 - x1)
    mask = np.zeros((h, w), dtype=bool)
    mask[:max(1, int(min(h, y2))), :] = True                 # beside and above the head...
    mask[:, max(0, int(x1 - pad)):min(w, int(x2 + pad) + 1)] = False   # ...not the head itself
    bg = img[mask].reshape(-1, 3).astype(np.float64)
    bg = bg[(bg.max(axis=1) < 250) & (bg.max(axis=1) > 8)]
    if len(bg) < 200:
        return None, None
    # OpenCV images are BGR; the flash palette is RGB.
    return (tuple(float(v) for v in np.concatenate(skin).mean(axis=0)[::-1]),
            tuple(float(v) for v in bg.mean(axis=0)[::-1]))


# Flash frames (seven per v2 burst) need only the face box, the keypoints, the
# embedding and the skin colour — not the 3D landmarks, and not a 640 px
# detection pass for a face that fills a quarter of the frame. Detecting at
# 320 px and skipping the landmark model saves ~0.25 s of the ~0.7 s a frame
# costs on the dev box (recognition, the rest, cannot be skipped: every frame
# is identity-checked). A seven-frame flash would otherwise add ~5 s per mark.
LIGHT_DET_SIZE = (320, 320)


def _detect_light(img: np.ndarray) -> list:
    from insightface.app.common import Face

    model = get_model()
    bboxes, kpss = model.det_model.detect(img, input_size=LIGHT_DET_SIZE, max_num=0, metric="default")
    found = []
    for i in range(bboxes.shape[0]):
        face = Face(bbox=bboxes[i, 0:4], kps=kpss[i] if kpss is not None else None, det_score=bboxes[i, 4])
        found.append(face)
    if len(found) == 1:
        model.models["recognition"].get(img, found[0])
    return found


def analyse_frame(img: np.ndarray | None, *, light: bool = False) -> FrameAnalysis:
    """Quality gates, then everything the judges read from one frame.
    `light` (flash frames): no 3D landmarks, so no model yaw and no eye
    measures — see LIGHT_DET_SIZE."""
    s = get_settings()
    if img is None or img.size == 0:
        return FrameAnalysis(False, "UNREADABLE_IMAGE")

    faces = _detect_light(img) if light else get_model().get(img)
    if not faces:
        return FrameAnalysis(False, "NO_FACE")
    if len(faces) > 1:
        # Two faces in shot is either a friend leaning in or a photo held up
        # next to a real person. Either way it is not a clean mark.
        return FrameAnalysis(False, "MULTIPLE_FACES")

    face = faces[0]
    h, w = img.shape[:2]
    x1, y1, x2, y2 = face.bbox[:4]
    face_ratio = float((x2 - x1) / max(w, 1))
    if face_ratio < s.min_face_ratio:
        return FrameAnalysis(False, "FACE_TOO_SMALL", face_ratio=face_ratio)

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    brightness = float(np.mean(gray))
    if brightness < 40:
        return FrameAnalysis(False, "TOO_DARK", brightness=brightness)

    sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    if sharpness < 25:
        return FrameAnalysis(False, "TOO_BLURRY", sharpness=sharpness)

    emb = face.normed_embedding
    if emb is None:
        return FrameAnalysis(False, "NO_EMBEDDING")

    yaw, pitch = (0.0, 0.0) if light else _yaw_from_pose(face)

    kps = getattr(face, "kps", None)
    turn = turn_ratio(kps) if kps is not None and len(kps) >= 3 else 0.0
    lm68 = getattr(face, "landmark_3d_68", None)
    has_lm = lm68 is not None and len(lm68) >= 48
    skin, bg = skin_and_background(img, kps, face.bbox) if kps is not None and len(kps) >= 5 else (None, None)

    return FrameAnalysis(
        ok=True,
        embedding=np.asarray(emb, dtype=np.float32),
        yaw=yaw,
        pitch=pitch,
        face_ratio=face_ratio,
        sharpness=sharpness,
        brightness=brightness,
        texture_score=_texture_score(img, face.bbox),
        det_score=float(getattr(face, "det_score", 0.0)),
        pose=_pose_label(yaw),
        turn=turn,
        side=side_from_ratio(turn),
        ear=float(np.mean(eye_aspect_ratios(lm68))) if has_lm else None,
        eye_dark=eye_darkness(gray, lm68) if has_lm else None,
        bbox=tuple(float(v) for v in face.bbox[:4]),
        skin_rgb=skin,
        bg_rgb=bg,
    )


def cosine(a: np.ndarray, b: np.ndarray) -> float:
    denom = float(np.linalg.norm(a) * np.linalg.norm(b)) or 1e-9
    return float(np.dot(a, b) / denom)


def best_score(embedding: np.ndarray, template: Sequence[Sequence[float]]) -> float:
    """Similarity against the best-matching angle of an enrolled template."""
    if not len(template):
        return 0.0
    mat = np.asarray(template, dtype=np.float32)
    sims = mat @ embedding / (
        np.linalg.norm(mat, axis=1) * (np.linalg.norm(embedding) or 1e-9) + 1e-9
    )
    return float(np.max(sims))


def load_template(raw: str) -> list[list[float]]:
    try:
        return json.loads(raw)
    except (TypeError, ValueError):
        return []


# ---------------------------------------------------------------------------
# Enrollment
# ---------------------------------------------------------------------------

def build_template(images: list[np.ndarray | None], min_frames: int) -> tuple[list[list[float]], float, str | None]:
    """Turn supervised capture frames into a multi-angle template.

    Returns (embeddings, quality, error_code). Frames that fail quality are
    dropped rather than averaged in — a blurry enrollment would haunt every
    future verification.
    """
    embeddings: list[np.ndarray] = []
    rejected: list[str] = []

    for img in images:
        fa = analyse_frame(img)
        if not fa.ok or fa.embedding is None:
            rejected.append(fa.reason or "UNKNOWN")
            continue
        embeddings.append(fa.embedding)

    if len(embeddings) < min_frames:
        code = "INSUFFICIENT_QUALITY_FRAMES"
        if rejected and all(r == "NO_FACE" for r in rejected):
            code = "NO_FACE"
        return [], 0.0, code

    # Self-consistency: every captured angle should still be recognisably the
    # same person. A low figure means we captured two different people, or the
    # capture was so poor the template would be useless.
    sims = [
        cosine(embeddings[i], embeddings[j])
        for i in range(len(embeddings))
        for j in range(i + 1, len(embeddings))
    ]
    quality = float(np.mean(sims)) if sims else 0.0
    if quality < 0.45:
        return [], quality, "INCONSISTENT_FRAMES"

    return [e.tolist() for e in embeddings], quality, None


# ---------------------------------------------------------------------------
# Verification
# ---------------------------------------------------------------------------

def _side(pose: str) -> int:
    return 1 if pose == POSE_RIGHT else -1 if pose == POSE_LEFT else 0


def check_challenge(
    analyses: Sequence[FrameAnalysis],
    challenge: Sequence[str],
    frames_per_step: int = FRAMES_PER_STEP,
) -> tuple[bool, list[dict]]:
    """Was every step performed, in order, within that step's own frames?

    Frame i belongs to step i // frames_per_step. A step passes when its usable
    frames show exactly ONE definite pose (centre, left or right — an
    in-between or unreadable frame is neutral) and that pose is the one asked
    for. Accepting "any one frame matches" would let a mixed pair such as
    (centre, left) serve two different steps, and a small stock burst could
    again fit several sequences.

    'other' must turn the opposite way to the previous turn step, which is a
    sign comparison within this burst and so independent of which way the
    model counts yaw. Returns (ok, per-step evidence for the attempt record).
    """
    steps: list[dict] = []
    if len(analyses) != frames_per_step * len(challenge):
        return False, steps

    ok = True
    last_side = 0
    for index, required in enumerate(challenge):
        group = analyses[index * frames_per_step:(index + 1) * frames_per_step]
        definite = {fa.pose for fa in group
                    if fa.ok and fa.pose in (POSE_CENTRE, POSE_LEFT, POSE_RIGHT)}
        shown = definite.pop() if len(definite) == 1 else None
        side = _side(shown) if shown else 0
        if required == POSE_CENTRE:
            passed = shown == POSE_CENTRE
        elif required == POSE_TURN:
            passed = side != 0
        else:                                   # POSE_OTHER
            passed = side != 0 and last_side != 0 and side == -last_side
        steps.append({
            "step": required,
            "ok": passed,
            "seen": [a.pose if a.ok else (a.reason or "unusable") for a in group],
        })
        if not passed:
            ok = False
        elif side:
            last_side = side
    return ok, steps


# --- v2 ---------------------------------------------------------------------

def _ratio(num: float | None, ref: float | None) -> float | None:
    if num is None or ref is None or ref <= 1e-6:
        return None
    return num / ref


def check_blink(closed: FrameAnalysis, opened: FrameAnalysis,
                open_refs: Sequence[FrameAnalysis]) -> tuple[str, dict]:
    """Did the eyes shut in `closed` and open again in `opened`?

    Judged against the student's own open eyes in this burst (`open_refs`:
    the usable, straight-on, eyes-open frames — the blink's open frame among
    them). Returns ("seen" | "unclear" | "not_seen", evidence). See the BLINK_*
    constants for why "unclear" exists and goes to review."""
    refs = [f for f in open_refs if f.ok]
    ear_ref = float(np.median([f.ear for f in refs if f.ear is not None])) \
        if any(f.ear is not None for f in refs) else None
    dark_ref = float(np.median([f.eye_dark for f in refs if f.eye_dark is not None])) \
        if any(f.eye_dark is not None for f in refs) else None
    if dark_ref is not None and dark_ref < 0.03:
        dark_ref = None                 # no visible iris to lose: measure unusable
    ear_c = _ratio(closed.ear, ear_ref) if closed.ok else None
    dark_c = _ratio(closed.eye_dark, dark_ref) if closed.ok else None
    ear_o = _ratio(opened.ear, ear_ref) if opened.ok else None
    dark_o = _ratio(opened.eye_dark, dark_ref) if opened.ok else None
    evidence = {
        "ear_open_ref": None if ear_ref is None else round(ear_ref, 3),
        "dark_open_ref": None if dark_ref is None else round(dark_ref, 3),
        "closed": {"ear": None if ear_c is None else round(ear_c, 2),
                   "dark": None if dark_c is None else round(dark_c, 2)},
        "opened": {"ear": None if ear_o is None else round(ear_o, 2),
                   "dark": None if dark_o is None else round(dark_o, 2)},
    }
    if ear_c is None:
        return "unclear", evidence
    def changed(r, limit):
        return r is not None and r > 0 and (r <= limit or r >= 1.0 / limit)

    def same(r, limit):
        return r is not None and limit <= r <= 1.0 / limit

    shut = ear_c <= BLINK_EAR_CLOSED or changed(dark_c, BLINK_DARK_CHANGE)
    # The frame after must show the eyes open again (like the open frames), or
    # a client could send two closed-eye frames — or one long squint — as a
    # blink.
    reopened = (ear_o is not None and ear_o >= BLINK_EAR_OPEN) or same(dark_o, BLINK_DARK_SAME)
    if shut and reopened:
        return "seen", evidence
    if ear_c >= BLINK_EAR_OPEN and (dark_c is None or same(dark_c, BLINK_DARK_SAME)):
        return "not_seen", evidence
    return "unclear", evidence


# --- the blink, judged by MediaPipe (see "blink" at the top) ---------------

_blink_lock = threading.Lock()
_blink_model = None
_blink_loaded = False


def get_blink_model():
    """MediaPipe's Face Landmarker (IMAGE mode, CPU, blendshapes on), or None
    when the package or its model is missing — then the blink falls back to
    check_blink's measures. Loaded once per process (~1 s, ~50 MB)."""
    global _blink_model, _blink_loaded
    if not _blink_loaded:
        with _blink_lock:
            if not _blink_loaded:
                path = get_settings().blink_model
                try:
                    if not path or not os.path.isfile(path):
                        raise FileNotFoundError(path)
                    from mediapipe.tasks.python import BaseOptions
                    from mediapipe.tasks.python import vision as mp_vision
                    options = mp_vision.FaceLandmarkerOptions(
                        base_options=BaseOptions(model_asset_path=path, delegate=BaseOptions.Delegate.CPU),
                        running_mode=mp_vision.RunningMode.IMAGE,
                        num_faces=1,
                        output_face_blendshapes=True,
                    )
                    _blink_model = mp_vision.FaceLandmarker.create_from_options(options)
                    log.info("blink model %s ready", path)
                except Exception:
                    log.exception("MediaPipe blink model unavailable (%s); using the landmark fallback", path)
                    _blink_model = None
                _blink_loaded = True
    return _blink_model


def blink_scores(img: np.ndarray | None) -> tuple[float, float] | None:
    """(eyeBlinkLeft, eyeBlinkRight) for the face in a BGR frame, or None.
    Thread-safe: MediaPipe graphs take one image at a time."""
    model = get_blink_model()
    if model is None or img is None:
        return None
    import mediapipe as mp

    rgb = np.ascontiguousarray(cv2.cvtColor(img, cv2.COLOR_BGR2RGB))
    with _blink_lock:
        res = model.detect(mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb))
    if not res.face_blendshapes:
        return None
    cats = {c.category_name: float(c.score) for c in res.face_blendshapes[0]}
    if "eyeBlinkLeft" not in cats or "eyeBlinkRight" not in cats:
        return None
    return cats["eyeBlinkLeft"], cats["eyeBlinkRight"]


def check_blink_mp(closed: tuple[float, float] | None, opened: tuple[float, float] | None, *,
                   closed_min: float = DEFAULT_BLINK_CLOSED_MIN,
                   open_max: float = DEFAULT_BLINK_OPEN_MAX,
                   min_diff: float = DEFAULT_BLINK_MIN_DIFF) -> tuple[str, dict]:
    """The blink from MediaPipe's blendshape scores of the two blink frames
    (see "blink" at the top for the rule and where the numbers come from)."""
    evidence = {
        "method": "mediapipe",
        "closed": None if closed is None else [round(v, 3) for v in closed],
        "opened": None if opened is None else [round(v, 3) for v in opened],
        "closed_min": closed_min, "open_max": open_max, "min_diff": min_diff,
    }
    if closed is None or opened is None:
        return "unclear", evidence
    c, o = float(np.mean(closed)), float(np.mean(opened))
    if c >= closed_min and o <= open_max and c - o >= min_diff:
        return "seen", evidence
    if c < BLINK_NOT_SEEN_MAX and c - o < BLINK_NOT_SEEN_DIFF:
        return "not_seen", evidence
    return "unclear", evidence


def _srgb_to_linear(rgb: np.ndarray) -> np.ndarray:
    c = np.asarray(rgb, dtype=np.float64) / 255.0
    return np.where(c <= 0.04045, c / 12.92, ((c + 0.055) / 1.055) ** 2.4)


# A face whose colour does not change AT ALL across the flashes is one picture
# re-sent (re-encoded so the bytes differ): even a still scene through a live
# camera moves the skin mean more than this from frame to frame (sensor noise,
# compression), so this is "fail", not "too weak".
FLASH_FLAT_RMS = 0.0001


def check_flash(frames: Sequence[FrameAnalysis], colors_rgb: Sequence[tuple[int, int, int]], *,
                min_signal: float = DEFAULT_FLASH_MIN_SIGNAL,
                min_correlation: float = DEFAULT_FLASH_MIN_CORRELATION) -> tuple[str, dict]:
    """Did the face reflect the commanded colours? One frame per colour.

    For each frame, the skin's log colour minus the background's (cancelling
    exposure and white balance, which hit the whole frame alike), with its
    overall brightness removed — chromaticity only. The change from each flash
    to the next is compared with the change in the screen's colour (linear
    light, brightness likewise removed): Pearson correlation over all channels
    and steps. Consecutive differences, not absolute values, because the
    absolute skin colour is mostly the room's light and the person's skin.

    Returns (verdict, evidence):
      pass        strong enough to judge, and correlated
      fail        strong enough to judge, but not following the colours; or a
                  face that did not change at all (a still picture)
      weak        too little change to judge (a very bright room) -> review
      incomplete  a flash frame had no measurable face -> review
    """
    evidence: dict = {"n": len(frames)}
    usable = [f for f in frames if f.ok and f.skin_rgb is not None and f.bg_rgb is not None]
    if len(frames) < 3 or len(usable) != len(frames) or len(colors_rgb) != len(frames):
        evidence["usable"] = len(usable)
        return "incomplete", evidence

    measured = []
    for f in frames:
        v = np.log(np.maximum(f.skin_rgb, 1.0)) - np.log(np.maximum(f.bg_rgb, 1.0))
        measured.append(v - v.mean())
    m = np.diff(np.asarray(measured), axis=0).ravel()
    lin = _srgb_to_linear(np.asarray(colors_rgb))
    e = np.diff(lin - lin.mean(axis=1, keepdims=True), axis=0).ravel()

    signal = float(np.sqrt(np.mean(m ** 2)))
    if np.std(m) < 1e-12 or np.std(e) < 1e-12:
        r = 0.0
    else:
        r = float(np.corrcoef(m, e)[0, 1])
    gain = float(np.dot(m, e) / max(float(np.dot(e, e)), 1e-12))
    evidence.update({"signal": round(signal, 5), "correlation": round(r, 3), "gain": round(gain, 5),
                     "min_signal": min_signal, "min_correlation": min_correlation})
    if signal < FLASH_FLAT_RMS:
        return "fail", evidence
    if signal < min_signal:
        return "weak", evidence
    if r >= min_correlation and gain > 0:
        return "pass", evidence
    return "fail", evidence


@dataclass
class LivenessV2:
    pose_ok: bool
    blink: str                 # seen | unclear | not_seen | absent
    flash: str                 # pass | fail | weak | incomplete | absent
    steps: list[dict]
    closed_frames: set[int]    # indexes of blink eyes-closed frames
    flash_frames: set[int]     # indexes of flash frames


def check_challenge_v2(analyses: Sequence[FrameAnalysis], challenge: Challenge, *,
                       flash_min_signal: float = DEFAULT_FLASH_MIN_SIGNAL,
                       flash_min_correlation: float = DEFAULT_FLASH_MIN_CORRELATION,
                       blink: dict | None = None,
                       blink_closed_min: float = DEFAULT_BLINK_CLOSED_MIN,
                       blink_open_max: float = DEFAULT_BLINK_OPEN_MAX,
                       blink_min_diff: float = DEFAULT_BLINK_MIN_DIFF) -> LivenessV2:
    """Judge a v2 burst step by step, each step in its own frames.

    Pose steps (centre/left/right): like v1, the step's usable frames must
    show exactly one definite pose and it must be the one asked for — but the
    pose is FrameAnalysis.side, the geometric turn in the student's own terms,
    so 'left' means the student's left whatever either model's conventions.

    `blink` is {"closed": scores, "opened": scores} from MediaPipe
    (verify_burst); None means the model is unavailable and check_blink's
    landmark measures decide instead."""
    counts = challenge.frame_counts()
    result = LivenessV2(True, "absent", "absent", [], set(), set())
    if len(analyses) != sum(counts):
        result.pose_ok = False
        return result

    starts = [sum(counts[:i]) for i in range(len(counts))]
    # Straight-on, eyes-open frames: every usable frame of a centre step and
    # the open half of a blink. The blink is judged against these.
    open_refs = []
    for i, step in enumerate(challenge.steps):
        if step == POSE_CENTRE:
            open_refs += [f for f in analyses[starts[i]:starts[i] + counts[i]] if f.ok and f.side == POSE_CENTRE]
        elif step == POSE_BLINK:
            opened = analyses[starts[i] + 1]
            if opened.ok and opened.side != POSE_LEFT and opened.side != POSE_RIGHT:
                open_refs.append(opened)

    for i, step in enumerate(challenge.steps):
        group = list(analyses[starts[i]:starts[i] + counts[i]])
        record: dict = {"step": step}
        if step == POSE_FLASH:
            result.flash_frames.update(range(starts[i], starts[i] + counts[i]))
            result.flash, record["flash"] = check_flash(
                group, challenge.flash_rgb(),
                min_signal=flash_min_signal, min_correlation=flash_min_correlation)
            record["ok"] = result.flash == "pass"
        elif step == POSE_BLINK:
            result.closed_frames.add(starts[i])
            closed, opened = group
            # The blink is asked for looking at the camera; a turned head is
            # not a blink step performed (and turned eyes measure badly).
            turned = any(f.ok and f.side in (POSE_LEFT, POSE_RIGHT) for f in group)
            if turned:
                result.blink, record["blink"] = "not_seen", {"turned": True}
            elif blink is not None:
                result.blink, record["blink"] = check_blink_mp(
                    blink.get("closed"), blink.get("opened"), closed_min=blink_closed_min,
                    open_max=blink_open_max, min_diff=blink_min_diff)
            else:
                result.blink, record["blink"] = check_blink(closed, opened, open_refs)
            record["ok"] = result.blink == "seen"
        else:
            definite = {f.side for f in group if f.ok and f.side in (POSE_CENTRE, POSE_LEFT, POSE_RIGHT)}
            shown = definite.pop() if len(definite) == 1 else None
            record["ok"] = shown == step
            if not record["ok"]:
                result.pose_ok = False
        record["seen"] = [f.side if f.ok else (f.reason or "unusable") for f in group]
        record["turn"] = [round(f.turn, 3) if f.ok else None for f in group]
        result.steps.append(record)
    return result


def judge_burst(
    analyses: Sequence[FrameAnalysis],
    challenge: Sequence[str] | Challenge,
    own_template: list[list[float]],
    *,
    others: list[tuple[int, list[list[float]]]],
    match_threshold: float,
    review_threshold: float,
    liveness_threshold: float,
    crossmatch_margin: float,
    consistency_threshold: float = DEFAULT_CONSISTENCY_THRESHOLD,
    flash_min_signal: float = DEFAULT_FLASH_MIN_SIGNAL,
    flash_min_correlation: float = DEFAULT_FLASH_MIN_CORRELATION,
    antispoof_review_threshold: float = DEFAULT_ANTISPOOF_REVIEW,
    antispoof: dict | None = None,
    blink: dict | None = None,
    blink_closed_min: float = DEFAULT_BLINK_CLOSED_MIN,
    blink_open_max: float = DEFAULT_BLINK_OPEN_MAX,
    blink_min_diff: float = DEFAULT_BLINK_MIN_DIFF,
    review_skip_match: float = DEFAULT_REVIEW_SKIP_MATCH,
) -> VerifyResult:
    """Decide one marking attempt from already-analysed frames (no model here,
    so the rules are unit-testable). `others` is the rest of the enrolled
    cohort, used for the cross-match check.

    `challenge` is a v1 step list (judged exactly as before v2 existed) or a
    Challenge. A v2 challenge adds: sides judged geometrically, the blink (from
    MediaPipe's scores in `blink`, see verify_burst), the dormant colour flash,
    and office review when the blink or the flash could not be judged or the
    passive anti-spoof model (`antispoof`) suspects a print or a screen —
    unless the identity match is at least `review_skip_match` (see
    DEFAULT_REVIEW_SKIP_MATCH). v1 never has those soft reasons, so the skip
    cannot touch it: its own review band (0.32-0.42) lies below the match
    threshold."""
    plan = challenge if isinstance(challenge, Challenge) else Challenge(LIVENESS_V1, tuple(challenge))
    v2 = plan.protocol == LIVENESS_V2

    good = [a for a in analyses if a.ok and a.embedding is not None]

    if not good:
        reason = next((a.reason for a in analyses if a.reason), None) or "NO_FRAMES"
        friendly = {
            "NO_FACE": "No face detected — hold the phone at eye level in good light.",
            "MULTIPLE_FACES": "More than one face in frame — make sure you are alone in the shot.",
            "FACE_TOO_SMALL": "Move the phone closer to your face.",
            "TOO_DARK": "Too dark — find better light.",
            "TOO_BLURRY": "Image was blurry — hold the phone steady.",
        }.get(reason, "Could not read the camera frames. Please try again.")
        return VerifyResult("fail", reason, friendly)

    if len(good) < len(plan.steps):
        return VerifyResult(
            "fail",
            "INSUFFICIENT_FRAMES",
            "Not all steps were captured clearly. Please try again.",
        )

    # --- pose challenge: each step, in order, in its own frames ---
    if v2:
        live = check_challenge_v2(analyses, plan, flash_min_signal=flash_min_signal,
                                  flash_min_correlation=flash_min_correlation, blink=blink,
                                  blink_closed_min=blink_closed_min, blink_open_max=blink_open_max,
                                  blink_min_diff=blink_min_diff)
        pose_ok, step_evidence = live.pose_ok, live.steps
    else:
        live = None
        pose_ok, step_evidence = check_challenge(analyses, list(plan.steps))
    index_of = {id(a): i for i, a in enumerate(analyses)}
    closed = live.closed_frames if live else set()
    flashed = live.flash_frames if live else set()

    # --- liveness: texture across the burst, weakest frame carries weight ---
    # (Flash frames are tinted by the screen, which moves the colour term; the
    # texture threshold was set on untinted frames, so they sit this out.)
    textured = [a for a in good if index_of[id(a)] not in flashed] or good
    textures = [a.texture_score for a in textured]
    liveness = float(0.5 * np.mean(textures) + 0.5 * np.min(textures))

    # --- identity: the WEAKEST frame decides ---
    # A median let a burst mix people: three frames of the account holder (or
    # photos of them) outvoted one frame of whoever actually performed the
    # turn. Every usable frame now has to be the enrolled student on its own.
    # The one exception is a blink's eyes-shut frame, which ArcFace scores lower
    # for the right person: it is held to "same person as the rest of the
    # burst" (consistency, below) and to the cohort cross-match, not to the
    # per-frame match threshold.
    scores = [best_score(a.embedding, own_template) for a in good]
    strict = [s for a, s in zip(good, scores) if index_of[id(a)] not in closed] or scores
    match = float(min(strict))

    # --- same person throughout ---
    consistency = 1.0
    for i in range(len(good)):
        for j in range(i + 1, len(good)):
            consistency = min(consistency, cosine(good[i].embedding, good[j].embedding))

    # --- cross-match, frame by frame, against the rest of the cohort ---
    # Checking one frame left every other frame free to be somebody else. A
    # frame only counts as a cross-match when it resembles the other student at
    # least at review level AND beats its own score by the margin — so a poor,
    # low-scoring-for-everyone frame of the real student cannot trip it.
    cross_id, cross_score = None, 0.0          # strongest resemblance anywhere
    hit_id, hit_score = None, 0.0              # a frame that actually tripped it
    per_frame_cross: list[list] = []
    for a, own in zip(good, scores):
        frame_id, frame_score = None, 0.0
        for sid, tmpl in others:
            s = best_score(a.embedding, tmpl)
            if s > frame_score:
                frame_id, frame_score = sid, s
        per_frame_cross.append([frame_id, round(frame_score, 4)])
        if frame_id is not None and frame_score > cross_score:
            cross_id, cross_score = frame_id, frame_score
        if (frame_id is not None and frame_score >= review_threshold
                and frame_score > own + crossmatch_margin and frame_score > hit_score):
            hit_id, hit_score = frame_id, frame_score
    if hit_id is not None:
        cross_id, cross_score = hit_id, hit_score

    detail = {
        "frames_used": len(good),
        "frames_submitted": len(analyses),
        "challenge": list(plan.steps),
        "steps": step_evidence,
        "observed_poses": [a.pose if a.ok else None for a in analyses],
        "yaw": [round(a.yaw, 1) if a.ok else None for a in analyses],
        "per_frame_match": [round(s, 4) for s in scores],
        "per_frame_cross": per_frame_cross,
        "consistency_min": round(consistency, 4),
        "texture_scores": [round(t, 4) for t in textures],
        "rejected": [a.reason for a in analyses if not a.ok],
    }

    # Things a v2 burst could not settle on its own: the attempt can still be
    # approved, but by a person looking at the photos, not automatically.
    review_reasons: list[str] = []
    if v2:
        detail.update({
            "protocol": LIVENESS_V2,
            "turn_ratio": [round(a.turn, 3) if a.ok else None for a in analyses],
            "ear": [round(a.ear, 3) if a.ok and a.ear is not None else None for a in analyses],
            "eye_dark": [round(a.eye_dark, 3) if a.ok and a.eye_dark is not None else None for a in analyses],
            "blink": live.blink,
            "flash": live.flash,
            "closed_frames": sorted(closed),
            "flash_frames": sorted(flashed),
        })
        if plan.flash_colors:
            detail["flash_colors"] = "".join(plan.flash_colors)
        if live.blink == "unclear":
            review_reasons.append("blink_unclear")
        if live.flash in ("weak", "incomplete"):
            review_reasons.append("flash_" + live.flash)
        if antispoof is not None:
            detail["antispoof"] = antispoof
            spoof = antispoof.get("spoof")
            if spoof is not None and spoof >= antispoof_review_threshold:
                review_reasons.append("antispoof")
        if review_reasons:
            detail["review_reasons"] = review_reasons

    base = dict(
        match_score=match,
        liveness_score=liveness,
        pose_ok=pose_ok,
        crossmatch_student_id=cross_id,
        crossmatch_score=cross_score,
        detail=detail,
    )

    # Somebody else's face scoring higher than your own is the signature of a
    # student marking on a friend's account. Never auto-accept it.
    if hit_id is not None:
        return VerifyResult(
            "fail",
            "CROSSMATCH",
            "Face does not match this account. This attempt has been recorded.",
            **base,
        )

    if consistency < consistency_threshold:
        return VerifyResult(
            "fail",
            "MIXED_FACES",
            "The photos did not all show the same person. Make sure you are alone and try again.",
            **base,
        )

    if not pose_ok:
        return VerifyResult(
            "fail",
            "POSE_CHALLENGE_FAILED",
            "Please follow the on-screen head movements exactly."
            if not v2 else "Please turn your head the way the arrow shows, and hold it there.",
            **base,
        )

    if v2 and live.blink == "not_seen":
        return VerifyResult(
            "fail",
            "BLINK_CHALLENGE_FAILED",
            "We could not see you blink. Please blink once, clearly, when asked.",
            **base,
        )

    if v2 and live.flash == "fail":
        return VerifyResult(
            "fail",
            "FLASH_CHALLENGE_FAILED",
            "Could not confirm a live face. Keep your face close to the screen and still while it changes colour.",
            **base,
        )

    if liveness < liveness_threshold:
        return VerifyResult(
            "fail",
            "LIVENESS_FAILED",
            "Could not confirm a live face. Photos and screens are not accepted.",
            **base,
        )

    if match >= match_threshold:
        if review_reasons:
            # Only soft reasons can get here (every hard failure returned
            # above). A strong enough match settles them: the office would be
            # looking at photos the model is already sure about.
            soft = all(r in SOFT_REVIEW_REASONS for r in review_reasons)
            if soft and match >= review_skip_match:
                detail["review_skipped"] = {"reasons": review_reasons, "match": round(match, 4),
                                            "review_skip_match": review_skip_match}
                return VerifyResult("pass", None, "Face verified.", **base)
            return VerifyResult(
                "pending",
                "LIVENESS_REVIEW",
                "Sent to the office for review — you will not be marked absent.",
                **base,
            )
        return VerifyResult("pass", None, "Face verified.", **base)

    if match >= review_threshold:
        return VerifyResult(
            "pending",
            "LOW_CONFIDENCE",
            "Sent to the office for review — you will not be marked absent.",
            **base,
        )

    return VerifyResult(
        "fail",
        "NO_MATCH",
        "Face did not match your enrolled photo. Contact the office if this repeats.",
        **base,
    )


# ---------------------------------------------------------------------------
# Mirrored frames
# ---------------------------------------------------------------------------
#
# The cheapest way to fake "turn one way, then the other" from photos is ONE
# turned photo plus its horizontal flip. A real head turned the other way is a
# different picture — face asymmetry, hair, side lighting and the background
# all change — so a turn frame that matches the MIRROR of a frame from another
# turn step almost pixel for pixel is not a live capture. Compared on small
# grayscale thumbnails (area-averaged, so sensor noise and JPEG re-encoding
# wash out) after removing any overall brightness shift.
MIRROR_THUMB = (48, 64)          # width, height
MIRROR_MAD_MAX = 2.0             # mean abs difference, grey levels (0-255)
# A near-featureless frame (lens covered, black frame from a stalled camera)
# is its own mirror image; those are left to the NO_FACE path, not called fraud.
MIRROR_MIN_DETAIL = 10.0         # std-dev of the thumbnail, grey levels


def frame_thumb(img: np.ndarray | None) -> np.ndarray | None:
    if img is None or img.size == 0:
        return None
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return cv2.resize(gray, MIRROR_THUMB, interpolation=cv2.INTER_AREA).astype(np.float32)


def mirrored_frames(
    thumbs: Sequence[np.ndarray | None],
    challenge: Sequence[str] | Challenge,
    frames_per_step: int = FRAMES_PER_STEP,
) -> tuple[int, int, float] | None:
    """(i, j, difference) for the first pair of frames from two DIFFERENT turn
    steps where one is (near-)identical to the mirror image of the other, else
    None. Frames of the same step, and centre frames, are never compared: a
    student holding still produces near-identical frames legitimately. In a v2
    challenge the turn steps are the named sides, and steps may hold different
    numbers of frames (the flash), so frames are mapped to steps by the plan."""
    if isinstance(challenge, Challenge):
        steps, step_of = challenge.steps, challenge.step_of_frame()
    else:
        steps = list(challenge)
        step_of = [i // frames_per_step for i in range(len(thumbs))]
    turns = (POSE_TURN, POSE_OTHER, POSE_LEFT, POSE_RIGHT)
    turn_frames = [
        i for i, t in enumerate(thumbs)
        if t is not None and float(t.std()) >= MIRROR_MIN_DETAIL
        and i < len(step_of) and step_of[i] < len(steps)
        and steps[step_of[i]] in turns
    ]
    for x in turn_frames:
        for y in turn_frames:
            if y <= x or step_of[x] == step_of[y]:
                continue
            diff = thumbs[x] - thumbs[y][:, ::-1]
            mad = float(np.mean(np.abs(diff - diff.mean())))
            if mad < MIRROR_MAD_MAX:
                return x, y, mad
    return None


# v2 asks for ONE turn, so there is no second turn for a flipped copy to be
# compared with. The single-turn form of the same fake: the challenge says
# LEFT, the attacker has a photo of the student turned to the RIGHT, and flips
# it. The face then turns the right way — but everything around it is mirrored
# too. Taken in the same place as the burst's straight-on frames, its
# background is the MIRROR of theirs, which a live turn's background never is.
# Compared on the thumbnails' outer bands (beside the head, so mostly
# background), and only when the background is asymmetric enough to tell.
FLIP_BAND = 10                   # thumbnail columns at each side (~21% of the width)
FLIP_MATCH_MAX = 4.0             # flipped background this close (grey levels)...
FLIP_RATIO = 0.6                 # ...and clearly closer than unflipped
FLIP_ASYMMETRY_MIN = 6.0         # unflipped differs at least this: telling is possible


def _bands(thumb: np.ndarray) -> np.ndarray:
    return np.concatenate([thumb[:, :FLIP_BAND], thumb[:, -FLIP_BAND:]], axis=1)


def _mad(a: np.ndarray, b: np.ndarray) -> float:
    d = a - b
    return float(np.mean(np.abs(d - d.mean())))


def flipped_turn(thumbs: Sequence[np.ndarray | None], plan: Challenge) -> tuple[int, int, float, float] | None:
    """(turn frame, straight frame, flipped diff, direct diff) when a turn
    frame's background is the mirror image of the straight-on frames'
    background, else None."""
    step_of = plan.step_of_frame()
    ok = [i for i, t in enumerate(thumbs) if t is not None and i < len(step_of)
          and float(t.std()) >= MIRROR_MIN_DETAIL]
    turns = [i for i in ok if plan.steps[step_of[i]] in (POSE_LEFT, POSE_RIGHT)]
    straight = [i for i in ok if plan.steps[step_of[i]] in (POSE_CENTRE, POSE_BLINK)]
    if not straight:
        return None
    for x in turns:
        bx = _bands(thumbs[x])
        direct = [_mad(bx, _bands(thumbs[y])) for y in straight]
        flipped = [_mad(bx, _bands(thumbs[y][:, ::-1])) for y in straight]
        d_direct, d_flip = float(np.median(direct)), float(np.median(flipped))
        if d_direct >= FLIP_ASYMMETRY_MIN and d_flip <= FLIP_MATCH_MAX and d_flip < FLIP_RATIO * d_direct:
            return x, straight[int(np.argmin(flipped))], d_flip, d_direct
    return None


# ---------------------------------------------------------------------------
# Passive anti-spoof (v2, review flag only)
# ---------------------------------------------------------------------------
#
# MiniFASNetV2 from minivision's Silent-Face-Anti-Spoofing (Apache-2.0), as
# exported to ONNX by yakhyo/face-anti-spoofing (Apache-2.0); fetched with a
# checksum at image build (Dockerfile). Three classes on an 80x80 crop of 2.7x
# the face box: 0 = print, 1 = live, 2 = screen replay. Published evaluations
# of these small models show high error rates on unseen phones and lighting,
# so its verdict NEVER fails or passes an attempt by itself: a high spoof
# probability only sends an otherwise-passing attempt to office review, with
# the scores in the attempt's detail. Absent model = no signal, not an error.
ANTISPOOF_SCALE = 2.7
ANTISPOOF_SIZE = (80, 80)

_antispoof_lock = threading.Lock()
_antispoof = None
_antispoof_loaded = False


def get_antispoof():
    """The ONNX session, or None when the model file is not there."""
    global _antispoof, _antispoof_loaded
    if not _antispoof_loaded:
        with _antispoof_lock:
            if not _antispoof_loaded:
                path = get_settings().antispoof_model
                if path and os.path.isfile(path):
                    try:
                        import onnxruntime as ort
                        _antispoof = ort.InferenceSession(path, providers=["CPUExecutionProvider"])
                        log.info("anti-spoof model %s ready", path)
                    except Exception:
                        log.exception("anti-spoof model %s failed to load; continuing without it", path)
                else:
                    log.warning("anti-spoof model %s not found; passive spoof signal disabled", path)
                _antispoof_loaded = True
    return _antispoof


def antispoof_crop(img: np.ndarray, bbox) -> np.ndarray:
    """The model's input: a square-ish crop of ANTISPOOF_SCALE x the face box
    (shrunk to fit the frame), resized, BGR, float, CHW — no normalisation,
    as the model was trained."""
    h, w = img.shape[:2]
    x1, y1, x2, y2 = [float(v) for v in bbox[:4]]
    bw, bh = max(x2 - x1, 1.0), max(y2 - y1, 1.0)
    scale = min((h - 1) / bh, (w - 1) / bw, ANTISPOOF_SCALE)
    cx, cy = x1 + bw / 2, y1 + bh / 2
    nx1, ny1 = max(0, int(cx - bw * scale / 2)), max(0, int(cy - bh * scale / 2))
    nx2, ny2 = min(w - 1, int(cx + bw * scale / 2)), min(h - 1, int(cy + bh * scale / 2))
    crop = cv2.resize(img[ny1:ny2 + 1, nx1:nx2 + 1], ANTISPOOF_SIZE)
    return crop.astype(np.float32).transpose(2, 0, 1)[None]


def antispoof_scores(images: Sequence[np.ndarray | None], analyses: Sequence[FrameAnalysis],
                     indexes: Sequence[int]) -> dict | None:
    """Mean class probabilities over the given frames; spoof = 1 - live."""
    session = get_antispoof()
    if session is None:
        return None
    probs = []
    for i in indexes:
        if i < len(images) and images[i] is not None and analyses[i].ok and analyses[i].bbox:
            logits = session.run(None, {session.get_inputs()[0].name: antispoof_crop(images[i], analyses[i].bbox)})[0][0]
            e = np.exp(logits - np.max(logits))
            probs.append(e / e.sum())
    if not probs:
        return None
    p = np.mean(probs, axis=0)
    return {"frames": list(indexes), "print": round(float(p[0]), 4), "live": round(float(p[1]), 4),
            "replay": round(float(p[2]), 4), "spoof": round(float(1.0 - p[1]), 4)}


def verify_burst(
    blobs: Sequence[bytes],
    challenge: Sequence[str] | Challenge,
    own_template: list[list[float]],
    **kwargs,
) -> VerifyResult:
    """Decode, analyse and judge one marking burst. CPU-bound: run it through
    run_in_engine(). Frames keep their positions (an unreadable one stays in
    place as a failed analysis) because position is what ties a frame to its
    challenge step."""
    images = [decode_image(b) for b in blobs]
    thumbs = [frame_thumb(img) for img in images]
    steps = challenge.steps if isinstance(challenge, Challenge) else challenge
    mirrored = mirrored_frames(thumbs, challenge)
    if mirrored is not None:
        x, y, mad = mirrored
        return VerifyResult(
            "fail",
            "MIRRORED_FRAMES",
            "These photos did not come from a live camera. This attempt has been recorded.",
            detail={"challenge": list(steps), "mirrored_pair": [x, y], "mirror_diff": round(mad, 3)},
        )
    v2 = isinstance(challenge, Challenge) and challenge.protocol == LIVENESS_V2
    if v2:
        flipped = flipped_turn(thumbs, challenge)
        if flipped is not None:
            x, y, d_flip, d_direct = flipped
            return VerifyResult(
                "fail",
                "MIRRORED_FRAMES",
                "These photos did not come from a live camera. This attempt has been recorded.",
                detail={"challenge": list(steps), "flipped_turn": [x, y],
                        "background_diff": {"flipped": round(d_flip, 3), "direct": round(d_direct, 3)}},
            )

    if not v2:
        analyses = [analyse_frame(img) for img in images]
        return judge_burst(analyses, challenge, own_template, **kwargs)

    # v2. With MediaPipe judging the blink, no frame needs the 3D landmarks
    # (sides come from the detection keypoints), so all but the first frame use
    # the light analysis (320 px detection, no 3D landmarks: ~0.4 s instead of
    # ~0.7 s a frame on the dev box). The first centre frame keeps the full
    # 640 px pass, so a small second face — a photo held up behind — is still
    # looked for at full size once per burst. Without MediaPipe, the fallback
    # blink measures need the landmarks on every frame.
    step_of = challenge.step_of_frame()
    starts = [sum(challenge.frame_counts()[:i]) for i in range(len(steps))]
    centre = starts[steps.index(POSE_CENTRE)]
    mp_ok = POSE_BLINK in steps and get_blink_model() is not None
    analyses = []
    for i, img in enumerate(images):
        step = steps[step_of[i]] if i < len(step_of) else None
        light = step == POSE_FLASH or (mp_ok and i != centre)
        analyses.append(analyse_frame(img, light=light))
    if mp_ok:
        b = starts[steps.index(POSE_BLINK)]
        kwargs["blink"] = {"closed": blink_scores(images[b]), "opened": blink_scores(images[b + 1])}
    # The centre step's two frames: straight-on, untinted, eyes open.
    kwargs["antispoof"] = antispoof_scores(images, analyses, [centre, centre + 1])
    return judge_burst(analyses, challenge, own_template, **kwargs)
