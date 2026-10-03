<?php

declare(strict_types=1);
require __DIR__.'/core.php';

use Hari\{Action,Hari,Risk,Tool};

$state=$argv[1]??null;
if($state===null){
    fwrite(STDERR,"usage: php hari/cli.php <state-file> [json-command]\n");
    exit(64);
}

$raw=$argv[2]??stream_get_contents(STDIN);
if(trim((string)$raw)===''){
    fwrite(STDERR,"missing JSON command\n");
    exit(64);
}

try{
    $command=json_decode((string)$raw,true,flags:JSON_THROW_ON_ERROR);
    if(!is_array($command))throw new InvalidArgumentException('command must be a JSON object');

    $hari=Hari::load($state);
    $op=(string)($command['op']??'');
    $result=match($op){
        'teach'=>teach($hari,$command),
        'remember'=>remember($hari,$command),
        'recall'=>recall($hari,$command),
        'age'=>ageHari($hari,$command),
        'demonstrate_skill'=>demonstrateSkill($hari,$command),
        'plan'=>plan($hari,$command),
        'tool_add'=>toolAdd($hari,$command),
        'tool_record'=>toolRecord($hari,$command),
        'tool_choose'=>toolChoose($hari,$command),
        'affect'=>affect($hari,$command),
        'status'=>status($hari),
        default=>throw new InvalidArgumentException("unknown op: {$op}"),
    };

    $hari->save($state);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    exit(1);
}

/** @param array<string,mixed> $c */
function teach(Hari $h,array $c):array
{
    $language=requiredString($c,'language');$phrase=requiredString($c,'phrase');$concept=requiredString($c,'concept');
    $h->teach($language,$phrase,$concept);
    return ['language'=>$language,'phrase'=>$phrase,'concept'=>$concept];
}

/** @param array<string,mixed> $c */
function remember(Hari $h,array $c):array
{
    $text=requiredString($c,'text');
    $cues=stringList($c['cues']??[]);
    $importance=(float)($c['importance']??.5);
    $m=$h->memory->remember($text,$cues,$importance);
    return ['id'=>$m->id,'text'=>$m->text];
}

/** @param array<string,mixed> $c */
function recall(Hari $h,array $c):?array
{
    $m=$h->memory->recall(stringList($c['cues']??[]),(bool)($c['reinforce']??true));
    return $m===null?null:['id'=>$m->id,'text'=>$m->text,'strength'=>$m->strength,'importance'=>$m->importance,'hits'=>$m->hits];
}

/** @param array<string,mixed> $c */
function ageHari(Hari $h,array $c):array
{
    $ticks=(int)($c['ticks']??1);$h->tick($ticks);return ['age'=>$h->age];
}

/** @param array<string,mixed> $c */
function demonstrateSkill(Hari $h,array $c):array
{
    $concept=requiredString($c,'concept');
    $slots=is_array($c['slots']??null)?$c['slots']:[];
    $rows=$c['actions']??[];
    if(!is_array($rows)||$rows===[])throw new InvalidArgumentException('actions required');
    $actions=array_map('actionFromArray',$rows);
    return ['learned'=>$h->demonstrateSkill($concept,$slots,$actions)];
}

/** @param array<string,mixed> $c */
function plan(Hari $h,array $c):?array
{
    $language=requiredString($c,'language');$phrase=requiredString($c,'phrase');
    $slots=is_array($c['slots']??null)?$c['slots']:[];
    $actions=$h->plan($language,$phrase,$slots);
    return $actions===null?null:array_map('actionToArray',$actions);
}

/** @param array<string,mixed> $c */
function toolAdd(Hari $h,array $c):array
{
    $name=requiredString($c,'name');$caps=stringList($c['capabilities']??[]);
    if($caps===[])throw new InvalidArgumentException('capabilities required');
    $h->tools->add(new Tool($name,$caps,(float)($c['cost']??0),(float)($c['reliability']??.5),(bool)($c['local']??false)));
    return ['name'=>$name];
}

/** @param array<string,mixed> $c */
function toolRecord(Hari $h,array $c):array
{
    $name=requiredString($c,'name');$h->tools->record($name,(bool)($c['success']??false));return ['name'=>$name];
}

/** @param array<string,mixed> $c */
function toolChoose(Hari $h,array $c):?array
{
    $tool=$h->tools->choose(requiredString($c,'capability'));
    return $tool===null?null:['name'=>$tool->name,'cost'=>$tool->cost,'reliability'=>$tool->reliability,'local'=>$tool->local];
}

/** @param array<string,mixed> $c */
function affect(Hari $h,array $c):array
{
    $event=requiredString($c,'event');
    match($event){
        'corrected'=>$h->affect->corrected(),
        'succeeded'=>$h->affect->succeeded(),
        'rest'=>$h->affect->rest((float)($c['amount']??.1)),
        default=>throw new InvalidArgumentException("unknown affect event: {$event}"),
    };
    return $h->affect->export();
}

function status(Hari $h):array
{
    return [
        'age'=>$h->age,
        'affect'=>$h->affect->export(),
        'language_entries'=>count($h->lexicon->export()),
        'memories'=>count($h->memory->export()['items']),
        'habits'=>count($h->habits->export()),
        'skills'=>count($h->skills->export()),
        'tools'=>count($h->tools->export()['tools']),
    ];
}

/** @param array<string,mixed> $row */
function actionFromArray(array $row):Action
{
    $risk=Risk::from((int)($row['risk']??0));
    $args=is_array($row['args']??null)?$row['args']:[];
    return new Action(requiredString($row,'op'),$args,(float)($row['confidence']??1),$risk);
}

function actionToArray(Action $a):array
{
    return ['op'=>$a->op,'args'=>$a->args,'confidence'=>$a->confidence,'risk'=>$a->risk->value];
}

/** @param array<string,mixed> $row */
function requiredString(array $row,string $key):string
{
    $value=trim((string)($row[$key]??''));
    if($value==='')throw new InvalidArgumentException("{$key} required");
    return $value;
}

/** @return list<string> */
function stringList(mixed $value):array
{
    if(!is_array($value))throw new InvalidArgumentException('expected string list');
    return array_values(array_map('strval',$value));
}
