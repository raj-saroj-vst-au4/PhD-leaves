"""FastAPI application — the attendance backend.

Served only on loopback; Apache proxies /phdtimeoff/attendance/api/ to it and
sets X-Client-IP on every request. Nothing here is reachable directly from a
student's phone except through that proxy. The PHP portal owns login and the
HTML; this service owns the gates, the face engine, and the evidence trail.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import secrets
from datetime import date, datetime, timedelta, timezone

from fastapi import Depends, FastAPI, File, Form, HTTPException, Request, Response, UploadFile
from fastapi.responses import FileResponse, JSONResponse
from starlette.concurrency import run_in_threadpool

from . import faces, marking, reports
from .auth import (
    Principal,
    client_ip,
    current_admin,
    current_principal,
    current_student,
    device_for_request,
    hash_enroll_code,
    hash_token,
)
from .config import get_settings
from .db import audit, execute, get_int_setting, get_setting, query_all, query_one
from .marking import MarkError
from .timewindow import IST, fmt_minute, now_ist, resolve_window, today_ist

logging.basicConfig(level=get_settings().log_level)
log = logging.getLogger("attendance")

app = FastAPI(title="SOM PhD Attendance", docs_url=None, redoc_url=None, openapi_url=None)

DEVICE_COOKIE = "att_device"
COOKIE_MAX_AGE = 400 * 24 * 3600  # browsers cap cookie lifetime at ~400 days
CHALLENGE_TTL_SECONDS = 120
MAX_ENROLL_FRAMES = 20


class BodySizeLimit:
    """Refuse an oversized request body before anything buffers it.

    The multipart parser spools every upload before a handler runs, so a size
    check inside the handler comes too late to protect memory or disk. A
    declared Content-Length over the limit is refused outright; a body that
    streams past it (chunked, or lying about its length) is cut off mid-read.
    """

    def __init__(self, app, max_bytes: int):
        self.app = app
        self.max_bytes = max_bytes

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        too_large = {"ok": False, "code": "BODY_TOO_LARGE",
                     "message": "The upload was too large. Please try again."}
        for name, value in scope.get("headers") or ():
            if name == b"content-length":
                try:
                    declared = int(value)
                except ValueError:
                    declared = self.max_bytes + 1
                if declared > self.max_bytes:
                    await JSONResponse(status_code=413, content=too_large)(scope, receive, send)
                    return

        received = 0

        async def limited_receive():
            nonlocal received
            message = await receive()
            if message.get("type") == "http.request":
                received += len(message.get("body", b""))
                if received > self.max_bytes:
                    # FastAPI re-raises an HTTPException from body parsing as-is,
                    # so this reaches the handler below as a normal 413.
                    raise HTTPException(413, {k: v for k, v in too_large.items() if k != "ok"})
            return message

        await self.app(scope, limited_receive, send)


app.add_middleware(BodySizeLimit, max_bytes=get_settings().max_body_bytes)


@app.exception_handler(MarkError)
async def mark_error_handler(request: Request, exc: MarkError):
    return JSONResponse(
        status_code=exc.status,
        content={"ok": False, "code": exc.code, "message": exc.message, **exc.extra},
    )


@app.exception_handler(HTTPException)
async def http_error_handler(request: Request, exc: HTTPException):
    """Keep every error response one shape: {ok:false, code, message}. Handlers
    that raise HTTPException with a dict detail (auth.py) would otherwise nest it
    under `detail`, and the frontend reads code/message at the top level."""
    detail = exc.detail
    if isinstance(detail, dict):
        body = {"ok": False, **detail}
    else:
        body = {"ok": False, "code": "ERROR", "message": str(detail)}
    return JSONResponse(status_code=exc.status_code, content=body)


@app.get("/api/healthz")
def healthz():
    query_one("SELECT 1 AS ok")
    return {"ok": True, "time_ist": now_ist().isoformat()}


def _ist(dt: datetime | None) -> str | None:
    if not dt:
        return None
    return dt.replace(tzinfo=timezone.utc).astimezone(IST).strftime("%H:%M")


# ---------------------------------------------------------------------------
# Student: status
# ---------------------------------------------------------------------------

@app.get("/api/netcheck")
def netcheck(request: Request, me: Principal = Depends(current_principal)):
    """Drives the student home screen. Never blocks — it *reports* the gates."""
    ip = client_ip(request)
    allowed_ips = {row["ip"] for row in query_all("SELECT ip FROM att_allowed_ips WHERE is_active=1")}
    now = now_ist()
    day = now.date()
    window = resolve_window(day)

    device = template = record = None
    if me.student_id:
        device = device_for_request(request, me.student_id)
        template = marking.active_template(me.student_id)
        record = marking.current_record(me.student_id, day)
        pending = query_one(
            "SELECT id FROM att_attempts WHERE student_id=%s AND date_ist=%s AND verdict='pending' LIMIT 1",
            (me.student_id, day),
        )
    else:
        pending = None

    today = None
    if record and record["kind"] != "void":
        today = {
            "kind": record["kind"],
            "marked_at_ist": _ist(record["marked_at_utc"]),
            "reason": record["reason"],
        }

    return {
        "ok": True,
        "server_time_ist": now.strftime("%Y-%m-%d %H:%M:%S"),
        "date_ist": day.isoformat(),
        "enabled": get_setting("attendance_enabled", "1") == "1",
        "ip_allowed": bool(ip and ip in allowed_ips),
        # No client address at all means the reverse proxy in front of the portal
        # is not publishing it — a deployment fault, not a student on the wrong
        # network. Surfaced separately so the UI can say so honestly.
        "ip_unavailable": ip is None,
        "allowlist_configured": bool(allowed_ips),
        "working_day": window.is_working_day,
        "day_label": window.label,
        "window": {
            "start": window.start_label,
            "end": window.end_label,
            "extended_minutes": window.extension_minutes,
            "open_now": window.is_open_at(now),
        },
        "strict_mode": get_setting("strict_mode", "0") == "1",
        # Which capture flow the page should prepare (liveness v2 or the
        # original); the challenge itself is still decided at issue time.
        "liveness": marking.liveness_protocol(me.student_id) if me.student_id else 1,
        "device_enrolled": bool(device and device["status"] == "active"),
        "face_enrolled": bool(template),
        "pending_review": bool(pending),
        "today": today,
    }


# ---------------------------------------------------------------------------
# Student: marking
# ---------------------------------------------------------------------------

@app.post("/api/mark/challenge")
def mark_challenge(request: Request, me: Principal = Depends(current_student)):
    """Pre-flight the cheap gates, then hand out a random pose sequence."""
    ip = client_ip(request)
    day = today_ist()

    marking.check_enabled()
    marking.check_source_ip(ip)
    marking.check_window(day, now_ist())
    device = marking.check_device(device_for_request(request, me.student_id))
    marking.check_not_already_marked(me.student_id, day)
    marking.check_attempt_budget(me.student_id, day)

    if not marking.active_template(me.student_id):
        raise MarkError(
            412, "FACE_NOT_ENROLLED",
            "Your face is not enrolled yet. Please complete enrollment at the SOM office.",
        )

    # Which protocol this student is on (att_settings liveness_v2 /
    # liveness_v2_students). It is stored with the challenge, so the attempt is
    # judged by the protocol it was issued under even if the flag flips.
    protocol = marking.liveness_protocol(me.student_id)
    if protocol == faces.LIVENESS_V2:
        plan = faces.new_challenge_v2(flash=marking.flash_enabled())
    else:
        plan = faces.Challenge(faces.LIVENESS_V1, tuple(faces.new_challenge()))
    challenge = list(plan.steps)
    raw = secrets.token_urlsafe(24)
    marking.issue_challenge(me.student_id, device["id"], plan.spec(), day,
                            CHALLENGE_TTL_SECONDS, hash_token(raw))
    body = {
        "ok": True,
        "token": raw,
        "challenge": challenge,
        "prompts": {p: _prompt_for(p) for p in set(challenge)},
        # The client must send exactly this many frames per step, in step order:
        # the server checks each step's pose in that step's own frames.
        "frames_per_step": faces.FRAMES_PER_STEP,
        "expires_in": CHALLENGE_TTL_SECONDS,
        "strict_mode": get_setting("strict_mode", "0") == "1",
        "protocol": protocol,
    }
    if protocol == faces.LIVENESS_V2:
        # v2 steps hold different numbers of frames (one per flash colour), so
        # the count is given per step. The flash colours exist only from this
        # moment and only in this response: a recording made earlier cannot
        # know them.
        body["frame_counts"] = plan.frame_counts()
        if plan.flash_colors:
            body["flash"] = {
                "colors": ["#%02x%02x%02x" % rgb for rgb in plan.flash_rgb()],
                "durations_ms": list(plan.flash_ms),
            }
    return body


def _prompt_for(pose: str) -> str:
    # v1 deliberately names no side: which physical direction reads as +yaw
    # depends on the camera image and the model's Euler convention, and naming
    # the wrong one made most real attempts fail. 'other' is relative to the
    # turn the student has just made, so it cannot be mis-scored that way.
    # v2 names sides in the STUDENT's terms, judged by faces.turn_ratio.
    return {
        faces.POSE_CENTRE: "Look straight at the camera",
        faces.POSE_TURN: "Turn your head to one side",
        faces.POSE_OTHER: "Now turn your head to the OTHER side",
        faces.POSE_LEFT: "Turn your head to your LEFT",
        faces.POSE_RIGHT: "Turn your head to your RIGHT",
        faces.POSE_BLINK: "Look at the camera and blink slowly",
        faces.POSE_FLASH: "Hold still while the screen changes colour",
    }.get(pose, "Look at the camera")


def _challenge_invalid() -> MarkError:
    return MarkError(401, "CHALLENGE_INVALID", "That attempt expired. Tap Mark Attendance again.")


def _peek_challenge(raw_token: str, student_id: int) -> dict:
    """The live, unconsumed challenge this token names — without using it up.

    Consuming happens later, in _consume_challenge, once a face-engine slot is
    held: a request turned away as SERVER_BUSY has not been judged, so the page
    can resend the very same frames with the same token."""
    row = query_one(
        "SELECT * FROM att_challenges WHERE token_hash=%s AND student_id=%s "
        "AND consumed_at IS NULL AND expires_at>NOW()",
        (hash_token(raw_token), student_id),
    )
    plan = faces.parse_challenge_spec(row["challenge"]) if row else None
    if not plan:
        raise _challenge_invalid()

    # Timing, from the database clock at millisecond precision. The upper bound
    # is the expiry above; the lower bound catches a script that fetched the
    # sequence and posted frames it already had, faster than a person can
    # perform the steps. issued_at comes from 2026_09_23_attendance_backend.sql;
    # until that migration runs the lower bound is simply not enforced.
    if "issued_at" in row:
        timing = query_one(
            "SELECT TIMESTAMPDIFF(MICROSECOND, issued_at, NOW(3)) AS us FROM att_challenges WHERE id=%s",
            (row["id"],),
        )
        elapsed = (timing["us"] or 0) / 1e6 if timing else None
        if elapsed is not None and elapsed < plan.min_seconds():
            raise MarkError(400, "CHALLENGE_TOO_FAST",
                            "That was quicker than the steps allow. Tap Mark Attendance and follow each step.")
    row["steps"] = list(plan.steps)
    row["plan"] = plan
    return row


def _consume_challenge(challenge_id: int) -> None:
    """Atomically use the challenge up: the UPDATE only wins once."""
    won = execute(
        "UPDATE att_challenges SET consumed_at=NOW() "
        "WHERE id=%s AND consumed_at IS NULL AND expires_at>NOW()",
        (challenge_id,),
    )
    if not won:
        raise _challenge_invalid()


async def _read_frames(frames: list[UploadFile], *, expected: int | None = None,
                       limit: int | None = None) -> list[bytes]:
    """Read the uploaded frames, refusing anything unsafe to decode.

    Only the header is inspected here (dimensions come from the JPEG header,
    never from a decode), so a decompression bomb is turned away before it can
    allocate anything. The body as a whole is already capped by BodySizeLimit.
    Two byte-identical frames in one burst are refused: a camera never
    produces them, a copied photo does.
    """
    if expected is not None and len(frames) != expected:
        raise MarkError(400, "FRAME_COUNT", "Not every step was captured. Please try again.")
    if limit is not None and len(frames) > limit:
        raise MarkError(400, "TOO_MANY_FRAMES", "Too many camera frames were sent. Please try again.")
    blobs: list[bytes] = []
    for upload in frames:
        data = await upload.read(faces.MAX_FRAME_BYTES + 1)
        problem = faces.frame_problem(data)
        if problem == "FRAME_TOO_LARGE":
            raise MarkError(400, "FRAME_TOO_LARGE", "Camera frames were too large. Please try again.")
        if problem:
            raise MarkError(400, "BAD_FRAME", "A camera frame could not be read. Please try again.")
        blobs.append(data)
    if not blobs:
        raise MarkError(400, "NO_FRAMES", "No camera frames were received. Please try again.")
    if len({hashlib.sha256(b).digest() for b in blobs}) != len(blobs):
        raise MarkError(400, "DUPLICATE_FRAMES", "Two camera frames were identical. Please try again.")
    return blobs


def _server_busy() -> MarkError:
    return MarkError(503, "SERVER_BUSY",
                     "The server is busy verifying other students. Please wait a few seconds and try again.")


def _mark_gates(request: Request, me: Principal, token: str, strict_code: str,
                ip: str | None, day: date) -> tuple[dict, bool, dict]:
    """Every non-biometric gate, in order, then the challenge (checked, not yet
    consumed). Runs on the threadpool — it is all database round trips."""
    try:
        marking.check_enabled()
        marking.check_source_ip(ip)
        marking.check_window(day, now_ist())
        device = marking.check_device(device_for_request(request, me.student_id))
        strict_used = marking.check_strict_code(strict_code or None)
        marking.check_not_already_marked(me.student_id, day)
        marking.check_attempt_budget(me.student_id, day)
        challenge_row = _peek_challenge(token, me.student_id)
        if device["id"] != challenge_row["device_id"]:
            raise MarkError(401, "DEVICE_MISMATCH", "Device changed mid-attempt. Please try again.")
        if not marking.active_template(me.student_id):
            raise MarkError(412, "FACE_NOT_ENROLLED", "Your face is not enrolled yet. Visit the SOM office.")
    except MarkError as exc:
        marking.log_blocked(exc.code, student_id=me.student_id, source_ip=ip, day=day)
        raise
    return device, strict_used, challenge_row


def _judge_mark(student_id: int, blobs: list[bytes], steps: list[str] | faces.Challenge) -> faces.VerifyResult:
    """Decode + detect + embed + judge. Runs on the face-engine pool; the cohort
    is loaded here too so a queued request holds no parsed templates."""
    template_row = marking.active_template(student_id)
    own = faces.load_template(template_row["embeddings"]) if template_row else []
    return faces.verify_burst(
        blobs,
        steps,
        own,
        others=marking.load_cohort_templates(exclude_student_id=student_id),
        **marking.thresholds(),
    )


def _settle_mark(me: Principal, *, day: date, ip: str | None, device: dict, strict_used: bool,
                 attempt_id: int, blobs: list[bytes], result: faces.VerifyResult):
    """Store the evidence, write the outcome, and mark present on a pass."""
    student = query_one("SELECT roll_number FROM students WHERE id=%s", (me.student_id,))
    frames_dir, primary_hash = marking.store_frames(student["roll_number"], day, blobs)

    marking.finish_attempt(
        attempt_id,
        verdict=result.verdict,
        fail_code=result.fail_code,
        match_score=round(result.match_score, 4),
        liveness_score=round(result.liveness_score, 4),
        pose_ok=result.pose_ok,
        crossmatch_student_id=result.crossmatch_student_id,
        crossmatch_score=round(result.crossmatch_score, 4),
        frames_dir=frames_dir,
        detail=result.detail,
    )
    execute("UPDATE att_devices SET last_seen_at=NOW() WHERE id=%s", (device["id"],))

    if result.verdict == "fail":
        audit(
            "mark.face_failed",
            actor_type="student",
            student_id=me.student_id,
            source_ip=ip,
            details={"code": result.fail_code, "match": round(result.match_score, 4),
                     "crossmatch": result.crossmatch_student_id, "attempt_id": attempt_id},
        )
        return JSONResponse(
            status_code=422,
            content={"ok": False, "code": result.fail_code, "message": result.message,
                     "attempt_id": attempt_id},
        )

    if result.verdict == "pending":
        # Deliberately NOT an absence. The office reviews the frames the same
        # day; a borderline ML score must never silently cost a stipend.
        audit(
            "mark.pending_review",
            actor_type="student",
            student_id=me.student_id,
            source_ip=ip,
            details={"attempt_id": attempt_id, "match": round(result.match_score, 4)},
        )
        return {
            "ok": True,
            "status": "pending_review",
            "message": "Sent to the SOM office for review. You will not be marked absent.",
        }

    # A voided day already holds earlier versions, so continue the chain rather
    # than assuming version 1 (that collided and looked like ALREADY_MARKED).
    version, supersedes_id = marking.next_version_for(me.student_id, day)
    try:
        record_id = marking.insert_record(
            student_id=me.student_id,
            day=day,
            kind="self",
            marked_at=now_ist(),
            source_ip=ip,
            device_id=device["id"],
            attempt_id=attempt_id,
            selfie_path=frames_dir,
            selfie_sha256=primary_hash,
            match_score=round(result.match_score, 4),
            liveness_score=round(result.liveness_score, 4),
            version=version,
            supersedes_id=supersedes_id,
        )
    except Exception as exc:  # unique key => a parallel request won the race
        if ("uk_student_date_version" in str(exc) or "uk_supersedes" in str(exc)
                or "Duplicate" in str(exc)):
            raise MarkError(409, "ALREADY_MARKED", "You have already been marked present today.")
        raise

    audit(
        "mark.success",
        actor_type="student",
        student_id=me.student_id,
        source_ip=ip,
        target_type="att_records",
        target_id=record_id,
        details={"match": round(result.match_score, 4), "strict": strict_used},
    )
    return {
        "ok": True,
        "status": "marked",
        "message": f"Present — {now_ist().strftime('%d %b %Y, %H:%M')}",
        "marked_at_ist": now_ist().strftime("%H:%M"),
    }


@app.post("/api/mark")
async def mark(
    request: Request,
    token: str = Form(...),
    frames: list[UploadFile] = File(...),
    strict_code: str = Form(default=""),
    me: Principal = Depends(current_student),
):
    """The marking transaction. Gate order is deliberate — see marking.py.

    Async only so it can read the upload and wait for a face-engine slot
    without holding a thread. Everything that blocks — every database round
    trip and the face work itself — runs off the event loop, so one student's
    verification never stalls another student's request.
    """
    ip = client_ip(request)
    day = today_ist()
    ua = request.headers.get("user-agent", "")

    device, strict_used, challenge_row = await run_in_threadpool(
        _mark_gates, request, me, token, strict_code, ip, day)
    steps = challenge_row["steps"]
    plan = challenge_row.get("plan") or faces.Challenge(faces.LIVENESS_V1, tuple(steps))

    try:
        blobs = await _read_frames(frames, expected=sum(plan.frame_counts()))
        hashes = [hashlib.sha256(b).hexdigest() for b in blobs]
        async with faces.engine_slot():
            # The challenge is used up, and a budget slot claimed, only once a
            # slot is free: a request that times out in the queue has not been
            # judged, costs the student nothing, and can be resent as-is.
            await run_in_threadpool(_consume_challenge, challenge_row["id"])
            attempt_id = await run_in_threadpool(
                marking.reserve_attempt,
                student_id=me.student_id, day=day, source_ip=ip, device_id=device["id"],
                challenge_spec=challenge_row["challenge"], user_agent=ua,
            )
            try:
                fresh = await run_in_threadpool(
                    marking.record_frame_hashes, me.student_id, attempt_id, hashes)
            except Exception:
                await run_in_threadpool(marking.settle_failed, attempt_id)
                raise
            if not fresh:
                # A frame this student has submitted before: an earlier burst
                # (or enrollment capture) sent again. Judged as a failure, with
                # the frames kept as evidence, and it counts.
                result = faces.VerifyResult(
                    "fail", "REPLAYED_FRAME",
                    "These photos were already used in an earlier attempt. This attempt has been recorded.",
                    detail={"challenge": steps, "frame_sha256": hashes},
                )
            else:
                try:
                    result = await faces.run_in_engine(
                        _judge_mark, me.student_id, blobs,
                        plan if plan.protocol == faces.LIVENESS_V2 else steps)
                except Exception:
                    log.exception("face verification crashed (attempt %s)", attempt_id)
                    await run_in_threadpool(marking.finish_attempt, attempt_id,
                                            verdict="fail", fail_code="ENGINE_ERROR")
                    raise
    except faces.EngineBusy:
        await run_in_threadpool(marking.log_blocked, "SERVER_BUSY",
                                student_id=me.student_id, source_ip=ip, day=day)
        raise _server_busy() from None
    except MarkError as exc:
        await run_in_threadpool(marking.log_blocked, exc.code,
                                student_id=me.student_id, source_ip=ip, day=day)
        raise

    try:
        return await run_in_threadpool(
            _settle_mark, me, day=day, ip=ip, device=device, strict_used=strict_used,
            attempt_id=attempt_id, blobs=blobs, result=result,
        )
    except MarkError:
        raise           # ALREADY_MARKED: a parallel request won; the verdict stands
    except Exception:
        # Disk full, DB hiccup: the reserved attempt must still be finalised,
        # and never as a failure the student pays for (see settle_failed).
        log.exception("settling attempt %s failed", attempt_id)
        await run_in_threadpool(marking.settle_failed, attempt_id)
        raise


@app.get("/api/my/history")
def my_history(me: Principal = Depends(current_student), months: int = 3):
    today = today_ist()
    start = today.replace(day=1)
    for _ in range(max(0, min(months, 12) - 1)):
        start = (start - timedelta(days=1)).replace(day=1)
    rows = query_all(
        "SELECT date_ist, kind, marked_at_utc, reason FROM att_records_current "
        "WHERE student_id=%s AND date_ist>=%s AND kind!='void' ORDER BY date_ist DESC",
        (me.student_id, start),
    )
    return {"ok": True, "records": [
        {
            "date": r["date_ist"].isoformat(),
            "kind": r["kind"],
            "marked_at_ist": _ist(r["marked_at_utc"]),
            "reason": r["reason"],
        }
        for r in rows
    ]}


# ---------------------------------------------------------------------------
# Enrollment (supervised, in office)
# ---------------------------------------------------------------------------

@app.post("/api/enroll/start")
def enroll_start(request: Request, student_id: int = Form(...), me: Principal = Depends(current_admin)):
    """Office staff opens a 20-minute, single-use enrollment session."""
    student = query_one(
        "SELECT s.id, s.roll_number, u.name FROM students s "
        "JOIN users u ON u.id=s.user_id WHERE s.id=%s",
        (student_id,),
    )
    if not student:
        raise MarkError(404, "NO_SUCH_STUDENT", "Student not found.")

    existing = marking.active_template(student_id)

    # Cancel any code still outstanding for this student. Only one code is ever
    # live at a time, which keeps the guessing surface at a single 6-digit value.
    execute(
        "UPDATE att_enroll_sessions SET status='cancelled' "
        "WHERE student_id=%s AND status='pending'",
        (student_id,),
    )

    # Six digits so the office can read it aloud. Low entropy on its own, but a
    # code alone grants nothing: the holder must also be signed in as *this*
    # student, be on the office WiFi, and use it within 20 minutes — and guesses
    # are throttled in enroll_capture below.
    raw = None
    for _ in range(10):                      # retry on the (rare) hash collision
        candidate = f"{secrets.randbelow(1_000_000):06d}"
        try:
            execute(
                "INSERT INTO att_enroll_sessions (student_id, token_hash, purpose, expires_at, created_by) "
                "VALUES (%s,%s,%s,DATE_ADD(NOW(), INTERVAL 20 MINUTE),%s)",
                (student_id, hash_enroll_code(candidate),
                 "re_enroll" if existing else "enroll", me.user_id),
            )
            raw = candidate
            break
        except Exception as exc:
            if "Duplicate" not in str(exc) and "1062" not in str(exc):
                raise
    if raw is None:
        raise MarkError(500, "CODE_GENERATION_FAILED", "Could not issue a code. Please try again.")
    audit(
        "enroll.session_created",
        actor_type="admin",
        user_id=me.user_id,
        student_id=student_id,
        source_ip=client_ip(request),
        details={"purpose": "re_enroll" if existing else "enroll"},
    )
    return {
        "ok": True,
        "token": raw,
        "expires_in": 1200,
        "student": {"id": student["id"], "name": student["name"], "roll": student["roll_number"]},
        "re_enroll": bool(existing),
    }


def _enroll_gates(me: Principal, token: str, ip: str | None) -> dict:
    """Network gate, code throttle and session lookup (database only)."""
    try:
        marking.check_enabled()
        marking.check_source_ip(ip)
    except MarkError as exc:
        audit("enroll.blocked", actor_type="student", student_id=me.student_id,
              source_ip=ip, details={"code": exc.code})
        raise

    # A six-digit code is only ~20 bits, so wrong guesses must be expensive.
    # Ten failures in 15 minutes locks this student out of enrollment until the
    # window passes, which makes exhausting the space over a 20-minute TTL
    # impossible while staying invisible to anyone typing their real code.
    recent_failures = query_one(
        "SELECT COUNT(*) AS n FROM att_audit WHERE action='enroll.bad_code' "
        "AND student_id=%s AND at_utc > NOW() - INTERVAL 15 MINUTE",
        (me.student_id,),
    )
    if recent_failures and int(recent_failures["n"]) >= 10:
        raise MarkError(429, "TOO_MANY_CODE_ATTEMPTS",
                        "Too many incorrect codes. Please wait 15 minutes and ask the office for a new code.")

    session = query_one(
        "SELECT * FROM att_enroll_sessions WHERE token_hash=%s AND status='pending' AND expires_at>NOW()",
        (hash_enroll_code(token),),
    )
    if not session:
        audit("enroll.bad_code", actor_type="student", student_id=me.student_id,
              source_ip=ip, details={"attempted": len(token)})
        raise MarkError(401, "ENROLL_SESSION_INVALID",
                        "That code is wrong or has expired. Ask the office for a new one.")
    if session["student_id"] != me.student_id:
        audit("enroll.wrong_student", actor_type="student", student_id=me.student_id,
              source_ip=ip, details={"session_student": session["student_id"]})
        raise MarkError(403, "WRONG_STUDENT", "This enrollment session belongs to a different student.")
    return session


def _build_enrollment(student_id: int, blobs: list[bytes], min_frames: int):
    """Decode + embed the capture and check it against the cohort. Runs on the
    face-engine pool. Returns (embeddings, quality, error_code, duplicate_of)."""
    import numpy as np

    images = [faces.decode_image(b) for b in blobs]
    embeddings, quality, error = faces.build_template(images, min_frames)
    if error:
        return [], quality, error, None

    # A face that already belongs to another student must never enroll twice —
    # that would be account-swapping by construction.
    centre = np.asarray(embeddings[0], dtype=np.float32)
    threshold = marking.thresholds()["match_threshold"]
    for other_id, tmpl in marking.load_cohort_templates(exclude_student_id=student_id):
        if faces.best_score(centre, tmpl) > threshold:
            return embeddings, quality, "FACE_ALREADY_ENROLLED", other_id
    return embeddings, quality, None, None


def _finish_enrollment(me: Principal, *, session: dict, blobs: list[bytes], embeddings: list,
                       quality: float, ua: str) -> str:
    """Store the evidence, swap in the new template and device. Returns the raw
    device token for the cookie."""
    student = query_one("SELECT roll_number FROM students WHERE id=%s", (me.student_id,))
    frames_dir, _ = marking.store_frames(student["roll_number"], today_ist(), blobs, kind="enroll")

    # Revoke-then-issue so a student can never hold two active devices. The
    # superseded template's embeddings are erased as it is revoked.
    marking.revoke_templates(me.student_id, reason="re-enrolled")
    marking.revoke_devices(me.student_id)
    execute(
        "INSERT INTO att_face_templates (student_id, embeddings, embedding_count, quality_score, "
        "frames_dir, enrolled_by, status) VALUES (%s,%s,%s,%s,%s,%s,'active')",
        (me.student_id, json.dumps(embeddings), len(embeddings), round(quality, 4),
         frames_dir, session["created_by"]),
    )

    device_token = secrets.token_urlsafe(32)
    execute(
        "INSERT INTO att_devices (student_id, token_hash, ua_hint, status) VALUES (%s,%s,%s,'active')",
        (me.student_id, hash_token(device_token), ua[:255]),
    )
    execute("UPDATE att_enroll_sessions SET status='used', used_at=NOW() WHERE id=%s", (session["id"],))
    # The enrollment frames sit on the student's phone as much as anywhere;
    # remember them so they can never be replayed as a marking burst.
    marking.record_frame_hashes(me.student_id, None,
                                [hashlib.sha256(b).hexdigest() for b in blobs], allow_seen=True)
    return device_token


@app.post("/api/enroll/capture")
async def enroll_capture(
    request: Request,
    response: Response,
    token: str = Form(...),
    frames: list[UploadFile] = File(...),
    me: Principal = Depends(current_student),
):
    """The student's phone submits the guided multi-angle capture.

    Two independent controls, so neither alone lets an impostor enroll:
      - the office-issued OTP proves a staff member started this session;
      - the office-WiFi source IP proves the phone is physically in the office.
    A leaked OTP is useless off the office network, and being on the network is
    useless without the OTP.

    Like /mark, the blocking work runs off the event loop.
    """
    ip = client_ip(request)
    session = await run_in_threadpool(_enroll_gates, me, token, ip)

    blobs = await _read_frames(frames, limit=MAX_ENROLL_FRAMES)
    min_frames = await run_in_threadpool(get_int_setting, "enrollment_min_frames", 5)
    try:
        async with faces.engine_slot():
            embeddings, quality, error, duplicate_of = await faces.run_in_engine(
                _build_enrollment, me.student_id, blobs, min_frames)
    except faces.EngineBusy:
        raise MarkError(503, "SERVER_BUSY",
                        "The server is busy. Wait a few seconds and start enrollment again.") from None

    if error == "FACE_ALREADY_ENROLLED":
        await run_in_threadpool(
            lambda: audit("enroll.duplicate_face", actor_type="student", student_id=me.student_id,
                          source_ip=ip, details={"matches_student": duplicate_of}))
        raise MarkError(
            409, "FACE_ALREADY_ENROLLED",
            "This face appears to be enrolled for another student. Please see the office.",
        )
    if error:
        message = {
            "NO_FACE": "No face detected. Hold the phone at eye level in good light.",
            "INSUFFICIENT_QUALITY_FRAMES": "Not enough clear frames. Please retry in better light.",
            "INCONSISTENT_FRAMES": "The captures did not look like the same person. Please retry.",
        }.get(error, "Enrollment capture failed. Please retry.")
        raise MarkError(422, error, message)

    device_token = await run_in_threadpool(
        _finish_enrollment, me, session=session, blobs=blobs, embeddings=embeddings,
        quality=quality, ua=request.headers.get("user-agent", ""),
    )

    response.set_cookie(
        DEVICE_COOKIE,
        device_token,
        max_age=COOKIE_MAX_AGE,
        httponly=True,
        secure=True,
        samesite="lax",
        path="/phdtimeoff",
    )
    await run_in_threadpool(
        lambda: audit("enroll.completed", actor_type="student", student_id=me.student_id,
                      source_ip=ip, details={"frames": len(embeddings), "quality": round(quality, 4)}))
    return {
        "ok": True,
        "message": "Enrollment complete. This phone is now your attendance device.",
        "frames_used": len(embeddings),
        "quality": round(quality, 4),
    }


# ---------------------------------------------------------------------------
# Office dashboard APIs
# ---------------------------------------------------------------------------

@app.get("/api/admin/today")
def admin_today(day: str | None = None, me: Principal = Depends(current_admin)):
    target = date.fromisoformat(day) if day else today_ist()
    window = resolve_window(target)

    students = query_all(
        "SELECT s.id, s.roll_number, u.name, "
        "(SELECT COUNT(*) FROM att_face_templates t WHERE t.student_id=s.id AND t.status='active') AS enrolled "
        "FROM students s JOIN users u ON u.id=s.user_id WHERE u.is_active=1 AND u.role='student' "
        "ORDER BY s.roll_number"
    )
    records = {
        r["student_id"]: r
        for r in query_all("SELECT * FROM att_records_current WHERE date_ist=%s", (target,))
    }
    pending = query_all(
        "SELECT a.*, s.roll_number, u.name FROM att_attempts a "
        "JOIN students s ON s.id=a.student_id JOIN users u ON u.id=s.user_id "
        "WHERE a.date_ist=%s AND a.verdict='pending' ORDER BY a.at_utc",
        (target,),
    )
    # Real failures only: server errors, refused reservations and attempts still
    # in flight are not the student's failures (marking.REAL_FAILURE_SQL).
    failed = query_all(
        "SELECT a.*, s.roll_number FROM att_attempts a LEFT JOIN students s ON s.id=a.student_id "
        f"WHERE a.date_ist=%s AND {marking.REAL_FAILURE_SQL} ORDER BY a.at_utc DESC LIMIT 100",
        (target,),
    )

    rows = []
    for s in students:
        rec = records.get(s["id"])
        status = rec["kind"] if (rec and rec["kind"] != "void") else "absent"
        rows.append({
            "student_id": s["id"],
            "roll": s["roll_number"],
            "name": s["name"],
            "enrolled": bool(s["enrolled"]),
            "status": status,
            "marked_at_ist": _ist(rec["marked_at_utc"]) if rec else None,
            "frames_dir": rec["selfie_path"] if rec else None,
            "match_score": float(rec["match_score"]) if rec and rec["match_score"] is not None else None,
            "reason": rec["reason"] if rec else None,
        })

    return {
        "ok": True,
        "date": target.isoformat(),
        "window": {
            "working_day": window.is_working_day,
            "start": window.start_label,
            "end": window.end_label,
            "extension": window.extension_minutes,
            "label": window.label,
        },
        "counts": {
            "total": len(rows),
            "present": sum(1 for r in rows if r["status"] in ("self", "manual")),
            "excused": sum(1 for r in rows if r["status"] == "excused"),
            "absent": sum(1 for r in rows if r["status"] == "absent"),
            "pending_review": len(pending),
            "failed_attempts": len(failed),
            "not_enrolled": sum(1 for r in rows if not r["enrolled"]),
        },
        "rows": rows,
        "pending": [
            {
                "attempt_id": p["id"],
                "student_id": p["student_id"],
                "roll": p["roll_number"],
                "name": p["name"],
                "at_ist": _ist(p["at_utc"]),
                "match_score": float(p["match_score"] or 0),
                "liveness": float(p["liveness_score"] or 0),
                "frames_dir": p["frames_dir"],
            }
            for p in pending
        ],
        "failed": [
            {
                "attempt_id": f["id"],
                "student_id": f["student_id"],
                "roll": f.get("roll_number"),
                "at_ist": _ist(f["at_utc"]),
                "code": f["fail_code"],
                "crossmatch_student_id": f["crossmatch_student_id"],
                "source_ip": f["source_ip"],
                "frames_dir": f["frames_dir"],
            }
            for f in failed
        ],
    }


@app.get("/api/admin/frame")
def admin_frame(dir: str, index: int = 0, me: Principal = Depends(current_admin)):
    """Serve one stored evidence frame to the office UI (path-traversal safe)."""
    from pathlib import Path

    base = Path(get_settings().frames_dir).resolve()
    target = (base / dir).resolve()
    if not str(target).startswith(str(base)) or not target.is_dir():
        raise MarkError(404, "NO_FRAMES", "Frames not found (may have been purged).")
    frames = sorted(target.glob("*.jpg"))
    if not frames or index >= len(frames):
        raise MarkError(404, "NO_FRAMES", "Frames not found (may have been purged).")
    return FileResponse(frames[min(index, len(frames) - 1)], media_type="image/jpeg")


@app.post("/api/admin/review")
def admin_review(
    request: Request,
    attempt_id: int = Form(...),
    decision: str = Form(...),          # approve | reject
    reason: str = Form(default=""),
    me: Principal = Depends(current_admin),
):
    """Resolve a pending-review attempt. Approving marks the student present."""
    attempt = query_one("SELECT * FROM att_attempts WHERE id=%s", (attempt_id,))
    if not attempt:
        raise MarkError(404, "NO_SUCH_ATTEMPT", "Attempt not found.")
    if attempt["verdict"] != "pending":
        raise MarkError(409, "ALREADY_RESOLVED", "This attempt has already been resolved.")
    if decision not in ("approve", "reject"):
        raise MarkError(400, "BAD_DECISION", "Decision must be approve or reject.")
    if decision == "reject" and not reason.strip():
        raise MarkError(400, "REASON_REQUIRED", "A reason is required when rejecting.")

    # Insert BEFORE consuming the attempt: if the insert fails the review stays
    # pending and can be retried, instead of being marked resolved with no
    # attendance record to show for it.
    record_id = None
    if decision == "approve":
        version, supersedes_id = marking.next_version_for(
            attempt["student_id"], attempt["date_ist"])
        record_id = marking.insert_record(
            student_id=attempt["student_id"],
            day=attempt["date_ist"],
            kind="self",
            marked_at=attempt["at_utc"].replace(tzinfo=timezone.utc),
            source_ip=attempt["source_ip"],
            device_id=attempt["device_id"],
            attempt_id=attempt_id,
            selfie_path=attempt["frames_dir"],
            match_score=float(attempt["match_score"] or 0),
            liveness_score=float(attempt["liveness_score"] or 0),
            reason=f"Face review approved by office. {reason}".strip(),
            created_by_user_id=me.user_id,
            version=version,
            supersedes_id=supersedes_id,
        )

    execute("UPDATE att_attempts SET verdict=%s WHERE id=%s",
            ("pass" if decision == "approve" else "fail", attempt_id))

    audit(
        f"review.{decision}",
        actor_type="admin",
        user_id=me.user_id,
        student_id=attempt["student_id"],
        source_ip=client_ip(request),
        target_type="att_attempts",
        target_id=attempt_id,
        details={"reason": reason, "record_id": record_id},
    )
    return {"ok": True, "record_id": record_id}


@app.post("/api/admin/mark")
def admin_mark(
    request: Request,
    student_id: int = Form(...),
    day: str = Form(...),
    kind: str = Form(...),              # manual | excused | void
    reason: str = Form(...),
    me: Principal = Depends(current_admin),
):
    """Office override. Reason mandatory; never disguised as a self-mark."""
    if kind not in ("manual", "excused", "void"):
        raise MarkError(400, "BAD_KIND", "Kind must be manual, excused or void.")
    if not reason.strip():
        raise MarkError(400, "REASON_REQUIRED", "A reason is mandatory for office overrides.")

    record_id = marking.supersede(
        student_id=student_id,
        day=date.fromisoformat(day),
        kind=kind,
        reason=reason.strip(),
        created_by_user_id=me.user_id,
    )
    audit(
        "mark.override",
        actor_type="admin",
        user_id=me.user_id,
        student_id=student_id,
        source_ip=client_ip(request),
        target_type="att_records",
        target_id=record_id,
        details={"kind": kind, "date": day, "reason": reason.strip()},
    )
    return {"ok": True, "record_id": record_id}


@app.post("/api/admin/extend")
def admin_extend(
    request: Request,
    minutes: int = Form(...),
    reason: str = Form(...),
    day: str = Form(default=""),
    me: Principal = Depends(current_admin),
):
    """Emergency valve: stretch today's window when WiFi/power/server failed."""
    if not 1 <= minutes <= 720:
        raise MarkError(400, "BAD_MINUTES", "Extension must be between 1 and 720 minutes.")
    if not reason.strip():
        raise MarkError(400, "REASON_REQUIRED", "A reason is required.")
    target = date.fromisoformat(day) if day else today_ist()
    execute(
        "INSERT INTO att_extensions (date_ist, extra_minutes, reason, created_by) VALUES (%s,%s,%s,%s)",
        (target, minutes, reason.strip(), me.user_id),
    )
    audit("window.extended", actor_type="admin", user_id=me.user_id, source_ip=client_ip(request),
          details={"date": str(target), "minutes": minutes, "reason": reason.strip()})
    return {"ok": True, "new_end": resolve_window(target).end_label}


