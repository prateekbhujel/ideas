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
        return $this->resolveDetailed($language,$phrase)['concept']??null;
    }

    /** @return array{concept:string,confidence:float}|null */
    public function resolveDetailed(string $language,string $phrase):?array
    {
        $key=$this->key($language,$phrase);
        if(isset($this->map[$key]))return ['concept'=>$this->map[$key]['concept'],'confidence'=>1.0];

        $q=self::normalize($phrase);$best=null;$score=0.0;
        foreach($this->map as $k=>$row){
            [$lang,$known]=explode("\0",$k,2);
            if($lang!==strtolower(trim($language)))continue;
            similar_text($q,$known,$pct);
            if($pct>$score){$score=$pct;$best=$row['concept'];}
        }
        $confidence=$score/100.0;
        return $best!==null&&$confidence>=.82?['concept'=>$best,'confidence'=>$confidence]:null;
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
        }
        foreach($plan as $a)$this->done[]=$a;
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
    public function record(string $name,bool $ok):void
    {
        if(!isset($this->history[$name]))throw new \OutOfBoundsException("unknown tool: {$name}");
        ++$this->history[$name][$ok?'ok':'bad'];
    }
    public function choose(string $capability):?Tool
    {
        $best=null;$bestScore=-INF;
        foreach($this->tools as $t){if(!in_array($capability,$t->capabilities,true))continue;$h=$this->history[$t->name];$n=$h['ok']+$h['bad'];$r=$n?(($h['ok']+1)/($n+2)):$t->reliability;$s=$r*10+($t->local?1:0)-$t->cost;if($s>$bestScore){$best=$t;$bestScore=$s;}}
        return $best;
    }
    /** @return array<string,mixed> */
    public function export():array
    {
        return [
            'tools'=>array_map(fn(Tool $t)=>['name'=>$t->name,'capabilities'=>$t->capabilities,'cost'=>$t->cost,'reliability'=>$t->reliability,'local'=>$t->local],array_values($this->tools)),
            'history'=>$this->history,
        ];
    }
    /** @param array<string,mixed> $data */
    public static function import(array $data):self
    {
        $r=new self();
        foreach($data['tools']??[] as $t)$r->add(new Tool((string)$t['name'],array_values(array_map('strval',$t['capabilities']??[])),(float)$t['cost'],(float)$t['reliability'],(bool)$t['local']));
        foreach($data['history']??[] as $name=>$h)if(isset($r->history[$name]))$r->history[$name]=['ok'=>(int)($h['ok']??0),'bad'=>(int)($h['bad']??0)];
        return $r;
    }
}

final class Affect
{
    public function __construct(
        private float $warmth=.5,
        private float $frustration=0.0,
        private float $energy=.7,
        private float $playfulness=.4,
    ){}
    public function corrected():void{$this->frustration=min(1,$this->frustration+.08);$this->warmth=max(0,$this->warmth-.01);}
    public function succeeded():void{$this->frustration=max(0,$this->frustration-.05);$this->warmth=min(1,$this->warmth+.02);}
    public function rest(float $amount=.1):void{$this->energy=min(1,$this->energy+$amount);$this->frustration=max(0,$this->frustration-$amount/2);}
    /** @return array{warmth:float,frustration:float,energy:float,playfulness:float} */
    public function export():array{return ['warmth'=>$this->warmth,'frustration'=>$this->frustration,'energy'=>$this->energy,'playfulness'=>$this->playfulness];}
    /** @param array<string,mixed> $data */
    public static function import(array $data):self{return new self((float)($data['warmth']??.5),(float)($data['frustration']??0),(float)($data['energy']??.7),(float)($data['playfulness']??.4));}
}

