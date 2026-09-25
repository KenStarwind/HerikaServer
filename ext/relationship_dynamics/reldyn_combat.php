<?php
/**
 * Relationship Dynamics — combat passion routing (MDD §3.3, roadmap combat-passion).
 *
 * One routine for every core combat event, whatever brought it in:
 *   - a combat request that reaches RelDyn's postrequest hook (combatend / combatendmighty when
 *     core voices the RPG comment; death / bleedout / radiantcombatfriend requests too);
 *   - core's eventlog: main.php logs 'death' and 'bleedout' events and terminates before any
 *     ext hook runs (its "log only" list), and Papyrus RecoverFromCombat's bleedout arrives as an
 *     'instruction' ("X has lost combat and is wounded bleedingout."). consumeEventlog() reads
 *     those rows once, in order, on RelDyn's next prerequest (a watermark on the eventlog rowid
 *     in conf_opts, claimed compare-and-set so two requests never route a row twice).
 *
 * route() per event (the April postrequest rules, config 'combat'):
 *   who     "X is teamed up with <player>" / "X has defeated Y" (X an NPC) / "X falls to the
 *           ground" / "X has lost combat": X is a direct participant; else the RelDyn NPCs
 *           around (CACHE_PEOPLE, or the row's people column); for a kill, the RelDyn NPCs
 *           nearby (people + party) are witnesses at witness_mult.
 *   fall    bleedout: RelationshipDynamics::bleedoutResponse (the NPC's own fight or fear);
 *           the same fall reported twice (bleedout + instruction) inside the active window counts once.
 *   gain    shared combat appraised as an activity (facets), x witness_mult, + kill streak
 *           (streak_per_kill per extra kill in the 5-play-minute window, cap streak_cap),
 *           x danger_mult when the NPC's live HP is at or under the MDD 3.3 threshold
 *           (danger_base - interestMultiplier(combat preference) x danger_interest_slope: the
 *           more she loves a fight, the nearer death it takes to feel it as danger), x shared_mult
 *           only when THIS NPC fought (fought()): its own combat
 *           bark, a kill of its own, its fall, the plugin's activity status in combat, or being
 *           the named participant. A bystander at a combat event is no longer "confirmed".
 *   grief   a death: RelDyn NPCs nearby with a bond to the deceased (onNpcDeath).
 * Defeat ("party defeated", -1.5 to -3) has no core signal (no defeat event in 3.4.1): unknown.
 *
 * Live HP (npcHealth / playerHealth): the AIAgent 3.4.1 plugin posts each nearby agent's
 * health / magicka / stamina to gamedata.php 'stats' (RefreshAIAgentStats: on a hit at most every
 * 3 s, every 15 s for agents nearby, on entering and leaving combat; the player the same way,
 * RefreshPlayerStats) and core keeps the latest report in core_npc_master.metadata.stats
 * (core_player.stats for the player). Core keeps no time with it, so it is the NPC's HP now: it
 * is read only for an event of now (within COMBAT_ACTIVE_WINDOW_GAMETS of the game clock), never
 * for an eventlog row routed later. No report (or a zero maximum) = unknown, never "healthy".
 *
 * Units: passion points; time on the eventlog game clock (raw gamets); windows are real-play
 * windows expressed in gamets (RelationshipDynamics::COMBAT_*_WINDOW_GAMETS).
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynCombat
{
    /** conf_opts row: the eventlog rowid up to which combat rows were routed. */
    const WATERMARK_ROW_ID = 'relationship_dynamics_combat_watermark';

    /** Request types that reach postrequest as combat events (plus the unreachable April ones). */
    const REQUEST_TYPES = RelationshipDynamics::CORE_COMBAT_REQUEST_TYPES;

    public static function configDefaults(): array
    {
        return [
            // Route core's death / bleedout eventlog rows (they never reach a hook)
            'consume_eventlog' => true,
            'batch' => 100,               // rows per prerequest at most (the rest on the next)
            'witness_mult' => 0.5,        // a kill seen, not made
            'shared_mult' => 1.3,         // this NPC fought beside the player
            'danger_mult' => 1.5,         // HP under the MDD 3.3 danger threshold (needs a known HP)
            'streak_per_kill' => 0.5,     // passion points per extra kill in the window
            'streak_cap' => 2.0,          // passion points
            // MDD 3.3 danger threshold, HP ratio: danger_base - interest multiplier x slope
            'danger_base' => 0.30,
            'danger_interest_slope' => 0.15,
        ];
    }

    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('combat');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    // =====================================================================
    // PARSING (pure)
    // =====================================================================

    /** Names in a core people string ("|A|B|") or a list. */
    public static function names($people): array
    {
        $list = is_array($people) ? $people : explode('|', (string) $people);
        return array_values(array_unique(array_filter(array_map(fn($n) => trim((string) $n), $list), fn($n) => $n !== '')));
    }

    /** Names in core's party JSON (DataGetCurrentPartyConf: name => member). */
    public static function partyNames($party): array
    {
        $data = is_string($party) ? json_decode($party, true) : $party;
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $key => $member) {
            $name = is_array($member) ? ($member['name'] ?? $key) : $key;
            if (is_string($name) && trim($name) !== '') $out[] = trim($name);
        }
        return $out;
    }

    /**
     * Participants named by the event text: [direct names (not the player), killer, victim].
     * "X is teamed up with <player>", "X has defeated Y", "X falls to the ground", "X has lost combat".
     */
    public static function parse(string $data, string $player): array
    {
        $direct = [];
        $killer = $victim = null;
        $text = preg_replace('/^(?:The Narrator:\s*)?(?:\([^)]*\))?\s*/', '', trim($data));
        if (preg_match('/^(.+?)\s+is teamed up with\s+' . preg_quote($player, '/') . '/i', $text, $m)) $direct[] = trim($m[1]);
        if (preg_match('/^(.+?)\s+has defeated\s+(.+?)(?:\s+with\s+.+?)?(?:\s+in an awesome move)?[\.\s]*$/i', $text, $m)) {
            $killer = trim($m[1]);
            $victim = trim($m[2]);
            if (strcasecmp($killer, $player) !== 0) $direct[] = $killer;
        }
        if (preg_match('/^(.+?)\s+falls to the ground/i', $text, $m) || preg_match('/^(.+?)\s+has lost combat/i', $text, $m)) {
            if (strcasecmp(trim($m[1]), $player) !== 0) $direct[] = trim($m[1]);
        }
        return [array_values(array_unique($direct)), $killer, $victim];
    }

    // =====================================================================
    // LIVE HEALTH (module doc)
    // =====================================================================

    /** health / health_max of a core stats report (gamedata.php buildStatsMetadataValue), 0..1; null = unknown. */
    public static function healthRatio($stats): ?float
    {
        if (!is_array($stats) || !is_numeric($stats['health'] ?? null) || !is_numeric($stats['health_max'] ?? null)) return null;
        $max = floatval($stats['health_max']);
        if ($max <= 0) return null;
        return max(0.0, min(1.0, floatval($stats['health']) / $max));
    }

    /** The NPC's latest reported HP ratio (core_npc_master.metadata.stats); null = unknown. */
    public static function npcHealth(string $npc): ?float
    {
        if (trim($npc) === '') return null;
        try {
            $core = RelationshipDynamics::fetchCoreProfileRow($npc);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('combat npc health', $e);
            return null;
        }
        return self::healthRatio(RelationshipDynamics::decodeProfileJson($core['metadata'] ?? null)['stats'] ?? null);
    }

    /** The player's latest reported HP ratio (core_player.stats); null = unknown. */
    public static function playerHealth(): ?float
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return null;
        try {
            $row = $db->fetchOne("SELECT value FROM core_player WHERE id = 'stats' LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('combat player health', $e);
            return null;
        }
        $raw = is_array($row) ? ($row['value'] ?? null) : null;
        if ($raw === null || $raw === '') return null;
        $stats = json_decode((string) $raw, true);
        if (!is_array($stats)) {
            error_log('[RelDyn] ERROR combat player health: core_player.stats is not JSON; treating it as unknown');
            return null;
        }
        return self::healthRatio($stats);
    }

    /** MDD 3.3: the HP ratio at or under which a fight is shared danger for an NPC with this combat preference. */
    public static function dangerThreshold(float $combatPreference, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        return max(0.0, floatval($cfg['danger_base']) - RelDynFacets::interestMultiplier($combatPreference) * floatval($cfg['danger_interest_slope']));
    }

    // =====================================================================
    // CONTEXT AT AN EVENT
    // =====================================================================

    /**
     * Did $npc fight at game time $at: its own combat bark, a kill of its own or its fall in the
     * eventlog within the kill-streak window before $at, or the plugin's activity status in
     * combat (core_npc_master.metadata.activity_status, reported at a game time in that window).
     */
    public static function fought(string $npc, float $at): bool
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $at <= 0 || trim($npc) === '') return false;
        $since = intval($at - RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS);
        $until = intval($at);
        $name = $db->escape(RelationshipDynamics::escapeLike(trim($npc)));
        try {
            $row = $db->fetchOne("SELECT 1 AS hit FROM eventlog WHERE gamets > {$since} AND gamets <= {$until} AND ("
                . "(type = 'infoaction' AND data LIKE '%({$name} shouts during combat)%' ESCAPE '\\') "
                . "OR (type = 'death' AND data LIKE '%{$name} has defeated%' ESCAPE '\\') "
                . "OR (type = 'bleedout' AND data LIKE '%{$name} falls to the ground%' ESCAPE '\\') "
                . "OR (type = 'instruction' AND data LIKE '%{$name} has lost combat%' ESCAPE '\\')) LIMIT 1");
            if (!empty($row['hit'])) return true;
            $core = RelationshipDynamics::fetchCoreProfileRow($npc);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('combat fought', $e);
            return false;
        }
        $status = RelationshipDynamics::decodeProfileJson($core['metadata'] ?? null)['activity_status'] ?? null;
        if (!is_array($status) || empty($status['is_in_combat'])) return false;
        $g = floatval($status['gamets'] ?? 0);
        return $g > $since && $g <= $at + RelationshipDynamics::COMBAT_ACTIVE_WINDOW_GAMETS;
    }

    /** Deaths near $npc (its name in the row's people) in the kill-streak window up to $at. */
    public static function recentKills(string $npc, float $at): int
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || $at <= 0) return 0;
        $since = intval($at - RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS);
        $until = intval($at);
        try {
            $name = $db->escape(RelationshipDynamics::escapeLike($npc));
            $rows = $db->fetchAll("SELECT COUNT(*) AS cnt FROM eventlog WHERE type = 'death' AND people LIKE '%{$name}%' ESCAPE '\\'"
                . " AND gamets > {$since} AND gamets <= {$until}");
            return intval($rows[0]['cnt'] ?? 0);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('combat recent kills', $e);
            return 0;
        }
    }

    // =====================================================================
    // ROUTING
    // =====================================================================

    /**
     * Route one combat event (module doc). $type: a core combat type ('instruction' bleedouts
     * are passed as 'bleedout'); $people / $party: who was around (CACHE_PEOPLE / CACHE_PARTY
     * for a request, the row's columns for an eventlog row); $at: the event's game time.
     *
     * @return array npc => ['gain' => passion change, 'witness' => bool, 'fought' => bool, 'kind' => 'fall'|'gain']
     */
    public static function route(string $type, string $data, float $at, $people, $party, string $player): array
    {
        $cfg = self::config();
        $nearby = array_values(array_filter(self::names($people), fn($n) => strcasecmp($n, $player) !== 0));
        [$direct, $killer, $victim] = self::parse($data, $player);
        $isRelDyn = fn(string $n) => !empty(RelationshipDynamics::getDynamics($n)['love_language_primary']);

        // Nobody named: the RelDyn NPCs around fought it (a combat end, a bark-less event). Not
        // for a kill (they are witnesses) nor a fall (only the fallen falls: the player's own
        // "Kaida falls to the ground" is nobody else's bleedout)
        if ($direct === [] && $type !== 'death' && $type !== 'bleedout') {
            foreach ($nearby as $n) if ($isRelDyn($n)) $direct[] = $n;
        }
        $witnesses = [];
        if ($type === 'death') {
            $candidates = array_unique(array_merge($nearby, self::partyNames($party)));
            $directLower = array_map('strtolower', $direct);
            foreach ($candidates as $w) {
                if (strcasecmp($w, $player) === 0 || in_array(strtolower($w), $directLower, true)) continue;
                if ($victim !== null && strcasecmp($w, $victim) === 0) continue;
                if ($isRelDyn($w)) $witnesses[] = $w;
            }
            if ($witnesses !== []) RelationshipDynamics::log('DEATH WITNESSES: ' . implode(', ', $witnesses) . ' (from people + party)');
        }

        $out = [];
        $witnessSet = array_map('strtolower', $witnesses);
        foreach (array_unique(array_merge($direct, $witnesses)) as $npc) {
            $dynamics = RelationshipDynamics::getDynamics($npc);
            if (empty($dynamics['love_language_primary'])) continue;
            $isWitness = in_array(strtolower($npc), $witnessSet, true);
            $fall = null;
            $fought = false;

            if ($type === 'bleedout') {
                // One fall, however many reports of it (bleedout + the RecoverFromCombat instruction)
                $last = floatval($dynamics['_combat_last_fall_gamets'] ?? 0);
                if ($at > 0 && $last > 0 && $at >= $last && $at - $last <= RelationshipDynamics::COMBAT_ACTIVE_WINDOW_GAMETS) continue;
                if ($at > 0) $dynamics['_combat_last_fall_gamets'] = $at;
                $fall = RelationshipDynamics::bleedoutResponse($dynamics, true);   // 0 inside the dead band
                $gain = $fall['passion'];
                RelationshipDynamics::log(sprintf('Bleedout: %s fight=%s fear=%s passion=%+.2f valence=%+.2f arousal=%+.2f',
                    $npc, $fall['fight'] === null ? 'n/a' : round($fall['fight'], 3), $fall['fear'] === null ? 'n/a' : round($fall['fear'], 3),
                    $gain, $fall['applied']['valence'], $fall['applied']['arousal']));
            } else {
                // Fighting together is an activity the NPC appraises (decisions §6)
                $prefs = RelDynFacets::preferences($dynamics, $npc);
                $appraisal = RelDynFacets::experienceThing($npc, $dynamics, 'activity', 'combat', $prefs, $at > 0 ? $at : RelationshipDynamics::currentGamets());
                $gain = RelationshipDynamics::calculatePassionGain($dynamics, RelationshipDynamics::LL_SERVICE, $appraisal);
                RelationshipDynamics::log(sprintf('Combat appraisal: %s valence=%+.3f dominant=%s',
                    $npc, floatval($appraisal['valence'] ?? 0), (string) ($appraisal['dominant'] ?? 'none')));
                if ($isWitness) $gain *= floatval($cfg['witness_mult']);

                $kills = self::recentKills($npc, $at);
                if ($kills > 1 && $gain > 0) {
                    $streak = min(floatval($cfg['streak_cap']), ($kills - 1) * floatval($cfg['streak_per_kill']));
                    $gain += $streak;
                    RelationshipDynamics::log("Kill streak bonus: +{$streak} ({$kills} kills)");
                }
                $fought = !$isWitness && (in_array($npc, $direct, true) && ($type !== 'combatend' && $type !== 'combatendmighty')
                    || self::fought($npc, $at));
                if ($fought && $gain > 0) {
                    // Shared danger: her live HP (an event of now only) under the MDD 3.3 threshold
                    $now = RelationshipDynamics::currentGamets();
                    $hp = ($at > 0 && $now > 0 && abs($now - $at) <= RelationshipDynamics::COMBAT_ACTIVE_WINDOW_GAMETS)
                        ? self::npcHealth($npc) : null;
                    $threshold = self::dangerThreshold(floatval($prefs['combat'] ?? 0.0), $cfg);
                    if ($hp !== null && $hp > 0 && $hp <= $threshold) {
                        $gain *= floatval($cfg['danger_mult']);
                        RelationshipDynamics::log(sprintf('Shared danger: %s HP=%.3f threshold=%.3f %sx', $npc, $hp, $threshold, $cfg['danger_mult']));
                    }
                    $gain *= floatval($cfg['shared_mult']);
                    RelationshipDynamics::log("Shared combat confirmed ({$npc} fought): " . $cfg['shared_mult'] . 'x');
                }
            }

            if (abs($gain) > 0.01) {
                if ($gain > 0) {
                    // x attraction (rulings §11): fighting beside someone she is not drawn to stirs nothing
                    $gain = RelationshipDynamics::gainPassion($npc, $dynamics, $gain, 'combat');
                    $dynamics['total_positive_interactions'] = intval($dynamics['total_positive_interactions'] ?? 0) + 1;
                } else {
                    // A drain (the fall of a fearful NPC): clamp at zero
                    RelationshipDynamics::setPassion($dynamics, max(0, RelationshipDynamics::getPassion($dynamics) + $gain));
                    $dynamics['passion_updated_at'] = RelationshipDynamics::getPlayGamets($dynamics);
                }
                $dynamics['interaction_count'] = intval($dynamics['interaction_count'] ?? 0) + 1;
                $dynamics['last_interaction_at'] = RelationshipDynamics::getPlayGamets($dynamics);
                $dynamics['passion_sources']['combat'] = floatval($dynamics['passion_sources']['combat'] ?? 0) + $gain;
                RelationshipDynamics::saveDynamics($npc, $dynamics);
                RelationshipDynamics::log("COMBAT EVENT: {$npc} type={$type} gain=" . round($gain, 2) . ' passion='
                    . round(RelationshipDynamics::getPassion($dynamics), 2) . ($fought ? ' [FOUGHT]' : '') . ($isWitness ? ' [WITNESS]' : ''));
            } elseif ($fall !== null) {
                // Inside the dead band the fall moves no passion; its arousal spike and valence stay
                RelationshipDynamics::saveDynamics($npc, $dynamics);
                RelationshipDynamics::log("COMBAT EVENT: {$npc} type={$type} passion unchanged (dead band); valence="
                    . round($fall['applied']['valence'], 2) . ' arousal=' . round($fall['applied']['arousal'], 2));
            }
            $out[$npc] = ['gain' => round($gain, 4), 'witness' => $isWitness, 'fought' => $fought, 'kind' => $fall !== null ? 'fall' : 'gain'];
        }

        if ($type === 'death' && $victim !== null && !empty(RelationshipDynamics::configValue('grief_system_enabled') ?? true)) {
            self::grief($victim, $nearby);
        }
        return $out;
    }

    /** Grief (PR 10): a RelDyn NPC nearby with a bond (> 30 on the 0..100 scale) to the deceased. */
    private static function grief(string $deceased, array $nearby): void
    {
        foreach ($nearby as $witness) {
            if (strcasecmp($witness, $deceased) === 0) continue;
            $bonds = RelationshipDynamics::getAllBondsForNpc($witness);
            if (!isset($bonds[$deceased])) continue;
            if ((($bonds[$deceased]['aff'] + 100) / 2.0) <= 30) continue;
            $wd = RelationshipDynamics::getDynamics($witness);
            RelationshipDynamics::onNpcDeath($deceased, $witness, $wd);
            RelationshipDynamics::saveDynamics($witness, $wd);
        }
    }

    // =====================================================================
    // EVENTLOG CONSUMER
    // =====================================================================

    /**
     * Route core's death / bleedout rows logged since the last call (module doc). The first call
     * anchors at the newest row (history is not replayed); an eventlog rebuilt below the
     * watermark re-anchors. Returns the number of rows routed (0 when another request claimed them).
     */
    public static function consumeEventlog(string $player): int
    {
        $db = $GLOBALS['db'] ?? null;
        $cfg = self::config();
        if (!$db || empty($cfg['consume_eventlog']) || empty(RelationshipDynamics::configValue('combat_enabled') ?? true)) return 0;
        try {
            $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::WATERMARK_ROW_ID]);
            $raw = is_array($row) && isset($row['value']) ? (string) $row['value'] : null;
            $mark = $raw !== null ? json_decode($raw, true) : null;
            if ($raw !== null && !is_numeric($mark['rowid'] ?? null)) {
                error_log('[RelDyn] ERROR combat watermark: conf_opts ' . self::WATERMARK_ROW_ID . ' unreadable; re-anchoring');
            }
            $top = $db->fetchOne('SELECT rowid FROM eventlog ORDER BY rowid DESC LIMIT 1');
            $newest = intval($top['rowid'] ?? 0);
            $from = is_numeric($mark['rowid'] ?? null) ? intval($mark['rowid']) : null;
            if ($from === null || $newest < $from) {
                self::claim($raw, $newest);
                return 0;
            }
            if ($newest === $from) return 0;
            $limit = max(1, intval($cfg['batch']));
            $rows = $db->fetchAll('SELECT rowid, type, data, gamets, people, party FROM eventlog WHERE rowid > ' . $from
                . ' AND rowid <= ' . $newest . " AND (type IN ('death', 'bleedout')"
                . " OR (type = 'instruction' AND data LIKE '%has lost combat and is wounded bleedingout%'))"
                . ' ORDER BY rowid LIMIT ' . $limit);
            $rows = is_array($rows) ? $rows : [];
            $to = count($rows) >= $limit ? intval(end($rows)['rowid']) : $newest;
            if (!self::claim($raw, $to)) return 0;   // another request routes them
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('combat eventlog consumer', $e);
            return 0;
        }
        foreach ($rows as $r) {
            $type = (string) $r['type'] === 'instruction' ? 'bleedout' : (string) $r['type'];
            try {
                self::route($type, (string) ($r['data'] ?? ''), floatval($r['gamets'] ?? 0), (string) ($r['people'] ?? ''), $r['party'] ?? null, $player);
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("combat route eventlog row {$r['rowid']}", $e);
            }
        }
        return count($rows);
    }

    /** Compare-and-set the watermark to $rowid; false when another request moved it first. */
    private static function claim(?string $raw, int $rowid): bool
    {
        $db = $GLOBALS['db'];
        $value = json_encode(['rowid' => $rowid]);
        $won = $raw === null
            ? $db->fetchOne('INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO NOTHING RETURNING id', [self::WATERMARK_ROW_ID, $value])
            : $db->fetchOne('UPDATE conf_opts SET value = $2 WHERE id = $1 AND value = $3 RETURNING id', [self::WATERMARK_ROW_ID, $value, $raw]);
        return isset($won['id']);
    }
}
