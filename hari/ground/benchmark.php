<?php

declare(strict_types=1);

namespace Hari\Ground;

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/brain.php';

/**
 * Baseline 1: Naive Bayes Learner (Laplace-smoothed feature count model).
 */
class BaselineNaiveBayes
{
    private array $counts = [];
    private array $classes = [];
    private int $total = 0;

    public function experience(string $utterance, AnonymousWorld $before, AnonymousWorld $after): void
    {
        $match = TemporalContinuityTracker::match($before, $after);
        $deltas = $match['deltas'];
        if ($deltas === []) return;

        $target = $deltas[0]['before_obj']['shape'] ?? 'unknown';
        $dest = ($deltas[0]['attr'] ?? '') . '=' . ($deltas[0]['to'] ?? '');
        $classKey = "{$target}|{$dest}";

        $this->classes[$classKey] = ($this->classes[$classKey] ?? 0) + 1;
        $this->total++;

        $tokens = GroundedBrain::tokenize($utterance);
        foreach ($tokens as $t) {
            $this->counts[$classKey][$t] = ($this->counts[$classKey][$t] ?? 0) + 1;
        }
    }

    public function predict(string $utterance, AnonymousWorld $world): array
    {
        $tokens = GroundedBrain::tokenize($utterance);
        if ($this->total === 0 || $tokens === [] || $world->entities === []) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => 0.0];
        }

        $bestScore = -INF;
        $bestClass = null;

        foreach ($this->classes as $c => $cCount) {
            $score = log(($cCount + 1.0) / ($this->total + count($this->classes)));
            foreach ($tokens as $t) {
                $tCount = $this->counts[$c][$t] ?? 0;
                $score += log(($tCount + 1.0) / ($cCount + 2.0));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestClass = $c;
            }
        }

        if ($bestClass === null) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => 0.0];
        }

        [$tShape, $destStr] = explode('|', $bestClass, 2);
        [$destAttr, $destVal] = explode('=', $destStr, 2);

        // Find target entity in world
        $found = null;
        foreach ($world->entities as $e) {
            if (($e['shape'] ?? null) === $tShape) {
                $found = $e;
                break;
            }
        }

        if ($found === null) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => 0.0];
        }

        $conf = 1.0 / (1.0 + exp(-max(-10.0, min(10.0, $bestScore / 5.0))));
        if ($conf < 0.50) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => $conf];
        }

        return ['action' => 'ACT', 'target_entity' => $found, 'dest_val' => $destVal, 'confidence' => $conf];
    }
}

/**
 * Baseline 2: Nearest Neighbor Exemplar Memory.
 */
class BaselineNearestNeighbor
{
    private array $exemplars = [];

    public function experience(string $utterance, AnonymousWorld $before, AnonymousWorld $after): void
    {
        $match = TemporalContinuityTracker::match($before, $after);
        $deltas = $match['deltas'];
        if ($deltas === []) return;

        $target = $deltas[0]['before_obj']['shape'] ?? 'unknown';
        $dest = ($deltas[0]['attr'] ?? '') . '=' . ($deltas[0]['to'] ?? '');

        $tokens = GroundedBrain::tokenize($utterance);
        $this->exemplars[] = ['tokens' => $tokens, 'target' => $target, 'dest' => $dest];
        if (count($this->exemplars) > 1024) {
            array_shift($this->exemplars);
        }
    }

