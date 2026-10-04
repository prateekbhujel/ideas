<?php

declare(strict_types=1);

namespace Hari\Brain\Memory;

/**
 * HARI World State.
 * Current verified beliefs about the user's environment.
 * Strictly separates: OBSERVATION vs INFERENCE vs HYPOTHESIS vs KNOWN_FACT.
 */
class WorldState
{
    public ?string $frontmostApp = null;
    public array $openWindows = [];
    public array $cameraObjects = [];
    public array $beliefs = [];
    public float $lastScreenUpdate = 0.0;
    public float $lastCameraUpdate = 0.0;

    public function updateScreenState(string $frontmostApp, array $windows): void
    {
        $this->frontmostApp = $frontmostApp;
        $this->openWindows = $windows;
        $this->lastScreenUpdate = microtime(true);

        $this->setBelief('active_application', $frontmostApp, 'OBSERVATION', 1.0, 'macos_system_events');
        $this->setBelief('open_windows_count', count($windows), 'OBSERVATION', 1.0, 'macos_window_list');
    }

    public function updateCameraState(array $detectedObjects): void
    {
        $this->cameraObjects = $detectedObjects;
        $this->lastCameraUpdate = microtime(true);
        $labels = array_map(fn($o) => $o['label'] ?? 'unknown', $detectedObjects);
        $this->setBelief('held_objects', $labels, 'INFERENCE', $detectedObjects !== [] ? 0.85 : 0.0, 'camera_perception');
    }

    public function setBelief(string $key, mixed $value, string $classification, float $confidence, string $source): void
    {
        $this->beliefs[$key] = [
            'key' => $key,
            'value' => $value,
            'class' => $classification,
            'confidence' => $confidence,
            'source' => $source,
            'timestamp' => microtime(true),
        ];
    }

    public function getSummary(): array
    {
        return [
            'frontmost_app' => $this->frontmostApp,
            'window_count' => count($this->openWindows),
            'windows' => array_slice($this->openWindows, 0, 5),
            'camera_objects' => $this->cameraObjects,
            'active_beliefs' => array_values($this->beliefs),
            'screen_age_s' => $this->lastScreenUpdate > 0 ? round(microtime(true) - $this->lastScreenUpdate, 1) : null,
            'camera_age_s' => $this->lastCameraUpdate > 0 ? round(microtime(true) - $this->lastCameraUpdate, 1) : null,
        ];
    }
}
