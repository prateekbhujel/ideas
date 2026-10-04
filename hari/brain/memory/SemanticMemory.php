<?php

declare(strict_types=1);

namespace Hari\Brain\Memory;

/**
 * HARI Semantic Memory & Grounded Concept Store.
 * Implements Stage A-H grounded learning discoveries:
 * - Unsupervised token-feature bindings via contrastive PPMI
 * - Local lateral inhibition (x0.10) for 1-shot rule reversal
 * - Dual-timescale recency decay
 * - Multilingual tokenization (English, Nepali Devanagari, Romanized code-switching)
 * - Personal vocabulary & family terminology storage
 * - Checksummed persistence to external SSD
 */
class SemanticMemory
{
    private int $maxTokens;
    private int $maxFeatures;
    private float $decayHalfLife;
    public int $step = 0;
    public float $totalEvents = 0.0;

    public array $tokens = [];    // token => ['count' => float, 'last' => int, 'pairs' => [feature => ['count' => float, 'last' => int]]]
    public array $features = [];  // feature => ['count' => float, 'last' => int]
    public array $facts = [];     // subject:predicate => ['subject' => str, 'predicate' => str, 'value' => mixed, 'provenance' => str]
    public array $personalVocab = []; // personal vocabulary, family terminology, pronunciation notes

    public function __construct(int $maxTokens = 1024, int $maxFeatures = 1024, float $decayHalfLife = 10000.0)
    {
        $this->maxTokens = $maxTokens;
        $this->maxFeatures = $maxFeatures;
        $this->decayHalfLife = $decayHalfLife;
    }

    private function decay(float $count, int $lastStep): float
    {
        $delta = max(0, $this->step - $lastStep);
        if ($delta === 0 || $count <= 0.0) {
            return $count;
        }
        return $count * pow(0.5, $delta / $this->decayHalfLife);
    }

    /**
     * Multilingual Unicode tokenization for English, Nepali Devanagari, and Romanized Nepali.
     */
    public static function tokenize(string $text): array
    {
        // Split on whitespace or punctuation, keeping Devanagari (\p{Devanagari}) and alphanumeric words
        $toks = preg_split('/[^\p{Devanagari}\p{L}\p{N}_]+/u', mb_strtolower(trim($text))) ?: [];
        $unique = [];
        foreach ($toks as $t) {
            $t = trim($t);
            if ($t !== '' && !in_array($t, $unique, true)) {
                $unique[] = $t;
            }
        }
        return $unique;
    }

    public function groundExperience(string $utterance, array $sensoryFeatures, bool $isCorrection = false): array
    {
        $tokens = self::tokenize($utterance);
        if ($tokens === [] || $sensoryFeatures === []) {
            return ['status' => 'ignored', 'tokens' => count($tokens)];
        }

        $strength = $isCorrection ? 3.0 : 1.0;
        ++$this->step;
        $this->totalEvents += $strength;

        // 1. Update feature marginals
        foreach ($sensoryFeatures as [$featKey, $category]) {
            $entry = $this->features[$featKey] ?? ['count' => 0.0, 'last' => $this->step];
            $entry['count'] = $this->decay($entry['count'], $entry['last']) + $strength;
            $entry['last'] = $this->step;
            $this->features[$featKey] = $entry;
        }

        // 2. Update token marginals & co-occurrences
        foreach ($tokens as $tok) {
            $tEntry = $this->tokens[$tok] ?? ['count' => 0.0, 'last' => $this->step, 'pairs' => []];
            $tEntry['count'] = $this->decay($tEntry['count'], $tEntry['last']) + $strength;
            $tEntry['last'] = $this->step;

            // Lateral inhibition on correction: suppress conflicting bindings in same slot
            if ($isCorrection && $tEntry['pairs'] !== []) {
                foreach ($sensoryFeatures as [$truthFeat, $category]) {
                    foreach ($tEntry['pairs'] as $oldFeat => &$pData) {
                        if ($oldFeat !== $truthFeat && str_starts_with($oldFeat, $category)) {
                            $pData['count'] = $this->decay($pData['count'], $pData['last']) * 0.10;
                            $pData['last'] = $this->step;
                        }
                    }
                    unset($pData);
                }
            }

            foreach ($sensoryFeatures as [$featKey, $category]) {
                $pEntry = $tEntry['pairs'][$featKey] ?? ['count' => 0.0, 'last' => $this->step];
                $pEntry['count'] = $this->decay($pEntry['count'], $pEntry['last']) + $strength;
                $pEntry['last'] = $this->step;
                $tEntry['pairs'][$featKey] = $pEntry;
            }

            $this->tokens[$tok] = $tEntry;
        }

        $this->enforceCaps();
        return ['status' => 'learned', 'tokens' => count($tokens), 'features' => count($sensoryFeatures)];
    }

