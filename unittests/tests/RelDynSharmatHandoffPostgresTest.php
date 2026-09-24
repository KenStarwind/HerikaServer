<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/utils_game_timestamp.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Minimal `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynSharmatHandoffPgDb
{
    public $link;

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) {
            throw new RuntimeException("cannot connect to {$dsn}");
        }
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function execQuery($q) { return pg_query($this->link, $q); }
    /** lib/postgresql.class.php query(): the raw result (Sharmat's auto-init uses it). */
    public function query($q) { return pg_query($this->link, $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * sharmat-disposition-bridge, handoff side (rulings 2026-09-24 §9, MDD Integration Philosophy):
 * CHIM + RelDyn own emotion, Sharmat owns the bedroom. RelDyn's real prerequest hook on a real
 * PostgreSQL:
 *   - publishes its romantic state (core type / rung, passion band, attraction, friendzone,
 *     walkaway, conflict, consent_block, effective disposition) to
 *     plugin_extended_data.reldyn.romance;
 *   - reads Sharmat's own arousal (nsfw_npc_data aiagent_nsfw_intimacy_data.sex_disposal,
 *     through Sharmat's real NsfwNpcData when D:\repos\Sharmat-Alpha is present) for the
 *     effective disposition, and never writes Sharmat's store (the old bridge added a
 *     top-level 'sex_disposal' key Sharmat never reads, creating a row for every NPC).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynSharmatHandoffPostgresTest extends TestCase
{
    private const NPC = 'Aela the Huntress';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 300 * self::DAY + 20 * (self::DAY / 24);
    /** Sharmat's NsfwNpcData, read-only reference (never edited). */
    private const SHARMAT_DATA = __DIR__ . '/../../../Sharmat-Alpha/nsfw_data.php';

    private string $dsn;
    private string $schema;
    private RelDynSharmatHandoffPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;

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
        $this->schema = 'reldyn_sharmat' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
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
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_close($admin);

        $this->db = new RelDynSharmatHandoffPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'CACHE_PEOPLE', 'CACHE_PARTY', 'contextDataFull', 'PLAYER_BIOS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->errorLog = tempnam(sys_get_temp_dir(), 'reldyn-sharmat-');
        ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_sharmat_pg_test.log');
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            ['relationship_dynamics_config', json_encode(RelationshipDynamics::defaultConfig())]);
        RelationshipDynamics::clearConfigCache();
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
        @unlink($this->errorLog);
    }

    private function playerStats(array $stats): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['_player_stats', json_encode($stats + ['gender' => 'Male', 'synced_at' => 1])]);
    }

    private function seedAela(int $aff, string $type, array $dyn = []): int
    {
        $dynamics = array_replace([
            'profile_overrides' => ['temperament' => 'Bold', 'attachment_style' => 'secure'],
            'attraction_profile' => [
                'beauty_keywords' => ['rugged'],
                'strength_skills' => ['OneHanded', 'Archery', 'LightArmor', 'Block'],
                'strength_mode' => 'strict', 'strength_threshold' => 200,
                'status_metrics' => [['type' => 'faction_rank', 'faction' => 'Companions', 'min' => 1]],
                'competence_metrics' => [['type' => 'kill_category', 'category' => 'animals', 'min' => 30]],
                'pillar_rigidity' => ['beauty' => 'soft', 'strength' => 'rigid', 'status' => 'soft', 'competence' => 'rigid'],
                'intimacy_gate' => 'visceral', 'gender_pref' => 'bisexual',
            ],
        ], $dyn);
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, personality, speechstyle, core, npc_static_bio, gender, race, metadata, extended_data, plugin_extended_data)'
            . " VALUES ($1, '', '', $2, '', 'female', 'NordRace', $3::jsonb, $4::jsonb, $5::jsonb) RETURNING id",
            [self::NPC, 'Roleplay as ' . self::NPC, json_encode(['skills' => []]),
             json_encode(['class' => ['name' => 'Hunter'], 'relationships' => ['Player' => ['aff' => $aff, 'type' => $type]]]),
             json_encode(['reldyn' => ['dynamics' => $dynamics]])]));
        return (int) $row['id'];
    }

    private function prerequest(): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) self::T0, 'Kaida: good hunt today'];
        $GLOBALS['HERIKA_NAME'] = self::NPC;
        $GLOBALS['contextDataFull'] = [];
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    private function reldyn(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn'] ?? [];
    }

    private function warrior(): void
    {
        $this->playerStats(['skills' => ['OneHanded' => 80, 'Archery' => 75, 'LightArmor' => 60, 'Block' => 40],
            'kill_counts' => ['animals' => 60], 'faction_ranks' => ['Companions' => 2]]);
    }

    // =========================================================================

    public function testThePrerequestPublishesTheRomanticStateForSharmat(): void
    {
        $this->warrior();
        $id = $this->seedAela(62, 'crush', ['dimensions' => ['passion' => ['x' => 45, 'baseline' => 0]], 'passion' => 45]);

        $this->prerequest();

        $state = $this->reldyn($id)['romance'] ?? null;
        $this->assertIsArray($state, 'published to plugin_extended_data.reldyn.romance');
        $this->assertSame('crush', $state['core_type'], "core Player.type, the value Sharmat's consent gate reads");
        $this->assertSame(1, $state['rung']);
        $this->assertTrue($state['romantic']);
        $this->assertContains($state['passion_band'], ['stirring', 'warm', 'intense'], 'a feeling band, not a number');
        $this->assertTrue($state['attraction_pass']);
        $this->assertFalse($state['friendzoned']);
        $this->assertFalse($state['consent_block']);
        $this->assertSame([], $state['block_reasons']);
        $this->assertNull($state['effective_disposition'], 'no Sharmat, no arousal to overlay');
        $this->assertEqualsWithDelta(self::T0, $state['gamets'], 1.0, 'stamped on the game clock');
        $this->assertSame('crush', RelationshipManager::getPlayerRelationship(self::NPC)['type']);
        $this->assertNull(pg_fetch_result(pg_query($this->db->link, "SELECT to_regclass('nsfw_npc_data')"), 0, 0),
            'without Sharmat RelDyn creates nothing of its store');
        $this->assertStringNotContainsString('ERROR', (string) file_get_contents($this->errorLog));
    }

    public function testFriendzoneAndConflictRaiseTheConsentBlock(): void
    {
        // A bard she tolerates: the prerequest's own attraction matrix friendzones him.
        $this->playerStats(['skills' => ['Speech' => 95, 'Illusion' => 80, 'OneHanded' => 10],
            'kill_counts' => ['animals' => 40], 'faction_ranks' => ['Companions' => 2]]);
        $id = $this->seedAela(62, 'crush', ['in_conflict' => true]);

        $this->prerequest();

        $state = $this->reldyn($id)['romance'];
        $this->assertTrue($state['friendzoned']);
        $this->assertFalse($state['attraction_pass']);
        $this->assertTrue($state['in_conflict']);
        $this->assertTrue($state['consent_block']);
        $this->assertEqualsCanonicalizing(['conflict', 'friendzoned'], $state['block_reasons']);
    }

    public function testWithSharmatLoadedRelDynReadsItsArousalAndNeverWritesItsStore(): void
    {
        if (!is_file(self::SHARMAT_DATA)) {
            $this->markTestSkipped('Sharmat-Alpha checkout not found next to this worktree (' . self::SHARMAT_DATA . ')');
        }
        require_once self::SHARMAT_DATA;   // Sharmat's real NsfwNpcData (read-only reference)
        NsfwNpcData::clearCache();
        $this->warrior();
        $id = $this->seedAela(62, 'crush', ['dimensions' => ['passion' => ['x' => 40, 'baseline' => 0]], 'passion' => 40]);
        $this->assertTrue(NsfwNpcData::save(self::NPC, [
            'sexual_orientation' => 'bisexual',
            'aiagent_nsfw_intimacy_data' => ['level' => 1, 'sex_disposal' => 12],
        ]));
        pg_query($this->db->link, "UPDATE nsfw_npc_data SET updated_at = '2026-01-01 00:00:00'");
        $before = pg_fetch_assoc(pg_query($this->db->link, 'SELECT npc_name, extended_data::text AS d, updated_at FROM nsfw_npc_data'));
        NsfwNpcData::clearCache();

        $this->prerequest();

        $state = $this->reldyn($id)['romance'];
        $dyn = $this->reldyn($id)['dynamics'];
        $expected = RelationshipDynamics::getEffectiveDisposition(12, $dyn);
        $this->assertSame($expected, $state['effective_disposition'], "the MDD overlay on Sharmat's own arousal");
        $this->assertGreaterThan(12, $state['effective_disposition'], 'passion raises it');
        $this->assertSame($expected, $GLOBALS['RELDYN_EFFECTIVE_DISPOSAL']);

        $rows = pg_fetch_all(pg_query($this->db->link, 'SELECT npc_name, extended_data::text AS d, updated_at FROM nsfw_npc_data'));
        $this->assertSame([$before], $rows, "Sharmat's store untouched: no 'sex_disposal' key added, no row created");
        $this->assertStringNotContainsString('"sex_disposal": ' . $expected, $rows[0]['d']);
    }
}
