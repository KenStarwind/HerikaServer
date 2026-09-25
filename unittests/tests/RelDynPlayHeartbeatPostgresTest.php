<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynHeartbeatPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) $rows[] = $row;
        return $res ? $rows : false;
    }

    public function execQuery($q) { return @pg_query($this->link, $q); }

    public function insert($table, $data)
    {
        // As lib/postgresql.class.php insert(): pg_query_params, so a PHP null stays SQL NULL
        $ph = [];
        foreach (array_keys($data) as $i => $_) $ph[] = '$' . ($i + 1);
        return @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * play-clock-wallclock (decisions 2026-09-23 §2, timer design): the play clock is measured in
 * game time only. The global play heartbeat (conf_opts relationship_dynamics_play_clock) walks
 * core's eventlog in rowid order and credits the game time between consecutive rows, except
 * where the game skipped time: a wait (info_timeforward, the window it names), a sleep
 * (goodnight / goodmorning), a load ('init', a jump back) and any jump over
 * PLAY_GAP_MAX_GAMETS between two rows (fast travel, carriage, jail, a wait or sleep with no
 * marker). Real seconds (localts, time()) never enter it: a break with the game closed adds
 * nothing, and the same rows always give the same clock. Each NPC's play clock is bounded by it.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynPlayHeartbeatPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;             // raw gamets per game day
    private const GAME_HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;  // raw gamets per game hour
    private const RATE = RelationshipDynamics::GAMETS_PER_REAL_SECOND;    // play gamets per real second
    private const STEP = 5 * self::RATE;   // core logs the plugin's 'request' poll every ~5 real seconds
    private const T0 = 300 * self::DAY;

    private string $dsn;
    private string $schema;
    private RelDynHeartbeatPgDb $db;
    private array $ids = [];
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_beat' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0,
            prompt_head text,
            npc_static_bio text,
            oghma_knowledge_tags text,
            emote_moods text,
            personality text,
            relationships text,
            occupation text,
            appearance text,
            skills text,
            speechstyle text,
            goals text,
            voiceid text,
            metadata jsonb,
            gender text,
            race text,
            profile_id integer,
            dynamic_profile integer,
            md5 text,
            gamets_last_updated numeric,
            core text,
            base text,
            tags text,
            refid text,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        // lib/core/database_schema/core_npc_master_history.sql: core's timeline stamp after a relationship write
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(),
            npc_name text, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0, prompt_head text,
            npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16),
            profile_id integer, dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb, md5 text, gamets_last_updated numeric,
            core text, base text, tags text)");
        // Same shape as data/database_default.sql: conf_opts(id text PRIMARY KEY, value text).
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        // data/database_default.sql eventlog (game clock, place read, player profile reads)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))"); // prerequest gold ledger + profile the Attraction Matrix reads (RelDynPlayer)
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_close($admin);

        $this->db = new RelDynHeartbeatPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_heartbeat_test.log');
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** One eventlog row as core writes it; localts is noise on purpose (real time must not matter). */
    private function row(string $type, float $gamets, string $data = ''): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people) VALUES ($1, $2, $3, $4, $5, $6, $7)',
            [(string) random_int(1, 1 << 40), (string) (int) round($gamets), $type, $data, 'web', random_int(1, 2000000000), '']);
    }

    /** $n core 'request' rows STEP apart after $from; returns the last gamets. */
    private function play(float $from, int $n): float
    {
        for ($i = 1; $i <= $n; $i++) $this->row('request', $from + $i * self::STEP);
        return $from + $n * self::STEP;
    }

    /** What core's waitstop handler writes (processor/comm.php): hours = gamets delta * 0.0000024. */
    private function waitStop(float $start, float $stop): void
    {
        $elapsed = ($stop - $start) * 0.0000024;
        $this->row('info_timeforward', $stop, "$elapsed hours have passed. Current date/time: Sundas, 17th of Last Seed, 4E 201");
    }

    private function storedHeartbeat(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT value FROM conf_opts WHERE id = $1',
            [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID]));
        return $r ? json_decode($r['value'], true) : [];
    }

    /** Anchor the heartbeat at a first row (no credit), as its first beat on a database does. */
    private function anchor(float $gamets): void
    {
        $this->row('request', $gamets);
        $this->assertSame(0.0, RelationshipDynamics::beatPlayClock(), 'the first beat anchors, credits nothing');
    }

    public function testPlayedGameTimeBetweenRowsIsCreditedAndNothingElse(): void
    {
        $this->anchor(self::T0);
        $this->assertSame((int) pg_fetch_result(pg_query($this->db->link, 'SELECT max(rowid) FROM eventlog'), 0, 0),
            (int) $this->storedHeartbeat()['rowid']);

        $g = $this->play(self::T0, 48);   // 4 real minutes of play
        $this->assertEqualsWithDelta(48 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001);
        // No new rows: the same clock, however much real time passes between the two calls
        $this->assertEqualsWithDelta(48 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001);

        // Quit, come back hours later: the game clock stood still, so the break adds nothing
        $this->row('init', $g + 10);
        $this->row('request', $g + 10 + self::STEP);
        $this->assertEqualsWithDelta(49 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001,
            'a real break with the game closed adds nothing');
    }

    public function testAWaitIsNeverPlay(): void
    {
        $this->anchor(self::T0);
        $g = $this->play(self::T0, 12);                    // one real minute of play
        $start = $g + 3 * self::RATE;                      // three more seconds, then wait 8 game hours
        $stop = $start + 8 * self::GAME_HOUR;
        $this->row('request', $start + 0.2 * self::GAME_HOUR);   // the poll logged just after the wait began
        $this->row('request', $start + 5 * self::GAME_HOUR);     // and one mid-wait
        $this->waitStop($start, $stop);
        $this->play($stop, 6);                             // half a real minute of play after it

        $this->assertEqualsWithDelta(18 * self::STEP + 3 * self::RATE, RelationshipDynamics::beatPlayClock(), 1.0,
            'play before and after the wait, none of its 8 hours (not even the slice under the gap limit)');
    }

    public function testAWaitWithNoRowInsideItCreditsOnlyThePlayBeforeIt(): void
    {
        $this->anchor(self::T0);
        $start = self::T0 + 2 * self::RATE;                // two seconds of play, then a 1 h wait
        $this->waitStop($start, $start + self::GAME_HOUR);
        $this->assertEqualsWithDelta(2 * self::RATE, RelationshipDynamics::beatPlayClock(), 1.0);

        // A waitstop against a stale last_waitstart names no usable window: the gap is dropped whole
        $g = $this->play($start + self::GAME_HOUR, 2);
        $this->row('info_timeforward', $g + 0.25 * self::GAME_HOUR, '900.5 hours have passed. Current date/time: Morndas');
        $this->assertEqualsWithDelta(2 * self::RATE + 2 * self::STEP, RelationshipDynamics::beatPlayClock(), 1.0);
    }

    public function testASleepIsNeverPlay(): void
    {
        $this->anchor(self::T0);
        $g = $this->play(self::T0, 12);
        $this->row('goodnight', $g + self::RATE);          // the player lies down
        $this->row('request', $g + self::RATE + 0.1 * self::GAME_HOUR);   // a poll inside the sleep, under the gap limit
        $this->row('goodmorning', $g + self::RATE + 9 * self::GAME_HOUR);
        $this->play($g + self::RATE + 9 * self::GAME_HOUR, 6);
        $this->assertEqualsWithDelta(18 * self::STEP + self::RATE, RelationshipDynamics::beatPlayClock(), 1.0);
    }

    public function testJumpsOverTheGapLimitAreNotPlay(): void
    {
        // Fast travel, a carriage ride, jail, or a sleep the game sent no event for: the game
        // clock leaps between two consecutive rows.
        $this->anchor(self::T0);
        $g = $this->play(self::T0, 6);
        $g2 = $g + RelationshipDynamics::PLAY_GAP_MAX_GAMETS + 1;
        $this->row('infoloc', $g2);
        $g3 = $g2 + 3 * self::GAME_HOUR;                   // fast travel to Whiterun
        $this->row('infoloc', $g3);
        $this->play($g3, 6);
        $this->assertEqualsWithDelta(12 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001);
        // The limit sits between the poll cadence and the shortest wait or sleep the game offers
        $this->assertGreaterThanOrEqual(60 * self::RATE, RelationshipDynamics::PLAY_GAP_MAX_GAMETS);
        $this->assertLessThan(self::GAME_HOUR, RelationshipDynamics::PLAY_GAP_MAX_GAMETS);
    }

    public function testLoadsRestartTheBaselineWithoutCredit(): void
    {
        $this->anchor(self::T0);
        $this->play(self::T0, 6);
        // Load an earlier save (three game days back): core logs the loaded game, then 'init'
        $back = self::T0 - 3 * self::DAY;
        $this->row('infonpc_close', $back);
        $this->row('init', $back + 1);
        $g2 = $this->play($back + 1, 6);
        // Load a later save (two game days ahead)
        $this->row('init', $g2 + 2 * self::DAY);
        $this->play($g2 + 2 * self::DAY, 6);
        $this->assertEqualsWithDelta(18 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001,
            'only the play inside each timeline');
    }

    public function testRowsLoggedWithAnOlderGameTimeDoNotCountTwice(): void
    {
        // Background writers stamp the last known game time; poll rows land out of order.
        $this->anchor(self::T0);
        $this->row('request', self::T0 + self::STEP);
        $this->row('backgroundaction', self::T0 + 2);
        $this->row('quest', self::T0 + 3 * self::STEP);
        $this->row('playerinfo', self::T0 + self::STEP);
        $this->row('request', self::T0 + 4 * self::STEP);
        $this->assertEqualsWithDelta(4 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001);
    }

    public function testManyRowsAreFoldedInOneBeat(): void
    {
        $this->anchor(self::T0);
        // Two real hours of play without talking to anyone: 1440 poll rows, a 24 h jump inside
        pg_query_params($this->db->link,
            "INSERT INTO eventlog (ts, gamets, type, data, sess, localts, people)
             SELECT i, ($1::bigint + i * $2::bigint + CASE WHEN i > 700 THEN $3::bigint ELSE 0 END), 'request', '', 'web', 0, ''
             FROM generate_series(1, 1440) AS i",
            [(string) (int) self::T0, (string) (int) self::STEP, (string) (int) self::DAY]);
        $this->assertEqualsWithDelta(1439 * (int) self::STEP, RelationshipDynamics::beatPlayClock(), 0.001,
            'every gap but the one holding the day');
    }

    public function testALegacyRealTimeHeartbeatKeepsItsPlayAndCreditsNoRealTime(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID, json_encode(['real_ts' => 1700000000, 'gamets' => self::T0, 'play' => 5.0e7])]);
        $g = $this->play(self::T0, 6);
        $this->assertEqualsWithDelta(5.0e7, RelationshipDynamics::beatPlayClock(), 0.001, 'migrated: play kept, anchored at the newest row');
        $this->assertArrayNotHasKey('real_ts', $this->storedHeartbeat());
        $this->play($g, 2);
        $this->assertEqualsWithDelta(5.0e7 + 2 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001);
    }

    public function testThePlayClockFunctionsNeverReadTheWallClock(): void
    {
        foreach (['updatePlayTime', 'updateAccumulatedTime', 'beatPlayClock', 'foldPlayRows'] as $fn) {
            $m = new ReflectionMethod(RelationshipDynamics::class, $fn);
            $src = implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
            $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $src);
            $this->assertDoesNotMatchRegularExpression('/\b(time|microtime|hrtime|date|mktime|strtotime)\s*\(|localts|[\'"]real_ts[\'"]/', $code, "{$fn} reads the wall clock");
        }
    }

    // ------------------------------------------------------------------ through prerequest

    /** An NPC last talked to at T0, whose last turn the heartbeat saw at global play $globalPlay. */
    private function seedRomantic(string $name, float $globalPlay): void
    {
        $npcPlay = 50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;   // this NPC's play clock
        $dynamics = [
            'inferred_temperament' => 'Romantic',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_interest_vector' => [0.1, 0.2, 0.3],
            '_last_contact_gamets' => self::T0,
            '_decay_last_game_gamets' => self::T0,
            '_last_gamets' => self::T0,
            '_last_real_ts' => 1700000000,                        // a stamp from the real-time build: dropped
            '_last_interaction_ts' => 1700000000,
            '_accumulated_play_gamets' => $npcPlay,
            '_accumulated_time' => 180000,                        // play seconds
            '_last_contact_play_gamets' => $npcPlay,
            '_last_global_play_gamets' => $globalPlay,
        ];
        $ext = ['relationships' => ['Player' => ['aff' => 60, 'type' => 'romantic']]];   // core aff (-100..100)
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, '101', json_encode($ext), json_encode(['reldyn' => ['dynamics' => $dynamics, 'calendar' => ['checked_gamets' => self::T0]]])]));
        $this->ids[$name] = (int) $row['id'];
    }

    /** Heartbeat state as a previous beat left it: anchored at the newest row, $play so far. */
    private function heartbeatAt(float $play): void
    {
        $r = pg_fetch_assoc(pg_query($this->db->link, 'SELECT rowid, gamets FROM eventlog ORDER BY rowid DESC LIMIT 1'));
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::PLAY_HEARTBEAT_ROW_ID, json_encode(['rowid' => (int) $r['rowid'], 'gamets' => (float) $r['gamets'], 'play' => $play])]);
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    /** The player speaks: main.php logs core's 'user_input' mark, then runs the ext prerequest hooks. */
    private function talkTo(string $name, float $gamets): void
    {
        $this->row('user_input', $gamets, 'inputtext');
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) round($gamets), 'Kaida: hello'];
        $GLOBALS['HERIKA_NAME'] = $name;
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /** Scenario r2/S6b: quit overnight, load, sleep 9 game hours, talk. No reunion from the sleep. */
    public function testASleepAfterAnOvernightBreakEarnsNoReunionThroughPrerequest(): void
    {
        $this->row('request', self::T0);                           // the last poll before quitting
        $this->heartbeatAt(1.0e8);
        $this->seedRomantic('Serana', 1.0e8);
        // The next evening: ten seconds of play, then sleep 9 game hours
        $g = $this->play(self::T0, 2);
        $this->row('goodnight', $g);
        $this->row('goodmorning', $g + 9 * self::GAME_HOUR);

        $this->talkTo('Serana', $g + 9 * self::GAME_HOUR + self::RATE);

        $d = $this->dynamics('Serana');
        $gained = (float) $d['_accumulated_play_gamets'] - 50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $this->assertEqualsWithDelta(2 * self::STEP + self::RATE, $gained, 1.0, 'the eleven played seconds, not the night or the sleep');
        $this->assertEqualsWithDelta(180000 + $gained / self::RATE, (float) $d['_accumulated_time'], 0.01, 'play seconds follow the play clock');
        $this->assertEmpty($d['reunion_spike_given'] ?? null, 'a sleep is not time apart played');
        $this->assertArrayNotHasKey('_last_real_ts', $d);
        $this->assertArrayNotHasKey('_last_interaction_ts', $d);
    }

    /** Control: 30 real minutes of play elsewhere (10 game hours at timescale 20) earn the reunion. */
    public function testPlayedTimeApartStillEarnsTheReunion(): void
    {
        $this->row('request', self::T0);
        $this->heartbeatAt(1.0e8);
        $this->seedRomantic('Serana', 1.0e8);
        $g = $this->play(self::T0, 360);

        $this->talkTo('Serana', $g);

        $d = $this->dynamics('Serana');
        $this->assertNotEmpty($d['reunion_spike_given'] ?? null, 'real play apart: reunion');
        $this->assertEqualsWithDelta(360 * self::STEP / self::GAME_HOUR, (float) $d['_reunion_hours_apart'], 0.01);
        $this->assertEqualsWithDelta(50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR + 360 * self::STEP,
            (float) $d['_accumulated_play_gamets'], 1.0);
    }

    /** Waiting a day beside her: the calendar moves, the play clock does not, and no reunion. */
    public function testWaitingBesideHerIsNeitherPlayNorAReunion(): void
    {
        $this->row('request', self::T0);
        $this->heartbeatAt(1.0e8);
        $this->seedRomantic('Serana', 1.0e8);
        $this->waitStop(self::T0 + self::RATE, self::T0 + self::RATE + 24 * self::GAME_HOUR);

        $this->talkTo('Serana', self::T0 + 2 * self::RATE + 24 * self::GAME_HOUR);

        $d = $this->dynamics('Serana');
        $this->assertEqualsWithDelta(50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR + 2 * self::RATE,
            (float) $d['_accumulated_play_gamets'], 1.0, 'two played seconds around the wait');
        $this->assertEmpty($d['reunion_spike_given'] ?? null, 'a wait is not time apart played');
    }

    /** Core prunes eventlog rows past the loaded time after the init prerequest: their play is kept. */
    public function testTheInitRequestBanksThePlayCoreIsAboutToPrune(): void
    {
        $this->anchor(self::T0);
        $this->play(self::T0, 24);
        $GLOBALS['gameRequest'] = ['init', '1', (string) (int) self::T0, ''];
        $GLOBALS['HERIKA_NAME'] = 'The Narrator';
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
        $this->assertEqualsWithDelta(24 * self::STEP, (float) $this->storedHeartbeat()['play'], 0.001);

        // comm.php: delete the rows at or after the loaded time, log 'init', play on
        pg_query_params($this->db->link, 'DELETE FROM eventlog WHERE gamets >= $1', [(string) (int) self::T0]);
        $this->row('init', self::T0);
        $this->play(self::T0, 2);
        $this->assertEqualsWithDelta(26 * self::STEP, RelationshipDynamics::beatPlayClock(), 0.001);
    }

    /** The same game, played twice, gives the same clocks: nothing depends on real time. */
    public function testMultiTurnPlayIsDeterministic(): void
    {
        $runs = [];
        foreach ([1, 2] as $run) {
            pg_query($this->db->link, 'DELETE FROM core_npc_master');
            pg_query($this->db->link, 'DELETE FROM eventlog');
            pg_query($this->db->link, 'DELETE FROM conf_opts');
            RelationshipDynamics::clearConfigCache();
            $this->row('request', self::T0);
            $this->heartbeatAt(1.0e8);
            $this->seedRomantic('Serana', 1.0e8);
            $g = self::T0;
            foreach ([[12, 0], [6, 8], [30, 0], [3, 24]] as [$rows, $waitHours]) {
                $g = $this->play($g, $rows);
                if ($waitHours > 0) {
                    $this->waitStop($g, $g + $waitHours * self::GAME_HOUR);
                    $g += $waitHours * self::GAME_HOUR;
                }
                $g += self::RATE;
                $this->talkTo('Serana', $g);
                if ($run === 1) usleep(1100000);   // real time passes between turns in one run only
            }
            $d = $this->dynamics('Serana');
            $dims = [];
            foreach (($d['dimensions'] ?? []) as $k => $v) $dims[$k] = is_array($v) ? ($v['x'] ?? null) : null;
            $runs[$run] = [
                'play' => $d['_accumulated_play_gamets'], 'seconds' => $d['_accumulated_time'],
                'passion' => RelationshipDynamics::getPassion($d), 'count' => $d['interaction_count'] ?? null,
                'last_interaction_at' => $d['last_interaction_at'] ?? null, 'dims' => $dims,
            ];
        }
        $this->assertSame($runs[1], $runs[2]);
        $this->assertEqualsWithDelta(50 * RelationshipDynamics::GAMETS_PER_REAL_HOUR + 51 * self::STEP + 4 * self::RATE,
            (float) $runs[1]['play'], 2.0);
    }
}
