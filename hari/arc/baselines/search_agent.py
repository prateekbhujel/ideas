import random
import numpy as np
from typing import Dict, Any, List, Optional, Tuple
from arcengine import GameAction

class SearchBaselineAgent:
    """Baseline 3: Heuristic state-space search without learning.
    Uses frontier exploration and change-seeking heuristics without learning persistent rules.
    """

    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        self.name = "Heuristic Search (No Learning)"
        self.recent_actions: List[GameAction] = []
        self.last_grid: Optional[np.ndarray] = None

    def select_action(self, obs: Dict[str, Any], available_actions: List[GameAction]) -> Tuple[GameAction, Optional[Dict[str, Any]], str]:
        grid = obs["grid"]
        simple_actions = [a for a in available_actions if a.is_simple()]

        # Heuristic 1: Avoid immediately reversing last action if it produced no change
        if self.last_grid is not None and np.array_equal(grid, self.last_grid):
            # Previous action had no effect (hit wall or invalid) -> penalize it
            if self.recent_actions and self.recent_actions[-1] in simple_actions and len(simple_actions) > 1:
                candidates = [a for a in simple_actions if a != self.recent_actions[-1]]
                chosen = self.rng.choice(candidates)
                self.recent_actions.append(chosen)
                self.last_grid = grid.copy()
                return chosen, None, "wall deflection: avoid ineffective action"

        self.last_grid = grid.copy()

        # Heuristic 2: Cycle directions to explore 2D perimeter
        if simple_actions:
            # Try to pick action least used in last 4 steps
            counts = {a.name: self.recent_actions[-4:].count(a) for a in simple_actions}
            min_count = min(counts.values())
            best_candidates = [a for a in simple_actions if counts[a.name] == min_count]
            chosen = self.rng.choice(best_candidates)
            self.recent_actions.append(chosen)
            if len(self.recent_actions) > 32:
                self.recent_actions.pop(0)
            return chosen, None, f"momentum search (used {min_count} times recently)"

        complex_actions = [a for a in available_actions if a.is_complex()]
        if complex_actions:
            # Click on non-background pixels
            non_zero = np.argwhere(grid > 0)
            if len(non_zero) > 0:
                pt = non_zero[self.rng.randint(0, len(non_zero) - 1)]
                y, x = int(pt[0]), int(pt[1])
                return complex_actions[0], {"x": x, "y": y}, f"salient click at ({x}, {y})"
            return complex_actions[0], {"x": 32, "y": 32}, "center click"

        return GameAction.ACTION1, None, "fallback"
