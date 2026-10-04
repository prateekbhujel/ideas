<?php

declare(strict_types=1);

namespace Hari\Brain;

use Hari\Brain\Memory\WorkingMemory;
use Hari\Brain\Memory\EpisodicMemory;
use Hari\Brain\Memory\SemanticMemory;
use Hari\Brain\Memory\ProceduralMemory;
use Hari\Brain\Memory\WorldState;

/**
 * HARI Cognitive Mind in PHP.
 * The central coordinator for perception, memory, multilingual language (Nepali & English),
 * epistemic deliberation, action planning, and continuous grounded learning.
 */
class Mind
{
    public WorkingMemory $workingMemory;
    public EpisodicMemory $episodicMemory;
    public SemanticMemory $semanticMemory;
    public ProceduralMemory $proceduralMemory;
    public WorldState $worldState;
    public EpistemicEngine $epistemicEngine;
    public ProvenanceEngine $provenanceEngine;
    public SleepConsolidator $sleepConsolidator;

    public string $status = 'ALIVE';
    public string $lastUserSpeech = '';
    public string $lastHariSpeech = 'HARI is alive and listening.';

    public function __construct()
    {
        $this->workingMemory = new WorkingMemory();
        $this->episodicMemory = new EpisodicMemory();
        $this->semanticMemory = SemanticMemory::load();
        $this->proceduralMemory = new ProceduralMemory();
        $this->worldState = new WorldState();
        $this->epistemicEngine = new EpistemicEngine();
        $this->provenanceEngine = new ProvenanceEngine();
        $this->sleepConsolidator = new SleepConsolidator(
            $this->episodicMemory,
            $this->semanticMemory,
            $this->proceduralMemory
        );
    }

    public function processUtterance(
        string $utterance,
        ?array $screenContext = null,
        ?array $cameraContext = null,
        bool $isCorrection = false
    ): array {
        $utt = trim($utterance);
        $this->lastUserSpeech = $utt;
        $this->workingMemory->addTurn('user', $utt);

        if ($screenContext !== null) {
            $this->worldState->updateScreenState(
                $screenContext['frontmost_app'] ?? 'Finder',
                $screenContext['windows'] ?? []
            );
        }
        if ($cameraContext !== null) {
            $this->worldState->updateCameraState($cameraContext['detected_objects'] ?? []);
        }

        $textLower = mb_strtolower($utt);
        $isNepali = (bool)preg_match('/[\p{Devanagari}]/u', $utt);

        // 1. Provenance Query: "Why did you do that?" / "किन त्यसो गरिस?"
        if ($this->isMatch($textLower, ['why did you do that', 'explain why', 'show evidence', 'kin tyaso', 'किन त्यसो'])) {
            $explanation = $this->provenanceEngine->explainLatestAction();
            $reply = $explanation['explanation'];
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null, 'explanation' => $explanation];
        }

