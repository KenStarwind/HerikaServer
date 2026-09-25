<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * The resentment threshold events (reldyn_resentment.php), no database ($GLOBALS['db'] unset:
 * config is the defaults, nothing is stored, core writes are refused):
 *   - MDD 15.5 confrontation at the NPC's threshold (attachment row: secure 50, anxious 30,
 *     avoidant 70), said to the player's face with its grievances as felt fuel, the addressed
 *     decay (-10 resentment points) when it is said; maturity shapes it (mature: calm, direct,
 *     one conversation; in between: means to, comes out in its style; immature: a blow-up);
 *     people-pleasers internalize; one boundary at a time: a mature NPC wronged again opens the
 *     values boundary (RelDynConcern channel 'grievance'), never a second one;
 *   - resentment_self: the dimension design's thresholds (baselines, reflection, crisis), the
 *     roadmap's recovery (processResentmentSelfDecay, a confession) and the people-pleaser
 *     buildup only while uncomfortable, on the play clock;
 *   - guilt bleed: min(15, resentment_self x bond / 100) on comfort toward the player, standing
 *     and lifted exactly;
 *   - walkaway: MDD 6.5 affinity at -20 in a bond that existed, the shame walkaway, a permanent
 *     walkaway's reject_recruitment.
 */
final class RelDynResentmentArcTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 300 * RelationshipDynamics::GAMETS_PER_DAY;       // raw gamets, day 300
    private const PLAY = 100 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
    private const PLAY_MIN = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;   // play gamets per play minute

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['gameRequest'] = ['inputtext', time(), self::T0, 'Kaida: hello'];
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** A bonded NPC (core affinity $aff, romantic) with dimension x values from $dims. */
    private function npc(array $dims = [], array $extra = [], float $aff = 60.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Stoic',
            'profile_overrides' => ['attachment_style' => 'secure'],
            '_accumulated_play_gamets' => self::PLAY,
            '_core_rel_type' => 'romantic',
            '_npc_name' => 'Lydia',
        ], $extra));
        $d['_aff_mirror_x'] = ($aff + 100.0) / 2.0;
        $d['dimensions']['affinity']['x'] = ($aff + 100.0) / 2.0;
        $dims += ['maturity' => 75.0, 'self_confidence' => 50.0, 'comfort' => 50.0, 'resentment' => 0.0, 'resentment_self' => 0.0];
        foreach ($dims as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
            if ($dim === 'maturity') $d['dimensions'][$dim]['baseline'] = $x;
        }
        return $d;
    }

    private static function x(array $d, string $dim): float
    {
        return (float) ($d['dimensions'][$dim]['x'] ?? 0);
    }

    private function grievance(array &$d, string $text, float $at, array $extra = []): void
    {
        $d['dimensions']['resentment']['grievance_log'][] = $extra + ['text' => $text, 'kind' => 'insult', 'severity' => 1,
            'raw' => 5.0, 'amount' => 5.0, 'target' => 'resentment', 'gamets' => $at];
    }

    /** A contract v1 eval item. */
    private function item(array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => 'Lydia', 'npc_id' => 7, 'gamets' => self::T0, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5, 'positive_interaction' => false, 'summary' => 'fixture',
        ], $over);
    }

    private function felt(array &$d, bool $addressed = true, float $now = self::T0): array
    {
        $out = RelDynResentment::takeFeltLines($d, 'Lydia', 'Kaida', $now, $addressed);
        $by = [];
        foreach ($out['lines'] as $l) $by[$l['key']] = $l;
        return $by;
    }

    // ------------------------------------------------------------ confrontation

    /** MDD 15.5 at 50, per attachment row (region key): anxious confront early, avoidant late. */
    public function testConfrontationThresholdFollowsTheAttachmentRow(): void
    {
        $at = fn(string $style) => RelDynResentment::confrontationThreshold(
            $this->npc([], ['profile_overrides' => ['attachment_style' => $style]]));
        $this->assertSame([50.0, 30.0, 70.0, 50.0], [$at('secure'), $at('anxious'), $at('avoidant'), $at('toxic')]);

        $d = $this->npc(['resentment' => 49.0]);
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, self::T0), 'below 50: nothing');
        $d = $this->npc(['resentment' => 31.0], ['profile_overrides' => ['attachment_style' => 'anxious']]);
        $this->assertSame(['confrontation_due'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0), 'anxious: at 30');
    }

    /** Mature (maturity 75): said once, calmly and directly, to the player's face; -10 points, resolved. */
    public function testAMatureNpcSaysItOnceCalmlyToThePlayersFace(): void
    {
        $d = $this->npc(['resentment' => 52.0]);
        $this->grievance($d, 'The player mocked her in front of the guards.', self::T0 - 3 * self::DAY);
        $d['dimensions']['resentment']['grievance_log'][] = ['text' => 'neglect: no contact for 12.3 game days', 'tag' => 'neglect',
            'since_gamets' => 1.0, 'game_days' => 12.3, 'raw' => 3.0, 'gamets' => self::T0 - self::DAY];

        $this->assertSame(['confrontation_due'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0));
        $this->assertSame('mature', $d['_resentment_arc']['confront']['pending']['mode']);

        $this->assertSame([], $this->felt($d, false), 'not said over the player\'s head (an NPC-to-NPC round)');
        $this->assertSame(52.0, self::x($d, 'resentment'));

        $lines = $this->felt($d);
        $text = $lines['confront']['text'];
        $this->assertTrue($lines['confront']['must']);
        $this->assertStringContainsString('calmly and directly, in one conversation', $text);
        $this->assertStringContainsString('being left alone for days on end', $text, 'the neglect, felt');
        $this->assertStringContainsString('mocked her in front of the guards', $text, 'the eval grievance, as it happened');
        $this->assertDoesNotMatchRegularExpression('/\d/', $text, 'feelings, never numbers');
        $this->assertSame(42.0, self::x($d, 'resentment'), 'MDD 15.5 addressed decay: -10 points');
        $this->assertTrue($d['_ick_confrontation_resolved']);
        $this->assertSame(1, $d['_resentment_arc']['confront']['count']);
        foreach ($d['dimensions']['resentment']['grievance_log'] as $g) $this->assertEquals(self::T0, $g['addressed'], 'the backlog was said: not raised again');
        $this->assertSame([], $this->felt($d), 'one conversation');

        // Still at or above 50 later, nothing new since: nothing to say again
        $d['dimensions']['resentment']['x'] = 55.0;
        $d['_accumulated_play_gamets'] += 120 * self::PLAY_MIN;
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, self::T0 + self::DAY));
    }

    /**
     * Decisions §14: a mature NPC treats repetition as a values mismatch that feeds the §9
     * boundary flow. Wronged again after the calm confrontation: the values boundary opens
     * (one boundary, the concern lane's), its statement said, and another grievance inside the
     * probation fails it. No confrontation while it runs.
     */
    public function testAMatureNpcWrongedAgainDrawsTheOneBoundary(): void
    {
        $d = $this->npc(['resentment' => 52.0]);
        $this->grievance($d, 'The player mocked her in front of the guards.', self::T0 - self::DAY);
        RelDynResentment::tickConfrontation('Lydia', $d, self::T0);
        $this->felt($d);
        $this->assertSame(42.0, self::x($d, 'resentment'));

        // Wronged again (the eval's grievance), over the threshold, after the cooldown
        $t1 = self::T0 + self::DAY;
        $GLOBALS['gameRequest'][2] = $t1;
        $f = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['gamets' => $t1,
            'grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 2], 'summary' => 'The player called her useless.']), $d);
        $this->assertGreaterThanOrEqual(50.0, self::x($d, 'resentment'), json_encode($f));
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, $t1), 'the cooldown (play clock)');
        $d['_accumulated_play_gamets'] += 61 * self::PLAY_MIN;
        $this->assertSame(['boundary_due'], RelDynResentment::tickConfrontation('Lydia', $d, $t1));
        $this->assertSame(['state' => 'pending', 'channel' => RelDynConcern::GRIEVANCE, 'kind' => RelDynConcern::GRIEVANCE_KIND],
            array_intersect_key($d['_concern']['boundary'], array_flip(['state', 'channel', 'kind'])));
        $this->assertNull($d['_resentment_arc']['confront']['pending'], 'no confrontation beside the boundary');

        $concern = RelDynConcern::takeFeltLines($d, 'Lydia', 'Kaida', $t1, true);
        $said = array_column($concern['lines'], 'text', 'key');
        $this->assertStringContainsString('being hurt the same way after already speaking up keeps happening', $said['boundary']);
        $this->assertSame('probation', $d['_concern']['boundary']['state']);
        $this->assertSame([], $this->felt($d, true, $t1));

        // While it watches: no confrontation, whatever the resentment
        $d['_accumulated_play_gamets'] += 120 * self::PLAY_MIN;
        $this->grievance($d, 'Another slight.', $t1 + 1000);
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, $t1 + 2000));

        // A grievance from before the statement (a late eval) is not the pattern going on ...
        $f = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['gamets' => $t1 - 5000,
            'grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 1]]), $d);
        $this->assertArrayNotHasKey('grievance_boundary_failed', $f);
        // ... one after it is
        $f = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['gamets' => $t1 + self::DAY,
            'grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 1]]), $d);
        $this->assertTrue($f['grievance_boundary_failed']);
        $this->assertSame('failed', $d['_concern']['boundary']['state']);
    }

    /** Nothing to step back from (no step-back target): the mature NPC says it calmly again. */
    public function testWithoutAStepBackTheMatureNpcSaysItCalmlyAgain(): void
    {
        $d = $this->npc(['resentment' => 52.0], ['_core_rel_type' => 'professional']);
        $this->grievance($d, 'The player ignored her warning.', self::T0 - self::DAY);
        RelDynResentment::tickConfrontation('Lydia', $d, self::T0);
        $this->felt($d);
        $d['dimensions']['resentment']['x'] = 58.0;
        $this->grievance($d, 'The player ignored her again.', self::T0 + 100);
        $d['_accumulated_play_gamets'] += 61 * self::PLAY_MIN;
        $this->assertSame(['confrontation_due'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0 + 200));
        $this->assertSame('none', $d['_concern']['boundary']['state'] ?? 'none');
        $this->assertStringContainsString('The player ignored her again', $this->felt($d)['confront']['text'], 'the new one first');
    }

    /** Immature (maturity 30): a blow-up, again each time something new is added, never a boundary, never "resolved". */
    public function testAnImmatureNpcBlowsUpAgainAndNeverDrawsABoundary(): void
    {
        $d = $this->npc(['resentment' => 60.0, 'maturity' => 30.0]);
        $expr = RelDynConcern::expression($d, RelDynConcern::traitsOf($d));
        $how = RelDynConcern::config()['style_phrases'][$expr['style']]['how'];
        $this->assertSame('immature', $expr['path']);
        $this->grievance($d, 'The player laughed at her.', self::T0 - self::DAY);

        $this->assertSame(['confrontation_due'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0));
        $line = $this->felt($d)['confront'];
        $this->assertStringContainsString('It all comes out at once, ' . $how, $line['text']);
        $this->assertStringContainsString('older hurts dragged in', $line['text']);
        $this->assertTrue($line['intense']);
        $this->assertSame(50.0, self::x($d, 'resentment'), 'venting is still saying it: -10');
        $this->assertArrayNotHasKey('_ick_confrontation_resolved', $d, 'a blow-up resolves nothing');

        // Something new, the cooldown not yet over: nothing; after it: a blow-up again
        $this->grievance($d, 'The player shrugged her off.', self::T0 + 100);
        $d['_accumulated_play_gamets'] += 30 * self::PLAY_MIN;
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, self::T0 + 200));
        $d['_accumulated_play_gamets'] += 31 * self::PLAY_MIN;
        $this->assertSame(['confrontation_due'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0 + 300));
        $this->assertSame('immature', $d['_resentment_arc']['confront']['pending']['mode']);
        $this->assertSame('none', $d['_concern']['boundary']['state'] ?? 'none', 'festers and blows up: no boundary');
    }

    /** In between (maturity 52): means to say it evenly; it comes out in its style. */
    public function testAnInBetweenNpcMeansToSayItEvenly(): void
    {
        $d = $this->npc(['resentment' => 51.0, 'maturity' => 52.0]);
        $expr = RelDynConcern::expression($d, RelDynConcern::traitsOf($d));
        $this->assertSame(['mixed', 'mature'], [$expr['band'], $expr['path']]);
        RelDynResentment::tickConfrontation('Lydia', $d, self::T0);
        $text = $this->felt($d)['confront']['text'];
        $this->assertStringContainsString('means to say it evenly, but it comes out ' . RelDynConcern::config()['style_phrases'][$expr['style']]['how'], $text);
        $this->assertStringContainsString('all the small things that kept piling up', $text, 'no grievance on record: the fallback');
    }

    /** Autonomy design: the people-pleaser suffers inward and never confronts. */
    public function testAPeoplePleaserNeverConfronts(): void
    {
        $d = $this->npc(['resentment' => 60.0, 'self_confidence' => 20.0, 'maturity' => 30.0]);
        $this->assertTrue(RelationshipDynamics::isPeoplePleaser($d));
        $this->assertSame(['internalized'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0));
        $this->assertNull($d['_resentment_arc']['confront']['pending']);
    }

    /** One conversation at a time: not while walking away, not while either boundary runs. */
    public function testNoConfrontationWhileWalkingAwayOrWhileABoundaryRuns(): void
    {
        $d = $this->npc(['resentment' => 60.0], ['_walkaway_state' => 'active']);
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, self::T0));
        $d = $this->npc(['resentment' => 60.0]);
        $d[RelDynFulfillment::STATE_KEY] = ['boundary' => ['state' => 'probation']];
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, self::T0));
        $d = $this->npc(['resentment' => 60.0]);
        $d[RelDynConcern::STATE_KEY] = ['v' => RelDynConcern::VERSION, 'level' => 0.0, 'incidents' => [], 'say' => [],
            'boundary' => ['state' => 'pending', 'channel' => RelDynConcern::PROTECTIVE, 'kind' => 'place']];
        $this->assertSame([], RelDynResentment::tickConfrontation('Lydia', $d, self::T0));
    }

    /** Worked through (below 30): the episode is over; the next one is a first confrontation again. */
    public function testTheEpisodeEndsBelowThirty(): void
    {
        $d = $this->npc(['resentment' => 52.0]);
        RelDynResentment::tickConfrontation('Lydia', $d, self::T0);
        $this->felt($d);
        $d['dimensions']['resentment']['x'] = 25.0;
        $this->assertSame(['episode_over'], RelDynResentment::tickConfrontation('Lydia', $d, self::T0 + 100));
        $this->assertSame(0, $d['_resentment_arc']['confront']['count']);
    }

    /** The fuel: engine grievances by their felt phrase, eval ones as they happened, never a number. */
    public function testTheFuelIsFeltNeverNumbers(): void
    {
        $d = $this->npc(['resentment' => 52.0]);
        $this->grievance($d, 'The player lied about the gold (score -5).', self::T0 - 4 * self::DAY);
        $this->grievance($d, 'values conflict: late nights', self::T0 - 3 * self::DAY, ['kind' => 'values_conflict:place']);
        $d['dimensions']['resentment']['grievance_log'][] = ['text' => 'neglect: needs unmet (real time together)', 'tag' => 'neglect',
            'kind' => 'unfulfilled', 'since_gamets' => 1.0, 'raw' => 1.0, 'gamets' => self::T0 - self::DAY];
        $this->grievance($d, 'held inward', self::T0, ['target' => 'resentment_self']);
        $fuel = RelDynResentment::fuel($d, 'Lydia', 'Kaida');
        $this->assertSame(['waiting on things from Kaida that never came', 'late nights at the tavern among strangers', 'The player lied about the gold'],
            $fuel['phrases'], 'newest first; the inward grievance (resentment_self) is not raised at the player');
        $this->assertSame([2, 1, 0], $fuel['entries']);

        // A summary that is all numbers is dropped: the NPC names the kind
        $d = $this->npc(['resentment' => 52.0]);
        $this->grievance($d, 'Owed 300 gold since day 212.', self::T0, ['kind' => 'broken_promise']);
        $this->assertSame(['broken promise'], RelDynResentment::fuel($d, 'Lydia', 'Kaida')['phrases']);
    }

    // ------------------------------------------------------------ resentment_self

    /** > 30 comfort baseline -5, > 70 warmth baseline -10; lifted exactly (dimension design). */
    public function testSelfThresholdsShiftTheBaselinesAndLiftThemExactly(): void
    {
        $d = $this->npc(['resentment_self' => 35.0]);
        $d['dimensions']['comfort']['baseline'] = 55.0;
        unset($d['dimensions']['warmth']['baseline']);
        $warmthOwn = RelationshipDynamics::getTemperamentBaseline('Stoic', 'warmth', $d);

        $this->assertSame(['baseline:comfort-'], RelDynResentment::tickSelf('Lydia', $d));
        $this->assertSame(50.0, $d['dimensions']['comfort']['baseline']);
        $d['dimensions']['resentment_self']['x'] = 75.0;
        $this->assertContains('baseline:warmth-', RelDynResentment::tickSelf('Lydia', $d));
        $this->assertEqualsWithDelta($warmthOwn - 10.0, $d['dimensions']['warmth']['baseline'], 1e-9);
        $this->assertSame([], array_values(array_filter(RelDynResentment::tickSelf('Lydia', $d), fn($e) => str_starts_with($e, 'baseline'))), 'applied once');

        $d['dimensions']['resentment_self']['x'] = 20.0;
        RelDynResentment::tickSelf('Lydia', $d);
        $this->assertSame(55.0, $d['dimensions']['comfort']['baseline'], 'lifted exactly');
        $this->assertArrayNotHasKey('baseline', $d['dimensions']['warmth'], "back to the temperament's own");
    }

    /** > 50: the self-reflection moment, once; re-armed at 30 or below. */
    public function testTheSelfReflectionIsAOneShotAboveFifty(): void
    {
        $d = $this->npc(['resentment_self' => 55.0]);
        $this->assertContains('reflection', RelDynResentment::tickSelf('Lydia', $d));
        $this->assertSame([], $this->felt($d, false), 'waits for the player');
        $line = $this->felt($d)['reflection'];
        $this->assertStringContainsString('ashamed', $line['text']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line['text']);
        $this->assertNotContains('reflection', RelDynResentment::tickSelf('Lydia', $d));
        $this->assertSame([], $this->felt($d));
        $d['dimensions']['resentment_self']['x'] = 25.0;
        RelDynResentment::tickSelf('Lydia', $d);
        $d['dimensions']['resentment_self']['x'] = 60.0;
        $this->assertContains('reflection', RelDynResentment::tickSelf('Lydia', $d), 're-armed');
    }

    /** min(15, resentment_self x bond / 100) on comfort toward the player; a stranger barely registers; lifted exactly. */
    public function testGuiltBleedIsBoundedScaledByTheBondAndLiftedExactly(): void
    {
        $d = $this->npc(['resentment_self' => 40.0, 'comfort' => 50.0], [], 30.0);
        $this->assertEqualsWithDelta(-12.0, RelDynResentment::guiltBleedTarget($d), 1e-9, '40 x 30/100');
        $this->assertEqualsWithDelta(-15.0, RelDynResentment::guiltBleedTarget($this->npc(['resentment_self' => 40.0], [], 60.0)), 1e-9, 'capped');
        $this->assertSame(0.0, RelDynResentment::guiltBleedTarget($this->npc(['resentment_self' => 40.0], [], 0.0)), 'a stranger');
        $this->assertSame(0.0, RelDynResentment::guiltBleedTarget($this->npc(['resentment_self' => 30.0], [], 60.0)), 'at 30: not above');

        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(38.0, self::x($d, 'comfort'), 1e-9);
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(38.0, self::x($d, 'comfort'), 1e-9, 'standing, not cumulative');
        $d['dimensions']['resentment_self']['x'] = 60.0;   // 60 x 0.3 = 18 -> 15
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(35.0, self::x($d, 'comfort'), 1e-9);
        $d['dimensions']['resentment_self']['x'] = 10.0;
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(50.0, self::x($d, 'comfort'), 1e-9, 'the guilt faded: lifted exactly');

        // At the floor only what moved is given back
        $d = $this->npc(['resentment_self' => 60.0, 'comfort' => 5.0], [], 60.0);
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertSame(0.0, self::x($d, 'comfort'));
        $d['dimensions']['resentment_self']['x'] = 0.0;
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(5.0, self::x($d, 'comfort'), 1e-9);
    }

    /** processResentmentSelfDecay: -0.5 x (1 + maturity/100) points, comfort above 20, on the play clock. */
    public function testSelfDecayIsTheRoadmapFormulaOnThePlayClock(): void
    {
        $d = $this->npc(['resentment_self' => 60.0, 'maturity' => 40.0, 'comfort' => 40.0]);
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true]), $d);
        $this->assertEqualsWithDelta(59.3, self::x($d, 'resentment_self'), 1e-9, '-0.5 x 1.4');
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true]), $d);
        $this->assertEqualsWithDelta(59.3, self::x($d, 'resentment_self'), 1e-9, 'rate-limited');
        $d['_accumulated_play_gamets'] += 15 * self::PLAY_MIN;
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true]), $d);
        $this->assertEqualsWithDelta(58.6, self::x($d, 'resentment_self'), 1e-9);

        $d = $this->npc(['resentment_self' => 60.0, 'comfort' => 20.0]);
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true]), $d);
        $this->assertSame(60.0, self::x($d, 'resentment_self'), 'comfort at 20: too uneasy to heal');
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => false]), $d);
        $this->assertSame(60.0, self::x($d, 'resentment_self'), 'not a positive exchange');
    }

    /** A confession (opened up, met with care): -10 points, beside the decay. */
    public function testAConfessionTakesTenPoints(): void
    {
        $d = $this->npc(['resentment_self' => 60.0, 'maturity' => 50.0, 'comfort' => 40.0]);
        $f = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true, 'tags' => ['confiding']]), $d);
        $this->assertEqualsWithDelta(60.0 - 0.75 - 10.0, self::x($d, 'resentment_self'), 1e-9);
        $this->assertEqualsWithDelta(10.75, $f['resentment_self_relief'], 1e-9);
        $none = $this->npc();
        $this->assertArrayNotHasKey('resentment_self_relief',
            RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true, 'tags' => ['confiding']]), $none));
    }

    /** The people-pleaser's buildup: only while uncomfortable, at most once per 15 play minutes. */
    public function testThePeoplePleaserBuildupOnlyWhileUncomfortableOnThePlayClock(): void
    {
        $d = $this->npc(['self_confidence' => 20.0, 'maturity' => 30.0]);
        $eval = ['people_pleaser' => true, 'autonomy_score' => 20.0, 'resentment_self_buildup' => 2.0];
        $this->assertSame(0.0, RelDynResentment::peoplePleaserBuildup('Lydia', $d, $eval), 'at ease: nothing');
        $eval['autonomy_score'] = 40.0;
        $expected = $d;
        RelationshipDynamics::applyDelta('resentment_self', $expected, 2.0, 'Stoic');
        $this->assertGreaterThan(0.0, RelDynResentment::peoplePleaserBuildup('Lydia', $d, $eval));
        $this->assertEqualsWithDelta(self::x($expected, 'resentment_self'), self::x($d, 'resentment_self'), 1e-9);
        $this->assertSame(0.0, RelDynResentment::peoplePleaserBuildup('Lydia', $d, $eval), 'the next line: not again');
        $d['_accumulated_play_gamets'] += 15 * self::PLAY_MIN;
        $this->assertGreaterThan(0.0, RelDynResentment::peoplePleaserBuildup('Lydia', $d, $eval));
    }

    /** > 90: the crisis is a self-triggered isolation walkaway, people-pleaser or not; it ends on resentment_self. */
    public function testTheShameCrisisIsASelfTriggeredWalkaway(): void
    {
        $d = $this->npc(['resentment_self' => 95.0, 'self_confidence' => 20.0, 'maturity' => 30.0, 'comfort' => 40.0]);
        $this->assertTrue(RelationshipDynamics::isPeoplePleaser($d));
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state']);
        $this->assertSame('shame', RelationshipDynamics::walkawayReason($d));
        $this->assertStringContainsString('cannot bear to be seen', (string) RelationshipDynamics::getAutonomyContext($d, 'Lydia', 'Stoic'));

        RelationshipDynamics::initiateWalkaway($d, 'Lydia', RelationshipDynamics::walkawayReason($d));
        $d['_walkaway_boundary_test_hours'] = 48.0;
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic');
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic');
        $this->assertSame('boundary_test', $d['_walkaway_state']);
        $this->assertStringContainsString('too ashamed to face anyone', (string) RelationshipDynamics::getAutonomyContext($d, 'Lydia', 'Stoic'));
        $this->assertNull(RelationshipDynamics::checkBoundaryTest($d), 'resentment toward the player is 0, but the shame is not over');
        $d['dimensions']['resentment_self']['x'] = 45.0;
        $this->assertSame('recovery', RelationshipDynamics::checkBoundaryTest($d));
    }

    // ------------------------------------------------------------ walkaway (MDD 6.5)

    /** Affinity at -20 in a bond that existed (a friend once): a walkaway; not a stranger. */
    public function testAffinityAtMinusTwentyInABondThatExistedIsAWalkaway(): void
    {
        $d = $this->npc([], ['context_tier_hwm' => 2], -20.0);
        $this->assertTrue(RelationshipDynamics::affinityWalkawayDue($d));
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state']);
        $this->assertSame('affinity', RelationshipDynamics::walkawayReason($d));
        $this->assertFalse(RelationshipDynamics::affinityWalkawayDue($this->npc([], ['context_tier_hwm' => 2], -19.0)));
        $this->assertFalse(RelationshipDynamics::affinityWalkawayDue($this->npc([], ['context_tier_hwm' => 1], -40.0)), 'never more than an acquaintance');
        $unread = $this->npc([], ['context_tier_hwm' => 3], -40.0);
        unset($unread['_aff_mirror_x']);
        $this->assertFalse(RelationshipDynamics::affinityWalkawayDue($unread), 'core never read');
    }

    /** MDD 6.5: followed during the test -> permanent, the hard reject_recruitment flag. */
    public function testAPermanentWalkawaySetsRejectRecruitment(): void
    {
        $d = $this->npc(['resentment' => 92.0, 'comfort' => 10.0]);
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'resentment');
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic');
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic');
        $GLOBALS['gameRequest'][2] += 2 * self::DAY / 24;
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic', true);   // sought out
        $r = RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic');
        $this->assertSame('permanent', $d['_walkaway_state']);
        $this->assertTrue($d['_reject_recruitment']);
        $this->assertNull($r['severed_type'], 'no database: the core write is refused, the flag stands');
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state'], 'recruiting stays denied');
        $this->assertTrue(RelDynResentment::jev($d)['reject_recruitment']);
    }

    /** processResentmentConfrontation: the MDD 15.5 addressed decay as points (not a tenth of it through the inverted band). */
    public function testTheAddressedDecayIsTenPoints(): void
    {
        $d = $this->npc(['resentment' => 50.0]);
        $viaPhysics = $d;
        RelationshipDynamics::applyDelta('resentment', $viaPhysics, -10.0, 'Stoic');
        $this->assertGreaterThan(45.0, self::x($viaPhysics, 'resentment'), 'the inverted rubber band would leave most of it');
        $this->assertSame(-10.0, RelationshipDynamics::processResentmentConfrontation($d));
        $this->assertSame(40.0, self::x($d, 'resentment'));
    }

    /** Jev gets the numbers. */
    public function testJevGetsTheNumbers(): void
    {
        $d = $this->npc(['resentment' => 52.0, 'resentment_self' => 40.0], [], 60.0);
        RelDynResentment::onPrerequest('Lydia', $d, self::T0);
        $j = RelDynResentment::jev($d);
        $this->assertSame(50.0, $j['confrontation_threshold']);
        $this->assertSame('mature', $j['confrontation_pending']);
        $this->assertSame(['comfort' => -5.0], $j['self_baseline_offsets']);
        $this->assertSame(-15.0, $j['guilt_bleed']);
        $this->assertFalse($j['self_crisis']);
        $text = RelDynJev::render(RelDynJev::state('Lydia', $d, self::T0));
        $this->assertStringContainsString('confront=0/at 50(due mature)', $text);
        $this->assertStringContainsString('guilt=-15', $text);
    }
}
