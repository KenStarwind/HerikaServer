<?php
/**
 * Relationship Dynamics — Context Hook
 *
 * Runs after other extension context hooks (alphabetical order).
 * Injects <emotional_dynamics> narrative block into LLM context.
 * Player never sees numbers — only behavioral descriptions.
 */

$npcName = $GLOBALS['HERIKA_NAME'] ?? '';
if (empty($npcName) || $npcName === 'The Narrator') {
    return;
}

require_once __DIR__ . '/relationship_dynamics.php';

if (!RelationshipDynamics::isEnabled()) {
    return;
}

$dynamics = RelationshipDynamics::getDynamics($npcName);

// Only inject if love language has been generated
if (empty($dynamics['love_language_primary'])) {
    return;
}

$passion = floatval($dynamics['passion'] ?? 0);
$jealousy = floatval($dynamics['jealousy_anger'] ?? 0);
$stage = $dynamics['stage'] ?? 'early';
$temperament = $dynamics['inferred_temperament'] ?? '';
$inConflict = !empty($dynamics['in_conflict']);
$reunionGiven = !empty($dynamics['reunion_spike_given']);

$parts = [];

// -------------------------------------------------------------------------
// Passion state (the core emotional temperature)
// -------------------------------------------------------------------------
$passionBand = RelationshipDynamics::getPassionBand($passion);
$passionContext = [
    'burning'  => "{$npcName} feels an electric tension with {$GLOBALS['PLAYER_NAME']} — barely able to keep composure, pulse racing, hyperaware of every word and movement.",
    'intense'  => "{$npcName} feels a palpable warmth and heightened awareness around {$GLOBALS['PLAYER_NAME']} — drawn to them, finding excuses to stay close.",
    'warm'     => "{$npcName} feels a growing excitement around {$GLOBALS['PLAYER_NAME']} — something is building between them, an undeniable pull.",
    'stirring' => "{$npcName} feels a pleasant warmth when {$GLOBALS['PLAYER_NAME']} is near — something faint stirring beneath the surface.",
    'faint'    => "{$npcName} feels the faintest spark of... something... when {$GLOBALS['PLAYER_NAME']} speaks.",
];
if (isset($passionContext[$passionBand])) {
    $parts[] = $passionContext[$passionBand];
}

// -------------------------------------------------------------------------
// Blush self-awareness (dynamic — only fires on passion spike)
// -------------------------------------------------------------------------
$lastDelta = floatval($dynamics['_last_passion_delta'] ?? 0);
$blushMult = floatval($dynamics['pending_blush_mult'] ?? 1.0);
if ($lastDelta >= 4.0 && $blushMult >= 1.5) {
    // High delta + love language match = strong involuntary response
    if ($lastDelta >= 7.0) {
        $parts[] = "<blush_awareness>Heat floods {$npcName}'s face unbidden — a deep, visible flush they cannot suppress. Their body is betraying something their words haven't admitted yet. They are acutely aware of it.</blush_awareness>";
    } else {
        $parts[] = "<blush_awareness>Unexpected warmth rises in {$npcName}'s cheeks — an involuntary response they didn't anticipate. They notice it happening and it catches them off guard.</blush_awareness>";
    }
    // Clear after injection — one-shot per spike
    $dynamics['_last_passion_delta'] = 0;
    $dynamics['pending_blush_mult'] = 1.0;
    RelationshipDynamics::saveDynamics($npcName, $dynamics);
} elseif ($lastDelta >= 2.0 && $blushMult >= 1.0) {
    // Moderate delta — subtle warmth, not full blush
    $parts[] = "<blush_awareness>A faint warmth touches {$npcName}'s skin — barely perceptible, but they feel it. Something about this moment landed differently than expected.</blush_awareness>";
    $dynamics['_last_passion_delta'] = 0;
    RelationshipDynamics::saveDynamics($npcName, $dynamics);
}

// -------------------------------------------------------------------------
// Relationship stage framing
// -------------------------------------------------------------------------
$stageContext = [
    'early'       => "{$npcName} and {$GLOBALS['PLAYER_NAME']} are still discovering each other — everything feels heightened, uncertain, full of possibility.",
    'established' => "{$npcName} and {$GLOBALS['PLAYER_NAME']} have settled into comfortable familiarity — they know each other's rhythms and patterns.",
    'deep'        => "{$npcName} and {$GLOBALS['PLAYER_NAME']} share something profound and resilient — a bond forged through shared time and experience that weathered storms.",
];
if (isset($stageContext[$stage])) {
    $parts[] = $stageContext[$stage];
}

// -------------------------------------------------------------------------
// Reunion warmth
// -------------------------------------------------------------------------
if ($reunionGiven) {
    $lastSeen = intval($dynamics['last_seen_at'] ?? 0);
    $hoursApart = $lastSeen > 0 ? (time() - $lastSeen) / 3600.0 : 0;
    $player = $GLOBALS['PLAYER_NAME'];

    // Temperament-aware reunion text
    $reunionText = RelationshipDynamics::getReunionText($npcName, $temperament, $hoursApart, $player);
    if ($reunionText) {
        $parts[] = $reunionText;
    }
}

