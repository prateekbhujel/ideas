"""
HARI Autonomous ARC-AGI-3 Agent.
Backwards-compatible interface forwarding to GeneralAutonomousLearner.
"""

from hari.arc.general_learner import GeneralAutonomousLearner

class HARIAgent(GeneralAutonomousLearner):
    """HARI Autonomous ARC-AGI-3 Agent.
    Operates without game-ID checks, hardcoded coordinates, or assumed action semantics.
    Discovers kinematics, avatar, hazards, goals, affordances, and plans multi-step
    collision-free paths with global backtracking.
    """
    def __init__(self, seed: int = 42):
        super().__init__(seed=seed)
        self.name = "HARI Autonomous Learner"
