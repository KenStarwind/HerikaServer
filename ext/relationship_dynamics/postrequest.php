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

        $eventData = $GLOBALS['gameRequest'][3] ?? '';
        $playerName = $GLOBALS['RELDYN_PLAYER_NAME'] ?? $GLOBALS['PLAYER_NAME'] ?? 'Player';

        // Find which NPC(s) in CACHE_PEOPLE are involved
        // CACHE_PEOPLE is a pipe-delimited string: "|Ashe|Lydia|Faendal|"
        $cachePeopleRaw = $GLOBALS['CACHE_PEOPLE'] ?? '';
        $cachePeople = array_values(array_filter(array_map('trim', explode('|', $cachePeopleRaw))));
        $involvedNpcs = [];

        // Method 1: Parse NPC name from event data
        // "NpcName is teamed up with Kaida..." (radiantcombatfriend)
        if (preg_match('/^(?:The Narrator:\s*)?(.+?)\s+is teamed up with\s+' . preg_quote($playerName, '/') . '/i', $eventData, $m)) {
            $involvedNpcs[] = trim($m[1]);
        }
        // "Kaida has defeated Enemy..." or "NpcName has defeated Enemy..."
        if (preg_match('/^(?:\(.*?\))?(.+?)\s+has defeated\s+/i', $eventData, $m)) {
            $killer = trim($m[1]);
            if (strcasecmp($killer, $playerName) !== 0) {
                $involvedNpcs[] = $killer; // NPC got the kill
            }
        }
        // "NpcName falls to the ground..." (bleedout)
        if (preg_match('/^(?:\(.*?\))?(.+?)\s+falls to the ground/i', $eventData, $m)) {
            $involvedNpcs[] = trim($m[1]);
        }

        // Method 2: Check CACHE_PEOPLE for any loaded NPCs during combat
        // If we couldn't parse a name but combat happened, check who's nearby
        if (empty($involvedNpcs) && !empty($cachePeople)) {
            foreach ($cachePeople as $pName) {
                if (!empty($pName) && strcasecmp($pName, $playerName) !== 0) {
                    // Only include if they have RelDyn dynamics (avoid random bystanders)
                    $testDyn = RelationshipDynamics::getDynamics($pName);
                    if (!empty($testDyn['love_language_primary'])) {
                        $involvedNpcs[] = $pName;
                    }
                }
            }
        }

        // -- Method 3: CACHE_PEOPLE + CACHE_PARTY witness fallback for death events --
        // Death events people column only contains the dead NPC, not nearby followers.
        // For kill events, check CACHE_PEOPLE and CACHE_PARTY to credit companions
        // who witnessed the kill, at a reduced multiplier (0.5x).
        $witnessNpcs = [];
        if ($reqType === 'death') {
            $witnessCandidates = [];
            // Gather from CACHE_PEOPLE (pipe-delimited nearby NPCs)
            if (!empty($cachePeople)) {
                foreach ($cachePeople as $pName) {
                    if (!empty($pName) && strcasecmp($pName, $playerName) !== 0) {
                        $witnessCandidates[$pName] = true;
                    }
                }
            }
            // Also check CACHE_PARTY (JSON object keyed by NPC name -- definitive followers)
            $cachePartyRaw = $GLOBALS['CACHE_PARTY'] ?? '';
            if (!empty($cachePartyRaw)) {
                $partyData = json_decode($cachePartyRaw, true);
                if (is_array($partyData)) {
                    foreach (array_keys($partyData) as $partyNpc) {
                        if (!empty($partyNpc) && strcasecmp($partyNpc, $playerName) !== 0) {
                            $witnessCandidates[$partyNpc] = true;
                        }
                    }
                }
            }
            // Filter: only NPCs with RelDyn dynamics, exclude already-found direct participants
            $directNpcs = array_map('strtolower', $involvedNpcs);
            foreach (array_keys($witnessCandidates) as $wName) {
                if (in_array(strtolower($wName), $directNpcs)) {
                    continue; // Already credited as direct participant
                }
                $wDyn = RelationshipDynamics::getDynamics($wName);
                if (!empty($wDyn['love_language_primary'])) {
                    $witnessNpcs[] = $wName;
                }
            }
            if (!empty($witnessNpcs)) {
                RelationshipDynamics::log("DEATH WITNESSES: " . implode(', ', $witnessNpcs) . " (from CACHE_PEOPLE+CACHE_PARTY)");
            }
        }

        // Apply combat passion to each involved NPC
        // Direct participants get full credit; witnesses get 0.5x
        $allCombatNpcs = array_unique(array_merge($involvedNpcs, $witnessNpcs));
        $witnessSet = array_map('strtolower', $witnessNpcs);
        foreach ($allCombatNpcs as $combatNpc) {
            $dynamics = RelationshipDynamics::getDynamics($combatNpc);
            if (empty($dynamics['love_language_primary'])) continue;

            // Determine combat classification
            $isWitness = in_array(strtolower($combatNpc), $witnessSet);
            $combatLL = RelationshipDynamics::LL_SERVICE; // default: positive combat
            if ($reqType === 'bleedout') {
                $combatLL = 'combat_bleedout';
            }

            // Combat context from core eventlog (health is unknown on 3.4.1: null)
            $combatCtx = RelationshipDynamics::getCombatContext($combatNpc);

            // Calculate passion change -- bleedout is a DRAIN, positive combat is a GAIN
            if ($combatLL === 'combat_bleedout') {
                // Bleedout drain: temperament-scaled negative passion
                $temperament = $dynamics['inferred_temperament'] ?? null;
                $gain = RelationshipDynamics::TEMPERAMENT_BLEEDOUT_DRAIN[$temperament] ?? -1.5;
                RelationshipDynamics::log("Bleedout drain: {$combatNpc} temperament={$temperament} base_drain={$gain}");
            } else {
                // Fighting together is an activity the NPC appraises (decisions §6): its combat
                // facets against the NPC's preferences scale the gain (MDD 1.2 0.5x-2.0x), feed
                // its internal weather and mark combat as fed (MDD 4.1 deprivation).
                $combatPrefs = RelDynFacets::preferences($dynamics, $combatNpc);
                $combatAppraisal = RelDynFacets::experienceThing($combatNpc, $dynamics, 'activity', 'combat',
                    $combatPrefs, RelationshipDynamics::currentGamets());
                $gain = RelationshipDynamics::calculatePassionGain($dynamics, $combatLL, $combatAppraisal);

                // Witnesses get reduced credit (0.5x) -- they saw the kill but didn't make it
                $isWitness = in_array(strtolower($combatNpc), $witnessSet);
                if ($isWitness) {
                    $gain *= 0.5;
                }

                // Kill streak bonus: multiple shared kills in 5 min window
                if ($combatCtx && $combatCtx['recent_kills'] > 1 && $gain > 0) {
                    $streakBonus = min(2.0, ($combatCtx['recent_kills'] - 1) * 0.5);
                    $gain += $streakBonus;
                    RelationshipDynamics::log("Kill streak bonus: +{$streakBonus} ({$combatCtx['recent_kills']} kills)");
                }

                // Shared danger bonus: low HP while fighting together (needs a known HP)
                if ($combatCtx && $combatCtx['in_combat'] && $gain > 0) {
                    $combatInterest = RelDynFacets::interestMultiplier($combatPrefs['combat']);   // MDD 1.2 0.5..2.0
                    $dangerThreshold = max(0.0, 0.30 - ($combatInterest * 0.15));
                    if ($combatCtx['health_pct'] !== null && $combatCtx['health_pct'] <= $dangerThreshold && $combatCtx['health_pct'] > 0) {
                        $gain *= 1.5; // shared danger intensity boost
                        RelationshipDynamics::log("Shared danger boost: HP={$combatCtx['health_pct']} threshold={$dangerThreshold}");
                    }
                    // Confirmed fighting together upgrades all gains
                    $gain *= 1.3;
                    RelationshipDynamics::log("Shared combat confirmed (source={$combatCtx['source']}): 1.3x multiplier");
                }
            }

            // Apply the passion change
            if (abs($gain) > 0.01) {
                if ($gain > 0) {
                    RelationshipDynamics::addPassion($dynamics, $gain, 'combat');
                    $dynamics['total_positive_interactions'] = intval($dynamics['total_positive_interactions'] ?? 0) + 1;
                } else {
                    // Negative drain (bleedout): clamp at zero, don't use addPassion
                    RelationshipDynamics::setPassion($dynamics, max(0, RelationshipDynamics::getPassion($dynamics) + $gain));
                    $dynamics['passion_updated_at'] = RelationshipDynamics::getPlayGamets($dynamics);
                }
                $dynamics['interaction_count'] = intval($dynamics['interaction_count'] ?? 0) + 1;
                $dynamics['last_interaction_at'] = RelationshipDynamics::getPlayGamets($dynamics);
                $dynamics['passion_sources']['combat'] = floatval($dynamics['passion_sources']['combat'] ?? 0) + $gain;
                RelationshipDynamics::saveDynamics($combatNpc, $dynamics);
                $witnessTag = $isWitness ? ' [WITNESS 0.5x]' : '';
                RelationshipDynamics::log("COMBAT EVENT: {$combatNpc} type={$reqType} LL={$combatLL} gain=" . round($gain, 2) . " passion=" . round($dynamics['passion'], 2) . " source=" . ($combatCtx['source'] ?? 'basic') . $witnessTag);
            }
        }

        // ========== DEATH/GRIEF SYSTEM (PR 10) ==========
        if ($reqType === 'death' && !empty($reldynCfg['grief_system_enabled'] ?? true)) {
            $deceasedName = null;
            // 3.4.1 has no HERIKA_EVENT_DATA/event_data globals; $eventData is $gameRequest[3]
            $eventData2 = $eventData ?? '';

            // Pattern 1: "X has defeated Y"
            if (preg_match('/has defeated\s+(.+?)[\.\s]*$/i', $eventData2, $m)) {
                $deceasedName = trim($m[1]);
            }
            // Pattern 2: "X killed Y"
            elseif (preg_match('/killed\s+(.+?)[\.\s]*$/i', $eventData2, $m)) {
                $deceasedName = trim($m[1]);
            }
            // Pattern 3: "Y died" or "Y has been slain"
            elseif (preg_match('/^(?:\(.*?\))?\s*(.+?)\s+(?:died|has been slain)/i', $eventData2, $m)) {
                $deceasedName = trim($m[1]);
            }

            // Strip narrator prefix
            if ($deceasedName) {
                $deceasedName = preg_replace('/^The Narrator:\s*/i', '', $deceasedName);
                $deceasedName = trim($deceasedName);
            }

            if ($deceasedName) {
                $activeNpcName = $GLOBALS['RELDYN_NPC_NAME'] ?? '';

                // Get all nearby NPCs who might witness the death
                $witnessList = [];
                // $cachePeople already defined above in the combat handler
                foreach ($cachePeople as $witness) {
                    if (!empty($witness) && strcasecmp($witness, $deceasedName) !== 0) {
                        $witnessList[] = $witness;
                    }
                }

                foreach ($witnessList as $witnessNpc) {
                    $isActiveNpc = (strcasecmp($witnessNpc, $activeNpcName) === 0);

                    if ($isActiveNpc) {
                        $witnessDynamics = &$dynamics;
                    } else {
                        $witnessDynamics = RelationshipDynamics::getDynamics($witnessNpc);
                    }

                    $witnessBonds = RelationshipDynamics::getAllBondsForNpc($witnessNpc);
                    if (isset($witnessBonds[$deceasedName])) {
                        $bondAff = ($witnessBonds[$deceasedName]['aff'] + 100) / 2.0;
                        if ($bondAff > 30) {
                            RelationshipDynamics::onNpcDeath($deceasedName, $witnessNpc, $witnessDynamics);
                            if (!$isActiveNpc) {
                                RelationshipDynamics::saveDynamics($witnessNpc, $witnessDynamics);
                            }
                        }
                    }
                }
            }
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
$radiantTypes = ['radiant', 'radiantsearchingfriend', 'radiantsearchinghostile',
    'radiantcombathostile', 'minai_force_rechat'];
if (in_array($reqType, $radiantTypes)) {
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
// A touch request (hug, kiss) feeds intimacy (PR 13 deprivation, on the game calendar)
RelationshipDynamics::recordIntimacyFromRequest($dynamics, $interactionLL, RelationshipDynamics::currentGamets());
// The same exchange as a love-language delivery to fulfillment (rulings §9), unless the eval
// scores it (its tags deliver then, in processEvalContractItem).
if (!$evalOwnsExchange) {
    RelationshipDynamics::recordLoveLanguageFulfillment($dynamics, $interactionLL, RelationshipDynamics::currentGamets());
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
if ($evalOwnsExchange) {
    RelationshipDynamics::log("POST legacy classifier stands down for {$npcName}: eval job {$evalJobId} scores this exchange (no local passion gain, affinity speed, repair or resentment decay)");
} elseif (($reldynCfg['passion_enabled'] ?? true) && $interactionLL !== null) {
    $rawPassionGain = RelationshipDynamics::calculatePassionGain($dynamics, $interactionLL);
    // Apply topic and flirt bonuses on top of base passion gain
    $passionGain = $rawPassionGain * $topicBonus * $flirtBonus;

    // ========== ATTRACTION x ATTACHMENT PASSION GATE (decisions §9) ==========
    $matrixPassionMult = RelationshipDynamics::attractionPassionMult($npcName, $dynamics);
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
$baseDelta = ($passionGain > 0) ? 1 : 0; // +1 per positive interaction

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
if ($passionGain > 0 && !empty($dynamics['in_conflict'])) {
    $repairBurst = RelationshipDynamics::recordConflictPositive($dynamics);
    if ($repairBurst > 0) {
        RelationshipDynamics::addPassion($dynamics, $repairBurst, 'repair');
    }
}

skip_conflict:

// ========== ATTRACTION TIER CEILING + PASSION HARD CAP (MDD 8 / 6.2) ==========
// The Matrix gates tier progression (getRelationshipType, attractionAllowsType), not affinity:
// affinity can still grow. Passion never ends a request above the attraction cap
// (friendzone / unattracted 20, a tolerated fail's reduced ceiling).
RelDynAttraction::enforcePassionCap($dynamics);

// ========== INTERACTION PATTERN TRACKING (PR 12) ==========
if (!empty($reldynCfg['parasite_detection_enabled'])) {
    $classifiedLL = $GLOBALS['RELDYN_LAST_INTERACTION_LL'] ?? null;
    $affinityDelta = floatval($GLOBALS['RELDYN_AFFINITY_DELTA'] ?? 0);
    RelationshipDynamics::updateInteractionPattern($dynamics, $classifiedLL, $affinityDelta);

    // Check for parasite detection/recovery
    RelationshipDynamics::checkParasitePattern($npcName, $dynamics);
    RelationshipDynamics::checkParasiteRecovery($npcName, $dynamics);
}

// A5: the eval readers below must only see this request's pending eval. With the
// dimension engine off nothing consumes it, so pendingEvalForRequest() drops it.
// Engine on: read-only peek of the latest queued eval; the inbox is consumed by
// processPendingEvalDeltas below.
$rdPendingEval = RelationshipDynamics::pendingEvalForRequest($npcName, $dynamics);

// ========== ICK TRACKER + CHARISMA DETECTION (PR 15) ==========
if (!empty($reldynCfg['ick_system_enabled'] ?? true)) {
    $classifiedLL = $GLOBALS['RELDYN_LAST_INTERACTION_LL'] ?? null;
    $evalPending = $rdPendingEval;
    $isRomantic = RelationshipDynamics::isRomanticAttempt($classifiedLL, $lastMood, $evalPending);
    $temperament = $dynamics['inferred_temperament'] ?? null;
    $ickChanged = RelationshipDynamics::updateIckTracker($dynamics, $isRomantic, $temperament);
    if ($ickChanged) {
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
    }
    $GLOBALS['RELDYN_ICK_ACTIVE'] = !empty($dynamics['_ick_tracker']['ick_active']);
}

if (!empty($reldynCfg['charisma_detection_enabled'] ?? true)) {
    $evalPending = $rdPendingEval;
    $romanticIntent = intval($evalPending['romantic_intent'] ?? 0);
    $affinityDelta = floatval($GLOBALS['RELDYN_AFFINITY_DELTA'] ?? 0);
    RelationshipDynamics::updateCharismaTracker($dynamics, $romanticIntent, $affinityDelta);
}

// -------------------------------------------------------------------------
// 7. Track positive interactions + stage advancement
// -------------------------------------------------------------------------
if ($passionGain > 0) {
    $dynamics['total_positive_interactions'] = intval($dynamics['total_positive_interactions'] ?? 0) + 1;
    RelationshipDynamics::checkStageAdvancement($dynamics);
}

// ========== MASKING STATE TRACKING (PR 14) ==========
// Track if masking state changed for mask-drop detection next cycle
if (isset($GLOBALS['RELDYN_MASKING_ACTIVE'])) {
    $dynamics['_was_masking'] = !empty($GLOBALS['RELDYN_MASKING_ACTIVE']);
}

// ========== DIARY COMPLETION (PR 14) ==========
// If diary was triggered and generated this cycle, mark completed
if (!empty($GLOBALS['RELDYN_DIARY_TRIGGERED'])) {
    RelationshipDynamics::markDiaryCompleted($dynamics);
}

// ========== DIRECTOR GOAL FULFILLMENT CHECK (PR 39, Step 5) ==========
$rdCfg = $rdCfg ?? RelationshipDynamics::getConfig();
if (!empty($rdCfg['director_goals_enabled'] ?? true)) {
    $activeGoal = RelationshipDynamics::getActiveDirectorGoal($dynamics);
    if ($activeGoal && !empty($activeGoal['text'])) {
        $evalData = $rdPendingEval;
        if (is_array($evalData) && !empty($evalData['goal_addressed'])) {
            RelationshipDynamics::fulfillDirectorGoal($dynamics, 'eval_confirmed');
        }
    }
}

// -------------------------------------------------------------------------
// 8. Save
// -------------------------------------------------------------------------
RelationshipDynamics::saveDynamics($npcName, $dynamics);

// ========== DUTY OVERRIDE DAMPENING (PR 12) ==========
$dutyFactor = floatval($GLOBALS['RELDYN_DUTY_FACTOR'] ?? 1.0);
if ($dutyFactor < 1.0) {
    // Only dampen negative deltas — positive quest moments still count
    // This is handled inside the eval processing — we set a global flag
    $GLOBALS['RELDYN_DUTY_DAMPEN_NEGATIVE'] = $dutyFactor;
}

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
    $wasPositive = ($passionGain ?? 0) > 0;
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
