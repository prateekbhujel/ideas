"""
HARI Cognitive Mind.
The central coordinator integrating:
- Working, Episodic, Semantic, Procedural, and World State memories
- Grounded associative reasoning (PPMI, lateral inhibition)
- Epistemic uncertainty calibration (ACT vs ASK)
- Provenance and rationale tracing
- Idle sleep consolidation
Strictly bounded memory with O(1) resource ceilings.
"""

import time
import re
from typing import Dict, List, Optional, Any, Tuple
from hari.core.protocol import ActionPlan, EpistemicEvaluation, MindStateSummary
from hari.core.config import (
    RISK_READ_ONLY,
    RISK_REVERSIBLE,
    RISK_CONSEQUENTIAL,
)
from hari.brain.memory.working import WorkingMemory
from hari.brain.memory.episodic import EpisodicMemory
from hari.brain.memory.semantic import SemanticMemory
from hari.brain.memory.procedural import ProceduralMemory
from hari.brain.memory.world_state import WorldState
from hari.brain.epistemic import EpistemicEngine
from hari.brain.provenance import ProvenanceEngine
from hari.brain.sleep import SleepConsolidator

class Mind:
    def __init__(self):
        self.working_memory = WorkingMemory()
        self.episodic_memory = EpisodicMemory()
        self.semantic_memory = SemanticMemory.load()
        self.procedural_memory = ProceduralMemory()
        self.world_state = WorldState()
        self.epistemic_engine = EpistemicEngine()
        self.provenance_engine = ProvenanceEngine()
        self.sleep_consolidator = SleepConsolidator(
            self.episodic_memory,
            self.semantic_memory,
            self.procedural_memory
        )
        self.status: str = "ALIVE"
        self.last_user_speech: str = ""
        self.last_hari_speech: str = "HARI is alive and listening."

    def process_utterance(
        self,
        utterance: str,
        screen_context: Optional[Dict[str, Any]] = None,
        camera_context: Optional[Dict[str, Any]] = None,
        is_correction: bool = False
    ) -> Dict[str, Any]:
        """Core cognitive turn: understand, deliberate, plan action, and generate response."""
        utterance_clean = utterance.strip()
        self.last_user_speech = utterance_clean
        self.working_memory.add_turn("user", utterance_clean)

        # Update world state from incoming sensory contexts
        if screen_context:
            self.world_state.update_screen_state(
                frontmost_app=screen_context.get("frontmost_app", "Finder"),
                windows=screen_context.get("windows", [])
            )
        if camera_context:
            self.world_state.update_camera_state(camera_context.get("detected_objects", []))

        text_lower = utterance_clean.lower()

        # 1. Check for Provenance / "Why did you do that?"
        if any(phrase in text_lower for phrase in ["why did you do that", "explain why", "why that action", "show evidence"]):
            explanation = self.provenance_engine.explain_latest_action()
            reply_text = explanation["explanation"]
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {
                "spoken_reply": reply_text,
                "plan": None,
                "epistemic": None,
                "explanation": explanation
            }

        # 2. Check for Explicit Fact Teaching: "Remember that I call this project Ground"
        teach_match = re.search(r"remember (?:that )?(?:i call )?(?:this |the )?project (?:is |as |name is )?([a-zA-Z0-9_-]+)", text_lower)
        if teach_match:
            project_name = teach_match.group(1).capitalize()
            self.semantic_memory.store_fact("project", "name", project_name, provenance=utterance_clean)
            self.semantic_memory.save()
            reply_text = f"Remembered. You call this project {project_name}."
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        # Check for Camera Object Teaching: "Remember this as my red notebook"
        cam_teach_match = re.search(r"remember this as (?:my |a )?([a-zA-Z0-9_ ]+)", text_lower)
        if cam_teach_match and camera_context:
            obj_label = cam_teach_match.group(1).strip()
            self.semantic_memory.store_fact("held_object", "label", obj_label, provenance=utterance_clean)
            self.semantic_memory.ground_experience(
                utterance=f"holding {obj_label}",
                sensory_features=[(f"cam:label={obj_label}", "cam:label"), ("cam:held=true", "cam:held")],
                is_correction=True
            )
            self.semantic_memory.save()
            reply_text = f"I will remember this object as your {obj_label}."
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        # 3. Check for Fact Recall: "What did I call this project?"
        if any(p in text_lower for p in ["what did i call this project", "project name", "name of this project"]):
            val = self.semantic_memory.query_fact("project", "name")
            if val:
                reply_text = f"You call this project {val}."
            else:
                reply_text = "I do not have a recorded project name yet. You can tell me by saying 'Remember that I call this project Ground'."
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        # Check for Object Recall: "What am I holding?" / "What is this?"
        if any(p in text_lower for p in ["what am i holding", "what is this", "what do you see in front of camera"]):
            val = self.semantic_memory.query_fact("held_object", "label")
            if val:
                reply_text = f"You are holding your {val}."
            elif self.world_state.camera_objects:
                top_obj = self.world_state.camera_objects[0].get("label", "an object")
                reply_text = f"In the camera view, I observe {top_obj}."
            else:
                reply_text = "I see you holding an object, but I haven't learned its name yet. What should I remember it as?"
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        # 4. Check for Visual Screen Perception: "What application is open?" / "What is on my screen?"
        if any(p in text_lower for p in ["what application is open", "what app is open", "frontmost app", "active app"]):
            front_app = self.world_state.frontmost_app or "Finder"
            win_count = len(self.world_state.open_windows)
            reply_text = f"The active application is {front_app}, with {win_count} open windows visible."
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        if any(p in text_lower for p in ["look at my screen", "what is on my screen", "read screen"]):
            front_app = self.world_state.frontmost_app or "Finder"
            windows = self.world_state.open_windows
            titles = [w.get("title", "") for w in windows[:3] if w.get("title")]
            title_desc = f" showing [{', '.join(titles)}]" if titles else ""
            reply_text = f"I am observing your screen. Currently, {front_app} is frontmost{title_desc}."
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        # 5. Conversational Greetings: "Hey Hari" / "Hello"
        if text_lower in ["hey hari", "hello hari", "hari", "hi hari", "hello"]:
            reply_text = "I am here. How can I assist you on your Mac?"
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {"spoken_reply": reply_text, "plan": None, "epistemic": None}

        # 6. Intent & Action Deliberation via Epistemic Engine
        candidate_intents = self._synthesize_candidate_intents(text_lower, is_correction)
        epistemic_eval = self.epistemic_engine.evaluate_intent(candidate_intents)

        if epistemic_eval.action == "ASK":
            # Epistemic Safety: Refuse to guess when uncertain or ambiguous!
            reply_text = f"I am uncertain: {epistemic_eval.reason}. Could you clarify what you'd like me to do?"
            self.working_memory.add_turn("hari", reply_text)
            self.last_hari_speech = reply_text
            return {
                "spoken_reply": reply_text,
                "plan": None,
                "epistemic": epistemic_eval,
            }

        # Top Intent is supported: construct deterministic ActionPlan
        top_intent_data = next((c for c in candidate_intents if c["intent"] == epistemic_eval.top_hypothesis), None)
        if not top_intent_data:
            reply_text = "I could not form a valid plan for that request."
            self.working_memory.add_turn("hari", reply_text)
            return {"spoken_reply": reply_text, "plan": None, "epistemic": epistemic_eval}

        plan: ActionPlan = top_intent_data["action_plan"]
        reply_text = top_intent_data["reply_text"]

        # Record provenance for verifiable accountability
        self.provenance_engine.record_decision(
            action_type=plan.action_type,
            trigger_utterance=utterance_clean,
            grounded_tokens=self.semantic_memory.tokenize(utterance_clean),
            supporting_features=top_intent_data.get("supporting_features", []),
            confidence=epistemic_eval.confidence,
            margin=epistemic_eval.margin,
            competing_alternatives=[c["intent"] for c in candidate_intents if c["intent"] != epistemic_eval.top_hypothesis],
            world_context={"frontmost_app": self.world_state.frontmost_app}
        )

        self.working_memory.add_turn("hari", reply_text, associated_action=plan.action_type)
        self.last_hari_speech = reply_text

        # Record in Episodic Memory
        self.episodic_memory.record(
            utterance=utterance_clean,
            action_taken=plan.action_type,
            outcome="planned",
            observation_before={"app": self.world_state.frontmost_app},
            observation_after={},
            importance=2.0 if plan.risk_level == RISK_CONSEQUENTIAL else 1.0,
            provenance=f"intent:{epistemic_eval.top_hypothesis}"
        )

        return {
            "spoken_reply": reply_text,
            "plan": plan,
            "epistemic": epistemic_eval,
        }

    def _synthesize_candidate_intents(self, text: str, is_correction: bool) -> List[Dict[str, Any]]:
        """Formulates competing intent hypotheses based on grounded lexicon and procedural schemas."""
        candidates = []

        # Intent: Open Application
        open_match = re.search(r"open (?:the )?([a-zA-Z0-9_-]+)", text)
        if open_match:
            app_target = open_match.group(1).capitalize()
            candidates.append({
                "intent": f"open_{app_target.lower()}",
                "score": 0.90 if not is_correction else 0.95,
                "reply_text": f"Opening {app_target}.",
                "supporting_features": [{"feature": f"act:open={app_target}", "score": 0.90}],
                "action_plan": ActionPlan(
                    action_type="OPEN_APP",
                    params={"app_name": app_target},
                    risk_level=RISK_REVERSIBLE,
                    needs_confirmation=False,
                    reason=f"User requested to open {app_target}",
                    target_description=f"Application '{app_target}'"
                )
            })

        # Intent: Move Window
        move_match = re.search(r"move (?:the )?([a-zA-Z0-9_-]+)? ?window (?:to the )?(left|right|center)", text)
        if move_match or "move that window" in text:
            app_name = move_match.group(1).capitalize() if (move_match and move_match.group(1)) else (self.world_state.frontmost_app or "Terminal")
            position = move_match.group(2) if (move_match and move_match.group(2)) else "left"
            candidates.append({
                "intent": f"move_window_{position}",
                "score": 0.88,
                "reply_text": f"Moving the {app_name} window to the {position}.",
                "supporting_features": [{"feature": f"act:move_window={position}", "score": 0.88}],
                "action_plan": ActionPlan(
                    action_type="MOVE_WINDOW",
                    params={"app_name": app_name, "position": position},
                    risk_level=RISK_REVERSIBLE,
                    needs_confirmation=False,
                    reason=f"Positioning window to {position}",
                    target_description=f"{app_name} Window -> {position}"
                )
            })

        # Intent: Type Text
        type_match = re.search(r"type (.+)", text)
        if type_match:
            text_to_type = type_match.group(1).strip()
            # If text contains shell commands like echo, rm, sudo -> consequential risk policy!
            is_consequential = any(cmd in text_to_type.lower() for cmd in ["rm ", "sudo", "echo", "curl", "bash", "kill", "git"])
            candidates.append({
                "intent": "type_keystrokes",
                "score": 0.85,
                "reply_text": f"Typing: {text_to_type}. I will not press Enter without your confirmation.",
                "supporting_features": [{"feature": "act:type_text", "score": 0.85}],
                "action_plan": ActionPlan(
                    action_type="TYPE",
                    params={"text": text_to_type, "press_enter": False},
                    risk_level=RISK_CONSEQUENTIAL if is_consequential else RISK_REVERSIBLE,
                    needs_confirmation=is_consequential,
                    reason=f"Type text without Enter: {text_to_type}",
                    target_description=f"Active Input Field: '{text_to_type}'"
                )
            })

        # Fallback query concepts from grounded associative memory
        grounded = self.semantic_memory.query_concepts_for_utterance(text)
        if grounded:
            top_g = grounded[0]
            candidates.append({
                "intent": f"grounded_{top_g['feature']}",
                "score": min(0.70, top_g["score"]),
                "reply_text": f"I associate that with {top_g['feature']}.",
                "supporting_features": grounded[:3],
                "action_plan": ActionPlan(
                    action_type="OBSERVE",
                    params={"feature": top_g["feature"]},
                    risk_level=RISK_READ_ONLY,
                    needs_confirmation=False,
                    reason=f"Associative memory match: {top_g['feature']}"
                )
            })

        return candidates

    def get_mind_state_summary(self) -> MindStateSummary:
        return MindStateSummary(
            timestamp=time.time(),
            status=self.status,
            senses={"mic": True, "camera": True, "screen": True, "hands": True},
            current_belief=self.world_state.get_summary(),
            epistemic={"threshold": self.epistemic_engine.confidence_threshold, "margin": self.epistemic_engine.ambiguity_margin},
            working_memory_count=len(self.working_memory.turns),
            episodic_count=self.episodic_memory.count(),
            semantic_tokens=len(self.semantic_memory.tokens),
            semantic_features=len(self.semantic_memory.features),
            resource_stats=self.semantic_memory.stats(),
            last_user_speech=self.last_user_speech,
            last_hari_speech=self.last_hari_speech,
        )
