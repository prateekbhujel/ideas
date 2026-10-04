import os
import time
import resource
import traceback
from typing import Dict, Any, List, Optional
from arc_agi import Arcade, OperationMode
from arcengine import GameAction, GameState

from hari.arc.env_adapter import ARCEnvironmentAdapter, DEFAULT_ENV_DIR, DEFAULT_REC_DIR
from hari.arc.scoreboard import Scoreboard

class BenchmarkHarness:
    """Evaluates an agent on official ARC-AGI-3 environments under standard rules."""

    def __init__(
        self,
        env_dir: str = DEFAULT_ENV_DIR,
        rec_dir: str = DEFAULT_REC_DIR
    ):
        self.env_dir = env_dir
        self.rec_dir = rec_dir
        self.arcade = Arcade(
            operation_mode=OperationMode.OFFLINE,
            environments_dir=self.env_dir,
            recordings_dir=self.rec_dir
        )

    def list_environments(self) -> List[str]:
        envs = self.arcade.get_environments()
        return [e.game_id for e in envs]

    def evaluate_agent(
        self,
        agent: Any,
        game_ids: Optional[List[str]] = None,
        max_steps_per_env: int = 250,
        verbose: bool = True
    ) -> Dict[str, Any]:
        available = self.list_environments()
        if not game_ids:
            game_ids = available

        card_id = self.arcade.create_scorecard(tags=[getattr(agent, "name", "agent")])
        if verbose:
            print(f"\n[HARNESS] Starting Evaluation of '{getattr(agent, 'name', 'agent')}' on {len(game_ids)} environments...")
            print(f"[HARNESS] Scorecard ID: {card_id}")

        total_actions = 0
        total_latencies = []
        env_results = []
        total_levels_completed = 0
        total_win_levels = 0

        start_time = time.time()

        for idx, gid in enumerate(game_ids):
            if verbose:
                print(f"[{idx+1}/{len(game_ids)}] Playing {gid}...", end="", flush=True)

            adapter = ARCEnvironmentAdapter(
                game_id=gid,
                scorecard_id=card_id,
                env_dir=self.env_dir,
                rec_dir=self.rec_dir
            )

            try:
                obs = adapter.reset()
                total_win_levels += obs["win_levels"]
                env_actions = 0

                for step in range(max_steps_per_env):
                    if obs["is_terminal"]:
                        break

                    actions = adapter.action_space
                    t0 = time.perf_counter()
                    action, data, why = agent.select_action(obs, actions)
                    lat = (time.perf_counter() - t0) * 1000.0
                    total_latencies.append(lat)

                    obs = adapter.step(action, data=data, reasoning={"why": why})
                    env_actions += 1

                total_actions += env_actions
                total_levels_completed += obs["levels_completed"]

                if verbose:
                    status = "WIN" if obs["state"] == GameState.WIN else ("OVER" if obs["state"] == GameState.GAME_OVER else "NOT_FINISHED")
                    print(f" done. Steps: {env_actions}, State: {status}, Levels: {obs['levels_completed']}/{obs['win_levels']}")

                env_results.append({
                    "game_id": gid,
                    "state": str(obs["state"]),
                    "levels_completed": obs["levels_completed"],
                    "win_levels": obs["win_levels"],
                    "steps": env_actions
                })

            except Exception as e:
                if verbose:
                    print(f" ERROR: {e}")
                traceback.print_exc()

        elapsed_time = time.time() - start_time
        scorecard = self.arcade.close_scorecard(card_id)
        final_score = scorecard.score if scorecard else 0.0

        avg_latency = sum(total_latencies) / len(total_latencies) if total_latencies else 0.0
        ram_mb = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / (1024.0 * 1024.0 if os.uname().sysname == "Darwin" else 1024.0)

        result_summary = {
            "scorecard_id": card_id,
            "algorithm": getattr(agent, "name", "agent"),
            "arc_score": final_score,
            "levels_completed": total_levels_completed,
            "total_levels": total_win_levels,
            "actions": total_actions,
            "runtime_sec": round(elapsed_time, 2),
            "step_latency_ms": round(avg_latency, 3),
            "ram_mb": round(ram_mb, 2),
            "env_results": env_results
        }

        # Record to persistent machine-readable scoreboard
        Scoreboard.record_run(
            algorithm=getattr(agent, "name", "agent"),
            arc_score=final_score,
            levels_completed=total_levels_completed,
            total_levels=total_win_levels,
            actions=total_actions,
            ram_mb=ram_mb,
            step_latency_ms=avg_latency,
            notes=f"Evaluated on {len(game_ids)} environments in {round(elapsed_time, 1)}s"
        )

        if verbose:
            print(f"\n" + "=" * 60)
            print(f"EVALUATION COMPLETE: {getattr(agent, 'name', 'agent')}")
            print(f"Official ARC-AGI-3 Score: {final_score:.4f}%")
            print(f"Levels Completed: {total_levels_completed} / {total_win_levels}")
            print(f"Total Actions: {total_actions} across {len(game_ids)} games")
            print(f"Average Step Latency: {avg_latency:.2f} ms")
            print(f"RAM Usage: {ram_mb:.1f} MB")
            print(f"=" * 60 + "\n")

        return result_summary
