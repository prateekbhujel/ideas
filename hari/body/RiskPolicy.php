<?php

declare(strict_types=1);

namespace Hari\Body;

/**
 * HARI Action Risk Policy in PHP.
 * Classifies actions into:
 * - READ / OBSERVE
 * - REVERSIBLE ACTION
 * - EXTERNAL / CONSEQUENTIAL ACTION
 */
class RiskPolicy
{
    public static function assessRisk(array $plan): array
    {
        $atype = strtoupper($plan['action_type'] ?? 'OBSERVE');

        if (in_array($atype, ['OBSERVE', 'READ', 'QUERY'], true)) {
            return ['level' => 'READ', 'needs_confirmation' => false];
        }

        if (in_array($atype, ['OPEN_APP', 'FOCUS_WINDOW', 'MOVE_WINDOW', 'MOVE_POINTER'], true)) {
            return ['level' => 'REVERSIBLE', 'needs_confirmation' => false];
        }

        if ($atype === 'TYPE') {
            $text = strtolower($plan['params']['text'] ?? '');
            $pressEnter = (bool)($plan['params']['press_enter'] ?? false);
            if ($pressEnter || preg_match('/(?:rm |sudo|curl |bash|kill|git push|rmdir)/i', $text)) {
                return ['level' => 'CONSEQUENTIAL', 'needs_confirmation' => true];
            }
            return ['level' => 'REVERSIBLE', 'needs_confirmation' => false];
        }

        return ['level' => 'CONSEQUENTIAL', 'needs_confirmation' => true];
    }
}
