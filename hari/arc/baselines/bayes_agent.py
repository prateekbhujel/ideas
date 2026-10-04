import random
import numpy as np
from typing import Dict, Any, List, Optional, Tuple
from arcengine import GameAction

class BayesBaselineAgent:
    """Baseline 4: Naive Bayesian hypothesis learner.
    Maintains prior and likelihood distributions over action efficacy.
    Updates posterior when positive environmental progress or changes occur.
    """

    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        self.name = "Bayesian Hypothesis Learner"
        # Prior alpha, beta for each action
        self.action_priors: Dict[str, Tuple[float, float]] = {}
        self.last_action: Optional[GameAction] = None
        self.last_levels: int = 0
        self.last_grid: Optional[np.ndarray] = None

    def select_action(self, obs: Dict[str, Any], available_actions: List[GameAction]) -> Tuple[GameAction, Optional[Dict[str, Any]], str]:
        grid = obs["grid"]
        levels = obs["levels_completed"]

        # Update Bayesian priors from previous step outcome
        if self.last_action is not None:
            act_name = self.last_action.name
            alpha, beta = self.action_priors.get(act_name, (1.0, 1.0))
            if levels > self.last_levels:
                # Big success: level completed!
                alpha += 5.0
            elif self.last_grid is not None and not np.array_equal(grid, self.last_grid):
                # State changed: positive evidence
                alpha += 0.5
            else:
                # No change: negative evidence
                beta += 0.5
            self.action_priors[act_name] = (alpha, beta)

        self.last_levels = levels
        self.last_grid = grid.copy()

        simple_actions = [a for a in available_actions if a.is_simple()]
        if simple_actions:
            # Thompson sampling / Expected value from Beta distribution
            sampled_vals = []
            for a in simple_actions:
                alpha, beta = self.action_priors.get(a.name, (1.0, 1.0))
                # Beta mean + exploration bonus
                expected_val = alpha / (alpha + beta) + self.rng.gauss(0, 0.05)
                sampled_vals.append((expected_val, a))

            sampled_vals.sort(key=lambda x: x[0], reverse=True)
            chosen = sampled_vals[0][1]
            self.last_action = chosen
            alpha, beta = self.action_priors.get(chosen.name, (1.0, 1.0))
            return chosen, None, f"Bayesian posterior mean={alpha/(alpha+beta):.2f}"

        complex_actions = [a for a in available_actions if a.is_complex()]
        if complex_actions:
            non_zero = np.argwhere(grid > 0)
            if len(non_zero) > 0:
                pt = non_zero[self.rng.randint(0, len(non_zero) - 1)]
                y, x = int(pt[0]), int(pt[1])
                return complex_actions[0], {"x": x, "y": y}, f"Bayesian focus point ({x}, {y})"
            return complex_actions[0], {"x": 32, "y": 32}, "center click"

        return GameAction.ACTION1, None, "fallback"
