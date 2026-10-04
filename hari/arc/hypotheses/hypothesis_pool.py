from typing import Dict, Any, List, Optional, Tuple
from collections import defaultdict

class CausalHypothesis:
    """A falsifiable hypothesis about environment mechanics and goals."""

    def __init__(
        self,
        hid: str,
        htype: str,
        description: str,
        target_color: Optional[int] = None,
        target_entity_id: Optional[int] = None,
        confidence: float = 0.5
    ):
        self.hid = hid
        self.htype = htype  # "REACH_COLOR", "COLLECT_ITEMS", "CLICK_TARGET", "AVOID_HAZARD"
        self.description = description
        self.target_color = target_color
        self.target_entity_id = target_entity_id
        self.confidence = confidence
        self.confirmations = 0
        self.falsifications = 0
        self.active = True

    def confirm(self, weight: float = 0.25):
        self.confirmations += 1
        self.confidence = min(1.0, self.confidence + weight)

    def falsify(self, weight: float = 0.35):
        self.falsifications += 1
        self.confidence = max(0.0, self.confidence - weight)
        if self.confidence < 0.15:
            self.active = False


class HypothesisPool:
    """Maintains, scores, and updates competing causal explanations."""

    def __init__(self):
        self.hypotheses: Dict[str, CausalHypothesis] = {}
        self.hypothesis_counter = 0

    def generate_candidate_hypotheses(self, candidate_colors: List[int], avatar_color: Optional[int] = None):
        """Generates initial hypotheses for unvisited colors."""
        for c in candidate_colors:
            if avatar_color is not None and c == avatar_color:
                continue

            reach_hid = f"H_reach_{c}"
            if reach_hid not in self.hypotheses:
                self.hypotheses[reach_hid] = CausalHypothesis(
                    hid=reach_hid,
                    htype="REACH_COLOR",
                    description=f"Reaching color {c} advances level",
                    target_color=c,
                    confidence=0.45
                )

            collect_hid = f"H_collect_{c}"
            if collect_hid not in self.hypotheses:
                self.hypotheses[collect_hid] = CausalHypothesis(
                    hid=collect_hid,
                    htype="COLLECT_ITEMS",
                    description=f"Collecting color {c} items completes level",
                    target_color=c,
                    confidence=0.35
                )

    def on_contact_event(self, contact_color: int, level_advanced: bool, game_over: bool):
        """Updates hypotheses based on collision outcome."""
        for h in self.hypotheses.values():
            if h.target_color == contact_color:
                if level_advanced:
                    h.confirm(weight=0.5)
                elif game_over:
                    h.falsify(weight=0.8)  # Poisonous / Hazard!
                else:
                    # Contact without level advance or death
                    h.confirm(weight=0.1)

    def get_ranked_hypotheses(self) -> List[CausalHypothesis]:
        active = [h for h in self.hypotheses.values() if h.active]
        active.sort(key=lambda h: h.confidence, reverse=True)
        return active

    def get_best_goal(self) -> Optional[CausalHypothesis]:
        ranked = self.get_ranked_hypotheses()
        return ranked[0] if ranked else None
