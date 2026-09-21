<?php
/**
 * Jev Tactical Layer - command line dry run. Needs no database and no CHIM configuration.
 *
 *   php ext/jev_tactical/dryrun.php                 print the request Jev would receive for a sample scene
 *   php ext/jev_tactical/dryrun.php --live          also send it (TYPESAFE_API_KEY or --key=...) and print the decision
 *   php ext/jev_tactical/dryrun.php --scene=f.json  use your own scene (same keys as the sample below)
 */

require_once __DIR__ . "/lib/jev_client.php";
require_once __DIR__ . "/lib/jev_tactical.php";

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$scene = [
    "npc_name"    => "Erik",
    "player_name" => "Dragonborn",
    "goal"        => [
        "goal"          => "Guard the doorway while Dragonborn searches the room",
        "rules"         => "Stay near the doorway. Do not start fights. Respond to threats aimed at Dragonborn or Erik.",
        "set_localts"   => time() - 120,
        "issued"        => 1,
        "last_decision" => "WaitHere [confidence 0.88]",
        "last_result"   => "",
    ],
    "situation"   => [
        "npc_name"         => "Erik",
        "player_name"      => "Dragonborn",
        "location"         => "Bleak Falls Barrow",
        "stats"            => ["level" => 12, "health" => 94, "health_max" => 100, "magicka" => 40, "magicka_max" => 50, "stamina" => 80, "stamina_max" => 100],
        "actor"            => ["Dragonborn", "Bandit", "Bandit Marauder"],
        "hostile"          => ["Bandit Marauder"],
        "actors_annotated" => ["Dragonborn (searching a chest, 8m)", "Bandit (neutral, unaware, 11m)", "Bandit Marauder (hostile, approaching Dragonborn, 16m)"],
        "location_list"    => ["Riverwood", "Whiterun"],
        "nearby_item"      => ["0x12345:Iron Sword"],
        "items_annotated"  => ["0x12345:Iron Sword"],
        "inventory"        => ["Potion of Minor Healing", "Steel Dagger"],
        "spell"            => ["Healing", "Flames"],
        "recent_events"    => ["Dragonborn: Keep watch while I search the room. Don't attack unless something threatens us.", "Erik decides: WaitHere (tactical layer)"],
        "trigger"          => ["type" => "bored"],
    ],
];

if (!empty($args["scene"]) && is_string($args["scene"])) {
    $custom = json_decode((string)file_get_contents($args["scene"]), true);
    if (!is_array($custom)) {
        fwrite(STDERR, "Could not parse scene file {$args["scene"]}\n");
        exit(1);
    }
    $scene = array_replace_recursive($scene, $custom);
}

$enabled = ["Attack", "Follow", "FollowPlayer", "MoveTo", "TravelTo", "WaitHere", "ComeCloser", "Relax",
    "PickupItem", "GiveItemTo", "CastSpell", "Surrender", "SheatheWeapon"];

$candidates = jev_tactical_candidates_from_situation($scene["situation"]);
$catalog    = jev_tactical_build_catalog($enabled, $candidates);
$questions  = jev_tactical_build_questions($catalog, ["npc_name" => $scene["npc_name"], "player_name" => $scene["player_name"]]);
$state      = jev_tactical_build_state($scene["goal"], $scene["situation"], time());

$client  = new JevClient(is_string($args["key"] ?? null) ? $args["key"] : null);
$request = $client->buildRequest($state, $questions);

echo "== Request to " . JevClient::DEFAULT_URL . " ==\n";
echo json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "\nActions in catalog: " . implode(", ", array_keys($catalog)) . "\n";
echo "Questions: " . count($questions) . "\n";

if (empty($args["live"])) {
    echo "\n(add --live to send it)\n";
    exit(0);
}

if (!$client->hasApiKey()) {
    fwrite(STDERR, "No API key: set TYPESAFE_API_KEY or pass --key=...\n");
    exit(1);
}

try {
    $answers  = $client->decide($state, $questions);
    $decision = jev_tactical_resolve($answers, $catalog, ["min_confidence" => 0.6, "escalate_threshold" => 0.7]);
} catch (JevClientException $e) {
    fwrite(STDERR, "Jev call failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n== Answers (" . round($client->lastLatency, 3) . "s, usage " . json_encode($client->lastUsage) . ") ==\n";
echo json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "\n== Decision ==\n";
echo jev_tactical_describe_decision($decision) . "\n";
$command = jev_tactical_format_command($scene["npc_name"], $decision);
echo "Command line: " . ($command ?? "(none)") . "\n";
