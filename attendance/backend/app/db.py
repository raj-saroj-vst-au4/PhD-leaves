"""Thin MySQL/MariaDB access layer.

Deliberately plain PyMySQL rather than an ORM: the append-only tables are
protected by GRANTs and triggers, and an ORM that silently emits UPDATEs would
just crash against them. Explicit SQL keeps the write paths honest.
"""

from __future__ import annotations

import json
import queue
from contextlib import contextmanager
from typing import Any, Iterable

import pymysql
from pymysql.cursors import DictCursor

from .config import get_settings

# Idle connections, shared by every thread of this worker (FastAPI runs the
# plain `def` endpoints on a thread pool). A thread-safe LIFO queue, not a bare
# list: the old `_pool.pop() if _pool else ...` checked and popped in two steps,
# so two threads could both see one idle connection, and the loser's pop()
# raised IndexError — the occasional unexplained 500 under load.
_POOL_MAX = 5
_pool: "queue.LifoQueue[pymysql.Connection]" = queue.LifoQueue(maxsize=_POOL_MAX)

# Errors after which a connection's protocol state cannot be trusted. Anything
# else (a duplicate key, a trigger's SIGNAL) leaves it perfectly reusable.
_BROKEN = (pymysql.err.OperationalError, pymysql.err.InterfaceError)


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


def _close_quietly(conn: pymysql.Connection) -> None:
    try:
        conn.close()
    except Exception:
        pass


@contextmanager
def connection():
    """Borrow a connection, returning it to a small pool on the way out.

    Each connection is held by exactly one thread at a time (PyMySQL
    connections are not thread-safe). A pooled one is pinged first — MySQL
    drops idle sessions after wait_timeout, and mysqld restarts drop them all —
    and one that errored at the protocol level is closed, never handed on.
    """
    try:
        conn = _pool.get_nowait()
    except queue.Empty:
        conn = _new_conn()
    else:
        try:
            conn.ping(reconnect=True)       # reconnect re-runs init_command (UTC)
        except Exception:
            _close_quietly(conn)
            conn = _new_conn()

    reusable = True
    try:
        yield conn
    except Exception as exc:
        if isinstance(exc, _BROKEN):
            reusable = False
        try:
            conn.rollback()
        except Exception:
            reusable = False
        raise
    finally:
        if reusable and conn.open:
            try:
                _pool.put_nowait(conn)
                conn = None
            except queue.Full:
                pass
        if conn is not None:
            _close_quietly(conn)


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
