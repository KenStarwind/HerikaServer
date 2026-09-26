<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * `sql` stand-in: the stored RelDyn config row; eventlog reads answer from $eventRows (the re-gift
 * lookup); every other read finds nothing.
 */
final class RelDynBatchRFixDb
{
    /** @var array<int, array{data: string}> rows the re-gift lookup (eventlog) returns, newest first */
    public array $eventRows = [];
    public array $queries = [];

    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        if (str_contains((string) $sql, RelationshipDynamics::CONFIG_ROW_ID)) return ['value' => json_encode($this->config)];
        return [];
    }
    public function fetchAll($sql, $log = false)
    {
        $this->queries[] = (string) $sql;
        return str_contains((string) $sql, 'FROM eventlog') ? $this->eventRows : [];
    }
    public function execQuery($sql) { return true; }
}

/**
 * Batch-R review fixes, pure paths on real engine code (no core rows, no LLM):
 *   post-intimacy   a new encounter never brings the earlier one's sober verdict early: it waits
 *                   until due and sober; one drinking night is one verdict per dimension (the
 *                   scene's correction and the sober diary share comfort / trust, not only the
 *                   shame); the fearful reading of the "manipulated" row is opt-in
 *   addiction       an ordinary tavern habit (a drink or two an evening) never forms a dependence;
 *                   heavy nightly drinking still does
 *   player mirror   the attachment pattern reads visits, not the lines of one conversation
 *   gift delta      a fungible item (gold, ammunition, a consumable) or an item two people gave is
 *                   never a recognized re-gift
 */
