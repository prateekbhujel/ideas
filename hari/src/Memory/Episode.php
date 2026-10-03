<?php

declare(strict_types=1);

namespace Hari\Memory;

use Hari\Language\TextVector;

final class Episode
{
    public readonly TextVector $vector;

    /** @param list<string> $tags */
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly array $tags,
        public readonly string $source,
        public readonly int $createdAt,
        public int $lastAccessedAt,
        public float $strength = 1.0,
        public float $importance = 0.5,
        public float $confidence = 1.0,
        public int $accessCount = 0,
    ) {
        if ($id === '' || $text === '') {
            throw new \InvalidArgumentException('Episode id and text are required.');
        }
        foreach ([$strength, $importance, $confidence] as $value) {
            if ($value < 0.0 || $value > 1.0) {
                throw new \InvalidArgumentException('Episode weights must be between 0 and 1.');
            }
        }
        $this->vector = TextVector::fromText($text . ' ' . implode(' ', $tags));
    }

    public function decayTo(int $now, float $decayPerTick): void
    {
        if ($now <= $this->lastAccessedAt || $decayPerTick <= 0.0) {
            return;
        }

        $age = $now - $this->lastAccessedAt;
        $retention = exp(-$decayPerTick * $age * (1.25 - (0.75 * $this->importance)));
        $this->strength = max(0.0, min(1.0, $this->strength * $retention));
    }

    public function reinforce(int $now, float $amount = 0.15): void
    {
        $this->strength = min(1.0, $this->strength + max(0.0, $amount));
        ++$this->accessCount;
        $this->lastAccessedAt = $now;
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'tags' => $this->tags,
            'source' => $this->source,
            'createdAt' => $this->createdAt,
            'lastAccessedAt' => $this->lastAccessedAt,
            'strength' => $this->strength,
            'importance' => $this->importance,
            'confidence' => $this->confidence,
            'accessCount' => $this->accessCount,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function import(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['text'],
            array_values(array_map('strval', (array) ($data['tags'] ?? []))),
            (string) ($data['source'] ?? 'unknown'),
            (int) $data['createdAt'],
            (int) $data['lastAccessedAt'],
            (float) $data['strength'],
            (float) $data['importance'],
            (float) $data['confidence'],
            (int) ($data['accessCount'] ?? 0),
        );
    }
}
