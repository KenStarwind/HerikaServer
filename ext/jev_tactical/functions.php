<?php
/**
 * Jev Tactical Layer - actions offered to the main model.
 *
 * Loaded by functions/functions.php (requireFunctionFilesRecursively) while the action catalog
 * is being built. Two server-side actions let the strategic model hand a goal to the tactical
 * layer and take it back:
 *
 *   SetTacticalGoal   {"goal": "...", "rules": "..."}
 *   ClearTacticalGoal
 *
 * Both are executed on the server through the action post-filter (the same mechanism the core
 * uses for Drink/Toast/Surrender) and never reach the game client.
 */

if (empty($GLOBALS["JEV_TACTICAL_ENABLED"])) {
    return;
}

$jevNpcLabel    = $GLOBALS["HERIKA_NAME"] ?? "the character";
$jevPlayerLabel = $GLOBALS["PLAYER_NAME"] ?? "the player";

$GLOBALS["F_NAMES"]["SetTacticalGoal"]        = "SetTacticalGoal";
$GLOBALS["F_TRANSLATIONS"]["SetTacticalGoal"] = "Hand a standing physical goal to {$jevNpcLabel}'s reflexes so it is carried out automatically (guarding, escorting, holding a position, fighting alongside {$jevPlayerLabel}). {$jevNpcLabel} keeps talking normally meanwhile. Goal is one sentence; rules are short constraints.";

$GLOBALS["F_NAMES"]["ClearTacticalGoal"]        = "ClearTacticalGoal";
$GLOBALS["F_TRANSLATIONS"]["ClearTacticalGoal"] = "End the current standing goal; {$jevNpcLabel} decides every action deliberately again.";

$GLOBALS["FUNCTIONS"][] = [
    "name"        => $GLOBALS["F_NAMES"]["SetTacticalGoal"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["SetTacticalGoal"],
    "parameters"  => [
        "type"       => "object",
        "properties" => [
            "goal"  => ["type" => "string", "description" => "REQUIRED: one sentence describing what to achieve, e.g. 'Guard the doorway while {$jevPlayerLabel} searches the room'"],
            "rules" => ["type" => "string", "description" => "Short constraints, e.g. 'Stay near the door. Do not start fights. Protect {$jevPlayerLabel} if attacked.'"],
        ],
        "required"   => ["goal"],
    ],
];

$GLOBALS["FUNCTIONS"][] = [
    "name"        => $GLOBALS["F_NAMES"]["ClearTacticalGoal"],
    "description" => $GLOBALS["F_TRANSLATIONS"]["ClearTacticalGoal"],
    "parameters"  => [
        "type"       => "object",
        "properties" => [
            "target" => ["type" => "string", "description" => "Keep it blank"],
        ],
        "required"   => [],
    ],
];

$GLOBALS["ENABLED_FUNCTIONS"][] = "SetTacticalGoal";
$GLOBALS["ENABLED_FUNCTIONS"][] = "ClearTacticalGoal";

$GLOBALS["action_post_process_fnct_ex"][] = function ($actions) {
    global $gameRequest;

    if (!is_array($actions)) {
        return $actions;
    }

    foreach ($actions as $n => $action) {
        $parts = explode("|", trim((string)$action), 3);
        if (count($parts) < 3) {
            continue;
        }
        [$actorName, , $call] = $parts;
        $callParts = explode("@", $call, 2);
        $code  = trim($callParts[0]);
        $param = trim($callParts[1] ?? "");

        if ($code !== "SetTacticalGoal" && $code !== "ClearTacticalGoal") {
            continue;
        }

        $npcMaster = new NpcMaster();
        $npcData   = $npcMaster->getByName($actorName);
        if (!$npcData) {
            Logger::warn("[JEV] {$code} for unknown NPC '{$actorName}'");
            unset($actions[$n]);
            continue;
        }

        if ($code === "SetTacticalGoal") {
            $goal  = $param;
            $rules = "";
            if ($param !== "" && $param[0] === "{") {
                $decoded = json_decode($param, true);
                if (is_array($decoded)) {
                    $goal  = (string)($decoded["goal"] ?? "");
                    $rules = (string)($decoded["rules"] ?? "");
                }
            }
            if ($goal === "") {
                Logger::warn("[JEV] SetTacticalGoal without a goal, ignored");
                unset($actions[$n]);
                continue;
            }
            jev_tactical_set_goal($npcData, $npcMaster, $goal, $rules, is_array($gameRequest) ? $gameRequest : []);
            Logger::info("[JEV] {$actorName} standing goal set: {$goal}");
        } else {
            jev_tactical_clear_goal($npcData, $npcMaster);
            Logger::info("[JEV] {$actorName} standing goal cleared");
        }

        $GLOBALS["db"]->insert(
            'actions_issued',
            [
                'action'    => $code,
                'fullcall'  => "{$actorName}|command|{$call}",
                'actorname' => $actorName,
                'ts'        => $gameRequest[1] ?? time(),
                'gamets'    => $gameRequest[2] ?? 0,
                'localts'   => time(),
                'original'  => 'jev_tactical',
            ]
        );

        unset($actions[$n]); // handled server-side, the client must not see it
    }

    return $actions;
};