// -------------------------------------------------------------------------
// Jealousy state
// -------------------------------------------------------------------------
$jealousyBand = RelationshipDynamics::getJealousyBand($jealousy);
$triggerNpc = $dynamics['jealousy_trigger_npc'] ?? null;
$jealousyContext = [
    'seething'  => "{$npcName} is seething — jaw clenched, barely containing fury. Something about " . ($triggerNpc ? "{$triggerNpc} and " : "") . "{$GLOBALS['PLAYER_NAME']} has deeply wounded them.",
    'hurt'      => "{$npcName} is visibly hurt and suspicious — their warmth has turned brittle, edged with accusation" . ($triggerNpc ? " about {$triggerNpc}" : "") . ".",
    'unsettled' => "{$npcName} seems unsettled — guarded, with flashes of hurt when certain topics arise.",
    'edgy'      => "{$npcName} has a slight edge — something is bothering them, a hint of insecurity lurking beneath the surface.",
];
if (isset($jealousyContext[$jealousyBand])) {
    $parts[] = $jealousyContext[$jealousyBand];
}

// -------------------------------------------------------------------------
// Conflict / Repair state
// -------------------------------------------------------------------------
if ($inConflict) {
    $repairCount = intval($dynamics['conflict_positive_count'] ?? 0);
    if ($repairCount >= 2) {
        $parts[] = "{$npcName} is cautiously warming again — the hurt isn't gone, but {$GLOBALS['PLAYER_NAME']}'s efforts are reaching through. Each kind word carries extra weight right now.";
    } elseif ($repairCount >= 1) {
        $parts[] = "{$npcName} is still hurt but watching {$GLOBALS['PLAYER_NAME']}'s actions closely — every positive gesture carries extra weight right now, like testing whether this person can be trusted again.";
    } else {
        $parts[] = "{$npcName} is wounded and wary — something {$GLOBALS['PLAYER_NAME']} did cut deep. They need to see genuine effort before the walls come down.";
    }
}

// -------------------------------------------------------------------------
// Love language discovery hints (pure behavioral — no labels)
// -------------------------------------------------------------------------
// The last interaction's love language match gets a hint in context
// so the LLM can describe the reaction differently
$lastLL = $GLOBALS['RELDYN_LAST_INTERACTION_LL'] ?? null;
$primaryLL = $dynamics['love_language_primary'] ?? null;
$secondaryLL = $dynamics['love_language_secondary'] ?? null;

if ($lastLL && $primaryLL) {
    if ($lastLL === $primaryLL) {
        // Strong resonance hint
        $hints = [
            'words_of_affirmation' => "{$npcName}'s eyes brighten noticeably — these words clearly reach something deep. Their whole demeanor softens.",
            'quality_time'         => "{$npcName} seems genuinely grateful for {$GLOBALS['PLAYER_NAME']}'s presence — as if their company alone is a precious gift.",
            'physical_touch'       => "{$npcName}'s breath catches slightly at the contact — their whole posture softens, leaning into it almost involuntarily.",
            'acts_of_service'      => "{$npcName} watches what {$GLOBALS['PLAYER_NAME']} did with quiet intensity — actions like this speak louder than any words could.",
            'gifts'                => "{$npcName} handles the offering with surprising tenderness — more moved than the gift's value alone would suggest.",
        ];
        if (isset($hints[$lastLL])) {
            $parts[] = $hints[$lastLL];
        }
    } elseif ($lastLL === $secondaryLL) {
        // Moderate resonance
        $parts[] = "{$npcName} appreciates the gesture warmly — it clearly means something to them, though perhaps not as deeply as some other form of affection might.";
    } else {
        // No match — contrast signal (this IS the discovery mechanic)
        $parts[] = "{$npcName} acknowledges the gesture with a polite smile — appreciative, but something tells you this isn't quite what moves them most.";
    }
}

// -------------------------------------------------------------------------
// Interest resonance (shared experience context)
// -------------------------------------------------------------------------
// Use cached ambient result from prerequest (avoids double-computing)
$currentInterest = $GLOBALS['RELDYN_AMBIENT_INTEREST'] ?? null;
$currentResonance = floatval($GLOBALS['RELDYN_AMBIENT_RESONANCE'] ?? 0.0);
$currentLocation = $GLOBALS['RELDYN_AMBIENT_LOCATION'] ?? '';
$currentSource = $GLOBALS['RELDYN_AMBIENT_SOURCE'] ?? 'none';

if ($currentInterest && $currentResonance >= 0.15) {
    if ($currentSource === 'vector' && $currentResonance >= 0.3) {
        // Rich vector-based resonance text — the NPC is responding to the specific place
        $intText = RelationshipDynamics::getEnvironmentalResonanceText($npcName, $currentInterest, $currentResonance, $currentLocation);
        if ($intText) $parts[] = $intText;
    } else {
        // Keyword-based or low resonance — use original interest category text
        $intPrefs = RelationshipDynamics::getInterests($dynamics);
        $rawMult = floatval($intPrefs[$currentInterest] ?? 1.0);
        $intText = RelationshipDynamics::getInterestResonanceText($npcName, $currentInterest, $rawMult);
        if ($intText) $parts[] = $intText;
    }
}

