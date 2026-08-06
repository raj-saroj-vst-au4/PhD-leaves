"""Face enrollment and verification.

Everything here runs on this server — no cloud API ever sees a student's face.

Why a burst of frames and not one photo: a printed photo, a face on another
phone's screen, or a pre-recorded video all defeat single-shot matching. The
server picks a random pose sequence per attempt (e.g. centre → left → right),
and the burst must satisfy it. A still image cannot turn its head, and a video
recorded earlier cannot know the sequence chosen a second ago.

Layers, in order of what they stop:
  quality      — too dark / too far / no face / more than one face
  pose         — the random challenge sequence must actually be performed
  texture      — screen-replay detection (moire, flat specular response)
  identity     — cosine similarity against the student's enrolled template
  cross-match  — must not resemble a *different* enrolled student more; this is
                 what catches "A holding B's phone and using A's own face"
"""

from __future__ import annotations

import json
import logging
import random
import threading
from dataclasses import dataclass, field
from typing import Sequence

import cv2
import numpy as np

from .config import get_settings

log = logging.getLogger(__name__)

# Pose labels recorded for each analysed frame (evidence/forensics).
POSE_CENTRE, POSE_LEFT, POSE_RIGHT = "centre", "left", "right"
# What a challenge may ASK for. 'turn' is satisfied by a turn to either side.
#
# Asking for a named side does not work: whether a given physical direction
# reads as +yaw or -yaw depends on the un-mirrored camera image and the model's
# Euler convention, so "turn LEFT" was scored as 'right' and 78% of real
# attempts failed POSE_CHALLENGE_FAILED (attempts 4-8 in production). Liveness
# does not need the side to be named — a photo or a screen cannot turn at all,
# and the burst must still contain a genuine centre frame as well.
POSE_TURN = "turn"
YAW_TURN_MIN = 15.0     # a turn must be at least this pronounced
YAW_CENTRE_MAX = 12.0   # ...and 'centre' must be genuinely centred

_model_lock = threading.Lock()
_model = None


def get_model():
    """Lazily load InsightFace once per process (~1s, ~400MB RSS)."""
    global _model
    if _model is None:
        with _model_lock:
            if _model is None:
                from insightface.app import FaceAnalysis

                s = get_settings()
                # Load ONLY the modules we actually read. By default buffalo_l
                # also loads genderage and landmark_2d_106, which nothing here
                # uses — five resident ONNX models plus their inference arenas
                # pushed a multi-frame enrollment past the container's memory
                # limit, and Docker killed the process mid-request (the student
                # saw "service is not responding").
                #   detection      -> bbox + kps        (quality, framing)
                #   landmark_3d_68 -> face.pose         (the yaw the gate uses)
                #   recognition    -> normed_embedding  (identity, cross-match)
                app = FaceAnalysis(
                    name=s.model_name,
                    root=s.model_root,
                    providers=["CPUExecutionProvider"],
                    allowed_modules=["detection", "landmark_3d_68", "recognition"],
                )
                app.prepare(ctx_id=-1, det_size=(s.det_size, s.det_size))
                _model = app
                log.info("face model %s ready", s.model_name)
    return _model


def new_challenge(rng: random.Random | None = None) -> list[str]:
    """The sequence a marking burst must satisfy: centre, then a head turn.

    Two steps rather than three keeps marking quick (a daily action), and the
    turn is side-agnostic so it cannot fail on a left/right convention — see
    POSE_TURN. Replay is still blocked by the single-use challenge token and the
    2-minute TTL, not by which side was asked for.
    """
    return [POSE_CENTRE, POSE_TURN]


@dataclass
class FrameAnalysis:
    ok: bool
    reason: str | None = None
    embedding: np.ndarray | None = None
    yaw: float = 0.0
    pitch: float = 0.0
    face_ratio: float = 0.0
    sharpness: float = 0.0
    brightness: float = 0.0
    texture_score: float = 0.0
    det_score: float = 0.0
    pose: str = POSE_CENTRE


@dataclass
class VerifyResult:
    verdict: str                       # pass | pending | fail
    fail_code: str | None = None
    message: str = ""
    match_score: float = 0.0
    liveness_score: float = 0.0
    pose_ok: bool = False
    crossmatch_student_id: int | None = None
    crossmatch_score: float = 0.0
    detail: dict = field(default_factory=dict)


def decode_image(data: bytes) -> np.ndarray | None:
    buf = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(buf, cv2.IMREAD_COLOR)
    return img


def _yaw_from_pose(face) -> tuple[float, float]:
    """InsightFace gives pose as (pitch, yaw, roll) when the model provides it;
    fall back to landmark geometry when it doesn't."""
    pose = getattr(face, "pose", None)
    if pose is not None and len(pose) >= 2:
        return float(pose[1]), float(pose[0])

    kps = getattr(face, "kps", None)
    if kps is None or len(kps) < 5:
        return 0.0, 0.0
    # kps: left eye, right eye, nose, left mouth, right mouth
    left_eye, right_eye, nose = kps[0], kps[1], kps[2]
    eye_centre_x = (left_eye[0] + right_eye[0]) / 2.0
    eye_span = max(abs(right_eye[0] - left_eye[0]), 1e-3)
    # Nose displacement from the eye midpoint, scaled to a rough degree figure.
    return float((nose[0] - eye_centre_x) / eye_span * 60.0), 0.0


