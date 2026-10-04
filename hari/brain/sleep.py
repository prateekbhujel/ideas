"""
HARI Sleep & Idle Memory Consolidation.
Runs during quiet idle periods to:
- Merge redundant episodic memories
- Reinforce frequently accessed semantic concepts
- Decay stale unreinforced hypothesis links
- Compress procedural macros
- Free memory budget to strictly maintain O(1) limits
"""

from typing import Dict, List, Any
import time
from hari.brain.memory.episodic import EpisodicMemory
from hari.brain.memory.semantic import SemanticMemory
from hari.brain.memory.procedural import ProceduralMemory

class SleepConsolidator:
    def __init__(
        self,
        episodic: EpisodicMemory,
        semantic: SemanticMemory,
        procedural: ProceduralMemory
    ):
        self.episodic = episodic
        self.semantic = semantic
        self.procedural = procedural
        self.last_consolidation: float = time.time()
        self.consolidation_runs: int = 0

    def run_cycle(self) -> Dict[str, Any]:
        """Executes a bounded offline memory consolidation cycle."""
        start_t = time.time()
        self.consolidation_runs += 1

        # 1. Compress redundant episodic memories
        initial_ep_count = len(self.episodic.episodes)
        compressed_count = 0
        seen_patterns = {}
        kept_episodes = []

        for ep in self.episodic.episodes:
            pattern_key = f"{ep.utterance.strip().lower()}::{ep.action_taken}"
            if pattern_key in seen_patterns:
                # Merge into existing pattern record
                prev = seen_patterns[pattern_key]
                prev.access_count += ep.access_count
                prev.importance = min(5.0, prev.importance + 0.1)
                compressed_count += 1
            else:
                seen_patterns[pattern_key] = ep
                kept_episodes.append(ep)

        self.episodic.episodes = kept_episodes
        self.episodic._enforce_budget()

        # 2. Semantic Memory Consolidation: prune zero-count links
        pruned_pairs = 0
        for tok, t_data in list(self.semantic.tokens.items()):
            for feat, p_data in list(t_data["pairs"].items()):
                effective_cnt = self.semantic._decay(p_data["count"], p_data["last"])
                if effective_cnt < 0.05:
                    del t_data["pairs"][feat]
                    pruned_pairs += 1

        self.semantic._enforce_caps()
        # Save consolidated semantic state to SSD
        self.semantic.save()

        duration = round(time.time() - start_t, 3)
        self.last_consolidation = time.time()

        return {
            "run_index": self.consolidation_runs,
            "duration_s": duration,
            "episodes_merged": compressed_count,
            "remaining_episodes": len(self.episodic.episodes),
            "stale_pairs_pruned": pruned_pairs,
            "active_tokens": len(self.semantic.tokens),
            "active_features": len(self.semantic.features),
        }
