<?php

declare(strict_types=1);

namespace Hari\Life;

require_once __DIR__.'/frame.php';
require_once __DIR__.'/memory.php';

final class HariBrain
{
    public function __construct(
        private AssociationMemory $language = new AssociationMemory(),
        private SchemaMemory $schemas = new SchemaMemory(),
        private ProgramMemory $programs = new ProgramMemory(),
        private EpisodicMemory $episodes = new EpisodicMemory(),
        private FactMemory $facts = new FactMemory(),
        private float $actThreshold = .42,
        private float $ambiguityMargin = .06,
    ) {}

    /** @return list<string> */
    public static function tokenize(string $utterance): array
    {
        $u = trim(strtolower($utterance));
        if($u==='')return [];
        $tokens=preg_split('/\s+/u',$u)?:[];
        return array_values(array_filter($tokens,fn($x)=>$x!==''));
    }

    public function infer(string $utterance): Inference
    {
        $tokens=self::tokenize($utterance);
        if($tokens===[])return new Inference(null,0.0,true,'I heard nothing.',['tokens'=>[]]);

        $verb=$this->language->best($tokens,'verb');
        if($verb['atom']===null||$verb['score']<$this->actThreshold){
            return new Inference(null,$verb['score'],true,'I do not know what action that means yet.',['tokens'=>$tokens,'verb'=>$verb]);
        }
        $verbValue=substr($verb['atom'],strlen('verb:'));
        $roles=$this->schemas->requiredRoles($verbValue);
        $args=[];$traceRoles=[];$confidence=$verb['score'];
        foreach($roles as $role){
            $best=$this->language->best($tokens,'arg.'.$role);
            $traceRoles[$role]=$best;
            if($best['atom']===null||$best['score']<$this->actThreshold||($best['score']-$best['runner_up'])<$this->ambiguityMargin){
                return new Inference(null,min($confidence,$best['score']),true,"I am not sure about {$role}.",['tokens'=>$tokens,'verb'=>$verb,'roles'=>$traceRoles]);
            }
            $args[$role]=substr($best['atom'],strlen('arg.'.$role.':'));
            $confidence=min($confidence,$best['score']);
        }
        ksort($args);
        $neg=$this->language->best($tokens,'polarity');
        $negated=$neg['atom']==='polarity:NEG'&&$neg['score']>=$this->actThreshold&&($neg['score']-$neg['runner_up'])>=$this->ambiguityMargin;
        return new Inference(new SemanticFrame($verbValue,$args,$negated),$confidence,false,'',['tokens'=>$tokens,'verb'=>$verb,'roles'=>$traceRoles,'polarity'=>$neg]);
    }

    public function experience(string $utterance, SemanticFrame $truth, ?Effect $observedEffect=null, bool $correction=false): array
    {
        $before=$this->infer($utterance);
        $predicted=$before->frame? $this->programs->predict($before->frame):['effect'=>null,'confidence'=>0.0,'pattern'=>null];
        $surprise=1.0;
        if($before->frame!==null&&$before->frame->equals($truth)){
            if($observedEffect===null){$surprise=0.0;}
            elseif($predicted['effect'] instanceof Effect&&$predicted['effect']->canonical()===$observedEffect->canonical()){$surprise=0.0;}
            else{$surprise=.65;}
        }
        $strength=$correction?3.0:1.0;
        $tokens=self::tokenize($utterance);
        $this->language->learn($tokens,$truth->atoms(),$strength,$correction);
        $this->schemas->learn($truth);
        if($observedEffect!==null)$this->programs->learn($truth,$observedEffect,$correction?5.0:1.0);
        $this->episodes->remember($utterance,$truth,$observedEffect,$surprise,$correction,$this->language->step());
        return ['surprise'=>$surprise,'before'=>$before,'predicted_effect'=>$predicted];
    }

    public function predictEffect(SemanticFrame $frame): array{return $this->programs->predict($frame);}
    public function rememberFact(string $key,string $value):void{$this->facts->set($key,$value,$this->language->step());}
    public function recallFact(string $key):?string{return $this->facts->get($key);}

    public function sleep(): array
    {
        $before=$this->resourceStats();
        $this->language->consolidate();
        $this->episodes->compact();
        $after=$this->resourceStats();
        return ['before'=>$before,'after'=>$after];
    }

    /** @return array<string,mixed> */
    public function resourceStats(): array
    {
        return ['language'=>$this->language->stats(),'programs'=>$this->programs->stats(),'episodes'=>$this->episodes->count(),'facts'=>$this->facts->count()];
    }

    public function explain(string $utterance): array
    {
        $i=$this->infer($utterance);
        $out=['utterance'=>$utterance,'decision'=>$i->shouldAsk?'ASK':'ACT','confidence'=>$i->confidence,'frame'=>$i->frame?->canonical(),'question'=>$i->question,'trace'=>$i->trace];
        if($i->frame)$out['world_prediction']=$this->programs->predict($i->frame);
        return $out;
    }

    public function save(string $path): void
    {
        $body=['v'=>1,'language'=>$this->language->export(),'schemas'=>$this->schemas->export(),'programs'=>$this->programs->export(),'episodes'=>$this->episodes->export(),'facts'=>$this->facts->export(),'actThreshold'=>$this->actThreshold,'ambiguityMargin'=>$this->ambiguityMargin'];
        $json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $envelope=json_encode(['body'=>$body,'sha256'=>hash('sha256',$json)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        $tmp=$path.'.tmp.'.getmypid();file_put_contents($tmp,$envelope,LOCK_EX);@chmod($tmp,0600);rename($tmp,$path);
    }

    public static function load(string $path): self
    {
        $e=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        $body=$e['body']??throw new \RuntimeException('invalid state');
        $json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(!hash_equals((string)($e['sha256']??''),hash('sha256',$json)))throw new \RuntimeException('state checksum mismatch');
        return new self(AssociationMemory::import($body['language']??[]),SchemaMemory::import($body['schemas']??[]),ProgramMemory::import($body['programs']??[]),EpisodicMemory::import($body['episodes']??[]),FactMemory::import($body['facts']??[]),(float)($body['actThreshold']??.42),(float)($body['ambiguityMargin']??.06));
    }
}
