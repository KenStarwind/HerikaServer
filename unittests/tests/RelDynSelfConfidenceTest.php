<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * In-memory $db for the real context hook: plugin_extended_data per NPC, everything else
 * (config row, eventlog, core tables) "nothing stored".
 */
final class RelDynSelfConfidenceFakeDb
{
    /** @var array<string, array> lower(npc_name) => plugin_extended_data */
    public array $plugin = [];

    private function key(int $id): ?string { return array_keys($this->plugin)[$id - 1] ?? null; }

    public function fetchOne($sql, $params = [])
    {
        $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
        if (strpos($sql, 'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1)') === 0) {
            $i = array_search(strtolower((string) $params[0]), array_keys($this->plugin), true);
            return $i === false ? [] : ['id' => (string) ($i + 1)];
        }
        if (strpos($sql, 'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1') === 0) {
            $k = $this->key((int) $params[0]);
            if ($k === null) return [];
            $ns = $this->plugin[$k][$params[1]] ?? null;
            return ['plugin_data' => $ns === null ? null : json_encode((object) $ns)];
        }
        if (strpos($sql, 'UPDATE core_npc_master') === 0 && strpos($sql, 'jsonb_build_object($3::text, $4::jsonb)') !== false) {
            $k = $this->key((int) $params[0]);
            if ($k === null) return [];
            $this->plugin[$k][$params[1]][$params[2]] = json_decode($params[3], true);
            return ['id' => (string) $params[0]];
        }
        return [];
    }

    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * Self-confidence, the global root node (roadmap self-confidence; dimension design draft
 * Dimension 11):
 *   - its input is derived, never eval-scored: respect x 0.3 + maturity x 0.3
 *     - resentment_self x 0.3 + goal completion x 0.1 (resentment_self and the director goals'
 *     outcomes are live now, no longer placeholders);
 *   - cross-effect: above 75 with maturity below 30 the band's keywords give way to the
 *     arrogant override ("confidence without wisdom is insufferable"); the same confidence
 *     with maturity reads as the band (Sovereign);
 *   - it is global: no per-bond multiplier (RelDynPerBondDisplayTest).
 */
final class RelDynSelfConfidenceTest extends TestCase
{
    private const NPC = 'Nazeem';
    private array $savedGlobals = [];
    private RelDynSelfConfidenceFakeDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'HERIKA_NAME', 'contextDataFull', 'gameRequest', 'CACHE_PEOPLE',
                  'RELDYN_MASKING_ACTIVE'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
        }
        $this->db = new RelDynSelfConfidenceFakeDb();
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

    public function testTheInputReadsResentmentSelfAndTheGoalsOutcomes(): void
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['dimensions']['respect']['x'] = 50.0;
        $d['dimensions']['maturity']['x'] = 60.0;
        $d['dimensions']['resentment_self']['x'] = 0.0;
        // no goal outcome yet: completion reads neutral 0.5
        $this->assertEqualsWithDelta(15.0 + 18.0 + 5.0, RelationshipDynamics::deriveConfidenceInput($d), 1e-9);
        // shame weighs on it (x 0.3 of resentment_self points)
        $d['dimensions']['resentment_self']['x'] = 50.0;
        $this->assertEqualsWithDelta(15.0 + 18.0 - 15.0 + 5.0, RelationshipDynamics::deriveConfidenceInput($d), 1e-9);
        // following through: 3 of 4 recent goals fulfilled
        $d['_director_goal_history'] = [
            ['text' => 'a', 'outcome' => 'fulfilled'], ['text' => 'b', 'outcome' => 'fulfilled'],
            ['text' => 'c', 'outcome' => 'expired'], ['text' => 'd', 'outcome' => 'fulfilled'],
        ];
        $this->assertEqualsWithDelta(0.75, RelationshipDynamics::goalCompletionRate($d), 1e-9);
        $this->assertEqualsWithDelta(15.0 + 18.0 - 15.0 + 7.5, RelationshipDynamics::deriveConfidenceInput($d), 1e-9);
        $d['_director_goal_history'] = [['text' => 'x', 'outcome' => 'expired']];
        $this->assertSame(0.0, RelationshipDynamics::goalCompletionRate($d));
    }

    /** The context hook for Nazeem at self-confidence $conf (baseline 50) and maturity $maturity. */
    private function felt(float $conf, float $maturity): array
    {
        $d = RelationshipDynamics::defaultDynamics();
        $d['love_language_primary'] = RelationshipDynamics::LL_WORDS;
        $d['inferred_temperament'] = 'Proud';
        $d['dimensions']['affinity']['x'] = 72.5;   // core 45: friend, context tier 2
        $d['_aff_mirror_x'] = 72.5;
        $d['dimensions']['self_confidence']['x'] = $conf;
        $d['dimensions']['self_confidence']['baseline'] = 50.0;
        $d['dimensions']['maturity']['x'] = $maturity;
        $d['dimensions']['maturity']['baseline'] = $maturity;
        $this->db->plugin = [strtolower(self::NPC) => ['reldyn' => ['dynamics' => $d]]];
        $GLOBALS['contextDataFull'] = [];
        (static function () { require __DIR__ . '/../../ext/relationship_dynamics/context.php'; })();
        return RelDynFelt::lastRendered();
    }

    public function testConfidenceWithoutMaturityReadsAsArrogance(): void
    {
        $arrogant = (string) RelDynFelt::config()['text']['self_confidence_arrogant'];
        $this->assertNotSame('', $arrogant);
        $this->assertDoesNotMatchRegularExpression('/\d|confiden|maturity/i', $arrogant, 'behaviour, never a label or a number');
        // letters only, lower case: low maturity degrades the shown text (intensity formatting)
        $plain = fn(string $t) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', '', strtolower(str_replace(['...', '..', '_'], ' ', $t)))));
        $like = function (string $a, string $b) use ($plain): float { similar_text($plain($a), $plain($b), $pct); return $pct; };
        $sovereign = RelationshipDynamics::getDimensionBand('self_confidence', 85.0);
        $this->assertSame('Sovereign', $sovereign['label']);

        $f = $this->felt(85.0, 20.0);
        $this->assertArrayHasKey('self_confidence', $f);
        $this->assertGreaterThanOrEqual(85.0, $like($arrogant, $f['self_confidence']), "confidence without wisdom: the override\n{$f['self_confidence']}");
        $this->assertLessThan(60.0, $like($sovereign['keywords'], $f['self_confidence']));
        // the same confidence with maturity: the band as written (Sovereign)
        $g = $this->felt(85.0, 70.0);
        $this->assertGreaterThanOrEqual(95.0, $like($sovereign['keywords'], $g['self_confidence']), $g['self_confidence']);
        // at 75 or below it is the band, whatever the maturity (the override is above 75)
        $h = $this->felt(76.0, 20.0);
        $this->assertGreaterThanOrEqual(85.0, $like($arrogant, (string) ($h['self_confidence'] ?? '')), 'just above 75');
        $i = $this->felt(75.0, 20.0);
        $this->assertLessThan(60.0, $like($arrogant, (string) ($i['self_confidence'] ?? '')), 'at 75: the band');
    }
}
