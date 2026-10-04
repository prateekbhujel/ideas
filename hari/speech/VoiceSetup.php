<?php

declare(strict_types=1);

namespace Hari\Speech;

/**
 * HARI Personal Voice Setup & Data Collection System in PHP.
 * Guides the user through recording their voice across:
 * - Natural & conversational Nepali
 * - English
 * - Nepali-English code switching
 * - Technical commands, numbers, names, varied prosody
 * - Difficult Nepali phonetic combinations
 * Stores all data strictly on the external SSD: /Volumes/DEV-T7/Projects/hari-data/voice/pratik/
 * Performs automated audio quality verification (clipping, silence, duration).
 */
class VoiceSetup
{
    private string $voiceDataDir;
    private SpeechClient $speechClient;

    public function __construct(
        string $voiceDataDir = '/Volumes/DEV-T7/Projects/hari-data/voice/pratik',
        ?SpeechClient $speechClient = null
    ) {
        $this->voiceDataDir = $voiceDataDir;
        $this->speechClient = $speechClient ?? new SpeechClient();
        if (!is_dir($this->voiceDataDir)) {
            mkdir($this->voiceDataDir, 0777, true);
        }
    }

    /**
     * Comprehensive phonetically and linguistically balanced prompts.
     */
    public function getPrompts(): array
    {
        return [
            // 1. Natural & Conversational Nepali
            [
                'id' => 'ne_001',
                'category' => 'conversational_nepali',
                'language' => 'ne',
                'text' => 'नमस्ते, म आजको काम सुरु गर्न पूर्ण रूपमा तयार छु।',
                'roman' => 'Namaste, ma aajako kaam suru garna purna roopma tayar chu.',
                'style' => 'calm',
            ],
            [
                'id' => 'ne_002',
                'category' => 'conversational_nepali',
                'language' => 'ne',
                'text' => 'कस्तो छ तिमीलाई? आज मौसम कस्तो छ हेरेर भन त।',
                'roman' => 'Kasto cha timilai? Aaja mausam kasto cha herera bhana ta.',
                'style' => 'casual',
            ],
            [
                'id' => 'ne_003',
                'category' => 'conversational_nepali',
                'language' => 'ne',
                'text' => 'हामीले नयाँ आर्टिफिसियल इन्टेलिजेन्स प्रणाली बनाउँदै छौँ।',
                'roman' => 'Haamile naya artificial intelligence pranali banaundaichhau.',
                'style' => 'confident',
            ],

            // 2. English Commands & Colloquial
            [
                'id' => 'en_001',
                'category' => 'english_commands',
                'language' => 'en',
                'text' => 'Please open the Terminal window on my Mac.',
                'style' => 'direct',
            ],
            [
                'id' => 'en_002',
                'category' => 'english_commands',
                'language' => 'en',
                'text' => 'Move this window to the left half of the display.',
                'style' => 'calm',
            ],
            [
                'id' => 'en_003',
                'category' => 'english_commands',
                'language' => 'en',
                'text' => 'Remember that I call this research project Ground.',
                'style' => 'instructive',
            ],

            // 3. Nepali-English Code Switching (Real developer everyday speech!)
            [
                'id' => 'mix_001',
                'category' => 'code_switching',
                'language' => 'mix',
                'text' => 'Terminal open gara ra git status check gara ta.',
                'devanagari' => 'टर्मिनल ओपन गर र गिट स्टाटस चेक गर त।',
                'style' => 'fluent',
            ],
            [
                'id' => 'mix_002',
                'category' => 'code_switching',
                'language' => 'mix',
                'text' => 'Yo window lai left tira move gara, ani browser refresh gara.',
                'devanagari' => 'यो विन्डोलाई लेफ्ट तिर मुभ गर, अनि ब्राउजर रिफ्रेस गर।',
                'style' => 'fluent',
            ],
            [
                'id' => 'mix_003',
                'category' => 'code_switching',
                'language' => 'mix',
                'text' => 'Naya feature deploy bhaisakyo, ekchhoti log hernu paryo.',
                'devanagari' => 'नयाँ फिचर डिप्लोय भइसक्यो, एकचोटि लग हेर्नु पर्यो।',
                'style' => 'fluent',
            ],

            // 4. Questions & Interrogatives
            [
                'id' => 'q_001',
                'category' => 'questions',
                'language' => 'ne',
                'text' => 'मैले अहिले क्यामराको अगाडि के समातिरहेको छु?',
                'roman' => 'Maile ahile camerako agaadi k samatiraheko chu?',
                'style' => 'inquisitive',
            ],
            [
                'id' => 'q_002',
                'category' => 'questions',
                'language' => 'en',
                'text' => 'Why did you execute that action just now?',
                'style' => 'inquisitive',
            ],

            // 5. Technical Terms, Numbers & Names
            [
                'id' => 'tech_001',
                'category' => 'technical_terms',
                'language' => 'mix',
                'text' => 'Apple M4 chip ma unified memory 16 gigabytes cha.',
                'style' => 'precise',
            ],
            [
                'id' => 'tech_002',
                'category' => 'numbers_names',
                'language' => 'ne',
                'text' => 'एक, दुई, तीन, चार, पाँच, छ, सात, आठ, नौ, दश।',
                'roman' => 'Ek, dui, teen, chaar, paanch, chha, saat, aath, nau, das.',
                'style' => 'measured',
            ],

            // 6. Difficult Nepali Phonetic Combinations (Conjuncts & Retroflexes)
            [
                'id' => 'phon_001',
                'category' => 'phonetic_stress',
                'language' => 'ne',
                'text' => 'ऋषि, ज्ञानी, क्षत्रिय, त्रिभुवन, ङ्याउरो, र प्रकृतिको सौन्दर्य।',
                'roman' => 'Rishi, gyaani, kshatriya, tribhuwan, nyaauro, ra prakritiko saundarya.',
                'style' => 'articulate',
            ],
            [
                'id' => 'phon_002',
                'category' => 'phonetic_stress',
                'language' => 'ne',
                'text' => 'कष्ट, विशिष्ट, ज्येष्ठ, प्रतिष्ठान, र दृष्टिकोण।',
                'roman' => 'Kashta, bishishta, jyeshtha, pratishthan, ra drishtikon.',
                'style' => 'articulate',
            ],
        ];
    }

