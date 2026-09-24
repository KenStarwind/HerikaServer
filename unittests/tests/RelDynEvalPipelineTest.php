<?php declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
// Loaded as in production (main.php), so currentGamets() takes the same path whatever ran before.
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * conf_opts only: the RelDyn config row, so a test can switch subsystems the way the
 * settings page does. Every other statement fails the test (the code under test here is
 * pure math on the dynamics array; nothing else may reach the database).
 */
final class RelDynEvalPipelineConfDb
{
    public array $confOpts = [];

    public function fetchOne($q, array $params = [])
    {
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", (string) $q, $m)) {
            return isset($this->confOpts[$m[1]]) ? ['value' => $this->confOpts[$m[1]]] : [];
        }
        throw new RuntimeException('unexpected query in a pure-math test: ' . $q);
    }

    public function fetchAll($q) { throw new RuntimeException('unexpected query: ' . $q); }
    public function execQuery($q) { throw new RuntimeException('unexpected query: ' . $q); }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
}

/**
 * Decisions 2026-09-23 §1 (relative affinity multipliers) and MDD 15.4 / 15.6 (per-signal
 * temperament resistance, maturity-type direction, distance decay, significance clamp):
 * the table-driven M_modifiers product and the per-signal pipeline of the eval consumer.
 *
 * Expected values are worked out by hand from the decisions table and the MDD tables.
 */
