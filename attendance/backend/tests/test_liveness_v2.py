"""Liveness v2 (faces.py "Liveness v2"): the convention-free turn direction,
blink judgement (MediaPipe scores, and the landmark fallback), the dormant
colour-flash check, v2 challenges and their judgement, review_skip_match, the
single-turn mirror check, the feature flag, and that v1 is untouched. No
model: frames are synthetic FrameAnalysis objects, blendshape scores are given
as numbers, and the pixel-level measures run on synthetic images."""

import json
import random
from collections import Counter

import cv2
import numpy as np
import pytest
from fastapi.testclient import TestClient

from app import faces, main, marking
from app.auth import Principal
from app.faces import (
    LIVENESS_V1, LIVENESS_V2, POSE_BLINK, POSE_CENTRE, POSE_FLASH, POSE_LEFT, POSE_RIGHT,
    Challenge, FrameAnalysis, check_blink, check_blink_mp, check_challenge_v2, check_flash, judge_burst,
    new_challenge_v2, parse_challenge_spec, side_from_ratio, turn_ratio,
)

# ---------------------------------------------------------------------------
# Direction: which way the STUDENT turned, from raw-image keypoints
# ---------------------------------------------------------------------------
#
# Real detector output (buffalo_l SCRFD kps: two eyes, nose, two mouth corners)
# for two faces in insightface's sample photo t1.jpg (1280 px wide), measured
# in the attendance image. The photo itself is not committed (it is not ours
# to redistribute); the numbers are what the test needs.
#
#   JOEY — the man standing at the top right. Looked at by a person: his nose
#   points towards the image's LEFT and his LEFT ear is in view, i.e. he has
#   turned his head to HIS RIGHT. Landmark model: yaw -54.8°, roll -8°.
#   MONICA — behind the table, turned and tilted: nose towards the image's
#   left, her left ear in view, so turned to HER RIGHT; yaw -36.7°, roll -29°.
#   ROSS — seated centre-left, facing the camera: yaw -1.7°, roll 15°.
JOEY = [[918.6, 118.7], [947.5, 117.4], [920.7, 151.1], [938.9, 170.5], [960.8, 167.9]]
MONICA = [[288.2, 210.9], [319.6, 195.8], [302.6, 227.5], [312.4, 245.3], [336.9, 232.3]]
ROSS = [[491.9, 321.8], [541.9, 332.1], [507.7, 366.4], [485.9, 369.7], [533.7, 378.4]]
T1_WIDTH = 1280


def mirror(kps, width=T1_WIDTH):
    """The same face in a horizontally flipped photo: a person who turned to
    their right now appears to have turned to their left."""
    return [[width - x, y] for x, y in kps]


def rotate(kps, degrees, centre=(640.0, 443.0)):
    t = np.radians(degrees)
    r = np.array([[np.cos(t), -np.sin(t)], [np.sin(t), np.cos(t)]])
    return ((np.asarray(kps) - centre) @ r.T + centre).tolist()


def test_a_head_turned_to_its_own_right_reads_right():
    assert turn_ratio(JOEY) == pytest.approx(-0.474, abs=0.005)
    assert side_from_ratio(turn_ratio(JOEY)) == POSE_RIGHT
    assert side_from_ratio(turn_ratio(MONICA)) == POSE_RIGHT


def test_the_mirror_image_reads_left():
    assert side_from_ratio(turn_ratio(mirror(JOEY))) == POSE_LEFT
    assert turn_ratio(mirror(JOEY)) == pytest.approx(-turn_ratio(JOEY), abs=1e-9)


def test_straight_on_reads_centre():
    assert side_from_ratio(turn_ratio(ROSS)) == POSE_CENTRE


def test_the_models_yaw_sign_would_have_named_the_wrong_side():
    """Why v2 does not use the Euler yaw: Joey's -54.8° is what v1's
    _pose_label calls 'left' — but he turned to his right."""
    assert faces._pose_label(-54.8) == POSE_LEFT
    assert side_from_ratio(turn_ratio(JOEY)) == POSE_RIGHT


def test_head_roll_does_not_change_the_reading():
    """Monica's roll (-29°) hides her turn on the image x axis (≈ -0.04,
    'centre'); along the eye line it is -0.33. Rolling the keypoints further
    either way changes nothing."""
    a, b, nose = np.asarray(MONICA[0]), np.asarray(MONICA[1]), np.asarray(MONICA[2])
    naive = (nose[0] - (a[0] + b[0]) / 2) / np.linalg.norm(b - a)
    assert abs(naive) < 0.085                     # the x axis would say "centre"
    for deg in (-25, -10, 10, 25):
        assert turn_ratio(rotate(MONICA, deg)) == pytest.approx(turn_ratio(MONICA), abs=1e-9)


def test_keypoint_order_is_not_a_convention():
    """Eyes are ordered by image position, so a detector that listed them the
    other way round reads the same."""
    swapped = [JOEY[1], JOEY[0]] + JOEY[2:]
    assert turn_ratio(swapped) == pytest.approx(turn_ratio(JOEY))


def test_thresholds_keep_the_v1_degree_semantics():
    # 0.4·tan(15°) and 0.4·tan(12°) — see faces.py "Scale"
    assert faces.TURN_RATIO_MIN == pytest.approx(0.4 * np.tan(np.radians(15)), abs=0.005)
    assert faces.CENTRE_RATIO_MAX == pytest.approx(0.4 * np.tan(np.radians(12)), abs=0.005)
    assert side_from_ratio(0.10) == "between" and side_from_ratio(-0.10) == "between"
    assert side_from_ratio(0.11) == POSE_LEFT and side_from_ratio(-0.11) == POSE_RIGHT


# ---------------------------------------------------------------------------
# Eye measures
# ---------------------------------------------------------------------------

def eye(height: float):
    """Six 68-point-order eye points, 30 px wide, lids `height` apart."""
    h = height / 2
    return [[0, 0], [10, -h], [20, -h], [30, 0], [20, h], [10, h]]


def test_eye_aspect_ratio():
    assert faces.eye_aspect_ratio(eye(9)) == pytest.approx(0.3)
    assert faces.eye_aspect_ratio(eye(1.5)) == pytest.approx(0.05)
    lm = np.zeros((68, 3))
    lm[36:42, :2] = eye(9)
    lm[42:48, :2] = np.asarray(eye(3)) + [60, 0]
    assert faces.eye_aspect_ratios(lm) == pytest.approx((0.3, 0.1))


