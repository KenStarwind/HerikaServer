<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * impulse-short-band + motivation-inner-conflict (MDD 13-14, traits design §2.5): the short band's
 * drives, decay, trait threshold and expression style, the long band (her intrinsic goals and
 * the director goal) and the inner conflict, and what the LLM (a feeling) and Jev (numbers) get.
 * No database: config is the defaults. The test beds run end to end in
 * RelDynImpulseTestBedsPostgresTest.
 */
final class RelDynImpulseTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 400 * self::DAY;
    /** One minute of play on the play clock (the combat windows' convention). */
    private const PLAY_MIN = 60 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
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

    private static function npc(string $preset, array $dims = [], array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => $preset, 'stage' => 'early', 'dimensions' => [], '_accumulated_play_gamets' => 1000000.0];
        foreach ($dims + ['passion' => 0.0, 'maturity' => 55.0, 'trust' => 50.0, 'respect' => 50.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    private static function goal(string $type, float $priority, array $extra = []): array
    {
        return $extra + ['id' => $type, 'type' => $type, 'source' => 'test', 'priority' => $priority, 'progress' => 0.0, 'active' => true,
            'facets' => [], 'keywords' => []];
    }

    public function testThresholdIsTheExactFitThroughBoldGuardedAnxiousOnTheTraitEngine(): void
    {
        $t = fn(string $p) => RelDynImpulse::threshold(RelDynTraits::presetPoint($p));
        // MDD 13.1: Bold 30, Guarded 70, Anxious 40 (the fit is exact to rounding)
        $this->assertEqualsWithDelta(30.0, $t('Bold'), 1.0);
        $this->assertEqualsWithDelta(70.0, $t('Guarded'), 1.0);
        $this->assertEqualsWithDelta(40.0, $t('Anxious'), 1.0);
        // traits design §2.5: Stoic 55, Proud 57, Playful 24, the base vector 45
        $this->assertEqualsWithDelta(55.0, $t('Stoic'), 1.0);
        $this->assertEqualsWithDelta(57.0, $t('Proud'), 1.0);
        $this->assertEqualsWithDelta(24.0, $t('Playful'), 1.0);
        $this->assertEqualsWithDelta(45.0, RelDynImpulse::threshold(RelDynImpulse::traitsOf(['inferred_temperament' => null])), 1.0);
        // It reads traits, not the label: guard raises it, confidence lowers it a little
        $x = RelDynTraits::presetPoint('Bold');
        $this->assertGreaterThan($t('Bold'), RelDynImpulse::threshold(['G' => 0.6] + $x));
        $this->assertLessThan($t('Bold'), RelDynImpulse::threshold(['C' => 0.9] + $x));
    }

    public function testExpressionStyleFollowsTheMddTableAndFalseStartsComeFromReactivityAndLowConfidence(): void
    {
        $expected = [
            'Bold' => 'bold', 'Defiant' => 'bold', 'Guarded' => 'guarded', 'Stoic' => 'stoic', 'Independent' => 'stoic',
            'Proud' => 'proud', 'Playful' => 'playful', 'Anxious' => 'anxious', 'Jealous' => 'anxious',
            'Romantic' => 'gentle', 'Gentle' => 'gentle', 'Humble' => 'gentle', 'Nurturing' => 'gentle',
        ];
        foreach ($expected as $preset => $style) {
            $this->assertSame($style, RelDynImpulse::style(RelDynTraits::presetPoint($preset)), $preset);
        }
        // Anxious with confidence grown: no more false starts
        $this->assertNotSame('anxious', RelDynImpulse::style(['C' => 0.6] + RelDynTraits::presetPoint('Anxious')));
        // Anxious calmed (low reactivity): no more false starts either
        $this->assertNotSame('anxious', RelDynImpulse::style(['L' => 0.4] + RelDynTraits::presetPoint('Anxious')));
    }

    public function testTheSameRomanticImpulseFiresForBoldAndOnlyStirsInGuarded(): void
    {
        // Passion 60, alone together: the MDD 13.1 example
        foreach (['Bold' => ['impulse', 'says it straight out'], 'Guarded' => ['impulse_stirring', 'half-wants'],
                     'Anxious' => ['impulse', 'starts again'], 'Playful' => ['impulse', 'teasing'], 'Proud' => ['impulse', 'comes over'],
                     'Stoic' => ['impulse', 'one loaded sentence'], 'Gentle' => ['impulse', 'lingering']] as $preset => [$key, $words]) {
            $d = self::npc($preset, ['passion' => 60.0]);
            $s = RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0, 'tier' => 2, 'audience' => 0]);
            $this->assertEqualsWithDelta(60.0, $s['levels']['romantic'], 1e-9, $preset);
            $lines = RelDynImpulse::feltLines('Aela the Huntress', 'Kaida', $d);
            $this->assertCount(1, $lines, $preset);
            $this->assertSame($key, $lines[0]['key'], $preset);
            $this->assertStringContainsString($words, $lines[0]['text'], $preset);
            $this->assertStringContainsString('close to Kaida', $lines[0]['text'], $preset);
            $this->assertDoesNotMatchRegularExpression('/\d/', $lines[0]['text'], 'a feeling, never a number');
            $this->assertSame('bond', $lines[0]['scope']);
        }
    }

    public function testRomanticDriveIsPassionTimesPrivacyAndNothingWhenPlatonicOrStrained(): void
    {
        $x = RelDynTraits::presetPoint('Bold');
        $d = self::npc('Bold', ['passion' => 60.0]);
        $alone = RelDynImpulse::drives($d, ['audience' => 0], $x)['levels']['romantic'];
        $crowd = RelDynImpulse::drives($d, ['audience' => 4], $x)['levels']['romantic'];
        $this->assertEqualsWithDelta(60.0, $alone, 1e-9);
        $this->assertEqualsWithDelta(30.0, $crowd, 1e-9, 'privacy = 1 / (1 + 0.25 x 4)');
        $this->assertSame(0.0, RelDynImpulse::drives($d, ['platonic' => true], $x)['levels']['romantic'], 'not that kind of pull');
        $this->assertSame(0.0, RelDynImpulse::drives($d, ['strained' => true], $x)['levels']['romantic'], 'open conflict, the ick, hurt');
        $this->assertSame(0.0, RelDynImpulse::drives($d, ['present' => false], $x)['levels']['romantic'], 'the player is not here');
        // The glow after a shared fight turns romantic from the spark up
        $glow = RelDynImpulse::drives($d, ['aftermath' => true, 'audience' => 0], $x);
        $this->assertEqualsWithDelta(75.0, $glow['levels']['romantic'], 1e-9);
        $this->assertSame('aftermath', $glow['sources']['romantic']);
        $cool = self::npc('Bold', ['passion' => 10.0]);
        $this->assertEqualsWithDelta(10.0, RelDynImpulse::drives($cool, ['aftermath' => true, 'audience' => 0], $x)['levels']['romantic'], 1e-9);
    }

    public function testProtectiveSurvivalAndCuriositySources(): void
    {
        $nurse = RelDynTraits::presetPoint('Nurturing');   // Pr .85
        $free = RelDynTraits::presetPoint('Playful');      // Pr .30
        $d = self::npc('Nurturing');
        $bleed = RelDynImpulse::drives($d, ['player_bleeding_out' => true], $nurse);
        $this->assertSame('player_bleedout', $bleed['sources']['protective']);
        $this->assertEqualsWithDelta(100.0, $bleed['levels']['protective'], 1e-9, 'capped at 100');
        $hurtNurse = RelDynImpulse::drives($d, ['physical' => ['injured']], $nurse)['levels']['protective'];
        $hurtFree = RelDynImpulse::drives($d, ['physical' => ['injured']], $free)['levels']['protective'];
        $this->assertEqualsWithDelta(60.0 * 1.35, $hurtNurse, 1e-9, 'x (1 + (Pr - 0.5))');
        $this->assertGreaterThan($hurtFree, $hurtNurse, 'protectiveness scales the urge');
        $this->assertEqualsWithDelta(80.0 * 0.6 * 1.35, RelDynImpulse::drives($d, ['concern' => 80.0], $nurse)['levels']['protective'], 1e-9,
            'her worry feeds it');

        $fall = RelDynImpulse::drives($d, ['combat' => ['in_combat' => true, 'bleeding_out' => true, 'health_pct' => null]], $nurse);
        $this->assertSame(100.0, $fall['levels']['survival']);
        $this->assertSame('bleeding_out', $fall['sources']['survival']);
        $this->assertSame(75.0, RelDynImpulse::drives($d, ['combat' => ['in_combat' => true, 'health_pct' => 0.2]], $nurse)['levels']['survival']);
        $this->assertSame(40.0, RelDynImpulse::drives($d, ['combat' => ['in_combat' => true, 'health_pct' => 0.5]], $nurse)['levels']['survival']);
        $this->assertSame(0.0, RelDynImpulse::drives($d, ['combat' => ['in_combat' => false, 'health_pct' => 0.2]], $nurse)['levels']['survival'],
            'her HP counts only while she fights');
        $cold = RelDynImpulse::drives($d, ['physical' => ['snowing', 'cold']], $nurse);
        $this->assertSame(45.0, $cold['levels']['survival']);

        // Curiosity: a place new to her, by how much it calls to her
        $ruin = ['scholarly' => 0.6, 'crafting' => 0.4, 'enchanting' => 0.3, 'adventure' => 0.8, 'combat' => 0.6, 'danger' => 0.7];
        $scholar = ['scholarly' => 0.9, 'enchanting' => 0.6, 'adventure' => 0.4];
        $hunter = ['scholarly' => -0.6, 'combat' => 0.8, 'nature' => 1.0];
        $env = ['now' => self::T0, 'place' => 'Mzinchaleft', 'facets' => $ruin];
        $a = RelDynImpulse::drives($d, $env + ['prefs' => $scholar], $nurse)['levels']['curiosity'];
        $b = RelDynImpulse::drives($d, $env + ['prefs' => $hunter], $nurse)['levels']['curiosity'];
        $this->assertEqualsWithDelta(80.0 * (0.3 + 0.7 * 0.54), $a, 1e-9);
        $this->assertEqualsWithDelta(80.0 * 0.3, $b, 1e-9, 'a hunter only glances around a ruin');
        $this->assertSame(0.0, RelDynImpulse::drives($d, $env + ['prefs' => $scholar, 'seen' => self::T0 - self::DAY], $nurse)['levels']['curiosity'],
            'seen yesterday: nothing new');
        $this->assertGreaterThan(0.0, RelDynImpulse::drives($d, $env + ['prefs' => $scholar, 'seen' => self::T0 - 31 * self::DAY], $nurse)['levels']['curiosity'],
            'a month later it is new again');
    }

    public function testSocialImpulseRisesWithTheLonelinessTimerAndAMeaningfulExchangeMeetsIt(): void
    {
        $d = self::npc('Gentle', [], ['context_tier_hwm' => 2]);
        RelDynImpulse::noteMeaningful($d, 0.5, self::T0);
        $this->assertFalse(RelDynImpulse::noteMeaningful($d, 0.1, self::T0 + self::HOUR), 'small talk is not meaningful');
        $x = RelDynTraits::presetPoint('Gentle');
        $env = fn(float $at) => ['now' => $at, 'tier' => 2, 'last_meaningful' => $d[RelDynImpulse::KEY]['last_meaningful_gamets']];
        $this->assertSame(0.0, RelDynImpulse::drives($d, $env(self::T0 + 3 * self::HOUR), $x)['levels']['social'], 'before the onset');
        $mid = RelDynImpulse::drives($d, $env(self::T0 + 39 * self::HOUR), $x)['levels']['social'];
        $full = RelDynImpulse::drives($d, $env(self::T0 + 4 * self::DAY), $x);
        $this->assertGreaterThan(0.0, $mid);
        $this->assertEqualsWithDelta(70.0, $full['levels']['social'], 1e-9, 'W .5: no gain');
        $this->assertSame('lonely', $full['sources']['social']);
        $this->assertSame(0.0, RelDynImpulse::drives($d, ['tier' => 0] + $env(self::T0 + 4 * self::DAY), $x)['levels']['social'],
            'a stranger does not miss the player');
        // A place both enjoy draws her to talk too
        $this->assertEqualsWithDelta(15.0, RelDynImpulse::drives($d, ['tier' => 2, 'place_valence' => 0.5], $x)['levels']['social'], 1e-9);

        // Lonely for days, then a meaningful exchange: the social impulse is met
        $d['_accumulated_play_gamets'] = 2000000.0;
        RelDynImpulse::update('Lynly Star-Sung', $d, ['now' => self::T0 + 4 * self::DAY, 'tier' => 2]);
        $this->assertEqualsWithDelta(70.0, $d[RelDynImpulse::KEY]['levels']['social'], 1e-9);
        $this->assertTrue(RelDynImpulse::noteMeaningful($d, 0.5, self::T0 + 4 * self::DAY + 1000));
        $this->assertSame(0.0, $d[RelDynImpulse::KEY]['levels']['social']);
        RelDynImpulse::update('Lynly Star-Sung', $d, ['now' => self::T0 + 4 * self::DAY + 2000, 'tier' => 2]);
        $this->assertSame(0.0, $d[RelDynImpulse::KEY]['levels']['social']);
    }

    public function testImpulseFallsWithAFivePlayMinuteHalfLifeAndStartsOverAfterASleep(): void
    {
        $d = self::npc('Bold', ['passion' => 80.0]);
        $play = 1000000.0;
        RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0, 'play' => $play, 'audience' => 0]);
        $this->assertSame(80.0, $d[RelDynImpulse::KEY]['levels']['romantic']);
        $s = $d[RelDynImpulse::KEY];
        $this->assertEqualsWithDelta(40.0, RelDynImpulse::decayed($s, $play + 5 * self::PLAY_MIN, self::T0 + self::HOUR)['romantic'], 1e-9);
        $this->assertEqualsWithDelta(20.0, RelDynImpulse::decayed($s, $play + 10 * self::PLAY_MIN, self::T0 + self::HOUR)['romantic'], 1e-9);
        // The player gone (no passion stimulus from her side: platonic now), five minutes of play
        $d['dimensions']['passion']['x'] = 0.0;
        RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0 + self::HOUR, 'play' => $play + 5 * self::PLAY_MIN]);
        $this->assertEqualsWithDelta(40.0, $d[RelDynImpulse::KEY]['levels']['romantic'], 1e-9);
        $this->assertSame('passion', $d[RelDynImpulse::KEY]['sources']['romantic'], 'what drove it last');
        // A night's sleep (no play, a long calendar gap): the short band starts over
        $this->assertSame(0.0, RelDynImpulse::decayed($d[RelDynImpulse::KEY], $play + 5 * self::PLAY_MIN, self::T0 + 10 * self::HOUR)['romantic']);
        // A load behind the stored calendar: starts over too
        $this->assertSame(0.0, RelDynImpulse::decayed($d[RelDynImpulse::KEY], $play + 5 * self::PLAY_MIN, self::T0 - self::HOUR)['romantic']);
        // A sustained source holds it
        $d['dimensions']['passion']['x'] = 80.0;
        RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0 + 2 * self::HOUR, 'play' => $play + 20 * self::PLAY_MIN, 'audience' => 0]);
        $this->assertSame(80.0, $d[RelDynImpulse::KEY]['levels']['romantic']);
    }

    public function testMotivationIsHerTopThreeGoalsAndTheDirectorGoalContends(): void
    {
        $d = self::npc('Guarded', [], [RelDynGoals::KEY => [
            self::goal('mastery', 0.5), self::goal('revenge', 0.8, ['source' => 'backstory', 'keywords' => ['Silver Hand']]),
            self::goal('bond_seeking', 0.45), self::goal('safety', 0.3),
        ]]);
        $m = RelDynImpulse::motivations($d);
        $this->assertSame(['revenge', 'mastery', 'bond_seeking'], array_column($m, 'type'), 'top three by priority');
        $this->assertSame('revenge', RelDynImpulse::dominantMotivation($d, null)['type']);
        $this->assertSame('revenge', RelDynImpulse::dominantMotivation($d, ['text' => 'Keep watch', 'priority' => 0.5])['type'], 'lighter');
        $this->assertSame('director', RelDynImpulse::dominantMotivation($d, ['text' => 'Keep watch', 'priority' => 0.9])['type'], 'heavier');
        $weak = self::npc('Guarded', [], [RelDynGoals::KEY => [self::goal('mastery', 0.3)]]);
        $this->assertNull(RelDynImpulse::dominantMotivation($weak, null), 'nothing weighs enough to pull against an urge');
    }

    public function testInnerConflictResolvesByTemperament(): void
    {
        $goals = [RelDynGoals::KEY => [self::goal('revenge', 0.8, ['source' => 'backstory', 'keywords' => ['Silver Hand']])]];
        $expect = ['Bold' => 'impulse', 'Playful' => 'impulse', 'Gentle' => 'impulse_soft', 'Romantic' => 'impulse_soft',
            'Guarded' => 'motivation', 'Stoic' => 'motivation', 'Anxious' => 'freeze', 'Proud' => 'dignity_motivation'];
        foreach ($expect as $preset => $resolution) {
            $d = self::npc($preset, ['passion' => 95.0], $goals);
            $s = RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0, 'tier' => 2, 'audience' => 0]);
            $this->assertSame('romantic', $s['top'], $preset);
            $this->assertSame('revenge', $s['conflict']['motivation'] ?? null, $preset);
            $this->assertSame($resolution, $s['conflict']['resolution'], $preset);
            $lines = RelDynImpulse::feltLines('Aela the Huntress', 'Kaida', $d);
            $this->assertCount(1, $lines, 'the conflict speaks for the impulse: one voice');
            $this->assertSame('inner_conflict', $lines[0]['tag']);
            $this->assertMatchesRegularExpression("/^Impulse: (a strong|an overwhelming) urge to be close to Kaida\nMotivation: an old score to settle tied to Silver Hand\nTemperament: \\S/",
                $lines[0]['text'], $preset);
            $this->assertSame('revenge', $lines[0]['motivation']);
            $this->assertDoesNotMatchRegularExpression('/\d/', $lines[0]['text']);
        }
        // An impulse the motivation agrees with is no conflict: bond-seeking and romance
        $d = self::npc('Guarded', ['passion' => 95.0], [RelDynGoals::KEY => [self::goal('bond_seeking', 0.9)]]);
        $this->assertNull(RelDynImpulse::update('Ashe', $d, ['now' => self::T0, 'audience' => 0])['conflict']);
        $this->assertSame('impulse', RelDynImpulse::feltLines('Ashe', 'Kaida', $d)[0]['key']);
        // Proud: whichever keeps her dignity (MDD 13.3)
        $this->assertSame('dignity_motivation', RelDynImpulse::resolve('proud', 'romantic', 'purpose'));
        $this->assertSame('dignity_impulse', RelDynImpulse::resolve('proud', 'curiosity', 'safety'));
        $this->assertSame('dignity_neither', RelDynImpulse::resolve('proud', 'social', 'safety'), 'both would look weak');
        // The director goal is the motivation in charge when it weighs more (MDD 14.4)
        $d = self::npc('Stoic', ['passion' => 95.0]);
        $s = RelDynImpulse::update('Ashe', $d, ['now' => self::T0, 'audience' => 0, 'goal' => ['text' => 'Search the ruin for the lexicon.', 'priority' => 0.8]]);
        $this->assertSame('director', $s['conflict']['motivation']);
        $this->assertStringContainsString('Motivation: what Ashe is set on right now: Search the ruin for the lexicon' . "\n",
            RelDynImpulse::feltLines('Ashe', 'Kaida', $d)[0]['text']);
    }

    public function testTheConflictRendersAsItsOwnBlockInsideSubtextAndJevGetsTheNumbers(): void
    {
        $d = self::npc('Guarded', ['passion' => 90.0], [RelDynGoals::KEY => [self::goal('purpose', 0.7, ['keywords' => ['Aryo'], 'facets' => ['scholarly' => 1.0]])]]);
        RelDynImpulse::update('Ashe', $d, ['now' => self::T0, 'tier' => 2, 'audience' => 1]);
        $l = RelDynImpulse::feltLines('Ashe', 'Kaida', $d)[0];
        $block = RelDynFelt::renderSubtext('Ashe', 'Kaida', [
            ['key' => 'trust', 'scope' => 'bond', 'lane' => 'core', 'salience' => 0.5, 'must' => false, 'intense' => false,
             'handwritten' => false, 'tier0' => false, 'tag' => null, 'text' => 'Ashe keeps a little distance'],
            ['key' => $l['key'], 'scope' => $l['scope'], 'lane' => 'turn', 'salience' => $l['salience'], 'must' => false, 'intense' => false,
             'handwritten' => false, 'tier0' => false, 'tag' => $l['tag'], 'text' => $l['text']],
        ]);
        $this->assertMatchesRegularExpression("/^<subtext>\n.*\n- Ashe keeps a little distance\n<inner_conflict>\nImpulse: .*\nMotivation: a purpose of Ashe's own tied to Aryo\nTemperament: Ashe stays the course and holds the urge down, but it leaks through in small ways\n<\\/inner_conflict>\n<\\/subtext>$/s", $block);

        $jev = RelDynImpulse::jev($d);
        $this->assertSame('romantic', $jev['top']);
        $this->assertEqualsWithDelta(90.0 / 1.25, $jev['levels']['romantic'], 0.01, 'one other person around');
        $this->assertEqualsWithDelta(69.45, $jev['threshold'], 0.01);
        $this->assertSame('guarded', $jev['style']);
        $this->assertFalse($jev['false_start']);
        $this->assertSame(['type' => 'purpose', 'weight' => 0.7], $jev['motivations'][0]);
        $this->assertSame(['impulse' => 'romantic', 'motivation' => 'purpose', 'weight' => 0.7, 'alignment' => -1.0, 'resolution' => 'motivation'], $jev['conflict']);
        // Five minutes of play later Jev reads the decayed levels (below her threshold: nothing fires)
        $d['_accumulated_play_gamets'] += 5 * self::PLAY_MIN;
        $later = RelDynImpulse::jev($d);
        $this->assertEqualsWithDelta(36.0, $later['levels']['romantic'], 0.01);
        $this->assertNull($later['top']);
        $this->assertNull($later['conflict']);
        $text = RelDynJev::render(['impulse' => $jev] + self::jevSkeleton());
        $this->assertMatchesRegularExpression('/impulse=romantic 72\/at 69\.\d\(guarded\) from=passion/', $text);
        $this->assertStringContainsString('inner_conflict=romantic vs purpose(0.70) -> motivation', $text);
    }

    public function testALoadStartsTheShortBandOverAndClampsItsStamps(): void
    {
        $d = self::npc('Bold', ['passion' => 80.0]);
        RelDynImpulse::noteMeaningful($d, 1.0, self::T0 + self::HOUR);
        RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0 + 2 * self::HOUR, 'audience' => 0, 'place' => 'Jorrvaskr',
            'facets' => ['social' => 1.0], 'prefs' => []]);
        $this->assertArrayHasKey('jorrvaskr', $d[RelDynImpulse::KEY]['seen']);
        $ns = RelDynTimeline::rebaselineDynamics($d, self::T0, null);
        $s = $ns[RelDynImpulse::KEY];
        $this->assertArrayNotHasKey('levels', $s);
        $this->assertEquals(self::T0, $s['gamets']);
        $this->assertEquals(self::T0, $s['last_meaningful_gamets']);
        $this->assertSame([], $s['seen'], 'the loaded game never saw it');
    }

    public function testTheLayerCanBeSwitchedOff(): void
    {
        $GLOBALS['db'] = new class {
            public function fetchOne($q, array $p = []) {
                return ['value' => json_encode(['impulse' => ['enabled' => false], 'config_schema' => RelationshipDynamics::CONFIG_SCHEMA])];
            }
            public function fetchAll($q, $log = false) { return []; }
            public function escape($s) { return (string) $s; }
        };
        RelationshipDynamics::clearConfigCache();
        $d = self::npc('Bold', ['passion' => 80.0], [RelDynImpulse::KEY => ['levels' => ['romantic' => 50.0], 'threshold' => 30.0]]);
        $this->assertNull(RelDynImpulse::update('Aela the Huntress', $d, ['now' => self::T0]));
        $this->assertArrayNotHasKey(RelDynImpulse::KEY, $d);
        $this->assertSame([], RelDynImpulse::feltLines('Aela the Huntress', 'Kaida', $d));
        $this->assertFalse(RelDynImpulse::noteMeaningful($d, 1.0, self::T0));
    }

    /** The fields RelDynJev::render reads besides the impulse (neutral values). */
    private static function jevSkeleton(): array
    {
        return [
            'npc' => 'Ashe', 'affinity' => 0.0, 'affinity_tier' => 'stranger', 'relationship_type' => 'neutral',
            'trust' => 50.0, 'comfort' => 50.0, 'respect' => 50.0, 'warmth' => 50.0, 'maturity' => 50.0, 'passion' => 0.0,
            'jealousy' => 0.0, 'jealousy_rival' => null, 'resentment' => 0.0, 'arousal' => 10.0, 'valence' => 0.0,
            'attachment' => 'secure', 'attachment_anxiety' => 0.2, 'attachment_avoidance' => 0.2, 'temperament' => null, 'traits' => null,
            'weather' => 'clear', 'open_conflict' => false, 'boundary' => 'none',
            'concern' => ['level' => 0.0, 'band' => 'none', 'possessive_incidents' => 0, 'protective_incidents' => 0, 'pattern' => [], 'values_boundary' => 'none'],
            'walkaway' => 'normal', 'resentment_self' => 0.0,
            'resentment_arc' => ['self_baseline_offsets' => [], 'self_crisis' => false, 'confrontations' => 0, 'confrontation_threshold' => 50.0,
                'confrontation_pending' => null, 'guilt_bleed' => 0.0, 'reject_recruitment' => false],
            'fulfillment' => ['band' => 0.0, 'low' => false], 'exclusivity' => null,
            'attraction' => ['enabled' => false], 'place' => null, 'creature' => null, 'protocols' => null, 'goal' => null,
            'autonomy' => null, 'intrinsic_goals' => [], 'reputation' => null, 'duty' => null,
        ];
    }
}
