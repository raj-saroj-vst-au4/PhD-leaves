"""Shared test setup: make `app` importable without installing the package.

The face model (insightface) is never loaded by these tests — they exercise
the pure decision logic with synthetic FrameAnalysis objects and embeddings,
and stub the database at module level with monkeypatch.
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