@app.get("/api/admin/settings")
def admin_settings(me: Principal = Depends(current_admin)):
    rows = query_all("SELECT skey, svalue FROM att_settings")
    ips = query_all("SELECT * FROM att_allowed_ips ORDER BY is_active DESC, id DESC")
    windows = query_all("SELECT * FROM att_windows ORDER BY weekday")
    overrides = query_all(
        "SELECT * FROM att_date_overrides WHERE date_ist>=CURDATE()-INTERVAL 60 DAY ORDER BY date_ist"
    )
    return {
        "ok": True,
        "settings": {r["skey"]: r["svalue"] for r in rows if r["skey"] != "strict_code_secret"},
        "allowed_ips": [
            {"id": i["id"], "ip": i["ip"], "label": i["label"], "active": bool(i["is_active"])}
            for i in ips
        ],
        "windows": [
            {"weekday": w["weekday"], "working": bool(w["is_working_day"]),
             "start": fmt_minute(w["start_minute"]), "end": fmt_minute(w["end_minute"])}
            for w in windows
        ],
        "overrides": [
            {"date": o["date_ist"].isoformat(), "working": o["is_working_day"],
             "start": fmt_minute(o["start_minute"]) if o["start_minute"] is not None else None,
             "end": fmt_minute(o["end_minute"]) if o["end_minute"] is not None else None,
             "label": o["label"]}
            for o in overrides
        ],
    }


