<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * Batch T, lane "classify" (no database): interaction-classification (an item handover is split
 * by item: food, drink and potions are service, anything else a gift; the dead MARAS branches
 * are gone; a handover is delivered once), conflict-repair (the repair passion multiplier on the
 * eval path), jealousy-core (courting words are exposure) and eval-input-quality part 2 (the
 * per-dimension anchors of the eval's SIGNALS text). The test beds are in
 * RelDynBatchTClassifyBedsPostgresTest.
 */
final class RelDynBatchTClassifyTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'CACHE_PARTY', 'CACHE_PEOPLE', 'LAST_LLM_RESPONSE'] as $k) {
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

    private static function request(string $type, string $action): array
    {
        return [$type, '1727000000', (string) self::T0, $action];
    }

    // =====================================================================
    // interaction-classification: the handover split
    // =====================================================================

    /** The item a give / trade action names (ExtCmdGiveItem@Name), else null. */
    public function testAHandoverActionNamesItsItem(): void
    {
        $this->assertSame('Ruby Ring', RelationshipDynamics::handoverItemOfAction('ExtCmdGiveItem@Ruby Ring'));
        $this->assertSame('Potion of Healing', RelationshipDynamics::handoverItemOfAction('ExtCmdTradeItem@Potion of Healing'));
        $this->assertSame('Ruby Ring', RelationshipDynamics::handoverItemOfAction("ExtCmdGiveItem@Ruby Ring\r\n"));
        $this->assertNull(RelationshipDynamics::handoverItemOfAction('ExtCmdGiveItem'), 'a handover that names nothing');
        $this->assertNull(RelationshipDynamics::handoverItemOfAction('ExtCmdHug'));
        $this->assertTrue(RelationshipDynamics::isHandoverAction('ExtCmdGiveItem'));
        $this->assertTrue(RelationshipDynamics::isHandoverAction('ExtCmdTradeItem@Bread'));
        $this->assertFalse(RelationshipDynamics::isHandoverAction('ExtCmdHug'));
    }

    /** Food, drink and potions are looking after her (service); a jewel is a gift. */
    public function testAPotionHandoverIsServiceAndAJewelIsAGift(): void
    {
        $ll = fn(string $action, ?string $mood = null) => RelationshipDynamics::classifyInteraction(self::request('infoaction', $action), $mood);
        $gift = RelationshipDynamics::LL_GIFTS;
        $service = RelationshipDynamics::LL_SERVICE;

        foreach (['Ruby Ring', 'Amulet of Talos', 'Gold Ring', 'Elven Dagger', 'Soul Gem', 'Dragonscale Armor', 'Scale Mail', '100 Septims'] as $item) {
            $this->assertSame($gift, $ll("ExtCmdGiveItem@{$item}"), "{$item}: a gift");
        }
        foreach (['Potion of Healing', 'Healing Potions', 'Honningbrew Mead', 'Ale', 'Bread', 'Venison Stew', 'Elixir of Vigor', 'Sweet Roll', 'Skooma'] as $item) {
            $this->assertSame($service, $ll("ExtCmdGiveItem@{$item}"), "{$item}: service");
        }
        $this->assertSame($gift, $ll('ExtCmdTradeItem@Ruby Ring'));
        $this->assertSame($service, $ll('ExtCmdTradeItem@Potion of Healing'));
        // a handover that names nothing is read as it always was
        $this->assertSame($service, $ll('ExtCmdGiveItem'));
    }

