"""Gate 4, the enrolled-device check, and its switch att_settings
require_enrolled_device: '1' limits marking to the phone bound at enrollment,
'0' lets any browser mark (the face check and every other gate still apply).
Database replaced by fakes."""

from datetime import date

import pytest
from fastapi.testclient import TestClient

from app import faces, main, marking
from app.auth import Principal
from app.faces import VerifyResult

ME = Principal(user_id=1, role="student", student_id=7, name="t", email="t@x")
DAY = date(2026, 10, 9)
ACTIVE = {"id": 3, "status": "active"}
REVOKED = {"id": 3, "status": "revoked"}


def required(monkeypatch, value):
    """Set require_enrolled_device ('1', '0', or None for a missing row)."""
    values = {} if value is None else {"require_enrolled_device": value}
    monkeypatch.setattr(marking, "get_setting", lambda k, d="": values.get(k, d))


# --- the switch ---

def test_a_missing_setting_keeps_the_device_check(monkeypatch):
    required(monkeypatch, None)
    assert marking.device_required()
    required(monkeypatch, "1")
    assert marking.device_required()
    required(monkeypatch, "0")
    assert not marking.device_required()


# --- check_device ---

@pytest.mark.parametrize("device,code", [(None, "DEVICE_NOT_ENROLLED"), (REVOKED, "DEVICE_REVOKED")])
def test_required_refuses_a_missing_or_revoked_device(monkeypatch, device, code):
    required(monkeypatch, "1")
    with pytest.raises(marking.MarkError) as exc:
        marking.check_device(device)
    assert exc.value.code == code and exc.value.status == 401


def test_required_passes_the_bound_device(monkeypatch):
    required(monkeypatch, "1")
    assert marking.check_device(ACTIVE) == ACTIVE


@pytest.mark.parametrize("device,expected", [(None, None), (REVOKED, None), (ACTIVE, ACTIVE)])
def test_off_never_refuses_and_keeps_an_active_device(monkeypatch, device, expected):
    required(monkeypatch, "0")
    assert marking.check_device(device) == expected


# --- _mark_gates: the device on the request against the challenge's ---

@pytest.fixture
def gates(monkeypatch):
    blocked = []
    for name in ("check_enabled", "check_source_ip", "check_window", "check_not_already_marked",
                 "check_attempt_budget"):
        monkeypatch.setattr(marking, name, lambda *a, **k: None)
    monkeypatch.setattr(marking, "check_strict_code", lambda code: False)
    monkeypatch.setattr(marking, "active_template", lambda sid: {"id": 1})
    monkeypatch.setattr(marking, "log_blocked", lambda code, **k: blocked.append(code))
    state = {"cookie": None, "challenge_device": None, "blocked": blocked}
    monkeypatch.setattr(main, "device_for_request", lambda req, sid: state["cookie"])
    monkeypatch.setattr(main, "_peek_challenge",
                        lambda token, sid: {"id": 55, "device_id": state["challenge_device"]})

    def run():
        return main._mark_gates(None, ME, "tok", "", "10.0.0.1", DAY)
    state["run"] = run
    return state


def test_off_any_browser_answers_a_deviceless_challenge(monkeypatch, gates):
    required(monkeypatch, "0")
    device, _, row = gates["run"]()
    assert device is None and row["device_id"] is None and gates["blocked"] == []


def test_off_a_bound_browser_answers_a_deviceless_challenge(monkeypatch, gates):
    required(monkeypatch, "0")
    gates["cookie"] = ACTIVE
    device, _, _ = gates["run"]()
    assert device == ACTIVE


@pytest.mark.parametrize("setting", ["0", "1"])
def test_a_challenge_issued_to_a_device_stays_with_it(monkeypatch, gates, setting):
    """Either way, a token handed out to the bound phone is not answered from
    another browser."""
    required(monkeypatch, setting)
    gates["challenge_device"] = 3
    with pytest.raises(marking.MarkError) as exc:
        gates["run"]()
    assert exc.value.code == ("DEVICE_MISMATCH" if setting == "0" else "DEVICE_NOT_ENROLLED")
    assert gates["blocked"] == [exc.value.code]


def test_required_the_bound_device_answers_its_challenge(monkeypatch, gates):
    required(monkeypatch, "1")
    gates["cookie"], gates["challenge_device"] = ACTIVE, 3
    device, _, _ = gates["run"]()
    assert device == ACTIVE


