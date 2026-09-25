<?php
/**
 * Relationship Dynamics — Action list hook (autonomy-command-denial; MDD 6.4, PR16 plan
 * "Action List Filtering", autonomy design memory)
 *
 * CHIM 3.4.1 core's functions/functions.php requires every ext/<plugin>/functions.php
 * (requireFunctionFilesRecursively) after it loaded the enabled action codes
 * ($GLOBALS['ENABLED_FUNCTIONS'], from the action catalog) and before it drops every function
 * definition whose code is not enabled. That is the one point where a plugin can take an action
 * off the LLM's list: the prerequest hooks run before prompt.includes.php defines unsetFunction()
 * and builds the list (main.php), and the list is rebuilt from the catalog anyway.
 *
 * A refusing or walking-away NPC (the prerequest's evaluateAutonomyState, this request's NPC)
 * loses the follow / trade / give actions (config autonomy.denied_actions); she can still end the
 * conversation, leave or defend herself. A people-pleaser's swallowed refusal denies nothing.
 * No RelDyn evaluation this request (disabled, radiant, a page that is not a request): no-op.
 */

if (!isset($GLOBALS['RELDYN_AUTONOMY_EVAL']) || !class_exists('RelationshipDynamics', false)) {
    return;
}

RelationshipDynamics::applyAutonomyActionFilter();
