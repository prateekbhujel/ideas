<?php

declare(strict_types=1);

namespace Hari\Life;

final readonly class SemanticFrame
{
    /** @param array<string,string> $args */
    public function __construct(
        public string $verb,
        public array $args = [],
        public bool $negated = false,
    ) {
        if ($verb === '') {
            throw new \InvalidArgumentException('verb required');
        }
    }

    public static function parse(string $canonical): self
    {
        $parts = preg_split('/\s+/u', trim($canonical)) ?: [];
        if ($parts === []) {
            throw new \InvalidArgumentException('empty frame');
        }
        $negated = false;
        if (strtoupper($parts[0]) === 'NOT') {
            $negated = true;
            array_shift($parts);
        }
        $verb = strtoupper((string) array_shift($parts));
        if ($verb === '') {
            throw new \InvalidArgumentException('verb required');
        }
        $args = [];
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                throw new \InvalidArgumentException("bad frame argument: {$part}");
            }
            [$key, $value] = explode('=', $part, 2);
            if ($key === '' || $value === '') {
                throw new \InvalidArgumentException("bad frame argument: {$part}");
            }
            $args[$key] = $value;
        }
        ksort($args);
        return new self($verb, $args, $negated);
    }

    /** @return list<string> */
    public function atoms(): array
    {
        $atoms = ['verb:' . $this->verb];
        foreach ($this->args as $role => $value) {
            $atoms[] = 'arg.' . $role . ':' . $value;
        }
        if ($this->negated) {
            $atoms[] = 'polarity:NEG';
        }
        return $atoms;
    }

    public function canonical(): string
    {
        $parts = [$this->negated ? 'NOT ' . $this->verb : $this->verb];
        foreach ($this->args as $role => $value) {
            $parts[] = $role . '=' . $value;
        }
        return implode(' ', $parts);
    }

    public function equals(self $other): bool
    {
        return $this->canonical() === $other->canonical();
    }
}

final readonly class Effect
{
    public function __construct(public string $key, public string $value)
    {
        if ($key === '') {
            throw new \InvalidArgumentException('effect key required');
        }
    }

    public static function parse(string $text): self
    {
        if (!str_contains($text, '=')) {
            throw new \InvalidArgumentException('effect must be key=value');
        }
        [$key, $value] = explode('=', $text, 2);
        return new self(trim($key), trim($value));
    }

    public function canonical(): string
    {
        return $this->key . '=' . $this->value;
    }
}

final readonly class Inference
{
    /** @param array<string,mixed> $trace */
    public function __construct(
        public ?SemanticFrame $frame,
        public float $confidence,
        public bool $shouldAsk,
        public string $question,
        public array $trace,
    ) {}
}

