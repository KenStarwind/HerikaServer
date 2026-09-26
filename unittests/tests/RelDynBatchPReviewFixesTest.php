<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** The RelDyn config row only (conf_opts): everything else reads as absent. */
final class RelDynBatchPReviewConfigDb
{
    public ?array $row = null;
    public function fetchOne($sql, $params = null)
    {
        return (strpos((string) $sql, RelationshipDynamics::CONFIG_ROW_ID) !== false && $this->row !== null)
            ? ['value' => json_encode($this->row)] : [];
    }
    public function fetchAll($sql) { return []; }
    public function escape($s) { return addslashes((string) $s); }
}

/**
 * Batch P review fixes, the pure parts.
 *
 * resentment-self (rulings 2026-09-25 §18 #10): a config row install.php wrote before the rulings
 * (config_schema 2) stores every tag list the rulings extended as it was then, and each section's
 * config() lets the stored list win. The rulings' tags (confessing split from confiding, the
 * player's forgiveness) join those stored lists (CONFIG_TAGS_ADDED_V3); a row stamped since is a
 * choice and stays as stored.
 *
 * charisma (MDD 5.1 / 5.2): the Charmer "triggers Ick if overused" (the Ick's threshold falls
 * while the Charmer is graded in most of the window), and low maturity "friendzones the Charmer"
 * (her passion barely moves for him, whatever her temperament).
 */
final class RelDynBatchPReviewFixesTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved['db'] = array_key_exists('db', $GLOBALS) ? [$GLOBALS['db']] : null;
        $GLOBALS['db'] = new RelDynBatchPReviewConfigDb();
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->saved['db'] === null) unset($GLOBALS['db']); else $GLOBALS['db'] = $this->saved['db'][0];
        RelationshipDynamics::clearConfigCache();
    }

    private function store(array $row): void
    {
        $GLOBALS['db']->row = $row;
        RelationshipDynamics::clearConfigCache();
    }

    /** The row install.php wrote at reldyn-v0.15 (31774b6f): today's defaults with the lists of then. */
    private static function rowBeforeTheRulings(): array
    {
        $row = RelationshipDynamics::defaultConfig();
        $row['config_schema'] = 2;
        $row['protocols']['parasite']['genuine_tags'] = ['quality_time', 'praise', 'help', 'rescue', 'confiding', 'confession',
            'reassurance', 'apology', 'touch', 'intimacy', 'competence'];
        $row['intrinsic_goals']['eval_tags']['bond_seeking'] = ['quality_time', 'confiding', 'touch', 'reassurance', 'praise'];
        $row['attraction']['emotional_passion']['tags'] = ['quality_time', 'praise', 'reassurance', 'confiding', 'touch'];
        unset($row['fulfillment']['tag_delivery']['confessing'], $row['fulfillment']['tag_delivery']['forgiveness']);
        $row['resentment_arc']['self']['recovery_tags'] = ['confiding' => 10.0];
        return $row;
    }

    // ------------------------------------------------------------------ resentment-self: the tag split

    public function testARowWrittenBeforeTheRulingsLearnsTheirTags(): void
    {
        $this->assertSame(3, RelationshipDynamics::CONFIG_SCHEMA, 'the rulings bumped the row stamp');
        $row = self::rowBeforeTheRulings();
        $row['fulfillment']['tag_delivery']['praise'] = [RelationshipDynamics::LL_WORDS => 2.0];   // someone's own tuning
        $this->store($row);

        $defaults = RelDynFulfillment::configDefaults()['tag_delivery'];
        $delivery = RelDynFulfillment::config()['tag_delivery'];
        $this->assertSame($defaults['confessing'], $delivery['confessing'], 'her confession is being known too');
        $this->assertSame($defaults['forgiveness'], $delivery['forgiveness']);
        $this->assertEquals([RelationshipDynamics::LL_WORDS => 2.0], $delivery['praise'], 'a stored choice stays');

        $this->assertContains('confessing', RelDynGoals::config()['eval_tags']['bond_seeking']);
        $genuine = RelDynProtocols::config()['parasite']['genuine_tags'];
        foreach (['confessing', 'forgiveness', 'confession', 'confiding'] as $tag) $this->assertContains($tag, $genuine, $tag);
        $this->assertContains('confessing', RelDynAttraction::emotionalChannels());
        $this->assertSame(['confessing' => 10.0], RelDynResentment::config()['self']['recovery_tags'], 'the retired default reads as the new one');

        // Nothing is added twice, and nothing where the stored list was never there
        $this->assertSame(1, count(array_keys($genuine, 'confessing', true)));
        $stored = RelationshipDynamics::loadStoredConfig();
        $this->assertSame(RelationshipDynamics::CONFIG_SCHEMA, $stored['config_schema'], 'migrated in memory: a save stamps the current schema');
        unset($row['intrinsic_goals']);
        $this->store($row);
        $this->assertArrayNotHasKey('intrinsic_goals', RelationshipDynamics::loadStoredConfig(), 'no section invented');
        $this->assertContains('confessing', RelDynGoals::config()['eval_tags']['bond_seeking'], 'the default applies');
    }

    public function testARowStampedSinceTheRulingsIsAChoice(): void
    {
        $row = self::rowBeforeTheRulings();
        $row['config_schema'] = RelationshipDynamics::CONFIG_SCHEMA;
        $this->store($row);
        $this->assertNotContains('confessing', RelDynGoals::config()['eval_tags']['bond_seeking'], 'removed on purpose');
        $this->assertArrayNotHasKey('confessing', RelDynFulfillment::config()['tag_delivery']);
    }

    public function testASchemaTwoRowKeepsItsToggles(): void
    {
        // Only a row from before the settings-page fix (schema < 2) has its toggles dropped
        $this->store(['config_schema' => 2, 'enabled' => false, 'walkaway_enabled' => false]);
        $this->assertFalse(RelationshipDynamics::isEnabled());
        $this->assertFalse(RelationshipDynamics::getConfig()['walkaway_enabled']);
        $this->store(['enabled' => false]);
        $this->assertTrue(RelationshipDynamics::isEnabled(), 'an unstamped row: toggles from the isset() bug are dropped');
    }

    public function testAConfessionOnAnOldRowStillFulfilsAndIsNoPurchase(): void
    {
        $this->store(self::rowBeforeTheRulings());
        $delivery = RelDynFulfillment::config()['tag_delivery']['confessing'] ?? [];
        $this->assertGreaterThan(0.0, floatval($delivery[RelDynIntimacy::EMOTIONAL] ?? 0), 'emotional intimacy delivered');
        $this->assertTrue(RelDynAttraction::channelOpen(['passion_channels' => RelDynAttraction::emotionalChannels()], ['confessing']),
            "an asexual NPC's passion grows through her confession");
    }

    // ------------------------------------------------------------------ charisma: the Charmer

    /** RelDyn state with the eval's charisma grades (oldest first) and maturity $maturity. */
    private static function graded(array $grades, float $maturity): array
    {
        $d = ['dimensions' => ['maturity' => ['x' => $maturity, 'baseline' => $maturity]]];
        foreach ($grades as $g) RelationshipDynamics::updateCharismaTracker($d, $g, 1000.0);
        return $d;
    }

    public function testTheCharmerOverusedBringsTheIckCloser(): void
    {
        $cfg = RelationshipDynamics::charismaConfig();
        $this->assertSame(0.7, $cfg['charmer_overuse_share']);
        $this->assertSame(0.7, $cfg['charmer_overuse_ick_mult']);
        $some = self::graded(['charmer', 'charmer', 'charmer', 'none', 'none', 'charmer', 'none', 'none', 'rock', 'charmer'], 50.0);
        $this->assertSame('charmer', RelationshipDynamics::charismaStyle($some));
        $this->assertFalse(RelationshipDynamics::charmerOverused($some), 'five of ten: his style, not overuse');
        $overused = self::graded(array_fill(0, 8, 'charmer'), 50.0);
        $this->assertTrue(RelationshipDynamics::charmerOverused($overused), 'eight of eight');
        $this->assertFalse(RelationshipDynamics::charmerOverused(self::graded(array_fill(0, 8, 'catalyst'), 50.0)));

        // The Ick's threshold: 0.5 x (1 + 50/100) = 0.75 of courting exchanges; overused: x 0.7 = 0.525
        $ick = function (array $d, int $romantic): array {
            $d['_ick_tracker'] = ['total_count' => 10, 'romantic_count' => $romantic, 'window_start' => 0];
            $d['dimensions'] += ['comfort' => ['x' => 20.0, 'baseline' => 50.0], 'passion' => ['x' => 5.0, 'baseline' => 10.0],
                'warmth' => ['x' => 20.0, 'baseline' => 50.0]];
            return $d;
        };
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($ick($some, 6), 'Gentle'), 'six of ten: under 0.75');
        $this->assertTrue(RelationshipDynamics::checkIckTrigger($ick($overused, 6), 'Gentle'), 'the same courting, overused: over 0.525');
        $this->assertFalse(RelationshipDynamics::checkIckTrigger($ick($overused, 5), 'Gentle'), 'five of ten stays under');
    }

    public function testLowMaturityFriendzonesTheCharmer(): void
    {
        $cfg = RelationshipDynamics::charismaConfig();
        $this->assertSame(40.0, (float) $cfg['charmer_friendzone_maturity_at_most']);
        $this->assertSame(0.5, $cfg['charmer_friendzone_passion_mult']);
        // An effective temperament (Romantic) is charmed at ordinary maturity...
        $this->assertSame(1.2, RelationshipDynamics::getCharismaEffectiveness('charmer', 'Romantic', 50.0, 'passion'));
        // ... and friendzones him when immature: passion barely moves, affinity is untouched
        $this->assertSame(0.5, RelationshipDynamics::getCharismaEffectiveness('charmer', 'Romantic', 40.0, 'passion'));
        $this->assertSame(0.5, RelationshipDynamics::getCharismaEffectiveness('charmer', 'Proud', 20.0, 'passion'));
        $this->assertSame(1.0, RelationshipDynamics::getCharismaEffectiveness('charmer', 'Romantic', 20.0, 'affinity'));
        // The Catalyst still works on her (MDD 5.2: she mistakes it for passion)
        $this->assertSame(1.5, RelationshipDynamics::getCharismaEffectiveness('catalyst', 'Romantic', 20.0, 'passion'));
    }
}
