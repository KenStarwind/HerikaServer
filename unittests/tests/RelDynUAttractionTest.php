<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynUAttractionConfigDb
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
    public function execQuery($sql) { return true; }
}

/**
 * Rulings 2026-10-01 §20 (Ken), the attraction lane, pure parts:
 *   §20.1  a passion spike skips the uphill only with a prerequisite (very attractive: her type; or a
 *          status gap with attraction AND aspiration), as a degree, not a gate;
 *   §20.3  hidden interest counts (the Ick and the like), voicing is gated by shyness (low
 *          self-confidence) and bond depth; the fear of losing the relationship (keeping) scales by her
 *          attachment corners, never a hard switch, and costs the bond above a point;
 *   §20.4  fighting beside the player is time together, weighted by her combat / adventure / danger taste.
 * The test beds through the real hooks are RelDynUAttractionTestBedsPostgresTest.
 */
final class RelDynUAttractionTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->config([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** The stored config row: the defaults with $edit's top-level keys replaced. */
    private function config(array $edit): void
    {
        $GLOBALS['db'] = new RelDynUAttractionConfigDb(array_replace(RelationshipDynamics::defaultConfig(), $edit));
        RelationshipDynamics::clearConfigCache();
    }

    private function units(array $m): array
    {
        $out = [];
        foreach ($m as $key => [$axis, $hill]) $out[$key] = ['axis' => $axis, 'm_hill' => $hill];
        return $out;
    }

    /** An attraction summary as stored (hand-made: only the fields the spike reads). */
    private function summary(array $over = []): array
    {
        return array_merge(['enabled' => true, 'spark' => 20.0, 'spark_mult' => 1.0, 'passion_mult' => 0.25], $over);
    }

    // ------------------------------------------------------------------ §20.1

    public function testTheTypeRampsFromHerFloorToTenPointsPast(): void
    {
        $p = fn(array $u) => RelDynAttraction::spikePrerequisite($this->units($u), null, null, 'soft', false);
        $this->assertSame(0.0, $p(['strength' => ['visceral', 1.0]])['weight'], 'her floor met is not yet very attractive');
        $this->assertEqualsWithDelta(0.5, $p(['strength' => ['visceral', 1.05]])['weight'], 1e-9);
        $this->assertSame(1.0, $p(['strength' => ['visceral', 1.1]])['weight']);
        $this->assertSame('type', $p(['strength' => ['visceral', 1.2]])['via']);
        // the weakest visceral unit sets it: a type is what she wants in the body, all of it
        $this->assertSame(0.0, $p(['beauty' => ['visceral', 1.0], 'strength' => ['visceral', 0.6]])['weight']);
        $this->assertSame(0.0, $p(['beauty' => ['visceral', 1.25], 'strength' => ['visceral', 0.6]])['weight'], 'below her floor on one');
        // sociological units do not count while she has visceral ones; with none, they are what she has
        $this->assertSame(1.0, $p(['strength' => ['visceral', 1.1], 'status' => ['sociological', 0.4]])['weight']);
        $this->assertSame(1.0, $p(['status' => ['sociological', 1.1]])['weight'], 'an NPC with no visceral unit reads them all');
        $none = RelDynAttraction::spikePrerequisite([], null, null, 'soft', false);
        $this->assertSame(0.0, $none['weight']);
        $this->assertNull($none['type_m']);
        $this->assertNull($none['via']);
    }

    public function testAStatusGapNeedsAttractionAndAspirationAndHerOwnStandingBelowHis(): void
    {
        $gap = fn(float $player, float $own, string $rig, bool $attracted) => RelDynAttraction::spikePrerequisite([], $player, $own, $rig, $attracted);
        // a thane and a barmaid: far above, attracted, standing matters to her (soft = 0.6)
        $r = $gap(0.9, 0.35, 'soft', true);
        $this->assertSame(1.0 * 0.6, $r['weight']);
        $this->assertSame('status_gap', $r['via']);
        $this->assertEqualsWithDelta(0.55, $r['gap'], 1e-9);
        // admiration scales with how much she values standing
        $this->assertSame(0.85, $gap(0.9, 0.35, 'flexible', true)['weight']);
        $this->assertSame(1.0, $gap(0.9, 0.35, 'rigid', true)['weight']);
        $this->assertSame(0.0, $gap(0.9, 0.35, 'irrelevant', true)['weight'], 'standing means nothing to her: no aspiration');
        $this->assertSame(0.0, $gap(0.9, 0.35, 'rigid', false)['weight'], 'no attraction in it');
        // a modest gap is partial, not nothing and not everything
        $this->assertEqualsWithDelta(0.4 * 0.85, $gap(0.7, 0.4, 'flexible', true)['weight'], 1e-9);
        // no gap, or the player below her: the one who admires is her
        $this->assertSame(0.0, $gap(0.5, 0.4, 'rigid', true)['weight']);
        $this->assertSame(0.0, $gap(0.3, 0.8, 'rigid', true)['weight']);
        // the larger way in counts
        $both = RelDynAttraction::spikePrerequisite($this->units(['strength' => ['visceral', 1.1]]), 0.9, 0.35, 'soft', true);
        $this->assertSame(1.0, $both['weight']);
        $this->assertSame('type', $both['via']);
        // an unknown standing is no gap
        $this->assertNull(RelDynAttraction::spikePrerequisite([], null, 0.3, 'rigid', true)['gap']);
    }

    public function testASpikeBlendsTheUphillAndTheFullMoment(): void
    {
        $f = fn(array $over, float $passion = 40.0, float $raw = 13.0, ?array $tags = null) => RelDynAttraction::spikeFactor($this->summary($over), $passion, $raw, $tags);
        // the uphill is x0.25 above the spark: a moment with no prerequisite climbs it like any gain
        $this->assertEqualsWithDelta(0.25, $f(['spike_open' => 0.0]), 1e-9);
        $this->assertEqualsWithDelta(0.25 + 0.5 * 0.75, $f(['spike_open' => 0.5]), 1e-9);
        $this->assertEqualsWithDelta(1.0, $f(['spike_open' => 1.0]), 1e-9);
        // a prerequisite met in part is part of the way up
        $this->assertGreaterThan($f(['spike_open' => 0.2]), $f(['spike_open' => 0.4]));
        // below the spark the early spark is what it always was: open to anyone, at her attachment
        $this->assertEqualsWithDelta(0.8, $f(['spike_open' => 0.0, 'spark_mult' => 0.8], 10.0, 5.0), 1e-9);
        // a moment never beats the full moment (the uphill's surplus is not a bonus on top)
        $this->assertEqualsWithDelta(1.0, $f(['spike_open' => 0.0, 'passion_mult' => 1.25]), 1e-9);
        // what stays absolute either way: a hard zero, a closed channel, the MDD 1.4 ceiling
        $this->assertSame(0.0, $f(['spike_open' => 1.0, 'hard_zero' => 'orientation']));
        $this->assertSame(0.0, $f(['spike_open' => 1.0, 'spark_mult' => 0.0]));
        $this->assertSame(0.0, $f(['spike_open' => 1.0, 'passion_channels' => ['praise']], 40.0, 13.0, ['touch']));
        $this->assertEqualsWithDelta(5.0 / 13.0, $f(['spike_open' => 1.0, 'passion_ceiling' => 45.0]), 1e-9);
        $this->assertEqualsWithDelta(0.25, $f(['spike_open' => 0.0, 'passion_ceiling' => 45.0]), 1e-9, 'the uphill is the lower of the two');
        $this->assertSame(0.0, $f(['spike_open' => 0.0, 'passion_ceiling' => 40.0]), 'at the ceiling');
    }

    public function testASummaryWithNoStoredDegreeReadsItsOwnUnitsAndOneWithNoUnitsClimbs(): void
    {
        $hers = $this->summary(['passion' => ['units' => $this->units(['strength' => ['visceral', 1.2]])]]);
        $this->assertSame(1.0, RelDynAttraction::spikeOpen($hers));
        $this->assertEqualsWithDelta(1.0, RelDynAttraction::spikeFactor($hers, 40.0, 13.0), 1e-9);
        $this->assertSame(0.0, RelDynAttraction::spikeOpen($this->summary()), 'nothing says she finds him very attractive');
        $this->assertEqualsWithDelta(0.25, RelDynAttraction::spikeFactor($this->summary(), 40.0, 13.0), 1e-9);
        // a player nobody can read has nothing to climb: the matrix's open result lands a moment in full
        $this->assertSame(1.0, RelDynAttraction::spikeOpen(['spike_open' => 1.0]));
    }

    public function testTheSwitchBringsBackTheOldBypass(): void
    {
        $att = RelDynAttraction::defaults();
        $att['spike_prereq']['enabled'] = false;
        $this->config(['attraction' => $att]);
        $this->assertSame(1.0, RelDynAttraction::spikeOpen($this->summary()));
        $this->assertEqualsWithDelta(1.0, RelDynAttraction::spikeFactor($this->summary(), 40.0, 13.0), 1e-9);
        $this->assertSame(1.0, RelDynAttraction::spikePrerequisite([], null, null, 'soft', false)['weight']);
    }

    public function testHerOwnStandingComesFromWhoSheIs(): void
    {
        $noble = RelDynAttraction::definition('Noble Npc', ['_profile_autogen' => ['archetype' => 'Noble']]);
        $thief = RelDynAttraction::definition('Thief Npc', ['_profile_autogen' => ['archetype' => 'Thief']]);
        $this->assertGreaterThan($thief['own_standing'], $noble['own_standing']);
        $this->assertSame(0.8, $noble['own_standing']);
        $named = RelDynAttraction::definition('Thief Npc', ['_profile_autogen' => ['archetype' => 'Thief'], 'attraction_overrides' => ['standing' => 0.6]]);
        $this->assertSame(0.6, $named['own_standing'], 'the override names it');
        $this->assertSame('override', $named['sources']['standing']);
    }

    // ------------------------------------------------------------------ §20.4

    public function testFightingBesideThePlayerIsTimeTogetherByHowMuchSheLikesIt(): void
    {
        $aela = ['combat' => 0.9, 'adventure' => 0.7, 'danger' => 0.6];
        $scholar = ['combat' => -0.6, 'adventure' => -0.2, 'danger' => -0.5, 'scholarly' => 0.9];
        $this->assertEqualsWithDelta(0.5 + 0.5 * (0.9 + 0.6 * 0.7 + 0.5 * 0.6) / 2.1, RelDynFulfillment::sharedFightWeight($aela), 1e-9);
        $this->assertGreaterThan(0.85, RelDynFulfillment::sharedFightWeight($aela));
        $this->assertLessThan(0.3, RelDynFulfillment::sharedFightWeight($scholar));
        $this->assertGreaterThan(RelDynFulfillment::sharedFightWeight($scholar), RelDynFulfillment::sharedFightWeight($aela));
        $this->assertEqualsWithDelta(0.5, RelDynFulfillment::sharedFightWeight([]), 1e-9, 'no taste either way: half');
        $this->assertEqualsWithDelta(0.1, RelDynFulfillment::sharedFightWeight(['combat' => -1.0, 'adventure' => -1.0, 'danger' => -1.0]), 1e-9,
            'never nothing, never a penalty: the floor');

        $deliver = function (array $prefs): float {
            $d = RelationshipDynamics::defaultDynamics();
            $now = 200.0 * RelationshipDynamics::GAMETS_PER_DAY;
            RelDynFulfillment::setPairState($d, 'Player', ['v' => 1, 'since' => $now, 'gamets' => $now, 'w' => [RelationshipDynamics::LL_TIME => 1.0],
                'lv' => [RelationshipDynamics::LL_TIME => 1.0], 'r' => [], 'sampled_gamets' => $now, 'days' => [], 'boundary' => ['state' => 'none']]);
            $applied = RelDynFulfillment::recordSharedFight($d, $prefs, $now);
            return floatval($applied[RelationshipDynamics::LL_TIME] ?? 0.0);
        };
        $a = $deliver($aela);
        $s = $deliver($scholar);
        $this->assertGreaterThan(0.4, $a, 'Aela: nearly a full half unit of time together');
        $this->assertLessThan(0.15, $s);
        $this->assertGreaterThan(2.5 * $s, $a, 'a fight is her evening, not the scholar\'s');
        // an NPC with no time-together need has nothing to deliver it to; switched off, nothing
        $d = RelationshipDynamics::defaultDynamics();
        $now = 200.0 * RelationshipDynamics::GAMETS_PER_DAY;
        RelDynFulfillment::setPairState($d, 'Player', ['v' => 1, 'since' => $now, 'gamets' => $now, 'w' => ['combat' => 1.0], 'lv' => ['combat' => 1.0],
            'r' => [], 'sampled_gamets' => $now, 'days' => [], 'boundary' => ['state' => 'none']]);
        $this->assertSame([], RelDynFulfillment::recordSharedFight($d, $aela, $now));
        $ful = RelDynFulfillment::configDefaults();
        $ful['shared_fight']['enabled'] = false;
        $this->config(['fulfillment' => $ful]);
        $this->assertSame(0.0, $deliver($aela));
    }

    // ------------------------------------------------------------------ §20.3 interest and voicing

    private function drawn(float $passion, array $over = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        RelationshipDynamics::setPassion($d, $passion);
        $d['_attraction'] = array_merge(['enabled' => true, 'attracted' => true, 'hard_zero' => null], $over);
        return $d;
    }

    public function testHiddenInterestCountsWhetherOrNotSheShowsIt(): void
    {
        $this->assertSame(['interest' => 0.3, 'drawn' => true], RelDynAttraction::interest($this->drawn(0.0)), 'drawn from the first: at least the floor');
        $this->assertSame(0.5, RelDynAttraction::interest($this->drawn(25.0))['interest']);
        $this->assertSame(1.0, RelDynAttraction::interest($this->drawn(80.0))['interest']);
        $this->assertSame(0.0, RelDynAttraction::interest($this->drawn(60.0, ['attracted' => false]))['interest'], 'not drawn');
        $this->assertSame(0.0, RelDynAttraction::interest($this->drawn(60.0, ['hard_zero' => 'orientation']))['interest']);
        $this->assertTrue(RelDynAttraction::interest($this->drawn(45.0, ['attracted' => false, 'won_over' => true]))['drawn'], 'won over is drawn');
        $this->assertSame(0.0, RelDynAttraction::interest(RelationshipDynamics::defaultDynamics())['interest'], 'no matrix read: unknown, not assumed');
    }

    public function testShynessIsLowSelfConfidence(): void
    {
        $conf = function (?float $x): array {
            $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
            $d['dimensions']['self_confidence']['x'] = $x;
            return $d;
        };
        $this->assertSame(0.0, RelDynAttraction::shyness($conf(50.0)));
        $this->assertSame(0.0, RelDynAttraction::shyness($conf(80.0)));
        $this->assertSame(0.5, RelDynAttraction::shyness($conf(32.5)));
        $this->assertSame(1.0, RelDynAttraction::shyness($conf(15.0)));
        $this->assertSame(1.0, RelDynAttraction::shyness($conf(0.0)));
        $this->assertSame(0.0, RelDynAttraction::shyness($conf(null)), 'unknown is not shy');
        // low self-esteem (resentment toward herself) adds to it: nothing up to 30, then whole at 100 x 0.6
        $est = function (?float $c, float $rs) use ($conf): float {
            $d = $conf($c);
            $d['dimensions']['resentment_self']['x'] = $rs;
            return RelDynAttraction::shyness($d);
        };
        $this->assertSame(0.0, $est(50.0, 30.0));
        $this->assertSame(0.3, $est(50.0, 65.0));
        $this->assertSame(0.6, $est(50.0, 100.0));
        $this->assertEqualsWithDelta(0.8, $est(32.5, 100.0), 1e-9, 'a soft-or: both together, still short of a wall');
        $this->assertLessThan(1.0, $est(40.0, 90.0));
        $this->assertGreaterThan($est(40.0, 0.0), $est(40.0, 90.0));
    }

    public function testVoicingNeedsADeeperBondTheShyerSheIs(): void
    {
        $v = fn(array $in) => RelDynAttraction::voiced($in + ['tier' => 2, 'passion' => 10.0, 'core_aff' => 40.0, 'romantic' => false, 'romance_effective' => 0,
            'shyness' => 0.0, 'flirt_min_tier' => 2, 'flirt_passion_min' => 40.0]);
        // unshy: exactly the old rule
        $this->assertTrue($v([]), 'a friend answers');
        $this->assertFalse($v(['tier' => 1]), 'an acquaintance with a spark does not');
        $this->assertTrue($v(['tier' => 1, 'passion' => 40.0]));
        $this->assertTrue($v(['tier' => 1, 'romantic' => true]));
        $this->assertTrue($v(['tier' => 1, 'romance_effective' => RelDynAttraction::ROMANCE_CRUSH]), 'an earned crush answers');
        // shy: a friend (core 40) is not enough; she needs her bond to run deeper (friend 31 -> bonded 76 by shyness)
        $this->assertFalse($v(['shyness' => 1.0]));
        $this->assertFalse($v(['shyness' => 1.0, 'core_aff' => 75.0]));
        $this->assertTrue($v(['shyness' => 1.0, 'core_aff' => 76.0]));
        $this->assertTrue($v(['shyness' => 0.5, 'core_aff' => 54.0]), 'half shy: halfway between friend and bonded');
        $this->assertFalse($v(['shyness' => 0.5, 'core_aff' => 53.0]));
        $this->assertTrue($v(['shyness' => 0.2, 'core_aff' => 41.0]));
        $this->assertFalse($v(['shyness' => 0.2, 'core_aff' => 39.0]));
        // and more passion to make up for it: never a wall (a burning pull still breaks through)
        $this->assertFalse($v(['shyness' => 1.0, 'passion' => 79.0]));
        $this->assertTrue($v(['shyness' => 1.0, 'passion' => 80.0]));
        // an earned level in the matrix is not a romance she has said; a title is
        $this->assertFalse($v(['shyness' => 1.0, 'romance_effective' => RelDynAttraction::ROMANCE_CRUSH]));
        $this->assertTrue($v(['shyness' => 1.0, 'romantic' => true]));
        // monotone in shyness
        $last = true;
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $s) {
            $now = $v(['shyness' => $s, 'core_aff' => 60.0]);
            $this->assertTrue($last || !$now, 'a shier NPC never voices what a bolder one at the same bond does not');
            $last = $now;
        }
        // switched off: no shyness gating
        $att = RelDynAttraction::defaults();
        $att['interest']['enabled'] = false;
        $this->config(['attraction' => $att]);
        $this->assertTrue($v(['shyness' => 1.0]));
        $this->assertSame(0.0, RelDynAttraction::shyness($this->drawn(10.0)));
    }

    public function testTheFeltTextKeepsHerInterestAndSaysWhyItStaysUnvoiced(): void
    {
        $sum = ['enabled' => true, 'outcome' => 'drawn', 'romance' => ['effective' => 1], 'passion' => ['curve' => 1.0]];
        $text = fn(array $ctx) => (string) RelDynAttraction::feltText('Lynly', $sum, $ctx + ['player' => 'Kaida', 'tier' => 2, 'passion' => 30.0, 'core_aff' => 45.0, 'shyness' => 0.0]);
        $bold = $text([]);
        $this->assertStringContainsString('answer', $bold);
        $this->assertStringContainsString('eyes keep finding Kaida', $bold);
        $shy = $text(['shyness' => 0.9]);
        $this->assertStringContainsString('eyes keep finding Kaida', $shy, 'she is still drawn');
        $this->assertStringContainsString('too unsure to say so', $shy);
        $this->assertStringNotContainsString('answer', $shy);
        $this->assertStringNotContainsString('herself', $shy, 'no pronoun for her');
        // a deeper bond lets it out
        $this->assertStringContainsString('answer', $text(['shyness' => 0.9, 'core_aff' => 80.0, 'tier' => 3]));
        // barely shy and unvoiced: the plain look, not shyness
        $plain = $text(['shyness' => 0.1, 'tier' => 1, 'core_aff' => 10.0, 'passion' => 5.0]);
        $this->assertStringContainsString('no further than a look', $plain);
        $this->assertDoesNotMatchRegularExpression('/\d/', $shy, 'feelings, never numbers');
    }

    // ------------------------------------------------------------------ §20.3 the Ick

    public function testAShyReplyIsInterestReturnedWhenSheIsDrawnAndPressureWhenSheIsNot(): void
    {
        $drawn = $this->drawn(30.0);
        $cold = $this->drawn(30.0, ['attracted' => false]);
        $ll = RelationshipDynamics::LL_TOUCH;
        $this->assertTrue(RelationshipDynamics::isRomanticAttempt($ll, 'shy', []), 'with no state: touch she did not answer in kind');
        $this->assertFalse(RelationshipDynamics::isRomanticAttempt($ll, 'shy', [], $drawn), 'drawn but shy is still interested');
        $this->assertTrue(RelationshipDynamics::isRomanticAttempt($ll, 'shy', [], $cold), 'not drawn: a shy reply is just a shy reply');
        $this->assertTrue(RelationshipDynamics::isRomanticAttempt($ll, 'angry', [], $drawn), 'only the shy mood counts as interest returned');
        $this->assertFalse(RelationshipDynamics::isRomanticAttempt($ll, 'playful', [], $cold), 'her reciprocal moods stand');
        // the eval's romantic_intent, same rule
        $this->assertFalse(RelationshipDynamics::isRomanticAttempt(null, 'shy', ['romantic_intent' => 3], $drawn));
        $this->assertTrue(RelationshipDynamics::isRomanticAttempt(null, 'shy', ['romantic_intent' => 3], $cold));
    }

    public function testInterestLiftsTheIckThresholdButNeverToNoIckAtAll(): void
    {
        $setup = function (float $passion, bool $attracted): array {
            $d = $this->drawn($passion, ['attracted' => $attracted]);
            $d['dimensions']['comfort']['x'] = 30.0;
            $d['dimensions']['maturity']['x'] = 50.0;
            $d['_ick_tracker'] = ['romantic_count' => 8, 'total_count' => 10, 'window_start' => 0, 'ick_active' => false, 'ick_cooldown_until_play_gamets' => 0];
            return $d;
        };
        // 80% of the window is courting; the baseline threshold is 0.5 x (1 + maturity / 100) = 0.75
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($setup(2.0, false), 'Stoic'), 'a stranger is pushed too far');
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($setup(2.0, true), 'Stoic'), 'someone she is drawn to takes more (0.75 x 1.15)');
        // but not everything: a ratio of 1 is every exchange
        $all = $setup(2.0, true);
        $all['_ick_tracker']['romantic_count'] = 10;
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($all, 'Stoic'), 'no one is immune');
    }

    // ------------------------------------------------------------------ §20.3 keeping

    private function partner(string $style, array $dims = [], ?array $axes = null): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['profile_overrides'] = $axes !== null ? ['attachment_axes' => $axes] : ['attachment_style' => $style];
        $this->core($d, 60.0);
        foreach (array_merge(['comfort' => 60.0, 'trust' => 60.0, 'maturity' => 50.0, 'self_confidence' => 50.0, 'jealousy' => 0.0], $dims) as $k => $x) {
            $d['dimensions'][$k]['x'] = $x;
        }
        return $d;
    }

    /** Core's affinity to the player (the mirror's mark, then the value). */
    private function core(array &$d, float $aff): void
    {
        $d['_aff_mirror_x'] = 50.0;
        RelationshipDynamics::setCoreAffinityValue($d, $aff);
    }

    private function fearOf(array $d, float $now): float
    {
        RelDynKeeping::advance('Muiri', $d, $now);
        return RelDynKeeping::fear($d);
    }

    public function testEveryoneFearsLosingThePlayerByWhoTheyAreNotByAFlag(): void
    {
        $now = 210.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $jealous = ['jealousy' => 80.0];
        $toxic = $this->fearOf($this->partner('toxic', $jealous), $now);
        $anxious = $this->fearOf($this->partner('anxious', $jealous), $now);
        $avoidant = $this->fearOf($this->partner('avoidant', $jealous), $now);
        $secure = $this->fearOf($this->partner('secure', $jealous), $now);
        $this->assertGreaterThan($anxious, $toxic);
        $this->assertGreaterThan($avoidant, $anxious);
        $this->assertGreaterThan($secure, $avoidant);
        $this->assertGreaterThan(0.0, $secure, 'secure is small, never zero');
        $this->assertGreaterThan(0.5, $toxic, 'a toxic NPC, jealous, is clinging');
        $this->assertLessThan(0.25, $secure, 'a secure one says nothing about it');
        // continuous in her axes: half-way between secure and anxious fears half-way between
        $half = $this->fearOf($this->partner('', $jealous, ['anxiety' => 0.5, 'avoidance' => 0.15]), $now);
        $this->assertGreaterThan($secure, $half);
        $this->assertLessThan($anxious, $half);
        // and nothing to lose, nothing to fear: a stranger, whoever she is
        $stranger = $this->partner('toxic', $jealous + ['comfort' => 20.0, 'trust' => 20.0]);
        $this->core($stranger, 0.0);
        $this->assertSame(0.0, $this->fearOf($stranger, $now));
    }

    public function testWhatLooksLikeLosingHimRaisesItAndInsecurityAndPossessivenessScaleIt(): void
    {
        $now = 210.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $calm = $this->fearOf($this->partner('toxic'), $now);
        $this->assertGreaterThan(0.0, $calm, 'a worry with nothing wrong: the paranoia');
        $this->assertLessThan(0.25, $calm);
        $away = $this->partner('toxic');
        $away['_previous_contact_gamets'] = $now - 5.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $jealous = $this->partner('toxic', ['jealousy' => 70.0]);
        $this->assertGreaterThan($calm, $this->fearOf($away, $now), 'five days without a word');
        $this->assertGreaterThan($calm, $this->fearOf($jealous, $now));
        $this->assertGreaterThan($this->fearOf($away, $now), $this->fearOf($jealous, $now), 'jealousy weighs more than the silence');
        $unsure = $this->partner('toxic', ['jealousy' => 70.0, 'self_confidence' => 10.0]);
        $sure = $this->partner('toxic', ['jealousy' => 70.0, 'self_confidence' => 90.0]);
        $this->assertGreaterThan($this->fearOf($sure, $now), $this->fearOf($unsure, $now), 'the insecure fear it more');
        $this->assertGreaterThan(0.0, $this->fearOf($sure, $now), 'the sure are not immune');
        // she cannot be pushed past what there is to lose: a thin bond fears less
        $thin = $this->partner('toxic', ['jealousy' => 70.0, 'comfort' => 40.0, 'trust' => 40.0]);
        $this->core($thin, 20.0);
        $this->assertLessThan($this->fearOf($jealous, $now), $this->fearOf($thin, $now));
    }

    public function testTheGripCostsTheBondAndLiftsExactlyAsTheFearFades(): void
    {
        $now = 210.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $hour = RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        $d = $this->partner('toxic', ['jealousy' => 90.0, 'maturity' => 20.0]);
        $r = RelDynKeeping::advance('Muiri', $d, $now);
        $this->assertContains($r['band'], ['clinging', 'controlling']);
        $this->assertLessThan(60.0, $d['dimensions']['trust']['x'], 'paranoia holds her trust down');
        $this->assertLessThan(60.0, $d['dimensions']['comfort']['x']);
        $this->assertLessThan(0.0, RelationshipDynamics::heldTemporaryOffset($d, 'trust'), 'a held offset, the physics reads her without it');
        $this->assertEqualsWithDelta(round($d['dimensions']['trust']['x'], 2), $d['dimensions']['trust']['x'], 1e-9, 'a hundredth of a point at a time (Jev and the display round to them)');
        $held = RelationshipDynamics::heldTemporaryOffset($d, 'trust');
        $this->assertEqualsWithDelta(60.0 + $held, 60.0 + $d[RelDynKeeping::KEY]['applied']['trust'], 1e-9);
        // the grip is bounded
        $this->assertGreaterThanOrEqual(-12.0 - 1e-9, $d[RelDynKeeping::KEY]['applied']['trust']);
        // a mature NPC holds it better, never entirely
        $m = $this->partner('toxic', ['jealousy' => 90.0, 'maturity' => 90.0]);
        RelDynKeeping::advance('Muiri', $m, $now);
        $this->assertLessThan(0.0, $m[RelDynKeeping::KEY]['applied']['trust']);
        $this->assertGreaterThan($d[RelDynKeeping::KEY]['applied']['trust'], $m[RelDynKeeping::KEY]['applied']['trust'], 'the mature lose less of their trust to it');
        // the fear eases (he is back, nothing looks like leaving) and the offsets lift without residue
        $d['dimensions']['jealousy']['x'] = 0.0;
        $t = $now;
        for ($i = 0; $i < 12; $i++) {
            $t += 72 * $hour;
            RelDynKeeping::advance('Muiri', $d, $t);
        }
        $this->assertLessThan(0.25, RelDynKeeping::fear($d), 'the worry settles to who she is');
        $this->assertEqualsWithDelta(60.0, $d['dimensions']['trust']['x'], 1e-6, 'trust is back where it was');
        $this->assertEqualsWithDelta(60.0, $d['dimensions']['comfort']['x'], 1e-6);
        $this->assertSame(0.0, $d[RelDynKeeping::KEY]['applied']['trust']);
    }

    public function testSwitchedOffTheFearGoesAndWhatItHeldIsLifted(): void
    {
        $now = 210.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $d = $this->partner('toxic', ['jealousy' => 90.0]);
        RelDynKeeping::advance('Muiri', $d, $now);
        $this->assertLessThan(60.0, $d['dimensions']['trust']['x']);
        $keeping = RelDynKeeping::configDefaults();
        $keeping['enabled'] = false;
        $this->config(['keeping' => $keeping]);
        RelDynKeeping::advance('Muiri', $d, $now + 60);
        $this->assertEqualsWithDelta(60.0, $d['dimensions']['trust']['x'], 1e-6);
        $this->assertArrayNotHasKey(RelDynKeeping::KEY, $d);
        $this->assertNull(RelDynKeeping::feltLine($d, 'Muiri', 'Kaida'));
        $this->assertSame(0.0, RelDynKeeping::fear($d));
    }

    public function testSheSaysItByHowMatureSheIsAndNeverInNumbers(): void
    {
        $now = 210.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $line = function (array $dims, string $style = 'toxic') use ($now): ?array {
            $d = $this->partner($style, $dims);
            RelDynKeeping::advance('Muiri', $d, $now);
            return RelDynKeeping::feltLine($d, 'Muiri', 'Kaida');
        };
        $mature = $line(['jealousy' => 90.0, 'maturity' => 90.0]);
        $raw = $line(['jealousy' => 90.0, 'maturity' => 10.0]);
        $this->assertNotNull($mature);
        $this->assertNotNull($raw);
        $this->assertSame('mature', $mature['expression']);
        $this->assertSame('immature', $raw['expression']);
        $this->assertNotSame($mature['text'], $raw['text']);
        foreach ([$mature, $raw] as $l) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $l['text'], 'feelings, never numbers');
            $this->assertStringContainsString('Muiri', $l['text']);
            $this->assertStringNotContainsString('{', $l['text']);
        }
        $this->assertGreaterThan(0.5, $raw['salience']);
        // a stranger has nothing to lose; a secure partner says nothing
        $d = $this->partner('toxic', ['jealousy' => 90.0]);
        RelDynKeeping::advance('Muiri', $d, $now);
        $this->assertNull(RelDynKeeping::feltLine($d, 'Muiri', 'Kaida', 0));
        $this->assertNull($line(['jealousy' => 90.0], 'secure'));
        // the stronger the fear, the stronger the words
        $this->assertSame('uneasy', RelDynKeeping::band(0.3));
        $this->assertSame('clinging', RelDynKeeping::band(0.55));
        $this->assertSame('controlling', RelDynKeeping::band(0.8));
        $this->assertNull(RelDynKeeping::band(0.1));
        // Jev gets the numbers
        $jev = RelDynKeeping::jev($d);
        $this->assertSame(true, $jev['enabled']);
        $this->assertGreaterThan(0.25, $jev['fear']);
        $this->assertArrayHasKey('jealousy', $jev['threat']);
        $this->assertLessThan(0.0, $jev['held']['trust']);
    }
}