final class RelDynEvalPipelineTest extends TestCase
{
    private RelDynEvalPipelineConfDb $db;
    private array $savedGlobals = [];
    private string $errorLog;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['RELDYN_PLAYER_NAME']);
        // A request carries its game clock (raw gamets, $gameRequest[2]); the grievance log
        // stamps it (recordGrievance -> currentGamets) without reading eventlog.
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', '123456', 'Kaida: hello'];
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-evalpipe-');
        ini_set('error_log', $this->errorLog);
        $this->db = new RelDynEvalPipelineConfDb();
        $GLOBALS['db'] = $this->db;
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        @unlink($this->errorLog);
    }

    /** Stored config row = current defaults with these keys changed. */
    private function setConfig(array $overrides): void
    {
        $cfg = array_merge(RelationshipDynamics::defaultConfig(), $overrides);
        $this->db->confOpts['relationship_dynamics_config'] = json_encode($cfg);
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * An NPC state for the math: explicit temperament, attachment, maturity type and traits,
     * core affinity (-100..100) held as the mirror dimensions.affinity.x = (aff + 100) / 2.
     */
    private function npc(array $o = []): array
    {
        $coreAff = $o['core_aff'] ?? 0.0;
        $mirror = ($coreAff + 100.0) / 2.0;
        $dyn = [
            'inferred_temperament' => $o['temperament'] ?? 'Humble',
            'attachment_style' => $o['attachment'] ?? 'secure',
            'traits' => $o['traits'] ?? [],
            'jealousy_anger' => $o['jealousy'] ?? 0.0,
            'love_language_primary' => $o['ll_primary'] ?? null,
            'love_language_secondary' => $o['ll_secondary'] ?? null,
            '_internal_weather' => $o['weather'] ?? 'clear',
            '_aff_mirror_x' => $mirror,
            'dimensions' => [
                'affinity' => ['x' => $mirror, 'baseline' => null],
                'maturity' => ['x' => $o['maturity'] ?? 50.0, 'baseline' => $o['maturity'] ?? 50.0,
                               'plasticity_type' => $o['maturity_type'] ?? 'Adaptive'],
                'passion' => ['x' => $o['passion'] ?? 0.0, 'baseline' => 0],
                'resentment' => ['x' => $o['resentment'] ?? 0.0, 'baseline' => 0],
                'comfort' => ['x' => $o['comfort'] ?? 50.0, 'baseline' => $o['comfort_baseline'] ?? ($o['comfort'] ?? 50.0)],
                'respect' => ['x' => $o['respect'] ?? 50.0, 'baseline' => $o['respect'] ?? 50.0],
                'trust' => ['x' => $o['trust'] ?? 50.0, 'baseline' => $o['trust_baseline'] ?? ($o['trust'] ?? 50.0)],
            ],
        ];
        $dyn['passion'] = $dyn['dimensions']['passion']['x'];
        return $dyn;
    }

    // ------------------------------------------------------------------------------------
    // M_modifiers: the decisions §1 table, row by row
    // ------------------------------------------------------------------------------------

    public static function modifierCases(): array
    {
        // [npc overrides, raw core-affinity delta, tags, expected M, expected matching row ids]
        return [
            'maturity 50: losses x1.0'          => [['maturity' => 50], -10, ['insult'], 1.0, ['maturity_losses']],
            'maturity 0: losses x1.5'           => [['maturity' => 0], -10, ['insult'], 1.5, ['maturity_losses']],
            'maturity 100: losses x0.5'         => [['maturity' => 100], -10, ['insult'], 0.5, ['maturity_losses']],
            'maturity never touches gains'      => [['maturity' => 0], 10, ['help'], 1.0, []],
            'jealousy 30 is not above 30'       => [['jealousy' => 30], -10, ['insult'], 1.0, ['maturity_losses']],
            'jealousy 65: losses x1.5'          => [['jealousy' => 65], -10, ['insult'], 1.5, ['maturity_losses', 'jealousy_losses']],
            'jealousy 100: losses x2.0'         => [['jealousy' => 100], -10, ['insult'], 2.0, ['maturity_losses', 'jealousy_losses']],
            'jealousy 50: reassurance x1.2'     => [['jealousy' => 50], 10, ['praise'], 1.2, ['jealousy_reassurance']],
            'jealousy 50: untagged gain x1.0'   => [['jealousy' => 50], 10, [], 1.0, []],
            'anxious: neglect loss x2.0'        => [['attachment' => 'anxious'], -10, ['neglect'], 2.0, ['maturity_losses', 'anxious_abandonment']],
            'anxious: jealousy_trigger x2.0'    => [['attachment' => 'anxious'], -10, ['jealousy_trigger'], 2.0, ['maturity_losses', 'anxious_abandonment']],
            'anxious: insult not amplified'     => [['attachment' => 'anxious'], -10, ['insult'], 1.0, ['maturity_losses']],
            'anxious: quality_time gain x1.3'   => [['attachment' => 'anxious'], 10, ['quality_time'], 1.3, ['anxious_reassurance']],
            'avoidant: touch at comfort 40'     => [['attachment' => 'avoidant', 'comfort' => 40], 10, ['touch'], 0.6, ['avoidant_closeness']],
            'avoidant: touch at comfort 60'     => [['attachment' => 'avoidant', 'comfort' => 60], 10, ['touch'], 1.0, []],
            'avoidant: neglect loss x0.5'       => [['attachment' => 'avoidant'], -10, ['neglect'], 0.5, ['maturity_losses', 'avoidant_neglect']],
            'toxic: gains x1.4'                 => [['attachment' => 'toxic'], 10, ['help'], 1.4, ['toxic_all']],
            'toxic: losses x1.4'                => [['attachment' => 'toxic'], -10, ['insult'], 1.4, ['maturity_losses', 'toxic_all']],
            'egocentric: gift x1.5'             => [['traits' => ['egocentric']], 10, ['gift'], 1.5, ['egocentric_flattery']],
            'egocentric: praise x1.5'           => [['traits' => ['egocentric']], 10, ['praise'], 1.5, ['egocentric_flattery']],
            'egocentric: criticism x1.5'        => [['traits' => ['egocentric']], -10, ['criticism'], 1.5, ['maturity_losses', 'egocentric_slight']],
            'egocentric: outshone by help x0.8' => [['traits' => ['egocentric']], 10, ['help'], 0.8, ['egocentric_outshone']],
            'egocentric: competence x0.8'       => [['traits' => ['egocentric']], 10, ['competence'], 0.8, ['egocentric_outshone']],
            'no trait: gift x1.0'               => [[], 10, ['gift'], 1.0, []],
            'LL primary gifts: gift x2.0'       => [['ll_primary' => 'gifts'], 10, ['gift'], 2.0, ['love_language_primary']],
            'LL secondary words: praise x1.5'   => [['ll_secondary' => 'words_of_affirmation'], 10, ['praise'], 1.5, ['love_language_secondary']],
            'LL primary touch: intimacy x2.0'   => [['ll_primary' => 'physical_touch'], 10, ['intimacy'], 2.0, ['love_language_primary']],
            'LL never on losses'                => [['ll_primary' => 'gifts'], -10, ['gift'], 1.0, ['maturity_losses']],
            'resentment 60: gains x0.5'         => [['resentment' => 60], 10, ['help'], 0.5, ['resentment_blocks_gains']],
            'resentment 50: not above 50'       => [['resentment' => 50], 10, ['help'], 1.0, []],
            'stormy: losses x1.2'               => [['weather' => 'stormy'], -10, ['insult'], 1.2, ['maturity_losses', 'stormy_losses']],
            'stormy: gains untouched'           => [['weather' => 'stormy'], 10, ['help'], 1.0, []],
            // Product, then clamp 0.25..3.0
            'immature + jealous 65: 1.3 x 1.5'  => [['maturity' => 20, 'jealousy' => 65], -10, ['insult'], 1.95, ['maturity_losses', 'jealousy_losses']],
            'clamp at 3.0'                      => [['maturity' => 0, 'jealousy' => 100, 'attachment' => 'toxic'], -10, ['insult'], 3.0, ['maturity_losses', 'jealousy_losses', 'toxic_all']],
            'clamp at 0.25'                     => [['resentment' => 60, 'traits' => ['egocentric'], 'attachment' => 'avoidant', 'comfort' => 10], 10, ['help', 'touch'], 0.25, ['avoidant_closeness', 'egocentric_outshone', 'resentment_blocks_gains']],
        ];
    }

    #[DataProvider('modifierCases')]
    public function testAffinityModifierTable(array $npc, float $raw, array $tags, float $expectedM, array $expectedRows): void
    {
        $this->setConfig(['passion_enabled' => false]);   // passion row checked separately below
        $m = RelationshipDynamics::affinityModifiers($this->npc($npc), $raw, $tags);
        $this->assertEqualsWithDelta($expectedM, $m['M'], 1e-9);
        $this->assertEqualsCanonicalizing($expectedRows, array_keys($m['rows']));
    }

    public function testClampReportsTheUnclampedProduct(): void
    {
        $this->setConfig(['passion_enabled' => false]);
        // 1.5 (maturity 0) x 2.0 (jealousy 100) x 1.4 (toxic) = 4.2, clamped to 3.0
        $m = RelationshipDynamics::affinityModifiers($this->npc(['maturity' => 0, 'jealousy' => 100, 'attachment' => 'toxic']), -10, ['insult']);
        $this->assertEqualsWithDelta(4.2, $m['product'], 1e-9);
        $this->assertSame(3.0, $m['M']);
    }

    public static function passionCases(): array
    {
        // passion (0..100) -> gains x (0.3 + 0.017 * p): MDD 1.1 idle 0.3x, redline 2.0x
        return [[0.0, 0.3], [50.0, 1.15], [100.0, 2.0]];
    }

    #[DataProvider('passionCases')]
    public function testPassionDrivesAffinityGains(float $passion, float $expected): void
    {
        $m = RelationshipDynamics::affinityModifiers($this->npc(['passion' => $passion]), 10, ['help']);
        $this->assertEqualsWithDelta($expected, $m['M'], 1e-9);
        $this->assertArrayHasKey('passion_drives_gains', $m['rows']);
        $loss = RelationshipDynamics::affinityModifiers($this->npc(['passion' => $passion]), -10, ['insult']);
        $this->assertArrayNotHasKey('passion_drives_gains', $loss['rows'], 'passion scales gains only');
    }

    public function testSwitchedOffSubsystemsDoNotModify(): void
    {
        $this->setConfig(['passion_enabled' => false, 'internal_weather_enabled' => false,
                          'jealousy_enabled' => false, 'attachment_style_enabled' => false]);
        $npc = $this->npc(['weather' => 'stormy', 'jealousy' => 100, 'attachment' => 'anxious']);
        $m = RelationshipDynamics::affinityModifiers($npc, -10, ['neglect']);
        $this->assertSame(['maturity_losses'], array_keys($m['rows']));
        $this->assertEqualsWithDelta(1.0, $m['M'], 1e-9);
    }

    public function testTheTableIsReadFromConfig(): void
    {
        // Retuned without code changes: egocentric flattery 1.5 -> 2.5, stormy row removed.
        $rows = RelationshipDynamics::defaultConfig()['affinity_modifiers'];
        $rows = array_values(array_filter($rows, fn($r) => $r['id'] !== 'stormy_losses'));
        foreach ($rows as &$r) {
            if ($r['id'] === 'egocentric_flattery') $r['mult'] = 2.5;
        }
        unset($r);
        $this->setConfig(['passion_enabled' => false, 'affinity_modifiers' => $rows, 'affinity_modifier_max' => 2.0]);

        $m = RelationshipDynamics::affinityModifiers($this->npc(['traits' => ['egocentric']]), 10, ['gift']);
        $this->assertSame(2.0, $m['M'], 'config clamp max');
        $this->assertEqualsWithDelta(2.5, $m['product'], 1e-9);
        $storm = RelationshipDynamics::affinityModifiers($this->npc(['weather' => 'stormy']), -10, ['insult']);
        $this->assertEqualsWithDelta(1.0, $storm['M'], 1e-9);
    }

    public function testAnInvalidConfigRowIsLoggedAndSkipped(): void
    {
        $rows = RelationshipDynamics::defaultConfig()['affinity_modifiers'];
        $rows[] = ['id' => 'broken', 'sign' => 'sideways', 'mult' => 9.0];
        $this->setConfig(['passion_enabled' => false, 'affinity_modifiers' => $rows]);
        $m = RelationshipDynamics::affinityModifiers($this->npc(), 10, ['help']);
        $this->assertEqualsWithDelta(1.0, $m['M'], 1e-9);
        $this->assertStringContainsString("affinity_modifiers row 'broken'", (string) file_get_contents($this->errorLog));
    }

    // ------------------------------------------------------------------------------------
    // Traits take effect (npc-trait-tags): hasTrait/getTraits, incl. the per-NPC override
    // ------------------------------------------------------------------------------------

    public function testTraitOverrideIsWhatTheTableReads(): void
    {
        $this->setConfig(['passion_enabled' => false]);
        $npc = $this->npc(['traits' => ['egocentric']]);
        $npc['profile_overrides'] = ['traits' => []];   // editor removed the trait
        $this->assertEqualsWithDelta(1.0, RelationshipDynamics::affinityModifiers($npc, 10, ['praise'])['M'], 1e-9);

        $npc = $this->npc(['traits' => []]);
        $npc['profile_overrides'] = ['traits' => ['Egocentric']];   // editor added it
        $this->assertEqualsWithDelta(1.5, RelationshipDynamics::affinityModifiers($npc, 10, ['praise'])['M'], 1e-9);
    }

    // ------------------------------------------------------------------------------------
    // The per-signal pipeline: raw x R_temperament x P_maturity_type x M x distance decay
    // ------------------------------------------------------------------------------------

    /** Core affinity after the NPC's mirror moved (mirror units x2 = core units). */
    private function coreAffinityMoved(array $before, array $after): float
    {
        return (floatval($after['dimensions']['affinity']['x']) - floatval($before['dimensions']['affinity']['x'])) * 2.0;
    }

    public function testImmatureJealousNpcIsHurtMoreBySameInsult(): void
    {
        // Jealous: R_affinity 1.3 (MDD 15.4); Adaptive: P 1.0; core aff 20 = Jealous baseline, decay 1.
        $immature = $this->npc(['temperament' => 'Jealous', 'core_aff' => 20, 'maturity' => 20, 'jealousy' => 65]);
        $mature   = $this->npc(['temperament' => 'Jealous', 'core_aff' => 20, 'maturity' => 80, 'jealousy' => 0]);
        $a0 = $immature; $b0 = $mature;

        RelationshipDynamics::applyEvalSignal('Mjoll', $immature, 'affinity', -10, ['insult'], 1.0);
        RelationshipDynamics::applyEvalSignal('Mjoll', $mature, 'affinity', -10, ['insult'], 1.0);

        // -10 x 1.3 x 1.0 x (1.3 maturity x 1.5 jealousy = 1.95) = -25.35 core points
        $this->assertEqualsWithDelta(-25.35, $this->coreAffinityMoved($a0, $immature), 1e-3);
        // -10 x 1.3 x 1.0 x 0.7 (maturity 80) = -9.1
        $this->assertEqualsWithDelta(-9.1, $this->coreAffinityMoved($b0, $mature), 1e-3);
    }

    public function testEgocentricNpcTakesPraiseAtOneAndAHalfTimes(): void
    {
        // Proud: R_affinity 0.7; passion 50 -> gains x1.15; core aff 15 = Proud baseline.
        $ego   = $this->npc(['temperament' => 'Proud', 'core_aff' => 15, 'traits' => ['egocentric'], 'passion' => 50]);
        $plain = $this->npc(['temperament' => 'Proud', 'core_aff' => 15, 'traits' => [], 'passion' => 50]);
        $a0 = $ego; $b0 = $plain;

        RelationshipDynamics::applyEvalSignal('Nazeem', $ego, 'affinity', 10, ['praise'], 1.0);
        RelationshipDynamics::applyEvalSignal('Nazeem', $plain, 'affinity', 10, ['praise'], 1.0);

        $this->assertEqualsWithDelta(10 * 0.7 * 1.5 * 1.15, $this->coreAffinityMoved($a0, $ego), 1e-3);    // 12.075
        $this->assertEqualsWithDelta(10 * 0.7 * 1.15, $this->coreAffinityMoved($b0, $plain), 1e-3);         // 8.05
    }

    public function testAnxiousNpcLosesTwiceAsMuchToNeglect(): void
    {
        // Romantic: R_affinity 1.3; core aff 30 = Romantic baseline.
        $anxious = $this->npc(['temperament' => 'Romantic', 'core_aff' => 30, 'attachment' => 'anxious']);
        $secure  = $this->npc(['temperament' => 'Romantic', 'core_aff' => 30, 'attachment' => 'secure']);
        $a0 = $anxious; $b0 = $secure;

        RelationshipDynamics::applyEvalSignal('Ysolda', $anxious, 'affinity', -10, ['neglect'], 1.0);
        RelationshipDynamics::applyEvalSignal('Ysolda', $secure, 'affinity', -10, ['neglect'], 1.0);

        $this->assertEqualsWithDelta(-26.0, $this->coreAffinityMoved($a0, $anxious), 1e-3);
        $this->assertEqualsWithDelta(-13.0, $this->coreAffinityMoved($b0, $secure), 1e-3);
    }

    public function testMaturityTypeSetsDirection(): void
    {
        // Humble R_affinity: not in the MDD 15.4 table -> 1.0. Brittle: gains x0.7, losses x1.3.
        $up = $this->npc(['temperament' => 'Humble', 'core_aff' => 25, 'maturity_type' => 'Brittle', 'passion' => 50]);
        $down = $this->npc(['temperament' => 'Humble', 'core_aff' => 25, 'maturity_type' => 'Brittle']);
        $u0 = $up; $d0 = $down;
        RelationshipDynamics::applyEvalSignal('Hulda', $up, 'affinity', 10, ['help'], 1.0);
        RelationshipDynamics::applyEvalSignal('Hulda', $down, 'affinity', -10, ['insult'], 1.0);
        $this->assertEqualsWithDelta(10 * 0.7 * 1.15, $this->coreAffinityMoved($u0, $up), 1e-3);
        $this->assertEqualsWithDelta(-13.0, $this->coreAffinityMoved($d0, $down), 1e-3);
    }

    public function testDistanceDecayDampensAwayFromBaseline(): void
    {
        // Humble baseline 25 core; at core 50 a further gain is 25 core points away, Z 25:
        // decay = 1 / (1 + 25/25) = 0.5.  +10 x 1.0 x 1.0 x 1.15 (passion 50) x 0.5 = 5.75
        $npc = $this->npc(['temperament' => 'Humble', 'core_aff' => 50, 'passion' => 50]);
        $n0 = $npc;
        RelationshipDynamics::applyEvalSignal('Hulda', $npc, 'affinity', 10, ['help'], 1.0);
        $this->assertEqualsWithDelta(5.75, $this->coreAffinityMoved($n0, $npc), 1e-3);
    }

    public function testSignificanceClampsInCoreUnits(): void
    {
        // Anxious R 1.5 x Volatile P 1.5 x M 1.0 = -67.5 core points -> clamp +-30 x 1.0
        $npc = $this->npc(['temperament' => 'Anxious', 'core_aff' => 25, 'maturity_type' => 'Volatile']);
        $n0 = $npc;
        RelationshipDynamics::applyEvalSignal('Ysolda', $npc, 'affinity', -30, ['insult'], 1.0);
        $this->assertEqualsWithDelta(-30.0, $this->coreAffinityMoved($n0, $npc), 1e-3);

        // significance 0.1 -> +-3
        $npc = $this->npc(['temperament' => 'Anxious', 'core_aff' => 25, 'maturity_type' => 'Volatile']);
        $n0 = $npc;
        RelationshipDynamics::applyEvalSignal('Ysolda', $npc, 'affinity', -30, ['insult'], 0.1);
        $this->assertEqualsWithDelta(-3.0, $this->coreAffinityMoved($n0, $npc), 1e-3);
    }

    public function testResentmentHalvesGainsOnceNotTwice(): void
    {
        // Resentment > 50 is an M row (0.5). The older cross-signal cap in applyDelta must not
        // halve the same gain again. Humble at baseline, passion 50: +10 x 1.15 x 0.5 = 5.75
        $npc = $this->npc(['temperament' => 'Humble', 'core_aff' => 25, 'passion' => 50, 'resentment' => 60]);
        $n0 = $npc;
        RelationshipDynamics::applyEvalSignal('Hulda', $npc, 'affinity', 10, ['help'], 1.0);
        $this->assertEqualsWithDelta(5.75, $this->coreAffinityMoved($n0, $npc), 1e-3);
    }

    public function testProducerConsumerAndResentmentShareOneTagVocabulary(): void
    {
        // The contract's tag list, as the producer defines it for the model and the consumer
        // validates it: the same set in the same order.
        $this->assertSame(array_keys(RelDynEval::TAG_DEFINITIONS), RelationshipDynamics::EVAL_CONTRACT_TAGS);
        $this->assertSame(RelDynEval::CONTRACT_VERSION, RelationshipDynamics::EVAL_CONTRACT_VERSION);
        $this->assertSame(RelDynEval::SOURCE, RelationshipDynamics::EVAL_CONTRACT_SOURCE);
        $this->assertSame(array_keys(RelDynEval::SIGNAL_LIMITS), array_keys(RelationshipDynamics::EVAL_CONTRACT_SIGNALS));

        // Every tag the M table, the love-language map and the resentment/jealousy code key on
        // is a contract tag (a typo would silently never match).
        $cfg = RelationshipDynamics::defaultConfig();
        $used = [];
        foreach ($cfg['affinity_modifiers'] as $row) {
            foreach ((array) $row['tags'] as $t) $used["M:{$row['id']}"][] = $t;
        }
        $used['affinity_tag_love_language'] = array_keys($cfg['affinity_tag_love_language']);
        $used['jealousy_bystander_tags'] = $cfg['jealousy_bystander_tags'];
        $used['applyEvalFeelings'] = ['jealousy_trigger'];
        foreach ($used as $where => $tags) {
            $this->assertSame([], array_values(array_diff($tags, RelationshipDynamics::EVAL_CONTRACT_TAGS)), "{$where}: unknown tag");
        }
    }

    public function testResentmentAtSeventyFreezesEvalGainsAndLossesStillLand(): void
    {
        // MDD 15.5: resentment 70 = withdrawal, affinity frozen. M's 0.25 floor cannot express
        // 0, so on the eval path the cap applies getResentmentEffects()'s freeze (and only it).
        $npc = $this->npc(['temperament' => 'Humble', 'core_aff' => 25, 'passion' => 50, 'resentment' => 75]);
        $n0 = $npc;
        RelationshipDynamics::applyEvalSignal('Hulda', $npc, 'affinity', 10, ['help'], 1.0);
        $this->assertEqualsWithDelta(0.0, $this->coreAffinityMoved($n0, $npc), 1e-9, 'gain frozen');
        $this->assertStringContainsString('affinity gain x0', (string) file_get_contents($this->errorLog));

        // Losses are not frozen: Humble R 1.0, maturity 50 -> x1.0 = -10 core
        RelationshipDynamics::applyEvalSignal('Hulda', $npc, 'affinity', -10, ['insult'], 1.0);
        $this->assertEqualsWithDelta(-10.0, $this->coreAffinityMoved($n0, $npc), 1e-3);
    }

    // ---- relational dimensions + maturity (same pipeline, no M) -----------------------------

    public static function dimensionCases(): array
    {
        // [temperament, maturity type, signal, x, baseline, raw, expected actual (dimension points)]
        return [
            // Guarded trust R 0.4, Brittle up 0.7, at baseline: 10 x 0.4 x 0.7
            'trust gain, Guarded Brittle'      => ['Guarded', 'Brittle', 'trust', 20, 20, 10, 2.8],
            // away from baseline by 30, Z 30: decay 0.5
            'trust gain away from baseline'    => ['Guarded', 'Brittle', 'trust', 50, 20, 10, 1.4],
            // toward baseline: decay 1 + 30/30 = 2; -10 x 0.4 x 1.3 x 2
            'trust loss toward baseline'       => ['Guarded', 'Brittle', 'trust', 50, 20, -10, -10.4],
            // Playful comfort R 1.4, Volatile down 1.5
            'comfort loss, Playful Volatile'   => ['Playful', 'Volatile', 'comfort', 55, 55, -10, -21.0],
            // Proud respect R 1.5, Brittle down 1.3
            'respect loss, Proud Brittle'      => ['Proud', 'Brittle', 'respect', 25, 25, -10, -19.5],
            // Romantic passion R 1.3 (MDD 1.3 passion column), Growth up 1.3
            'passion gain, Romantic Growth'    => ['Romantic', 'Growth', 'passion', 0, 0, 10, 16.9],
            // Stoic maturity R 0.5, Resilient down 0.5; raw -25 is clamped to the contract's -10
            'maturity loss, clamped to -10'    => ['Stoic', 'Resilient', 'maturity', 55, 55, -25, -2.5],
            // Growth maturity gain: R Nurturing 0.8 x up 1.3 = 1.04 x 10
            'maturity gain, Nurturing Growth'  => ['Nurturing', 'Growth', 'maturity', 60, 60, 10, 10.4],
        ];
    }

    #[DataProvider('dimensionCases')]
    public function testRelationalDimensionsAndMaturityRunThePipeline(string $temperament, string $type, string $signal, float $x, float $baseline, float $raw, float $expected): void
    {
        $npc = $this->npc(['temperament' => $temperament, 'maturity_type' => $type, 'maturity' => 45,
                           'traits' => ['egocentric'], 'attachment' => 'anxious']);
        $npc['dimensions'][$signal]['x'] = $x;
        $npc['dimensions'][$signal]['baseline'] = $baseline;
        if ($signal === 'passion') $npc['passion'] = $x;
        if ($signal !== 'maturity') $npc['dimensions']['maturity']['x'] = 45;   // >= 30: no trust-gain halving
        if ($signal === 'maturity') $npc['dimensions']['comfort']['x'] = 50;     // >= 30: no maturity-gain halving

        $r = RelationshipDynamics::applyEvalSignal('Npc', $npc, $signal, $raw, ['praise', 'neglect'], 1.0);

        $this->assertEqualsWithDelta($expected, $r['actual'], 1e-3, "tags and traits must not modify {$signal} (M is affinity only)");
        $this->assertEqualsWithDelta($x + $expected, floatval($npc['dimensions'][$signal]['x']), 1e-3);
    }

    public function testMaturityTypeOverrideFromAnArcIsUsed(): void
    {
        // PR 10 plasticity override (e.g. Growth after an arc) beats the stored type while active.
        $npc = $this->npc(['temperament' => 'Stoic', 'maturity_type' => 'Rigid', 'maturity' => 55]);
        $npc['_plasticity_override'] = 'Volatile';
        $npc['_plasticity_override_expires_gamets'] = 5000;
        $npc['_last_gamets'] = 1000;
        $r = RelationshipDynamics::applyEvalSignal('Ashe', $npc, 'trust', -10, [], 1.0);
        // Stoic trust R 0.7 x Volatile down 1.5 at baseline
        $this->assertEqualsWithDelta(-10.5, $r['actual'], 1e-3);
    }

    // ------------------------------------------------------------------------------------
    // Contract items (shared eval contract v1)
    // ------------------------------------------------------------------------------------

    private function item(array $o = []): array
    {
        return array_replace([
            'v' => 1, 'npc' => 'Mjoll', 'npc_id' => 7, 'gamets' => 123456, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => -10, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['insult'],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 1.0,
            'positive_interaction' => false,
            'summary' => 'insulted her',
        ], $o);
    }

    /**
     * Decisions §1: M_modifiers = clamp(product, 0.25, 3.0) is the whole relative multiplier.
     * The low self-confidence amplification (x1.5 on losses) is a row of M, clamped with the
     * rest and logged, not an extra factor after the clamp.
     */
    public function testLowSelfConfidenceIsAnMRowInsideTheClamp(): void
    {
        $o = ['temperament' => 'Jealous', 'core_aff' => 20, 'attachment' => 'toxic', 'traits' => ['egocentric'],
              'maturity' => 0, 'jealousy' => 100];
        $moved = [];
        foreach ([50.0, 20.0] as $sc) {
            $npc = $this->npc($o);
            $npc['dimensions']['self_confidence'] = ['x' => $sc, 'baseline' => $sc];
            $n0 = $npc;
            $r = RelationshipDynamics::applyEvalSignal('Mjoll', $npc, 'affinity', -2.0, ['insult'], 1.0);
            $moved[$sc === 20.0 ? 'low' : 'normal'] = $this->coreAffinityMoved($n0, $npc);
            $this->assertStringContainsString('x decay/caps 1.000', $r['line'], "self_confidence {$sc}: nothing after M");
        }
        // Jealous R 1.3 x Adaptive P 1.0 x M 3.0 (1.5 x 2.0 x 1.4 x 1.5 [x 1.5] = 6.3 [9.45], clamped)
        $this->assertEqualsWithDelta(-2.0 * 1.3 * 3.0, $moved['normal'], 1e-3);
        $this->assertEqualsWithDelta(-2.0 * 1.3 * 3.0, $moved['low'], 1e-3, 'M never exceeds 3.0');

        $npc = $this->npc(['maturity' => 50]);
        $npc['dimensions']['self_confidence'] = ['x' => 20.0, 'baseline' => 20.0];
        $m = RelationshipDynamics::affinityModifiers($npc, -2.0, ['insult']);
        $this->assertSame(1.5, $m['rows']['low_self_confidence_losses'] ?? null, 'the row is visible in M');
        $this->assertArrayNotHasKey('low_self_confidence_losses', RelationshipDynamics::affinityModifiers($npc, 2.0, ['praise'])['rows'],
            'gains are not amplified');
    }

    /** A negative resentment delta is relief (MDD 15.5 decay), not criticism: never amplified. */
    public function testLowSelfConfidenceDoesNotSpeedUpResentmentDecay(): void
    {
        $low = $this->npc(['resentment' => 40.0]);
        $low['dimensions']['self_confidence'] = ['x' => 20.0, 'baseline' => 20.0];
        $normal = $this->npc(['resentment' => 40.0]);
        $normal['dimensions']['self_confidence'] = ['x' => 50.0, 'baseline' => 50.0];
        $a = RelationshipDynamics::applyDelta('resentment', $low, -1.0, 'Humble');
        $b = RelationshipDynamics::applyDelta('resentment', $normal, -1.0, 'Humble');
        $this->assertEqualsWithDelta($b, $a, 1e-9);
    }

    /** One tag -> love-language table: every tag the producer maps feeds the consumer's love-language rows. */
    public function testEveryTagMappedToALoveLanguageFeedsTheLoveLanguageRows(): void
    {
        $this->assertSame(RelDynEval::TAG_LOVE_LANGUAGE, RelationshipDynamics::defaultConfig()['affinity_tag_love_language'],
            'the producer constant and the consumer config default are the same table');
        foreach (RelDynEval::TAG_LOVE_LANGUAGE as $tag => $ll) {
            $primary = RelationshipDynamics::affinityModifiers($this->npc(['ll_primary' => $ll]), 5, [$tag]);
            $this->assertSame(2.0, $primary['rows']['love_language_primary'] ?? null, "{$tag} -> {$ll} primary x2.0");
            $secondary = RelationshipDynamics::affinityModifiers($this->npc(['ll_secondary' => $ll]), 5, [$tag]);
            $this->assertSame(1.5, $secondary['rows']['love_language_secondary'] ?? null, "{$tag} -> {$ll} secondary x1.5");
            $this->assertSame([$ll], RelDynEval::loveLanguagesForTags([$tag]));
        }
        foreach (['apology', 'reassurance'] as $tag) {
            $this->assertArrayHasKey($tag, RelDynEval::TAG_LOVE_LANGUAGE, "{$tag} is words of affirmation");
        }
    }

    public function testContractValidation(): void
    {
        $this->assertNotNull(RelationshipDynamics::normalizeEvalContractItem($this->item()));
        $this->assertNull(RelationshipDynamics::normalizeEvalContractItem(['affinity_delta' => 5]), 'legacy shape is not a contract item');
        $this->assertNull(RelationshipDynamics::normalizeEvalContractItem($this->item(['v' => 2])));
        $this->assertNull(RelationshipDynamics::normalizeEvalContractItem($this->item(['source' => 'core_eval'])));
        $this->assertNull(RelationshipDynamics::normalizeEvalContractItem($this->item(['signals' => 'x'])));
        $this->assertNull(RelationshipDynamics::normalizeEvalContractItem($this->item(['signals' => ['affinity' => 'lots']])));
        $this->assertNull(RelationshipDynamics::normalizeEvalContractItem($this->item(['significance' => null])));

        $n = RelationshipDynamics::normalizeEvalContractItem($this->item([
            'signals' => ['affinity' => 45, 'maturity' => -25, 'trust' => 3.6],
            'tags' => ['insult', 'Praise', 'teleport'],
            'significance' => 1.7,
        ]));
        $this->assertSame(30.0, $n['signals']['affinity'], 'affinity clamped to +-30');
        $this->assertSame(-10.0, $n['signals']['maturity'], 'maturity clamped to +-10');
        $this->assertSame(3.6, $n['signals']['trust']);
        $this->assertSame(['insult', 'praise'], $n['tags'], 'unknown tag dropped, case folded');
        $this->assertSame(1.0, $n['significance']);
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringContainsString("unknown tag 'teleport'", $log);
        $this->assertStringContainsString('affinity 45 outside -30..30', $log);
    }

    public function testContractItemAppliesEverySignalAndRecordsTheGrievance(): void
    {
        $npc = $this->npc(['temperament' => 'Stoic', 'core_aff' => 15, 'maturity_type' => 'Adaptive', 'maturity' => 55]);
        $npc['dimensions']['trust'] = ['x' => 35, 'baseline' => 35];
        $n0 = $npc;
        $item = $this->item([
            'signals' => ['affinity' => -10, 'trust' => -10, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => -4],
            'grievance' => ['flag' => true, 'kind' => 'broken_promise', 'severity' => 2],
        ]);

        $totals = RelationshipDynamics::processEvalContractItem('Mjoll', $item, $npc);

        // Stoic: R affinity 0.5, trust 0.7, maturity 0.5; Adaptive 1.0; maturity 55 -> losses x0.95
        $this->assertEqualsWithDelta(-10 * 0.5 * 0.95, $this->coreAffinityMoved($n0, $npc), 1e-3);
        $this->assertEqualsWithDelta(-10 * 0.5 * 0.95 / 2.0, $totals['affinity'], 1e-3, 'totals in dimension (mirror) units');
        $this->assertEqualsWithDelta(-7.0, $totals['trust'], 1e-3);
        $this->assertEqualsWithDelta(-2.0, $totals['maturity'], 1e-3);
        $this->assertArrayNotHasKey('comfort', $totals, 'zero signals are not applied');
        // The grievance goes through the resentment accumulator (applyEvalFeelings ->
        // recordGrievance) once, not onto the legacy pending list as well.
        $this->assertSame([], $npc['dimensions']['resentment']['pending_grievances'] ?? []);
        $logged = $npc['dimensions']['resentment']['grievance_log'] ?? [];
        $this->assertCount(1, $logged);
        $this->assertSame('broken_promise', $logged[0]['kind']);
        $this->assertSame(2, $logged[0]['severity']);
        $this->assertGreaterThan(0, (float) $npc['dimensions']['resentment']['x']);
    }

    /**
     * What the legacy eval path fed downstream, the contract path feeds too: last_reason /
     * last_delta per moved dimension (context <recent_emotional_shifts>), the dimensional
     * memory (confrontation / diary fuel) and the interaction significance level (1..3; the
     * diary's defining_moment fires at 3).
     */
    public function testContractItemFeedsReasonsMemoryAndSignificance(): void
    {
        unset($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE']);
        $npc = $this->npc(['temperament' => 'Stoic', 'core_aff' => 15]);
        $item = $this->item([
            'signals' => ['affinity' => -10, 'trust' => -6, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'summary' => 'Kaida broke her promise to Mjoll',
            'significance' => 1.0,
        ]);
        $totals = RelationshipDynamics::processEvalContractItem('Mjoll', $item, $npc);

        foreach (['affinity', 'trust'] as $dim) {
            $this->assertSame('Kaida broke her promise to Mjoll', $npc['dimensions'][$dim]['last_reason'] ?? null, $dim);
            $this->assertEqualsWithDelta($totals[$dim], (float) ($npc['dimensions'][$dim]['last_delta'] ?? 0), 1e-9, $dim);
        }
        $this->assertArrayNotHasKey('last_reason', $npc['dimensions']['comfort'], 'unmoved dimensions keep theirs');
        $mem = array_column($npc['dimensional_memory'] ?? [], 'reason', 'dim');
        $this->assertSame('Kaida broke her promise to Mjoll', $mem['trust'] ?? null);
        $this->assertSame('Kaida broke her promise to Mjoll', $mem['affinity'] ?? null);
        $this->assertSame(3, $GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] ?? null, 'significance 1.0 -> level 3 (defining moment)');

        unset($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE']);
        $npc2 = $this->npc();
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['significance' => 0.33, 'summary' => 'small talk']), $npc2);
        $this->assertSame(1, $GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE'] ?? null, '0.33 (a normal exchange, MDD +-10 of 30) -> level 1');
        unset($GLOBALS['RELDYN_INTERACTION_SIGNIFICANCE']);
    }

    public function testContractItemForAnotherNpcIsRejected(): void
    {
        $npc = $this->npc();
        $n0 = $npc;
        $this->assertSame([], RelationshipDynamics::processEvalContractItem('Lydia', $this->item(['npc' => 'Mjoll']), $npc));
        $this->assertSame($n0['dimensions'], $npc['dimensions']);
        $this->assertStringContainsString("item for 'Mjoll' in the inbox of 'Lydia'", (string) file_get_contents($this->errorLog));
    }

    public function testSameItemIsNeverAppliedTwice(): void
    {
        $npc = $this->npc(['temperament' => 'Humble', 'core_aff' => 25]);
        $n0 = $npc;
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(), $npc);
        $once = $npc['dimensions']['affinity']['x'];
        $this->assertSame([], RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(), $npc), 'a re-queued copy is skipped');
        $this->assertSame($once, $npc['dimensions']['affinity']['x']);
        $this->assertLessThan($n0['dimensions']['affinity']['x'], $once);
    }
}
