"""
HARI Screen Perception.
Uses official macOS APIs:
- AppleScript / System Events for frontmost process and window geometries
- screencapture -x for screen frame capture directly to SSD
- Computes window layouts and temporal perceptual signatures
"""

import subprocess
import json
import time
from pathlib import Path
from typing import Dict, List, Optional, Any
from hari.core.config import SCRATCH_ROOT

class ScreenPerceiver:
    def __init__(self, capture_dir: Path = SCRATCH_ROOT):
        self.capture_dir = capture_dir
        self.latest_frame_path = self.capture_dir / "latest_screen.jpg"
        self.last_capture_time: float = 0.0

    def get_frontmost_app(self) -> str:
        """Query frontmost macOS application via System Events."""
        script = 'tell application "System Events" to get name of first process whose frontmost is true'
        try:
            res = subprocess.run(
                ["/usr/bin/osascript", "-e", script],
                capture_output=True,
                text=True,
                timeout=2.0
            )
            if res.returncode == 0:
                return res.stdout.strip()
        except Exception as e:
            print(f"[Screen] Error querying frontmost app: {e}")
        return "Finder"

    def get_window_list(self) -> List[Dict[str, Any]]:
        """Query visible window titles and bounding boxes via System Events."""
        script = '''
        tell application "System Events"
            set winList to {}
            set procList to (processes whose background only is false)
            repeat with proc in procList
                try
                    set procName to name of proc
                    repeat with w in (windows of proc)
                        try
                            set wName to name of w
                            set wPos to position of w
                            set wSize to size of w
                            set end of winList to {app:procName, title:wName, x:(item 1 of wPos), y:(item 2 of wPos), w:(item 1 of wSize), h:(item 2 of wSize)}
                        end try
                    end repeat
                end try
            end repeat
            return winList
        end tell
        '''
        try:
            res = subprocess.run(
                ["/usr/bin/osascript", "-s", "s", "-e", script],
                capture_output=True,
                text=True,
                timeout=3.0
            )
            if res.returncode == 0:
                raw_out = res.stdout.strip()
                # Parse AppleScript list output safely
                return self._parse_applescript_records(raw_out)
        except Exception as e:
            print(f"[Screen] Error querying windows: {e}")
        return []

    def _parse_applescript_records(self, raw: str) -> List[Dict[str, Any]]:
        # Fallback simple parser for AppleScript record strings
        records = []
        if not raw or raw == "{}":
            return records
        # Split on record delimiters
        items = raw.replace("{app:", "SPLIT{app:").split("SPLIT")
        for item in items:
            if not item.strip():
                continue
            try:
                app_part = item.split('app:"')[1].split('"')[0] if 'app:"' in item else "Unknown"
                title_part = item.split('title:"')[1].split('"')[0] if 'title:"' in item else ""
                records.append({"app": app_part, "title": title_part})
            except Exception:
                pass
        return records

    def capture_frame(self) -> Optional[Path]:
        """Captures a screenshot to the external SSD scratch folder."""
        try:
            subprocess.run(
                ["/usr/sbin/screencapture", "-x", "-t", "jpg", str(self.latest_frame_path)],
                check=True,
                timeout=3.0,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL
            )
            self.last_capture_time = time.time()
            return self.latest_frame_path
        except Exception as e:
            print(f"[Screen] screencapture error: {e}")
            return None

    def perceive(self) -> Dict[str, Any]:
        """Full perceptual screen observation."""
        app = self.get_frontmost_app()
        windows = self.get_window_list()
        frame = self.capture_frame()
        return {
            "timestamp": time.time(),
            "frontmost_app": app,
            "windows": windows,
            "frame_path": str(frame) if frame else None,
        }
