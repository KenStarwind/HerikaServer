<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/** Stand-in for $db: conf_opts rows in memory and core_npc_master genders (the config store, the pronoun read). */
final class RelDynWSocialFakeDb
{
    public array $rows = [];

    public function __construct(public array $genders = []) {}

    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (stripos($sql, 'INSERT INTO conf_opts') !== false) {
            $this->rows[(string) $params[0]] = (string) $params[1];
            return ['id' => (string) $params[0]];
        }
        if (stripos($sql, 'FROM conf_opts') !== false) {
            $id = is_array($params) ? (string) $params[0] : (preg_match("/id = '([^']+)'/", $sql, $m) ? $m[1] : '');
            return isset($this->rows[$id]) ? ['value' => $this->rows[$id]] : [];
        }
        if (stripos($sql, 'FROM core_npc_master') !== false) {
            foreach ($this->genders as $name => $gender) {
                if (strtolower((string) $name) === strtolower((string) ($params[0] ?? ''))) return ['npc_name' => $name, 'gender' => $gender];
            }
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * The duty affinity channel (roadmap duty-affinity-channel; Ken 2026-10-01 §24, pipeline Addendum 9), the pure parts:
 * who is bound, what earns it and how it diminishes, who the NPC is (the dutiful trait scales it and nobody is a wall),
 * that it decays slower than affinity and passion, that mistreatment wears it down, its own <duty_context> words in
 * every gender, and above all that it never feeds desire: not sex_disposal, not passion, not the consent decision, not
 * romance. The test beds through the real hooks are RelDynWSocialDutyTestBedsPostgresTest.
 *
 * Units: duty value 0..100 points; time on the game calendar (raw gamets, RelationshipDynamics::GAMETS_PER_DAY).
 */
final class RelDynWSocialDutyTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 100 * RelationshipDynamics::GAMETS_PER_DAY;

    private array $saved = [];
    private string $logFile = '';
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynPronouns::reset();
        RelDynTraits::$assignmentOverride = 'read';
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdwduty');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        $log = (string) @file_get_contents($this->logFile);
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelDynPronouns::reset();
        $this->assertStringNotContainsString('ERROR', $log, $log);
    }

