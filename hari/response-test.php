<?php
declare(strict_types=1);
require __DIR__.'/core.php';require __DIR__.'/dialogue.php';require __DIR__.'/presence.php';require __DIR__.'/response.php';
use Hari\{GroundingDecision,GroundingMove,HumanResponsePolicy,InteractionSignals,ResponseMode,WorldAnswer};
$tests=[];
$tests['heated correction changes repair style not facts']=function(){ $p=new HumanResponsePolicy();$x=$p->plan(new GroundingDecision(GroundingMove::Repair,'broke'),new InteractionSignals(.95,true,true,false,true));eq(ResponseMode::ComfortRepair,$x->mode);ok($x->stopCurrentAction);ok($x->acknowledgeOwnMistake);ok($x->avoidEmotionClaim);eq('warm_and_steady',$x->tone);};
$tests['ambiguity asks and blocks']=function(){ $p=new HumanResponsePolicy();$x=$p->plan(new GroundingDecision(GroundingMove::Ask,'unclear',['person']),new InteractionSignals());eq(ResponseMode::Clarify,$x->mode);ok($x->stopCurrentAction);};
$tests['offline pc unknown cause stays unknown']=function(){ $p=new HumanResponsePolicy();$w=new WorldAnswer('unreachable','device is not reachable',null,true);$x=$p->plan(new GroundingDecision(GroundingMove::Hold,'off'),new InteractionSignals(userAskedWhy:true),$w);eq(ResponseMode::Investigate,$x->mode);ok(in_array('say_cause_unknown',$x->steps,true));};
$tests['known cause may be reported']=function(){ $p=new HumanResponsePolicy();$w=new WorldAnswer('unreachable','device is not reachable','battery exhausted',false);$x=$p->plan(new GroundingDecision(GroundingMove::Hold,'off'),new InteractionSignals(userAskedWhy:true),$w);eq(ResponseMode::Report,$x->mode);ok(in_array('state_known_cause',$x->steps,true));};
$n=0;foreach($tests as $name=>$fn){try{$fn();++$n;echo "PASS  $name\n";}catch(Throwable $e){fwrite(STDERR,"FAIL  $name\n{$e->getMessage()}\n");exit(1);}}echo "\n$n/".count($tests)." passed\n";
function eq(mixed $a,mixed $b):void{if($a!==$b)throw new RuntimeException('expected '.var_export($a,true).' got '.var_export($b,true));}
function ok(bool $x):void{if(!$x)throw new RuntimeException('assertion failed');}