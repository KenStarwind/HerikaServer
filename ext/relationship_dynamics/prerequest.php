<?php
/**
 * Relationship Dynamics — Prerequest Hook
 *
 * Runs after other extension prerequest hooks (alphabetical order).
 * Handles: accumulated time tracking, passion decay, jealousy decay, reunion spike,
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

// Skip NPC-to-NPC radiant dialogue — player isn't involved.
// CHIM core's relationship_system handles NPC↔NPC affinity.
// Ambient trickle/decay would be wasted work since these NPCs
// aren't interacting with the player.
$radiantTypes = ['radiant', 'radiantsearchingfriend', 'radiantsearchinghostile',
    'radiantcombathostile', 'minai_force_rechat'];
if (in_array($reqType, $radiantTypes)) {
    return;
}

// Skip narrator
$npcName = $GLOBALS['HERIKA_NAME'] ?? '';
if (empty($npcName) || $npcName === 'The Narrator') {
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';

if (!RelationshipDynamics::isEnabled()) {
    return;
}

// Load dynamics
$dynamics = RelationshipDynamics::getDynamics($npcName);

// ========== ACCUMULATED TIME TRACKING ==========
// Must run FIRST -- before any decay/cooldown logic that depends on accumulated time
$timeDelta = RelationshipDynamics::updateAccumulatedTime($dynamics);
$accumulatedTotal = intval($dynamics['_accumulated_time'] ?? 0);
$accumulatedMinutes = round($accumulatedTotal / 60.0, 1);
if ($timeDelta > 0) {
    RelationshipDynamics::log("[RelDyn-TIME] {$npcName}: +{$timeDelta}s, total: {$accumulatedTotal}s ({$accumulatedMinutes}min)");
}

// ========== GAMETS PLAY TIME TRACKING ==========
// Must run alongside accumulated time -- before any decay/cooldown logic.
// Reads gamets from $gameRequest[2] (set by CHIM from Skyrim game clock).
// Filters out wait/sleep by comparing gamets/real-time ratio.
$gametsDelta = RelationshipDynamics::updatePlayTime($dynamics);
$playGametsTotal = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
if ($gametsDelta > 0) {
    RelationshipDynamics::log("[RelDyn-GAMETS] {$npcName}: +{$gametsDelta} gamets, total: {$playGametsTotal} play_gamets");
}

// Auto-generate love language if missing
RelationshipDynamics::ensureLoveLanguage($npcName, $dynamics);

// Auto-generate interest vector if missing (~8ms, fires once per NPC)
RelationshipDynamics::getInterestVector($dynamics);

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
        $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
        $escaped = $db->escape($npcName);
        $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
        if (is_array($row) && !empty($row['extended_data'])) {
            $ext = json_decode($row['extended_data'], true) ?: [];
            $playerRel = $ext['relationships'][$playerName] ?? null;
            if ($playerRel) {
                // Use raw CHIM affinity (-100..+100) directly
                // reunion_min_affection default 40 means CHIM aff >= 40 (Friendly+)
                $npcAffection = intval($playerRel['aff'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    // Use default
}

// Snapshot current CHIM affinity for RPM→Speed delta in postrequest
$GLOBALS['RELDYN_PRE_AFF'] = null;
try {
    $db2 = $GLOBALS['db'] ?? null;
    if ($db2) {
        $playerName2 = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
        $escaped2 = $db2->escape($npcName);
        $row2 = $db2->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped2}') LIMIT 1");
        if (is_array($row2) && !empty($row2['extended_data'])) {
            $ext2 = json_decode($row2['extended_data'], true) ?: [];
            $pRel = $ext2['relationships'][$playerName2] ?? null;
            if ($pRel) {
                $GLOBALS['RELDYN_PRE_AFF'] = intval($pRel['aff'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    // Best effort
}

// ========== CHIM → RelDyn AFFINITY DIMENSION SYNC ==========
// CHIM owns aff (-100..+100); RelDyn XYZ uses dimensions.affinity.x (0..100).
// Sync once per prerequest so dimension consumers see up-to-date value.
if (!empty($reldynCfg['dimension_engine_enabled'])) {
    $chimAff = $GLOBALS['RELDYN_PRE_AFF'];
    if ($chimAff !== null) {
        $relDynAff = ($chimAff + 100) / 2.0;  // -100→0, 0→50, 100→100
        if (!isset($dynamics['dimensions'])) {
            $dynamics['dimensions'] = [];
        }
        if (!isset($dynamics['dimensions']['affinity'])) {
            $dynamics['dimensions']['affinity'] = ['x' => 0, 'baseline' => null];
        }
        $dynamics['dimensions']['affinity']['x'] = round($relDynAff, 2);
    }
}

$reunionPassion = ($reldynCfg['reunion_enabled'] ?? true) ? RelationshipDynamics::checkReunion($dynamics, $npcAffection) : 0;
if ($reunionPassion > 0) {
    RelationshipDynamics::addPassion($dynamics, $reunionPassion, 'reunion');
}

// -------------------------------------------------------------------------
// Ambient presence: being in a location matching NPC interests builds
// warmth passively and resists decay. No interactions needed.
// -------------------------------------------------------------------------
$ambientResult = ($reldynCfg['ambient_enabled'] ?? true) ? RelationshipDynamics::detectCurrentInterest($dynamics) : null;
$ambientInterest = is_array($ambientResult) ? ($ambientResult['interest'] ?? null) : $ambientResult;
$ambientResonance = is_array($ambientResult) ? ($ambientResult['resonance'] ?? 0.0) : 0.0;
$ambientSource = is_array($ambientResult) ? ($ambientResult['source'] ?? 'none') : 'none';
$ambientLocation = is_array($ambientResult) ? ($ambientResult['location'] ?? '') : '';

// Store for context.php and postrequest.php to use
$GLOBALS['RELDYN_AMBIENT_INTEREST'] = $ambientInterest;
$GLOBALS['RELDYN_AMBIENT_RESONANCE'] = $ambientResonance;
$GLOBALS['RELDYN_AMBIENT_LOCATION'] = $ambientLocation;
$GLOBALS['RELDYN_AMBIENT_SOURCE'] = $ambientSource;

if ($ambientInterest && $ambientResonance >= 0.15) {
    // Use resonance score directly for vector path, or interest multiplier for keyword path
    if ($ambientSource === 'vector') {
        // Vector resonance: scale 0.15-0.65 → multiplier 1.1-2.0
        $ambientMult = 1.0 + min(1.0, ($ambientResonance - 0.15) * 2.0);
    } else {
        // Keyword fallback: use NPC's interest slider value
        $interests = RelationshipDynamics::getInterests($dynamics);
        $ambientMult = floatval($interests[$ambientInterest] ?? 1.0);
    }

    if ($ambientMult > 1.0) {
        // CEILING — trickle builds up to this, then stops
        // Aela in wilderness (resonance 0.61): ceiling ≈ 19
        // Ashe in Dwemer ruin (resonance 0.65): ceiling ≈ 20
        $ambientCeiling = 10.0 * $ambientMult;
        $currentPassion = floatval($dynamics['passion'] ?? 0);

        // TRICKLE — passive gain, ~0.3/min at high resonance, stops at ceiling
        $lastAmbient = intval($dynamics['_ambient_updated_at'] ?? 0);
        $minutesSince = $lastAmbient > 0 ? (time() - $lastAmbient) / 60.0 : 0;
        if ($minutesSince > 0.5 && $currentPassion < $ambientCeiling) {
            $trickle = min($ambientCeiling - $currentPassion, 0.3 * ($ambientMult - 1.0) * $minutesSince);
            $dynamics['passion'] = $currentPassion + $trickle;
            $dynamics['_ambient_updated_at'] = time();
            if ($trickle > 0.01) {
                RelationshipDynamics::log("Ambient trickle: {$npcName} @ '{$ambientLocation}' ({$ambientSource}, resonance=" . round($ambientResonance, 3) . ", mult={$ambientMult}x) +{" . round($trickle, 2) . "} passion=" . round($dynamics['passion'], 1) . " (ceiling=" . round($ambientCeiling, 0) . ")");
            }
        } elseif ($lastAmbient === 0) {
            $dynamics['_ambient_updated_at'] = time();
        }

        // DECAY RESIST — while in matching location, reduce decay rate
        $dynamics['_ambient_decay_resist'] = 1.0 / $ambientMult;
    }
} else {
    // Not in a matching location — clear decay resist
    unset($dynamics['_ambient_decay_resist']);
}

// -------------------------------------------------------------------------
// Physical State Bridges (PR 8): detect physical conditions from MinAI
// signals and apply/clear temporary dimension modifiers.
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
}

// ========== ATTRACTION MATRIX EVALUATION (PR 11) ==========
if (!empty($reldynCfg['attraction_matrix_enabled'])) {
    $interactionCount = intval($dynamics['interaction_count'] ?? 0);
    $lastMatrixEval = intval($dynamics['_attraction_matrix_last_eval'] ?? 0);
    $evalInterval = intval($reldynCfg['attraction_eval_interval'] ?? 10);

    // Re-evaluate if: first time, or interval elapsed, or cache is empty
    if ($lastMatrixEval === 0 || ($interactionCount - $lastMatrixEval) >= $evalInterval || empty($dynamics['_attraction_matrix_cache'])) {
        $matrixResult = RelationshipDynamics::calculateAttractionMatrix($npcName, $dynamics);
        $GLOBALS['RELDYN_ATTRACTION_MATRIX'] = $matrixResult;
    } else {
        // Use cached result
        $GLOBALS['RELDYN_ATTRACTION_MATRIX'] = $dynamics['_attraction_matrix_cache'];
    }

    // Set globals for postrequest consumption
    $GLOBALS['RELDYN_MATRIX_PASSION_MULT'] = floatval($dynamics['_attraction_passion_mult'] ?? 1.0);
    $GLOBALS['RELDYN_MATRIX_TIER_CEILING'] = $dynamics['_attraction_tier_ceiling'] ?? 'sworn';
    $GLOBALS['RELDYN_MATRIX_FRIENDZONED'] = !empty($dynamics['_attraction_friendzoned']);
}

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
    $currentInterest = $GLOBALS['RELDYN_CURRENT_INTEREST'] ?? null;
    RelationshipDynamics::updateInternalWeather($npcName, $dynamics, $currentInterest);
    $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
    RelationshipDynamics::applyWeatherModifiers($npcName, $dynamics, $temperament);
}

if (!empty($reldynCfg['creature_moodifications_enabled'])) {
    $temperament = $temperament ?? ($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic');
    RelationshipDynamics::applyCreatureModifiers($npcName, $dynamics, $temperament);
}

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
        // Determine reason
        $ickActive = !empty($dynamics['_ick_tracker']['ick_active']);
        $comfort = floatval($dynamics['dimensions']['comfort']['x'] ?? 50);
        $resentment = floatval($dynamics['dimensions']['resentment']['x'] ?? 0);
        if ($ickActive && $comfort < 20) {
            $reason = 'ick_comfort';
        } elseif ($resentment > 70) {
            $reason = 'resentment';
        } else {
            $reason = 'autonomy';
        }
        RelationshipDynamics::initiateWalkaway($dynamics, $npcName, $reason);
    }

    // Process walkaway tick if in walkaway state
    if (!empty($dynamics['_walkaway_state']) && $dynamics['_walkaway_state'] !== 'normal') {
        $isDialogue = in_array($reqType, ['inputtext', 'inputtext_s', 'ginputtext']);
        $walkResult = RelationshipDynamics::processWalkawayTick($dynamics, $npcName, $autoTemperament, $isDialogue);

        // If recovered, execute autonomous return
        if (($walkResult['state'] ?? '') === 'recovery') {
            RelationshipDynamics::executeAutonomousReturn($npcName, $dynamics);
            RelationshipDynamics::resetWalkawayState($dynamics);
        }

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
// PR 16: Pause affinity decay during walkaway (NPC chose to leave, not forgotten)
if (!empty($reldynCfg['dimension_engine_enabled']) && empty($dynamics['_walkaway_affinity_decay_paused'])) {
    $decayTicks = RelationshipDynamics::calculateDecayTicks($dynamics);
    if ($decayTicks > 0.001) {
        $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
        $relType = 'stranger';
        try {
            $db = $GLOBALS['db'] ?? null;
            if ($db) {
                $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
                $escaped = $db->escape($npcName);
                $row = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower('{$escaped}') LIMIT 1");
                if (is_array($row) && !empty($row['extended_data'])) {
                    $ext = json_decode($row['extended_data'], true) ?: [];
                    $relType = $ext['relationships'][$playerName]['type'] ?? 'stranger';
                }
            }
        } catch (\Throwable $e) {}

        $decayResult = RelationshipDynamics::processAffinityDecay($dynamics, $npcName, $temperament, $relType, $decayTicks);
        if ($decayResult && !($decayResult['skipped'] ?? false)) {
            RelationshipDynamics::log("[RelDyn-DECAY-WIRED] {$npcName}: decay=" . round($decayResult['decay_amount'], 2));
        }
    }
}

// Calculate effective disposition (overlay on existing sex_disposal)
$npcNameKey = "aiagent_nsfw_intimacy_" . strtolower(str_replace(' ', '_', $npcName));
$existingDisposal = 0;
try {
    if (isset($GLOBALS['db'])) {
        $escapedKey = $GLOBALS['db']->escape($npcNameKey);
        $confRow = $GLOBALS['db']->fetchOne("SELECT value FROM conf_opts WHERE id = '{$escapedKey}' LIMIT 1");
        if (is_array($confRow) && !empty($confRow['value'])) {
            $intimacyData = json_decode($confRow['value'], true) ?: [];
            $existingDisposal = intval($intimacyData['sex_disposal'] ?? 0);
        }
    }
} catch (Throwable $e) {
    // Use 0
}

$effectiveDisposal = RelationshipDynamics::getEffectiveDisposition($existingDisposal, $dynamics);

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

// Bridge to Sharmat: write effective disposal so Sharmat's scene gating reads it
// Only fires if Sharmat is installed — zero dependency otherwise
if (class_exists('NsfwNpcData')) {
    try {
        NsfwNpcData::setKey($npcName, 'sex_disposal', intval($effectiveDisposal));
    } catch (\Throwable $e) {
        // Sharmat not available — silently continue
    }
}

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

// Save dynamics (decay + reunion applied)
RelationshipDynamics::saveDynamics($npcName, $dynamics);