    public function predict(string $utterance, AnonymousWorld $world): array
    {
        $tokens = GroundedBrain::tokenize($utterance);
        if ($this->exemplars === [] || $tokens === [] || $world->entities === []) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => 0.0];
        }

        $bestSim = -1.0;
        $bestMatch = null;

        foreach ($this->exemplars as $ex) {
            $intersection = count(array_intersect($tokens, $ex['tokens']));
            $union = count(array_unique(array_merge($tokens, $ex['tokens'])));
            $sim = $union > 0 ? $intersection / $union : 0.0;
            if ($sim > $bestSim) {
                $bestSim = $sim;
                $bestMatch = $ex;
            }
        }

        if ($bestMatch === null || $bestSim < 0.60) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => max(0.0, $bestSim)];
        }

        $found = null;
        foreach ($world->entities as $e) {
            if (($e['shape'] ?? null) === $bestMatch['target']) {
                $found = $e;
                break;
            }
        }

        if ($found === null) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => $bestSim];
        }

        [$destAttr, $destVal] = explode('=', $bestMatch['dest'], 2);
        return ['action' => 'ACT', 'target_entity' => $found, 'dest_val' => $destVal, 'confidence' => $bestSim];
    }
}

/**
 * Baseline 3: Online SGD Perceptron.
 */
class BaselineOnlineSGD
{
    private array $weights = [];
    private array $classes = [];
    private float $lr = 0.1;

    public function experience(string $utterance, AnonymousWorld $before, AnonymousWorld $after): void
    {
        $match = TemporalContinuityTracker::match($before, $after);
        $deltas = $match['deltas'];
        if ($deltas === []) return;

        $target = $deltas[0]['before_obj']['shape'] ?? 'unknown';
        $dest = ($deltas[0]['attr'] ?? '') . '=' . ($deltas[0]['to'] ?? '');
        $classKey = "{$target}|{$dest}";

        if (!in_array($classKey, $this->classes, true)) {
            $this->classes[] = $classKey;
        }

        $tokens = GroundedBrain::tokenize($utterance);

        // Update weights with SGD
        foreach ($this->classes as $c) {
            $y = ($c === $classKey) ? 1.0 : -1.0;
            $dot = 0.0;
            foreach ($tokens as $t) {
                $dot += ($this->weights[$c][$t] ?? 0.0);
            }
            $margin = $y * $dot;
            if ($margin < 1.0) {
                foreach ($tokens as $t) {
                    $this->weights[$c][$t] = ($this->weights[$c][$t] ?? 0.0) + $this->lr * $y;
                }
            }
        }
    }

    public function predict(string $utterance, AnonymousWorld $world): array
    {
        $tokens = GroundedBrain::tokenize($utterance);
        if ($this->classes === [] || $tokens === [] || $world->entities === []) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => 0.0];
        }

        $bestScore = -INF;
        $bestClass = null;

        foreach ($this->classes as $c) {
            $score = 0.0;
            foreach ($tokens as $t) {
                $score += ($this->weights[$c][$t] ?? 0.0);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestClass = $c;
            }
        }

        $conf = 1.0 / (1.0 + exp(-$bestScore));
        if ($bestClass === null || $conf < 0.65) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => max(0.0, $conf)];
        }

        [$tShape, $destStr] = explode('|', $bestClass, 2);
        [$destAttr, $destVal] = explode('=', $destStr, 2);

        $found = null;
        foreach ($world->entities as $e) {
            if (($e['shape'] ?? null) === $tShape) {
                $found = $e;
                break;
            }
        }

        if ($found === null) {
            return ['action' => 'ASK', 'target_entity' => null, 'dest_val' => null, 'confidence' => $conf];
        }

        return ['action' => 'ACT', 'target_entity' => $found, 'dest_val' => $destVal, 'confidence' => $conf];
    }
}

/**
 * Benchmark Runner.
 */
class GroundBenchmark
{
    private array $actions = ['move' => 'mako', 'slide' => 'tora', 'push' => 'nari', 'shift' => 'vado'];
    private array $shapes  = ['ball' => 'lumi', 'cube' => 'keba', 'key' => 'nova', 'gem' => 'penta', 'cup' => 'sela'];
    private array $dests   = ['table' => 'mesa', 'box' => 'kosa', 'tray' => 'tara', 'shelf' => 'rima'];

