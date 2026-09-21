<?php
/**
 * Jev Tactical Layer - decision logic.
 *
 * Splits NPC autonomy into three timescales:
 *
 *   strategic  main LLM (GPT/Claude/...)  minutes   dialogue, personality, goals   -> SetTacticalGoal
 *   tactical   Jev (TypeSafe System One)  ~seconds  which CHIM action to run next   -> this file
 *   motor      Skyrim (Papyrus/DLL)       frames    pathing, animation, combat      -> CHIM command lines
 *
 * The functions in the first half of this file are pure (no database, no globals) so they can
 * be unit tested. The helpers in the second half touch CHIM data (NpcMaster, eventlog) and are
 * only used by the request hooks.
 *
 * Vocabulary: a "catalog" is the subset of CHIM actions Jev may pick from this tick, each with
 * the concrete candidates for its parameter slots. A "decision" is what jev_tactical_resolve()
 * returns: act / wait / escalate.
 */

require_once __DIR__ . "/jev_client.php";

const JEV_ACTION_WAIT     = "Wait";
const JEV_ACTION_ESCALATE = "Escalate";
const JEV_OPTION_NONE     = "none";

const JEV_KIND_ACT      = "act";
const JEV_KIND_WAIT     = "wait";
const JEV_KIND_ESCALATE = "escalate";

/* ====================================================================== */
/* Pure logic                                                              */
/* ====================================================================== */

/**
 * The tactical vocabulary: CHIM action codename => [short third-person description, slots].
 * Slots map a CHIM parameter name to a candidate kind (see jev_tactical_slot_options()).
 *
 * Deliberately excluded: anything that returns information for the LLM to talk about
 * (Inspect, InspectSurroundings, CheckInventory, ReadQuestJournal, SearchDiary, SearchMemory),
 * anything social or conversational (OpenInventory, TakeGoldFromPlayer, GiveGoldTo, MakeFollower,
 * Toast, Drink, rituals, Training, EndConversation, UseSoulGaze) and free-form parameters.
 * Those stay with the main model.
 */
function jev_tactical_default_actions(): array
{
    return [
        "Attack"         => ["Engage a hostile with intent to kill",                 ["target" => "hostile"]],
        "Brawl"          => ["Start a non-lethal fist fight with someone",           ["target" => "actor"]],
        "Follow"         => ["Move to and keep following someone",                   ["target" => "actor"]],
        "FollowPlayer"   => ["Fall in and follow {player}",                          []],
        "ComeCloser"     => ["Close the distance to {player}",                       []],
        "MoveTo"         => ["Walk to a visible actor or building",                  ["target" => "place"]],
        "TravelTo"       => ["Set off toward a distant location or point of interest", ["location" => "location"]],
        "WaitHere"       => ["Hold this position and stand watch",                   []],
        "StopWalk"       => ["Stop moving immediately",                              []],
        "Relax"          => ["Stand down and relax here",                            []],
        "SheatheWeapon"  => ["Put the weapon away",                                  []],
        "TakeASeat"      => ["Sit down on nearby furniture",                         []],
        "GoToSleep"      => ["Lie down and sleep",                                   []],
        "ReturnBackHome" => ["Leave and go back home",                               []],
        "PickupItem"     => ["Pick up an item lying nearby",                         ["item" => "nearby_item"]],
        "GiveItemTo"     => ["Hand an inventory item to someone",                    ["target" => "actor", "item" => "inventory"]],
        "CastSpell"      => ["Cast a known spell on someone, or on self",            ["target" => "spell_target", "item" => "spell"]],
        "Surrender"      => ["Yield and stop fighting",                              []],
    ];
}

/**
 * Candidate labels for a slot kind. $candidates has the keys
 * actor, hostile, location, nearby_item, inventory, spell (each a list of strings).
 */
function jev_tactical_slot_options(string $kind, array $candidates): array
{
    $get = function (string $key) use ($candidates): array {
        return (isset($candidates[$key]) && is_array($candidates[$key])) ? $candidates[$key] : [];
    };

    switch ($kind) {
        case "actor":
            $options = $get("actor");
            break;
        case "hostile":
            $options = $get("hostile");
            if (count($options) === 0) {
                $options = $get("actor");
            }
            break;
        case "place":
            $options = array_merge($get("actor"), $get("location"));
            break;
        case "location":
            $options = $get("location");
            break;
        case "nearby_item":
            $options = $get("nearby_item");
            break;
        case "inventory":
            $options = $get("inventory");
            break;
        case "spell":
            $options = $get("spell");
            break;
        case "spell_target":
            $options = array_merge($get("actor"), ["self"]);
            break;
        default:
            $options = [];
    }

    $clean = [];
    foreach ($options as $label) {
        $label = trim((string)$label);
        if ($label === "" || $label === JEV_OPTION_NONE || is_numeric($label)) {
            continue;
        }
        $clean[$label] = true;
    }
    // Leave room for the mandatory "none" option.
    return array_slice(array_keys($clean), 0, JevClient::MAX_CHOICE_OPTIONS - 1);
}

