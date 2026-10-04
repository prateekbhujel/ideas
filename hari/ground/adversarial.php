<?php

declare(strict_types=1);

namespace Hari\Ground;

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/brain.php';

echo "=== HARI Ground Adversarial Stress Suite ===\n\n";

$tests = [];

// 1. Identical Twin Ambiguity Attack
$tests['twin_ambiguity_safe_refusal'] = function(): void {
    $brain = new GroundedBrain();

    // Train core vocabulary
    $w0 = AnonymousWorld::create([['shape' => 'cylinder', 'loc' => 'origin']]);
    $w1 = AnonymousWorld::create([['shape' => 'cylinder', 'loc' => 'tray']]);
    $brain->experience('koro vapa tray', $w0, $w1);

    $w2 = AnonymousWorld::create([['shape' => 'cone', 'loc' => 'origin']]);
    $w3 = AnonymousWorld::create([['shape' => 'cone', 'loc' => 'box']]);
    $brain->experience('ziri vapa box', $w2, $w3);

    // World with identical twin cylinders
    $wAttack = AnonymousWorld::create([
        ['shape' => 'cylinder', 'color' => 'silver', 'loc' => 'shelf_left'],
        ['shape' => 'cylinder', 'color' => 'silver', 'loc' => 'shelf_right'],
    ]);

    $pred = $brain->predict('koro vapa tray', $wAttack);
    // Must refuse to act blindly when targets are indistinguishable
    assert($pred['action'] === 'ASK', "Expected ASK under identical twin ambiguity, got {$pred['action']}");
    assert(str_contains($pred['reason'], 'Target entity ambiguous') || $pred['confidence'] < 0.20);
    echo "  [PASS] Identical twin attack -> safely refused (ASK)\n";
};

// 2a. Strict Collinear Feature Confounding -> Must Safely Refuse (ASK)
$tests['strictly_correlated_ambiguity_safe_refusal'] = function(): void {
    $brain = new GroundedBrain();

    // In training: ruby is always red, emerald is always green. No other red objects exist.
    for ($i = 0; $i < 3; $i++) {
        $w0 = AnonymousWorld::create([['shape' => 'gem_ruby', 'color' => 'red', 'loc' => 'origin']]);
        $w1 = AnonymousWorld::create([['shape' => 'gem_ruby', 'color' => 'red', 'loc' => 'chest']]);
        $brain->experience('penta vapa chest', $w0, $w1);

        $w2 = AnonymousWorld::create([['shape' => 'gem_emerald', 'color' => 'green', 'loc' => 'origin']]);
        $w3 = AnonymousWorld::create([['shape' => 'gem_emerald', 'color' => 'green', 'loc' => 'bag']]);
        $brain->experience('gema vapa bag', $w2, $w3);
    }

    // World with red ruby vs red brick. Both match 'penta' with identical PPMI (0.694).
    $wWorld = AnonymousWorld::create([
        ['shape' => 'gem_ruby', 'color' => 'red', 'loc' => 'origin'],
        ['shape' => 'brick', 'color' => 'red', 'loc' => 'origin'],
    ]);

    $pred = $brain->predict('penta vapa chest', $wWorld);
    // Since color and shape were 100% collinear in experience, agent cannot know if 'penta' refers to ruby or red
    assert($pred['action'] === 'ASK', "Expected ASK under strict collinearity, got {$pred['action']}");
    assert(str_contains($pred['reason'], 'Target entity ambiguous'));
    echo "  [PASS] Strict collinearity -> epistemic ambiguity correctly detected (ASK)\n";
};

// 2b. Contrastive Decorrelation Breaks Symmetry -> ACT
$tests['contrastive_feature_disentanglement'] = function(): void {
    $brain = new GroundedBrain();

    for ($i = 0; $i < 3; $i++) {
        $w0 = AnonymousWorld::create([['shape' => 'gem_ruby', 'color' => 'red', 'loc' => 'origin']]);
        $w1 = AnonymousWorld::create([['shape' => 'gem_ruby', 'color' => 'red', 'loc' => 'chest']]);
        $brain->experience('penta vapa chest', $w0, $w1);

        $w2 = AnonymousWorld::create([['shape' => 'gem_emerald', 'color' => 'green', 'loc' => 'origin']]);
        $w3 = AnonymousWorld::create([['shape' => 'gem_emerald', 'color' => 'green', 'loc' => 'bag']]);
        $brain->experience('gema vapa bag', $w2, $w3);

        // Apple is also red, but named 'pomu'
        $w4 = AnonymousWorld::create([['shape' => 'apple', 'color' => 'red', 'loc' => 'origin']]);
        $w5 = AnonymousWorld::create([['shape' => 'apple', 'color' => 'red', 'loc' => 'basket']]);
        $brain->experience('pomu vapa basket', $w4, $w5);
    }

    $wWorld = AnonymousWorld::create([
        ['shape' => 'gem_ruby', 'color' => 'red', 'loc' => 'origin'],
        ['shape' => 'brick', 'color' => 'red', 'loc' => 'origin'],
    ]);

    $pred = $brain->predict('penta vapa chest', $wWorld);
    // Now 'penta' has higher PPMI with 'gem_ruby' than with 'red'. It should act!
    assert($pred['action'] === 'ACT', "Expected ACT after contrastive decorrelation, got {$pred['action']}");
    assert($pred['target_entity']['shape'] === 'gem_ruby');
    assert($pred['dest_val'] === 'chest');
    echo "  [PASS] Contrastive decorrelation -> ruby correctly isolated over red brick (ACT)\n";
};

