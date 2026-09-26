<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynPassionSpikeConfigDb
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
    public function execQuery($sql) { return true; }
}

/**
 * Passion floor + spike (roadmap passion-floor-spike; memory feedback_passion_spikes, session
 * 2026-03-30): the floor is the passion earned through play (the stored passion, every writer it
 * had); the spike is the moment on top of it:
 *   - event-driven triggers (primary love language +8, secondary +5, flirty mood +6, touch +10,
 *     topic +3, rescue +7, the eval's passion signal) that bypass the multiplicative suppressors;
 *   - none on a floor below 10 (no racing heart for a stranger); temperament scales it (the trait
 *     engine's passion_mult: Guarded 0.6), a higher floor makes it bigger, arousal amplifies it;
 *   - 55% kept per interaction: gone in about five exchanges; faded by absence (decisions §2);
 *   - every spike a passion gain through gainPassion: the attraction factor (a hard zero adds
 *     nothing), no spike while the Ick lasts;
 *   - effective passion = floor + spike: what display reads; the affinity drive keeps the floor.
 * Units: passion / spike points 0..100, arousal points 0..100.
 */
final class RelDynPassionSpikeTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = new RelDynPassionSpikeConfigDb(RelationshipDynamics::defaultConfig());
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC of $temperament with passion floor $floor, arousal $arousal, an open attraction (spark 20, x1). */
    private function npc(string $temperament, float $floor, float $arousal = 10.0, array $attraction = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $temperament,
            'love_language_primary' => RelationshipDynamics::LL_TOUCH,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
        ]));
        RelationshipDynamics::setPassion($d, $floor);
        $d['dimensions']['arousal']['x'] = $arousal;
        $d['_attraction'] = array_merge(['enabled' => true, 'spark' => 20.0, 'spark_mult' => 1.0, 'passion_mult' => 1.0], $attraction);
        return $d;
    }

    public function testASpikeNeedsAFloorAndScalesByWhoSheIs(): void
    {
        // A stranger (floor below 10) has no racing heart, whatever happens
        $stranger = $this->npc('Romantic', 9.9);
        $this->assertSame(0.0, RelDynPassion::spikeSize($stranger, 10.0));
        $this->assertSame(0.0, RelDynPassion::addTrigger('Tester', $stranger, 'touch'));
        $this->assertSame(0.0, RelDynPassion::spike($stranger));

        // At the reference floor (40) a Guarded NPC's touch spike is 10 x 0.6 (feedback_passion_spikes)
        $guarded = $this->npc('Guarded', 40.0);
        $this->assertEqualsWithDelta(6.0, RelDynPassion::spikeSize($guarded, 10.0), 1e-9);
        $romantic = $this->npc('Romantic', 40.0);
        $this->assertEqualsWithDelta(13.0, RelDynPassion::spikeSize($romantic, 10.0), 1e-9);
        // A higher floor makes a bigger spike (floor / 40, at most 1.5)
        $this->assertEqualsWithDelta(3.0, RelDynPassion::spikeSize($this->npc('Guarded', 20.0), 10.0), 1e-9);
        $this->assertEqualsWithDelta(9.0, RelDynPassion::spikeSize($this->npc('Guarded', 90.0), 10.0), 1e-9);
        // Arousal amplifies: 1.0x up to arousal 15, 1.8x at 100 (the desire loop's cross-feed)
        $this->assertEqualsWithDelta(1.0, RelDynPassion::arousalAmp($this->npc('Guarded', 40.0, 15.0)), 1e-9);
        $this->assertEqualsWithDelta(1.4, RelDynPassion::arousalAmp($this->npc('Guarded', 40.0, 57.5)), 1e-9);
        $this->assertEqualsWithDelta(1.8, RelDynPassion::arousalAmp($this->npc('Guarded', 40.0, 100.0)), 1e-9);
        $this->assertEqualsWithDelta(6.0 * 1.8, RelDynPassion::spikeSize($this->npc('Guarded', 40.0, 100.0), 10.0), 1e-9);
    }

    public function testTheSpikeIsItsOwnPoolAndTheFloorStays(): void
    {
        $d = $this->npc('Romantic', 40.0);
        $added = RelDynPassion::addTrigger('Tester', $d, 'love_language_primary', ['touch']);
        $this->assertEqualsWithDelta(8.0 * 1.3, $added, 1e-9, 'bypasses the session / stage / love-language suppressors');
        $this->assertSame(40.0, RelationshipDynamics::getPassion($d), 'the floor is not touched');
        $this->assertEqualsWithDelta(40.0 + $added, RelationshipDynamics::getEffectivePassion($d), 1e-9);
        // The display reads the effective passion (per bond: acquaintance x sqrt(0.5) at core affinity 0)
        RelationshipDynamics::setCoreRelationshipType($d, 'professional');
        $this->assertEqualsWithDelta((40.0 + $added) * sqrt(0.5),
            RelationshipDynamics::getEffectiveDimensionValue($d, 'passion'), 1e-6);
        // The affinity drive (MDD 1.1 RPM) stays on the floor
        $this->assertEqualsWithDelta(0.3 + 0.4 * 1.7, RelationshipDynamics::getAffinityGainMultiplier($d), 1e-9);

        // Capped: spike.max (40), and floor + spike never past passion_max (100)
        for ($i = 0; $i < 10; $i++) RelDynPassion::addTrigger('Tester', $d, 'touch', ['touch']);
        $this->assertEqualsWithDelta(40.0, RelDynPassion::spike($d), 1e-9);
        $high = $this->npc('Romantic', 80.0);
        for ($i = 0; $i < 10; $i++) RelDynPassion::addTrigger('Tester', $high, 'touch', ['touch']);
        $this->assertEqualsWithDelta(20.0, RelDynPassion::spike($high), 1e-9);
        $this->assertEqualsWithDelta(100.0, RelationshipDynamics::getEffectivePassion($high), 1e-9);
    }

    public function testEverySpikeGoesThroughTheAttraction(): void
    {
        // A hard zero (orientation): the moment stirs nothing
        $zero = $this->npc('Romantic', 40.0, 10.0, ['spark_mult' => 0.0, 'passion_mult' => 0.0, 'hard_zero' => 'orientation']);
        $this->assertSame(0.0, RelDynPassion::addTrigger('Tester', $zero, 'touch', ['touch']));
        $this->assertSame(0.0, RelDynPassion::spike($zero));
        // The uphill (above the spark at x0.25): the factor is read at the effective passion
        $hill = $this->npc('Romantic', 40.0, 10.0, ['passion_mult' => 0.25]);
        $this->assertEqualsWithDelta(13.0 * 0.25, RelDynPassion::addTrigger('Tester', $hill, 'touch', ['touch']), 1e-9);
        // The MDD 1.4 ceiling bounds floor + spike
        $ceiling = $this->npc('Romantic', 40.0, 10.0, ['passion_ceiling' => 45.0]);
        $this->assertEqualsWithDelta(5.0, RelDynPassion::addTrigger('Tester', $ceiling, 'touch', ['touch']), 1e-9);
        $this->assertSame(0.0, RelDynPassion::addTrigger('Tester', $ceiling, 'touch', ['touch']), 'at the ceiling');
        // An asexual NPC (emotional channels only): a touch is no channel, a word is
        $ace = $this->npc('Romantic', 40.0, 10.0, ['passion_channels' => ['praise', 'quality_time'], 'passion_channel' => 'emotional']);
        $this->assertSame(0.0, RelDynPassion::addTrigger('Tester', $ace, 'touch', ['touch']));
        $this->assertGreaterThan(0.0, RelDynPassion::addTrigger('Tester', $ace, 'love_language_secondary', ['praise']));
        // The Ick: no racing heart while it lasts
        $ick = $this->npc('Romantic', 40.0);
        $ick['_ick_tracker'] = ['ick_active' => true];
        $this->assertSame(0.0, RelDynPassion::addTrigger('Tester', $ick, 'flirty_mood'));
    }

    public function testTheMomentFadesInAboutFiveExchanges(): void
    {
        $d = $this->npc('Romantic', 40.0);
        RelDynPassion::storeSpike($d, 10.0, 'touch');
        $seen = [];
        for ($i = 1; $i <= 6; $i++) $seen[$i] = RelDynPassion::decayInteraction($d);
        $this->assertEqualsWithDelta(5.5, $seen[1], 1e-9);
        $this->assertEqualsWithDelta(10.0 * 0.55 ** 5, $seen[5], 1e-3);
        $this->assertLessThan(0.6, $seen[5], 'about gone after five exchanges');
        $this->assertSame(40.0, RelationshipDynamics::getPassion($d), 'the floor stays');
        for ($i = 0; $i < 10; $i++) RelDynPassion::decayInteraction($d);
        $this->assertArrayNotHasKey(RelDynPassion::SPIKE_KEY, $d, 'gone');
    }

    public function testAbsenceTakesTheMoment(): void
    {
        $day = RelationshipDynamics::GAMETS_PER_DAY;
        $mk = function (string $style) use ($day): array {
            $d = $this->npc('Romantic', 40.0);
            RelDynPassion::storeSpike($d, 20.0, 'touch');
            $d['_last_contact_gamets'] = 100 * $day;
            $d['profile_overrides']['attachment_style'] = $style;
            return $d;
        };
        $mult = fn(array $d) => RelationshipDynamics::attachmentBlend($d, (array) RelationshipDynamics::configValue('passion_absence_attachment_mult'));
        // Within the passion absence grace (24 game hours) the moment waits
        $d = $mk('anxious');
        $r = RelationshipDynamics::advanceCalendar($d, 100 * $day, 100.5 * $day);
        $this->assertSame(0.0, $r['spike_fade']);
        $this->assertEqualsWithDelta(20.0, RelDynPassion::spike($d), 1e-9);
        // Past it (a quarter day): 40 spike points a day x the attachment multiplier; the anxious
        // lose the moment faster than the avoidant (decisions §2: Anxious x2, Avoidant x0.5)
        $anx = $mk('anxious');
        $r = RelationshipDynamics::advanceCalendar($anx, 100 * $day, 101.25 * $day);
        $this->assertGreaterThan(1.5, $mult($anx));
        $this->assertEqualsWithDelta(min(20.0, 10.0 * $mult($anx)), $r['spike_fade'], 1e-6);
        $avo = $mk('avoidant');
        $r = RelationshipDynamics::advanceCalendar($avo, 100 * $day, 101.25 * $day);
        $this->assertLessThan(0.75, $mult($avo));
        $this->assertEqualsWithDelta(10.0 * $mult($avo), $r['spike_fade'], 1e-6);
        $this->assertGreaterThan(RelDynPassion::spike($anx), RelDynPassion::spike($avo), 'the avoidant keep the moment longer');
    }

    public function testObservedTriggersSpikeWhoeverScoresTheExchange(): void
    {
        // Eval scores the exchange: only what was observed (flirty reply, a topic, reported intimacy)
        $d = $this->npc('Romantic', 40.0);
        $out = RelDynPassion::onExchange('Tester', $d, RelationshipDynamics::LL_TOUCH, 'Flirty', true, false, true);
        $this->assertSame(['flirty_mood', 'topic_match'], array_keys($out));
        $this->assertEqualsWithDelta((6.0 + 3.0) * 1.3, RelDynPassion::spike($d), 1e-9);
        // No eval: the classifier's love language (primary here) and the touch
        $d = $this->npc('Romantic', 40.0);
        $out = RelDynPassion::onExchange('Tester', $d, RelationshipDynamics::LL_TOUCH, 'default', false, false, false);
        $this->assertSame(['love_language_primary', 'touch'], array_keys($out));
        $d = $this->npc('Romantic', 40.0);
        $out = RelDynPassion::onExchange('Tester', $d, RelationshipDynamics::LL_WORDS, null, false, false, false);
        $this->assertSame(['love_language_secondary'], array_keys($out));
        // Intimacy the plugin reports is one touch, not two
        $d = $this->npc('Romantic', 40.0);
        $out = RelDynPassion::onExchange('Tester', $d, RelationshipDynamics::LL_TOUCH, null, false, true, false);
        $this->assertSame(['touch', 'love_language_primary'], array_keys($out));
    }

    public function testTheEvalItemsMoment(): void
    {
        $d = $this->npc('Romantic', 40.0);
        $n = ['tags' => ['praise', 'rescue'], 'signals' => ['passion' => 4.0], 'reported_intimacy' => null];
        $out = RelDynPassion::onEvalItem('Tester', $n, $d);
        // praise = words (her secondary), a rescue, and the passion signal x 1.0
        $this->assertEqualsWithDelta(5.0 * 1.3, $out['love_language_secondary'], 1e-9);
        $this->assertEqualsWithDelta(7.0 * 1.3, $out['rescue'], 1e-9);
        $this->assertEqualsWithDelta(4.0 * 1.3, $out['eval'], 1e-9);
        $this->assertArrayNotHasKey('touch', $out);
        // A touch the game reported was spiked by the postrequest already
        $d = $this->npc('Romantic', 40.0);
        $out = RelDynPassion::onEvalItem('Tester', ['tags' => ['touch'], 'signals' => ['passion' => -2.0], 'reported_intimacy' => 'hug'], $d);
        $this->assertSame(['love_language_primary'], array_keys($out), 'touch is her primary language; no double touch, no negative spike');
    }
}