/**
 * Build this tick's catalog: enabled actions whose every slot has at least one candidate.
 *
 * @param string[] $enabled     CHIM codenames the NPC may use (e.g. $GLOBALS["ENABLED_FUNCTIONS"])
 * @param array    $candidates  see jev_tactical_slot_options()
 * @param array|null $actions   override of jev_tactical_default_actions()
 * @return array codename => ["description" => string, "slots" => [slot => kind], "options" => [slot => string[]]]
 */
function jev_tactical_build_catalog(array $enabled, array $candidates, ?array $actions = null): array
{
    $actions = $actions ?? jev_tactical_default_actions();
    $catalog = [];
    foreach ($actions as $code => $def) {
        if (!in_array($code, $enabled, true)) {
            continue;
        }
        [$description, $slots] = $def;
        $options = [];
        $usable  = true;
        foreach ($slots as $slot => $kind) {
            $opts = jev_tactical_slot_options($kind, $candidates);
            if (count($opts) === 0) {
                $usable = false;
                break;
            }
            $options[$slot] = $opts;
        }
        if (!$usable) {
            continue;
        }
        $catalog[$code] = ["description" => $description, "slots" => $slots, "options" => $options];
    }
    return $catalog;
}

/**
 * Speculative fan-out question set. One "action" choice, one conditional choice per
 * (action, slot) pair with its premise stated, plus interrupt / threat / needs_deliberation.
 * All questions are evaluated in parallel by Jev; jev_tactical_resolve() reads only the slot
 * answers belonging to the chosen action.
 */
function jev_tactical_build_questions(array $catalog, array $opts = []): array
{
    $npc    = $opts["npc_name"] ?? "the character";
    $player = $opts["player_name"] ?? "the player";
    $subst  = ["{player}" => $player, "{npc}" => $npc];

    $criteria = [];
    foreach ($catalog as $code => $entry) {
        $criteria[$code] = strtr($entry["description"], $subst);
    }
    $criteria[JEV_ACTION_WAIT]     = "Do nothing new this tick; the current behaviour already serves the goal";
    $criteria[JEV_ACTION_ESCALATE] = "Stop and think: this needs {$npc}'s full reasoning, a conversation, or a new plan";

    $questions = [];
    $questions["action"] = JevClient::choice(
        "Given the standing goal, its rules and the current situation, which single action should {$npc} take right now?",
        $criteria
    );

    foreach ($catalog as $code => $entry) {
        foreach ($entry["slots"] as $slot => $kind) {
            $slotCriteria = [];
            foreach ($entry["options"][$slot] as $label) {
                $slotCriteria[$label] = null;
            }
            $slotCriteria[JEV_OPTION_NONE] = "No listed option fits the goal";
            $questions["{$code}.{$slot}"] = JevClient::choice(
                "Assume {$npc} is about to perform the action '{$code}'. Which {$slot} should it use? Pick '" . JEV_OPTION_NONE . "' if no listed option serves the goal.",
                $slotCriteria
            );
        }
    }

    $questions["interrupt"] = JevClient::noul(
        "Something just changed that means {$npc} should abandon whatever it is currently doing immediately."
    );
    $questions["threat"] = JevClient::score(
        "How dangerous is the situation right now for {$npc} and the people {$npc} is protecting?",
        ["none", "low", "moderate", "high", "critical"]
    );
    $questions["needs_deliberation"] = JevClient::noul(
        "This moment calls for {$npc}'s full mind: a conversation, a plan, a moral judgement, or something the standing goal does not cover."
    );

    return $questions;
}

/**
 * Turn Jev's answers into one decision.
 *
 * Rules, in order:
 *   1. needs_deliberation >= escalate_threshold        -> escalate (wake the main LLM)
 *   2. no action answer / unknown action                -> escalate / wait
 *   3. action == Escalate                               -> escalate
 *   4. action == Wait                                   -> wait
 *   5. action confidence < min_confidence               -> wait (reason low_confidence)
 *   6. every slot: read "<action>.<slot>"; "none" or missing -> wait; low confidence -> wait
 *   7. otherwise                                        -> act with the resolved parameters
 *
 * @return array kind, action, params, confidence, reason, threat, interrupt, needs_deliberation, probabilities
 */
