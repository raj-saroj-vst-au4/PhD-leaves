"""Monthly report roster: mid-month joiners and deactivated students, plus the
nightly template purge. Database calls are replaced with in-memory rows."""

from datetime import date, datetime

import pytest

from app import marking, reports

# September 2026: a plain Mon-Fri month (22 working days), evaluated as if
# generated on 1 October, i.e. the whole month is in the past.
SEPT = [date(2026, 9, d) for d in range(1, 31) if date(2026, 9, d).isoweekday() <= 5]


def student(sid, roll, joined, active=1, updated=None):
    return {"id": sid, "roll_number": roll, "joining_date": joined, "user_id": 100 + sid,
            "name": f"S{sid}", "email": f"s{sid}@x", "is_active": active,
            "updated_at": updated or datetime(2026, 1, 1)}


@pytest.fixture
def month(monkeypatch):
    state = {"students": [], "records": [], "logs": [], "logs_fail": False}

    def query_all(sql, params=()):
        if "FROM att_records_current" in sql:
            return state["records"]
        if "FROM students s" in sql:
            last = params[0]
            return [s for s in state["students"] if s["joining_date"] <= last]
        if "FROM system_logs" in sql:
            if state["logs_fail"]:
                raise RuntimeError("SELECT command denied")
            return state["logs"]
        if "FROM att_attempts" in sql:
            return []
        raise AssertionError(sql)

    monkeypatch.setattr(reports, "query_all", query_all)
    monkeypatch.setattr(reports, "working_days_in_month", lambda y, m: list(SEPT))
    monkeypatch.setattr(reports, "approved_leave_dates", lambda sid, a, b: set())
    monkeypatch.setattr(reports, "today_ist", lambda: date(2026, 10, 1))
    return state


def rec(sid, d, kind="self"):
    return {"student_id": sid, "date_ist": d, "kind": kind}


def by_roll(data):
    return {r["roll"]: r for r in data["rows"]}


def test_full_month_student_unchanged(month):
    month["students"] = [student(1, "A", date(2024, 7, 1))]
    month["records"] = [rec(1, d) for d in SEPT[:20]]
    row = by_roll(reports.compute_month(2026, 9))["A"]
    assert (row["working_days"], row["present"], row["absent"]) == (22, 20, 2)
    assert row["percentage"] == round(100 * 20 / 22, 2)


def test_mid_month_joiner_is_not_absent_before_joining(month):
    month["students"] = [student(2, "B", date(2026, 9, 15))]          # a Tuesday
    month["records"] = [rec(2, d) for d in SEPT if d >= date(2026, 9, 15)]
    row = by_roll(reports.compute_month(2026, 9))["B"]
    on_roll = [d for d in SEPT if d >= date(2026, 9, 15)]
    assert row["working_days"] == len(on_roll)
    assert row["absent"] == 0 and row["percentage"] == 100.0
    assert row["per_day"]["2026-09-14"] == "N" and row["per_day"]["2026-09-15"] == "P"
    assert reports._roll_note(row, {"year": 2026, "month": 9}) == "joined 2026-09-15"


def test_joined_after_the_month_is_left_out(month):
    month["students"] = [student(3, "C", date(2026, 10, 5))]
    assert reports.compute_month(2026, 9)["rows"] == []


def log(action, desc, when):
    return {"action": action, "description": desc, "created_at": when}


def test_deactivated_mid_month_keeps_their_days(month):
    """Previously an inactive account vanished from the report entirely."""
    month["students"] = [student(4, "D", date(2024, 7, 1), active=0)]
    month["logs"] = [
        log("student_deactivated", "Student 'D' (ID: 104) deactivated. Reason: x", datetime(2026, 8, 1, 5)),
        log("student_reactivated", "Student 'D' <s4@x> (user #104) reactivated", datetime(2026, 8, 3, 5)),
        log("student_deactivated", "Student 'D' <s4@x> (user #104) deactivated. Reason: passed out",
            datetime(2026, 9, 18, 6, 0)),                          # 11:30 IST on the 18th
    ]
    month["records"] = [rec(4, d) for d in SEPT if d <= date(2026, 9, 16)]
    row = by_roll(reports.compute_month(2026, 9))["D"]
    assert row["deactivated_on"] == "2026-09-18"
    # The deactivation day itself is off the roll: on roll = working days before the 18th.
    assert row["working_days"] == len([d for d in SEPT if d < date(2026, 9, 18)])
    assert row["absent"] == 1                               # the 17th only
    assert row["per_day"]["2026-09-18"] == "N" and row["per_day"]["2026-09-21"] == "N"
    assert row["active"] is False


