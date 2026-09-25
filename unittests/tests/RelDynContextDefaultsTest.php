<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory $db for running the real context hook: holds plugin_extended_data per NPC
 * and answers everything else (config row, eventlog, core tables) with "nothing stored".
 */
final class RelDynContextFakeDb
{
    /** @var array<string, array> lower(npc_name) => plugin_extended_data */
    public array $plugin = [];
    /** The stored RelDyn config row (conf_opts), null = none (the shipped defaults). */
    public ?string $config = null;

    private function key(int $id): ?string { return array_keys($this->plugin)[$id - 1] ?? null; }

    public function fetchOne($sql, $params = [])
    {
        $sql = preg_replace('/\s+/', ' ', trim((string)$sql));
        if ($this->config !== null && strpos($sql, "FROM conf_opts WHERE id = 'relationship_dynamics_config'") !== false) {
            return ['value' => $this->config];
        }
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $i = array_search(strtolower((string)$params[0]), array_keys($this->plugin), true);
            return $i === false ? [] : ['id' => (string)($i + 1)];
        }
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $k = $this->key((int)$params[0]);
            if ($k === null) return [];
            $ns = $this->plugin[$k][$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object)$ns)];
        }
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
            $k = $this->key((int)$params[0]);
            if ($k === null) return [];
            $this->plugin[$k][$params[1]][$params[2]] = json_decode($params[3], true);
            return ['id' => (string)$params[0]];
        }
        return [];
    }

    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string)$s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * fresh-start-defaults: with the shipped defaults the dimension context is on, and the
 * context hook never hands the LLM a raw number (decisions 2026-09-23 section 3: felt
 * steering, not numbers). Runs the real ext/relationship_dynamics/context.php.
 */
final class RelDynContextDefaultsTest extends TestCase
{
    private const NPC = 'Serana';
    private array $savedGlobals = [];
    private RelDynContextFakeDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'HERIKA_NAME', 'contextDataFull', 'gameRequest', 'CACHE_PEOPLE',
                  'RELDYN_MASKING_ACTIVE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        $this->db = new RelDynContextFakeDb();
        $GLOBALS['db'] = $this->db;
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['HERIKA_NAME'] = self::NPC;
        unset($GLOBALS['gameRequest'], $GLOBALS['CACHE_PEOPLE'], $GLOBALS['RELDYN_MASKING_ACTIVE']);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** Runs the context hook for a stored state and returns the injected blocks. */
    private function contextFor(array $dynamics): array
    {
        $this->db->plugin = [strtolower(self::NPC) => ['reldyn' => ['dynamics' => $dynamics]]];
        $GLOBALS['contextDataFull'] = [];
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/context.php'; })();
        return array_map(fn($m) => (string)$m['content'], $GLOBALS['contextDataFull']);
    }

    private function baseState(float $affinityMirror, array $dims = [], array $extra = []): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['love_language_primary'] = RelationshipDynamics::LL_TIME;
        $d['love_language_secondary'] = RelationshipDynamics::LL_WORDS;
        $d['inferred_temperament'] = 'Romantic';
        $d['dimensions']['affinity']['x'] = $affinityMirror;   // mirror scale 0..100 = (core aff + 100) / 2
        // Core snapshot equal to the mirror (no uncommitted RelDyn change): getCoreAffinity reads
        // core aff = 2 * mirror - 100 only when prerequest has taken this snapshot; without it the
        // NPC is a core-0 stranger at context tier 0 and none of the tiered blocks render.
        $d['_aff_mirror_x'] = $affinityMirror;
        foreach ($dims as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        return array_merge($d, $extra);
    }

