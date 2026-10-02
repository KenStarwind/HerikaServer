<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynPronounsBedsPgDb
{
    public $link;
    public array $failures = [];

    public function __construct(string $dsn, string $schema)
    {
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) { $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160); return []; }
        return pg_fetch_assoc($res) ?: [];
    }

    public function fetchAll($q, $log = false)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) throw new RuntimeException('fetchAll failed: ' . pg_last_error($this->link));
        $rows = [];
        while ($row = pg_fetch_assoc($res)) $rows[] = $row;
        return $rows;
    }

    public function query($q) { return $this->fetchOne($q); }
    public function execQuery($q)
    {
        $res = @pg_query($this->link, $q);
        if (!$res) $this->failures[] = pg_last_error($this->link);
        return $res;
    }

    public function insert($table, $data)
    {
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
 * Pronouns (Ken 2026-10-01 §21): RelDyn is for every character regardless of gender. The five test beds (Aela, Ashe, Muiri,
 * Lynly Star-Sung, and Farkas, a man) through the real hooks (prerequest -> context_pre -> context -> postrequest) and the
 * real stores on a real PostgreSQL from CHIM 3.4.1 core-shaped rows. The felt text tables carry the NPC's pronoun vars
 * ({THEY} {THEIR} ...); each NPC is told them in the pronouns core_npc_master.gender gives, neutral where core does not say,
 * and never in a default she. No LLM call.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynPronounsTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
        'Farkas'            => ['farkas', 'NordRace', 'Warrior', ['CompanionsFaction'], ['twohanded' => 70, 'heavyarmor' => 55], 'sk_malenord'],
    ];
    /** core_npc_master.gender of each bed (Farkas is the man; the others are the women the beds always were) */
    private const GENDER = ['Farkas' => 'male'];

    private string $dsn;
    private string $schema;
    private RelDynPronounsBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY + 10 * self::HOUR;
    private int $llmCalls = 0;

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_pronbeds' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // lib/core/database_schema/core_npc_master.sql / core_npc_master_history.sql columns.
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
        // data/database_default.sql eventlog / responselog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
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
        pg_close($admin);

        $this->db = new RelDynPronounsBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdpronbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_pronouns_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };

        // Shipped defaults, stored as the config page stores them
        $this->storeConfig([]);
        $this->seed();
        $this->warrior();
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
        $this->clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    // ------------------------------------------------------------------ fixture

    private function storeConfig(array $top): void
    {
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true], $top))]);
        RelationshipDynamics::clearConfigCache();
    }

    /** Core rows, voice types, placeholder templates, and the seed's reads keyed to them (Ashe: skip-listed, hand-set). */
    private function seed(): void
    {
        $seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        foreach (self::BEDS as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, self::GENDER[$name] ?? 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 10, 'type' => 'platonic']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
    }

    /** A core_player row as core writes it (gamedata.php: one row per id, value text). */
    private function corePlayer(string $id, $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /** The player: a seasoned warrior and Companion (skills, level, tracked Skyrim stats), as the plugin reports it. */
    private function warrior(): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speechcraft', 'twohanded'], 15);
        $this->corePlayer('skills', array_merge($all, ['onehanded' => 70, 'twohanded' => 60, 'block' => 55, 'heavyarmor' => 60]));
        $this->corePlayer('stats', ['level' => 30, 'health' => 400]);
        foreach (['People Killed' => 110, 'Creatures Killed' => 135, 'Quests Completed' => 27, 'Dungeons Cleared' => 17,
                     'Locations Discovered' => 70, 'The Companions Quests Completed' => 4] as $stat => $n) {
            $this->corePlayer($stat, (string) $n);
        }
        $this->corePlayer('gender', 'male');
    }

    /** One player line to $npc through the real hooks: prerequest, context, postrequest; the NPC answers in $mood. */
    private function turn(string $npc, string $line, ?string $mood = null): void
    {
        if ($mood !== null) {
            pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)', [$npc, $mood, $this->realTs]);
        }
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->gamets += 5 * 60 * RelationshipDynamics::GAMETS_PER_DAY / 1440;
        $this->realTs += 60;
    }

    private function ped(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true) ?? [];
    }

    private function dynamics(string $npc): array
    {
        return $this->ped($npc)['reldyn']['dynamics'] ?? [];
    }

    /** Edit $npc's stored RelDyn state (as the editor or an older save would hold it). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $ped = $this->ped($npc);
        $d = $ped['reldyn']['dynamics'];
        $edit($d);
        $ped['reldyn']['dynamics'] = $d;
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /** Core's relationship to the player (CHIM 3.4.1 key "Player"). */
    private function setCore(string $npc, int $aff, string $type = 'platonic'): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb)
            WHERE npc_name = $1", [$npc, json_encode([RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => $aff, 'type' => $type]])]);
    }


    // ------------------------------------------------------------------ helpers of this test

    private const BARE = '/\b(she|her|hers|herself|he|him|his|himself)\b/i';
    /** A sentence made of the pronoun vars, in every felt text table the bond reaches the LLM through. */
    private const SENTENCE = '{They_are} guarded: {THEIR} manner, {THEIR} voice; {THEY} keep{S} it to {THEMSELF}';
    private const SENTENCES = [
        'm' => 'He is guarded: his manner, his voice; he keeps it to himself',
        'f' => 'She is guarded: her manner, her voice; she keeps it to herself',
        'n' => 'They are guarded: their manner, their voice; they keep it to themself',
    ];

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    /** One NPC at a mid bond: a romance with passion, comfort and trust to match. */
    private function bond(string $npc): void
    {
        $this->setCore($npc, 70, 'romantic');
        $this->turn($npc, 'Well met.');
        $this->editDynamics($npc, function (array &$d): void {
            RelationshipDynamics::setPassion($d, 65.0);
            foreach (['comfort' => 70.0, 'trust' => 72.0, 'resentment' => 0.0] as $dim => $v) {
                $d['dimensions'][$dim]['x'] = $v;
                $d['dimensions'][$dim]['baseline'] = $v;
            }
            $d['in_conflict'] = false;
        });
    }

    /**
     * Everything RelDyn puts in front of the LLM for one player line to $npc: <character> (HERIKA_PERS after context_pre.php:
     * <knowledge_of_player>, <emotional_core>) and the context blocks (<subtext>), through the real hooks.
     */
    private function told(string $npc, string $line = 'Hm.'): string
    {
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $party = '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
        $told = '';
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            } elseif ($hook === 'prerequest.php') {
                $GLOBALS['contextDataFull'] = [];
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $told = (string) ($GLOBALS['HERIKA_PERS'] ?? '') . "\n"
                    . implode("\n", array_map(fn($m) => (string) ($m['content'] ?? ''), (array) ($GLOBALS['contextDataFull'] ?? [])));
            }
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->gamets += 5 * 60 * RelationshipDynamics::GAMETS_PER_DAY / 1440;
        $this->realTs += 60;
        return $told;
    }

    /** The felt text tables that reach the LLM at a bond, written with the NPC's pronoun vars (the shipped ones name the NPC). */
    private function pronounTables(): void
    {
        $knowledge = [];
        foreach (array_keys((array) RelDynFelt::configDefaults()['text']['knowledge']) as $tier) {
            $knowledge[$tier] = '{NAME} knows {PLAYER}; ' . self::SENTENCE . '.';
        }
        $this->storeConfig(['felt_steering' => ['text' => [
            'header_bond' => '{NAME} with {PLAYER} right now. ' . self::SENTENCE . ':',
            'header_self' => '{NAME} right now. ' . self::SENTENCE . ':',
            'core_header' => 'Underneath, ' . self::SENTENCE . ':',
            'knowledge' => $knowledge,
        ]]]);
    }

    // ------------------------------------------------------------------ the tests

    public function testEachBedIsToldInThePronounsCoreGivesIt(): void
    {
        $this->pronounTables();
        $expect = ['Aela the Huntress' => 'f', 'Ashe' => 'f', 'Muiri' => 'f', 'Lynly Star-Sung' => 'f', 'Farkas' => 'm'];
        foreach ($expect as $npc => $g) {
            $this->assertSame($g, RelDynPronouns::genderOf($npc), "{$npc}: from core_npc_master.gender, not from a default");
            $this->bond($npc);
            $told = $this->told($npc);
            $this->assertGreaterThanOrEqual(2, substr_count($told, self::SENTENCES[$g]),
                "{$npc}: the knowledge line and a header both carry the pronouns core gives:\n{$told}");
            foreach (self::SENTENCES as $other => $sentence) {
                if ($other !== $g) $this->assertStringNotContainsString($sentence, $told, "{$npc}: never another gender's pronouns");
            }
            $this->assertDoesNotMatchRegularExpression('/\{(?:THEY|THEM|THEIR|THEMSELF|THEIRS|They|Them|Their|S)[A-Za-z_]*\}/', $told, "{$npc}: every var resolved");
        }
        $this->assertNoDbFailures();
    }

    public function testTheShippedTextNamesTheNPCAndNeverAssumesAPronounForAnyBed(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $stranger = $this->told($npc, 'Well met.');
            $this->assertStringContainsString($npc, $stranger, $npc);
            $this->assertDoesNotMatchRegularExpression(self::BARE, str_replace("Roleplay as {$npc}.", '', $stranger), "{$npc}: a first meeting");
            $this->bond($npc);
            $bonded = $this->told($npc);
            $this->assertStringContainsString('<subtext>', $bonded, "{$npc}: the bond reaches the LLM as felt text");
            $this->assertDoesNotMatchRegularExpression(self::BARE, str_replace("Roleplay as {$npc}.", '', $bonded), "{$npc}: a bond");
            $this->editDynamics($npc, function (array &$d): void { $d['in_conflict'] = true; RelationshipDynamics::setPassion($d, 80.0); });
            $strained = $this->told($npc, 'You lied to me.');
            $this->assertDoesNotMatchRegularExpression(self::BARE, str_replace("Roleplay as {$npc}.", '', $strained), "{$npc}: a conflict");
        }
        $this->assertNoDbFailures();
    }

    public function testAnNPCCoreDoesNotGiveAGenderIsToldInNeutralPronounsNeverShe(): void
    {
        $this->pronounTables();
        foreach (['Lynly Star-Sung' => '', 'Muiri' => 'other', 'Ashe' => null] as $npc => $gender) {
            pg_query_params($this->db->link, 'UPDATE core_npc_master SET gender = $2 WHERE npc_name = $1', [$npc, $gender]);
            RelDynPronouns::reset();
            $this->bond($npc);
            $told = $this->told($npc);
            $this->assertGreaterThanOrEqual(2, substr_count($told, self::SENTENCES['n']), "{$npc}: neutral:\n{$told}");
            $this->assertStringNotContainsString(self::SENTENCES['f'], $told, "{$npc}: never a default she");
            $this->assertStringNotContainsString(self::SENTENCES['m'], $told, "{$npc}: never a default he");
        }
        $this->assertNoDbFailures();
    }

    public function testTheConsentDecisionSharmatReadsAndTheExclusivityBlockUseTheSamePronouns(): void
    {
        $cfg = RelDynConsent::config();
        $cfg['felt_text']['declines']['default'] = '{NAME} is not ready. ' . self::SENTENCE . '.';
        $decision = ['stance' => 'declines', 'reasons' => []];
        $man = RelDynConsent::feltText('Farkas', self::PLAYER, $decision, $cfg);
        $woman = RelDynConsent::feltText('Aela the Huntress', self::PLAYER, $decision, $cfg);
        $this->assertStringContainsString(self::SENTENCES['m'], (string) $man);
        $this->assertStringContainsString(self::SENTENCES['f'], (string) $woman);
        $this->assertStringContainsString('Aela the Huntress is not ready.', (string) $woman);

        $block = RelDynExclusivity::render('Farkas', 'Vilkas', '{They} turn{S} {THEM} aside and {THEIR} heart stays put.');
        $this->assertStringContainsString('- He turns him aside and his heart stays put.', $block);
        $block = RelDynExclusivity::render('Muiri', 'Vilkas', '{They} turn{S} {THEM} aside and {THEIR} heart stays put.');
        $this->assertStringContainsString('- She turns her aside and her heart stays put.', $block);
        $this->assertNoDbFailures();
    }

    public function testTheSameBondDivergesByWhoTheNPCIsAndOnlyThePronounsFollowTheirGender(): void
    {
        $this->pronounTables();
        $told = [];
        foreach (['Farkas', 'Aela the Huntress'] as $npc) {
            $this->bond($npc);
            $told[$npc] = $this->told($npc);
        }
        // the felt lines differ by who each NPC is; the pronoun sentence is the one place they differ by gender alone
        $strip = fn(string $t, string $name): string => str_replace(
            [self::SENTENCES['m'], self::SENTENCES['f'], $name], ['<P>', '<P>', '<N>'], $t);
        $this->assertGreaterThanOrEqual(2, substr_count($strip($told['Farkas'], 'Farkas'), '<P>'));
        $this->assertGreaterThanOrEqual(2, substr_count($strip($told['Aela the Huntress'], 'Aela the Huntress'), '<P>'));
        $this->assertNotSame($strip($told['Farkas'], 'Farkas'), $strip($told['Aela the Huntress'], 'Aela the Huntress'),
            'two different people: the same bond does not read the same');
    }
}
