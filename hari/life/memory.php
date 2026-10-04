<?php

declare(strict_types=1);

namespace Hari\Life;

final class AssociationMemory
{
    /** @var array<string,array{count:float,last:int,pairs:array<string,array{count:float,last:int}>}> */
    private array $tokens = [];
    /** @var array<string,array{count:float,last:int}> */
    private array $atoms = [];

    public function __construct(
        private int $step = 0,
        private readonly float $decay = 0.9995,
        private readonly int $maxTokens = 1024,
        private readonly int $maxPairsPerToken = 48,
    ) {}

    public function step(): int { return $this->step; }

    /** @param list<string> $tokens @param list<string> $atoms */
    public function learn(array $tokens, array $atoms, float $strength = 1.0, bool $correction = false): void
    {
        ++$this->step;
        $tokens = array_values(array_unique($tokens));
        $atoms = array_values(array_unique($atoms));

        foreach ($atoms as $atom) {
            $entry = $this->atoms[$atom] ?? ['count' => 0.0, 'last' => $this->step];
            $entry['count'] = $this->effective($entry['count'], $entry['last']) + $strength;
            $entry['last'] = $this->step;
            $this->atoms[$atom] = $entry;
        }

        foreach ($tokens as $token) {
            $entry = $this->tokens[$token] ?? ['count' => 0.0, 'last' => $this->step, 'pairs' => []];
            $entry['count'] = $this->effective($entry['count'], $entry['last']) + $strength;
            $entry['last'] = $this->step;

            if ($correction && $entry['pairs'] !== []) {
                $truthByCategory = [];
                foreach ($atoms as $truthAtom) $truthByCategory[$this->categoryOf($truthAtom)][$truthAtom] = true;
                foreach ($entry['pairs'] as $oldAtom => &$oldPair) {
                    $category = $this->categoryOf($oldAtom);
                    if (isset($truthByCategory[$category]) && !isset($truthByCategory[$category][$oldAtom])) {
                        $oldPair['count'] = $this->effective($oldPair['count'], $oldPair['last']) * 0.05;
                        $oldPair['last'] = $this->step;
                    }
                }
                unset($oldPair);
            }

            foreach ($atoms as $atom) {
                $pair = $entry['pairs'][$atom] ?? ['count' => 0.0, 'last' => $this->step];
                $pair['count'] = $this->effective($pair['count'], $pair['last']) + $strength;
                $pair['last'] = $this->step;
                $entry['pairs'][$atom] = $pair;
            }

            if (count($entry['pairs']) > $this->maxPairsPerToken) {
                uasort($entry['pairs'], fn(array $a, array $b): int => $this->effective($b['count'], $b['last']) <=> $this->effective($a['count'], $a['last']));
                $entry['pairs'] = array_slice($entry['pairs'], 0, $this->maxPairsPerToken, true);
            }
            $this->tokens[$token] = $entry;
        }

        $this->enforceTokenBudget();
    }

    /** @param list<string> $inputTokens @return array{atom:?string,score:float,token:?string,runner_up:float} */
    public function best(array $inputTokens, string $category): array
    {
        $bestAtom = null;
        $bestToken = null;
        $best = 0.0;
        $runner = 0.0;
        foreach (array_unique($inputTokens) as $token) {
            $entry = $this->tokens[$token] ?? null;
            if ($entry === null) continue;
            foreach ($entry['pairs'] as $atom => $pair) {
                if (!$this->inCategory($atom, $category)) continue;
                $score = $this->associationScore($token, $atom);
                if ($score > $best) {
                    $runner = $best;
                    $best = $score;
                    $bestAtom = $atom;
                    $bestToken = $token;
                } elseif ($score > $runner) {
                    $runner = $score;
                }
            }
        }
        return ['atom' => $bestAtom, 'score' => $best, 'token' => $bestToken, 'runner_up' => $runner];
    }

    public function associationScore(string $token, string $atom): float
    {
        $t = $this->tokens[$token] ?? null;
        $a = $this->atoms[$atom] ?? null;
        $p = $t['pairs'][$atom] ?? null;
        if ($t === null || $a === null || $p === null) return 0.0;

        $tc = $this->effective($t['count'], $t['last']);
        $ac = $this->effective($a['count'], $a['last']);
        $pc = $this->effective($p['count'], $p['last']);
        $den = $tc + $ac - $pc;
        if ($den <= 0.0) return 0.0;
        return max(0.0, min(1.0, $pc / $den));
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $pairs = 0;
        foreach ($this->tokens as $entry) $pairs += count($entry['pairs']);
        return ['tokens' => count($this->tokens), 'atoms' => count($this->atoms), 'pairs' => $pairs, 'step' => $this->step];
    }

