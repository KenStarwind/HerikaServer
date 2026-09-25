<?php
/**
 * RelDyn personality traits, phase 2: the bio read (D:\docs\reldyn-personality-traits-design.md
 * §4.2 and §4.7; decisions 2026-09-23 §16 #4 and #10).
 *
 * One LLM read per bio template turns the template's text fields into the ten trait values,
 * each with a confidence and a verbatim quote of at most 12 words from one field. A quote that
 * is not in that field (whitespace, case and quote marks normalized) zeroes that trait's
 * confidence, so nothing the model invents moves an NPC. Reads centre by default (ruling #10):
 * a value outside 0.25..0.75 needs confidence >= 0.7, otherwise it is pulled to the band edge.
 *
 * The read is keyed by the template, not by the NPC: template_key = the bio template's own
 * npc_name (combined_bio_templates, core's codename form, e.g. aela_the_huntress), src_hash =
 * sha1 of the prompt version and the six text fields sent. `core` is never sent (it can hold
 * director's notes), nor the live profile (the dynamic profile rewrites it).
 *
 * Its own table reldyn_trait_reads (template_key, src_hash) is the cache and the queue in one:
 * no npc_id, never in the eval queue's way. The committed seed (data/trait_reads_seed.json,
 * quotes only, no bios) is loaded on install / first use; an NPC not in it gets a 'pending' row
 * on first meeting, which the eval worker drains AFTER its own jobs (drain()). Until a read is
 * done the NPC keeps its priors (RelDynTraitAssign).
 *
 * Skip list (trait_reader.skip, template keys): never read, never enqueued, no quote stored.
 * Ashe is on it: her vector is the hand-set conclusion in config npc_overrides.
 *
 * Units: trait values and confidences 0..1 (rounded to 0.05); maturity_start 0..100 (rounded to 5).
 */

final class RelDynTraitRead
{
    /** Prompt version (traits_v1). A bump re-reads every template (new src_hash). */
    const PROMPT_V = 1;

    const TABLE = 'reldyn_trait_reads';

    /** The template fields sent to the read, in prompt order. `core` is never sent. */
    const FIELDS = ['personality', 'relationships', 'npc_static_bio', 'speechstyle', 'goals', 'occupation'];

    /** Storage names of the ten traits (RelDynTraits::TRAITS values), the output keys. */
    const TRAIT_KEYS = ['guard', 'expressiveness', 'confidence', 'pride', 'resilience', 'reactivity',
                        'warmth', 'restraint', 'possessiveness', 'protectiveness'];

    const MAX_QUOTE_WORDS = 12;

    /** Ruling #10: outside [CENTRE_LO, CENTRE_HI] a value needs conf >= EXTREME_CONF. */
    const CENTRE_LO = 0.25;
    const CENTRE_HI = 0.75;
    const EXTREME_CONF = 0.7;
    /** The same rule for maturity_start (0..100). */
    const MATURITY_CENTRE = [30.0, 70.0];

    /**
     * Evidence gate version (screen()). 1: the quote rule and conf-gated centring only.
     * 2 (review 2026-09-25): the evidence screen. A result stored under an older gate is screened
     * again when it is looked up (stateFor), so no read is repeated for a gate change.
     */
    const GATE_V = 2;

    /** Fields that may carry an extreme (ruling #10): the character described, not her job or aims. */
    const EXTREME_FIELDS = ['personality', 'speechstyle', 'npc_static_bio', 'relationships'];

    /** A conf-0.7+ extreme without supporting evidence is kept at the band edge with at most this conf. */
    const WEAK_EXTREME_CONF = 0.6;

    /**
     * Cue words an extreme must carry in its quote (screen(), rule E), per trait and direction,
     * on the normalized quote (lower case). A crude but auditable test that the quote names the
     * quality: "unwavering loyalty to the Dark Brotherhood" names no confidence.
     */
    const EXTREME_CUES = [
        'guard' => [
            'hi' => "/\\b(guarded|wary|wariness|suspicio\\w*|distrust\\w*|mistrust\\w*|trusts? (very )?few|trusts? no ?one|paranoi\\w*|facade|aloof|reserved|secretive|cautious|closed off|arm's length|at a distance|keeps? \\w+ at|intimidating|standoffish|reclusive|withdrawn)\\b/",
            'lo' => "/\\b(trusts? easily|trusting|open-hearted|welcoming|approachable|naive|lets? anyone|friendly to (all|everyone|strangers))\\b/",
        ],
        'expressiveness' => [
            'hi' => '/\b(exaggerated|dramatic|theatrical|boisterous|flamboyant|emotional|expressive|animated|sing-song|jovial|passionate|loud\w*|effusive|heart on|exuberant|enthusias\w*|laughs?|bubbly|excitab\w*)\b/',
            'lo' => '/\b(rarely (show|express)\w*|stoic\w*|reserved|impassive|contained|hard to read|emotionless|unreadable|stone-faced|deadpan|terse|laconic|hides? (her|his|their) (feelings|emotions)|composed)\b/',
        ],
        'confidence' => [
            'hi' => '/\b(confiden\w*|self-assured|assured|commanding|authoritative|authority|arrogan\w*|bold|fearless|assertive|domineering|leadership|superiority|self-important\w*|swagger\w*|cocky|brash)\b/',
            'lo' => '/\b(timid|shy|insecure|self-doubt\w*|doubts? (her|him|them)sel\w*|meek|fearful|nervous|anxious|hesitant|coward\w*|diffident|unsure|afraid)\b/',
        ],
        'pride' => [
            'hi' => '/\b(proud|prideful|pride|arrogan\w*|vain|vanity|haughty|superior\w*|beneath (her|his|their)|condescen\w*|conceited|self-important\w*|ego\w*|entitle\w*|demands? respect|snob\w*|pompous|insufferabl\w*)\b/',
            'lo' => '/\b(humble|humility|modest\w*|self-effacing|unassuming|meek)\b/',
        ],
        'resilience' => [
            'hi' => '/\b(resilien\w*|endur\w*|surviv\w*|unbreakable|hard to break|persever\w*|weathered|recover\w*|bounced back|tough\w*|indomitable|undaunted|rebuil\w*|overc[oa]me\w*)\b/',
            'lo' => '/\b(fragile|broken|breaks|shattered|never recovered|brittle|crushed|despair\w*|defeated|gave up)\b/',
        ],
        'reactivity' => [
            'hi' => '/\b((short|quick|hot|bad|fiery)[- ]temper\w*|temper|rage|volatile|explosive|quick to anger|hot-headed|erratic|unstable|mood swings?|snaps?|outbursts?|panic\w*|hysteric\w*|furious|fury|lashes out|unpredictable)\b/',
            'lo' => '/\b(even-tempered|calm|steady|unflappable|placid|serene|measured|level-headed|glacial|unshak\w*|imperturbable)\b/',
        ],
        'warmth' => [
            'hi' => '/\b(warm\w*|welcoming|kind\w*|friendl\w*|gentle|caring|generous|compassion\w*|cheerful|affable|amiable|hospitab\w*|good-natured|open-hearted|big-hearted)\b/',
            'lo' => '/\b(cold\w*|curt|dismissive|indifferen\w*|contempt\w*|disdain\w*|hostil\w*|callous|rude|aloof|tools|cruel\w*|scorn\w*|harsh|unfriendly|icy|ruthless|misanthrop\w*)\b/',
        ],
        'restraint' => [
            'hi' => '/\b(disciplin\w*|dutiful|duty|self-control\w*|controlled|composed|restrain\w*|reserved|stoic\w*|professional|no-nonsense|principled|honou?r\w*|oath|sworn|loyal\w*|law-abiding|measured|by the book)\b/',
            'lo' => '/\b(impulsive|reckless|rash|defian\w*|rebel\w*|wild|undisciplined|hot-headed|lawless|carefree|hedonis\w*|indulgen\w*|whims?)\b/',
        ],
        'possessiveness' => [
            'hi' => self::POSSESSIVE_CUE,
            'lo' => '/\b(never jealous|not jealous|free-spirited|lets? (her|him|them) go)\b/',
        ],
        'protectiveness' => [
            'hi' => self::CARE_CUE,   // and a loved one (rule P)
            'lo' => '/\b(fend for|selfish|callous|indifferen\w*|neglect\w*|abandon\w*|leaves? (others|them)|replaceable|tools?|inconvenien\w*)\b/',
        ],
    ];

