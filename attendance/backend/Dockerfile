# Attendance backend: FastAPI + InsightFace (CPU), talks to host MariaDB/MySQL.
FROM python:3.12-slim

# opencv-headless needs libgl-less stack; insightface builds a small C extension.
RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential g++ libglib2.0-0 curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv/app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Bake the face model into the image so first startup needs no internet.
# (~280 MB; downloads from the insightface release bucket at build time.)
RUN python -c "from insightface.app import FaceAnalysis; \
    FaceAnalysis(name='buffalo_l', root='/models', providers=['CPUExecutionProvider'])" \
    || echo "WARNING: model download failed at build; mount /models at runtime instead"

COPY app ./app

# Give the service user a real HOME so InsightFace's matplotlib import can write
# its cache (no MPLCONFIGDIR juggling, no cold-start warning).
RUN useradd -r -u 1001 -m -d /home/attendance attendance \
    && mkdir -p /data/frames /data/reports \
    && chown -R attendance:attendance /data /models /home/attendance 2>/dev/null || true
USER attendance

EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8000/api/healthz || exit 1

CMD ["python", "-m", "uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2"]