        // 2. Fact Teaching: "Remember that I call this project Ground" / "यो प्रोजेक्टको नाम Ground हो"
        if (preg_match('/(?:remember (?:that )?(?:i call )?(?:this )?project (?:is |as |name is )?([a-zA-Z0-9_-]+)|प्रोजेक्ट(?:को)? (?:नाम )?([a-zA-Z0-9_-]+))/iu', $utt, $m)) {
            $projName = ucfirst(!empty($m[1]) ? $m[1] : $m[2]);
            $this->semanticMemory->storeFact('project', 'name', $projName, $utt);
            $this->semanticMemory->save();
            $reply = $isNepali
                ? "मैले सम्झिएँ। हजुरले यो प्रोजेक्टलाई {$projName} भन्नुहुन्छ।"
                : "Remembered. You call this project {$projName}.";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        // Camera Object Teaching: "Remember this as my red notebook"
        if (preg_match('/(?:remember this as (?:my |a )?([a-zA-Z0-9_ ]+)|यसलाई ([a-zA-Z0-9_\p{Devanagari} ]+) भनेर सम्झ)/iu', $utt, $m)) {
            $objLabel = trim(!empty($m[1]) ? $m[1] : $m[2]);
            $this->semanticMemory->storeFact('held_object', 'label', $objLabel, $utt);
            $this->semanticMemory->groundExperience(
                "holding {$objLabel}",
                [["cam:label={$objLabel}", 'cam:label'], ['cam:held=true', 'cam:held']],
                true
            );
            $this->semanticMemory->save();
            $reply = $isNepali
                ? "मैले यो वस्तुलाई हजुरको {$objLabel} भनेर सम्झिएँ।"
                : "I will remember this object as your {$objLabel}.";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        // 3. Fact Recall: "What did I call this project?"
        if ($this->isMatch($textLower, ['what did i call this project', 'project name', 'name of this project', 'प्रोजेक्टको नाम', 'project ko naam'])) {
            $val = $this->semanticMemory->queryFact('project', 'name');
            if ($val !== null) {
                $reply = $isNepali ? "हजुरले यो प्रोजेक्टलाई {$val} भन्नुभएको थियो।" : "You call this project {$val}.";
            } else {
                $reply = $isNepali ? "मैले अझै प्रोजेक्टको नाम सिकेको छैन।" : "I do not have a recorded project name yet.";
            }
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        // Object Recall: "What am I holding?" / "मैले समातेको के हो?"
        if ($this->isMatch($textLower, ['what am i holding', 'what is this', 'what do you see', 'समातेको के हो', 'yo k ho'])) {
            $val = $this->semanticMemory->queryFact('held_object', 'label');
            if ($val !== null) {
                $reply = $isNepali ? "हजुरले आफ्नो {$val} समात्नुभएको छ।" : "You are holding your {$val}.";
            } else {
                $reply = $isNepali ? "मैले वस्तु देखेँ, तर यसको नाम के राखौँ?" : "I see you holding an object, but what should I remember it as?";
            }
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        // 4. Screen Perception: "What application is open?" / "कुन एप खुलेको छ?"
        if ($this->isMatch($textLower, ['what application is open', 'what app is open', 'frontmost app', 'कुन एप खुलेको', 'kun app khuleko'])) {
            $app = $this->worldState->frontmostApp ?? 'Finder';
            $winCount = count($this->worldState->openWindows);
            $reply = $isNepali
                ? "अहिले सक्रिय एप {$app} हो, जसमा {$winCount} वटा विन्डो खुला छन्।"
                : "The active application is {$app}, with {$winCount} open windows visible.";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        if ($this->isMatch($textLower, ['look at my screen', 'what is on my screen', 'read screen', 'स्क्रिन हेर', 'screen hera'])) {
            $app = $this->worldState->frontmostApp ?? 'Finder';
            $reply = $isNepali
                ? "मैले हजुरको स्क्रिन हेरिरहेको छु। हाल {$app} सबैभन्दा अगाडि छ।"
                : "I am observing your screen. Currently, {$app} is frontmost.";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        // 5. Greeting: "Hey Hari" / "नमस्ते हरि"
        $cleanTrim = trim($textLower, " \t\n\r\0\x0B.!?,");
        $greetings = ['hey hari', 'hello hari', 'hari', 'hi hari', 'hello', 'namaste', 'namaste hari', 'नमस्ते', 'नमस्ते हरि'];
        if (in_array($cleanTrim, $greetings, true)) {
            $reply = $isNepali
                ? "नमस्ते! म हजुरको म्याकमा तयार छु। के मद्दत गरौँ?"
                : "I am here. How can I assist you on your Mac?";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => null];
        }

        // 6. Intent Deliberation via Epistemic Engine
        $candidates = $this->synthesizeCandidates($textLower, $isCorrection, $isNepali);
        $epistemic = $this->epistemicEngine->evaluateIntent($candidates);

        if ($epistemic['action'] === 'ASK') {
            $reply = $isNepali
                ? "म दुविधामा छु: {$epistemic['reason']}। कृपया स्पष्ट गरिदिनुहुन्छ कि?"
                : "I am uncertain: {$epistemic['reason']}. Could you clarify what you'd like me to do?";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => $epistemic];
        }

        // Top intent supported: construct ActionPlan
        $chosen = null;
        foreach ($candidates as $c) {
            if ($c['intent'] === $epistemic['top_hypothesis']) {
                $chosen = $c;
                break;
            }
        }

        if ($chosen === null) {
            $reply = "I could not construct a plan for that request.";
            $this->recordHariTurn($reply);
            return ['spoken_reply' => $reply, 'plan' => null, 'epistemic' => $epistemic];
        }

        $plan = $chosen['plan'];
        $reply = $chosen['reply_text'];

        // Record Provenance
        $this->provenanceEngine->recordDecision(
            $plan['action_type'],
            $utt,
            SemanticMemory::tokenize($utt),
            $chosen['supporting_features'] ?? [],
            $epistemic['confidence'],
            $epistemic['margin'],
            array_filter(array_map(fn($c) => $c['intent'], $candidates), fn($i) => $i !== $epistemic['top_hypothesis']),
            ['frontmost_app' => $this->worldState->frontmostApp]
        );

        $this->recordHariTurn($reply, $plan['action_type']);

        // Record in Episodic Memory
        $this->episodicMemory->record(
            $utt,
            $plan['action_type'],
            'planned',
            ['app' => $this->worldState->frontmostApp],
            [],
            $plan['risk_level'] === 'CONSEQUENTIAL' ? 2.0 : 1.0,
            "intent:{$epistemic['top_hypothesis']}"
        );

        return [
            'spoken_reply' => $reply,
            'plan' => $plan,
            'epistemic' => $epistemic,
        ];
    }

    private function synthesizeCandidates(string $text, bool $isCorrection, bool $isNepali): array
    {
        $candidates = [];

        // Intent: Open App ("Open Terminal" / "टर्मिनल खोल" / "Terminal open gara")
        if (preg_match('/(?:open (?:the )?([a-zA-Z0-9_-]+)|([a-zA-Z0-9_-]+) (?:खोल|open gara))/iu', $text, $m)) {
            $appName = ucfirst(!empty($m[1]) ? $m[1] : $m[2]);
            $candidates[] = [
                'intent' => 'open_' . strtolower($appName),
                'score' => $isCorrection ? 0.95 : 0.90,
                'reply_text' => $isNepali ? "{$appName} खोल्दै छु।" : "Opening {$appName}.",
                'supporting_features' => [['feature' => "act:open={$appName}", 'score' => 0.90]],
                'plan' => [
                    'action_type' => 'OPEN_APP',
                    'params' => ['app_name' => $appName],
                    'risk_level' => 'REVERSIBLE',
                    'needs_confirmation' => false,
                    'reason' => "Launch {$appName}",
                ],
            ];
        }

        // Intent: Move Window ("Move Terminal window to the left" / "Yo window left tira move gara")
        if (preg_match('/(?:move (?:the )?([a-zA-Z0-9_-]+)? ?window (?:to the )?(left|right|center)|window (left|right|center) tira move gara)/iu', $text, $m)) {
            $app = !empty($m[1]) ? ucfirst($m[1]) : ($this->worldState->frontmostApp ?? 'Terminal');
            $pos = !empty($m[2]) ? $m[2] : ($m[3] ?? 'left');
            $candidates[] = [
                'intent' => "move_window_{$pos}",
                'score' => 0.88,
                'reply_text' => $isNepali ? "{$app} विन्डोलाई {$pos} तिर सार्दै छु।" : "Moving the {$app} window to the {$pos}.",
                'supporting_features' => [['feature' => "act:move_window={$pos}", 'score' => 0.88]],
                'plan' => [
                    'action_type' => 'MOVE_WINDOW',
                    'params' => ['app_name' => $app, 'position' => $pos],
                    'risk_level' => 'REVERSIBLE',
                    'needs_confirmation' => false,
                    'reason' => "Move {$app} to {$pos}",
                ],
            ];
        }

        // Intent: Type Text ("Type echo hello hari")
        if (preg_match('/(?:type (.+)|टाइप गर (.+))/iu', $text, $m)) {
            $toType = trim(!empty($m[1]) ? $m[1] : $m[2]);
            $isConsequential = (bool)preg_match('/(?:rm |sudo|echo|curl|bash|kill|git)/i', $toType);
            $candidates[] = [
                'intent' => 'type_keystrokes',
                'score' => 0.85,
                'reply_text' => $isNepali
                    ? "टाइप गर्दै छु: {$toType}। हजुरको अनुमति बिना इन्टर थिच्ने छैन।"
                    : "Typing: {$toType}. I will not press Enter without your confirmation.",
                'supporting_features' => [['feature' => 'act:type_text', 'score' => 0.85]],
                'plan' => [
                    'action_type' => 'TYPE',
                    'params' => ['text' => $toType, 'press_enter' => false],
                    'risk_level' => $isConsequential ? 'CONSEQUENTIAL' : 'REVERSIBLE',
                    'needs_confirmation' => $isConsequential,
                    'reason' => "Type text: {$toType}",
                ],
            ];
        }

        return $candidates;
    }

    private function isMatch(string $text, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (str_contains($text, $p)) {
                return true;
            }
        }
        return false;
    }

    private function recordHariTurn(string $reply, ?string $action = null): void
    {
        $this->workingMemory->addTurn('hari', $reply, $action);
        $this->lastHariSpeech = $reply;
    }

    public function getMindStateSummary(): array
    {
        return [
            'timestamp' => microtime(true),
            'status' => $this->status,
            'senses' => ['mic' => true, 'camera' => true, 'screen' => true, 'hands' => true],
            'current_belief' => $this->worldState->getSummary(),
            'working_memory_count' => count($this->workingMemory->getTurns()),
            'episodic_count' => $this->episodicMemory->count(),
            'semantic_tokens' => count($this->semanticMemory->tokens),
            'semantic_features' => count($this->semanticMemory->features),
            'resource_stats' => $this->semanticMemory->stats(),
            'last_user_speech' => $this->lastUserSpeech,
            'last_hari_speech' => $this->lastHariSpeech,
        ];
    }
}
