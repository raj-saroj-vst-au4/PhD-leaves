"""Monthly stipend report: computed from the append-only record, archived
immutably (files + SHA-256), and emailed to the office on the 1st.

Present  = self-marked (face verified) or office 'manual' (reason on file)
Excused  = office 'excused' OR a day covered by an approved leave application
           in the existing leave system — approved leave never costs a stipend
Absent   = a working day with none of the above
"""

from __future__ import annotations

import hashlib
import json
import os
import shutil
import smtplib
import time
from datetime import date, timedelta
from email.message import EmailMessage
from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill

from .config import get_settings
from .db import execute, get_setting, query_all, query_one
from .timewindow import approved_leave_dates, today_ist, working_days_in_month

MONTHS = ["", "January", "February", "March", "April", "May", "June",
          "July", "August", "September", "October", "November", "December"]


def compute_month(year: int, month: int) -> dict:
    """Everything the report needs, also served raw to the dashboard preview."""
    days = working_days_in_month(year, month)
    day_set = set(days)
    first = date(year, month, 1)
    last = date(year + (month == 12), (month % 12) + 1, 1) - timedelta(days=1)

    students = query_all(
        "SELECT s.id, s.roll_number, u.name, u.email FROM students s "
        "JOIN users u ON u.id=s.user_id WHERE u.is_active=1 AND u.role='student' "
        "ORDER BY s.roll_number"
    )
    records = query_all(
        "SELECT * FROM att_records_current WHERE date_ist BETWEEN %s AND %s", (first, last)
    )
    by_student: dict[int, dict[date, dict]] = {}
    for r in records:
        by_student.setdefault(r["student_id"], {})[r["date_ist"]] = r

    attempts = query_all(
        "SELECT student_id, COUNT(*) AS n FROM att_attempts "
        "WHERE date_ist BETWEEN %s AND %s AND verdict='fail' GROUP BY student_id",
        (first, last),
    )
    failed_by_student = {a["student_id"]: int(a["n"]) for a in attempts}

    rows = []
    for s in students:
        recs = by_student.get(s["id"], {})
        leave_days = approved_leave_dates(s["id"], first, last) & day_set

        present, manual, excused, absent = [], [], [], []
        per_day: dict[str, str] = {}
        for d in days:
            rec = recs.get(d)
            kind = rec["kind"] if rec and rec["kind"] != "void" else None
            if kind == "self":
                present.append(d); per_day[d.isoformat()] = "P"
            elif kind == "manual":
                present.append(d); manual.append(d); per_day[d.isoformat()] = "M"
            elif kind == "excused" or d in leave_days:
                excused.append(d); per_day[d.isoformat()] = "E"
            elif d <= today_ist():
                absent.append(d); per_day[d.isoformat()] = "A"
            else:
                per_day[d.isoformat()] = "-"   # future

        accountable = [d for d in days if d <= today_ist()]
        denominator = len([d for d in accountable if d not in excused])
        pct = round(100.0 * len(present) / denominator, 2) if denominator else None

        rows.append({
            "student_id": s["id"],
            "roll": s["roll_number"],
            "name": s["name"],
            "email": s["email"],
            "working_days": len(days),
            "present": len(present),
            "manual": len(manual),
            "excused": len(excused),
            "absent": len(absent),
            "percentage": pct,
            "failed_attempts": failed_by_student.get(s["id"], 0),
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
    xlsx_path = out_dir / f"attendance-{year}-{month:02d}-v{version}.xlsx"

    _write_xlsx(data, xlsx_path)
    sha256 = hashlib.sha256(xlsx_path.read_bytes()).hexdigest()

    report_id = execute(
        "INSERT INTO att_reports (period_year, period_month, version, trigger_type, triggered_by, "
        "working_days, student_count, xlsx_path, xlsx_sha256) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        (year, month, version, trigger, triggered_by, data["working_days"],
         data["student_count"], str(xlsx_path), sha256),
    )

    email_status, email_error, recipients = _email_report(data, xlsx_path)
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


def _write_xlsx(data: dict, path: Path) -> None:
    wb = Workbook()

    head_font = Font(bold=True, color="FFFFFF")
    head_fill = PatternFill("solid", fgColor="1F4E79")
    centre = Alignment(horizontal="center")

    ws = wb.active
    ws.title = "Summary"
    ws.append([f"PhD Attendance — {data['month_name']} {data['year']} "
               f"(working days: {data['working_days']})"])
    ws["A1"].font = Font(bold=True, size=13)
    ws.append([])
    header = ["Roll No", "Name", "Working Days", "Present", "of which Manual",
              "Excused (leave)", "Absent", "Attendance %", "Failed Attempts"]
    ws.append(header)
    for cell in ws[3]:
        cell.font, cell.fill, cell.alignment = head_font, head_fill, centre

    for row in data["rows"]:
        ws.append([
            row["roll"], row["name"], row["working_days"], row["present"], row["manual"],
            row["excused"], row["absent"],
            row["percentage"] if row["percentage"] is not None else "n/a",
            row["failed_attempts"],
        ])
    widths = [16, 30, 14, 10, 16, 15, 10, 14, 16]
    for i, w in enumerate(widths, start=1):
        ws.column_dimensions[chr(64 + i)].width = w

    # Day-by-day matrix: P present, M manual, E excused, A absent
    matrix = wb.create_sheet("Daily")
    matrix.append(["Roll No", "Name"] + [d[8:] for d in data["days"]])
    for cell in matrix[1]:
        cell.font, cell.fill, cell.alignment = head_font, head_fill, centre
    for row in data["rows"]:
        matrix.append([row["roll"], row["name"]] +
                      [row["per_day"].get(d, "-") for d in data["days"]])
    matrix.column_dimensions["A"].width = 16
    matrix.column_dimensions["B"].width = 30

    legend = wb.create_sheet("Legend")
    for line in [
        ["P", "Present — self-marked with face verification on office WiFi"],
        ["M", "Present — marked manually by the office (reason on record)"],
        ["E", "Excused — approved leave (leave system) or office-excused"],
        ["A", "Absent"],
        ["-", "Future date at generation time"],
        [],
        ["This report is generated from an append-only record.",
         "Every manual entry carries a mandatory reason and the acting staff member."],
    ]:
        legend.append(line)
    legend.column_dimensions["A"].width = 6
    legend.column_dimensions["B"].width = 80

    wb.save(path)


def _email_report(data: dict, xlsx_path: Path) -> tuple[str, str | None, list[str]]:
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
    msg.set_content(
        f"Attached is the PhD attendance report for {data['month_name']} {data['year']}.\n\n"
        f"Working days: {data['working_days']}\nStudents: {data['student_count']}\n\n"
        f"SHA-256 of the attached file:\n{hashlib.sha256(xlsx_path.read_bytes()).hexdigest()}\n\n"
        f"Generated automatically by the PhD Time-Off portal attendance module."
    )
    msg.add_attachment(
        xlsx_path.read_bytes(),
        maintype="application",
        subtype="vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        filename=xlsx_path.name,
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