final class SkillBook
{
    /** @var array<string,list<Action>> */ private array $skills=[];
    /** @param list<Action> $template */ public function teach(string $concept,array $template):void{$this->skills[$concept]=$template;}
    /** @param array<string,scalar|null> $slots @return list<Action>|null */
    public function plan(string $concept,array $slots):?array
    {
        $template=$this->skills[$concept]??null;if($template===null)return null;$out=[];
        foreach($template as $a){$args=[];foreach($a->args as $k=>$v){if(is_string($v)&&preg_match('/^\{(.+)\}$/',$v,$m)){$v=$slots[$m[1]]??null;if($v===null)return null;}$args[$k]=$v;}$out[]=new Action($a->op,$args,$a->confidence,$a->risk);}return $out;
    }
    /** @return array<string,list<array<string,mixed>>> */ public function export():array
    { $out=[];foreach($this->skills as $c=>$as){$out[$c]=array_map(fn($a)=>['op'=>$a->op,'args'=>$a->args,'confidence'=>$a->confidence,'risk'=>$a->risk->value],$as);}return $out; }
    /** @param array<string,list<array<string,mixed>>> $data */ public static function import(array $data):self
    { $b=new self();foreach($data as $c=>$as){$b->skills[$c]=array_map(fn($a)=>new Action((string)$a['op'],$a['args']??[],(float)($a['confidence']??1),Risk::from((int)($a['risk']??0))),$as);}return $b; }
}

final class SkillInducer
{
    /** @var array<string,list<array{slots:array<string,scalar|null>,actions:list<Action>}>> */ private array $demos=[];
    /** @param array<string,scalar|null> $slots @param list<Action> $actions */
    public function demonstrate(string $concept,array $slots,array $actions):void{$this->demos[$concept][]=['slots'=>$slots,'actions'=>$actions];}
    /** @return list<Action>|null */
    public function induce(string $concept,int $minimum=2):?array
    {
        $ds=$this->demos[$concept]??[];if(count($ds)<$minimum)return null;$count=count($ds[0]['actions']);
        foreach($ds as $d)if(count($d['actions'])!==$count)return null;$template=[];
        for($i=0;$i<$count;$i++){
            $first=$ds[0]['actions'][$i];foreach($ds as $d){$a=$d['actions'][$i];if($a->op!==$first->op||$a->risk!==$first->risk)return null;}
            $args=[];foreach($first->args as $key=>$value){$replacement=null;
                foreach($ds[0]['slots'] as $slot=>$slotValue){$matches=true;foreach($ds as $d){if(!array_key_exists($key,$d['actions'][$i]->args)||($d['actions'][$i]->args[$key]??null)!==($d['slots'][$slot]??null)){$matches=false;break;}}if($matches){$replacement='{'.$slot.'}';break;}}
                if($replacement===null){foreach($ds as $d){if(($d['actions'][$i]->args[$key]??null)!==$value)return null;}$replacement=$value;}$args[$key]=$replacement;
            }
            $template[]=new Action($first->op,$args,$first->confidence,$first->risk);
        }
        return $template;
    }
    /** @return array<string,list<array{slots:array<string,scalar|null>,actions:list<array<string,mixed>>}>> */
    public function export():array
    {
        $out=[];
        foreach($this->demos as $concept=>$demos){
            foreach($demos as $demo){
                $out[$concept][]=[
                    'slots'=>$demo['slots'],
                    'actions'=>array_map(fn(Action $a)=>['op'=>$a->op,'args'=>$a->args,'confidence'=>$a->confidence,'risk'=>$a->risk->value],$demo['actions']),
                ];
            }
        }
        return $out;
    }
    /** @param array<string,list<array{slots?:array<string,scalar|null>,actions?:list<array<string,mixed>>}>> $data */
    public static function import(array $data):self
    {
        $i=new self();
        foreach($data as $concept=>$demos){
            foreach($demos as $demo){
                $actions=array_map(fn($a)=>new Action((string)$a['op'],$a['args']??[],(float)($a['confidence']??1),Risk::from((int)($a['risk']??0))),$demo['actions']??[]);
                if($actions!==[])$i->demonstrate((string)$concept,is_array($demo['slots']??null)?$demo['slots']:[],$actions);
            }
        }
        return $i;
    }
}

final class UtteranceBook
{
    /** @var array<string,array<string,array{concept:string,seen:int}>> */
    private array $patterns=[];

