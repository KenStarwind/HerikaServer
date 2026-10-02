<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings_view.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynPullbackBedsPgDb
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
 * Letting in and pulling back with the four test beds through the real hooks (Ken 2026-10-01: "closed off" is neither a
 * hard pass / fail nor permanent; standing rule feedback_reldyn_testbeds): Aela the Huntress, Ashe (Serene's hand-set
 * vector, never read), Muiri and Lynly Star-Sung, each a devoted platonic bond (core Player.type platonic, affinity 92:
 * the devoted tier) with no passion: the friend, the shield-sibling, the family whom the old rule read "closed" for good.
 * All on their CHIM 3.4.1 core-shaped rows and the committed seed's reads (RelDynTraitTestBedsPostgresTest has the
 * vectors). The real hooks (prerequest -> context_pre -> context -> postrequest), the real eval producer and worker
 * (the LLM stubbed at the connector boundary), the core relationship write. No LLM call.
 *
 *   a  fulfilled, clear weather: let in, no closed line, no pull-back
 *   b  a stormy, unfulfilled stretch: she pulls back for a while, it persists over several turns, and when her needs
 *      are met again (warm exchanges that deliver on them) she opens up
 *   c  the same stretch on the four beds, each as she is
 *   d  a mature (maturity 80) and an immature (maturity 25) copy of one NPC: the immature one pulls back sooner and
 *      further, and the mature one voices it
 *   e  a newly met NPC at a low tier is not let in yet
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynPullbackTestBedsPostgresTest extends TestCase
{
    private const PLAYER = 'Kaida';
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const N0 = 210;   // game day of the first evening
    private const BEDS = [
        // name => [template key, race, class, factions, skills, voice]
        'Aela the Huntress' => ['aela_the_huntress', 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
                                ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45], 'sk_femalecommander'],
        'Ashe'              => ['ashe', 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
                                ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55], null],
        'Muiri'             => ['muiri', 'BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30], 'sk_femaleyoungeager'],
        'Lynly Star-Sung'   => ['lynly_star-sung', 'NordRace', 'Bard', [], ['speech' => 40, 'onehanded' => 20], 'sk_femalenord'],
    ];
    private const HOME = '(Context location: Breezehome ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Sundas, 6:00 PM, 17th of Last Seed, 4E 201, current weather: outdoors it is Pleasant)';

    private string $dsn;
    private string $schema;
    private RelDynPullbackBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private int $llmCalls = 0;
    private int $evalCalls = 0;
    /** The core relationship every bed starts with. */
    private array $relationship = ['aff' => 92, 'type' => 'platonic'];
    /** Numbers worth reporting, printed with RELDYN_PULLBACK_REPORT=1 */
    private array $report = [];

    protected function setUp(): void
    {
        $this->bootWorld();
    }

    private function bootWorld(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_pullback' . getmypid() . '_' . bin2hex(random_bytes(3));
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
        pg_query($admin, "CREATE TABLE speech (sess varchar(1024), speaker text, speech text, location text, listener text,
            topic text, localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY,
            companions text, audios text, utterance_id text)");
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

        $this->db = new RelDynPullbackBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'HERIKA_PERS', 'COMMAND_PROMPT', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['RELLLM_CONNECTOR'] = 5;   // an eval connector is configured; the call itself is stubbed
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdpullbackbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_pullback_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function () { $this->llmCalls++; return null; };
        RelDynEval::$launcher = function (): void {};

        // Shipped defaults, stored as the config page stores them
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2)',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['log_enabled' => true,
                // the daily roll of the weather (fixed per NPC and game day) off, so that a day is clear or stormy by what the
                // stretch feeds and withholds, not by the luck of the day; everything else as shipped
                'facet_appraisal' => array_replace(RelDynFacets::appraisalDefaults(), ['weather_roll_amplitude' => 0.0])]))]);
        RelationshipDynamics::clearConfigCache();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->shutWorld();
        if (getenv('RELDYN_PULLBACK_REPORT') && $this->report !== []) {
            fwrite(STDERR, "\n=== " . $this->name() . " ===\n" . implode("\n", $this->report) . "\n");
        }
    }

    private function shutWorld(): void
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
        unset($this->schema);
    }

    private function clearReldynGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, 'RELDYN_')) unset($GLOBALS[$key]);
        }
    }

    private function note(string $line): void
    {
        $this->report[] = $line;
    }

    /** Core rows (the core relationship in $this->relationship), voice types, placeholder templates and the seed's reads. */
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
                     'relationships' => [self::PLAYER => $this->relationship]])]);
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
        // Core's locations rows: Breezehome is a player house
        pg_query($this->db->link, "INSERT INTO locations (name, hold, tags, is_interior, world) VALUES
            ('Breezehome', 'Whiterun', 'House,Player House,', 1, 'WhiterunWorld')");
    }

    private static function at(float $day, float $hour): int
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

    /**
     * One player line to $npc through the real hooks at $gamets, logged as core logs it (input row, then the reply):
     * prerequest, context_pre (the <character> block: knowledge_of_player), context (<subtext>), postrequest.
     * Returns ['knowledge' => the <knowledge_of_player> text, 'felt' => key => text, 'subtext' => the <subtext> block].
     */
    private function turn(string $npc, string $line, int $gamets): array
    {
        $request = ['inputtext', (string) $this->realTs, (string) $gamets, self::PLAYER . ": {$line} (Talking to {$npc})"];
        $this->event('inputtext', self::PLAYER . ": {$line} (Talking to {$npc})", $gamets, $this->home());
        $out = ['knowledge' => '', 'felt' => [], 'subtext' => ''];
        $set = function () use ($npc, $request): void {
            $GLOBALS['gameRequest'] = $request;
            $GLOBALS['HERIKA_NAME'] = $npc;
            $GLOBALS['RELDYN_NPC_NAME'] = $npc;
            $GLOBALS['CACHE_PEOPLE'] = $this->home();
            $GLOBALS['CACHE_PARTY'] = '';
            $GLOBALS['SCRIPTLINE_LISTENER_ATOMIC'] = self::PLAYER;
            $GLOBALS['OGHMA_PARITY_RESULT'] = ['topics' => []];
        };
        $GLOBALS['HERIKA_PERS'] = "Roleplay as {$npc}.";
        $GLOBALS['COMMAND_PROMPT'] = '';
        $GLOBALS['contextDataFull'] = [];
        foreach (['prerequest.php', 'context_pre.php', 'context.php', 'postrequest.php'] as $hook) {
            if ($hook === 'postrequest.php') {
                $this->event('chat', "{$npc}: Hm. (talking to " . self::PLAYER . ')', $gamets, $this->home(), 'emitted');
            }
            $set();
            (static function () use ($hook): void { require __DIR__ . "/../../ext/relationship_dynamics/{$hook}"; })();
            if ($hook === 'context_pre.php') {
                $pers = substr((string) $GLOBALS['HERIKA_PERS'], strlen("Roleplay as {$npc}."));
                if (preg_match('~<knowledge_of_player>\s*(.*?)\s*</knowledge_of_player>~s', $pers, $m)) $out['knowledge'] = $m[1];
            }
            if ($hook === 'context.php') {
                $out['felt'] = RelDynFelt::lastRendered();
                foreach ((array) $GLOBALS['contextDataFull'] as $msg) {
                    $c = (string) ($msg['content'] ?? '');
                    if (str_contains($c, '<subtext>')) $out['subtext'] .= $c;
                }
            }
            RelationshipDynamics::endRequest();
        }
        $this->clearReldynGlobals();
        $this->realTs += 60;
        return $out;
    }

    /** The eval LLM at the connector boundary: a warm exchange (the fire) meets her needs, anything else is small talk. */
    private function evalLlm(): callable
    {
        return function (array $messages, array $params): string {
            $this->evalCalls++;
            $exchange = substr((string) $messages[1]['content'], (int) strpos((string) $messages[1]['content'], 'THIS EXCHANGE'));
            $warm = str_contains($exchange, 'by the fire');
            return json_encode([
                'signals' => $warm ? ['affinity' => 1, 'trust' => 1, 'comfort' => 1, 'respect' => 1, 'passion' => 0, 'maturity' => 0]
                                   : ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
                'tags' => $warm ? ['quality_time', 'reassurance', 'praise', 'touch'] : [],
                'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
                'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
                'exposure' => ['flag' => false, 'kinds' => [], 'intensity' => 0, 'when' => null],
                'significance' => $warm ? 0.8 : 0.1,
                'summary' => $warm ? 'They sat together by the fire and talked for a long while.' : 'Small talk.',
            ]);
        };
    }

    private function runEval(): void
    {
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, 'eval worker: ' . json_encode($stats));
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

    /** The first evening: everyone at home with the player; they meet through the hooks so their state is real. */
    private function hello(?array $npcs = null): array
    {
        $this->event('infoloc', self::HOME, self::at(self::N0, 18.0), $this->home());
        $i = 0;
        $out = [];
        foreach ($npcs ?? array_keys(self::BEDS) as $npc) $out[$npc] = $this->turn($npc, 'Well met, friend.', self::at(self::N0, 18.0) + 600 * $i++);
        return $out;
    }

    /** She has had her fill of what she loves today (a fight, the woods, company: the facets her weather is deprived of). */
    private function feedFacets(string $npc, int $gamets): void
    {
        $this->editDynamics($npc, function (array &$dd) use ($gamets): void {
            foreach (RelDynFacets::FACETS as $facet) $dd['_facet_fed'][$facet] = $gamets;
        });
    }

    /** A devoted bond with no passion, comfort and trust high (the player earned it), the maturity this run wants (null: her own). */
    private function makeDevoted(string $npc, ?float $maturity = null): void
    {
        $this->editDynamics($npc, function (array &$dd) use ($maturity): void {
            foreach (['comfort' => 82.0, 'trust' => 85.0, 'respect' => 70.0] as $dim => $v) {
                $dd['dimensions'][$dim]['x'] = $v;
                $dd['dimensions'][$dim]['baseline'] = $v;
            }
            if ($maturity !== null) {
                $dd['dimensions']['maturity']['x'] = $maturity;
                $dd['dimensions']['maturity']['baseline'] = $maturity;
            }
            RelationshipDynamics::setPassion($dd, 0.0);
        });
    }

    /** One game day for the beds named: the player at home in the evening; $warm: sits with each by the fire. */
    private function day(int $day, bool $warm, ?array $npcs = null, int $turns = 1, bool $fed = false): array
    {
        $npcs = $npcs ?? array_keys(self::BEDS);
        $this->event('infoloc', self::HOME, self::at($day, 17.5), $this->home());
        if ($fed) foreach ($npcs as $npc) $this->feedFacets($npc, self::at($day, 17.0));
        $felt = [];
        for ($t = 0; $t < $turns; $t++) {
            $i = 0;
            foreach ($npcs as $npc) {
                $line = $warm ? 'Sit with me by the fire, I have missed this.' : 'Mm. Busy day.';
                $felt[$npc] = $this->turn($npc, $line, self::at($day, 18.0 + 0.5 * $t) + 600 * $i++);
            }
        }
        $this->runEval();
        return $felt;
    }

    /** What the weather, the needs and the state are for $npc now. */
    private function snap(string $npc): array
    {
        $d = $this->dynamics($npc);
        $p = $d['_pullback'] ?? [];
        return [
            'weather' => (string) ($d['_internal_weather'] ?? '?'),
            'rel_dep' => round(floatval($d['_weather_state']['relationship_deprivation'] ?? 0.0), 2),
            'valence_held' => round(floatval($d['_weather_gravity']['offsets']['valence'] ?? 0.0), 1),
            'resentment' => round(floatval($d['dimensions']['resentment']['x'] ?? 0.0), 1),
            'let_in' => round(RelDynPullback::letIn($d), 1),
            'pressure' => round(floatval($p['pressure'] ?? 0.0), 3),
            'active' => !empty($p['active']),
            'on' => $p['last']['on'] ?? null, 'off' => $p['last']['off'] ?? null,
            'target' => $p['last']['target'] ?? null,
            'inputs' => $p['last']['inputs'] ?? null,
            'band' => RelDynPullback::expression($d)['band'], 'style' => RelDynPullback::expression($d)['style'],
            'attach' => RelDynPullback::expression($d)['attachment'], 'maturity' => round(floatval($d['dimensions']['maturity']['x'] ?? 0), 1),
            'G' => round(RelDynConcern::traitsOf($d)['G'], 2), 'L' => round(RelDynConcern::traitsOf($d)['L'], 2),
            'E' => round(RelDynConcern::traitsOf($d)['E'], 2),
        ];
    }

    /** What the old rule (derived warmth, tier 2+, permanent) says of this NPC's state: the pullback switch off, everything else as it is. */
    private function oldLine(string $npc, array $dynamics): string
    {
        $row = json_decode((string) pg_fetch_result(pg_query($this->db->link, "SELECT value FROM conf_opts WHERE id = '" . RelationshipDynamics::CONFIG_ROW_ID . "'"), 0, 0), true);
        $off = $row;
        $off['pullback']['enabled'] = false;
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($off)]);
        RelationshipDynamics::clearConfigCache();
        $text = RelDynFelt::knowledgeOfPlayer($npc, self::PLAYER, $dynamics);
        pg_query_params($this->db->link, 'UPDATE conf_opts SET value = $2 WHERE id = $1', [RelationshipDynamics::CONFIG_ROW_ID, json_encode($row)]);
        RelationshipDynamics::clearConfigCache();
        return $text;
    }

    /** The bridge sentence of a knowledge_of_player text: what follows the tier's own sentence. */
    private static function bridge(string $knowledge): string
    {
        return trim((string) preg_replace('/^.*?(together|on the road|a few shared words, not by heart: polite familiarity, nothing personal assumed|what can be seen\.)[^ ]*\.\s*/s', '', $knowledge, 1));
    }

    private const CLOSED_WORDS = '/lets show|has not let|Pulling back|pulled back|Spoiling for|Pouting/';

    // =====================================================================
    // a, b, c: the same stormy, unfulfilled stretch on the four beds
    // =====================================================================

    /**
     * Devoted platonic bonds with no passion, through three weeks of play:
     *   days 1-3    warm: the player sits with each by the fire; she has her fill of what she loves (facets fed)
     *   days 4-14   the player is around and unwarm, nothing she needs comes: her needs go unmet, the weather turns
     *   days 15-22  warm again, and what she loves is fed again
     * The daily roll of the weather is off (setUp), so the weather is what the stretch makes it.
     */
    public function testAStormyUnfulfilledStretchMakesEachBedPullBackForAWhileThenOpenUpAgain(): void
    {
        $this->hello();
        $npcs = array_keys(self::BEDS);
        foreach ($npcs as $npc) $this->makeDevoted($npc);
        $line = [];   // npc => day => ['snap', 'knowledge', 'felt' (every turn of the day)]
        $run = function (int $from, int $to, bool $warm, bool $fed, string $phase) use (&$line, $npcs) {
            foreach (range($from, $to) as $k) {
                foreach ($this->day(self::N0 + $k, $warm, null, 1, $fed) as $npc => $f) {
                    $line[$npc][$k] = ['phase' => $phase, 'snap' => $this->snap($npc), 'knowledge' => $f['knowledge'], 'felt' => $f['felt']];
                }
            }
        };

        // ---- a: fulfilled, clear weather: let in, nothing closed, nothing pulled back
        $run(1, 3, true, true, 'warm');
        $before = [];
        foreach ($npcs as $npc) {
            $d = $this->dynamics($npc);
            $a = $line[$npc][3];
            $this->assertSame('clear', $a['snap']['weather'], "{$npc}: clear weather after three warm days");
            $this->assertGreaterThanOrEqual(40.0, $a['snap']['let_in'], "{$npc}: let in");
            $this->assertLessThan(floatval(RelDynFelt::config()['bridge']['closed_warmth_max']), RelDynPassion::warmth($d, false) ?? 0.0,
                "{$npc}: no passion in this bond: the old rule's derived warmth is under its closed limit");
            $this->assertFalse($a['snap']['active'], $npc);
            $this->assertLessThan($a['snap']['off'], $a['snap']['pressure'], "{$npc}: well under the off-threshold");
            $this->assertDoesNotMatchRegularExpression(self::CLOSED_WORDS, $a['knowledge'], "{$npc}: no closed line when fulfilled and let in");
            $this->assertStringContainsString('Steady, unhurried loyalty', $a['knowledge'], "{$npc}: a devoted, trusted bond reads steady");
            $old = $this->oldLine($npc, $d);
            $this->assertStringContainsString('Cares more than', $old, "{$npc}: the old rule read this devoted, passionless bond closed");
            $before[$npc] = ['old' => self::bridge($old), 'new' => self::bridge($a['knowledge'])];
        }

        // ---- b/c: the stormy, unfulfilled stretch
        $run(4, 14, false, false, 'storm');
        $entered = $left = [];
        foreach ($npcs as $npc) {
            foreach (range(4, 14) as $k) {
                if ($line[$npc][$k]['snap']['active'] && !isset($entered[$npc])) $entered[$npc] = $k;
            }
            $this->assertArrayHasKey($npc, $entered, "{$npc}: pulls back in a stormy, unfulfilled stretch");
            $this->assertGreaterThan(4, $entered[$npc], "{$npc}: not at once: the weather and the unmet needs have to build");
        }
        // the weather and the deficit really are the cause
        foreach ($npcs as $npc) {
            $s = $line[$npc][$entered[$npc]]['snap'];
            $this->assertContains($s['weather'], ['stormy', 'overcast'], "{$npc}: inclement when she pulls back");
            $this->assertGreaterThan(0.3, $s['rel_dep'], "{$npc}: unfulfilled when she pulls back");
        }
        // what she is let in to the end, and its line
        foreach ($npcs as $npc) {
            $last = $line[$npc][14];
            $this->assertTrue($last['snap']['active'], "{$npc}: still pulled back on day 14: it lasts");
            $this->assertGreaterThanOrEqual(40.0, $last['snap']['let_in'], "{$npc}: still let in: the closing off is not the bond");
            $this->assertMatchesRegularExpression('/Pulling back from Kaida|Has pulled back from Kaida|Spoiling for a fight|Pouting/', $last['knowledge'], $npc);
            $this->assertStringNotContainsString('has not let Kaida in', $last['knowledge'], $npc);
            $this->assertStringNotContainsString('lets show', $last['knowledge'], $npc);
        }
        // persists over several turns with one since-stamp, said once to her face, and the generic unmet line stands down
        foreach ($npcs as $npc) {
            $k0 = $entered[$npc];
            $days = array_filter(range($k0, 14), fn($k) => $line[$npc][$k]['snap']['active']);
            $this->assertGreaterThanOrEqual(3, count($days), "{$npc}: persists over several turns");
            $d = $this->dynamics($npc);
            $this->assertSame(1, $d['_pullback']['episodes'], $npc);
            $saidAt = array_values(array_filter(range(4, 14), fn($k) => isset($line[$npc][$k]['felt']['pullback_enter'])));
            $this->assertSame([$k0], $saidAt, "{$npc}: said to her face once, on the turn she pulled back");
            foreach ($days as $k) $this->assertArrayNotHasKey('fulfillment_unmet', $line[$npc][$k]['felt'], "{$npc} day {$k}: one voice");
            $this->assertDoesNotMatchRegularExpression('/\d/', $line[$npc][$k0]['felt']['pullback_enter'], "{$npc}: feelings, not numbers");
        }

        // ---- c: each bed as she is
        $std = fn(string $npc) => self::bridge($line[$npc][14]['knowledge']);
        $enter = fn(string $npc) => $line[$npc][$entered[$npc]]['felt']['pullback_enter'];
        $x = [];
        foreach ($npcs as $npc) {
            $s = $line[$npc][14]['snap'];
            $x[$npc] = ['entered' => $entered[$npc], 'maturity' => $s['maturity'], 'band' => $s['band'], 'style' => $s['style'], 'attach' => $s['attach'],
                'G' => $s['G'], 'L' => $s['L'], 'E' => $s['E'], 'peak' => max(array_map(fn($k) => $line[$npc][$k]['snap']['pressure'], range(4, 14)))];
        }
        $this->note('c: ' . json_encode($x));
        // Muiri (toxic, reactive): blames, and wants to be come after
        $this->assertSame(['mixed', 'accusation', 'toxic'], [$x['Muiri']['band'], $x['Muiri']['style'], $x['Muiri']['attach']], json_encode($x));
        $this->assertStringContainsString('as an accusation', $std('Muiri'));
        $this->assertStringContainsString('come after Muiri', $std('Muiri'));
        $this->assertStringContainsString('as an accusation', $enter('Muiri'));
        // Ashe (mature, avoidant): says it plainly, quietly and briefly, and keeps some distance
        $this->assertSame(['mature', 'avoidant'], [$x['Ashe']['band'], $x['Ashe']['attach']], json_encode($x));
        $this->assertStringContainsString('has said so', $std('Ashe'));
        $this->assertStringContainsString('quietly and briefly', $std('Ashe'));
        $this->assertStringContainsString('tells Kaida plainly', $enter('Ashe'));
        $this->assertGreaterThan(max($entered['Aela the Huntress'], $entered['Muiri'], $entered['Lynly Star-Sung']), $entered['Ashe'], 'the mature one takes the longest: ' . json_encode($x));
        // Aela, by her own path (maturity about 50, the mixed band, secure): means to say it evenly and it comes out sharp
        $this->assertSame('mixed', $x['Aela the Huntress']['band'], json_encode($x));
        $this->assertStringContainsString('comes out sharp', $std('Aela the Huntress'));
        $this->assertStringContainsString('means to say evenly', $enter('Aela the Huntress'));
        $this->assertNull($x['Aela the Huntress']['attach']);
        // Lynly, by HER real vector (the seed's read: expressive, E 0.70, reactivity 0.49, maturity 51, secure): the in-between band, so she
        // does not withdraw: she means to say it evenly and it comes out as blame, like Aela, with nothing of Muiri's colour. She does not pout:
        // a sulk is for the NPC whose reactivity, expressiveness and confidence are low (RelDynPullbackTest, and the immature copies below)
        $this->assertSame(['mixed', 'accusation', null], [$x['Lynly Star-Sung']['band'], $x['Lynly Star-Sung']['style'], $x['Lynly Star-Sung']['attach']], json_encode($x));
        $this->assertStringNotContainsString('come after', $std('Lynly Star-Sung'));
        // four different standing lines, and the three expressions the beds' own traits give
        $this->assertCount(4, array_unique(array_map($std, $npcs)), 'the four beds diverge: ' . json_encode(array_map($std, $npcs)));
        $this->assertCount(3, array_unique(array_map(fn($n) => $x[$n]['band'] . '/' . $x[$n]['style'] . '/' . $x[$n]['attach'], ['Aela the Huntress', 'Ashe', 'Muiri'])));
        // the same bond, the same weather: Jev gets the numbers and the state
        $jev = RelationshipDynamics::jevStateBlock('Aela the Huntress');
        $this->assertTrue($jev['pullback']['active']);
        $this->assertStringContainsString('pullback=on', $jev['text']);
        $this->assertMatchesRegularExpression('/let_in=\d+(\.\d)?/', $jev['text']);
        foreach ($npcs as $npc) {
            $this->note(sprintf('%-18s old: %s', $npc, $before[$npc]['old']));
            $this->note(sprintf('%-18s fulfilled, clear: %s', '', $before[$npc]['new']));
            $this->note(sprintf('%-18s stormy (day 14): %s', '', $std($npc)));
            $this->note(sprintf('%-18s stormy, the old rule (what it would have said): %s', '', self::bridge($this->oldLine($npc, $this->dynamics($npc)))));
        }

        // the gating preview: the "At every tier" table changes only what the NPC knows of the player and holds their feelings fixed
        $html = RelDynSettingsView::gatingPreviewPanel('Aela the Huntress', null, array_keys(self::BEDS), false);
        $this->assertStringContainsString('This table changes only what the NPC knows of the player', $html);
        $this->assertStringContainsString('held fixed', $html);
        $preview = RelDynSettings::gatingTierPreview('Aela the Huntress');
        foreach (['acquaintance', 'friend', 'close_friend', 'bonded', 'devoted'] as $tier) {
            $this->assertStringContainsString('Pulling back from', $preview[$tier]['text'], "{$tier}: her current feelings are held fixed at every tier");
        }

        // ---- the needs are met again: she opens up, said once, and the line is gone
        $run(15, 22, true, true, 'met');
        foreach ($npcs as $npc) {
            $this->note(sprintf('%-18s days 4-22 (pressure/active/weather/deficit): %s', $npc, implode(' ', array_map(fn($k) => sprintf('%d:%.2f%s/%s/%.2f', $k,
                $line[$npc][$k]['snap']['pressure'], $line[$npc][$k]['snap']['active'] ? '*' : '', substr($line[$npc][$k]['snap']['weather'], 0, 2), $line[$npc][$k]['snap']['rel_dep']), range(4, 22)))));
        }
        foreach ($npcs as $npc) {
            foreach (range(15, 22) as $k) {
                if (!$line[$npc][$k]['snap']['active'] && !isset($left[$npc])) $left[$npc] = $k;
            }
            $this->assertArrayHasKey($npc, $left, "{$npc}: opens up once her needs are met");
            $this->assertLessThanOrEqual(19, $left[$npc], "{$npc}: within a few days of being met (one warm evening a day: Aela, whose fighting need the fire does not meet, is the slowest)");
            $d = $this->dynamics($npc);
            $this->assertFalse(RelDynPullback::active($d), $npc);
            $this->assertGreaterThan(floatval($d['_pullback']['since_gamets']), floatval($d['_pullback']['ended_gamets']), $npc);
            $this->assertSame(1, $d['_pullback']['episodes'], "{$npc}: one pull-back, one reopening");
            $reopen = array_values(array_filter(range(15, 22), fn($k) => isset($line[$npc][$k]['felt']['pullback_reopen'])));
            $this->assertSame([$left[$npc]], $reopen, "{$npc}: the reopening is said once");
            foreach (range($left[$npc], 22) as $k) {
                $this->assertDoesNotMatchRegularExpression('/Pulling back from|Has pulled back from|Spoiling for|Pouting/', $line[$npc][$k]['knowledge'], "{$npc} day {$k}");
            }
        }
        $this->note('entered: ' . json_encode($entered) . ' opened up: ' . json_encode($left));
        $this->assertSame([], $this->db->failures, 'no failed statement');
        $this->assertSame(0, $this->llmCalls, 'no trait read');
    }

    // =====================================================================
    // d: a mature and an immature copy of one NPC
    // =====================================================================

    /** The same stormy stretch for one NPC at a given maturity: ['entered' => day, 'peak' => pressure, 'days' => days pulled back, 'enter' => her words, 'standing' => her line]. */
    private function stretchAt(string $npc, float $maturity): array
    {
        $this->hello([$npc]);
        $this->makeDevoted($npc, $maturity);
        foreach ([1, 2, 3] as $k) $this->day(self::N0 + $k, true, [$npc], 1, true);
        $out = ['entered' => null, 'peak' => 0.0, 'days' => 0, 'enter' => null, 'standing' => null, 'pressure' => [], 'maturity' => $maturity, 'mood_gain' => null];
        foreach (range(4, 16) as $k) {
            $f = $this->day(self::N0 + $k, false, [$npc])[$npc];
            $s = $this->snap($npc);
            $out['pressure'][$k] = $s['pressure'];
            $out['peak'] = max($out['peak'], $s['pressure']);
            $out['mood_gain'] = $this->dynamics($npc)['_pullback']['last']['mood_gain'];
            if ($s['active']) {
                $out['days']++;
                if ($out['entered'] === null) { $out['entered'] = $k; $out['enter'] = $f['felt']['pullback_enter'] ?? null; }
                $out['standing'] = self::bridge($f['knowledge']);
            }
        }
        return $out;
    }

    public function testAnImmatureCopyPullsBackSoonerAndFurtherAndAMatureOneVoicesIt(): void
    {
        $npc = 'Lynly Star-Sung';
        $mature = $this->stretchAt($npc, 80.0);
        $this->shutWorld();
        $this->bootWorld();
        $immature = $this->stretchAt($npc, 25.0);
        $this->note('d mature: ' . json_encode($mature));
        $this->note('d immature: ' . json_encode($immature));
        $this->assertNotNull($immature['entered'], 'the immature copy pulls back: ' . json_encode($immature));
        $this->assertLessThan($mature['entered'] ?? 99, $immature['entered'], 'sooner');
        $this->assertGreaterThan($mature['peak'], $immature['peak'], 'and further');
        $this->assertGreaterThan($mature['mood_gain'], $immature['mood_gain'], 'her mood drives it harder');
        $this->assertGreaterThan(0.0, $mature['mood_gain'], 'a mature one is damped, not deaf');
        foreach ($mature['pressure'] as $k => $p) $this->assertLessThanOrEqual($immature['pressure'][$k] + 1e-9, $p, "day {$k}: the immature copy is never the calmer one");
        // the mature copy voices it plainly; the immature one withdraws
        $this->assertNotNull($mature['entered'], 'a mature NPC with unmet needs and a storm still pulls back: ' . json_encode($mature));
        $this->assertStringContainsString('tells Kaida plainly', (string) $mature['enter']);
        $this->assertStringContainsString('has said so', (string) $mature['standing']);
        // the immature one does not say it plainly: low maturity degrades the text too (RelDynFelt::intensify), so only the shape is asserted
        $this->assertStringNotContainsString('plainly', (string) $immature['enter']);
        $this->assertMatchesRegularExpression('/Lynly.*Kaida/s', (string) $immature['enter']);
        $this->assertStringNotContainsString('has said so', (string) $immature['standing']);
        $this->assertMatchesRegularExpression('/Spoiling for a fight|Pouting/', (string) $immature['standing']);
    }

    // =====================================================================
    // e: newly met
    // =====================================================================

    public function testANewlyMetAsheAtALowTierIsNotLetInYet(): void
    {
        $this->relationship = ['aff' => 12, 'type' => 'professional'];
        $this->shutWorld();
        $this->bootWorld();
        $f = $this->hello(['Ashe'])['Ashe'];
        $d = $this->dynamics('Ashe');
        $this->assertSame(1, RelationshipDynamics::getContextTier($d), 'an acquaintance');
        $this->assertLessThan(40.0, RelDynPullback::letIn($d), 'comfort ' . ($d['dimensions']['comfort']['x'] ?? '?') . ' trust ' . ($d['dimensions']['trust']['x'] ?? '?'));
        $this->assertStringContainsString('has not let Kaida in yet', $f['knowledge']);
        $this->assertStringContainsString('has to be earned', $f['knowledge'], 'yet, not never');
        $this->assertStringNotContainsString('Pulling back', $f['knowledge']);
        $this->note('e: ' . $f['knowledge'] . ' | let-in ' . round(RelDynPullback::letIn($d), 1));
        // a bad stretch does not make a stranger "pull back": nothing to pull back from
        foreach (range(1, 8) as $k) $this->day(self::N0 + $k, false, ['Ashe']);
        $d = $this->dynamics('Ashe');
        $this->assertFalse(RelDynPullback::active($d), json_encode($d['_pullback'] ?? null));
        $this->assertStringContainsString('has not let Kaida in yet', $this->turn('Ashe', 'Mm. Busy day.', self::at(self::N0 + 9, 18.0))['knowledge']);
    }
}