def face_with_eyes(open_: bool) -> tuple[np.ndarray, np.ndarray]:
    """A grey 'face' with two eyes: a dark iris disc when open, skin and a thin
    lash line when shut. Returns (gray image, 68 landmarks)."""
    img = np.full((240, 240), 170, np.uint8)
    lm = np.zeros((68, 3))
    for base, cx in ((36, 80), (42, 160)):
        lm[base:base + 6, :2] = np.asarray(eye(10)) + [cx - 15, 100]
        if open_:
            cv2.ellipse(img, (cx, 100), (14, 5), 0, 0, 360, 235, -1)   # sclera
            cv2.circle(img, (cx, 100), 6, 40, -1)                      # iris
        else:
            cv2.line(img, (cx - 14, 102), (cx + 14, 102), 60, 1)       # lashes
    return img, lm


def test_eye_darkness_sees_the_iris():
    open_dark = faces.eye_darkness(*face_with_eyes(True))
    shut_dark = faces.eye_darkness(*face_with_eyes(False))
    assert open_dark > 0.15 and shut_dark < 0.6 * open_dark


def fa(side=POSE_CENTRE, emb=None, ear=0.30, dark=0.40, ok=True, turn=None, skin=None, bg=None,
       texture=0.9):
    t = {POSE_CENTRE: 0.0, POSE_LEFT: 0.25, POSE_RIGHT: -0.25}.get(side, 0.1) if turn is None else turn
    return FrameAnalysis(ok=ok, embedding=emb, turn=t, side=side_from_ratio(t), ear=ear, eye_dark=dark,
                         texture_score=texture, skin_rgb=skin, bg_rgb=bg,
                         reason=None if ok else "NO_FACE")


REFS = [fa(), fa()]


@pytest.mark.parametrize("closed,opened,expected", [
    (dict(ear=0.20, dark=0.38), dict(), "seen"),          # the landmarks saw the lids shut
    (dict(ear=0.29, dark=0.10), dict(), "seen"),          # only the iris vanished (the usual case)
    (dict(ear=0.29, dark=0.80), dict(), "seen"),          # dark lids / lash line: darker, still changed
    (dict(ear=0.30, dark=0.40), dict(), "not_seen"),      # nothing changed
    (dict(ear=0.29, dark=0.30), dict(), "unclear"),       # a bit of both: a person decides
    (dict(ear=0.20, dark=0.10), dict(ear=0.20, dark=0.10), "unclear"),  # never reopened
    (dict(ok=False), dict(), "unclear"),
])
def test_blink_judgement(closed, opened, expected):
    o = fa(**opened)
    assert check_blink(fa(**closed), o, REFS + [o])[0] == expected


def test_blink_is_relative_to_the_students_own_eyes():
    """Narrow eyes (open EAR 0.18) blinking to 0.12 is a blink; for wide eyes
    (open 0.36) the same 0.12 would be one too — the absolute value decides
    nothing, the drop from this burst's open eyes does."""
    narrow = [fa(ear=0.18, dark=0.25) for _ in range(3)]
    assert check_blink(fa(ear=0.12, dark=0.24), narrow[0], narrow)[0] == "seen"
    wide = [fa(ear=0.36, dark=0.45) for _ in range(3)]
    assert check_blink(fa(ear=0.34, dark=0.44), wide[0], wide)[0] == "not_seen"
    assert check_blink(fa(ear=0.20, dark=0.44), wide[0], wide)[0] == "seen"


# ---------------------------------------------------------------------------
# The blink, on MediaPipe's eyeBlinkLeft/Right scores (0 open .. 1 shut)
# ---------------------------------------------------------------------------
#
# Reference points from the real model (Python MediaPipe 0.10.35, IMAGE mode)
# on frames the browser harness uploaded and on t1.jpg crops: open eyes
# 0.21/0.10, the same face eyes shut 0.64/0.52, people squinting while laughing
# 0.69/0.35 and 0.61/0.53.

@pytest.mark.parametrize("closed,opened,expected", [
    ((0.64, 0.52), (0.21, 0.10), "seen"),          # the harness's real shut / open frames
    ((0.85, 0.80), (0.05, 0.04), "seen"),          # a full blink
    ((0.21, 0.10), (0.21, 0.10), "not_seen"),      # the "shut" frame is as open as the open one
    ((0.26, 0.20), (0.18, 0.12), "not_seen"),
    ((0.50, 0.42), (0.40, 0.30), "unclear"),       # some closure, not enough apart
    ((0.75, 0.70), (0.50, 0.46), "unclear"),       # the open frame never really opened
    ((0.30, 0.22), (0.10, 0.08), "unclear"),       # a little lower, not shut
    (None, (0.2, 0.1), "unclear"),                 # no face found in a frame
])
def test_mediapipe_blink(closed, opened, expected):
    verdict, ev = check_blink_mp(closed, opened)
    assert verdict == expected, ev
    assert ev["method"] == "mediapipe"


def test_mediapipe_blink_thresholds_come_from_settings():
    assert check_blink_mp((0.64, 0.52), (0.21, 0.10), closed_min=0.7)[0] == "unclear"
    assert check_blink_mp((0.64, 0.52), (0.21, 0.10), min_diff=0.5)[0] == "unclear"
    assert check_blink_mp((0.80, 0.80), (0.45, 0.45), open_max=0.5)[0] == "seen"


def test_the_phone_only_sends_what_the_server_will_call_shut():
    """liveness.js keeps a frame as 'shut' only at a mean >= 0.50 with each
    eye >= 0.40, then an open frame 0.28 below it and at most 0.34; the
    server's 'seen' is >= 0.45, <= 0.40 and 0.25 apart. 'Not seen' needs the
    shut frame under 0.30 — 0.20 below anything the phone sends as shut."""
    assert check_blink_mp((0.50, 0.50), (0.22, 0.22))[0] == "seen"
    assert check_blink_mp((0.55, 0.45), (0.22, 0.22))[0] == "seen"
    assert faces.BLINK_NOT_SEEN_MAX <= 0.50 - 0.20


