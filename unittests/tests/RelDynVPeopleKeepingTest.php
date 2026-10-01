<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * The fear of losing the player, answered by who the NPC is (rulings 2026-10-01 §23, Ken): the response follows the NPC's own
 * character graph, with no moralising. A people-pleaser appeases and complies, even with a player who treats them badly; an
 * immature NPC whose way is control may start a conflict at the controlling band; others cling or withdraw; never a refused
 * command. RelDyn models the person, it does not judge the player. The pure halves, no database: the defaults, fixed game
 * timestamps. The four test beds through the real hooks are RelDynVPeopleTestBedsPostgresTest.
 */
final class RelDynVPeopleKeepingTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 300 * self::DAY + 12 * self::HOUR;

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
    }

    protected function tearDown(): void
    {
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function at(float $gamets): void
    {
        $GLOBALS['gameRequest'] = ['inputtext', (string) time(), (string) (int) round($gamets), 'Kaida: hello'];
    }

    /**
     * A partner of the player: attachment axes, maturity, self-confidence, traits; comfort and trust 60, core affinity 60 (a bond to
     * lose); jealous (so the fear is at its worst).
     */
    private function npc(array $axes, float $maturity = 50.0, float $confidence = 50.0, array $traits = [], float $jealousy = 90.0): array
    {
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], $traits);
        $x['maturity_start'] = $maturity;
        $d = RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics());
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src'] = ['assignment' => 'read'];
        $d['profile_overrides'] = ['attachment_axes' => ['anxiety' => $axes[0], 'avoidance' => $axes[1]]];
        $d['_aff_mirror_x'] = 50.0;
        RelationshipDynamics::setCoreAffinityValue($d, 60.0);
        foreach (['comfort' => 60.0, 'trust' => 60.0, 'maturity' => $maturity, 'self_confidence' => $confidence, 'jealousy' => $jealousy] as $k => $v) {
            $d['dimensions'][$k]['x'] = $v;
        }
        $d['_core_rel_type'] = 'romantic';
        return $d;
    }

    private const FEARFUL = [0.85, 0.85];
    private const ANXIOUS = [0.85, 0.15];
    private const AVOIDANT = [0.15, 0.85];
    private const SECURE = [0.15, 0.15];
    private const CONTROL = ['Po' => 0.95, 'C' => 0.5];   // a controlling way (the control style wins)

    private static function hasFear(array &$d, float $fear): void
    {
        $d['_keeping'] = ['v' => 1, 'fear' => $fear, 'applied' => ['trust' => 0.0, 'comfort' => 0.0]];
    }

    // =====================================================================
    // who the NPC is decides what the fear makes them do
    // =====================================================================

    public function testTheResponseFollowsWhoTheNpcIs(): void
    {
        $kind = fn(array $d) => RelDynKeeping::response($d)['kind'];
        $this->assertSame('appease', $kind($this->npc(self::FEARFUL, 20.0, 15.0)), 'low confidence and low maturity: a people-pleaser');
        $this->assertSame('appease', $kind($this->npc(self::AVOIDANT, 20.0, 15.0)), 'whatever their axes: the people-pleaser appeases');
        $this->assertSame('appease', $kind($this->npc(self::SECURE, 20.0, 15.0)));
        $this->assertNotSame('appease', $kind($this->npc(self::FEARFUL, 50.0, 15.0)), 'low confidence alone is not enough');
        $this->assertNotSame('appease', $kind($this->npc(self::FEARFUL, 20.0, 50.0)), 'low maturity alone is not enough');
        $this->assertSame('withdraw', $kind($this->npc(self::AVOIDANT, 55.0, 60.0)));
        $this->assertSame('withdraw', $kind($this->npc(self::AVOIDANT, 15.0, 60.0)), 'mature or not, the avoidant withdraw');
        $this->assertSame('express', $kind($this->npc(self::ANXIOUS, 55.0, 60.0)), 'the anxious cling, in their way');
        $this->assertSame('express', $kind($this->npc(self::SECURE, 55.0, 60.0)));
        $this->assertSame('express', $kind($this->npc(self::FEARFUL, 55.0, 60.0)), 'both axes high: reaching and shoving away, in their way');
        // continuous in the axes: a little more avoidant than anxious is not yet a withdrawal, well over is
        $this->assertSame('express', $kind($this->npc([0.5, 0.6], 55.0, 60.0)));
        $this->assertSame('withdraw', $kind($this->npc([0.4, 0.75], 55.0, 60.0)));
        $r = RelDynKeeping::response($this->npc(self::AVOIDANT, 55.0, 60.0));
        $this->assertEqualsWithDelta(0.7, $r['lean'], 1e-6);
        $this->assertSame('mature', $r['expression']['band']);
        // the knob
        $GLOBALS['db'] = new RelDynVPeopleKeepingConfigDb(['keeping' => ['response' => ['withdraw_at' => 0.9]]]);
        RelationshipDynamics::clearConfigCache();
        $this->assertSame('express', $kind($this->npc(self::AVOIDANT, 55.0, 60.0)));
    }

    // =====================================================================
    // what is said: behaviour, no verdict on the player, no numbers, no pronoun of its own
    // =====================================================================

    public function testEachResponseIsSaidAsBehaviourNeverAVerdictANumberOrAPronoun(): void
    {
        $now = self::T0;
        $people = [
            'appease'  => $this->npc(self::FEARFUL, 20.0, 15.0),
            'withdraw mature' => $this->npc(self::AVOIDANT, 80.0, 60.0),
            'withdraw raw'    => $this->npc(self::AVOIDANT, 20.0, 60.0),
            'cling mature'    => $this->npc(self::ANXIOUS, 80.0, 60.0),
            'control raw'     => $this->npc(self::FEARFUL, 15.0, 60.0, self::CONTROL),
        ];
        $seen = [];
        foreach (['uneasy' => 0.3, 'clinging' => 0.55, 'controlling' => 0.85] as $band => $fear) {
            foreach ($people as $who => $d) {
                self::hasFear($d, $fear);
                $line = RelDynKeeping::feltLine($d, 'Rowan', 'Kaida');
                $this->assertNotNull($line, "{$who} {$band}");
                $this->assertSame($band, $line['band']);
                $text = $line['text'];
                $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$who} {$band}: feelings, never numbers");
                $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|hers|him|himself|herself)\b/i', $text, "{$who} {$band}: no hard-coded pronoun");
                $this->assertDoesNotMatchRegularExpression('/\{[A-Z]+\}/', $text, "{$who} {$band}: every var filled");
                $this->assertStringContainsString('Rowan', $text);
                $this->assertStringContainsString('Kaida', $text);
                // RelDyn models the person: no verdict on the player, and never a refusal
                $this->assertDoesNotMatchRegularExpression('/\b(abus\w*|toxic|unfair|deserv\w*|cruel|manipulat\w*|refus\w*|will not do|won\'t do|denies|deny)\b/i', $text, "{$who} {$band}: no moralising, no refusal");
                $seen[$band][$who] = $text;
            }
            $this->assertCount(count($people), array_unique($seen[$band]), "{$band}: five characters, five ways");
        }
        $this->assertSame('appease', RelDynKeeping::feltLine($this->withFear($people['appease'], 0.5), 'Rowan', 'Kaida')['response']);
        $this->assertSame('withdraw', RelDynKeeping::feltLine($this->withFear($people['withdraw raw'], 0.5), 'Rowan', 'Kaida')['response']);
        $this->assertSame('express', RelDynKeeping::feltLine($this->withFear($people['cling mature'], 0.5), 'Rowan', 'Kaida')['response']);
        $this->assertStringContainsString('plainly', $seen['clinging']['withdraw mature'], 'the mature voice it');
        $this->assertStringContainsString('smooth', $seen['uneasy']['appease']);
        // the knob: a stored text replaces one and keeps the rest
        $GLOBALS['db'] = new RelDynVPeopleKeepingConfigDb(['keeping' => ['response_text' => ['appease_uneasy' => '{NAME} agrees to everything {PLAYER} says.']]]);
        RelationshipDynamics::clearConfigCache();
        $d = $this->withFear($people['appease'], 0.3);
        $this->assertSame('Rowan agrees to everything Kaida says.', RelDynKeeping::feltLine($d, 'Rowan', 'Kaida')['text']);
        $this->assertSame($seen['clinging']['appease'], RelDynKeeping::feltLine($this->withFear($people['appease'], 0.55), 'Rowan', 'Kaida')['text']);
    }

    private function withFear(array $d, float $fear): array
    {
        self::hasFear($d, $fear);
        return $d;
    }

    // =====================================================================
    // a people-pleaser appeases and complies, even with a player who treats them badly
    // =====================================================================

    public function testAPeoplePleaserAppeasesAndStaysCompliantWhateverThePlayerDoes(): void
    {
        $badly = ['trust' => 10.0, 'respect' => 5.0, 'resentment' => 80.0];
        $pleaser = $this->npc(self::FEARFUL, 20.0, 15.0, self::CONTROL);   // even a controlling streak in the traits: they appease
        $firm = $this->npc(self::FEARFUL, 65.0, 70.0);
        foreach ([&$pleaser, &$firm] as &$d) {
            foreach ($badly as $dim => $v) $d['dimensions'][$dim]['x'] = $v;
        }
        unset($d);
        // the same treatment: the firm NPC's autonomy refuses (that is the existing autonomy design), the people-pleaser complies
        $auto = RelationshipDynamics::evaluateAutonomyState($pleaser, 'Stoic');
        $this->assertSame('compliant', $auto['state'], 'complies');
        $this->assertTrue($auto['people_pleaser']);
        $this->assertContains(RelationshipDynamics::evaluateAutonomyState($firm, 'Stoic')['state'], ['refusing', 'walkaway']);
        // and the fear of losing the player makes them appease: the worst of it, over days, never a conflict, never a refusal
        $now = self::T0;
        $text = null;
        for ($i = 0; $i < 12; $i++) {
            $this->at($now);
            RelDynKeeping::advance('Rowan', $pleaser, $now);
            $now += 12 * self::HOUR;
        }
        $this->assertSame('controlling', RelDynKeeping::band(RelDynKeeping::fear($pleaser)), 'the fear is at its worst');
        $this->assertEmpty($pleaser['in_conflict'] ?? false, 'a people-pleaser never starts a conflict');
        $this->assertSame(0, intval($pleaser['_keeping']['conflict']['count'] ?? 0));
        $line = RelDynKeeping::feltLine($pleaser, 'Rowan', 'Kaida');
        $this->assertSame('appease', $line['response']);
        $this->assertStringContainsString('agree to anything', $line['text']);
        $this->assertSame('compliant', RelationshipDynamics::evaluateAutonomyState($pleaser, 'Stoic')['state'], 'still compliant at the controlling band');
        $this->assertSame([], RelationshipDynamics::getDeniedActions('compliant'), 'no action off the list');
    }

    // =====================================================================
    // an immature, controlling NPC may start a conflict at the controlling band
    // =====================================================================

    /** Hourly-ish steps; returns the game time reached. */
    private function step(array &$d, float $from, float $hours): float
    {
        $t = $from + $hours * self::HOUR;
        $this->at($t);
        RelDynKeeping::advance('Rowan', $d, $t);
        return $t;
    }

    public function testAnImmatureControllingNpcStartsAConflictOnceTheFearHasHeldAtItsWorst(): void
    {
        $d = $this->npc(self::FEARFUL, 15.0, 60.0, self::CONTROL);
        $this->assertSame('control', RelDynKeeping::expression($d)['style']);
        $this->assertSame('immature', RelDynKeeping::expression($d)['band']);
        $t = $this->step($d, self::T0, 0.0);
        $this->assertSame('controlling', RelDynKeeping::band(RelDynKeeping::fear($d)));
        $this->assertFalse(!empty($d['in_conflict']), 'not at once: the fear has to have held');
        $line = RelDynKeeping::feltLine($d, 'Rowan', 'Kaida');
        $this->assertSame('express', $line['response']);
        $this->assertStringContainsString('gripping hard', $line['text'], 'the grip, in words');
        $t = $this->step($d, $t, 3.0);
        $this->assertFalse(!empty($d['in_conflict']));
        $t = $this->step($d, $t, 4.0);
        $this->assertTrue(!empty($d['in_conflict']), 'it has held for a night: the NPC starts one');
        $this->assertTrue(RelDynKeeping::conflictOpen($d));
        $this->assertSame(1, $d['_keeping']['conflict']['count']);
        $line = RelDynKeeping::feltLine($d, 'Rowan', 'Kaida');
        $this->assertStringContainsString('started a fight', $line['text']);
        $this->assertStringContainsString('stay', $line['text']);
        // never refuses: the conflict changes nothing in what the autonomy design says of the NPC
        $with = $d;
        $without = $d;
        $without['in_conflict'] = false;
        unset($without['_keeping']['conflict']);
        $a = RelationshipDynamics::evaluateAutonomyState($with, 'Stoic');
        $b = RelationshipDynamics::evaluateAutonomyState($without, 'Stoic');
        $this->assertSame($b['state'], $a['state']);
        $this->assertSame($b['deny_actions'], $a['deny_actions'], 'no action taken off: the conflict is in the NPC\'s words, not a refused command');
        $this->assertSame($b['autonomy_score'], $a['autonomy_score']);
        // the NPC's own fight is no evidence the player is leaving: it does not feed the fear again
        $foreign = $d;
        unset($foreign['_keeping']['conflict']);   // as if the conflict came from elsewhere
        $own = RelDynKeeping::threat($d, $t);
        $other = RelDynKeeping::threat($foreign, $t);
        $this->assertGreaterThan($own['grievance'], $other['grievance'], 'a conflict the player caused counts, the NPC\'s own does not');
        // one at a time, and not again at once: the player repairs it (three warm exchanges), the cooldown holds
        $d['jealousy_anger'] = 0.0;
        for ($i = 0; $i < 3; $i++) RelationshipDynamics::recordConflictPositive($d);
        $this->assertFalse(!empty($d['in_conflict']), 'repaired');
        $t = $this->step($d, $t, 6.0);
        $this->assertFalse(!empty($d['in_conflict']), 'inside the cooldown: not again');
        $t = $this->step($d, $t, 3.0 * 24.0);
        $this->assertTrue(!empty($d['in_conflict']), 'the fear still at its worst after three days: again');
        $this->assertSame(2, $d['_keeping']['conflict']['count']);
    }

    public function testOnlyThoseWhoseWayItIsStartAFightTheRestShowTheFearInTheirOwn(): void
    {
        $startsOne = function (array $d): bool {
            $t = $this->step($d, self::T0, 0.0);
            for ($i = 0; $i < 6; $i++) $t = $this->step($d, $t, 6.0);
            return !empty($d['in_conflict']);
        };
        $this->assertTrue($startsOne($this->npc(self::FEARFUL, 15.0, 60.0, self::CONTROL)), 'immature, control');
        $this->assertFalse($startsOne($this->npc(self::FEARFUL, 15.0, 60.0, ['Po' => 0.1, 'E' => 0.05, 'C' => 0.9])), 'immature, a sulker');
        $this->assertFalse($startsOne($this->npc(self::FEARFUL, 15.0, 60.0, ['L' => 0.95, 'Pd' => 0.9, 'Po' => 0.5])), 'immature, an accuser: in words');
        $this->assertFalse($startsOne($this->npc(self::FEARFUL, 85.0, 60.0, self::CONTROL)), 'mature: says it plainly');
        $this->assertFalse($startsOne($this->npc(self::FEARFUL, 50.0, 60.0, self::CONTROL)), 'in between: sharp, not a fight');
        $this->assertFalse($startsOne($this->npc(self::AVOIDANT, 15.0, 60.0, self::CONTROL)), 'avoidant: withdraws');
        $this->assertFalse($startsOne($this->npc(self::FEARFUL, 20.0, 15.0, self::CONTROL)), 'a people-pleaser: appeases');
        // not at the controlling band: a worry is not a fight
        $calm = $this->npc(self::FEARFUL, 15.0, 60.0, self::CONTROL, 0.0);
        $t = $this->step($calm, self::T0, 0.0);
        for ($i = 0; $i < 6; $i++) $t = $this->step($calm, $t, 6.0);
        $this->assertNotSame('controlling', RelDynKeeping::band(RelDynKeeping::fear($calm)));
        $this->assertFalse(!empty($calm['in_conflict']));
        // the switch
        $GLOBALS['db'] = new RelDynVPeopleKeepingConfigDb(['keeping' => ['response' => ['conflict' => ['enabled' => false]]]]);
        RelationshipDynamics::clearConfigCache();
        $this->assertFalse($startsOne($this->npc(self::FEARFUL, 15.0, 60.0, self::CONTROL)), 'off: it stays in the words');
    }

    public function testTheStrainOfTheFearIsNeverReadAsDistrustSoNoCommandIsRefusedForIt(): void
    {
        // the grip holds trust down by up to 12 points (a standing offset); autonomy reads trust without it
        $held = $this->npc(self::FEARFUL, 55.0, 60.0);
        $held['dimensions']['trust']['x'] = 45.0;
        $held['_keeping'] = ['v' => 1, 'fear' => 0.9, 'applied' => ['trust' => -12.0, 'comfort' => -8.0]];
        $free = $this->npc(self::FEARFUL, 55.0, 60.0);
        $free['dimensions']['trust']['x'] = 57.0;
        $worse = $this->npc(self::FEARFUL, 55.0, 60.0);
        $worse['dimensions']['trust']['x'] = 45.0;   // the same low trust from anything else: that does count
        $a = RelationshipDynamics::evaluateAutonomyState($held, 'Stoic');
        $b = RelationshipDynamics::evaluateAutonomyState($free, 'Stoic');
        $c = RelationshipDynamics::evaluateAutonomyState($worse, 'Stoic');
        $this->assertSame($b['autonomy_score'], $a['autonomy_score'], 'trust without the held strain');
        $this->assertGreaterThan($b['autonomy_score'], $c['autonomy_score'], 'distrust from anything else still counts');
        // the one who is not held: unchanged by the new read
        $this->assertSame(RelationshipDynamics::evaluateAutonomyState($free, 'Stoic'), RelationshipDynamics::evaluateAutonomyState($free + ['_keeping' => ['v' => 1, 'fear' => 0.0, 'applied' => ['trust' => 0.0]]], 'Stoic'));
    }

    public function testJevCarriesTheResponse(): void
    {
        $d = $this->npc(self::FEARFUL, 15.0, 60.0, self::CONTROL);
        $t = $this->step($d, self::T0, 0.0);
        $this->step($d, $t, 7.0);
        $jev = RelDynKeeping::jev($d);
        $this->assertSame('express', $jev['response']);
        $this->assertSame(true, $jev['conflict']['open']);
        $this->assertSame(1, $jev['conflict']['count']);
        $this->assertEqualsWithDelta(0.0, $jev['lean'], 1e-6);
        $this->assertSame('appease', RelDynKeeping::jev($this->npc(self::FEARFUL, 20.0, 15.0))['response']);
        $this->assertSame('withdraw', RelDynKeeping::jev($this->npc(self::AVOIDANT, 55.0, 60.0))['response']);
    }
}

/** Just enough of `sql` for a stored RelDyn config row (the stored settings over the defaults). */
final class RelDynVPeopleKeepingConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode(array_merge(RelationshipDynamics::defaultConfig(), $this->config))] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
}
