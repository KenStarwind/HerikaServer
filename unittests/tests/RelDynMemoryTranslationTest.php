<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * memory-translation-layer (MDD §12, pipeline Addendum 12), the pure parts: the wrapper that turns
 * her numbers into words before a memory is committed (translate / clauses), and the anchors'
 * revisit turn (contextTurn) given core's place. No database: config is the defaults, the place
 * is passed in. The Postgres tests cover the writes and the hooks.
 */
final class RelDynMemoryTranslationTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'gameRequest'] as $k) {
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

    /** A bond's state: dimension points, core affinity through the mirror (x = (aff + 100) / 2). */
    private static function state(array $dims, float $aff = 0.0, array $extra = []): array
    {
        $d = ['dimensions' => [], '_aff_mirror_x' => ($aff + 100) / 2];
        foreach ($dims as $k => $x) $d['dimensions'][$k] = ['x' => $x, 'baseline' => $x];
        $d['dimensions']['affinity'] = ['x' => ($aff + 100) / 2, 'baseline' => 50];
        return $d + $extra;
    }

    public function testTheMddExampleDutyResentmentAndDistanceBecomeWords(): void
    {
        // Aela dealing with the player she resents, because the quest makes her: MDD §12's example
        $d = self::state(['resentment' => 72, 'comfort' => 20, 'arousal' => 20, 'valence' => -10, 'coord_m' => 40, 'coord_f' => -30], -45.0);
        $note = RelDynMemory::translate($d, 'Aela the Huntress', 'Kaida', 'Kaida asked for work; she named the bandit camp at Halted Stream.', ['duty' => true]);
        $this->assertIsString($note);
        $this->assertStringStartsWith('(Beneath this moment with Kaida, Aela the Huntress was operating strictly out of begrudging duty', $note);
        $this->assertStringContainsString('harbouring a deep, unresolved resentment toward Kaida', $note);
        $this->assertStringContainsString('keeping an icy, transactional distance', $note);
        $this->assertStringContainsString('What happened: Kaida asked for work; she named the bandit camp at Halted Stream.)', $note);
        $this->assertDoesNotMatchRegularExpression('/\d/', $note, 'words, never numbers');
    }

    public function testClausesAreTheMostSalientWithinCountAndTokenBudget(): void
    {
        $d = self::state(['resentment' => 80, 'arousal' => 80, 'valence' => -60, 'coord_m' => -50, 'coord_f' => -50], -60.0,
            ['in_conflict' => true, 'jealousy_anger' => 75]);
        $c = RelDynMemory::clauses($d, 'Muiri', 'Kaida', ['duty' => true]);
        $this->assertSame(['duty', 'conflict', 'resentment'], array_keys($c), 'duty, conflict, resentment outrank the rest');
        $this->assertCount(3, $c);
        $tokens = array_sum(array_map([RelDynFelt::class, 'estimateTokens'], array_slice(array_values($c), 1)));
        $this->assertLessThanOrEqual(RelDynMemory::configDefaults()['translation']['token_budget'], $tokens);
    }

    public function testANeutralMomentWithNoEventSaysNothing(): void
    {
        $d = self::state(['resentment' => 5, 'arousal' => 20, 'valence' => 5, 'coord_m' => 5, 'coord_f' => 5], 10.0);
        $c = RelDynMemory::clauses($d, 'Lynly Star-Sung', 'Kaida');
        // the attachment clause is the only one a neutral moment may carry (secure: none)
        $style = RelationshipDynamics::getAttachmentStyle($d);
        $expect = isset(RelDynMemory::configDefaults()['text']['clauses']['attachment'][$style]) ? ['attachment'] : [];
        $this->assertSame($expect, array_keys($c), "style {$style}");
        if ($expect === []) $this->assertNull(RelDynMemory::translate($d, 'Lynly Star-Sung', 'Kaida', null));
    }

    public function testArousalValenceAndMfPickTheirOwnWords(): void
    {
        $warm = self::state(['arousal' => 30, 'valence' => 40, 'coord_m' => 30, 'coord_f' => 40], 70.0);
        // derived warmth sqrt(passion x comfort) through the per-bond display: open, well past the line
        $warm['dimensions']['comfort'] = ['x' => 100, 'baseline' => 100];
        $warm['dimensions']['passion'] = ['x' => 100, 'baseline' => 0];
        $this->assertGreaterThanOrEqual(60.0, RelDynPassion::warmth($warm));
        $c = RelDynMemory::clauses($warm, 'Ashe', 'Kaida');
        $this->assertSame('at ease', $c['arousal_valence'] ?? null);
        $this->assertSame('openly fond of Kaida', $c['affection'] ?? null);
        $this->assertArrayNotHasKey('distance', $c);
        $panic = self::state(['arousal' => 90, 'valence' => -70, 'coord_m' => -40, 'coord_f' => 30], 0.0);
        $c2 = RelDynMemory::clauses($panic, 'Muiri', 'Kaida');
        $this->assertSame('tense and on edge', $c2['arousal_valence'] ?? null);
    }

