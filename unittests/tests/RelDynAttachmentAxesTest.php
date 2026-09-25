<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/eval_producer.php';

/**
 * `sql` stand-in holding core_npc_master rows (as PostgreSQL returns them, jsonb as text) and an
 * optional stored RelDyn config; every other read finds nothing.
 */
final class RelDynAttachmentAxesCoreDb
{
    public function __construct(private array $rows, private ?array $config = null) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (str_contains($sql, "conf_opts WHERE id = 'relationship_dynamics_config'")) {
            return $this->config !== null ? ['value' => json_encode($this->config)] : [];
        }
        if (!str_contains($sql, 'FROM core_npc_master')) return [];
        foreach ($this->rows as $name => $row) {
            if ((is_array($params) && strcasecmp((string) ($params[0] ?? ''), $name) === 0)
                || stripos($sql, "lower('" . $this->escape($name) . "')") !== false) {
                return $row;
            }
        }
        return [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * attachment-two-axes (decisions 2026-09-24 §12, MDD 6.1, Fraley & Shaver 2000): attachment is
 * anxiety x avoidance (0..1 each), derived per NPC from its own evidence (class/role, traits,
 * attachment-behaviour words in the profile, temperament only as a weak prior, loss history),
 * with presets and per-NPC overrides; the named style is the region; every consumer reads the
 * axes (label tables are the corners, blended between); the axes drift with sustained
 * experience on the game calendar (Earned Security).
 *
 * NPCs are derived from core_npc_master-shaped rows through the real profile auto-generation
 * (ensureLoveLanguage -> ensureTemperamentProfile); no name checks except the named presets.
 * Real engine code; the database is a stub holding those rows.
 */
final class RelDynAttachmentAxesTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = self::DAY / 24;
    private const T0 = 420 * self::DAY + 9 * self::HOUR;

    private array $saved = [];
    private string $errorLog;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME', 'RELDYN_PLAYER_NAME', 'RELDYN_INTERACTION_SIGNIFICANCE',
                  'RELDYN_ATTACHMENT_CONFLICT_PASSION'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $GLOBALS['db'] = new RelDynAttachmentAxesCoreDb([]);
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdattach');
        $this->prevLog = ini_set('error_log', $this->errorLog);
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->errorLog);
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ core-shaped NPCs

    /** A core_npc_master row as the plugin registers the NPC (no RelDyn state). */
    private static function row(string $name, string $race, string $class, array $factions, array $skills, string $personality = ''): array
    {
        $all = array_fill_keys(['archery', 'block', 'onehanded', 'twohanded', 'conjuration', 'destruction',
            'restoration', 'alteration', 'illusion', 'heavyarmor', 'lightarmor', 'lockpicking', 'pickpocket',
            'sneak', 'speech', 'smithing', 'alchemy', 'enchanting'], '15');
        $f = [];
        foreach ($factions as $i => $faction) {
            $f[] = ['formid' => sprintf('0x%08x', 0x72834 + $i), 'rank' => 0, 'name' => $faction];
        }
        return [
            'npc_name' => $name, 'gender' => 'female', 'race' => $race, 'voiceid' => 'FemaleEvenToned',
            'personality' => $personality, 'speechstyle' => '', 'core' => "Roleplay as {$name}", 'npc_static_bio' => '',
            'metadata' => json_encode(['skills' => array_merge($all, array_map('strval', $skills))]),
            'extended_data' => json_encode(['class' => ['name' => $class, 'formid' => '0x0001317f'], 'factions' => $f,
                'relationships' => ['Player' => ['aff' => 40, 'type' => 'romantic']]]),
        ];
    }

