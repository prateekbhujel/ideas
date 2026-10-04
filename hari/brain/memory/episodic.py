"""
HARI Episodic Memory.
Chronological record of meaningful interaction episodes.
Implements surprise-weighted retention, recency decay,
and compression of redundant episodes to maintain an O(1) bound.
"""

from typing import List, Dict, Optional, Any
from dataclasses import dataclass, field, asdict
import time
from hari.core.config import MAX_EPISODIC_EVENTS

@dataclass
class Episode:
    id: str
    timestamp: float
    utterance: str
    action_taken: str
    outcome: str
    observation_before: Dict[str, Any]
    observation_after: Dict[str, Any]
    importance: float = 1.0
    access_count: int = 1
    last_accessed: float = field(default_factory=time.time)
    provenance: str = ""

class EpisodicMemory:
    def __init__(self, capacity: int = MAX_EPISODIC_EVENTS):
        self.capacity = capacity
        self.episodes: List[Episode] = []
        self._next_id = 1

    def record(
        self,
        utterance: str,
        action_taken: str,
        outcome: str,
        observation_before: Dict[str, Any],
        observation_after: Dict[str, Any],
        importance: float = 1.0,
        provenance: str = ""
    ) -> Episode:
        # Check for near-identical duplicate to compress
        for ep in reversed(self.episodes[-10:]):
            if (
                ep.utterance.strip().lower() == utterance.strip().lower()
                and ep.action_taken == action_taken
                and ep.outcome == outcome
            ):
                ep.access_count += 1
                ep.last_accessed = time.time()
                ep.importance = min(5.0, ep.importance + 0.2)
                return ep

        ep_id = f"ep_{self._next_id:04d}"
        self._next_id += 1

        ep = Episode(
            id=ep_id,
            timestamp=time.time(),
            utterance=utterance,
            action_taken=action_taken,
            outcome=outcome,
            observation_before=observation_before,
            observation_after=observation_after,
            importance=importance,
            last_accessed=time.time(),
            provenance=provenance
        )
        self.episodes.append(ep)
        self._enforce_budget()
        return ep

    def _enforce_budget(self) -> None:
        if len(self.episodes) <= self.capacity:
            return
        now = time.time()
        # Score each episode by importance and recency
        def score(ep: Episode) -> float:
            recency = max(1.0, now - ep.last_accessed)
            return (ep.importance * ep.access_count) / (recency ** 0.5)

        self.episodes.sort(key=score, reverse=True)
        self.episodes = self.episodes[:self.capacity]
        self.episodes.sort(key=lambda ep: ep.timestamp)

    def find_relevant(self, query: str, limit: int = 5) -> List[Episode]:
        q_tokens = set(query.lower().split())
        scored: List[tuple[float, Episode]] = []
        for ep in self.episodes:
            ep_tokens = set(ep.utterance.lower().split())
            overlap = len(q_tokens.intersection(ep_tokens))
            if overlap > 0:
                ep.access_count += 1
                ep.last_accessed = time.time()
                scored.append((overlap * ep.importance, ep))
        scored.sort(key=lambda pair: pair[0], reverse=True)
        return [ep for _, ep in scored[:limit]]

    def get_recent(self, limit: int = 8) -> List[Dict[str, Any]]:
        return [asdict(ep) for ep in self.episodes[-limit:]]

    def count(self) -> int:
        return len(self.episodes)
