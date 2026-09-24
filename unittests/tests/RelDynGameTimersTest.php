<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory CHIM $db for RelDyn timer tests.
 *  - core_npc_master: extended_data stored as JSON text, so RelDyn's jsonb_set save
 *    and its SELECT reload round-trip through real json_encode/json_decode.
 *  - plugin_extended_data: the RelDynStorage / NpcMaster::getPluginData query shapes
 *    (RelDyn state lives in plugin_extended_data.reldyn; extended_data is migrated once).
 *  - eventlog: rows with type/data/people/gamets/ts, honouring the gamets/ts bounds
 *    and LIKE filters RelDyn puts in its queries.
 */
final class RelDynTimersFakeDb
{
    /** @var array<string,string> lower(npc_name) => extended_data JSON */
    public array $npcs = [];
    /** @var array<string,array> lower(npc_name) => plugin_extended_data */
    public array $plugin = [];
    public array $eventlog = [];
    public array $queries = [];

    private function keyOf(int $id): ?string
    {
        return array_keys($this->npcs)[$id - 1] ?? null;
    }

    /** RelDynStorage / NpcMaster plugin-data statements (parameterized). */
    private function pluginQuery(string $q, array $params)
    {
        $sql = preg_replace('/\s+/', ' ', trim($q));
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $i = array_search(strtolower((string)$params[0]), array_keys($this->npcs), true);
            return $i === false ? null : ['id' => (string)($i + 1)];
        }
        $key = $this->keyOf((int)($params[0] ?? 0));
        if ($key === null) {
            return null;
        }
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $ns = $this->plugin[$key][$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object)$ns)];
        }
        if (strpos($sql, 'WITH cur AS (') === 0 && strpos($sql, '#-') !== false) {
            $value = $this->plugin[$key][$params[1]][$params[2]] ?? null;
            unset($this->plugin[$key][$params[1]][$params[2]]);
            return ['inbox' => $value === null ? null : json_encode($value)];
        }
        if (strpos($sql, "extended_data -> 'relationship_dynamics'") !== false) {
            $legacy = (json_decode($this->npcs[$key], true) ?: [])['relationship_dynamics'] ?? null;
            if (isset($this->plugin[$key][$params[1]][$params[2]]) || !is_array($legacy) || $legacy === []) {
                return null;
            }
            $this->plugin[$key][$params[1]][$params[2]] = $legacy;
            return ['id' => (string)$params[0]];
        }
        if (strpos($sql, 'jsonb_build_array($4::jsonb)') !== false) {
            $this->plugin[$key][$params[1]][$params[2]][] = json_decode($params[3], true);
            return ['id' => (string)$params[0]];
        }
        if (strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
            $this->plugin[$key][$params[1]][$params[2]] = json_decode($params[3], true);
            return ['id' => (string)$params[0]];
        }
        throw new RuntimeException('RelDynTimersFakeDb: unhandled parameterized query: ' . $sql);
    }

    public function escape($s) { return str_replace("'", "''", (string)$s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }

    private static function unescape(string $s): string { return str_replace("''", "'", $s); }

    public function fetchOne($q, array $params = [])
    {
        $this->queries[] = $q;
        if (!empty($params)) {
            return $this->pluginQuery($q, $params);
        }
        if (preg_match("/SELECT extended_data FROM core_npc_master WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)/", $q, $m)) {
            $key = strtolower(self::unescape($m[1]));
            return isset($this->npcs[$key]) ? ['extended_data' => $this->npcs[$key]] : null;
        }
        return null;
    }

    public function fetchAll($q)
    {
        $this->queries[] = $q;
        if (stripos($q, 'FROM eventlog') === false) {
            return [];
        }
        if (stripos($q, 'MAX(gamets)') !== false) {
            $max = 0;
            foreach ($this->eventlog as $r) { $max = max($max, $r['gamets']); }
            return $max > 0 ? [['m_gts' => (string)$max]] : [];
        }
        $rows = array_filter($this->eventlog, fn($r) => $this->matches($r, $q));
        if (preg_match('/COUNT\(\*\)\s+as\s+(\w+)/i', $q, $m)) {
            return [[$m[1] => count($rows)]];
        }
        return array_values($rows);
    }

    private function matches(array $r, string $q): bool
    {
        if (preg_match("/type\s*=\s*'([^']+)'/", $q, $m) && $r['type'] !== $m[1]) return false;
        if (preg_match('/gamets\s*(>=?)\s*(-?[\d.]+)/', $q, $m)) {
            if ($m[1] === '>' ? !($r['gamets'] > (float)$m[2]) : !($r['gamets'] >= (float)$m[2])) return false;
        }
        if (preg_match('/\bts\s*>\s*(-?[\d.]+)/', $q, $m) && !($r['ts'] > (float)$m[1])) return false;
        if (preg_match("/people LIKE '((?:[^']|'')*)'/", $q, $m) && !self::like($r['people'], self::unescape($m[1]))) return false;
        preg_match_all("/data LIKE '((?:[^']|'')*)'/", $q, $all);
        if (!empty($all[1])) {
            $hits = array_map(fn($p) => self::like($r['data'], self::unescape($p)), $all[1]);
            $ok = stripos($q, ' OR data LIKE') !== false ? in_array(true, $hits, true) : !in_array(false, $hits, true);
            if (!$ok) return false;
        }
        return true;
    }

    private static function like(string $value, string $pattern): bool
    {
        $re = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/is';
        $re = str_replace(['\.\*', '\.'], ['.*', '.'], $re); // undo preg_quote on wildcards
        return (bool)preg_match($re, $value);
    }

    public function execQuery($q)
    {
        $this->queries[] = $q;
        if (preg_match("/'\{relationship_dynamics\}', '((?:[^']|'')*)'::jsonb\) WHERE lower\(npc_name\) = lower\('((?:[^']|'')*)'\)/s", $q, $m)) {
            $key = strtolower(self::unescape($m[2]));
            $ext = json_decode($this->npcs[$key] ?? '{}', true) ?: [];
            $ext['relationship_dynamics'] = json_decode(self::unescape($m[1]), true);
            $this->npcs[$key] = json_encode($ext);
        }
        return true;
    }

    public function insert($table, $data) { $this->queries[] = "INSERT {$table}"; return true; }
    public function query($q) { $this->queries[] = $q; return true; }
}