def test_a_mark_on_the_deactivation_day_still_counts(month):
    month["students"] = [student(4, "D", date(2024, 7, 1), active=0)]
    month["logs"] = [log("student_deactivated", "Student 'D' <s4@x> (user #104) deactivated. Reason: x",
                         datetime(2026, 9, 18, 6, 0))]
    month["records"] = [rec(4, date(2026, 9, 18))]
    row = by_roll(reports.compute_month(2026, 9))["D"]
    assert row["per_day"]["2026-09-18"] == "P" and row["present"] == 1


def test_reactivated_student_is_not_absent_while_deactivated(month):
    month["students"] = [student(9, "R", date(2024, 7, 1), active=1)]
    month["logs"] = [
        log("student_deactivated", "Student 'R' <s9@x> (user #109) deactivated. Reason: x", datetime(2026, 9, 8, 6)),
        log("student_reactivated", "Student 'R' <s9@x> (user #109) reactivated", datetime(2026, 9, 14, 6)),
    ]
    row = by_roll(reports.compute_month(2026, 9))["R"]
    off = [d for d in SEPT if date(2026, 9, 8) <= d <= date(2026, 9, 14)]
    assert all(row["per_day"][d.isoformat()] == "N" for d in off)
    assert row["per_day"]["2026-09-15"] == "A"
    assert row["working_days"] == len(SEPT) - len(off)
    assert (row["deactivated_on"], row["reactivated_on"]) == ("2026-09-08", "2026-09-14")
    assert reports._roll_note(row, {"year": 2026, "month": 9}) == "deactivated 2026-09-08; reactivated 2026-09-14"


def test_deactivation_date_from_user_delete_log_format(month):
    month["students"] = [student(5, "E", date(2024, 7, 1), active=0)]
    month["logs"] = [log("user_deactivated", "User 'E' <s5@x> (student, user #105) deactivated",
                         datetime(2026, 9, 9, 20, 0))]                # 01:30 IST on the 10th
    row = by_roll(reports.compute_month(2026, 9))["E"]
    assert row["deactivated_on"] == "2026-09-10"


def test_a_name_cannot_forge_someone_elses_deactivation(month):
    """A student's own name can contain "(user #N) deactivated"; the real token
    is the last one, and in the newer formats must carry that account's email."""
    month["students"] = [student(1, "A", date(2024, 7, 1), active=1),
                         student(2, "B", date(2024, 7, 1), active=0, updated=datetime(2026, 9, 25))]
    month["logs"] = [
        # B's genuine deactivation, B's name crafted to name A (user #101).
        log("student_deactivated",
            "Student 'Bob <s1@x> (user #101) deactivated' <s2@x> (user #102) deactivated. Reason: left",
            datetime(2026, 9, 7, 6)),
        # Right action, token with the wrong email for #101: ignored.
        log("user_deactivated", "User 'x' <evil@x> (student, user #101) deactivated", datetime(2026, 9, 3, 6)),
        # Wrong action for the token: ignored.
        log("student_reactivated", "Student 'y' <s1@x> (user #101) deactivated", datetime(2026, 9, 4, 6)),
    ]
    rows = by_roll(reports.compute_month(2026, 9))
    assert rows["A"]["deactivated_on"] is None and rows["A"]["working_days"] == len(SEPT)
    assert rows["B"]["deactivated_on"] == "2026-09-07"


def test_falls_back_to_updated_at_without_logs(month):
    month["students"] = [student(6, "F", date(2024, 7, 1), active=0, updated=datetime(2026, 9, 4, 3))]
    month["logs_fail"] = True
    row = by_roll(reports.compute_month(2026, 9))["F"]
    assert row["deactivated_on"] == "2026-09-04"
    assert row["working_days"] == 3                         # Tue 1st - Thu 3rd


