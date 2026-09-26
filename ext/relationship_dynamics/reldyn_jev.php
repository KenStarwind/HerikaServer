<?php
/**
 * Jev's explicit state (decisions 2026-09-23 §3, the one exception to felt steering).
 *
 * Jev (the tactical action picker) does not roleplay: "if I feel this and my goal is that, then
 * I do x". It receives the concrete values, not the felt prose the dialogue model gets. The Jev
 * plugin is expected to call RelationshipDynamics::jevStateBlock($npcName) and use either the
 * structured array or its compact 'text' rendering (this file never calls or edits Jev).
 *
 * Fields (all toward the player; defaults for an NPC RelDyn never saw, nothing invented):
 *   npc              string
 *   affinity         float  core units -100..100 (core relationships.Player.aff + RelDyn's
 *                           uncommitted change; RelationshipDynamics::getCoreAffinity)
 *   affinity_tier    string hostile|stranger|acquaintance|friend|close_friend|bonded|devoted
 *   context_tier     int    0..3 (with the high-water mark)
 *   trust, comfort, respect, warmth, maturity, resentment, resentment_self, self_confidence
 *                    float  dimension points 0..100 (warmth derived: sqrt(effective passion x comfort)
 *                           + the states held on it, roadmap derived-warmth)
 *   arousal          float  0..100      valence  float -100..100
 *   passion          float  0..100 the floor: passion earned through play (the Attraction Matrix's uphill)
 *   passion_spike    float  0..100 the moment on top of it (roadmap passion-floor-spike: fades per exchange)
 *   passion_effective float 0..100 floor + spike + the weather's pull (what she feels right now)
 *   jealousy         float  0..100      jealousy_rival ?string
 *   attachment       string secure|anxious|avoidant|toxic (the style region of the axes; toxic =
 *                           fearful, MDD Toxic/Disorganized)
 *   attachment_anxiety, attachment_avoidance
 *                    float  0..1 the two attachment axes (decisions §12, Fraley & Shaver): fear of
 *                           abandonment; discomfort with closeness once someone is in
 *   temperament      ?string the nearest preset (display; traits phase 3: the numbers are below)
 *   traits           ?array  storage name => 0..1 (unitless), the NPC's own trait vector (design §1;
 *                            null when the NPC has none yet)
 *   trait_preset     ?array  ['nearest' => preset, 'distance' => trait-space distance (unitless)]
 *   relationship_type string RelDyn type (RelationshipDynamics::getRelationshipType)
 *   core_type        ?string core relationships.Player.type
 *   weather          string sunny|clear|overcast|stormy
 *   weather_pull     array  dimension => points the weather's gravity holds on it now (MDD 4.1 target
 *                           node, roadmap weather-gravity-pull; passion / warmth read at display time)
 *   open_conflict    bool   conflict_repairs int (positive interactions since it opened)
 *   boundary         string none|pending|probation|failed (mature boundary, rulings §9)
 *   concern          ['level' => concern points 0..100 (protective worry, traits design §1.4),
 *                     'band' => none|uneasy|raise|insist, 'possessive_incidents' /
 *                     'protective_incidents' => the channel's highest pattern[kind] in the values
 *                     window (§1.5), 'pattern' => kind => counted nights of that kind,
 *                     'values_boundary' => none|pending|probation|failed]
 *   walkaway         string normal|pending|active|boundary_test|recovery|permanent
 *   resentment_arc   ['confrontation_threshold' => resentment points, 'confrontations' => int said
 *                     this episode, 'confrontation_pending' => ?mature|mixed|immature,
 *                     'self_baseline_offsets' => dimension => baseline points (resentment_self),
 *                     'self_crisis' => bool, 'guilt_bleed' => comfort points taken (<= 0),
 *                     'reject_recruitment' => bool] (reldyn_resentment.php)
 *   fulfillment      ['band' => -1..1, 'trend' => band per game day, 'low' => bool, 'known' => bool]
 *   exclusivity      null | ['pull' => 0..1 (decisions §17: toward the player), 'band' => devoted|taken|
 *                     leaning|open, 'titled' => bool, 'stepped_back' => bool (rulings §9: released), 'unweakened' => 0..1 (before low fulfillment /
 *                     neglect), 'low_cut' / 'neglect' => multipliers 0..1, 'suitor_interest' => name =>
 *                     interest points 0..100 (her damped romantic interest, top 3)] (RelDynExclusivity::jev)
 *   attraction       ['enabled' => bool, 'outcome' => ?string, 'score' => 0..1,
 *                     decisions §13, attraction is an uphill, not a wall:
 *                     'curve' => the passion curve (unitless; < 1 below the NPC's floors, 1 at them,
 *                                up to the surplus cap above),
 *                     'spark' => passion points open to anyone at spark_mult,
 *                     'spark_mult' => gain multiplier below the spark (attachment; 0 for a hard zero),
 *                     'passion_mult' => gain multiplier from the spark = curve x attachment [x prebond],
 *                     'hard_zero' => ?string (orientation | preference:<type> | rigid:<pillar>),
 *                     'attracted' => bool (every passion unit at its MDD bar, a balanced NPC
 *                                at the bonded tier, or won over),
 *                     'won_over' => bool (below her bars, passion climbed past won_over_passion),
 *                     'passion_ceiling' => ?float passion points (MDD 1.4: a passion pillar below
 *                                its bar at medium / high openness; gains stop there),
 *                     'channel' => ?string ('emotional': asexual, only the emotional channels
 *                                move passion, decisions §15),
 *                     'charm' => 0..1 (speech; closes up to charm_hill_max of the gap to her floor),
 *                     'relief' => 0..1 (a balanced NPC's bond easing the visceral hill),
 *                     'units' => [unit => ['score' => pillar points 0..100, 'floor' => pillar points,
 *                                'met' => bool (at its MDD bar), 'm' => multiplier]],
 *                     'respect_mult' => respect-gain multiplier (0.5..2.0, 1 at the neutral pillar score),
 *                     'friendzoned' => bool (a label; no passion cap)]
 *   place            null | ['name' => ?string, 'valence' => -1..1, 'intensity' => 0..1, 'dominant' => ?string]
 *   governor         null | ['tier' => distant|friendly|crush|committed|hostile, 'passion_floor',
 *                    'passion_ceiling' (passion points), 'raised' => bool] (MDD 8 tiered governors,
 *                    RelDynGovernors::jev; null while they are off)
 *   rescue           null | ['pending' => bool (her fall waits for the player's next exchange),
 *                    'last_bonus' => ?passion points of the last caring response, 'last_gamets' => ?raw
 *                    gamets of it] (MDD 3.3 rescue response, RelDynCombat)
 *   creature         null | ['type' => vampire|werewolf, 'state' => ?string (vampire_night|vampire_day|
 *                    werewolf_moon|werewolf_night|werewolf_day), 'moon' => ?string (Skyrim's phase),
 *                    'offsets' => [dim => points held now]] (RelDynCreatures::jev)
 *   protocols        ['grief' => [deceased => ['phase' 1..4, 'memory_warmth', 'widow_lock' => bool]],
 *                    'widow_ceiling' => ?core points, 'crisis' => null | ['event', 'fraction' 0..1],
 *                    'arc' => ?redemption|breaking, 'ick' => bool, 'parasite' => bool,
 *                    'gift_share' => 0..1] (RelDynProtocols::jev)
 *   goal             null | ['text' => string, 'priority' => 0..1]
 *   intrinsic_goals  list of ['type' => bond_seeking|purpose|mastery|safety|independence|revenge|
 *                    self_worth_recovery, 'priority' => 0..1, 'progress' => 0..1, 'source' => string,
 *                    'phase' => ?string (self-worth: change|maintain), 'keywords' => string[]]
 *                    (RelDynGoals, MDD 14.2), highest priority first
 *   impulse          null | ['levels' => type => impulse points 0..100 (romantic, protective, social,
 *                    survival, curiosity; MDD 13.1, as of her last turn on her play clock),
 *                    'threshold' => impulse points (14 + 69 G - 8 C), 'style' => bold|guarded|anxious|
 *                    playful|stoic|proud|gentle, 'false_start' => bool, 'firing' => types at or above
 *                    the threshold, strongest first, 'top' => ?type, 'source' => ?string (what drives
 *                    the top one), 'motivations' => [['type' => goal type, 'weight' => 0..1]] (the long
 *                    band, top 3), 'conflict' => null | ['impulse' => type, 'motivation' => goal type |
 *                    'director', 'weight' => 0..1, 'alignment' => -1..1, 'resolution' => impulse|
 *                    impulse_soft|motivation|freeze|dignity_motivation|dignity_impulse|dignity_neither]]
 *                    (RelDynImpulse, MDD 13.3)
 *   reputation       null | ['fame' => 0..1, 'infamy' => 0..1, 'weight' => 0..1 (fades with meaningful
 *                    interactions), 'meaningful' => int, 'offsets' => dimension => points held now]
 *   duty             null | ['quest' => ?string, 'factor' => 0..1 on negative eval signals, 'hostile' => ?string]
 *   autonomy         ['state' => compliant|resistant|refusing|walkaway, 'score' => 0..100,
 *                    'refusal' => ?silent|boundary|dramatic|direct|manipulative, 'people_pleaser' => bool,
 *                    'denied_actions' => core action codes taken off the list]
 *   units            field => unit description
 *   text             compact one-line rendering ("key=value ...") for a prompt
 */
