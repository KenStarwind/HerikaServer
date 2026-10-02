<?php
/**
 * Relationship Dynamics — the maturity gate, and the dark path (roadmap maturity-gate-dark-path;
 * dimension design draft "The Maturity Gate (The Dark Path)").
 *
 * Ken, 2026-10-01 (decisions §24): build it "when we can", read as direction, not law: dynamic, scaled by who the NPC
 * is, never a hard permanent gate, no one ever fully immune to a feeling. The draft's four corners of maturity x trust:
 *
 *   high maturity, high trust   healthy. The floors hold. Secure.
 *   high maturity, low trust    "I deserve better." The floor holds from the NPC's side: they walk away.
 *   low maturity,  high trust   codependent. Trusts blindly; easily steered by someone they trust.
 *   low maturity,  low trust    no floors. Passion builds from intensity alone and, held, it becomes a parasite.
 *
 * and "positive treatment raises maturity, so slowly the floors switch on: the dark path limits itself".
 *
 * NOTHING HERE IS A SWITCH. Maturity and trust are each read through a logistic curve (centred on the draft's 40 and on
 * the NPC's own trust line), so the corners are degrees that always add up to one NPC: 0.9 mature and 0.1 mature are both
 * real, and a curve never reaches 0 or 1. What each corner does is the existing machinery, reused, never a second copy:
 *
 *   FLOORS (the maturity gate itself). The tier floors of checkTierDemotion held a bond's tier while the floor gate
 *     dimension was at the gate line, and only above maturity 40. Now the floor's strength is the maturity curve s (0..1):
 *     the gate dimension must reach gate_line + (1 - s)^curve x (100 - gate_line) to hold (at s = 1 the old line), and
 *     below `gate.off_below` the floor is gone altogether (the old "immature: no floors"). The reported floor_active stays
 *     "maturity above 40".
 *
 *   I DESERVE BETTER. degree = maturity x low trust x the fall (trust must have FALLEN from where it was: a bond that never had trust,
 *     or a state that simply begins fresh over a deep core affinity, is nothing to leave over) x how deep the bond is x what that kind of
 *     bond expects of the other. A pressure (0..1) follows it on the game calendar (up at a day or two of low trust, down slowly; a bond that
 *     has not been invested in cannot reach it). Leaving is the walkaway system: at the NPC's own line (walk_at, lowered
 *     by pride and avoidance, raised by the fear of losing the player: RelDynKeeping) autonomy asks for a walkaway with the
 *     reason 'deserve' (RelationshipDynamics::walkawayReason, initiateWalkaway, the boundary test and its return grace),
 *     and a romance that is walked out of is ended through the breakup fork (RelDynBonds::breakup). Before the line the
 *     same pressure feeds the pull-back (RelDynPullback input 'standards'): a mature NPC closes off and says what is
 *     missing before they go. The pressure rises faster for a proud NPC and slower for a clinging one, never to a wall.
 *
 *   CODEPENDENT. degree = (1 - maturity) x high trust x bond. Trust stays where it was against evidence: the negative
 *     trust of an exchange lands at (1 - sticky x degree) of itself, never below trust_floor (they can still be hurt);
 *     autonomy's compliant / resistant / refusing lines lift by autonomy_lift points x degree (RelationshipDynamics::evaluateAutonomyState).
 *     (The fear of losing the player is already scaled by the attachment corners, insecurity and possessiveness that make an NPC
 *     codependent: RelDynKeeping needs no second lean.) This is the model of a person, never a verdict on the player. NOT a consent
 *     switch: RelDynConsent is not read or relaxed by any of this.
 *
 *   ADRIFT (no floors). degree = (1 - maturity) x low trust, fed by intensity (passion). Sustained, it sets RelDyn's own
 *     parasite overlay (the existing _relationship_type_override 'parasite' and its history; RelDynProtocols' passion
 *     half-life), held while the corner itself lasts (no floors, no trust: not the intensity that started it, which the overlay
 *     burns off) and never lifted by the gift ledger's recovery (holdsParasite). Positive treatment is how it ends: a positive
 *     exchange lifts maturity a little (growth), toward a floor that switches on, and trust comes back.
 *
 * Units: maturity / trust / passion dimension points 0..100; core affinity -100..100; degrees, pressure, strength 0..1
 * (unitless); game hours / days on the game calendar (raw gamets, RelationshipDynamics::GAMETS_PER_DAY). No wall clock.
 * State: $dynamics['_dark'] (state()).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynDark
{
    const KEY = '_dark';
    const VERSION = 1;
    const HEALTHY = 'healthy';
    const DESERVE = 'deserve';
    const CODEPENDENT = 'codependent';
    const ADRIFT = 'adrift';

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'dark_path' (nested tables merge per entry). Serene's starting values for Ken's §24 ruling. */
    public static function configDefaults(): array
    {
        return [
            // Off: the maturity gate is the old switch at maturity 40, no pressure, no corners, no growth.
            'enabled' => true,

            // --- maturity: how much of a floor the NPC has (logistic, 0..1) ---
            // centre: the draft's floor threshold (RelationshipDynamics::MATURITY_FLOOR_THRESHOLD); width: how soft the turn is.
            'maturity' => ['centre' => 40.0, 'width' => 4.0],
            // The floor's gate dimension must reach gate_line + (1 - s')^curve x (100 - gate_line) to hold, s' = s / full_at
            // (at or past full_at, which maturity 50 reaches, the old line exactly); below off_below (strength) the floor is gone (immature: demote on raw
            // affinity).
            'gate' => ['curve' => 2.0, 'off_below' => 0.02, 'full_at' => 0.92],

            // --- trust: the NPC's own line (logistic) ---
            // line = trust baseline + baseline_shift, kept within line_min..gate_line: trust below that is "low" for this
            // NPC (a guarded one trusts less to begin with; only a fall below their own normal counts).
            'trust' => ['gate_line' => 50.0, 'baseline_shift' => 10.0, 'line_min' => 25.0, 'width' => 8.0],

            // --- I deserve better ---
            'deserve' => [
                // how deep the bond must be (core affinity from -> full) before there is anything to deserve more of
                'bond' => ['from' => 10.0, 'full' => 40.0],
                // a bond must once have been a friend's at least (context_tier_hwm; the affinity walkaway's rule)
                'min_tier' => 2,
                // trust must have fallen this many points from the highest it reached (smoothstep from -> full); a state that begins fresh over a
                // deep core affinity has not fallen from anything. The peak is forgotten once trust is back at the NPC's line.
                'fall' => ['from' => 6.0, 'full' => 20.0],
                // what each kind of bond expects of the other (getRelationshipType); 'other' for any not listed
                'types' => ['bonded' => 1.0, 'crush' => 0.8, 'friend' => 0.7, 'friendzone' => 0.6, 'mentor' => 0.5,
                            'student' => 0.5, 'sworn' => 0.0, 'mercenary' => 0.0, 'parasite' => 0.0, 'rival' => 0.0,
                            'hostile' => 0.0, 'grieving' => 0.0, 'other' => 0.6],
                // the pressure follows its degree on the game calendar: this share of the gap per game hour
                'rates' => ['rise_per_game_hour' => 0.04, 'fall_per_game_hour' => 0.01, 'max_step_game_hours' => 72.0],
                // the line at which the NPC leaves: walk_at - pride_shift x egocentric(Pd) - avoidant_shift x avoidance
                // + fear_hold x the fear of losing the player, kept within walk_min..walk_max (never a wall: the pressure
                // can reach 1)
                'walk_at' => 0.85, 'pride_shift' => 0.08, 'avoidant_shift' => 0.05, 'fear_hold' => 0.12,
                'walk_min' => 0.5, 'walk_max' => 0.98,
                // the share of the pressure the NPC keeps after acting on it (they left; if nothing changes it builds again)
                'after_walk' => 0.6,
                // said from this pressure on
                'felt_from' => 0.4, 'felt_firm' => 0.7,
            ],

            // --- codependent ---
            'codependent' => [
                'bond' => ['from' => 10.0, 'full' => 50.0],
                // the degree acts through a smoothstep from -> full (a middling NPC is not a little codependent in every sentence)
                'lean' => ['from' => 0.15, 'full' => 0.65],
                // negative trust of an exchange lands at max(trust_floor, 1 - trust_sticky x degree) of itself
                'trust_sticky' => 0.7, 'trust_floor' => 0.3,
                // autonomy's compliant / resistant / refusing lines lift by this many score points x degree
                'autonomy_lift' => 12.0,
                'felt_from' => 0.5,
            ],

            // --- adrift (no floors) ---
            'adrift' => [
                // intensity = passion / passion_full (0..1); target = degree x (base + (1 - base) x intensity)
                'passion_full' => 60.0, 'base' => 0.25,
                'rates' => ['rise_per_game_hour' => 0.02, 'fall_per_game_hour' => 0.03, 'max_step_game_hours' => 72.0],
                // the parasite overlay switches on when the pressure reaches `on` and off when the corner (no floors, no trust: the degree
                // without the intensity) falls below `off` (hysteresis); parasite false: the pressure is said, nothing is set
                'parasite' => true, 'on' => 0.7, 'off' => 0.4,
            ],

            // --- growth: positive treatment lifts maturity, toward the floors switching on ---
            // A positive exchange of at least min_significance asks per_item x significance x (1 - maturity strength) points of
            // maturity through applyDelta (its own physics apply: plasticity, and the pull toward the NPC's baseline that makes a
            // low maturity gain about 2-3 times what was asked; the fearful region's maturity floor of 30 holds), at most daily_cap
            // points asked per game day. About a fortnight of daily warmth takes an immature NPC across the line.
            'growth' => ['per_item' => 0.3, 'min_significance' => 0.3, 'daily_cap' => 1.2, 'enabled' => true],

            // --- felt text (feelings, never numbers; {NAME} the NPC, {PLAYER} the player, no pronoun of their own) ---
            'felt_text' => [
                'deserve_building' => "{NAME} has begun to wonder whether {NAME} deserves more than this, and weighs it quietly; the answer is not yet decided.",
                'deserve_firm' => "{NAME} has all but decided: {NAME} deserves better than how little {PLAYER} can be trusted, and is waiting to see whether anything changes before saying so.",
                'codependent' => "{NAME} leans on {PLAYER} for how to feel about nearly everything, and takes {PLAYER}'s word over {NAME}'s own judgement; saying no to {PLAYER} does not come easily.",
                'adrift' => "{NAME} feels everything at full volume and trusts nothing underneath it; what {NAME} calls closeness is the rush, and it burns off fast.",
                'parasite' => "{NAME} stays close for what {PLAYER} can give, in attention, in company, in things, and sees little of what it costs {PLAYER}; any warmth shown is a lever.",
            ],
            'walkaway_text' => "{NAME} has weighed it and decided {NAME} deserves better than this. {NAME} has left calmly, without a scene, and cannot be talked round by pleading or pressed back with demands.",
            'salience' => ['deserve_building' => 0.55, 'deserve_firm' => 0.8, 'codependent' => 0.5, 'adrift' => 0.55, 'parasite' => 0.7],
        ];
    }

    private const MERGED_TABLES = ['maturity', 'gate', 'trust', 'growth', 'felt_text', 'salience'];

    /** The dark path settings: stored config per setting, nested tables merged per entry. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('dark_path');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (self::MERGED_TABLES as $t) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
        }
        foreach (['deserve' => ['bond', 'types', 'rates', 'fall'], 'codependent' => ['bond', 'lean'], 'adrift' => ['rates']] as $t => $subs) {
            $cfg[$t] = array_replace($defaults[$t], is_array($stored[$t] ?? null) ? $stored[$t] : []);
            foreach ($subs as $s) {
                $cfg[$t][$s] = array_replace((array) $defaults[$t][$s], is_array($stored[$t][$s] ?? null) ? $stored[$t][$s] : []);
            }
        }
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function hour(): float
    {
        return RelationshipDynamics::GAMETS_PER_DAY / 24.0;
    }

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /** $v between $from (0) and $full (1). */
    private static function between(float $v, float $from, float $full): float
    {
        return $full > $from ? self::clamp01(($v - $from) / ($full - $from)) : ($v >= $full ? 1.0 : 0.0);
    }

    /** 1 / (1 + e^-((x - centre) / width)): 0.5 at the centre, never 0 or 1. */
    public static function logistic(float $x, float $centre, float $width): float
    {
        $z = ($x - $centre) / max(1e-6, $width);
        return 1.0 / (1.0 + exp(-max(-60.0, min(60.0, $z))));
    }

    private static function dim(array $dynamics, string $dim, float $default): float
    {
        $x = $dynamics['dimensions'][$dim]['x'] ?? null;
        return is_numeric($x) ? floatval($x) : $default;
    }

    // =====================================================================
    // THE TWO CURVES (pure)
    // =====================================================================

    /**
     * How much of a floor the NPC has (0..1): the maturity curve, 0.5 at maturity 40. With the dark path off, the old switch
     * (1 above maturity 40, else 0).
     */
    public static function floorStrength(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $m = self::dim($dynamics, 'maturity', 50.0);
        if (empty($cfg['enabled'])) return $m > floatval(RelationshipDynamics::MATURITY_FLOOR_THRESHOLD) ? 1.0 : 0.0;
        $c = (array) $cfg['maturity'];
        return self::logistic($m, floatval($c['centre']), floatval($c['width']));
    }

    /** Is the floor gone altogether (the old "immature: no floors")? */
    public static function floorOff(float $strength, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        return $strength < floatval(((array) $cfg['gate'])['off_below']);
    }

    /**
     * The value a floor's gate dimension must reach to hold at floor strength $s: gate_line + (1 - s')^curve x (100 - gate_line),
     * s' = s / gate.full_at. At full strength the old line, exactly.
     */
    public static function gateRequired(float $strength, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $line = floatval(RelationshipDynamics::TIER_GATE_THRESHOLD);
        if (empty($cfg['enabled'])) return $line;
        $g = (array) $cfg['gate'];
        $curve = max(0.1, floatval($g['curve']));
        $s = self::clamp01($strength / max(0.05, floatval($g['full_at'] ?? 1.0)));
        return round($line + pow(1.0 - $s, $curve) * (100.0 - $line), 4);
    }

    /** The trust the NPC counts as low below: their own baseline + baseline_shift, within line_min..gate_line (trust points). */
    public static function trustLine(array $dynamics, ?array $cfg = null): float
    {
        $t = (array) (($cfg ?? self::config())['trust']);
        $base = $dynamics['dimensions']['trust']['baseline'] ?? null;
        if (!is_numeric($base)) {
            $temperament = $dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? null;
            $base = RelationshipDynamics::getTemperamentBaseline($temperament, 'trust', $dynamics);
        }
        return max(floatval($t['line_min']), min(floatval($t['gate_line']), floatval($base) + floatval($t['baseline_shift'])));
    }

    /** The NPC's trust in the player, without what the fear of losing them holds down (that strain is not distrust). */
    public static function ownTrust(array $dynamics): float
    {
        $x = self::dim($dynamics, 'trust', 50.0);
        $held = min(0.0, floatval($dynamics[RelDynKeeping::KEY]['applied']['trust'] ?? 0.0));
        return max(0.0, min(100.0, $x - $held));
    }

    /** How high the NPC's trust sits against their own line (0..1, logistic). */
    public static function trustSense(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        return self::logistic(self::ownTrust($dynamics), self::trustLine($dynamics, $cfg), floatval(((array) $cfg['trust'])['width']));
    }

    // =====================================================================
    // THE FOUR CORNERS (pure)
    // =====================================================================

    /**
     * The corners as degrees (they sum to 1): maturity weight m and trust sense t (both 0..1), healthy = m t, deserve =
     * m (1 - t), codependent = (1 - m) t, adrift = (1 - m)(1 - t); 'dominant' the largest.
     *
     * @return array{m: float, t: float, healthy: float, deserve: float, codependent: float, adrift: float, dominant: string}
     */
    public static function corners(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $m = self::floorStrength($dynamics, $cfg);
        $t = self::trustSense($dynamics, $cfg);
        $c = [self::HEALTHY => $m * $t, self::DESERVE => $m * (1.0 - $t), self::CODEPENDENT => (1.0 - $m) * $t, self::ADRIFT => (1.0 - $m) * (1.0 - $t)];
        $dominant = self::HEALTHY;
        foreach ($c as $k => $v) {
            if ($v > $c[$dominant] + 1e-12) $dominant = $k;
        }
        return ['m' => round($m, 6), 't' => round($t, 6)] + array_map(fn($v) => round($v, 6), $c) + ['dominant' => $dominant];
    }

    /** What this kind of bond expects of the other (getRelationshipType), 0..1; 'other' for a kind not listed. */
    public static function typeWeight(array $dynamics, ?array $cfg = null): float
    {
        $table = (array) ((($cfg ?? self::config())['deserve'])['types']);
        $type = (string) RelationshipDynamics::getRelationshipType('', $dynamics);
        return self::clamp01(floatval(array_key_exists($type, $table) ? $table[$type] : ($table['other'] ?? 0.6)));
    }

    private static function bondDepth(array $dynamics, array $range): float
    {
        return self::between(RelationshipDynamics::getCoreAffinity($dynamics), floatval($range['from']), floatval($range['full']));
    }

    /** How far the NPC's trust has fallen from the highest it reached (trust points; 0 for a state with no peak yet). */
    public static function trustFall(array $dynamics): float
    {
        $peak = $dynamics[self::KEY]['trust_peak'] ?? null;
        return is_numeric($peak) ? max(0.0, floatval($peak) - self::ownTrust($dynamics)) : 0.0;
    }

    /**
     * The "I deserve better" degree the pressure follows (0..1): maturity x low trust x the fall (trust must have fallen from where it
     * was) x bond depth x what the bond expects.
     */
    public static function deserveDegree(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return 0.0;
        $c = self::corners($dynamics, $cfg);
        $d = (array) $cfg['deserve'];
        $f = (array) $d['fall'];
        $fall = RelDynTraits::smoothstep(self::trustFall($dynamics), floatval($f['from']), floatval($f['full']));
        return round(self::clamp01($c[self::DESERVE] * $fall * self::bondDepth($dynamics, (array) $d['bond']) * self::typeWeight($dynamics, $cfg)), 4);
    }

    /** The codependent degree (0..1): (1 - maturity) x high trust x bond depth. */
    public static function codependence(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return 0.0;
        $c = self::corners($dynamics, $cfg);
        return round(self::clamp01($c[self::CODEPENDENT] * self::bondDepth($dynamics, (array) $cfg['codependent']['bond'])), 4);
    }

    /** How far the codependent lean acts (0..1): the degree through a smoothstep (codependent.lean), so a middling NPC is not a little codependent everywhere. */
    public static function lean(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return 0.0;
        $l = (array) $cfg['codependent']['lean'];
        return round(RelDynTraits::smoothstep(self::codependence($dynamics, $cfg), floatval($l['from']), floatval($l['full'])), 4);
    }

    /** The adrift degree (0..1): (1 - maturity) x low trust, fed by intensity (passion). */
    public static function adriftDegree(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return 0.0;
        $a = (array) $cfg['adrift'];
        $c = self::corners($dynamics, $cfg);
        $intensity = self::clamp01(floatval(RelationshipDynamics::getPassion($dynamics)) / max(1.0, floatval($a['passion_full'])));
        $base = self::clamp01(floatval($a['base']));
        return round(self::clamp01($c[self::ADRIFT] * ($base + (1.0 - $base) * $intensity)), 4);
    }

    /** The pressure after $hours game hours toward $target: up / down at the table's rates, one step at most max_step_game_hours. */
    public static function approach(float $pressure, float $target, float $hours, array $rates): float
    {
        $hours = max(0.0, min($hours, floatval($rates['max_step_game_hours'])));
        $rate = $target > $pressure ? floatval($rates['rise_per_game_hour']) : floatval($rates['fall_per_game_hour']);
        return self::clamp01($pressure + ($target - $pressure) * (1.0 - pow(1.0 - self::clamp01($rate), $hours)));
    }

    /**
     * The pressure at which this NPC leaves: walk_at less pride (egocentric Pd: they expect better) and avoidance (they leave
     * sooner), more the fear of losing the player (the pull to stay), within walk_min..walk_max. Pure.
     */
    public static function walkAt(array $dynamics, ?array $cfg = null): float
    {
        $d = (array) (($cfg ?? self::config())['deserve']);
        $at = floatval($d['walk_at']);
        $x = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        if (is_array($x) && is_numeric($x['Pd'] ?? null)) {
            $at -= floatval($d['pride_shift']) * RelDynTraits::egocentric(floatval($x['Pd']));
        }
        $axes = RelationshipDynamics::getAttachmentAxes($dynamics);
        $at -= floatval($d['avoidant_shift']) * self::clamp01(floatval($axes['avoidance'] ?? 0.0));
        $at += floatval($d['fear_hold']) * self::clamp01(RelDynKeeping::fear($dynamics));
        return round(max(floatval($d['walk_min']), min(floatval($d['walk_max']), $at)), 4);
    }

    // =====================================================================
    // STATE
    // =====================================================================

    /**
     * $dynamics['_dark']: v, deserve (pressure 0..1), adrift (pressure 0..1), trust_peak (the highest trust reached), trust_low (trust
     * fell below the NPC's line from that peak), parasite (the overlay is held by this path),
     * gamets (the last advance, raw), walks (walkaways started on it), growth {day, pts}, last (the numbers of the last
     * advance, for Jev).
     */
    private static function &state(array &$dynamics): array
    {
        if (!is_array($dynamics[self::KEY] ?? null) || intval($dynamics[self::KEY]['v'] ?? 0) !== self::VERSION) {
            $dynamics[self::KEY] = ['v' => self::VERSION, 'deserve' => 0.0, 'adrift' => 0.0, 'walks' => 0];
        }
        return $dynamics[self::KEY];
    }

    public static function deservePressure(array $dynamics): float
    {
        return self::enabled() ? floatval($dynamics[self::KEY]['deserve'] ?? 0.0) : 0.0;
    }

    public static function adriftPressure(array $dynamics): float
    {
        return self::enabled() ? floatval($dynamics[self::KEY]['adrift'] ?? 0.0) : 0.0;
    }

    /** Does this path hold the parasite overlay (the gift ledger's recovery must not lift it)? */
    public static function holdsParasite(array $dynamics): bool
    {
        return !empty($dynamics[self::KEY]['parasite']) && ($dynamics['_relationship_type_override'] ?? null) === 'parasite';
    }

    /** Forget the state (the editor's reset): an overlay this path set is lifted, the pressures and the growth ledger are gone. */
    public static function reset(array &$dynamics): void
    {
        if (self::holdsParasite($dynamics)) self::releaseParasite('', $dynamics);
        unset($dynamics[self::KEY]);
    }

    // =====================================================================
    // ADVANCE (the prerequest hook)
    // =====================================================================

    /**
     * One step to $now (raw gamets): both pressures follow their degrees, the parasite overlay follows the adrift pressure
     * (switched on at adrift.on, off below adrift.off). Disabled: the state is forgotten and an overlay this path set is
     * lifted. Returns ['deserve', 'adrift', 'walk_at', 'parasite' => ?bool].
     */
    public static function advance(string $npcName, array &$dynamics, float $now): array
    {
        $out = ['deserve' => 0.0, 'adrift' => 0.0, 'walk_at' => null, 'parasite' => null];
        $cfg = self::config();
        if (empty($cfg['enabled'])) {
            if (is_array($dynamics[self::KEY] ?? null)) {
                if (self::holdsParasite($dynamics)) self::releaseParasite($npcName, $dynamics);
                unset($dynamics[self::KEY]);
            }
            return $out;
        }
        if ($now <= 0) return $out;
        $state = &self::state($dynamics);
        // the highest trust reached, kept until trust is back at the NPC's line after a fall (a clean slate then)
        $trust = self::ownTrust($dynamics);
        $line = self::trustLine($dynamics, $cfg);
        $peak = is_numeric($state['trust_peak'] ?? null) ? floatval($state['trust_peak']) : $trust;
        if (!empty($state['trust_low']) && $trust >= $line) {
            $peak = $trust;
            unset($state['trust_low']);
        } elseif ($trust < $line && $peak - $trust >= floatval($cfg['deserve']['fall']['from'])) {
            $state['trust_low'] = true;
        }
        $state['trust_peak'] = round(max($peak, $trust), 4);
        unset($state);
        $state = &self::state($dynamics);
        $last = floatval($state['gamets'] ?? 0);
        $hours = $last > 0 ? max(0.0, ($now - $last) / self::hour()) : 0.0;
        $deserveTarget = self::deserveDegree($dynamics, $cfg);
        $adriftTarget = self::adriftDegree($dynamics, $cfg);
        $d0 = floatval($state['deserve'] ?? 0.0);
        $a0 = floatval($state['adrift'] ?? 0.0);
        // first sight of the NPC: the state of the world is what it is
        $d = $last > 0 ? self::approach($d0, $deserveTarget, $hours, (array) $cfg['deserve']['rates']) : $deserveTarget;
        $a = $last > 0 ? self::approach($a0, $adriftTarget, $hours, (array) $cfg['adrift']['rates']) : $adriftTarget;
        $state['deserve'] = round($d, 4);
        $state['adrift'] = round($a, 4);
        $state['gamets'] = $now;
        $state['last'] = ['corners' => self::corners($dynamics, $cfg), 'deserve_target' => $deserveTarget, 'adrift_target' => $adriftTarget,
                          'codependent' => self::codependence($dynamics, $cfg)];
        unset($state);

        $out['deserve'] = round($d, 4);
        $out['adrift'] = round($a, 4);
        $out['walk_at'] = self::walkAt($dynamics, $cfg);
        if (!empty($cfg['adrift']['parasite'])) $out['parasite'] = self::stepParasite($npcName, $dynamics, $a, $cfg);
        if (($d >= floatval($cfg['deserve']['felt_from'])) !== ($d0 >= floatval($cfg['deserve']['felt_from']))) {
            RelationshipDynamics::log("[DARK] {$npcName}: the thought that they deserve better is " . ($d >= floatval($cfg['deserve']['felt_from']) ? 'building' : 'gone')
                . ' (pressure ' . round($d, 3) . ", target {$deserveTarget}, walks at {$out['walk_at']})");
        }
        return $out;
    }

    /** Switch the parasite overlay on / off with the adrift pressure (hysteresis). Returns whether this path holds it now. */
    private static function stepParasite(string $npcName, array &$dynamics, float $adrift, array $cfg): bool
    {
        $a = (array) $cfg['adrift'];
        $held = self::holdsParasite($dynamics);
        $override = $dynamics['_relationship_type_override'] ?? null;
        if (!$held && $override === null && $adrift >= floatval($a['on'])) {
            $dynamics['_relationship_type_history'][] = [
                'from' => RelationshipDynamics::getRelationshipType($npcName, $dynamics), 'from_override' => $override, 'to' => 'parasite',
                'at' => RelationshipDynamics::interactionClock($dynamics),
                'reason' => 'dark_path adrift=' . round($adrift, 2),
            ];
            $dynamics['_relationship_type_override'] = 'parasite';
            $dynamics[self::KEY]['parasite'] = true;
            RelationshipDynamics::log("[DARK] {$npcName}: no floors and no trust, held: the bond turns transactional (parasite), adrift " . round($adrift, 3));
            return true;
        }
        // held by the corner itself (no floors, no trust), not by the intensity that started it: a passion the overlay burned off does not
        // lift it; maturity or trust coming back does
        if ($held && floatval(self::corners($dynamics, $cfg)[self::ADRIFT]) < floatval($a['off'])) {
            self::releaseParasite($npcName, $dynamics);
            return false;
        }
        if (!$held && !empty($dynamics[self::KEY]['parasite'])) {
            unset($dynamics[self::KEY]['parasite']);   // somebody else cleared the overlay: forget that it was ours
        }
        return $held;
    }

    private static function releaseParasite(string $npcName, array &$dynamics): void
    {
        $history = $dynamics['_relationship_type_history'] ?? [];
        $lastEntry = !empty($history) ? end($history) : null;
        $previous = ($lastEntry && ($lastEntry['reason'] ?? '') !== '' && str_starts_with((string) $lastEntry['reason'], 'dark_path') && isset($lastEntry['from_override']))
            ? $lastEntry['from_override'] : null;
        $dynamics['_relationship_type_override'] = ($previous !== 'parasite') ? $previous : null;
        $dynamics['_relationship_type_history'][] = ['from' => 'parasite', 'to' => 'restored', 'at' => RelationshipDynamics::interactionClock($dynamics),
                                                     'reason' => 'dark_path_released'];
        unset($dynamics[self::KEY]['parasite']);
        RelationshipDynamics::log("[DARK] {$npcName}: the parasite overlay lifts (the pressure that held it eased)");
    }

    // =====================================================================
    // I DESERVE BETTER: the walkaway
    // =====================================================================

    /**
     * Is an "I deserve better" walkaway due: the pressure at the NPC's own line, in a bond that existed (the context-tier high
     * water mark reached deserve.min_tier). Pure (reads the stored pressure).
     */
    public static function walkawayDue(array $dynamics, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return false;
        if (intval($dynamics['context_tier_hwm'] ?? 0) < intval($cfg['deserve']['min_tier'])) return false;
        return floatval($dynamics[self::KEY]['deserve'] ?? 0.0) >= self::walkAt($dynamics, $cfg);
    }

    /** The NPC acted on it (initiateWalkaway with the reason 'deserve'): they keep after_walk of the pressure. */
    public static function onWalkaway(string $npcName, array &$dynamics): void
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return;
        $state = &self::state($dynamics);
        $before = floatval($state['deserve'] ?? 0.0);
        $state['deserve'] = round($before * self::clamp01(floatval($cfg['deserve']['after_walk'])), 4);
        $state['walks'] = intval($state['walks'] ?? 0) + 1;
        unset($state);
        RelationshipDynamics::log("[DARK] {$npcName}: leaves, deserving better (pressure " . round($before, 3) . ' -> ' . $dynamics[self::KEY]['deserve'] . ')');
    }

    /** The context line for a walkaway whose reason is 'deserve' (feelings, the NPC's name, no pronoun, no digits). */
    public static function walkawayText(string $npcName): string
    {
        return strtr((string) self::config()['walkaway_text'], ['{NAME}' => $npcName]);
    }

    // =====================================================================
    // CODEPENDENT
    // =====================================================================

    /**
     * The share of a hit to trust that lands on this NPC (1 for most; max(trust_floor, 1 - trust_sticky x lean) for a codependent one:
     * trusts blindly, but can still be hurt). Pure.
     */
    public static function trustLanding(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return 1.0;
        $c = (array) $cfg['codependent'];
        return min(1.0, max(self::clamp01(floatval($c['trust_floor'])), 1.0 - floatval($c['trust_sticky']) * self::lean($dynamics, $cfg)));
    }

    /** An exchange's trust signal ($raw, raw eval points) as this NPC lets it land: a negative one at trustLanding() of itself; a positive one as it is. */
    public static function trustSignal(array $dynamics, float $raw, ?array $cfg = null): float
    {
        return $raw >= 0.0 ? $raw : $raw * self::trustLanding($dynamics, $cfg);
    }

    /** Score points autonomy's compliant / resistant / refusing lines lift by (0 .. autonomy_lift). */
    public static function autonomyLift(array $dynamics, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        if (empty($cfg['enabled'])) return 0.0;
        return round(floatval($cfg['codependent']['autonomy_lift']) * self::lean($dynamics, $cfg), 4);
    }

    // =====================================================================
    // GROWTH (the dark path limits itself)
    // =====================================================================

    /**
     * A positive exchange lifts maturity a little, where floors are not yet on: per_item x significance x (1 - floor strength)
     * points through applyDelta (plasticity applies), at most daily_cap points per game day. Returns the points applied.
     */
    public static function onEvalItem(string $npcName, array $n, array &$dynamics, float $gamets): float
    {
        $cfg = self::config();
        $g = (array) $cfg['growth'];
        if (empty($cfg['enabled']) || empty($g['enabled']) || empty($n['positive_interaction'])) return 0.0;
        $sig = floatval($n['significance'] ?? 0.0);
        if ($sig < floatval($g['min_significance'])) return 0.0;
        $weight = 1.0 - self::floorStrength($dynamics, $cfg);
        $raw = floatval($g['per_item']) * $sig * $weight;
        if ($raw < 0.005) return 0.0;
        $day = $gamets > 0 ? (int) floor($gamets / RelationshipDynamics::GAMETS_PER_DAY) : 0;
        $state = &self::state($dynamics);
        $grown = is_array($state['growth'] ?? null) ? $state['growth'] : ['day' => $day, 'pts' => 0.0];
        if (intval($grown['day'] ?? -1) !== $day) $grown = ['day' => $day, 'pts' => 0.0];
        $room = max(0.0, floatval($g['daily_cap']) - floatval($grown['pts']));
        $raw = min($raw, $room);
        if ($raw < 0.005) {
            $state['growth'] = $grown;
            unset($state);
            return 0.0;
        }
        unset($state);
        $applied = RelationshipDynamics::applyDelta('maturity', $dynamics, $raw, $dynamics['inferred_temperament'] ?? null);
        $state = &self::state($dynamics);
        $grown['pts'] = round(floatval($grown['pts']) + $raw, 4);
        $state['growth'] = $grown;
        unset($state);
        if (abs($applied) > 0.0) {
            RelationshipDynamics::log(sprintf('[DARK] %s: being treated well lifts maturity by %+.3f (asked %.3f, floor strength %.2f)', $npcName, $applied, $raw, 1.0 - $weight));
        }
        return $applied;
    }

    // =====================================================================
    // FELT TEXT
    // =====================================================================

    /**
     * The standing lines of the dark path: [['key', 'text', 'salience'], ...]. The thought of deserving better (building, firm), the
     * codependent lean, the adrift state (the parasite's own line replaces it while the overlay is held). $tier: the context tier;
     * nothing below tier 1. Feelings and what the NPC does, no digits, no pronoun of their own.
     */
    public static function feltLines(array $dynamics, string $npc, string $player, int $tier = 2): array
    {
        $cfg = self::config();
        if (empty($cfg['enabled']) || $tier < 1) return [];
        $t = (array) $cfg['felt_text'];
        $sal = (array) $cfg['salience'];
        $vars = ['{NAME}' => $npc, '{PLAYER}' => $player];
        $lines = [];
        $d = floatval($dynamics[self::KEY]['deserve'] ?? 0.0);
        $dc = (array) $cfg['deserve'];
        if ($d >= floatval($dc['felt_from']) && $tier >= 2 && ($dynamics['_walkaway_state'] ?? 'normal') === 'normal') {
            $key = $d >= floatval($dc['felt_firm']) ? 'deserve_firm' : 'deserve_building';
            $lines[] = ['key' => $key, 'text' => strtr((string) $t[$key], $vars), 'salience' => round(min(1.0, floatval($sal[$key]) + 0.1 * $d), 3)];
        }
        if (self::codependence($dynamics, $cfg) >= floatval($cfg['codependent']['felt_from'])) {
            $lines[] = ['key' => 'codependent', 'text' => strtr((string) $t['codependent'], $vars), 'salience' => floatval($sal['codependent'])];
        }
        if (self::holdsParasite($dynamics)) {
            $lines[] = ['key' => 'parasite', 'text' => strtr((string) $t['parasite'], $vars), 'salience' => floatval($sal['parasite'])];
        } elseif (floatval($dynamics[self::KEY]['adrift'] ?? 0.0) >= floatval($cfg['adrift']['off'])) {
            $lines[] = ['key' => 'adrift', 'text' => strtr((string) $t['adrift'], $vars), 'salience' => floatval($sal['adrift'])];
        }
        return $lines;
    }

    // =====================================================================
    // JEV
    // =====================================================================

    /** Numbers for Jev: the corners, the pressures, where the NPC walks, the codependent degree, the parasite hold. */
    public static function jev(array $dynamics): array
    {
        $cfg = self::config();
        $s = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        $c = self::corners($dynamics, $cfg);
        return [
            'enabled' => !empty($cfg['enabled']),
            'floor_strength' => $c['m'], 'trust_sense' => $c['t'], 'trust_line' => round(self::trustLine($dynamics, $cfg), 1),
            'gate_required' => self::gateRequired($c['m'], $cfg),
            'corners' => ['healthy' => $c[self::HEALTHY], 'deserve' => $c[self::DESERVE], 'codependent' => $c[self::CODEPENDENT], 'adrift' => $c[self::ADRIFT]],
            'dominant' => $c['dominant'],
            'deserve' => round(floatval($s['deserve'] ?? 0.0), 3), 'walk_at' => self::walkAt($dynamics, $cfg), 'trust_fall' => round(self::trustFall($dynamics), 2),
            'adrift' => round(floatval($s['adrift'] ?? 0.0), 3), 'codependent' => self::codependence($dynamics, $cfg),
            'parasite_held' => self::holdsParasite($dynamics), 'walks' => intval($s['walks'] ?? 0),
        ];
    }
}
