"""Pose-challenge and burst-judgement rules (faces.py), without the model.

Frames are synthetic FrameAnalysis objects: a pose label plus an embedding.
Embeddings are unit vectors in a small space so "same person" / "different
person" can be constructed exactly.
"""

import random
from collections import Counter

import numpy as np
import pytest

from app import faces
from app.faces import (
    FRAMES_PER_STEP, POSE_CENTRE, POSE_LEFT, POSE_OTHER, POSE_RIGHT, POSE_TURN,
    FrameAnalysis, check_challenge, judge_burst, new_challenge, parse_challenge,
)

DIM = 16


def person(seed: int) -> np.ndarray:
    """A random unit vector: two different seeds are ~orthogonal in 16-D."""
    v = np.random.default_rng(seed).normal(size=DIM).astype(np.float32)
    return v / np.linalg.norm(v)


def near(base: np.ndarray, jitter: float, seed: int) -> np.ndarray:
    """The same person photographed again: base plus a little noise."""
    v = base + jitter * np.random.default_rng(seed).normal(size=DIM).astype(np.float32)
    return (v / np.linalg.norm(v)).astype(np.float32)


def frame(pose: str, emb: np.ndarray, texture: float = 0.9) -> FrameAnalysis:
    yaw = {POSE_CENTRE: 0.0, POSE_LEFT: -25.0, POSE_RIGHT: 25.0}.get(pose, 13.0)
    return FrameAnalysis(ok=True, embedding=emb, yaw=yaw, pose=pose, texture_score=texture)


def bad(reason: str = "TOO_BLURRY") -> FrameAnalysis:
    return FrameAnalysis(ok=False, reason=reason)


def burst(poses, emb_for):
    """poses: one pose label per FRAME. emb_for(i) -> embedding of frame i."""
    return [frame(p, emb_for(i)) for i, p in enumerate(poses)]


THRESHOLDS = dict(match_threshold=0.42, review_threshold=0.32,
                  liveness_threshold=0.60, crossmatch_margin=0.05)

ALICE = person(1)
BOB = person(2)
ALICE_TEMPLATE = [near(ALICE, 0.1, s).tolist() for s in range(10, 14)]
BOB_TEMPLATE = [near(BOB, 0.1, s).tolist() for s in range(20, 24)]


def alice(i):
    return near(ALICE, 0.15, 100 + i)


# ---------------------------------------------------------------------------
# new_challenge / parse_challenge
# ---------------------------------------------------------------------------

def test_challenges_are_well_formed():
    for _ in range(2000):
        steps = new_challenge()
        assert 3 <= len(steps) <= 4
        assert POSE_CENTRE in steps and POSE_OTHER in steps
        # The first turn is 'turn'; every later one is relative ('other').
        turns = [s for s in steps if s != POSE_CENTRE]
        assert turns[0] == POSE_TURN and all(t == POSE_OTHER for t in turns[1:])
        assert all(not (a == b == POSE_CENTRE) for a, b in zip(steps, steps[1:]))


def test_challenges_vary_and_use_the_csprng():
    seen = Counter(tuple(new_challenge()) for _ in range(3000))
    assert len(seen) >= 8                       # 10 possible sequences
    assert isinstance(faces._rng, random.SystemRandom)


def test_challenges_are_uniform_over_the_valid_sequences():
    """Step-by-step construction made some sequences 16% likely — a better bet
    for anyone re-rolling. Now each of the 10 is drawn with equal odds."""
    assert len(faces.SEQUENCES) == 10
    rng = random.Random(3)
    counts = Counter(tuple(new_challenge(rng)) for _ in range(50000))
    assert set(counts) == set(faces.SEQUENCES)
    assert max(counts.values()) / 50000 < 0.11


def test_challenge_uses_injected_rng_deterministically():
    a = [new_challenge(random.Random(7)) for _ in range(5)]
    b = [new_challenge(random.Random(7)) for _ in range(5)]
    assert a == b


def test_parse_challenge_rejects_unknown_steps():
    assert parse_challenge("centre,turn,other") == ["centre", "turn", "other"]
    assert parse_challenge("centre,turn") == ["centre", "turn"]       # issued before this change
    assert parse_challenge("centre,left") is None
    assert parse_challenge("") is None


# ---------------------------------------------------------------------------
# check_challenge: order, grouping, relative sides
# ---------------------------------------------------------------------------

