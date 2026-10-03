<?php

declare(strict_types=1);

require __DIR__.'/core.php';
require __DIR__.'/dialogue.php';

use Hari\{ClaimScope,ClaimStatus,CommonGround,GroundingMove,GroundingPolicy,MeaningCandidate,Risk,SpeechAct};

$tests=[];

$tests['ambiguous reference causes a question instead of a guess']=function():void{
    $g=new CommonGround();$p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Request,'call',['person'=>null],.97,['person']);
    eq(GroundingMove::Ask,$p->decide($m,Risk::External,$g)->move);
};

$tests['heated response after our failure triggers repair not stubborn execution']=function():void{
    $g=new CommonGround();$p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Request,'call',['person'=>'Mom'],.99);
    $d=$p->decide($m,Risk::External,$g,.88,true);
    eq(GroundingMove::Repair,$d->move);
};

$tests['heated tone alone does not rewrite a known fact']=function():void{
    $g=new CommonGround();
    $x=$g->correct('preferred.video_app','whatsapp',ClaimScope::Persistent);
    $p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Request,'video_call',['person'=>'Mom'],.99);
    eq(GroundingMove::Execute,$p->decide($m,Risk::External,$g,.80,false)->move);
    eq('whatsapp',$g->resolve('preferred.video_app')?->value);
    eq(ClaimStatus::Grounded,$x->status);
};

$tests['explicit rejection is a repair act even when words were otherwise confident']=function():void{
    $g=new CommonGround();$p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Reject,'call',['person'=>'Mom'],1.0);
    eq(GroundingMove::Repair,$p->decide($m,Risk::External,$g)->move);
};

$tests['prohibition never becomes a side effect']=function():void{
    $g=new CommonGround();$p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Prohibit,'call',['person'=>'Mom'],1.0);
    eq(GroundingMove::Acknowledge,$p->decide($m,Risk::External,$g)->move);
};

$tests['risky request asks when an assumption is not mutually grounded']=function():void{
    $g=new CommonGround();$p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Request,'send_message',['person'=>'Mom'],.99,[],['contact.mom']);
    $d=$p->decide($m,Risk::External,$g);
    eq(GroundingMove::Ask,$d->move);
    eq(['contact.mom'],$d->missing);

    $claim=$g->propose('contact.mom','+9779800000000',.95,ClaimScope::Persistent,'user');
    $g->confirm($claim->id);
    eq(GroundingMove::Execute,$p->decide($m,Risk::External,$g)->move);
};

$tests['temporary exception does not destroy stable preference']=function():void{
    $g=new CommonGround();
    $g->correct('preferred.video_app','whatsapp',ClaimScope::Persistent,'user');
    $g->correct('preferred.video_app','viber',ClaimScope::Session,'user','just for today');

    eq('viber',$g->resolve('preferred.video_app')?->value);
    $g->clearSession();
    eq('whatsapp',$g->resolve('preferred.video_app')?->value);
};

$tests['persistent correction supersedes old persistent belief but keeps history']=function():void{
    $g=new CommonGround();
    $old=$g->correct('preferred.video_app','viber',ClaimScope::Persistent,'user');
    $new=$g->correct('preferred.video_app','whatsapp',ClaimScope::Persistent,'user','changed preference');

    eq(ClaimStatus::Superseded,$old->status);
    eq(ClaimStatus::Grounded,$new->status);
    eq('whatsapp',$g->resolve('preferred.video_app')?->value);
    eq(2,count($g->history('preferred.video_app')));
};

$tests['tentative inference is not common ground until confirmed']=function():void{
    $g=new CommonGround();
    $claim=$g->propose('relation.krishna','maternal_uncle',.82,ClaimScope::Persistent,'inference');
    eq(null,$g->resolve('relation.krishna'));
    $g->confirm($claim->id);
    eq('maternal_uncle',$g->resolve('relation.krishna')?->value);
};

$tests['high confidence still cannot execute an informing statement']=function():void{
    $g=new CommonGround();$p=new GroundingPolicy();
    $m=new MeaningCandidate(SpeechAct::Inform,'weather',['state'=>'raining'],1.0);
    eq(GroundingMove::Acknowledge,$p->decide($m,Risk::Read,$g)->move);
};

$n=0;
foreach($tests as $name=>$fn){
    try{$fn();++$n;echo "PASS  {$name}\n";}
    catch(Throwable $e){fwrite(STDERR,"FAIL  {$name}\n{$e->getMessage()}\n");exit(1);}
}
echo "\n{$n}/".count($tests)." passed\n";

function eq(mixed $expected,mixed $actual):void
{
    if($expected!==$actual)throw new RuntimeException('expected '.var_export($expected,true).' got '.var_export($actual,true));
}