/**
 * Timer rules: decay uses filtered play gamets, cooldowns use accumulated play time,
 * absence uses the game calendar — never the wall clock. All inputs are fixed gamets.
 */
final class RelDynGameTimersTest extends TestCase
{
    private const DAY = 10000000;
    private const HOUR = self::DAY / 24;
    /** One real minute of normal play measured on the game clock. */
    private const PLAY_MINUTE = 2315 * 60;

    private array $saved = [];
    private RelDynTimersFakeDb $db;

    protected function setUp(): void
    {
        foreach (['gameRequest', 'db', 'PLAYER_NAME', 'RELDYN_NPC_NAME', 'RELDYN_PLAYER_NAME', 'CACHE_PEOPLE', 'CACHE_PARTY', 'HERIKA_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynTimersFakeDb();
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
        self::clearNpcCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_timers_test.log');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v[0];
            }
        }
        RelationshipDynamics::clearConfigCache();
        self::clearNpcCache();
        Logger::unsetCustomLog();
    }

    private static function clearNpcCache(): void
    {
        // Storage has no process cache any more (A3); kept as the engine's no-op hook.
        RelationshipDynamics::clearNpcCache();
    }

    /** Game timestamps are integers on the wire; returns the value actually set. */
    private static function setClock(float $gamets, string $type = 'inputtext', string $data = 'Player: hi'): float
    {
        $g = (float)round($gamets);
        $GLOBALS['gameRequest'] = [$type, '1727000000', (string)(int)$g, $data];
        return $g;
    }

