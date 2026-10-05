"""/api/mark request flow and the burst-content checks that sit around the
face judgement: duplicate and mirrored frames, replayed frames, SERVER_BUSY
leaving the challenge unused, and a failed settle never leaving the attempt
stuck. Database and model are replaced by fakes."""

import contextlib

import cv2
import numpy as np
import pytest
from fastapi.testclient import TestClient

from app import faces, main, marking
from app.auth import Principal
from app.faces import POSE_CENTRE, POSE_OTHER, POSE_TURN, VerifyResult


def scene(seed: int, w: int = 360, h: int = 480) -> np.ndarray:
    """An asymmetric picture with real structure (blobs on a gradient)."""
    rng = np.random.default_rng(seed)
    img = np.tile(np.linspace(40, 200, w, dtype=np.uint8), (h, 1))
    img = cv2.cvtColor(img, cv2.COLOR_GRAY2BGR)
    for _ in range(25):
        c = (int(rng.integers(0, w)), int(rng.integers(0, h)))
        cv2.circle(img, c, int(rng.integers(8, 60)), tuple(int(v) for v in rng.integers(0, 255, 3)), -1)
    return img


def enc(img: np.ndarray, q: int = 85) -> bytes:
    return cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, q])[1].tobytes()


# ---------------------------------------------------------------------------
# Mirrored frames
# ---------------------------------------------------------------------------

CH = [POSE_CENTRE, POSE_TURN, POSE_OTHER]


def thumbs(blobs):
    return [faces.frame_thumb(faces.decode_image(b)) for b in blobs]


def test_one_photo_and_its_mirror_is_caught():
    """The reviewer's stock set: one turned photo, its horizontal flip for the
    'other side', re-encoded so no two frames share bytes."""
    centre, turned = scene(1), scene(2)
    mirror = cv2.flip(turned, 1)
    burst = [enc(centre, 90), enc(centre, 80), enc(turned, 90), enc(turned, 80),
             enc(mirror, 90), enc(mirror, 80)]
    assert len(set(burst)) == 6
    hit = faces.mirrored_frames(thumbs(burst), CH)
    assert hit is not None and hit[0] in (2, 3) and hit[1] in (4, 5)

    res = faces.verify_burst(burst, CH, [], others=[], match_threshold=0.42, review_threshold=0.32,
                             liveness_threshold=0.6, crossmatch_margin=0.05)
    assert res.verdict == "fail" and res.fail_code == "MIRRORED_FRAMES"


def test_mirror_with_a_brightness_change_is_still_caught():
    turned = scene(2)
    brighter = cv2.convertScaleAbs(cv2.flip(turned, 1), alpha=1.0, beta=25)
    burst = [enc(scene(1))] * 2 + [enc(turned), enc(turned, 70), enc(brighter), enc(brighter, 70)]
    assert faces.mirrored_frames(thumbs(burst), CH) is not None


def test_genuinely_different_turn_frames_pass_the_mirror_check():
    a, b = scene(2), scene(3)
    burst = [enc(scene(1))] * 2 + [enc(a), enc(a, 70), enc(b), enc(b, 70)]
    assert faces.mirrored_frames(thumbs(burst), CH) is None


def test_moved_mirror_is_not_flagged_as_identical():
    """A turn frame that merely resembles the other side's mirror after real
    motion (here a 6% shift) is not called a mirror copy."""
    turned = scene(2)
    moved = np.roll(cv2.flip(turned, 1), 22, axis=1)
    burst = [enc(scene(1))] * 2 + [enc(turned), enc(turned, 70), enc(moved), enc(moved, 70)]
    assert faces.mirrored_frames(thumbs(burst), CH) is None


def test_same_step_and_centre_frames_are_never_compared():
    c = scene(1)
    # Centre frames mirrored, and the two frames of one turn step mirrored:
    # neither is a cross-step turn pair.
    burst = [enc(c), enc(cv2.flip(c, 1)), enc(scene(2)), enc(cv2.flip(scene(2), 1)),
             enc(scene(3)), enc(scene(3), 70)]
    assert faces.mirrored_frames(thumbs(burst), CH) is None


def test_featureless_frames_are_not_called_mirrors():
    blank = np.full((480, 360, 3), 20, dtype=np.uint8)
    burst = [enc(blank)] * 2 + [enc(blank, 90), enc(blank, 80), enc(blank, 70), enc(blank, 60)]
    assert faces.mirrored_frames(thumbs(burst), CH) is None


