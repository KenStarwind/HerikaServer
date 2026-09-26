<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** conf_opts only: the stored RelDyn config row for the mirror's config-dependent reads. */
final class RelDynMirrorConfigDb
{
    public function __construct(private array $config) {}
    public function fetchOne($q, array $params = [])
    {
        return str_contains((string) $q, 'conf_opts') ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($q, $log = false) { return []; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
}

/**
 * player-profile-mirror (D:\docs\reldyn-player-profile-design.md), the pure profile: compute()
 * over stored observations (what every NPC's eval scored the player doing), the design's six
 * scored dimensions with their bands, validation locus, charisma archetype (50 window), attachment
 * pattern (100 window, every 25, 60%), love language (200 window), the reputation trust points
 * for new NPCs (+-5..+-15), Character mode, the trajectory, and the opt-in felt line (words only).
 */
final class RelDynPlayerMirrorTest extends TestCase
{
    private const H = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const NPCS = ['Aela the Huntress', 'Ashe', 'Muiri', 'Lynly Star-Sung'];
    private array $saved = [];
    private int $q = 0;

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /**
     * One observation as observe() stores it. $s: signal => raw points; $c: her state then.
     */
    private function obs(string $npc, float $hour, array $s = [], array $tags = [], array $o = []): array
    {
        $sig = [];
        foreach (RelDynMirror::SIGNALS as $k) $sig[] = floatval($s[$k] ?? 0);
        return ['fp' => 'fp' . (++$this->q), 'q' => $this->q, 'g' => (int) round(100 * RelationshipDynamics::GAMETS_PER_DAY + $hour * self::H),
            'n' => $npc, 's' => $sig, 't' => $tags, 'sig' => $o['sig'] ?? 0.4, 'pi' => $o['pi'] ?? 1, 'gv' => $o['gv'] ?? 0,
            'ri' => $o['ri'] ?? 0, 'c' => ($o['c'] ?? []) + ['cf' => false, 'cm' => false, 'pa' => false, 'b' => 50]]
            + (isset($o['ch']) ? ['ch' => $o['ch']] : []);
    }

    private static function state(array $obs, array $extra = []): array
    {
        return array_replace(['v' => 1, 'total' => count($obs), 'obs' => $obs, 'history' => []], $extra);
    }

    /** $n kind, reliable exchanges spread over the four beds, one every few game hours. */
    private function kind(int $n, float $from = 0.0): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->obs(self::NPCS[$i % 4], $from + 6.0 * $i, ['affinity' => 3, 'trust' => 3, 'comfort' => 2, 'respect' => 2, 'maturity' => 1],
                ['help'], ['ch' => 'rock']);
        }
        return $out;
    }

    public function testAKindReliablePlayerReadsAsDependableAndConfidenceGrowsWithEvidence(): void
    {
        $few = RelDynMirror::compute(self::state($this->kind(3)));
        $many = RelDynMirror::compute(self::state($this->kind(60)));
        $this->assertTrue($many['known']);
        $this->assertSame(4, $many['npcs']);
        foreach (['trust', 'warmth', 'comfort', 'respect', 'maturity'] as $d) {
            $this->assertGreaterThan(50.0, $few['dimensions'][$d]['score'], $d);
            $this->assertGreaterThan($few['dimensions'][$d]['score'], $many['dimensions'][$d]['score'], "{$d}: more evidence, a firmer read");
        }
        // trust +3 of 6 per exchange, 60 exchanges: 50 + 25 x 60/80 = 68.75, the design's 61-80 band
        $this->assertEqualsWithDelta(68.75, $many['dimensions']['trust']['score'], 0.01);
        $this->assertSame(4, $many['dimensions']['trust']['band']);
        $this->assertSame('dependable, follows through, good to their word', $many['dimensions']['trust']['keywords']);
        $this->assertSame('Trust Rating', $many['dimensions']['trust']['label']);
        // a calm, decisive approach (the eval's rock grade) reads as confidence
        $this->assertGreaterThan(60.0, $many['dimensions']['confidence']['score']);
        $this->assertSame('rock', $many['charisma']['primary']);
    }

