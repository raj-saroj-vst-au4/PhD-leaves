"""Liveness v2 (faces.py "Liveness v2"): the convention-free turn direction,
blink judgement, the colour-flash check, v2 challenges and their judgement,
the feature flag, and that v1 is untouched. No model: frames are synthetic
FrameAnalysis objects, or synthetic images for the pixel-level measures."""

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
    Challenge, FrameAnalysis, check_blink, check_challenge_v2, check_flash, judge_burst,
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
# Colour flash
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

def test_v2_challenges_are_well_formed():
    for _ in range(2000):
        c = new_challenge_v2()
        assert c.protocol == LIVENESS_V2 and c.steps[0] == POSE_CENTRE
        assert sorted(c.steps[1:]) == sorted((POSE_LEFT, POSE_RIGHT, POSE_BLINK, POSE_FLASH))
        assert len(c.flash_colors) == faces.FLASH_COUNT == len(c.flash_ms)
        assert all(a != b for a, b in zip(c.flash_colors, c.flash_colors[1:]))
        assert all(faces.FLASH_MIN_MS <= ms <= faces.FLASH_MAX_MS for ms in c.flash_ms)
        assert len(c.spec()) <= 100                     # att_challenges.challenge VARCHAR(100)
        assert parse_challenge_spec(c.spec()) == c


def test_v2_challenges_are_uniform_and_use_the_csprng():
    assert len(faces.SEQUENCES_V2) == 24
    rng = random.Random(9)
    counts = Counter(new_challenge_v2(rng).steps for _ in range(48000))
    assert set(counts) == set(faces.SEQUENCES_V2)
    assert max(counts.values()) / 48000 < 1 / 24 * 1.15
    assert isinstance(faces._rng, random.SystemRandom)


def test_a_v2_burst_fits_through_the_php_forwarder():
    """attapi/index.php receives the frames as PHP uploads first, and PHP's
    default max_file_uploads is 20: a burst of more files is silently cut."""
    longest = max(sum(Challenge(LIVENESS_V2, seq, tuple("RGBYMCR"), (400,) * 7).frame_counts())
                  for seq in faces.SEQUENCES_V2)
    assert longest == 15 and longest <= 20


def test_flash_never_changes_faster_than_three_times_a_second():
    assert faces.FLASH_MIN_MS > 1000 / 3


def test_v2_without_the_flash():
    c = new_challenge_v2(flash=False)
    assert POSE_FLASH not in c.steps and not c.flash_colors
    assert c.steps in faces.SEQUENCES_V2_NO_FLASH and parse_challenge_spec(c.spec()) == c
    assert c.frame_counts() == [2, 2, 2, 2]


def test_frame_counts_and_timing_floor():
    c = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_FLASH, POSE_LEFT, POSE_BLINK, POSE_RIGHT),
                  tuple("RGBYMCR"), (400,) * 7)
    assert c.frame_counts() == [2, 7, 2, 2, 2]
    assert c.step_of_frame()[:4] == [0, 0, 1, 1] and c.step_of_frame()[-1] == 4
    assert c.min_seconds() == pytest.approx(0.4 * 4 + 0.8 * 2.8)


