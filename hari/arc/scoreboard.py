import os
import json
import time
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, Any, List, Optional

BENCHMARK_DIR = Path(__file__).resolve().parent.parent.parent / "benchmarks"
DEV_SCOREBOARD_FILE = BENCHMARK_DIR / "arc_dev_scoreboard.json"
CLEAN_SCOREBOARD_FILE = BENCHMARK_DIR / "arc_clean_scoreboard.json"

class Scoreboard:
    """Tracks machine-readable history of ARC-AGI-3 benchmark evaluations.
    Strictly partitions DEVELOPMENT (contaminated) vs CLEAN BLIND evaluations.
    """

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
    def _load_file(cls, path: Path) -> List[Dict[str, Any]]:
        if not path.exists():
            return []
        try:
            with open(path, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception:
            return []

    @classmethod
    def load_dev_history(cls) -> List[Dict[str, Any]]:
        return cls._load_file(DEV_SCOREBOARD_FILE)

    @classmethod
    def load_clean_history(cls) -> List[Dict[str, Any]]:
        return cls._load_file(CLEAN_SCOREBOARD_FILE)

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
        is_clean_blind: bool = True,
        notes: str = "",
        extra_metrics: Optional[Dict[str, Any]] = None
    ) -> Dict[str, Any]:
        BENCHMARK_DIR.mkdir(parents=True, exist_ok=True)
        target_file = CLEAN_SCOREBOARD_FILE if is_clean_blind else DEV_SCOREBOARD_FILE
        history = cls._load_file(target_file)

        entry = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "commit": cls.get_current_commit(),
            "algorithm": algorithm,
            "track": "CLEAN_BLIND" if is_clean_blind else "DEVELOPMENT_CONTAMINATED",
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

        with open(target_file, "w", encoding="utf-8") as f:
            json.dump(history, f, indent=2)

        return entry

    @classmethod
    def print_summary(cls):
        clean_hist = cls.load_clean_history()
        dev_hist = cls.load_dev_history()

        print("\n" + "=" * 84)
        print(" " * 22 + "OFFICIAL CLEAN BLIND SCOREBOARD (HELD-OUT)")
        print("  NOTE: ONLY clean blind scores count toward HARI research milestones.")
        print("=" * 84)
        print(f"{'Date':<18} | {'Algorithm':<22} | {'Score (%)':<10} | {'Levels':<8} | {'RAM (MB)':<8} | {'Latency':<8}")
        print("-" * 84)
        if not clean_hist:
            print("  [No clean blind evaluations recorded yet. Honest baseline: 0.00%]")
        else:
            for h in clean_hist[-8:]:
                date_str = h["timestamp"][:16].replace("T", " ")
                lvl_str = f"{h['levels_completed']}/{h['total_levels']}"
                lat_str = f"{h['step_latency_ms']:.1f}ms"
                print(f"{date_str:<18} | {h['algorithm']:<22} | {h['arc_score']:<10.2f} | {lvl_str:<8} | {h['ram_mb']:<8.1f} | {lat_str:<8}")
        print("=" * 84)

        print("\n" + "=" * 84)
        print(" " * 20 + "DEVELOPMENT SCOREBOARD (CONTAMINATED / DEBUG)")
        print("  NOTE: For perception, harness, and low-level mechanics validation only.")
        print("=" * 84)
        print(f"{'Date':<18} | {'Algorithm':<22} | {'Score (%)':<10} | {'Levels':<8} | {'RAM (MB)':<8} | {'Latency':<8}")
        print("-" * 84)
        if not dev_hist:
            print("  [No development evaluations recorded.]")
        else:
            for h in dev_hist[-8:]:
                date_str = h["timestamp"][:16].replace("T", " ")
                lvl_str = f"{h['levels_completed']}/{h['total_levels']}"
                lat_str = f"{h['step_latency_ms']:.1f}ms"
                print(f"{date_str:<18} | {h['algorithm']:<22} | {h['arc_score']:<10.2f} | {lvl_str:<8} | {h['ram_mb']:<8.1f} | {lat_str:<8}")
        print("=" * 84 + "\n")
