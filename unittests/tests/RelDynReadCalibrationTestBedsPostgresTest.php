<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynReadCalibrationPgDb
{
    public $link;
    public array $failures = [];
    public array $writes = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    private function note(string $q): void
    {
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $q)) $this->writes[] = substr(preg_replace('/\s+/', ' ', $q), 0, 160);
    }

    public function fetchOne($q, array $params = [])
    {
        $this->note($q);
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q)
    {
        $this->note($q);
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
        $this->writes[] = "INSERT {$table}";
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link, "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * Love-language inference and the read calibration on the four test beds: Aela the Huntress
 * (Companions' Circle werewolf, Hunter), Ashe (Serene's hand-set vector, never read; placeholders
 * only), Muiri (toxic) and Lynly Star-Sung (shy bard), on CHIM 3.4.1 core-shaped rows (race
 * 'NordRace', 'BretonRace') and the committed trait-read seed. What it pins (rulings 2026-09-24 §10:
 * Aela's need is physical, visceral; Ashe's is connection): the intimacy need per bed, the love
 * languages (race primary, temperament secondary), a hand-set vector untouched by the calibration,
 * a stored love language kept, and the switch (traits.read_calibration.enabled) restoring the raw
 * read. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynReadCalibrationTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    private const EVIL = '<b onmouseover="alert(1)">Eve & \'Co\'</b>';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];

    private string $dsn;
    private string $schema;
    private RelDynReadCalibrationPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $llmCalls = 0;
    private int $launches = 0;
    private array $session = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_readcalib_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        self::createSchema($admin);
        pg_close($admin);

        $this->db = new RelDynReadCalibrationPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (170 * RelationshipDynamics::GAMETS_PER_DAY), ''];
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdreadcalib');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_read_calibration_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () { $this->launches++; };
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelationshipDynamics::clearConfigCache();
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        if (!isset($this->schema)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    /** Production-shaped tables (lib/core/database_schema/core_npc_master.sql, data/database_default.sql). */
    private static function createSchema($admin): void
    {
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
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
        pg_query($admin, "CREATE TABLE speech (sess character varying(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial NOT NULL, companions text,
            audios text, mood text, emotion text, emotion_intensity text, utterance_id text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
    }

    /** The four beds (core rows, voice types, placeholder templates, the seed's reads; Ashe never read) plus two list-only rows. */
    private function seed(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_replace(RelationshipDynamics::defaultConfig(), ['internal_weather_enabled' => false]))]);
        RelationshipDynamics::clearConfigCache();
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $beds = self::BEDS + ['Test Wanderer' => ['test_wanderer', 'ImperialRace', 'Pilgrim', [], [], null]];
        foreach ($beds as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe' || $key === 'test_wanderer') continue;   // Ashe: hand-set, never read; the wanderer: no read yet
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, gender, race, extended_data) VALUES ($1, $2, $3, $4::jsonb)',
            [self::EVIL, 'female', 'NordRace', json_encode(['class' => ['name' => 'Citizen']])]);
        pg_query_params($this->db->link, 'INSERT INTO core_npc_master (npc_name, gender, race) VALUES ($1, $2, $3)', ['Nazeem', 'male', 'RedguardRace']);
    }

    // ------------------------------------------------------------------ helpers

    private function stored(string $npc): ?array
    {
        return RelationshipDynamics::loadStoredDynamics($npc);
    }

    /** Store the NPC's resolved state (what her first turn would store), through the engine's own save. */
    private function track(string $npc): array
    {
        RelationshipDynamics::beginRequest();
        try {
            RelDynTraitRead::stateFor($npc, false);
            $d = RelationshipDynamics::getDynamics($npc);
            $this->assertTrue(RelationshipDynamics::saveDynamics($npc, $d), "first save of {$npc}");
        } finally {
            RelationshipDynamics::endRequest();
        }
        return $this->stored($npc);
    }

    /** Turn the read calibration (config traits.read_calibration.enabled) on or off, as the settings row would. */
    private function calibration(bool $on): void
    {
        $cfg = array_replace(RelationshipDynamics::defaultConfig(), ['internal_weather_enabled' => false]);
        $cfg['traits']['read_calibration']['enabled'] = $on;
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $1 WHERE id = $2', [json_encode($cfg), RelationshipDynamics::CONFIG_ROW_ID]);
        RelationshipDynamics::clearConfigCache();
    }

    /** The NPC's love languages, intimacy need and vector from her stored state (nothing here is stored back). */
    private function profile(string $npc): array
    {
        $d = $this->track($npc);
        RelationshipDynamics::ensureLoveLanguage($npc, $d);
        RelDynIntimacy::ensureNeed($npc, $d);
        $need = RelDynIntimacy::need($d);
        return ['d' => $d, 'physical' => $need['physical'], 'emotional' => $need['emotional'],
                'll' => [$d['love_language_primary'], $d['love_language_secondary']], 'vec' => RelDynTraits::readVector($d)];
    }

    // ------------------------------------------------------------------ the beds

    public function testAelaNeedsPhysicalIntimacyStronglyAndMoreThanEmotional(): void
    {
        $a = $this->profile(self::AELA);
        $this->assertGreaterThanOrEqual(0.7, $a['physical'], 'rulings §10: Aela, strongly (physical, visceral)');
        $this->assertGreaterThan($a['emotional'] + 0.1, $a['physical'], 'physical, not emotional');
        $this->assertSame([], $this->db->failures);
    }

    public function testAsheNeedsConnectionNotSexAndHerHandSetVectorIsUntouched(): void
    {
        $this->calibration(true);
        $on = $this->profile('Ashe');
        $this->assertGreaterThanOrEqual(0.6, $on['emotional'], 'Ashe is about connection');
        $this->assertLessThanOrEqual(0.25, $on['physical'], 'Ashe is not about sex');
        $this->assertSame('hand-set', $on['d']['_trait_vector_src']['traits']['guard']['preset']);
        $this->calibration(false);
        $off = $this->profile('Ashe');
        // a hand-set vector is nobody's read: byte-identical with the calibration on or off, stored and used
        $this->assertSame(json_encode($on['vec']), json_encode($off['vec']));
        $this->assertSame(json_encode($on['d']['trait_vector']), json_encode($off['d']['trait_vector']));
        $this->assertEqualsWithDelta(0.75, $on['vec']['G'], 1e-12);
        $this->assertEqualsWithDelta(0.40, $on['vec']['W'], 1e-12);
    }

    public function testMuiriAndLynlyAreNotAela(): void
    {
        $aela = $this->profile(self::AELA);
        foreach (['Muiri', self::LYNLY] as $npc) {
            $p = $this->profile($npc);
            $gap = max(abs($p['physical'] - $aela['physical']), abs($p['emotional'] - $aela['emotional']));
            $this->assertGreaterThanOrEqual(0.1, $gap, "{$npc} differs from Aela on at least one need axis");
        }
        $this->assertNotSame($aela['ll'], $this->profile('Muiri')['ll'], "Muiri's love languages are not Aela's");
    }

    public function testLoveLanguagesFollowTheCoreRaceAndTheTemperament(): void
    {
        $pairs = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $pairs[$npc] = $this->profile($npc)['ll'];
            $this->assertNotSame($pairs[$npc][0], $pairs[$npc][1], "{$npc}: a secondary equal to the primary rotates");
        }
        // NordRace: acts of service (Aela, Lynly); BretonRace: words of affirmation (Ashe, Muiri)
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $pairs[self::AELA][0]);
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $pairs[self::LYNLY][0]);
        $this->assertSame(RelationshipDynamics::LL_WORDS, $pairs['Ashe'][0]);
        $this->assertSame(RelationshipDynamics::LL_WORDS, $pairs['Muiri'][0]);
        $this->assertGreaterThan(1, count(array_unique(array_map('json_encode', $pairs))), 'the four beds do not all share one pair');
        // the secondary is the temperament's: Aela's (Bold-leaning) is touch, Ashe's (Stoic-leaning) service
        $this->assertSame(RelationshipDynamics::LL_TOUCH, $pairs[self::AELA][1]);
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $pairs['Ashe'][1]);
    }

    public function testAStoredLoveLanguageIsKept(): void
    {
        $d = $this->track(self::AELA);
        $d['love_language_primary'] = RelationshipDynamics::LL_GIFTS;
        $d['love_language_secondary'] = RelationshipDynamics::LL_TIME;
        RelationshipDynamics::ensureLoveLanguage(self::AELA, $d);
        $this->assertSame(RelationshipDynamics::LL_GIFTS, $d['love_language_primary']);
        $this->assertSame(RelationshipDynamics::LL_TIME, $d['love_language_secondary']);
    }

    public function testTheSwitchRestoresTheRawReadAndTheOldRules(): void
    {
        $this->calibration(true);
        $on = $this->profile(self::AELA);
        $this->calibration(false);
        $off = $this->profile(self::AELA);
        $raw = RelDynTraits::fromStored($off['d']['trait_vector']);
        $this->assertSame(json_encode($raw), json_encode($off['vec']), 'off: the stored read is the vector, as before');
        $this->assertLessThan($raw['G'] - 0.02, $on['vec']['G'], "on: her guard read is corrected for the LLM's leniency");
        // the stored vector is the raw read either way (the correction is applied at use time)
        $this->assertSame(json_encode($on['d']['trait_vector']), json_encode($off['d']['trait_vector']));
        // off: the textbook intercepts, so a middle-ish read carries the old +0.2 emotional offset
        $this->assertGreaterThan($on['emotional'] + 0.1, $off['emotional']);
    }
}
