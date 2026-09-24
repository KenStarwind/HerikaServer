<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Minimal `sql`-compatible adapter over one pg connection (lib/postgresql.class.php conventions). */
final class RelDynTemperamentPgDb
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
}

/**
 * ll-temperament-autogen end to end on a real PostgreSQL: the core_npc_master read, the
 * resolution inside getDynamics(), persistence through the merged saveDynamics(), and the
 * dimension baselines on the next load.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 * Each test works in its own schema and drops it.
 */
final class RelDynTemperamentPostgresTest extends TestCase
{
    private string $dsn;
    private string $schema;
    private RelDynTemperamentPgDb $db;
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
            personality text, speechstyle text, core text, npc_static_bio text,
            voiceid text, gender text, race text,
            metadata jsonb,
            extended_data jsonb,
            gamets_last_updated numeric,
            plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object'))");
        pg_query($admin, "CREATE TABLE conf_opts (id text PRIMARY KEY, value text)");
        // Core 3.4.1 eventlog / locations (empty): RelDyn reads the current place from them.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigint NOT NULL, people text, location text, party text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_close($admin);

        $this->db = new RelDynTemperamentPgDb($dsn, $this->schema);
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

    private function coreProfileReads(): int
    {
        return count(array_filter($this->db->statements,
            fn($s) => str_contains($s, 'FROM core_npc_master') && str_contains($s, 'personality')));
    }