@app.post("/api/admin/settings")
def admin_set_setting(
    request: Request,
    key: str = Form(...),
    value: str = Form(...),
    me: Principal = Depends(current_admin),
):
    allowed = {
        "face_match_threshold", "face_review_threshold", "liveness_threshold",
        "crossmatch_margin", "max_attempts_per_day", "frame_retention_days",
        "strict_mode", "report_recipients", "enrollment_min_frames", "attendance_enabled",
        # liveness v2 (faces.py "Liveness v2"; RUNBOOK "Liveness v2")
        "liveness_v2", "liveness_v2_students", "flash_enabled",
        "flash_min_signal", "flash_min_correlation", "antispoof_review_threshold",
        "blink_closed_min", "blink_open_max", "blink_min_diff", "review_skip_match",
    }
    if key not in allowed:
        raise MarkError(400, "BAD_SETTING", "Unknown setting.")
    value = _valid_setting(key, value)

    if key == "strict_mode" and value == "1" and not get_setting("strict_code_secret"):
        execute(
            "INSERT INTO att_settings (skey, svalue) VALUES ('strict_code_secret', %s) "
            "ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)",
            (secrets.token_hex(32),),
        )

    execute(
        "INSERT INTO att_settings (skey, svalue, updated_by) VALUES (%s,%s,%s) "
        "ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_by=VALUES(updated_by)",
        (key, value, me.user_id),
    )
    audit("settings.updated", actor_type="admin", user_id=me.user_id, source_ip=client_ip(request),
          details={"key": key, "value": value})
    return {"ok": True}


