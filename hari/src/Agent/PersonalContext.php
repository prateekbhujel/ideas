<?php

declare(strict_types=1);

namespace Hari\Agent;

use Hari\Learning\HabitLearner;
use Hari\Memory\EpisodicMemory;

final class PersonalContext
{
    public function __construct(
        public readonly EpisodicMemory $memory = new EpisodicMemory(),
        public readonly HabitLearner $habits = new HabitLearner(),
    ) {
    }

    /** @param list<string> $tags */
    public function learnFact(string $text, array $tags = [], float $importance = 0.7): string
    {
        return $this->memory->remember($text, $tags, 'user', $importance)->id;
    }

    public function observeChoice(string $context, string $choice): void
    {
        $this->habits->observe($context, $choice);
    }

    /** @return array{choice:string,confidence:float,observations:int}|null */
    public function preferredChoice(string $context): ?array
    {
        return $this->habits->predict($context);
    }
}
