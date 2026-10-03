<?php

declare(strict_types=1);

$root = getenv('HARI_ROOT') ?: '/tmp/hari';
require $root . '/bootstrap.php';

use Hari\Memory\EpisodicMemory;
use Hari\Learning\HabitLearner;
use Hari\Agent\PersonalContext;

$tests = [];

$tests['episodic memory decays but cues recover and reinforce it'] = static function (): void {
    $memory = new EpisodicMemory(decayPerTick: 0.03, forgetBelow: 0.02);
    $episode = $memory->remember('Mom calls Viber a video phone', ['mom', 'viber', 'video call'], importance: 0.75);
    $memory->remember('Dad prefers the gallery app', ['dad', 'gallery'], importance: 0.5);
    $memory->advance(40);
    $before = $episode->strength;
    if (!($before < 1.0)) throw new RuntimeException('memory did not decay');
    $recalled = $memory->recall('video phone', ['mom']);
    if ($recalled === [] || $recalled[0]['episode']->id !== $episode->id) throw new RuntimeException('cue recall failed');
    if (!($episode->strength > $before)) throw new RuntimeException('recall did not reinforce memory');
};

$tests['important memories survive longer than ordinary memories'] = static function (): void {
    $memory = new EpisodicMemory(decayPerTick: 0.08, forgetBelow: 0.10);
    $ordinary = $memory->remember('temporary detail', ['noise'], importance: 0.1);
    $important = $memory->remember('Dad calls this person ठूलो मामा', ['dad', 'family'], importance: 0.98);
    $memory->advance(80);
    if (!($important->strength > $ordinary->strength)) throw new RuntimeException('importance did not affect retention');
    $memory->forgetWeak();
    $ids = array_map(static fn ($e) => $e->id, $memory->episodes());
    if (!in_array($important->id, $ids, true)) throw new RuntimeException('important memory was lost');
};

$tests['habit learner waits for evidence then predicts preference'] = static function (): void {
    $habits = new HabitLearner();
    $habits->observe('mom:call', 'whatsapp');
    $habits->observe('mom:call', 'whatsapp');
    if ($habits->predict('mom:call') !== null) throw new RuntimeException('habit formed too early');
    $habits->observe('mom:call', 'viber');
    $habits->observe('mom:call', 'whatsapp');
    $prediction = $habits->predict('mom:call');
    if ($prediction === null || $prediction['choice'] !== 'whatsapp' || $prediction['confidence'] <= 0.5) {
        throw new RuntimeException('habit prediction failed');
    }
};

$tests['personal context separates memories and learned habits'] = static function (): void {
    $context = new PersonalContext();
    $context->learnFact('Mom calls Pratik बाबु', ['mom', 'pratik']);
    $context->observeChoice('mom:video_call', 'whatsapp');
    $context->observeChoice('mom:video_call', 'whatsapp');
    $context->observeChoice('mom:video_call', 'whatsapp');
    $recall = $context->memory->recall('Pratik', ['mom'], reinforce: false);
    if ($recall === []) throw new RuntimeException('personal recall failed');
    if ($context->preferredChoice('mom:video_call')['choice'] !== 'whatsapp') throw new RuntimeException('personal habit failed');
};

$passed = 0;
foreach ($tests as $name => $test) {
    $test();
    ++$passed;
    fwrite(STDOUT, "PASS  {$name}\n");
}
fwrite(STDOUT, "\n{$passed}/" . count($tests) . " M2 tests passed.\n");
