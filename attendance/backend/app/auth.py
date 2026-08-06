"""Auth bridge between the PHP frontend and this API.

There is no second login. The student (or office staff) is already authenticated
by the phdtimeoff PHP session; that page mints a short-lived HMAC ticket which
the browser sends here. We verify the signature and expiry — so a ticket cannot
be forged, cannot be reused past its TTL, and carries the role we need.

Client IP is taken *only* from the header Apache sets unconditionally
(X-Client-IP). X-Forwarded-For is never trusted: mod_proxy appends to it, so its
first element is attacker-controlled.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
from base64 import urlsafe_b64decode, urlsafe_b64encode
from dataclasses import dataclass

from fastapi import Header, HTTPException, Request

from .config import get_settings
from .db import query_one


@dataclass(frozen=True)
class Principal:
    user_id: int
    role: str          # student | faculty | hod | admin
    student_id: int | None
    name: str
    email: str

    @property
    def is_admin(self) -> bool:
        return self.role in ("admin", "hod")


def _b64d(data: str) -> bytes:
    return urlsafe_b64decode(data + "=" * (-len(data) % 4))


def sign_ticket(payload: dict) -> str:
    """Mirror of the PHP minting function — used by tests and the CLI."""
    secret = get_settings().shared_secret.encode()
    body = urlsafe_b64encode(json.dumps(payload, separators=(",", ":")).encode()).rstrip(b"=")
    sig = hmac.new(secret, body, hashlib.sha256).digest()
    return f"{body.decode()}.{urlsafe_b64encode(sig).rstrip(b'=').decode()}"


def verify_ticket(ticket: str) -> Principal:
    settings = get_settings()
    if not settings.shared_secret:
        raise HTTPException(500, {"code": "SERVER_MISCONFIG", "message": "Auth bridge not configured"})
    try:
        body_b64, sig_b64 = ticket.split(".", 1)
        expected = hmac.new(settings.shared_secret.encode(), body_b64.encode(), hashlib.sha256).digest()
        if not hmac.compare_digest(expected, _b64d(sig_b64)):
            raise ValueError("bad signature")
        payload = json.loads(_b64d(body_b64))
    except Exception:
        raise HTTPException(401, {"code": "TICKET_INVALID", "message": "Session expired. Please reload the page."})

    if int(payload.get("exp", 0)) < int(time.time()):
        raise HTTPException(401, {"code": "TICKET_EXPIRED", "message": "Session expired. Please reload the page."})

    return Principal(
        user_id=int(payload["uid"]),
        role=str(payload["role"]),
        student_id=int(payload["sid"]) if payload.get("sid") else None,
        name=str(payload.get("name", "")),
        email=str(payload.get("email", "")),
    )


async def current_principal(authorization: str = Header(default="")) -> Principal:
    if not authorization.startswith("Bearer "):
        raise HTTPException(401, {"code": "NO_TICKET", "message": "Not signed in"})
    return verify_ticket(authorization[7:].strip())


async def current_student(authorization: str = Header(default="")) -> Principal:
    p = await current_principal(authorization)
    if p.role != "student" or not p.student_id:
        raise HTTPException(403, {"code": "NOT_A_STUDENT", "message": "This action is for students only"})
    return p


async def current_admin(authorization: str = Header(default="")) -> Principal:
    p = await current_principal(authorization)
    if not p.is_admin:
        raise HTTPException(403, {"code": "NOT_ADMIN", "message": "Office access required"})
    return p


def client_ip(request: Request) -> str | None:
    """The only trusted source of the caller's address.

    Apache sets X-Client-IP from REMOTE_ADDR on every proxied request, so a
    client-supplied value of the same name is always overwritten before it
    reaches us. If the header is missing the proxy is misconfigured, and we
    fail closed rather than guess.
    """
    return request.headers.get("x-client-ip") or None


def hash_token(raw: str) -> str:
    return hashlib.sha256(raw.encode()).hexdigest()


def hash_enroll_code(raw: str) -> str:
    """Hash a short enrollment code, peppered with the server secret.

    Enrollment codes are only six digits so the office can read them aloud —
    a plain SHA-256 of one is reversible instantly from a rainbow table. HMAC
    with the shared secret means a leaked database still does not reveal any
    live code. (Brute-forcing the code over the API is separately throttled.)
    """
    secret = get_settings().shared_secret.encode()
    return hmac.new(secret, ("enroll:" + raw).encode(), hashlib.sha256).hexdigest()


def device_for_request(request: Request, student_id: int) -> dict | None:
    """Resolve the bound-device row from the cookie, if it is this student's."""
    raw = request.cookies.get("att_device")
    if not raw:
        return None
    return query_one(
        "SELECT * FROM att_devices WHERE token_hash=%s AND student_id=%s",
        (hash_token(raw), student_id),
    )
