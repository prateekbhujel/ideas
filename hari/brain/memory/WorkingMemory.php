<?php

declare(strict_types=1);

namespace Hari\Brain\Memory;

/**
 * HARI Working Memory.
 * Holds active situational context, dialogue history, current attentional focus,
 * and discourse referents (e.g. 'this', 'that window', 'Terminal').
 * Bounded by hard capacity of 32 turns.
 */
class WorkingMemory
{
    private array $turns = [];
    private int $maxTurns;
    public ?string $activeFocus = null;
    public array $activeReferents = [];
    public ?string $currentGoal = null;

    public function __construct(int $maxTurns = 32)
    {
        $this->maxTurns = $maxTurns;
    }

    public function addTurn(string $speaker, string $text, ?string $associatedAction = null): array
    {
        $turn = [
            'speaker' => $speaker,
            'text' => trim($text),
            'timestamp' => microtime(true),
            'interrupted' => false,
            'associated_action' => $associatedAction,
        ];
        $this->turns[] = $turn;
        if (count($this->turns) > $this->maxTurns) {
            array_shift($this->turns);
        }
        return $turn;
    }

    public function flagInterrupted(): void
    {
        for ($i = count($this->turns) - 1; $i >= 0; $i--) {
            if ($this->turns[$i]['speaker'] === 'hari') {
                $this->turns[$i]['interrupted'] = true;
                break;
            }
        }
    }

    public function setFocus(string $focusName, ?array $referentData = null): void
    {
        $this->activeFocus = $focusName;
        if ($referentData !== null) {
            $this->activeReferents[$focusName] = $referentData;
            $this->activeReferents['this'] = $referentData;
            $this->activeReferents['it'] = $referentData;
            $this->activeReferents['tyo'] = $referentData; // Nepali: 'त्यो'
            $this->activeReferents['yo'] = $referentData;  // Nepali: 'यो'
        }
    }

    public function getReferent(string $term): ?array
    {
        $clean = strtolower(trim($term));
        if (isset($this->activeReferents[$clean])) {
            return $this->activeReferents[$clean];
        }
        if (in_array($clean, ['this', 'it', 'that', 'yo', 'tyo'], true) && $this->activeFocus !== null) {
            return $this->activeReferents[$this->activeFocus] ?? null;
        }
        return null;
    }

    public function getContextSummary(): array
    {
        return [
            'turn_count' => count($this->turns),
            'recent_dialogue' => array_slice($this->turns, -6),
            'active_focus' => $this->activeFocus,
            'current_goal' => $this->currentGoal,
        ];
    }

    public function getTurns(): array
    {
        return $this->turns;
    }
}
