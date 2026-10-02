<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';
require_once __DIR__ . '/RelDynWSocialDutyTest.php';   // the config / gender stand-in $db

/**
 * Cascade extensions (roadmap cascade-extensions; Ken 2026-10-01 §24: friend of a friend, love triangles, the name
 * lookup), the pure parts: the lean of a friend or a foe of someone close to the player, how likely the NPC is to know
 * of the tie, who the NPC is (nobody is a wall), the cap, the settling, the pressure of a triangle, which names a request
 * names, the words. The test beds through the real hooks are RelDynWSocialCircleTestBedsPostgresTest.
 *
 * Units: core affinity points -100..100; interest, pressure, knowledge, susceptibility unitless.
 */
final class RelDynWSocialCircleTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 100 * RelationshipDynamics::GAMETS_PER_DAY;

    private array $saved = [];
    private string $logFile = '';
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynPronouns::reset();
        RelDynTraits::$assignmentOverride = 'read';
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdwcircle');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        $log = (string) @file_get_contents($this->logFile);
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelDynPronouns::reset();
        $this->assertStringNotContainsString('ERROR', $log, $log);
    }

    private function storeConfig(array $row): void
    {
        $db = new RelDynWSocialFakeDb();
        $db->rows[RelationshipDynamics::CONFIG_ROW_ID] = json_encode($row + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC: a hand-set trait vector over the middle, maturity, no state otherwise. */
    private function npc(array $traits = [], float $maturity = 50.0): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], $traits);
        $x['maturity_start'] = $maturity;
        return [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            'dimensions' => ['maturity' => ['x' => $maturity, 'baseline' => $maturity]],
        ];
    }

    // ------------------------------------------------------------------ friend of a friend

    public function testAFriendOfSomeoneCloseToThePlayerWarmsAndAFoeOfTheirsCoolsAndTheReadingGoesOneStepFurther(): void
    {
        $lean = fn(float $bond, float $closeness) => RelDynCascadeExt::leanOf($bond, $closeness);
        $this->assertGreaterThan(0.0, $lean(70, 70), 'A likes B and B is close to the player: A warms');
        $this->assertLessThan(0.0, $lean(-70, 70), 'A dislikes B and B is close to the player: A cools');
        $this->assertLessThan(0.0, $lean(70, -70), 'a friend of someone at odds with the player cools');
        $this->assertGreaterThan(0.0, $lean(-70, -70), 'an enemy of their enemy warms');
        // a foe of B reads weaker than a friend, and B at odds weaker than B close
        $this->assertEqualsWithDelta(abs($lean(70, 70)) * 0.5, abs($lean(-70, 70)), 1e-9, 'enemy_mult');
        $this->assertEqualsWithDelta(abs($lean(70, 70)) * 0.6, abs($lean(70, -70)), 1e-9, 'foe_weight');
        $this->assertEqualsWithDelta(abs($lean(70, 70)) * 0.5 * 0.6, abs($lean(-70, -70)), 1e-9);
        // stronger bonds, stronger lean
        $this->assertGreaterThan($lean(40, 70), $lean(90, 70));
        $this->assertGreaterThan($lean(70, 40), $lean(70, 90));
        // a stranger to A does not cascade, and a B who barely knows the player says nothing
        $this->assertSame(0.0, $lean(30, 70), 'at the minimum bond: not past it');
        $this->assertSame(0.0, $lean(70, 24), 'under the minimum closeness');
        $this->assertGreaterThan(0.0, $lean(31, 25));
        // one link at the top of both scales is within the whole association's cap
        $this->assertEqualsWithDelta(14.0, $lean(100, 100), 1e-9);
    }

    public function testKnowingOfTheTieDependsOnTheBondAndTheDistanceAndNeverFallsToNothing(): void
    {
        $k = fn(float $bond, int $steps) => RelDynCascadeExt::knowledgeOf($bond, $steps);
        $this->assertEqualsWithDelta(0.25 + 0.5 * 0.7, $k(70, 0), 1e-9, 'the same hold: floor + bond');
        $this->assertGreaterThan($k(40, 0), $k(90, 0), 'a closer friend hears more');
        $this->assertGreaterThan($k(70, 2), $k(70, 0), 'across the map, less');
        $this->assertGreaterThan($k(70, 4), $k(70, 2));
        $this->assertGreaterThanOrEqual(0.25, $k(35, 9), 'word gets round: never below the floor');
        $this->assertSame($k(-70, 1), $k(70, 1), 'a foe is as well informed as a friend');
        $this->assertLessThanOrEqual(1.0, $k(100, 0));
    }

    public function testWhoTheNPCIsSetsHowEasilyTheyAreSwayedAndNoOneIsImmune(): void
    {
        $s = fn(array $t = [], float $m = 50.0) => RelDynCascadeExt::susceptibility($this->npc($t, $m));
        $middle = $s();
        $this->assertGreaterThan(0.9, $middle);
        $this->assertLessThan($middle, $s(['G' => 1.0]), 'a guarded NPC makes up their own mind');
        $this->assertLessThan($middle, $s(['C' => 1.0]), 'so does a self-assured one');
        $this->assertLessThan($middle, $s(['Pd' => 1.0]), 'and a proud one');
        $this->assertGreaterThan($middle, $s(['W' => 1.0]), 'a warm one is swayed by those they love');
        $this->assertGreaterThan($middle, $s(['G' => 0.0, 'C' => 0.0]), 'an open, unsure one most of all');
        $this->assertLessThan($middle, $s([], 90.0), 'a mature NPC is steadier');
        $this->assertGreaterThan($middle, $s([], 10.0), 'an immature one is swayed more');
        // the steadiest there is: guarded, self-assured, proud, cool and fully mature: still not a wall
        $steadiest = $s(['G' => 1.0, 'C' => 1.0, 'Pd' => 1.0, 'W' => 0.0], 100.0);
        $this->assertGreaterThanOrEqual(0.3, $steadiest, 'susceptibility.floor');
        $this->assertGreaterThan(0.0, $steadiest);
        $this->assertLessThanOrEqual(1.6, $s(['W' => 1.0, 'G' => 0.0, 'C' => 0.0, 'Pd' => 0.0], 0.0));
    }

    public function testTheWholeAssociationIsSoftenedTowardTheCapAndSettlesOnTheGameCalendar(): void
    {
        $t = fn(float $sum, float $susc = 1.0) => RelDynCascadeExt::targetOf($sum, $susc);
        $this->assertEqualsWithDelta(0.0, $t(0.0), 1e-9);
        $this->assertGreaterThan($t(2.0), $t(5.0));
        $this->assertLessThanOrEqual(12.0, $t(1000.0), 'never past the cap');
        $this->assertLessThan($t(5.0) - $t(4.0), $t(9.0) - $t(8.0), 'the fifth friend adds less than the first');
        $this->assertEqualsWithDelta(-$t(5.0), $t(-5.0), 1e-9, 'symmetric: a foe cools by what a friend warms');
        $this->assertEqualsWithDelta($t(5.0) * 0.5, $t(5.0, 0.5), 1e-9, 'times who the NPC is');
        // it settles: this share of the gap per game hour
        $rates = ['per_game_hour' => 0.15];
        $one = RelDynCascadeExt::settle(0.0, 10.0, 1.0, $rates);
        $this->assertEqualsWithDelta(10.0 * (1 - exp(-0.15)), $one, 1e-9);
        $this->assertGreaterThan($one, RelDynCascadeExt::settle(0.0, 10.0, 6.0, $rates));
        $this->assertLessThan(10.0, RelDynCascadeExt::settle(0.0, 10.0, 100.0, $rates));
        $this->assertEqualsWithDelta(4.0, RelDynCascadeExt::settle(4.0, 4.0, 10.0, $rates), 1e-9, 'at target: nothing moves');
        $this->assertLessThan(4.0, RelDynCascadeExt::settle(4.0, 0.0, 3.0, $rates), 'and back toward zero when the tie is gone');
        $this->assertSame(4.0, RelDynCascadeExt::settle(4.0, 0.0, 0.0, $rates), 'no time, no change');
    }

    // ------------------------------------------------------------------ love triangles

    public function testATriangleNeedsBothToWantThePlayerAndItsPressureIsTheProduct(): void
    {
        $this->assertSame(0.0, RelDynCascadeExt::pressureOf(0.9, 0.2), 'the other barely wants the player: no triangle');
        $this->assertSame(0.0, RelDynCascadeExt::pressureOf(0.2, 0.9));
        $this->assertEqualsWithDelta(0.5 * 0.8, RelDynCascadeExt::pressureOf(0.5, 0.8), 1e-9);
        $this->assertGreaterThan(RelDynCascadeExt::pressureOf(0.4, 0.4), RelDynCascadeExt::pressureOf(0.9, 0.9));
        $this->assertLessThanOrEqual(1.0, RelDynCascadeExt::pressureOf(1.0, 1.0));
        $this->assertGreaterThan(0.0, RelDynCascadeExt::pressureOf(0.3, 0.3), 'the threshold itself counts: a shy hidden interest is a rival');
    }

    public function testWhoTheNPCIsDecidesHowHardARivalryLandsAndNoOneIsImmune(): void
    {
        $d = fn(array $t = [], float $m = 50.0) => RelDynCascadeExt::rivalDisposition($this->npc($t, $m));
        $middle = $d();
        $this->assertGreaterThan($middle, $d(['Po' => 1.0]), 'possessive');
        $this->assertGreaterThan($middle, $d(['Pd' => 1.0]), 'proud');
        $this->assertGreaterThan($middle, $d(['L' => 1.0]), 'reactive');
        $this->assertGreaterThan($middle, $d([], 10.0), 'immature');
        $this->assertLessThan($middle, $d(['Po' => 0.0, 'Pd' => 0.0, 'L' => 0.0]), 'secure and easy-going');
        $this->assertLessThan($middle, $d([], 95.0), 'mature');
        $floor = $d(['Po' => 0.0, 'Pd' => 0.0, 'L' => 0.0], 100.0);
        $this->assertGreaterThanOrEqual(0.3, $floor);
        $this->assertGreaterThan(0.0, $floor, 'the steadiest still feels it');
        $this->assertLessThanOrEqual(1.8, $d(['Po' => 1.0, 'Pd' => 1.0, 'L' => 1.0], 0.0));
    }

    // ------------------------------------------------------------------ the name lookup

    public function testWhatThePlayerSaidIsReadFromTheInputRowAndOnlyFromAPlayersLine(): void
    {
        $row = ['inputtext', '1', '2', 'Kaida: I saw Nazeem at the market today. (Talking to Lynly Star-Sung)'];
        $this->assertSame('I saw Nazeem at the market today.', RelDynCascadeExt::playerWords($row, 'Kaida'));
        $this->assertSame('', RelDynCascadeExt::playerWords(['rechat', '1', '2', 'x'], 'Kaida'), 'an NPC-to-NPC exchange is not the player speaking');
        $this->assertSame('', RelDynCascadeExt::playerWords(null, 'Kaida'));
        $this->assertSame('Hello.', RelDynCascadeExt::playerWords(['inputtext', '1', '2', 'Hello.'], 'Kaida'), 'a row with no speaker prefix');
    }

    public function testANameIsFoundWholeOrByAFirstWordThatNamesOneBondOnlyAndNeverTheNPCOrThePlayer(): void
    {
        $bonds = ['Farkas', 'Aela the Huntress', 'Nazeem', 'Jon Battle-Born', 'Jon Snow', 'Ashe', 'Kaida', 'Lynly Star-Sung'];
        $named = fn(string $text) => RelDynCascadeExt::namedIn($text, $bonds, 'Lynly Star-Sung', 'Kaida');
        $this->assertSame(['Farkas'], $named('I ran into Farkas.'));
        $this->assertSame(['Aela the Huntress'], $named('Aela the Huntress sends word.'), 'the whole name');
        $this->assertSame(['Aela the Huntress'], $named('Have you heard what Aela said?'), 'a first word that names one bond only');
        $this->assertSame([], $named('Jon was there.'), 'a first word two bonds share names neither');
        $this->assertSame(['Jon Battle-Born'], $named('Jon Battle-Born was there.'));
        $this->assertSame([], $named('The ashes are cold.'), 'a name inside a word is no name');
        $this->assertSame(['Ashe'], $named('Ashe came with me.'));
        $this->assertSame([], $named('Kaida says hello. Lynly Star-Sung too.'), 'never the player, never the NPC themself');
        $this->assertSame(['Farkas', 'Nazeem'], $named('Farkas and Nazeem argued.'));
        $this->assertSame([], $named(''));
    }

    public function testTheMentionLineFollowsHowTheNPCFeelsAboutTheNamedAndIsSaidOnce(): void
    {
        $mk = function (float $bond, bool $pending = true): array {
            $d = $this->npc();
            $d[RelDynCascadeExt::KEY] = ['mentions' => ['farkas' => ['name' => 'Farkas', 'at' => self::T0, 'bond' => $bond, 'felt_at' => self::T0, 'pending' => $pending]]];
            return $d;
        };
        foreach ([[70.0, 'fond'], [20.0, 'wary'], [-20.0, 'cool'], [-70.0, 'hostile']] as [$bond, $kind]) {
            $d = $mk($bond);
            $r = RelDynCascadeExt::feltLines($d, 'Lynly Star-Sung', 'Kaida', ['Lynly Star-Sung', 'Kaida'], true);
            $this->assertCount(1, $r['lines'], $kind);
            $this->assertSame('mention', $r['lines'][0]['key']);
            $this->assertSame('turn', $r['lines'][0]['lane']);
            $this->assertStringContainsString('Kaida brought up Farkas, who is not here', $r['lines'][0]['text']);
            $this->assertStringContainsString(['fond' => 'is fond of Farkas', 'wary' => 'mixed feelings', 'cool' => 'thinks little', 'hostile' => 'cannot stand'][$kind], $r['lines'][0]['text']);
            $this->assertTrue($r['changed']);
            $again = RelDynCascadeExt::feltLines($d, 'Lynly Star-Sung', 'Kaida', ['Kaida'], true);
            $this->assertSame([], $again['lines'], 'once');
        }
        // the named one is in the room: they are not "not here", and the line is spent
        $d = $mk(70.0);
        $r = RelDynCascadeExt::feltLines($d, 'Lynly Star-Sung', 'Kaida', ['Lynly Star-Sung', 'Farkas (idle)', 'Kaida'], true);
        $this->assertSame([], $r['lines']);
        $this->assertFalse($d[RelDynCascadeExt::KEY]['mentions']['farkas']['pending']);
        // the line waits for the player to speak to the NPC
        $d = $mk(70.0);
        $this->assertSame([], RelDynCascadeExt::feltLines($d, 'Lynly Star-Sung', 'Kaida', [], false)['lines']);
        $this->assertTrue($d[RelDynCascadeExt::KEY]['mentions']['farkas']['pending']);
    }

    // ------------------------------------------------------------------ the words of the rest

    public function testTheStandingWordsNameTheFriendOrFoeAndTheRivalAndCarryNoNumbers(): void
    {
        $assoc = fn(float $applied, string $kind) => [RelDynCascadeExt::KEY => ['assoc' => ['applied' => $applied, 'links' => [
            ['name' => 'Farkas', 'lean' => $applied, 'kind' => $kind]]], 'rivals' => []]] + $this->npc();
        foreach ([[4.0, 'ally_close', 'speaks well of Kaida'], [-4.0, 'rival_close', 'no love for Farkas'], [-4.0, 'ally_foe', 'no use for Kaida'], [4.0, 'rival_foe', 'do not get on']] as [$applied, $kind, $phrase]) {
            $d = $assoc($applied, $kind);
            $r = RelDynCascadeExt::feltLines($d, 'Aela the Huntress', 'Kaida', [], false);
            $this->assertCount(1, $r['lines'], $kind);
            $this->assertSame('association', $r['lines'][0]['key']);
            $this->assertSame('core', $r['lines'][0]['lane']);
            $this->assertStringContainsString($phrase, $r['lines'][0]['text']);
            $this->assertDoesNotMatchRegularExpression('/\d/', $r['lines'][0]['text']);
        }
        $d = $assoc(1.0, 'ally_close');
        $this->assertSame([], RelDynCascadeExt::feltLines($d, 'Aela the Huntress', 'Kaida', [], false)['lines'], 'under felt.from: nothing to say yet');
        // the rivalry: by how mature the NPC is
        $rival = function (float $maturity): string {
            $d = $this->npc([], $maturity);
            $d[RelDynCascadeExt::KEY] = ['assoc' => [], 'rivals' => ['Lynly Star-Sung' => ['pressure' => 0.5, 'applied' => -8.0, 'written' => -8.0]]];
            $r = RelDynCascadeExt::feltLines($d, 'Aela the Huntress', 'Kaida', [], false);
            $this->assertCount(1, $r['lines']);
            $this->assertSame('rivalry', $r['lines'][0]['key']);
            return $r['lines'][0]['text'];
        };
        $this->assertStringContainsString('honest with themself', $rival(90.0));
        $this->assertStringContainsString('careful eye', $rival(50.0));
        $this->assertStringContainsString('bristles', $rival(10.0));
        $this->assertStringContainsString('Lynly Star-Sung', $rival(50.0));
        foreach (['mature' => 90.0, 'mixed' => 50.0, 'immature' => 10.0] as $m) $this->assertDoesNotMatchRegularExpression('/\d/', $rival($m));
        // every shipped word is neutral of gender and of digits
        $words = [];
        array_walk_recursive(RelDynCascadeExt::configDefaults(), function ($v) use (&$words) { if (is_string($v)) $words[] = $v; });
        foreach ($words as $w) $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|him|his|hers|herself|himself)\b/i', $w);
    }

    // ------------------------------------------------------------------ state and config

    public function testASaveLoadClampsTheCirclesClocksToTheLoadedTime(): void
    {
        $d = $this->npc();
        $d[RelDynCascadeExt::KEY] = [
            'assoc' => ['applied' => 3.0, 'at' => self::T0 + 5 * self::DAY, 'checked' => self::T0 + 4 * self::DAY],
            'rivals_checked' => self::T0 + 3 * self::DAY,
            'rivals' => ['Lynly Star-Sung' => ['applied' => -6.0, 'written' => -6.0, 'at' => self::T0 + 6 * self::DAY, 'since' => self::T0 + 2 * self::DAY]],
            'mentions' => ['farkas' => ['name' => 'Farkas', 'at' => self::T0 + 7 * self::DAY, 'felt_at' => self::T0 + 7 * self::DAY, 'bond' => 50.0, 'pending' => false]],
        ];
        $r = RelDynTimeline::rebaselineDynamics($d, self::T0, null)[RelDynCascadeExt::KEY];
        $this->assertSame((float) self::T0, floatval($r['assoc']['at']));
        $this->assertSame((float) self::T0, floatval($r['assoc']['checked']));
        $this->assertSame((float) self::T0, floatval($r['rivals_checked']));
        $this->assertSame((float) self::T0, floatval($r['rivals']['Lynly Star-Sung']['at']));
        $this->assertSame((float) self::T0, floatval($r['rivals']['Lynly Star-Sung']['since']));
        $this->assertSame((float) self::T0, floatval($r['mentions']['farkas']['at']));
        $this->assertSame((float) self::T0, floatval($r['mentions']['farkas']['felt_at']));
        $this->assertSame(3.0, floatval($r['assoc']['applied']), 'what was applied stays: it is core\'s now, and its ledger is what gives it back');
    }

    public function testAPartialStoredConfigReadsLikeTheWholeSectionAndTheSectionIsOnTheHub(): void
    {
        $this->storeConfig(['cascade_ext' => ['association' => ['gain' => 20.0, 'knowledge' => ['floor' => 0.4], 'susceptibility' => ['trait_gain' => ['W' => 0.5]]],
            'mention' => ['felt_text' => ['fond' => 'edited.']]]]);
        $cfg = RelDynCascadeExt::config();
        $this->assertEquals(20.0, $cfg['association']['gain']);
        $this->assertEquals(12.0, $cfg['association']['cap'], 'the rest stays default');
        $this->assertEquals(0.4, $cfg['association']['knowledge']['floor']);
        $this->assertEquals(0.5, $cfg['association']['knowledge']['bond_relief']);
        $this->assertEquals(0.5, $cfg['association']['susceptibility']['trait_gain']['W']);
        $this->assertEquals(-0.35, $cfg['association']['susceptibility']['trait_gain']['G']);
        $this->assertSame('edited.', $cfg['mention']['felt_text']['fond']);
        $this->assertArrayHasKey('hostile', $cfg['mention']['felt_text']);
        $this->assertArrayHasKey('cascade_ext', RelDynSettings::readers());
        $this->assertSame('world', RelDynSettings::groupOf('cascade_ext'));
        foreach (['cascade_ext.enabled', 'cascade_ext.association.enabled', 'cascade_ext.triangle.enabled', 'cascade_ext.mention.enabled',
                  'cascade_ext.association.felt.enabled', 'cascade_ext.triangle.felt.enabled'] as $switch) {
            $this->assertTrue(RelDynSettingsText::isExplicit($switch), $switch);
        }
    }

    public function testOffMeansNothingRunsAndTheJevViewIsQuiet(): void
    {
        $this->storeConfig(['cascade_ext' => ['enabled' => false]]);
        $this->assertFalse(RelDynCascadeExt::enabled());
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) self::T0, 'Kaida: Farkas says hi. (Talking to Aela the Huntress)'];
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $d = $this->npc();
        $r = RelDynCascadeExt::onPrerequest('Aela the Huntress', $d);
        $this->assertSame(['mentions' => [], 'association' => null, 'triangle' => null], $r);
        $this->assertArrayNotHasKey(RelDynCascadeExt::KEY, $d);
        $j = RelDynCascadeExt::jev($d);
        $this->assertSame([], $j['rivals']);
        $this->assertSame(0.0, $j['association']['applied']);
    }
}