final class RelDynJev
{
    const UNITS = [
        'affinity' => 'core units -100..100',
        'dimensions' => 'points 0..100 (valence -100..100)',
        'passion' => 'points 0..100 (the floor)', 'passion_spike' => 'points 0..100', 'passion_effective' => 'points 0..100',
        'jealousy' => 'points 0..100',
        'fulfillment.band' => '-1..1 (below fulfillment low_band = neglected)',
        'fulfillment.trend' => 'band change per game day',
        'exclusivity.pull' => '0..1 (pull toward the player)', 'exclusivity.suitor_interest' => 'interest points 0..100',
        'attraction.curve' => 'passion curve, multiplier (1 at the floors)', 'attraction.spark' => 'passion points',
        'attraction.spark_mult' => 'passion-gain multiplier below the spark',
        'attraction.passion_mult' => 'passion-gain multiplier from the spark', 'attraction.respect_mult' => 'respect-gain multiplier',
        'attraction.units.score' => 'pillar points 0..100', 'attraction.units.floor' => 'pillar points 0..100',
        'attraction.relief' => '0..1', 'attraction.charm' => 'speech 0..1',
        'attraction.passion_ceiling' => 'passion points (null = none)',
        'attraction.score' => '0..1',
        'attachment_anxiety' => 'axis 0..1 (fear of abandonment)',
        'attachment_avoidance' => 'axis 0..1 (discomfort with closeness once in)',
        'concern.level' => 'concern points 0..100', 'concern.incidents' => 'highest pattern[kind] per channel in the values window (counted nights)',
        'concern.pattern' => 'kind => counted nights in the values window',
        'resentment_arc.confrontation_threshold' => 'resentment points 0..100',
        'resentment_arc.self_baseline_offsets' => 'baseline points', 'resentment_arc.guilt_bleed' => 'comfort points',
        'place.valence' => '-1..1', 'place.intensity' => '0..1', 'goal.priority' => '0..1',
        'creature.offsets' => 'dimension points held by the creature row',
        'governor.passion_floor' => 'passion points', 'governor.passion_ceiling' => 'passion points',
        'rescue.last_bonus' => 'passion points', 'rescue.last_gamets' => 'raw game gamets',
        'protocols.grief.memory_warmth' => 'warmth points 0..100 toward the deceased (idealized, then memorial)',
        'protocols.widow_ceiling' => 'core affinity points (the widow lock on new bonds)',
        'protocols.crisis.fraction' => '0..1 of the unstable window (game calendar)',
        'protocols.gift_share' => '0..1 of the exchanges in the parasite ledger',
        'intrinsic_goals.priority' => '0..1', 'intrinsic_goals.progress' => '0..1',
        'impulse.levels' => 'impulse points 0..100', 'impulse.threshold' => 'impulse points 0..100',
        'impulse.motivations.weight' => '0..1', 'impulse.conflict.weight' => '0..1', 'impulse.conflict.alignment' => '-1..1',
        'reputation.fame' => '0..1', 'reputation.infamy' => '0..1', 'reputation.weight' => '0..1',
        'reputation.offsets' => 'dimension points held now', 'duty.factor' => 'multiplier on negative eval signals',
        'autonomy.score' => '0..100',
        'absence.bond_break.absent_game_days' => 'game days', 'absence.bond_break.resentment' => 'resentment points added',
        'absence.bond_break.comfort_delta' => 'comfort points', 'absence.bond_break.trust_delta' => 'trust points',
        'absence.rot_applied' => 'core affinity points (total, <= 0)',
        'weather_pull' => 'dimension points held by the weather gravity',
    ];

