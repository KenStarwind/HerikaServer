<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** conf_opts only (the RelDyn config row); any other statement fails the test. */
final class RelDynEvalFieldsConfDb
{
    public array $confOpts = [];

    public function fetchOne($q, array $params = [])
    {
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", (string) $q, $m)) {
            return isset($this->confOpts[$m[1]]) ? ['value' => $this->confOpts[$m[1]]] : [];
        }
        throw new RuntimeException('unexpected query: ' . $q);
    }

    public function fetchAll($q)
    {
        if (preg_match('/FROM (core_player|conf_opts|eventlog|quests)/', (string) $q)) {
            return [];
        }
        throw new RuntimeException('unexpected query: ' . $q);
    }
    public function execQuery($q) { throw new RuntimeException('unexpected query: ' . $q); }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . str_replace("'", "''", (string) $s) . "'"; }
}

/**
 * eval-extra-fields (decisions 2026-09-24 §8: romantic_intent, goal_addressed, masking as
 * additive v1 fields) through producer prompt, strict parser and consumer, and the readers they
 * feed: charisma (MDD 5.1), director goals (PR 39, ages on the play clock), social masking
 * (MDD 11) and dimensional memory (reason anchors: cleaned text on game time). No database
 * beyond the config row; the hooks, the worker and the four test beds are
 * RelDynEvalFieldsTestBedsPostgresTest.
 */
final class RelDynEvalFieldsTest extends TestCase
{
    private RelDynEvalFieldsConfDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevLog = null;

