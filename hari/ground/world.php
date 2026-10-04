<?php

declare(strict_types=1);

namespace Hari\Ground;

/**
 * An unordered perceptual frame representing detected physical entities.
 * Entities have NO persistent ID labels; they are bare bundles of discrete attributes.
 */
final readonly class AnonymousWorld
{
    /** @param list<array<string,string>> $entities */
    public function __construct(public array $entities) {}

    public static function create(array $entities): self
    {
        // Shuffle to enforce that the learner can NEVER rely on array index ordering
        $shuffled = $entities;
        shuffle($shuffled);
        return new self($shuffled);
    }
}

/**
 * Computes temporal object continuity between two successive sensory observations.
 * Exploits the physical prior that entities persist over time with minimal feature changes.
 */
final class TemporalContinuityTracker
{
    /**
     * Finds the maximal feature-affinity bipartite matching between before and after sets.
     *
     * @return array{
     *   pairs: list<array{before_idx:int, after_idx:int, diffs:array<string,array{from:string,to:string}>}>,
     *   deltas: list<array{before_obj:array<string,string>, attr:string, from:string, to:string}>
     * }
     */
    public static function match(AnonymousWorld $before, AnonymousWorld $after): array
    {
        $B = $before->entities;
        $A = $after->entities;
        $nB = count($B);
        $nA = count($A);

        // Compute similarity matrix based on shared attribute-value pairs
        $candidates = [];
        for ($i = 0; $i < $nB; $i++) {
            for ($j = 0; $j < $nA; $j++) {
                $shared = 0;
                $allKeys = array_unique(array_merge(array_keys($B[$i]), array_keys($A[$j])));
                $totalKeys = count($allKeys);
                foreach ($B[$i] as $k => $v) {
                    if (($A[$j][$k] ?? null) === $v) {
                        $shared++;
                    }
                }
                $sim = $totalKeys > 0 ? $shared / $totalKeys : 0.0;
                $candidates[] = ['b' => $i, 'a' => $j, 'sim' => $sim];
            }
        }

        // Greedy maximal bipartite assignment
        usort($candidates, fn(array $x, array $y): int => $y['sim'] <=> $x['sim']);

        $usedB = [];
        $usedA = [];
        $pairs = [];
        $deltas = [];

        foreach ($candidates as $c) {
            $b = $c['b'];
            $a = $c['a'];
            if (isset($usedB[$b]) || isset($usedA[$a])) {
                continue;
            }
            $usedB[$b] = true;
            $usedA[$a] = true;

            $diffs = [];
            foreach ($A[$a] as $k => $v) {
                $old = $B[$b][$k] ?? null;
                if ($old !== $v) {
                    $diffs[$k] = ['from' => (string)$old, 'to' => (string)$v];
                    $deltas[] = [
                        'before_obj' => $B[$b],
                        'attr' => $k,
                        'from' => (string)$old,
                        'to' => (string)$v,
                    ];
                }
            }
            $pairs[] = ['before_idx' => $b, 'after_idx' => $a, 'diffs' => $diffs];
        }

        return ['pairs' => $pairs, 'deltas' => $deltas];
    }
}
