<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynMoodColoringConfigDb
{
    public function __construct(public array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Mood colouring (roadmap mood-coloring-181; MDD section 7): 181 named emotional states read from
 * the M/F coordinates and arousal / valence, whose words replace the plain arousal / valence band
 * in the felt steering. 6 arousal bands x 6 valence bands x 5 M/F zones = 180, plus the one at
 * rest. Feelings only: behaviour and manner of speech, no digits, no gendered pronoun; the felt
 * budget is untouched (one line, the same place, the same salience). No database: config is the
 * defaults, with derived warmth off so warmth is a stored dimension.
 */
final class RelDynMoodColoringTest extends TestCase
{
    private const T0 = 400 * RelationshipDynamics::GAMETS_PER_DAY;

    private array $saved = [];
    private RelDynMoodColoringConfigDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->setConfig([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** The shipped config with $patch laid over it (top-level section => replacement), derived warmth off. */
    private function setConfig(array $patch): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['passion_dynamics'] = array_merge(RelDynPassion::configDefaults(), ['derived_warmth_enabled' => false]);
        $this->db = new RelDynMoodColoringConfigDb(array_replace($cfg, $patch));
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    /** A bond state with arousal / valence / M / F set (every other dimension at its resting value). */
    private function state(float $arousal, float $valence, float $m = 0.0, float $f = 0.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['inferred_temperament' => 'Guarded']));
        $d['dimensions']['arousal']['x'] = $arousal;
        $d['dimensions']['valence']['x'] = $valence;
        $d['dimensions']['coord_m'] = ['x' => $m, 'baseline' => 0.0] + ($d['dimensions']['coord_m'] ?? []);
        $d['dimensions']['coord_f'] = ['x' => $f, 'baseline' => 0.0] + ($d['dimensions']['coord_f'] ?? []);
        $d['dimensions']['coord_m']['x'] = $m;
        $d['dimensions']['coord_f']['x'] = $f;
        return $d;
    }

    private function line(array $d, string $key = 'arousal_valence'): ?array
    {
        $c = RelDynFelt::compose('Muiri', 'Kaida', $d, self::T0, ['player_addressed' => true]);
        foreach ($c['lines'] as $l) if ($l['key'] === $key) return $l;
        return null;
    }

    // ------------------------------------------------------------------ the 181

    public function testThereAreExactlyOneHundredEightyOneNamedStates(): void
    {
        $this->assertSame(181, RelDynMoods::count());
        $names = RelDynMoods::names();
        $this->assertCount(181, $names);
        $this->assertSame(RelDynMoods::REST, $names[0], 'the first is the one at rest');
        $this->assertCount(181, array_unique(array_map('mb_strtolower', $names)), 'every state has its own name');
        foreach ($names as $n) $this->assertNotSame('', trim($n));
    }

    public function testEveryStateIsReachableOnTheGridAndOnlyThoseStates(): void
    {
        $zones = ['level' => [0.0, 0.0], 'protective' => [60.0, 60.0], 'stoic' => [60.0, -60.0], 'soft' => [-60.0, 60.0], 'bitter' => [-60.0, -60.0]];
        $cfg = RelDynMoods::config();
        $seen = [];
        $wrongZone = [];
        foreach ($zones as $zone => [$m, $f]) {
            for ($a = 0; $a <= 100; $a += 1) {
                for ($v = -100; $v <= 100; $v += 1) {
                    $s = RelDynMoods::state((float) $a, (float) $v, $m, $f, $cfg);
                    if ($s['zone'] !== $zone) $wrongZone[] = "{$a}/{$v}";
                    $seen[$s['id']] = $s['name'];
                }
            }
        }
        $this->assertSame([], $wrongZone);
        $this->assertCount(181, $seen, 'a state the grid never reaches is a state that never speaks');
        $ids = array_keys($seen);
        sort($ids);
        $this->assertSame(range(0, 180), $ids);
        foreach ($seen as $id => $name) $this->assertSame(RelDynMoods::names()[$id], $name, "id {$id} is its name's position");
    }

    public function testTheSettledZoneIsTheOneAtRestAndSaysNothing(): void
    {
        foreach ([[10.0, 0.0], [25.0, 15.0], [0.0, -15.0], [20.0, 5.0]] as [$a, $v]) {
            $s = RelDynMoods::state($a, $v, 40.0, 40.0);
            $this->assertTrue($s['rest'], "{$a}/{$v}");
            $this->assertSame(0, $s['id']);
            $this->assertSame('', $s['text']);
            $this->assertNull(RelDynMoods::feltKeywords($this->state($a, $v), $a, $v, 40.0, 40.0));
        }
        $this->assertFalse(RelDynMoods::state(26.0, 0.0)['rest'], 'above the settled arousal');
        $this->assertFalse(RelDynMoods::state(10.0, 16.0)['rest'], 'valence past the settled band');
    }

    public function testTheZoneIsTheQuadrantTheCoordinatesSitInAndLevelNearTheCentre(): void
    {
        $this->assertSame('level', RelDynMoods::zone(null, null), 'no stored coordinates');
        $this->assertSame('level', RelDynMoods::zone(10.0, -20.0));
        $this->assertSame('protective', RelDynMoods::zone(30.0, 5.0));
        $this->assertSame('soft', RelDynMoods::zone(-5.0, 90.0), 'a coordinate past the even zone, m just below zero');
        $this->assertSame('soft', RelDynMoods::zone(-30.0, 30.0));
        $this->assertSame('stoic', RelDynMoods::zone(80.0, -26.0));
        $this->assertSame('bitter', RelDynMoods::zone(-26.0, -90.0));
        // the quadrant names are the MDD 3.1 ones (RelationshipDynamics::MF_QUADRANT_BANDS)
        foreach (['+M/+F' => 'protective', '+M/-F' => 'stoic', '-M/+F' => 'soft', '-M/-F' => 'bitter'] as $quadrant => $zone) {
            [$m, $f] = [$quadrant[0] === '+' ? 70.0 : -70.0, substr($quadrant, 3, 1) === '+' ? 70.0 : -70.0];
            $this->assertSame($quadrant, RelationshipDynamics::getMFQuadrantBand($m, $f)['quadrant']);
            $this->assertSame($zone, RelDynMoods::zone($m, $f));
        }
    }

    public function testTheSameJoltReadsDifferentlyByWhoIsFeelingIt(): void
    {
        // a dragon kill: arousal 92, valence 55
        $texts = [];
        foreach ([[0.0, 0.0], [70.0, 70.0], [70.0, -70.0], [-70.0, 70.0], [-70.0, -70.0]] as [$m, $f]) {
            $texts[RelDynMoods::zone($m, $f)] = RelDynMoods::state(92.0, 55.0, $m, $f)['text'];
        }
        $this->assertCount(5, array_unique($texts), json_encode($texts));
        $this->assertSame(['level', 'protective', 'stoic', 'soft', 'bitter'], array_keys($texts));
        // and the same zone reads differently by arousal and by valence
        $this->assertNotSame(RelDynMoods::state(92.0, 55.0, 70.0, 70.0)['text'], RelDynMoods::state(50.0, 55.0, 70.0, 70.0)['text']);
        $this->assertNotSame(RelDynMoods::state(92.0, 55.0, 70.0, 70.0)['text'], RelDynMoods::state(92.0, -55.0, 70.0, 70.0)['text']);
    }

    public function testWhatTheLlmReadsIsFeltNotNumbersNorLabelsNorGenderedPronouns(): void
    {
        foreach (RelDynMoods::LEXICON as $zone => $rows) {
            $this->assertCount(6, $rows, $zone);
            foreach ($rows as $a => $row) {
                $this->assertCount(6, $row, "{$zone} arousal {$a}");
                foreach ($row as $v => [$name, $text]) {
                    $why = "{$zone}/{$a}/{$v} {$name}";
                    $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$why}: a number reached the LLM");
                    $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|herself|he|him|his|himself)\b/i', $text, "{$why}: a gendered pronoun");
                    $this->assertDoesNotMatchRegularExpression('/\b(feels?|felt|is feeling|emotion|mood|arousal|valence|state)\b/i', $text, "{$why}: a stated feeling, not behaviour");
                    $this->assertGreaterThanOrEqual(2, count(preg_split('/[,;]/', $text)), "{$why}: a few behaviours");
                    $this->assertLessThanOrEqual(100, strlen($text), "{$why}: the felt budget");
                }
            }
        }
    }

    // ------------------------------------------------------------------ the felt line

    public function testTheFeltArousalValenceLineSpeaksTheStatesWordsInTheSamePlace(): void
    {
        $d = $this->state(92.0, 55.0, 70.0, 70.0);
        $line = $this->line($d);
        $this->assertNotNull($line, 'a flooded, glad NPC has an arousal / valence line');
        $state = RelDynMoods::stateOf($d);
        $this->assertSame('protective', $state['zone']);
        $this->assertSame($state['text'], $line['text']);
        $this->assertTrue($line['intense'], 'intensity formatting still applies');
        $this->assertSame(RelDynFelt::LANE_CORE, $line['lane']);
        $plain = RelationshipDynamics::getArousalValenceBand(92.0, 55.0)['keywords'];
        $this->assertNotSame($plain, $line['text']);
    }

    public function testColouringChangesOnlyTheWordsNotTheBudget(): void
    {
        $d = $this->state(92.0, -55.0, -70.0, -70.0);
        $on = $this->line($d);
        $this->setConfig(['mood_axes' => ['coloring' => ['enabled' => false]] + RelDynMoodAxes::configDefaults()]);
        $this->assertFalse(RelDynMoods::enabled());
        $off = $this->line($d);
        $this->assertNotNull($on);
        $this->assertNotNull($off);
        $this->assertSame(RelationshipDynamics::getArousalValenceBand(92.0, -55.0)['keywords'], $off['text'], 'off: the plain band words');
        $this->assertSame($off['salience'], $on['salience'], 'the same salience');
        $this->assertSame($off['key'], $on['key']);
        $this->assertSame($off['lane'], $on['lane']);
        $this->assertLessThanOrEqual(RelDynFelt::estimateTokens($off['text']) + 8, RelDynFelt::estimateTokens($on['text']), 'about the same size as the line it replaces');
    }

    public function testAnNpcAtRestKeepsTheLineSilentAsBefore(): void
    {
        $this->assertNull($this->line($this->state(12.0, 3.0, 70.0, 70.0)));
    }

    public function testTheMfCoordinatesShapeTheWordsOnTheLine(): void
    {
        $by = [];
        foreach ([[0.0, 0.0], [70.0, 70.0], [70.0, -70.0], [-70.0, 70.0], [-70.0, -70.0]] as [$m, $f]) {
            $by[] = $this->line($this->state(70.0, -50.0, $m, $f))['text'] ?? null;
        }
        $this->assertNotContains(null, $by);
        $this->assertCount(5, array_unique($by), 'the same fear is carried five ways');
    }

    public function testTheShippedConfigCarriesTheColouringSettings(): void
    {
        $d = RelDynMoodAxes::configDefaults();
        $this->assertSame(RelDynMoods::configDefaults(), $d['coloring']);
        $this->assertTrue($d['coloring']['enabled']);
        $this->assertSame($d, RelationshipDynamics::defaultConfig()['mood_axes']);
    }
}
