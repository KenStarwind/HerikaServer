<?php
/**
 * Jev Tactical Layer - request hook.
 *
 * main.php loads this at the "prerequest" stage: the NPC profile is loaded, IS_NPC is known,
 * but no prompt has been built and no LLM has been called yet. On the request types listed in
 * JEV_TICK_TYPES (default: funcret, bored, jev_tick) and only while the NPC has a standing goal,
 * this hook asks Jev which CHIM action to run next and either
 *
 *   act      -> emits "Name|command|Code@param" into this response (same channel the LLM
 *               connectors use), records it in actions_issued / eventlog and ends the request,
 *   wait     -> ends the request without waking the main model,
 *   escalate -> lets the request continue into the normal LLM flow with an escalation note
 *               (see context_pre.php), or, for a synthetic jev_tick, stores the note for the
 *               next real request.
 *
 * Any failure (no key, network error, malformed answer) falls back to the normal LLM flow.
 */

if (!isset($gameRequest) || !is_array($gameRequest)) {
    return;
}

$jevType   = strtolower((string)($gameRequest[0] ?? ""));
$jevIsTick = ($jevType === "jev_tick");

if (empty($GLOBALS["JEV_TACTICAL_ENABLED"])) {
    if ($jevIsTick) {
        Logger::info("[JEV] jev_tick received but JEV_TACTICAL_ENABLED is off");
        terminate();
    }
    return;
}

if (!in_array($jevType, jev_tactical_tick_types(), true)) {
    return;
}

$jevNpcName = (string)($GLOBALS["HERIKA_NAME"] ?? "");
if ($jevNpcName === "" || $jevNpcName === "The Narrator" || $jevNpcName === "(actor)") {
    if ($jevIsTick) {
        terminate();
    }
    return;
}

$jevNpcMaster = new NpcMaster();
$jevNpcData   = $jevNpcMaster->getByName($jevNpcName);
if (!$jevNpcData) {
    if ($jevIsTick) {
        terminate();
    }
    return;
}

$jevGoal = jev_tactical_get_goal($jevNpcData, $jevNpcMaster);
if ($jevGoal === null) {
    // No standing goal: the main model is in charge, nothing to do here.
    if ($jevIsTick) {
        terminate();
    }
    return;
}

if ($jevType === "funcret") {
    // Only results of actions this layer issued are ours. Results of actions the main model
    // issued (Inspect, ReadQuestJournal, ...) must reach the LLM so it can talk about them.
    $jevRet  = explode("@", (string)($gameRequest[3] ?? ""));
    $jevCode = trim($jevRet[1] ?? "");
    if (empty($jevGoal["pending_action"]) || $jevGoal["pending_action"] !== $jevCode) {
        return;
    }
    $jevResult = trim($jevRet[3] ?? "");
    jev_tactical_update_goal($jevNpcData, $jevNpcMaster, function (array $g) use ($jevResult): array {
        $g["pending_action"] = null;
        $g["last_result"]    = $jevResult;
        return $g;
    });
    $jevGoal["pending_action"] = null;
    $jevGoal["last_result"]    = $jevResult;
}

try {
    $jevClient = new JevClient();
    if (!$jevClient->hasApiKey()) {
        Logger::warn("[JEV] JEV_API_KEY is not configured; tactical layer inactive");
        if ($jevIsTick) {
            terminate();
        }
        return;
    }

    $jevSituation  = jev_tactical_collect_situation($jevNpcData, $jevNpcMaster, $gameRequest);
    $jevCandidates = jev_tactical_candidates_from_situation($jevSituation);
    $jevCatalog    = jev_tactical_build_catalog(jev_tactical_enabled_actions(), $jevCandidates);
    $jevState      = jev_tactical_build_state($jevGoal, $jevSituation, time());
    $jevQuestions  = jev_tactical_build_questions($jevCatalog, [
        "npc_name"    => $jevNpcName,
        "player_name" => (string)($GLOBALS["PLAYER_NAME"] ?? "Player"),
    ]);

    $jevAnswers  = $jevClient->decide($jevState, $jevQuestions);
    $jevDecision = jev_tactical_resolve($jevAnswers, $jevCatalog, jev_tactical_thresholds());
} catch (Throwable $jevError) {
    Logger::warn("[JEV] tactical tick failed, falling back to the main model: " . $jevError->getMessage());
    if ($jevIsTick) {
        terminate();
    }
    return;
}

