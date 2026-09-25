<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynDriftConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}

/**
 * Baseline drift (roadmap baseline-drift; audit #57; PR 13; recap 2026-03-31 Fix 5; decisions
 * 2026-09-23 §2 "time does not heal, contact does"): significant bonds change who you are.
 *   - one sample per game-calendar day, taken on contact (the NPC's own requests), never from
 *     time passing alone (a wait or a sleep adds none);
 *   - at the diary eval (a day's evidence counted once): 3 consistent samples (all beyond the tolerance on one side of the
 *     baseline) move the GLOBAL baseline 5% of the way to their average, at most +-20 from
 *     where it started (the seed, or the value an editor / arc last set);
 *   - affinity, trust, comfort, respect, maturity; not warmth. Affinity in core units
 *     (-100..100, as its baseline and physics), never the 0..100 mirror.
 */
final class RelDynBaselineDriftTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** A Guarded NPC (stored trust / comfort / respect / maturity baselines) at core affinity $coreAff. */
    private function npc(float $coreAff = 0.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Guarded',
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $coreAff);
        return $d;
    }

    /** One contact on game day $day at $hour with trust at $trust. */
    private function contact(array &$d, int $day, float $trust, float $hour = 12.0): void
    {
        $d['dimensions']['trust']['x'] = $trust;
        RelationshipDynamics::recordBaselineDriftSample($d, $day * self::DAY + $hour * self::DAY / 24);
    }

    public function testOneSamplePerGameDayOnContactOnly(): void
    {
        $d = $this->npc();
        $this->contact($d, 10, 40.0, 9.0);
        $this->contact($d, 10, 44.0, 20.0);   // same game day: the day's sample is its latest contact
        $this->assertCount(1, $d['_baseline_drift_samples']['trust']);
        $this->assertSame(44.0, $d['_baseline_drift_samples']['trust'][0]['v']);
        $this->assertSame(10, $d['_baseline_drift_samples']['trust'][0]['day']);
        $this->contact($d, 11, 46.0);
        $this->assertCount(2, $d['_baseline_drift_samples']['trust']);
        // no game clock: nothing can be credited to a day
        $before = $d['_baseline_drift_samples'];
        $d['dimensions']['trust']['x'] = 90.0;
        RelationshipDynamics::recordBaselineDriftSample($d, 0.0);
        $this->assertSame($before, $d['_baseline_drift_samples']);
        // warmth is not a drift dimension; the five are
        $this->assertArrayNotHasKey('warmth', $d['_baseline_drift_samples']);
        foreach (['affinity', 'trust', 'comfort', 'respect', 'maturity'] as $dim) {
            $this->assertArrayHasKey($dim, $d['_baseline_drift_samples'], $dim);
        }
        // the window keeps the last few days only
        for ($day = 12; $day < 30; $day++) $this->contact($d, $day, 50.0);
        $this->assertCount(RelationshipDynamics::defaultConfig()['baseline_drift']['window'], $d['_baseline_drift_samples']['trust']);
    }

    public function testThreeConsistentDaysMoveTheBaselineFivePercent(): void
    {
        $d = $this->npc();
        $base = floatval($d['dimensions']['trust']['baseline']);   // Guarded trust seed (20)
        $this->contact($d, 10, $base + 30.0);
        $this->contact($d, 11, $base + 34.0);
        $this->assertSame([], RelationshipDynamics::processBaselineDrift('Tester', $d), 'two days are not sustained');
        $this->assertSame($base, floatval($d['dimensions']['trust']['baseline']));
        $this->contact($d, 12, $base + 38.0);
        $r = RelationshipDynamics::processBaselineDrift('Tester', $d);
        $this->assertEqualsWithDelta(0.05 * 34.0, $r['trust']['drift'], 1e-9, '5% of (average - baseline)');
        $this->assertEqualsWithDelta($base + 1.7, floatval($d['dimensions']['trust']['baseline']), 1e-9);
        $this->assertSame($base, $r['trust']['old_baseline']);
        // gated by the game calendar: a second diary eval on the same evidence moves nothing ...
        $this->assertArrayNotHasKey('trust', RelationshipDynamics::processBaselineDrift('Tester', $d), 'day 12 already counted');
        $this->assertEqualsWithDelta($base + 1.7, floatval($d['dimensions']['trust']['baseline']), 1e-9);
        // ... the next contact day does (days 11-13: average base + 38, gap 36.3)
        $this->contact($d, 13, $base + 42.0);
        $r = RelationshipDynamics::processBaselineDrift('Tester', $d);
        $this->assertEqualsWithDelta(0.05 * ($base + 38.0 - ($base + 1.7)), $r['trust']['drift'], 1e-9);

        // a mixed week does not drift
        $m = $this->npc();
        $mb = floatval($m['dimensions']['trust']['baseline']);
        $this->contact($m, 10, $mb + 30.0);
        $this->contact($m, 11, $mb - 10.0);
        $this->contact($m, 12, $mb + 30.0);
        $this->assertArrayNotHasKey('trust', RelationshipDynamics::processBaselineDrift('Tester', $m));
        // within the tolerance (5 points) is not "held above"
        $t = $this->npc();
        $tb = floatval($t['dimensions']['trust']['baseline']);
        foreach ([10, 11, 12] as $day) $this->contact($t, $day, $tb + 4.0);
        $this->assertArrayNotHasKey('trust', RelationshipDynamics::processBaselineDrift('Tester', $t));
        // switched off: nothing
        $GLOBALS['db'] = new RelDynDriftConfigDb(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'baseline_drift_enabled' => false]);
        RelationshipDynamics::clearConfigCache();
        $o = $this->npc();
        foreach ([10, 11, 12] as $day) $this->contact($o, $day, 90.0);
        $this->assertSame([], RelationshipDynamics::processBaselineDrift('Tester', $o));
    }

    public function testDriftIsBoundedFromWhereItStartedAndReanchorsAfterAnEdit(): void
    {
        $d = $this->npc();
        $origin = floatval($d['dimensions']['trust']['baseline']);
        $day = 10;
        for ($i = 0; $i < 400; $i++) {
            $this->contact($d, $day++, 100.0);
            RelationshipDynamics::processBaselineDrift('Tester', $d);
        }
        $this->assertEqualsWithDelta($origin + 20.0, floatval($d['dimensions']['trust']['baseline']), 1e-9, 'at most +20 from the origin');
        // the editor sets a new baseline: the bound is measured from there
        $d['dimensions']['trust']['baseline'] = 60.0;
        for ($i = 0; $i < 400; $i++) {
            $this->contact($d, $day++, 0.0);
            RelationshipDynamics::processBaselineDrift('Tester', $d);
        }
        $this->assertEqualsWithDelta(40.0, floatval($d['dimensions']['trust']['baseline']), 1e-9, 'at most -20 from the edited value');
    }

    public function testAffinityDriftsInCoreUnits(): void
    {
        // Held at core 60 (mirror 80) for three contact days; the Guarded affinity baseline is 18
        // core (not stored: the trait / temperament value)
        $d = $this->npc(60.0);
        $this->assertNull($d['dimensions']['affinity']['baseline'] ?? null);
        $base = RelationshipDynamics::getTemperamentBaseline('Guarded', 'affinity', $d);
        foreach ([10, 11, 12] as $day) RelationshipDynamics::recordBaselineDriftSample($d, $day * self::DAY);
        $this->assertSame(60.0, $d['_baseline_drift_samples']['affinity'][0]['v'], 'core units, not the mirror 80');
        $r = RelationshipDynamics::processBaselineDrift('Tester', $d);
        $this->assertEqualsWithDelta($base + 0.05 * (60.0 - $base), floatval($d['dimensions']['affinity']['baseline']), 1e-9);
        $this->assertEqualsWithDelta($base, $r['affinity']['old_baseline'], 1e-9);
        // the diary's sustained-delta trigger reads the same units
        $this->assertContains('sustained_delta:affinity(' . round(60.0 - floatval($d['dimensions']['affinity']['baseline']), 1) . ')',
            RelationshipDynamics::detectDiaryContentTriggers($d));
    }

    public function testMaturityIsGlobalAndDriftsToo(): void
    {
        $d = $this->npc();
        $base = floatval($d['dimensions']['maturity']['baseline']);
        foreach ([10, 11, 12] as $day) {
            $d['dimensions']['maturity']['x'] = $base - 20.0;
            RelationshipDynamics::recordBaselineDriftSample($d, $day * self::DAY);
        }
        $r = RelationshipDynamics::processBaselineDrift('Tester', $d);
        $this->assertEqualsWithDelta(-1.0, $r['maturity']['drift'], 1e-9);
        $this->assertArrayNotHasKey('warmth', $r);
    }
}
