import random
from typing import Dict, Any, List, Optional
from arcengine import GameAction

class RandomBaselineAgent:
    """Baseline 1: Uniform random action policy."""

    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        self.name = "Random Baseline"

    def select_action(self, obs: Dict[str, Any], available_actions: List[GameAction]) -> tuple[GameAction, Optional[Dict[str, Any]], str]:
        # Filter actions that can be taken
        simple_actions = [a for a in available_actions if a.is_simple()]
        complex_actions = [a for a in available_actions if a.is_complex()]

        # With 85% probability choose simple, or if only complex available choose complex
        if simple_actions and (not complex_actions or self.rng.random() < 0.85):
            chosen = self.rng.choice(simple_actions)
            return chosen, None, "random simple action"
        elif complex_actions:
            chosen = self.rng.choice(complex_actions)
            # Pick random coordinate in grid
            x = self.rng.randint(0, 63)
            y = self.rng.randint(0, 63)
            return chosen, {"x": x, "y": y}, f"random click at ({x}, {y})"
        else:
            return GameAction.ACTION1, None, "fallback ACTION1"
