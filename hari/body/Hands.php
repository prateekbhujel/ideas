<?php

declare(strict_types=1);

namespace Hari\Body;

/**
 * HARI Deterministic Mac Actions in PHP.
 * Executes low-level macOS actions using official mechanisms:
 * - /usr/bin/open for launching applications
 * - /usr/bin/osascript for window management and typing
 * - /usr/bin/swift for CoreGraphics synthetic mouse events
 */
class Hands
{
    public static function openApp(string $appName): array
    {
        $app = escapeshellarg(trim($appName));
        exec("/usr/bin/open -a {$app} 2>&1", $output, $code);
        return [
            'success' => $code === 0,
            'action' => 'OPEN_APP',
            'app' => $appName,
            'output' => implode("\n", $output),
        ];
    }

    public static function focusWindow(string $appName): array
    {
        $safeApp = addslashes(trim($appName));
        $script = "tell application \"{$safeApp}\" to activate";
        $cmd = '/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        return [
            'success' => $code === 0,
            'action' => 'FOCUS_WINDOW',
            'app' => $appName,
        ];
    }

    public static function moveWindow(string $appName, string $position = 'left'): array
    {
        if ($position === 'left') {
            [$x, $y, $w, $h] = [0, 25, 750, 900];
        } elseif ($position === 'right') {
            [$x, $y, $w, $h] = [750, 25, 750, 900];
        } else {
            [$x, $y, $w, $h] = [200, 100, 1000, 750];
        }

        $safeApp = addslashes(trim($appName));
        $script = <<<APPLESCRIPT
        tell application "System Events"
            tell process "{$safeApp}"
                set frontmost to true
                try
                    set position of window 1 to {{$x}, {$y}}
                    set size of window 1 to {{$w}, {$h}}
                end try
            end tell
        end tell
        APPLESCRIPT;

        $cmd = '/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        return [
            'success' => $code === 0,
            'action' => 'MOVE_WINDOW',
            'app' => $appName,
            'position' => $position,
            'bounds' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h],
        ];
    }

    public static function typeText(string $text, bool $pressEnter = false): array
    {
        $safeText = addslashes($text);
        $script = "tell application \"System Events\" to keystroke \"{$safeText}\"";
        if ($pressEnter) {
            $script .= "\ntell application \"System Events\" to key code 36";
        }

        $cmd = '/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>&1';
        exec($cmd, $output, $code);
        return [
            'success' => $code === 0,
            'action' => 'TYPE',
            'length' => strlen($text),
            'pressed_enter' => $pressEnter,
        ];
    }

    public static function movePointer(int $x, int $y): array
    {
        $swift = <<<SWIFT
        import CoreGraphics
        let point = CGPoint(x: {$x}, y: {$y})
        if let event = CGEvent(mouseEventSource: nil, mouseType: .mouseMoved, mouseCursorPosition: point, mouseButton: .left) {
            event.post(tap: .cghidEventTap)
        }
        SWIFT;

        $cmd = '/usr/bin/swift -e ' . escapeshellarg($swift) . ' 2>&1';
        exec($cmd, $output, $code);
        return ['success' => $code === 0, 'action' => 'MOVE_POINTER', 'x' => $x, 'y' => $y];
    }

    public static function click(int $x, int $y): array
    {
        $swift = <<<SWIFT
        import CoreGraphics
        let point = CGPoint(x: {$x}, y: {$y})
        if let down = CGEvent(mouseEventSource: nil, mouseType: .leftMouseDown, mouseCursorPosition: point, mouseButton: .left),
           let up = CGEvent(mouseEventSource: nil, mouseType: .leftMouseUp, mouseCursorPosition: point, mouseButton: .left) {
            down.post(tap: .cghidEventTap)
            usleep(50000)
            up.post(tap: .cghidEventTap)
        }
        SWIFT;

        $cmd = '/usr/bin/swift -e ' . escapeshellarg($swift) . ' 2>&1';
        exec($cmd, $output, $code);
        return ['success' => $code === 0, 'action' => 'CLICK', 'x' => $x, 'y' => $y];
    }
}
