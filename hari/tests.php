<?php

declare(strict_types=1);
require __DIR__.'/core.php';

use Hari\{Action,Affect,Executor,Habits,Hari,Memory,Risk,Tool,ToolRouter};

$tests=[];

$tests['teaches native language without retraining']=function(){
    $h=new Hari();$h->teach('ne','देखिने फोन','video_call');
    eq('video_call',$h->lexicon->resolve('ne','देखिने   फोन')['concept']??null);
};

$tests['learns an exact machine procedure from repeated human demonstrations']=function(){
    $h=new Hari();$h->teach('ne','देखिने फोन','video_call');
    $demo=[new Action('FIND_CONTACT',['person'=>'Pratik'],.99),new Action('CALL_VIDEO',['person'=>'Pratik'],.96,Risk::External)];
    $h->demonstrate('ne','देखिने फोन',$demo);$h->demonstrate('ne','देखिने फोन',$demo);
    eq(null,$h->plan('ne','देखिने फोन'));
    $h->demonstrate('ne','देखिने फोन',$demo);
    $plan=$h->plan('ne','देखिने फोन');ok($plan!==null);eq('CALL_VIDEO',$plan[1]->op);
};

$tests['unknown language never invents a machine procedure']=function(){
    $h=new Hari();eq(null,$h->plan('ne','यो मैले कहिल्यै सिकाएको छैन'));
};

$tests['external actions require confirmation']=function(){
    $e=new Executor();$plan=[new Action('FIND_CONTACT',['person'=>'Pratik'],.99),new Action('CALL',['person'=>'Pratik'],.99,Risk::External)];
    $a=$e->run($plan);eq('confirm',$a['why']);eq(1,count($e->done()));$b=$e->run($plan,[1]);ok($b['ok']);
};

$tests['uncertain actions never execute']=function(){
    $e=new Executor();$r=$e->run([new Action('OPEN_APP',['app'=>'WhatsApp'],.25,Risk::Reversible)]);eq('uncertain',$r['why']);eq(0,count($e->done()));
};

$tests['personal memory weakens with time and a cue reinforces it']=function(){
    $m=new Memory();$x=$m->remember('Mom calls Viber video phone',['mom','video phone'],.6);$m->age(180);
    $before=$x->effective(180);$got=$m->recall(['mom','video phone']);ok($got!==null);ok($got->strength>$before);
};

$tests['important memories retain a floor while weak noise can disappear']=function(){
    $m=new Memory();$m->remember('Dad calls Krishna ठूलो मामा',['dad','family'],.98);$m->remember('random screen flash',['noise'],.01);
    $m->age(1000);$m->forget(.08);ok($m->recall(['dad','family'],false,.01)!==null);eq(null,$m->recall(['noise'],false,.01));
};

$tests['habit learner waits for evidence']=function(){
    $h=new Habits();$h->observe('mom:call','whatsapp');$h->observe('mom:call','whatsapp');eq(null,$h->predict('mom:call'));
    $h->observe('mom:call','viber');$h->observe('mom:call','whatsapp');eq('whatsapp',$h->predict('mom:call')['choice']);
};

$tests['tool routing changes from experience']=function(){
    $r=new ToolRouter();$r->add(new Tool('local',['coding'],0,.70,true));$r->add(new Tool('expert',['coding'],.6,.85,false));eq('local',$r->choose('coding')?->name);
    for($i=0;$i<10;$i++){$r->record('local',false);$r->record('expert',true);}eq('expert',$r->choose('coding')?->name);
};

$tests['affect changes behavior state but is only state']=function(){
    $a=new Affect();$before=$a->export();$a->corrected();$after=$a->export();ok($after['frustration']>$before['frustration']);$a->success();ok($a->export()['frustration']<$after['frustration']);
};

$tests['whole life survives restart']=function(){
    $p=sys_get_temp_dir().'/hari-'.bin2hex(random_bytes(3)).'.json';
    try{
        $h=new Hari();$h->teach('ne','बाबुलाई फोन','call_dad');$h->memory->remember('Dad prefers slow instructions',['dad','slow'],.9);
        for($i=0;$i<4;$i++)$h->habits->observe('dad:teaching','slow');
        $h->affect->corrected();$h->tools->add(new Tool('local',['image'],0,.7,true));$h->tools->add(new Tool('expert',['image'],.5,.9,false));
        for($i=0;$i<5;$i++){$h->tools->record('local',false);$h->tools->record('expert',true);}
        $demo=[new Action('FIND_CONTACT',['person'=>'Dad'],.99),new Action('CALL',['person'=>'Dad'],.99,Risk::External)];
        for($i=0;$i<3;$i++)$h->demonstrate('ne','बाबुलाई फोन',$demo);
        $h->tick(42);$h->save($p);$r=Hari::load($p);
        eq(42,$r->age);eq('call_dad',$r->lexicon->resolve('ne','बाबुलाई फोन')['concept']??null);eq('Dad prefers slow instructions',$r->memory->recall(['dad','slow'])?->text);
        eq('slow',$r->habits->predict('dad:teaching')['choice']??null);ok($r->affect->export()['frustration']>0);eq('expert',$r->tools->choose('image')?->name);ok($r->plan('ne','बाबुलाई फोन')!==null);
    }finally{@unlink($p);}
};

$n=0;foreach($tests as $name=>$fn){try{$fn();$n++;echo "PASS  $name\n";}catch(Throwable $e){fwrite(STDERR,"FAIL  $name\n{$e->getMessage()}\n");exit(1);}}echo "\n$n/".count($tests)." passed\n";
function eq(mixed $a,mixed $b):void{if($a!==$b)throw new RuntimeException('expected '.var_export($a,true).' got '.var_export($b,true));}
function ok(bool $x):void{if(!$x)throw new RuntimeException('assertion failed');}
