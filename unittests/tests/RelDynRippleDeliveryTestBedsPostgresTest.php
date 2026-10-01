<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * Cascade ripples that travel, rulings 2026-10-01 §20 #13 and #14, end to end on a real PostgreSQL with the four
 * test beds (Aela the Huntress, Ashe (Serene's hand-set vector, never read, nothing of her story anywhere), Muiri
 * (toxic) and Lynly Star-Sung (the shy bard)) plus Farkas (Aela's Companions brother), Ysolda and Sven.
 *
 * The player mocks Aela in Breezehome (Whiterun) with only Farkas in the room. Through the real hooks, the real eval
 * producer and worker (LLM stubbed at the connector boundary):
 *   - Farkas was there: his ripple is queued as 'witnessed' and lands at his very next turn, as seen, undamped (the
 *     MDD's Farkas at 80 loses 8 of a 10 drop);
 *   - nobody else is told by telepathy. Lynly (last seen in Whiterun), Ashe (last seen at the College in
 *     Winterhold, two holds off) and Muiri (never seen anywhere: as far as the map goes) each get a PENDING ripple
 *     carrying the earliest game time it can reach her, sooner the closer by place and by bond; a turn before then
 *     applies nothing, says nothing and leaves core's affinity alone; a turn after applies it, damped as hearsay,
 *     and she says she has heard (not seen);
 *   - word of mouth shortens it: when Ashe and Aela actually talk (a rechat NPC-to-NPC exchange) Ashe has it at her
 *     next turn, long before the news could have reached the College; a talk with anyone else does nothing;
 *   - the same news lands differently on each of them by who they are, and by when.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynRippleDeliveryTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const YSOLDA = RelDynNetworkBedsKit::YSOLDA;
    private const SVEN = RelDynNetworkBedsKit::SVEN;
    private const INSULT = 'You call that a hunt? I mock your whole pack of hunters.';
    private const SUMMARY = 'The player mocked her hunt in front of her shield-brother.';
    private const HOUR = RelDynNetworkBedsKit::HOUR;

    private RelDynNetworkBedsKit $kit;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        if (isset($this->kit)) $this->kit->destroy();
    }

    /** The cascade world; bonds to Aela as given (Farkas 60, Lynly 45, Ashe 35, Muiri -50 unless overridden). */
    private function world(array $bonds = [], array $config = []): RelDynNetworkBedsKit
    {
        $b = $bonds + [self::FARKAS => 60, self::LYNLY => 45, self::ASHE => 35, self::MUIRI => -50];
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'ripple', $config);
        $this->kit->seed([
            self::AELA => ['Player' => [30, 'platonic'], self::FARKAS => [70, 'friend'], self::LYNLY => [20, 'neutral'],
                           self::ASHE => [10, 'neutral'], self::MUIRI => [-30, 'rival']],
            self::FARKAS => ['Player' => [40, 'friend'], self::AELA => [$b[self::FARKAS], 'friend']],
            self::LYNLY => ['Player' => [40, 'friend'], self::AELA => [$b[self::LYNLY], 'friend']],
            self::ASHE => ['Player' => [40, 'friend'], self::AELA => [$b[self::ASHE], 'neutral']],
            self::MUIRI => ['Player' => [40, 'friend'], self::AELA => [$b[self::MUIRI], 'rival']],
            self::YSOLDA => ['Player' => [40, 'friend']],
            self::SVEN => ['Player' => [40, 'friend'], self::AELA => [20, 'neutral']],
        ]);
        pg_query($this->kit->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Winterhold College', 'Winterhold', 'Mage,', 1, 'WinterholdWorld'), ('Dragonsreach', 'Whiterun', 'Palace,', 1, 'WhiterunWorld')");
        $this->kit->people = '|' . RelDynNetworkBedsKit::PLAYER . '|';   // the player alone: nobody is placed by this row
        $this->kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        $this->kit->people = null;
        return $this->kit;
    }

    private function t(float $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    /**
     * $npc was in $place at game time $at (an eventlog row of the kind core writes: who was present, and where, as the
     * whole location context string core logs). $hold null: only the bare place name is logged (the locations table
     * says which hold it is in).
     */
    private function seenAt(string $npc, string $place, int $at, ?string $hold = null): void
    {
        $where = $hold === null ? $place
            : "(Context location: {$place} ,Hold: {$hold}, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)";
        pg_query_params($this->kit->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
            VALUES ('infonpc_close', 'idle', 'pending', \$1, \$2, \$1, \$3, \$4)",
            [$at, $this->kit->realTs, "|{$npc}|" . RelDynNetworkBedsKit::PLAYER . '|', $where]);
    }

    private function scoreInsult(array $loss = ['affinity' => -30, 'trust' => -4]): void
    {
        $this->kit->evalReply = function (string $exchange) use ($loss): ?array {
            if (str_contains($exchange, 'mock your whole pack')) {
                return ['signals' => $loss, 'tags' => ['insult'], 'significance' => 1.0, 'summary' => self::SUMMARY,
                        'grievance' => ['flag' => true, 'kind' => 'disrespect', 'severity' => 2]];
            }
            return null;
        };
    }

    /** The player mocks Aela in Breezehome with $present in the room (and nobody else); the worker applies and ripples it. */
    private function insultAela(array $present = [self::FARKAS]): void
    {
        $this->kit->people = '|' . self::AELA . '|' . implode('|', $present) . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $this->kit->turn(self::AELA, self::INSULT, $this->t(0), 'insult');
        pg_query($this->kit->db->link, "UPDATE eventlog SET location = '" . RelDynNetworkBedsKit::HOME . "' WHERE location = ''");   // where it happened, as core logs it
        $this->kit->worker();
        $this->kit->people = null;
    }

    private function inbox(string $npc): array
    {
        $v = $this->kit->pluginKey($npc, RelDynCascade::INBOX_KEY);
        return is_array($v) ? $v : [];
    }

    private function ripple(string $npc): array
    {
        $inbox = $this->inbox($npc);
        $this->assertCount(1, $inbox, "{$npc} has the news waiting");
        return $inbox[0];
    }

    private function talk(string $npc, string $with, float $hours): void
    {
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = $with;
        $this->kit->request($npc, ['rechat', (string) $this->kit->realTs, (string) $this->t($hours), json_encode(['speaker' => $with, 'text' => 'Did you hear?'])], $with, 'talk');
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
    }

    private function assertClean(): void
    {
        $this->assertSame([], $this->kit->db->failures, 'failed SQL statements');
        $this->assertSame(0, $this->kit->llmCalls, 'no trait read');
        $log = $this->kit->errorLog();
        $this->assertStringNotContainsString('ERROR', $log, $log);
    }

    private function moved(string $npc, int $before): float
    {
        $d = $this->kit->dynamics($npc);
        return round($this->kit->coreAff($npc) - $before + floatval($d['_pending_aff_delta'] ?? 0), 3);
    }

    // ------------------------------------------------------------------ the story

    public function testAWitnessHearsAtOnceAndTheRestAreQueuedWithTheTimeTheNewsNeedsToReachThem(): void
    {
        $this->world();
        $this->scoreInsult();
        // before the news: Lynly was in Whiterun, Ashe at the College in Winterhold; Muiri was seen nowhere in particular
        $this->seenAt(self::LYNLY, 'Dragonsreach', $this->t(-5), 'Whiterun');
        $this->seenAt(self::ASHE, 'Winterhold College', $this->t(-5));
        $this->insultAela();
        $lost = 30 - $this->kit->coreAff(self::AELA);
        $this->assertGreaterThanOrEqual(15, $lost, 'a big loss, over the ripple threshold');
        $news = $this->t(0);

        $farkas = $this->ripple(self::FARKAS);
        $this->assertSame('witnessed', $farkas['via'], 'he was in the room');
        $this->assertSame($farkas['gamets'], $farkas['ready_at'], 'nothing to wait for');
        $this->assertEqualsWithDelta(-$lost * 0.60, $farkas['delta'], 0.6, 'seen: the whole of the MDD figure, change x bond');

        $lynly = $this->ripple(self::LYNLY);
        $ashe = $this->ripple(self::ASHE);
        $muiri = $this->ripple(self::MUIRI);
        foreach ([self::LYNLY => $lynly, self::ASHE => $ashe, self::MUIRI => $muiri] as $npc => $item) {
            $this->assertSame('delayed', $item['via'], "{$npc} was not there");
            $this->assertGreaterThan($item['gamets'], $item['ready_at'], "{$npc}: a pending ripple carries the earliest game time it can reach her");
        }
        // hearsay is damped, what a witness saw is not: the same change through the same kind of bond
        $decay = (float) RelationshipDynamics::defaultConfig()['cascade_decay'];
        $this->assertEqualsWithDelta(-$lost * 0.45 * $decay, $lynly['delta'], 0.6);
        $this->assertEqualsWithDelta(-$lost * 0.35 * $decay, $ashe['delta'], 0.6);
        $this->assertGreaterThan(0.0, $muiri['delta'], 'the enemy is glad of it, when she hears');

        // sooner the closer: Lynly (the same hold, a fair bond) before Ashe (two holds off) before Muiri (nobody has seen her)
        $hours = fn(array $i) => ($i['ready_at'] - $news) / self::HOUR;
        $this->assertGreaterThan(0.5, $hours($lynly), 'never instant');
        $this->assertLessThan(3.0, $hours($lynly), 'the same hold: an hour or two');
        $this->assertGreaterThan($hours($lynly) + 6.0, $hours($ashe));
        $this->assertLessThan(24.0, $hours($ashe));
        $this->assertGreaterThan($hours($ashe) + 12.0, $hours($muiri), 'the other end of the map: days, not hours');
        $this->assertLessThanOrEqual(168.0, $hours($muiri));

        // LAZY, and no telepathy: not one of them has been touched
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) {
            $this->assertArrayNotHasKey(RelDynCascade::APPLIED_KEY, $this->kit->dynamics($npc), $npc);
        }
        $this->assertSame([], $this->inbox(self::YSOLDA), 'no bond to her');
        $this->assertSame([], $this->inbox(self::SVEN), 'a bond too weak to hear');
        $this->assertClean();
    }

    public function testTheWitnessHearsAtHisNextTurnAndSaysSeenNotHeard(): void
    {
        $this->world();
        $this->scoreInsult();
        $this->insultAela();
        $before = $this->kit->coreAff(self::FARKAS);
        $raw = $this->ripple(self::FARKAS)['delta'];
        $this->kit->turn(self::FARKAS, 'Well met, Farkas.', $this->t(0.1), 'hear');   // six game minutes on: no waiting
        $moved = $this->moved(self::FARKAS, $before);
        $this->assertLessThan(0, $moved, 'Farkas thinks less of the player');
        $this->assertGreaterThan($raw, $moved, 'through his own curve: it lands, but never louder than the news (keeps the per-target curve)');
        // What he ends up losing is NOT the MDD figure: that (about 0.6 of the drop at bond 60, 0.8 at 80) is the queued
        // ripple, and his own social-sensitivity curve at his bond with the player takes a further fifth to a third
        // off it (batch U review). Pinned so a change to either is seen: a share between a third and the ripple's own.
        $share = abs($moved) / (30 - $this->kit->coreAff(self::AELA));
        $this->assertLessThan(0.60, $share, 'the curve shaves the queued ripple');
        $this->assertGreaterThan(0.33, $share, 'but he still feels most of what he saw');
        $this->assertSame([], $this->inbox(self::FARKAS));
        $this->assertCount(1, $this->kit->dynamics(self::FARKAS)[RelDynCascade::APPLIED_KEY], 'remembered by id');
        $line = $this->kit->felt[self::FARKAS]['hear']['cascade_ally_hurt'] ?? '';
        $this->assertStringContainsString('has seen what', $line, 'he was there');
        $this->assertStringNotContainsString('has heard what', $line);
        $this->assertClean();
    }

    public function testAnotherHoldsNewsDoesNotReachHerBeforeItCouldAndThenItDoes(): void
    {
        $this->world();
        $this->scoreInsult();
        $this->seenAt(self::LYNLY, 'Dragonsreach', $this->t(-5), 'Whiterun');
        $this->seenAt(self::ASHE, 'Winterhold College', $this->t(-5));
        $this->insultAela();
        $lynly = $this->ripple(self::LYNLY);
        $ashe = $this->ripple(self::ASHE);
        $lynlyBefore = $this->kit->coreAff(self::LYNLY);
        $asheBefore = $this->kit->coreAff(self::ASHE);

        // twenty game minutes on: it cannot have reached either of them
        $this->kit->turn(self::LYNLY, 'Evening, Lynly.', $this->t(0.34), 'early');
        $this->kit->turn(self::ASHE, 'Evening, Ashe.', $this->t(0.4), 'early');
        foreach ([self::LYNLY, self::ASHE] as $npc) {
            $this->assertCount(1, $this->inbox($npc), "{$npc}: still waiting");
            $this->assertArrayNotHasKey(RelDynCascade::APPLIED_KEY, $this->kit->dynamics($npc), $npc);
            $this->assertSame([], array_filter(array_keys($this->kit->felt[$npc]['early']), fn($k) => str_starts_with((string) $k, 'cascade_')), "{$npc} knows nothing yet");
        }
        $this->assertSame($lynlyBefore, $this->kit->coreAff(self::LYNLY));
        $this->assertSame($asheBefore, $this->kit->coreAff(self::ASHE));

        // past Lynly's ready_at and well short of Ashe's: Lynly has heard, Ashe has not
        $mid = ($lynly['ready_at'] - $this->t(0)) / self::HOUR + 0.5;
        $this->assertLessThan(($ashe['ready_at'] - $this->t(0)) / self::HOUR, $mid);
        $this->kit->turn(self::LYNLY, 'Evening again.', $this->t($mid), 'late');
        $this->kit->turn(self::ASHE, 'Evening again.', $this->t($mid + 0.1), 'late');
        $this->assertSame([], $this->inbox(self::LYNLY), 'it reached her');
        $this->assertLessThan(0, $this->moved(self::LYNLY, $lynlyBefore));
        $this->assertStringContainsString('has heard what', $this->kit->felt[self::LYNLY]['late']['cascade_ally_hurt'] ?? '', 'heard, not seen');
        $this->assertCount(1, $this->inbox(self::ASHE), 'two holds off: it has not reached her');
        $this->assertSame($asheBefore, $this->kit->coreAff(self::ASHE));

        // and the enemy at the far end of the map keeps waiting, days if need be
        $this->kit->turn(self::MUIRI, 'Muiri.', $this->t($mid + 0.2), 'late');
        $this->assertCount(1, $this->inbox(self::MUIRI));
        $this->assertSame(40, $this->kit->coreAff(self::MUIRI));
        $muiriReady = ($this->ripple(self::MUIRI)['ready_at'] - $this->t(0)) / self::HOUR;
        $this->kit->turn(self::MUIRI, 'Muiri, a word.', $this->t($muiriReady + 1), 'later');
        $this->assertSame([], $this->inbox(self::MUIRI), 'by then it has reached even her');
        $this->assertGreaterThan(0, $this->moved(self::MUIRI, 40), 'and she is not sorry');
        $this->assertStringContainsString('not sorry', $this->kit->felt[self::MUIRI]['later']['cascade_rival_hurt'] ?? '');
        $this->assertClean();
    }

    public function testWordOfMouthBringsItEarlyWhenSheAndTheSourceActuallyTalk(): void
    {
        $this->world();
        $this->scoreInsult();
        $this->seenAt(self::ASHE, 'Winterhold College', $this->t(-5));
        $this->seenAt(self::YSOLDA, 'Dragonsreach', $this->t(-5), 'Whiterun');
        $this->insultAela();
        $ashe = $this->ripple(self::ASHE);
        $readyAfter = ($ashe['ready_at'] - $this->t(0)) / self::HOUR;
        $this->assertGreaterThan(8.0, $readyAfter, 'a long way off');
        $asheBefore = $this->kit->coreAff(self::ASHE);

        // Ashe talks with Ysolda (who knows nothing of it): no news in that
        $this->talk(self::ASHE, self::YSOLDA, 1.0);
        $this->assertNull($this->kit->pluginKey(self::ASHE, RelDynCascade::TALK_KEY), 'a talk with someone who carries no news of Aela is not noted');
        $this->kit->turn(self::ASHE, 'Evening, Ashe.', $this->t(1.5), 'quiet');
        $this->assertCount(1, $this->inbox(self::ASHE));
        $this->assertSame($asheBefore, $this->kit->coreAff(self::ASHE));

        // Ysolda and Sven talk: nothing waits for either, and nothing is written
        $this->talk(self::YSOLDA, self::SVEN, 1.6);
        $this->assertNull($this->kit->pluginKey(self::YSOLDA, RelDynCascade::TALK_KEY));
        $this->assertNull($this->kit->pluginKey(self::SVEN, RelDynCascade::TALK_KEY));

        // Ashe and Aela talk (a rechat answering her, NPC to NPC, the player not in it): the word is out
        $this->talk(self::ASHE, self::AELA, 2.0);
        $ledger = $this->kit->pluginKey(self::ASHE, RelDynCascade::TALK_KEY);
        $this->assertIsArray($ledger);
        $this->assertSame(self::AELA, $ledger[0]['with']);
        $this->assertSame($asheBefore, $this->kit->coreAff(self::ASHE), 'a talk between NPCs does not touch the player pair');
        $this->assertCount(1, $this->inbox(self::ASHE), 'and applies nothing itself: it lands at her next turn with the player');

        $this->kit->turn(self::ASHE, 'Ashe, how are you?', $this->t(2.5), 'told');   // hours before the news could have crossed two holds
        $this->assertLessThan($readyAfter, 2.5);
        $this->assertSame([], $this->inbox(self::ASHE), 'she has it');
        $this->assertLessThan(0, $this->moved(self::ASHE, $asheBefore), 'and thinks the less of the player');
        $this->assertStringContainsString('has heard what', $this->kit->felt[self::ASHE]['told']['cascade_ally_hurt'] ?? '');
        $this->assertNull($this->kit->pluginKey(self::ASHE, RelDynCascade::TALK_KEY), 'the ledger is spent');
        $this->assertClean();
    }

    public function testACloserBondAndACloserPlaceHearSooner(): void
    {
        // Farkas (80) and Lynly (45) were both in Whiterun, neither in the room: the closer bond hears first. Ashe at 45
        // two holds off hears after Lynly at the same bond: the closer place does.
        $this->world([self::FARKAS => 80, self::ASHE => 45]);
        $this->scoreInsult();
        $this->seenAt(self::FARKAS, 'Dragonsreach', $this->t(-3), 'Whiterun');
        $this->seenAt(self::LYNLY, 'Dragonsreach', $this->t(-3), 'Whiterun');
        $this->seenAt(self::ASHE, 'Winterhold College', $this->t(-3));
        $this->insultAela([]);
        $at = fn(string $npc) => $this->ripple($npc)['ready_at'];
        $this->assertSame('delayed', $this->ripple(self::FARKAS)['via'], 'nobody was in the room');
        $this->assertLessThan($at(self::LYNLY), $at(self::FARKAS), 'the same place: the closer bond is sooner');
        $this->assertLessThan($at(self::ASHE), $at(self::LYNLY), 'the same bond: the nearer place is sooner');
        // Farkas at 80: pending, and when it lands it is still most of the MDD's figure (8 of 10, damped only by the telling)
        $lost = 30 - $this->kit->coreAff(self::AELA);
        $this->assertEqualsWithDelta(-$lost * 0.80 * (float) RelationshipDynamics::defaultConfig()['cascade_decay'], $this->ripple(self::FARKAS)['delta'], 0.6);
        $this->assertClean();
    }

    public function testThePendingRippleQueuedTwiceLandsOnce(): void
    {
        $this->world();
        $this->scoreInsult();
        $this->seenAt(self::LYNLY, 'Dragonsreach', $this->t(-5), 'Whiterun');
        $this->insultAela();
        $item = $this->ripple(self::LYNLY);
        // a crash between queueing and the source's save: the same pending ripple is appended again
        RelDynStorage::appendItem($this->kit->id(self::LYNLY), RelDynCascade::INBOX_KEY, $item);
        $this->assertCount(2, $this->inbox(self::LYNLY));
        $before = $this->kit->coreAff(self::LYNLY);
        $this->kit->turn(self::LYNLY, 'Hello.', $this->t(0.2), 'early');
        $this->assertCount(2, $this->inbox(self::LYNLY), 'neither copy has come due');
        $this->kit->turn(self::LYNLY, 'Hello again.', $this->t(5), 'due');
        $this->assertSame([], $this->inbox(self::LYNLY));
        $this->assertCount(1, $this->kit->dynamics(self::LYNLY)[RelDynCascade::APPLIED_KEY], 'one id');
        $once = $this->moved(self::LYNLY, $before);
        $this->assertLessThan(0, $once);
        $this->assertGreaterThan($item['delta'], $once, 'applied once, through her curve');
        $this->assertClean();
    }

    public function testAPendingRippleSurvivesAnotherRipplesDeliveryAndTheNextOneJoinsIt(): void
    {
        // Farkas (in the room) hears at once while Ashe's, from the same news, stays in HER inbox; a second piece of news
        // queued meanwhile joins hers and both come due together
        $this->world();
        $this->scoreInsult();
        $this->seenAt(self::ASHE, 'Winterhold College', $this->t(-5));
        $this->insultAela();
        $first = $this->ripple(self::ASHE);
        RelDynStorage::appendItem($this->kit->id(self::ASHE), RelDynCascade::INBOX_KEY,
            ['id' => 'second', 'source' => self::AELA, 'delta' => -2.0, 'kind' => 'ally_hurt', 'gamets' => $this->t(1), 'anchor' => null,
             'defining' => false, 'via' => 'delayed', 'ready_at' => $this->t(2)]);
        $this->kit->turn(self::ASHE, 'Evening.', $this->t(1.5), 'early');
        $this->assertCount(2, $this->inbox(self::ASHE), 'nothing due yet: both wait');
        // the second is due, the first (two holds) is not
        $this->kit->turn(self::ASHE, 'Evening.', $this->t(3), 'second');
        $left = $this->inbox(self::ASHE);
        $this->assertCount(1, $left, 'only the one that came due left the inbox');
        $this->assertSame($first['id'], $left[0]['id'], 'the pending one is kept');
        $this->assertSame(['second'], $this->kit->dynamics(self::ASHE)[RelDynCascade::APPLIED_KEY]);
        $this->assertClean();
    }
}