// 3. Ambient Distractor Noise Filtering
$tests['ambient_distractor_filtering'] = function(): void {
    $brain = new GroundedBrain();

    for ($i = 0; $i < 20; $i++) {
        // Target: ball moves to table
        $target0 = ['shape' => 'ball', 'loc' => 'origin'];
        $target1 = ['shape' => 'ball', 'loc' => 'table'];
        // Noise: ambient siren blinks and beeps in every scene
        $siren0 = ['shape' => 'siren', 'state' => 'silent'];
        $siren1 = ['shape' => 'siren', 'state' => 'blinking'];

        $w0 = AnonymousWorld::create([$target0, $siren0]);
        $w1 = AnonymousWorld::create([$target1, $siren1]);
        $brain->experience('mako lumi table', $w0, $w1);

        // Siren also fires during totally unrelated actions
        $other0 = ['shape' => 'cup', 'loc' => 'origin'];
        $other1 = ['shape' => 'cup', 'loc' => 'tray'];
        $w0b = AnonymousWorld::create([$other0, $siren0]);
        $w1b = AnonymousWorld::create([$other1, $siren1]);
        $brain->experience('tora nuba tray', $w0b, $w1b);
    }

    // Test in a world containing both ball and siren
    $wTest = AnonymousWorld::create([
        ['shape' => 'ball', 'loc' => 'origin'],
        ['shape' => 'siren', 'state' => 'silent'],
    ]);

    $pred = $brain->predict('mako lumi table', $wTest);
    assert($pred['action'] === 'ACT');
    assert($pred['target_entity']['shape'] === 'ball');
    assert($pred['dest_val'] === 'table');

    // Verify siren has zero or negative association with 'mako'
    $pmiSiren = $brain->ppmi('mako', 'ent:shape=siren');
    assert($pmiSiren < 0.05, "PPMI with siren should be near 0, got $pmiSiren");
    echo "  [PASS] Ambient noise flood -> siren PPMI is {$pmiSiren}, ball cleanly isolated\n";
};

// 4. Out-of-Distribution / Complete Gibberish Token Attack
$tests['ood_gibberish_refusal'] = function(): void {
    $brain = new GroundedBrain();

    $w0 = AnonymousWorld::create([['shape' => 'pyramid', 'loc' => 'origin']]);
    $w1 = AnonymousWorld::create([['shape' => 'pyramid', 'loc' => 'pedestal']]);
    $brain->experience('solis vapa pedestal', $w0, $w1);

    $w2 = AnonymousWorld::create([['shape' => 'sphere', 'loc' => 'origin']]);
    $w3 = AnonymousWorld::create([['shape' => 'sphere', 'loc' => 'basket']]);
    $brain->experience('luna vapa basket', $w2, $w3);

    $wWorld = AnonymousWorld::create([
        ['shape' => 'pyramid', 'loc' => 'origin'],
        ['shape' => 'sphere', 'loc' => 'origin'],
    ]);

    // Attack with totally unseen tokens
    $pred = $brain->predict('xyzzy blorp qwerty', $wWorld);
    assert($pred['action'] === 'ASK', "Expected ASK on complete gibberish, got {$pred['action']}");
    assert($pred['confidence'] === 0.0);
    echo "  [PASS] Complete OOD gibberish -> zero false action, safe ASK with conf 0.0\n";
};