@pytest.mark.parametrize("spec", [
    "v2:centre,left,left,blink",                                           # not a v2 sequence
    "v2:left,centre,right,blink,flash|RGBYMCR|400,400,400,400,400,400,400",  # centre not first
    "v2:centre,left,right,blink,flash",                                    # flash without colours
    "v2:centre,left,right,blink,flash|RRBYMCR|400,400,400,400,400,400,400",  # repeat back to back
    "v2:centre,left,right,blink,flash|RGBYMCX|400,400,400,400,400,400,400",  # unknown colour
    "v2:centre,left,right,blink,flash|RGBYMCR|100,400,400,400,400,400,400",  # too fast
    "v2:centre,left,right,blink,flash|RGBYMC|400,400,400,400,400,400",       # too few
    "v2:centre,left,right,blink|RGB|400,400,400",                          # colours without a flash
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

PLAN = Challenge(LIVENESS_V2, (POSE_CENTRE, POSE_LEFT, POSE_FLASH, POSE_BLINK, POSE_RIGHT),
                 tuple("RGBYMCR"), (400,) * 7)


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


def test_genuine_v2_burst_passes():
    res = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[(2, BOB_T)], **TH)
    assert res.verdict == "pass", (res.fail_code, res.detail)
    d = res.detail
    assert d["protocol"] == 2 and d["blink"] == "seen" and d["flash"] == "pass"
    assert d["closed_frames"] == [11] and d["flash_frames"] == list(range(4, 11))


def test_named_sides_must_be_the_ones_asked_for():
    swapped = genuine_burst(over={2: fa(side=POSE_RIGHT, emb=ALICE), 3: fa(side=POSE_RIGHT, emb=ALICE),
                               13: fa(side=POSE_LEFT, emb=ALICE), 14: fa(side=POSE_LEFT, emb=ALICE)})
    res = judge_burst(swapped, PLAN, ALICE_T, others=[], **TH)
    assert res.fail_code == "POSE_CHALLENGE_FAILED"
    assert [s["ok"] for s in res.detail["steps"]] == [True, False, True, True, False]


def test_a_frame_caught_mid_turn_is_neutral_but_a_pair_spanning_two_poses_fails():
    between = genuine_burst(over={2: fa(turn=0.09, emb=near(ALICE, 0.15, 2))})
    assert judge_burst(between, PLAN, ALICE_T, others=[], **TH).verdict == "pass"
    spanning = genuine_burst(over={2: fa(side=POSE_CENTRE, emb=near(ALICE, 0.15, 2))})
    assert judge_burst(spanning, PLAN, ALICE_T, others=[], **TH).fail_code == "POSE_CHALLENGE_FAILED"


def test_the_shut_eye_frame_is_not_held_to_the_match_threshold():
    """ArcFace scores a shut-eyed frame lower; it only has to be the same
    person as the rest of the burst."""
    weak = (0.45 * ALICE + 0.89 * person(9)).astype(np.float32)
    weak /= np.linalg.norm(weak)
    assert faces.best_score(weak, ALICE_T) < 0.42
    burst = genuine_burst(over={11: fa(emb=weak, ear=0.22, dark=0.08)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[(2, BOB_T)],
                      **dict(TH, consistency_threshold=0.05))
    assert res.verdict == "pass", (res.fail_code, res.detail)
    # ...but the same frame anywhere else sinks the burst (weakest frame decides).
    burst = genuine_burst(over={0: fa(emb=weak)})
    assert judge_burst(burst, PLAN, ALICE_T, others=[], **dict(TH, consistency_threshold=0.05)).verdict != "pass"


def test_someone_else_blinking_is_caught():
    burst = genuine_burst(over={11: fa(emb=near(BOB, 0.1, 5), ear=0.22, dark=0.08)})
    res = judge_burst(burst, PLAN, ALICE_T, others=[(2, BOB_T)], **TH)
    assert res.fail_code == "CROSSMATCH" and res.crossmatch_student_id == 2
    stranger = genuine_burst(over={11: fa(emb=person(77), ear=0.22, dark=0.08)})
    assert judge_burst(stranger, PLAN, ALICE_T, others=[], **TH).fail_code == "MIXED_FACES"


def test_no_blink_fails_and_an_unclear_blink_goes_to_review():
    res = judge_burst(genuine_burst(over={11: fa(emb=near(ALICE, 0.15, 11))}), PLAN, ALICE_T, others=[], **TH)
    assert res.fail_code == "BLINK_CHALLENGE_FAILED"
    res = judge_burst(genuine_burst(over={11: fa(emb=near(ALICE, 0.15, 11), ear=0.29, dark=0.30)}),
                      PLAN, ALICE_T, others=[], **TH)
    assert res.verdict == "pending" and res.fail_code == "LIVENESS_REVIEW"
    assert res.detail["review_reasons"] == ["blink_unclear"]


def test_flash_outcomes():
    weak = judge_burst(genuine_burst(k=0.01, seed=3), PLAN, ALICE_T, others=[], **TH)
    assert weak.verdict == "pending" and weak.detail["review_reasons"] == ["flash_weak"]
    assert weak.detail["steps"][2]["flash"]["signal"] < faces.DEFAULT_FLASH_MIN_SIGNAL

    other = Challenge(LIVENESS_V2, PLAN.steps, tuple("GBRCYMB"), PLAN.flash_ms)
    lit_wrong = genuine_burst(plan=other)          # the face followed a different sequence
    res = judge_burst(lit_wrong, PLAN, ALICE_T, others=[], **TH)
    assert res.fail_code == "FLASH_CHALLENGE_FAILED", res.detail["steps"][2]

    tuned = judge_burst(genuine_burst(), PLAN, ALICE_T, others=[], **dict(TH, flash_min_signal=0.5))
    assert tuned.verdict == "pending"


def test_antispoof_only_ever_sends_to_review():
    burst = genuine_burst()
    high = {"spoof": 0.97, "live": 0.03, "print": 0.5, "replay": 0.47}
    res = judge_burst(burst, PLAN, ALICE_T, others=[], antispoof=high, **TH)
    assert res.verdict == "pending" and res.detail["review_reasons"] == ["antispoof"]
    assert res.detail["antispoof"] == high
    low = dict(high, spoof=0.2, live=0.8)
    assert judge_burst(burst, PLAN, ALICE_T, others=[], antispoof=low, **TH).verdict == "pass"
    assert judge_burst(burst, PLAN, ALICE_T, others=[], antispoof=None, **TH).verdict == "pass"
    # It never turns a failure into anything else, nor a pass into a failure.
    bad = genuine_burst(over={11: fa(emb=near(ALICE, 0.15, 11))})
    assert judge_burst(bad, PLAN, ALICE_T, others=[], antispoof=high, **TH).fail_code == "BLINK_CHALLENGE_FAILED"
    looser = judge_burst(burst, PLAN, ALICE_T, others=[], antispoof=high,
                         **dict(TH, antispoof_review_threshold=0.99))
    assert looser.verdict == "pass"


def test_wrong_frame_count_fails_the_pose_check():
    assert not check_challenge_v2(genuine_burst()[:-1], PLAN).pose_ok


def test_mirrored_turn_frames_are_caught_in_v2():
    rng = np.random.default_rng(0)
    turned = rng.uniform(0, 255, (64, 48)).astype(np.float32)
    thumbs = [rng.uniform(0, 255, (64, 48)).astype(np.float32) for _ in range(sum(PLAN.frame_counts()))]
    thumbs[2] = turned                     # 'left'
    thumbs[13] = turned[:, ::-1].copy()    # 'right' = its mirror
    assert faces.mirrored_frames(thumbs, PLAN)[:2] == (2, 13)
    # Flash frames are never compared, even mirrored ones (a still face under
    # changing light legitimately looks alike).
    thumbs[13] = rng.uniform(0, 255, (64, 48)).astype(np.float32)
    thumbs[5], thumbs[6] = turned, turned[:, ::-1].copy()
    assert faces.mirrored_frames(thumbs, PLAN) is None


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


def test_flash_switch(monkeypatch):
    settings(monkeypatch)
    assert marking.flash_enabled()
    settings(monkeypatch, flash_enabled="0")
    assert not marking.flash_enabled()


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


def challenge_response(monkeypatch, protocol, flash=True):
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
    assert plan.protocol == 2 and list(plan.steps) == body["challenge"]
    assert body["frame_counts"] == plan.frame_counts()
    assert body["flash"]["colors"] == ["#%02x%02x%02x" % rgb for rgb in plan.flash_rgb()]
    assert body["flash"]["durations_ms"] == list(plan.flash_ms)
    assert body["prompts"]["left"] == "Turn your head to your LEFT"
    assert body["prompts"]["right"] == "Turn your head to your RIGHT"
    no_flash = challenge_response(monkeypatch, 2, flash=False)
    assert "flash" not in no_flash and no_flash["frame_counts"] == [2, 2, 2, 2]


# --- /api/mark takes its frame count and judgement from the stored plan ---

def jpeg(seed):
    img = np.random.default_rng(seed).integers(0, 255, (96, 72, 3), dtype=np.uint8)
    return cv2.imencode(".jpg", img)[1].tobytes()


def test_mark_expects_the_v2_frame_count_and_judges_with_the_plan(monkeypatch):
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
        n = sum(PLAN.frame_counts())
        files = [("frames", (f"f{i}.jpg", jpeg(i), "image/jpeg")) for i in range(8)]
        r = client.post("/api/mark", data={"token": "t"}, files=files)
        assert r.status_code == 400 and r.json()["code"] == "FRAME_COUNT"
        files = [("frames", (f"f{i}.jpg", jpeg(i), "image/jpeg")) for i in range(n)]
        r = client.post("/api/mark", data={"token": "t"}, files=files)
        assert r.status_code == 200, r.text
        assert judged == {"n": n, "plan": PLAN}
    finally:
        main.app.dependency_overrides.clear()


# --- the office can set the knobs, but not to values the backend misreads ---

@pytest.mark.parametrize("key,value,stored", [
    ("liveness_v2", "1", "1"), ("liveness_v2_students", '[12, "40", 12]', "[12, 40]"),
    ("liveness_v2_students", "", "[]"), ("flash_min_correlation", "0.8", "0.8"), ("flash_enabled", "0", "0"),
])
def test_settings_are_normalised(key, value, stored):
    assert main._valid_setting(key, value) == stored


@pytest.mark.parametrize("key,value", [
    ("liveness_v2", "yes"), ("liveness_v2_students", "12, 40"), ("liveness_v2_students", '{"a": 1}'),
    ("flash_min_signal", "lots"), ("antispoof_review_threshold", "2"),
])
def test_bad_settings_are_refused(key, value):
    with pytest.raises(marking.MarkError):
        main._valid_setting(key, value)


def test_settings_json_round_trip_matches_the_flag_reader(monkeypatch):
    stored = main._valid_setting("liveness_v2_students", "[7]")
    settings(monkeypatch, liveness_v2_students=stored)
    assert json.loads(stored) == [7] and marking.liveness_protocol(7) == 2
