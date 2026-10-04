"""
HARI Deterministic Mac Actions.
Executes low-level macOS actions using official mechanisms:
- CoreGraphics for synthetic mouse movements and clicks
- AppleScript / System Events for window manipulation and text typing
- /usr/bin/open for application launching
"""

import subprocess
import time
from typing import Dict, Any, Tuple

class MacActionEngine:
    @staticmethod
    def open_app(app_name: str) -> Dict[str, Any]:
        """Launch or activate application."""
        clean_name = app_name.strip()
        try:
            res = subprocess.run(
                ["/usr/bin/open", "-a", clean_name],
                capture_output=True,
                text=True,
                timeout=4.0
            )
            success = res.returncode == 0
            return {
                "success": success,
                "action": "OPEN_APP",
                "app": clean_name,
                "error": res.stderr.strip() if not success else None
            }
        except Exception as e:
            return {"success": False, "action": "OPEN_APP", "error": str(e)}

    @staticmethod
    def focus_window(app_name: str) -> Dict[str, Any]:
        """Bring window to front."""
        script = f'tell application "{app_name}" to activate'
        try:
            res = subprocess.run(
                ["/usr/bin/osascript", "-e", script],
                capture_output=True,
                text=True,
                timeout=3.0
            )
            return {"success": res.returncode == 0, "action": "FOCUS_WINDOW", "app": app_name}
        except Exception as e:
            return {"success": False, "action": "FOCUS_WINDOW", "error": str(e)}

    @staticmethod
    def move_window(app_name: str, position: str = "left") -> Dict[str, Any]:
        """Move and snap window to left half, right half, or center."""
        # Positions tailored for MacBook Air Display (typically 1470x956 or 1710x1112 scaled)
        if position == "left":
            x, y, w, h = 0, 25, 750, 900
        elif position == "right":
            x, y, w, h = 750, 25, 750, 900
        else:
            x, y, w, h = 200, 100, 1000, 750

        script = f'''
        tell application "System Events"
            tell process "{app_name}"
                set frontmost to true
                try
                    set position of window 1 to {{{x}, {y}}}
                    set size of window 1 to {{{w}, {h}}}
                end try
            end tell
        end tell
        '''
        try:
            res = subprocess.run(
                ["/usr/bin/osascript", "-e", script],
                capture_output=True,
                text=True,
                timeout=3.0
            )
            return {
                "success": res.returncode == 0,
                "action": "MOVE_WINDOW",
                "app": app_name,
                "position": position,
                "coords": {"x": x, "y": y, "w": w, "h": h}
            }
        except Exception as e:
            return {"success": False, "action": "MOVE_WINDOW", "error": str(e)}

    @staticmethod
    def type_text(text: str, press_enter: bool = False) -> Dict[str, Any]:
        """Type characters into active focused UI element."""
        safe_text = text.replace('"', '\\"')
        script = f'tell application "System Events" to keystroke "{safe_text}"'
        if press_enter:
            script += '\ntell application "System Events" to key code 36'

        try:
            res = subprocess.run(
                ["/usr/bin/osascript", "-e", script],
                capture_output=True,
                text=True,
                timeout=3.0
            )
            return {
                "success": res.returncode == 0,
                "action": "TYPE",
                "length": len(text),
                "pressed_enter": press_enter
            }
        except Exception as e:
            return {"success": False, "action": "TYPE", "error": str(e)}

    @staticmethod
    def move_mouse(x: int, y: int) -> Dict[str, Any]:
        """Post synthetic CoreGraphics mouse move event."""
        script = f'''
        import CoreGraphics
        let point = CGPoint(x: {x}, y: {y})
        if let event = CGEvent(mouseEventSource: nil, mouseType: .mouseMoved, mouseCursorPosition: point, mouseButton: .left) {{
            event.post(tap: .cghidEventTap)
        }}
        '''
        try:
            res = subprocess.run(
                ["/usr/bin/swift", "-e", script],
                capture_output=True,
                text=True,
                timeout=3.0
            )
            return {"success": res.returncode == 0, "action": "MOVE_POINTER", "x": x, "y": y}
        except Exception as e:
            return {"success": False, "action": "MOVE_POINTER", "error": str(e)}

    @staticmethod
    def click(x: int, y: int) -> Dict[str, Any]:
        """Post synthetic CoreGraphics mouse click event."""
        script = f'''
        import CoreGraphics
        let point = CGPoint(x: {x}, y: {y})
        if let down = CGEvent(mouseEventSource: nil, mouseType: .leftMouseDown, mouseCursorPosition: point, mouseButton: .left),
           let up = CGEvent(mouseEventSource: nil, mouseType: .leftMouseUp, mouseCursorPosition: point, mouseButton: .left) {{
            down.post(tap: .cghidEventTap)
            usleep(50000)
            up.post(tap: .cghidEventTap)
        }}
        '''
        try:
            res = subprocess.run(
                ["/usr/bin/swift", "-e", script],
                capture_output=True,
                text=True,
                timeout=3.0
            )
            return {"success": res.returncode == 0, "action": "CLICK", "x": x, "y": y}
        except Exception as e:
            return {"success": False, "action": "CLICK", "error": str(e)}
