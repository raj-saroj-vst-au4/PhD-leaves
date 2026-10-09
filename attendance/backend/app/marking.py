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
import logging
import os
import struct
import time
from dataclasses import dataclass
from datetime import date, datetime, timezone
from pathlib import Path

from .config import get_settings
from .db import (
    audit, connection, execute, get_float_setting, get_int_setting, get_setting, query_all, query_one,
)
from .timewindow import IST, DayWindow, now_ist, resolve_window, today_ist


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
# Gate 4: device binding, only when att_settings require_enrolled_device='1'
# ---------------------------------------------------------------------------

def device_required() -> bool:
    """Whether marking is limited to the phone bound at enrollment. Off on
    production since Oct 2026: the cookie went missing too often (private
    tabs, other browsers, cleared data, new phones) and locked students out.
    A missing row means the original behaviour, required."""
    return get_setting("require_enrolled_device", "1") == "1"


def check_device(device: dict | None) -> dict | None:
    """The device to record the mark against, or None.

    With the device check off, any browser may mark: an active bound device
    is still returned (kept on the attempt and record for the office), and a
    missing, foreign or revoked one is simply None."""
    if not device_required():
        return device if device and device["status"] == "active" else None
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


# fail_codes that record a SERVER-side problem or a refusal, not a judged
# attempt. They are kept as evidence but never cost the student a try, and the
# office's "failed attempts" figures leave them out. VERIFYING is an attempt in
# flight; one still VERIFYING after STALE_VERIFYING_MINUTES was orphaned by a
# crash or an OOM kill, and stops counting too.
NOT_COUNTED_CODES = ("ENGINE_ERROR", "SERVER_ERROR", "TOO_MANY_ATTEMPTS")
STALE_VERIFYING_MINUTES = 5

# SQL for "this att_attempts row (alias a) is a real failed attempt" — shared by
# the dashboard and the monthly report so both agree with the budget.
REAL_FAILURE_SQL = (
    "a.verdict='fail' AND COALESCE(a.fail_code,'') NOT IN "
    "('ENGINE_ERROR','SERVER_ERROR','TOO_MANY_ATTEMPTS','VERIFYING')"
)


def _counted_attempts(student_id: int, day: date) -> int:
    # What uses up the daily budget: every judged failure AND every result sent
    # to office review. Pending used to be free, which let a lookalike keep
    # retrying from the review band (0.32-0.42) until one burst crossed 0.42.
    #
    # Only attempts made SINCE the office last voided this student-day count.
    # Voiding is the "let them mark again" action, so it has to clear the budget
    # too, or a student who burned their tries stays locked out after a reset.
    # Evidence is never destroyed — the old attempts stay, they just stop
    # counting. Both timestamps come from the same DB clock, so comparing them
    # needs no timezone conversion. An attempt still being verified is stored
    # as a 'fail' (see reserve_attempt), so it counts from the moment it starts.
    row = query_one(
        "SELECT COUNT(*) AS n FROM att_attempts a "
        "WHERE a.student_id=%s AND a.date_ist=%s AND a.verdict IN ('fail','pending') "
        "  AND COALESCE(a.fail_code,'') NOT IN ('ENGINE_ERROR','SERVER_ERROR','TOO_MANY_ATTEMPTS') "
        "  AND NOT (a.fail_code='VERIFYING' AND a.at_utc < NOW() - INTERVAL %s MINUTE) "
        "  AND a.at_utc > COALESCE(("
        "        SELECT MAX(r.created_at) FROM att_records r "
        "        WHERE r.student_id=a.student_id AND r.date_ist=a.date_ist AND r.kind='void'"
        "      ), '1000-01-01')",
        (student_id, day, STALE_VERIFYING_MINUTES),
    )
    return int(row["n"]) if row else 0


def _too_many_attempts() -> MarkError:
    return MarkError(
        429, "TOO_MANY_ATTEMPTS",
        "Too many attempts today. Please see the SOM office.",
    )


def check_attempt_budget(student_id: int, day: date) -> None:
    """Early, friendly refusal before any camera work. Not the guard itself:
    parallel requests can all pass this check together — reserve_attempt()
    is what actually holds the limit."""
    if _counted_attempts(student_id, day) >= get_int_setting("max_attempts_per_day", 5):
        raise _too_many_attempts()


