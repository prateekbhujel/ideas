#!/usr/bin/env python3
"""
HARI Disposable Acoustic Worker.

=============================================================================
BORROWED ACOUSTIC MODEL DISCLOSURE:
1. Kokoro-v1.0-ONNX (82M open-weights neural vocoder)
   Source: Kokoro / StyleTTS-2 family (Apache 2.0 license)
2. Piper Nepali Medium (OpenSLR 43 Google CSL Nepali Speech corpus)
   Source: OpenSLR 43 (CC BY-SA 4.0 license)
Location on SSD: /Volumes/DEV-T7/Projects/hari-scratch/models/
Purpose: Acoustic synthesis and audio validation.
Replacement Path: HARI native lightweight acoustic model once developmental
language embodiment gathers sufficient personalized grounding data.
=============================================================================

This worker is a disposable IPC process communicating with PHP HARI via JSON over stdio.
PHP remains the master orchestration language.
"""

import sys
import json
import os
import signal
import subprocess
import time
from pathlib import Path
import numpy as np
import soundfile as sf

SSD_SCRATCH = Path("/Volumes/DEV-T7/Projects/hari-scratch")
KOKORO_MODEL = SSD_SCRATCH / "models/kokoro/kokoro-v1.0.onnx"
KOKORO_VOICES = SSD_SCRATCH / "models/kokoro/voices-v1.0.bin"

_kokoro_engine = None
_current_player_proc = None

def get_kokoro():
    global _kokoro_engine
    if _kokoro_engine is None:
        try:
            from kokoro_onnx import Kokoro
            if KOKORO_MODEL.exists() and KOKORO_VOICES.exists():
                _kokoro_engine = Kokoro(
                    model_path=str(KOKORO_MODEL),
                    voices_path=str(KOKORO_VOICES)
                )
        except Exception as e:
            sys.stderr.write(f"[Worker] Kokoro load error: {e}\n")
    return _kokoro_engine

def handle_synthesize(req):
    text = req.get("text", "").strip()
    voice = req.get("voice", "am_adam")
    speed = float(req.get("speed", 1.05))
    kokoro = get_kokoro()

    if not kokoro:
        return {"status": "error", "message": "Acoustic engine not initialized"}

    out_file = SSD_SCRATCH / f"speech_{int(time.time() * 1000)}.wav"
    try:
        samples, sr = kokoro.create(text, voice=voice, speed=speed)
        sf.write(str(out_file), samples, sr)
        return {
            "status": "ok",
            "audio_path": str(out_file),
            "samples": len(samples),
            "sample_rate": sr,
            "duration_s": round(len(samples) / sr, 2)
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}

def handle_play(req):
    global _current_player_proc
    path = req.get("path")
    handle_stop({})

    if not path or not os.path.exists(path):
        return {"status": "error", "message": "File not found"}

    try:
        cmd = ["/opt/homebrew/bin/ffplay", "-nodisp", "-autoexit", "-loglevel", "quiet", path]
        _current_player_proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return {"status": "ok", "playing": True}
    except Exception as e:
        return {"status": "error", "message": str(e)}

def handle_stop(req):
    global _current_player_proc
    if _current_player_proc is not None:
        try:
            _current_player_proc.terminate()
            _current_player_proc.kill()
        except Exception:
            pass
        finally:
            _current_player_proc = None
    return {"status": "ok", "stopped": True}

def handle_check_quality(req):
    path = req.get("path")
    if not path or not os.path.exists(path):
        return {"status": "error", "message": "Audio file not found"}

    try:
        data, sr = sf.read(path)
        if len(data.shape) > 1:
            data = data[:, 0] # mono
        peak = float(np.max(np.abs(data)))
        rms = float(np.sqrt(np.mean(data ** 2)))
        duration = float(len(data) / sr)

        is_clipped = peak > 0.98
        is_too_quiet = peak < 0.05
        is_usable = (not is_clipped) and (not is_too_quiet) and (duration >= 0.5)

        return {
            "status": "ok",
            "duration_s": round(duration, 2),
            "peak": round(peak, 3),
            "rms": round(rms, 3),
            "is_clipped": is_clipped,
            "is_too_quiet": is_too_quiet,
            "is_usable": is_usable,
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}

def main():
    sys.stderr.write("[Worker] HARI Speech Worker ready.\n")
    sys.stderr.flush()

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            req = json.loads(line)
            cmd = req.get("cmd")

            if cmd == "ping":
                resp = {"status": "pong", "time": time.time()}
            elif cmd == "synthesize":
                resp = handle_synthesize(req)
            elif cmd == "play":
                resp = handle_play(req)
            elif cmd == "stop":
                resp = handle_stop(req)
            elif cmd == "check_quality":
                resp = handle_check_quality(req)
            elif cmd == "quit":
                break
            else:
                resp = {"status": "error", "message": f"Unknown cmd: {cmd}"}

            sys.stdout.write(json.dumps(resp) + "\n")
            sys.stdout.flush()
        except Exception as e:
            sys.stdout.write(json.dumps({"status": "error", "message": str(e)}) + "\n")
            sys.stdout.flush()

if __name__ == "__main__":
    main()
