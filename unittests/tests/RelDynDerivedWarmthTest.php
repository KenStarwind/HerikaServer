<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynDerivedWarmthConfigDb
{
    public function __construct(public array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Derived warmth (roadmap derived-warmth; session 2026-03-30, dimension draft Dimension 3):
 * "how emotionally open am I" is no longer a stored dimension the display reads. Per bond,
 * warmth = sqrt(effective passion x effective comfort), both as they read toward the player (the
 * per-bond display multiplier), plus the states held on warmth (a drink, dusk, grief, the shame of
 * resentment_self) and the weather's pull. Progression (session): stranger ~12 (Walled), crush
 * ~55 (Cautious), romantic ~60, bonded ~70 (Comfortable). The bands and keywords stay; warmth is
 * out of the eval map and out of baseline drift; tension checks read it raw.
 * Units: points 0..100.
 */
final class RelDynDerivedWarmthTest extends TestCase
{
    private array $saved = [];
    private RelDynDerivedWarmthConfigDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynDerivedWarmthConfigDb(RelationshipDynamics::defaultConfig());
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** A Guarded NPC with core Player.type $coreType at core affinity $aff, passion floor $p, comfort $c. */
    private function npc(string $coreType, float $aff, float $p, float $c, float $storedWarmth = 90.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['inferred_temperament' => 'Guarded']));
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        RelationshipDynamics::setPassion($d, $p);
        $d['dimensions']['comfort']['x'] = $c;
        $d['dimensions']['warmth']['x'] = $storedWarmth;   // stored warmth is not what she shows any more
        return $d;
    }

    private static function band(?float $w): string
    {
        return (string) RelationshipDynamics::getDimensionBand('warmth', (float) $w)['label'];
    }

    public function testTheProgressionFromStrangerToBonded(): void
    {
        $stranger = $this->npc('neutral', 0, 10, 30);
        $crush = $this->npc('crush', 40, 30, 50);
        $romantic = $this->npc('romantic', 60, 40, 50);
        $bonded = $this->npc('romantic', 80, 40, 60);
        $w = array_map(fn($d) => RelDynPassion::warmth($d, true), compact('stranger', 'crush', 'romantic', 'bonded'));
        $this->assertSame('Walled', self::band($w['stranger']), json_encode($w));
        $this->assertSame('Cautious', self::band($w['crush']), json_encode($w));
        $this->assertSame('Comfortable', self::band($w['romantic']), json_encode($w));
        $this->assertGreaterThan($w['romantic'], $w['bonded']);
        $this->assertEqualsWithDelta(12.0, $w['stranger'], 3.0);
        $this->assertEqualsWithDelta(55.0, $w['crush'], 7.0);
        $this->assertEqualsWithDelta(70.0, $w['bonded'], 5.0);
        // Exactly sqrt(passion x comfort) as they read toward the player; the stored 90 is not read
        $p = RelationshipDynamics::getEffectiveDimensionValue($crush, 'passion');
        $c = RelationshipDynamics::getEffectiveDimensionValue($crush, 'comfort');
        $this->assertEqualsWithDelta(sqrt($p * $c), $w['crush'], 1e-9);
        $this->assertEqualsWithDelta($w['crush'], RelationshipDynamics::getEffectiveDimensionValue($crush, 'warmth'), 1e-9);
        // Raw (no per-bond multiplier): what tension checks read
        $this->assertEqualsWithDelta(sqrt(30.0 * 50.0), RelDynPassion::warmth($crush, false), 1e-9);
    }

    public function testTheMomentAndTheHeldStatesMoveIt(): void
    {
        $d = $this->npc('crush', 40, 30, 50);
        $before = RelDynPassion::warmth($d, false);
        // A spike opens her up while it lasts (effective passion)
        RelDynPassion::storeSpike($d, 18.0, 'touch');
        $this->assertEqualsWithDelta(sqrt(48.0 * 50.0), RelDynPassion::warmth($d, false), 1e-9);
        // Dusk's held warmth (+2), a drink's (+5), resentment_self's shame (-10 on the baseline)
        $d = $this->npc('crush', 40, 30, 50);
        $d['_env_applied_effects'] = ['warmth' => 2.0];
        $d['_active_consumables'] = [['immediate' => ['warmth' => 5.0]]];
        $this->assertEqualsWithDelta($before + 7.0, RelDynPassion::warmth($d, false), 1e-9);
        // A held comfort state moves warmth through comfort, outside the bond's multiplier
        $e = $this->npc('crush', 40, 30, 50);
        $e['_env_applied_effects'] = ['comfort' => -3.0];
        $this->assertEqualsWithDelta(sqrt(30.0 * 50.0), RelDynPassion::warmth($e, false), 1e-9, 'x already carries the held -3');
        // The drift sample leaves the held states out
        $this->assertEqualsWithDelta($before, RelationshipDynamics::driftSampleValue($d, 'warmth'), 1e-9);
        // The felt baseline: at the passion stage floor and comfort's baseline. The early stage's
        // floor is 0, but a crush's tier holds passion at its governor floor once reached (MDD 8.3,
        // RelDynGovernors: crush 10), so she rests a little open
        $floor = RelationshipDynamics::passionStageFloor($d);
        $this->assertEqualsWithDelta(10.0, $floor, 1e-9);
        $cb = floatval($d['dimensions']['comfort']['baseline']);
        $this->assertEqualsWithDelta(sqrt(RelationshipDynamics::getEffectiveDimensionValue($d, 'passion', $floor)
            * RelationshipDynamics::getEffectiveDimensionValue($d, 'comfort', $cb)), RelDynPassion::warmthBaseline($d, true), 1e-9);
        $e = $this->npc('neutral', 0, 30, 50);
        $this->assertEqualsWithDelta(0.0, RelDynPassion::warmthBaseline($e, true), 1e-9, 'a stranger, early: the tier holds nothing');
    }

    public function testStoredWarmthIsOnlyReadWithDerivedWarmthOff(): void
    {
        $d = $this->npc('neutral', 0, 10, 30, 64.0);
        $this->assertNotEqualsWithDelta(64.0, RelDynPassion::warmth($d, false), 1.0);
        $this->db->config['passion_dynamics'] = array_merge(RelDynPassion::configDefaults(), ['derived_warmth_enabled' => false]);
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(64.0, RelDynPassion::warmth($d, false));
        $this->assertEqualsWithDelta(64.0 * sqrt(0.3), RelationshipDynamics::getEffectiveDimensionValue($d, 'warmth'), 1e-9, 'the old per-bond multiplier');
    }

    public function testReadersTakeTheDerivedWarmth(): void
    {
        // Emergent 'longing' (affinity 60+, warmth 50+, passion <= 15) reads derived warmth: a
        // passionless bond is not warm enough to long, whatever the stored value says
        $d = $this->npc('romantic', 80, 10, 90, 95.0);
        $d['dimensions']['affinity']['x'] = 90.0;
        $this->assertLessThan(50.0, RelDynPassion::warmth($d, false));
        $this->assertNotContains('longing', RelationshipDynamics::detectEmergentEmotions($d));
        $d = $this->npc('romantic', 80, 14, 95, 0.0);
        $d['dimensions']['affinity']['x'] = 90.0;
        RelDynPassion::storeSpike($d, 20.0, 'touch');   // the moment warms her; passion (the floor) stays low
        $this->assertGreaterThanOrEqual(50.0, RelDynPassion::warmth($d, false));
        $this->assertContains('longing', RelationshipDynamics::detectEmergentEmotions($d));
        // Jev gets the number
        $this->assertEqualsWithDelta(round(RelDynPassion::warmth($d, false), 2), RelDynJev::state('Tester', $d, 0.0)['warmth'], 1e-9);
    }

    public function testWarmthIsOutOfTheEvalMapAndTheDrift(): void
    {
        $this->assertArrayNotHasKey('warmth_delta', RelationshipDynamics::EVAL_DELTA_MAP);
        $this->assertNotContains('warmth', RelationshipDynamics::BASELINE_DRIFT_DEFAULTS['dimensions']);
        $d = $this->npc('crush', 40, 30, 50, 40.0);
        $applied = RelationshipDynamics::processEvalDeltas('Tester', ['warmth_delta' => 20, 'warmth_reason' => 'x'], $d);
        $this->assertArrayNotHasKey('warmth', $applied);
        $this->assertSame(40.0, floatval($d['dimensions']['warmth']['x']));
        // The Guarded baseline the session raised (15 -> 25) stays for the stored seed
        $this->assertEqualsWithDelta(25.0, RelationshipDynamics::TEMPERAMENT_BASELINES['warmth']['Guarded'], 1e-9);
    }
}