# ---------------------------------------------------------------------------
# Colour flash (dormant: att_settings flash_enabled='1')
# ---------------------------------------------------------------------------
#
# Synthetic frames: a wall and a skin-coloured oval, rendered in linear light
# and encoded like a phone frame (sRGB, JPEG). The screen adds `k` x its
# colour to the light on the face (a tenth of that on the wall, which is
# further away), and the camera may change white balance / exposure per frame.

FW, FH = 480, 640
F_KPS = np.array([[195, 300], [285, 300], [240, 350], [205, 400], [275, 400]], float)
F_BBOX = (160, 220, 320, 450)


def _to_srgb(x):
    x = np.clip(x, 0, 1)
    return np.where(x <= 0.0031308, 12.92 * x, 1.055 * x ** (1 / 2.4) - 0.055)


def flash_frame(screen_rgb, k, rng, awb=0.0):
    s = faces._srgb_to_linear(np.asarray(screen_rgb))
    yy, xx = np.mgrid[0:FH, 0:FW]
    face = ((xx - 240) / 80.0) ** 2 + ((yy - 335) / 115.0) ** 2 <= 1
    img = np.empty((FH, FW, 3))
    img[:] = np.array([0.30, 0.32, 0.34]) * (1 + 0.1 * k * s)
    img[face] = np.array([0.55, 0.36, 0.27]) * (1 + k * s)
    img *= 0.8 * (1 + awb * rng.uniform(-1, 1, 3))
    img += rng.normal(0, 0.01, img.shape) * img
    bgr = cv2.cvtColor((_to_srgb(img) * 255).round().astype(np.uint8), cv2.COLOR_RGB2BGR)
    return cv2.imdecode(cv2.imencode(".jpg", bgr, [cv2.IMWRITE_JPEG_QUALITY, 85])[1], cv2.IMREAD_COLOR)


def flash_analysis(img):
    skin, bg = faces.skin_and_background(img, F_KPS, F_BBOX)
    return FrameAnalysis(ok=True, skin_rgb=skin, bg_rgb=bg)


def commanded(seed):
    colors, _ = faces.new_flash(random.Random(seed))
    return [faces.FLASH_PALETTE[c] for c in colors]


@pytest.mark.parametrize("seed", range(4))
def test_a_face_that_follows_the_colours_passes(seed):
    rng = np.random.default_rng(seed)
    rgb = commanded(seed)
    frames = [flash_analysis(flash_frame(c, 0.1, rng, awb=0.05)) for c in rgb]
    verdict, ev = check_flash(frames, rgb)
    assert verdict == "pass", ev
    assert ev["correlation"] >= 0.9 and ev["signal"] >= faces.DEFAULT_FLASH_MIN_SIGNAL


@pytest.mark.parametrize("seed", range(4))
def test_colours_independent_of_the_sequence_fail(seed):
    """A face lit by some OTHER sequence — a recording, or a guess."""
    rng = np.random.default_rng(seed)
    frames = [flash_analysis(flash_frame(c, 0.1, rng)) for c in commanded(100 + seed)]
    verdict, ev = check_flash(frames, commanded(seed))
    assert verdict == "fail", ev
    assert ev["correlation"] < faces.DEFAULT_FLASH_MIN_CORRELATION


def test_a_face_whose_colour_never_changes_fails():
    rng = np.random.default_rng(0)
    still = flash_analysis(flash_frame((128, 128, 128), 0.0, rng))
    verdict, ev = check_flash([still] * faces.FLASH_COUNT, commanded(0))
    assert verdict == "fail" and ev["signal"] < faces.FLASH_FLAT_RMS


def test_a_response_too_weak_to_judge_goes_to_review():
    """A very bright room: the screen barely registers, and white balance
    wobbles about as much as the face does."""
    rng = np.random.default_rng(3)
    rgb = commanded(3)
    frames = [flash_analysis(flash_frame(c, 0.01, rng)) for c in rgb]
    verdict, ev = check_flash(frames, rgb)
    assert verdict == "weak", ev


def test_white_balance_alone_is_not_a_response():
    rng = np.random.default_rng(4)
    rgb = commanded(4)
    frames = [flash_analysis(flash_frame(c, 0.0, rng, awb=0.1)) for c in rgb]
    assert check_flash(frames, rgb)[0] in ("weak", "fail")


def test_a_flash_frame_without_a_face_cannot_be_judged():
    rng = np.random.default_rng(5)
    rgb = commanded(5)
    frames = [flash_analysis(flash_frame(c, 0.1, rng)) for c in rgb]
    frames[3] = FrameAnalysis(ok=False, reason="NO_FACE")
    assert check_flash(frames, rgb)[0] == "incomplete"


def test_flash_thresholds_come_from_settings():
    rng = np.random.default_rng(6)
    rgb = commanded(6)
    frames = [flash_analysis(flash_frame(c, 0.1, rng)) for c in rgb]
    assert check_flash(frames, rgb, min_signal=0.5)[0] == "weak"
    assert check_flash(frames, rgb, min_correlation=1.01)[0] == "fail"


def test_skin_and_background_are_rgb():
    img = np.zeros((FH, FW, 3), np.uint8)
    img[:] = (200, 100, 50)          # BGR: blue-ish background
    cv2.ellipse(img, (240, 335), (80, 115), 0, 0, 360, (60, 120, 220), -1)   # BGR: orange skin
    skin, bg = faces.skin_and_background(img, F_KPS, F_BBOX)
    assert skin == pytest.approx((220, 120, 60), abs=1) and bg == pytest.approx((50, 100, 200), abs=1)


# ---------------------------------------------------------------------------
# v2 challenges
# ---------------------------------------------------------------------------

def test_v2_challenges_are_centre_then_one_named_turn_and_a_blink():
    for _ in range(2000):
        c = new_challenge_v2()
        assert c.protocol == LIVENESS_V2 and c.steps[0] == POSE_CENTRE and len(c.steps) == 3
        assert len([s for s in c.steps if s in (POSE_LEFT, POSE_RIGHT)]) == 1
        assert POSE_BLINK in c.steps and POSE_FLASH not in c.steps and not c.flash_colors
        assert c.frame_counts() == [2, 2, 2]
        assert len(c.spec()) <= 100                     # att_challenges.challenge VARCHAR(100)
        assert parse_challenge_spec(c.spec()) == c


