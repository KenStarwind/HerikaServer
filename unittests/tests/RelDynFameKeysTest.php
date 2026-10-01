<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** conf_opts only: the stored RelDyn config row (everything else reads as absent). */
final class RelDynFameKeysConfigDb
{
    public ?string $value = null;
    public function fetchOne($sql, $params = null)
    {
        return (strpos((string) $sql, 'conf_opts') !== false && $this->value !== null) ? ['value' => $this->value] : [];
    }
    public function fetchAll($sql) { return []; }
    public function escape($s) { return addslashes((string) $s); }
    public function escapeLiteral($s) { return "'" . addslashes((string) $s) . "'"; }
}

/**
 * Prompt gating, the fame keys of the April design that the first port left out
 * (prompt-gating-fame; prompt-gating-design.md fame_fragments / fame_location_gating): thane (the
 * hold where held), the Bards College, Volkihar and the civil-war side split (Imperial or
 * Stormcloak), and the Dawnguard kept to the Dawnguard's own side. Core 3.4.1 keeps no player
 * factions, so each is read from the journal's quest editor ids (and the quest stage log for the
 * thane title). The pure parts: the keys' homes and reach, which fame supersedes which, the
 * thane builder, the questline prefixes. The player profile and the four test beds are the
 * Postgres part (RelDynPlayerProfilePostgresTest, RelDynPromptGatingTestBedsPostgresTest).
 */
final class RelDynFameKeysTest extends TestCase
{
    private array $savedDb = [];