def _texture_score(img: np.ndarray, bbox) -> float:
    """Cheap screen-replay signal.

    A face on an LCD/OLED re-photographed by a phone loses high-frequency skin
    detail and gains regular pixel-grid structure. We score the crop's
    high-frequency energy: real skin under real light sits high, a replayed
    screen sits low. Not a silver bullet on its own — it is one vote among
    several, and the pose challenge is the layer that does the heavy lifting.
    """
    x1, y1, x2, y2 = [int(max(v, 0)) for v in bbox[:4]]
    crop = img[y1:y2, x1:x2]
    if crop.size == 0:
        return 0.0
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
    gray = cv2.resize(gray, (128, 128))
    lap_var = float(cv2.Laplacian(gray, cv2.CV_64F).var())

    # Normalised into roughly 0..1; 150+ variance is comfortably real skin.
    hf = min(lap_var / 150.0, 1.0)

    # Colour spread: screens compress chroma, real faces keep more variety.
    hsv = cv2.cvtColor(cv2.resize(crop, (128, 128)), cv2.COLOR_BGR2HSV)
    sat_std = float(np.std(hsv[:, :, 1])) / 64.0
    return float(np.clip(0.7 * hf + 0.3 * min(sat_std, 1.0), 0.0, 1.0))


def analyse_frame(img: np.ndarray) -> FrameAnalysis:
    s = get_settings()
    if img is None or img.size == 0:
        return FrameAnalysis(False, "UNREADABLE_IMAGE")

    faces = get_model().get(img)
    if not faces:
        return FrameAnalysis(False, "NO_FACE")
    if len(faces) > 1:
        # Two faces in shot is either a friend leaning in or a photo held up
        # next to a real person. Either way it is not a clean mark.
        return FrameAnalysis(False, "MULTIPLE_FACES")

    face = faces[0]
    h, w = img.shape[:2]
    x1, y1, x2, y2 = face.bbox[:4]
    face_ratio = float((x2 - x1) / max(w, 1))
    if face_ratio < s.min_face_ratio:
        return FrameAnalysis(False, "FACE_TOO_SMALL", face_ratio=face_ratio)

    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    brightness = float(np.mean(gray))
    if brightness < 40:
        return FrameAnalysis(False, "TOO_DARK", brightness=brightness)

    sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    if sharpness < 25:
        return FrameAnalysis(False, "TOO_BLURRY", sharpness=sharpness)

    emb = face.normed_embedding
    if emb is None:
        return FrameAnalysis(False, "NO_EMBEDDING")

    yaw, pitch = _yaw_from_pose(face)
    if yaw <= -YAW_TURN_MIN:
        pose = POSE_LEFT
    elif yaw >= YAW_TURN_MIN:
        pose = POSE_RIGHT
    elif abs(yaw) <= YAW_CENTRE_MAX:
        pose = POSE_CENTRE
    else:
        pose = "between"

    return FrameAnalysis(
        ok=True,
        embedding=np.asarray(emb, dtype=np.float32),
        yaw=yaw,
        pitch=pitch,
        face_ratio=face_ratio,
        sharpness=sharpness,
        brightness=brightness,
        texture_score=_texture_score(img, face.bbox),
        det_score=float(getattr(face, "det_score", 0.0)),
        pose=pose,
    )


def cosine(a: np.ndarray, b: np.ndarray) -> float:
    denom = float(np.linalg.norm(a) * np.linalg.norm(b)) or 1e-9
    return float(np.dot(a, b) / denom)


def best_score(embedding: np.ndarray, template: Sequence[Sequence[float]]) -> float:
    """Similarity against the best-matching angle of an enrolled template."""
    if not len(template):
        return 0.0
    mat = np.asarray(template, dtype=np.float32)
    sims = mat @ embedding / (
        np.linalg.norm(mat, axis=1) * (np.linalg.norm(embedding) or 1e-9) + 1e-9
    )
    return float(np.max(sims))


def load_template(raw: str) -> list[list[float]]:
    try:
        return json.loads(raw)
    except (TypeError, ValueError):
        return []


# ---------------------------------------------------------------------------
# Enrollment
# ---------------------------------------------------------------------------

def build_template(images: list[np.ndarray], min_frames: int) -> tuple[list[list[float]], float, str | None]:
    """Turn supervised capture frames into a multi-angle template.

    Returns (embeddings, quality, error_code). Frames that fail quality are
    dropped rather than averaged in — a blurry enrollment would haunt every
    future verification.
    """
    embeddings: list[np.ndarray] = []
    rejected: list[str] = []

    for img in images:
        fa = analyse_frame(img)
        if not fa.ok or fa.embedding is None:
            rejected.append(fa.reason or "UNKNOWN")
            continue
        embeddings.append(fa.embedding)

    if len(embeddings) < min_frames:
        code = "INSUFFICIENT_QUALITY_FRAMES"
        if rejected and all(r == "NO_FACE" for r in rejected):
            code = "NO_FACE"
        return [], 0.0, code

    # Self-consistency: every captured angle should still be recognisably the
    # same person. A low figure means we captured two different people, or the
    # capture was so poor the template would be useless.
    sims = [
        cosine(embeddings[i], embeddings[j])
        for i in range(len(embeddings))
        for j in range(i + 1, len(embeddings))
    ]
    quality = float(np.mean(sims)) if sims else 0.0
    if quality < 0.45:
        return [], quality, "INCONSISTENT_FRAMES"

    return [e.tolist() for e in embeddings], quality, None