    public function run(array $budgets = [1, 2, 5, 10, 25, 50, 100], int $seeds = 5): array
    {
        $results = [
            'meta' => [
                'timestamp' => date('c'),
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'budgets' => $budgets,
                'seeds' => $seeds,
            ],
            'sample_efficiency' => [],
            'stream_churn' => [],
        ];

        // 1. Sample Efficiency Benchmark
        foreach ($budgets as $B) {
            $modelStats = [
                'HARI_Ground' => ['act_ok' => [], 'ask' => [], 'act_wrong' => [], 'brier' => []],
                'Naive_Bayes' => ['act_ok' => [], 'ask' => [], 'act_wrong' => [], 'brier' => []],
                'Nearest_Neighbor' => ['act_ok' => [], 'ask' => [], 'act_wrong' => [], 'brier' => []],
                'Online_SGD' => ['act_ok' => [], 'ask' => [], 'act_wrong' => [], 'brier' => []],
            ];

            for ($s = 0; $s < $seeds; $s++) {
                mt_srand(42 + $s * 100 + $B);

                $hari = new GroundedBrain();
                $nb   = new BaselineNaiveBayes();
                $nn   = new BaselineNearestNeighbor();
                $sgd  = new BaselineOnlineSGD();

                // Training Pool (Held-out combination: action=move, shape=ball, dest=table will NOT be in training)
                $trainingPool = [];
                foreach ($this->actions as $actName => $actWord) {
                    foreach ($this->shapes as $shpName => $shpWord) {
                        foreach ($this->dests as $dstName => $dstWord) {
                            if ($actName === 'move' && $shpName === 'ball' && $dstName === 'table') {
                                continue; // Held-out composition
                            }
                            $trainingPool[] = [
                                'act' => $actName, 'actW' => $actWord,
                                'shp' => $shpName, 'shpW' => $shpWord,
                                'dst' => $dstName, 'dstW' => $dstWord,
                            ];
                        }
                    }
                }

                shuffle($trainingPool);
                $trainSet = array_slice($trainingPool, 0, $B);

                // Train models
                foreach ($trainSet as $ex) {
                    $w0 = AnonymousWorld::create([['shape' => $ex['shp'], 'loc' => 'origin']]);
                    $w1 = AnonymousWorld::create([['shape' => $ex['shp'], 'loc' => $ex['dst']]]);
                    $utt = "{$ex['actW']} {$ex['shpW']} {$ex['dstW']}";

                    $hari->experience($utt, $w0, $w1);
                    $nb->experience($utt, $w0, $w1);
                    $nn->experience($utt, $w0, $w1);
                    $sgd->experience($utt, $w0, $w1);
                }

                // Test: Compositional Held-Out Query ('mako lumi mesa' -> ball to table)
                // In an anonymous world with 3 distractor objects!
                $testWorld = AnonymousWorld::create([
                    ['shape' => 'cube', 'loc' => 'origin'],
                    ['shape' => 'ball', 'loc' => 'origin'],
                    ['shape' => 'key',  'loc' => 'origin'],
                ]);
                $testUtt = 'mako lumi mesa';

                $models = ['HARI_Ground' => $hari, 'Naive_Bayes' => $nb, 'Nearest_Neighbor' => $nn, 'Online_SGD' => $sgd];

                foreach ($models as $mName => $model) {
                    $pred = $model->predict($testUtt, $testWorld);
                    $isCorrect = ($pred['action'] === 'ACT')
                        && (($pred['target_entity']['shape'] ?? null) === 'ball')
                        && (($pred['dest_val'] ?? null) === 'table');

                    $isWrong = ($pred['action'] === 'ACT') && !$isCorrect;
                    $isAsk = ($pred['action'] === 'ASK');

                    $conf = (float)($pred['confidence'] ?? 0.0);
                    $targetOutcome = $isCorrect ? 1.0 : 0.0;
                    $brier = ($conf - $targetOutcome) ** 2;

                    $modelStats[$mName]['act_ok'][] = $isCorrect ? 1.0 : 0.0;
                    $modelStats[$mName]['ask'][] = $isAsk ? 1.0 : 0.0;
                    $modelStats[$mName]['act_wrong'][] = $isWrong ? 1.0 : 0.0;
                    $modelStats[$mName]['brier'][] = $brier;
                }
            }

            $bSummary = [];
            foreach ($modelStats as $mName => $metrics) {
                $bSummary[$mName] = [
                    'act_ok'    => self::mean($metrics['act_ok']),
                    'ask'       => self::mean($metrics['ask']),
                    'act_wrong' => self::mean($metrics['act_wrong']),
                    'brier'     => self::mean($metrics['brier']),
                ];
            }
            $results['sample_efficiency'][$B] = $bSummary;
        }

        // 2. High-Volume Stream Churn Test (50,000 continuous transitions)
        echo "\nRunning 50,000 experience stream stress test...\n";
        $brainStream = new GroundedBrain();
        $memStart = memory_get_usage(true);
        $t0 = microtime(true);

        for ($i = 0; $i < 50000; $i++) {
            $junkShape = 'ent_' . ($i % 5000);
            $junkDest = 'loc_' . ($i % 5000);
            $w0 = AnonymousWorld::create([['shape' => $junkShape, 'loc' => 'void']]);
            $w1 = AnonymousWorld::create([['shape' => $junkShape, 'loc' => $junkDest]]);
            $brainStream->experience("tok_{$i} op {$junkDest}", $w0, $w1);
        }

        $streamDuration = microtime(true) - $t0;
        $memEnd = memory_get_usage(true);
        $streamStats = $brainStream->stats();

        $results['stream_churn'] = [
            'steps' => 50000,
            'duration_sec' => round($streamDuration, 3),
            'throughput_ops_sec' => round(50000 / $streamDuration, 1),
            'avg_latency_us' => round(($streamDuration / 50000) * 1e6, 2),
            'tokens_count' => $streamStats['tokens'],
            'features_count' => $streamStats['features'],
            'pairs_count' => $streamStats['pairs'],
            'peak_memory_mb' => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
            'ram_delta_mb' => round(($memEnd - $memStart) / (1024 * 1024), 2),
        ];

        return $results;
    }

