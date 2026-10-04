import os
import time
import random
import resource
import numpy as np
from typing import Dict, Any, List, Optional, Tuple, Set
from collections import deque
from arcengine import GameAction, GameState

class EmpiricalKinematics:
    """Discovers action kinematics, step size, and interaction actions empirically."""

    def __init__(self):
        # Action name -> (dr, dc)
        self.displacements: Dict[str, Tuple[int, int]] = {}
        # Simple actions that do not produce translations (dr=0, dc=0)
        self.interaction_actions: Set[str] = set()
        # Native spatial discretization
        self.step_size: int = 1
        # Action tested counts
        self.action_counts: Dict[str, int] = {}
        # Locked status once calibrated
        self.is_calibrated: bool = False

    def update_from_transition(
        self,
        action: GameAction,
        grid_prev: np.ndarray,
        grid_curr: np.ndarray,
        bg_col: int
    ) -> Tuple[int, Tuple[int, int], Optional[Set[int]], int]:
        """Returns: (num_changed, (dr, dc), observed_sprite_colors, sprite_size)"""
        act_name = action.name
        self.action_counts[act_name] = self.action_counts.get(act_name, 0) + 1

        # Diff mask excluding bottom UI rows (>= 59)
        diff_mask = (grid_prev != grid_curr)
        h, w = diff_mask.shape
        diff_mask[max(0, h - 5):, :] = False
        num_changed = int(np.sum(diff_mask))

        if num_changed == 0:
            return 0, (0, 0), None, 0

        # Detect local vacated floor color (most frequent in new frame within diff region)
        vals, counts = np.unique(grid_curr[diff_mask], return_counts=True)
        local_floor = int(vals[np.argmax(counts)])

        # Check vacated background vs appeared (try local floor first, fallback to bg_col)
        vacated = diff_mask & ((grid_curr == local_floor) | (grid_curr == bg_col))
        appeared = diff_mask & ((grid_prev == local_floor) | (grid_prev == bg_col))

        if np.sum(vacated) > 0 and np.sum(appeared) > 0:
            old_pts = np.argwhere(vacated)
            new_pts = np.argwhere(appeared)
            dr = int(round(new_pts.mean(axis=0)[0] - old_pts.mean(axis=0)[0]))
            dc = int(round(new_pts.mean(axis=0)[1] - old_pts.mean(axis=0)[1]))
            if dr != 0 or dc != 0:
                self.displacements[act_name] = (dr, dc)
                mag = max(abs(dr), abs(dc))
                if mag > 0:
                    self.step_size = mag
                sprite_cols = set(int(c) for c in np.unique(grid_prev[vacated]))
                sprite_sz = int(np.sum(vacated))
                return num_changed, (dr, dc), sprite_cols, sprite_sz

        # If simple action and no translation, mark as interaction action ONLY if state actually changed
        if action.is_simple() and act_name not in self.displacements and num_changed > 0:
            self.interaction_actions.add(act_name)

        return num_changed, (0, 0), None, 0


