<?php

declare(strict_types=1);

namespace Hari\Ground;

require_once __DIR__ . '/world.php';

final class GroundedBrain
{
    /** @var array<string,array{count:float,last:int,pairs:array<string,array{count:float,last:int}>}> token => entry */
    private array $tokens = [];
    /** @var array<string,array{count:float,last:int}> feature => entry */
    private array $features = [];

    private int $step = 0;
    private float $totalEvents = 0.0;

    public function __construct(
        private readonly float $decay = 0.9995,
        private readonly float $stableDecay = 0.99998,
        private readonly int $maxTokens = 1024,
        private readonly int $maxFeatures = 1024,
        private readonly int $maxPairsPerToken = 48,
        private readonly float $confidenceThreshold = 0.20,
        private readonly float $ambiguityMargin = 0.08,
    ) {}

    /** @return list<string> */
    public static function tokenize(string $utterance): array
    {
        $toks = preg_split('/\s+/u', trim(strtolower($utterance))) ?: [];
        return array_values(array_unique(array_filter($toks, fn(string $t): bool => $t !== '')));
    }

    /**
     * Learn from a raw sensory transition without any developer-supplied frame tags.
     */
    public function experience(string $utterance, AnonymousWorld $before, AnonymousWorld $after, bool $isCorrection = false): array
    {
        $match = TemporalContinuityTracker::match($before, $after);
        $deltas = $match['deltas'];

        $tokens = self::tokenize($utterance);
        if ($tokens === [] || $deltas === []) {
            return ['status' => 'ignored', 'deltas' => count($deltas)];
        }

        $strength = $isCorrection ? 3.0 : 1.0;
        ++$this->step;
        $this->totalEvents += $strength;

        // Extract raw perceptual grounding features
        $extractedFeatures = [];
        foreach ($deltas as $d) {
            $destKey = "dest:{$d['attr']}={$d['to']}";
            $extractedFeatures[$destKey] = $d['attr'];

            // Invariant features of the entity that underwent the transition
            foreach ($d['before_obj'] as $attr => $val) {
                if ($attr !== $d['attr']) {
                    $entKey = "ent:{$attr}={$val}";
                    $extractedFeatures[$entKey] = "ent:{$attr}";
                }
            }
        }

        // Update feature marginals
        foreach ($extractedFeatures as $feat => $_) {
            $fEntry = $this->features[$feat] ?? ['count' => 0.0, 'last' => $this->step];
            $fEntry['count'] = $this->effective($fEntry['count'], $fEntry['last']) + $strength;
            $fEntry['last'] = $this->step;
            $this->features[$feat] = $fEntry;
        }

        // Update token marginals & co-occurrence
        foreach ($tokens as $token) {
            $tEntry = $this->tokens[$token] ?? ['count' => 0.0, 'last' => $this->step, 'pairs' => []];
            $tEntry['count'] = $this->effective($tEntry['count'], $tEntry['last']) + $strength;
            $tEntry['last'] = $this->step;

            // Lateral inhibition on correction: suppress conflicting associations in the same slot
            if ($isCorrection && $tEntry['pairs'] !== []) {
                foreach ($extractedFeatures as $truthFeat => $category) {
                    foreach ($tEntry['pairs'] as $oldFeat => &$pairData) {
                        if ($oldFeat !== $truthFeat && str_starts_with($oldFeat, $category)) {
                            $pairData['count'] = $this->effective($pairData['count'], $pairData['last']) * 0.10;
                            $pairData['last'] = $this->step;
                        }
                    }
                    unset($pairData);
                }
            }

            foreach ($extractedFeatures as $feat => $_) {
                $pair = $tEntry['pairs'][$feat] ?? ['count' => 0.0, 'last' => $this->step];
                $pair['count'] = $this->effective($pair['count'], $pair['last']) + $strength;
                $pair['last'] = $this->step;
                $tEntry['pairs'][$feat] = $pair;
            }

            if (count($tEntry['pairs']) > $this->maxPairsPerToken) {
                uasort($tEntry['pairs'], fn(array $a, array $b): int => $this->effective($b['count'], $b['last']) <=> $this->effective($a['count'], $a['last']));
                $tEntry['pairs'] = array_slice($tEntry['pairs'], 0, $this->maxPairsPerToken, true);
            }

            $this->tokens[$token] = $tEntry;
        }

        $this->enforceBudgets();

        return ['status' => 'learned', 'tokens' => count($tokens), 'deltas' => count($deltas)];
    }

