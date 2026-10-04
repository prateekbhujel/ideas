<?php

declare(strict_types=1);
require __DIR__.'/brain.php';

use Hari\Life\{Effect,HariBrain,SemanticFrame};

final class ExactPhraseBaseline
{
    /** @var array<string,string> */ private array $map=[];
    public function learn(string $utterance,SemanticFrame $frame):void{$this->map[HariBrain::tokenize($utterance)===[]?'':implode(' ',HariBrain::tokenize($utterance))]=$frame->canonical();}
    public function infer(string $utterance):?string{return $this->map[implode(' ',HariBrain::tokenize($utterance))]??null;}
}

/** @return array{0:HariBrain,1:ExactPhraseBaseline,2:list<array{string,string,string}>} */
function train():array
{
    $b=new HariBrain();$base=new ExactPhraseBaseline();
    $families=[
        ['verb'=>'MOVE','word'=>'mako','role'=>'destination','values'=>['table'=>'mesa','shelf'=>'tara','box'=>'vora'],'effect'=>'location.{object}={destination}'],
        ['verb'=>'PAINT','word'=>'zefi','role'=>'color','values'=>['red'=>'rena','blue'=>'bela','green'=>'gira'],'effect'=>'color.{object}={color}'],
        ['verb'=>'GIVE','word'=>'nari','role'=>'recipient','values'=>['alice'=>'soma','bob'=>'toma','cara'=>'kira'],'effect'=>'owner.{object}={recipient}'],
    ];
    $objects=['ball'=>'lumi','cup'=>'piko','book'=>'dara'];
    $training=[];$held=[];
    foreach($families as $family){
        $vals=array_keys($family['values']);$valWords=array_values($family['values']);$objs=array_keys($objects);$objWords=array_values($objects);
        for($o=0;$o<3;$o++){
            for($v=0;$v<3;$v++){
                $utter=$family['word'].' '.$objWords[$o].' '.$valWords[$v];
                $frame=$family['verb'].' object='.$objs[$o].' '.$family['role'].'='.$vals[$v];
                $effect=str_replace(['{object}','{'.$family['role'].'}'],[$objs[$o],$vals[$v]],$family['effect']);
                if($v===($o+2)%3){$held[]=[$utter,$frame,$effect];}
                else{$training[]=[$utter,$frame,$effect];}
            }
        }
    }
    foreach($training as [$u,$c,$e]){
        $f=SemanticFrame::parse($c);$effect=Effect::parse($e);$b->experience($u,$f,$effect);$base->learn($u,$f);
    }
    return [$b,$base,$held];
}

[$brain,$baseline,$held]=train();
$correct=0;$baselineCorrect=0;$worldCorrect=0;
foreach($held as [$u,$canonical,$effect]){
    $truth=SemanticFrame::parse($canonical);$i=$brain->infer($u);
    if($i->frame?->equals($truth))$correct++;
    if($baseline->infer($u)===$truth->canonical())$baselineCorrect++;
    if($i->frame){$p=$brain->predictEffect($i->frame);if($p['effect']?->canonical()===$effect)$worldCorrect++;}
}

// Negation is taught in two contexts, then tested on a third verb and unseen combination.
$brain->experience('sen mako lumi mesa',SemanticFrame::parse('NOT MOVE object=ball destination=table'),null,true);
$brain->experience('sen nari piko toma',SemanticFrame::parse('NOT GIVE object=cup recipient=bob'),null,true);
$neg=$brain->infer('sen zefi dara bela');
$negCorrect=$neg->frame?->canonical()==='NOT PAINT color=blue object=book';

// One new concept from one demonstration, transferred to a different learned program.
$brain->experience('mako nova mesa',SemanticFrame::parse('MOVE object=key destination=table'),Effect::parse('location.key=table'),true);
$oneShot=$brain->infer('zefi nova gira');
$oneShotCorrect=$oneShot->frame?->canonical()==='PAINT color=green object=key';
$oneShotWorld=$oneShot->frame?$brain->predictEffect($oneShot->frame):['effect'=>null];
$oneShotWorldCorrect=$oneShotWorld['effect']?->canonical()==='color.key=green';

// Explicit correction changes the novel concept without replaying old training data.
$brain->experience('mako nova mesa',SemanticFrame::parse('MOVE object=phone destination=table'),Effect::parse('location.phone=table'),true);
$corrected=$brain->infer('nari nova soma');
$correctionCorrect=$corrected->frame?->canonical()==='GIVE object=phone recipient=alice';
$retained=$brain->infer('zefi lumi bela');
$retentionCorrect=$retained->frame?->canonical()==='PAINT color=blue object=ball';

// Unknown language must not execute.
$unknown=$brain->infer('totally unseen words');
$unknownSafe=$unknown->shouldAsk&&$unknown->frame===null;