    protected function setUp(): void
    {
        $this->savedDb = array_key_exists('db', $GLOBALS) ? [$GLOBALS['db']] : [];
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb) $GLOBALS['db'] = $this->savedDb[0]; else unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
    }

    private static function fames(): array
    {
        return RelDynReputation::configDefaults()['fames'];
    }

    private static function line(string $editorId, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (stripos($editorId, $p) === 0) return true;
        }
        return false;
    }

    public function testTheMissingAprilKeysCarryTheDesignsHomeAndReach(): void
    {
        $f = self::fames();
        foreach (['civil_war_imperial', 'civil_war_stormcloak', 'volkihar'] as $key) {
            $this->assertSame([null, 9], [$f[$key]['home'], $f[$key]['reach']], "{$key}: heard everywhere");
        }
        $this->assertSame(['Haafingar', 2], [$f['bards_college']['home'], $f['bards_college']['reach']]);
        $this->assertSame(['Whiterun Hold', 0], [$f['thane_whiterun']['home'], $f['thane_whiterun']['reach']], 'the hold where it is held');
        // the April fragments, the fame never giving the name away ({PLAYER} becomes the stranger's description)
        $this->assertStringContainsString('Imperial Legion', $f['civil_war_imperial']['text']);
        $this->assertStringNotContainsString('Stormcloak', $f['civil_war_imperial']['text']);
        $this->assertStringContainsString('Stormcloaks', $f['civil_war_stormcloak']['text']);
        $this->assertStringNotContainsString('Legion', $f['civil_war_stormcloak']['text']);
        $this->assertStringContainsString('Bards College', $f['bards_college']['text']);
        $this->assertStringContainsString('vampiric powers', $f['volkihar']['text']);
        $this->assertStringContainsString('Thane of Whiterun', $f['thane_whiterun']['text']);
        foreach (['civil_war_imperial', 'civil_war_stormcloak', 'bards_college', 'volkihar', 'thane_whiterun'] as $key) {
            $this->assertStringContainsString('{PLAYER}', $f[$key]['text'], $key);
            $this->assertStringContainsString('{NAME}', $f[$key]['text'], $key);
            $this->assertDoesNotMatchRegularExpression('/\d/', $f[$key]['text'], "{$key}: feelings, never numbers");
        }
    }

    /** Audit bug: the Dawnguard's fame came from questline prefix DLC1, so a Volkihar player was rumoured to hunt vampires. */
    public function testTheDawnguardFameIsTheDawnguardsSideAndVolkiharIsTheOther(): void
    {
        $f = self::fames();
        $this->assertSame(['questline:dawnguard_hunter'], array_keys($f['dawnguard']['evidence']), 'no side-less stat: it would rumour a Volkihar player too');
        $this->assertSame(['questline:volkihar'], array_keys($f['volkihar']['evidence']));
        $this->assertSame(['The Rift', 3], [$f['dawnguard']['home'], $f['dawnguard']['reach']], 'unchanged');
        $lines = RelDynPlayer::configDefaults()['questlines_fine'];
        $hunter = $lines['dawnguard_hunter'];
        $vampire = $lines['volkihar'];
        foreach ($hunter as $prefix) {
            $this->assertFalse(self::line($prefix, $vampire), "{$prefix}: one side only");
        }
        // the shared quests (Awakening, Bloodline, Kindred Judgment...) belong to neither side
        foreach (['DLC1VQ00', 'DLC1VQ01', 'DLC1VQ02', 'DLC1VQ05', 'DLC1VQ08'] as $shared) {
            $this->assertFalse(self::line($shared, $hunter), $shared);
            $this->assertFalse(self::line($shared, $vampire), $shared);
        }
        // the side-bearing editor ids of core's own quest data (data/skyrim_quest_definitions.json)
        $this->assertTrue(self::line('DLC1VQ03Hunter', $hunter));
        $this->assertTrue(self::line('DLC1HunterBaseIntro', $hunter));
        $this->assertTrue(self::line('DLC1RH07', $hunter));
        $this->assertTrue(self::line('DLC1VQ03Vampire', $vampire));
        $this->assertTrue(self::line('DLC1VampireBaseIntro', $vampire));
        $this->assertTrue(self::line('DLC1RV08', $vampire));
    }

    public function testTheCivilWarSidesAndTheBardsCollegeComeFromQuestIds(): void
    {
        $lines = RelDynPlayer::configDefaults()['questlines_fine'];
        $this->assertTrue(self::line('CW00A', $lines['civil_war_imperial']), 'Joining the Legion');
        $this->assertTrue(self::line('CW02A', $lines['civil_war_imperial']), 'The Jagged Crown (Imperial)');
        $this->assertTrue(self::line('CW01B', $lines['civil_war_stormcloak']), 'Joining the Stormcloaks');
        $this->assertTrue(self::line('CW02B', $lines['civil_war_stormcloak']), 'The Jagged Crown (Stormcloaks)');
        // one editor id for both sides (CW03, CWMission03, CWFortSiegeFort, CWObj): neither side's evidence
        foreach (['CW03', 'CWMission03', 'CWMission07', 'CWFortSiegeFort', 'CWObj', 'CWSiegeObj'] as $both) {
            $this->assertFalse(self::line($both, $lines['civil_war_imperial']), $both);
            $this->assertFalse(self::line($both, $lines['civil_war_stormcloak']), $both);
        }
        $this->assertTrue(self::line('BardsCollegeLute', $lines['bards_college']));
        $this->assertFalse(self::line('MQ101', $lines['bards_college']));
        // the coarse questlines (attraction's markers, archetype deeds) are untouched
        $coarse = RelDynPlayer::configDefaults()['questlines'];
        $this->assertSame(['CW'], $coarse['civil_war']);
        $this->assertSame(['DLC1'], $coarse['dawnguard']);
    }

    public function testAThaneTitleIsHeardOnlyInThatHold(): void
    {
        $scores = ['thane_whiterun' => 0.8, 'renown' => 0.5];
        $here = RelDynGating::heardFames('Whiterun', $scores);
        $this->assertSame(['thane_whiterun', 'renown'], array_keys($here), 'the title first: it is the most famous thing');
        $this->assertSame(0, $here['thane_whiterun']['distance']);
        $this->assertArrayNotHasKey('thane_whiterun', RelDynGating::heardFames('The Pale', $scores), 'one hold out: reach 0');
        $this->assertArrayNotHasKey('thane_whiterun', RelDynGating::heardFames('Solstheim', $scores), 'off the map');
        $this->assertArrayNotHasKey('thane_whiterun', RelDynGating::heardFames('', $scores), 'her hold unknown');
        $this->assertArrayHasKey('renown', RelDynGating::heardFames('', $scores));
    }

    public function testTheBardsCollegeIsHeardTwoHoldsFromSolitude(): void
    {
        $scores = ['bards_college' => 0.4];
        $this->assertArrayHasKey('bards_college', RelDynGating::heardFames('Haafingar', $scores));
        $this->assertSame(1, RelDynGating::heardFames('Hjaalmarch', $scores)['bards_college']['distance']);
        $this->assertSame(2, RelDynGating::heardFames('Whiterun', $scores)['bards_college']['distance']);
        $this->assertArrayNotHasKey('bards_college', RelDynGating::heardFames('Winterhold', $scores), 'three holds out');
        $this->assertArrayHasKey('volkihar', RelDynGating::heardFames('Solstheim', ['volkihar' => 0.5]), 'heard everywhere, off the map too');
    }

    /** Where the side is known the war is the side's: the side-less line would say it twice. */
    public function testASideSupersedesTheSideLessCivilWarLine(): void
    {
        $f = self::fames();
        $this->assertSame(['civil_war_imperial', 'civil_war_stormcloak'], $f['civil_war']['superseded_by']);
        $both = RelDynGating::heardFames('Whiterun', ['civil_war' => 0.7, 'civil_war_stormcloak' => 0.4]);
        $this->assertSame(['civil_war_stormcloak'], array_keys($both));
        $only = RelDynGating::heardFames('Whiterun', ['civil_war' => 0.7]);
        $this->assertSame(['civil_war'], array_keys($only), 'only the war stat: the side is not known');
        $below = RelDynGating::heardFames('Whiterun', ['civil_war' => 0.7, 'civil_war_imperial' => 0.1]);
        $this->assertSame(['civil_war'], array_keys($below), 'a side below its minimum is not heard, so it supersedes nothing');
    }

    public function testTheThaneBuilderMakesAFamePerHoldFromItsQuests(): void
    {
        $fames = RelDynReputation::thaneFames([
            'Whiterun Hold' => ['title' => 'Thane of Whiterun', 'quests' => ['MQ104' => 160]],
            'The Rift' => ['title' => 'Thane of the Rift', 'quests' => ['FavorA' => 10, 'FavorB' => 20]],
        ]);
        $this->assertSame(['thane_whiterun', 'thane_rift'], array_keys($fames));
        $this->assertSame(['quest:MQ104@160'], array_keys($fames['thane_whiterun']['evidence']));
        $this->assertSame(['quest:FavorA@10', 'quest:FavorB@20'], array_keys($fames['thane_rift']['evidence']));
        $this->assertSame(['The Rift', 0], [$fames['thane_rift']['home'], $fames['thane_rift']['reach']]);
        $this->assertStringContainsString('Thane of the Rift', $fames['thane_rift']['text']);
        $this->assertSame([], RelDynReputation::thaneFames([]));
        $this->assertSame([], RelDynReputation::thaneFames(['Nowhere' => ['title' => 'x', 'quests' => []]]), 'a hold with no quest to read is not a fame');
        // the shipped table is only what core's own quest data confirms
        $this->assertSame(['Whiterun Hold'], array_keys(RelDynReputation::configDefaults()['thane_holds']));
        $this->assertSame(['MQ104' => 160], RelDynReputation::configDefaults()['thane_holds']['Whiterun Hold']['quests']);
    }

    /** A stored fames table (saved before these keys existed) still gets the thane keys. */
    public function testAStoredFameTableGainsTheThaneKeys(): void
    {
        $db = new RelDynFameKeysConfigDb();
        $db->value = json_encode(['reputation' => ['fames' => ['renown' => self::fames()['renown']]]]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        $cfg = RelDynReputation::config();
        $this->assertSame(['renown', 'thane_whiterun'], array_keys($cfg['fames']));
    }
}
