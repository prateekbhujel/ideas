<?php

declare(strict_types=1);
require __DIR__.'/brain.php';

use Hari\Life\{Effect,HariBrain,SemanticFrame};

$state=$argv[1]??(__DIR__.'/hari-life-state.json');
$brain=is_file($state)?HariBrain::load($state):new HariBrain();

echo "HARI Life MVP\n";
echo "No transformer, pretrained model, or training job. Learning happens on each experience.\n";
echo "Type help for commands. State: {$state}\n\n";

while(true){
    echo 'hari> ';
    $line=fgets(STDIN);if($line===false)break;$line=trim($line);if($line==='')continue;
    try{
        if($line==='quit'||$line==='exit'){break;}
        if($line==='help'){help();continue;}
        if($line==='stats'){printJson($brain->resourceStats());continue;}
        if($line==='sleep'){printJson($brain->sleep());$brain->save($state);continue;}
        if($line==='save'){$brain->save($state);echo "saved\n";continue;}
        if(str_starts_with($line,'fact ')){
            $body=substr($line,5);if(!str_contains($body,'='))throw new RuntimeException('use: fact key=value');[$k,$v]=explode('=',$body,2);$brain->rememberFact(trim($k),trim($v));$brain->save($state);echo "remembered\n";continue;
        }
        if(str_starts_with($line,'recall ')){
            $k=trim(substr($line,7));$v=$brain->recallFact($k);echo $v===null?"unknown\n":"{$k} = {$v}\n";continue;
        }
        if(str_starts_with($line,'why ')){printJson($brain->explain(trim(substr($line,4))));continue;}
        if(str_starts_with($line,'teach ')||str_starts_with($line,'correct ')){
            $correction=str_starts_with($line,'correct ');$body=substr($line,$correction?8:6);
            if(!str_contains($body,'=>'))throw new RuntimeException('use: teach utterance => FRAME [| effect=value]');
            [$utterance,$rhs]=array_map('trim',explode('=>',$body,2));
            $effect=null;if(str_contains($rhs,'|')){[$frameText,$effectText]=array_map('trim',explode('|',$rhs,2));$effect=Effect::parse($effectText);}else{$frameText=$rhs;}
            $result=$brain->experience($utterance,SemanticFrame::parse($frameText),$effect,$correction);$brain->save($state);
            printf("learned (surprise %.2f)\n",$result['surprise']);continue;
        }

        $i=$brain->infer($line);
        if($i->shouldAsk){echo "ASK: {$i->question}\n";continue;}
        echo 'ACT: '.$i->frame?->canonical().sprintf("  [confidence %.3f]\n",$i->confidence);
        if($i->frame){$p=$brain->predictEffect($i->frame);if($p['effect'])echo 'PREDICT: '.$p['effect']->canonical().sprintf("  [confidence %.3f]\n",$p['confidence']);}
    }catch(Throwable $e){echo 'ERROR: '.$e->getMessage()."\n";}
}
$brain->save($state);echo "saved; bye\n";

function help():void{
    echo <<<'TXT'
Commands:
  teach <utterance> => <FRAME> [| key=value]
  correct <utterance> => <FRAME> [| key=value]
  why <utterance>
  fact <key>=<value>
  recall <key>
  stats
  sleep
  save
  quit

FRAME examples:
  MOVE object=ball destination=table
  NOT MOVE object=ball destination=table
  PAINT object=ball color=red

Example teaching session:
  teach mako lumi mesa => MOVE object=ball destination=table | location.ball=table
  teach mako piko tara => MOVE object=cup destination=shelf | location.cup=shelf
  teach mako lumi tara => MOVE object=ball destination=shelf | location.ball=shelf
  teach mako piko mesa => MOVE object=cup destination=table | location.cup=table
  mako lumi mesa
  why mako lumi mesa

TXT;
}
function printJson(mixed $x):void{echo json_encode($x,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";}
