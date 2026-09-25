<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynNeglectConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}

/**
 * Rulings 2026-09-24 §8: neglect is per NPC. The grace before an absence starts to hurt and
 * the resentment it builds per game day scale with who the NPC is: independent vs
 * codependent (attachment, temperament, traits), maturity ("life happens") and pride
 * (being ignored is a slight). Warmth fading with absence scales the same way.
 * No database unless a test stores a config: defaults, nothing saved.
 */
final class RelDynNeglectSeverityTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;   // raw gamets per game day
    private const T0 = 200 * self::DAY;                          // last contact: game day 200

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** A spouse (RelDyn bond 'bonded') last seen at T0. $maturity 0..100. */
    private function spouse(string $temperament, string $attachment, array $traits, float $maturity, array $dims = []): array
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) self::T0, 'Kaida: hello'];
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
            'profile_overrides' => ['attachment_style' => $attachment],
            'traits' => $traits,
            'relationship_type' => 'bonded',
            '_accumulated_play_gamets' => 10.0 * RelationshipDynamics::GAMETS_PER_REAL_HOUR,
        ]));
        foreach ($dims + ['maturity' => $maturity, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim]['x'] = $x;
        }
        RelationshipDynamics::markContact($d);
        return $d;
    }

    private function mature(): array   // mature, secure, independent
    {
        return $this->spouse('Independent', 'secure', [], 80.0);
    }

    private function furious(): array  // immature, anxious, possessive (codependent), egocentric
    {
        // traits phase 3 (A20): codependence is the attachment's anxiety plus possessiveness; the
        // Proud / insecure version of this NPC is no longer counted codependent three times
        return $this->spouse('Jealous', 'anxious', ['egocentric', 'insecure'], 20.0);
    }

    private function typical(): array  // secure, no pride, maturity 50
    {
        return $this->spouse('Romantic', 'secure', [], 50.0);
    }

    private static function resentment(array $d): float
    {
        return (float) $d['dimensions']['resentment']['x'];   // 0..100
    }

    // ------------------------------------------------------------ the profile

    public function testCodependenceComesFromAttachmentTemperamentAndTraits(): void
    {
        $c = fn(string $t, string $a, array $traits = []) =>
            RelationshipDynamics::getNeglectProfile($this->spouse($t, $a, $traits, 50.0))['codependence'];

        $this->assertEqualsWithDelta(0.0, $c('Independent', 'avoidant'), 1e-9, 'independent and avoidant: none');
        $this->assertEqualsWithDelta(0.5, $c('Romantic', 'secure'), 1e-9, 'the typical NPC sits in the middle');
        // traits phase 3 (A20): the temperament term is possessiveness (the Jealous part); anxiety
        // is the attachment term only, and the insecure tag no longer adds to it
        $this->assertEqualsWithDelta(1.0, $c('Jealous', 'anxious', ['insecure']), 1e-9, 'the top: jealous and anxious');
        $this->assertLessThan($c('Romantic', 'secure'), $c('Independent', 'secure'), 'Independent temperament lowers it');
        $this->assertGreaterThan($c('Romantic', 'secure'), $c('Jealous', 'secure'), 'Jealous raises it');
        $this->assertGreaterThan($c('Romantic', 'secure'), $c('Romantic', 'anxious'), 'Anxious attachment raises it');
        $this->assertEqualsWithDelta($c('Romantic', 'anxious'), $c('Romantic', 'toxic'), 1e-9, 'Toxic is as high as Anxious');
        $this->assertLessThan($c('Romantic', 'secure'), $c('Romantic', 'avoidant'), 'Avoidant lowers it');
        $this->assertEqualsWithDelta($c('Romantic', 'secure'), $c('Romantic', 'secure', ['insecure']), 1e-12, 'insecure is not counted again');
    }

    public function testPrideComesFromProudAndEgocentric(): void
    {
        $p = fn(string $t, array $traits) =>
            RelationshipDynamics::getNeglectProfile($this->spouse($t, 'secure', $traits, 50.0))['pride'];

        $this->assertEqualsWithDelta(1.0, $p('Proud', ['egocentric']), 1e-9, 'Proud is egocentric by default');
        $this->assertEqualsWithDelta(0.5, $p('Romantic', ['egocentric']), 1e-9, 'an egocentric noble who is not Proud');
        $this->assertEqualsWithDelta(0.0, $p('Romantic', []), 1e-9);
    }

    public function testTheTypicalNpcKeepsTheBondTableRates(): void
    {
        $prof = RelationshipDynamics::getNeglectProfile($this->typical());
        $this->assertEqualsWithDelta(1.0, $prof['grace_mult'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $prof['rate_mult'], 1e-9);
    }

    public function testMaturityPrideAndCodependenceMoveGraceAndRate(): void
    {
        $prof = fn(string $t, string $a, array $traits, float $m) =>
            RelationshipDynamics::getNeglectProfile($this->spouse($t, $a, $traits, $m));
        $base = $prof('Romantic', 'secure', [], 50.0);

        $mature = $prof('Romantic', 'secure', [], 90.0);
        $this->assertGreaterThan($base['grace_mult'], $mature['grace_mult'], 'mature: "life happens", longer grace');
        $this->assertLessThan($base['rate_mult'], $mature['rate_mult'], 'mature: slower build');

        $proud = $prof('Proud', 'secure', ['egocentric'], 50.0);
        $this->assertLessThan($base['grace_mult'], $proud['grace_mult'], 'proud: a slight, noticed sooner');
        $this->assertGreaterThan($base['rate_mult'], $proud['rate_mult'], 'proud: faster build');

        $independent = $prof('Independent', 'avoidant', [], 50.0);
        $this->assertGreaterThan($base['grace_mult'], $independent['grace_mult'], 'independent: barely minds');
        $this->assertLessThan($base['rate_mult'], $independent['rate_mult']);

        $codependent = $prof('Anxious', 'anxious', ['insecure'], 50.0);
        $this->assertLessThan($base['grace_mult'], $codependent['grace_mult'], 'codependent: takes it hard');
        $this->assertGreaterThan($base['rate_mult'], $codependent['rate_mult']);
    }

    public function testTheFormulaIsExactAndItsCoefficientsLiveInConfig(): void
    {
        $cfg = RelationshipDynamics::defaultConfig()['neglect_severity'];
        $d = $this->spouse('Proud', 'anxious', ['egocentric'], 30.0);
        $c = 0.6 * 1.0 + 0.4 * 0.5;                 // anxious attachment, Proud temperament (default T)
        $terms = ['codependence' => 2 * $c - 1, 'maturity' => (30.0 - 50.0) / 50.0, 'pride' => 1.0];
        $exp = fn(array $coef) => array_sum(array_map(fn($k) => $coef[$k] * $terms[$k], array_keys($terms)));

        $prof = RelationshipDynamics::getNeglectProfile($d);
        $this->assertEqualsWithDelta($c, $prof['codependence'], 1e-9);
        $this->assertEqualsWithDelta(2 ** $exp($cfg['grace_log2']), $prof['grace_mult'], 1e-9);
        $this->assertEqualsWithDelta(2 ** $exp($cfg['rate_log2']), $prof['rate_mult'], 1e-9);

        // A stored config with the coefficients zeroed is the flat bond-table rate again,
        // and a partial table keeps the other defaults.
        $GLOBALS['db'] = new RelDynNeglectConfigDb(array_merge(RelationshipDynamics::defaultConfig(), [
            'neglect_severity' => ['rate_log2' => ['codependence' => 0, 'maturity' => 0, 'pride' => 0]],
        ]));
        RelationshipDynamics::clearConfigCache();
        $flat = RelationshipDynamics::getNeglectProfile($d);
        $this->assertEqualsWithDelta(1.0, $flat['rate_mult'], 1e-9);
        $this->assertEqualsWithDelta($prof['grace_mult'], $flat['grace_mult'], 1e-9, 'grace coefficients kept from the defaults');
    }

    // ------------------------------------------------------------ neglect uses it

    public function testNeglectGraceAndRateAreScaledPerNpc(): void
    {
        $bond = RelationshipDynamics::defaultConfig()['neglect_bond_types']['bonded'];
        foreach (['mature' => $this->mature(), 'furious' => $this->furious(), 'typical' => $this->typical()] as $who => $d) {
            $prof = RelationshipDynamics::getNeglectProfile($d);
            $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 20 * self::DAY);
            $days = 20 - $bond['grace_game_days'] * $prof['grace_mult'];   // game days past the grace
            $this->assertEqualsWithDelta($days, $r['neglect_days'], 1e-9, $who);
            $this->assertEqualsWithDelta($days * $bond['resentment_per_game_day'] * $prof['rate_mult'], $r['resentment_raw'], 1e-9, $who);
        }
    }

    /**
     * The ruling's scenario: a spouse away 45 game days. A mature, secure, independent partner
     * is cool but not gone; an immature, anxious, proud, codependent one is furious.
     */
    public function testFortyFiveDaysAwayCoolsTheMatureSpouseAndInfuriatesTheCodependentOne(): void
    {
        $mature = $this->mature();
        $mature['dimensions']['warmth']['x'] = 80.0;          // warmth points (0..100), well above ...
        $mature['dimensions']['warmth']['baseline'] = 30.0;   // ... its resting point
        $furious = $this->furious();
        RelationshipDynamics::advanceCalendar($mature, self::T0, self::T0 + 45 * self::DAY);
        RelationshipDynamics::advanceCalendar($furious, self::T0, self::T0 + 45 * self::DAY);

        $m = RelationshipDynamics::getResentmentEffects($mature);
        $this->assertLessThan(80.0, (float) $mature['dimensions']['warmth']['x'], 'cool: warmth faded');
        $this->assertGreaterThan(30.0, (float) $mature['dimensions']['warmth']['x'], 'but not gone cold');
        $this->assertGreaterThanOrEqual(10.0, self::resentment($mature), 'the absence is felt');
        $this->assertCount(1, array_filter($mature['dimensions']['resentment']['grievance_log'],
            fn($g) => ($g['tag'] ?? null) === 'neglect'), 'as one neglect grievance');
        $this->assertFalse($m['withdrawn'], 'not withdrawn: resentment ' . self::resentment($mature));
        $this->assertFalse($m['walkaway'], 'not gone');

        $f = RelationshipDynamics::getResentmentEffects($furious);
        $this->assertTrue($f['walkaway'], 'furious: resentment ' . self::resentment($furious));
    }

    public function testTheSameAbsenceIsWorseTheMoreCodependentImmatureAndProudTheNpc(): void
    {
        $felt = function (array $d): float {
            RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 15 * self::DAY);
            return self::resentment($d);
        };
        $this->assertLessThan($felt($this->typical()), $felt($this->mature()));
        $this->assertLessThan($felt($this->furious()), $felt($this->typical()));
    }

    // ------------------------------------------------------------ the neglect ceiling

    /**
     * Rulings §8: neglect alone takes each NPC only as far as who it is. The ceiling is in
     * resentment points (0..100): ceiling_base + codependence x (2c - 1) + maturity x
     * (m - 50) / 50 + pride x p, clamped to 0..100; its points live in config.
     */
    public function testTheNeglectCeilingFormulaIsExactAndLivesInConfig(): void
    {
        $cfg = RelationshipDynamics::defaultConfig()['neglect_severity'];
        $d = $this->spouse('Proud', 'anxious', ['egocentric'], 30.0);
        $c = 0.6 * 1.0 + 0.4 * 0.5;                 // anxious attachment, Proud temperament (default T)
        $terms = ['codependence' => 2 * $c - 1, 'maturity' => (30.0 - 50.0) / 50.0, 'pride' => 1.0];
        $expected = $cfg['ceiling_base'];
        foreach ($terms as $k => $v) {
            $expected += $cfg['ceiling_points'][$k] * $v;
        }
        $this->assertEqualsWithDelta(max(0.0, min(100.0, $expected)),
            RelationshipDynamics::getNeglectProfile($d)['ceiling'], 1e-9);
        $this->assertEqualsWithDelta($cfg['ceiling_base'], RelationshipDynamics::getNeglectProfile($this->typical())['ceiling'], 1e-9,
            'the typical NPC sits at the base');

        $GLOBALS['db'] = new RelDynNeglectConfigDb(array_merge(RelationshipDynamics::defaultConfig(), [
            'neglect_severity' => ['ceiling_base' => 30.0],
        ]));
        RelationshipDynamics::clearConfigCache();
        $this->assertEqualsWithDelta(30.0, RelationshipDynamics::getNeglectProfile($this->typical())['ceiling'], 1e-9);
    }

    /** Every temperament x attachment x maturity x trait set, left alone for 120 game days. */
    private static ?array $grid = null;

    private function grid(): array
    {
        if (self::$grid !== null) return self::$grid;
        $rows = [];
        foreach (['Independent', 'Romantic', 'Guarded', 'Stoic', 'Anxious', 'Jealous', 'Proud'] as $t) {
            foreach (['avoidant', 'secure', 'anxious', 'toxic'] as $a) {
                foreach ([0.0, 10.0, 20.0, 35.0, 50.0, 65.0, 80.0, 95.0] as $m) {
                    foreach ([[], ['insecure'], ['egocentric'], ['egocentric', 'insecure']] as $traits) {
                        $d = $this->spouse($t, $a, $traits, $m);
                        $prof = RelationshipDynamics::getNeglectProfile($d);
                        $peak = 0.0;
                        $walkDay = null;
                        for ($day = 2; $day <= 120; $day += 2) {   // game days, stepped like a sparse scan
                            RelationshipDynamics::advanceCalendar($d, self::T0 + ($day - 2) * self::DAY, self::T0 + $day * self::DAY);
                            $peak = max($peak, self::resentment($d));
                            if ($walkDay === null && self::resentment($d) >= RelationshipDynamics::RESENTMENT_WALKAWAY_AT) $walkDay = $day;
                        }
                        $rows[] = ['who' => sprintf('%s/%s/m%d/%s', $t, $a, $m, implode('+', $traits) ?: '-'),
                                   'prof' => $prof, 'peak' => $peak, 'walk_day' => $walkDay];
                    }
                }
            }
        }
        return self::$grid = $rows;
    }

    /**
     * The review's finding: scaling the rate only moved the day an NPC maxed out (430 of 560
     * NPCs walked out within 45 game days on neglect alone, the most independent one
     * included). Neglect now plateaus at the NPC's ceiling, and stays there: time never heals.
     */
    public function testNeglectAloneNeverCarriesResentmentPastTheNpcsCeiling(): void
    {
        foreach ($this->grid() as $row) {
            $this->assertLessThanOrEqual($row['prof']['ceiling'] + 1e-4, $row['peak'], $row['who']);   // stored to 4 decimals
            $this->assertSame($row['prof']['ceiling'] >= RelationshipDynamics::RESENTMENT_WALKAWAY_AT, $row['walk_day'] !== null,
                $row['who'] . ' walks iff its ceiling reaches the walkaway (ceiling ' . $row['prof']['ceiling'] . ', peak ' . $row['peak'] . ')');
        }
    }

    public function testOnlyImmatureCodependentNpcsWalkOutOverNeglectAlone(): void
    {
        $walkers = array_filter($this->grid(), fn($r) => $r['walk_day'] !== null);
        $this->assertNotEmpty($walkers, 'the furious ones still go');
        $this->assertLessThan(count($this->grid()) / 4, count($walkers), count($walkers) . ' walk');
        foreach ($walkers as $row) {
            $this->assertGreaterThanOrEqual(0.7, $row['prof']['codependence'], $row['who'] . ': codependent');
            $this->assertLessThan(50.0, $row['prof']['maturity'], $row['who'] . ': immature');
        }
        foreach ($this->grid() as $row) {
            if ($row['prof']['codependence'] <= 0.0) {
                $this->assertLessThan(RelationshipDynamics::RESENTMENT_WITHDRAWAL_AT, $row['peak'],
                    $row['who'] . ': independent and avoidant barely mind, at any maturity or pride');
            }
            if ($row['prof']['maturity'] >= 50.0 || $row['prof']['codependence'] <= 0.5) {
                $this->assertNull($row['walk_day'], $row['who'] . ': mature or not codependent: stays');
            }
        }
    }

    /** The profiles the review named, 45 game days away. */
    public function testTheNamedProfilesStayAfterFortyFiveDays(): void
    {
        $cases = [
            // who => [temperament, attachment, traits, maturity 0..100, withdrawn?]
            'independent, avoidant, immature' => ['Independent', 'avoidant', [], 20.0, false],
            'the typical NPC'                 => ['Romantic', 'secure', [], 50.0, false],
            // traits phase 3 (A20): anxiety counts once (the attachment term): the Anxious
            // temperament on an anxious attachment is hurt, not withdrawn; possessiveness is what
            // makes it codependent
            'anxious and anxious, average'    => ['Anxious', 'anxious', [], 50.0, false],
            'jealous and anxious, average'    => ['Jealous', 'anxious', [], 50.0, true],
            'anxious and anxious, mature'     => ['Anxious', 'anxious', [], 95.0, false],
            'guarded, avoidant, egocentric'   => ['Guarded', 'avoidant', ['egocentric'], 50.0, false],
        ];
        foreach ($cases as $who => [$t, $a, $traits, $m, $withdrawn]) {
            $d = $this->spouse($t, $a, $traits, $m);
            RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 45 * self::DAY);
            $fx = RelationshipDynamics::getResentmentEffects($d);
            $this->assertFalse($fx['walkaway'], "{$who}: resentment " . self::resentment($d));
            $this->assertSame($withdrawn, $fx['withdrawn'], "{$who}: resentment " . self::resentment($d));
            $this->assertGreaterThan(0.0, self::resentment($d), "{$who}: the absence is still felt");
        }
    }

    /** Above its ceiling (from a fight), an absence adds nothing, and takes nothing away. */
    public function testAboveItsCeilingAnAbsenceNeitherAddsNorHeals(): void
    {
        $d = $this->spouse('Independent', 'avoidant', [], 80.0, ['resentment' => 60.0]);
        $this->assertLessThan(60.0, RelationshipDynamics::getNeglectProfile($d)['ceiling']);
        $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 60 * self::DAY);
        $this->assertGreaterThan(0.0, $r['neglect_days'], 'the absence is counted');
        $this->assertEqualsWithDelta(60.0, self::resentment($d), 1e-9, 'time does not heal, neglect adds nothing');
    }

    /** The ceiling is neglect's alone: an open conflict still festers past it (decisions §2). */
    public function testFesterIsNotCappedByTheNeglectCeiling(): void
    {
        $d = $this->spouse('Independent', 'avoidant', [], 20.0);
        $d['in_conflict'] = true;
        $ceiling = RelationshipDynamics::getNeglectProfile($d)['ceiling'];
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 60 * self::DAY);
        $this->assertGreaterThan($ceiling + 5.0, self::resentment($d));
    }

    // ------------------------------------------------------------ the neglect walkaway

    /** The player speaks to the NPC $gameDays after T0 (the request's contact stamp). */
    private static function contactAt(array &$d, float $gameDays): void
    {
        $GLOBALS['gameRequest'][2] = (string) (self::T0 + $gameDays * self::DAY);
        RelationshipDynamics::markContact($d);
    }

    /**
     * Rulings §8 scopes the parting exemption to the neglect walkaway: the one that starts on
     * the player's return from an absence that grew the NPC's resentment.
     */
    public function testAWalkawayOnTheReturnFromNeglectIsANeglectWalkaway(): void
    {
        $d = $this->furious();
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 45 * self::DAY);
        $this->assertTrue(RelationshipDynamics::getResentmentEffects($d)['walkaway']);

        self::contactAt($d, 45.0);                                   // the return greeting
        $this->assertSame('neglect', RelationshipDynamics::walkawayReason($d));

        self::contactAt($d, 45.0 + 10.0 / 1440.0);                   // ten game minutes into the conversation
        $this->assertSame('resentment', RelationshipDynamics::walkawayReason($d),
            'a walkaway later in the conversation is about the conversation');
    }

    public function testAWalkawayFromAFightIsNotANeglectWalkaway(): void
    {
        $d = $this->typical();
        self::contactAt($d, 0.5);
        RelationshipDynamics::applyDelta('resentment', $d, 95.0, 'Romantic');   // an in-person fight
        $this->assertTrue(RelationshipDynamics::getResentmentEffects($d)['walkaway']);
        self::contactAt($d, 0.51);
        $this->assertSame('resentment', RelationshipDynamics::walkawayReason($d));
    }

    /** Angry from a fight, left alone past a ceiling below that: the absence added nothing. */
    public function testAnAbsenceThatAddedNothingDoesNotMakeItANeglectWalkaway(): void
    {
        $d = $this->spouse('Independent', 'avoidant', [], 80.0, ['resentment' => 95.0]);
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 60 * self::DAY);
        self::contactAt($d, 60.0);
        $this->assertSame('resentment', RelationshipDynamics::walkawayReason($d));
    }

    /** The other reasons keep their order (prerequest's walkaway initiation). */
    public function testTheOtherWalkawayReasonsAreUnchanged(): void
    {
        $d = $this->furious();
        RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 45 * self::DAY);
        self::contactAt($d, 45.0);
        $ick = $d;
        $ick['_ick_tracker']['ick_active'] = true;
        $ick['dimensions']['comfort']['x'] = 10.0;
        $this->assertSame('ick_comfort', RelationshipDynamics::walkawayReason($ick));

        $calm = $this->typical();
        $calm['jealousy_anger'] = 100.0;
        $this->assertSame('jealousy', RelationshipDynamics::walkawayReason($calm));
        $calm['jealousy_anger'] = 0.0;
        $this->assertSame('autonomy', RelationshipDynamics::walkawayReason($calm));
    }

    // ------------------------------------------------------------ warmth fade uses it too

    public function testWarmthFadeIsScaledLikeNeglect(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        $rate = (float) $cfg['warmth_absence_fade_per_game_day'];          // warmth points (0..100) per game day
        $graceDays = (float) $cfg['warmth_absence_grace_game_hours'] / 24.0;

        $fade = [];
        foreach (['mature' => $this->mature(), 'furious' => $this->furious(), 'typical' => $this->typical()] as $who => $d) {
            $d['dimensions']['warmth']['x'] = 90.0;
            $d['dimensions']['warmth']['baseline'] = 10.0;
            $prof = RelationshipDynamics::getNeglectProfile($d);
            $r = RelationshipDynamics::advanceCalendar($d, self::T0, self::T0 + 8 * self::DAY);
            $fade[$who] = 90.0 - (float) $d['dimensions']['warmth']['x'];
            $this->assertEqualsWithDelta($rate * $prof['rate_mult'] * (8 - $graceDays * $prof['grace_mult']), $fade[$who], 1e-6, $who);
            $this->assertEqualsWithDelta($fade[$who], $r['warmth_fade'], 1e-6);
        }
        $this->assertEqualsWithDelta($rate * (8 - $graceDays), $fade['typical'], 1e-6, 'typical NPC: the configured rate');
        $this->assertLessThan($fade['typical'], $fade['mature']);
        $this->assertGreaterThan($fade['typical'], $fade['furious']);
    }
}
