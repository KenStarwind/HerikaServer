<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynReunionTextConfigDb
{
    public function __construct(public array $config) {}
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
 * Reunion prose (roadmap reunion-spike): the felt text of a return after a played absence.
 *   - it speaks from the same minimum the spike fires on (reunion_min_hours, game-calendar hours),
 *     not a second hard-coded 8;
 *   - it is felt text, not numbers (decisions §3: no digit reaches the LLM), and it names the NPC
 *     the way every other felt line does: never a gendered pronoun for the NPC (the player is
 *     "them"), whoever she or he is;
 *   - the three tiers (short / medium / long) differ, and so do the temperaments.
 * Pure: the per-NPC temperament routing and the hooks-level turn are in
 * RelDynFeltLaneTestBedsPostgresTest.
 */
final class RelDynReunionTextTest extends TestCase
{
    private array $saved = [];
    private RelDynReunionTextConfigDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $this->db = new RelDynReunionTextConfigDb(RelationshipDynamics::defaultConfig());
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** Every temperament the table knows, plus one it does not (the default set). */
    private static function temperaments(): array
    {
        return array_merge(array_keys(RelationshipDynamics::TEMPERAMENT_BASELINES['affinity']), ['Unnamed']);
    }

    public function testTheProseReadsTheSameMinimumHoursTheSpikeFiresOn(): void
    {
        $this->assertSame(8, RelationshipDynamics::defaultConfig()['reunion_min_hours'], 'the shipped minimum');
        $this->assertNull(RelationshipDynamics::getReunionText('Aela', 'Bold', 7.5, 'Kaida'));
        $this->assertNotNull(RelationshipDynamics::getReunionText('Aela', 'Bold', 8.0, 'Kaida'));

        // Config moves both together: a 12 hour minimum is 12 hours of prose too
        $this->db->config['reunion_min_hours'] = 12;
        RelationshipDynamics::clearConfigCache();
        $this->assertNull(RelationshipDynamics::getReunionText('Aela', 'Bold', 10.0, 'Kaida'), 'under the configured minimum');
        $this->assertNotNull(RelationshipDynamics::getReunionText('Aela', 'Bold', 12.5, 'Kaida'));
        // ... and a lower one lets a shorter absence speak (the spike fires from the same number)
        $this->db->config['reunion_min_hours'] = 4;
        RelationshipDynamics::clearConfigCache();
        $this->assertNotNull(RelationshipDynamics::getReunionText('Aela', 'Bold', 5.0, 'Kaida'));
        $this->assertNull(RelationshipDynamics::getReunionText('Aela', 'Bold', 3.0, 'Kaida'));
    }

    public function testEveryTemperamentAndTierIsFeelingsNamedNeverGendered(): void
    {
        $tiers = ['short' => 10.0, 'medium' => 30.0, 'long' => 60.0];
        foreach (self::temperaments() as $temperament) {
            $seen = [];
            foreach ($tiers as $tier => $hours) {
                $text = RelationshipDynamics::getReunionText('Serana', $temperament, $hours, 'Kaida');
                $this->assertIsString($text, "{$temperament} {$tier}");
                $this->assertStringContainsString('Serana', $text, "{$temperament} {$tier}: the NPC is named");
                $this->assertStringContainsString('Kaida', $text, "{$temperament} {$tier}: the player is named");
                $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$temperament} {$tier}: no number reaches the LLM");
                $this->assertDoesNotMatchRegularExpression('/\b(she|her|hers|herself|he|him|his|himself)\b/i', $text,
                    "{$temperament} {$tier}: a pronoun for the NPC is the NPC's gender, which this table does not know:\n{$text}");
                $seen[$tier] = $text;
            }
            $this->assertCount(3, array_unique($seen), "{$temperament}: three tiers, three texts");
        }
    }

    public function testTemperamentsReadDifferently(): void
    {
        $texts = [];
        foreach (self::temperaments() as $temperament) {
            $texts[$temperament] = RelationshipDynamics::getReunionText('Serana', $temperament, 10.0, 'Kaida');
        }
        $this->assertCount(count($texts), array_unique($texts), 'each temperament has its own short return, the fallback its own');
        $this->assertStringContainsString('slight nod', $texts['Independent']);
        $this->assertStringContainsString('glad to see', $texts['Romantic']);
    }

    public function testAnotherNpcsNameFillsTheSameProse(): void
    {
        $a = RelationshipDynamics::getReunionText('Aela', 'Stoic', 30.0, 'Kaida');
        $b = RelationshipDynamics::getReunionText('Lydia', 'Stoic', 30.0, 'Kaida');
        $this->assertSame(str_replace('Aela', 'Lydia', $a), $b);
    }
}