def _valid_setting(key: str, value: str) -> str:
    """Normalise the liveness v2 knobs, refusing values the backend would
    silently misread (a typo in the pilot list must not quietly drop it)."""
    value = (value or "").strip()
    if key in ("liveness_v2", "flash_enabled"):
        if value not in ("0", "1"):
            raise MarkError(400, "BAD_VALUE", "Use 1 (on) or 0 (off).")
    elif key == "liveness_v2_students":
        try:
            ids = json.loads(value or "[]")
            if not isinstance(ids, list):
                raise ValueError
            ids = sorted({int(v) for v in ids})
        except (TypeError, ValueError):
            raise MarkError(400, "BAD_VALUE", "Give a JSON list of student ids, e.g. [12, 40].") from None
        value = json.dumps(ids)
    elif key in ("flash_min_signal", "flash_min_correlation", "antispoof_review_threshold",
                 "blink_closed_min", "blink_open_max", "blink_min_diff", "review_skip_match"):
        try:
            number = float(value)
        except ValueError:
            raise MarkError(400, "BAD_VALUE", "Enter a number.") from None
        if not 0 <= number <= 1.0:
            raise MarkError(400, "BAD_VALUE", "Enter a number between 0 and 1.")
    return value


@app.post("/api/admin/allowlist")
def admin_allowlist(
    request: Request,
    action: str = Form(...),            # add | deactivate
    ip: str = Form(default=""),
    label: str = Form(default=""),
    entry_id: int = Form(default=0),
    me: Principal = Depends(current_admin),
):
    if action == "add":
        if not ip.strip():
            raise MarkError(400, "IP_REQUIRED", "Enter the router's WAN IP.")
        execute(
            "INSERT INTO att_allowed_ips (ip, label, created_by) VALUES (%s,%s,%s)",
            (ip.strip(), label.strip() or "Office WiFi", me.user_id),
        )
        audit("allowlist.added", actor_type="admin", user_id=me.user_id,
              source_ip=client_ip(request), details={"ip": ip.strip()})
    elif action == "deactivate":
        execute(
            "UPDATE att_allowed_ips SET is_active=0, deactivated_at=NOW(), deactivated_by=%s WHERE id=%s",
            (me.user_id, entry_id),
        )
        audit("allowlist.deactivated", actor_type="admin", user_id=me.user_id,
              source_ip=client_ip(request), details={"entry_id": entry_id})
    else:
        raise MarkError(400, "BAD_ACTION", "Unknown action.")
    return {"ok": True}


