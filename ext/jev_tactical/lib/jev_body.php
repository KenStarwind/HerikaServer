<?php
/**
 * Jev Tactical Layer - the body (RelDyn's bio-mimetic feedback).
 *
 * Ken 2026-10-01: "Bio-mimetic feedback (voice emotion, body language, approach / turn-away): a good use for Jev." RelDyn
 * (ext/relationship_dynamics, Ken's relationship extension) computes how the NPC's body would show what the NPC feels: four
 * body-language cues, each with a 0..1 strength that depends on who the NPC is (approach, turn_away, shy_glance, tense_stance),
 * and the emotion of the voice for TTS. RelDyn itself needs no Jev: without one the strongest cue speaks as felt text. This is
 * Jev's part, in the form it was built for, "if I feel this and my goal is that, then I do x": Jev is given RelDyn's explicit
 * state (RelationshipDynamics::jevStateBlock: numbers, not the felt prose the dialogue model gets) and the NPC's goal, and picks
 *
 *   cue    which of the cues RelDyn offers fires now, or none (a quiet body)
 *   voice  whether the voice carries the emotion RelDyn read, or stays ordinary
 *
 * Both reach RelDyn through RelDynBody::pick(), which makes the cue speak as felt text for the dialogue model for a while and
 * hands back a command line for the one cue core has an action for (approach -> ComeCloser). That line goes out the way this
 * plugin's tactical actions do (echoed into the current response, recorded in actions_issued, logged as an infoaction event),
 * only if the action is enabled for this NPC. Turning away, glancing and standing tense have no animation channel in CHIM 3.4.1;
 * they stay context text until a game-side hook exists (see RelDyn's reldyn_body.php, ANIMATION HOOKS).
 *
 * Soft dependency: everything here is inert unless RelDyn is loaded (class RelDynBody and RelationshipDynamics::jevStateBlock),
 * off by default (JEV_BODY_ENABLED), and any failure leaves the request to the normal flow. The pure functions (the first half)
 * need no database and no RelDyn, so they are unit tested with a stub of RelDyn's API.
 */

require_once __DIR__ . "/jev_client.php";

const JEV_BODY_NONE = "none";

/* ====================================================================== */
/* Pure logic                                                              */
/* ====================================================================== */

/** What each cue means, for Jev (third person; {npc} and {player} are replaced). */
function jev_body_cue_descriptions(): array
{
    return [
        "approach"     => "{npc} moves closer to {player}: drawn to them, wanting the distance gone (fires the CHIM action ComeCloser when it is enabled)",
        "turn_away"    => "{npc} turns away from {player}: withdrawn, pulled back or ready to leave (shown in the words, no animation yet)",
        "shy_glance"   => "{npc} steals a glance at {player} and looks away: drawn to them and shy about it (shown in the words, no animation yet)",
        "tense_stance" => "{npc} stands tense and braced: a quarrel, resentment, jealousy (shown in the words, no animation yet)",
    ];
}

/** The cues RelDyn offers in this block (known ids only, strongest first as RelDyn ordered them). */
function jev_body_offered_cues(array $block): array
{
    $known = array_keys(jev_body_cue_descriptions());
    $out = [];
    foreach ((array)($block["body"]["offered"] ?? []) as $cue) {
        if (is_string($cue) && in_array($cue, $known, true)) {
            $out[] = $cue;
        }
    }
    return $out;
}

/**
 * The state Jev judges: RelDyn's explicit numbers for the body, its compact one-line state, and the goal. $goal is the standing
 * tactical goal (jev_tactical_get_goal) or null; RelDyn's own director goal travels in the block.
 */
function jev_body_build_state(array $block, ?array $goal, string $playerName): array
{
    $body = (array)($block["body"] ?? []);
    $cues = [];
    foreach ((array)($body["cues"] ?? []) as $id => $c) {
        $cues[$id] = [
            "strength"      => $c["strength"] ?? 0,
            "offered"       => !empty($c["offered"]),
            "on_cooldown"   => floatval($c["cooldown_play_seconds"] ?? 0) > 0,
        ];
    }
    return [
        "npc"          => (string)($block["npc"] ?? ""),
        "player"       => $playerName,
        "standing_goal" => $goal === null ? null : ["goal" => (string)($goal["goal"] ?? ""), "rules" => (string)($goal["rules"] ?? "")],
        "director_goal" => $block["goal"] ?? null,
        "relationship" => (string)($block["text"] ?? ""),
        "body"         => [
            "cues"               => $cues,
            "approach_threshold" => $body["approach_threshold"] ?? null,
            "speaking_now"       => $body["chosen"] ?? null,
            "voice"              => $body["voice"] ?? null,
        ],
    ];
}

