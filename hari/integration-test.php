<?php

declare(strict_types=1);

require __DIR__.'/core.php';
require __DIR__.'/device.php';

use Hari\{Action,DeviceKind,DeviceMesh,DeviceNode,DeviceRuntime,Hari,MemoryDeviceDriver,Risk};

$hari=new Hari();

$hari->demonstrateSkill('video_call',['person'=>'Pratik'],[
    new Action('FIND_CONTACT',['person'=>'Pratik'],.97,Risk::Read),
    new Action('VIDEO_CALL',['person'=>'Pratik'],.97,Risk::External),
]);
$hari->demonstrateSkill('video_call',['person'=>'Mom'],[
    new Action('FIND_CONTACT',['person'=>'Mom'],.97,Risk::Read),
    new Action('VIDEO_CALL',['person'=>'Mom'],.97,Risk::External),
]);

$hari->demonstrateUtterance('ne','प्रतीकलाई देखिने फोन गर','video_call',['person'=>'प्रतीक']);
$hari->demonstrateUtterance('ne','आमालाई देखिने फोन गर','video_call',['person'=>'आमा']);

$plan=$hari->planUtterance('ne','बुबालाई देखिने फोन गर')
    ?? throw new RuntimeException('HARI did not understand the learned sentence structure');

$mesh=new DeviceMesh();
$mesh->add(new DeviceNode('phone',DeviceKind::Phone,['FIND_CONTACT','VIDEO_CALL'],priority:5));
$mesh->add(new DeviceNode('watch',DeviceKind::Watch,['SHOW_NOTIFICATION'],priority:10));

$phone=new MemoryDeviceDriver('phone');
$watch=new MemoryDeviceDriver('watch');
$runtime=new DeviceRuntime($mesh,[$phone,$watch]);

$blocked=$runtime->run($plan);
if(($blocked['why']??null)!=='confirm'||count($phone->received())!==0){
    throw new RuntimeException('external action was not stopped at confirmation');
}

$done=$runtime->run($plan,[1]);
if(!$done['ok']||count($phone->received())!==2){
    throw new RuntimeException('confirmed phone execution failed');
}
if(($phone->received()[1]->args['person']??null)!=='बुबा'){
    throw new RuntimeException('learned Nepali slot was not preserved into machine action');
}
if(count($watch->received())!==0){
    throw new RuntimeException('action routed to wrong body');
}

$state=sys_get_temp_dir().'/hari-integration-'.bin2hex(random_bytes(4)).'.json';
try{
    $hari->tick(90);$hari->save($state);
    $restored=Hari::load($state);
    $next=$restored->planUtterance('ne','दाइलाई देखिने फोन गर');
    if(($next[1]->args['person']??null)!=='दाइ'||$restored->age!==90){
        throw new RuntimeException('learned language or life state did not survive restart');
    }
}finally{@unlink($state);}

echo "PASS  Nepali -> learned reasoning -> verified phone body\n";
