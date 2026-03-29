<?php
/**
 * Relationship Dynamics — Per-NPC Editor Section
 * Included inside npc_master.php partial form (inside <details> block).
 * Expects $editItem to be in scope from npc_master.php.
 */

if (empty($editItem['npc_name'])) return;

$rdNpcName = $editItem['npc_name'];

// Load relationship dynamics from core_npc_master (CHIM-native)
$rdDynamics = [];
try {
    $rdRow = $GLOBALS['db']->fetchOne(
        "SELECT extended_data FROM core_npc_master WHERE lower(npc_name) = lower("
        . $GLOBALS['db']->escapeLiteral($rdNpcName) . ") LIMIT 1"
    );
    if ($rdRow) {
        $rdExt = json_decode($rdRow['extended_data'] ?? '{}', true) ?: [];
        $rdDynamics = $rdExt['relationship_dynamics'] ?? [];
    }
} catch (Throwable $e) {
    // Silently fail — section will show defaults
}

// Current values (with defaults)
$rdLLPrimary    = $rdDynamics['love_language_primary'] ?? '';
$rdLLSecondary  = $rdDynamics['love_language_secondary'] ?? '';
$rdWarmth       = $rdDynamics['warmth_curve'] ?? '';
$rdTemperament  = $rdDynamics['inferred_temperament'] ?? '';
$rdRelPref      = $rdDynamics['relationship_preference'] ?? '';
$rdOpenness     = $rdDynamics['openness'] ?? '';
$rdAttachment   = $rdDynamics['attachment_style'] ?? '';
$rdSensitivity  = $rdDynamics['social_sensitivity_curve'] ?? '';
$rdHomeLocation = $rdDynamics['home_location'] ?? '';
$rdPassion      = floatval($rdDynamics['passion'] ?? 0);
$rdJealousy     = floatval($rdDynamics['jealousy_anger'] ?? 0);
$rdStage        = $rdDynamics['stage'] ?? 'early';
$rdTotalPos     = intval($rdDynamics['total_positive_interactions'] ?? 0);
$rdInConflict   = !empty($rdDynamics['in_conflict']);
$rdInterests    = $rdDynamics['interests'] ?? ($rdDynamics['activity_preferences'] ?? []);
$rdInteractions = intval($rdDynamics['interaction_count'] ?? 0);

// ========== MATURITY DIMENSION (PR 3) ==========
// Ensure class is loaded for getDimensionBand()
if (!class_exists('RelationshipDynamics')) {
    @include_once __DIR__ . '/relationship_dynamics.php';
}
$rdMaturityDims = $rdDynamics['dimensions']['maturity'] ?? [];
$rdMaturityX = $rdMaturityDims['x'] ?? null;
$rdMaturityBaseline = $rdMaturityDims['baseline'] ?? null;
$rdMaturityPlasticityType = $rdMaturityDims['plasticity_type'] ?? '';
$rdMaturityActive = !empty($rdMaturityDims['active']);

// Compute band label from X value
$rdMaturityBandLabel = '';
$rdMaturityBandKeywords = '';
if ($rdMaturityX !== null && class_exists('RelationshipDynamics')) {
    $rdMatBand = RelationshipDynamics::getDimensionBand('maturity', $rdMaturityX);
    if ($rdMatBand) {
        $rdMaturityBandLabel = $rdMatBand['label'];
        $rdMaturityBandKeywords = $rdMatBand['keywords'];
    }
}

// Maturity plasticity type options
$rdMaturityPlasticityOptions = [
    '' => '-- From temperament --',
    'Resilient' => 'Resilient (hard to break, normal rebuild)',
    'Growth'    => 'Growth (resists collapse, amplifies improvement)',
    'Brittle'   => 'Brittle (easy to break, hard to rebuild)',
    'Volatile'  => 'Volatile (big swings both ways)',
    'Rigid'     => 'Rigid (barely moves, set in ways)',
    'Adaptive'  => 'Adaptive (symmetric default)',
];

// ========== TRUST DIMENSION (PR 4) ==========
$rdTrustDims = $rdDynamics['dimensions']['trust'] ?? [];
$rdTrustX = $rdTrustDims['x'] ?? null;
$rdTrustBaseline = $rdTrustDims['baseline'] ?? null;
$rdTrustActive = !empty($rdTrustDims['active']);

// Compute trust band label from X value
$rdTrustBandLabel = '';
$rdTrustBandKeywords = '';
if ($rdTrustX !== null && class_exists('RelationshipDynamics')) {
    $rdTrustBand = RelationshipDynamics::getDimensionBand('trust', $rdTrustX);
    if ($rdTrustBand) {
        $rdTrustBandLabel = $rdTrustBand['label'];
        $rdTrustBandKeywords = $rdTrustBand['keywords'];
    }
}

// ========== RESPECT DIMENSION (PR 4) ==========
$rdRespectDims = $rdDynamics['dimensions']['respect'] ?? [];
$rdRespectX = $rdRespectDims['x'] ?? null;
$rdRespectBaseline = $rdRespectDims['baseline'] ?? null;
$rdRespectActive = !empty($rdRespectDims['active']);

// Compute respect band label from X value
$rdRespectBandLabel = '';
$rdRespectBandKeywords = '';
if ($rdRespectX !== null && class_exists('RelationshipDynamics')) {
    $rdRespectBand = RelationshipDynamics::getDimensionBand('respect', $rdRespectX);
    if ($rdRespectBand) {
        $rdRespectBandLabel = $rdRespectBand['label'];
        $rdRespectBandKeywords = $rdRespectBand['keywords'];
    }
}

// ========== COMFORT DIMENSION (PR 4) ==========
$rdComfortDims = $rdDynamics['dimensions']['comfort'] ?? [];
$rdComfortX = $rdComfortDims['x'] ?? null;
$rdComfortBaseline = $rdComfortDims['baseline'] ?? null;
$rdComfortActive = !empty($rdComfortDims['active']);

// Compute comfort band label from X value
$rdComfortBandLabel = '';
$rdComfortBandKeywords = '';
if ($rdComfortX !== null && class_exists('RelationshipDynamics')) {
    $rdComfortBand = RelationshipDynamics::getDimensionBand('comfort', $rdComfortX);
    if ($rdComfortBand) {
        $rdComfortBandLabel = $rdComfortBand['label'];
        $rdComfortBandKeywords = $rdComfortBand['keywords'];
    }
}
// ========== M/F COORDINATES (PR 6) ==========$rdCoordFDims = $rdDynamics['dimensions']['coord_f'] ?? [];$rdCoordFX = $rdCoordFDims['x'] ?? null;$rdCoordFBaseline = $rdCoordFDims['baseline'] ?? null;$rdCoordFActive = !empty($rdCoordFDims['active']);// Compute M/F quadrant band (needs both M and F to be set)$rdCoordMDims = $rdDynamics['dimensions']['coord_m'] ?? [];$rdCoordMX = $rdCoordMDims['x'] ?? null;$rdMFQuadrantLabel = '';$rdMFQuadrantKeywords = '';if ($rdCoordMX !== null && $rdCoordFX !== null && class_exists('RelationshipDynamics')) {    $rdMFBand = RelationshipDynamics::getMFQuadrantBand($rdCoordMX, $rdCoordFX);    if ($rdMFBand) {        $rdMFQuadrantLabel = $rdMFBand['label'];        $rdMFQuadrantKeywords = $rdMFBand['keywords'];    }}

// ========== AROUSAL/VALENCE (PR 6) ==========
$rdArousalDims = $rdDynamics['dimensions']['arousal'] ?? [];
$rdArousalX = $rdArousalDims['x'] ?? 0;
$rdArousalBaseline = $rdArousalDims['baseline'] ?? 10;
$rdArousalActive = !empty($rdArousalDims['active']);

$rdValenceDims = $rdDynamics['dimensions']['valence'] ?? [];
$rdValenceX = $rdValenceDims['x'] ?? 0;
$rdValenceBaseline = $rdValenceDims['baseline'] ?? 0;
$rdValenceActive = !empty($rdValenceDims['active']);

// ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========
$rdSelfConfDims = $rdDynamics['dimensions']['self_confidence'] ?? [];
$rdSelfConfX = $rdSelfConfDims['x'] ?? null;
$rdSelfConfBaseline = $rdSelfConfDims['baseline'] ?? null;
$rdSelfConfActive = !empty($rdSelfConfDims['active']);

// Compute self-confidence band label from X value
$rdSelfConfBandLabel = '';
$rdSelfConfBandKeywords = '';
if ($rdSelfConfX !== null && class_exists('RelationshipDynamics')) {
    $rdSelfConfBand = RelationshipDynamics::getDimensionBand('self_confidence', $rdSelfConfX);
    if ($rdSelfConfBand) {
        $rdSelfConfBandLabel = $rdSelfConfBand['label'];
        $rdSelfConfBandKeywords = $rdSelfConfBand['keywords'];
    }
}

// Compute derived confidence input signal (read-only display)
$rdSelfConfInput = null;
if ($rdSelfConfActive && class_exists('RelationshipDynamics') && method_exists('RelationshipDynamics', 'deriveConfidenceInput')) {
    $rdSelfConfInput = RelationshipDynamics::deriveConfidenceInput($rdDynamics);
}

// Compute combined arousal/valence band
$rdAVBandLabel = '';
$rdAVBandKeywords = '';
if ($rdArousalActive && class_exists('RelationshipDynamics')) {
    $rdAVBand = RelationshipDynamics::getArousalValenceBand($rdArousalX, $rdValenceX);
    if ($rdAVBand) {
        $rdAVBandLabel = $rdAVBand['label'];
        $rdAVBandKeywords = $rdAVBand['keywords'];
    }
}