    public static function state(string $npcName, array $dynamics, float $now): array
    {
        $dims = is_array($dynamics['dimensions'] ?? null) ? $dynamics['dimensions'] : [];
        $num = function (string $d, float $default) use ($dims): float {
            $x = $dims[$d]['x'] ?? null;
            return is_numeric($x) ? round(floatval($x), 2) : $default;
        };
        $affinity = round(RelationshipDynamics::getCoreAffinity($dynamics), 2);

        $fulfillment = ['band' => 0.0, 'trend' => 0.0, 'low' => false, 'known' => false];
        if ($now > 0 && RelDynFulfillment::enabled()) {
            $f = RelationshipDynamics::fulfillment($npcName, $dynamics, $now);
            $fulfillment = ['band' => round(floatval($f['band'] ?? 0), 3), 'trend' => round(floatval($f['trend'] ?? 0), 4),
                'low' => !empty($f['low_band']), 'known' => !empty($f['known'])];
        }

        $a = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : [];
        $units = [];
        foreach ((array) ($a['passion']['units'] ?? []) as $key => $u) {
            $units[(string) $key] = ['score' => round(floatval($u['score'] ?? 0), 2), 'floor' => round(floatval($u['floor'] ?? 0), 2),
                'met' => (bool) ($u['met'] ?? true), 'm' => round(floatval($u['m'] ?? 1.0), 4)];
        }
        $passionMult = round(floatval($a['passion_mult'] ?? $dynamics['_attraction_passion_mult'] ?? 1.0), 4);
        $attraction = [
            'enabled' => !empty($a['enabled']),
            'outcome' => isset($a['outcome']) ? (string) $a['outcome'] : null,
            'curve' => round(floatval($a['passion']['curve'] ?? 1.0), 4),
            'spark' => round(floatval($a['spark'] ?? 0.0), 2),
            'spark_mult' => round(floatval($a['spark_mult'] ?? $passionMult), 4),
            'passion_mult' => $passionMult,
            'hard_zero' => isset($a['hard_zero']) ? (string) $a['hard_zero'] : null,
            'attracted' => empty($a['enabled']) ? true : !empty($a['attracted']),
            'won_over' => !empty($a['won_over']),
            'passion_ceiling' => is_numeric($a['passion_ceiling'] ?? null) ? round(floatval($a['passion_ceiling']), 2) : null,
            // decisions §15: 'emotional' = only the emotional channels move passion (asexual)
            'channel' => isset($a['passion_channel']) ? (string) $a['passion_channel'] : null,
            'charm' => round(floatval($a['passion']['charm'] ?? 0.0), 4),
            'relief' => round(floatval($a['passion']['relief'] ?? 0.0), 4),
            'units' => $units,
            'respect_mult' => round(floatval($a['respect_mult'] ?? 1.0), 4),
            'score' => round(floatval($a['score'] ?? 0), 4),
            'friendzoned' => !empty($a['friendzoned']),
        ];

        $place = null;
        $pa = $dynamics['_place_appraisal'] ?? null;
        if (is_array($pa) && isset($pa['valence'])) {
            $place = ['name' => isset($pa['place']) ? (string) $pa['place'] : null,
                'valence' => round(floatval($pa['valence']), 3), 'intensity' => round(floatval($pa['intensity'] ?? 0), 3),
                'dominant' => isset($pa['dominant']) ? (string) $pa['dominant'] : null];
        }

        $goal = null;
        $g = RelationshipDynamics::getActiveDirectorGoal($dynamics);
        if (is_array($g) && trim((string) ($g['text'] ?? '')) !== '') {
            $goal = ['text' => trim((string) $g['text']), 'priority' => round(floatval($g['priority'] ?? 0.5), 2)];
        }

        $boundary = RelDynFulfillment::pairState($dynamics)['boundary']['state'] ?? 'none';
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        $traitVector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $rival = trim((string) ($dynamics['jealousy_trigger_npc'] ?? ''));
        $out = [
            'npc' => $npcName,
            'affinity' => $affinity,
            'affinity_tier' => RelationshipDynamics::getCurrentTier($affinity),
            'context_tier' => RelationshipDynamics::getContextTier($dynamics),
            'trust' => $num('trust', 50.0), 'comfort' => $num('comfort', 50.0), 'respect' => $num('respect', 50.0),
            'warmth' => round(RelDynPassion::warmth($dynamics, false) ?? 50.0, 2), 'maturity' => $num('maturity', 50.0), 'resentment' => $num('resentment', 0.0),
            'resentment_self' => $num('resentment_self', 0.0), 'self_confidence' => $num('self_confidence', 50.0),
            'arousal' => $num('arousal', 10.0), 'valence' => $num('valence', 0.0),
            'passion' => round(RelationshipDynamics::getPassion($dynamics), 2),
            'passion_spike' => round(RelDynPassion::spike($dynamics), 2),
            'passion_effective' => round(RelationshipDynamics::getEffectivePassion($dynamics), 2),
            'jealousy' => round(floatval($dynamics['jealousy_anger'] ?? 0), 2),
            'jealousy_rival' => $rival !== '' ? $rival : null,
            'attachment' => RelationshipDynamics::attachmentStyleOf($axes['anxiety'], $axes['avoidance']),
            'attachment_anxiety' => round($axes['anxiety'], 3),
            'attachment_avoidance' => round($axes['avoidance'], 3),
            'temperament' => RelationshipDynamics::validTemperament($dynamics['inferred_temperament'] ?? null),
            'traits' => $traitVector !== null ? array_map(fn($v) => round(floatval($v), 3), array_intersect_key(RelDynTraits::toStored($traitVector), array_flip(RelDynTraits::TRAITS))) : null,
            'trait_preset' => $traitVector !== null ? ['nearest' => RelDynTraits::nearestPreset($traitVector)['name'],
                'distance' => round(RelDynTraits::nearestPreset($traitVector)['distance'], 3)] : null,
            'relationship_type' => (string) RelationshipDynamics::getRelationshipType($npcName, $dynamics),
            'core_type' => isset($dynamics['_core_rel_type']) ? (string) $dynamics['_core_rel_type'] : null,
            'weather' => (string) ($dynamics['_internal_weather'] ?? 'clear'),
            'weather_pull' => array_map(fn($v) => round(floatval($v), 2), (array) ($dynamics['_weather_gravity']['offsets'] ?? [])),
            'open_conflict' => !empty($dynamics['in_conflict']),
            'conflict_repairs' => intval($dynamics['conflict_positive_count'] ?? 0),
            'boundary' => is_string($boundary) ? $boundary : 'none',
            'concern' => RelDynConcern::jev($dynamics, $now),
            'walkaway' => (string) ($dynamics['_walkaway_state'] ?? 'normal'),
            'resentment_arc' => RelDynResentment::jev($dynamics),
            'absence' => RelDynAbsence::jev($dynamics),
            'fulfillment' => $fulfillment,
            'exclusivity' => RelDynExclusivity::jev($dynamics, $now),
            'attraction' => $attraction,
            'place' => $place,
            'governor' => RelDynGovernors::jev($dynamics),
            'rescue' => RelDynCombat::jev($dynamics),
            'creature' => RelDynCreatures::jev($dynamics),
            'protocols' => RelDynProtocols::jev($dynamics),
            'goal' => $goal,
            'intrinsic_goals' => RelDynGoals::jev($dynamics),
            'impulse' => RelDynImpulse::jev($dynamics),
            'reputation' => RelDynReputation::jev($dynamics),
            'duty' => RelDynQuests::jev($dynamics),
            'autonomy' => self::autonomy($dynamics),
            'units' => self::UNITS,
        ];
        $out['text'] = self::render($out);
        return $out;
    }

