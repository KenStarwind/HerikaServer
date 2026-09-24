<?php
/**
 * Relationship Dynamics — Context Hook (recency position)
 *
 * main.php requires every ext/<plugin>/context.php right after the system prompt is built;
 * what this hook appends to contextDataFull lands after the dialogue history, right before
 * the request: the recency position (feedback_context_engineering_v2).
 *
 * RelDyn adds exactly one block here, <subtext>: the NPC's current state as felt steering
 * (behavioral keywords, subtext, intensity formatting; never numbers, never "you feel that you
 * trust the player": decisions 2026-09-23 §3), tiered and token-budgeted (RelDynFelt). When
 * context_pre.php ran this request it already composed every line, put <knowledge_of_player>
 * and the emotional core into <character>, and handed the remaining lines over; otherwise the
 * lines are composed here and <subtext> carries all of them.
 */

$npcName = $GLOBALS['HERIKA_NAME'] ?? '';
if (empty($npcName) || $npcName === 'The Narrator') {
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';
// Each hook is its own request scope: config/bond caches never outlive it (A3).
RelationshipDynamics::beginRequest();

if (!RelationshipDynamics::isEnabled()) {
    unset($GLOBALS[RelDynFelt::HANDOFF_GLOBAL]);
    return;
}

RelDynFelt::contextPost($npcName, (string) ($GLOBALS['PLAYER_NAME'] ?? 'Player'));
