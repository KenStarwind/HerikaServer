<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** $db whose core_npc_master read answers from a name => gender map (a missing name is an NPC core has no row for). */
final class RelDynPronounsFakeDb
{
    public int $reads = 0;
    public function __construct(private array $genders, private bool $fail = false) {}

    public function fetchOne($q, array $params = [])
    {
        $this->reads++;
        if ($this->fail) throw new RuntimeException('simulated core_npc_master failure');
        foreach ($this->genders as $name => $gender) {
            if (strtolower((string) $name) === strtolower((string) ($params[0] ?? ''))) return ['npc_name' => $name, 'gender' => $gender];
        }
        return [];
    }

    public function fetchAll($q, $log = false) { return []; }
    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * Pronouns (Ken 2026-10-01 §21): RelDyn is for every character regardless of gender. Nothing an LLM, the player or Ken
 * reads carries a bare she / he / her / him / his: the NPC is named, or a pronoun var resolves from core_npc_master.gender
 * (RelDynPronouns), neutral when core does not say.
 *
 * Two nets: every literal string of the extension (and the changelog), and every string of the shipped config defaults
 * (the editable text tables as Ken sees them in the settings hub).
 */
final class RelDynPronounsTest extends TestCase
{
    private const BARE = '/\b(she|her|hers|herself|he|him|his|himself)\b/i';
    private const ROOT = __DIR__ . '/../../ext/relationship_dynamics';

    private array $savedGlobals = [];
    private string $errorLog = '';
    private $prevErrorLog = null;

    protected function setUp(): void
    {
        foreach (['db', 'HERIKA_NAME'] as $key) {
            $this->savedGlobals[$key] = array_key_exists($key, $GLOBALS) ? [$GLOBALS[$key]] : null;
            unset($GLOBALS[$key]);
        }
        RelDynPronouns::reset();
        RelationshipDynamics::clearConfigCache();
        $this->errorLog = tempnam(sys_get_temp_dir(), 'rdpron');
        $this->prevErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        @unlink($this->errorLog);
        foreach ($this->savedGlobals as $key => $saved) {
            if ($saved === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $saved[0];
        }
        RelDynPronouns::reset();
        RelationshipDynamics::clearConfigCache();
    }

    // ------------------------------------------------------------------ the vars

    public function testFormsFollowTheNPCsOwnGender(): void
    {
        $GLOBALS['db'] = new RelDynPronounsFakeDb(['Farkas' => 'Male', 'Aela the Huntress' => 'female', 'Vex' => 'other']);
        $line = '{They} walk{S} off, and {THEY_ARE} done with {THEMSELF}; {THEIR} word, not {THEIRS}; {THEM} again. {They_are} sure {THEY_HAVE} it. {They_have} it.';
        $this->assertSame('He walks off, and he is done with himself; his word, not his; him again. He is sure he has it. He has it.',
            RelDynPronouns::fill($line, 'Farkas'));
        $this->assertSame('She walks off, and she is done with herself; her word, not hers; her again. She is sure she has it. She has it.',
            RelDynPronouns::fill($line, 'Aela the Huntress'));
        $this->assertSame('They walk off, and they are done with themself; their word, not theirs; them again. They are sure they have it. They have it.',
            RelDynPronouns::fill($line, 'Vex'), 'a gender core does not state as male or female: neutral, never a default she');
    }

    public function testUnknownEmptyAndUnreadableGenderFallBackToNeutralNeverShe(): void
    {
        $GLOBALS['db'] = new RelDynPronounsFakeDb(['Blank' => '', 'Nulled' => null]);
        foreach (['Blank', 'Nulled', 'Nobody In Core'] as $npc) {
            $this->assertSame('they / them / their', RelDynPronouns::fill('{THEY} / {THEM} / {THEIR}', $npc), $npc);
        }
        $GLOBALS['db'] = new RelDynPronounsFakeDb([], true);
        RelDynPronouns::reset();
        $this->assertSame('They are here', RelDynPronouns::fill('{They_are} here', 'Farkas'), 'a failed read is neutral');
        $this->assertStringContainsString('pronouns', (string) file_get_contents($this->errorLog), 'and it is logged');
        $this->assertSame('They', RelDynPronouns::fill('{They}', ''), 'no name, no lookup: neutral');
        unset($GLOBALS['db']);
        RelDynPronouns::reset();
        $this->assertSame('their', RelDynPronouns::fill('{THEIR}', 'Farkas'), 'no database: neutral');
    }

    public function testClassifyReadsEveryWayCoreWritesAGender(): void
    {
        foreach (['Male', 'male', ' MALE ', 'm', 'man'] as $g) $this->assertSame('m', RelDynPronouns::classify($g), $g);
        foreach (['Female', 'female', 'f', 'woman'] as $g) $this->assertSame('f', RelDynPronouns::classify($g), $g);
        foreach (['', 'other', 'nonbinary', 'unknown', null, 'creature'] as $g) $this->assertSame('n', RelDynPronouns::classify($g), (string) $g);
    }

    public function testTextWithoutAVarNeverTouchesTheDatabaseAndAnUnknownBraceIsLeftAlone(): void
    {
        $db = new RelDynPronounsFakeDb(['Farkas' => 'male']);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::beginRequest();
        $this->assertSame('{NAME} is quiet {THING}.', RelDynPronouns::fill('{NAME} is quiet {THING}.', 'Farkas'));
        $this->assertSame('plain', RelDynPronouns::fill('plain', 'Farkas'));
        $this->assertSame(0, $db->reads, 'ordinary prose costs nothing');
        $this->assertSame('{THEYRE} {they}', RelDynPronouns::fill('{THEYRE} {they}', 'Farkas'), 'only the documented vars resolve');
        RelDynPronouns::fill('{THEY}', 'Farkas');
        RelDynPronouns::fill('{THEM}', 'farkas');
        $this->assertSame(1, $db->reads, 'one read per NPC per request scope, case-insensitive on the name');
        RelationshipDynamics::endRequest();
        RelDynPronouns::fill('{THEY}', 'Farkas');
        RelDynPronouns::fill('{THEY}', 'Farkas');
        $this->assertSame(3, $db->reads, 'outside a request scope nothing is cached: a long-lived process never reads a stale row');
    }

    public function testTheCoreGenderIsReReadWhenTheRequestScopeEnds(): void
    {
        $GLOBALS['db'] = new RelDynPronounsFakeDb(['Farkas' => 'male']);
        RelationshipDynamics::beginRequest();
        $this->assertSame('he', RelDynPronouns::fill('{THEY}', 'Farkas'));
        $GLOBALS['db'] = new RelDynPronounsFakeDb(['Farkas' => 'female']);
        $this->assertSame('he', RelDynPronouns::fill('{THEY}', 'Farkas'), 'cached inside the scope');
        RelationshipDynamics::endRequest();
        RelationshipDynamics::beginRequest();
        $this->assertSame('she', RelDynPronouns::fill('{THEY}', 'Farkas'), 'a new request reads core again');
        RelationshipDynamics::endRequest();
    }

    // ------------------------------------------------------------------ the nets

    /**
     * Strings that match the pronoun pattern on purpose, and are not text anybody reads: bio-matching patterns, which must
     * recognise both genders in the source bios. file => exact string contents (outside any quotes).
     */
    private const EXEMPT = [
        'reldyn_goals.php' => ['prove herself', 'prove himself'],
        'reldyn_trait_read.php' => ['she', 'her', 'hers', 'he', 'his', 'him'],
        'reldyn_pronouns.php' => ['he', 'she', 'him', 'her', 'his', 'hers', 'himself', 'herself', 'he is', 'she is', 'he has', 'she has'],
    ];

    /** A PCRE pattern literal ('/.../i'): a matcher for bio text, not text anyone reads. */
    private static function isPattern(string $s): bool
    {
        return preg_match('~^/.*/[a-z]*$~s', $s) === 1;
    }

    private static function unquote(string $raw): string
    {
        $q = $raw[0] ?? '';
        return ($q === "'" || $q === '"') && substr($raw, -1) === $q ? substr($raw, 1, -1) : $raw;
    }

    private function phpFiles(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = str_replace('\\', '/', $f->getPathname());
            if (substr($p, -4) !== '.php' || strpos($p, '/debug/') !== false) continue;   // debug/ = Ken's own legacy harness scripts
            $out[] = $p;
        }
        sort($out);
        return $out;
    }

