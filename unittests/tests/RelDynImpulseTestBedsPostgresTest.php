<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynImpulsePgDb
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
 * impulse-short-band + motivation-inner-conflict (MDD 13-14) end to end with the four test beds
 * (standing rule): Aela the Huntress, Ashe (Serene's hand-set vector, never read: nothing of her
 * story anywhere; her motivation here is a bare editor-set purpose), Muiri (toxic, fearful-nudged)
 * and Lynly Star-Sung (the shy bard), on CHIM 3.4.1 core-shaped rows, through the real hooks
 * (prerequest -> core's action-list hook -> context_pre -> context -> postrequest), the real eval
 * worker (LLM stubbed at the connector boundary) and core's own eventlog / locations / metadata rows.
 * No LLM call; feelings, never numbers, in front of the LLM; Jev gets the numbers.
 *
 *   the same moment alone    the same passion, alone with the player: each fires (or only stirs) at
 *                            her own trait threshold and shows it in her own style; the one whose
 *                            motivation pulls elsewhere gets an <inner_conflict> resolved her way
 *   the player falls         the player's bleedout in a fight: everyone wants to shield him, each
 *                            as much as her protectiveness says, and in her own way
 *   a ruin none has seen     a Dwarven ruin new to all four: the scholar is drawn in, the huntress
 *                            goes to look against her revenge, the bard half-wants to, the
 *                            apothecary barely glances
 *   days apart               three game days after a meaningful evening: who missed him (the
 *                            loneliness timer, by warmth), and how a new meaningful exchange meets it
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynImpulseTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;
    private const AELA = 'Aela the Huntress';
    private const LYNLY = 'Lynly Star-Sung';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    /** Template 'goals' text (the live bios are not committed): Aela's is the test's own backstory sentence. */
    private const GOALS_TEXT = [
        'Aela the Huntress' => 'Placeholder goals text. She swore to hunt down the Silver Hand.',
        'Ashe'              => 'Placeholder goals text.',
        'Muiri'             => 'Placeholder goals text.',
        'Lynly Star-Sung'   => 'Placeholder goals text.',
    ];
    private const CORE_ACTIONS = ['MoveTo', 'OpenInventory', 'Attack', 'Follow', 'Inspect', 'TravelTo', 'FollowPlayer', 'EndConversation'];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';
    private const RUIN = '(Context location: Mzinchaleft ,Hold: The Reach, Buildings to go:, Current Date in Skyrim World: Morndas, 11:00 AM, 18th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynImpulsePgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    /** npc => label => felt text key => text */
    private array $felt = [];
    /** npc => label => the <subtext> block context.php appended */
    private array $subtext = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_imp_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // core's speech table (data/database_default.sql): prompt gating reads who has talked with the player
        pg_query($admin, "CREATE TABLE speech (sess varchar(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY,
            companions text, audios text, utterance_id text)");
        // data/database_default.sql eventlog
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        pg_query($admin, "CREATE TABLE locations (name text, formid bigint, region text, hold text, tags text,
            factions text, is_interior integer, vanilla_location boolean, coords point, refs text, cleared boolean,
            updated_at timestamp, world text, chim_added integer)");
        pg_query($admin, "CREATE TABLE moods_issued (speaker text, mood text, localts bigint)");
        pg_query($admin, "CREATE TABLE core_player (id text PRIMARY KEY, value text)");
        // core 3.4.1 quests / questlog (live dwemer \d, 2026-09-25)
        pg_query($admin, "CREATE TABLE quests (ts text NOT NULL, sess varchar(1024), id_quest varchar(1024) NOT NULL,
            name text, editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text,
            stage integer, briefing text, briefing2 text, localts bigint NOT NULL, gamets bigint NOT NULL, data text,
            status text, rowid bigserial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE questlog (ts text, sess varchar(1024), id_quest varchar(1024), name text,
            editor_id text, giver_actor_id text, reward text, target_id text, is_unique boolean, mod text, stage integer,
            briefing text, briefing2 text, localts bigint, gamets bigint, data text, status text, rowid serial PRIMARY KEY)");
        pg_query($admin, "CREATE TABLE oghma (topic character varying NOT NULL, topic_desc character varying,
            knowledge_class text, topic_desc_basic text, knowledge_class_basic text, tags text, category text, aliases text,
            retrieval_phrases text, source_type text)");
        pg_query($admin, "CREATE TABLE combined_bio_templates (npc_name varchar, oghma_knowledge_tags text, core text,
            npc_static_bio text, appearance text, personality text, relationships text, occupation text, skills text,
            speechstyle text, goals text, voiceid text, gender text, race text, refid text, tts_filter_preset text)");
        pg_query($admin, "CREATE TABLE npc_templates_v2 (npc_name varchar, npc_pers text, npc_misc text,
            melotts_voiceid varchar, xtts_voiceid varchar, xvasynth_voiceid varchar)");
        pg_close($admin);

        $this->db = new RelDynImpulsePgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS', 'ENABLED_FUNCTIONS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdimp');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_impulse_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
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

    /** Core rows (partners: Player romantic, affinity 60), voice types, templates and the committed seed's reads. */
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
            $ext = ['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                'relationships' => [self::PLAYER => ['aff' => 60, 'type' => 'romantic']]];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]), json_encode($ext)]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            $fields['goals'] = self::GOALS_TEXT[$name];
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
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld'),
            ('Mzinchaleft', 'The Reach', 'Dungeon,Dwarven Ruin,', 1, 'Tamriel')");
    }

    private static function at(int $day, float $hour): int
    {
        return (int) round($day * self::DAY + $hour * self::HOUR);
    }

    private function event(string $type, string $data, int $gamets, ?string $state = null, ?string $people = null): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
            VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)', [$type, $data, 'pending', $gamets, $this->realTs, $gamets, $people ?? $this->everyone(), '', $state]);
    }

    private function everyone(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /**
     * One player line to $npc through the real hooks at $gamets, as main.php runs them. $people:
     * core's CACHE_PEOPLE for the turn (default: all four and the player).
     */
    private function turn(string $npc, string $line, int $gamets, string $label, ?string $people = null): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, null, $people);
        foreach (['prerequest.php', 'functions.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, 'emitted', $people);
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $people ?? $this->everyone();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            if ($hook === 'functions.php') $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_ACTIONS;
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') {
                $this->felt[$npc][$label] = RelDynFelt::lastRendered();
                $this->subtext[$npc][$label] = '';
                foreach ((array) $GLOBALS['contextDataFull'] as $m) {
                    if (str_starts_with((string) ($m['content'] ?? ''), '<subtext>')) $this->subtext[$npc][$label] = (string) $m['content'];
                }
            }
            if ($hook !== 'functions.php') RelationshipDynamics::endRequest();
        }
        unset($GLOBALS['ENABLED_FUNCTIONS']);
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** Edit $npc's stored RelDyn state (as the NPC editor would). */
    private function editDynamics(string $npc, callable $edit): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $edit($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1', [$npc, json_encode($ped)]);
    }

    /** Evening at home: everyone with the player. */
    private function hello(string $label = 'hello'): void
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 17.9));
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met.', self::at(self::N0, 18.0) + 600 * $i++, $label);
    }

    /** The eval LLM at the connector boundary: "about your day" is meaningful quality time, anything else small talk. */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $together = str_contains($exchange, 'about your day');
            return json_encode([
                'signals' => ['affinity' => $together ? 2 : 0, 'trust' => $together ? 3 : 0, 'comfort' => $together ? 3 : 0,
                              'respect' => $together ? 1 : 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $together ? ['quality_time'] : [],
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $together ? 0.6 : 0.1,
                'summary' => $together ? 'A quiet evening together.' : 'Small talk.',
                'romantic_intent' => 0,
            ]);
        };
    }

    /** Every bed's passion toward the player set to $p (as the editor would; the floor too, so decay keeps it). */
    private function passion(float $p): void
    {
        foreach (array_keys(self::BEDS) as $npc) {
            $this->editDynamics($npc, function (array &$d) use ($p): void {
                $d['passion'] = $p;
                $d['dimensions']['passion']['x'] = $p;
            });
        }
    }

    /** No digit in anything the felt steering put in front of the LLM. */
    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
    }

    private function impulse(string $npc): array
    {
        return RelationshipDynamics::jevStateBlock($npc)['impulse'] ?? [];
    }

    /** Ashe's motivation for these tests: a bare purpose the editor sets (nothing of her story in it). */
    private function ashePurpose(): void
    {
        $this->editDynamics('Ashe', function (array &$d): void {
            RelDynGoals::form($d, 'purpose', 0.7, 'editor', self::at(self::N0, 18.5));
        });
    }

    /** RELDYN_PROBE=1: print each bed's impulse state after a scene (tuning aid, no effect on the test). */
    private function dump(string $label): void
    {
        if (!getenv('RELDYN_PROBE')) return;
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $d = $this->dynamics($npc);
            $out[$npc] = ['impulse' => $this->impulse($npc), 'passion' => RelationshipDynamics::getPassion($d),
                'goals' => array_map(fn($g) => [$g['type'], $g['priority']], RelDynGoals::active($d)),
                'felt' => $this->felt[$npc][$label] ?? null];
        }
        fwrite(STDERR, "\n=== {$label}\n" . json_encode($out, JSON_PRETTY_PRINT));
    }

    /** Every test ends the same way: feelings in front of the LLM, no LLM call, no failed query. */
    private function assertClean(): void
    {
        $this->assertFeelingsNotNumbers();
        foreach ($this->subtext as $npc => $turns) {
            foreach ($turns as $label => $block) $this->assertDoesNotMatchRegularExpression('/\d/', $block, "{$npc} {$label} <subtext>");
        }
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

    // ------------------------------------------------------------------ the same moment

    /**
     * The same passion, first among everyone at home, then alone with the player. In company it
     * only stirs (privacy) and not at all in Ashe, whose guard sets the highest bar. Alone it
     * fires in all four, each at her own threshold and in her own way: Aela's Silver Hand pulls
     * against it and the urge wins (bold); Ashe's purpose holds it down and it leaks (stoic);
     * Lynly is set on a director goal and the urge wins; Muiri has nothing pulling against it.
     */
    public function testTheSameMomentAloneIsFeltFourWays(): void
    {
        $this->seed();
        $this->hello();
        $this->ashePurpose();
        $this->editDynamics(self::LYNLY, function (array &$d): void {
            RelationshipDynamics::setDirectorGoal($d, 'Rehearse the new ballad before the feast.', 'director', null, 0.8);
        });
        $this->passion(70.0);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Quite a crowd tonight.', self::at(self::N0, 20.0) + 600 * $i++, 'crowd');
        $this->dump('crowd');
        $thresholds = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $im = $this->impulse($npc);
            $thresholds[$npc] = $im['threshold'];
            $this->assertNull($im['top'], "{$npc}: in company nothing fires " . json_encode($im));
            $this->assertArrayNotHasKey('impulse', $this->felt[$npc]['crowd']);
            $this->assertArrayNotHasKey('inner_conflict', $this->felt[$npc]['crowd']);
        }
        foreach ([self::AELA, 'Muiri', self::LYNLY] as $npc) {
            $this->assertStringContainsString('half-wants to be close to Kaida', $this->felt[$npc]['crowd']['impulse_stirring'] ?? '', $npc);
        }
        $this->assertArrayNotHasKey('impulse_stirring', $this->felt['Ashe']['crowd'], 'her guard: not even a stir in company');
        // Thresholds by guard and confidence (traits design §2.5): Ashe's is the highest, Lynly's the lowest
        $this->assertGreaterThan($thresholds[self::AELA], $thresholds['Ashe'], json_encode($thresholds));
        $this->assertGreaterThan($thresholds[self::LYNLY], $thresholds[self::AELA], json_encode($thresholds));

        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Just us tonight.', self::at(self::N0, 21.0) + 600 * $i++, 'alone', "|{$npc}|" . self::PLAYER . '|');
        }
        $this->dump('alone');
        $im = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $im[$npc] = $this->impulse($npc);
            $this->assertSame('romantic', $im[$npc]['top'], "{$npc}: alone, it fires " . json_encode($im[$npc]));
            $this->assertSame('passion', $im[$npc]['source']);
        }
        $this->assertSame('stoic', $im['Ashe']['style'], "Ashe's hand-set vector: Stoic-leaning");
        $this->assertSame('bold', $im[self::AELA]['style']);
        $this->assertSame(['impulse' => 'romantic', 'motivation' => 'revenge', 'weight' => 0.8, 'alignment' => -1.0, 'resolution' => 'impulse'],
            $im[self::AELA]['conflict']);
        $this->assertSame('motivation', $im['Ashe']['conflict']['resolution'] ?? null, json_encode($im['Ashe']));
        $this->assertSame('purpose', $im['Ashe']['conflict']['motivation']);
        $this->assertSame('director', $im[self::LYNLY]['conflict']['motivation'] ?? null, json_encode($im[self::LYNLY]));
        $this->assertSame('impulse', $im[self::LYNLY]['conflict']['resolution']);
        $this->assertNull($im['Muiri']['conflict'], 'getting better at alchemy does not pull against it');

        // What the LLM gets: the conflict as its own block inside <subtext>, in words
        $aela = $this->subtext[self::AELA]['alone'];
        $this->assertMatchesRegularExpression("/<inner_conflict>\nImpulse: (a pull|a strong urge|an overwhelming urge) to be close to Kaida\n"
            . "Motivation: an old score to settle tied to Silver Hand\nTemperament: the urge wins for now: Aela the Huntress acts first[^\n]*\n<\\/inner_conflict>\n<\\/subtext>$/", $aela);
        $this->assertArrayNotHasKey('intrinsic_goal', $this->felt[self::AELA]['alone'], 'the conflict speaks for her revenge: one voice');
        $this->assertArrayHasKey('intrinsic_goal', $this->felt[self::AELA]['crowd'], 'in company her revenge speaks for itself');
        $this->assertStringContainsString("Motivation: a purpose of Ashe's own\nTemperament: Ashe stays the course and holds the urge down, but it leaks through",
            $this->felt['Ashe']['alone']['inner_conflict'] ?? '');
        $this->assertStringContainsString('Motivation: what Lynly Star-Sung is set on right now: Rehearse the new ballad before the feast',
            $this->felt[self::LYNLY]['alone']['inner_conflict'] ?? '');
        $this->assertArrayNotHasKey('goal', $this->felt[self::LYNLY]['alone'], 'the conflict speaks for the director goal');
        $this->assertArrayHasKey('goal', $this->felt[self::LYNLY]['crowd']);
        $this->assertStringContainsString('acts on it plainly', $this->felt['Muiri']['alone']['impulse'] ?? '', json_encode($this->felt['Muiri']['alone']));
        $this->assertStringNotContainsString('<inner_conflict>', $this->subtext['Muiri']['alone']);

        // Jev gets the numbers
        $jev = RelationshipDynamics::jevStateBlock(self::AELA);
        $this->assertGreaterThanOrEqual($jev['impulse']['threshold'], $jev['impulse']['levels']['romantic']);
        $this->assertSame([['type' => 'revenge', 'weight' => 0.8], ['type' => 'mastery', 'weight' => 0.5]], $jev['impulse']['motivations']);
        $this->assertStringContainsString('inner_conflict=romantic vs revenge(0.80) -> impulse', $jev['text']);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ the player falls

    /**
     * The player falls in a fight: all four want to shield him at once (a bleedout is the
     * strongest protective source), each in her own way; Muiri, badly hurt herself, also wants
     * out. Hours later, with the player still hurt, how much each wants to stand between him and
     * harm follows her protectiveness: Aela first, Lynly last.
     */
    public function testThePlayerFallsAndEachWantsToShieldHim(): void
    {
        $this->seed();
        $this->hello();
        $this->ashePurpose();
        pg_query_params($this->db->link, "UPDATE core_npc_master SET metadata = metadata || $2::jsonb WHERE npc_name = $1",
            ['Muiri', json_encode(['stats' => ['health' => 20, 'health_max' => 100]])]);
        $t = self::at(self::N0 + 1, 12.0);
        $this->event('combatend', 'The Narrator: combat', $t - 5000);
        $this->event('infoaction', 'Muiri shouts during combat', $t - 400);
        $this->event('bleedout', self::PLAYER . ' falls to the ground', $t - 300);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Help me...', $t + 20 * $i++, 'fall');
        $this->dump('fall');
        foreach (array_keys(self::BEDS) as $npc) {
            $im = $this->impulse($npc);
            $this->assertSame('protective', $im['top'], "{$npc} " . json_encode($im));
            $this->assertSame('player_bleedout', $im['source']);
            $this->assertNull($im['conflict'], "{$npc}: no motivation of hers pulls against shielding him");
            $this->assertStringContainsString('stand between Kaida and harm', $this->felt[$npc]['fall']['impulse'] ?? '', $npc);
        }
        $this->assertStringContainsString('one loaded sentence', $this->felt['Ashe']['fall']['impulse']);
        $this->assertStringContainsString('acts on it plainly', $this->felt[self::AELA]['fall']['impulse']);
        $muiri = $this->impulse('Muiri');
        $this->assertSame(['protective', 'survival'], $muiri['firing'], 'badly hurt herself, she wants out too');
        $this->assertStringContainsString('also=survival', RelationshipDynamics::jevStateBlock('Muiri')['text']);

        // Hours later, out of the fight: the player is still badly hurt (core's stats report)
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode(['health' => 20, 'health_max' => 100])]);
        $t2 = self::at(self::N0 + 1, 16.0);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'I will live.', $t2 + 600 * $i++, 'hurt');
        $this->dump('hurt');
        $level = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $im = $this->impulse($npc);
            $this->assertSame('player_injured', $im['source'], "{$npc} " . json_encode($im));
            $level[$npc] = $im['levels']['protective'];
        }
        $this->assertTrue($level[self::AELA] > $level['Ashe'] && $level['Ashe'] > $level['Muiri'] && $level['Muiri'] > $level[self::LYNLY],
            'by protectiveness ' . json_encode($level));
        $this->assertLessThan(20.0, $this->impulse('Muiri')['levels']['survival'], 'out of the fight her own fear has ebbed');
        $this->assertClean();
    }

    // ------------------------------------------------------------------ a ruin none has seen

    /**
     * A Dwarven ruin new to all four: the scholar is drawn in (and her purpose agrees), the
     * huntress wants a look too, against her revenge, and goes; the bard half-wants to look and
     * the apothecary barely glances. Coming back the next day, nothing is new.
     */
    public function testARuinNoneOfThemHasSeen(): void
    {
        $this->seed();
        $this->hello();
        $this->ashePurpose();
        $t = self::at(self::N0 + 1, 11.0);
        $this->event('infoloc', self::RUIN, $t - 100);
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Dwemer work. Look at this place.', $t + 600 * $i++, 'ruin');
        $this->dump('ruin');
        $c = [];
        foreach (array_keys(self::BEDS) as $npc) $c[$npc] = $this->impulse($npc)['levels']['curiosity'];
        $this->assertTrue($c['Ashe'] > $c[self::AELA] && $c[self::AELA] > $c[self::LYNLY] && $c[self::LYNLY] > $c['Muiri'], json_encode($c));
        $ashe = $this->impulse('Ashe');
        $this->assertSame('curiosity', $ashe['top']);
        $this->assertNull($ashe['conflict'], 'her purpose draws her the same way');
        $this->assertStringContainsString('look around this place', $this->felt['Ashe']['ruin']['impulse'] ?? '');
        $this->assertSame('revenge', $this->impulse(self::AELA)['conflict']['motivation'] ?? null);
        $this->assertStringContainsString('Impulse: a pull to go and look around this place', $this->felt[self::AELA]['ruin']['inner_conflict'] ?? '');
        $this->assertStringContainsString('half-wants to go and look around', $this->felt[self::LYNLY]['ruin']['impulse_stirring'] ?? '');
        $this->assertNull($this->impulse('Muiri')['top']);
        $this->assertArrayHasKey('mzinchaleft', $this->dynamics('Ashe')[RelDynImpulse::KEY]['seen']);

        // The next day, back in the same ruin: nothing new about it
        $t2 = self::at(self::N0 + 2, 11.0);
        $this->event('infoloc', self::RUIN, $t2 - 100);
        $this->turn('Ashe', 'Back again.', $t2, 'again');
        $this->assertSame(0.0, $this->impulse('Ashe')['levels']['curiosity'] ?? null);
        $this->assertClean();
    }

    // ------------------------------------------------------------------ days apart

    /**
     * A meaningful evening, then three game days apart: each missed him as much as her warmth
     * says (Muiri most, Ashe least); Aela's revenge and Ashe's purpose pull against it, and each
     * resolves it her own way. Another meaningful exchange meets it.
     */
    public function testDaysApartEachMissedHimHerWay(): void
    {
        $this->seed();
        $this->hello();
        $this->ashePurpose();
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) {
            $this->turn($npc, 'Tell me about your day.', self::at(self::N0, 19.0) + 600 * $i++, 'evening');
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertGreaterThan(0.0, floatval($this->dynamics($npc)[RelDynImpulse::KEY]['last_meaningful_gamets'] ?? 0), "{$npc}: the evening counted");
        }
        $back = self::N0 + 3;
        $this->event('infoloc', self::HOME, self::at($back, 17.9));
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'I am back.', self::at($back, 18.0) + 600 * $i++, 'back');
        $this->dump('back');
        $s = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $im = $this->impulse($npc);
            $this->assertSame('social', $im['top'], "{$npc} " . json_encode($im));
            $this->assertSame('lonely', $im['source']);
            $s[$npc] = $im['levels']['social'];
        }
        $this->assertTrue($s['Muiri'] > $s[self::LYNLY] && $s[self::LYNLY] > $s[self::AELA] && $s[self::AELA] > $s['Ashe'], 'by warmth ' . json_encode($s));
        $this->assertSame('impulse', $this->impulse(self::AELA)['conflict']['resolution'] ?? null);
        $this->assertSame('motivation', $this->impulse('Ashe')['conflict']['resolution'] ?? null);
        $this->assertStringContainsString('talk with Kaida and share something', $this->felt['Muiri']['back']['impulse'] ?? '');

        // A meaningful exchange meets it
        $this->turn(self::LYNLY, 'Tell me about your day.', self::at($back, 18.5), 'talk');
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));
        $this->turn(self::LYNLY, 'Good night.', self::at($back, 19.0), 'after');
        $this->assertSame(0.0, $this->impulse(self::LYNLY)['levels']['social'], json_encode($this->impulse(self::LYNLY)));
        $this->assertArrayNotHasKey('impulse', $this->felt[self::LYNLY]['after']);
        $this->assertClean();
    }
}