def test_side_and_order_are_drawn_uniformly_with_the_csprng():
    assert len(faces.SEQUENCES_V2) == 4
    rng = random.Random(9)
    counts = Counter(new_challenge_v2(rng).steps for _ in range(40000))
    assert set(counts) == set(faces.SEQUENCES_V2)
    assert max(counts.values()) / 40000 < 0.25 * 1.05
    sides = Counter(next(s for s in steps if s in (POSE_LEFT, POSE_RIGHT)) for steps in counts.elements())
    assert abs(sides[POSE_LEFT] / 40000 - 0.5) < 0.02
    assert isinstance(faces._rng, random.SystemRandom)


def test_the_flash_only_joins_when_switched_on():
    for _ in range(500):
        c = new_challenge_v2(flash=True)
        assert c.steps[0] == POSE_CENTRE and len(c.steps) == 4 and POSE_FLASH in c.steps
        assert len(c.flash_colors) == faces.FLASH_COUNT == len(c.flash_ms)
        assert all(a != b for a, b in zip(c.flash_colors, c.flash_colors[1:]))
        assert all(faces.FLASH_MIN_MS <= ms <= faces.FLASH_MAX_MS for ms in c.flash_ms)
        assert parse_challenge_spec(c.spec()) == c and len(c.spec()) <= 100
    assert len(faces.SEQUENCES_V2_FLASH) == 12


def test_a_v2_burst_fits_through_the_php_forwarder():
    """attapi/index.php receives the frames as PHP uploads first, and PHP's
    default max_file_uploads is 20: a burst of more files is silently cut."""
    assert max(sum(Challenge(LIVENESS_V2, seq).frame_counts()) for seq in faces.SEQUENCES_V2) == 6
    with_flash = max(sum(Challenge(LIVENESS_V2, seq, tuple("RGBYMCR"), (400,) * 7).frame_counts())
                     for seq in faces.SEQUENCES_V2_FLASH)
    assert with_flash == 13 and with_flash <= 20


def test_flash_never_changes_faster_than_three_times_a_second():
    assert faces.FLASH_MIN_MS > 1000 / 3


def test_frame_counts_and_timing_floor():
    c = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_BLINK, POSE_RIGHT))
    assert c.frame_counts() == [2, 2, 2] and c.step_of_frame() == [0, 0, 1, 1, 2, 2]
    assert c.min_seconds() == pytest.approx(0.4 * 3)
    f = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_FLASH, POSE_LEFT, POSE_BLINK), tuple("RGBYMCR"), (400,) * 7)
    assert f.frame_counts() == [2, 7, 2, 2]
    assert f.min_seconds() == pytest.approx(0.4 * 3 + 0.8 * 2.8)


@pytest.mark.parametrize("spec", [
    "v2:centre,left,right,blink",                                          # both sides: no longer issued
    "v2:centre,left,flash,blink,right|RGBYMCR|400,400,400,400,400,400,400",  # the first pilot's shape
    "v2:left,centre,blink",                                                # centre not first
    "v2:centre,left,left",
    "v2:centre,blink",                                                     # no turn
    "v2:centre,left,blink,flash",                                          # flash without colours
    "v2:centre,left,blink,flash|RRBYMCR|400,400,400,400,400,400,400",      # repeat back to back
    "v2:centre,left,blink,flash|RGBYMCX|400,400,400,400,400,400,400",      # unknown colour
    "v2:centre,left,blink,flash|RGBYMCR|100,400,400,400,400,400,400",      # too fast
    "v2:centre,left,blink,flash|RGBYMC|400,400,400,400,400,400",           # too few
    "v2:centre,left,blink|RGB|400,400,400",                                # colours without a flash
    "v2:centre,turn,other",
    "centre,left",
])
def test_malformed_specs_are_refused(spec):
    assert parse_challenge_spec(spec) is None


def test_v1_specs_still_parse_as_v1():
    c = parse_challenge_spec("centre,turn,other")
    assert c == Challenge(LIVENESS_V1, ("centre", "turn", "other")) and c.spec() == "centre,turn,other"
    assert c.frame_counts() == [2, 2, 2]


# ---------------------------------------------------------------------------
# Judging a v2 burst
# ---------------------------------------------------------------------------

DIM = 16


def person(seed):
    v = np.random.default_rng(seed).normal(size=DIM).astype(np.float32)
    return v / np.linalg.norm(v)


def near(base, jitter, seed):
    v = base + jitter * np.random.default_rng(seed).normal(size=DIM).astype(np.float32)
    return (v / np.linalg.norm(v)).astype(np.float32)


ALICE, BOB = person(1), person(2)
ALICE_T = [near(ALICE, 0.1, s).tolist() for s in range(10, 14)]
BOB_T = [near(BOB, 0.1, s).tolist() for s in range(20, 24)]
TH = dict(match_threshold=0.42, review_threshold=0.32, liveness_threshold=0.60, crossmatch_margin=0.05)

# frames: 0,1 centre | 2,3 left | 4 shut, 5 open
PLAN = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_LEFT, POSE_BLINK))
PLAN_FLASH = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_LEFT, POSE_FLASH, POSE_BLINK),
                       tuple("RGBYMCR"), (400,) * 7)
BLINKED = {"closed": (0.64, 0.52), "opened": (0.21, 0.10)}
NO_BLINK = {"closed": (0.21, 0.10), "opened": (0.20, 0.11)}
UNCLEAR = {"closed": (0.50, 0.42), "opened": (0.40, 0.30)}


def genuine_burst(plan=PLAN, k=0.1, seed=0, over=None):
    """A burst that does everything right; `over` replaces frames by index."""
    over = over or {}
    rng = np.random.default_rng(seed)
    out, n = [], 0
    for step, count in zip(plan.steps, plan.frame_counts()):
        for j in range(count):
            emb = near(ALICE, 0.15, 100 + n)
            if step == POSE_FLASH:
                img = flash_frame(plan.flash_rgb()[j], k, rng)
                skin, bg = faces.skin_and_background(img, F_KPS, F_BBOX)
                f = fa(emb=emb, skin=skin, bg=bg)
            elif step == POSE_BLINK:
                f = fa(emb=emb, ear=0.22, dark=0.08) if j == 0 else fa(emb=emb)
            else:
                f = fa(side=step, emb=emb)
            out.append(over.get(n, f))
            n += 1
    return out