/** The cue and voice questions for the cues RelDyn offers. Empty when nothing is offered and the voice is ordinary. */
function jev_body_build_questions(array $block, array $opts = []): array
{
    $npc    = $opts["npc_name"] ?? (string)($block["npc"] ?? "the character");
    $player = $opts["player_name"] ?? "the player";
    $subst  = ["{npc}" => $npc, "{player}" => $player];
    $desc   = jev_body_cue_descriptions();

    $questions = [];
    $offered = jev_body_offered_cues($block);
    if (count($offered) > 0) {
        $criteria = [];
        foreach ($offered as $cue) {
            $criteria[$cue] = strtr($desc[$cue], $subst);
        }
        $criteria[JEV_BODY_NONE] = "None of these: {$npc}'s body stays quiet, whatever is felt";
        $questions["cue"] = JevClient::choice(
            "Given what {$npc} feels (the state) and {$npc}'s goal, which body-language cue, if any, should {$npc} show {$player} right now? "
            . "Pick '" . JEV_BODY_NONE . "' when the moment calls for a quiet body, for instance when the goal is to stay unnoticed or to keep a guard up.",
            $criteria
        );
    }
    $voice = $block["body"]["voice"] ?? null;
    if (is_array($voice)) {
        $label = (string)($voice["mood"] ?? $voice["family"] ?? "an emotion");
        $questions["voice"] = JevClient::noul(
            "{$npc}'s voice would carry {$label} ({$voice["intensity"]}) from what {$npc} feels. Given the goal and the moment, should {$npc}'s voice carry it right now, rather than stay ordinary?"
        );
    }
    return $questions;
}

/**
 * Turn Jev's answers into what to hand RelDyn.
 *
 *   cue     answer missing, not offered, or confidence under min_confidence -> no pick (RelDyn's own default stays)
 *           "none"                                                           -> a pick of no cue (the default stays quiet)
 *   voice   probability of "yes" >= voice_at -> true, under it -> false, no answer -> null (RelDyn's own rule)
 *
 * @return array pick (bool: call RelDynBody::pick), cue (?string), voice (?bool), confidence (?float), reason
 */
function jev_body_resolve(array $answers, array $block, array $thresholds = []): array
{
    $min     = floatval($thresholds["min_confidence"] ?? 0.6);
    $voiceAt = floatval($thresholds["voice_at"] ?? 0.5);
    $offered = jev_body_offered_cues($block);

    $voice = null;
    $p = JevClient::answerNoul($answers, "voice");
    if ($p !== null) {
        $voice = $p >= $voiceAt;
    }

    $res = ["pick" => false, "cue" => null, "voice" => $voice, "confidence" => null, "reason" => ""];
    $a = JevClient::answerChoice($answers, "cue");
    if ($a === null) {
        if ($voice !== null) {
            $res["pick"]   = true;
            $res["reason"] = "voice_only";
            return $res;
        }
        $res["reason"] = count($offered) === 0 ? "nothing_offered" : "no_answer";
        return $res;
    }
    $res["confidence"] = $a["confidence"];
    if ($a["choice"] !== JEV_BODY_NONE && !in_array($a["choice"], $offered, true)) {
        $res["reason"] = "invalid_cue";
        return $res;
    }
    if ($a["confidence"] !== null && $a["confidence"] < $min) {
        $res["reason"] = "low_confidence";
        $res["voice"]  = null;   // an unsure picker leaves the voice to RelDyn too
        return $res;
    }
    $res["pick"]   = true;
    $res["cue"]    = $a["choice"] === JEV_BODY_NONE ? null : $a["choice"];
    $res["reason"] = $res["cue"] === null ? "quiet_body" : "ok";
    return $res;
}

