<?php

declare(strict_types=1);

namespace Hari;

final class Lexicon
{
    /** @var array<string,array{concept:string,seen:int}> */
    private array $map = [];

    public function teach(string $language, string $phrase, string $concept): void
    {
        $key = $this->key($language, $phrase);
        $seen = ($this->map[$key]['seen'] ?? 0) + 1;
        $this->map[$key] = ['concept' => $concept, 'seen' => $seen];
    }

    public function resolve(string $language, string $phrase): ?string
    {
        $key = $this->key($language, $phrase);
        if (isset($this->map[$key])) return $this->map[$key]['concept'];

        $q = self::normalize($phrase);
        $best = null; $score = 0.0;
        foreach ($this->map as $k => $row) {
            [$lang, $known] = explode("\0", $k, 2);
            if ($lang !== strtolower($language)) continue;
            similar_text($q, $known, $pct);
            if ($pct > $score) { $score = $pct; $best = $row['concept']; }
        }
        return $score >= 82.0 ? $best : null;
    }

    /** @return array<string,array{concept:string,seen:int}> */
    public function export(): array { return $this->map; }
    /** @param array<string,array{concept:string,seen:int}> $data */
    public static function import(array $data): self { $x = new self(); $x->map = $data; return $x; }

    private function key(string $language, string $phrase): string
    { return strtolower(trim($language)) . "\0" . self::normalize($phrase); }
    private static function normalize(string $s): string
    { return preg_replace('/\s+/u', ' ', \function_exists('mb_strtolower') ? \mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s))) ?? trim($s); }
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
        public int $last,
        public int $hits = 0,
    ) {}

    public function effective(int $now): float
    {
        $age = max(0, $now - $this->last);
        return max($this->importance * .25, $this->strength * (2 ** (-$age / 100)));
    }
}

final class Memory
{
    /** @var array<string,MemoryItem> */
    private array $items = [];
    private int $clock = 0;
    private int $next = 1;

    /** @param list<string> $cues */
    public function remember(string $text, array $cues, float $importance = .5): MemoryItem
    {
        $m = new MemoryItem('m'.$this->next++, $text, array_values(array_unique($cues)), 1.0, $importance, $this->clock);
        return $this->items[$m->id] = $m;
    }

    public function age(int $ticks): void { $this->clock += max(0, $ticks); }

    /** @param list<string> $cues */
    public function recall(array $cues, bool $reinforce = true): ?MemoryItem
    {
        $best = null; $bestScore = 0.0;
        foreach ($this->items as $m) {
            $overlap = count(array_intersect($cues, $m->cues));
            if (!$overlap) continue;
            $score = ($overlap / max(count($cues), 1)) * $m->effective($this->clock);
            if ($score > $bestScore) { $best = $m; $bestScore = $score; }
        }
        if ($best && $reinforce) {
            $best->strength = min(1.0, $best->strength + .12);
            $best->last = $this->clock; ++$best->hits;
        }
        return $best;
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        return ['clock'=>$this->clock,'next'=>$this->next,'items'=>array_map(fn($m)=>get_object_vars($m), array_values($this->items))];
    }

    /** @param array<string,mixed> $data */
    public static function import(array $data): self
    {
        $x = new self(); $x->clock=(int)($data['clock']??0); $x->next=(int)($data['next']??1);
        foreach ($data['items']??[] as $r) {
            $m = new MemoryItem((string)$r['id'],(string)$r['text'],array_values($r['cues']), (float)$r['strength'],(float)$r['importance'],(int)$r['last'],(int)$r['hits']);
            $x->items[$m->id]=$m;
        }
        return $x;
    }
}

final class Habits
{
    /** @var array<string,array<string,int>> */
    private array $counts=[];
    public function observe(string $context,string $choice): void { $this->counts[$context][$choice]=($this->counts[$context][$choice]??0)+1; }
    /** @return array{choice:string,confidence:float}|null */
    public function predict(string $context,int $minimum=3): ?array
    {
        $x=$this->counts[$context]??[]; $n=array_sum($x); if($n<$minimum)return null; arsort($x); $c=(string)array_key_first($x);
        return ['choice'=>$c,'confidence'=>(($x[$c]+1)/($n+count($x)))];
    }
    /** @return array<string,array<string,int>> */ public function export():array{return $this->counts;}
    /** @param array<string,array<string,int>> $x */ public static function import(array $x):self{$h=new self();$h->counts=$x;return $h;}
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
    public function run(array $plan,array $confirmed=[]): array
    {
        foreach($plan as $i=>$a){
            if($a->confidence<.70)return ['ok'=>false,'at'=>$i,'why'=>'uncertain'];
            if($a->risk===Risk::External && !in_array($i,$confirmed,true))return ['ok'=>false,'at'=>$i,'why'=>'confirm'];
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
    public function record(string $name,bool $ok):void{++$this->history[$name][$ok?'ok':'bad'];}
    public function choose(string $capability):?Tool
    {
        $best=null;$bestScore=-INF;
        foreach($this->tools as $t){if(!in_array($capability,$t->capabilities,true))continue;$h=$this->history[$t->name];$n=$h['ok']+$h['bad'];$r=$n?(($h['ok']+1)/($n+2)):$t->reliability;$s=$r*10+($t->local?1:0)-$t->cost;if($s>$bestScore){$best=$t;$bestScore=$s;}}
        return $best;
    }
}

final class IntentCompiler
{
    /** @param array<string,scalar|null> $slots @return list<Action>|null */
    public function compile(string $concept,array $slots):?array
    {
        return match($concept){
            'video_call','voice_call' => isset($slots['person']) ? [
                new Action('FIND_CONTACT',['person'=>$slots['person']],.95,Risk::Read),
                new Action($concept==='video_call'?'VIDEO_CALL':'CALL',['person'=>$slots['person']],.95,Risk::External),
            ] : null,
            'send_message' => isset($slots['person'],$slots['message']) ? [
                new Action('FIND_CONTACT',['person'=>$slots['person']],.95,Risk::Read),
                new Action('SEND_MESSAGE',['person'=>$slots['person'],'message'=>$slots['message']],.95,Risk::External),
            ] : null,
            default => null,
        };
    }
}

final class Hari
{
    public function __construct(public Lexicon $lexicon=new Lexicon(),public Memory $memory=new Memory(),public Habits $habits=new Habits(),private IntentCompiler $compiler=new IntentCompiler()){}
    public function teach(string $language,string $phrase,string $concept):void
    { $this->lexicon->teach($language,$phrase,$concept); $this->memory->remember("$language:$phrase=$concept",[$language,$phrase,$concept],.75); }
    /** @param array<string,scalar|null> $slots @return list<Action>|null */
    public function plan(string $language,string $phrase,array $slots):?array
    { $concept=$this->lexicon->resolve($language,$phrase); return $concept===null?null:$this->compiler->compile($concept,$slots); }
    public function save(string $path):void
    { file_put_contents($path,json_encode(['v'=>1,'lexicon'=>$this->lexicon->export(),'memory'=>$this->memory->export(),'habits'=>$this->habits->export()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX); }
    public static function load(string $path):self
    { if(!is_file($path))return new self();$x=json_decode((string)file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);return new self(Lexicon::import($x['lexicon']??[]),Memory::import($x['memory']??[]),Habits::import($x['habits']??[])); }
}
