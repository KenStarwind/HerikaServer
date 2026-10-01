<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$GLOBALS['ENGINE_PATH'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'logger.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'relationship_manager.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'eventlog_helper.php';
require_once $GLOBALS['ENGINE_PATH'] . 'lib' . DIRECTORY_SEPARATOR . 'core'
    . DIRECTORY_SEPARATOR . 'npc_master.class.php';

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
}