    private function storeConfig(array $row, array $genders = []): void
    {
        $db = new RelDynWSocialFakeDb($genders);
        $db->rows[RelationshipDynamics::CONFIG_ROW_ID] = json_encode($row + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC toward the player: a hand-set trait vector (dutiful trait D), core affinity and type, passion, maturity. */
    private function npc(float $dutiful = 0.5, array $o = []): array
    {
        $o += ['aff' => 40.0, 'type' => 'friend', 'passion' => 10.0, 'maturity' => 55.0, 'role' => 'follower'];
        $x = ['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => $dutiful, 'Po' => 0.5, 'Pr' => 0.5];
        $x['maturity_start'] = $o['maturity'];
        $mirror = ($o['aff'] + 100.0) / 2.0;
        $d = [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            '_core_rel_type' => $o['type'],
            '_aff_mirror_x' => $mirror,
            'dimensions' => ['affinity' => ['x' => $mirror, 'baseline' => null], 'passion' => ['x' => $o['passion'], 'baseline' => 0.0],
                'maturity' => ['x' => $o['maturity'], 'baseline' => $o['maturity']]],
            'passion' => $o['passion'],
        ];
        if ($o['role'] !== null) $d[RelDynDuty::ROLE_OVERRIDE_KEY] = $o['role'];
        return $d;
    }

    private function serve(array &$d, float $hours, float $from = self::T0): float
    {
        $now = $from;
        RelDynDuty::advance('Lydia', $d, $now);
        for ($h = 1; $h <= $hours; $h += 3) {
            $now = $from + $h * self::HOUR;
            RelDynDuty::advance('Lydia', $d, $now);
        }
        return $now;
    }

    // ------------------------------------------------------------------ who is bound

    public function testARoleIsDerivedOrHandSetAndNoneNeverBinds(): void
    {
        $this->assertNull(RelDynDuty::roleFor('Lydia', $this->npc(0.5, ['role' => null])), 'a stranger to service');
        $this->assertSame(['role' => 'housecarl', 'strength' => 1.0, 'source' => 'editor'], RelDynDuty::roleFor('Lydia', $this->npc(0.5, ['role' => 'housecarl'])));
        $this->assertSame(0.8, RelDynDuty::roleFor('Lydia', $this->npc(0.5, ['role' => 'follower']))['strength']);
        $this->assertNull(RelDynDuty::roleFor('Lydia', $this->npc(0.5, ['role' => 'none'])), 'hand-set to never bound, even in the party');
        // derived: the player's party, and core's sworn type (this channel does not depend on the sworn type; it is just one way to be bound)
        $GLOBALS['CACHE_PARTY'] = ['Lydia' => ['name' => 'Lydia']];
        $this->assertSame('follower', RelDynDuty::roleFor('Lydia', $this->npc(0.5, ['role' => null]))['role']);
        $this->assertNull(RelDynDuty::roleFor('Brynjolf', $this->npc(0.5, ['role' => null])), 'not in the party');
        unset($GLOBALS['CACHE_PARTY']);
        $d = $this->npc(0.5, ['role' => null, 'type' => 'sworn']);
        $this->assertSame(['role' => 'sworn', 'strength' => 1.0, 'source' => 'type:sworn'], RelDynDuty::roleFor('Lydia', $d));
        // the strongest wins: a party member who is also sworn is sworn
        $GLOBALS['CACHE_PARTY'] = ['Lydia' => []];
        $this->assertSame('sworn', RelDynDuty::roleFor('Lydia', $d)['role']);
    }

    // ------------------------------------------------------------------ what earns it

    public function testServiceEarnsDutyByTimeInServiceBoundedPerStep(): void
    {
        $d = $this->npc();
        RelDynDuty::advance('Lydia', $d, self::T0);
        $this->assertSame(0.0, RelDynDuty::value($d), 'the first turn in service starts the clock');
        $r = RelDynDuty::advance('Lydia', $d, self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta(0.25 * 2 * 0.8, $r['service'], 1e-6, 'per game hour x the role\'s strength');
        $this->assertEqualsWithDelta($r['service'], RelDynDuty::value($d), 1e-6);
        // a week away earns one bounded step, not a week of service
        $before = RelDynDuty::value($d);
        $r = RelDynDuty::advance('Lydia', $d, self::T0 + 7 * self::DAY);
        $this->assertEqualsWithDelta(0.25 * 6.0 * 0.8 * (1.0 - $before / 100.0), $r['service'], 0.01, 'service.max_step_game_hours');
    }

    public function testEveryGainDiminishesTowardAHundred(): void
    {
        $low = $this->npc();
        $high = $this->npc();
        $high[RelDynDuty::KEY] = ['value' => 90.0, 'gamets' => self::T0, 'last_credit' => self::T0, 'role' => 'follower', 'bound' => true];
        RelDynDuty::advance('Lydia', $low, self::T0);
        RelDynDuty::advance('Lydia', $low, self::T0 + 4 * self::HOUR);
        RelDynDuty::advance('Lydia', $high, self::T0 + 4 * self::HOUR);
        $this->assertGreaterThan(5 * (RelDynDuty::value($high) - 90.0), RelDynDuty::value($low), 'the last stretch is slow');
        $this->assertLessThanOrEqual(100.0, RelDynDuty::value($high));
    }

    public function testTheDutifulTraitScalesTheGainAndNobodyIsAWall(): void
    {
        $gain = function (float $dutiful): float {
            $d = $this->npc($dutiful);
            RelDynDuty::advance('Lydia', $d, self::T0);
            RelDynDuty::advance('Lydia', $d, self::T0 + 4 * self::HOUR);
            return RelDynDuty::value($d);
        };
        $impulsive = $gain(0.0);
        $middle = $gain(0.5);
        $dutiful = $gain(1.0);
        $this->assertGreaterThan(0.0, $impulsive, 'an impulsive NPC still earns duty: no one is exempt');
        $this->assertGreaterThan($impulsive, $middle);
        $this->assertGreaterThan($middle, $dutiful);
        $this->assertEqualsWithDelta(1.6 / 0.4, $dutiful / $impulsive, 0.01, 'disposition 0.4 .. 1.6 around the middle');
    }

    public function testFightingBesideThePlayerEarnsMoreAgainstMoreDangerousFoesAndSeeingItEarnsAShare(): void
    {
        $earn = function (bool $fought, ?string $threat, $danger = false, ?string $role = 'follower'): float {
            $d = $this->npc(0.5, ['role' => $role]);
            RelDynDuty::onFight('Lydia', $d, $fought, $threat, $danger, self::T0);
            return RelDynDuty::value($d);
        };
        $weak = $earn(true, 'weak');
        $regular = $earn(true, 'regular');
        $mighty = $earn(true, 'mighty');
        $this->assertGreaterThan(0.0, $weak);
        $this->assertGreaterThan($weak, $regular);
        $this->assertGreaterThan($regular, $mighty);
        $this->assertEqualsWithDelta(2.0 / 0.4, $mighty / $weak, 0.01, 'weak 0.4, regular 1, mighty 2');
        $this->assertEqualsWithDelta($regular * 1.5, $earn(true, 'regular', true), 1e-6, 'shared danger');
        $this->assertEqualsWithDelta($regular * 0.25, $earn(false, 'regular'), 1e-6, 'a fight only seen earns a share');
        $this->assertEqualsWithDelta($regular, $earn(true, 'regular', true) / 1.5, 1e-6);
        $this->assertSame(0.0, $earn(true, 'mighty', true, 'none'), 'a hand-set none never serves');
        $this->assertSame(0.0, $earn(true, 'mighty', true, null), 'not bound: no duty from a fight');
    }

    public function testTheDangerIsOnlyLookedAtForABoundNPCWhoFought(): void
    {
        $asked = 0;
        $probe = function () use (&$asked): bool { $asked++; return true; };
        $unbound = $this->npc(0.5, ['role' => null]);
        RelDynDuty::onFight('Lydia', $unbound, true, 'regular', $probe, self::T0);
        $saw = $this->npc();
        RelDynDuty::onFight('Lydia', $saw, false, 'regular', $probe, self::T0);
        $this->assertSame(0, $asked, 'no read of the NPC\'s health for one who is not bound, or who only watched');
        $fought = $this->npc();
        RelDynDuty::onFight('Lydia', $fought, true, 'regular', $probe, self::T0);
        $this->assertSame(1, $asked);
    }

    public function testAQuestStageCountsOnceAndTheFinishingStageEarnsTheCompletionOnce(): void
    {
        $d = $this->npc();
        $stage = RelDynDuty::onQuest('Lydia', $d, 'DA02', 30, self::T0);
        $this->assertEqualsWithDelta(1.0 * 0.8, $stage, 1e-6);
        $this->assertSame(0.0, RelDynDuty::onQuest('Lydia', $d, 'DA02', 30, self::T0 + self::HOUR), 'the same stage again is nothing');
        $done = RelDynDuty::onQuest('Lydia', $d, 'DA02', 200, self::T0 + 2 * self::HOUR);
        $this->assertEqualsWithDelta((1.0 + 4.0) * 0.8 * (1.0 - RelDynDuty::value($d) / 100.0 + $done / 100.0), $done, 0.2, 'a stage and the completion');
        $this->assertGreaterThan($stage * 3, $done);
        $again = RelDynDuty::onQuest('Lydia', $d, 'DA02', 210, self::T0 + 3 * self::HOUR);
        $this->assertLessThan($stage * 1.01, $again, 'the quest is completed once: a later stage is only a stage');
        $unbound = $this->npc(0.5, ['role' => null]);
        $this->assertSame(0.0, RelDynDuty::onQuest('Lydia', $unbound, 'DA02', 200, self::T0));
    }

    public function testBeingLookedAfterEarnsDutyAndBeingTreatedBadlyWearsItDownNeverToNothingForADutifulNPC(): void
    {
        $d = $this->npc();
        $d[RelDynDuty::KEY] = ['value' => 40.0, 'gamets' => self::T0, 'last_credit' => self::T0, 'role' => 'follower', 'bound' => true];
        $care = RelDynDuty::onEvalItem('Lydia', ['tags' => ['help'], 'significance' => 0.6], ['affinity' => 2.0], $d, self::T0);
        $this->assertGreaterThan(0.0, $care);
        $this->assertSame(0.0, RelDynDuty::onEvalItem('Lydia', ['tags' => ['help'], 'significance' => 0.1], ['affinity' => 0.0], $d, self::T0), 'a trifle');
        $this->assertSame(0.0, RelDynDuty::onEvalItem('Lydia', ['tags' => ['praise'], 'significance' => 0.9], ['affinity' => 3.0], $d, self::T0), 'praise is not service');
        $this->assertSame(0.0, RelDynDuty::onEvalItem('Lydia', ['tags' => [], 'significance' => 0.5], ['affinity' => -3.0], $d, self::T0), 'a loss under the line is nothing');
        // a big loss (core points = mirror x2): (30 - 10) x 0.15 = 3 points at the middle
        $before = RelDynDuty::value($d);
        $lost = RelDynDuty::onEvalItem('Lydia', ['tags' => ['insult'], 'significance' => 0.8], ['affinity' => -15.0], $d, self::T0);
        $this->assertEqualsWithDelta(-3.0, $lost, 1e-6);
        $this->assertEqualsWithDelta($before - 3.0, RelDynDuty::value($d), 1e-6);
        // who the NPC is: an impulsive NPC loses more of it than a dutiful one, and the dutiful one loses something
        $lose = function (float $dutiful): float {
            $x = $this->npc($dutiful);
            $x[RelDynDuty::KEY] = ['value' => 40.0, 'gamets' => self::T0, 'last_credit' => self::T0, 'role' => 'follower', 'bound' => true];
            return RelDynDuty::onEvalItem('Lydia', ['tags' => ['insult'], 'significance' => 0.8], ['affinity' => -15.0], $x, self::T0);
        };
        $this->assertLessThan($lose(1.0), $lose(0.0), 'the impulsive lose more');
        $this->assertLessThan(0.0, $lose(1.0), 'no one is immune');
        $this->assertGreaterThan(-0.2 * 40.0, $lose(0.0));
    }

    // ------------------------------------------------------------------ it lingers

    public function testItDecaysSlowerThanAffinityAndPassionAndSlowerStillWhileBound(): void
    {
        $idleDays = 60.0;
        $mk = function (float $dutiful, ?string $role): array {
            $d = $this->npc($dutiful, ['role' => $role]);
            $d[RelDynDuty::KEY] = ['value' => 60.0, 'gamets' => self::T0, 'last_credit' => self::T0, 'role' => 'follower', 'bound' => $role !== null && $role !== 'none'];
            return $d;
        };
        $after = function (array $d) use ($idleDays): float {
            RelDynDuty::advance('Lydia', $d, self::T0 + $idleDays * self::DAY);
            return RelDynDuty::value($d);
        };
        $former = $after($mk(0.5, 'none'));
        $bound = $after($mk(0.5, 'follower'));
        $dutiful = $after($mk(1.0, 'none'));
        $impulsive = $after($mk(0.0, 'none'));
        // the half-life is 180 game days; the grace is 2 days: 0.5^(58/180) of it left
        $this->assertEqualsWithDelta(60.0 * pow(0.5, 58.0 / 180.0), $former, 0.05);
        $this->assertGreaterThan($former, $bound, 'loyalty to the post: slower while still bound');
        $this->assertGreaterThan($former, $dutiful);
        $this->assertGreaterThan($impulsive, $former, 'a dutiful NPC holds it longer, an impulsive one lets it go sooner');
        $this->assertGreaterThan(0.0, $impulsive);
        // the slowest affinity decay there is (an Independent NPC, -0.1 core points per tick) and the slowest passion decay (2.5 points per
        // play hour, 20 game hours), both per game day, against the FASTEST way duty can fade (an impulsive NPC no longer serving)
        $affinityPerDay = abs(max(RelationshipDynamics::TEMPERAMENT_DECAY_RATES)) * self::DAY / RelationshipDynamics::GAMETS_PER_DECAY_TICK;
        $gameSecondsPerPlaySecond = RelationshipDynamics::GAMETS_PER_REAL_SECOND / (self::DAY / 86400);
        $passionPerDay = min(array_column(RelationshipDynamics::CURVE_PARAMS, 'passion_decay')) * 24.0 / $gameSecondsPerPlaySecond;
        $x = $mk(0.0, 'none');
        RelDynDuty::advance('Lydia', $x, self::T0 + 2.0 * self::DAY);
        RelDynDuty::advance('Lydia', $x, self::T0 + 3.0 * self::DAY);
        $dutyPerDay = 60.0 - RelDynDuty::value($x);
        $this->assertGreaterThan(0.0, $dutyPerDay);
        $this->assertLessThan($affinityPerDay, $dutyPerDay, 'slower than the slowest affinity decay, even at its fastest');
        $this->assertLessThan($passionPerDay, $dutyPerDay, 'and slower than passion');
        // the grace: nothing is lost in the first two days after the last credit
        $x = $mk(0.5, 'none');
        RelDynDuty::advance('Lydia', $x, self::T0 + 1.9 * self::DAY);
        $this->assertSame(60.0, RelDynDuty::value($x));
    }

    // ------------------------------------------------------------------ the words

    public function testTheDutyContextIsItsOwnBlockAboutTheRoleAndTheBandAndFollowsTheRegard(): void
    {
        $this->storeConfig([], ['Lydia' => 'female', 'Jordis' => 'male', 'Vex' => '']);
        $mk = function (float $value, float $aff, bool $bound = true, string $role = 'housecarl'): array {
            $d = $this->npc(0.5, ['aff' => $aff]);
            $d[RelDynDuty::KEY] = ['value' => $value, 'gamets' => self::T0, 'last_credit' => self::T0, 'role' => $role, 'bound' => $bound];
            return $d;
        };
        $block = RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(30.0, 60.0));
        $this->assertStringStartsWith("<duty_context>\n", (string) $block);
        $this->assertStringEndsWith("\n</duty_context>", (string) $block);
        $this->assertStringContainsString('Lydia is sworn to the household of Kaida.', $block);
        $this->assertStringContainsString('duty and fondness', $block, 'warm regard');
        $this->assertStringContainsString('She ', RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(30.0, 60.0)), 'the NPC\'s own gender');
        $this->assertStringContainsString('He ', RelDynDuty::contextBlock('Jordis', 'Kaida', $mk(30.0, 60.0)));
        $this->assertStringContainsString('They ', RelDynDuty::contextBlock('Vex', 'Kaida', $mk(30.0, 60.0)));
        $cool = (string) RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(30.0, 0.0));
        $this->assertStringContainsString('not affection', $cool);
        $this->assertStringContainsString('resent', (string) RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(30.0, -40.0)), 'duty to a player the NPC resents is still duty');
        // bands by how much is held
        $steady = (string) RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(15.0, 60.0));
        $devoted = (string) RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(50.0, 60.0));
        $sworn = (string) RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(85.0, 60.0));
        $this->assertNotSame($steady, $devoted);
        $this->assertNotSame($devoted, $sworn);
        $this->assertStringContainsString('hold the line', $sworn);
        // below the first band, or never in service: no block; a former follower's duty lingers while it is held
        $this->assertNull(RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(5.0, 60.0)));
        $this->assertNull(RelDynDuty::contextBlock('Lydia', 'Kaida', $this->npc()));
        $former = (string) RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(40.0, 60.0, false));
        $this->assertStringContainsString('once served Kaida', $former);
        $this->assertNull(RelDynDuty::contextBlock('Lydia', 'Kaida', $mk(12.0, 60.0, false)), 'a faded duty is not said');
        // feelings, never numbers
        $defaultWords = RelDynDuty::configDefaults()['text'];
        foreach ([$block, $cool, $steady, $devoted, $sworn, $former] as $text) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $text);
        }
        // the shipped words carry no gendered pronoun of their own: every one comes from the NPC's gender
        $words = [];
        array_walk_recursive($defaultWords, function ($v) use (&$words) { $words[] = $v; });
        foreach ($words as $w) $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|him|his|hers|herself|himself)\b/i', (string) $w);
        $off = $this->npc();
        $this->storeConfig(['duty' => ['enabled' => false]]);
        $on = $mk(50.0, 60.0);
        $this->assertNull(RelDynDuty::contextBlock('Lydia', 'Kaida', $on), 'off: no block');
        $this->assertEqualsWithDelta(50.0, RelDynDuty::value($on), 1e-9, 'and what was earned is kept');
        $this->assertSame(0.0, RelDynDuty::onFight('Lydia', $off, true, 'mighty', true, self::T0), 'off: nothing new is earned');
    }

    // ------------------------------------------------------------------ never desire

    public function testDutyNeverFeedsDesire(): void
    {
        $plain = $this->npc(0.5, ['aff' => 55.0, 'type' => 'romantic', 'passion' => 40.0]);
        $sworn = $plain;
        $sworn[RelDynDuty::KEY] = ['value' => 95.0, 'gamets' => self::T0, 'last_credit' => self::T0, 'role' => 'sworn', 'bound' => true,
            'sources' => ['service' => 40.0, 'combat' => 40.0, 'quest' => 10.0, 'care' => 5.0, 'mistreat' => 0.0]];
        // sex_disposal, passion, the attraction interest, the consent decision: identical with and without a sworn protector's duty
        $this->assertSame(RelationshipDynamics::getEffectiveDisposition(8, $plain), RelationshipDynamics::getEffectiveDisposition(8, $sworn));
        $this->assertSame(RelationshipDynamics::getEffectivePassion($plain), RelationshipDynamics::getEffectivePassion($sworn));
        $this->assertSame(RelDynAttraction::interest($plain), RelDynAttraction::interest($sworn));
        $a = RelDynConsent::decide('Test NPC', $plain, null, 'Kaida');
        $b = RelDynConsent::decide('Test NPC', $sworn, null, 'Kaida');
        $this->assertSame($a, $b, 'the consent decision does not read duty');
        // and a gain of duty moves none of it
        $x = $this->npc(0.5, ['aff' => 55.0, 'type' => 'romantic', 'passion' => 40.0]);
        $beforeDimensions = $x['dimensions'];
        RelDynDuty::advance('Lydia', $x, self::T0);
        RelDynDuty::advance('Lydia', $x, self::T0 + 6 * self::HOUR);
        RelDynDuty::onFight('Lydia', $x, true, 'mighty', true, self::T0 + 7 * self::HOUR);
        RelDynDuty::onQuest('Lydia', $x, 'MQ', 200, self::T0 + 8 * self::HOUR);
        $this->assertGreaterThan(5.0, RelDynDuty::value($x));
        $this->assertSame($beforeDimensions, $x['dimensions'], 'duty earns on its own channel and touches no dimension');
        $this->assertSame(40.0, $x['passion']);
    }

    public function testNoDesireOrRomanceCodeReadsTheDutyChannel(): void
    {
        $root = __DIR__ . '/../../ext/relationship_dynamics';
        $readers = [];
        foreach (glob($root . '/*.php') as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, 'RelDynDuty') || str_contains($src, '_duty_affinity')) $readers[] = basename($file);
        }
        sort($readers);
        // the channel itself, the places that feed or show it, and the plumbing around a state key; never a desire or romance file
        $allowed = ['context.php', 'prerequest.php', 'relationship_dynamics.php', 'reldyn_combat.php', 'reldyn_duty.php', 'reldyn_editor.php',
            'reldyn_jev.php', 'reldyn_settings.php', 'reldyn_settings_text.php', 'reldyn_timeline.php'];
        $this->assertSame([], array_values(array_diff($readers, $allowed)), 'a file outside the duty channel reads it: ' . implode(', ', array_diff($readers, $allowed)));
        foreach (['reldyn_consent.php', 'reldyn_passion.php', 'reldyn_romance.php', 'reldyn_attraction.php', 'reldyn_governors.php', 'reldyn_exclusivity.php',
                  'reldyn_intimacy.php', 'reldyn_post_intimacy.php', 'reldyn_gifts.php', 'reldyn_fulfillment.php', 'reldyn_keeping.php', 'reldyn_pullback.php'] as $never) {
            $this->assertNotContains($never, $readers, "{$never} is desire / romance / bond-depth: duty never feeds it");
        }
        // inside relationship_dynamics.php the channel is touched only by its three event hooks and the config default
        $src = (string) file_get_contents($root . '/relationship_dynamics.php');
        preg_match_all('/RelDynDuty::(\w+)/', $src, $m);
        $this->assertEqualsCanonicalizing(['onEvalItem', 'onQuest', 'configDefaults'], array_values(array_unique($m[1])));
    }

    // ------------------------------------------------------------------ state and config

    public function testASaveLoadClampsTheChannelsClocksToTheLoadedTime(): void
    {
        $d = $this->npc();
        $d[RelDynDuty::KEY] = ['value' => 33.0, 'gamets' => self::T0 + 9 * self::DAY, 'last_credit' => self::T0 + 8 * self::DAY, 'since' => self::T0 + 7 * self::DAY,
            'role' => 'follower', 'bound' => true];
        $r = RelDynTimeline::rebaselineDynamics($d, self::T0, null);
        foreach (['gamets', 'last_credit', 'since'] as $stamp) {
            $this->assertSame((float) self::T0, floatval($r[RelDynDuty::KEY][$stamp]), $stamp);
        }
        $this->assertSame(33.0, $r[RelDynDuty::KEY]['value'], 'the duty earned is kept by the policy, only its clocks come back');
    }

    public function testAPartialStoredConfigReadsLikeTheWholeSectionAndTheSectionIsOnTheHub(): void
    {
        $this->storeConfig(['duty' => ['service' => ['per_game_hour' => 1.0], 'text' => ['band' => ['steady' => 'edited.']]]]);
        $cfg = RelDynDuty::config();
        $this->assertEquals(1.0, $cfg['service']['per_game_hour']);
        $this->assertEquals(6.0, $cfg['service']['max_step_game_hours'], 'the rest of the table stays default');
        $this->assertSame('edited.', $cfg['text']['band']['steady']);
        $this->assertArrayHasKey('devoted', $cfg['text']['band']);
        $this->assertArrayHasKey('housecarl', $cfg['text']['role']);
        $this->assertArrayHasKey('duty', RelDynSettings::readers());
        $this->assertSame('inner', RelDynSettings::groupOf('duty'));
        $this->assertTrue(RelDynSettingsText::isExplicit('duty.enabled'));
    }

    public function testJevShowsTheChannel(): void
    {
        $d = $this->npc();
        $d[RelDynDuty::KEY] = ['value' => 45.0, 'gamets' => self::T0, 'role' => 'housecarl', 'bound' => true, 'sources' => ['service' => 30.0, 'combat' => 15.0]];
        $j = RelDynDuty::jev($d);
        $this->assertSame(45.0, $j['value']);
        $this->assertSame('devoted', $j['band']);
        $this->assertSame('housecarl', $j['role']);
        $this->assertTrue($j['bound']);
        $this->assertSame('follower', $j['override']);
        $this->assertSame(30.0, $j['sources']['service']);
        $this->assertSame(0.0, $j['sources']['mistreat'] ?? 0.0);
    }
}