// -------------------------------------------------------------------------
// Topic resonance hint (from previous interaction's topic match)
// -------------------------------------------------------------------------
$lastTopicMatch = $dynamics['_last_topic_match'] ?? null;
if (!empty($lastTopicMatch)) {
    $parts[] = "<topic_resonance>{$npcName} was genuinely engaged by a recent conversation about {$lastTopicMatch} — this topic touched on something they truly care about. If the subject comes up again, they'll light up.</topic_resonance>";
}

// -------------------------------------------------------------------------
// NPC initiation context (LLM decides, we just provide the urge)
// -------------------------------------------------------------------------
if ($passion >= 40 && $primaryLL) {
    $initiationHints = [
        'words_of_affirmation' => "{$npcName} has a strong urge to express what they feel — the words are right there, wanting to come out.",
        'quality_time'         => "{$npcName} doesn't want this moment to end — they want to find reasons to keep {$GLOBALS['PLAYER_NAME']} close.",
        'physical_touch'       => "{$npcName} is acutely aware of the space between them and {$GLOBALS['PLAYER_NAME']} — wanting to close it.",
        'acts_of_service'      => "{$npcName} wants to DO something for {$GLOBALS['PLAYER_NAME']} — to show through action what words can't capture.",
        'gifts'                => "{$npcName} thinks about what they could give {$GLOBALS['PLAYER_NAME']} — something meaningful, something that says what they feel.",
    ];
    if (isset($initiationHints[$primaryLL])) {
        $parts[] = $initiationHints[$primaryLL];
    }
}


// -------------------------------------------------------------------------
// Combat awareness (shared danger / post-combat glow)
// -------------------------------------------------------------------------
$combatCtx = RelationshipDynamics::getCombatContext($npcName);
$player = $GLOBALS['PLAYER_NAME'] ?? 'Player';

if ($combatCtx) {
    if (!empty($combatCtx['bleeding_out'])) {
        $parts[] = "{$npcName} is critically wounded and barely conscious. The pain is overwhelming — every breath is a fight to stay awake.";
    } elseif ($combatCtx['in_combat']) {
        $hpPct = $combatCtx['health_pct'];
        if ($hpPct < 0.3) {
            $parts[] = "{$npcName} is badly hurt but still fighting alongside {$player}. The shared danger sharpens every sense.";
        } elseif ($hpPct < 0.6) {
            $parts[] = "{$npcName} is wounded but holding the line with {$player}. The adrenaline of shared combat bonds them.";
        } else {
            $parts[] = "{$npcName} fights alongside {$player}. The rhythm of shared combat — watching each other's backs, coordinating strikes — builds unspoken trust.";
        }
    } elseif ($combatCtx['in_combat']) {
        $parts[] = "{$npcName} is engaged in combat. Adrenaline sharpens focus and strips away social pretense.";
    }
}

// Post-combat glow — combat ended recently but NPC is no longer in active combat
if (!$combatCtx || !$combatCtx['in_combat']) {
    $recentCombat = RelationshipDynamics::getRecentCombatSummary($npcName);
    if ($recentCombat) {
        $parts[] = "The adrenaline from recent combat still lingers. {$npcName} and {$player} just survived a fight together — that shared experience hangs in the air.";
    }
}

// -------------------------------------------------------------------------
// Assemble and inject
// -------------------------------------------------------------------------
if (!empty($parts)) {
    $block = "<emotional_dynamics>\n" . implode("\n", $parts) . "\n</emotional_dynamics>";
    $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => $block];
    RelationshipDynamics::log("CTX: Injected emotional_dynamics for {$npcName}: " . count($parts) . " parts, passion={$passion}");
} else {
    RelationshipDynamics::log("CTX: No parts for {$npcName}: passion={$passion} stage={$stage}");
}

// ========== TIERED CONTEXT INJECTION ==========
//
// Injects <relational_dimensions> block with band keywords for active dimensions.
// Gated behind dimension_context_enabled — zero change to existing context when off.
// Placed AFTER <emotional_dynamics> to extend, not replace.
//
// Tier 0 (Stranger)     : 0-25 affinity — bare minimum ("stranger, no established history")
// Tier 1 (Acquaintance) : 26-40 affinity — band keywords only (cap 5 lines)
// Tier 2 (Friend+)      : 41-70 affinity — keywords + maturity guidance + recent shifts (cap 8 lines)
// Tier 3 (Bonded+)      : 71-100 affinity — full dimensional state + reasons + maturity + shifts (cap 10 lines)
//
// High water mark: once tier 2 is reached, it becomes the permanent floor.
// Tier 3 requires active high affinity (71+) — drops back to tier 2 if bond breaks.
// "You don't forget who someone is because you hate them."
// ==================================================

