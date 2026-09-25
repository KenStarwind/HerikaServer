<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Minimal core_npc_master reader for getAllBondsForNpc: name => extended_data. */
final class RelDynProtocolsFakeDb
{
    public array $rows = [];

    public function fetchOne($q, array $params = [])
    {
        if (preg_match("/FROM core_npc_master WHERE lower\\(npc_name\\) = lower\\('([^']*)'\\)/", (string) $q, $m)) {
            foreach ($this->rows as $name => $ext) {
                if (strcasecmp($name, $m[1]) === 0) return ['extended_data' => json_encode($ext)];
            }
        }
        return [];
    }

    public function fetchAll($q) { return []; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
}

/**
 * The P3 protocols' units (reldyn_protocols.php and the RelationshipDynamics code it wires):
 * grief phases on the game calendar x the bond-duration weight, held acute offsets that lift
 * exactly, the memorial, the widow's lock in core units; the Divine Intervention fork without the
 * dead or the betrayer, the unstable window on the calendar; the Ick's avoidance term, its
 * per-attempt costs, its reachable recovery, its game-time stamp and inverted passion; the
 * parasite ledger, threshold and half-life. No database (a fake core row reader where bonds are read).
 */
final class RelDynProtocolsTest extends TestCase
{
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 2100000000.0;   // raw gamets (game day 210)

    private $savedDb;
    private $savedRequest;
    private string $errorLog;
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->savedRequest = $GLOBALS['gameRequest'] ?? null;
        unset($GLOBALS['db']);
        $this->clock(self::T0);
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdproto');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb; else unset($GLOBALS['db']);
        if ($this->savedRequest !== null) $GLOBALS['gameRequest'] = $this->savedRequest; else unset($GLOBALS['gameRequest']);
        RelationshipDynamics::clearConfigCache();
    }

