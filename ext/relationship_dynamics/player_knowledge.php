<?php
/**
 * Relationship Dynamics — registration for the CHIM fork hook that gates the player in core's
 * prompt (lib/relationship_manager.php chimPlayerKnowledgeFor(): the player's bio in the nearby
 * actors, the player's line in core's relationship block, the familiarity note on the player's
 * nearby entry). Decisions §18 #6: strangers don't know the player's name.
 *
 * Core loads this file itself (every ext/<name>/player_knowledge.php) the first time it asks. The
 * RelDyn classes load only then. RelDynGating::coreGate() decides; null (the Narrator, RelDyn or
 * prompt gating off) leaves core's behaviour as it is.
 */

$GLOBALS['CHIM_PLAYER_KNOWLEDGE_GATES']['reldyn'] = static function (string $npcName): ?array {
    require_once __DIR__ . '/relationship_dynamics.php';
    return RelDynGating::coreGate($npcName);
};