    /**
     * Positive Pointwise Mutual Information (PPMI).
     */
    public function ppmi(string $token, string $feature): float
    {
        $t = $this->tokens[$token] ?? null;
        $f = $this->features[$feature] ?? null;
        if ($t === null || $f === null || !isset($t['pairs'][$feature]) || $this->totalEvents <= 0.0) {
            return 0.0;
        }

        $tc = $this->effective($t['count'], $t['last']);
        $fc = $this->effective($f['count'], $f['last']);
        $p = $t['pairs'][$feature];
        $pc = $this->effective($p['count'], $p['last']);

        if ($tc <= 0.0 || $fc <= 0.0 || $pc <= 0.0) {
            return 0.0;
        }

        $p_tf = $pc / $this->totalEvents;
        $p_t  = $tc / $this->totalEvents;
        $p_f  = $fc / $this->totalEvents;

        $ratio = $p_tf / ($p_t * $p_f);
        return $ratio > 1.0 ? (float)log($ratio) : 0.0;
    }

    /**
     * Given an utterance and an anonymous world, predict the target entity and intended change.
     */
    public function predict(string $utterance, AnonymousWorld $world): array
    {
        $tokens = self::tokenize($utterance);
        if ($tokens === [] || $world->entities === []) {
            return [
                'action' => 'ASK',
                'target_entity' => null,
                'dest_attr' => null,
                'dest_val' => null,
                'confidence' => 0.0,
                'reason' => 'Empty input or empty world',
            ];
        }

        // 1. Score each entity in the current world against utterance tokens
        $entityScores = [];
        $entityTrace = [];
        foreach ($world->entities as $idx => $props) {
            $bestScore = 0.0;
            $bestProp = null;
            $bestToken = null;
            foreach ($props as $attr => $val) {
                $featKey = "ent:{$attr}={$val}";
                foreach ($tokens as $t) {
                    $pmi = $this->ppmi($t, $featKey);
                    if ($pmi > $bestScore) {
                        $bestScore = $pmi;
                        $bestProp = $featKey;
                        $bestToken = $t;
                    }
                }
            }
            $entityScores[$idx] = $bestScore;
            $entityTrace[$idx] = ['score' => $bestScore, 'prop' => $bestProp, 'token' => $bestToken];
        }

        arsort($entityScores);
        $topIdx = array_key_first($entityScores);
        $topEntityScore = $topIdx !== null ? $entityScores[$topIdx] : 0.0;
        $secondEntityScore = count($entityScores) > 1 ? array_values($entityScores)[1] : 0.0;

        // 2. Score destination candidates
        $destScores = [];
        $destTrace = [];
        foreach ($tokens as $t) {
            $tEntry = $this->tokens[$t] ?? null;
            if ($tEntry === null) continue;
            foreach ($tEntry['pairs'] as $f => $_) {
                if (str_starts_with($f, 'dest:')) {
                    [$attr, $val] = explode('=', substr($f, 5), 2);
                    $pmi = $this->ppmi($t, $f);
                    if ($pmi > ($destScores["$attr=$val"] ?? 0.0)) {
                        $destScores["$attr=$val"] = $pmi;
                        $destTrace["$attr=$val"] = ['score' => $pmi, 'token' => $t, 'attr' => $attr, 'val' => $val];
                    }
                }
            }
        }

        arsort($destScores);
        $topDestKey = array_key_first($destScores);
        $topDestScore = $topDestKey !== null ? $destScores[$topDestKey] : 0.0;
        $secondDestScore = count($destScores) > 1 ? array_values($destScores)[1] : 0.0;

        // 3. Uncertainty & Ambiguity Detection
        $entityAmbiguous = ($topEntityScore - $secondEntityScore) < $this->ambiguityMargin && $secondEntityScore > 0.20;
        $destAmbiguous = ($topDestScore - $secondDestScore) < $this->ambiguityMargin && $secondDestScore > 0.20;

        $conf = min(1.0, ($topEntityScore + $topDestScore) / 4.0);

        if ($topIdx !== null && $topDestKey !== null
            && $topEntityScore >= $this->confidenceThreshold
            && $topDestScore >= $this->confidenceThreshold
            && !$entityAmbiguous && !$destAmbiguous) {
            
            [$destAttr, $destVal] = explode('=', $topDestKey, 2);
            return [
                'action' => 'ACT',
                'target_entity' => $world->entities[$topIdx],
                'target_index' => $topIdx,
                'dest_attr' => $destAttr,
                'dest_val' => $destVal,
                'confidence' => $conf,
                'trace' => [
                    'entity' => $entityTrace[$topIdx],
                    'dest' => $destTrace[$topDestKey],
                    'margin_entity' => $topEntityScore - $secondEntityScore,
                    'margin_dest' => $topDestScore - $secondDestScore,
                ],
            ];
        }

        return [
            'action' => 'ASK',
            'target_entity' => $topIdx !== null ? $world->entities[$topIdx] : null,
            'dest_attr' => null,
            'dest_val' => null,
            'confidence' => $conf,
            'reason' => $entityAmbiguous ? 'Target entity ambiguous' : ($destAmbiguous ? 'Destination ambiguous' : 'Low confidence'),
            'trace' => [
                'top_entity_score' => $topEntityScore,
                'second_entity_score' => $secondEntityScore,
                'top_dest_score' => $topDestScore,
                'second_dest_score' => $secondDestScore,
            ],
        ];
    }