def weaker(score_target_seed=9, a=0.45):
    w = (a * ALICE + np.sqrt(1 - a * a) * person(score_target_seed)).astype(np.float32)
    return w / np.linalg.norm(w)


def test_genuine_v2_burst_passes():
    res = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[(2, BOB_T)], blink=BLINKED, **TH)
    assert res.verdict == "pass", (res.fail_code, res.detail)
    d = res.detail
    assert d["protocol"] == 2 and d["blink"] == "seen" and d["flash"] == "absent"
    assert d["closed_frames"] == [4] and d["frames_submitted"] == 6
    assert d["steps"][2]["blink"]["method"] == "mediapipe"
    assert "review_skipped" not in d


def test_without_mediapipe_the_landmark_measures_judge_the_blink():
    res = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[], blink=None, **TH)
    assert res.verdict == "pass" and res.detail["blink"] == "seen"
    assert "method" not in res.detail["steps"][2]["blink"]


def test_the_named_side_must_be_the_one_asked_for():
    wrong = genuine_burst(over={2: fa(side=POSE_RIGHT, emb=ALICE), 3: fa(side=POSE_RIGHT, emb=ALICE)})
    res = judge_burst(wrong, PLAN, ALICE_T, others=[], blink=BLINKED, **TH)
    assert res.fail_code == "POSE_CHALLENGE_FAILED"
    assert [s["ok"] for s in res.detail["steps"]] == [True, False, True]
    right_plan = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_BLINK, POSE_RIGHT))
    burst = genuine_burst(plan=right_plan)
    assert judge_burst(burst, right_plan, ALICE_T, others=[], blink=BLINKED, **TH).verdict == "pass"


def test_a_wrong_side_is_never_waved_through_by_a_strong_match():
    wrong = genuine_burst(over={2: fa(side=POSE_RIGHT, emb=ALICE), 3: fa(side=POSE_RIGHT, emb=ALICE)})
    res = judge_burst(wrong, PLAN, ALICE_T, others=[], blink=BLINKED, review_skip_match=0.0, **TH)
    assert res.verdict == "fail" and res.fail_code == "POSE_CHALLENGE_FAILED"


def test_a_frame_caught_mid_turn_is_neutral_but_a_pair_spanning_two_poses_fails():
    between = genuine_burst(over={2: fa(turn=0.09, emb=near(ALICE, 0.15, 2))})
    assert judge_burst(between, PLAN, ALICE_T, others=[], blink=BLINKED, **TH).verdict == "pass"
    spanning = genuine_burst(over={2: fa(side=POSE_CENTRE, emb=near(ALICE, 0.15, 2))})
    res = judge_burst(spanning, PLAN, ALICE_T, others=[], blink=BLINKED, **TH)
    assert res.fail_code == "POSE_CHALLENGE_FAILED"


def test_the_shut_eye_frame_is_not_held_to_the_match_threshold():
    """ArcFace scores a shut-eyed frame lower; it only has to be the same
    person as the rest of the burst."""
    weak = weaker()
    assert faces.best_score(weak, ALICE_T) < 0.42
    burst = genuine_burst(over={4: fa(emb=weak, ear=0.22, dark=0.08)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[(2, BOB_T)], blink=BLINKED,
                      **dict(TH, consistency_threshold=0.05))
    assert res.verdict == "pass", (res.fail_code, res.detail)
    # ...but the same frame anywhere else sinks the burst (weakest frame decides).
    burst = genuine_burst(over={0: fa(emb=weak)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=BLINKED, **dict(TH, consistency_threshold=0.05))
    assert res.verdict != "pass"


def test_someone_else_blinking_is_caught():
    burst = genuine_burst(over={4: fa(emb=near(BOB, 0.1, 5), ear=0.22, dark=0.08)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[(2, BOB_T)], blink=BLINKED, **TH)
    assert res.fail_code == "CROSSMATCH" and res.crossmatch_student_id == 2
    stranger = genuine_burst(over={4: fa(emb=person(77), ear=0.22, dark=0.08)})
    assert judge_burst(stranger, PLAN, ALICE_T, others=[], blink=BLINKED, **TH).fail_code == "MIXED_FACES"


def test_a_definite_no_blink_fails_even_with_a_strong_match():
    res = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[], blink=NO_BLINK, review_skip_match=0.0, **TH)
    assert res.verdict == "fail" and res.fail_code == "BLINK_CHALLENGE_FAILED"


def test_a_blink_with_the_head_turned_fails():
    burst = genuine_burst(over={4: fa(side=POSE_LEFT, emb=near(ALICE, 0.15, 4))})
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=BLINKED, **TH)
    assert res.fail_code == "BLINK_CHALLENGE_FAILED"


# --- review_skip_match ------------------------------------------------------

def test_an_unclear_blink_passes_on_a_strong_match_and_says_so():
    res = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[], blink=UNCLEAR, **TH)
    assert res.match_score >= faces.DEFAULT_REVIEW_SKIP_MATCH
    assert res.verdict == "pass" and res.fail_code is None
    assert res.detail["review_reasons"] == ["blink_unclear"]
    assert res.detail["review_skipped"] == {"reasons": ["blink_unclear"], "match": round(res.match_score, 4),
                                            "review_skip_match": faces.DEFAULT_REVIEW_SKIP_MATCH}


