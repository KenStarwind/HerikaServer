<?php
/**
 * Relationship Dynamics — Per-NPC AJAX Save Endpoint
 *
 * Actions:
 *   save    — Save love language, warmth curve, temperament, interests
 *   autogen — Auto-generate preferences from NPC class + skills
 *   reset   — Reset passion, interactions, stage (keep config)
 */

header('Content-Type: application/json');

$enginePath = __DIR__ . "/../../";
require_once($enginePath . "conf/conf.php");
require_once($enginePath . "lib/" . $GLOBALS["DBDRIVER"] . ".class.php");
$GLOBALS['db'] = new sql();

require_once __DIR__ . '/relationship_dynamics.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['npc'])) {
    echo json_encode(['ok' => false, 'error' => 'Missing NPC name']);
    exit;
}

$npcName = trim($input['npc']);
$action = $input['action'] ?? 'save';
$db = $GLOBALS['db'];

try {
    switch ($action) {

        case 'save':
            // Load current dynamics
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Update configurable fields (only if provided)
            $configFields = ['love_language_primary', 'love_language_secondary', 'warmth_curve', 'inferred_temperament', 'relationship_preference', 'openness'];
            foreach ($configFields as $field) {
                if (isset($input[$field])) {
                    $dynamics[$field] = $input[$field] !== '' ? $input[$field] : null;
                }
            }

            // Attachment Style (PR 10)
            if (isset($input['attachment_style'])) {
                $validStyles = ['secure', 'avoidant', 'anxious', 'toxic', ''];
                $style = $input['attachment_style'];
                if (in_array($style, $validStyles, true)) {
                    $dynamics['attachment_style'] = $style === '' ? null : $style;
                }
            }

            // ========== SOCIAL SENSITIVITY OVERRIDE (PR 15) ==========
            if (isset($input['social_sensitivity_curve'])) {
                $validCurves = ['inner_circle', 'open_heart', 'uniform', 'inverse_tolerance', 'romantic_mid', ''];
                $curve = $input['social_sensitivity_curve'];
                if (in_array($curve, $validCurves, true)) {
                    $dynamics['social_sensitivity_curve'] = $curve === '' ? null : $curve;
                }
            }

            // ========== HOME LOCATION (PR 16) ==========
            if (isset($input['home_location'])) {
                $homeLoc = trim($input['home_location']);
                $dynamics['home_location'] = $homeLoc !== '' ? $homeLoc : null;
            }

            // ========== ATTRACTION PROFILE (PR 11) ==========
            $attractionChanged = false;
            if (isset($input['attraction_archetype']) && !empty($input['attraction_archetype'])) {
                $archetype = $input['attraction_archetype'];
                $validArchetypes = ['Warrior', 'Noble', 'Scholar', 'Rogue', 'Priest', 'Primal', 'Bard'];
                if (in_array($archetype, $validArchetypes, true)) {
                    $dynamics['attraction_profile'] = RelationshipDynamics::ATTRACTION_ARCHETYPES[$archetype] ?? null;
                    $attractionChanged = true;
                }
            }
            if (isset($input['attraction_beauty_keywords']) && !empty(trim($input['attraction_beauty_keywords']))) {
                if (!is_array($dynamics['attraction_profile'] ?? null)) {
                    $dynamics['attraction_profile'] = RelationshipDynamics::getArchetypeProfile($dynamics);
                }
                $dynamics['attraction_profile']['beauty_keywords'] = array_map('trim', explode(',', $input['attraction_beauty_keywords']));
                $dynamics['attraction_profile']['beauty_keywords_embedding'] = null; // Clear cached embedding
                $attractionChanged = true;
            }
            if (isset($input['attraction_intimacy_gate'])) {
                $validGates = ['visceral', 'bond', 'balanced', ''];
                $gate = $input['attraction_intimacy_gate'];
                if (in_array($gate, $validGates, true)) {
                    if (!is_array($dynamics['attraction_profile'] ?? null)) {
                        $dynamics['attraction_profile'] = RelationshipDynamics::getArchetypeProfile($dynamics);
                    }
                    $dynamics['attraction_profile']['intimacy_gate'] = $gate ?: 'balanced';
                    $attractionChanged = true;
                }
            }
            if (isset($input['attraction_gender_pref'])) {
                $validPrefs = ['heterosexual', 'homosexual', 'bisexual', ''];
                $pref = $input['attraction_gender_pref'];
                if (in_array($pref, $validPrefs, true)) {
                    if (!is_array($dynamics['attraction_profile'] ?? null)) {
                        $dynamics['attraction_profile'] = RelationshipDynamics::getArchetypeProfile($dynamics);
                    }
                    $dynamics['attraction_profile']['gender_pref'] = $pref ?: 'bisexual';
                    $attractionChanged = true;
                }
            }
            // Clear matrix cache if profile changed
            if ($attractionChanged) {
                $dynamics['_attraction_matrix_cache'] = null;
            }

            // Update interests
            if (isset($input['interests']) && is_array($input['interests'])) {
                $prefs = [];
                foreach ($input['interests'] as $act => $mult) {
                    if (in_array($act, RelationshipDynamics::INTEREST_TYPES)) {
                        $prefs[$act] = max(0.5, min(2.0, floatval($mult)));
                    }
                }
                $dynamics['interests'] = !empty($prefs) ? $prefs : null;
            }

            // Backward compat: accept old activity_preferences key
            if (!isset($input['interests']) && isset($input['activity_preferences']) && is_array($input['activity_preferences'])) {
                $dynamics['interests'] = RelationshipDynamics::migrateOldPreferences($input['activity_preferences']);
                unset($dynamics['activity_preferences']);
            }

            // ========== MATURITY DIMENSION (PR 3) ==========
            // Update maturity overrides if provided
            if (isset($input['maturity_x']) && $input['maturity_x'] !== null && $input['maturity_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['maturity'])) $dynamics['dimensions']['maturity'] = [];
                $dynamics['dimensions']['maturity']['x'] = max(0, min(100, floatval($input['maturity_x'])));
            }
            if (isset($input['maturity_baseline']) && $input['maturity_baseline'] !== null && $input['maturity_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['maturity'])) $dynamics['dimensions']['maturity'] = [];
                $dynamics['dimensions']['maturity']['baseline'] = max(0, min(100, floatval($input['maturity_baseline'])));
            }
            if (isset($input['maturity_plasticity_type'])) {
                $validTypes = ['Resilient', 'Growth', 'Brittle', 'Volatile', 'Rigid', 'Adaptive', ''];
                $mpt = $input['maturity_plasticity_type'];
                if (in_array($mpt, $validTypes, true)) {
                    if (!isset($dynamics['dimensions']['maturity'])) $dynamics['dimensions']['maturity'] = [];
                    // Empty string means "derive from temperament" — store null
                    $dynamics['dimensions']['maturity']['plasticity_type'] = $mpt !== '' ? $mpt : null;
                }
            }

            // ========== TRUST DIMENSION (PR 4) ==========
            // Update trust overrides if provided
            if (isset($input['trust_x']) && $input['trust_x'] !== null && $input['trust_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['trust'])) $dynamics['dimensions']['trust'] = [];
                $dynamics['dimensions']['trust']['x'] = max(0, min(100, floatval($input['trust_x'])));
            }
            if (isset($input['trust_baseline']) && $input['trust_baseline'] !== null && $input['trust_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['trust'])) $dynamics['dimensions']['trust'] = [];
                $dynamics['dimensions']['trust']['baseline'] = max(0, min(100, floatval($input['trust_baseline'])));
            }

            // ========== RESPECT DIMENSION (PR 4) ==========
            // Update respect overrides if provided
            if (isset($input['respect_x']) && $input['respect_x'] !== null && $input['respect_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['respect'])) $dynamics['dimensions']['respect'] = [];
                $dynamics['dimensions']['respect']['x'] = max(0, min(100, floatval($input['respect_x'])));
            }
            if (isset($input['respect_baseline']) && $input['respect_baseline'] !== null && $input['respect_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['respect'])) $dynamics['dimensions']['respect'] = [];
                $dynamics['dimensions']['respect']['baseline'] = max(0, min(100, floatval($input['respect_baseline'])));
            }

            // ========== COMFORT DIMENSION (PR 4) ==========
            // Update comfort overrides if provided
            if (isset($input['comfort_x']) && $input['comfort_x'] !== null && $input['comfort_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['comfort'])) $dynamics['dimensions']['comfort'] = [];
                $dynamics['dimensions']['comfort']['x'] = max(0, min(100, floatval($input['comfort_x'])));
            }
            if (isset($input['comfort_baseline']) && $input['comfort_baseline'] !== null && $input['comfort_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['comfort'])) $dynamics['dimensions']['comfort'] = [];
                $dynamics['dimensions']['comfort']['baseline'] = max(0, min(100, floatval($input['comfort_baseline'])));
            }

            // ========== M/F COORDINATES (PR 6) ==========
            // Update coord_m overrides if provided (bipolar: -100 to +100)
            if (isset($input['coord_m_x']) && $input['coord_m_x'] !== null && $input['coord_m_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['coord_m'])) $dynamics['dimensions']['coord_m'] = [];
                $dynamics['dimensions']['coord_m']['x'] = max(-100, min(100, floatval($input['coord_m_x'])));
            }
            if (isset($input['coord_m_baseline']) && $input['coord_m_baseline'] !== null && $input['coord_m_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['coord_m'])) $dynamics['dimensions']['coord_m'] = [];
                $dynamics['dimensions']['coord_m']['baseline'] = max(-100, min(100, floatval($input['coord_m_baseline'])));
            }

            // Update coord_f overrides if provided (bipolar: -100 to +100)
            if (isset($input['coord_f_x']) && $input['coord_f_x'] !== null && $input['coord_f_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['coord_f'])) $dynamics['dimensions']['coord_f'] = [];
                $dynamics['dimensions']['coord_f']['x'] = max(-100, min(100, floatval($input['coord_f_x'])));
            }
            if (isset($input['coord_f_baseline']) && $input['coord_f_baseline'] !== null && $input['coord_f_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['coord_f'])) $dynamics['dimensions']['coord_f'] = [];
                $dynamics['dimensions']['coord_f']['baseline'] = max(-100, min(100, floatval($input['coord_f_baseline'])));
            }

            // ========== AROUSAL/VALENCE (PR 6) ==========
            // Update arousal overrides if provided
            if (isset($input['arousal_x']) && $input['arousal_x'] !== null && $input['arousal_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['arousal'])) $dynamics['dimensions']['arousal'] = [];
                $dynamics['dimensions']['arousal']['x'] = max(0, min(100, floatval($input['arousal_x'])));
            }
            if (isset($input['arousal_baseline']) && $input['arousal_baseline'] !== null && $input['arousal_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['arousal'])) $dynamics['dimensions']['arousal'] = [];
                $dynamics['dimensions']['arousal']['baseline'] = max(0, min(100, floatval($input['arousal_baseline'])));
            }

            // Update valence overrides if provided
            if (isset($input['valence_x']) && $input['valence_x'] !== null && $input['valence_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['valence'])) $dynamics['dimensions']['valence'] = [];
                $dynamics['dimensions']['valence']['x'] = max(-100, min(100, floatval($input['valence_x'])));
            }
            if (isset($input['valence_baseline']) && $input['valence_baseline'] !== null && $input['valence_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['valence'])) $dynamics['dimensions']['valence'] = [];
                $dynamics['dimensions']['valence']['baseline'] = max(-100, min(100, floatval($input['valence_baseline'])));
            }

            // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========
            // Update self_confidence overrides if provided
            if (isset($input['self_confidence_x']) && $input['self_confidence_x'] !== null && $input['self_confidence_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['self_confidence'])) $dynamics['dimensions']['self_confidence'] = [];
                $dynamics['dimensions']['self_confidence']['x'] = max(0, min(100, floatval($input['self_confidence_x'])));
            }
            if (isset($input['self_confidence_baseline']) && $input['self_confidence_baseline'] !== null && $input['self_confidence_baseline'] !== '') {
                if (!isset($dynamics['dimensions']['self_confidence'])) $dynamics['dimensions']['self_confidence'] = [];
                $dynamics['dimensions']['self_confidence']['baseline'] = max(0, min(100, floatval($input['self_confidence_baseline'])));
            }

            // ========== RESENTMENT DIMENSION (PR 7) ==========
            // Update resentment X if provided (admin override)
            if (isset($input['resentment_x']) && $input['resentment_x'] !== null && $input['resentment_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['resentment'])) $dynamics['dimensions']['resentment'] = [];
                $dynamics['dimensions']['resentment']['x'] = max(0, min(100, floatval($input['resentment_x'])));
            }

            // ========== RESENTMENT_SELF (PR 7) ==========
            // Update resentment_self X if provided
            if (isset($input['resentment_self_x']) && $input['resentment_self_x'] !== null && $input['resentment_self_x'] !== '') {
                if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
                if (!isset($dynamics['dimensions']['resentment_self'])) $dynamics['dimensions']['resentment_self'] = [];
                $dynamics['dimensions']['resentment_self']['x'] = max(0, min(100, floatval($input['resentment_self_x'])));
            }

            // Re-embed interest vector with updated sliders
            $interests = $dynamics['interests'] ?? RelationshipDynamics::generateInterests();
            RelationshipDynamics::embedInterestVector($npcName, $interests, $dynamics);

            // Save
            RelationshipDynamics::saveDynamics($npcName, $dynamics);
            RelationshipDynamics::clearConfigCache();

            echo json_encode(['ok' => true]);
            break;

        case 'autogen':
            // Load NPC data for auto-generation
            $npcRow = $db->fetchOne(
                "SELECT skills, extended_data FROM core_npc_master WHERE lower(npc_name) = lower("
                . $db->escapeLiteral($npcName) . ") LIMIT 1"
            );

            // Set GLOBALS so generateInterests() can read them
            $GLOBALS['HERIKA_NAME'] = $npcName;
            $GLOBALS['HERIKA_SKILLS'] = $npcRow['skills'] ?? '';

            // Generate interests from bio + class + skills
            $prefs = RelationshipDynamics::generateInterests();

            // Also auto-gen love language if not set
            $dynamics = RelationshipDynamics::getDynamics($npcName);
            $llPrimary = $dynamics['love_language_primary'] ?? null;
            $llSecondary = $dynamics['love_language_secondary'] ?? null;
            $warmth = $dynamics['warmth_curve'] ?? null;
            $temp = $dynamics['inferred_temperament'] ?? null;

            if (empty($llPrimary)) {
                RelationshipDynamics::ensureLoveLanguage($npcName, $dynamics);
                $llPrimary = $dynamics['love_language_primary'] ?? null;
                $llSecondary = $dynamics['love_language_secondary'] ?? null;
                $warmth = $dynamics['warmth_curve'] ?? null;
                $temp = $dynamics['inferred_temperament'] ?? null;
            }

            // Auto-embed interest vector (async-safe, ~8ms)
            RelationshipDynamics::embedInterestVector($npcName, $prefs, $dynamics);
            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'preferences' => $prefs,
                'love_language_primary' => $llPrimary,
                'love_language_secondary' => $llSecondary,
                'warmth_curve' => $warmth,
                'inferred_temperament' => $temp,
            ]);
            break;

        case 'reset':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset runtime state but keep configuration
            $dynamics['passion'] = 0.0;
            $dynamics['passion_updated_at'] = 0;
            $dynamics['passion_sources'] = ['love_match' => 0, 'reunion' => 0, 'dramatic' => 0, 'repair' => 0];
            $dynamics['jealousy_anger'] = 0.0;
            $dynamics['jealousy_updated_at'] = 0;
            $dynamics['jealousy_trigger_npc'] = null;
            $dynamics['in_conflict'] = false;
            $dynamics['conflict_entered_at'] = 0;
            $dynamics['conflict_positive_count'] = 0;
            $dynamics['interaction_count'] = 0;
            $dynamics['last_interaction_at'] = 0;
            $dynamics['total_positive_interactions'] = 0;
            $dynamics['stage'] = 'early';
            $dynamics['last_seen_at'] = 0;
            $dynamics['reunion_spike_given'] = false;
            $dynamics['love_language_hints_given'] = 0;

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode(['ok' => true]);
            break;

        // ========== MATURITY DIMENSION (PR 3) ==========
        case 'reset_maturity':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset maturity to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'maturity');
            $plasticityType = RelationshipDynamics::getMaturityPlasticityType($temperament);

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['maturity'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
                'plasticity_type' => $plasticityType,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'maturity_x' => $baseline,
                'maturity_baseline' => $baseline,
                'maturity_plasticity_type' => $plasticityType,
            ]);
            break;

        // ========== TRUST DIMENSION (PR 4) ==========
        case 'reset_trust':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset trust to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'trust');

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['trust'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'trust_x' => $baseline,
                'trust_baseline' => $baseline,
            ]);
            break;

        // ========== RESPECT DIMENSION (PR 4) ==========
        case 'reset_respect':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset respect to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'respect');

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['respect'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'respect_x' => $baseline,
                'respect_baseline' => $baseline,
            ]);
            break;

        // ========== COMFORT DIMENSION (PR 4) ==========
        case 'reset_comfort':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset comfort to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'comfort');

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['comfort'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'comfort_x' => $baseline,
                'comfort_baseline' => $baseline,
            ]);
            break;


        // ========== M/F COORDINATES (PR 6) ==========
        case 'reset_coord_m':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset coord_m to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'coord_m');

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['coord_m'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'coord_m_x' => $baseline,
                'coord_m_baseline' => $baseline,
            ]);
            break;

        case 'reset_coord_f':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset coord_f to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'coord_f');

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['coord_f'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'coord_f_x' => $baseline,
                'coord_f_baseline' => $baseline,
            ]);
            break;
        // ========== AROUSAL/VALENCE (PR 6) ==========
        case 'reset_arousal':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset arousal to resting state defaults
            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['arousal'] = [
                'x' => 10,
                'baseline' => 10,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'arousal_x' => 10,
                'arousal_baseline' => 10,
            ]);
            break;

        case 'reset_valence':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset valence to neutral
            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['valence'] = [
                'x' => 0,
                'baseline' => 0,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'valence_x' => 0,
                'valence_baseline' => 0,
            ]);
            break;

        // ========== SELF-CONFIDENCE DIMENSION (PR 7) ==========
        case 'reset_self_confidence':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset self-confidence to temperament-derived defaults
            $temperament = $dynamics['inferred_temperament'] ?? 'Stoic';
            $baseline = RelationshipDynamics::getTemperamentBaseline($temperament, 'self_confidence');

            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['self_confidence'] = [
                'x' => $baseline,
                'baseline' => $baseline,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'self_confidence_x' => $baseline,
                'self_confidence_baseline' => $baseline,
            ]);
            break;

                // ========== RESENTMENT DIMENSION (PR 7) ==========
        case 'reset_resentment':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset resentment to 0 and clear all grievance data
            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['resentment'] = [
                'x' => 0,
                'baseline' => 0,
                'active' => true,
                'pending_grievances' => [],
                'grievance_log' => [],
                'last_decay_tick' => 0,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'resentment_x' => 0,
            ]);
            break;

        case 'clear_grievances':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Clear grievance log but keep resentment value
            if (isset($dynamics['dimensions']['resentment'])) {
                $dynamics['dimensions']['resentment']['pending_grievances'] = [];
                $dynamics['dimensions']['resentment']['grievance_log'] = [];
            }

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode(['ok' => true]);
            break;

        // ========== RESENTMENT_SELF (PR 7) ==========
        case 'reset_resentment_self':
            $dynamics = RelationshipDynamics::getDynamics($npcName);

            // Reset resentment_self to 0 (clean slate)
            if (!isset($dynamics['dimensions'])) $dynamics['dimensions'] = [];
            $dynamics['dimensions']['resentment_self'] = [
                'x' => 0,
                'baseline' => 0,
                'active' => true,
            ];

            RelationshipDynamics::saveDynamics($npcName, $dynamics);

            echo json_encode([
                'ok' => true,
                'resentment_self_x' => 0,
                'resentment_self_baseline' => 0,
            ]);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
