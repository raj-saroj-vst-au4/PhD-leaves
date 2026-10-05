"""Concurrency fixes: the atomic attempt budget, the face-engine offload, and
the thread-safe connection pool. The database is replaced by small in-memory
fakes that commit each statement atomically, as autocommit MySQL does."""

import asyncio
import threading
import time
from datetime import date

import pytest

from app import db, faces, marking


# ---------------------------------------------------------------------------
# Attempt budget
# ---------------------------------------------------------------------------

class FakeAttempts:
    """att_attempts as a list. Each statement is atomic (autocommit), and a
    short sleep between statements lets racing threads interleave."""

    def __init__(self):
        self.rows = []
        self.lock = threading.Lock()

    # -- what marking.reserve_attempt uses --
    def connection(self):
        fake = self

        class Cur:
            lastrowid = None

            def __enter__(self):
                return self

            def __exit__(self, *a):
                return False

            def execute(self, sql, params):
                assert sql.startswith("INSERT INTO att_attempts")
                with fake.lock:
                    fake.rows.append({"id": len(fake.rows) + 1, "student_id": params[0],
                                      "verdict": "fail", "fail_code": "VERIFYING"})
                    self.lastrowid = len(fake.rows)
                time.sleep(0.002)

        class Conn:
            def __enter__(self):
                return self

            def __exit__(self, *a):
                return False

            def cursor(self):
                return Cur()

            def commit(self):
                pass

        return Conn()

    def query_one(self, sql, params):
        # What _counted_attempts' SQL counts: judged failures, pending reviews
        # and in-flight reservations; not server errors or refused slots.
        assert "COUNT(*)" in sql and "'pending'" in sql and "TOO_MANY_ATTEMPTS" in sql
        with self.lock:
            n = sum(1 for r in self.rows if r["student_id"] == params[0]
                    and r["verdict"] in ("fail", "pending")
                    and r["fail_code"] not in marking.NOT_COUNTED_CODES)
        time.sleep(0.002)
        return {"n": n}

    def execute(self, sql, params):
        with self.lock:
            for r in self.rows:
                if r["id"] == params[0]:
                    r["fail_code"] = "TOO_MANY_ATTEMPTS"
        return 1


@pytest.fixture
def fake_attempts(monkeypatch):
    fake = FakeAttempts()
    monkeypatch.setattr(marking, "connection", fake.connection)
    monkeypatch.setattr(marking, "query_one", fake.query_one)
    monkeypatch.setattr(marking, "execute", fake.execute)
    monkeypatch.setattr(marking, "get_int_setting", lambda k, d: 5)
    return fake


def reserve():
    return marking.reserve_attempt(student_id=7, day=date(2026, 9, 23), source_ip="1.2.3.4",
                                   device_id=1, challenge_spec="centre,turn,other", user_agent="t")


