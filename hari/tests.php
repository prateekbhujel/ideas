<?php

declare(strict_types=1);
require __DIR__.'/core.php';

use Hari\{Action,Executor,Habits,Hari,Memory,Risk,Tool,ToolRouter};

$tests=[];
$tests['teaches native language without retraining']=function(){ $h=new Hari();$h->teach('ne','देखिने फोन','video_call');eq('video_call',$h->lexicon->resolve('ne','देखिने   फोन')); };
$tests['personal memory weakens with time and a cue reinforces it']=function(){ $m=new Memory();$x=$m->remember('Mom calls Viber video phone',['mom','video phone'],.6);$m->age(180);$before=$x->effective(180);$got=$m->recall(['mom','video phone']);ok($got!==null);ok($got->strength>$before); };
$tests['learned language and memory survive restart']=function(){ $p=sys_get_temp_dir().'/hari-'.bin2hex(random_bytes(3)).'.json';try{$h=new Hari();$h->teach('ne','बाबुलाई फोन','call_dad');$h->memory->remember('Dad prefers slow instructions',['dad','slow'],.9);$h->save($p);$r=Hari::load($p);eq('call_dad',$r->lexicon->resolve('ne','बाबुलाई फोन'));eq('Dad prefers slow instructions',$r->memory->recall(['dad','slow'])?->text);}finally{@unlink($p);} };
$tests['habit learner waits for evidence']=function(){ $h=new Habits();$h->observe('mom:call','whatsapp');$h->observe('mom:call','whatsapp');eq(null,$h->predict('mom:call'));$h->observe('mom:call','viber');$h->observe('mom:call','whatsapp');eq('whatsapp',$h->predict('mom:call')['choice']); };
$tests['habits survive restart']=function(){ $p=sys_get_temp_dir().'/hari-habit-'.bin2hex(random_bytes(3)).'.json';try{$h=new Hari();for($i=0;$i<4;$i++)$h->habits->observe('mom:video','whatsapp');$h->save($p);$r=Hari::load($p);eq('whatsapp',$r->habits->predict('mom:video')['choice']);}finally{@unlink($p);} };
$tests['taught Nepali intent compiles to verified machine actions']=function(){ $h=new Hari();$h->teach('ne','देखिने फोन','video_call');$plan=$h->plan('ne','देखिने फोन',['person'=>'Pratik']);ok($plan!==null);eq('FIND_CONTACT',$plan[0]->op);eq('VIDEO_CALL',$plan[1]->op);$e=new Executor();eq('confirm',$e->run($plan)['why']);ok($e->run($plan,[1])['ok']); };
$tests['external actions require confirmation']=function(){ $e=new Executor();$plan=[new Action('FIND_CONTACT',['person'=>'Pratik'],.99),new Action('CALL',['person'=>'Pratik'],.99,Risk::External)];$a=$e->run($plan);eq('confirm',$a['why']);eq(1,count($e->done()));$b=$e->run($plan,[1]);ok($b['ok']); };
$tests['uncertain actions never execute']=function(){ $e=new Executor();$r=$e->run([new Action('OPEN_APP',['app'=>'WhatsApp'],.25,Risk::Reversible)]);eq('uncertain',$r['why']);eq(0,count($e->done())); };
$tests['tool routing changes from experience']=function(){ $r=new ToolRouter();$r->add(new Tool('local',['coding'],0,.70,true));$r->add(new Tool('expert',['coding'],.6,.85,false));eq('local',$r->choose('coding')?->name);for($i=0;$i<10;$i++){$r->record('local',false);$r->record('expert',true);}eq('expert',$r->choose('coding')?->name); };

$n=0;foreach($tests as $name=>$fn){try{$fn();$n++;echo "PASS  $name\n";}catch(Throwable $e){fwrite(STDERR,"FAIL  $name\n{$e->getMessage()}\n");exit(1);}}echo "\n$n/".count($tests)." passed\n";
function eq(mixed $a,mixed $b):void{if($a!==$b)throw new RuntimeException('expected '.var_export($a,true).' got '.var_export($b,true));}
function ok(bool $x):void{if(!$x)throw new RuntimeException('assertion failed');}
