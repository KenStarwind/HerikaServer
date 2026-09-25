<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * The RelDyn config row, and core's speech table as main.php reads it for a continue (its last
 * row's speaker); every other statement answers empty and is recorded.
 */
final class RelDynP3pRulingsConfDb
{
    public array $confOpts = [];
    /** speaker of core's last speech row (null: the table is empty) */
    public ?string $lastSpeaker = null;
    public array $speechReads = [];
    public array $other = [];

    public function fetchOne($q, array $params = [])
    {
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", (string) $q, $m)) {
            return isset($this->confOpts[$m[1]]) ? ['value' => $this->confOpts[$m[1]]] : [];
        }
        if (str_contains((string) $q, 'FROM speech')) {
            $this->speechReads[] = (string) $q;
            return $this->lastSpeaker === null ? [] : ['speaker' => $this->lastSpeaker];
        }
        $this->other[] = (string) $q;
        return [];
    }

    public function fetchAll($q, $log = false) { $this->other[] = (string) $q; return []; }
    public function execQuery($q) { $this->other[] = (string) $q; return false; }
    public function insert($table, $data) { $this->other[] = "insert {$table}"; return false; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . str_replace("'", "''", (string) $s) . "'"; }
}

/**
 * Ken's rulings of 2026-09-25 (decisions §18) for this lane, without a database beyond the
 * config row (the hooks, the eval worker and the four test beds are
 * RelDynP3pRulingsTestBedsPostgresTest):
 *   #7  a shame walkaway is exempt from pursuit: approaching her is not following her, and a
 *       gentle approach helps (resentment_self relief, the confession opens);
 *   #8  a partner's comfort / trust display saturates, 100 x (1 - (1 - x/100)^mult);
 *   #9  social masking ships off; Muiri's preset nudges her into the fearful region;
 *   #10 'forgiveness' (-15, once an episode) and 'confessing' (-10, the confession path) are
 *       their own eval tags; 'confiding' is no confession;
 *   #11 charisma is the eval's own grade (covered with the contract in RelDynEvalFieldsTest);
 *   and a continue / continue_group answering another NPC is an NPC exchange before core has
 *   resolved its previous speaker (the prerequest): core's last speech row, read as core reads it.
 */
final class RelDynP3pRulingsTest extends TestCase
{
    private const T0 = 300 * RelationshipDynamics::GAMETS_PER_DAY;       // raw gamets, day 300
    private const PLAY = 100 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
    private const PLAY_MIN = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;