$rdCfg = RelationshipDynamics::getConfig();
if (!empty($rdCfg['dimension_context_enabled']) && !empty($dynamics['dimensions'])) {

    // -----------------------------------------------------------------
    // Update HWM and compute effective tier
    // -----------------------------------------------------------------
    $hwmChanged = RelationshipDynamics::updateContextTierHWM($dynamics);
    $contextTier = RelationshipDynamics::getContextTier($dynamics);
    $hwmValue = intval($dynamics['context_tier_hwm'] ?? 0);

    // Persist HWM if it ratcheted up
    if ($hwmChanged) {
        RelationshipDynamics::saveDynamics($npcName, $dynamics);
    }

    // Log the tier
    RelationshipDynamics::log("[RelDyn-CTX] Context tier for {$npcName}: {$contextTier} (hwm={$hwmValue})");

    $player = $GLOBALS['PLAYER_NAME'] ?? 'Player';
    $dims = $dynamics['dimensions'];
    $dimDebug = !empty($rdCfg['dimension_debug_logging']);

    // Tier-based line caps
    $tierMaxLines = [0 => 0, 1 => 5, 2 => 8, 3 => 10];
    $maxLines = $tierMaxLines[$contextTier] ?? 10;

    // Config override can still lower the cap (but not raise above tier cap)
    $configMax = intval($rdCfg['dimension_max_context_lines'] ?? 10);
    if ($configMax > 0) {
        $maxLines = min($maxLines, $configMax);
    }

    // -----------------------------------------------------------------
    // Tier 0: Stranger — inject minimal context, skip dimension loop
    // -----------------------------------------------------------------
    if ($contextTier === 0) {
        $dimBlock = "<relational_dimensions>\n{$npcName} is a stranger to {$player} — no established emotional history.\n</relational_dimensions>";
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => $dimBlock];

        RelationshipDynamics::log("CTX: Injected relational_dimensions for {$npcName}: tier 0 (stranger)");

        if ($dimDebug) {
            error_log("[RelDyn-CTX] Dimension context for {$npcName}: tier 0 (stranger), hwm={$hwmValue}");
        }
    } else {
        // -----------------------------------------------------------------
        // Tiers 1-3: Build dimension candidate lines
        // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // Priority filtering: skip dimensions at/near baseline, skip inactive
    // -----------------------------------------------------------------
    $candidateLines = [];
    $includedDims = [];
    $skippedDims = [];

    // Track M/F and A/V combo handling (same logic as buildDimensionContext)
    $mfHandled = false;
    $avHandled = false;

    foreach ($dims as $dimId => $dimData) {
        // Skip inactive dimensions (x is null)
        if (!isset($dimData['x']) || $dimData['x'] === null) {
            $skippedDims[] = "{$dimId}(inactive)";
            continue;
        }

        $x = floatval($dimData['x']);

        // --- M/F Coordinates: combine into one quadrant entry ---
        if ($dimId === 'coord_m' || $dimId === 'coord_f') {
            if ($mfHandled) {
                continue;
            }
            $mfHandled = true;

            $mData = $dims['coord_m'] ?? null;
            $fData = $dims['coord_f'] ?? null;

            if ($mData === null || !isset($mData['x']) || $mData['x'] === null
                || $fData === null || !isset($fData['x']) || $fData['x'] === null) {
                $skippedDims[] = 'coord_mf(inactive)';
                continue;
            }
            // M/F: check distance from temperament baseline
            $mBase = floatval($mData['baseline'] ?? 0);
            $fBase = floatval($fData['baseline'] ?? 0);
            $mDist = abs(floatval($mData['x']) - $mBase);
            $fDist = abs(floatval($fData['x']) - $fBase);

            if ($mDist <= 10 && $fDist <= 10) {
                $skippedDims[] = 'coord_mf(near_baseline)';
                continue;
            }

            $band = RelationshipDynamics::getMFQuadrantBand($mData['x'], $fData['x']);
            $candidateLines[] = [
                'dimId'    => 'coord_mf',
                'priority' => max($mDist, $fDist),
                'extreme'  => false,
                'line'     => "Behavioral Mode: {$band['label']} — {$band['keywords']}",
            ];
            $includedDims[] = 'coord_mf';
            continue;
        }

        // --- Arousal/Valence: combine into one combo entry ---
        if ($dimId === 'arousal' || $dimId === 'valence') {
            if ($avHandled) {
                continue;
            }
            $avHandled = true;

            $aData = $dims['arousal'] ?? null;
            $vData = $dims['valence'] ?? null;

            if ($aData === null || !isset($aData['x']) || $aData['x'] === null
                || $vData === null || !isset($vData['x']) || $vData['x'] === null) {
                $skippedDims[] = 'arousal_valence(inactive)';
                continue;
            }

            // Skip if arousal is at resting baseline (10 or below)
            if (floatval($aData['x']) <= 10) {
                $skippedDims[] = 'arousal_valence(resting)';
                continue;
            }

            $aBase = floatval($aData['baseline'] ?? 10);
            $vBase = floatval($vData['baseline'] ?? 0);
            $aDist = abs(floatval($aData['x']) - $aBase);
            $vDist = abs(floatval($vData['x']) - $vBase);

            if ($aDist <= 10 && $vDist <= 10) {
                $skippedDims[] = 'arousal_valence(near_baseline)';
                continue;
            }

            $band = RelationshipDynamics::getArousalValenceBand($aData['x'], $vData['x']);
            $candidateLines[] = [
                'dimId'    => 'arousal_valence',
                'priority' => max($aDist, $vDist),
                'extreme'  => (floatval($aData['x']) > 80 || floatval($aData['x']) < 10),
                'line'     => "Emotional State: {$band['label']} — {$band['keywords']}",
            ];
            $includedDims[] = 'arousal_valence';
            continue;
        }
        // --- Standard single-axis dimensions ---
        $def = RelationshipDynamics::getDimensionDefinition($dimId);
        if (!$def) {
            $skippedDims[] = "{$dimId}(unknown)";
            continue;
        }

        $baseline = floatval($dimData['baseline'] ?? $def['default_baseline']);
        $dist = abs($x - $baseline);

        // Get the band
        $band = RelationshipDynamics::getDimensionBand($dimId, $x);
        if ($band === null) {
            $skippedDims[] = "{$dimId}(no_band)";
            continue;
        }

        // Skip resentment clean-slate band (empty keywords)
        if ($dimId === 'resentment' && empty($band['keywords'])) {
            $skippedDims[] = "{$dimId}(clean_slate)";
            continue;
        }

        // Determine if this is an extreme band (first or last in the band table)
        $allBands = RelationshipDynamics::DIMENSION_BANDS[$dimId] ?? [];
        $isExtreme = false;
        if (!empty($allBands)) {
            $firstBand = $allBands[0];
            $lastBand = $allBands[count($allBands) - 1];
            $isExtreme = ($x >= $firstBand['range'][0] && $x <= $firstBand['range'][1])
                      || ($x >= $lastBand['range'][0] && $x <= $lastBand['range'][1]);
        }

        // Priority filtering: skip if near baseline and not extreme
        if ($dist <= 10 && !$isExtreme) {
            $skippedDims[] = "{$dimId}(near_baseline:{$dist})";
            continue;
        }

        // Build the context line
        $label = RelationshipDynamics::DIMENSION_LABELS[$dimId] ?? ucfirst(str_replace('_', ' ', $dimId));

        if ($dimId === 'resentment') {
            $lineText = "{$npcName} internal state (not visible to {$player}): {$band['keywords']}";
        } else {
            $lineText = "{$label}: {$band['label']} — {$band['keywords']}";
        }

        $candidateLines[] = [
            'dimId'    => $dimId,
            'priority' => $dist,
            'extreme'  => $isExtreme,
            'line'     => $lineText,
        ];
        $includedDims[] = $dimId;
    }

    // Sort candidates: extreme bands first, then by distance from baseline (descending)
    usort($candidateLines, function ($a, $b) {
        // Extreme bands always win
        if ($a['extreme'] && !$b['extreme']) return -1;
        if (!$a['extreme'] && $b['extreme']) return 1;
        // Then by priority (distance from baseline) descending
        return $b['priority'] <=> $a['priority'];
    });

    // Cap at max lines
    $candidateLines = array_slice($candidateLines, 0, $maxLines);
    // -----------------------------------------------------------------
    // Build the dimension context lines
    // -----------------------------------------------------------------
    // --- Text Intensity post-processing on dimension keyword lines ---
    // Transform each keyword line through the intensity engine at render time.
    // Only applies if arousal, passion, or maturity have non-default values.
    $intensityActive = false;
    $iArousal  = floatval($dims['arousal']['x'] ?? 10);
    $iPassion  = floatval($dims['passion']['x'] ?? 0);
    $iMaturity = floatval($dims['maturity']['x'] ?? 60);
    if ($iArousal > 10 || $iPassion > 15 || $iMaturity < 56 || ($iArousal < 10 && $iPassion < 10)) {
        $intensityActive = true;
    }

    $dimParts = [];
    foreach ($candidateLines as $entry) {
        $line = $entry['line'];

        // Apply text intensity to the keywords portion of each line
        if ($intensityActive) {
            // Pattern: "Label: Band â keywords" or "NPC internal state (...): keywords"
            if (preg_match('/^(.+?\xe2\x80\x94\s*)(.+)$/', $line, $m)) {
                $m[2] = RelationshipDynamics::applyTextIntensity($m[2], $dynamics);
                $line = $m[1] . $m[2];
            } elseif (preg_match('/^(.+?\):\s*)(.+)$/', $line, $m)) {
                $m[2] = RelationshipDynamics::applyTextIntensity($m[2], $dynamics);
                $line = $m[1] . $m[2];
            }
        }

        $dimParts[] = $line;
    }

    // -----------------------------------------------------------------
    // Maturity-specific guidance (tier 2+ only)
    // -----------------------------------------------------------------
    if ($contextTier >= 2) {
    $maturityData = $dims['maturity'] ?? null;
    if ($maturityData && isset($maturityData['x']) && $maturityData['x'] !== null) {
        $matX = floatval($maturityData['x']);
        $matBand = RelationshipDynamics::getDimensionBand('maturity', $matX);
        if ($matBand) {
            $matLabel = $matBand['label'];
            $matKeywords = $matBand['keywords'];
            $matXRounded = round($matX);
            $dimParts[] = "<maturity_guidance>"
                . "{$npcName}'s emotional maturity is {$matLabel} ({$matXRounded}/100): {$matKeywords}. "
                . "This affects HOW they express other emotions — a mature NPC handles jealousy differently than an immature one."
                . "</maturity_guidance>";
        }
    }


    // -----------------------------------------------------------------
    // Dimensional memory context (PR 9 — tier 2+ only)
    //
    // Injects <dimensional_memory> block with the NPC's strongest
    // memories about this bond. Gives the LLM specific events to
    // reference in dialogue instead of generic emotional statements.
    // Gated by dimension_context_enabled AND context tier >= 2.
    // -----------------------------------------------------------------
    $memoryPlayer = $GLOBALS['PLAYER_NAME'] ?? 'Player';
    $memoryBlock = RelationshipDynamics::buildMemoryContext($dynamics, $npcName, $memoryPlayer, 5);
    if ($memoryBlock !== null) {
        $dimParts[] = $memoryBlock;
        RelationshipDynamics::log("[RelDyn-CTX] Injected dimensional_memory for {$npcName}, bond={$memoryPlayer}");
    }

    // -----------------------------------------------------------------
    // Recent emotional shifts (tier 2+ only, from last_reason stored per dimension)
    //
    // When eval processing stores a 'last_reason' string in a dimension's
    // state, we surface it here so the LLM has specific events to
    // reference in dialogue. Gracefully no-ops when field is absent.
    // -----------------------------------------------------------------
    $shiftLines = [];
    foreach ($dims as $dimId => $dimData) {
        if (empty($dimData['last_reason']) || !is_string($dimData['last_reason'])) {
            continue;
        }
        // Determine direction from last_delta if available
        $shiftDelta = floatval($dimData['last_delta'] ?? 0);
        $direction = $shiftDelta >= 0 ? 'increased' : 'decreased';
        $label = RelationshipDynamics::DIMENSION_LABELS[$dimId] ?? ucfirst(str_replace('_', ' ', $dimId));

        // M/F and Arousal/Valence use their combo labels
        if ($dimId === 'coord_m' || $dimId === 'coord_f') {
            $label = 'Behavioral Mode';
            $direction = 'shifted';
        } elseif ($dimId === 'arousal' || $dimId === 'valence') {
            $label = 'Emotional State';
            $direction = 'shifted';
        }

        $reason = trim($dimData['last_reason']);
        // Cap reason length to prevent token bloat
        if (strlen($reason) > 120) {
            $reason = substr($reason, 0, 117) . '...';
        }
        $shiftLines[] = "- {$label} {$direction}: \"{$reason}\"";
    }

    if (!empty($shiftLines)) {
        // Cap shift lines to avoid token bloat
        $shiftLines = array_slice($shiftLines, 0, 5);
        $dimParts[] = "<recent_emotional_shifts>"
            . "\n" . implode("\n", $shiftLines) . "\n"
            . "</recent_emotional_shifts>";
    }
    }
    // -----------------------------------------------------------------
    // Assemble and inject dimension context
    // -----------------------------------------------------------------
    if (!empty($dimParts)) {
        $dimBlock = "<relational_dimensions>\n" . implode("\n", $dimParts) . "\n</relational_dimensions>";
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => $dimBlock];

        $dimCharCount = strlen($dimBlock);
        $dimCount = count($candidateLines);

        RelationshipDynamics::log("CTX: Injected relational_dimensions for {$npcName}: {$dimCount} dims, {$dimCharCount} chars, tier={$contextTier}");

        // Debug logging — detailed breakdown
        if ($dimDebug) {
            $includedStr = implode(', ', $includedDims);
            $skippedStr = implode(', ', $skippedDims);
            error_log("[RelDyn-CTX] Dimension context for {$npcName}: {$dimCount} dims, {$dimCharCount} chars, tier={$contextTier}, hwm={$hwmValue}");
            error_log("[RelDyn-CTX]   Included: {$includedStr}");
            error_log("[RelDyn-CTX]   Skipped: {$skippedStr}");
            if (!empty($shiftLines)) {
                error_log("[RelDyn-CTX]   Shift reasons: " . count($shiftLines));
            }
        }
    } elseif ($dimDebug) {
        $skippedStr = implode(', ', $skippedDims);
        error_log("[RelDyn-CTX] Dimension context for {$npcName}: 0 dims (all skipped/inactive), tier={$contextTier}, hwm={$hwmValue}");
        error_log("[RelDyn-CTX]   Skipped: {$skippedStr}");
    }
    }
}

