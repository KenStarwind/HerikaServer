<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynConcernBedsPgDb
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
 * Protective concern end to end with the four test beds (personality-traits design §1.1-1.6;
 * decisions §14 Ken's bar example; standing rule feedback_reldyn_testbeds): Aela the Huntress,
 * Ashe (Serene's hand-set vector), Muiri and Lynly Star-Sung, each the player's partner (core
 * Player.type romantic, affinity 60), all on their CHIM 3.4.1 core-shaped rows and the committed
 * seed's reads (RelDynTraitTestBedsPostgresTest has the vectors). They stay home at Breezehome;
 * the player spends the same nights at the Bannered Mare (an Inn in core's locations table,
 * strangers there, a mead at 23:30) and comes home at 01:00:
 *   route B  each NPC's prerequest on the player's return reads what she can perceive from the
 *            eventlog rows she was not in (late from the inn, the drink);
 *   route A  next morning the player says "I was at the Bannered Mare last night with some
 *            friends"; the real eval worker (LLM stubbed at the connector boundary with the
 *            contract's exposure field) scores it and applies it through the eval inbox.
 * Everything goes through the real hooks (prerequest -> context -> postrequest), the real
 * eval producer and worker, and the core relationship write. No LLM call.
 *
 * What the design says, and what the four do (their real vectors, not caricatures):
 *   - Aela (danger-loving: signed combat preference high): the tavern night registers as
 *     nothing (rough-place tolerance), so no worry, no count, no grievance; her low
 *     possessiveness (Po 0.24) feels a little jealousy about the suitors and never files it.
 *   - Ashe (maturity 75, mature): notices, states her values once and plainly, a reminder, then
 *     the values conflict and a calm §9 boundary; the pattern goes on through the probation and
 *     she steps back (romantic -> platonic), with no blow-up.
 *   - Muiri (maturity 52, the most reactive, accusation her style): the in-between band: she
 *     means to say it evenly and it comes out as an accusation; the most jealous of the four.
 *   - Lynly (maturity 51, the least protective of the three that worry): the in-between band
 *     too, her worry the smallest of the three.
 * Nobody's possessiveness reaches the 0.35 sensitivity line (Muiri 0.33 from her prior), so the
 * suitors are felt (jealousy, trust-damped) and never counted: the possessive values path is
 * RelDynConcernTest's (design walkthrough, Po 0.5).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynConcernTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the first night out
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const MARE_PEOPLE = '|Hulda|Mikael|Jon Battle-Born|Olfina Gray-Mane|Ysolda|Kaida|';
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';
    private const MARE = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 9:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynConcernBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => key => felt text the context hook put in front of the LLM, per turn label */
    private array $felt = [];
    /** npc => the <subtext> blocks the LLM got */
    private array $subtext = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_concern' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        // Columns of lib/core/database_schema/core_npc_master.sql and its history table
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
        // data/database_default.sql eventlog
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // core 3.4.1 locations (debug/db_updates.php columns)
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

        $this->db = new RelDynConcernBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdconcernbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_concern_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        // Shipped defaults, stored as the config page stores them
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
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

    /** Core rows (partners: Player romantic, affinity 60), voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic']]])]);
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
        // Core's locations rows: the Bannered Mare is an Inn, Breezehome a player house
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld'), ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, string $people, ?string $state = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people, '', $state]);
    }

    private function home(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /** One player line to $npc through the real hooks at $gamets, logged as core logs it (input row, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->home(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                foreach ((array) $GLOBALS['contextDataFull'] as $m) $this->subtext[$npc][] = (string) ($m['content'] ?? '');
            }
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    /** The eval LLM at the connector boundary: the Bannered Mare line carries the exposure field. */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $told = str_contains($exchange, 'Bannered Mare last night');
            return json_encode([
                'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => [], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => $told ? ['flag' => true, 'kinds' => ['rival_exposure', 'place', 'vice'], 'intensity' => 2, 'when' => 'last_night']
                                    : ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $told ? 0.4 : 0.1, 'summary' => $told ? 'The player told her about a night at the tavern.' : 'Small talk.',
            ]);
        };
    }

    /** The same night for all four: they stay home, the player is at the Mare 21:00-00:30 and has a mead at 23:30. */
    private function nightAtTheMare(int $day): void
    {
        $this->event('infoloc', self::HOME, self::at($day, 20.5), $this->home());
        foreach ([21.0, 22.5, 0.5 + 24] as $h) $this->event('infoloc', self::MARE, self::at($day, $h), self::MARE_PEOPLE);
        $this->event('itemfound', self::PLAYER . ' drank Nord Mead', self::at($day, 23.5), self::MARE_PEOPLE);
        $this->event('infoloc', self::HOME, self::at($day + 1, 0.9), $this->home());
    }

    /** Home at 01:00 (route B), then next morning "at the Mare last night with some friends" (route A, the real eval worker). */
    private function night(int $n, int $day): void
    {
        $this->nightAtTheMare($day);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, "I'm home.", self::at($day + 1, 1.0) + 600 * $i++, "return{$n}");
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'I was at the Bannered Mare last night with some friends.', self::at($day + 1, 9.0) + 600 * $i++, "told{$n}");
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function coreType(string $npc): string
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $rel = RelationshipDynamics::getPlayerRelationshipFromExtended(json_decode($r['extended_data'], true) ?: []);
        return (string) ($rel['type'] ?? '');
    }

    /** The values-conflict grievances (other grievances, such as unfulfilled neglect, are not this lane's). */
    private static function grievances(array $d): array
    {
        return array_values(array_filter(array_map(fn($g) => $g['kind'] ?? null, (array) ($d['dimensions']['resentment']['grievance_log'] ?? [])),
            fn($k) => is_string($k) && str_starts_with($k, 'values_conflict:')));
    }

    public function testTheSameBarNightsDivergeAcrossTheFourTestBeds(): void
    {
        // Evening: everyone at home with the player
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met, love.', self::at(self::N0, 18.0) + 600 * $i++, 'hello');
        $d = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d[$npc] = $this->dynamics($npc);
            $this->assertSame('read', $d[$npc]['_trait_vector_src']['assignment'] ?? null, "{$npc}: her own vector");
            $this->assertSame('romantic', $d[$npc]['_core_rel_type'], "{$npc}: a partner");
        }
        $x = array_map(fn($dd) => RelDynConcern::traitsOf($dd), $d);
        $expr = array_map(fn($npc) => RelDynConcern::expression($d[$npc], $x[$npc]), array_combine(array_keys($d), array_keys($d)));
        $combat = array_map(fn($npc) => RelDynFacets::preferences($d[$npc], $npc)['combat'], array_combine(array_keys($d), array_keys($d)));

        // Who they are, for the concern (their real vectors)
        $this->assertSame('mature', $expr['Ashe']['band'], 'Ashe: maturity 75');
        $this->assertSame('mixed', $expr['Muiri']['band']);
        $this->assertSame('mixed', $expr['Lynly Star-Sung']['band']);
        $this->assertSame('accusation', $expr['Muiri']['style'], 'Muiri: the most reactive; blame is how it comes out');
        $this->assertGreaterThan(0.4, $combat['Aela the Huntress'], 'Aela loves a fight');
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) $this->assertLessThan(0.2, $combat[$npc], "{$npc}: no fighter's tolerance");
        foreach ($x as $npc => $t) {
            $this->assertLessThan(0.35, $t['Po'], "{$npc}: possessiveness below the sensitivity line");
            $this->assertGreaterThanOrEqual(0.35, $t['Pr'], "{$npc}: protective enough to count");
        }

        // ---- Night 1
        $this->night(1, self::N0);
        foreach (array_keys(self::BEDS) as $npc) $d[$npc] = $this->dynamics($npc);
        $now = self::at(self::N0 + 1, 10.0);

        // Aela: the tavern night barely registers: nothing noticed, nothing counted, no worry
        $aela = $d['Aela the Huntress'];
        $this->assertSame(0.0, RelDynConcern::level($aela), 'Aela: no worry');
        $this->assertSame(0, RelDynConcern::count($aela, RelDynConcern::PROTECTIVE, $now));
        $this->assertSame([], array_filter(array_keys($this->felt['Aela the Huntress']['return1']), fn($k) => str_starts_with($k, 'concern_')));
        $this->assertGreaterThan(0.0, $aela['jealousy_anger'], 'the suitors: a little jealousy');

        // The other three noticed on the return (route B) and counted one night
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $f = $this->felt[$npc]['return1'];
            $this->assertArrayHasKey('concern_noticed', $f, "{$npc}: route B");
            $this->assertStringContainsString('the drink on Kaida', $f['concern_noticed']);
            $this->assertStringContainsString('coming in late from the tavern', $f['concern_noticed']);
            $this->assertSame(1, RelDynConcern::count($d[$npc], RelDynConcern::PROTECTIVE, $now), "{$npc}: one night, however many routes");
            $this->assertSame(['B', 'A'], $d[$npc]['_concern']['incidents'][0]['routes'], "{$npc}: B at 01:00, A next morning, one incident");
            $this->assertSame(0, RelDynConcern::count($d[$npc], RelDynConcern::POSSESSIVE, $now), "{$npc}: the suitors felt, not filed");
            $this->assertGreaterThan(0.0, RelDynConcern::level($d[$npc]));
            $this->assertLessThan(25.0, RelDynConcern::level($d[$npc]), "{$npc}: one night is tolerated");
        }
        // Expression diverges by maturity and style
        $this->assertStringContainsString('once, plainly and without blame', $this->felt['Ashe']['return1']['concern_stated_mature']);
        $this->assertStringContainsString('means to say it evenly', $this->felt['Muiri']['return1']['concern_stated_mixed']);
        $this->assertStringContainsString('as an accusation', $this->felt['Muiri']['return1']['concern_stated_mixed']);
        $this->assertArrayHasKey('concern_stated_mixed', $this->felt['Lynly Star-Sung']['return1']);
        $this->assertArrayNotHasKey('concern_stated_mixed', $this->felt['Ashe']['return1']);
        // How much: Lynly (the least protective of the three) worries least
        $lv = array_map(fn($dd) => RelDynConcern::level($dd), $d);
        $this->assertLessThan(min($lv['Ashe'], $lv['Muiri']), $lv['Lynly Star-Sung']);
        // Jealousy: trust-damped for all; Muiri, the most jealousy-prone, feels it most
        $j = array_map(fn($dd) => floatval($dd['jealousy_anger']), $d);
        arsort($j);
        $this->assertSame('Muiri', array_key_first($j), 'Muiri: the most jealous about the suitors');
        foreach ($d as $npc => $dd) $this->assertLessThan(1.0, RelationshipDynamics::jealousyTrustFactor($dd), "{$npc}: trust damps it");

        // ---- Nights 2 and 3 (two nights apart): a reminder, then the values conflict
        $this->night(2, self::N0 + 2);
        $this->night(3, self::N0 + 4);
        foreach (array_keys(self::BEDS) as $npc) $d[$npc] = $this->dynamics($npc);
        $this->assertSame([], self::grievances($d['Aela the Huntress']), 'Aela: no values conflict over a mead hall');
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertArrayHasKey('concern_reminder_protective', $this->felt[$npc]['return2'], "{$npc}: night 2, a reminder");
            $this->assertSame(['values_conflict:place'], self::grievances($d[$npc]), "{$npc}: the third night in the week");
            $this->assertArrayHasKey('concern_boundary', $this->felt[$npc]['return3'], "{$npc}: the calm boundary, said on the return");
            $this->assertSame('probation', $d[$npc]['_concern']['boundary']['state']);
            $this->assertArrayNotHasKey('concern_blowup', $this->felt[$npc]['return3']);
        }
        $values = array_values(array_filter($d['Ashe']['dimensions']['resentment']['grievance_log'], fn($g) => ($g['kind'] ?? '') === 'values_conflict:place'));
        $this->assertEqualsWithDelta(5.0, $values[0]['raw'], 1e-9, 'MDD 15.5 +5: not amplified');

        // ---- Night 4, inside the probation: the pattern goes on -> a deliberate step back
        $this->night(4, self::N0 + 6);
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $this->assertSame('platonic', $this->coreType($npc), "{$npc}: stepped back from romance on the return");
            $this->assertStringContainsString('stepping back from a romance to friendship', $this->felt[$npc]['return4']['concern_step_back'] ?? '');
        }
        $this->assertSame('romantic', $this->coreType('Aela the Huntress'), 'Aela: nothing to step back from');

        // Feelings, never numbers, in front of the LLM; Jev gets the numbers
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) {
                    if (str_starts_with($key, 'concern_')) $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$npc} {$label} {$key}");
                }
            }
        }
        $jev = RelationshipDynamics::jevStateBlock('Ashe');
        $this->assertArrayHasKey('concern', $jev);
        $this->assertStringContainsString('concern=', $jev['text']);
        $this->assertSame(0, $this->llmCalls, 'no trait read');
        $this->assertGreaterThan(0, $this->evalCalls, 'the eval worker scored the mornings (stubbed)');
        $this->assertSame([], $this->db->failures, 'no failed statement');
    }
}
