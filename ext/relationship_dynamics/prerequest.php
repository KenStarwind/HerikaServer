<?php
/**
 * Relationship Dynamics — Prerequest Hook
 *
 * Runs after other extension prerequest hooks (alphabetical order).
 * Handles: play clock (game time), passion decay, jealousy decay, reunion spike,
 * effective disposition calculation, blush multiplier.
 */

if (!isset($GLOBALS['gameRequest']) || !is_array($GLOBALS['gameRequest'])) {
    return;
}

// Skip non-dialogue events
$reqType = $GLOBALS['gameRequest'][0] ?? '';
if ($reqType === 'maras_sync') {
    return;
}

// ========== CORE'S REQUEST POLL (prerequest-on-poll) ==========
// The plugin's poll for queued responses (every POLINT real seconds) reaches this hook only:
// comm.php ends it before context_pre / context / postrequest. It carries no NPC, whatever
// HERIKA_NAME conf.php left, so no bond is touched: the save-load reconcile and the play
// heartbeat beat only (RelationshipDynamics::onPollRequest, config 'poll').
require_once __DIR__ . '/relationship_dynamics.php';
if (RelationshipDynamics::isPollRequest($GLOBALS['gameRequest'])) {
    RelationshipDynamics::onPollRequest();
    return;
}

// ========== SAVE LOAD (save-load-rollback) ==========
// 'init' = the player loaded a save. This hook runs BEFORE core's comm.php prunes the later
// eventlog and restores every NPC row from its history snapshot, so nothing here may touch
// RelDyn state: stash it (keep policy, pending evals) and stop. The reconcile runs on the
// first RelDyn entry after core's restore (below, and in the eval worker).
if ($reqType === 'init') {
    require_once __DIR__ . '/relationship_dynamics.php';
    RelationshipDynamics::beginRequest();
    // Bank the play in the rows core is about to prune (gamets at or after the loaded time):
    // the player did play it, and play clocks are kept across loads.
    try {
        if (RelationshipDynamics::isEnabled()) {
            RelationshipDynamics::beatPlayClock();
        }
    } catch (Throwable $e) {
        RelationshipDynamics::logError('play heartbeat before core restore', $e);
    }
    try {
        RelDynTimeline::beforeCoreLoad(floatval($GLOBALS['gameRequest'][2] ?? 0));
    } catch (Throwable $e) {
        RelationshipDynamics::logError('save-load stash before core restore', $e);
    }
    RelationshipDynamics::endRequest();
    return;
}

// Skip NPC-to-NPC radiant dialogue — player isn't involved.
// CHIM core's relationship_system handles NPC↔NPC affinity.
// Ambient trickle/decay would be wasted work since these NPCs
// aren't interacting with the player.
require_once __DIR__ . '/relationship_dynamics.php';
if (RelationshipDynamics::isRadiantRequest($GLOBALS['gameRequest'])) {
    return;
}

