<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * fresh-start-defaults (decisions 2026-09-23 section 3): RelDyn on 3.4.1 starts fresh.
 * April data (extended_data.relationship_dynamics, pre-v2 passion/resentment aliasing,
 * activity_preferences) is never carried over; stored state is read as the current
 * engine wrote it, and missing keys come from the current defaults.
 *
 * The storage test runs against a real PostgreSQL when RELDYN_TEST_PG_DSN points at a
 * THROWAWAY database (never dwemer); it uses its own schema and drops it.
 */
final class RelDynFreshStartTest extends TestCase
{
    private const NPC = 'Serana';

    private array $savedGlobals = [];
    private ?string $schema = null;
    private string $dsn = '';
    private $link = null;
    private array $statements = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'HERIKA_NAME', 'gameRequest'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['db'], $GLOBALS['PLAYER_NAME'], $GLOBALS['HERIKA_NAME'], $GLOBALS['gameRequest']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        if ($this->schema !== null) {
            pg_close($this->link);
            $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
            pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
            pg_close($admin);
        }
    }

    private function usePostgres(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) {
            $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        }
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) {
            $this->fail('refusing to run against the live dwemer database');
        }
        $this->dsn = $dsn;
        $this->schema = 'reldyn_fs' . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($this->link, "CREATE SCHEMA {$this->schema}");
        pg_query($this->link, "SET search_path TO {$this->schema}");
        // Same column types as lib/core/database_schema/core_npc_master.sql for what RelDyn touches.
        pg_query($this->link, "CREATE TABLE core_npc_master (
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
            refid character varying(16),
            profile_id integer,
            dynamic_profile integer,
            md5 text,
            gamets_last_updated numeric,
            core text,
            base text,
            tags text,
            extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        // lib/core/database_schema/core_npc_master_history.sql: core's timeline stamp after a relationship write
        pg_query($this->link, "CREATE TABLE core_npc_master_history (history_id serial PRIMARY KEY, npc_id integer NOT NULL,
            created timestamp without time zone DEFAULT now(),
            npc_name text, npc_favorite integer DEFAULT 0, lock_profile integer DEFAULT 0, prompt_head text,
            npc_static_bio text, oghma_knowledge_tags text, emote_moods text, personality text,
            relationships text, occupation text, appearance text, skills text, speechstyle text, goals text,
            voiceid text, metadata jsonb, gender text, race text, refid character varying(16),
            profile_id integer, dynamic_profile integer, extended_data jsonb,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb, md5 text, gamets_last_updated numeric,
            core text, base text, tags text)");
        pg_query($this->link, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        // lib/core/database_schema/core_player.sql (RelDynPlayer::profile)
        pg_query($this->link, "CREATE TABLE core_player (id text NOT NULL PRIMARY KEY, value text)");
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($this->link, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // data/database_default.sql eventlog (game clock, place read, player profile reads)
        pg_query($this->link, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");

        $test = $this;
        $GLOBALS['db'] = new class($this->link, $this->statements) {
            public $link;
            private array $log;
            public function __construct($link, array &$log) { $this->link = $link; $this->log = &$log; }
            public function fetchOne($q, array $params = [])
            {
                $this->log[] = preg_replace('/\s+/', ' ', trim($q));
                $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
                return $res ? (pg_fetch_assoc($res) ?: []) : [];
            }
            public function fetchAll($q)
            {
                $this->log[] = preg_replace('/\s+/', ' ', trim($q));
                $r = @pg_query($this->link, $q);
                $rows = [];
                while ($r && ($row = pg_fetch_assoc($r))) $rows[] = $row;
                return $rows;
            }
            public function execQuery($q) { $this->log[] = preg_replace('/\s+/', ' ', trim($q)); return @pg_query($this->link, $q); }
            public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string)$s); }
            public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string)$s); }
        };
    }

    private function row(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->link,
            'SELECT extended_data, plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return ['extended' => json_decode($r['extended_data'], true), 'plugin' => json_decode($r['plugin_extended_data'], true)];
    }

    public function testAprilExtendedDataIsNotCarriedOver(): void
    {
        $this->usePostgres();
        $april = ['love_language_primary' => 'gifts', 'passion' => 40.0, 'total_positive_interactions' => 12,
                  'inferred_temperament' => 'Romantic'];
        $id = (int)pg_fetch_result(pg_query_params($this->link,
            'INSERT INTO core_npc_master (npc_name, extended_data) VALUES ($1, $2::jsonb) RETURNING id',
            [self::NPC, json_encode(['relationship_dynamics' => $april, 'relationships' => ['Player' => ['aff' => 10]]])]), 0, 0);

        $d = RelationshipDynamics::getDynamics(self::NPC);

        $defaults = RelationshipDynamics::defaultDynamics();
        $this->assertSame($defaults['love_language_primary'], $d['love_language_primary'], 'fresh defaults, not April data');
        $this->assertSame(0, $d['total_positive_interactions']);
        $this->assertEqualsWithDelta(0.0, $d['passion'], 0.0001);
        $this->assertArrayNotHasKey('reldyn', $this->row($id)['plugin'], 'nothing copied into plugin storage on load');
        foreach ($this->statements as $sql) {
            $this->assertStringNotContainsString("extended_data -> 'relationship_dynamics'", $sql);
        }

        // The first save writes fresh state to plugin storage and leaves the old key alone.
        $d['total_positive_interactions'] = 1;
        $this->assertTrue(RelationshipDynamics::saveDynamics(self::NPC, $d));
        $row = $this->row($id);
        $this->assertSame(1, $row['plugin']['reldyn']['dynamics']['total_positive_interactions']);
        $this->assertEquals($april, $row['extended']['relationship_dynamics'], 'old key untouched');
        $this->assertSame(1, RelationshipDynamics::getDynamics(self::NPC)['total_positive_interactions']);
    }

    public function testStoredPassionIsReadFromDimensions(): void
    {
        // No version stamp and a stale flat mirror: the dimension value is the state.
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'passion' => 30.0,
            'dimensions' => ['passion' => ['x' => 12.0, 'baseline' => 0]],
        ]));
        $this->assertEqualsWithDelta(12.0, $d['dimensions']['passion']['x'], 0.0001);
        $this->assertEqualsWithDelta(12.0, $d['passion'], 0.0001, 'flat key is only a mirror');
    }

    public function testStoredResentmentIsKeptOnLoad(): void
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'jealousy_anger' => 25.0,
            'dimensions' => ['resentment' => ['x' => 25.0, 'baseline' => 0, 'active' => true,
                'pending_grievances' => [], 'grievance_log' => [], 'last_decay_tick' => 0]],
        ]));
        $this->assertEqualsWithDelta(25.0, $d['dimensions']['resentment']['x'], 0.0001, 'no April aliasing reset');
        $this->assertEqualsWithDelta(25.0, $d['jealousy_anger'], 0.0001);

        $again = RelationshipDynamics::migrateDimensions(RelationshipDynamics::syncLegacyFromDimensions($d));
        $this->assertEqualsWithDelta(25.0, $again['dimensions']['resentment']['x'], 0.0001);
    }

    public function testOldActivityPreferencesAreNotMigrated(): void
    {
        $fromOld = RelationshipDynamics::getInterests(['activity_preferences' => ['smithing' => 2.0, 'tavern' => 0.5]]);
        $this->assertSame(RelationshipDynamics::getInterests([]), $fromOld, 'April key ignored: interests are generated fresh');
    }

    public function testAprilMigrationEntryPointsAreGone(): void
    {
        $this->assertFalse(method_exists('RelDynStorage', 'migrateLegacy'));
        $this->assertFalse(method_exists('RelationshipDynamics', 'migrateOldPreferences'));
        $this->assertFalse(defined('RelationshipDynamics::DIMENSION_STATE_VERSION'));
        $src = file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/api_save_npc.php')
             . file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/npc_editor_section.php');
        $this->assertStringNotContainsString('activity_preferences', $src, 'editor neither reads nor accepts the April key');
    }
}
