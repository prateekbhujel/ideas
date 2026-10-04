"""
HARI Provenance & Rationale Engine.
Tracks the concrete evidential lineage of actions and beliefs.
When asked "Why did you do that?", returns verifiable facts and memory traces
rather than confabulating.
"""

from typing import Dict, List, Optional, Any
from dataclasses import dataclass, field, asdict
import time

@dataclass
class ActionProvenanceRecord:
    action_id: str
    action_type: str
    timestamp: float
    trigger_utterance: str
    grounded_tokens: List[str]
    supporting_features: List[Dict[str, Any]]
    confidence: float
    margin: float
    competing_alternatives: List[str]
    world_context_snapshot: Dict[str, Any]

class ProvenanceEngine:
    def __init__(self, capacity: int = 64):
        self.capacity = capacity
        self.records: List[ActionProvenanceRecord] = []

    def record_decision(
        self,
        action_type: str,
        trigger_utterance: str,
        grounded_tokens: List[str],
        supporting_features: List[Dict[str, Any]],
        confidence: float,
        margin: float,
        competing_alternatives: List[str],
        world_context: Dict[str, Any]
    ) -> str:
        act_id = f"act_{len(self.records) + 1:03d}"
        rec = ActionProvenanceRecord(
            action_id=act_id,
            action_type=action_type,
            timestamp=time.time(),
            trigger_utterance=trigger_utterance,
            grounded_tokens=grounded_tokens,
            supporting_features=supporting_features,
            confidence=confidence,
            margin=margin,
            competing_alternatives=competing_alternatives,
            world_context_snapshot=world_context
        )
        self.records.append(rec)
        if len(self.records) > self.capacity:
            self.records.pop(0)
        return act_id

    def explain_latest_action(self) -> Dict[str, Any]:
        """Provides verified provenance for the most recent action."""
        if not self.records:
            return {
                "has_action": False,
                "explanation": "I have not taken any physical actions yet in this session."
            }

        last = self.records[-1]
        tokens_str = ", ".join(f"'{t}'" for t in last.grounded_tokens) if last.grounded_tokens else "direct command"
        feat_str = "; ".join(f"{f.get('feature')} (score {f.get('score', 0):.2f})" for f in last.supporting_features[:3])

        explanation = (
            f"I executed {last.action_type} because you said '{last.trigger_utterance}'. "
            f"The key tokens were {tokens_str}, which matched features [{feat_str}] "
            f"with confidence {last.confidence:.2f} (margin over alternative: {last.margin:.2f})."
        )

        return {
            "has_action": True,
            "action_id": last.action_id,
            "action_type": last.action_type,
            "trigger_utterance": last.trigger_utterance,
            "explanation": explanation,
            "supporting_evidence": last.supporting_features,
            "alternatives_rejected": last.competing_alternatives,
            "world_context": last.world_context_snapshot
        }