    public function testPushingWhereSheIsPullingAwayCostsTheComfortEffect(): void
    {
        $obs = [];
        for ($i = 0; $i < 40; $i++) {
            $obs[] = $this->obs(self::NPCS[$i % 4], 8.0 * $i, ['affinity' => 1], [], ['ri' => 2, 'c' => ['pa' => $i % 2 === 0, 'cm' => $i % 2 === 1]]);
        }
        $p = RelDynMirror::compute(self::state($obs));
        $this->assertLessThan(30.0, $p['dimensions']['comfort']['score'], 'pushes when told no');
        $this->assertSame(["suffocating, pushes constantly, doesn't hear no", 'tries too hard, well-meaning but overwhelming'][$p['dimensions']['comfort']['band'] - 1],
            $p['dimensions']['comfort']['keywords']);
    }

    public function testGrievancesCostAndRepairInHerConflictEarnsMaturity(): void
    {
        $careless = $repairing = [];
        for ($i = 0; $i < 30; $i++) {
            $careless[] = $this->obs(self::NPCS[$i % 4], 8.0 * $i, ['affinity' => -2], ['insult'], ['gv' => 2, 'pi' => 0, 'c' => ['cf' => true]]);
            $repairing[] = $this->obs(self::NPCS[$i % 4], 8.0 * $i, ['affinity' => 1], ['apology'], ['c' => ['cf' => true]]);
        }
        $a = RelDynMirror::compute(self::state($careless))['dimensions']['maturity'];
        $b = RelDynMirror::compute(self::state($repairing))['dimensions']['maturity'];
        $this->assertLessThan(40.0, $a['score']);
        $this->assertGreaterThan(60.0, $b['score']);
    }

    public function testCharismaCountsTheLastFiftyGrades(): void
    {
        $obs = [];
        for ($i = 0; $i < 60; $i++) $obs[] = $this->obs('Ashe', 3.0 * $i, [], [], ['ch' => 'catalyst']);   // older, out of the window
        for ($i = 0; $i < 50; $i++) $obs[] = $this->obs('Ashe', 200 + 3.0 * $i, [], [], ['ch' => $i % 5 === 0 ? 'charmer' : ($i % 5 === 1 ? 'none' : 'rock')]);
        $c = RelDynMirror::compute(self::state($obs))['charisma'];
        $this->assertSame(['primary' => 'rock', 'secondary' => 'charmer'], ['primary' => $c['primary'], 'secondary' => $c['secondary']]);
        $this->assertSame(['rock' => 30, 'charmer' => 10, 'catalyst' => 0], $c['counts']);
        $few = RelDynMirror::compute(self::state(array_slice($obs, -4)))['charisma'];
        $this->assertNull($few['primary'], 'fewer graded exchanges than min_graded: no archetype yet');
    }

    /** Anxious: back at her within the hour after every bad exchange, and a gift into the fight. */
    private function anxious(int $pairs): array
    {
        $out = [];
        for ($i = 0; $i < $pairs; $i++) {
            $npc = self::NPCS[$i % 2];
            $t = 30.0 * $i;
            $out[] = $this->obs($npc, $t, ['affinity' => -6, 'trust' => -4, 'comfort' => -4], ['criticism'], ['gv' => 1, 'pi' => 0]);
            $out[] = $this->obs($npc, $t + 0.5, ['affinity' => 1], ['gift'], ['c' => ['cf' => true]]);
        }
        return $out;
    }

