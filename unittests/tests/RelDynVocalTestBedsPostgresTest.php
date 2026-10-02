<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynVocalBedsPgDb
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
 * Vocal style (Ken 2026-10-01 24: Sharmat scene awareness, vocalization per NPC), the four test beds through the real hooks
 * (prerequest -> context -> postrequest), the real stores and the committed seed's bio reads, on a real PostgreSQL from CHIM 3.4.1
 * core-shaped rows. No LLM call.
 *
 * RelDyn publishes how much each NPC talks in bed and how they sound to plugin_extended_data.reldyn.vocal for Sharmat: a register,
 * a silence chance that is never 0 and never 1, a small pace and a line about how the NPC sounds. The beds are Aela (bold,
 * visceral), Ashe (hand-set vector), Muiri (toxic) and Lynly (a bard, the read as it stands: the bio does not establish
 * shyness; the shy mechanism is tested on a hand-set copy). Text never assumes a pronoun.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynVocalTestBedsPostgresTest extends TestCase
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
    ];

    private string $dsn;
    private string $schema;
    private RelDynVocalBedsPgDb $db;
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
        $this->schema = 'reldyn_vocalbeds' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynVocalBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdvocalbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_vocal_beds_test.log');
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
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
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

    /** What Sharmat reads: plugin_extended_data.reldyn.vocal. */
    private function vocal(string $npc): array
    {
        return $this->ped($npc)['reldyn']['vocal'] ?? [];
    }

    /** The decision Sharmat's consent hook reads (it feeds the vocal reading through the last stance kept). */
    private function consent(string $npc): array
    {
        return $this->ped($npc)['reldyn']['consent'] ?? [];
    }

    /** A romance that has earned intimacy: core romantic, deep affinity, passion, comfort and trust (the bond RelDyn would open to). */
    private function deepRomance(string $npc, int $aff = 80): void
    {
        $this->setCore($npc, $aff, 'romantic');
        $this->turn($npc, 'Well met.');
        $this->editDynamics($npc, function (array &$d): void {
            RelationshipDynamics::setPassion($d, 75.0);
            foreach (['comfort' => 82.0, 'trust' => 84.0, 'resentment' => 0.0] as $dim => $v) {
                $d['dimensions'][$dim]['x'] = $v;
                $d['dimensions'][$dim]['baseline'] = $dim === 'resentment' ? 0.0 : $v;
            }
            $d['in_conflict'] = false;
        });
    }

    private function assertNoDbFailures(): void
    {
        $this->assertSame([], $this->db->failures, 'SQL failures: ' . implode(' | ', $this->db->failures));
    }

    private function noPronoun(?string $text, string $why): void
    {
        $this->assertIsString($text, $why);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his|herself|himself)\b/i', $text, $why);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text, $why);
    }

    // ------------------------------------------------------------------ the tests

    public function testEveryBedPublishesTheDecisionForSharmatAndAStrangerIsRefused(): void
    {
        $felt = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Well met.');
            $c = $this->consent($npc);
            $this->assertSame(1, $c['v'] ?? null, "{$npc}: published to plugin_extended_data.reldyn.consent");
            $this->assertTrue($c['enabled'], $npc);
            $this->assertFalse($c['allow'], "{$npc}: a first meeting does not get there");
            $this->assertSame('declines', $c['stance'], $npc);
            $this->assertNotSame([], $c['reasons'], "{$npc}: says why");
            $this->assertEqualsWithDelta($this->gamets - 5 * 60 * self::DAY / 1440, $c['gamets'], self::HOUR, "{$npc}: stamped on the game clock");
            $this->assertStringContainsString($npc, (string) $c['felt'], $npc);
            $this->noPronoun($c['felt'], $npc);
            $felt[$npc] = $c;
            // the older romance state keeps its flag; the consent key is the decision that supersedes it
            $this->assertArrayHasKey('romance', $this->ped($npc)['reldyn'], $npc);
        }
        $this->assertNull(pg_fetch_result(pg_query($this->db->link, "SELECT to_regclass('nsfw_npc_data')"), 0, 0), "RelDyn never creates Sharmat's store");
        $this->assertNoDbFailures();
    }

    /**
     * One scene at a bond part of the way there (a crush, mid affinity and passion, comfort and trust to match): $apply sets
     * the state on the NPC's stored dynamics (everything volatile is cleared first, so scenes do not carry over), $dims the
     * dimension points, then a turn through the real hooks. Returns what Sharmat reads.
     */
    private function scene(string $npc, ?callable $apply = null, array $dims = [], int $aff = 45, string $type = 'crush', float $passion = 35.0): array
    {
        $this->setCore($npc, $aff, $type);
        $this->turn($npc, 'Well met.');
        $this->editDynamics($npc, function (array &$d) use ($apply, $dims, $passion): void {
            RelationshipDynamics::setPassion($d, $passion);
            foreach (array_replace(['comfort' => 60.0, 'trust' => 65.0, 'resentment' => 0.0], $dims) as $dim => $v) {
                $d['dimensions'][$dim]['x'] = $v;
                $d['dimensions'][$dim]['baseline'] = $dim === 'resentment' ? 0.0 : $v;
            }
            $d['in_conflict'] = false;
            unset($d['_consent'], $d['_pullback'], $d['_ick_tracker'], $d['_keeping'], $d['_walkaway_state']);
            if ($apply !== null) $apply($d);
        });
        $this->turn($npc, 'Hm.');
        return $this->vocal($npc);
    }

    /** The states the soft factors read, as the editor or an older save would hold them. */
    private function states(): array
    {
        return [
            'conflict'    => function (array &$d): void { $d['in_conflict'] = true; $d['conflict_positive_count'] = 0; },   // (the quarrel holds through the exchange that follows: three kind words repair it)
            'ick'         => function (array &$d): void {
                $d['_ick_tracker'] = ['ick_active' => true, 'quiet' => 0, 'romantic_count' => 3, 'total_count' => 3, 'counted_gamets' => []];
                $d['dimensions']['comfort']['x'] = $d['dimensions']['comfort']['baseline'] = 25.0;
            },
            'withdrawn'   => function (array &$d): void { $d['dimensions']['resentment']['x'] = 80.0; },
            'pulled_back' => function (array &$d): void {
                $d['_pullback'] = ['v' => RelDynPullback::VERSION, 'pressure' => 0.8, 'active' => true, 'since_gamets' => $this->gamets, 'gamets' => $this->gamets, 'say' => [], 'episodes' => 1];
            },
            'not_let_in'  => function (array &$d): void {
                $d['dimensions']['comfort']['x'] = $d['dimensions']['comfort']['baseline'] = 25.0;
                $d['dimensions']['trust']['x'] = $d['dimensions']['trust']['baseline'] = 30.0;
            },
        ];
    }

    /** Aela, Ashe, Muiri and Lynly in one state: the people they are, at the same bond. */
    private function allBeds(?callable $apply = null, array $dims = [], int $aff = 45, string $type = 'crush', float $passion = 35.0): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) $out[$npc] = $this->scene($npc, $apply, $dims, $aff, $type, $passion);
        return $out;
    }

    private function noPronounOrDigit(?string $text, string $why): void
    {
        $this->assertIsString($text, $why);
        $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his|herself|himself)\b/i', $text, $why);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text, $why);
    }

    /** Aela, Ashe, Muiri and Lynly at a deep bond: a romance, passion, comfort and trust. */
    private function deepBeds(?callable $apply = null): array
    {
        return $this->allBeds($apply, ['comfort' => 85.0, 'trust' => 85.0], 85, 'romantic', 80.0);
    }

    public function testEveryBedPublishesItsVocalStyleForSharmat(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Well met.');
            $v = $this->vocal($npc);
            $this->assertSame(1, $v['v'] ?? null, "{$npc}: published to plugin_extended_data.reldyn.vocal");
            $this->assertTrue($v['enabled'], $npc);
            $this->assertContains($v['style'], RelDynVocal::STYLES, $npc);
            $this->assertGreaterThan(0.0, $v['silence_chance'], "{$npc}: never certain to speak");
            $this->assertLessThanOrEqual(0.85, $v['silence_chance'], "{$npc}: never certain to stay silent");
            $this->assertEqualsWithDelta(1.0, $v['pace'], 0.1, $npc);
            $this->assertEqualsWithDelta($this->gamets - 5 * 60 * self::DAY / 1440, $v['gamets'], self::HOUR, "{$npc}: stamped on the game clock");
            if ($v['style'] !== 'open') {
                $this->assertStringContainsString($npc, (string) $v['felt'], $npc);
                $this->noPronounOrDigit($v['felt'], $npc);
            } else {
                $this->assertNull($v['felt'], $npc);
            }
        }
        $this->assertNull(pg_fetch_result(pg_query($this->db->link, "SELECT to_regclass('nsfw_npc_data')"), 0, 0), "RelDyn never creates Sharmat's store");
        $this->assertNoDbFailures();
    }

    public function testTheBedsSoundDifferentAndAshesCharacterIsTheQuietest(): void
    {
        foreach (['mid' => $this->allBeds(), 'deep' => $this->deepBeds()] as $label => $v) {
            $silence = array_map(fn($x) => $x['silence_chance'], $v);
            $styles = array_map(fn($x) => $x['style'], $v);
            $ctx = json_encode([$silence, $styles]);
            $this->assertGreaterThan(0.3, max($silence) - min($silence), "meaningful divergence ({$label}): {$ctx}");
            $this->assertGreaterThanOrEqual(3, count(array_unique($styles)), "at least three different registers ({$label}): {$ctx}");
            $this->assertSame('Ashe', array_search(max($silence), $silence, true), "the guarded NPC is the quietest ({$label}): {$ctx}");
            $this->assertSame('Lynly Star-Sung', array_search(min($silence), $silence, true), "the open bard is the most talkative ({$label}): {$ctx}");
            $this->assertSame('minimal', $styles['Ashe'], "Ashe says little ({$label})");
            $this->assertSame('sharp', $styles['Muiri'], "the toxic NPC is sharp ({$label})");
            $this->assertSame('vocal', $styles['Lynly Star-Sung'], "Lynly's read as it stands is open about it ({$label})");
            $this->assertGreaterThan($silence['Aela the Huntress'] + 0.1, $silence['Ashe'], "{$label}: Ashe is quieter than Aela");
            $this->assertGreaterThan(0.5, $silence['Ashe'], "{$label}: Ashe would not be vocal in bed");
        }
        $this->assertSame(0, $this->llmCalls);
        $this->assertNoDbFailures();
    }

    public function testACloserBondLoosensEveryTongueButNobodyIsMadeOver(): void
    {
        $mid = $this->allBeds();
        $deep = $this->deepBeds();
        foreach ($mid as $npc => $v) {
            $this->assertLessThan($v['silence_chance'] - 0.03, $deep[$npc]['silence_chance'], "{$npc}: closeness eases the quiet");
            $this->assertSame($v['style'], $deep[$npc]['style'], "{$npc}: and does not change who they are");
        }
        $this->assertGreaterThan(0.2, $deep['Ashe']['silence_chance'], 'a guarded NPC stays quiet even when close');
        $this->assertNoDbFailures();
    }

    public function testWhatWeighsOnAnNPCQuietsThemByWhoTheyAre(): void
    {
        $mid = $this->allBeds();
        foreach (['conflict', 'ick', 'withdrawn', 'pulled_back'] as $state) {
            $c = $this->allBeds($this->states()[$state]);
            $quiet = [];
            foreach ($c as $npc => $v) {
                $this->assertGreaterThan($mid[$npc]['silence_chance'] + 0.03, $v['silence_chance'], "{$npc}: {$state} quiets the NPC");
                $this->assertLessThanOrEqual(0.85, $v['silence_chance'], "{$npc}: {$state}: still never certain to stay silent");
                $quiet[$npc] = round($v['silence_chance'] - $mid[$npc]['silence_chance'], 3);
            }
            $this->assertGreaterThan(0.01, max($quiet) - min($quiet), "{$state} weighs differently on each of them: " . json_encode($quiet));
        }
        $this->assertNoDbFailures();
    }

    public function testAYesGivenInToIsQuieterThanAWholeHeartedOne(): void
    {
        // the people-pleaser version of a bed, in a quarrel, says yes against their own want (the consent lane's appeasement):
        // the vocal reading carries it as a quiet of its own, a whole-hearted yes carries none
        $pleaser = function (array &$d): void {
            $d['profile_overrides']['attachment_style'] = 'anxious';
            $this->states()['conflict']($d);
        };
        $pleased = $this->allBeds($pleaser, ['self_confidence' => 15.0, 'maturity' => 25.0]);
        $gaveIn = [];
        foreach (array_keys(self::BEDS) as $npc) {
            if (($this->consent($npc)['stance'] ?? '') !== 'appeasing') continue;
            $gaveIn[] = $npc;
            $r = RelDynVocal::decide($npc, $this->dynamics($npc), null, self::PLAYER);
            $this->assertEqualsWithDelta(RelDynVocal::configDefaults()['silence']['appeasing'], $r['parts']['stance'], 0.0001, "{$npc}: going along with it is quiet");
            $this->assertGreaterThan(0.3, $pleased[$npc]['silence_chance'], "{$npc}: and a quarrel besides");
        }
        $this->assertGreaterThanOrEqual(2, count($gaveIn), 'the fixture: some of them give in: ' . json_encode(array_map(fn($n) => $this->consent($n)['stance'] ?? null, array_keys(self::BEDS))));
        $this->assertNoDbFailures();
    }

    public function testAWholeHeartedYesAddsNoQuiet(): void
    {
        $this->deepBeds();
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertSame('willing', $this->consent($npc)['stance'], $npc);
            $r = RelDynVocal::decide($npc, $this->dynamics($npc), null, self::PLAYER);
            $this->assertEqualsWithDelta(0.0, $r['parts']['stance'], 0.0001, "{$npc}: a whole-hearted yes adds no quiet");
        }
        $this->assertNoDbFailures();
    }

    public function testTheShyMechanismOnAHandSetCopyOfTheBard(): void
    {
        // Lynly's own read stands as it is (the bio does not establish shyness): she is open about it. A hand-set shy copy is not.
        $this->deepRomance('Lynly Star-Sung');
        $this->turn('Lynly Star-Sung', 'Hm.');
        $own = RelDynVocal::decide('Lynly Star-Sung', $this->dynamics('Lynly Star-Sung'), null, self::PLAYER);
        $this->assertNotSame('minimal', $own['style'], "Lynly's read is not forced shy");
        $copy = $this->dynamics('Lynly Star-Sung');
        $copy['trait_vector'] = RelDynTraits::toStored(['G' => 0.85, 'E' => 0.2, 'C' => 0.25, 'Pd' => 0.4, 'Rs' => 0.5, 'L' => 0.4, 'W' => 0.55, 'D' => 0.7, 'Po' => 0.3, 'Pr' => 0.5, 'maturity_start' => 50.0]);
        $copy['_trait_vector_src'] = ['assignment' => 'read', 'auto_source' => 'hand-set'];
        $shy = RelDynVocal::decide('Lynly Star-Sung', $copy, null, self::PLAYER);
        $this->assertSame('minimal', $shy['style'], 'a hand-set shy copy says little');
        $this->assertGreaterThan($own['silence_chance'] + 0.3, $shy['silence_chance'], json_encode([$own['silence_chance'], $shy['silence_chance']]));
        $this->assertLessThanOrEqual(0.85, $shy['silence_chance']);
        $this->noPronounOrDigit($shy['felt'], 'the copy');
        $this->assertNoDbFailures();
    }

    public function testNobodyIsCertainEvenInTheWorstQuarrel(): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $v = $this->scene($npc, function (array &$d): void {
                $d['in_conflict'] = true;
                $d['_ick_tracker'] = ['ick_active' => true, 'quiet' => 0, 'romantic_count' => 3, 'total_count' => 3, 'counted_gamets' => []];
                $d['dimensions']['resentment']['x'] = 90.0;
                $d['_pullback'] = ['v' => RelDynPullback::VERSION, 'pressure' => 1.0, 'active' => true, 'since_gamets' => $this->gamets, 'gamets' => $this->gamets, 'say' => [], 'episodes' => 1];
            });
            $this->assertGreaterThan(0.0, $v['silence_chance'], $npc);
            $this->assertLessThanOrEqual(0.85, $v['silence_chance'], "{$npc}: at the ceiling at most, never silent for certain");
        }
        $this->assertNoDbFailures();
    }

    public function testRepublishingTheSameStateDoesNotWriteAgain(): void
    {
        $npc = 'Aela the Huntress';
        $this->turn($npc, 'Well met.');
        $first = $this->vocal($npc);
        $this->turn($npc, 'Hm.');
        $second = $this->vocal($npc);
        $this->assertSame($first['style'], $second['style']);
        $this->assertSame($first['gamets'], $second['gamets'], 'within the dead band nothing is written: the stamp stands');
        $this->assertNoDbFailures();
    }

    public function testTheSwitchOffPublishesOffOnceAndSharmatHasNothingToRead(): void
    {
        $npc = 'Ashe';
        $this->turn($npc, 'Well met.');
        $this->assertTrue($this->vocal($npc)['enabled']);
        $this->storeConfig(['vocal' => ['enabled' => false]]);
        $this->turn($npc, 'Hm.');
        $off = $this->vocal($npc);
        $keys = array_keys($off);
        sort($keys);
        $this->assertSame(['enabled', 'gamets', 'v'], $keys);
        $this->assertFalse($off['enabled']);
        $stamp = $off['gamets'];
        $this->turn($npc, 'Hm.');
        $this->assertSame($stamp, $this->vocal($npc)['gamets'], 'published once, not every request');
        $this->storeConfig([]);
        $this->turn($npc, 'Hm.');
        $this->assertTrue($this->vocal($npc)['enabled'], 'and back on');
        $this->assertNoDbFailures();
    }

    public function testTheVocalKeyDoesNotTouchTheConsentKeyOrTheOtherStores(): void
    {
        $npc = 'Aela the Huntress';
        $this->turn($npc, 'Well met.');
        $ped = $this->ped($npc)['reldyn'];
        $this->assertArrayHasKey('vocal', $ped);
        $this->assertArrayHasKey('consent', $ped, 'the consent decision is still published beside it');
        $this->assertArrayHasKey('dynamics', $ped);
        $this->assertNotSame($ped['vocal'], $ped['consent']);
        $this->assertNoDbFailures();
    }
}