    /** Aela-like: a Nord huntress of the Companions' Circle. */
    private static function huntress(string $name = 'Huntress'): array
    {
        return self::row($name, 'NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle', 'CurrentFollowerFaction'],
            ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52, 'onehanded' => 45]);
    }

    /** A Guarded scholar-mage follower; $personality adds profile text. */
    private static function scholar(string $name = 'Scholar', string $personality = ''): array
    {
        return self::row($name, 'BretonRace', 'Sorcerer', ['CurrentFollowerFaction', 'PotentialFollowerFaction'],
            ['destruction' => 62, 'alteration' => 58, 'enchanting' => 55, 'conjuration' => 40, 'restoration' => 35], $personality);
    }

    /** Ashe-like (spoiler-free, decisions §12): Guarded, and she stonewalls and withdraws when pressed. */
    private static function stonewallingScholar(string $name = 'Wary Scholar'): array
    {
        return self::scholar($name, 'Reserved and wary of strangers. She stonewalls when pressed about herself '
            . 'and withdraws from affection she did not ask for.');
    }

    /** The NPC as RelDyn first meets it: the real profile auto-generation. */
    private function derived(array $rows, string $name, array $state = [], ?array $config = null): array
    {
        $GLOBALS['db'] = new RelDynAttachmentAxesCoreDb($rows, $config);
        RelationshipDynamics::clearConfigCache();
        $d = RelationshipDynamics::migrateDimensions(array_replace(RelationshipDynamics::defaultDynamics(), $state));
        RelationshipDynamics::ensureLoveLanguage($name, $d);
        $d['_core_rel_type'] = $d['_core_rel_type'] ?? 'romantic';
        return $d;
    }

    /** A pure state NPC pinned to a style's textbook point (explicit override). */
    private static function textbook(string $style, array $extra = []): array
    {
        return array_replace_recursive(RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics()),
            ['inferred_temperament' => 'Humble', 'profile_overrides' => ['attachment_style' => $style]], $extra);
    }

    private static function at(float $anxiety, float $avoidance, array $extra = []): array
    {
        return array_replace_recursive(RelationshipDynamics::migrateDimensions(RelationshipDynamics::defaultDynamics()),
            ['inferred_temperament' => 'Humble', 'profile_overrides' => ['attachment_axes' => ['anxiety' => $anxiety, 'avoidance' => $avoidance]]], $extra);
    }

    private static function axes(array $d): array
    {
        $a = RelationshipDynamics::getAttachmentAxes($d);
        return [$a['anxiety'], $a['avoidance']];
    }

    private static function evalItem(string $npc, float $gamets, array $tags, float $significance = 0.8, array $signals = []): array
    {
        return [
            'v' => 1, 'npc' => $npc, 'npc_id' => 9, 'gamets' => (int) $gamets, 'source' => 'reldyn_eval',
            'signals' => array_replace(['affinity' => 1, 'trust' => 0, 'comfort' => 1, 'respect' => 0, 'passion' => 0, 'maturity' => 0], $signals),
            'tags' => $tags,
            'grievance' => ['flag' => false, 'kind' => null, 'severity' => 0],
            'jealousy' => ['flag' => false, 'rival' => null, 'intensity' => 0],
            'significance' => $significance, 'positive_interaction' => !array_intersect($tags, ['betrayal', 'lie']),
            'summary' => "{$npc}: " . implode(', ', $tags) . " at {$gamets}",
        ];
    }

    // ------------------------------------------------------------------ derivation

    public function testAelaLikeHuntressIsSecureLeaningFromHerCoreRow(): void
    {
        $d = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        [$anx, $avo] = self::axes($d);
        $this->assertSame('Ranger', $d['_profile_autogen']['archetype']);
        $this->assertSame('derived', $d['_profile_autogen']['attachment_source']);
        $this->assertEqualsWithDelta(0.15, $anx, 1e-9, 'low anxiety');
        $this->assertEqualsWithDelta(0.35, $avo, 1e-9, 'moderate avoidance: a self-reliant hunter copes through action (Ranger + Independent)');
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($d), 'secure-leaning, not avoidant');
        $this->assertContains('archetype:Ranger', $d['_profile_autogen']['attachment']['signals']);
        $this->assertArrayNotHasKey('attachment_style', $d, 'no label is stored: the axes are read from the profile');
    }

    public function testGuardedIsNotAvoidant(): void
    {
        $d = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        $this->assertSame('Guarded', $d['inferred_temperament']);
        $this->assertSame([0.15, 0.15], self::axes($d), 'a Guarded scholar with no avoidance evidence is textbook secure');
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($d));

        // Guarded adds nothing to either axis, whatever else the NPC is
        $cfg = RelationshipDynamics::getAttachmentConfig();
        foreach ([null, 'Ranger', 'Assassin', 'Mage'] as $arch) {
            foreach ([[], ['insecure'], ['egocentric']] as $traits) {
                $in = ['archetype' => $arch, 'traits' => $traits, 'losses' => 0];
                $plain = RelationshipDynamics::deriveAttachmentAxes($in + ['temperament' => null], $cfg);
                $guarded = RelationshipDynamics::deriveAttachmentAxes($in + ['temperament' => 'Guarded'], $cfg);
                $this->assertSame([$plain['anxiety'], $plain['avoidance']], [$guarded['anxiety'], $guarded['avoidance']]);
            }
        }
        $this->assertArrayNotHasKey('Guarded', $cfg['derive']['temperament']);
        // ... nor do Stoic / Independent / Proud / Defiant make anyone avoidant on their own (the April map)
        foreach (['Stoic', 'Independent', 'Proud', 'Defiant', 'Guarded'] as $t) {
            $a = RelationshipDynamics::deriveAttachmentAxes(['temperament' => $t, 'traits' => [], 'archetype' => null], $cfg);
            $this->assertSame('secure', RelationshipDynamics::attachmentStyleOf($a['anxiety'], $a['avoidance']), $t);
            $this->assertLessThanOrEqual(0.1 + 1e-9, $a['avoidance'] - 0.15, "{$t}: a weak prior at most");
        }
    }

    public function testAsheLikeGuardedScholarWhoStonewallsHasModerateAvoidance(): void
    {
        $d = $this->derived(['Wary Scholar' => self::stonewallingScholar()], 'Wary Scholar');
        $this->assertSame('Guarded', $d['inferred_temperament'], 'reserved, wary: hard to get in');
        [$anx, $avo] = self::axes($d);
        $this->assertLessThan(0.3, $anx, 'low-moderate anxiety');
        $this->assertGreaterThanOrEqual(0.3, $avo, 'the stonewalling and withdrawing are avoidance evidence');
        $this->assertLessThan(0.5, $avo);
        $this->assertSame(['anxiety' => 0, 'avoidance' => 2], $d['_profile_autogen']['attachment_text']);
        $plain = $this->derived(['Scholar' => self::scholar()], 'Scholar');
        $this->assertGreaterThan(self::axes($plain)[1], $avo, 'the same Guarded scholar without that behaviour is not');
    }

    public function testEachPieceOfEvidenceMovesItsOwnAxis(): void
    {
        $cfg = RelationshipDynamics::getAttachmentConfig();
        $base = ['archetype' => null, 'temperament' => 'Humble', 'traits' => [], 'losses' => 0, 'text_hits' => []];
        $d0 = RelationshipDynamics::deriveAttachmentAxes($base, $cfg);
        $with = fn(array $o) => RelationshipDynamics::deriveAttachmentAxes(array_replace($base, $o), $cfg);

        $ins = $with(['traits' => ['insecure']]);
        $this->assertGreaterThan($d0['anxiety'] + 0.3, $ins['anxiety'], 'insecure is attachment anxiety');
        $this->assertSame($d0['avoidance'], $ins['avoidance']);
        $ego = $with(['traits' => ['egocentric']]);
        $this->assertGreaterThan($d0['avoidance'], $ego['avoidance'], 'egocentric keeps a little distance');
        $this->assertSame($d0['anxiety'], $ego['anxiety']);
        $this->assertGreaterThan($d0['anxiety'], $with(['temperament' => 'Anxious'])['anxiety'], 'Anxious temperament: a prior on anxiety');
        $ind = $with(['temperament' => 'Independent']);
        $this->assertEqualsWithDelta($d0['avoidance'] + 0.1, $ind['avoidance'], 1e-9, 'Independent raises avoidance a little');
        $this->assertGreaterThan($d0['avoidance'], $with(['archetype' => 'Assassin'])['avoidance'], 'class / role');
        $loss = $with(['losses' => 2]);
        $this->assertGreaterThan($d0['anxiety'], $loss['anxiety'], 'loss history');
        $this->assertGreaterThan($d0['avoidance'], $loss['avoidance']);
        $this->assertEquals($with(['losses' => 3]), $with(['losses' => 9]), 'losses count up to loss_max_bonds');
        $this->assertGreaterThan($d0['anxiety'], $with(['text_hits' => ['anxiety' => 1]])['anxiety'], 'clingy / needy words');
        $this->assertSame($with(['text_hits' => ['avoidance' => 3]]), $with(['text_hits' => ['avoidance' => 7]]), 'at most text_max_hits');

        // Anxious / Jealous temperaments carry the insecure trait: anxious, as before
        $anxious = $this->derived(['Nervous' => self::row('Nervous', 'ImperialRace', 'Citizen', [], [], 'A timid, nervous and clingy woman.')], 'Nervous');
        $this->assertSame('Anxious', $anxious['inferred_temperament']);
        $this->assertSame('anxious', RelationshipDynamics::getAttachmentStyle($anxious));
    }

    public function testTheDerivationIsDeterministicAndNeverFearful(): void
    {
        $a = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $b = $this->derived(['Huntress' => self::huntress()], 'Huntress');
        $this->assertSame(RelationshipDynamics::getAttachmentAxes($a), RelationshipDynamics::getAttachmentAxes($b));

        // Everything at once: an insecure, egocentric, Anxious assassin who lost three people
        $worst = RelationshipDynamics::deriveAttachmentAxes(['archetype' => 'Assassin', 'temperament' => 'Anxious',
            'traits' => ['insecure', 'egocentric'], 'losses' => 3, 'text_hits' => ['anxiety' => 3, 'avoidance' => 3]]);
        $this->assertNotSame('toxic', RelationshipDynamics::attachmentStyleOf($worst['anxiety'], $worst['avoidance']),
            'Toxic/Disorganized is never derived (MDD 6.1 pipeline)');
        $this->assertContains('not_fearful', $worst['signals']);
    }

    public function testNamedPresetsAndOverrides(): void
    {
        $aela = $this->derived(['Aela the Huntress' => self::huntress('Aela the Huntress')], 'Aela the Huntress');
        $this->assertSame([0.15, 0.35], self::axes($aela));
        $this->assertSame('preset', $aela['_profile_autogen']['attachment_source']);
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($aela));

        $ashe = $this->derived(['Ashe' => self::scholar('Ashe')], 'Ashe');
        $this->assertSame([0.3, 0.5], self::axes($ashe));
        $this->assertSame('Guarded', $ashe['inferred_temperament']);
        $this->assertSame('avoidant', RelationshipDynamics::getAttachmentStyle($ashe), 'at the threshold: guarded closeness that can ease');

        // An explicit override beats the preset: axes, or a label read as its textbook point
        $this->assertTrue(RelationshipDynamics::setProfileOverride($ashe, 'attachment_axes', ['anxiety' => 0.2, 'avoidance' => 0.25]));
        $this->assertSame([0.2, 0.25], self::axes($ashe));
        $this->assertTrue(RelationshipDynamics::setProfileOverride($ashe, 'attachment_style', 'anxious'));
        $this->assertSame([0.85, 0.15], self::axes($ashe));
        $this->assertArrayNotHasKey('attachment_axes', $ashe['profile_overrides'], 'the two overrides replace each other');
        $this->assertTrue(RelationshipDynamics::setProfileOverride($ashe, 'attachment_style', null));
        $this->assertSame([0.3, 0.5], self::axes($ashe), 'cleared: the preset again');
        $this->assertFalse(RelationshipDynamics::setProfileOverride($ashe, 'attachment_axes', ['anxiety' => 1.4, 'avoidance' => 0.2]));
        $this->assertFalse(RelationshipDynamics::setProfileOverride($ashe, 'attachment_style', 'clingy'));

        // Fresh start: a stored label (April auto-map, earlier builds) is not read
        $old = $this->derived(['Scholar' => self::scholar()], 'Scholar', ['attachment_style' => 'avoidant']);
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($old));
        $this->assertArrayNotHasKey('attachment_style', $old);
    }

    /** @return array<string, array{float, float, string}> */
    public static function labelPoints(): array
    {
        return [
            'origin'               => [0.0, 0.0, 'secure'],
            'just below both'      => [0.4999, 0.4999, 'secure'],
            'anxiety at threshold' => [0.5, 0.2, 'anxious'],
            'avoidance at threshold' => [0.2, 0.5, 'avoidant'],
            'both at threshold'    => [0.5, 0.5, 'toxic'],
            'textbook anxious'     => [0.85, 0.15, 'anxious'],
            'textbook avoidant'    => [0.15, 0.85, 'avoidant'],
            'textbook fearful'     => [0.85, 0.85, 'toxic'],
            'Aela preset'          => [0.15, 0.35, 'secure'],
            'Ashe preset'          => [0.3, 0.5, 'avoidant'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('labelPoints')]
    public function testTheStyleIsTheRegionOfTheAxes(float $anxiety, float $avoidance, string $style): void
    {
        $this->assertSame(['anxiety' => 0.5, 'avoidance' => 0.5], RelationshipDynamics::getAttachmentConfig()['thresholds']);
        $this->assertSame($style, RelationshipDynamics::attachmentStyleOf($anxiety, $avoidance));
        $this->assertSame($style, RelationshipDynamics::getAttachmentStyle(self::at($anxiety, $avoidance)));
    }

    public function testAttachmentOffReadsSecure(): void
    {
        $GLOBALS['db'] = new RelDynAttachmentAxesCoreDb([], array_merge(RelationshipDynamics::defaultConfig(), ['attachment_style_enabled' => false]));
        RelationshipDynamics::clearConfigCache();
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle(self::textbook('toxic')));
        $this->assertSame([0.15, 0.15], self::axes(self::textbook('anxious')));
    }

    // ------------------------------------------------------------------ consumers read the axes

    /**
     * Every label-keyed consumer, read at each style's textbook point, returns exactly the value
     * its April table gave that style (behaviour kept for a textbook NPC of each label).
     */
    public function testATextbookNpcOfEachStyleKeepsTodaysValues(): void
    {
        $april = [
            // style => [jealousy_mult, affinity_absence_mult, absence_comfort_delta, resentment_gain_mult,
            //           codependence A, passion absence mult, intimacy decay rate, attraction passion mult,
            //           attraction pace, romance momentum mult, masking cost mult]
            'secure'   => [1.0, 1.0, 0.0, 1.0, 0.5, 1.0, 1.0, 1.0, 1.0, 1.0, 1.0],
            'anxious'  => [2.0, 2.0, -1.0, 1.5, 1.0, 2.0, 2.0, 1.3, 0.6, 0.5, 1.5],
            'avoidant' => [0.5, 0.5, 0.5, 1.0, 0.0, 0.5, 0.5, 0.7, 1.4, 2.0, 0.5],
            'toxic'    => [1.5, 1.0, 0.0, 1.3, 1.0, 1.0, 1.5, 1.2, 0.8, 1.0, 1.0],
        ];
        $neglect = RelationshipDynamics::getNeglectSeverityConfig();
        $att = RelDynAttraction::config();
        $rom = RelDynRomance::config();
        foreach ($april as $style => $want) {
            $d = self::textbook($style);
            $got = [
                RelationshipDynamics::getAttachmentModifier($d, 'jealousy_mult'),
                RelationshipDynamics::getAttachmentModifier($d, 'affinity_absence_mult'),
                RelationshipDynamics::getAttachmentModifier($d, 'absence_comfort_delta'),
                RelationshipDynamics::getAttachmentModifier($d, 'resentment_gain_mult'),
                RelationshipDynamics::attachmentBlend($d, $neglect['codependence_attachment'], 0.5),
                RelationshipDynamics::attachmentBlend($d, RelationshipDynamics::configValue('passion_absence_attachment_mult'), 1.0),
                RelDynIntimacy::decayRates($d)[RelDynIntimacy::PHYSICAL] ?? 1.0,
                RelationshipDynamics::attachmentBlend($d, $att['passion']['attachment_mult'], 1.0),
                RelationshipDynamics::attachmentBlend($d, $att['advance']['attachment_pace'], 1.0),
                RelationshipDynamics::attachmentBlend($d, $rom['momentum_attachment_mult'], 1.0),
                RelationshipDynamics::attachmentBlend($d, ['avoidant' => 0.5, 'anxious' => 1.5], 1.0),
            ];
            foreach ($want as $i => $w) {
                $this->assertEqualsWithDelta($w, $got[$i], 1e-9, "{$style} #{$i}");
            }
            $this->assertSame($style === 'toxic' ? 30 : null, RelationshipDynamics::getAttachmentModifier($d, 'maturity_floor'));
            $this->assertEqualsWithDelta($style === 'toxic' ? 5.0 : 0.0, RelationshipDynamics::getAttachmentModifier($d, 'conflict_passion_gain'), 1e-9);
        }
        // The M table rows (decisions §1) at the corners
        $m = fn(string $style, float $raw, array $tags, array $extra = []) => RelationshipDynamics::affinityModifiers(self::textbook($style, $extra), $raw, $tags)['rows'];
        $this->assertEqualsWithDelta(2.0, $m('anxious', -10, ['neglect'])['anxious_abandonment'], 1e-9);
        $this->assertEqualsWithDelta(1.3, $m('anxious', 10, ['quality_time'])['anxious_reassurance'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $m('avoidant', -10, ['neglect'])['avoidant_neglect'], 1e-9);
        $this->assertEqualsWithDelta(0.6, $m('avoidant', 10, ['touch'], ['dimensions' => ['comfort' => ['x' => 40]]])['avoidant_closeness'], 1e-9);
        $this->assertEqualsWithDelta(1.4, $m('toxic', 10, ['help'])['toxic_all'], 1e-9);
        $this->assertSame([], array_intersect(array_keys($m('secure', -10, ['neglect'])), ['anxious_abandonment', 'avoidant_neglect', 'toxic_all']));
        // Categorical choices read the region
        $this->assertSame('manipulative', RelationshipDynamics::getRefusalType(self::textbook('toxic')));
        $this->assertTrue(RelationshipDynamics::isHooverSleeper(self::textbook('toxic', ['dimensions' => ['maturity' => ['x' => 20]]])));
        $this->assertFalse(RelationshipDynamics::isHooverSleeper(self::textbook('anxious', ['dimensions' => ['maturity' => ['x' => 20]]])));
    }

    public function testBetweenTheCornersTheConsumersReadTheAxesContinuously(): void
    {
        // Halfway from secure to anxious (u = 0.5): half of each anxious effect
        $half = self::at(0.5, 0.15);
        $this->assertEqualsWithDelta(1.5, RelationshipDynamics::getAttachmentModifier($half, 'jealousy_mult'), 1e-9);
        $this->assertEqualsWithDelta(1.5, RelationshipDynamics::affinityModifiers($half, -10, ['neglect'])['rows']['anxious_abandonment'], 1e-9);
        $this->assertEqualsWithDelta(1.5, RelDynIntimacy::decayRates($half)[RelDynIntimacy::EMOTIONAL], 1e-9);
        $this->assertEqualsWithDelta(0.75, RelationshipDynamics::attachmentBlend($half,
            RelationshipDynamics::getNeglectSeverityConfig()['codependence_attachment'], 0.5), 1e-9, 'neglect codependence A');
        // Monotone along each axis
        $prev = null;
        foreach ([0.15, 0.3, 0.5, 0.7, 0.85] as $avo) {
            $j = RelationshipDynamics::getAttachmentModifier(self::at(0.15, $avo), 'jealousy_mult');
            if ($prev !== null) $this->assertLessThan($prev, $j, 'more avoidance, less jealousy');
            $prev = $j;
        }
        // The fearful protocol is a region: an NPC near but outside it never manufactures conflict
        $near = self::at(0.49, 0.49);
        $this->assertEqualsWithDelta(0.0, RelationshipDynamics::getAttachmentModifier($near, 'conflict_passion_gain'), 1e-9);
        $this->assertNull(RelationshipDynamics::getAttachmentModifier($near, 'maturity_floor'));
        $this->assertNotSame('manipulative', RelationshipDynamics::getRefusalType($near));
    }

    public function testAelaIntimacyWearsOffAtANearlyNormalRate(): void
    {
        $aela = $this->derived(['Aela the Huntress' => self::huntress('Aela the Huntress')], 'Aela the Huntress');
        $rate = RelDynIntimacy::decayRates($aela)[RelDynIntimacy::PHYSICAL] ?? 1.0;
        $this->assertGreaterThanOrEqual(0.85, $rate, 'decisions §12: a normal rate, not avoidant half speed');
        $this->assertLessThanOrEqual(1.0, $rate);
        // Before §12 she was avoidant by the temperament map: half speed
        $april = $this->derived(['Aela the Huntress' => self::huntress('Aela the Huntress')], 'Aela the Huntress',
            ['profile_overrides' => ['attachment_style' => 'avoidant']]);
        $this->assertSame(0.5, RelDynIntimacy::decayRates($april)[RelDynIntimacy::PHYSICAL]);
    }

    /**
     * Decisions §12: a hug is a little physical intimacy, not a lot; with Aela's intimacy wearing
     * off at a near-normal rate, a daily hug no longer keeps her physical need covered forever
     * (at the April avoidant half speed it did).
     */
    public function testDailyHugsNoLongerCoverHerPhysicalNeedForever(): void
    {
        $touch = RelDynFulfillment::evalItemAmounts(RelationshipDynamics::normalizeEvalContractItem(self::evalItem('X', self::T0, ['touch'], 1.0)));
        $night = RelDynFulfillment::evalItemAmounts(RelationshipDynamics::normalizeEvalContractItem(self::evalItem('X', self::T0, ['intimacy'], 1.0)));
        $this->assertGreaterThan(0.0, $touch[RelDynIntimacy::PHYSICAL]);
        $this->assertLessThanOrEqual(0.15, $touch[RelDynIntimacy::PHYSICAL], 'a hug: a small physical delivery');
        $this->assertGreaterThanOrEqual(5 * $touch[RelDynIntimacy::PHYSICAL], $night[RelDynIntimacy::PHYSICAL]);

        $run = function (array $state) {
            $d = $this->derived(['Aela the Huntress' => self::huntress('Aela the Huntress')], 'Aela the Huntress', $state);
            RelDynIntimacy::ensureNeed('Aela the Huntress', $d);
            $this->assertTrue(RelDynFulfillment::ensure($d, RelDynFacets::neutralPreferences(), self::T0));
            for ($day = 0; $day < 20; $day++) {
                RelationshipDynamics::processEvalContractItem('Aela the Huntress',
                    self::evalItem('Aela the Huntress', self::T0 + $day * self::DAY + 3 * self::HOUR, ['touch'], 0.8), $d);
            }
            return RelDynIntimacy::axesAt($d, self::T0 + 20 * self::DAY)[RelDynIntimacy::PHYSICAL];
        };
        $now = $run([]);
        $this->assertTrue($now['deprived'], 'twenty days of hugs and nothing more: she is still missing it');
        $april = $run(['profile_overrides' => ['attachment_style' => 'avoidant']]);
        $this->assertFalse($april['deprived'], 'at the April avoidant half speed the same hugs covered it');
    }

    public function testJevGetsTheAxesAsNumbersTheEvalGetsBehaviour(): void
    {
        $ashe = $this->derived(['Ashe' => self::scholar('Ashe')], 'Ashe');
        $jev = RelDynJev::state('Ashe', $ashe, self::T0);
        $this->assertSame(0.3, $jev['attachment_anxiety']);
        $this->assertSame(0.5, $jev['attachment_avoidance']);
        $this->assertSame('avoidant', $jev['attachment']);
        $this->assertStringContainsString('attachment=avoidant(anxiety 0.30 avoidance 0.50)', $jev['text']);
        $this->assertArrayHasKey('attachment_avoidance', $jev['units']);

        foreach (['secure', 'anxious', 'avoidant', 'toxic'] as $style) {
            $lines = implode("\n", RelDynEval::stateSummary('Ashe', self::textbook($style)));
            $this->assertStringContainsString('in closeness: ', $lines);
            $this->assertDoesNotMatchRegularExpression('/\b(secure|anxious|avoidant|toxic|fearful|attachment)\b/i', $lines, "{$style}: behaviour, never a style name");
            $this->assertStringNotContainsString('0.', $lines, 'never numbers');
        }
        $this->assertStringContainsString('a little keeps some distance', RelationshipDynamics::attachmentFeltText(self::at(0.2, 0.5)));
        $this->assertStringStartsWith('keeps some distance', RelationshipDynamics::attachmentFeltText(self::textbook('avoidant')));
    }

    // ------------------------------------------------------------------ drift (Earned Security)

    /** Ashe (preset: anxiety 0.3, avoidance 0.5) in a romance, trust earned, at game day T0. */
    private function asheInRomance(float $trust = 80.0): array
    {
        $d = $this->derived(['Ashe' => self::scholar('Ashe')], 'Ashe');
        $d['dimensions']['trust']['x'] = $trust;
        return $d;
    }

    /**
     * $days days of consistent fulfillment: each a contact, two attentive exchanges and an
     * afternoon of what she loves together (an Arcanaeum-like library, then a short delve:
     * facets delivered at place_units_per_game_hour per hour), the fulfillment calendar stepped.
     */
    private function attentiveDays(string $name, array &$d, float $from, int $days): void
    {
        $perHour = floatval(RelDynFulfillment::config()['place_units_per_game_hour']);
        for ($k = 0; $k < $days; $k++) {
            $t = $from + $k * self::DAY;
            $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) $t, "Kaida: hello {$name}"];
            RelationshipDynamics::advanceFulfillment($name, $d, $t, true);
            RelationshipDynamics::markContact($d);
            RelationshipDynamics::processEvalContractItem($name, self::evalItem($name, $t + self::HOUR, ['quality_time', 'praise']), $d);
            RelationshipDynamics::processEvalContractItem($name, self::evalItem($name, $t + 3 * self::HOUR, ['quality_time', 'reassurance', 'confiding']), $d);
            RelDynFulfillment::recordFacets($d, ['scholarly' => 1.0, 'quiet' => 0.8, 'enchanting' => 0.6, 'confined' => 0.4], 3 * $perHour, $t + 5 * self::HOUR);
            RelDynFulfillment::recordFacets($d, ['adventure' => 0.8, 'danger' => 0.5], $perHour, $t + 7 * self::HOUR);
        }
    }

    public function testSustainedFulfillmentAndRepairEarnSecurityOverGameDays(): void
    {
        $d = $this->asheInRomance();
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) self::T0, 'Kaida: hello'];
        [$anx0, $avo0] = self::axes($d);
        $this->attentiveDays('Ashe', $d, self::T0, 10);
        [$anx10, $avo10] = self::axes($d);
        $this->assertLessThan($avo0, $avo10, 'ten fulfilled days bring her avoidance down');
        $this->assertLessThan($anx0, $anx10);
        $this->assertGreaterThan($avo0 - 0.1, $avo10, '... slowly');

        // A fight, repaired through contact: repair lowers it a step more
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) (self::T0 + 10 * self::DAY), 'Kaida: I am sorry'];
        RelationshipDynamics::enterConflict($d);
        for ($i = 0; $i < 3; $i++) RelationshipDynamics::recordConflictPositive($d);
        $this->assertEmpty($d['in_conflict']);
        $this->assertSame('repair', end($d['_attachment_drift']['log'])['why']);
        $this->assertLessThan($avo10, self::axes($d)[1]);

        $this->attentiveDays('Ashe', $d, self::T0 + 11 * self::DAY, 30);
        [$anx, $avo] = self::axes($d);
        $this->assertSame('secure', RelationshipDynamics::getAttachmentStyle($d), 'earned security (MDD 6.1)');
        $this->assertLessThanOrEqual(0.4, $avo, 'forty game days of it: slow roots, but it moved');
        $this->assertSame([0.3, 0.5], [RelationshipDynamics::getAttachmentAxes($d)['base']['anxiety'], RelationshipDynamics::getAttachmentAxes($d)['base']['avoidance']],
            'the base is who she was; the drift is what the bond did');
        $this->assertLessThanOrEqual(0.4 + 1e-9, 0.5 - $avo, 'never further than max_from_base');
        // bounded per game day, every day
        foreach ($d['_attachment_drift']['log'] as $e) {
            $this->assertLessThanOrEqual(0.03 + 1e-9, abs($e['avoidance'] ?? 0));
        }
    }

    public function testWaitingDoesNotEarnSecurityAndLowTrustEarnsLittle(): void
    {
        // Fulfilled on day 0, then the player waits nearby for days without a word: no contact
        // day, so no fulfilled day counts; the band sinks and past the grace it is neglect.
        $d = $this->asheInRomance();
        $this->attentiveDays('Ashe', $d, self::T0, 1);
        $dayEnd = (floor(self::T0 / self::DAY) + 1) * self::DAY;
        RelationshipDynamics::advanceFulfillment('Ashe', $d, $dayEnd + self::HOUR);   // the contact day's end counts
        [, $avo1] = self::axes($d);
        RelationshipDynamics::advanceFulfillment('Ashe', $d, $dayEnd + 2 * self::DAY + self::HOUR);
        $this->assertGreaterThanOrEqual($avo1, self::axes($d)[1], 'no earned security from time alone');

        $low = $this->asheInRomance(14.0);   // trust 14 of trust_full 70: a fifth of the rate
        $high = $this->asheInRomance(70.0);
        $this->attentiveDays('Ashe', $low, self::T0, 8);
        $this->attentiveDays('Ashe', $high, self::T0, 8);
        $this->assertGreaterThan(self::axes($high)[1], self::axes($low)[1], 'security is earned as trust is');
    }

    public function testNeglectAndBetrayalRaiseTheAxesWithASlingshot(): void
    {
        // Betrayal and a lie, scored by the eval
        $d = $this->asheInRomance();
        [$anx0, $avo0] = self::axes($d);
        RelationshipDynamics::processEvalContractItem('Ashe', self::evalItem('Ashe', self::T0, ['betrayal'], 1.0, ['affinity' => -5, 'trust' => -5]), $d);
        [$anx1, $avo1] = self::axes($d);
        $this->assertEqualsWithDelta($anx0 + 0.03, $anx1, 1e-6, 'betrayal: +0.04 x bond weight... capped at 0.03 per game day');
        $this->assertGreaterThan($avo0, $avo1);
        RelationshipDynamics::processEvalContractItem('Ashe', self::evalItem('Ashe', self::T0 + self::HOUR, ['lie'], 1.0, ['trust' => -2]), $d);
        $this->assertEqualsWithDelta($anx1, self::axes($d)[0], 1e-9, 'the day budget is spent');
        RelationshipDynamics::processEvalContractItem('Ashe', self::evalItem('Ashe', self::T0 + self::DAY, ['lie'], 1.0, ['trust' => -2]), $d);
        $this->assertGreaterThan($anx1, self::axes($d)[0], 'a new game day');

        // Neglect: security earned, then weeks away. The rise back to her base is the MDD 6.1
        // regression slingshot (x3), faster than the climb above it.
        $e = $this->asheInRomance();
        $this->attentiveDays('Ashe', $e, self::T0, 40);
        [, $earned] = self::axes($e);
        $this->assertLessThanOrEqual(0.4, $earned);
        $last = self::T0 + 39 * self::DAY + 3 * self::HOUR;
        $steps = [];
        for ($k = 1; $k <= 40; $k++) {
            RelationshipDynamics::advanceFulfillment('Ashe', $e, $last + $k * self::DAY);
            $steps[] = self::axes($e)[1];
        }
        $this->assertGreaterThan($earned, end($steps), 'neglect undoes it');
        $this->assertSame('low_day', end($e['_attachment_drift']['log'])['why']);
        // Daily rises: below her base (0.5) up to 3 x the low-day rate (MDD 6.1 slingshot),
        // above it never more than the plain rate (0.003 per low day at full depth)
        $prev = $earned;
        $maxBelow = $maxAbove = 0.0;
        foreach ($steps as $x) {
            if ($prev < 0.5 - 1e-9) $maxBelow = max($maxBelow, $x - $prev); else $maxAbove = max($maxAbove, $x - $prev);
            $prev = $x;
        }
        $this->assertGreaterThan(0.003 * 1.5, $maxBelow, 'below her base: the slingshot');
        $this->assertLessThanOrEqual(0.003 + 1e-6, $maxAbove, 'past her base: the plain rate');
        $this->assertGreaterThanOrEqual(0.5, end($steps), 'forty game days of neglect take her back past where she started');
    }

    public function testDriftNeverCrossesIntoFearfulButAnArcCan(): void
    {
        // Anxious (0.85, 0.3): betrayals every day raise avoidance to the fearful edge and stop
        $d = self::at(0.85, 0.3, ['_core_rel_type' => 'romantic']);
        for ($k = 0; $k < 20; $k++) {
            RelationshipDynamics::attachmentExperience($d, 'betrayal', self::T0 + $k * self::DAY);
        }
        $this->assertSame('anxious', RelationshipDynamics::getAttachmentStyle($d));
        $this->assertEqualsWithDelta(0.49, self::axes($d)[1], 1e-9, 'stops just below the avoidance threshold');

        // A breaking arc (divine intervention) is the MDD path to Toxic/Disorganized
        $GLOBALS['db'] = new RelDynAttachmentAxesCoreDb([], array_merge(RelationshipDynamics::defaultConfig(), ['divine_intervention_enabled' => true]));
        RelationshipDynamics::clearConfigCache();
        $d['_attachment_shift_available'] = true;
        $d['_divine_intervention_last_type'] = 'breaking';
        $this->assertSame('toxic', RelationshipDynamics::processAttachmentShift($d));
        $d['_attachment_shift_available'] = true;
        $d['_divine_intervention_last_type'] = 'redemption';
        $this->assertSame('anxious', RelationshipDynamics::processAttachmentShift($d), 'a redemption arc lowers both: toxic -> anxious (the April table)');

        // An unknown experience is logged, not applied
        $before = self::axes($d);
        $this->assertSame([], RelationshipDynamics::attachmentExperience($d, 'hug', self::T0));
        $this->assertSame($before, self::axes($d));
        $this->assertStringContainsString("attachment experience 'hug'", (string) file_get_contents($this->errorLog));
    }

    /** Two requests that each moved her attachment and saved concurrently: both experiences count. */
    public function testConcurrentDriftSavesKeepBothExperiences(): void
    {
        $k = RelationshipDynamics::ATTACHMENT_DRIFT_KEY;
        $base = [$k => ['anxiety' => 0.01, 'avoidance' => -0.02, 'day' => 420, 'moved' => ['anxiety' => 0.01, 'avoidance' => 0.02]]];
        $mine = [$k => ['anxiety' => -0.003, 'avoidance' => -0.026, 'day' => 420, 'moved' => ['anxiety' => 0.023, 'avoidance' => 0.026]]];
        $theirs = [$k => ['anxiety' => 0.04, 'avoidance' => 0.01, 'day' => 420, 'moved' => ['anxiety' => 0.04, 'avoidance' => 0.05]]];
        $m = RelationshipDynamics::mergeDynamics($base, $mine, $theirs)[$k];
        $this->assertEqualsWithDelta(0.04 - 0.013, $m['anxiety'], 1e-9, 'signed offsets add up (no clamp at 0)');
        $this->assertEqualsWithDelta(0.01 - 0.006, $m['avoidance'], 1e-9);
        $this->assertEqualsWithDelta(0.053, $m['moved']['anxiety'], 1e-9, 'the day budget spent by both');
    }

    public function testWalkawayBoundaryAndBondWeight(): void
    {
        $d = $this->asheInRomance();
        $GLOBALS['gameRequest'] = ['inputtext', '1', (string) (int) self::T0, 'Kaida: ...'];
        [$anx0, $avo0] = self::axes($d);
        RelationshipDynamics::activateWalkaway($d, 'Ashe');
        [$anx1, $avo1] = self::axes($d);
        $this->assertGreaterThan($anx0, $anx1, 'things got bad enough that she left');
        $this->assertGreaterThan($avo0, $avo1);

        // Kept boundary next day: left alone as she needed
        $e = $this->asheInRomance();
        $avoE = self::axes($e)[1];
        RelationshipDynamics::attachmentExperience($e, 'boundary_kept', self::T0);
        $this->assertLessThan($avoE, self::axes($e)[1]);

        // A stranger's betrayal barely registers (bond weight 0); a friend's counts less than a lover's
        $w = RelationshipDynamics::getAttachmentConfig()['drift']['bond_weight'];
        $this->assertSame(0.0, $w['stranger']);
        $friend = self::at(0.3, 0.3, ['_core_rel_type' => 'platonic']);
        $lover = self::at(0.3, 0.3, ['_core_rel_type' => 'romantic']);
        RelationshipDynamics::attachmentExperience($friend, 'lie', self::T0);
        RelationshipDynamics::attachmentExperience($lover, 'lie', self::T0);
        $this->assertEqualsWithDelta(0.3 + 0.015 * 0.6, self::axes($friend)[0], 1e-9);
        $this->assertEqualsWithDelta(0.3 + 0.015 * 1.0, self::axes($lover)[0], 1e-9);
    }
}
