"""The marking transaction — every gate a student must pass to be marked present.

Order matters. The cheap, non-biometric gates run first so that an attack test
(or a confused student on mobile data) gets a precise answer without spending
CPU on face inference, and so a flood of bad requests cannot pin the server.

Each gate has its own failure code; that list doubles as the attack-test matrix
in ops/test/gate-matrix.sh.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import struct
import time
from dataclasses import dataclass
from datetime import date, datetime, timezone
from pathlib import Path

from .config import get_settings
from .db import audit, connection, get_float_setting, get_int_setting, get_setting, query_all, query_one
from .timewindow import DayWindow, now_ist, resolve_window, today_ist


class MarkError(Exception):
    """A gate refused the attempt. `code` is stable; `message` is for the student."""

    def __init__(self, status: int, code: str, message: str, **extra):
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message
        self.extra = extra


@dataclass
class GateContext:
    student_id: int
    day: date
    window: DayWindow
    device: dict
    source_ip: str


# ---------------------------------------------------------------------------
# Gates 1-2: network
# ---------------------------------------------------------------------------

def check_enabled() -> None:
    if get_setting("attendance_enabled", "1") != "1":
        raise MarkError(503, "DISABLED", "Attendance marking is currently disabled by the office.")


def check_source_ip(ip: str | None) -> str:
    # A missing header means the request did not come through Apache. We fail
    # closed: guessing here would hand out an unauthenticated bypass.
    if not ip:
        raise MarkError(
            500, "NET_MISCONFIG",
            "Server network configuration error. Please inform the SOM office.",
        )

    allowed = query_all("SELECT ip FROM att_allowed_ips WHERE is_active=1")
    if not allowed:
        raise MarkError(
            503, "NO_ALLOWLIST",
            "Attendance is not yet configured. Please inform the SOM office.",
        )
    if ip not in {row["ip"] for row in allowed}:
        raise MarkError(
            403, "IP_NOT_ALLOWED",
            "You must be connected to the SOM office WiFi to mark attendance.",
        )
    return ip


# ---------------------------------------------------------------------------
# Gate 3: time
# ---------------------------------------------------------------------------

def check_window(day: date, at: datetime) -> DayWindow:
    window = resolve_window(day)
    if not window.is_working_day:
        raise MarkError(
            403, "NON_WORKING_DAY",
            f"{window.label or 'Today'} is not a working day — no attendance required.",
        )
    if not window.is_open_at(at):
        raise MarkError(
            403, "OUTSIDE_WINDOW",
            f"Attendance can only be marked between {window.start_label} and {window.end_label}.",
            window={"start": window.start_label, "end": window.end_label},
        )
    return window


# ---------------------------------------------------------------------------
# Gate 4: device binding
# ---------------------------------------------------------------------------

def check_device(device: dict | None) -> dict:
    if not device:
        raise MarkError(
            401, "DEVICE_NOT_ENROLLED",
            "This phone is not enrolled. Please complete enrollment at the SOM office.",
        )
    if device["status"] != "active":
        raise MarkError(
            401, "DEVICE_REVOKED",
            "This phone's enrollment was revoked. Please visit the SOM office.",
        )
    return device


# ---------------------------------------------------------------------------
# Gate 5: strict mode (rotating office-screen code), off unless enabled
# ---------------------------------------------------------------------------

def current_strict_code(secret: str, at: float | None = None, step: int = 30) -> str:
    counter = int((at if at is not None else time.time()) // step)
    digest = hmac.new(secret.encode(), struct.pack(">Q", counter), hashlib.sha256).digest()
    offset = digest[-1] & 0x0F
    code = struct.unpack(">I", digest[offset:offset + 4])[0] & 0x7FFFFFFF
    return f"{code % 1_000_000:06d}"


def check_strict_code(submitted: str | None) -> bool:
    if get_setting("strict_mode", "0") != "1":
        return False
    secret = get_setting("strict_code_secret", "")
    if not secret:
        return False
    if not submitted:
        raise MarkError(
            403, "STRICT_CODE_REQUIRED",
            "Enter the 6-digit code shown on the office screen.",
        )
    now = time.time()
    # Accept the current and previous slot so a code typed at the boundary works.
    valid = {current_strict_code(secret, now), current_strict_code(secret, now - 30)}
    if submitted.strip() not in valid:
        raise MarkError(
            403, "STRICT_CODE_INVALID",
            "That code is wrong or has expired. Check the office screen again.",
        )
    return True


# ---------------------------------------------------------------------------
# Gate 6: already marked / attempt budget
# ---------------------------------------------------------------------------

def current_record(student_id: int, day: date) -> dict | None:
    return query_one(
        "SELECT * FROM att_records_current WHERE student_id=%s AND date_ist=%s",
        (student_id, day),
    )


def check_not_already_marked(student_id: int, day: date) -> None:
    record = current_record(student_id, day)
    if record and record["kind"] != "void":
        marked = record["marked_at_utc"]
        when = marked.replace(tzinfo=timezone.utc).astimezone(now_ist().tzinfo).strftime("%H:%M") \
            if isinstance(marked, datetime) else ""
        raise MarkError(409, "ALREADY_MARKED", f"You have already been marked present today at {when}.")


def check_attempt_budget(student_id: int, day: date) -> None:
    limit = get_int_setting("max_attempts_per_day", 5)
    # Only attempts made SINCE the office last voided this student-day count.
    # Voiding is the "let them mark again" action, so it has to clear the budget
    # too, or a student who burned their tries stays locked out after a reset.
    # Evidence is never destroyed — the old attempts stay, they just stop
    # counting. Both timestamps come from the same DB clock, so comparing them
    # needs no timezone conversion.
    row = query_one(
        "SELECT COUNT(*) AS n FROM att_attempts a "
        "WHERE a.student_id=%s AND a.date_ist=%s AND a.verdict='fail' "
        "  AND a.at_utc > COALESCE(("
        "        SELECT MAX(r.created_at) FROM att_records r "
        "        WHERE r.student_id=a.student_id AND r.date_ist=a.date_ist AND r.kind='void'"
        "      ), '1000-01-01')",
        (student_id, day),
    )
    if row and int(row["n"]) >= limit:
        raise MarkError(
            429, "TOO_MANY_ATTEMPTS",
            "Too many failed attempts today. Please see the SOM office.",
        )


# ---------------------------------------------------------------------------
# Evidence storage
# ---------------------------------------------------------------------------

def store_frames(student_roll: str, day: date, images: list[bytes], kind: str = "mark") -> tuple[str, str | None]:
    """Persist the capture burst. Returns (relative_dir, sha256_of_primary).

    The primary frame is the one the office sees in the daily grid; its hash is
    kept in the record so the evidence remains verifiable even after the frame
    files are purged at 60 days.
    """
    s = get_settings()
    rel = os.path.join(kind, day.strftime("%Y/%m/%d"), f"{student_roll}-{int(time.time()*1000)}")
    target = Path(s.frames_dir) / rel
    target.mkdir(parents=True, exist_ok=True)

    primary_hash = None
    for index, blob in enumerate(images):
        path = target / f"{index:02d}.jpg"
        tmp = path.with_suffix(".tmp")
        tmp.write_bytes(blob)
        os.replace(tmp, path)
        if index == 0:
            primary_hash = hashlib.sha256(blob).hexdigest()
    return rel, primary_hash


def record_attempt(
    *,
    student_id: int | None,
    day: date,
    source_ip: str | None,
    device_id: int | None,
    verdict: str,
    fail_code: str | None,
    match_score: float | None = None,
    liveness_score: float | None = None,
    pose_ok: bool | None = None,
    crossmatch_student_id: int | None = None,
    crossmatch_score: float | None = None,
    challenge_spec: str | None = None,
    frames_dir: str | None = None,
    user_agent: str | None = None,
    detail: dict | None = None,
) -> int:
    with connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO att_attempts (student_id, date_ist, source_ip, device_id, verdict, "
                "fail_code, match_score, liveness_score, pose_ok, crossmatch_student_id, "
                "crossmatch_score, challenge_spec, frames_dir, user_agent, detail) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (
                    student_id, day, source_ip, device_id, verdict, fail_code,
                    match_score, liveness_score,
                    None if pose_ok is None else int(pose_ok),
                    crossmatch_student_id, crossmatch_score, challenge_spec,
                    frames_dir, (user_agent or "")[:512],
                    json.dumps(detail, default=str) if detail else None,
                ),
            )
            attempt_id = cur.lastrowid
            conn.commit()
            return attempt_id


def insert_record(
    *,
    student_id: int,
    day: date,
    kind: str,
    marked_at: datetime,
    source_ip: str | None = None,
    device_id: int | None = None,
    attempt_id: int | None = None,
    selfie_path: str | None = None,
    selfie_sha256: str | None = None,
    match_score: float | None = None,
    liveness_score: float | None = None,
    reason: str | None = None,
    created_by_user_id: int | None = None,
    supersedes_id: int | None = None,
    version: int = 1,
) -> int:
    """Append one attendance row. Never updates: a correction is a new version
    that points at the row it supersedes (see att_records_current)."""
    with connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO att_records (student_id, date_ist, version, kind, marked_at_utc, "
                "source_ip, device_id, attempt_id, selfie_path, selfie_sha256, match_score, "
                "liveness_score, reason, created_by_user_id, supersedes_id) "
                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                (
                    student_id, day, version, kind, marked_at.astimezone(timezone.utc).replace(tzinfo=None),
                    source_ip, device_id, attempt_id, selfie_path, selfie_sha256,
                    match_score, liveness_score, reason, created_by_user_id, supersedes_id,
                ),
            )
            record_id = cur.lastrowid
            conn.commit()
            return record_id


def next_version_for(student_id: int, day: date) -> tuple[int, int | None]:
    """(version, supersedes_id) for a NEW row on this student-day.

    Any writer of att_records must use this rather than assuming version 1.
    A day that was voided (office reset, or a correction) already holds
    version 1..N, so inserting version 1 again collides with
    uk_student_date_version — which is how "reset then mark again" used to
    fail with a spurious ALREADY_MARKED.
    """
    existing = current_record(student_id, day)
    if not existing:
        return 1, None
    return existing["version"] + 1, existing["id"]


def supersede(
    *,
    student_id: int,
    day: date,
    kind: str,
    reason: str,
    created_by_user_id: int,
    marked_at: datetime | None = None,
) -> int:
    """Office correction: append a new version superseding the current one."""
    existing = current_record(student_id, day)
    return insert_record(
        student_id=student_id,
        day=day,
        kind=kind,
        marked_at=marked_at or now_ist(),
        reason=reason,
        created_by_user_id=created_by_user_id,
        supersedes_id=existing["id"] if existing else None,
        version=(existing["version"] + 1) if existing else 1,
    )


# ---------------------------------------------------------------------------
# Cohort templates (for identity + cross-match)
# ---------------------------------------------------------------------------

def load_cohort_templates(exclude_student_id: int | None = None) -> list[tuple[int, list[list[float]]]]:
    rows = query_all(
        "SELECT student_id, embeddings FROM att_face_templates WHERE status='active'"
    )
    out = []
    for row in rows:
        if exclude_student_id is not None and row["student_id"] == exclude_student_id:
            continue
        try:
            out.append((row["student_id"], json.loads(row["embeddings"])))
        except (TypeError, ValueError):
            continue
    return out


def active_template(student_id: int) -> dict | None:
    return query_one(
        "SELECT * FROM att_face_templates WHERE student_id=%s AND status='active' "
        "ORDER BY id DESC LIMIT 1",
        (student_id,),
    )


def thresholds() -> dict:
    return {
        "match_threshold": get_float_setting("face_match_threshold", 0.42),
        "review_threshold": get_float_setting("face_review_threshold", 0.32),
        "liveness_threshold": get_float_setting("liveness_threshold", 0.60),
        "crossmatch_margin": get_float_setting("crossmatch_margin", 0.05),
    }


def log_blocked(code: str, *, student_id: int | None, source_ip: str | None, day: date | None = None) -> None:
    audit(
        "mark.blocked",
        actor_type="student",
        student_id=student_id,
        source_ip=source_ip,
        details={"code": code, "date": str(day or today_ist())},
    )
