<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/**
 * Batch V "people" lane, the pure halves (rulings 2026-10-01 §22 and §23, Ken): a shared fight is contact and time together
 * by the NPC's taste and, where the NPC does not enjoy it, partly unfulfilling; the fear of losing the player is answered
 * by who the NPC is (appease, cling, withdraw, open a conflict); a fearful NPC pulls back after intimacy. No database, no
 * clock but the game's: $GLOBALS['db'] unset (the defaults, nothing stored), fixed game timestamps. The test beds through
 * the real hooks are RelDynVPeopleTestBedsPostgresTest.
 */
final class RelDynVPeopleTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function at(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) round($gamets), 'Kaida: hello'];
    }

    // =====================================================================
    // §23 shared fights: what a fight she does not enjoy takes off what she does
    // =====================================================================

    /** A scholar who loves books and the woods and dreads a fight. */
    private static function scholar(): array
    {
        return ['scholarly' => 0.8, 'nature' => 0.5, 'combat' => -0.8, 'adventure' => -0.6, 'danger' => -0.7];
    }

    /** One who lives for it. */
    private static function huntress(): array
    {
        return ['combat' => 0.9, 'adventure' => 0.7, 'danger' => 0.5, 'nature' => 0.6, 'scholarly' => -0.2];
    }

    private function pair(array $prefs, array $over = []): array
    {
        $this->at(self::T0);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'love_language_primary' => RelationshipDynamics::LL_TIME,
        ], $over));
        $this->assertTrue(RelDynFulfillment::ensure($d, $prefs, self::T0));
        return $d;
    }

    public function testALikedFightTakesNothingOffAndADislikedOneTakesOffWhatSheEnjoys(): void
    {
        $scholar = $this->pair(self::scholar());
        $state = RelDynFulfillment::pairState($scholar);
        $unmet = RelDynFulfillment::sharedFightUnmet($state, self::scholar());
        $this->assertSame(['scholarly', 'nature'], array_keys($unmet), 'the things she does enjoy, the stronger first');
        foreach ($unmet as $axis => $units) $this->assertLessThan(0.0, $units, $axis);
        $this->assertGreaterThan($unmet['scholarly'], $unmet['nature'], 'by weight: the books take more of it');
        $liking = RelDynFulfillment::sharedFightLiking(self::scholar());
        $this->assertLessThan(-0.5, $liking);
        $this->assertEqualsWithDelta(-0.5 * min(1.0, (-$liking - 0.1) / 0.9), array_sum($unmet), 1e-5, 'units x how far she dislikes it');
        $this->assertSame([], RelDynFulfillment::sharedFightUnmet(RelDynFulfillment::pairState($this->pair(self::huntress())), self::huntress()), 'a fight she enjoys is not partly unfulfilling');
        // a shrug is not a wish: the liking must pass the line before anything is taken
        $meh = ['scholarly' => 0.8, 'combat' => -0.05, 'adventure' => -0.05, 'danger' => -0.05];
        $this->assertSame([], RelDynFulfillment::sharedFightUnmet(RelDynFulfillment::pairState($this->pair($meh)), $meh));
        // more dislike, more taken: continuous, not a switch
        $cold = ['scholarly' => 0.8, 'combat' => -0.3, 'adventure' => -0.3, 'danger' => -0.3];
        $dread = ['scholarly' => 0.8, 'combat' => -0.9, 'adventure' => -0.9, 'danger' => -0.9];
        $a = array_sum(RelDynFulfillment::sharedFightUnmet(RelDynFulfillment::pairState($this->pair($cold)), $cold));
        $b = array_sum(RelDynFulfillment::sharedFightUnmet(RelDynFulfillment::pairState($this->pair($dread)), $dread));
        $this->assertLessThan(0.0, $a);
        $this->assertLessThan($a, $b);
    }

    public function testWithNoFacetNeedItFallsOnHerOtherNeedsNeverTheTimeTheFightGave(): void
    {
        $prefs = ['combat' => -0.8, 'adventure' => -0.6, 'danger' => -0.7];   // nothing she loves to name
        $d = $this->pair($prefs);
        $needs = array_keys((array) RelDynFulfillment::pairState($d)['w']);
        $this->assertNotContains('scholarly', $needs);
        $unmet = RelDynFulfillment::sharedFightUnmet(RelDynFulfillment::pairState($d), $prefs);
        $this->assertNotContains(RelationshipDynamics::LL_TIME, array_keys($unmet), 'the time together is what the fight did give');
        foreach (array_keys($unmet) as $axis) $this->assertNotSame(RelDynFulfillment::KIND_INTIMACY, RelDynFulfillment::axisKind($axis));
    }

    public function testTheFightGivesTimeTogetherAndTheDislikedOneTakesOffTheEnjoyedAtOnce(): void
    {
        $scholar = $this->pair(self::scholar());
        $before = RelDynFulfillment::levelsAt(RelDynFulfillment::pairState($scholar), self::T0 + self::HOUR);
        $unmet = [];
        $time = RelDynFulfillment::recordSharedFight($scholar, self::scholar(), self::T0 + self::HOUR, RelDynFulfillment::PLAYER, $unmet);
        $after = RelDynFulfillment::levelsAt(RelDynFulfillment::pairState($scholar), self::T0 + self::HOUR);
        $this->assertSame([RelationshipDynamics::LL_TIME], array_keys($time), 'the return stays the time together alone (the log line and the callers read it)');
        $this->assertGreaterThan(0.0, $time[RelationshipDynamics::LL_TIME], 'it still counts a little');
        $this->assertLessThan(0.15, $time[RelationshipDynamics::LL_TIME], 'but a little: a dreaded fight is a sliver of an evening');
        $this->assertGreaterThan($before[RelationshipDynamics::LL_TIME], $after[RelationshipDynamics::LL_TIME]);
        $this->assertSame(['scholarly', 'nature'], array_keys($unmet));
        $this->assertLessThan($before['scholarly'], $after['scholarly'], 'the books she would rather be at are further away');
        $this->assertLessThan($before['nature'], $after['nature']);

        // the one who enjoys it: a full share of time together, nothing taken
        $huntress = $this->pair(self::huntress());
        $hBefore = RelDynFulfillment::levelsAt(RelDynFulfillment::pairState($huntress), self::T0 + self::HOUR);
        $hUnmet = [];
        $hTime = RelDynFulfillment::recordSharedFight($huntress, self::huntress(), self::T0 + self::HOUR, RelDynFulfillment::PLAYER, $hUnmet);
        $hAfter = RelDynFulfillment::levelsAt(RelDynFulfillment::pairState($huntress), self::T0 + self::HOUR);
        $this->assertSame([], $hUnmet);
        $this->assertGreaterThan(4.0 * $time[RelationshipDynamics::LL_TIME], $hTime[RelationshipDynamics::LL_TIME], 'meaningfully apart');
        $this->assertEqualsWithDelta($hBefore['nature'], $hAfter['nature'], 1e-3, 'nothing taken off what she enjoys');
        // her band reads it: the same fight, the scholar's band is lower than the huntress's
        $sBand = RelDynFulfillment::bandAt(RelDynFulfillment::pairState($scholar), self::T0 + self::HOUR);
        $hBand = RelDynFulfillment::bandAt(RelDynFulfillment::pairState($huntress), self::T0 + self::HOUR);
        $this->assertLessThan($hBand, $sBand);
        // and what she misses is nameable: the generic unmet line speaks of the things she loves, not the fight
        $phrases = RelDynFulfillment::unmetPhrases(RelDynFulfillment::pairState($scholar), self::T0 + self::HOUR, 2);
        $this->assertNotSame([], $phrases);
    }

    public function testTheUnmetSideIsSwitchedWithTheFightAndItsKnobsMerge(): void
    {
        $sf = RelDynFulfillment::sharedFightConfig();
        $this->assertSame(true, $sf['contact']);
        $this->assertSame(0.5, $sf['unmet']['units']);
        // a stored config that predates §23 (no 'unmet', no 'contact') reads the defaults
        $GLOBALS['db'] = new RelDynFulfillmentConfigDbV(['fulfillment' => ['shared_fight' => ['enabled' => true, 'units' => 0.5, 'facets' => ['combat' => 1.0], 'min_weight' => 0.1]]]);
        RelationshipDynamics::clearConfigCache();
        $old = RelDynFulfillment::sharedFightConfig();
        $this->assertSame(0.5, $old['unmet']['units']);
        $this->assertEquals(['combat' => 1.0], $old['facets']);
        // a partial 'unmet' keeps the rest of its defaults
        $GLOBALS['db'] = new RelDynFulfillmentConfigDbV(['fulfillment' => ['shared_fight' => ['unmet' => ['units' => 0.0]]]]);
        RelationshipDynamics::clearConfigCache();
        $this->assertSame(2, RelDynFulfillment::sharedFightConfig()['unmet']['axes']);
        $d = $this->pair(self::scholar());
        $this->assertSame([], RelDynFulfillment::sharedFightUnmet(RelDynFulfillment::pairState($d), self::scholar()), 'switched to nothing, nothing taken');
    }

    // =====================================================================
    // §23 shared fights are contact for the neglect and absence rules
    // =====================================================================

    /** A spouse (RelDyn bond 'bonded') last spoken to at T0. */
    private function spouse(): array
    {
        $this->at(self::T0);
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Independent', 'relationship_type' => 'bonded',
            '_accumulated_play_gamets' => 10.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
        ]));
        $d['dimensions']['maturity']['x'] = 50.0;
        $d['dimensions']['resentment']['x'] = 0.0;
        RelationshipDynamics::markContact($d);
        $d['_decay_last_game_gamets'] = self::T0;
        return $d;
    }

    public function testAFightBesideThePlayerIsContactTheNeglectCountsFrom(): void
    {
        $quiet = $this->spouse();
        $fought = $this->spouse();
        $this->assertTrue(RelationshipDynamics::markFightContact($fought, self::T0 + 5 * self::DAY));
        $this->assertEqualsWithDelta(self::T0 + 5 * self::DAY, RelationshipDynamics::lastContactGamets($fought), 1e-6);
        $this->assertEqualsWithDelta(self::T0, RelationshipDynamics::lastContactGamets($quiet), 1e-6);
        $this->assertEqualsWithDelta(self::T0, floatval($fought['_last_contact_gamets']), 1e-6, 'the player\'s own word is what it was');
        $a = RelationshipDynamics::advanceCalendar($quiet, self::T0, self::T0 + 12 * self::DAY);
        $b = RelationshipDynamics::advanceCalendar($fought, self::T0, self::T0 + 12 * self::DAY);
        $this->assertGreaterThan(5.0, $a['neglect_days'], 'twelve days without a word, past the days of grace');
        $this->assertEqualsWithDelta($a['neglect_days'] - 5.0, $b['neglect_days'], 1e-6, 'the five days of it up to the fight she was fighting beside the player are not neglect');
        $this->assertLessThan($a['resentment_raw'], $b['resentment_raw']);
        // the grace runs from the fight
        $this->assertEqualsWithDelta(5.0 * self::DAY, RelationshipDynamics::neglectGraceEndGamets($fought) - RelationshipDynamics::neglectGraceEndGamets($quiet), 1e-3 * self::DAY, 'the grace runs from the fight');
        // an older fight never moves it back
        $this->assertFalse(RelationshipDynamics::markFightContact($fought, self::T0 + 2 * self::DAY, 0.0));
        $this->assertEqualsWithDelta(self::T0 + 5 * self::DAY, RelationshipDynamics::lastContactGamets($fought), 1e-6);
    }

    public function testAFightIsNoAbsenceForTheAffinityDecayEither(): void
    {
        $quiet = $this->spouse();
        $fought = $this->spouse();
        RelationshipDynamics::markFightContact($fought, self::T0 + 1 * self::DAY, 2.0);
        $this->at(self::T0 + 2 * self::DAY);
        $ticksQuiet = RelationshipDynamics::calculateDecayTicks($quiet);
        $ticksFought = RelationshipDynamics::calculateDecayTicks($fought);
        $this->assertGreaterThan(0.0, $ticksQuiet);
        $this->assertEqualsWithDelta(2.0 * self::HOUR / RelationshipDynamics::GAMETS_PER_DECAY_TICK, $ticksQuiet - $ticksFought, 1e-6, 'the two hours the fight went on');
        // overlapping fights are one stretch, not counted twice
        $d = $this->spouse();
        RelationshipDynamics::markFightContact($d, self::T0 + 1 * self::DAY, 2.0);
        RelationshipDynamics::markFightContact($d, self::T0 + 1 * self::DAY + self::HOUR, 2.0);
        $paused = array_values(array_filter((array) $d['_decay_paused_intervals'], fn($iv) => ($iv['until'] ?? null) !== null));
        $this->assertCount(1, $paused);
        $this->assertEqualsWithDelta(3.0 * self::HOUR, $paused[0]['until'] - $paused[0]['from'], 1e-6);
        $this->at(self::T0 + 2 * self::DAY);
        $this->assertEqualsWithDelta(3.0 * self::HOUR / RelationshipDynamics::GAMETS_PER_DECAY_TICK, $ticksQuiet - RelationshipDynamics::calculateDecayTicks($d), 1e-6);
    }

    public function testTheReturnFromAnAbsenceStaysWithThePlayersWord(): void
    {
        // a fight is contact for the neglect and the decay; the reunion and the bond break measure the time since the player last
        // spoke to this NPC (markContact), so a return by a fight is still met at the first word
        $d = $this->spouse();
        RelationshipDynamics::markFightContact($d, self::T0 + 5 * self::DAY);
        $this->at(self::T0 + 6 * self::DAY);
        RelationshipDynamics::markContact($d);
        $this->assertEqualsWithDelta(self::T0, floatval($d['_previous_contact_gamets']), 1e-6);
        $this->assertEqualsWithDelta(self::T0 + 6 * self::DAY, RelationshipDynamics::lastContactGamets($d), 1e-6, 'and the later word is the contact again');
    }
}

/** Just enough of `sql` for a stored RelDyn config row (the stored settings over the defaults). */
final class RelDynFulfillmentConfigDbV
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode(array_merge(RelationshipDynamics::defaultConfig(), $this->config))] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}
