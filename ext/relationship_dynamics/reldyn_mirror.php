<?php
/**
 * Relationship Dynamics — the player mirror: an auto-generated behavioural profile of the player
 * (roadmap player-profile-mirror; D:\docs\reldyn-player-profile-design.md, roadmap PR 14, memory
 * feedback_player_feedback_loop, project_player_profile_spider).
 *
 * "The mirror you didn't ask for": the player never fills anything in. Every applied eval item
 * (RelationshipDynamics::processEvalContractItem) is one observation of what the player did, as
 * the NPCs collectively experienced it: the eval's raw signals (its grade of the exchange, before
 * any NPC's own filter), tags, grievance, romantic intent, charisma grade (rulings §18 #11) and
 * significance, plus the NPC's state when the player acted (open conflict, low comfort, pulled
 * away, the bond's core affinity). Observations live in core_player row reldyn_player_mirror
 * (a playthrough table, like RelDyn's gold ledger: a new character starts a new mirror), the
 * newest `window` kept, appended in one statement (concurrent workers never lose one), once per
 * item (its fingerprint), rewound on a save load (RelDynTimeline: observations past the loaded
 * game time go).
 *
 * compute() derives, all from the stored window (pure):
 *   six dimensions 0..100, 50 neutral (the design's Maturity, Trust Rating, Warmth, Respect,
 *     Comfort Effect, Self-Confidence): per observation an evidence value e in -1..1 (a signal /
 *     signal_full, plus the design's detection signals: pressure while she pulls away costs comfort,
 *     a grievance costs maturity, engaging in her conflict constructively earns it, validation
 *     seeking and the charisma grade read confidence), weighted by significance;
 *     score = 50 + 50 x clamp(weighted mean, -1, 1) x n / (n + confidence_half). Band keywords
 *     (the design's five bands) per dimension.
 *   validation locus (design): internal / external / concentrated external (the top
 *     concentrated_npcs NPCs hold concentrated_share of the validation seeking).
 *   charisma archetype (design / MDD 5.1): over the last charisma.window observations, the
 *     eval's grade counts: primary and secondary (min_graded to name one).
 *   attachment pattern (design): votes over attachment.window observations (anxious: back at her
 *     within a short gap after a bad exchange, pushing while she pulls away, a gift into a fight;
 *     avoidant: a long gap from a bond, surface talk with a bond; secure: constructive repair,
 *     steady contact, leaving her room; disorganized: hot and cold with the same NPC). Updated
 *     every attachment.update_every observations (the window ends at the last multiple);
 *     named at min_confidence (60%) share, else "-leaning".
 *   love language (design): the tags' love languages (the one tag table,
 *     affinity_tag_love_language) over love_language.window observations.
 *   trajectory: a snapshot of the six scores at most every history.every_game_days, trend over
 *     history.trend_game_days.
 * Mirror vs Character mode (feedback_player_feedback_loop, both valid): 'mirror' (the design:
 * "never self-reported", default) reads the observations alone; 'character' blends a
 * player-authored seed (character_seed: dimension => 0..100, charisma / attachment / love
 * language) at character_weight, so the profile holds the role while play moves it slowly.
 *
 * Where it reaches NPCs (numbers never reach the NPC LLM; the page gets numbers):
 *   - reputation (design "Reputation modifier: trust +5 to +15 for new NPCs"): a trust rating
 *     outside the neutral band shifts a stranger's first impression (RelDynReputation::apply) by
 *     reputation.min..max trust points, linear over the band (within the approved
 *     REPUTATION_CAPS), once min_observations are in. On.
 *   - prompt (design "band keywords injected globally"; Rangroo: an option to keep it out of the
 *     prompt): the most telling bands as one felt line of how the player comes across. Off by
 *     default (opt-in; open question).
 *   - read API for the P5 spider graph: spider() (api_player_mirror.php). No UI here.
 *
 * Units: signals are raw eval points (affinity core points; -30..30, maturity -10..10);
 * significance 0..1; gamets raw game calendar (GAMETS_PER_DAY a day); scores 0..100.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynMirror
{
    /** core_player row (a playthrough table). */
    const ROW_ID = 'reldyn_player_mirror';
    /** The six scored dimensions, in display order. */
    const DIMENSIONS = ['maturity', 'trust', 'warmth', 'respect', 'comfort', 'confidence'];
    /** Signal order of an observation's 's' list (the contract's signals). */
    const SIGNALS = ['affinity', 'trust', 'comfort', 'respect', 'passion', 'maturity'];
    const PATTERNS = ['secure', 'anxious', 'avoidant', 'disorganized'];

    private static $cache = null;

    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // 'mirror' (the design: observed behaviour only) or 'character' (a player-authored seed
            // held at character_weight; feedback_player_feedback_loop)
            'mode' => 'mirror',
            'character_seed' => [],          // dimension => 0..100; 'charisma', 'attachment', 'love_language' => name
            'character_weight' => 0.8,       // 0..1 share of the seed in Character mode
            'window' => 200,                 // observations kept (the longest window below)
            'signal_full' => ['affinity' => 6.0, 'trust' => 6.0, 'comfort' => 6.0, 'respect' => 6.0, 'maturity' => 3.0],
            'confidence_half' => 20,         // observations with evidence at which a score shows half its lean
            'min_weight' => 0.1,             // weight of an observation of significance 0
            'negative_net' => 0.15,          // |mean normalized signal| from which an exchange was good / bad
            'comfort_low' => 35.0,           // her comfort (points) under which a push is pressure
            // evidence (e, -1..1) of the design's detection signals
            'evidence' => [
                'pressure_comfort' => -0.8,      // romantic move while she pulls away / is uneasy
                'grievance_maturity' => -0.6,    // x severity / 3
                'repair_maturity' => 0.6,        // apology / reassurance / forgiveness into her open conflict
                'escalate_maturity' => -0.6,     // insult / criticism into her open conflict
                'validation_confidence' => -0.7, // back at her soon after a bad exchange, or a gift into a fight
                'charisma_confidence' => ['rock' => 0.6, 'catalyst' => 0.3, 'charmer' => -0.3],
            ],
            'validation' => ['window' => 100, 'recheck_game_hours' => 12.0, 'min_observations' => 20,
                             'external_share' => 0.15, 'concentrated_share' => 0.8, 'concentrated_npcs' => 2],
            'charisma' => ['window' => 50, 'min_graded' => 5],
            'attachment' => [
                'window' => 100, 'update_every' => 25, 'min_observations' => 25, 'min_confidence' => 0.6,
                'spike_game_hours' => 3.0,       // back within this after a bad exchange: anxious
                'long_gap_game_days' => 7.0,     // this long away from a bond: avoidant
                'bond_min' => 40.0,              // core affinity from which a bond counts (-100..100)
                'surface_max_significance' => 0.15,
                'hot_cold_game_hours' => 24.0,   // a swing between exchanges this close: disorganized
                'hot_cold_net' => 0.4,           // |net| of both ends of a swing
                // vote weights
                'votes' => ['spike' => 1.0, 'push' => 1.0, 'gift_bomb' => 1.0, 'long_gap' => 1.0, 'surface' => 0.25,
                            'repair' => 1.0, 'steady' => 0.5, 'room' => 0.5, 'hot_cold' => 1.0],
            ],
            'love_language' => ['window' => 200, 'min_count' => 3],
            'history' => ['every_game_days' => 1.0, 'keep' => 90, 'trend_game_days' => 28.0, 'trend_min_change' => 5.0],
            // The design's band keywords (0-20, 21-40, 41-60, 61-80, 81-100)
            'bands' => [
                'maturity' => ['unpredictable, exhausting to be around, creates chaos', "means well but clumsy, doesn't read the room",
                    'reasonable most of the time, occasionally tone-deaf', 'steady presence, makes people feel heard, good in crisis',
                    "everyone is calmer when they're around, natural leader"],
                'trust' => ['known liar, never turn your back, count your coins after', "unreliable, makes promises they don't keep",
                    "seems decent enough, jury's still out", 'dependable, follows through, good to their word',
                    'unshakable integrity, would trust with my life on reputation alone'],
                'warmth' => ['cold, transactional, treats people like quest objectives', 'businesslike, efficient, not unkind but not warm',
                    'pleasant, makes small talk, generally agreeable', 'genuinely warm, remembers details, people seek them out',
                    'magnetic warmth, strangers open up to them, makes everyone feel seen'],
                'respect' => ['talks over everyone, dismissive, treats people as tools', 'occasionally listens but usually does their own thing',
                    'respectful enough, acknowledges others when it matters', 'values input, asks for counsel, credits others',
                    'elevates everyone around them, makes people feel important'],
                'comfort' => ["suffocating, pushes constantly, doesn't hear no", 'tries too hard, well-meaning but overwhelming',
                    'generally appropriate, occasionally missteps', 'easy to be around, gives space when needed, reads the room',
                    'people relax visibly in their presence, a natural safe space'],
                'confidence' => ["hesitant, seeks permission, can't commit to a choice, needs constant reassurance",
                    'capable but second-guesses, looks for approval after acting, deflects compliments',
                    'functional, occasionally checks in but mostly self-directed',
                    "decisive, trusts own judgment, takes feedback as data not verdict, doesn't need permission",
                    'completely self-directed, unmoved by opinion, acts from internal authority'],
            ],
            'labels' => ['maturity' => 'Maturity', 'trust' => 'Trust Rating', 'warmth' => 'Warmth', 'respect' => 'Respect Rating',
                         'comfort' => 'Comfort Effect', 'confidence' => 'Self-Confidence'],
            // Reputation (design): trust points for new NPCs, a stranger's first impression
            'reputation' => ['enabled' => true, 'min_observations' => 20, 'neutral_low' => 41.0, 'neutral_high' => 60.0,
                             'min' => 5.0, 'max' => 15.0],
            // The felt line in NPC prompts (design: band keywords; Rangroo: opt-in). Off by default.
            'prompt' => [
                'enabled' => false,
                'max_bands' => 2,                // the most telling bands (farthest from 50)
                'min_observations' => 20,        // observations with evidence a band needs to speak
                'salience' => 0.4,
                // context tier from which a dimension speaks (manner is seen; a trust record is known)
                'min_tier' => ['maturity' => 0, 'warmth' => 0, 'comfort' => 0, 'confidence' => 0, 'respect' => 0, 'trust' => 1],
                'text' => 'What {NAME} senses of {PLAYER} with people: {ITEMS}.',
            ],
        ];
    }

    public static function config(): array
    {
        $d = self::configDefaults();
        $stored = RelationshipDynamics::configValue('player_mirror');
        if (!is_array($stored)) return $d;
        $cfg = array_replace($d, $stored);
        foreach (['signal_full', 'evidence', 'validation', 'charisma', 'attachment', 'love_language', 'history',
                     'bands', 'labels', 'reputation', 'prompt'] as $k) {
            $cfg[$k] = array_replace($d[$k], is_array($stored[$k] ?? null) ? $stored[$k] : []);
        }
        $cfg['attachment']['votes'] = array_replace($d['attachment']['votes'], (array) ($stored['attachment']['votes'] ?? []));
        $cfg['prompt']['min_tier'] = array_replace($d['prompt']['min_tier'], (array) ($stored['prompt']['min_tier'] ?? []));
        return $cfg;
    }

    public static function enabled(): bool
    {
        return RelationshipDynamics::isEnabled() && !empty(self::config()['enabled']);
    }

    // =====================================================================
    // OBSERVING
    // =====================================================================

    /** Her state when the player acted (before the item's signals): the observation's context. */
    public static function context(array $dynamics): array
    {
        $cfg = self::config();
        $comfort = $dynamics['dimensions']['comfort']['x'] ?? null;
        return [
            'cf' => !empty($dynamics['in_conflict']),
            'cm' => is_numeric($comfort) && floatval($comfort) < floatval($cfg['comfort_low']),
            'pa' => ($dynamics['_walkaway_state'] ?? 'normal') !== 'normal',
            'b' => is_numeric($dynamics['_aff_mirror_x'] ?? null) ? (int) round(RelationshipDynamics::getCoreAffinity($dynamics)) : null,
        ];
    }

    /** One observation from a normalized eval item ($n), her state $ctx (context()) and its game time. */
    public static function observation(string $npc, array $n, array $ctx, float $gamets, string $fingerprint): array
    {
        $s = [];
        foreach (self::SIGNALS as $sig) $s[] = round(floatval($n['signals'][$sig] ?? 0), 2);
        $o = ['fp' => $fingerprint, 'g' => (int) round($gamets), 'n' => trim($npc), 's' => $s,
              't' => array_values(array_map('strval', (array) ($n['tags'] ?? []))),
              'sig' => round(floatval($n['significance'] ?? 0), 3), 'pi' => !empty($n['positive_interaction']) ? 1 : 0,
              'gv' => !empty($n['grievance']['flag']) ? max(1, intval($n['grievance']['severity'] ?? 1)) : 0,
              'ri' => intval($n['romantic_intent'] ?? 0), 'c' => $ctx];
        if (isset($n['charisma'])) $o['ch'] = (string) $n['charisma'];
        return $o;
    }

    /**
     * Record one applied eval item (processEvalContractItem). One statement: appended, the window
     * kept, skipped when this fingerprint is already in (a re-applied item). Then a trajectory
     * snapshot when one is due (compare-and-set; a writer in between leaves it to the next item).
     * Returns true when the observation was recorded.
     */
    public static function observe(string $npc, array $n, array $ctx, float $gamets, string $fingerprint): bool
    {
        if (!self::enabled()) return false;
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $gamets <= 0) return false;
        $cfg = self::config();
        $obs = self::observation($npc, $n, $ctx, $gamets, $fingerprint);
        $window = max(1, intval($cfg['window']));
        $init = json_encode(['v' => 1, 'total' => 1, 'obs' => [$obs + ['q' => 1]], 'history' => []], JSON_UNESCAPED_UNICODE);
        $old = 'core_player.value::jsonb';
        $total = "COALESCE(({$old}->>'total')::int, 0) + 1";
        $row = $db->fetchOne(
            "INSERT INTO core_player (id, value) VALUES (\$1, \$2)
             ON CONFLICT (id) DO UPDATE SET value = ({$old} || jsonb_build_object(
                 'v', 1,
                 'total', {$total},
                 'obs', (SELECT COALESCE(jsonb_agg(k.e ORDER BY k.i), '[]'::jsonb) FROM (
                     SELECT a.e, a.i FROM jsonb_array_elements(
                         (CASE WHEN jsonb_typeof({$old}->'obs') = 'array' THEN {$old}->'obs' ELSE '[]'::jsonb END)
                         || jsonb_build_array(\$3::jsonb || jsonb_build_object('q', {$total}))) WITH ORDINALITY AS a(e, i)
                     ORDER BY a.i DESC LIMIT \$4::int) k)))::text
             WHERE NOT (COALESCE({$old}->'obs', '[]'::jsonb) @> jsonb_build_array(jsonb_build_object('fp', \$5::text)))
             RETURNING value",
            [self::ROW_ID, $init, json_encode($obs, JSON_UNESCAPED_UNICODE), $window, $fingerprint]
        );
        if (!isset($row['value'])) {
            RelationshipDynamics::log("[RelDyn-MIRROR] {$npc}: observation {$fingerprint} already recorded (or not written)");
            return false;
        }
        self::$cache = null;
        try {
            self::snapshotIfDue((string) $row['value'], $gamets, $cfg);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('player mirror trajectory snapshot', $e);
        }
        return true;
    }

    /** The trajectory: a snapshot of the six scores when the last one is every_game_days old. */
    private static function snapshotIfDue(string $raw, float $now, array $cfg): void
    {
        $state = json_decode($raw, true);
        if (!is_array($state)) return;
        $hist = array_values(array_filter((array) ($state['history'] ?? []), fn($h) => is_array($h) && is_numeric($h['g'] ?? null)));
        $every = floatval($cfg['history']['every_game_days']) * RelationshipDynamics::GAMETS_PER_DAY;
        $last = $hist === [] ? null : floatval(end($hist)['g']);
        if ($last !== null && $now >= $last && $now - $last < $every) return;
        $p = self::compute($state, $cfg, $now);
        $scores = [];
        foreach (self::DIMENSIONS as $d) $scores[$d] = $p['dimensions'][$d]['score'];
        $hist = array_values(array_filter($hist, fn($h) => floatval($h['g']) < $now));   // a later one is a lost timeline
        $hist[] = ['g' => (int) round($now), 'scores' => $scores];
        $state['history'] = array_slice($hist, -max(1, intval($cfg['history']['keep'])));
        $db = $GLOBALS['db'];
        $db->fetchOne('UPDATE core_player SET value = $2 WHERE id = $1 AND value = $3 RETURNING id',
            [self::ROW_ID, json_encode($state, JSON_UNESCAPED_UNICODE), $raw]);
    }

    /** The stored state (['obs' => [], 'total' => 0, 'history' => []] when none). */
    public static function state(): array
    {
        $db = $GLOBALS['db'] ?? null;
        $empty = ['v' => 1, 'total' => 0, 'obs' => [], 'history' => []];
        if (!$db) return $empty;
        $row = $db->fetchOne('SELECT value FROM core_player WHERE id = $1', [self::ROW_ID]);
        $s = is_string($row['value'] ?? null) ? json_decode($row['value'], true) : null;
        return is_array($s) ? $s + $empty : $empty;
    }

    /**
     * Save load (RelDynTimeline::reconcile): the mirror follows the game back to $loadGamets (raw):
     * observations and snapshots later than it go. Compare-and-set, retried.
     *
     * @return string 'none' | 'current' | 'rewound' | 'conflict'
     */
    public static function rewind(float $loadGamets): string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return 'none';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row = $db->fetchOne('SELECT value FROM core_player WHERE id = $1', [self::ROW_ID]);
            $raw = $row['value'] ?? null;
            $s = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($s)) return 'none';
            $obs = (array) ($s['obs'] ?? []);
            $keep = array_values(array_filter($obs, fn($o) => is_array($o) && floatval($o['g'] ?? 0) <= $loadGamets));
            $hist = array_values(array_filter((array) ($s['history'] ?? []), fn($h) => is_array($h) && floatval($h['g'] ?? 0) <= $loadGamets));
            if (count($keep) === count($obs) && count($hist) === count((array) ($s['history'] ?? []))) return 'current';
            $s['total'] = max(0, intval($s['total'] ?? 0) - (count($obs) - count($keep)));
            $s['obs'] = $keep;
            $s['history'] = $hist;
            $won = $db->fetchOne('UPDATE core_player SET value = $2 WHERE id = $1 AND value = $3 RETURNING id',
                [self::ROW_ID, json_encode($s, JSON_UNESCAPED_UNICODE), $raw]);
            if (isset($won['id'])) {
                self::$cache = null;
                return 'rewound';
            }
        }
        error_log('[RelDyn-MIRROR] ERROR rewinding the player mirror to gamets ' . $loadGamets . ': another writer kept changing it');
        return 'conflict';
    }

    // =====================================================================
    // THE PROFILE
    // =====================================================================

    /** The profile (compute() of the stored state), cached for the request scope. */
    public static function profile(): array
    {
        $token = RelationshipDynamics::requestScopeToken();
        if ($token !== null && is_array(self::$cache) && self::$cache[0] === $token) return self::$cache[1];
        $p = self::compute(self::state(), null, RelationshipDynamics::currentGamets());
        self::$cache = $token !== null ? [$token, $p] : null;
        return $p;
    }

    /** Normalized signal of observation $o (index in SIGNALS), -1..1 by signal_full. */
    private static function norm(array $o, string $signal, array $cfg): float
    {
        $i = array_search($signal, self::SIGNALS, true);
        $v = floatval(((array) ($o['s'] ?? []))[$i] ?? 0);
        $full = max(1e-6, floatval($cfg['signal_full'][$signal] ?? 6.0));
        return max(-1.0, min(1.0, $v / $full));
    }

    /** Net of an exchange: the mean of its normalized affinity / trust / comfort / respect. */
    private static function net(array $o, array $cfg): float
    {
        $sum = 0.0;
        foreach (['affinity', 'trust', 'comfort', 'respect'] as $s) $sum += self::norm($o, $s, $cfg);
        return $sum / 4;
    }

    private static function bad(array $o, array $cfg): bool
    {
        return intval($o['gv'] ?? 0) > 0 || self::net($o, $cfg) <= -floatval($cfg['negative_net']);
    }

    private static function band(float $score): int
    {
        return $score <= 20.5 ? 0 : ($score <= 40.5 ? 1 : ($score <= 60.5 ? 2 : ($score <= 80.5 ? 3 : 4)));
    }

    /**
     * The profile from a stored state (pure but for config). $now: raw gamets (trend reference).
     * Returns ['known', 'mode', 'observations', 'total', 'npcs', 'dimensions' => d => [score,
     * observed, band, keywords, n, confidence, trend, was], 'validation_locus', 'charisma',
     * 'attachment', 'love_language', 'reputation_trust'].
     */
    public static function compute(array $state, ?array $cfg = null, ?float $now = null): array
    {
        $cfg = $cfg ?? self::config();
        $obs = array_values(array_filter((array) ($state['obs'] ?? []), fn($o) => is_array($o) && is_numeric($o['g'] ?? null)));
        usort($obs, fn($a, $b) => [intval($a['q'] ?? 0), $a['g']] <=> [intval($b['q'] ?? 0), $b['g']]);
        $total = max(count($obs), intval($state['total'] ?? 0));
        $hour = RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        $ev = (array) $cfg['evidence'];

        // The previous observation with the same NPC (in the window), the gap to it (game hours)
        $prev = [];
        $lastBy = [];
        foreach ($obs as $i => $o) {
            $k = strtolower((string) ($o['n'] ?? ''));
            $prev[$i] = $lastBy[$k] ?? null;
            $lastBy[$k] = $i;
        }
        $gapH = function (int $i) use ($obs, $prev, $hour): ?float {
            $p = $prev[$i];
            return $p === null ? null : max(0.0, (floatval($obs[$i]['g']) - floatval($obs[$p]['g'])) / $hour);
        };
        // Validation seeking: back at her within recheck hours after a bad exchange, or a gift into her open conflict
        $recheck = floatval($cfg['validation']['recheck_game_hours']);
        $seeking = function (int $i) use ($obs, $prev, $gapH, $cfg, $recheck): bool {
            $o = $obs[$i];
            if (!empty($o['c']['cf']) && in_array('gift', (array) ($o['t'] ?? []), true)) return true;
            $p = $prev[$i];
            return $p !== null && self::bad($obs[$p], $cfg) && ($g = $gapH($i)) !== null && $g <= $recheck;
        };

        // ---- the six dimensions
        $acc = array_fill_keys(self::DIMENSIONS, ['sum' => 0.0, 'w' => 0.0, 'n' => 0]);
        $put = function (string $d, float $e, float $w) use (&$acc): void {
            $acc[$d]['sum'] += max(-1.0, min(1.0, $e)) * $w;
            $acc[$d]['w'] += $w;
            $acc[$d]['n']++;
        };
        foreach ($obs as $i => $o) {
            $w = max(floatval($cfg['min_weight']), floatval($o['sig'] ?? 0));
            $tags = (array) ($o['t'] ?? []);
            $c = (array) ($o['c'] ?? []);
            $sig = (array) ($o['s'] ?? []);
            $has = fn(string $s) => abs(floatval($sig[array_search($s, self::SIGNALS, true)] ?? 0)) > 1e-9;
            if ($has('trust')) $put('trust', self::norm($o, 'trust', $cfg), $w);
            if ($has('affinity')) $put('warmth', self::norm($o, 'affinity', $cfg), $w);
            if ($has('respect')) $put('respect', self::norm($o, 'respect', $cfg), $w);
            // Comfort effect: her comfort, and pressure where she pulls away or is uneasy
            $pressure = intval($o['ri'] ?? 0) > 0 && (!empty($c['pa']) || !empty($c['cm']));
            if ($has('comfort') || $pressure) {
                $put('comfort', ($has('comfort') ? self::norm($o, 'comfort', $cfg) : 0.0) + ($pressure ? floatval($ev['pressure_comfort']) : 0.0), $w);
            }
            // Maturity: NPCs grow or regress around the player; grievances; engaging her conflict
            $m = null;
            if ($has('maturity')) $m = self::norm($o, 'maturity', $cfg);
            if (intval($o['gv'] ?? 0) > 0) $m = ($m ?? 0.0) + floatval($ev['grievance_maturity']) * min(3, intval($o['gv'])) / 3;
            if (!empty($c['cf'])) {
                if (array_intersect($tags, ['apology', 'reassurance', 'forgiveness', 'confiding']) !== [] && !empty($o['pi'])) {
                    $m = ($m ?? 0.0) + floatval($ev['repair_maturity']);
                } elseif (array_intersect($tags, ['insult', 'criticism']) !== []) {
                    $m = ($m ?? 0.0) + floatval($ev['escalate_maturity']);
                }
            }
            if ($m !== null) $put('maturity', $m, $w);
            // Self-confidence: validation seeking, the approach the eval graded
            $conf = null;
            if ($seeking($i)) $conf = floatval($ev['validation_confidence']);
            $ch = (string) ($o['ch'] ?? '');
            if (isset($ev['charisma_confidence'][$ch])) $conf = ($conf ?? 0.0) + floatval($ev['charisma_confidence'][$ch]);
            if ($conf !== null) $put('confidence', $conf, $w);
        }
        $half = max(1e-6, floatval($cfg['confidence_half']));
        $dims = [];
        foreach (self::DIMENSIONS as $d) {
            $a = $acc[$d];
            $mean = $a['w'] > 0 ? $a['sum'] / $a['w'] : 0.0;
            $confidence = $a['n'] / ($a['n'] + $half);
            $score = 50.0 + 50.0 * max(-1.0, min(1.0, $mean)) * $confidence;
            $dims[$d] = ['observed' => round($score, 2), 'n' => $a['n'], 'confidence' => round($confidence, 3)];
        }

        // ---- Character mode: the authored seed held at character_weight
        $mode = (string) $cfg['mode'] === 'character' ? 'character' : 'mirror';
        $seed = is_array($cfg['character_seed']) ? $cfg['character_seed'] : [];
        $wSeed = $mode === 'character' ? max(0.0, min(1.0, floatval($cfg['character_weight']))) : 0.0;
        foreach (self::DIMENSIONS as $d) {
            $score = $dims[$d]['observed'];
            if ($wSeed > 0 && is_numeric($seed[$d] ?? null)) {
                $score = $wSeed * max(0.0, min(100.0, floatval($seed[$d]))) + (1 - $wSeed) * $score;
            }
            $b = self::band($score);
            $dims[$d] += ['score' => round($score, 2), 'band' => $b + 1, 'label' => (string) ($cfg['labels'][$d] ?? $d),
                'keywords' => (string) (((array) ($cfg['bands'][$d] ?? []))[$b] ?? '')];
        }

        // ---- trajectory
        $hist = array_values(array_filter((array) ($state['history'] ?? []), fn($h) => is_array($h) && is_numeric($h['g'] ?? null)));
        $ref = null;
        if ($now !== null && $now > 0) {
            $at = $now - floatval($cfg['history']['trend_game_days']) * RelationshipDynamics::GAMETS_PER_DAY;
            foreach ($hist as $h) if (floatval($h['g']) <= $at) $ref = $h;
            if ($ref === null && $hist !== []) $ref = $hist[0];   // the oldest there is
        }
        foreach (self::DIMENSIONS as $d) {
            $was = $ref !== null && is_numeric($ref['scores'][$d] ?? null) ? floatval($ref['scores'][$d]) : null;
            $delta = $was === null ? null : $dims[$d]['observed'] - $was;
            $dims[$d]['was'] = $was;
            $dims[$d]['trend'] = $delta === null ? null
                : ($delta >= floatval($cfg['history']['trend_min_change']) ? 'up' : ($delta <= -floatval($cfg['history']['trend_min_change']) ? 'down' : 'stable'));
        }

        // ---- validation locus
        $vc = (array) $cfg['validation'];
        $vObs = array_slice(array_keys($obs), -max(1, intval($vc['window'])));
        $seekBy = [];
        $seekN = 0;
        foreach ($vObs as $i) {
            if (!$seeking($i)) continue;
            $seekN++;
            $k = (string) $obs[$i]['n'];
            $seekBy[$k] = ($seekBy[$k] ?? 0) + 1;
        }
        $locus = null;
        if (count($vObs) >= intval($vc['min_observations'])) {
            $share = $seekN / count($vObs);
            arsort($seekBy);
            $top = array_slice($seekBy, 0, max(1, intval($vc['concentrated_npcs'])), true);
            $kind = $share < floatval($vc['external_share']) ? 'internal'
                : ($seekN > 0 && array_sum($top) / $seekN >= floatval($vc['concentrated_share']) ? 'concentrated_external' : 'external');
            $locus = ['locus' => $kind, 'seeking_share' => round($share, 3),
                'validators' => $kind === 'concentrated_external' ? array_keys($top) : []];
        }

        // ---- charisma archetype (MDD 5.1): the eval's grades over the window
        $cc = (array) $cfg['charisma'];
        $counts = ['rock' => 0, 'catalyst' => 0, 'charmer' => 0];
        foreach (array_slice($obs, -max(1, intval($cc['window']))) as $o) {
            $g = (string) ($o['ch'] ?? '');
            if (isset($counts[$g])) $counts[$g]++;
        }
        arsort($counts);
        $graded = array_sum($counts);
        $names = array_keys($counts);
        $charisma = ['primary' => null, 'secondary' => null, 'counts' => $counts];
        if ($graded >= intval($cc['min_graded'])) {
            $charisma['primary'] = $names[0];
            $charisma['secondary'] = $counts[$names[1]] > 0 ? $names[1] : null;
        }
        if ($mode === 'character' && is_string($seed['charisma'] ?? null) && isset($counts[$seed['charisma']])) {
            $charisma['primary'] = $seed['charisma'];
        }

        // ---- attachment pattern (every update_every observations, over the window up to then)
        $atc = (array) $cfg['attachment'];
        $every = max(1, intval($atc['update_every']));
        $atCount = intdiv($total, $every) * $every;
        $attachment = null;
        if ($atCount >= intval($atc['min_observations'])) {
            $firstQ = intval($obs[0]['q'] ?? 1);
            $idx = array_values(array_filter(array_keys($obs), fn($i) => intval($obs[$i]['q'] ?? ($firstQ + $i)) <= $atCount));
            $idx = array_slice($idx, -max(1, intval($atc['window'])));
            $votes = array_fill_keys(self::PATTERNS, 0.0);
            $vw = (array) $atc['votes'];
            foreach ($idx as $i) {
                $o = $obs[$i];
                $c = (array) ($o['c'] ?? []);
                $tags = (array) ($o['t'] ?? []);
                $g = $gapH($i);
                $p = $prev[$i];
                $bond = is_numeric($c['b'] ?? null) && floatval($c['b']) >= floatval($atc['bond_min']);
                if ($p !== null && self::bad($obs[$p], $cfg) && $g !== null && $g <= floatval($atc['spike_game_hours'])) $votes['anxious'] += floatval($vw['spike']);
                if (intval($o['ri'] ?? 0) > 0 && (!empty($c['pa']) || !empty($c['cm']))) $votes['anxious'] += floatval($vw['push']);
                if (in_array('gift', $tags, true) && (!empty($c['cf']) || ($p !== null && self::bad($obs[$p], $cfg)))) $votes['anxious'] += floatval($vw['gift_bomb']);
                if ($bond && $g !== null && $g >= floatval($atc['long_gap_game_days']) * 24.0) $votes['avoidant'] += floatval($vw['long_gap']);
                if ($bond && floatval($o['sig'] ?? 0) <= floatval($atc['surface_max_significance'])) $votes['avoidant'] += floatval($vw['surface']);
                if (!empty($c['cf']) && !empty($o['pi']) && array_intersect($tags, ['apology', 'reassurance', 'confiding', 'forgiveness']) !== []) {
                    $votes['secure'] += floatval($vw['repair']);
                }
                if ($g !== null && $g > floatval($atc['spike_game_hours']) && $g < floatval($atc['long_gap_game_days']) * 24.0 && !empty($o['pi'])) {
                    $votes['secure'] += floatval($vw['steady']);
                }
                if (!empty($c['pa']) && intval($o['ri'] ?? 0) === 0 && !in_array('command', $tags, true)) $votes['secure'] += floatval($vw['room']);
                if ($p !== null && $g !== null && $g <= floatval($atc['hot_cold_game_hours'])) {
                    $a = self::net($obs[$p], $cfg);
                    $b = self::net($o, $cfg);
                    $hc = floatval($atc['hot_cold_net']);
                    if (($a >= $hc && $b <= -$hc) || ($a <= -$hc && $b >= $hc)) $votes['disorganized'] += floatval($vw['hot_cold']);
                }
            }
            $sum = array_sum($votes);
            $shares = array_map(fn($v) => $sum > 0 ? round($v / $sum, 3) : 0.0, $votes);
            arsort($shares);
            $order = array_keys($shares);
            $primary = $sum > 0 ? $order[0] : null;
            $confident = $primary !== null && $shares[$primary] >= floatval($atc['min_confidence']);
            $attachment = ['primary' => $primary, 'share' => $primary !== null ? $shares[$primary] : 0.0,
                'secondary' => $sum > 0 && $shares[$order[1]] > 0 ? $order[1] : null,
                'secondary_share' => $sum > 0 ? $shares[$order[1]] : 0.0,
                'confident' => $confident, 'label' => $primary === null ? null : ($confident ? $primary : "{$primary}-leaning"),
                'shares' => $shares, 'at_count' => $atCount, 'observations' => count($idx)];
        }
        if ($mode === 'character' && is_string($seed['attachment'] ?? null) && in_array($seed['attachment'], self::PATTERNS, true)) {
            $attachment = ['primary' => $seed['attachment'], 'label' => $seed['attachment'], 'confident' => true, 'seeded' => true]
                + ($attachment ?? []);
        }

        // ---- love language (what the player DOES): the tags' love languages
        $lc = (array) $cfg['love_language'];
        $tagLL = (array) RelationshipDynamics::configValue('affinity_tag_love_language');
        $ll = [];
        foreach (array_slice($obs, -max(1, intval($lc['window']))) as $o) {
            foreach (array_unique((array) ($o['t'] ?? [])) as $t) {
                if (isset($tagLL[$t])) $ll[(string) $tagLL[$t]] = ($ll[(string) $tagLL[$t]] ?? 0) + 1;
            }
        }
        arsort($ll);
        $llNames = array_keys(array_filter($ll, fn($c) => $c >= intval($lc['min_count'])));
        $love = ['primary' => $llNames[0] ?? null, 'secondary' => $llNames[1] ?? null, 'counts' => $ll];
        if ($mode === 'character' && is_string($seed['love_language'] ?? null) && $seed['love_language'] !== '') {
            $love['primary'] = $seed['love_language'];
        }

        $npcs = count(array_unique(array_map(fn($o) => strtolower((string) ($o['n'] ?? '')), $obs)));
        $out = ['known' => $obs !== [] || ($mode === 'character' && $seed !== []), 'mode' => $mode, 'observations' => count($obs),
            'total' => $total, 'npcs' => $npcs, 'dimensions' => $dims, 'validation_locus' => $locus, 'charisma' => $charisma,
            'attachment' => $attachment, 'love_language' => $love];
        $out['reputation_trust'] = self::reputationTrustOffset($out, $cfg);
        return $out;
    }

    // =====================================================================
    // WHERE IT REACHES NPCs
    // =====================================================================

    /**
     * Trust points a stranger's first impression takes from the player's trust rating (design:
     * "+5 to +15 for new NPCs", "-5 to -15"): 0 inside the neutral band, reputation.min at its
     * edge rising linearly to reputation.max at 100 (0 below); 0 until min_observations
     * observations carry trust evidence.
     */
    public static function reputationTrustOffset(?array $profile = null, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $rc = (array) $cfg['reputation'];
        if (empty($cfg['enabled']) || empty($rc['enabled'])) return 0.0;
        $profile = $profile ?? self::profile();
        $d = $profile['dimensions']['trust'] ?? null;
        if (!is_array($d) || intval($d['n'] ?? 0) < intval($rc['min_observations'])) {
            if (!($profile['mode'] === 'character' && is_numeric(((array) $cfg['character_seed'])['trust'] ?? null))) return 0.0;
        }
        $s = floatval($d['score'] ?? 50.0);
        $lo = floatval($rc['neutral_low']);
        $hi = floatval($rc['neutral_high']);
        $min = floatval($rc['min']);
        $max = floatval($rc['max']);
        if ($s > $hi) return round($min + ($max - $min) * min(1.0, ($s - $hi) / max(1e-6, 100.0 - $hi)), 3);
        if ($s < $lo) return round(-($min + ($max - $min) * min(1.0, ($lo - $s) / max(1e-6, $lo))), 3);
        return 0.0;
    }

    /**
     * The felt line of how the player comes across (prompt.enabled; off by default): the
     * prompt.max_bands bands farthest from neutral that have prompt.min_observations of evidence
     * and speak at context tier $tier. Words only. null when nothing speaks.
     */
    public static function feltText(string $npc, string $playerRef, int $tier, ?array $profile = null): ?string
    {
        $cfg = self::config();
        $pc = (array) $cfg['prompt'];
        if (empty($cfg['enabled']) || empty($pc['enabled'])) return null;
        $profile = $profile ?? self::profile();
        $cands = [];
        foreach (self::DIMENSIONS as $d) {
            $x = $profile['dimensions'][$d] ?? null;
            if (!is_array($x) || intval($x['band'] ?? 3) === 3) continue;   // the neutral band says nothing
            $seeded = $profile['mode'] === 'character' && is_numeric(((array) $cfg['character_seed'])[$d] ?? null);
            if (!$seeded && intval($x['n'] ?? 0) < intval($pc['min_observations'])) continue;
            if ($tier < intval($pc['min_tier'][$d] ?? 0)) continue;
            if (trim((string) $x['keywords']) === '') continue;
            $cands[$d] = [abs(floatval($x['score']) - 50.0), (string) $x['keywords']];
        }
        if ($cands === []) return null;
        uasort($cands, fn($a, $b) => $b[0] <=> $a[0]);
        $items = array_column(array_slice($cands, 0, max(1, intval($pc['max_bands']))), 1);
        return strtr((string) $pc['text'], ['{NAME}' => $npc, '{PLAYER}' => $playerRef, '{ITEMS}' => implode('; ', $items)]);
    }

    /**
     * The P5 spider graph (project_player_profile_spider; the design's profile page): axes 0..100
     * with band, keywords and trend, plus the categorical reads. For the page: numbers allowed
     * here, never for the NPC LLM. Writes nothing.
     */
    public static function spider(?array $profile = null): array
    {
        $p = $profile ?? self::profile();
        $axes = [];
        foreach (self::DIMENSIONS as $d) {
            $x = $p['dimensions'][$d];
            $axes[] = ['axis' => $d, 'label' => $x['label'], 'value' => $x['score'], 'observed' => $x['observed'], 'band' => $x['band'],
                'keywords' => $x['keywords'], 'evidence' => $x['n'], 'confidence' => $x['confidence'], 'trend' => $x['trend'], 'was' => $x['was']];
        }
        return ['known' => $p['known'], 'mode' => $p['mode'], 'observations' => $p['observations'], 'interactions' => $p['total'],
            'npcs' => $p['npcs'], 'axes' => $axes, 'charisma' => $p['charisma'], 'attachment' => $p['attachment'],
            'love_language' => $p['love_language'], 'validation_locus' => $p['validation_locus'],
            'reputation' => ['trust_offset_new_npcs' => $p['reputation_trust']]];
    }
}
