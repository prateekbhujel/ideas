<?php

declare(strict_types=1);

namespace Hari\Brain;

/**
 * HARI Provenance & Rationale Engine.
 * Tracks verifiable evidential lineage of actions.
 * Answers "Why did you do that?" without confabulating.
 */
class ProvenanceEngine
{
    private array $records = [];
    private int $capacity;
    private string $stateFile;

    public function __construct(int $capacity = 64, string $stateFile = '/Volumes/DEV-T7/Projects/hari-scratch/state/provenance_history.json')
    {
        $this->capacity = $capacity;
        $this->stateFile = $stateFile;
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->stateFile)) {
            $data = json_decode((string)file_get_contents($this->stateFile), true);
            if (is_array($data)) {
                $this->records = $data;
            }
        }
    }

    private function save(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->stateFile, json_encode($this->records, JSON_PRETTY_PRINT));
    }

    public function recordDecision(
        string $actionType,
        string $triggerUtterance,
        array $groundedTokens,
        array $supportingFeatures,
        float $confidence,
        float $margin,
        array $competingAlternatives,
        array $worldContext
    ): string {
        $actId = sprintf('act_%03d', count($this->records) + 1);
        $record = [
            'action_id' => $actId,
            'action_type' => $actionType,
            'timestamp' => microtime(true),
            'trigger_utterance' => $triggerUtterance,
            'grounded_tokens' => $groundedTokens,
            'supporting_features' => $supportingFeatures,
            'confidence' => $confidence,
            'margin' => $margin,
            'competing_alternatives' => $competingAlternatives,
            'world_context' => $worldContext,
        ];
        $this->records[] = $record;
        if (count($this->records) > $this->capacity) {
            array_shift($this->records);
        }
        $this->save();
        return $actId;
    }

    public function explainLatestAction(): array
    {
        if ($this->records === []) {
            return [
                'has_action' => false,
                'explanation' => 'I have not taken any physical actions yet in this session.',
            ];
        }

        $last = end($this->records);
        $tokensStr = $last['grounded_tokens'] !== []
            ? implode(', ', array_map(fn($t) => "'$t'", $last['grounded_tokens']))
            : 'direct command';

        $featDesc = [];
        foreach (array_slice($last['supporting_features'], 0, 3) as $f) {
            $featDesc[] = sprintf('%s (score %.2f)', $f['feature'] ?? 'unknown', $f['score'] ?? 0.0);
        }
        $featStr = implode('; ', $featDesc);

        $explanation = sprintf(
            "I executed %s because you said '%s'. The grounded tokens were [%s], supporting features [%s], with calibrated confidence %.2f (decision margin %.2f over alternative).",
            $last['action_type'],
            $last['trigger_utterance'],
            $tokensStr,
            $featStr,
            $last['confidence'],
            $last['margin']
        );

        return [
            'has_action' => true,
            'action_id' => $last['action_id'],
            'action_type' => $last['action_type'],
            'trigger_utterance' => $last['trigger_utterance'],
            'explanation' => $explanation,
            'supporting_features' => $last['supporting_features'],
            'alternatives_rejected' => $last['competing_alternatives'],
            'world_context' => $last['world_context'],
        ];
    }
}