def reserve_attempt(
    *,
    student_id: int,
    day: date,
    source_ip: str | None,
    device_id: int | None,
    challenge_spec: str | None,
    user_agent: str | None,
) -> int:
    """Claim one slot of today's attempt budget before the face is judged.

    Count-then-insert let a burst of parallel requests all read "4 of 5 used"
    and all go on to face verification. This inserts FIRST (as a 'fail' with
    fail_code VERIFYING, so it already counts) and counts SECOND, each
    statement committing on its own. Of N racing requests, the k-th row to
    commit is counted by its own request together with every row committed
    before it, so at most `limit` requests can ever see a count within the
    limit. No lock needed — and none available: the append-only DB user has no
    DELETE, and SELECT … FOR UPDATE on students would need UPDATE there.

    The error is always on the safe side: racers can over-refuse (each may see
    the others' reservations), never over-grant. A refused reservation stays
    on record as TOO_MANY_ATTEMPTS (which does not itself count); it only
    arises from requests racing past check_attempt_budget, which the page never
    does. Returns the id to finish with finish_attempt() — and every caller
    must finish it, or settle_failed() it, on every path.
    """
    limit = get_int_setting("max_attempts_per_day", 5)
    with connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "INSERT INTO att_attempts (student_id, date_ist, source_ip, device_id, verdict, "
                "fail_code, challenge_spec, user_agent) VALUES (%s,%s,%s,%s,'fail','VERIFYING',%s,%s)",
                (student_id, day, source_ip, device_id, challenge_spec, (user_agent or "")[:512]),
            )
            attempt_id = cur.lastrowid
            conn.commit()

    if _counted_attempts(student_id, day) > limit:
        execute("UPDATE att_attempts SET fail_code='TOO_MANY_ATTEMPTS' WHERE id=%s", (attempt_id,))
        raise _too_many_attempts()
    return attempt_id


def settle_failed(attempt_id: int) -> None:
    """The request died after reserving an attempt. Never leave the row saying
    VERIFYING (it would count for 5 minutes, and look like a failure after), and
    never leave a 'pass' with no attendance record behind it: that becomes a
    pending review, so the office's Approve creates the record and the student
    is not silently marked absent by a server fault."""
    execute(
        "UPDATE att_attempts SET verdict='fail', fail_code='SERVER_ERROR' "
        "WHERE id=%s AND fail_code='VERIFYING'",
        (attempt_id,),
    )
    execute(
        "UPDATE att_attempts a SET a.verdict='pending', a.fail_code='SERVER_ERROR' "
        "WHERE a.id=%s AND a.verdict='pass' "
        "AND NOT EXISTS (SELECT 1 FROM att_records r WHERE r.attempt_id=a.id)",
        (attempt_id,),
    )


# ---------------------------------------------------------------------------
# Challenge issue (rate-limited)
# ---------------------------------------------------------------------------
#
# There are only ten v1 sequences (four in v2), so an unlimited supply of
# challenges would let a client re-roll until it drew one its stock photos
# happen to fit. Issue is capped per minute and per day (a multiple of the
# attempt budget: tapping Mark and then cancelling costs a challenge but not an
# attempt). A void by the office resets the daily count along with the attempt
# budget.
CHALLENGES_PER_MINUTE = 4
CHALLENGES_PER_ATTEMPT = 3


def issue_challenge(student_id: int, device_id: int | None, steps: list[str] | str, day: date,
                    ttl_seconds: int, token_hash: str) -> None:
    """Store a challenge, refusing (and removing it again) past either cap.
    Insert-then-count, like reserve_attempt, so racing requests cannot
    overshoot. `steps` is a v1 step list or a stored spec string
    (faces.Challenge.spec)."""
    per_day = max(10, CHALLENGES_PER_ATTEMPT * get_int_setting("max_attempts_per_day", 5))
    spec = steps if isinstance(steps, str) else ",".join(steps)
    challenge_id = execute(
        "INSERT INTO att_challenges (token_hash, student_id, device_id, challenge, expires_at) "
        "VALUES (%s,%s,%s,%s,DATE_ADD(NOW(), INTERVAL %s SECOND))",
        (token_hash, student_id, device_id, spec, ttl_seconds),
    )
    # IST midnight of `day`, as the UTC instant this backend's session uses.
    midnight = (datetime.combine(day, datetime.min.time(), tzinfo=IST)
                .astimezone(timezone.utc).replace(tzinfo=None))
    row = query_one(
        "SELECT "
        "  SUM(c.created_at > NOW() - INTERVAL 60 SECOND) AS last_minute, "
        "  SUM(c.created_at >= %s AND c.created_at > COALESCE(("
        "        SELECT MAX(r.created_at) FROM att_records r "
        "        WHERE r.student_id=%s AND r.date_ist=%s AND r.kind='void'"
        "      ), '1000-01-01')) AS today "
        "FROM att_challenges c WHERE c.student_id=%s AND c.created_at >= %s - INTERVAL 1 DAY",
        (midnight, student_id, day, student_id, midnight),
    )
    last_minute = int(row["last_minute"] or 0) if row else 0
    today = int(row["today"] or 0) if row else 0
    if last_minute > CHALLENGES_PER_MINUTE or today > per_day:
        execute("DELETE FROM att_challenges WHERE id=%s", (challenge_id,))
        if today > per_day:
            raise MarkError(429, "TOO_MANY_CHALLENGES",
                            "Too many attempts started today. Please see the SOM office.")
        raise MarkError(429, "TOO_MANY_CHALLENGES",
                        "Please wait a minute before trying again.")


