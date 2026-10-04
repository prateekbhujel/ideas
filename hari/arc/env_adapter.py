import os
import time
import resource
import numpy as np
from typing import Dict, Any, List, Optional, Tuple
from arc_agi import Arcade, OperationMode, EnvironmentWrapper
from arcengine import GameAction, GameState, SimpleAction, ComplexAction

DEFAULT_ENV_DIR = "/Volumes/DEV-T7/Projects/hari-scratch/environments"
DEFAULT_REC_DIR = "/Volumes/DEV-T7/Projects/hari-scratch/recordings"

class ARCEnvironmentAdapter:
    """Standardized environment adapter for ARC-AGI-3 environments."""

    def __init__(
        self,
        game_id: str,
        scorecard_id: Optional[str] = None,
        env_dir: str = DEFAULT_ENV_DIR,
        rec_dir: str = DEFAULT_REC_DIR
    ):
        self.game_id = game_id
        self.scorecard_id = scorecard_id
        self.env_dir = env_dir
        self.rec_dir = rec_dir

        self.arcade = Arcade(
            operation_mode=OperationMode.OFFLINE,
            environments_dir=self.env_dir,
            recordings_dir=self.rec_dir
        )
        self.env: Optional[EnvironmentWrapper] = None
        self.last_grid: Optional[np.ndarray] = None
        self.last_obs: Any = None
        self.step_count: int = 0
        self.total_latency_sec: float = 0.0

    def reset(self) -> Dict[str, Any]:
        self.env = self.arcade.make(
            self.game_id,
            scorecard_id=self.scorecard_id
        )
        if not self.env:
            raise RuntimeError(f"Failed to initialize environment for {self.game_id}")

        self.last_obs = self.env.reset()
        self.step_count = 0
        self.total_latency_sec = 0.0
        return self._format_obs(self.last_obs)

    @property
    def action_space(self) -> List[GameAction]:
        if not self.env:
            return []
        return self.env.action_space

    def step(self, action: GameAction, data: Optional[Dict[str, Any]] = None, reasoning: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
        if not self.env:
            raise RuntimeError("Environment not initialized. Call reset() first.")

        t0 = time.perf_counter()

        if action.is_complex() and data:
            action.set_data(data)
            self.last_obs = self.env.step(action, data=data, reasoning=reasoning)
        else:
            self.last_obs = self.env.step(action, data=data, reasoning=reasoning)

        dt = time.perf_counter() - t0
        self.total_latency_sec += dt
        self.step_count += 1

        return self._format_obs(self.last_obs, latency_ms=dt * 1000.0)

    def _format_obs(self, raw_obs: Any, latency_ms: float = 0.0) -> Dict[str, Any]:
        grid = np.zeros((64, 64), dtype=np.int8)
        if raw_obs and hasattr(raw_obs, "frame") and raw_obs.frame:
            grid = np.array(raw_obs.frame[0], dtype=np.int8)
            self.last_grid = grid

        state = getattr(raw_obs, "state", GameState.NOT_FINISHED) if raw_obs else GameState.GAME_OVER
        levels_completed = getattr(raw_obs, "levels_completed", 0) if raw_obs else 0
        win_levels = getattr(raw_obs, "win_levels", 1) if raw_obs else 1
        available_actions = getattr(raw_obs, "available_actions", []) if raw_obs else []

        # Get current process RAM usage (in MB)
        ram_mb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / (1024.0 * 1024.0 if os.uname().sysname == "Darwin" else 1024.0)

        return {
            "grid": grid,
            "state": state,
            "levels_completed": levels_completed,
            "win_levels": win_levels,
            "available_actions": available_actions,
            "step_count": self.step_count,
            "latency_ms": latency_ms,
            "ram_mb": ram_mb,
            "is_terminal": state in [GameState.WIN, GameState.GAME_OVER]
        }
