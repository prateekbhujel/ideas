<?php

declare(strict_types=1);

namespace Hari;

final class Lexicon
{
    /** @var array<string,array{concept:string,seen:int,confidence:float}> */
    private array $map = [];

    public function teach(string $language, string $phrase, string $concept, float $confidence = 1.0): void
    {
        if ($confidence <= 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('confidence must be > 0 and <= 1');
        }
        $key = $this->key($language, $phrase);
        $old = $this->map[$key] ?? null;
        $seen = ($old['seen'] ?? 0) + 1;
        $avg = $old === null
            ? $confidence
            : (($old['confidence'] * $old['seen']) + $confidence) / $seen;
        $this->map[$key] = ['concept' => $concept, 'seen' => $seen, 'confidence' => min(1.0, $avg)];
    }

    /** @return array{concept:string,confidence:float}|null */
    public function resolve(string $language, string $phrase): ?array
    {
        $key = $this->key($language, $phrase);
        if (isset($this->map[$key])) {
            return ['concept' => $this->map[$key]['concept'], 'confidence' => $this->map[$key]['confidence']];
        }

        $q = self::normalize($phrase);
        $best = null;
        $bestScore = 0.0;
        foreach ($this->map as $k => $row) {
            [$lang, $known] = explode("\0", $k, 2);
            if ($lang !== strtolower(trim($language))) continue;
            similar_text($q, $known, $pct);
            $score = ($pct / 100.0) * $row['confidence'];
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['concept' => $row['concept'], 'confidence' => $score];
            }
        }
        return $bestScore >= 0.78 ? $best : null;
    }

    /** @return array<string,array{concept:string,seen:int,confidence:float}> */
    public function export(): array { return $this->map; }

    /** @param array<string,array{concept:string,seen:int,confidence?:float}> $data */
    public static function import(array $data): self
    {
        $x = new self();
        foreach ($data as $key => $row) {
            $x->map[$key] = [
                'concept' => (string) $row['concept'],
                'seen' => max(1, (int) $row['seen']),
                'confidence' => (float) ($row['confidence'] ?? 1.0),
            ];
        }
        return $x;
    }

    private function key(string $language, string $phrase): string
    { return strtolower(trim($language)) . "\0" . self::normalize($phrase); }

    private static function normalize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}

final class MemoryItem
{
    /** @param list<string> $cues */
    public function __construct(
        public string $id,
        public string $text,
        public array $cues,
        public float $strength,
        public float $importance,
        public float $confidence,
        public int $created,
        public int $last,
        public int $hits = 0,
    ) {}

    public function effective(int $now): float
    {
        $age = max(0, $now - $this->last);
        $retention = 2 ** (-$age / 100.0);
        return max($this->importance * 0.30, $this->strength * $retention);
    }

    public function reinforce(int $now): void
    {
        $this->strength = min(1.0, $this->strength + 0.12);
        $this->last = $now;
        ++$this->hits;
    }
}

final class Memory
{
    /** @var array<string,MemoryItem> */
    private array $items = [];
    private int $clock = 0;
    private int $next = 1;

    /** @param list<string> $cues */
    public function remember(string $text, array $cues, float $importance = 0.5, float $confidence = 1.0): MemoryItem
    {
        if ($importance < 0.0 || $importance > 1.0 || $confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('memory weights must be between 0 and 1');
        }
        $normalized = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $cues))));
        $m = new MemoryItem('m'.$this->next++, trim($text), $normalized, 1.0, $importance, $confidence, $this->clock, $this->clock);
        return $this->items[$m->id] = $m;
    }

    public function age(int $ticks): void
    {
        if ($ticks < 0) throw new \InvalidArgumentException('time cannot go backwards');
        $this->clock += $ticks;
    }

    /** @param list<string> $cues */
    public function recall(array $cues, bool $reinforce = true, float $minimum = 0.12): ?MemoryItem
    {
        $query = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $cues))));
        if ($query === []) return null;

        $best = null;
        $bestScore = 0.0;
        foreach ($this->items as $m) {
            $match = $this->cueMatch($query, $m->cues);
            if ($match <= 0.0) continue;
            $score = $match * $m->effective($this->clock) * $m->confidence;
            if ($score > $bestScore) { $best = $m; $bestScore = $score; }
        }

        if ($best === null || $bestScore < $minimum) return null;
        if ($reinforce) $best->reinforce($this->clock);
        return $best;
    }

    public function forget(float $threshold = 0.08): int
    {
        $n = 0;
        foreach ($this->items as $id => $m) {
            if ($m->effective($this->clock) < $threshold && $m->importance < 0.85) {
                unset($this->items[$id]); ++$n;
            }
        }
        return $n;
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return ['clock'=>$this->clock,'next'=>$this->next,'items'=>array_map(fn($m)=>get_object_vars($m), array_values($this->items))];
    }

    /** @param array<string,mixed> $data */
    public static function import(array $data): self
    {
        $x = new self();
        $x->clock = max(0, (int)($data['clock'] ?? 0));
        $x->next = max(1, (int)($data['next'] ?? 1));
        foreach ($data['items'] ?? [] as $r) {
            $m = new MemoryItem(
                (string)$r['id'], (string)$r['text'], array_values(array_map('strval', $r['cues'] ?? [])),
                (float)$r['strength'], (float)$r['importance'], (float)($r['confidence'] ?? 1.0),
                (int)($r['created'] ?? 0), (int)$r['last'], (int)($r['hits'] ?? 0),
            );
            $x->items[$m->id] = $m;
        }
        return $x;
    }

    /** @param list<string> $a @param list<string> $b */
    private function cueMatch(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $q) {
            $local = 0.0;
            foreach ($b as $known) {
                if ($q === $known) { $local = 1.0; break; }
                similar_text($q, $known, $pct);
                $similarity = $pct / 100.0;
                if ($similarity >= 0.72) $local = max($local, $similarity);
            }
            $sum += $local;
        }
        return $sum / max(1, count($a));
    }

    private static function normalize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}

