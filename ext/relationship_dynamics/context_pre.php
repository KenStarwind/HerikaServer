<?php
/**
 * Relationship Dynamics — Context Pre Hook (primacy position)
 *
 * CHIM 3.4.1 main.php requires every ext/<plugin>/context_pre.php (requireFilesRecursively,
 * alphabetical, so this runs before ext/relationship_system's) right before it builds the
 * system prompt; HERIKA_PERS becomes the <character> block. context-pre-steering
 * (feedback_context_engineering_v2): what lands here anchors the model before the dialogue.
 *
 * Adds to HERIKA_PERS (config felt_steering.position: after the character's bio by default):
 *   <knowledge_of_player>  what this NPC knows of the player, by context tier (a stranger knows
 *                          only what can be seen and not the name, unless core's relationship
 *                          block names the player anyway; acquaintance, friend, bonded,
 *                          a lapsed friendship held by the high-water mark), plus one tension
 *                          bridge. Skipped when another plugin already wrote one (the P3
 *                          prompt-gating port is the consolidation point).
 *   <emotional_core>       the most salient enduring felt lines (RelDynFelt::splitCore).
 * The rest of the lines go to context.php's <subtext> (recency). Toggle: context_pre_enabled.
 * NPC-to-NPC radiant requests get none of it (the player is not in the exchange).
 */

$npcName = $GLOBALS['HERIKA_NAME'] ?? '';
if (empty($npcName) || $npcName === 'The Narrator') {
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';

// NPC-to-NPC radiant dialogue: the player is not in this exchange (prerequest skips it too), so
// no player-directed steering, and the player's one-shots (blush, boundary) wait for the player.
if (RelationshipDynamics::isRadiantRequest($GLOBALS['gameRequest'] ?? null)) {
    unset($GLOBALS[RelDynFelt::HANDOFF_GLOBAL]);
    return;
}

// Each hook is its own request scope: config/bond caches never outlive it (A3).
RelationshipDynamics::beginRequest();

if (!RelationshipDynamics::isEnabled()) {
    unset($GLOBALS[RelDynFelt::HANDOFF_GLOBAL]);
    return;
}

RelDynFelt::contextPre($npcName, (string) ($GLOBALS['PLAYER_NAME'] ?? 'Player'));