    /** The word 'ale' inside 'Dragonscale' or 'Scale' is not a drink: whole words only. */
    public function testConsumablesAreWholeWordsNotSubstrings(): void
    {
        foreach (['Dragonscale Boots', 'Scale Armor', 'Meadow Flower Bouquet', 'Swine Iron', 'Spies Ledger', 'Pied Piper Flute'] as $item) {
            $this->assertFalse(RelDynGifts::isConsumable($item), $item);
        }
        foreach (['Ale', 'Black-Briar Mead', 'Potion of Minor Healing', 'Cabbage Soup', 'Fresh Bread'] as $item) {
            $this->assertTrue(RelDynGifts::isConsumable($item), $item);
        }
        $this->assertFalse(RelDynGifts::isConsumable(''));
        $this->assertSame(RelationshipDynamics::LL_GIFTS, RelDynGifts::handoverLoveLanguage('Ruby Ring'));
        $this->assertSame(RelationshipDynamics::LL_SERVICE, RelDynGifts::handoverLoveLanguage('Ale'));
        $this->assertSame(RelationshipDynamics::LL_SERVICE, RelDynGifts::handoverLoveLanguage(null));
    }

    /** What the action proves comes first: a flirty reply mood no longer turns a handover into words. */
    public function testAnObservedHandoverBeatsTheMoodGuess(): void
    {
        $req = self::request('infoaction', 'ExtCmdGiveItem@Ruby Ring');
        $this->assertSame(RelationshipDynamics::LL_GIFTS, RelationshipDynamics::classifyInteraction($req, 'flirty'));
        $this->assertSame(RelationshipDynamics::LL_GIFTS, RelationshipDynamics::classifyInteraction($req, null));
        // plain talk in a flirty mood is still words
        $this->assertSame(RelationshipDynamics::LL_WORDS, RelationshipDynamics::classifyInteraction(self::request('inputtext', 'Kaida: hi'), 'flirty'));
    }

    /** The eval is told the same split as certain events: gift for a jewel, help for a potion. */
    public function testTheEvalIsToldTheSplitAsAnObservedEvent(): void
    {
        $this->assertSame(['gift'], RelDynEval::eventTagsForRequest(self::request('infoaction', 'ExtCmdGiveItem@Ruby Ring')));
        $this->assertSame(['help'], RelDynEval::eventTagsForRequest(self::request('infoaction', 'ExtCmdGiveItem@Potion of Healing')));
        $this->assertSame(['help'], RelDynEval::eventTagsForRequest(self::request('infoaction', 'ExtCmdTradeItem@Honningbrew Mead')));
    }

