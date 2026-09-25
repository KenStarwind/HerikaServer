<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Answers the stored RelDyn config row only (the attraction definition's other reads get no row). */
final class RelDynStandardsFloorConfigDb
{
    public ?array $config = null;

    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }

    public function fetchOne($sql, $params = null)
    {
        if (str_contains((string) $sql, "FROM conf_opts WHERE id = 'relationship_dynamics_config'")) {
            return $this->config === null ? [] : ['value' => json_encode($this->config)];
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return false; }
}

/**
 * Decisions 2026-09-23 §15 / traits design §5.1: attraction floors scale with standards.
 * Ken: floors are "scaled by openness, something like standards; more mature know what they
 * want". The flat curve.floor 45 and Aela's hand-set 68 are replaced by
 *   floor = clamp(45 + 16 z_sel + 27 z_mat + 27 z_self, 25, 85)   pillar points
 * z_sel from the effective openness (0.6 centre, 0.3 span), z_mat from the maturity BASELINE
 * (50 / 50), z_self from max(confidence, pride) (0.5 / 0.5). The design's 12 / 20 / 20 shape,
 * scaled so Aela's committed read lands in Ken's "high 60s" (the four test beds' floors,
 * Aela's among them, come out of their reads in RelDynStandardsTestBedsPostgresTest).
 *
 * Plus the openness band's hysteresis around the won-over switch (0.45): a band read from the
 * traits holds until the openness is 0.02 past the boundary, so an NPC a hair from the edge
 * (Aela's read 0.462, Serana's 0.445) does not flip when its vector is re-resolved.
 */
final class RelDynStandardsFloorTest extends TestCase
{
    private $savedDb = null;
    private bool $hadDb = false;
    private RelDynStandardsFloorConfigDb $db;
    private $prevErrorLog = null;
    private string $errorLog;

    protected function setUp(): void
    {
        RelDynTraits::$assignmentOverride = 'read';
        $this->hadDb = array_key_exists('db', $GLOBALS);
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->db = new RelDynStandardsFloorConfigDb();
        $this->db->config = RelationshipDynamics::defaultConfig();
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdstd');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        if ($this->hadDb) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
    }

    private static function floorOf(float $o, float $m, float $c, float $pd = 0.0): float
    {
        return RelDynAttraction::standardsFloor(['o' => $o, 'M' => $m, 'C' => $c, 'Pd' => $pd])['floor'];
    }

    /** A read-assigned NPC's state: its own stored vector (codes + maturity_start) and maturity baseline. */
    private static function readNpc(array $x, ?float $baseline = null): array
    {
        return [
            'inferred_temperament' => RelDynTraits::nearestPreset($x)['name'],
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            'dimensions' => ['maturity' => ['x' => $baseline ?? $x['maturity_start'], 'baseline' => $baseline ?? $x['maturity_start']]],
        ];
    }

    /** The base vector: the prior with no evidence (0.5 everywhere, Po_base), maturity from its model. */
    private static function baseVector(): array
    {
        $x = RelDynTraitAssign::prior([])['x'];
        $x['maturity_start'] = RelDynTraitAssign::maturityModel($x);
        return $x;
    }

    private static function opennessO(array $x): float
    {
        $cfg = RelDynAttraction::defaults();
        return floatval(RelDynTraits::opennessAt($x, $cfg['temperament_openness'], $cfg['openness_levels'])['o']);
    }

    // ------------------------------------------------------------------ the formula

    public function testKensGenericFloorIsTheCentreAndTheSpanIsClamped(): void
    {
        $this->assertSame(45.0, floatval(RelDynAttraction::curveConfig()['floor']), "F0: Ken's generic 45");
        $this->assertSame(45.0, self::floorOf(0.6, 50.0, 0.5, 0.5), 'a middling NPC: Ken\'s 45');
        $r = RelDynAttraction::standardsFloor(['o' => 0.45, 'M' => 75.0, 'C' => 0.75]);
        $this->assertEqualsWithDelta(['sel' => 0.5, 'mat' => 0.5, 'self' => 0.5], $r['z'], 1e-9);
        $this->assertEqualsWithDelta(['sel' => 8.0, 'mat' => 13.5, 'self' => 13.5], $r['points'], 1e-9);
        $this->assertEqualsWithDelta(80.0, $r['floor'], 1e-9);
        // each z saturates at +-1, and the sum is clamped to 25..85
        $this->assertSame(85.0, self::floorOf(0.0, 100.0, 1.0), 'the most selective, mature, self-assured: capped');
        $this->assertSame(25.0, self::floorOf(1.0, 0.0, 0.0), 'the least: the bottom of the clamp');
        $this->assertEqualsWithDelta(45.0 + 16.0, self::floorOf(0.1, 50.0, 0.5), 1e-9, 'z_sel saturates at 1 (o 0.3 below the centre)');
    }

    public function testMonotoneInOpennessMaturityAndSelfRegard(): void
    {
        $prev = INF;
        for ($o = 0.30; $o <= 0.9001; $o += 0.05) {
            $f = self::floorOf($o, 55.0, 0.6);
            $this->assertLessThanOrEqual($prev, $f, "less open, higher standards (o {$o})");
            $prev = $f;
        }
        $prev = -INF;
        for ($m = 0.0; $m <= 100.0; $m += 10.0) {
            $f = self::floorOf(0.5, $m, 0.6);
            $this->assertGreaterThanOrEqual($prev, $f, "the more mature know what they want (M {$m})");
            $prev = $f;
        }
        $prev = -INF;
        for ($c = 0.0; $c <= 1.0001; $c += 0.1) {
            $f = self::floorOf(0.5, 55.0, $c);
            $this->assertGreaterThanOrEqual($prev, $f, "self-regard (C {$c})");
            $prev = $f;
        }
        // strictly inside the clamp each input moves it
        $this->assertGreaterThan(self::floorOf(0.6, 55.0, 0.6), self::floorOf(0.5, 55.0, 0.6));
        $this->assertGreaterThan(self::floorOf(0.5, 50.0, 0.6), self::floorOf(0.5, 60.0, 0.6));
        $this->assertGreaterThan(self::floorOf(0.5, 55.0, 0.6), self::floorOf(0.5, 55.0, 0.7));
        // pride stands in for confidence when it is the higher of the two (max(C, Pd))
        $this->assertSame(self::floorOf(0.5, 55.0, 0.8, 0.3), self::floorOf(0.5, 55.0, 0.3, 0.8));
        $this->assertSame(self::floorOf(0.5, 55.0, 0.6, 0.2), self::floorOf(0.5, 55.0, 0.6, 0.6));
    }

    // ------------------------------------------------------------------ the presets, the base, Ashe

    public function testThePresetsSortBySelectivenessAndTheBaseVectorStaysNearKensFortyFive(): void
    {
        $floors = [];
        foreach (RelDynTraits::points() as $name => $p) {
            $floors[$name] = self::floorOf(self::opennessO($p), floatval($p['maturity_start']), $p['C'], $p['Pd']);
        }
        $open = max($floors['Romantic'], $floors['Anxious'], $floors['Playful'], $floors['Gentle'], $floors['Nurturing']);
        $selective = min($floors['Proud'], $floors['Independent'], $floors['Stoic'], $floors['Guarded']);
        $this->assertGreaterThan($open + 10.0, $selective, 'the low-openness presets hold the high floors: ' . json_encode($floors));
        foreach ($floors as $name => $f) {
            $this->assertGreaterThanOrEqual(25.0, $f, $name);
            $this->assertLessThanOrEqual(85.0, $f, $name);
        }
        // The base vector (no evidence at all) sits at Ken's generic 45. Design §6.2 asked for
        // 45-48 from its assumed base (46.6); the real base vector's openness is 0.56 and its
        // model maturity 45 (below the 50 centre), so it lands at 44.4 (the design's own 12 /
        // 20 / 20 give 44.6 there too): within a point of Ken's 45, which is the intent.
        $base = self::baseVector();
        $bf = self::floorOf(self::opennessO($base), floatval($base['maturity_start']), $base['C'], $base['Pd']);
        $this->assertEqualsWithDelta(45.0, $bf, 1.0, 'the base vector: Ken\'s generic 45');
    }

    /**
     * Ashe (decisions §16 #4: "high floor"): Serene's hand-set vector (config, never read)
     * with maturity 75 (MDD 15.4) gives the highest of the four test beds' floors.
     */
    public function testAshesHandSetVectorGivesHerAHighFloor(): void
    {
        $hand = RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides']['ashe'];
        $x = RelDynTraits::fromStored($hand['trait_vector']);
        $x['maturity_start'] = floatval($hand['maturity_start']);
        $def = RelDynAttraction::definition('Ashe', self::readNpc($x));
        $this->assertSame('standards', $def['sources']['floors']);
        $this->assertSame('low', $def['openness'], 'the least open of the four');
        $this->assertEqualsWithDelta(self::floorOf($def['openness_o'], 75.0, 0.65, 0.40), $def['floors']['strength'], 1e-9);
        $this->assertGreaterThan(78.0, $def['floors']['strength']);
        $this->assertLessThan(85.0, $def['floors']['strength'], 'not at the clamp');
        foreach (RelDynAttraction::PILLARS as $p) {
            $this->assertSame($def['floors']['strength'], $def['floors'][$p], "{$p}: one floor on every pillar");
        }
    }

    // ------------------------------------------------------------------ the definition

    public function testTheDefinitionReadsTheStandardsFloorAndOverridesStillWin(): void
    {
        $x = self::baseVector();
        $x['G'] = 0.7;
        $x['C'] = 0.7;
        $d = self::readNpc($x, 60.0);
        $def = RelDynAttraction::definition('Hilde the Tester', $d);
        $expect = self::floorOf($def['openness_o'], 60.0, 0.7, $x['Pd']);
        $this->assertEqualsWithDelta(self::opennessO($x), $def['openness_o'], 1e-4, 'o is her traits\' openness');
        $this->assertSame('standards', $def['sources']['floors']);
        $this->assertIsArray($def['standards']);
        $this->assertEqualsWithDelta($expect, $def['standards']['floor'], 1e-9);
        foreach (RelDynAttraction::PILLARS as $p) $this->assertEqualsWithDelta($expect, $def['floors'][$p], 1e-9, $p);

        // Maturity reads the baseline, not the live x (standards do not wobble with a bad day)
        $bad = $d;
        $bad['dimensions']['maturity']['x'] = 10.0;
        $this->assertSame($def['floors'], RelDynAttraction::definition('Hilde the Tester', $bad)['floors']);
        $older = $d;
        $older['dimensions']['maturity']['baseline'] = 80.0;
        $this->assertGreaterThan($def['floors']['beauty'], RelDynAttraction::definition('Hilde the Tester', $older)['floors']['beauty']);

        // The editor's openness is the effective openness: it moves the floor (design §5.1)
        $byBand = [];
        foreach (['low', 'medium', 'high'] as $band) {
            $e = $d;
            $e['openness'] = $band;
            $bd = RelDynAttraction::definition('Hilde the Tester', $e);
            $this->assertSame($band, $bd['openness']);
            $this->assertEqualsWithDelta(RelDynAttraction::defaults()['openness_levels'][$band], $bd['openness_o'], 1e-9);
            $byBand[$band] = $bd['floors']['strength'];
        }
        $this->assertGreaterThan($byBand['medium'], $byBand['low']);
        $this->assertGreaterThan($byBand['high'], $byBand['medium']);

        // Per-pillar floors still win: the editor's pillar_floors, then attraction_overrides
        $e = $d;
        $e['attraction_profile'] = ['pillar_floors' => ['strength' => 50]];
        $ed = RelDynAttraction::definition('Hilde the Tester', $e);
        $this->assertSame(50.0, $ed['floors']['strength']);
        $this->assertSame('editor', $ed['sources']['floors.strength']);
        $this->assertEqualsWithDelta($expect, $ed['floors']['beauty'], 1e-9, 'the other pillars keep her standards');
        $e['attraction_overrides'] = ['floors' => ['strength' => 72.5]];
        $od = RelDynAttraction::definition('Hilde the Tester', $e);
        $this->assertSame(72.5, $od['floors']['strength']);
        $this->assertSame('override', $od['sources']['floors.strength']);
    }

    public function testNoVectorOrStandardsOffKeepsTheFlatFloor(): void
    {
        // No temperament and no read vector (design §3.6: the consumer's default)
        RelDynTraits::$assignmentOverride = 'label';
        $def = RelDynAttraction::definition('Nobody Inparticular', []);
        $this->assertNull($def['standards']);
        $this->assertSame('default', $def['sources']['floors']);
        $this->assertSame(array_fill_keys(RelDynAttraction::PILLARS, 45.0), $def['floors']);

        // Standards switched off in config: the flat curve.floor for everyone
        RelDynTraits::$assignmentOverride = 'read';
        $att = RelDynAttraction::defaults();
        $att['standards']['enabled'] = false;
        $this->db->config = array_merge(RelationshipDynamics::defaultConfig(), ['attraction' => $att]);
        RelationshipDynamics::clearConfigCache();
        $x = self::baseVector();
        $x['G'] = 0.8;
        $off = RelDynAttraction::definition('Hilde the Tester', self::readNpc($x, 70.0));
        $this->assertNull($off['standards']);
        $this->assertSame(array_fill_keys(RelDynAttraction::PILLARS, 45.0), $off['floors']);
    }

    /** Aela's hand-set floor is gone from the named presets: hers falls out of her traits. */
    public function testAelasHandSetFloorIsRetired(): void
    {
        $aela = RelDynAttraction::defaults()['npc_overrides']['aela the huntress'];
        $this->assertArrayNotHasKey('floors', $aela);
        $this->assertTrue($aela['openness_from_traits']);
    }

    // ------------------------------------------------------------------ hysteresis at 0.45

    public function testTheOpennessBandHoldsNearTheWonOverSwitch(): void
    {
        $levels = RelDynAttraction::defaults()['openness_levels'];
        $h = RelDynAttraction::defaults()['openness_hysteresis'];
        $this->assertSame(0.02, $h);
        // no band yet: the nearest (Aela's read 0.462 medium, Serana's 0.445 low)
        $this->assertSame('medium', RelDynAttraction::opennessBandHeld(0.462, null, $levels, $h));
        $this->assertSame('low', RelDynAttraction::opennessBandHeld(0.445, null, $levels, $h));
        // a hair across the switch keeps the band she had; clearly across it flips
        $this->assertSame('low', RelDynAttraction::opennessBandHeld(0.462, 'low', $levels, $h));
        $this->assertSame('low', RelDynAttraction::opennessBandHeld(0.4699, 'low', $levels, $h));
        $this->assertSame('medium', RelDynAttraction::opennessBandHeld(0.4701, 'low', $levels, $h));
        $this->assertSame('medium', RelDynAttraction::opennessBandHeld(0.445, 'medium', $levels, $h));
        $this->assertSame('medium', RelDynAttraction::opennessBandHeld(0.4301, 'medium', $levels, $h));
        $this->assertSame('low', RelDynAttraction::opennessBandHeld(0.4299, 'medium', $levels, $h));
        // the medium / high boundary (0.75) the same way; a band two away never holds
        $this->assertSame('medium', RelDynAttraction::opennessBandHeld(0.76, 'medium', $levels, $h));
        $this->assertSame('high', RelDynAttraction::opennessBandHeld(0.78, 'medium', $levels, $h));
        $this->assertSame('high', RelDynAttraction::opennessBandHeld(0.76, 'low', $levels, $h));
        $this->assertSame('medium', RelDynAttraction::opennessBandHeld(0.462, 'low', $levels, 0.0), 'no margin: nearest');
    }

    public function testTheDefinitionHoldsATraitBandFromTheAttractionState(): void
    {
        // a vector whose openness sits a hair above the switch
        $x = self::baseVector();
        $o = null;
        for ($g = 0.55; $g <= 0.75; $g += 0.0025) {
            $x['G'] = $g;
            $o = self::opennessO($x);
            if ($o < 0.465) break;
        }
        $this->assertGreaterThan(0.45, $o);
        $this->assertLessThan(0.47, $o, 'within the margin of the switch');
        $d = self::readNpc($x);
        $fresh = RelDynAttraction::definition('Hilde the Tester', $d);
        $this->assertSame('medium', $fresh['openness'], 'first reading: the nearest band');
        $this->assertTrue($fresh['openness_from_traits']);
        $d['_attraction_state'] = ['openness_band' => 'low'];
        $held = RelDynAttraction::definition('Hilde the Tester', $d);
        $this->assertSame('low', $held['openness'], 'recorded low a hair below: held');
        $this->assertTrue($held['sources']['openness_held']);
        $this->assertSame($fresh['floors'], $held['floors'], 'the floor reads the continuous openness, not the band');
        // an explicit band (editor) is not the traits': no hysteresis
        $d['openness'] = 'medium';
        $ed = RelDynAttraction::definition('Hilde the Tester', $d);
        $this->assertSame('medium', $ed['openness']);
        $this->assertFalse($ed['openness_from_traits']);
    }
}
