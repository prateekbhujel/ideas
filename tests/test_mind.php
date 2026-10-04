<?php

declare(strict_types=1);

require_once __DIR__ . '/../hari/brain/Memory/WorkingMemory.php';
require_once __DIR__ . '/../hari/brain/Memory/EpisodicMemory.php';
require_once __DIR__ . '/../hari/brain/Memory/SemanticMemory.php';
require_once __DIR__ . '/../hari/brain/Memory/ProceduralMemory.php';
require_once __DIR__ . '/../hari/brain/Memory/WorldState.php';
require_once __DIR__ . '/../hari/brain/EpistemicEngine.php';
require_once __DIR__ . '/../hari/brain/ProvenanceEngine.php';
require_once __DIR__ . '/../hari/brain/SleepConsolidator.php';
require_once __DIR__ . '/../hari/brain/Mind.php';
require_once __DIR__ . '/../hari/body/RiskPolicy.php';
require_once __DIR__ . '/../hari/speech/SpeechClient.php';
require_once __DIR__ . '/../hari/speech/VoiceSetup.php';

use Hari\Brain\Mind;
use Hari\Body\RiskPolicy;
use Hari\Speech\VoiceSetup;

echo "=== HARI Embodied Mind Regression Suite ===\n\n";

$tests = [];

// 1. English & Nepali Greeting
$tests['multilingual_greeting'] = function(): void {
    $mind = new Mind();

    $resEn = $mind->processUtterance('Hey Hari');
    assert(str_contains(strtolower($resEn['spoken_reply']), 'assist') || str_contains(strtolower($resEn['spoken_reply']), 'here'));

    $resNe = $mind->processUtterance('नमस्ते हरि');
    assert(str_contains($resNe['spoken_reply'], 'नमस्ते') && str_contains($resNe['spoken_reply'], 'तयार'));
    echo "  [PASS] Multilingual greeting (English + Nepali)\n";
};

// 2. Fact Learning and Retention
$tests['interactive_fact_retention'] = function(): void {
    $mind = new Mind();

    // Teach fact: "Remember that I call this project Ground"
    $resTeach = $mind->processUtterance('Remember that I call this project Ground');
    assert(str_contains($resTeach['spoken_reply'], 'Ground'));

    // Recall fact: "What did I call this project?"
    $resRecall = $mind->processUtterance('What did I call this project?');
    assert(str_contains($resRecall['spoken_reply'], 'Ground'));

    // Nepali recall: "प्रोजेक्टको नाम के हो?"
    $resRecallNe = $mind->processUtterance('प्रोजेक्टको नाम के हो?');
    assert(str_contains($resRecallNe['spoken_reply'], 'Ground'));
    echo "  [PASS] Fact learning & bilingual retention ('Ground')\n";
};

// 3. Provenance & "Why did you do that?"
$tests['provenance_audit_trail'] = function(): void {
    $mind = new Mind();

    // Trigger an action
    $mind->processUtterance('Open Terminal');

    // Query provenance
    $resWhy = $mind->processUtterance('Why did you do that?');
    $exp = $resWhy['explanation'];
    assert($exp['has_action'] === true);
    assert($exp['action_type'] === 'OPEN_APP');
    assert(str_contains($exp['explanation'], 'Open Terminal'));
    echo "  [PASS] Verifiable provenance trail ('Why did you do that?')\n";
};

// 4. Consequential Risk Policy
$tests['risk_policy_enforcement'] = function(): void {
    // Reversible: Open Terminal
    $planReversible = ['action_type' => 'OPEN_APP', 'params' => ['app_name' => 'Terminal']];
    $riskRev = RiskPolicy::assessRisk($planReversible);
    assert($riskRev['level'] === 'REVERSIBLE');
    assert($riskRev['needs_confirmation'] === false);

    // Consequential: Type shell command or press Enter
    $planDangerous = [
        'action_type' => 'TYPE',
        'params' => ['text' => 'rm -rf /', 'press_enter' => true]
    ];
    $riskDang = RiskPolicy::assessRisk($planDangerous);
    assert($riskDang['level'] === 'CONSEQUENTIAL');
    assert($riskDang['needs_confirmation'] === true);
    echo "  [PASS] Consequential action risk policy & safety gates\n";
};

// 5. Code-Switching Command Understanding
$tests['nepali_code_switching_actions'] = function(): void {
    $mind = new Mind();

    // "Terminal open gara"
    $res1 = $mind->processUtterance('Terminal open gara');
    assert($res1['plan']['action_type'] === 'OPEN_APP');
    assert($res1['plan']['params']['app_name'] === 'Terminal');

    // "Yo window left tira move gara"
    $res2 = $mind->processUtterance('Yo window left tira move gara');
    assert($res2['plan']['action_type'] === 'MOVE_WINDOW');
    assert($res2['plan']['params']['position'] === 'left');
    echo "  [PASS] Nepali-English code-switching commands\n";
};

// 6. Voice Setup Prompts Structure
$tests['voice_setup_prompts'] = function(): void {
    $vs = new VoiceSetup();
    $prompts = $vs->getPrompts();
    assert(count($prompts) >= 12);
    $hasNepali = false;
    $hasCodeSwitch = false;
    foreach ($prompts as $p) {
        if ($p['language'] === 'ne') $hasNepali = true;
        if ($p['language'] === 'mix') $hasCodeSwitch = true;
    }
    assert($hasNepali && $hasCodeSwitch);
    echo "  [PASS] Voice setup guided prompts (Nepali, English, code-switch)\n";
};

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
printf("\nAll %d/%d Embodied Mind tests PASSED.\n\n", $passed, count($tests));
