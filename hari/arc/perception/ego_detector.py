import numpy as np
from typing import Dict, Any, List, Tuple, Optional, Set
from arcengine import GameAction
from hari.arc.perception.entity_tracker import Entity

class EgoDetector:
    """Hypothesis tester for controllable avatar / player entity."""

    EXPECTED_DIRECTIONS = {
        GameAction.ACTION1: (-1, 0),  # UP
        GameAction.ACTION2: (1, 0),   # DOWN
        GameAction.ACTION3: (0, -1),  # LEFT
        GameAction.ACTION4: (0, 1),   # RIGHT
    }

    def __init__(self):
        self.avatar_colors: Set[int] = set()
        self.avatar_pos: Optional[Tuple[int, int]] = None
        self.avatar_bbox: Optional[Tuple[int, int, int, int]] = None
        self.confidence: float = 0.0
        self.step_size: int = 1
        self.matches_count: int = 0

    def update_with_motion(
        self,
        action: GameAction,
        shift: Tuple[int, int],
        sprite_colors: Optional[List[int]],
        bbox: Optional[Tuple[int, int, int, int]]
    ):
        if action not in self.EXPECTED_DIRECTIONS:
            return

        exp_dr, exp_dc = self.EXPECTED_DIRECTIONS[action]
        shift_dr, shift_dc = shift

        # Check if displacement aligns with intended action direction
        r_aligned = (exp_dr == 0 and shift_dr == 0) or (exp_dr != 0 and shift_dr * exp_dr > 0)
        c_aligned = (exp_dc == 0 and shift_dc == 0) or (exp_dc != 0 and shift_dc * exp_dc > 0)

        if r_aligned and c_aligned and (shift_dr != 0 or shift_dc != 0):
            self.matches_count += 1
            mag = abs(shift_dr) if shift_dr != 0 else abs(shift_dc)
            self.step_size = max(1, mag)
            if sprite_colors:
                self.avatar_colors.update(sprite_colors)
            if bbox:
                self.avatar_bbox = bbox
                self.avatar_pos = ((bbox[0] + bbox[2]) // 2, (bbox[1] + bbox[3]) // 2)

            self.confidence = min(1.0, 0.4 + 0.3 * self.matches_count)

    def locate_avatar_in_grid(self, grid: np.ndarray) -> Optional[Tuple[int, int]]:
        """Locates avatar centroid in current frame using learned avatar palette."""
        if not self.avatar_colors:
            return None

        # Mask of pixels matching avatar colors
        mask = np.isin(grid, list(self.avatar_colors))
        coords = np.argwhere(mask)
        if len(coords) == 0:
            return None

        # Centroid of avatar pixels
        cr = int(round(coords[:, 0].mean()))
        cc = int(round(coords[:, 1].mean()))
        self.avatar_pos = (cr, cc)
        return self.avatar_pos