/** One-line summary of a resolution, for logs and the eventlog. */
function jev_body_describe(array $res): string
{
    if (empty($res["pick"])) {
        return "no pick (" . ($res["reason"] ?: "?") . ")";
    }
    $text = $res["cue"] ?? "quiet body";
    if ($res["voice"] !== null) {
        $text .= $res["voice"] ? ", voice carries it" : ", voice ordinary";
    }
    if ($res["confidence"] !== null) {
        $text .= sprintf(" [confidence %.2f]", $res["confidence"]);
    }
    return $text;
}

/** What the eventlog says the body did, for the dialogue model to know: only the cue with an action is a visible act. */
function jev_body_event_text(string $npc, string $player, string $cue): string
{
    return $cue === "approach" ? "{$npc} moves closer to {$player}" : "{$npc} shows {$cue}";
}

/**
 * Decide and pick, with everything injected: the block RelDyn gave, the goal, the Jev client, the actions enabled for this NPC.
 * Calls RelDynBody::pick once when Jev gave a usable answer. The command line comes back only if the cue's action is enabled.
 *
 * @return array resolution, questions, pick (RelDynBody::pick's result or null), command (?string to send), action (?string)
 */
function jev_body_decide(string $npc, array $block, ?array $goal, string $player, JevClient $client, array $enabledActions, array $thresholds = []): array
{
    $out = ["resolution" => ["pick" => false, "reason" => "nothing_asked"], "questions" => [], "pick" => null, "command" => null, "action" => null];
    $questions = jev_body_build_questions($block, ["npc_name" => $npc, "player_name" => $player]);
    $out["questions"] = $questions;
    if (count($questions) === 0) {
        return $out;
    }
    $answers = $client->decide(jev_body_build_state($block, $goal, $player), $questions);
    $res = jev_body_resolve($answers, $block, $thresholds);
    $out["resolution"] = $res;
    if (empty($res["pick"])) {
        return $out;
    }
    $pick = RelDynBody::pick($npc, $res["cue"], "jev", $res["voice"]);
    $out["pick"] = $pick;
    if (!empty($pick["ok"]) && !empty($pick["command"]) && !empty($pick["action"]) && in_array($pick["action"], $enabledActions, true)) {
        $out["command"] = (string)$pick["command"];
        $out["action"]  = (string)$pick["action"];
    }
    return $out;
}

/* ====================================================================== */
/* CHIM-bound helpers (RelDyn, database, globals)                          */
/* ====================================================================== */

const JEV_BODY_PLUGIN_ID = "jev_tactical_body";

/**
 * Is RelDyn there to give a body? RelDyn loads its own classes in its prerequest hook, which runs after this plugin's, so when the
 * classes are not loaded yet and RelDyn is installed next to this plugin its main file is loaded here (it is require_once'd by RelDyn too).
 */
function jev_body_available(): bool
{
    if (!class_exists("RelDynBody", false)) {
        $file = dirname(__DIR__, 2) . "/relationship_dynamics/relationship_dynamics.php";
        if (is_file($file)) {
            try {
                require_once $file;
            } catch (Throwable $e) {
                Logger::warn("[JEV] RelDyn could not be loaded for the body: " . $e->getMessage());
                return false;
            }
        }
    }
    return class_exists("RelDynBody", false) && class_exists("RelationshipDynamics", false)
        && method_exists("RelationshipDynamics", "jevStateBlock") && method_exists("RelDynBody", "pick");
}

/** Request types on which the body is asked: the player speaking to the NPC (JEV_BODY_TYPES, conf.php only). */
function jev_body_request_types(): array
{
    $raw = (string)($GLOBALS["JEV_BODY_TYPES"] ?? "inputtext,inputtext_s,ginputtext,ginputtext_s,rechat");
    return array_values(array_filter(array_map("trim", explode(",", strtolower($raw)))));
}

function jev_body_thresholds(): array
{
    return ["min_confidence" => floatval($GLOBALS["JEV_MIN_CONFIDENCE"] ?? 0.6), "voice_at" => 0.5];
}

