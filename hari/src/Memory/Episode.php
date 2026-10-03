<?php

declare(strict_types=1);

namespace Hari\Memory;

final class Episode
{
    /** @param list<string> $cues */
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly array $cues,
        public float $strength,
        public readonly float $importance,
        public float $confidence,
        public readonly int $createdAt,
        public int $lastAccessedAt,
        public int $accesses = 0,
    ) {
    }

    public function effectiveStrength(int $now, float $halfLife = 100.0): float
    {
        $age = max(0, $now - $this->lastAccessedAt);
        $retention = 2 ** (-$age / max(1.0, $halfLife));
        $importanceFloor = $this->importance * 0.30;

        return max($importanceFloor, $this->strength * $retention);
    }

    public function reinforce(int $now, float $amount = 0.12): void
    {
        $this->strength = min(1.0, $this->strength + $amount);
        $this->lastAccessedAt = $now;
        ++$this->accesses;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'cues' => $this->cues,
            'strength' => $this->strength,
            'importance' => $this->importance,
            'confidence' => $this->confidence,
            'created_at' => $this->createdAt,
            'last_accessed_at' => $this->lastAccessedAt,
            'accesses' => $this->accesses,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['content'],
            array_values(array_map('strval', $data['cues'] ?? [])),
            (float) $data['strength'],
            (float) $data['importance'],
            (float) $data['confidence'],
            (int) $data['created_at'],
            (int) $data['last_accessed_at'],
            (int) ($data['accesses'] ?? 0),
        );
    }
}
