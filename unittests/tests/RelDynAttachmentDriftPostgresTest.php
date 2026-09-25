<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws,
 * insert / updateRow are parameterized. Failed statements are recorded.
 */
final class RelDynAttachmentDriftPgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function record(string $what): void
    {
        $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $what), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) {
            $this->record((string) $q);
            return [];
        }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) {
            $this->record((string) $q);
            throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        }
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }

    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) $this->record((string) $q);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->record("insert {$table}");
        return $res;
    }

    public function updateRow($table, $data, $where)
    {
        $set = [];
        $i = 0;
        foreach (array_keys($data) as $col) $set[] = "{$col} = $" . (++$i);
        $res = @pg_query_params($this->link, "UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where}", array_values($data));
        if (!$res) {
            $this->record("updateRow {$table}");
            return false;
        }
        return true;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * attachment-two-axes drift (decisions 2026-09-24 §12, MDD 6.1 "Earned Security" and its
 * "regression slingshot on neglect") on a real PostgreSQL through the real hooks: prerequest.php
 * per player line, the eval inbox consumer, the calendar scan and context.php, fixed game
 * timestamps. The NPC is Ashe as core registers her (her preset: Guarded; anxiety 0.3,
 * avoidance 0.5 that eases as trust is earned). No LLM or embedding call is involved.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynAttachmentDriftPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;      // raw gamets per game day
    private const HOUR = self::DAY / 24;
    private const T0 = 310 * self::DAY + 10 * self::HOUR;          // game day 310, 10:00
    private const ASHE = 'Ashe';

    private string $dsn;
    private string $schema;
    private RelDynAttachmentDriftPgDb $db;
    private int $id = 0;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        // Personality traits phase 2: this class pins the LABEL assignment (the phase-1 legacy path):
        // its NPCs' temperaments are the old core-data vote's. The read assignment has its own tests.
        RelDynTraits::$assignmentOverride = 'label';
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_attach' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and core_npc_master_history.sql.
        $columns = "npc_name text NOT NULL, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0,
            prompt_head text, npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16), profile_id integer,
            dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'),
            md5 text, gamets_last_updated numeric, core text, base text, tags text";
        pg_query($admin, "CREATE TABLE core_npc_master (id serial PRIMARY KEY, {$columns})");
        pg_query($admin, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(), " . str_replace('npc_name text NOT NULL', 'npc_name text', $columns) . ")");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        // data/database_default.sql eventlog (game clock, presence catch-up, place read, combat reads)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        // locations as 3.4.1 core has it (debug/db_updates.php): the place read
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // lib/core/database_schema/core_player.sql (the attraction matrix reads the player's appearance)
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_close($admin);

        $this->db = new RelDynAttachmentDriftPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'contextDataFull', 'RELDYN_PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdattachpg');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_attachment_drift_test.log');
        // The daily weather roll off, so nothing but the relationship moves her
        $this->config(['facet_appraisal' => ['weather_roll_amplitude' => 0.0] + RelDynFacets::appraisalDefaults()]);
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixture helpers

    private function config(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * Ashe as core registers her (a romance with the player, core aff 60), with the RelDyn state an
     * earlier stretch of the relationship left: trust earned (75 points of 100), love languages
     * quality time / words, no loved places in play (facet preferences pinned neutral). Her
     * temperament and attachment come from the real auto-generation (her named preset).
     */
    private function seedAshe(): void
    {
        $dynamics = [
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            'facet_pref_overrides' => [],
            'dimensions' => ['trust' => ['x' => 75.0, 'baseline' => 55.0], 'maturity' => ['x' => 60.0, 'baseline' => 60.0]],
        ];
        $ext = ['relationships' => ['Player' => ['aff' => 60, 'type' => 'romantic', 'note' => 'travelled together']]];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, race, extended_data, plugin_extended_data) VALUES ($1, $2, $3, $4::jsonb, $5::jsonb) RETURNING id',
            [self::ASHE, '171', 'BretonRace', json_encode($ext), json_encode(['reldyn' => ['dynamics' => $dynamics]])]));
        $this->id = (int) $row['id'];
    }

    private function dynamics(): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    /** [anxiety, avoidance] of the stored NPC. */
    private function axes(): array
    {
        $a = RelationshipDynamics::getAttachmentAxes($this->dynamics());
        return [round($a['anxiety'], 6), round($a['avoidance'], 6)];
    }

    /** One player line at game time $gamets, through the real prerequest hook. */
    private function talk(float $gamets, string $line = 'hello'): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, "Kaida: {$line}"];
        $GLOBALS['HERIKA_NAME'] = self::ASHE;
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /** The same request's context hook; returns the RelDyn system lines it added. */
    private function context(float $gamets): string
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, 'Kaida: hello'];
        $GLOBALS['HERIKA_NAME'] = self::ASHE;
        $GLOBALS['contextDataFull'] = [];
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/context.php'; })();
        RelationshipDynamics::endRequest();
        return implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull']));
    }

    /** An exchange the eval scored, applied through the inbox consumer (postrequest / worker path). */
    private function evalExchange(float $gamets, array $tags, float $significance = 0.8, array $signals = [], bool $positive = true): void
    {
        $item = [
            'v' => 1, 'npc' => self::ASHE, 'npc_id' => $this->id, 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 2, 'trust' => 1, 'comfort' => 1, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => $tags,
            'grievance' => ['flag' => !$positive, 'kind' => $positive ? null : 'betrayal', 'severity' => $positive ? 0 : 3],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => $positive,
            'summary' => 'Kaida and Ashe: ' . implode(', ', $tags) . " at {$gamets}",
        ];
        $this->assertTrue(RelDynStorage::appendItem($this->id, RelDynStorage::KEY_EVAL_INBOX, $item));
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, 'Kaida: ...'];
        $dyn = RelationshipDynamics::getDynamics(self::ASHE);
        RelationshipDynamics::applyEvalInbox(self::ASHE, $dyn);
        RelationshipDynamics::endRequest();
    }

    /** A day at her side that gives her what she needs: time, words, being known. */
    private function fulfilledDay(float $t): void
    {
        $this->talk($t);
        $this->evalExchange($t + self::HOUR, ['quality_time', 'praise']);
        $this->evalExchange($t + 3 * self::HOUR, ['quality_time', 'reassurance', 'confiding']);
    }

    /** Game time passes with no word to her: the calendar scan steps her on other requests. */
    private function awayUntil(float $from, float $to): void
    {
        for ($t = $from + 6 * self::HOUR; $t <= $to; $t += 6 * self::HOUR) {
            $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $t, 'Kaida: ...'];
            RelationshipDynamics::runCalendarScan(null);
            RelationshipDynamics::endRequest();
        }
    }

    private function assertNoFailedStatements(): void
    {
        $this->assertSame([], $this->db->failures, 'the schema holds every table the production path touches');
    }

    // ------------------------------------------------------------------ tests

    public function testEarnedSecurityThenTheSlingshotThroughTheRealHooks(): void
    {
        $this->seedAshe();
        $this->talk(self::T0);
        $d = $this->dynamics();
        $this->assertSame('Guarded', $d['inferred_temperament']);
        $this->assertSame('preset', $d['_profile_autogen']['attachment_source']);
        $this->assertSame([0.3, 0.5], $this->axes());
        $this->assertSame('avoidant', RelationshipDynamics::getAttachmentStyle($d));
        $this->assertArrayNotHasKey('attachment_style', $d, 'no label stored');

        // Twenty-five game days of consistent fulfillment, and a fight repaired on the way
        for ($k = 0; $k < 25; $k++) {
            $this->fulfilledDay(self::T0 + $k * self::DAY);
            if ($k === 12) {
                $dyn = RelationshipDynamics::getDynamics(self::ASHE);
                RelationshipDynamics::enterConflict($dyn);
                for ($i = 0; $i < 3; $i++) RelationshipDynamics::recordConflictPositive($dyn);
                $this->assertTrue(RelationshipDynamics::saveDynamics(self::ASHE, $dyn));
                RelationshipDynamics::endRequest();
                $this->assertSame('repair', end($this->dynamics()['_attachment_drift']['log'])['why'], 'repair after conflict, stored');
            }
        }
        [$anx, $avo] = $this->axes();
        $this->assertLessThan(0.5, $avo, 'her avoidance came down as trust was honoured');
        $this->assertLessThan(0.3, $anx);
        $this->assertGreaterThanOrEqual(0.5 - 0.4, $avo, 'within max_from_base');
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($this->dynamics()), 'earned security (MDD 6.1)');
        $whys = array_column($this->dynamics()['_attachment_drift']['log'], 'why');
        $this->assertContains('fulfilled_day', $whys);
        $earned = $avo;

        // Jev gets the axes as numbers; the dialogue model gets no style name and no number for it
        $jev = RelationshipDynamics::jevStateBlock(self::ASHE);
        $this->assertEqualsWithDelta($avo, $jev['attachment_avoidance'], 1e-3);
        $this->assertEqualsWithDelta($anx, $jev['attachment_anxiety'], 1e-3);
        $this->assertStringContainsString('attachment=secure(anxiety ', $jev['text']);
        $ctx = $this->context(self::T0 + 25 * self::DAY);
        $this->assertDoesNotMatchRegularExpression('/\b(secure|anxious|avoidant|fearful|attachment|anxiety|avoidance)\b/i', $ctx);

        // Then weeks away: neglect undoes it, faster than it was earned (the slingshot)
        $last = self::T0 + 24 * self::DAY + 3 * self::HOUR;
        $this->awayUntil($last, $last + 20 * self::DAY);
        [$anx2, $avo2] = $this->axes();
        $this->assertGreaterThan($earned, $avo2, 'neglect raises it again');
        $this->assertGreaterThan($anx, $anx2);
        $lows = array_filter($this->dynamics()['_attachment_drift']['log'], fn($l) => $l['why'] === 'low_day');
        $this->assertNotEmpty($lows);
        $this->assertGreaterThan(0.003 * 1.5, max(array_map(fn($l) => $l['avoidance'] ?? 0.0, $lows)),
            'below her base a low day climbs at the slingshot rate');
        $this->assertNoFailedStatements();
    }

    public function testABetrayalScoredByTheEvalRaisesBothAxesBounded(): void
    {
        $this->seedAshe();
        $this->talk(self::T0);
        [$anx0, $avo0] = $this->axes();
        $this->evalExchange(self::T0 + self::HOUR, ['betrayal'], 1.0, ['affinity' => -6, 'trust' => -8, 'comfort' => -3], false);
        [$anx1, $avo1] = $this->axes();
        // betrayal row +0.04 on each axis x bond weight (romantic core type = bonded, 1.0),
        // x significance 1.0, capped at max_per_game_day 0.03
        $this->assertEqualsWithDelta($anx0 + 0.03, $anx1, 1e-6);
        $this->assertEqualsWithDelta($avo0 + 0.03, $avo1, 1e-6);
        $this->assertSame('betrayal', end($this->dynamics()['_attachment_drift']['log'])['why']);
        // A lie the same game day: that day's budget is spent
        $this->evalExchange(self::T0 + 2 * self::HOUR, ['lie'], 1.0, ['trust' => -2], false);
        $this->assertSame([$anx1, $avo1], $this->axes());
        $this->assertNoFailedStatements();
    }
}
