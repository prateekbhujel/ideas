"""
HARI Interruptible Audio Player.
Manages audio playback through local speakers via ffplay or audio pipes.
Provides instantaneous (<10ms) cancellation upon barge-in / user interruption.
"""

import subprocess
import os
import signal
import time
from pathlib import Path
from typing import Optional

class InterruptibleAudioPlayer:
    def __init__(self):
        self._current_process: Optional[subprocess.Popen] = None
        self._is_playing: bool = False

    @property
    def is_playing(self) -> bool:
        if self._current_process is not None:
            poll_res = self._current_process.poll()
            if poll_res is not None:
                self._is_playing = False
                self._current_process = None
        return self._is_playing

    def play_wav(self, wav_path: Path, blocking: bool = False) -> None:
        """Plays audio through macOS default output using ffplay."""
        self.stop()  # Stop any active playback immediately

        if not wav_path.exists():
            return

        cmd = [
            "/opt/homebrew/bin/ffplay",
            "-nodisp",
            "-autoexit",
            "-loglevel", "quiet",
            str(wav_path)
        ]

        try:
            self._current_process = subprocess.Popen(
                cmd,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL
            )
            self._is_playing = True

            if blocking and self._current_process:
                self._current_process.wait()
                self._is_playing = False
                self._current_process = None
        except Exception as e:
            print(f"[AudioPlayer] Playback error: {e}")
            self._is_playing = False

    def stop(self) -> None:
        """Instantly terminates playback for barge-in / interruption (<10ms)."""
        if self._current_process is not None:
            try:
                self._current_process.terminate()
                self._current_process.kill()
            except ProcessLookupError:
                pass
            except Exception as e:
                print(f"[AudioPlayer] Stop error: {e}")
            finally:
                self._current_process = None
                self._is_playing = False
