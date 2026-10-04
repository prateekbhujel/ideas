import numpy as np
from typing import Dict, Any, List, Tuple, Optional
from collections import deque

class DiffTracker:
    """Tracks pixel-level and object-level temporal variations between frames."""

    @staticmethod
    def extract_change_clusters(grid_prev: np.ndarray, grid_curr: np.ndarray, ignore_ui_bottom: int = 4) -> List[List[Tuple[int, int]]]:
        """Clusters changed pixels into connected components, ignoring bottom UI counters."""
        h, w = grid_prev.shape
        diff_mask = (grid_prev != grid_curr)

        # Ignore bottom UI rows if present
        if ignore_ui_bottom > 0:
            diff_mask[h - ignore_ui_bottom:, :] = False

        visited = np.zeros((h, w), dtype=bool)
        clusters = []

        for r in range(h):
            for c in range(w):
                if diff_mask[r, c] and not visited[r, c]:
                    cluster = []
                    q = deque([(r, c)])
                    visited[r, c] = True

                    while q:
                        cr, cc = q.popleft()
                        cluster.append((cr, cc))
                        for dr in [-1, 0, 1]:
                            for dc in [-1, 0, 1]:
                                nr, nc = cr + dr, cc + dc
                                if 0 <= nr < h and 0 <= nc < w and diff_mask[nr, nc] and not visited[nr, nc]:
                                    visited[nr, nc] = True
                                    q.append((nr, nc))

                    clusters.append(cluster)

        # Sort clusters by size descending
        clusters.sort(key=len, reverse=True)
        return clusters

    @classmethod
    def compute_motion_shift(
        cls,
        grid_prev: np.ndarray,
        grid_curr: np.ndarray
    ) -> Tuple[Tuple[int, int], Optional[List[int]], Optional[Tuple[int, int, int, int]]]:
        """Computes true spatial displacement vector and moving sprite characteristics."""
        clusters = cls.extract_change_clusters(grid_prev, grid_curr)
        if not clusters:
            return (0, 0), None, None

        # The primary moving agent is typically the largest change cluster
        main_cluster = clusters[0]
        if len(main_cluster) < 2:
            return (0, 0), None, None

        pts = np.array(main_cluster)
        min_r, max_r = int(pts[:, 0].min()), int(pts[:, 0].max())
        min_c, max_c = int(pts[:, 1].min()), int(pts[:, 1].max())
        bbox = (min_r, min_c, max_r, max_c)

        # Detect vacated floor color (most frequent color in curr frame inside vacated cluster)
        curr_vals = [grid_curr[r, c] for r, c in main_cluster]
        prev_vals = [grid_prev[r, c] for r, c in main_cluster]

        vals, counts = np.unique(curr_vals, return_counts=True)
        floor_color = int(vals[np.argmax(counts)])

        # Old positions vacated: were sprite in prev, now floor in curr
        old_pos = [p for p in main_cluster if grid_curr[p[0], p[1]] == floor_color and grid_prev[p[0], p[1]] != floor_color]
        # New positions occupied: were floor in prev, now sprite in curr
        new_pos = [p for p in main_cluster if grid_prev[p[0], p[1]] == floor_color and grid_curr[p[0], p[1]] != floor_color]

        if old_pos and new_pos:
            c0_r = sum(p[0] for p in old_pos) / len(old_pos)
            c0_c = sum(p[1] for p in old_pos) / len(old_pos)
            c1_r = sum(p[0] for p in new_pos) / len(new_pos)
            c1_c = sum(p[1] for p in new_pos) / len(new_pos)

            shift_dr = int(round(c1_r - c0_r))
            shift_dc = int(round(c1_c - c0_c))

            sprite_colors = list(np.unique([grid_curr[p[0], p[1]] for p in new_pos]))
            return (shift_dr, shift_dc), sprite_colors, bbox

        return (0, 0), None, bbox
