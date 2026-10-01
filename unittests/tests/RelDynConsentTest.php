<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/** Stand-in for $db holding core's conf_opts rows in memory (the config store's SELECT and upsert). */
final class RelDynConsentRowDb
{
    public array $rows = [];

    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (stripos($sql, 'INSERT INTO conf_opts') !== false) {
            $this->rows[(string) $params[0]] = (string) $params[1];
            return ['id' => (string) $params[0]];
        }
        if (stripos($sql, 'FROM conf_opts') !== false) {
            $id = is_array($params) ? (string) $params[0] : (preg_match("/id = '([^']+)'/", $sql, $m) ? $m[1] : '');
            return isset($this->rows[$id]) ? ['value' => $this->rows[$id]] : [];
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * Consent (Ken 2026-10-01 §21): RelDyn decides whether intimacy happens at all, one decision API, allow or refuse with
 * reasons, dynamic and scaled by who the NPC is (reldyn_consent.php). No database but a config stand-in: the defaults
 * and nothing stored. The test beds through the real hooks, with the published key Sharmat reads, are
 * RelDynConsentTestBedsPostgresTest.
 *
 * Units: willingness / bar / want / appeasement / intensities 0..1, dimension points 0..100, core affinity -100..100.
 */
final class RelDynConsentTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;

    private array $saved = [];
    private string $logFile;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdconsent');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function storeConfig(array $row): void
    {
        $db = new RelDynConsentRowDb();
        $db->rows[RelationshipDynamics::CONFIG_ROW_ID] = json_encode($row + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * An NPC toward the player, a bond that has earned intimacy unless the caller says otherwise: its own trait vector,
     * maturity, self-confidence, core affinity and type, passion, comfort and trust, attachment style.
     */
    private function npc(array $traits = [], float $maturity = 55.0, float $confidence = 55.0, array $o = []): array
    {
        $o += ['aff' => 80.0, 'type' => 'romantic', 'passion' => 70.0, 'comfort' => 75.0, 'trust' => 80.0, 'attachment' => 'secure', 'resentment' => 0.0];
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], $traits);
        $x['maturity_start'] = $maturity;
        $mirror = ($o['aff'] + 100.0) / 2.0;
        $d = [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            '_core_rel_type' => $o['type'],
            '_aff_mirror_x' => $mirror,
            'profile_overrides' => ['attachment_style' => $o['attachment']],
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
            '_attraction' => ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => null, 'intimacy_allowed' => true],
        ];
        foreach (['affinity' => $mirror, 'trust' => $o['trust'], 'maturity' => $maturity, 'comfort' => $o['comfort'], 'respect' => 60.0,
                  'self_confidence' => $confidence, 'resentment' => $o['resentment'], 'passion' => $o['passion']] as $dim => $v) {
            $d['dimensions'][$dim] = ['x' => $v, 'baseline' => in_array($dim, ['resentment'], true) ? 0.0 : $v];
        }
        $d['passion'] = $o['passion'];
        return $d;
    }

    private function decide(array $d, string $name = 'Test NPC'): array
    {
        return RelDynConsent::decide($name, $d, null, 'Kaida');
    }

    /** A bond part of the way there (a crush, mid affinity and passion): the one a people-pleaser gives in on. */
    private function midBond(array $o = []): array
    {
        return $o + ['aff' => 45.0, 'passion' => 35.0, 'type' => 'crush', 'comfort' => 60.0, 'trust' => 65.0];
    }

    private function pleaser(array $o = []): array
    {
        return $this->npc(['Pd' => 0.1, 'W' => 0.9, 'L' => 0.4], 25.0, 15.0, $this->midBond($o + ['attachment' => 'anxious']));
    }

    private function firm(array $o = []): array
    {
        return $this->npc(['Pd' => 0.7, 'W' => 0.4, 'L' => 0.4], 70.0, 75.0, $this->midBond($o));
    }

    /** The traits of a proud, reactive NPC who holds a grudge, and of a restrained, resilient, humble one. */
    private const FIERY = ['L' => 0.9, 'Pd' => 0.85, 'Rs' => 0.25, 'W' => 0.3, 'G' => 0.5];
    private const STEADY = ['L' => 0.15, 'Pd' => 0.25, 'Rs' => 0.9, 'W' => 0.8, 'G' => 0.4];

    // ------------------------------------------------------------------ the shape

