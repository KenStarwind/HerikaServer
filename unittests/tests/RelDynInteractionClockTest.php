<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Batch T traits: diminishing-returns (3, 4) and diary-reflection-eval (1).
 *
 * interaction_count is the diminishing-returns factor: recordInteraction decays it exponentially
 * over play time before adding one, so at a steady pace it settles (about 116 at ten talks per play
 * hour) and then only wiggles. It was also read as a monotone clock by the diary's interaction gap,
 * the ick window and the parasite ledger: at a steady pace "15 interactions since the last diary
 * moment" never came again, so moments (and the baseline drift that runs at a mark) stopped for good.
 * Those three read the lifetime counter (interactionClock) now; interaction_count is only the
 * session multiplier's.
 *
 * Everything here goes through the real recordInteraction / getSessionMultiplier /
 * checkDiaryTrigger / markDiaryCompleted / updateIckTracker, on an NPC with a play clock; no database.
 */
final class RelDynInteractionClockTest extends TestCase
{
    private const PLAY_HOUR = 2315 * 3600;   // play gamets in one real hour of play

    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    /** A mature Guarded NPC in a standing crisis (every diary check has something to write about). */
    private function npc(): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Guarded',
        ]));
        foreach (['maturity' => 60.0, 'trust' => 10.0, 'comfort' => 50.0, 'respect' => 50.0, 'resentment' => 40.0,
                  'resentment_self' => 0.0, 'self_confidence' => 50.0] as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        return $d;
    }

    /** One talk: the play clock moves $minutes, the real recordInteraction counts it. */
    private function talk(array &$d, float $minutes): void
    {
        $d['_accumulated_play_gamets'] = floatval($d['_accumulated_play_gamets'] ?? 0) + self::PLAY_HOUR * $minutes / 60.0;
        $d['_accumulated_time'] = intval($d['_accumulated_time'] ?? 0) + intval(round($minutes * 60));
        RelationshipDynamics::recordInteraction($d);
    }

    public function testTheLifetimeCounterOnlyEverGrowsWhileTheDiminishingCountSettles(): void
    {
        $d = $this->npc();
        $clock = [];
        $count = [];
        for ($i = 1; $i <= 300; $i++) {
            $this->talk($d, 6.0);     // ten talks per play hour
            $clock[] = RelationshipDynamics::interactionClock($d);
            $count[] = intval($d['interaction_count']);
        }
        $this->assertSame(range(1, 300), $clock, 'one per talk, never decayed');
        $this->assertLessThan(150, max($count), 'the diminishing-returns count settles');
        $this->assertLessThan(5, abs($count[299] - $count[250]), 'and only wiggles at a steady pace');
    }

    public function testDiaryMomentsKeepComingAtASteadyPaceForeverNotJustUntilTheCountSettles(): void
    {
        $d = $this->npc();
        $marks = [];
        for ($i = 1; $i <= 1500; $i++) {
            $this->talk($d, 6.0);
            if (RelationshipDynamics::checkDiaryTrigger('Tester', $d)) {
                RelationshipDynamics::markDiaryCompleted($d);
                $marks[] = $i;
            }
        }
        // Fifteen interactions per moment, as DIARY_INTERACTION_GAP says: 100 in 1500 talks
        $this->assertGreaterThanOrEqual(95, count($marks), 'the diary kept marking moments to the end');
        $this->assertGreaterThan(1450, max($marks), 'the last moment is near the last talk, not at the first plateau');
        $gaps = array_map(fn($a, $b) => $b - $a, array_slice($marks, 0, -1), array_slice($marks, 1));
        $this->assertSame(RelationshipDynamics::DIARY_INTERACTION_GAP, min($gaps));
        $this->assertSame(RelationshipDynamics::DIARY_INTERACTION_GAP, max($gaps), 'exactly one gap apart, every time');
    }

    public function testADiaryBookmarkAheadOfTheClockFromAnOldSaveIsRearmedNotFrozen(): void
    {
        // An old save's bookmark is a value of the decaying count (it can be anywhere); the lifetime
        // counter starts from the count the save had. A bookmark above the clock reads as "marked now"
        $d = $this->npc();
        $d['interaction_count'] = 40;
        $d['_diary_last_interaction'] = 500;
        $this->talk($d, 6.0);
        $this->assertFalse(RelationshipDynamics::checkDiaryTrigger('Tester', $d), 'just marked, in effect');
        for ($i = 0; $i < RelationshipDynamics::DIARY_INTERACTION_GAP; $i++) $this->talk($d, 6.0);
        $this->assertTrue(RelationshipDynamics::checkDiaryTrigger('Tester', $d), 'a gap of talks later it is due');
    }

    public function testTheIckWindowRollsAtAnyPaceBecauseItCountsLifetimeTalks(): void
    {
        $d = $this->npc();
        $resets = 0;
        $lastStart = null;
        for ($i = 1; $i <= 400; $i++) {
            $this->talk($d, 6.0);
            RelationshipDynamics::updateIckTracker($d, false, 'Guarded');
            $start = $d['_ick_tracker']['window_start'];
            if ($lastStart !== null && $start !== $lastStart) $resets++;
            $lastStart = $start;
        }
        // ICK_WINDOW_SIZE talks per window: 400 talks roll it about 40 times (the first window starts at the first talk)
        $this->assertGreaterThanOrEqual(38, $resets);
        $this->assertLessThanOrEqual(40, $resets);
    }

    public function testTheParasiteLedgerStampsLifetimeTalksNotTheDecayingCount(): void
    {
        $d = $this->npc();
        for ($i = 1; $i <= 250; $i++) $this->talk($d, 6.0);
        $this->assertLessThan(250, intval($d['interaction_count']), 'the diminishing count is lower than the talks had');
        $d['_interaction_pattern'] = ['gift_count' => 9, 'genuine_count' => 0, 'total_window' => 10, 'window_start' => 0];
        $d['_core_rel_type'] = 'friend';
        $type = RelationshipDynamics::checkParasitePattern('Tester', $d);
        $this->assertSame('parasite', $type);
        $this->assertSame(250, $d['_relationship_type_history'][0]['at']);
    }

    public function testACombatBeatIsAnInteractionOnBothCounters(): void
    {
        $d = $this->npc();
        for ($i = 1; $i <= 3; $i++) $this->talk($d, 6.0);
        RelationshipDynamics::countCombatInteraction($d);
        $this->assertSame(4, $d['interaction_count']);
        $this->assertSame(4, RelationshipDynamics::interactionClock($d));
    }

    public function testASaveWithoutTheLifetimeCounterReadsItsCountUntilTheNextTalkSeedsIt(): void
    {
        $d = array_merge(RelationshipDynamics::defaultDynamics(), ['interaction_count' => 7]);
        $this->assertSame(7, RelationshipDynamics::interactionClock($d), 'legacy: the stored count');
        $d['_accumulated_play_gamets'] = self::PLAY_HOUR;
        $d['last_interaction_at'] = self::PLAY_HOUR;     // the same instant: no decay before the +1
        RelationshipDynamics::recordInteraction($d);
        $this->assertSame(8, RelationshipDynamics::interactionClock($d), 'seeded from the count, then +1');
        $d['_accumulated_play_gamets'] = self::PLAY_HOUR * 50;   // a long played break: the count melts
        RelationshipDynamics::recordInteraction($d);
        $this->assertSame(9, RelationshipDynamics::interactionClock($d), 'the clock does not melt with it');
        $this->assertLessThan(3, $d['interaction_count']);
    }

    // ------------------------------------------------------------------ diminishing returns, across turns

    public function testConsecutiveTurnsEachGainLessAndAPlayedBreakGivesTheGainBack(): void
    {
        $d = $this->npc();
        $d['warmth_curve'] = 'moderate';
        $mult = [];
        for ($i = 0; $i < 12; $i++) {
            $this->talk($d, 2.0);     // a talk every two play minutes
            $mult[] = RelationshipDynamics::getSessionMultiplier($d);
        }
        for ($i = 1; $i < 12; $i++) {
            $this->assertLessThan($mult[$i - 1], $mult[$i], "talk " . ($i + 1) . ' pays less than the one before');
        }
        $this->assertLessThan(0.5, end($mult), 'a chatty session is well down');
        // Ten played hours away: the count melts (lambda .087/h), the multiplier comes back
        $d['_accumulated_play_gamets'] += self::PLAY_HOUR * 10;
        $after = RelationshipDynamics::getSessionMultiplier($d);
        $this->assertGreaterThan(end($mult) + 0.2, $after);
        $this->assertLessThanOrEqual(1.0, $after);
        // ... and the count itself, not the lifetime clock, is what melted
        $clockBefore = RelationshipDynamics::interactionClock($d);
        RelationshipDynamics::recordInteraction($d);
        $this->assertSame($clockBefore + 1, RelationshipDynamics::interactionClock($d));
    }
}