@app.post("/api/admin/window")
def admin_window(
    request: Request,
    weekday: int = Form(...),
    working: int = Form(...),
    start: str = Form(...),
    end: str = Form(...),
    me: Principal = Depends(current_admin),
):
    def to_minutes(hhmm: str) -> int:
        h, m = hhmm.split(":")
        return int(h) * 60 + int(m)

    start_m, end_m = to_minutes(start), to_minutes(end)
    if start_m >= end_m:
        raise MarkError(400, "BAD_WINDOW", "Start time must be before end time.")
    if not 1 <= weekday <= 7:
        raise MarkError(400, "BAD_WEEKDAY", "Weekday must be 1 (Mon) to 7 (Sun).")

    execute(
        "INSERT INTO att_windows (weekday, is_working_day, start_minute, end_minute, updated_by) "
        "VALUES (%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE is_working_day=VALUES(is_working_day), "
        "start_minute=VALUES(start_minute), end_minute=VALUES(end_minute), updated_by=VALUES(updated_by)",
        (weekday, 1 if working else 0, start_m, end_m, me.user_id),
    )
    audit("window.updated", actor_type="admin", user_id=me.user_id, source_ip=client_ip(request),
          details={"weekday": weekday, "start": start, "end": end, "working": bool(working)})
    return {"ok": True}


