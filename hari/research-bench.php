<?php

declare(strict_types=1);

require __DIR__.'/core.php';

use Hari\Habits;

/*
 * This is intentionally a cheap falsification harness, not a claim of intelligence.
 * It measures one narrow property: how fast a preference mechanism adapts after
 * a stable preference changes.
 */

$old='whatsapp';
$new='viber';
$history=30;

$hari=new Habits();
$lifetime=[$old=>$history,$new=>0];
$recent=array_fill(0,5,$old);

for($i=0;$i<$history;$i++)$hari->observe('mom:video',$old);

$hariLag=null;
$lifetimeLag=null;
$recentLag=null;

for($step=1;$step<=40;$step++){
    $hari->observe('mom:video',$new);
    $lifetime[$new]++;
    array_shift($recent);$recent[]=$new;

    if($hariLag===null&&($hari->predict('mom:video')['choice']??null)===$new)$hariLag=$step;

    arsort($lifetime);
    if($lifetimeLag===null&&array_key_first($lifetime)===$new)$lifetimeLag=$step;

    $counts=array_count_values($recent);arsort($counts);
    if($recentLag===null&&array_key_first($counts)===$new)$recentLag=$step;
}

$result=[
    'stable_observations'=>$history,
    'preference_change'=>"{$old} -> {$new}",
    'adaptation_lag'=>[
        'hari_decay'=>$hariLag,
        'lifetime_majority'=>$lifetimeLag,
        'recent_5_majority'=>$recentLag,
    ],
];

echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";

if($hariLag===null||$lifetimeLag===null||$recentLag===null)throw new RuntimeException('benchmark did not converge');
if($hariLag>=$lifetimeLag)throw new RuntimeException('adaptive memory failed to beat lifetime counting on drift');
