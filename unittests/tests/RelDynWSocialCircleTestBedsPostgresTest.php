<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_editor.php';

/**
 * Cascade extensions (roadmap cascade-extensions; Ken 2026-10-01 §24), end to end on a real PostgreSQL with the four test
 * beds (Aela the Huntress, Ashe (Serene's hand-set vector, never read, nothing of her story anywhere), Muiri (toxic) and
 * Lynly Star-Sung (a bard; the bio does not establish shyness, so none is forced)) plus Farkas, Aela's Companions brother,
 * through the real hooks (prerequest -> context_pre -> context -> postrequest), CHIM 3.4.1 core-shaped rows. No LLM call.
 *
 * Farkas is close to the player (his affinity toward the player is 70). Everyone has a bond to him:
 *   - FRIEND OF A FRIEND. Aela (70) and Lynly (45) are fond of him, Muiri dislikes him (-50), Ashe barely knows him (10).
 *     With each one's own turn with the player, Aela and Lynly warm to the player, Muiri cools, Ashe does not move: a
 *     bond has to be worth something. Each by who they are (how easily they are swayed), by how well they would know of
 *     the tie. Nothing is written into any other NPC's row. It moves with the tie (Farkas falls out with the player and the
 *     warmth turns to coolness, what was given is given back exactly) and the standing line names him in feelings.
 *   - THE NAME LOOKUP. The player names Farkas to someone, who is not here: Lynly's fondness and Muiri's dislike colour
 *     the answer, once; Ashe has no feeling about him worth a line; with Farkas in the room it is not "not here". The
 *     tie is known at once to whoever heard the name.
 *   - LOVE TRIANGLES. Aela and Lynly both want the player (their interest is set before each turn: the real attraction
 *     matrix is not what this test is about): each cools toward the other at their own turn, by who they are, and the
 *     cooling is given back when the interest goes.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynWSocialCircleTestBedsPostgresTest extends TestCase
{
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const BEDS = [self::AELA, self::LYNLY, self::ASHE, self::MUIRI];
    private const HOUR = RelDynNetworkBedsKit::HOUR;

    private ?RelDynNetworkBedsKit $kit = null;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
    }

    protected function tearDown(): void
    {
        if ($this->kit !== null) $this->kit->destroy();
        $this->kit = null;
    }

    private function t(float $hours): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $hours);
    }

    /**
     * The world: Farkas close to the player; Aela and Lynly fond of Farkas, Muiri against him, Ashe barely knows him; Aela and
     * Lynly know each other (a fair bond). Everyone was last seen in Breezehome, Whiterun (the hold graph reads the same place).
     */
    private function world(array $config = [], array $overrides = []): RelDynNetworkBedsKit
    {
        $this->kit = $kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'wcircle', $config);
        $rels = [
            self::AELA => ['Player' => [30, 'platonic'], self::FARKAS => [70, 'friend'], self::LYNLY => [20, 'neutral'], self::MUIRI => [15, 'neutral']],
            self::FARKAS => ['Player' => [70, 'friend'], self::AELA => [70, 'friend']],
            self::LYNLY => ['Player' => [25, 'neutral'], self::FARKAS => [45, 'friend'], self::AELA => [50, 'friend']],
            self::ASHE => ['Player' => [25, 'neutral'], self::FARKAS => [10, 'neutral']],
            self::MUIRI => ['Player' => [25, 'neutral'], self::FARKAS => [-50, 'rival'], self::AELA => [15, 'neutral'], self::LYNLY => [15, 'neutral']],
        ];
        foreach ($overrides as $npc => $map) $rels[$npc] = array_replace($rels[$npc] ?? [], $map);
        $kit->seed($rels);
        foreach ([self::AELA, self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI] as $n) {
            pg_query_params($kit->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
                VALUES ('infonpc_close', 'idle', 'pending', \$1, \$2, \$1, \$3, \$4)",
                [$this->t(-3.0), $kit->realTs, "|{$n}|" . RelDynNetworkBedsKit::PLAYER . '|', RelDynNetworkBedsKit::HOME]);
        }
        return $kit;
    }

    /** The player's word to each bed in turn (the first meeting of the evening), then the same again $later hours on. */
    private function round(RelDynNetworkBedsKit $kit, float $hours, string $line = 'Good evening.', array $npcs = self::BEDS, string $label = 'r'): void
    {
        foreach ($npcs as $i => $npc) $kit->turn($npc, $line, $this->t($hours) + $i * 600000, $label);
    }

    /** Her regard for the player as the engine holds it: core's affinity and what waits to be committed. */
    private function moved(RelDynNetworkBedsKit $kit, string $npc): float
    {
        return $kit->coreAff($npc) + floatval($kit->dynamics($npc)['_pending_aff_delta'] ?? 0.0);
    }

    private function circle(RelDynNetworkBedsKit $kit, string $npc): array
    {
        return $kit->dynamics($npc)['_circle'] ?? [];
    }

    private function applied(RelDynNetworkBedsKit $kit, string $npc): float
    {
        return floatval($this->circle($kit, $npc)['assoc']['applied'] ?? 0.0);
    }

    private function interested(RelDynNetworkBedsKit $kit, string $npc, float $passion): void
    {
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(jsonb_set(plugin_extended_data, '{reldyn,dynamics,_attraction}',
            '{\"enabled\":true,\"attracted\":true,\"hard_zero\":false}'::jsonb), '{reldyn,dynamics,dimensions,passion,x}', to_jsonb(\$2::float8)) WHERE npc_name = \$1", [$npc, $passion]);
    }

    /** What a request of someone else must not change in $npc's row: their bonds in core, their circle and their cascade inbox. */
    private function rowHash(RelDynNetworkBedsKit $kit, string $npc): string
    {
        $r = pg_fetch_assoc(pg_query_params($kit->db->link, "SELECT md5(coalesce(extended_data::text, '') || coalesce((plugin_extended_data #> '{reldyn,dynamics,_circle}')::text, '')
            || coalesce((plugin_extended_data #> '{reldyn,cascade_inbox}')::text, '')) AS h FROM core_npc_master WHERE npc_name = \$1", [$npc]));
        return (string) $r['h'];
    }

    private function assertClean(RelDynNetworkBedsKit $kit): void
    {
        $this->assertSame([], $kit->db->failures, 'failed SQL statements');
        $this->assertSame(0, $kit->llmCalls, 'no trait read');
        $this->assertStringNotContainsString('ERROR', $kit->errorLog(), $kit->errorLog());
    }

    // ------------------------------------------------------------------ friend of a friend

    public function testAFriendOfSomeoneCloseToThePlayerWarmsAndAFoeCoolsEachByWhoTheyAre(): void
    {
        $on = $this->world();
        $this->round($on, 0.0);
        $links = fn(string $npc) => array_column($this->circle($on, $npc)['assoc']['links'] ?? [], null, 'name');
        // Aela (70) and Lynly (45) are fond of Farkas, who is close to the player: they warm
        $this->assertSame('ally_close', $links(self::AELA)[self::FARKAS]['kind']);
        $this->assertSame('ally_close', $links(self::LYNLY)[self::FARKAS]['kind']);
        $this->assertGreaterThan(0.5, $this->applied($on, self::AELA));
        $this->assertGreaterThan(0.5, $this->applied($on, self::LYNLY));
        // Muiri dislikes him (-50): she cools, by the inverted reading, which is the weaker
        $this->assertSame('rival_close', $links(self::MUIRI)[self::FARKAS]['kind']);
        $this->assertLessThan(-0.2, $this->applied($on, self::MUIRI));
        // Ashe barely knows him (10, under the bar): nothing, whoever he is to the player
        $this->assertSame([], $this->circle($on, self::ASHE)['assoc']['links']);
        $this->assertSame(0.0, $this->applied($on, self::ASHE));
        // it reaches core as the cascade's queued delta: against a world where the extensions are off, the difference is the reading
        $off = $this->world(['cascade_ext' => ['enabled' => false]]);
        $this->round($off, 0.0);
        foreach (self::BEDS as $npc) {
            $this->assertArrayNotHasKey('_circle', $off->dynamics($npc), "{$npc}: off means no state");
            $this->assertEqualsWithDelta($this->applied($on, $npc), $this->moved($on, $npc) - $this->moved($off, $npc), 0.01, "{$npc}: what core and the queue hold is what was applied");
        }
        $this->assertGreaterThanOrEqual(1, $on->coreAff(self::AELA) - $off->coreAff(self::AELA), 'whole points reached core for Aela');
        $this->assertSame($on->coreAff(self::ASHE), $off->coreAff(self::ASHE));
        // who they are: the same fondness is not the same sway, and they differ (susceptibility, knowledge by the bond)
        $s = [];
        foreach (self::BEDS as $npc) $s[$npc] = $this->circle($on, $npc)['assoc']['susceptibility'];
        foreach (self::BEDS as $a) {
            foreach (self::BEDS as $b) {
                if ($a < $b) $this->assertGreaterThan(0.03, abs($s[$a] - $s[$b]), "{$a} and {$b} are swayed differently");
            }
        }
        // ... and how likely they are to know of the tie follows how close a friend of Farkas's they are (70 against 45)
        $this->assertGreaterThan($links(self::LYNLY)[self::FARKAS]['knowledge'] + 0.05, $links(self::AELA)[self::FARKAS]['knowledge']);
        $this->assertGreaterThan($links(self::AELA)[self::FARKAS]['lean'], 4.2, 'a bond of 70 to someone at 70 reads at most 14 x 0.49 x 0.6');
        // nobody passes the cap, however they are made
        foreach (self::BEDS as $npc) $this->assertLessThanOrEqual(12.0 * 1.6 + 1e-9, abs($this->circle($on, $npc)['assoc']['target']));
        $this->assertClean($on);
        $this->assertClean($off);
    }

    public function testItSettlesOnTheGameCalendarAndIsSaidInFeelingsOnceItMatters(): void
    {
        $kit = $this->world();
        $this->round($kit, 0.0, 'Good evening.', [self::AELA, self::LYNLY], 'first');
        $first = $this->applied($kit, self::AELA);
        $target = $this->circle($kit, self::AELA)['assoc']['target'];
        $this->assertLessThan($target, $first, 'a first reading is partway');
        $this->assertArrayNotHasKey('circle_association', $kit->felt[self::AELA]['first'], 'under two points there is nothing to say yet');
        $this->round($kit, 7.0, 'Good evening again.', [self::AELA, self::LYNLY], 'second');
        $second = $this->applied($kit, self::AELA);
        $this->assertGreaterThan($first, $second, 'it settles toward the target as game time passes');
        $this->assertLessThan($target + 0.001, $second);
        $this->assertEqualsWithDelta($target * (1 - exp(-0.15 * 7.0)) + $first * exp(-0.15 * 7.0), $second, 0.05, 'by the rate: this share of the gap per game hour');
        $line = $kit->felt[self::AELA]['second']['circle_association'] ?? '';
        $this->assertStringContainsString('Farkas speaks well of Kaida, and Aela the Huntress trusts Farkas', $line);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line, 'feelings, never numbers');
        $this->assertClean($kit);
    }

    public function testItMovesWithTheTieAndWhatWasAppliedIsGivenBackExactly(): void
    {
        $kit = $this->world(['cascade_ext' => ['association' => ['felt' => ['from' => 0.5]]]]);
        $this->round($kit, 0.0, 'Good evening.', [self::AELA], 'a1');
        $before = $this->moved($kit, self::AELA);
        $warm = $this->applied($kit, self::AELA);
        $this->assertGreaterThan(0.5, $warm);
        // Farkas falls out with the player (his own bond to the player is a fact core holds), and days go by
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships,Player,aff}', to_jsonb(-60::int)) WHERE npc_name = \$1", [self::FARKAS]);
        foreach ([1, 2, 3, 4] as $day) $this->round($kit, 24.0 * $day, 'Good evening.', [self::AELA], "d{$day}");
        $link = $this->circle($kit, self::AELA)['assoc']['links'][0];
        $this->assertSame('ally_foe', $link['kind'], 'a friend of someone at odds with the player');
        $cool = $this->applied($kit, self::AELA);
        $this->assertLessThan(-0.5, $cool);
        $this->assertEqualsWithDelta($this->circle($kit, self::AELA)['assoc']['target'], $cool, 0.05, 'it has settled');
        // the ledger: what core and the queue hold has moved by exactly what the reading went through, nothing lost or twice
        $this->assertEqualsWithDelta($cool - $warm, $this->moved($kit, self::AELA) - $before, 0.01);
        $this->assertStringContainsString('has no use for Kaida', $kit->felt[self::AELA]['d4']['circle_association'] ?? '', 'and the words follow');
        $this->assertClean($kit);
    }

    public function testAWholeNPCResetKeepsTheLedgerOfWhatTheCircleWroteIntoCoreSoNothingIsAppliedTwice(): void
    {
        $kit = $this->world();
        $this->round($kit, 0.0, 'Good evening.', [self::AELA], 'a');
        $this->round($kit, 7.0, 'Good evening again.', [self::AELA], 'b');
        $applied = $this->applied($kit, self::AELA);
        $this->assertGreaterThan(1.5, $applied);
        $before = $this->moved($kit, self::AELA);
        // the editor's fresh start: RelDyn's state starts over, core's numbers stay where they are
        $session = [];
        $r = RelDynEditor::handle('POST', [], ['npc' => self::AELA, 'op' => 'reset_npc', 'confirm' => 'yes', 'csrf_token' => RelDynEditor::csrfToken($session)], $session);
        $this->assertSame(303, $r['status']);
        $this->assertTrue($session[RelDynEditor::FLASH_SESSION_KEY]['ok'], json_encode($session));
        $this->assertEqualsWithDelta($applied, $this->applied($kit, self::AELA), 1e-6, 'the ledger of what core holds survives the reset');
        $this->assertSame([], $this->circle($kit, self::AELA)['assoc']['links'] ?? [], 'everything else of the circle starts over');
        // her next turn reads the tie again and settles toward it from the ledger: core is not given the same points twice
        $this->round($kit, 31.0, 'Good evening, a day on.', [self::AELA], 'c');
        $target = $this->circle($kit, self::AELA)['assoc']['target'];
        $this->assertGreaterThanOrEqual($applied - 1e-6, $this->applied($kit, self::AELA), 'on its way to the target from where the ledger had it');
        $this->assertLessThanOrEqual($target + 1e-6, $this->applied($kit, self::AELA));
        $this->assertEqualsWithDelta($this->applied($kit, self::AELA) - $applied, $this->moved($kit, self::AELA) - $before, 0.05,
            'what moved is only the difference, not the reading applied again');
        $this->assertClean($kit);
    }

    public function testNothingIsWrittenIntoAnyOtherNPCsRowFromARequestThatIsNotTheirs(): void
    {
        $kit = $this->world();
        $others = [self::FARKAS, self::LYNLY, self::ASHE, self::MUIRI];
        $this->round($kit, 0.0, 'Good evening.', [self::AELA, self::LYNLY], 'meet');
        $this->interested($kit, self::AELA, 70.0);
        $this->interested($kit, self::LYNLY, 50.0);
        $hash = [];
        foreach ($others as $n) $hash[$n] = $this->rowHash($kit, $n);
        $kit->turn(self::AELA, 'Good evening again.', $this->t(7.0), 'aela');   // reads Farkas, Lynly, Muiri; warms, and a triangle with Lynly
        $this->assertNotEmpty($this->circle($kit, self::AELA)['assoc']['links']);
        $this->assertNotEmpty($this->circle($kit, self::AELA)['rivals'], 'the triangle is there to leave the others untouched');
        foreach ($others as $n) $this->assertSame($hash[$n], $this->rowHash($kit, $n), "{$n}'s row is untouched by Aela's request (lazy: no telepathy, nobody is told)");
        $this->assertClean($kit);
    }

    public function testWhoWasThereOrActuallyTalkedKnowsTheTieInFullAndTheRestGuess(): void
    {
        $guess = $this->world();
        $this->round($guess, 0.0, 'Good evening.', [self::LYNLY], 'a');
        $link = fn(RelDynNetworkBedsKit $k) => $this->circle($k, self::LYNLY)['assoc']['links'][0];
        $this->assertSame('guess', $link($guess)['via']);
        $this->assertLessThan(0.6, $link($guess)['knowledge'], 'the guess of the NPC, by bond and distance');
        $guess->destroy();
        $this->kit = null;
        // Farkas was right there a few minutes ago, in Lynly's own hold: she can see for herself
        $near = $this->world();
        pg_query_params($near->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
            VALUES ('infonpc_close', 'idle', 'pending', \$1, \$2, \$1, \$3, \$4)", [$this->t(-0.2), $near->realTs, '|' . self::FARKAS . '|' . RelDynNetworkBedsKit::PLAYER . '|', RelDynNetworkBedsKit::HOME]);
        $this->round($near, 0.0, 'Good evening.', [self::LYNLY], 'a');
        $this->assertSame('near', $link($near)['via']);
        $this->assertSame(1.0, floatval($link($near)['knowledge']));
        $near->destroy();
        $this->kit = null;
        // Farkas was in Winterhold a few minutes ago: not in her hold, so she cannot have seen (and the distance is the guess's)
        $far = $this->world();
        pg_query($far->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES ('Winterhold College', 'Winterhold', 'Mage,', 1, 'WinterholdWorld')");
        pg_query_params($far->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
            VALUES ('infonpc_close', 'idle', 'pending', \$1, \$2, \$1, \$3, 'Winterhold College')", [$this->t(-0.2), $far->realTs, '|' . self::FARKAS . '|' . RelDynNetworkBedsKit::PLAYER . '|']);
        $this->round($far, 0.0, 'Good evening.', [self::LYNLY], 'a');
        $this->assertSame('guess', $link($far)['via']);
        $this->assertLessThan(0.6, $link($far)['knowledge'], 'and two holds off: less than a neighbour\'s guess');
        $far->destroy();
        $this->kit = null;
        // Lynly and Farkas actually talked (a rechat between them): word of mouth, no clock needed
        $talk = $this->world();
        $this->round($talk, 0.0, 'Good evening.', [self::LYNLY], 'a');
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = self::FARKAS;
        $talk->request(self::LYNLY, ['rechat', (string) $talk->realTs, (string) $this->t(5.0), json_encode(['speaker' => self::FARKAS, 'text' => 'Did you hear?'])], self::FARKAS, 'talk');
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        $this->assertArrayHasKey('farkas', $this->circle($talk, self::LYNLY)['talks'], 'noted in her own circle');
        $this->assertSame(1, count($this->circle($talk, self::LYNLY)['talks']));
        $talk->turn(self::LYNLY, 'Good evening again.', $this->t(7.0), 'b');
        $this->assertSame('talked', $link($talk)['via']);
        $this->assertSame(1.0, floatval($link($talk)['knowledge']));
        // ... for a while: two days on, she is guessing again
        $talk->turn(self::LYNLY, 'Good evening still.', $this->t(7.0 + 24.0 * 3), 'c');
        $this->assertSame('guess', $link($talk)['via']);
        $this->assertClean($talk);
    }

    // ------------------------------------------------------------------ the name lookup

    public function testNamingSomeoneWhoIsNotHereColoursTheAnswerOnceByWhoTheNPCFeelsAboutThem(): void
    {
        $kit = $this->world();
        $this->round($kit, 0.0, 'Good evening.', self::BEDS, 'meet');
        $words = 'I met Farkas in Jorrvaskr today.';
        $kit->people = '|' . self::AELA . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        // Aela (fond: 70), Lynly (fond: 45), Muiri (-50) and Ashe (10: under the bar) are each told, and Farkas is not in the room
        $answers = [];
        foreach ([self::AELA, self::LYNLY, self::MUIRI, self::ASHE] as $i => $npc) {
            $kit->people = '|' . $npc . '|' . RelDynNetworkBedsKit::PLAYER . '|';
            $kit->turn($npc, $words, $this->t(1.0 + $i * 0.1), 'told');
            $answers[$npc] = $kit->felt[$npc]['told']['circle_mention'] ?? null;
        }
        $this->assertStringContainsString('Kaida brought up Farkas, who is not here; Aela the Huntress is fond of Farkas', (string) $answers[self::AELA]);
        $this->assertStringContainsString('Lynly Star-Sung is fond of Farkas', (string) $answers[self::LYNLY]);
        $this->assertStringContainsString('Muiri cannot stand Farkas, and the answer has an edge', (string) $answers[self::MUIRI]);
        $this->assertNull($answers[self::ASHE], 'Ashe has no feeling about Farkas worth a line');
        // the tie is known to whoever heard the name: knowledge 1 for Farkas, so the reading reads him in full
        foreach ([self::AELA, self::LYNLY, self::MUIRI] as $npc) {
            $link = $this->circle($kit, $npc)['assoc']['links'][0];
            $this->assertSame(self::FARKAS, $link['name'], $npc);
            $this->assertSame(1.0, floatval($link['knowledge']), "{$npc}: the player has just said so");
        }
        $this->assertSame([], $this->circle($kit, self::ASHE)['assoc']['links'], 'and a bond under the bar stays under it, named or not');
        // once: the same name again within the cooldown colours nothing, and later it does again
        $kit->people = '|' . self::AELA . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $kit->turn(self::AELA, $words, $this->t(1.5), 'again');
        $this->assertArrayNotHasKey('circle_mention', $kit->felt[self::AELA]['again']);
        $kit->turn(self::AELA, $words, $this->t(4.0), 'later');
        $this->assertArrayHasKey('circle_mention', $kit->felt[self::AELA]['later']);
        // with Farkas in the room he is not "not here"
        $kit->people = '|' . self::LYNLY . '|' . self::FARKAS . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $kit->turn(self::LYNLY, $words, $this->t(9.0), 'there');
        $this->assertArrayNotHasKey('circle_mention', $kit->felt[self::LYNLY]['there']);
        // someone the NPC has no bond to colours nothing
        $kit->people = '|' . self::AELA . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $kit->turn(self::AELA, 'I met Nazeem in Whiterun today.', $this->t(10.0), 'nazeem');
        $this->assertArrayNotHasKey('circle_mention', $kit->felt[self::AELA]['nazeem']);
        $this->assertClean($kit);
    }

    public function testTheNameBringsTheTieKnownAtOnceSoTheReadingIsFullerThanWithoutIt(): void
    {
        $with = $this->world();
        $this->round($with, 0.0, 'Good evening.', [self::LYNLY], 'a');
        $with->people = '|' . self::LYNLY . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $with->turn(self::LYNLY, 'I met Farkas in Jorrvaskr today.', $this->t(7.0), 'b');
        $named = $this->circle($with, self::LYNLY)['assoc']['target'];
        $with->destroy();
        $this->kit = null;
        $without = $this->world();
        $this->round($without, 0.0, 'Good evening.', [self::LYNLY], 'a');
        $without->people = '|' . self::LYNLY . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $without->turn(self::LYNLY, 'Good evening again.', $this->t(7.0), 'b');
        $unnamed = $this->circle($without, self::LYNLY)['assoc']['target'];
        $this->assertGreaterThan($unnamed + 0.5, $named, 'a name the player said makes the tie known: knowledge 1 against the NPC\'s guess');
        $this->assertClean($without);
    }

    // ------------------------------------------------------------------ love triangles

    public function testTwoWhoBothWantThePlayerCoolTowardEachOtherEachAtTheirOwnTurnAndItIsGivenBackWhenTheInterestGoes(): void
    {
        $kit = $this->world();
        $this->round($kit, 0.0, 'Good evening.', [self::AELA, self::LYNLY, self::ASHE], 'meet');
        $aelaToLynly = fn() => intval($kit->core(self::AELA, self::LYNLY)['aff']);
        $lynlyToAela = fn() => intval($kit->core(self::LYNLY, self::AELA)['aff']);
        $this->assertSame(20, $aelaToLynly());
        $this->assertSame(50, $lynlyToAela());
        // both want the player
        $this->interested($kit, self::AELA, 70.0);
        $this->interested($kit, self::LYNLY, 55.0);
        $kit->turn(self::AELA, 'Good evening again.', $this->t(7.0), 'a2');
        $rival = $this->circle($kit, self::AELA)['rivals'][self::LYNLY] ?? null;
        $this->assertNotNull($rival, 'Aela sees Lynly as a rival for the player');
        $this->assertGreaterThan(0.3, $rival['pressure']);
        $this->assertLessThan(-1.0, $rival['applied']);
        $this->assertLessThan(20, $aelaToLynly(), 'Aela is cooler toward Lynly (her own row)');
        $this->assertSame(50, $lynlyToAela(), 'but Lynly has not heard of it: her own turn is where her side happens');
        $this->assertStringContainsString('Lynly Star-Sung', $kit->felt[self::AELA]['a2']['circle_rivalry'] ?? '', 'and the words name the rival');
        $this->assertDoesNotMatchRegularExpression('/\d/', $kit->felt[self::AELA]['a2']['circle_rivalry'] ?? '');
        $this->interested($kit, self::AELA, 70.0);
        $kit->turn(self::LYNLY, 'Good evening again.', $this->t(7.2), 'l2');
        $this->assertLessThan(50, $lynlyToAela(), 'at Lynly\'s own turn she cools toward Aela');
        $this->assertNotNull($this->circle($kit, self::LYNLY)['rivals'][self::AELA] ?? null);
        // Ashe is not interested: no rival for her, and nothing toward her
        $kit->turn(self::ASHE, 'Good evening again.', $this->t(7.4), 'h2');
        $this->assertSame([], $this->circle($kit, self::ASHE)['rivals'] ?? []);
        // the cooling is bounded by the cap and by who Aela is: never past the cap at full pressure and full disposition
        $this->assertGreaterThanOrEqual(20 - 30 * 1.8, $aelaToLynly());
        // Lynly's interest goes (she is no longer drawn): at Aela's next turns the cooling is given back, and the rival leaves the ledger
        pg_query_params($kit->db->link, "UPDATE core_npc_master SET plugin_extended_data = jsonb_set(jsonb_set(plugin_extended_data, '{reldyn,dynamics,_attraction}',
            '{\"enabled\":true,\"attracted\":false,\"hard_zero\":false}'::jsonb), '{reldyn,dynamics,dimensions,passion,x}', to_jsonb(0::float8)) WHERE npc_name = \$1", [self::LYNLY]);
        $cooled = $aelaToLynly();
        foreach ([1, 2, 3, 4, 5, 6] as $day) {
            $this->interested($kit, self::AELA, 70.0);
            $kit->turn(self::AELA, 'Good evening again.', $this->t(7.0 + 24.0 * $day), "g{$day}");
        }
        $this->assertGreaterThan($cooled, $aelaToLynly(), 'given back');
        $this->assertGreaterThanOrEqual(19, $aelaToLynly(), 'to within a point of where it was');
        $this->assertArrayNotHasKey(self::LYNLY, $this->circle($kit, self::AELA)['rivals'] ?? [], 'a settled rivalry leaves the ledger');
        $this->assertArrayNotHasKey('circle_rivalry', $kit->felt[self::AELA]['g6']);
        $this->assertClean($kit);
    }

    public function testHowHardARivalryLandsFollowsWhoTheNPCIsAndNoOneEscapesIt(): void
    {
        $kit = $this->world();
        $this->round($kit, 0.0, 'Good evening.', [self::AELA, self::LYNLY, self::MUIRI], 'meet');
        foreach ([self::AELA => 70.0, self::LYNLY => 70.0, self::MUIRI => 70.0] as $npc => $passion) $this->interested($kit, $npc, $passion);
        foreach ([self::AELA, self::LYNLY, self::MUIRI] as $i => $npc) {
            $this->interested($kit, self::AELA, 70.0);
            $this->interested($kit, self::LYNLY, 70.0);
            $this->interested($kit, self::MUIRI, 70.0);
            $kit->turn($npc, 'Good evening again.', $this->t(7.0 + $i * 0.2), 'tri');
        }
        $disposition = [];
        foreach ([self::AELA, self::LYNLY, self::MUIRI] as $npc) {
            $c = $this->circle($kit, $npc);
            $this->assertNotEmpty($c['rivals'], "{$npc} has rivals for the player");
            $disposition[$npc] = floatval($c['rival_disposition']);
            $this->assertGreaterThan(0.0, $disposition[$npc], 'no one is immune');
            foreach ($c['rivals'] as $name => $r) {
                // each rival's target is the cap x the pressure x who this NPC is
                $this->assertEqualsWithDelta(-30.0 * $r['pressure'] * $disposition[$npc], $r['target'], 0.01, "{$npc} toward {$name}");
            }
        }
        $this->assertGreaterThan(0.015, abs($disposition[self::AELA] - $disposition[self::MUIRI]), 'Aela and Muiri feel a rival differently: ' . json_encode($disposition));
        $this->assertGreaterThan(0.015, abs($disposition[self::AELA] - $disposition[self::LYNLY]), json_encode($disposition));
        $this->assertClean($kit);
    }

    // ------------------------------------------------------------------ switches

    public function testEachExtensionHasItsOwnSwitch(): void
    {
        $kit = $this->world(['cascade_ext' => ['association' => ['enabled' => false], 'triangle' => ['enabled' => false], 'mention' => ['enabled' => false]]]);
        $this->round($kit, 0.0, 'Good evening.', [self::AELA, self::LYNLY], 'x');
        $this->interested($kit, self::AELA, 70.0);
        $this->interested($kit, self::LYNLY, 70.0);
        $kit->people = '|' . self::AELA . '|' . RelDynNetworkBedsKit::PLAYER . '|';
        $kit->turn(self::AELA, 'I met Farkas in Jorrvaskr today.', $this->t(7.0), 'y');
        $c = $this->circle($kit, self::AELA);
        $this->assertSame(0.0, floatval($c['assoc']['applied'] ?? 0.0));
        $this->assertSame([], $c['rivals'] ?? []);
        $this->assertSame([], $c['mentions'] ?? []);
        $this->assertArrayNotHasKey('circle_mention', $kit->felt[self::AELA]['y']);
        $this->assertArrayNotHasKey('circle_association', $kit->felt[self::AELA]['y']);
        $this->assertSame(20, intval($kit->core(self::AELA, self::LYNLY)['aff']), 'no rivalry');
        $this->assertClean($kit);
    }
}