    public function testNoStringLiteralOfTheExtensionCarriesABareGenderedPronoun(): void
    {
        $files = $this->phpFiles();
        $this->assertGreaterThan(60, count($files), 'the scan sees the whole extension');
        $bad = [];
        foreach ($files as $p) {
            $base = basename($p);
            foreach (token_get_all((string) file_get_contents($p)) as $t) {
                if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) continue;
                $text = $t[0] === T_CONSTANT_ENCAPSED_STRING ? self::unquote($t[1]) : $t[1];
                if (!preg_match(self::BARE, $text) || self::isPattern($text)) continue;
                if (in_array(trim($text), self::EXEMPT[$base] ?? [], true)) continue;
                $bad[] = "{$base}:{$t[2]}: " . substr(preg_replace('/\s+/', ' ', $text), 0, 110);
            }
        }
        $this->assertSame([], $bad, "bare gendered pronouns in strings (name the NPC, or use {THEY} / {THEIR} ...):\n" . implode("\n", $bad));
    }

    public function testTheChangelogUsesNoGenderedPronouns(): void
    {
        $bad = [];
        foreach (file(self::ROOT . '/CHANGELOG.md') as $i => $line) {
            if (preg_match(self::BARE, $line)) $bad[] = 'CHANGELOG.md:' . ($i + 1) . ': ' . substr(trim($line), 0, 120);
        }
        $this->assertSame([], $bad, "gendered wording in the changelog:\n" . implode("\n", $bad));
    }

    /** The settings hub's editable text tables and hints: every string of the shipped defaults. */
    public function testEveryStringOfTheShippedConfigDefaultsIsPronounFree(): void
    {
        $strings = [];
        $walk = function ($node, string $path) use (&$walk, &$strings): void {
            if (is_array($node)) { foreach ($node as $k => $v) $walk($v, $path . '.' . $k); return; }
            if (is_string($node)) $strings[$path] = $node;
        };
        $walk(RelationshipDynamics::defaultConfig(), 'config');
        $this->assertGreaterThan(300, count($strings), 'the walk reaches every module default');
        $this->assertArrayHasKey('config.felt_steering.text.header_bond', $strings);
        $bad = [];
        foreach ($strings as $path => $s) {
            if (!preg_match(self::BARE, $s) || self::isPattern($s)) continue;
            if (preg_match('/^config\.intrinsic_goals\.backstory\.\w+\.patterns\./', $path)) continue;   // bio-matching patterns (both genders)
            $bad[] = "{$path}: " . substr($s, 0, 100);
        }
        $this->assertSame([], $bad, "bare gendered pronouns in the config defaults:\n" . implode("\n", $bad));
    }

    public function testTheSettingsHubDescribesThePronounVars(): void
    {
        $src = (string) file_get_contents(self::ROOT . '/reldyn_settings_text.php');
        $this->assertTrue(str_contains($src, '{THEY}'), 'a text table hint tells Ken the vars exist');
        $this->assertCount(15, RelDynPronouns::tokens());
    }
}
