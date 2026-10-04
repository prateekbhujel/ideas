import sys
import time
import argparse
import numpy as np
from typing import Dict, Any, List
from arcengine import GameAction, GameState

from hari.arc.agent import HARIAgent
from hari.arc.env_adapter import ARCEnvironmentAdapter, DEFAULT_ENV_DIR, DEFAULT_REC_DIR
from hari.arc.scoreboard import Scoreboard
from hari.arc.harness import BenchmarkHarness
from hari.arc.partition import is_contaminated, CLEAN_HOLDOUT_GAMES, CONTAMINATED_DEV_GAMES

COLOR_CHARS = {
    0: " ",
    1: "·",
    2: "■",
    3: "▲",
    4: "◆",
    5: "#",
    6: "✦",
    7: "★",
    8: "◉",
    9: "♦",
    10: " ",
    11: "o",
    12: "+",
    13: "*",
    14: "x",
    15: "@"
}

def render_ascii_grid(grid: np.ndarray, avatar_coord=None, max_rows=20, max_cols=40) -> str:
    h, w = grid.shape
    if avatar_coord and 0 <= avatar_coord[0] < h and 0 <= avatar_coord[1] < w:
        cr, cc = avatar_coord
        r_start = max(0, min(h - max_rows, cr - max_rows // 2))
        c_start = max(0, min(w - max_cols, cc - max_cols // 2))
    else:
        r_start = (h - max_rows) // 2
        c_start = (w - max_cols) // 2

    r_end = min(h, r_start + max_rows)
    c_end = min(w, c_start + max_cols)

    lines = ["+" + "-" * (c_end - c_start) + "+"]
    for r in range(r_start, r_end):
        row_str = ""
        for c in range(c_start, c_end):
            if avatar_coord and (r, c) == avatar_coord:
                row_str += "P"
            else:
                val = int(grid[r, c])
                row_str += COLOR_CHARS.get(val, "?")
        lines.append("|" + row_str + "|")
    lines.append("+" + "-" * (c_end - c_start) + "+")
    return "\n".join(lines)


def run_live(game_id: str = "wa30", max_steps: int = 150, delay_sec: float = 0.05):
    track_tag = "[DEV / CONTAMINATED]" if is_contaminated(game_id) else "[CLEAN BLIND HOLDOUT]"
    print(f"\n[HARI ARC-AGI-3] Initializing {track_tag} environment '{game_id}'...")
    agent = HARIAgent()
    adapter = ARCEnvironmentAdapter(game_id=game_id)
    obs = adapter.reset()
    agent.reset_for_new_game()

    for step in range(1, max_steps + 1):
        if obs["is_terminal"]:
            break

        actions = adapter.action_space
        action, data, why = agent.select_action(obs, actions)
        diag = agent.get_status_diagnostics()

        avatar_coord = diag.get("avatar_pos")

        print("\033[H\033[J", end="")
        print("=" * 64)
        print("                 HARI ARC-AGI-3 LEARNER")
        print(f" Track: {track_tag}")
        print("=" * 64)
        print(f"Environment:  {game_id.upper()}")
        print(f"Experience:   {step} steps")
        status_str = "WIN" if obs["state"] == GameState.WIN else ("OVER" if obs["state"] == GameState.GAME_OVER else "NOT_FINISHED")
        print(f"Status:       {status_str} | Levels: {obs['levels_completed']} / {obs['win_levels']}")
        print("\nObservation (Spatial Grid Window):")
        print(render_ascii_grid(obs["grid"], avatar_coord=avatar_coord, max_rows=16, max_cols=36))

        print("\nCurrent Hypotheses:")
        if diag["hypotheses"]:
            for h in diag["hypotheses"]:
                print(f"  • {h}")
        else:
            print("  (Formulating causal hypotheses...)")

        print("\nAvatar Perception:")
        if diag.get("avatar_colors"):
            print(f"  Colors: {diag['avatar_colors']} (Conf: {diag['avatar_conf'] * 100:.0f}%) | Pos: {avatar_coord}")
        else:
            print("  Avatar: Unconfirmed (Active probing...)")

        print(f"\nChosen Action:  {action.name} {data if data else ''}")
        print(f"Why:            {why}")
        print(f"Memory:         {diag['memory_mb']:.1f} MB")
        print(f"Step Latency:   {diag['latency_ms']:.2f} ms")
        print("=" * 64)

        obs = adapter.step(action, data=data, reasoning={"why": why})

        if delay_sec > 0:
            time.sleep(delay_sec)

    print(f"\n[HARI ARC-AGI-3] Run finished. Final State: {obs['state']}, Levels: {obs['levels_completed']}/{obs['win_levels']}\n")


def main():
    parser = argparse.ArgumentParser(description="HARI ARC-AGI-3 Runner")
    parser.add_argument("game_id", nargs="?", default="wa30", help="ARC-AGI-3 Game ID (defaults to clean holdout wa30)")
    parser.add_argument("--scoreboard", action="store_true", help="Display scoreboard history")
    parser.add_argument("--clean", action="store_true", help="Run full CLEAN BLIND benchmark on held-out environments")
    parser.add_argument("--dev", action="store_true", help="Run development benchmark on contaminated environments")
    parser.add_argument("--steps", type=int, default=100, help="Max steps per environment")
    parser.add_argument("--fast", action="store_true", help="Run without UI sleep delay")
    args = parser.parse_args()

    if args.scoreboard:
        Scoreboard.print_summary()
        return

    if args.clean:
        harness = BenchmarkHarness()
        agent = HARIAgent()
        harness.evaluate_agent(agent, game_ids=CLEAN_HOLDOUT_GAMES, max_steps_per_env=args.steps)
        Scoreboard.print_summary()
        return

    if args.dev:
        harness = BenchmarkHarness()
        agent = HARIAgent()
        harness.evaluate_agent(agent, game_ids=CONTAMINATED_DEV_GAMES, max_steps_per_env=args.steps)
        Scoreboard.print_summary()
        return

    delay = 0.0 if args.fast else 0.04
    run_live(game_id=args.game_id, max_steps=args.steps, delay_sec=delay)


if __name__ == "__main__":
    main()