final class Habits
{
    /** @var array<string,array<string,int>> */
    private array $counts = [];

    public function observe(string $context, string $choice): void
    {
        if ($context === '' || $choice === '') throw new \InvalidArgumentException('context and choice required');
        $this->counts[$context][$choice] = ($this->counts[$context][$choice] ?? 0) + 1;
    }

    /** @return array{choice:string,confidence:float,observations:int}|null */
    public function predict(string $context, int $minimum = 3): ?array
    {
        $x = $this->counts[$context] ?? [];
        $n = array_sum($x);
        if ($n < $minimum || $x === []) return null;
        arsort($x);
        $choice = (string)array_key_first($x);
        return ['choice'=>$choice,'confidence'=>(($x[$choice]+1)/($n+count($x))),'observations'=>$n];
    }

    /** @return array<string,array<string,int>> */
    public function export(): array { return $this->counts; }
    /** @param array<string,array<string,int>> $x */
    public static function import(array $x): self { $h=new self();$h->counts=$x;return $h; }
}

final class Affect
{
    public function __construct(
        private float $warmth=.5,
        private float $frustration=0.0,
        private float $energy=.7,
        private float $playfulness=.4,
    ) {}

    public function corrected(): void { $this->frustration=min(1,$this->frustration+.08);$this->warmth=max(0,$this->warmth-.01); }
    public function success(): void { $this->frustration=max(0,$this->frustration-.05);$this->warmth=min(1,$this->warmth+.02); }
    public function rest(float $amount=.1): void { $this->energy=min(1,$this->energy+$amount);$this->frustration=max(0,$this->frustration-$amount/2); }
    /** @return array{warmth:float,frustration:float,energy:float,playfulness:float} */
    public function export(): array { return ['warmth'=>$this->warmth,'frustration'=>$this->frustration,'energy'=>$this->energy,'playfulness'=>$this->playfulness]; }
    /** @param array<string,float|int> $x */
    public static function import(array $x): self { return new self((float)($x['warmth']??.5),(float)($x['frustration']??0),(float)($x['energy']??.7),(float)($x['playfulness']??.4)); }
}

enum Risk:int { case Read=0; case Reversible=1; case External=2; }

final readonly class Action
{
    /** @param array<string,scalar|null> $args */
    public function __construct(public string $op, public array $args=[], public float $confidence=1.0, public Risk $risk=Risk::Read) {}
}

final class Executor
{
    /** @var list<Action> */ private array $done=[];

    /** @param list<Action> $plan @param list<int> $confirmed */
    public function run(array $plan, array $confirmed=[]): array
    {
        foreach ($plan as $i=>$a) {
            if ($a->confidence < .70) return ['ok'=>false,'at'=>$i,'why'=>'uncertain'];
            if ($a->risk === Risk::External && !in_array($i,$confirmed,true)) return ['ok'=>false,'at'=>$i,'why'=>'confirm'];
            $this->done[]=$a;
        }
        return ['ok'=>true,'at'=>null,'why'=>null];
    }

    /** @return list<Action> */ public function done():array{return $this->done;}
}

final readonly class Tool
{
    /** @param list<string> $capabilities */
    public function __construct(public string $name,public array $capabilities,public float $cost,public float $reliability,public bool $local=false){}
}

final class ToolRouter
{
    /** @var array<string,Tool> */ private array $tools=[];
    /** @var array<string,array{ok:int,bad:int}> */ private array $history=[];

    public function add(Tool $t):void{$this->tools[$t->name]=$t;$this->history[$t->name]??=['ok'=>0,'bad'=>0];}
    public function record(string $name,bool $ok):void{if(!isset($this->history[$name]))throw new \OutOfBoundsException($name);++$this->history[$name][$ok?'ok':'bad'];}

    public function choose(string $capability):?Tool
    {
        $best=null;$bestScore=-INF;
        foreach($this->tools as $t){
            if(!in_array($capability,$t->capabilities,true))continue;
            $h=$this->history[$t->name];$n=$h['ok']+$h['bad'];
            $posterior=($h['ok']+1)/($n+2);
            $r=$n===0?$t->reliability:(($posterior*$n)+$t->reliability)/($n+1);
            $score=$r*10+($t->local?1:0)-$t->cost;
            if($score>$bestScore){$best=$t;$bestScore=$score;}
        }
        return $best;
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return [
            'tools'=>array_map(fn(Tool $t)=>['name'=>$t->name,'capabilities'=>$t->capabilities,'cost'=>$t->cost,'reliability'=>$t->reliability,'local'=>$t->local],array_values($this->tools)),
            'history'=>$this->history,
        ];
    }