# ---------------------------------------------------------------------------
# Frame replay
# ---------------------------------------------------------------------------
#
# Every submitted frame's SHA-256 is kept per student (att_frame_hashes), and a
# burst containing any frame this student has sent before is refused. That
# stops re-submitting an earlier burst (or enrollment frames) as-is. It does
# not stop re-encoded copies — see the RUNBOOK's threat model.

def _missing_table(exc: Exception) -> bool:
    return getattr(exc, "args", (None,))[0] == 1146


def record_frame_hashes(student_id: int, attempt_id: int | None, hashes: list[str],
                        *, allow_seen: bool = False) -> bool:
    """Remember these frame hashes for the student. Returns False if any was
    already on record (a replay) and allow_seen is False; in that case nothing
    is written. One multi-row INSERT, so it is atomic against a parallel replay
    of the same burst (uk_student_sha)."""
    if not hashes:
        return True
    rows = ",".join(["(%s,%s,%s)"] * len(hashes))
    params: list = []
    for h in hashes:
        params += [student_id, h, attempt_id]
    verb = "INSERT IGNORE" if allow_seen else "INSERT"
    try:
        execute(f"{verb} INTO att_frame_hashes (student_id, sha256, attempt_id) VALUES {rows}", params)
    except Exception as exc:
        if _missing_table(exc):
            # Deployed ahead of 2026_09_23_attendance_backend.sql: no replay
            # check yet, but marking must keep working.
            logging.getLogger(__name__).warning("att_frame_hashes missing; replay check skipped")
            return True
        if not allow_seen and ("Duplicate" in str(exc) or getattr(exc, "args", (None,))[0] == 1062):
            return False
        raise
    return True


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


def finish_attempt(
    attempt_id: int,
    *,
    verdict: str,
    fail_code: str | None,
    match_score: float | None = None,
    liveness_score: float | None = None,
    pose_ok: bool | None = None,
    crossmatch_student_id: int | None = None,
    crossmatch_score: float | None = None,
    frames_dir: str | None = None,
    detail: dict | None = None,
) -> None:
    """Write the outcome onto an attempt opened by reserve_attempt()."""
    execute(
        "UPDATE att_attempts SET verdict=%s, fail_code=%s, match_score=%s, liveness_score=%s, "
        "pose_ok=%s, crossmatch_student_id=%s, crossmatch_score=%s, frames_dir=%s, detail=%s "
        "WHERE id=%s",
        (
            verdict, fail_code, match_score, liveness_score,
            None if pose_ok is None else int(pose_ok),
            crossmatch_student_id, crossmatch_score, frames_dir,
            json.dumps(detail, default=str) if detail else None,
            attempt_id,
        ),
    )


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


# ---------------------------------------------------------------------------
# Template retention
# ---------------------------------------------------------------------------
#
# Only an ACTIVE template is ever matched against, so a revoked one is
# biometric data with no remaining purpose. Revoking therefore erases the
# embeddings on the spot; the row itself stays (append-style) as the record of
# who enrolled the student, when, and why it ended. The RUNBOOK has always told
# the office "delete a leaver's template via revoke" — until now revoke only
# flipped the status and the vectors stayed in the table indefinitely.

def revoke_templates(student_id: int, *, reason: str, revoked_by: int | None = None) -> int:
    """Revoke the student's active template(s) and erase the embeddings."""
    return execute(
        "UPDATE att_face_templates SET status='revoked', revoked_at=NOW(), revoked_by=%s, "
        "revoke_reason=%s, embeddings='[]', embedding_count=0 "
        "WHERE student_id=%s AND status='active'",
        (revoked_by, reason, student_id),
    )


def revoke_devices(student_id: int, *, revoked_by: int | None = None) -> int:
    return execute(
        "UPDATE att_devices SET status='revoked', revoked_at=NOW(), revoked_by=%s "
        "WHERE student_id=%s AND status='active'",
        (revoked_by, student_id),
    )