    /** Possessiveness (rule O): jealousy, control, rivals. A treasure, a daughter or a cause is not it. */
    const POSSESSIVE_CUE = "/\\b(jealous\\w*|possessiv\\w*|control\\w*|rivals?|suitors?|come between|obsess\\w*|covet\\w*|env(y|ious)|clingy|won't share|all to (her|him)sel\\w*|smother\\w*)\\b/";

    /** Protectiveness (rule P): worry or care for someone's safety. */
    const CARE_CUE = '/\b(protect\w*|guardian|guards?|guarding|safe|safety|shield\w*|defend\w*|worr(y|ies|ied)|concern(ed|s)?|car(e|es|ing)|looks? after|watch(es)? over|devot\w*|affection\w*|nurtur\w*|rais(e|ed|ing))\b/';

    /** The people one loves (rule P): kin, partners, friends, one's own people. */
    const LOVED_CUE = '/\b(daughters?|sons?|child|children|kids?|family|wife|husband|spouse|mother|father|sisters?|brothers?|siblings?|friends?|fellow|followers|kin|loved ones?|(her|his|their) people|students|pupils|wards?|nieces?|nephews?|grand\w+|lover|little ones?)\b/';

    /**
     * A duty, a post, a place, an order or a thing (rule P: the prompt's "duty to protect a
     * place or a lord is restraint"; hatred of an enemy is neither).
     */
    const DUTY_CUE = "/\\b(sworn|oath|bound by|dut(y|ies)|serv(e|es|ed|ing)|housecarl|bodyguard|post|order|security|justice|reputation|role|job|works?|position|hold|city|town|village|realm|empire|kingdom|crossing|caravan|guild|college|shrine|sepulcher|temple|legacy|key|traditions?|culture|power|authority|wealth|gold|business|shop|farm|status|the mine|the people of|jarl|thane|lord|master|night mother|skyrim|tamriel|whiterun|solitude|windhelm|riften|markarth|morthal|dawnstar|winterhold|falkreath|riverwood|rorikstead|ivarstead|dragonsreach)\\b/";

    /** Relationship entries (type + relation) by kind. */
    const REL_FAMILY = '/\b(familial|family|daughter|son|child|mother|father|mom|dad|sister|brother|sibling|aunt|uncle|niece|nephew|cousin|grand\w*|parent|ward|adopt\w*|step\w*|twin)\b/';
    const REL_ROMANTIC = '/\b(wife|husband|spouse|lover|betrothed|fianc\w*|girlfriend|boyfriend|romantic|romance|beloved|courting|suitor|crush|ex-\w+)\b/';
    const REL_FRIEND = '/\b(friends?|friendly|companion|confidante?|comrade|shield-\w+|loving)\b/';
    const REL_DUTY = '/\b(housecarl|bodyguard|jarl|thane|employer|employee|professional|lord|master|servant|client|superior|subordinate|commander|steward|captain|appointer|patron|liege)\b/';
    const REL_FEMALE = '/\b(wife|daughter|mother|mom|sister|aunt|niece|girlfriend|grandmother|granddaughter|stepdaughter|stepmother|queen|matron)\b/';
    const REL_MALE = '/\b(husband|son|father|dad|brother|uncle|nephew|boyfriend|grandfather|grandson|stepson|stepfather|king)\b/';

    /** Pride (rule D): taking pride in one's craft, family or order is not ego (the prompt says so). */
    const PRIDE_IN_WORK = '/\b((takes?|taking|took)\s+(\w+\s+){0,2}pride in|pride in (her|his|their|the)|proud of (her|his|their))\b/';

    /** Advisory lock (RelDynEval::LOCK_CLASS, LOCK_TRAITS): one trait drainer at a time. */
    const LOCK_TRAITS = 2;

    const SEED_FILE = __DIR__ . '/data/trait_reads_seed.json';

    /** Test hooks: the LLM (callable(array $messages, array $params): ?string) and the worker launcher. */
    public static $llm = null;
    public static $launcher = null;

    private static $memo = [];
    private static $memoToken = null;
    private static $seedChecked = [];

    // =========================================================================
    // CONFIG
    // =========================================================================

    public static function defaultConfig(): array
    {
        return [
            'enabled'      => true,
            'connector_id' => 0,      // core_llm_connector id; 0 = the eval connector, else core RELLLM_CONNECTOR
            'jobs_per_run' => 5,      // reads one worker handles after the eval queue is empty
            'max_attempts' => 3,      // failed reads before the row is dead (priors stay)
            'max_tokens'   => 900,    // completion tokens for one read
            'temperature'  => 0.3,    // a rating task; determinism comes from the cache (design §4.2)
            'autostart_worker' => true,
            'skip'         => ['ashe'],   // template keys never read (spoiler screen, hand-set vectors)
        ];
    }

    /** RelDyn config key 'trait_reader' over defaultConfig(); read fresh. */
    public static function config(): array
    {
        $stored = class_exists('RelationshipDynamics') ? (RelationshipDynamics::getConfig()['trait_reader'] ?? []) : [];
        $cfg = array_merge(self::defaultConfig(), is_array($stored) ? $stored : []);
        // Ashe is always screened, whatever the stored list says.
        $cfg['skip'] = array_values(array_unique(array_merge(['ashe'], array_map(fn($k) => strtolower(trim((string) $k)), (array) $cfg['skip']))));
        return $cfg;
    }

