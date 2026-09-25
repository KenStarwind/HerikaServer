<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynTestBedsNightPgDb
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
 * reldyn-v0.13 end to end: the four test beds (standing rule feedback_reldyn_testbeds: Aela the
 * Huntress, Ashe with Serene's hand-set vector, Muiri, Lynly Star-Sung), each the player's
 * partner, over several game days with two different players: a seasoned warrior and Companion,
 * and a silver-tongued bard. Everything runs through the real hooks (prerequest -> context ->
 * postrequest), the real eval producer and worker (the LLM stubbed at the connector boundary,
 * no LLM call), the core relationship write, core's request poll, and CHIM 3.4.1 core-shaped rows
 * on a real PostgreSQL. The committed seed's bio reads give Aela, Muiri and Lynly their vectors.
 *
 *   day 0     evening at Breezehome: hello
 *   days 1-3  time together at home: words of praise, evenings by the fire (eval praise /
 *             quality_time with a passion signal)
 *   day 4     hello at 18:00; the player spends the night at the Bannered Mare (an Inn, strangers
 *             there, a mead at 23:30) while they stay home; core polls the server all night, with
 *             conf.php's default HERIKA_NAME set to Ashe; home at 01:00 (route B)
 *   day 5     09:00 "I was at the Bannered Mare last night with some friends" (route A, the eval
 *             exposure field); 15:00 a reassurance
 *
 * What the design promises and this asserts (all four, both players):
 *   floors      each NPC's attraction floor is her own standards (decisions §15, design §5.1):
 *               the same whoever the player is, Ashe (72) > Aela > Muiri > Lynly;
 *   passion     who she is drawn to (rulings §9, §13): Aela's passion climbs for the warrior and
 *               barely moves for the bard ("a bard she can tolerate but feels no passion for");
 *               Ashe's rigid bar holds against both; attraction is about the player, the floor is not;
 *   concern vs jealousy  (decisions §14) the suitors at the inn are felt as jealousy (possessive,
 *               trust-damped, never counted: nobody's Po reaches the line); the night itself is
 *               worry (protective), through who each is: Aela, the most protective, worries most
 *               (a taste for a fight is no tolerance for a tavern night); Lynly, the Vilemyr Inn's
 *               bard, is at home in an inn and does not worry at all; Aela, Ashe and Muiri notice
 *               on the return and count the night once, however they learn of it;
 *   expression  by maturity (§14, §9): Aela and Ashe (mature) state it once, plainly and without
 *               blame; Muiri (in-between) means to say it evenly and it comes out as an
 *               accusation; reassurance eases it; felt text never carries numbers, Jev does;
 *   the poll    core's once-a-second poll touches no bond (prerequest-on-poll), so an NPC named as
 *               the server default still has her night apart and notices the return.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynTestBedsNightTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const D0 = 200;   // game day of the first evening
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
    private const FIRE = 'Stay by the fire with me a while.';
    private const PRAISE = 'You were something to see today.';
    private const TOLD = 'I was at the Bannered Mare last night with some friends.';
    private const REASSURE = "I'll be careful. I'm here now.";

    private string $dsn;
    /** @var string[] schemas this test made (one per player) */
    private array $schemas = [];
    private ?RelDynTestBedsNightPgDb $db = null;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => turn label => felt lines (key => text) the context hook put in front of the LLM */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbedsnight');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_testbeds_night_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        if (!isset($this->dsn)) return;
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        $this->clearReldynGlobals();
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        Logger::unsetCustomLog();
        if ($this->db !== null) pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        foreach ($this->schemas as $schema) pg_query($admin, "DROP SCHEMA {$schema} CASCADE");
        pg_close($admin);
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    // ------------------------------------------------------------------ fixture

    /** A fresh schema with the CHIM 3.4.1 tables RelDyn touches, the shipped config and the four test beds. */
    private function world(string $build): void
    {
        if ($this->db !== null) {
            pg_close($this->db->link);
            $this->db = null;
        }
        $schema = 'reldyn_night_' . $build . getmypid() . '_' . bin2hex(random_bytes(3));
        $this->schemas[] = $schema;
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$schema}");
        pg_query($admin, "SET search_path TO {$schema}");
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
        // data/database_default.sql eventlog / responselog
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

        $this->db = new RelDynTestBedsNightPgDb($this->dsn, $schema);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        RelationshipDynamics::endRequest();
        RelDynTraitRead::reset();
        // Shipped defaults, stored as the config page stores them
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();
        $this->felt = [];

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
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 60, 'type' => 'romantic']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;   // Serene's hand-set vector: never read
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        // Core's locations rows: the Bannered Mare is an Inn, Breezehome a player house
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld'), ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
        $this->player($build);
    }

    /** A core_player row as core writes it (gamedata.php: one row per id, value text). */
    private function corePlayer(string $id, $value): void
    {
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)
            ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value', [$id, is_string($value) ? $value : json_encode($value)]);
    }

    /** The player, as the plugin reports him: skills, level and the tracked Skyrim stats. */
    private function player(string $build): void
    {
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speechcraft', 'twohanded'], 15);
        if ($build === 'warrior') {
            // a seasoned warrior and Companion
            $this->corePlayer('skills', array_merge($all, ['onehanded' => 70, 'twohanded' => 60, 'block' => 55, 'heavyarmor' => 60]));
            $stats = ['People Killed' => 110, 'Creatures Killed' => 135, 'Quests Completed' => 27, 'Dungeons Cleared' => 17,
                      'Locations Discovered' => 70, 'The Companions Quests Completed' => 4];
        } else {
            // a silver-tongued bard: talks his way through, fights as little as he can
            $this->corePlayer('skills', array_merge($all, ['speechcraft' => 85, 'illusion' => 45, 'onehanded' => 25, 'lightarmor' => 30]));
            $stats = ['People Killed' => 6, 'Creatures Killed' => 25, 'Quests Completed' => 27, 'Dungeons Cleared' => 6,
                      'Locations Discovered' => 70, 'Persuasions' => 30, 'Bribes' => 8, 'Intimidations' => 2];
        }
        $this->corePlayer('stats', ['level' => 30, 'health' => 250]);
        foreach ($stats as $stat => $n) $this->corePlayer($stat, (string) $n);
        $this->corePlayer('gender', 'male');
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
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    /** Every NPC hears $line, ten game minutes apart from $gamets; then the eval worker scores the round. */
    private function round(string $line, int $gamets, string $label): void
    {
        $i = 0;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, $line, $gamets + 600 * $i++, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, "eval worker after {$label}: " . json_encode($stats));
    }

    /** Core's request poll (every POLINT real seconds): the plugin sends no profile, so HERIKA_NAME is conf.php's default. */
    private function poll(string $herikaName, int $gamets): void
    {
        unset($GLOBALS['CACHE_PEOPLE'], $GLOBALS['CACHE_PARTY'], $GLOBALS['CACHE_LOCATION'], $GLOBALS['contextDataFull']);
        $GLOBALS['gameRequest'] = ['request', (string) ($this->realTs * 1000), (string) $gamets, self::MARE, '0x0001a279'];
        $GLOBALS['HERIKA_NAME'] = $herikaName;
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/prerequest.php'; })();
        RelationshipDynamics::endRequest();
        $this->clearReldynGlobals();
        $this->realTs += 1;
    }

    /** The eval LLM at the connector boundary: what each line of this story scores as (contract v1 + exposure). */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $exchange = substr($content, (int) strpos($content, 'THIS EXCHANGE'));
            $signals = ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0];
            $tags = [];
            $significance = 0.1;
            $summary = 'Small talk.';
            $exposure = ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null];
            if (str_contains($exchange, self::FIRE)) {
                $signals = array_replace($signals, ['affinity' => 3, 'trust' => 3, 'comfort' => 3, 'passion' => 8]);
                $tags = ['quality_time'];
                $significance = 0.6;
                $summary = 'A quiet evening together by the fire.';
            } elseif (str_contains($exchange, self::PRAISE)) {
                $signals = array_replace($signals, ['affinity' => 2, 'respect' => 2, 'passion' => 6]);
                $tags = ['praise'];
                $significance = 0.5;
                $summary = 'The player admired her.';
            } elseif (str_contains($exchange, 'Bannered Mare last night')) {
                $exposure = ['flag' => true, 'kinds' => ['rival_exposure', 'place', 'vice'], 'intensity' => 2, 'when' => 'last_night'];
                $significance = 0.4;
                $summary = 'The player told her about a night at the tavern.';
            } elseif (str_contains($exchange, self::REASSURE)) {
                $signals = array_replace($signals, ['trust' => 2, 'comfort' => 2]);
                $tags = ['reassurance'];
                $significance = 0.4;
                $summary = 'The player reassured her.';
            }
            return json_encode([
                'signals' => $signals, 'tags' => $tags,
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => $exposure, 'significance' => $significance, 'summary' => $summary,
            ]);
        };
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

    /** Everything RelDyn keeps on the four core rows (the bonds), for the poll check. */
    private function bonds(): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) {
            $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data, extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
            $out[$npc] = [$r['plugin_extended_data'], $r['extended_data']];
        }
        return $out;
    }

    private static function passionOf(array $d): float
    {
        return floatval(RelationshipDynamics::getPassion($d));
    }

    // ------------------------------------------------------------------ the story

    /** The same days and the same night for one player; what each test bed ended up with. */
    private function story(string $build): array
    {
        $this->world($build);
        $out = ['build' => $build];
        $npcs = array_keys(self::BEDS);

        // ---- day 0, evening at home
        $this->event('infoloc', self::HOME, self::at(self::D0, 18.0), $this->home());
        $this->round('Well met, love.', self::at(self::D0, 18.0), 'hello');
        foreach ($npcs as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('read', $d['_trait_vector_src']['assignment'] ?? null, "{$build} {$npc}: her own vector");
            $this->assertSame('romantic', $d['_core_rel_type'] ?? null, "{$build} {$npc}: a partner");
            $def = RelDynAttraction::definition($npc, $d);
            $this->assertSame('standards', $def['sources']['floors'], "{$build} {$npc}: the floor is her standards");
            $out[$npc]['floor'] = $def['standards']['floor'];
            $out[$npc]['passion0'] = self::passionOf($d);
            $x = RelDynConcern::traitsOf($d);
            $out[$npc]['Po'] = $x['Po'];
            $out[$npc]['Pr'] = $x['Pr'];
            $out[$npc]['expression'] = RelDynConcern::expression($d, $x);
        }

        // ---- days 1-3 together at home: words of praise, evenings by the fire
        foreach ([1, 2, 3] as $k) {
            $day = self::D0 + $k;
            $this->event('infoloc', self::HOME, self::at($day, 10.0), $this->home());
            $this->round(self::PRAISE, self::at($day, 11.0), "praise{$k}a");
            $this->round(self::PRAISE, self::at($day, 14.0), "praise{$k}b");
            $this->event('infoloc', self::HOME, self::at($day, 16.0), $this->home());
            $this->round(self::FIRE, self::at($day, 19.0), "fire{$k}a");
            $this->round(self::FIRE, self::at($day, 21.0), "fire{$k}b");
        }
        foreach ($npcs as $npc) {
            $d = $this->dynamics($npc);
            $a = (array) ($d['_attraction'] ?? []);
            $out[$npc]['passion1'] = self::passionOf($d);
            $out[$npc]['curve'] = $a['passion_mult'] ?? null;
            $out[$npc]['friendzoned'] = $a['friendzoned'] ?? null;
            $out[$npc]['hard_zero'] = $a['hard_zero'] ?? null;
            $out[$npc]['outcome'] = $a['outcome'] ?? null;
            $out[$npc]['floor_after'] = RelDynAttraction::definition($npc, $d)['standards']['floor'];
        }

        // ---- day 4: hello at 18:00, then the night at the Mare while they stay home
        $day = self::D0 + 4;
        $this->event('infoloc', self::HOME, self::at($day, 18.0), $this->home());
        $this->round('Evening, love.', self::at($day, 18.0), 'evening');
        $this->event('infoloc', self::HOME, self::at($day, 20.5), $this->home());
        $before = $this->bonds();
        foreach ([21.0, 22.5, 0.5 + 24] as $h) {
            $this->event('infoloc', self::MARE, self::at($day, $h), self::MARE_PEOPLE);
            // core polls all night; conf.php's default HERIKA_NAME is Ashe, and the Narrator
            foreach (['Ashe', 'The Narrator', 'Ashe'] as $i => $name) $this->poll($name, self::at($day, $h) + 60 * $i);
        }
        $this->event('itemfound', self::PLAYER . ' drank Nord Mead', self::at($day, 23.5), self::MARE_PEOPLE);
        $this->poll('Ashe', self::at($day, 23.6));
        $out['polls_touched_bonds'] = $this->bonds() !== $before;
        $this->event('infoloc', self::HOME, self::at($day + 1, 0.9), $this->home());
        $this->round("I'm home.", self::at($day + 1, 1.0), 'return');

        // ---- day 5: he mentions it (route A), then reassures
        $this->round(self::TOLD, self::at($day + 1, 9.0), 'told');
        $now = self::at($day + 1, 10.0);
        foreach ($npcs as $npc) {
            $d = $this->dynamics($npc);
            $out[$npc]['concern'] = RelDynConcern::level($d);
            $out[$npc]['protective'] = RelDynConcern::count($d, RelDynConcern::PROTECTIVE, $now);
            $out[$npc]['possessive'] = RelDynConcern::count($d, RelDynConcern::POSSESSIVE, $now);
            $out[$npc]['routes'] = $d['_concern']['incidents'][0]['routes'] ?? [];
            $out[$npc]['jealousy'] = floatval($d['jealousy_anger'] ?? 0);
            $out[$npc]['damping'] = RelationshipDynamics::jealousyTrustFactor($d);
            $out[$npc]['felt_return'] = $this->felt[$npc]['return'] ?? [];
            $out[$npc]['felt_told'] = $this->felt[$npc]['told'] ?? [];
        }
        $this->event('infoloc', self::HOME, self::at($day + 1, 14.0), $this->home());
        $this->round(self::REASSURE, self::at($day + 1, 15.0), 'reassure');
        foreach ($npcs as $npc) {
            $d = $this->dynamics($npc);
            $out[$npc]['concern_after'] = RelDynConcern::level($d);
            $out[$npc]['passion2'] = self::passionOf($d);
            $out[$npc]['core_type'] = $d['_core_rel_type'] ?? null;
        }
        $jev = RelationshipDynamics::jevStateBlock('Ashe');
        $out['jev_ashe'] = $jev['text'] ?? '';
        $out['felt_all'] = $this->felt;
        $out['db_failures'] = $this->db->failures;
        return $out;
    }

    public function testTheFourTestBedsDivergeForAWarriorAndABardOverDaysAndABarNight(): void
    {
        $r = ['warrior' => $this->story('warrior'), 'bard' => $this->story('bard')];
        $why = json_encode(array_map(fn($s) => array_map(fn($v) => is_array($v) ? array_diff_key($v, ['felt_return' => 1, 'felt_told' => 1]) : $v,
            array_diff_key($s, ['felt_all' => 1, 'jev_ashe' => 1])), $r));
        $w = $r['warrior'];
        $b = $r['bard'];
        $worriers = ['Aela the Huntress', 'Ashe', 'Muiri'];

        foreach ($r as $build => $s) {
            $this->assertSame([], $s['db_failures'], "{$build}: no failed statement");
            // prerequest-on-poll: a night of polls, conf.php's default HERIKA_NAME an NPC, touched no bond
            $this->assertFalse($s['polls_touched_bonds'], "{$build}: core's poll touches no bond");
        }
        $this->assertSame(0, $this->llmCalls, "no trait read: the seed and Serene's vector");
        $this->assertGreaterThan(0, $this->evalCalls, 'the eval worker scored the days (stubbed)');

        // ---------------- floors: her standards, not the player
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertEqualsWithDelta($w[$npc]['floor'], $b[$npc]['floor'], 1e-9, "{$npc}: the same floor whoever he is");
            $this->assertEqualsWithDelta($w[$npc]['floor'], $w[$npc]['floor_after'], 1e-9, "{$npc}: standards hold over the days");
        }
        $this->assertEqualsWithDelta(61.8, $w['Aela the Huntress']['floor'], 0.5, "Aela: her read on the design's formula " . $why);
        $this->assertEqualsWithDelta(72.0, $w['Ashe']['floor'], 0.5, "Ashe: design Q4(b)'s 72 " . $why);
        $this->assertGreaterThan($w['Aela the Huntress']['floor'] + 5.0, $w['Ashe']['floor'], 'Ashe: the highest bar ' . $why);
        $this->assertGreaterThan($w['Muiri']['floor'] + 5.0, $w['Aela the Huntress']['floor'], $why);
        $this->assertGreaterThan($w['Lynly Star-Sung']['floor'] + 5.0, $w['Muiri']['floor'], 'Lynly: the open bard, the lowest ' . $why);

        // ---------------- passion: who she is drawn to (the same days, the same words)
        $spark = floatval(RelDynAttraction::curveConfig()['spark']);
        // Aela: the warrior climbs her hill; the bard she tolerates and feels little for
        $aw = $w['Aela the Huntress'];
        $ab = $b['Aela the Huntress'];
        $this->assertSame('drawn', $aw['outcome'], $why);
        $this->assertFalse($aw['friendzoned'], $why);
        $this->assertSame('friendzone', $ab['outcome'], 'Aela: a bard she can tolerate ' . $why);
        $this->assertTrue($ab['friendzoned'], $why);
        $this->assertGreaterThan(3.0 * $ab['curve'], $aw['curve'], $why);
        $this->assertGreaterThan($spark, $aw['passion1'], $why);
        $this->assertGreaterThan(2.5 * max(0.0, $ab['passion1'] - $spark), $aw['passion1'] - $spark, 'Aela: above the spark, the warrior far ahead ' . $why);
        $this->assertGreaterThan($ab['passion1'] + 3.0, $aw['passion1'], $why);
        foreach ($r as $build => $s) {
            // Ashe: her rigid competence bar holds against both (no passion, whatever the words)
            $this->assertSame('rigid:competence', $s['Ashe']['hard_zero'], "{$build}: " . $why);
            $this->assertEqualsWithDelta(0.0, $s['Ashe']['passion1'], 1e-9, "{$build} Ashe");
            // Muiri: bond-gated, a slow burn before the bond, for either
            $this->assertSame('prebond', $s['Muiri']['outcome'], "{$build}: " . $why);
            $this->assertLessThan(0.3, $s['Muiri']['curve'], "{$build}: " . $why);
            // Lynly: open, her low floor met: the most passion of the four
            $this->assertSame('drawn', $s['Lynly Star-Sung']['outcome'], "{$build}: " . $why);
            $this->assertGreaterThanOrEqual(1.0, $s['Lynly Star-Sung']['curve'], $why);
            foreach (['Aela the Huntress', 'Ashe', 'Muiri'] as $npc) {
                $this->assertGreaterThan($s[$npc]['passion1'] + 10.0, $s['Lynly Star-Sung']['passion1'], "{$build} {$npc}: " . $why);
            }
        }
        // attraction reads the player only through her lens: Lynly's tastes (looks, unknown here)
        // do not care whether he fights or sings; Aela's do
        $this->assertEqualsWithDelta($w['Lynly Star-Sung']['passion1'], $b['Lynly Star-Sung']['passion1'], 1e-6, $why);
        // on the warrior's days the four fall in the order of who is drawn to him
        $this->assertGreaterThan($w['Aela the Huntress']['passion1'], $w['Lynly Star-Sung']['passion1'], $why);
        $this->assertGreaterThan($w['Muiri']['passion1'], $w['Aela the Huntress']['passion1'], $why);
        $this->assertGreaterThan($w['Ashe']['passion1'], $w['Muiri']['passion1'], $why);

        // ---------------- the bar night: concern vs jealousy (the worry is the same for either player: it is her, and the night)
        foreach ($r as $build => $s) {
            foreach (array_keys(self::BEDS) as $npc) {
                $x = $s[$npc];
                $this->assertLessThan(0.35, $x['Po'], "{$build} {$npc}: possessiveness below the counting line");
                $this->assertGreaterThan(0.0, $x['jealousy'], "{$build} {$npc}: the suitors are felt as jealousy");
                $this->assertLessThan(1.0, $x['damping'], "{$build} {$npc}: trust damps the jealousy");
                $this->assertSame(0, $x['possessive'], "{$build} {$npc}: felt, never filed");
                $this->assertSame('romantic', $x['core_type'], "{$build} {$npc}: one night is not a values conflict");
                // the worry is hers (her Pr) and the bond's (trust, core affinity: concern gain §1.4), not his build:
                // Aela's bond grew a little more with the warrior she is drawn to, the others' alike
                $this->assertEqualsWithDelta($w[$npc]['concern'], $x['concern'], 0.05 * max(1.0, $w[$npc]['concern']), "{$build} {$npc}: the worry is hers, not his build");
            }
            // Lynly, the inn's bard: the night is her world: no worry, nothing counted, nothing said
            $lynly = $s['Lynly Star-Sung'];
            $this->assertSame(0.0, floatval($lynly['concern']), "{$build} Lynly: no worry");
            $this->assertSame(0, $lynly['protective'], "{$build} Lynly");
            foreach (['felt_return', 'felt_told'] as $turn) {
                $this->assertSame([], array_values(array_filter(array_keys($lynly[$turn]), fn($k) => str_starts_with((string) $k, 'concern_'))), "{$build} Lynly {$turn}");
            }
            // the other three worry: noticed on the return (route B; Ashe despite the night of
            // polls under her name), one night counted once whether seen or told; the most
            // protective (Aela) the most
            foreach ($worriers as $npc) {
                $x = $s[$npc];
                $this->assertArrayHasKey('concern_noticed', $x['felt_return'], "{$build} {$npc}: route B");
                $this->assertStringContainsString('the drink on Kaida', $x['felt_return']['concern_noticed']);
                $this->assertSame(1, $x['protective'], "{$build} {$npc}: one night, however many routes");
                $this->assertSame(['B', 'A'], $x['routes'], "{$build} {$npc}: seen at 01:00, told next morning: one incident");
                $this->assertGreaterThan(0.0, $x['concern'], "{$build} {$npc}");
                $this->assertLessThan(25.0, $x['concern'], "{$build} {$npc}: one night is tolerated");
                $this->assertLessThan($x['concern'], $x['concern_after'], "{$build} {$npc}: reassurance eases it");
            }
            $this->assertGreaterThan(1.15 * max($s['Ashe']['concern'], $s['Muiri']['concern']), $s['Aela the Huntress']['concern'], "{$build}: Aela, Pr highest " . $why);
            $j = array_map(fn($npc) => $s[$npc]['jealousy'], array_combine(array_keys(self::BEDS), array_keys(self::BEDS)));
            arsort($j);
            $this->assertSame('Muiri', array_key_first($j), "{$build}: Muiri is the most jealous about the suitors " . $why);

            // ---------------- expression by maturity
            $this->assertSame('mature', $s['Ashe']['expression']['band'], "{$build} Ashe: maturity 75");
            $this->assertSame('mature', $s['Aela the Huntress']['expression']['band'], "{$build} Aela");
            foreach (['Aela the Huntress', 'Ashe'] as $npc) {
                $this->assertStringContainsString('once, plainly and without blame', $s[$npc]['felt_return']['concern_stated_mature'] ?? '', "{$build} {$npc}");
                $this->assertArrayNotHasKey('concern_stated_mixed', $s[$npc]['felt_return'], "{$build} {$npc}");
            }
            $this->assertSame('mixed', $s['Muiri']['expression']['band'], "{$build} Muiri: in between");
            $this->assertStringContainsString('means to say it evenly', $s['Muiri']['felt_return']['concern_stated_mixed'] ?? '', $build);
            $this->assertArrayNotHasKey('concern_stated_mature', $s['Muiri']['felt_return'], $build);
            $this->assertSame('accusation', $s['Muiri']['expression']['style'], "{$build}: Muiri, the most reactive");
            $this->assertStringContainsString('as an accusation', $s['Muiri']['felt_return']['concern_stated_mixed'], $build);

            // feelings, never numbers, in front of the LLM; Jev gets the numbers
            foreach ($s['felt_all'] as $npc => $turns) {
                foreach ($turns as $label => $lines) {
                    foreach ((array) $lines as $key => $text) {
                        if (str_starts_with((string) $key, 'concern_') || $key === 'passion') {
                            $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$build} {$npc} {$label} {$key}");
                        }
                    }
                }
            }
            $this->assertMatchesRegularExpression('/concern=\d/', $s['jev_ashe'], "{$build}: Jev gets the numbers");
        }
    }
}