def test_steps_performed_in_order_pass():
    challenge = [POSE_TURN, POSE_CENTRE, POSE_OTHER]
    frames = burst([POSE_LEFT, POSE_LEFT, POSE_CENTRE, POSE_CENTRE, POSE_RIGHT, POSE_RIGHT], alice)
    ok, steps = check_challenge(frames, challenge)
    assert ok and all(s["ok"] for s in steps)


def test_either_side_first_but_other_must_be_opposite():
    challenge = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
    right_then_left = burst([POSE_CENTRE] * 2 + [POSE_RIGHT] * 2 + [POSE_LEFT] * 2, alice)
    assert check_challenge(right_then_left, challenge)[0]
    same_side_twice = burst([POSE_CENTRE] * 2 + [POSE_RIGHT] * 2 + [POSE_RIGHT] * 2, alice)
    ok, steps = check_challenge(same_side_twice, challenge)
    assert not ok and steps[2] == {"step": POSE_OTHER, "ok": False, "seen": [POSE_RIGHT, POSE_RIGHT]}


def test_other_is_relative_to_the_latest_turn():
    challenge = [POSE_TURN, POSE_OTHER, POSE_OTHER, POSE_CENTRE]
    frames = burst([POSE_LEFT] * 2 + [POSE_RIGHT] * 2 + [POSE_LEFT] * 2 + [POSE_CENTRE] * 2, alice)
    assert check_challenge(frames, challenge)[0]
    frames = burst([POSE_LEFT] * 2 + [POSE_RIGHT] * 2 + [POSE_RIGHT] * 2 + [POSE_CENTRE] * 2, alice)
    assert not check_challenge(frames, challenge)[0]


def test_wrong_order_fails():
    challenge = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
    frames = burst([POSE_LEFT, POSE_LEFT, POSE_CENTRE, POSE_CENTRE, POSE_RIGHT, POSE_RIGHT], alice)
    assert not check_challenge(frames, challenge)[0]


def test_one_good_frame_per_step_is_enough():
    challenge = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
    frames = [frame(POSE_CENTRE, alice(0)), bad(),
              frame("between", alice(2)), frame(POSE_LEFT, alice(3)),
              frame(POSE_RIGHT, alice(4)), bad("NO_FACE")]
    assert check_challenge(frames, challenge)[0]


def test_a_universal_burst_cannot_satisfy_every_sequence():
    """With a free-form subsequence check, one stock set of photos contained
    every sequence. Per-step grouping means one burst fits only its own."""
    stock = burst([POSE_CENTRE, POSE_LEFT, POSE_CENTRE, POSE_RIGHT, POSE_LEFT, POSE_CENTRE], alice)
    three_step = [[POSE_CENTRE, POSE_TURN, POSE_OTHER], [POSE_TURN, POSE_CENTRE, POSE_OTHER],
                  [POSE_TURN, POSE_OTHER, POSE_CENTRE]]
    assert sum(check_challenge(stock, c)[0] for c in three_step) == 0


def test_a_pair_showing_two_poses_satisfies_neither():
    challenge = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
    frames = burst([POSE_CENTRE, POSE_LEFT, POSE_LEFT, POSE_LEFT, POSE_RIGHT, POSE_RIGHT], alice)
    ok, steps = check_challenge(frames, challenge)
    assert not ok and steps[0]["ok"] is False and steps[1]["ok"] and steps[2]["ok"]
    # ...and a pair that mixes both sides is not "a turn" either.
    frames = burst([POSE_CENTRE, POSE_CENTRE, POSE_LEFT, POSE_RIGHT, POSE_RIGHT, POSE_RIGHT], alice)
    assert not check_challenge(frames, challenge)[0]


def test_frame_count_must_match_the_challenge():
    challenge = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
    frames = burst([POSE_CENTRE, POSE_LEFT, POSE_RIGHT], alice)          # one per step
    assert not check_challenge(frames, challenge)[0]
    assert FRAMES_PER_STEP == 2


# ---------------------------------------------------------------------------
# judge_burst: identity on every frame, same person throughout, cross-match
# ---------------------------------------------------------------------------

CH = [POSE_CENTRE, POSE_TURN, POSE_OTHER]
POSES = [POSE_CENTRE, POSE_CENTRE, POSE_LEFT, POSE_LEFT, POSE_RIGHT, POSE_RIGHT]