// ========== RESENTMENT_SELF (PR 7) ==========
$rdResentmentSelfDims = $rdDynamics['dimensions']['resentment_self'] ?? [];
$rdResentmentSelfX = $rdResentmentSelfDims['x'] ?? 0;
$rdResentmentSelfBaseline = $rdResentmentSelfDims['baseline'] ?? 0;
$rdResentmentSelfActive = !empty($rdResentmentSelfDims['active']);

// Compute resentment_self band label from X value
$rdResentmentSelfBandLabel = '';
$rdResentmentSelfBandKeywords = '';
if ($rdResentmentSelfActive && class_exists('RelationshipDynamics')) {
    $rdResentmentSelfBand = RelationshipDynamics::getDimensionBand('resentment_self', $rdResentmentSelfX);
    if ($rdResentmentSelfBand) {
        $rdResentmentSelfBandLabel = $rdResentmentSelfBand['label'];
        $rdResentmentSelfBandKeywords = $rdResentmentSelfBand['keywords'];
    }
}
// Compute resentment_self threshold status
$rdResentmentSelfThresholds = [];
if ($rdResentmentSelfActive && class_exists('RelationshipDynamics')) {
    $rdResentmentSelfThresholds = RelationshipDynamics::checkResentmentSelfThresholds($rdDynamics);
}

// ========== M/F COORDINATES (PR 6) ==========
$rdCoordMDims = $rdDynamics['dimensions']['coord_m'] ?? [];
$rdCoordMX = $rdCoordMDims['x'] ?? null;
$rdCoordMBaseline = $rdCoordMDims['baseline'] ?? null;
$rdCoordMActive = !empty($rdCoordMDims['active']);

// Compute M/F quadrant band if both coordinates are available
$rdCoordMQuadrantLabel = '';
$rdCoordMQuadrantKeywords = '';
$rdCoordFX = ($rdDynamics['dimensions']['coord_f']['x'] ?? null);
if ($rdCoordMX !== null && $rdCoordFX !== null && class_exists('RelationshipDynamics')) {
    $rdMFBand = RelationshipDynamics::getMFQuadrantBand($rdCoordMX, $rdCoordFX);
    if ($rdMFBand) {
        $rdCoordMQuadrantLabel = $rdMFBand['label'];
        $rdCoordMQuadrantKeywords = $rdMFBand['keywords'];
    }
}

// ========== RESENTMENT DIMENSION (PR 7) ==========
$rdResentmentDims = $rdDynamics['dimensions']['resentment'] ?? [];
$rdResentmentX = floatval($rdResentmentDims['x'] ?? 0);
$rdResentmentBaseline = floatval($rdResentmentDims['baseline'] ?? 0);
$rdResentmentActive = !empty($rdResentmentDims['active']);
$rdGrievanceLog = $rdResentmentDims['grievance_log'] ?? [];

// Compute resentment band label from X value
$rdResentmentBandLabel = '';
$rdResentmentBandKeywords = '';
if ($rdResentmentActive && class_exists('RelationshipDynamics')) {
    $rdResBand = RelationshipDynamics::getDimensionBand('resentment', $rdResentmentX);
    if ($rdResBand) {
        $rdResentmentBandLabel = $rdResBand['label'];
        $rdResentmentBandKeywords = $rdResBand['keywords'];
    }
}

// Love language options
$rdLLOptions = [
    '' => '— Auto-generate —',
    'words_of_affirmation' => 'Words of Affirmation',
    'quality_time' => 'Quality Time',
    'physical_touch' => 'Physical Touch',
    'acts_of_service' => 'Acts of Service',
    'gifts' => 'Gifts',
];

// Warmth curve options
$rdCurveOptions = [
    '' => '— Auto-generate —',
    'slow_burn' => 'Slow Burn (10h half-life, trust earned slowly)',
    'moderate' => 'Moderate (8h half-life, open but paces)',
    'quick_warmth' => 'Quick Warmth (6h half-life, warms in 1-2 days)',
    'guarded' => 'Guarded (12h half-life, hardest to crack)',
];

// Temperament options (11 types)
$rdTempOptions = [
    '' => '— Auto-generate —',
    'Romantic' => 'Romantic (passion ×1.3, falls fast)',
    'Anxious' => 'Anxious (reunion ×1.8, fears abandonment)',
    'Bold' => 'Bold (passion ×1.1, confident & direct)',
    'Playful' => 'Playful (passion ×1.4, flirty & volatile)',
    'Humble' => 'Humble (passion ×1.1, steady & modest)',
    'Nurturing' => 'Nurturing (reunion ×1.2, caretaker)',
    'Jealous' => 'Jealous (jealousy ×2.0, possessive)',
    'Proud' => 'Proud (passion ×0.8, demands respect)',
    'Guarded' => 'Guarded (passion ×0.6, slow burn, huge payoff)',
    'Independent' => 'Independent (passion ×0.7, autonomy-first)',
    'Stoic' => 'Stoic (passion ×0.5, duty-first, deep quiet loyalty)',
];

// Relationship preference options
$rdRelPrefOptions = [
    '' => '— Not set —',
    'monogamous' => 'Monogamous',
    'polyamorous' => 'Polyamorous',
    'uncommitted' => 'Uncommitted',
    'demisexual' => 'Demisexual',
    'asexual' => 'Asexual',
    'not_interested' => 'Not Interested',
];

// Openness options
$rdOpennessOptions = [
    '' => '— Auto from temperament —',
    'high' => 'High (tolerant, effort compensates)',
    'medium' => 'Medium (soft blocks, 2x effort needed)',
    'low' => 'Low (hard blocks, strict standards)',
];

// Interest types and labels
$rdInterestTypes = [
    'combat'     => ['label' => 'Combat',     'icon' => '⚔️'],
    'crafting'   => ['label' => 'Crafting',   'icon' => '⚒️'],
    'alchemy'    => ['label' => 'Alchemy',    'icon' => '⚗️'],
    'enchanting' => ['label' => 'Enchanting', 'icon' => '✨'],
    'scholarly'  => ['label' => 'Scholarly',  'icon' => '📖'],
    'nature'     => ['label' => 'Nature',     'icon' => '🌲'],
    'social'     => ['label' => 'Social',     'icon' => '🍺'],
    'domestic'   => ['label' => 'Domestic',   'icon' => '🏠'],
    'adventure'  => ['label' => 'Adventure',  'icon' => '🗺️'],
    'spiritual'  => ['label' => 'Spiritual',  'icon' => '🙏'],
    'wealth'     => ['label' => 'Wealth',     'icon' => '💎'],
];

// Stage thresholds
$rdStageThresholds = ['early' => 0, 'established' => 50, 'deep' => 200];
$rdNextThreshold = ($rdStage === 'early') ? 50 : (($rdStage === 'established') ? 200 : null);

// API base URL
$rdApiUrl = '';
$rdScriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$rdUiPos = strpos($rdScriptPath, '/ui/');
if ($rdUiPos !== false) {
    $rdApiUrl = substr($rdScriptPath, 0, $rdUiPos);
}
?>

