"""Working-day calendar and marking-window engine.

Resolution order for any date, most specific first:

  1. att_date_overrides  — the office's explicit per-date decision
  2. holidays            — the leave system's existing calendar, reused as-is
                           (is_working_day=1 marks a compensatory working day)
  3. att_windows         — the per-weekday default

Emergency extensions only ever stretch the end of an already-open window; they
cannot turn a non-working day into a working one.

All arithmetic is in IST. India has no DST, so a fixed +05:30 offset is exact,
and we never read the client's clock.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime, time, timedelta, timezone

from .db import query_all, query_one

IST = timezone(timedelta(hours=5, minutes=30))


def now_ist() -> datetime:
    return datetime.now(IST)


def today_ist() -> date:
    return now_ist().date()


def to_ist(dt: datetime) -> datetime:
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(IST)


def minutes_since_midnight(dt: datetime) -> int:
    return dt.hour * 60 + dt.minute


def fmt_minute(m: int) -> str:
    return f"{m // 60:02d}:{m % 60:02d}"


@dataclass
class DayWindow:
    day: date
    is_working_day: bool
    start_minute: int
    end_minute: int          # exclusive; already includes extensions
    base_end_minute: int
    extension_minutes: int
    label: str | None
    source: str              # override | holiday | weekday

    @property
    def start_label(self) -> str:
        return fmt_minute(self.start_minute)

    @property
    def end_label(self) -> str:
        return fmt_minute(self.end_minute)

    def is_open_at(self, dt: datetime) -> bool:
        """Start inclusive, end exclusive — at exactly the cutoff, marking is shut."""
        if not self.is_working_day:
            return False
        return self.start_minute <= minutes_since_midnight(to_ist(dt)) < self.end_minute


def resolve_window(day: date) -> DayWindow:
    weekday = day.isoweekday()  # 1=Mon .. 7=Sun

    default = query_one("SELECT * FROM att_windows WHERE weekday=%s", (weekday,)) or {
        "is_working_day": 1,
        "start_minute": 540,
        "end_minute": 630,
    }

    is_working = bool(default["is_working_day"])
    start = int(default["start_minute"])
    end = int(default["end_minute"])
    label: str | None = None
    source = "weekday"

    # 2. the leave system's holiday calendar
    holiday = query_one(
        "SELECT holiday_name, is_working_day FROM holidays WHERE holiday_date=%s "
        "ORDER BY id DESC LIMIT 1",
        (day,),
    )
    if holiday:
        is_working = bool(holiday["is_working_day"])
        label = holiday["holiday_name"]
        source = "holiday"

    # 1. explicit attendance override wins over everything
    override = query_one("SELECT * FROM att_date_overrides WHERE date_ist=%s", (day,))
    if override:
        if override["is_working_day"] is not None:
            is_working = bool(override["is_working_day"])
        if override["start_minute"] is not None:
            start = int(override["start_minute"])
        if override["end_minute"] is not None:
            end = int(override["end_minute"])
        label = override["label"] or label
        source = "override"

    extension = 0
    if is_working:
        row = query_one(
            "SELECT COALESCE(SUM(extra_minutes),0) AS mins FROM att_extensions WHERE date_ist=%s",
            (day,),
        )
        extension = int(row["mins"]) if row else 0

    return DayWindow(
        day=day,
        is_working_day=is_working,
        start_minute=start,
        end_minute=min(end + extension, 24 * 60),
        base_end_minute=end,
        extension_minutes=extension,
        label=label,
        source=source,
    )


def working_days_in_month(year: int, month: int, until: date | None = None) -> list[date]:
    """Every working day of the month — the denominator of the monthly report."""
    first = date(year, month, 1)
    last = date(year + (month == 12), (month % 12) + 1, 1) - timedelta(days=1)
    if until and until < last:
        last = until

    days: list[date] = []
    cursor = first
    while cursor <= last:
        if resolve_window(cursor).is_working_day:
            days.append(cursor)
        cursor += timedelta(days=1)
    return days


def approved_leave_dates(student_id: int, start: date, end: date) -> set[date]:
    """Approved leave from the existing leave system, expanded to single dates.

    These become 'excused' days in the report instead of absences — the office
    already approved them, so they must not cost the student their stipend.
    """
    rows = query_all(
        "SELECT start_date, end_date FROM leave_applications "
        "WHERE student_id=%s AND status='approved' AND start_date<=%s AND end_date>=%s",
        (student_id, end, start),
    )
    out: set[date] = set()
    for row in rows:
        cursor = max(row["start_date"], start)
        stop = min(row["end_date"], end)
        while cursor <= stop:
            out.add(cursor)
            cursor += timedelta(days=1)
    return out