// Skip narrator
$npcName = $GLOBALS['HERIKA_NAME'] ?? '';
if (empty($npcName) || $npcName === 'The Narrator') {
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';
// Each hook is its own request scope: config/bond caches never outlive it (A3).
RelationshipDynamics::beginRequest();

if (!RelationshipDynamics::isEnabled()) {
    return;
}

// A save was loaded since RelDyn last ran: drop what the load discarded, rebaseline the game
// clocks, keep or follow core's restore, re-read core's affinity. Once per load, before
// anything below reads or writes RelDyn state.
try {
    $reldynReconcile = RelDynTimeline::reconcileIfLoaded();
    if (!empty($reldynReconcile['deferred'])) {
        // Core is still restoring the NPC rows for that load: this entry leaves RelDyn state
        // alone (saves are refused until the reconcile, RelDynTimeline::saveGate)
        RelationshipDynamics::endRequest();
        return;
    }
} catch (Throwable $e) {
    RelationshipDynamics::logError('save-load reconcile', $e);
}

// ========== GLOBAL PLAY HEARTBEAT ==========
// Played game time across the whole game, read from core's eventlog game clock (waits,
// sleeps, fast travel and loads cut out, no real time); bounds each NPC's play-clock credit
// in updatePlayTime() below.
$globalPlayGamets = RelationshipDynamics::beatPlayClock();

// ========== PLAYER GOLD LEDGER (player-stats-pipeline) ==========
// Economic footprint = gold moved, not the wallet: fold core's latest inventory snapshot
// into the ledger RelDynPlayer::profile() reads for the status pillar.
RelDynPlayer::recordGoldSnapshot();

// ========== GAME CALENDAR (decisions 2026-09-23 §2: time does not heal) ==========
// Time moves for every bond, not only this one: each NPC whose calendar step is due gets
// fester, neglect, passion fade and walkaway/hoover timers advanced. This NPC goes first,
// before its dynamics load and before contact is marked below, so its own absence counts.
RelationshipDynamics::runCalendarScan($npcName);

// Load dynamics
$dynamics = RelationshipDynamics::getDynamics($npcName);

// ========== PLAY CLOCK (game time only) ==========
// Must run FIRST -- before any decay/cooldown logic that reads the play clock.
// Credits the played game time since this NPC's last turn (bounded by the heartbeat above),
// then the same credit as play seconds (_accumulated_time, diary cooldowns).
$gametsDelta = RelationshipDynamics::updatePlayTime($dynamics, null, $globalPlayGamets);
$playGametsTotal = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
$timeDelta = RelationshipDynamics::updateAccumulatedTime($dynamics, $gametsDelta);
if ($gametsDelta > 0) {
    $accumulatedTotal = round(floatval($dynamics['_accumulated_time'] ?? 0), 1);
    RelationshipDynamics::log("[RelDyn-GAMETS] {$npcName}: +{$gametsDelta} play gamets (+" . round($timeDelta, 1) . " play s), total: {$playGametsTotal} play_gamets, {$accumulatedTotal} play s");
}

// Auto-generate love language if missing
RelationshipDynamics::ensureLoveLanguage($npcName, $dynamics);

// Load config toggles
$reldynCfg = RelationshipDynamics::getConfig();

// Apply time-based decays
if ($reldynCfg['passion_enabled'] ?? true) {
    RelationshipDynamics::decayPassion($dynamics);
}
if ($reldynCfg['jealousy_enabled'] ?? true) {
    RelationshipDynamics::decayJealousy($dynamics);
}

// ========== CONSUMABLE EXPIRY TICK (PR 8) ==========
// Reverse immediate effects of expired consumables (game-time based)
$expiredCount = RelationshipDynamics::tickConsumableExpiry($dynamics);
if ($expiredCount > 0) {
    RelationshipDynamics::log("Consumable expiry: {$expiredCount} expired for {$npcName}");
}

// Check reunion spike
$npcAffection = 0; // Default — no relationship means no reunion spike
try {
    $db = $GLOBALS['db'] ?? null;
    if ($db) {
        $escaped = $db->escape($npcName);
        $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
        if (is_array($row) && !empty($row['extended_data'])) {
            $ext = json_decode($row['extended_data'], true) ?: [];
            $playerRel = RelationshipDynamics::getPlayerRelationshipFromExtended($ext); // CHIM 3.4.1 key "Player"
            if ($playerRel) {
                // Use raw CHIM affinity (-100..+100) directly
                // reunion_min_affection default 40 means CHIM aff >= 40 (Friendly+)
                $npcAffection = intval($playerRel['aff'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    // Use default
    error_log("[RelDyn-PRE] Reunion affinity read failed for {$npcName}: " . $e->getMessage());
}

// Snapshot current CHIM affinity for RPM→Speed delta in postrequest
$GLOBALS['RELDYN_PRE_AFF'] = null;
// Core's Player.type as of this NPC's last request (the romance ownership guard below compares)
$reldynPrevCoreType = $dynamics['_core_rel_type'] ?? null;
try {
    $db2 = $GLOBALS['db'] ?? null;
    if ($db2) {
        $escaped2 = $db2->escape($npcName);
        $row2 = $db2->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped2}') LIMIT 1");
        if (is_array($row2) && !empty($row2['extended_data'])) {
            $ext2 = json_decode($row2['extended_data'], true) ?: [];
            $pRel = RelationshipDynamics::getPlayerRelationshipFromExtended($ext2);
            // No Player entry yet = core's default neutral stranger (aff 0)
            $GLOBALS['RELDYN_PRE_AFF'] = intval($pRel['aff'] ?? 0);
            // Core Player.type is the one relationship type; getRelationshipType() maps it
            RelationshipDynamics::setCoreRelationshipType($dynamics, $pRel['type'] ?? 'neutral');
        }
    }
} catch (Throwable $e) {
    // Best effort
    error_log("[RelDyn-PRE] Affinity snapshot read failed for {$npcName}: " . $e->getMessage());
}

// ========== CHIM → RelDyn AFFINITY DIMENSION SYNC ==========
// CHIM owns aff (-100..+100); RelDyn XYZ uses dimensions.affinity.x (0..100) as a
// read-only mirror. Refresh it once per prerequest so dimension consumers see the
// value core holds (including core eval's changes). RelDyn's own changes to x are
// pushed to core by commitPlayerAffinity() before each save.
if (!empty($reldynCfg['dimension_engine_enabled'])) {
    $chimAff = $GLOBALS['RELDYN_PRE_AFF'];
    if ($chimAff !== null) {
        RelationshipDynamics::refreshAffinityMirror($dynamics, $chimAff);  // -100→0, 0→50, 100→100
    }
}
// Conflict from an affinity drop within a session, whoever lowered Player.aff (core eval too)
if (($reldynCfg['conflict_enabled'] ?? true) && $GLOBALS['RELDYN_PRE_AFF'] !== null) {
    RelationshipDynamics::observeCoreAffinity($dynamics, floatval($GLOBALS['RELDYN_PRE_AFF']), RelationshipDynamics::currentGamets());
}

// ========== FULFILLMENT (rulings 2026-09-24 §9: neglect = absence of fulfillment) ==========
// Needs vector refreshed on contact, day-end samples, unfulfilled neglect, the mature boundary
// (a failed probation steps core's type back), and the band this contact leaves behind for
// the next absence. After core's type snapshot above, before weather and contact below.
RelationshipDynamics::advanceFulfillment($npcName, $dynamics, RelationshipDynamics::currentGamets(), true);

// The reunion is measured before contact is marked; its passion is applied after this
// request's attraction (below): a reunion spike is a passion gain like any other (rulings §11)
$reunionPassion = ($reldynCfg['reunion_enabled'] ?? true) ? RelationshipDynamics::checkReunion($dynamics, $npcAffection) : 0;
// Protective concern (traits design §1): eases while together; on a return, what the NPC can
// perceive of the time apart (route B); the values boundary's probation and step-back. Before
// contact is marked: it measures the time since the previous contact.
RelDynConcern::onContact($npcName, $dynamics, RelationshipDynamics::currentGamets(), trim((string) ($GLOBALS['PLAYER_NAME'] ?? 'Player')));
// Contact now (game calendar + play clock): absence, neglect and the next reunion count from here
RelationshipDynamics::markContact($dynamics);

// Ambient presence (MDD 1.5 Points of Interest, decisions §6) runs in context.php:
// the place read needs core's CACHE_LOCATION, which main.php sets after these hooks.

// -------------------------------------------------------------------------
// Physical State Bridges (PR 8): detect physical conditions from core data
// (weather outside, time of day) and apply/clear temporary dimension modifiers.
// Gated behind dimension_engine_enabled.
// -------------------------------------------------------------------------
if (!empty($reldynCfg['dimension_engine_enabled'])) {
    $physPlayerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
    $physTemperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';

    // Detect current physical states from game signals
    $activePhysStates = RelationshipDynamics::detectPhysicalStates($npcName, $physPlayerName);

    // Clear modifiers for states that are no longer active (reverse deltas)
    RelationshipDynamics::clearPhysicalStateModifiers($dynamics, $activePhysStates, $physTemperament);

    // Apply modifiers for newly detected states
    if (!empty($activePhysStates)) {
        RelationshipDynamics::applyPhysicalStateModifiers($dynamics, $activePhysStates, $physTemperament);
    }

    // Store active states in globals for context.php to reference
    $GLOBALS['RELDYN_ACTIVE_PHYS_STATES'] = $activePhysStates;

    // Environmental modifiers: the core place's facets (danger, dark) and the hour (dawn,
    // dusk) move arousal / comfort / mood for anyone; re-applied only when they change.
    RelationshipDynamics::applyEnvironmentalModifiers($dynamics, $npcName, $physPlayerName, $physTemperament);
}

// ========== ATTRACTION MATRIX (MDD §2, decisions §9) ==========
// Every request: the player profile (skills, deeds, economic footprint) and the NPC's own
// pillar definitions -> passes / friendzone label / tier ceiling / passion curve (decisions §13).
// Stored in $dynamics['_attraction'] for postrequest, context, the eval consumer and
// getRelationshipType. Off: nothing is gated.
RelationshipDynamics::updateAttraction($npcName, $dynamics);
if ($reunionPassion > 0) {
    RelationshipDynamics::gainPassion($npcName, $dynamics, $reunionPassion, 'reunion');
}

// ========== ROMANCE OWNERSHIP (rulings §9: RelDyn owns romance promotion) ==========
// A romance type core wrote since this NPC's last request without RelDyn (core's MODE 2 #TYPE
// tags have no earned-romance gate) that this request's Attraction Matrix does not allow is
// stepped back to the type before it, so Sharmat never sees a friendzoned / unattracted NPC
// as romantic. relationships_locked (the editor's manual edits) is respected.
RelDynRomance::guardCorePromotion($npcName, $dynamics, $reldynPrevCoreType);

// ========== DUTY OVERRIDE (PR 12) ==========
if (!empty($reldynCfg['duty_override_enabled'])) {
    $dutyFactor = RelationshipDynamics::getDutyOverrideFactor();
    $GLOBALS['RELDYN_DUTY_FACTOR'] = $dutyFactor;
    if ($dutyFactor < 1.0) {
        RelationshipDynamics::log("[RelDyn-PRE] Duty override active: factor=" . round($dutyFactor, 2));
    }
}

// ========== INTERNAL WEATHER + CREATURE MODIFIERS (PR 13) ==========
if (!empty($reldynCfg['internal_weather_enabled'])) {
    // Weather state only (pressure, daily roll, deprivation on the game calendar): no core
    // caches needed. The place appraisal that feeds it runs in context.php, after core has
    // set CACHE_LOCATION / CACHE_PEOPLE.
    RelationshipDynamics::updateInternalWeather($npcName, $dynamics);
    $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
    RelationshipDynamics::applyWeatherModifiers($npcName, $dynamics, $temperament);
}

// ========== CREATURE MOODIFICATIONS (feedback_creature_moodifications, decisions §7) ==========
// Vampires by night / day, werewolves by Skyrim's moon: the row's offsets are held while it
// holds and taken back when it changes (or when creature_moodifications_enabled is off); a
// return from beast form (core's transformation report, the form watch) is the shame.
RelDynCreatures::observeForms();
$temperament = $temperament ?? ($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic');
RelDynCreatures::update($npcName, $dynamics, $temperament);

// ========== SOCIAL MASKING (PR 14) ==========
if (!empty($reldynCfg['social_masking_enabled'])) {
    $isMasking = RelationshipDynamics::shouldMask($npcName, $dynamics);
    $dynamics['_was_masking'] = $isMasking;

    if ($isMasking) {
        RelationshipDynamics::applyMaskingCost($npcName, $dynamics);
        $performedState = RelationshipDynamics::calculatePerformedState($dynamics);
        $dynamics['_performed_state_cache'] = $performedState;
        $GLOBALS['RELDYN_MASKING_ACTIVE'] = true;
        $GLOBALS['RELDYN_PERFORMED_STATE'] = $performedState;
    } else {
        $dynamics['_performed_state_cache'] = null;
        $GLOBALS['RELDYN_MASKING_ACTIVE'] = false;
    }
}

// ========== ICK RECOVERY CHECK (PR 15) ==========
if (!empty($reldynCfg['ick_system_enabled'] ?? true)) {
    $ickCleared = RelationshipDynamics::checkIckRecovery($dynamics);
    if ($ickCleared) {
        RelationshipDynamics::log("[RelDyn-PRE] Ick cleared for {$npcName}");
    }
    $GLOBALS['RELDYN_ICK_ACTIVE'] = !empty($dynamics['_ick_tracker']['ick_active']);
}

// ========== AUTONOMY OVERRIDE + WALKAWAY (PR 16) ==========
if (!empty($reldynCfg['autonomy_enabled'] ?? true)) {
    $autoTemperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
    $autonomyEval = RelationshipDynamics::evaluateAutonomyState($dynamics, $autoTemperament);
    $GLOBALS['RELDYN_AUTONOMY_STATE'] = $autonomyEval['state'];
    $GLOBALS['RELDYN_AUTONOMY_EVAL'] = $autonomyEval;

    // People-pleaser internalization: build resentment_self
    if ($autonomyEval['people_pleaser'] && $autonomyEval['resentment_self_buildup'] > 0) {
        RelationshipDynamics::applyDelta('resentment_self', $dynamics, $autonomyEval['resentment_self_buildup'], $autoTemperament);
    }

    // Action list filtering for refusing/walkaway states
    $deniedActions = $autonomyEval['deny_actions'];
    if (!empty($deniedActions) && function_exists('unsetFunction')) {
        foreach ($deniedActions as $actionName) {
            unsetFunction($actionName);
        }
        RelationshipDynamics::log("[RelDyn-PRE] Autonomy action filter: state={$autonomyEval['state']}, denied=" . implode(',', $deniedActions));
    }

    // Initiate walkaway if state demands it and not already walking away
    $currentWalkState = $dynamics['_walkaway_state'] ?? 'normal';
    if ($autonomyEval['state'] === 'walkaway' && $currentWalkState === 'normal') {
        // Why they leave; 'neglect' when it starts on the return from an absence (rulings §8)
        RelationshipDynamics::initiateWalkaway($dynamics, $npcName, RelationshipDynamics::walkawayReason($dynamics));
    }

    // Process walkaway tick if in walkaway state
    if (!empty($dynamics['_walkaway_state']) && $dynamics['_walkaway_state'] !== 'normal') {
        $isDialogue = in_array($reqType, ['inputtext', 'inputtext_s', 'ginputtext']);
        // A resolved boundary test returns the NPC and clears the walkaway (not the resentment)
        RelationshipDynamics::resolveWalkawayTick($dynamics, $npcName, $autoTemperament, $isDialogue);

        $GLOBALS['RELDYN_WALKAWAY_STATE'] = $dynamics['_walkaway_state'] ?? 'normal';
    }
}

// ========== HOOVER CHECK (PR 16) ==========
if (!empty($reldynCfg['hoover_enabled'] ?? true)) {
    if (RelationshipDynamics::checkHooverEligibility($dynamics)) {
        $hooverTemperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $hooverResult = RelationshipDynamics::executeHoover($dynamics, $npcName, $hooverTemperament);
        RelationshipDynamics::log("[RelDyn-PRE] Hoover executed for {$npcName}: " . json_encode($hooverResult));
        $GLOBALS['RELDYN_HOOVER_ACTIVE'] = true;
    }
}

// ========== AUTONOMOUS DIARY TRIGGER (PR 14) ==========
if (!empty($reldynCfg['autonomous_diary_enabled'])) {
    $diaryTriggered = RelationshipDynamics::checkDiaryTrigger($npcName, $dynamics, 'interaction');
    if ($diaryTriggered) {
        $GLOBALS['RELDYN_DIARY_TRIGGERED'] = true;
    }
}

// ========== UNSTABLE WINDOW CHECK (PR 10) ==========
if (!empty($reldynCfg['divine_intervention_enabled'])) {
    $unstableWindow = $dynamics['_unstable_window'] ?? null;
    if ($unstableWindow && empty($unstableWindow['resolved'])) {
        $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
        $windowResult = RelationshipDynamics::checkUnstableWindow($npcName, $dynamics, $playerName);
        if ($windowResult && $windowResult !== 'active') {
            RelationshipDynamics::log("[RelDyn-PRE] Unstable window resolved: {$windowResult} for {$npcName}");
        }
    }
}

// ========== GRIEF PHASE PROCESSING (PR 10) ==========
if (!empty($reldynCfg['grief_system_enabled'] ?? true)) {
    RelationshipDynamics::processGriefPhases($npcName, $dynamics);
}

// ========== ATTACHMENT SHIFT (PR 10) ==========
if (!empty($reldynCfg['attachment_style_enabled'] ?? true)) {
    if (!empty($dynamics['_attachment_shift_available'])) {
        $shiftResult = RelationshipDynamics::processAttachmentShift($dynamics);
        if ($shiftResult) {
            RelationshipDynamics::log("[RelDyn-PRE] Attachment shift: {$npcName} -> {$shiftResult}");
        }
    }
}

// ========== AFFINITY DECAY WIRING (PR 10) ==========
// Absence ticks on the game calendar since the last turn with this NPC (consumed here).
// PR 16: Pause affinity decay during walkaway (NPC chose to leave, not forgotten): the
// paused interval is dropped, not banked for after the walkaway resolves. calculateDecayTicks
// leaves exactly the walkaway interval out, so a walkaway that starts on this turn does not
// swallow the absence before it.
if (!empty($reldynCfg['dimension_engine_enabled'])) {
    $decayTicks = RelationshipDynamics::calculateDecayTicks($dynamics);
    if ($decayTicks > 0.001) {
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        // Same type every other consumer reads: core Player.type (snapshotted above), mapped
        $relType = RelationshipDynamics::getRelationshipType($npcName, $dynamics);

        $decayResult = RelationshipDynamics::processAffinityDecay($dynamics, $npcName, $temperament, $relType, $decayTicks);
        if ($decayResult && !($decayResult['skipped'] ?? false)) {
            RelationshipDynamics::log("[RelDyn-DECAY-WIRED] {$npcName}: decay=" . round($decayResult['decay_amount'], 2));
        }
    }
}

// Effective disposition: the MDD overlay (passion x 0.3 - jealousy x 0.3) on Sharmat's own
// arousal (nsfw_npc_data aiagent_nsfw_intimacy_data.sex_disposal), read only; 0 without Sharmat.
$sharmatArousal = RelDynRomance::sharmatArousal($npcName);
$effectiveDisposal = RelationshipDynamics::getEffectiveDisposition($sharmatArousal ?? 0, $dynamics);

// Snapshot NPC name AND player name for postrequest (processor/postrequest.php re-requires
// conf.php which resets HERIKA_NAME to 'The Narrator' and PLAYER_NAME to 'Prisoner')
$GLOBALS['RELDYN_NPC_NAME'] = $npcName;
$GLOBALS['RELDYN_PLAYER_NAME'] = $GLOBALS['PLAYER_NAME'] ?? 'Player';

// Store effective disposal for other extensions to read
$GLOBALS['RELDYN_EFFECTIVE_DISPOSAL'] = $effectiveDisposal;
$GLOBALS['RELDYN_PASSION'] = floatval($dynamics['passion']);
$GLOBALS['RELDYN_JEALOUSY'] = floatval($dynamics['jealousy_anger']);
$GLOBALS['RELDYN_STAGE'] = $dynamics['stage'] ?? 'early';
$GLOBALS['RELDYN_LOVE_LANG_PRIMARY'] = $dynamics['love_language_primary'];
$GLOBALS['RELDYN_LOVE_LANG_SECONDARY'] = $dynamics['love_language_secondary'];

// Set blush multiplier for love language match
// (maras_bridge reads this to scale blush duration)
$GLOBALS['RELDYN_BLUSH_MULTIPLIER'] = 1.0;

// Handoff to Sharmat (rulings §9, domain split): Sharmat's consent gate reads core
// Player.type, which RelDyn's romance promotion writes. RelDyn's romantic state (passion band,
// attraction, friendzone, walkaway, conflict, effective disposition) is published to
// plugin_extended_data.reldyn.romance for a Sharmat hook; Sharmat's store is never written.
RelDynRomance::publishState($npcName, $dynamics, $dynamics['_core_rel_type'] ?? null, $sharmatArousal);

// ========== DIRECTOR GOAL — PASSIVE BRIDGE (PR 39, Step 9) ==========
// If no director goal is currently active, check CHIM's HERIKA_GOALS for
// Director/SNQE-assigned goals and bridge them into the RelDyn goal system.
// HERIKA_GOALS is loaded by npc_master.class.php from the goals column
// (combined_bio_templates) and may contain SNQE quest-injected goals.
if (!empty($reldynCfg['director_goals_enabled'] ?? true)) {
    $existingGoal = RelationshipDynamics::getActiveDirectorGoal($dynamics);
    if (!$existingGoal) {
        $chimGoals = trim($GLOBALS['HERIKA_GOALS'] ?? '');
        if (!empty($chimGoals)) {
            // Only bridge if the goals text has changed since last bridge
            // (avoids re-setting the same static bio goal every tick)
            $lastBridgedHash = $dynamics['_director_goal_last_bridge_hash'] ?? '';
            $currentHash = md5($chimGoals);
            if ($currentHash !== $lastBridgedHash) {
                RelationshipDynamics::setDirectorGoal(
                    $dynamics,
                    $chimGoals,
                    'director',       // source: CHIM Director/SNQE
                    7200,             // 2h gamets — director quests are longer-lived
                    0.4               // moderate priority — bio goals are background
                );
                $dynamics['_director_goal_last_bridge_hash'] = $currentHash;
                RelationshipDynamics::log("[RelDyn-PRE] Bridged CHIM goals -> director goal for {$npcName}: " . substr($chimGoals, 0, 80));
            }
        }
    }
}

// ========== DIRECTOR GOAL EXPIRY CHECK (PR 39, Step 3) ==========
if (!empty($reldynCfg['director_goals_enabled'] ?? true)) {
    $activeGoal = RelationshipDynamics::getActiveDirectorGoal($dynamics);
    if ($activeGoal) {
        $currentGamets = RelationshipDynamics::getPlayGamets($dynamics);
        $goalAge = $currentGamets - floatval($activeGoal['created_gamets'] ?? 0);
        $maxAge = floatval($activeGoal['max_age_gamets'] ?? 3600);
        if ($goalAge > $maxAge) {
            RelationshipDynamics::expireDirectorGoal($dynamics);
        }
    }
    $GLOBALS['RELDYN_DIRECTOR_GOAL'] = RelationshipDynamics::getActiveDirectorGoal($dynamics);
}

// Push RelDyn's affinity change (absence decay) to core as a locked delta
RelationshipDynamics::commitPlayerAffinity($npcName, $dynamics);

// Save dynamics (decay + reunion applied)
RelationshipDynamics::saveDynamics($npcName, $dynamics);