def test_genuine_burst_passes():
    res = judge_burst(burst(POSES, alice), CH, ALICE_TEMPLATE,
                      others=[(2, BOB_TEMPLATE)], **THRESHOLDS)
    assert res.verdict == "pass", (res.fail_code, res.detail)
    assert res.pose_ok and res.match_score >= 0.42


def test_one_stranger_frame_sinks_the_burst():
    """A median let four frames of the account holder outvote the one frame of
    whoever really turned their head. The weakest frame now decides."""
    stranger = person(3)                         # not enrolled anywhere
    embs = [alice(0), alice(1), alice(2), stranger, alice(4), alice(5)]
    res = judge_burst(burst(POSES, lambda i: embs[i]), CH, ALICE_TEMPLATE,
                      others=[(2, BOB_TEMPLATE)], **THRESHOLDS)
    assert res.verdict == "fail"
    assert res.fail_code == "MIXED_FACES"
    assert res.match_score < 0.32


def test_enrolled_friend_in_any_frame_is_a_crossmatch():
    """Bob's face in a non-centre frame of Alice's burst: the old code only
    cross-checked the centre frame, so this passed on the median."""
    embs = [alice(0), alice(1), alice(2), near(BOB, 0.15, 7), alice(4), alice(5)]
    res = judge_burst(burst(POSES, lambda i: embs[i]), CH, ALICE_TEMPLATE,
                      others=[(2, BOB_TEMPLATE)], **THRESHOLDS)
    assert res.verdict == "fail" and res.fail_code == "CROSSMATCH"
    assert res.crossmatch_student_id == 2


def test_whole_burst_of_friend_is_a_crossmatch():
    res = judge_burst(burst(POSES, lambda i: near(BOB, 0.15, 50 + i)), CH, ALICE_TEMPLATE,
                      others=[(2, BOB_TEMPLATE)], **THRESHOLDS)
    assert res.fail_code == "CROSSMATCH" and res.crossmatch_student_id == 2


def test_consistency_threshold_is_applied():
    frames = burst(POSES, alice)
    strict = dict(THRESHOLDS, consistency_threshold=0.999)
    assert judge_burst(frames, CH, ALICE_TEMPLATE, others=[], **strict).fail_code == "MIXED_FACES"


def test_pose_failure_reported_after_identity_checks():
    frames = burst([POSE_CENTRE, POSE_CENTRE, POSE_LEFT, POSE_LEFT, POSE_LEFT, POSE_LEFT], alice)
    res = judge_burst(frames, CH, ALICE_TEMPLATE, others=[(2, BOB_TEMPLATE)], **THRESHOLDS)
    assert res.fail_code == "POSE_CHALLENGE_FAILED" and not res.pose_ok


def test_borderline_weakest_frame_goes_to_review():
    # Pull one frame part-way towards a stranger so its own score sits between
    # the review (0.32) and match (0.42) thresholds.
    mixed = ALICE * 0.40 + person(9) * 0.92
    mixed = (mixed / np.linalg.norm(mixed)).astype(np.float32)
    score = faces.best_score(mixed, ALICE_TEMPLATE)
    assert 0.32 <= score < 0.42, score
    embs = [alice(0), alice(1), alice(2), alice(3), alice(4), mixed]
    # (consistency disabled here: this test is about the review band alone)
    res = judge_burst(burst(POSES, lambda i: embs[i]), CH, ALICE_TEMPLATE, others=[],
                      **dict(THRESHOLDS, consistency_threshold=-1.0))
    assert res.verdict == "pending" and res.fail_code == "LOW_CONFIDENCE"


def test_no_usable_frames():
    res = judge_burst([bad("NO_FACE")] * 6, CH, ALICE_TEMPLATE, others=[], **THRESHOLDS)
    assert res.verdict == "fail" and res.fail_code == "NO_FACE"


@pytest.mark.parametrize("texture,expected", [(0.9, "pass"), (0.2, "LIVENESS_FAILED")])
def test_liveness_threshold(texture, expected):
    frames = [frame(p, alice(i), texture=texture) for i, p in enumerate(POSES)]
    res = judge_burst(frames, CH, ALICE_TEMPLATE, others=[], **THRESHOLDS)
    assert (res.verdict if expected == "pass" else res.fail_code) == expected