# ---------------------------------------------------------------------------
# /api/mark flow
# ---------------------------------------------------------------------------

ME = Principal(user_id=1, role="student", student_id=7, name="t", email="t@x")
CHALLENGE_ROW = {"id": 55, "challenge": "centre,turn,other", "steps": CH, "device_id": 3}


@pytest.fixture
def flow(monkeypatch):
    calls = {"consumed": [], "reserved": [], "hashes": [], "settled_failed": [], "blocked": [],
             "finished": [], "judged": 0}
    main.app.dependency_overrides[main.current_student] = lambda: ME
    monkeypatch.setattr(main, "_mark_gates", lambda *a, **k: ({"id": 3}, False, dict(CHALLENGE_ROW)))
    monkeypatch.setattr(main, "_consume_challenge", lambda cid: calls["consumed"].append(cid))
    monkeypatch.setattr(marking, "reserve_attempt", lambda **k: calls["reserved"].append(k) or 42)
    monkeypatch.setattr(marking, "log_blocked", lambda code, **k: calls["blocked"].append(code))
    monkeypatch.setattr(marking, "settle_failed", lambda aid: calls["settled_failed"].append(aid))
    monkeypatch.setattr(marking, "finish_attempt", lambda aid, **k: calls["finished"].append((aid, k)))

    def record(student_id, attempt_id, hashes, allow_seen=False):
        calls["hashes"].append(hashes)
        return calls.get("fresh", True)
    monkeypatch.setattr(marking, "record_frame_hashes", record)

    async def run_in_engine(fn, *args, **kwargs):
        calls["judged"] += 1
        if calls.get("engine_raises"):
            raise RuntimeError("onnx exploded")
        return VerifyResult("pass", None, "Face verified.")
    monkeypatch.setattr(faces, "run_in_engine", run_in_engine)

    def settle(me, **k):
        calls["settle_result"] = k["result"]
        if calls.get("settle_raises"):
            raise OSError("disk full")
        return {"ok": True, "status": "marked"}
    monkeypatch.setattr(main, "_settle_mark", settle)
    yield calls
    main.app.dependency_overrides.clear()


def post(frames):
    client = TestClient(main.app, raise_server_exceptions=False)
    files = [("frames", (f"f{i}.jpg", b, "image/jpeg")) for i, b in enumerate(frames)]
    return client.post("/api/mark", data={"token": "tok"}, files=files)


BURST = [enc(scene(10 + i)) for i in range(6)]


def test_happy_path_consumes_then_reserves_then_judges(flow):
    r = post(BURST)
    assert r.status_code == 200, r.text
    assert flow["consumed"] == [55] and len(flow["reserved"]) == 1 and flow["judged"] == 1
    assert len(flow["hashes"][0]) == 6 and len(set(flow["hashes"][0])) == 6


def test_byte_identical_frames_refused_before_anything_is_used(flow):
    r = post([BURST[0], BURST[0]] + BURST[2:])
    assert r.status_code == 400 and r.json()["code"] == "DUPLICATE_FRAMES"
    assert flow["consumed"] == [] and flow["reserved"] == [] and flow["judged"] == 0
    assert flow["blocked"] == ["DUPLICATE_FRAMES"]


def test_replayed_frame_is_a_recorded_failure_without_face_work(flow):
    flow["fresh"] = False
    r = post(BURST)
    assert r.status_code == 200          # the fake settle; the real one returns 422
    assert flow["judged"] == 0
    assert flow["settle_result"].fail_code == "REPLAYED_FRAME"
    assert flow["settle_result"].verdict == "fail"


def test_server_busy_leaves_challenge_and_budget_untouched(flow, monkeypatch):
    @contextlib.asynccontextmanager
    async def busy(timeout=None):
        raise faces.EngineBusy()
        yield
    monkeypatch.setattr(faces, "engine_slot", busy)
    r = post(BURST)
    assert r.status_code == 503 and r.json()["code"] == "SERVER_BUSY"
    assert flow["consumed"] == [] and flow["reserved"] == []
    assert flow["blocked"] == ["SERVER_BUSY"]


def test_engine_crash_is_finalised_as_engine_error(flow):
    flow["engine_raises"] = True
    r = post(BURST)
    assert r.status_code == 500
    assert flow["finished"] == [(42, {"verdict": "fail", "fail_code": "ENGINE_ERROR"})]


def test_failed_settle_never_leaves_the_attempt_verifying(flow):
    flow["settle_raises"] = True
    r = post(BURST)
    assert r.status_code == 500
    assert flow["settled_failed"] == [42]
