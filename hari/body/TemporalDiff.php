<?php

declare(strict_types=1);

namespace Hari\Body;

/**
 * HARI Temporal Difference Tracker in PHP.
 * Extracts: what appeared, disappeared, moved, changed, and stayed stable.
 */
class TemporalDiff
{
    private ?array $lastState = null;

    public function computeDelta(array $currentState): array
    {
        $now = microtime(true);
        if ($this->lastState === null) {
            $this->lastState = $currentState;
            return [
                'timestamp' => $now,
                'appeared' => [],
                'disappeared' => [],
                'moved' => [],
                'modified' => [],
                'stable' => [],
            ];
        }

        $appeared = [];
        $disappeared = [];
        $moved = [];
        $modified = [];
        $stable = [];

        // Compare frontmost app
        $prevApp = $this->lastState['frontmost_app'] ?? null;
        $currApp = $currentState['frontmost_app'] ?? null;
        if ($prevApp !== $currApp) {
            $modified[] = ['attr' => 'frontmost_app', 'from' => $prevApp, 'to' => $currApp];
        }

        // Compare open windows
        $prevWins = array_column($this->lastState['windows'] ?? [], 'title');
        $currWins = array_column($currentState['windows'] ?? [], 'title');

        foreach ($currWins as $w) {
            if (!in_array($w, $prevWins, true)) {
                $appeared[] = "window:{$w}";
            } else {
                $stable[] = "window:{$w}";
            }
        }
        foreach ($prevWins as $w) {
            if (!in_array($w, $currWins, true)) {
                $disappeared[] = "window:{$w}";
            }
        }

        $this->lastState = $currentState;
        return [
            'timestamp' => $now,
            'appeared' => $appeared,
            'disappeared' => $disappeared,
            'moved' => $moved,
            'modified' => $modified,
            'stable' => $stable,
        ];
    }
}