class GeneralAutonomousLearner:
    """Game-agnostic autonomous learner for ARC-AGI-3.
    Zero prior assumptions: discovers kinematics, avatar, hazards, goals,
    affordances, and plans multi-step collision-free paths with global backtracking.
    """

    def __init__(self, seed: int = 42):
        self.rng = random.Random(seed)
        self.name = "HARI General Autonomous Learner"

        # Kinematics & Ego
        self.kinematics = EmpiricalKinematics()
        self.avatar_palette: Set[int] = set()
        self.avatar_pixel_count: int = 0
        self.avatar_locked: bool = False
        self.avatar_pos: Optional[Tuple[int, int]] = None

        # World Model & Spatial Knowledge
        self.obstacle_colors: Set[int] = set()
        self.hazard_colors: Set[int] = set()
        self.goal_colors: Set[int] = set()
        self.visited_nodes: Set[Tuple[int, int]] = set()
        self.visit_counts: Dict[Tuple[int, int], int] = {}
        self.blocked_transitions: Set[Tuple[Tuple[int, int], str]] = set()

        # Affordances & Interactivity
        self.satisfied_affordances: Set[Tuple[int, int]] = set()
        self.interacted_affordances: Set[Tuple[int, int]] = set()
        self.affordance_attempts: Dict[Tuple[int, int], int] = {}
        self.pending_interaction: Optional[Tuple[Tuple[int, int], str]] = None # ((ar, ac), phase)

        # Multi-Step Plan Queue
        self.planned_action_queue: List[Tuple[GameAction, Optional[Dict[str, Any]], str]] = []

        # Complex Action (Click) State
        self.tried_clicks: Set[Tuple[int, int]] = set()
        self.effectful_clicks: Set[Tuple[int, int]] = set()

        # Transient State
        self.last_grid: Optional[np.ndarray] = None
        self.last_action: Optional[GameAction] = None
        self.last_pos: Optional[Tuple[int, int]] = None
        self.last_levels: int = 0
        self.calibration_phase: bool = True
        self.step_counter: int = 0
        self.last_why: str = "initial calibration"
        self.last_chosen_action: str = "none"
        self.last_latency_ms: float = 0.0

    def reset_for_new_game(self):
        """Full reset when switching to a completely new game environment."""
        self.kinematics = EmpiricalKinematics()
        self.avatar_palette.clear()
        self.avatar_pixel_count = 0
        self.avatar_locked = False
        self.avatar_pos = None
        self.obstacle_colors.clear()
        self.hazard_colors.clear()
        self.goal_colors.clear()
        self.visited_nodes.clear()
        self.visit_counts.clear()
        self.blocked_transitions.clear()
        self.satisfied_affordances.clear()
        self.interacted_affordances.clear()
        self.affordance_attempts.clear()
        self.pending_interaction = None
        self.planned_action_queue.clear()
        self.tried_clicks.clear()
        self.effectful_clicks.clear()
        self.last_grid = None
        self.last_action = None
        self.last_pos = None
        self.last_levels = 0
        self.calibration_phase = True
        self.step_counter = 0

    def on_episode_reset(self):
        """Preserves learned kinematics, avatar palette, and hazard colors across deaths/resets."""
        self.visited_nodes.clear()
        self.visit_counts.clear()
        self.planned_action_queue.clear()
        self.pending_interaction = None
        self.last_grid = None
        self.last_action = None
        self.last_pos = None

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

        # Background color = most frequent color in playable area (< 59)
        vals, counts = np.unique(grid[:59], return_counts=True)
        bg_col = int(vals[np.argmax(counts)])

        simple_actions = [a for a in available_actions if a.is_simple()]
        complex_actions = [a for a in available_actions if a.is_complex()]

        # 1. Update models from transition outcome
        if self.last_grid is not None and self.last_action is not None:
            num_changed, shift, sprite_cols, sprite_sz = self.kinematics.update_from_transition(
                self.last_action, self.last_grid, grid, bg_col
            )

            # Check if pending interaction produced an effect
            if self.pending_interaction is not None:
                aff_coord, phase = self.pending_interaction
                if num_changed > 0:
                    self.satisfied_affordances.add(aff_coord)
                    self.interacted_affordances.add(aff_coord)
                    self.affordance_attempts.clear()
                else:
                    self.affordance_attempts[aff_coord] = self.affordance_attempts.get(aff_coord, 0) + 1
                self.pending_interaction = None

            # Calibration of avatar palette
            if shift != (0, 0) and sprite_cols and not self.avatar_locked:
                self.avatar_palette = set(sprite_cols)
                self.avatar_pixel_count = sprite_sz

            # Wall / Obstacle detection for ANY action that resulted in zero change
            if num_changed == 0:
                if self.last_pos is not None:
                    self.blocked_transitions.add((self.last_pos, self.last_action.name))
                    self.planned_action_queue.clear()
                    if self.last_action.name in self.kinematics.displacements:
                        dr, dc = self.kinematics.displacements[self.last_action.name]
                        nr, nc = self.last_pos[0] + dr, self.last_pos[1] + dc
                        if 0 <= nr < 59 and 0 <= nc < 64:
                            wall_col = int(grid[nr, nc])
                            if wall_col != bg_col and wall_col not in self.avatar_palette:
                                self.obstacle_colors.add(wall_col)

            # Hazard / Goal learning from game events
            if levels > self.last_levels:
                # Level completed! Mark contacted colors as goals
                if self.last_pos is not None:
                    for dr in [-1, 0, 1]:
                        for dc in [-1, 0, 1]:
                            r, c = self.last_pos[0] + dr, self.last_pos[1] + dc
                            if 0 <= r < 59 and 0 <= c < 64 and grid[r, c] != bg_col:
                                self.goal_colors.add(int(grid[r, c]))
                self.on_episode_reset()
            elif state == GameState.GAME_OVER:
                # Fatal collision! Mark contacted color as hazard
                if self.last_pos is not None:
                    for dr in [-1, 0, 1]:
                        for dc in [-1, 0, 1]:
                            r, c = self.last_pos[0] + dr, self.last_pos[1] + dc
                            if 0 <= r < 59 and 0 <= c < 64 and grid[r, c] != bg_col:
                                self.hazard_colors.add(int(grid[r, c]))
                self.on_episode_reset()

        self.last_levels = levels
        self.last_grid = grid.copy()

        # 2. Locate Avatar using localized connected components
        if self.avatar_palette:
            mask = np.isin(grid[:59], list(self.avatar_palette))
            pts = np.argwhere(mask)
            if len(pts) > 0:
                k = self.kinematics.step_size
                if self.avatar_pos is not None:
                    # Filter points in proximity to previous position
                    near = [p for p in pts if abs(p[0] - self.avatar_pos[0]) <= k * 2 and abs(p[1] - self.avatar_pos[1]) <= k * 2]
                    if len(near) >= max(1, self.avatar_pixel_count // 3):
                        pts = np.array(near)

                cur_r = int(round(pts[:, 0].mean()))
                cur_c = int(round(pts[:, 1].mean()))
                self.avatar_pos = (cur_r, cur_c)
                self.visited_nodes.add(self.avatar_pos)
                self.visit_counts[self.avatar_pos] = self.visit_counts.get(self.avatar_pos, 0) + 1

        self.last_pos = self.avatar_pos

        # 3. Mode A: Pure Click Environments (Only ACTION6)
        if complex_actions and not simple_actions:
            act = complex_actions[0]
            non_bg = np.argwhere(grid[:59] != bg_col)
            valid = [tuple(p) for p in non_bg if tuple(p) not in self.tried_clicks]
            if valid:
                target = self.rng.choice(valid)
            else:
                target = (self.rng.randint(4, 55), self.rng.randint(4, 55))
            self.tried_clicks.add(target)
            why = f"empirical click probe at ({target[1]}, {target[0]})"
            self._record_decision(act, {"x": int(target[1]), "y": int(target[0])}, why, t0)
            return act, {"x": int(target[1]), "y": int(target[0])}, why

        # 4. Mode B: Planned Action Queue Execution
        if self.planned_action_queue:
            next_act, next_data, next_why = self.planned_action_queue.pop(0)
            self._record_decision(next_act, next_data, next_why, t0)
            return next_act, next_data, next_why

        # 5. Mode C: Adaptive Calibration Phase
        # An action is uncalibrated if it has neither displacement nor confirmed interaction
        uncalibrated = [
            a for a in simple_actions
            if a.name not in self.kinematics.displacements and a.name not in self.kinematics.interaction_actions
        ]
        if uncalibrated:
            untested = [a for a in uncalibrated if self.kinematics.action_counts.get(a.name, 0) == 0]
            if untested:
                chosen = untested[0]
                why = f"calibration: initial probe of {chosen.name}"
                self._record_decision(chosen, None, why, t0)
                return chosen, None, why

            can_probe = [
                a for a in uncalibrated
                if not (self.avatar_pos and (self.avatar_pos, a.name) in self.blocked_transitions)
                and self.kinematics.action_counts.get(a.name, 0) < 3
            ]
            if can_probe:
                chosen = min(can_probe, key=lambda a: self.kinematics.action_counts.get(a.name, 0))
                why = f"calibration: discover kinematics of {chosen.name} in open space"
                self._record_decision(chosen, None, why, t0)
                return chosen, None, why
        else:
            self.calibration_phase = False
            self.avatar_locked = True
            self.kinematics.is_calibrated = True

        # 6. Mode D: Model-Based Goal / Affordance / Frontier Planning
        if self.avatar_pos is not None and self.kinematics.displacements:
            k = self.kinematics.step_size
            name_to_act = {a.name: a for a in simple_actions}
            moves = [(self.kinematics.displacements[an], name_to_act[an]) for an in self.kinematics.displacements if an in name_to_act]
            interacts = [a for a in simple_actions if a.name not in self.kinematics.displacements]

            # Extract candidate affordances
            affordances = self._extract_affordances(grid, bg_col, k)

            # Prioritize goal colors if known
            if self.goal_colors:
                goals = [a for a in affordances if a[2] in self.goal_colors]
                if goals:
                    affordances = goals

            # Sort affordances by distance
            affordances.sort(key=lambda a: abs(self.avatar_pos[0] - a[0]) + abs(self.avatar_pos[1] - a[1]))

            # Plan path to closest reachable affordance
            for ar, ac, col, sz in affordances:
                manhattan = abs(self.avatar_pos[0] - ar) + abs(self.avatar_pos[1] - ac)

                # If orthogonally adjacent: trigger bump or interaction action
                if manhattan <= k:
                    # Check if directional bump into affordance is possible
                    dr = np.sign(ar - self.avatar_pos[0]) * k
                    dc = np.sign(ac - self.avatar_pos[1]) * k
                    bump_act = None
                    for (mdr, mdc), mact in moves:
                        if (mdr, mdc) == (dr, dc) and ((self.avatar_pos, mact.name) not in self.blocked_transitions):
                            bump_act = mact
                            break

                    attempts = self.affordance_attempts.get((ar, ac), 0)
                    if bump_act and attempts == 0:
                        # Try bump first
                        self.pending_interaction = ((ar, ac), "bump")
                        why = f"bump into affordance at ({ar}, {ac}) with {bump_act.name}"
                        self._record_decision(bump_act, None, why, t0)
                        return bump_act, None, why
                    elif interacts:
                        # Try interaction action (ACTION5)
                        iact = interacts[0]
                        self.pending_interaction = ((ar, ac), "interact")
                        why = f"interact with affordance at ({ar}, {ac}) color {col} using {iact.name}"
                        self._record_decision(iact, None, why, t0)
                        return iact, None, why
                    else:
                        self.satisfied_affordances.add((ar, ac))

                # Plan BFS route to become orthogonally adjacent to affordance
                path = self._bfs_path_to_affordance(self.avatar_pos, (ar, ac), moves, k)
                if path is not None and len(path) > 0:
                    self.planned_action_queue = [(act, None, f"follow path to affordance ({ar}, {ac})") for act in path[1:]]
                    why = f"navigate to affordance at ({ar}, {ac}) [step 1/{len(path)}]"
                    self._record_decision(path[0], None, why, t0)
                    return path[0], None, why

            # If no affordance is reachable, backtrack & explore unvisited frontier!
            frontier_path = self._bfs_frontier_path(self.avatar_pos, moves, k)
            if frontier_path is not None and len(frontier_path) > 0:
                self.planned_action_queue = [(act, None, "frontier exploration") for act in frontier_path[1:]]
                why = f"explore unvisited frontier [step 1/{len(frontier_path)}]"
                self._record_decision(frontier_path[0], None, why, t0)
                return frontier_path[0], None, why

        # Fallback exploratory step avoiding known blocked transitions
        if simple_actions:
            unblocked = [a for a in simple_actions if not (self.avatar_pos and (self.avatar_pos, a.name) in self.blocked_transitions)]
            chosen = self.rng.choice(unblocked) if unblocked else self.rng.choice(simple_actions)
            why = "exploratory fallback"
            self._record_decision(chosen, None, why, t0)
            return chosen, None, why

        fallback_act = available_actions[0]
        self._record_decision(fallback_act, None, "default fallback", t0)
        return fallback_act, None, "default fallback"

    def _extract_affordances(self, grid: np.ndarray, bg_col: int, k: int) -> List[Tuple[int, int, int, int]]:
        """Segments non-background clusters into affordances (r, c, color, size)."""
        affordances = []
        non_bg = np.argwhere(grid[:59] != bg_col)
        seen = set()

        for p in non_bg:
            r, c = int(p[0]), int(p[1])
            if (r, c) in seen:
                continue
            col = int(grid[r, c])
            if col in self.avatar_palette or col in self.obstacle_colors or col in self.hazard_colors:
                continue

            cluster = [(r, c)]
            seen.add((r, c))
            q = [(r, c)]
            while q:
                qr, qc = q.pop()
                for dr in [-1, 0, 1]:
                    for dc in [-1, 0, 1]:
                        nr, nc = qr + dr, qc + dc
                        if 0 <= nr < 59 and 0 <= nc < 64 and (nr, nc) not in seen and grid[nr, nc] != bg_col:
                            seen.add((nr, nc))
                            cluster.append((nr, nc))
                            q.append((nr, nc))

            cr = int(round(np.mean([pt[0] for pt in cluster])))
            cc = int(round(np.mean([pt[1] for pt in cluster])))

            # Defer affordances already interacted with to prevent immediate 2-cycle toggle oscillations
            if any(abs(cr - ir) <= k and abs(cc - ic) <= k for ir, ic in self.interacted_affordances):
                continue

            # Only ignore if failed attempts >= 3 (preventing infinite loops on inert objects)
            if self.affordance_attempts.get((cr, cc), 0) < 3:
                affordances.append((cr, cc, col, len(cluster)))

        # If no affordances remain because all were deferred, reset deferrals
        if not affordances and self.interacted_affordances:
            self.interacted_affordances.clear()
            for p in non_bg:
                r, c = int(p[0]), int(p[1])
                col = int(grid[r, c])
                if col not in self.avatar_palette and col not in self.obstacle_colors and col not in self.hazard_colors:
                    affordances.append((r, c, col, 1))
            # Keep top clusters
            if affordances:
                return affordances[:8]

        return affordances

    def _bfs_path_to_affordance(
        self,
        start: Tuple[int, int],
        target: Tuple[int, int],
        moves: List[Tuple[Tuple[int, int], GameAction]],
        k: int
    ) -> Optional[List[GameAction]]:
        sr, sc = start
        tr, tc = target
        queue = deque([(sr, sc)])
        visited = { (sr, sc): None }
        found = None

        while queue:
            cr, cc = queue.popleft()
            # Goal is orthogonal adjacency
            if abs(cr - tr) + abs(cc - tc) <= k:
                found = (cr, cc)
                break

            for (dr, dc), act in moves:
                if ((cr, cc), act.name) in self.blocked_transitions:
                    continue
                nr, nc = cr + dr, cc + dc
                if 0 <= nr < 59 and 0 <= nc < 64:
                    if (nr, nc) not in visited:
                        visited[(nr, nc)] = ((cr, cc), act)
                        queue.append((nr, nc))

        if not found:
            return None

        # Backtrack path
        path = []
        curr = found
        while curr != (sr, sc):
            parent, act = visited[curr]
            path.append(act)
            curr = parent
        path.reverse()
        return path

    def _bfs_frontier_path(
        self,
        start: Tuple[int, int],
        moves: List[Tuple[Tuple[int, int], GameAction]],
        k: int
    ) -> Optional[List[GameAction]]:
        sr, sc = start
        queue = deque([(sr, sc)])
        visited = { (sr, sc): None }
        target_node = None

        while queue:
            cr, cc = queue.popleft()
            if (cr, cc) not in self.visited_nodes and (cr, cc) != (sr, sc):
                target_node = (cr, cc)
                break

            # Shuffle moves slightly to avoid systematic directional bias
            m_list = list(moves)
            self.rng.shuffle(m_list)
            for (dr, dc), act in m_list:
                if ((cr, cc), act.name) in self.blocked_transitions:
                    continue
                nr, nc = cr + dr, cc + dc
                if 0 <= nr < 59 and 0 <= nc < 64:
                    if (nr, nc) not in visited:
                        visited[(nr, nc)] = ((cr, cc), act)
                        queue.append((nr, nc))

        if not target_node:
            return None

        path = []
        curr = target_node
        while curr != (sr, sc):
            parent, act = visited[curr]
            path.append(act)
            curr = parent
        path.reverse()
        return path

    def _record_decision(self, act: GameAction, data: Optional[Dict[str, Any]], why: str, t0: float):
        self.last_action = act
        self.last_why = why
        self.last_chosen_action = f"{act.name} {data if data else ''}".strip()
        self.last_latency_ms = (time.perf_counter() - t0) * 1000.0

    def get_status_diagnostics(self) -> Dict[str, Any]:
        ram_mb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / (1024.0 * 1024.0 if os.uname().sysname == "Darwin" else 1024.0)
        hypotheses = []
        if self.kinematics.displacements:
            hypotheses.append(f"Grid step size: {self.kinematics.step_size} pixels ({len(self.kinematics.displacements)} directional vectors)")
        if self.kinematics.interaction_actions:
            hypotheses.append(f"Interactive actions: {list(self.kinematics.interaction_actions)}")
        for g in self.goal_colors:
            hypotheses.append(f"Goal: Contact with color {g} advances level")
        for h in self.hazard_colors:
            hypotheses.append(f"Hazard: Collision with color {h} is fatal")
        if self.obstacle_colors:
            hypotheses.append(f"Obstacles: Static walls detected at colors {list(self.obstacle_colors)}")

        return {
            "step": self.step_counter,
            "step_size": self.kinematics.step_size,
            "displacements": self.kinematics.displacements,
            "interactions": list(self.kinematics.interaction_actions),
            "avatar_pos": self.avatar_pos,
            "avatar_palette": list(self.avatar_palette),
            "avatar_colors": list(self.avatar_palette),
            "avatar_conf": 1.0 if self.avatar_locked else (0.5 if self.avatar_palette else 0.0),
            "obstacles": list(self.obstacle_colors),
            "hazards": list(self.hazard_colors),
            "goals": list(self.goal_colors),
            "hypotheses": hypotheses,
            "chosen_action": self.last_chosen_action,
            "why": self.last_why,
            "latency_ms": round(self.last_latency_ms, 2),
            "memory_mb": round(ram_mb, 1)
        }
