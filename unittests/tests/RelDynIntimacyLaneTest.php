<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql` stand-in: a stored RelDyn config row and core_npc_master rows (extended_data as the
 * text PostgreSQL returns); every other read finds nothing.
 */
final class RelDynIntimacyLaneDb
{
    public function __construct(private array $config, private array $rows = []) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (str_contains($sql, "conf_opts WHERE id = 'relationship_dynamics_config'")) return ['value' => json_encode($this->config)];
        if (str_contains($sql, 'FROM core_npc_master') && is_array($params)) {
            foreach ($this->rows as $name => $row) {
                if (strcasecmp((string) ($params[0] ?? ''), $name) === 0) return $row;
            }
        }
        return [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * The p4r intimacy lane, pure paths (real engine code; the database is a stub):
 *   post-intimacy       the outcome of an encounter from its context (the draft's context table,
 *                       the audit's outcome names), the afterglow held and taken back exactly, the
 *                       sober self's correction (only when sober, scaled by maturity, never for a
 *                       shallow mind), a load from before the encounter, felt text without numbers
 *   mf-coordinates      the coordinates derived at display time (Fix 6: respect + self-confidence,
 *                       trust + comfort, x maturity, the traits engine's directional Y)
 *   arousal-valence     what events left on arousal / valence settles with time (held states
 *                       stay), the eval reads the band, the social feed is off by default
 *   gift-delta-formula  base, primary / secondary love language, the stolen marker, the stolen
 *                       inversion
 */
final class RelDynIntimacyLaneTest extends TestCase
{
    private const HOUR = RelationshipDynamics::GAMETS_PER_HOUR;
    private const T0 = 300 * RelationshipDynamics::GAMETS_PER_DAY + 20 * self::HOUR;
    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->useDb();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdintlane');
        $this->prevLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
    }

    private function useDb(array $overrides = [], array $rows = []): void
    {
        $GLOBALS['db'] = new RelDynIntimacyLaneDb(array_replace(RelationshipDynamics::defaultConfig(), $overrides), $rows);
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC of $temperament, core type $core toward the player, with dimension x values $x. */
    private function npc(string $temperament, string $core, array $x = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, $core);
        foreach ($x as $dim => $v) $d['dimensions'][$dim]['x'] = $v;
        return $d;
    }

    private function scene(string $npc): array
    {
        return ['ext_nsfw_sexcene', '1727000000', (string) self::T0, "OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/{$npc}^sub,vaginal"];
    }

    // ------------------------------------------------------------------ post-intimacy: the outcome

    public function testTheOutcomeIsTheContextNotTheAct(): void
    {
        $cfg = RelDynPostIntimacy::config();
        $base = ['bonded' => false, 'romance' => false, 'trust' => 45.0, 'maturity' => 60.0, 'intoxicated' => false,
                 'avoidance' => 0.3, 'anxiety' => 0.2, 'other_partners' => [], 'core_type' => ''];
        $case = fn(array $c) => RelDynPostIntimacy::outcome(array_replace($base, $c), $cfg);
        $this->assertSame(RelDynPostIntimacy::BONDED, $case(['bonded' => true, 'romance' => true, 'trust' => 70.0]));
        // the same partner drunk: a deep bond is still a deep bond; a shallower one regrets
        $this->assertSame(RelDynPostIntimacy::BONDED, $case(['bonded' => true, 'romance' => true, 'trust' => 70.0, 'intoxicated' => true]));
        $this->assertSame(RelDynPostIntimacy::DRUNK, $case(['bonded' => true, 'romance' => true, 'trust' => 40.0, 'intoxicated' => true]));
        $this->assertSame(RelDynPostIntimacy::DRUNK, $case(['intoxicated' => true]));
        $this->assertSame(RelDynPostIntimacy::COMMITTED, $case(['romance' => true, 'core_type' => 'crush']));
        $this->assertSame(RelDynPostIntimacy::AVOIDANT, $case(['romance' => true, 'avoidance' => 0.7]));
        // who she is in closeness before how deep the bond runs (earned security lowers the axes)
        $this->assertSame(RelDynPostIntimacy::AVOIDANT, $case(['bonded' => true, 'romance' => true, 'trust' => 70.0, 'avoidance' => 0.7]));
        // the fearful reading of "manipulated" is opt-in (batch-R: the draft keys that row on maturity, not attachment)
        $this->assertSame(RelDynPostIntimacy::BONDED, $case(['bonded' => true, 'romance' => true, 'trust' => 70.0, 'anxiety' => 0.6, 'avoidance' => 0.55]));
        $fearful = fn(array $c) => RelDynPostIntimacy::outcome(array_replace($base, $c), ['fearful_vulnerable' => true] + $cfg);
        $this->assertSame(RelDynPostIntimacy::VULNERABLE, $fearful(['bonded' => true, 'romance' => true, 'trust' => 70.0, 'anxiety' => 0.6, 'avoidance' => 0.55]));
        $this->assertSame(RelDynPostIntimacy::COMMITTED, $fearful(['romance' => true, 'anxiety' => 0.6, 'avoidance' => 0.3]), 'anxious alone is not fear of it');
        $this->assertSame(RelDynPostIntimacy::VULNERABLE, $case(['trust' => 20.0]));
        $this->assertSame(RelDynPostIntimacy::VULNERABLE, $case(['maturity' => 25.0]));
        $this->assertSame(RelDynPostIntimacy::CASUAL, $case([]));
        // she has a partner who is not the player
        $this->assertSame(RelDynPostIntimacy::CHEATING, $case(['other_partners' => ['Mikael']]));
        $this->assertSame(RelDynPostIntimacy::BONDED, $case(['bonded' => true, 'romance' => true, 'trust' => 70.0, 'other_partners' => ['Mikael']]));
    }

    public function testShameScalesWithMaturityWithinTheDraftRange(): void
    {
        $this->assertEqualsWithDelta(12.0, RelDynPostIntimacy::resentmentSelf(12.0, 65.0), 1e-9);   // the draft's Aela at 65
        $this->assertEqualsWithDelta(6.0, RelDynPostIntimacy::resentmentSelf(12.0, 20.0), 1e-9);
        $this->assertEqualsWithDelta(15.0, RelDynPostIntimacy::resentmentSelf(15.0, 90.0), 1e-9);   // kept within +5..+15
        $this->assertEqualsWithDelta(5.0, RelDynPostIntimacy::resentmentSelf(8.0, 20.0), 1e-9);
        $this->assertSame(0.0, RelDynPostIntimacy::resentmentSelf(0.0, 65.0));
    }

    public function testOnlyAPartnerWhoIsNotThePlayerMakesItCheating(): void
    {
        $this->assertSame(['Mikael'], RelDynPostIntimacy::otherPartners([
            'Kaida' => ['aff' => 40, 'type' => 'friend'], 'Mikael' => ['aff' => 60, 'type' => 'romantic'], 'Ysolda' => ['aff' => 20, 'type' => 'platonic'],
        ]));
        $this->assertSame([], RelDynPostIntimacy::otherPartners(['Player' => ['aff' => 70, 'type' => 'romantic']]));
    }

    // ------------------------------------------------------------------ post-intimacy: the aftermath

    public function testTheAfterglowIsHeldForTwoGameHoursAndTakenBackExactly(): void
    {
        $d = $this->npc('Romantic', 'romantic', ['trust' => 80.0, 'comfort' => 40.0, 'maturity' => 60.0]);
        $comfort = $d['dimensions']['comfort']['x'];
        $trust = $d['dimensions']['trust']['x'];
        $arousal = $d['dimensions']['arousal']['x'];
        $this->assertSame(RelDynPostIntimacy::BONDED, RelDynPostIntimacy::onIntimateRequest('Lydia', $d, $this->scene('Lydia'), 'Kaida', self::T0));
        $state = $d[RelDynPostIntimacy::KEY];
        $this->assertGreaterThan(0.0, $state['held']['comfort']);
        $this->assertEqualsWithDelta($comfort + $state['held']['comfort'], $d['dimensions']['comfort']['x'], 1e-6);
        $this->assertEqualsWithDelta($state['held']['comfort'], RelationshipDynamics::heldTemporaryOffset($d, 'comfort'), 1e-6, 'a state held on comfort');
        $this->assertGreaterThan($trust, $d['dimensions']['trust']['x'], 'chose to be vulnerable with them: trust +3, lasting');
        $this->assertGreaterThan($arousal, $d['dimensions']['arousal']['x'], 'the horseshoe: an arousal spike');
        $this->assertGreaterThan(0.0, $d['dimensions']['valence']['x'], '... read as warmth in this context');

        // a later stage of the same scene extends the encounter, it is not a second one
        $this->assertNull(RelDynPostIntimacy::onIntimateRequest('Lydia', $d, $this->scene('Lydia'), 'Kaida', self::T0 + 0.5 * self::HOUR));
        $felt = RelDynPostIntimacy::feltText($d, self::T0 + 2.0 * self::HOUR);
        $this->assertSame('glow', $felt['phase']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt['text']);

        $r = RelDynPostIntimacy::tick('Lydia', $d, self::T0 + 2.6 * self::HOUR);
        $this->assertSame($state['held'], $r['released']);
        $this->assertEqualsWithDelta($comfort, $d['dimensions']['comfort']['x'], 1e-6, 'the glow is taken back exactly');
        $this->assertTrue($r['ended']);
        $this->assertArrayNotHasKey(RelDynPostIntimacy::KEY, $d);
        $this->assertNull(RelDynPostIntimacy::feltText($d, self::T0 + 2.6 * self::HOUR));
    }

    public function testTheDrunkenNightIsJudgedBySoberMorning(): void
    {
        $d = $this->npc('Bold', 'neutral', ['trust' => 30.0, 'comfort' => 30.0, 'maturity' => 65.0]);
        $d['_active_consumables'] = [['key' => 'ale', 'item_name' => 'Nord Mead', 'immediate' => [], 'expires_gamets' => self::T0 + 10 * self::HOUR, 'applied_gamets' => self::T0]];
        $comfort = $d['dimensions']['comfort']['x'];
        $rs = floatval($d['dimensions']['resentment_self']['x'] ?? 0);
        $this->assertSame(RelDynPostIntimacy::DRUNK, RelDynPostIntimacy::onIntimateRequest('Aela', $d, $this->scene('Aela'), 'Kaida', self::T0));
        $this->assertSame('glow', RelDynPostIntimacy::feltText($d, self::T0 + self::HOUR)['phase'], 'in the moment');

        // six game hours on she is still drinking: the sober self has not spoken
        $r = RelDynPostIntimacy::tick('Aela', $d, self::T0 + 7 * self::HOUR);
        $this->assertSame([], $r['corrected']);
        $this->assertNotEmpty($r['released'], 'the glow is over');
        // sober: the correction lands, below where she started
        $d['_active_consumables'] = [];
        $r = RelDynPostIntimacy::tick('Aela', $d, self::T0 + 11 * self::HOUR);
        $this->assertLessThan(0.0, $r['corrected']['comfort']);
        $this->assertLessThan($comfort, $d['dimensions']['comfort']['x'], 'crashed past where she started');
        $this->assertGreaterThan($rs, floatval($d['dimensions']['resentment_self']['x']), 'the stranger row: shame');
        $this->assertArrayNotHasKey('trust', $r['corrected'], 'a stranger she regrets: trust unchanged');
        $this->assertLessThan(0.0, $r['corrected']['valence'], 'the same night now reads negative');
        $felt = RelDynPostIntimacy::feltText($d, self::T0 + 12 * self::HOUR);
        $this->assertSame('after', $felt['phase']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt['text']);
        // the feeling lasts its window, then the state ends
        $this->assertTrue(RelDynPostIntimacy::tick('Aela', $d, self::T0 + 36 * self::HOUR)['ended']);
    }

    public function testAShallowMindNeverLooksBack(): void
    {
        $d = $this->npc('Playful', 'neutral', ['trust' => 30.0, 'comfort' => 30.0, 'maturity' => 15.0]);
        $d['_active_consumables'] = [['key' => 'ale', 'item_name' => 'Ale', 'immediate' => [], 'expires_gamets' => self::T0 + self::HOUR, 'applied_gamets' => self::T0]];
        $this->assertSame(RelDynPostIntimacy::DRUNK, RelDynPostIntimacy::onIntimateRequest('Mikaela', $d, $this->scene('Mikaela'), 'Kaida', self::T0));
        $d['_active_consumables'] = [];
        $rs = floatval($d['dimensions']['resentment_self']['x'] ?? 0);
        $r = RelDynPostIntimacy::tick('Mikaela', $d, self::T0 + 7 * self::HOUR);
        $this->assertSame([], $r['corrected']);
        $this->assertEqualsWithDelta($rs, floatval($d['dimensions']['resentment_self']['x'] ?? 0), 1e-9);
        $this->assertTrue($r['ended'], 'nothing held, nothing pending, nothing felt');
    }

    public function testALoadFromBeforeTheEncounterUndoesIt(): void
    {
        $d = $this->npc('Romantic', 'romantic', ['trust' => 80.0, 'comfort' => 40.0]);
        $comfort = $d['dimensions']['comfort']['x'];
        RelDynPostIntimacy::onIntimateRequest('Lydia', $d, $this->scene('Lydia'), 'Kaida', self::T0);
        $r = RelDynPostIntimacy::tick('Lydia', $d, self::T0 - self::HOUR);
        $this->assertTrue($r['dropped']);
        $this->assertEqualsWithDelta($comfort, $d['dimensions']['comfort']['x'], 1e-6);
        $this->assertArrayNotHasKey(RelDynPostIntimacy::KEY, $d);
    }

    public function testAVrTouchIsNoEncounterAndOffIsOff(): void
    {
        $d = $this->npc('Romantic', 'romantic', ['trust' => 80.0]);
        $this->assertNull(RelDynPostIntimacy::onIntimateRequest('Lydia', $d, ['ext_nsfw_physics', '1', (string) self::T0, 'Lydia^breast^grab^0^^left^'], 'Kaida', self::T0));
        $this->useDb(['post_intimacy' => ['enabled' => false] + RelDynPostIntimacy::configDefaults()]);
        $this->assertNull(RelDynPostIntimacy::onIntimateRequest('Lydia', $d, $this->scene('Lydia'), 'Kaida', self::T0));
        $this->assertArrayNotHasKey(RelDynPostIntimacy::KEY, $d);
    }

    public function testCheatingReadsHerOwnCoreRelationships(): void
    {
        $this->useDb([], ['Ysolda' => ['npc_name' => 'Ysolda', 'extended_data' => json_encode(['relationships' => [
            'Kaida' => ['aff' => 30, 'type' => 'friend'], 'Mikael' => ['aff' => 70, 'type' => 'romantic']]])]]);
        $d = $this->npc('Romantic', 'friend', ['trust' => 50.0, 'comfort' => 40.0, 'maturity' => 55.0]);
        $this->assertSame(RelDynPostIntimacy::CHEATING, RelDynPostIntimacy::onIntimateRequest('Ysolda', $d, $this->scene('Ysolda'), 'Kaida', self::T0));
        $this->assertSame(['Mikael'], $d[RelDynPostIntimacy::KEY]['context']['other_partners']);
        $r = RelDynPostIntimacy::tick('Ysolda', $d, self::T0 + 7 * self::HOUR);
        $this->assertGreaterThan(0.0, $r['corrected']['resentment_self'], 'guilt, which the bleed carries into her bonds');
        $this->assertLessThan(0.0, $r['corrected']['comfort']);
    }

    // ------------------------------------------------------------------ mf-coordinates

    public function testTheCoordinatesAreDerivedFromHowSheFeelsTowardThem(): void
    {
        $d = $this->npc('Guarded', 'neutral', ['maturity' => 80.0, 'respect' => 50.0, 'self_confidence' => 50.0, 'trust' => 50.0, 'comfort' => 50.0]);
        $m0 = floatval($d['dimensions']['coord_m']['x']);
        $f0 = floatval($d['dimensions']['coord_f']['x']);
        // at the neutral drivers (and the per-bond reading of a stranger) she is her anchor
        $d['_core_rel_type'] = 'neutral';
        $trustShown = RelationshipDynamics::getEffectiveDimensionValue($d, 'trust');
        $comfortShown = RelationshipDynamics::getEffectiveDimensionValue($d, 'comfort');
        $expectF = $f0 + (($trustShown + $comfortShown) / 2 - 50.0) * 0.8
            * RelationshipDynamics::getPlasticityProfile('Guarded', 'coord_f', [], $d)[(($trustShown + $comfortShown) / 2 >= 50.0) ? 'Y_up' : 'Y_down'];
        $this->assertEqualsWithDelta($expectF, RelDynMoodAxes::derivedCoord($d, 'coord_f'), 1e-6);

        // trust and comfort earned: she softens toward +F; respect and confidence lost: -M
        $open = $d;
        $open['dimensions']['trust']['x'] = 85.0;
        $open['dimensions']['comfort']['x'] = 85.0;
        $this->assertGreaterThan(RelDynMoodAxes::derivedCoord($d, 'coord_f'), RelDynMoodAxes::derivedCoord($open, 'coord_f'));
        $cold = $d;
        $cold['dimensions']['respect']['x'] = 10.0;
        $cold['dimensions']['self_confidence']['x'] = 20.0;
        $this->assertLessThan(RelDynMoodAxes::derivedCoord($d, 'coord_m'), RelDynMoodAxes::derivedCoord($cold, 'coord_m'));
        // maturity rations how strongly the drivers push
        $young = $cold;
        $young['dimensions']['maturity']['x'] = 20.0;
        $this->assertGreaterThan(RelDynMoodAxes::derivedCoord($cold, 'coord_m'), RelDynMoodAxes::derivedCoord($young, 'coord_m'));
        $this->assertLessThan($m0, RelDynMoodAxes::derivedCoord($young, 'coord_m'));
        // the stored x is never written; with the derivation off it is what reads
        $this->assertSame($m0, floatval($cold['dimensions']['coord_m']['x']));
        $this->useDb(['mood_axes' => ['derived_coords' => ['enabled' => false]] + RelDynMoodAxes::configDefaults()]);
        $this->assertSame($m0, RelDynMoodAxes::derivedCoord($cold, 'coord_m'));
    }

    // ------------------------------------------------------------------ arousal-valence

    public function testWhatAnEventLeftSettlesAndAHeldStateStays(): void
    {
        $d = $this->npc('Anxious', 'friend');
        $d['dimensions']['arousal']['x'] = 10.0;
        $d['dimensions']['valence']['x'] = 0.0;
        $this->assertSame([], RelDynMoodAxes::settle($d, self::T0), 'the first call only stamps the clock');
        // a fall: arousal +60, valence -40 (event residue); a place holds +15 arousal
        $d['dimensions']['arousal']['x'] = 85.0;
        $d['dimensions']['valence']['x'] = -40.0;
        $d['_env_applied_effects'] = ['arousal' => 15.0];
        $half = 5.0 * 60.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
        $moved = RelDynMoodAxes::settle($d, self::T0 + $half);
        $this->assertEqualsWithDelta(10.0 + 15.0 + 30.0, $d['dimensions']['arousal']['x'], 1e-3, 'the residue halves; the held place stays');
        $this->assertEqualsWithDelta(-40.0 * 0.5 ** (5.0 / 7.5), $d['dimensions']['valence']['x'], 1e-3, 'valence settles slower (Z 12 vs 8)');
        $this->assertLessThan(0.0, $moved['arousal']);
        // long after, only the held state is left
        RelDynMoodAxes::settle($d, self::T0 + 100 * $half);
        $this->assertEqualsWithDelta(25.0, $d['dimensions']['arousal']['x'], 1e-3);
        $this->assertEqualsWithDelta(0.0, $d['dimensions']['valence']['x'], 1e-3);
        // arousal never stirred rests where it started: below its resting 10 there is no event to fade
        $still = $this->npc('Stoic', 'friend');
        $still['dimensions']['arousal']['x'] = 0.0;
        RelDynMoodAxes::settle($still, self::T0);
        RelDynMoodAxes::settle($still, self::T0 + 10 * $half);
        $this->assertSame(0.0, floatval($still['dimensions']['arousal']['x']));
        // a calendar behind the stamp (a load) moves nothing and restarts the clock
        $d['dimensions']['arousal']['x'] = 70.0;
        $this->assertSame([], RelDynMoodAxes::settle($d, self::T0));
        $this->assertSame(70.0, $d['dimensions']['arousal']['x']);
    }

    public function testTheEvalReadsTheBandButDoesNotScoreIt(): void
    {
        $d = $this->npc('Anxious', 'friend');
        $d['dimensions']['arousal']['x'] = 12.0;
        $d['dimensions']['valence']['x'] = 5.0;
        $this->assertNull(RelDynMoodAxes::evalLine($d), 'settled');
        $d['dimensions']['arousal']['x'] = 80.0;
        $d['dimensions']['valence']['x'] = -30.0;
        $this->assertStringContainsString('Panicked', (string) RelDynMoodAxes::evalLine($d));
        $this->assertContains('Nervous state: ' . RelationshipDynamics::getArousalValenceBand(80, -30)['label'] . ' ('
            . RelationshipDynamics::getArousalValenceBand(80, -30)['keywords'] . ')', RelDynEval::stateSummary('Tester', $d));
        // the contract has no arousal / valence signal: the eval never scores them
        $this->assertArrayNotHasKey('arousal', RelDynEval::SIGNAL_LIMITS);
        $this->assertArrayNotHasKey('valence', RelDynEval::SIGNAL_LIMITS);
    }

    public function testTheSocialFeedIsOffByDefaultAndSizedByCodeWhenOn(): void
    {
        $item = ['grievance' => ['flag' => true, 'kind' => 'insult', 'severity' => 2], 'jealousy' => ['flag' => false],
                 'tags' => ['insult'], 'significance' => 0.5];
        $d = $this->npc('Anxious', 'friend');
        $this->assertSame([], RelDynMoodAxes::onEvalItem($d, $item, 'Anxious'));
        $cfg = RelDynMoodAxes::configDefaults();
        $cfg['social']['enabled'] = true;
        $this->useDb(['mood_axes' => $cfg]);
        $applied = RelDynMoodAxes::onEvalItem($d, $item, 'Anxious');
        $this->assertGreaterThan(0.0, $applied['arousal']);
        $this->assertLessThan(0.0, $applied['valence']);
    }

    // ------------------------------------------------------------------ gift-delta-formula

    public function testTheGiftFormulaBaseAndLoveLanguage(): void
    {
        $this->assertSame(5.0, RelDynGifts::base(900), 'flat by default: gold is not effort');
        $cfg = RelDynGifts::configDefaults();
        $cfg['value_base']['enabled'] = true;
        $this->assertEqualsWithDelta(5.0, RelDynGifts::base(50, $cfg), 1e-9);
        $this->assertEqualsWithDelta(5.0 * 1.6, RelDynGifts::base(100000, $cfg), 1e-9);
        $this->assertEqualsWithDelta(5.0 * 0.6, RelDynGifts::base(1, $cfg), 1e-9);
        $this->assertSame(5.0, RelDynGifts::base(null, $cfg));

        $d = $this->npc('Humble', 'friend');
        $d['love_language_primary'] = RelationshipDynamics::LL_GIFTS;
        $this->assertSame(2.0, RelDynGifts::loveLanguageMult($d));
        $d['love_language_primary'] = RelationshipDynamics::LL_TIME;
        $d['love_language_secondary'] = RelationshipDynamics::LL_GIFTS;
        $this->assertSame(1.5, RelDynGifts::loveLanguageMult($d), 'MDD 1.2: the secondary language x1.5');
        $d['love_language_secondary'] = RelationshipDynamics::LL_WORDS;
        $this->assertSame(1.0, RelDynGifts::loveLanguageMult($d));
    }

    public function testTheStolenMarkerIsAFlagNotTheItemName(): void
    {
        $this->assertSame(['Kaida gave 1 Gold Ring to Aela,(value 75 gold)', true],
            RelDynGifts::stripStolenMarker('Kaida gave 1 Gold Ring (stolen) to Aela,(value 75 gold)'));
        $this->assertSame(['Kaida gave 1 Gold Ring to Aela', false], RelDynGifts::stripStolenMarker('Kaida gave 1 Gold Ring to Aela'));
    }

    public function testAStolenGiftIsNoGift(): void
    {
        $d = $this->npc('Humble', 'friend', ['trust' => 50.0, 'respect' => 50.0]);
        $d['love_language_primary'] = RelationshipDynamics::LL_GIFTS;
        $aff = $d['dimensions']['affinity']['x'] ?? null;
        $r = RelationshipDynamics::processGift($d, 'Gold Ring', 'Kaida', 'Humble', 'Ysolda', ['stolen' => true, 'value' => 75, 'rowid' => null]);
        $this->assertLessThan(0.0, $r['trust']);
        $this->assertLessThan(0.0, $r['respect']);
        $this->assertArrayNotHasKey('affinity', $r, 'the formula does not apply');
        $this->assertSame($aff, $d['dimensions']['affinity']['x'] ?? null);
        $this->assertGreaterThan(0.0, floatval($d['dimensions']['resentment']['x'] ?? 0), 'a grievance');
        $this->assertStringContainsString('stolen', (string) $d['_last_gift_felt']);
        $this->assertDoesNotMatchRegularExpression('/\d/', (string) $d['_last_gift_felt']);
    }

    public function testThePrimaryLanguageOutweighsTheSecondaryOutweighsNone(): void
    {
        $gain = function (?string $primary, ?string $secondary): float {
            $d = $this->npc('Humble', 'friend', ['comfort' => 40.0]);
            $d['love_language_primary'] = $primary;
            $d['love_language_secondary'] = $secondary;
            $r = RelationshipDynamics::processGift($d, 'Unclassifiable Trinket Xq', 'Kaida', 'Humble', 'Ysolda', ['stolen' => false]);
            return floatval($r['comfort'] ?? 0.0);
        };
        $none = $gain(RelationshipDynamics::LL_TIME, RelationshipDynamics::LL_WORDS);
        $secondary = $gain(RelationshipDynamics::LL_TIME, RelationshipDynamics::LL_GIFTS);
        $primary = $gain(RelationshipDynamics::LL_GIFTS, RelationshipDynamics::LL_WORDS);
        $this->assertGreaterThan($none, $secondary);
        $this->assertGreaterThan($secondary, $primary);
    }
}