    private function assertNoNumbers(array $blocks, string $case): void
    {
        foreach ($blocks as $block) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $block, "{$case}: raw number reached the LLM:\n{$block}");
        }
    }

    public function testDimensionContextIsOnByDefault(): void
    {
        $this->assertTrue(RelationshipDynamics::defaultConfig()['dimension_context_enabled']);
        $blocks = $this->contextFor($this->baseState(55.0, ['trust' => 5.0]));
        $this->assertStringContainsString('watches their hands', (string) (RelDynFelt::lastRendered()['trust'] ?? ''),
            'band keywords reach the context with the shipped defaults');
        $this->assertStringContainsString(RelDynFelt::lastRendered()['trust'], implode("\n", $blocks));
    }

    public function testNoBandOfAnyDimensionPrintsANumber(): void
    {
        $checked = 0;
        foreach (RelationshipDynamics::DIMENSION_BANDS as $dim => $bands) {
            if ($dim === 'affinity' || $dim === 'passion') {
                continue; // affinity sets the tier below; passion is rendered in <emotional_dynamics>
            }
            foreach ($bands as $band) {
                $x = ($band['range'][0] + $band['range'][1]) / 2;   // band midpoint, in that dimension's own scale
                // Mirror 60 / 72.5 / 92.5 = core aff 20 / 45 / 85 (-100..100) = acquaintance / friend /
                // bonded = context tiers 1, 2 and 3.
                foreach ([60.0, 72.5, 92.5] as $affinityMirror) {
                    $expectedTier = ['60' => 1, '72.5' => 2, '92.5' => 3][(string)$affinityMirror];
                    $this->assertSame($expectedTier,
                        RelationshipDynamics::getContextTier($this->baseState($affinityMirror, [$dim => $x])),
                        'the sweep really renders at context tiers 1, 2 and 3');
                    $this->assertNoNumbers($this->contextFor($this->baseState($affinityMirror, [$dim => $x])),
                        "{$dim}={$x} at affinity mirror {$affinityMirror}");
                    $checked++;
                }
            }
        }
        foreach (['affinity' => [10.0, 30.0, 50.0, 80.0, 99.0]] as $dim => $xs) {
            foreach ($xs as $x) {
                $this->assertNoNumbers($this->contextFor($this->baseState($x)), "affinity mirror {$x}");
            }
        }
        $this->assertGreaterThan(100, $checked);
    }

    public function testCombosMaturityMemoryAndShiftsPrintNoNumbers(): void
    {
        $state = $this->baseState(90.0,
            ['coord_m' => -80.0, 'coord_f' => 90.0, 'arousal' => 95.0, 'valence' => -85.0, 'maturity' => 12.0,
             'resentment' => 80.0],
            [
                'passion' => 85.0,
                'jealousy_anger' => 60.0,
                'in_conflict' => true,
                'dimensional_memory' => [
                    ['dim' => 'trust', 'delta' => -12.5, 'abs_delta' => 12.5, 'reason' => 'caught lying about the Dawnguard',
                     'bond' => 'Kaida', 'ts' => date('Y-m-d H:i:s', time() - 3 * 3600)],
                    ['dim' => 'comfort', 'delta' => 8.0, 'abs_delta' => 8.0, 'reason' => 'shared a quiet night by the fire',
                     'bond' => 'Kaida', 'ts' => date('Y-m-d H:i:s', time() - 5 * 86400)],
                ],
            ]);
        $state['dimensions']['passion']['x'] = 85.0;
        $state['dimensions']['trust']['last_reason'] = 'kept the promise at Castle Volkihar';
        $state['dimensions']['trust']['last_delta'] = 4.0;

        $blocks = $this->contextFor($state);
        $this->assertCount(1, $blocks, 'one <subtext> block');
        $this->assertArrayHasKey('maturity', RelDynFelt::lastRendered(), 'tier 3: how they handle it always speaks');
        $this->assertNoNumbers($blocks, 'combined extreme state');

        // The memories and the last eval reason, when the moment is not crowded by extremes
        $calm = $this->baseState(90.0, [], ['dimensional_memory' => $state['dimensional_memory']]);
        $calm['dimensions']['trust']['last_reason'] = 'kept the promise at Castle Volkihar';
        $calm['dimensions']['trust']['last_delta'] = 4.0;
        $blocks = $this->contextFor($calm);
        $joined = implode("\n", $blocks);
        $this->assertStringContainsString("'caught lying about the Dawnguard' (still stings)", $joined);
        $this->assertStringContainsString("'shared a quiet night by the fire' (still warm)", $joined);
        $this->assertStringContainsString("'kept the promise at Castle Volkihar' (still warm)", $joined);
        $this->assertDoesNotMatchRegularExpression('/\b(Trust|Comfort) (rose|dropped)|\bago\b/', $joined, 'no dimension, no timestamp');
        $this->assertNoNumbers($blocks, 'memories');
    }

    /**
     * dimensional-memory: the stored stings are the confrontation fuel (the draft's term),
     * distinct events, most significant first, cleaned text only. With the resentment arc on
     * (shipped), its confrontation is the one voice that raises them (prerequest decides it; the
     * context alone never adds a standing grievances line). With the arc's confrontation off, at
     * the MDD 15.5 threshold (resentment 50) they are a standing list of grievances the NPC is
     * ready to bring up, and the memory line keeps what is not already there. Below the
     * threshold only the memory line.
     */
    public function testConfrontationFuelSpeaksAtResentmentFifty(): void
    {
        $mem = [
            ['dim' => 'trust', 'delta' => -12.0, 'abs_delta' => 12.0, 'reason' => 'Kaida lied about the artifact', 'bond' => 'Kaida', 'gamets' => 5000],
            ['dim' => 'affinity', 'delta' => -9.0, 'abs_delta' => 9.0, 'reason' => 'Kaida lied about the artifact', 'bond' => 'Kaida', 'gamets' => 5000],
            ['dim' => 'respect', 'delta' => -6.0, 'abs_delta' => 6.0, 'reason' => 'Kaida walked away mid-conversation (respect -6)', 'bond' => 'Kaida', 'gamets' => 7000],
            ['dim' => 'comfort', 'delta' => 5.0, 'abs_delta' => 5.0, 'reason' => 'shared a quiet night by the fire', 'bond' => 'Kaida', 'gamets' => 9000],
        ];
        $this->assertSame(['Kaida lied about the artifact', 'Kaida walked away mid-conversation'],
            RelationshipDynamics::getConfrontationFuel(['dimensional_memory' => $mem], 'Kaida'), 'distinct events, strongest first, cleaned');

        $this->contextFor($this->baseState(90.0, ['resentment' => 55.0], ['dimensional_memory' => $mem]));
        $this->assertArrayNotHasKey('grievances', RelDynFelt::lastRendered(), 'the arc on: its confrontation is the one voice');

        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['resentment_arc']['confrontation']['enabled'] = false;
        $this->db->config = json_encode($cfg);
        RelationshipDynamics::clearConfigCache();
        $calm = $this->baseState(90.0, ['resentment' => 20.0], ['dimensional_memory' => $mem]);
        $this->contextFor($calm);
        $this->assertArrayNotHasKey('grievances', RelDynFelt::lastRendered(), 'below 50: no confrontation');

        $blocks = $this->contextFor($this->baseState(90.0, ['resentment' => 55.0], ['dimensional_memory' => $mem]));
        $felt = RelDynFelt::lastRendered();
        $this->assertArrayHasKey('grievances', $felt, json_encode(array_keys($felt)));
        $this->assertStringContainsString("'Kaida lied about the artifact'; 'Kaida walked away mid-conversation'", $felt['grievances']);
        $this->assertStringNotContainsString('lied about the artifact', (string) ($felt['memory'] ?? ''), 'said once');
        $this->assertNoNumbers($blocks, 'grievances');
    }

    /**
     * MDD 11 (social-masking): the Mask is worn by an NPC to whom status matters (pride) with a
     * Toxic / Avoidant attachment, in front of someone she does not trust. The context decides
     * it, where core's CACHE_PEOPLE is set (never in prerequest, which runs before core sets
     * it), and the next turn alone with the player drops it.
     */
    public function testSocialMaskingShipsOffAndItsTextIsFeltWhenOn(): void
    {
        $this->assertFalse(RelationshipDynamics::defaultConfig()['social_masking_enabled'], 'a gameplay call, unchanged');
        // Proud (pride 0.9) and avoidant: she wears the Mask; Isran has no bond with her
        $state = $this->baseState(80.0, ['resentment' => 70.0, 'comfort' => 20.0, 'maturity' => 70.0],
            ['inferred_temperament' => 'Proud', 'profile_overrides' => ['attachment_style' => 'avoidant']]);
        $GLOBALS['CACHE_PEOPLE'] = '|Serana|Kaida|Isran|';
        $this->assertStringNotContainsString('performs ease', implode("\n", $this->contextFor($state)), 'shipped off');

        $this->db->config = json_encode(array_merge(RelationshipDynamics::defaultConfig(), ['social_masking_enabled' => true]));
        RelationshipDynamics::clearConfigCache();
        $blocks = $this->contextFor($state);
        $this->assertStringContainsString('In front of Isran, Serana performs ease', implode("\n", $blocks));
        $this->assertNoNumbers($blocks, 'social masking');
        $stored = $this->db->plugin['serana']['reldyn']['dynamics'];
        $this->assertTrue($stored['_was_masking'], 'the turn\'s mask is recorded for the next turn and the eval');

        // Next turn, alone with the player: the mask drops, once
        $GLOBALS['CACHE_PEOPLE'] = '|Serana|Kaida|';
        $blocks = $this->contextFor($stored);
        $this->assertStringContainsString('Serana lets the mask fall now that they are alone with Kaida', implode("\n", $blocks));
        $this->assertNoNumbers($blocks, 'mask drop');
        $blocks = $this->contextFor($this->db->plugin['serana']['reldyn']['dynamics']);
        $this->assertStringNotContainsString('lets the mask fall', implode("\n", $blocks), 'a drop is a one-shot');

        // Same audience, but no Mask to wear: a secure Proud NPC, an avoidant Gentle one (pride 0.2)
        $GLOBALS['CACHE_PEOPLE'] = '|Serana|Kaida|Isran|';
        foreach ([['Proud', 'secure'], ['Gentle', 'avoidant']] as [$temperament, $style]) {
            $other = $this->baseState(80.0, ['resentment' => 70.0, 'comfort' => 20.0, 'maturity' => 70.0],
                ['inferred_temperament' => $temperament, 'profile_overrides' => ['attachment_style' => $style]]);
            $this->assertStringNotContainsString('performs ease', implode("\n", $this->contextFor($other)), "{$temperament} {$style}");
        }
        $this->db->config = null;
    }
}