    public function testAttachmentUpdatesEveryTwentyFiveAndNamesAPatternAtSixtyPercent(): void
    {
        $this->assertNull(RelDynMirror::compute(self::state($this->anxious(12)))['attachment'], '24 observations: not yet');
        $this->q = 0;                // a fresh mirror: its sequence starts over
        $obs = $this->anxious(13);   // 26: the read stands at the 25th
        $a = RelDynMirror::compute(self::state($obs))['attachment'];
        $this->assertSame(25, $a['at_count']);
        $this->assertSame(25, $a['observations']);
        $this->assertSame('anxious', $a['primary']);
        $this->assertTrue($a['confident']);
        $this->assertSame('anxious', $a['label']);
        $this->assertGreaterThanOrEqual(0.6, $a['share']);
        // The 26th..49th observations do not move it until the 50th
        $more = $obs;
        for ($i = 0; $i < 23; $i++) $more[] = $this->obs('Muiri', 800 + 60.0 * $i, ['affinity' => 2], ['quality_time']);
        $this->assertSame($a['shares'], RelDynMirror::compute(self::state($more))['attachment']['shares'], 'held between updates');
    }

    public function testAMixedPatternIsOnlyLeaning(): void
    {
        $obs = $this->anxious(6);   // 12 anxious-leaning
        // steady, warm returns (secure) and long gaps from bonds (avoidant)
        for ($i = 0; $i < 7; $i++) $obs[] = $this->obs('Ashe', 400 + 12.0 * $i, ['affinity' => 2], ['quality_time']);
        for ($i = 0; $i < 6; $i++) $obs[] = $this->obs('Lynly Star-Sung', 600 + 24 * 9.0 * $i, ['affinity' => 1], [], ['c' => ['b' => 60]]);
        $a = RelDynMirror::compute(self::state($obs))['attachment'];
        $this->assertSame(25, $a['at_count']);
        $this->assertFalse($a['confident']);
        $this->assertSame($a['primary'] . '-leaning', $a['label']);
        $this->assertNotNull($a['secondary']);
    }

