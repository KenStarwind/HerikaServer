<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql` stand-in: the stored RelDyn config row; every other read finds nothing. */
final class RelDynUReviewFixesDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'") ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Batch U review fixes (rulings 2026-10-01 §20), pure paths on real engine code:
 *   - a config row install.php stored BEFORE batch U (the defaults of reldyn-v0.22) holds three defaults batch U
 *     retired: cascade_decay 0.3, the four felt-text lines without the seen / heard word, and the post-intimacy
 *     table with the old drunk row. None of them was ever a choice; each reads as the current default (a row
 *     stamped since is a choice and stays);
 *   - a drunken night with an intimate encounter is uncertainty, not shame, from the scene AND from the sober
 *     diary (§20 #27): the diary asks no resentment_self and no trust cut for it; a drunken night without one
 *     keeps the diary's reflection as before;
 *   - hidden interest moves a romance (§20 #3): a drawn but shy NPC's moment counts in proportion to her
 *     unvoiced interest, though the eval saw no passion in what she showed.
 */
final class RelDynUReviewFixesTest extends TestCase
{
    private const NPC = 'Aela the Huntress';
    private const HOUR = RelationshipDynamics::GAMETS_PER_HOUR;
    private const T0 = 320 * RelationshipDynamics::GAMETS_PER_DAY + 20 * self::HOUR;

    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->store(RelationshipDynamics::defaultConfig());
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_u_review_fixes_test.log');
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdureview');
        $this->prevLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    private function store(array $row): void
    {
        $GLOBALS['db'] = new RelDynUReviewFixesDb($row);
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * The row install.php wrote at reldyn-v0.22 (f5cb7079): today's defaults with the three defaults batch U
     * retired put back, as that version's defaultConfig() returned them (schema 3). Leaf-diffed against the
     * real f5cb7079 defaults: nothing else of an old row differs but the sections batch U added, which the
     * modules read through per-key fallbacks (testEverySectionBatchUAddedReadsFromAnOldRow).
     */
    private static function rowOfV022(): array
    {
        $row = RelationshipDynamics::defaultConfig();
        $row['config_schema'] = 3;
        $row['cascade_decay'] = 0.3;
        $row['cascade']['felt_text'] = [
            'ally_hurt'    => "{NAME} has heard what {PLAYER} did to {SOURCE}{REASON}; it sits badly, and {NAME} is cooler toward {PLAYER} for it.",
            'ally_helped'  => "{NAME} has heard what {PLAYER} did for {SOURCE}{REASON}; {NAME} thinks better of {PLAYER} for it.",
            'rival_hurt'   => "{NAME} has heard what {PLAYER} did to {SOURCE}{REASON}; {NAME} is not sorry for {SOURCE}, and warms to {PLAYER} for it.",
            'rival_helped' => "{NAME} has heard what {PLAYER} did for {SOURCE}{REASON}; {NAME} resents it, and is cooler toward {PLAYER} for it.",
        ];
        $outcomes = $row['post_intimacy']['outcomes'];
        unset($outcomes['drunk_uncertainty']);
        $outcomes['drunk_regret'] = [
            'held' => ['comfort' => 10.0], 'lasting' => [], 'valence' => 10.0,
            'correction' => ['comfort' => -15.0, 'trust' => -5.0, 'resentment_self' => 10.0],
            'correction_casual' => ['comfort' => -20.0, 'resentment_self' => 12.0],
            'correction_valence' => -20.0,
            'glow' => 'relaxed, laughing easily, guards down in a way the sober self would not allow',
            'after' => 'avoids their eyes, flinches at a casual touch, hides behind distance and brisk professionalism',
        ];
        $row['post_intimacy']['outcomes'] = $outcomes;
        unset($row['post_intimacy']['uncertainty']);
        return $row;
    }

    // ------------------------------------------------------------------ an old install row

    public function testTheHearsayDampingOfAnOldRowIsTheRetiredDefaultNotAChoice(): void
    {
        $this->store(self::rowOfV022());
        $this->assertEqualsWithDelta(0.9, floatval(RelationshipDynamics::configValue('cascade_decay')), 1e-9, 'the default of today');
        $this->assertSame(-8.0, RelDynCascade::rippleFor(-10.0, 80.0, null, 'witnessed'));
        $this->assertSame(-7.2, RelDynCascade::rippleFor(-10.0, 80.0, null, 'delayed'), 'news told or late loses a tenth, not seven tenths');
        $this->assertSame(-7.2, RelDynCascade::rippleFor(-10.0, 80.0, null, 'told'));
        $this->assertSame(RelationshipDynamics::CONFIG_SCHEMA, RelationshipDynamics::loadStoredConfig()['config_schema'], 'migrated in memory: a save stamps the current schema');
    }

    public function testADampingChosenSinceTheStampIsKeptAndSoIsAHandEditedLine(): void
    {
        $row = self::rowOfV022();
        $row['config_schema'] = RelationshipDynamics::CONFIG_SCHEMA;
        $this->store($row);
        $this->assertEqualsWithDelta(0.3, floatval(RelationshipDynamics::configValue('cascade_decay')), 1e-9, 'a row stamped since is a choice');
        $this->assertSame(-2.4, RelDynCascade::rippleFor(-10.0, 80.0, null, 'delayed'));
        // a line someone rewrote is theirs in an old row too; only the shipped text is the retired default
        $row = self::rowOfV022();
        $row['cascade']['felt_text']['ally_hurt'] = '{NAME} knows what {PLAYER} did to {SOURCE}.';
        $this->store($row);
        $text = RelDynCascade::config()['felt_text'];
        $this->assertSame('{NAME} knows what {PLAYER} did to {SOURCE}.', $text['ally_hurt']);
        $this->assertStringContainsString('{HEARD}', $text['ally_helped'], 'the shipped ones are the current default');
    }

    public function testAnOldRowsFeltTextSaysSeenToAWitness(): void
    {
        $this->store(self::rowOfV022());
        foreach (['ally_hurt', 'ally_helped', 'rival_hurt', 'rival_helped'] as $k) {
            $this->assertSame(RelDynCascade::configDefaults()['felt_text'][$k], RelDynCascade::config()['felt_text'][$k], $k);
        }
    }

    public function testAnOldRowsPostIntimacyTableStillHasTheDrunkRowAndNeverBringsShameBack(): void
    {
        $this->store(self::rowOfV022());
        $cfg = RelDynPostIntimacy::config();
        $this->assertArrayHasKey(RelDynPostIntimacy::DRUNK, $cfg['outcomes']);
        $this->assertSame(['comfort' => 10.0], $cfg['outcomes'][RelDynPostIntimacy::DRUNK]['held']);
        $this->assertArrayNotHasKey('correction', $cfg['outcomes'][RelDynPostIntimacy::DRUNK], 'the retired shame table is not read');
        $this->assertArrayNotHasKey('correction_casual', $cfg['outcomes'][RelDynPostIntimacy::DRUNK]);
        $this->assertSame(RelDynPostIntimacy::configDefaults()['uncertainty'], $cfg['uncertainty']);
        // a row's own other outcome rows are its own
        $row = self::rowOfV022();
        $row['post_intimacy']['outcomes'][RelDynPostIntimacy::CASUAL]['glow'] = 'her own words';
        $this->store($row);
        $this->assertSame('her own words', RelDynPostIntimacy::config()['outcomes'][RelDynPostIntimacy::CASUAL]['glow']);
    }

    public function testADrunkenNightOnAnOldRowStillHasItsAfterglowAndItsWobble(): void
    {
        $fresh = $this->drunkNightScene();
        $this->store(self::rowOfV022());
        $old = $this->drunkNightScene();
        $this->assertSame(RelDynPostIntimacy::DRUNK, $old['outcome']);
        $this->assertSame($fresh['held'], $old['held'], 'the same afterglow');
        $this->assertNotSame([], $old['held'], 'held comfort');
        $this->assertSame($fresh['comfort_after'], $old['comfort_after'], 'the same comfort: the night is not all negative');
        $this->assertNotNull($old['felt'], 'her glow reaches the words');
        $this->assertSame($fresh['felt']['text'], $old['felt']['text']);
        $this->assertSame($fresh['wobble'], $old['wobble'], 'and the sober wobble as on a new install');
    }

    /** Aela, intoxicated, a scene; the encounter's held comfort, her comfort, the felt text and the sober morning's wobble. */
    private function drunkNightScene(): array
    {
        $d = $this->npc();
        for ($i = 0; $i < 5; $i++) RelDynSubstances::onConsume(self::NPC, $d, 'ale', $this->clock(self::T0 + $i));
        $t = $this->clock(self::T0 + self::HOUR);
        $req = ['ext_nsfw_sexcene', '1727000000', (string) (int) $t, 'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/' . self::NPC . '^sub,vaginal'];
        RelDynPostIntimacy::onIntimateRequest(self::NPC, $d, $req, 'Kaida', $t);
        $enc = $d[RelDynPostIntimacy::KEY];
        $felt = RelDynPostIntimacy::feltText($d, $t);
        $comfortAfter = self::x($d, 'comfort');
        $this->clock(self::T0 + 8 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $this->clock(self::T0 + 8 * self::HOUR));
        RelDynPostIntimacy::tick(self::NPC, $d, $this->clock(self::T0 + 8 * self::HOUR));
        return ['outcome' => $enc['outcome'], 'held' => $enc['held'], 'felt' => $felt, 'comfort_after' => round($comfortAfter, 4),
                'wobble' => round(floatval($d[RelDynPostIntimacy::WOBBLE_KEY]['comfort'] ?? 0.0), 4)];
    }

    public function testEverySectionBatchUAddedReadsFromAnOldRow(): void
    {
        $row = self::rowOfV022();
        foreach (['jealousy_bystander', 'keeping'] as $k) unset($row[$k]);
        unset($row['attraction']['interest'], $row['attraction']['spike_prereq'], $row['cascade']['delivery'],
            $row['diary_reflection']['prompt'], $row['exclusivity']['core_damping'], $row['exclusivity']['core_gain_floor'],
            $row['fulfillment']['shared_fight'], $row['love_language_attachment']['choices'],
            $row['protocols']['ick']['hidden_interest_min'], $row['protocols']['ick']['hidden_interest_moods'],
            $row['protocols']['ick']['hidden_interest_threshold_cap'], $row['protocols']['ick']['hidden_interest_threshold_gain']);
        $this->store($row);
        $d = RelationshipDynamics::defaultConfig();
        $this->assertSame($d['attraction']['interest'], RelDynAttraction::interestConfig());
        $this->assertSame($d['attraction']['spike_prereq'], RelDynAttraction::spikePrereqConfig());
        $this->assertSame($d['cascade']['delivery'], RelDynCascade::config()['delivery']);
        $this->assertSame($d['diary_reflection']['prompt'], RelDynDiary::config()['prompt']);
        $this->assertSame(true, RelDynExclusivity::config()['core_damping']);
        $this->assertSame($d['fulfillment']['shared_fight'], RelDynFulfillment::sharedFightConfig());
        $this->assertSame($d['protocols']['ick']['hidden_interest_moods'], RelDynProtocols::config()['ick']['hidden_interest_moods']);
        $this->assertSame($d['jealousy_bystander'], RelationshipDynamics::configValue('jealousy_bystander'));
        $this->assertSame($d['keeping'], RelationshipDynamics::configValue('keeping'));
    }

    // ------------------------------------------------------------------ §20 #27: the diary is uncertainty too

    private function clock(float $t): float
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) round($t), 'Kaida: hm'];
        return (float) (int) round($t);
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x']);
    }

    /** A Bold huntress at maturity 65, a friend she is not drawn to soberly (her curve 0.25). */
    private function npc(): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Bold',
            '_attraction' => ['enabled' => true, 'attracted' => false, 'won_over' => false, 'passion' => ['curve' => 0.25]],
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, 'friend');
        $d['dimensions']['maturity']['x'] = 65.0;
        $d['dimensions']['maturity']['baseline'] = 65.0;
        $d['dimensions']['affinity']['x'] = 60.0;
        $d['_aff_mirror_x'] = 60.0;
        RelationshipDynamics::setPassion($d, 30.0);
        return $d;
    }

    /** Five ales, the flirting at the fourth mug (the ledger: affinity, trust, comfort, passion), and a scene when $scene. */
    private function night(array &$d, bool $scene): void
    {
        for ($i = 0; $i < 5; $i++) RelDynSubstances::onConsume(self::NPC, $d, 'ale', $this->clock(self::T0 + $i));
        RelDynSubstances::update(self::NPC, $d, $this->clock(self::T0 + 0.1 * self::HOUR));
        RelDynSubstances::afterEvalItem(self::NPC, ['tags' => ['touch'], 'romantic_intent' => 2],
            ['affinity' => 2.5, 'trust' => 3.0, 'comfort' => 5.0, 'passion' => 4.0], $d, $this->clock(self::T0 + 0.2 * self::HOUR));
        $this->assertCount(1, $d['_substances']['nights'] ?? [], 'the drunk self\'s night is in the ledger');
        if ($scene) {
            $t = $this->clock(self::T0 + self::HOUR);
            $req = ['ext_nsfw_sexcene', '1727000000', (string) (int) $t, 'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/' . self::NPC . '^sub,vaginal'];
            $this->assertSame(RelDynPostIntimacy::DRUNK, RelDynPostIntimacy::onIntimateRequest(self::NPC, $d, $req, 'Kaida', $t));
        }
    }

    public function testTheSoberDiaryOfADrunkenNightWithASceneIsUncertaintyNotShame(): void
    {
        $d = $this->npc();
        $this->night($d, true);
        $t = $this->clock(self::T0 + 6.2 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $rs = self::x($d, 'resentment_self');
        $trust = self::x($d, 'trust');
        $r = array_values(RelDynSubstances::soberReflection(self::NPC, $d, 'examination'))[0];
        $this->assertLessThan(0.5, $r['endorsed'], 'she does not stand behind it soberly');
        $this->assertArrayNotHasKey('resentment_self', $r['asked'], 'no shame was asked of this night');
        $this->assertArrayNotHasKey('resentment_self', $r['applied']);
        $this->assertArrayNotHasKey('trust', $r['asked'], 'and no trust cut');
        $this->assertEqualsWithDelta($rs, self::x($d, 'resentment_self'), 1e-9);
        $this->assertEqualsWithDelta($trust, self::x($d, 'trust'), 1e-9);
        $this->assertTrue($r['uncertain'] ?? false, 'the diary knows the night was one of the scene\'s uncertainty');
        // what is hers to regret of the flirting stays, and the morning's wobble comes as ever
        $this->assertLessThan(0.0, floatval($r['applied']['affinity'] ?? 0.0), 'the flirting\'s affinity is still taken back');
        $this->assertLessThan(0.0, floatval($r['applied']['passion'] ?? 0.0));
        $this->assertSame([], (array) ($d['_substances']['shame'][array_key_first($d['_substances']['shame'])]['verdicts'] ?? []), 'no shame verdict on the night');
    }

    public function testADrunkenNightWithoutASceneKeepsTheDiarysReflection(): void
    {
        $d = $this->npc();
        $this->night($d, false);
        $t = $this->clock(self::T0 + 6.2 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $rs = self::x($d, 'resentment_self');
        $trust = self::x($d, 'trust');
        $r = array_values(RelDynSubstances::soberReflection(self::NPC, $d, 'examination'))[0];
        $this->assertGreaterThan(0.0, floatval($r['applied']['resentment_self'] ?? 0.0), 'drunken flirting she does not endorse: the reflection is as before');
        $this->assertGreaterThan($rs, self::x($d, 'resentment_self'));
        $this->assertLessThan($trust, self::x($d, 'trust'));
        $this->assertArrayNotHasKey('uncertain', $r);
    }

    public function testTheMarkIsSpentWithTheNight(): void
    {
        $d = $this->npc();
        $this->night($d, true);
        RelDynSubstances::update(self::NPC, $d, $this->clock(self::T0 + 6.2 * self::HOUR));
        RelDynSubstances::soberReflection(self::NPC, $d, 'examination');
        $this->assertArrayNotHasKey('uncertain', (array) $d['_substances'], 'nothing left of the night');
    }

    // ------------------------------------------------------------------ §20 #3: hidden interest moves a romance

    private function moment(array $over = []): array
    {
        return array_merge(['tags' => ['quality_time'], 'significance' => 0.6, 'positive_interaction' => true,
            'signals' => ['passion' => 0.0, 'affinity' => 2.0], 'summary' => 'An evening by the fire.', 'gamets' => 1.0], $over);
    }

    /** Drawn (attracted, passion $passion) with the self-confidence the dimension holds at $confidence. */
    private function drawn(float $passion, float $confidence, bool $attracted = true): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        RelationshipDynamics::setPassion($d, $passion);
        $d['dimensions']['self_confidence']['x'] = $confidence;
        $d['_attraction'] = ['enabled' => true, 'attracted' => $attracted, 'hard_zero' => null];
        return $d;
    }

    private static function pending(array $d): array
    {
        return array_values((array) ($d['_romance']['pending'] ?? []));
    }

    public function testADrawnButShyNpcsEveningCountsTowardTheRomanceThoughNoPassionShowed(): void
    {
        $shy = $this->drawn(40.0, 15.0);
        RelDynRomance::noteMoment($shy, $this->moment());
        $p = self::pending($shy);
        $this->assertCount(1, $p, 'a moment');
        $this->assertSame('moment', $p[0]['kind']);
        $this->assertGreaterThan(0.0, $p[0]['weight']);
        $this->assertLessThan(0.6, $p[0]['weight'], 'less than a moment she showed: only what is unvoiced counts');
        $interest = RelDynAttraction::interest($shy)['interest'];
        $this->assertEqualsWithDelta(0.6 * $interest * RelDynAttraction::shyness($shy), $p[0]['weight'], 1e-6, 'her interest x how shy she is x the moment');
    }

    public function testHowMuchOfItCountsIsWhoSheIs(): void
    {
        $weight = function (float $confidence, float $passion = 40.0): float {
            $d = $this->drawn($passion, $confidence);
            RelDynRomance::noteMoment($d, $this->moment());
            return floatval(self::pending($d)[0]['weight'] ?? 0.0);
        };
        $this->assertGreaterThan($weight(40.0), $weight(15.0), 'the shyer she is, the more of her pull is hidden');
        $this->assertGreaterThan($weight(15.0, 10.0), $weight(15.0, 45.0), 'and the more drawn she is');
        $this->assertSame(0.0, $weight(50.0), 'one who shows what she feels has nothing hidden: her passion signal is the whole of it');
    }

    public function testNoInterestNoHiddenMomentAndAShownOneIsWholeAsBefore(): void
    {
        $cold = $this->drawn(40.0, 15.0, false);
        RelDynRomance::noteMoment($cold, $this->moment());
        $this->assertSame([], self::pending($cold), 'not drawn: a moment with no passion in it is none');
        $shown = $this->drawn(40.0, 15.0);
        RelDynRomance::noteMoment($shown, $this->moment(['signals' => ['passion' => 2.0, 'affinity' => 2.0]]));
        $this->assertSame(0.6, self::pending($shown)[0]['weight'], 'the passion she showed counts whole');
        $quiet = $this->drawn(40.0, 15.0);
        RelDynRomance::noteMoment($quiet, $this->moment(['significance' => 0.3]));
        $this->assertSame([], self::pending($quiet), 'a small moment is still small');
        $sour = $this->drawn(40.0, 15.0);
        RelDynRomance::noteMoment($sour, $this->moment(['positive_interaction' => false]));
        $this->assertSame('setback', self::pending($sour)[0]['kind'], 'hidden interest never softens a setback');
    }
}