// ========== ATTRACTION CONTEXT (PR 11) ==========
$matrixResult = $GLOBALS['RELDYN_ATTRACTION_MATRIX'] ?? null;
if ($matrixResult && !empty($matrixResult['enabled'])) {
    $attractionText = RelationshipDynamics::generateAttractionContext($npcName, $matrixResult, $dynamics);
    if (!empty($attractionText)) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<attraction_context>{$attractionText}</attraction_context>"];
    }
}

// ========== DUTY OVERRIDE CONTEXT (PR 12) ==========
$dutyFactor = floatval($GLOBALS['RELDYN_DUTY_FACTOR'] ?? 1.0);
if ($dutyFactor < 1.0) {
    $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' =>
        "<duty_context>This interaction involves quest-required dialogue. {$npcName} understands the player may be acting under obligation, not personal choice.</duty_context>"];
}

// ========== PARASITE/FRIENDZONE TYPE CONTEXT (PR 12) ==========
$relTypeOverride = $dynamics['_relationship_type_override'] ?? null;
if ($relTypeOverride === 'parasite') {
    $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' =>
        "<relationship_type_context>{$npcName} has noticed a pattern. The gifts keep coming but there is no substance behind them. They feel used, not valued. The warmth in their voice has a transactional edge.</relationship_type_context>"];
}