@app.post("/api/admin/override")
def admin_override_date(
    request: Request,
    day: str = Form(...),
    working: str = Form(default=""),    # "", "0" or "1"
    start: str = Form(default=""),
    end: str = Form(default=""),
    label: str = Form(default=""),
    remove: int = Form(default=0),
    me: Principal = Depends(current_admin),
):
    target = date.fromisoformat(day)
    if remove:
        execute("DELETE FROM att_date_overrides WHERE date_ist=%s", (target,))
        audit("override.removed", actor_type="admin", user_id=me.user_id,
              source_ip=client_ip(request), details={"date": day})
        return {"ok": True}

    def to_minutes(hhmm: str) -> int | None:
        if not hhmm:
            return None
        h, m = hhmm.split(":")
        return int(h) * 60 + int(m)

    execute(
        "INSERT INTO att_date_overrides (date_ist, is_working_day, start_minute, end_minute, label, created_by) "
        "VALUES (%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE is_working_day=VALUES(is_working_day), "
        "start_minute=VALUES(start_minute), end_minute=VALUES(end_minute), label=VALUES(label)",
        (target, None if working == "" else int(working), to_minutes(start), to_minutes(end),
         label.strip() or None, me.user_id),
    )
    audit("override.set", actor_type="admin", user_id=me.user_id, source_ip=client_ip(request),
          details={"date": day, "working": working, "start": start, "end": end})
    return {"ok": True}


