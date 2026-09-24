<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * temperaments-13: with temperament autogen every NPC has a temperament, so the passion,
 * reunion and jealousy multipliers apply to everyone. They must be MDD section 1.3's values
 * (relationship-dynamics-mdd.md, the 13-row table), measured through the functions that
 * use them, each against the same NPC with no temperament (1.0x).
 */
final class RelDynTemperamentMddTest extends TestCase
{
    /** MDD 1.3: temperament => [passion, reunion, jealousy] multipliers. */
    private const MDD_1_3 = [
        'Romantic'    => [1.3, 1.5, 1.3],
        'Anxious'     => [1.2, 1.8, 1.5],
        'Bold'        => [1.1, 1.0, 0.8],
        'Playful'     => [1.4, 0.8, 0.4],
        'Humble'      => [1.1, 1.0, 0.5],
        'Nurturing'   => [1.0, 1.2, 0.6],
        'Gentle'      => [0.9, 1.3, 0.4],
        'Jealous'     => [1.0, 1.2, 2.0],
        'Proud'       => [0.8, 0.8, 1.5],
        'Defiant'     => [1.2, 0.6, 1.0],
        'Guarded'     => [0.6, 0.7, 0.5],
        'Independent' => [0.7, 0.5, 0.3],
        'Stoic'       => [0.5, 0.3, 0.2],
    ];

    private const CALENDAR = 3.0e9;   // raw gamets, game day 300

    private $savedDb;
    private $savedRequest;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->savedRequest = $GLOBALS['gameRequest'] ?? null;
        unset($GLOBALS['db']);   // no stored config: shipped defaults
        $GLOBALS['gameRequest'] = ['inputtext', time(), self::CALENDAR, 'Kaida: hello'];
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        if ($this->savedRequest !== null) $GLOBALS['gameRequest'] = $this->savedRequest; else unset($GLOBALS['gameRequest']);
        RelationshipDynamics::clearConfigCache();
    }

    private function npc(?string $temperament): array
    {
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['inferred_temperament'] = $temperament;
        $d['love_language_primary'] = RelationshipDynamics::LL_TIME;
        $d['love_language_secondary'] = RelationshipDynamics::LL_WORDS;
        // 24 game-calendar hours since the last contact, with 2 real hours of play in between
        $d['_last_contact_gamets'] = self::CALENDAR - RelationshipDynamics::GAMETS_PER_DAY;
        $d['_accumulated_play_gamets'] = 10 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        $d['_last_contact_play_gamets'] = 8 * RelationshipDynamics::GAMETS_PER_REAL_HOUR;
        return $d;
    }

    public function testPassionReunionAndJealousyMultipliersAreTheMddValues(): void
    {
        $passion0 = RelationshipDynamics::calculatePassionGain($this->npc(null), RelationshipDynamics::LL_TIME);
        $n = $this->npc(null);
        $reunion0 = RelationshipDynamics::checkReunion($n, 100);   // core affinity 100 (-100..100)
        $jealousy0 = RelationshipDynamics::jealousyEventGain(['attachment_style' => 'secure'] + $this->npc(null), 1);   // secure: temperament only
        $this->assertGreaterThan(0, $passion0);
        $this->assertGreaterThan(0, $reunion0);
        $this->assertGreaterThan(0, $jealousy0);

        foreach (self::MDD_1_3 as $temperament => [$passion, $reunion, $jealousy]) {
            $p = RelationshipDynamics::calculatePassionGain($this->npc($temperament), RelationshipDynamics::LL_TIME);
            $this->assertEqualsWithDelta($passion, $p / $passion0, 1e-9, "{$temperament} passion (MDD 1.3)");

            $d = $this->npc($temperament);
            $r = RelationshipDynamics::checkReunion($d, 100);
            $this->assertEqualsWithDelta($reunion, $r / $reunion0, 1e-9, "{$temperament} reunion (MDD 1.3)");

            $j = RelationshipDynamics::jealousyEventGain(['attachment_style' => 'secure'] + $this->npc($temperament), 1);
            $this->assertEqualsWithDelta($jealousy, $j / $jealousy0, 1e-9, "{$temperament} jealousy (MDD 1.3)");
        }
    }
}