    private static function at(int $day, float $hour): float
    {
        return (float)round($day * self::DAY + $hour * self::HOUR);
    }

    // ---------------------------------------------------------------- helpers

    public function testPlayGametsSinceMeasuresFilteredPlayClock(): void
    {
        $dyn = ['_accumulated_play_gamets' => 1000000.0];
        $this->assertNull(RelationshipDynamics::playGametsSince($dyn, '_mark'), 'unset checkpoint');

        RelationshipDynamics::markPlayCheckpoint($dyn, '_mark');
        $this->assertSame(1000000.0, $dyn['_mark']);

        $dyn['_accumulated_play_gamets'] += self::PLAY_MINUTE;
        $this->assertEqualsWithDelta(self::PLAY_MINUTE, RelationshipDynamics::playGametsSince($dyn, '_mark'), 1e-6);

        // A wall-clock value left by an older build is ahead of the play clock: not trusted.
        $dyn['_mark'] = 1758000000;
        $this->assertNull(RelationshipDynamics::playGametsSince($dyn, '_mark'));
    }

    public function testGameHoursSinceUsesGameCalendar(): void
    {
        $dyn = [];
        self::setClock(self::at(10, 8));
        RelationshipDynamics::markGameClock($dyn, '_seen_gamets');
        $this->assertSame(self::at(10, 8), $dyn['_seen_gamets']);

        self::setClock(self::at(11, 14));
        $this->assertEqualsWithDelta(30.0, RelationshipDynamics::gameHoursSince($dyn, '_seen_gamets'), 1e-4);

        self::setClock(self::at(9, 0)); // earlier save loaded
        $this->assertNull(RelationshipDynamics::gameHoursSince($dyn, '_seen_gamets'));
    }

    // ---------------------------------------------------------------- ick

    private static function ickDynamics(float $playGamets): array
    {
        return [
            '_accumulated_play_gamets' => $playGamets,
            'interaction_count' => 5,
            'dimensions' => [
                'comfort' => ['x' => 60], 'passion' => ['x' => 50], 'resentment' => ['x' => 10],
                'warmth' => ['x' => 50], 'maturity' => ['x' => 0],
            ],
            '_ick_tracker' => [
                'romantic_count' => 4, 'total_count' => 5, 'window_start' => 0,
                'ick_active' => true, 'ick_triggered_at' => 0,
            ],
        ];
    }

    public function testIckCooldownRunsOnAccumulatedPlayTime(): void
    {
        $dyn = self::ickDynamics(5000000);
        $this->assertTrue(RelationshipDynamics::checkIckRecovery($dyn));
        $this->assertSame(5000000.0 + RelationshipDynamics::ICK_COOLDOWN_PLAY_GAMETS, (float)$dyn['_ick_tracker']['ick_cooldown_until_play_gamets']);
        $this->assertSame(600 * RelationshipDynamics::GAMETS_PER_REAL_SECOND, RelationshipDynamics::ICK_COOLDOWN_PLAY_GAMETS, '10 minutes of real play');

        // Unreceptive again and pushing: only the cooldown can hold the trigger back.
        $dyn['dimensions']['comfort']['x'] = 10;
        $dyn['dimensions']['passion']['x'] = 5;
        $dyn['_ick_tracker']['romantic_count'] = 5;
        $dyn['_ick_tracker']['total_count'] = 5;

        $dyn['_accumulated_play_gamets'] = 5000000 + 9 * self::PLAY_MINUTE;
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($dyn, 'Stoic'), 'still cooling down after 9 play minutes');

