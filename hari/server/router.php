<?php

declare(strict_types=1);

/**
 * HARI Local HTTP & API Router in PHP.
 * Serves the Web UI and provides real-time REST endpoints for HARI embodiment.
 */

require_once __DIR__ . '/../brain/Memory/WorkingMemory.php';
require_once __DIR__ . '/../brain/Memory/EpisodicMemory.php';
require_once __DIR__ . '/../brain/Memory/SemanticMemory.php';
require_once __DIR__ . '/../brain/Memory/ProceduralMemory.php';
require_once __DIR__ . '/../brain/Memory/WorldState.php';
require_once __DIR__ . '/../brain/EpistemicEngine.php';
require_once __DIR__ . '/../brain/ProvenanceEngine.php';
require_once __DIR__ . '/../brain/SleepConsolidator.php';
require_once __DIR__ . '/../brain/Mind.php';

require_once __DIR__ . '/../body/Hands.php';
require_once __DIR__ . '/../body/RiskPolicy.php';
require_once __DIR__ . '/../body/Eyes.php';
require_once __DIR__ . '/../body/TemporalDiff.php';
require_once __DIR__ . '/../body/Ears.php';

require_once __DIR__ . '/../speech/SpeechClient.php';
require_once __DIR__ . '/../speech/VoiceSetup.php';

use Hari\Brain\Mind;
use Hari\Body\Hands;
use Hari\Body\RiskPolicy;
use Hari\Body\Eyes;
use Hari\Body\TemporalDiff;
use Hari\Body\Ears;
use Hari\Speech\SpeechClient;
use Hari\Speech\VoiceSetup;

// Global Singletons cached across requests in worker
global $hariMind, $hariEyes, $hariTemporalDiff, $hariEars, $hariSpeech, $hariVoiceSetup;

if (!isset($hariMind)) {
    $hariMind = new Mind();
    $hariEyes = new Eyes();
    $hariTemporalDiff = new TemporalDiff();
    $hariSpeech = new SpeechClient();
    $hariVoiceSetup = new VoiceSetup();
    $hariEars = new Ears(function() use ($hariSpeech, $hariMind) {
        $hariSpeech->stop();
        $hariMind->workingMemory->flagInterrupted();
    });
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// CORS headers for local UI
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 1. Static UI routes
if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../ui/index.html');
    exit;
}

if ($uri === '/voice-setup' || $uri === '/voice_setup.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../ui/voice_setup.html');
    exit;
}

if ($uri === '/style.css') {
    header('Content-Type: text/css');
    readfile(__DIR__ . '/../ui/style.css');
    exit;
}

if ($uri === '/app.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/../ui/app.js');
    exit;
}

