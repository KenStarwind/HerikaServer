<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
// Production requests have core's game clock and NPC master helpers loaded.
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
final class RelDynIntimacyNeedPgDb
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
 * intimacy-need-profile on a real PostgreSQL through the real hooks (prerequest.php,
 * context.php, the eval inbox consumer), fixed game timestamps, CHIM 3.4.1 core-shaped rows
 * only (no RelDyn state: temperament, attachment, love languages and the intimacy need are
 * all auto-derived):
 *   - an Ashe-like scholar-mage and an Aela-like Nord huntress of the Companions' Circle, both
 *     in a romance with the player, both given connection every day (quality time, a
 *     confiding talk) and no sex;
 *   - the Ashe-like NPC is fulfilled on intimacy (no intimacy line, no intimacy weather
 *     deprivation); the Aela-like one is not (an intimacy line with the physical feeling, the
 *     weather reads it) until a night together.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynIntimacyNeedPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY + 10 * self::HOUR;
    private const ASHE = 'Ashe';
    private const AELA = 'Aela the Huntress';

    private string $dsn;
    private string $schema;
    private RelDynIntimacyNeedPgDb $db;
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
        $this->schema = 'reldyn_intneed' . getmypid() . '_' . bin2hex(random_bytes(3));

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
        // postrequest.php: the NPC's last mood (moods_issued), the Oghma facet build check (oghma)
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_close($admin);

        $this->db = new RelDynIntimacyNeedPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'contextDataFull', 'RELDYN_PLAYER_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdintneedpg');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_intimacy_need_pg_test.log');
        // The daily roll off, so the weather reads only what is tested
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['facet_appraisal' => ['weather_roll_amplitude' => 0.0] + RelDynFacets::appraisalDefaults()]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seedNpcs();
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

    /** A core_npc_master row as the plugin registers the NPC, in a romance with the player (no RelDyn state). */
    private function npc(string $name, string $refid, string $race, string $class, array $factions, array $skills): void
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $faction) {
            $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
        }
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb) RETURNING id',
            [$name, $refid, 'female', $race, 'FemaleEvenToned', "Roleplay as {$name}", '',
             json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
             json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                 'relationships' => ['Player' => ['aff' => 60, 'type' => 'romantic', 'note' => 'together']]])]));
        $this->ids[$name] = (int) $row['id'];
    }

    private function seedNpcs(): void
    {
        // Ashe-like: a scholar-mage follower.
        $this->npc(self::ASHE, 'FE0019C2', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
            ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55, 'conjuration' => 40, 'restoration' => 35]);
        // Aela-like: the Companions' huntress, of the Circle.
        $this->npc(self::AELA, '1A696', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]);
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$this->ids[$name]]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
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

    /** A day of connection and no sex: a long talk, and a confiding moment. */
    private function connectedDay(string $name, float $t): void
    {
        $this->talkTo($name, $t);
        $this->evalExchange($name, $t + self::HOUR, ['quality_time']);
        $this->evalExchange($name, $t + 3 * self::HOUR, ['confiding']);
    }

    /**
     * A request the plugin sends while the player is with $name (a Sharmat scene stage, a VR
     * touch), through the real prerequest and postrequest hooks. No eval connector is set, so
     * the eval does not score it and the local path runs (the reviewer's "eval off" case).
     */
    private function intimateRequest(string $name, float $gamets, string $type, string $data): void
    {
        $GLOBALS['gameRequest'] = [$type, '1727000000', (string) (int) $gamets, $data];
        $GLOBALS['HERIKA_NAME'] = $name;
        $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = 'Kaida';
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
        $GLOBALS['gameRequest'] = [$type, '1727000000', (string) (int) $gamets, $data];
        (static function () { require $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/postrequest.php'; })();
        RelationshipDynamics::endRequest();
        unset($GLOBALS['SCRIPTLINE_LISTENER_ATOMIC']);
    }

    private function intimacyState(string $ctx): ?string
    {
        $text = RelDynFelt::lastRendered()['intimacy'] ?? null;
        if ($text !== null) $this->assertStringContainsString($text, $ctx, 'the intimacy line is in the context');
        return $text;
    }

    // ------------------------------------------------------------------ tests

    public function testConnectionWithoutSexFulfilsTheScholarButNotTheHuntress(): void
    {
        for ($k = 0; $k < 7; $k++) {
            $this->connectedDay(self::ASHE, self::T0 + $k * self::DAY);
            $this->connectedDay(self::AELA, self::T0 + $k * self::DAY);
        }
        $end = self::T0 + 7 * self::DAY;
        $this->talkTo(self::ASHE, $end);
        $this->talkTo(self::AELA, $end);
        $ashe = $this->dynamics(self::ASHE);
        $aela = $this->dynamics(self::AELA);

        // Auto-derived from core data, read once
        $this->assertSame('BretonRace', $ashe['_intimacy_need']['race']);
        $this->assertSame('werewolf', $aela['_intimacy_need']['creature'], 'the Circle shares the beast blood');
        $this->assertGreaterThan($ashe['_intimacy_need']['physical'] + 0.4, $ashe['_intimacy_need']['emotional'], 'connection over sex');
        $this->assertGreaterThanOrEqual(0.7, $aela['_intimacy_need']['physical'], 'physical, visceral');
        $this->assertArrayNotHasKey('_intimacy_fed', $aela);

        // The needs vector (spider graph) carries the axes each one actually has
        $this->assertArrayHasKey(RelDynIntimacy::EMOTIONAL, RelDynFulfillment::pairState($ashe)['w']);
        $this->assertArrayNotHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::pairState($ashe)['w']);
        $this->assertArrayHasKey(RelDynIntimacy::PHYSICAL, RelDynFulfillment::pairState($aela)['w']);
        $graph = array_column(RelationshipDynamics::fulfillmentGraph(self::AELA, $end)['axes'], null, 'axis');
        $this->assertSame('intimacy', $graph[RelDynIntimacy::PHYSICAL]['kind']);
        $this->assertLessThan(-0.4, $graph[RelDynIntimacy::PHYSICAL]['coverage'], 'a week of talks is not what she needs');
        $this->assertGreaterThan(0.5, $graph[RelDynIntimacy::EMOTIONAL]['coverage'], 'the connection reaches her too');

        // Context: the Ashe-like NPC is fulfilled on intimacy, the Aela-like one feels it, as a feeling
        $this->assertNull($this->intimacyState($this->context(self::ASHE, $end)));
        $felt = $this->intimacyState($this->context(self::AELA, $end));
        $this->assertNotNull($felt);
        $this->assertStringContainsString(self::AELA, $felt);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt, 'feelings, never numbers');

        // Internal weather: going without weighs on her, not on the scholar (whose other unmet
        // needs, her loved facets, are the fulfillment band's business, not intimacy's)
        $this->assertSame(0.0, RelDynIntimacy::weatherDeprivation($ashe, $end));
        $this->assertGreaterThan(0.2, RelDynIntimacy::weatherDeprivation($aela, $end));
        $this->assertGreaterThanOrEqual(RelDynIntimacy::weatherDeprivation($aela, $end) - 1e-3,
            $aela['_weather_state']['relationship_deprivation'], 'the weather reads it');
        $this->assertGreaterThan($ashe['_weather_state']['relationship_deprivation'], $aela['_weather_state']['relationship_deprivation']);

        // A night together covers it
        $this->evalExchange(self::AELA, $end + 2 * self::HOUR, ['intimacy']);
        $this->evalExchange(self::AELA, $end + 3 * self::HOUR, ['intimacy']);
        $this->talkTo(self::AELA, $end + 4 * self::HOUR);
        $this->assertNull($this->intimacyState($this->context(self::AELA, $end + 4 * self::HOUR)));
        $this->assertSame(0.0, RelDynIntimacy::weatherDeprivation($this->dynamics(self::AELA), $end + 4 * self::HOUR));
        $this->assertLessThan($aela['_weather_state']['relationship_deprivation'],
            $this->dynamics(self::AELA)['_weather_state']['relationship_deprivation'], 'her weather lifts');
        $this->assertSame([], $this->db->failures, 'the schema holds every table the production path touches');
    }

    /**
     * PR 13: "OStim/Sharmat events -> fully satisfied". Aela in a romance, the eval not scoring
     * anything (no connector): a week of intimacy the plugin reports (VR touches twice a day, then
     * a Sharmat scene every day) keeps her physical need covered, through the real hooks; she is
     * never deprived and no intimacy line speaks.
     */
    public function testIntimacyThePluginReportsKeepsHerCoveredWithoutTheEval(): void
    {
        $this->talkTo(self::AELA, self::T0);
        // A partner at ease with the player (the protocols lane, MDD 6.3: touching a partner who is
        // cold toward the player, comfort low and passion gone, is the Ick, not intimacy)
        $d = RelationshipDynamics::getDynamics(self::AELA);
        foreach (['comfort' => 55.0, 'warmth' => 55.0] as $dim => $x) $d['dimensions'][$dim]['x'] = $d['dimensions'][$dim]['baseline'] = $x;
        RelationshipDynamics::setPassion($d, 35.0);
        RelationshipDynamics::saveDynamics(self::AELA, $d);
        RelationshipDynamics::endRequest();
        for ($k = 0; $k < 7; $k++) {
            $day = self::T0 + $k * self::DAY;
            $this->talkTo(self::AELA, $day + self::HOUR);
            $this->intimateRequest(self::AELA, $day + 2 * self::HOUR, 'ext_nsfw_physics', self::AELA . '^breast^grab^0^^left^');
            $this->intimateRequest(self::AELA, $day + 14 * self::HOUR, 'ext_nsfw_physics', self::AELA . '^butt^grab^0^^right^');
            $aela = $this->dynamics(self::AELA);
            $axes = RelDynIntimacy::axesAt($aela, $day + 14 * self::HOUR);
            $this->assertArrayHasKey(RelDynIntimacy::PHYSICAL, $axes, 'a romance: physical is in play');
            $this->assertFalse($axes[RelDynIntimacy::PHYSICAL]['deprived'], "day {$k}: " . json_encode($axes));
        }
        $end = self::T0 + 7 * self::DAY;
        $this->talkTo(self::AELA, $end);
        $this->assertNull($this->intimacyState($this->context(self::AELA, $end)));
        $this->assertSame(0.0, RelDynIntimacy::weatherDeprivation($this->dynamics(self::AELA), $end));

        // A Sharmat scene with the player in it covers the physical need in full
        for ($k = 7; $k < 10; $k++) {
            $this->intimateRequest(self::AELA, self::T0 + $k * self::DAY + 22 * self::HOUR, 'ext_nsfw_sexcene',
                'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/' . self::AELA . '^sub,vaginal');
        }
        $night = self::T0 + 9 * self::DAY + 22 * self::HOUR;
        $axes = RelDynIntimacy::axesAt($this->dynamics(self::AELA), $night);
        $this->assertEqualsWithDelta(1.0, $axes[RelDynIntimacy::PHYSICAL]['coverage'], 1e-3, json_encode($axes));
        $this->assertSame([], $this->db->failures, 'the schema holds every table the production path touches');
    }

    public function testNoConnectionIsEmotionalDeprivationForTheScholar(): void
    {
        $this->talkTo(self::ASHE, self::T0);
        for ($k = 1; $k <= 5; $k++) {
            $this->talkTo(self::ASHE, self::T0 + $k * self::DAY);   // around, nothing given
        }
        $felt = $this->intimacyState($this->context(self::ASHE, self::T0 + 5 * self::DAY));
        $this->assertNotNull($felt);
        $this->assertStringContainsString('Kaida', $felt, 'what she misses is closeness with the player');
        $this->assertStringNotContainsStringIgnoringCase('physical', $felt);
        $this->assertDoesNotMatchRegularExpression('/\d/', $felt);
        $this->assertSame([], $this->db->failures);
    }
}
