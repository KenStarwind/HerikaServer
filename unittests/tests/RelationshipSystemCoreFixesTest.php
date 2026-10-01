<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$GLOBALS['ENGINE_PATH'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'npc_master.class.php';
require_once $GLOBALS['ENGINE_PATH'] . 'ext' . DIRECTORY_SEPARATOR . 'relationship_system'
    . DIRECTORY_SEPARATOR . 'relationship_llm.php';

// postrequest.php is a script that returns immediately while the relationship system is
// disabled, but its helper functions are still declared. Load it with the system off so the
// helpers can be tested without running the script.
$relSysEnabledBeforeLoad = $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] ?? null;
unset($GLOBALS['RELATIONSHIP_SYSTEM_ENABLED']);
require_once $GLOBALS['ENGINE_PATH'] . 'ext' . DIRECTORY_SEPARATOR . 'relationship_system'
    . DIRECTORY_SEPARATOR . 'postrequest.php';
if ($relSysEnabledBeforeLoad !== null) {
    $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = $relSysEnabledBeforeLoad;
}
unset($relSysEnabledBeforeLoad);

/**
 * Minimal in-memory stand-in for core_npc_master lookups by exact name.
 * $npcs maps an NPC name to that NPC's relationship map.
 */
final class RelationshipSystemFakeDb
{
    public array $npcs = [];
    /** @var array<string,string> prompt_key => prompt text served from the prompts table */
    public array $prompts = [];
    public array $inserts = [];

    private function npcRow(string $name): array
    {
        return [
            'id' => array_search($name, array_keys($this->npcs), true) + 1,
            'npc_name' => $name,
            'extended_data' => json_encode(['relationships' => $this->npcs[$name]]),
        ];
    }

    public function fetchOne(string $query): ?array
    {
        if (preg_match("/npc_name = '((?:[^']|'')*)'/", $query, $m)) {
            $name = str_replace("''", "'", $m[1]);
            return isset($this->npcs[$name]) ? $this->npcRow($name) : null;
        }
        if (preg_match('/FROM core_npc_master WHERE id = (\d+)/', $query, $m)) {
            $names = array_keys($this->npcs);
            return isset($names[(int)$m[1] - 1]) ? $this->npcRow($names[(int)$m[1] - 1]) : null;
        }
        if (preg_match("/prompt_key = '([^']+)'/", $query, $m) && isset($this->prompts[$m[1]])) {
            return ['custom_prompt' => $this->prompts[$m[1]], 'default_prompt' => $this->prompts[$m[1]]];
        }
        return null;
    }

    public function insert(string $table, array $row): bool
    {
        $this->inserts[] = ['table' => $table, 'row' => $row];
        return true;
    }

    public function fetchAll(string $query): array
    {
        return [];
    }

    public function escape($value): string
    {
        return str_replace("'", "''", (string)$value);
    }
}

/** Stands in for the relationship connector driver; records the messages it is asked to send. */
final class RelationshipSystemFakeDriver
{
    public array $requests = [];

    public function fast_request($messages, $params, $context)
    {
        $this->requests[] = $messages;
        return '{"changes": {}}';
    }
}