def test_an_unclear_blink_goes_to_review_below_review_skip_match():
    res = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[], blink=UNCLEAR, review_skip_match=0.999, **TH)
    assert res.verdict == "pending" and res.fail_code == "LIVENESS_REVIEW"
    assert "review_skipped" not in res.detail
    # A real burst whose weakest open-eye frame matches at ~0.5: above the
    # match threshold, below 0.65 -> review, as before.
    mid = weaker(a=0.55)
    score = faces.best_score(mid, ALICE_T)
    assert 0.42 <= score < 0.65, score
    burst = genuine_burst(over={1: fa(emb=mid)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=UNCLEAR, **dict(TH, consistency_threshold=0.05))
    assert res.verdict == "pending" and res.match_score == pytest.approx(score, abs=1e-6)


def test_review_skip_match_reads_the_open_eye_minimum():
    """The score that decides is the one stored as att_attempts.match_score:
    the weakest OPEN-eye frame. A weak shut-eye frame does not pull it down."""
    burst = genuine_burst(over={4: fa(emb=weaker(a=0.5), ear=0.22, dark=0.08)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=UNCLEAR, **dict(TH, consistency_threshold=0.05))
    assert res.verdict == "pass" and res.detail["review_skipped"]["match"] == round(res.match_score, 4)
    assert res.match_score >= 0.65


def test_antispoof_is_a_soft_reason():
    burst = genuine_burst()
    high = {"spoof": 0.97, "live": 0.03, "print": 0.5, "replay": 0.47}
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=BLINKED, antispoof=high, **TH)
    assert res.verdict == "pass" and res.detail["review_skipped"]["reasons"] == ["antispoof"]
    assert res.detail["antispoof"] == high
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=BLINKED, antispoof=high,
                      review_skip_match=0.999, **TH)
    assert res.verdict == "pending" and res.detail["review_reasons"] == ["antispoof"]
    low = dict(high, spoof=0.2, live=0.8)
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=BLINKED, antispoof=low, **TH)
    assert res.verdict == "pass" and "review_reasons" not in res.detail
    # It never turns a failure into anything else.
    res = judge_burst(burst, PLAN, ALICE_T, others=[], blink=NO_BLINK, antispoof=high, **TH)
    assert res.fail_code == "BLINK_CHALLENGE_FAILED"


def test_v1_review_is_untouched_by_review_skip_match():
    """v1's review band (0.32-0.42) lies below the match threshold, and v1 has
    no soft liveness reasons, so even a zero review_skip_match changes nothing."""
    from app.faces import POSE_OTHER, POSE_TURN
    ch = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
    mixed = (ALICE * 0.40 + person(9) * 0.92)
    mixed = (mixed / np.linalg.norm(mixed)).astype(np.float32)
    frames = [FrameAnalysis(ok=True, embedding=e, pose=p, texture_score=0.9)
              for e, p in zip([near(ALICE, 0.15, 1), near(ALICE, 0.15, 2), near(ALICE, 0.15, 3),
                               near(ALICE, 0.15, 4), near(ALICE, 0.15, 5), mixed],
                              [POSE_CENTRE, POSE_CENTRE, POSE_LEFT, POSE_LEFT, POSE_RIGHT, POSE_RIGHT])]
    res = judge_burst(frames, ch, ALICE_T, others=[], review_skip_match=0.0,
                      **dict(TH, consistency_threshold=-1.0))
    assert res.verdict == "pending" and res.fail_code == "LOW_CONFIDENCE"
    assert "review_skipped" not in res.detail and "protocol" not in res.detail


# --- the dormant flash, if switched on --------------------------------------

def test_flash_outcomes_when_switched_on():
    weak = judge_burst(genuine_burst(plan=PLAN_FLASH, k=0.01, seed=3), PLAN_FLASH, ALICE_T, others=[],
                       blink=BLINKED, review_skip_match=0.999, **TH)
    assert weak.verdict == "pending" and weak.detail["review_reasons"] == ["flash_weak"]
    assert weak.detail["steps"][2]["flash"]["signal"] < faces.DEFAULT_FLASH_MIN_SIGNAL
    skipped = judge_burst(genuine_burst(plan=PLAN_FLASH, k=0.01, seed=3), PLAN_FLASH, ALICE_T, others=[],
                          blink=BLINKED, **TH)
    assert skipped.verdict == "pass" and skipped.detail["review_skipped"]["reasons"] == ["flash_weak"]

    other = Challenge(LIVENESS_V2, PLAN_FLASH.steps, tuple("GBRCYMB"), PLAN_FLASH.flash_ms)
    lit_wrong = genuine_burst(plan=other)          # the face followed a different sequence
    res = judge_burst(lit_wrong, PLAN_FLASH, ALICE_T, others=[], blink=BLINKED, review_skip_match=0.0, **TH)
    assert res.fail_code == "FLASH_CHALLENGE_FAILED", res.detail["steps"][2]

    ok = judge_burst(genuine_burst(plan=PLAN_FLASH), PLAN_FLASH, ALICE_T, others=[], blink=BLINKED, **TH)
    assert ok.verdict == "pass" and ok.detail["flash"] == "pass" and ok.detail["closed_frames"] == [11]


def test_wrong_frame_count_fails_the_pose_check():
    assert not check_challenge_v2(genuine_burst()[:-1], PLAN).pose_ok


# ---------------------------------------------------------------------------
# Mirrored frames with a single turn
# ---------------------------------------------------------------------------
#
# Synthetic 480x640 "photos": an asymmetric background (blobs on a gradient,
# like tests/test_mark_flow.py) with a head-sized ellipse in the middle.

def backdrop(seed=4, plain=False):
    rng = np.random.default_rng(seed)
    img = np.tile(np.linspace(40, 200, 480, dtype=np.uint8), (640, 1))
    img = cv2.cvtColor(img, cv2.COLOR_GRAY2BGR)
    if plain:
        img[:] = 120
        return img
    for _ in range(25):
        c = (int(rng.integers(0, 480)), int(rng.integers(0, 640)))
        cv2.circle(img, c, int(rng.integers(8, 60)), tuple(int(v) for v in rng.integers(0, 255, 3)), -1)
    return img


def shot(bg, head_x=240, shade=170, shift=0):
    img = np.roll(bg, shift, axis=1).copy()
    cv2.ellipse(img, (head_x, 300), (105, 140), 0, 0, 360, (shade, shade, shade + 10), -1)
    cv2.circle(img, (head_x + 20, 280), 12, (40, 40, 40), -1)
    return img


def thumbs_of(images):
    return [faces.frame_thumb(img) for img in images]


def test_one_turn_has_no_second_turn_to_mirror():
    imgs = [shot(backdrop())] * 6
    assert faces.mirrored_frames(thumbs_of(imgs), PLAN) is None


