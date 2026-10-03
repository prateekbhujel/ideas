<?php

declare(strict_types=1);

$root=__DIR__;
$state=sys_get_temp_dir().'/hari-cli-'.bin2hex(random_bytes(4)).'.json';

try{
    run(['op'=>'teach','language'=>'ne','phrase'=>'देखिने फोन','concept'=>'video_call']);
    run(['op'=>'remember','text'=>'Mom calls this a video phone','cues'=>['mom','video phone'],'importance'=>.9]);

    $a=[
        ['op'=>'FIND_CONTACT','args'=>['person'=>'Pratik'],'confidence'=>.95,'risk'=>0],
        ['op'=>'VIDEO_CALL','args'=>['person'=>'Pratik'],'confidence'=>.95,'risk'=>2],
    ];
    $b=[
        ['op'=>'FIND_CONTACT','args'=>['person'=>'Mom'],'confidence'=>.95,'risk'=>0],
        ['op'=>'VIDEO_CALL','args'=>['person'=>'Mom'],'confidence'=>.95,'risk'=>2],
    ];

    $first=run(['op'=>'demonstrate_skill','concept'=>'video_call','slots'=>['person'=>'Pratik'],'actions'=>$a]);
    if($first['result']['learned']!==false)fail('skill learned from only one demo');
    $second=run(['op'=>'demonstrate_skill','concept'=>'video_call','slots'=>['person'=>'Mom'],'actions'=>$b]);
    if($second['result']['learned']!==true)fail('skill was not induced');

    $plan=run(['op'=>'plan','language'=>'ne','phrase'=>'देखिने फोन','slots'=>['person'=>'Dad']]);
    if(($plan['result'][1]['op']??null)!=='VIDEO_CALL'||($plan['result'][1]['args']['person']??null)!=='Dad')fail('generalized plan failed');

    $u1=run(['op'=>'demonstrate_utterance','language'=>'ne','utterance'=>'प्रतीकलाई देखिने फोन गर','concept'=>'video_call','slots'=>['person'=>'प्रतीक']]);
    if(($u1['result']['learned']??null)!==false)fail('utterance learned too early');
    $u2=run(['op'=>'demonstrate_utterance','language'=>'ne','utterance'=>'आमालाई देखिने फोन गर','concept'=>'video_call','slots'=>['person'=>'आमा']]);
    if(($u2['result']['learned']??null)!==true)fail('utterance pattern was not learned');
    $natural=run(['op'=>'plan_utterance','language'=>'ne','utterance'=>'बुबालाई देखिने फोन गर']);
    if(($natural['result'][1]['args']['person']??null)!=='बुबा')fail('utterance slot extraction failed');

    run(['op'=>'tool_add','name'=>'local','capabilities'=>['image'],'cost'=>0,'reliability'=>.75,'local'=>true]);
    run(['op'=>'tool_add','name'=>'expert','capabilities'=>['image'],'cost'=>.5,'reliability'=>.90,'local'=>false]);
    for($i=0;$i<6;$i++){run(['op'=>'tool_record','name'=>'local','success'=>false]);run(['op'=>'tool_record','name'=>'expert','success'=>true]);}
    $tool=run(['op'=>'tool_choose','capability'=>'image']);
    if(($tool['result']['name']??null)!=='expert')fail('tool experience did not persist');

    run(['op'=>'affect','event'=>'corrected']);
    run(['op'=>'age','ticks'=>100]);
    $status=run(['op'=>'status']);
    if(($status['result']['age']??null)!==100)fail('age lost');
    if(($status['result']['language_entries']??0)<1||($status['result']['memories']??0)<2||($status['result']['skills']??0)<1)fail('state counts wrong');

    $recall=run(['op'=>'recall','cues'=>['mom','video phone']]);
    if(($recall['result']['text']??null)!=='Mom calls this a video phone')fail('memory recall failed');

    echo "PASS  persistent JSON protocol\n";
}finally{@unlink($state);}

function run(array $command):array
{
    global $root,$state;
    $json=json_encode($command,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/cli.php').' '.escapeshellarg($state).' '.escapeshellarg($json);
    exec($cmd,$lines,$code);
    $raw=implode("\n",$lines);
    $data=json_decode($raw,true);
    if($code!==0||!is_array($data)||($data['ok']??false)!==true)fail("command failed: {$raw}");
    return $data;
}

function fail(string $message):never
{
    throw new RuntimeException($message);
}