def test_parallel_requests_cannot_exceed_the_daily_budget(fake_attempts):
    results = []
    barrier = threading.Barrier(20)

    def worker():
        barrier.wait()
        try:
            results.append(("ok", reserve()))
        except marking.MarkError as exc:
            results.append(("refused", exc.code))

    threads = [threading.Thread(target=worker) for _ in range(20)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()

    granted = [r for r in results if r[0] == "ok"]
    # Never more than the budget. Under a pile-up like this one it may grant
    # fewer, even none — every racer sees the others' reservations — which is
    # the safe way to be wrong, and only a scripted flood produces it (the page
    # sends one request per single-use challenge).
    assert len(granted) <= 5, results
    assert all(r[1] == "TOO_MANY_ATTEMPTS" for r in results if r[0] == "refused")
    # Every refused reservation is labelled as such in the evidence table.
    assert sum(r["fail_code"] == "TOO_MANY_ATTEMPTS" for r in fake_attempts.rows) == 20 - len(granted)


def test_sequential_requests_get_exactly_the_budget(fake_attempts):
    granted = 0
    for _ in range(8):
        try:
            reserve()
            granted += 1
        except marking.MarkError:
            pass
    assert granted == 5


def test_server_errors_and_refusals_do_not_use_up_the_budget(fake_attempts):
    for _ in range(3):
        fake_attempts.rows.append({"id": 0, "student_id": 7, "verdict": "fail", "fail_code": "ENGINE_ERROR"})
        fake_attempts.rows.append({"id": 0, "student_id": 7, "verdict": "fail", "fail_code": "SERVER_ERROR"})
        fake_attempts.rows.append({"id": 0, "student_id": 7, "verdict": "fail", "fail_code": "TOO_MANY_ATTEMPTS"})
    granted = 0
    for _ in range(8):
        try:
            reserve()
            granted += 1
        except marking.MarkError:
            pass
    assert granted == 5


def test_pending_reviews_use_up_the_budget(fake_attempts):
    """A lookalike scoring in the review band must not get unlimited retries."""
    for _ in range(5):
        fake_attempts.rows.append({"id": 0, "student_id": 7, "verdict": "pending", "fail_code": "LOW_CONFIDENCE"})
    with pytest.raises(marking.MarkError):
        reserve()


# ---------------------------------------------------------------------------
# Face engine off the event loop
# ---------------------------------------------------------------------------

@pytest.fixture
def engine(monkeypatch):
    """A fresh 2-slot engine for each test (the pool binds to one loop)."""
    monkeypatch.setattr(faces, "_engine_pool", None)
    monkeypatch.setattr(faces, "_engine_slots", None)
    settings = faces.get_settings()
    monkeypatch.setattr(settings, "face_workers", 2)
    monkeypatch.setattr(settings, "face_queue_timeout", 0.3)
    yield
    if faces._engine_pool is not None:
        faces._engine_pool.shutdown(wait=True)


def test_blocking_face_work_does_not_stall_the_loop(engine):
    async def scenario():
        ticks = 0

        async def heartbeat():
            nonlocal ticks
            while True:
                await asyncio.sleep(0.01)
                ticks += 1

        hb = asyncio.create_task(heartbeat())
        async with faces.engine_slot():
            await faces.run_in_engine(time.sleep, 0.3)        # CPU-bound stand-in
        hb.cancel()
        return ticks

    # Had the work run inline, the heartbeat could not have ticked at all.
    assert asyncio.run(scenario()) >= 10


def test_concurrency_is_capped_and_the_queue_times_out(engine):
    async def scenario():
        running = peak = 0
        lock = threading.Lock()

        def job():
            nonlocal running, peak
            with lock:
                running += 1
                peak = max(peak, running)
            time.sleep(0.5)
            with lock:
                running -= 1

        async def attempt():
            try:
                async with faces.engine_slot():
                    await faces.run_in_engine(job)
                return "done"
            except faces.EngineBusy:
                return "busy"

        outcomes = await asyncio.gather(*(attempt() for _ in range(5)))
        return peak, outcomes

    peak, outcomes = asyncio.run(scenario())
    assert peak == 2                              # never more than face_workers at once
    assert outcomes.count("done") == 2            # the rest waited 0.3 s, then gave up
    assert outcomes.count("busy") == 3


# ---------------------------------------------------------------------------
# Connection pool
# ---------------------------------------------------------------------------

class FakeConn:
    created = 0
    lock = threading.Lock()

    def __init__(self):
        with FakeConn.lock:
            FakeConn.created += 1
        self.in_use = threading.Lock()
        self.open = True
        self.fail_ping = False

    def ping(self, reconnect=True):
        if self.fail_ping:
            raise db.pymysql.err.OperationalError(2006, "gone away")

    def rollback(self):
        pass

    def close(self):
        self.open = False


@pytest.fixture
def fake_pool(monkeypatch):
    import queue

    FakeConn.created = 0
    monkeypatch.setattr(db, "_new_conn", FakeConn)
    monkeypatch.setattr(db, "_pool", queue.LifoQueue(maxsize=db._POOL_MAX))
    return db


def test_pool_survives_many_threads_and_never_shares_a_connection(fake_pool):
    errors = []

    def worker():
        for _ in range(200):
            try:
                with db.connection() as conn:
                    # Two threads holding the same connection would collide here.
                    assert conn.in_use.acquire(blocking=False), "connection shared between threads"
                    conn.in_use.release()
            except Exception as exc:          # the old pop() race raised IndexError
                errors.append(exc)

    threads = [threading.Thread(target=worker) for _ in range(16)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert errors == []
    assert db._pool.qsize() <= db._POOL_MAX


def test_broken_connection_is_not_returned_to_the_pool(fake_pool):
    with pytest.raises(db.pymysql.err.OperationalError):
        with db.connection() as conn:
            raise db.pymysql.err.OperationalError(2013, "lost connection")
    assert not conn.open and db._pool.qsize() == 0

    with pytest.raises(db.pymysql.err.IntegrityError):
        with db.connection() as conn:
            raise db.pymysql.err.IntegrityError(1062, "Duplicate entry")
    assert conn.open and db._pool.qsize() == 1        # a duplicate key is not a broken socket


def test_stale_pooled_connection_is_replaced(fake_pool):
    with db.connection() as first:
        pass
    first.fail_ping = True
    with db.connection() as second:
        assert second is not first
    assert not first.open
