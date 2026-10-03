<?php

declare(strict_types=1);
require __DIR__.'/core.php';

use Hari\{Action,Executor,Hari,Risk};

$hari=new Hari();

$hari->demonstrateSkill('video_call',['person'=>'Pratik'],[
    new Action('FIND_CONTACT',['person'=>'Pratik'],.95,Risk::Read),
    new Action('VIDEO_CALL',['person'=>'Pratik'],.95,Risk::External),
]);
$hari->demonstrateSkill('video_call',['person'=>'Mom'],[
    new Action('FIND_CONTACT',['person'=>'Mom'],.95,Risk::Read),
    new Action('VIDEO_CALL',['person'=>'Mom'],.95,Risk::External),
]);

$hari->demonstrateUtterance('ne','प्रतीकलाई देखिने फोन गर','video_call',['person'=>'प्रतीक']);
$hari->demonstrateUtterance('ne','आमालाई देखिने फोन गर','video_call',['person'=>'आमा']);

$plan=$hari->planUtterance('ne','बुबालाई देखिने फोन गर') ?? throw new RuntimeException('not learned');
printf("learned=%s person=%s\n",$plan[1]->op,(string)$plan[1]->args['person']);

$executor=new Executor();
$first=$executor->run($plan);
printf("first=%s at=%s executed=%d\n",$first['why'],(string)$first['at'],count($executor->done()));

$second=$executor->run($plan,[1]);
printf("confirmed=%s actions=%d\n",$second['ok']?'yes':'no',count($executor->done()));

$state=sys_get_temp_dir().'/hari-demo.state.json';
$hari->tick(30);
$hari->save($state);
$reloaded=Hari::load($state);
@unlink($state);
$again=$reloaded->planUtterance('ne','दाइलाई देखिने फोन गर');
printf("restart=%s age=%d person=%s\n",$again!==null?'remembered':'forgot',$reloaded->age,(string)($again[1]->args['person']??''));
