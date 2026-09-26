<?php
/**
 * Relationship Dynamics — tiered governors (MDD §8 "The Handshake", pipeline Addendum 8;
 * roadmap tiered-governors).
 *
 * "RelDyn defers to CHIM relationship tiers as constraints": the relationship tier is the
 * gearbox, passion the engine (Integration Philosophy). Each tier sets a passion floor and a
 * ceiling (MDD 8.1, passion points):
 *   Unknown / Acquaintance   0 / 20    (romantic gestures there meet the Ick, MDD 6.3)
 *   Friendly / Platonic      5 / 40    (the "Friendzone limit")
 *   Crush / Uncommitted     10 / 80
 *   Committed / Engaged     20 / 100
 *   Divorced / Hostile       0 / 0
 * The tier is read from CHIM core's own data (tier()): core's relationship type when it is a
 * romance the attraction allows (crush, admirer, obsessed -> crush; romantic, which core's
 * aliases marriage / married / lover map to -> committed) or an ended one (ex -> hostile), a
 * hostile RelDyn bond type (core enemy / nemesis / betrayed / contempt), otherwise the depth of
 * core affinity capped at the attraction's tier ceiling (hostile tier -> hostile; stranger,
 * acquaintance -> distant; friend and above -> friendly).
 *
 * The caps are a skeleton each NPC modulates (MDD 8.2): an NPC the player attracts (the
 * Attraction Matrix judged it: every passion pillar at its bar, a balanced NPC at the bonded
 * tier, or won over) whose intimacy gate is in raise_gates reads the ceiling of the next rung
 * (Aela: "Beauty + Strength pass -> passion ceiling at Acquaintance raised, e.g. 40 instead of
 * 20"; the engine revs, the gear does not shift). A bond-gated NPC keeps the table as it is
 * (Ashe: "the base table is accurate as-is ... passion stays low until deep trust is earned").
 * A lower ceiling for a pillar she does not pass is the MDD 1.4 cut (RelDynAttraction::gainFactor).
 * Decisions §13 retired the MDD 6.2 friendzone cap in favour of the uphill, not the MDD 8.1 row:
 * by default an unattracted acquaintance stops at the row's 20 (the spark), so she cannot climb
 * to the §15 won-over line (40) and open romance before the bond is a friendship; MDD 8.2 raises
 * it to 40 only for an NPC the Matrix finds attracted. spark_supersedes (off by default; Ken's
 * call, batch-Q review) reads a base ceiling at or below the spark as the next rung's for
 * everyone instead: the unattracted climb the steep hill to 40, the attracted run at their rate.
 * Which pillars move the tier itself is the Matrix's depth ceiling (MDD 8.3, the decoupling
 * principle: passion and tier are gated by different pillars).
 *
 * The ceiling bounds GAINS (factor): a gain never lifts passion past it; passion already above
 * it (the bond fell a tier) is not cut, it decays as it would. The floor is where decay stops
 * once passion has reached it (RelationshipDynamics::passionStageFloor, through the attraction
 * like every floor): it never lifts passion below it (MDD 8.3: a political marriage is committed
 * and loveless) and is never above the ceiling. RelDyn's parasite overlay reads as distant. Passion writers in exempt_sources are not bounded (the hoover: the toxic
 * NPC's own snap, "Hoover protocols active" in the Divorced / Hostile row).
 *
 * Units: floors and ceilings in passion points (0..passion_max); core affinity -100..100 read
 * only through RelationshipDynamics::getCoreAffinity. Config key 'governors' (configDefaults).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynGovernors
{
    /** Governor tiers, the ladder a raise climbs (hostile is off it). */
    const LADDER = ['distant', 'friendly', 'crush', 'committed'];
    const HOSTILE = 'hostile';

    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // MDD 8.1 base tier caps (passion points)
            'tiers' => [
                'distant'   => ['floor' => 0.0,  'ceiling' => 20.0],    // Unknown / Acquaintance
                'friendly'  => ['floor' => 5.0,  'ceiling' => 40.0],    // Friendly / Platonic
                'crush'     => ['floor' => 10.0, 'ceiling' => 80.0],    // Crush / Uncommitted
                'committed' => ['floor' => 20.0, 'ceiling' => 100.0],   // Committed / Engaged
                'hostile'   => ['floor' => 0.0,  'ceiling' => 0.0],     // Divorced / Hostile
            ],
            // Core relationships.Player.type -> governor tier (core's romance types, and 'ex' =
            // divorced). A romance type the attraction blocks falls back to the depth.
            'core_types' => ['crush' => 'crush', 'admirer' => 'crush', 'obsessed' => 'crush',
                             'romantic' => 'committed', 'ex' => 'hostile'],
            // RelDyn bond types (getRelationshipType) that are the hostile row
            'hostile_types' => ['hostile'],
            // RelDyn's own overlays (getRelationshipType) read as a tier whatever core's type says:
            // a parasite (MDD 6.2, the player as a wallet) is no partner's bond, its floor included
            'overlay_types' => ['parasite' => 'distant'],
            // RelDyn depth tier (core affinity, capped at the attraction ceiling) -> governor tier
            'depth' => ['hostile' => 'hostile', 'stranger' => 'distant', 'acquaintance' => 'distant',
                        'friend' => 'friendly', 'close_friend' => 'friendly', 'bonded' => 'friendly', 'devoted' => 'friendly'],
            // MDD 8.2: intimacy gates (RelDynAttraction::GATES) whose NPCs, attracted, read the
            // ceiling raise_steps rungs up the ladder. 'bond' (Ashe, demisexual, mages, healers,
            // nobles) keeps the base table
            'raise_gates' => ['visceral', 'balanced'],
            'raise_steps' => 1,
            // Off (default): the MDD 8.1 row as written, a 20 for the unattracted at Unknown /
            // Acquaintance (decisions §13 retired only the MDD 6.2 friendzone cap). On: a base
            // ceiling at or below the attraction spark (attraction.curve.spark, 20) reads as the
            // next rung's ceiling for everyone (module doc)
            'spark_supersedes' => false,
            // Passion writers (gainPassion / attractionPassionFactor sources) the ceiling does not bound
            'exempt_sources' => ['hoover'],
        ];
    }

    public static function config(): array
    {
        $stored = RelationshipDynamics::configValue('governors');
        return is_array($stored) ? array_replace(self::configDefaults(), $stored) : self::configDefaults();
    }

    public static function enabled(?array $cfg = null): bool
    {
        return !empty(($cfg ?? self::config())['enabled']);
    }

    /**
     * The governor tier of the bond (module doc), from core's type, the RelDyn bond type and
     * core affinity capped at the attraction's tier ceiling. Pure (reads $dynamics).
     */
    public static function tier(array $dynamics, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $core = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        $blocked = array_map('strtolower', (array) ($dynamics['_attraction']['blocked_types'] ?? []));
        $byCore = ((array) ($cfg['core_types'] ?? []))[$core] ?? null;
        if (is_string($byCore) && $byCore === self::HOSTILE) return self::HOSTILE;
        $type = (string) RelationshipDynamics::getRelationshipType('', $dynamics);
        if (in_array($type, (array) ($cfg['hostile_types'] ?? []), true)) return self::HOSTILE;
        $overlay = ((array) ($cfg['overlay_types'] ?? []))[$type] ?? null;
        if (is_string($overlay) && self::rowOf($overlay, $cfg) !== null) return $overlay;
        if (is_string($byCore) && self::rowOf($byCore, $cfg) !== null && !in_array($core, $blocked, true)) return $byCore;
        $depth = RelationshipDynamics::attractionCappedTier($dynamics);
        $t = ((array) ($cfg['depth'] ?? []))[$depth] ?? 'distant';
        return self::rowOf((string) $t, $cfg) !== null ? (string) $t : 'distant';
    }

    /** A tier's ['floor', 'ceiling'] (passion points), or null for a tier the table lacks. */
    private static function rowOf(string $tier, array $cfg): ?array
    {
        $row = ((array) ($cfg['tiers'] ?? []))[$tier] ?? null;
        if (!is_array($row) || !is_numeric($row['floor'] ?? null) || !is_numeric($row['ceiling'] ?? null)) return null;
        return ['floor' => floatval($row['floor']), 'ceiling' => floatval($row['ceiling'])];
    }

    /**
     * The bond's governor now: ['tier', 'floor', 'ceiling' (both passion points), 'base_ceiling',
     * 'raised' => bool]. The raise (MDD 8.2): the Matrix judged the NPC attracted, with no hard
     * zero, and her gate is in raise_gates. null while the governors are off. Pure.
     */
    public static function governor(array $dynamics, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        if (!self::enabled($cfg)) return null;
        $tier = self::tier($dynamics, $cfg);
        $row = self::rowOf($tier, $cfg) ?? ['floor' => 0.0, 'ceiling' => 20.0];
        $ceiling = $row['ceiling'];
        $raised = false;
        $a = is_array($dynamics['_attraction'] ?? null) ? $dynamics['_attraction'] : null;
        $rung = array_search($tier, self::LADDER, true);
        $spark = floatval(RelDynAttraction::curveConfig()['spark'] ?? 20.0);
        if ($rung !== false && !empty($cfg['spark_supersedes']) && $ceiling <= $spark && $rung + 1 < count(self::LADDER)) {
            $next = self::rowOf(self::LADDER[$rung + 1], $cfg);
            if ($next !== null && $next['ceiling'] > $ceiling) $ceiling = $next['ceiling'];
        }
        if ($rung !== false && $a !== null && !empty($a['enabled']) && !empty($a['attracted']) && empty($a['hard_zero'])
            && in_array((string) ($a['gate'] ?? ''), (array) ($cfg['raise_gates'] ?? []), true)) {
            $to = self::LADDER[min(count(self::LADDER) - 1, $rung + max(0, intval($cfg['raise_steps'] ?? 1)))];
            $up = self::rowOf($to, $cfg);
            if ($up !== null && $up['ceiling'] > $row['ceiling']) {
                $ceiling = max($ceiling, $up['ceiling']);
                $raised = true;
            }
        }
        $max = floatval(RelationshipDynamics::getConfig()['passion_max'] ?? 100.0);
        $ceiling = min($max, $ceiling);
        return ['tier' => $tier, 'floor' => min($row['floor'], $ceiling), 'ceiling' => $ceiling,
                'base_ceiling' => min($max, $row['ceiling']), 'raised' => $raised];
    }

    /**
     * The governor's factor (0..1, unitless) for a passion GAIN of $raw points at passion
     * $passion from $source: the share of the gain that fits under the ceiling. 1.0 for no gain,
     * the governors off or an exempt source.
     */
    public static function gainFactor(array $dynamics, float $passion, float $raw, ?string $source = null, ?array $cfg = null): float
    {
        if ($raw <= 0.0) return 1.0;
        $cfg = $cfg ?? self::config();
        if ($source !== null && in_array($source, (array) ($cfg['exempt_sources'] ?? []), true)) return 1.0;
        $g = self::governor($dynamics, $cfg);
        if ($g === null) return 1.0;
        $room = $g['ceiling'] - $passion;
        return $room <= 0.0 ? 0.0 : min(1.0, $room / $raw);
    }

    /** The tier's passion floor (points), 0 while the governors are off. */
    public static function floor(array $dynamics): float
    {
        $g = self::governor($dynamics);
        return $g === null ? 0.0 : $g['floor'];
    }

    /** Jev's governor block (numbers): null while the governors are off. */
    public static function jev(array $dynamics): ?array
    {
        $g = self::governor($dynamics);
        if ($g === null) return null;
        return ['tier' => $g['tier'], 'passion_floor' => round($g['floor'], 2), 'passion_ceiling' => round($g['ceiling'], 2), 'raised' => $g['raised']];
    }
}