    private const JOB = ['npc' => 'Aela the Huntress', 'npc_id' => 12, 'player_name' => 'Kaida', 'gamets' => 5000123, 'event_tags' => []];
    private const WINDOW = ['earlier' => [], 'current' => [
        ['speaker' => 'Kaida', 'listener' => 'Aela the Huntress', 'text' => 'You look lovely in the moonlight.'],
        ['speaker' => 'Aela the Huntress', 'listener' => 'Kaida', 'text' => 'Careful, Shield-Sibling.'],
    ]];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['RELDYN_PLAYER_NAME']);
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', '123456', 'Kaida: hello'];
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-evalfields-');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        $this->db = new RelDynEvalFieldsConfDb();
        $GLOBALS['db'] = $this->db;
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        @unlink($this->errorLog);
    }

    private function setConfig(array $overrides): void
    {
        $this->db->confOpts['relationship_dynamics_config'] = json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides));
        RelationshipDynamics::clearConfigCache();
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private function npc(array $o = []): array
    {
        $mirror = (($o['core_aff'] ?? 20.0) + 100.0) / 2.0;
        $dyn = [
            'inferred_temperament' => $o['temperament'] ?? 'Humble',
            'profile_overrides' => ['attachment_style' => $o['attachment'] ?? 'secure'],
            'traits' => [],
            'jealousy_anger' => 0.0,
            '_internal_weather' => 'clear',
            '_aff_mirror_x' => $mirror,
            '_accumulated_play_gamets' => $o['play'] ?? 0.0,
            'dimensions' => [
                'affinity' => ['x' => $mirror, 'baseline' => null],
                'maturity' => ['x' => $o['maturity'] ?? 60.0, 'baseline' => $o['maturity'] ?? 60.0, 'plasticity_type' => 'Adaptive'],
                'passion' => ['x' => 10.0, 'baseline' => 0],
                'resentment' => ['x' => 0.0, 'baseline' => 0],
                'comfort' => ['x' => 50.0, 'baseline' => 50.0],
                'respect' => ['x' => 50.0, 'baseline' => 50.0],
                'trust' => ['x' => 50.0, 'baseline' => 50.0],
            ],
        ];
        $dyn['passion'] = 10.0;
        return $dyn;
    }

    private function item(array $o = []): array
    {
        return array_replace([
            'v' => 1, 'npc' => 'Mjoll', 'npc_id' => 7, 'gamets' => 123456, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.3,
            'positive_interaction' => false,
            'summary' => 'small talk',
        ], $o);
    }

    private static function reply(array $extra = []): string
    {
        return json_encode(array_merge([
            'signals' => ['affinity' => 2, 'trust' => 0, 'comfort' => 1, 'respect' => 0, 'passion' => 1, 'maturity' => 0],
            'tags' => ['praise'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0], 'significance' => 0.3,
            'summary' => 'Kaida complimented her in the moonlight.',
        ], $extra));
    }

    // ------------------------------------------------------------------ producer prompt

    public function testThePromptAlwaysAsksRomanticIntentAndTheRestOnlyWhenTheyApply(): void
    {
        $user = RelDynEval::buildMessages('Aela the Huntress', 'Kaida', self::WINDOW, ['Bond with the player: friend'], [])[1]['content'];
        $this->assertStringContainsString('ROMANTIC_INTENT: 0..3, how romantically Kaida approached Aela the Huntress', $user);
        $this->assertStringContainsString('"summary": "one short line", "romantic_intent": 0}', $user, 'in the reply shape');
        $this->assertStringNotContainsString('GOAL_ADDRESSED', $user, 'no goal shown: not asked');
        $this->assertStringNotContainsString('MASKING', $user, 'nobody else present: not asked');

        $user = RelDynEval::buildMessages('Aela the Huntress', 'Kaida', self::WINDOW, [], [],
            ['goal' => 'Hunt down the Silver Hand cell near Driftshade', 'audience' => ['Farkas', 'Vilkas']])[1]['content'];
        $this->assertStringContainsString("Aela the Huntress'S CURRENT PURPOSE: Hunt down the Silver Hand cell near Driftshade", $user);
        $this->assertStringContainsString('GOAL_ADDRESSED: true only when this exchange clearly served that purpose', $user);
        $this->assertStringContainsString('MASKING (others were present: Farkas, Vilkas)', $user);
        $this->assertStringContainsString('Score the signals from what Aela the Huntress really felt, not the front.', $user);
        $this->assertStringContainsString('"romantic_intent": 0, "goal_addressed": false, "masking": {"flag": false, "slipped": false}}', $user);
    }

    // ------------------------------------------------------------------ strict parser

    public function testRomanticIntentIsParsedStrictlyAndAlwaysPresent(): void
    {
        foreach ([[2, 2], [2.6, 3], [-1, 0], [7, 3], [0, 0]] as [$in, $want]) {
            $item = RelDynEval::parseResponse(self::reply(['romantic_intent' => $in]), self::JOB, $reason);
            $this->assertNull($reason);
            $this->assertSame($want, $item['romantic_intent'], json_encode($in));
        }
        $item = RelDynEval::parseResponse(self::reply(), self::JOB, $reason);
        $this->assertSame(0, $item['romantic_intent'], 'always asked: absent reads as none');

        $item = RelDynEval::parseResponse(self::reply(['romantic_intent' => 'high']), self::JOB, $reason);
        $this->assertNull($reason, 'an additive field never drops the exchange');
        $this->assertArrayNotHasKey('romantic_intent', $item, 'malformed: left out, so the charisma tracker is not fed a guess');
        $this->assertSame(2, $item['signals']['affinity']);
        $this->assertStringContainsString('romantic_intent is not a number, left out', $this->log());
    }

    public function testGoalAddressedIsReadOnlyForTheGoalTheEvalWasShown(): void
    {
        $item = RelDynEval::parseResponse(self::reply(['goal_addressed' => true]), self::JOB, $reason);
        $this->assertArrayNotHasKey('goal_addressed', $item, 'no goal was shown: nothing to have addressed');

        $job = self::JOB + ['goal_ref' => 'abc123'];
        $this->assertSame(['goal_addressed' => true, 'goal_ref' => 'abc123'],
            array_intersect_key(RelDynEval::parseResponse(self::reply(['goal_addressed' => true]), $job, $reason), ['goal_addressed' => 1, 'goal_ref' => 1]));
        $this->assertTrue(RelDynEval::parseResponse(self::reply(['goal_addressed' => 1]), $job, $reason)['goal_addressed']);
        $this->assertFalse(RelDynEval::parseResponse(self::reply(), $job, $reason)['goal_addressed'], 'shown, not answered: not addressed');
        $item = RelDynEval::parseResponse(self::reply(['goal_addressed' => 'yes']), $job, $reason);
        $this->assertNull($reason);
        $this->assertArrayNotHasKey('goal_addressed', $item);
        $this->assertArrayNotHasKey('goal_ref', $item);
    }

    public function testMaskingIsReadOnlyWhenOthersWerePresent(): void
    {
        $mask = ['masking' => ['flag' => true, 'slipped' => false]];
        $this->assertArrayNotHasKey('masking', RelDynEval::parseResponse(self::reply($mask), self::JOB, $reason), 'not asked');

        $job = self::JOB + ['masking_asked' => true];
        $this->assertSame(['flag' => true, 'slipped' => false], RelDynEval::parseResponse(self::reply($mask), $job, $reason)['masking']);
        $this->assertSame(['flag' => true, 'slipped' => true],
            RelDynEval::parseResponse(self::reply(['masking' => ['flag' => false, 'slipped' => true]]), $job, $reason)['masking'],
            'a front that slipped was a front');
        $this->assertArrayNotHasKey('masking', RelDynEval::parseResponse(self::reply(['masking' => ['flag' => false, 'slipped' => false]]), $job, $reason));
        $item = RelDynEval::parseResponse(self::reply(['masking' => [true, false]]), $job, $reason);
        $this->assertNull($reason);
        $this->assertArrayNotHasKey('masking', $item);
        $this->assertStringContainsString('masking is not an object, left out', $this->log());
    }

    // ------------------------------------------------------------------ consumer

    public function testTheNormalizerPassesTheNewFieldsAndOlderItemsStayAsTheyWere(): void
    {
        $old = $this->item();
        $n = RelationshipDynamics::normalizeEvalContractItem($old);
        foreach (['romantic_intent', 'goal_addressed', 'goal_ref', 'masking'] as $k) {
            $this->assertArrayNotHasKey($k, $n, "an older item has no {$k}");
        }
        $new = $this->item(['romantic_intent' => 2.4, 'goal_addressed' => true, 'goal_ref' => 'r1', 'masking' => ['flag' => true, 'slipped' => true]]);
        $n = RelationshipDynamics::normalizeEvalContractItem($new);
        $this->assertSame(2, $n['romantic_intent']);
        $this->assertSame([true, 'r1'], [$n['goal_addressed'], $n['goal_ref']]);
        $this->assertSame(['flag' => true, 'slipped' => true], $n['masking']);
        $this->assertSame(RelationshipDynamics::evalContractFingerprint($old), RelationshipDynamics::evalContractFingerprint($new),
            'fingerprints of already-applied items stay valid');

        $bad = RelationshipDynamics::normalizeEvalContractItem($this->item(['goal_addressed' => true, 'romantic_intent' => 'x']));
        $this->assertArrayNotHasKey('goal_addressed', $bad, 'no goal_ref: it cannot say which goal');
        $this->assertArrayNotHasKey('romantic_intent', $bad);
        $this->assertStringContainsString('goal_addressed needs a boolean and the goal_ref it answers', $this->log());
    }

    /**
     * MDD 5.1: the style is graded from the eval's romantic_intent and affinity. Before this
     * field existed the tracker was fed intent 0 on every request and read every player as the
     * Rock; a tracker from that time starts over, and an item without the field feeds nothing.
     */
    public function testRomanticIntentGradesThePlayersStyle(): void
    {
        $feed = function (array $npc, array $rows): array {
            foreach ($rows as $i => [$intent, $aff]) {
                RelationshipDynamics::processEvalContractItem('Mjoll', $this->item([
                    'gamets' => 200000 + 1000 * $i, 'romantic_intent' => $intent,
                    'signals' => ['affinity' => $aff, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                    'summary' => "exchange {$i}",
                ]), $npc);
            }
            return $npc;
        };
        $charmer = $feed($this->npc(), array_fill(0, 5, [2, 3]));
        $this->assertSame('charmer', RelationshipDynamics::charismaStyle($charmer));
        $this->assertSame(204000.0, $charmer['_charisma_tracker']['style_detected_gamets'], 'stamped on the fifth exchange\'s game time');
        $this->assertArrayNotHasKey('style_detected_at', $charmer['_charisma_tracker']);

        $catalyst = $feed($this->npc(), [[2, 8], [1, -6], [2, 8], [1, -6], [2, 8]]);
        $this->assertSame('catalyst', RelationshipDynamics::charismaStyle($catalyst), 'push-pull');
        $rock = $feed($this->npc(), array_fill(0, 5, [0, 1]));
        $this->assertSame('rock', RelationshipDynamics::charismaStyle($rock), 'steady, no romance');
        $this->assertNull(RelationshipDynamics::charismaStyle($feed($this->npc(), array_fill(0, 4, [2, 3]))), 'too few exchanges yet');

        // The old heuristic's tracker is not evidence
        $legacy = $this->npc();
        $legacy['_charisma_tracker'] = ['recent_intents' => [0, 0, 0, 0, 0], 'recent_deltas' => [1, 1, 1, 1, 1],
            'detected_style' => 'rock', 'style_confidence' => 0.9, 'style_detected_at' => 1727000000];
        $this->assertNull(RelationshipDynamics::charismaStyle($legacy), 'not read');
        $legacy = $feed($legacy, [[2, 3]]);
        $this->assertSame([2], $legacy['_charisma_tracker']['recent_intents'], 'starts over');

        // An older item (no romantic_intent) says nothing about style
        $npc = $this->npc();
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['signals' => ['affinity' => 3] + $this->item()['signals']]), $npc);
        $this->assertArrayNotHasKey('_charisma_tracker', $npc);

        $this->setConfig(['charisma_detection_enabled' => false]);
        $this->assertArrayNotHasKey('_charisma_tracker', $feed($this->npc(), array_fill(0, 5, [2, 3])), 'switched off');
    }

    /** PR 39 step 5: goal_addressed fulfils the goal the eval was shown, never a newer one. */
    public function testGoalAddressedFulfilsTheGoalItWasShown(): void
    {
        $npc = $this->npc(['play' => 1000.0]);
        RelationshipDynamics::setDirectorGoal($npc, 'Find the Silver Hand camp', 'director');
        $ref = RelationshipDynamics::directorGoalRef(RelationshipDynamics::getActiveDirectorGoal($npc));

        $stale = $npc;
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['goal_addressed' => false, 'goal_ref' => $ref]), $stale);
        $this->assertNotNull(RelationshipDynamics::getActiveDirectorGoal($stale), 'not addressed: stays');

        $other = $npc;
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['goal_addressed' => true, 'goal_ref' => 'someothergoal0']), $other);
        $this->assertNotNull(RelationshipDynamics::getActiveDirectorGoal($other), 'the eval answered for another goal');

        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['goal_addressed' => true, 'goal_ref' => $ref]), $npc);
        $this->assertNull(RelationshipDynamics::getActiveDirectorGoal($npc));
        $this->assertSame(['fulfilled', 'eval_confirmed'], [$npc['_director_goal_history'][0]['outcome'], $npc['_director_goal_history'][0]['fulfill_reason']]);

        $off = $this->npc();
        RelationshipDynamics::setDirectorGoal($off, 'Find the Silver Hand camp', 'director');
        $offRef = RelationshipDynamics::directorGoalRef(RelationshipDynamics::getActiveDirectorGoal($off));
        $this->setConfig(['director_goals_enabled' => false]);
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['goal_addressed' => true, 'goal_ref' => $offRef]), $off);
        $this->assertSame('Find the Silver Hand camp', $off['_director_goal']['text'], 'switched off: untouched');
    }

    /**
     * PR 39: goals age on the NPC's play clock in play hours (1 h director, 2 h background
     * life; the HERIKA_GOALS bridge 2 h). The April code compared "3600 / 7200 gamets" with
     * the play clock (2315 play gamets per second of play): a bridged goal lasted ~3 s of play.
     */
    public function testDirectorGoalsAgeInPlayHours(): void
    {
        $H = RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $npc = $this->npc(['play' => 10.0 * $H]);
        RelationshipDynamics::setDirectorGoal($npc, 'Suggest exploring the ruin', 'director');
        $this->assertEqualsWithDelta(1.0 * $H, $npc['_director_goal']['max_age_play_gamets'], 1e-6);
        foreach ([[0.05, true], [0.9, true], [1.1, false]] as [$h, $active]) {
            $npc['_accumulated_play_gamets'] = (10.0 + $h) * $H;
            $this->assertSame($active, RelationshipDynamics::getActiveDirectorGoal($npc) !== null, "director goal after {$h} play hours");
        }
        $bgl = $this->npc(['play' => 0.0]);
        RelationshipDynamics::setDirectorGoal($bgl, 'Gather firewood', 'bgl');
        $bgl['_accumulated_play_gamets'] = 1.9 * $H;
        $this->assertNotNull(RelationshipDynamics::getActiveDirectorGoal($bgl), 'background life: 2 play hours');
        $bridge = RelationshipDynamics::directorGoalConfig();
        $this->assertEquals([2.0, 0.4], [$bridge['bridge_max_age_play_hours'], $bridge['bridge_priority']], 'the bridge keeps its 2 h, background priority');
        $this->assertEqualsWithDelta(2.0 * $H, RelationshipDynamics::directorGoalMaxAgePlayGamets('director', 2.0), 1e-6);

        // A goal stored by the old code (max_age_gamets in "seconds") takes its source's age
        $old = $this->npc(['play' => 5000.0]);
        $old['_director_goal'] = ['text' => 'Old goal', 'source' => 'director', 'created_gamets' => 0.0, 'max_age_gamets' => 7200, 'priority' => 0.4, 'active' => true];
        $this->assertNotNull(RelationshipDynamics::getActiveDirectorGoal($old));
    }

    /** MDD 11: the eval's masking field pays the front's cost; a slip is a one-shot for the next context. */
    public function testMaskingFieldPaysTheCostAndLeavesTheSlip(): void
    {
        $masked = ['masking' => ['flag' => true, 'slipped' => false]];
        $npc = $this->npc(['maturity' => 60.0]);
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item($masked), $npc);
        $this->assertSame(60.0, $npc['dimensions']['maturity']['x'], 'social masking ships off: nothing');

        $this->setConfig(['social_masking_enabled' => true]);
        $npc = $this->npc(['maturity' => 60.0]);
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item($masked), $npc);
        $this->assertEqualsWithDelta(60.0 - 0.15, $npc['dimensions']['maturity']['x'], 1e-9, 'mask_maturity_cost, secure corner x1.0');
        $this->assertSame(1, $npc['_mask_interactions_count']);
        $this->assertArrayNotHasKey('_mask_slip_gamets', $npc);

        $avoidant = $this->npc(['maturity' => 60.0, 'attachment' => 'avoidant']);
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(['masking' => ['flag' => true, 'slipped' => true], 'gamets' => 777000]), $avoidant);
        $this->assertEqualsWithDelta(60.0 - 0.075, $avoidant['dimensions']['maturity']['x'], 1e-9, 'a practised masker: x0.5');
        $this->assertSame(777000.0, $avoidant['_mask_slip_gamets']);

        $plain = $this->npc(['maturity' => 60.0]);
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item(), $plain);
        $this->assertSame(60.0, $plain['dimensions']['maturity']['x'], 'no masking field: no cost');
    }

    /**
     * dimensional-memory: reason anchors are stored as the cleaned event (no scores, no
     * numbers, no named feelings) on game time; readers never see a raw number.
     */
    public function testReasonAnchorsAreCleanedEventsOnGameTime(): void
    {
        $d = [];
        RelationshipDynamics::storeDimensionalMemory($d, 'trust', -6.0, 'Kaida sold 3 of her pelts (trust -6); she trusts Kaida less now', 'Kaida', 4242.0);
        $this->assertSame([['dim' => 'trust', 'delta' => -6.0, 'reason' => 'Kaida sold three of her pelts', 'bond' => 'Kaida', 'gamets' => 4242.0, 'abs_delta' => 6.0]],
            $d['dimensional_memory']);
        RelationshipDynamics::storeDimensionalMemory($d, 'comfort', 2.0, 'She felt more comfortable (+2)', 'Kaida', 4300.0);
        $this->assertCount(1, $d['dimensional_memory'], 'nothing of the event left: not stored');

        $npc = $this->npc(['core_aff' => 10]);
        RelationshipDynamics::processEvalContractItem('Mjoll', $this->item([
            'signals' => ['affinity' => -8, 'trust' => -5, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'summary' => 'Kaida mocked her 2 times in front of the guards; her respect dropped', 'gamets' => 555000,
        ]), $npc);
        foreach ($npc['dimensional_memory'] as $m) {
            $this->assertSame('Kaida mocked her two times in front of the guards', $m['reason']);
            $this->assertSame(555000.0, $m['gamets']);
        }
        $this->assertSame('Kaida mocked her two times in front of the guards', $npc['dimensions']['trust']['last_reason']);
        $this->assertSame(['Kaida mocked her two times in front of the guards'], RelationshipDynamics::getConfrontationFuel($npc, 'Kaida'));
    }
}
