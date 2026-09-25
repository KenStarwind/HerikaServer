<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
// Production requests have core's game clock and NPC master helpers loaded: the relationship
// timeline stamp after a core write takes its DataLastKnownGameTS() / backupNpcById() path.
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws,
 * insert / updateRow are parameterized. Failed statements are recorded so a test can show
 * the schema holds every table the production path touches.
 */
final class RelDynFulfillmentPgDb
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
 * Rulings 2026-09-24 §9 on a real PostgreSQL through the real hooks (prerequest.php,
 * context.php, the eval inbox consumer, the calendar scan), fixed game timestamps:
 *  - fulfillment-coverage: an attentive player keeps the band up; being around without
 *    giving anything is neglect; a high band buffers absence; a low band is weather deprivation;
 *  - mature-boundary-enforcement: a mature NPC states a calm boundary once, watches a probation
 *    window, and steps core's relationship type back when the change does not hold (under
 *    core's lock, relationships_locked respected, timeline stamped); an immature NPC festers.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynFulfillmentPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;      // raw gamets per game day
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY + 10 * self::HOUR;          // game day 300, 10:00

    private string $dsn;
    private string $schema;
    private RelDynFulfillmentPgDb $db;
    private array $ids = [];
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevLog = null;

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
        $this->schema = 'reldyn_fulfil' . getmypid() . '_' . bin2hex(random_bytes(3));

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

        $this->db = new RelDynFulfillmentPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'contextDataFull', 'RELDYN_PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdfulfil');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_fulfillment_test.log');
        // The daily roll off, so the weather reads only what is tested
        $this->config(['facet_appraisal' => ['weather_roll_amplitude' => 0.0] + RelDynFacets::appraisalDefaults()]);
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
     * A romance with the player (core Player.type romantic, aff 60): profile pinned
     * (temperament, attachment, traits), love languages quality time / words, maturity 0..100.
     */
    private function seed(string $name, float $maturity, string $temperament = 'Independent', string $attachment = 'secure',
                          array $traits = [], array $extended = [], array $prefs = []): int
    {
        $dynamics = [
            'profile_overrides' => ['temperament' => $temperament, 'attachment_style' => $attachment, 'traits' => $traits],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            'facet_pref_overrides' => $prefs,
            'dimensions' => ['maturity' => ['x' => $maturity, 'baseline' => $maturity]],
        ];
        $ext = $extended + ['relationships' => ['Player' => ['aff' => 60, 'type' => 'romantic', 'note' => 'hunted together']]];
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, extended_data, plugin_extended_data) VALUES ($1, $2, $3::jsonb, $4::jsonb) RETURNING id',
            [$name, (string) (100 + count($this->ids)), json_encode($ext), json_encode(['reldyn' => ['dynamics' => $dynamics]])]));
        return $this->ids[$name] = (int) $row['id'];
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'];
    }

    private function corePlayer(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['extended_data'], true)['relationships']['Player'];
    }

    private function historyTypes(string $name): array
    {
        $res = pg_query_params($this->db->link,
            "SELECT extended_data #>> '{relationships,Player,type}' AS type FROM core_npc_master_history WHERE npc_id = $1 ORDER BY history_id",
            [$this->ids[$name]]);
        return array_column(pg_fetch_all($res) ?: [], 'type');
    }

    private function resentment(string $name): float
    {
        return floatval($this->dynamics($name)['dimensions']['resentment']['x'] ?? 0);
    }

    private function band(string $name, float $at): float
    {
        return RelationshipDynamics::fulfillment($name, $this->dynamics($name), $at)['band'];
    }

    /** One player line to $name at game time $gamets, through the real prerequest hook. */
    private function talkTo(string $name, float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, "Kaida: hello {$name}"];
        $GLOBALS['HERIKA_NAME'] = $name;
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    /** The same request's context hook; returns the RelDyn system lines it added. */
    private function context(string $name, float $gamets): string
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, "Kaida: hello {$name}"];
        $GLOBALS['HERIKA_NAME'] = $name;
        $GLOBALS['contextDataFull'] = [];
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/context.php'; })();
        RelationshipDynamics::endRequest();
        return implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull']));
    }

    /** An exchange the eval scored, applied through the inbox consumer (postrequest / worker path). */
    private function evalExchange(string $name, float $gamets, array $tags, float $significance = 0.8): void
    {
        $item = [
            'v' => 1, 'npc' => $name, 'npc_id' => $this->ids[$name], 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 2, 'trust' => 1, 'comfort' => 1, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => true,
            'summary' => "Kaida and {$name}: " . implode(', ', $tags) . " at {$gamets}",
        ];
        $this->assertTrue(RelDynStorage::appendItem($this->ids[$name], RelDynStorage::KEY_EVAL_INBOX, $item));
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, "Kaida: hello {$name}"];
        $dyn = RelationshipDynamics::getDynamics($name);
        RelationshipDynamics::applyEvalInbox($name, $dyn);
        RelationshipDynamics::endRequest();
    }

    private function attentiveDay(string $name, float $gamets): void
    {
        $this->evalExchange($name, $gamets + self::HOUR, ['quality_time', 'praise']);
        $this->evalExchange($name, $gamets + 3 * self::HOUR, ['quality_time', 'reassurance']);
    }

    private function assertNoFailedStatements(): void
    {
        $this->assertSame([], $this->db->failures, 'the schema holds every table the production path touches');
    }

    // ------------------------------------------------------------------ fulfillment-coverage

    public function testAttentivePlayerKeepsTheBandUpBeingAroundWithoutGivingIsNeglect(): void
    {
        $this->seed('Aela', 50.0);
        $this->seed('Serana', 50.0);
        for ($k = 0; $k <= 8; $k++) {
            $t = self::T0 + $k * self::DAY;
            $this->talkTo('Aela', $t);
            $this->attentiveDay('Aela', $t);
            $this->talkTo('Serana', $t);        // daily, polite, nothing she needs
        }
        $end = self::T0 + 8 * self::DAY + 4 * self::HOUR;

        $aela = RelationshipDynamics::fulfillment('Aela', $this->dynamics('Aela'), $end);
        $serana = RelationshipDynamics::fulfillment('Serana', $this->dynamics('Serana'), $end);
        $this->assertTrue($aela['known']);
        $this->assertSame(['quality_time', 'words_of_affirmation', RelDynIntimacy::EMOTIONAL, RelDynIntimacy::PHYSICAL], array_keys($aela['needs']),
            'her love languages (no loved facets derived) and her intimacy axes (rulings §10; a romance: physical in play)');
        $this->assertGreaterThan(0.3, $aela['band']);
        $this->assertLessThan(-0.6, $serana['band'], 'present every day, and still neglected');
        $this->assertLessThan(0.0, $serana['trend']);

        // Low fulfillment while present builds neglect resentment; the attentive bond none
        $this->assertSame(0.0, $this->resentment('Aela'));
        $this->assertGreaterThan(0.5, $this->resentment('Serana'));
        $log = $this->dynamics('Serana')['dimensions']['resentment']['grievance_log'];
        $unfulfilled = array_values(array_filter($log, fn($g) => ($g['kind'] ?? null) === 'unfulfilled'));
        $this->assertCount(1, $unfulfilled, 'one grievance per low stretch');
        $this->assertSame('neglect', $unfulfilled[0]['tag']);
        $this->assertStringContainsString('real time together', $unfulfilled[0]['text']);

        // Her context says what she misses, as a feeling
        $ctx = $this->context('Serana', $end);
        $this->assertStringContainsString('- Serana keeps waiting on something from Kaida that does not come', $ctx);
        $this->assertStringNotContainsString('keeps waiting on something from', $this->context('Aela', $end));
        $this->assertNoFailedStatements();
    }

    public function testHighFulfillmentBuffersAbsenceLowFulfillmentSharpensIt(): void
    {
        // Neglect from absence only (unfulfilled neglect off): the band at the last contact scales it.
        $this->config(['facet_appraisal' => ['weather_roll_amplitude' => 0.0] + RelDynFacets::appraisalDefaults(),
                       'fulfillment' => ['unfulfilled_rate_mult' => 0.0] + RelDynFulfillment::configDefaults()]);
        $this->seed('Aela', 50.0);
        $this->seed('Serana', 50.0);
        for ($k = 0; $k <= 5; $k++) {
            $t = self::T0 + $k * self::DAY;
            $this->attentiveDay('Aela', $t - 4 * self::HOUR);
            $this->talkTo('Aela', $t);         // the contact at day 5 leaves a high band behind
            $this->talkTo('Serana', $t);       // ... and a low one here
        }
        $aelaBand = RelDynFulfillment::pairState($this->dynamics('Aela'))['contact_band'];
        $seranaBand = RelDynFulfillment::pairState($this->dynamics('Serana'))['contact_band'];
        $this->assertGreaterThan(0.4, $aelaBand);
        $this->assertLessThan(-0.5, $seranaBand);

        // Their grace multipliers as they left: the absence itself moves attachment once neglect
        // starts (decisions §12 drift), which does not reach back into the grace already served
        $graceMult = ['Aela' => RelationshipDynamics::getNeglectProfile($this->dynamics('Aela'))['grace_mult'],
                      'Serana' => RelationshipDynamics::getNeglectProfile($this->dynamics('Serana'))['grace_mult']];

        // Twelve game days away; time moves for both on other requests (the calendar scan)
        $neglectDays = ['Aela' => 0.0, 'Serana' => 0.0];
        for ($h = 6; $h <= 12 * 24; $h += 6) {
            $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) (self::T0 + 5 * self::DAY + $h * self::HOUR), 'Kaida: ...'];
            foreach (RelationshipDynamics::runCalendarScan(null) as $name => $r) {
                $neglectDays[$name] += floatval($r['calendar']['neglect_days'] ?? 0);
            }
            RelationshipDynamics::endRequest();
        }
        // Grace = bonded 3 game days x the NPC's grace_mult x 2^band at the last contact
        foreach (['Aela' => $aelaBand, 'Serana' => $seranaBand] as $name => $band) {
            $grace = 3.0 * $graceMult[$name] * 2 ** $band;
            $this->assertEqualsWithDelta(12.0 - $grace, $neglectDays[$name], 0.01, "{$name}: neglect starts after a grace of {$grace} game days");
        }
        $neglect = function (string $name): float {
            foreach (array_reverse($this->dynamics($name)['dimensions']['resentment']['grievance_log'] ?? []) as $g) {
                if (($g['tag'] ?? null) === 'neglect' && !isset($g['kind'])) return floatval($g['game_days'] ?? 0) > 0 ? floatval($g['raw']) : 0.0;
            }
            return 0.0;
        };
        // Same NPC, same absence: the fulfilled bond's grace is 2^band longer, its rate 2^-band slower
        $this->assertGreaterThan(0.0, $neglect('Serana'));
        $this->assertLessThan($neglect('Serana') / 3.0, $neglect('Aela'), 'a well-kept bond takes the absence far better');
        $this->assertLessThan($this->resentment('Serana'), $this->resentment('Aela'));
        $this->assertNoFailedStatements();
    }

    public function testLowFulfillmentIsInternalWeatherDeprivation(): void
    {
        $this->seed('Aela', 50.0);   // no loved facets: facet deprivation stays 0
        $this->talkTo('Aela', self::T0);
        $d = $this->dynamics('Aela');
        $this->assertEquals(0.0, $d['_weather_state']['relationship_deprivation']);
        $this->assertSame('clear', $d['_internal_weather']);

        $this->talkTo('Aela', self::T0 + 4 * self::DAY);
        $d = $this->dynamics('Aela');
        $this->assertEquals(0.0, $d['_weather_state']['deprivation'], 'no loved facet went unfed');
        $this->assertEqualsWithDelta(1 - 0.5 ** (4 / 3), $d['_weather_state']['relationship_deprivation'], 1e-3,
            'band -0.60 after four game days of nothing she needs');
        $this->assertSame('overcast', $d['_internal_weather'], 'the relationship itself clouds her');
        $this->assertNoFailedStatements();
    }

    /**
     * Review 2026-09-24 (global-neglect): an absence shorter than the bond's grace is not
     * "low fulfillment while present". Friend bond, one greeting, six game days away with the
     * calendar scan once a game day: no unfulfilled raw resentment, no grievance, no low
     * stretch toward the boundary. The same six days spent together with nothing given are.
     */
    public function testAbsenceInsideGraceIsNotChargedAsUnfulfilledPresence(): void
    {
        $friend = ['relationships' => ['Player' => ['aff' => 60, 'type' => 'platonic']]];
        $this->seed('Aela', 80.0, 'Independent', 'secure', [], $friend);    // away
        $this->seed('Serana', 80.0, 'Independent', 'secure', [], $friend);  // around, given nothing
        $this->talkTo('Aela', self::T0);
        $this->talkTo('Serana', self::T0);
        $graceEnd = RelationshipDynamics::neglectGraceEndGamets($this->dynamics('Aela'));
        $this->assertNotNull($graceEnd, 'a friend bond: its neglect matters');
        $this->assertGreaterThan(self::T0 + 6 * self::DAY, $graceEnd, 'six game days are inside the grace');
        for ($k = 1; $k <= 6; $k++) {
            $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) (self::T0 + $k * self::DAY), 'Kaida: ...'];
            RelationshipDynamics::runCalendarScan(null);
            RelationshipDynamics::endRequest();
            $this->talkTo('Serana', self::T0 + $k * self::DAY);
        }
        $d = $this->dynamics('Aela');
        $this->assertLessThan(-0.25, RelDynFulfillment::bandAt(RelDynFulfillment::pairState($d), self::T0 + 6 * self::DAY), 'nothing delivered: the band sank');
        $this->assertEquals(0.0, floatval($d['_calendar_neglect_raw'] ?? 0), 'away inside the grace: no neglect raw');
        $unfulfilled = array_filter($d['dimensions']['resentment']['grievance_log'] ?? [], fn($g) => ($g['kind'] ?? null) === 'unfulfilled');
        $this->assertSame([], array_values($unfulfilled), 'no "needs unmet" grievance for an excused absence');
        $this->assertArrayNotHasKey('low_since_gamets', RelDynFulfillment::pairState($d), 'the absence does not count toward the boundary');
        $this->assertSame(0.0, $this->resentment('Aela'));

        $s = $this->dynamics('Serana');
        $this->assertGreaterThan(0.0, floatval($s['_calendar_neglect_raw'] ?? 0) + $this->resentment('Serana'), 'present and unfulfilled: neglect');
        $this->assertArrayHasKey('low_since_gamets', RelDynFulfillment::pairState($s));
        $this->assertNotEmpty(array_filter($s['dimensions']['resentment']['grievance_log'] ?? [], fn($g) => ($g['kind'] ?? null) === 'unfulfilled'));

        // Back on day 6: the low stretch starts with the return, not with the absence
        $this->talkTo('Aela', self::T0 + 6 * self::DAY + self::HOUR);
        $this->assertEqualsWithDelta(self::T0 + 6 * self::DAY + self::HOUR, RelDynFulfillment::pairState($this->dynamics('Aela'))['low_since_gamets'], 1.0);
        $this->assertNoFailedStatements();
    }

    /**
     * Review 2026-09-24 (global-neglect): the band a contact leaves behind (contact_band,
     * which scales the next absence) is the band after the visit's deliveries, not the band
     * the visit started with; the eval items land after the request's prerequest.
     */
    public function testAFulfillingVisitLeavesItsBandBehindForTheNextAbsence(): void
    {
        $this->seed('Aela', 50.0);
        $this->talkTo('Aela', self::T0);
        $this->talkTo('Aela', self::T0 + 4 * self::DAY);   // four days of nothing
        $start = RelDynFulfillment::pairState($this->dynamics('Aela'))['contact_band'];
        $this->assertLessThan(-0.5, $start, 'the visit starts low');

        $this->attentiveDay('Aela', self::T0 + 4 * self::DAY);
        $this->attentiveDay('Aela', self::T0 + 4 * self::DAY + 4 * self::HOUR);
        $d = $this->dynamics('Aela');
        $after = RelDynFulfillment::bandAt(RelDynFulfillment::pairState($d), self::T0 + 4 * self::DAY + 7 * self::HOUR);
        $this->assertGreaterThan(0.5, $after, 'an attentive visit');
        $this->assertEqualsWithDelta($after, RelDynFulfillment::pairState($d)['contact_band'], 0.02, 'the visit leaves its delivered band behind');
        $f = RelationshipDynamics::absenceBandFactors($d);
        $this->assertGreaterThan(1.3, $f['grace'], 'and the next absence is buffered, not sharpened');
        $this->assertLessThan(0.8, $f['rate']);
        $this->assertNoFailedStatements();
    }

    // ------------------------------------------------------------------ mature-boundary-enforcement

    /** Talk to $name once a day from day $from to day $to (inclusive), giving nothing. */
    private function politeDays(string $name, int $from, int $to): void
    {
        for ($k = $from; $k <= $to; $k++) {
            $this->talkTo($name, self::T0 + $k * self::DAY);
        }
    }

    /** Mature Aela, ignored while present until the boundary is due, then told it on her next turn. */
    private function boundaryStated(string $name): float
    {
        $this->politeDays($name, 0, 7);
        $this->assertSame('pending', RelDynFulfillment::pairState($this->dynamics($name))['boundary']['state'],
            'low since the day-2 end, five game days sustained');
        $at = self::T0 + 7 * self::DAY;
        $ctx = $this->context($name, $at);
        $this->assertStringContainsString("- {$name} has thought about this calmly", $ctx);
        $this->assertStringContainsString('it has to change consistently', $ctx);
        $this->assertDoesNotMatchRegularExpression('/\d/', $ctx, 'a feeling, never a number');
        $b = RelDynFulfillment::pairState($this->dynamics($name))['boundary'];
        $this->assertSame('probation', $b['state']);
        $this->assertEqualsWithDelta($at + 7 * self::DAY, $b['until_gamets'], 1.0, 'probation: seven game days from the statement');

        $next = $this->context($name, $at + self::HOUR);
        $this->assertStringNotContainsString('has thought about this calmly', $next, 'stated once');
        $this->assertStringContainsString('quietly watching whether it really changes', $next);
        return $at;
    }

    /** The same request's context hook for an NPC-to-NPC round ($type radiant / rechat). */
    private function npcRoundContext(string $name, float $gamets, string $type): string
    {
        $GLOBALS['gameRequest'] = [$type, '1727000000', (string) (int) $gamets, "Farkas: What do you think, {$name}?"];
        $GLOBALS['HERIKA_NAME'] = $name;
        $GLOBALS['contextDataFull'] = [];
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/context.php'; })();
        RelationshipDynamics::endRequest();
        return implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull']));
    }

    /**
     * Review 2026-09-24 (mature-boundary-enforcement): the boundary is said to the player. An
     * NPC-to-NPC round (radiant, rechat) neither says nor consumes it, and its probation
     * starts on the player's own next turn with her.
     */
    public function testTheBoundaryWaitsForThePlayersOwnTurn(): void
    {
        $this->seed('Aela', 80.0);
        $this->politeDays('Aela', 0, 7);
        $this->assertSame('pending', RelDynFulfillment::pairState($this->dynamics('Aela'))['boundary']['state']);
        $at = self::T0 + 7 * self::DAY;
        foreach (['radiant', 'rechat'] as $i => $type) {
            $ctx = $this->npcRoundContext('Aela', $at + ($i + 1) * self::HOUR, $type);
            $this->assertStringNotContainsString('has thought about this calmly', $ctx, "{$type}: not said over the player's head");
            $this->assertSame('pending', RelDynFulfillment::pairState($this->dynamics('Aela'))['boundary']['state'], "{$type}: not consumed");
        }
        $ctx = $this->context('Aela', $at + 3 * self::HOUR);
        $this->assertStringContainsString('- Aela has thought about this calmly', $ctx);
        $b = RelDynFulfillment::pairState($this->dynamics('Aela'))['boundary'];
        $this->assertSame('probation', $b['state']);
        $this->assertEqualsWithDelta($at + 3 * self::HOUR, $b['started_gamets'], 1.0, 'the window starts when she said it to the player');
        $this->assertNoFailedStatements();
    }

    public function testMatureNpcStepsBackDeliberatelyWhenTheChangeDoesNotHold(): void
    {
        $this->seed('Aela', 80.0);
        $at = $this->boundaryStated('Aela');

        // One big effort the next day, then the old pattern
        $this->talkTo('Aela', $at + self::DAY);
        $this->attentiveDay('Aela', $at + self::DAY);
        $this->attentiveDay('Aela', $at + self::DAY + 4 * self::HOUR);
        $this->politeDays('Aela', 9, 13);
        $this->assertSame('romantic', $this->corePlayer('Aela')['type'], 'still inside the probation window');
        $historyBefore = count($this->historyTypes('Aela'));

        $this->talkTo('Aela', $at + 7 * self::DAY);   // the window closes on this turn
        $core = $this->corePlayer('Aela');
        $this->assertSame('platonic', $core['type'], 'romantic -> friend, deliberately');
        $this->assertSame('hunted together', $core['note'], "core's note is left alone (only the type: see the contract test)");
        $history = $this->historyTypes('Aela');
        $this->assertGreaterThan($historyBefore, count($history), "core's timeline stamp ran");
        $this->assertSame('platonic', end($history));

        $d = $this->dynamics('Aela');
        $this->assertSame('platonic', $d['_core_rel_type']);
        $this->assertSame('friend', RelationshipDynamics::getRelationshipType('Aela', $d));
        $this->assertSame('none', RelDynFulfillment::pairState($d)['boundary']['state']);
        $steps = RelDynFulfillment::pairState($d)['step_backs'];
        $step = end($steps);
        $this->assertSame(['romantic', 'platonic'], [$step['from'], $step['to']]);
        $this->assertStringContainsString('mature boundary', $step['reason']);
        $this->assertStringContainsString('type romantic -> platonic (mature boundary', (string) file_get_contents($this->errorLog));

        // Mature: the anger stayed under her neglect ceiling; no walkaway, a calm decision
        $this->assertLessThanOrEqual(RelationshipDynamics::getNeglectProfile($d)['ceiling'] + 1e-6, $this->resentment('Aela'));
        $this->assertSame('normal', $d['_walkaway_state'] ?? 'normal');
        $ctx = $this->context('Aela', $at + 7 * self::DAY + self::HOUR);
        $this->assertStringContainsString('stepping back from a romance to friendship', $ctx);
        $this->assertStringNotContainsString('stepping back', $this->context('Aela', $at + 7 * self::DAY + 2 * self::HOUR), 'said once');
        $this->assertNoFailedStatements();
    }

    public function testConsistentChangeClearsTheBoundary(): void
    {
        $this->seed('Aela', 80.0);
        $at = $this->boundaryStated('Aela');
        for ($k = 1; $k <= 7; $k++) {
            $t = $at + $k * self::DAY;
            $this->talkTo('Aela', $t);
            $this->attentiveDay('Aela', $t);
            $this->attentiveDay('Aela', $t + 4 * self::HOUR);
        }
        $d = $this->dynamics('Aela');
        $this->assertSame('none', RelDynFulfillment::pairState($d)['boundary']['state']);
        $this->assertArrayHasKey('resolved_gamets', RelDynFulfillment::pairState($d)['boundary']);
        $this->assertSame('romantic', $this->corePlayer('Aela')['type'], 'the change held: nothing stepped back');
        $this->assertStringContainsString('the change in Kaida and it has held', $this->context('Aela', $at + 7 * self::DAY + self::HOUR));
        $this->assertNoFailedStatements();
    }

    public function testEditorLockedRelationshipIsNeverSteppedBack(): void
    {
        $this->seed('Aela', 80.0, 'Independent', 'secure', [], ['relationships_locked' => true]);
        $at = $this->boundaryStated('Aela');
        $this->politeDays('Aela', 8, 14);
        $this->assertSame('romantic', $this->corePlayer('Aela')['type'], 'relationships_locked: manual edits protected');
        $b = RelDynFulfillment::pairState($this->dynamics('Aela'))['boundary'];
        $this->assertSame('none', $b['state'], 'closed, not retried every turn');
        $this->assertArrayHasKey('blocked_gamets', $b);
        $this->assertStringContainsString('relationships_locked', (string) file_get_contents($this->errorLog));
        $this->assertSame([], $this->historyTypes('Aela'), 'nothing written, nothing stamped');
        $this->assertNoFailedStatements();
    }

    public function testImmatureNpcFestersInsteadOfStatingABoundary(): void
    {
        $this->seed('Aela', 80.0);                                               // mature, independent
        $this->seed('Serana', 20.0, 'Anxious', 'anxious', ['insecure']);        // immature, codependent
        for ($k = 0; $k <= 14; $k++) {
            $this->talkTo('Aela', self::T0 + $k * self::DAY);
            $this->talkTo('Serana', self::T0 + $k * self::DAY);
        }
        $serana = $this->dynamics('Serana');
        $this->assertSame('none', RelDynFulfillment::pairState($serana)['boundary']['state'], 'no calm boundary');
        $this->assertSame('romantic', $this->corePlayer('Serana')['type']);
        $this->assertGreaterThan(3.0 * $this->resentment('Aela'), $this->resentment('Serana'), 'it festers');
        $this->assertGreaterThan(RelationshipDynamics::getNeglectProfile($this->dynamics('Aela'))['ceiling'], $this->resentment('Serana'),
            "past the mature NPC's anger ceiling");
        $this->assertNoFailedStatements();
    }

    // ------------------------------------------------------------------ changeCoreRelationshipType contract

    public function testChangeCoreRelationshipTypeWritesOnlyTheTypeUnderCoresLock(): void
    {
        $this->seed('Aela', 50.0);
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) self::T0, 'Kaida: hi'];

        $this->assertFalse(RelationshipDynamics::changeCoreRelationshipType('Aela', 'soulmate', 'test'), 'not a core type');
        $this->assertFalse(RelationshipDynamics::changeCoreRelationshipType('Aela', 'platonic', 'test', 'crush'), 'core holds romantic, not crush');
        $this->assertSame('romantic', $this->corePlayer('Aela')['type']);
        $this->assertSame([], $this->historyTypes('Aela'));

        $this->assertTrue(RelationshipDynamics::changeCoreRelationshipType('Aela', 'Lover', 'romance promotion test'), 'core alias -> romantic: already so');
        $this->assertSame([], $this->historyTypes('Aela'), 'unchanged: no write, no stamp');

        $this->assertTrue(RelationshipDynamics::changeCoreRelationshipType('Aela', 'platonic', 'step-back test', 'romantic'));
        $this->assertEquals(['aff' => 60, 'type' => 'platonic', 'note' => 'hunted together'], $this->corePlayer('Aela'));
        $this->assertSame(['platonic'], $this->historyTypes('Aela'));
        $row = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT gamets_last_updated FROM core_npc_master WHERE id = $1', [$this->ids['Aela']]));
        $this->assertEqualsWithDelta(self::T0, floatval($row['gamets_last_updated']), 1.0, 'stamped on the game timeline');

        // A legacy real-name player entry is folded into "Player", as core reads it
        $id = $this->seed('Lydia', 50.0, 'Stoic', 'secure', [], ['relationships' => ['Kaida' => ['aff' => 20, 'type' => 'platonic']]]);
        $this->assertTrue(RelationshipDynamics::changeCoreRelationshipType('Lydia', 'professional', 'test', 'platonic'));
        $rels = json_decode(pg_fetch_result(pg_query($this->db->link, "SELECT extended_data FROM core_npc_master WHERE id = {$id}"), 0, 0), true)['relationships'];
        $this->assertSame(['Player'], array_keys($rels));
        $this->assertEquals(['aff' => 20, 'type' => 'professional'], $rels['Player']);
        $this->assertNoFailedStatements();
    }

    // ------------------------------------------------------------------ spider graph read API

    public function testSpiderGraphReadsTheStoredNpc(): void
    {
        $this->seed('Aela', 50.0, 'Independent', 'secure', [], [], ['nature' => 0.9, 'combat' => 0.7]);
        $this->talkTo('Aela', self::T0);
        $this->attentiveDay('Aela', self::T0);
        $g = RelationshipDynamics::fulfillmentGraph('Aela', self::T0 + 4 * self::HOUR);
        $this->assertTrue($g['known']);
        $axes = array_column($g['axes'], null, 'axis');
        $this->assertSame(['quality_time', 'nature', 'combat', 'words_of_affirmation', RelDynIntimacy::EMOTIONAL, RelDynIntimacy::PHYSICAL],
            array_keys($axes));
        $this->assertSame('intimacy', $axes[RelDynIntimacy::PHYSICAL]['kind']);
        $this->assertSame('facet', $axes['nature']['kind']);
        $this->assertGreaterThan(0.5, $axes['quality_time']['coverage'], 'two quality-time exchanges today');
        $this->assertLessThan(0.0, $axes['combat']['coverage'], 'no fight together yet');
        $this->assertSame(['state' => 'none'], $g['boundary']);
        $json = json_decode((string) json_encode(['ok' => true] + $g), true);
        $this->assertSame('Aela', $json['npc']);

        $unknown = RelationshipDynamics::fulfillmentGraph('Nobody', self::T0);
        $this->assertFalse($unknown['known']);
        $this->assertSame(0.0, $unknown['band']);
        $this->assertNoFailedStatements();
    }
}