// ========== INTERNAL WEATHER CONTEXT (PR 13) ==========
$weather = $dynamics['_internal_weather'] ?? 'clear';
if ($weather !== 'clear') {
    $weatherContext = [
        'sunny'    => "{$npcName} is in good spirits -- their needs are being met and it shows.",
        'overcast' => "{$npcName} seems a little off. Something is missing but they may not be able to name it.",
        'stormy'   => "{$npcName} is visibly restless and dissatisfied. Multiple needs have gone unmet for too long.",
    ];
    if (isset($weatherContext[$weather])) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<internal_weather>{$weatherContext[$weather]}</internal_weather>"];
    }
}

// ========== INTIMACY DEPRIVATION CONTEXT (PR 13) ==========
$intimacyContext = RelationshipDynamics::generateIntimacyDeprivationContext($npcName, $dynamics);
if ($intimacyContext) {
    $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<intimacy_state>{$intimacyContext}</intimacy_state>"];
}

// ========== CREATURE STATE CONTEXT (PR 13) ==========
$creatureType = RelationshipDynamics::detectCreatureType($npcName, $dynamics);
if ($creatureType) {
    $isNight = RelationshipDynamics::isGameNight();
    $isFullMoon = RelationshipDynamics::isFullMoon();
    if ($creatureType === 'vampire' && $isNight) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' =>
            "<creature_state>{$npcName}'s vampiric nature is ascendant. The night feeds their predatory edge -- sharper, hungrier, more intense. The mask of humanity is thinner now.</creature_state>"];
    } elseif ($creatureType === 'werewolf' && $isFullMoon) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' =>
            "<creature_state>The full moon pulls at {$npcName}'s beast blood. They are fighting for control -- primal urges surge against trained restraint. Everything is more raw, more immediate, more dangerous.</creature_state>"];
    } elseif ($creatureType === 'werewolf' && $isNight) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' =>
            "<creature_state>{$npcName}'s beast blood stirs quietly in the night. Not a full transformation, but the primal edge is there -- heightened senses, shorter patience, deeper instincts.</creature_state>"];
    }
}

