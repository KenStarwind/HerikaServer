<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Minimal `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynTraitStoragePgDb
{
    public $link;
    public array $statements = [];

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
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        $res = $params ? pg_query_params($this->link, $q, $params) : pg_query($this->link, $q);
        return $res ? (pg_fetch_assoc($res) ?: []) : [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        $res = pg_query($this->link, $q);
        $rows = [];
        while ($res && ($row = pg_fetch_assoc($res))) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function execQuery($q)
    {
        $this->statements[] = preg_replace('/\s+/', ' ', trim($q));
        return pg_query($this->link, $q);
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }

    /** lib/postgresql.class.php insert(): core's timeline stamp writes core_npc_master_history with it. */
    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        return pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
    }
}

/**
 * ll-temperament-autogen end to end on a real PostgreSQL: the core_npc_master read, the
 * resolution inside getDynamics(), persistence through the merged saveDynamics(), and the
 * dimension baselines on the next load.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
/**
 * Personality traits phase 1 storage on a real PostgreSQL (design §4.6): trait_vector is a NEW
 * key of plugin_extended_data.reldyn.dynamics that mirrors the NPC's temperament label (its
 * preset point) through getDynamics() / saveDynamics(); the 'traits' tag list is untouched; the
 * profile_overrides.trait_vector field is stored and not read by the phase-1 assignment.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynTraitStoragePostgresTest extends TestCase
{
    private string $dsn;
    private string $schema;
    private RelDynTraitStoragePgDb $db;
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
        $this->schema = 'reldyn_tmp' . getmypid() . '_' . bin2hex(random_bytes(3));

        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Column types as in lib/core/database_schema/core_npc_master.sql, for what RelDyn reads.
        pg_query($admin, "CREATE TABLE core_npc_master (
            id serial PRIMARY KEY,
            npc_name text NOT NULL,
            npc_favorite integer DEFAULT 0,
            lock_profile integer DEFAULT 0,
            prompt_head text,
            oghma_knowledge_tags text,
            emote_moods text,
            relationships text,
            occupation text,
            appearance text,
            skills text,
            goals text,
            refid character varying(16),
            profile_id integer,
            dynamic_profile integer,
            md5 text,
            base text,
            tags text,
            personality text, speechstyle text, core text, npc_static_bio text,
            voiceid text, gender text, race text,
            metadata jsonb,
            extended_data jsonb,
            gamets_last_updated numeric,
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
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE core_player (id text NOT NULL, value text, CONSTRAINT core_player_pkey PRIMARY KEY (id))"); // prerequest gold ledger + profile the Attraction Matrix reads (RelDynPlayer)
        // core quests journal (RelDynPlayer::profile questlines)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        // Core 3.4.1 eventlog / locations (empty): RelDyn reads the current place from them.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigint NOT NULL, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_close($admin);

        $this->db = new RelDynTraitStoragePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        unset($GLOBALS['PLAYER_NAME'], $GLOBALS['gameRequest'], $GLOBALS['HERIKA_NAME']);
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) {
            return;
        }
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** Insert a vanilla-shaped NPC (processor/comm.php addnpc layout), optionally with stored RelDyn state. */
    private function seedNpc(string $name, string $className, string $race, string $voice, ?array $dynamics = null, string $personality = '', ?array $playerRel = null): int
    {
        $meta = ['skills' => ['destruction' => '45', 'conjuration' => '40', 'speech' => '20', 'onehanded' => '15']];
        $ext = ['class' => ['name' => $className, 'formid' => '0x00013176'], 'factions' => [
            ['formid' => '0x0002816e', 'rank' => 0, 'name' => 'JobCourtWizardFaction'],
        ]];
        if ($playerRel !== null) {
            $ext['relationships'] = ['Player' => $playerRel];   // core relationships.Player (aff -100..100)
        }
        $plugin = $dynamics === null ? '{}' : json_encode(['reldyn' => ['dynamics' => $dynamics]]);
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, personality, speechstyle, core, npc_static_bio, voiceid, gender, race, metadata, extended_data, plugin_extended_data)'
            . ' VALUES ($1, $2, \'\', $3, \'\', $4, \'male\', $5, $6::jsonb, $7::jsonb, $8::jsonb) RETURNING id',
            [$name, $personality, "Roleplay as {$name}", $voice, $race, json_encode($meta), json_encode($ext), $plugin]));
        return (int) $row['id'];
    }

    private function stored(int $id): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE id = $1', [$id]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    public function testResolvedNpcStoresTheVectorOfItsLabelAndItFollowsALabelChange(): void
    {
        $id = $this->seedNpc('Farengar Secret-Fire', 'Spell Vendor', 'Nord', 'sk_malecondescending');

        $d = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame('Guarded', $d['inferred_temperament']);
        $this->assertSame(RelDynTraits::toStored(RelDynTraits::points()['Guarded']), $d['trait_vector']);
        $this->assertTrue(RelationshipDynamics::saveDynamics('Farengar Secret-Fire', $d));

        $s = $this->stored($id);
        $this->assertSame('Guarded', $s['inferred_temperament']);
        $this->assertEqualsWithDelta(RelDynTraits::toStored(RelDynTraits::points()['Guarded']), $s['trait_vector'], 1e-12);
        $this->assertEquals(['source' => 'preset', 'preset' => 'Guarded', 'assignment' => 'label'], $s['_trait_vector_src']);   // jsonb orders keys
        $this->assertSame('Guarded', $s['trait_preset']['nearest']);
        $this->assertSame(RelDynTraits::VERSION, $s['trait_vector_version']);
        $this->assertSame([], $s['traits'], 'the trait TAG list is its own key, untouched');
        $this->assertSame('Brittle', $s['dimensions']['maturity']['plasticity_type']);

        // The editor writes the label directly (api_save_npc); the save carries its vector along
        $d = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $d['inferred_temperament'] = 'Proud';
        $this->assertTrue(RelationshipDynamics::saveDynamics('Farengar Secret-Fire', $d));
        $s = $this->stored($id);
        $this->assertSame('Proud', $s['inferred_temperament']);
        $this->assertEqualsWithDelta(RelDynTraits::toStored(RelDynTraits::points()['Proud']), $s['trait_vector'], 1e-12);

        // Behaviour reads the label's preset point, whatever sits in the stored key (phase 1)
        $again = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame(0.8, RelDynTraits::param($again['inferred_temperament'], 'passion_mult', 1.0, $again));
        $this->assertSame(RelationshipDynamics::TEMPERAMENT_SIGNAL_RESISTANCE['Proud']['respect'],
            RelationshipDynamics::getSignalResistance($again['inferred_temperament'], 'respect'));
    }

    public function testTraitVectorOverridePersistsWithoutMovingTheAssignment(): void
    {
        $id = $this->seedNpc('Farengar Secret-Fire', 'Spell Vendor', 'Nord', 'sk_malecondescending');
        $d = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $before = $d;
        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'trait_vector', ['guard' => 0.5, 'warmth' => 0.6]));
        $this->assertTrue(RelationshipDynamics::saveDynamics('Farengar Secret-Fire', $d));

        $s = $this->stored($id);
        $this->assertSame(['guard' => 0.5, 'warmth' => 0.6], $s['profile_overrides']['trait_vector']);
        $this->assertSame('Guarded', $s['inferred_temperament']);
        $this->assertEqualsWithDelta($before['trait_vector'], $s['trait_vector'], 1e-12);
        $this->assertEquals($before['dimensions']['trust'], $s['dimensions']['trust']);

        // the next load keeps it, and every parameter is still Guarded's
        $again = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame(['guard' => 0.5, 'warmth' => 0.6], $again['profile_overrides']['trait_vector']);
        $this->assertSame(0.6, RelDynTraits::param($again['inferred_temperament'], 'passion_mult', 1.0, $again));
        $this->assertSame(20.0, RelationshipDynamics::getTemperamentBaseline($again['inferred_temperament'], 'trust'));
    }

    /** A label the editor stored directly (not an override) survives a trait_vector override round trip. */
    public function testTraitVectorOverrideKeepsAnEditorStoredLabel(): void
    {
        $id = $this->seedNpc('Farengar Secret-Fire', 'Spell Vendor', 'Nord', 'sk_malecondescending');
        $d = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame('Guarded', $d['_profile_autogen']['base_temperament']);
        $d['inferred_temperament'] = 'Proud';   // api_save_npc writes the label, not profile_overrides
        $this->assertTrue(RelationshipDynamics::saveDynamics('Farengar Secret-Fire', $d));

        $d = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame('Proud', $d['inferred_temperament']);
        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'trait_vector', ['guard' => 0.4]));
        $this->assertTrue(RelationshipDynamics::saveDynamics('Farengar Secret-Fire', $d));

        $s = $this->stored($id);
        $this->assertSame('Proud', $s['inferred_temperament']);
        $this->assertSame(['guard' => 0.4], $s['profile_overrides']['trait_vector']);
        $this->assertEqualsWithDelta(RelDynTraits::toStored(RelDynTraits::points()['Proud']), $s['trait_vector'], 1e-12);
        $again = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame('Proud', $again['inferred_temperament']);
        $this->assertSame(0.8, RelDynTraits::param($again['inferred_temperament'], 'passion_mult', 1.0, $again));
    }
}
