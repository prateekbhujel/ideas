<?php

declare(strict_types=1);

namespace Hari\Body;

/**
 * HARI Ears & Turn Management in PHP.
 * Controls conversational state, VAD silence thresholds, and barge-in cut-off.
 */
class Ears
{
    public string $state = 'LISTENING';
    public float $lastSpeechTime = 0.0;
    public float $userTurnStart = 0.0;
    public float $silenceTimeout = 0.60; // 600ms
    /** @var callable|null */
    public $onBargeIn = null;

    public function __construct(?callable $onBargeIn = null)
    {
        $this->onBargeIn = $onBargeIn;
    }

    public function onUserSpeechStart(): bool
    {
        $now = microtime(true);
        $wasInterrupted = false;

        if ($this->state === 'HARI_SPEAKING') {
            $this->state = 'INTERRUPTED';
            $wasInterrupted = true;
            if ($this->onBargeIn !== null) {
                ($this->onBargeIn)();
            }
        } else {
            $this->state = 'USER_SPEAKING';
        }

        $this->userTurnStart = $now;
        $this->lastSpeechTime = $now;
        return $wasInterrupted;
    }

    public function onUserSpeechChunk(): void
    {
        $this->lastSpeechTime = microtime(true);
        if ($this->state !== 'INTERRUPTED') {
            $this->state = 'USER_SPEAKING';
        }
    }

    public function checkTurnCompletion(): bool
    {
        if (in_array($this->state, ['USER_SPEAKING', 'INTERRUPTED'], true)) {
            $elapsed = microtime(true) - $this->lastSpeechTime;
            if ($elapsed >= $this->silenceTimeout) {
                $this->state = 'THINKING';
                return true;
            }
        }
        return false;
    }

    public function onHariSpeechStart(): void
    {
        $this->state = 'HARI_SPEAKING';
    }

    public function onHariSpeechEnd(): void
    {
        if ($this->state !== 'INTERRUPTED') {
            $this->state = 'LISTENING';
        }
    }
}