// One-shot mutable fact memory.
$brain->rememberFact('krishna.city','Kathmandu');
$brain->rememberFact('krishna.city','Pokhara');
$factCorrect=$brain->recallFact('krishna.city')==='Pokhara';

// Long non-stationary stream with unique distractors forces the token budget to fill.
$verbs=[
    ['MOVE','mako','destination',['table'=>'mesa','shelf'=>'tara','box'=>'vora'],'location'],
    ['PAINT','zefi','color',['red'=>'rena','blue'=>'bela','green'=>'gira'],'color'],
    ['GIVE','nari','recipient',['alice'=>'soma','bob'=>'toma','cara'=>'kira'],'owner'],
];
$objects=['ball'=>'lumi','cup'=>'piko','book'=>'dara'];$objKeys=array_keys($objects);$objWords=array_values($objects);
$streamEvents=4000;$started=microtime(true);
for($i=0;$i<$streamEvents;$i++){
    $vf=$verbs[$i%3];$o=intdiv($i,3)%3;$v=intdiv($i,9)%3;$values=array_keys($vf[3]);$words=array_values($vf[3]);
    $u=$vf[1].' '.$objWords[$o].' '.$words[$v].' distractor'.$i;
    $canonical=$vf[0].' object='.$objKeys[$o].' '.$vf[2].'='.$values[$v];
    $f=SemanticFrame::parse($canonical);
    $key=$vf[4].'.'.$objKeys[$o];$e=new Effect($key,$values[$v]);
    $brain->experience($u,$f,$e);
    if($i%500===499)$brain->sleep();
}
$seconds=microtime(true)-$started;
$stats=$brain->resourceStats();
$postLife=$brain->infer('zefi lumi bela');
$postLifeCorrect=$postLife->frame?->canonical()==='PAINT color=blue object=ball';

$tmp=sys_get_temp_dir().'/hari-life-benchmark-'.getmypid().'.json';$brain->save($tmp);$stateBytes=filesize($tmp)?:0;$restored=HariBrain::load($tmp);@unlink($tmp);
$restartCorrect=$restored->infer('mako piko mesa')->frame?->canonical()==='MOVE destination=table object=cup';

$total=count($held);
$result=[
    'architecture'=>[
        'transformer'=>false,
        'pretrained_model'=>false,
        'backpropagation'=>false,
        'normal_learning'=>'local bounded association updates + episodic memory + induced programs',
    ],
    'held_out_composition'=>[
        'cases'=>$total,
        'hari_correct'=>$correct,
        'hari_accuracy'=>$total?round($correct/$total,4):0,
        'exact_phrase_baseline_correct'=>$baselineCorrect,
        'exact_phrase_baseline_accuracy'=>$total?round($baselineCorrect/$total,4):0,
        'world_effect_correct'=>$worldCorrect,
    ],
    'capabilities'=>[
        'negation_transfer'=>$negCorrect,
        'one_shot_new_concept_transfer'=>$oneShotCorrect,
        'one_shot_world_program_transfer'=>$oneShotWorldCorrect,
        'explicit_correction'=>$correctionCorrect,
        'unrelated_retention_after_correction'=>$retentionCorrect,
        'unknown_causes_ask'=>$unknownSafe,
        'one_shot_fact_revision'=>$factCorrect,
        'restart_retention'=>$restartCorrect,
        'retention_after_long_stream'=>$postLifeCorrect,
    ],
    'bounded_life'=>[
        'stream_events'=>$streamEvents,
        'seconds'=>round($seconds,4),
        'events_per_second'=>$seconds>0?round($streamEvents/$seconds,2):null,
        'state_bytes'=>$stateBytes,
        'resource_stats'=>$stats,
        'limits'=>['tokens'=>1024,'pairs_per_token'=>48,'episodes'=>256,'programs'=>256,'facts'=>256],
    ],
    'warning'=>'Synthetic grounded world only. This is evidence for mechanisms, not evidence of human-level language or intelligence.',
];

$json=json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
echo $json,"\n";
foreach($argv as $arg){if(str_starts_with($arg,'--json=')){file_put_contents(substr($arg,7),$json."\n");}}

$allGood=$correct===$total&&$baselineCorrect===0&&$worldCorrect===$total&&$negCorrect&&$oneShotCorrect&&$oneShotWorldCorrect&&$correctionCorrect&&$retentionCorrect&&$unknownSafe&&$factCorrect&&$restartCorrect&&$postLifeCorrect
    &&$stats['language']['tokens']<=1024&&$stats['language']['pairs']<=1024*48&&$stats['episodes']<=256&&$stats['programs']['programs']<=256&&$stats['facts']<=256;
exit($allGood?0:1);
