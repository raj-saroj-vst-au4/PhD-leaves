"""Runtime configuration, loaded from environment (see .env.example)."""

from functools import lru_cache
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    # --- database (the existing phd_timeoff schema) ---
    # Prefer the unix socket: it works identically under rootful and rootless
    # Docker, and needs no TCP listener on the database (both hosts bind MySQL
    # to 127.0.0.1 only, which a container cannot reach). Falls back to
    # host/port when db_socket is empty.
    db_socket: str = ""
    db_host: str = "127.0.0.1"
    db_port: int = 3306
    db_name: str = "phd_timeoff"
    db_user: str = "attendance_app"
    db_password: str = ""

    # --- auth bridge with the PHP frontend ---
    # PHP mints a short-lived HMAC ticket for the logged-in user; we verify it.
    # Must match ATT_SHARED_SECRET in config/attendance.php.
    shared_secret: str = ""
    ticket_ttl_seconds: int = 900

    # --- storage ---
    frames_dir: str = "/data/frames"
    reports_dir: str = "/data/reports"

    # --- face engine ---
    model_name: str = "buffalo_l"
    model_root: str = "/models"
    det_size: int = 640
    # A face smaller than this fraction of frame width is too far away to judge.
    min_face_ratio: float = 0.18
    # Passive anti-spoof model for liveness v2 (review flag only). Baked into
    # the image by the Dockerfile with a checksum; if it is missing the signal
    # is simply off (faces.get_antispoof).
    antispoof_model: str = "/models/antispoof/MiniFASNetV2.onnx"
    # Concurrent verifications per uvicorn worker (the Dockerfile runs 2). Each
    # one is already multi-threaded inside ONNX Runtime, so more slots mostly
    # add contention; the rest queue on the event loop without holding a thread.
    face_workers: int = 2
    # How long a request may wait for a slot before it is told to retry. Kept
    # well inside the PHP forwarder's 120 s curl timeout.
    face_queue_timeout: float = 30.0

    # --- request limits ---
    # Whole request body. A marking burst is ~1-2 MB and an enrollment sweep
    # ~5 MB; anything far beyond that is not a phone camera.
    max_body_bytes: int = 12 * 1024 * 1024

    # --- misc ---
    timezone: str = "Asia/Kolkata"
    log_level: str = "INFO"


@lru_cache
def get_settings() -> Settings:
    return Settings()
