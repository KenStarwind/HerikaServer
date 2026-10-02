<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** $db that keeps what is inserted (the responselog rows the body sends through the command channel). */
final class RelDynBodyRecordingDb
{
    public array $inserts = [];
    public bool $fail = false;

    public function insert($table, $data)
    {
        if ($this->fail) return false;
        $this->inserts[] = [$table, $data];
        return true;
    }

    public function fetchOne($q, array $params = []) { return []; }
    public function fetchAll($q, $log = false) { return []; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * bio-mimetic-feedback and oblush-physical-blush (Ken 2026-10-01 §24): the body-language cue strengths and who the NPC is,
 * the voice emotion for TTS, the blush's duration / extension / cooldown / safeguard, the command that leaves for the game,
 * and what the LLM (felt text, no pronoun of a fixed gender) and Jev (numbers) get. No database but a recording insert; the
 * four test beds run end to end in RelDynBodyTestBedsPostgresTest.
 */
final class RelDynBodyTest extends TestCase
{
    private const PER_SECOND = RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    private const PLAY0 = 1000000.0;

    private array $saved = [];
    private string $errorLog = '';
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'FORCE_MOOD', 'EMOTEMOODS'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbody');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** The inputs of an NPC at rest; override what a test is about. */
    private static function in(array $o = []): array
    {
        return $o + ['passion' => 0.0, 'shyness' => 0.0, 'guard' => 0.5, 'avoidance' => 0.0, 'anxiety' => 0.0, 'pullback' => 0.0,
            'walkaway' => 'normal', 'conflict' => false, 'resentment' => 0.0, 'jealousy' => 0.0, 'ick' => false, 'maturity' => 50.0,
            'self_confidence' => 50.0, 'withdraw' => 0.0, 'arousal' => 10.0, 'valence' => 0.0, 'flush_held' => false];
    }

    private static function npc(string $preset, array $dims = [], array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => $preset, 'stage' => 'early', 'dimensions' => [], '_accumulated_play_gamets' => self::PLAY0];
        foreach ($dims + ['passion' => 0.0, 'maturity' => 55.0, 'self_confidence' => 50.0, 'resentment' => 0.0, 'arousal' => 10.0, 'valence' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    // ------------------------------------------------------------------ the cues, and who the NPC is

    public function testTheApproachThresholdIsSixtyForAnAverageNpcAndMovesWithWhoTheyAre(): void
    {
        $this->assertEqualsWithDelta(60.0, RelDynBody::approachThreshold(self::in()), 1e-9, 'Ken: approach at passion above 60');
        $bold = RelDynBody::approachThreshold(self::in(['guard' => 0.1]));
        $guarded = RelDynBody::approachThreshold(self::in(['guard' => 0.9]));
        $shy = RelDynBody::approachThreshold(self::in(['shyness' => 1.0]));
        $this->assertLessThan(60.0, $bold, 'a bold NPC closes the distance earlier');
        $this->assertGreaterThan(60.0, $guarded, 'a guarded one later');
        $this->assertGreaterThan(60.0, $shy, 'and a shy one later');
        $this->assertGreaterThanOrEqual(40.0, RelDynBody::approachThreshold(self::in(['guard' => 0.0])), 'never below the floor');
        $this->assertLessThanOrEqual(85.0, RelDynBody::approachThreshold(self::in(['guard' => 1.0, 'shyness' => 1.0])), 'never above the ceiling');
    }

    public function testNoOneIsImmuneToApproachingNotEvenTheMostGuardedAndShyAtOverwhelmingPassion(): void
    {
        $s = RelDynBody::strengths(self::in(['passion' => 100.0, 'guard' => 1.0, 'shyness' => 1.0]));
        $this->assertGreaterThan(0.5, $s['approach'], 'a ceiling, not a wall');
        $this->assertSame(0.0, RelDynBody::strengths(self::in(['passion' => 55.0]))['approach'], 'under the threshold nothing');
        $this->assertEqualsWithDelta(0.6, RelDynBody::strengths(self::in(['passion' => 75.0]))['approach'], 1e-6, '15 over, a span of 25');
        $this->assertLessThan(RelDynBody::strengths(self::in(['passion' => 75.0, 'guard' => 0.1]))['approach'],
            RelDynBody::strengths(self::in(['passion' => 75.0, 'guard' => 0.9]))['approach'], 'the same passion moves a bold NPC more than a guarded one');
    }

    public function testWhatPullsTheNpcAwayEasesTheApproachButIsNotAHardGate(): void
    {
        $base = RelDynBody::strengths(self::in(['passion' => 90.0]))['approach'];
        foreach ([['pullback' => 0.5], ['walkaway' => 'active'], ['conflict' => true], ['jealousy' => 85.0]] as $o) {
            $eased = RelDynBody::strengths(self::in(['passion' => 90.0] + $o))['approach'];
            $this->assertLessThan($base, $eased, json_encode($o));
        }
        // a walkaway in progress takes nearly all of it, and a gentler pull-back only part
        $this->assertLessThan(0.1 * $base + 1e-9, RelDynBody::strengths(self::in(['passion' => 90.0, 'walkaway' => 'active']))['approach']);
        $this->assertGreaterThan(0.4 * $base, RelDynBody::strengths(self::in(['passion' => 90.0, 'pullback' => 0.5]))['approach']);
    }

    public function testTurningAwayFollowsAWalkawayAPullbackOrTheWithdrawingFearAndTheAvoidantTurnMore(): void
    {
        $s = fn(array $o) => RelDynBody::strengths(self::in($o))['turn_away'];
        $this->assertSame(0.0, $s([]));
        $this->assertEqualsWithDelta(0.5, $s(['walkaway' => 'pending']), 1e-9);
        $this->assertEqualsWithDelta(1.0, $s(['walkaway' => 'active']), 1e-9);
        $this->assertEqualsWithDelta(0.6, $s(['pullback' => 0.3]), 1e-9, 'pulled back: at least the floor');
        $this->assertEqualsWithDelta(0.9, $s(['pullback' => 0.9]), 1e-9, 'and rising with the pressure');
        $this->assertEqualsWithDelta(0.8 * 0.5, $s(['withdraw' => 0.5]), 1e-9, 'the withdrawing fear of losing the player');
        $avoidant = $s(['walkaway' => 'pending', 'avoidance' => 0.8, 'anxiety' => 0.2]);
        $anxious = $s(['walkaway' => 'pending', 'avoidance' => 0.2, 'anxiety' => 0.8]);
        $this->assertGreaterThan($anxious, $avoidant, 'the avoidant turn away more');
        $this->assertGreaterThan(0.2, $anxious, 'the anxious still turn away: nobody is immune');
    }

    public function testAShyNpcStealsGlancesAtPassionAndABoldOneOnlyWhenItOverwhelms(): void
    {
        $s = fn(array $o) => RelDynBody::strengths(self::in($o))['shy_glance'];
        $this->assertSame(0.0, $s(['shyness' => 0.9, 'passion' => 5.0]), 'nothing to be shy about yet');
        $this->assertEqualsWithDelta(0.4, $s(['shyness' => 0.8, 'passion' => 30.0]), 1e-9, 'half drawn, 0.8 shy');
        $this->assertEqualsWithDelta(0.8, $s(['shyness' => 0.8, 'passion' => 45.0]), 1e-9);
        $this->assertSame(0.0, $s(['shyness' => 0.0, 'passion' => 45.0]), 'a bold NPC at ordinary passion has nothing to hide');
        $this->assertGreaterThan(0.15, $s(['shyness' => 0.0, 'passion' => 95.0]), 'but even a bold one steals a glance at overwhelming passion');
        $this->assertGreaterThan($s(['shyness' => 0.5, 'passion' => 40.0]), $s(['shyness' => 0.5, 'passion' => 40.0, 'flush_held' => true]), 'the flush a match holds');
        $this->assertLessThan($s(['shyness' => 0.8, 'passion' => 45.0]), $s(['shyness' => 0.8, 'passion' => 45.0, 'conflict' => true]), 'not in a quarrel');
    }

    public function testATenseStanceFollowsConflictResentmentJealousyAndTheIckAndTheMatureShowLess(): void
    {
        $s = fn(array $o) => RelDynBody::strengths(self::in($o))['tense_stance'];
        $this->assertSame(0.0, $s([]));
        $this->assertEqualsWithDelta(0.6, $s(['conflict' => true]), 1e-9);
        $this->assertEqualsWithDelta(0.7, $s(['jealousy' => 85.0]), 1e-9);
        $this->assertEqualsWithDelta(0.7, $s(['ick' => true]), 1e-9);
        $this->assertEqualsWithDelta(1.0, $s(['resentment' => 80.0]), 1e-9);
        $immature = $s(['conflict' => true, 'maturity' => 10.0]);
        $mature = $s(['conflict' => true, 'maturity' => 95.0]);
        $this->assertGreaterThan($mature, $immature);
        $this->assertGreaterThan(0.2, $mature, 'the mature still feel it in the body');
    }

    public function testOfferedCuesAreTheStrongestFirstAndNothingBelowTheMinimum(): void
    {
        $s = RelDynBody::strengths(self::in(['passion' => 75.0, 'conflict' => true, 'walkaway' => 'pending']));
        $offered = RelDynBody::offered($s);
        $this->assertSame(array_keys($offered), array_values(array_keys($offered)));
        $vals = array_values($offered);
        $sorted = $vals;
        rsort($sorted);
        $this->assertSame($sorted, $vals, 'strongest first');
        foreach ($offered as $cue => $v) $this->assertGreaterThanOrEqual(0.15, $v, $cue);
        $this->assertSame([], RelDynBody::offered(RelDynBody::strengths(self::in())), 'a calm NPC at rest shows nothing');
    }

    // ------------------------------------------------------------------ the voice

    public function testTheVoiceFollowsPassionArousalAndValenceAndIsPlainWhenThereIsNothingToSay(): void
    {
        $v = fn(array $o) => RelDynBody::voice(self::in($o));
        $this->assertNull($v([]), 'an ordinary voice');
        $this->assertNull($v(['passion' => 10.0]));
        $s = $v(['passion' => 80.0, 'valence' => 20.0, 'arousal' => 55.0]);
        $this->assertSame('seductive', $s['family']);
        $this->assertSame('seductive', $s['mood']);
        $this->assertSame('strong', $s['intensity']);
        $this->assertSame('flirtatious', $s['cartesia']);
        $this->assertSame('normal', $s['pace']);
        $this->assertSame('shy', $v(['passion' => 80.0, 'shyness' => 0.8])['family'], 'a drawn voice is shy for a shy NPC');
        $this->assertSame('teasing', $v(['passion' => 50.0, 'self_confidence' => 70.0, 'arousal' => 65.0])['family']);
        $this->assertSame('lovely', $v(['passion' => 50.0, 'self_confidence' => 40.0])['family']);
        $this->assertSame('kindly', $v(['passion' => 30.0])['family']);
        $this->assertSame('angry', $v(['valence' => -60.0, 'arousal' => 70.0])['family']);
        $this->assertSame('irritated', $v(['valence' => -30.0, 'arousal' => 40.0])['family']);
        $this->assertSame('sad', $v(['valence' => -40.0, 'arousal' => 20.0])['family']);
        $this->assertSame('happy', $v(['valence' => 50.0, 'arousal' => 70.0])['family']);
        $this->assertSame('slow', $v(['valence' => -40.0, 'arousal' => 20.0])['pace']);
        $this->assertSame('fast', $v(['valence' => -60.0, 'arousal' => 80.0])['pace']);
    }

    public function testTheVoiceMoodIsOneTheNpcMayUseAndFallsBackAlongItsList(): void
    {
        $in = self::in(['passion' => 80.0]);
        $this->assertSame('lovely', RelDynBody::voice($in, null, ['lovely', 'neutral'])['mood'], 'no seductive or sexy: lovely');
        $this->assertSame('sexy', RelDynBody::voice($in, null, ['sexy', 'lovely'])['mood']);
        $none = RelDynBody::voice($in, null, ['neutral', 'drunk']);
        $this->assertNull($none['mood'], 'nothing the NPC may use: the family still reads, the mood does not');
        $this->assertSame('seductive', $none['family']);
        $GLOBALS['EMOTEMOODS'] = 'sad,neutral';
        $this->assertNull(RelDynBody::voice($in)['mood'], 'the NPC own emote_moods are read');
    }

    public function testTheVoiceCanBeSwitchedOffOrKeptQuietBelowItsMinimum(): void
    {
        $this->assertNotNull(RelDynBody::voice(self::in(['passion' => 80.0])));
        $cfg = RelDynBody::configDefaults();
        $cfg['voice']['enabled'] = false;
        $this->assertNull(RelDynBody::voice(self::in(['passion' => 80.0]), $cfg));
        $cfg = RelDynBody::configDefaults();
        $cfg['voice']['min_intensity'] = 0.9;
        $this->assertNull(RelDynBody::voice(self::in(['passion' => 80.0]), $cfg));
    }

    public function testForceMoodIsOptInAndNeverOverAnotherForcedMood(): void
    {
        $d = self::npc('Bold', ['passion' => 85.0, 'valence' => 30.0]);
        $this->assertNull(RelDynBody::applyVoice($d), 'ships off');
        $this->assertArrayNotHasKey('FORCE_MOOD', $GLOBALS);
        $cfg = RelDynBody::configDefaults();
        $cfg['voice']['force_mood'] = true;
        $this->assertSame('seductive', RelDynBody::applyVoice($d, $cfg));
        $this->assertSame('seductive', $GLOBALS['FORCE_MOOD']);
        $GLOBALS['FORCE_MOOD'] = 'angry';
        $this->assertNull(RelDynBody::applyVoice($d, $cfg), 'a mood something else forced stays');
        $this->assertSame('angry', $GLOBALS['FORCE_MOOD']);
    }

    // ------------------------------------------------------------------ who speaks

    public function testTheStrongestCueSpeaksByDefaultAndNothingSpeaksForACalmNpc(): void
    {
        $calm = self::npc('Stoic');
        $this->assertNull(RelDynBody::chosen($calm)['cue']);
        $warm = self::npc('Bold', ['passion' => 90.0]);
        $c = RelDynBody::chosen($warm);
        $this->assertSame('approach', $c['cue']);
        $this->assertSame('default', $c['source']);
        $this->assertGreaterThan(0.15, $c['strength']);
        $cfg = RelDynBody::configDefaults();
        $cfg['cues']['default_pick'] = false;
        $this->assertNull(RelDynBody::chosen($warm, null, $cfg)['cue'], 'without default_pick a cue speaks only when Jev picks it');
    }

    public function testAPicksCueSpeaksForItsLifetimeAndOnlyWhileTheCueIsStillOffered(): void
    {
        $d = self::npc('Bold', ['passion' => 90.0], ['in_conflict' => false]);
        $d[RelDynBody::KEY] = ['v' => 1, 'picked' => ['cue' => 'shy_glance', 'source' => 'jev', 'play' => self::PLAY0], 'last' => [], 'blush' => null, 'outbox' => []];
        $d['dimensions']['self_confidence'] = ['x' => 10.0, 'baseline' => 10.0];
        $this->assertSame('shy_glance', RelDynBody::chosen($d)['cue'], 'Jev picked it, it is offered');
        $this->assertSame('jev', RelDynBody::chosen($d)['source']);
        $d['_accumulated_play_gamets'] = self::PLAY0 + 200 * self::PER_SECOND;
        $this->assertSame('default', RelDynBody::chosen($d)['source'], 'after pick_ttl_play_seconds the default speaks again');
        $d['_accumulated_play_gamets'] = self::PLAY0 + 10 * self::PER_SECOND;
        $d[RelDynBody::KEY]['picked']['cue'] = 'turn_away';
        $this->assertSame('default', RelDynBody::chosen($d)['source'], 'a picked cue the state no longer offers falls back');
        $this->assertNotSame('turn_away', RelDynBody::chosen($d)['cue']);
        $d[RelDynBody::KEY]['picked']['cue'] = null;
        $this->assertNull(RelDynBody::chosen($d)['cue'], 'Jev decided nothing fits: the default stays quiet');
        $this->assertSame('jev', RelDynBody::chosen($d)['source']);
    }

    // ------------------------------------------------------------------ the blush

    public function testABlushLastsLongerForABiggerMomentAPrimaryMatchAndAShyNpcAndNeverNothing(): void
    {
        $poised = self::npc('Stoic', ['self_confidence' => 90.0]);
        $unsure = self::npc('Anxious', ['self_confidence' => 10.0]);
        $avg = self::npc('Stoic', ['self_confidence' => 50.0]);
        $s = fn(array $d, float $delta, float $mult = 1.0) => RelDynBody::blushSeconds($d, $delta, $mult);
        $this->assertEqualsWithDelta(30.0, $s($avg, 2.0), 1e-6, 'the faintest blush: 30 s');
        $this->assertEqualsWithDelta(120.0, $s($avg, 7.0), 1e-6, 'the strongest: 120 s');
        $this->assertEqualsWithDelta(75.0, $s($avg, 4.5), 1e-6, 'linear between');
        $this->assertEqualsWithDelta(2.0 * $s($avg, 4.0), $s($avg, 4.0, 2.0), 1e-6, 'a primary love language holds twice as long');
        $this->assertEqualsWithDelta(1.5 * $s($avg, 4.0), $s($avg, 4.0, 1.5), 1e-6);
        $this->assertGreaterThan($s($avg, 4.0), $s($unsure, 4.0), 'an unsure NPC blushes longer');
        $this->assertLessThan($s($avg, 4.0), $s($poised, 4.0), 'a poised one shorter');
        $this->assertGreaterThan(10.0, $s($poised, 2.0), 'and never to nothing');
        $this->assertEqualsWithDelta(180.0, $s($avg, 20.0), 1e-6, 'a huge moment stops growing');
        $this->assertEqualsWithDelta(180.0, $s($avg, 40.0), 1e-6);
        $this->assertEqualsWithDelta(150.0, $s($avg, 13.5), 1e-6, 'linear between the strongest and the huge');
        $this->assertEqualsWithDelta(300.0, $s($avg, 20.0, 2.0), 1e-6, 'capped');
        $this->assertGreaterThanOrEqual(10.0, $s($poised, 0.5));
    }

    public function testBlushinessDivergesByWhoTheNpcIsInsideItsBounds(): void
    {
        $shy = RelDynBody::blushiness(self::npc('Anxious', ['self_confidence' => 5.0]));
        $sure = RelDynBody::blushiness(self::npc('Bold', ['self_confidence' => 95.0]));
        $this->assertGreaterThan(1.0, $shy);
        $this->assertLessThan(1.0, $sure);
        $this->assertGreaterThanOrEqual(0.6, $sure);
        $this->assertLessThanOrEqual(1.6, $shy);
    }

    public function testAMomentStartsABlushAQuietMomentWhileItIsOnHoldsAndABiggerOneExtendsIt(): void
    {
        $d = self::npc('Stoic', ['self_confidence' => 50.0]);
        $r = RelDynBody::onBlushMoment('Aela', $d, 4.5, 1.0);
        $this->assertSame('start', $r['action']);
        $this->assertEqualsWithDelta(75.0, $r['seconds'], 1e-6);
        $this->assertSame(1, $r['token']);
        $this->assertSame([['cmd' => 'ExtCmdRelDynBody_Blush', 'param' => '75@1']], $d['_body']['outbox']);
        // the same size again, a moment later: still on, nothing to add
        $d['_accumulated_play_gamets'] += 5 * self::PER_SECOND;
        $r = RelDynBody::onBlushMoment('Aela', $d, 4.5, 1.0);
        $this->assertSame('hold', $r['action']);
        $this->assertCount(1, $d['_body']['outbox'], 'nothing queued');
        // a much bigger moment with a primary match outlasts it: extended, a new token
        $r = RelDynBody::onBlushMoment('Aela', $d, 9.0, 2.0);
        $this->assertSame('extend', $r['action']);
        $this->assertSame(2, $r['token']);
        $big = 2.0 * (120.0 + 60.0 * (9.0 - 7.0) / 13.0);   // 9 points: between the strongest and the huge, x the primary match
        $this->assertSame((int) round(round($big, 1)) . '@2', $d['_body']['outbox'][1]['param']);
        $this->assertEqualsWithDelta($big, ($d['_body']['blush']['until_play'] - $d['_accumulated_play_gamets']) / self::PER_SECOND, 0.06);
    }

    public function testANewBlushWaitsForTheCooldownAfterTheLastEndedSoItNeverFlickers(): void
    {
        $d = self::npc('Stoic');
        RelDynBody::onBlushMoment('Aela', $d, 2.0, 1.0);   // 30 s
        $until = $d['_body']['blush']['until_play'];
        $d['_accumulated_play_gamets'] = $until + 5 * self::PER_SECOND;
        $r = RelDynBody::onBlushMoment('Aela', $d, 7.0, 2.0);
        $this->assertSame('cooldown', $r['action'], '5 s after it ended: not yet');
        $this->assertCount(1, $d['_body']['outbox']);
        $d['_accumulated_play_gamets'] = $until + 25 * self::PER_SECOND;
        $r = RelDynBody::onBlushMoment('Aela', $d, 7.0, 2.0);
        $this->assertSame('start', $r['action'], 'past the cooldown: a new one');
        $this->assertSame(2, $r['token']);
    }

    public function testABlushTheClockSaysIsLongerThanAnyCanBeBelongsToADiscardedTimelineAndIsOver(): void
    {
        $d = self::npc('Stoic');
        RelDynBody::onBlushMoment('Aela', $d, 7.0, 2.0);
        $d['_accumulated_play_gamets'] = self::PLAY0 - 5000 * self::PER_SECOND;   // a save from long ago was loaded
        $r = RelDynBody::onBlushMoment('Aela', $d, 4.0, 1.0);
        $this->assertSame('start', $r['action']);
        $this->assertLessThanOrEqual(241.0, RelDynBody::jev($d)['blush']['play_seconds_left'], 'and the numbers for Jev never claim more than a blush can last');
    }

    public function testTheSafeguardTakesAnUnconfirmedBlushOffOnceAfterTheGrace(): void
    {
        $d = self::npc('Stoic');
        RelDynBody::onBlushMoment('Aela', $d, 2.0, 1.0);
        $until = $d['_body']['blush']['until_play'];
        $d['_accumulated_play_gamets'] = $until + 5 * self::PER_SECOND;
        $this->assertFalse(RelDynBody::tick('Aela', $d), 'inside the grace');
        $d['_accumulated_play_gamets'] = $until + 20 * self::PER_SECOND;
        $this->assertTrue(RelDynBody::tick('Aela', $d));
        $this->assertSame('ExtCmdRelDynBody_BlushOff', $d['_body']['outbox'][1]['cmd']);
        $this->assertSame('1', $d['_body']['outbox'][1]['param'], 'the token it takes off');
        $this->assertFalse(RelDynBody::tick('Aela', $d), 'once');
        $this->assertFalse(RelDynBody::tick('Nobody', self::npc('Stoic')), 'no blush, nothing to take off');
    }

    public function testTheOutboxLeavesAsResponselogRowsInTheCommandChannelsOwnShape(): void
    {
        $db = new RelDynBodyRecordingDb();
        $GLOBALS['db'] = $db;
        $d = self::npc('Stoic');
        RelDynBody::onBlushMoment('Lynly Star-Sung', $d, 4.5, 1.5);
        $this->assertTrue(RelDynBody::flush('Lynly Star-Sung', $d));
        $this->assertCount(1, $db->inserts);
        [$table, $row] = $db->inserts[0];
        $this->assertSame('responselog', $table);
        $this->assertSame('Lynly Star-Sung', $row['actor'], 'the command is the NPC own');
        $this->assertSame(0, $row['sent']);
        $this->assertSame('', $row['text']);
        // action = "command|<ExtCmd>@<seconds>@<token>": game side, queue 'command', ExtCmd bridge script RelDynBody, action Blush
        $this->assertMatchesRegularExpression('/^command\|ExtCmdRelDynBody_Blush@\d+@1$/', $row['action']);
        $this->assertStringNotContainsString('|', explode('|', $row['action'], 2)[1], 'one pipe only: the game splits the line on it');
        $this->assertSame([], $d['_body']['outbox'], 'sent once');
        $this->assertFalse(RelDynBody::flush('Lynly Star-Sung', $d), 'nothing waits');
    }

    public function testAFailedInsertIsLoggedAndDroppedNeverRetriedForever(): void
    {
        $db = new RelDynBodyRecordingDb();
        $db->fail = true;
        $GLOBALS['db'] = $db;
        $d = self::npc('Stoic');
        RelDynBody::onBlushMoment('Aela', $d, 4.0, 1.0);
        $this->assertTrue(RelDynBody::flush('Aela', $d));
        $this->assertSame([], $d['_body']['outbox']);
        $this->assertStringContainsString('could not be queued', (string) file_get_contents($this->errorLog));
        unset($GLOBALS['db']);
        RelDynBody::onBlushMoment('Aela', $d, 9.0, 2.0);
        $this->assertTrue(RelDynBody::flush('Aela', $d));
        $this->assertStringContainsString('no database', (string) file_get_contents($this->errorLog));
    }

    public function testTheBlushSwitchesOffWithTheBodyOrItsOwnSwitch(): void
    {
        $GLOBALS['db'] = null;
        unset($GLOBALS['db']);
        $d = self::npc('Stoic');
        $cfg = RelDynBody::configDefaults();
        $cfg['blush']['enabled'] = false;
        $this->storeConfig(['body' => $cfg]);
        $this->assertSame('off', RelDynBody::onBlushMoment('Aela', $d, 4.0, 1.0)['action']);
        $this->assertArrayNotHasKey('_body', $d);
    }

    /** Make RelationshipDynamics read this config row (a tiny in-memory db for configValue). */
    private function storeConfig(array $row): void
    {
        $GLOBALS['db'] = new class($row) {
            public function __construct(private array $row) {}
            public function fetchOne($q, array $params = []) { return ['value' => json_encode($this->row + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA])]; }
            public function fetchAll($q, $log = false) { return []; }
            public function insert($t, $d) { return true; }
            public function escape($s) { return (string) $s; }
            public function escapeLiteral($s) { return "'" . $s . "'"; }
        };
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ what the LLM and Jev get

    public function testTheFeltLinesAreTextForAnyGenderAndNeverNumbers(): void
    {
        $d = self::npc('Bold', ['passion' => 90.0, 'valence' => 30.0, 'arousal' => 60.0]);
        $lines = RelDynBody::feltLines('Aela the Huntress', 'Kaida', $d);
        $keys = array_column($lines, 'key');
        $this->assertContains('body_approach', $keys);
        $this->assertContains('voice', $keys);
        foreach ($lines as $l) {
            $this->assertSame('bond', $l['scope']);
            $this->assertGreaterThan(0.0, $l['salience']);
            $this->assertLessThanOrEqual(1.0, $l['salience']);
            $this->assertStringContainsString('Aela the Huntress', $l['text']);
            $this->assertDoesNotMatchRegularExpression('/\d/', $l['text'], 'felt text carries no numbers');
            $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|he|him|his)\b/i', $l['text'], 'pronouns are vars, resolved from the NPC own gender');
        }
        foreach (['m' => ['he is', 'his'], 'f' => ['she is', 'her'], 'n' => ['they are', 'their']] as $class => [$are, $their]) {
            $text = strtr(RelDynBody::cueText('approach', 0.9), ['{NAME}' => 'X', '{PLAYER}' => 'Y']);
            $filled = strtr($text, ['{THEY_ARE}' => RelDynPronouns::form('are', $class)]);
            $this->assertStringContainsString($are, $filled, $class);
        }
        $cfg = RelDynBody::configDefaults();
        $cfg['enabled'] = false;
        $this->assertSame([], RelDynBody::feltLines('Aela', 'Kaida', $d, $cfg));
    }

    public function testTheSoftAndStrongWordingFollowTheStrengthAndEveryCueHasBoth(): void
    {
        foreach (RelDynBody::CUES as $cue) {
            $soft = RelDynBody::cueText($cue, 0.2);
            $strong = RelDynBody::cueText($cue, 0.9);
            $this->assertNotSame('', $soft, $cue);
            $this->assertNotSame('', $strong, $cue);
            $this->assertNotSame($soft, $strong, $cue);
            $this->assertStringContainsString('{NAME}', $soft);
        }
    }

    public function testJevGetsTheNumbersAndACompactLine(): void
    {
        $d = self::npc('Bold', ['passion' => 90.0, 'valence' => 30.0, 'arousal' => 60.0]);
        $b = RelDynBody::jev($d, 0.0);
        $this->assertTrue($b['enabled']);
        $this->assertSame(['turn_away', 'tense_stance', 'approach', 'shy_glance'], array_keys($b['cues']));
        $this->assertTrue($b['cues']['approach']['offered']);
        $this->assertSame('ComeCloser', $b['cues']['approach']['action'], 'the one cue with a core action');
        $this->assertNull($b['cues']['turn_away']['action'], 'the rest are context text');
        $this->assertContains('approach', $b['offered']);
        $this->assertSame('approach', $b['chosen']['cue']);
        $this->assertSame('default', $b['chosen']['source']);
        $this->assertNotNull($b['voice']);
        $this->assertFalse($b['blush']['on']);
        $this->assertIsString(json_encode($b), 'plain data');
    }

    public function testTheBodySectionIsAReaderOfTheSettingsHubAndItsTablesMergeEntryByEntry(): void
    {
        $this->assertArrayHasKey('body', RelationshipDynamics::defaultConfig());
        $this->storeConfig(['body' => ['cues' => ['approach' => ['passion_at' => 50.0]], 'voice' => ['moods' => ['kindly' => ['neutral']]]]]);
        $cfg = RelDynBody::config();
        $this->assertEqualsWithDelta(50.0, $cfg['cues']['approach']['passion_at'], 1e-9);
        $this->assertEqualsWithDelta(12.0, $cfg['cues']['approach']['guard_shift'], 1e-9, 'the rest of the table stays');
        $this->assertSame(['neutral'], $cfg['voice']['moods']['kindly'], 'a list replaces whole');
        $this->assertSame(['seductive', 'sexy', 'lovely'], $cfg['voice']['moods']['seductive']);
        $this->assertEqualsWithDelta(50.0, RelDynBody::approachThreshold(self::in()), 1e-9);
    }
}
