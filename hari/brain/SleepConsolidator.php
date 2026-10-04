<?php

declare(strict_types=1);

namespace Hari\Brain;

use Hari\Brain\Memory\EpisodicMemory;
use Hari\Brain\Memory\SemanticMemory;
use Hari\Brain\Memory\ProceduralMemory;

/**
 * HARI Sleep & Idle Memory Consolidation in PHP.
 */
class SleepConsolidator
{
    private EpisodicMemory $episodic;
    private SemanticMemory $semantic;
    private ProceduralMemory $procedural;
    public float $lastConsolidation;
    public int $consolidationRuns = 0;

    public function __construct(EpisodicMemory $episodic, SemanticMemory $semantic, ProceduralMemory $procedural)
    {
        $this->episodic = $episodic;
        $this->semantic = $semantic;
        $this->procedural = $procedural;
        $this->lastConsolidation = microtime(true);
    }

    public function runCycle(): array
    {
        $t0 = microtime(true);
        $this->consolidationRuns++;

        // 1. Compress redundant episodic memories
        $episodes = $this->episodic->getEpisodes();
        $seen = [];
        $kept = [];
        $merged = 0;

        foreach ($episodes as $ep) {
            $key = strtolower(trim($ep['utterance'])) . '::' . $ep['action_taken'];
            if (isset($seen[$key])) {
                $idx = $seen[$key];
                $kept[$idx]['access_count'] += $ep['access_count'];
                $kept[$idx]['importance'] = min(5.0, $kept[$idx]['importance'] + 0.1);
                $merged++;
            } else {
                $seen[$key] = count($kept);
                $kept[] = $ep;
            }
        }
        $this->episodic->setEpisodes($kept);

        // 2. Prune low-count semantic pairs
        $prunedPairs = 0;
        foreach ($this->semantic->tokens as $tok => &$tEntry) {
            foreach ($tEntry['pairs'] as $f => $pData) {
                if (($pData['count'] ?? 0.0) < 0.05) {
                    unset($tEntry['pairs'][$f]);
                    $prunedPairs++;
                }
            }
        }
        unset($tEntry);

        // Save to SSD
        $this->semantic->save();
        $this->lastConsolidation = microtime(true);

        return [
            'run_index' => $this->consolidationRuns,
            'duration_s' => round(microtime(true) - $t0, 3),
            'episodes_merged' => $merged,
            'remaining_episodes' => $this->episodic->count(),
            'stale_pairs_pruned' => $prunedPairs,
            'active_tokens' => count($this->semantic->tokens),
            'active_features' => count($this->semantic->features),
        ];
    }
}