@app.post("/api/admin/revoke")
def admin_revoke(
    request: Request,
    student_id: int = Form(...),
    reason: str = Form(...),
    me: Principal = Depends(current_admin),
):
    """Revoke a student's face template and bound device (lost/changed phone,
    or a leaver). The template's embeddings are erased as part of the revoke."""
    if not reason.strip():
        raise MarkError(400, "REASON_REQUIRED", "A reason is required.")
    marking.revoke_templates(student_id, reason=reason.strip(), revoked_by=me.user_id)
    marking.revoke_devices(student_id, revoked_by=me.user_id)
    audit("enrollment.revoked", actor_type="admin", user_id=me.user_id, student_id=student_id,
          source_ip=client_ip(request), details={"reason": reason.strip()})
    return {"ok": True}


@app.get("/api/admin/audit")
def admin_audit_view(limit: int = 200, action: str = "", me: Principal = Depends(current_admin)):
    limit = max(1, min(limit, 1000))
    if action:
        rows = query_all("SELECT * FROM att_audit WHERE action=%s ORDER BY id DESC LIMIT %s",
                         (action, limit))
    else:
        rows = query_all("SELECT * FROM att_audit ORDER BY id DESC LIMIT %s", (limit,))
    return {"ok": True, "entries": [
        {
            "id": r["id"], "at": r["at_utc"].isoformat(), "actor": r["actor_type"],
            "user_id": r["user_id"], "student_id": r["student_id"], "action": r["action"],
            "source_ip": r["source_ip"], "details": r["details"],
        }
        for r in rows
    ]}


