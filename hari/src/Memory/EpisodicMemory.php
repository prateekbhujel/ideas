<?php

declare(strict_types=1);

namespace Hari\Memory;

use Hari\Language\TextVector;

final class EpisodicMemory
{
    /** @var array<string,Episode> */
    private array $episodes = [];
    private int $clock = 0;
    private int $nextId = 1;

    /** @param list<string> $cues */
    public function remember(
        string $content,
        array $cues,
        float $importance = 0.5,
        float $confidence = 1.0,
    ): Episode {
        $importance = $this->unit($importance, 'importance');
        $confidence = $this->unit($confidence, 'confidence');
        $normalizedCues = $this->normalizeCues($cues);

        $episode = new Episode(
            'e' . $this->nextId++,
            trim($content),
            $normalizedCues,
            1.0,
            $importance,
            $confidence,
            $this->clock,
            $this->clock,
        );

        $this->episodes[$episode->id] = $episode;
        return $episode;
    }

    public function tick(int $steps = 1): void
    {
        if ($steps < 0) {
            throw new \InvalidArgumentException('Clock cannot move backwards.');
        }
        $this->clock += $steps;
    }

    /**
     * @param list<string> $cues
     * @return array{episode:Episode,score:float,strength:float}|null
     */
    public function recall(array $cues, float $minimumScore = 0.18, bool $reinforce = true): ?array
    {
        $query = $this->normalizeCues($cues);
        if ($query === []) {
            return null;
        }

        $best = null;
        foreach ($this->episodes as $episode) {
            $cueScore = $this->cueSimilarity($query, $episode->cues);
            if ($cueScore <= 0.0) {
                continue;
            }

            $strength = $episode->effectiveStrength($this->clock);
            $score = $cueScore * $strength * $episode->confidence;
            if ($best === null || $score > $best['score']) {
                $best = ['episode' => $episode, 'score' => $score, 'strength' => $strength];
            }
        }

        if ($best === null || $best['score'] < $minimumScore) {
            return null;
        }

        if ($reinforce) {
            $best['episode']->reinforce($this->clock);
            $best['strength'] = $best['episode']->effectiveStrength($this->clock);
        }

        return $best;
    }

    public function forgetWeak(float $threshold = 0.08): int
    {
        $removed = 0;
        foreach ($this->episodes as $id => $episode) {
            if ($episode->effectiveStrength($this->clock) < $threshold) {
                unset($this->episodes[$id]);
                ++$removed;
            }
        }
        return $removed;
    }

    /** @return list<Episode> */
    public function episodes(): array
    {
        return array_values($this->episodes);
    }

    /** @return array{clock:int,next_id:int,episodes:list<array<string,mixed>>} */
    public function export(): array
    {
        return [
            'clock' => $this->clock,
            'next_id' => $this->nextId,
            'episodes' => array_map(static fn (Episode $e): array => $e->toArray(), $this->episodes()),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function import(array $data): self
    {
        $memory = new self();
        $memory->clock = max(0, (int) ($data['clock'] ?? 0));
        $memory->nextId = max(1, (int) ($data['next_id'] ?? 1));

        foreach (($data['episodes'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $episode = Episode::fromArray($row);
            $memory->episodes[$episode->id] = $episode;
        }

        return $memory;
    }

    /** @param list<string> $cues @return list<string> */
    private function normalizeCues(array $cues): array
    {
        $out = [];
        foreach ($cues as $cue) {
            $cue = TextVector::normalize((string) $cue);
            if ($cue !== '') $out[$cue] = true;
        }
        return array_keys($out);
    }

    /** @param list<string> $a @param list<string> $b */
    private function cueSimilarity(array $a, array $b): float
    {
        $best = 0.0;
        foreach ($a as $queryCue) {
            $queryVector = TextVector::fromText($queryCue);
            foreach ($b as $memoryCue) {
                if ($queryCue === $memoryCue) {
                    $best = max($best, 1.0);
                    continue;
                }
                $best = max($best, $queryVector->similarity(TextVector::fromText($memoryCue)));
            }
        }
        return $best;
    }

    private function unit(float $value, string $name): float
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new \InvalidArgumentException("{$name} must be between 0 and 1.");
        }
        return $value;
    }
}
