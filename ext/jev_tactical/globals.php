<?php
/**
 * Jev Tactical Layer - defaults and library bootstrap.
 *
 * Loaded very early by main.php (requireFilesRecursively(ext, "globals.php")), before any
 * profile-specific configuration is applied. Every value here can be overridden from the
 * CHIM configuration UI (see conf/conf_schema.json) or from conf/conf.php.
 */

$jevDefaults = [
    "JEV_TACTICAL_ENABLED"              => false,
    "JEV_API_KEY"                       => "",
    "JEV_MODEL"                         => "jev-latest",
    "JEV_API_URL"                       => "https://api.typesafe.ai/v1/systemone",
    "JEV_TIMEOUT"                       => 5,      // seconds for the HTTP call
    "JEV_MIN_CONFIDENCE"                => 0.6,    // below this the tactical layer waits instead of acting
    "JEV_ESCALATE_THRESHOLD"            => 0.7,    // needs_deliberation probability that wakes the main LLM
    "JEV_LOW_CONFIDENCE_ESCALATE_AFTER" => 3,      // consecutive low-confidence ticks before waking the main LLM
    "JEV_GOAL_TTL_SECONDS"              => 900,    // a standing goal expires after this many real seconds
    "JEV_TICK_TYPES"                    => "funcret,bored,jev_tick",
];

foreach ($jevDefaults as $jevKey => $jevValue) {
    if (!isset($GLOBALS[$jevKey]) || $GLOBALS[$jevKey] === "") {
        $GLOBALS[$jevKey] = $jevValue;
    }
}
unset($jevDefaults, $jevKey, $jevValue);

require_once __DIR__ . "/lib/jev_client.php";
require_once __DIR__ . "/lib/jev_tactical.php";
