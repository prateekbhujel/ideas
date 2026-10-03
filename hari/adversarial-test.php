<?php

declare(strict_types=1);

require __DIR__.'/core.php';
require __DIR__.'/device.php';

use Hari\{Action,DeviceKind,DeviceMesh,DeviceNode,DeviceRuntime,Hari,MemoryDeviceDriver,Risk};

$tests=[];

$tests['negated wording cannot ride fuzzy similarity into execution']=function():void{
    $h=new Hari();
    $h->teach('en','call','call');
    $h->teachSkill('call',[new Action('CALL',['person'=>'{person}'],.99,Risk::External)]);

    if($h->lexicon->resolve('en','do not call')!=='call'){
        throw new RuntimeException('fixture no longer exercises fuzzy collision');
    }

    eq(null,$h->plan('en','do not call',['person'=>'Mom']));
};

$tests['explicit correction supersedes the executable skill']=function():void{
    $h=new Hari();
    $h->teach('en','video call','video_call');

    $h->teachSkill('video_call',[
        new Action('VIDEO_CALL',['person'=>'{person}','provider'=>'viber'],.99,Risk::External),
    ]);
    eq('viber',$h->plan('en','video call',['person'=>'Mom'])[0]->args['provider']??null);

    $h->correctSkill('video_call',[
        new Action('VIDEO_CALL',['person'=>'{person}','provider'=>'whatsapp'],.99,Risk::External),
    ],'user changed preferred calling app');

    $plan=$h->plan('en','video call',['person'=>'Mom']);
    eq('whatsapp',$plan[0]->args['provider']??null);
    eq('skill correction: video_call: user changed preferred calling app',$h->memory->recall(['skill','video_call','correction'],false)?->text);
};

$tests['a successful lookup with no contact blocks the call']=function():void{
    $mesh=new DeviceMesh();
    $mesh->add(new DeviceNode('phone',DeviceKind::Phone,['FIND_CONTACT','CALL'],priority:1));

    $phone=(new MemoryDeviceDriver('phone'))
        ->when('FIND_CONTACT',true,['found'=>false]);

    $runtime=new DeviceRuntime($mesh,[$phone]);
    $plan=[
        new Action('FIND_CONTACT',['person'=>'Ghost'],.99,Risk::Read),
        new Action('CALL',['person'=>'Ghost'],.99,Risk::External,[
            ['step'=>0,'field'=>'found','equals'=>true],
        ]),
    ];

    $result=$runtime->run($plan,[1]);
    eq('dependency',$result['why']);
    eq(1,count($phone->received()));
    eq('FIND_CONTACT',$phone->received()[0]->op);
};

$tests['the same dependency permits execution when evidence is present']=function():void{
    $mesh=new DeviceMesh();
    $mesh->add(new DeviceNode('phone',DeviceKind::Phone,['FIND_CONTACT','CALL'],priority:1));

    $phone=(new MemoryDeviceDriver('phone'))
        ->when('FIND_CONTACT',true,['found'=>true]);

    $runtime=new DeviceRuntime($mesh,[$phone]);
    $plan=[
        new Action('FIND_CONTACT',['person'=>'Mom'],.99,Risk::Read),
        new Action('CALL',['person'=>'Mom'],.99,Risk::External,[
            ['step'=>0,'field'=>'found','equals'=>true],
        ]),
    ];

    $result=$runtime->run($plan,[1]);
    ok($result['ok']);
    eq(2,count($phone->received()));
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

function ok(bool $value):void
{
    if(!$value)throw new RuntimeException('assertion failed');
}
