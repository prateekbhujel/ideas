"""
HARI Protocol & Data Structures.
Standardized message schemas between Senses, Brain, Body, Server, and UI.
"""

from dataclasses import dataclass, field, asdict
from typing import Dict, List, Optional, Any
import time

@dataclass
class Observation:
    kind: str            # 'screen', 'camera', 'system'
    timestamp: float
    raw_summary: str
    attributes: Dict[str, Any] = field(default_factory=dict)
    classification: str = "OBSERVATION"  # OBSERVATION, INFERENCE, HYPOTHESIS, KNOWN_FACT

@dataclass
class TemporalDelta:
    timestamp: float
    appeared: List[str] = field(default_factory=list)
    disappeared: List[str] = field(default_factory=list)
    moved: List[str] = field(default_factory=list)
    modified: List[Dict[str, Any]] = field(default_factory=list)
    stable: List[str] = field(default_factory=list)

@dataclass
class ActionPlan:
    action_type: str             # OPEN_APP, FOCUS_WINDOW, MOVE_WINDOW, RESIZE_WINDOW, TYPE, KEY, CLICK, MOVE_POINTER, ASK
    params: Dict[str, Any] = field(default_factory=dict)
    risk_level: str = "REVERSIBLE"  # READ, REVERSIBLE, CONSEQUENTIAL
    needs_confirmation: bool = False
    status: str = "PENDING"      # PENDING, CONFIRMED, EXECUTED, CANCELLED, FAILED
    reason: str = ""
    target_description: str = ""

@dataclass
class EpistemicEvaluation:
    action: str                  # 'ACT', 'ASK'
    confidence: float
    top_hypothesis: str
    runner_up_hypothesis: Optional[str]
    margin: float
    reason: str
    competing_hypotheses: List[Dict[str, Any]] = field(default_factory=list)
    provenance: Dict[str, Any] = field(default_factory=dict)

@dataclass
class DialogueTurn:
    speaker: str                 # 'user', 'hari'
    text: str
    timestamp: float
    interrupted: bool = False
    associated_action: Optional[str] = None

@dataclass
class MindStateSummary:
    timestamp: float
    status: str                  # 'ALIVE', 'LISTENING', 'THINKING', 'SPEAKING', 'IDLE'
    senses: Dict[str, bool]      # {'mic': True, 'camera': True, 'screen': True, 'hands': True}
    current_belief: Dict[str, Any]
    epistemic: Dict[str, Any]
    working_memory_count: int
    episodic_count: int
    semantic_tokens: int
    semantic_features: int
    resource_stats: Dict[str, Any]
    recent_plan: Optional[Dict[str, Any]] = None
    last_user_speech: str = ""
    last_hari_speech: str = ""