    /** @param array<string,scalar|null> $slots */
    public function demonstrate(string $language,string $utterance,string $concept,array $slots,int $minimum=2):bool
    {
        $language=strtolower(trim($language));$utterance=$this->normalize($utterance);
        if($language===''||$utterance===''||$concept===''||$slots===[])throw new \InvalidArgumentException('language utterance concept and slots required');

        $rows=[];
        foreach($slots as $name=>$surface){
            if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',(string)$name))throw new \InvalidArgumentException('invalid slot name');
            $surface=$this->normalize((string)$surface);
            if($surface===''||!str_contains($utterance,$surface))throw new \InvalidArgumentException("slot {$name} is not present in utterance");
            $rows[]=['name'=>(string)$name,'surface'=>$surface];
        }
        usort($rows,fn($a,$b)=>strlen($b['surface'])<=>strlen($a['surface']));
        $pattern=$utterance;
        foreach($rows as $row)$pattern=str_replace($row['surface'],'{'.$row['name'].'}',$pattern);

        $entry=$this->patterns[$language][$pattern]??null;
        if($entry!==null&&$entry['concept']!==$concept)throw new \RuntimeException('conflicting utterance meaning');
        $seen=($entry['seen']??0)+1;
        $this->patterns[$language][$pattern]=['concept'=>$concept,'seen'=>$seen];
        return $seen>=$minimum;
    }

    /** @return array{concept:string,slots:array<string,string>,confidence:float,pattern:string}|null */
    public function parse(string $language,string $utterance,int $minimumSeen=2):?array
    {
        $language=strtolower(trim($language));$utterance=$this->normalize($utterance);
        $best=null;$bestSpecificity=-1;$ambiguous=false;

        foreach($this->patterns[$language]??[] as $pattern=>$entry){
            if($entry['seen']<$minimumSeen)continue;
            [$regex,$slotNames]=$this->compile($pattern);
            if(!preg_match($regex,$utterance,$matches))continue;

            $slots=[];
            foreach($slotNames as $name)$slots[$name]=$this->normalize((string)($matches[$name]??''));
            if(in_array('',$slots,true))continue;

            $static=preg_replace('/\{[A-Za-z_][A-Za-z0-9_]*\}/','',$pattern)??'';
            $specificity=strlen($static);
            $confidence=min(1.0,.80+min(.19,$entry['seen']*.05));

            if($specificity>$bestSpecificity){
                $best=['concept'=>$entry['concept'],'slots'=>$slots,'confidence'=>$confidence,'pattern'=>$pattern];
                $bestSpecificity=$specificity;$ambiguous=false;
            }elseif($specificity===$bestSpecificity&&$best!==null&&$best['concept']!==$entry['concept']){
                $ambiguous=true;
            }
        }
        return $ambiguous?null:$best;
    }

    /** @return array<string,array<string,array{concept:string,seen:int}>> */
    public function export():array{return $this->patterns;}

    /** @param array<string,array<string,array{concept:string,seen:int}>> $data */
    public static function import(array $data):self{$b=new self();$b->patterns=$data;return $b;}

    /** @return array{0:string,1:list<string>} */
    private function compile(string $pattern):array
    {
        $parts=preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/',$pattern,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY)?:[];
        $regex='~^';$names=[];
        foreach($parts as $part){
            if(preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/',$part,$m)){
                if(in_array($m[1],$names,true))throw new \RuntimeException('repeated utterance slot is unsupported');
                $names[]=$m[1];$regex.='(?P<'.$m[1].'>.+?)';
            }else{$regex.=preg_quote($part,'~');}
        }
        return [$regex.'$~u',$names];
    }

    private function normalize(string $text):string
    {
        $text=trim($text);$text=preg_replace('/\s+/u',' ',$text)??$text;
        return function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    }
}