def test_required_another_device_is_a_mismatch(monkeypatch, gates):
    required(monkeypatch, "1")
    gates["cookie"], gates["challenge_device"] = {"id": 9, "status": "active"}, 3
    with pytest.raises(marking.MarkError) as exc:
        gates["run"]()
    assert exc.value.code == "DEVICE_MISMATCH"


# --- /api/mark/challenge with no device ---

def test_off_a_cookieless_challenge_is_issued_to_no_device(monkeypatch):
    required(monkeypatch, "0")
    for name in ("check_enabled", "check_source_ip", "check_window", "check_not_already_marked",
                 "check_attempt_budget"):
        monkeypatch.setattr(marking, name, lambda *a, **k: None)
    monkeypatch.setattr(marking, "active_template", lambda sid: {"id": 1})
    monkeypatch.setattr(marking, "liveness_protocol", lambda sid: faces.LIVENESS_V2)
    monkeypatch.setattr(main, "device_for_request", lambda req, sid: None)
    monkeypatch.setattr(main, "get_setting", lambda k, d="": d)
    issued = []
    monkeypatch.setattr(marking, "issue_challenge", lambda *a: issued.append(a))
    main.app.dependency_overrides[main.current_student] = lambda: ME
    try:
        r = TestClient(main.app).post("/api/mark/challenge")
    finally:
        main.app.dependency_overrides.clear()
    assert r.status_code == 200, r.text
    assert len(issued) == 1 and issued[0][0] == 7 and issued[0][1] is None


def test_required_a_cookieless_challenge_is_refused(monkeypatch):
    required(monkeypatch, "1")
    for name in ("check_enabled", "check_source_ip", "check_window"):
        monkeypatch.setattr(marking, name, lambda *a, **k: None)
    monkeypatch.setattr(main, "device_for_request", lambda req, sid: None)
    main.app.dependency_overrides[main.current_student] = lambda: ME
    try:
        r = TestClient(main.app).post("/api/mark/challenge")
    finally:
        main.app.dependency_overrides.clear()
    assert r.status_code == 401 and r.json()["code"] == "DEVICE_NOT_ENROLLED"


# --- settling a mark with no device ---

def test_a_deviceless_mark_is_recorded_without_a_device(monkeypatch):
    executed, records = [], []
    monkeypatch.setattr(main, "query_one", lambda sql, params=(): {"roll_number": "22d0001"})
    monkeypatch.setattr(marking, "store_frames", lambda roll, day, blobs: ("frames/x", "abc"))
    monkeypatch.setattr(marking, "finish_attempt", lambda aid, **k: None)
    monkeypatch.setattr(main, "execute", lambda sql, params=(): executed.append(sql))
    monkeypatch.setattr(main, "audit", lambda *a, **k: None)
    monkeypatch.setattr(marking, "next_version_for", lambda sid, day: (1, None))
    monkeypatch.setattr(marking, "insert_record", lambda **k: records.append(k) or 99)

    out = main._settle_mark(ME, day=DAY, ip="10.0.0.1", device=None, strict_used=False,
                            attempt_id=42, blobs=[b"x"], result=VerifyResult("pass", None, "ok"))

    assert out["status"] == "marked"
    assert records[0]["device_id"] is None and records[0]["attempt_id"] == 42
    assert not any("att_devices" in sql for sql in executed)


def test_a_bound_mark_still_touches_its_device(monkeypatch):
    executed, records = [], []
    monkeypatch.setattr(main, "query_one", lambda sql, params=(): {"roll_number": "22d0001"})
    monkeypatch.setattr(marking, "store_frames", lambda roll, day, blobs: ("frames/x", "abc"))
    monkeypatch.setattr(marking, "finish_attempt", lambda aid, **k: None)
    monkeypatch.setattr(main, "execute", lambda sql, params=(): executed.append((sql, params)))
    monkeypatch.setattr(main, "audit", lambda *a, **k: None)
    monkeypatch.setattr(marking, "next_version_for", lambda sid, day: (1, None))
    monkeypatch.setattr(marking, "insert_record", lambda **k: records.append(k) or 99)

    main._settle_mark(ME, day=DAY, ip="10.0.0.1", device=ACTIVE, strict_used=False,
                      attempt_id=42, blobs=[b"x"], result=VerifyResult("pass", None, "ok"))

    assert records[0]["device_id"] == 3
    assert ("UPDATE att_devices SET last_seen_at=NOW() WHERE id=%s", (3,)) in executed
