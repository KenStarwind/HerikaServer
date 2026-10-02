<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCombatMoodPgDb
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
 * The fight mood and the mood colouring end to end on the four test beds (Aela the Huntress, Ashe with
 * Serene's hand-set vector, Muiri nudged fearful, Lynly Star-Sung the bard), through the real hooks
 * (prerequest -> context -> postrequest), CHIM 3.4.1 core-shaped rows on a real PostgreSQL, no LLM
 * (the trait reader and the eval are stubbed at their connector):
 *   combat-arousal-temperament-context  the same fight against a skeever, a bandit ambush and a dragon
 *                         lifts every bed by the foe and by who the bed is; a win after high arousal is
 *                         positive valence, most for Aela; a fall hits harder against a worse foe; a
 *                         near miss (a fall lived through, or core's low HP report) brings the relief and
 *                         bonds; the felt aftermath is each bed's own.
 *   mood-coloring-181     what the aroused beds are told reads in the named state's words, by who the
 *                         bed is, within the felt budget, in numbers-free text.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCombatMoodTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const MINUTE = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;   // a minute of real play on the game clock
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
    private const REAL_TS0 = 1727000000;

    private string $dsn;
    private ?string $schema = null;
    private ?RelDynCombatMoodPgDb $db = null;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = self::REAL_TS0;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdcombatmood');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_combat_mood_beds_test.log');
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
        $this->dropWorld();
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    // ------------------------------------------------------------------ fixture

    private function dropWorld(): void
    {
        if ($this->schema === null) return;
        if ($this->db !== null) pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
        $this->schema = null;
        $this->db = null;
    }

    /**
     * A fresh schema with the CHIM 3.4.1 tables RelDyn touches, the shipped config and the beds
     * (core affinity 40, friends). Called again, it starts the same world from nothing: the
     * same clocks, the same rows (the threat comparison runs one fight per world).
     */
    private function world(): void
    {
        $this->dropWorld();
        RelationshipDynamics::endRequest();
        RelationshipDynamics::clearConfigCache();
        $this->realTs = self::REAL_TS0;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->schema = 'reldyn_combatmood_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynCombatMoodPgDb($this->dsn, $this->schema);
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        RelDynTraitRead::reset();
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true]))]);
        RelationshipDynamics::clearConfigCache();

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
                     'relationships' => [RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => 40, 'type' => 'friend']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if (!isset($seed['reads'][$key])) continue;   // Ashe: Serene's hand-set vector, never read
            $e = $seed['reads'][$key];
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', ['stats', json_encode(['level' => 20])]);
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
        return '|' . implode('|', array_keys(self::BEDS)) . '|' . self::PLAYER . '|';
    }

    /** One player line to $npc through the real hooks at $gamets, logged as core logs it. */
    private function turn(string $npc, string $line, int $gamets, string $label, ?string $mood = null): void
    {
        if ($mood !== null) {
            pg_query_params($this->db->link, 'INSERT INTO moods_issued (speaker, mood, localts) VALUES ($1, $2, $3)', [$npc, $mood, $this->realTs]);
        }
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

    /** Every bed hears $line, ten game minutes apart from $gamets. */
    private function round(array $npcs, string $line, int $gamets, string $label): void
    {
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 600 * $i++, $label);
    }

    /** A core combat request (the RPG combat end) through the real hooks, voiced by $speaker. */
    private function combatRequest(string $type, string $data, int $gamets, string $speaker): void
    {
        $this->event($type, $data, $gamets, $this->people());
        foreach (['prerequest.php', 'context.php', 'postrequest.php'] as $hook) {
            $GLOBALS['gameRequest'] = [$type, (string) $this->realTs, (string) $gamets, $data];
            $GLOBALS['HERIKA_NAME'] = $speaker;
            $GLOBALS['RELDYN_NPC_NAME'] = $speaker;
            $GLOBALS['CACHE_PEOPLE'] = $this->people();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $GLOBALS['contextDataFull'] = [];
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            RelationshipDynamics::endRequest();
            $this->clearReldynGlobals();
        }
        $this->realTs += 60;
    }

    private function bark(string $npc, int $gamets): void
    {
        $this->event('infoaction', self::PLAYER . ": Behind you! ({$npc} shouts during combat)", $gamets, $this->people());
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

    /** Core's relationship to the player (CHIM 3.4.1 extended_data.relationships.Player). */
    private function setCore(string $npc, int $aff, string $type): void
    {
        pg_query_params($this->db->link, "UPDATE core_npc_master SET extended_data = jsonb_set(extended_data, '{relationships}', $2::jsonb)
            WHERE npc_name = $1", [$npc, json_encode([RelationshipDynamics::PLAYER_RELATIONSHIP_KEY => ['aff' => $aff, 'type' => $type]])]);
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private function assertNoFailures(): void
    {
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no trait-read LLM call');
        $this->assertStringNotContainsString('ERROR', $this->log());
    }

    /**
     * The eval LLM at the connector boundary: "take my hand" after her fall is care (the player
     * protects her: rescue, reassurance); "Get up, you are slowing me down" is contempt (insult,
     * a grievance); anything else is small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $care = str_contains($exchange, 'take my hand');
            $contempt = str_contains($exchange, 'slowing me down');
            return json_encode([
                'signals' => ['affinity' => $care ? 2 : ($contempt ? -2 : 0), 'trust' => $care ? 2 : 0, 'comfort' => $care ? 2 : ($contempt ? -2 : 0),
                              'respect' => $contempt ? -1 : 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $care ? ['rescue', 'reassurance'] : ($contempt ? ['insult'] : []),
                'grievance' => $contempt ? ['flag' => true, 'kind' => 'insult', 'severity' => 1] : ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $care ? 0.6 : ($contempt ? 0.4 : 0.1),
                'summary' => $care ? 'She went down in the fight; the player knelt by her and helped her up.'
                    : ($contempt ? 'The player told her to get up, that she was slowing them down.' : 'Small talk.'),
            ]);
        };
    }

    // ------------------------------------------------------------------ the fight, the foe, the bed

    /** The felt aftermath texts the fight mood can put in post_combat, outcome/lean => text with {NAME} filled for $npc. */
    private function aftermathTexts(string $npc): array
    {
        $out = [];
        foreach (RelDynFelt::TEXT_DEFAULTS['combat']['aftermath'] as $outcome => $byLean) {
            foreach ($byLean as $lean => $text) $out["{$outcome}/{$lean}"] = str_replace('{NAME}', $npc, $text);
        }
        return $out;
    }

    /** Letters only, lower case: the felt line after intensity formatting (CAPS, pauses, exclamations) keeps these. */
    public static function letters(string $s): string
    {
        return strtolower(preg_replace('/[^A-Za-z]/', '', $s));
    }

    /** Is $line $words after intensity formatting? */
    private static function sameWords(string $line, string $words): bool
    {
        similar_text(self::letters($line), self::letters($words), $pct);
        return self::letters($line) === self::letters($words) || $pct >= 88.0;
    }

    /** Which aftermath (outcome/lean) the felt line $text of $npc is, or null for any other line. */
    private function aftermathOf(string $npc, ?string $text): ?string
    {
        if ($text === null) return null;
        foreach ($this->aftermathTexts($npc) as $key => $t) {
            // {PLAYER} is whatever name the bed's tier gives the player: compare the words before it
            $head = strstr($t, '{PLAYER}', true);
            $head = substr(self::letters($head === false ? $t : $head), 0, 30);
            if ($head !== '' && str_starts_with(self::letters($text), $head)) return $key;
        }
        return null;
    }

    /**
     * One fight in a fresh world: the beds meet the player, all four bark (they are in it), then core logs the
     * player's kill of $victim (with $falls, the beds that go down first); a later turn routes it and every bed
     * answers once, $afterOffset game ticks after $t1 (the default is past the minute the kill still counts as
     * combat, inside the five the glow lasts; a shorter one is "now" for core's health reports).
     * Returns npc => ['arousal', 'valence', 'dynamics', 'felt'].
     */
    private function fight(string $victim, array $falls = [], ?callable $beforeKill = null, int $afterOffset = 180000): array
    {
        $this->world();
        $beds = array_keys(self::BEDS);
        $t0 = self::at(150, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        $pre = [];
        foreach ($beds as $npc) $pre[$npc] = floatval($this->dynamics($npc)['dimensions']['valence']['x'] ?? 0);
        $t1 = self::at(150, 12.0);
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        foreach ($falls as $i => $npc) $this->event('bleedout', "{$npc} falls to the ground almost unconscious", $t1 + 5000 + 1000 * $i, $this->people());
        if ($beforeKill !== null) $beforeKill($t1);
        $this->event('death', '(Context location: Whiterun outdoors)' . self::PLAYER . " has defeated {$victim} using weapon Dragonbone Sword", $t1 + 30000, $this->people());
        $this->round($beds, 'Is everyone whole?', $t1 + $afterOffset, 'after');
        $out = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            // (valence is read from where the bed stood before the fight: some beds start a few points above zero)
            $out[$npc] = ['arousal' => floatval($d['dimensions']['arousal']['x'] ?? 0),
                'valence' => floatval($d['dimensions']['valence']['x'] ?? 0) - $pre[$npc],
                'dynamics' => $d, 'felt' => $this->felt[$npc]['after'] ?? []];
        }
        $this->assertNoFailures();
        return $out;
    }

    public function testTheSameFightMovesEveryBedByTheFoeAndByWhoTheBedIs(): void
    {
        $skeever = $this->fight('Skeever');
        $bandit = $this->fight('Bandit Marauder');
        $dragon = $this->fight('Dragon');
        $col = fn(array $f, string $k) => array_map(fn($b) => round($b[$k], 1), $f);
        $why = json_encode(['arousal' => ['skeever' => $col($skeever, 'arousal'), 'bandit' => $col($bandit, 'arousal'), 'dragon' => $col($dragon, 'arousal')],
            'valence' => ['skeever' => $col($skeever, 'valence'), 'bandit' => $col($bandit, 'valence'), 'dragon' => $col($dragon, 'valence')]]);
        foreach (array_keys(self::BEDS) as $npc) {
            // MDD 3.3 Stage 2: a skeever barely registers, a bandit ambush is alert and engaged, a dragon an adrenaline dump
            $this->assertLessThan(13.0, $skeever[$npc]['arousal'], "{$npc}: a skeever fight barely registers {$why}");
            // (Aela's own liking for the activity, the facets' appraisal, is the few points she has from any fight: not a win's thrill)
            $this->assertLessThan(6.0, $skeever[$npc]['valence'], "{$npc}: and no thrill in it {$why}");
            $this->assertGreaterThan(-6.0, $skeever[$npc]['valence'], "{$npc}: and no dread {$why}");
            $this->assertGreaterThan($skeever[$npc]['arousal'] + 5.0, $bandit[$npc]['arousal'], "{$npc}: a bandit ambush is more {$why}");
            $this->assertLessThan(60.0, $bandit[$npc]['arousal'], "{$npc}: but alert, not flooded {$why}");
            $this->assertGreaterThan($bandit[$npc]['arousal'] + 5.0, $dragon[$npc]['arousal'], "{$npc}: a dragon is the most {$why}");
            $this->assertGreaterThan($bandit[$npc]['valence'], $dragon[$npc]['valence'], "{$npc}: and the bigger win {$why}");
            $this->assertGreaterThan(5.0, $dragon[$npc]['valence'], "{$npc}: a win after high arousal is positive valence {$why}");
        }
        // ... and who the bed is decides how far: the same dragon, four different nervous systems
        $this->assertGreaterThanOrEqual(3, count(array_unique(array_map(fn($b) => round($b['arousal']), $dragon))), $why);
        $this->assertGreaterThanOrEqual(3, count(array_unique(array_map(fn($b) => round($b['valence']), $dragon))), $why);
        $this->assertGreaterThanOrEqual(75.0, max(array_map(fn($b) => $b['arousal'], $dragon)), 'somebody floods: ' . $why);
        // Aela, who loves a fight, takes the biggest thrill of it; Ashe, the sorceress who hardly cares, and Muiri, who dreads it, less
        $this->assertGreaterThan($dragon['Ashe']['valence'], $dragon[self::AELA]['valence'], $why);
        $this->assertGreaterThan($dragon['Muiri']['valence'], $dragon[self::AELA]['valence'], $why);
        $this->assertStringContainsString('Fight mood: ' . self::AELA . ' foe=overwhelming (Dragon)', $this->log());
        $this->assertStringContainsString('Fight mood: Muiri foe=trivial (Skeever)', $this->log());
        $this->assertStringContainsString('Fight mood: Muiri foe=engaged (Bandit Marauder)', $this->log());
    }

    public function testACombatEndNamesNoFoeSoTheKillsBeforeItDoAndAMightyEndNeedsNoRow(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(150, 10.0);
        $t1 = self::at(150, 12.0);
        $this->world();
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        $this->event('death', self::PLAYER . ' has defeated Dwarven Centurion using weapon Dragonbone Sword', $t1 + 20000, $this->people());
        $this->combatRequest('combatend', '(Context location: Whiterun outdoors)', $t1 + 30000, self::AELA);
        $this->assertMatchesRegularExpression('/Fight mood: Ashe foe=overwhelming \((Dwarven Centurion|combatend)\)/', $this->log(), 'the centurion, from the row');
        $this->assertGreaterThanOrEqual(30.0, $this->dynamics(self::AELA)['dimensions']['arousal']['x']);
        $this->assertNoFailures();

        $this->world();
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        $this->combatRequest('combatendmighty', '(Context location: Whiterun outdoors)', $t1 + 30000, self::AELA);
        $this->assertStringContainsString('foe=overwhelming (combatendmighty)', $this->log(), 'the plugin\'s own mighty-foe end');
        $this->assertGreaterThanOrEqual(30.0, $this->dynamics(self::AELA)['dimensions']['arousal']['x']);
        $this->assertNoFailures();
    }

    public function testTheFeltAftermathIsEachBedsOwnAndFeelingsOnly(): void
    {
        $dragon = $this->fight('Dragon');
        $keys = [];
        foreach ($dragon as $npc => $b) {
            $line = $b['felt']['post_combat'] ?? null;
            $this->assertIsString($line, "{$npc}: " . json_encode($b['felt']));
            $keys[$npc] = $this->aftermathOf($npc, $line);
            $this->assertNotNull($keys[$npc], "{$npc}: the dragon's aftermath, not the plain glow: {$line}");
            $this->assertStringStartsWith('triumph/', $keys[$npc]);
            $this->assertStringContainsString($npc, $line);
            $this->assertDoesNotMatchRegularExpression('/\d/', $line, "{$npc}: a number reached the LLM");
            $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|herself|he|him|his|himself)\b/i', $line, "{$npc}: a gendered pronoun");
        }
        // who the bed is: the warrior burns with it, the frightened shake with it
        $this->assertSame('triumph/bold', $keys[self::AELA], json_encode($keys));
        $this->assertContains($keys['Muiri'], ['triumph/shaken', 'triumph/steady'], json_encode($keys));
        $this->assertGreaterThanOrEqual(2, count(array_unique($keys)), 'not one aftermath for four beds: ' . json_encode($keys));
        // it fades with the glow (five minutes of play)
        $this->turn(self::AELA, 'Onward.', self::at(150, 12.0) + 180000 + 10 * self::MINUTE, 'later');
        $this->assertNull($this->aftermathOf(self::AELA, $this->felt[self::AELA]['later']['post_combat'] ?? null));
        // a skeever is not worth one
        $skeever = $this->fight('Skeever');
        foreach ($skeever as $npc => $b) {
            $this->assertNull($this->aftermathOf($npc, $b['felt']['post_combat'] ?? null), "{$npc}: nothing to tell about a skeever");
        }
    }

    public function testTheFightInProgressIsHowTheBedFights(): void
    {
        $this->world();
        $beds = array_keys(self::BEDS);
        $t0 = self::at(150, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        $t1 = self::at(150, 12.0);
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);   // core's combat rows: the beds are in combat right now
        $this->round($beds, 'Behind you!', $t1 + 5000, 'fighting');
        $lines = [];
        foreach ($beds as $npc) {
            $lines[$npc] = $this->felt[$npc]['fighting']['combat'] ?? null;
            $this->assertIsString($lines[$npc], "{$npc}: " . json_encode($this->felt[$npc]['fighting'] ?? []));
            $this->assertDoesNotMatchRegularExpression('/\d/', $lines[$npc]);
        }
        $this->assertStringContainsString('relish', strtolower($lines[self::AELA]), 'Aela fights with relish: ' . json_encode($lines));
        $this->assertGreaterThanOrEqual(2, count(array_unique($lines)), json_encode($lines));
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ the fall, the near miss

    public function testAFallHitsByTheFoeTheFightHasShownAndNeverTurnsItsDirection(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(150, 10.0);
        $t1 = self::at(150, 12.0);
        // Muiri falls before anybody knows what the foe is: the plain fall
        $this->fight('Dragon', ['Muiri']);
        $this->assertMatchesRegularExpression('/Bleedout: Muiri fight=\S+ fear=\S+ passion=\S+ valence=\S+ arousal=\S+ foe scale=1\.00/', $this->log());
        // ... after a dragon went down in the fight (core logs the kill first): the same fall hits harder
        $this->world();
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        $this->event('death', self::PLAYER . ' has defeated Dragon using weapon Dragonbone Sword', $t1 + 10000, $this->people());
        $this->event('bleedout', 'Muiri falls to the ground almost unconscious', $t1 + 20000, $this->people());
        $this->round($beds, 'Muiri!', $t1 + 60000, 'after');
        $this->assertMatchesRegularExpression('/Bleedout: Muiri fight=\S+ fear=\S+ passion=\S+ valence=\S+ arousal=\S+ foe scale=1\.50/', $this->log(),
            'a fall in a fight against a dragon hits harder');
        $this->assertNoFailures();
        // ... after a skeever, softer
        $this->world();
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        $this->event('death', self::PLAYER . ' has defeated Skeever using weapon Dragonbone Sword', $t1 + 10000, $this->people());
        $this->event('bleedout', 'Muiri falls to the ground almost unconscious', $t1 + 20000, $this->people());
        $this->round($beds, 'Muiri!', $t1 + 60000, 'after');
        $this->assertMatchesRegularExpression('/Bleedout: Muiri fight=\S+ fear=\S+ passion=\S+ valence=\S+ arousal=\S+ foe scale=0\.60/', $this->log());
        // Muiri, nudged fearful, falls into dread whatever the foe: the foe scales the fall, who she is decides its direction
        preg_match_all('/Bleedout: Muiri fight=\S+ fear=\S+ passion=\S+ valence=([+-][\d.]+)/', $this->log(), $m);
        $this->assertCount(3, $m[1]);
        foreach ($m[1] as $v) $this->assertLessThan(0.0, floatval($v), 'Muiri\'s fall is dread');
        $this->assertNoFailures();
    }

    public function testALivedThroughFallIsANearMissAndItBondsWhereAWinAloneDoesNot(): void
    {
        $control = $this->fight('Dragon');
        $rush = $this->fight('Dragon', [self::AELA, 'Muiri']);
        $this->assertStringContainsString('NEAR MISS: ' . self::AELA . ' (fell in the fight)', $this->log());
        $this->assertStringNotContainsString('NEAR MISS: Ashe', $this->log(), 'Ashe did not fall');
        $dims = fn(array $b, string $dim) => floatval($b['dynamics']['dimensions'][$dim]['x'] ?? 0);
        foreach ([self::AELA, 'Muiri'] as $npc) {
            $why = $npc . ' ' . json_encode(['control' => [$dims($control[$npc], 'trust'), $dims($control[$npc], 'comfort')],
                'rush' => [$dims($rush[$npc], 'trust'), $dims($rush[$npc], 'comfort')]]);
            $this->assertTrue($rush[$npc]['dynamics'][RelDynCombat::MOOD_KEY]['near_miss'], $npc);
            $this->assertGreaterThan($dims($control[$npc], 'comfort'), $dims($rush[$npc], 'comfort'), "{$npc}: comfort, the bond it makes {$why}");
            $this->assertGreaterThanOrEqual($dims($control[$npc], 'trust'), $dims($rush[$npc], 'trust'), "{$npc}: trust {$why}");
            $this->assertArrayNotHasKey('near_miss', $control[$npc]['dynamics']['passion_sources'] ?? [], "{$npc}: a win alone is no rush");
            $key = $this->aftermathOf($npc, $rush[$npc]['felt']['post_combat'] ?? null);
            $this->assertStringStartsWith('near_miss/', (string) $key, "{$npc}: {$why} " . ($rush[$npc]['felt']['post_combat'] ?? ''));
        }
        // the passion rush: governed like any gain, so the beds the player has drawn feel it (Aela, a friend with a pull)
        $this->assertFalse($control[self::AELA]['dynamics'][RelDynCombat::MOOD_KEY]['near_miss']);
        $this->assertFalse($rush['Ashe']['dynamics'][RelDynCombat::MOOD_KEY]['near_miss'], 'Ashe was beside it, not in it');
        // the beds are not the same bed: Aela rushes bold, Muiri is shaken or steady
        $this->assertSame('near_miss/bold', $this->aftermathOf(self::AELA, $rush[self::AELA]['felt']['post_combat'] ?? null));
        $this->assertNotSame('near_miss/bold', $this->aftermathOf('Muiri', $rush['Muiri']['felt']['post_combat'] ?? null));
        // ... and the rush runs deeper than the win alone: more arousal, more valence for the one who fell
        $this->assertGreaterThan($control[self::AELA]['valence'], $rush[self::AELA]['valence'], 'a rush on top of the win');
    }

    public function testAFallWithNoWinIsBeatenAndTheBedAnswersByWhoItIs(): void
    {
        $this->world();
        $beds = array_keys(self::BEDS);
        $t0 = self::at(150, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        $t1 = self::at(150, 12.0);
        foreach ([self::AELA, 'Muiri'] as $i => $npc) $this->event('bleedout', "{$npc} falls to the ground almost unconscious", $t1 + 1000 * $i, $this->people());
        // two minutes on: past the minute core still reports them down, inside the five minutes of glow
        $this->turn(self::AELA, 'Easy.', $t1 + 2 * self::MINUTE, 'glow');
        $this->turn('Muiri', 'Easy.', $t1 + 2 * self::MINUTE + 600, 'glow');
        $keys = [];
        foreach ([self::AELA, 'Muiri'] as $npc) {
            $ep = $this->dynamics($npc)[RelDynCombat::MOOD_KEY] ?? [];
            $this->assertSame('beaten', $ep['outcome'] ?? null, $npc);
            $this->assertFalse($ep['near_miss'], $npc);
            $keys[$npc] = $this->aftermathOf($npc, $this->felt[$npc]['glow']['post_combat'] ?? null);
            $this->assertStringStartsWith('beaten/', (string) $keys[$npc], $npc . ' ' . json_encode($this->felt[$npc]['glow'] ?? []));
        }
        $this->assertSame('beaten/bold', $keys[self::AELA], json_encode($keys));
        $this->assertNotSame('beaten/bold', $keys['Muiri'], json_encode($keys));
        $this->assertArrayNotHasKey(RelDynCombat::MOOD_KEY, $this->dynamics('Ashe'), 'Ashe did not fall');
        $this->assertNoFailures();
    }

    public function testCoresLowHealthReportMakesANearMissWhenTheEventIsOfNow(): void
    {
        $hp = function (string $npc, int $health): void {
            pg_query_params($this->db->link, "UPDATE core_npc_master SET metadata = COALESCE(metadata, '{}'::jsonb) || $2::jsonb WHERE npc_name = $1",
                [$npc, json_encode(['stats' => ['health' => $health, 'health_max' => 100, 'magicka' => 50, 'magicka_max' => 100, 'stamina' => 50, 'stamina_max' => 100]])]);
        };
        // Aela reported at 10% HP and the fight won: her own near miss; Lynly at 90% is not
        $r = $this->fight('Frost Troll', [], function () use ($hp): void { $hp(self::AELA, 10); $hp('Lynly Star-Sung', 90); }, 60000);
        $this->assertStringContainsString('NEAR MISS: ' . self::AELA . ' (own HP)', $this->log());
        $this->assertTrue($r[self::AELA]['dynamics'][RelDynCombat::MOOD_KEY]['near_miss']);
        $this->assertFalse($r['Lynly Star-Sung']['dynamics'][RelDynCombat::MOOD_KEY]['near_miss']);
        $this->assertMatchesRegularExpression('/NEAR MISS: Aela the Huntress \(own HP\) terror -[\d.]+ then relief/', $this->log(),
            'no fall to be the first valence: the terror is asked too');
        // the player at 8%: everyone who fought beside them saw how close it was
        $r = $this->fight('Frost Troll', [], function (): void {
            pg_query_params($this->db->link, "INSERT INTO core_player (id, value) VALUES ('stats', $1) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value",
                [json_encode(['health' => 8, 'health_max' => 100])]);
        }, 60000);
        foreach (array_keys(self::BEDS) as $npc) $this->assertTrue($r[$npc]['dynamics'][RelDynCombat::MOOD_KEY]['near_miss'], "{$npc}: the player nearly died");
        $this->assertStringContainsString("NEAR MISS: Muiri (the player's HP)", $this->log());
        // no report is unknown, never hurt; a healthy report is no near miss
        $r = $this->fight('Frost Troll', [], null, 60000);
        foreach (array_keys(self::BEDS) as $npc) $this->assertFalse($r[$npc]['dynamics'][RelDynCombat::MOOD_KEY]['near_miss'], $npc);
        // a report from before: the row is routed on a much later turn, which is not "now"
        $r = $this->fight('Frost Troll', [], function () use ($hp): void { $hp(self::AELA, 10); }, 30 * self::MINUTE);
        $this->assertFalse($r[self::AELA]['dynamics'][RelDynCombat::MOOD_KEY]['near_miss'] ?? false, 'a health report is read only for an event of now');
    }

    // ------------------------------------------------------------------ the mood, in words

    public function testWhatTheArousedBedsAreToldIsTheNamedStatesWordsByWhoTheBedIs(): void
    {
        $dragon = $this->fight('Dragon');
        $words = [];
        foreach ($dragon as $npc => $b) {
            $line = $b['felt']['arousal_valence'] ?? null;
            $this->assertIsString($line, "{$npc}: " . json_encode($b['felt']));
            $state = RelDynMoods::stateOf($b['dynamics']);
            $this->assertFalse($state['rest'], $npc);
            $this->assertTrue(self::sameWords($line, $state['text']), "{$npc}: the named state {$state['name']} ({$state['zone']}): {$state['text']} / {$line}");
            $this->assertDoesNotMatchRegularExpression('/\d/', $line);
            $plain = RelationshipDynamics::getArousalValenceBand($b['arousal'], floatval($b['dynamics']['dimensions']['valence']['x']))['keywords'];
            $this->assertFalse(self::sameWords($line, $plain), "{$npc}: not the plain band's words");
            $words[$npc] = $state['name'] . ' / ' . $state['zone'];
        }
        $this->assertGreaterThanOrEqual(3, count(array_unique(array_map(fn($b) => RelDynCombatMoodTestBedsPostgresTest::letters($b['felt']['arousal_valence'] ?? ''), $dragon))),
            'four beds, at least three ways to carry a dragon: ' . json_encode($words));
        // the felt budget holds: one line for the mood, and the whole steering inside its tier's lines and tokens
        $cfg = RelDynFelt::config();
        foreach (array_keys(self::BEDS) as $npc) {
            $felt = $this->felt[$npc]['after'] ?? [];
            $this->assertLessThanOrEqual(max($cfg['tier_max_lines']), count($felt), "{$npc}: " . json_encode(array_keys($felt)));
            $this->assertLessThanOrEqual(max($cfg['tier_token_budget']), array_sum(array_map(fn($t) => RelDynFelt::estimateTokens((string) $t), $felt)),
                "{$npc}: the felt steering stays inside its budget");
        }
        $this->assertNoFailures();
    }

    public function testTheSwitchesTakeTheNewMoodsOffAndLeaveTheOldFightAlone(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['combat']['arousal']['enabled'] = false;
        $cfg['mood_axes']['coloring']['enabled'] = false;
        $cfg['log_enabled'] = true;
        $dragon = $this->fight('Dragon', [], function () use ($cfg): void {
            pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($cfg)]);
            RelationshipDynamics::clearConfigCache();
        });
        foreach ($dragon as $npc => $b) {
            $this->assertLessThan(6.0, abs($b['valence']), "{$npc}: no fight mood (a dragon's thrill is 18 and more)");
            $this->assertArrayNotHasKey(RelDynCombat::MOOD_KEY, $b['dynamics'], $npc);
            $this->assertGreaterThan(0.0, floatval($b['dynamics']['passion_sources']['combat'] ?? 0.0), "{$npc}: the combat passion is as it was");
        }
        $this->assertStringNotContainsString('Fight mood:', $this->log());
    }
}
