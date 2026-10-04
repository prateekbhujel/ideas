<?php

declare(strict_types=1);

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/brain.php';

use Hari\Ground\{AnonymousWorld, GroundedBrain};

$tests = [];

$tests['starts uncertain instead of guessing'] = function(): void {
    $brain = new GroundedBrain();
    $world = AnonymousWorld::create([
        ['shape' => 'ball', 'color' => 'red', 'loc' => 'table'],
    ]);
    $pred = $brain->predict('mako lumi mesa', $world);
    ok($pred['action'] === 'ASK');
    ok($pred['dest_val'] === null);
};

$tests['learns grounded meaning from anonymous transitions'] = function(): void {
    $brain = new GroundedBrain();

    // 4 demonstrations
    // Ball -> table, Cup -> shelf, Ball -> shelf, Cup -> table
    $pairs = [
        ['mako lumi mesa', ['shape'=>'ball', 'loc'=>'origin'], 'table'],
        ['mako piko tara', ['shape'=>'cup',  'loc'=>'origin'], 'shelf'],
        ['mako lumi tara', ['shape'=>'ball', 'loc'=>'origin'], 'shelf'],
        ['mako piko mesa', ['shape'=>'cup',  'loc'=>'origin'], 'table'],
    ];

    foreach ($pairs as [$u, $target, $dest]) {
        $dist = ['shape'=>'distractor', 'loc'=>'origin'];
        $w0 = AnonymousWorld::create([$target, $dist]);
        $targetAfter = $target;
        $targetAfter['loc'] = $dest;
        $w1 = AnonymousWorld::create([$targetAfter, $dist]);
        $brain->experience($u, $w0, $w1);
    }

    // Test trained commands
    $testWorld = AnonymousWorld::create([
        ['shape' => 'ball', 'loc' => 'origin'],
        ['shape' => 'cup',  'loc' => 'origin'],
    ]);

    $p1 = $brain->predict('mako lumi mesa', $testWorld);
    ok($p1['action'] === 'ACT');
    eq('ball', $p1['target_entity']['shape']);
    eq('table', $p1['dest_val']);

    $p2 = $brain->predict('mako piko tara', $testWorld);
    ok($p2['action'] === 'ACT');
    eq('cup', $p2['target_entity']['shape']);
    eq('shelf', $p2['dest_val']);
};

$tests['generalizes to held-out composition in novel world'] = function(): void {
    $brain = new GroundedBrain();

    // Train on subsets:
    // ball -> table, cup -> shelf, book -> box, ball -> shelf, cup -> box, book -> table
    // Held-out: ball -> box ('mako lumi vora')
    $train = [
        ['mako lumi mesa', 'ball', 'table'],
        ['mako piko tara', 'cup',  'shelf'],
        ['mako dara vora', 'book', 'box'],
        ['mako lumi tara', 'ball', 'shelf'],
        ['mako piko vora', 'cup',  'box'],
        ['mako dara mesa', 'book', 'table'],
    ];

    for ($r = 0; $r < 2; $r++) {
        foreach ($train as [$u, $shape, $dest]) {
            $w0 = AnonymousWorld::create([['shape' => $shape, 'loc' => 'origin'], ['shape' => 'dist', 'loc' => 'origin']]);
            $w1 = AnonymousWorld::create([['shape' => $shape, 'loc' => $dest],   ['shape' => 'dist', 'loc' => 'origin']]);
            $brain->experience($u, $w0, $w1);
        }
    }

    // Test NEVER DEMONSTRATED combination: ball to box ('mako lumi vora')
    $novelWorld = AnonymousWorld::create([
        ['shape' => 'ball', 'color' => 'yellow_novel', 'loc' => 'somewhere'],
        ['shape' => 'other', 'color' => 'white_novel',  'loc' => 'elsewhere'],
    ]);

    $p = $brain->predict('mako lumi vora', $novelWorld);
    ok($p['action'] === 'ACT');
    eq('ball', $p['target_entity']['shape']);
    eq('box', $p['dest_val']);
    ok($p['confidence'] > 0.30);
};

$tests['safe refusal under twin entity reference ambiguity'] = function(): void {
    $brain = new GroundedBrain();
    $w0 = AnonymousWorld::create([['shape' => 'ball', 'loc' => 'table']]);
    $w1 = AnonymousWorld::create([['shape' => 'ball', 'loc' => 'shelf']]);
    $brain->experience('mako lumi tara', $w0, $w1);

    // Present world with TWO IDENTICAL BALLS at different locations
    $twinWorld = AnonymousWorld::create([
        ['shape' => 'ball', 'loc' => 'loc_A'],
        ['shape' => 'ball', 'loc' => 'loc_B'],
    ]);

    $pred = $brain->predict('mako lumi tara', $twinWorld);
    // Must safely refuse to act because which ball to move is under-specified!
    ok($pred['action'] === 'ASK');
};

