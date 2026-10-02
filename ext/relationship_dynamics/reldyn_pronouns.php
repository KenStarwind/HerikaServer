<?php
/**
 * RelDyn pronouns (decisions 2026-10-01 §21): RelDyn is for every character regardless of gender.
 *
 * Text that reaches an LLM, Sharmat, the player or Ken never carries a bare she / he / her / him / his. It names the
 * NPC ({NAME}) or uses the pronoun vars below, which resolve from the NPC's own core gender (core_npc_master.gender,
 * one read per NPC per request scope). An NPC whose gender core does not state (empty, "other", a creature row, a failed
 * read) gets the neutral forms (they / them / their): never a default she or he.
 *
 *   {THEY} {They}          subject          he / she / they
 *   {THEM} {Them}          object           him / her / them
 *   {THEIR} {Their}        possessive       his / her / their
 *   {THEIRS}               possessive alone his / hers / theirs
 *   {THEMSELF}             reflexive        himself / herself / themself
 *   {THEY_ARE} {They_are}  subject + be     he is / she is / they are
 *   {THEY_HAVE} {They_have} subject + have  he has / she has / they have
 *   {S}                    verb suffix      "s" for he / she, "" for they: "{THEY} walk{S}"
 *
 * Case matters: {THEY} lower-cases to the form as written ("he"), {They} capitalises it ("He"). The vars resolve at
 * every place RelDyn hands text on (RelDynFelt, the consent decision, the exclusivity block, the diary prompt, the eval
 * prompt); fill() is a no-op on text with no var, so it never touches the database for the usual prose.
 */

final class RelDynPronouns
{
    const MASCULINE = 'm';
    const FEMININE = 'f';
    const NEUTRAL = 'n';

    /** form key => [masculine, feminine, neutral] */
    private const FORMS = [
        'they'   => ['he', 'she', 'they'],
        'them'   => ['him', 'her', 'them'],
        'their'  => ['his', 'her', 'their'],
        'theirs' => ['his', 'hers', 'theirs'],
        'self'   => ['himself', 'herself', 'themself'],
        'are'    => ['he is', 'she is', 'they are'],
        'have'   => ['he has', 'she has', 'they have'],
        's'      => ['s', 's', ''],
    ];

    /** token => [form key, capitalised] */
    private const TOKENS = [
        '{THEY}' => ['they', false], '{They}' => ['they', true],
        '{THEM}' => ['them', false], '{Them}' => ['them', true],
        '{THEIR}' => ['their', false], '{Their}' => ['their', true],
        '{THEIRS}' => ['theirs', false], '{Theirs}' => ['theirs', true],
        '{THEMSELF}' => ['self', false], '{Themself}' => ['self', true],
        '{THEY_ARE}' => ['are', false], '{They_are}' => ['are', true],
        '{THEY_HAVE}' => ['have', false], '{They_have}' => ['have', true],
        '{S}' => ['s', false],
    ];

    /** @var array<string, string> lower(npc) => m|f|n, for the request scope $scope only */
    private static array $gender = [];
    private static ?string $scope = null;

    /** Forget the cached genders (a request scope ends, or a test changes a core row). */
    public static function reset(): void
    {
        self::$gender = [];
        self::$scope = null;
    }

    /** Every var this class resolves, for the settings hub's hint text. */
    public static function tokens(): array
    {
        return array_keys(self::TOKENS);
    }

    /** True when $text carries at least one pronoun var. */
    public static function hasTokens(string $text): bool
    {
        if (strpos($text, '{') === false) return false;
        foreach (self::TOKENS as $token => $_) {
            if (strpos($text, $token) !== false) return true;
        }
        return false;
    }

    /** m | f | n from a core gender value ("Male", "female", "m", "man"...); anything else is neutral. */
    public static function classify($gender): string
    {
        $g = strtolower(trim((string) $gender));
        if (in_array($g, ['male', 'm', 'man', 'masculine', 'boy', 'he'], true)) return self::MASCULINE;
        if (in_array($g, ['female', 'f', 'woman', 'feminine', 'girl', 'she'], true)) return self::FEMININE;
        return self::NEUTRAL;
    }

    /**
     * The NPC's own gender class from core_npc_master.gender: one read per NPC inside an open request scope (never cached
     * outside one, so a long-lived process never acts on a stale row); neutral when unknown or unreadable.
     */
    public static function genderOf(string $npcName): string
    {
        $key = strtolower(trim($npcName));
        if ($key === '') return self::NEUTRAL;
        $scope = RelationshipDynamics::requestScopeToken();
        if ($scope !== null) {
            if (self::$scope !== $scope) { self::$gender = []; self::$scope = $scope; }
            if (isset(self::$gender[$key])) return self::$gender[$key];
        }
        $class = self::NEUTRAL;
        try {
            $row = RelationshipDynamics::fetchCoreProfileRow($npcName);
            $class = self::classify($row['gender'] ?? '');
        } catch (Throwable $e) {
            error_log("[RelDyn] ERROR pronouns: core_npc_master read failed for {$npcName}, neutral forms used: " . $e->getMessage());
            return self::NEUTRAL;   // not cached: the next call retries
        }
        if ($scope !== null) self::$gender[$key] = $class;
        return $class;
    }

    /** One form for a gender class: pronoun('they', 'f') = "she". */
    public static function form(string $form, string $class): string
    {
        $i = $class === self::MASCULINE ? 0 : ($class === self::FEMININE ? 1 : 2);
        return self::FORMS[$form][$i];
    }

    /** token => resolved form, for $npcName (the table fill() applies; usable inside a caller's own strtr). */
    public static function vars(string $npcName): array
    {
        $class = self::genderOf($npcName);
        $out = [];
        foreach (self::TOKENS as $token => [$form, $cap]) {
            $word = self::form($form, $class);
            $out[$token] = $cap ? ucfirst($word) : $word;
        }
        return $out;
    }

    /** $text with the pronoun vars resolved for $npcName; text without a var comes back untouched (no lookup). */
    public static function fill(string $text, string $npcName): string
    {
        if (!self::hasTokens($text)) return $text;
        return strtr($text, self::vars($npcName));
    }
}
