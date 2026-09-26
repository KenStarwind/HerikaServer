<?php
/**
 * Relationship Dynamics — Postrequest Hook
 *
 * Runs after other extension postrequest hooks (alphabetical order).
 * Handles: interaction classification, passion gain, diminishing returns,
 * affinity multiplier (RPM→Speed), jealousy scan, conflict, stages.
 */

if (!isset($GLOBALS['gameRequest']) || !is_array($GLOBALS['gameRequest'])) {
    return;
}

$reqType = $GLOBALS['gameRequest'][0] ?? '';
if ($reqType === 'maras_sync') {
    return;
}

// IMPORTANT: $GLOBALS['HERIKA_NAME'] is clobbered to 'The Narrator' by
// processor/postrequest.php (which re-requires conf.php before ext/ hooks run).
// Use the NPC name snapshot saved by prerequest.php, falling back to
// GetOriginalHerikaName() (CHIM utility) then HERIKA_NAME as last resort.
$npcName = $GLOBALS['RELDYN_NPC_NAME']
    ?? (function_exists('GetOriginalHerikaName') ? GetOriginalHerikaName() : null)
    ?? $GLOBALS['HERIKA_NAME']
    ?? '';
// === COMBAT EVENT ROUTING ===
// Combat events must ALWAYS go through the combat handler, even when
// RELDYN_NPC_NAME is set to a real NPC. CHIM 3.4.1 core combat event types only (no MinAI).
// (Same list as RelationshipDynamics::CORE_COMBAT_REQUEST_TYPES; the class is not loaded yet.)
$combatNarratorTypes = ['radiantcombatfriend', 'death', 'bleedout', 'combatend', 'combatendmighty'];
$isCombatEvent = in_array($reqType, $combatNarratorTypes);

if ($isCombatEvent || empty($npcName) || $npcName === 'The Narrator') {
    if ($isCombatEvent) {
        require_once __DIR__ . '/relationship_dynamics.php';
        RelationshipDynamics::beginRequest();
        if (!RelationshipDynamics::isEnabled()) { return; }

        $reldynCfg = RelationshipDynamics::getConfig();
        if (empty($reldynCfg['combat_enabled'] ?? true)) { return; }

        // One routine for every core combat event (reldyn_combat.php): who fought, who saw it,
        // the NPC's own appraisal of the fight, kill streak, the fall of bleedout, grief. Core's
        // death / bleedout requests never get here (main.php logs them and ends the request):
        // RelDynCombat::consumeEventlog routes those rows on the next prerequest.
        $playerName = $GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player';
        try {
            RelDynCombat::route($reqType, (string) ($GLOBALS['gameRequest'][3] ?? ''), floatval($GLOBALS['gameRequest'][2] ?? 0),
                (string) ($GLOBALS['CACHE_PEOPLE'] ?? ''), $GLOBALS['CACHE_PARTY'] ?? null, (string) $playerName);
        } catch (Throwable $e) {
            RelationshipDynamics::logError("combat route {$reqType}", $e);
        }
    }
    // Non-combat narrator events or combat events that didn't match — nothing more to do
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';
// Each hook is its own request scope: config/bond caches never outlive it (A3).
RelationshipDynamics::beginRequest();

if (!RelationshipDynamics::isEnabled()) {
    return;
}

$dynamics = RelationshipDynamics::getDynamics($npcName);
$reldynCfg = RelationshipDynamics::getConfig();

// ── EVAL PRODUCER (assessment Phase 2 option A) ──
// Queue this exchange for RelDyn's own multi-signal eval (eval_producer.php). It decides
// itself: never the Narrator, radiant / NPC-to-NPC only when the player is addressed,
// config chance and cooldown. The worker runs outside this request, fills the eval inbox and
// applies it (eval_producer.apply_in_worker).
require_once __DIR__ . '/eval_producer.php';
$evalJobId = RelDynEval::onPostrequest($npcName, $GLOBALS['gameRequest']);
// An exchange the eval scores is the eval's alone: its item (applied by the worker, else on
// this NPC's next request) moves affinity, passion, resentment decay and conflict repair. The legacy local
// classifier below stands down for it, so nothing is counted twice. An exchange the eval
// does not score (eval off, no connector, chance, cooldown) keeps the local heuristics.
$evalOwnsExchange = ($evalJobId !== null);

// ── OGHMA FACETS (oghma-facet-classifier) ──
// The facet table topics and gifts are appraised from is built in the background when it is
// missing or stale (RelDynFacetClassifier::maybeLaunchBuild; the game-time path never embeds).
RelDynFacetClassifier::maybeLaunchBuild($GLOBALS['db'] ?? null, RelationshipDynamics::currentGamets());

// ── NPC-TO-NPC FILTER ──
// Radiant dialogue is NPC-to-NPC — player isn't involved.
// Passion/affinity between those NPCs is handled by CHIM core's relationship_system.
// Skip RelDyn player↔NPC passion math to prevent parasitism.
if (RelationshipDynamics::isRadiantRequest($GLOBALS['gameRequest'] ?? null)) {
    return;
}
// A rechat / continue answering another NPC is NPC-to-NPC too, unless her reply was addressed to
// the player: a suitor's flirt is no passion, interaction, contact or fulfillment of the player
// pair (rulings §11, decisions §17).
if (RelationshipDynamics::isNpcExchange($GLOBALS['gameRequest'] ?? null, trim((string) ($GLOBALS['PLAYER_NAME'] ?? 'Player')), (string) $npcName)
    && !RelDynEval::isPlayerAddressed($GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] ?? null, trim((string) ($GLOBALS['PLAYER_NAME'] ?? 'Player')))) {
    return;
}

