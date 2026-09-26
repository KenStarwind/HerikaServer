<?php
/**
 * Relationship Dynamics — Prompt gating: who knows the player (prompt-gating-core-hook,
 * prompt-gating-tier-knowledge, prompt-gating-tier-floor, prompt-gating-fame; Wondernutts' design,
 * D:\docs\prompt-gating-design.md, ported from the April plugin D:\prompt_gating\ onto CHIM 3.4.1
 * core data; decisions 2026-09-23 §7, §18 #6).
 *
 * "You can only be unknown once." Three axes decide what an NPC knows of the player:
 *   relationship tier  core affinity toward the player (RelDyn tiers, core points -100..100): from
 *                      name_min_tier (acquaintance) she knows the player's name; from bio_min_tier
 *                      (friend) their story (core's player bio).
 *   tier floor         the highest core affinity she ever held toward the player (_gating.peak_core_aff,
 *                      noted with the context tier high-water mark) keeps what she learned: once at
 *                      floor_tier she never forgets the name again, a fallen bond reads 'lapsed', not
 *                      'stranger'. A context tier high-water mark (context_tier_hwm >= 1) counts too.
 *                      So does any history with the player, warm or not ('met', metHistory): core's
 *                      speech rows between them (met_min_speech; the signal core's own familiarity
 *                      note reads), RelDyn's interaction history, or a core affinity outside the
 *                      stranger band, such as a start in hostility. Core's relationship type alone
 *                      is no history (a 'professional' merchant she has never spoken to).
 *   fame x proximity   what the player is known for (RelDynReputation::fameScores: the reputation
 *                      layer's fame / infamy and its per-questline fames, from RelDynPlayer's profile of
 *                      core's tracked stats and journal) heard where she is: each fame has a home hold
 *                      and a reach in hold steps (the April design's fame_location_gating and its
 *                      hold_adjacency, BFS on core's canonical holds; no home = heard everywhere). A
 *                      stranger who has heard of the player knows the deeds, not the name (the design:
 *                      #PLAYER_REF# is the name only from acquaintance). Below fame_min_core_aff
 *                      (core's Wary floor) she wants none of it (the April rule).
 * Knowledge levels: personal (current tier), lapsed (the floor), met (a history, never warm),
 * renowned (never met, the deeds heard), stranger.
 *
 * Where it lands (the one CHIM fork hook, lib/relationship_manager.php chimPlayerKnowledgeFor,
 * registered by player_knowledge.php): core shows the player's bio in the nearby actors only to an NPC
 * who knows their story and puts the player's line in its relationship block only for an NPC who has
 * met them. The familiarity note on the player's nearby entry stays core's own wherever core knows
 * they have talked; RelDyn's note replaces it for a stranger, and for an NPC who knows the player where
 * core has no speech row (core would call her a stranger). Only for NPCs: core's non-NPC callers
 * (HERIKA_NAME "(actor)", player_rewrite.php and the rolemaster processors) keep core's behaviour.
 * The name itself stays in core's entry and the dialogue history (the design's section 5: an
 * instruction, not stripping). RelDyn's own text follows the same decision: <knowledge_of_player>
 * (RelDynFelt::knowledgeOfPlayer, with the heard fames as rumours for a stranger or an acquaintance,
 * token-budgeted), the player's referent in every felt line (RelDynFelt::playerRef) and, for an NPC
 * who does not know the name, an instruction in COMMAND_PROMPT not to use it (contextPre). Without the
 * core hook (upstream CHIM) core keeps naming the player and RelDyn agrees with it
 * (RelDynFelt::coreNamesPlayer). The Narrator knows everything.
 * Not in CHIM 3.4.1 core, so not here: thane titles, faction ranks, the civil-war side.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynGating
{
    const KEY = '_gating';

    /** Knowledge levels, least to most. */
    const LEVELS = ['stranger', 'renowned', 'met', 'lapsed', 'personal'];

    /** Levels at which she has met the player: the name, and the player's line in core's block. */
    const KNOWN_LEVELS = ['met', 'lapsed', 'personal'];

    /** Per request scope: [scope token, npc => hold]. */
    private static $holdCache = null;

    /** Per request scope: [scope token, npc => speech rows with the player (capped at met_min_speech)]. */
    private static $speechCache = null;

    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // RelDyn tiers (RelationshipDynamics::RELATIONSHIP_TIERS, on core affinity points)
            'name_min_tier' => 'acquaintance',   // she knows the player's name from here
            'floor_tier' => 'acquaintance',      // once reached, the name is never forgotten (lapsed)
            'bio_min_tier' => 'friend',          // she knows the player's story (core's player bio) from here
            // Met: core's speech rows between her and the player (either way) from which she has met
            // them (core's own familiarity note tells none from some)
            'met_min_speech' => 1,
            // Fame (core affinity points): below fame_min_core_aff (core's Wary floor) she hears none
            // of it; above fame_max_tier's range she knows the player too well for rumours to matter.
            'fame_min_core_aff' => -30,
            'fame_max_tier' => 'acquaintance',
            // Hold steps assumed when her hold (or a fame's home) is not on the map: only fames heard
            // everywhere reach her (April open question 2: treat an unknown hold as far).
            'unknown_hold_distance' => 9,
            // Rumour lines in <knowledge_of_player>: at most fame_max_lines, most famous first, and the
            // whole block within token_budget estimated tokens (the April design's 150).
            'fame_max_lines' => 2,
            'token_budget' => 150,
            // Skyrim's nine holds by core's canonical names (getCanonicalHoldGroups): the April
            // design's hold_adjacency (prompt-gating-design.md; D:\prompt_gating\install.php shipped
            // the same borders). A border listed on either side counts both ways.
            'hold_adjacency' => [
                'Whiterun Hold'  => ['Falkreath Hold', 'The Pale', 'Eastmarch', 'Hjaalmarch', 'The Reach'],
                'Haafingar'      => ['Hjaalmarch', 'The Reach'],
                'Hjaalmarch'     => ['Haafingar', 'The Pale', 'Whiterun Hold', 'The Reach'],
                'The Pale'       => ['Hjaalmarch', 'Whiterun Hold', 'Winterhold', 'Eastmarch'],
                'Winterhold'     => ['The Pale', 'Eastmarch'],
                'Eastmarch'      => ['Winterhold', 'The Pale', 'Whiterun Hold', 'The Rift'],
                'The Rift'       => ['Eastmarch', 'Falkreath Hold'],
                'Falkreath Hold' => ['The Rift', 'Whiterun Hold'],
                'The Reach'      => ['Whiterun Hold', 'Hjaalmarch', 'Haafingar'],
            ],
            'text' => [
                // the player in a rumour line when she does not know the name
                'rumor_ref' => "someone of this stranger's description",
                // COMMAND_PROMPT, for an NPC who does not know the name ({PLAYER_NAME} = the name;
                // the design's section 5, prong 2)
                'name_unknown' => "{NAME} does not know this person's name. Never call them \"{PLAYER_NAME}\": address them as {NAME} would a stranger, unless they give their name in this conversation.",
                // The familiarity note on the player's nearby-actors entry, by knowledge level: for a
                // stranger, and for an NPC who knows the player where core has no speech row (core's
                // own note would call her a stranger); elsewhere core's own note stands
                'note' => [
                    'personal' => '{NAME} knows this person',
                    'lapsed'   => '{NAME} has met this person before',
                    'met'      => '{NAME} has crossed paths with this person before',
                    'renowned' => '{NAME} has never met this person and does not know their name, but has heard of their deeds',
                    'stranger' => '{NAME} has never met this person and does not know their name, past or deeds',
                ],
            ],
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('prompt_gating');
        if (!is_array($stored)) return $defaults;
        $cfg = array_replace($defaults, $stored);
        $cfg['text'] = array_replace_recursive($defaults['text'], is_array($stored['text'] ?? null) ? $stored['text'] : []);
        return $cfg;
    }

    /** RelDyn on and prompt gating on. */
    public static function enabled(): bool
    {
        return RelationshipDynamics::isEnabled() && !empty(self::config()['enabled']);
    }

    /** CHIM core carries the fork hook (lib/relationship_manager.php chimPlayerKnowledgeFor). */
    public static function coreHookPresent(): bool
    {
        if (!function_exists('chimPlayerKnowledgeFor')) {
            $rm = ($GLOBALS['ENGINE_PATH'] ?? dirname(__DIR__, 2) . '/') . 'lib/relationship_manager.php';
            if (is_file($rm)) require_once $rm;
        }
        return function_exists('chimPlayerKnowledgeFor');
    }

    /** RelDyn decides what core shows of the player: gating on and the fork hook in core. */
    public static function gatesCore(): bool
    {
        return self::enabled() && self::coreHookPresent();
    }

    // =====================================================================
    // HOLDS
    // =====================================================================

    /** Hold name to a comparison key: case, "The " and " Hold" dropped ('Whiterun Hold' = 'Whiterun'). */
    public static function holdKey(string $hold): string
    {
        $k = strtolower(trim((string) preg_replace('/\s+/u', ' ', $hold)));
        $k = (string) preg_replace('/^the /', '', $k);
        return (string) preg_replace('/ hold$/', '', $k);
    }

    /**
     * Hold steps between two holds (BFS over the adjacency, borders both ways): 0 the same hold,
     * null when either is not on the map or no path joins them.
     */
    public static function holdDistance(string $a, string $b, ?array $cfg = null): ?int
    {
        $cfg = $cfg ?? self::config();
        $graph = [];
        foreach ((array) $cfg['hold_adjacency'] as $hold => $borders) {
            $h = self::holdKey((string) $hold);
            $graph[$h] = $graph[$h] ?? [];
            foreach ((array) $borders as $border) {
                $n = self::holdKey((string) $border);
                $graph[$h][$n] = true;
                $graph[$n][$h] = true;
            }
        }
        $from = self::holdKey($a);
        $to = self::holdKey($b);
        if ($from === '' || $to === '' || !isset($graph[$from], $graph[$to])) return null;
        if ($from === $to) return 0;
        $seen = [$from => 0];
        $queue = [$from];
        while ($queue) {
            $h = array_shift($queue);
            foreach (array_keys($graph[$h]) as $n) {
                if (isset($seen[$n])) continue;
                $seen[$n] = $seen[$h] + 1;
                if ($n === $to) return $seen[$n];
                $queue[] = $n;
            }
        }
        return null;
    }

    /** The hold $npcName is in now (core's newest location context, RelDynFacets::currentPlaceContext), '' when unknown. */
    public static function currentHold(string $npcName): string
    {
        $token = RelationshipDynamics::requestScopeToken();
        if ($token !== null && is_array(self::$holdCache) && self::$holdCache[0] === $token && isset(self::$holdCache[1][$npcName])) {
            return self::$holdCache[1][$npcName];
        }
        $hold = trim((string) (RelDynFacets::currentPlaceContext($npcName)['hold'] ?? ''));
        if ($token !== null) {
            if (!is_array(self::$holdCache) || self::$holdCache[0] !== $token) self::$holdCache = [$token, []];
            self::$holdCache[1][$npcName] = $hold;
        }
        return $hold;
    }

    // =====================================================================
    // FAME x PROXIMITY
    // =====================================================================

    /**
     * The fames (RelDynReputation config 'fames') heard in $hold: key => ['score' 0..1, 'distance'
     * hold steps, 'text'], most famous first. $scores: RelDynReputation::fameScores().
     */
    public static function heardFames(string $hold, array $scores, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $out = [];
        foreach ((array) (RelDynReputation::config()['fames'] ?? []) as $key => $spec) {
            $score = $scores[$key] ?? null;
            if (!is_array($spec) || $score === null || floatval($score) < floatval($spec['min_score'] ?? 0)) continue;
            $home = trim((string) ($spec['home'] ?? ''));
            $dist = $home === '' ? 0 : self::holdDistance($hold, $home, $cfg);
            if ($dist === null) $dist = intval($cfg['unknown_hold_distance']);
            if ($dist > intval($spec['reach'] ?? 0)) continue;
            $out[(string) $key] = ['score' => round(floatval($score), 4), 'distance' => $dist, 'text' => (string) ($spec['text'] ?? '')];
        }
        uasort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    // =====================================================================
    // KNOWLEDGE
    // =====================================================================

    /** Core affinity (points) of core's Player entry for $npcName; 0 (a neutral stranger) without one. */
    private static function coreEntryAffinity(string $npcName): float
    {
        $rel = RelationshipDynamics::getPlayerRelationship($npcName);
        return is_array($rel) && is_numeric($rel['aff'] ?? null) ? floatval($rel['aff']) : 0.0;
    }

    /**
     * Core's speech rows between $npcName and the player (PLAYER_NAME), either way, counted up to
     * met_min_speech (enough to tell met from not): the table core's own familiarity note reads
     * (DirectConversationsWith). 0 without a player name or a database; a failed read is logged.
     */
    public static function speechWith(string $npcName, ?array $cfg = null): int
    {
        $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? ''));
        $db = $GLOBALS['db'] ?? null;
        if ($player === '' || !$db || trim($npcName) === '') return 0;
        $token = RelationshipDynamics::requestScopeToken();
        if ($token !== null && is_array(self::$speechCache) && self::$speechCache[0] === $token && isset(self::$speechCache[1][$npcName])) {
            return self::$speechCache[1][$npcName];
        }
        $cfg = $cfg ?? self::config();
        $limit = max(1, intval($cfg['met_min_speech']));
        $n = $db->escape($npcName);
        $p = $db->escape($player);
        try {
            $row = $db->fetchOne("SELECT count(*) AS n FROM (SELECT 1 FROM speech WHERE (speaker = '{$n}' AND listener = '{$p}')"
                . " OR (speaker = '{$p}' AND listener = '{$n}') LIMIT {$limit}) AS met");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('prompt gating (speech rows)', $e);
            return 0;
        }
        $count = is_array($row) ? intval($row['n'] ?? 0) : 0;
        if ($token !== null) {
            if (!is_array(self::$speechCache) || self::$speechCache[0] !== $token) self::$speechCache = [$token, []];
            self::$speechCache[1][$npcName] = $count;
        }
        return $count;
    }

    /**
     * RelDyn's own record of a history with the player: an interaction history
     * (interaction_count / total_positive_interactions) or core affinity ($aff, points) outside the
     * stranger tier (a start in hostility: something has passed between them). Core's speech rows
     * are speechWith's.
     */
    public static function metHistory(array $dynamics, float $aff): bool
    {
        if (max(intval($dynamics['interaction_count'] ?? 0), intval($dynamics['total_positive_interactions'] ?? 0)) > 0) return true;
        return RelationshipDynamics::getCurrentTier($aff) !== 'stranger';
    }

    /**
     * What $npcName knows of the player:
     *   level         personal | lapsed | met | renowned | stranger
     *   name          she knows the player's name (personal, lapsed, met)
     *   bio           she knows the player's story (core's player bio)
     *   relationship  she has met the player (core's relationship block may show the player's line)
     *   speech        core's speech rows with the player (capped at met_min_speech), null when not read
     *   core_aff, peak_core_aff  core affinity points now and at its highest
     *   hold, fames   her hold and the fames heard there (heardFames), when the tier lets rumours matter
     * $dynamics: her RelDyn state (default: the stored state; none stored: core's Player entry only).
     */
    public static function knowledge(string $npcName, ?array $dynamics = null): array
    {
        $cfg = self::config();
        $tiers = RelationshipDynamics::RELATIONSHIP_TIERS;
        if ($dynamics === null) {
            $stored = RelationshipDynamics::loadStoredDynamics($npcName);
            $dynamics = is_array($stored) ? $stored : [];
        }
        $aff = is_numeric($dynamics['_aff_mirror_x'] ?? null)
            ? RelationshipDynamics::getCoreAffinity($dynamics) : self::coreEntryAffinity($npcName);
        $peak = max($aff, is_numeric($dynamics[self::KEY]['peak_core_aff'] ?? null) ? floatval($dynamics[self::KEY]['peak_core_aff']) : $aff);
        $hwm = intval($dynamics['context_tier_hwm'] ?? 0);
        $min = fn(string $key, float $def) => floatval($tiers[(string) $cfg[$key]]['min'] ?? $def);

        $level = null;
        $speech = null;
        if ($aff >= $min('name_min_tier', 6)) $level = 'personal';
        elseif ($peak >= $min('floor_tier', 6) || $hwm >= 1) $level = 'lapsed';
        elseif (self::metHistory($dynamics, $aff)) $level = 'met';
        elseif (($speech = self::speechWith($npcName, $cfg)) >= max(1, intval($cfg['met_min_speech']))) $level = 'met';

        $hold = '';
        $fames = [];
        $fameMax = floatval($tiers[(string) $cfg['fame_max_tier']]['max'] ?? 30);
        if ($aff >= floatval($cfg['fame_min_core_aff']) && $aff <= $fameMax) {
            $hold = self::currentHold($npcName);
            $fames = self::heardFames($hold, RelDynReputation::fameScores(), $cfg);
        }
        if ($level === null) $level = $fames ? 'renowned' : 'stranger';
        $known = in_array($level, self::KNOWN_LEVELS, true);
        return [
            'level' => $level,
            'name' => $known,
            'bio' => $peak >= $min('bio_min_tier', 31) || $hwm >= 2,
            'relationship' => $known,
            'speech' => $speech,
            'core_aff' => round($aff, 4),
            'peak_core_aff' => round($peak, 4),
            'hold' => $hold,
            'fames' => $fames,
        ];
    }

    /**
     * Tier floor: note her highest core affinity toward the player (points). True when it rose
     * (the caller saves). Nothing before core's affinity was mirrored in.
     */
    public static function notePeak(array &$dynamics): bool
    {
        if (!is_numeric($dynamics['_aff_mirror_x'] ?? null)) return false;
        $aff = round(RelationshipDynamics::getCoreAffinity($dynamics), 4);
        $state = is_array($dynamics[self::KEY] ?? null) ? $dynamics[self::KEY] : [];
        if (is_numeric($state['peak_core_aff'] ?? null) && floatval($state['peak_core_aff']) >= $aff) return false;
        $state['peak_core_aff'] = $aff;
        $dynamics[self::KEY] = $state;
        return true;
    }

    /**
     * The rumour lines (heard fames, most famous first, at most fame_max_lines) added to the
     * knowledge text $text while it stays within token_budget. $playerName: the player's name
     * when she knows it, null for the rumour referent (a stranger's description).
     */
    public static function withRumours(string $text, string $npcName, ?string $playerName, array $knowledge, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $ref = $playerName ?? (string) $cfg['text']['rumor_ref'];
        $budget = intval($cfg['token_budget']);
        $n = 0;
        foreach ((array) ($knowledge['fames'] ?? []) as $fame) {
            if ($n >= intval($cfg['fame_max_lines'])) break;
            $line = trim(strtr((string) $fame['text'], ['{NAME}' => $npcName, '{PLAYER}' => $ref]));
            if ($line === '') continue;
            $candidate = $text . ' ' . $line;
            if (RelDynFelt::estimateTokens($candidate) > $budget) break;
            $text = $candidate;
            $n++;
        }
        return $text;
    }

    // =====================================================================
    // HOOKS
    // =====================================================================

    /**
     * The fork hook's answer from a knowledge() result: ['bio', 'relationship', 'note', 'level'].
     * 'note' null keeps core's own familiarity note: for an NPC who knows the player where core
     * has speech rows between them (core's note then says they have talked, or nothing). A
     * stranger's note, and a known NPC's where core has none (core would call her a stranger),
     * come from text.note.
     */
    public static function gateAnswer(array $k, string $npcName, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $level = (string) $k['level'];
        $note = null;
        if (!in_array($level, self::KNOWN_LEVELS, true) || intval($k['speech'] ?? self::speechWith($npcName, $cfg)) < 1) {
            $note = strtr((string) ($cfg['text']['note'][$level] ?? ''), ['{NAME}' => $npcName]);
        }
        return ['bio' => !empty($k['bio']), 'relationship' => !empty($k['relationship']), 'note' => $note, 'level' => $level];
    }

    /**
     * The CHIM fork hook's answer for $npcName (player_knowledge.php; gateAnswer), or null (core's
     * own behaviour) for the Narrator, for core's non-NPC callers (a HERIKA_NAME such as "(actor)":
     * core's own guard, lib/data_functions.php DataLastInfoFor), with RelDyn or gating off, or when
     * the state cannot be read (logged).
     */
    public static function coreGate(string $npcName): ?array
    {
        $npcName = trim($npcName);
        if ($npcName === '' || strcasecmp($npcName, 'The Narrator') === 0 || strpos($npcName, 'actor') !== false) return null;
        try {
            if (!self::enabled()) return null;
            $k = self::knowledge($npcName);
            $answer = self::gateAnswer($k, $npcName);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('prompt gating (core hook)', $e);
            return null;
        }
        RelationshipDynamics::log("[RelDyn-GATING] {$npcName}: {$k['level']}" . ($k['hold'] !== '' ? " in {$k['hold']}" : '')
            . ' (bio ' . ($k['bio'] ? 'yes' : 'no') . ', heard: ' . (implode(', ', array_keys($k['fames'])) ?: 'nothing') . ')');
        return $answer;
    }

    /**
     * context_pre.php: for an NPC who does not know the player's name (and core does not name the
     * player to her either), the instruction in COMMAND_PROMPT not to use it.
     */
    public static function contextPre(string $npcName, string $playerName): void
    {
        if (!self::enabled() || RelDynFelt::coreNamesPlayer()) return;
        $k = self::knowledge($npcName);
        if ($k['name']) return;
        $text = strtr((string) self::config()['text']['name_unknown'], ['{NAME}' => $npcName, '{PLAYER_NAME}' => $playerName]);
        $GLOBALS['COMMAND_PROMPT'] = rtrim((string) ($GLOBALS['COMMAND_PROMPT'] ?? '')) . "\n\n" . $text;
        RelationshipDynamics::log("[RelDyn-GATING] {$npcName}: does not know the player's name");
    }
}
