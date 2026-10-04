<?php

declare(strict_types=1);

namespace Hari\Body;

/**
 * HARI Eyes (Screen & Camera Perception) in PHP.
 * Uses official macOS screencapture and System Events.
 */
class Eyes
{
    private string $scratchDir;
    public string $latestScreenPath;
    public string $latestCameraPath;

    public function __construct(string $scratchDir = '/Volumes/DEV-T7/Projects/hari-scratch')
    {
        $this->scratchDir = $scratchDir;
        $this->latestScreenPath = $scratchDir . '/latest_screen.jpg';
        $this->latestCameraPath = $scratchDir . '/latest_camera.jpg';
    }

    public function getFrontmostApp(): string
    {
        $script = 'tell application "System Events" to get name of first process whose frontmost is true';
        $cmd = '/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>/dev/null';
        $res = trim((string)shell_exec($cmd));
        return $res !== '' ? $res : 'Finder';
    }

    public function getWindowList(): array
    {
        $script = <<<APPLESCRIPT
        tell application "System Events"
            set winList to {}
            set procList to (processes whose background only is false)
            repeat with proc in procList
                try
                    set procName to name of proc
                    repeat with w in (windows of proc)
                        try
                            set wName to name of w
                            set end of winList to procName & ":::" & wName
                        end try
                    end repeat
                end try
            end repeat
            return winList
        end tell
        APPLESCRIPT;

        $cmd = '/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>/dev/null';
        $out = trim((string)shell_exec($cmd));
        $windows = [];
        if ($out !== '') {
            $lines = explode(', ', $out);
            foreach ($lines as $line) {
                if (str_contains($line, ':::')) {
                    [$app, $title] = explode(':::', $line, 2);
                    $windows[] = ['app' => trim($app), 'title' => trim($title)];
                }
            }
        }
        return $windows;
    }

    public function captureScreen(): ?string
    {
        $cmd = "/usr/sbin/screencapture -x -t jpg " . escapeshellarg($this->latestScreenPath) . ' 2>/dev/null';
        exec($cmd, $output, $code);
        return $code === 0 && file_exists($this->latestScreenPath) ? $this->latestScreenPath : null;
    }

    public function perceive(): array
    {
        $app = $this->getFrontmostApp();
        $windows = $this->getWindowList();
        $frame = $this->captureScreen();
        return [
            'timestamp' => microtime(true),
            'frontmost_app' => $app,
            'windows' => $windows,
            'frame_path' => $frame,
        ];
    }

    public function ingestCameraB64(string $b64, ?string $labelHint = null): array
    {
        if (str_contains($b64, ',')) {
            $b64 = explode(',', $b64, 2)[1];
        }
        $raw = base64_decode($b64);
        file_put_contents($this->latestCameraPath, $raw);
        $objLabel = $labelHint ?? 'held_object';
        return [
            'timestamp' => microtime(true),
            'detected_objects' => [
                ['id' => 'cam_1', 'label' => $objLabel, 'confidence' => 0.88, 'held' => true]
            ],
            'frame_path' => $this->latestCameraPath,
        ];
    }
}
