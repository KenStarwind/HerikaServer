<?php
/**
 * Relationship Dynamics — cascade extensions: friend-of-a-friend, love triangles, and the name lookup
 * (roadmap cascade-extensions; pipeline Addendum 10, MDD §10; Ken 2026-10-01 §24: "cascade extensions
 * (friend-of-a-friend, triangles, name lookup)").
 *
 * Built on the cascade network (reldyn_cascade.php): the same lazy rule. Nothing is computed for an NPC
 * who is not on the player's side of a request, nothing is written into another NPC's row from a request
 * that is not theirs, and nobody knows what they could not have heard. It all runs at the NPC's OWN
 * prerequest, from their own bonds (core's extended_data.relationships, the JSONB the relationship model
 * already keeps: no new tables).
 *
 * FRIEND OF A FRIEND (association). A likes B and B is close to the player: A warms to the player. A dislikes
 * B and B is close to the player: A cools. The same reading goes on one step: B at odds with the player makes
 * a friend of B cooler, and an enemy of B warmer (the enemy of my enemy), at association.foe_weight. Per link:
 *     lean = gain x (bond/100) x (closeness/100) x (foe_weight if B is at odds with the player)
 *            x (enemy_mult if A dislikes B) x knowledge
 *   bond        A's core affinity toward B, closeness B's core affinity toward the player; both must clear their
 *               minimums (a stranger of A's does not cascade; a B who barely knows the player says nothing).
 *   knowledge   how likely A is to know of the tie: a close friend of B hears more than an acquaintance, and one
 *               who lives in B's hold hears more than one across the map (the prompt-gating hold graph, where
 *               each was last seen); never below knowledge.floor (word gets round). It is 1 when A has reason to
 *               know in full, the way the cascade's ripples reach someone: A was THERE (B was seen in A's hold
 *               within association.witness_window_game_minutes, so A can see for themself), A and B actually TALKED
 *               (an NPC-to-NPC exchange, within association.talk_hold_game_hours: word of mouth needs no clock), or
 *               the player has just named B (mentions, below); otherwise it is the delay's guess, by bond and distance.
 *   the sum     is softened toward association.cap (tanh: the fifth friend adds less than the first) and
 *               multiplied by WHO A IS (susceptibility): a guarded, self-assured or proud NPC makes up their own
 *               mind, a warm one is swayed by those they love, a mature one is steadier (the same softening that
 *               blunts a mature NPC's jealousy, never below susceptibility.floor): never a wall, no one is immune.
 *   it is a standing judgement, not news: it settles toward its target on the game calendar (rates), is
 *   re-read every recheck_game_hours, and moves with the tie (B falls out with the player and the warmth goes).
 *   It reaches core's Player.aff as a queued delta (the cascade's path), tracked as applied so it is given back
 *   exactly as it fades. Its words are a standing felt line (feelings, never numbers).
 *
 * LOVE TRIANGLES (rivalry). A and B both want the player (each side's interest, RelDynAttraction::interest: a
 * shy hidden interest counts, a core romantic type counts for an NPC with no RelDyn state): rivalry pressure is
 * the product of the two interests, times who A is (possessive, proud, reactive, immature feel it more; a mature
 * NPC less, never to nothing). It cools A's own regard for B (A's relationships[B].aff, A's own row, written
 * under core's relationship lock) toward triangle.cap points below where it was, gradually; B's side happens at
 * B's own prerequest, so until B next loads, the rivalry is one-sided. When the triangle resolves (an interest
 * fades) the cooling is given back. A line in A's words names the rival.
 *
 * NAME LOOKUP (mention). The player names someone in a request: the NPC's own bond to the named NPC colours the
 * answer even if the named NPC is far away ("Nazeem" - their JSONB is checked for him, no 3D presence needed). One
 * felt line by A's feeling toward the named NPC (fond / wary / hostile), once per name per cooldown, only if A has
 * a bond past mention.min_bond. And the player having said the name makes the tie known to A at once: the
 * friend-of-a-friend reading of that named NPC takes knowledge 1 for mention.knowledge_hold_game_hours.
 *
 * Switches: cascade_ext.enabled (all three), and association.enabled / triangle.enabled / mention.enabled.
 * Units: core affinity points -100..100; interest, pressure, knowledge, susceptibility unitless; time on the game
 * calendar (raw gamets, RelationshipDynamics::GAMETS_PER_DAY). State: $dynamics['_circle'] (KEY).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynCascadeExt
{
    const KEY = '_circle';
    /** plugin_extended_data.reldyn key of who the NPC talked to and when: [['with', 'gamets']], appended atomically, outside the bond state (an NPC-to-NPC exchange moves nothing of the player pair). */
    const TALK_KEY = 'circle_talk';
    const TALK_KEEP = 16;
    const MAX_MENTIONS = 8;
    const MAX_LINKS = 8;
    /** NPCs of the NPC's bonds (strongest first) a triangle looks through. */
    const MAX_SCAN = 40;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'cascade_ext' (nested tables merge per entry). Serene's starting values for Ken's §24 item. */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,

            // --- friend of a friend ---
            'association' => [
                'enabled' => true,
                'min_bond' => 30.0,        // core points: |A's affinity toward B| must exceed this for B to count as a friend or a foe
                'min_closeness' => 25.0,   // core points: |B's affinity toward the player| must reach this for the player to be close to (or at odds with) B
                'enemy_mult' => 0.5,       // A dislikes B: the inverted reading is this much of a friend's
                'foe_weight' => 0.6,       // B at odds with the player counts this much of B close to the player
                'gain' => 14.0,            // core points one link adds at bond 100 and closeness 100, before knowledge and who A is
                'cap' => 12.0,             // core points: the whole association is softened toward this (tanh) and never passes it
                'max_links' => 4,          // friends and foes counted (the strongest bonds first)
                'recheck_game_hours' => 6.0,
                // how likely A is to know of the tie: floor + bond_relief x |bond|/100 (within 0..1), x 1 / (1 + per_hold_step x hold steps),
                // never below floor. An unknown place is as far as the hold graph's unknown_hold_distance.
                'knowledge' => ['floor' => 0.25, 'bond_relief' => 0.5, 'per_hold_step' => 0.35],
                // the ways the tie is known in full: B was seen in A's hold this recently (game minutes), A and B talked this recently (game hours)
                'witness_window_game_minutes' => 60.0,
                'talk_hold_game_hours' => 48.0,
                // who A is: x (1 + sum of gain x (trait - 0.5) x 2) x the maturity softening, within floor..ceiling
                'susceptibility' => ['floor' => 0.3, 'ceiling' => 1.6, 'trait_gain' => ['G' => -0.35, 'C' => -0.25, 'Pd' => -0.2, 'W' => 0.25]],
                // settling: this share of the gap per game hour (1 - exp(-rate x hours)); the first reading counts first_game_hours
                'rates' => ['per_game_hour' => 0.15, 'first_game_hours' => 6.0, 'max_step_game_hours' => 24.0],
                // the felt line: from this many core points applied, salience 0..1
                'felt' => ['enabled' => true, 'from' => 2.0, 'salience' => 0.55],
                // {NAME} A, {PLAYER} the player, {OTHER} the friend or foe in between
                'felt_text' => [
                    'ally_close'  => "{OTHER} speaks well of {PLAYER}, and {NAME} trusts {OTHER}; that goodwill colours how {NAME} sees {PLAYER} before anything has been said.",
                    'rival_close' => "{NAME} has no love for {OTHER}, and {PLAYER} being close to {OTHER} colours how {NAME} sees {PLAYER}: wary before a word is said.",
                    'ally_foe'    => "{OTHER} has no use for {PLAYER}, and {NAME} takes {OTHER}'s word seriously; {NAME} starts out cooler toward {PLAYER} for it.",
                    'rival_foe'   => "{NAME} and {OTHER} do not get on, and {OTHER} has no use for {PLAYER}; that makes {PLAYER} easier to like.",
                ],
            ],

            // --- love triangles ---
            'triangle' => [
                'enabled' => true,
                'min_interest' => 0.3,       // each side's interest in the player (0..1) for a triangle to exist
                'min_player_aff' => 20.0,    // core points: the other's affinity toward the player, to look at them at all (a cheap filter)
                'max_candidates' => 3,       // the others looked at (the strongest bonds to the player first)
                // an NPC with no RelDyn state of their own: the interest their core Player.type stands for
                'type_interest' => ['romantic' => 0.85, 'obsessed' => 0.85, 'crush' => 0.65, 'bonded' => 0.85],
                'cap' => 30.0,               // core points: the most the rivalry takes off A's regard for B at full pressure and full disposition
                'recheck_game_hours' => 6.0,
                // who A is: x (1 + sum of gain x (trait - 0.5) x 2) x the maturity softening, within floor..ceiling
                'disposition' => ['floor' => 0.3, 'ceiling' => 1.8, 'trait_gain' => ['Po' => 0.5, 'Pd' => 0.3, 'L' => 0.3]],
                'rates' => ['per_game_hour' => 0.1, 'first_game_hours' => 6.0, 'max_step_game_hours' => 24.0],
                // the felt line: from this many core points taken off, salience 0..1; text by maturity (below mature_below: sharp)
                'felt' => ['enabled' => true, 'from' => 3.0, 'salience' => 0.6, 'mature_at' => 65.0, 'immature_below' => 35.0],
                // {NAME} A, {PLAYER} the player, {RIVAL} the other who wants the player
                'felt_text' => [
                    'mature'   => "{NAME} knows {RIVAL} cares for {PLAYER} too, and is honest with themself about it; it shows only as a measured coolness toward {RIVAL}.",
                    'mixed'    => "{NAME} knows {RIVAL} wants {PLAYER} as well, and keeps a careful eye on how {PLAYER} treats {RIVAL}; the old warmth toward {RIVAL} has an edge on it.",
                    'immature' => "{NAME} bristles around {RIVAL}: {RIVAL} is competition for {PLAYER}, and {NAME} makes sure it is known.",
                ],
            ],

            // --- the name lookup ---
            'mention' => [
                'enabled' => true,
                'min_bond' => 15.0,                     // core points: |the NPC's affinity toward the named NPC| must exceed this for it to colour the answer
                'cooldown_game_hours' => 2.0,           // the same name colours the answer again only after this
                'knowledge_hold_game_hours' => 12.0,    // the tie to the named NPC is known to the NPC this long after the player named them
                'first_name' => true,                   // the first word of a name counts when it is 4 letters or more and names one of the NPC's bonds only
                'felt' => ['salience' => 0.6, 'fond_from' => 40.0, 'hostile_below' => -40.0],
                // {NAME} the NPC, {PLAYER} the player, {OTHER} the named NPC
                'felt_text' => [
                    'fond'    => "{PLAYER} brought up {OTHER}, who is not here; {NAME} is fond of {OTHER}, and it warms the answer.",
                    'wary'    => "{PLAYER} brought up {OTHER}, who is not here; {NAME} has mixed feelings about {OTHER} and the answer is careful.",
                    'cool'    => "{PLAYER} brought up {OTHER}, who is not here; {NAME} thinks little of {OTHER}, and it cools the answer.",
                    'hostile' => "{PLAYER} brought up {OTHER}, who is not here; {NAME} cannot stand {OTHER}, and the answer has an edge.",
                ],
            ],
        ];
    }

    /** The section as RelDyn reads it: the stored row laid over the defaults, table by table and one level down. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('cascade_ext');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        foreach (['association', 'triangle', 'mention'] as $sec) {
            $cfg[$sec] = is_array($stored[$sec] ?? null) ? array_replace($defaults[$sec], $stored[$sec]) : $defaults[$sec];
            foreach ($defaults[$sec] as $k => $v) {
                if (is_array($v) && !array_is_list($v)) {
                    $cfg[$sec][$k] = is_array($stored[$sec][$k] ?? null) ? array_replace($v, $stored[$sec][$k]) : $v;
                }
            }
        }
        foreach (['association' => ['susceptibility' => 'trait_gain'], 'triangle' => ['disposition' => 'trait_gain']] as $sec => $map) {
            foreach ($map as $table => $inner) {
                $d = $defaults[$sec][$table][$inner];
                $cfg[$sec][$table][$inner] = is_array($stored[$sec][$table][$inner] ?? null) ? array_replace($d, $stored[$sec][$table][$inner]) : $d;
            }
        }
        return $cfg;
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }

    // =====================================================================
    // PURE PARTS
    // =====================================================================

    /**
     * One link's lean on A's regard for the player, core points (before knowledge and who A is): A warms when A likes B and B
     * is close to the player, cools when A dislikes B and B is close; B at odds with the player reverses each, at foe_weight.
     * 0 when the bond or the closeness is under its minimum. Pure.
     *
     * @param float $bond      A's core affinity toward B (-100..100)
     * @param float $closeness B's core affinity toward the player (-100..100)
     */
    public static function leanOf(float $bond, float $closeness, ?array $cfg = null): float
    {
        $a = ($cfg ?? self::config())['association'];
        if (abs($bond) <= floatval($a['min_bond']) || abs($closeness) < floatval($a['min_closeness'])) return 0.0;
        $b = self::clamp($bond / 100.0, -1.0, 1.0);
        $c = self::clamp($closeness / 100.0, -1.0, 1.0);
        $lean = floatval($a['gain']) * $b * $c;
        if ($c < 0) $lean *= floatval($a['foe_weight']);
        if ($b < 0) $lean *= floatval($a['enemy_mult']);
        return $lean;
    }

    /** How likely A is to know of the tie to B: 0..1, never below knowledge.floor (word gets round). Pure. */
    public static function knowledgeOf(float $bond, int $holdSteps, ?array $cfg = null): float
    {
        $k = ($cfg ?? self::config())['association']['knowledge'];
        $floor = self::clamp(floatval($k['floor']), 0.0, 1.0);
        $base = self::clamp($floor + floatval($k['bond_relief']) * min(1.0, abs($bond) / 100.0), 0.0, 1.0);
        return max($floor, $base / (1.0 + max(0.0, floatval($k['per_hold_step'])) * max(0, $holdSteps)));
    }

    /** The product of a trait table's gains: 1 + sum gain x (trait - 0.5) x 2 (a vector that never read a trait is the middle). */
    private static function traitFactor(array $dynamics, array $gains): float
    {
        $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        $f = 1.0;
        foreach ($gains as $code => $gain) {
            $t = is_array($vector) && is_numeric($vector[$code] ?? null) ? self::clamp(floatval($vector[$code]), 0.0, 1.0) : 0.5;
            $f += floatval($gain) * ($t - 0.5) * 2.0;
        }
        return $f;
    }

    /**
     * Who A is, for the association: how easily A is swayed by those they love or loathe. The trait table x the maturity
     * softening (the curve that blunts a mature NPC's jealousy), within floor..ceiling. Never 0: no one is immune. Pure over $dynamics.
     */
    public static function susceptibility(array $dynamics, ?array $cfg = null): float
    {
        $s = ($cfg ?? self::config())['association']['susceptibility'];
        $f = self::traitFactor($dynamics, (array) $s['trait_gain']) * RelationshipDynamics::bystanderMaturityFactor($dynamics);
        return self::clamp($f, floatval($s['floor']), floatval($s['ceiling']));
    }

    /** The association target, core points: the sum softened toward the cap (tanh), times who A is. Pure. */
    public static function targetOf(float $sum, float $susceptibility, ?array $cfg = null): float
    {
        $cap = max(0.01, floatval(($cfg ?? self::config())['association']['cap']));
        return $cap * tanh($sum / $cap) * $susceptibility;
    }

    /** One settling step toward $target over $hours: this share of the gap per game hour. Pure. */
    public static function settle(float $applied, float $target, float $hours, array $rates): float
    {
        $share = 1.0 - exp(-max(0.0, floatval($rates['per_game_hour'])) * max(0.0, $hours));
        return $applied + ($target - $applied) * $share;
    }

    /** Rivalry pressure of a triangle: the two interests multiplied (both must reach min_interest), 0..1. Pure. */
    public static function pressureOf(float $interestA, float $interestB, ?array $cfg = null): float
    {
        $min = floatval(($cfg ?? self::config())['triangle']['min_interest']);
        if ($interestA < $min || $interestB < $min) return 0.0;
        return self::clamp($interestA, 0.0, 1.0) * self::clamp($interestB, 0.0, 1.0);
    }

    /** Who A is, for a rivalry: possessive, proud and reactive feel it more; a mature NPC less (floor); within floor..ceiling. */
    public static function rivalDisposition(array $dynamics, ?array $cfg = null): float
    {
        $d = ($cfg ?? self::config())['triangle']['disposition'];
        $f = self::traitFactor($dynamics, (array) $d['trait_gain']) * RelationshipDynamics::bystanderMaturityFactor($dynamics);
        return self::clamp($f, floatval($d['floor']), floatval($d['ceiling']));
    }

    // =====================================================================
    // THE STATE
    // =====================================================================

    private static function state(array $dynamics): array
    {
        $s = $dynamics[self::KEY] ?? null;
        $s = is_array($s) ? $s : [];
        $s['assoc'] = is_array($s['assoc'] ?? null) ? $s['assoc'] : [];
        $s['rivals'] = is_array($s['rivals'] ?? null) ? $s['rivals'] : [];
        $s['mentions'] = is_array($s['mentions'] ?? null) ? $s['mentions'] : [];
        return $s;
    }

    /**
     * What the circle has WRITTEN into core: the friend-of-a-friend points applied to the NPC's regard for the player, and the whole
     * points each rivalry took off their regard for a rival. A whole-NPC reset starts the NPC's RelDyn state over but leaves core's
     * numbers where they are, so this ledger is what must survive it: without it the reading would apply itself a second time on top
     * of what core already holds. Rivals keep a target of nothing, so a reset rivalry is given back, not forgotten.
     */
    public static function ledger(array $dynamics): array
    {
        $s = self::state($dynamics);
        $out = [];
        if (abs(floatval($s['assoc']['applied'] ?? 0.0)) >= 0.0001) $out['assoc'] = ['applied' => floatval($s['assoc']['applied'])];
        foreach ($s['rivals'] as $name => $r) {
            if (is_array($r) && abs(floatval($r['written'] ?? 0.0)) >= 0.5) {
                $out['rivals'][$name] = ['applied' => floatval($r['applied'] ?? 0.0), 'written' => floatval($r['written']), 'target' => 0.0, 'pressure' => 0.0];
            }
        }
        return $out;
    }

    /** The core affinity applied by the association, core points (0 when none). */
    public static function associationApplied(array $dynamics): float
    {
        return floatval($dynamics[self::KEY]['assoc']['applied'] ?? 0.0);
    }

    // =====================================================================
    // DATABASE
    // =====================================================================

    /**
     * Each of $names' core row: lower(name) => ['id', 'name', 'player' => their relationships.Player entry or []]. One query.
     * A name core has no row for is left out.
     */
    public static function playerEntries(array $names): array
    {
        $db = $GLOBALS['db'] ?? null;
        $names = array_values(array_unique(array_filter(array_map(fn($n) => mb_strtolower(trim((string) $n)), $names), fn($n) => $n !== '')));
        if (!$db || $names === []) return [];
        $in = implode(', ', array_map(fn($n) => $db->escapeLiteral($n), $names));
        try {
            $rows = $db->fetchAll("SELECT n.id, n.npc_name, n.extended_data -> 'relationships' -> '" . RelationshipDynamics::PLAYER_RELATIONSHIP_KEY . "' AS player
                FROM core_npc_master n
                WHERE lower(n.npc_name) IN ({$in})
                  AND n.id = (SELECT min(m.id) FROM core_npc_master m WHERE lower(m.npc_name) = lower(n.npc_name))");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('cascade extensions: bonds to the player', $e);
            return [];
        }
        $out = [];
        foreach ((array) $rows as $row) {
            $rel = json_decode((string) ($row['player'] ?? ''), true);
            $out[mb_strtolower((string) $row['npc_name'])] = ['id' => intval($row['id']), 'name' => (string) $row['npc_name'], 'player' => is_array($rel) ? $rel : []];
        }
        return $out;
    }

    /** A's bonds to other NPCs (never the player, never A): name => ['aff', 'type'], the strongest first. */
    public static function npcBonds(string $npcName): array
    {
        $out = [];
        foreach (RelationshipDynamics::getAllBondsForNpc($npcName) as $name => $bond) {
            $name = (string) $name;
            if ($name === '' || RelationshipDynamics::isPlayerRelationshipKey($name) || strcasecmp($name, $npcName) === 0) continue;
            $out[$name] = ['aff' => floatval($bond['aff'] ?? 0), 'type' => (string) ($bond['type'] ?? '')];
        }
        uasort($out, fn($a, $b) => abs($b['aff']) <=> abs($a['aff']));
        return $out;
    }

    /**
     * Move $npcName's own regard for $target (their relationships[$target].aff, only if the entry exists) by $delta whole core
     * points under core's relationship lock (the Player-affinity writer's protocol). Nothing is written when the editor locked
     * their relationships. Returns ['old', 'new', 'delta'] (plus 'locked') or null (no entry, no row, or a failure).
     */
    public static function applyBondDelta(string $npcName, string $target, int $delta): ?array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $delta === 0 || trim($target) === '') return null;
        $npcId = intval(RelDynStorage::resolveNpcId($npcName) ?? 0);
        if ($npcId <= 0) return null;
        $lockId = RelationshipDynamics::CORE_RELATIONSHIP_LOCK_BASE + $npcId;
        try {
            if ($db->execQuery('BEGIN') === false) throw new RuntimeException('BEGIN failed');
            if ($db->execQuery("SELECT pg_advisory_xact_lock({$lockId})") === false) throw new RuntimeException("advisory lock {$lockId} failed");
            $locked = $db->fetchOne("SELECT extended_data FROM core_npc_master WHERE id = {$npcId} FOR UPDATE");
            $ext = json_decode((string) ($locked['extended_data'] ?? '{}'));
            if (!is_object($ext)) throw new RuntimeException('extended_data is not valid JSON');
            $rels = $ext->relationships ?? null;
            $key = null;
            if (is_object($rels)) {
                foreach (array_keys(get_object_vars($rels)) as $k) {
                    if (strcasecmp((string) $k, $target) === 0) { $key = (string) $k; break; }
                }
            }
            if ($key === null || !is_object($rels->$key)) {
                $db->execQuery('COMMIT');
                return null;
            }
            $old = intval($rels->$key->aff ?? 0);
            if (!empty($ext->relationships_locked)) {
                $db->execQuery('COMMIT');
                RelationshipDynamics::log("[CIRCLE] {$npcName} -> {$target} " . sprintf('%+d', $delta) . ': relationships_locked (manual edits protected)');
                return ['old' => $old, 'new' => $old, 'delta' => 0, 'locked' => true];
            }
            $new = max(RelationshipDynamics::CORE_AFFINITY_MIN, min(RelationshipDynamics::CORE_AFFINITY_MAX, $old + $delta));
            $rels->$key->aff = $new;
            $json = $db->escape(json_encode($rels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($db->execQuery("UPDATE core_npc_master SET extended_data = jsonb_set(COALESCE(extended_data, '{}'::jsonb), '{relationships}', '{$json}'::jsonb, true) WHERE id = {$npcId}") === false) {
                throw new RuntimeException('relationships UPDATE failed');
            }
            if ($db->execQuery('COMMIT') === false) throw new RuntimeException('COMMIT failed');
        } catch (\Throwable $e) {
            $db->execQuery('ROLLBACK');
            error_log("[RelDyn] ERROR cascade extensions: {$npcName} -> {$target} {$delta} rolled back: " . $e->getMessage());
            return null;
        }
        if (function_exists('chimRelationshipTimelineStamp')) chimRelationshipTimelineStamp($npcId);
        RelationshipDynamics::log("[CIRCLE] {$npcName} -> {$target}: " . sprintf('%+d', $delta) . " (aff {$old} -> {$new})");
        return ['old' => $old, 'new' => $new, 'delta' => $new - $old];
    }

    // =====================================================================
    // THE NAME LOOKUP
    // =====================================================================

    /** The player's words in a request: the input row without the speaker prefix and the "(Talking to X)" tail; '' for a request that is not the player speaking. */
    public static function playerWords($gameRequest, string $playerName): string
    {
        if (!RelationshipDynamics::isPlayerInputRequest($gameRequest)) return '';
        $text = trim((string) ($gameRequest[3] ?? ''));
        if ($playerName !== '' && stripos($text, $playerName . ':') === 0) $text = substr($text, strlen($playerName) + 1);
        $text = preg_replace('/\s*\((?:Talking to|talking to)[^)]*\)\s*$/u', '', $text);
        return trim((string) $text);
    }

    /**
     * The names among $bondNames that the text names: the whole name, or (first_name) the first word of it when that is 4 letters
     * or more and no other bond shares it. Never the NPC themself or the player. In order of the bond list; each once.
     *
     * @param string[] $bondNames
     */
    public static function namedIn(string $text, array $bondNames, string $npcName, string $playerName, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        if (trim($text) === '') return [];
        $firsts = [];
        foreach ($bondNames as $n) {
            $w = preg_split('/\s+/u', trim((string) $n))[0] ?? '';
            $firsts[mb_strtolower($w)] = ($firsts[mb_strtolower($w)] ?? 0) + 1;
        }
        $out = [];
        foreach ($bondNames as $n) {
            $n = (string) $n;
            if (strcasecmp($n, $npcName) === 0 || strcasecmp($n, $playerName) === 0 || RelationshipDynamics::isPlayerRelationshipKey($n)) continue;
            $hit = RelDynQuests::names($text, $n);
            if (!$hit && !empty($cfg['mention']['first_name'])) {
                $w = preg_split('/\s+/u', trim($n))[0] ?? '';
                if (mb_strlen($w) >= 4 && ($firsts[mb_strtolower($w)] ?? 0) === 1 && strcasecmp($w, $npcName) !== 0 && strcasecmp($w, $playerName) !== 0) {
                    $hit = RelDynQuests::names($text, $w);
                }
            }
            if ($hit) $out[] = $n;
        }
        return $out;
    }

    // =====================================================================
    // PREREQUEST
    // =====================================================================

    /**
     * The NPC's own prerequest: the player's mention of someone, the friend-of-a-friend reading, the triangle. Call after the
     * affinity mirror was read from core (the cascade's onPrerequest). Returns what happened:
     * ['mentions' => [names], 'association' => ?['applied', 'target', 'delta'], 'triangle' => ?[rival => ['pressure', 'applied', 'delta']]].
     */
    public static function onPrerequest(string $npcName, array &$dynamics): array
    {
        $out = ['mentions' => [], 'association' => null, 'triangle' => null];
        if (!self::enabled()) return $out;
        $cfg = self::config();
        $now = RelationshipDynamics::currentGamets();
        if ($now <= 0) return $out;
        $state = self::state($dynamics);
        $bonds = null;

        // --- the name lookup: who the player just named ---
        if (!empty($cfg['mention']['enabled'])) {
            $text = self::playerWords($GLOBALS['gameRequest'] ?? null, trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')));
            if ($text !== '') {
                $bonds = self::npcBonds($npcName);
                $m = (array) $cfg['mention'];
                $cool = floatval($m['cooldown_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
                foreach (self::namedIn($text, array_keys($bonds), $npcName, trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')), $cfg) as $name) {
                    $bond = $bonds[$name]['aff'];
                    $key = mb_strtolower($name);
                    $prev = is_array($state['mentions'][$key] ?? null) ? $state['mentions'][$key] : [];
                    // the tie is known from now on (for a while), whatever the felt line does
                    $state['mentions'][$key] = ['name' => $name, 'at' => $now, 'bond' => $bond,
                        'felt_at' => $prev['felt_at'] ?? null, 'pending' => $prev['pending'] ?? false];
                    $out['mentions'][] = $name;
                    if (abs($bond) > floatval($m['min_bond']) && (!is_numeric($prev['felt_at'] ?? null) || $now - floatval($prev['felt_at']) >= $cool)) {
                        $state['mentions'][$key]['pending'] = true;
                        $state['mentions'][$key]['felt_at'] = $now;
                    }
                    RelationshipDynamics::log("[CIRCLE] {$npcName}: the player named {$name} (bond " . sprintf('%+.0f', $bond) . '); the NPC looks them up');
                }
                $state['mentions'] = array_slice($state['mentions'], -self::MAX_MENTIONS, null, true);
            }
        }

        // --- friend of a friend ---
        if (!empty($cfg['association']['enabled'])) {
            $bonds = $bonds ?? self::npcBonds($npcName);
            $out['association'] = self::stepAssociation($npcName, $dynamics, $state, $bonds, $now, $cfg);
        }

        // --- love triangles ---
        if (!empty($cfg['triangle']['enabled'])) {
            $bonds = $bonds ?? self::npcBonds($npcName);
            $out['triangle'] = self::stepTriangle($npcName, $dynamics, $state, $bonds, $now, $cfg);
        }

        $dynamics[self::KEY] = $state;
        return $out;
    }

    /**
     * Where and when $npcName was last seen: ['hold' => the hold ('' when unknown), 'gamets' => when]. The newest eventlog row that had
     * them present (people column) with a location, at most the cascade's delivery.last_seen_lookback_game_days before $before. The
     * place is read as the cascade reads it (core logs the whole location context string: the hold is in it; a bare place name is
     * looked up in core's locations table).
     */
    public static function lastSeen(string $npcName, float $before): array
    {
        $none = ['hold' => '', 'gamets' => 0.0];
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($npcName) === '') return $none;
        $days = max(0.0, floatval(RelDynCascade::config()['delivery']['last_seen_lookback_game_days']));
        $since = $before > 0 ? (int) floor($before - $days * RelationshipDynamics::GAMETS_PER_DAY) : 0;
        $bare = $db->escapeLiteral('|' . mb_strtolower(trim($npcName)) . '|');
        $stated = $db->escapeLiteral('|' . mb_strtolower(trim($npcName)) . ' (');
        try {
            $row = $db->fetchOne(
                "SELECT e.location, e.gamets FROM eventlog e
                 WHERE e.location IS NOT NULL AND e.location <> '' AND e.gamets >= {$since}
                   AND (position({$bare} in lower(e.people)) > 0 OR position({$stated} in lower(e.people)) > 0)
                 ORDER BY e.gamets DESC, e.rowid DESC LIMIT 1");
            $location = is_array($row) ? trim((string) ($row['location'] ?? '')) : '';
            if ($location === '') return $none;
            $hold = RelDynFacets::parseLocationContext($location)['hold'];
            if ($hold === '') {
                $parsed = RelDynFacets::parseLocationContext($location);
                $place = $parsed['name'] !== '' ? $parsed['name'] : $location;
                $loc = $db->fetchOne('SELECT hold FROM locations WHERE lower(name) = lower(' . $db->escapeLiteral($place) . ') LIMIT 1');
                $hold = is_array($loc) ? trim((string) ($loc['hold'] ?? '')) : '';
            }
            return ['hold' => $hold, 'gamets' => floatval($row['gamets'] ?? 0)];
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("cascade extensions: where {$npcName} was last seen", $e);
            return $none;
        }
    }

    /**
     * Word of mouth: $npcName and $other actually talked (an NPC-to-NPC exchange, in $npcName's own request: the context hook). The
     * NPC's own circle notes it, so what $other has to say of the player's tie to them is known in full for a while
     * (association.talk_hold_game_hours). Only the speaker's own row is written. Returns true when something was noted.
     */
    public static function noteTalk(string $npcName, string $other, float $now): bool
    {
        if (!self::enabled() || empty(self::config()['association']['enabled']) || $now <= 0 || trim($other) === '' || strcasecmp($npcName, $other) === 0
            || RelationshipDynamics::isPlayerRelationshipKey($other)) {
            return false;
        }
        // only a talk with someone the NPC has a bond worth reading (the common radiant exchange writes nothing)
        $minBond = floatval(self::config()['association']['min_bond']);
        $counts = false;
        foreach (self::npcBonds($npcName) as $name => $b) {
            if (strcasecmp($name, trim($other)) === 0 && abs($b['aff']) > $minBond) { $counts = true; break; }
        }
        if (!$counts) return false;
        $id = RelDynStorage::resolveNpcId($npcName);
        if ($id === null) return false;
        // a line in the NPC's own talk ledger, appended atomically; the bond state (dynamics) is not touched
        if (!RelDynStorage::appendItem($id, self::TALK_KEY, ['with' => trim($other), 'gamets' => $now])) return false;
        $have = RelDynStorage::readKeyForUpdate($id, self::TALK_KEY);
        $n = is_array($have['value'] ?? null) && array_is_list($have['value']) ? count($have['value']) : 0;
        if ($n > self::TALK_KEEP) RelDynStorage::dropFirstItems($id, self::TALK_KEY, $n - self::TALK_KEEP);
        RelationshipDynamics::log("[CIRCLE] {$npcName} and {$other} talked: what {$other} knows of the player's ties is known to the NPC");
        return true;
    }

    /** The links (A's friends and foes who are close to, or at odds with, the player) with their lean, knowledge and kind. */
    private static function readLinks(string $npcName, array $bonds, array $state, float $now, array $cfg): array
    {
        $a = (array) $cfg['association'];
        $cands = [];
        foreach ($bonds as $name => $b) {
            if (abs($b['aff']) > floatval($a['min_bond'])) $cands[$name] = $b;
        }
        if ($cands === []) return [];
        $entries = self::playerEntries(array_keys($cands));
        $myHold = null;
        $links = [];
        // who the NPC talked to, and when (the newest talk with each)
        $talks = [];
        $id = RelDynStorage::resolveNpcId($npcName);
        $ledger = $id !== null ? RelDynStorage::readKeyForUpdate($id, self::TALK_KEY) : null;
        foreach (is_array($ledger['value'] ?? null) && array_is_list($ledger['value']) ? $ledger['value'] : [] as $t) {
            if (is_array($t) && is_string($t['with'] ?? null) && is_numeric($t['gamets'] ?? null)) {
                $k = mb_strtolower(trim($t['with']));
                $talks[$k] = max($talks[$k] ?? 0.0, floatval($t['gamets']));
            }
        }
        foreach ($cands as $name => $b) {
            $e = $entries[mb_strtolower($name)] ?? null;
            if ($e === null || !is_numeric($e['player']['aff'] ?? null)) continue;
            $closeness = floatval($e['player']['aff']);
            if (abs($closeness) < floatval($a['min_closeness'])) continue;
            $mention = $state['mentions'][mb_strtolower($name)] ?? null;
            $named = is_array($mention) && is_numeric($mention['at'] ?? null)
                && $now - floatval($mention['at']) <= floatval($cfg['mention']['knowledge_hold_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
            $talk = $talks[mb_strtolower($name)] ?? null;
            $talked = is_numeric($talk) && $now >= floatval($talk)
                && $now - floatval($talk) <= floatval($a['talk_hold_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
            $via = $named ? 'named' : ($talked ? 'talked' : null);
            $steps = 0;
            if ($via === null) {
                $myHold = $myHold ?? RelDynCascade::lastSeenHold($npcName, $now);
                $seen = self::lastSeen($e['name'], $now);
                $near = $seen['hold'] !== '' && $seen['hold'] === $myHold
                    && $now - $seen['gamets'] <= floatval($a['witness_window_game_minutes']) * RelationshipDynamics::GAMETS_PER_DAY / 1440.0;
                if ($near) $via = 'near';
                $steps = RelDynCascade::holdSteps($myHold, $seen['hold']);
            }
            $knowledge = $via !== null ? 1.0 : self::knowledgeOf($b['aff'], $steps, $cfg);
            $lean = self::leanOf($b['aff'], $closeness, $cfg) * $knowledge;
            if (abs($lean) < 0.0001) continue;
            $links[] = ['name' => $e['name'], 'bond' => round($b['aff'], 1), 'closeness' => round($closeness, 1), 'knowledge' => round($knowledge, 3),
                'lean' => round($lean, 4), 'via' => $via ?? 'guess', 'kind' => ($b['aff'] >= 0 ? 'ally' : 'rival') . '_' . ($closeness >= 0 ? 'close' : 'foe')];
        }
        usort($links, fn($x, $y) => abs($y['lean']) <=> abs($x['lean']));
        return array_slice($links, 0, max(1, intval($a['max_links'])));
    }

    private static function stepAssociation(string $npcName, array &$dynamics, array &$state, array $bonds, float $now, array $cfg): ?array
    {
        $a = (array) $cfg['association'];
        $s = $state['assoc'];
        $applied = floatval($s['applied'] ?? 0.0);
        $recheck = floatval($a['recheck_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        $fresh = is_numeric($s['checked'] ?? null) && $now - floatval($s['checked']) < $recheck && $now >= floatval($s['checked']);
        // a name the player just said is read at once
        foreach ($state['mentions'] as $m) {
            if (is_array($m) && is_numeric($m['at'] ?? null) && abs($now - floatval($m['at'])) < 1.0) $fresh = false;
        }
        if (!$fresh) {
            $links = self::readLinks($npcName, $bonds, $state, $now, $cfg);
            $sum = array_sum(array_column($links, 'lean'));
            $susceptibility = self::susceptibility($dynamics, $cfg);
            $s['links'] = $links;
            $s['sum'] = round($sum, 4);
            $s['susceptibility'] = round($susceptibility, 4);
            $s['target'] = round(self::targetOf($sum, $susceptibility, $cfg), 4);
            $s['checked'] = $now;
        }
        $target = floatval($s['target'] ?? 0.0);
        $rates = (array) $a['rates'];
        $last = is_numeric($s['at'] ?? null) ? floatval($s['at']) : null;
        $hours = $last === null ? floatval($rates['first_game_hours'])
            : min(floatval($rates['max_step_game_hours']), max(0.0, ($now - $last) / (RelationshipDynamics::GAMETS_PER_DAY / 24.0)));
        $new = self::settle($applied, $target, $hours, $rates);
        $delta = $new - $applied;
        $s['at'] = $now;
        if (abs($delta) >= 0.001) {
            $s['applied'] = round($new, 4);
            RelationshipDynamics::queueAffinityDelta($dynamics, $delta);
            RelationshipDynamics::log(sprintf('[CIRCLE] %s: friend-of-a-friend %+.2f toward the player (target %+.2f, applied %+.2f)', $npcName, $delta, $target, $new));
        } else {
            $s['applied'] = round($applied, 4);
        }
        $state['assoc'] = $s;
        // whole points reach core now: the state (what is applied) is saved first, the commit after, so a crash between never gives the same points twice
        if (abs($delta) >= 0.001 && abs(floatval($dynamics['_pending_aff_delta'] ?? 0)) >= 1.0) {
            $dynamics[self::KEY] = $state;
            if (RelationshipDynamics::saveDynamics($npcName, $dynamics)) {
                $r = RelationshipDynamics::commitPlayerAffinity($npcName, $dynamics);
                if ($r !== null) {
                    $GLOBALS['RELDYN_PRE_AFF'] = intval($r['new']);
                    RelationshipDynamics::log("[CIRCLE] {$npcName}: core affinity {$r['old']} -> {$r['new']} from the NPC's friends and foes");
                }
            } else {
                error_log("[RelDyn] ERROR cascade extensions: {$npcName}'s state was not saved; the friend-of-a-friend delta waits for the next request");
            }
        }
        return ['applied' => round($new, 3), 'target' => round($target, 3), 'delta' => round($delta, 3)];
    }

    /** A's and B's interest in the player: [0..1]. B's RelDyn state when they have one, else the interest their core type stands for. */
    private static function interestOf(?array $dynamics, array $playerEntry, array $cfg): float
    {
        $i = 0.0;
        if (is_array($dynamics) && $dynamics !== []) $i = floatval(RelDynAttraction::interest($dynamics)['interest'] ?? 0.0);
        $type = strtolower(trim((string) ($playerEntry['type'] ?? '')));
        $byType = array_change_key_case((array) $cfg['triangle']['type_interest'], CASE_LOWER);
        if ($type !== '' && isset($byType[$type]) && (!is_array($dynamics) || $dynamics === [])) $i = max($i, floatval($byType[$type]));
        return self::clamp($i, 0.0, 1.0);
    }

    private static function stepTriangle(string $npcName, array &$dynamics, array &$state, array $bonds, float $now, array $cfg): ?array
    {
        $t = (array) $cfg['triangle'];
        $rivals = $state['rivals'];
        $mine = floatval(RelDynAttraction::interest($dynamics)['interest'] ?? 0.0);
        $checked = is_numeric($state['rivals_checked'] ?? null) ? floatval($state['rivals_checked']) : null;
        $recheck = floatval($t['recheck_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0;
        $due = $checked === null || $now - $checked >= $recheck || $now < $checked;
        $pressures = [];
        if ($due && ($mine >= floatval($t['min_interest']) || $rivals !== [])) {
            // the strongest bonds only (a core NPC can hold hundreds): the one query stays small
            $entries = self::playerEntries(array_slice(array_keys($bonds), 0, self::MAX_SCAN));
            $cands = [];
            foreach ($entries as $low => $e) {
                $aff = floatval($e['player']['aff'] ?? 0.0);
                $type = strtolower(trim((string) ($e['player']['type'] ?? '')));
                $byType = array_change_key_case((array) $t['type_interest'], CASE_LOWER);
                if ($aff >= floatval($t['min_player_aff']) || isset($byType[$type])) $cands[] = ['e' => $e, 'aff' => $aff];
            }
            usort($cands, fn($x, $y) => $y['aff'] <=> $x['aff']);
            foreach (array_slice($cands, 0, max(1, intval($t['max_candidates']))) as $c) {
                $e = $c['e'];
                $theirDynamics = RelDynStorage::loadDynamics($e['id']);
                $theirs = self::interestOf($theirDynamics, $e['player'], $cfg);
                $p = self::pressureOf($mine, $theirs, $cfg);
                if ($p > 0) $pressures[$e['name']] = ['pressure' => $p, 'interest' => $theirs];
            }
            $state['rivals_checked'] = $now;
            // a rival whose triangle ended is still in the ledger: its pressure is 0 and the cooling is given back
            foreach ($rivals as $name => $r) {
                if (!isset($pressures[$name]) && is_array($r)) $pressures[$name] = ['pressure' => 0.0, 'interest' => 0.0];
            }
            $disposition = self::rivalDisposition($dynamics, $cfg);
            $state['rival_disposition'] = round($disposition, 4);
            foreach ($pressures as $name => $p) {
                $r = is_array($rivals[$name] ?? null) ? $rivals[$name] : ['applied' => 0.0, 'written' => 0.0, 'at' => null, 'since' => $now];
                $r['pressure'] = round($p['pressure'], 4);
                $r['target'] = round(-floatval($t['cap']) * $p['pressure'] * $disposition, 4);
                $rivals[$name] = $r;
            }
        }
        // settle every rival toward its target on the game calendar
        $out = [];
        $rates = (array) $t['rates'];
        foreach ($rivals as $name => $r) {
            if (!is_array($r)) { unset($rivals[$name]); continue; }
            // applied: the settled value, continuous; written: the whole core points actually taken off (or given back to) the
            // NPC's regard for the rival. The difference waits until it is a whole point.
            $applied = floatval($r['applied'] ?? 0.0);
            $written = floatval($r['written'] ?? 0.0);
            $target = floatval($r['target'] ?? 0.0);
            $last = is_numeric($r['at'] ?? null) ? floatval($r['at']) : null;
            $hours = $last === null ? floatval($rates['first_game_hours'])
                : min(floatval($rates['max_step_game_hours']), max(0.0, ($now - $last) / (RelationshipDynamics::GAMETS_PER_DAY / 24.0)));
            $new = self::settle($applied, $target, $hours, $rates);
            $r['at'] = $now;
            $r['applied'] = round($new, 4);
            $whole = (int) ($new - $written);   // toward zero
            if ($whole !== 0) {
                $w = self::applyBondDelta($npcName, (string) $name, $whole);
                if ($w !== null && !empty($w['locked'])) {
                    $r['applied'] = round($written, 4);   // the editor pinned the relationships: nothing is taken, and the ledger does not pile up
                } elseif ($w !== null) {
                    $r['written'] = round($written + intval($w['delta']), 4);
                }
                // (no entry or a failed write: the difference waits for the next request)
            }
            // a settled rivalry leaves the ledger (a residue under a point is not worth carrying)
            if (abs($target) < 0.001 && abs($r['applied']) < 0.5) {
                unset($rivals[$name]);
                continue;
            }
            $rivals[$name] = $r;
            $out[$name] = ['pressure' => floatval($r['pressure'] ?? 0.0), 'applied' => floatval($r['applied']), 'written' => floatval($r['written'] ?? 0.0), 'delta' => round($new - $applied, 3)];
        }
        $state['rivals'] = array_slice($rivals, 0, 6, true);
        return $out === [] ? null : $out;
    }

    // =====================================================================
    // FELT
    // =====================================================================

    /** Which mention text a bond reads as: fond / wary / cool / hostile. */
    public static function mentionKind(float $bond, ?array $cfg = null): string
    {
        $f = ($cfg ?? self::config())['mention']['felt'];
        if ($bond >= floatval($f['fond_from'])) return 'fond';
        if ($bond <= floatval($f['hostile_below'])) return 'hostile';
        return $bond < 0 ? 'cool' : 'wary';
    }

    /**
     * The NPC's lines for this turn (feelings, no numbers; pronoun vars NOT yet resolved): the standing friend-of-a-friend
     * reading and rivalry, and, once, the name the player just said (only if that NPC is not here).
     *
     * @param string[] $people names around (CACHE_PEOPLE)
     * @return array ['lines' => [['key', 'text', 'salience', 'lane' => 'core'|'turn']], 'changed' => bool]
     */
    public static function feltLines(array &$dynamics, string $npcName, string $playerRef, array $people, bool $addressed): array
    {
        $out = ['lines' => [], 'changed' => false];
        if (!self::enabled() || !is_array($dynamics[self::KEY] ?? null)) return $out;
        $cfg = self::config();
        $s = self::state($dynamics);
        $here = array_map(fn($p) => mb_strtolower(trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', (string) $p))), $people);
        $fill = fn(string $text, string $other, string $key = '{OTHER}') => strtr($text, ['{NAME}' => $npcName, '{PLAYER}' => $playerRef, $key => $other]);

        // the standing reading of the NPC's friends and foes
        $a = (array) $cfg['association'];
        if (!empty($a['felt']['enabled']) && abs(floatval($s['assoc']['applied'] ?? 0.0)) >= floatval($a['felt']['from']) && is_array($s['assoc']['links'] ?? null) && $s['assoc']['links'] !== []) {
            $applied = floatval($s['assoc']['applied']);
            // the strongest link that leans the way the NPC leans
            foreach ($s['assoc']['links'] as $link) {
                if (!is_array($link) || ($link['lean'] > 0) !== ($applied > 0)) continue;
                $text = (string) (($a['felt_text'] ?? [])[$link['kind'] ?? ''] ?? '');
                if ($text === '') continue;
                $out['lines'][] = ['key' => 'association', 'text' => $fill($text, (string) $link['name']), 'lane' => 'core',
                    'salience' => floatval($a['felt']['salience']) * min(1.0, 0.5 + abs($applied) / max(0.01, floatval($a['cap'])))];
                break;
            }
        }

        // the standing rivalry
        $t = (array) $cfg['triangle'];
        if (!empty($t['felt']['enabled'])) {
            $top = null;
            foreach ($s['rivals'] as $name => $r) {
                if (is_array($r) && -floatval($r['applied'] ?? 0.0) >= floatval($t['felt']['from']) && ($top === null || $r['applied'] < $s['rivals'][$top]['applied'])) $top = $name;
            }
            if ($top !== null) {
                $maturity = floatval($dynamics['dimensions']['maturity']['x'] ?? 50.0);
                $kind = $maturity >= floatval($t['felt']['mature_at']) ? 'mature' : ($maturity < floatval($t['felt']['immature_below']) ? 'immature' : 'mixed');
                $text = (string) (($t['felt_text'] ?? [])[$kind] ?? '');
                if ($text !== '') {
                    $out['lines'][] = ['key' => 'rivalry', 'text' => $fill($text, (string) $top, '{RIVAL}'), 'lane' => 'core', 'salience' => floatval($t['felt']['salience'])];
                }
            }
        }

        // the name the player just said (once; not when that NPC is in the room)
        $m = (array) $cfg['mention'];
        if ($addressed && !empty($m['enabled'])) {
            foreach ($s['mentions'] as $key => $e) {
                if (!is_array($e) || empty($e['pending'])) continue;
                $s['mentions'][$key]['pending'] = false;
                $out['changed'] = true;
                if (in_array(mb_strtolower((string) ($e['name'] ?? '')), $here, true)) continue;
                $text = (string) (($m['felt_text'] ?? [])[self::mentionKind(floatval($e['bond'] ?? 0.0), $cfg)] ?? '');
                if ($text === '') continue;
                $out['lines'][] = ['key' => 'mention', 'text' => $fill($text, (string) $e['name']), 'lane' => 'turn', 'salience' => floatval($m['felt']['salience'])];
            }
            if ($out['changed']) $dynamics[self::KEY] = $s;
        }
        return $out;
    }

    // =====================================================================
    // JEV
    // =====================================================================

    /** The state for Jev and the editor. */
    public static function jev(array $dynamics): array
    {
        $s = self::state($dynamics);
        $a = $s['assoc'];
        return [
            'enabled' => self::enabled(),
            'association' => [
                'applied' => round(floatval($a['applied'] ?? 0.0), 2),
                'target' => round(floatval($a['target'] ?? 0.0), 2),
                'susceptibility' => round(floatval($a['susceptibility'] ?? 0.0), 3),
                'links' => array_map(fn($l) => ['name' => (string) ($l['name'] ?? ''), 'lean' => round(floatval($l['lean'] ?? 0.0), 2), 'kind' => (string) ($l['kind'] ?? '')],
                    array_values(array_filter((array) ($a['links'] ?? []), 'is_array'))),
            ],
            'rivals' => array_map(fn($r) => ['pressure' => round(floatval($r['pressure'] ?? 0.0), 3), 'applied' => round(floatval($r['applied'] ?? 0.0), 2)],
                array_filter($s['rivals'], 'is_array')),
            'mentions' => array_values(array_map(fn($m) => (string) ($m['name'] ?? ''), array_filter($s['mentions'], 'is_array'))),
        ];
    }
}
