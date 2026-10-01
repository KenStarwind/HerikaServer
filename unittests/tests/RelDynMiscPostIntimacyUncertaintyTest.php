<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql` stand-in: a stored RelDyn config row; every other read finds nothing. */
final class RelDynMiscPostIntimacyDb
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
 * Decisions 2026-09-23 §20 #27 (post-intimacy). Drink raises her openness (RelDynSubstances: disinhibited from the
 * fourth drink), and with it attraction and passion override the trust she would normally need. The morning after is
 * not shame: the sober her is unsure ("was that too soon?"), sized by the trust gap and her own openness. No
 * resentment_self comes of it; at most a small comfort wobble that a reassuring exchange resolves (or that the
 * bond's own growth does, once the trust she needed is there). The felt text has no digits. Never a lock and never
 * zero: a floor of doubt remains for anyone who was drunk with someone she had not yet trusted enough, scaled by
 * how closed she is. Pure paths, real engine code, a stub database.
 */
final class RelDynMiscPostIntimacyUncertaintyTest extends TestCase
{
    private const HOUR = RelationshipDynamics::GAMETS_PER_HOUR;
    private const T0 = 300 * RelationshipDynamics::GAMETS_PER_DAY + 20 * self::HOUR;
    private const NPC = 'Aela the Huntress';

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
        $this->useDb();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdmiscpi');
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
    }

    private function useDb(array $overrides = []): void
    {
        $GLOBALS['db'] = new RelDynMiscPostIntimacyDb(array_replace(RelationshipDynamics::defaultConfig(), $overrides));
        RelationshipDynamics::clearConfigCache();
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0.0);
    }

    private static function ownComfort(array $d): float
    {
        return self::x($d, 'comfort') - RelationshipDynamics::heldTemporaryOffset($d, 'comfort');
    }

    /** A friend she is not with; $traits override the neutral middle of her vector (openness follows them). */
    private function npc(float $trust, array $traits = [], float $maturity = 60.0): array
    {
        $x = RelDynTraits::presetPoint('Bold');
        foreach ($x as $k => $_) if ($k !== 'maturity_start') $x[$k] = 0.5;
        foreach ($traits as $code => $v) $x[$code] = $v;
        $prev = RelDynTraits::$assignmentOverride;
        RelDynTraits::$assignmentOverride = 'read';
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Bold',
            'trait_vector' => RelDynTraits::toStored($x),
            'trait_vector_version' => RelDynTraits::VERSION,
            '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($x), 'read_status' => 'done', 'prior' => ['complete' => true]],
            '_profile_autogen' => ['version' => RelationshipDynamics::PROFILE_AUTOGEN_VERSION],
        ]));
        RelDynTraits::$assignmentOverride = $prev;
        RelationshipDynamics::setCoreRelationshipType($d, 'friend');
        $d['dimensions']['trust']['x'] = $trust;
        $d['dimensions']['comfort']['x'] = 40.0;
        $d['dimensions']['maturity']['x'] = $maturity;
        $d['dimensions']['maturity']['baseline'] = $maturity;
        return $d;
    }

    private function scene(array &$d, float $t = self::T0): ?string
    {
        $req = ['ext_nsfw_sexcene', '1727000000', (string) (int) $t, 'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/' . self::NPC . '^sub,vaginal'];
        return RelDynPostIntimacy::onIntimateRequest(self::NPC, $d, $req, 'Kaida', $t);
    }

    private function drinks(array &$d, int $n, float $t = self::T0 - 2 * self::HOUR): void
    {
        for ($i = 0; $i < $n; $i++) RelDynSubstances::onConsume(self::NPC, $d, 'ale', $t + $i * 60);
    }

    /** Drunk, a scene, then the sober morning: the sober tick at nine game hours. Returns [$d, $tickResult]. */
    private function drunkNight(float $trust, array $traits = [], float $maturity = 60.0, int $drinks = 2): array
    {
        $d = $this->npc($trust, $traits, $maturity);
        $this->drinks($d, $drinks);
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d));
        RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 3 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, self::T0 + 9 * self::HOUR);   // the drink has cleared
        $r = RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 9 * self::HOUR);
        return [$d, $r];
    }

    // ------------------------------------------------------------------ the row carries no shame

    public function testTheDrunkenRowIsUncertaintyNotShame(): void
    {
        $this->assertSame('drunk_uncertainty', RelDynPostIntimacy::DRUNK);
        $cfg = RelDynPostIntimacy::configDefaults();
        $row = $cfg['outcomes'][RelDynPostIntimacy::DRUNK];
        $json = json_encode($row);
        $this->assertStringNotContainsString('resentment_self', $json);
        $this->assertArrayNotHasKey('correction_casual', $row);
        $this->assertArrayNotHasKey('trust', (array) ($row['correction'] ?? []));
        $u = $cfg['uncertainty'];
        $this->assertGreaterThan(0.0, $u['wobble_comfort_max']);
        $this->assertLessThanOrEqual(8.0, $u['wobble_comfort_max'], 'a small wobble');
        $this->assertContains('reassurance', $u['resolved_by']);
        // the felt words have no numbers and say unsure, not ashamed
        foreach ($u['felt'] as $tier => $text) {
            $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, $tier);
            $this->assertMatchesRegularExpression('/too soon|unsure|wonder/i', (string) $text, $tier);
            $this->assertDoesNotMatchRegularExpression('/shame|regret|dirty|disgust|used/i', (string) $text, $tier);
        }
        $this->assertDoesNotMatchRegularExpression('/\d/', (string) $u['lingering']);
    }

    // ------------------------------------------------------------------ drink raises openness (the premise)

    public function testDrinkRaisesOpennessAndTheEncounterRemembersHowFar(): void
    {
        $sober = $this->npc(30.0);
        $oSober = RelDynPostIntimacy::soberOpenness($sober);
        $this->assertGreaterThan(0.0, $oSober);
        $this->assertLessThan(1.0, $oSober);

        $merry = $sober;
        $this->drinks($merry, 2);
        $this->scene($merry);
        $this->assertEqualsWithDelta(0.0, $merry[RelDynPostIntimacy::KEY]['context']['openness_drink'], 1e-9, 'two ales: not yet disinhibited');

        $drunk = $this->npc(30.0);
        $this->drinks($drunk, 4);
        $this->assertEqualsWithDelta(0.2, RelDynSubstances::shifts($drunk)['openness_bonus'], 1e-9, 'the fourth ale raises her openness');
        $this->scene($drunk);
        $ctx = $drunk[RelDynPostIntimacy::KEY]['context'];
        $this->assertEqualsWithDelta(0.2, $ctx['openness_drink'], 1e-9);
        $this->assertEqualsWithDelta($oSober, $ctx['openness'], 1e-9, 'her own openness is the sober one, not the drink\'s');
    }

    // ------------------------------------------------------------------ sizing: the trust gap and her openness

    public function testTheUncertaintyIsSizedByTheTrustGapAndHerOpenness(): void
    {
        $size = fn(float $trust, float $o): float => RelDynPostIntimacy::uncertainty($trust, $o)['size'];
        // a bigger gap, more unsure
        $this->assertGreaterThan($size(40.0, 0.6), $size(10.0, 0.6));
        $this->assertGreaterThan($size(30.0, 0.6), $size(10.0, 0.6));
        // a more closed woman needs more trust to feel it was not too soon, so the same trust leaves her more unsure
        $this->assertGreaterThan($size(30.0, 0.6), $size(30.0, 0.3));
        $this->assertGreaterThan($size(30.0, 0.9), $size(30.0, 0.6));
        // the need itself moves with openness
        $this->assertGreaterThan(RelDynPostIntimacy::uncertainty(30.0, 0.9)['need'], RelDynPostIntimacy::uncertainty(30.0, 0.3)['need']);
        // 0..1
        foreach ([0.0, 25.0, 60.0, 100.0] as $t) foreach ([0.0, 0.3, 0.6, 0.9, 1.0] as $o) {
            $s = $size($t, $o);
            $this->assertGreaterThanOrEqual(0.0, $s);
            $this->assertLessThanOrEqual(1.0, $s);
        }
        // never zero, never a lock: even with all the trust she needed, a floor of doubt remains for a closed woman,
        // smaller for an open one, and an open woman with the trust there is nearly clear
        $this->assertGreaterThan(0.0, $size(100.0, 0.3));
        $this->assertGreaterThan($size(100.0, 0.9), $size(100.0, 0.3));
        $this->assertGreaterThan(0.0, $size(100.0, 0.9), 'no one is immune');
        $this->assertLessThan(0.1, $size(100.0, 0.9));
        // no trust at all: close to the whole of it for a closed woman
        $this->assertGreaterThan(0.9, $size(0.0, 0.3));
    }

    // ------------------------------------------------------------------ the sober morning

    public function testTheSoberMorningIsUncertaintyWithNoShameAndASmallComfortWobble(): void
    {
        $d = $this->npc(25.0);
        $this->drinks($d, 2);
        $rs0 = self::x($d, 'resentment_self');
        $trust0 = self::x($d, 'trust');
        $comfort0 = self::ownComfort($d);
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d));
        $this->assertSame('glow', RelDynPostIntimacy::feltText($d, self::T0 + self::HOUR)['phase']);
        // six game hours on she is still drinking: the sober self has not spoken
        $this->assertSame([], RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 7 * self::HOUR)['corrected']);
        $this->assertNotEmpty($d[RelDynPostIntimacy::KEY]);
        // sober
        $d['_active_consumables'] = [];
        RelDynSubstances::update(self::NPC, $d, self::T0 + 11 * self::HOUR);
        $r = RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 11 * self::HOUR);
        $max = RelDynPostIntimacy::config()['uncertainty']['wobble_comfort_max'];
        $this->assertSame(['comfort'], array_keys($r['corrected']), 'a comfort wobble and nothing else');
        $this->assertLessThan(0.0, $r['corrected']['comfort']);
        $this->assertGreaterThanOrEqual(-$max - 1e-6, $r['corrected']['comfort'], 'small');
        $this->assertEqualsWithDelta($rs0, self::x($d, 'resentment_self'), 1e-9, 'no resentment_self from it');
        $this->assertEqualsWithDelta($trust0, self::x($d, 'trust'), 1e-9);
        $this->assertGreaterThan($comfort0 - $max - 1e-6, self::ownComfort($d), 'never a crash below where she started by more than the wobble');
        $felt = RelDynPostIntimacy::feltText($d, self::T0 + 12 * self::HOUR);
        $this->assertSame('after', $felt['phase']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt['text']);
        $this->assertMatchesRegularExpression('/too soon|unsure|wonder/i', $felt['text']);
    }

    public function testTheWobbleIsSizedByWhoSheIsAndTheTrustThereWas(): void
    {
        [$closedLow, $r1] = $this->drunkNight(10.0, ['G' => 0.9, 'E' => 0.2, 'C' => 0.3, 'Pd' => 0.8]);
        [$openLow, $r2] = $this->drunkNight(10.0, ['G' => 0.1, 'E' => 0.9, 'C' => 0.7, 'Pd' => 0.2]);
        [$closedHigh, $r3] = $this->drunkNight(60.0, ['G' => 0.9, 'E' => 0.2, 'C' => 0.3, 'Pd' => 0.8]);
        $o = fn(array $d) => RelDynPostIntimacy::soberOpenness($d);
        $this->assertLessThan($o($openLow), $o($closedLow), 'the two vectors differ in openness');
        $w = fn(array $r) => abs($r['corrected']['comfort'] ?? 0.0);
        $this->assertGreaterThan($w($r2), $w($r1), 'less open, same trust: more unsure');
        $this->assertGreaterThan($w($r3), $w($r1), 'same woman, less trust: more unsure');
        $this->assertGreaterThan(0.0, $w($r3), 'never zero');
        $s = fn(array $d) => $d[RelDynPostIntimacy::WOBBLE_KEY]['uncertainty'];
        $this->assertGreaterThan($s($openLow), $s($closedLow));
        $this->assertGreaterThan($s($closedHigh), $s($closedLow));
    }

    public function testAShallowMindDoesNotWonder(): void
    {
        [$d, $r] = $this->drunkNight(20.0, [], 15.0);
        $this->assertSame([], $r['corrected']);
        $this->assertArrayNotHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d);
    }

    public function testAStoredOldRowCannotBringTheShameBack(): void
    {
        // a settings row saved before this ruling holds the old drunk row: shame, a trust cut, a crash
        $old = RelDynPostIntimacy::configDefaults();
        $old['outcomes'][RelDynPostIntimacy::DRUNK]['correction'] = ['comfort' => -15.0, 'trust' => -5.0, 'resentment_self' => 10.0];
        $old['outcomes'][RelDynPostIntimacy::DRUNK]['correction_casual'] = ['comfort' => -20.0, 'resentment_self' => 12.0];
        $old['outcomes'][RelDynPostIntimacy::DRUNK]['correction_valence'] = -20.0;
        unset($old['uncertainty']);
        $this->useDb(['post_intimacy' => $old]);
        $d = $this->npc(20.0);
        $rs0 = self::x($d, 'resentment_self');
        $trust0 = self::x($d, 'trust');
        $this->drinks($d, 2);
        $this->scene($d);
        RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 3 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, self::T0 + 9 * self::HOUR);
        $r = RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 9 * self::HOUR);
        $this->assertEqualsWithDelta($rs0, self::x($d, 'resentment_self'), 1e-9);
        $this->assertEqualsWithDelta($trust0, self::x($d, 'trust'), 1e-9);
        $this->assertSame(['comfort'], array_keys($r['corrected']));
        $this->assertGreaterThan(-8.0, $r['corrected']['comfort']);
    }

    // ------------------------------------------------------------------ a reassuring exchange resolves it

    public function testAReassuringExchangeTakesTheWobbleBackExactly(): void
    {
        [$d, ] = $this->drunkNight(20.0);
        $w = $d[RelDynPostIntimacy::WOBBLE_KEY];
        $this->assertLessThan(0.0, $w['comfort']);
        $before = self::x($d, 'comfort');
        // another kind of exchange does not resolve it
        $this->assertFalse(RelDynPostIntimacy::onEvalItem(self::NPC, ['tags' => ['praise', 'quality_time'], 'gamets' => self::T0 + 20 * self::HOUR], $d, self::T0 + 20 * self::HOUR));
        $this->assertArrayHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d);
        $this->assertEqualsWithDelta($before, self::x($d, 'comfort'), 1e-9);
        // a reassurance does
        $this->assertTrue(RelDynPostIntimacy::onEvalItem(self::NPC, ['tags' => ['reassurance'], 'gamets' => self::T0 + 21 * self::HOUR], $d, self::T0 + 21 * self::HOUR));
        $this->assertArrayNotHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d);
        $this->assertEqualsWithDelta($before - $w['comfort'], self::x($d, 'comfort'), 1e-9, 'exactly what was taken');
        $this->assertNull(RelDynPostIntimacy::feltText($d, self::T0 + 22 * self::HOUR));
        // nothing left to resolve
        $this->assertFalse(RelDynPostIntimacy::onEvalItem(self::NPC, ['tags' => ['reassurance'], 'gamets' => self::T0 + 23 * self::HOUR], $d, self::T0 + 23 * self::HOUR));
    }

    public function testTheWobbleOutlastsTheFeltWindowAndTimeAloneDoesNotHealIt(): void
    {
        [$d, ] = $this->drunkNight(20.0);
        $w = $d[RelDynPostIntimacy::WOBBLE_KEY];
        // a week of game days later, no contact that reassures: the state is over, the wobble is not (decisions §2)
        $later = self::T0 + 7 * RelationshipDynamics::GAMETS_PER_DAY;
        $r = RelDynPostIntimacy::tick(self::NPC, $d, $later);
        $this->assertArrayNotHasKey(RelDynPostIntimacy::KEY, $d, 'the encounter itself has ended');
        $this->assertArrayHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d, 'time does not heal, contact does');
        $this->assertEqualsWithDelta($w['comfort'], $d[RelDynPostIntimacy::WOBBLE_KEY]['comfort'], 1e-9);
        $felt = RelDynPostIntimacy::feltText($d, $later);
        $this->assertSame('wobble', $felt['phase']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt['text']);
    }

    public function testTheTrustSheNeededArrivingResolvesItToo(): void
    {
        [$d, ] = $this->drunkNight(20.0);
        $need = RelDynPostIntimacy::uncertainty(self::x($d, 'trust'), RelDynPostIntimacy::soberOpenness($d))['need'];
        $before = self::x($d, 'comfort');
        $w = $d[RelDynPostIntimacy::WOBBLE_KEY]['comfort'];
        // the bond grows: not yet enough, then enough
        $d['dimensions']['trust']['x'] = $need - 5.0;
        $r = RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 30 * self::HOUR);
        $this->assertArrayHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d);
        $d['dimensions']['trust']['x'] = 100.0;   // read toward her through the bond's own scaling
        $r = RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 31 * self::HOUR);
        $this->assertArrayNotHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d, 'it was not too soon after all');
        $this->assertEqualsWithDelta($before - $w, self::x($d, 'comfort'), 1e-9);
        $this->assertArrayHasKey('wobble', $r['resolved']);
    }

    public function testALoadFromBeforeTheMorningTakesTheWobbleBack(): void
    {
        [$d, ] = $this->drunkNight(20.0);
        $w = $d[RelDynPostIntimacy::WOBBLE_KEY]['comfort'];
        $before = self::x($d, 'comfort');
        RelDynPostIntimacy::tick(self::NPC, $d, self::T0 + 2 * self::HOUR);
        $this->assertArrayNotHasKey(RelDynPostIntimacy::WOBBLE_KEY, $d);
        $this->assertEqualsWithDelta($before - $w, self::x($d, 'comfort'), 1e-9);
    }

    // ------------------------------------------------------------------ Jev, the numbers

    public function testJevCarriesTheWobbleForAsLongAsItLasts(): void
    {
        [$d, ] = $this->drunkNight(20.0);
        $j = RelDynPostIntimacy::jev($d, self::T0 + 10 * self::HOUR);
        $this->assertSame(RelDynPostIntimacy::DRUNK, $j['outcome']);
        $this->assertLessThan(0.0, $j['wobble']['comfort']);
        $this->assertGreaterThan(0.0, $j['wobble']['uncertainty']);
        $later = self::T0 + 7 * RelationshipDynamics::GAMETS_PER_DAY;
        RelDynPostIntimacy::tick(self::NPC, $d, $later);
        $j2 = RelDynPostIntimacy::jev($d, $later);
        $this->assertNotNull($j2, 'the state is over, the wobble is not');
        $this->assertSame($j['wobble']['comfort'], $j2['wobble']['comfort']);
    }

    // ------------------------------------------------------------------ the other outcomes are untouched

    public function testCheatingGuiltAndTheOtherRowsKeepTheirCorrections(): void
    {
        $cfg = RelDynPostIntimacy::configDefaults();
        $this->assertSame(15.0, $cfg['outcomes'][RelDynPostIntimacy::CHEATING]['correction']['resentment_self']);
        $this->assertSame(8.0, $cfg['outcomes'][RelDynPostIntimacy::VULNERABLE]['correction']['resentment_self']);
    }
}