function jev_tactical_resolve(array $answers, array $catalog, array $thresholds = []): array
{
    $minConfidence     = floatval($thresholds["min_confidence"] ?? 0.6);
    $escalateThreshold = floatval($thresholds["escalate_threshold"] ?? 0.7);

    $deliberation = JevClient::answerNoul($answers, "needs_deliberation");
    $interrupt    = JevClient::answerNoul($answers, "interrupt");
    $threat       = JevClient::answerScore($answers, "threat");
    $action       = JevClient::answerChoice($answers, "action");

    $decision = [
        "kind"               => JEV_KIND_WAIT,
        "action"             => null,
        "params"             => [],
        "confidence"         => $action["confidence"] ?? null,
        "reason"             => "",
        "threat"             => $threat,
        "interrupt"          => $interrupt,
        "needs_deliberation" => $deliberation,
        "probabilities"      => $action["probabilities"] ?? [],
    ];

    if ($deliberation !== null && $deliberation >= $escalateThreshold) {
        $decision["kind"]   = JEV_KIND_ESCALATE;
        $decision["reason"] = "needs_deliberation";
        return $decision;
    }
    if ($action === null) {
        $decision["kind"]   = JEV_KIND_ESCALATE;
        $decision["reason"] = "no_action_answer";
        return $decision;
    }

    $chosen = $action["choice"];
    if ($chosen === JEV_ACTION_ESCALATE) {
        $decision["kind"]   = JEV_KIND_ESCALATE;
        $decision["reason"] = "model_requested";
        return $decision;
    }
    if ($chosen === JEV_ACTION_WAIT) {
        $decision["reason"] = "model_chose_wait";
        return $decision;
    }
    if ($action["confidence"] !== null && $action["confidence"] < $minConfidence) {
        $decision["reason"] = "low_confidence";
        $decision["action"] = $chosen;
        return $decision;
    }
    if (!isset($catalog[$chosen])) {
        $decision["reason"] = "unknown_action";
        $decision["action"] = $chosen;
        return $decision;
    }

    $params = [];
    foreach ($catalog[$chosen]["slots"] as $slot => $kind) {
        $slotAnswer = JevClient::answerChoice($answers, "{$chosen}.{$slot}");
        if ($slotAnswer === null || $slotAnswer["choice"] === JEV_OPTION_NONE) {
            $decision["action"] = $chosen;
            $decision["reason"] = "no_target:{$slot}";
            return $decision;
        }
        if (!in_array($slotAnswer["choice"], $catalog[$chosen]["options"][$slot], true)) {
            $decision["action"] = $chosen;
            $decision["reason"] = "invalid_target:{$slot}";
            return $decision;
        }
        if ($slotAnswer["confidence"] !== null && $slotAnswer["confidence"] < $minConfidence) {
            $decision["action"] = $chosen;
            $decision["reason"] = "low_target_confidence:{$slot}";
            return $decision;
        }
        $params[$slot] = $slotAnswer["choice"];
    }

    $decision["kind"]   = JEV_KIND_ACT;
    $decision["action"] = $chosen;
    $decision["params"] = $params;
    $decision["reason"] = "ok";
    return $decision;
}

/**
 * Format an "act" decision as the command line the CHIM client understands:
 *   Name|command|Code@param            (zero or one parameter)
 *   Name|command|Code@{"target":..,..}  (several parameters, same as the JSON connectors emit)
 */