// ========== EMERGENT EMOTION CONTEXT (PR 13) ==========
$emotions = RelationshipDynamics::detectEmergentEmotions($dynamics);
if (!empty($emotions)) {
    $emotionText = RelationshipDynamics::generateEmergentEmotionContext($npcName, $emotions);
    if ($emotionText) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<emergent_emotion>{$emotionText}</emergent_emotion>"];
    }
}

// ========== SOCIAL MASKING CONTEXT (PR 14) ==========
$maskingActive = !empty($GLOBALS['RELDYN_MASKING_ACTIVE']);
if ($maskingActive) {
    $performedState = $GLOBALS['RELDYN_PERFORMED_STATE'] ?? RelationshipDynamics::calculatePerformedState($dynamics);
    $maskContext = RelationshipDynamics::generateMaskingContext($npcName, $dynamics, $performedState);
    if ($maskContext) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<social_mask>\n{$maskContext}\n</social_mask>"];
    }
} else {
    // Check for mask drop (was masking last cycle, not masking now)
    $maskDropText = RelationshipDynamics::generateMaskDropContext($npcName, $dynamics);
    if ($maskDropText) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<mask_drop>{$maskDropText}</mask_drop>"];
    }
}

// ========== UNSTABLE WINDOW CRISIS SCENE (PR 10) ==========
$unstableWindow = $dynamics['_unstable_window'] ?? null;
if ($unstableWindow && empty($unstableWindow['resolved'])) {
    $reldynCfg = $reldynCfg ?? RelationshipDynamics::getConfig();
    if (!empty($reldynCfg['divine_intervention_enabled'])) {
        $currentPlayGamets = floatval($dynamics['_accumulated_play_gamets'] ?? 0);
        $windowElapsed = $currentPlayGamets - floatval($unstableWindow['start_gamets']);
        $windowDuration = floatval($unstableWindow['duration_gamets']);
        $unstableWindow['_elapsed_fraction'] = ($windowDuration > 0) ? ($windowElapsed / $windowDuration) : 0;

        $crisisText = RelationshipDynamics::generateCrisisNarration($npcName, $unstableWindow);
        if ($crisisText) {
            $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<crisis_state>{$crisisText}</crisis_state>"];
            RelationshipDynamics::log("[RelDyn-CTX] Injected crisis_state for {$npcName}");
        }
    }
}

