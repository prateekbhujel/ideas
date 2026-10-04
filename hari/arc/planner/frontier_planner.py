import numpy as np
from typing import Dict, Any, List, Tuple, Optional, Set
from collections import deque
from arcengine import GameAction

class FrontierPlanner:
    """A* / BFS model-based path planner on the 64x64 grid."""

    DIRECTION_TO_ACTION = {
        (-1, 0): GameAction.ACTION1,  # UP
        (1, 0): GameAction.ACTION2,   # DOWN
        (0, -1): GameAction.ACTION3,  # LEFT
        (0, 1): GameAction.ACTION4,   # RIGHT
    }

    ACTION_TO_DIRECTION = {
        GameAction.ACTION1: (-1, 0),
        GameAction.ACTION2: (1, 0),
        GameAction.ACTION3: (0, -1),
        GameAction.ACTION4: (0, 1),
    }

    @classmethod
    def plan_path_to_coord(
        cls,
        start_coord: Tuple[int, int],
        target_coord: Tuple[int, int],
        walkable_mask: np.ndarray,
        step_size: int = 1
    ) -> List[GameAction]:
        """Finds shortest obstacle-free action path from start to target."""
        sr, sc = start_coord
        tr, tc = target_coord
        h, w = walkable_mask.shape

        if (sr, sc) == (tr, tc):
            return []

        queue = deque([(sr, sc)])
        visited = { (sr, sc): None }  # coord -> (parent_coord, action)

        moves = [
            ((-step_size, 0), GameAction.ACTION1),
            ((step_size, 0), GameAction.ACTION2),
            ((0, -step_size), GameAction.ACTION3),
            ((0, step_size), GameAction.ACTION4),
        ]

        found = False
        while queue:
            curr_r, curr_c = queue.popleft()

            # Target reached
            if abs(curr_r - tr) < step_size and abs(curr_c - tc) < step_size:
                tr, tc = curr_r, curr_c
                found = True
                break

            for (dr, dc), action in moves:
                nr, nc = curr_r + dr, curr_c + dc
                if 0 <= nr < h and 0 <= nc < w:
                    if (nr, nc) not in visited and walkable_mask[nr, nc]:
                        visited[(nr, nc)] = ((curr_r, curr_c), action)
                        queue.append((nr, nc))

        if not found:
            return []

        # Reconstruct path
        path = []
        curr = (tr, tc)
        while curr != (sr, sc):
            parent_info = visited.get(curr)
            if not parent_info:
                break
            parent_coord, action = parent_info
            path.append(action)
            curr = parent_coord

        path.reverse()
        return path

    @classmethod
    def plan_frontier_exploration(
        cls,
        start_coord: Tuple[int, int],
        walkable_mask: np.ndarray,
        visited_mask: np.ndarray,
        step_size: int = 1
    ) -> Optional[GameAction]:
        """Plans a step towards the nearest unvisited walkable cell."""
        sr, sc = start_coord
        h, w = walkable_mask.shape

        queue = deque([(sr, sc)])
        visited_bfs = { (sr, sc): None }

        moves = [
            ((-step_size, 0), GameAction.ACTION1),
            ((step_size, 0), GameAction.ACTION2),
            ((0, -step_size), GameAction.ACTION3),
            ((0, step_size), GameAction.ACTION4),
        ]

        frontier_target = None
        while queue:
            curr_r, curr_c = queue.popleft()

            # If this cell has not been visited by the agent
            if not visited_mask[curr_r, curr_c] and (curr_r, curr_c) != (sr, sc):
                frontier_target = (curr_r, curr_c)
                break

            for (dr, dc), action in moves:
                nr, nc = curr_r + dr, curr_c + dc
                if 0 <= nr < h and 0 <= nc < w:
                    if (nr, nc) not in visited_bfs and walkable_mask[nr, nc]:
                        visited_bfs[(nr, nc)] = ((curr_r, curr_c), action)
                        queue.append((nr, nc))

        if not frontier_target:
            return None

        # Reconstruct first action
        curr = frontier_target
        first_action = None
        while curr != (sr, sc):
            parent_info = visited_bfs.get(curr)
            if not parent_info:
                break
            parent_coord, action = parent_info
            first_action = action
            curr = parent_coord

        return first_action
