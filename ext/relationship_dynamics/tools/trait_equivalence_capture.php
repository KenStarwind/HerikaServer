<?php
/**
 * Relationship Dynamics -- personality traits phase-1 equivalence capture (CLI).
 *
 * Records the value every temperament-keyed consumer reads, for the 13 MDD 1.3 presets and the
 * no-temperament / fallback labels, through the consumers' own functions (and, for the three
 * tables read inline, the exact expression the consumer used). Run it against the tree BEFORE
 * the trait engine to produce the fixtures of RelDynTraitEquivalenceTest; the test runs the
 * same capture against the current tree and compares (1e-9, same PHP types).
 *
 *   git archive 33392df6 ext/relationship_dynamics lib | tar -x -C /tmp/rd_base
 *   php ext/relationship_dynamics/tools/trait_equivalence_capture.php /tmp/rd_base > base.json
 *
 * The argument is a repository root (lib/ and ext/relationship_dynamics/); default: this tree.
 * No database: shipped config defaults. Output: one JSON object, one label per line.
 * Exit codes: 0 done, 1 bad arguments.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Labels captured: the 13 presets, then null (no temperament), '' (the jealousy consumer's key),
 *  'stoic' (lower case: exact-key tables miss it, validTemperament consumers read Stoic) and
 *  'Volatile' (the MDD 15.4 resistance row that is not a temperament). */
const RELDYN_TRAIT_CAPTURE_LABELS = ['Romantic', 'Anxious', 'Bold', 'Playful', 'Humble', 'Nurturing', 'Gentle',
    'Jealous', 'Proud', 'Defiant', 'Guarded', 'Independent', 'Stoic', null, '', 'stoic', 'Volatile'];

function reldyn_trait_capture_key($label): string
{
    return $label === null ? '(null)' : ($label === '' ? "('')" : (string) $label);
}