    public function ppmi(string $token, string $feature): float
    {
        $t = $this->tokens[$token] ?? null;
        $f = $this->features[$feature] ?? null;
        if ($t === null || $f === null || !isset($t['pairs'][$feature]) || $this->totalEvents <= 0.0) {
            return 0.0;
        }

        $tc = $this->decay($t['count'], $t['last']);
        $fc = $this->decay($f['count'], $f['last']);
        $p = $t['pairs'][$feature];
        $pc = $this->decay($p['count'], $p['last']);

        if ($tc <= 0.0 || $fc <= 0.0 || $pc <= 0.0) {
            return 0.0;
        }

        $p_tf = $pc / $this->totalEvents;
        $p_t  = $tc / $this->totalEvents;
        $p_f  = $fc / $this->totalEvents;

        $ratio = $p_tf / ($p_t * $p_f);
        return $ratio > 1.0 ? log($ratio) : 0.0;
    }

    public function storeFact(string $subject, string $predicate, mixed $value, string $provenance = ''): void
    {
        $key = strtolower(trim($subject)) . ':' . strtolower(trim($predicate));
        $this->facts[$key] = [
            'subject' => $subject,
            'predicate' => $predicate,
            'value' => $value,
            'provenance' => $provenance,
            'timestamp' => microtime(true),
        ];
    }

    public function queryFact(string $subject, ?string $predicate = null): mixed
    {
        $subClean = strtolower(trim($subject));
        if ($predicate !== null) {
            $key = $subClean . ':' . strtolower(trim($predicate));
            return $this->facts[$key]['value'] ?? null;
        }
        foreach ($this->facts as $k => $data) {
            if (str_starts_with($k, $subClean . ':') || str_contains($k, $subClean)) {
                return $data['value'];
            }
        }
        return null;
    }

    public function storePersonalVocab(string $term, string $meaning, string $language = 'ne', string $notes = ''): void
    {
        $clean = mb_strtolower(trim($term));
        $this->personalVocab[$clean] = [
            'term' => $term,
            'meaning' => $meaning,
            'language' => $language,
            'notes' => $notes,
            'timestamp' => microtime(true),
        ];
    }

    private function enforceCaps(): void
    {
        if (count($this->tokens) > $this->maxTokens) {
            uasort($this->tokens, fn(array $a, array $b): int => $this->decay($b['count'], $b['last']) <=> $this->decay($a['count'], $a['last']));
            $this->tokens = array_slice($this->tokens, 0, $this->maxTokens, true);
        }
        if (count($this->features) > $this->maxFeatures) {
            uasort($this->features, fn(array $a, array $b): int => $this->decay($b['count'], $b['last']) <=> $this->decay($a['count'], $a['last']));
            $this->features = array_slice($this->features, 0, $this->maxFeatures, true);
        }
    }

    public function save(?string $filepath = null): string
    {
        $path = $filepath ?? '/Volumes/DEV-T7/Projects/hari-scratch/state/semantic_memory.json';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $payload = [
            'step' => $this->step,
            'total_events' => $this->totalEvents,
            'tokens' => $this->tokens,
            'features' => $this->features,
            'facts' => $this->facts,
            'personal_vocab' => $this->personalVocab,
        ];
        $raw = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $raw);
        $envelope = json_encode(['checksum' => $checksum, 'data' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $envelope);
        return $path;
    }

    public static function load(?string $filepath = null): self
    {
        $path = $filepath ?? '/Volumes/DEV-T7/Projects/hari-scratch/state/semantic_memory.json';
        $mem = new self();
        if (!file_exists($path)) {
            return $mem;
        }
        $raw = file_get_contents($path);
        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !isset($envelope['data'])) {
            return $mem;
        }
        $data = $envelope['data'];
        $checksum = $envelope['checksum'] ?? '';
        $calcChecksum = hash('sha256', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($checksum !== '' && $checksum !== $calcChecksum) {
            error_log("Warning: Semantic memory checksum mismatch at {$path}");
        }

        $mem->step = (int)($data['step'] ?? 0);
        $mem->totalEvents = (float)($data['total_events'] ?? 0.0);
        $mem->tokens = $data['tokens'] ?? [];
        $mem->features = $data['features'] ?? [];
        $mem->facts = $data['facts'] ?? [];
        $mem->personalVocab = $data['personal_vocab'] ?? [];
        return $mem;
    }

    public function stats(): array
    {
        $pairs = 0;
        foreach ($this->tokens as $t) {
            $pairs += count($t['pairs'] ?? []);
        }
        return [
            'tokens' => count($this->tokens),
            'features' => count($this->features),
            'pairs' => $pairs,
            'facts' => count($this->facts),
            'personal_vocab' => count($this->personalVocab),
            'step' => $this->step,
            'total_events' => $this->totalEvents,
        ];
    }
}
