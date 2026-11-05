"""Monthly stipend report: computed from the append-only record, archived
immutably (files + SHA-256), and emailed to the office on the 1st.

Present  = self-marked (face verified) or office 'manual' (reason on file)
Excused  = office 'excused' OR a day covered by an approved leave application
           in the existing leave system — approved leave never costs a stipend
Absent   = a working day with none of the above, on which the student was on
           the roll (joined, and not yet deactivated)
"""

from __future__ import annotations

import csv
import hashlib
import json
import logging
import os
import re
import shutil
import smtplib
import time
from datetime import date, datetime, timedelta, timezone
from email.message import EmailMessage
from pathlib import Path

from .config import get_settings
from .db import execute, get_setting, query_all, query_one
from .marking import REAL_FAILURE_SQL
from .timewindow import IST, approved_leave_dates, today_ist, working_days_in_month

log = logging.getLogger(__name__)

MONTHS = ["", "January", "February", "March", "April", "May", "June",
          "July", "August", "September", "October", "November", "December"]

# How the portal's audit entries name the account whose roll status changed:
#   old:  "Student '<name>' (ID: 42) deactivated. Reason: ..."
#   now:  "Student '<name>' <email> (user #42) deactivated. Reason: ..."
#         "Student '<name>' <email> (user #42) reactivated"
#         "User '<name>' <email> (student, user #42) deactivated"
# The name is the student's own text and may itself contain something that
# looks like "(user #7) deactivated", so the LAST match is taken — everything
# after the real token is the admin's reason or nothing — and in the newer
# formats the email right before it must be that account's own.
_ROLL_EVENT = re.compile(
    r"(?:<([^<>\s]*)> \((?:[a-z]+, )?user #|\(ID: )(\d+)\) (deactivated|reactivated)\b"
)
_ROLL_ACTIONS = {"student_deactivated": "deactivated", "user_deactivated": "deactivated",
                 "student_reactivated": "reactivated"}


def _ist_date(value) -> date | None:
    """A DB timestamp read on this backend's UTC session, as an IST date."""
    if isinstance(value, datetime):
        return value.replace(tzinfo=timezone.utc).astimezone(IST).date()
    if isinstance(value, date):
        return value
    return None


def _roll_events(emails: dict[int, str]) -> dict[int, list[tuple[date, str]]]:
    """Deactivations and reactivations per user id, oldest first, as IST dates.

    `users` keeps no deactivated_at column; the portal's system_logs entries
    are the record. Best-effort: a DB user without SELECT on system_logs gets
    no events, and the caller falls back to users.updated_at.
    """
    try:
        rows = query_all(
            "SELECT action, description, created_at FROM system_logs "
            "WHERE action IN ('student_deactivated','user_deactivated','student_reactivated') "
            "ORDER BY id"
        )
    except Exception:
        log.warning("system_logs unreadable; using users.updated_at as the deactivation date")
        return {}
    events: dict[int, list[tuple[date, str]]] = {}
    for row in rows:
        matches = list(_ROLL_EVENT.finditer(row["description"] or ""))
        if not matches:
            continue
        m = matches[-1]
        email, uid, kind = m.group(1), int(m.group(2)), m.group(3)
        if kind != _ROLL_ACTIONS.get(row["action"]) or uid not in emails:
            continue
        if email is not None and email.lower() != (emails[uid] or "").lower():
            continue
        when = _ist_date(row["created_at"])
        if when:
            events.setdefault(uid, []).append((when, kind))
    return events