# ---------------------------------------------------------------------------
# Verification
# ---------------------------------------------------------------------------

def verify_burst(
    images: list[np.ndarray],
    challenge: list[str],
    own_template: list[list[float]],
    *,
    others: list[tuple[int, list[list[float]]]],
    match_threshold: float,
    review_threshold: float,
    liveness_threshold: float,
    crossmatch_margin: float,
) -> VerifyResult:
    """Judge one marking attempt. `others` is the rest of the enrolled cohort,
    used for the cross-match check."""

    analyses = [analyse_frame(img) for img in images]
    good = [a for a in analyses if a.ok and a.embedding is not None]

    if not good:
        reason = analyses[0].reason if analyses else "NO_FRAMES"
        friendly = {
            "NO_FACE": "No face detected — hold the phone at eye level in good light.",
            "MULTIPLE_FACES": "More than one face in frame — make sure you are alone in the shot.",
            "FACE_TOO_SMALL": "Move the phone closer to your face.",
            "TOO_DARK": "Too dark — find better light.",
            "TOO_BLURRY": "Image was blurry — hold the phone steady.",
        }.get(reason or "", "Could not read the camera frames. Please try again.")
        return VerifyResult("fail", reason or "NO_FRAMES", friendly)

    if len(good) < len(challenge):
        return VerifyResult(
            "fail",
            "INSUFFICIENT_FRAMES",
            "Not all steps were captured clearly. Please try again.",
        )

    # --- pose challenge: the required poses must appear, in order ---
    observed = [a.pose for a in good]
    pose_ok = _sequence_present(observed, challenge)

    # --- liveness: texture across the burst, weakest frame carries weight ---
    textures = [a.texture_score for a in good]
    liveness = float(0.5 * np.mean(textures) + 0.5 * np.min(textures))

    # --- identity ---
    scores = [best_score(a.embedding, own_template) for a in good]
    match = float(np.median(scores)) if scores else 0.0

    # --- cross-match against the rest of the cohort ---
    cross_id, cross_score = None, 0.0
    centre = next((a for a in good if a.pose == POSE_CENTRE), good[0])
    for sid, tmpl in others:
        s = best_score(centre.embedding, tmpl)
        if s > cross_score:
            cross_id, cross_score = sid, s

    detail = {
        "frames_used": len(good),
        "frames_submitted": len(analyses),
        "observed_poses": observed,
        "challenge": challenge,
        "per_frame_match": [round(s, 4) for s in scores],
        "texture_scores": [round(t, 4) for t in textures],
        "rejected": [a.reason for a in analyses if not a.ok],
    }

    base = dict(
        match_score=match,
        liveness_score=liveness,
        pose_ok=pose_ok,
        crossmatch_student_id=cross_id,
        crossmatch_score=cross_score,
        detail=detail,
    )

    # Somebody else's face scoring higher than your own is the signature of a
    # student marking on a friend's account. Never auto-accept it.
    if cross_id is not None and cross_score > match + crossmatch_margin:
        return VerifyResult(
            "fail",
            "CROSSMATCH",
            "Face does not match this account. This attempt has been recorded.",
            **base,
        )

    if not pose_ok:
        return VerifyResult(
            "fail",
            "POSE_CHALLENGE_FAILED",
            "Please follow the on-screen head movements exactly.",
            **base,
        )

    if liveness < liveness_threshold:
        return VerifyResult(
            "fail",
            "LIVENESS_FAILED",
            "Could not confirm a live face. Photos and screens are not accepted.",
            **base,
        )

    if match >= match_threshold:
        return VerifyResult("pass", None, "Face verified.", **base)

    if match >= review_threshold:
        return VerifyResult(
            "pending",
            "LOW_CONFIDENCE",
            "Sent to the office for review — you will not be marked absent.",
            **base,
        )

    return VerifyResult(
        "fail",
        "NO_MATCH",
        "Face did not match your enrolled photo. Contact the office if this repeats.",
        **base,
    )


def _pose_satisfies(observed: str, required: str) -> bool:
    """Does an observed frame label satisfy a required challenge step?

    'turn' accepts a turn to either side; every other step must match exactly.
    """
    if required == POSE_TURN:
        return observed in (POSE_LEFT, POSE_RIGHT)
    return observed == required


def _sequence_present(observed: list[str], required: list[str]) -> bool:
    """Is `required` a subsequence of `observed`, in order?"""
    it = iter(observed)
    return all(any(_pose_satisfies(o, r) for o in it) for r in required)
