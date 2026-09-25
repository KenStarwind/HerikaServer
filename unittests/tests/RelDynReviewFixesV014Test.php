<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** The RelDyn config row only (conf_opts); every other statement answers empty and is recorded. */
final class RelDynReviewFixesV014ConfDb
{
    public array $confOpts = [];
    public array $other = [];

    public function fetchOne($q, array $params = [])
    {
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", (string) $q, $m)) {
            return isset($this->confOpts[$m[1]]) ? ['value' => $this->confOpts[$m[1]]] : [];
        }
        $this->other[] = (string) $q;
        return [];
    }

    public function fetchAll($q, $log = false) { $this->other[] = (string) $q; return []; }
    public function execQuery($q) { $this->other[] = (string) $q; return false; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . str_replace("'", "''", (string) $s) . "'"; }
}

/**
 * The v0.14 review's confirmed findings, one test per finding (no database beyond the config
 * row; the hooks, the eval worker and the four test beds are RelDynReviewFixesV014TestBedsPostgresTest):
 *   - resentment-self: the confession (-10) is the NPC telling what she is ashamed of: it needs the
 *     self-reflection to have come up (dimension draft: the Director's self-reflection at 50, then
 *     she tells), and it is said once; the player confiding his own troubles is not her confession;
 *   - guilt-bleed / creatures / physical states: a held offset is neutral to the physics (the rubber
 *     band reads the value without it), so lifting it exactly leaves the NPC where she would be
 *     without it;
 *   - per-bond-type-modifiers: held offsets sit outside the per-bond display multiplier (the guilt
 *     reaches the felt text of a partner), and the eval reads trust and comfort as the actor plays them;
 *   - resentment-confrontation: only a calm (mature) confrontation resolves the ick's condition;
 *   - baseline-drift: the guilt offset is not sampled as who she is, and resentment_self's baseline
 *     offsets neither re-anchor the drift origin nor move it;
 *   - walkaway-boundary: no "leaving" text or walkaway action filter while no walkaway can start
 *     (walkaway_enabled off, the return grace); the grace is the recovery the LLM hears;
 *   - charisma: an exchange window with no romantic intent grades no style, a null reading clears
 *     the old one, and the Rock is effective against an Overcast NPC (MDD 5.1);
 *   - social-sensitivity: the cascade reads the target's bond on the mirror scale again;
 *   - eval-extra-fields: the Ick is fed once per applied eval item, with the NPC's reply mood of
 *     that exchange (carried by the job), never by peeking at the inbox.
 */
final class RelDynReviewFixesV014Test extends TestCase
{
    private const T0 = 300 * RelationshipDynamics::GAMETS_PER_DAY;       // raw gamets, day 300
    private const PLAY = 100 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
    private const PLAY_MIN = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;