def purge_templates() -> dict:
    """Nightly biometric clean-up (runs with the frame purge).

    1. Leavers: a student whose portal account is deactivated can no longer
       sign in to mark, so their template and bound phone are revoked — the
       office's manual "revoke a leaver" step, made impossible to forget. If
       the account is reactivated they re-enroll at the office.
    2. Anything revoked that still carries embeddings (rows revoked before
       erase-on-revoke existed) is erased.
    """
    leavers = query_all(
        "SELECT DISTINCT t.student_id FROM att_face_templates t "
        "JOIN students s ON s.id=t.student_id JOIN users u ON u.id=s.user_id "
        "WHERE t.status='active' AND u.is_active=0"
    )
    for row in leavers:
        revoke_templates(row["student_id"], reason="Portal account deactivated (nightly purge)")
        revoke_devices(row["student_id"])
        audit("enrollment.revoked", student_id=row["student_id"],
              details={"reason": "portal account deactivated", "by": "nightly purge"})

    erased = execute(
        "UPDATE att_face_templates SET embeddings='[]', embedding_count=0 "
        "WHERE status='revoked' AND embeddings<>'[]'"
    )
    return {"leavers_revoked": len(leavers), "templates_erased": int(erased or 0)}


def thresholds() -> dict:
    from .faces import (
        DEFAULT_ANTISPOOF_REVIEW, DEFAULT_BLINK_CLOSED_MIN, DEFAULT_BLINK_MIN_DIFF, DEFAULT_BLINK_OPEN_MAX,
        DEFAULT_FLASH_MIN_CORRELATION, DEFAULT_FLASH_MIN_SIGNAL, DEFAULT_REVIEW_SKIP_MATCH,
    )

    return {
        "match_threshold": get_float_setting("face_match_threshold", 0.42),
        "review_threshold": get_float_setting("face_review_threshold", 0.32),
        "liveness_threshold": get_float_setting("liveness_threshold", 0.60),
        "crossmatch_margin": get_float_setting("crossmatch_margin", 0.05),
        # Not in the office UI; tune in att_settings if real bursts trip it.
        "consistency_threshold": get_float_setting("burst_consistency_threshold", 0.35),
        # Liveness v2 only (a v1 challenge has no flash and runs no anti-spoof
        # model, so these change nothing for it). See faces.check_flash and
        # the RUNBOOK for tuning them from the measurements attempts record.
        "flash_min_signal": get_float_setting("flash_min_signal", DEFAULT_FLASH_MIN_SIGNAL),
        "flash_min_correlation": get_float_setting("flash_min_correlation", DEFAULT_FLASH_MIN_CORRELATION),
        "antispoof_review_threshold": get_float_setting("antispoof_review_threshold", DEFAULT_ANTISPOOF_REVIEW),
        "blink_closed_min": get_float_setting("blink_closed_min", DEFAULT_BLINK_CLOSED_MIN),
        "blink_open_max": get_float_setting("blink_open_max", DEFAULT_BLINK_OPEN_MAX),
        "blink_min_diff": get_float_setting("blink_min_diff", DEFAULT_BLINK_MIN_DIFF),
        # A soft liveness doubt (blink unclear, flash too weak, anti-spoof
        # suspicion) does not send a mark to review when the match is this
        # strong — faces.DEFAULT_REVIEW_SKIP_MATCH.
        "review_skip_match": get_float_setting("review_skip_match", DEFAULT_REVIEW_SKIP_MATCH),
    }


# ---------------------------------------------------------------------------
# Liveness protocol (feature flag)
# ---------------------------------------------------------------------------
#
# Liveness v2 (one named turn and a blink — faces.py) ships dark:
#   liveness_v2 = '1'            -> every student gets v2
#   liveness_v2_students = [ids] -> just these students.id (a pilot), while
#                                   liveness_v2 stays '0' for everyone else
# The protocol is fixed when a challenge is ISSUED and stored with it, so
# flipping the flag never changes how an attempt already in flight is judged.

def _pilot_students() -> set[int]:
    raw = get_setting("liveness_v2_students", "[]")
    try:
        ids = json.loads(raw or "[]")
    except (TypeError, ValueError):
        logging.getLogger(__name__).warning("att_settings liveness_v2_students is not JSON: %r", raw)
        return set()
    if not isinstance(ids, list):
        return set()
    out = set()
    for v in ids:
        try:
            out.add(int(v))
        except (TypeError, ValueError):
            continue
    return out


def liveness_protocol(student_id: int | None) -> int:
    """1 (current) or 2 (liveness v2) for this student."""
    if get_setting("liveness_v2", "0") == "1":
        return 2
    if student_id is not None and int(student_id) in _pilot_students():
        return 2
    return 1


def flash_enabled() -> bool:
    """The colour flash is OFF unless att_settings flash_enabled='1': the pilot
    found it too slow and, in the office light, too weak to judge (faces.py,
    "colour flash"). It stays available as an extra step."""
    return get_setting("flash_enabled", "0") == "1"


def log_blocked(code: str, *, student_id: int | None, source_ip: str | None, day: date | None = None) -> None:
    audit(
        "mark.blocked",
        actor_type="student",
        student_id=student_id,
        source_ip=source_ip,
        details={"code": code, "date": str(day or today_ist())},
    )
