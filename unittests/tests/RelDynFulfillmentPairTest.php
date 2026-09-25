<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/RelDynFacetAppraisalTest.php';

/**
 * Rulings 2026-09-24 §11, fulfillment-per-pair: fulfillment is stored and computed per
 * relationship pair (this NPC -> target), 'Player' being today's only live target; the
 * pre-pair blob is the player pair and migrates transparently; presence is an actual
 * interaction within the pair. Pure: no database, fixed game timestamps.
 */
final class RelDynFulfillmentPairTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
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

    private function prefs(): array
    {
        return array_replace(RelDynFacets::neutralPreferences(), ['nature' => 0.9, 'combat' => 0.8, 'scholarly' => -0.5]);
    }

    private function npc(float $maturity = 80.0): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => 'Independent',
            'profile_overrides' => ['attachment_style' => 'secure'],
            'traits' => [],
            'love_language_primary' => RelationshipDynamics::LL_TIME,
            'love_language_secondary' => RelationshipDynamics::LL_WORDS,
            '_core_rel_type' => 'romantic',
        ]));
        $d['dimensions']['maturity']['x'] = $maturity;
        return $d;
    }

    public function testPairKeysArePlayerOrTheTargetNpcCaseInsensitive(): void
    {
        $this->assertSame('Player', RelDynFulfillment::pairKey('player'));
        $this->assertSame('Player', RelDynFulfillment::pairKey(' Player '));
        $this->assertSame('farkas', RelDynFulfillment::pairKey('Farkas'));
        $this->assertSame('aela the huntress', RelDynFulfillment::pairKey('Aela the Huntress'));
        $this->assertTrue(RelDynFulfillment::isPlayerTarget('PLAYER'));
        $this->assertFalse(RelDynFulfillment::isPlayerTarget('Farkas'));
        $this->expectException(InvalidArgumentException::class);
        RelDynFulfillment::pairKey('  ');
    }

    public function testThePrePairBlobIsThePlayerPairAndMigratesUnchanged(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $state = RelDynFulfillment::pairState($d);
        $this->assertSame(['v' => RelDynFulfillment::CONTAINER_VERSION, 'pairs' => ['Player' => $state]], $d['_fulfillment'],
            'new state is written per pair');

        // A blob stored before the pairs (one state straight under _fulfillment)
        $legacy = $d;
        $legacy['_fulfillment'] = $state;
        $this->assertSame($state, RelDynFulfillment::pairState($legacy), 'read as the player pair before any migration');
        $this->assertSame(['Player' => $state], RelDynFulfillment::pairs($legacy));
        $this->assertEquals(RelDynFulfillment::compute($d, [], self::T0 + 2 * self::DAY),
            RelDynFulfillment::compute($legacy, [], self::T0 + 2 * self::DAY), 'the same read either way');
        $this->assertTrue(RelDynFulfillment::migrate($legacy));
        $this->assertSame($d['_fulfillment'], $legacy['_fulfillment'], 'migrated: the container, the state unchanged');
        $this->assertFalse(RelDynFulfillment::migrate($legacy), 'once');

        // Writing through the pair API migrates on the way too
        $legacy2 = $d;
        $legacy2['_fulfillment'] = $state;
        RelDynFulfillment::deliver($legacy2, [RelationshipDynamics::LL_TIME => 1.0], self::T0 + self::HOUR);
        $this->assertArrayHasKey('pairs', $legacy2['_fulfillment']);
        $this->assertSame(['Player'], array_keys($legacy2['_fulfillment']['pairs']));

        // An empty pre-pair blob is no state
        $empty = $this->npc();
        $empty['_fulfillment'] = [];
        $this->assertNull(RelDynFulfillment::pairState($empty));
        $this->assertTrue(RelDynFulfillment::migrate($empty));
        $this->assertArrayNotHasKey('_fulfillment', $empty);
    }

    public function testStorageLoadsMigrateThePrePairBlob(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        $state = RelDynFulfillment::pairState($d);
        $stored = $d;
        $stored['_fulfillment'] = $state;
        // The shape getDynamics() hands out and saveDynamics() merges against
        $norm = (new ReflectionMethod(RelationshipDynamics::class, 'normalizeStoredDynamics'))->invoke(null, $stored);
        $this->assertSame(['v' => 2, 'pairs' => ['Player' => $state]], $norm['_fulfillment']);
    }

    public function testEachPairKeepsItsOwnLevelsPresenceAndBand(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0, 'Farkas');
        $this->assertSame(['Player', 'farkas'], array_keys(RelDynFulfillment::pairs($d)));
        $this->assertSame(RelDynFulfillment::pairState($d)['w'], RelDynFulfillment::pairState($d, 'farkas')['w'],
            'the needs are hers, whoever the target');

        // Time together with Farkas covers the Farkas pair, not the player's
        RelDynFulfillment::deliver($d, [RelationshipDynamics::LL_TIME => 1.5, 'nature' => 1.5], self::T0 + self::HOUR, 'Farkas');
        RelDynFulfillment::recordContactDay($d, self::T0 + self::HOUR, 'Farkas');
        $at = self::T0 + 2 * self::HOUR;
        $player = RelDynFulfillment::compute($d, [], $at);
        $farkas = RelDynFulfillment::compute($d, [], $at, 'farkas');
        $this->assertGreaterThan($player['band'] + 0.2, $farkas['band']);
        $this->assertTrue(RelDynFulfillment::wasPresentOn(RelDynFulfillment::pairState($d, 'Farkas'), RelDynFulfillment::gameDayOf(self::T0)));
        $this->assertFalse(RelDynFulfillment::wasPresentOn(RelDynFulfillment::pairState($d), RelDynFulfillment::gameDayOf(self::T0)),
            'being with Farkas is not a day with the player');

        $graph = RelDynFulfillment::graph('Aela', $d, [], $at, 'Farkas');
        $this->assertSame('farkas', $graph['target']);
        $this->assertSame($farkas['band'], $graph['band']);
        $this->assertSame('Player', RelDynFulfillment::graph('Aela', $d, [], $at)['target']);
    }

    public function testTheBoundaryAndNeglectBelongToThePlayerPairOnly(): void
    {
        $d = $this->npc(80.0);   // mature: the player pair would state a boundary
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0, 'Farkas');
        for ($day = 0; $day < 9; $day++) {
            RelDynFulfillment::recordContactDay($d, self::T0 + $day * self::DAY + self::HOUR);
            RelDynFulfillment::recordContactDay($d, self::T0 + $day * self::DAY + self::HOUR, 'Farkas');
        }
        $now = self::T0 + 9 * self::DAY;
        $npcTick = RelDynFulfillment::tick($d, $now, 'Farkas');
        $this->assertNotSame([], $npcTick['samples'], 'the Farkas pair samples its days');
        $this->assertSame([], $npcTick['events'], 'no boundary machine on an NPC pair (yet)');
        $this->assertArrayNotHasKey('neglect', $npcTick, 'no unfulfilled-neglect charge against the player bond');
        $this->assertArrayHasKey('low_since_gamets', RelDynFulfillment::pairState($d, 'Farkas'), 'its low stretch is tracked');
        $this->assertSame('none', RelDynFulfillment::pairState($d, 'Farkas')['boundary']['state']);

        $playerTick = RelDynFulfillment::tick($d, $now);
        $this->assertContains('boundary_due', $playerTick['events'], 'the player pair runs the mature boundary');
        $this->assertArrayHasKey('neglect', $playerTick);
        $this->assertTrue(RelDynFulfillment::boundaryActive($d));
        $this->assertFalse(RelDynFulfillment::boundaryActive($d, 'Farkas'));
    }

    public function testPresenceIsAnInteractionWithinThePair(): void
    {
        $this->assertTrue(RelationshipDynamics::isPairInteraction(['inputtext', '1', '2', 'Kaida: hi (Talking to Aela)'], 'Kaida'));
        $this->assertTrue(RelationshipDynamics::isPairInteraction(['ginputtext_s', '1', '2', 'Kaida: hi'], 'Kaida'));
        foreach (['bored', 'radiant', 'rechat', 'chatnf_greet', 'infoloc'] as $type) {
            $this->assertFalse(RelationshipDynamics::isPairInteraction([$type, '1', '2', 'Aela: hm'], 'Kaida'), "{$type}: not the pair's interaction");
        }
        $this->assertTrue(RelationshipDynamics::isPairInteraction(['ext_nsfw_sexcene', '1', '2', 'Scene/kissing/Stage 1/Actor^Aela^Kaida'], 'Kaida'),
            'intimacy with the player the plugin reports');
        $this->assertFalse(RelationshipDynamics::isPairInteraction(['ext_nsfw_npc_scene', '1', '2', 'Scene/Aela^Farkas'], 'Kaida'));

        // The prerequest step: contact without an interaction refreshes the state but is no day with her
        $d = $this->npc();
        RelationshipDynamics::advanceFulfillment('Aela', $d, self::T0 + self::HOUR, true, false);
        $this->assertNotNull(RelDynFulfillment::pairState($d), 'the state exists from the first contact');
        $this->assertFalse(RelDynFulfillment::wasPresentOn(RelDynFulfillment::pairState($d), RelDynFulfillment::gameDayOf(self::T0)),
            'an NPC remark the player never answered is not presence');
        RelationshipDynamics::advanceFulfillment('Aela', $d, self::T0 + 2 * self::HOUR, true, true);
        $this->assertTrue(RelDynFulfillment::wasPresentOn(RelDynFulfillment::pairState($d), RelDynFulfillment::gameDayOf(self::T0)));
    }

    /**
     * Place time and shared experiences fill the player pair only on turns of that pair (batch O
     * review; rulings §11): her own remarks in a forest the player never answers, a fight she
     * only watched and her own drink are not time with the player.
     */
    public function testPlaceTimeAndExperiencesFillThePlayerPairOnlyOnItsOwnTurns(): void
    {
        $prefs = $this->prefs();
        $levels = fn(array $d) => RelDynFulfillment::pairState($d)['lv'];
        $alone = $this->npc();
        RelDynFulfillment::ensure($alone, $prefs, self::T0);
        $start = $levels($alone);
        // Six of her own remarks an hour apart in the woods: no word from the player
        for ($h = 0; $h <= 5; $h++) {
            RelDynFacets::placeTurn('Aela', $alone, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0 + $h * self::HOUR, false);
        }
        $this->assertEquals($start, $levels($alone), 'a follower the player never talks to is not fulfilling the player pair');
        // A fight she only watched and her own drink: hers alone
        RelDynFacets::experienceThing('Aela', $alone, 'activity', 'combat', $prefs, self::T0 + 6 * self::HOUR, null, null);
        RelDynFacets::experienceThing('Aela', $alone, 'item', 'Nord Mead', $prefs, self::T0 + 6 * self::HOUR, ['nature' => 0.5], null);
        $this->assertEquals($start, $levels($alone), 'experiences outside the pair');

        // The same woods with the player's word each hour: the hours between those turns are the pair's
        $together = $this->npc();
        RelDynFulfillment::ensure($together, $prefs, self::T0);
        for ($h = 0; $h <= 5; $h++) {
            RelDynFacets::placeTurn('Aela', $together, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0 + $h * self::HOUR, true);
        }
        $this->assertGreaterThan($start['nature'], $levels($together)['nature'], 'time in the woods together');

        // Her own remarks between two turns of the pair in the same place do not break the stretch;
        // a remark of hers somewhere else does
        $mixed = $this->npc();
        RelDynFulfillment::ensure($mixed, $prefs, self::T0);
        RelDynFacets::placeTurn('Aela', $mixed, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0, true);
        RelDynFacets::placeTurn('Aela', $mixed, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0 + 2 * self::HOUR, false);
        RelDynFacets::placeTurn('Aela', $mixed, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0 + 5 * self::HOUR, true);
        $pairOnly = $this->npc();
        RelDynFulfillment::ensure($pairOnly, $prefs, self::T0);
        RelDynFacets::placeTurn('Aela', $pairOnly, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0, true);
        RelDynFacets::placeTurn('Aela', $pairOnly, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0 + 5 * self::HOUR, true);
        $this->assertGreaterThan($start['nature'], $levels($mixed)['nature']);
        $this->assertEquals($levels($pairOnly), $levels($mixed), 'the same five hours together, her remark in between or not');
        $apart = $this->npc();
        RelDynFulfillment::ensure($apart, $prefs, self::T0);
        RelDynFacets::placeTurn('Aela', $apart, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0, true);
        RelDynFacets::placeTurn('Aela', $apart, 'Jorrvaskr', ['combat' => 0.6, 'crowd' => 0.5], $prefs, self::T0 + 2 * self::HOUR, false);
        RelDynFacets::placeTurn('Aela', $apart, 'Fallowstone Woods', RelDynFacetAppraisalTest::FOREST, $prefs, self::T0 + 5 * self::HOUR, true);
        $this->assertEquals($start['nature'], $levels($apart)['nature'], 'she was in Jorrvaskr in between: arrival, no stretch');

        // A fight beside the player is the pair's
        $fought = $this->npc();
        RelDynFulfillment::ensure($fought, $prefs, self::T0);
        RelDynFacets::experienceThing('Aela', $fought, 'activity', 'combat', $prefs, self::T0 + self::HOUR, ['combat' => 1.0]);
        $this->assertGreaterThan($start['combat'], $levels($fought)['combat']);
    }

    public function testASaveLoadRebaselinesEveryPair(): void
    {
        $d = $this->npc();
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0);
        RelDynFulfillment::ensure($d, $this->prefs(), self::T0, 'Farkas');
        foreach ([null, 'Farkas'] as $t) {
            $at = self::T0 + 5 * self::DAY;
            $t === null ? RelDynFulfillment::recordContactDay($d, $at) : RelDynFulfillment::recordContactDay($d, $at, $t);
            $t === null ? RelDynFulfillment::tick($d, $at) : RelDynFulfillment::tick($d, $at, $t);
        }
        $loadedAt = self::T0 + 2 * self::DAY + 3 * self::HOUR;
        $r = RelDynTimeline::rebaselineDynamics($d, $loadedAt, null);
        foreach (['Player', 'farkas'] as $key) {
            $s = RelDynFulfillment::pairs($r)[$key];
            $this->assertLessThanOrEqual($loadedAt, $s['sampled_gamets'], "{$key}: no day-end after the load");
            $this->assertSame([], array_values(array_filter($s['contact_days'] ?? [], fn($day) => $day > RelDynFulfillment::gameDayOf($loadedAt))),
                "{$key}: no contact from the discarded timeline");
        }
    }
}