<div class="form-item span-2" id="reldyn-editor-section">
<details style="border:1px solid #4a4a4a; border-radius:8px; padding:12px; background:#262626; margin-top:4px;">
    <summary style="cursor:pointer; font-weight:700; color:rgb(242, 124, 17); font-size:1.05em; letter-spacing:0.5px;">
        💕 Relationship Dynamics
    </summary>

    <div style="margin-top:14px;" id="reldyn-content">

        <!-- Row 1: Love Languages + Warmth Curve -->
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;">
                    Primary Love Language
                </label>
                <select id="reldyn_ll_primary" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <?php foreach ($rdLLOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= $rdLLPrimary === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;">
                    Secondary Love Language
                </label>
                <select id="reldyn_ll_secondary" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <?php foreach ($rdLLOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= $rdLLSecondary === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;">
                    Warmth Curve
                </label>
                <select id="reldyn_warmth" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <?php foreach ($rdCurveOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= $rdWarmth === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Row 2: Temperament + Stage + Status -->
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; margin-top:12px;">
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;">
                    Temperament
                </label>
                <select id="reldyn_temperament" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <?php foreach ($rdTempOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= $rdTemperament === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;">
                    Stage
                </label>
                <div style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; padding:6px 8px; color:#e9efff; font-size:0.9em;">
                    <?php
                    $stageEmoji = ['early' => '🌱', 'established' => '🌿', 'deep' => '🌳'];
                    echo ($stageEmoji[$rdStage] ?? '') . ' ' . ucfirst($rdStage);
                    if ($rdNextThreshold !== null) {
                        echo " <span style='color:#888;'>({$rdTotalPos}/{$rdNextThreshold} interactions)</span>";
                    } else {
                        echo " <span style='color:#888;'>({$rdTotalPos} interactions)</span>";
                    }
                    ?>
                </div>
            </div>
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;">
                    Status
                </label>
                <div style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; padding:6px 8px; color:#e9efff; font-size:0.9em;">
                    <?php if ($rdInConflict): ?>
                        <span style="color:#f87171;">⚠️ In Conflict</span>
                    <?php elseif ($rdPassion >= 40): ?>
                        <span style="color:#fbbf24;">🔥 High Passion</span>
                    <?php elseif ($rdPassion >= 15): ?>
                        <span style="color:#86efac;">✨ Warming</span>
                    <?php else: ?>
                        <span style="color:#9fb1c9;">◽ Neutral</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Row 2b: Relationship Preference + Openness -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;"
                    title="How this NPC approaches exclusivity. Affects jealousy triggers and commitment gating.">
                    Relationship Preference
                </label>
                <select id="reldyn_rel_pref" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <?php foreach ($rdRelPrefOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= $rdRelPref === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;"
                    title="How tolerant this NPC is of the player failing attraction checks. High = forgiving, Low = strict gatekeeping.">
                    Openness
                </label>
                <select id="reldyn_openness" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <?php foreach ($rdOpennessOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>"<?= $rdOpenness === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Row 2c: Attachment Style (PR 10) -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;"
                    title="Attachment style affects jealousy sensitivity, conflict patterns, and emotional volatility. Blank = derive from temperament.">
                    Attachment Style
                </label>
                <select id="reldyn_attachment_style" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <option value=""<?= $rdAttachment === '' ? ' selected' : '' ?>>-- From temperament --</option>
                    <option value="secure"<?= $rdAttachment === 'secure' ? ' selected' : '' ?>>Secure</option>
                    <option value="avoidant"<?= $rdAttachment === 'avoidant' ? ' selected' : '' ?>>Avoidant</option>
                    <option value="anxious"<?= $rdAttachment === 'anxious' ? ' selected' : '' ?>>Anxious</option>
                    <option value="toxic"<?= $rdAttachment === 'toxic' ? ' selected' : '' ?>>Toxic / Disorganized</option>
                </select>
            </div>
            <div>
                <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:4px; font-size:0.85em;"
                    title="How much this NPC cares about input from different bond levels. Inner Circle = only close bonds land. Open Heart = everyone's opinion matters. Blank = derive from temperament.">
                    Social Sensitivity
                </label>
                <select id="reldyn_social_sensitivity" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                    <option value=""<?= $rdSensitivity === '' ? ' selected' : '' ?>>-- From temperament --</option>
                    <option value="inner_circle"<?= $rdSensitivity === 'inner_circle' ? ' selected' : '' ?>>Inner Circle (only close bonds land)</option>
                    <option value="open_heart"<?= $rdSensitivity === 'open_heart' ? ' selected' : '' ?>>Open Heart (everyone's opinion matters)</option>
                    <option value="uniform"<?= $rdSensitivity === 'uniform' ? ' selected' : '' ?>>Uniform (doesn't discriminate)</option>
                    <option value="inverse_tolerance"<?= $rdSensitivity === 'inverse_tolerance' ? ' selected' : '' ?>>Inverse Tolerance (close bonds get patience)</option>
                    <option value="romantic_mid"<?= $rdSensitivity === 'romantic_mid' ? ' selected' : '' ?>>Romantic Mid (inner circle + open heart blend)</option>
                </select>
            </div>
        </div>

        <!-- Row 2d: Home Location (PR 16) -->
        <div style="margin-top:12px; border:1px solid #3a5a3a; border-radius:6px; padding:12px; background:#1a2a1a;">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label style="font-weight:700; color:#4ade80; display:block; margin-bottom:4px; font-size:0.85em;"
                        title="Where this NPC goes when they walk away. Used by walkaway protocol. Leave blank for vanilla home.">
                        Home Location (Walkaway Destination)
                    </label>
                    <input type="text" id="reldyn_home_location"
                        value="<?= htmlspecialchars($rdHomeLocation) ?>"
                        placeholder="e.g. Breezehome, Lakeview Manor (blank = vanilla home)"
                        list="reldyn_home_locations_list"
                        style="background:#0d1a0d; border:1px solid #3a5a3a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                    <?php
                    // Populate datalist with known locations
                    $rdLocations = [];
                    try {
                        $locRows = $GLOBALS['db']->fetchAll("SELECT DISTINCT name FROM locations WHERE name IS NOT NULL AND name != '' ORDER BY name LIMIT 200");
                        if (is_array($locRows)) {
                            foreach ($locRows as $lr) {
                                $rdLocations[] = $lr['name'];
                            }
                        }
                    } catch (\Throwable $e) { /* silent */ }
                    ?>
                    <datalist id="reldyn_home_locations_list">
                        <?php foreach ($rdLocations as $loc): ?>
                        <option value="<?= htmlspecialchars($loc) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div style="display:flex; align-items:center;">
                    <span style="color:#6a8a6a; font-size:0.78em; font-style:italic;">
                        Used by Walkaway Protocol. NPC travels here when they walk away.<br>
                        If blank, falls back to vanilla ReturnHome command.
                    </span>
                </div>
            </div>
        </div>

        <!-- Row 2e: Attraction Profile (PR 11) -->
        <div style="margin-top:12px; border:1px solid #4a3a5a; border-radius:6px; padding:12px; background:#1e1a2e;">
            <label style="font-weight:700; color:#c084fc; display:block; margin-bottom:8px; font-size:0.85em;">
                Attraction Profile
            </label>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <div>
                    <label style="font-weight:600; color:#a78bfa; display:block; margin-bottom:4px; font-size:0.8em;"
                        title="Archetype preset that pre-fills attraction weights. Leave blank to auto-derive from interests.">
                        Archetype Preset
                    </label>
                    <select id="reldyn_attraction_archetype" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                        <option value="">-- Auto from interests --</option>
                        <?php
                        $rdAttractionProfile = $rdDynamics['attraction_profile'] ?? null;
                        $rdCurrentArchetype = '';
                        foreach (['Warrior', 'Noble', 'Scholar', 'Rogue', 'Priest', 'Primal', 'Bard'] as $arch) {
                            $sel = ($rdCurrentArchetype === $arch) ? ' selected' : '';
                            echo "<option value=\"{$arch}\"{$sel}>{$arch}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600; color:#a78bfa; display:block; margin-bottom:4px; font-size:0.8em;"
                        title="Comma-separated keywords describing what this NPC finds physically attractive.">
                        Beauty Keywords
                    </label>
                    <input type="text" id="reldyn_attraction_beauty_keywords"
                        value="<?= htmlspecialchars(implode(', ', $rdAttractionProfile['beauty_keywords'] ?? [])) ?>"
                        placeholder="e.g. strong, scarred, tall"
                        style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:8px;">
                <div>
                    <label style="font-weight:600; color:#a78bfa; display:block; margin-bottom:4px; font-size:0.8em;"
                        title="How this NPC approaches physical intimacy. Visceral=physical first, Bond=commitment required, Balanced=either path.">
                        Intimacy Gate
                    </label>
                    <select id="reldyn_attraction_intimacy_gate" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                        <?php
                        $rdAttrGate = $rdAttractionProfile['intimacy_gate'] ?? '';
                        foreach (['visceral' => 'Visceral (physical first)', 'bond' => 'Bond (commitment required)', 'balanced' => 'Balanced'] as $val => $label) {
                            $sel = ($rdAttrGate === $val) ? ' selected' : '';
                            echo "<option value=\"{$val}\"{$sel}>{$label}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600; color:#a78bfa; display:block; margin-bottom:4px; font-size:0.8em;"
                        title="Gender preference for romantic/sexual attraction.">
                        Gender Preference
                    </label>
                    <select id="reldyn_attraction_gender_pref" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                        <?php
                        $rdAttrGP = $rdAttractionProfile['gender_pref'] ?? '';
                        foreach (['heterosexual' => 'Heterosexual', 'homosexual' => 'Homosexual', 'bisexual' => 'Bisexual'] as $val => $label) {
                            $sel = ($rdAttrGP === $val) ? ' selected' : '';
                            echo "<option value=\"{$val}\"{$sel}>{$label}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Row 3: Passion + Jealousy bars -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
            <div>
                <label style="font-weight:600; color:#9fb1c9; display:block; margin-bottom:4px; font-size:0.8em;">
                    Passion (RPM) — <?= round($rdPassion, 1) ?>/100
                </label>
                <div style="background:#1a1a1a; border:1px solid #3a3a3a; border-radius:4px; height:20px; overflow:hidden;">
                    <div style="height:100%; width:<?= min(100, $rdPassion) ?>%; background:linear-gradient(90deg, #22c55e, #fbbf24 50%, #ef4444); border-radius:4px; transition:width 0.3s;"></div>
                </div>
            </div>
            <div>
                <label style="font-weight:600; color:#9fb1c9; display:block; margin-bottom:4px; font-size:0.8em;">
                    Jealousy — <?= round($rdJealousy, 1) ?>/100
                </label>
                <div style="background:#1a1a1a; border:1px solid #3a3a3a; border-radius:4px; height:20px; overflow:hidden;">
                    <div style="height:100%; width:<?= min(100, $rdJealousy) ?>%; background:linear-gradient(90deg, #fbbf24, #ef4444); border-radius:4px; transition:width 0.3s;"></div>
                </div>
            </div>
        </div>

        <!-- ========== MATURITY DIMENSION (PR 3) ========== -->
        <?php if ($rdMaturityActive): ?>
        <div style="margin-top:16px; border:1px solid #3a3a3a; border-radius:6px; padding:12px; background:#1e1e1e;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:rgb(242, 124, 17); font-size:0.85em;">
                    Maturity
                    <?php if ($rdMaturityBandLabel): ?>
                        <span style="color:#9fb1c9; font-weight:400;"> -- <?= htmlspecialchars($rdMaturityBandLabel) ?></span>
                    <?php endif; ?>
                </label>
                <button type="button" onclick="reldynResetMaturity()"
                    title="Reset maturity X and baseline to temperament default"
                    style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#fde68a; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdMaturityBandKeywords): ?>
            <div style="color:#888; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdMaturityBandKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
                <!-- Maturity X value -->
                <div>
                    <label style="font-weight:600; color:#9fb1c9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_maturity_x"
                            min="0" max="100" step="1"
                            value="<?= $rdMaturityX !== null ? round($rdMaturityX) : 50 ?>"
                            style="flex:1; accent-color:rgb(242, 124, 17); height:6px;"
                            oninput="reldynUpdateMaturitySlider()">
                        <span id="reldyn_maturity_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= $rdMaturityX !== null ? round($rdMaturityX) : '50' ?>
                        </span>
                    </div>
                    <!-- Mini progress bar colored by band -->
                    <div style="background:#1a1a1a; border:1px solid #3a3a3a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_maturity_bar" style="height:100%; width:<?= $rdMaturityX !== null ? min(100, max(0, $rdMaturityX)) : 50 ?>%; background:linear-gradient(90deg, #ef4444 0%, #fbbf24 30%, #22c55e 60%, #3b82f6 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>

                <!-- Maturity Baseline -->
                <div>
                    <label style="font-weight:600; color:#9fb1c9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_maturity_baseline"
                        min="0" max="100" step="1"
                        value="<?= $rdMaturityBaseline !== null ? round($rdMaturityBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>

                <!-- Maturity Plasticity Type -->
                <div>
                    <label style="font-weight:600; color:#9fb1c9; display:block; margin-bottom:4px; font-size:0.8em;"
                        title="Determines how maturity responds to pressure. Resilient=hard to break. Growth=resists collapse. Brittle=easy to break. Volatile=big swings. Rigid=barely moves. Adaptive=symmetric.">
                        Plasticity Type
                    </label>
                    <select id="reldyn_maturity_plasticity" style="background:#1a1a1a; border:1px solid #4a4a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em;">
                        <?php foreach ($rdMaturityPlasticityOptions as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>"<?= $rdMaturityPlasticityType === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ========== TRUST DIMENSION (PR 4) ========== -->
        <?php if ($rdTrustActive): ?>
        <div style="margin-top:16px; border:1px solid #3a5a7a; border-radius:6px; padding:12px; background:#1a2a3a;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#5b9bd5; font-size:0.85em;">
                    Trust
                    <?php if ($rdTrustBandLabel): ?>
                        <span style="color:#8ab4d9; font-weight:400;"> -- <?= htmlspecialchars($rdTrustBandLabel) ?></span>
                    <?php endif; ?>
                </label>
                <button type="button" onclick="reldynResetTrust()"
                    title="Reset trust X and baseline to temperament default"
                    style="background:#1a2a3a; border:1px solid #3a5a7a; border-radius:4px; color:#8ab4d9; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdTrustBandKeywords): ?>
            <div style="color:#6a8faa; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdTrustBandKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <!-- Trust X value -->
                <div>
                    <label style="font-weight:600; color:#8ab4d9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_trust_x"
                            min="0" max="100" step="1"
                            value="<?= $rdTrustX !== null ? round($rdTrustX) : 35 ?>"
                            style="flex:1; accent-color:#5b9bd5; height:6px;"
                            oninput="reldynUpdateTrustSlider()">
                        <span id="reldyn_trust_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= $rdTrustX !== null ? round($rdTrustX) : '35' ?>
                        </span>
                    </div>
                    <!-- Mini progress bar colored by band -->
                    <div style="background:#0d1520; border:1px solid #3a5a7a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_trust_bar" style="height:100%; width:<?= $rdTrustX !== null ? min(100, max(0, $rdTrustX)) : 35 ?>%; background:linear-gradient(90deg, #ef4444 0%, #fbbf24 25%, #5b9bd5 60%, #22c55e 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>

                <!-- Trust Baseline -->
                <div>
                    <label style="font-weight:600; color:#8ab4d9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_trust_baseline"
                        min="0" max="100" step="1"
                        value="<?= $rdTrustBaseline !== null ? round($rdTrustBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#0d1520; border:1px solid #3a5a7a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
            </div>

            <div style="color:#5a7a8a; font-size:0.72em; margin-top:8px; font-style:italic;">
                Slow to build, fast to lose. Trust &lt; 30 halves comfort gains.
            </div>
        </div>
        <?php endif; ?>

        <!-- ========== RESPECT DIMENSION (PR 4) ========== -->
        <?php if ($rdRespectActive): ?>
        <div style="margin-top:16px; border:1px solid #7a6a3a; border-radius:6px; padding:12px; background:#2a2510;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#d4a843; font-size:0.85em;">
                    Respect
                    <?php if ($rdRespectBandLabel): ?>
                        <span style="color:#b8a060; font-weight:400;"> -- <?= htmlspecialchars($rdRespectBandLabel) ?></span>
                    <?php endif; ?>
                </label>
                <button type="button" onclick="reldynResetRespect()"
                    title="Reset respect X and baseline to temperament default"
                    style="background:#2a2510; border:1px solid #7a6a3a; border-radius:4px; color:#d4a843; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdRespectBandKeywords): ?>
            <div style="color:#8a7a50; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdRespectBandKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <!-- Respect X value -->
                <div>
                    <label style="font-weight:600; color:#b8a060; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_respect_x"
                            min="0" max="100" step="1"
                            value="<?= $rdRespectX !== null ? round($rdRespectX) : 40 ?>"
                            style="flex:1; accent-color:#d4a843; height:6px;"
                            oninput="reldynUpdateRespectSlider()">
                        <span id="reldyn_respect_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= $rdRespectX !== null ? round($rdRespectX) : '40' ?>
                        </span>
                    </div>
                    <!-- Mini progress bar colored by band -->
                    <div style="background:#1a1a0d; border:1px solid #7a6a3a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_respect_bar" style="height:100%; width:<?= $rdRespectX !== null ? min(100, max(0, $rdRespectX)) : 40 ?>%; background:linear-gradient(90deg, #ef4444 0%, #fbbf24 30%, #d4a843 60%, #f5d78e 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>

                <!-- Respect Baseline -->
                <div>
                    <label style="font-weight:600; color:#b8a060; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_respect_baseline"
                        min="0" max="100" step="1"
                        value="<?= $rdRespectBaseline !== null ? round($rdRespectBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#1a1a0d; border:1px solid #7a6a3a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
            </div>

            <div style="color:#6a5a3a; font-size:0.72em; margin-top:8px; font-style:italic;">
                Earned slowly, lost fast. Respect &lt; 30 soft-caps affinity at 60. Proud NPCs: disrespect hits HARD (Y_down=2.0).
            </div>
        </div>
        <?php endif; ?>

        <!-- ========== COMFORT DIMENSION (PR 4) ========== -->
        <?php if ($rdComfortActive): ?>
        <div style="margin-top:16px; border:1px solid #3a6a4a; border-radius:6px; padding:12px; background:#1a2e1a;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#6abf69; font-size:0.85em;">
                    Comfort
                    <?php if ($rdComfortBandLabel): ?>
                        <span style="color:#8fd98e; font-weight:400;"> -- <?= htmlspecialchars($rdComfortBandLabel) ?></span>
                    <?php endif; ?>
                </label>
                <button type="button" onclick="reldynResetComfort()"
                    title="Reset comfort X and baseline to temperament default"
                    style="background:#1a2e1a; border:1px solid #3a6a4a; border-radius:4px; color:#8fd98e; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdComfortBandKeywords): ?>
            <div style="color:#5a8a5a; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdComfortBandKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <!-- Comfort X value -->
                <div>
                    <label style="font-weight:600; color:#8fd98e; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_comfort_x"
                            min="0" max="100" step="1"
                            value="<?= $rdComfortX !== null ? round($rdComfortX) : 35 ?>"
                            style="flex:1; accent-color:#6abf69; height:6px;"
                            oninput="reldynUpdateComfortSlider()">
                        <span id="reldyn_comfort_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= $rdComfortX !== null ? round($rdComfortX) : '35' ?>
                        </span>
                    </div>
                    <!-- Mini progress bar colored by band -->
                    <div style="background:#0d1a0d; border:1px solid #3a6a4a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_comfort_bar" style="height:100%; width:<?= $rdComfortX !== null ? min(100, max(0, $rdComfortX)) : 35 ?>%; background:linear-gradient(90deg, #ef4444 0%, #fbbf24 25%, #6abf69 60%, #22c55e 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>

                <!-- Comfort Baseline -->
                <div>
                    <label style="font-weight:600; color:#8fd98e; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_comfort_baseline"
                        min="0" max="100" step="1"
                        value="<?= $rdComfortBaseline !== null ? round($rdComfortBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#0d1a0d; border:1px solid #3a6a4a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
            </div>

            <div style="color:#4a7a4a; font-size:0.72em; margin-top:8px; font-style:italic;">
                Can I be myself around you? High affinity + low comfort = suffocation.
            </div>
        </div>
        <?php endif; ?>
        <!-- ========== M/F COORDINATES: MASCULINE AXIS (PR 6) ========== -->
        <?php if ($rdCoordMActive): ?>
        <div style="margin-top:16px; border:1px solid #5a5a5a; border-radius:6px; padding:12px; background:#1e1e22;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#a0a8b8; font-size:0.85em;">
                    Masculine Coordinate
                    <?php if ($rdCoordMQuadrantLabel): ?>
                        <span style="color:#7a8294; font-weight:400;"> -- Quadrant: <?= htmlspecialchars($rdCoordMQuadrantLabel) ?></span>
                    <?php endif; ?>
                </label>
                <button type="button" onclick="reldynResetCoordM()"
                    title="Reset masculine coordinate X and baseline to temperament default"
                    style="background:#1e1e22; border:1px solid #5a5a5a; border-radius:4px; color:#a0a8b8; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdCoordMQuadrantKeywords): ?>
            <div style="color:#6a6e7a; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdCoordMQuadrantKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <!-- Masculine Coordinate X value -->
                <div>
                    <label style="font-weight:600; color:#8a90a0; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:4px; font-size:0.7em; color:#6a6e7a; margin-bottom:2px;">
                        <span>Aggressive/Cold</span>
                        <span style="flex:1;"></span>
                        <span>Stoic/Protective</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_coord_m_x"
                            min="-100" max="100" step="1"
                            value="<?= $rdCoordMX !== null ? round($rdCoordMX) : 0 ?>"
                            style="flex:1; accent-color:#a0a8b8; height:6px;"
                            oninput="reldynUpdateCoordMSlider()">
                        <span id="reldyn_coord_m_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:36px; text-align:right;">
                            <?= $rdCoordMX !== null ? round($rdCoordMX) : '0' ?>
                        </span>
                    </div>
                    <!-- Mini progress bar: centered at 0, extends left (negative) or right (positive) -->
                    <div style="background:#12121a; border:1px solid #5a5a5a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px; position:relative;">
                        <?php
                        $coordMPercent = $rdCoordMX !== null ? ($rdCoordMX + 100) / 200 * 100 : 50;
                        $coordMBarLeft = min($coordMPercent, 50);
                        $coordMBarWidth = abs($coordMPercent - 50);
                        ?>
                        <div style="position:absolute; left:50%; top:0; bottom:0; width:1px; background:#5a5a5a;"></div>
                        <div id="reldyn_coord_m_bar" style="position:absolute; left:<?= $coordMBarLeft ?>%; top:0; height:100%; width:<?= $coordMBarWidth ?>%; background:linear-gradient(90deg, #6080b0, #a0a8b8); border-radius:2px; transition:left 0.2s, width 0.2s;"></div>
                    </div>
                </div>

                <!-- Masculine Coordinate Baseline -->
                <div>
                    <label style="font-weight:600; color:#8a90a0; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_coord_m_baseline"
                        min="-100" max="100" step="1"
                        value="<?= $rdCoordMBaseline !== null ? round($rdCoordMBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#12121a; border:1px solid #5a5a5a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
            </div>

            <div style="color:#4a4e5a; font-size:0.72em; margin-top:8px; font-style:italic;">
                GLOBAL dimension (intrinsic to NPC, not per-bond). Part of the M/F quadrant system. Z=15 (moderate-tight rubber band).
            </div>
        </div>
        <?php endif; ?>

        <!-- ========== FEMININE COORDINATE (PR 6) ========== -->
        <?php if ($rdCoordFActive): ?>
        <div style="margin-top:16px; border:1px solid #7a4a5a; border-radius:6px; padding:12px; background:#2a1a20;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#d4788a; font-size:0.85em;">
                    Feminine Coordinate (F)
                    <?php if ($rdMFQuadrantLabel): ?>
                        <span style="color:#b8808a; font-weight:400;"> -- Quadrant: <?= htmlspecialchars($rdMFQuadrantLabel) ?></span>
                    <?php endif; ?>
                </label>
                <button type="button" onclick="reldynResetCoordF()"
                    title="Reset feminine coordinate X and baseline to temperament default"
                    style="background:#2a1a20; border:1px solid #7a4a5a; border-radius:4px; color:#d4788a; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdMFQuadrantKeywords): ?>
            <div style="color:#8a5a6a; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdMFQuadrantKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <!-- Coord F X value -->
                <div>
                    <label style="font-weight:600; color:#b8808a; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; justify-content:space-between; margin-bottom:2px;">
                        <small style="color:#8a5a6a; font-size:0.7em;">Needy/Manipulative</small>
                        <small style="color:#8a5a6a; font-size:0.7em;">Nurturing/Empathetic</small>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_coord_f_x"
                            min="-100" max="100" step="1"
                            value="<?= $rdCoordFX !== null ? round($rdCoordFX) : 0 ?>"
                            style="flex:1; accent-color:#d4788a; height:6px;"
                            oninput="reldynUpdateCoordFSlider()">
                        <span id="reldyn_coord_f_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:36px; text-align:right;">
                            <?= $rdCoordFX !== null ? round($rdCoordFX) : '0' ?>
                        </span>
                    </div>
                    <!-- Bipolar bar: center = 0, fills left or right -->
                    <div style="background:#1a0d12; border:1px solid #7a4a5a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px; position:relative;">
                        <div style="position:absolute; left:50%; top:0; bottom:0; width:1px; background:#5a3a4a;"></div>
                        <?php
                            $cfVal = $rdCoordFX !== null ? round($rdCoordFX) : 0;
                            $cfBarLeft = $cfVal >= 0 ? 50 : max(0, 50 + ($cfVal / 2));
                            $cfBarWidth = abs($cfVal) / 2;
                            $cfBarColor = $cfVal >= 0 ? '#d4788a' : '#8a4a5a';
                        ?>
                        <div id="reldyn_coord_f_bar" style="position:absolute; left:<?= $cfBarLeft ?>%; width:<?= $cfBarWidth ?>%; height:100%; background:<?= $cfBarColor ?>; border-radius:2px; transition:all 0.2s;"></div>
                    </div>
                </div>

                <!-- Coord F Baseline -->
                <div>
                    <label style="font-weight:600; color:#b8808a; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_coord_f_baseline"
                        min="-100" max="100" step="1"
                        value="<?= $rdCoordFBaseline !== null ? round($rdCoordFBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#1a0d12; border:1px solid #7a4a5a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
            </div>

            <div style="color:#6a3a4a; font-size:0.72em; margin-top:8px; font-style:italic;">
                Behavioral mode: +F = nurturing/empathetic, -F = needy/manipulative. GLOBAL dimension (intrinsic, not per-bond). Z=15.
            </div>
        </div>
        <?php endif; ?>


        <!-- ========== AROUSAL/VALENCE (PR 6) ========== -->
        <?php if ($rdArousalActive): ?>
        <div style="margin-top:16px; display:grid; grid-template-columns: 1fr 1fr; gap:12px;">

            <!-- Arousal Card -->
            <div style="border:1px solid #7a3a2a; border-radius:6px; padding:12px; background:#2a1510;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label style="font-weight:700; color:#e87040; font-size:0.85em;">
                        Arousal
                        <span style="color:#b85a30; font-weight:400; font-size:0.85em;">(nervous system activation)</span>
                    </label>
                    <button type="button" onclick="reldynResetArousal()"
                        title="Reset arousal to resting state (baseline=10)"
                        style="background:#2a1510; border:1px solid #7a3a2a; border-radius:4px; color:#e87040; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                        Reset
                    </button>
                </div>
                <div>
                    <label style="font-weight:600; color:#c06030; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X): <span style="color:#888; font-weight:400;">Calm</span> &rarr; <span style="color:#888; font-weight:400;">Activated</span>
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_arousal_x"
                            min="0" max="100" step="1"
                            value="<?= round($rdArousalX) ?>"
                            style="flex:1; accent-color:#e87040; height:6px;"
                            oninput="reldynUpdateArousalSlider()">
                        <span id="reldyn_arousal_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= round($rdArousalX) ?>
                        </span>
                    </div>
                    <div style="background:#1a0d08; border:1px solid #7a3a2a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_arousal_bar" style="height:100%; width:<?= min(100, max(0, $rdArousalX)) ?>%; background:linear-gradient(90deg, #4a6a4a 0%, #e87040 50%, #ef4444 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <label style="font-weight:600; color:#c06030; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (resting state)
                    </label>
                    <input type="number" id="reldyn_arousal_baseline"
                        min="0" max="100" step="1"
                        value="<?= round($rdArousalBaseline) ?>"
                        placeholder="10"
                        style="background:#1a0d08; border:1px solid #7a3a2a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
                <div style="color:#6a3a2a; font-size:0.72em; margin-top:8px; font-style:italic;">
                    Z=8 (very tight rubber band). Fast decay back to resting state. Event-driven, not eval-scored.
                </div>
            </div>

            <!-- Valence Card -->
            <div style="border:1px solid #4a3a6a; border-radius:6px; padding:12px; background:#1a1528;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label style="font-weight:700; color:#9b6dd7; font-size:0.85em;">
                        Valence
                        <span style="color:#7a5aaa; font-weight:400; font-size:0.85em;">(emotional direction)</span>
                    </label>
                    <button type="button" onclick="reldynResetValence()"
                        title="Reset valence to neutral (baseline=0)"
                        style="background:#1a1528; border:1px solid #4a3a6a; border-radius:4px; color:#9b6dd7; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                        Reset
                    </button>
                </div>
                <div>
                    <label style="font-weight:600; color:#8a60c0; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X): <span style="color:#ef4444; font-weight:400;">Fear/Panic</span> &rarr; <span style="color:#22c55e; font-weight:400;">Thrill/Excitement</span>
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_valence_x"
                            min="-100" max="100" step="1"
                            value="<?= round($rdValenceX) ?>"
                            style="flex:1; accent-color:#9b6dd7; height:6px;"
                            oninput="reldynUpdateValenceSlider()">
                        <span id="reldyn_valence_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:40px; text-align:right;">
                            <?= round($rdValenceX) ?>
                        </span>
                    </div>
                    <!-- Mini progress bar (centered at 0) -->
                    <div style="background:#0d0a15; border:1px solid #4a3a6a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px; position:relative;">
                        <div style="position:absolute; left:50%; top:0; bottom:0; width:1px; background:#4a3a6a;"></div>
                        <?php
                        $valPct = ($rdValenceX + 100) / 200 * 100;
                        $valBarLeft = min(50, $valPct);
                        $valBarWidth = abs($valPct - 50);
                        ?>
                        <div id="reldyn_valence_bar" style="height:100%; position:absolute; left:<?= $valBarLeft ?>%; width:<?= $valBarWidth ?>%; background:<?= $rdValenceX >= 0 ? '#22c55e' : '#ef4444' ?>; border-radius:4px; transition:all 0.2s;"></div>
                    </div>
                </div>
                <div style="margin-top:8px;">
                    <label style="font-weight:600; color:#8a60c0; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (neutral center)
                    </label>
                    <input type="number" id="reldyn_valence_baseline"
                        min="-100" max="100" step="1"
                        value="<?= round($rdValenceBaseline) ?>"
                        placeholder="0"
                        style="background:#0d0a15; border:1px solid #4a3a6a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>
                <div style="color:#4a3a5a; font-size:0.72em; margin-top:8px; font-style:italic;">
                    Z=12 (fast decay to neutral). Bold: amplifies thrill. Anxious: amplifies fear.
                </div>
            </div>
        </div>

        <!-- Combined A/V Band Display -->
        <?php if ($rdAVBandLabel): ?>
        <div style="margin-top:8px; padding:8px 12px; background:#1a1520; border:1px solid #3a2a4a; border-radius:4px;">
            <span style="color:#9b6dd7; font-weight:700; font-size:0.8em;">Combined State:</span>
            <span id="reldyn_av_band_label" style="color:#c8a0f0; font-weight:600; font-size:0.8em; margin-left:6px;">
                <?= htmlspecialchars($rdAVBandLabel) ?>
            </span>
            <span id="reldyn_av_band_keywords" style="color:#7a6a8a; font-style:italic; font-size:0.75em; margin-left:8px;">
                <?= htmlspecialchars($rdAVBandKeywords) ?>
            </span>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ========== RESENTMENT DIMENSION (PR 7) ========== -->
        <?php if ($rdResentmentActive): ?>
        <div style="margin-top:16px; border:1px solid #7a2a2a; border-radius:6px; padding:12px; background:#2a1010;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#d44; font-size:0.85em;">
                    Resentment
                    <?php if ($rdResentmentBandLabel): ?>
                        <span style="color:#c66; font-weight:400;"> -- <?= htmlspecialchars($rdResentmentBandLabel) ?></span>
                    <?php endif; ?>
                </label>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="color:#c44; font-size:0.7em; font-weight:600; text-transform:uppercase; letter-spacing:1px; border:1px solid #7a2a2a; padding:2px 8px; border-radius:3px;">
                        HIDDEN -- player does not see this value
                    </span>
                    <button type="button" onclick="reldynResetResentment()"
                        title="Clear all resentment (admin debug tool)"
                        style="background:#2a1010; border:1px solid #7a2a2a; border-radius:4px; color:#d44; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                        Clear resentment
                    </button>
                    <button type="button" onclick="reldynClearGrievances()"
                        title="Clear grievance log (admin debug tool)"
                        style="background:#2a1010; border:1px solid #7a2a2a; border-radius:4px; color:#c66; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                        Clear grievances
                    </button>
                </div>
            </div>

            <?php if ($rdResentmentBandKeywords): ?>
            <div style="color:#8a4a4a; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdResentmentBandKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                <!-- Resentment X value -->
                <div>
                    <label style="font-weight:600; color:#c66; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_resentment_x"
                            min="0" max="100" step="1"
                            value="<?= round($rdResentmentX) ?>"
                            style="flex:1; accent-color:#d44; height:6px;"
                            oninput="reldynUpdateResentmentSlider()">
                        <span id="reldyn_resentment_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= round($rdResentmentX) ?>
                        </span>
                    </div>
                    <!-- Red warning bar -->
                    <div style="background:#1a0808; border:1px solid #7a2a2a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_resentment_bar" style="height:100%; width:<?= min(100, max(0, $rdResentmentX)) ?>%; background:linear-gradient(90deg, #22c55e 0%, #fbbf24 25%, #ef4444 60%, #991b1b 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>

                <!-- Resentment info panel -->
                <div>
                    <label style="font-weight:600; color:#c66; display:block; margin-bottom:4px; font-size:0.8em;">
                        Inverted Rubber Band (Z=8)
                    </label>
                    <div style="color:#8a5a5a; font-size:0.78em; line-height:1.4;">
                        Resentment builds easily but resists clearing.<br>
                        Baseline: <?= round($rdResentmentBaseline) ?> | Decay: -1/positive, -10/confrontation
                    </div>
                </div>
            </div>

            <!-- Grievance log -->
            <?php if (!empty($rdGrievanceLog)): ?>
            <div style="margin-top:12px; border-top:1px solid #4a1a1a; padding-top:10px;">
                <label style="font-weight:600; color:#c66; display:block; margin-bottom:6px; font-size:0.8em;">
                    Grievance Log (last <?= count($rdGrievanceLog) ?>)
                </label>
                <div style="max-height:120px; overflow-y:auto; background:#1a0808; border:1px solid #4a1a1a; border-radius:4px; padding:6px 8px;">
                    <?php foreach (array_reverse($rdGrievanceLog) as $g): ?>
                    <div style="color:#a88; font-size:0.75em; padding:3px 0; border-bottom:1px solid #2a1515;">
                        <span style="color:#666; font-size:0.9em;"><?= date('m/d H:i', $g['timestamp'] ?? 0) ?></span>
                        <span style="color:#c88; margin-left:4px;">+<?= $g['amount'] ?? '?' ?></span>
                        <span style="margin-left:6px;"><?= htmlspecialchars($g['text'] ?? '(no text)') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="margin-top:8px; color:#5a3a3a; font-size:0.72em; font-style:italic;">
                No grievances recorded. Clean slate.
            </div>
            <?php endif; ?>

            <div style="color:#5a2a2a; font-size:0.72em; margin-top:8px; font-style:italic;">
                Hidden accumulator. Resentment > 50 halves affinity gains. Fed by eval grievance flags, not direct deltas.
            </div>
        </div>
        <?php endif; ?>

        <!-- ========== SELF-CONFIDENCE DIMENSION (PR 7) ========== -->
        <?php if ($rdSelfConfActive): ?>
        <div style="margin-top:16px; border:1px solid #3a3a6a; border-radius:6px; padding:12px; background:#1a1a2e;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <label style="font-weight:700; color:#6a6ad4; font-size:0.85em;">
                    Self-Confidence
                    <?php if ($rdSelfConfBandLabel): ?>
                        <span style="color:#8a8ad9; font-weight:400;"> -- <?= htmlspecialchars($rdSelfConfBandLabel) ?></span>
                    <?php endif; ?>
                    <span style="color:#5a5a8a; font-weight:400; font-size:0.8em;">(GLOBAL)</span>
                </label>
                <button type="button" onclick="reldynResetSelfConfidence()"
                    title="Reset self-confidence X and baseline to temperament default"
                    style="background:#1a1a2e; border:1px solid #3a3a6a; border-radius:4px; color:#8a8ad9; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                    Reset to default
                </button>
            </div>

            <?php if ($rdSelfConfBandKeywords): ?>
            <div style="color:#6a6a9a; font-size:0.78em; margin-bottom:10px; font-style:italic;">
                <?= htmlspecialchars($rdSelfConfBandKeywords) ?>
            </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
                <!-- Self-Confidence X value -->
                <div>
                    <label style="font-weight:600; color:#8a8ad9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X)
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_self_confidence_x"
                            min="0" max="100" step="1"
                            value="<?= $rdSelfConfX !== null ? round($rdSelfConfX) : 45 ?>"
                            style="flex:1; accent-color:#6a6ad4; height:6px;"
                            oninput="reldynUpdateSelfConfidenceSlider()">
                        <span id="reldyn_self_confidence_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= $rdSelfConfX !== null ? round($rdSelfConfX) : '45' ?>
                        </span>
                    </div>
                    <!-- Mini progress bar -->
                    <div style="background:#0d0d1a; border:1px solid #3a3a6a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_self_confidence_bar" style="height:100%; width:<?= $rdSelfConfX !== null ? min(100, max(0, $rdSelfConfX)) : 45 ?>%; background:linear-gradient(90deg, #ef4444 0%, #fbbf24 25%, #6a6ad4 60%, #a0a0f0 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>

                <!-- Self-Confidence Baseline -->
                <div>
                    <label style="font-weight:600; color:#8a8ad9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Baseline (rubber band center)
                    </label>
                    <input type="number" id="reldyn_self_confidence_baseline"
                        min="0" max="100" step="1"
                        value="<?= $rdSelfConfBaseline !== null ? round($rdSelfConfBaseline) : '' ?>"
                        placeholder="Auto from temperament"
                        style="background:#0d0d1a; border:1px solid #3a3a6a; border-radius:4px; color:#e9efff; padding:6px 8px; width:100%; font-size:0.9em; box-sizing:border-box;">
                </div>

                <!-- Derived confidence input (read-only) -->
                <div>
                    <label style="font-weight:600; color:#8a8ad9; display:block; margin-bottom:4px; font-size:0.8em;">
                        Confidence Input (derived)
                    </label>
                    <div style="background:#0d0d1a; border:1px solid #3a3a6a; border-radius:4px; padding:6px 8px; color:#c0c0e0; font-size:0.9em; font-family:monospace;">
                        <?= $rdSelfConfInput !== null ? round($rdSelfConfInput, 1) : 'n/a' ?>
                        <span style="color:#5a5a7a; font-size:0.8em;">/100</span>
                    </div>
                    <div style="color:#4a4a6a; font-size:0.65em; margin-top:2px;">
                        = respect*0.3 + maturity*0.3 - resentment_self*0.3 + goals*0.1
                    </div>
                </div>
            </div>

            <div style="color:#4a4a6a; font-size:0.72em; margin-top:8px; font-style:italic;">
                Root node for attachment style. Proud NPCs have brittle confidence (Y_up=0.3, Y_down=2.0). Z=25 (wide rubber band). Slow to build, fast to lose.
            </div>
        </div>
        <?php endif; ?>

        <!-- ========== RESENTMENT_SELF (PR 7) ========== -->
        <?php if ($rdResentmentSelfActive): ?>
        <div style="margin-top:16px;">
            <div style="border:1px solid #7a2a2a; border-radius:6px; padding:12px; background:#2a1010;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label style="font-weight:700; color:#ef4444; font-size:0.85em;">
                        Resentment (Self)
                        <span style="color:#b83030; font-weight:400; font-size:0.85em;">SELF-DIRECTED &mdash; shame, guilt, self-disappointment</span>
                    </label>
                    <button type="button" onclick="reldynResetResentmentSelf()"
                        title="Reset resentment_self to 0 (clear slate)"
                        style="background:#2a1010; border:1px solid #7a2a2a; border-radius:4px; color:#ef4444; padding:3px 10px; cursor:pointer; font-size:0.75em;">
                        Reset
                    </button>
                </div>
                <div>
                    <label style="font-weight:600; color:#c03030; display:block; margin-bottom:4px; font-size:0.8em;">
                        Current Value (X): <span style="color:#888; font-weight:400;">Clean</span> &rarr; <span style="color:#888; font-weight:400;">Crisis</span>
                    </label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="range" id="reldyn_resentment_self_x"
                            min="0" max="100" step="1"
                            value="<?= round($rdResentmentSelfX) ?>"
                            style="flex:1; accent-color:#ef4444; height:6px;"
                            oninput="reldynUpdateResentmentSelfSlider()">
                        <span id="reldyn_resentment_self_x_val" style="color:#e9efff; font-weight:700; font-size:0.85em; min-width:30px; text-align:right;">
                            <?= round($rdResentmentSelfX) ?>
                        </span>
                    </div>
                    <div style="background:#1a0808; border:1px solid #7a2a2a; border-radius:4px; height:8px; overflow:hidden; margin-top:4px;">
                        <div id="reldyn_resentment_self_bar" style="height:100%; width:<?= min(100, max(0, $rdResentmentSelfX)) ?>%; background:linear-gradient(90deg, #4a4a2a 0%, #ef4444 50%, #991b1b 100%); border-radius:4px; transition:width 0.2s;"></div>
                    </div>
                </div>
                <?php if ($rdResentmentSelfBandLabel): ?>
                <div style="margin-top:8px; padding:6px 10px; background:#1a0808; border:1px solid #5a1a1a; border-radius:4px;">
                    <span style="color:#ef4444; font-weight:700; font-size:0.8em;">Band: <?= htmlspecialchars($rdResentmentSelfBandLabel) ?></span>
                    <?php if ($rdResentmentSelfBandKeywords): ?>
                    <span style="color:#7a4a4a; font-style:italic; font-size:0.75em; margin-left:8px;"><?= htmlspecialchars($rdResentmentSelfBandKeywords) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($rdResentmentSelfThresholds)): ?>
                <div style="margin-top:6px;">
                    <?php foreach ($rdResentmentSelfThresholds as $thresh): ?>
                    <div style="padding:4px 10px; margin-top:4px; background:#2a0808; border:1px solid #5a1a1a; border-radius:3px; color:#f87171; font-size:0.75em;">
                        <strong>&gt;<?= $thresh['threshold'] ?>:</strong> <?= htmlspecialchars($thresh['desc']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div style="color:#5a2a2a; font-size:0.72em; margin-top:8px; font-style:italic;">
                    Z=8 (inverted rubber band — sticky once accumulated). GLOBAL: affects ALL bonds.
                    Cleared by: confession (-10), forgiveness (-15), sustained positive diary (+1/entry).
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Row 4: Interests -->
        <div style="margin-top:16px;">
            <label style="font-weight:700; color:rgb(242, 124, 17); display:block; margin-bottom:8px; font-size:0.85em;">
                Interests <span style="color:#888; font-weight:400;">(passion multiplier across all love languages)</span>
            </label>
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:8px 12px;">
                <?php foreach ($rdInterestTypes as $intKey => $intInfo):
                    $intVal = $rdInterests[$intKey] ?? 1.0;
                    $barColor = $intVal >= 1.3 ? '#22c55e' : ($intVal <= 0.85 ? '#ef4444' : '#4a4a4a');
                ?>
                <div style="background:#1a1a1a; border:1px solid #3a3a3a; border-radius:4px; padding:6px 8px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                        <small style="color:#cfd9ea; font-size:0.8em;"><?= $intInfo['icon'] ?> <?= $intInfo['label'] ?></small>
                        <small id="reldyn_int_val_<?= $intKey ?>" style="color:<?= $barColor ?>; font-weight:700; font-size:0.8em;"><?= number_format($intVal, 1) ?>x</small>
                    </div>
                    <input type="range" id="reldyn_int_<?= $intKey ?>"
                        min="0.5" max="2.0" step="0.1" value="<?= $intVal ?>"
                        style="width:100%; accent-color:rgb(242, 124, 17); height:6px;"
                        oninput="reldynUpdateSlider('<?= $intKey ?>', this.value)">
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Actions bar -->
        <div style="margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <button type="button" onclick="reldynSave()" id="reldyn-save-btn"
                title="Saves current settings and re-embeds the interest vector for location/topic matching."
                style="background:linear-gradient(180deg, #1a472a, #0d3320); border:1px solid #22c55e; border-radius:4px; color:#86efac; padding:7px 16px; cursor:pointer; font-size:0.85em; font-weight:600;">
                💾 Apply Changes
            </button>
            <button type="button" onclick="reldynAutoGen()"
                title="Reads NPC bio, personality, relationships, occupation, and class to generate interests, love language, and warmth curve. Also embeds an interest vector for location/topic matching."
                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#fde68a; padding:7px 12px; cursor:pointer; font-size:0.85em;">
                🔄 Auto-Generate from Profile
            </button>
            <button type="button" onclick="reldynReset()"
                style="background:#2a2a2a; border:1px solid #4a4a4a; border-radius:4px; color:#f87171; padding:7px 12px; cursor:pointer; font-size:0.85em;">
                ↺ Reset Dynamics
            </button>
            <span id="reldyn-status" style="color:#86efac; font-size:0.85em; margin-left:8px;"></span>
        </div>

    </div>
</details>
</div>

<script>
(function(){
    const RELDYN_NPC = <?= json_encode($rdNpcName) ?>;
    const RELDYN_API = <?= json_encode($rdApiUrl . '/ext/relationship_dynamics/api_save_npc.php') ?>;
    const INTERESTS = <?= json_encode(array_keys($rdInterestTypes)) ?>;

    window.reldynUpdateSlider = function(act, val) {
        const el = document.getElementById('reldyn_int_val_' + act);
        if (!el) return;
        val = parseFloat(val);
        el.textContent = val.toFixed(1) + 'x';
        el.style.color = val >= 1.3 ? '#22c55e' : (val <= 0.85 ? '#ef4444' : '#9fb1c9');
    };

    function collectData() {
        const data = {
            npc: RELDYN_NPC,
            love_language_primary: document.getElementById('reldyn_ll_primary')?.value || '',
            love_language_secondary: document.getElementById('reldyn_ll_secondary')?.value || '',
            warmth_curve: document.getElementById('reldyn_warmth')?.value || '',
            inferred_temperament: document.getElementById('reldyn_temperament')?.value || '',
            relationship_preference: document.getElementById('reldyn_rel_pref')?.value || '',
            openness: document.getElementById('reldyn_openness')?.value || '',
            // PR 10: Attachment Style
            attachment_style: document.getElementById('reldyn_attachment_style')?.value || '',
            // PR 15: Social Sensitivity override
            social_sensitivity_curve: document.getElementById('reldyn_social_sensitivity')?.value || '',
            // PR 16: Home Location
            home_location: document.getElementById('reldyn_home_location')?.value || '',
            interests: {},
            // PR 3: Maturity dimension
            maturity_x: document.getElementById('reldyn_maturity_x')?.value ?? null,
            maturity_baseline: document.getElementById('reldyn_maturity_baseline')?.value || null,
            maturity_plasticity_type: document.getElementById('reldyn_maturity_plasticity')?.value || '',
            // PR 4: Trust dimension
            trust_x: document.getElementById('reldyn_trust_x')?.value ?? null,
            trust_baseline: document.getElementById('reldyn_trust_baseline')?.value || null,
            // PR 4: Respect dimension
            respect_x: document.getElementById('reldyn_respect_x')?.value ?? null,
            respect_baseline: document.getElementById('reldyn_respect_baseline')?.value || null,
            // PR 4: Comfort dimension
            comfort_x: document.getElementById('reldyn_comfort_x')?.value ?? null,
            comfort_baseline: document.getElementById('reldyn_comfort_baseline')?.value || null,
            // PR 6: Masculine Coordinate
            coord_m_x: document.getElementById('reldyn_coord_m_x')?.value ?? null,
            coord_m_baseline: document.getElementById('reldyn_coord_m_baseline')?.value || null,
            // PR 6: Feminine Coordinate
            coord_f_x: document.getElementById('reldyn_coord_f_x')?.value ?? null,
            coord_f_baseline: document.getElementById('reldyn_coord_f_baseline')?.value || null,
            // PR 6: Arousal/Valence
            arousal_x: document.getElementById('reldyn_arousal_x')?.value ?? null,
            arousal_baseline: document.getElementById('reldyn_arousal_baseline')?.value || null,
            valence_x: document.getElementById('reldyn_valence_x')?.value ?? null,
            valence_baseline: document.getElementById('reldyn_valence_baseline')?.value || null,
            // PR 7: Self-Confidence
            self_confidence_x: document.getElementById('reldyn_self_confidence_x')?.value ?? null,
            self_confidence_baseline: document.getElementById('reldyn_self_confidence_baseline')?.value || null,
            // PR 7: Resentment dimension
            resentment_x: document.getElementById('reldyn_resentment_x')?.value ?? null,
            // PR 7: Resentment_self (self-directed shame)
            resentment_self_x: document.getElementById('reldyn_resentment_self_x')?.value ?? null,
            // PR 11: Attraction Profile
            attraction_archetype: document.getElementById('reldyn_attraction_archetype')?.value || '',
            attraction_beauty_keywords: document.getElementById('reldyn_attraction_beauty_keywords')?.value || '',
            attraction_intimacy_gate: document.getElementById('reldyn_attraction_intimacy_gate')?.value || '',
            attraction_gender_pref: document.getElementById('reldyn_attraction_gender_pref')?.value || '',
        };
        INTERESTS.forEach(int => {
            const slider = document.getElementById('reldyn_int_' + int);
            if (slider) data.interests[int] = parseFloat(slider.value);
        });
        return data;
    }

    function showStatus(msg, color) {
        const el = document.getElementById('reldyn-status');
        if (el) { el.textContent = msg; el.style.color = color || '#86efac'; }
        if (msg) setTimeout(() => { if (el) el.textContent = ''; }, 4000);
    }

    window.reldynSave = async function() {
        const data = collectData();
        data.action = 'save';
        showStatus('Saving...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Saved ✓', '#86efac');
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    window.reldynAutoGen = async function() {
        showStatus('Generating...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'autogen' })
            });
            const json = await resp.json();
            if (json.ok && json.preferences) {
                // Update sliders
                Object.entries(json.preferences).forEach(([act, val]) => {
                    const slider = document.getElementById('reldyn_int_' + act);
                    if (slider) {
                        slider.value = val;
                        reldynUpdateSlider(act, val);
                    }
                });
                // Update dropdowns if auto-generated
                if (json.love_language_primary) {
                    const llp = document.getElementById('reldyn_ll_primary');
                    if (llp) llp.value = json.love_language_primary;
                }
                if (json.love_language_secondary) {
                    const lls = document.getElementById('reldyn_ll_secondary');
                    if (lls) lls.value = json.love_language_secondary;
                }
                if (json.warmth_curve) {
                    const wc = document.getElementById('reldyn_warmth');
                    if (wc) wc.value = json.warmth_curve;
                }
                if (json.inferred_temperament) {
                    const t = document.getElementById('reldyn_temperament');
                    if (t) t.value = json.inferred_temperament;
                }
                showStatus('Generated and saved from profile ✓', '#4ade80');
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== MATURITY DIMENSION (PR 3) ==========
    window.reldynUpdateMaturitySlider = function() {
        const slider = document.getElementById('reldyn_maturity_x');
        const valEl = document.getElementById('reldyn_maturity_x_val');
        const barEl = document.getElementById('reldyn_maturity_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetMaturity = async function() {
        showStatus('Resetting maturity to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_maturity' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Maturity reset to default \u2713', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== TRUST DIMENSION (PR 4) ==========
    window.reldynUpdateTrustSlider = function() {
        const slider = document.getElementById('reldyn_trust_x');
        const valEl = document.getElementById('reldyn_trust_x_val');
        const barEl = document.getElementById('reldyn_trust_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetTrust = async function() {
        showStatus('Resetting trust to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_trust' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Trust reset to default ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== RESPECT DIMENSION (PR 4) ==========
    window.reldynUpdateRespectSlider = function() {
        const slider = document.getElementById('reldyn_respect_x');
        const valEl = document.getElementById('reldyn_respect_x_val');
        const barEl = document.getElementById('reldyn_respect_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetRespect = async function() {
        showStatus('Resetting respect to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_respect' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Respect reset to default ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== COMFORT DIMENSION (PR 4) ==========
    window.reldynUpdateComfortSlider = function() {
        const slider = document.getElementById('reldyn_comfort_x');
        const valEl = document.getElementById('reldyn_comfort_x_val');
        const barEl = document.getElementById('reldyn_comfort_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetComfort = async function() {
        showStatus('Resetting comfort to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_comfort' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Comfort reset to default ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== M/F COORDINATES: MASCULINE AXIS (PR 6) ==========
    window.reldynUpdateCoordMSlider = function() {
        const slider = document.getElementById('reldyn_coord_m_x');
        const valEl = document.getElementById('reldyn_coord_m_x_val');
        const barEl = document.getElementById('reldyn_coord_m_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) {
            // Bipolar bar: centered at 0, extends left or right
            const pct = (val + 100) / 200 * 100;
            const left = Math.min(pct, 50);
            const width = Math.abs(pct - 50);
            barEl.style.left = left + '%';
            barEl.style.width = width + '%';
        }
    };

    window.reldynResetCoordM = async function() {
        showStatus('Resetting masculine coordinate to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_coord_m' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Masculine coordinate reset to default ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== FEMININE COORDINATE (PR 6) ==========
    window.reldynUpdateCoordFSlider = function() {
        const slider = document.getElementById('reldyn_coord_f_x');
        const valEl = document.getElementById('reldyn_coord_f_x_val');
        const barEl = document.getElementById('reldyn_coord_f_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) {
            const barLeft = val >= 0 ? 50 : Math.max(0, 50 + (val / 2));
            const barWidth = Math.abs(val) / 2;
            barEl.style.left = barLeft + '%';
            barEl.style.width = barWidth + '%';
            barEl.style.background = val >= 0 ? '#d4788a' : '#8a4a5a';
        }
    };

    window.reldynResetCoordF = async function() {
        showStatus('Resetting feminine coordinate to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_coord_f' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Feminine coordinate reset to default \u2713', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== AROUSAL/VALENCE (PR 6) ==========
    window.reldynUpdateArousalSlider = function() {
        const slider = document.getElementById('reldyn_arousal_x');
        const valEl = document.getElementById('reldyn_arousal_x_val');
        const barEl = document.getElementById('reldyn_arousal_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynUpdateValenceSlider = function() {
        const slider = document.getElementById('reldyn_valence_x');
        const valEl = document.getElementById('reldyn_valence_x_val');
        const barEl = document.getElementById('reldyn_valence_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) {
            // Bipolar bar: centered at 0
            const pct = (val + 100) / 200 * 100;
            const barLeft = Math.min(pct, 50);
            const barWidth = Math.abs(pct - 50);
            barEl.style.left = barLeft + '%';
            barEl.style.width = barWidth + '%';
            barEl.style.background = val >= 0 ? '#22c55e' : '#ef4444';
        }
    };

    window.reldynResetArousal = async function() {
        showStatus('Resetting arousal to resting state...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_arousal' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Arousal reset to resting state ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    window.reldynResetValence = async function() {
        showStatus('Resetting valence to neutral...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_valence' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Valence reset to neutral ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========
    window.reldynUpdateSelfConfidenceSlider = function() {
        const slider = document.getElementById('reldyn_self_confidence_x');
        const valEl = document.getElementById('reldyn_self_confidence_x_val');
        const barEl = document.getElementById('reldyn_self_confidence_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetSelfConfidence = async function() {
        showStatus('Resetting self-confidence to temperament default...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_self_confidence' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Self-confidence reset to default \u2713', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

        // ========== RESENTMENT DIMENSION (PR 7) ==========
    window.reldynUpdateResentmentSlider = function() {
        const slider = document.getElementById('reldyn_resentment_x');
        const valEl = document.getElementById('reldyn_resentment_x_val');
        const barEl = document.getElementById('reldyn_resentment_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetResentment = async function() {
        if (!confirm('Clear all resentment for ' + RELDYN_NPC + '? This resets resentment to 0.')) return;
        showStatus('Clearing resentment...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_resentment' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Resentment cleared ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    window.reldynClearGrievances = async function() {
        if (!confirm('Clear grievance log for ' + RELDYN_NPC + '? Resentment value is kept.')) return;
        showStatus('Clearing grievances...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'clear_grievances' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Grievances cleared ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    // ========== RESENTMENT_SELF (PR 7) ==========
    window.reldynUpdateResentmentSelfSlider = function() {
        const slider = document.getElementById('reldyn_resentment_self_x');
        const valEl = document.getElementById('reldyn_resentment_self_x_val');
        const barEl = document.getElementById('reldyn_resentment_self_bar');
        if (!slider) return;
        const val = parseInt(slider.value, 10);
        if (valEl) valEl.textContent = val;
        if (barEl) barEl.style.width = Math.min(100, Math.max(0, val)) + '%';
    };

    window.reldynResetResentmentSelf = async function() {
        if (!confirm('Clear all self-directed resentment for ' + RELDYN_NPC + '? This resets resentment_self to 0.')) return;
        showStatus('Clearing self-resentment...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset_resentment_self' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Self-resentment cleared ✓', '#86efac');
                setTimeout(() => location.reload(), 800);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };

    window.reldynReset = async function() {
        if (!confirm('Reset all dynamics for ' + RELDYN_NPC + '? This clears passion, interactions, stage, and conflict state. Love language and interests are kept.')) return;
        showStatus('Resetting...', '#fde68a');
        try {
            const resp = await fetch(RELDYN_API, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ npc: RELDYN_NPC, action: 'reset' })
            });
            const json = await resp.json();
            if (json.ok) {
                showStatus('Dynamics reset ✓', '#86efac');
                // Reload section to reflect new state
                setTimeout(() => location.reload(), 1000);
            } else {
                showStatus('Error: ' + (json.error || 'Unknown'), '#f87171');
            }
        } catch(e) {
            showStatus('Network error: ' + e.message, '#f87171');
        }
    };
})();
</script>
