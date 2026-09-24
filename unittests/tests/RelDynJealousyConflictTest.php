<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Jealousy without MARAS (decisions §5, MDD 1.3 / 6.5), jealousy -> resentment on the game
 * calendar, and the conflict / repair cycle, from contract v1 fixtures.
 * No database: config is the defaults and nothing is stored.
 */
final class RelDynJealousyConflictTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const HOUR = self::DAY / 24;                       // raw gamets per game hour
    private const T0 = 300 * self::DAY;                        // game day 300

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function npc(array $extra = [], array $dims = []): array
    {
        $d = $extra + [
            'inferred_temperament' => 'Stoic',
            'attachment_style' => 'secure',
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
        ];
        $dims += ['maturity' => 60.0, 'self_confidence' => 50.0, 'trust' => 50.0, 'respect' => 50.0,
                  'comfort' => 50.0, 'resentment' => 0.0];
        foreach ($dims as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $dim === 'resentment' ? 0 : $x];
        }
        return $d;
    }

    private static function res(array $d): float
    {
        return (float) ($d['dimensions']['resentment']['x'] ?? 0);
    }

    private function item(array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => 'Lydia', 'npc_id' => 7, 'gamets' => self::T0, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5,
            'positive_interaction' => false,
            'summary' => 'fixture',
        ], $over);
    }

    // ------------------------------------------------------------ jealousy sources (no MARAS)

    public function testEvalJealousyEventScalesByTemperamentAttachmentAndIntensity(): void
    {
        $base = (float) RelationshipDynamics::defaultConfig()['jealousy_eval_gain'];   // jealousy points
        $gain = function (array $extra, int $intensity): float {
            $d = $this->npc($extra);
            RelationshipDynamics::applyEvalFeelings('Lydia',
                $this->item(['jealousy' => ['flag' => true, 'rival' => 'Aela', 'intensity' => $intensity]]), $d);
            return (float) $d['jealousy_anger'];
        };

        $this->assertEqualsWithDelta($base * 2.0, $gain(['inferred_temperament' => 'Jealous'], 1), 1e-9, 'MDD 1.3 Jealous 2.0x');
        $this->assertEqualsWithDelta($base * 0.2, $gain(['inferred_temperament' => 'Stoic'], 1), 1e-9, 'MDD 1.3 Stoic 0.2x');
        $this->assertEqualsWithDelta($base * 2.0 * 2.0,
            $gain(['inferred_temperament' => 'Jealous', 'attachment_style' => 'anxious'], 1), 1e-9, 'Anxious attachment 2.0x');
        $this->assertEqualsWithDelta(2.0, $gain(['inferred_temperament' => 'Romantic'], 3) / $gain(['inferred_temperament' => 'Romantic'], 1), 1e-9);
        // Relationship preference (pipeline doc: monogamous 2x, polyamorous 0.1x), from config
        $this->assertEqualsWithDelta($base * 1.3 * 0.1,
            $gain(['inferred_temperament' => 'Romantic', 'relationship_preference' => 'polyamorous'], 1), 1e-9);
        $this->assertEqualsWithDelta($base * 1.3 * 2.0,
            $gain(['inferred_temperament' => 'Romantic', 'relationship_preference' => 'monogamous'], 1), 1e-9);
        $this->assertSame(0.0, $gain(['inferred_temperament' => 'Romantic', 'relationship_preference' => 'not_interested'], 3));
    }

    public function testRivalIsRecordedAndTheTagAloneIsAJealousyEvent(): void
    {
        $d = $this->npc(['inferred_temperament' => 'Romantic']);
        RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['jealousy' => ['flag' => true, 'rival' => 'Aela', 'intensity' => 1]]), $d);
        $this->assertSame('Aela', $d['jealousy_trigger_npc']);

        $tagged = $this->npc(['inferred_temperament' => 'Romantic']);
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['tags' => ['jealousy_trigger']]), $tagged);
        $this->assertEqualsWithDelta((float) $d['jealousy_anger'], (float) $tagged['jealousy_anger'], 1e-9, 'tag = intensity 1');
    }

    /** §5: jealousy is not a grievance; a jealousy-kind grievance raises jealousy, not resentment. */
    public function testJealousyKindGrievanceRaisesJealousyNotResentment(): void
    {
        $d = $this->npc(['inferred_temperament' => 'Romantic'], ['resentment' => 10.0]);
        $out = RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['grievance' => ['flag' => true, 'kind' => 'jealousy', 'severity' => 2]]), $d);

        $this->assertNull($out['grievance']);
        $this->assertSame(10.0, self::res($d));
        $this->assertGreaterThan(0.0, (float) $d['jealousy_anger']);
    }

    public function testJealousyAtFortyOpensAConflict(): void
    {
        $d = $this->npc(['inferred_temperament' => 'Jealous', 'jealousy_anger' => 25.0]);
        RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['jealousy' => ['flag' => true, 'rival' => 'Aela', 'intensity' => 1]]), $d);   // +20
        $this->assertEqualsWithDelta(45.0, (float) $d['jealousy_anger'], 1e-9);
        $this->assertTrue($d['in_conflict']);
    }

    /** A bystander is jealous only with a committed core Player.type, no MARAS. */
    public function testBystanderJealousyNeedsACommittedCoreType(): void
    {
        $base = (float) RelationshipDynamics::defaultConfig()['jealousy_eval_gain'];
        $romantic = $this->npc(['inferred_temperament' => 'Romantic', '_core_rel_type' => 'romantic']);
        $friend = $this->npc(['inferred_temperament' => 'Romantic', '_core_rel_type' => 'platonic']);
        $unknown = $this->npc(['inferred_temperament' => 'Romantic']);

        $this->assertEqualsWithDelta($base * 1.3, RelationshipDynamics::bystanderJealousyGain($romantic), 1e-9);
        $this->assertSame(0.0, RelationshipDynamics::bystanderJealousyGain($friend));
        $this->assertSame(0.0, RelationshipDynamics::bystanderJealousyGain($unknown));
    }

    public function testIntimacyWithThisNpcIsReportedForTheBystanderScan(): void
    {
        $d = $this->npc();
        $out = RelationshipDynamics::applyEvalFeelings('Lydia',
            $this->item(['tags' => ['intimacy', 'praise'], 'positive_interaction' => true]), $d);
        $this->assertTrue($out['romantic_exposure']);
        $out = RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['tags' => ['help'], 'positive_interaction' => true]), $d);
        $this->assertFalse($out['romantic_exposure']);
    }

    /** MDD 6.5: jealousy 100 -> walkaway. */
    public function testJealousyAtOneHundredWalksAway(): void
    {
        $calm = $this->npc(['jealousy_anger' => 99.0], ['trust' => 70.0, 'respect' => 70.0]);
        $this->assertNotSame('walkaway', RelationshipDynamics::evaluateAutonomyState($calm, 'Stoic')['state']);
        $gone = $this->npc(['jealousy_anger' => 100.0], ['trust' => 70.0, 'respect' => 70.0]);
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($gone, 'Stoic')['state']);
    }

    // ------------------------------------------------------------ jealousy -> resentment (§5)

    /**
     * Decisions §5: while jealousy > 30, resentment += k x (jealousy - 30) / 70 per game day,
     * k = 2. That is the resentment the NPC ends up with, whoever she is: the conversion does
     * not go through applyDelta's physics (maturity Y, attachment gain, suppressed +50%).
     */
    public function testSustainedJealousyConvertsIntoResentmentPerGameDay(): void
    {
        $k = (float) RelationshipDynamics::defaultConfig()['jealousy_resentment_k'];
        $this->assertSame(2.0, $k);

        $profiles = [
            'secure, maturity 60'                  => [['attachment_style' => 'secure'], ['maturity' => 60.0, 'resentment' => 5.0]],
            'anxious, maturity 60'                 => [['attachment_style' => 'anxious'], ['maturity' => 60.0, 'resentment' => 5.0]],
            'secure, maturity 40, resentment 35'   => [['attachment_style' => 'secure'], ['maturity' => 40.0, 'resentment' => 35.0]],
            'anxious, maturity 30, resentment 35'  => [['attachment_style' => 'anxious'], ['maturity' => 30.0, 'resentment' => 35.0]],
        ];
        foreach ($profiles as $label => [$extra, $dims]) {
            $d = $this->npc(['jealousy_anger' => 65.0] + $extra, $dims);
            $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 3 * self::DAY);

            // 3 days x 2 x (65 - 30) / 70 = 3.0 resentment points
            $this->assertEqualsWithDelta(3.0, $r['jealousy_resentment_raw'], 1e-9, $label);
            $this->assertEqualsWithDelta($dims['resentment'] + 3.0, self::res($d), 1e-9, "{$label}: exactly k(j-30)/70 per day");
            $this->assertSame(65.0, (float) $d['jealousy_anger'], 'jealousy itself does not cool with absence');
        }
    }

    public function testJealousyAtOrBelowThirtyDoesNotConvert(): void
    {
        $d = $this->npc(['jealousy_anger' => 30.0], ['resentment' => 5.0]);
        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 10 * self::DAY);
        $this->assertSame(0.0, $r['jealousy_resentment_raw']);
        $this->assertSame(5.0, self::res($d));
    }

    public function testConversionDoesNotDependOnHowTheCalendarIsSliced(): void
    {
        $once = $this->npc(['jealousy_anger' => 80.0], ['resentment' => 5.0]);
        $sliced = $once;
        RelationshipDynamics::advanceCalendar($once, self::T0, self::T0 + 4 * self::DAY);
        for ($h = 0; $h < 4 * 24; $h++) {
            RelationshipDynamics::advanceCalendar($sliced, self::T0 + $h * self::HOUR, self::T0 + ($h + 1) * self::HOUR);
        }
        $this->assertEqualsWithDelta(self::res($once), self::res($sliced), 1e-6);
    }

    // ------------------------------------------------------------ conflict / repair

    /** An affinity drop of 10 core points below the session's high opens a conflict, whoever wrote it. */
    public function testAffinityDropWithinASessionOpensAConflict(): void
    {
        $d = $this->npc();
        $this->assertFalse(RelationshipDynamics::observeCoreAffinity($d, 20.0, self::T0));
        $this->assertFalse(RelationshipDynamics::observeCoreAffinity($d, 26.0, self::T0 + self::HOUR));
        $this->assertFalse(RelationshipDynamics::observeCoreAffinity($d, 18.0, self::T0 + 2 * self::HOUR), 'drop 8');
        $this->assertTrue(RelationshipDynamics::observeCoreAffinity($d, 16.0, self::T0 + 3 * self::HOUR), 'drop 10 from the high 26');
        $this->assertTrue($d['in_conflict']);
    }

    public function testASessionGapStartsFreshAndNoDropNeverConflicts(): void
    {
        $gap = (float) RelationshipDynamics::defaultConfig()['conflict_session_gap_game_hours'];
        $d = $this->npc();
        RelationshipDynamics::observeCoreAffinity($d, 30.0, self::T0);
        $this->assertFalse(RelationshipDynamics::observeCoreAffinity($d, 15.0, self::T0 + ($gap + 1) * self::HOUR),
            'a new session: the earlier high is not this session\'s');
        $this->assertFalse($d['in_conflict']);
        $this->assertFalse(RelationshipDynamics::observeCoreAffinity($d, 15.0, 0.0), 'unknown game clock: no judgement');
    }

    /** Three positive interactions with jealousy below 20 resolve the conflict with a +20 passion burst. */
    public function testThreePositiveInteractionsRepairTheConflict(): void
    {
        $d = $this->npc(['in_conflict' => true, 'conflict_positive_count' => 0, 'jealousy_anger' => 10.0]);
        RelationshipDynamics::setPassion($d, 30.0);
        $positive = $this->item(['positive_interaction' => true, 'tags' => ['apology']]);

        RelationshipDynamics::applyEvalFeelings('Lydia', $positive, $d);
        $this->assertSame(1, $d['conflict_positive_count']);
        RelationshipDynamics::applyEvalFeelings('Lydia', $positive, $d);
        $out = RelationshipDynamics::applyEvalFeelings('Lydia', $positive, $d);

        $this->assertFalse($d['in_conflict']);
        $this->assertSame(20.0, $out['repair_burst']);
        $this->assertEqualsWithDelta(50.0, RelationshipDynamics::getPassion($d), 1e-9);
    }

    public function testJealousyAboveTwentyHoldsTheConflictOpen(): void
    {
        $d = $this->npc(['in_conflict' => true, 'conflict_positive_count' => 0, 'jealousy_anger' => 25.0]);
        $positive = $this->item(['positive_interaction' => true]);
        foreach (range(1, 4) as $_) {
            RelationshipDynamics::applyEvalFeelings('Lydia', $positive, $d);
        }
        $this->assertTrue($d['in_conflict']);
    }
}