    private RelDynP3pRulingsConfDb $db;
    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE', 'PLAYER_NAME', 'RECHAT_PREVIOUS_SPEAKER'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) self::T0, 'Kaida: hello'];
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-p3p-');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        $this->db = new RelDynP3pRulingsConfDb();
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
            '_last_gamets' => self::T0,
        ], $extra));
        $d['_aff_mirror_x'] = ($aff + 100.0) / 2.0;
        $d['dimensions']['affinity']['x'] = ($aff + 100.0) / 2.0;
        $dims += ['maturity' => 50.0, 'self_confidence' => 50.0, 'comfort' => 40.0, 'trust' => 50.0, 'resentment' => 0.0, 'resentment_self' => 0.0];
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

    /** A contract v1 eval item, positive unless told otherwise. */
    private function item(array $tags, array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => 'Lydia', 'npc_id' => 7, 'gamets' => self::T0, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 1, 'trust' => 1, 'comfort' => 1, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => $tags, 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5, 'positive_interaction' => true, 'summary' => 'fixture',
        ], $over);
    }

    private function feel(array &$d, array $tags): array
    {
        return RelationshipDynamics::applyEvalFeelings('Lydia', $this->item($tags), $d);
    }

    /** Play minutes pass (the play clock the recovery's cooldowns count on). */
    private static function played(array &$d, float $minutes): void
    {
        $d['_accumulated_play_gamets'] += $minutes * self::PLAY_MIN;
    }

    // ------------------------------------------------------------------ #10 the tags

    public function testConfessingAndForgivenessAreTheirOwnTags(): void
    {
        $defs = RelDynEval::TAG_DEFINITIONS;
        $this->assertArrayHasKey('confessing', $defs);
        $this->assertArrayHasKey('forgiveness', $defs);
        $this->assertStringContainsString('ashamed', $defs['confessing']);
        $this->assertStringContainsString('the player forgave them', $defs['forgiveness']);
        $this->assertStringContainsString('that is confessing', $defs['confiding'], 'the model is told where confiding ends');
        $this->assertContains('confessing', RelationshipDynamics::EVAL_CONTRACT_TAGS);
        $this->assertContains('forgiveness', RelationshipDynamics::EVAL_CONTRACT_TAGS);
        $user = RelDynEval::buildMessages('Lydia', 'Kaida', ['earlier' => [], 'current' => [
            ['speaker' => 'Lydia', 'listener' => 'Kaida', 'text' => 'I let them take the child. I did nothing.'],
            ['speaker' => 'Kaida', 'listener' => 'Lydia', 'text' => 'I forgive you. You were afraid.'],
        ]], [], [])[1]['content'];
        $this->assertStringContainsString('- confessing: they admitted something they did and are ashamed of', $user);
        $this->assertStringContainsString('- forgiveness: the player forgave them for something they did', $user);
        // A confession is being known (fulfillment's emotional intimacy, like confiding); both are genuine moments
        $this->assertSame(RelDynFulfillment::configDefaults()['tag_delivery']['confiding'], RelDynFulfillment::configDefaults()['tag_delivery']['confessing']);
        $this->assertContains('confessing', RelDynAttraction::defaults()['emotional_passion']['tags']);
        $genuine = RelDynProtocols::configDefaults()['parasite']['genuine_tags'];
        $this->assertContains('confessing', $genuine);
        $this->assertContains('forgiveness', $genuine);
        $item = RelDynEval::parseResponse(json_encode(['signals' => ['affinity' => 3, 'comfort' => 4], 'tags' => ['Forgiveness', 'confessing']]),
            ['npc' => 'Lydia', 'npc_id' => 7, 'player_name' => 'Kaida', 'gamets' => self::T0, 'event_tags' => []]);
        $this->assertSame(['confessing', 'forgiveness'], $item['tags']);
        $this->assertTrue($item['positive_interaction']);
    }

    /**
     * Opening up in general is no confession (§18 #10 split). After the self-reflection was said,
     * confiding moves only the decay, and the confession stays open for the real one.
     */
    public function testConfidingIsNoConfession(): void
    {
        $d = $this->npc(['resentment_self' => 60.0]);
        $this->assertContains('reflection', RelDynResentment::tickSelf('Lydia', $d));
        RelDynResentment::takeFeltLines($d, 'Lydia', 'Kaida', self::T0);
        for ($i = 0; $i < 4; $i++) {
            $this->feel($d, ['confiding', 'quality_time']);
            self::played($d, 16);
        }
        $this->assertEqualsWithDelta(60.0 - 4 * 0.75, self::x($d, 'resentment_self'), 1e-9, 'four decays, no confession');
        $this->assertTrue($d['_resentment_arc']['self']['confess_open']);
        // a config row install.php stored before the split holds the retired default: not a choice
        $old = RelDynResentment::configDefaults();
        $old['self']['recovery_tags'] = ['confiding' => 10.0];
        $this->setConfig(['resentment_arc' => $old]);
        $this->assertEquals(['confessing' => 10.0], RelDynResentment::config()['self']['recovery_tags']);
        $f = $this->feel($d, ['confessing']);
        $this->assertEqualsWithDelta(60.0 - 5 * 0.75 - 10.0, self::x($d, 'resentment_self'), 1e-9, 'she tells: -10 beside the decay');
        $this->assertEqualsWithDelta(10.75, $f['resentment_self_relief'], 1e-9);
        $this->assertFalse($d['_resentment_arc']['self']['confess_open']);
    }

    /**
     * Forgiveness from the affected party: -15 (dimension design), needing no reflection first,
     * once an episode: forgiving her again for the same shame adds nothing until it has been
     * worked through (reflection_rearm_at) and a new episode began.
     */
    public function testForgivenessTakesFifteenOnceAnEpisode(): void
    {
        $d = $this->npc(['resentment_self' => 60.0]);
        $f = $this->feel($d, ['forgiveness']);
        $this->assertEqualsWithDelta(60.0 - 0.75 - 15.0, self::x($d, 'resentment_self'), 1e-9, 'no reflection needed');
        $this->assertEqualsWithDelta(15.75, $f['resentment_self_relief'], 1e-9);
        $this->assertTrue($d['_resentment_arc']['self']['forgiven']);
        self::played($d, 16);
        $this->feel($d, ['forgiveness']);
        $this->assertEqualsWithDelta(60.0 - 2 * 0.75 - 15.0, self::x($d, 'resentment_self'), 1e-9, 'forgiven already: only the decay');

        // not a positive exchange: nothing (forgiven through gritted teeth, a grievance beside it)
        $n = $this->npc(['resentment_self' => 60.0]);
        RelationshipDynamics::applyEvalFeelings('Lydia', $this->item(['forgiveness'], ['positive_interaction' => false]), $n);
        $this->assertSame(60.0, self::x($n, 'resentment_self'));

        // worked through, then a new episode: her next shame can be forgiven
        $d['dimensions']['resentment_self']['x'] = 25.0;
        RelDynResentment::tickSelf('Lydia', $d);
        $this->assertFalse($d['_resentment_arc']['self']['forgiven']);
        $d['dimensions']['resentment_self']['x'] = 55.0;
        self::played($d, 16);
        $this->feel($d, ['forgiveness']);
        $this->assertEqualsWithDelta(55.0 - 0.75 - 15.0, self::x($d, 'resentment_self'), 1e-9);
        // the tag's points are config
        $this->setConfig(['resentment_arc' => ['self' => ['forgiveness_tags' => ['forgiveness' => 20.0]]]]);
        $c = $this->npc(['resentment_self' => 60.0]);
        $this->feel($c, ['forgiveness']);
        $this->assertEqualsWithDelta(60.0 - 0.75 - 20.0, self::x($c, 'resentment_self'), 1e-9);
    }

    // ------------------------------------------------------------------ #7 the shame walkaway

    /** She left in shame: the walkaway state as prerequest leaves it after she went. */
    private function leftInShame(array $dims = []): array
    {
        $d = $this->npc($dims + ['resentment_self' => 92.0, 'comfort' => 40.0]);
        $this->assertTrue(RelDynResentment::selfCrisis($d));
        $this->assertSame('shame', RelationshipDynamics::walkawayReason($d));
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState($d, 'Stoic')['state']);
        RelationshipDynamics::initiateWalkaway($d, 'Lydia', 'shame');
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic', true);   // pending -> active: she goes
        $this->assertSame('active', $d['_walkaway_state']);
        $this->assertTrue(RelDynResentment::inShameWalkaway($d));
        return $d;
    }

    /**
     * §18 #7: approaching an NPC who left in shame is not "following" her (MDD 6.4 pursuit): no
     * doubled resentment, no trust penalty, no boundary_violated attachment experience, and the
     * boundary test is not failed by it. The same words after a resentment walkaway still are
     * pursuit.
     */
    public function testApproachingHerInHerShameIsNotPursuit(): void
    {
        $d = $this->leftInShame(['resentment' => 20.0]);
        $trust = self::x($d, 'trust');
        $drift = $d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] ?? null;
        foreach ([1, 2, 3] as $i) {
            $tick = RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic', true);
            $this->assertTrue($tick['shame_approach'] ?? false, "approach {$i}");
            $this->assertArrayNotHasKey('pressure_applied', $tick);
        }
        $this->assertFalse($d['_walkaway_player_followed']);
        $this->assertSame($trust, self::x($d, 'trust'), 'no trust penalty');
        $this->assertSame(20.0, self::x($d, 'resentment'), 'no resentment doubling');
        $this->assertEquals($drift, $d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] ?? null, 'no boundary_violated experience');
        $this->assertSame('boundary_test', $d['_walkaway_state']);
        $this->assertNotSame('permanent', RelationshipDynamics::checkBoundaryTest($d), 'not failed by being approached');
        $this->assertStringContainsString('a gentle word might reach them',
            RelationshipDynamics::getAutonomyContext($d, 'Lydia', 'Stoic') ?? '');

        // The same approach to someone who walked out on the player (resentment) is pursuit
        $r = $this->npc(['resentment' => 80.0, 'comfort' => 40.0]);
        $this->assertSame('resentment', RelationshipDynamics::walkawayReason($r));
        RelationshipDynamics::initiateWalkaway($r, 'Lydia', 'resentment');
        RelationshipDynamics::processWalkawayTick($r, 'Lydia', 'Stoic', true);
        $tick = RelationshipDynamics::processWalkawayTick($r, 'Lydia', 'Stoic', true);
        $this->assertTrue($tick['pressure_applied'] ?? false);
        $this->assertTrue($r['_walkaway_player_followed'], 'followed: the boundary test fails (MDD 6.4)');
        $this->assertLessThan(50.0, self::x($r, 'trust'), 'the trust penalty');
    }

    /**
     * §18 #7 "gentle approach should help": a positive exchange with her while she is away in her
     * shame takes shame_gentle_relief off (at most once per 15 play minutes) and opens the
     * confession (asked gently, it can come out) once per walkaway. With her confession and the
     * player's forgiveness, resentment_self falls below the walkaway's recovery line and the
     * boundary test lets her come back early. Outside a shame walkaway the same exchange is only
     * the decay.
     */
    public function testAGentleApproachHelpsHerBack(): void
    {
        $d = $this->leftInShame();
        RelationshipDynamics::processWalkawayTick($d, 'Lydia', 'Stoic', true);   // the approach
        $this->assertEmpty($d['_resentment_arc']['self']['confess_open'] ?? false, 'the reflection was never said');
        $f = $this->feel($d, ['quality_time', 'reassurance']);
        $this->assertEqualsWithDelta(92.0 - 5.0 - 0.75, self::x($d, 'resentment_self'), 1e-9, 'the gentle approach and the decay');
        $this->assertEqualsWithDelta(5.75, $f['resentment_self_relief'], 1e-9);
        $this->assertTrue($d['_resentment_arc']['self']['confess_open'], 'approached gently: it can come out');
        $this->feel($d, ['quality_time']);
        $this->assertEqualsWithDelta(92.0 - 5.0 - 0.75, self::x($d, 'resentment_self'), 1e-9, 'rate-limited on the play clock');

        $this->feel($d, ['confessing']);
        $this->assertEqualsWithDelta(92.0 - 5.0 - 0.75 - 10.0, self::x($d, 'resentment_self'), 1e-9, 'she tells');
        $this->feel($d, ['forgiveness']);
        $this->assertEqualsWithDelta(92.0 - 5.0 - 0.75 - 10.0 - 15.0, self::x($d, 'resentment_self'), 1e-9, 'and is forgiven');
        self::played($d, 16);
        $this->feel($d, ['quality_time']);
        $this->assertFalse($d['_resentment_arc']['self']['confess_open'], 'the confession opens once per walkaway');
        self::played($d, 16);
        $this->feel($d, ['quality_time']);
        $rs = self::x($d, 'resentment_self');
        $this->assertEqualsWithDelta(92.0 - 3 * 5.0 - 3 * 0.75 - 25.0, $rs, 1e-9);
        $this->assertLessThan(RelationshipDynamics::WALKAWAY_RECOVERY_RESENTMENT_MAX, $rs);
        $this->assertSame('recovery', RelationshipDynamics::checkBoundaryTest($d), 'below the recovery line: she comes back early');
        $back = RelationshipDynamics::resolveWalkawayTick($d, 'Lydia', 'Stoic', true);
        $this->assertTrue($back['returned']);
        $this->assertSame('normal', $d['_walkaway_state']);
        $this->assertArrayNotHasKey('_reject_recruitment', $d, 'the bond is intact');

        // The same kindness when she is not away in her shame: the decay only
        $home = $this->npc(['resentment_self' => 60.0]);
        $this->feel($home, ['quality_time', 'reassurance']);
        $this->assertEqualsWithDelta(60.0 - 0.75, self::x($home, 'resentment_self'), 1e-9);
        $this->assertEmpty($home['_resentment_arc']['self']['confess_open'] ?? false);
        // A walkaway for another reason is no shame walkaway
        $away = $this->npc(['resentment_self' => 60.0], ['_walkaway_state' => 'boundary_test', '_walkaway_reason' => 'resentment']);
        $this->assertFalse(RelDynResentment::inShameWalkaway($away));
        $this->feel($away, ['quality_time']);
        $this->assertEqualsWithDelta(60.0 - 0.75, self::x($away, 'resentment_self'), 1e-9);
    }

    // ------------------------------------------------------------------ #8 the saturating display

    /**
     * §18 #8: a partner's comfort and trust saturate, 100 x (1 - (1 - x/100)^mult): the slope of
     * x x mult at the bottom, strictly increasing, 100 only at 100. Raw 46 and raw 80 no longer
     * both read as 100.
     */
    public function testAPartnersComfortAndTrustSaturate(): void
    {
        $d = $this->npc(['comfort' => 46.0, 'trust' => 46.0], [], 80.0);
        foreach (['comfort', 'trust'] as $dim) {
            $m = RelationshipDynamics::perBondMultiplier($d, $dim);
            $this->assertGreaterThan(1.5, $m, $dim);
            $prev = -1.0;
            foreach (range(0, 100, 5) as $x) {
                $v = RelationshipDynamics::perBondScaled($dim, (float) $x, $m);
                $this->assertEqualsWithDelta(100.0 * (1.0 - pow(1.0 - $x / 100.0, $m)), $v, 1e-9, "{$dim} at {$x}");
                $this->assertGreaterThan($prev, $v, "{$dim}: increasing at {$x}");
                $this->assertLessThanOrEqual(100.0, $v);
                if ($x < 100) $this->assertLessThan(100.0, $v, "{$dim}: 100 only at 100");
                $prev = $v;
            }
            $this->assertEqualsWithDelta($m, RelationshipDynamics::perBondScaled($dim, 0.001, $m) / 0.001, 0.01, "{$dim}: x x mult at the bottom");
            $at46 = RelationshipDynamics::getEffectiveDimensionValue($d, $dim);
            $at80 = RelationshipDynamics::getEffectiveDimensionValue($d, $dim, 80.0);
            $this->assertLessThan($at80, $at46, "{$dim}: raw 46 and raw 80 read apart");
            $this->assertLessThan(100.0, $at80);
            if ($dim === 'comfort') $this->assertGreaterThanOrEqual(100.0, 46.0 * $m, 'the hard clamp read a partner at raw 46 as 100');
        }
        // at or below 1 the multiplier stays linear (it never clamps)
        $this->assertSame(40.0 * 0.5, RelationshipDynamics::perBondScaled('trust', 40.0, 0.5));
        $this->assertSame(40.0, RelationshipDynamics::perBondScaled('trust', 40.0, 1.0));
    }

    // ------------------------------------------------------------------ #9 masking off, Muiri fearful

    public function testSocialMaskingShipsOff(): void
    {
        $this->assertFalse(RelationshipDynamics::defaultConfig()['social_masking_enabled'], 'off until a playtest (§18 #9)');
        $GLOBALS['CACHE_PEOPLE'] = '|Mikael|Hulda|Kaida|';
        $d = $this->npc(['maturity' => 60.0], ['profile_overrides' => ['attachment_style' => 'toxic']]);
        $this->assertFalse(RelationshipDynamics::shouldMask('Lydia', $d), 'nobody masks with it off');
    }

    /**
     * §18 #9: Muiri's preset puts her just inside the fearful region (never derived: MDD 6.1),
     * short of the textbook corner; an editor's attachment still beats it, and every other test
     * bed keeps what her own read gives.
     */
    public function testMuirisPresetNudgesHerIntoTheFearfulRegion(): void
    {
        $presets = RelationshipDynamics::temperamentAutogenDefaults()['npc_overrides'];
        $axes = $presets['muiri']['attachment_axes'];
        $cfg = RelationshipDynamics::getAttachmentConfig();
        $this->assertSame('toxic', RelationshipDynamics::attachmentStyleOf($axes['anxiety'], $axes['avoidance']), 'the fearful region');
        foreach (['anxiety', 'avoidance'] as $axis) {
            $this->assertGreaterThanOrEqual($cfg['thresholds'][$axis], $axes[$axis]);
            $this->assertLessThan($cfg['prototype']['high'], $axes[$axis], "{$axis}: a nudge, not the textbook corner");
        }
        // the derivation alone never lands there, whatever the evidence
        $derived = RelationshipDynamics::deriveAttachmentAxes(['traits' => ['insecure', 'egocentric'], 'losses' => 3,
            'text_hits' => ['anxiety' => 3, 'avoidance' => 3], 'temperament' => 'Jealous']);
        $this->assertNotSame('toxic', RelationshipDynamics::attachmentStyleOf($derived['anxiety'], $derived['avoidance']));

        $muiri = $this->npc([], ['profile_overrides' => [], '_profile_autogen' => ['preset' => ['attachment_axes' => $axes]]]);
        $this->assertSame('toxic', RelationshipDynamics::getAttachmentStyle($muiri));
        $this->assertSame('preset', RelationshipDynamics::getAttachmentAxes($muiri)['source']);
        $muiri['profile_overrides']['attachment_style'] = 'secure';
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($muiri), "the editor's choice beats the preset");
        foreach (['aela the huntress', 'lynly star-sung'] as $other) {
            $p = $presets[$other]['attachment_axes'] ?? null;
            if ($p !== null) $this->assertNotSame('toxic', RelationshipDynamics::attachmentStyleOf($p['anxiety'], $p['avoidance']), $other);
        }
        $this->assertArrayNotHasKey('lynly star-sung', $presets);
    }

    // ------------------------------------------------------------------ continue answering another NPC

    /**
     * A continue / continue_group reaches the prerequest before core has resolved whom it
     * answers (main.php sets RECHAT_PREVIOUS_SPEAKER after the prerequest hooks, from its last
     * speech row). RelDyn reads the same row: after another NPC's line it is an NPC exchange (no
     * contact of the player pair); after the player's line, or her own, it is not. Once core has
     * resolved it, core's answer is used and nothing is read.
     */
    public function testAContinueAnsweringAnotherNpcIsAnNpcExchange(): void
    {
        foreach (['continue', 'continue_group'] as $type) {
            $req = [$type, '1727000000', (string) self::T0, ''];
            $this->db->lastSpeaker = 'Mikael';
            $this->assertSame('Mikael', RelationshipDynamics::previousNpcSpeaker($req, 'Kaida', 'Ysolda'), $type);
            $this->assertTrue(RelationshipDynamics::isNpcExchange($req, 'Kaida', 'Ysolda'), $type);
            $this->db->lastSpeaker = 'Kaida';
            $this->assertFalse(RelationshipDynamics::isNpcExchange($req, 'Kaida', 'Ysolda'), "{$type} after the player");
            $this->db->lastSpeaker = 'Ysolda';
            $this->assertFalse(RelationshipDynamics::isNpcExchange($req, 'Kaida', 'Ysolda'), "{$type} of her own line");
            $this->db->lastSpeaker = 'The Narrator';
            $this->assertFalse(RelationshipDynamics::isNpcExchange($req, 'Kaida', 'Ysolda'), "{$type} after the Narrator");
            $this->db->lastSpeaker = null;
            $this->assertFalse(RelationshipDynamics::isNpcExchange($req, 'Kaida', 'Ysolda'), "{$type}: no speech yet");
        }
        $this->assertSame(["SELECT speaker FROM speech ORDER BY rowid DESC LIMIT 1"], array_values(array_unique($this->db->speechReads)),
            "core's own query, read only");

        // core resolved it (the later hooks): its answer, nothing read
        $reads = count($this->db->speechReads);
        $this->db->lastSpeaker = 'Mikael';
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = 'Kaida';
        $this->assertFalse(RelationshipDynamics::isNpcExchange(['continue', '1', '2', ''], 'Kaida', 'Ysolda'));
        $GLOBALS['RECHAT_PREVIOUS_SPEAKER'] = '';
        $this->assertFalse(RelationshipDynamics::isNpcExchange(['continue', '1', '2', ''], 'Kaida', 'Ysolda'), 'core found no speaker');
        $this->assertSame($reads, count($this->db->speechReads));
        unset($GLOBALS['RECHAT_PREVIOUS_SPEAKER']);
        // the player's own line and a rechat are not read from the speech table
        $this->assertFalse(RelationshipDynamics::isNpcExchange(['inputtext', '1', '2', 'Kaida: hi (Talking to Ysolda)'], 'Kaida', 'Ysolda'));
        $this->assertTrue(RelationshipDynamics::isNpcExchange(['rechat', '1', '2', json_encode(['speaker' => 'Mikael'])], 'Kaida', 'Ysolda'));
        $this->assertSame($reads, count($this->db->speechReads));
    }
}
