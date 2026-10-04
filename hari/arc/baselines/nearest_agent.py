import random
import hashlib
import numpy as np
from typing import Dict, Any, List, Optional, Tuple
from arcengine import GameAction

class NearestBaselineAgent:
    """Baseline 2: Nearest-state / memorization policy.
    Maintains a hash table of visited state grids and action visit counts.
    Selects the least-frequently taken action from the nearest known state.
    """

    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        self.name = "Nearest State / Memorization"
        # Map state_hash -> {action_name: count}
        self.state_action_counts: Dict[str, Dict[str, int]] = {}

    def _hash_grid(self, grid: np.ndarray) -> str:
        return hashlib.sha256(grid.tobytes()).hexdigest()[:16]

    def select_action(self, obs: Dict[str, Any], available_actions: List[GameAction]) -> Tuple[GameAction, Optional[Dict[str, Any]], str]:
        grid = obs["grid"]
        state_key = self._hash_grid(grid)

        if state_key not in self.state_action_counts:
            self.state_action_counts[state_key] = {}

        action_counts = self.state_action_counts[state_key]

        # Prioritize simple actions with lowest visitation count
        simple_actions = [a for a in available_actions if a.is_simple()]
        if simple_actions:
            # Sort by count
            scored = [(action_counts.get(a.name, 0), self.rng.random(), a) for a in simple_actions]
            scored.sort(key=lambda x: (x[0], x[1]))
            chosen = scored[0][2]
            action_counts[chosen.name] = action_counts.get(chosen.name, 0) + 1
            return chosen, None, f"least-visited action (visited {scored[0][0]} times)"

        complex_actions = [a for a in available_actions if a.is_complex()]
        if complex_actions:
            chosen = self.rng.choice(complex_actions)
            x, y = self.rng.randint(0, 63), self.rng.randint(0, 63)
            return chosen, {"x": x, "y": y}, f"click ({x}, {y})"

        return GameAction.ACTION1, None, "fallback"
