<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynCombatLanePgDb
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
 * The Phase 4 combat lane end to end on the four test beds (Aela the Huntress, Ashe with Serene's
 * hand-set vector, Muiri nudged fearful, Lynly Star-Sung the shy bard), through the real hooks
 * (prerequest -> context -> postrequest), the real eval worker on a stubbed LLM, CHIM 3.4.1
 * core-shaped rows on a real PostgreSQL:
 *   enemy-threat-scaling  the same fight against a mighty foe moves every bed twice as much, a
 *                         skeever half; who she is still decides how much a fight moves her.
 *   rescue-bonus          after her fall the player's next exchange, answered with care, moves
 *                         each bed by who she is (Aela least: she does not need saving; Muiri
 *                         clings and her anxiety grows); not caring, or too late, moves nobody.
 *   tiered-governors      the relationship tier holds passion (the Friendly row's 40 unless she is
 *                         drawn and her gate allows the raise: Ashe's bond gate never does); a
 *                         partner's floor is 20; an ex (Divorced / Hostile) gains nothing.
 *   speech                MDD 2.5's lift is counted once: every pillar score x(1 + 0.15 speech),
 *                         the passion curve's input unlifted, charm only on the hill.
 *
 * Opt-in: RELDYN_TEST_PG_DSN must point at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynCombatLaneTestBedsPostgresTest extends TestCase
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
    private ?RelDynCombatLanePgDb $db = null;
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
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdcombatlane');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_combat_lane_beds_test.log');
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
        $this->schema = 'reldyn_combatlane_' . getmypid() . '_' . bin2hex(random_bytes(3));
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

        $this->db = new RelDynCombatLanePgDb($this->dsn, $this->schema);
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

    // ------------------------------------------------------------------ enemy threat

    /**
     * One fight in a fresh world: the beds meet the player, all four fight (each barks), then
     * $fight ('combatend' / 'combatendmighty' through core's RPG comment, or a victim's name for
     * a kill the player makes, core's death row routed on the next turn). Returns each bed's
     * combat passion from it.
     */
    private function oneFight(string $fight): array
    {
        $this->world();
        $beds = array_keys(self::BEDS);
        $t0 = self::at(150, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay sharp.', $t0, 'before');
        $t1 = self::at(150, 12.0);
        foreach ($beds as $i => $npc) $this->bark($npc, $t1 + 1000 * $i);
        if ($fight === 'combatend' || $fight === 'combatendmighty') {
            $this->combatRequest($fight, '(Context location: Whiterun outdoors)', $t1 + 30000, self::AELA);
        } else {
            $this->event('death', '(Context location: Whiterun outdoors)' . self::PLAYER . " has defeated {$fight} using weapon Dragonbone Sword", $t1 + 30000, $this->people());
            $this->turn('Ashe', 'Is everyone whole?', $t1 + 60000, 'after');
        }
        $out = [];
        foreach ($beds as $npc) $out[$npc] = floatval($this->dynamics($npc)['passion_sources']['combat'] ?? 0);
        $this->assertNoFailures();
        return $out;
    }

    public function testTheSameFightMovesEveryBedByTheEnemysThreatAndHerOwnTaste(): void
    {
        $regular = $this->oneFight('combatend');
        $mighty = $this->oneFight('combatendmighty');
        $this->assertStringContainsString('Enemy threat: ' . self::AELA . ' mighty (combatendmighty) x2.00', $this->log());
        $troll = $this->oneFight('Frost Troll');
        $skeever = $this->oneFight('Skeever');
        $centurion = $this->oneFight('Dwarven Centurion');
        $why = json_encode(compact('regular', 'mighty', 'troll', 'skeever', 'centurion'));
        foreach (array_keys(self::BEDS) as $npc) {
            $this->assertGreaterThan(0.0, $regular[$npc], "{$npc} {$why}");
            // MDD 3.3 Stage 2: the enemy's threat is the enemy's, the same for every bed
            $this->assertEqualsWithDelta(2.0, $mighty[$npc] / $regular[$npc], 1e-3, "{$npc}: a boss fight, 2.0x {$why}");
            $this->assertGreaterThan(0.0, $troll[$npc], "{$npc} saw the troll fall {$why}");
            $this->assertEqualsWithDelta(0.5, $skeever[$npc] / $troll[$npc], 1e-3, "{$npc}: a skeever is nothing, 0.5x {$why}");
            $this->assertEqualsWithDelta(2.0, $centurion[$npc] / $troll[$npc], 1e-3, "{$npc}: a centurion, a shared survival moment {$why}");
        }
        // ... and who she is still decides how much a fight moves her (her appraisal of the fight,
        // her love languages, her temperament): four beds, four different gains
        $this->assertCount(4, array_unique(array_map(fn($v) => round($v, 3), $mighty)), $why);
        $this->assertGreaterThan($mighty['Ashe'], $mighty[self::AELA], 'the sorceress hardly cares for a fight: ' . $why);
    }

    // ------------------------------------------------------------------ rescue response

    public function testTheRescueAnsweredWithCareIsWhoEachBedIs(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(160, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay close.', $t0, 'before');
        $this->assertSame(0, RelDynEval::runWorker($this->evalLlm())['failed'] ?? 0);
        $before = [];
        foreach ($beds as $npc) $before[$npc] = $this->dynamics($npc);

        // All four go down (core logs the falls; RelDyn routes them on its next turn)
        $t1 = self::at(160, 12.0);
        foreach ($beds as $i => $npc) $this->event('bleedout', "{$npc} falls to the ground almost unconscious", $t1 + 1000 * $i, $this->people());
        // The player's next exchange with each: care, scored by the eval
        $t2 = $t1 + 2 * self::MINUTE;
        $this->round($beds, 'Are you hurt? Here, take my hand.', $t2, 'rescue');
        foreach ($beds as $npc) {
            $this->assertIsArray($this->dynamics($npc)[RelDynCombat::RESCUE_PENDING_KEY] ?? null, "{$npc}: her fall waits for the eval of the exchange");
        }
        $stats = RelDynEval::runWorker($this->evalLlm());
        $this->assertSame(0, $stats['failed'] ?? 0, json_encode($stats));

        $after = [];
        $bonus = [];
        $felt = [];
        foreach ($beds as $npc) {
            $after[$npc] = $this->dynamics($npc);
            $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $after[$npc], $npc);
            $last = $after[$npc][RelDynCombat::RESCUE_LAST_KEY] ?? null;
            $this->assertIsArray($last, "{$npc}: the rescue was answered with care");
            $this->assertStringStartsWith('eval:', $last['via']);
            $bonus[$npc] = floatval($last['bonus']);
            $felt[$npc] = $last['felt'];
            $this->assertGreaterThan(0.0, $bonus[$npc], $npc);
            $this->assertLessThanOrEqual(5.0, $bonus[$npc], "{$npc}: never past the MDD's largest row");
            $this->assertMatchesRegularExpression('/\[RESCUE\] ' . preg_quote($npc, '/') . ': caring response \(eval:/', $this->log());
        }
        $why = json_encode(['bonus' => $bonus, 'felt' => $felt]);
        // Aela, secure and sure of herself: a nod, the smallest response ("does not need saving")
        foreach (['Ashe', 'Muiri', 'Lynly Star-Sung'] as $npc) $this->assertGreaterThan($bonus[self::AELA], $bonus[$npc], "{$npc} {$why}");
        $this->assertContains($felt[self::AELA], ['nod', 'grudging'], $why);
        // Muiri, nudged fearful: she clings or is torn
        $this->assertContains($felt['Muiri'], ['cling', 'torn'], $why);
        // The rescue builds dependency where anxiety leans (Ashe, low-to-moderate anxiety); Aela, low
        // anxiety, none; Muiri is already fearful, and drift never deepens the fearful region
        // (attachment drift reach_fearful: only arcs and overrides go there)
        $anxiety = fn(array $d) => floatval($d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY]['anxiety'] ?? 0.0);
        $this->assertGreaterThan($anxiety($before['Ashe']), $anxiety($after['Ashe']), 'Ashe: a little dependency');
        $this->assertEqualsWithDelta($anxiety($before[self::AELA]), $anxiety($after[self::AELA]), 1e-9, 'Aela: low anxiety, no dependency');
        $this->assertEqualsWithDelta($anxiety($before['Muiri']), $anxiety($after['Muiri']), 1e-9, 'Muiri: the fearful region holds');
        $this->assertGreaterThanOrEqual(3, count(array_unique($felt)), "four women, not one response {$why}");

        // The next turns carry it as felt text, feelings not numbers, in one voice
        $t3 = $t2 + 3 * self::MINUTE;
        $this->round($beds, 'Can you walk?', $t3, 'after');
        foreach ($beds as $npc) {
            $line = $this->felt[$npc]['after']['rescue'] ?? null;
            $this->assertIsString($line, "{$npc}: " . json_encode($this->felt[$npc]['after'] ?? []));
            $this->assertDoesNotMatchRegularExpression('/\d/', $line, 'no numbers in felt text');
            $this->assertStringContainsString(self::PLAYER, $line);
        }
        // ... and it fades with the moment (five minutes of play)
        $this->round($beds, 'Onward.', $t3 + 10 * self::MINUTE, 'later');
        foreach ($beds as $npc) $this->assertArrayNotHasKey('rescue', $this->felt[$npc]['later'] ?? [], $npc);
        // Jev gets the numbers
        $jev = RelDynJev::state(self::AELA, $this->dynamics(self::AELA), (float) ($t3 + 10 * self::MINUTE));
        $this->assertEqualsWithDelta($bonus[self::AELA], $jev['rescue']['last_bonus'], 0.01);
        $this->assertFalse($jev['rescue']['pending']);
        $this->assertNoFailures();
    }

    public function testContemptLatenessAndAMoodTellTheirOwnStory(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(170, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        $this->round($beds, 'Stay close.', $t0, 'before');
        RelDynEval::runWorker($this->evalLlm());

        // Scored by the eval: contempt after her fall is no rescue
        $t1 = self::at(170, 12.0);
        $this->event('bleedout', 'Ashe falls to the ground almost unconscious', $t1, $this->people());
        $this->turn('Ashe', 'Get up, you are slowing me down.', $t1 + self::MINUTE, 'contempt');
        RelDynEval::runWorker($this->evalLlm());
        $ashe = $this->dynamics('Ashe');
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $ashe);
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_LAST_KEY, $ashe);
        $this->assertMatchesRegularExpression('/\[RESCUE\] Ashe: the first exchange after the fall was not caring \(eval:[a-z_,]*insult/', $this->log());

        // Not scored (no eval connector): her own answer decides. Lynly answers grateful; Aela,
        // annoyed, takes the same words as nothing (a line right after a fight always reads as
        // acts of service to the local classifier: that is not care)
        unset($GLOBALS['RELLLM_CONNECTOR']);
        $t2 = self::at(170, 14.0);
        foreach (['Lynly Star-Sung', self::AELA, 'Muiri'] as $i => $npc) {
            $this->event('bleedout', "{$npc} falls to the ground almost unconscious", $t2 + 1000 * $i, $this->people());
        }
        $this->turn('Lynly Star-Sung', 'Are you hurt? Here, take my hand.', $t2 + self::MINUTE, 'local', 'grateful');
        $this->turn(self::AELA, 'Are you hurt? Here, take my hand.', $t2 + self::MINUTE + 600, 'local', 'annoyed');
        $lynly = $this->dynamics('Lynly Star-Sung');
        $this->assertIsArray($lynly[RelDynCombat::RESCUE_LAST_KEY] ?? null, 'Lynly took it as care');
        $this->assertSame('local:' . RelationshipDynamics::LL_SERVICE . '/grateful', $lynly[RelDynCombat::RESCUE_LAST_KEY]['via']);
        $aela = $this->dynamics(self::AELA);
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_LAST_KEY, $aela, 'Aela did not');
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $aela, 'the moment is spent either way');
        // Too late: Muiri's moment passed (eleven minutes of play)
        $this->turn('Muiri', 'Are you hurt? Here, take my hand.', $t2 + 11 * self::MINUTE, 'late', 'grateful');
        $muiri = $this->dynamics('Muiri');
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_PENDING_KEY, $muiri);
        $this->assertArrayNotHasKey(RelDynCombat::RESCUE_LAST_KEY, $muiri, 'the moment after the fall has passed');
        $this->assertStringContainsString('[RESCUE] Muiri: the moment after the fall has passed', $this->log());
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ tiered governors

    public function testTheTierHoldsPassionEachBedByHerOwnGate(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(180, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        // The player draws all four (the editor sets every pillar soft: nothing gates, all
        // attracted), so each bed's own intimacy gate decides the raise
        $this->round($beds, 'Good morning.', $t0, 'hello');
        foreach ($beds as $npc) {
            $this->editDynamics($npc, function (array &$d): void {
                $d['attraction_overrides'] = ['rigidity' => ['beauty' => 'soft', 'strength' => 'soft', 'status' => 'soft', 'competence' => 'soft']];
            });
        }
        $this->round($beds, 'Walk with me.', $t0 + (int) self::HOUR, 'drawn');
        $gov = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $this->assertTrue($d['_attraction']['attracted'] ?? false, "{$npc}: " . json_encode($d['_attraction'] ?? null));
            $gov[$npc] = RelDynJev::state($npc, $d, (float) ($t0 + (int) self::HOUR))['governor'];
            $this->assertSame('friendly', $gov[$npc]['tier'], "{$npc}: core affinity 40, friends");
            $this->assertSame(5.0, $gov[$npc]['passion_floor']);
        }
        $why = json_encode($gov);
        // Ashe's bond gate keeps the Friendly row as it is (MDD 8.2 A); Aela (Primal, visceral)
        // and Lynly (Bard, visceral) are drawn past it toward a crush
        $this->assertFalse($gov['Ashe']['raised'], $why);
        $this->assertSame(40.0, $gov['Ashe']['passion_ceiling'], $why);
        foreach ([self::AELA, 'Lynly Star-Sung'] as $npc) {
            $this->assertTrue($gov[$npc]['raised'], "{$npc} {$why}");
            $this->assertSame(80.0, $gov[$npc]['passion_ceiling'], "{$npc} {$why}");
        }

        // The same passionate evening for all: Ashe stops at her tier's 40, Aela climbs past it
        foreach ($beds as $npc) {
            $this->editDynamics($npc, function (array &$d) use ($npc): void {
                RelationshipDynamics::setPassion($d, 38.0);
                for ($i = 0; $i < 6; $i++) RelationshipDynamics::gainPassion($npc, $d, 4.0, 'reunion');
            });
        }
        $p = [];
        foreach ($beds as $npc) $p[$npc] = RelationshipDynamics::getPassion($this->dynamics($npc));
        $this->assertLessThanOrEqual(40.0 + 1e-6, $p['Ashe'], json_encode($p));
        $this->assertSame(0.0, RelDynGovernors::gainFactor($this->dynamics('Ashe'), 40.0, 1.0), 'no room past 40 for Ashe');
        $this->assertGreaterThan(40.0, $p[self::AELA], json_encode($p));
        $this->assertGreaterThan(40.0, $p['Lynly Star-Sung'], json_encode($p));
        $this->assertStringContainsString("bounded by the friendly tier's passion ceiling 40", $this->log());

        // Core moves on: Aela becomes his partner (Committed: floor 20), Muiri his ex (Divorced:
        // nothing grows, no floor)
        $this->setCore(self::AELA, 80, 'romantic');
        $this->setCore('Muiri', 40, 'ex');
        $t1 = $t0 + 3 * (int) self::HOUR;
        $this->turn(self::AELA, 'Come here, love.', $t1, 'partner');
        $this->turn('Muiri', 'We need to talk.', $t1 + 600, 'ex');
        $aela = $this->dynamics(self::AELA);
        $muiri = $this->dynamics('Muiri');
        $this->assertSame('committed', RelDynGovernors::governor($aela)['tier']);
        $this->assertSame(20.0, RelationshipDynamics::passionStageFloor($aela), 'a partner never cools below 20');
        $this->assertSame('hostile', RelDynGovernors::governor($muiri)['tier']);
        $this->assertSame(0.0, RelationshipDynamics::passionStageFloor($muiri));
        $pm = RelationshipDynamics::getPassion($muiri);
        $this->assertSame(0.0, RelationshipDynamics::gainPassion('Muiri', $muiri, 5.0, 'combat'), 'Divorced / Hostile: 0 / 0');
        $this->assertSame($pm, RelationshipDynamics::getPassion($muiri), 'and nothing is cut: it only stops rising');
        $this->assertNoFailures();
    }

    // ------------------------------------------------------------------ speech, counted once

    public function testSpeechLiftsEveryPillarOnceAndTheCurveOnlyThroughCharm(): void
    {
        $beds = array_keys(self::BEDS);
        $t0 = self::at(190, 10.0);
        $this->event('infoloc', self::OUTSIDE, $t0 - 1000, $this->people());
        // A player the Matrix can read: a fighter with some deeds
        $skills = array_fill_keys(['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
            'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration', 'smithing',
            'sneak', 'speech', 'twohanded'], 20);
        $skills['onehanded'] = 45;
        $skills['archery'] = 40;
        pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            ['skills', json_encode($skills)]);
        foreach (['Quests Completed' => 20, 'Dungeons Cleared' => 8, 'Creatures Killed' => 60, 'People Killed' => 15] as $stat => $n) {
            pg_query_params($this->db->link, 'INSERT INTO core_player (id, value) VALUES ($1, $2)', [$stat, (string) $n]);
        }
        $this->round($beds, 'Good morning.', $t0, 'hello');
        $profile = RelDynPlayer::profile();
        $this->assertTrue($profile['known']);
        $curveShift = [];
        foreach ($beds as $npc) {
            $d = $this->dynamics($npc);
            $mute = RelDynAttraction::evaluate($npc, $d, array_merge($profile, ['speech' => 0.0]));
            $silver = RelDynAttraction::evaluate($npc, $d, array_merge($profile, ['speech' => 1.0]));
            foreach (RelDynAttraction::PILLARS as $p) {
                if (!$mute['pillars'][$p]['known']) {
                    $this->assertSame($mute['pillars'][$p]['score'], $silver['pillars'][$p]['score'], "{$npc} {$p}: an unknown pillar is not lifted");
                    continue;
                }
                // MDD 2.5 / roadmap speech-attraction-bonus: every pillar x(1 + 0.15), after its
                // scoring (the base) and before the bar
                $this->assertEqualsWithDelta(min(1.0, $mute['pillars'][$p]['base_score'] * 1.15), $silver['pillars'][$p]['score'], 1e-3, "{$npc} {$p}");
                $this->assertSame($mute['pillars'][$p]['base_score'], $silver['pillars'][$p]['base_score'], "{$npc} {$p}: the base is speech-free");
            }
            // The passion curve reads the unlifted base: speech reaches it only as charm on the hill
            foreach ((array) ($mute['passion']['units'] ?? []) as $key => $u) {
                $s = $silver['passion']['units'][$key];
                $this->assertSame($u['score'], $s['score'], "{$npc} {$key}: no second speech lift in the curve");
                $this->assertSame($u['m_hill'] ?? null, $s['m_hill'] ?? null, "{$npc} {$key}");
                if (isset($s['m_hill']) && $s['m_hill'] > 0.0 && $s['m_hill'] < 1.0) {
                    $this->assertEqualsWithDelta($s['m_hill'] + (1 - $s['m_hill']) * 0.15, $s['m_charm'], 1e-3, "{$npc} {$key}: charm, 15% of the gap");
                }
            }
            $curveShift[$npc] = round(floatval($silver['passion']['curve'] ?? 1.0) - floatval($mute['passion']['curve'] ?? 1.0), 4);
        }
        // The same silver tongue moves each bed's curve by her own hill (none where she is past it)
        $this->assertGreaterThanOrEqual(2, count(array_unique($curveShift)), json_encode($curveShift));
        $this->assertNoFailures();
    }
}