function jev_tactical_format_command(string $npcName, array $decision): ?string
{
    if (($decision["kind"] ?? "") !== JEV_KIND_ACT || empty($decision["action"])) {
        return null;
    }
    $params = $decision["params"] ?? [];
    if (count($params) === 0) {
        $param = "";
    } elseif (count($params) === 1) {
        $param = (string)current($params);
    } else {
        $param = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return "{$npcName}|command|{$decision["action"]}@{$param}";
}

/**
 * Compose the state Jev judges. $goal comes from jev_tactical_goal_from_extended(),
 * $situation from jev_tactical_collect_situation() (or a fixture in tests / dry runs).
 */
function jev_tactical_build_state(array $goal, array $situation, int $now): array
{
    $setAgo = isset($goal["set_localts"]) ? max(0, intval(($now - intval($goal["set_localts"])) / 60)) : null;

    $state = [
        "character" => [
            "name"  => $situation["npc_name"] ?? "",
            "stats" => $situation["stats"] ?? [],
        ],
        "standing_goal" => [
            "goal"                => $goal["goal"] ?? "",
            "rules"               => $goal["rules"] ?? "",
            "set_minutes_ago"     => $setAgo,
            "actions_issued"      => intval($goal["issued"] ?? 0),
            "last_decision"       => $goal["last_decision"] ?? null,
            "last_action_result"  => $goal["last_result"] ?? null,
        ],
        "player" => [
            "name" => $situation["player_name"] ?? "",
        ],
        "location"            => $situation["location"] ?? "",
        "nearby_actors"       => $situation["actors_annotated"] ?? [],
        "hostiles"            => $situation["hostile"] ?? [],
        "nearby_items"        => $situation["items_annotated"] ?? [],
        "reachable_locations" => $situation["location_list"] ?? [],
        "inventory"           => $situation["inventory"] ?? [],
        "spells"              => $situation["spell"] ?? [],
        "recent_events"       => $situation["recent_events"] ?? [],
        "trigger"             => $situation["trigger"] ?? [],
    ];

    return $state;
}

/**
 * Read the standing goal out of an NPC's extended_data array; null when absent or expired.
 */
function jev_tactical_goal_from_extended(array $extended, int $now, int $ttlSeconds): ?array
{
    $goal = $extended["jev_goal"] ?? null;
    if (!is_array($goal) || empty($goal["goal"])) {
        return null;
    }
    $ttl = intval($goal["ttl_seconds"] ?? $ttlSeconds);
    if ($ttl > 0 && isset($goal["set_localts"]) && ($now - intval($goal["set_localts"])) > $ttl) {
        return null;
    }
    return $goal;
}

/**
 * Parse the "|Name (tag) (tag)|Other|" strings CHIM keeps for nearby actors.
 * @return array ["actor" => names, "hostile" => names, "annotated" => original entries]
 */
function jev_tactical_parse_actor_list(string $packed, string $excludeName = ""): array
{
    $actors = [];
    $hostile = [];
    $annotated = [];
    foreach (explode("|", $packed) as $entry) {
        $entry = trim($entry);
        if ($entry === "") {
            continue;
        }
        $name = trim(preg_replace('/\s*\([^)]*\)/', "", $entry));
        if ($name === "" || ($excludeName !== "" && strcasecmp($name, $excludeName) === 0)) {
            continue;
        }
        if (stripos($entry, "(dead)") !== false) {
            continue;
        }
        $annotated[] = $entry;
        $actors[$name] = true;
        if (preg_match('/\((?:[^)]*\b(?:hostile|attacking|in combat|enemy)\b[^)]*)\)/i', $entry)) {
            $hostile[$name] = true;
        }
    }
    return ["actor" => array_keys($actors), "hostile" => array_keys($hostile), "annotated" => $annotated];
}

/**
 * Parse CHIM's "RefID:Item (note), RefID:Item" nearby item string.
 * PickupItem wants the exact "RefID:ItemName" so the label keeps the RefID and drops notes.
 * @return array ["nearby_item" => labels, "annotated" => original entries]
 */
function jev_tactical_parse_item_list(string $items): array
{
    $labels = [];
    $annotated = [];
    foreach (explode(",", $items) as $entry) {
        $entry = trim($entry);
        if ($entry === "") {
            continue;
        }
        $label = trim(preg_replace('/\s*\([^)]*\)\s*$/', "", $entry));
        if ($label === "") {
            continue;
        }
        $annotated[] = $entry;
        $labels[$label] = true;
    }
    return ["nearby_item" => array_keys($labels), "annotated" => $annotated];
}

/* ====================================================================== */
/* CHIM-bound helpers (database, globals)                                  */
/* ====================================================================== */

function jev_tactical_tick_types(): array
{
    $raw = (string)($GLOBALS["JEV_TICK_TYPES"] ?? "funcret,bored,jev_tick");
    return array_values(array_filter(array_map("trim", explode(",", strtolower($raw)))));
}

function jev_tactical_thresholds(): array
{
    return [
        "min_confidence"     => floatval($GLOBALS["JEV_MIN_CONFIDENCE"] ?? 0.6),
        "escalate_threshold" => floatval($GLOBALS["JEV_ESCALATE_THRESHOLD"] ?? 0.7),
    ];
}

/**
 * Which CHIM actions this NPC may use. prompt.includes.php (and with it functions/functions.php)
 * is loaded after the prerequest hook, so $GLOBALS["ENABLED_FUNCTIONS"] is normally not yet
 * available; fall back to CHIM's own NPC/follower defaults, honouring functions/user_pref.json.
 */
function jev_tactical_enabled_actions(): array
{
    if (isset($GLOBALS["ENABLED_FUNCTIONS"]) && is_array($GLOBALS["ENABLED_FUNCTIONS"]) && count($GLOBALS["ENABLED_FUNCTIONS"]) > 0) {
        return array_values($GLOBALS["ENABLED_FUNCTIONS"]);
    }
    $prefFile = rtrim((string)($GLOBALS["ENGINE_PATH"] ?? (__DIR__ . "/../../..")), "/\\") . "/functions/user_pref.json";
    if (file_exists($prefFile)) {
        $pref = json_decode(file_get_contents($prefFile), true);
        if (is_array($pref) && count($pref) > 0) {
            return array_values($pref);
        }
    }
    $common = ["Attack", "Follow", "MoveTo", "TravelTo", "WaitHere", "ComeCloser", "Relax", "TakeASeat",
        "Brawl", "GiveItemTo", "PickupItem", "GoToSleep", "CastSpell", "Surrender"];
    if (!empty($GLOBALS["IS_NPC"])) {
        return array_merge($common, ["FollowPlayer"]);
    }
    return array_merge($common, ["SheatheWeapon", "StopWalk", "ReturnBackHome"]);
}

/**
 * Gather everything CHIM already knows about the NPC's surroundings.
 */
function jev_tactical_collect_situation(array $npcData, NpcMaster $npcMaster, array $gameRequest): array
{
    $npcName    = (string)($npcData["npc_name"] ?? ($GLOBALS["HERIKA_NAME"] ?? ""));
    $playerName = (string)($GLOBALS["PLAYER_NAME"] ?? "Player");

    $packed = function_exists("DataBeingsInCloseRange") ? (string)DataBeingsInCloseRange(true) : "";
    $actors = jev_tactical_parse_actor_list($packed, $npcName);
    if (count($actors["actor"]) === 0 && function_exists("DataPosibleInspectTargets")) {
        $fallback = DataPosibleInspectTargets(false);
        if (is_array($fallback)) {
            $actors = jev_tactical_parse_actor_list("|" . implode("|", $fallback) . "|", $npcName);
        }
    }
    if (!in_array($playerName, $actors["actor"], true)) {
        $actors["actor"][] = $playerName;
    }

    $locations = function_exists("DataPosibleLocationsToGo") ? DataPosibleLocationsToGo() : [];
    if (!is_array($locations)) {
        $locations = [];
    }

    $items = jev_tactical_parse_item_list(function_exists("DataItemsInCloseRange") ? (string)DataItemsInCloseRange() : "");

    $meta      = $npcMaster->getMetadata($npcData);
    $inventory = [];
    if (isset($meta["inventory"]) && is_array($meta["inventory"])) {
        foreach ($meta["inventory"] as $item) {
            $name = is_array($item) ? ($item["name"] ?? "") : (string)$item;
            if ($name !== "" && $name !== "<Missing Name>") {
                $inventory[$name] = true;
            }
        }
    }
    $spells = [];
    if (isset($meta["spells"]) && is_array($meta["spells"])) {
        foreach ($meta["spells"] as $spell) {
            $name = is_array($spell) ? ($spell["name"] ?? "") : (string)$spell;
            if ($name !== "") {
                $spells[$name] = true;
            }
        }
    }

    $stats = [];
    if (isset($meta["stats"]) && is_array($meta["stats"])) {
        foreach (["level", "health", "health_max", "magicka", "magicka_max", "stamina", "stamina_max"] as $k) {
            if (isset($meta["stats"][$k])) {
                $stats[$k] = $meta["stats"][$k];
            }
        }
    }

    $recent = [];
    if (isset($GLOBALS["db"])) {
        $rows = $GLOBALS["db"]->fetchAll("SELECT type, data FROM eventlog WHERE type IN ('infoaction','death','itemfound','logaction','combatend','funcret','inputtext') ORDER BY gamets DESC, ts DESC LIMIT 8");
        if (is_array($rows)) {
            foreach (array_reverse($rows) as $row) {
                $recent[] = trim((string)($row["data"] ?? ""));
            }
        }
    }

    $location = function_exists("DataLastKnownLocationHuman") ? (string)DataLastKnownLocationHuman(false, true) : "";

    $trigger = ["type" => $gameRequest[0] ?? ""];
    if (($gameRequest[0] ?? "") === "funcret") {
        $ret = explode("@", (string)($gameRequest[3] ?? ""));
        $trigger["action"] = $ret[1] ?? "";
        $trigger["result"] = $ret[3] ?? "";
    }

    return [
        "npc_name"         => $npcName,
        "player_name"      => $playerName,
        "location"         => $location,
        "stats"            => $stats,
        "actor"            => $actors["actor"],
        "hostile"          => $actors["hostile"],
        "actors_annotated" => $actors["annotated"],
        "location_list"    => array_values(array_unique(array_map("strval", $locations))),
        "nearby_item"      => $items["nearby_item"],
        "items_annotated"  => $items["annotated"],
        "inventory"        => array_keys($inventory),
        "spell"            => array_keys($spells),
        "recent_events"    => $recent,
        "trigger"          => $trigger,
    ];
}

/** Candidate lists in the shape jev_tactical_build_catalog() expects. */
function jev_tactical_candidates_from_situation(array $situation): array
{
    return [
        "actor"       => $situation["actor"] ?? [],
        "hostile"     => $situation["hostile"] ?? [],
        "location"    => $situation["location_list"] ?? [],
        "nearby_item" => $situation["nearby_item"] ?? [],
        "inventory"   => $situation["inventory"] ?? [],
        "spell"       => $situation["spell"] ?? [],
    ];
}

function jev_tactical_get_goal(array $npcData, NpcMaster $npcMaster): ?array
{
    $extended = $npcMaster->getExtendedData($npcData);
    return jev_tactical_goal_from_extended($extended, time(), intval($GLOBALS["JEV_GOAL_TTL_SECONDS"] ?? 900));
}

function jev_tactical_set_goal(array $npcData, NpcMaster $npcMaster, string $goal, string $rules, array $gameRequest = []): array
{
    $extended = $npcMaster->getExtendedData($npcData);
    $extended["jev_goal"] = [
        "goal"                 => trim($goal),
        "rules"                => trim($rules),
        "set_localts"          => time(),
        "set_gamets"           => intval($gameRequest[2] ?? 0),
        "ttl_seconds"          => intval($GLOBALS["JEV_GOAL_TTL_SECONDS"] ?? 900),
        "issued"               => 0,
        "low_confidence_streak"=> 0,
        "pending_action"       => null,
        "last_decision"        => null,
        "last_result"          => null,
        "escalation"           => null,
    ];
    $npcData = $npcMaster->setExtendedData($npcData, $extended);
    $npcMaster->updateByArray($npcData);
    return $extended["jev_goal"];
}

function jev_tactical_clear_goal(array $npcData, NpcMaster $npcMaster): void
{
    $extended = $npcMaster->getExtendedData($npcData);
    if (isset($extended["jev_goal"])) {
        unset($extended["jev_goal"]);
        $npcData = $npcMaster->setExtendedData($npcData, $extended);
        $npcMaster->updateByArray($npcData);
    }
}

/** Apply $mutator(array $goal): array to the stored goal and persist it. */
function jev_tactical_update_goal(array $npcData, NpcMaster $npcMaster, callable $mutator): void
{
    $extended = $npcMaster->getExtendedData($npcData);
    if (!isset($extended["jev_goal"]) || !is_array($extended["jev_goal"])) {
        return;
    }
    $extended["jev_goal"] = $mutator($extended["jev_goal"]);
    $npcData = $npcMaster->setExtendedData($npcData, $extended);
    $npcMaster->updateByArray($npcData);
}

/** One-line human summary of a decision, used for logs and the eventlog. */
function jev_tactical_describe_decision(array $decision): string
{
    $kind = $decision["kind"] ?? "?";
    if ($kind === JEV_KIND_ACT) {
        $params = $decision["params"] ?? [];
        $text = $decision["action"] . (count($params) ? " " . implode(", ", array_map(fn($k, $v) => "$k=$v", array_keys($params), $params)) : "");
    } else {
        $text = $kind . (!empty($decision["reason"]) ? " ({$decision["reason"]})" : "");
    }
    if (isset($decision["confidence"]) && $decision["confidence"] !== null) {
        $text .= sprintf(" [confidence %.2f]", $decision["confidence"]);
    }
    return $text;
}
