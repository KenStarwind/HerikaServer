<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * Protective concern and the values path of both channels (personality-traits design §1.1-1.6,
 * decisions §14 Ken's bar example; roadmap protective-concern). No database: config is the
 * defaults and nothing is stored. The end to end run with the four test beds through the real
 * hooks is RelDynConcernTestBedsPostgresTest.
 *
 * Units: risk and traits 0..1, intensity 0..3, concern / jealousy points 0..100, raw gamets
 * (1 game day = GAMETS_PER_DAY).
 */
final class RelDynConcernTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const D0 = 400;   // game day of night 1

    private array $saved = [];
    private string $logFile;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdconcern');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC toward the player: its own trait vector (read assignment), maturity, trust, core affinity and type. */
    private function npc(array $traits, float $maturity, float $trust = 80.0, float $coreAff = 60.0, string $coreType = 'romantic', array $extra = []): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.6], $traits);
        $x['maturity_start'] = $maturity;
        $mirror = ($coreAff + 100.0) / 2.0;   // the 0..100 affinity mirror of core -100..100
        $d = $extra + [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            '_core_rel_type' => $coreType,
            '_aff_mirror_x' => $mirror,
            'profile_overrides' => ['attachment_style' => 'secure'],
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
        ];
        foreach (['affinity' => $mirror, 'trust' => $trust, 'maturity' => $maturity, 'comfort' => 50.0, 'respect' => 50.0,
                  'self_confidence' => 50.0, 'resentment' => 0.0] as $dim => $v) {
            $d['dimensions'][$dim] = ['x' => $v, 'baseline' => $dim === 'resentment' ? 0.0 : $v];
        }
        return $d;
    }

    private static function at(int $day, float $hour): float
    {
        return $day * self::DAY + $hour * self::HOUR;
    }

    private function clock(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', '1727000000', (string) (int) $gamets, 'Kaida: hello'];
    }

    /** A disclosed exposure through the real eval consumer (contract v1 item, route A). */
    private function tell(array &$d, float $gamets, array $kinds, int $intensity, ?string $when, array $tags = []): array
    {
        $this->clock($gamets);
        $item = [
            'v' => 1, 'npc' => 'Ysgerd', 'npc_id' => 7, 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => $tags, 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.4, 'positive_interaction' => false, 'summary' => 'The player talked about last night.',
            'exposure' => ['flag' => true, 'kinds' => $kinds, 'intensity' => $intensity, 'when' => $when],
        ];
        $feelings = [];
        RelationshipDynamics::processEvalContractItem('Ysgerd', $item, $d, $feelings);
        return $feelings;
    }

    /** What the NPC perceives of a Bannered Mare night with ale on a return (route B), learned. */
    private function returnsFromTheMare(array &$d, int $day, bool $ale = true): array
    {
        $traits = RelDynConcern::traitsOf($d);
        $a = RelDynConcern::appraise(['venue' => 'inn', 'night' => true, 'crowd' => 0.6, 'strangers' => null, 'consumed' => $ale ? 'ale' : null],
            ['pref_combat' => 0.0, 'C' => $traits['C']]);
        $this->clock(self::at($day + 1, 1.0));
        return RelDynConcern::learn('Ysgerd', $d, ['kinds' => array_intersect_key($a['kinds'], ['place' => 1, 'vice' => 1]),
            'gamets' => self::at($day, 23.5), 'route' => 'B'], self::at($day + 1, 1.0));
    }

    private function lines(array &$d, float $now): array
    {
        $out = [];
        foreach (RelDynConcern::takeFeltLines($d, 'Ysgerd', 'Kaida', $now, true)['lines'] as $l) $out[$l['key']] = $l['text'];
        return $out;
    }

    private static function grievanceKinds(array $d): array
    {
        return array_values(array_filter(array_map(fn($g) => $g['kind'] ?? null, (array) ($d['dimensions']['resentment']['grievance_log'] ?? []))));
    }

    // ------------------------------------------------------------ §1.3 risk: crowd alone is not risk

    public function testTheBarAtNightIsRiskyAndACrowdedMarketIsNot(): void
    {
        $neutral = ['pref_combat' => 0.0, 'C' => 0.5];
        $a = fn(array $f, array $npc = null) => RelDynConcern::appraise($f + ['night' => false, 'strangers' => null, 'consumed' => null], $npc ?? $neutral);

        // The design's table (§1.3), for an NPC with no taste for fighting
        $mareNight = $a(['venue' => 'inn', 'night' => true, 'strangers' => 6]);
        $this->assertEqualsWithDelta(0.75, $mareNight['risk'], 1e-9);
        $this->assertSame(3, $mareNight['intensity']);
        $this->assertSame(['place' => 3], $mareNight['kinds']);
        $this->assertSame(2, $mareNight['rival_exposure'], 'six strangers at the inn at night: suitors');

        $withAle = $a(['venue' => 'inn', 'night' => true, 'strangers' => 6, 'consumed' => 'ale']);
        $this->assertEqualsWithDelta(0.80, $withAle['risk'], 1e-9);
        $this->assertSame(['place', 'vice'], array_keys($withAle['kinds']), 'place + vice');

        $mareDay = $a(['venue' => 'inn', 'strangers' => 3]);
        $this->assertEqualsWithDelta(0.3875, $mareDay['risk'], 1e-9);
        $this->assertSame(1, $mareDay['intensity']);
        $this->assertSame(0, $mareDay['rival_exposure'], 'halved by day');

        $marketDay = $a(['venue' => 'town', 'strangers' => 10]);
        $this->assertEqualsWithDelta(0.10, $marketDay['risk'], 1e-9);
        $this->assertSame(0, $marketDay['intensity']);
        $this->assertSame([], $marketDay['kinds'], 'a crowd alone is not risk');
        $marketNight = $a(['venue' => 'town', 'night' => true, 'strangers' => 4]);
        $this->assertEqualsWithDelta(0.1333, $marketNight['risk'], 1e-4);
        $this->assertSame(0, $marketNight['intensity']);
        $this->assertGreaterThan(7 * $marketDay['risk'], $mareNight['risk'], 'the Bannered Mare at night >> the market by day');

        // A night at a friend's house fires nothing: no strangers, no vice venue (§1.1)
        $friends = $a(['venue' => 'other', 'night' => true, 'strangers' => 0]);
        $this->assertSame([], $friends['kinds']);
        $this->assertSame(0, $friends['rival_exposure']);

        // The crowd facet stands in when nobody is counted (the Mare's crowd 0.6 -> 4 strangers)
        $this->assertSame(4, $a(['venue' => 'inn', 'night' => true, 'crowd' => 0.6])['parts']['strangers']);

        // Danger is covered by appetite: a crypt is nothing to Aela, a real risk to a scholar
        $crypt = ['venue' => 'other', 'danger' => 0.6];
        $this->assertEqualsWithDelta(0.48, $a($crypt, ['pref_combat' => -0.2, 'C' => 0.40])['risk'], 1e-9);
        $this->assertSame(['danger' => 2], $a($crypt, ['pref_combat' => -0.2, 'C' => 0.40])['kinds']);
        $this->assertSame(0.0, $a($crypt, ['pref_combat' => 0.8, 'C' => 0.77])['risk'], "her appetite covers it");
    }

    public function testAFightersTasteForCombatIsNoTavernToleranceButABardsTasteForTheInnIs(): void
    {
        $cfg = RelDynConcern::config();
        $this->assertArrayNotHasKey('rough_combat', $cfg['risk'], 'the invented combat-based tolerance is gone');
        $mare = ['venue' => 'inn', 'night' => true, 'strangers' => 6];
        // Ken's bar example holds exactly for a fighter: the §1.3 table, whatever her combat taste
        foreach ([0.0, 0.5, 0.9] as $combat) {
            $a = RelDynConcern::appraise($mare + ['consumed' => 'ale'], ['pref_combat' => $combat, 'C' => 0.74]);
            $this->assertEqualsWithDelta(0.80, $a['risk'], 1e-9, "combat {$combat}: the design's risk");
            $this->assertSame(['place' => 3, 'vice' => 2], $a['kinds'], "combat {$combat}: place + vice");
            $this->assertSame(3, RelDynConcern::perceive('place', 3, ['pref_combat' => $combat, 'C' => 0.74]), "combat {$combat}: told");
        }
        // ... and her appetite still covers danger (§1.3 crypt row)
        $this->assertSame([], RelDynConcern::appraise(['venue' => 'other', 'danger' => 0.6, 'strangers' => 0], ['pref_combat' => 0.8, 'C' => 0.77])['kinds']);

        // A bard whose life is the inn (her own appraisal of an Inn's facets, +0.6): a tavern
        // night with a mead is her world, not a risk; skooma still is
        $bard = ['pref_combat' => 0.0, 'C' => 0.56, 'venue_taste' => 0.6];
        $a = RelDynConcern::appraise($mare + ['consumed' => 'ale'], $bard);
        $this->assertEqualsWithDelta(0.42, $a['parts']['venue_ease'], 1e-9, 'venue_taste 0.7 x her taste 0.6');
        $this->assertSame([], $a['kinds'], 'nothing registers');
        $this->assertSame(2, RelDynConcern::appraise($mare + ['consumed' => 'skooma'], $bard)['kinds']['vice'] ?? 0, 'skooma still does');
        $this->assertSame(0, RelDynConcern::perceive('place', 2, $bard), 'a tavern night, told');
        $this->assertSame(2, RelDynConcern::perceive('rival_exposure', 2, $bard), 'suitors are not a risk she can shrug off');
        // disliking the venue gives no extra risk (the design's table is the floor of it)
        $this->assertEqualsWithDelta(0.75, RelDynConcern::appraise($mare, ['pref_combat' => 0.0, 'C' => 0.5, 'venue_taste' => -0.8])['risk'], 1e-9);
        // venue_taste 0 is the design formula exactly, for everyone
        $design = $cfg;
        $design['risk']['venue_taste'] = 0.0;
        $this->assertSame(['place' => 3, 'vice' => 2], RelDynConcern::appraise($mare + ['consumed' => 'ale'], $bard, $design)['kinds']);
        // the NPC's taste is her appraisal of the venue's facets against her own preferences
        $d = $this->npc([], 70.0, 80.0, 60.0, 'romantic', ['facet_pref_overrides' => ['social' => 0.83, 'crowd' => 0.55]]);
        $this->assertGreaterThan(0.5, RelDynConcern::venueTaste($d, 'Ysgerd'), 'social and crowd: an Inn is her kind of place');
        $d = $this->npc([], 70.0, 80.0, 60.0, 'romantic', ['facet_pref_overrides' => ['combat' => 0.9, 'crowd' => -0.3]]);
        $this->assertLessThanOrEqual(0.0, RelDynConcern::venueTaste($d, 'Ysgerd'), 'a fighter who dislikes a crowd');
    }

    // ------------------------------------------------------------ §1.4 gains, units

    public function testConcernGainIsTheDesignFormulaInConcernPoints(): void
    {
        $x = RelDynConcern::traitsOf($this->npc(['Pr' => 0.6], 70.0));
        $this->assertEqualsWithDelta(0.6, $x['Pr'], 1e-9, 'the NPC\'s own vector is read');
        // Pr .6, trust 80, core affinity 60, a tavern night (I 2): 14.7, below the felt level
        $this->assertEqualsWithDelta(14.72, RelDynConcern::concernGain($this->npc(['Pr' => 0.6], 70.0), 2, $x), 0.01);
        // the same NPC, skooma (I 3): 19.6
        $this->assertEqualsWithDelta(19.63, RelDynConcern::concernGain($this->npc(['Pr' => 0.6], 70.0), 3, $x), 0.01);
        // Pr .85, trust 50, core affinity 80, danger (I 3): 33.7, clamped to 30 per incident
        $y = RelDynConcern::traitsOf($this->npc(['Pr' => 0.85], 70.0));
        $this->assertSame(30.0, RelDynConcern::concernGain($this->npc(['Pr' => 0.85], 70.0, 50.0, 80.0), 3, $y));
        // no affection, no worry
        $this->assertSame(0.0, RelDynConcern::concernGain($this->npc([], 70.0, 80.0, -20.0), 3, $x));
    }

    public function testTrustDampsJealousyStronglyConcernMildlyAndNeverTheCount(): void
    {
        $trusting = $this->npc(['Po' => 0.5, 'Pr' => 0.6], 70.0, 80.0);
        $wary = $this->npc(['Po' => 0.5, 'Pr' => 0.6], 70.0, 0.0);
        // possessive: x (1 - 0.7 trust / 100) -> 0.44 at trust 80; clamped at 0.3
        $this->assertEqualsWithDelta(0.44, RelationshipDynamics::jealousyTrustFactor($trusting), 1e-9);
        $this->assertEqualsWithDelta(0.3, RelationshipDynamics::jealousyTrustFactor($this->npc([], 70.0, 100.0)), 1e-9);
        $this->assertSame(1.0, RelationshipDynamics::jealousyTrustFactor(['dimensions' => []]), 'no trust value yet: not damped');
        $this->assertEqualsWithDelta(0.44, RelationshipDynamics::jealousyEventGain($trusting, 2) / RelationshipDynamics::jealousyEventGain($wary, 2), 1e-9);
        // protective: x (1 - 0.15 trust / 100) -> 0.88 at trust 80
        $x = RelDynConcern::traitsOf($trusting);
        $this->assertEqualsWithDelta(0.88, RelDynConcern::concernGain($trusting, 2, $x) / RelDynConcern::concernGain($wary, 2, $x), 1e-9);

        // The same bar night told to both: the trusting one barely feels the jealousy and still counts it
        $jt = $this->tell($trusting, self::at(self::D0 + 1, 9.0), ['rival_exposure', 'place'], 2, 'last_night');
        $jw = $this->tell($wary, self::at(self::D0 + 1, 9.0), ['rival_exposure', 'place'], 2, 'last_night');
        $this->assertEqualsWithDelta(0.44, $jt['concern']['jealousy'] / $jw['concern']['jealousy'], 1e-9);
        $now = self::at(self::D0 + 1, 9.0);
        foreach ([RelDynConcern::POSSESSIVE, RelDynConcern::PROTECTIVE] as $ch) {
            $this->assertSame(1, RelDynConcern::count($trusting, $ch, $now), "trusting: {$ch} counted");
            $this->assertSame(1, RelDynConcern::count($wary, $ch, $now), "wary: {$ch} counted");
        }
    }

    // ------------------------------------------------------------ §1.6 Ken's bar example, end to end

    public function testKensBarNightMaturePartnerStatesItThenDrawsABoundary(): void
    {
        // Po .5, Pr .6, trust 80, core affinity 60, maturity 70, a romantic partner; she stays home
        $d = $this->npc(['Po' => 0.5, 'Pr' => 0.6, 'C' => 0.6], 70.0);

        // Night 1: back at 01:00 after mead (route B), then he mentions the Mare "with some friends" (route A)
        $b = $this->returnsFromTheMare($d, self::D0);
        $this->assertSame(['stated'], $b['events']);
        $this->assertEqualsWithDelta(14.72, $b['concern'], 0.01, 'place and vice at I 2: +14.7, tolerated, below the felt level');
        $a = $this->tell($d, self::at(self::D0 + 1, 9.0), ['rival_exposure', 'place'], 2, 'last_night');
        $this->assertGreaterThan(0.0, $a['concern']['jealousy'], 'the suitors: a small jealousy');
        $this->assertSame(0.0, $a['concern']['concern'], 'the same night told again adds no worry');
        $this->assertSame(['stated'], $a['concern']['events'], 'the possessive channel: its first counted incident');
        $now = self::at(self::D0 + 1, 9.0);
        $this->assertSame(1, RelDynConcern::count($d, RelDynConcern::PROTECTIVE, $now), 'place + vice: one night');
        $this->assertSame(1, RelDynConcern::count($d, RelDynConcern::POSSESSIVE, $now));
        $l = $this->lines($d, $now);
        $this->assertArrayHasKey('stated_mature', $l);
        $this->assertStringContainsString('plainly and without blame', $l['stated_mature']);
        $this->assertStringContainsString('not something Ysgerd values', $l['stated_mature']);
        $this->assertArrayNotHasKey('worry', $l, 'one night is tolerated: no worry line');
        $this->assertSame([], self::grievanceKinds($d), 'no grievance yet');
        $this->assertSame([], $this->lines($d, $now), 'said once');

        // Night 3: the same; a felt reminder in both channels
        $this->returnsFromTheMare($d, self::D0 + 2);
        $this->tell($d, self::at(self::D0 + 3, 9.0), ['rival_exposure', 'place'], 2, 'last_night');
        $l = $this->lines($d, self::at(self::D0 + 3, 9.0));
        $this->assertArrayHasKey('reminder_possessive', $l);
        $this->assertArrayHasKey('reminder_protective', $l);

        // Night 5: the third counted within the week -> values conflict in both channels
        $this->returnsFromTheMare($d, self::D0 + 4);
        $r = $this->tell($d, self::at(self::D0 + 5, 9.0), ['rival_exposure', 'place'], 2, 'last_night');
        $this->assertSame(['values_conflict:place', 'values_conflict:rival_exposure'], self::grievanceKinds($d));
        $this->assertContains('values_conflict:rival_exposure', $r['concern']['events']);
        $this->assertSame('pending', $d['_concern']['boundary']['state'], 'a mature partner: the §9 boundary flow');
        $this->assertSame(5.0, $d['dimensions']['resentment']['grievance_log'][0]['raw'], 'MDD 15.5 +5, not amplified');
        $now5 = self::at(self::D0 + 5, 9.0);
        $l = $this->lines($d, $now5);
        $this->assertArrayHasKey('boundary', $l);
        $this->assertStringContainsString('No shouting, no ultimatum', $l['boundary']);
        $this->assertSame('probation', $d['_concern']['boundary']['state'], 'the statement starts the probation');
        $this->assertArrayHasKey('probation', $this->lines($d, $now5 + self::HOUR));

        // Later: it happens again during the probation -> a deliberate step back is due
        $this->returnsFromTheMare($d, self::D0 + 7);
        $this->assertSame('failed', $d['_concern']['boundary']['state'], 'the core write is for the next contact (RelDynConcernTestBedsPostgresTest)');
    }

    public function testKensBarNightImmaturePartnerAccusesAndBlowsUp(): void
    {
        // The same partner at maturity 30, reactive and proud: accusation is her style
        $d = $this->npc(['Po' => 0.5, 'Pr' => 0.6, 'L' => 0.8, 'Pd' => 0.6], 30.0);
        $this->assertSame(['m' => 30.0, 'w' => 0.0, 'band' => 'immature', 'path' => 'immature', 'style' => 'accusation'],
            RelDynConcern::expression($d, RelDynConcern::traitsOf($d)));
        $this->returnsFromTheMare($d, self::D0);
        $l = $this->lines($d, self::at(self::D0 + 1, 1.0));
        $this->assertArrayHasKey('stated_accusation', $l, 'night 1: an accusation, not a statement of values');
        $this->assertStringContainsString('as an accusation', $l['stated_accusation']);

        $this->returnsFromTheMare($d, self::D0 + 2);
        $r = $this->returnsFromTheMare($d, self::D0 + 4);
        $this->assertSame(['values_conflict:place', 'blowup'], $r['events']);
        $this->assertSame('none', $d['_concern']['boundary']['state'], 'no calm boundary: it festers and blows up');
        $g = $d['dimensions']['resentment']['grievance_log'][0];
        $this->assertSame('values_conflict:place', $g['kind']);
        $this->assertSame(7.5, $g['raw'], 'resentment amplified +50% (MDD 15.5)');
        $l = $this->lines($d, self::at(self::D0 + 5, 1.0));
        $this->assertArrayHasKey('blowup', $l);
        $this->assertStringContainsString('It boils over', $l['blowup']);

        // A possessive, assertive immature partner goes for control instead
        $c = $this->npc(['Po' => 0.9, 'C' => 0.8, 'L' => 0.4], 30.0);
        $this->assertSame('control', RelDynConcern::expression($c, RelDynConcern::traitsOf($c))['style'], 'high Po + low maturity: control');
        // ... and a withdrawn one sulks
        $s = $this->npc(['E' => 0.15, 'C' => 0.3, 'Po' => 0.3, 'L' => 0.4], 30.0);
        $this->assertSame('sulking', RelDynConcern::expression($s, RelDynConcern::traitsOf($s))['style']);
        // in between, the bands blend: she means to say it evenly and it comes out edged
        $mid = $this->npc(['Po' => 0.5, 'L' => 0.8, 'Pd' => 0.6], 50.0);
        $this->assertSame('mixed', RelDynConcern::expression($mid, RelDynConcern::traitsOf($mid))['band']);
        $this->returnsFromTheMare($mid, self::D0);
        $l = $this->lines($mid, self::at(self::D0 + 1, 1.0));
        $this->assertStringContainsString('means to say it evenly', $l['stated_mixed']);
        $this->assertStringContainsString('as an accusation', $l['stated_mixed']);
    }

    public function testALowPossessiveProtectorNeverFilesRivalExposureButCountsThePlace(): void
    {
        // Mjoll-like: Po .1, Pr .85
        $d = $this->npc(['Po' => 0.1, 'Pr' => 0.85], 70.0);
        foreach ([0, 2, 4] as $n) {
            $this->tell($d, self::at(self::D0 + $n + 1, 9.0), ['rival_exposure', 'place', 'vice'], 2, 'last_night');
        }
        $now = self::at(self::D0 + 5, 9.0);
        $this->assertSame(0, RelDynConcern::count($d, RelDynConcern::POSSESSIVE, $now), 'Po .1 never files a bar night under values');
        $this->assertSame(['values_conflict:place'], self::grievanceKinds($d), 'the place / drink pattern does');
        $this->assertGreaterThan(0.0, $d['jealousy_anger'], 'she still feels a little of it');
    }

    /**
     * §1.5 / §1.6: the counter is pattern[kind], deduplicated by (kind, game day). A danger day,
     * a tavern night and a drink on another day are three patterns at one each, not one
     * pattern at three; three tavern nights are a values conflict.
     */
    public function testMixedProtectiveKindsDoNotAddUpToAValuesConflict(): void
    {
        $d = $this->npc(['Po' => 0.1, 'Pr' => 0.7], 70.0);
        $r = [];
        $r[] = $this->tell($d, self::at(self::D0, 20.0), ['danger'], 2, 'today')['concern']['events'];
        $r[] = $this->tell($d, self::at(self::D0 + 2, 9.0), ['place'], 2, 'last_night')['concern']['events'];
        $r[] = $this->tell($d, self::at(self::D0 + 3, 20.0), ['vice'], 2, 'today')['concern']['events'];
        $now = self::at(self::D0 + 3, 21.0);
        $this->assertSame([['stated'], ['stated'], ['stated']], $r, 'each kind is stated once, on its own first night');
        $this->assertSame(['place' => 1, 'vice' => 1, 'danger' => 1], RelDynConcern::patterns($d, $now));
        $this->assertSame(1, RelDynConcern::count($d, RelDynConcern::PROTECTIVE, $now), 'the channel is as far as its furthest kind');
        $this->assertSame([], self::grievanceKinds($d), 'three different kinds are not three of the same');
        $this->assertSame(1, RelDynConcern::count($d, 'place', $now));
        // two more tavern nights in the week: the place pattern reaches three
        $this->tell($d, self::at(self::D0 + 5, 9.0), ['place'], 2, 'last_night');
        $this->assertArrayHasKey('reminder_protective', $this->lines($d, self::at(self::D0 + 5, 9.0)), 'the second tavern night: a reminder');
        $e = $this->tell($d, self::at(self::D0 + 6, 9.0), ['place'], 2, 'last_night')['concern']['events'];
        $this->assertContains('values_conflict:place', $e);
        $this->assertSame(['values_conflict:place'], self::grievanceKinds($d));
        $this->assertSame(['vice' => 1, 'danger' => 1], RelDynConcern::patterns($d, self::at(self::D0 + 6, 9.0)), 'the tavern nights are filed; the rest stay');
        $this->assertSame(['place'], $d['_concern']['boundary']['kinds'] ?? null);
        $this->assertSame(['vice' => 1, 'danger' => 1], RelDynConcern::jev($d, self::at(self::D0 + 6, 9.0))['pattern'], 'Jev gets the pattern');
    }

    /**
     * The values path is mature for an in-between NPC (w >= 0.5) while her band is 'mixed': her
     * first word slips out sharp, but once the calm boundary is under way her worry is said
     * calmly too. One voice per prompt: never "sharp, as blame" next to "no shouting".
     */
    public function testAnInBetweenPartnerSpeaksInOneVoiceOnceTheBoundaryIsUnderWay(): void
    {
        $d = $this->npc(['Po' => 0.1, 'Pr' => 0.95, 'L' => 0.8, 'Pd' => 0.6], 52.0, 20.0, 90.0);
        $x = RelDynConcern::expression($d, RelDynConcern::traitsOf($d));
        $this->assertSame(['mixed', 'mature', 'accusation'], [$x['band'], $x['path'], $x['style']]);
        $this->returnsFromTheMare($d, self::D0);
        $this->returnsFromTheMare($d, self::D0 + 1);
        $l = $this->lines($d, self::at(self::D0 + 2, 1.5));
        $this->assertStringContainsString('as blame', $l['worry'], 'before the boundary: the in-between band shows');
        $this->returnsFromTheMare($d, self::D0 + 3);
        $this->assertSame('pending', $d['_concern']['boundary']['state']);
        $l = $this->lines($d, self::at(self::D0 + 4, 1.5));
        $this->assertArrayHasKey('boundary', $l);
        $this->assertStringContainsString('No shouting, no ultimatum', $l['boundary']);
        $this->assertStringContainsString('without blame', $l['worry'], 'the same prompt: calm');
        $this->assertStringNotContainsString('as blame', $l['worry']);
        $this->assertStringNotContainsString('as blame', $this->lines($d, self::at(self::D0 + 4, 3.0))['worry'], 'on probation: calm');
        // after the step-back (the lane's record), still one voice
        $d['_concern']['boundary'] = ['state' => 'none', 'stepped_back_gamets' => self::at(self::D0 + 6, 1.0), 'from' => 'romantic', 'to' => 'platonic', 'kind' => 'place'];
        $this->assertStringNotContainsString('as blame', $this->lines($d, self::at(self::D0 + 6, 2.0))['worry']);
        // an immature partner keeps her own voice throughout (her path is the blow-up)
        $i = $this->npc(['Po' => 0.1, 'Pr' => 0.95, 'L' => 0.8, 'Pd' => 0.6], 30.0, 20.0, 90.0);
        foreach ([0, 1, 3] as $n) $this->returnsFromTheMare($i, self::D0 + $n);
        $this->assertStringContainsString('as blame', $this->lines($i, self::at(self::D0 + 4, 1.5))['worry']);
    }

    /**
     * One boundary at a time per bond: the values boundary waits while the fulfillment lane's
     * §9 boundary runs (its third counted night is said plainly instead), and either lane's
     * boundary holds a romance promotion back.
     */
    public function testOneBoundaryAtATimeAndEitherHoldsRomanceBack(): void
    {
        $d = $this->npc(['Po' => 0.1, 'Pr' => 0.7], 70.0);
        $d['_fulfillment'] = ['boundary' => ['state' => 'probation', 'started_gamets' => self::at(self::D0, 9.0), 'until_gamets' => self::at(self::D0 + 7, 9.0)]];
        $this->assertContains('boundary', RelDynRomance::blockingStates($d), 'the fulfillment lane');
        foreach ([0, 1, 2] as $n) $r = $this->returnsFromTheMare($d, self::D0 + $n);
        $this->assertContains('values_conflict:place', $r['events'], 'the values conflict is still filed');
        $this->assertSame('none', $d['_concern']['boundary']['state'], 'no second boundary while the first runs');
        $this->assertContains('stated', $r['events'], 'said plainly instead');

        $c = $this->npc(['Po' => 0.1, 'Pr' => 0.7], 70.0);
        foreach ([0, 1, 2] as $n) $this->returnsFromTheMare($c, self::D0 + $n);
        $this->assertSame('pending', $c['_concern']['boundary']['state']);
        $this->assertTrue(RelDynConcern::boundaryActive($c));
        $this->assertContains('boundary', RelDynRomance::blockingStates($c), 'the concern lane holds a promotion back too');
        $this->assertNotContains('boundary', RelDynRomance::blockingStates($this->npc(['Pr' => 0.7], 70.0)));
    }

    /**
     * After a deliberate step-back out of the romance (either lane's record, core now holding
     * the type it stepped back to), the romance's intimacy is over; a new romance ends it.
     */
    public function testAStepBackEndsTheRomancesIntimacyUntilANewRomance(): void
    {
        $d = $this->npc(['Pr' => 0.7], 70.0, 80.0, 60.0, 'platonic');
        RelationshipDynamics::setPassion($d, 60.0);
        $d['_attraction'] = ['enabled' => true, 'attracted' => true, 'outcome' => 'drawn'];
        $this->assertTrue(RelDynIntimacy::inPlay($d), 'drawn, passion 60: in play before');
        $d['_concern']['boundary'] = ['state' => 'none', 'stepped_back_gamets' => self::at(self::D0, 1.0), 'from' => 'romantic', 'to' => 'platonic', 'kind' => 'place'];
        $this->assertSame(['lane' => 'concern', 'from' => 'romantic', 'to' => 'platonic', 'gamets' => (float) self::at(self::D0, 1.0)],
            RelDynFulfillment::romanceSteppedBack($d));
        $this->assertFalse(RelDynIntimacy::inPlay($d), 'that closeness is over');
        $this->assertFalse(RelDynIntimacy::physicalInPlay($d));
        $this->assertStringContainsString('kind deflection', (string) RelDynAttraction::feltText('Ysgerd', $d['_attraction'] + ['passion' => ['curve' => 1.2]],
            ['player' => 'Kaida', 'tier' => 2, 'passion' => 60.0, 'stepped_back' => true]), 'flirtation: the friend\'s kind deflection');
        // the fulfillment lane's record reads the same
        $f = $d;
        unset($f['_concern']);
        $f['_fulfillment']['boundary'] = ['state' => 'none', 'stepped_back_gamets' => self::at(self::D0, 2.0), 'from' => 'crush', 'to' => 'platonic'];
        $this->assertSame('fulfillment', RelDynFulfillment::romanceSteppedBack($f)['lane']);
        // a new romance (core back in a romance type) ends it; so does a step-back that core no longer holds
        $d['_core_rel_type'] = 'romantic';
        $this->assertNull(RelDynFulfillment::romanceSteppedBack($d));
        $this->assertTrue(RelDynIntimacy::inPlay($d));
        $d['_core_rel_type'] = 'professional';
        $this->assertNull(RelDynFulfillment::romanceSteppedBack($d), 'core moved on to another type');
    }

    /**
     * §1.2 present route: "the place appraisal sees it directly". With the player at a crypt,
     * a scholar's appetite does not cover the danger: a 'danger' exposure (one per game day,
     * at its worst); a fighter's appetite does. A tavern with her there is a shared night:
     * nothing.
     */
    public function testAPresentPartnerSeesTheDangerDirectly(): void
    {
        $crypt = ['name' => 'Bleak Falls Barrow', 'tags' => ['Dungeon']];
        $facets = RelDynFacets::placeFacets($crypt);
        $this->assertGreaterThanOrEqual(0.6, $facets['danger'], 'a barrow: a dungeon');
        $scholar = $this->npc(['Pr' => 0.7, 'C' => 0.4], 70.0, 80.0, 60.0, 'romantic', ['facet_pref_overrides' => ['combat' => -0.2, 'scholarly' => 0.9]]);
        $fighter = $this->npc(['Pr' => 0.7, 'C' => 0.77], 70.0, 80.0, 60.0, 'romantic', ['facet_pref_overrides' => ['combat' => 0.8]]);
        $now = self::at(self::D0, 14.0);
        $s = RelDynConcern::onPresentPlace('Ysgerd', $scholar, $crypt, $facets, $now);
        $this->assertGreaterThan(0.0, $s['concern'], 'the scholar worries');
        $this->assertSame(['stated'], $s['events']);
        $this->assertSame(['danger' => 2], $scholar['_concern']['incidents'][0]['kinds']);
        $this->assertSame(['present'], $scholar['_concern']['incidents'][0]['routes']);
        $again = RelDynConcern::onPresentPlace('Ysgerd', $scholar, $crypt, $facets, $now + self::HOUR);
        $this->assertSame(0.0, $again['concern'], 'the same day: counted once');
        $this->assertSame(1, RelDynConcern::count($scholar, 'danger', $now + self::HOUR));
        $f = RelDynConcern::onPresentPlace('Ysgerd', $fighter, $crypt, $facets, $now);
        $this->assertSame([0.0, []], [$f['concern'], $f['events']], 'her appetite covers it');
        $this->assertArrayNotHasKey('_concern', $fighter);
        $inn = ['name' => 'The Bannered Mare', 'tags' => ['Inn']];
        $t = RelDynConcern::onPresentPlace('Ysgerd', $fighter, $inn, RelDynFacets::placeFacets($inn), self::at(self::D0, 22.0));
        $this->assertSame([], $t['events'], 'out together is a shared night');
    }

    public function testOneNightCountsOnceHoweverManyRoutesRevealIt(): void
    {
        $d = $this->npc(['Po' => 0.5, 'Pr' => 0.6], 70.0);
        $b = $this->returnsFromTheMare($d, self::D0);                                         // 01:00, drunk and late
        $a = $this->tell($d, self::at(self::D0 + 1, 9.0), ['place', 'vice'], 2, 'last_night');   // 09:00: "last night"
        $this->assertGreaterThan(0.0, $b['concern']);
        $this->assertSame(0.0, $a['concern']['concern'] ?? 0.0, 'the same night: no second worry');
        $this->assertCount(1, $d['_concern']['incidents']);
        $this->assertSame(['B', 'A'], $d['_concern']['incidents'][0]['routes']);
        // told the next evening as "today" (the day after): a new night
        $this->tell($d, self::at(self::D0 + 1, 21.0), ['place'], 2, 'today');
        $this->assertSame(2, RelDynConcern::count($d, RelDynConcern::PROTECTIVE, self::at(self::D0 + 1, 21.0)));
        // older than the window: out of the count
        $this->assertSame(0, RelDynConcern::count($d, RelDynConcern::PROTECTIVE, self::at(self::D0 + 10, 21.0)));
    }

    public function testReassuranceAddressesOneIncidentAndEasesTheWorry(): void
    {
        $d = $this->npc(['Po' => 0.5, 'Pr' => 0.9], 70.0, 20.0, 90.0);
        $this->returnsFromTheMare($d, self::D0);
        $this->returnsFromTheMare($d, self::D0 + 1);
        $now = self::at(self::D0 + 2, 9.0);
        $before = RelDynConcern::level($d);
        $this->assertGreaterThanOrEqual(25.0, $before, 'two nights: worried');
        $l = $this->lines($d, $now);
        $this->assertStringContainsString('worried enough to bring it up', $l['worry'], 'past 50: raises it');
        $this->assertStringContainsString('without blame', $l['worry'], 'mature: says so plainly');

        $this->clock($now);
        $item = ['v' => 1, 'npc' => 'Ysgerd', 'npc_id' => 7, 'gamets' => (int) $now, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 2, 'trust' => 2, 'comfort' => 1, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['reassurance'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.4, 'positive_interaction' => true, 'summary' => 'The player promised to be careful.'];
        $f = [];
        RelationshipDynamics::processEvalContractItem('Ysgerd', $item, $d, $f);
        $this->assertContains('addressed', $f['concern']['events']);
        $this->assertSame(1, RelDynConcern::count($d, RelDynConcern::PROTECTIVE, $now), 'one incident addressed');
        $this->assertEqualsWithDelta($before - 15.0, RelDynConcern::level($d), 1e-9);

        // Time together eases it (4 concern points per game hour); an absence heals nothing
        $d['_last_contact_gamets'] = $now;
        $level = RelDynConcern::level($d);
        RelDynConcern::onContact('Ysgerd', $d, $now + 1.5 * self::HOUR, 'Kaida');
        $this->assertEqualsWithDelta($level - 6.0, RelDynConcern::level($d), 1e-9);
        $d['_last_contact_gamets'] = $now + 1.5 * self::HOUR;
        $level = RelDynConcern::level($d);
        RelDynConcern::onContact('Ysgerd', $d, $now + 30 * self::HOUR, 'Kaida');
        $this->assertEqualsWithDelta($level, RelDynConcern::level($d), 1e-9, 'a day apart: no easing');
    }

    public function testFlirtingInFrontOfThemIsARivalIncidentAndAromanticHasNoRivalChannel(): void
    {
        $d = $this->npc(['Po' => 0.6], 70.0);
        $now = self::at(self::D0, 14.0);
        $this->clock($now);
        $item = ['v' => 1, 'npc' => 'Ysgerd', 'npc_id' => 7, 'gamets' => (int) $now, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => -2, 'trust' => -1, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => ['jealousy_trigger'], 'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => true, 'rival' => 'Ysolda', 'intensity' => 2],
            'significance' => 0.4, 'positive_interaction' => false, 'summary' => 'The player flirted with Ysolda.'];
        $f = [];
        RelationshipDynamics::processEvalContractItem('Ysgerd', $item, $d, $f);
        $this->assertGreaterThan(0.0, $f['jealousy']);
        $this->assertSame(1, RelDynConcern::count($d, RelDynConcern::POSSESSIVE, $now), "a 'rival' incident");
        $this->assertSame(['rival'], array_keys($d['_concern']['incidents'][0]['kinds']));

        // §1.7: aromantic, no romantic rival: no jealousy and no possessive count
        $aro = $this->npc(['Po' => 0.6], 70.0, 80.0, 60.0, 'romantic', ['relationship_preference' => 'aromantic']);
        $this->assertSame(0.0, RelationshipDynamics::jealousyEventGain($aro, 2, 1.0));
        $r = $this->tell($aro, self::at(self::D0 + 1, 9.0), ['rival_exposure', 'place'], 2, 'last_night');
        $this->assertSame(0, RelDynConcern::count($aro, RelDynConcern::POSSESSIVE, self::at(self::D0 + 1, 9.0)));
        $this->assertSame(1, RelDynConcern::count($aro, RelDynConcern::PROTECTIVE, self::at(self::D0 + 1, 9.0)), 'protective as for any friend');
        $this->assertSame(0.0, $r['concern']['jealousy']);

        // Not a partner: rival exposure is none of a friend's business (commitment 0)
        $friend = $this->npc(['Po' => 0.6], 70.0, 80.0, 60.0, 'platonic');
        $r = $this->tell($friend, self::at(self::D0 + 1, 9.0), ['rival_exposure'], 2, 'last_night');
        $this->assertSame(0.0, $r['concern']['jealousy'] ?? 0.0);
        $this->assertSame(0, RelDynConcern::count($friend, RelDynConcern::POSSESSIVE, self::at(self::D0 + 1, 9.0)));
    }

    // ------------------------------------------------------------ the contract field (route A)

    public function testTheExposureFieldIsOptionalAndAdditiveToContractV1(): void
    {
        $job = ['npc' => 'Ysgerd', 'player_name' => 'Kaida', 'npc_id' => 7, 'gamets' => 5000];
        $base = '{"signals": {"affinity": 1, "trust": 0, "comfort": 0, "respect": 0, "passion": 0, "maturity": 0}, "tags": [],'
            . ' "grievance": {"flag": false, "kind": null, "severity": 0}, "jealousy": {"flag": false, "rival": null, "intensity": 0},'
            . ' "significance": 0.3, "summary": "Talked about the evening."';
        $old = RelDynEval::parseResponse($base . '}', $job);
        $this->assertArrayNotHasKey('exposure', $old, 'an item without it is unchanged');
        $off = RelDynEval::parseResponse($base . ', "exposure": {"flag": false, "kinds": [], "intensity": 0, "when": null}}', $job);
        $this->assertSame($old, $off);

        $on = RelDynEval::parseResponse($base . ', "exposure": {"flag": true, "kinds": ["rival_exposure", "Place", "gossip", "rival"], "intensity": 5, "when": "last_night"}}', $job);
        $this->assertSame(['flag' => true, 'kinds' => ['rival_exposure', 'place'], 'intensity' => 3, 'when' => 'last_night', 'disclosed' => true],
            $on['exposure'], 'unknown kinds dropped, rival is the jealousy object, intensity clamped');
        $bad = RelDynEval::parseResponse($base . ', "exposure": "the tavern"}', $job);
        $this->assertNotNull($bad, 'a malformed optional field never drops the item');
        $this->assertArrayNotHasKey('exposure', $bad);
        $this->assertStringContainsString('exposure is not an object', (string) file_get_contents($this->logFile));

        $n = RelationshipDynamics::normalizeEvalContractItem($on + ['v' => 1]);
        $this->assertSame($on['exposure'], $n['exposure'], 'the consumer keeps it');
        $this->assertSame(RelationshipDynamics::evalContractFingerprint($old + ['v' => 1]),
            RelationshipDynamics::evalContractFingerprint(array_diff_key($on, ['exposure' => 1]) + ['v' => 1]), 'fingerprints ignore it');

        $messages = RelDynEval::buildMessages('Ysgerd', 'Kaida', ['earlier' => [], 'current' => [['speaker' => 'Kaida', 'listener' => null, 'text' => 'hi']]], [], []);
        $this->assertStringContainsString('EXPOSURE: flag true only when Kaida tells Ysgerd about something Ysgerd was not there for', $messages[1]['content']);
        $this->assertStringContainsString('"exposure": {"flag": false, "kinds": [], "intensity": 0, "when": null}', $messages[1]['content']);
    }

    public function testFeltLinesAreFeelingsNeverNumbers(): void
    {
        $d = $this->npc(['Po' => 0.5, 'Pr' => 0.95, 'L' => 0.8], 50.0, 10.0, 100.0);
        foreach ([0, 1, 2] as $n) $this->returnsFromTheMare($d, self::D0 + $n);
        $this->tell($d, self::at(self::D0 + 3, 9.0), ['rival_exposure', 'danger'], 3, 'today');
        $this->assertGreaterThanOrEqual(75.0, RelDynConcern::level($d));
        $l = $this->lines($d, self::at(self::D0 + 3, 9.0));
        $this->assertStringContainsString('frightened for Kaida', $l['worry'], 'at 75: insists');
        foreach ($l as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$key}: no numbers");
            $this->assertDoesNotMatchRegularExpression('/\b(concern|jealousy|points?|trust)\b/i', $text, "{$key}: no state names");
        }
        $jev = RelDynConcern::jev($d, self::at(self::D0 + 3, 9.0));
        $this->assertSame('insist', $jev['band'], 'Jev gets the numbers');
        $this->assertGreaterThanOrEqual(75.0, $jev['level']);
    }
}
