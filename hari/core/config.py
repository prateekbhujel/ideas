"""
HARI System Configuration and Resource Limits.
All substantial data, models, and caches MUST reside on the external SSD.
"""

from pathlib import Path
import os

# External SSD Paths
SSD_ROOT = Path("/Volumes/DEV-T7/Projects/hari")
SCRATCH_ROOT = Path("/Volumes/DEV-T7/Projects/hari-scratch")
DATA_DIR = SCRATCH_ROOT / "data"
MODELS_DIR = SCRATCH_ROOT / "models"
KOKORO_DIR = MODELS_DIR / "kokoro"
STATE_DIR = SCRATCH_ROOT / "state"

# Ensure SSD state and scratch dirs exist
STATE_DIR.mkdir(parents=True, exist_ok=True)
DATA_DIR.mkdir(parents=True, exist_ok=True)

# Kokoro Model Weights (Borrowed open-source neural acoustic component)
KOKORO_MODEL_PATH = KOKORO_DIR / "kokoro-v1.0.onnx"
KOKORO_VOICES_PATH = KOKORO_DIR / "voices-v1.0.bin"

# Server Configuration
SERVER_HOST = "127.0.0.1"
SERVER_PORT = 8765

# Hard Resource Limits (O(1) Memory Rule)
MAX_WORKING_MEMORY_TURNS = 32
MAX_EPISODIC_EVENTS = 512
MAX_SEMANTIC_TOKENS = 1024
MAX_SEMANTIC_FEATURES = 1024
MAX_PROCEDURAL_MACROS = 128
MAX_ACTIVE_HYPOTHESES = 16

# Epistemic Decision Margins
CONFIDENCE_THRESHOLD = 0.35
AMBIGUITY_MARGIN = 0.25

# Conversational Audio & Turn Timing
VAD_SILENCE_TIMEOUT_MS = 600   # ms of silence to declare user turn complete
BARGE_IN_TRIGGER_MS = 120       # ms of user speech to immediately cut off HARI voice
AUDIO_SAMPLE_RATE = 24000      # 24 kHz neural synthesis

# Risk Policies for Embodied Hands
RISK_READ_ONLY = "READ"
RISK_REVERSIBLE = "REVERSIBLE"
RISK_CONSEQUENTIAL = "CONSEQUENTIAL"
