<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** `sql` stand-in holding only a stored RelDyn config row; every other read finds nothing. */
final class RelDynMiscReassuranceConfigDb
{
    public function __construct(private array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'") ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Decisions 2026-09-23 §20 #11 (and #12): an NPC who seeks reassurance (anxious and toxic attachment
 * corners summing to the config's min_weight or more) asks for it in words of affirmation OR in acts of
 * service ("do x to prove you care"), chosen by who she is: reactive and expressive leans words,
 * possessive or proud leans service. The choice is a config table (love_language_attachment.choices:
 * language => trait weights); the default language is the tie-break and the fallback. Nobody is locked
 * into one: her traits move, the derivation moves with them (the _ll_auto signature).
 *
 * No database: NPCs are built current with the vector the test hands them; nothing is stored.
 */
final class RelDynMiscReassuranceLanguageTest extends TestCase
{
    private $savedDb;
    private $savedAssignment;
    private $prevErrorLog;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        $this->savedAssignment = RelDynTraits::$assignmentOverride;
        RelDynTraits::$assignmentOverride = 'read';
        RelationshipDynamics::clearConfigCache();
        $this->prevErrorLog = ini_set('error_log', sys_get_temp_dir() . '/reldyn_misc_reassurance_test.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog === false ? '' : (string) $this->prevErrorLog);
        RelDynTraits::$assignmentOverride = $this->savedAssignment;
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
    }

    private const ANXIOUS = ['anxiety' => 0.85, 'avoidance' => 0.15];
    private const TOXIC   = ['anxiety' => 0.85, 'avoidance' => 0.85];
    private const SECURE  = ['anxiety' => 0.15, 'avoidance' => 0.15];
    private const AVOIDANT = ['anxiety' => 0.15, 'avoidance' => 0.85];

    /** A resolved NPC at the trait overrides over the neutral middle, with the given attachment axes. */
    private function npc(array $traits, array $axes, string $label = 'Anxious'): array
    {
        $x = RelDynTraits::presetPoint($label);
        foreach ($x as $k => $_) if ($k !== 'maturity_start') $x[$k] = 0.5;
        foreach ($traits as $code => $v) $x[$code] = $v;
        return RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), [
            'inferred_temperament' => $label,
            'trait_vector' => RelDynTraits::toStored($x),
            'trait_vector_version' => RelDynTraits::VERSION,
            '_trait_vector_src' => ['assignment' => 'read', 'auto' => RelDynTraits::toStored($x), 'read_status' => 'done',
                                    'prior' => ['complete' => true]],
            '_profile_autogen' => ['version' => RelationshipDynamics::PROFILE_AUTOGEN_VERSION],
            'profile_overrides' => ['attachment_axes' => $axes],
        ]));
    }

    private function primary(array $traits, array $axes, string $name = 'Test NPC'): string
    {
        $d = $this->npc($traits, $axes);
        RelationshipDynamics::ensureLoveLanguage($name, $d);
        return (string) $d['love_language_primary'];
    }

    // ------------------------------------------------------------------ the config table

    public function testTheChoiceIsAConfigTableOverTraitsAndTheDefaultStaysWords(): void
    {
        $row = RelationshipDynamics::defaultConfig()['love_language_attachment'];
        $this->assertSame(['anxious', 'toxic'], $row['corners']);
        $this->assertSame(RelationshipDynamics::LL_WORDS, $row['language'], 'the default / tie-break');
        $this->assertSame(['E', 'L'], array_keys($row['choices'][RelationshipDynamics::LL_WORDS]), 'words: expressive and reactive');
        $this->assertSame(['Po', 'Pd'], array_keys($row['choices'][RelationshipDynamics::LL_SERVICE]), 'service: possessive and proud');
        foreach ($row['choices'] as $language => $weights) {
            $this->assertContains($language, [RelationshipDynamics::LL_WORDS, RelationshipDynamics::LL_SERVICE]);
            foreach ($weights as $code => $w) {
                $this->assertArrayHasKey($code, RelDynTraits::TRAITS, "{$language}: a real trait code");
                $this->assertGreaterThan(0.0, $w);
            }
        }
    }

    // ------------------------------------------------------------------ the choice by trait

    public function testAReactiveExpressiveReassuranceSeekerAsksInWords(): void
    {
        $traits = ['E' => 0.9, 'L' => 0.85, 'Po' => 0.3, 'Pd' => 0.25];
        $this->assertSame(RelationshipDynamics::LL_WORDS, $this->primary($traits, self::ANXIOUS));
        $this->assertSame(RelationshipDynamics::LL_WORDS, $this->primary($traits, self::TOXIC));
    }

    public function testAPossessiveOrProudReassuranceSeekerAsksForProof(): void
    {
        $possessive = ['E' => 0.3, 'L' => 0.35, 'Po' => 0.9, 'Pd' => 0.4];
        $proud = ['E' => 0.35, 'L' => 0.3, 'Po' => 0.45, 'Pd' => 0.9];
        foreach ([self::ANXIOUS, self::TOXIC] as $axes) {
            $this->assertSame(RelationshipDynamics::LL_SERVICE, $this->primary($possessive, $axes), 'possessive: do x to prove it');
            $this->assertSame(RelationshipDynamics::LL_SERVICE, $this->primary($proud, $axes), 'proud: do x to prove it');
        }
    }

    public function testEitherChoiceIsAReassuranceLanguageAndNoOtherIsChosenByAttachment(): void
    {
        $seen = [];
        foreach ([[0.9, 0.9, 0.1, 0.1], [0.1, 0.1, 0.9, 0.9], [0.5, 0.5, 0.5, 0.5], [0.2, 0.9, 0.9, 0.2], [0.9, 0.2, 0.2, 0.9]] as [$e, $l, $po, $pd]) {
            $seen[$this->primary(['E' => $e, 'L' => $l, 'Po' => $po, 'Pd' => $pd], self::TOXIC)] = true;
        }
        $this->assertEqualsCanonicalizing([RelationshipDynamics::LL_WORDS, RelationshipDynamics::LL_SERVICE], array_keys($seen));
    }

    public function testATieFallsToTheConfiguredDefault(): void
    {
        $level = ['E' => 0.5, 'L' => 0.5, 'Po' => 0.5, 'Pd' => 0.5];
        $this->assertSame(RelationshipDynamics::LL_WORDS, $this->primary($level, self::ANXIOUS));
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['love_language_attachment']['language'] = RelationshipDynamics::LL_SERVICE;
        $this->storeConfig($cfg);
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $this->primary($level, self::ANXIOUS), 'the default is the tie-break');
    }

    public function testASecureOrAvoidantNpcDoesNotSeekReassuranceSoTheTraitsDoNotDecide(): void
    {
        // the possessive-and-proud profile that asks for proof when anxious takes her own temperament's language when secure
        $traits = ['E' => 0.3, 'L' => 0.35, 'Po' => 0.9, 'Pd' => 0.9];
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $this->primary($traits, self::TOXIC));
        $d = $this->npc($traits, self::SECURE, 'Proud');
        RelationshipDynamics::ensureLoveLanguage('Secure', $d);
        $d2 = $this->npc($traits, self::AVOIDANT, 'Proud');
        RelationshipDynamics::ensureLoveLanguage('Avoidant', $d2);
        $w = RelationshipDynamics::attachmentWeights($d);
        $this->assertEqualsWithDelta(0.0, $w['anxious'] + $w['toxic'], 1e-9);
        // not an attachment-driven choice: the nearest preset's table decides (Proud -> service by the temperament, unchanged)
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $d['love_language_primary']);
        $reactive = $this->npc(['E' => 0.9, 'L' => 0.9, 'Po' => 0.1, 'Pd' => 0.1], self::SECURE, 'Playful');
        RelationshipDynamics::ensureLoveLanguage('Secure Reactive', $reactive);
        $this->assertNotSame(RelationshipDynamics::LL_WORDS, $reactive['love_language_primary'], 'secure + expressive is not asking for reassurance in words (Playful: touch)');
    }

    // ------------------------------------------------------------------ dynamic, never a lock

    public function testHerTraitsMovingMovesHerLanguageAndAChosenOneIsKept(): void
    {
        $d = $this->npc(['E' => 0.9, 'L' => 0.85, 'Po' => 0.3, 'Pd' => 0.25], self::ANXIOUS);
        RelationshipDynamics::ensureLoveLanguage('Mover', $d);
        $this->assertSame(RelationshipDynamics::LL_WORDS, $d['love_language_primary']);
        // she hardens and grows possessive (a later read, an editor trait change)
        $x = RelDynTraits::fromStored($d['trait_vector']);
        $x['E'] = 0.3; $x['L'] = 0.3; $x['Po'] = 0.9; $x['Pd'] = 0.85;
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src']['auto'] = RelDynTraits::toStored($x);
        RelationshipDynamics::ensureLoveLanguage('Mover', $d);
        $this->assertSame(RelationshipDynamics::LL_SERVICE, $d['love_language_primary'], 're-derived: what she holds from the derivation moves with her');
        $d['love_language_primary'] = RelationshipDynamics::LL_GIFTS;   // the editor's choice
        $x['E'] = 0.95; $x['Po'] = 0.1; $x['Pd'] = 0.1;
        $d['trait_vector'] = RelDynTraits::toStored($x);
        $d['_trait_vector_src']['auto'] = RelDynTraits::toStored($x);
        RelationshipDynamics::ensureLoveLanguage('Mover', $d);
        $this->assertSame(RelationshipDynamics::LL_GIFTS, $d['love_language_primary'], 'chosen: kept');
    }

    public function testTheSecondaryIsNeverTheSamePrimaryWhicheverWasChosen(): void
    {
        foreach ([['E' => 0.9, 'L' => 0.9, 'Po' => 0.1, 'Pd' => 0.1], ['E' => 0.1, 'L' => 0.1, 'Po' => 0.9, 'Pd' => 0.9]] as $traits) {
            $d = $this->npc($traits, self::TOXIC);
            RelationshipDynamics::ensureLoveLanguage('Pair', $d);
            $this->assertNotSame($d['love_language_primary'], $d['love_language_secondary']);
        }
    }

    public function testAStoredRowWithoutTheChoicesTableTakesTheDefaultTable(): void
    {
        $cfg = RelationshipDynamics::defaultConfig();
        unset($cfg['love_language_attachment']['choices']);       // a row saved before the table existed
        $this->storeConfig($cfg);
        $this->assertSame(RelationshipDynamics::LL_SERVICE,
            $this->primary(['E' => 0.2, 'L' => 0.2, 'Po' => 0.9, 'Pd' => 0.9], self::ANXIOUS));
    }

    /** Hand the engine a stored settings row (a stub database holding it). */
    private function storeConfig(array $cfg): void
    {
        $GLOBALS['db'] = new RelDynMiscReassuranceConfigDb($cfg);
        RelationshipDynamics::clearConfigCache();
    }
}
