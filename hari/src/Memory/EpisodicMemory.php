<?php

declare(strict_types=1);

namespace Hari\Memory;

use Hari\Language\TextVector;

final class EpisodicMemory
{
    /** @var array<string, Episode> */
    private array $episodes = [];

    public function __construct(
        private int $clock = 0,
        private readonly float $decayPerTick = 0.012,
        private readonly float $forgetBelow = 0.04,
    ) {
        if ($decayPerTick < 0.0 || $forgetBelow < 0.0 || $forgetBelow > 1.0) {
            throw new \InvalidArgumentException('Invalid memory parameters.');
        }
    }

    /** @param list<string> $tags */
    public function remember(
        string $text,
        array $tags = [],
        string $source = 'user',
        float $importance = 0.5,
        float $confidence = 1.0,
        ?string $id = null,
    ): Episode {
        $id ??= hash('sha256', $text . "\0" . $source . "\0" . $this->clock . "\0" . count($this->episodes));
        $episode = new Episode(
            $id,
            $text,
            array_values(array_unique(array_map([TextVector::class, 'normalize'], $tags))),
            $source,
            $this->clock,
            $this->clock,
            1.0,
            $importance,
            $confidence,
        );
        $this->episodes[$id] = $episode;
        return $episode;
    }

    public function advance(int $ticks = 1): void
    {
        if ($ticks < 0) {
            throw new \InvalidArgumentException('Cannot move memory clock backwards.');
        }
        $this->clock += $ticks;
        foreach ($this->episodes as $episode) {
            $episode->decayTo($this->clock, $this->decayPerTick);
        }
    }

    /** @param list<string> $tags
     *  @return list<array{episode:Episode,score:float}>
     */
    public function recall(string $query, array $tags = [], int $limit = 5, bool $reinforce = true): array
    {
        if ($limit < 1) return [];

        $queryVector = TextVector::fromText($query . ' ' . implode(' ', $tags));
        $normalizedTags = array_values(array_unique(array_map([TextVector::class, 'normalize'], $tags)));
        $scored = [];

        foreach ($this->episodes as $episode) {
            if ($episode->strength < $this->forgetBelow) continue;

            $semantic = $queryVector->similarity($episode->vector);
            $tagMatches = count(array_intersect($normalizedTags, $episode->tags));
            $tagScore = $normalizedTags === [] ? 0.0 : $tagMatches / count($normalizedTags);
            $recency = 1.0 / (1.0 + max(0, $this->clock - $episode->lastAccessedAt));

            $score = ($semantic * 0.52)
                + ($tagScore * 0.18)
                + ($episode->strength * 0.12)
                + ($episode->importance * 0.08)
                + ($episode->confidence * 0.06)
                + ($recency * 0.04);

            $scored[] = ['episode' => $episode, 'score' => $score];
        }

        usort($scored, static function (array $a, array $b): int {
            $score = $b['score'] <=> $a['score'];
            return $score !== 0 ? $score : ($b['episode']->lastAccessedAt <=> $a['episode']->lastAccessedAt);
        });
        $scored = array_slice($scored, 0, $limit);

        if ($reinforce && $scored !== []) {
            $scored[0]['episode']->reinforce($this->clock);
        }
        return $scored;
    }

    public function forgetWeak(): int
    {
        $removed = 0;
        foreach ($this->episodes as $id => $episode) {
            if ($episode->strength < $this->forgetBelow && $episode->importance < 0.85) {
                unset($this->episodes[$id]);
                ++$removed;
            }
        }
        return $removed;
    }

    public function reinforce(string $id, float $amount = 0.15): void
    {
        ($this->episodes[$id] ?? throw new \OutOfBoundsException("Unknown episode {$id}."))
            ->reinforce($this->clock, $amount);
    }

    public function clock(): int { return $this->clock; }

    /** @return list<Episode> */
    public function episodes(): array { return array_values($this->episodes); }

    /** @return array{clock:int,episodes:list<array<string,mixed>>} */
    public function export(): array
    {
        return [
            'clock' => $this->clock,
            'episodes' => array_map(static fn (Episode $e): array => $e->export(), $this->episodes()),
        ];
    }

    /** @param array{clock?:int,episodes?:array<int,array<string,mixed>>} $data */
    public static function import(array $data, float $decayPerTick = 0.012, float $forgetBelow = 0.04): self
    {
        $memory = new self((int) ($data['clock'] ?? 0), $decayPerTick, $forgetBelow);
        foreach ((array) ($data['episodes'] ?? []) as $episodeData) {
            $episode = Episode::import($episodeData);
            $memory->episodes[$episode->id] = $episode;
        }
        return $memory;
    }
}
