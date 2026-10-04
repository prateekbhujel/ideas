import numpy as np
from typing import Dict, Any, List, Tuple, Set
from collections import deque

class Entity:
    """Represents a coherent visual object in the 64x64 grid."""
    def __init__(
        self,
        entity_id: int,
        color: int,
        pixels: List[Tuple[int, int]],
        bbox: Tuple[int, int, int, int]
    ):
        self.entity_id = entity_id
        self.color = color
        self.pixels = pixels
        self.bbox = bbox  # (min_r, min_c, max_r, max_c)
        self.min_r, self.min_c, self.max_r, self.max_c = bbox
        self.width = self.max_c - self.min_c + 1
        self.height = self.max_r - self.min_r + 1
        self.size = len(pixels)
        self.centroid = (
            sum(p[0] for p in pixels) / len(pixels),
            sum(p[1] for p in pixels) / len(pixels)
        )

    @property
    def center_coord(self) -> Tuple[int, int]:
        return (int(round(self.centroid[0])), int(round(self.centroid[1])))

    def distance_to(self, other: "Entity") -> float:
        return abs(self.centroid[0] - other.centroid[0]) + abs(self.centroid[1] - other.centroid[1])


class EntityTracker:
    """Extracts and tracks coherent visual entities from raw 64x64 grids."""

    def __init__(self):
        self.background_color: int = 0

    def analyze_frame(self, grid: np.ndarray) -> Tuple[int, List[Entity]]:
        """Extracts background color and connected component entities."""
        # Detect background color as most frequent pixel value
        vals, counts = np.unique(grid, return_counts=True)
        self.background_color = int(vals[np.argmax(counts)])

        h, w = grid.shape
        visited = np.zeros((h, w), dtype=bool)
        entities: List[Entity] = []
        entity_id = 0

        # Scan for non-background contiguous components
        for r in range(h):
            for c in range(w):
                color = int(grid[r, c])
                if color == self.background_color or visited[r, c]:
                    continue

                # BFS connected component
                pixels = []
                queue = deque([(r, c)])
                visited[r, c] = True
                min_r, max_r = r, r
                min_c, max_c = c, c

                while queue:
                    curr_r, curr_c = queue.popleft()
                    pixels.append((curr_r, curr_c))
                    min_r = min(min_r, curr_r)
                    max_r = max(max_r, curr_r)
                    min_c = min(min_c, curr_c)
                    max_c = max(max_c, curr_c)

                    # 4-neighbors
                    for dr, dc in [(-1, 0), (1, 0), (0, -1), (0, 1)]:
                        nr, nc = curr_r + dr, curr_c + dc
                        if 0 <= nr < h and 0 <= nc < w:
                            if not visited[nr, nc] and grid[nr, nc] == color:
                                visited[nr, nc] = True
                                queue.append((nr, nc))

                # Create Entity
                entity = Entity(
                    entity_id=entity_id,
                    color=color,
                    pixels=pixels,
                    bbox=(min_r, min_c, max_r, max_c)
                )
                entities.append(entity)
                entity_id += 1

        return self.background_color, entities
