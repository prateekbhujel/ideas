"""
HARI Conversational Turn & Barge-In Manager.
Maintains conversational state:
- LISTENING: Waiting for user speech
- USER_SPEAKING: User is currently vocalizing
- THINKING: Deliberating on utterance
- HARI_SPEAKING: HARI is speaking aloud
- INTERRUPTED: User spoke while HARI was talking (barge-in cut-off)
"""

import time
from typing import Optional, Callable
from hari.core.config import VAD_SILENCE_TIMEOUT_MS, BARGE_IN_TRIGGER_MS

class TurnManager:
    def __init__(self, on_barge_in: Optional[Callable[[], None]] = None):
        self.state: str = "LISTENING"
        self.last_speech_time: float = 0.0
        self.user_turn_start: float = 0.0
        self.on_barge_in = on_barge_in
        self.silence_timeout = VAD_SILENCE_TIMEOUT_MS / 1000.0

    def on_user_speech_start(self) -> bool:
        """Called immediately when speech onset is detected."""
        now = time.time()
        was_interrupted = False

        if self.state == "HARI_SPEAKING":
            # Barge-in triggered!
            self.state = "INTERRUPTED"
            was_interrupted = True
            if self.on_barge_in:
                self.on_barge_in()
        else:
            self.state = "USER_SPEAKING"

        self.user_turn_start = now
        self.last_speech_time = now
        return was_interrupted

    def on_user_speech_chunk(self) -> None:
        """Keepalive while user continues talking."""
        self.last_speech_time = time.time()
        if self.state != "INTERRUPTED":
            self.state = "USER_SPEAKING"

    def check_turn_completion(self) -> bool:
        """Returns True if the user has completed their turn (silence threshold reached)."""
        if self.state in ["USER_SPEAKING", "INTERRUPTED"]:
            elapsed_silence = time.time() - self.last_speech_time
            if elapsed_silence >= self.silence_timeout:
                self.state = "THINKING"
                return True
        return False

    def on_hari_speech_start(self) -> None:
        self.state = "HARI_SPEAKING"

    def on_hari_speech_end(self) -> None:
        if self.state != "INTERRUPTED":
            self.state = "LISTENING"