// 5. Rapid 1-Shot Concept Reversal via Lateral Inhibition
$tests['rapid_concept_reversal'] = function(): void {
    $brain = new GroundedBrain();

    // Background vocabulary diversity
    $brain->experience('nari keba box',
        AnonymousWorld::create([['shape' => 'cube', 'loc' => 'origin']]),
        AnonymousWorld::create([['shape' => 'cube', 'loc' => 'box']])
    );
    $brain->experience('tora polo shelf',
        AnonymousWorld::create([['shape' => 'cylinder', 'loc' => 'origin']]),
        AnonymousWorld::create([['shape' => 'cylinder', 'loc' => 'shelf']])
    );
    $brain->experience('solis vapa mat',
        AnonymousWorld::create([['shape' => 'pyramid', 'loc' => 'origin']]),
        AnonymousWorld::create([['shape' => 'pyramid', 'loc' => 'mat']])
    );

    // Strongly teach 'tok_x' means apple
    for ($i = 0; $i < 3; $i++) {
        $w0 = AnonymousWorld::create([['shape' => 'apple', 'loc' => 'origin']]);
        $w1 = AnonymousWorld::create([['shape' => 'apple', 'loc' => 'basket']]);
        $brain->experience('tok_x vapa basket', $w0, $w1);
    }

    $wTest = AnonymousWorld::create([
        ['shape' => 'apple', 'loc' => 'origin'],
        ['shape' => 'orange', 'loc' => 'origin'],
    ]);

    $pBefore = $brain->predict('tok_x vapa basket', $wTest);
    assert($pBefore['action'] === 'ACT');
    assert($pBefore['target_entity']['shape'] === 'apple');

    // 1-Shot Teacher Correction: 'tok_x' now means orange!
    $wRev0 = AnonymousWorld::create([['shape' => 'orange', 'loc' => 'origin']]);
    $wRev1 = AnonymousWorld::create([['shape' => 'orange', 'loc' => 'basket']]);
    $brain->experience('tok_x vapa basket', $wRev0, $wRev1, isCorrection: true);

    $pAfter = $brain->predict('tok_x vapa basket', $wTest);
    assert($pAfter['action'] === 'ACT');
    assert($pAfter['target_entity']['shape'] === 'orange', "Expected 1-shot reversal to orange, got {$pAfter['target_entity']['shape']}");
    echo "  [PASS] 1-shot concept reversal -> successfully shifted to orange in 1 step\n";
};

// 6. High-Volume Churn Stress (10,000 experiences)
$tests['high_volume_churn_budget_cap'] = function(): void {
    $brain = new GroundedBrain();

    // Anchor concepts
    $w0 = AnonymousWorld::create([['shape' => 'anchor_gold', 'loc' => 'vault']]);
    $w1 = AnonymousWorld::create([['shape' => 'anchor_gold', 'loc' => 'safe']]);
    $brain->experience('aurum secure safe', $w0, $w1);

    $w2 = AnonymousWorld::create([['shape' => 'anchor_silver', 'loc' => 'vault']]);
    $w3 = AnonymousWorld::create([['shape' => 'anchor_silver', 'loc' => 'bank']]);
    $brain->experience('argent secure bank', $w2, $w3);

    $memStart = memory_get_usage(true);
    $t0 = microtime(true);

    // Flood with 10,000 random non-stationary churn events
    for ($i = 0; $i < 10000; $i++) {
        $junkShape = 'junk_' . ($i % 3000);
        $junkDest = 'dest_' . ($i % 3000);
        $wJ0 = AnonymousWorld::create([['shape' => $junkShape, 'loc' => 'nowhere']]);
        $wJ1 = AnonymousWorld::create([['shape' => $junkShape, 'loc' => $junkDest]]);
        $brain->experience("noise_{$i} churn {$junkDest}", $wJ0, $wJ1);
    }

    $elapsed = microtime(true) - $t0;
    $memEnd = memory_get_usage(true);
    $st = $brain->stats();

    assert($st['tokens'] <= 1024, "Tokens exceeded hard cap: {$st['tokens']}");
    assert($st['features'] <= 1024, "Features exceeded hard cap: {$st['features']}");

    echo sprintf("  [PASS] 10,000 churn steps in %.2fs (%.1f events/s), tokens=%d, features=%d, RAM delta=%.2f MB\n",
        $elapsed, 10000 / $elapsed, $st['tokens'], $st['features'], ($memEnd - $memStart) / (1024 * 1024));
};

$tAll = microtime(true);
$passed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $passed++;
    } catch (\Throwable $e) {
        fwrite(STDERR, "  [FAIL] {$name}: {$e->getMessage()}\n");
        exit(1);
    }
}
printf("\nAll %d/%d adversarial stress tests PASSED in %.3fs\n", $passed, count($tests), microtime(true) - $tAll);
