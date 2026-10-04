<?php

declare(strict_types=1);
require __DIR__.'/brain.php';

use Hari\Life\{Effect,HariBrain,SemanticFrame};

$tests=[];

$curriculum=function(HariBrain $b):void{
    $rows=[
        ['mako lumi mesa','MOVE object=ball destination=table'],
        ['mako piko tara','MOVE object=cup destination=shelf'],
        ['mako dara vora','MOVE object=book destination=box'],
        ['mako lumi tara','MOVE object=ball destination=shelf'],
        ['mako piko vora','MOVE object=cup destination=box'],
        ['mako dara mesa','MOVE object=book destination=table'],
    ];
    foreach($rows as [$u,$canonical]){
        $f=SemanticFrame::parse($canonical);
        $b->experience($u,$f,new Effect('location.'.$f->args['object'],$f->args['destination']));
    }
};

$tests['starts uncertain instead of inventing meaning']=function():void{
    $b=new HariBrain();$i=$b->infer('mako lumi mesa');
    ok($i->shouldAsk);eq(null,$i->frame);
};

$tests['learns compositional meaning from demonstrations']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $cases=[
        'mako lumi vora'=>'MOVE destination=box object=ball',
        'mako piko mesa'=>'MOVE destination=table object=cup',
        'mako dara tara'=>'MOVE destination=shelf object=book',
    ];
    foreach($cases as $u=>$want){$i=$b->infer($u);ok(!$i->shouldAsk);eq($want,$i->frame?->canonical());}
};

$tests['induces a reusable world program instead of memorizing outcomes']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $i=$b->infer('mako lumi vora');ok($i->frame!==null);
    $p=$b->predictEffect($i->frame);eq('location.ball=box',$p['effect']?->canonical());ok($p['confidence']>.8);eq('location.{object}={destination}',$p['pattern']);
};

$tests['learns negation once and composes it with a new combination']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $b->experience('sen mako piko mesa',SemanticFrame::parse('NOT MOVE object=cup destination=table'),null,true);
    $i=$b->infer('sen mako dara tara');ok(!$i->shouldAsk);eq('NOT MOVE destination=shelf object=book',$i->frame?->canonical());
    $p=$b->predictEffect($i->frame);eq(null,$p['effect']);
};

$tests['one demonstration can introduce a new object then compose it']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $f=SemanticFrame::parse('MOVE object=key destination=table');
    $b->experience('mako nova mesa',$f,new Effect('location.key','table'),true);
    $i=$b->infer('mako nova tara');ok(!$i->shouldAsk);eq('MOVE destination=shelf object=key',$i->frame?->canonical());
};

$tests['explicit correction locally changes one concept without global retraining']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $b->experience('mako nova mesa',SemanticFrame::parse('MOVE object=key destination=table'),new Effect('location.key','table'),true);
    $b->experience('mako nova mesa',SemanticFrame::parse('MOVE object=phone destination=table'),new Effect('location.phone','table'),true);
    $i=$b->infer('mako nova vora');ok(!$i->shouldAsk);eq('MOVE destination=box object=phone',$i->frame?->canonical());
    $old=$b->infer('mako lumi vora');eq('MOVE destination=box object=ball',$old->frame?->canonical());
};

$tests['one-shot facts revise immediately']=function():void{
    $b=new HariBrain();$b->rememberFact('krishna.city','Kathmandu');eq('Kathmandu',$b->recallFact('krishna.city'));
    $b->rememberFact('krishna.city','Pokhara');eq('Pokhara',$b->recallFact('krishna.city'));
};

$tests['state survives restart with checksum']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $b->rememberFact('owner.name','Pratik');
    $path=sys_get_temp_dir().'/hari-life-'.getmypid().'.json';$b->save($path);$c=HariBrain::load($path);@unlink($path);
    eq('Pratik',$c->recallFact('owner.name'));eq('MOVE destination=box object=ball',$c->infer('mako lumi vora')->frame?->canonical());
};

$tests['explanation is an inspectable evidence trace']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);$x=$b->explain('mako lumi vora');
    eq('ACT',$x['decision']);eq('mako',$x['trace']['verb']['token']);eq('lumi',$x['trace']['roles']['object']['token']);eq('vora',$x['trace']['roles']['destination']['token']);
};

$tests['hard budgets remain bounded during a long stream']=function()use($curriculum):void{
    $b=new HariBrain();$curriculum($b);
    $objects=['ball','cup','book'];$objectWords=['lumi','piko','dara'];$dests=['table','shelf','box'];$destWords=['mesa','tara','vora'];
    for($i=0;$i<5000;$i++){
        $o=$i%3;$d=intdiv($i,3)%3;
        $noise='noise'.$i;
        $u='mako '.$objectWords[$o].' '.$destWords[$d].' '.$noise;
        $f=SemanticFrame::parse('MOVE object='.$objects[$o].' destination='.$dests[$d]);
        $b->experience($u,$f,new Effect('location.'.$objects[$o],$dests[$d]));
        if($i%1000===999)$b->sleep();
    }
    $s=$b->resourceStats();
    ok($s['language']['tokens']<=1024);ok($s['language']['pairs']<=1024*48);ok($s['episodes']<=256);ok($s['programs']['programs']<=256);ok($s['facts']<=256);
    eq('MOVE destination=box object=ball',$b->infer('mako lumi vora')->frame?->canonical());
};

$n=0;$started=microtime(true);
foreach($tests as $name=>$fn){try{$fn();++$n;echo "PASS  {$name}\n";}catch(Throwable $e){fwrite(STDERR,"FAIL  {$name}\n{$e->getMessage()}\n");exit(1);}}
printf("\n%d/%d passed in %.3fs\n",$n,count($tests),microtime(true)-$started);
function eq(mixed $expected,mixed $actual):void{if($expected!==$actual)throw new RuntimeException('expected '.var_export($expected,true).' got '.var_export($actual,true));}
function ok(bool $value):void{if(!$value)throw new RuntimeException('assertion failed');}