    public function consolidate(float $minimum = 0.015): void
    {
        foreach ($this->tokens as $token => &$entry) {
            foreach ($entry['pairs'] as $atom => $pair) {
                if ($this->effective($pair['count'], $pair['last']) < $minimum) {
                    unset($entry['pairs'][$atom]);
                }
            }
            if ($this->effective($entry['count'], $entry['last']) < $minimum || $entry['pairs'] === []) {
                unset($this->tokens[$token]);
            }
        }
        unset($entry);
        foreach ($this->atoms as $atom => $entry) {
            if ($this->effective($entry['count'], $entry['last']) < $minimum) unset($this->atoms[$atom]);
        }
        $this->enforceTokenBudget();
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return ['step'=>$this->step,'decay'=>$this->decay,'maxTokens'=>$this->maxTokens,'maxPairsPerToken'=>$this->maxPairsPerToken,'tokens'=>$this->tokens,'atoms'=>$this->atoms];
    }

    public static function import(array $data): self
    {
        $self = new self((int)($data['step'] ?? 0),(float)($data['decay'] ?? .9995),(int)($data['maxTokens'] ?? 1024),(int)($data['maxPairsPerToken'] ?? 48));
        $self->tokens = is_array($data['tokens'] ?? null) ? $data['tokens'] : [];
        $self->atoms = is_array($data['atoms'] ?? null) ? $data['atoms'] : [];
        return $self;
    }

    private function effective(float $value, int $last): float
    {
        $age = max(0, $this->step - $last);
        return $age === 0 ? $value : $value * ($this->decay ** $age);
    }

    private function inCategory(string $atom, string $category): bool
    {
        return str_starts_with($atom, $category . ':');
    }

    private function categoryOf(string $atom): string
    {
        $at = strpos($atom, ':');
        return $at === false ? $atom : substr($atom, 0, $at);
    }

    private function enforceTokenBudget(): void
    {
        if (count($this->tokens) <= $this->maxTokens) return;
        uasort($this->tokens, fn(array $a, array $b): int => $this->effective($a['count'], $a['last']) <=> $this->effective($b['count'], $b['last']));
        while (count($this->tokens) > $this->maxTokens) array_shift($this->tokens);
    }
}

final class SchemaMemory
{
    /** @var array<string,array<string,int>> */
    private array $roles = [];
    /** @var array<string,int> */
    private array $seen = [];

    public function learn(SemanticFrame $frame): void
    {
        $this->seen[$frame->verb] = ($this->seen[$frame->verb] ?? 0) + 1;
        foreach (array_keys($frame->args) as $role) {
            $this->roles[$frame->verb][$role] = ($this->roles[$frame->verb][$role] ?? 0) + 1;
        }
    }

    /** @return list<string> */
    public function requiredRoles(string $verb, float $threshold = .65): array
    {
        $seen = $this->seen[$verb] ?? 0;
        if ($seen === 0) return [];
        $roles = [];
        foreach ($this->roles[$verb] ?? [] as $role => $count) {
            if ($count / $seen >= $threshold) $roles[] = $role;
        }
        sort($roles);
        return $roles;
    }

    /** @return array<string,mixed> */
    public function export(): array { return ['roles'=>$this->roles,'seen'=>$this->seen]; }
    public static function import(array $d): self { $x=new self();$x->roles=$d['roles']??[];$x->seen=$d['seen']??[];return $x; }
}

final class ProgramMemory
{
    /** @var array<string,array<string,array{count:float,key:string,value:string}>> */
    private array $programs = [];
    /** @var array<string,int> */
    private array $verbSeen = [];

    public function __construct(private readonly int $maxPrograms = 256) {}

    public function learn(SemanticFrame $frame, Effect $effect, float $strength = 1.0): void
    {
        if ($frame->negated) return;
        $this->verbSeen[$frame->verb] = ($this->verbSeen[$frame->verb] ?? 0) + 1;
        [$key, $value] = $this->abstractEffect($frame, $effect);
        $signature = $key . '=' . $value;
        $existing = $this->programs[$frame->verb][$signature] ?? ['count'=>0.0,'key'=>$key,'value'=>$value];
        $existing['count'] += $strength;
        $this->programs[$frame->verb][$signature] = $existing;
        $this->enforceBudget();
    }

    /** @return array{effect:?Effect,confidence:float,pattern:?string} */
    public function predict(SemanticFrame $frame): array
    {
        if ($frame->negated) return ['effect'=>null,'confidence'=>1.0,'pattern'=>'negated:no-effect'];
        $options = $this->programs[$frame->verb] ?? [];
        if ($options === []) return ['effect'=>null,'confidence'=>0.0,'pattern'=>null];
        uasort($options, fn(array $a,array $b):int=>$b['count']<=>$a['count']);
        $top = reset($options);
        $seen = max(1, $this->verbSeen[$frame->verb] ?? 1);
        $confidence = min(1.0, $top['count'] / $seen);
        $pattern = $top['key'].'='.$top['value'];
        if ($confidence < 0.60) return ['effect'=>null,'confidence'=>$confidence,'pattern'=>$pattern];
        $key = $this->instantiate($top['key'], $frame->args);
        $value = $this->instantiate($top['value'], $frame->args);
        if ($key === null || $value === null) return ['effect'=>null,'confidence'=>$confidence,'pattern'=>$pattern];
        return ['effect'=>new Effect($key,$value),'confidence'=>$confidence,'pattern'=>$pattern];
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $n=0;foreach($this->programs as $ps)$n+=count($ps);
        return ['programs'=>$n,'verbs'=>count($this->programs)];
    }