    public function testLoveLanguageIsWhatThePlayerDoes(): void
    {
        $obs = [];
        foreach ([['help'], ['rescue'], ['help', 'quality_time'], ['quality_time'], ['help'], ['praise'], ['quality_time'], ['rescue']] as $i => $tags) {
            $obs[] = $this->obs(self::NPCS[$i % 4], 5.0 * $i, ['affinity' => 1], $tags);
        }
        $ll = RelDynMirror::compute(self::state($obs))['love_language'];
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $ll['primary']);
        $this->assertSame(RelationshipDynamics::LL_TIME, $ll['secondary']);
        $this->assertSame(1, $ll['counts'][RelationshipDynamics::LL_WORDS], 'praise counted, below min_count');
    }

    public function testValidationLocusInternalExternalAndConcentrated(): void
    {
        $this->assertSame('internal', RelDynMirror::compute(self::state($this->kind(30)))['validation_locus']['locus']);
        // Always back at Ashe and Muiri right after a bad exchange with them: two validators
        $obs = $this->kind(10);
        for ($i = 0; $i < 10; $i++) {
            $npc = $i % 2 ? 'Ashe' : 'Muiri';
            $obs[] = $this->obs($npc, 300 + 20.0 * $i, ['affinity' => -5, 'trust' => -3], [], ['pi' => 0]);
            $obs[] = $this->obs($npc, 302 + 20.0 * $i, ['affinity' => 1]);
        }
        $v = RelDynMirror::compute(self::state($obs))['validation_locus'];
        $this->assertSame('concentrated_external', $v['locus']);
        $this->assertEqualsCanonicalizing(['Ashe', 'Muiri'], $v['validators']);
        $this->assertNull(RelDynMirror::compute(self::state($this->kind(5)))['validation_locus'], 'too few observations to say');
    }

    public function testReputationTrustPointsForNewNpcs(): void
    {
        $at = function (float $score, int $n = 40): float {
            return RelDynMirror::reputationTrustOffset(['mode' => 'mirror', 'dimensions' => ['trust' => ['score' => $score, 'n' => $n]]]);
        };
        $this->assertSame(0.0, $at(50.0));
        $this->assertSame(0.0, $at(60.0), 'the neutral band says nothing');
        $this->assertEqualsWithDelta(5.0, $at(60.01), 0.01, 'just outside it: +5');
        $this->assertEqualsWithDelta(10.0, $at(80.0), 0.01);
        $this->assertEqualsWithDelta(15.0, $at(100.0), 0.01, 'the design: +5 to +15');
        $this->assertEqualsWithDelta(-5.0, $at(40.99), 0.03);
        $this->assertEqualsWithDelta(-15.0, $at(0.0), 0.01);
        $this->assertSame(0.0, $at(90.0, 5), 'too little evidence: nothing travels yet');
    }

    public function testCharacterModeHoldsTheAuthoredRoleWhilePlayMovesItSlowly(): void
    {
        $GLOBALS['db'] = new RelDynMirrorConfigDb(['player_mirror' => ['mode' => 'character', 'character_weight' => 0.8,
            'character_seed' => ['trust' => 90, 'warmth' => 20, 'charisma' => 'catalyst', 'attachment' => 'avoidant']]]);
        RelationshipDynamics::clearConfigCache();
        $p = RelDynMirror::compute(self::state($this->kind(60)));
        $this->assertSame('character', $p['mode']);
        $this->assertEqualsWithDelta(0.8 * 90 + 0.2 * $p['dimensions']['trust']['observed'], $p['dimensions']['trust']['score'], 0.01);
        $this->assertEqualsWithDelta(0.8 * 20 + 0.2 * $p['dimensions']['warmth']['observed'], $p['dimensions']['warmth']['score'], 0.01);
        $this->assertLessThan(40.0, $p['dimensions']['warmth']['score'], 'the cold character stays cold through kind play');
        $this->assertSame('catalyst', $p['charisma']['primary']);
        $this->assertSame('avoidant', $p['attachment']['label']);
        $this->assertSame($p['dimensions']['respect']['observed'], $p['dimensions']['respect']['score'], 'no seed: observed');
    }

    public function testTheTrajectoryComparesWithTheSnapshotAMonthAgo(): void
    {
        $obs = $this->kind(60);
        $now = floatval(end($obs)['g']);
        $old = ['g' => (int) ($now - 30 * RelationshipDynamics::GAMETS_PER_DAY),
            'scores' => ['maturity' => 40.0, 'trust' => 68.0, 'warmth' => 90.0, 'respect' => 50.0, 'comfort' => 50.0, 'confidence' => 50.0]];
        $p = RelDynMirror::compute(self::state($obs, ['history' => [$old]]), null, $now);
        $this->assertSame('up', $p['dimensions']['maturity']['trend']);
        $this->assertSame('stable', $p['dimensions']['trust']['trend']);
        $this->assertSame('down', $p['dimensions']['warmth']['trend']);
        $this->assertSame(40.0, $p['dimensions']['maturity']['was']);
    }

    public function testTheFeltLineIsOptInWordsOnlyAndTheTrustRecordNeedsAnAcquaintance(): void
    {
        $p = RelDynMirror::compute(self::state($this->kind(60)));
        $this->assertNull(RelDynMirror::feltText('Ashe', 'Kaida', 3, $p), 'off by default (Rangroo: an option to keep it out)');
        $GLOBALS['db'] = new RelDynMirrorConfigDb(['player_mirror' => ['prompt' => ['enabled' => true]]]);
        RelationshipDynamics::clearConfigCache();
        $line = RelDynMirror::feltText('Ashe', 'Kaida', 3, $p);
        $this->assertIsString($line);
        $this->assertStringStartsWith('What Ashe senses of Kaida with people: ', $line);
        $this->assertDoesNotMatchRegularExpression('/\d/', $line);
        $this->assertSame(1, substr_count($line, ';'), 'the two most telling bands');
        // A stranger sees manner, not a record: the trust band needs context tier 1
        $stranger = (string) RelDynMirror::feltText('Ashe', 'this stranger', 0, $p);
        $this->assertStringNotContainsString('dependable', $stranger);
        $spider = RelDynMirror::spider($p);
        $this->assertSame(RelDynMirror::DIMENSIONS, array_column($spider['axes'], 'axis'));
        $this->assertSame(60, $spider['interactions']);
    }
}
