"""Upload hardening: header-only size probe, decompression-bomb refusal, the
request body cap, and the frame-count rule on /api/mark."""

import struct

import cv2
import numpy as np
import pytest
from fastapi.testclient import TestClient

from app import faces, main


def jpeg(w: int, h: int) -> bytes:
    img = np.full((h, w, 3), 128, dtype=np.uint8)
    ok, buf = cv2.imencode(".jpg", img)
    assert ok
    return buf.tobytes()


def png(w: int, h: int) -> bytes:
    ok, buf = cv2.imencode(".png", np.zeros((h, w, 3), dtype=np.uint8))
    assert ok
    return buf.tobytes()


def with_sof_size(data: bytes, w: int, h: int) -> bytes:
    """Rewrite the dimensions in a real JPEG's SOF header — the classic
    decompression bomb: a few KB on the wire, gigabytes once decoded."""
    buf = bytearray(data)
    i = 2
    while True:
        assert buf[i] == 0xFF
        marker = buf[i + 1]
        seglen = struct.unpack(">H", buf[i + 2:i + 4])[0]
        if marker in (0xC0, 0xC1, 0xC2):
            buf[i + 5:i + 7] = struct.pack(">H", h)
            buf[i + 7:i + 9] = struct.pack(">H", w)
            return bytes(buf)
        i += 2 + seglen


def test_probe_reads_real_headers():
    assert faces.probe_image_size(jpeg(720, 960)) == (720, 960)


def test_png_is_refused():
    """The page only ever sends canvas.toBlob('image/jpeg')."""
    assert faces.probe_image_size(png(64, 48)) is None
    assert faces.frame_problem(png(64, 48)) == "BAD_FRAME"


def progressive(w=128, h=96) -> bytes:
    img = np.full((h, w, 3), 128, dtype=np.uint8)
    ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_PROGRESSIVE, 1])
    assert ok and cv2.imdecode(buf, cv2.IMREAD_COLOR) is not None
    return buf.tobytes()


def test_normal_progressive_jpeg_is_tolerated():
    """An ordinary progressive file (~10 scans) costs little to decode; it is
    accepted in case a browser's encoder ever writes one."""
    data = progressive()
    assert 1 < data.count(b"\xff\xda") <= 16
    assert faces.frame_problem(data) is None


def test_progressive_scan_bomb_is_refused():
    """Repeating a refinement scan thousands of times is a CPU bomb (~40 s to
    decode 3 MB); the scan count is capped, so it never reaches the decoder."""
    data = progressive()
    first = data.index(b"\xff\xda")
    second = data.index(b"\xff\xda", first + 2)
    third = data.index(b"\xff\xda", second + 2)
    unit = data[second:third]                       # one whole scan
    bomb = data[:third] + unit * 1000 + data[third:]
    assert faces.frame_problem(bomb) == "BAD_FRAME"


def _segments(data: bytes):
    """(marker, start, end) for each header segment up to and including SOS."""
    out, i = [], 2
    while True:
        marker = data[i + 1]
        seglen = struct.unpack(">H", data[i + 2:i + 4])[0]
        out.append((marker, i, i + 2 + seglen))
        if marker == 0xDA:
            return out
        i += 2 + seglen


def test_second_scan_in_a_baseline_file_is_refused():
    data = jpeg(64, 64)
    sos = _segments(data)[-1]
    # Append a second scan (its header plus a little entropy data) before EOI.
    doubled = data[:-2] + data[sos[1]:sos[2]] + b"\x00" * 16 + b"\xff\xd9"
    assert faces.frame_problem(data) is None
    assert faces.frame_problem(doubled) == "BAD_FRAME"


def test_sof1_extended_sequential_is_accepted():
    data = bytearray(jpeg(64, 48))
    sof = next(s for s in _segments(bytes(data)) if s[0] == 0xC0)
    data[sof[1] + 1] = 0xC1
    assert faces.probe_image_size(bytes(data)) == (64, 48)