    private RelDynReviewFixesV014ConfDb $db;
    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::T0, 'Kaida: hello'];
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-rev14-');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        $this->db = new RelDynReviewFixesV014ConfDb();
        $GLOBALS['db'] = $this->db;
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function setConfig(array $overrides): void
    {
        $this->db->confOpts[RelationshipDynamics::CONFIG_ROW_ID] = json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides));
        RelationshipDynamics::clearConfigCache();
    }

    /** A partner (core romantic, core affinity $aff) with dimension x values from $dims. */
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
        $dims += ['maturity' => 50.0, 'self_confidence' => 50.0, 'comfort' => 50.0, 'resentment' => 0.0, 'resentment_self' => 0.0];
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

    private function confiding(array &$d): array
    {
        return RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['positive_interaction' => true, 'tags' => ['confiding']]), $d);
    }

    // ------------------------------------------------------------ resentment-self

    /**
     * Lynly at resentment_self 80, the player telling her about his father again and again
     * (tagged confiding): before the self-reflection has come up nothing she confessed (only the
     * rate-limited decay); once it has come up (said to the player), her first opening up is the
     * confession (-10), and it is said once: the next ones are the decay again, on the play clock.
     */
    public function testTheConfessionFollowsTheReflectionAndIsSaidOnce(): void
    {
        $d = $this->npc(['resentment_self' => 80.0, 'maturity' => 50.0, 'comfort' => 40.0]);
        $trace = [];
        for ($i = 0; $i < 8; $i++) {
            $this->confiding($d);
            $trace[] = self::x($d, 'resentment_self');
        }
        $this->assertEqualsWithDelta(80.0 - 0.75, self::x($d, 'resentment_self'), 1e-9,
            'no reflection yet: the player confiding is not her confession; one decay, rate-limited ' . json_encode($trace));

        $this->assertContains('reflection', RelDynResentment::tickSelf('Lydia', $d));
        $this->confiding($d);
        $this->assertEqualsWithDelta(80.0 - 0.75, self::x($d, 'resentment_self'), 1e-9, 'queued, not yet said to the player');
        $out = RelDynResentment::takeFeltLines($d, 'Lydia', 'Kaida', self::T0);
        $this->assertSame(['reflection'], array_column($out['lines'], 'key'));

        $before = self::x($d, 'resentment_self');
        $f = $this->confiding($d);
        $this->assertEqualsWithDelta($before - 10.0, self::x($d, 'resentment_self'), 1e-9, 'she tells: the confession, -10');
        $this->assertEqualsWithDelta(10.0, $f['resentment_self_relief'], 1e-9);
        for ($i = 0; $i < 6; $i++) $this->confiding($d);
        $this->assertEqualsWithDelta($before - 10.0, self::x($d, 'resentment_self'), 1e-9, 'said once: no second -10, the decay waits for the play clock');
        $d['_accumulated_play_gamets'] += 15 * self::PLAY_MIN;
        $this->confiding($d);
        $this->assertEqualsWithDelta($before - 10.0 - 0.75, self::x($d, 'resentment_self'), 1e-9, 'then only the decay');

        // Worked through (30 or below): the next episode's reflection opens the next confession
        $d['dimensions']['resentment_self']['x'] = 25.0;
        RelDynResentment::tickSelf('Lydia', $d);
        $d['dimensions']['resentment_self']['x'] = 60.0;
        RelDynResentment::tickSelf('Lydia', $d);
        RelDynResentment::takeFeltLines($d, 'Lydia', 'Kaida', self::T0);
        $d['_accumulated_play_gamets'] += 15 * self::PLAY_MIN;
        $this->confiding($d);
        $this->assertEqualsWithDelta(60.0 - 0.75 - 10.0, self::x($d, 'resentment_self'), 1e-9);
    }

    // ------------------------------------------------------------ guilt-bleed (held offsets)

    /**
     * The same exchanges with and without a held comfort offset: once it is lifted exactly, the
     * NPC is where she would be without it (the rubber band reads comfort without the offset), for
     * the guilt bleed, a creature row and a physical state alike.
     */
    public function testAHeldOffsetIsNeutralOnceLifted(): void
    {
        $run = function (?string $holder): float {
            $d = $this->npc(['comfort' => 34.0]);
            $d['dimensions']['comfort']['baseline'] = 50.0;
            if ($holder === 'guilt') {
                $d['dimensions']['resentment_self']['x'] = 60.0;
                RelDynResentment::tickGuiltBleed('Lydia', $d);
                $this->assertEqualsWithDelta(-15.0, $d['_resentment_arc']['guilt']['applied'], 1e-9);
                $d['dimensions']['resentment_self']['x'] = 0.0;   // lifted at the next tick, not before
            } elseif ($holder === 'creature') {
                $d['dimensions']['comfort']['x'] -= 10.0;
                $d[RelDynCreatures::STATE_KEY] = ['applied' => ['comfort' => -10.0], 'key' => 'vampire_day|x'];
            } elseif ($holder === 'physical') {
                $d['dimensions']['comfort']['x'] -= 10.0;
                $d['_active_physical_states'] = ['cold'];
                $d['_applied_physical_deltas'] = ['cold' => ['comfort' => -10.0]];
            }
            for ($i = 0; $i < 12; $i++) RelationshipDynamics::applyDelta('comfort', $d, 3.0, 'Stoic');
            if ($holder === 'guilt') {
                RelDynResentment::tickGuiltBleed('Lydia', $d);
                $this->assertSame(0.0, $d['_resentment_arc']['guilt']['applied']);
            } elseif ($holder === 'creature') {
                RelationshipDynamics::reverseAppliedDeltas($d, $d[RelDynCreatures::STATE_KEY]['applied'], '', 'creature');
            } elseif ($holder === 'physical') {
                RelationshipDynamics::clearPhysicalStateModifiers($d, [], 'Stoic');
            }
            return self::x($d, 'comfort');
        };
        $none = $run(null);
        foreach (['guilt', 'creature', 'physical'] as $holder) {
            $this->assertEqualsWithDelta($none, $run($holder), 1e-3, "{$holder}: lifted, the NPC is where she would be without it");
        }
    }

    // ------------------------------------------------------------ per-bond-type-modifiers

    /**
     * A partner's comfort shows through the per-bond display multiplier; the guilt bleed is held
     * on it outside the multiplier: the felt value drops by exactly the guilt, where before the
     * multiplier saturated it away (raw 53 with -15 held read as 100, the same as no guilt).
     */
    public function testTheGuiltReachesAPartnersFeltComfort(): void
    {
        $guilt = $this->npc(['comfort' => 68.0, 'resentment_self' => 60.0]);
        RelDynResentment::tickGuiltBleed('Lydia', $guilt);
        $this->assertEqualsWithDelta(53.0, self::x($guilt, 'comfort'), 1e-9);
        $none = $this->npc(['comfort' => 68.0]);
        $mult = RelationshipDynamics::perBondMultiplier($none, 'comfort');
        $this->assertGreaterThan(1.9, $mult, 'a partner');
        $this->assertSame(100.0, RelationshipDynamics::getEffectiveDimensionValue($none, 'comfort'));
        $this->assertEqualsWithDelta(85.0, RelationshipDynamics::getEffectiveDimensionValue($guilt, 'comfort'), 1e-9, 'the guilt, felt');
        $this->assertNotSame(RelationshipDynamics::getDimensionBand('comfort', 100.0)['label'],
            RelationshipDynamics::getDimensionBand('comfort', RelationshipDynamics::getEffectiveDimensionValue($guilt, 'comfort'))['label']);
        // A value passed in (a baseline) is read as it is
        $this->assertEqualsWithDelta(min(100.0, 30.0 * $mult), RelationshipDynamics::getEffectiveDimensionValue($guilt, 'comfort', 30.0), 1e-9);
    }

    /** The eval reads trust and comfort as the actor plays them (the per-bond display value). */
    public function testTheEvalSeesTrustAndComfortAsTheActorPlaysThem(): void
    {
        $d = $this->npc(['trust' => 45.0, 'comfort' => 40.0]);
        $lines = RelDynEval::stateSummary('Lydia', $d);
        $trust = RelationshipDynamics::getDimensionBand('trust', RelationshipDynamics::getEffectiveDimensionValue($d, 'trust'));
        $comfort = RelationshipDynamics::getDimensionBand('comfort', RelationshipDynamics::getEffectiveDimensionValue($d, 'comfort'));
        $this->assertNotSame('Uncertain', $trust['label'], 'a partner at raw 45 is not played as uncertain');
        $this->assertContains("Trust in the player: {$trust['label']} ({$trust['keywords']})", $lines, json_encode($lines));
        $this->assertContains("Comfort with the player: {$comfort['label']} ({$comfort['keywords']})", $lines, json_encode($lines));
    }

    // ------------------------------------------------------------ resentment-confrontation

    /** Meant evenly but coming out as an accusation is not calm: relief, but the ick's condition stays open. */
    public function testOnlyACalmConfrontationResolves(): void
    {
        $mixed = $this->npc(['resentment' => 55.0, 'maturity' => 52.0]);
        $this->assertSame('mixed', RelDynConcern::expression($mixed, RelDynConcern::traitsOf($mixed))['band']);
        $this->assertSame(['confrontation_due'], RelDynResentment::tickConfrontation('Lydia', $mixed, self::T0));
        $out = RelDynResentment::takeFeltLines($mixed, 'Lydia', 'Kaida', self::T0);
        $this->assertStringContainsString('means to say it evenly', $out['lines'][0]['text']);
        $this->assertSame(45.0, self::x($mixed, 'resentment'), 'said: the addressed decay');
        $this->assertArrayNotHasKey('_ick_confrontation_resolved', $mixed, 'not calm: not resolved');

        $calm = $this->npc(['resentment' => 55.0, 'maturity' => 75.0]);
        RelDynResentment::tickConfrontation('Lydia', $calm, self::T0);
        RelDynResentment::takeFeltLines($calm, 'Lydia', 'Kaida', self::T0);
        $this->assertTrue($calm['_ick_confrontation_resolved']);
    }

    // ------------------------------------------------------------ baseline-drift

    /** The guilt held on comfort is not who she is: the drift sample leaves it out. */
    public function testTheGuiltIsNotSampledAsWhoSheIs(): void
    {
        $d = $this->npc(['comfort' => 50.0, 'resentment_self' => 60.0]);
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(35.0, self::x($d, 'comfort'), 1e-9);
        $this->assertEqualsWithDelta(-15.0, RelationshipDynamics::heldTemporaryOffset($d, 'comfort'), 1e-9);
        $this->assertEqualsWithDelta(50.0, RelationshipDynamics::driftSampleValue($d, 'comfort'), 1e-9);

        // Days of guilt at a baseline of 50 move nothing; lifted, comfort and baseline are both 50
        $d['dimensions']['comfort']['baseline'] = 50.0;
        for ($day = 10; $day <= 15; $day++) {
            RelationshipDynamics::recordBaselineDriftSample($d, $day * RelationshipDynamics::GAMETS_PER_DAY + 1000);
            RelationshipDynamics::processBaselineDrift('Lydia', $d);
        }
        $d['dimensions']['resentment_self']['x'] = 0.0;
        RelDynResentment::tickGuiltBleed('Lydia', $d);
        $this->assertEqualsWithDelta(50.0, self::x($d, 'comfort'), 1e-9);
        $this->assertEqualsWithDelta(50.0, floatval($d['dimensions']['comfort']['baseline']), 1e-9, 'the global baseline did not take the guilt');
    }

    /**
     * resentment_self's comfort offset (-5 above 30) is a state on the baseline, not a new
     * origin: drift runs on her own baseline, keeps its origin and bound, and the offset lifts
     * back onto the drifted value.
     */
    public function testTheSelfOffsetDoesNotReanchorTheDrift(): void
    {
        $d = $this->npc(['comfort' => 70.0]);
        $d['dimensions']['comfort']['baseline'] = 50.0;
        $day = 20;
        $drift = function () use (&$d, &$day): void {
            RelationshipDynamics::recordBaselineDriftSample($d, $day++ * RelationshipDynamics::GAMETS_PER_DAY + 1000);
            RelationshipDynamics::processBaselineDrift('Lydia', $d);
        };
        for ($i = 0; $i < 3; $i++) $drift();
        $own1 = 50.0 + (70.0 - 50.0) * RelationshipDynamics::BASELINE_DRIFT_RATE;
        $this->assertEqualsWithDelta($own1, floatval($d['dimensions']['comfort']['baseline']), 1e-9);
        $this->assertEqualsWithDelta(50.0, $d['_baseline_drift_origin']['comfort']['origin'], 1e-9);

        // The shame arrives: her baseline reads 5 lower, the drift's origin stays hers
        $d['dimensions']['resentment_self']['x'] = 35.0;
        RelDynResentment::tickSelf('Lydia', $d);
        $this->assertEqualsWithDelta($own1 - 5.0, floatval($d['dimensions']['comfort']['baseline']), 1e-9);
        $drift();
        $own2 = $own1 + (70.0 - $own1) * RelationshipDynamics::BASELINE_DRIFT_RATE;
        $this->assertEqualsWithDelta(50.0, $d['_baseline_drift_origin']['comfort']['origin'], 1e-9, 'not re-anchored on the offset');
        $this->assertEqualsWithDelta($own2 - 5.0, floatval($d['dimensions']['comfort']['baseline']), 1e-9, 'drifted on her own baseline, offset kept');
        $this->assertEqualsWithDelta($own2, $d['_baseline_drift_origin']['comfort']['at'], 1e-9, 'the drift remembers her own value');

        // Lifted: her own drifted baseline, and the next drift goes on from the same origin
        $d['dimensions']['resentment_self']['x'] = 0.0;
        RelDynResentment::tickSelf('Lydia', $d);
        $this->assertEqualsWithDelta($own2, floatval($d['dimensions']['comfort']['baseline']), 1e-9);
        $drift();
        $this->assertEqualsWithDelta(50.0, $d['_baseline_drift_origin']['comfort']['origin'], 1e-9);
        $this->assertEqualsWithDelta($own2 + (70.0 - $own2) * RelationshipDynamics::BASELINE_DRIFT_RATE,
            floatval($d['dimensions']['comfort']['baseline']), 1e-9);
    }

    // ------------------------------------------------------------ walkaway-boundary

    /**
     * Back from a walkaway (the return grace) with core affinity at -20: no walkaway can start,
     * so the LLM hears the return (the recovery text), not "they are leaving", and only the
     * refusing actions are held back. The grace counts contacts: the contact that spends the
     * last one is still held; the next one starts the walkaway.
     */
    public function testNoLeavingWhileTheReturnGraceHolds(): void
    {
        $d = $this->npc([], ['context_tier_hwm' => 2, '_walkaway_state' => 'normal', '_walkaway_return_grace' => 1,
            '_last_contact_gamets' => (float) self::T0], -20.0);
        $e = RelationshipDynamics::evaluateAutonomyState($d, 'Stoic');
        $this->assertSame(['refusing', true, 'grace'], [$e['state'], $e['walkaway_due'], $e['walkaway_held']], json_encode($e));
        $this->assertNotContains('ComeCloser', $e['deny_actions']);
        $text = (string) RelationshipDynamics::getAutonomyContext($d, 'Lydia', 'Stoic');
        $this->assertStringContainsString('has returned because they chose to', $text);
        $this->assertStringNotContainsString('They are leaving', $text);

        RelationshipDynamics::initiateWalkaway($d, 'Lydia', RelationshipDynamics::walkawayReason($d));
        $this->assertSame(['normal', 0], [$d['_walkaway_state'], $d['_walkaway_return_grace']]);
        $this->assertSame('refusing', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state'], 'this contact spent the last grace: still held');

        $d['_last_contact_gamets'] = (float) self::T0 + 5000;   // the next contact
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state']);
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', RelationshipDynamics::walkawayReason($d));
        $this->assertSame('pending', $d['_walkaway_state']);
        $this->assertStringContainsString('They are leaving', (string) RelationshipDynamics::getAutonomyContext($d, 'Lydia', 'Stoic'));
    }

    /** walkaway_enabled off: a walkaway is due but never starts, so nobody tells the LLM she is leaving. */
    public function testWalkawayOffMeansNoLeavingText(): void
    {
        $this->setConfig(['walkaway_enabled' => false]);
        foreach ([
            'affinity' => $this->npc([], ['context_tier_hwm' => 2], -20.0),
            'shame'    => $this->npc(['resentment_self' => 95.0]),
            'resentment' => $this->npc(['resentment' => 92.0]),
        ] as $why => $d) {
            $e = RelationshipDynamics::evaluateAutonomyState($d, 'Stoic');
            $this->assertSame([true, 'disabled'], [$e['walkaway_due'], $e['walkaway_held']], "{$why}: " . json_encode($e));
            $this->assertNotSame('walkaway', $e['state'], $why);
            $this->assertNotContains('ComeCloser', $e['deny_actions'], $why);
            $text = (string) RelationshipDynamics::getAutonomyContext($d, 'Lydia', 'Stoic');
            $this->assertStringNotContainsString('leaving', $text, $why);
            $this->assertStringNotContainsString('pulling away to be alone', $text, $why);
        }
        // Already walking away (started before it was switched off): it goes on
        $d = $this->npc([], ['_walkaway_state' => 'active']);
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state']);
    }

    // ------------------------------------------------------------ charisma

    /**
     * MDD 5.1 "no press X to flirt": the style is the flavour of the player's approach. Five
     * exchanges with no romantic intent at all grade no style (the steady non-flirting player was
     * read as the Rock and every affinity signal of a Bold NPC cut to 0.7); a window that no
     * longer reads as any style clears the old label.
     */
    public function testNoRomanticIntentGradesNoStyleAndANullReadingClearsIt(): void
    {
        $this->assertNull(RelationshipDynamics::detectCharismaStyle([0, 0, 0, 0, 0], [1, 2, 0, 1, 1]));
        $this->assertNull(RelationshipDynamics::detectCharismaStyle([0, 0, 0, 0, 0], [-1, 0, -2, 0, -1]));
        $this->assertSame('rock', RelationshipDynamics::detectCharismaStyle([1, 0, 0, 1, 0], [1, 1, 0, 1, 1])['style'] ?? null, 'low, steady intent: the Rock');

        $d = $this->npc();
        foreach ([[1, 1], [0, 1], [0, 0], [1, 1], [0, 1]] as [$intent, $aff]) {
            RelationshipDynamics::updateCharismaTracker($d, $intent, $aff, self::T0);
        }
        $this->assertSame('rock', RelationshipDynamics::charismaStyle($d));
        foreach (array_fill(0, RelationshipDynamics::CHARISMA_WINDOW, [0, 1]) as [$intent, $aff]) {
            RelationshipDynamics::updateCharismaTracker($d, $intent, $aff, self::T0 + 100);
        }
        $this->assertNull(RelationshipDynamics::charismaStyle($d), 'the window reads as no style now: the old label goes');
        $this->assertSame(1.0, RelationshipDynamics::getCharismaEffectiveness(RelationshipDynamics::charismaStyle($d), 'Bold', 50.0, 'affinity', $d));
    }

    /** MDD 5.1: the Rock is effective against Anxious and Overcast NPCs (internal weather overcast). */
    public function testTheRockSteadiesAnOvercastNpc(): void
    {
        $d = $this->npc([], ['inferred_temperament' => 'Stoic', '_internal_weather' => 'clear']);
        $clear = RelationshipDynamics::getCharismaEffectiveness('rock', 'Stoic', 50.0, 'affinity', $d);
        $d['_internal_weather'] = 'overcast';
        $this->assertSame(1.3, RelationshipDynamics::getCharismaEffectiveness('rock', 'Stoic', 50.0, 'affinity', $d), "overcast (clear: {$clear})");
        $this->assertSame(1.0, RelationshipDynamics::getCharismaEffectiveness('charmer', 'Stoic', 50.0, 'affinity', $d), 'only the Rock');
    }

    // ------------------------------------------------------------ social-sensitivity (cascade)

    /**
     * The cascade (word of the player reaching a friend of the source) reads the target's bond on
     * the mirror scale, as before the eval path moved to core affinity: a Stoic, Guarded or
     * Nurturing target who has never met the player (core 0) still hears it (bond 50), not nothing.
     */
    public function testTheCascadeStillReachesANeutralTarget(): void
    {
        foreach (['Stoic', 'Guarded', 'Nurturing'] as $t) {
            $target = $this->npc([], ['inferred_temperament' => $t], 0.0);
            $this->assertEqualsWithDelta(50.0, RelationshipDynamics::cascadeBondLevel($target), 1e-9, $t);
            $cascade = RelationshipDynamics::cascadeSocialSensitivity($target, 10.0, $t);
            $expected = 10.0 * RelationshipDynamics::socialSensitivityFactor($target, 'affinity', false, $t, 50.0);
            $this->assertEqualsWithDelta($expected, $cascade, 1e-9, $t);
            $this->assertGreaterThanOrEqual(1.0, abs($cascade), "{$t}: not dropped by the <1 skip");
        }
        // The eval path keeps core affinity as the bond (the baselines lane's choice)
        $this->assertSame(0.0, RelationshipDynamics::socialSensitivityBondLevel($this->npc([], [], 0.0)));
    }

    // ------------------------------------------------------------ eval-extra-fields (the Ick)

    /**
     * The Ick reads romantic_intent once per applied eval item, with the NPC's reply mood of
     * that exchange (carried by the item): a clear courting she did not answer in kind is an
     * attempt; one she answered flirty is not; an exchange already counted as touch at the
     * postrequest is not counted twice.
     */
    public function testTheIckIsFedPerAppliedItemWithThatExchangesMood(): void
    {
        $d = $this->npc(['comfort' => 30.0], ['interaction_count' => 3]);
        foreach ([1000, 2000, 3000] as $g) {
            RelationshipDynamics::updateIckTracker($d, $g === 3000, 'Stoic', (float) (self::T0 + $g));   // 3000: touch at the postrequest
        }
        $this->assertSame([3, 1], [$d['_ick_tracker']['total_count'], $d['_ick_tracker']['romantic_count']]);

        $apply = function (int $g, int $intent, ?string $mood) use (&$d): void {
            $item = $this->item(['gamets' => self::T0 + $g, 'romantic_intent' => $intent, 'summary' => "exchange {$g}"]);
            if ($mood !== null) $item['reply_mood'] = $mood;
            RelationshipDynamics::applyEvalExtraFields('Lydia', $item, $d, (float) (self::T0 + $g));
        };
        $apply(1000, 3, 'default');
        $this->assertSame(2, $d['_ick_tracker']['romantic_count'], 'courting she did not answer in kind');
        $apply(2000, 3, 'flirty');
        $this->assertSame(2, $d['_ick_tracker']['romantic_count'], 'she flirted back: reciprocated');
        $apply(3000, 3, 'default');
        $this->assertSame(2, $d['_ick_tracker']['romantic_count'], 'already counted as touch');
        $apply(1000, 3, 'default');
        $this->assertSame(2, $d['_ick_tracker']['romantic_count'], 'one item, one count');
        $apply(4000, 1, 'default');
        $this->assertSame(2, $d['_ick_tracker']['romantic_count'], 'a light flirt is not pressure');
        $this->assertLessThanOrEqual($d['_ick_tracker']['total_count'], $d['_ick_tracker']['romantic_count']);
    }

    /** The eval job carries the NPC's reply mood of its exchange; the item carries it on (code, not the LLM). */
    public function testTheItemCarriesTheReplyMoodOfItsExchange(): void
    {
        $raw = json_encode([
            'signals' => ['affinity' => 1, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.2, 'summary' => 'He flirted.', 'romantic_intent' => 2,
        ]);
        $job = ['npc' => 'Lydia', 'npc_id' => 7, 'player_name' => 'Kaida', 'gamets' => self::T0, 'event_tags' => [], 'reply_mood' => 'Flirty'];
        $item = RelDynEval::parseResponse($raw, $job);
        $this->assertSame('flirty', $item['reply_mood'] ?? null);
        unset($job['reply_mood']);
        $this->assertArrayNotHasKey('reply_mood', RelDynEval::parseResponse($raw, $job), 'an older job: unknown');
    }
}
