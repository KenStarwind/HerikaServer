<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Love-language race keys and the read calibration (rulings 2026-09-30), the pure parts: the race
 * normalisation (CHIM's 'NordRace' is not the map's 'nord'), the leniency correction of LLM-read
 * traits (x' = clamp01(r - read_mean + 0.5) at use time, hand-set / preset / overridden traits
 * untouched), the neutral intercepts of the Rule R 'offset' / 'mult' regressions (all-0.5 vector =
 * no offset / x1.0, slopes unchanged, every preset exact), the switch (enabled = false is today's
 * behaviour exactly) and the committed seed's statistics. No database.
 * Units: traits 0..1; offsets in need units (0..1 scale); multipliers unitless.
 */
final class RelDynReadCalibrationTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);   // shipped config
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = null;
        RelDynTraits::$readCalibrationOverride = null;
        RelDynTraits::resetReadCalibrationCache();
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        RelDynTraits::$readCalibrationOverride = null;
        RelDynTraits::resetReadCalibrationCache();
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ race keys

    public static function raceKeys(): array
    {
        return [
            'nord'              => ['NordRace', 'nord'],
            'nord vampire'      => ['NordRaceVampire', 'nord'],
            'breton vampire'    => ['BretonRaceVampire', 'breton'],
            'dark elf'          => ['DarkElfRace', 'darkelf'],
            'spaced'            => ['Dark Elf', 'darkelf'],
            'underscored'       => ['wood_elf', 'woodelf'],
            'high elf vampire'  => ['HighElfRaceVampire', 'highelf'],
            'bare'              => ['Imperial', 'imperial'],
            'empty'             => ['', ''],
            'null'              => [null, ''],
        ];
    }

    #[DataProvider('raceKeys')]
    public function testRaceKeysAreNormalised($race, string $key): void
    {
        $this->assertSame($key, RelationshipDynamics::normalizeRaceKey($race));
    }

    public function testTheCoreRaceNamesFindTheirLoveLanguage(): void
    {
        $LL = fn(string $c) => constant("RelationshipDynamics::{$c}");
        $this->assertSame($LL('LL_SERVICE'), RelationshipDynamics::raceToLoveLanguage('NordRace'));
        $this->assertSame($LL('LL_SERVICE'), RelationshipDynamics::raceToLoveLanguage('NordRaceVampire'));
        $this->assertSame($LL('LL_SERVICE'), RelationshipDynamics::raceToLoveLanguage('OrcRace'));
        $this->assertSame($LL('LL_SERVICE'), RelationshipDynamics::raceToLoveLanguage('RedguardRace'));
        $this->assertSame($LL('LL_WORDS'), RelationshipDynamics::raceToLoveLanguage('BretonRace'));
        $this->assertSame($LL('LL_WORDS'), RelationshipDynamics::raceToLoveLanguage('DarkElfRace'));
        $this->assertSame($LL('LL_GIFTS'), RelationshipDynamics::raceToLoveLanguage('HighElfRace'));
        $this->assertSame($LL('LL_GIFTS'), RelationshipDynamics::raceToLoveLanguage('ImperialRace'));
        $this->assertSame($LL('LL_TOUCH'), RelationshipDynamics::raceToLoveLanguage('KhajiitRace'));
        $this->assertSame($LL('LL_TOUCH'), RelationshipDynamics::raceToLoveLanguage('WoodElfRace'));
        $this->assertSame($LL('LL_TIME'), RelationshipDynamics::raceToLoveLanguage('ArgonianRace'));
        // unknown or missing: quality time, as before
        $this->assertSame($LL('LL_TIME'), RelationshipDynamics::raceToLoveLanguage('SnowElfRace'));
        $this->assertSame($LL('LL_TIME'), RelationshipDynamics::raceToLoveLanguage(null));
    }

    public function testTheLoveLanguageTablesCoverEveryPresetWithAValidLanguage(): void
    {
        foreach (['love_language_primary', 'love_language_secondary'] as $key) $this->checkLoveLanguageTable($key);
    }

    private function checkLoveLanguageTable(string $key): void
    {
        $table = RelationshipDynamics::defaultConfig()[$key];
        $valid = [RelationshipDynamics::LL_WORDS, RelationshipDynamics::LL_TIME, RelationshipDynamics::LL_TOUCH,
                  RelationshipDynamics::LL_SERVICE, RelationshipDynamics::LL_GIFTS];
        $this->assertSame([], array_values(array_diff(array_keys(RelDynTraits::PRESET_TRAITS), array_keys($table))));
        foreach ($table as $preset => $ll) {
            $this->assertTrue(RelDynTraits::isPreset($preset), $preset);
            $this->assertContains($ll, $valid, $preset);
        }
    }

    // ------------------------------------------------------------------ the leniency correction

    /** A stored read vector + provenance as ensureTemperamentProfile writes it, from per-trait [read, conf] (conf 0 = prior). */
    private static function stored(array $reads, array $srcExtra = []): array
    {
        $result = ['v' => 1, 'traits' => []];
        foreach (RelDynTraits::TRAITS as $name) {
            [$v, $c] = $reads[$name] ?? [0.5, 0.0];
            $result['traits'][$name] = ['value' => $v, 'conf' => $c, 'field' => $c > 0 ? 'personality' : null, 'evidence' => $c > 0 ? 'some words' : null];
        }
        $auto = RelDynTraitAssign::resolve(['read' => $result, 'prior_in' => []]);
        return ['trait_vector' => RelDynTraits::toStored($auto['x']),
                '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($auto['x']), 'auto_label' => $auto['label'],
                    'traits' => $auto['src'], 'composed' => 'auto', 'overridden' => []] + $srcExtra,
                'inferred_temperament' => $auto['label']];
    }

    public function testAnLlmReadTraitIsMovedByItsLeniencyAtItsOwnWeight(): void
    {
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'leniency' => true, 'read_mean' => ['guard' => 0.66, 'warmth' => 0.50]];
        $d = self::stored(['guard' => [0.8, 0.6], 'warmth' => [0.3, 0.9], 'pride' => [0.7, 0.6]]);
        $raw = RelDynTraits::fromStored($d['trait_vector']);
        $x = RelDynTraits::readVector($d);
        // guard: read 0.8 at weight 0.8 x 0.6 over the 0.5 prior; the read itself becomes 0.8 - 0.66 + 0.5
        $this->assertEqualsWithDelta(0.5 + 0.48 * (0.8 - 0.66 + 0.5 - 0.5), $x['G'], 1e-12);
        $this->assertEqualsWithDelta(0.5 + 0.48 * (0.8 - 0.5), $raw['G'], 1e-12, 'the stored read stays raw');
        // warmth: read mean 0.50 is the neutral point: nothing to correct
        $this->assertEqualsWithDelta($raw['W'], $x['W'], 1e-12);
        // pride has no read_mean (not enough reads): untouched; so is an unread trait and maturity_start
        $this->assertEqualsWithDelta($raw['Pd'], $x['Pd'], 1e-12);
        $this->assertEqualsWithDelta($raw['E'], $x['E'], 1e-12);
        $this->assertEqualsWithDelta($raw['maturity_start'], $x['maturity_start'], 1e-12);
    }

    public function testTheCorrectionKeepsTheSpreadOfTheReads(): void
    {
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'leniency' => true, 'read_mean' => ['guard' => 0.66]];
        $hi = self::stored(['guard' => [0.9, 0.9]]);
        $lo = self::stored(['guard' => [0.5, 0.9]]);
        $rawGap = RelDynTraits::fromStored($hi['trait_vector'])['G'] - RelDynTraits::fromStored($lo['trait_vector'])['G'];
        $gap = RelDynTraits::readVector($hi)['G'] - RelDynTraits::readVector($lo)['G'];
        $this->assertEqualsWithDelta($rawGap, $gap, 1e-12);
        $this->assertEqualsWithDelta(0.72 * 0.4, $gap, 1e-12);
        // and a read at the population's mean lands on the middle, whatever the prior
        $mid = self::stored(['guard' => [0.66, 1.0]]);
        $this->assertEqualsWithDelta(0.5 + 0.8 * 0.0, RelDynTraits::readVector($mid)['G'], 1e-12);
    }

    public function testTheCorrectedValueIsClampedToTheUnitInterval(): void
    {
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'leniency' => true, 'read_mean' => ['guard' => 0.9, 'warmth' => 0.1]];
        $d = self::stored(['guard' => [0.05, 1.0], 'warmth' => [0.99, 1.0]]);
        $x = RelDynTraits::readVector($d);
        // guard read 0.05 - 0.9 + 0.5 < 0 -> 0; warmth 0.99 - 0.1 + 0.5 > 1 -> 1, each blended at weight 0.8 over the 0.5 prior
        $this->assertEqualsWithDelta(0.5 + 0.8 * (0.0 - 0.5), $x['G'], 1e-12);
        $this->assertEqualsWithDelta(0.5 + 0.8 * (1.0 - 0.5), $x['W'], 1e-12);
        foreach (RelDynTraits::TRAITS as $code => $_) {
            $this->assertGreaterThanOrEqual(0.0, $x[$code]);
            $this->assertLessThanOrEqual(1.0, $x[$code]);
        }
    }

    public function testOnlyAnLlmReadIsCorrected(): void
    {
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'leniency' => true, 'read_mean' => array_fill_keys(RelDynTraits::TRAITS, 0.8)];
        $read = ['guard' => [0.9, 0.9], 'warmth' => [0.9, 0.9], 'confidence' => [0.9, 0.9]];
        $base = self::stored($read);
        $raw = RelDynTraits::fromStored($base['trait_vector']);
        $this->assertLessThan($raw['G'], RelDynTraits::readVector($base)['G'], 'the control: this read is corrected');

        // a hand-set vector (Ashe): sources 'preset', no bio trait
        $auto = RelDynTraitAssign::resolve(['hand_set' => ['guard' => 0.75, 'warmth' => 0.4], 'prior_in' => []]);
        $hand = ['trait_vector' => RelDynTraits::toStored($auto['x']),
                 '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($auto['x']), 'traits' => $auto['src'], 'composed' => 'auto', 'overridden' => []]];
        $this->assertSame(json_encode($auto['x']), json_encode(RelDynTraits::readVector($hand)));

        // a preset (label-assigned, config or Sharmat): every trait is the preset's
        $auto = RelDynTraitAssign::resolve(['preset' => 'Bold', 'prior_in' => []]);
        $preset = ['trait_vector' => RelDynTraits::toStored($auto['x']),
                   '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($auto['x']), 'traits' => $auto['src'], 'composed' => 'auto', 'overridden' => []]];
        $this->assertSame(json_encode($auto['x']), json_encode(RelDynTraits::readVector($preset)));

        // a read vector the editor's preset (or a stored preset label) replaced, and a per-trait override
        $replaced = $base;
        $replaced['_trait_vector_src']['composed'] = 'override';
        $this->assertSame(json_encode($raw), json_encode(RelDynTraits::readVector($replaced)));
        $replaced['_trait_vector_src']['composed'] = 'stored';
        $this->assertSame(json_encode($raw), json_encode(RelDynTraits::readVector($replaced)));
        $over = $base;
        $over['_trait_vector_src']['overridden'] = ['guard'];
        $this->assertEqualsWithDelta($raw['G'], RelDynTraits::readVector($over)['G'], 1e-12);
        $this->assertLessThan($raw['W'], RelDynTraits::readVector($over)['W'], 'the other traits still are');

        // the label assignment has no own vector at all
        RelDynTraits::$assignmentOverride = 'label';
        $this->assertNull(RelDynTraits::readVector($base));
    }

    public function testDisabledIsTodayExactly(): void
    {
        RelDynTraits::$readCalibrationOverride = ['relevel' => false, 'leniency' => false, 'read_mean' => array_fill_keys(RelDynTraits::TRAITS, 0.8)];
        $d = self::stored(['guard' => [0.9, 0.9], 'warmth' => [0.2, 0.7], 'pride' => [0.7, 0.6]]);
        $this->assertSame(json_encode(RelDynTraits::fromStored($d['trait_vector'])), json_encode(RelDynTraits::readVector($d)));
        $this->assertFalse(RelDynTraits::leniencyEnabled());
        $this->assertFalse(RelDynTraits::relevelEnabled());
        // leniency on, relevel off: the correction alone moves the vector
        RelDynTraits::$readCalibrationOverride = ['relevel' => false, 'leniency' => true, 'read_mean' => array_fill_keys(RelDynTraits::TRAITS, 0.8)];
        $this->assertNotSame(json_encode(RelDynTraits::fromStored($d['trait_vector'])), json_encode(RelDynTraits::readVector($d)));
        // a stored row without read_calibration: relevel on, leniency off (the read is used as read), the seed's means
        RelDynTraits::$readCalibrationOverride = [];
        $this->assertTrue(RelDynTraits::relevelEnabled());
        $this->assertFalse(RelDynTraits::leniencyEnabled());
        $this->assertSame(json_encode(RelDynTraits::fromStored($d['trait_vector'])), json_encode(RelDynTraits::readVector($d)), 'default: the read as read');
        $this->assertSame(array_keys(array_filter(RelDynTraits::seedReadStats(), fn($s) => $s['n'] >= RelDynTraits::READ_CALIBRATION_MIN_READS)),
            array_keys(RelDynTraits::readMeans()));
    }

    // ------------------------------------------------------------------ the committed seed

    public function testTheShippedReadMeansAreTheSeedsEvidencedMeans(): void
    {
        $stats = RelDynTraits::seedReadStats();
        $shipped = RelationshipDynamics::defaultConfig()['traits']['read_calibration'];
        $this->assertTrue($shipped['relevel']);
        $this->assertFalse($shipped['leniency'], 'the read is the profile: the leniency shift ships off');
        foreach ($stats as $name => $s) {
            if ($s['n'] >= RelDynTraits::READ_CALIBRATION_MIN_READS) {
                $this->assertEqualsWithDelta($s['mean'], $shipped['read_mean'][$name], 0.0005, "{$name} read_mean");
            } else {
                $this->assertArrayNotHasKey($name, $shipped['read_mean'], "{$name}: too few reads for a mean ({$s['n']})");
            }
        }
        // the LLM reads high: guard 0.66 where the middle is 0.5 (the 100-read seed)
        $this->assertGreaterThan(0.6, $stats['guard']['mean']);
        $this->assertGreaterThan(RelDynTraits::READ_CALIBRATION_MIN_READS, $stats['guard']['n']);
        // and the means are what the engine uses when the config says nothing
        RelDynTraits::$readCalibrationOverride = [];
        foreach (RelDynTraits::readMeans() as $name => $m) $this->assertEqualsWithDelta($stats[$name]['mean'], $m, 1e-12);
    }

    // ------------------------------------------------------------------ the neutral intercepts

    /** Today's Rule R, as it was before the intercepts were re-levelled (the test's own copy). */
    private static function legacyR($model, array $table, array $x): float
    {
        $f = fn(array $y) => is_callable($model) ? floatval($model($y)) : self::linear((array) $model, $y);
        $rho = RelDynTraits::residualReach();
        $v = $f($x);
        foreach (RelDynTraits::points() as $name => $p) {
            $u = RelDynTraits::distance($x, $p) / $rho;
            if ($u >= 1.0) continue;
            $v += RelDynTraits::residualKernel($u) * (floatval($table[$name]) - $f($p));
        }
        return $v;
    }

    private static function linear(array $coef, array $x): float
    {
        $v = floatval($coef[0] ?? 0.0);
        foreach ($coef as $k => $c) {
            if ($k !== 0) $v += floatval($c) * floatval($x[$k] ?? 0.5);
        }
        return $v;
    }

    /** Rule R columns whose unit is re-levelled: id => [unit, neutral]. */
    private static function relevelled(): array
    {
        $out = [];
        foreach (RelDynTraits::columns() as $id => $c) {
            if ($c['rule'] === 'R' && $c['model'] !== null && isset(RelDynTraits::RELEVEL_UNITS[$c['unit']]) && !in_array($id, RelDynTraits::RELEVEL_EXCLUDED, true)) $out[$id] = [$c['unit'], RelDynTraits::RELEVEL_UNITS[$c['unit']]];
        }
        return $out;
    }

    /** Vectors around the middle, none within the Rule R reach of a preset (so the residuals are zero there). */
    private static function middleVectors(int $n): array
    {
        mt_srand(2026);
        $out = [];
        $rho = RelDynTraits::residualReach();
        while (count($out) < $n) {
            $x = [];
            foreach (RelDynTraits::TRAITS as $code => $_) $x[$code] = 0.5 + (mt_rand(-1000, 1000) / 1000.0) * 0.15;
            $x['maturity_start'] = 50.0;
            if (RelDynTraits::nearestPreset($x)['distance'] >= $rho) $out[] = $x;
        }
        return $out;
    }

    public function testEveryRelevelledRuleIsNeutralAtTheMiddleVector(): void
    {
        $half = array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.5) + ['maturity_start' => 50.0];
        $rules = self::relevelled();
        $this->assertCount(8, $rules, 'the Rule R regressions in the offset / mult units, less the five that break the wrong-way guard');
        // y_affinity_up keeps its textbook intercept (re-levelled it would break the 20% wrong-way guard)
        $this->assertSame(['y_affinity_up', 'y_affinity_down', 'y_valence_up', 'y_respect_up', 'resist_trust'], RelDynTraits::RELEVEL_EXCLUDED);
        foreach (['y_affinity_up' => 0.865, 'y_affinity_down' => 0.865, 'y_valence_up' => 0.785, 'y_respect_up' => 0.675, 'resist_trust' => 0.76] as $id => $middle) {
            $this->assertEqualsWithDelta($middle, RelDynTraits::value($half, $id), 1e-12, "{$id} keeps its textbook intercept");
        }
        foreach ($rules as $id => [$unit, $neutral]) {
            $this->assertEqualsWithDelta($neutral, RelDynTraits::value($half, $id), 1e-12, "{$id} at the all-0.5 vector");
        }
        // the textbook intercepts were not: old values at the middle, as re-levelled (listed in the commit message)
        $old = [];
        foreach (RelDynTraits::columns() as $id => $c) {
            if (isset($rules[$id])) $old[$id] = round(RelDynTraits::modelAtMiddle($c['model'], $c['unit'])['old'], 3);
        }
        $this->assertSame(0.925, $old['passion_mult']);
        $this->assertSame(1.185, $old['jealousy_mult']);
        $this->assertSame(0.925, $old['passion_mult']);
        $this->assertSame(0.91, $old['y_warmth_up']);
        // A26 and C2 (rowParam rules): the +0.20 emotional offset at a middle read, and avoidance
        $this->assertEqualsWithDelta(0.20, RelDynTraits::modelAtMiddle(RelDynIntimacy::TEMPERAMENT_RULES['emotional'][1], 'offset')['old'], 1e-12);
        $this->assertEqualsWithDelta(0.01, RelDynTraits::modelAtMiddle(RelDynIntimacy::TEMPERAMENT_RULES['physical'][1], 'offset')['old'], 1e-12);
        foreach (RelDynIntimacy::TEMPERAMENT_RULES as $a => [$rule, $model]) {
            $this->assertEqualsWithDelta(0.0, RelDynTraits::modelAtMiddle($model, 'offset')['new'], 1e-12, "A26 {$a}");
        }
        $this->assertEqualsWithDelta(0.0, RelDynTraits::modelAtMiddle([0.08, 'W' => -0.21], 'offset')['new'], 1e-12, 'C2 avoidance');
    }

    public function testSlopesAreUnchangedAndOffIsTodaysRule(): void
    {
        $rules = self::relevelled();
        $half = array_fill_keys(array_keys(RelDynTraits::TRAITS), 0.5) + ['maturity_start' => 50.0];
        $vectors = self::middleVectors(25);
        foreach ($rules as $id => [$unit, $neutral]) {
            $c = RelDynTraits::columns()[$id];
            $delta = RelDynTraits::modelAtMiddle($c['model'], $unit)['old'] - $neutral;
            foreach ($vectors as $x) {
                RelDynTraits::$readCalibrationOverride = ['relevel' => true];
                $on = RelDynTraits::value($x, $id);
                RelDynTraits::$readCalibrationOverride = ['relevel' => false];
                $off = RelDynTraits::value($x, $id);
                $this->assertEqualsWithDelta(self::legacyR($c['model'], RelDynTraits::table($id), $x), $off, 1e-9, "{$id} off = today's Rule R");
                $this->assertEqualsWithDelta($off - $delta, $on, 1e-9, "{$id}: the same slopes, the intercept moved by the middle's offset");
            }
        }
        // every other column (other units, Rule RI and I) is the same on or off, at any vector
        foreach (RelDynTraits::columns() as $id => $c) {
            if (isset($rules[$id])) continue;
            foreach (array_slice($vectors, 0, 5) as $x) {
                RelDynTraits::$readCalibrationOverride = ['relevel' => true];
                $on = RelDynTraits::value($x, $id);
                RelDynTraits::$readCalibrationOverride = ['relevel' => false];
                $this->assertEqualsWithDelta($on, RelDynTraits::value($x, $id), 1e-12, "{$id} is not re-levelled");
            }
        }
    }

    public function testEveryPresetIsExactOnOrOff(): void
    {
        foreach ([true, false] as $enabled) {
            RelDynTraits::$readCalibrationOverride = ['relevel' => $enabled, 'leniency' => $enabled];
            foreach (RelDynTraits::points() as $preset => $p) {
                foreach (RelDynTraits::columns() as $id => $c) {
                    $this->assertSame(RelDynTraits::table($id)[$preset], RelDynTraits::value($p, $id), "{$id} at {$preset}, calibration " . ($enabled ? 'on' : 'off'));
                }
                $table = RelDynIntimacy::configDefaults()['temperament'];
                $this->assertSame((array) ($table[$preset] ?? []),
                    RelDynTraits::rowAt($p, $table, RelDynIntimacy::TEMPERAMENT_RULES, 'offset'), "A26 row at {$preset}");
            }
        }
    }

    public function testALabelPathNpcIsUnchanged(): void
    {
        // the label assignment: the vector is the label's preset point, so every column is its table value
        RelDynTraits::$assignmentOverride = 'label';
        foreach ([true, false] as $enabled) {
            RelDynTraits::$readCalibrationOverride = ['relevel' => $enabled, 'leniency' => $enabled];
            foreach (array_keys(RelDynTraits::PRESET_TRAITS) as $preset) {
                $d = ['inferred_temperament' => $preset];
                $this->assertEqualsWithDelta(RelationshipDynamics::TEMPERAMENT_PASSION_MULT[$preset] ?? 1.0, RelDynTraits::param($preset, 'passion_mult', 1.0, $d), 1e-12, $preset);
                $this->assertSame((array) (RelDynIntimacy::configDefaults()['temperament'][$preset] ?? []),
                    RelDynTraits::rowParam($preset, RelDynIntimacy::configDefaults()['temperament'], RelDynIntimacy::TEMPERAMENT_RULES, 'offset', $d), $preset);
            }
        }
    }

    // ------------------------------------------------------------------ A26 over the seed reads

    /** Each of the 100 seed reads as an NPC with only the read (a flat 0.5 prior): the vector the engine uses. */
    private static function seedNpcs(): array
    {
        $out = [];
        foreach ((array) RelDynTraitRead::loadSeedFile()['reads'] as $key => $e) {
            $auto = RelDynTraitAssign::resolve(['read' => $e['result'], 'prior_in' => []]);
            $stored = RelDynTraits::toStored($auto['x']);
            $out[$key] = ['trait_vector' => $stored, 'inferred_temperament' => $auto['label'],
                '_trait_vector_src' => ['assignment' => 'read', 'auto' => $stored, 'auto_label' => $auto['label'], 'traits' => $auto['src'], 'composed' => 'auto', 'overridden' => []]];
        }
        return $out;
    }

    private static function meanA26(array $npcs): array
    {
        $table = RelDynIntimacy::configDefaults()['temperament'];
        $sum = ['physical' => 0.0, 'emotional' => 0.0];
        foreach ($npcs as $d) {
            $row = RelDynTraits::rowParam($d['inferred_temperament'], $table, RelDynIntimacy::TEMPERAMENT_RULES, 'offset', $d);
            foreach ($sum as $axis => $_) $sum[$axis] += floatval($row[$axis] ?? 0.0) / count($npcs);
        }
        return $sum;
    }

    public function testTheMeanA26OffsetOverTheSeedReadsIsAboutZero(): void
    {
        $npcs = self::seedNpcs();
        $this->assertCount(100, $npcs);
        // both corrections (leniency explicitly on): the seed's population is centred on the middle
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'leniency' => true];
        $both = self::meanA26($npcs);
        $this->assertEqualsWithDelta(0.0, $both['emotional'], 0.05, 'emotional offset, relevel + leniency');
        $this->assertEqualsWithDelta(0.0, $both['physical'], 0.05, 'physical offset, relevel + leniency');
        // the shipped defaults (relevel on, the read used as read): these reads are guarded and confident
        // named people, so their offsets are theirs; the unchosen intercept is gone
        RelDynTraits::$readCalibrationOverride = ['relevel' => true, 'leniency' => false];
        $shipped = self::meanA26($npcs);
        $this->assertEqualsWithDelta(0.0, $shipped['emotional'], 0.06, 'emotional offset, shipped defaults');
        $this->assertEqualsWithDelta(0.0, $shipped['physical'], 0.05, 'physical offset, shipped defaults');
        // today's behaviour: a typical read carries +0.2 emotional need nobody chose
        RelDynTraits::$readCalibrationOverride = ['relevel' => false, 'leniency' => false];
        $off = self::meanA26($npcs);
        $this->assertGreaterThan(0.15, $off['emotional'], 'both off: the unchosen offset');
    }
}