// ── BYSTANDER FILTER ──
// Only the active conversation target gets full passion math.
// Bystanders (NPCs in CACHE_PEOPLE but not being spoken to) already received
// ambient trickle in prerequest.php — no interaction-based passion for them.
$activeNpc = $GLOBALS['RELDYN_NPC_NAME']
    ?? (function_exists('GetOriginalHerikaName') ? GetOriginalHerikaName() : null)
    ?? '';
if (!empty($activeNpc) && strtolower($npcName) !== strtolower($activeNpc)) {
    // Bystander — skip full passion math, ambient trickle already applied
    return;
}

// Ensure love language exists (in case prerequest was skipped)
RelationshipDynamics::ensureLoveLanguage($npcName, $dynamics);

// -------------------------------------------------------------------------
// 1. Classify interaction
// -------------------------------------------------------------------------
$lastMood = null;
try {
    if (isset($GLOBALS['db'])) {
        $db = $GLOBALS['db'];
        $moodRow = $db->fetchOne(
            "SELECT mood FROM moods_issued WHERE lower(speaker) = lower('" . $db->escape($npcName) . "') ORDER BY localts DESC LIMIT 1"
        );
        $lastMood = $moodRow['mood'] ?? null;
    }
} catch (Throwable $e) {
    RelationshipDynamics::logError('postrequest mood lookup', $e);
    // Mood query failed, continue without
}

$interactionLL = RelationshipDynamics::classifyInteraction($GLOBALS['gameRequest'], $lastMood);
$GLOBALS['RELDYN_LAST_INTERACTION_LL'] = $interactionLL;
// Intimacy the plugin reports (a Sharmat / OStim scene with the player, a VR touch) is an
// observed fact, not a judgment: it feeds the intimacy axes whether or not the eval scores the
// exchange (rulings §10; PR 13: "OStim/Sharmat events -> fully satisfied").
$reldynIntimate = RelDynIntimacy::requestKind($GLOBALS['gameRequest'], (string) ($GLOBALS['PLAYER_NAME'] ?? '')) !== null;
if ($reldynIntimate) {
    RelDynIntimacy::recordRequest($dynamics, $GLOBALS['gameRequest'], (string) ($GLOBALS['PLAYER_NAME'] ?? ''), RelationshipDynamics::currentGamets());
}
// The exchange as a love-language delivery to fulfillment (rulings §9; a hug or a kiss also
// feeds the intimacy axes, rulings §10, unless the request fed them itself above), unless the
// eval scores it (its tags deliver then, in processEvalContractItem).
if (!$evalOwnsExchange) {
    RelationshipDynamics::recordLoveLanguageFulfillment($dynamics, $interactionLL, RelationshipDynamics::currentGamets(), !$reldynIntimate);
}
RelationshipDynamics::log("POST classify: npc={$npcName} type={$reqType} mood={$lastMood} LL=" . ($interactionLL ?? 'NULL'));

