<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (empty($GLOBALS['ENGINE_PATH'])) {
    $GLOBALS['ENGINE_PATH'] = dirname(__DIR__, 2) . '/';
}
require_once $GLOBALS['ENGINE_PATH'] . 'lib/logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/core/npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib/chat_helper_functions.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext/relationship_dynamics/eval_producer.php';

/** `sql`-compatible adapter over one pg connection (CHIM conventions: fetchOne returns [] on failure). */
final class RelDynSceneLinesPgDb
{
    public $link;
    private string $schema;

    public function __construct(string $dsn, string $schema)
    {
        $this->schema = $schema;
        $this->link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$this->link) throw new RuntimeException("cannot connect to {$dsn}");
        pg_query($this->link, "SET search_path TO {$schema}");
    }

    public function fetchOne($q, array $params = [])
    {
        $res = $params ? @pg_query_params($this->link, $q, $params) : @pg_query($this->link, $q);
        if (!$res) return [];
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
    public function execQuery($q) { return @pg_query($this->link, $q); }
    public function escape($s) { return $s === null ? '' : pg_escape_string($this->link, (string) $s); }
    public function escapeLiteral($s) { return $s === null ? '' : pg_escape_literal($this->link, (string) $s); }

    /** CHIM's insert($table, $row): what logEvent() stores. */
    public function insert($table, $row)
    {
        return pg_insert($this->link, $this->schema . '.' . $table, $row) !== false;
    }
}

/**
 * Scripted scene dialogue (CHIM type 'chat_background') in RelDyn's eval window (Ken's ruling D6, content/dialogue
 * proposal 2026-10-04): voiced follower banter and quest scenes ride along as context, marked "(scene)", never as the
 * scored exchange; the same line counts once; lines heard in The Forgotten City (a time loop that replays them) are
 * ignored, and the CHIM fork hook keeps core from storing them; the count is capped so they never crowd out the
 * conversation with the player.
 *
 * Opt-in: RELDYN_TEST_PG_DSN pointing at a THROWAWAY database (never dbname=dwemer).
 */
final class RelDynSceneLinesPostgresTest extends TestCase
{
    private const T0 = 5000000;     // raw gamets of the exchange under test
    private const NPC = 'Lydia';
    private const PLAYER = 'Kaida';
    private const HOUR = 416667;    // raw gamets of one game hour (GAMETS_PER_DAY / 24)
    private const WHITERUN = '(Context location: Whiterun, Hold: Whiterun background chat) ';
    private const FORGOTTEN = '(Context location: The Forgotten City background chat) ';