def test_a_flipped_photo_for_the_turn_is_caught_by_its_background():
    """Asked LEFT, holding a photo turned RIGHT from the same session: flip it.
    The face now turns the right way, but the background is mirrored."""
    bg = backdrop()
    centre, other_side = shot(bg), shot(bg, head_x=225, shade=150)
    imgs = [centre, shot(bg, shift=2), cv2.flip(other_side, 1), cv2.flip(other_side, 1), centre, shot(bg, shift=-2)]
    hit = faces.flipped_turn(thumbs_of(imgs), PLAN)
    assert hit is not None and hit[0] in (2, 3)
    assert hit[2] <= faces.FLIP_MATCH_MAX and hit[3] >= faces.FLIP_ASYMMETRY_MIN


def test_a_live_turn_is_not_called_flipped():
    bg = backdrop()
    # The head turns and the hand-held phone drifts a few pixels between steps.
    imgs = [shot(bg), shot(bg, shift=3), shot(bg, head_x=255, shade=150, shift=6),
            shot(bg, head_x=258, shade=148, shift=7), shot(bg, shift=-4), shot(bg, shift=-5)]
    assert faces.flipped_turn(thumbs_of(imgs), PLAN) is None


def test_a_plain_background_cannot_tell_and_is_left_alone():
    bg = backdrop(plain=True)
    other_side = shot(bg, head_x=225, shade=150)
    imgs = [shot(bg), shot(bg), cv2.flip(other_side, 1), cv2.flip(other_side, 1), shot(bg), shot(bg)]
    assert faces.flipped_turn(thumbs_of(imgs), PLAN) is None


# ---------------------------------------------------------------------------
# verify_burst: what runs on which frame (models replaced by fakes)
# ---------------------------------------------------------------------------

def enc(img):
    return cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 90])[1].tobytes()


@pytest.fixture
def engine(monkeypatch):
    seen = {"light": [], "scored": 0}

    def analyse(img, light=False):
        seen["light"].append(light)
        return FrameAnalysis(ok=True)
    monkeypatch.setattr(faces, "analyse_frame", analyse)
    monkeypatch.setattr(faces, "get_blink_model", lambda: object())

    def scores(img):
        seen["scored"] += 1
        return (0.7, 0.6) if seen["scored"] == 1 else (0.1, 0.1)
    monkeypatch.setattr(faces, "blink_scores", scores)
    monkeypatch.setattr(faces, "antispoof_scores", lambda images, analyses, idx: {"frames": list(idx)})

    def judge(analyses, challenge, template, **kw):
        seen["kw"] = kw
        return faces.VerifyResult("pass", None, "ok")
    monkeypatch.setattr(faces, "judge_burst", judge)
    return seen


def live_images(n=6):
    bg = backdrop()
    return [enc(shot(bg, shift=i)) for i in range(n)]


def test_v2_runs_light_analysis_and_scores_the_blink_with_mediapipe(engine):
    plan = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_BLINK, POSE_LEFT))
    faces.verify_burst(live_images(), plan, [])
    assert engine["light"] == [False, True, True, True, True, True]   # first centre frame at full size
    assert engine["kw"]["blink"] == {"closed": (0.7, 0.6), "opened": (0.1, 0.1)}
    assert engine["kw"]["antispoof"] == {"frames": [0, 1]}


def test_without_mediapipe_every_frame_gets_the_landmarks(engine, monkeypatch):
    monkeypatch.setattr(faces, "get_blink_model", lambda: None)
    faces.verify_burst(live_images(), PLAN, [])
    assert engine["light"] == [False] * 6 and "blink" not in engine["kw"]


def test_v1_is_analysed_as_before(engine):
    faces.verify_burst(live_images(), ["centre", "turn", "other"], [])
    assert engine["light"] == [False] * 6 and "blink" not in engine["kw"] and "antispoof" not in engine["kw"]


def test_a_flipped_turn_is_refused_before_any_face_work(engine):
    bg = backdrop()
    centre, other_side = shot(bg), shot(bg, head_x=225, shade=150)
    imgs = [centre, shot(bg, shift=2), cv2.flip(other_side, 1), cv2.flip(other_side, 1), centre, shot(bg, shift=-2)]
    res = faces.verify_burst([enc(i) for i in imgs], PLAN, [])
    assert res.verdict == "fail" and res.fail_code == "MIRRORED_FRAMES" and "flipped_turn" in res.detail
    assert engine["light"] == []


# ---------------------------------------------------------------------------
# The flag
# ---------------------------------------------------------------------------

def settings(monkeypatch, **values):
    monkeypatch.setattr(marking, "get_setting", lambda k, d="": values.get(k, d))


def test_default_is_v1(monkeypatch):
    settings(monkeypatch)
    assert marking.liveness_protocol(7) == 1


def test_global_switch(monkeypatch):
    settings(monkeypatch, liveness_v2="1")
    assert marking.liveness_protocol(7) == 2 and marking.liveness_protocol(8) == 2


@pytest.mark.parametrize("pilot,expected", [
    ("[7]", 2), ('["7", 9]', 2), ("[8, 9]", 1), ("[]", 1), ("not json", 1), ('{"7": 1}', 1), ("[null, 7]", 2),
])
def test_pilot_list(monkeypatch, pilot, expected):
    settings(monkeypatch, liveness_v2="0", liveness_v2_students=pilot)
    assert marking.liveness_protocol(7) == expected


def test_the_flash_is_off_by_default(monkeypatch):
    settings(monkeypatch)
    assert not marking.flash_enabled()
    settings(monkeypatch, flash_enabled="0")
    assert not marking.flash_enabled()
    settings(monkeypatch, flash_enabled="1")
    assert marking.flash_enabled()


def test_thresholds_read_the_new_settings(monkeypatch):
    monkeypatch.setattr(marking, "get_float_setting", lambda k, d: {"review_skip_match": 0.7}.get(k, d))
    th = marking.thresholds()
    assert th["review_skip_match"] == 0.7
    assert th["blink_closed_min"] == faces.DEFAULT_BLINK_CLOSED_MIN
    assert th["blink_open_max"] == faces.DEFAULT_BLINK_OPEN_MAX
    assert th["blink_min_diff"] == faces.DEFAULT_BLINK_MIN_DIFF


# --- /api/mark/challenge routes by protocol, and v1's response is unchanged ---

ME = Principal(user_id=1, role="student", student_id=7, name="t", email="t@x")


