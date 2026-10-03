<?php
declare(strict_types=1);
namespace Hari;
enum Availability:string{case Online='online';case Asleep='asleep';case Offline='offline';case Unknown='unknown';}
final readonly class PresenceObservation{
    public function __construct(public string $device,public Availability $availability,public int $observedAt,public string $source,public ?string $evidence=null,public ?string $knownCause=null){
        if($device==='')throw new \InvalidArgumentException('device required');
        if($source==='')throw new \InvalidArgumentException('presence source required');
    }
}
final readonly class BodySelection{
    public function __construct(public string $device,public bool $reachable,public Availability $availability,public string $fact,public ?string $cause,public bool $causeKnown){}
}
final class PresenceBook{
    /** @var array<string,PresenceObservation> */ private array $latest=[];
    public function observe(PresenceObservation $o):void{$c=$this->latest[$o->device]??null;if($c!==null&&$o->observedAt<$c->observedAt)return;$this->latest[$o->device]=$o;}
    public function get(string $device):?PresenceObservation{return $this->latest[$device]??null;}
    public function select(string $device):BodySelection{
        $o=$this->latest[$device]??null;
        if($o===null)return new BodySelection($device,false,Availability::Unknown,'no presence evidence',null,false);
        $reachable=$o->availability===Availability::Online;
        $fact=match($o->availability){Availability::Online=>'device is reachable',Availability::Asleep=>'device reports sleep/unavailable state',Availability::Offline=>'device is not reachable',Availability::Unknown=>'device state is unknown'};
        return new BodySelection($device,$reachable,$o->availability,$fact,$o->knownCause,$o->knownCause!==null);
    }
}
final class ConversationBody{
    public function __construct(private string $current='phone'){}
    public function use(string $device):void{if(trim($device)==='')throw new \InvalidArgumentException('body required');$this->current=trim($device);}
    public function current():string{return $this->current;}
}
final readonly class WorldAnswer{
    public function __construct(public string $kind,public string $fact,public ?string $cause=null,public bool $needsInvestigation=false){}
}
final class WorldInterpreter{
    public function explainBody(BodySelection $s,bool $askedWhy=false):WorldAnswer{
        if($s->reachable)return new WorldAnswer('reachable',$s->fact);
        if($askedWhy){
            if($s->causeKnown)return new WorldAnswer('unreachable',$s->fact,$s->cause,false);
            return new WorldAnswer('unreachable',$s->fact,null,true);
        }
        return new WorldAnswer('unreachable',$s->fact,$s->cause,false);
    }
}