<?php
declare(strict_types=1);
require __DIR__.'/presence.php';
use Hari\{Availability,ConversationBody,PresenceBook,PresenceObservation,WorldInterpreter};
$tests=[];
$tests['body context changes without moving identity']=function(){ $b=new ConversationBody();eq('phone',$b->current());$b->use('computer');eq('computer',$b->current());};
$tests['offline pc reports fact not invented cause']=function(){ $p=new PresenceBook();$p->observe(new PresenceObservation('computer',Availability::Offline,100,'heartbeat','last heartbeat 20s ago'));$a=(new WorldInterpreter())->explainBody($p->select('computer'),true);ok($a->needsInvestigation);eq(null,$a->cause);eq('device is not reachable',$a->fact);};
$tests['known telemetry may explain why']=function(){ $p=new PresenceBook();$p->observe(new PresenceObservation('computer',Availability::Offline,100,'power','battery=0','battery exhausted'));$a=(new WorldInterpreter())->explainBody($p->select('computer'),true);ok(!$a->needsInvestigation);eq('battery exhausted',$a->cause);};
$tests['stale packet cannot overwrite newer presence']=function(){ $p=new PresenceBook();$p->observe(new PresenceObservation('computer',Availability::Online,200,'heartbeat'));$p->observe(new PresenceObservation('computer',Availability::Offline,100,'old'));eq(Availability::Online,$p->get('computer')?->availability);};
$tests['unknown body remains unknown']=function(){ $s=(new PresenceBook())->select('tv');eq(Availability::Unknown,$s->availability);ok(!$s->causeKnown);};
$n=0;foreach($tests as $name=>$fn){try{$fn();++$n;echo "PASS  $name\n";}catch(Throwable $e){fwrite(STDERR,"FAIL  $name\n{$e->getMessage()}\n");exit(1);}}echo "\n$n/".count($tests)." passed\n";
function eq(mixed $a,mixed $b):void{if($a!==$b)throw new RuntimeException('expected '.var_export($a,true).' got '.var_export($b,true));}
function ok(bool $x):void{if(!$x)throw new RuntimeException('assertion failed');}