<?php

declare(strict_types=1);

namespace Hari;

enum SpeechAct: string
{
    case Request = 'request';
    case Inform = 'inform';
    case Confirm = 'confirm';
    case Reject = 'reject';
    case Correct = 'correct';
    case Prohibit = 'prohibit';
    case Ask = 'ask';
    case Unknown = 'unknown';
}

enum ClaimScope: string
{
    case Turn = 'turn';
    case Session = 'session';
    case Persistent = 'persistent';
}

enum ClaimStatus: string
{
    case Tentative = 'tentative';
    case Grounded = 'grounded';
    case Disputed = 'disputed';
    case Superseded = 'superseded';
}

final class GroundClaim
{
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public mixed $value,
        public float $confidence,
        public ClaimScope $scope,
        public ClaimStatus $status,
        public readonly string $source,
        public readonly int $createdAt,
        public ?string $reason = null,
    ) {}
}

final class CommonGround
{
    /** @var array<string,GroundClaim> */
    private array $claims = [];
    private int $clock = 0;
    private int $next = 1;

    public function tick(int $n=1): void
    {
        if($n<0)throw new \InvalidArgumentException('grounding clock cannot move backwards');
        $this->clock += $n;
    }

    public function propose(
        string $key,
        mixed $value,
        float $confidence,
        ClaimScope $scope,
        string $source='inference',
    ): GroundClaim {
        if($key==='')throw new \InvalidArgumentException('claim key required');
        if($confidence<0||$confidence>1)throw new \InvalidArgumentException('confidence must be between 0 and 1');

        $claim=new GroundClaim(
            'g'.$this->next++,
            $key,
            $value,
            $confidence,
            $scope,
            ClaimStatus::Tentative,
            $source,
            $this->clock,
        );
        $this->claims[$claim->id]=$claim;
        return $claim;
    }

    public function confirm(string $id): GroundClaim
    {
        $claim=$this->claim($id);
        $this->supersedeActive($claim->key,$claim->scope,$claim->id);
        $claim->status=ClaimStatus::Grounded;
        $claim->confidence=1.0;
        return $claim;
    }

    public function reject(string $id,string $reason='rejected by user'): GroundClaim
    {
        $claim=$this->claim($id);
        $claim->status=ClaimStatus::Disputed;
        $claim->reason=$reason;
        return $claim;
    }

    public function correct(
        string $key,
        mixed $value,
        ClaimScope $scope,
        string $source='user correction',
        string $reason='explicit correction',
    ): GroundClaim {
        foreach($this->claims as $claim){
            if($claim->key===$key&&$claim->status===ClaimStatus::Grounded){
                if($scope===ClaimScope::Persistent||$claim->scope===$scope){
                    $claim->status=ClaimStatus::Superseded;
                    $claim->reason=$reason;
                }
            }
        }

        $claim=$this->propose($key,$value,1.0,$scope,$source);
        $claim->status=ClaimStatus::Grounded;
        return $claim;
    }

    public function resolve(string $key,bool $includeTurn=true,bool $includeSession=true): ?GroundClaim
    {
        $rank=[
            ClaimScope::Persistent->value=>1,
            ClaimScope::Session->value=>2,
            ClaimScope::Turn->value=>3,
        ];

        $best=null;$bestRank=-1;$bestCreated=-1;
        foreach($this->claims as $claim){
            if($claim->key!==$key||$claim->status!==ClaimStatus::Grounded)continue;
            if(!$includeTurn&&$claim->scope===ClaimScope::Turn)continue;
            if(!$includeSession&&$claim->scope===ClaimScope::Session)continue;
            $r=$rank[$claim->scope->value];
            if($r>$bestRank||($r===$bestRank&&$claim->createdAt>$bestCreated)){
                $best=$claim;$bestRank=$r;$bestCreated=$claim->createdAt;
            }
        }
        return $best;
    }

    public function clearTurn(): void
    {
        foreach($this->claims as $claim){
            if($claim->scope===ClaimScope::Turn&&$claim->status===ClaimStatus::Grounded){
                $claim->status=ClaimStatus::Superseded;
                $claim->reason='turn ended';
            }
        }
    }

