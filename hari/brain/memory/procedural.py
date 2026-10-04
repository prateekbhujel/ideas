"""
HARI Procedural Memory.
Stores learned deterministic action sequences, macros, and UI interaction schemas.
Bounded by MAX_PROCEDURAL_MACROS.
"""

from typing import Dict, List, Optional, Any
from dataclasses import dataclass, field
import time
from hari.core.config import MAX_PROCEDURAL_MACROS

@dataclass
class ActionMacro:
    name: str
    description: str
    steps: List[Dict[str, Any]]
    success_count: int = 1
    failure_count: int = 0
    created_at: float = field(default_factory=time.time)

class ProceduralMemory:
    def __init__(self, capacity: int = MAX_PROCEDURAL_MACROS):
        self.capacity = capacity
        self.macros: Dict[str, ActionMacro] = {}
        self._init_core_procedures()

    def _init_core_procedures(self) -> None:
        """Seed foundational deterministic system procedures."""
        self.macros["open_app"] = ActionMacro(
            name="open_app",
            description="Launch or activate a macOS application",
            steps=[{"type": "OPEN_APP", "param_key": "app_name"}]
        )
        self.macros["move_window_left"] = ActionMacro(
            name="move_window_left",
            description="Move frontmost window to left half of screen",
            steps=[{"type": "MOVE_WINDOW", "position": "left"}]
        )
        self.macros["move_window_right"] = ActionMacro(
            name="move_window_right",
            description="Move frontmost window to right half of screen",
            steps=[{"type": "MOVE_WINDOW", "position": "right"}]
        )
        self.macros["type_text"] = ActionMacro(
            name="type_text",
            description="Type a sequence of keystrokes without pressing Enter",
            steps=[{"type": "TYPE", "param_key": "text"}]
        )

    def get_macro(self, name: str) -> Optional[ActionMacro]:
        return self.macros.get(name.lower().strip())

    def register_macro(self, name: str, description: str, steps: List[Dict[str, Any]]) -> None:
        self.macros[name.lower().strip()] = ActionMacro(
            name=name.lower().strip(),
            description=description,
            steps=steps
        )
        if len(self.macros) > self.capacity:
            oldest = min(self.macros.keys(), key=lambda k: self.macros[k].created_at)
            del self.macros[oldest]

    def record_outcome(self, name: str, success: bool) -> None:
        macro = self.get_macro(name)
        if macro:
            if success:
                macro.success_count += 1
            else:
                macro.failure_count += 1

    def list_macros(self) -> List[Dict[str, Any]]:
        return [
            {
                "name": m.name,
                "description": m.description,
                "success_rate": round(m.success_count / max(1, m.success_count + m.failure_count), 2),
                "steps_count": len(m.steps),
            }
            for m in self.macros.values()
        ]
