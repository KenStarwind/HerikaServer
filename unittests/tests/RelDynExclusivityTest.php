<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynExclusivityConfigDb
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
}

/**
 * Natural exclusivity (decisions 2026-09-24 §17), the pure parts: the pull (drive x who she is
 * x title, weakened by low fulfillment and neglect), its bands, her style, the romantic-move
 * markers, the damped suitor ledger and the felt line. No database (a config stub where a test
 * changes config), fixed game timestamps.
 */
final class RelDynExclusivityTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RECHAT_PREVIOUS_SPEAKER'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelDynTraits::$assignmentOverride = 'label';   // the preset points, no stored read
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelDynTraits::$assignmentOverride = null;
        RelationshipDynamics::clearConfigCache();
    }

    private function config(array $exclusivity): void
    {
        $GLOBALS['db'] = new RelDynExclusivityConfigDb(array_merge(RelationshipDynamics::defaultConfig(),
            ['exclusivity' => array_replace(RelDynExclusivity::configDefaults(), $exclusivity)]));
        RelationshipDynamics::clearConfigCache();
    }

    /** A partner-to-be: core affinity $aff, passion $passion, $type, a fulfilled player pair, maturity 60, secure. */
    private function npc(float $passion = 45.0, float $aff = 60.0, string $type = 'platonic', array $o = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Gentle',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'traits' => [],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            '_core_rel_type' => $type,
        ], $o));
        RelationshipDynamics::refreshAffinityMirror($d, $aff);
        RelationshipDynamics::setPassion($d, $passion);
        $d['dimensions']['maturity']['x'] = 60.0;
        RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0);
        $s = RelDynFulfillment::pairState($d);
        $s['lv'] = array_map(fn() => 2.25, $s['lv']);
        RelDynFulfillment::setPairState($d, RelDynFulfillment::PLAYER, $s);
        RelDynFulfillment::recordContactDay($d, self::T0 + self::HOUR);
        $d['_last_contact_gamets'] = self::T0 + self::HOUR;
        return $d;
    }

    public function testNoSparkNoPullWhateverTheBondOrTitle(): void
    {
        foreach (['platonic', 'romantic', 'crush'] as $type) {
            $p = RelDynExclusivity::pull($this->npc(0.0, 90.0, $type), self::T0 + 2 * self::HOUR);
            $this->assertSame(0.0, $p['pull'], "{$type}: a close friend is not exclusive, a title alone creates nothing");
            $this->assertSame(RelDynExclusivity::BAND_OPEN, $p['band']);
        }
        // The spark gate opens linearly to spark_passion
        $half = RelDynExclusivity::pull($this->npc(10.0), self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta(0.5, $half['gate'], 1e-9);
    }

    public function testTheDriveGrowsWithPassionBondAndFulfillment(): void
    {
        $at = self::T0 + 2 * self::HOUR;
        $base = RelDynExclusivity::pull($this->npc(45.0, 60.0), $at);
        $this->assertGreaterThan($base['pull'], RelDynExclusivity::pull($this->npc(60.0, 60.0), $at)['pull'], 'passion');
        $this->assertGreaterThan($base['pull'], RelDynExclusivity::pull($this->npc(45.0, 80.0), $at)['pull'], 'bond depth');
        $low = $this->npc(45.0, 60.0);
        $s = RelDynFulfillment::pairState($low);
        $s['lv'] = array_map(fn() => 0.75, $s['lv']);   // a quarter covered: band -0.5
        RelDynFulfillment::setPairState($low, RelDynFulfillment::PLAYER, $s);
        $lp = RelDynExclusivity::pull($low, $at);
        $this->assertLessThan($base['pull'], $lp['pull'], 'fulfillment');
        $this->assertLessThan(1.0, $lp['low_cut'], 'a band below the low band cuts it');
        // The drive's weighted mean: passion 45/60, bond (60-20)/60, fulfillment (0.5+1)/2
        $this->assertEqualsWithDelta(0.4 * 0.75 + 0.35 * (40 / 60) + 0.25 * 0.75, $base['drive'], 0.01, 'two game hours of decay aside');
    }

    public function testATitleMultipliesTheDriveItDoesNotAddToIt(): void
    {
        $at = self::T0 + 2 * self::HOUR;
        $plain = RelDynExclusivity::pull($this->npc(30.0, 50.0, 'platonic'), $at);
        $titled = RelDynExclusivity::pull($this->npc(30.0, 50.0, 'romantic'), $at);
        $crush = RelDynExclusivity::pull($this->npc(30.0, 50.0, 'crush'), $at);
        $this->assertFalse($plain['titled']);
        $this->assertTrue($titled['titled']);
        $this->assertEqualsWithDelta(1.35 * $plain['pull'], $titled['pull'], 1e-3);
        $this->assertEqualsWithDelta(1.1 * $plain['pull'], $crush['pull'], 1e-3);
        $this->assertGreaterThan(0.45, RelDynExclusivity::pull($this->npc(55.0, 75.0, 'platonic'), $at)['pull'],
            'high feelings hold before any title: "not official, but not entertaining anyone else"');
    }

    public function testWhoSheIsShapesThePull(): void
    {
        $at = self::T0 + 2 * self::HOUR;
        $pull = fn(array $o, float $mat = 60.0) => RelDynExclusivity::pull((function () use ($o, $mat) {
            $d = $this->npc(45.0, 60.0, 'platonic', $o);
            $d['dimensions']['maturity']['x'] = $mat;
            return $d;
        })(), $at)['pull'];
        $base = $pull([]);
        $this->assertGreaterThan($base, $pull(['relationship_preference' => 'monogamous']));
        $this->assertLessThan($base, $pull(['relationship_preference' => 'polyamorous']));
        $this->assertSame(0.0, $pull(['relationship_preference' => 'aromantic']));
        $this->assertLessThan($base, $pull([], 20.0), 'an immature NPC holds less');
        $this->assertGreaterThan($base, $pull([], 90.0), 'a mature one knows what she wants');
        $this->assertLessThan($base, $pull(['profile_overrides' => ['attachment_style' => 'avoidant']]), 'avoidant keeps a door open');
        $this->assertGreaterThan($pull(['inferred_temperament' => 'Playful']), $pull(['inferred_temperament' => 'Stoic']),
            'restraint / duty (Stoic D 0.9 vs Playful D 0.15) is loyalty');
        $this->assertGreaterThan($pull(['inferred_temperament' => 'Independent']), $pull(['inferred_temperament' => 'Jealous']),
            'possessiveness (Jealous Po 0.9 vs Independent Po 0.1)');
    }

    public function testNeglectPastTheGraceLoosensItFromTheLastRealInteraction(): void
    {
        $d = $this->npc(45.0, 60.0, 'romantic');   // bonded: 3 game days of grace x her neglect profile
        $grace = (RelationshipDynamics::neglectGraceEndGamets($d) - $d['_last_contact_gamets']) / self::DAY;
        $inGrace = RelDynExclusivity::pull($d, self::T0 + self::HOUR + 0.5 * $grace * self::DAY);
        $this->assertSame(1.0, $inGrace['neglect']);
        $late = RelDynExclusivity::pull($d, self::T0 + self::HOUR + ($grace + 7.0) * self::DAY);
        $this->assertEqualsWithDelta(0.5, $late['neglect'], 1e-3, 'halves every neglect_half_life_game_days past the grace');
        $this->assertLessThan($inGrace['pull'], $late['pull']);

        // A later contact that was no interaction (an NPC's own remark, a rechat) does not reset it
        $remark = $d;
        $remark['_last_contact_gamets'] = self::T0 + 8 * self::DAY;
        $this->assertLessThan(1.0, RelDynExclusivity::pull($remark, self::T0 + 10 * self::DAY)['neglect'],
            'the absence runs from the last day of real interaction');
        RelDynFulfillment::recordContactDay($remark, self::T0 + 8 * self::DAY);
        $this->assertSame(1.0, RelDynExclusivity::pull($remark, self::T0 + 10 * self::DAY)['neglect'], 'a real one does');
    }

    public function testStyleRulesPickHerWay(): void
    {
        $style = function (array $o, float $mat = 60.0): string {
            $d = $this->npc(45.0, 60.0, 'platonic', $o);
            $d['dimensions']['maturity']['x'] = $mat;
            return RelDynExclusivity::style($d);
        };
        $this->assertSame('sharp', $style([], 30.0), 'immature: an edge');
        $this->assertSame('flustered', $style(['profile_overrides' => ['attachment_style' => 'anxious']]));
        $this->assertSame('cool', $style(['profile_overrides' => ['attachment_style' => 'avoidant']]));
        $this->assertSame('cool', $style(['inferred_temperament' => 'Guarded']), 'guard 0.85');
        $this->assertSame('playful', $style(['inferred_temperament' => 'Playful']), 'expressive, unrestrained');
        $this->assertSame('plain', $style([]));
        $this->config(['style_rules' => [['style' => 'cool', 'maturity_at_least' => 50.0]], 'default_style' => 'plain']);
        $this->assertSame('cool', $style([]), 'rules from config');
    }

    public function testRomanticMovesAreMarkedWholeWordsAndConfigurable(): void
    {
        $this->assertTrue(RelDynExclusivity::isRomanticLine('You look lovely today. Share a drink with me?'));
        $this->assertTrue(RelDynExclusivity::isRomanticLine('Such BEAUTIFUL eyes.'));
        $this->assertFalse(RelDynExclusivity::isRomanticLine('Lovely weather for a hunt.'));
        $this->assertFalse(RelDynExclusivity::isRomanticLine('The kissing bandit? Never heard of him.'), 'whole words: kissing is not kiss');
        $this->assertFalse(RelDynExclusivity::isRomanticLine('Good hunting today?'));
        $this->config(['move_markers' => ['good hunting']]);
        $this->assertTrue(RelDynExclusivity::isRomanticLine('Good hunting today?'));
    }

    public function testTheSuitorLedgerIsDampedByThePullAndCountsEachLineOnce(): void
    {
        $d = [];
        $lines = [['rowid' => 10, 'text' => 'You look lovely.'], ['rowid' => 11, 'text' => 'Dance with me.']];
        $held = RelDynExclusivity::recordExchange($d, 'Mikael', $lines, 20.0, true, 1.0, self::T0);
        $this->assertEqualsWithDelta(2 * 6.0 * 0.1, $held, 1e-9, 'two moves at pull 1: x (1 - 0.9)');
        $this->assertSame(0.0, RelDynExclusivity::recordExchange($d, 'mikael', $lines, 20.0, true, 1.0, self::T0 + self::HOUR),
            'the same lines again (any case of his name) count once');
        // Her core affinity toward him rose 10 while it was romantic: 10 x core_gain_factor, damped
        $gain = RelDynExclusivity::recordExchange($d, 'Mikael', [], 30.0, true, 0.5, self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta(10.0 * (1 - 0.9 * 0.5), $gain, 1e-9);
        $e = RelDynExclusivity::suitor($d, 'MIKAEL');
        $this->assertSame(2, $e['moves']);
        $this->assertSame(11, $e['seen_rowid']);
        $this->assertSame(0.5, $e['pull_seen']);
        $open = [];
        $this->assertEqualsWithDelta(6.0, RelDynExclusivity::recordExchange($open, 'Mikael', [$lines[0]], null, true, 0.0, self::T0), 1e-9,
            'no pull: nothing damped');
        // Interest fades on the game calendar
        $this->assertEqualsWithDelta(3.0, RelDynExclusivity::interestAt(RelDynExclusivity::suitor($open, 'Mikael'), self::T0 + 10 * self::DAY), 1e-6);
    }

    public function testTheFeltLineIsHerBandHerWayAndNeverANumber(): void
    {
        $at = self::T0 + 2 * self::HOUR;
        $d = $this->npc(55.0, 75.0, 'romantic');
        $p = RelDynExclusivity::pull($d, $at);
        $this->assertSame(RelDynExclusivity::BAND_DEVOTED, $p['band']);
        $l = RelDynExclusivity::feltLine($d, $p, 'Ysolda', 'Mikael', 'Kaida', 0.0);
        $this->assertSame(['devoted', 'plain'], [$l['kind'], $l['style']]);
        $this->assertStringContainsString('with Kaida', $l['text'], 'titled: she names the player');

        $u = $this->npc(55.0, 75.0, 'platonic');
        $l = RelDynExclusivity::feltLine($u, RelDynExclusivity::pull($u, $at), 'Ysolda', 'Mikael', 'Kaida', 0.0);
        $this->assertStringNotContainsString('Kaida', $l['text'], 'not official: someone unnamed');
        $this->assertStringContainsString('someone they have not named out loud', $l['text']);

        // A weakened pull with a romance behind it: drifting; open without one: nothing
        $late = RelDynExclusivity::pull($d, self::T0 + 20 * self::DAY);
        $this->assertLessThan(0.45, $late['pull']);
        $this->assertSame('drifting', RelDynExclusivity::feltLine($d, $late, 'Ysolda', 'Mikael', 'Kaida', 0.0)['kind']);
        $friend = $this->npc(5.0, 60.0, 'platonic');
        $this->assertNull(RelDynExclusivity::feltLine($friend, RelDynExclusivity::pull($friend, $at), 'Ysolda', 'Mikael', 'Kaida', 0.0));
        $html = RelDynExclusivity::render('Ysolda', 'Mikael', 'x');
        $this->assertStringStartsWith("<subtext>\nYsolda right now, with Mikael.", $html);
        foreach (RelDynExclusivity::configDefaults()['felt_text'] as $band => $styles) {
            foreach ($styles as $style => $text) $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$band}/{$style}");
        }
    }

    public function testTheCounterpartIsTheOtherNpcNeverThePlayer(): void
    {
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'Mikael';
        $this->assertSame('Mikael', RelDynExclusivity::counterpart(['rechat', '1', '2', '{}'], 'Ysolda', 'Kaida'));
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'Kaida';
        $this->assertNull(RelDynExclusivity::counterpart(['rechat', '1', '2', '{}'], 'Ysolda', 'Kaida'), 'the player is no suitor here');
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'Ysolda';
        $this->assertNull(RelDynExclusivity::counterpart(['rechat', '1', '2', '{}'], 'Ysolda', 'Kaida'));
        // core's continue / continue_group: the previous speaker is the last speech row's (main.php)
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'Mikael';
        $this->assertSame('Mikael', RelDynExclusivity::counterpart(['continue_group', '1', '2', ''], 'Ysolda', 'Kaida'));
        $this->assertSame('Mikael', RelDynExclusivity::counterpart(['continue', '1', '2', ''], 'Ysolda', 'Kaida'));
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'Kaida';
        $this->assertNull(RelDynExclusivity::counterpart(['continue_group', '1', '2', ''], 'Ysolda', 'Kaida'), 'after the player');
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->assertSame('Mikael', RelDynExclusivity::counterpart(['radiant', '1', '2', 'Ysolda: Hello. (talking to Mikael)'], 'Ysolda', 'Kaida'));
        $this->assertSame('Mikael', RelDynExclusivity::counterpart(['radiant', '1', '2', 'Mikael: Hello there. (talking to Ysolda)'], 'Ysolda', 'Kaida'));
        $this->assertNull(RelDynExclusivity::counterpart(['radiant', '1', '2', 'Ysolda: Hello. (talking to Kaida)'], 'Ysolda', 'Kaida'));
        $this->assertNull(RelDynExclusivity::counterpart(['inputtext', '1', '2', 'Kaida: Hi (Talking to Ysolda)'], 'Ysolda', 'Kaida'),
            'the player speaking to her is not an NPC-to-NPC exchange');
    }

    public function testASaveLoadForgetsTheMovesItDiscarded(): void
    {
        $d = $this->npc(45.0, 60.0);
        RelDynExclusivity::recordExchange($d, 'Mikael', [['rowid' => 5, 'text' => 'Kiss me.']], null, true, 0.3, self::T0 + 2 * self::DAY);
        RelDynExclusivity::recordExchange($d, 'Farkas', [['rowid' => 6, 'text' => 'Kiss me.']], null, true, 0.3, self::T0 - self::DAY);
        $r = RelDynTimeline::rebaselineDynamics($d, (float) (self::T0 + self::DAY), null);
        $m = RelDynExclusivity::suitor($r, 'Mikael');
        $this->assertArrayNotHasKey('last_move_gamets', $m, 'his move came after the loaded time');
        $this->assertLessThanOrEqual(self::T0 + self::DAY, $m['gamets']);
        $this->assertSame(self::T0 - self::DAY, (int) RelDynExclusivity::suitor($r, 'Farkas')['last_move_gamets'], 'a move before it stands');
    }

    public function testJevGetsTheNumbersAndTheOffSwitchSilencesIt(): void
    {
        $d = $this->npc(55.0, 75.0, 'romantic');
        RelDynExclusivity::recordExchange($d, 'Mikael', [['rowid' => 3, 'text' => 'Kiss me.']], null, true, 0.2, self::T0);
        $j = RelDynExclusivity::jev($d, self::T0 + self::HOUR);
        $this->assertSame(RelDynExclusivity::BAND_DEVOTED, $j['band']);
        $this->assertTrue($j['titled']);
        $this->assertArrayHasKey('Mikael', $j['suitor_interest']);
        $this->config(['enabled' => false]);
        $this->assertNull(RelDynExclusivity::jev($d, self::T0 + self::HOUR));
        $this->assertNull(RelDynExclusivity::onNpcExchange('Ysolda', 'Mikael', 'Kaida', self::T0), 'off: no steering');
    }
}