    /** The autonomy evaluation (MDD 6.4) as Jev needs it. */
    private static function autonomy(array $dynamics): array
    {
        $temperament = (string) ($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic');
        $e = RelationshipDynamics::evaluateAutonomyState($dynamics, $temperament);
        return ['state' => (string) $e['state'], 'score' => round(floatval($e['autonomy_score']), 2),
            'refusal' => $e['refusal_type'] !== null ? (string) $e['refusal_type'] : null,
            'people_pleaser' => !empty($e['people_pleaser']), 'denied_actions' => array_values((array) $e['deny_actions'])];
    }

    /** Compact one-line rendering for a prompt: "key=value" pairs, numbers as they are. */
    public static function render(array $s): string
    {
        $f = fn(float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $parts = [
            "npc={$s['npc']}",
            'affinity=' . $f($s['affinity']) . "({$s['affinity_tier']})",
            "type={$s['relationship_type']}",
        ];
        foreach (['trust', 'comfort', 'respect', 'warmth', 'maturity', 'passion'] as $k) $parts[] = "{$k}=" . $f($s[$k]);
        if ($s['passion_spike'] > 0) $parts[] = 'passion_spike=' . $f($s['passion_spike']);
        $parts[] = 'jealousy=' . $f($s['jealousy']) . ($s['jealousy_rival'] !== null ? "(rival {$s['jealousy_rival']})" : '');
        $parts[] = 'resentment=' . $f($s['resentment']);
        $parts[] = 'mood=' . $f($s['arousal']) . '/' . $f($s['valence']);
        $parts[] = "attachment={$s['attachment']}(anxiety " . number_format($s['attachment_anxiety'], 2, '.', '')
            . ' avoidance ' . number_format($s['attachment_avoidance'], 2, '.', '') . ')';
        if ($s['temperament'] !== null) $parts[] = "temperament={$s['temperament']}";
        if ($s['traits'] !== null) {
            $codes = array_flip(RelDynTraits::TRAITS);
            $t = [];
            foreach ($s['traits'] as $name => $v) $t[] = $codes[$name] . number_format($v, 2, '.', '');
            $parts[] = 'traits=' . implode(',', $t) . '(' . $s['trait_preset']['nearest'] . ' '
                . number_format($s['trait_preset']['distance'], 2, '.', '') . ')';
        }
        $parts[] = "weather={$s['weather']}";
        $parts[] = 'conflict=' . ($s['open_conflict'] ? 'open' : 'none');
        $parts[] = "boundary={$s['boundary']}";
        $c = $s['concern'];
        $pattern = [];
        foreach ((array) ($c['pattern'] ?? []) as $kind => $n) $pattern[] = "{$kind}:" . intval($n);
        $parts[] = 'concern=' . $f($c['level']) . "({$c['band']}) incidents=" . $c['possessive_incidents'] . '/' . $c['protective_incidents']
            . ($pattern ? ' pattern=' . implode(',', $pattern) : '')
            . ($c['values_boundary'] !== 'none' ? " values_boundary={$c['values_boundary']}" : '');
        $parts[] = "walkaway={$s['walkaway']}";
        $r = $s['resentment_arc'];
        $offsets = [];
        foreach ($r['self_baseline_offsets'] as $dim => $o) $offsets[] = "{$dim}" . $f($o);
        $parts[] = 'resentment_self=' . $f($s['resentment_self']) . ($offsets ? '(baseline ' . implode(',', $offsets) . ')' : '')
            . ($r['self_crisis'] ? '(crisis)' : '')
            . ' confront=' . $r['confrontations'] . '/at ' . $f($r['confrontation_threshold'])
            . ($r['confrontation_pending'] !== null ? "(due {$r['confrontation_pending']})" : '')
            . ($r['guilt_bleed'] != 0.0 ? ' guilt=' . $f($r['guilt_bleed']) : '')
            . ($r['reject_recruitment'] ? ' reject_recruitment' : '');
        $parts[] = 'fulfillment=' . number_format($s['fulfillment']['band'], 2, '.', '') . ($s['fulfillment']['low'] ? '(low)' : '');
        $ab = $s['absence'] ?? null;
        if (is_array($ab) && ($ab['bond_break'] !== null || $ab['rot_conditions'] !== [] || $ab['rot_applied'] != 0.0)) {
            // compact: "absence=break(<mode> <game days>d +<resentment>) rot(<conditions> <core points>)"
            $bb = $ab['bond_break'];
            $bits = [];
            if ($bb !== null) $bits[] = "break({$bb['mode']} " . $f($bb['absent_game_days']) . 'd +' . $f($bb['resentment']) . ')';
            if ($ab['rot_conditions'] !== [] || $ab['rot_applied'] != 0.0) {
                $bits[] = 'rot(' . ($ab['rot_conditions'] !== [] ? implode('+', $ab['rot_conditions']) : '-')
                    . ($ab['rot_applied'] != 0.0 ? ' ' . $f($ab['rot_applied']) : '') . ')';
            }
            $parts[] = 'absence=' . implode(' ', $bits);
        }
        if (($s['exclusivity'] ?? null) !== null) {
            $x = $s['exclusivity'];
            $suitors = [];
            foreach ($x['suitor_interest'] as $name => $v) $suitors[] = "{$name} " . $f($v);
            $parts[] = 'exclusivity=' . number_format($x['pull'], 2, '.', '') . "({$x['band']}" . ($x['titled'] ? ', titled' : '') . ')'
                . ($suitors ? ' suitors=' . implode(',', $suitors) : '');
        }
        $a = $s['attraction'];
        if ($a['enabled']) {
            $unitText = [];
            foreach ($a['units'] as $key => $u) {
                $unitText[] = $key . ' ' . $f($u['score']) . '/' . $f($u['floor']) . ' x' . number_format($u['m'], 2, '.', '');
            }
            $parts[] = 'attraction=' . ($a['outcome'] ?? 'unknown') . ' curve=' . number_format($a['curve'], 2, '.', '')
                . ($unitText ? '[' . implode(', ', $unitText) . ']' : '')
                . ' spark=' . $f($a['spark']) . '(x' . number_format($a['spark_mult'], 2, '.', '') . ')'
                . ' passion_mult=' . number_format($a['passion_mult'], 2, '.', '')
                . ($a['hard_zero'] !== null ? " hard_zero={$a['hard_zero']}" : '')
                . ($a['won_over'] ? ' won_over' : '')
                . ($a['channel'] !== null ? " channel={$a['channel']}" : '')
                . ($a['passion_ceiling'] !== null ? ' passion_ceiling=' . $f($a['passion_ceiling']) : '');
        }
        if ($s['place'] !== null) {
            $parts[] = 'place=' . number_format($s['place']['valence'], 2, '.', '') . ($s['place']['dominant'] !== null ? "({$s['place']['dominant']})" : '');
        }
        if (($s['creature'] ?? null) !== null) {
            $parts[] = 'creature=' . $s['creature']['type'] . ($s['creature']['state'] !== null ? "({$s['creature']['state']})" : '')
                . ($s['creature']['moon'] !== null ? " moon={$s['creature']['moon']}" : '');
        }
        $pr = $s['protocols'] ?? null;
        if (is_array($pr)) {
            foreach ($pr['grief'] as $name => $g) {
                $parts[] = "grief={$name}(phase {$g['phase']}" . ($g['memory_warmth'] !== null ? ' memory ' . $f($g['memory_warmth']) : '')
                    . ($g['widow_lock'] ? ' widow_lock' : '') . ')';
            }
            if ($pr['widow_ceiling'] !== null) $parts[] = 'widow_ceiling=' . $f($pr['widow_ceiling']);
            if ($pr['crisis'] !== null) $parts[] = "crisis={$pr['crisis']['event']}(" . number_format($pr['crisis']['fraction'], 2, '.', '') . ')';
            if ($pr['arc'] !== null) $parts[] = "arc={$pr['arc']}";
            if ($pr['ick']) $parts[] = 'ick';
            if ($pr['parasite']) $parts[] = 'parasite(gift_share ' . number_format($pr['gift_share'], 2, '.', '') . ')';
        }
        if ($s['goal'] !== null) {
            $parts[] = 'goal="' . str_replace('"', "'", $s['goal']['text']) . '"(' . number_format($s['goal']['priority'], 1, '.', '') . ')';
        }
        $au = $s['autonomy'] ?? null;
        if (is_array($au)) {
            $parts[] = "autonomy={$au['state']}(" . $f($au['score']) . ')' . ($au['refusal'] !== null ? " refusal={$au['refusal']}" : '')
                . ($au['people_pleaser'] ? ' people_pleaser' : '');
        }
        foreach ((array) ($s['intrinsic_goals'] ?? []) as $g) {
            $parts[] = "intrinsic={$g['type']}(" . number_format($g['priority'], 2, '.', '') . ' progress ' . number_format($g['progress'], 2, '.', '') . ')';
        }
        if (($s['impulse'] ?? null) !== null) {
            $im = $s['impulse'];
            // compact: "impulse=<top> <level>/<threshold> <style>" ('-' when nothing fires; anxious = false starts)
            $parts[] = 'impulse=' . ($im['top'] !== null ? $im['top'] . ' ' . $f($im['levels'][$im['top']]) : '-')
                . '/' . $f($im['threshold']) . " {$im['style']}"
                . ($im['source'] !== null ? " from={$im['source']}" : '')
                . (count($im['firing']) > 1 ? ' also=' . implode(',', array_slice($im['firing'], 1)) : '');
            if ($im['conflict'] !== null) {
                $c = $im['conflict'];
                $parts[] = "inner_conflict={$c['impulse']} vs {$c['motivation']}(" . number_format($c['weight'], 2, '.', '') . ") -> {$c['resolution']}";
            }
        }
        // compact: a reputation she has heard nothing of says nothing (the state block keeps it)
        if (($s['reputation'] ?? null) !== null && (floatval($s['reputation']['fame']) > 0.0 || floatval($s['reputation']['infamy']) > 0.0)) {
            $r = $s['reputation'];
            $parts[] = 'reputation=fame ' . number_format($r['fame'], 2, '.', '') . ' infamy ' . number_format($r['infamy'], 2, '.', '')
                . ' weight ' . number_format($r['weight'], 2, '.', '');
        }
        if (($s['duty'] ?? null) !== null) {
            $parts[] = 'duty=' . ($s['duty']['quest'] !== null ? '"' . str_replace('"', "'", $s['duty']['quest']) . '"' : 'flag')
                . ' x' . number_format($s['duty']['factor'], 2, '.', '');
        }
        return implode(' ', $parts);
    }
}