/** Every consumer's value for one label (the code already loaded: RelationshipDynamics & co.). */
function reldyn_trait_capture_label($t): array
{
    $RD = 'RelationshipDynamics';
    $priv = function (string $method, array $args) use ($RD) {
        $m = new ReflectionMethod($RD, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    };
    $dims = array_keys($RD::DIMENSION_DEFS);
    $r = [];

    // Consumers that read a table inline. Before the engine: the expression each consumer used.
    // After: the engine call each consumer makes now (same default).
    if (class_exists('RelDynTraits')) {
        $r['passion_mult'] = RelDynTraits::param($t, 'passion_mult', 1.0);                    // gainPassion
        $r['bleedout'] = RelDynTraits::param($t, 'bleedout', -1.5);                            // postrequest bleedout
        $r['reunion_mult'] = RelDynTraits::param($t, 'reunion_mult', 1.0);                     // checkReunion
        $r['jealousy_mult'] = floatval(RelDynTraits::param($t ?? '', 'jealousy_mult', 1.0));   // jealousyEventGain
        $r['tier_retention'] = RelDynTraits::param($t, 'tier_retention', -15);                 // checkTierDemotion
        $r['absence_decay'] = RelDynTraits::param($t, 'absence_decay', -0.5);                  // processAffinityDecay
    } else {
        $r['passion_mult'] = $RD::TEMPERAMENT_PASSION_MULT[$t] ?? 1.0;
        $r['bleedout'] = $RD::TEMPERAMENT_BLEEDOUT_DRAIN[$t] ?? -1.5;
        $r['reunion_mult'] = $RD::TEMPERAMENT_REUNION_MULT[$t] ?? 1.0;
        $r['jealousy_mult'] = floatval($RD::TEMPERAMENT_JEALOUSY_MULT[$t ?? ''] ?? 1.0);
        $r['tier_retention'] = $RD::TEMPERAMENT_TIER_RETENTION[$t] ?? -15;
        $r['absence_decay'] = $RD::TEMPERAMENT_DECAY_RATES[$t] ?? -0.5;
    }

    foreach (['affinity', 'trust', 'comfort', 'respect', 'maturity', 'passion', 'unknown_signal'] as $sig) {
        $r['resistance'][$sig] = $RD::getSignalResistance($t, $sig);
    }
    foreach ($dims as $dim) {
        $r['baseline'][$dim] = $RD::getTemperamentBaseline($t, $dim);
        $r['plasticity'][$dim] = $RD::getPlasticityProfile($t, $dim, []);
    }
    $r['plasticity']['maturity_ctx_null_type'] = $RD::getPlasticityProfile($t, 'maturity', ['plasticity_type' => null]);
    $r['maturity_type'] = $RD::getMaturityPlasticityType($t);
    $r['maturity_type_resolved'] = $RD::resolveMaturityType(['inferred_temperament' => $t]);
    $r['sensitivity_curve'] = $RD::getSocialSensitivityCurve($t, null);
    foreach ($dims as $dim) {
        foreach ([0, 10, 25, 50, 75, 90, 100] as $b) {
            $d = ['dimensions' => ['affinity' => ['x' => $b]]];
            $r['social_sensitivity'][$dim][$b] = [
                $RD::applySocialSensitivity($d, $dim, 1.0, $t),
                $RD::applySocialSensitivity($d, $dim, -1.0, $t),
            ];
        }
    }
    $r['love_language'] = $priv('temperamentToLoveLanguage', [$t]);
    $r['warmth_curve'] = $priv('temperamentToWarmthCurve', [$t]);
    foreach ([9, 30, 60] as $h) {
        $r['reunion_text'][$h] = $RD::getReunionText('Mira', $t, $h, 'Kaida');
    }
    $prof = $RD::getArchetypeProfile(['inferred_temperament' => $t]);
    $r['archetype_profile'] = md5(json_encode($prof));
    foreach ($RD::ATTRACTION_ARCHETYPES as $aname => $a) {
        if (!isset($a['tier_thresholds'])) $a['tier_thresholds'] = $RD::DEFAULT_TIER_THRESHOLDS;
        if ($a === $prof) { $r['archetype_profile'] = $aname; break; }
    }
    foreach (['rock', 'catalyst', 'charmer', 'nonexistent'] as $style) {
        foreach ([40, 70] as $mat) {
            foreach (['affinity', 'passion', 'trust'] as $dim) {
                $r['charisma'][$style][$mat][$dim] = $RD::getCharismaEffectiveness($style, $t, $mat, $dim);
            }
        }
    }
    $r['neglect'] = $RD::getNeglectProfile(['inferred_temperament' => $t]);
    if (is_string($t)) {
        foreach ([null, 'Bard', 'Noble'] as $arch) {
            $r['profile_dependents'][$arch ?? '(none)'] = $priv('deriveProfileDependents', [$t, $arch, $RD::getTemperamentAutogenConfig()]);
        }
    }
    $r['attachment_derive'] = $RD::deriveAttachmentAxes(['archetype' => null, 'temperament' => $RD::validTemperament($t), 'traits' => [], 'losses' => 0, 'text_hits' => []]);
    $r['attachment_derive_raw'] = $RD::deriveAttachmentAxes(['archetype' => null, 'temperament' => $t, 'traits' => [], 'losses' => 0, 'text_hits' => []]);
    $r['attachment_trait_evidence'] = $priv('attachmentTraitEvidence', [[
        'inferred_temperament' => $t, 'traits' => ['insecure', 'egocentric'],
        '_profile_autogen' => ['traits_source' => 'derived', 'archetype' => null]]]);
    $r['intimacy'] = RelDynIntimacy::derive([
        'race' => null, 'creature' => null, 'temperament' => $RD::validTemperament($t), 'attachment' => ['secure' => 1.0],
        'traits' => [], 'love_language_primary' => null, 'love_language_secondary' => null, 'maturity' => 50.0,
        'preference' => null, 'gate' => 'balanced']);
    $r['facet_prefs'] = RelDynFacets::derivePreferences([], ['temperament' => $t, 'traits' => []]);
    $def = RelDynAttraction::definition('Traitless Test', ['inferred_temperament' => $t]);
    $r['attraction_openness'] = [$def['openness'], $def['sources']['openness'] ?? null];
    // requiredMomentum needs a stored NPC; its temperament factor, as the consumer reads it
    $rcfg = RelDynRomance::config();
    $r['momentum_temperament_mult'] = class_exists('RelDynTraits')
        ? floatval(RelDynTraits::tableParam((string) ($t ?? ''), (array) $rcfg['momentum_temperament_mult'], 1.0, 'R',
            fn(array $x) => 1.0 + 4.0 * max(0.0, $x['G'] - 0.6), 'mult'))
        : floatval(((array) $rcfg['momentum_temperament_mult'])[(string) ($t ?? '')] ?? 1.0);
    $r['fulfillment_needs'] = RelDynFulfillment::needs(['inferred_temperament' => $t], []);

    // physical states (A23 healer / warrior gates) through applyPhysicalStateModifiers
    foreach (array_keys($RD::PHYSICAL_STATE_MODIFIERS) as $state) {
        $d = $RD::migrateDimensions($RD::defaultDynamics());
        $RD::applyPhysicalStateModifiers($d, [$state], $t);
        $r['physical'][$state] = $d['_applied_physical_deltas'] ?? null;
    }
    // end to end: applyDelta on every dimension (baseline, plasticity, rubber band) +7 / -7
    foreach ($dims as $dim) {
        foreach ([7.0, -7.0] as $raw) {
            $d = $RD::migrateDimensions($RD::defaultDynamics());
            $d['inferred_temperament'] = $t;
            $a = $RD::applyDelta($dim, $d, $raw, $t);
            $r['apply_delta'][$dim][(string) $raw] = [$a, $d['dimensions'][$dim]['x'] ?? null];
        }
    }
    // end to end: passion gain, reunion spike, jealousy event gain (as RelDynTemperamentMddTest)
    $cal = 3.0e9;   // raw gamets, game day 300
    $savedRequest = $GLOBALS['gameRequest'] ?? null;
    $GLOBALS['gameRequest'] = ['inputtext', 1700000000, $cal, 'Kaida: hello'];
    $n = $RD::migrateDimensions($RD::defaultDynamics());
    $n['inferred_temperament'] = $t;
    $n['love_language_primary'] = $RD::LL_TIME;
    $n['love_language_secondary'] = $RD::LL_WORDS;
    $n['_last_contact_gamets'] = $cal - $RD::GAMETS_PER_DAY;
    $n['_accumulated_play_gamets'] = 10 * $RD::GAMETS_PER_REAL_HOUR;
    $n['_last_contact_play_gamets'] = 8 * $RD::GAMETS_PER_REAL_HOUR;
    $r['e2e']['passion_gain'] = $RD::calculatePassionGain($n, $RD::LL_TIME);
    $m = $n;
    $r['e2e']['reunion'] = $RD::checkReunion($m, 100);
    // Phase 3 (traits design §1.4) damps every jealousy gain by the bond's trust; this pins the
    // temperament path, so the bond has no trust value (damping factor 1.0, as before phase 3).
    $j = $n;
    unset($j['dimensions']['trust']);
    foreach ([1, 2, 3] as $i) {
        $r['e2e']['jealousy'][$i] = $RD::jealousyEventGain(['profile_overrides' => ['attachment_style' => 'secure']] + $j, $i);
    }
    if ($savedRequest !== null) $GLOBALS['gameRequest'] = $savedRequest; else unset($GLOBALS['gameRequest']);
    // end to end: tier retention (A19) in checkTierDemotion, core affinity 20 / 5 below a held 'friend' (floor 31)
    foreach ([60.0, 52.5, 45.0] as $mirrorX) {
        $d = ['_aff_mirror_x' => $mirrorX, 'dimensions' => ['affinity' => ['x' => $mirrorX]]];
        $r['e2e']['tier_demotion'][(string) $mirrorX] = $RD::checkTierDemotion($d, $t, 'friend', 'friend');
    }
    return $r;
}

/** label key => capture, for every label. */
function reldyn_trait_capture_all(): array
{
    $out = [];
    foreach (RELDYN_TRAIT_CAPTURE_LABELS as $t) {
        $out[reldyn_trait_capture_key($t)] = reldyn_trait_capture_label($t);
    }
    return $out;
}

/** JSON, one label per line (the fixture format). */
function reldyn_trait_capture_json(array $all): string
{
    $lines = [];
    foreach ($all as $k => $v) {
        $lines[] = json_encode((string) $k) . ':' . json_encode($v, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return "{\n" . implode(",\n", $lines) . "\n}\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $root = rtrim($argv[1] ?? dirname(__DIR__, 3), '/');
    if (!is_file($root . '/ext/relationship_dynamics/relationship_dynamics.php')) {
        fwrite(STDERR, "usage: php trait_equivalence_capture.php [repo-root]\n");
        exit(1);
    }
    ini_set('error_log', '/dev/null');   // the consumers log; the capture is the output
    require_once $root . '/lib/logger.php';
    require_once $root . '/ext/relationship_dynamics/relationship_dynamics.php';
    unset($GLOBALS['db']);
    RelationshipDynamics::clearConfigCache();
    echo reldyn_trait_capture_json(reldyn_trait_capture_all());
}