    /** @return array<string,mixed> */
    public function export(): array { return ['maxPrograms'=>$this->maxPrograms,'programs'=>$this->programs,'verbSeen'=>$this->verbSeen]; }
    public static function import(array $d): self { $x=new self((int)($d['maxPrograms']??256));$x->programs=$d['programs']??[];$x->verbSeen=$d['verbSeen']??[];return $x; }

    /** @return array{string,string} */
    private function abstractEffect(SemanticFrame $frame, Effect $effect): array
    {
        $keyParts = explode('.', $effect->key);
        foreach ($keyParts as &$part) {
            foreach ($frame->args as $role => $value) {
                if ($part === $value) { $part = '{'.$role.'}'; break; }
            }
        }
        unset($part);
        $value = $effect->value;
        foreach ($frame->args as $role => $argValue) {
            if ($value === $argValue) { $value = '{'.$role.'}'; break; }
        }
        return [implode('.', $keyParts), $value];
    }

    private function instantiate(string $pattern, array $args): ?string
    {
        if (preg_match_all('/\{([^}]+)\}/', $pattern, $matches)) {
            foreach ($matches[1] as $role) {
                if (!array_key_exists($role, $args)) return null;
                $pattern = str_replace('{'.$role.'}', $args[$role], $pattern);
            }
        }
        return $pattern;
    }

    private function enforceBudget(): void
    {
        $all=[];
        foreach($this->programs as $verb=>$items){foreach($items as $sig=>$entry)$all[]=[$entry['count'],$verb,$sig];}
        if(count($all)<=$this->maxPrograms)return;
        usort($all,fn($a,$b)=>$a[0]<=>$b[0]);
        while(count($all)>$this->maxPrograms){[, $verb,$sig]=array_shift($all);unset($this->programs[$verb][$sig]);if(($this->programs[$verb]??[])===[])unset($this->programs[$verb]);}
    }
}

final class EpisodicMemory
{
    /** @var list<array<string,mixed>> */
    private array $episodes = [];

    public function __construct(private readonly int $maxEpisodes = 256) {}

    public function remember(string $utterance, SemanticFrame $frame, ?Effect $effect, float $surprise, bool $correction, int $step): void
    {
        $this->episodes[] = [
            'utterance'=>$utterance,
            'frame'=>$frame->canonical(),
            'effect'=>$effect?->canonical(),
            'surprise'=>$surprise,
            'correction'=>$correction,
            'step'=>$step,
        ];
        if(count($this->episodes)>$this->maxEpisodes)$this->compact();
    }

    public function compact(): void
    {
        usort($this->episodes,function(array $a,array $b):int{
            $pa=($a['correction']?4:0)+3*$a['surprise']+log(2+$a['step']);
            $pb=($b['correction']?4:0)+3*$b['surprise']+log(2+$b['step']);
            return $pb<=>$pa;
        });
        $this->episodes=array_slice($this->episodes,0,$this->maxEpisodes);
        usort($this->episodes,fn(array $a,array $b):int=>$a['step']<=>$b['step']);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $n=5):array{return array_slice($this->episodes,-$n);}
    public function count():int{return count($this->episodes);}
    public function export():array{return ['maxEpisodes'=>$this->maxEpisodes,'episodes'=>$this->episodes];}
    public static function import(array $d):self{$x=new self((int)($d['maxEpisodes']??256));$x->episodes=$d['episodes']??[];return $x;}
}

final class FactMemory
{
    /** @var array<string,array{value:string,step:int,revision:int}> */
    private array $facts=[];
    private int $revision=0;
    public function __construct(private readonly int $maxFacts=256){}
    public function set(string $key,string $value,int $step):void{
        $this->facts[$key]=['value'=>$value,'step'=>$step,'revision'=>++$this->revision];
        if(count($this->facts)>$this->maxFacts){uasort($this->facts,fn($a,$b)=>$a['revision']<=>$b['revision']);array_shift($this->facts);}
    }
    public function get(string $key):?string{return $this->facts[$key]['value']??null;}
    public function count():int{return count($this->facts);}
    public function export():array{return ['maxFacts'=>$this->maxFacts,'facts'=>$this->facts,'revision'=>$this->revision];}
    public static function import(array $d):self{$x=new self((int)($d['maxFacts']??256));$x->facts=$d['facts']??[];$x->revision=(int)($d['revision']??0);return $x;}
}

