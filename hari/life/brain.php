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
        private float $semanticAlternativeRatio = .90,
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

        $opAtoms=[];
        foreach($tokens as $token){
            $c=$this->language->bestForToken($token,'verb');
            if($c['atom']!==null)$opAtoms[$c['atom']]=true;
        }

        $best=null;$runner=0.0;$alignmentScores=[];
        foreach(array_keys($opAtoms) as $opAtom){
            $verbValue=substr($opAtom,strlen('verb:'));
            $roles=$this->schemas->requiredRoles($verbValue);
            foreach($tokens as $verbIndex=>$verbToken){
                $verbScore=$this->language->associationScore($verbToken,$opAtom);
                if($verbScore<.20)continue;
                $used=[$verbIndex=>true];
                $alignments=$this->alignRolesAll($tokens,$roles,0,$used,[],[]);
                foreach($alignments as $aligned){
                    $scores=array_merge([$verbScore],$aligned['scores']);
                    $score=array_sum($scores)/max(1,count($scores));
                    $candidate=['verb'=>$verbValue,'verbAtom'=>$opAtom,'verbToken'=>$verbToken,'verbScore'=>$verbScore,'roles'=>$roles,'args'=>$aligned['args'],'scores'=>$scores,'used'=>$aligned['used'],'roleTrace'=>$aligned['trace'],'score'=>$score];
                    $candidate['used'][$verbIndex]=true;
                    $candidateArgs=$candidate['args'];ksort($candidateArgs);
                    $candidate['semanticSignature']=(new SemanticFrame($candidate['verb'],$candidateArgs,false))->canonical();
                    $binding=['verb@'.$verbToken];
                    foreach($candidate['roleTrace'] as $role=>$rt)$binding[]=$role.'@'.($rt['token']??'');
                    sort($binding);
                    $candidate['bindingSignature']=implode('|',$binding);
                    $alignmentScores[$candidate['bindingSignature']]=max($alignmentScores[$candidate['bindingSignature']]??0.0,$score);

                    if($best===null||$score>$best['score']){
                        if($best!==null&&$best['semanticSignature']!==$candidate['semanticSignature'])$runner=max($runner,$best['score']);
                        $best=$candidate;
                    }elseif($best['semanticSignature']!==$candidate['semanticSignature']){
                        $runner=max($runner,$score);
                    }
                }
            }
        }

        $alignmentRunner=0.0;
        if($best!==null){
            foreach($alignmentScores as $signature=>$score){
                if($signature!==$best['bindingSignature'])$alignmentRunner=max($alignmentRunner,$score);
            }
        }
        $semanticAmbiguous=$best!==null&&$runner>0.0&&($runner/max(0.000001,$best['score']))>=$this->semanticAlternativeRatio;
        if($best===null||$best['score']<$this->actThreshold||$semanticAmbiguous){
            return new Inference(null,$best['score']??0.0,true,'I am not sure how the words map to an action.',['tokens'=>$tokens,'best_alignment'=>$best,'runner_up'=>$runner,'alignment_runner_up'=>$alignmentRunner]);
        }

        $negated=false;$negTrace=null;
        foreach($tokens as $i=>$token){
            if(isset($best['used'][$i]))continue;
            $neg=$this->language->bestForToken($token,'polarity');
            if($neg['atom']==='polarity:NEG'&&$neg['score']>=$this->actThreshold&&($neg['score']-$neg['runner_up'])>=$this->ambiguityMargin){
                $negated=true;$negTrace=$neg;break;
            }
        }

        $args=$best['args'];ksort($args);
        return new Inference(
            new SemanticFrame($best['verb'],$args,$negated),
            (float)$best['score'],
            false,
            '',
            ['tokens'=>$tokens,'verb'=>['atom'=>$best['verbAtom'],'score'=>$best['verbScore'],'token'=>$best['verbToken'],'runner_up'=>$runner],'roles'=>$best['roleTrace'],'alignment'=>['verb'=>$best['verb'],'verb_token'=>$best['verbToken'],'verb_score'=>$best['verbScore'],'score'=>$best['score'],'roles'=>$best['roleTrace'],'runner_up'=>$runner,'alignment_runner_up'=>$alignmentRunner],'polarity'=>$negTrace],
        );
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $roles
     * @param array<int,bool> $used
     * @param array<string,string> $args
     * @param list<float> $scores
     * @return array{args:array<string,string>,scores:list<float>,used:array<int,bool>,trace:array<string,mixed>}|null
     */
    /**
     * Return competing token-to-role explanations instead of discarding all but
     * one. The cap keeps ambiguity search bounded.
     *
     * @param list<string> $tokens
     * @param list<string> $roles
     * @param array<int,bool> $used
     * @param array<string,string> $args
     * @param list<float> $scores
     * @return list<array{args:array<string,string>,scores:list<float>,used:array<int,bool>,trace:array<string,mixed>}>
     */
    private function alignRolesAll(array $tokens,array $roles,int $at,array $used,array $args,array $scores,int $limit=128):array
    {
        if($at>=count($roles))return [['args'=>$args,'scores'=>$scores,'used'=>$used,'trace'=>[]]];

        $role=$roles[$at];$out=[];
        foreach($tokens as $i=>$token){
            if(isset($used[$i]))continue;
            $choice=$this->language->bestForToken($token,'arg.'.$role);
            if($choice['atom']===null||$choice['score']<.20)continue;

            $prefix='arg.'.$role.':';
            $value=substr($choice['atom'],strlen($prefix));
            $u=$used;$u[$i]=true;
            $a=$args;$a[$role]=$value;
            $sc=$scores;$sc[]=$choice['score'];

            foreach($this->alignRolesAll($tokens,$roles,$at+1,$u,$a,$sc,$limit) as $rest){
                $trace=$rest['trace'];
                $trace[$role]=['token'=>$token,'atom'=>$choice['atom'],'score'=>$choice['score'],'runner_up'=>$choice['runner_up']];
                $rest['trace']=$trace;
                $out[]=$rest;
                if(count($out)>=$limit)return $out;
            }
        }
        return $out;
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

        $aligned=false;
        if(!$correction&&$before->frame!==null&&$before->frame->equals($truth)){
            $trace=$before->trace;
            $alignmentScore=(float)($trace['alignment']['score']??0.0);
            $alignmentRunner=(float)($trace['alignment']['alignment_runner_up']??0.0);
            $alignmentUnique=$alignmentScore>0.0&&($alignmentRunner<=0.0||($alignmentRunner/$alignmentScore)<$this->semanticAlternativeRatio);
            $bindings=[];
            $verbToken=$trace['alignment']['verb_token']??null;
            if(is_string($verbToken)&&$verbToken!=='')$bindings[$verbToken]='verb:'.$truth->verb;
            foreach($trace['alignment']['roles']??[] as $role=>$roleTrace){
                $token=$roleTrace['token']??null;
                if(is_string($token)&&isset($truth->args[$role]))$bindings[$token]='arg.'.$role.':'.$truth->args[$role];
            }
            if($truth->negated&&is_array($trace['polarity']??null)){
                $token=$trace['polarity']['token']??null;
                if(is_string($token)&&$token!=='')$bindings[$token]='polarity:NEG';
            }
            if($alignmentUnique&&$bindings!==[]){
                $this->language->learnBindings($bindings,$strength);
                $aligned=true;
            }
        }
        if(!$aligned)$this->language->learn($tokens,$truth->atoms(),$strength,$correction);
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
        $body=['v'=>1,'language'=>$this->language->export(),'schemas'=>$this->schemas->export(),'programs'=>$this->programs->export(),'episodes'=>$this->episodes->export(),'facts'=>$this->facts->export(),'actThreshold'=>$this->actThreshold,'ambiguityMargin'=>$this->ambiguityMargin,'semanticAlternativeRatio'=>$this->semanticAlternativeRatio];
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
        return new self(AssociationMemory::import($body['language']??[]),SchemaMemory::import($body['schemas']??[]),ProgramMemory::import($body['programs']??[]),EpisodicMemory::import($body['episodes']??[]),FactMemory::import($body['facts']??[]),(float)($body['actThreshold']??.42),(float)($body['ambiguityMargin']??.06),(float)($body['semanticAlternativeRatio']??.90));
    }
}
