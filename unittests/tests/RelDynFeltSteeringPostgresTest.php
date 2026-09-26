<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!isset($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/utils_game_timestamp.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql`-compatible adapter over one pg connection with CHIM's conventions
 * (lib/postgresql.class.php): fetchOne returns [] on a failed statement, fetchAll throws.
 */
final class RelDynFeltSteeringPgDb
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
        if (!$res) {
            $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
            return [];
        }
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
        if (!$res) $this->failures[] = pg_last_error($this->link) . ' :: ' . substr(preg_replace('/\s+/', ' ', $q), 0, 160);
        return $res;
    }

    public function insert($table, $data)
    {
        $cols = array_keys($data);
        $ph = [];
        foreach ($cols as $i => $_) $ph[] = '$' . ($i + 1);
        $res = @pg_query_params($this->link,
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')', array_values($data));
        if (!$res) $this->failures[] = pg_last_error($this->link) . " :: insert {$table}";
        return $res;
    }

    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }
}

/**
 * felt-steering / behavioral-keywords / context-pre-steering / intensity-formatting /
 * jev-explicit-state (decisions 2026-09-23 §3): the whole RelDyn prompt for six realistic
 * states, assembled the way main.php assembles it: prerequest, then every context_pre.php
 * (HERIKA_PERS, inside <character>), then every context.php (contextDataFull, the recency
 * position). Real PostgreSQL, CHIM 3.4.1 core-shaped rows, real hooks; nothing stubbed (no
 * LLM or embedding call is on this path).
 *
 * The LLM-bound RelDyn text carries no digits, percentages, "you feel" or dimension labels;
 * the behaviour it asks for differs across the states; its size stays inside the tier budget.
 * Jev (the one exception, §3) gets the numbers from RelationshipDynamics::jevStateBlock().
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynFeltSteeringPostgresTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const MINUTE = self::HOUR / 60;
    private const PLAYER = 'Kaida';
    private const BIO = 'Roleplay as this character.';

    private string $dsn;
    private string $schema;
    private RelDynFeltSteeringPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY + 10 * self::HOUR;   // game day 200, 10:00

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
        $this->schema = 'reldyn_felt' . getmypid() . '_' . bin2hex(random_bytes(3));

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
        // core's speech table (data/database_default.sql): prompt gating reads who has talked with the player
        pg_query($admin, "CREATE TABLE speech (sess varchar(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY,
            companions text, audios text, utterance_id text)");
        // data/database_default.sql eventlog / responselog.
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE responselog (localts bigint, sent int, actor text, text text, action text, tag text)");
        // Core 3.4.1 locations (debug/db_updates.php columns).
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
        pg_close($admin);

        $this->db = new RelDynFeltSteeringPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'HERIKA_PERS', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE',
                     'CACHE_PARTY', 'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdfelt');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_felt_steering_test.log');

        $this->config([]);
        // Kaida, a male warrior (one-handed / two-handed / heavy armor, level 40), as the plugin writes core_player.
        $all = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 15);
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2), ($3, $4), ($5, $6)',
            ['gender', 'male', 'skills', json_encode(array_merge($all, ['onehanded' => 90, 'twohanded' => 80, 'block' => 70, 'heavyarmor' => 80])),
             'stats', json_encode(['level' => 40, 'health' => 300])]);
        foreach ([
            ['College of Winterhold', 'Winterhold', 'Guild,', 'Skyrim'],
            ['Whiterun', 'Whiterun', 'City,', 'Skyrim'],
        ] as [$name, $hold, $tags, $world]) {
            pg_query_params($this->db->link,
                'INSERT INTO locations (name, region, hold, tags, factions, is_interior, world, chim_added) VALUES ($1, $2, $2, $3, $4, $5, $6, 1)',
                [$name, $hold, $tags, '', 5, $world]);
        }
    }

    protected function tearDown(): void
    {
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

    private function config(array $overrides): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(),
                ['log_enabled' => true], $overrides))]);
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ fixture

    /**
     * A core_npc_master row as CHIM 3.4.1 registers an NPC, the core relationship to the player,
     * and (optionally) stored RelDyn state as a save holds it.
     */
    private function npc(string $name, string $race, string $class, array $factions, array $skills, int $aff,
                         string $type, array $dynamics = []): void
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $faction) {
            $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
        }
        pg_query_params($this->db->link,
            'INSERT INTO core_npc_master (npc_name, refid, gender, race, voiceid, core, npc_static_bio, metadata, extended_data, plugin_extended_data)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8::jsonb, $9::jsonb, $10::jsonb)',
            [$name, strtoupper(substr(md5($name), 0, 6)), 'female', $race, 'FemaleEvenToned', "Roleplay as {$name}", '',
             json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
             json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                 'relationships' => ['Player' => ['aff' => $aff, 'type' => $type]]]),
             json_encode($dynamics === [] ? new stdClass() : ['reldyn' => ['dynamics' => $dynamics]])]);
    }

    private function dynamics(string $name): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$name]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function patchDynamics(string $name, callable $patch): void
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link,
            'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$name]));
        $ped = json_decode($r['plugin_extended_data'], true);
        $ped['reldyn']['dynamics'] = $patch($ped['reldyn']['dynamics']);
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET plugin_extended_data = $2::jsonb WHERE npc_name = $1',
            [$name, json_encode($ped)]);
    }

    /** Dimension positions as a save holds them; baselines stay the temperament's. */
    private function dims(array $x): array
    {
        $out = [];
        foreach ($x as $dim => $v) $out[$dim] = ['x' => $v];
        return $out;
    }

    /** The plugin's location context line (eventlog infoloc) for where the party stands. */
    private function arrive(string $context, string $party): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['infoloc', $context, 'pending', (int) $this->gamets, $this->realTs, (int) $this->gamets, $party, '']);
    }

    /**
     * One player line to $npc in main.php's order: prerequest, context_pre (HERIKA_PERS, the
     * <character> block), context (contextDataFull, after the dialogue history).
     * Returns ['pers' => what RelDyn added to HERIKA_PERS, 'blocks' => RelDyn's context messages].
     */
    private function turn(string $npc, string $line): array
    {
        $this->gamets += 10 * self::MINUTE;
        $this->realTs += 30;
        $party = '|' . $npc . '|' . self::PLAYER . '|';
        $request = ['inputtext', (string) $this->realTs, (string) (int) $this->gamets, self::PLAYER . ": {$line}"];
        $run = function (string $hook) use ($npc, $request, $party): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $party;
            $GLOBALS['CACHE_PARTY'] = $party;
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
            $path = __DIR__ . "/../../ext/relationship_dynamics/{$hook}";
            if (is_file($path)) {
                (static function () use ($path): void { require $path; })();
            }
            RelationshipDynamics::endRequest();
        };
        $run('prerequest.php');
        $this->clearReldynGlobals();
        $GLOBALS['HERIKA_PERS'] = self::BIO;
        $GLOBALS['contextDataFull'] = [];
        $run('context_pre.php');
        $pers = substr((string) $GLOBALS['HERIKA_PERS'], strlen(self::BIO));
        $run('context.php');
        $blocks = array_map(fn($m) => (string) ($m['content'] ?? ''), $GLOBALS['contextDataFull'] ?? []);
        $this->clearReldynGlobals();
        return ['pers' => $pers, 'blocks' => $blocks];
    }

    // ------------------------------------------------------------------ the six states

    /** @return array<string, array{npc: string, pers: string, blocks: string[]}> */
    private function renderStates(): array
    {
        // 1. A stranger: never met, core affinity 0.
        $this->npc('Brelyna Maryon', 'DarkElfRace', 'Sorcerer', ['CollegeofWinterholdFaction'],
            ['destruction' => 45, 'conjuration' => 40], 0, 'professional');

        // 2. A friend: Lydia, housecarl, core friend tier, warm and trusting, one memory of the road.
        $this->npc('Lydia', 'NordRace', 'Warrior', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
            ['onehanded' => 60, 'heavyarmor' => 58, 'block' => 50], 45, 'platonic', [
                'profile_overrides' => ['temperament' => 'Stoic', 'attachment_style' => 'secure', 'traits' => []],
                'dimensions' => $this->dims(['trust' => 64.0, 'comfort' => 70.0, 'warmth' => 64.0, 'respect' => 62.0, 'maturity' => 66.0]),
                'dimensional_memory' => [
                    ['dim' => 'trust', 'delta' => 9.0, 'abs_delta' => 9.0, 'reason' => 'held the line with her at Bleak Falls Barrow',
                     'bond' => self::PLAYER, 'ts' => date('Y-m-d H:i:s', $this->realTs - 3 * 86400)],
                ],
            ]);

        // 3. An angry, immature partner: jealous of Camilla, a fight still open, pulse up.
        $this->npc('Ysolda', 'NordRace', 'Citizen', [], ['speech' => 45], 50, 'romantic', [
            'profile_overrides' => ['temperament' => 'Jealous', 'attachment_style' => 'anxious', 'traits' => ['insecure']],
            'dimensions' => $this->dims(['maturity' => 14.0, 'warmth' => 24.0, 'comfort' => 28.0, 'trust' => 30.0,
                'arousal' => 88.0, 'valence' => -72.0, 'resentment' => 66.0, 'passion' => 64.0]),
            'passion' => 64.0,
            'jealousy_anger' => 74.0,
            'jealousy_trigger_npc' => 'Camilla Valerius',
            'in_conflict' => true,
            'conflict_positive_count' => 0,
        ]);

        // 4. A mature partner on probation: Mjoll said what she needs; now she watches.
        $this->npc('Mjoll the Lioness', 'NordRace', 'Warrior', ['CurrentFollowerFaction'],
            ['onehanded' => 70, 'block' => 60, 'heavyarmor' => 55], 60, 'romantic', [
                'profile_overrides' => ['temperament' => 'Independent', 'attachment_style' => 'secure', 'traits' => []],
                'dimensions' => $this->dims(['maturity' => 80.0, 'warmth' => 58.0, 'trust' => 55.0, 'comfort' => 55.0]),
            ]);

        // 5. A friendzoned admirer: Camilla thinks the world of the player, feels no romantic pull.
        $this->npc('Camilla Valerius', 'ImperialRace', 'Citizen', [], ['speech' => 40], 60, 'platonic', [
            'profile_overrides' => ['temperament' => 'Romantic', 'attachment_style' => 'secure', 'traits' => []],
            'attraction_overrides' => ['gender_pref' => 'homosexual'],
            'dimensions' => $this->dims(['respect' => 80.0, 'trust' => 62.0, 'warmth' => 66.0, 'comfort' => 64.0]),
        ]);

        // 6. Aela in the library: the Companions' huntress in the Arcanaeum.
        $this->npc('Aela the Huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 10, 'platonic');

        $out = [];
        $out['stranger'] = ['npc' => 'Brelyna Maryon'] + $this->turn('Brelyna Maryon', 'Excuse me, is this the College?');
        $this->turn('Lydia', 'Ready to head out?');
        $out['friend'] = ['npc' => 'Lydia'] + $this->turn('Lydia', 'You did well back there.');
        $out['angry_immature_partner'] = ['npc' => 'Ysolda'] + $this->turn('Ysolda', 'Ysolda, can we talk?');

        // Mjoll: the first request creates her fulfillment state; the save then holds a boundary
        // she stated a game day ago (probation for a week, no good day yet).
        $this->turn('Mjoll the Lioness', 'Morning, Mjoll.');
        $now = $this->gamets;
        $this->patchDynamics('Mjoll the Lioness', function (array $d) use ($now): array {
            $this->assertIsArray(RelDynFulfillment::pairState($d) ?? null, 'the first request created the fulfillment state');
            $f = RelDynFulfillment::pairState($d);
            $f['boundary'] = ['state' => 'probation', 'decided_gamets' => $now - 2 * self::DAY,
                'started_gamets' => $now - self::DAY, 'until_gamets' => $now + 6 * self::DAY, 'streak' => 0];
            RelDynFulfillment::setPairState($d, RelDynFulfillment::PLAYER, $f);
            return $d;
        });
        $out['mature_partner_on_probation'] = ['npc' => 'Mjoll the Lioness'] + $this->turn('Mjoll the Lioness', 'I brought you something.');

        $this->turn('Camilla Valerius', 'Hello, Camilla.');
        $out['friendzoned_admirer'] = ['npc' => 'Camilla Valerius'] + $this->turn('Camilla Valerius', 'You look lovely today.');

        $aela = '|Aela the Huntress|' . self::PLAYER . '|';
        $this->arrive('(Context location: The Arcanaeum ,Hold: Winterhold, Buildings to go:, Current Date in Skyrim World: Wednesday, 10:00 AM, 19th of Last Seed, 4E 201, current weather: outdoors it is Snowing)', $aela);
        $this->turn('Aela the Huntress', 'Quiet in here, isn\'t it?');
        $this->gamets += self::HOUR;
        $this->realTs += 120;
        $this->arrive('(Context location: The Arcanaeum ,Hold: Winterhold, Buildings to go:, Current Date in Skyrim World: Wednesday, 11:00 AM, 19th of Last Seed, 4E 201, current weather: outdoors it is Snowing)', $aela);
        $out['aela_in_the_library'] = ['npc' => 'Aela the Huntress'] + $this->turn('Aela the Huntress', 'Just a few more books.');
        return $out;
    }

    /** Everything RelDyn put in front of the LLM for one state. */
    private function llmText(array $state): string
    {
        return trim($state['pers'] . "\n" . implode("\n", $state['blocks']));
    }

    /**
     * Estimated LLM tokens: word pieces (a lowercase word of up to 7 letters is one token,
     * longer ones and CAPS / mixed-case runs cost more), punctuation half a token each.
     * The same estimate RelDynFelt::estimateTokens() budgets with.
     */
    private static function tokens(string $s): int
    {
        preg_match_all("/[A-Za-z]+|[^\sA-Za-z]/", $s, $m);
        $t = 0.0;
        foreach ($m[0] as $piece) {
            if (ctype_alpha($piece)) {
                $len = strlen($piece);
                $t += (strtolower($piece) === $piece || ucfirst(strtolower($piece)) === $piece)
                    ? 1 + intdiv($len - 1, 7) : (int) ceil($len / 3);
            } else {
                $t += 0.5;
            }
        }
        return (int) ceil($t);
    }

    // ------------------------------------------------------------------ tests

    /**
     * The token report (before/after for the review): prints each state's RelDyn prompt and
     * its estimated size. Runs against any version of the hooks.
     */
    public function testReportsTheRelDynPromptPerState(): void
    {
        $states = $this->renderStates();
        $total = 0;
        foreach ($states as $label => $s) {
            $text = $this->llmText($s);
            $n = self::tokens($text);
            $total += $n;
            fwrite(STDERR, "\n===== {$label} ({$s['npc']}): ~{$n} tokens, " . strlen($text) . " chars\n{$text}\n");
        }
        fwrite(STDERR, "\n===== total ~{$total} tokens over " . count($states) . " states\n");
        $this->assertCount(6, $states);
    }

    public function testNoNumbersPercentagesOrStatedFeelingsReachTheLlm(): void
    {
        foreach ($this->renderStates() as $label => $s) {
            $text = $this->llmText($s);
            $this->assertNotSame('', $text, "{$label}: RelDyn steers every one of these states");
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$label}: a digit reached the LLM:\n{$text}");
            $this->assertStringNotContainsString('%', $text, "{$label}: a percentage reached the LLM");
            $this->assertDoesNotMatchRegularExpression('/\byou\s+feel\b/i', $text, "{$label}: 'you feel' phrasing:\n{$text}");
            $this->assertDoesNotMatchRegularExpression('/\bfeels?\s+that\b/i', $text, "{$label}: 'feels that' phrasing:\n{$text}");
            $this->assertDoesNotMatchRegularExpression('/\b(trusts?|loves?|likes?|respects?|resents?)\s+(the player|'
                . preg_quote(self::PLAYER, '/') . ')\b/i', $text, "{$label}: a stated feeling toward the player:\n{$text}");
            // No dimension label or mechanic: 'Trust: ...', 'comfort=', 'affinity gains', '/100', 'internal state'
            $this->assertDoesNotMatchRegularExpression(
                '/\b(affinity|trust|comfort|respect|resentment|passion|warmth|maturity|arousal|valence|jealousy)\s*[:=(]/i',
                $text, "{$label}: a dimension label:\n{$text}");
            $this->assertDoesNotMatchRegularExpression('/affinity gains|walkaway|internal state|\/\s*100|TRUE STATE|PERFORMED STATE/i',
                $text, "{$label}: a mechanic reached the LLM:\n{$text}");
        }
        $this->assertSame([], $this->db->failures, 'the schema holds every table the production path touches');
    }

    public function testOneCompactBlockAtRecencyAndKnowledgeOfPlayerInTheCharacterBlock(): void
    {
        foreach ($this->renderStates() as $label => $s) {
            $this->assertCount(1, $s['blocks'], "{$label}: RelDyn adds exactly one block after the dialogue history:\n"
                . implode("\n---\n", $s['blocks']));
            $this->assertMatchesRegularExpression('~^<subtext>\n.*\n</subtext>$~s', $s['blocks'][0], $label);
            $this->assertSame(1, substr_count($s['pers'], '<knowledge_of_player>'), "{$label}: one knowledge_of_player in <character>");
            // No line is said twice (primacy and recency carry different lines).
            $lines = array_filter(array_map('trim', explode("\n", $s['pers'] . "\n" . $s['blocks'][0])),
                fn($l) => str_starts_with($l, '- '));
            $this->assertSame(array_values(array_unique($lines)), array_values($lines), "{$label}: a line said twice");
        }
    }

    public function testBehaviourDiffersAcrossTheStates(): void
    {
        $states = $this->renderStates();
        $text = array_map(fn($s) => $this->llmText($s), $states);

        // The stranger knows nothing of the player and gets no bond behaviour.
        $this->assertMatchesRegularExpression('/stranger/i', $states['stranger']['pers']);
        $this->assertStringNotContainsString(self::PLAYER, $states['stranger']['pers'], 'a stranger does not know the name');
        $this->assertLessThanOrEqual(2, substr_count($states['stranger']['blocks'][0] ?? '', "\n- "), 'a stranger: bare minimum');

        // The friend: warm, trusting behaviour and the shared memory.
        $this->assertMatchesRegularExpression('/knows ' . self::PLAYER . ' well/', $states['friend']['pers']);
        $this->assertStringContainsString('Bleak Falls', $text['friend']);
        $this->assertMatchesRegularExpression('/personal matters|turns to them|seeks them out|lingers/i', $text['friend']);

        // The angry, immature partner: CAPS and '!' from the high arousal, degraded text from the
        // low maturity, the rival named, clipped answers.
        $angry = $text['angry_immature_partner'];
        $this->assertMatchesRegularExpression('/\b[A-Z]{4,}\b/', $angry, 'high arousal: CAPS');
        $this->assertStringContainsString('!', $angry, 'high arousal: exclamation');
        $this->assertMatchesRegularExpression('/\.\.|_|\b[a-z]+[A-Z][a-z]*[A-Z]/', $angry, 'low maturity: the text itself degrades');
        $this->assertStringContainsString('Camilla', $angry);
        $this->assertMatchesRegularExpression('/brittle|clipped|accusation/i', $angry);

        // The mature partner on probation: calm, watching whether it changes; no degradation, no CAPS.
        $mjoll = $text['mature_partner_on_probation'];
        $this->assertStringContainsString('watching whether it really changes', $mjoll);
        $this->assertDoesNotMatchRegularExpression('/\b[A-Z]{4,}\b|!|_/', preg_replace('~</?[a-z_]+>~', '', $mjoll),
            'a mature, calm NPC: plain text');

        // The friendzoned admirer: warm respect, romance deflected.
        $this->assertMatchesRegularExpression('/deflect/i', $text['friendzoned_admirer']);
        $this->assertMatchesRegularExpression('/counsel|follows their lead|speaks well of them|measures themselves/i', $text['friendzoned_admirer']);

        // Aela in the Arcanaeum: restless, wants out.
        $this->assertMatchesRegularExpression('/restless|door|anywhere but here/i', $text['aela_in_the_library']);

        // Pairwise: the recency blocks share little (word-set overlap below one half).
        $words = [];
        foreach ($states as $label => $s) {
            $body = preg_replace('~^<subtext>\n[^\n]*\n~', '', $s['blocks'][0] ?? '');
            preg_match_all('/[a-z\']+/', strtolower($body), $m);
            $words[$label] = array_unique($m[0]);
        }
        $labels = array_keys($words);
        foreach ($labels as $i => $a) {
            foreach (array_slice($labels, $i + 1) as $b) {
                $union = count(array_unique(array_merge($words[$a], $words[$b])));
                $overlap = $union > 0 ? count(array_intersect($words[$a], $words[$b])) / $union : 0.0;
                $this->assertLessThan(0.5, $overlap, "{$a} vs {$b}: the behaviour barely differs");
            }
        }
    }

    public function testTheRelDynPromptStaysInsideTheTierBudget(): void
    {
        $cfg = RelDynFelt::config();
        foreach ($this->renderStates() as $label => $s) {
            $tier = RelationshipDynamics::getContextTier($this->dynamics($s['npc']));
            $budget = intval($cfg['tier_token_budget'][$tier]) + intval($cfg['knowledge_token_budget']);
            $n = self::tokens($this->llmText($s));
            $this->assertSame(RelDynFelt::estimateTokens($this->llmText($s)), $n, 'the test and the budget count alike');
            $this->assertLessThanOrEqual($budget, $n, "{$label} (tier {$tier}): ~{$n} tokens over the budget {$budget}");
        }
    }

    public function testJevGetsTheExplicitNumbers(): void
    {
        $this->renderStates();
        $d = $this->dynamics('Ysolda');
        $jev = RelationshipDynamics::jevStateBlock('Ysolda');

        $this->assertSame('Ysolda', $jev['npc']);
        $this->assertEqualsWithDelta(RelationshipDynamics::getCoreAffinity($d), $jev['affinity'], 0.001, 'core units');
        foreach (['trust', 'comfort', 'respect', 'maturity', 'resentment'] as $dim) {
            $this->assertEqualsWithDelta(floatval($d['dimensions'][$dim]['x']), $jev[$dim], 0.001, $dim);
        }
        // warmth is derived (roadmap derived-warmth): sqrt(passion x comfort) and what is held on it
        $this->assertEqualsWithDelta(RelDynPassion::warmth($d, false), $jev['warmth'], 0.01, 'warmth');
        $this->assertEqualsWithDelta(RelationshipDynamics::getPassion($d), $jev['passion'], 0.001);
        $this->assertEqualsWithDelta(floatval($d['jealousy_anger']), $jev['jealousy'], 0.001);
        $this->assertGreaterThan(50.0, $jev['jealousy']);
        $this->assertSame('anxious', $jev['attachment']);
        $this->assertSame('Jealous', $jev['temperament']);
        $this->assertContains($jev['weather'], ['sunny', 'clear', 'overcast', 'stormy']);
        $this->assertTrue($jev['open_conflict']);
        $this->assertSame('Camilla Valerius', $jev['jealousy_rival']);
        $this->assertArrayHasKey('boundary', $jev);
        $this->assertArrayHasKey('walkaway', $jev);
        $this->assertIsFloat($jev['fulfillment']['band']);
        // Decisions §13: the curve, the spark and its rate, the rate above it, the hard zero
        $att = $d['_attraction'];
        $this->assertTrue($jev['attraction']['enabled']);
        $this->assertEqualsWithDelta(floatval($att['passion']['curve']), $jev['attraction']['curve'], 1e-4, 'the passion curve');
        $this->assertEqualsWithDelta(floatval($att['spark']), $jev['attraction']['spark'], 1e-4, 'the spark (passion points)');
        $this->assertEqualsWithDelta(floatval($att['spark_mult']), $jev['attraction']['spark_mult'], 1e-4, 'below the spark');
        $this->assertSame($att['hard_zero'], $jev['attraction']['hard_zero']);
        $this->assertSame(!empty($att['attracted']), $jev['attraction']['attracted']);
        $this->assertEqualsWithDelta(floatval($att['passion_mult']), $jev['attraction']['passion_mult'], 1e-4, 'passion-gain multiplier');
        $this->assertEqualsWithDelta(floatval($att['respect_mult']), $jev['attraction']['respect_mult'], 1e-4, 'respect-gain multiplier');
        $this->assertMatchesRegularExpression('/curve=\d\.\d+(\[[^\]]*\])? spark=\d+(\.\d)?\(x\d\.\d+\) passion_mult=\d\.\d+/', $jev['text']);
        $this->assertArrayHasKey('units', $jev);
        // The compact text carries the numbers (Jev conditions on them directly).
        $this->assertMatchesRegularExpression('/affinity=-?\d/', $jev['text']);
        $this->assertMatchesRegularExpression('/jealousy=\d+/', $jev['text']);
        $this->assertStringContainsString('conflict=open', $jev['text']);
        $this->assertLessThan(700, strlen($jev['text']), 'compact');

        // Mjoll's probation is visible to Jev as state, not prose.
        $this->assertSame('probation', RelationshipDynamics::jevStateBlock('Mjoll the Lioness')['boundary']);
        // An NPC RelDyn never saw: numbers at their defaults, nothing invented.
        $unknown = RelationshipDynamics::jevStateBlock('Nobody In Particular');
        $this->assertEqualsWithDelta(0.0, $unknown['affinity'], 0.001);
        $this->assertFalse($unknown['open_conflict']);
    }

    public function testContextPreCanBeSwitchedOffAndTheRecencyBlockCarriesEverything(): void
    {
        $this->config(['context_pre_enabled' => false]);
        $states = $this->renderStates();
        foreach ($states as $label => $s) {
            $this->assertSame('', $s['pers'], "{$label}: nothing in <character> with context_pre_enabled off");
            $this->assertCount(1, $s['blocks'], $label);
            $this->assertDoesNotMatchRegularExpression('/\d/', $s['blocks'][0], $label);
        }
        $this->assertStringContainsString('watching whether it really changes', $states['mature_partner_on_probation']['blocks'][0]);
    }
}