def _off_roll_spans(events: list[tuple[date, str]], is_active: bool,
                    updated_on: date | None) -> list[tuple[date, date | None]]:
    """Inclusive date spans the student was off the roll: from the day of a
    deactivation to the day of the matching reactivation (None = still off).

    The deactivation day itself is off — the office deactivates people who
    have left — and so is the reactivation day, since it may come after that
    morning's window. A day with an actual mark counts regardless.
    """
    spans: list[tuple[date, date | None]] = []
    start = None
    last_event = None
    for when, kind in events:
        last_event = when
        if kind == "deactivated" and start is None:
            start = when
        elif kind == "reactivated" and start is not None:
            spans.append((start, when))
            start = None
    if not is_active and start is None:
        # Inactive with no deactivation on record (or one the logs missed):
        # the deactivating UPDATE bumped users.updated_at.
        known = [d for d in (updated_on, last_event) if d]
        start = max(known) if known else None
    if is_active and start is not None:
        # Logged as deactivated but active now: reactivated some other way;
        # updated_at is the best guess at when.
        spans.append((start, updated_on if updated_on and updated_on >= start else start))
        start = None
    if start is not None:
        spans.append((start, None))
    return spans


def compute_month(year: int, month: int) -> dict:
    """Everything the report needs, also served raw to the dashboard preview.

    The roster is every student ON THE ROLL at any point in the month, not just
    today's active accounts: a student deactivated on the 20th still attended
    the 1st-19th, and dropping them made their stipend days vanish from the
    report. Each student is accountable only from their joining_date and while
    not deactivated; other days are 'N' (not on roll) and count as neither
    absent nor working days for them — a mid-month joiner used to be marked
    absent for every day before they arrived.
    """
    days = working_days_in_month(year, month)
    day_set = set(days)
    first = date(year, month, 1)
    last = date(year + (month == 12), (month % 12) + 1, 1) - timedelta(days=1)
    today = today_ist()

    records = query_all(
        "SELECT * FROM att_records_current WHERE date_ist BETWEEN %s AND %s", (first, last)
    )
    by_student: dict[int, dict[date, dict]] = {}
    for r in records:
        by_student.setdefault(r["student_id"], {})[r["date_ist"]] = r

    candidates = query_all(
        "SELECT s.id, s.roll_number, s.joining_date, u.id AS user_id, u.name, u.email, "
        "u.is_active, u.updated_at FROM students s "
        "JOIN users u ON u.id=s.user_id WHERE u.role='student' AND s.joining_date<=%s "
        "ORDER BY s.roll_number",
        (last,),
    )
    events = _roll_events({s["user_id"]: s["email"] for s in candidates})

    students = []
    for s in candidates:
        spans = _off_roll_spans(events.get(s["user_id"], []), s["is_active"] == 1,
                                _ist_date(s["updated_at"]))
        joined = s["joining_date"]

        def on_roll(d, joined=joined, spans=spans):
            return ((joined is None or d >= joined)
                    and not any(a <= d and (b is None or d <= b) for a, b in spans))

        # Off the roll for the whole month, and nothing recorded in it: not ours.
        if not any(on_roll(d) for d in days) and s["id"] not in by_student:
            continue
        in_month = [(a, b) for a, b in spans if a <= last and (b is None or b >= first)]
        students.append({**s, "on_roll": on_roll, "spans": in_month})

    attempts = query_all(
        "SELECT a.student_id, COUNT(*) AS n FROM att_attempts a "
        f"WHERE a.date_ist BETWEEN %s AND %s AND {REAL_FAILURE_SQL} GROUP BY a.student_id",
        (first, last),
    )
    failed_by_student = {a["student_id"]: int(a["n"]) for a in attempts}

    rows = []
    for s in students:
        recs = by_student.get(s["id"], {})
        leave_days = approved_leave_dates(s["id"], first, last) & day_set
        joined = s["joining_date"]

        present, manual, excused, absent, on_roll = [], [], [], [], []
        per_day: dict[str, str] = {}
        for d in days:
            rec = recs.get(d)
            kind = rec["kind"] if rec and rec["kind"] != "void" else None
            if not s["on_roll"](d) and kind is None:
                per_day[d.isoformat()] = "N"   # not on the roll that day
                continue
            on_roll.append(d)
            if kind == "self":
                present.append(d); per_day[d.isoformat()] = "P"
            elif kind == "manual":
                present.append(d); manual.append(d); per_day[d.isoformat()] = "M"
            elif kind == "excused" or d in leave_days:
                excused.append(d); per_day[d.isoformat()] = "E"
            elif d <= today:
                absent.append(d); per_day[d.isoformat()] = "A"
            else:
                per_day[d.isoformat()] = "-"   # future

        accountable = [d for d in on_roll if d <= today]
        denominator = len([d for d in accountable if d not in excused])
        pct = round(100.0 * len(present) / denominator, 2) if denominator else None

        rows.append({
            "student_id": s["id"],
            "roll": s["roll_number"],
            "name": s["name"],
            "email": s["email"],
            # This student's own working days: the month's, less any before they
            # joined or after they left. Present + excused + absent (+ future)
            # add up to it; the sign-off snapshots it as the student's figure.
            "working_days": len(on_roll),
            "present": len(present),
            "manual": len(manual),
            "excused": len(excused),
            "absent": len(absent),
            "percentage": pct,
            "failed_attempts": failed_by_student.get(s["id"], 0),
            "active": s["is_active"] == 1,
            "joining_date": joined.isoformat() if joined else None,
            "deactivated_on": s["spans"][-1][0].isoformat() if s["spans"] else None,
            "reactivated_on": (s["spans"][-1][1].isoformat()
                               if s["spans"] and s["spans"][-1][1] else None),
            "per_day": per_day,
        })

    return {
        "year": year,
        "month": month,
        "month_name": MONTHS[month],
        "working_days": len(days),
        "days": [d.isoformat() for d in days],
        "student_count": len(rows),
        "rows": rows,
    }


