<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * intrinsic-goals (MDD 13.2 / 14.2, dimension draft "Intrinsic Goal Generation"): tier-1 goals the
 * NPC forms from her own state, their lifecycle on the game calendar, and what the LLM (a
 * feeling) and Jev (numbers) get. No database: config is the defaults, the bio template is
 * absent (backstory goals are the Postgres test beds' part).
 */
final class RelDynIntrinsicGoalsTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 400 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private static function npc(array $dims, array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => 'Stoic', 'profile_overrides' => ['attachment_axes' => ['anxiety' => 0.2, 'avoidance' => 0.2]],
            'stage' => 'early', 'dimensions' => []];
        foreach ($dims + ['maturity' => 55.0, 'self_confidence' => 55.0, 'trust' => 50.0, 'respect' => 50.0,
                          'comfort' => 50.0, 'resentment' => 0.0, 'resentment_self' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    private static function types(array $d): array
    {
        return array_map(fn($g) => $g['type'], RelDynGoals::active($d));
    }

    public function testDimensionalPainFormsINeedToChangeOnlyWithSelfAwareness(): void
    {
        // deficit = (50 - 20) + (50 - 25) + 40 / 2 = 75 > 60, maturity 25 > 20
        $d = self::npc(['respect' => 20.0, 'maturity' => 25.0, 'resentment_self' => 40.0]);
        $this->assertEqualsWithDelta(75.0, RelDynGoals::selfWorth($d), 1e-9);
        $this->assertSame(['self_worth_recovery'], RelDynGoals::onContact('Lynly Star-Sung', $d, self::T0));
        $g = RelDynGoals::active($d)[0];
        $this->assertEqualsWithDelta(0.75, $g['priority'], 1e-9, 'priority = deficit / 100');
        $this->assertSame(25.0, $g['maturity_start']);
        $this->assertStringContainsString('knows they need to change', (string) RelDynGoals::feltText('Lynly Star-Sung', 'Kaida', $d));

        // Below the awareness floor the same pain forms nothing (draft: maturity > 20)
        $numb = self::npc(['respect' => 10.0, 'maturity' => 20.0, 'resentment_self' => 60.0]);
        $this->assertNull(RelDynGoals::selfWorth($numb));
        $this->assertSame([], RelDynGoals::onContact('Lynly Star-Sung', $numb, self::T0));
        // Mild pain is not a crisis
        $this->assertNull(RelDynGoals::selfWorth(self::npc(['respect' => 40.0, 'maturity' => 45.0])));
    }

    public function testHeldChangeLiftsHerBaselineAndSlippingBackCostsShame(): void
    {
        $d = self::npc(['respect' => 20.0, 'maturity' => 25.0, 'resentment_self' => 40.0]);
        RelDynGoals::onContact('Lynly Star-Sung', $d, self::T0);
        $baseline = floatval($d['dimensions']['maturity']['baseline']);

        // Three game days held above where she stood (start + margin): the draft's success mode
        $d['dimensions']['maturity']['x'] = 28.0;
        foreach ([1, 2, 3] as $day) RelDynGoals::onContact('Lynly Star-Sung', $d, self::T0 + $day * self::DAY);
        $g = RelDynGoals::active($d)[0];
        $this->assertSame('maintain', $g['phase']);
        $this->assertEqualsWithDelta($baseline + 2.0, floatval($d['dimensions']['maturity']['baseline']), 1e-9, 'her baseline drifts up');
        $this->assertStringContainsString('firmer ground', (string) RelDynGoals::feltText('Lynly Star-Sung', 'Kaida', $d));

        // Slipping below it: the goal lapses and the shame adds up (draft failure mode)
        $shameBefore = floatval($d['dimensions']['resentment_self']['x']);
        $d['dimensions']['maturity']['x'] = 20.0;
        RelDynGoals::onContact('Lynly Star-Sung', $d, self::T0 + 4 * self::DAY);
        $this->assertSame([], self::types($d));
        $this->assertSame('lapsed', $d[RelDynGoals::HISTORY_KEY][0]['outcome']);
        $this->assertGreaterThan($shameBefore, floatval($d['dimensions']['resentment_self']['x']));
        // and it does not form again straight away, even in pain
        $d['dimensions']['maturity']['x'] = 25.0;
        $this->assertSame([], RelDynGoals::onContact('Lynly Star-Sung', $d, self::T0 + 5 * self::DAY));
    }

    public function testAffinityTrajectoryFormsBondSeekingOrIndependenceByAttachment(): void
    {
        $rising = [['v' => 10.0, 'day' => 400], ['v' => 14.0, 'day' => 401], ['v' => 20.0, 'day' => 402]];
        $falling = [['v' => 30.0, 'day' => 400], ['v' => 24.0, 'day' => 401], ['v' => 20.0, 'day' => 402]];
        $d = self::npc([], ['_baseline_drift_samples' => ['affinity' => $rising]]);
        $this->assertEqualsWithDelta(5.0, RelDynGoals::affinitySlope($d, 3), 1e-9);
        RelDynGoals::onContact('Aela the Huntress', $d, self::T0 + 2 * self::DAY);
        $this->assertSame(['bond_seeking'], self::types($d));
        $this->assertStringContainsString('wants to be closer to Kaida', (string) RelDynGoals::feltText('Aela the Huntress', 'Kaida', $d));

        // Falling closeness: an avoidant NPC wants room; a secure one forms nothing from it
        $avoidant = self::npc([], ['_baseline_drift_samples' => ['affinity' => $falling],
            'profile_overrides' => ['attachment_axes' => ['anxiety' => 0.2, 'avoidance' => 0.6]]]);
        RelDynGoals::onContact('Ashe', $avoidant, self::T0 + 2 * self::DAY);
        $this->assertSame(['independence'], self::types($avoidant));
        $secure = self::npc([], ['_baseline_drift_samples' => ['affinity' => $falling]]);
        RelDynGoals::onContact('Muiri', $secure, self::T0 + 2 * self::DAY);
        $this->assertSame([], self::types($secure));
    }

    public function testReachingEstablishedFormsDeepenTheBondAndInterestsFormMastery(): void
    {
        $d = self::npc([], ['facet_pref_overrides' => ['scholarly' => 0.9, 'enchanting' => 0.7]]);
        RelDynGoals::onContact('Ashe', $d, self::T0);
        $this->assertSame(['mastery'], self::types($d), 'her strongest interest');
        $this->assertSame(['scholarly' => 1.0], RelDynGoals::active($d)[0]['facets']);
        $this->assertStringContainsString('getting better at study and learning', (string) RelDynGoals::feltText('Ashe', 'Kaida', $d));

        $d['stage'] = RelationshipDynamics::STAGE_ESTABLISHED;
        RelDynGoals::onContact('Ashe', $d, self::T0 + 100);
        $this->assertSame(['bond_seeking', 'mastery'], self::types($d), 'MDD 14.2: reaching Established creates "deepen bond" (0.7)');
        $this->assertSame('stage:established', RelDynGoals::active($d)[0]['source']);
    }

    public function testExperiencesEvalTagsAndQuestsMoveTheGoalsTheyTouch(): void
    {
        $d = self::npc([], ['facet_pref_overrides' => ['nature' => 0.9]]);
        RelDynGoals::onContact('Aela the Huntress', $d, self::T0);
        $this->assertSame(['mastery'], self::types($d));
        // A forest (nature 1.0) moves it; the same place again that game day does not
        $this->assertSame(['mastery'], RelDynGoals::onExperience('Aela the Huntress', $d, 'place', 'Fallowstone Woods', ['nature' => 1.0, 'wild' => 0.8], self::T0 + 10));
        $this->assertSame([], RelDynGoals::onExperience('Aela the Huntress', $d, 'place', 'Fallowstone Woods', ['nature' => 1.0], self::T0 + 20));
        $this->assertSame([], RelDynGoals::onExperience('Aela the Huntress', $d, 'topic', 'Arcanaeum', ['scholarly' => 1.0], self::T0 + 30), 'not her pursuit');
        $this->assertEqualsWithDelta(0.05, RelDynGoals::active($d)[0]['progress'], 1e-9);
        $this->assertSame(['mastery'], RelDynGoals::onExperience('Aela the Huntress', $d, 'place', 'Fallowstone Woods', ['nature' => 1.0], self::T0 + self::DAY));

        // Bond-seeking moves with quality time; an insult moves nothing
        RelDynGoals::form($d, 'bond_seeking', 0.7, 'stage:established', self::T0);
        $item = ['positive_interaction' => true, 'tags' => ['quality_time'], 'significance' => 0.5];
        $this->assertSame(['bond_seeking'], RelDynGoals::onEvalItem('Aela the Huntress', $d, $item, self::T0 + 40));
        $this->assertSame([], RelDynGoals::onEvalItem('Aela the Huntress', $d, ['positive_interaction' => false, 'tags' => ['insult'], 'significance' => 0.5], self::T0 + 50));

        // A quest stage whose name carries a revenge goal's keyword moves it
        RelDynGoals::form($d, 'revenge', 0.8, 'backstory', self::T0, ['keywords' => ['Silver Hand']]);
        $this->assertSame(['revenge'], RelDynGoals::onQuestEvent('Aela the Huntress', $d, ['name' => 'The Silver Hand', 'objective' => 'Kill the leader'], self::T0 + 60));
        $this->assertSame([], RelDynGoals::onQuestEvent('Aela the Huntress', $d, ['name' => 'Unbound', 'objective' => 'Escape Helgen'], self::T0 + 70));
        $jev = RelDynGoals::jev($d);
        $this->assertSame('revenge', $jev[0]['type']);
        $this->assertEqualsWithDelta(0.25, $jev[0]['progress'], 1e-9);
    }

    public function testGoalsWithoutProgressFadeAndProgressOneIsAchieved(): void
    {
        $d = self::npc([]);
        RelDynGoals::form($d, 'mastery', 0.3, 'interest', self::T0, ['facets' => ['alchemy' => 1.0]]);
        // 0.3 - 0.02 x 11 days = 0.08 < 0.1: faded (an interest's goal; a backstory one does not fade)
        RelDynGoals::onContact('Muiri', $d, self::T0 + 11 * self::DAY);
        $this->assertSame([], self::types($d));
        $this->assertSame('faded', $d[RelDynGoals::HISTORY_KEY][0]['outcome']);

        RelDynGoals::form($d, 'purpose', 0.6, 'backstory', self::T0 + 11 * self::DAY, ['keywords' => ['Dawnstar']]);
        for ($i = 0; $i < 4; $i++) RelDynGoals::onQuestEvent('Muiri', $d, ['name' => 'Waking Nightmare', 'objective' => 'Go to Dawnstar'], self::T0 + 11 * self::DAY + $i);
        $this->assertSame([], self::types($d), 'progress 1: achieved');
        $this->assertSame('achieved', $d[RelDynGoals::HISTORY_KEY][0]['outcome']);
    }

    public function testTheFeltLineIsAFeelingNeverNumbersAndTheGoalReachesTheContext(): void
    {
        $d = self::npc(['respect' => 20.0, 'maturity' => 25.0, 'resentment_self' => 40.0], ['love_language_primary' => 'quality_time']);
        RelDynGoals::onContact('Lynly Star-Sung', $d, self::T0);
        $composed = RelDynFelt::compose('Lynly Star-Sung', 'Kaida', $d, self::T0);
        $lines = array_column($composed['lines'], 'text', 'key');
        $this->assertArrayHasKey('intrinsic_goal', $lines);
        $this->assertDoesNotMatchRegularExpression('/\d/', $lines['intrinsic_goal']);
        $this->assertTrue(RelationshipDynamics::defaultConfig()['intrinsic_goals']['enabled']);
    }

    /**
     * batch O review: a backstory goal is character-defining (MDD 14.2 "persistent across
     * sessions"). Sixty idle game days lower Aela's Silver Hand revenge to the floor, never away;
     * four newer, stronger goals crowd out one of themselves, not her revenge; achieved, it is done.
     */
    public function testABackstoryGoalIsNotWornAwayByTimeNorCrowdedOut(): void
    {
        $d = self::npc([]);
        RelDynGoals::form($d, 'revenge', 0.8, 'backstory', self::T0, ['keywords' => ['Silver Hand']]);
        RelDynGoals::onContact('Aela the Huntress', $d, self::T0 + 60 * self::DAY);
        $g = array_column(RelDynGoals::active($d), null, 'type')['revenge'] ?? null;
        $this->assertNotNull($g, 'sixty idle game days: still hers');
        $this->assertEqualsWithDelta(RelDynGoals::configDefaults()['backstory_priority_floor'], $g['priority'], 1e-9, 'quieter, at the floor');
        $this->assertSame([], (array) ($d[RelDynGoals::HISTORY_KEY] ?? []));
        $this->assertGreaterThanOrEqual(RelDynGoals::configDefaults()['felt_min_priority'], $g['priority'], 'still felt at its quietest');

        $t = self::T0 + 61 * self::DAY;
        foreach (['bond_seeking' => 'trajectory', 'independence' => 'trajectory', 'mastery' => 'interest', 'self_worth_recovery' => 'self_worth'] as $type => $src) {
            RelDynGoals::form($d, $type, 0.9, $src, $t);
        }
        $types = self::types($d);
        $this->assertContains('revenge', $types, 'newer goals do not crowd out who she is');
        $this->assertCount(RelDynGoals::configDefaults()['max_active'], $types);
        $this->assertSame('crowded_out', $d[RelDynGoals::HISTORY_KEY][0]['outcome']);
        $this->assertNotSame('revenge', $d[RelDynGoals::HISTORY_KEY][0]['type']);

        // Achieved: done for good (the backstory does not form it again)
        for ($i = 0; $i < 4; $i++) RelDynGoals::onQuestEvent('Aela the Huntress', $d, ['name' => 'The Silver Hand', 'objective' => 'Hunt the Silver Hand'], $t + $i);
        $this->assertNotContains('revenge', self::types($d));
        $this->assertArrayHasKey('revenge', $d[RelDynGoals::META_KEY]['backstory_done'] ?? []);
    }
}
