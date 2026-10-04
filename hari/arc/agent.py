import os
import time
import random
import resource
import numpy as np
from typing import Dict, Any, List, Optional, Tuple, Set
from arcengine import GameAction, GameState

from hari.arc.perception.entity_tracker import EntityTracker, Entity
from hari.arc.perception.diff_tracker import DiffTracker
from hari.arc.perception.ego_detector import EgoDetector
from hari.arc.hypotheses.hypothesis_pool import HypothesisPool, CausalHypothesis
from hari.arc.planner.frontier_planner import FrontierPlanner

class HARIAgent:
    """HARI Autonomous ARC-AGI-3 Agent.
    Combines entity perception, ego-motion discovery, competing causal hypotheses,
    world model obstacle inference, and model-based frontier planning.
    """

    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        self.name = "HARI Autonomous Learner"

        # Perceptual & cognitive modules
        self.tracker = EntityTracker()
        self.ego_detector = EgoDetector()
        self.hypotheses = HypothesisPool()

        # World model state
        self.obstacle_colors: Set[int] = set()
        self.visited_grid = np.zeros((64, 64), dtype=bool)
        self.last_grid: Optional[np.ndarray] = None
        self.last_entities: List[Entity] = []
        self.last_action: Optional[GameAction] = None
        self.last_levels_completed: int = 0
        self.current_path: List[GameAction] = []

        # Click exploration state
        self.clicked_coords: Set[Tuple[int, int]] = set()

        # Metrics & diagnostics
        self.step_counter: int = 0
        self.last_why: str = "initial exploration"
        self.last_chosen_action: str = "none"
        self.last_latency_ms: float = 0.0

    def reset_for_new_game(self):
        """Resets agent state when starting a new environment."""
        self.ego_detector = EgoDetector()
        self.hypotheses = HypothesisPool()
        self.obstacle_colors.clear()
        self.visited_grid = np.zeros((64, 64), dtype=bool)
        self.last_grid = None
        self.last_entities = []
        self.last_action = None
        self.last_levels_completed = 0
        self.current_path = []
        self.clicked_coords.clear()
        self.step_counter = 0

    def select_action(
        self,
        obs: Dict[str, Any],
        available_actions: List[GameAction]
    ) -> Tuple[GameAction, Optional[Dict[str, Any]], str]:
        t0 = time.perf_counter()
        self.step_counter += 1

        grid = obs["grid"]
        levels = obs["levels_completed"]
        state = obs["state"]

        # 1. Perception: Entity extraction
        bg_color, entities = self.tracker.analyze_frame(grid)

        # 2. Update causal models from previous transition
        if self.last_grid is not None and self.last_action is not None:
            shift, sprite_colors, bbox = DiffTracker.compute_motion_shift(self.last_grid, grid)

            # Check if previous action was blocked (Hit wall/obstacle)
            if self.last_action in [GameAction.ACTION1, GameAction.ACTION2, GameAction.ACTION3, GameAction.ACTION4]:
                if shift == (0, 0):
                    # Check what color was in front of avatar
                    cur_avatar = self.ego_detector.avatar_pos
                    if cur_avatar is not None:
                        exp_dr, exp_dc = EgoDetector.EXPECTED_DIRECTIONS.get(self.last_action, (0, 0))
                        nr = cur_avatar[0] + exp_dr * self.ego_detector.step_size
                        nc = cur_avatar[1] + exp_dc * self.ego_detector.step_size
                        if 0 <= nr < 64 and 0 <= nc < 64:
                            front_col = int(grid[nr, nc])
                            if front_col != bg_color and front_col not in self.ego_detector.avatar_colors:
                                self.obstacle_colors.add(front_col)

            # Update ego avatar evidence
            self.ego_detector.update_with_motion(
                self.last_action,
                shift,
                sprite_colors,
                bbox
            )

            # Check level completion signal
            level_advanced = (levels > self.last_levels_completed)
            game_over = (state == GameState.GAME_OVER)
            if level_advanced or game_over:
                cur_avatar = self.ego_detector.avatar_pos
                if cur_avatar is not None:
                    for e in self.last_entities:
                        if e.color not in self.ego_detector.avatar_colors:
                            dist = abs(cur_avatar[0] - e.centroid[0]) + abs(cur_avatar[1] - e.centroid[1])
                            if dist <= max(4.0, self.ego_detector.step_size * 1.5):
                                self.hypotheses.on_contact_event(e.color, level_advanced, game_over)

        self.last_levels_completed = levels
        self.last_grid = grid.copy()
        self.last_entities = entities

        # 3. Locate Avatar
        avatar_pos = self.ego_detector.locate_avatar_in_grid(grid)
        if avatar_pos is not None:
            self.visited_grid[avatar_pos[0], avatar_pos[1]] = True

        # Generate candidate hypotheses for foreground colors
        fg_colors = list({e.color for e in entities if e.color != bg_color and e.color not in self.ego_detector.avatar_colors})
        self.hypotheses.generate_candidate_hypotheses(fg_colors)

        simple_actions = [a for a in available_actions if a.is_simple()]
        complex_actions = [a for a in available_actions if a.is_complex()]

        # Mode A: Click-based environment (ComplexAction)
        if complex_actions and (not simple_actions or len(simple_actions) == 0):
            target_coord = None
            # Find candidate entities
            for e in entities:
                center = e.center_coord
                if center not in self.clicked_coords:
                    target_coord = center
                    break

            if target_coord is None:
                non_zero = [tuple(p) for p in np.argwhere(grid != bg_color) if tuple(p) not in self.clicked_coords]
                if non_zero:
                    target_coord = self.rng.choice(non_zero)
                else:
                    target_coord = (self.rng.randint(0, 63), self.rng.randint(0, 63))

            self.clicked_coords.add(target_coord)
            why = f"interact with visual entity at ({target_coord[1]}, {target_coord[0]})"
            self.last_why = why
            self.last_chosen_action = f"ACTION6 ({target_coord[1]}, {target_coord[0]})"
            self.last_action = complex_actions[0]
            self.last_latency_ms = (time.perf_counter() - t0) * 1000.0
            return complex_actions[0], {"x": int(target_coord[1]), "y": int(target_coord[0])}, why

        # Mode B: Avatar exploration & Goal navigation (SimpleAction)
        if simple_actions:
            # If avatar not yet confirmed, run active directional probe
            if avatar_pos is None or self.ego_detector.confidence < 0.70:
                directional = [a for a in simple_actions if a in EgoDetector.EXPECTED_DIRECTIONS]
                chosen = self.rng.choice(directional) if directional else self.rng.choice(simple_actions)
                why = "active probe: infer controllable avatar"
                self.last_why = why
                self.last_chosen_action = chosen.name
                self.last_action = chosen
                self.last_latency_ms = (time.perf_counter() - t0) * 1000.0
                return chosen, None, why

            # Avatar is confirmed! Build walkable grid
            walkable_mask = np.ones((64, 64), dtype=bool)
            for obs_col in self.obstacle_colors:
                walkable_mask[grid == obs_col] = False

            # Find target entities that are not avatar and not obstacles
            candidate_targets = [
                e for e in entities
                if e.color not in self.ego_detector.avatar_colors
                and e.color not in self.obstacle_colors
                and e.color != bg_color
            ]

            # If top hypothesis exists, prioritize its color
            top_h = self.hypotheses.get_best_goal()
            if top_h and top_h.target_color is not None:
                h_candidates = [e for e in candidate_targets if e.color == top_h.target_color]
                if h_candidates:
                    candidate_targets = h_candidates

            if candidate_targets:
                candidate_targets.sort(
                    key=lambda e: abs(avatar_pos[0] - e.centroid[0]) + abs(avatar_pos[1] - e.centroid[1])
                )
                target = candidate_targets[0]

                path = FrontierPlanner.plan_path_to_coord(
                    avatar_pos,
                    target.center_coord,
                    walkable_mask,
                    step_size=self.ego_detector.step_size
                )
                if path and path[0] in simple_actions:
                    chosen = path[0]
                    why = f"plan path to candidate goal color={target.color} [step 1/{len(path)}]"
                    self.last_why = why
                    self.last_chosen_action = chosen.name
                    self.last_action = chosen
                    self.last_latency_ms = (time.perf_counter() - t0) * 1000.0
                    return chosen, None, why

            # Frontier exploration to discover new targets/paths
            frontier_action = FrontierPlanner.plan_frontier_exploration(
                avatar_pos,
                walkable_mask,
                self.visited_grid,
                step_size=self.ego_detector.step_size
            )
            if frontier_action and frontier_action in simple_actions:
                why = "model-based exploration: expand visited frontier"
                self.last_why = why
                self.last_chosen_action = frontier_action.name
                self.last_action = frontier_action
                self.last_latency_ms = (time.perf_counter() - t0) * 1000.0
                return frontier_action, None, why

            # Fallback directional move
            directional = [a for a in simple_actions if a in EgoDetector.EXPECTED_DIRECTIONS]
            chosen = self.rng.choice(directional) if directional else self.rng.choice(simple_actions)
            why = "exploratory walk"
            self.last_why = why
            self.last_chosen_action = chosen.name
            self.last_action = chosen
            self.last_latency_ms = (time.perf_counter() - t0) * 1000.0
            return chosen, None, why

        self.last_latency_ms = (time.perf_counter() - t0) * 1000.0
        return GameAction.ACTION1, None, "default fallback"

    def get_status_diagnostics(self) -> Dict[str, Any]:
        """Provides real-time state for live visualization & logging."""
        ram_mb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / (1024.0 * 1024.0 if os.uname().sysname == "Darwin" else 1024.0)
        top_h = self.hypotheses.get_ranked_hypotheses()
        h_desc = [f"{h.hid}: {h.description} (conf: {h.confidence:.2f})" for h in top_h[:3]]

        return {
            "experience_steps": self.step_counter,
            "avatar_colors": list(self.ego_detector.avatar_colors),
            "avatar_pos": self.ego_detector.avatar_pos,
            "avatar_conf": round(self.ego_detector.confidence, 2),
            "obstacles": list(self.obstacle_colors),
            "hypotheses": h_desc,
            "chosen_action": self.last_chosen_action,
            "why": self.last_why,
            "latency_ms": round(self.last_latency_ms, 2),
            "memory_mb": round(ram_mb, 1)
        }
