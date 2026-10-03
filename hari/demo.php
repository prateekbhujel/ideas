<?php

declare(strict_types=1);
require __DIR__.'/core.php';

use Hari\{Action,Executor,Hari,Risk};

$hari=new Hari();
$hari->teach('ne','देखिने फोन','video_call');

$hari->demonstrateSkill('video_call',['person'=>'Pratik'],[
    new Action('FIND_CONTACT',['person'=>'Pratik'],.95,Risk::Read),
    new Action('VIDEO_CALL',['person'=>'Pratik'],.95,Risk::External),
]);
$hari->demonstrateSkill('video_call',['person'=>'Mom'],[
    new Action('FIND_CONTACT',['person'=>'Mom'],.95,Risk::Read),
    new Action('VIDEO_CALL',['person'=>'Mom'],.95,Risk::External),
]);

$plan=$hari->plan('ne','देखिने फोन',['person'=>'Dad']) ?? throw new RuntimeException('not learned');
$executor=new Executor();
$first=$executor->run($plan);
printf("first=%s at=%s\n",$first['why'],(string)$first['at']);
$second=$executor->run($plan,[1]);
printf("confirmed=%s actions=%d\n",$second['ok']?'yes':'no',count($executor->done()));

$state=sys_get_temp_dir().'/hari-demo.state.json';
$hari->save($state);
$reloaded=Hari::load($state);
@unlink($state);
$again=$reloaded->plan('ne','देखिने फोन',['person'=>'Dad']);
printf("restart=%s\n",$again!==null?'remembered':'forgot');