    /** @param array<string,mixed> $x */
    public static function import(array $x): self
    {
        $r=new self();
        foreach($x['tools']??[] as $t)$r->add(new Tool((string)$t['name'],array_values(array_map('strval',$t['capabilities']??[])),(float)$t['cost'],(float)$t['reliability'],(bool)$t['local']));
        foreach($x['history']??[] as $name=>$h)if(isset($r->history[$name]))$r->history[$name]=['ok'=>(int)($h['ok']??0),'bad'=>(int)($h['bad']??0)];
        return $r;
    }
}

final class ProcedureLibrary
{
    /** @var array<string,array<string,array{actions:list<array<string,mixed>>,seen:int}>> */
    private array $demos=[];

    /** @param list<Action> $actions */
    public function demonstrate(string $intent,array $actions):void
    {
        if($intent===''||$actions===[])throw new \InvalidArgumentException('intent and actions required');
        $encoded=array_map(fn(Action $a)=>['op'=>$a->op,'args'=>$a->args,'confidence'=>$a->confidence,'risk'=>$a->risk->value],$actions);
        $key=hash('sha256',json_encode($encoded,JSON_THROW_ON_ERROR));
        $this->demos[$intent][$key]??=['actions'=>$encoded,'seen'=>0];
        ++$this->demos[$intent][$key]['seen'];
    }

    /** @return list<Action>|null */
    public function recall(string $intent,int $minimumSeen=3):?array
    {
        $choices=$this->demos[$intent]??[];
        if($choices===[])return null;
        uasort($choices,fn($a,$b)=>$b['seen']<=>$a['seen']);
        $best=reset($choices);
        if($best===false||$best['seen']<$minimumSeen)return null;
        return array_map(fn($r)=>new Action((string)$r['op'],(array)$r['args'],(float)$r['confidence'],Risk::from((int)$r['risk'])), $best['actions']);
    }

    /** @return array<string,mixed> */ public function export():array{return $this->demos;}
    /** @param array<string,mixed> $x */ public static function import(array $x):self{$p=new self();$p->demos=$x;return $p;}
}

final class Hari
{
    public function __construct(
        public int $age=0,
        public Lexicon $lexicon=new Lexicon(),
        public Memory $memory=new Memory(),
        public Habits $habits=new Habits(),
        public Affect $affect=new Affect(),
        public ToolRouter $tools=new ToolRouter(),
        public ProcedureLibrary $procedures=new ProcedureLibrary(),
    ){}

    public function teach(string $language,string $phrase,string $concept,float $confidence=1.0):void
    {
        $this->lexicon->teach($language,$phrase,$concept,$confidence);
        $this->memory->remember("$language:$phrase=$concept",[$language,$phrase,$concept,'language'],.75,$confidence);
    }

    /** @param list<Action> $actions */
    public function demonstrate(string $language,string $phrase,array $actions):void
    {
        $meaning=$this->lexicon->resolve($language,$phrase);
        if($meaning===null)throw new \RuntimeException('unknown phrase');
        $this->procedures->demonstrate($meaning['concept'],$actions);
    }

    /** @return list<Action>|null */
    public function plan(string $language,string $phrase):?array
    {
        $meaning=$this->lexicon->resolve($language,$phrase);
        return $meaning===null?null:$this->procedures->recall($meaning['concept']);
    }

    public function tick(int $ticks=1):void
    {
        if($ticks<0)throw new \InvalidArgumentException('life cannot go backwards');
        $this->age+=$ticks;$this->memory->age($ticks);
    }

    public function save(string $path):void
    {
        $payload=json_encode([
            'v'=>2,'age'=>$this->age,'lexicon'=>$this->lexicon->export(),'memory'=>$this->memory->export(),
            'habits'=>$this->habits->export(),'affect'=>$this->affect->export(),'tools'=>$this->tools->export(),
            'procedures'=>$this->procedures->export(),
        ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('state dir');
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,$payload,LOCK_EX)===false)throw new \RuntimeException('state write');
        @chmod($tmp,0600);if(!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('state commit');}
    }

    public static function load(string $path):self
    {
        if(!is_file($path))return new self();
        $x=json_decode((string)file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        $v=(int)($x['v']??1);
        if($v===1)return new self(0,Lexicon::import($x['lexicon']??[]),Memory::import($x['memory']??[]));
        if($v!==2)throw new \RuntimeException('unsupported state');
        return new self(
            (int)($x['age']??0),Lexicon::import($x['lexicon']??[]),Memory::import($x['memory']??[]),
            Habits::import($x['habits']??[]),Affect::import($x['affect']??[]),ToolRouter::import($x['tools']??[]),ProcedureLibrary::import($x['procedures']??[]),
        );
    }
}