def test_left_before_the_month_is_left_out_unless_marked(month):
    month["students"] = [student(7, "G", date(2020, 7, 1), active=0, updated=datetime(2025, 5, 1)),
                         student(8, "H", date(2020, 7, 1), active=0, updated=datetime(2025, 5, 1))]
    month["records"] = [rec(8, date(2026, 9, 2), "manual")]
    rows = by_roll(reports.compute_month(2026, 9))
    assert "G" not in rows
    # A day the office actually recorded is never dropped, roll or not.
    assert rows["H"]["present"] == 1 and rows["H"]["working_days"] == 1


def test_failed_attempts_exclude_server_side_codes():
    sql = marking.REAL_FAILURE_SQL
    for code in ("ENGINE_ERROR", "SERVER_ERROR", "TOO_MANY_ATTEMPTS", "VERIFYING"):
        assert code in sql


# ---------------------------------------------------------------------------
# Template purge
# ---------------------------------------------------------------------------

def test_purge_revokes_leavers_and_erases_revoked_templates(monkeypatch):
    calls = []
    monkeypatch.setattr(marking, "query_all", lambda sql, p=(): [{"student_id": 4}, {"student_id": 9}])
    monkeypatch.setattr(marking, "execute", lambda sql, p=(): calls.append((sql, p)) or 3)
    audits = []
    monkeypatch.setattr(marking, "audit", lambda action, **k: audits.append((action, k["student_id"])))

    out = marking.purge_templates()

    assert out == {"leavers_revoked": 2, "templates_erased": 3}
    revokes = [c for c in calls if "SET status='revoked'" in c[0] and "att_face_templates" in c[0]]
    assert [c[1][2] for c in revokes] == [4, 9]
    assert all("embeddings='[]'" in c[0] for c in revokes)
    assert any("att_devices" in c[0] for c in calls)
    sweep = calls[-1][0]
    assert "embeddings='[]'" in sweep and "status='revoked'" in sweep
    assert audits == [("enrollment.revoked", 4), ("enrollment.revoked", 9)]


def test_revoke_erases_embeddings(monkeypatch):
    calls = []
    monkeypatch.setattr(marking, "execute", lambda sql, p=(): calls.append((sql, p)) or 1)
    marking.revoke_templates(12, reason="lost phone", revoked_by=3)
    sql, params = calls[0]
    assert "embeddings='[]'" in sql and "embedding_count=0" in sql
    assert params == (3, "lost phone", 12)


# --- the CSV file (since Nov 2026) ---

def test_csv_has_totals_then_one_column_per_working_day(tmp_path):
    import csv
    data = {
        "year": 2026, "month": 10, "days": ["2026-10-01", "2026-10-03"],
        "rows": [
            {"roll": "22d1508", "name": "Ayushi Rai", "working_days": 2, "present": 1, "manual": 0,
             "excused": 1, "absent": 0, "percentage": 100.0, "failed_attempts": 2,
             "per_day": {"2026-10-01": "P", "2026-10-03": "E"}},
            # A name that a spreadsheet would run as a formula, and no percentage.
            {"roll": "25d1521", "name": "=HYPERLINK(\"http://x\")", "working_days": 2, "present": 0,
             "manual": 0, "excused": 0, "absent": 2, "percentage": None, "failed_attempts": 0,
             "per_day": {"2026-10-01": "A"}, "joining_date": "2026-10-02"},
        ],
    }
    path = tmp_path / "r.csv"
    reports._write_csv(data, path)

    raw = path.read_bytes()
    assert raw.startswith(b"\xef\xbb\xbf")          # BOM: Excel reads UTF-8 names correctly
    rows = list(csv.reader(path.open(encoding="utf-8-sig", newline="")))
    assert rows[0] == ["Roll No", "Name", "Working Days", "Present", "of which Manual",
                       "Excused (leave)", "Absent", "Attendance %", "Failed Attempts", "On roll",
                       "2026-10-01", "2026-10-03"]
    assert rows[1] == ["22d1508", "Ayushi Rai", "2", "1", "0", "1", "0", "100.0", "2", "", "P", "E"]
    assert rows[2][1] == "'=HYPERLINK(\"http://x\")"
    assert rows[2][7] == "n/a" and rows[2][9] == "joined 2026-10-02"
    assert rows[2][10:] == ["A", "-"]
