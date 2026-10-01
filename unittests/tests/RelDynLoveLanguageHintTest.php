<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * Love-language discovery through feedback (roadmap ll-discovery-hints; pipeline Blocks 3 and 7):
 * the player is never told an NPC's love language, only shown how a gesture landed.
 *   blush   the passion moment of the last exchange, one-shot. The BAND is the delta alone
 *           (2 faint, 4 mild, 7 strong); the love language the gesture matched scales it (primary:
 *           brighter, held one more turn; secondary: a little brighter) and never gates it.
 *   hint    how the last gesture landed (primary / secondary / a polite miss), one-shot, persisted
 *           by the request that classified it (core runs the context hook before ext postrequest, so
 *           a global set there is never there when the context composes), spent on the player's
 *           next word, and gone when too much game time passed.
 * No database: config is the defaults. The whole pipeline (prerequest -> context -> postrequest ->
 * worker, four test beds) is RelDynFeltLaneTestBedsPostgresTest.
 */
final class RelDynLoveLanguageHintTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 400 * self::DAY;

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

    /** A bonded NPC whose primary love language is touch and secondary quality time. */
    private static function npc(array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => 'Bold', 'stage' => 'early', 'dimensions' => [],
            'love_language_primary' => RelationshipDynamics::LL_TOUCH, 'love_language_secondary' => RelationshipDynamics::LL_TIME,
            'profile_overrides' => ['attachment_axes' => ['anxiety' => 0.2, 'avoidance' => 0.2]]];
        foreach (['maturity' => 55.0, 'self_confidence' => 55.0, 'trust' => 50.0, 'respect' => 50.0, 'comfort' => 50.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    /** One compose with the player speaking: the line texts by key. */
    private static function lines(array &$d, array $env = ['player_addressed' => true], float $now = self::T0): array
    {
        $c = RelDynFelt::compose('Aela the Huntress', 'Kaida', $d, $now, $env);
        return array_column($c['lines'], 'text', 'key');
    }

    private static function text(string $key, string $sub): string
    {
        return str_replace('{NAME}', 'Aela the Huntress', (string) RelDynFelt::config()['text'][$key][$sub]);
    }

    // ------------------------------------------------------------------ the blush

    public function testTheBlushBandIsTheDeltaAndTheMatchScalesItNeverGatesIt(): void
    {
        // delta => [mult => band]; the old reading gated mild and strong on mult >= 1.5, so a
        // non-matching 5 or 8 only ever got 'faint'
        $cases = [[1.9, null], [2.0, 'faint'], [3.9, 'faint'], [4.0, 'mild'], [6.9, 'mild'], [7.0, 'strong'], [12.0, 'strong']];
        foreach ($cases as [$delta, $band]) {
            foreach ([1.0, 1.5, 2.0] as $mult) {
                $d = self::npc(['_last_passion_delta' => $delta, 'pending_blush_mult' => $mult]);
                $lines = self::lines($d);
                if ($band === null) {
                    $this->assertArrayNotHasKey('blush', $lines, "delta {$delta} x{$mult}");
                    $this->assertEqualsWithDelta($delta, floatval($d['_last_passion_delta']), 1e-9, 'a delta under the band is not spent');
                    continue;
                }
                $this->assertSame(self::text('blush', $band), $lines['blush'], "delta {$delta} x{$mult}");
                $this->assertEquals(0.0, floatval($d['_last_passion_delta']), 'one-shot');
                $this->assertEquals(1.0, floatval($d['pending_blush_mult']), 'one-shot');
                $this->assertDoesNotMatchRegularExpression('/\d/', $lines['blush']);
            }
        }
    }

    public function testThePrimaryMatchBrightensAndSecondaryLightlyBrightensTheSameBand(): void
    {
        $sal = [];
        $intense = [];
        foreach (['none' => 1.0, 'secondary' => 1.5, 'primary' => 2.0] as $label => $mult) {
            $d = self::npc(['_last_passion_delta' => 5.0, 'pending_blush_mult' => $mult]);
            $c = RelDynFelt::compose('Aela the Huntress', 'Kaida', $d, self::T0, ['player_addressed' => true]);
            $line = array_values(array_filter($c['lines'], fn($l) => $l['key'] === 'blush'))[0];
            $sal[$label] = $line['salience'];
            $intense[$label] = $line['intense'];
        }
        $this->assertLessThan($sal['secondary'], $sal['none']);
        $this->assertLessThan($sal['primary'], $sal['secondary']);
        $this->assertSame(['none' => false, 'secondary' => false, 'primary' => true], $intense, 'a primary match is the one that burns');
    }

    public function testAPrimaryMatchHoldsTheBlushOneMoreTurnAndOnlyForThePlayer(): void
    {
        $d = self::npc(['_last_passion_delta' => 5.0, 'pending_blush_mult' => 2.0]);
        $first = self::lines($d);
        $this->assertSame(self::text('blush', 'mild'), $first['blush']);
        $this->assertNotEmpty($d[RelDynFelt::BLUSH_HOLD_KEY] ?? null, 'the flush stays a turn');

        // her own remark (the player is not speaking): the hold waits
        $quiet = self::lines($d, ['player_addressed' => false]);
        $this->assertArrayNotHasKey('blush_hold', $quiet);
        $this->assertNotEmpty($d[RelDynFelt::BLUSH_HOLD_KEY] ?? null);

        $second = self::lines($d);
        $this->assertArrayNotHasKey('blush', $second);
        $this->assertSame(str_replace('{NAME}', 'Aela the Huntress', (string) RelDynFelt::config()['text']['blush_hold']), $second['blush_hold']);
        $this->assertDoesNotMatchRegularExpression('/\d/', $second['blush_hold']);
        $this->assertEmpty($d[RelDynFelt::BLUSH_HOLD_KEY] ?? null);

        $third = self::lines($d);
        $this->assertArrayNotHasKey('blush_hold', $third);
        $this->assertArrayNotHasKey('blush', $third);

        // a secondary or no match holds nothing
        foreach ([1.0, 1.5] as $mult) {
            $e = self::npc(['_last_passion_delta' => 8.0, 'pending_blush_mult' => $mult]);
            self::lines($e);
            $this->assertEmpty($e[RelDynFelt::BLUSH_HOLD_KEY] ?? null, "x{$mult}");
            $this->assertArrayNotHasKey('blush_hold', self::lines($e));
        }
    }

    // ------------------------------------------------------------------ the hint

    public function testTheHintIsHowTheLastGestureLandedOnceAndOnTheNextWord(): void
    {
        $expect = [
            RelationshipDynamics::LL_TOUCH => self::text('ll_reaction', RelationshipDynamics::LL_TOUCH),
            RelationshipDynamics::LL_TIME  => self::text('ll_reaction', 'secondary'),
            RelationshipDynamics::LL_GIFTS => self::text('ll_reaction', 'miss'),
            RelationshipDynamics::LL_WORDS => self::text('ll_reaction', 'miss'),
        ];
        foreach ($expect as $ll => $text) {
            $d = self::npc(['_last_interaction_ll' => $ll, '_last_interaction_ll_gamets' => self::T0 - self::HOUR]);
            // the NPC's own remark does not spend it
            $this->assertArrayNotHasKey('ll_reaction', self::lines($d, ['player_addressed' => false]), $ll);
            $this->assertSame($ll, $d['_last_interaction_ll'], "{$ll}: still waiting for the player");

            $lines = self::lines($d);
            $this->assertSame($text, $lines['ll_reaction'], $ll);
            $this->assertDoesNotMatchRegularExpression('/\d/', $lines['ll_reaction']);
            $this->assertArrayNotHasKey('_last_interaction_ll', $d, "{$ll}: one-shot");
            $this->assertArrayNotHasKey('ll_reaction', self::lines($d), "{$ll}: said once");
            $this->assertSame(1, intval($d['love_language_hints_given']), "{$ll}: the hints given are counted");
        }
    }

    public function testAHintFromTooLongAgoIsNotHowTheLastGestureLanded(): void
    {
        $d = self::npc(['_last_interaction_ll' => RelationshipDynamics::LL_TOUCH, '_last_interaction_ll_gamets' => self::T0 - 30 * self::HOUR]);
        $this->assertArrayNotHasKey('ll_reaction', self::lines($d));
        $this->assertArrayNotHasKey('_last_interaction_ll', $d, 'a stale gesture is dropped, not kept for later');
        $this->assertSame(0, intval($d['love_language_hints_given'] ?? 0));
    }

    public function testACallerCanStillHandTheComposeTheGesture(): void
    {
        $d = self::npc();
        $lines = self::lines($d, ['player_addressed' => true, 'last_ll' => RelationshipDynamics::LL_TOUCH]);
        $this->assertSame(self::text('ll_reaction', RelationshipDynamics::LL_TOUCH), $lines['ll_reaction']);
    }

    // ------------------------------------------------------------------ what persists a gesture

    public function testTheLocalClassifierCallsOnlyAGestureAGestureNotPlainConversation(): void
    {
        $chat = ['inputtext', '1', '1', 'Kaida: Hello there.'];
        $this->assertNull(RelDynFelt::legacyGestureLL(RelationshipDynamics::LL_TIME, $chat), 'plain dialogue reads as quality time to the classifier: not a gesture');
        $this->assertNull(RelDynFelt::legacyGestureLL(null, $chat));
        $this->assertSame(RelationshipDynamics::LL_WORDS, RelDynFelt::legacyGestureLL(RelationshipDynamics::LL_WORDS, $chat), 'warm words are one');
        $this->assertNull(RelDynFelt::legacyGestureLL(RelationshipDynamics::LL_SERVICE, $chat), 'talking after a fight is no deed of service');
        $this->assertSame(RelationshipDynamics::LL_TOUCH, RelDynFelt::legacyGestureLL(RelationshipDynamics::LL_TOUCH, ['ext_nsfw_physics', '1', '1', 'x']));
        $this->assertSame(RelationshipDynamics::LL_GIFTS, RelDynFelt::legacyGestureLL(RelationshipDynamics::LL_GIFTS, ['maras_sync', '1', '1', 'gift']));
        // an evening together the classifier saw outside plain dialogue (a shared fight, a quest) is one
        $this->assertSame(RelationshipDynamics::LL_TIME, RelDynFelt::legacyGestureLL(RelationshipDynamics::LL_TIME, ['combatend', '1', '1', 'x']));
    }

    private static function item(array $tags, array $signals = [], bool $grievance = false): array
    {
        return ['tags' => $tags, 'signals' => $signals + ['affinity' => 1.0, 'trust' => 0.0, 'comfort' => 1.0, 'respect' => 0.0, 'passion' => 1.0, 'maturity' => 0.0],
            'grievance' => ['flag' => $grievance, 'kind' => null, 'severity' => 0]];
    }

    public function testAnEvalItemsLoveLanguageTagsSetTheGestureByWhatSheLikesBest(): void
    {
        $tl = RelationshipDynamics::LL_TOUCH;
        // a hug: primary
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['touch']), true, self::T0);
        $this->assertSame($tl, $d['_last_interaction_ll']);
        $this->assertEquals(self::T0, $d['_last_interaction_ll_gamets']);
        $this->assertEquals(2.0, $d['pending_blush_mult'], 'the moment reads as a primary match');
        // an evening: her secondary
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['quality_time']), true, self::T0);
        $this->assertSame(RelationshipDynamics::LL_TIME, $d['_last_interaction_ll']);
        $this->assertEquals(1.5, $d['pending_blush_mult']);
        // a gift she does not care for: a miss, the blush as it was
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['gift']), true, self::T0);
        $this->assertSame(RelationshipDynamics::LL_GIFTS, $d['_last_interaction_ll']);
        $this->assertArrayNotHasKey('pending_blush_mult', $d);
        // several tags: the one she likes best is how it landed, not the first listed
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['praise', 'gift', 'touch']), true, self::T0);
        $this->assertSame($tl, $d['_last_interaction_ll']);
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['praise', 'quality_time']), true, self::T0);
        $this->assertSame(RelationshipDynamics::LL_TIME, $d['_last_interaction_ll'], 'secondary over a plain miss');
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['praise', 'gift']), true, self::T0);
        $this->assertSame(RelationshipDynamics::LL_WORDS, $d['_last_interaction_ll'], 'no match: the first');
    }

    public function testTheBlushMultiplierOnlyRidesAMomentAndNeverShrinks(): void
    {
        $d = self::npc();
        RelDynFelt::noteEvalGesture($d, self::item(['touch']), false, self::T0);
        $this->assertArrayNotHasKey('pending_blush_mult', $d, 'no moment on top of the floor, nothing to blush at');
        $this->assertSame(RelationshipDynamics::LL_TOUCH, $d['_last_interaction_ll'], 'but the gesture still landed');
        $d = self::npc(['pending_blush_mult' => 2.0]);
        RelDynFelt::noteEvalGesture($d, self::item(['quality_time']), true, self::T0);
        $this->assertEquals(2.0, $d['pending_blush_mult'], 'an unspent primary moment is not cooled by a later secondary one');
    }

    public function testAGestureThatHurtOrCarriedNoLoveLanguageIsNotHowItLanded(): void
    {
        foreach ([
            'a grievance' => self::item(['touch'], [], true),
            'a net loss' => self::item(['touch'], ['affinity' => -3.0, 'comfort' => -2.0, 'passion' => 0.0]),
            'no love-language tag' => self::item(['competence']),
            'a rescue (its own line)' => self::item(['rescue']),
            'no tags' => self::item([]),
        ] as $why => $item) {
            $d = self::npc();
            RelDynFelt::noteEvalGesture($d, $item, true, self::T0);
            $this->assertArrayNotHasKey('_last_interaction_ll', $d, $why);
        }
    }
}