    public function clearSession(): void
    {
        foreach($this->claims as $claim){
            if(in_array($claim->scope,[ClaimScope::Turn,ClaimScope::Session],true)&&$claim->status===ClaimStatus::Grounded){
                $claim->status=ClaimStatus::Superseded;
                $claim->reason='session ended';
            }
        }
    }

    /** @return list<GroundClaim> */
    public function history(string $key): array
    {
        return array_values(array_filter($this->claims,fn(GroundClaim $c)=>$c->key===$key));
    }

    private function claim(string $id): GroundClaim
    {
        return $this->claims[$id]??throw new \OutOfBoundsException("unknown claim: {$id}");
    }

    private function supersedeActive(string $key,ClaimScope $scope,string $except):void
    {
        foreach($this->claims as $claim){
            if($claim->id!==$except&&$claim->key===$key&&$claim->scope===$scope&&$claim->status===ClaimStatus::Grounded){
                $claim->status=ClaimStatus::Superseded;
                $claim->reason='newer grounded claim';
            }
        }
    }
}

final readonly class MeaningCandidate
{
    /**
     * @param array<string,scalar|null> $slots
     * @param list<string> $unresolved
     * @param list<string> $assumptions
     */
    public function __construct(
        public SpeechAct $act,
        public ?string $intent,
        public array $slots,
        public float $confidence,
        public array $unresolved=[],
        public array $assumptions=[],
    ) {
        if($confidence<0||$confidence>1)throw new \InvalidArgumentException('confidence must be between 0 and 1');
    }
}

enum GroundingMove: string
{
    case Execute = 'execute';
    case Ask = 'ask';
    case Repair = 'repair';
    case Acknowledge = 'acknowledge';
    case Hold = 'hold';
}

final readonly class GroundingDecision
{
    /** @param list<string> $missing */
    public function __construct(
        public GroundingMove $move,
        public string $reason,
        public array $missing=[],
        public float $requiredConfidence=0.0,
    ) {}
}

final class GroundingPolicy
{
    public function decide(
        MeaningCandidate $meaning,
        Risk $risk,
        CommonGround $ground,
        float $friction=0.0,
        bool $previousSystemFailure=false,
    ): GroundingDecision {
        $friction=max(0.0,min(1.0,$friction));

        if($meaning->act===SpeechAct::Prohibit){
            return new GroundingDecision(GroundingMove::Acknowledge,'prohibition is not an executable request');
        }

        if(in_array($meaning->act,[SpeechAct::Reject,SpeechAct::Correct],true)){
            return new GroundingDecision(GroundingMove::Repair,'user challenged the current common ground');
        }

        if($meaning->act===SpeechAct::Unknown||$meaning->intent===null){
            return new GroundingDecision(GroundingMove::Ask,'meaning is unknown');
        }

        if($meaning->unresolved!==[]){
            return new GroundingDecision(GroundingMove::Ask,'unresolved reference',$meaning->unresolved);
        }

        $missing=[];
        foreach($meaning->assumptions as $key){
            if($ground->resolve($key)===null)$missing[]=$key;
        }
        if($missing!==[]){
            return new GroundingDecision(GroundingMove::Ask,'required assumption is not grounded',$missing);
        }

        if($previousSystemFailure&&$friction>=.35){
            return new GroundingDecision(GroundingMove::Repair,'friction after a system failure suggests common-ground break');
        }

        $threshold=match($risk){
            Risk::Read=>.72,
            Risk::Reversible=>.84,
            Risk::External=>.94,
        };

        // Tone/friction is weak evidence. It can increase caution, but it never
        // mutates facts or proves an emotion.
        if($friction>=.70)$threshold=min(.99,$threshold+.04);

        if($meaning->confidence<$threshold){
            return new GroundingDecision(GroundingMove::Ask,'semantic confidence is insufficient',requiredConfidence:$threshold);
        }

        if($meaning->act===SpeechAct::Inform){
            return new GroundingDecision(GroundingMove::Acknowledge,'information should update common ground, not execute');
        }

        if($meaning->act!==SpeechAct::Request){
            return new GroundingDecision(GroundingMove::Hold,'speech act does not authorize execution');
        }

        return new GroundingDecision(GroundingMove::Execute,'grounded enough for current purpose',requiredConfidence:$threshold);
    }
}
