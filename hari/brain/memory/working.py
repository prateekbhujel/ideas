"""
HARI Working Memory.
Holds active situational context, dialogue history, current attentional focus,
and discourse referents (e.g. 'this', 'that window', 'Terminal').
Bounded by hard capacity MAX_WORKING_MEMORY_TURNS.
"""

from typing import List, Dict, Optional, Any
import time
from hari.core.config import MAX_WORKING_MEMORY_TURNS
from hari.core.protocol import DialogueTurn

class WorkingMemory:
    def __init__(self, max_turns: int = MAX_WORKING_MEMORY_TURNS):
        self.max_turns = max_turns
        self.turns: List[DialogueTurn] = []
        self.active_focus: Optional[str] = None          # e.g. "Terminal", "red notebook", "window_0"
        self.active_referents: Dict[str, Any] = {}       # "this" -> target object, "that window" -> app
        self.current_goal: Optional[str] = None
        self.pending_confirmation: Optional[Dict[str, Any]] = None

    def add_turn(self, speaker: str, text: str, associated_action: Optional[str] = None) -> DialogueTurn:
        turn = DialogueTurn(
            speaker=speaker,
            text=text,
            timestamp=time.time(),
            interrupted=False,
            associated_action=associated_action
        )
        self.turns.append(turn)
        if len(self.turns) > self.max_turns:
            self.turns.pop(0)
        return turn

    def flag_interrupted(self) -> None:
        """Mark the last HARI turn as interrupted by the user."""
        for turn in reversed(self.turns):
            if turn.speaker == "hari":
                turn.interrupted = True
                break

    def set_focus(self, focus_name: str, referent_data: Optional[Dict[str, Any]] = None) -> None:
        self.active_focus = focus_name
        if referent_data:
            self.active_referents[focus_name] = referent_data
            self.active_referents["this"] = referent_data
            self.active_referents["it"] = referent_data

    def get_referent(self, term: str) -> Optional[Any]:
        term = term.lower().strip()
        if term in self.active_referents:
            return self.active_referents[term]
        if term in ("this", "it", "that") and self.active_focus:
            return self.active_referents.get(self.active_focus)
        return None

    def get_context_summary(self) -> Dict[str, Any]:
        return {
            "turn_count": len(self.turns),
            "recent_dialogue": [
                {"speaker": t.speaker, "text": t.text, "interrupted": t.interrupted}
                for t in self.turns[-6:]
            ],
            "active_focus": self.active_focus,
            "has_pending_confirmation": self.pending_confirmation is not None,
            "current_goal": self.current_goal,
        }
