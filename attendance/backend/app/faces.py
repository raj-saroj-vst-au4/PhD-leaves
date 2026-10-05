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


def analyse_frame(img: np.ndarray | None) -> FrameAnalysis:
    s = get_settings()
    if img is None or img.size == 0:
        return FrameAnalysis(False, "UNREADABLE_IMAGE")

    faces = get_model().get(img)
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

    yaw, pitch = _yaw_from_pose(face)

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


def judge_burst(
    analyses: Sequence[FrameAnalysis],
    challenge: Sequence[str],
    own_template: list[list[float]],
    *,
    others: list[tuple[int, list[list[float]]]],
    match_threshold: float,
    review_threshold: float,
    liveness_threshold: float,
    crossmatch_margin: float,
    consistency_threshold: float = DEFAULT_CONSISTENCY_THRESHOLD,
) -> VerifyResult:
    """Decide one marking attempt from already-analysed frames (no model here,
    so the rules are unit-testable). `others` is the rest of the enrolled
    cohort, used for the cross-match check."""

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

    if len(good) < len(challenge):
        return VerifyResult(
            "fail",
            "INSUFFICIENT_FRAMES",
            "Not all steps were captured clearly. Please try again.",
        )

    # --- pose challenge: each step, in order, in its own frames ---
    pose_ok, step_evidence = check_challenge(analyses, challenge)

    # --- liveness: texture across the burst, weakest frame carries weight ---
    textures = [a.texture_score for a in good]
    liveness = float(0.5 * np.mean(textures) + 0.5 * np.min(textures))

    # --- identity: the WEAKEST frame decides ---
    # A median let a burst mix people: three frames of the account holder (or
    # photos of them) outvoted one frame of whoever actually performed the
    # turn. Every usable frame now has to be the enrolled student on its own.
    scores = [best_score(a.embedding, own_template) for a in good]
    match = float(min(scores))

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
        "challenge": list(challenge),
        "steps": step_evidence,
        "observed_poses": [a.pose if a.ok else None for a in analyses],
        "yaw": [round(a.yaw, 1) if a.ok else None for a in analyses],
        "per_frame_match": [round(s, 4) for s in scores],
        "per_frame_cross": per_frame_cross,
        "consistency_min": round(consistency, 4),
        "texture_scores": [round(t, 4) for t in textures],
        "rejected": [a.reason for a in analyses if not a.ok],
    }

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
            "Please follow the on-screen head movements exactly.",
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
    challenge: Sequence[str],
    frames_per_step: int = FRAMES_PER_STEP,
) -> tuple[int, int, float] | None:
    """(i, j, difference) for the first pair of frames from two DIFFERENT turn
    steps where one is (near-)identical to the mirror image of the other, else
    None. Frames of the same step, and centre frames, are never compared: a
    student holding still produces near-identical frames legitimately."""
    turn_frames = [
        i for i, t in enumerate(thumbs)
        if t is not None and float(t.std()) >= MIRROR_MIN_DETAIL
        and i // frames_per_step < len(challenge)
        and challenge[i // frames_per_step] in (POSE_TURN, POSE_OTHER)
    ]
    for x in turn_frames:
        for y in turn_frames:
            if y <= x or x // frames_per_step == y // frames_per_step:
                continue
            diff = thumbs[x] - thumbs[y][:, ::-1]
            mad = float(np.mean(np.abs(diff - diff.mean())))
            if mad < MIRROR_MAD_MAX:
                return x, y, mad
    return None


def verify_burst(
    blobs: Sequence[bytes],
    challenge: Sequence[str],
    own_template: list[list[float]],
    **kwargs,
) -> VerifyResult:
    """Decode, analyse and judge one marking burst. CPU-bound: run it through
    run_in_engine(). Frames keep their positions (an unreadable one stays in
    place as a failed analysis) because position is what ties a frame to its
    challenge step."""
    images = [decode_image(b) for b in blobs]
    mirrored = mirrored_frames([frame_thumb(img) for img in images], challenge)
    if mirrored is not None:
        x, y, mad = mirrored
        return VerifyResult(
            "fail",
            "MIRRORED_FRAMES",
            "These photos did not come from a live camera. This attempt has been recorded.",
            detail={"challenge": list(challenge), "mirrored_pair": [x, y], "mirror_diff": round(mad, 3)},
        )
    analyses = [analyse_frame(img) for img in images]
    return judge_burst(analyses, challenge, own_template, **kwargs)
