<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynSubstancesBedsPgDb
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
 * drunk-state and addiction end to end on the four test beds (standing rule): Aela the Huntress,
 * Ashe (Serene's hand-set vector, never read; nothing of her story here), Muiri (toxic, nudged
 * fearful) and Lynly Star-Sung (shy bard), on CHIM 3.4.1 core-shaped rows through the real hooks
 * (prerequest -> context_pre -> context -> postrequest), the real eval producer and worker (the
 * eval LLM stubbed at the connector boundary), core's own consume lines in the eventlog (the
 * Consume action's infoaction "<NPC> consumes <item>.") and core's diarylog. No MinAI, no LLM call.
 *   - an evening of mead: each bed's maturity drops by the design's steps (clamped at her own
 *     floor), the stage shows as a feeling, her attraction floors fall by fifteen while she is
 *     drunk; a night's sleep later every one of them is exactly herself again;
 *   - the flirting done drunk goes to the night's ledger; the sober diary judges it as who she is
 *     (her own attraction to the player; a shallow diary judges nothing);
 *   - daily skooma makes a dependence: craving and withdrawal show as feelings, the maturity
 *     ceiling holds until the player's intervention; a healing potion instead eases it.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynSubstancesTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 150;   // game day of the hello
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const MARE = '(Context location: The Bannered Mare ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Fredas, 8:00 PM, 17th of Last Seed, 4E 201, current weather: indoors)';

    private string $dsn;
    private string $schema;
    private RelDynSubstancesBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** npc => label => key => felt text the context hook put in front of the LLM */
    private array $felt = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_subst_' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        // data/database_default.sql eventlog and diarylog (rowid from its own sequence)
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_query($admin, "CREATE TABLE diarylog (ts text NOT NULL, sess character varying(1024), topic text, content text,
            tags text, people text, localts bigint NOT NULL, location text, gamets bigint NOT NULL, rowid bigserial NOT NULL)");
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

        $this->db = new RelDynSubstancesBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE', 'HERIKA_PERS'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the calls themselves are stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdsubstbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_substances_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};
        RelDynDiary::$launcher = function (): void {};
        RelDynDiary::$llm = function () { $this->llmCalls++; return null; };
        $this->seed();
    }

    protected function tearDown(): void
    {
        RelDynTraitRead::$launcher = null;
        RelDynTraitRead::$llm = null;
        RelDynTraitRead::reset();
        RelDynEval::$launcher = null;
        RelDynDiary::$launcher = null;
        RelDynDiary::$llm = null;
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

    /** Core rows (friends of the player, core affinity 40), voice types, placeholder templates and the seed's reads. */
    private function seed(): void
    {
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
                     'relationships' => [self::PLAYER => ['aff' => 40, 'type' => 'friend']]])]);
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
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('The Bannered Mare', 'Whiterun', 'Inn,', 1, 'WhiterunWorld')");
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

    /** One player line to $npc through the real hooks at $gamets, logged as core logs it (input row, then the reply). */
    private function turn(string $npc, string $line, int $gamets, string $label): void
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->people());
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
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
            if ($hook === 'context_pre.php') {
                $GLOBALS['contextDataFull'] = [];
                $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
            }
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context.php') $this->felt[$npc][$label] = RelDynFelt::lastRendered();
            RelationshipDynamics::endRequest();
        }
        $this->clearReldynGlobals();
        $this->realTs += 60;
    }

    /** $npc drinks / takes $item: the Consume action's own line (Commands.cpp), as core logs it. */
    private function consume(string $npc, string $item, int $gamets): void
    {
        $this->event('infoaction', "{$npc} consumes {$item}.", $gamets, $this->people());
    }

    /** A page in $npc's diary as core's generateFollowerDiary stores it (people = the NPC). */
    private function diary(string $npc, int $gamets, string $content): void
    {
        pg_query_params($this->db->link,
            'INSERT INTO diarylog (ts, gamets, topic, content, tags, people, location, sess, localts)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)',
            [(string) $this->realTs, $gamets, 'Night (Auto-diary: goodnight)', $content, 'Auto-diary,goodnight',
             $npc, 'The Bannered Mare', (string) $this->realTs, $this->realTs]);
    }

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    private function all(): array
    {
        $out = [];
        foreach (array_keys(self::BEDS) as $npc) $out[$npc] = $this->dynamics($npc);
        return $out;
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x'] ?? 0);
    }

    private static function own(array $d, string $dim): float
    {
        return RelationshipDynamics::driftSampleValue($d, $dim) ?? 0.0;
    }

    /**
     * The eval LLM at the connector boundary: flirting is a romantic exchange (touch, intent 2);
     * the plea about the skooma is help (and a little maturity); anything else is small talk.
     */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $content = (string) $messages[1]['content'];
            $exchange = substr($content, (int) strpos($content, 'THIS EXCHANGE'));
            $flirt = str_contains($exchange, 'prettiest');
            $plea = str_contains($exchange, 'put the skooma down');
            preg_match('/Talking to ([^)]+)\)/', $exchange, $m);
            $npc = trim($m[1] ?? 'her');
            return json_encode([
                'signals' => ['affinity' => $flirt ? 3 : 0, 'trust' => 0, 'comfort' => $flirt ? 4 : ($plea ? 1 : 0), 'respect' => 0,
                              'passion' => $flirt ? 5 : 0, 'maturity' => $plea ? 3 : 0],
                'tags' => $flirt ? ['touch'] : ($plea ? ['help'] : []),
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'significance' => $flirt || $plea ? 0.5 : 0.1,
                'summary' => $flirt ? 'The player flirted over the mead.' : ($plea ? "The player begged {$npc} to put the skooma down." : 'Small talk.'),
                'romantic_intent' => $flirt ? 2 : 0,
            ]);
        };
    }

    /** $line to each of $npcs from $gamets (one game minute apart), then the real eval worker. */
    private function round(array $npcs, string $line, int $gamets, string $label): void
    {
        $i = 0;
        foreach ($npcs as $npc) $this->turn($npc, $line, $gamets + 60 * $i++, $label);
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
    }

    /** The evening: hello, then five rounds of mead (a mug each, a word each); the flirting from the third. Returns the last turn's gamets. */
    private function evening(bool $flirt): int
    {
        $beds = array_keys(self::BEDS);
        $t = self::at(self::N0, 19.0);
        $this->event('infoloc', self::MARE, $t - 100, $this->people());
        $this->round($beds, 'Evening, friends.', $t, 'hello');
        for ($r = 1; $r <= 5; $r++) {
            $t = self::at(self::N0, 19.0) + (int) round($r * self::HOUR / 6);   // a mug every ten game minutes
            foreach ($beds as $npc) $this->consume($npc, 'Nord Mead', $t);
            $line = ($flirt && $r >= 3) ? 'You are the prettiest thing in Whiterun tonight.' : 'Another round!';
            $this->round($beds, $line, $t + 30, "mead_{$r}");
        }
        // One more song after the fifth mug (her postrequest counted it: this context sees all five)
        $t += (int) round(self::HOUR / 12);
        $this->round($beds, $flirt ? 'You are the prettiest thing in Whiterun tonight.' : 'One more song!', $t, 'song');
        return $t;
    }

    private function assertFeelingsNotNumbers(): void
    {
        foreach ($this->felt as $npc => $turns) {
            foreach ($turns as $label => $lines) {
                foreach ($lines as $key => $text) $this->assertDoesNotMatchRegularExpression('/\d/', (string) $text, "{$npc} {$label} {$key}");
            }
        }
    }

    private function assertClean(): void
    {
        $this->assertSame([], $this->db->failures);
        $this->assertSame(0, $this->llmCalls, 'no LLM call (the eval is stubbed at its boundary)');
        $log = (string) file_get_contents($this->errorLog);
        $this->assertStringNotContainsString('PHP Warning', $log);
        $this->assertStringNotContainsString('PHP Fatal', $log);
    }

    // ------------------------------------------------------------------ the evening

    /**
     * Five mugs of mead: every bed's maturity falls by the design's steps from her own (clamped at
     * zero), the drunk stage speaks as a feeling, her attraction floors fall by fifteen and her
     * openness loosens; overnight it all clears on the game clock and each is exactly herself.
     */
    public function testAnEveningOfMeadOnEveryBed(): void
    {
        $beds = array_keys(self::BEDS);
        $this->round($beds, 'Evening, friends.', self::at(self::N0, 18.0), 'sober');
        $sober = $this->all();
        $last = $this->evening(false);
        $drunk = $this->all();
        foreach ($beds as $npc) {
            $s = $drunk[$npc]['_substances'];
            $own = self::own($drunk[$npc], 'maturity');
            $without = $drunk[$npc];
            unset($without['_substances']);
            $others = RelationshipDynamics::heldTemporaryOffset($without, 'maturity');   // the place, the weather...
            $this->assertSame(5, count($s['drinks']), "{$npc}: five mugs, her own consume lines");
            $this->assertSame('drunk', $s['stage'], $npc);
            $expected = -min($own + $others, RelDynSubstances::maturityDrop(floatval($s['effective']), [1, 3, 8, 10, 10]));
            $this->assertEqualsWithDelta($expected, $s['held']['maturity'], 0.01, "{$npc}: the design's steps from where she is");
            $this->assertEqualsWithDelta($own + $others + $s['held']['maturity'], self::x($drunk[$npc], 'maturity'), 1e-6, "{$npc}: held with the rest");
            $this->assertEqualsWithDelta(self::own($sober[$npc], 'maturity'), $own, 3.0, "{$npc}: her own maturity is not the drink (only the table's small bleed)");
            $this->assertLessThan(self::own($sober[$npc], 'maturity'), floatval($drunk[$npc]['dimensions']['maturity']['baseline']) + 1e-9,
                "{$npc}: the mead's permanent cost (the item table) stays on her baseline");
            $this->assertArrayHasKey('substance_drunk', $this->felt[$npc]['mead_5'], "{$npc}: the drink speaks as a feeling");
            $this->assertStringContainsString('merry', $this->felt[$npc]['mead_5']['substance_drunk'], "{$npc}: four mugs in her at that turn");
            $this->assertStringContainsString('is drunk', $this->felt[$npc]['song']['substance_drunk'], $npc);
            $this->assertArrayNotHasKey('substance_drunk', $this->felt[$npc]['sober'], $npc);
            // Her attraction, drunk: every floor fifteen lower, the band never tighter
            $bands = ['low' => 0, 'medium' => 1, 'high' => 2];
            $this->assertGreaterThanOrEqual($bands[$sober[$npc]['_attraction']['openness']], $bands[$drunk[$npc]['_attraction']['openness']], $npc);
            $withoutDrink = $drunk[$npc];
            unset($withoutDrink['_substances']);
            $soberDef = RelDynAttraction::definition($npc, $withoutDrink);
            $drunkDef = RelDynAttraction::definition($npc, $drunk[$npc]);
            foreach ($soberDef['floors'] as $pillar => $floor) {
                $this->assertEqualsWithDelta(max(1.0, $floor - 15.0), $drunkDef['floors'][$pillar], 1e-6, "{$npc} {$pillar}: the floor fifteen lower");
            }
            $this->assertSame($soberDef['openness'], $drunkDef['openness_sober'], "{$npc}: the hysteresis keeps her sober band");
            if (!empty($drunk[$npc]['_attraction']['passion']['units'])) $this->assertLessThan(0.0, array_sum(array_map(fn($u) => floatval($u['floor']), (array) ($drunk[$npc]['_attraction']['passion']['units'] ?? [])))
                - array_sum(array_map(fn($u) => floatval($u['floor']), (array) ($sober[$npc]['_attraction']['passion']['units'] ?? []))) + 1e-9,
                "{$npc}: the Matrix's summary read the lowered floors");
            $j = RelDynJev::state($npc, $drunk[$npc], $last);
            $this->assertSame('drunk', $j['substances']['stage']);
            $this->assertStringContainsString('substances=drunk', $j['text']);
        }
        // Divergence: who she is decides where the drink leaves her (her own maturity, clamped at zero)
        $left = array_map(fn($d) => round(self::x($d, 'maturity'), 2), $drunk);
        $this->assertGreaterThan(1, count(array_unique($left)), 'each bed is left somewhere else: ' . json_encode($left));

        // A night's sleep later (the game clock runs on): every bed is exactly herself again
        $this->round($beds, 'Morning.', self::at(self::N0 + 1, 8.0), 'morning');
        foreach ($this->all() as $npc => $d) {
            $this->assertFalse(RelDynSubstances::intoxicated($d), $npc);
            $this->assertArrayNotHasKey('held', $d['_substances'], $npc);
            $this->assertEqualsWithDelta(self::own($d, 'maturity') + RelationshipDynamics::heldTemporaryOffset($d, 'maturity'), self::x($d, 'maturity'), 1e-6,
                "{$npc}: nothing of the drink is held (what is held is the place and the weather)");
            $this->assertNull(RelDynSubstances::shifts($d), $npc);
            $withoutDrink = $d;
            unset($withoutDrink['_substances']);
            $this->assertSame(RelDynAttraction::definition($npc, $withoutDrink)['floors'], RelDynAttraction::definition($npc, $d)['floors'], "{$npc}: her own floors again");
            $this->assertArrayNotHasKey('substance_drunk', $this->felt[$npc]['morning'], $npc);
        }
        $this->assertFeelingsNotNumbers();
        $this->assertClean();
    }

    /**
     * The flirting done drunk joins the night's ledger; the next morning's diary page is read by
     * the sober self: a bed not drawn to the player regrets her share (resentment_self rises, the
     * night's gains are taken back x2 of it), one whose own maturity keeps her diary shallow
     * judges nothing. Each bed's verdict is her own attraction to the player.
     */
    public function testTheSoberDiaryJudgesTheDrunkFlirtingAsWhoEachBedIs(): void
    {
        $beds = array_keys(self::BEDS);
        $this->evening(true);
        $night = $this->all();
        foreach ($night as $npc => $d) {
            $nights = $d['_substances']['nights'] ?? [];
            $this->assertCount(1, $nights, "{$npc}: the drunk self's night is in the ledger");
            $n = array_values($nights)[0];
            $this->assertTrue($n['romantic'], $npc);
            $this->assertGreaterThan(0.0, $n['gains']['comfort'] ?? 0.0, "{$npc}: the flirting's comfort, from the third mug on");
        }
        // Past midnight: sober (the ledger closes, the night waits for the diary as a moment)
        $this->round($beds, 'Good night.', self::at(self::N0 + 1, 2.0), 'late');
        foreach ($this->all() as $npc => $d) {
            $this->assertFalse(RelDynSubstances::intoxicated($d), $npc);
            $this->assertTrue(array_values($d['_substances']['nights'])[0]['closed'], $npc);
        }
        $before = $this->all();
        foreach ($beds as $npc) $this->diary($npc, self::at(self::N0 + 1, 3.0), 'A long night at the Mare. My head hurts.');
        $this->round($beds, 'Morning.', self::at(self::N0 + 1, 9.0), 'morning');
        $after = $this->all();
        $endorsed = [];
        foreach ($beds as $npc) {
            $d = $after[$npc];
            $this->assertArrayNotHasKey('nights', $d['_substances'], "{$npc}: the night is spent");
            $last = $d['_substances']['last_sober'];
            $depth = RelDynDiary::depth(self::own($before[$npc], 'maturity'));
            $this->assertSame($depth, $last['depth'], $npc);
            if ($depth === 'shallow') {
                $this->assertNull($last['endorsed'], "{$npc}: a shallow diary judges nothing");
                continue;
            }
            $E = RelDynSubstances::endorsement($before[$npc], ['romantic' => true]);
            $this->assertEqualsWithDelta($E, $last['endorsed'], 1e-6, "{$npc}: her own attraction to the player decides");
            $endorsed[$npc] = $last['endorsed'];
            if ($E < 1.0) {
                $this->assertGreaterThan(self::x($before[$npc], 'resentment_self'), self::x($d, 'resentment_self'), "{$npc}: the shame of a night she does not stand behind");
            } else {
                $this->assertEqualsWithDelta(self::x($before[$npc], 'resentment_self'), self::x($d, 'resentment_self'), 1e-6, "{$npc}: nothing to regret");
            }
        }
        // Divergence (the Matrix decides, through each bed's own lens on the same player): with a
        // level-twenty all-rounder, Aela and Ashe are not drawn to the player and regret it, Muiri and Lynly are
        $this->assertLessThan(0.5, $endorsed['Aela the Huntress']);
        $this->assertLessThan(0.5, $endorsed['Ashe']);
        $this->assertEqualsWithDelta(1.0, $endorsed['Muiri'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $endorsed['Lynly Star-Sung'], 1e-9);
        $this->assertFeelingsNotNumbers();
        $this->assertClean();
    }

    // ------------------------------------------------------------------ addiction

    /**
     * Five evenings of skooma on every bed make a dependence, and a day without it brings the
     * craving and the withdrawal for all four; then each goes her own way: Muiri uses again (the
     * withdrawal lifts, and the relapse shames her: she had set herself to stay clean), Lynly takes a
     * healing potion instead (harm reduction eases it by half, and it is no use), the player pleads
     * with Aela (an intervention: her maturity grows past the addiction's ceiling), Ashe goes
     * without (the full withdrawal, and no growth while she depends on it).
     */
    public function testSkoomaMakesADependenceAndEachBedGoesHerOwnWay(): void
    {
        $beds = array_keys(self::BEDS);
        $this->event('infoloc', self::MARE, self::at(self::N0, 18.0), $this->people());
        $this->round($beds, 'Evening.', self::at(self::N0, 18.0), 'hello');
        for ($day = 0; $day < 5; $day++) {
            $t = self::at(self::N0 + $day, 20.0);
            foreach ($beds as $npc) $this->consume($npc, 'Skooma', $t);
            $this->round($beds, 'Careful with that.', $t + 30, "skooma_{$day}");
        }
        foreach ($this->all() as $npc => $d) {
            $this->assertTrue(RelDynSubstances::addicted($d), "{$npc}: five evenings of it");
            $this->assertSame(5, $d['_substances']['use']['skooma']['uses'], $npc);
            $probe = $d;
            $this->assertLessThan(1.0, RelDynSubstances::onConsume('probe', $probe, 'skooma', self::at(self::N0 + 4, 21.0))['spike_mult'], "{$npc}: tolerance");
            $goal = array_values(array_filter(RelDynGoals::active($d), fn($g) => $g['type'] === 'recovery'))[0] ?? null;
            $this->assertNotNull($goal, "{$npc}: her own maturity is past twenty: the goal forms");
            $this->assertSame('clean', $goal['phase'], "{$npc}: past twenty-five she governs herself: 'Stay clean'");
        }

        // The next day, twenty-two game hours on: craving and withdrawal, felt, never numbers
        $t = self::at(self::N0 + 5, 18.0);
        $this->round($beds, 'How are you holding up?', $t, 'without');
        $without = $this->all();
        foreach ($without as $npc => $d) {
            $this->assertTrue($d['_substances']['withdrawal'], $npc);
            $this->assertLessThan(0.0, $d['_substances']['held']['comfort'], $npc);
            $this->assertGreaterThan(30.0, $d['_substances']['craving'], $npc);
            $this->assertArrayHasKey('substance_withdrawal', $this->felt[$npc]['without'], $npc);
            $this->assertStringContainsString('skooma', $this->felt[$npc]['without']['substance_withdrawal']);
            $this->assertSame(25.0, RelDynSubstances::maturityCeiling($d), $npc);
        }

        // Each her own way
        $t += (int) self::HOUR;
        $this->consume('Muiri', 'Skooma', $t);
        $this->consume('Lynly Star-Sung', 'Potion of Healing', $t);
        $this->turn('Muiri', 'You said you would stop.', $t + 30, 'way');
        $this->turn('Lynly Star-Sung', 'Drink this instead.', $t + 90, 'way');
        $this->turn('Aela the Huntress', 'Please, put the skooma down. For me.', $t + 150, 'way');
        $this->turn('Ashe', 'Get some rest.', $t + 210, 'way');
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0);
        $t += (int) self::HOUR;
        $this->round($beds, 'Evening.', $t, 'after');
        $after = $this->all();
        $held = fn(string $npc) => floatval($after[$npc]['_substances']['held']['comfort'] ?? 0.0);

        // Muiri used: the withdrawal is lifted, the relapse costs her
        $this->assertSame(0.0, $held('Muiri'));
        $this->assertGreaterThan(self::x($without['Muiri'], 'resentment_self'), self::x($after['Muiri'], 'resentment_self'), 'Muiri: the shame of it');
        $this->assertSame(1, array_values(array_filter(RelDynGoals::active($after['Muiri']), fn($g) => $g['type'] === 'recovery'))[0]['relapses']);
        // Lynly's potion: the same withdrawal, eased by half, and no use (her skooma clock runs on)
        $this->assertEqualsWithDelta($held('Ashe') / 2.0, $held('Lynly Star-Sung'), 0.3, 'harm reduction halves it');
        $this->assertSame(5, $after['Lynly Star-Sung']['_substances']['use']['skooma']['uses']);
        $this->assertTrue(RelDynJev::state('Lynly Star-Sung', $after['Lynly Star-Sung'], $t)['substances']['relief']);
        // Aela: the plea is an intervention; her maturity grows past the ceiling, Ashe's does not grow at all
        $this->assertSame(1, $after['Aela the Huntress']['_substances']['interventions'] ?? 0);
        $this->assertGreaterThan(self::own($without['Aela the Huntress'], 'maturity'), self::own($after['Aela the Huntress'], 'maturity'));
        $this->assertLessThanOrEqual(self::own($without['Ashe'], 'maturity') + 1e-6, self::own($after['Ashe'], 'maturity'), 'Ashe: no growth while she depends on it');
        $this->assertEqualsWithDelta($held('Ashe'), $held('Aela the Huntress'), 0.3, 'the plea is no potion: her withdrawal is the same');
        $this->assertLessThan(0.0, $held('Ashe'));
        $this->assertFeelingsNotNumbers();
        $this->assertClean();
    }
}
