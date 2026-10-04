<?php

declare(strict_types=1);
require __DIR__.'/brain.php';
use Hari\Life\{Effect,HariBrain,SemanticFrame};

$tests=[];
$tests['biased evidence becomes uncertainty rather than a confident fabricated role']=function():void{
    $b=new HariBrain();
    // "lumi" and "mesa" are perfectly correlated here. HARI should not pretend
    // it has identified which one carries destination when they later conflict.
    for($i=0;$i<6;$i++)$b->experience('mako lumi mesa',SemanticFrame::parse('MOVE object=ball destination=table'),new Effect('location.ball','table'));
    $b->experience('mako piko tara',SemanticFrame::parse('MOVE object=cup destination=shelf'),new Effect('location.cup','shelf'));
    $i=$b->infer('mako lumi tara');
    ok($i->shouldAsk||$i->frame?->canonical()==='MOVE destination=shelf object=ball');
};
$tests['checksum corruption is rejected']=function():void{
    $b=new HariBrain();$path=sys_get_temp_dir().'/hari-corrupt-'.getmypid().'.json';$b->save($path);$raw=file_get_contents($path);$raw=str_replace('"v": 1','"v": 2',$raw);file_put_contents($path,$raw);
    $thrown=false;try{HariBrain::load($path);}catch(RuntimeException){$thrown=true;}@unlink($path);ok($thrown);
};
$tests['unknown words never become action solely because a world program exists']=function():void{
    $b=new HariBrain();
    foreach([['mako lumi mesa','MOVE object=ball destination=table'],['mako piko tara','MOVE object=cup destination=shelf']] as [$u,$c]){$f=SemanticFrame::parse($c);$b->experience($u,$f,new Effect('location.'.$f->args['object'],$f->args['destination']));}
    $i=$b->infer('blah blah blah');ok($i->shouldAsk);eq(null,$i->frame);
};
$n=0;foreach($tests as $name=>$fn){try{$fn();++$n;echo "PASS  {$name}\n";}catch(Throwable $e){fwrite(STDERR,"FAIL  {$name}\n{$e->getMessage()}\n");exit(1);}}echo "\n{$n}/".count($tests)." passed\n";
function eq(mixed $a,mixed $b):void{if($a!==$b)throw new RuntimeException('expected '.var_export($a,true).' got '.var_export($b,true));}
function ok(bool $x):void{if(!$x)throw new RuntimeException('assertion failed');}