$GLOBALS["DEBUG_DATA"]["jev"] = [
    "request"  => $jevClient->lastRequest,
    "answers"  => $jevAnswers,
    "decision" => $jevDecision,
    "latency"  => $jevClient->lastLatency,
    "usage"    => $jevClient->lastUsage,
];
Logger::info("[JEV] {$jevNpcName}: " . jev_tactical_describe_decision($jevDecision) . " in " . round($jevClient->lastLatency, 3) . "s");

// Repeated low confidence means the goal or the vocabulary no longer fits: wake the main model.
$jevStreakLimit = intval($GLOBALS["JEV_LOW_CONFIDENCE_ESCALATE_AFTER"] ?? 3);
$jevLowConf     = ($jevDecision["kind"] === JEV_KIND_WAIT && strpos((string)$jevDecision["reason"], "low_") === 0);
$jevStreak      = $jevLowConf ? intval($jevGoal["low_confidence_streak"] ?? 0) + 1 : 0;
if ($jevLowConf && $jevStreakLimit > 0 && $jevStreak >= $jevStreakLimit) {
    $jevDecision["kind"]   = JEV_KIND_ESCALATE;
    $jevDecision["reason"] = "low_confidence_streak";
    $jevStreak = 0;
}

$jevSummary = jev_tactical_describe_decision($jevDecision);

jev_tactical_update_goal($jevNpcData, $jevNpcMaster, function (array $g) use ($jevDecision, $jevStreak, $jevSummary): array {
    $g["low_confidence_streak"] = $jevStreak;
    $g["last_decision"]         = $jevSummary;
    if ($jevDecision["kind"] === JEV_KIND_ACT) {
        $g["issued"]         = intval($g["issued"] ?? 0) + 1;
        $g["pending_action"] = $jevDecision["action"];
    } elseif ($jevDecision["kind"] === JEV_KIND_ESCALATE) {
        $g["escalation"] = [
            "reason"  => $jevDecision["reason"],
            "summary" => $jevSummary,
            "localts" => time(),
        ];
    }
    return $g;
});

switch ($jevDecision["kind"]) {
    case JEV_KIND_ACT:
        $jevCommand = jev_tactical_format_command($jevNpcName, $jevDecision);

        // Deliver the command inside this request's response, exactly like the LLM connectors do.
        echo $jevCommand . "\r\n";
        if (ob_get_level()) {
            @ob_flush();
        }
        @flush();

        $GLOBALS["db"]->insert(
            'actions_issued',
            [
                'action'    => $jevDecision["action"],
                'fullcall'  => $jevCommand,
                'actorname' => $jevNpcName,
                'ts'        => $gameRequest[1] ?? time(),
                'gamets'    => $gameRequest[2] ?? 0,
                'localts'   => time(),
                'original'  => 'jev_tactical',
            ]
        );

        // Leave a trace in the event log so the main model later knows what its body has been doing.
        $jevEvent    = $gameRequest;
        $jevEvent[0] = "infoaction";
        $jevEvent[3] = "{$jevNpcName} decides: {$jevSummary} (tactical layer, goal: {$jevGoal["goal"]})";
        logEvent($jevEvent, $jevNpcName);

        terminate();
        // no fallthrough: terminate() exits

    case JEV_KIND_WAIT:
        // Nothing worth doing and nothing worth thinking about: do not spend an LLM call.
        terminate();
        // no fallthrough: terminate() exits

    case JEV_KIND_ESCALATE:
    default:
        $GLOBALS["JEV_ESCALATION"] = [
            "reason"  => $jevDecision["reason"],
            "summary" => $jevSummary,
            "goal"    => $jevGoal["goal"],
        ];
        if ($jevIsTick) {
            // A synthetic tick has no player request to answer; the stored escalation note is
            // injected into the next real request by context_pre.php.
            terminate();
        }
        // Fall through into the normal LLM flow with the escalation note in hand.
        return;
}