// The shared-activity multiplier (place / gift appraisal, decisions §6) is part of
// calculatePassionGain() below.

// -------------------------------------------------------------------------
// 1b. Topic Talk Bonus -- decisions 2026-09-23 section 6: the Oghma topics core's retrieval
// grounded THIS turn (processor/oghma.php ran earlier in this request) -> facets
// (RelDynFacets::thingFacets) -> this NPC's appraisal. Dwemer lore lands warmly with Ashe
// (scholarly) and tiresomely with Aela (scholarly and confined are what she dislikes). The
// valence scales passion in MDD 1.2's 0.5x..2.0x; the felt read (never numbers) reaches the
// next context as <topic_resonance>.
// -------------------------------------------------------------------------
$topicBonus = 1.0;
$topicMatch = null;
$topicFelt = null;
if ($reldynCfg['topic_bonus_enabled'] ?? true) {
    $topicTurn = RelDynFacetClassifier::topicTurn($dynamics, $npcName, RelDynFacetClassifier::turnTopics());
    $topicBonus = $topicTurn['bonus'];
    $topicMatch = $topicTurn['match'];
    $topicFelt = $topicTurn['felt'];
    if ($topicTurn['appraisal'] !== null) {
        $ta = $topicTurn['appraisal'];
        // A topic that touches one of her intrinsic goals moves it (MDD 14.2)
        RelDynGoals::onExperience($npcName, $dynamics, 'topic', (string) $ta['name'], (array) ($ta['facets'] ?? []), RelationshipDynamics::currentGamets());
        RelationshipDynamics::log("TopicBonus: {$npcName} topic='{$ta['name']}' valence=" . round((float) $ta['valence'], 3)
            . " dominant=" . ($ta['dominant'] ?? 'none') . " bonus=" . round($topicBonus, 3) . "x match=" . ($topicMatch ? 'yes' : 'no'));
    }
} // end topic_bonus_enabled
$GLOBALS['RELDYN_TOPIC_BONUS'] = $topicBonus;
$GLOBALS['RELDYN_TOPIC_MATCH'] = $topicMatch;

// -------------------------------------------------------------------------
// 1c. Flirt-in-Context Bonus — flirty mood + (topic match OR location match)
// -------------------------------------------------------------------------
$flirtBonus = 1.0;
if ($reldynCfg['flirt_bonus_enabled'] ?? true) {
$flirtyMoods = ['flirty', 'romantic', 'playful', 'teasing', 'amused', 'charmed',
                'smitten', 'coy', 'seductive', 'affectionate', 'bashful', 'flustered'];
if (!empty($lastMood) && in_array(strtolower($lastMood), $flirtyMoods)) {
    // A place the NPC loves (fresh place appraisal at Point-of-Interest valence, MDD 1.5)
    $placeRead = RelDynFacets::freshPlaceAppraisal($dynamics, RelationshipDynamics::currentGamets());
    $hasLocationMatch = $placeRead !== null
        && floatval($placeRead['valence']) >= floatval(RelDynFacets::getAppraisalConfig()['poi_valence_min']);
    $hasTopicMatch = ($topicMatch !== null);   // a topic this NPC warms to (topic_match_min_valence)
    if ($hasLocationMatch || $hasTopicMatch) {
        $flirtBonus = 1.2;
        RelationshipDynamics::log("FlirtBonus: {$npcName} mood={$lastMood} location=" . ($hasLocationMatch ? 'yes' : 'no') . " topic=" . ($hasTopicMatch ? 'yes' : 'no') . " bonus=1.2x");
    }
}
} // end flirt_bonus_enabled
$GLOBALS['RELDYN_FLIRT_BONUS'] = $flirtBonus;