$tests['explicit correction locally revises meaning without global retraining'] = function(): void {
    $brain = new GroundedBrain();

    // Establish vocabulary diversity so tokens have contrastive entropy
    $wB0 = AnonymousWorld::create([['shape' => 'cube', 'loc' => 'origin']]);
    $wB1 = AnonymousWorld::create([['shape' => 'cube', 'loc' => 'box']]);
    $brain->experience('nari keba kosa', $wB0, $wB1);

    $wB2 = AnonymousWorld::create([['shape' => 'cup', 'loc' => 'origin']]);
    $wB3 = AnonymousWorld::create([['shape' => 'cup', 'loc' => 'shelf']]);
    $brain->experience('vado sela rima', $wB2, $wB3);

    // Teach 'mako nova mesa' => key (3 times)
    for ($i = 0; $i < 3; $i++) {
        $w0 = AnonymousWorld::create([['shape' => 'key', 'loc' => 'origin']]);
        $w1 = AnonymousWorld::create([['shape' => 'key', 'loc' => 'table']]);
        $brain->experience('mako nova mesa', $w0, $w1);
    }

    $wCheck = AnonymousWorld::create([['shape' => 'key', 'loc' => 'origin'], ['shape' => 'phone', 'loc' => 'origin']]);
    $pBefore = $brain->predict('mako nova mesa', $wCheck);
    ok($pBefore['action'] === 'ACT');
    eq('key', $pBefore['target_entity']['shape']);

    // Teacher explicitly corrects: 'mako nova mesa' => phone
    $w0c = AnonymousWorld::create([['shape' => 'phone', 'loc' => 'origin']]);
    $w1c = AnonymousWorld::create([['shape' => 'phone', 'loc' => 'table']]);
    $brain->experience('mako nova mesa', $w0c, $w1c, isCorrection: true);

    $pAfter = $brain->predict('mako nova mesa', $wCheck);
    ok($pAfter['action'] === 'ACT');
    eq('phone', $pAfter['target_entity']['shape']);
};

$tests['persistence survives save and reload with checksum'] = function(): void {
    $brain = new GroundedBrain();
    $w0 = AnonymousWorld::create([['shape' => 'ball', 'loc' => 'origin']]);
    $w1 = AnonymousWorld::create([['shape' => 'ball', 'loc' => 'table']]);
    $brain->experience('mako lumi mesa', $w0, $w1);

    $w2 = AnonymousWorld::create([['shape' => 'cube', 'loc' => 'origin']]);
    $w3 = AnonymousWorld::create([['shape' => 'cube', 'loc' => 'box']]);
    $brain->experience('nari keba kosa', $w2, $w3);

    $scratchDir = is_dir('/Volumes/DEV-T7/Projects/hari-scratch') ? '/Volumes/DEV-T7/Projects/hari-scratch' : sys_get_temp_dir();
    $tmp = $scratchDir . '/ground-test-' . getmypid() . '.json';
    $brain->save($tmp);

    $loaded = GroundedBrain::load($tmp);
    @unlink($tmp);

    $wTest = AnonymousWorld::create([['shape' => 'ball', 'loc' => 'origin']]);
    $pred = $loaded->predict('mako lumi mesa', $wTest);
    ok($pred['action'] === 'ACT');
    eq('table', $pred['dest_val']);
};

$tests['filters frequent ambient environmental noise via PPMI'] = function(): void {
    $brain = new GroundedBrain();

    for ($i = 0; $i < 10; $i++) {
        // Target ball moves to table
        $target0 = ['shape' => 'ball', 'loc' => 'origin'];
        $target1 = ['shape' => 'ball', 'loc' => 'table'];
        // Ambient lamp also flickers
        $lamp0 = ['shape' => 'lamp', 'state' => 'on'];
        $lamp1 = ['shape' => 'lamp', 'state' => 'off'];

        $w0 = AnonymousWorld::create([$target0, $lamp0]);
        $w1 = AnonymousWorld::create([$target1, $lamp1]);
        $brain->experience('mako lumi mesa', $w0, $w1);

        // Lamp also flickers across other unrelated events
        $other0 = ['shape' => 'book', 'loc' => 'origin'];
        $other1 = ['shape' => 'book', 'loc' => 'shelf'];
        $w0b = AnonymousWorld::create([$other0, $lamp0]);
        $w1b = AnonymousWorld::create([$other1, $lamp1]);
        $brain->experience('nari dara tara', $w0b, $w1b);
    }

    // Query 'mako lumi mesa' in a world with the ball and the lamp
    $testWorld = AnonymousWorld::create([
        ['shape' => 'ball', 'loc' => 'origin'],
        ['shape' => 'lamp', 'state' => 'on'],
    ]);

    $pred = $brain->predict('mako lumi mesa', $testWorld);
    ok($pred['action'] === 'ACT');
    eq('ball', $pred['target_entity']['shape']);
    eq('table', $pred['dest_val']);
    eq('loc', $pred['dest_attr']);
};

$tests['hard resource ceilings enforced under churn'] = function(): void {
    $brain = new GroundedBrain();

    for ($i = 0; $i < 3000; $i++) {
        $w0 = AnonymousWorld::create([['shape' => 'junk_' . $i, 'loc' => 'origin']]);
        $w1 = AnonymousWorld::create([['shape' => 'junk_' . $i, 'loc' => 'table']]);
        $brain->experience('mako jw_' . $i . ' mesa', $w0, $w1);
    }

    $st = $brain->stats();
    ok($st['tokens'] <= 1024);
    ok($st['features'] <= 1024);
    ok($st['pairs'] <= 1024 * 48);
};

// --- Test Runner ---
$n = 0;
$t0 = microtime(true);
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $n++;
        echo "PASS  {$name}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAIL  {$name}\n{$e->getMessage()}\n");
        exit(1);
    }
}
printf("\n%d/%d tests passed in %.3fs\n", $n, count($tests), microtime(true) - $t0);

function eq(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new \RuntimeException("expected " . var_export($expected, true) . " got " . var_export($actual, true));
    }
}

function ok(bool $value): void {
    if (!$value) {
        throw new \RuntimeException("assertion failed");
    }
}
