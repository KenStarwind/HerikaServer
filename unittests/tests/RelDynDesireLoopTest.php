<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynDesireLoopConfigDb
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
 * The desire loop (roadmap desire-loop; session 2026-03-30):
 *   - arousal amplifies passion gain, 1.0x at arousal 15 rising to 1.8x at 100 (the local
 *     love-language gain, the eval's passion signal and the spike alike);
 *   - desire (the effective sex disposition) reads the effective passion (floor + the moment)
 *     and the mood, +-3 disposition points;
 *   - the bond filters how a flirt feels: a crush's reads warm, a stranger's reads "eww", an
 *     acquaintance's is shrugged off (the valence back-filter, by RelDyn bond type).
 * Units: passion / arousal points 0..100, valence -100..100, disposition 0..30.
 */
final class RelDynDesireLoopTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = new RelDynDesireLoopConfigDb(RelationshipDynamics::defaultConfig());
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function npc(string $coreType, float $aff, float $passion, float $arousal, float $valence = 0.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Romantic',
            'love_language_primary' => RelationshipDynamics::LL_WORDS,
            'love_language_secondary' => RelationshipDynamics::LL_TIME,
        ]));
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        RelationshipDynamics::setCoreRelationshipType($d, $coreType);
        RelationshipDynamics::setPassion($d, $passion);
        $d['dimensions']['arousal']['x'] = $arousal;
        $d['dimensions']['valence']['x'] = $valence;
        $d['_attraction'] = ['enabled' => true, 'spark' => 20.0, 'spark_mult' => 1.0, 'passion_mult' => 1.0];
        return $d;
    }

    public function testArousalAmplifiesPassionGain(): void
    {
        $calm = $this->npc('romantic', 60, 40, 10);
        $rush = $this->npc('romantic', 60, 40, 100);
        $half = $this->npc('romantic', 60, 40, 57.5);
        $g = RelationshipDynamics::calculatePassionGain($calm, RelationshipDynamics::LL_WORDS);
        $this->assertGreaterThan(0.0, $g);
        $this->assertEqualsWithDelta(1.8 * $g, RelationshipDynamics::calculatePassionGain($rush, RelationshipDynamics::LL_WORDS), 1e-9);
        $this->assertEqualsWithDelta(1.4 * $g, RelationshipDynamics::calculatePassionGain($half, RelationshipDynamics::LL_WORDS), 1e-9);

        // The eval's passion signal too (above the spark, attraction x1: the physics is linear in it)
        $r1 = RelationshipDynamics::applyEvalSignal('Tester', $calm, 'passion', 2.0, [], 1.0, 50.0);
        $r2 = RelationshipDynamics::applyEvalSignal('Tester', $rush, 'passion', 2.0, [], 1.0, 50.0);
        $this->assertGreaterThan(0.0, $r1['actual']);
        $this->assertEqualsWithDelta(1.8 * $r1['actual'], $r2['actual'], 1e-3, $r2['line']);
        $this->assertStringContainsString('arousal x1.80', $r2['line']);
        // A loss is not amplified
        $l1 = RelationshipDynamics::applyEvalSignal('Tester', $calm, 'passion', -2.0, [], 1.0, 50.0);
        $l2 = RelationshipDynamics::applyEvalSignal('Tester', $rush, 'passion', -2.0, [], 1.0, 50.0);
        $this->assertEqualsWithDelta($l1['actual'], $l2['actual'], 1e-9);
    }

    public function testDesireReadsTheMomentAndTheMood(): void
    {
        $d = $this->npc('romantic', 60, 20, 10);
        $this->assertSame(16, RelationshipDynamics::getEffectiveDisposition(10, $d));    // 10 + 20 x 0.3
        RelDynPassion::storeSpike($d, 10.0, 'touch');
        $this->assertSame(19, RelationshipDynamics::getEffectiveDisposition(10, $d), 'the moment is desire too');
        $d['dimensions']['valence']['x'] = 50.0;
        $this->assertSame(22, RelationshipDynamics::getEffectiveDisposition(10, $d), 'a good mood, +3');
        $d['dimensions']['valence']['x'] = -100.0;
        $this->assertSame(16, RelationshipDynamics::getEffectiveDisposition(10, $d), 'a bad one, -3');
        $d['dimensions']['valence']['x'] = 25.0;
        $this->assertEqualsWithDelta(1.5, RelDynPassion::desireValenceTerm($d), 1e-9);
        // Asexual (emotional channel): no sexual desire from passion, the mood still counts
        $d['_attraction']['passion_channel'] = 'emotional';
        $this->assertSame(12, RelationshipDynamics::getEffectiveDisposition(10, $d));    // 10 + 1.5 rounded
    }

    public function testTheBondFiltersHowAFlirtFeels(): void
    {
        $crush = $this->npc('crush', 40, 30, 10);
        $stranger = $this->npc('neutral', 0, 5, 10);
        $acquaintance = $this->npc('professional', 20, 10, 10);
        $this->assertSame('crush', RelationshipDynamics::getRelationshipType('Tester', $crush));
        $this->assertSame('stranger', RelationshipDynamics::getRelationshipType('Tester', $stranger));
        $this->assertSame('acquaintance', RelationshipDynamics::getRelationshipType('Tester', $acquaintance));
        $this->assertGreaterThan(0.0, RelDynPassion::flirtValence('Tester', $crush, 3.0), 'a crush warms to it');
        $this->assertLessThan(0.0, RelDynPassion::flirtValence('Tester', $stranger, 3.0), "a stranger's reads eww");
        $this->assertSame(0.0, RelDynPassion::flirtValence('Tester', $acquaintance, 3.0), "an acquaintance's is shrugged off");
        $this->assertGreaterThan(0.0, floatval($crush['dimensions']['valence']['x']));
        $this->assertLessThan(0.0, floatval($stranger['dimensions']['valence']['x']));
        $this->assertSame(0.0, floatval($acquaintance['dimensions']['valence']['x']));
        // Intent scales it: a light flirt (1 of 3) a third of open pursuit, before the physics
        $a = $this->npc('crush', 40, 30, 10);
        $b = $this->npc('crush', 40, 30, 10);
        $light = RelDynPassion::flirtValence('Tester', $a, 1.0);
        $open = RelDynPassion::flirtValence('Tester', $b, 3.0);
        $this->assertEqualsWithDelta(3.0, $open / $light, 0.05);
        // While the Ick lasts a romantic move warms nothing, even from the one she has a crush on
        $ick = $this->npc('crush', 40, 30, 10);
        $ick['_ick_tracker'] = ['ick_active' => true];
        $this->assertSame(0.0, RelDynPassion::flirtValence('Tester', $ick, 3.0));
        $this->assertSame(0.0, floatval($ick['dimensions']['valence']['x']));
        // The eval's romantic_intent reaches it once per applied item
        $c = $this->npc('neutral', 0, 5, 10);
        RelationshipDynamics::applyEvalExtraFields('Tester', ['romantic_intent' => 3, 'gamets' => 1000, 'reply_mood' => 'default', 'tags' => []], $c, 1000.0);
        $this->assertLessThan(0.0, floatval($c['dimensions']['valence']['x']));
    }
}
