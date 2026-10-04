<?php

declare(strict_types=1);
require __DIR__.'/brain.php';
use Hari\Life\{Effect,HariBrain,SemanticFrame};

$b=new HariBrain();
function teach(HariBrain $b,string $u,string $f,string $e):void{$b->experience($u,SemanticFrame::parse($f),Effect::parse($e));printf("teach %-18s  -> %s\n",$u,$f);}

teach($b,'mako lumi mesa','MOVE object=ball destination=table','location.ball=table');
teach($b,'mako piko tara','MOVE object=cup destination=shelf','location.cup=shelf');
teach($b,'mako dara vora','MOVE object=book destination=box','location.book=box');
teach($b,'mako lumi tara','MOVE object=ball destination=shelf','location.ball=shelf');
teach($b,'mako piko vora','MOVE object=cup destination=box','location.cup=box');
teach($b,'mako dara mesa','MOVE object=book destination=table','location.book=table');

echo "\nNever demonstrated: mako lumi vora\n";
$x=$b->explain('mako lumi vora');echo json_encode($x,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";

echo "\nTeach negation once, then compose it elsewhere:\n";
$b->experience('sen mako piko mesa',SemanticFrame::parse('NOT MOVE object=cup destination=table'),null,true);
$x=$b->explain('sen mako dara tara');echo json_encode($x,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";

echo "\nUnknown language:\n";
$x=$b->explain('zog completely unknown');echo json_encode($x,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
