<?php

declare(strict_types=1);

namespace Hari\Learning;

final class HabitLearner
{
    /** @var array<string, array<string,int>> */
    private array $counts = [];

    public function observe(string $context, string $choice): void
    {
        $context = trim($context);
        $choice = trim($choice);
        if ($context === '' || $choice === '') {
            throw new \InvalidArgumentException('Context and choice are required.');
        }
        $this->counts[$context][$choice] = ($this->counts[$context][$choice] ?? 0) + 1;
    }

    /** @return array{choice:string,confidence:float,observations:int}|null */
    public function predict(string $context, int $minimumObservations = 3): ?array
    {
        $choices = $this->counts[$context] ?? [];
        $total = array_sum($choices);
        if ($total < $minimumObservations || $choices === []) return null;

        arsort($choices);
        $choice = (string) array_key_first($choices);
        $count = (int) $choices[$choice];
        $confidence = ($count + 1) / ($total + count($choices));

        return ['choice' => $choice, 'confidence' => $confidence, 'observations' => $total];
    }

    /** @return array<string,array<string,int>> */
    public function export(): array { return $this->counts; }

    /** @param array<string,array<string,int>> $data */
    public static function import(array $data): self
    {
        $learner = new self();
        $learner->counts = $data;
        return $learner;
    }
}
