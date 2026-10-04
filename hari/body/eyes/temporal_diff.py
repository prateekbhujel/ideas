"""
HARI Temporal Difference Tracker.
Implements event-driven perceptual change tracking:
Delta S = (S_{t-1}, S_t)
Extracts:
- what appeared?
- what disappeared?
- what moved?
- what changed?
- what stayed stable?
"""

import time
from typing import Dict, List, Optional, Any, Set
from hari.core.protocol import TemporalDelta

class TemporalDiffTracker:
    def __init__(self):
        self.last_state: Optional[Dict[str, Any]] = None

    def compute_delta(self, current_state: Dict[str, Any]) -> TemporalDelta:
        now = time.time()
        if not self.last_state:
            self.last_state = current_state
            return TemporalDelta(
                timestamp=now,
                appeared=[],
                disappeared=[],
                moved=[],
                modified=[],
                stable=[]
            )

        appeared = []
        disappeared = []
        moved = []
        modified = []
        stable = []

        # 1. Compare active application
        prev_app = self.last_state.get("frontmost_app")
        curr_app = current_state.get("frontmost_app")
        if prev_app != curr_app:
            modified.append({
                "attribute": "frontmost_app",
                "from": prev_app,
                "to": curr_app
            })

        # 2. Compare open windows
        prev_wins = {w.get("title", ""): w for w in self.last_state.get("windows", []) if w.get("title")}
        curr_wins = {w.get("title", ""): w for w in current_state.get("windows", []) if w.get("title")}

        for title, win in curr_wins.items():
            if title not in prev_wins:
                appeared.append(f"window:{title}")
            else:
                p_win = prev_wins[title]
                if (win.get("x") != p_win.get("x")) or (win.get("y") != p_win.get("y")):
                    moved.append(f"window:{title}")
                else:
                    stable.append(f"window:{title}")

        for title in prev_wins:
            if title not in curr_wins:
                disappeared.append(f"window:{title}")

        # 3. Compare camera held objects
        prev_cam = {o.get("label"): o for o in self.last_state.get("camera_objects", []) if o.get("label")}
        curr_cam = {o.get("label"): o for o in current_state.get("camera_objects", []) if o.get("label")}

        for label in curr_cam:
            if label not in prev_cam:
                appeared.append(f"camera_object:{label}")
            else:
                stable.append(f"camera_object:{label}")

        for label in prev_cam:
            if label not in curr_cam:
                disappeared.append(f"camera_object:{label}")

        self.last_state = current_state

        return TemporalDelta(
            timestamp=now,
            appeared=appeared,
            disappeared=disappeared,
            moved=moved,
            modified=modified,
            stable=stable
        )