    private string $dsn;
    private string $schema;
    private RelDynSceneLinesPgDb $db;
    private array $savedGlobals = [];

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
        $this->schema = 'reldyn_scene' . getmypid() . '_' . bin2hex(random_bytes(3));
        $admin = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "CREATE SCHEMA {$this->schema}");
        pg_query($admin, "SET search_path TO {$this->schema}");
        pg_query($admin, "CREATE TABLE conf_opts (id text NOT NULL, value text, CONSTRAINT pid PRIMARY KEY (id))");
        pg_query($admin, "CREATE TABLE eventlog (type varchar(128), data text, sess text, gamets bigint NOT NULL,
            localts bigint NOT NULL, ts bigint, rowid bigserial PRIMARY KEY, people text, location text, party text,
            utterance_id text, delivery_state text)");
        pg_close($admin);

        $this->db = new RelDynSceneLinesPgDb($dsn, $this->schema);
        foreach (['db', 'PLAYER_NAME', 'CACHE_PEOPLE_LIMITED', 'CACHE_LOCATION', 'CACHE_PARTY'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = self::PLAYER;
        $GLOBALS['CACHE_PEOPLE_LIMITED'] = '|Lydia|';
        $GLOBALS['CACHE_LOCATION'] = '(Context location: Whiterun, Hold: Whiterun)';
        $GLOBALS['CACHE_PARTY'] = '[]';
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->schema)) return;
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
        RelationshipDynamics::endRequest();
        pg_close($this->db->link);
        $admin = pg_connect($this->dsn, PGSQL_CONNECT_FORCE_NEW);
        pg_query($admin, "DROP SCHEMA {$this->schema} CASCADE");
        pg_close($admin);
    }

    // ------------------------------------------------------------------ fixtures

    /** Store RelDyn's config row: $overrides over the shipped defaults; eval_producer replaced key by key. */
    private function setConfig(array $overrides, array $producer = []): void
    {
        $row = ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA] + $overrides;
        if ($producer !== []) {
            $row['eval_producer'] = $producer;
        }
        pg_query_params($this->db->link, 'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value',
            [RelationshipDynamics::CONFIG_ROW_ID, json_encode($row)]);
        RelationshipDynamics::clearConfigCache();
    }

    private function event(string $type, string $data, int $gamets, string $people = '|Lydia|Kaida|', ?string $state = null): int
    {
        $row = pg_fetch_assoc(pg_query_params($this->db->link,
            'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location, delivery_state)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9) RETURNING rowid',
            [$type, $data, 'pending', $gamets, 1727000000 + $gamets, $gamets, $people, 'Whiterun', $state]));
        return (int) $row['rowid'];
    }

    /** A scene line the way the game plugin logs it (stamped with where the player was). */
    private function scene(string $speaker, string $text, int $gamets, string $people = '|Lydia|Aela|Kaida|', string $stamp = self::WHITERUN): int
    {
        return $this->event('chat_background', "{$stamp}{$speaker}: {$text}", $gamets, $people);
    }

    /** The exchange under test: the player speaks to Lydia, Lydia answers. Returns the anchor rowid. */
    private function exchange(int $gamets = self::T0): int
    {
        $this->event('inputtext', 'Kaida: Lydia, are you ready? (Talking to Lydia)', $gamets);
        return $this->event('chat', 'Lydia: Always, my Thane. (talking to Kaida)', $gamets + 2, '|Lydia|Kaida|', 'emitted');
    }

    private function cfg(array $over = []): array
    {
        return array_merge(RelDynEval::defaultConfig(), $over);
    }

    private function window(int $anchor, ?array $cfg): array
    {
        return RelDynEval::conversationWindow(self::NPC, self::PLAYER, $anchor, 12, 200, null, $cfg);
    }

    private static function texts(array $lines): array
    {
        return array_column($lines, 'text');
    }

    // ------------------------------------------------------------------ inclusion and tagging

    public function testSceneLinesTheNpcSaidOrWasAddressedWithArePartOfTheWindowAsContextOnly(): void
    {
        $t = self::T0;
        $this->scene('Lydia', 'Let us keep moving, Dragonborn.', $t - 3000);
        $this->scene('Aela', 'Kaida, the Companions could use you.', $t - 2000);
        $this->scene('Aela', 'Nice weather for a hunt.', $t - 1900);                                       // ambient: nobody named
        $this->scene('Farkas', 'Kaida, hold this.', $t - 1800, '|Farkas|Aela|Kaida|');                     // Lydia was not there
        $this->scene('Aela', 'Lydia, you look tired.', $t - 1700);                                          // about Lydia
        $anchor = $this->exchange();

        $w = $this->window($anchor, $this->cfg());
        $scene = array_values(array_filter($w['earlier'], static fn($l) => !empty($l['scene'])));
        $this->assertSame([
            'Let us keep moving, Dragonborn.',
            'Kaida, the Companions could use you.',
            'Lydia, you look tired.',
        ], self::texts($scene), 'her own line, a line to the player, a line about her; ambient chatter and a scene she was not at are dropped');
        $this->assertSame(['npc', 'other', 'other'], array_column($scene, 'role'));
        $this->assertSame(['Lydia', 'Aela', 'Aela'], array_column($scene, 'speaker'));
        $this->assertSame([], array_filter($w['current'], static fn($l) => !empty($l['scene'])), 'a scene line is never the scored exchange');
        $this->assertSame(['Lydia, are you ready?', 'Always, my Thane.'], self::texts($w['current']));
        $rowids = array_column($w['earlier'], 'rowid');
        $sorted = $rowids;
        sort($sorted);
        $this->assertSame($sorted, $rowids, 'merged in the order they happened');
    }

    public function testWithoutTheSceneConfigTheWindowIsTheChatOnly(): void
    {
        $this->scene('Lydia', 'Let us keep moving, Dragonborn.', self::T0 - 3000);
        $anchor = $this->exchange();
        $this->assertSame([], $this->window($anchor, null)['earlier']);
        $this->assertSame([], $this->window($anchor, $this->cfg(['scene_lines' => false]))['earlier'], 'scene_lines off');
        $this->assertSame([], $this->window($anchor, $this->cfg(['scene_max_lines' => 0]))['earlier'], 'a cap of 0');
    }

    public function testTheEvalPromptMarksSceneLinesAndExplainsTheMarker(): void
    {
        $this->scene('Lydia', 'Let us keep moving, Dragonborn.', self::T0 - 3000);
        $this->scene('Aela', 'Kaida, the Companions could use you.', self::T0 - 2000);
        $anchor = $this->exchange();
        $w = $this->window($anchor, $this->cfg());
        $user = RelDynEval::buildMessages(self::NPC, self::PLAYER, $w, ['Bond with the player: friends.'], [])[1]['content'];

        [$earlier, $current] = explode('THIS EXCHANGE (score only this):', $user, 2);
        $this->assertStringContainsString('[Lydia] (scene): Let us keep moving, Dragonborn.', $earlier);
        $this->assertStringContainsString('[Aela] (scene): Kaida, the Companions could use you.', $earlier);
        $this->assertStringContainsString('lines marked (scene) are scripted dialogue Lydia took part in or overheard, context for how Lydia is acting, not conversation with Kaida', $earlier);
        $this->assertStringNotContainsString('(scene)', $current);
        $this->assertStringContainsString('[Kaida] (to Lydia): Lydia, are you ready?', $current);
        $this->assertStringNotContainsString('Context location', $user, 'the location stamp never reaches the model');
        $this->assertStringNotContainsString('background chat', $user);
    }

    public function testThePromptIsUnchangedWhenThereAreNoSceneLines(): void
    {
        $anchor = $this->exchange();
        $user = RelDynEval::buildMessages(self::NPC, self::PLAYER, $this->window($anchor, $this->cfg()), ['x'], [])[1]['content'];
        $this->assertStringContainsString('EARLIER CONVERSATION (context only, already scored, do not score it again):', $user);
        $this->assertStringNotContainsString('scene', $user);
    }

    public function testRaceTagsAndGhostsAreReadAsTheSpeakerAndPlayerTermsCountAsAddressingThePlayer(): void
    {
        $this->scene('Hivorate [Dremora]', 'Mortal, we have come to test your worth, Dragonborn.', self::T0 - 1000, '|Lydia|Hivorate [Dremora]|Kaida|');
        $this->scene('Lydia [Nord]', 'Stay behind me.', self::T0 - 900, '|Lydia [Nord]|');
        $anchor = $this->exchange();
        $scene = array_values(array_filter($this->window($anchor, $this->cfg())['earlier'], static fn($l) => !empty($l['scene'])));
        $this->assertSame(['Hivorate', 'Lydia'], array_column($scene, 'speaker'));
        $this->assertSame(['other', 'npc'], array_column($scene, 'role'));
    }

    // ------------------------------------------------------------------ dedupe

    public function testTheSameLineBySameSpeakerCountsOnceButNotAcrossSpeakers(): void
    {
        $t = self::T0;
        $this->scene('Lydia', 'Stay close, Dragonborn.', $t - 5000);
        $this->scene('Aela', 'Dragonborn, wait.', $t - 4900);
        $this->scene('Lydia', 'Stay close,   Dragonborn.', $t - 4000);   // spacing differs only
        $this->scene('Lydia', 'Stay close, Dragonborn.', $t - 3000);
        $this->scene('Lydia', 'Dragonborn, wait.', $t - 2500);           // same words, another speaker
        $this->scene('Aela', 'Dragonborn, wait.', $t - 2000);
        $anchor = $this->exchange();

        $scene = array_values(array_filter($this->window($anchor, $this->cfg())['earlier'], static fn($l) => !empty($l['scene'])));
        $pairs = array_map(static fn($l) => $l['speaker'] . ': ' . $l['text'], $scene);
        $this->assertSame(['Lydia: Stay close, Dragonborn.', 'Lydia: Dragonborn, wait.', 'Aela: Dragonborn, wait.'], $pairs,
            'each (speaker, line) once, at its latest occurrence, in order');
    }

    // ------------------------------------------------------------------ The Forgotten City

    public function testSceneLinesHeardInTheForgottenCityAreIgnored(): void
    {
        $t = self::T0;
        $this->scene('Lydia', 'Do you hear the bell, Dragonborn?', $t - 4000, '|Lydia|Kaida|', self::FORGOTTEN);
        $this->scene('Lydia', 'Mind the golden statues, Dragonborn.', $t - 3900, '|Lydia|Kaida|', '(Context location: The Golden Sentinel Tavern, Hold: Whiterun background chat) ');
        $this->scene('Lydia', "Ulrin's door is locked, Dragonborn.", $t - 3800, '|Lydia|Kaida|', "(Context location: Ulrin\u{2019}s house background chat) ");
        // no stamp at all: the row's own location column decides
        pg_query_params($this->db->link, 'INSERT INTO eventlog (type, data, sess, gamets, localts, ts, people, location) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)',
            ['chat_background', 'Lydia: The loop begins again, Dragonborn.', 'pending', $t - 3700, 1, 1, '|Lydia|Kaida|', '(Context location: Forgotten Ruins, Falkreath)']);
        $this->scene('Lydia', 'Lovely day in Whiterun, Dragonborn.', $t - 3000);
        $anchor = $this->exchange();

        $scene = array_values(array_filter($this->window($anchor, $this->cfg())['earlier'], static fn($l) => !empty($l['scene'])));
        $this->assertSame(['Lovely day in Whiterun, Dragonborn.'], self::texts($scene), 'only the Whiterun line survives');

        $none = array_values(array_filter($this->window($anchor, $this->cfg(['scene_excluded_locations' => []]))['earlier'], static fn($l) => !empty($l['scene'])));
        $this->assertCount(4, $none, 'an empty list switches the filter off (the stamp-less row too)');
    }

    public function testLocationMatchingIsCaseInsensitiveSubstringAndSurvivesCurlyApostrophes(): void
    {
        $ex = ['Forgotten City', "Brandas' house"];
        $this->assertTrue(RelDynEval::locationExcluded('The FORGOTTEN CITY', $ex));
        $this->assertTrue(RelDynEval::locationExcluded('Citadel, Forgotten City Ruins', $ex));
        $this->assertTrue(RelDynEval::locationExcluded("Brandas\u{2019} House", $ex));
        $this->assertFalse(RelDynEval::locationExcluded('Whiterun, Hold: Whiterun', $ex));
        $this->assertFalse(RelDynEval::locationExcluded('', $ex));
        $this->assertFalse(RelDynEval::locationExcluded(null, $ex));
        $this->assertFalse(RelDynEval::locationExcluded('Forgotten City', []), 'no names, no filter');
        $this->assertFalse(RelDynEval::locationExcluded('Forgotten City', ['  ']), 'a blank name matches nothing');
    }

    public function testTheLocationStampIsReadFromTheLine(): void
    {
        $this->assertSame('Abandoned House,Hold: Markarth', RelDynEval::sceneLocation("(Context location: Abandoned House,Hold: Markarth background chat) Hivorate [Dremora]: Mortal."));
        $this->assertSame('The Forgotten City', RelDynEval::sceneLocation('(Context location: The Forgotten City background chat) Lydia: Hm.'));
        $this->assertNull(RelDynEval::sceneLocation('Lydia: no stamp'));
    }

    public function testTheShippedDefaultsNameTheForgottenCityCellsNotTheGenericOnes(): void
    {
        $names = RelDynEval::defaultConfig()['scene_excluded_locations'];
        foreach (['The Forgotten City', 'Forgotten Ruins', 'The Golden Sentinel Tavern', 'Firefly Finery', 'The Honest Trader', "Vernon's Fresh Produce"] as $cell) {
            $this->assertTrue(RelDynEval::locationExcluded($cell, $names), $cell);
        }
        foreach (['Cave', 'Citadel', 'Chambers', 'Underground tunnels', 'Whiterun, Hold: Whiterun', 'Dragonsreach'] as $generic) {
            $this->assertFalse(RelDynEval::locationExcluded($generic, $names), "{$generic} is not excluded by default");
        }
    }

    // ------------------------------------------------------------------ the cap and the window

    public function testSceneLinesAreCappedAndNeverCrowdOutTheConversation(): void
    {
        $t = self::T0;
        for ($i = 1; $i <= 10; $i++) {
            $this->scene('Lydia', "Line number {$i}, Dragonborn.", $t - 6000 + $i * 100);
        }
        $anchor = $this->exchange();

        $plain = $this->window($anchor, null);
        $capped = $this->window($anchor, $this->cfg());
        $scene = array_values(array_filter($capped['earlier'], static fn($l) => !empty($l['scene'])));
        $this->assertSame(['Line number 7, Dragonborn.', 'Line number 8, Dragonborn.', 'Line number 9, Dragonborn.', 'Line number 10, Dragonborn.'],
            self::texts($scene), 'the default cap keeps the latest four');
        $this->assertSame($plain['current'], $capped['current']);
        $this->assertSame($plain['earlier'], array_values(array_filter($capped['earlier'], static fn($l) => empty($l['scene']))), 'the conversation is untouched');

        $two = array_filter($this->window($anchor, $this->cfg(['scene_max_lines' => 2]))['earlier'], static fn($l) => !empty($l['scene']));
        $this->assertCount(2, $two);
    }

    public function testLongSceneLinesAreClipped(): void
    {
        $this->scene('Lydia', 'Dragonborn, ' . str_repeat('listen carefully ', 40), self::T0 - 1000);
        $anchor = $this->exchange();
        $scene = array_values(array_filter($this->window($anchor, $this->cfg(['scene_line_max_chars' => 60]))['earlier'], static fn($l) => !empty($l['scene'])));
        $this->assertLessThanOrEqual(60, mb_strlen($scene[0]['text']));
        $this->assertStringEndsWith('...', $scene[0]['text']);
    }

    public function testOnlyLinesFromTheLastGameHoursBeforeTheExchangeCount(): void
    {
        $t = self::T0;
        $this->scene('Lydia', 'That was last night, Dragonborn.', $t - 3 * self::HOUR);
        $this->scene('Lydia', 'That was an hour ago, Dragonborn.', $t - self::HOUR);
        $anchor = $this->exchange();
        $this->assertSame(['That was an hour ago, Dragonborn.'], self::texts(array_filter($this->window($anchor, $this->cfg())['earlier'], static fn($l) => !empty($l['scene']))));
        $this->assertCount(2, array_filter($this->window($anchor, $this->cfg(['scene_window_game_hours' => 4]))['earlier'], static fn($l) => !empty($l['scene'])));
    }

    public function testALineAfterTheAnchoredExchangeIsNotPartOfIt(): void
    {
        $anchor = $this->exchange();
        $this->scene('Lydia', 'Said afterwards, Dragonborn.', self::T0 + 500);
        $this->assertSame([], $this->window($anchor, $this->cfg())['earlier']);
    }

    // ------------------------------------------------------------------ the evaluator end to end

    public function testTheProducersOwnConfigTurnsSceneLinesOnByDefault(): void
    {
        $this->scene('Lydia', 'Let us keep moving, Dragonborn.', self::T0 - 3000);
        $anchor = $this->exchange();
        $window = RelDynEval::conversationWindow(self::NPC, self::PLAYER, $anchor, 12, 200, null, RelDynEval::config());
        $this->assertSame(['Let us keep moving, Dragonborn.'], self::texts(array_filter($window['earlier'], static fn($l) => !empty($l['scene']))),
            'the producer config (what evaluateJob passes) has scene lines on');
        $this->setConfig([], ['scene_lines' => false]);
        $off = RelDynEval::conversationWindow(self::NPC, self::PLAYER, $anchor, 12, 200, null, RelDynEval::config());
        $this->assertSame([], $off['earlier'], 'and a stored scene_lines false switches them off');
    }

    // ------------------------------------------------------------------ the fork hook gate

    public function testTheGateSkipsLinesHeardInTheForgottenCityOnly(): void
    {
        $this->assertTrue(RelDynEval::backgroundChatSkipped(self::FORGOTTEN . 'Lydia: Hm.'));
        $this->assertFalse(RelDynEval::backgroundChatSkipped(self::WHITERUN . 'Lydia: Hm.'));
        $this->assertTrue(RelDynEval::backgroundChatSkipped('Lydia: no stamp', '(Context location: The Forgotten City, Hold)'), 'no stamp: core\'s cached location');
        $this->assertFalse(RelDynEval::backgroundChatSkipped('Lydia: no stamp', '(Context location: Whiterun)'));
        $this->assertFalse(RelDynEval::backgroundChatSkipped('Lydia: no stamp', null));
        $this->assertFalse(RelDynEval::backgroundChatSkipped(self::WHITERUN . 'Lydia: Hm.', '(Context location: The Forgotten City)'), 'the line\'s own stamp beats the cache');
    }

    public function testTheGateIsOffWithItsFlagWithRelDynOffOrWithAnEmptyList(): void
    {
        $line = self::FORGOTTEN . 'Lydia: Hm.';
        $this->setConfig([], ['scene_skip_write' => false]);
        $this->assertFalse(RelDynEval::backgroundChatSkipped($line), 'scene_skip_write off');
        $this->setConfig(['enabled' => false]);
        $this->assertFalse(RelDynEval::backgroundChatSkipped($line), 'RelDyn off');
        $this->setConfig([], ['scene_excluded_locations' => []]);
        $this->assertFalse(RelDynEval::backgroundChatSkipped($line), 'no locations');
        $this->setConfig([], ['scene_excluded_locations' => ['Whiterun']]);
        $this->assertTrue(RelDynEval::backgroundChatSkipped(self::WHITERUN . 'Lydia: Hm.'), 'the list is a config value');
        $this->assertFalse(RelDynEval::backgroundChatSkipped($line), 'and replaces the default one');
    }

    public function testCoresLogEventHookStoresOrSkipsBackgroundLines(): void
    {
        require_once $GLOBALS['ENGINE_PATH'] . 'lib/data_functions.php';   // logEvent() asks it who is in close range
        $this->event('infonpc_close', 'beings in range:Lydia|Aela', self::T0 - 10);
        $count = fn(string $type) => (int) pg_fetch_result(pg_query_params($this->db->link, 'SELECT count(*) FROM eventlog WHERE type = $1', [$type]), 0, 0);

        logEvent(['chat', 1, self::T0, self::FORGOTTEN . 'Lydia: The bell again.', 'pending'], '|Lydia|');
        $this->assertSame(0, $count('chat_background'), 'a Forgotten City line is not stored');

        logEvent(['chat', 2, self::T0 + 1, self::WHITERUN . 'Lydia: Fine weather.', 'pending'], '|Lydia|');
        $this->assertSame(1, $count('chat_background'), 'a Whiterun line is');

        logEvent(['chat', 3, self::T0 + 2, 'Lydia: Spoken to Kaida. (talking to Kaida)', 'pending'], '|Lydia|');
        $this->assertSame(1, $count('chat'), 'ordinary dialogue is never gated');

        $this->assertSame(0, logEvent(['chat', 3, self::T0 + 3, self::FORGOTTEN . 'Lydia: Skipped, id asked.', 'pending'], '|Lydia|', true), 'a skipped line returns no event id');
        $this->assertSame(1, $count('chat_background'));

        $this->setConfig([], ['scene_skip_write' => false]);
        logEvent(['chat', 4, self::T0 + 4, self::FORGOTTEN . 'Lydia: The bell once more.', 'pending'], '|Lydia|');
        $this->assertSame(2, $count('chat_background'), 'with the flag off core stores it as it always did');

        $this->setConfig(['enabled' => false]);
        logEvent(['chat', 5, self::T0 + 5, self::FORGOTTEN . 'Lydia: RelDyn is off.', 'pending'], '|Lydia|');
        $this->assertSame(3, $count('chat_background'), 'RelDyn off: stored');
    }
}
