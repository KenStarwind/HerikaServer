<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$GLOBALS['ENGINE_PATH'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'npc_master.class.php';

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

    public function fetchOne(string $query): ?array
    {
        if (preg_match("/npc_name = '((?:[^']|'')*)'/", $query, $m)) {
            $name = str_replace("''", "'", $m[1]);
            if (isset($this->npcs[$name])) {
                return [
                    'id' => 1,
                    'npc_name' => $name,
                    'extended_data' => json_encode(['relationships' => $this->npcs[$name]]),
                ];
            }
        }
        return null;
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
}
