<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RelDynNetworkBedsKit.php';

/**
 * cascade-network (MDD 10, lazy), end to end on a real PostgreSQL with the four test beds
 * (standing rule feedback_reldyn_testbeds: Aela the Huntress, Ashe (Serene's hand-set vector,
 * never read, nothing of her story anywhere), Muiri (toxic) and Lynly Star-Sung (the shy bard)),
 * plus Farkas (Aela's Companions brother), Ysolda (no bond to Aela) and Sven (a bond too weak to hear).
 *
 * The player mocks Aela's hunt in front of her shield-siblings; the eval (LLM stubbed at the
 * connector boundary) scores it a big loss. Through the real hooks and the real eval producer and
 * worker:
 *   - the loss is noted on Aela with the eval item and queued on every NPC who holds a bond to her
 *     (strongest first; a bond of 30 or less does not hear; no bond, nothing);
 *   - NOTHING is applied to any of them then (lazy: their core Player.aff is untouched);
 *   - each target applies it at ITS next prerequest, through its own social sensitivity curve, into
 *     core's relationships.Player.aff (not overwritten by the mirror refresh), with a one-shot felt
 *     line "heard what the player did to Aela", said once;
 *   - an ally takes her side (loses), an enemy the opposite (the inverted ripple), and the same
 *     news lands differently on each of them by who they are.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCascadeNetworkTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const YSOLDA = RelDynNetworkBedsKit::YSOLDA;
    private const SVEN = RelDynNetworkBedsKit::SVEN;
    private const INSULT = 'You call that a hunt? I mock your whole pack of hunters.';
    private const SUMMARY = 'The player mocked her hunt in front of her shield-siblings.';

    private RelDynNetworkBedsKit $kit;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->kit)) $this->kit->destroy();
    }

    private function world(array $config = [], int $aelaAff = 30): RelDynNetworkBedsKit
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'cascade', $config);
        $this->kit->seed([
            self::AELA => ['Player' => [$aelaAff, 'platonic'], self::FARKAS => [70, 'friend'], self::LYNLY => [20, 'neutral'],
                           self::ASHE => [10, 'neutral'], self::MUIRI => [-30, 'rival']],
            self::FARKAS => ['Player' => [40, 'friend'], self::AELA => [60, 'friend']],
            self::LYNLY => ['Player' => [40, 'friend'], self::AELA => [45, 'friend']],
            self::ASHE => ['Player' => [40, 'friend'], self::AELA => [35, 'neutral']],
            self::MUIRI => ['Player' => [40, 'friend'], self::AELA => [-50, 'rival']],
            self::YSOLDA => ['Player' => [40, 'friend']],
            self::SVEN => ['Player' => [40, 'friend'], self::AELA => [20, 'neutral']],
        ]);
        $this->kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        return $this->kit;
    }

    /** The LLM at the connector boundary: the insult is a big loss, a rescue a gain, anything else small talk. */
    private function scoreInsult(array $loss = ['affinity' => -30, 'trust' => -4], float $significance = 1.0): void
    {
        $this->kit->evalReply = function (string $exchange) use ($loss, $significance): ?array {
            if (str_contains($exchange, 'mock your whole pack')) {
                return ['signals' => $loss, 'tags' => ['insult'], 'significance' => $significance, 'summary' => self::SUMMARY,
                        'grievance' => ['flag' => true, 'kind' => 'disrespect', 'severity' => 2]];
            }
            return null;
        };
    }

    private function insultAela(int $gamets): void
    {
        $this->kit->turn(self::AELA, self::INSULT, $gamets, 'insult');
        $this->kit->worker();
    }

    private function inbox(string $npc): array
    {
        $v = $this->kit->pluginKey($npc, RelDynCascade::INBOX_KEY);
        return is_array($v) ? $v : [];
    }

    private function t(int $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    /** RELDYN_PROBE=1: print a scene's numbers (tuning aid, no effect on the test). */
    private function probe(string $label, array $data): void
    {
        if (getenv('RELDYN_PROBE')) fwrite(STDERR, "
=== {$label}
" . json_encode($data, JSON_PRETTY_PRINT));
    }

    /** No error the code logged, no failed query. */
    private function assertClean(): void
    {
        $this->assertSame([], $this->kit->db->failures, 'failed SQL statements');
        $this->assertSame(0, $this->kit->llmCalls, 'no trait read');
        $log = $this->kit->errorLog();
        $this->assertStringNotContainsString('ERROR', $log, $log);
    }

    // ------------------------------------------------------------------ the story

    public function testInsultingAelaRipplesToHerFriendsLazilyAndEachHearsOnTheirNextTurn(): void
    {
        $this->world();
        $this->scoreInsult();
        $before = [];
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI, self::YSOLDA, self::SVEN] as $npc) $before[$npc] = $this->kit->coreAff($npc);

        $this->insultAela($this->t(0));

        // Aela took it: her own affinity fell, and the loss was noted and queued
        $aela = $this->kit->dynamics(self::AELA);
        $this->assertLessThan(30, $this->kit->coreAff(self::AELA), 'she lost affinity toward the player');
        $this->assertArrayNotHasKey(RelDynCascade::OUT_KEY, $aela, 'every noted ripple was queued and cleared');
        $lost = 30 - $this->kit->coreAff(self::AELA);
        $this->probe('insult', ['lost' => $lost, 'inboxes' => array_map(fn($n) => $this->inbox($n), [self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI])]);

        // Queued on everyone who holds a bond above 30 to her; nobody else
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertCount(1, $this->inbox($npc), "{$npc} was told");
        $this->assertSame([], $this->inbox(self::YSOLDA), 'no bond to her: nothing');
        $this->assertSame([], $this->inbox(self::SVEN), 'a bond of 20 is under the filter: nothing');
        $this->assertSame([], $this->inbox(self::AELA), 'not told of her own');
        $farkas = $this->inbox(self::FARKAS)[0];
        $this->assertSame(self::AELA, $farkas['source']);
        $this->assertSame('ally_hurt', $farkas['kind']);
        $this->assertSame(rtrim(self::SUMMARY, '.'), $farkas['anchor'], 'the reason anchor travels, cleaned of its full stop');
        // ripple = change x bond/100 x decay: Farkas (60) hears more than Lynly (45), more than Ashe (35); Muiri (-50) the inverse, weaker
        $ripple = fn(string $npc) => floatval($this->inbox($npc)[0]['delta']);
        $this->assertEqualsWithDelta(-$lost * 0.60 * 0.3, $ripple(self::FARKAS), 0.6);
        $this->assertLessThan($ripple(self::LYNLY), $ripple(self::FARKAS), 'a closer friend hears more (more negative)');
        $this->assertLessThan($ripple(self::ASHE), $ripple(self::LYNLY));
        $this->assertGreaterThan(0.0, $ripple(self::MUIRI), 'an enemy of hers is glad: the ripple is inverted');
        $this->assertSame('rival_hurt', $this->inbox(self::MUIRI)[0]['kind']);
        $this->assertLessThan(abs($ripple(self::FARKAS)), abs($ripple(self::MUIRI)), 'and weaker (enemy_mult)');

        // LAZY: not one of them has been touched
        foreach ($before as $npc => $aff) $this->assertSame($aff, $this->kit->coreAff($npc), "{$npc}: core affinity untouched until her next turn");
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) {
            $this->assertArrayNotHasKey(RelDynCascade::APPLIED_KEY, $this->kit->dynamics($npc), $npc);
        }

        // Farkas's next turn: the ripple lands in core's Player.aff, through his own curve, and he says so once
        $this->kit->turn(self::FARKAS, 'Well met, Farkas.', $this->t(2), 'hear');
        $farkasAff = $this->kit->coreAff(self::FARKAS);
        $this->assertLessThan($before[self::FARKAS], $farkasAff, 'Farkas now holds less for the player in core');
        $this->assertSame([], $this->inbox(self::FARKAS), 'the inbox was trimmed after the save');
        $d = $this->kit->dynamics(self::FARKAS);
        $this->assertCount(1, $d[RelDynCascade::APPLIED_KEY], 'the ripple is remembered by id');
        $this->assertSame($farkasAff, intval(round(RelationshipDynamics::getCoreAffinity($d))), 'the mirror holds what core holds');
        $this->assertArrayHasKey('cascade_ally_hurt', $this->kit->felt[self::FARKAS]['hear'], json_encode($this->kit->felt[self::FARKAS]['hear']));
        $line = $this->kit->felt[self::FARKAS]['hear']['cascade_ally_hurt'];
        $this->assertStringContainsString(self::AELA, $line);
        $this->assertStringContainsString('mocked her hunt', $line);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line, 'feelings, never numbers');
        // said once
        $this->kit->turn(self::FARKAS, 'Anything to drink?', $this->t(3), 'again');
        $this->assertArrayNotHasKey('cascade_ally_hurt', $this->kit->felt[self::FARKAS]['again']);
        $this->assertSame($farkasAff, $this->kit->coreAff(self::FARKAS), 'and nothing is applied twice');

        $this->assertClean();
    }

    public function testTheSameNewsLandsDifferentlyOnEachOfThemByWhoTheyAre(): void
    {
        $this->world();
        $this->scoreInsult();
        $before = [self::FARKAS => 40, self::LYNLY => 40, self::ASHE => 40, self::MUIRI => 40];
        $this->insultAela($this->t(0));
        $raw = [];
        foreach (array_keys($before) as $npc) $raw[$npc] = floatval($this->inbox($npc)[0]['delta']);
        foreach (array_keys($before) as $i => $npc) $this->kit->turn($npc, 'Good evening.', $this->t(2 + $i), 'hear');

        $moved = [];
        foreach ($before as $npc => $aff) {
            $d = $this->kit->dynamics($npc);
            // whole points to core, the fraction waits in her pending delta: what she heard is both
            $moved[$npc] = round($this->kit->coreAff($npc) - $aff + floatval($d['_pending_aff_delta'] ?? 0), 3);
            $this->assertCount(1, $d[RelDynCascade::APPLIED_KEY], $npc);
            $this->assertSame([], $this->inbox($npc), $npc);
        }
        $this->probe('moved', ['raw' => $raw, 'moved' => $moved]);
        foreach ([self::FARKAS, self::LYNLY, self::ASHE] as $npc) {
            $this->assertLessThan(0, $moved[$npc], "{$npc} (an ally of hers) thinks less of the player: " . json_encode($moved));
            $this->assertArrayHasKey('cascade_ally_hurt', $this->kit->felt[$npc]['hear'], $npc);
        }
        $this->assertGreaterThan(0, $moved[self::MUIRI], 'Muiri (her enemy) is not sorry: ' . json_encode($moved));
        $this->assertArrayHasKey('cascade_rival_hurt', $this->kit->felt[self::MUIRI]['hear']);
        $this->assertStringContainsString('not sorry', $this->kit->felt[self::MUIRI]['hear']['cascade_rival_hurt']);
        // Three allies, three different bonds and curves, three different drops; the same word through each one's curve
        $this->assertCount(3, array_unique([$moved[self::FARKAS], $moved[self::LYNLY], $moved[self::ASHE]]), json_encode($moved));
        $kept = fn(string $npc) => $moved[$npc] / $raw[$npc];
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) {
            $this->assertGreaterThan(0.0, $kept($npc), "{$npc}: the curve damps, never flips");
            $this->assertLessThan(1.0, $kept($npc), "{$npc}: a rumour is never louder than the news");
        }
        $this->assertLessThan($kept(self::LYNLY) - 0.05, $kept(self::ASHE), 'Ashe (guarded, stoic-leaning) takes the same word in less than Lynly (the open-hearted bard)');
        $this->assertClean();
    }

    public function testASmallChangeDoesNotRipple(): void
    {
        $this->world();
        $this->scoreInsult(['affinity' => -3], 0.2);
        $this->insultAela($this->t(0));
        $this->assertLessThan(30, $this->kit->coreAff(self::AELA), 'she did feel it');
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertSame([], $this->inbox($npc), $npc);
        $this->assertClean();
    }

    public function testADefiningMomentRipplesFromAMuchSmallerChange(): void
    {
        $this->world();
        // a loss under the ordinary threshold, at significance 0.9: the moment people retell
        $this->scoreInsult(['affinity' => -14], 0.9);
        $this->insultAela($this->t(0));
        $lost = 30 - $this->kit->coreAff(self::AELA);
        $this->assertGreaterThanOrEqual(5, $lost);
        $this->assertLessThan(15, $lost, 'under the ordinary threshold');
        $this->assertCount(1, $this->inbox(self::FARKAS), 'but a defining moment carries it');
        $this->assertTrue($this->inbox(self::FARKAS)[0]['defining']);
        $this->assertClean();
    }

    public function testTheSameLossOfAnOrdinaryExchangeStaysWithHer(): void
    {
        $this->world();
        $this->scoreInsult(['affinity' => -14], 0.3);   // the same loss, an everyday exchange
        $this->insultAela($this->t(0));
        $this->assertGreaterThanOrEqual(5, 30 - $this->kit->coreAff(self::AELA));
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertSame([], $this->inbox($npc), $npc);
        $this->assertClean();
    }

    public function testARescueRipplesWarmthToWhoLovesHer(): void
    {
        $this->world();
        $rescue = fn(string $e) => str_contains($e, 'burning barn')
            ? ['signals' => ['affinity' => 30, 'trust' => 6], 'tags' => ['rescue', 'help'], 'significance' => 0.6, 'summary' => 'The player pulled her out of the fire.']
            : null;
        $this->kit->evalReply = $rescue;
        $this->kit->turn(self::AELA, 'I pulled you out of the burning barn, remember?', $this->t(0), 'rescue');
        $this->kit->worker();
        $gain = $this->kit->coreAff(self::AELA) - 30;
        $this->probe('rescue', ['gain' => $gain, 'inboxes' => array_map(fn($n) => $this->inbox($n), [self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI])]);
        $this->assertGreaterThanOrEqual(5, $gain, 'the rescue moved her');
        // a rescue is a defining moment (significance 0.6 >= 0.4): the two who love her best hear it; the rest of the
        // ripple is under a point and not worth carrying
        $this->assertCount(1, $this->inbox(self::FARKAS));
        $this->assertSame('ally_helped', $this->inbox(self::FARKAS)[0]['kind']);
        $this->assertTrue($this->inbox(self::FARKAS)[0]['defining']);
        $this->assertCount(1, $this->inbox(self::LYNLY));
        $this->assertSame([], $this->inbox(self::MUIRI), 'the inverted ripple of an enemy at this size is under a point');
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $this->assertGreaterThan(40, $this->kit->coreAff(self::FARKAS), 'Farkas thinks better of the player');
        $this->assertStringContainsString('thinks better', $this->kit->felt[self::FARKAS]['hear']['cascade_ally_helped']);
        $this->assertStringContainsString('pulled her out of the fire', $this->kit->felt[self::FARKAS]['hear']['cascade_ally_helped']);
        $this->assertClean();
    }

    public function testARippleQueuedTwiceLandsOnce(): void
    {
        $this->world();
        $this->scoreInsult();
        $this->insultAela($this->t(0));
        $item = $this->inbox(self::FARKAS)[0];
        // a crash between queueing and the source's save: the same ripple is appended again
        RelDynStorage::appendItem($this->kit->id(self::FARKAS), RelDynCascade::INBOX_KEY, $item);
        $this->assertCount(2, $this->inbox(self::FARKAS));
        $reference = $this->kit->coreAff(self::FARKAS);
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $once = $this->kit->coreAff(self::FARKAS);
        $this->assertLessThan($reference, $once);
        $this->assertCount(1, $this->kit->dynamics(self::FARKAS)[RelDynCascade::APPLIED_KEY], 'one id');
        $this->assertSame([], $this->inbox(self::FARKAS));
        $this->assertClean();
    }

    public function testWithTheNetworkOffNothingIsQueuedAndNothingPendingIsApplied(): void
    {
        $this->world(['cascade_network_enabled' => false]);
        $this->scoreInsult();
        $this->insultAela($this->t(0));
        foreach ([self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $npc) $this->assertSame([], $this->inbox($npc), $npc);
        $this->assertArrayNotHasKey(RelDynCascade::OUT_KEY, $this->kit->dynamics(self::AELA), 'nothing is held back to ripple later');
        // a ripple already waiting (queued while it was on) is not applied while it is off
        RelDynStorage::appendItem($this->kit->id(self::FARKAS), RelDynCascade::INBOX_KEY,
            ['id' => 'abc', 'source' => self::AELA, 'delta' => -5.0, 'kind' => 'ally_hurt', 'gamets' => 1.0, 'anchor' => null, 'defining' => false]);
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $this->assertSame(40, $this->kit->coreAff(self::FARKAS));
        $this->assertCount(1, $this->inbox(self::FARKAS), 'it waits');
        $this->assertClean();
    }

    public function testAnEditorLockedNpcKeepsWhatTheEditorPinned(): void
    {
        $this->world();
        $this->scoreInsult();
        pg_query_params($this->kit->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships_locked}', 'true'::jsonb) WHERE npc_name = \$1", [self::FARKAS]);
        $this->insultAela($this->t(0));
        $this->kit->turn(self::FARKAS, 'Well met.', $this->t(2), 'hear');
        $this->assertSame(40, $this->kit->coreAff(self::FARKAS), 'manual edits are protected, ripple or not');
        $this->assertSame([], $this->inbox(self::FARKAS), 'and it does not come back');
        $this->assertClean();
    }
}
