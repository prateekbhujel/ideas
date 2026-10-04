"""
HARI Epistemic Engine & Active Decision Calibration.
Implements Stage F, G, H findings:
- Competing hypotheses tracking
- Decision margin calibration: Delta = Score_1 - Score_2
- Decision policy: ASK under ambiguity vs ACT when supported
- Expected Information Gain (EIG) for active questions and safe experiments
"""

import math
from typing import Dict, List, Optional, Any, Tuple
from hari.core.config import CONFIDENCE_THRESHOLD, AMBIGUITY_MARGIN
from hari.core.protocol import EpistemicEvaluation

class EpistemicEngine:
    def __init__(
        self,
        confidence_threshold: float = CONFIDENCE_THRESHOLD,
        ambiguity_margin: float = AMBIGUITY_MARGIN
    ):
        self.confidence_threshold = confidence_threshold
        self.ambiguity_margin = ambiguity_margin

    def evaluate_intent(
        self,
        candidate_interpretations: List[Dict[str, Any]] # [{'intent': str, 'score': float, 'evidence': dict, 'action_plan': dict}]
    ) -> EpistemicEvaluation:
        """Evaluates competing intent hypotheses and decides whether to ACT or ASK."""
        if not candidate_interpretations:
            return EpistemicEvaluation(
                action="ASK",
                confidence=0.0,
                top_hypothesis="none",
                runner_up_hypothesis=None,
                margin=0.0,
                reason="No viable candidate interpretations found",
                competing_hypotheses=[],
                provenance={"reason": "empty_candidates"}
            )

        # Sort candidates by score descending
        sorted_cand = sorted(candidate_interpretations, key=lambda c: c["score"], reverse=True)
        top = sorted_cand[0]
        second = sorted_cand[1] if len(sorted_cand) > 1 else None

        top_score = float(top["score"])
        second_score = float(second["score"]) if second else 0.0
        margin = top_score - second_score

        # Check for ambiguity
        is_ambiguous = (second is not None) and (margin < self.ambiguity_margin) and (second_score > 0.20)
        is_underconfident = top_score < self.confidence_threshold

        if is_ambiguous:
            decision = "ASK"
            reason = f"Ambiguous between '{top['intent']}' ({top_score:.2f}) and '{second['intent']}' ({second_score:.2f})"
        elif is_underconfident:
            decision = "ASK"
            reason = f"Confidence {top_score:.2f} is below safety threshold {self.confidence_threshold:.2f}"
        else:
            decision = "ACT"
            reason = f"Decisive support for '{top['intent']}' (score {top_score:.2f}, margin {margin:.2f})"

        # Compute calibrated confidence in [0, 1]
        calibrated_conf = min(1.0, max(0.0, (top_score + margin) / 2.0))

        return EpistemicEvaluation(
            action=decision,
            confidence=round(calibrated_conf, 3),
            top_hypothesis=top["intent"],
            runner_up_hypothesis=second["intent"] if second else None,
            margin=round(margin, 3),
            reason=reason,
            competing_hypotheses=[
                {"intent": c["intent"], "score": round(c["score"], 3)}
                for c in sorted_cand[:5]
            ],
            provenance=top.get("evidence", {})
        )

    def calculate_eig(self, prior_entropy: float, p_outcomes: List[float]) -> float:
        """Expected Information Gain (EIG) = H(Prior) - E[H(Posterior)]."""
        if prior_entropy <= 0.0:
            return 0.0
        # If inquiry yields distinct outcomes, posterior entropy collapses
        exp_post_entropy = 0.0
        for p in p_outcomes:
            if p > 0.0:
                exp_post_entropy += p * (-math.log2(p))
        eig = max(0.0, prior_entropy - exp_post_entropy)
        return round(eig, 3)
