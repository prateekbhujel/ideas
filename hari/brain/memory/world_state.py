"""
HARI World State.
Current verified beliefs about the user's environment:
- Active frontmost macOS process
- Visible window layout and bounds
- Camera entities (objects shown to the camera)
- Screen perception summary
Strictly separates: OBSERVATION vs INFERENCE vs HYPOTHESIS vs KNOWN_FACT.
"""

from typing import Dict, List, Optional, Any
from dataclasses import dataclass, field, asdict
import time

@dataclass
class WorldBelief:
    key: str
    value: Any
    classification: str     # OBSERVATION, INFERENCE, HYPOTHESIS, KNOWN_FACT
    confidence: float
    source: str             # 'screen_api', 'camera_api', 'dialogue', 'deduction'
    timestamp: float = field(default_factory=time.time)

class WorldState:
    def __init__(self):
        self.beliefs: Dict[str, WorldBelief] = {}
        self.frontmost_app: Optional[str] = None
        self.open_windows: List[Dict[str, Any]] = []
        self.camera_objects: List[Dict[str, Any]] = []
        self.last_screen_update: float = 0.0
        self.last_camera_update: float = 0.0

    def update_screen_state(self, frontmost_app: str, windows: List[Dict[str, Any]]) -> None:
        self.frontmost_app = frontmost_app
        self.open_windows = windows
        self.last_screen_update = time.time()

        self.set_belief(
            key="active_application",
            value=frontmost_app,
            classification="OBSERVATION",
            confidence=1.0,
            source="macos_system_events"
        )
        self.set_belief(
            key="open_windows_count",
            value=len(windows),
            classification="OBSERVATION",
            confidence=1.0,
            source="macos_window_list"
        )

    def update_camera_state(self, detected_objects: List[Dict[str, Any]]) -> None:
        self.camera_objects = detected_objects
        self.last_camera_update = time.time()
        self.set_belief(
            key="held_objects",
            value=[obj.get("label", "unknown") for obj in detected_objects],
            classification="INFERENCE",
            confidence=0.85 if detected_objects else 0.0,
            source="camera_perception"
        )

    def set_belief(self, key: str, value: Any, classification: str, confidence: float, source: str) -> None:
        self.beliefs[key] = WorldBelief(
            key=key,
            value=value,
            classification=classification,
            confidence=confidence,
            source=source,
            timestamp=time.time()
        )

    def get_belief(self, key: str) -> Optional[WorldBelief]:
        return self.beliefs.get(key)

    def get_summary(self) -> Dict[str, Any]:
        return {
            "frontmost_app": self.frontmost_app,
            "window_count": len(self.open_windows),
            "windows": [
                {"app": w.get("app"), "title": w.get("title", "")[:40]}
                for w in self.open_windows[:5]
            ],
            "camera_objects": self.camera_objects,
            "active_beliefs": [
                {
                    "key": b.key,
                    "value": str(b.value)[:60],
                    "class": b.classification,
                    "conf": b.confidence,
                    "source": b.source
                }
                for b in self.beliefs.values()
            ],
            "screen_age_s": round(time.time() - self.last_screen_update, 1) if self.last_screen_update else None,
            "camera_age_s": round(time.time() - self.last_camera_update, 1) if self.last_camera_update else None,
        }
