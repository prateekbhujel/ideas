<?php
declare(strict_types=1);
namespace Hari;
enum ResponseMode:string{case Normal='normal';case Clarify='clarify';case Repair='repair';case ComfortRepair='comfort_repair';case Report='report';case Investigate='investigate';}
final readonly class InteractionSignals{
    public function __construct(public float $friction=0.0,public bool $systemMadeMistake=false,public bool $userRejected=false,public bool $userAskedWhy=false,public bool $distressPossible=false){
        if($friction<0||$friction>1)throw new \InvalidArgumentException('friction must be between 0 and 1');
    }
}
final readonly class ResponsePlan{
    /** @param list<string> $steps */
    public function __construct(public ResponseMode $mode,public bool $stopCurrentAction,public bool $acknowledgeOwnMistake,public bool $avoidEmotionClaim,public string $tone,public string $pace,public string $length,public array $steps){}
}
final class HumanResponsePolicy{
    public function plan(GroundingDecision $g,InteractionSignals $s,?WorldAnswer $world=null):ResponsePlan{
        if($world!==null&&$world->kind==='unreachable'){
            return new ResponsePlan($world->needsInvestigation?ResponseMode::Investigate:ResponseMode::Report,true,false,true,'calm','normal','short',$world->needsInvestigation?['state_observed_fact','say_cause_unknown','offer_or_begin_safe_investigation']:['state_observed_fact','state_known_cause']);
        }
        if($g->move===GroundingMove::Repair||$s->userRejected||($s->systemMadeMistake&&$s->friction>=.35)){
            $comfort=$s->friction>=.65||$s->distressPossible;
            return new ResponsePlan($comfort?ResponseMode::ComfortRepair:ResponseMode::Repair,true,$s->systemMadeMistake,true,$comfort?'warm_and_steady':'calm',$comfort?'slower':'normal','short',['stop_and_do_not_repeat','acknowledge_error_without_defending','reflect_only_observed_problem','ask_one_minimal_repair_question_if_needed']);
        }
        if($g->move===GroundingMove::Ask){
            return new ResponsePlan(ResponseMode::Clarify,true,false,true,'neutral_warm','normal','short',['state_uncertainty_briefly','ask_one_question_that_changes_the_action']);
        }
        return new ResponsePlan(ResponseMode::Normal,false,false,true,'natural','normal','adaptive',['respond_to_current_goal']);
    }
}