@pytest.mark.parametrize("name,data", [
    ("all fill bytes", b"\xff\xd8" + b"\xff" * (faces.MAX_FRAME_BYTES - 2)),
    ("many tiny APP segments", b"\xff\xd8" + b"\xff\xe0\x00\x02" * ((faces.MAX_FRAME_BYTES - 2) // 4)),
    ("many RST markers", b"\xff\xd8" + b"\xff\xd0" * ((faces.MAX_FRAME_BYTES - 2) // 2)),
])
def test_header_probe_is_bounded(name, data):
    """The probe runs on the event loop; a crafted 3 MB header must not turn
    it into a multi-second Python byte loop."""
    import time
    t = time.perf_counter()
    assert faces.frame_problem(data) == "BAD_FRAME"
    assert time.perf_counter() - t < 0.05, name


def test_phone_sized_frame_is_accepted_and_decodes():
    data = jpeg(720, 960)
    assert faces.frame_problem(data) is None
    img = faces.decode_image(data)
    assert img is not None and img.shape[:2] == (960, 720)


def test_decompression_bomb_refused_without_decoding(monkeypatch):
    bomb = with_sof_size(jpeg(64, 64), 65000, 65000)
    assert len(bomb) < 5000
    assert faces.probe_image_size(bomb) == (65000, 65000)
    assert faces.frame_problem(bomb) == "FRAME_TOO_LARGE"

    def boom(*a, **k):
        raise AssertionError("cv2.imdecode must not be reached for an oversized header")
    monkeypatch.setattr(faces.cv2, "imdecode", boom)
    assert faces.decode_image(bomb) is None


@pytest.mark.parametrize("w,h", [(faces.MAX_FRAME_SIDE + 1, 100), (100, faces.MAX_FRAME_SIDE + 1),
                                 (1920, 1920)])
def test_limits_on_each_side_and_on_area(w, h):
    assert faces.frame_problem(with_sof_size(jpeg(32, 32), w, h)) == "FRAME_TOO_LARGE"


@pytest.mark.parametrize("data", [
    b"",
    b"GIF89a....",
    b"\xff\xd8\xff\xe0\x00\x10JFIF",           # truncated before any frame header
    b"\xff\xd8\xff\xda\x00\x08" + b"\x00" * 8,  # scan before a frame header
    b"\xff\xd8\x00\x00garbage",                 # stray bytes where a marker belongs
])
def test_malformed_frames_are_refused(data):
    assert faces.frame_problem(data) in ("EMPTY_FRAME", "BAD_FRAME")
    assert faces.decode_image(data) is None


def test_oversized_bytes_refused():
    assert faces.frame_problem(b"\xff\xd8\xff" + b"\x00" * faces.MAX_FRAME_BYTES) == "FRAME_TOO_LARGE"


def test_opencv_pixel_ceiling_is_set_before_import():
    import os
    assert int(os.environ["OPENCV_IO_MAX_IMAGE_PIXELS"]) <= faces.MAX_FRAME_PIXELS


# ---------------------------------------------------------------------------
# HTTP layer
# ---------------------------------------------------------------------------

@pytest.fixture
def client():
    return TestClient(main.app, raise_server_exceptions=False)


def test_body_over_limit_refused_before_parsing(client, monkeypatch):
    # No auth needed: the cap applies before the route (and its ticket check).
    big = b"x" * (main.get_settings().max_body_bytes + 1)
    r = client.post("/api/mark", content=big,
                    headers={"content-type": "multipart/form-data; boundary=zz"})
    assert r.status_code == 413
    assert r.json()["code"] == "BODY_TOO_LARGE"


def test_streamed_body_over_limit_cut_off(monkeypatch):
    """No Content-Length (chunked): the counter trips mid-read."""
    app = main.BodySizeLimit(main.app, max_bytes=1000)
    sent = []

    async def receive_chunks():
        for _ in range(10):
            yield {"type": "http.request", "body": b"y" * 500, "more_body": True}
        yield {"type": "http.request", "body": b"", "more_body": False}

    import asyncio

    async def run():
        chunks = receive_chunks()

        async def receive():
            return await chunks.__anext__()

        async def send(message):
            sent.append(message)

        scope = {"type": "http", "method": "POST", "path": "/api/admin/report", "raw_path": b"/api/admin/report",
                 "query_string": b"", "root_path": "", "scheme": "http", "server": ("t", 80),
                 "client": ("127.0.0.1", 1), "http_version": "1.1",
                 "headers": [(b"content-type", b"application/x-www-form-urlencoded"),
                             (b"authorization", b"Bearer x.y")]}
        await app(scope, receive, send)

    asyncio.run(run())
    start = next(m for m in sent if m["type"] == "http.response.start")
    # FastAPI reads a form body before resolving the ticket dependency, so the
    # cap trips first — a clean 413, not a 500 and not a buffered body.
    assert start["status"] == 413


def test_mark_rejects_wrong_frame_count_before_face_work(client, monkeypatch):
    """A burst that does not have exactly frames_per_step per challenge step is
    refused up front — positions are what tie frames to steps."""
    from app.auth import Principal

    me = Principal(user_id=1, role="student", student_id=7, name="t", email="t@x")
    main.app.dependency_overrides[main.current_student] = lambda: me
    blocked = []
    monkeypatch.setattr(main, "_mark_gates", lambda *a, **k: (
        {"id": 3}, False, {"challenge": "centre,turn,other", "steps": ["centre", "turn", "other"]}))
    monkeypatch.setattr(main.marking, "log_blocked", lambda code, **k: blocked.append(code))

    def no_engine(*a, **k):
        raise AssertionError("face engine must not run")
    monkeypatch.setattr(main.faces, "run_in_engine", no_engine)
    monkeypatch.setattr(main.marking, "reserve_attempt", no_engine)
    try:
        files = [("frames", (f"f{i}.jpg", jpeg(64, 64), "image/jpeg")) for i in range(5)]
        r = client.post("/api/mark", data={"token": "t"}, files=files)
        assert r.status_code == 400 and r.json()["code"] == "FRAME_COUNT"

        bomb = with_sof_size(jpeg(64, 64), 60000, 60000)
        files = [("frames", (f"f{i}.jpg", bomb if i == 3 else jpeg(64, 64), "image/jpeg")) for i in range(6)]
        r = client.post("/api/mark", data={"token": "t"}, files=files)
        assert r.status_code == 400 and r.json()["code"] == "FRAME_TOO_LARGE"
        assert blocked == ["FRAME_COUNT", "FRAME_TOO_LARGE"]
    finally:
        main.app.dependency_overrides.clear()


# ---------------------------------------------------------------------------
# /api/jobs/* token
# ---------------------------------------------------------------------------

@pytest.mark.parametrize("path", ["/api/jobs/monthly-report", "/api/jobs/purge-frames", "/api/jobs/cleanup"])
def test_every_job_route_requires_the_token(client, monkeypatch, path):
    monkeypatch.setenv("JOB_TOKEN", "s3cret-token")
    assert client.post(path).status_code == 401
    assert client.post(path, headers={"authorization": "Bearer wrong"}).status_code == 401
    # Non-ASCII in the header used to raise TypeError inside compare_digest (500).
    r = client.post(path, headers={"authorization": "Bearer café".encode("latin-1")})
    assert r.status_code == 401 and r.json()["code"] == "BAD_JOB_TOKEN"


def test_unset_job_token_refuses_everything(client, monkeypatch):
    monkeypatch.delenv("JOB_TOKEN", raising=False)
    assert client.post("/api/jobs/cleanup", headers={"authorization": "Bearer "}).status_code == 401
