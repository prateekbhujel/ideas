<?php

declare(strict_types=1);

namespace Hari\Brain\Memory;

/**
 * HARI Procedural Memory.
 * Action templates and learned routines.
 */
class ProceduralMemory
{
    private array $macros = [];
    private int $capacity;

    public function __construct(int $capacity = 128)
    {
        $this->capacity = $capacity;
        $this->initCoreProcedures();
    }

    private function initCoreProcedures(): void
    {
        $this->macros['open_app'] = [
            'name' => 'open_app',
            'desc' => 'Launch or activate application',
            'steps' => [['type' => 'OPEN_APP']],
            'success_count' => 1,
            'failure_count' => 0,
        ];
        $this->macros['move_window'] = [
            'name' => 'move_window',
            'desc' => 'Snap window to left/right/center',
            'steps' => [['type' => 'MOVE_WINDOW']],
            'success_count' => 1,
            'failure_count' => 0,
        ];
        $this->macros['type_text'] = [
            'name' => 'type_text',
            'desc' => 'Type characters without pressing Enter',
            'steps' => [['type' => 'TYPE']],
            'success_count' => 1,
            'failure_count' => 0,
        ];
    }

    public function getMacro(string $name): ?array
    {
        return $this->macros[strtolower(trim($name))] ?? null;
    }

    public function recordOutcome(string $name, bool $success): void
    {
        $clean = strtolower(trim($name));
        if (isset($this->macros[$clean])) {
            if ($success) {
                $this->macros[$clean]['success_count']++;
            } else {
                $this->macros[$clean]['failure_count']++;
            }
        }
    }

    public function listMacros(): array
    {
        return array_values($this->macros);
    }
}