    public function testVanillaNpcGetsACoreDerivedProfileThatPersistsAndSeedsDimensions(): void
    {
        $id = $this->seedNpc('Farengar Secret-Fire', 'Spell Vendor', 'Nord', 'sk_malecondescending');

        $d = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame('Guarded', $d['inferred_temperament'], 'class Mage (3) + court wizard faction (2) beat voice Proud (2) and race Bold (1)');
        RelationshipDynamics::ensureLoveLanguage('Farengar Secret-Fire', $d);
        $this->assertTrue(RelationshipDynamics::saveDynamics('Farengar Secret-Fire', $d));

        $s = $this->stored($id);
        $this->assertSame('Guarded', $s['inferred_temperament']);
        $this->assertSame('avoidant', $s['attachment_style']);
        $this->assertSame('Brittle', $s['dimensions']['maturity']['plasticity_type']);
        $this->assertSame([], $s['traits']);
        $this->assertSame('guarded', $s['warmth_curve']);
        $this->assertSame('core', $s['_profile_autogen']['temperament_source']);
        $this->assertSame('Mage', $s['_profile_autogen']['archetype']);

        // Next load: dimensions are seeded from Guarded, not the old Stoic fallback.
        $this->db->statements = [];
        $again = RelationshipDynamics::getDynamics('Farengar Secret-Fire');
        $this->assertSame(0, $this->coreProfileReads(), 'a resolved NPC does not read its core row again');
        $this->assertEquals(RelationshipDynamics::getTemperamentBaseline('Guarded', 'trust'), $again['dimensions']['trust']['x']);
        $this->assertEquals(RelationshipDynamics::getTemperamentBaseline('Guarded', 'self_confidence'), $again['dimensions']['self_confidence']['baseline']);
        $this->assertSame('Brittle', $again['dimensions']['maturity']['plasticity_type']);
        $this->assertSame('avoidant', RelationshipDynamics::getAttachmentStyle($again));

        // Dynamic profiles rewrite personality text; the stored profile stays put.
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET personality = $1 WHERE id = $2',
            ['Arrogant, vain, haughty and pompous.', $id]);
        $this->assertSame('Guarded', RelationshipDynamics::getDynamics('Farengar Secret-Fire')['inferred_temperament']);
    }

    /** Runs the real prerequest hook for one NPC at $gamets (raw game-calendar gamets). */
    private function prerequestAt(string $npc, float $gamets): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['HERIKA_NAME'] = $npc;
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, 'Kaida: hello'];
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
    }

    public function testAbsenceDecayUsesTheCoreDerivedTemperamentNotTheStoicFallback(): void
    {
        // Affinity lane seam: prerequest's absence decay reads inferred_temperament, which
        // on vanilla 3.4.1 was null (every NPC decayed as Stoic). getDynamics now resolves
        // it from core before the decay runs. Control NPC: same core data, stored Stoic.
        $t0 = 400.0 * RelationshipDynamics::GAMETS_PER_DAY;         // raw gamets
        $halfDay = 0.5 * RelationshipDynamics::GAMETS_PER_DAY;      // raw gamets
        $rel = ['aff' => 60, 'type' => 'neutral'];                  // core aff (-100..100): close_friend tier
        $this->seedNpc('Farengar Secret-Fire', 'Spell Vendor', 'Nord', 'sk_malecondescending',
            ['_decay_last_game_gamets' => $t0], '', $rel);
        $this->seedNpc('Wylandriah', 'Spell Vendor', 'Nord', 'sk_malecondescending',
            ['_decay_last_game_gamets' => $t0, 'inferred_temperament' => 'Stoic', 'attachment_style' => 'avoidant'], '', $rel);

        // The attraction matrix can friendzone one of them (a different type modifier); keep
        // it out so the temperament is the only difference between the two NPCs.
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(['config_schema' => RelationshipDynamics::CONFIG_SCHEMA, 'attraction_matrix_enabled' => false])]);
        RelationshipDynamics::clearConfigCache();

        $drop = [];
        foreach (['Farengar Secret-Fire', 'Wylandriah'] as $npc) {
            $this->prerequestAt($npc, $t0 + $halfDay);
            $d = RelationshipDynamics::loadStoredDynamics($npc);
            $this->assertSame('friend', RelationshipDynamics::getRelationshipType($npc, $d));
            // Core points lost: committed whole points plus the fraction still queued for core.
            $drop[$npc] = 60.0 - (RelationshipDynamics::getCoreAffinity($d) + floatval($d['_pending_aff_delta'] ?? 0));
        }
        $derived = RelationshipDynamics::loadStoredDynamics('Farengar Secret-Fire');
        $this->assertSame('Guarded', $derived['inferred_temperament']);
        $this->assertSame('avoidant', $derived['attachment_style'], 'same attachment as the control');

        $this->assertGreaterThan(0.0, $drop['Wylandriah'], 'the Stoic control decays too');
        // Everything but the temperament rate is identical, so the drops keep the rate ratio.
        $ratio = RelationshipDynamics::TEMPERAMENT_DECAY_RATES['Guarded'] / RelationshipDynamics::TEMPERAMENT_DECAY_RATES['Stoic'];
        $this->assertEqualsWithDelta($ratio, $drop['Farengar Secret-Fire'] / $drop['Wylandriah'], 0.05,
            'decay ran at the derived Guarded rate, not the Stoic fallback');
    }

    public function testStateStoredBeforeTheFixIsResolvedAndUntouchedStoicSeedsAreReplaced(): void
    {
        // What 3.4.1 stored before: temperament null, dimensions seeded with Stoic baselines.
        $pre = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $pre['love_language_primary'] = 'acts_of_service';
        $pre['dimensions']['respect']['x'] = 52;   // moved by play since: must survive
        $id = $this->seedNpc('Brelyna Maryon', 'Conjurer', 'DarkElfRace', 'sk_femaledarkelf', $pre);

        $d = RelationshipDynamics::getDynamics('Brelyna Maryon');
        $this->assertSame('Guarded', $d['inferred_temperament']);
        $this->assertTrue(RelationshipDynamics::saveDynamics('Brelyna Maryon', $d));

        $s = $this->stored($id);
        $this->assertSame('Guarded', $s['inferred_temperament']);
        $this->assertEquals(RelationshipDynamics::getTemperamentBaseline('Guarded', 'trust'), $s['dimensions']['trust']['x']);
        $this->assertEquals(RelationshipDynamics::getTemperamentBaseline('Guarded', 'trust'), $s['dimensions']['trust']['baseline']);
        $this->assertEquals(52, $s['dimensions']['respect']['x']);
        $this->assertSame('Brittle', $s['dimensions']['maturity']['plasticity_type']);
        $this->assertSame('acts_of_service', $s['love_language_primary']);
    }

    public function testPerNpcOverrideSurvivesTheSaveAndBeatsTheNamedPreset(): void
    {
        $id = $this->seedNpc('Ysolda', 'Food Vendor', 'Nord', 'sk_femaleyoungeager');
        $d = RelationshipDynamics::getDynamics('Ysolda');
        $this->assertSame('Anxious', $d['inferred_temperament'], 'MDD preset');
        $this->assertSame('preset', $d['_profile_autogen']['temperament_source']);
        $this->assertSame(['insecure'], RelationshipDynamics::getTraits($d));

        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'temperament', 'Playful'));
        $this->assertTrue(RelationshipDynamics::setProfileOverride($d, 'traits', ['egocentric']));
        $this->assertTrue(RelationshipDynamics::saveDynamics('Ysolda', $d));

        $again = RelationshipDynamics::getDynamics('Ysolda');
        $this->assertSame('Playful', $again['inferred_temperament']);
        $this->assertSame(['egocentric'], RelationshipDynamics::getTraits($again));
        $this->assertEquals(['temperament' => 'Playful', 'traits' => ['egocentric']], $this->stored($id)['profile_overrides']);
    }
}