        $dyn['_accumulated_play_gamets'] = 5000000 + 11 * self::PLAY_MINUTE;
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($dyn, 'Stoic'), 'cooldown over after 11 play minutes');
    }

    public function testLegacyWallClockIckCooldownDoesNotBlock(): void
    {
        $dyn = self::ickDynamics(5000000);
        $dyn['dimensions']['comfort']['x'] = 10;
        $dyn['dimensions']['passion']['x'] = 5;
        $dyn['_ick_tracker']['ick_active'] = false;
        $dyn['_ick_tracker']['ick_cooldown_until'] = time() + 600; // written by the April build
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($dyn, 'Stoic'));
    }

    // ---------------------------------------------------------------- combat

    public function testKillStreakCountsOnlyRecentDeathsOnGameClock(): void
    {
        $now = self::at(20, 13);
        self::setClock($now, 'combatend', 'Lydia has defeated Bandit Chief');
        $window = RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS;
        $this->assertSame(300 * RelationshipDynamics::GAMETS_PER_REAL_SECOND, $window, '5 minutes of real play');

        foreach ([$now - 100000, $now - ($window - 1000), $now - ($window + 1000), $now - 3 * self::DAY] as $i => $g) {
            $this->db->eventlog[] = ['type' => 'death', 'data' => "Lydia has defeated Bandit {$i}", 'people' => '|Lydia|Player|', 'gamets' => $g, 'ts' => time()];
        }

        $ctx = RelationshipDynamics::getCombatContext('Lydia');
        $this->assertSame(2, $ctx['recent_kills']);
    }

    public function testBleedoutStampsPlayClockNotWallClock(): void
    {
        $this->db->npcs['lydia'] = json_encode(['relationship_dynamics' => [
            'love_language_primary' => RelationshipDynamics::LL_SERVICE,
            'inferred_temperament' => 'Stoic',
            'passion' => 10.0,
            'passion_updated_at' => 2900000.0,
            'last_interaction_at' => 2900000.0,
            'interaction_count' => 2,
            '_accumulated_play_gamets' => 3000000.0,
        ]]);
        self::setClock(self::at(20, 13), 'bleedout', 'Lydia falls to the ground, badly wounded.');
        $GLOBALS['RELDYN_NPC_NAME'] = 'Lydia';
        $GLOBALS['RELDYN_PLAYER_NAME'] = 'Player';
        $GLOBALS['CACHE_PEOPLE'] = '|Lydia|';

        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/postrequest.php'; })();

        self::clearNpcCache();
        $saved = RelationshipDynamics::getDynamics('Lydia');
        $this->assertLessThan(0.0, $saved['passion_sources']['combat'] ?? 0.0, 'bleedout handler ran and was saved');
        $this->assertLessThan(10.0, RelationshipDynamics::getPassion($saved), 'bleedout drain survives save (canonical passion setter)');
        $this->assertEquals(3000000.0, $saved['passion_updated_at'], 'passion clock is play gamets');
        $this->assertEquals(3000000.0, $saved['last_interaction_at'], 'interaction clock is play gamets');
    }

    // ---------------------------------------------------------------- items

    public function testItemEventsUseRecentGameClockWindow(): void
    {
        $now = self::at(30, 18);
        self::setClock($now);
        $this->assertSame(30 * RelationshipDynamics::GAMETS_PER_REAL_SECOND, RelationshipDynamics::ITEM_EVENT_WINDOW_GAMETS);
        $this->db->eventlog[] = ['type' => 'itemfound', 'data' => 'Player gave 1 Sweetroll to Lydia', 'people' => '', 'gamets' => $now - 10000, 'ts' => time()];
        $this->db->eventlog[] = ['type' => 'itemfound', 'data' => 'Player gave 1 Iron Dagger to Lydia', 'people' => '', 'gamets' => $now - 500000, 'ts' => time()];

        $events = RelationshipDynamics::detectItemEvents($GLOBALS['gameRequest'], 'Lydia', 'Player');
        $items = array_column(array_filter($events, fn($e) => $e['action'] === 'gift'), 'item');
        $this->assertSame(['Sweetroll'], $items);
    }

    // ---------------------------------------------------------------- walkaway / hoover

    private static function walkawayDynamics(): array
    {
        return [
            'attachment_style' => 'toxic',
            'dimensions' => [
                'resentment' => ['x' => 80, 'baseline' => 0, 'active' => true],
                'comfort' => ['x' => 10, 'baseline' => 50, 'active' => true],
                'trust' => ['x' => 30, 'baseline' => 50, 'active' => true],
                'maturity' => ['x' => 20, 'baseline' => 50, 'active' => true],
            ],
        ];
    }

    public function testBoundaryTestExpiresOnGameCalendar(): void
    {
        $start = self::at(40, 8);
        self::setClock($start);
        $dyn = self::walkawayDynamics();

        RelationshipDynamics::initiateWalkaway($dyn, 'Ashe', 'resentment');
        $this->assertSame($start, $dyn['_walkaway_started_gamets']);
        $dyn['_walkaway_boundary_test_hours'] = 30.0;

        RelationshipDynamics::processWalkawayTick($dyn, 'Ashe', 'Stoic'); // pending -> active
        $this->assertSame($start, $dyn['_walkaway_activated_gamets']);
        RelationshipDynamics::processWalkawayTick($dyn, 'Ashe', 'Stoic'); // active -> boundary_test
        $this->assertSame('boundary_test', $dyn['_walkaway_state']);
        $this->assertSame($start, $dyn['_boundary_test_started_gamets']);
        $dyn['dimensions']['resentment']['x'] = 80; // keep recovery out of reach

        self::setClock($start + 29 * self::HOUR);
        $this->assertNull(RelationshipDynamics::checkBoundaryTest($dyn), '29 game hours: still testing');

        self::setClock($start + 31 * self::HOUR);
        $this->assertSame('permanent', RelationshipDynamics::checkBoundaryTest($dyn), '31 game hours: expired');
    }

    public function testHooverWindowAndContextUseGameCalendar(): void
    {
        $start = self::at(50, 0) + 37; // seed: intval(start) % 100 = 37
        $dyn = self::walkawayDynamics();
        $dyn['_walkaway_state'] = 'boundary_test';
        $dyn['_walkaway_activated_gamets'] = $start;
        $hooverHours = RelationshipDynamics::HOOVER_MIN_HOURS + 0.37 * (RelationshipDynamics::HOOVER_MAX_HOURS - RelationshipDynamics::HOOVER_MIN_HOURS);

        self::setClock($start + ($hooverHours - 1) * self::HOUR);
        $this->assertFalse(RelationshipDynamics::checkHooverEligibility($dyn));
        self::setClock($start + ($hooverHours + 1) * self::HOUR);
        $this->assertTrue(RelationshipDynamics::checkHooverEligibility($dyn));

        $hooverAt = (float)round($start + ($hooverHours + 1) * self::HOUR);
        RelationshipDynamics::executeHoover($dyn, 'Ashe', 'Stoic');
        $this->assertSame($hooverAt, $dyn['_hoover_last_gamets']);
        $this->assertArrayNotHasKey('_walkaway_activated_gamets', $dyn, 'walkaway clock cleared on reset');

        self::setClock($hooverAt + 47 * self::HOUR);
        $this->assertNotNull(RelationshipDynamics::getHooverContext($dyn, 'Ashe'));
        self::setClock($hooverAt + 49 * self::HOUR);
        $this->assertNull(RelationshipDynamics::getHooverContext($dyn, 'Ashe'));
    }

    // ---------------------------------------------------------------- hooks

    public function testHooksHaveNoWallClockTimers(): void
    {
        foreach (['prerequest.php', 'postrequest.php', 'context.php'] as $hook) {
            $src = file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/' . $hook);
            $this->assertDoesNotMatchRegularExpression('/\btime\(\)|microtime\(/', $src, "{$hook} still uses the wall clock");
        }
    }
}
