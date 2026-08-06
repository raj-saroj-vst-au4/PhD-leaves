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

    # --- misc ---
    timezone: str = "Asia/Kolkata"
    log_level: str = "INFO"


@lru_cache
def get_settings() -> Settings:
    return Settings()