@app.get("/api/admin/strict-code")
def admin_strict_code(me: Principal = Depends(current_admin)):
    """Polled by the office display page when strict mode is on."""
    import time as _time

    if get_setting("strict_mode", "0") != "1":
        return {"ok": True, "enabled": False}
    secret = get_setting("strict_code_secret", "")
    if not secret:
        return {"ok": True, "enabled": False}
    return {
        "ok": True,
        "enabled": True,
        "code": marking.current_strict_code(secret),
        "seconds_left": int(30 - (_time.time() % 30)),
    }


# ---------------------------------------------------------------------------
# Reports & maintenance jobs
# ---------------------------------------------------------------------------

@app.post("/api/admin/report")
def admin_report(
    request: Request,
    year: int = Form(...),
    month: int = Form(...),
    me: Principal = Depends(current_admin),
):
    result = reports.generate_monthly(year, month, trigger="manual", triggered_by=me.user_id)
    audit("report.generated", actor_type="admin", user_id=me.user_id, source_ip=client_ip(request),
          details={"year": year, "month": month, "version": result.get("version")})
    return {"ok": True, **result}


@app.get("/api/admin/report/preview")
def admin_report_preview(year: int, month: int, me: Principal = Depends(current_admin)):
    return {"ok": True, **reports.compute_month(year, month)}


@app.get("/api/admin/reports")
def admin_reports_list(me: Principal = Depends(current_admin)):
    rows = query_all(
        "SELECT id, period_year, period_month, version, trigger_type, generated_at, "
        "working_days, student_count, xlsx_sha256, email_status FROM att_reports "
        "ORDER BY period_year DESC, period_month DESC, version DESC LIMIT 60"
    )
    return {"ok": True, "reports": [
        {**r, "generated_at": r["generated_at"].isoformat()} for r in rows
    ]}


@app.get("/api/admin/report/download")
def admin_report_download(report_id: int, me: Principal = Depends(current_admin)):
    row = query_one("SELECT * FROM att_reports WHERE id=%s", (report_id,))
    if not row or not row["xlsx_path"]:
        raise MarkError(404, "NO_REPORT", "Report not found.")
    name = f"attendance-{row['period_year']}-{row['period_month']:02d}-v{row['version']}.xlsx"
    return FileResponse(row["xlsx_path"], filename=name,
                        media_type="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")


@app.post("/api/jobs/monthly-report")
def job_monthly_report(request: Request, year: int = Form(default=0), month: int = Form(default=0)):
    _require_job_token(request)
    if not year or not month:
        first = today_ist().replace(day=1)
        previous = first - timedelta(days=1)
        year, month = previous.year, previous.month
    result = reports.generate_monthly(year, month, trigger="scheduled")
    audit("report.generated", details={"year": year, "month": month, "version": result.get("version")})
    return {"ok": True, **result}


@app.post("/api/jobs/purge-frames")
def job_purge_frames(request: Request):
    """Nightly biometric retention: evidence frames past retention, plus the
    templates of revoked enrollments and deactivated students (see
    marking.purge_templates). Kept on this one route so the existing host
    timers/crontab pick it up unchanged."""
    _require_job_token(request)
    days = get_int_setting("frame_retention_days", 60)
    removed = reports.purge_frames(days)
    audit("frames.purged", details={"removed": removed, "older_than_days": days})
    templates = marking.purge_templates()
    audit("templates.purged", details=templates)
    return {"ok": True, "removed": removed, **templates}


@app.post("/api/jobs/cleanup")
def job_cleanup(request: Request):
    _require_job_token(request)
    expired = execute("DELETE FROM att_challenges WHERE expires_at < NOW() - INTERVAL 1 DAY")
    sessions = execute("DELETE FROM att_enroll_sessions WHERE expires_at < NOW() - INTERVAL 7 DAY "
                       "AND status='pending'")
    return {"ok": True, "challenges_removed": expired, "sessions_removed": sessions}


def _require_job_token(request: Request) -> None:
    """Every /api/jobs/* route calls this first. An unset JOB_TOKEN refuses
    everything; the comparison is constant-time. Bytes, not str: compare_digest
    raises TypeError on non-ASCII str, which turned a junk header into a 500."""
    expected = os.environ.get("JOB_TOKEN", "")
    supplied = request.headers.get("authorization", "").removeprefix("Bearer ").strip()
    if not expected or not hmac.compare_digest(expected.encode(), supplied.encode()):
        raise MarkError(401, "BAD_JOB_TOKEN", "Unauthorised.")