// -------------------------------------------------------------------------
// 2. Calculate and apply passion gain
// -------------------------------------------------------------------------
$passionGain = 0.0;
// A positive exchange the legacy classifier scored, whatever the attraction made of its passion
// (a closed gate zeroes the passion, not the exchange: affinity, repair and stages still count)
$positiveExchange = false;
if ($evalOwnsExchange) {
    RelationshipDynamics::log("POST legacy classifier stands down for {$npcName}: eval job {$evalJobId} scores this exchange (no local passion gain, affinity speed, repair or resentment decay)");
} elseif (($reldynCfg['passion_enabled'] ?? true) && $interactionLL !== null) {
    $rawPassionGain = RelationshipDynamics::calculatePassionGain($dynamics, $interactionLL);
    // Apply topic and flirt bonuses on top of base passion gain
    $passionGain = $rawPassionGain * $topicBonus * $flirtBonus;
    $positiveExchange = $passionGain > 0;

    // ========== ATTRACTION: THE SPARK, THEN THE UPHILL x ATTACHMENT (decisions §13, rulings §9) ==========
    // (the love language's eval tag is the gain's channel: decisions §15, an asexual NPC's
    // passion grows only through the emotional ones)
    $matrixPassionMult = $passionGain > 0
        ? RelationshipDynamics::attractionPassionFactor($npcName, $dynamics, $passionGain, 'love_match',
            RelDynAttraction::loveLanguageChannelTags($interactionLL)) : 1.0;
    if ($passionGain > 0) {
        $passionGain *= $matrixPassionMult;
    }

    error_log("[RelDyn-POST] passionGain: npc={$npcName} LL={$interactionLL} raw={$rawPassionGain} topic={$topicBonus}x flirt={$flirtBonus}x matrix={$matrixPassionMult}x final={$passionGain} currentPassion={$dynamics['passion']}");
    if ($passionGain > 0) {
        RelationshipDynamics::addPassion($dynamics, $passionGain, 'love_match');
    }

    if ($passionGain > 0) {
        // Store blush multiplier for next prerequest cycle
        // (maras_bridge runs before relationship_dynamics in prerequest,
        //  so we persist it for the next cycle's blush trigger to read)
        if ($interactionLL === $dynamics['love_language_primary']) {
            $dynamics['pending_blush_mult'] = 2.0;
            $GLOBALS['RELDYN_BLUSH_MULTIPLIER'] = 2.0;
        } elseif ($interactionLL === $dynamics['love_language_secondary']) {
            $dynamics['pending_blush_mult'] = 1.5;
            $GLOBALS['RELDYN_BLUSH_MULTIPLIER'] = 1.5;
        } else {
            $dynamics['pending_blush_mult'] = 1.0;
        }

        // Store passion delta for blush self-awareness in next context.php cycle
        $dynamics['_last_passion_delta'] = round($passionGain, 2);
    }
}

// -------------------------------------------------------------------------
// 2b. Rescue response (MDD 3.3): the player's first exchange with her after her fall. An
// exchange the eval scores is claimed and its item decides (RelDynCombat::onEvalItem); one it
// does not score is caring when she answered in a caring mood or the player hugged her.
// -------------------------------------------------------------------------
if ($reldynCfg['combat_enabled'] ?? true) {
    RelDynCombat::onExchange($npcName, $dynamics, $evalOwnsExchange, $interactionLL, is_string($lastMood) ? $lastMood : null,
        floatval($GLOBALS['gameRequest'][2] ?? 0) > 0 ? floatval($GLOBALS['gameRequest'][2]) : RelationshipDynamics::currentGamets());
}

// Store the topic read for the next context.php cycle (<topic_resonance>): the felt text
// (a match, or a topic the NPC dislikes) lives one turn, then clears.
if ($topicMatch) {
    $dynamics['_last_topic_match'] = $topicMatch;
} else {
    unset($dynamics['_last_topic_match']);
}
if ($topicFelt !== null) {
    $dynamics['_last_topic_felt'] = $topicFelt;
} else {
    unset($dynamics['_last_topic_felt']);
}
// A gift's felt read (processGift, below) also lives one turn.
unset($dynamics['_last_gift_felt']);

// -------------------------------------------------------------------------
// 3. Diminishing returns — record interaction
// -------------------------------------------------------------------------
RelationshipDynamics::recordInteraction($dynamics);
// An exchange the legacy classifier scored is a meaningful interaction for the reputation
// layer's fade (the eval counts its own items, at their significance)
if (!$evalOwnsExchange && $interactionLL !== null) {
    RelDynReputation::countInteraction($dynamics, 1.0);
}