    public function testDefaultsAreTheDecisionsTableAndGenderNeutral(): void
    {
        $cfg = RelDynConsent::configDefaults();
        $this->assertTrue($cfg['enabled']);
        $this->assertSame(['conflict', 'ick', 'withdrawn', 'pulled_back', 'not_let_in', 'fear'], array_keys($cfg['factors']), "Ken's soft states");
        $this->assertSame(RelDynConsent::STANCES, ['willing', 'hesitant', 'appeasing', 'declines', 'closed']);
        // §21: never she / he in LLM-facing text: the name only
        $all = json_encode($cfg['felt_text']);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his|herself|himself|they|them|their)\b/i', $all, 'no pronoun for the NPC in the felt text');
        $this->assertDoesNotMatchRegularExpression('/\d/', $all, 'feelings, never numbers');
        foreach ($cfg['felt_text'] as $stance => $row) {
            $this->assertArrayHasKey('default', $row, "{$stance} has a default line");
        }
        // the settings hub carries it, described, with the switches
        $this->assertArrayHasKey('consent', RelationshipDynamics::defaultConfig());
        $text = RelDynSettingsText::SECTION_HELP['consent'] ?? null;
        $this->assertIsString($text);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|his|him)\b/i', $text);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|his|him)\b/i', RelDynSettingsText::HINTS['consent.enabled']);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|his|him)\b/i', RelDynSettingsText::HINTS['consent.appeasement.enabled']);
    }

    // ------------------------------------------------------------------ closed

    public function testAnAsexualNPCRefusesIntimacyWhateverElseIsTrue(): void
    {
        $d = $this->npc();
        $d['_attraction'] = ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => 'asexual', 'intimacy_allowed' => false];
        $r = $this->decide($d);
        $this->assertFalse($r['allow']);
        $this->assertSame('closed', $r['stance']);
        $this->assertTrue($r['closed']);
        $this->assertSame(['asexual'], $r['reasons']);
        $this->assertStringContainsString('Test NPC', (string) $r['felt']);
    }

    public function testAromanticNotInterestedAndDemisexualBeforeTheBondRefuseEachInTheirOwnReason(): void
    {
        $why = [];
        foreach (['aromantic' => 'aromantic', 'not_interested' => 'not_interested', 'demisexual' => 'not_bonded_yet'] as $pref => $reason) {
            $d = $this->npc();
            $d['_attraction'] = ['enabled' => true, 'passes' => false, 'attracted' => false, 'friendzoned' => false, 'preference' => $pref, 'intimacy_allowed' => false];
            $r = $this->decide($d);
            $this->assertFalse($r['allow'], $pref);
            $this->assertSame([$reason], $r['reasons'], $pref);
            $why[] = $r['felt'];
        }
        $this->assertCount(3, array_unique($why), 'each says it in its own words');
        // the demisexual NPC after the bond: the preference filter lets intimacy through, so the decision follows the want
        $d = $this->npc();
        $d['_attraction'] = ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => 'demisexual', 'intimacy_allowed' => true];
        $this->assertTrue($this->decide($d)['allow']);
    }

    public function testAFriendzonedNPCAndOneWhoWalkedAwayRefuseAndItEnds(): void
    {
        $d = $this->npc();
        $d['_attraction_friendzoned'] = true;
        $d['_attraction'] = ['enabled' => true, 'passes' => false, 'attracted' => false, 'friendzoned' => true, 'preference' => null, 'intimacy_allowed' => true];
        $r = $this->decide($d);
        $this->assertSame('closed', $r['stance']);
        $this->assertSame(['friendzoned'], $r['reasons']);
        // the friendzone ends (the attraction climbed): the same bond is open
        $d['_attraction_friendzoned'] = false;
        $d['_attraction'] = ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => null, 'intimacy_allowed' => true];
        $this->assertTrue($this->decide($d)['allow'], 'a friendzone is a state, not a verdict');
        // walked away; the boundary test ends and it is open again
        $d['_walkaway_state'] = 'walking';
        $r = $this->decide($d);
        $this->assertSame('closed', $r['stance']);
        $this->assertSame(['walked_away'], $r['reasons']);
        $d['_walkaway_state'] = 'normal';
        $this->assertTrue($this->decide($d)['allow']);
    }

    public function testClosedIsNeverAppeased(): void
    {
        // the autonomy lane's people-pleaser: little confidence, little maturity, anxious: the one who gives in
        $d = $this->npc(['Pd' => 0.1, 'W' => 0.9], 25.0, 15.0, ['attachment' => 'anxious']);
        $this->assertTrue(RelationshipDynamics::isPeoplePleaser($d));
        foreach (['asexual', 'aromantic'] as $pref) {
            $d['_attraction'] = ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => $pref, 'intimacy_allowed' => false];
            $r = $this->decide($d);
            $this->assertFalse($r['allow'], "{$pref}: nobody is argued out of who they are");
            $this->assertFalse($r['appeasing']);
        }
        $d['_attraction'] = ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => null, 'intimacy_allowed' => true];
        $d['_walkaway_state'] = 'walking';
        $this->assertFalse($this->decide($d)['allow']);
    }

    public function testWithTheMatrixAndTheFilterOffThereIsNoPreferenceToRead(): void
    {
        $d = $this->npc();
        unset($d['_attraction']);
        $this->assertSame([], RelDynConsent::closedReasons($d));
        $this->assertTrue($this->decide($d)['allow'], 'a romance with nothing against it');
    }

    // ------------------------------------------------------------------ willingness

    public function testABondThatHasEarnedItIsWillingAndAStrangerIsNot(): void
    {
        $r = $this->decide($this->npc());
        $this->assertTrue($r['allow']);
        $this->assertSame('willing', $r['stance']);
        $this->assertSame([], $r['reasons']);
        $this->assertNull($r['felt'], 'nothing to say');
        $stranger = $this->npc([], 55.0, 55.0, ['aff' => 5.0, 'type' => 'neutral', 'passion' => 0.0, 'comfort' => 40.0, 'trust' => 45.0]);
        $this->assertFalse($this->decide($stranger)['allow'], 'RelDyn decides whether it gets there at all');
        $this->assertSame('declines', $this->decide($stranger)['stance']);
    }

    public function testEachSoftStateLowersTheWillingnessAndIsNamed(): void
    {
        $base = $this->decide($this->npc());
        $cases = [
            'conflict'    => fn(array &$d) => $d['in_conflict'] = true,
            'ick'         => fn(array &$d) => $d['_ick_tracker'] = ['ick_active' => true],
            'withdrawn'   => fn(array &$d) => $d['dimensions']['resentment']['x'] = 80.0,
            'pulled_back' => fn(array &$d) => $d['_pullback'] = ['v' => RelDynPullback::VERSION, 'pressure' => 0.8, 'active' => true],
            'not_let_in'  => function (array &$d) { $d['dimensions']['comfort']['x'] = 20.0; $d['dimensions']['trust']['x'] = 25.0; },
            'fear'        => fn(array &$d) => $d['_keeping'] = ['v' => RelDynKeeping::VERSION, 'fear' => 0.9],
        ];
        foreach ($cases as $reason => $apply) {
            $d = $this->npc();
            $apply($d);
            $r = $this->decide($d);
            $this->assertLessThan($base['willingness'], $r['willingness'], "{$reason} lowers it");
            $this->assertContains($reason, $r['reasons'], "{$reason} is named");
            $this->assertGreaterThan(0.0, $r['weight'], $reason);
        }
    }

    public function testResentmentBelowTheWithdrawalLineIsAHurtNotAWithdrawal(): void
    {
        $d = $this->npc();
        $d['dimensions']['resentment']['x'] = 60.0;
        $r = $this->decide($d);
        $this->assertContains('resentful', $r['reasons']);
        $this->assertNotContains('withdrawn', $r['reasons']);
        $d['dimensions']['resentment']['x'] = 75.0;
        $this->assertContains('withdrawn', $this->decide($d)['reasons']);
    }

    public function testTheSameQuarrelSettlesDifferentlyByWhoTheNPCIs(): void
    {
        $fiery = $this->npc(self::FIERY, 55.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]);
        $steady = $this->npc(self::STEADY, 55.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]);
        $fiery['in_conflict'] = $steady['in_conflict'] = true;
        $this->assertTrue($this->decide($this->npc(self::FIERY, 55.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]))['allow'], 'no quarrel: open');
        $f = $this->decide($fiery);
        $s = $this->decide($steady);
        $this->assertGreaterThan($f['willingness'], $s['willingness'], 'a quarrel weighs more on the proud and reactive');
        $this->assertGreaterThan($s['weight'], $f['weight']);
        $this->assertFalse($f['allow'], 'the fiery one refuses in the middle of a quarrel');
        $this->assertTrue($s['allow'], 'the steady one is still open to it');
        // no one is ever immune: the steadiest NPC still feels it
        $this->assertGreaterThan(0.1, $s['weight']);
        $this->assertLessThan($this->decide($this->npc(self::STEADY, 55.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]))['willingness'], $s['willingness']);
    }

    public function testAMatureNPCIsMovedLessByTheirMoodThanAnImmatureOne(): void
    {
        $pulled = ['v' => RelDynPullback::VERSION, 'pressure' => 0.7, 'active' => true];
        $immature = $this->npc([], 15.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]);
        $mature = $this->npc([], 90.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]);
        $immature['_pullback'] = $mature['_pullback'] = $pulled;
        $i = $this->decide($immature);
        $m = $this->decide($mature);
        $this->assertGreaterThan($m['effects']['pulled_back'], $i['effects']['pulled_back'], 'maturity sets how much the mood drives it');
        $this->assertGreaterThan(0.0, $m['effects']['pulled_back'], 'never nothing');
    }

    public function testAGuardedNPCNeedsToBeLetInFurther(): void
    {
        $open = $this->npc(['G' => 0.2], 55.0, 55.0, ['comfort' => 45.0, 'trust' => 50.0]);
        $guarded = $this->npc(['G' => 0.9], 55.0, 55.0, ['comfort' => 45.0, 'trust' => 50.0]);
        $this->assertGreaterThan($this->decide($open)['effects']['not_let_in'], $this->decide($guarded)['effects']['not_let_in']);
        $this->assertGreaterThan($this->decide($open)['bar'], $this->decide($guarded)['bar'], 'guard raises the bar');
    }

    public function testTheIntimacyGateDecidesWhatTheWantReadsAndTheBar(): void
    {
        // a physical, passionate attraction on a thin bond: the visceral NPC is further along than the bond-first one
        $mk = fn(string $gate) => $this->npc([], 55.0, 55.0, ['aff' => 25.0, 'passion' => 85.0, 'type' => 'crush', 'comfort' => 50.0, 'trust' => 55.0]) + ['attraction_profile' => ['intimacy_gate' => $gate]];
        $visceral = $this->decide($mk('visceral'));
        $bond = $this->decide($mk('bond'));
        $this->assertSame('visceral', $visceral['gate']);
        $this->assertSame('bond', $bond['gate']);
        $this->assertGreaterThan($bond['want'], $visceral['want']);
        $this->assertLessThan($bond['bar'], $visceral['bar']);
        $this->assertTrue($visceral['allow']);
        $this->assertFalse($bond['allow']);
    }

    public function testTheLastAnswerIsHeldAgainstFlicker(): void
    {
        $d = $this->npc(self::FIERY, 55.0, 55.0, ['aff' => 66.0, 'passion' => 55.0]);
        // find a willingness on the line: a quarrel's effect scaled until the bar sits between the two readings
        $base = $this->decide($d);
        $bar = $base['bar'];
        $d[RelDynConsent::KEY] = ['v' => 1, 'allow' => true];
        $afterYes = RelDynConsent::decide('Test NPC', $d, null, 'Kaida');
        $d[RelDynConsent::KEY] = ['v' => 1, 'allow' => false];
        $afterNo = RelDynConsent::decide('Test NPC', $d, null, 'Kaida');
        $this->assertEqualsWithDelta(0.06, $afterNo['bar'] - $afterYes['bar'], 1e-9, 'after a yes the bar is lower, after a no higher');
        $this->assertEqualsWithDelta($bar, ($afterYes['bar'] + $afterNo['bar']) / 2, 1e-9);
    }

    // ------------------------------------------------------------------ the people-pleaser

    public function testAPeoplePleaserSaysYesWhenTheyShouldNotAndOthersDoNot(): void
    {
        // the same quarrel and the same bond: who they are decides it
        $pleaser = $this->pleaser();
        $firm = $this->firm();
        $this->assertTrue($this->decide($pleaser)['allow'] && $this->decide($firm)['allow'], 'no quarrel: both are open to it');
        $pleaser['in_conflict'] = $firm['in_conflict'] = true;
        $p = $this->decide($pleaser);
        $f = $this->decide($firm);
        $this->assertTrue($p['allow'], 'the people-pleaser gives in');
        $this->assertSame('appeasing', $p['stance']);
        $this->assertTrue($p['appeasing']);
        $this->assertContains('conflict', $p['reasons'], 'it was a state that should have stopped them');
        $this->assertLessThan($p['bar'], $p['willingness'], 'short of their own bar');
        $this->assertFalse($f['allow'], 'the confident, mature one says no');
        $this->assertSame('declines', $f['stance']);
        $this->assertGreaterThan($f['appeasement'], $p['appeasement']);
        $this->assertStringContainsString('going along with it anyway', (string) $p['felt'], 'the words of an NPC who says yes unwillingly');
    }

    public function testAppeasementTakesMoreTheFurtherTheyFallShortAndFearIsNamed(): void
    {
        $pleaser = fn() => $this->pleaser();
        $mild = $pleaser();
        $mild['in_conflict'] = true;
        $severe = $pleaser();
        $severe['in_conflict'] = true;
        $severe['_ick_tracker'] = ['ick_active' => true];
        $severe['dimensions']['resentment']['x'] = 88.0;
        $severe['_pullback'] = ['v' => RelDynPullback::VERSION, 'pressure' => 0.9, 'active' => true];
        $this->assertSame('appeasing', $this->decide($mild)['stance']);
        $this->assertSame('declines', $this->decide($severe)['stance'], 'there is a limit even for a people-pleaser: the shortfall is too great');
        // the fear of losing the player is what drives it, and the felt text says that instead
        $fear = $pleaser();
        $fear['in_conflict'] = true;
        $fear['_keeping'] = ['v' => RelDynKeeping::VERSION, 'fear' => 0.8];
        $r = $this->decide($fear);
        $this->assertSame('appeasing', $r['stance']);
        $this->assertContains('fear', $r['reasons']);
        $this->assertStringContainsString('out of that fear', (string) $r['felt']);
    }

    public function testAppeasementCanBeSwitchedOffAndEveryoneShortOfTheirBarSaysNo(): void
    {
        $pleaser = $this->pleaser();
        $pleaser['in_conflict'] = true;
        $this->assertSame('appeasing', $this->decide($pleaser)['stance']);
        $this->storeConfig(['consent' => ['appeasement' => ['enabled' => false]]]);
        $this->assertSame('declines', $this->decide($pleaser)['stance']);
    }

    public function testNothingInTheDecisionReadsHowThePlayerTreatsTheNPC(): void
    {
        // RelDyn models the person, it does not judge the player: the same state, whatever the player did, is the same answer
        $d = $this->pleaser();
        $d['in_conflict'] = true;
        $a = $this->decide($d);
        $d['grievance_log'] = [['kind' => 'betrayal', 'severity' => 5]];
        $d['_player_conduct'] = 'cruel';
        $this->assertSame($a, $this->decide($d));
    }

    // ------------------------------------------------------------------ the text and the payload

    public function testTheFeltLineNamesTheNPCAndThePlayerAndSaysNoPronounOrNumber(): void
    {
        $pulled = $this->npc();
        $pulled['_pullback'] = ['v' => RelDynPullback::VERSION, 'pressure' => 0.9, 'active' => true];
        $pulled['in_conflict'] = true;
        $pulled['_ick_tracker'] = ['ick_active' => true];
        foreach ([$pulled, $this->npc([], 55.0, 55.0, ['aff' => 5.0, 'type' => 'neutral', 'passion' => 0.0])] as $d) {
            $r = RelDynConsent::decide('Lynly Star-Sung', $d, null, 'Kaida');
            $this->assertFalse($r['allow']);
            $this->assertStringContainsString('Lynly Star-Sung', (string) $r['felt']);
            $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his)\b/i', (string) $r['felt']);
            $this->assertDoesNotMatchRegularExpression('/\d/', (string) $r['felt']);
        }
    }

    public function testThePayloadIsWhatSharmatReads(): void
    {
        $r = $this->decide($this->npc());
        $p = RelDynConsent::payload($r);
        $this->assertSame(['v', 'enabled', 'allow', 'stance', 'closed', 'reasons', 'appeasing', 'willingness', 'bar', 'felt'], array_keys($p));
        $this->assertTrue($p['enabled']);
        $this->assertTrue($p['allow']);
        $this->assertSame('willing', $p['stance']);
        $this->assertArrayNotHasKey('effects', $p, 'the working numbers stay in RelDyn');
    }

    public function testTheSettingsTableMergesPerEntryAndRoundTripsThroughTheHub(): void
    {
        $this->storeConfig(['consent' => ['bar' => ['base' => 0.9], 'factors' => ['ick' => ['base' => 0.1]]]]);
        $cfg = RelDynConsent::config();
        $this->assertEqualsWithDelta(0.9, $cfg['bar']['base'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $cfg['bar']['guard'], 1e-9, 'the rest of the table is the default');
        $this->assertEqualsWithDelta(0.1, $cfg['factors']['ick']['base'], 1e-9);
        $this->assertSame(['G' => 0.3], $cfg['factors']['ick']['traits'], 'a factor merges per entry');
        $d = $this->npc([], 55.0, 55.0, $this->midBond());
        $this->assertSame('declines', $this->decide($d)['stance'], 'a higher bar is a stricter NPC');
        $this->storeConfig([]);
        $this->assertTrue($this->decide($d)['allow'], 'the default bar lets the same bond through');
    }
}