final class RelDynBatchRFixTest extends TestCase
{
    private const NPC = 'Aela the Huntress';
    private const HOUR = RelationshipDynamics::GAMETS_PER_HOUR;
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const T0 = 410 * RelationshipDynamics::GAMETS_PER_DAY + 19 * self::HOUR;

    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;
    private RelDynBatchRFixDb $db;
    private int $q = 0;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $this->useConfig();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_batchr_fix_test.log');
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdbatchr');
        $this->prevLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    private function useConfig(array $over = []): void
    {
        $this->db = new RelDynBatchRFixDb(array_replace_recursive(RelationshipDynamics::defaultConfig(), $over));
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    private function clock(float $t): float
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) round($t), 'Kaida: hm'];
        return (float) (int) round($t);
    }

    private static function x(array $d, string $dim): float
    {
        return floatval($d['dimensions'][$dim]['x']);
    }

    /** Her own comfort (without what is held on it: the glow, the drink, withdrawal). */
    private static function ownComfort(array $d): float
    {
        return self::x($d, 'comfort') - RelationshipDynamics::heldTemporaryOffset($d, 'comfort');
    }

    /** A Bold huntress at maturity 65, a friend she is not drawn to soberly (her curve 0.25). */
    private function npc(float $maturity = 65.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Bold',
            '_attraction' => ['enabled' => true, 'attracted' => false, 'won_over' => false, 'passion' => ['curve' => 0.25]],
        ]));
        RelationshipDynamics::setCoreRelationshipType($d, 'friend');
        $d['dimensions']['maturity']['x'] = $maturity;
        $d['dimensions']['maturity']['baseline'] = $maturity;
        $d['dimensions']['affinity']['x'] = 60.0;
        $d['_aff_mirror_x'] = 60.0;
        RelationshipDynamics::setPassion($d, 30.0);
        return $d;
    }

    private function scene(array &$d, float $t): ?string
    {
        $t = $this->clock($t);
        $req = ['ext_nsfw_sexcene', '1727000000', (string) (int) $t, 'OStimScene/vaginal,romantic/Stage1_A1/Kaida^dom,vaginal/' . self::NPC . '^sub,vaginal'];
        return RelDynPostIntimacy::onIntimateRequest(self::NPC, $d, $req, 'Kaida', $t);
    }

    private function drinks(array &$d, float $t, int $n, string $key = 'ale'): void
    {
        for ($i = 0; $i < $n; $i++) RelDynSubstances::onConsume(self::NPC, $d, $key, $this->clock($t + $i));
    }

    /** The resentment_self verdicts on her one drinking night (asked points, in order). */
    private function shame(array $d): array
    {
        $shame = (array) ($d['_substances']['shame'] ?? []);
        $this->assertCount(1, $shame, 'one night');
        return array_map(fn($v) => round(floatval($v[1]), 4), (array) array_values($shame)[0]['verdicts']);
    }

    // ------------------------------------------------------------------ post-intimacy: two scenes, one night

    /**
     * Five ales, a scene, and a second scene an hour and a half later (a new encounter: past the
     * encounter gap). She is still drunk at the second: the first scene's sober verdict must not
     * land then (draft: "6 hours pass. Alcohol effects expire" before the diary eval). It waits,
     * lands in the sober morning, and the second scene of the same night adds no second crash and
     * no second shame: one night, one verdict.
     */
    public function testASecondSceneDoesNotBringTheSoberVerdictWhileSheIsDrunk(): void
    {
        $d = $this->npc();
        $this->drinks($d, self::T0, 5);
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, self::T0 + 0.2 * self::HOUR));
        $rs0 = self::x($d, 'resentment_self');
        $comfort0 = self::ownComfort($d);
        $trust0 = self::x($d, 'trust');

        $t2 = $this->clock(self::T0 + 1.7 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t2);
        $this->assertTrue(RelDynSubstances::intoxicated($d), 'still drunk at the second scene');
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, $t2), 'a new encounter, drunk again');
        $this->assertEqualsWithDelta($rs0, self::x($d, 'resentment_self'), 1e-9, 'no shame while she is drunk');
        $this->assertEqualsWithDelta($comfort0, self::ownComfort($d), 1e-6, 'no comfort crash while she is drunk');
        $this->assertEqualsWithDelta($trust0, self::x($d, 'trust'), 1e-9);
        $this->assertCount(1, $d[RelDynPostIntimacy::KEY]['deferred'] ?? [], 'the first verdict waits');
        $felt = RelDynPostIntimacy::feltText($d, $t2);
        $this->assertSame('glow', $felt['phase'] ?? null, 'the moment, not the morning');
        // Still drunk past the first verdict's hour? It waits on
        $tick = RelDynPostIntimacy::tick(self::NPC, $d, $this->clock(self::T0 + 5.9 * self::HOUR));
        $this->assertSame([], $tick['corrected']);
        $this->assertEqualsWithDelta($rs0, self::x($d, 'resentment_self'), 1e-9);

        // Sober, the first verdict is due: it lands whole
        $t = $this->clock(self::T0 + 6.5 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $this->assertFalse(RelDynSubstances::intoxicated($d));
        $first = RelDynPostIntimacy::tick(self::NPC, $d, $t)['corrected'];
        $this->assertLessThan(0.0, $first['comfort'] ?? 0.0, json_encode($first));
        $this->assertGreaterThan(0.0, $first['resentment_self'] ?? 0.0, json_encode($first));
        $this->assertSame('after', RelDynPostIntimacy::feltText($d, $t)['phase'] ?? null, 'the sober self shows');
        $comfort1 = self::ownComfort($d);
        $rs1 = self::x($d, 'resentment_self');

        // The second scene's verdict: the same night, nothing past the first
        $t = $this->clock(self::T0 + 8.0 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $second = RelDynPostIntimacy::tick(self::NPC, $d, $t)['corrected'];
        $this->assertArrayNotHasKey('comfort', $second, 'one crash for one night ' . json_encode($second));
        $this->assertArrayNotHasKey('resentment_self', $second, 'one shame for one night ' . json_encode($second));
        $this->assertEqualsWithDelta($comfort1, self::ownComfort($d), 1e-6);
        $this->assertEqualsWithDelta($rs1, self::x($d, 'resentment_self'), 1e-9);
        $P = RelDynPostIntimacy::resentmentSelf(12.0, RelDynDiary::ownMaturity($d));
        $this->assertSame([round($P, 4), round($P, 4)], $this->shame($d), 'both verdicts on the night, recorded');
        // Nothing pending, the after-text fades on its clock, then the state ends
        $this->assertSame([], $d[RelDynPostIntimacy::KEY]['deferred'] ?? []);
        $end = RelDynPostIntimacy::tick(self::NPC, $d, $this->clock(self::T0 + 40 * self::HOUR));
        $this->assertTrue($end['ended']);
    }

    /** A romance drunk twice in one night: trust -5 is one verdict, not two. */
    public function testARomanceDrunkTwiceInOneNightLosesTrustOnce(): void
    {
        $d = $this->npc();
        RelationshipDynamics::setCoreRelationshipType($d, 'romantic');
        $d['dimensions']['trust']['x'] = 25.0;   // as it reads toward him, below trust_deep: the draft's "Bonded, drunk, regret next day"
        $this->drinks($d, self::T0, 5);
        $this->scene($d, self::T0 + 0.2 * self::HOUR);
        $trust0 = self::x($d, 'trust');
        $this->clock(self::T0 + 1.7 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, self::T0 + 1.7 * self::HOUR);
        $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, self::T0 + 1.7 * self::HOUR));
        $this->assertEqualsWithDelta($trust0, self::x($d, 'trust'), 1e-9, 'no verdict while she is drunk');
        $t = $this->clock(self::T0 + 9.0 * self::HOUR);
        RelDynSubstances::update(self::NPC, $d, $t);
        $out = RelDynPostIntimacy::tick(self::NPC, $d, $t);
        $this->assertLessThan(0.0, $out['corrected']['trust'] ?? 0.0, 'the second (current) encounter corrects ' . json_encode($out));
        $this->assertSame([], $d[RelDynPostIntimacy::KEY]['deferred'] ?? [], 'both due and sober: both landed');
        $lost = $trust0 - self::x($d, 'trust');
        $once = $d;
        $once['dimensions']['trust']['x'] = $trust0;
        $want = -RelationshipDynamics::applyDelta('trust', $once, -5.0, 'Bold');
        $this->assertGreaterThan(0.5, $want);
        $this->assertEqualsWithDelta($want, $lost, 0.05, 'one verdict of trust for one night');
    }

    /**
     * The sober diary and the scene both judge a drunken night's comfort (draft: one diary verdict,
     * "comfort toward Mikael: -20"). Whichever comes second adds only what exceeds the first; what
     * only one of them judges (the flirting's affinity and passion) is still taken back.
     */
    public function testTheDiaryAndTheSceneShareTheNightsComfort(): void
    {
        foreach (['diary first', 'scene first'] as $order) {
            $d = $this->npc();
            $this->drinks($d, self::T0, 5);
            RelDynSubstances::update(self::NPC, $d, $this->clock(self::T0 + 0.1 * self::HOUR));
            RelDynSubstances::afterEvalItem(self::NPC, ['tags' => ['touch'], 'romantic_intent' => 2],
                ['affinity' => 2.5, 'comfort' => 10.0, 'passion' => 4.0], $d, $this->clock(self::T0 + 0.2 * self::HOUR));
            $start = self::T0 + self::HOUR;
            $this->assertSame(RelDynPostIntimacy::DRUNK, $this->scene($d, $start));
            RelDynPostIntimacy::tick(self::NPC, $d, $this->clock($start + 2.5 * self::HOUR));   // the afterglow ends
            $this->assertSame([], $d[RelDynPostIntimacy::KEY]['held']);
            $diaryAsk = -2.0 * 0.75 * 10.0;    // regret_mult x (1 - E) x the night's comfort gain
            $sceneAsk = -20.0;                 // the draft's "Stranger, drunk, one-night"
            $applied = [];
            $before = [];
            $steps = $order === 'diary first'
                ? [self::T0 + 6.2 * self::HOUR => 'diary', $start + 6.1 * self::HOUR => 'scene']
                : [$start + 6.1 * self::HOUR => 'scene', $start + 8.0 * self::HOUR => 'diary'];
            foreach ($steps as $t => $who) {
                $t = $this->clock($t);
                RelDynSubstances::update(self::NPC, $d, $t);
                $before[$who] = $d;
                if ($who === 'diary') {
                    $applied['diary'] = array_values(RelDynSubstances::soberReflection(self::NPC, $d, 'examination'))[0]['applied'];
                } else {
                    $applied['scene'] = RelDynPostIntimacy::tick(self::NPC, $d, $t)['corrected'];
                }
            }
            $why = $order . ' ' . json_encode($applied);
            // the night's comfort is judged once: the first verdict whole, the second only past it
            [$firstWho, $secondWho] = array_values($steps);
            $firstAsk = $firstWho === 'diary' ? $diaryAsk : $sceneAsk;
            $secondAsk = $secondWho === 'diary' ? $diaryAsk : $sceneAsk;
            $copy = $before[$firstWho];
            $this->assertEqualsWithDelta(RelationshipDynamics::applyDelta('comfort', $copy, $firstAsk, 'Bold'), $applied[$firstWho]['comfort'] ?? 0.0, 0.01,
                "the first verdict is whole: {$why}");
            $excess = min(0.0, $secondAsk - $firstAsk);
            $copy = $before[$secondWho];
            $want = $excess < 0.0 ? RelationshipDynamics::applyDelta('comfort', $copy, $excess, 'Bold') : 0.0;
            $this->assertEqualsWithDelta($want, $applied[$secondWho]['comfort'] ?? 0.0, 0.01, "the second only past the first: {$why}");
            if ($order === 'scene first') {
                $this->assertArrayNotHasKey('comfort', $applied['diary'], "the diary adds no second crash: {$why}");
            } else {
                $this->assertLessThan(0.0, $applied['scene']['comfort'] ?? 0.0, "the scene asks more than the page: the excess lands: {$why}");
                $this->assertGreaterThan($applied['diary']['comfort'], $applied['scene']['comfort'], "the excess only: {$why}");
            }
            $this->assertEqualsWithDelta([$firstAsk, $secondAsk], array_map(fn($v) => $v[1], array_values($d['_substances']['shame'])[0]['dims']['comfort']),
                0.0, "both asks on the night's record: {$why}");
            foreach (['affinity', 'passion'] as $sig) {
                $this->assertLessThan(0.0, $applied['diary'][$sig] ?? 0.0, "only the diary judges the flirting's {$sig}: {$why}");
            }
        }
    }

    /** Outside a drinking night nothing is shared (an active skooma consumable, no drink session). */
    public function testNoDrinkingNightNoSharedVerdict(): void
    {
        $d = $this->npc();
        $this->assertSame(-20.0, RelDynSubstances::nightVerdict($d, self::T0, 'comfort', -20.0, self::T0));
        $this->assertSame(-20.0, RelDynSubstances::nightVerdict($d, self::T0, 'comfort', -20.0, self::T0));
        $this->assertArrayNotHasKey('shame', (array) ($d['_substances'] ?? []));
        // A night: same sign shares, opposite signs never cancel
        $this->drinks($d, self::T0, 3);
        $key = RelDynSubstances::sessionKey($d);
        $this->assertNotNull($key);
        $this->assertSame(-15.0, RelDynSubstances::nightVerdict($d, self::T0, 'comfort', -15.0, self::T0, $key));
        $this->assertEqualsWithDelta(-5.0, RelDynSubstances::nightVerdict($d, self::T0, 'comfort', -20.0, self::T0, $key), 1e-9);
        $this->assertEqualsWithDelta(0.0, RelDynSubstances::nightVerdict($d, self::T0, 'comfort', -10.0, self::T0, $key), 1e-9);
        $this->assertEqualsWithDelta(3.0, RelDynSubstances::nightVerdict($d, self::T0, 'comfort', 3.0, self::T0, $key), 1e-9);
    }

    // ------------------------------------------------------------------ post-intimacy: the fearful reading

    /**
     * The draft keys "Low maturity, manipulated" on maturity and manipulation, not attachment.
     * Reading fearful attachment as that row is opt-in (config fearful_vulnerable): by default a
     * trusted partner sober is the draft's "Bonded, high trust, sober", whatever her axes.
     */
    public function testTheFearfulReadingIsOptIn(): void
    {
        $base = ['bonded' => true, 'romance' => true, 'trust' => 70.0, 'maturity' => 60.0, 'intoxicated' => false,
                 'avoidance' => 0.55, 'anxiety' => 0.6, 'other_partners' => [], 'core_type' => 'romantic'];
        $this->assertFalse(RelDynPostIntimacy::config()['fearful_vulnerable']);
        $this->assertSame(RelDynPostIntimacy::BONDED, RelDynPostIntimacy::outcome($base));
        $this->assertSame(RelDynPostIntimacy::COMMITTED, RelDynPostIntimacy::outcome(['bonded' => false, 'core_type' => 'crush', 'trust' => 45.0] + $base));
        // the draft's own rows still apply to her
        $this->assertSame(RelDynPostIntimacy::VULNERABLE, RelDynPostIntimacy::outcome(['bonded' => false, 'romance' => false, 'trust' => 20.0] + $base));
        $this->useConfig(['post_intimacy' => ['fearful_vulnerable' => true]]);
        $this->assertSame(RelDynPostIntimacy::VULNERABLE, RelDynPostIntimacy::outcome($base), 'opted in: the fearful reading');
    }

    // ------------------------------------------------------------------ addiction: habits

    /** $perEvening drinks every evening at 20:00 for $days game days; the last day's state. */
    private function habit(int $perEvening, int $days): array
    {
        $d = $this->npc(65.0);
        $first = null;
        for ($day = 0; $day < $days; $day++) {
            $t = 500 * self::DAY + $day * self::DAY + 20 * self::HOUR;
            for ($i = 0; $i < $perEvening; $i++) RelDynSubstances::onConsume(self::NPC, $d, 'ale', $t + $i * self::HOUR / 6);
            RelDynSubstances::update(self::NPC, $d, $t + 13 * self::HOUR);   // the next morning
            if ($first === null && RelDynSubstances::addicted($d)) $first = $day;
        }
        return ['d' => $d, 'first_addicted' => $first];
    }

    /**
     * "A drink now and then never does": one or two drinks an evening, every evening, is a tavern
     * habit, not a dependence. No withdrawal in the morning, and her maturity still grows.
     */
    public function testAnOrdinaryTavernHabitNeverFormsADependence(): void
    {
        foreach ([1, 2] as $n) {
            $h = $this->habit($n, 120);
            $d = $h['d'];
            $this->assertNull($h['first_addicted'], "{$n} a night for four months: never addicted");
            $this->assertLessThan(RelDynSubstances::config()['addiction']['craving_from'], RelDynSubstances::dependence($d, 'alcohol'), "{$n} a night");
            $this->assertFalse(!empty($d['_substances']['withdrawal']), "{$n} a night: no withdrawal");
            $this->assertNull(RelDynSubstances::maturityCeiling($d));
            $this->assertGreaterThan(0.0, RelationshipDynamics::applyDelta('maturity', $d, 3.0, 'Bold'), "{$n} a night: she still grows");
        }
    }

    /** Getting drunk every night is another thing: a dependence forms within a few weeks. */
    public function testHeavyNightlyDrinkingStillFormsADependence(): void
    {
        $h = $this->habit(5, 30);
        $this->assertNotNull($h['first_addicted'], 'five a night for a month');
        $this->assertLessThan(21, $h['first_addicted'], 'within three weeks');
        $this->assertTrue(RelDynSubstances::addicted($h['d']));
    }

    // ------------------------------------------------------------------ player mirror: visits

    /** One observation as observe() stores it; $minute: game minutes from day 100. */
    private function obs(string $npc, float $minute, array $s = [], array $tags = [], array $o = []): array
    {
        $sig = [];
        foreach (RelDynMirror::SIGNALS as $k) $sig[] = floatval($s[$k] ?? 0);
        return ['fp' => 'fp' . (++$this->q), 'q' => $this->q, 'g' => (int) round(100 * self::DAY + $minute * self::HOUR / 60),
            'n' => $npc, 's' => $sig, 't' => $tags, 'sig' => $o['sig'] ?? 0.4, 'pi' => $o['pi'] ?? 1, 'gv' => $o['gv'] ?? 0,
            'ri' => $o['ri'] ?? 0, 'c' => ($o['c'] ?? []) + ['cf' => false, 'cm' => false, 'pa' => false, 'b' => 50]];
    }

    /**
     * Daily visits to two bonded beds, five lines a visit about ten game minutes apart. $mix: per
     * visit, the kinds of its five lines ('small', 'warm', 'mild').
     */
    private function friendlyPlay(int $days, callable $mix): array
    {
        $bonds = ['Aela the Huntress' => 60, 'Lynly Star-Sung' => 55];
        $out = [];
        for ($day = 0; $day < $days; $day++) {
            $v = 0;
            foreach ($bonds as $npc => $b) {
                $start = $day * 1440 + 600 + 120 * $v++;
                foreach ($mix($day, $npc) as $line => $kind) {
                    $c = ['c' => ['b' => $b]];
                    $m = $start + 10 * $line;
                    $out[] = match ($kind) {
                        'small' => $this->obs($npc, $m, [], [], $c + ['sig' => 0.1, 'pi' => 0]),
                        'warm' => $this->obs($npc, $m, ['affinity' => 2, 'trust' => 1, 'comfort' => 2], ['quality_time'], $c + ['sig' => 0.4]),
                        'mild' => $this->obs($npc, $m, ['affinity' => -2, 'comfort' => -2], ['criticism'], $c + ['sig' => 0.3, 'pi' => 0]),
                    };
                }
            }
        }
        return $out;
    }

    private static function state(array $obs): array
    {
        return ['v' => 1, 'total' => count($obs), 'obs' => $obs, 'history' => []];
    }

    /**
     * The design reads attachment from how the player handles the relationship: frequency, gaps,
     * returns after conflict. Ordinary friendly play (daily visits, small talk mixed with warmth,
     * a mild word now and then) is steady contact: secure, never avoidant for the small talk of
     * a visit that also had warmth, never anxious for the next line of the same conversation.
     */
    public function testFriendlyDailyVisitsReadSecure(): void
    {
        $cases = [
            '60 small / 40 warm' => fn($day, $npc) => ['small', 'warm', 'small', 'warm', 'small'],
            '40 small / 60 warm' => fn($day, $npc) => ['warm', 'small', 'warm', 'small', 'warm'],
            '60 small / 30 warm / 10 mild' => fn($day, $npc) => $day % 2 ? ['small', 'warm', 'small', 'mild', 'small'] : ['small', 'warm', 'small', 'warm', 'small'],
        ];
        foreach ($cases as $label => $mix) {
            $this->q = 0;
            $a = RelDynMirror::compute(self::state($this->friendlyPlay(10, $mix)))['attachment'];
            $why = $label . ' ' . json_encode($a);
            $this->assertSame('secure', $a['primary'], $why);
            $this->assertLessThan(0.2, $a['shares']['avoidant'], $why);
            $this->assertLessThan(0.2, $a['shares']['anxious'], $why);
        }
    }

    /** What the design names still reads: coming back soon after a visit that went badly, and visits that are all small talk. */
    public function testReturnsAfterConflictAndSurfaceVisitsStillRead(): void
    {
        $obs = [];
        for ($i = 0; $i < 13; $i++) {
            $m = $i * 1440;
            $obs[] = $this->obs('Muiri', $m, ['affinity' => -5, 'trust' => -3, 'comfort' => -3], ['criticism'], ['pi' => 0, 'gv' => 1]);
            $obs[] = $this->obs('Muiri', $m + 90, ['affinity' => 1], ['reassurance'], ['sig' => 0.3]);   // back within the hour and a half
        }
        $a = RelDynMirror::compute(self::state($obs))['attachment'];
        $this->assertSame('anxious', $a['primary'], json_encode($a));
        $v = RelDynMirror::compute(self::state($obs))['validation_locus'];
        $this->assertNotSame('internal', $v['locus'], json_encode($v));

        $this->q = 0;
        $surface = $this->friendlyPlay(5, fn($day, $npc) => ['small', 'small', 'small', 'small', 'small']);
        $a = RelDynMirror::compute(self::state($surface))['attachment'];
        $this->assertSame('avoidant', $a['primary'], 'bonds kept to small talk, visit after visit ' . json_encode($a));
    }

    /** The next line of the same conversation after a mild word is no validation seeking. */
    public function testTheNextLineOfTheSameConversationIsNoValidationSeeking(): void
    {
        $obs = $this->friendlyPlay(10, fn($day, $npc) => ['small', 'mild', 'warm', 'small', 'warm']);
        $v = RelDynMirror::compute(self::state($obs))['validation_locus'];
        $this->assertSame('internal', $v['locus'], json_encode($v));
        $this->assertSame(0.0, $v['seeking_share']);
    }

    // ------------------------------------------------------------------ gift delta: what can be recognized

    /**
     * "This was Ysolda's, wasn't it?" needs a thing she could recognize. Gold, ammunition, a
     * consumable (mead, a potion, bread), or an item two different people gave the player are
     * fungible: which one the player hands over cannot be told, so it is no recognized re-gift.
     */
    public function testOnlyADistinctItemIsARecognizedRegift(): void
    {
        $ask = fn(string $item, array $rows) => ($this->db->eventRows = array_map(fn($r) => ['data' => $r], $rows))
            ? RelDynGifts::previousGiver('Lynly Star-Sung', $item, 'Kaida', 5000, 500 * self::DAY) : null;
        $this->assertSame('Aela the Huntress', $ask('Silver Necklace', ['Aela the Huntress gave 1 Silver Necklace to Kaida']), 'a distinct thing');
        $this->assertSame('Aela the Huntress', $ask('Silver Necklace', ['Aela the Huntress gave Silver Necklace to Kaida']));
        $this->assertSame('Aela the Huntress', $ask('Silver Necklace', ['Aela the Huntress gave 3 Silver Necklace to Kaida']), 'several, all hers');
        $this->assertNull($ask('gold', ['Aela the Huntress gave 100 gold to Kaida']), 'gold');
        $this->assertNull($ask('Gold', ['Farengar Secret-Fire gave 500 Gold to Kaida']), 'gold, any case');
        $this->assertSame('Aela the Huntress', $ask('Dragonscale Helmet', ['Aela the Huntress gave 1 Dragonscale Helmet to Kaida']), 'a whole word: no ale in it');
        $this->assertNull($ask('Nord Mead', ['Hulda gave 2 Nord Mead to Kaida']), 'a consumable');
        $this->assertNull($ask('Health Potion', ['Lydia gave 1 Health Potion to Kaida']), 'a potion');
        $this->assertNull($ask('Iron Arrow', ['Aela the Huntress gave 12 Iron Arrow to Kaida']), 'ammunition');
        $this->assertNull($ask('Silver Necklace', ['Lynly Star-Sung gave 1 Silver Necklace to Kaida', 'Aela the Huntress gave 1 Silver Necklace to Kaida']),
            'she gave him one herself: which one is this?');
        $this->assertNull($ask('Silver Necklace', ['Ysolda gave 1 Silver Necklace to Kaida', 'Aela the Huntress gave 1 Silver Necklace to Kaida']),
            'two people gave one: which one is this?');
        $this->assertSame('Aela the Huntress', $ask('Silver Necklace', ['Aela the Huntress gave 1 Silver Necklace to Kaida',
            'Aela the Huntress gave 1 Silver Necklace to Kaida']), 'the same giver twice is still hers');
        foreach ($this->db->queries as $sql) $this->assertStringContainsString("ESCAPE '\\'", $sql);
    }
}