@pytest.fixture
def issuing(monkeypatch):
    stored = {}
    main.app.dependency_overrides[main.current_student] = lambda: ME
    for gate in ("check_enabled", "check_window", "check_not_already_marked", "check_attempt_budget"):
        monkeypatch.setattr(marking, gate, lambda *a, **k: None)
    monkeypatch.setattr(marking, "check_source_ip", lambda ip: ip)
    monkeypatch.setattr(marking, "check_device", lambda d: {"id": 3, "status": "active"})
    monkeypatch.setattr(main, "device_for_request", lambda r, sid: {"id": 3, "status": "active"})
    monkeypatch.setattr(main, "client_ip", lambda r: "10.0.0.1")
    monkeypatch.setattr(marking, "active_template", lambda sid: {"id": 1})
    monkeypatch.setattr(main, "get_setting", lambda k, d="": d)
    monkeypatch.setattr(marking, "issue_challenge",
                        lambda sid, dev, spec, day, ttl, th: stored.update(spec=spec))
    yield stored
    main.app.dependency_overrides.clear()


def challenge_response(monkeypatch, protocol, flash=False):
    monkeypatch.setattr(marking, "liveness_protocol", lambda sid: protocol)
    monkeypatch.setattr(marking, "flash_enabled", lambda: flash)
    return TestClient(main.app).post("/api/mark/challenge").json()


def test_v1_challenge_response_and_storage_are_as_before(issuing, monkeypatch):
    body = challenge_response(monkeypatch, 1)
    assert body["protocol"] == 1 and body["frames_per_step"] == 2
    assert "frame_counts" not in body and "flash" not in body
    assert faces.parse_challenge(issuing["spec"]) == body["challenge"]          # bare v1 form
    assert set(body["prompts"]) <= {"centre", "turn", "other"}


def test_v2_challenge_response(issuing, monkeypatch):
    body = challenge_response(monkeypatch, 2)
    plan = parse_challenge_spec(issuing["spec"])
    assert plan.protocol == 2 and list(plan.steps) == body["challenge"] and len(body["challenge"]) == 3
    assert body["frame_counts"] == [2, 2, 2] and "flash" not in body
    side = next(s for s in body["challenge"] if s in ("left", "right"))
    assert body["prompts"][side] == {"left": "Turn your head to your LEFT", "right": "Turn your head to your RIGHT"}[side]
    assert body["prompts"]["blink"] == "Look at the camera and blink slowly"
    with_flash = challenge_response(monkeypatch, 2, flash=True)
    plan = parse_challenge_spec(issuing["spec"])
    assert len(with_flash["challenge"]) == 4 and with_flash["frame_counts"] == plan.frame_counts()
    assert with_flash["flash"]["colors"] == ["#%02x%02x%02x" % rgb for rgb in plan.flash_rgb()]
    assert with_flash["flash"]["durations_ms"] == list(plan.flash_ms)


# --- /api/mark takes its frame count and judgement from the stored plan ---

def jpeg(seed):
    img = np.random.default_rng(seed).integers(0, 255, (96, 72, 3), dtype=np.uint8)
    return cv2.imencode(".jpg", img)[1].tobytes()


def test_mark_expects_six_frames_and_judges_with_the_plan(monkeypatch):
    main.app.dependency_overrides[main.current_student] = lambda: ME
    row = {"id": 55, "challenge": PLAN.spec(), "steps": list(PLAN.steps), "plan": PLAN, "device_id": 3}
    monkeypatch.setattr(main, "_mark_gates", lambda *a, **k: ({"id": 3}, False, dict(row)))
    monkeypatch.setattr(main, "_consume_challenge", lambda cid: None)
    monkeypatch.setattr(marking, "reserve_attempt", lambda **k: 42)
    monkeypatch.setattr(marking, "log_blocked", lambda code, **k: None)
    monkeypatch.setattr(marking, "record_frame_hashes", lambda *a, **k: True)
    judged = {}

    async def run_in_engine(fn, student_id, blobs, plan):
        judged.update(n=len(blobs), plan=plan)
        return faces.VerifyResult("pass", None, "ok")
    monkeypatch.setattr(faces, "run_in_engine", run_in_engine)
    monkeypatch.setattr(main, "_settle_mark", lambda me, **k: {"ok": True})
    client = TestClient(main.app, raise_server_exceptions=False)
    try:
        files = [("frames", (f"f{i}.jpg", jpeg(i), "image/jpeg")) for i in range(8)]
        r = client.post("/api/mark", data={"token": "t"}, files=files)
        assert r.status_code == 400 and r.json()["code"] == "FRAME_COUNT"
        files = [("frames", (f"f{i}.jpg", jpeg(i), "image/jpeg")) for i in range(6)]
        r = client.post("/api/mark", data={"token": "t"}, files=files)
        assert r.status_code == 200, r.text
        assert judged == {"n": 6, "plan": PLAN}
    finally:
        main.app.dependency_overrides.clear()


# --- the office can set the knobs, but not to values the backend misreads ---

@pytest.mark.parametrize("key,value,stored", [
    ("liveness_v2", "1", "1"), ("liveness_v2_students", '[12, "40", 12]', "[12, 40]"),
    ("liveness_v2_students", "", "[]"), ("flash_min_correlation", "0.8", "0.8"), ("flash_enabled", "0", "0"),
    ("review_skip_match", "0.7", "0.7"), ("blink_closed_min", "0.5", "0.5"), ("blink_min_diff", " 0.3 ", "0.3"),
])
def test_settings_are_normalised(key, value, stored):
    assert main._valid_setting(key, value) == stored


@pytest.mark.parametrize("key,value", [
    ("liveness_v2", "yes"), ("liveness_v2_students", "12, 40"), ("liveness_v2_students", '{"a": 1}'),
    ("flash_min_signal", "lots"), ("antispoof_review_threshold", "2"), ("review_skip_match", "1.5"),
    ("blink_open_max", "open"),
])
def test_bad_settings_are_refused(key, value):
    with pytest.raises(marking.MarkError):
        main._valid_setting(key, value)


def test_settings_json_round_trip_matches_the_flag_reader(monkeypatch):
    stored = main._valid_setting("liveness_v2_students", "[7]")
    settings(monkeypatch, liveness_v2_students=stored)
    assert json.loads(stored) == [7] and marking.liveness_protocol(7) == 2
