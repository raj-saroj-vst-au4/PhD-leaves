"""Window-engine unit tests — the cutoff maths that decides stipends.

DB calls are stubbed; these test the pure logic: boundary inclusivity,
override precedence, extensions, month arithmetic.
"""

import sys
import types
from datetime import date, datetime
from pathlib import Path
from zoneinfo import ZoneInfo

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

# Stub pymysql-backed db module before importing timewindow.
fake_db = types.ModuleType("app.db")
fake_db.STATE = {"windows": {}, "holidays": {}, "overrides": {}, "extensions": {}, "leaves": []}


def _query_one(sql, params=()):
    s = fake_db.STATE
    if "FROM att_windows" in sql:
        return s["windows"].get(params[0])
    if "FROM holidays" in sql:
        return s["holidays"].get(params[0])
    if "FROM att_date_overrides" in sql:
        return s["overrides"].get(params[0])
    if "FROM att_extensions" in sql:
        return {"mins": s["extensions"].get(params[0], 0)}
    return None


def _query_all(sql, params=()):
    if "FROM leave_applications" in sql:
        return fake_db.STATE["leaves"]
    return []


fake_db.query_one = _query_one
fake_db.query_all = _query_all
sys.modules["app.db"] = fake_db

from app.timewindow import IST, resolve_window, working_days_in_month  # noqa: E402

IST_TZ = ZoneInfo("Asia/Kolkata")


def w(working=1, start=540, end=630):
    return {"is_working_day": working, "start_minute": start, "end_minute": end}


def reset(windows_working=(1, 2, 3, 4, 5)):
    fake_db.STATE.update({"windows": {}, "holidays": {}, "overrides": {}, "extensions": {}, "leaves": []})
    for wd in range(1, 8):
        fake_db.STATE["windows"][wd] = w(1 if wd in windows_working else 0)


def at(day, hh, mm):
    return datetime(day.year, day.month, day.day, hh, mm, tzinfo=IST_TZ)


def test_start_inclusive_end_exclusive():
    reset()
    monday = date(2026, 7, 27)
    win = resolve_window(monday)
    assert win.is_working_day
    assert win.is_open_at(at(monday, 9, 0)) is True        # exactly at start: open
    assert win.is_open_at(at(monday, 10, 29)) is True      # last minute: open
    assert win.is_open_at(at(monday, 10, 30)) is False     # exactly at cutoff: SHUT
    assert win.is_open_at(at(monday, 8, 59)) is False


def test_weekend_not_working():
    reset()
    assert resolve_window(date(2026, 7, 26)).is_working_day is False   # Sunday


def test_holiday_beats_weekday_default():
    reset()
    holi = date(2026, 3, 4)                                            # a Wednesday
    fake_db.STATE["holidays"][holi] = {"holiday_name": "Holi", "is_working_day": 0}
    win = resolve_window(holi)
    assert win.is_working_day is False
    assert win.label == "Holi"


def test_compensatory_working_saturday_via_holidays_table():
    reset()
    sat = date(2026, 8, 1)
    fake_db.STATE["holidays"][sat] = {"holiday_name": "Working Saturday", "is_working_day": 1}
    assert resolve_window(sat).is_working_day is True


def test_override_beats_holiday_and_default():
    reset()
    day = date(2026, 8, 15)
    fake_db.STATE["holidays"][day] = {"holiday_name": "Independence Day", "is_working_day": 0}
    fake_db.STATE["overrides"][day] = {
        "is_working_day": 1, "start_minute": 600, "end_minute": 660, "label": "Flag ceremony",
    }
    win = resolve_window(day)
    assert win.is_working_day is True
    assert (win.start_minute, win.end_minute) == (600, 660)
    assert win.source == "override"


def test_override_with_null_fields_inherits():
    reset()
    day = date(2026, 7, 27)
    fake_db.STATE["overrides"][day] = {
        "is_working_day": None, "start_minute": None, "end_minute": 720, "label": None,
    }
    win = resolve_window(day)
    assert win.is_working_day is True          # inherited from weekday default
    assert win.start_minute == 540             # inherited
    assert win.end_minute == 720               # overridden


def test_extension_stretches_end():
    reset()
    day = date(2026, 7, 27)
    fake_db.STATE["extensions"][day] = 45
    win = resolve_window(day)
    assert win.end_minute == 630 + 45
    assert win.is_open_at(at(day, 11, 10)) is True
    assert win.is_open_at(at(day, 11, 15)) is False


def test_extension_cannot_open_non_working_day():
    reset()
    sunday = date(2026, 7, 26)
    fake_db.STATE["extensions"][sunday] = 240
    win = resolve_window(sunday)
    assert win.is_working_day is False
    assert win.is_open_at(at(sunday, 9, 30)) is False


def test_working_days_in_month_counts_correctly():
    reset()
    # July 2026: 31 days, Mon-Fri = 23 weekdays; one holiday knocks out one.
    fake_db.STATE["holidays"][date(2026, 7, 6)] = {"holiday_name": "X", "is_working_day": 0}
    days = working_days_in_month(2026, 7)
    assert len(days) == 22
    assert date(2026, 7, 6) not in days
    assert date(2026, 7, 4) not in days       # Saturday
    assert date(2026, 7, 31) in days          # Friday


def test_month_boundary_days():
    reset()
    days = working_days_in_month(2026, 2)     # Feb 2026: 28 days
    assert date(2026, 2, 28) not in days      # Saturday
    assert date(2026, 2, 27) in days          # Friday
    assert all(d.month == 2 for d in days)


if __name__ == "__main__":
    failures = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS {name}")
            except AssertionError as exc:
                failures += 1
                print(f"FAIL {name}: {exc}")
    raise SystemExit(1 if failures else 0)