def generate_monthly(year: int, month: int, *, trigger: str = "scheduled",
                     triggered_by: int | None = None) -> dict:
    data = compute_month(year, month)

    prior = query_one(
        "SELECT COALESCE(MAX(version),0) AS v FROM att_reports WHERE period_year=%s AND period_month=%s",
        (year, month),
    )
    version = int(prior["v"]) + 1

    out_dir = Path(get_settings().reports_dir) / f"{year}-{month:02d}-v{version}"
    out_dir.mkdir(parents=True, exist_ok=True)
    # CSV since Nov 2026 (reports up to then are .xlsx and stay as archived).
    # The att_reports columns keep their xlsx_* names; they hold either file.
    report_path = out_dir / f"attendance-{year}-{month:02d}-v{version}.csv"

    _write_csv(data, report_path)
    sha256 = hashlib.sha256(report_path.read_bytes()).hexdigest()

    report_id = execute(
        "INSERT INTO att_reports (period_year, period_month, version, trigger_type, triggered_by, "
        "working_days, student_count, xlsx_path, xlsx_sha256) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        (year, month, version, trigger, triggered_by, data["working_days"],
         data["student_count"], str(report_path), sha256),
    )

    email_status, email_error, recipients = _email_report(data, report_path)
    execute(
        "UPDATE att_reports SET email_status=%s, email_error=%s, emailed_to=%s WHERE id=%s",
        (email_status, email_error, json.dumps(recipients), report_id),
    )

    return {
        "report_id": report_id,
        "version": version,
        "working_days": data["working_days"],
        "student_count": data["student_count"],
        "sha256": sha256,
        "email_status": email_status,
        "recipients": recipients,
    }


def _roll_note(row: dict, data: dict) -> str:
    """Why a student's Working Days is short of the month's, if it is."""
    notes = []
    month = f"{data['year']}-{data['month']:02d}"
    if row.get("joining_date") and row["joining_date"][:7] == month:
        notes.append(f"joined {row['joining_date']}")
    if row.get("deactivated_on"):
        notes.append(f"deactivated {row['deactivated_on']}")
    if row.get("reactivated_on"):
        notes.append(f"reactivated {row['reactivated_on']}")
    return "; ".join(notes)


LEGEND = [
    ("P", "Present — self-marked with face verification on office WiFi"),
    ("M", "Present — marked manually by the office (reason on record)"),
    ("E", "Excused — approved leave (leave system) or office-excused"),
    ("A", "Absent"),
    ("N", "Not on the roll that day (before joining, or after the account was deactivated)"),
    ("-", "Future date at generation time"),
]