    public function recordSample(string $promptId, string $tempAudioWavPath, array $recordingMetadata = []): array
    {
        $prompts = $this->getPrompts();
        $targetPrompt = null;
        foreach ($prompts as $p) {
            if ($p['id'] === $promptId) {
                $targetPrompt = $p;
                break;
            }
        }

        if ($targetPrompt === null) {
            return ['status' => 'error', 'message' => "Prompt {$promptId} not found"];
        }

        // Quality check via acoustic worker
        $quality = $this->speechClient->checkQuality($tempAudioWavPath);
        if (($quality['status'] ?? '') !== 'ok' || empty($quality['is_usable'])) {
            return [
                'status' => 'rejected',
                'quality' => $quality,
                'message' => 'Audio rejected by automated quality check (too quiet, clipped, or too short). Please re-record.',
            ];
        }

        // Persist to SSD
        $promptDir = $this->voiceDataDir . '/' . $promptId;
        if (!is_dir($promptDir)) {
            mkdir($promptDir, 0777, true);
        }

        $destWav = $promptDir . '/audio.wav';
        copy($tempAudioWavPath, $destWav);

        $transcriptTxt = $targetPrompt['text'] . "\n" . ($targetPrompt['roman'] ?? '') . "\n";
        file_put_contents($promptDir . '/transcript.txt', $transcriptTxt);

        $meta = [
            'prompt_id' => $promptId,
            'language' => $targetPrompt['language'],
            'category' => $targetPrompt['category'],
            'style' => $targetPrompt['style'],
            'quality' => $quality,
            'speaker' => 'pratik',
            'timestamp' => microtime(true),
            'environment' => $recordingMetadata['environment'] ?? 'macbook_air_mic',
        ];
        file_put_contents($promptDir . '/metadata.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->updateSpeakerProfile();

        return [
            'status' => 'accepted',
            'prompt_id' => $promptId,
            'quality' => $quality,
            'saved_to' => $destWav,
        ];
    }

    public function updateSpeakerProfile(): array
    {
        $dirs = glob($this->voiceDataDir . '/*', GLOB_ONLYDIR) ?: [];
        $samples = [];
        $totalDuration = 0.0;

        foreach ($dirs as $d) {
            $metaFile = $d . '/metadata.json';
            if (file_exists($metaFile)) {
                $m = json_decode(file_get_contents($metaFile), true);
                if (is_array($m)) {
                    $samples[] = $m;
                    $totalDuration += (float)($m['quality']['duration_s'] ?? 0.0);
                }
            }
        }

        $profile = [
            'speaker' => 'pratik',
            'total_samples' => count($samples),
            'total_duration_s' => round($totalDuration, 2),
            'total_duration_min' => round($totalDuration / 60.0, 2),
            'languages' => [
                'ne' => count(array_filter($samples, fn($s) => $s['language'] === 'ne')),
                'en' => count(array_filter($samples, fn($s) => $s['language'] === 'en')),
                'mix' => count(array_filter($samples, fn($s) => $s['language'] === 'mix')),
            ],
            'voice_identity' => [
                'timbre' => 'natural_warm',
                'pitch' => 'baritone_medium',
                'cadence' => 'conversational',
            ],
            'last_updated' => microtime(true),
        ];

        file_put_contents($this->voiceDataDir . '/speaker_profile.json', json_encode($profile, JSON_PRETTY_PRINT));
        return $profile;
    }

    public function getStatus(): array
    {
        $profileFile = $this->voiceDataDir . '/speaker_profile.json';
        if (file_exists($profileFile)) {
            return json_decode(file_get_contents($profileFile), true);
        }
        return [
            'speaker' => 'pratik',
            'total_samples' => 0,
            'total_duration_s' => 0.0,
            'total_duration_min' => 0.0,
        ];
    }
}
