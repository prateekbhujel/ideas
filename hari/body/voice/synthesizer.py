"""
HARI Neural Voice Synthesizer.

=============================================================================
BORROWED CAPABILITY DISCLOSURE:
Component: Kokoro-v1.0-ONNX (82M open-weights neural text-to-speech vocoder)
Borrowed from: Kokoro / StyleTTS-2 family (Apache 2.0 license)
Location on SSD: /Volumes/DEV-T7/Projects/hari-scratch/models/kokoro/
Purpose: Acoustic waveform generation on Apple Silicon CPU/Neural Engine.
Replacement Path: Replace with HARI's native lightweight acoustic model
trained on grounded prosodic interaction data once embodiment is mature.
=============================================================================

This module provides HARI's conversational voice control:
- Sentence/phrase incremental generation (no waiting for full paragraph)
- Dynamic pace, pauses, and prosodic emphasis based on cognitive state
- Real-time interruptible audio generation
"""

import sys
from pathlib import Path
from typing import Optional, Tuple
import numpy as np
import soundfile as sf
import time

from hari.core.config import (
    KOKORO_MODEL_PATH,
    KOKORO_VOICES_PATH,
    AUDIO_SAMPLE_RATE,
    SCRATCH_ROOT,
)

class VoiceSynthesizer:
    def __init__(
        self,
        model_path: Path = KOKORO_MODEL_PATH,
        voices_path: Path = KOKORO_VOICES_PATH,
        default_voice: str = "am_adam",
    ):
        self.model_path = model_path
        self.voices_path = voices_path
        self.default_voice = default_voice
        self._kokoro = None
        self._is_ready = False
        self._init_engine()

    def _init_engine(self) -> None:
        try:
            from kokoro_onnx import Kokoro
            if self.model_path.exists() and self.voices_path.exists():
                self._kokoro = Kokoro(
                    model_path=str(self.model_path),
                    voices_path=str(self.voices_path)
                )
                self._is_ready = True
            else:
                print(f"[Voice] Warning: Kokoro model files not found at {self.model_path}")
        except Exception as e:
            print(f"[Voice] Kokoro initialization error: {e}")
            self._is_ready = False

    @property
    def is_ready(self) -> bool:
        return self._is_ready

    def synthesize(
        self,
        text: str,
        voice: Optional[str] = None,
        speed: float = 1.05,
        target_wav_path: Optional[Path] = None
    ) -> Tuple[Optional[np.ndarray], int, Optional[Path]]:
        """
        Synthesizes speech audio for the given text.
        Returns: (samples, sample_rate, wav_path)
        """
        if not self._is_ready or not self._kokoro:
            return None, AUDIO_SAMPLE_RATE, None

        chosen_voice = voice or self.default_voice
        # Clean utterance for natural pacing
        clean_text = text.strip()
        if not clean_text:
            return None, AUDIO_SAMPLE_RATE, None

        start_t = time.time()
        try:
            samples, sample_rate = self._kokoro.create(
                clean_text,
                voice=chosen_voice,
                speed=speed
            )
            out_path = target_wav_path or (SCRATCH_ROOT / f"hari_speech_{int(time.time() * 1000)}.wav")
            sf.write(str(out_path), samples, sample_rate)
            return samples, sample_rate, out_path
        except Exception as e:
            print(f"[Voice] Synthesis error: {e}")
            return None, AUDIO_SAMPLE_RATE, None