    /** MARAS is retired: its sync requests classify as nothing (they never reach the hook anyway). */
    public function testTheDeadMarasBranchesAreGone(): void
    {
        $this->assertNull(RelationshipDynamics::classifyInteraction(self::request('maras_sync', 'gift'), null));
        $this->assertNull(RelationshipDynamics::classifyInteraction(self::request('maras_sync', 'promotion'), null));
        $src = (string) file_get_contents(__DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php');
        $start = (int) strpos($src, 'public static function classifyInteraction');
        $body = substr($src, $start, (int) strpos($src, 'private static function isNpcInCombatRecently') - $start);
        $this->assertStringNotContainsString('maras_sync', $body);
    }

    // ---------------------------------------------------------- delivered once (the handover ledger)

    private function dyn(): array
    {
        return ['dimensions' => []];
    }

    /** A handover row and an eval 'gift' tag for one exchange are one delivery, whichever comes first. */
    public function testARowAndAnEvalTagForTheSameHandoverPairOnce(): void
    {
        $at = self::T0;
        // the row first: it delivers; the eval's tag of the same exchange is then the same gift
        $d = $this->dyn();
        $this->assertFalse(RelDynGifts::noteHandover($d, 'row', 'gift', $at - 2 * self::HOUR / 60), 'a row nobody paired: deliver it');
        $this->assertTrue(RelDynGifts::noteHandover($d, 'request', 'gift', $at), 'the eval tag pairs with the row: not delivered twice');
        $this->assertFalse(RelDynGifts::noteHandover($d, 'request', 'gift', $at + 10), 'a second eval tag has no row left to pair with');

        // the eval first: the row is the same gift
        $d = $this->dyn();
        $this->assertFalse(RelDynGifts::noteHandover($d, 'request', 'gift', $at));
        $this->assertTrue(RelDynGifts::noteHandover($d, 'row', 'gift', $at - 60));
        $this->assertFalse(RelDynGifts::noteHandover($d, 'row', 'gift', $at - 30), 'a second row is a second gift');
    }

    public function testPairingKeepsTagsAndGameHoursApart(): void
    {
        $hours = (float) RelDynGifts::config()['eval_pair_game_hours'];
        $this->assertGreaterThan(0.0, $hours);
        $d = $this->dyn();
        $this->assertFalse(RelDynGifts::noteHandover($d, 'row', 'gift', self::T0));
        $this->assertFalse(RelDynGifts::noteHandover($d, 'request', 'help', self::T0), 'a gift row is not the help of a potion');
        $this->assertFalse(RelDynGifts::noteHandover($d, 'request', 'gift', self::T0 + ($hours + 1) * self::HOUR),
            'a gift tag long after the row is another gift');
        $this->assertTrue(RelDynGifts::noteHandover($d, 'request', 'gift', self::T0 + ($hours - 0.5) * self::HOUR));
    }

    /** The ledger is a keyed map (the save merge keeps both writers' entries) and does not grow without end. */
    public function testTheLedgerStaysSmallAndMergeable(): void
    {
        $d = $this->dyn();
        for ($i = 0; $i < 40; $i++) {
            RelDynGifts::noteHandover($d, 'row', 'gift', self::T0 + $i * self::DAY);
        }
        $ledger = $d[RelDynGifts::LEDGER_KEY];
        $this->assertLessThanOrEqual(RelDynGifts::LEDGER_KEEP, count($ledger));
        $this->assertFalse(array_is_list($ledger), 'keyed by id, not a list');
    }

    /** The eval's delivery leaves out a tag that a handover row already delivered. */
    public function testTheEvalDeliveryCanLeaveOutAPairedTag(): void
    {
        $item = ['tags' => ['gift', 'praise'], 'significance' => 0.5, 'positive_interaction' => true, 'signals' => ['affinity' => 2], 'grievance' => ['flag' => false]];
        $with = RelDynFulfillment::evalItemAmounts($item);
        $without = RelDynFulfillment::evalItemAmounts($item, null, ['gift']);
        $this->assertArrayHasKey(RelationshipDynamics::LL_GIFTS, $with);
        $this->assertArrayNotHasKey(RelationshipDynamics::LL_GIFTS, $without);
        $this->assertEqualsWithDelta($with[RelationshipDynamics::LL_WORDS], $without[RelationshipDynamics::LL_WORDS], 1e-9, 'praise is untouched');
    }

    // =====================================================================
    // jealousy-core: courting words are exposure
    // =====================================================================

    private function npc(array $extra = []): array
    {
        $d = $extra + [
            'inferred_temperament' => 'Stoic',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'jealousy_anger' => 0.0, 'in_conflict' => false, 'dimensions' => [],
        ];
        foreach (['maturity' => 60.0, 'self_confidence' => 50.0, 'trust' => 0.0, 'respect' => 50.0, 'comfort' => 50.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    private function item(array $over = []): array
    {
        return array_replace_recursive([
            'v' => 1, 'npc' => 'Lynly Star-Sung', 'npc_id' => 7, 'gamets' => self::T0, 'source' => 'reldyn_eval',
            'signals' => ['affinity' => 0, 'trust' => 0, 'comfort' => 0, 'respect' => 0, 'passion' => 0, 'maturity' => 0],
            'tags' => [],
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => 0.5, 'positive_interaction' => false, 'summary' => 'fixture',
        ], $over);
    }

    /** Open courting (romantic_intent 2 or 3) is seen by the room whether or not she welcomed it. */
    public function testCourtingWordsAreExposureWithoutATouchTag(): void
    {
        $min = (int) RelationshipDynamics::defaultConfig()['jealousy_romantic_intent_min'];
        $this->assertSame(2, $min);
        $exposure = function (array $over) {
            $d = $this->npc();
            return RelationshipDynamics::applyEvalFeelings('Lynly Star-Sung', $this->item($over), $d)['romantic_exposure'];
        };
        $this->assertTrue($exposure(['romantic_intent' => 2, 'positive_interaction' => true]), 'clear flirting, welcomed');
        $this->assertTrue($exposure(['romantic_intent' => 3]), 'open pursuit, not a positive exchange: still seen');
        $this->assertTrue($exposure(['romantic_intent' => 2, 'tags' => ['praise']]));
        $this->assertFalse($exposure(['romantic_intent' => 1, 'positive_interaction' => true]), 'light warmth is not courting');
        $this->assertFalse($exposure(['romantic_intent' => 0, 'positive_interaction' => true, 'tags' => ['help']]));
        // the touch / intimacy tags still count, as before (positive exchanges)
        $this->assertTrue($exposure(['tags' => ['intimacy', 'praise'], 'positive_interaction' => true]));
        $this->assertFalse($exposure(['tags' => ['touch']]), 'an unwelcome touch is not a positive exchange: as before');
    }

    public function testIntimacyTheGameReportedIsScannedAtTheRequestNotTwice(): void
    {
        $d = $this->npc();
        $out = RelationshipDynamics::applyEvalFeelings('Lynly Star-Sung',
            $this->item(['tags' => ['touch'], 'positive_interaction' => true, 'reported_intimacy' => 'scene']), $d);
        $this->assertTrue($out['romantic_exposure'], 'still exposure');
        $this->assertTrue($out['scanned_at_request'], 'but the request already made its witnesses jealous');
        $out = RelationshipDynamics::applyEvalFeelings('Lynly Star-Sung',
            $this->item(['tags' => ['touch'], 'positive_interaction' => true]), $d);
        $this->assertFalse($out['scanned_at_request']);
    }

    // =====================================================================
    // eval-input-quality part 2: the per-dimension anchors
    // =====================================================================

    public function testEverySignalCarriesItsAnchorsInTheSignalsText(): void
    {
        $user = RelDynEval::buildMessages('Lynly Star-Sung', 'Kaida',
            ['earlier' => [], 'current' => [['speaker' => 'Kaida', 'listener' => null, 'text' => 'hi']]], [], [])[1]['content'];
        $signals = substr($user, (int) strpos($user, 'SIGNALS'), (int) strpos($user, 'TAGS (') - (int) strpos($user, 'SIGNALS'));
        $this->assertSame(array_keys(RelDynEval::SIGNAL_LIMITS), array_keys(RelDynEval::SIGNAL_ANCHORS), 'one anchor line per signal');
        foreach (RelDynEval::SIGNAL_ANCHORS as $signal => $anchor) {
            $this->assertStringContainsString("- {$signal}:", $signals);
            $this->assertStringContainsString($anchor, $signals, "{$signal}'s anchor is in the prompt");
        }
        // the draft's anchors (design draft Dimensions 7 / 8 / 9; MDD 15.1)
        $this->assertMatchesRegularExpression('/kept a promise.*trust|trust.*kept a promise/is', $signals);
        $this->assertMatchesRegularExpression('/caught lying/i', $signals);
        $this->assertMatchesRegularExpression('/betrayed a confidence/i', $signals);
        $this->assertMatchesRegularExpression('/shared a secret/i', $signals);
        $this->assertMatchesRegularExpression('/large|big|heavy/i', $signals, 'a lie or a betrayal is a large loss');
        $this->assertMatchesRegularExpression('/boundary/i', $signals, 'comfort: a respected or overstepped boundary');
        $this->assertMatchesRegularExpression('/competence|expertise/i', $signals, 'respect');
        // the original signal questions are still there, and the scale line follows the anchors
        $this->assertStringContainsString('do they like Kaida more or less', $signals);
        $this->assertStringContainsString('Scale: most exchanges 0 to 3', $user);
        $this->assertStringContainsString('Maturity at most 10', $user);
    }

    public function testAnchorsDoNotNameNumbersTheModelWouldCopy(): void
    {
        foreach (RelDynEval::SIGNAL_ANCHORS as $signal => $anchor) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $anchor, "{$signal}: words, the scale line owns the numbers");
        }
    }
}
