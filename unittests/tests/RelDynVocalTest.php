<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_settings.php';

/** Stand-in for $db holding core's conf_opts rows in memory (the config store's SELECT and upsert). */
final class RelDynVocalRowDb
{
    public array $rows = [];

    public function fetchOne($sql, $params = null)
    {
        $sql = (string) $sql;
        if (stripos($sql, 'INSERT INTO conf_opts') !== false) {
            $this->rows[(string) $params[0]] = (string) $params[1];
            return ['id' => (string) $params[0]];
        }
        if (stripos($sql, 'FROM conf_opts') !== false) {
            $id = is_array($params) ? (string) $params[0] : (preg_match("/id = '([^']+)'/", $sql, $m) ? $m[1] : '');
            return isset($this->rows[$id]) ? ['value' => $this->rows[$id]] : [];
        }
        return [];
    }

    public function fetchAll($sql) { return []; }
    public function execQuery($sql) { return true; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * Vocal style (Ken 2026-10-01 §24, Sharmat scene awareness: vocalization per NPC, by temperament): how much an NPC talks in
 * bed and how they sound, one reading dynamic and scaled by who the NPC is (reldyn_vocal.php). No database but a config
 * stand-in: the defaults and nothing stored. The test beds through the real hooks, with the published key Sharmat reads, are
 * RelDynVocalTestBedsPostgresTest.
 *
 * Units: silence chance, closeness, traits 0..1; dimension points 0..100; pace a multiplier near 1.
 */
final class RelDynVocalTest extends TestCase
{
    private array $saved = [];
    private string $logFile;
    private $prevLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
        RelDynTraits::$assignmentOverride = 'read';
        $this->logFile = tempnam(sys_get_temp_dir(), 'rdvocal');
        $this->prevLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevLog === false ? '' : (string) $this->prevLog);
        @unlink($this->logFile);
        RelDynTraits::$assignmentOverride = null;
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    private function storeConfig(array $row): void
    {
        $db = new RelDynVocalRowDb();
        $db->rows[RelationshipDynamics::CONFIG_ROW_ID] = json_encode($row + ['config_schema' => RelationshipDynamics::CONFIG_SCHEMA]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
    }

    /** An NPC toward the player: its own trait vector, maturity, self-confidence, core affinity and type, passion, comfort and trust, attachment style. */
    private function npc(array $traits = [], float $maturity = 55.0, float $confidence = 55.0, array $o = []): array
    {
        $o += ['aff' => 80.0, 'type' => 'romantic', 'passion' => 70.0, 'comfort' => 75.0, 'trust' => 80.0, 'attachment' => 'secure', 'resentment' => 0.0];
        $x = array_replace(['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'Pd' => 0.5, 'Rs' => 0.5, 'L' => 0.5, 'W' => 0.5, 'D' => 0.5, 'Po' => 0.5, 'Pr' => 0.5], $traits);
        $x['maturity_start'] = $maturity;
        $mirror = ($o['aff'] + 100.0) / 2.0;
        $d = [
            'inferred_temperament' => 'Stoic',
            'trait_vector' => RelDynTraits::toStored($x),
            '_trait_vector_src' => ['assignment' => 'read'],
            '_core_rel_type' => $o['type'],
            '_aff_mirror_x' => $mirror,
            'profile_overrides' => ['attachment_style' => $o['attachment']],
            'jealousy_anger' => 0.0,
            'in_conflict' => false,
            'dimensions' => [],
            '_attraction' => ['enabled' => true, 'passes' => true, 'attracted' => true, 'friendzoned' => false, 'preference' => null, 'intimacy_allowed' => true],
        ];
        foreach (['affinity' => $mirror, 'trust' => $o['trust'], 'maturity' => $maturity, 'comfort' => $o['comfort'], 'respect' => 60.0,
                  'self_confidence' => $confidence, 'resentment' => $o['resentment'], 'passion' => $o['passion']] as $dim => $v) {
            $d['dimensions'][$dim] = ['x' => $v, 'baseline' => in_array($dim, ['resentment'], true) ? 0.0 : $v];
        }
        $d['passion'] = $o['passion'];
        return $d;
    }

    private function preset(string $name, array $o = []): array
    {
        $t = RelDynTraits::PRESET_TRAITS[$name];
        $attachment = ['Anxious' => 'anxious', 'Jealous' => 'anxious', 'Proud' => 'avoidant', 'Defiant' => 'avoidant', 'Guarded' => 'avoidant',
                       'Independent' => 'avoidant', 'Stoic' => 'avoidant'][$name] ?? 'secure';
        return $this->npc($t, 55.0, floatval($t['C']) * 100.0, $o + ['attachment' => $attachment]);
    }

    private function read(array $d, string $name = 'Test NPC'): array
    {
        return RelDynVocal::decide($name, $d, null, 'Kaida');
    }

    /** A bond that is not yet close, and one that is. */
    private function distant(array $o = []): array { return $o + ['passion' => 15.0, 'comfort' => 35.0, 'trust' => 40.0]; }
    private function close(array $o = []): array { return $o + ['passion' => 75.0, 'comfort' => 80.0, 'trust' => 85.0]; }

    // ------------------------------------------------------------------ the shape

    public function testDefaultsAreAComposedTableAndGenderNeutral(): void
    {
        $cfg = RelDynVocal::configDefaults();
        $this->assertTrue($cfg['enabled']);
        $this->assertSame(['vocal', 'whispered', 'minimal', 'seeking', 'sharp'], array_keys($cfg['styles']));
        $this->assertSame(RelDynVocal::STYLES, array_keys($cfg['pace']), 'a pace for every register');
        $this->assertSame(RelDynVocal::STYLES, array_keys($cfg['felt_text']), 'a felt line (or none) for every register');
        $this->assertNull($cfg['felt_text']['open']);
        foreach ($cfg['felt_text'] as $style => $line) {
            if ($line === null) continue;
            $this->assertStringContainsString('{NAME}', $line, $style);
            $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his|herself|himself)\b/i', $line, "{$style}: no assumed pronoun");
            $this->assertDoesNotMatchRegularExpression('/\d/', $line, "{$style}: feelings, never numbers");
        }
        $this->assertSame($cfg, RelDynVocal::config(), 'nothing stored: the config is the defaults');
        $this->assertSame($cfg, RelationshipDynamics::defaultConfig()['vocal'], 'registered in the shipped defaults');
        $this->assertArrayHasKey('vocal', RelDynSettings::readers(), 'a settings section of its own');
    }

    public function testStoredConfigMergesPerEntry(): void
    {
        $this->storeConfig(['vocal' => ['silence' => ['base' => 0.1, 'traits' => ['G' => 0.9]], 'pace' => ['whispered' => 0.8], 'felt_text' => ['minimal' => 'Plain line for {NAME}.']]]);
        $cfg = RelDynVocal::config();
        $this->assertSame(0.1, $cfg['silence']['base']);
        $this->assertSame(0.9, $cfg['silence']['traits']['G']);
        $this->assertSame(-0.40, $cfg['silence']['traits']['E'], 'the traits not stored keep their default');
        $this->assertSame(0.80, $cfg['silence']['closeness']['relief'] + 0.60, 'the nested closeness table is merged too');
        $this->assertSame(0.8, $cfg['pace']['whispered']);
        $this->assertSame(0.95, $cfg['pace']['minimal']);
        $this->assertSame('Plain line for {NAME}.', $cfg['felt_text']['minimal']);
        $this->assertNotNull($cfg['felt_text']['vocal']);
    }

    public function testSettingsTextCoversTheSection(): void
    {
        $text = RelDynSettingsText::SECTION_HELP['vocal'] ?? null;
        $this->assertIsString($text);
        $this->assertDoesNotMatchRegularExpression('/(she|he|her|his|him)/i', $text);
        $hint = RelDynSettingsText::HINTS['vocal.enabled'] ?? null;
        $this->assertIsString($hint);
        $this->assertDoesNotMatchRegularExpression('/(she|he|her|his|him)/i', $hint);
    }

    // ------------------------------------------------------------------ the register, by who the NPC is

    public function testTheTemperamentsLandWhereKensSketchSays(): void
    {
        $expect = ['Bold' => 'vocal', 'Playful' => 'vocal', 'Romantic' => 'whispered', 'Gentle' => 'whispered', 'Nurturing' => 'whispered',
                   'Guarded' => 'minimal', 'Stoic' => 'minimal', 'Independent' => 'minimal', 'Proud' => 'minimal',
                   'Anxious' => 'seeking', 'Jealous' => 'seeking'];
        foreach ($expect as $preset => $style) {
            $this->assertSame($style, $this->read($this->preset($preset, $this->close()), $preset)['style'], "{$preset} sounds {$style}");
        }
    }

    public function testToxicCornerAndPrideReadSharp(): void
    {
        $traits = ['Pd' => 0.6, 'L' => 0.6, 'W' => 0.4, 'G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'D' => 0.5];
        $sharp = $this->npc($traits, 40.0, 45.0, $this->close(['attachment' => 'toxic']));
        $this->assertSame('sharp', $this->read($sharp)['style']);
        $same = $this->npc($traits, 40.0, 45.0, $this->close(['attachment' => 'secure']));
        $this->assertNotSame('sharp', $this->read($same)['style'], 'without the toxic corner the same traits do not read sharp: it is the attachment, not just the pride');
    }

    public function testNoRegisterLeansHardEnoughIsOpen(): void
    {
        $r = $this->read($this->npc([], 55.0, 55.0, $this->close()));
        $this->assertSame('open', $r['style']);
        $this->assertNull($r['felt'], 'the open register says nothing');
        $this->assertSame(1.0, $r['pace']);
    }

    public function testEveryRegisterHasItsLineAndPace(): void
    {
        $seen = [];
        foreach (['Bold', 'Romantic', 'Guarded', 'Anxious'] as $preset) {
            $r = $this->read($this->preset($preset, $this->close()), 'Lynly Star-Sung');
            $seen[$r['style']] = true;
            $this->assertStringContainsString('Lynly Star-Sung', (string) $r['felt'], $preset);
            $this->assertDoesNotMatchRegularExpression('/\b(she|he|her|hers|him|his|herself|himself)\b/i', (string) $r['felt'], $preset);
            $this->assertDoesNotMatchRegularExpression('/\d/', (string) $r['felt'], $preset);
        }
        $this->assertSame(['vocal', 'whispered', 'minimal', 'seeking'], array_keys($seen));
        $this->assertLessThan(1.0, $this->read($this->preset('Romantic', $this->close()))['pace'], 'a whisperer is a touch slower');
        $this->assertGreaterThan(1.0, $this->read($this->preset('Anxious', $this->close()))['pace'], 'an anxious one a touch quicker');
    }

    // ------------------------------------------------------------------ the silence chance

    public function testWhoTheNPCIsDecidesHowQuiet(): void
    {
        $guarded = $this->read($this->preset('Guarded', $this->close()))['silence_chance'];
        $stoic = $this->read($this->preset('Stoic', $this->close()))['silence_chance'];
        $neutral = $this->read($this->npc([], 55.0, 55.0, $this->close()))['silence_chance'];
        $bold = $this->read($this->preset('Bold', $this->close()))['silence_chance'];
        $romantic = $this->read($this->preset('Romantic', $this->close()))['silence_chance'];
        $this->assertGreaterThan(0.6, $guarded, 'a guarded NPC says little even when close');
        $this->assertGreaterThan(0.6, $stoic);
        $this->assertGreaterThan($neutral + 0.3, $guarded, 'and far more than a neutral one');
        $this->assertGreaterThan($bold + 0.5, $guarded, 'and far, far more than a bold one');
        $this->assertGreaterThan($romantic, $neutral);
        $this->assertLessThan(0.2, $bold, 'a bold NPC talks');
    }

    public function testAnAvoidantCornerIsQuieterAndAnAnxiousOneTalksMore(): void
    {
        $t = ['G' => 0.5, 'E' => 0.5, 'C' => 0.5, 'D' => 0.5];
        $avoidant = $this->read($this->npc($t, 55.0, 50.0, $this->close(['attachment' => 'avoidant'])))['silence_chance'];
        $secure = $this->read($this->npc($t, 55.0, 50.0, $this->close(['attachment' => 'secure'])))['silence_chance'];
        $anxious = $this->read($this->npc($t, 55.0, 50.0, $this->close(['attachment' => 'anxious'])))['silence_chance'];
        $this->assertGreaterThan($secure, $avoidant);
        $this->assertGreaterThan($anxious, $secure);
    }

    public function testClosenessEasesTheQuietAndAGuardedNPCIsEasedLess(): void
    {
        $far = $this->read($this->preset('Guarded', $this->distant()))['silence_chance'];
        $near = $this->read($this->preset('Guarded', $this->close()))['silence_chance'];
        $this->assertLessThan($far, $near, 'a bond that has been let in loosens the tongue');
        $openFar = $this->read($this->npc(['G' => 0.2], 55.0, 55.0, $this->distant()))['silence_chance'];
        $openNear = $this->read($this->npc(['G' => 0.2], 55.0, 55.0, $this->close()))['silence_chance'];
        $this->assertGreaterThan($far - $near, $openFar - $openNear, 'the same closeness eases an open NPC more than a guarded one');
    }

    public function testNoOneIsCertainToSpeakOrCertainToStaySilent(): void
    {
        $cfg = RelDynVocal::configDefaults();
        // the most closed vector there is, in a quarrel, hurt and afraid, avoidant
        $mute = $this->npc(['G' => 1.0, 'D' => 1.0, 'E' => 0.0, 'C' => 0.0, 'Pd' => 1.0, 'L' => 1.0], 55.0, 5.0,
            $this->distant(['attachment' => 'avoidant', 'resentment' => 95.0]));
        $mute['in_conflict'] = true;
        $mute['_ick_tracker'] = ['ick_active' => true];
        $mute['_consent'] = ['v' => 1, 'allow' => true, 'stance' => 'appeasing', 'gamets' => 0];
        $r = $this->read($mute);
        $this->assertSame($cfg['silence']['max'], $r['silence_chance'], 'the quietest possible NPC is held at the ceiling');
        $this->assertLessThan(1.0, $r['silence_chance'], 'never certain to be silent');
        // the most open vector, deep in love
        $talker = $this->npc(['G' => 0.0, 'D' => 0.0, 'E' => 1.0, 'C' => 1.0], 55.0, 95.0, $this->close(['attachment' => 'anxious', 'passion' => 100.0, 'comfort' => 100.0, 'trust' => 100.0]));
        $r = $this->read($talker);
        $this->assertSame($cfg['silence']['min'], $r['silence_chance'], 'the most open possible NPC is held at the floor');
        $this->assertGreaterThan(0.0, $r['silence_chance'], 'never certain to speak');
    }

    public function testWhatWeighsMakesAnNPCQuieter(): void
    {
        $base = $this->preset('Romantic', $this->close());
        $calm = $this->read($base)['silence_chance'];
        $fight = $base; $fight['in_conflict'] = true;
        $ick = $base; $ick['_ick_tracker'] = ['ick_active' => true];
        $hurt = $base; $hurt['dimensions']['resentment']['x'] = 85.0;
        foreach (['a quarrel' => $fight, 'the ick' => $ick, 'old hurt' => $hurt] as $what => $d) {
            $this->assertGreaterThan($calm + 0.02, $this->read($d)['silence_chance'], "{$what} quiets the NPC");
        }
        $gaveIn = $base; $gaveIn['_consent'] = ['v' => 1, 'allow' => true, 'stance' => 'appeasing', 'gamets' => 0];
        $unsure = $base; $unsure['_consent'] = ['v' => 1, 'allow' => true, 'stance' => 'hesitant', 'gamets' => 0];
        $willing = $base; $willing['_consent'] = ['v' => 1, 'allow' => true, 'stance' => 'willing', 'gamets' => 0];
        $this->assertGreaterThan($this->read($unsure)['silence_chance'], $this->read($gaveIn)['silence_chance'], 'a yes given in to is quieter than a yes with doubts');
        $this->assertGreaterThan($this->read($willing)['silence_chance'], $this->read($unsure)['silence_chance'], 'and a yes with doubts is quieter than a whole-hearted one');
        $this->assertEqualsWithDelta($calm, $this->read($willing)['silence_chance'], 0.0001, 'a willing NPC adds nothing');
    }

    public function testTheSilencePartsAddUpAndAreExplained(): void
    {
        $r = $this->read($this->preset('Proud', $this->distant()));
        $this->assertSame(['base', 'traits', 'corner', 'closeness', 'weighs', 'stance'], array_keys($r['parts']));
        $this->assertEqualsWithDelta(array_sum($r['parts']), $r['silence_chance'], 0.0005);
        $this->assertGreaterThan(0.0, $r['parts']['traits'], 'a proud, guarded NPC leans quiet by character');
        $this->assertLessThanOrEqual(0.0, $r['parts']['closeness'], 'closeness only ever eases');
    }

    // ------------------------------------------------------------------ the switch and Sharmat's contract

    public function testPayloadIsWhatSharmatReads(): void
    {
        $r = $this->read($this->preset('Guarded', $this->close()), 'Ashe');
        $p = RelDynVocal::payload($r);
        $this->assertSame(['v', 'enabled', 'style', 'silence_chance', 'pace', 'felt'], array_keys($p));
        $this->assertSame(1, $p['v']);
        $this->assertTrue($p['enabled']);
        $this->assertSame('minimal', $p['style']);
        $this->assertSame(round($r['silence_chance'], 2), $p['silence_chance']);
        $this->assertGreaterThan(0.0, $p['silence_chance']);
        $this->assertLessThan(1.0, $p['silence_chance']);
        $this->assertSame(0.95, $p['pace']);
        $this->assertStringContainsString('Ashe', $p['felt']);
    }

    public function testTheSwitchOffReadsAsOffInTheConfig(): void
    {
        $this->storeConfig(['vocal' => ['enabled' => false]]);
        $this->assertFalse(RelDynVocal::enabled());
        $this->storeConfig([]);
        $this->assertTrue(RelDynVocal::enabled());
    }
}