// Serve synthesized audio files from SSD scratch
if (str_starts_with($uri, '/audio/')) {
    $filename = basename($uri);
    $path = "/Volumes/DEV-T7/Projects/hari-scratch/{$filename}";
    if (file_exists($path)) {
        header('Content-Type: audio/wav');
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo "Audio not found";
    exit;
}

// 2. REST API Endpoints
header('Content-Type: application/json; charset=utf-8');

if ($uri === '/api/status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $screenObs = $hariEyes->perceive();
    $delta = $hariTemporalDiff->computeDelta([
        'frontmost_app' => $screenObs['frontmost_app'],
        'windows' => $screenObs['windows'],
    ]);
    $mindSummary = $hariMind->getMindStateSummary();

    echo json_encode([
        'status' => 'ok',
        'mind' => $mindSummary,
        'screen' => $screenObs,
        'delta' => $delta,
        'voice_status' => $hariVoiceSetup->getStatus(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($input['text'] ?? ''));
    $camB64 = $input['camera_b64'] ?? null;
    $isCorrection = (bool)($input['is_correction'] ?? false);

    if ($text === '') {
        echo json_encode(['status' => 'error', 'message' => 'Empty input']);
        exit;
    }

    // 1. Gather Screen & Camera observations
    $screenObs = $hariEyes->perceive();
    $camObs = null;
    if ($camB64 !== null) {
        $camObs = $hariEyes->ingestCameraB64($camB64);
    }

    // 2. Process in Cognitive Mind
    $cognitiveRes = $hariMind->processUtterance($text, $screenObs, $camObs, $isCorrection);
    $spokenReply = $cognitiveRes['spoken_reply'] ?? '';
    $plan = $cognitiveRes['plan'] ?? null;
    $epistemic = $cognitiveRes['epistemic'] ?? null;

    // 3. Execute Action if present and risk allows
    $actionResult = null;
    if ($plan !== null) {
        $risk = RiskPolicy::assessRisk($plan);
        $plan['risk_level'] = $risk['level'];
        $plan['needs_confirmation'] = $risk['needs_confirmation'];

        if (!$risk['needs_confirmation']) {
            if ($plan['action_type'] === 'OPEN_APP') {
                $actionResult = Hands::openApp($plan['params']['app_name'] ?? 'Terminal');
            } elseif ($plan['action_type'] === 'MOVE_WINDOW') {
                $actionResult = Hands::moveWindow($plan['params']['app_name'] ?? 'Terminal', $plan['params']['position'] ?? 'left');
            } elseif ($plan['action_type'] === 'TYPE') {
                $actionResult = Hands::typeText($plan['params']['text'] ?? '', false);
            }
        }
    }

    // 4. Synthesize voice aloud via SpeechClient
    $audioUrl = null;
    if ($spokenReply !== '') {
        $hariSpeech->stop(); // Stop prior playback
        $audioPath = $hariSpeech->synthesize($spokenReply);
        if ($audioPath !== null && file_exists($audioPath)) {
            $hariSpeech->play($audioPath);
            $audioUrl = '/audio/' . basename($audioPath);
        }
    }

    echo json_encode([
        'status' => 'ok',
        'reply' => $spokenReply,
        'plan' => $plan,
        'action_result' => $actionResult,
        'epistemic' => $epistemic,
        'audio_url' => $audioUrl,
        'mind' => $hariMind->getMindStateSummary(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/action/confirm' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $plan = $input['plan'] ?? [];
    $actionResult = null;

    if (($plan['action_type'] ?? '') === 'TYPE') {
        $text = $plan['params']['text'] ?? '';
        $pressEnter = (bool)($plan['params']['press_enter'] ?? false);
        $actionResult = Hands::typeText($text, $pressEnter);
    }

    echo json_encode(['status' => 'ok', 'action_result' => $actionResult]);
    exit;
}

if ($uri === '/api/voice/interrupt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $hariSpeech->stop();
    $hariMind->workingMemory->flagInterrupted();
    echo json_encode(['status' => 'ok', 'interrupted' => true]);
    exit;
}

// 3. Voice Setup Endpoints
if ($uri === '/api/voice/prompts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'prompts' => $hariVoiceSetup->getPrompts(),
        'speaker_profile' => $hariVoiceSetup->getStatus(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/voice/record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $promptId = $input['prompt_id'] ?? '';
    $audioB64 = $input['audio_b64'] ?? '';

    if ($promptId === '' || $audioB64 === '') {
        echo json_encode(['status' => 'error', 'message' => 'Missing prompt_id or audio data']);
        exit;
    }

    if (str_contains($audioB64, ',')) {
        $audioB64 = explode(',', $audioB64, 2)[1];
    }

    $tempWav = "/Volumes/DEV-T7/Projects/hari-scratch/temp_voice_{$promptId}.wav";
    file_put_contents($tempWav, base64_decode($audioB64));

    $res = $hariVoiceSetup->recordSample($promptId, $tempWav, [
        'environment' => $input['environment'] ?? 'macbook_air_mic',
    ]);
    @unlink($tempWav);

    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($uri === '/api/voice/status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['status' => 'ok', 'profile' => $hariVoiceSetup->getStatus()]);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'not_found', 'path' => $uri]);