/** Seconds since this NPC's body was last asked of Jev (null = never), kept in its own plugin namespace so the goal's is untouched. */
function jev_body_seconds_since(array $npcData, NpcMaster $npcMaster): ?int
{
    $id = jev_tactical_npc_id($npcData);
    if ($id <= 0) {
        return null;
    }
    $data = $npcMaster->getPluginData($id, JEV_BODY_PLUGIN_ID);
    return is_array($data) && isset($data["at"]) ? max(0, time() - intval($data["at"])) : null;
}

function jev_body_stamp(array $npcData, NpcMaster $npcMaster): void
{
    $id = jev_tactical_npc_id($npcData);
    if ($id > 0) {
        $npcMaster->setPluginData($id, JEV_BODY_PLUGIN_ID, ["at" => time()]);
    }
}

/**
 * The request hook (prerequest.php calls it before the tactical tick): on a player's word to the NPC, once per
 * JEV_BODY_MIN_INTERVAL seconds, with RelDyn loaded and a body to show, ask Jev which cue fires and hand it to RelDyn.
 * Never ends the request: the NPC answers as usual, now with the cue in its felt text.
 */
function jev_body_prerequest(array $gameRequest): void
{
    if (empty($GLOBALS["JEV_BODY_ENABLED"]) || !jev_body_available()) {
        return;
    }
    if (!in_array(strtolower((string)($gameRequest[0] ?? "")), jev_body_request_types(), true)) {
        return;
    }
    $npc = (string)($GLOBALS["HERIKA_NAME"] ?? "");
    if ($npc === "" || $npc === "The Narrator" || $npc === "(actor)") {
        return;
    }
    $client = new JevClient();
    if (!$client->hasApiKey()) {
        return;
    }
    $npcMaster = new NpcMaster();
    $npcData   = $npcMaster->getByName($npc);
    if (!$npcData) {
        return;
    }
    $since    = jev_body_seconds_since($npcData, $npcMaster);
    $interval = intval($GLOBALS["JEV_BODY_MIN_INTERVAL"] ?? 20);
    if ($since !== null && $since < $interval) {
        return;
    }

    $block = RelationshipDynamics::jevStateBlock($npc);
    $body  = (array)($block["body"] ?? []);
    if (empty($body["enabled"]) || (jev_body_offered_cues($block) === [] && !is_array($body["voice"] ?? null))) {
        return;
    }
    // every offered cue still cooling down: nothing to pick this time
    $ready = array_filter(jev_body_offered_cues($block), fn($c) => floatval($body["cues"][$c]["cooldown_play_seconds"] ?? 0) <= 0);
    if ($ready === [] && !is_array($body["voice"] ?? null)) {
        return;
    }

    $goal   = jev_tactical_get_goal($npcData, $npcMaster);
    $player = (string)($GLOBALS["PLAYER_NAME"] ?? "Player");
    $d = jev_body_decide($npc, $block, $goal, $player, $client, jev_tactical_enabled_actions(), jev_body_thresholds());
    jev_body_stamp($npcData, $npcMaster);

    $GLOBALS["DEBUG_DATA"]["jev_body"] = ["request" => $client->lastRequest, "resolution" => $d["resolution"], "pick" => $d["pick"], "latency" => $client->lastLatency];
    Logger::info("[JEV] {$npc} body: " . jev_body_describe($d["resolution"]) . " in " . round($client->lastLatency, 3) . "s");

    if ($d["command"] === null) {
        return;
    }
    // The one cue with a core action: delivered inside this request's response, as the tactical acts are
    echo $d["command"] . "\r\n";
    if (ob_get_level()) {
        @ob_flush();
    }
    @flush();
    $GLOBALS["db"]->insert('actions_issued', [
        'action'    => $d["action"],
        'fullcall'  => $d["command"],
        'actorname' => $npc,
        'ts'        => $gameRequest[1] ?? time(),
        'gamets'    => $gameRequest[2] ?? 0,
        'localts'   => time(),
        'original'  => 'jev_body',
    ]);
    $event    = $gameRequest;
    $event[0] = "infoaction";
    $event[3] = jev_body_event_text($npc, $player, (string)$d["resolution"]["cue"]) . " (body language)";
    logEvent($event, $npc);
}
