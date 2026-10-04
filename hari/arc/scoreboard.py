import os
import json
import time
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, Any, List, Optional

SCOREBOARD_FILE = Path(__file__).resolve().parent.parent.parent / "benchmarks" / "arc_scoreboard.json"

class Scoreboard:
    """Tracks machine-readable history of ARC-AGI-3 benchmark evaluations."""

    @staticmethod
    def get_current_commit() -> str:
        try:
            return subprocess.check_output(
                ["git", "rev-parse", "HEAD"],
                cwd=str(Path(__file__).resolve().parent.parent.parent),
                stderr=subprocess.DEVNULL
            ).decode().strip()
        except Exception:
            return "unknown"

    @classmethod
    def load_history(cls) -> List[Dict[str, Any]]:
        if not SCOREBOARD_FILE.exists():
            return []
        try:
            with open(SCOREBOARD_FILE, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            return []

    @classmethod
    def record_run(
        cls,
        algorithm: str,
        arc_score: float,
        levels_completed: int,
        total_levels: int,
        actions: int,
        ram_mb: float,
        step_latency_ms: float,
        notes: str = "",
        extra_metrics: Optional[Dict[str, Any]] = None
    ) -> Dict[str, Any]:
        SCOREBOARD_FILE.parent.mkdir(parents=True, exist_ok=True)
        history = cls.load_history()

        entry = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "commit": cls.get_current_commit(),
            "algorithm": algorithm,
            "arc_score": round(float(arc_score), 4),
            "levels_completed": int(levels_completed),
            "total_levels": int(total_levels),
            "actions": int(actions),
            "ram_mb": round(float(ram_mb), 2),
            "step_latency_ms": round(float(step_latency_ms), 3),
            "notes": notes,
            "extra": extra_metrics or {}
        }
        history.append(entry)

        with open(SCOREBOARD_FILE, "w", encoding="utf-8") as f:
            json.dump(history, f, indent=2)

        return entry

    @classmethod
    def print_summary(cls):
        history = cls.load_history()
        print("\n" + "=" * 80)
        print(" " * 25 + "HARI ARC-AGI-3 SCOREBOARD")
        print("=" * 80)
        print(f"{'Date':<20} | {'Algorithm':<22} | {'Score (%)':<10} | {'Levels':<8} | {'RAM (MB)':<8} | {'Latency':<8}")
        print("-" * 80)
        for h in history[-10:]:
            date_str = h["timestamp"][:16].replace("T", " ")
            lvl_str = f"{h['levels_completed']}/{h['total_levels']}"
            lat_str = f"{h['step_latency_ms']:.1f}ms"
            print(f"{date_str:<20} | {h['algorithm']:<22} | {h['arc_score']:<10.2f} | {lvl_str:<8} | {h['ram_mb']:<8.1f} | {lat_str:<8}")
        print("=" * 80 + "\n")
