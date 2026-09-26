<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/relationship_manager.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Config row (conf_opts) and core's speech rows with the player per NPC: everything else reads as absent. */
final class RelDynPromptGatingConfigDb
{
    public ?string $value = null;
    /** npc => speech rows between her and the player (core's speech table) */
    public array $speech = [];
    public array $speechQueries = [];
    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (strpos($sql, 'FROM speech') !== false) {
            $this->speechQueries[] = $sql;
            foreach ($this->speech as $npc => $n) {
                if (strpos($sql, "speaker = '" . addslashes($npc) . "'") !== false) return ['n' => (string) $n];
            }
            return ['n' => '0'];
        }
        return (strpos($sql, 'conf_opts') !== false && $this->value !== null) ? ['value' => $this->value] : [];
    }
    public function fetchAll($sql) { return []; }
    public function escape($s) { return addslashes((string) $s); }
    public function escapeLiteral($s) { return "'" . addslashes((string) $s) . "'"; }
}

/**
 * Prompt gating (prompt-gating-tier-knowledge, -tier-floor, -fame, -core-hook; Wondernutts' design,
 * decisions §7 and §18 #6): who knows the player. The pure parts: the hold map and the fames' reach
 * (the April design's values), a fame that never names the player, the knowledge levels from the
 * tier, the floor and a met history (core's speech rows, a hostile start), the rumour budget,
 * RelDyn's own text, and the CHIM fork hook's registration and scope. No database: the stored state
 * and the profile are the Postgres test beds' part (RelDynPromptGatingTestBedsPostgresTest).
 */