    private function clock(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '0', (string) $gamets, 'Kaida: hello'];
    }

    private static function npc(array $extra = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), $extra));
        foreach (['comfort' => 50.0, 'warmth' => 50.0, 'trust' => 50.0, 'valence' => 0.0, 'maturity' => 50.0, 'resentment' => 0.0] as $dim => $v) {
            $d['dimensions'][$dim]['x'] = $v;
        }
        return $d;
    }

    private static function grief(float $death, float $bondHours, float $coreAff = 70.0, int $phase = 1): array
    {
        return ['phase' => $phase, 'clock' => 'calendar', 'death_gamets' => $death, 'bond_duration_hours' => $bondHours,
            'bond_affinity_at_death' => ($coreAff + 100) / 2, 'core_affinity_at_death' => $coreAff, 'phase_transitions' => [1 => $death],
            '_phase_1_applied' => true, '_phase_2_applied' => false, '_phase_3_applied' => false, '_phase_4_applied' => false];
    }

    // ------------------------------------------------------------------ grief

    public function testPhasesRunOnTheGameCalendarTimesTheBondWeight(): void
    {
        $this->assertSame(0.1, RelDynProtocols::griefWeight(0.0), 'floored at weight_min');
        $this->assertSame(0.5, RelDynProtocols::griefWeight(50.0));
        $this->assertSame(2.0, RelDynProtocols::griefWeight(900.0), 'capped at weight_max');
        // the design's 48 h / 1 week / 4 weeks, in game hours x weight
        $this->assertSame([1, 0.0], RelDynProtocols::phaseAt(0.0, 1.0));
        $this->assertSame(2, RelDynProtocols::phaseAt(48.0, 1.0)[0]);
        $this->assertSame(3, RelDynProtocols::phaseAt(168.0, 1.0)[0]);
        $this->assertSame([4, 1.0], RelDynProtocols::phaseAt(672.0, 1.0));
        $this->assertSame(1, RelDynProtocols::phaseAt(95.0, 2.0)[0], 'a long bond: bargaining only after 96 game hours');
        $this->assertSame(3, RelDynProtocols::phaseAt(40.0, 0.2)[0], 'a short bond is through bargaining in two days');
        $this->assertEqualsWithDelta(0.5, RelDynProtocols::phaseAt(420.0, 1.0)[1], 1e-9, 'halfway through integration');
    }

    /**
     * Acute grief is held on comfort and warmth (points), lifts across integration and is gone,
     * exactly, once she carries it forward; valence is held negative while it is acute; the memory
     * is idealized, settles, and rests at its memorial level; trust -5 once in bargaining.
     */
    public function testTheAcuteOffsetsAreHeldAndLiftExactly(): void
    {
        $d = self::npc();
        $d['_grief_bonds']['Vilkas'] = self::grief(self::T0, 100.0, 70.0);
        $this->assertTrue(RelDynProtocols::tickGrief('Aela', $d, self::T0));
        $this->assertSame(['comfort' => -15.0, 'warmth' => -10.0], array_map('floatval', $d['_grief_held']));
        $this->assertSame([35.0, 40.0, -30.0], [$d['dimensions']['comfort']['x'], $d['dimensions']['warmth']['x'], $d['dimensions']['valence']['x']]);
        $this->assertSame(-15.0, RelationshipDynamics::heldTemporaryOffset($d, 'comfort'), 'held: never who she is');

        // life goes on meanwhile: comfort moves by other causes
        $d['dimensions']['comfort']['x'] += 7.0;
        RelDynProtocols::tickGrief('Aela', $d, self::T0 + 50 * self::HOUR);
        $this->assertSame(2, $d['_grief_bonds']['Vilkas']['phase']);
        $this->assertSame(100.0, floatval($d['_grief_bonds']['Vilkas']['memory_warmth']), 'the memory idealized');
        $this->assertSame(45.0, floatval($d['dimensions']['trust']['x']), 'bargaining: trust -5 once');
        RelDynProtocols::tickGrief('Aela', $d, self::T0 + 51 * self::HOUR);
        $this->assertSame(45.0, floatval($d['dimensions']['trust']['x']), 'once');

        RelDynProtocols::tickGrief('Aela', $d, self::T0 + 420 * self::HOUR);   // halfway through integration
        $this->assertEqualsWithDelta(-7.5, $d['_grief_held']['comfort'], 1e-6);
        $this->assertEqualsWithDelta(100 - (100 - 54) / 2, $d['_grief_bonds']['Vilkas']['memory_warmth'], 1e-6);
        $this->assertEqualsWithDelta(49.5, $d['dimensions']['comfort']['x'], 1e-6);

        RelDynProtocols::tickGrief('Aela', $d, self::T0 + 700 * self::HOUR);
        $this->assertArrayNotHasKey('_grief_held', $d);
        $this->assertEqualsWithDelta(57.0, $d['dimensions']['comfort']['x'], 1e-6, 'exactly where she would be without the grief');
        $this->assertEqualsWithDelta(50.0, $d['dimensions']['warmth']['x'], 1e-6);
        $this->assertSame('memorial', $d['_grief_bonds']['Vilkas']['bond_type']);
        $this->assertEqualsWithDelta(54.0, $d['_grief_bonds']['Vilkas']['memory_warmth'], 1e-6, 'memorial: 40 + 20 x 0.7');
    }

    public function testTwoGriefsDoNotStackAndAnEarlierSaveIsLeftAlone(): void
    {
        $d = self::npc();
        $d['_grief_bonds']['Vilkas'] = self::grief(self::T0, 100.0);
        $d['_grief_bonds']['Skjor'] = self::grief(self::T0 + 10 * self::HOUR, 100.0);
        RelDynProtocols::tickGrief('Aela', $d, self::T0 + 10 * self::HOUR);
        $this->assertSame(-15.0, floatval($d['_grief_held']['comfort']), 'the strongest grief holds, they do not add up');
        $e = self::npc();
        $e['_grief_bonds']['Vilkas'] = self::grief(self::T0, 100.0);
        $this->assertFalse(RelDynProtocols::tickGrief('Aela', $e, self::T0 - 5 * self::HOUR), 'a save from before the death');
        $this->assertSame(1, $e['_grief_bonds']['Vilkas']['phase']);
    }

    public function testALegacyPlayClockGriefStartsOverOnTheCalendarAtItsPhase(): void
    {
        $d = self::npc();
        $g = self::grief(12345.0, 100.0, 70.0, 2);
        unset($g['clock']);
        $g['_phase_2_applied'] = true;
        $d['_grief_bonds']['Vilkas'] = $g;
        RelDynProtocols::tickGrief('Aela', $d, self::T0);
        $this->assertSame('calendar', $d['_grief_bonds']['Vilkas']['clock']);
        $this->assertSame(2, $d['_grief_bonds']['Vilkas']['phase'], 'not jumped to memorial by a clock mix-up');
        $this->assertEqualsWithDelta(self::T0 - 48 * self::HOUR, $d['_grief_bonds']['Vilkas']['death_gamets'], 1e-6);
    }

    /** How she grieves: maturity (quiet / public) and the attachment axes (action / clinging). */
    public function testGriefShowsAsWhoSheIs(): void
    {
        $mk = function (float $maturity, array $axes): array {
            $d = self::npc(['profile_overrides' => ['attachment_axes' => $axes]]);
            $d['dimensions']['maturity']['x'] = $maturity;
            $d['_grief_bonds']['Vilkas'] = self::grief(self::T0, 100.0);
            return $d;
        };
        $hunter = RelDynProtocols::griefFeltLines('Aela', $mk(70.0, ['anxiety' => 0.15, 'avoidance' => 0.55]))[0]['text'];
        $clinger = RelDynProtocols::griefFeltLines('Muiri', $mk(40.0, ['anxiety' => 0.7, 'avoidance' => 0.2]))[0]['text'];
        $plain = RelDynProtocols::griefFeltLines('Lynly', $mk(40.0, ['anxiety' => 0.15, 'avoidance' => 0.15]))[0]['text'];
        $this->assertStringContainsString('in silence', $hunter);
        $this->assertStringContainsString('throws herself into the hunt', $hunter, 'avoidance copes through action');
        $this->assertStringContainsString('shattered', $clinger);
        $this->assertStringContainsString('holds on harder to the people still here', $clinger);
        $this->assertStringNotContainsString('; she', $plain, 'no coping colour for a secure NPC');
        foreach ([$hunter, $clinger, $plain] as $t) $this->assertDoesNotMatchRegularExpression('/\d/', $t);
    }

    public function testTheWidowsLockIsForAPartnerAndInCoreUnits(): void
    {
        $this->assertTrue(RelDynProtocols::widowLockApplies(['aff' => 40, 'type' => 'romantic']));
        $this->assertTrue(RelDynProtocols::widowLockApplies(['aff' => 20, 'type' => 'familial']));
        $this->assertTrue(RelDynProtocols::widowLockApplies(['aff' => 80, 'type' => 'platonic']), 'the bonded tier');
        $this->assertFalse(RelDynProtocols::widowLockApplies(['aff' => 70, 'type' => 'platonic']));

        // ceiling 70 core: at core 70 (mirror 85) a gain is held; at core 60 (mirror 80) +20 -> +10
        $d = self::npc(['_widow_lock_ceiling' => 70.0]);
        $d['dimensions']['affinity']['x'] = 85.0;
        $this->assertSame(0.0, floatval(RelationshipDynamics::applyCrossSignalCaps($d, 'affinity', 5.0)));
        $d['dimensions']['affinity']['x'] = 80.0;
        $this->assertEqualsWithDelta(10.0, RelationshipDynamics::applyCrossSignalCaps($d, 'affinity', 20.0), 1e-9);
        $this->assertEqualsWithDelta(-5.0, RelationshipDynamics::applyCrossSignalCaps($d, 'affinity', -5.0), 1e-9, 'losses pass');
    }

    // ------------------------------------------------------------------ divine intervention

    private function bonds(array $rows): RelDynProtocolsFakeDb
    {
        $db = new RelDynProtocolsFakeDb();
        $db->rows = $rows;
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        return $db;
    }

    public function testTheDeadAnchorNoOne(): void
    {
        $this->bonds(['Muiri' => ['relationships' => ['Player' => ['aff' => 60, 'type' => 'romantic'],
            'Vilkas' => ['aff' => 90, 'type' => 'romantic'], 'Lami' => ['aff' => 90, 'type' => 'platonic']]]]);
        $d = self::npc();
        $d['dimensions']['trust']['x'] = 30.0;
        $all = RelationshipDynamics::calculateAnchorStatus('Muiri', $d);
        $this->assertTrue($all['has_anchor'], json_encode($all));   // 30 + 47.5 + 47.5
        $gone = RelationshipDynamics::calculateAnchorStatus('Muiri', $d, ['Vilkas']);
        $this->assertFalse($gone['has_anchor'], 'Vilkas is dead: ' . json_encode($gone));
        $d['_grief_bonds']['Lami'] = self::grief(self::T0, 10.0);
        $this->assertEqualsWithDelta(30.0, RelationshipDynamics::calculateAnchorStatus('Muiri', $d, ['Vilkas'])['total_trust'], 1e-9,
            'nor anyone she grieves');
    }

    /** The window runs on the game calendar; the betrayer and the dead are no anchors; nobody by its end: breaking, said once. */
    public function testTheWindowRunsOnTheCalendar(): void
    {
        $this->bonds(['Ashe' => ['relationships' => ['Player' => ['aff' => 80, 'type' => 'romantic'], 'Aela the Huntress' => ['aff' => 80, 'type' => 'platonic']]]]);
        $d = self::npc();
        $d['dimensions']['trust']['x'] = 60.0;
        $d['_accumulated_play_gamets'] = 5.0e6;
        $d['_unstable_window'] = ['start_gamets' => self::T0, 'duration_gamets' => 24 * self::HOUR, 'clock' => 'calendar',
            'event_type' => 'betrayal', 'severity' => 4, 'exclude' => ['Player'], 'resolved' => false, 'resolution' => null];
        $this->clock(self::T0 + 2 * self::HOUR);
        $this->assertSame('active', RelationshipDynamics::checkUnstableWindow('Ashe', $d, 'Kaida', []), 'the betrayer anchors nothing');
        $this->clock(self::T0 + 23 * self::HOUR);
        $this->assertSame('active', RelationshipDynamics::checkUnstableWindow('Ashe', $d, null, []));
        $this->clock(self::T0 + 24 * self::HOUR);
        $this->assertSame('breaking', RelationshipDynamics::checkUnstableWindow('Ashe', $d, null, []));
        $this->assertSame(['kind' => 'breaking', 'gamets' => self::T0 + 24 * self::HOUR], $d[RelDynProtocols::CRISIS_SAY_KEY]);
        $this->assertEqualsWithDelta(self::T0 + 24 * self::HOUR + RelationshipDynamics::THIRTY_GAME_DAYS_GAMETS, $d['_plasticity_override_expires_gamets'], 1e-6,
            'the arc runs 30 game days from the event');
        $turn = RelDynProtocols::crisisTurn('Ashe', $d, [], true);
        $this->assertSame(['crisis_resolved', 'arc'], array_column($turn['lines'], 'key'));
        $this->assertArrayNotHasKey(RelDynProtocols::CRISIS_SAY_KEY, $d, 'said once');

        // A companion death: someone around with a bond anchors her
        $e = self::npc();
        $e['_unstable_window'] = ['start_gamets' => self::T0, 'duration_gamets' => 24 * self::HOUR, 'clock' => 'calendar',
            'event_type' => 'companion_death', 'severity' => 2, 'exclude' => ['Vilkas'], 'resolved' => false, 'resolution' => null];
        $this->clock(self::T0 + self::HOUR);
        $this->assertSame('redemption', RelationshipDynamics::checkUnstableWindow('Ashe', $e, null, ['Ashe', 'Aela the Huntress']));
        $this->assertSame('Aela the Huntress', $e['_unstable_window']['anchor']);

        // A window from the play-clock build starts over on the calendar
        $f = self::npc();
        $f['_unstable_window'] = ['start_gamets' => 12345.0, 'duration_gamets' => 200016000.0, 'event_type' => 'near_tpk', 'severity' => 2, 'resolved' => false];
        $this->assertSame('active', RelationshipDynamics::checkUnstableWindow('Ashe', $f, null, []));
        $this->assertEqualsWithDelta(self::T0 + self::HOUR, $f['_unstable_window']['start_gamets'], 1.0, 'now (the request clock)');
        $this->assertSame([24 * self::HOUR, 'calendar'], [$f['_unstable_window']['duration_gamets'], $f['_unstable_window']['clock']]);
    }

    // ------------------------------------------------------------------ the Ick

    private static function cold(array $axes, float $maturity = 50.0): array
    {
        $d = self::npc(['profile_overrides' => ['attachment_axes' => $axes], 'interaction_count' => 0]);
        $d['dimensions']['maturity']['x'] = $maturity;
        $d['dimensions']['comfort']['x'] = 25.0;
        RelationshipDynamics::setPassion($d, 5.0);
        return $d;
    }

    public function testAvoidanceLowersTheIckThreshold(): void
    {
        $this->assertSame(1.0, RelDynProtocols::ickAvoidanceMult(self::cold(['anxiety' => 0.15, 'avoidance' => 0.15])));
        $this->assertEqualsWithDelta(0.7, RelDynProtocols::ickAvoidanceMult(self::cold(['anxiety' => 0.15, 'avoidance' => 0.85])), 1e-9);
        // 3 attempts in 5 at maturity 50: under 0.75 (secure), over 0.75 x 0.7 (avoidant)
        foreach ([[0.15, false], [0.85, true]] as [$avoid, $expect]) {
            $d = self::cold(['anxiety' => 0.15, 'avoidance' => $avoid]);
            $d['_ick_tracker'] = ['total_count' => 5, 'romantic_count' => 3];
            $this->assertSame($expect, RelationshipDynamics::checkIckTrigger($d, 'Guarded'), "avoidance {$avoid}");
        }
    }

    /**
     * The trigger is stamped with the exchange's game time and the play clock (no wall clock);
     * a continued attempt costs comfort and builds resentment; a quiet exchange counts toward the
     * recovery; passion gains invert while it lasts.
     */
    public function testTheIcksCostsStampAndInvertedPassion(): void
    {
        $d = self::cold(['anxiety' => 0.15, 'avoidance' => 0.85]);
        $d['_accumulated_play_gamets'] = 777.0;
        RelationshipDynamics::updateIckTracker($d, false, 'Guarded', self::T0);
        RelationshipDynamics::updateIckTracker($d, true, 'Guarded', self::T0 + 100);
        $this->assertTrue(RelationshipDynamics::updateIckTracker($d, true, 'Guarded', self::T0 + 200), 'triggered');
        $tr = $d['_ick_tracker'];
        $this->assertSame([self::T0 + 200, 777.0], [$tr['ick_triggered_gamets'], $tr['ick_triggered_play_gamets']]);
        $this->assertArrayNotHasKey('ick_triggered_at', $tr);

        [$comfort, $resentment] = [$d['dimensions']['comfort']['x'], $d['dimensions']['resentment']['x']];
        $this->assertFalse(RelationshipDynamics::updateIckTracker($d, true, 'Guarded', self::T0 + 300));
        $this->assertLessThan($comfort, $d['dimensions']['comfort']['x'], 'comfort pays');
        $this->assertGreaterThan($resentment, $d['dimensions']['resentment']['x'], 'resentment builds');
        RelationshipDynamics::updateIckTracker($d, false, 'Guarded', self::T0 + 400);
        RelationshipDynamics::updateIckTracker($d, false, 'Guarded', self::T0 + 500);
        $this->assertSame(2, $d['_ick_tracker']['quiet']);

        $p = RelationshipDynamics::getPassion($d);
        RelationshipDynamics::addPassion($d, 2.0, 'love_match');
        $this->assertEqualsWithDelta($p - 2.0, RelationshipDynamics::getPassion($d), 1e-9, 'gains invert');
        $raw = 4.0;
        $this->assertTrue(RelationshipDynamics::applyIckEffects($d, 'passion', $raw));
        $this->assertSame(-4.0, $raw);
        $raw = 3.0;
        $this->assertFalse(RelationshipDynamics::applyIckEffects($d, 'comfort', $raw), 'comfort is paid per attempt, not forced on every exchange');
        $this->assertSame(3.0, $raw);
    }

    /** Recovery the design describes, reachable: at ease again, the push stopped, resentment low or said. */
    public function testTheIckRecoveryIsReachable(): void
    {
        $mk = function (float $comfort, float $passion, int $quiet, float $resentment): array {
            $d = self::cold(['anxiety' => 0.15, 'avoidance' => 0.5]);
            $d['dimensions']['comfort']['x'] = $comfort;
            RelationshipDynamics::setPassion($d, $passion);
            $d['dimensions']['resentment']['x'] = $resentment;
            $d['_ick_tracker'] = ['total_count' => 6, 'romantic_count' => 4, 'ick_active' => true, 'quiet' => $quiet];
            return $d;
        };
        $d = $mk(45.0, 5.0, 3, 10.0);
        $this->assertTrue(RelationshipDynamics::checkIckRecovery($d), 'passion low (inverted while it lasted), but the push stopped');
        $d = $mk(45.0, 5.0, 2, 10.0);
        $this->assertFalse(RelationshipDynamics::checkIckRecovery($d), 'the push only just stopped');
        $d = $mk(45.0, 25.0, 0, 10.0);
        $this->assertTrue(RelationshipDynamics::checkIckRecovery($d), 'passion at its floor is stable');
        $d = $mk(38.0, 25.0, 5, 10.0);
        $this->assertFalse(RelationshipDynamics::checkIckRecovery($d), 'not at ease yet');
        $d = $mk(45.0, 25.0, 5, 30.0);
        $this->assertFalse(RelationshipDynamics::checkIckRecovery($d), 'resentment unsaid');
        $d['_ick_confrontation_resolved'] = true;
        $this->assertTrue(RelationshipDynamics::checkIckRecovery($d), 'said calmly');
        $this->assertSame(0, $d['_ick_tracker']['quiet']);
    }

    // ------------------------------------------------------------------ the Parasite

    public function testTheLedgerKeepsOneEntryPerExchangeAndTheStrongerRead(): void
    {
        $d = [];
        RelDynProtocols::recordExchange($d, self::T0, 'other');
        RelDynProtocols::recordExchange($d, self::T0, 'gift');       // the eval's read of the same exchange
        RelDynProtocols::recordExchange($d, self::T0 + 1, 'gift');
        RelDynProtocols::recordExchange($d, self::T0 + 1, 'other');  // a weaker read does not demote it
        RelDynProtocols::recordExchange($d, self::T0 + 2, 'gift');
        RelDynProtocols::recordExchange($d, self::T0 + 2, 'genuine'); // a gift in a real moment
        $this->assertSame([3, 2, 1], [$d['_interaction_pattern']['total_window'], $d['_interaction_pattern']['gift_count'], $d['_interaction_pattern']['genuine_count']]);
        for ($i = 3; $i < 30; $i++) RelDynProtocols::recordExchange($d, self::T0 + $i, 'other');
        $this->assertSame(20, $d['_interaction_pattern']['total_window'], 'a rolling window');
        $this->assertSame(0, $d['_interaction_pattern']['gift_count']);
        // the April counters start fresh
        $e = ['_interaction_pattern' => ['gift_count' => 9, 'genuine_count' => 1, 'total_window' => 10, 'window_start' => 0]];
        RelDynProtocols::recordExchange($e, self::T0, 'gift');
        $this->assertSame([1, 1], [$e['_interaction_pattern']['total_window'], $e['_interaction_pattern']['gift_count']]);
    }

    public function testEvalItemsReadAsGiftGenuineOrOther(): void
    {
        $this->assertSame('gift', RelDynProtocols::exchangeKindOfEval(['tags' => ['gift'], 'positive_interaction' => true]));
        $this->assertSame('genuine', RelDynProtocols::exchangeKindOfEval(['tags' => ['gift', 'quality_time']]));
        $this->assertSame('genuine', RelDynProtocols::exchangeKindOfEval(['tags' => [], 'positive_interaction' => true]));
        $this->assertSame('other', RelDynProtocols::exchangeKindOfEval(['tags' => ['command'], 'positive_interaction' => false]));
    }

    /** The share it takes is hers (warmth raises it, egocentric pride lowers it), bounded. */
    public function testTheTransactionalShareIsPersonalityRelative(): void
    {
        RelDynTraits::$assignmentOverride = 'label';
        try {
            $warm = RelDynProtocols::parasiteRatioThreshold(['inferred_temperament' => 'Nurturing']);
            $proud = RelDynProtocols::parasiteRatioThreshold(['inferred_temperament' => 'Proud']);
            $this->assertGreaterThan(0.7, $warm);
            $this->assertLessThan(0.7, $proud);
            $this->assertGreaterThanOrEqual(0.5, $proud);
            $this->assertLessThanOrEqual(0.9, $warm);
        } finally {
            RelDynTraits::$assignmentOverride = null;
        }
        $this->assertSame(0.7, RelDynProtocols::parasiteRatioThreshold([]), 'no personality known: the plain share');
    }

    public function testATransactionalBondsPassionHalvesEveryTwoGameHours(): void
    {
        $d = self::npc(['_relationship_type_override' => 'parasite']);
        RelationshipDynamics::setPassion($d, 40.0);
        $this->assertSame(0.0, RelDynProtocols::parasitePassionDecay($d, self::T0), 'the clock starts');
        RelDynProtocols::parasitePassionDecay($d, self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta(20.0, RelationshipDynamics::getPassion($d), 1e-3);
        RelDynProtocols::parasitePassionDecay($d, self::T0 + 3 * self::HOUR);
        $this->assertEqualsWithDelta(20.0 / sqrt(2.0), RelationshipDynamics::getPassion($d), 1e-3, 'the same law however it is sliced');
        $e = self::npc();
        RelationshipDynamics::setPassion($e, 40.0);
        RelDynProtocols::parasitePassionDecay($e, self::T0);
        RelDynProtocols::parasitePassionDecay($e, self::T0 + 5 * self::HOUR);
        $this->assertSame(40.0, RelationshipDynamics::getPassion($e), 'not a parasite: nothing');
        $this->assertArrayNotHasKey(RelDynProtocols::PARASITE_CLOCK_KEY, $e);
    }

    public function testJevGetsTheNumbers(): void
    {
        $d = self::npc(['_relationship_type_override' => 'parasite', '_widow_lock_ceiling' => 70.0]);
        $d['_grief_bonds']['Vilkas'] = self::grief(self::T0, 150.0) + ['memory_warmth' => 100.0, 'widow_lock' => true];
        $d['_unstable_window'] = ['start_gamets' => self::T0 - 6 * self::HOUR, 'duration_gamets' => 24 * self::HOUR, 'clock' => 'calendar',
            'event_type' => 'companion_death', 'severity' => 3, 'resolved' => false];
        RelDynProtocols::recordExchange($d, self::T0, 'gift');
        $j = RelDynProtocols::jev($d);
        $this->assertSame(['phase' => 1, 'memory_warmth' => 100.0, 'widow_lock' => true], $j['grief']['Vilkas']);
        $this->assertSame(70.0, $j['widow_ceiling']);
        $this->assertSame(['event' => 'companion_death', 'fraction' => 0.25], $j['crisis']);
        $this->assertTrue($j['parasite']);
        $this->assertSame(1.0, $j['gift_share']);
        $text = RelDynJev::render(RelDynJev::state('Muiri', $d, self::T0));
        $this->assertStringContainsString('grief=Vilkas(phase 1 memory 100 widow_lock)', $text);
        $this->assertStringContainsString('widow_ceiling=70', $text);
        $this->assertStringContainsString('crisis=companion_death(0.25)', $text);
        $this->assertStringContainsString('parasite(gift_share 1.00)', $text);
    }

    /** Loading an earlier save (keep policy): the protocols' game-time stamps come back to the loaded time. */
    public function testALoadBringsTheStampsBackToTheLoadedTime(): void
    {
        $T = self::T0;
        $d = self::npc();
        $d['_grief_bonds']['Vilkas'] = self::grief($T + 5 * self::HOUR, 100.0);
        $d['_unstable_window'] = ['start_gamets' => $T + 3 * self::HOUR, 'duration_gamets' => 24 * self::HOUR, 'clock' => 'calendar', 'resolved' => false];
        $d['_ick_tracker'] = ['ick_active' => true, 'ick_triggered_gamets' => $T + self::HOUR];
        $d[RelDynProtocols::PARASITE_CLOCK_KEY] = $T + 2 * self::HOUR;
        $r = RelDynTimeline::rebaselineDynamics($d, $T, ['aff' => 60, 'type' => 'romantic']);
        $this->assertSame([$T, $T, $T, $T], [$r['_grief_bonds']['Vilkas']['death_gamets'], $r['_unstable_window']['start_gamets'],
            $r['_ick_tracker']['ick_triggered_gamets'], $r[RelDynProtocols::PARASITE_CLOCK_KEY]]);
        $this->assertSame(24 * self::HOUR, $r['_unstable_window']['duration_gamets'], 'the window keeps its length');
    }

    public function testConfigIsMergedPerSection(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['protocols'] = ['grief' => ['phase_game_hours' => [2 => 24.0, 3 => 72.0, 4 => 240.0]]];
        $ref = new ReflectionProperty('RelationshipDynamics', 'config');
        $ref->setAccessible(true);
        RelationshipDynamics::beginRequest();
        $ref->setValue(null, $cfg);
        try {
            $p = RelDynProtocols::config();
            $this->assertSame(2, RelDynProtocols::phaseAt(24.0, 1.0, $p)[0]);
            $this->assertSame(24.0, $p['grief']['phase_game_hours'][2], 'the stored table replaces the default one');
            $this->assertSame(-15.0, $p['grief']['acute_comfort'], 'other settings keep their defaults');
            $this->assertSame(24.0, $p['divine']['unstable_window_game_hours'], 'other sections keep theirs');
        } finally {
            RelationshipDynamics::endRequest();
        }
    }
}
