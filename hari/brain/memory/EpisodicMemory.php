<?php

declare(strict_types=1);

namespace Hari\Brain\Memory;

/**
 * HARI Episodic Memory.
 * Chronological record of interaction episodes with importance weighting,
 * recency decay, and compression of redundant episodes to maintain O(1) memory.
 */
class EpisodicMemory
{
    private array $episodes = [];
    private int $capacity;
    private int $nextId = 1;

    public function __construct(int $capacity = 512)
    {
        $this->capacity = $capacity;
    }

    public function record(
        string $utterance,
        string $actionTaken,
        string $outcome,
        array $observationBefore,
        array $observationAfter,
        float $importance = 1.0,
        string $provenance = ''
    ): array {
        // Redundant episode compression
        $uttClean = strtolower(trim($utterance));
        $recentSlice = array_slice($this->episodes, -10, 10, true);
        foreach (array_reverse($recentSlice, true) as $idx => $ep) {
            if (
                strtolower(trim($ep['utterance'])) === $uttClean
                && $ep['action_taken'] === $actionTaken
                && $ep['outcome'] === $outcome
            ) {
                $this->episodes[$idx]['access_count']++;
                $this->episodes[$idx]['last_accessed'] = microtime(true);
                $this->episodes[$idx]['importance'] = min(5.0, $this->episodes[$idx]['importance'] + 0.2);
                return $this->episodes[$idx];
            }
        }

        $epId = sprintf('ep_%04d', $this->nextId++);
        $now = microtime(true);
        $episode = [
            'id' => $epId,
            'timestamp' => $now,
            'utterance' => $utterance,
            'action_taken' => $actionTaken,
            'outcome' => $outcome,
            'observation_before' => $observationBefore,
            'observation_after' => $observationAfter,
            'importance' => $importance,
            'access_count' => 1,
            'last_accessed' => $now,
            'provenance' => $provenance,
        ];

        $this->episodes[] = $episode;
        $this->enforceBudget();
        return $episode;
    }

    private function enforceBudget(): void
    {
        if (count($this->episodes) <= $this->capacity) {
            return;
        }
        $now = microtime(true);
        // Sort by utility score
        uasort($this->episodes, function (array $a, array $b) use ($now): int {
            $scoreA = ($a['importance'] * $a['access_count']) / sqrt(max(1.0, $now - $a['last_accessed']));
            $scoreB = ($b['importance'] * $b['access_count']) / sqrt(max(1.0, $now - $b['last_accessed']));
            return $scoreB <=> $scoreA;
        });

        $this->episodes = array_slice($this->episodes, 0, $this->capacity);
        usort($this->episodes, fn(array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
    }

    public function getRecent(int $limit = 8): array
    {
        return array_slice($this->episodes, -$limit);
    }

    public function count(): int
    {
        return count($this->episodes);
    }

    public function getEpisodes(): array
    {
        return $this->episodes;
    }

    public function setEpisodes(array $episodes): void
    {
        $this->episodes = $episodes;
        $this->enforceBudget();
    }
}
