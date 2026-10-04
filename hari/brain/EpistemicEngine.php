<?php

declare(strict_types=1);

namespace Hari\Brain;

/**
 * HARI Epistemic Engine & Uncertainty Calibration in PHP.
 * Decision policy: ASK under ambiguity vs ACT when supported.
 */
class EpistemicEngine
{
    public float $confidenceThreshold;
    public float $ambiguityMargin;

    public function __construct(float $confidenceThreshold = 0.35, float $ambiguityMargin = 0.25)
    {
        $this->confidenceThreshold = $confidenceThreshold;
        $this->ambiguityMargin = $ambiguityMargin;
    }

    public function evaluateIntent(array $candidateInterpretations): array
    {
        if ($candidateInterpretations === []) {
            return [
                'action' => 'ASK',
                'confidence' => 0.0,
                'top_hypothesis' => 'none',
                'runner_up_hypothesis' => null,
                'margin' => 0.0,
                'reason' => 'No viable candidate interpretations found',
                'competing_hypotheses' => [],
            ];
        }

        usort($candidateInterpretations, fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = $candidateInterpretations[0];
        $second = $candidateInterpretations[1] ?? null;

        $topScore = (float)$top['score'];
        $secondScore = $second !== null ? (float)$second['score'] : 0.0;
        $margin = $topScore - $secondScore;

        $isAmbiguous = ($second !== null) && ($margin < $this->ambiguityMargin) && ($secondScore > 0.20);
        $isUnderconfident = $topScore < $this->confidenceThreshold;

        if ($isAmbiguous) {
            $decision = 'ASK';
            $reason = sprintf("Ambiguous between '%s' (%.2f) and '%s' (%.2f)", $top['intent'], $topScore, $second['intent'], $secondScore);
        } elseif ($isUnderconfident) {
            $decision = 'ASK';
            $reason = sprintf('Confidence %.2f is below safety threshold %.2f', $topScore, $this->confidenceThreshold);
        } else {
            $decision = 'ACT';
            $reason = sprintf("Decisive support for '%s' (score %.2f, margin %.2f)", $top['intent'], $topScore, $margin);
        }

        $calibratedConf = min(1.0, max(0.0, ($topScore + $margin) / 2.0));

        return [
            'action' => $decision,
            'confidence' => round($calibratedConf, 3),
            'top_hypothesis' => $top['intent'],
            'runner_up_hypothesis' => $second['intent'] ?? null,
            'margin' => round($margin, 3),
            'reason' => $reason,
            'competing_hypotheses' => array_map(fn($c) => ['intent' => $c['intent'], 'score' => round($c['score'], 3)], array_slice($candidateInterpretations, 0, 5)),
            'provenance' => $top['evidence'] ?? [],
        ];
    }
}