def _cell(value) -> str | int | float:
    """A CSV cell that a spreadsheet will not run as a formula: a student's
    name is their own text, and one starting with = + - @ would otherwise be
    evaluated when the office opens the file in Excel."""
    if isinstance(value, str) and value[:1] in ("=", "+", "-", "@", "\t", "\r"):
        return "'" + value
    return value


def _write_csv(data: dict, path: Path) -> None:
    """One row per student: the month's totals, then one column per working
    day (P, M, E, A, N or -; see LEGEND). UTF-8 with a byte-order mark so
    Excel shows names correctly when the file is double-clicked."""
    header = ["Roll No", "Name", "Working Days", "Present", "of which Manual",
              "Excused (leave)", "Absent", "Attendance %", "Failed Attempts", "On roll"]
    with path.open("w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.writer(fh)
        writer.writerow(header + data["days"])
        for row in data["rows"]:
            writer.writerow([_cell(v) for v in [
                row["roll"], row["name"], row["working_days"], row["present"], row["manual"],
                row["excused"], row["absent"],
                row["percentage"] if row["percentage"] is not None else "n/a",
                row["failed_attempts"],
                _roll_note(row, data),
            ]] + [row["per_day"].get(d, "-") for d in data["days"]])


def _email_report(data: dict, report_path: Path) -> tuple[str, str | None, list[str]]:
    try:
        recipients = [r for r in json.loads(get_setting("report_recipients", "[]")) if r]
    except (TypeError, ValueError):
        recipients = []
    if not recipients:
        return "pending", "no recipients configured", []

    host = os.environ.get("SMTP_HOST", "")
    if not host:
        return "failed", "SMTP not configured", recipients

    msg = EmailMessage()
    msg["Subject"] = (f"PhD Attendance Report — {data['month_name']} {data['year']} "
                      f"({data['student_count']} students, {data['working_days']} working days)")
    msg["From"] = os.environ.get("EMAIL_FROM", "phd-attendance@som.iitb.ac.in")
    msg["To"] = ", ".join(recipients)
    legend = "\n".join(f"  {code}  {meaning}" for code, meaning in LEGEND)
    msg.set_content(
        f"Attached is the PhD attendance report for {data['month_name']} {data['year']} (CSV).\n\n"
        f"Working days: {data['working_days']}\nStudents: {data['student_count']}\n\n"
        f"The columns after \"On roll\" are the working days:\n{legend}\n\n"
        f"SHA-256 of the attached file:\n{hashlib.sha256(report_path.read_bytes()).hexdigest()}\n\n"
        f"Generated automatically by the PhD Time-Off portal attendance module."
    )
    msg.add_attachment(
        report_path.read_bytes(),
        maintype="text",
        subtype="csv",
        filename=report_path.name,
    )

    try:
        port = int(os.environ.get("SMTP_PORT", "587"))
        user = os.environ.get("SMTP_USER", "")
        password = os.environ.get("SMTP_PASSWORD", "")
        if os.environ.get("SMTP_SECURE", "false").lower() == "true":
            server = smtplib.SMTP_SSL(host, port, timeout=30)
        else:
            server = smtplib.SMTP(host, port, timeout=30)
            server.starttls()
        with server:
            if user:
                server.login(user, password)
            server.send_message(msg)
        return "sent", None, recipients
    except Exception as exc:
        return "failed", str(exc)[:500], recipients


def purge_frames(older_than_days: int) -> int:
    """Delete evidence frame files past retention. Rows (scores, hashes) stay."""
    base = Path(get_settings().frames_dir)
    if not base.exists():
        return 0
    cutoff = time.time() - older_than_days * 86400
    removed = 0
    for kind_dir in base.iterdir():
        if not kind_dir.is_dir():
            continue
        for leaf in sorted(kind_dir.glob("*/*/*/*")):
            try:
                if leaf.is_dir() and leaf.stat().st_mtime < cutoff:
                    shutil.rmtree(leaf, ignore_errors=True)
                    removed += 1
            except OSError:
                continue
    return removed