final class RelationshipSystemCoreFixesTest extends TestCase
{
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'RELLLM_CONNECTOR', 'CACHE_PEOPLE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        $GLOBALS['db'] = new RelationshipSystemFakeDb();
        $GLOBALS['PLAYER_NAME'] = 'Test Player';
        unset($GLOBALS['RELLLM_CONNECTOR']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) {
                unset($GLOBALS[$key]);
            } else {
                $GLOBALS[$key] = $saved[0];
            }
        }
    }

    // --- nearby NPC list: CACHE_PEOPLE is '|'-delimited ---------------------------------

    public function testPeopleListIsSplitOnPipeNotComma(): void
    {
        $this->assertSame(
            ['Lydia', 'Aela the Huntress', 'Lynly Star-Sung'],
            RelationshipManager::parsePeopleList('|Lydia|Aela the Huntress|Lynly Star-Sung|')
        );
    }

    public function testPeopleListKeepsCommasInsideNamesAndDropsEmptyTokens(): void
    {
        $this->assertSame(
            ['Smith, Jon', 'Lydia'],
            RelationshipManager::parsePeopleList(' | Smith, Jon || Lydia |  | ')
        );
        $this->assertSame(['Lydia'], RelationshipManager::parsePeopleList('Lydia'));
        $this->assertSame([], RelationshipManager::parsePeopleList(''));
        $this->assertSame([], RelationshipManager::parsePeopleList('||'));
    }

    public function testNearbyNpcRelationshipsReachThePromptContext(): void
    {
        $GLOBALS['db']->npcs['Lydia'] = [
            'Player' => ['aff' => 20, 'type' => 'platonic'],
            'Aela the Huntress' => ['aff' => 40, 'type' => 'professional'],
            'Faendal' => ['aff' => -10, 'type' => 'rival'],
        ];

        $nearby = RelationshipManager::parsePeopleList('|Lydia|Aela the Huntress|Faendal|');
        $context = RelationshipManager::buildContext('Lydia', $nearby);

        $this->assertStringContainsString('Aela the Huntress: +40', $context);
        $this->assertStringContainsString('Faendal: -10', $context);

        // The old comma split produced one token ("|Lydia|Aela the Huntress|Faendal|") that never
        // matches a relationship key, so nobody except the player was listed.
        $legacy = RelationshipManager::buildContext(
            'Lydia',
            array_map('trim', explode(',', '|Lydia|Aela the Huntress|Faendal|'))
        );
        $this->assertStringNotContainsString('Aela the Huntress', $legacy);
    }

    // --- "PlayerName:" prefix on player input ----------------------------------------------

    public function testSpeakerPrefixIsStrippedForAnyPlayerNameShape(): void
    {
        $cases = [
            'Kaida' => 'Kaida: Hello there',
            'Aela the Huntress' => 'Aela the Huntress: Hello there',
            'Lynly Star-Sung' => 'Lynly Star-Sung: Hello there',
            "J'zargo" => "J'zargo: Hello there",
            'Éowyn Ærin' => 'Éowyn Ærin: Hello there',
            'Ragnar Ørn-Þórsson' => 'Ragnar Ørn-Þórsson: Hello there',
            'Sōren' => 'sōren : Hello there',
        ];
        foreach ($cases as $playerName => $line) {
            $GLOBALS['PLAYER_NAME'] = $playerName;
            $this->assertSame('Hello there', _relStripSpeakerPrefix($line), $line);
        }
    }

    public function testSpeakerPrefixFallbackWorksWhenPlayerNameIsUnknownOrStale(): void
    {
        unset($GLOBALS['PLAYER_NAME']);
        $this->assertSame('Hello', _relStripSpeakerPrefix('Aela the Huntress: Hello'));
        $this->assertSame('Hello', _relStripSpeakerPrefix('Lynly Star-Sung:Hello'));
        $this->assertSame('Hello', _relStripSpeakerPrefix('Éowyn: Hello'));

        $GLOBALS['PLAYER_NAME'] = 'Someone Else';
        $this->assertSame('Hello', _relStripSpeakerPrefix('Aela the Huntress: Hello'));
    }

    public function testSpeakerPrefixOnlyRemovesTheFirstLabelAndLeavesPlainSpeechAlone(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Aela the Huntress';
        $this->assertSame(
            'Look: the door is open',
            _relStripSpeakerPrefix('Aela the Huntress: Look: the door is open')
        );
        $this->assertSame('No prefix here', _relStripSpeakerPrefix('No prefix here'));
        $this->assertSame('', _relStripSpeakerPrefix(''));
        // A long sentence before a colon is speech, not a name.
        $line = 'I have been wondering about the dragons near Whiterun lately, haven\'t you: what do you think';
        $this->assertSame($line, _relStripSpeakerPrefix($line));
    }

    // --- relationship evaluation prompt -------------------------------------------------------

    /**
     * Run RelationshipLLM::evaluateContext() against a fake driver (no network, no database)
     * and return [systemPrompt, userPrompt] as they would be sent to the model.
     */
    private function captureEvalPrompts(string $npcResponse, array $context, string $npcName = 'Lydia'): array
    {
        $GLOBALS['db']->npcs[$npcName] ??= ['Player' => ['aff' => 12, 'type' => 'platonic']];
        $driver = new RelationshipSystemFakeDriver();

        $llm = (new ReflectionClass(RelationshipLLM::class))->newInstanceWithoutConstructor();
        foreach (
            [
                'db' => $GLOBALS['db'],
                'driver' => $driver,
                'connector' => ['driver' => 'test_driver', 'model' => 'test-model', 'label' => 'Test'],
                'modelName' => 'test-model',
            ] as $property => $value
        ) {
            $reflection = new ReflectionProperty(RelationshipLLM::class, $property);
            $reflection->setValue($llm, $value);
        }

        $result = $llm->evaluateContext(1, $npcResponse, $context);
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertCount(1, $driver->requests);
        $this->assertSame('system', $driver->requests[0][0]['role']);
        $this->assertSame('user', $driver->requests[0][1]['role']);

        return [$driver->requests[0][0]['content'], $driver->requests[0][1]['content']];
    }

    public function testEvalPromptDoesNotRepeatTheCurrentReplyAsEarlierDialogue(): void
    {
        // talkedSoFar holds the sentences of the reply that is being evaluated.
        $sentences = ['I will not forget this.', 'You have my thanks, friend.'];
        [, $user] = $this->captureEvalPrompts(
            implode(' ', $sentences),
            ['dialogue' => $sentences, 'player_action' => 'Here, take the amulet.', 'listener_name' => 'Player']
        );

        $this->assertStringNotContainsString('Previous exchanges', $user);
        $this->assertStringNotContainsString('said earlier', $user);
        $this->assertSame(1, substr_count($user, 'I will not forget this.'));
        $this->assertSame(1, substr_count($user, 'You have my thanks, friend.'));
        $this->assertStringContainsString('[Lydia replied]: I will not forget this. You have my thanks, friend.', $user);
    }

    public function testEvalSystemPromptAgreesWithTheUserPrompt(): void
    {
        [$system, $user] = $this->captureEvalPrompts(
            'Thank you, truly.',
            ['player_action' => 'I brought your sword back.', 'listener_name' => 'Player']
        );

        // The user prompt labels the two lines "[<listener> said]" and "[<speaker> replied]".
        $this->assertStringContainsString('[Player said]: I brought your sword back.', $user);
        $this->assertStringContainsString('[Lydia replied]: Thank you, truly.', $user);
        $this->assertStringContainsString("how did Lydia's feelings toward Player change", $user);

        // The system prompt must describe those labels, not tags that are never sent, and must not
        // tell the model to ignore the speaker's own words while the user prompt asks for them.
        $this->assertStringNotContainsString('[PLAYER]', $system);
        $this->assertStringNotContainsString('[NPC]', $system);
        $this->assertStringNotContainsString('Only evaluate based on what PLAYER did', $system);
        $this->assertStringContainsString('said]', $system);
        $this->assertStringContainsString('replied]', $system);
        $this->assertStringContainsString("SPEAKER's feelings toward the LISTENER", $system);
    }

    public function testStoredEvalPromptWithTheOldAttributionBlockIsSuperseded(): void
    {
        // The default seeded by older migrations, plus a "type" mention so the other upgrade
        // rules in getDynamicEvalPrompt() do not apply.
        $GLOBALS['db']->prompts['rel_llm_evaluation'] =
            "SPEAKER ATTRIBUTION:\n- [PLAYER] and [NPC] tags show who said what\n"
            . "- Only evaluate based on what PLAYER did, not the NPC's own words\n"
            . "OUTPUT: {\"changes\": {\"Player\": {\"delta\": 1, \"type\": \"crush\"}}}";

        [$system] = $this->captureEvalPrompts('Hello.', ['listener_name' => 'Player']);

        $this->assertStringNotContainsString('[PLAYER] and [NPC] tags', $system);
        $this->assertStringContainsString("SPEAKER's feelings toward the LISTENER", $system);
    }

    public function testCustomEvalPromptWithoutTheOldBlockIsLeftAlone(): void
    {
        $custom = "Judge this like a bard. Be kind.\nOUTPUT: {\"changes\": {\"Player\": {\"delta\": 1, \"type\": \"crush\"}}}";
        $GLOBALS['db']->prompts['rel_llm_evaluation'] = $custom;

        [$system] = $this->captureEvalPrompts('Hello.', ['listener_name' => 'Player']);

        $this->assertSame($custom, $system);
    }
}
