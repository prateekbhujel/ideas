"""
HARI Action Executor.
Compiles high-level ActionPlan into deterministic MacActionEngine calls.
Guards execution with RiskPolicy and produces structured execution telemetry.
"""

import time
from typing import Dict, Any, Optional
from hari.core.protocol import ActionPlan
from hari.body.hands.mac_actions import MacActionEngine
from hari.body.hands.risk_policy import RiskPolicy

class ActionExecutor:
    def __init__(self):
        self.action_history: list = []

    def execute(self, plan: ActionPlan, confirmed: bool = False) -> Dict[str, Any]:
        risk_level, needs_confirmation = RiskPolicy.assess_risk(plan)
        plan.risk_level = risk_level
        plan.needs_confirmation = needs_confirmation

        if needs_confirmation and not confirmed:
            plan.status = "PENDING_CONFIRMATION"
            return {
                "executed": False,
                "status": "PENDING_CONFIRMATION",
                "message": f"Action '{plan.action_type}' is consequential and requires your confirmation before proceeding.",
                "plan": plan
            }

        start_t = time.time()
        result = {}
        atype = plan.action_type.upper()

        if atype == "OPEN_APP":
            app = plan.params.get("app_name", "Terminal")
            result = MacActionEngine.open_app(app)

        elif atype == "FOCUS_WINDOW":
            app = plan.params.get("app_name", "Terminal")
            result = MacActionEngine.focus_window(app)

        elif atype == "MOVE_WINDOW":
            app = plan.params.get("app_name", "Terminal")
            pos = plan.params.get("position", "left")
            result = MacActionEngine.move_window(app, pos)

        elif atype == "TYPE":
            text = plan.params.get("text", "")
            press_enter = plan.params.get("press_enter", False)
            result = MacActionEngine.type_text(text, press_enter=press_enter)

        elif atype == "CLICK":
            x = plan.params.get("x", 200)
            y = plan.params.get("y", 200)
            result = MacActionEngine.click(x, y)

        elif atype == "MOVE_POINTER":
            x = plan.params.get("x", 200)
            y = plan.params.get("y", 200)
            result = MacActionEngine.move_mouse(x, y)

        elif atype in ["OBSERVE", "ASK"]:
            result = {"success": True, "action": atype}

        else:
            result = {"success": False, "error": f"Unknown action type: {atype}"}

        duration = round(time.time() - start_t, 3)
        plan.status = "EXECUTED" if result.get("success", False) else "FAILED"

        entry = {
            "timestamp": time.time(),
            "plan": plan,
            "result": result,
            "duration_s": duration
        }
        self.action_history.append(entry)
        if len(self.action_history) > 64:
            self.action_history.pop(0)

        return {
            "executed": True,
            "status": plan.status,
            "result": result,
            "duration_s": duration
        }
