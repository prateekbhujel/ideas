"""
HARI Action Risk Policy.
Classifies actions into:
- READ / OBSERVE: Safe to execute immediately without side effects
- REVERSIBLE ACTION: Non-destructive OS actions (move window, focus app, launch app)
- EXTERNAL / CONSEQUENTIAL ACTION: Potentially dangerous (executing terminal shell commands, pressing Enter, closing apps)
Enforces user confirmation before consequential execution.
"""

from typing import Dict, Any, Tuple
from hari.core.config import (
    RISK_READ_ONLY,
    RISK_REVERSIBLE,
    RISK_CONSEQUENTIAL,
)
from hari.core.protocol import ActionPlan

class RiskPolicy:
    @staticmethod
    def assess_risk(action_plan: ActionPlan) -> Tuple[str, bool]:
        """Returns: (risk_level, requires_confirmation)"""
        atype = action_plan.action_type.upper()

        if atype in ["OBSERVE", "READ", "QUERY"]:
            return RISK_READ_ONLY, False

        if atype in ["OPEN_APP", "FOCUS_WINDOW", "MOVE_WINDOW", "RESIZE_WINDOW", "MOVE_POINTER"]:
            return RISK_REVERSIBLE, False

        if atype == "TYPE":
            params = action_plan.params
            text = params.get("text", "").lower()
            press_enter = params.get("press_enter", False)
            # Dangerous commands or pressing Enter require confirmation!
            if press_enter or any(c in text for c in ["rm ", "sudo", "curl ", "bash", "kill", "git push", "rmdir"]):
                return RISK_CONSEQUENTIAL, True
            return RISK_REVERSIBLE, False

        if atype in ["CLICK", "DOUBLE_CLICK", "KEY"]:
            return RISK_REVERSIBLE, False

        return RISK_CONSEQUENTIAL, True