    public static function isSkipped(string $templateKey, ?array $cfg = null): bool
    {
        $cfg = $cfg ?? self::config();
        $k = strtolower(trim($templateKey));
        foreach ((array) $cfg['skip'] as $s) {
            if ($k === $s || self::matchKey($k) === self::matchKey((string) $s)) return true;
        }
        return false;
    }

    // =========================================================================
    // TEMPLATE KEY, FIELDS, HASH
    // =========================================================================

    /** Letters and digits only (voice / template name comparison). */
    public static function matchKey(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /**
     * Template keys to try for an NPC name, most specific first: core's codename
     * (npcNameToCodename: lower case, spaces -> _, ' -> +) and the apostrophe-kept form the
     * bio templates use ("j'zargo").
     */
    public static function keyCandidates(string $npcName): array
    {
        $n = strtolower(trim($npcName));
        $codename = preg_replace('/[^\w+-]/u', '', strtr($n, [' ' => '_', "'" => '+'])) ?? '';
        $alt = preg_replace("/[^\\w'+-]/u", '', strtr($n, [' ' => '_'])) ?? '';
        return array_values(array_unique(array_filter([$codename, $alt], fn($k) => $k !== '')));
    }

    /** The six text fields of a template row, trimmed ('' when absent). */
    public static function fieldsOf(array $row): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $v = $row[$f] ?? '';
            $out[$f] = is_string($v) ? trim($v) : '';
        }
        return $out;
    }

    /** sha1 of the prompt version and the fields sent: changes when the template text or the prompt does. */
    public static function srcHash(array $fields): string
    {
        return sha1(json_encode(['p' => self::PROMPT_V, 'f' => self::fieldsOf($fields)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** True when the template has any text to read. */
    public static function hasText(array $fields): bool
    {
        foreach (self::fieldsOf($fields) as $v) if ($v !== '') return true;
        return false;
    }

    /**
     * The bio template of an NPC from combined_bio_templates: ['key', 'fields'] or null.
     * Throws on a DB error.
     */
    public static function fetchTemplate(string $npcName, $db = null): ?array
    {
        $db = $db ?? ($GLOBALS['db'] ?? null);
        if (!$db) return null;
        $cands = self::keyCandidates($npcName);
        if (!$cands || !self::tableExists('combined_bio_templates', $db)) return null;
        foreach ($cands as $key) {
            $row = $db->fetchOne(
                'SELECT npc_name, ' . implode(', ', self::FIELDS) . ' FROM combined_bio_templates WHERE lower(npc_name) = lower($1) LIMIT 1',
                [$key]
            );
            if (is_array($row) && isset($row['npc_name'])) {
                return ['key' => strtolower((string) $row['npc_name']), 'fields' => self::fieldsOf($row)];
            }
        }
        return null;
    }

    /** Tables that exist (per process; a missing table is not an error: no template, no voice). */
    private static $tables = [];

    public static function tableExists(string $table, $db = null): bool
    {
        $db = $db ?? ($GLOBALS['db'] ?? null);
        if (!$db) return false;
        $k = spl_object_id($db) . ':' . $table;
        if (!isset(self::$tables[$k])) {
            $row = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [$table]);
            self::$tables[$k] = self::isTrue($row['present'] ?? null);
        }
        return self::$tables[$k];
    }

    /**
     * The NPC's voice type for the voice prior: npc_templates_v2.xvasynth_voiceid by template key
     * or name (letters and digits compared: "j+zargo", "Adrianne Avenicci"), else $fallback (the
     * core row's voiceid, usually empty when the profile is first derived). design §4.4.
     */
    public static function voiceFor(?string $templateKey, string $npcName, ?string $fallback = null): ?string
    {
        $db = $GLOBALS['db'] ?? null;
        try {
            if ($db && self::tableExists('npc_templates_v2', $db)) {
                foreach (array_unique(array_filter([self::matchKey((string) $templateKey), self::matchKey($npcName)])) as $k) {
                    $row = $db->fetchOne("SELECT xvasynth_voiceid FROM npc_templates_v2
                        WHERE lower(regexp_replace(npc_name, '[^a-zA-Z0-9]', '', 'g')) = \$1 LIMIT 1", [$k]);
                    $v = is_string($row['xvasynth_voiceid'] ?? null) ? trim($row['xvasynth_voiceid']) : '';
                    if ($v !== '') return $v;
                }
            }
        } catch (\Throwable $e) {
            error_log('[RelDyn-TRAITS] voice lookup failed for ' . $npcName . ': ' . $e->getMessage());
        }
        $fallback = is_string($fallback) ? trim($fallback) : '';
        return $fallback !== '' ? $fallback : null;
    }

    // =========================================================================
    // PROMPT traits_v1 (design §4.2)
    // =========================================================================

    public static function systemPrompt(): string
    {
        return <<<'TXT'
You rate a Skyrim character's personality for a relationship simulation, from the given text only.

Traits, each 0..1 (0.5 = ordinary, or no evidence):
- guard: how hard it is to get close at first. 0.1 lets anyone in at once; 0.5 ordinary caution; 0.9 arm's length until trust is earned. Entry only, not discomfort once someone is close.
- expressiveness: how visibly feeling shows. 0.1 contained, hard to read; 0.9 heart on sleeve, big displays.
- confidence: self-assurance, assertiveness. 0.1 timid, self-doubting; 0.9 commanding, sure of self.
- pride: ego invested in status and respect. 0.1 humble, unbothered by slights; 0.9 vain or haughty, demands respect, slights land hard. Confidence is self-assurance; pride is ego and status. Taking pride in one's craft, family or order is not ego.
- resilience: how hardship leaves them. 0.1 breaks easily, slow to rebuild; 0.9 recovers, hard to break.
- reactivity: size of emotional swings. 0.1 steady, glacial; 0.9 volatile, quick temper or panic. Resilience is recovering vs breaking; reactivity is how big the swings are.
- warmth: default friendliness toward anyone. 0.1 cold, curt; 0.9 kind and friendly to all. Warmth kept for those who earned it is guard, not warmth.
- restraint: duty and self-control first. 0.1 impulsive, defiant; 0.9 dutiful, disciplined, keeps feelings in. A sworn or civic duty to protect a hold, a lord or a post belongs here. Having a job is not evidence.
- possessiveness: fear of losing a partner to rivals; jealousy, control. 0.1 none; 0.9 jealous, controlling.
- protectiveness: worry for a loved one's safety and wellbeing. 0.1 leaves others to fend; 0.9 fiercely guards the people they love. Duty to protect a place or a lord is restraint; hatred or revenge against an enemy is neither.

maturity_start, 0..100: emotional maturity now. 20 petty, impulsive; 50 ordinary adult; 80 wise, self-aware.

Rules:
- Stay near the middle (0.35..0.65) unless the text clearly says otherwise. Go below 0.25 or above 0.75 only with strong, explicit evidence.
- No evidence: value 0.5 (maturity 50), conf 0, field null, evidence null.
- conf 0..1: how directly the text shows it (0.9 stated outright, 0.6 clearly implied, 0.3 hinted).
- evidence: words copied exactly from ONE field, 12 words or fewer, no ellipsis, no paraphrase. field: that field's name.
- Fields you may cite: personality, relationships, npc_static_bio, speechstyle, goals, occupation.
- Do not infer anything from race, gender or looks.

Output JSON only, in exactly this shape:
{"v":1,"traits":{"guard":{"value":0.5,"conf":0,"field":null,"evidence":null},"expressiveness":{...},"confidence":{...},"pride":{...},"resilience":{...},"reactivity":{...},"warmth":{...},"restraint":{...},"possessiveness":{...},"protectiveness":{...}},"maturity_start":{"value":50,"conf":0,"field":null,"evidence":null}}
TXT;
    }

    /** A readable display name from a template key ("aela_the_huntress" -> "Aela the Huntress"). */
    public static function displayName(string $templateKey): string
    {
        $words = preg_split('/[_\s]+/', str_replace('+', "'", $templateKey)) ?: [];
        $small = ['the', 'of', 'and', 'in', 'at', 'gro', 'gra', 'af', 'al'];
        $out = [];
        foreach ($words as $i => $w) {
            if ($w === '') continue;
            $out[] = ($i > 0 && in_array($w, $small, true)) ? $w
                : implode('-', array_map(fn($p) => mb_convert_case($p, MB_CASE_TITLE, 'UTF-8'), explode('-', $w)));
        }
        return implode(' ', $out);
    }

    public static function buildMessages(string $displayName, array $fields): array
    {
        $parts = ["Character: {$displayName}"];
        foreach (self::fieldsOf($fields) as $f => $text) {
            if ($text === '') continue;
            $parts[] = "[{$f}]\n{$text}";
        }
        return [
            ['role' => 'system', 'content' => self::systemPrompt()],
            ['role' => 'user', 'content' => implode("\n\n", $parts)],
        ];
    }

    // =========================================================================
    // PARSE + VALIDATE (strict, RelDynEval::parseResponse pattern)
    // =========================================================================

    /** Lower case, one space, straight quotes, JSON-escaped quotes unescaped. */
    public static function normalizeText(string $s): string
    {
        $s = str_replace(['\\"', "\\'", '\\n', '\\/'], ['"', "'", ' ', '/'], $s);
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{201B}", '`', "\u{00B4}"], "'", $s);
        $s = str_replace(["\u{201C}", "\u{201D}", "\u{201E}"], '"', $s);
        $s = str_replace(["\u{2013}", "\u{2014}"], '-', $s);
        $s = mb_strtolower($s, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** A quote as it is compared: normalized, outer quote marks and edge punctuation removed. */
    public static function normalizeQuote(string $q): string
    {
        $q = self::normalizeText($q);
        return trim($q, " \t\"'.,;:!?*-");
    }

    public static function wordCount(string $s): int
    {
        $s = trim($s);
        return $s === '' ? 0 : count(preg_split('/\s+/u', $s) ?: []);
    }

    /** True when $quote (<= 12 words, >= 3 chars) is in $fieldText after normalization. */
    public static function quoteMatches(?string $quote, string $fieldText): bool
    {
        if (!is_string($quote)) return false;
        $q = self::normalizeQuote($quote);
        if (mb_strlen($q) < 3 || self::wordCount($q) > self::MAX_QUOTE_WORDS) return false;
        if (strpos($q, '...') !== false || strpos($q, "\u{2026}") !== false) return false;
        return strpos(self::normalizeText($fieldText), $q) !== false;
    }

    public static function round05(float $v): float
    {
        return round(round($v * 20.0) / 20.0, 2);
    }

    /**
     * Parse and validate a raw read against the template's fields. Returns the result
     * ['v' => 1, 'traits' => [name => [value, conf, field, evidence, note?]], 'maturity_start' => [...]]
     * or null (with $reason) when the output is unusable (counts as a failed attempt).
     * A single trait with a bad quote or field is not a failure: its conf becomes 0.
     * The result then passes the evidence screen (screen(); $gender = the NPC's, for rule S).
     */
    public static function parse(string $raw, array $fields, ?string &$reason = null, ?string $gender = null): ?array
    {
        $reason = null;
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a === false || $b === false || $b <= $a) { $reason = 'no JSON object'; return null; }
        $data = json_decode(substr($text, $a, $b - $a + 1), true);
        if (!is_array($data)) { $reason = 'invalid JSON'; return null; }
        if (isset($data['v']) && intval($data['v']) !== 1) { $reason = 'unknown version'; return null; }
        $extra = array_diff(array_keys($data), ['v', 'traits', 'maturity_start']);
        if ($extra) { $reason = 'unexpected keys: ' . implode(',', array_slice($extra, 0, 3)); return null; }
        $traits = $data['traits'] ?? null;
        if (!is_array($traits)) { $reason = 'no traits object'; return null; }
        $keys = array_keys($traits);
        sort($keys);
        $want = self::TRAIT_KEYS;
        sort($want);
        if ($keys !== $want) { $reason = 'trait keys differ'; return null; }
        $fields = self::fieldsOf($fields);

        $out = ['v' => 1, 'traits' => []];
        foreach (self::TRAIT_KEYS as $name) {
            $t = $traits[$name];
            if (!is_array($t) || !is_numeric($t['value'] ?? null) || !is_numeric($t['conf'] ?? 0)) {
                $reason = "{$name}: value/conf not numeric";
                return null;
            }
            $v = floatval($t['value']);
            $c = floatval($t['conf'] ?? 0);
            if ($v < 0.0 || $v > 1.0 || $c < 0.0 || $c > 1.0) { $reason = "{$name}: out of range"; return null; }
            $out['traits'][$name] = self::checkEntry($v, $c, $t, $fields, 0.0, 1.0, [self::CENTRE_LO, self::CENTRE_HI], 'round05');
        }

        $m = $data['maturity_start'] ?? null;
        if (is_array($m) && is_numeric($m['value'] ?? null) && is_numeric($m['conf'] ?? 0)) {
            $v = floatval($m['value']);
            $c = floatval($m['conf'] ?? 0);
            if ($v < 0.0 || $v > 100.0 || $c < 0.0 || $c > 1.0) { $reason = 'maturity_start out of range'; return null; }
            $out['maturity_start'] = self::checkEntry($v, $c, $m, $fields, 0.0, 100.0, self::MATURITY_CENTRE, 'round5');
        } else {
            $out['maturity_start'] = ['value' => 50.0, 'conf' => 0.0, 'field' => null, 'evidence' => null, 'note' => 'missing'];
        }
        return self::screen($out, $fields, $gender);
    }

    /** One entry: quote check (conf 0 on a bad quote or field), rounding, centring (ruling #10). */
    private static function checkEntry(float $v, float $c, array $t, array $fields, float $lo, float $hi, array $centre, string $rounding): array
    {
        $field = is_string($t['field'] ?? null) ? strtolower(trim($t['field'])) : null;
        $evidence = is_string($t['evidence'] ?? null) ? trim($t['evidence']) : null;
        $note = null;
        if ($evidence === '' ) $evidence = null;
        if ($field === null || !isset($fields[$field])) {
            if ($c > 0.0 || $evidence !== null) $note = $field === 'core' ? 'core_field' : 'bad_field';
            $c = 0.0;
            $field = null;
            $evidence = null;
        } elseif (!self::quoteMatches($evidence, $fields[$field])) {
            if ($c > 0.0 || $evidence !== null) $note = 'quote_mismatch';
            $c = 0.0;
            $evidence = null;
        } else {
            $evidence = trim(self::collapse($evidence), " \t\"'");
        }
        if ($c <= 0.0) {
            $c = 0.0;
            if ($evidence === null) $field = null;
        }
        $v = $rounding === 'round5' ? round($v / 5.0) * 5.0 : self::round05($v);
        $c = self::round05($c);
        if ($c < self::EXTREME_CONF && ($v < $centre[0] || $v > $centre[1])) {
            $v = max($centre[0], min($centre[1], $v));
            $note = $note ?? 'centred';
        }
        $v = max($lo, min($hi, $v));
        $entry = ['value' => $v, 'conf' => $c, 'field' => $field, 'evidence' => $evidence];
        if ($note !== null) $entry['note'] = $note;
        return $entry;
    }

    private static function collapse(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    // =========================================================================
    // EVIDENCE SCREEN (GATE_V 2: ruling #10 enforced in code, review 2026-09-25)
    // =========================================================================

    /** 'female' | 'male' from a voice id ("sk_femalecommander", "FemaleEvenToned"), else null. */
    public static function genderOfVoice(?string $voiceId): ?string
    {
        $v = self::matchKey((string) $voiceId);
        if (strncmp($v, 'sk', 2) === 0) $v = substr($v, 2);
        if (strncmp($v, 'female', 6) === 0) return 'female';
        if (strncmp($v, 'male', 4) === 0) return 'male';
        return null;
    }

    /** The NPC's gender for rule S, from her npc_templates_v2 voice type (null when unknown). */
    public static function genderFor(string $templateKey): ?string
    {
        return self::genderOfVoice(self::voiceFor($templateKey, self::displayName($templateKey)));
    }

    /**
     * The relationships entry (CHIM's JSON: name => {type, relation, note, best, worst}) whose
     * text holds $quote: ['name', 'kind' => duty|family|romantic|friend|other, 'other_gender' =>
     * female|male|null (from a gendered relation word)], or null (not JSON, or not in one entry).
     */
    public static function relationshipEntryOf(string $quote, string $relationships): ?array
    {
        $data = json_decode($relationships, true);
        if (!is_array($data)) return null;
        $q = self::normalizeQuote($quote);
        if ($q === '') return null;
        foreach ($data as $name => $e) {
            if (!is_array($e)) continue;
            $text = '';
            foreach ($e as $v) if (is_string($v)) $text .= ' ' . $v;
            if (strpos(self::normalizeText($text), $q) === false) continue;
            $relation = self::normalizeText((string) ($e['relation'] ?? ''));
            $rel = self::normalizeText((string) ($e['type'] ?? '')) . ' ' . $relation;
            $kind = preg_match(self::REL_DUTY, $rel) ? 'duty'
                : (preg_match(self::REL_FAMILY, $rel) ? 'family'
                : (preg_match(self::REL_ROMANTIC, $rel) ? 'romantic'
                : (preg_match(self::REL_FRIEND, $rel) ? 'friend' : 'other')));
            $other = preg_match(self::REL_FEMALE, $relation) ? 'female' : (preg_match(self::REL_MALE, $relation) ? 'male' : null);
            return ['name' => (string) $name, 'kind' => $kind, 'other_gender' => $other];
        }
        return null;
    }

    /**
     * The evidence screen. Pure and idempotent: $result is a parse() result (or a stored one),
     * $fields the template's text fields, $gender the NPC's (female | male | null, her voice type).
     * The quote rule (checkEntry) only proves the words are in the bio; this asks whether they
     * show THIS trait of THIS character. Per trait with conf > 0:
     *   S  someone else: a relationships quote led by a pronoun of the other person (against the
     *      NPC's gender, or a gendered relation word such as "wife") describes that person.
     *   J  job text: the occupation field is not evidence ("having a job is not evidence").
     *   D  pride above 0.5 from taking pride in one's work, family or order: not ego.
     *   O  possessiveness (any value but 0.5) needs jealousy / control / rival words (or their
     *      absence named: "never jealous"), and above 0.5 not inside a family or duty
     *      relationship entry (it is about a partner and rivals, not a daughter or a treasure).
     *   P  protectiveness above 0.5 needs worry / care words; a duty, post, place, order or
     *      thing without a loved one is restraint, not protectiveness (the prompt's own rule).
     *      Below 0.5 it needs care words or their absence named ("a replaceable tool").
     * A rule that fires sets conf 0 (the trait keeps its prior), as a quote mismatch does.
     *   E  an extreme (outside 0.25..0.75; maturity 30..70) is kept only when its quote comes from
     *      a character field (EXTREME_FIELDS: not goals, not occupation) and names the quality
     *      (EXTREME_CUES; high protectiveness also a loved one). Otherwise it stays at the band
     *      edge with conf at most WEAK_EXTREME_CONF: a strong claim without strong evidence reads
     *      as an ordinary one (ruling #10).
     * A changed entry keeps what the read said under 'screen' (rule, the read's value, conf,
     * field and quote). $result['gate'] = GATE_V.
     */
    public static function screen(array $result, array $fields, ?string $gender = null): array
    {
        $fields = self::fieldsOf($fields);
        foreach (self::TRAIT_KEYS as $name) {
            if (is_array($result['traits'][$name] ?? null)) {
                $result['traits'][$name] = self::screenEntry($name, $result['traits'][$name], $fields, $gender);
            }
        }
        if (is_array($result['maturity_start'] ?? null)) {
            $result['maturity_start'] = self::screenEntry('maturity_start', $result['maturity_start'], $fields, $gender);
        }
        $result['gate'] = self::GATE_V;
        return $result;
    }

    private static function screenEntry(string $name, array $e, array $fields, ?string $gender): array
    {
        $v = floatval($e['value'] ?? 0.5);
        $c = floatval($e['conf'] ?? 0);
        $field = is_string($e['field'] ?? null) ? $e['field'] : null;
        $ev = is_string($e['evidence'] ?? null) ? $e['evidence'] : null;
        if ($c <= 0.0 || $field === null || $ev === null) return $e;
        $q = self::normalizeQuote($ev);
        $entry = $field === 'relationships' ? self::relationshipEntryOf($ev, $fields['relationships'] ?? '') : null;
        $kind = $entry['kind'] ?? null;
        $rule = null;
        if ($field === 'relationships' && preg_match('/^(she|her|hers|he|his|him)\b/', $q, $m)) {
            $pg = in_array($m[1], ['she', 'her', 'hers'], true) ? 'female' : 'male';
            $og = $entry['other_gender'] ?? null;
            if (($gender !== null && $pg !== $gender) || ($gender === null && $og !== null && $pg === $og)) $rule = 'someone_else';
        }
        if ($rule === null && $field === 'occupation') $rule = 'job';
        if ($rule === null && $name === 'pride' && $v > 0.5 && preg_match(self::PRIDE_IN_WORK, $q)) $rule = 'pride_in_work';
        if ($rule === null && $name === 'possessiveness' && $v != 0.5) {
            if (!preg_match(self::POSSESSIVE_CUE, $q) && !preg_match(self::EXTREME_CUES['possessiveness']['lo'], $q)) $rule = 'no_jealousy';
            elseif ($v > 0.5 && in_array($kind, ['family', 'duty'], true)) $rule = 'not_a_partner';
        }
        $loved = $name === 'protectiveness'
            && (preg_match(self::LOVED_CUE, $q) === 1 || in_array($kind, ['family', 'romantic', 'friend'], true));
        if ($rule === null && $name === 'protectiveness' && $v > 0.5) {
            if (!preg_match(self::CARE_CUE, $q)) $rule = 'no_care';
            elseif (!$loved && (preg_match(self::DUTY_CUE, $q) === 1 || $kind === 'duty')) $rule = 'duty';
        } elseif ($rule === null && $name === 'protectiveness' && $v < 0.5
            && !preg_match(self::CARE_CUE, $q) && !preg_match(self::EXTREME_CUES['protectiveness']['lo'], $q)) {
            $rule = 'no_care';
        }
        $centre = $name === 'maturity_start' ? self::MATURITY_CENTRE : [self::CENTRE_LO, self::CENTRE_HI];
        $outside = $v < $centre[0] || $v > $centre[1];
        $said = ['value' => $v, 'conf' => $c, 'field' => $field, 'evidence' => $ev];
        if ($rule !== null) {
            $e['value'] = $outside ? max($centre[0], min($centre[1], $v)) : $v;
            $e['conf'] = 0.0;
            $e['field'] = null;
            $e['evidence'] = null;
            $e['note'] = 'screened';
            $e['screen'] = ['rule' => $rule] + $said;
            return $e;
        }
        if ($outside) {
            $why = null;
            if (!in_array($field, self::EXTREME_FIELDS, true)) {
                $why = 'extreme_field';
            } elseif ($name !== 'maturity_start') {
                $dir = $v > $centre[1] ? 'hi' : 'lo';
                if (!preg_match(self::EXTREME_CUES[$name][$dir], $q)) $why = 'extreme_cue';
                elseif ($name === 'protectiveness' && $dir === 'hi' && !$loved) $why = 'extreme_no_loved_one';
            }
            if ($why !== null) {
                $e['value'] = max($centre[0], min($centre[1], $v));
                $e['conf'] = min($c, self::WEAK_EXTREME_CONF);
                $e['note'] = 'centred';
                $e['screen'] = ['rule' => $why] + $said;
            }
        }
        return $e;
    }

    // =========================================================================
    // TABLE, SEED, LOOKUP (design §4.7)
    // =========================================================================

    private static function db()
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) throw new RuntimeException('RelDynTraitRead: no database connection');
        return $db;
    }

    private static function isTrue($v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    public static function ensureTable(): void
    {
        $db = self::db();
        $row = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::TABLE]);
        if (self::isTrue($row['present'] ?? null)) return;
        $db->fetchOne('CREATE TABLE IF NOT EXISTS ' . self::TABLE . " (
            template_key text NOT NULL,
            src_hash text NOT NULL,
            prompt_v integer NOT NULL,
            status text NOT NULL DEFAULT 'pending',
            attempts integer NOT NULL DEFAULT 0,
            last_error text,
            model text,
            result jsonb,
            created timestamptz NOT NULL DEFAULT now(),
            updated timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (template_key, src_hash))");
        $check = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::TABLE]);
        if (!self::isTrue($check['present'] ?? null)) {
            throw new RuntimeException('RelDynTraitRead: could not create ' . self::TABLE);
        }
    }

    /** The committed seed: ['meta' => ..., 'reads' => [template_key => entry]] ([] when absent/invalid). */
    public static function loadSeedFile(?string $path = null): array
    {
        $path = $path ?? self::SEED_FILE;
        if (!is_file($path)) return [];
        $data = json_decode((string) file_get_contents($path), true);
        return (is_array($data) && is_array($data['reads'] ?? null)) ? $data : [];
    }

    /**
     * Load the seed's done reads into the table (INSERT ... ON CONFLICT DO NOTHING, so a
     * newer or failed row is never overwritten) once per seed content: a marker row
     * ('__seed__', sha1 of the file) records it. Returns the rows inserted.
     */
    public static function ensureSeedLoaded(?string $path = null): int
    {
        $path = $path ?? self::SEED_FILE;
        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        if ($raw === '') return 0;
        $sig = sha1($raw);
        if (isset(self::$seedChecked[$sig])) return 0;
        self::ensureTable();
        $db = self::db();
        $marker = $db->fetchOne('SELECT 1 AS present FROM ' . self::TABLE . ' WHERE template_key = $1 AND src_hash = $2', ['__seed__', $sig]);
        if (isset($marker['present'])) { self::$seedChecked[$sig] = true; return 0; }
        $seed = json_decode($raw, true);
        $n = 0;
        foreach ((array) ($seed['reads'] ?? []) as $key => $e) {
            if (!is_array($e) || ($e['status'] ?? '') !== 'done' || !is_array($e['result'] ?? null)) continue;
            if (self::isSkipped((string) $key)) continue;   // never, even if a seed carried one
            $r = $db->fetchOne(
                'INSERT INTO ' . self::TABLE . " (template_key, src_hash, prompt_v, status, attempts, model, result)
                 VALUES (\$1, \$2, \$3, 'done', \$4, \$5, \$6::jsonb) ON CONFLICT (template_key, src_hash) DO NOTHING RETURNING 1 AS ins",
                [strtolower((string) $key), (string) $e['src_hash'], intval($e['prompt_v'] ?? self::PROMPT_V), intval($e['attempts'] ?? 1),
                 'seed:' . (string) ($e['model'] ?? ''), json_encode($e['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            if (isset($r['ins'])) $n++;
        }
        $db->fetchOne('INSERT INTO ' . self::TABLE . " (template_key, src_hash, prompt_v, status, model) VALUES ('__seed__', \$1, \$2, 'seed', 'seed')
                       ON CONFLICT (template_key, src_hash) DO NOTHING", [$sig, self::PROMPT_V]);
        self::$seedChecked[$sig] = true;
        return $n;
    }

    /** Forget process memos (tests; a seed file swapped at runtime). */
    public static function reset(): void
    {
        self::$memo = [];
        self::$memoToken = null;
        self::$seedChecked = [];
        self::$tables = [];
    }

    /**
     * The read state of an NPC: ['key', 'hash', 'status' => none|skip|done|pending|dead|error,
     * 'result' (done only), 'model']. On a miss it enqueues a 'pending' row (one per key and
     * hash, however often it is called) and asks for a worker. Memoized per request scope.
     * Never throws: a DB problem is status 'error' (priors stay).
     */
    public static function stateFor(string $npcName, bool $enqueue = true): array
    {
        $token = class_exists('RelationshipDynamics') ? RelationshipDynamics::requestScopeToken() : null;
        if ($token === null || $token !== self::$memoToken) { self::$memo = []; self::$memoToken = $token; }
        $mk = strtolower(trim($npcName));
        if ($token !== null && isset(self::$memo[$mk])) return self::$memo[$mk];

        $state = ['key' => null, 'hash' => null, 'status' => 'none', 'result' => null, 'model' => null];
        try {
            $tpl = self::fetchTemplate($npcName);
            if ($tpl === null || !self::hasText($tpl['fields'])) {
                $state['key'] = $tpl['key'] ?? null;
            } elseif (self::isSkipped($tpl['key'])) {
                $state = ['key' => $tpl['key'], 'hash' => null, 'status' => 'skip', 'result' => null, 'model' => null];
            } else {
                $state['key'] = $tpl['key'];
                $state['hash'] = self::srcHash($tpl['fields']);
                self::ensureSeedLoaded();
                $db = self::db();
                $row = $db->fetchOne('SELECT status, result::text AS result, model FROM ' . self::TABLE . ' WHERE template_key = $1 AND src_hash = $2',
                    [$state['key'], $state['hash']]);
                if (isset($row['status'])) {
                    $state['status'] = (string) $row['status'];
                    $state['model'] = $row['model'] ?? null;
                    if ($state['status'] === 'done') {
                        $res = json_decode((string) ($row['result'] ?? ''), true);
                        if (is_array($res) && is_array($res['traits'] ?? null)) {
                            // a read stored under an older evidence gate is screened again (no new read)
                            if (intval($res['gate'] ?? 1) < self::GATE_V) $res = self::screen($res, $tpl['fields'], self::genderFor($tpl['key']));
                            $state['result'] = $res;
                        } else {
                            $state['status'] = 'dead';
                        }
                    }
                } elseif ($enqueue) {
                    $ins = $db->fetchOne('INSERT INTO ' . self::TABLE . " (template_key, src_hash, prompt_v, status) VALUES (\$1, \$2, \$3, 'pending')
                                          ON CONFLICT (template_key, src_hash) DO NOTHING RETURNING 1 AS ins",
                        [$state['key'], $state['hash'], self::PROMPT_V]);
                    $state['status'] = 'pending';
                    if (isset($ins['ins'])) {
                        if (class_exists('RelationshipDynamics')) RelationshipDynamics::log("[RelDyn-TRAITS] trait read queued for {$npcName} ({$state['key']})");
                        self::launchWorker();
                    }
                } else {
                    $state['status'] = 'pending';
                }
            }
        } catch (\Throwable $e) {
            error_log('[RelDyn-TRAITS] ERROR trait read lookup for ' . $npcName . ': ' . $e->getMessage());
            $state['status'] = 'error';
        }
        if ($token !== null) self::$memo[$mk] = $state;
        return $state;
    }

    /** Ask for a worker (the eval worker drains trait reads after its own jobs). */
    public static function launchWorker(): bool
    {
        if (self::$launcher !== null) { (self::$launcher)(); return true; }
        if (empty(self::config()['autostart_worker'])) return false;
        if (getenv('PHPUNIT_TEST')) return false;
        if (!class_exists('RelDynEval')) {
            $f = __DIR__ . '/eval_producer.php';
            if (!is_file($f)) return false;
            require_once $f;
        }
        return RelDynEval::launchWorker();
    }

    // =========================================================================
    // DRAIN (eval worker, after its own jobs)
    // =========================================================================

    public static function connectorId(array $cfg): int
    {
        $own = intval($cfg['connector_id'] ?? 0);
        if ($own > 0) return $own;
        if (class_exists('RelDynEval')) {
            $e = RelDynEval::connectorId(RelDynEval::config());
            if ($e > 0) return $e;
        }
        return intval($GLOBALS['RELLLM_CONNECTOR'] ?? 0);
    }

    /** Default LLM: the trait connector through core's LLMConnector (as RelDynEval::defaultLlm). */
    public static function defaultLlm(): callable
    {
        return static function (array $messages, array $params) {
            $id = self::connectorId(self::config());
            if ($id <= 0) throw new RuntimeException('no trait-read connector (trait_reader.connector_id / eval connector / RELLLM_CONNECTOR)');
            require_once dirname(__DIR__, 2) . '/lib/core/llm_connector.class.php';
            $lc = new LLMConnector();
            $conn = $lc->readOne($id);
            if (!is_array($conn) || empty($conn['driver'])) throw new RuntimeException("trait-read connector {$id} not found");
            $driver = $lc->getConnector($conn);
            $saved = $GLOBALS['CONNECTOR'] ?? null;
            try {
                $lc->setOldGlobals($conn);
                $out = $driver->fast_request($messages, $params, 'reldyn_traits');
                return ['text' => $out, 'model' => (string) ($conn['model'] ?? '')];
            } finally {
                if ($saved !== null) $GLOBALS['CONNECTOR'] = $saved;
            }
        };
    }

    /** Call params for one read. */
    public static function callParams(array $cfg): array
    {
        return ['MAX_TOKENS' => intval($cfg['max_tokens']), 'temperature' => floatval($cfg['temperature'])];
    }

    /**
     * One read of a template: messages -> LLM -> parse. Returns ['ok' => bool, 'result' =>
     * ?array, 'error' => ?string, 'model' => ?string]. The LLM callable returns a string or
     * ['text' => string, 'model' => string]. $gender: the NPC's (screen rule S); null = looked up.
     */
    public static function readOnce(string $templateKey, array $fields, callable $llm, array $cfg, ?string $gender = null): array
    {
        if (self::isSkipped($templateKey, $cfg)) return ['ok' => false, 'result' => null, 'error' => 'skip-listed', 'model' => null];
        $out = $llm(self::buildMessages(self::displayName($templateKey), $fields), self::callParams($cfg));
        $model = is_array($out) ? ($out['model'] ?? null) : null;
        $text = is_array($out) ? ($out['text'] ?? null) : $out;
        if (!is_string($text) || trim($text) === '') return ['ok' => false, 'result' => null, 'error' => 'empty response', 'model' => $model];
        $reason = null;
        $res = self::parse($text, $fields, $reason, $gender ?? self::genderFor($templateKey));
        if ($res === null) return ['ok' => false, 'result' => null, 'error' => 'malformed: ' . $reason, 'model' => $model];
        return ['ok' => true, 'result' => $res, 'error' => null, 'model' => $model];
    }

    /**
     * Drain pending reads (eval worker, only after RelDynEval::runWorker() and only with no
     * pending eval job). Own advisory lock, jobs_per_run reads, attempts +1 per failure and
     * 'dead' at max_attempts. Switched off: returns at once and pending rows STAY. Never
     * touches reldyn_eval_queue. $pause (default: a pending Playthrough Save switch,
     * RelDynEval::playthroughSwitchPending) is checked before every read: the worker holds the
     * shared work lease, which the switch waits 30 s for, so it stops between reads ('paused').
     */
    public static function drain(?callable $llm = null, ?callable $pause = null): array
    {
        $stats = ['processed' => 0, 'done' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => false, 'off' => false, 'paused' => false];
        $pause = $pause ?? (class_exists('RelDynEval') ? [RelDynEval::class, 'playthroughSwitchPending']
            : fn() => function_exists('ptr_runtime_paused') && ptr_runtime_paused());
        $cfg = self::config();
        if (empty($cfg['enabled']) || (class_exists('RelationshipDynamics') && !RelationshipDynamics::isEnabled())) {
            $stats['off'] = true;
            return $stats;
        }
        self::ensureTable();
        self::ensureSeedLoaded();
        $db = self::db();
        $lockClass = class_exists('RelDynEval') ? RelDynEval::LOCK_CLASS : 1380218437;
        $lock = $db->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [$lockClass, self::LOCK_TRAITS]);
        if (!self::isTrue($lock['got'] ?? null)) { $stats['locked'] = true; return $stats; }
        try {
            $llm = $llm ?? self::$llm ?? self::defaultLlm();
            $tried = [];
            while ($stats['processed'] < max(1, intval($cfg['jobs_per_run']))) {
                if ($pause()) {
                    error_log('[RelDyn-TRAITS] trait reads stop: a Playthrough Save switch is pending; the rest wait for the next worker');
                    $stats['paused'] = true;
                    break;
                }
                $row = $db->fetchOne('SELECT template_key, src_hash, attempts FROM ' . self::TABLE . "
                     WHERE status = 'pending' AND NOT ((template_key || '|' || src_hash) = ANY(\$1::text[]))
                     ORDER BY created, template_key LIMIT 1", [self::pgTextArray($tried)]);
                if (!isset($row['template_key'])) break;
                $key = (string) $row['template_key'];
                $hash = (string) $row['src_hash'];
                $tried[] = "{$key}|{$hash}";
                $stats['processed']++;
                if (self::isSkipped($key, $cfg)) {
                    $db->fetchOne('DELETE FROM ' . self::TABLE . ' WHERE template_key = $1 AND src_hash = $2', [$key, $hash]);
                    $stats['skipped']++;
                    continue;
                }
                $outcome = self::processRow($key, $hash, intval($row['attempts'] ?? 0), $llm, $cfg);
                $stats[$outcome]++;
            }
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int) AS released', [$lockClass, self::LOCK_TRAITS]);
        }
        return $stats;
    }

    private static function pgTextArray(array $items): string
    {
        return '{' . implode(',', array_map(fn($s) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"', $items)) . '}';
    }

    /** @return string 'done' | 'failed' | 'dead' */
    private static function processRow(string $key, string $hash, int $attempts, callable $llm, array $cfg): string
    {
        $db = self::db();
        $error = null;
        $res = null;
        try {
            $tplRow = $db->fetchOne('SELECT npc_name, ' . implode(', ', self::FIELDS) . ' FROM combined_bio_templates WHERE lower(npc_name) = lower($1) LIMIT 1', [$key]);
            if (!isset($tplRow['npc_name'])) {
                $error = 'template gone';
            } else {
                $fields = self::fieldsOf($tplRow);
                if (self::srcHash($fields) !== $hash) {
                    $error = 'template changed since queued';   // the next lookup queues the new hash
                    $attempts = PHP_INT_MAX - 1;
                } else {
                    $res = self::readOnce($key, $fields, $llm, $cfg);
                    if (!$res['ok']) $error = $res['error'];
                }
            }
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            error_log("[RelDyn-TRAITS] trait read for {$key} threw: " . substr($error, 0, 200));
        }
        if ($error === null) {
            $db->fetchOne('UPDATE ' . self::TABLE . " SET status = 'done', attempts = attempts + 1, result = \$3::jsonb, model = \$4, last_error = NULL, updated = now()
                           WHERE template_key = \$1 AND src_hash = \$2",
                [$key, $hash, json_encode($res['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) ($res['model'] ?? '')]);
            if (class_exists('RelationshipDynamics')) RelationshipDynamics::log("[RelDyn-TRAITS] trait read done for {$key}");
            return 'done';
        }
        $dead = $attempts + 1 >= max(1, intval($cfg['max_attempts']));
        $db->fetchOne('UPDATE ' . self::TABLE . ' SET status = $3, attempts = attempts + 1, last_error = $4, updated = now() WHERE template_key = $1 AND src_hash = $2',
            [$key, $hash, $dead ? 'dead' : 'pending', substr((string) $error, 0, 300)]);
        error_log("[RelDyn-TRAITS] trait read for {$key} failed" . ($dead ? ' (dead)' : '') . ': ' . substr((string) $error, 0, 200));
        return $dead ? 'dead' : 'failed';
    }

    /** Pending rows (worker exit check / tests). */
    public static function pendingCount(): int
    {
        $row = self::db()->fetchOne('SELECT count(*) AS n FROM ' . self::TABLE . " WHERE status = 'pending'");
        return intval($row['n'] ?? 0);
    }
}
