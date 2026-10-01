<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynBioReingestBedsPgDb
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
 * The bio re-ingest on the four test beds (decisions 2026-10-01 §21 #10): Aela the Huntress,
 * Ashe, Muiri and Lynly Star-Sung met through the real hooks (prerequest -> context -> postrequest)
 * on a real PostgreSQL, from core-shaped rows and the committed seed's first reads (bio text here
 * is placeholder text, as in RelDynTraitTestBedsPostgresTest). The dynamic profile is stood in for
 * by an UPDATE of the live personality; the model by a stub. The same changed bio moves each bed
 * by who they are (the Bard's class rule is Volatile, Aela resists collapse, Muiri swings both
 * ways), nobody is immune, and Ashe's hand-set vector is never read or recorded, not even on a
 * milestone. Lynly's read stands as it is (no shyness is assumed).
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynBioReingestTestBedsPostgresTest extends TestCase
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
    private RelDynBioReingestBedsPgDb $db;
    private array $savedGlobals = [];
    private string $errorLog;
    private $prevErrorLog = null;
    private int $realTs = 1727000000;
    private float $gamets = 200 * self::DAY + 10 * self::HOUR;
    private int $llmCalls = 0;
    private $llmOut = null;
    private array $llmMessages = [];

    protected function setUp(): void
    {
        $dsn = getenv('RELDYN_TEST_PG_DSN');
        if (!$dsn || !function_exists('pg_connect')) $this->markTestSkipped('RELDYN_TEST_PG_DSN not set (opt-in test against a throwaway PostgreSQL)');
        if (preg_match('/dbname\s*=\s*dwemer\b/', $dsn)) $this->fail('refusing to run against the live dwemer database');
        $this->dsn = $dsn;
        $this->schema = 'reldyn_bribeds' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
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

        $this->db = new RelDynBioReingestBedsPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'gameRequest', 'HERIKA_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE', 'CACHE_PARTY',
                     'CACHE_LOCATION', 'contextDataFull', 'OGHMA_PARITY_RESULT', 'SCRIPTLINE_LISTENER_ATOMIC',
                     'SCRIPTLINE_LISTENER', 'LAST_LLM_RESPONSE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        $this->clearReldynGlobals();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbeds');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_bio_reingest_beds_test.log');
        RelDynTraits::$assignmentOverride = null;
        RelDynTraitRead::reset();
        RelDynTraitRead::$launcher = function () {};
        RelDynTraitRead::$llm = function (array $messages) {
            $this->llmCalls++;
            $this->llmMessages[] = $messages;
            return is_callable($this->llmOut) ? ($this->llmOut)($messages) : $this->llmOut;
        };

        // Shipped defaults, stored as the config page stores them (traits.assignment 'read')
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

    /** Core rows, voice types, placeholder templates, and the seed's reads keyed to them. */
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
                     'relationships' => [self::PLAYER => ['aff' => 10, 'type' => 'platonic']]])]);
            $fields = array_fill_keys(RelDynTraitRead::FIELDS, "Placeholder {$key} text (the live bio is not committed).");
            pg_query_params($this->db->link, 'INSERT INTO combined_bio_templates (npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8)', [$key, 'core', $fields['personality'], $fields['relationships'],
                $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            // the live bio starts as the shipped text (the dynamic profile rewrites it later)
            pg_query_params($this->db->link, 'UPDATE core_npc_master SET personality = $2, relationships = $3, npc_static_bio = $4, speechstyle = $5, goals = $6, occupation = $7 WHERE npc_name = $1',
                [$name, $fields['personality'], $fields['relationships'], $fields['npc_static_bio'], $fields['speechstyle'], $fields['goals'], $fields['occupation']]);
            if ($voice !== null) pg_query_params($this->db->link, 'INSERT INTO npc_templates_v2 (npc_name, xvasynth_voiceid) VALUES ($1, $2)', [$key, $voice]);
            if ($key === 'ashe') continue;
            $e = $seed['reads'][$key];
            $this->assertSame('done', $e['status'], $key);
            pg_query_params($this->db->link, "INSERT INTO reldyn_trait_reads (template_key, src_hash, prompt_v, status, attempts, model, result)
                VALUES (\$1, \$2, \$3, 'done', 1, \$4, \$5::jsonb)",
                [$key, RelDynTraitRead::srcHash($fields), RelDynTraitRead::PROMPT_V, 'seed:' . $e['model'], json_encode($e['result'])]);
        }
    }

    /** One player line to $npc through the real hooks: prerequest, context, postrequest. */
    private function turn(string $npc, string $line): void
    {
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

    private function dynamics(string $npc): array
    {
        $r = pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT plugin_extended_data FROM core_npc_master WHERE npc_name = $1', [$npc]));
        return json_decode($r['plugin_extended_data'], true)['reldyn']['dynamics'] ?? [];
    }

    /** The four meet the player at Jorrvaskr: two lines each through the real hooks. */
    private function meetAll(): void
    {
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1, $2, $3, $4, $5, $6, $7, $8)',
            ['infoloc', '(Context location: Jorrvaskr ,Hold: Whiterun, Buildings to go:, Current Date in Skyrim World: Morndas, 10:00 AM, 17th of Last Seed, 4E 201, current weather: Pleasant)',
             'pending', (int) $this->gamets, $this->realTs, (int) $this->gamets, '|' . implode('|', array_keys(self::BEDS)) . '|', '']);
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Well met. Have a moment?');
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'How have you been keeping?');
    }

    private function npcId(string $npc): int
    {
        return intval(pg_fetch_assoc(pg_query_params($this->db->link, 'SELECT id FROM core_npc_master WHERE npc_name = $1', [$npc]))['id']);
    }

    // ------------------------------------------------------------------ the re-ingest on the four beds

    /** The dynamic profile has rewritten an NPC's personality (made-up text; no pronouns, no story). */
    private function rewriteBio(string $npc): void
    {
        pg_query_params($this->db->link, 'UPDATE core_npc_master SET personality = $2 WHERE npc_name = $1',
            [$npc, "{$npc} has grown guarded and cold since the fire."]);
    }

    /** The read the stub model gives every rewritten bio: guarded and cold (quotes are in each bed's live text). */
    private static function coldRead(): string
    {
        $t = [];
        foreach (RelDynTraitRead::TRAIT_KEYS as $k) $t[$k] = ['value' => 0.5, 'conf' => 0, 'field' => null, 'evidence' => null];
        $t['guard'] = ['value' => 0.85, 'conf' => 0.9, 'field' => 'personality', 'evidence' => 'grown guarded and cold'];
        $t['warmth'] = ['value' => 0.2, 'conf' => 0.9, 'field' => 'personality', 'evidence' => 'guarded and cold'];
        return json_encode(['v' => 1, 'traits' => $t, 'maturity_start' => ['value' => 50, 'conf' => 0, 'field' => null, 'evidence' => null]]);
    }

    private function liveRows(): array
    {
        $res = pg_query($this->db->link, "SELECT template_key, status FROM reldyn_trait_reads WHERE template_key LIKE 'live:%' ORDER BY template_key");
        $out = [];
        while ($r = pg_fetch_assoc($res)) $out[$r['template_key']] = $r['status'];
        return $out;
    }

    private function modify(string $npc, callable $fn): void
    {
        RelationshipDynamics::beginRequest();
        $d = RelationshipDynamics::getDynamics($npc);
        $fn($d);
        RelationshipDynamics::saveDynamics($npc, $d);
        RelationshipDynamics::endRequest();
    }

    public function testAnUnchangedBioCostsNothingOnAnyBedAndOnlyTheRewrittenOneIsQueued(): void
    {
        $this->meetAll();
        $this->gamets += 95 * self::DAY;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Back at last.');
        $this->assertSame([], $this->liveRows(), 'the live bios are the shipped text: nothing to read');
        foreach (['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $s = $this->dynamics($npc)['_bio_reingest'];
            $this->assertGreaterThan($s['last_read'] + 80 * self::DAY, $s['last_check'], "{$npc}: checked after the cadence");
            $this->assertNull($s['pending'], $npc);
        }
        $this->assertSame(0, $this->llmCalls);

        $this->rewriteBio('Lynly Star-Sung');
        $this->gamets += 95 * self::DAY;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'And again.');
        $this->assertSame(['live:lynly star-sung' => 'pending'], $this->liveRows(), 'only the changed bio is queued');
        $this->assertSame(0, $this->llmCalls, 'no model runs in a request');
        $this->assertSame([], $this->db->failures);
    }

    public function testTheSameRewrittenBioMovesEachBedByWhoTheyAreAndAsheNotAtAll(): void
    {
        $this->meetAll();
        $x0 = [];
        foreach (array_keys(self::BEDS) as $npc) $x0[$npc] = RelDynTraits::readVector($this->dynamics($npc));
        $k0 = [];
        foreach (['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) $k0[$npc] = RelDynTraitReingest::inertia($this->dynamics($npc));

        foreach (array_keys(self::BEDS) as $npc) $this->rewriteBio($npc);
        $this->gamets += 95 * self::DAY;
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Back at last.');
        $this->assertSame(['live:aela the huntress' => 'pending', 'live:lynly star-sung' => 'pending', 'live:muiri' => 'pending'], $this->liveRows(),
            'three queued; the hand-set Ashe is not');
        $this->assertSame(0, $this->llmCalls);

        $this->llmOut = self::coldRead();
        $stats = RelDynTraitRead::drain();
        $this->assertSame(3, $stats['done']);
        $this->assertSame(3, $this->llmCalls);
        foreach ($this->llmMessages as $m) {
            $user = $m[1]['content'];
            $this->assertStringContainsString('has grown guarded and cold since the fire.', $user, 'the live bio is read, not the shipped text');
            $this->assertStringNotContainsString('Director', $user);
        }
        foreach (array_keys(self::BEDS) as $npc) $this->turn($npc, 'Settle in.');

        $k = [];
        $share = [];
        foreach (['Aela the Huntress', 'Muiri', 'Lynly Star-Sung'] as $npc) {
            $d = $this->dynamics($npc);
            $s = $d['_bio_reingest'];
            $this->assertSame(1, $s['reads'], $npc);
            $this->assertNull($s['pending'], $npc);
            $k[$npc] = $s['history'][0]['k'];
            $this->assertEqualsWithDelta($k0[$npc]['k'], $k[$npc], 0.03, "{$npc}: sized by their own plasticity and maturity");
            $x = RelDynTraits::readVector($d);
            $this->assertGreaterThan($x0[$npc]['G'], $x['G'], "{$npc}: more guarded");
            $this->assertLessThan($x0[$npc]['W'], $x['W'], "{$npc}: colder");
            $g = $d['_trait_vector_src']['traits']['guard'];
            $target = $g['prior'] + 0.72 * (0.85 - $g['prior']);
            $share[$npc] = ($x['G'] - $x0[$npc]['G']) / ($target - $x0[$npc]['G']);
            // a trait the first read had no evidence for fills in fully (unknown traits do not bias); one it knew moves a share
            $knew = isset($s['history'][0]['superseded']['read']['guard']);
            if ($knew) $this->assertLessThan(1.0, $share[$npc], "{$npc}: guard was read before: a share of the way, not a replacement");
            else $this->assertEqualsWithDelta(1.0, $share[$npc], 1e-3, "{$npc}: guard was unknown: it fills in");
            $this->assertGreaterThan(0.05, $share[$npc], "{$npc}: nobody is immune");
            $this->assertSame('bio', $d['_trait_vector_src']['traits']['guard']['source']);
            $old = $s['history'][0]['superseded'];
            $this->assertNotSame([], $old['read'], "{$npc}: the old read is kept");
        }
        // who they are: the Bard's class rule is Volatile (x1.5), Aela resists collapse, Muiri swings both ways
        $this->assertGreaterThan($k['Aela the Huntress'], $k['Lynly Star-Sung'], 'Lynly moves more than Aela');
        $this->assertGreaterThan($k['Aela the Huntress'], $k['Muiri'], 'Muiri moves more than Aela');
        $this->assertGreaterThan(0.15, max($k) - min($k), 'meaningful divergence');
        $this->assertGreaterThan($share['Aela the Huntress'], $share['Lynly Star-Sung']);
        $this->assertGreaterThan(0.0, min($k), 'no one is fully immune');

        // Ashe: the hand-set conclusion, never read, nothing recorded, exactly as set
        $ashe = $this->dynamics('Ashe');
        $this->assertArrayNotHasKey('_bio_reingest', $ashe);
        $this->assertSame('hand-set', $ashe['_trait_vector_src']['auto_source']);
        $this->assertEquals($x0['Ashe'], RelDynTraits::readVector($ashe));
        $this->assertArrayNotHasKey('live:ashe', $this->liveRows());
        $this->assertSame([], $this->db->failures);
    }

    public function testAMilestoneChecksAelaSoonerAndAsheStaysUntouchedEvenWhenAskedTo(): void
    {
        $this->meetAll();
        $this->rewriteBio('Aela the Huntress');
        $this->rewriteBio('Ashe');
        // nine game days on: a romance promotion for Aela, a (made-up) betrayal mark on Ashe
        $this->gamets += 9 * self::DAY;
        $this->modify('Aela the Huntress', function (array &$d) {
            $d['_romance']['last_promotion'] = ['from' => 'platonic', 'to' => 'romantic', 'gamets' => $this->gamets - self::DAY];
        });
        $this->modify('Ashe', function (array &$d) { RelDynTraitReingest::noteMilestone($d, 'betrayal', $this->gamets); });
        foreach (['Aela the Huntress', 'Ashe'] as $npc) $this->turn($npc, 'I have news.');
        $this->assertSame(['live:aela the huntress' => 'pending'], $this->liveRows(), 'well before the 90 days: Aela only');
        $s = $this->dynamics('Aela the Huntress')['_bio_reingest'];
        $this->assertSame('milestone: romance', $s['pending']['reason']);
        $ashe = $this->dynamics('Ashe');
        $this->assertArrayNotHasKey('_bio_reingest', $ashe, 'the mark is dropped for a hand-set vector, never read');
        $this->assertSame(0, $this->llmCalls);
        $this->assertSame([], $this->db->failures);
    }

}
