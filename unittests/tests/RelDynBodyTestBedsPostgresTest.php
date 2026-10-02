<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// the beds, the hooks and the eval worker (declared with the cascade test)
require_once __DIR__ . '/RelDynCascadeNetworkTestBedsPostgresTest.php';

/**
 * The body (roadmap bio-mimetic-feedback and oblush-physical-blush, Ken 2026-10-01 §24) end to end on a real PostgreSQL with
 * the four test beds (Aela the Huntress, Ashe (Serene's hand-set vector, never read, nothing of her story anywhere), Muiri
 * (toxic) and Lynly Star-Sung (her bio does not establish shyness: the shy mechanism is tested on a hand-set copy of her state)),
 * plus Farkas, a man, because RelDyn is for every character. Through the real hooks, the real eval producer and worker (the LLM
 * stubbed at the connector boundary), and the command channel's own table (responselog), nothing else faked:
 *
 *   - a hug the eval scores as a passion moment earns each of them a felt blush AND a blush command for the game, whose
 *     duration is the moment's, the love-language match's and who they are; the beds diverge, none is immune, and it is the same
 *     moment as the felt line;
 *   - a primary love-language gesture holds the blush longer than a secondary one;
 *   - a blush still on is not sent again for a smaller moment, one that ended waits out its cooldown, and one the game never
 *     confirmed is taken off once;
 *   - body language is felt text in front of the LLM, by who each of them is: a guarded NPC needs more passion to approach, a
 *     walkaway turns away, a conflict stands tense, a hand-set shy copy steals glances; and Jev gets the numbers and picks, and
 *     what it picks speaks, with the one core action (ComeCloser) coming back as a command line;
 *   - the voice is a mood from the NPC's own list, never forced unless Ken opts in.
 * No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBodyTestBedsPostgresTest extends TestCase
{
    private const K = RelDynNetworkBedsKit::class;
    private const BEDS = RelDynNetworkBedsKit::BEDS;
    private const AELA = RelDynNetworkBedsKit::AELA;
    private const ASHE = RelDynNetworkBedsKit::ASHE;
    private const MUIRI = RelDynNetworkBedsKit::MUIRI;
    private const LYNLY = RelDynNetworkBedsKit::LYNLY;
    private const FARKAS = RelDynNetworkBedsKit::FARKAS;
    private const HUG = 'Come here, let me hold you.';
    private const WORDS = 'You are the best thing in this whole place.';
    private const PER_SECOND = RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    /** love-language id => the eval tag that stands for it */
    private const TAG_OF = [
        RelationshipDynamics::LL_TOUCH => 'touch', RelationshipDynamics::LL_WORDS => 'praise', RelationshipDynamics::LL_TIME => 'quality_time',
        RelationshipDynamics::LL_SERVICE => 'help', RelationshipDynamics::LL_GIFTS => 'gift',
    ];

    private ?RelDynNetworkBedsKit $kit = null;
    /** @var array<string, array{0: string, 1: string, 2: int}> npc => [gesture tag, line, the eval passion signal] the stubbed eval scores as a moment */
    private array $gesture = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        $this->gesture = [];
        unset($GLOBALS['FORCE_MOOD'], $GLOBALS['EMOTEMOODS']);
    }

    protected function tearDown(): void
    {
        if ($this->kit !== null) $this->kit->destroy();
        $this->kit = null;
        unset($GLOBALS['FORCE_MOOD'], $GLOBALS['EMOTEMOODS']);
    }

    // ------------------------------------------------------------------ the world

    /**
     * The four beds (and Farkas) as the player's partners at the same earned floor, each having had a turn with the player.
     *
     * @param array $config RelDyn config sections to lay over the defaults
     * @param string[] $npcs who is in the world's conversation (default: the four beds)
     */
    private function world(array $config = [], array $npcs = self::BEDS, int $floor = 30): RelDynNetworkBedsKit
    {
        $this->kit = new RelDynNetworkBedsKit((string) getenv('RELDYN_TEST_PG_DSN'), 'body', $config);
        $kit = $this->kit;
        $rels = [];
        foreach ($npcs as $npc) $rels[$npc] = ['Player' => [60, 'romantic']];
        $kit->seed($rels);
        $kit->event('infoloc', RelDynNetworkBedsKit::HOME, RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 17.9));
        foreach ($npcs as $i => $npc) $kit->turn($npc, 'Good evening.', RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, 18.0 + $i * 0.1), 'hello');
        foreach ($npcs as $npc) $this->edit($npc, function (array &$d) use ($floor): void { RelationshipDynamics::setPassion($d, (float) $floor); });
        $kit->evalReply = function (string $exchange): ?array {
            // the line the player said is unique to the NPC it was said to
            foreach ($this->gesture as [$tag, $line, $passion]) {
                if (str_contains($exchange, $line)) return ['signals' => ['passion' => $passion], 'tags' => $tag === '' ? [] : [$tag], 'significance' => 0.3, 'romantic_intent' => 1, 'summary' => 'A moment.'];
            }
            return null;
        };
        return $kit;
    }

    /** Edit $npc's stored RelDyn state the way the NPC editor would (through RelDyn's own load and save). */
    private function edit(string $npc, callable $change): void
    {
        $d = RelationshipDynamics::getDynamics($npc);
        $change($d);
        $this->assertTrue(RelationshipDynamics::saveDynamics($npc, $d), "{$npc}: the edit was saved");
        RelationshipDynamics::endRequest();
    }

    private function state(string $npc): array
    {
        $d = RelationshipDynamics::getDynamics($npc);
        RelationshipDynamics::endRequest();
        return $d;
    }

    /** responselog rows (the command channel) in the order they were queued: [npc, action]. */
    private function rows(): array
    {
        $out = [];
        $res = pg_query($this->kit->db->link, 'SELECT actor, action, sent FROM responselog ORDER BY ctid');
        while ($r = pg_fetch_assoc($res)) $out[] = [$r['actor'], $r['action'], (int) $r['sent']];
        return $out;
    }

    /** @return int[] the blush durations (seconds) queued for $npc, in order */
    private function blushes(string $npc): array
    {
        $out = [];
        foreach ($this->rows() as [$actor, $action]) {
            if ($actor === $npc && preg_match('/^command\|ExtCmdRelDynBody_Blush@(\d+)@\d+$/', $action, $m)) $out[] = (int) $m[1];
        }
        return $out;
    }

    private function at(float $hour): int
    {
        return RelDynNetworkBedsKit::at(RelDynNetworkBedsKit::N0, $hour);
    }

    /** The player makes $gesture to each of $npcs (the eval scores it a moment), the worker applies it, then one more word each. */
    private function moment(array $npcs, array $gestures, float $hour = 19.0): void
    {
        $kit = $this->kit;
        foreach ($gestures as $npc => $g) $gestures[$npc] = $g + [2 => 2];
        $this->gesture = $gestures;
        foreach ($npcs as $i => $npc) $kit->turn($npc, $gestures[$npc][1], $this->at($hour + $i * 0.05), 'moment');
        $kit->worker();
        foreach ($npcs as $i => $npc) $kit->turn($npc, 'You are lovely.', $this->at($hour + 0.5 + $i * 0.05), 'after');
    }

    private function hugAll(array $npcs = self::BEDS): void
    {
        $g = [];
        foreach ($npcs as $npc) $g[$npc] = ['touch', self::HUG . ' ' . $npc . '.'];
        $this->moment($npcs, $g);
    }

    private function assertClean(): void
    {
        $this->assertSame([], $this->kit->db->failures, 'no failed query');
        $this->assertSame(0, $this->kit->llmCalls, 'no LLM call');
        $this->assertStringNotContainsString('ERROR', $this->kit->errorLog());
    }

    // ------------------------------------------------------------------ the blush

    public function testAHugThatLandsPutsAFeltBlushAndABlushCommandOnEachBedByWhoTheyAre(): void
    {
        $kit = $this->world();
        $this->hugAll();
        $secs = [];
        foreach (self::BEDS as $npc) {
            $felt = $kit->felt[$npc]['after'] ?? [];
            $b = $this->blushes($npc);
            $this->assertCount(1, $b, "{$npc}: exactly one blush command, no flicker " . json_encode($this->rows()));
            $this->assertArrayHasKey('blush', $felt, "{$npc}: the felt blush line and the command are the same moment");
            $d = $this->state($npc);
            $blush = $d['_body']['blush'];
            $this->assertSame(1, $blush['token'], $npc);
            $this->assertGreaterThanOrEqual(10, $b[0], $npc);
            $this->assertLessThanOrEqual(300, $b[0], $npc);
            $this->assertEqualsWithDelta($blush['seconds'], $b[0], 0.51, "{$npc}: what was sent is what the state holds");
            // the duration is the moment's, the match's and who she is, nothing else
            $expected = RelDynBody::blushSeconds($d, (float) $blush['delta'], (float) $blush['mult']);
            $this->assertEqualsWithDelta($expected, $blush['seconds'], 0.11, $npc);
            $this->assertEqualsWithDelta($d['_accumulated_play_gamets'], $blush['start_play'] + 0.0, 8 * 90 * self::PER_SECOND, 'a blush on the play clock');
            $this->assertSame([], $d['_body']['outbox'], "{$npc}: sent once");
            $secs[$npc] = $b[0];
            $this->assertGreaterThan(0.6, RelDynBody::blushiness($d), "{$npc}: nobody is immune");
        }
        $this->assertGreaterThan(1, count(array_unique($secs)), 'the same hug, different blushes ' . json_encode($secs));
        foreach ($this->rows() as [$actor, $action, $sent]) {
            $this->assertSame(0, $sent, 'waiting for the game to poll it');
            $this->assertMatchesRegularExpression('/^command\|ExtCmdRelDynBody_Blush@\d+@1$/', $action);
        }
        $this->assertClean();
    }

    public function testAPrimaryLoveLanguageHoldsTheBlushLongerThanASecondaryOne(): void
    {
        $result = [];
        foreach (['primary', 'secondary'] as $which) {
            $kit = $this->world();
            $gestures = [];
            foreach (self::BEDS as $npc) {
                $d = $this->state($npc);
                $ll = $d['love_language_' . $which];
                $gestures[$npc] = [self::TAG_OF[$ll], 'Let me show you what you mean to me, ' . $npc . ', ' . $which . '.'];
            }
            $this->moment(self::BEDS, $gestures);
            foreach (self::BEDS as $npc) {
                $d = $this->state($npc);
                $result[$which][$npc] = ['seconds' => $this->blushes($npc)[0] ?? 0, 'mult' => (float) ($d['_body']['blush']['mult'] ?? 0), 'delta' => (float) ($d['_body']['blush']['delta'] ?? 0)];
            }
            $this->assertClean();
            $kit->destroy();
            $this->kit = null;
        }
        foreach (self::BEDS as $npc) {
            $p = $result['primary'][$npc];
            $s = $result['secondary'][$npc];
            $this->assertEqualsWithDelta(2.0, $p['mult'], 1e-9, "{$npc}: a primary match doubles");
            $this->assertEqualsWithDelta(1.5, $s['mult'], 1e-9, "{$npc}: a secondary one adds half");
            $this->assertGreaterThan($s['seconds'], $p['seconds'], "{$npc}: primary holds longer " . json_encode($result));
        }
    }

    public function testABlushStillOnIsNotSentAgainForASmallerMomentAndOneTheGameNeverConfirmedIsTakenOffOnce(): void
    {
        $kit = $this->world();
        $this->hugAll([self::AELA]);
        $this->assertCount(1, $this->blushes(self::AELA));
        // a gentler gesture a turn later: she is still blushing enough, nothing more is sent
        $this->gesture = [self::AELA => ['quality_time', 'Sit with me a while, Aela.', 2]];
        $kit->turn(self::AELA, 'Sit with me a while, Aela.', $this->at(20.0), 'time');
        $kit->worker();
        $kit->turn(self::AELA, 'It is a quiet night.', $this->at(20.1), 'quiet');
        $this->assertCount(1, $this->blushes(self::AELA), 'held, not flickered');
        // the game never said the blush ended (a reloaded save): well past the end, the next request takes it off, once
        $this->edit(self::AELA, function (array &$d): void { $d['_accumulated_play_gamets'] = $d['_body']['blush']['until_play'] + 40 * self::PER_SECOND; });
        $kit->turn(self::AELA, 'Still here?', $this->at(20.2), 'later');
        $off = array_values(array_filter($this->rows(), fn($r) => $r[0] === self::AELA && str_starts_with($r[1], 'command|ExtCmdRelDynBody_BlushOff@')));
        $this->assertCount(1, $off, json_encode($this->rows()));
        $this->assertSame('command|ExtCmdRelDynBody_BlushOff@1', $off[0][1], 'the token of the blush it takes off');
        $kit->turn(self::AELA, 'Hm.', $this->at(20.3), 'again');
        $this->assertCount(1, array_filter($this->rows(), fn($r) => str_contains($r[1], 'BlushOff')), 'once');
        // and a blush that just ended waits out its cooldown before the next
        $d = $this->state(self::AELA);
        $d['_accumulated_play_gamets'] = $d['_body']['blush']['until_play'] + 5 * self::PER_SECOND;
        $this->assertSame('cooldown', RelDynBody::onBlushMoment(self::AELA, $d, 9.0, 2.0)['action']);
        $d['_accumulated_play_gamets'] = $d['_body']['blush']['until_play'] + 60 * self::PER_SECOND;
        $this->assertSame('start', RelDynBody::onBlushMoment(self::AELA, $d, 9.0, 2.0)['action']);
        $this->assertClean();
    }

    public function testAHandSetShyCopyOfLynlyBlushesLongerThanLynlyAsSheStands(): void
    {
        $seconds = [];
        foreach (['stock', 'shy copy'] as $variant) {
            $kit = $this->world([], [self::LYNLY]);
            if ($variant === 'shy copy') {
                $this->edit(self::LYNLY, function (array &$d): void {
                    $d['dimensions']['self_confidence']['x'] = 8.0;
                    $d['dimensions']['resentment_self']['x'] = 60.0;
                });
            }
            // a gesture that is neither of her languages: only the eval's own passion makes the moment
            $this->moment([self::LYNLY], [self::LYNLY => ['', 'Tell me about the songs you love.', 5]]);
            $b = $this->blushes(self::LYNLY);
            $d = $this->state(self::LYNLY);
            $seconds[$variant] = ['sent' => $b[0] ?? 0, 'blushiness' => RelDynBody::blushiness($d), 'shyness' => RelDynAttraction::shyness($d),
                'delta' => (float) ($d['_body']['blush']['delta'] ?? 0)];
            $this->assertClean();
            $kit->destroy();
            $this->kit = null;
        }
        $stock = $seconds['stock'];
        $shy = $seconds['shy copy'];
        $this->assertGreaterThan($stock['shyness'], $shy['shyness'], json_encode($seconds));
        $this->assertGreaterThan($stock['blushiness'], $shy['blushiness'], json_encode($seconds));
        $this->assertGreaterThan(0, $stock['sent'], 'Lynly as she stands is not immune either ' . json_encode($seconds));
        $this->assertGreaterThan($stock['sent'], $shy['sent'], 'the shy copy blushes longer for the same words ' . json_encode($seconds));
    }

    public function testTheBlushIsNotGatedByGenderAndTheSwitchTurnsItOff(): void
    {
        $kit = $this->world([], [self::FARKAS]);
        $this->hugAll([self::FARKAS]);
        $this->assertCount(1, $this->blushes(self::FARKAS), 'a man blushes through the same bridge');
        $this->assertArrayHasKey('blush', $kit->felt[self::FARKAS]['after']);
        $this->assertClean();
        $kit->destroy();
        $this->kit = null;

        $kit = $this->world(['body' => ['blush' => ['enabled' => false]]], [self::AELA]);
        $this->hugAll([self::AELA]);
        $this->assertSame([], $this->rows(), 'switched off: no command');
        $this->assertArrayHasKey('blush', $kit->felt[self::AELA]['after'], 'the felt line is the felt steering own');
        $this->assertClean();
        $kit->destroy();
        $this->kit = null;

        $kit = $this->world(['body' => ['enabled' => false]], [self::AELA]);
        $this->hugAll([self::AELA]);
        $this->assertSame([], $this->rows());
        $this->assertSame([], array_filter(array_keys($kit->felt[self::AELA]['after']), fn($k) => $k === 'voice' || str_starts_with($k, 'body_')));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ body language, by who each of them is

    public function testAGuardedNpcNeedsMorePassionToApproachThanABoldOneAndNobodyIsImmune(): void
    {
        $kit = $this->world();
        $thr = [];
        $strength = [];
        foreach (self::BEDS as $npc) {
            $this->edit($npc, function (array &$d): void { RelationshipDynamics::setPassion($d, 72.0); });
            $d = $this->state($npc);
            $in = RelDynBody::inputs($d);
            $thr[$npc] = RelDynBody::approachThreshold($in);
            $strength[$npc] = RelDynBody::strengths($in)['approach'];
            $this->assertGreaterThanOrEqual(40.0, $thr[$npc], $npc);
            $this->assertLessThanOrEqual(85.0, $thr[$npc], $npc);
        }
        $this->assertGreaterThan(1, count(array_unique(array_map(fn($t) => round($t, 2), $thr))), 'who they are moves the threshold ' . json_encode($thr));
        // the order of the strengths follows the order of the thresholds (the same passion for all)
        $byThr = $thr;
        asort($byThr);
        $byStrength = $strength;
        arsort($byStrength);
        $this->assertSame(array_keys($byThr), array_keys($byStrength), json_encode(['threshold' => $thr, 'strength' => $strength]));
        // at overwhelming passion everyone approaches
        foreach (self::BEDS as $npc) {
            $this->edit($npc, function (array &$d): void { RelationshipDynamics::setPassion($d, 98.0); });
            $this->assertGreaterThan(0.3, RelDynBody::strengths(RelDynBody::inputs($this->state($npc)))['approach'], "{$npc}: even the guarded");
        }
        $this->assertClean();
    }

    public function testBodyLanguageIsFeltTextAndItFollowsTheStateOfTheBedWithoutNumbersOrAFixedGender(): void
    {
        $kit = $this->world([], [self::AELA, self::ASHE, self::MUIRI, self::LYNLY, self::FARKAS]);
        // five different states, one per NPC: they walk away, quarrel, glow, hold the flush and hide their feelings
        $this->edit(self::AELA, function (array &$d): void { $d['_walkaway_state'] = 'active'; $d['_walkaway_reason'] = 'test'; $d['_walkaway_activated_gamets'] = RelationshipDynamics::currentGamets() ?: 1; });
        $this->edit(self::ASHE, function (array &$d): void { $d['in_conflict'] = true; $d['dimensions']['resentment']['x'] = 60.0; });
        $this->edit(self::MUIRI, function (array &$d): void { RelationshipDynamics::setPassion($d, 92.0); });
        $this->edit(self::LYNLY, function (array &$d): void { RelationshipDynamics::setPassion($d, 55.0); $d['dimensions']['self_confidence']['x'] = 8.0; $d['dimensions']['resentment_self']['x'] = 60.0; });
        $this->edit(self::FARKAS, function (array &$d): void { RelationshipDynamics::setPassion($d, 92.0); });
        $strengths = [];
        foreach ([self::AELA, self::ASHE, self::MUIRI, self::LYNLY, self::FARKAS] as $i => $npc) {
            $strengths[$npc] = RelDynBody::strengths(RelDynBody::inputs($this->state($npc)));
            $kit->turn($npc, 'How are you holding up?', $this->at(19.0 + $i * 0.05), 'state');
        }
        $felt = fn(string $npc) => $kit->felt[$npc]['state'] ?? [];
        $this->assertGreaterThan(0.15, $strengths[self::ASHE]['tense_stance'], json_encode($strengths));
        $this->assertGreaterThan(0.15, $strengths[self::LYNLY]['shy_glance'], 'the hand-set shy copy steals glances ' . json_encode($strengths));
        $this->assertGreaterThan(0.15, $strengths[self::MUIRI]['approach'], json_encode($strengths));
        $this->assertArrayHasKey('body_tense_stance', $felt(self::ASHE), json_encode(array_keys($felt(self::ASHE))));
        $this->assertArrayHasKey('body_shy_glance', $felt(self::LYNLY), json_encode(array_keys($felt(self::LYNLY))));
        $this->assertArrayHasKey('body_approach', $felt(self::MUIRI));
        $this->assertArrayHasKey('body_approach', $felt(self::FARKAS));
        // never a number, never a fixed gender: the pronoun is the NPC own
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung', 'Farkas'] as $npc) {
            foreach ($felt($npc) as $key => $text) {
                if (str_starts_with((string) $key, 'body_') || $key === 'voice') $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$key}");
            }
        }
        $this->assertStringContainsString('Farkas', $felt(self::FARKAS)['body_approach'], 'a man: named, no assumed pronoun');
        $this->assertStringContainsString('Muiri', $felt(self::MUIRI)['body_approach']);
        foreach ([self::FARKAS, self::MUIRI, self::LYNLY, self::ASHE] as $npc) {
            $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|herself|he|him|his|himself)\b/i', implode(' ', array_filter($felt($npc), fn($k) => str_starts_with((string) $k, 'body_') || $k === 'voice', ARRAY_FILTER_USE_KEY)), $npc);
        }
        $this->assertStringNotContainsString('{', implode(' ', $felt(self::MUIRI)), 'no unresolved var');
        $this->assertClean();
    }

    public function testAWalkawayTurnsAwayAndAnOpenConflictStandsTense(): void
    {
        $kit = $this->world([], [self::AELA, self::ASHE]);
        $this->edit(self::AELA, function (array &$d): void { RelationshipDynamics::setPassion($d, 80.0); $d['_walkaway_state'] = 'boundary_test'; });
        $this->edit(self::ASHE, function (array &$d): void { RelationshipDynamics::setPassion($d, 80.0); $d['in_conflict'] = true; $d['dimensions']['resentment']['x'] = 70.0; });
        foreach ([self::AELA, self::ASHE] as $npc) {
            $s = RelDynBody::strengths(RelDynBody::inputs($this->state($npc)));
            $calm = RelDynBody::strengths(RelDynBody::inputs(array_replace($this->state($npc), ['_walkaway_state' => 'normal', 'in_conflict' => false])));
            $this->assertLessThan($calm['approach'], $s['approach'], "{$npc}: what pulls away eases the approach, same passion");
        }
        $this->assertGreaterThan(0.5, RelDynBody::strengths(RelDynBody::inputs($this->state(self::AELA)))['turn_away']);
        $this->assertGreaterThan(0.5, RelDynBody::strengths(RelDynBody::inputs($this->state(self::ASHE)))['tense_stance']);
        $this->assertSame('turn_away', RelDynBody::chosen($this->state(self::AELA))['cue']);
        $this->assertContains(RelDynBody::chosen($this->state(self::ASHE))['cue'], ['tense_stance', 'turn_away']);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ Jev

    public function testJevGetsTheBodyNumbersPicksTheCueAndWhatItPicksSpeaks(): void
    {
        $kit = $this->world([], [self::MUIRI, self::LYNLY]);
        $this->edit(self::MUIRI, function (array &$d): void { RelationshipDynamics::setPassion($d, 92.0); });
        $this->edit(self::LYNLY, function (array &$d): void { RelationshipDynamics::setPassion($d, 55.0); $d['dimensions']['self_confidence']['x'] = 8.0; $d['dimensions']['resentment_self']['x'] = 60.0; });
        $j = RelationshipDynamics::jevStateBlock(self::MUIRI);
        $this->assertTrue($j['body']['enabled']);
        $this->assertTrue($j['body']['cues']['approach']['offered']);
        $this->assertSame('ComeCloser', $j['body']['cues']['approach']['action']);
        $this->assertNotNull($j['body']['voice']);

        // Jev picks the glance for the shy copy (not the default's approach), refuses a cue she does not show, and the approach fires ComeCloser
        $pick = RelDynBody::pick(self::LYNLY, 'shy_glance');
        $this->assertTrue($pick['ok'], json_encode($pick));
        $this->assertNull($pick['command'], 'no animation channel in core for a glance: context text');
        $no = RelDynBody::pick(self::LYNLY, 'turn_away');
        $this->assertFalse($no['ok']);
        $this->assertSame('not_offered', $no['reason']);
        $again = RelDynBody::pick(self::LYNLY, 'shy_glance');
        $this->assertSame('cooldown', $again['reason'], 'no spam');
        $kit->turn(self::LYNLY, 'What are you thinking about?', $this->at(19.0), 'picked');
        $this->assertArrayHasKey('body_shy_glance', $kit->felt[self::LYNLY]['picked']);
        $this->assertSame('jev', RelDynBody::chosen($this->state(self::LYNLY))['source']);

        $come = RelDynBody::pick(self::MUIRI, 'approach');
        $this->assertTrue($come['ok'], json_encode($come));
        $this->assertSame('ComeCloser', $come['action']);
        $this->assertSame(self::MUIRI . '|command|ComeCloser@', $come['command'], 'the line Jev sends through the channel its own actions use');
        $this->assertSame([], $this->rows(), 'RelDyn sends no game command for a cue by itself: Jev does');
        $kit->turn(self::MUIRI, 'Come sit by me.', $this->at(19.1), 'come');
        $this->assertArrayHasKey('body_approach', $kit->felt[self::MUIRI]['come']);

        // Jev decides nothing fits: the default stays quiet
        $none = RelDynBody::pick(self::MUIRI, null);
        $this->assertTrue($none['ok']);
        $kit->turn(self::MUIRI, 'Well?', $this->at(19.2), 'none');
        $this->assertSame([], array_filter(array_keys($kit->felt[self::MUIRI]['none']), fn($k) => str_starts_with((string) $k, 'body_')));
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the voice

    public function testTheVoiceIsAMoodFromTheNpcOwnListAndOnlyForcedWhenKenOptsIn(): void
    {
        $kit = $this->world([], [self::MUIRI]);
        $this->edit(self::MUIRI, function (array &$d): void { RelationshipDynamics::setPassion($d, 88.0); });
        $GLOBALS['EMOTEMOODS'] = 'neutral,lovely,sad,angry';
        $v = RelDynBody::voice(RelDynBody::inputs($this->state(self::MUIRI)));
        $this->assertSame('seductive', $v['family']);
        $this->assertSame('lovely', $v['mood'], 'her own list has no seductive or sexy: the next in line');
        $this->assertSame('affectionate', $v['cartesia']);
        $kit->turn(self::MUIRI, 'Good evening.', $this->at(19.0), 'voice');
        $this->assertArrayHasKey('voice', $kit->felt[self::MUIRI]['voice'], 'felt text for the model to pick the mood from');
        $this->assertArrayNotHasKey('FORCE_MOOD', $GLOBALS, 'ships off: the model own mood stands');
        $kit->destroy();
        $this->kit = null;

        $kit = $this->world(['body' => ['voice' => ['force_mood' => true]]], [self::MUIRI]);
        $this->edit(self::MUIRI, function (array &$d): void { RelationshipDynamics::setPassion($d, 88.0); });
        $GLOBALS['EMOTEMOODS'] = 'neutral,lovely,sad,angry';
        unset($GLOBALS['FORCE_MOOD']);
        $kit->turn(self::MUIRI, 'Good evening.', $this->at(19.0), 'forced');
        $this->assertSame('lovely', $GLOBALS['FORCE_MOOD'] ?? null, 'opted in: the line carries the voice mood');
        $this->assertClean();
    }
}