    public function stats(): array
    {
        $pairs = 0;
        foreach ($this->tokens as $entry) {
            $pairs += count($entry['pairs']);
        }
        return [
            'tokens' => count($this->tokens),
            'features' => count($this->features),
            'pairs' => $pairs,
            'step' => $this->step,
            'total_events' => $this->totalEvents,
        ];
    }

    public function save(string $path): void
    {
        $body = [
            'v' => 1,
            'step' => $this->step,
            'totalEvents' => $this->totalEvents,
            'tokens' => $this->tokens,
            'features' => $this->features,
        ];
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $envelope = json_encode(['body' => $body, 'sha256' => hash('sha256', $json)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, $envelope, LOCK_EX);
        rename($tmp, $path);
    }

    public static function load(string $path): self
    {
        $e = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $body = $e['body'] ?? throw new \RuntimeException('invalid state');
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals((string)($e['sha256'] ?? ''), hash('sha256', $json))) {
            throw new \RuntimeException('state checksum mismatch');
        }
        $self = new self();
        $self->step = (int)($body['step'] ?? 0);
        $self->totalEvents = (float)($body['totalEvents'] ?? 0.0);
        $self->tokens = is_array($body['tokens'] ?? null) ? $body['tokens'] : [];
        $self->features = is_array($body['features'] ?? null) ? $body['features'] : [];
        return $self;
    }

    private function effective(float $value, int $last): float
    {
        $age = max(0, $this->step - $last);
        if ($age === 0) return $value;
        $rate = $value >= 2.0 ? $this->stableDecay : $this->decay;
        return $value * ($rate ** $age);
    }

    private function enforceBudgets(): void
    {
        if (count($this->tokens) > $this->maxTokens) {
            uasort($this->tokens, fn(array $a, array $b): int => $this->effective($a['count'], $a['last']) <=> $this->effective($b['count'], $b['last']));
            $target = max(1, (int)floor($this->maxTokens * 0.875));
            while (count($this->tokens) > $target) {
                array_shift($this->tokens);
            }
        }

        if (count($this->features) > $this->maxFeatures) {
            uasort($this->features, fn(array $a, array $b): int => $this->effective($a['count'], $a['last']) <=> $this->effective($b['count'], $b['last']));
            $target = max(1, (int)floor($this->maxFeatures * 0.875));
            $removed = [];
            while (count($this->features) > $target) {
                $f = array_key_first($this->features);
                if ($f === null) break;
                $removed[$f] = true;
                unset($this->features[$f]);
            }
            if ($removed !== []) {
                foreach ($this->tokens as &$entry) {
                    foreach ($removed as $f => $_) {
                        unset($entry['pairs'][$f]);
                    }
                }
                unset($entry);
            }
        }
    }
}