    public function testAnEventsNumbersNeverReachTheWrapper(): void
    {
        $d = self::state(['resentment' => 60], 0.0);
        $note = RelDynMemory::translate($d, 'Muiri', 'Kaida', 'Trust -5 (lie). The player lied about the 300 gold; she caught it.');
        $this->assertDoesNotMatchRegularExpression('/\d/', (string) $note);
        $this->assertStringContainsString('she caught it', (string) $note);
    }

    // ------------------------------------------------------------------ revisits

    private static function ctx(string $name): array
    {
        return ['name' => $name, 'raw_name' => $name, 'hold' => 'Whiterun', 'known' => true];
    }

    private static function withAnchors(array $kinds, string $place, array $extra = []): array
    {
        $d = self::state([], 40.0, $extra);
        foreach ($kinds as $k) $d[RelDynMemory::ANCHORS_KEY][$k] = ['gamets' => 1000, 'place' => ['name' => $place, 'hold' => 'Whiterun'], 'event' => null, 'subtext' => null];
        return $d;
    }

    public function testARevisitNeedsAnArrivalAndSpeaksForItsTurns(): void
    {
        $d = self::withAnchors(['first_combat', 'first_meeting'], 'Bleak Falls Barrow');
        $now = 5.0 * RelationshipDynamics::GAMETS_PER_DAY;
        // First read of a place: no before, so no arrival (she may have been here all along)
        $r = RelDynMemory::contextTurn('Aela the Huntress', 'Kaida', $d, self::ctx('Bleak Falls Barrow'), $now, 2, false, true);
        $this->assertNull($r['text']);
        $this->assertTrue($r['changed']);
        $this->assertArrayNotHasKey(RelDynMemory::VISIT_KEY, $d);
        // Elsewhere, then back: an arrival
        RelDynMemory::contextTurn('Aela the Huntress', 'Kaida', $d, self::ctx('Riverwood'), $now + 100, 2, false, true);
        $r = RelDynMemory::contextTurn('Aela the Huntress', 'Kaida', $d, self::ctx('Bleak Falls Barrow'), $now + 200, 2, false, true);
        $this->assertSame('This place brings it back to Aela the Huntress: the first meeting with Kaida and the first fight side by side with Kaida, right here.', $r['text']);
        $this->assertSame(0.0, $r['spike'], 'no passion floor: no moment (spike min floor)');
        // It holds for its turns (three player turns), then it is spent
        $texts = [];
        for ($i = 0; $i < 3; $i++) $texts[] = RelDynMemory::contextTurn('Aela the Huntress', 'Kaida', $d, self::ctx('Bleak Falls Barrow'), $now + 300 + $i, 2, false, true)['text'];
        $this->assertSame([true, true, false], array_map(fn($t) => $t !== null, $texts), 'three turns, the arrival turn included');
        $this->assertArrayNotHasKey(RelDynMemory::VISIT_KEY, $d);
    }

    public function testTheStrainedBondRemembersItAsAnAche(): void
    {
        $d = self::withAnchors(['partners'], 'Breezehome', [RelDynMemory::PLACE_KEY => 'the bannered mare']);
        $r = RelDynMemory::contextTurn('Muiri', 'Kaida', $d, self::ctx('Breezehome'), 1e8, 3, true, true);
        $this->assertSame('This place brings it back to Muiri, and now it aches: the day Muiri and Kaida became partners, right here.', $r['text']);
    }

    public function testAStrangerTierOpensTheVisitButSaysNothingAndItExpires(): void
    {
        $d = self::withAnchors(['first_meeting'], 'Riverwood', [RelDynMemory::PLACE_KEY => 'whiterun']);
        $t0 = 3.0 * RelationshipDynamics::GAMETS_PER_DAY;
        $r = RelDynMemory::contextTurn('Lynly Star-Sung', 'this stranger', $d, self::ctx('Riverwood'), $t0, 0, false, false);
        $this->assertNull($r['text'], 'below min_tier: no line');
        $this->assertIsArray($d[RelDynMemory::VISIT_KEY] ?? null);
        // Past max_game_hours the visit is over, whatever the tier
        $late = $t0 + 7 * RelationshipDynamics::GAMETS_PER_DAY / 24;
        $r = RelDynMemory::contextTurn('Lynly Star-Sung', 'Kaida', $d, self::ctx('Riverwood'), $late, 2, false, true);
        $this->assertNull($r['text']);
        $this->assertArrayNotHasKey(RelDynMemory::VISIT_KEY, $d);
    }

    public function testAnchorsAreDescribedForThePageAndKindsAreConfig(): void
    {
        $d = self::withAnchors(['first_gift'], 'Breezehome');
        $this->assertSame([['kind' => 'first_gift', 'gamets' => 1000.0, 'place' => 'Breezehome', 'hold' => 'Whiterun', 'event' => null, 'subtext' => null]],
            RelDynMemory::describe($d));
        $this->assertSame(RelDynMemory::KINDS, RelationshipDynamics::defaultConfig()['memory_translation']['anchors']['kinds']);
        $this->assertFalse(RelationshipDynamics::defaultConfig()['memory_translation']['commit']['enabled'], 'core memory writes are opt-in');
        $this->assertSame(0.0, RelationshipDynamics::defaultConfig()['memory_translation']['anchors']['maturity_per_new_kind'], 'no guessed maturity size');
    }
}