final class RelDynPromptGatingTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RELATIONSHIP_SYSTEM_ENABLED', 'CHIM_PLAYER_KNOWLEDGE_GATES'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
        }
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RELATIONSHIP_SYSTEM_ENABLED'] as $k) unset($GLOBALS[$k]);
        // core loads the gate files on its first question; load them now so tearDown restores them
        chimPlayerKnowledgeFor('');
        $this->saved['CHIM_PLAYER_KNOWLEDGE_GATES'] = [$GLOBALS['CHIM_PLAYER_KNOWLEDGE_GATES'] ?? []];
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** RelDyn state at core affinity $aff (points), core's affinity mirrored in. */
    private static function at(float $aff, array $extra = []): array
    {
        $x = ($aff + 100.0) / 2.0;
        return array_replace(['_aff_mirror_x' => $x, 'dimensions' => ['affinity' => ['x' => $x, 'baseline' => 50.0]]], $extra);
    }

    private function config(array $gating): void
    {
        $db = new RelDynPromptGatingConfigDb();
        $db->value = json_encode(['prompt_gating' => $gating]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ holds

    public function testHoldsAreCoresCanonicalHoldsAndBordersCountBothWays(): void
    {
        $this->assertSame(RelDynGating::holdKey('Whiterun Hold'), RelDynGating::holdKey('Whiterun'));
        $this->assertSame(RelDynGating::holdKey('The Pale'), RelDynGating::holdKey('pale'));
        $this->assertSame(0, RelDynGating::holdDistance('Whiterun', 'Whiterun Hold'));
        $this->assertSame(1, RelDynGating::holdDistance('Whiterun Hold', 'The Pale'));
        $this->assertSame(2, RelDynGating::holdDistance('Whiterun', 'Winterhold'));
        $this->assertSame(2, RelDynGating::holdDistance('Winterhold', 'Whiterun'));
        $this->assertSame(3, RelDynGating::holdDistance('Haafingar', 'Winterhold'), 'Solitude to the College: across Skyrim');
        // The April graph (prompt-gating-design.md hold_adjacency, read both ways): no Whiterun-Rift
        // or Reach-Falkreath border of its own
        $this->assertSame(2, RelDynGating::holdDistance('Whiterun', 'The Rift'), 'the design: two holds away');
        $this->assertSame(2, RelDynGating::holdDistance('Falkreath Hold', 'The Reach'));
        $this->assertSame(4, RelDynGating::holdDistance('The Rift', 'Haafingar'));
        $this->assertSame(1, RelDynGating::holdDistance('The Reach', 'Hjaalmarch'), "the Reach's own list");
        $this->assertSame(1, RelDynGating::holdDistance('The Pale', 'Eastmarch'), "Eastmarch's own list");
        $this->assertNull(RelDynGating::holdDistance('Solstheim', 'Whiterun'), 'not on the map');
        $this->assertNull(RelDynGating::holdDistance('', 'Whiterun'));
        // A border listed on one side only still joins both holds
        $cfg = RelDynGating::config();
        $cfg['hold_adjacency'] = ['A' => ['B'], 'C' => ['B']];
        $this->assertSame(2, RelDynGating::holdDistance('A', 'C', $cfg));
        $this->assertSame(2, RelDynGating::holdDistance('C', 'A', $cfg));
    }

    // ------------------------------------------------------------------ fame x proximity

    public function testAFameIsHeardWithinTheDesignsReachAndNeverNamesThePlayer(): void
    {
        // The April design's fame_location_gating: home hold and max_distance
        $fames = RelDynReputation::configDefaults()['fames'];
        $this->assertSame(['Whiterun Hold', 2], [$fames['companions']['home'], $fames['companions']['reach']]);
        $this->assertSame(['Winterhold', 2], [$fames['college']['home'], $fames['college']['reach']]);
        $this->assertSame(['The Rift', 3], [$fames['thieves_guild']['home'], $fames['thieves_guild']['reach']]);
        $this->assertSame(['The Rift', 3], [$fames['dawnguard']['home'], $fames['dawnguard']['reach']]);
        foreach (['dragonborn', 'dark_brotherhood', 'civil_war', 'renown', 'notoriety'] as $key) {
            $this->assertSame([null, 9], [$fames[$key]['home'], $fames[$key]['reach']], "{$key}: heard everywhere");
        }
        // The design's Dragonborn fragment is tales of a Dragonborn, not of the player
        $this->assertStringNotContainsString('{PLAYER}', $fames['dragonborn']['text']);
        $this->assertArrayNotHasKey('name_min_score', RelDynGating::configDefaults(), 'fame never names the player');

        $scores = ['companions' => 0.62, 'renown' => 0.4, 'dragonborn' => null, 'college' => 0.2, 'notoriety' => 0.0];
        $here = RelDynGating::heardFames('Whiterun', $scores);
        $this->assertSame(['companions', 'renown'], array_keys($here), 'most famous first; unknown or below min_score: not talked about');
        $this->assertSame(0, $here['companions']['distance']);
        $this->assertArrayNotHasKey('names', $here['companions']);

        $this->assertSame(1, RelDynGating::heardFames('The Pale', $scores)['companions']['distance']);
        $this->assertSame(2, RelDynGating::heardFames('Winterhold', $scores)['companions']['distance'], 'two holds out: within its reach');
        $this->assertArrayNotHasKey('companions', RelDynGating::heardFames('Solstheim', $scores), 'off the map: beyond its reach');
        $this->assertArrayNotHasKey('companions', RelDynGating::heardFames('', $scores), 'her hold unknown: only what is heard everywhere');
        $this->assertArrayHasKey('renown', RelDynGating::heardFames('', $scores));
        // The Thieves Guild (the Rift, three steps) does not reach Solitude (four)
        $this->assertArrayHasKey('thieves_guild', RelDynGating::heardFames('Whiterun', ['thieves_guild' => 0.5]));
        $this->assertArrayNotHasKey('thieves_guild', RelDynGating::heardFames('Haafingar', ['thieves_guild' => 0.5]));
    }

    // ------------------------------------------------------------------ knowledge

    public function testWhatSheKnowsFollowsTheTierAndTheFloorHoldsIt(): void
    {
        $friend = RelDynGating::knowledge('Aela the Huntress', self::at(45.0));
        $this->assertSame(['personal', true, true, true], [$friend['level'], $friend['name'], $friend['bio'], $friend['relationship']]);
        $this->assertSame([], $friend['fames'], 'she knows the player too well for rumours to matter');

        $acq = RelDynGating::knowledge('Ashe', self::at(20.0));
        $this->assertSame(['personal', true, false, true], [$acq['level'], $acq['name'], $acq['bio'], $acq['relationship']], 'a name, not the story');

        $stranger = RelDynGating::knowledge('Lynly Star-Sung', self::at(0.0));
        $this->assertSame(['stranger', false, false, false], [$stranger['level'], $stranger['name'], $stranger['bio'], $stranger['relationship']]);

        // The floor: you can only be unknown once
        $lapsed = RelDynGating::knowledge('Ashe', self::at(0.0, [RelDynGating::KEY => ['peak_core_aff' => 20.0]]));
        $this->assertSame(['lapsed', true, false, true], [$lapsed['level'], $lapsed['name'], $lapsed['bio'], $lapsed['relationship']]);
        $this->assertSame(20.0, $lapsed['peak_core_aff']);
        $fallen = RelDynGating::knowledge('Muiri', self::at(-40.0, [RelDynGating::KEY => ['peak_core_aff' => 45.0]]));
        $this->assertSame(['lapsed', true, true], [$fallen['level'], $fallen['name'], $fallen['bio']], 'what she learned stays learned');
        $legacy = RelDynGating::knowledge('Lydia', self::at(0.0, ['context_tier_hwm' => 1]));
        $this->assertSame('lapsed', $legacy['level'], 'a context tier reached before the floor was kept counts');
    }

    /**
     * Met, never warm: an NPC with a history with the player whose affinity never reached the
     * floor is not a stranger. Core's speech rows between them, or a start in hostility (core
     * affinity below the stranger band: something has passed between them), or RelDyn's own
     * interaction history (RelDynGating::metHistory). She knows the name (core's block and its
     * familiarity note name him to her), not the story, and core's relationship line stays.
     */
    public function testAnNpcWhoHasMetThePlayerIsNeverAStranger(): void
    {
        $this->config([]);
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        // Antagonised from the start: core affinity -50, never positive, no context tier
        $hostile = RelDynGating::knowledge('Nazeem', self::at(-50.0));
        $this->assertSame(['met', true, false, true], [$hostile['level'], $hostile['name'], $hostile['bio'], $hostile['relationship']]);
        $this->assertSame(-50.0, $hostile['peak_core_aff'], 'no floor reached');
        // Core's speech rows: Birna (aff 3) has talked with him a dozen times
        $GLOBALS['db']->speech = ['Birna' => 12, 'Brina Merilis' => 3];
        $birna = RelDynGating::knowledge('Birna', self::at(3.0));
        $this->assertSame(['met', true, false, true], [$birna['level'], $birna['name'], $birna['bio'], $birna['relationship']]);
        $this->assertGreaterThanOrEqual(1, $birna['speech']);
        $q = end($GLOBALS['db']->speechQueries);
        $this->assertStringContainsString("speaker = 'Birna' AND listener = 'Kaida'", $q, 'both ways');
        $this->assertStringContainsString("speaker = 'Kaida' AND listener = 'Birna'", $q);
        // Brina: three exchanges, core affinity -40: met, and it shows as hostility
        $this->assertSame('met', RelDynGating::knowledge('Brina Merilis', self::at(-40.0))['level']);
        // RelDyn's own interaction history counts as meeting him too
        $this->assertSame('met', RelDynGating::knowledge('Lynly Star-Sung', self::at(0.0, ['interaction_count' => 2]))['level']);
        // ... and a true stranger stays one: no row, no history, the stranger band
        $this->assertSame('stranger', RelDynGating::knowledge('Lynly Star-Sung', self::at(0.0, ['_core_rel_type' => 'professional']))['level'],
            "core's relationship type alone is no history");
        $never = RelDynGating::knowledge('Lynly Star-Sung', self::at(0.0));
        $this->assertSame(['stranger', false, false], [$never['level'], $never['name'], $never['relationship']]);
        $this->assertSame(0, $never['speech']);
        // The threshold is config (core's speech rows between them)
        $this->config(['met_min_speech' => 20]);
        $GLOBALS['db']->speech = ['Birna' => 12];
        $this->assertSame('stranger', RelDynGating::knowledge('Birna', self::at(3.0))['level']);
        $this->assertStringContainsString('LIMIT 20', end($GLOBALS['db']->speechQueries), 'counted up to the threshold only');
    }

    public function testThePeakOnlyRisesAndWaitsForCoresAffinity(): void
    {
        $d = ['dimensions' => []];
        $this->assertFalse(RelDynGating::notePeak($d), 'no mirror yet: nothing to note');
        $d = self::at(20.0);
        $this->assertTrue(RelDynGating::notePeak($d));
        $this->assertSame(20.0, $d[RelDynGating::KEY]['peak_core_aff']);
        $d = self::at(-10.0, [RelDynGating::KEY => $d[RelDynGating::KEY]]);
        $this->assertFalse(RelDynGating::notePeak($d));
        $this->assertSame(20.0, $d[RelDynGating::KEY]['peak_core_aff']);
    }

    public function testRumoursKeepToTheLineCapAndTheBudget(): void
    {
        $k = ['name' => false, 'fames' => [
            'dragonborn' => ['text' => '{NAME} has heard that {PLAYER} is the Dragonborn.'],
            'companions' => ['text' => '{NAME} has heard that {PLAYER} runs with the Companions.'],
            'renown' => ['text' => '{NAME} has heard tavern stories about {PLAYER}.'],
        ]];
        $text = RelDynGating::withRumours('Base.', 'Hulda', null, $k);
        $this->assertSame("Base. Hulda has heard that someone of this stranger's description is the Dragonborn. "
            . "Hulda has heard that someone of this stranger's description runs with the Companions.", $text, 'two lines at most');
        $this->assertStringContainsString('Kaida is the Dragonborn', RelDynGating::withRumours('Base.', 'Hulda', 'Kaida', $k));
        $cfg = RelDynGating::config();
        $cfg['token_budget'] = RelDynFelt::estimateTokens('Base.') + 5;
        $this->assertSame('Base.', RelDynGating::withRumours('Base.', 'Hulda', null, $k, $cfg), 'over the budget: no rumour');
    }

    public function testRelDynsOwnTextFollowsTheGate(): void
    {
        $companions = ['companions' => ['score' => 0.62, 'distance' => 0,
            'text' => (string) RelDynReputation::configDefaults()['fames']['companions']['text']]];
        // Renowned: never met, the stories heard; the name is not among them (the April design:
        // #PLAYER_REF# is the name only from acquaintance)
        $renowned = ['level' => 'renowned', 'name' => false, 'fames' => $companions];
        $text = RelDynFelt::knowledgeOfPlayer('Hulda', 'Kaida', self::at(0.0), null, $renowned);
        $this->assertStringContainsString('Hulda has never met this stranger, only heard the stories', $text);
        $this->assertStringContainsString("someone of this stranger's description runs with the Companions", $text);
        $this->assertStringNotContainsString('Kaida', $text);
        $this->assertSame('this stranger', RelDynFelt::playerRef('Kaida', 0, self::at(0.0), null, $renowned));

        // Met, never warm: the name, no familiarity
        $met = ['level' => 'met', 'name' => true, 'fames' => []];
        $text = RelDynFelt::knowledgeOfPlayer('Birna', 'Kaida', self::at(3.0), null, $met);
        $this->assertStringContainsString('Birna has crossed paths with Kaida before and knows the name', $text);
        $this->assertStringContainsString('Brina knows Kaida only as trouble',
            RelDynFelt::knowledgeOfPlayer('Brina', 'Kaida', self::at(-40.0), null, $met), 'a hostile history, named');

        $lapsed = ['level' => 'lapsed', 'name' => true, 'fames' => []];
        $text = RelDynFelt::knowledgeOfPlayer('Ashe', 'Kaida', self::at(0.0), null, $lapsed);
        $this->assertStringContainsString('met Kaida before and remembers the name', $text);
        $hostile = RelDynFelt::knowledgeOfPlayer('Muiri', 'Kaida', self::at(-40.0), null, $lapsed);
        $this->assertStringContainsString('knows Kaida only as trouble', $hostile, 'the bond fell into hostility: she still knows who');
        $never = RelDynFelt::knowledgeOfPlayer('Jora', 'Kaida', self::at(0.0), null, ['level' => 'stranger', 'name' => false, 'fames' => []]);
        $this->assertStringContainsString('knows nothing of their name', $never);
        $this->assertStringNotContainsString('Kaida', $never);
        foreach ([$text, $hostile, $never] as $t) $this->assertDoesNotMatchRegularExpression('/\d/', $t, 'feelings, never numbers');

        // The context tier's high-water mark holds an acquaintance at tier 1 while the bond fell
        // away: she remembers him, not with polite familiarity
        $held = RelDynFelt::knowledgeOfPlayer('Muiri', 'Kaida', self::at(-40.0, ['context_tier_hwm' => 1]));
        $this->assertStringContainsString('Muiri knows Kaida only as trouble', $held);
        $this->assertStringContainsString('Ashe has met Kaida before and remembers the name',
            RelDynFelt::knowledgeOfPlayer('Ashe', 'Kaida', self::at(0.0, ['context_tier_hwm' => 1])));
        $this->assertStringContainsString('by name and a few shared words',
            RelDynFelt::knowledgeOfPlayer('Ashe', 'Kaida', self::at(20.0, ['context_tier_hwm' => 1])));
    }

    // ------------------------------------------------------------------ the CHIM fork hook

    public function testTheCoreHookAsksRelDynAndIsInertWithoutIt(): void
    {
        $this->assertArrayHasKey('reldyn', $GLOBALS['CHIM_PLAYER_KNOWLEDGE_GATES'], 'core found player_knowledge.php');
        $this->assertContains(realpath(__DIR__ . '/../../ext/relationship_dynamics/player_knowledge.php'),
            array_map('realpath', get_included_files()));
        // No stored state, no core entry: a stranger
        $gate = chimPlayerKnowledgeFor('Lynly Star-Sung');
        $this->assertSame(['bio' => false, 'relationship' => false, 'level' => 'stranger'],
            ['bio' => $gate['bio'], 'relationship' => $gate['relationship'], 'level' => $gate['level']]);
        $this->assertSame('Lynly Star-Sung has never met this person and does not know their name, past or deeds', $gate['note']);
        $this->assertNull(chimPlayerKnowledgeFor('The Narrator'), 'the Narrator knows everything: core decides');
        $this->assertNull(chimPlayerKnowledgeFor(''));

        // Core's relationship block names the player only while RelDyn does not gate it
        $GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'] = true;
        $this->assertFalse(RelDynFelt::coreNamesPlayer());
        $this->config(['enabled' => false]);
        $this->assertNull(chimPlayerKnowledgeFor('Lynly Star-Sung'), 'prompt gating off: core decides');
        $this->assertTrue(RelDynFelt::coreNamesPlayer());
        $GLOBALS['db']->value = json_encode(['enabled' => false, 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        RelationshipDynamics::clearConfigCache();
        $this->assertNull(chimPlayerKnowledgeFor('Lynly Star-Sung'), 'RelDyn off: core decides');

        // RelDyn not installed: no gate registered, core's behaviour
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        unset($GLOBALS['CHIM_PLAYER_KNOWLEDGE_GATES']);
        $this->assertNull(chimPlayerKnowledgeFor('Lynly Star-Sung'));
    }

    /**
     * The hook keeps to the ruling (§18 #6: strangers don't know the player's name): it speaks for
     * an NPC only. Core's non-NPC callers (player_rewrite.php, the rolemaster processors: HERIKA_NAME
     * "(actor)", for whom core adds no familiarity note) get core's behaviour. Core's own
     * familiarity note stands wherever core already knows they have talked; RelDyn's note replaces
     * it only where core would call a known NPC a stranger (no speech row), or for a stranger.
     */
    public function testTheCoreHookSpeaksOnlyForNpcsAndKeepsCoresOwnNote(): void
    {
        $this->config([]);
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->assertNull(chimPlayerKnowledgeFor('(actor)'), "core's non-NPC callers: core decides");
        $this->assertNull(RelDynGating::coreGate('(actor)'));

        // Brina: three exchanges (core's speech rows), core affinity never positive
        $GLOBALS['db']->speech = ['Brina Merilis' => 3];
        $gate = chimPlayerKnowledgeFor('Brina Merilis');
        $this->assertSame(['bio' => false, 'relationship' => true, 'level' => 'met'],
            ['bio' => $gate['bio'], 'relationship' => $gate['relationship'], 'level' => $gate['level']]);
        $this->assertNull($gate['note'], "core's own note ('has talked to Kaida a couple of times before')");

        // The note, by level and whether core knows they have talked
        $k = fn(string $level, int $speech) => ['level' => $level, 'bio' => false, 'relationship' => in_array($level, ['met', 'lapsed', 'personal'], true), 'speech' => $speech];
        $this->assertNull(RelDynGating::gateAnswer($k('personal', 4), 'Ashe')['note']);
        $this->assertSame('Ashe knows this person', RelDynGating::gateAnswer($k('personal', 0), 'Ashe')['note'], "core would call her a stranger");
        $this->assertNull(RelDynGating::gateAnswer($k('lapsed', 1), 'Ashe')['note']);
        $this->assertSame('Ashe has met this person before', RelDynGating::gateAnswer($k('lapsed', 0), 'Ashe')['note']);
        $this->assertSame('Nazeem has crossed paths with this person before', RelDynGating::gateAnswer($k('met', 0), 'Nazeem')['note'],
            'a hostile start with no word spoken');
        $this->assertSame('Hulda has never met this person and does not know their name, but has heard of their deeds',
            RelDynGating::gateAnswer($k('renowned', 0), 'Hulda')['note']);
        $this->assertSame('Jora has never met this person and does not know their name, past or deeds',
            RelDynGating::gateAnswer($k('stranger', 0), 'Jora')['note']);
    }
}