    private static function mean(array $arr): float
    {
        return $arr === [] ? 0.0 : array_sum($arr) / count($arr);
    }
}

// CLI Execution
$bench = new GroundBenchmark();
$results = $bench->run();

$outputDir = is_dir('/Volumes/DEV-T7/Projects/hari/research')
    ? '/Volumes/DEV-T7/Projects/hari/research'
    : __DIR__ . '/../../research';

$outFile = $outputDir . '/benchmark_results.json';
file_put_contents($outFile, json_encode($results, JSON_PRETTY_PRINT));
echo "\nBenchmark complete. Machine-readable JSON saved to: {$outFile}\n\n";

// Print summary table
printf("%-8s | %-15s | %-10s | %-10s | %-10s | %-10s\n", "Budget", "Model", "ACT_OK", "ASK", "ACT_WRONG", "Brier");
echo str_repeat('-', 75) . "\n";
foreach ($results['sample_efficiency'] as $b => $models) {
    foreach ($models as $m => $mStats) {
        printf("%-8d | %-15s | %-10.2f | %-10.2f | %-10.2f | %-10.4f\n",
            $b, $m, $mStats['act_ok'], $mStats['ask'], $mStats['act_wrong'], $mStats['brier']);
    }
    echo str_repeat('-', 75) . "\n";
}

printf("\nStream Stress (50,000 steps):\n");
printf("  Throughput: %.1f events/s (%.2f µs/step)\n",
    $results['stream_churn']['throughput_ops_sec'], $results['stream_churn']['avg_latency_us']);
printf("  Final Tokens: %d (Cap: 1024)\n", $results['stream_churn']['tokens_count']);
printf("  Final Features: %d (Cap: 1024)\n", $results['stream_churn']['features_count']);
printf("  Peak RAM: %.2f MB\n", $results['stream_churn']['peak_memory_mb']);