final class Hari
{
    public function __construct(
        public Lexicon $lexicon=new Lexicon(),
        public Memory $memory=new Memory(),
        public Habits $habits=new Habits(),
        public SkillBook $skills=new SkillBook(),
        public SkillInducer $inducer=new SkillInducer(),
        public Affect $affect=new Affect(),
        public ToolRouter $tools=new ToolRouter(),
        public int $age=0,
        public UtteranceBook $utterances=new UtteranceBook(),
    ){}
    public function teach(string $language,string $phrase,string $concept):void
    { $this->lexicon->teach($language,$phrase,$concept); $this->memory->remember("$language:$phrase=$concept",[$language,$phrase,$concept],.75); }
    /** @param list<Action> $template */ public function teachSkill(string $concept,array $template):void{$this->skills->teach($concept,$template);}
    /** @param array<string,scalar|null> $slots @param list<Action> $actions */ public function demonstrateSkill(string $concept,array $slots,array $actions):bool{$this->inducer->demonstrate($concept,$slots,$actions);$template=$this->inducer->induce($concept);if($template===null)return false;$this->skills->teach($concept,$template);return true;}
    /** @param array<string,scalar|null> $slots */
    public function demonstrateUtterance(string $language,string $utterance,string $concept,array $slots):bool
    { return $this->utterances->demonstrate($language,$utterance,$concept,$slots); }
    /** @return array{status:string,concept:?string,confidence:float} */
    public function interpret(string $language,string $phrase,float $actionThreshold=.90):array
    {
        $resolution=$this->lexicon->resolveDetailed($language,$phrase);
        if($resolution===null)return ['status'=>'unknown','concept'=>null,'confidence'=>0.0];
        return [
            'status'=>$resolution['confidence']>=$actionThreshold?'known':'ask',
            'concept'=>$resolution['concept'],
            'confidence'=>$resolution['confidence'],
        ];
    }
    /** @param array<string,scalar|null> $slots @return list<Action>|null */
    public function plan(string $language,string $phrase,array $slots,float $minimumIntentConfidence=.90):?array
    {
        $interpretation=$this->interpret($language,$phrase,$minimumIntentConfidence);
        if($interpretation['status']!=='known'||$interpretation['concept']===null)return null;
        return $this->skills->plan($interpretation['concept'],$slots);
    }
    /** @return list<Action>|null */
    public function planUtterance(string $language,string $utterance,float $minimumConfidence=.90):?array
    {
        $parsed=$this->utterances->parse($language,$utterance);
        if($parsed===null||$parsed['confidence']<$minimumConfidence)return null;
        return $this->skills->plan($parsed['concept'],$parsed['slots']);
    }
    public function tick(int $ticks=1):void
    { if($ticks<0)throw new \InvalidArgumentException('life cannot move backwards');$this->age+=$ticks;$this->memory->age($ticks); }
    public function save(string $path):void
    {
        $body=[
            'age'=>$this->age,'lexicon'=>$this->lexicon->export(),'memory'=>$this->memory->export(),
            'habits'=>$this->habits->export(),'skills'=>$this->skills->export(),'inducer'=>$this->inducer->export(),'affect'=>$this->affect->export(),'tools'=>$this->tools->export(),'utterances'=>$this->utterances->export(),
        ];
        $flags=JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR;
        $bodyJson=json_encode($body,$flags);
        $payload=json_encode(['v'=>5,'body'=>$body,'sha256'=>hash('sha256',$bodyJson)],$flags);
        $dir=dirname($path);
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('state directory');
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,$payload,LOCK_EX)===false)throw new \RuntimeException('state write');
        @chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('state commit');}
    }
    public static function load(string $path):self
    {
        if(!is_file($path))return new self();
        $x=json_decode((string)file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
        $v=(int)($x['v']??1);
        if($v===1)return new self(Lexicon::import($x['lexicon']??[]),Memory::import($x['memory']??[]),Habits::import($x['habits']??[]),SkillBook::import($x['skills']??[]));
        if($v===2)return new self(
            Lexicon::import($x['lexicon']??[]),Memory::import($x['memory']??[]),Habits::import($x['habits']??[]),SkillBook::import($x['skills']??[]),new SkillInducer(),
            Affect::import($x['affect']??[]),ToolRouter::import($x['tools']??[]),(int)($x['age']??0),
        );
        if($v===4||$v===5){
            $body=$x['body']??null;$checksum=(string)($x['sha256']??'');
            if(!is_array($body)||$checksum==='')throw new \RuntimeException('invalid state envelope');
            $bodyJson=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
            if(!hash_equals($checksum,hash('sha256',$bodyJson)))throw new \RuntimeException('state checksum mismatch');
            $x=$body;
        }elseif($v!==3){
            throw new \RuntimeException('unsupported state');
        }
        return new self(
            Lexicon::import($x['lexicon']??[]),Memory::import($x['memory']??[]),Habits::import($x['habits']??[]),SkillBook::import($x['skills']??[]),SkillInducer::import($x['inducer']??[]),
            Affect::import($x['affect']??[]),ToolRouter::import($x['tools']??[]),(int)($x['age']??0),$v===5?UtteranceBook::import($x['utterances']??[]):new UtteranceBook(),
        );
    }
}