// ========== GRIEF CONTEXT (PR 10) ==========
$griefBonds = $dynamics['_grief_bonds'] ?? [];
if (!empty($griefBonds)) {
    $reldynCfg = $reldynCfg ?? RelationshipDynamics::getConfig();
    if (!empty($reldynCfg['grief_system_enabled'] ?? true)) {
        // Cap to 2 most significant grief bonds (by affinity at death)
        $sortedGrief = $griefBonds;
        uasort($sortedGrief, function($a, $b) {
            return ($b['bond_affinity_at_death'] ?? 0) <=> ($a['bond_affinity_at_death'] ?? 0);
        });
        $topGriefBonds = array_slice($sortedGrief, 0, 2, true);

        foreach ($topGriefBonds as $deceasedName => $grief) {
            $phase = intval($grief['phase']);
            $maturity = floatval(($dynamics['dimensions']['maturity']['x'] ?? 50));
            $griefKeywords = RelationshipDynamics::getGriefKeywords($npcName, $deceasedName, $phase, $maturity);
            if ($griefKeywords) {
                $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<grief_state>{$griefKeywords}</grief_state>"];
                RelationshipDynamics::log("[RelDyn-CTX] Injected grief_state for {$npcName}, deceased={$deceasedName}, phase={$phase}");
            }
        }
    }
}

// ========== ICK CONTEXT (PR 15) ==========
if (!empty($GLOBALS['RELDYN_ICK_ACTIVE'])) {
    $temperament = $dynamics['inferred_temperament'] ?? null;
    $ickText = RelationshipDynamics::getIckContext($dynamics, $npcName, $temperament);
    if ($ickText) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<ick_state>{$ickText}</ick_state>"];
        RelationshipDynamics::log("[RelDyn-CTX] Injected ick_state for {$npcName}");
    }
}

// ========== CHARISMA AWARENESS CONTEXT (PR 15) ==========
$charismaText = RelationshipDynamics::getCharismaContext($dynamics, $npcName);
if ($charismaText) {
    $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<charisma_awareness>{$charismaText}</charisma_awareness>"];
    RelationshipDynamics::log("[RelDyn-CTX] Injected charisma_awareness for {$npcName}");
}

// ========== AUTONOMY CONTEXT (PR 16) ==========
$reldynCfg = $reldynCfg ?? RelationshipDynamics::getConfig();
if (!empty($reldynCfg['autonomy_enabled'] ?? true)) {
    $autoTemperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic';
    $autonomyText = RelationshipDynamics::getAutonomyContext($dynamics, $npcName, $autoTemperament);
    if ($autonomyText) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<autonomy_state>{$autonomyText}</autonomy_state>"];
        RelationshipDynamics::log("[RelDyn-CTX] Injected autonomy_state for {$npcName}");
    }
}

// ========== HOOVER CONTEXT (PR 16) ==========
if (!empty($reldynCfg['hoover_enabled'] ?? true)) {
    $hooverText = RelationshipDynamics::getHooverContext($dynamics, $npcName);
    if ($hooverText) {
        $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' => "<hoover_state>{$hooverText}</hoover_state>"];
        RelationshipDynamics::log("[RelDyn-CTX] Injected hoover_state for {$npcName}");
    }
}

// ========== DIRECTOR GOAL CONTEXT (PR 39) ==========
$directorGoal = $GLOBALS['RELDYN_DIRECTOR_GOAL'] ?? RelationshipDynamics::getActiveDirectorGoal($dynamics);
if ($directorGoal && !empty($directorGoal['text'])) {
    $goalText = trim($directorGoal['text']);
    $priority = floatval($directorGoal['priority'] ?? 0.5);
    $urgency = $priority >= 0.8 ? "This is a strong internal drive right now."
             : ($priority >= 0.5 ? "This is on their mind." : "This is a background thought.");

    $GLOBALS['contextDataFull'][] = ['role' => 'system', 'content' =>
        "<director_goal>{$npcName}'s current purpose: {$goalText}. {$urgency}</director_goal>"];
}

