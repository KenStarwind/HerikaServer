<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynDiminishingBedsPgDb
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
 * diminishing-returns (and the diary gap it fed) on the four test beds (Aela the Huntress, Ashe with
 * Serene's hand-set vector, Muiri, Lynly Star-Sung) through the real hooks, CHIM 3.4.1 core-shaped rows
 * on a real PostgreSQL, no LLM call.
 *
 * Consecutive turns each pay less (the session multiplier falls with the count), at a rate that is
 * her own curve (guard-owned half-life and decay, the continuous A6 columns at her vector); a played
 * break (the play heartbeat crediting eventlog time) gives it back while the lifetime interaction clock
 * only grows; a bio read that lands after the first meeting moves her curve and love languages to
 * the read's; a steady pace keeps opening the diary's gap.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynDiminishingReturnsTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const AELA = 'Aela the Huntress';
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const OUTSIDE = '(Context location: Whiterun outdoors ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: ..., current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private array $readFields = [];
    private array $seed = [];
    private ?RelDynDiminishingBedsPgDb $db = null;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    /** npc => label => felt lines (key => text) */
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rddimret');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_dimret_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        $this->world();
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

    /** A fresh schema with the CHIM 3.4.1 tables RelDyn touches, the shipped config, the beds and Serana. */
    private function world(): void
    {
        $this->schema = 'reldyn_dimret_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
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

        $this->db = new RelDynDiminishingBedsPgDb($this->dsn, $this->schema);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;
        RelationshipDynamics::endRequest();
        RelDynTraitRead::reset();
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();

        $seed = $this->seed = RelDynTraitRead::loadSeedFile();
        RelDynTraitRead::ensureTable();
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $npcs = self::BEDS + ['Serana' => ['serana', 'NordRace', 'Vampire', ['DLC1SeranaFaction', 'DLC1SeranaCrimeFaction', 'CurrentFollowerFaction'],
                                           ['destruction' => 50, 'conjuration' => 45, 'sneak' => 40], null]];
        foreach ($npcs as $name => [$key, $race, $class, $factions, $skills, $voice]) {
            $f = [];
            foreach ($factions as $i => $faction) $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
            pg_query_params($this->db->link,
                'INSERT INTO core_npc_master (npc_name, gender, race, voiceid, core, npc_static_bio, metadata, extended_data)
                 VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb, $8::jsonb)',
                [$name, 'female', $race, '', "Roleplay as {$name}", '',
                 json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
                 json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            $this->readFields[$key] = $fields;
            $this->insertRead($key);
        }
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode(['level' => 20])]);
    }

    /** The seed read of $key (the bio read the shipped seed holds; Ashe's vector is hand-set and Serana has none: nothing to land). */
    private function insertRead(string $key): void
    {
        if (!isset($this->seed['reads'][$key])) return;
        $e = $this->seed['reads'][$key];
        pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
            VALUES ($1, $2, $3, 'done', 1, $4, $5::jsonb)
            ON CONFLICT (template_key, src_hash) DO UPDATE SET status = 'done', attempts = 1, model = EXCLUDED.model, result = EXCLUDED.result",
            [$key, RelDynTraitRead::srcHash($this->readFields[$key]), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
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

    private function people(): string
    {
        return '|' . implode('|', array_keys(self::BEDS)) . '|Serana|' . self::PLAYER . '|';
    }

    /** One player line to $npc through the real hooks at $gamets, logged as core logs it. */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->people());
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->people(), 'emitted');
            }
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->people();
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

    /** Every NPC hears $line, ten game minutes apart from $gamets. */
    private function round(array $npcs, string $line, int $gamets, string $label): void
    {
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 600 * $i++, $label);
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    private function assertNoFailures(): void
    {
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no LLM call');
        $this->assertStringNotContainsString('ERROR', (string) file_get_contents($this->errorLog));
    }

    /** The plugin's live stats report for the player (gamedata.php 'stats', actor_type player -> core_player.stats). */
    private function playerStats(?float $health, float $max = 300.0): void
    {
        pg_query_params($this->db->link, 'DELETE FROM core_player WHERE id = $1', ['stats']);
        if ($health === null) return;
        $stats = ['level' => 20, 'health' => $health, 'health_max' => $max, 'magicka' => 150.0, 'magicka_max' => 150.0,
                  'stamina' => 20.0, 'stamina_max' => 200.0, 'scale' => 1.0];
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode($stats)]);
    }

    // ------------------------------------------------------------------ helpers

    /** Eventlog 'request' polls every 100,000 raw gamets from $from for $hours of real play: the play heartbeat credits them as play. Returns the last row's gamets. */
    private function playFor(float $hours, int $from): int
    {
        $step = 100000;
        $n = (int) ceil($hours * RelationshipDynamics::GAMETS_PER_REAL_HOUR / $step);
        pg_query($this->db->link, "INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location)
            SELECT 'request', '', 'pending', {$from} + g * {$step}, {$this->realTs}, {$from} + g * {$step}, '', '' FROM generate_series(1, {$n}) g");
        return $from + $n * $step;
    }

    private function params(string $npc): array
    {
        return RelationshipDynamics::warmthParams($this->dynamics($npc));
    }

    // ------------------------------------------------------------------ diminishing returns through the hooks

    public function testConsecutiveTurnsEachPayLessAtHerOwnRateAndAPlayedBreakGivesItBack(): void
    {
        $beds = array_keys(self::BEDS);
        $t = self::at(70, 12.0);
        $this->event('infoloc', self::OUTSIDE, $t - 1000, $this->people());
        $mult = [];
        $clock = [];
        for ($i = 0; $i < 8; $i++) {
            $this->round($beds, 'Another good talk.', $t, "talk{$i}");
            $t += 90000;      // 39 real seconds of play between her turns
            foreach ($beds as $npc) {
                $d = $this->dynamics($npc);
                $mult[$npc][] = RelationshipDynamics::getSessionMultiplier($d);
                $clock[$npc][] = RelationshipDynamics::interactionClock($d);
            }
        }
        foreach ($beds as $npc) {
            for ($i = 1; $i < 8; $i++) {
                $this->assertLessThan($mult[$npc][$i - 1], $mult[$npc][$i], "{$npc}: talk " . ($i + 1) . ' pays less than the one before');
                $this->assertSame($clock[$npc][$i - 1] + 1, $clock[$npc][$i], "{$npc}: the lifetime clock counts the talk");
            }
            $this->assertGreaterThan(0.05, $mult[$npc][7]);
            $this->assertLessThan(0.97, $mult[$npc][7], "{$npc}: eight talks in five play minutes are well down");
        }

        // Her own rate: the continuous curve at her vector (guard-owned), so the beds do not pay alike
        $final = array_map(fn($m) => round($m[7], 4), $mult);
        $this->assertGreaterThan(1, count(array_unique($final)), 'the four beds do not all pay alike: ' . json_encode($final));
        $rate = [];
        foreach ($beds as $npc) $rate[$npc] = $this->params($npc)['decay_rate'];
        $this->assertGreaterThan(1, count(array_unique(array_map(fn($r) => round($r, 4), $rate))), 'the beds run on different curves');
        foreach ($beds as $a) {
            foreach ($beds as $b) {
                if ($rate[$a] > $rate[$b] + 1e-4) $this->assertLessThan($mult[$b][7], $mult[$a][7], "{$a} decays faster than {$b}: pays less");
            }
        }

        // Ten played hours away: the count melts, the multiplier recovers; the lifetime clock does not melt
        $before = array_map(fn($m) => $m[7], $mult);
        $end = $this->playFor(10.0, $t);
        $this->round($beds, 'It has been a while.', $end + 100000, 'afterbreak');
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertGreaterThan($before[$npc] + 0.05, RelationshipDynamics::getSessionMultiplier($d), "{$npc}: the break gave the gain back");
            $this->assertSame($clock[$npc][7] + 1, RelationshipDynamics::interactionClock($d), "{$npc}: the clock only grows");
            $this->assertLessThan(RelationshipDynamics::interactionClock($d), intval($d['interaction_count']), "{$npc}: the diminishing count melted below the lifetime clock");
        }
        $this->assertNoFailures();
    }

    public function testHerBioReadLandingAfterTheFirstMeetingMovesHerCurveAndLoveLanguages(): void
    {
        $keys = ['Aela the Huntress' => 'aela_the_huntress', 'Muiri' => 'muiri', 'Lynly Star-Sung' => 'lynly_star-sung'];
        foreach ($keys as $key) pg_query_params($this->db->link, 'DELETE FROM reldyn_trait_reads WHERE template_key = $1', [$key]);
        RelDynTraitRead::reset();

        $t = self::at(71, 12.0);
        $this->event('infoloc', self::OUTSIDE, $t - 1000, $this->people());
        $this->round(array_keys($keys), 'We have only just met.', $t, 'first');
        $first = [];
        foreach (array_keys($keys) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('pending', $d['_trait_vector_src']['read_status'], "{$npc}: her read is not in yet");
            $this->assertNotEmpty($d['love_language_primary'], $npc);
            $first[$npc] = ['vec' => $d['trait_vector'], 'curve' => $d['warmth_curve'], 'll' => [$d['love_language_primary'], $d['love_language_secondary']],
                            'params' => RelationshipDynamics::warmthParams($d)];
        }

        foreach ($keys as $key) $this->insertRead($key);   // the read lands
        RelDynTraitRead::reset();
        $this->round(array_keys($keys), 'Tell me about yourself.', $t + 90000, 'second');
        $changed = 0;
        foreach (array_keys($keys) as $npc) {
            $d = $this->dynamics($npc);
            $this->assertSame('done', $d['_trait_vector_src']['read_status'], "{$npc}: her read landed");
            $this->assertNotSame($first[$npc]['vec'], $d['trait_vector'], "{$npc}: her vector is her read's now");
            // her read's curve: what the engine gives a fresh NPC at that vector (the label the vector names), and the
            // continuous numbers at her own vector
            $this->assertSame(RelDynTraits::labelParam($d['inferred_temperament'], RelationshipDynamics::TEMPERAMENT_WARMTH_CURVES, 'moderate', $d),
                $d['warmth_curve'], "{$npc}: the curve of her read");
            $x = RelDynTraits::readVector($d);
            $params = RelationshipDynamics::warmthParams($d);
            foreach (['decay_rate' => 'warmth_decay_rate', 'half_life' => 'warmth_half_life', 'passion_decay' => 'warmth_passion_decay'] as $k => $col) {
                $this->assertEqualsWithDelta(RelDynTraits::value($x, $col), $params[$k], 1e-9, "{$npc} {$k}");
            }
            $probe = $d;
            unset($probe['love_language_primary'], $probe['love_language_secondary'], $probe['warmth_curve'], $probe['_ll_auto']);
            RelationshipDynamics::ensureLoveLanguage($npc, $probe);
            $this->assertSame([$probe['love_language_primary'], $probe['love_language_secondary'], $probe['warmth_curve']],
                [$d['love_language_primary'], $d['love_language_secondary'], $d['warmth_curve']], "{$npc}: the languages a fresh derivation at her read gives");
            if ($params != $first[$npc]['params']) $changed++;
        }
        $this->assertSame(3, $changed, 'each of the three runs on a different curve once her read is in');
        $this->assertNoFailures();
    }
}
