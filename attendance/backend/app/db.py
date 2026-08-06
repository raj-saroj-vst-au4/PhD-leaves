"""Thin MySQL/MariaDB access layer.

Deliberately plain PyMySQL rather than an ORM: the append-only tables are
protected by GRANTs and triggers, and an ORM that silently emits UPDATEs would
just crash against them. Explicit SQL keeps the write paths honest.
"""

from __future__ import annotations

import json
from contextlib import contextmanager
from typing import Any, Iterable

import pymysql
from pymysql.cursors import DictCursor

from .config import get_settings

_pool: list[pymysql.Connection] = []
_POOL_MAX = 5


def _new_conn() -> pymysql.Connection:
    s = get_settings()
    # A unix socket connection also satisfies MySQL's 'user'@'localhost' grants,
    # which is what the portal's database user is defined as.
    where = {"unix_socket": s.db_socket} if s.db_socket else {"host": s.db_host, "port": s.db_port}
    return pymysql.connect(
        **where,
        user=s.db_user,
        password=s.db_password,
        database=s.db_name,
        charset="utf8mb4",
        cursorclass=DictCursor,
        # autocommit: pooled connections are long-lived, so a REPEATABLE-READ
        # snapshot held open across borrows would go stale — a student's netcheck
        # could miss an allowed IP an admin just added on another connection.
        # With autocommit every statement is its own transaction and sees the
        # latest committed data. (No multi-statement transaction is relied upon;
        # the once-per-day guarantee is the unique index, not tx isolation.)
        autocommit=True,
        # Pin this connection to UTC so NOW()/current_timestamp() defaults match
        # the UTC instants Python inserts. Every att_* timestamp is then UTC, and
        # the dashboard's _ist() converts uniformly. (The PHP app's own
        # connections are unaffected — they keep the server's IST session.)
        init_command="SET time_zone='+00:00'",
    )


@contextmanager
def connection():
    """Borrow a connection, returning it to a small pool on the way out."""
    conn = _pool.pop() if _pool else _new_conn()
    try:
        conn.ping(reconnect=True)
    except Exception:
        conn = _new_conn()
    try:
        yield conn
    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise
    finally:
        if len(_pool) < _POOL_MAX:
            _pool.append(conn)
        else:
            conn.close()


def query_all(sql: str, params: Iterable[Any] = ()) -> list[dict]:
    with connection() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            return cur.fetchall()


def query_one(sql: str, params: Iterable[Any] = ()) -> dict | None:
    with connection() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            return cur.fetchone()


def execute(sql: str, params: Iterable[Any] = ()) -> int:
    """Run a write and return lastrowid (or affected rows for updates)."""
    with connection() as conn:
        with conn.cursor() as cur:
            cur.execute(sql, tuple(params))
            rid = cur.lastrowid
            conn.commit()
            return rid if rid else cur.rowcount


def audit(
    action: str,
    *,
    actor_type: str = "system",
    user_id: int | None = None,
    student_id: int | None = None,
    target_type: str | None = None,
    target_id: str | int | None = None,
    source_ip: str | None = None,
    details: dict | None = None,
    conn: pymysql.Connection | None = None,
) -> None:
    """Append to att_audit. Never raises — a failed audit write must not take
    down the request that triggered it, but it must be visible in the log."""
    sql = (
        "INSERT INTO att_audit "
        "(actor_type, user_id, student_id, action, target_type, target_id, source_ip, details) "
        "VALUES (%s,%s,%s,%s,%s,%s,%s,%s)"
    )
    params = (
        actor_type,
        user_id,
        student_id,
        action,
        target_type,
        str(target_id) if target_id is not None else None,
        source_ip,
        json.dumps(details, default=str) if details else None,
    )
    try:
        if conn is not None:
            with conn.cursor() as cur:
                cur.execute(sql, params)
        else:
            execute(sql, params)
    except Exception:  # pragma: no cover
        import logging

        logging.getLogger(__name__).exception("audit write failed: %s", action)


def get_setting(key: str, default: str = "") -> str:
    row = query_one("SELECT svalue FROM att_settings WHERE skey=%s", (key,))
    return row["svalue"] if row else default


def get_float_setting(key: str, default: float) -> float:
    try:
        return float(get_setting(key, str(default)))
    except (TypeError, ValueError):
        return default


def get_int_setting(key: str, default: int) -> int:
    try:
        return int(float(get_setting(key, str(default))))
    except (TypeError, ValueError):
        return default
