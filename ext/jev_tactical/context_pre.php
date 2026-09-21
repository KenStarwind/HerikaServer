<?php
/**
 * Jev Tactical Layer - prompt hook.
 *
 * main.php loads this right before the system prompt is assembled. When the NPC has a standing
 * goal, a <tactical_goal> section is appended to PROMPT_NEARBY_SECTIONS so the main model knows
 * what its "hands" are currently doing, why it was woken up (if the tactical layer escalated)
 * and how to change or end the goal.
 */

if (empty($GLOBALS["JEV_TACTICAL_ENABLED"])) {
    return;
}

$jevCtxNpc = (string)($GLOBALS["HERIKA_NAME"] ?? "");
if ($jevCtxNpc === "" || $jevCtxNpc === "The Narrator" || $jevCtxNpc === "(actor)") {
    return;
}

$jevCtxMaster = new NpcMaster();
$jevCtxData   = $jevCtxMaster->getByName($jevCtxNpc);
if (!$jevCtxData) {
    return;
}

$jevCtxGoal    = jev_tactical_get_goal($jevCtxData, $jevCtxMaster);
$jevCtxSection = "";

if ($jevCtxGoal !== null) {
    $jevCtxSection .= "\n<tactical_goal>\n# STANDING GOAL\n";
    $jevCtxSection .= "A fast tactical layer carries this goal out moment-to-moment (movement, targets, follow, wait). {$jevCtxNpc} does not need to micro-manage it.\n";
    $jevCtxSection .= "## Goal: {$jevCtxGoal["goal"]}\n";
    if (!empty($jevCtxGoal["rules"])) {
        $jevCtxSection .= "## Rules: {$jevCtxGoal["rules"]}\n";
    }
    if (!empty($jevCtxGoal["last_decision"])) {
        $jevCtxSection .= "## Last tactical decision: {$jevCtxGoal["last_decision"]}\n";
    }
    if (!empty($jevCtxGoal["last_result"])) {
        $jevCtxSection .= "## Last action result: {$jevCtxGoal["last_result"]}\n";
    }

    $jevCtxEscalation = $GLOBALS["JEV_ESCALATION"] ?? (is_array($jevCtxGoal["escalation"] ?? null) ? $jevCtxGoal["escalation"] : null);
    if (is_array($jevCtxEscalation) && !empty($jevCtxEscalation["reason"])) {
        $jevCtxSection .= "## ATTENTION: the tactical layer handed control back to {$jevCtxNpc} ({$jevCtxEscalation["reason"]}). Decide what to do: talk, keep the goal, or set a new one.\n";
        // The note is delivered once.
        jev_tactical_update_goal($jevCtxData, $jevCtxMaster, function (array $g): array {
            $g["escalation"] = null;
            return $g;
        });
    }

    $jevCtxSection .= "## Use the action SetTacticalGoal to replace the goal, ClearTacticalGoal to end it.\n";
    $jevCtxSection .= "</tactical_goal>";
}

if ($jevCtxSection !== "") {
    if (!isset($GLOBALS["PROMPT_NEARBY_SECTIONS"])) {
        $GLOBALS["PROMPT_NEARBY_SECTIONS"] = "";
    }
    $GLOBALS["PROMPT_NEARBY_SECTIONS"] .= $jevCtxSection;
}