// -------------------------------------------------------------------------
// 4. RPM → Speed: Apply passion-weighted affinity change
// -------------------------------------------------------------------------
// Core owns aff (relationships.Player.aff, CHIM 3.4.1 key "Player"); core's
// relationship_system eval keeps writing it too. RelDyn adds its delta on top
// under core's advisory lock (commitPlayerAffinity), so neither side clobbers
// the other and affinity can move both ways.
// Base delta: +1 per positive interaction, scaled by passion multiplier.
// Formula: aff_delta = base × passion_multiplier
//   passion 0   → ×0.3  (idling — affinity barely moves)
//   passion 50  → ×1.15 (cruising — normal pace)
//   passion 100 → ×2.0  (redline — maximum)
$affinityGainMult = RelationshipDynamics::getAffinityGainMultiplier($dynamics);
$baseDelta = $positiveExchange ? 1 : 0; // +1 per positive interaction

try {
    if ($baseDelta != 0) {
        $modifiedDelta = round($baseDelta * $affinityGainMult, 2);

        // Accumulate fractional deltas — only whole points are applied to core
        RelationshipDynamics::queueAffinityDelta($dynamics, $modifiedDelta);
        $affResult = RelationshipDynamics::commitPlayerAffinity($npcName, $dynamics);
        $intDelta = $affResult['delta'] ?? 0;

        if ($affResult !== null) {
            $currentAff = $affResult['old'];
            $newAff = $affResult['new'];
            $GLOBALS['RELDYN_AFFINITY_DELTA'] = $intDelta;

            // ========== CASCADE AFFINITY (PR 12) ==========
            if (!empty($reldynCfg['cascade_network_enabled'])) {
                $affinityDelta = floatval($GLOBALS['RELDYN_AFFINITY_DELTA'] ?? 0);
                if (abs($affinityDelta) >= RelationshipDynamics::CASCADE_THRESHOLD) {
                    $playerName = trim($GLOBALS['PLAYER_NAME'] ?? 'Player');
                    $cascadeResults = RelationshipDynamics::propagateAffinityChange($npcName, $affinityDelta, $playerName);
                    if (!empty($cascadeResults)) {
                        RelationshipDynamics::log("[CASCADE] {$npcName}: " . count($cascadeResults) . " NPCs affected by delta=" . round($affinityDelta, 2));
                    }
                }
            }

            RelationshipDynamics::log("RPM→Speed: base={$baseDelta} × mult=" . round($affinityGainMult, 2) . " = +{$intDelta} aff (passion=" . intval($dynamics['passion']) . ", aff {$currentAff}→{$newAff})");
        } else {
            RelationshipDynamics::log("RPM→Speed: base={$baseDelta} × mult=" . round($affinityGainMult, 2) . " = pending " . round(floatval($dynamics['_pending_aff_delta'] ?? 0), 2) . " (passion=" . intval($dynamics['passion']) . ", not enough for +1 yet)");
        }

        // Check for conflict from negative delta
        if ($intDelta < 0) {
            RelationshipDynamics::checkAffinityDropConflict($dynamics, $intDelta);
        }
    }
} catch (Throwable $e) {
    error_log("[RelDyn] RPM→Speed error: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 5. Jealousy — bystanders are scanned after the eval items are applied (below,
//    XYZ EVAL DELTA PROCESSING): the eval's intimacy/touch tags are the trigger, the
//    bystander's core Player.type the commitment (no MARAS on 3.4.1).
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// 6. Conflict resolution check
// -------------------------------------------------------------------------
if (!($reldynCfg['conflict_enabled'] ?? true)) goto skip_conflict;
// For an exchange the eval scores, its positive_interaction drives repair
// (applyEvalFeelings); $passionGain is 0 then, so this heuristic stands down.
if ($positiveExchange && !empty($dynamics['in_conflict'])) {
    $repairBurst = RelationshipDynamics::recordConflictPositive($dynamics);
    if ($repairBurst > 0) {
        RelationshipDynamics::gainPassion($npcName, $dynamics, $repairBurst, 'repair');
    }
}

skip_conflict:

// ========== ATTRACTION TIER CEILING (MDD 8) ==========
// The Matrix gates tier progression (getRelationshipType, attractionAllowsType), not affinity:
// affinity can still grow. Passion has no attraction cap (decisions §13 retired the MDD 6.2
// hard cap of 20): the uphill scales its gains instead (attractionPassionFactor).

// (Interaction pattern tracking for the Parasite protocol runs after the item events, below:
// a gift seen this request is part of what this exchange was.)

// A5: with the dimension engine off nothing consumes the eval inbox, so
// pendingEvalForRequest() drops it (engine on: a read-only peek, which nothing here reads:
// the inbox is consumed by processPendingEvalDeltas below).
RelationshipDynamics::pendingEvalForRequest($npcName, $dynamics);

// ========== ICK TRACKER + CHARISMA DETECTION (PR 15) ==========
// This interaction, and touch she did not answer in kind (never intimacy the game reports inside
// the romance: RelDynProtocols::ickAttemptOfRequest). The eval's romantic_intent reaches the Ick
// once per applied item, with that exchange's reply mood (applyEvalExtraFields ->
// recordIckEvalAttempt), not by peeking at the inbox the worker has usually emptied.
if (!empty($reldynCfg['ick_system_enabled'] ?? true)) {
    $classifiedLL = $GLOBALS['RELDYN_LAST_INTERACTION_LL'] ?? null;
    $isRomantic = RelDynProtocols::ickAttemptOfRequest($dynamics, $classifiedLL, is_string($lastMood) ? $lastMood : null,
        (array) ($GLOBALS['gameRequest'] ?? []), (string) ($GLOBALS['PLAYER_NAME'] ?? ''));
    $temperament = $dynamics['inferred_temperament'] ?? null;
    $ickChanged = RelationshipDynamics::updateIckTracker($dynamics, $isRomantic, $temperament,
        floatval($GLOBALS['gameRequest'][2] ?? 0));   // raw gamets of this exchange (its eval item's)
    if ($ickChanged) {
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
    }
    $GLOBALS['RELDYN_ICK_ACTIVE'] = !empty($dynamics['_ick_tracker']['ick_active']);
}

// Charisma (MDD 5.1) is fed by each applied eval item's charisma grade (rulings §18 #11,
// RelationshipDynamics::applyEvalExtraFields), not here: an exchange nobody graded says
// nothing about the player's style.

// -------------------------------------------------------------------------
// 7. Track positive interactions + stage advancement
// -------------------------------------------------------------------------
if ($positiveExchange) {
    $dynamics['total_positive_interactions'] = intval($dynamics['total_positive_interactions'] ?? 0) + 1;
    RelationshipDynamics::checkStageAdvancement($dynamics);
    // Contact heals: the affinity rot clock starts over (reldyn_absence.php)
    RelDynAbsence::markPositive($dynamics, RelationshipDynamics::currentGamets());
}

// Social masking (MDD 11): the context decides and records the mask each turn
// (RelationshipDynamics::maskingTurn, after core set CACHE_PEOPLE); the eval's masking field
// pays its cost (applyEvalExtraFields).

// ========== DIARY MOMENTS (PR 14) ==========
// The trigger found meaningful moments this cycle: mark them (kept for her next entry in core's
// diary, which RelDynDiary reflects on at a later prerequest). The mark is where
// sustained experience moves the NPC's global baselines (baseline drift, PR 13 / recap
// 2026-03-31 Fix 5), before the diary bookmarks are taken.
if (!empty($GLOBALS['RELDYN_DIARY_TRIGGERED'])) {
    $driftResults = RelationshipDynamics::processBaselineDrift($npcName, $dynamics);
    if (!empty($driftResults)) {
        RelationshipDynamics::log("Baseline drift for {$npcName}: " . json_encode($driftResults));
    }
    RelationshipDynamics::markDiaryCompleted($dynamics);
}

// Director goal fulfilment (PR 39, Step 5): an applied eval item's goal_addressed for the goal
// it was shown (RelationshipDynamics::applyEvalExtraFields), once per item.

// -------------------------------------------------------------------------
// 8. Save
// -------------------------------------------------------------------------
RelationshipDynamics::saveDynamics($npcName, $dynamics);

// Duty override (MDD 9): the eval job of this exchange carries RELDYN_DUTY_FACTOR
// (RelDynEval::onPostrequest); the consumer dampens its negative signals.

// ========== XYZ EVAL DELTA PROCESSING (PR 3) ==========
$rdConfig = RelationshipDynamics::getConfig();
if (!empty($rdConfig['dimension_engine_enabled'])) {
    // Items the eval worker could not apply itself (another request held the inbox, or
    // eval_producer.apply_in_worker is off): applied here, with what follows from them.
    RelationshipDynamics::applyEvalInbox($npcName, $dynamics);

    // ========== RESENTMENT DIMENSION (PR 7) ==========
    // PR 16: Post-hoover resentment builds faster (patterns don't heal from carpet-sweeping)
    $hooverResentmentMult = floatval($dynamics['_hoover_resentment_mult'] ?? 1.0);

    // Process pending grievances into resentment buildup
    $pendingGrievances = $dynamics['dimensions']['resentment']['pending_grievances'] ?? [];
    if (!empty($pendingGrievances)) {
        // Apply hoover multiplier to grievance values
        if ($hooverResentmentMult > 1.0) {
            foreach ($pendingGrievances as &$grievance) {
                if (isset($grievance['value'])) {
                    $grievance['value'] = $grievance['value'] * $hooverResentmentMult;
                }
            }
            unset($grievance);
            $dynamics['dimensions']['resentment']['pending_grievances'] = $pendingGrievances;
        }
        $dynamics['_npc_name'] = $npcName; // Tag for logging
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $grievanceCount = RelationshipDynamics::processGrievances($dynamics, $temperament);
        if ($grievanceCount > 0) {
            error_log("[RelDyn-POST] Processed {$grievanceCount} grievances for {$npcName}");
            RelationshipDynamics::saveDynamics($npcName, $dynamics);
        }
    }

    // Natural resentment decay on positive interactions (for an exchange the eval scores,
    // its positive_interaction does this in applyEvalFeelings; $passionGain is 0 then)
    $wasPositive = $positiveExchange ?? false;
    if ($wasPositive) {
        $dynamics['_npc_name'] = $npcName;
        $temperament = $dynamics['inferred_temperament'] ?? null;
        $decay = RelationshipDynamics::processResentmentDecay($dynamics, $temperament, true);
        if (abs($decay) > 0.001) {
            RelationshipDynamics::saveDynamics($npcName, $dynamics);
        }
    }

    // ========== ITEM DIMENSION MODIFIERS (PR 8) ==========
    // Detect and process consumable, gift, and equip events from this interaction.
    // Uses eventlog patterns and ExtCmdGiveItem (core data).
    $playerName = $GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player';
    $temperament = $dynamics['inferred_temperament'] ?? null;
    $itemResults = RelationshipDynamics::processItemEvents(
        $dynamics, $GLOBALS['gameRequest'], $npcName, $playerName, $temperament
    );
    if (!empty($itemResults['consumable']) || !empty($itemResults['gift']) || !empty($itemResults['equip'])) {
        error_log("[RelDyn-POST] Item events for {$npcName}: " . json_encode($itemResults));
        // gifts move affinity; push it to core as a locked delta
        RelationshipDynamics::commitPlayerAffinity($npcName, $dynamics);
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
    }

    // ========== TOXIC CONFLICT PASSION (PR 10) ==========
    $conflictPassion = $GLOBALS['RELDYN_ATTACHMENT_CONFLICT_PASSION'] ?? 0;
    if ($conflictPassion > 0) {
        RelationshipDynamics::applyDelta('passion', $dynamics, $conflictPassion, $temperament ?? null);
        unset($GLOBALS['RELDYN_ATTACHMENT_CONFLICT_PASSION']);
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
    }
}

// ========== INTERACTION PATTERN TRACKING (PR 12; MDD 6.2 Parasite, reldyn_protocols.php) ==========
// This exchange in the transactional ledger, keyed by its game time: a gift seen this request
// (core's eventlog "gave X to" row or the request's give action), else a positive exchange the
// local classifier scored, else nothing yet (its eval item, same game time, may say what it was).
if (!empty($reldynCfg['parasite_detection_enabled'])) {
    $giftSeen = !empty($itemResults['gift'] ?? null);
    RelationshipDynamics::updateInteractionPattern($dynamics, $GLOBALS['RELDYN_LAST_INTERACTION_LL'] ?? null,
        floatval($GLOBALS['RELDYN_AFFINITY_DELTA'] ?? 0), floatval($GLOBALS['gameRequest'][2] ?? 0), $giftSeen, $positiveExchange);
    RelationshipDynamics::checkParasitePattern($npcName, $dynamics);
    RelationshipDynamics::checkParasiteRecovery($npcName, $dynamics);
    RelationshipDynamics::saveDynamics($npcName, $dynamics);
}
