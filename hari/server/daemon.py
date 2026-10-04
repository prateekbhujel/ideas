"""
HARI Daemon & Server.
Coordinates Body (Senses, Voice, Hands) with Brain (Mind, Memory, Epistemic Engine).
Runs a local async HTTP + WebSocket event server at http://127.0.0.1:8765.
"""

import asyncio
import json
import time
import os
from pathlib import Path
from typing import Dict, List, Set, Optional, Any
import websockets
from http.server import HTTPServer, SimpleHTTPRequestHandler
import threading

from hari.core.config import SERVER_HOST, SERVER_PORT, SCRATCH_ROOT
from hari.brain.mind import Mind
from hari.body.eyes.screen import ScreenPerceiver
from hari.body.eyes.camera import CameraPerceiver
from hari.body.eyes.temporal_diff import TemporalDiffTracker
from hari.body.voice.synthesizer import VoiceSynthesizer
from hari.body.voice.player import InterruptibleAudioPlayer
from hari.body.ears.turn_manager import TurnManager
from hari.body.hands.executor import ActionExecutor

class HariDaemon:
    def __init__(self):
        # 1. Cognitive Brain
        self.mind = Mind()

        # 2. Physical Body Senses & Actuators
        self.screen = ScreenPerceiver()
        self.camera = CameraPerceiver()
        self.temporal_diff = TemporalDiffTracker()
        self.voice = VoiceSynthesizer()
        self.audio_player = InterruptibleAudioPlayer()
        self.hands = ActionExecutor()

        # 3. Conversational Audio & Turn Manager
        self.turn_manager = TurnManager(on_barge_in=self._handle_barge_in)

        # 4. WebSocket Clients
        self.connected_clients: Set[websockets.WebSocketServerProtocol] = set()

        # 5. Sensor Toggles
        self.sensor_toggles = {
            "mic": True,
            "camera": True,
            "screen": True,
            "hands": True,
        }

        # 6. Idle timer for consolidation
        self.last_interaction_time = time.time()
        self._is_running = True

    def _handle_barge_in(self) -> None:
        """Immediate cut-off when user interrupts (<10ms)."""
        print("\n[Barge-In] User interrupted! Stopping audio immediately.")
        self.audio_player.stop()
        self.mind.working_memory.flag_interrupted()
        # Broadcast interrupt signal to all connected clients
        asyncio.create_task(self.broadcast({"type": "interrupt", "timestamp": time.time()}))

    async def broadcast(self, message: Dict[str, Any]) -> None:
        if not self.connected_clients:
            return
        payload = json.dumps(message)
        dead_clients = set()
        for client in self.connected_clients:
            try:
                await client.send(payload)
            except Exception:
                dead_clients.add(client)
        self.connected_clients.difference_update(dead_clients)

    async def process_user_input(
        self,
        text: str,
        camera_snapshot_b64: Optional[str] = None,
        is_correction: bool = False
    ) -> Dict[str, Any]:
        """Full cognitive lifecycle for an incoming user interaction."""
        self.last_interaction_time = time.time()

        # 1. Gather live screen observation
        screen_obs = {}
        if self.sensor_toggles["screen"]:
            screen_obs = self.screen.perceive()

        # 2. Gather camera observation
        camera_obs = {}
        if camera_snapshot_b64 and self.sensor_toggles["camera"]:
            camera_obs = self.camera.ingest_frame_b64(camera_snapshot_b64)
        elif self.sensor_toggles["camera"]:
            camera_obs = self.camera.get_summary()

        # 3. Compute temporal delta
        current_world_snapshot = {
            "frontmost_app": screen_obs.get("frontmost_app"),
            "windows": screen_obs.get("windows", []),
            "camera_objects": camera_obs.get("detected_objects", [])
        }
        delta = self.temporal_diff.compute_delta(current_world_snapshot)

        # 4. Process in Cognitive Mind
        self.mind.status = "THINKING"
        await self.broadcast({"type": "status_update", "status": "THINKING", "user_text": text})

        cognitive_res = self.mind.process_utterance(
            utterance=text,
            screen_context=screen_obs,
            camera_context=camera_obs,
            is_correction=is_correction
        )

        spoken_reply = cognitive_res.get("spoken_reply", "")
        plan = cognitive_res.get("plan")
        epistemic = cognitive_res.get("epistemic")

        # 5. Execute Action if present and Hands are enabled
        action_res = None
        if plan and self.sensor_toggles["hands"]:
            action_res = self.hands.execute(plan)

        # 6. Synthesize & Play Voice aloud
        wav_path = None
        if spoken_reply:
            self.turn_manager.on_hari_speech_start()
            self.mind.status = "SPEAKING"
            await self.broadcast({
                "type": "hari_speech_start",
                "text": spoken_reply,
                "plan": plan.__dict__ if plan else None,
                "action_result": action_res,
                "epistemic": epistemic.__dict__ if epistemic else None,
            })

            samples, sample_rate, wav_path = self.voice.synthesize(spoken_reply)
            if wav_path and self.voice.is_ready:
                # Play audio asynchronously on local speakers
                self.audio_player.play_wav(wav_path, blocking=False)

            self.turn_manager.on_hari_speech_end()
            self.mind.status = "ALIVE"
            await self.broadcast({"type": "hari_speech_end"})

        # Broadcast state update
        mind_summary = self.mind.get_mind_state_summary()
        await self.broadcast({
            "type": "mind_state_update",
            "state": mind_summary.__dict__,
            "delta": delta.__dict__ if delta else None,
            "reply": spoken_reply,
            "action_result": action_res
        })

        return {
            "reply": spoken_reply,
            "plan": plan.__dict__ if plan else None,
            "action_result": action_res,
            "audio_file": str(wav_path) if wav_path else None
        }

    async def handle_ws_client(self, websocket: websockets.WebSocketServerProtocol):
        self.connected_clients.add(websocket)
        print(f"[WS] Client connected. Total active: {len(self.connected_clients)}")

        # Send initial full state
        mind_summary = self.mind.get_mind_state_summary()
        await websocket.send(json.dumps({
            "type": "init",
            "state": mind_summary.__dict__,
            "sensor_toggles": self.sensor_toggles
        }))

        try:
            async for message in websocket:
                data = json.loads(message)
                mtype = data.get("type")

                if mtype == "user_speech_start":
                    self.turn_manager.on_user_speech_start()
                    await self.broadcast({"type": "user_speaking"})

                elif mtype == "user_speech_chunk":
                    self.turn_manager.on_user_speech_chunk()

                elif mtype == "user_utterance":
                    text = data.get("text", "").strip()
                    cam_b64 = data.get("camera_b64")
                    is_corr = data.get("is_correction", False)
                    if text:
                        await self.process_user_input(text, cam_b64, is_corr)

                elif mtype == "confirm_action":
                    action_id = data.get("action_id")
                    if self.hands.action_history:
                        last = self.hands.action_history[-1]
                        res = self.hands.execute(last["plan"], confirmed=True)
                        await self.broadcast({"type": "action_confirmed", "result": res})

                elif mtype == "toggle_sensor":
                    sensor = data.get("sensor")
                    val = data.get("value")
                    if sensor in self.sensor_toggles:
                        self.sensor_toggles[sensor] = val
                        await self.broadcast({"type": "sensor_toggles", "toggles": self.sensor_toggles})

                elif mtype == "barge_in":
                    self._handle_barge_in()

        except websockets.exceptions.ConnectionClosed:
            pass
        finally:
            self.connected_clients.discard(websocket)
            print(f"[WS] Client disconnected. Total active: {len(self.connected_clients)}")

    async def background_loop(self):
        """Periodic background loop for perception updates and idle consolidation."""
        while self._is_running:
            await asyncio.sleep(2.0)
            now = time.time()

            # Check for turn completion if user stopped speaking
            if self.turn_manager.check_turn_completion():
                pass

            # Idle consolidation after 30s of silence
            if (now - self.last_interaction_time) > 30.0:
                if (now - self.mind.sleep_consolidator.last_consolidation) > 60.0:
                    consolidation_res = self.mind.sleep_consolidator.run_cycle()
                    await self.broadcast({"type": "consolidation_complete", "result": consolidation_res})

            # Broadcast regular heartbeat
            mind_summary = self.mind.get_mind_state_summary()
            await self.broadcast({"type": "heartbeat", "state": mind_summary.__dict__})
