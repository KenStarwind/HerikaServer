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
 *   gain    shared combat appraised as an activity (facets), x witness_mult, x the enemy's
 *           threat (MDD 3.3 Stage 2: combatendmighty 2.0x, a weak victim 0.5x; threatClass), + kill streak
 *           (streak_per_kill per extra kill in the 5-play-minute window, cap streak_cap),
 *           x danger_mult when the NPC's live HP is at or under the MDD 3.3 threshold
 *           (danger_base - interestMultiplier(combat preference) x danger_interest_slope: the
 *           more she loves a fight, the nearer death it takes to feel it as danger), x shared_mult
 *           only when THIS NPC fought (fought()): its own combat
 *           bark, a kill of its own, its fall, the plugin's activity status in combat, or being
 *           the named participant. A bystander at a combat event is no longer "confirmed".
 *   grief   a death: RelDyn NPCs around (people + party) with a bond to the deceased
 *           (RelDynProtocols::onDeath -> RelationshipDynamics::onNpcDeath).
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
            // MDD 3.3 Stage 2, enemy threat scaling: a kill's passion x the enemy's threat class
            // (unitless). combatendmighty (the plugin's mighty-foe combat end) is 'mighty'; a
            // death is classed by its victim (threatClass); every other event is 'regular'.
            'threat_mult' => ['weak' => 0.5, 'regular' => 1.0, 'mighty' => 2.0],
            // Victim-name keywords (whole words, case-insensitive), 'weak' checked first (a Giant
            // Frostbite Spider is a spider). MDD: "death of weak enemies (animals, bandits) 0.5x";
            // "a Dwarven Centurion kill is a shared survival moment". Mammoths are the animal the
            // MDD's "A skeever kill is nothing" does not describe: mighty.
            'threat_keywords' => [
                'weak' => ['skeever', 'mudcrab', 'rabbit', 'hare', 'deer', 'elk', 'fox', 'goat', 'cow', 'chicken',
                           'dog', 'wolf', 'bear', 'sabre cat', 'horker', 'slaughterfish', 'spider', 'chaurus', 'bandit'],
                'mighty' => ['dragon', 'dragon priest', 'centurion', 'giant', 'mammoth', 'lich', 'vampire lord',
                             'ebony warrior', 'karstaag', 'miraak', 'alduin', 'harkon'],
            ],
            'rescue' => self::rescueDefaults(),
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
        // AIAgent 3.4.1's death rows: "X has defeated Y", "... with W", "... using W", "... using
        // weapon W", "... in an awesome move" (the weapon is not the victim: a Dragonbone Sword
        // is no dragon)
        if (preg_match('/^(.+?)\s+has defeated\s+(.+?)(?:\s+(?:with|using(?:\s+weapon)?)\s+.+?)?(?:\s+in an awesome move)?[\.\s]*$/i', $text, $m)) {
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
    // ENEMY THREAT (MDD 3.3 Stage 2, roadmap enemy-threat-scaling)
    // =====================================================================

    /**
     * The threat class of a combat event: 'mighty' for combatendmighty; for a death, the class
     * of its victim's name (config threat_keywords, whole words, 'weak' first, then 'mighty');
     * 'regular' otherwise (combatend, radiantcombatfriend, a victim no list names). Pure.
     */
    public static function threatClass(string $type, ?string $victim, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        if ($type === 'combatendmighty') return 'mighty';
        if ($type !== 'death' || $victim === null || trim($victim) === '') return 'regular';
        $lists = (array) ($cfg['threat_keywords'] ?? []);
        foreach (['weak', 'mighty'] as $class) {
            foreach ((array) ($lists[$class] ?? []) as $word) {
                $word = trim((string) $word);
                if ($word !== '' && preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $victim)) return $class;
            }
        }
        return 'regular';
    }

    /** The passion multiplier of a threat class (config threat_mult, unitless); 1.0 for a class it does not list. */
    public static function threatMult(string $class, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::config();
        $m = ((array) ($cfg['threat_mult'] ?? []))[$class] ?? null;
        return is_numeric($m) ? max(0.0, floatval($m)) : 1.0;
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
            $threat = null;

            if ($type === 'bleedout') {
                // One fall, however many reports of it (bleedout + the RecoverFromCombat instruction)
                $last = floatval($dynamics['_combat_last_fall_gamets'] ?? 0);
                if ($at > 0 && $last > 0 && $at >= $last && $at - $last <= RelationshipDynamics::COMBAT_ACTIVE_WINDOW_GAMETS) continue;
                if ($at > 0) $dynamics['_combat_last_fall_gamets'] = $at;
                $fall = RelationshipDynamics::bleedoutResponse($dynamics, true);   // 0 inside the dead band
                $gain = $fall['passion'];
                // MDD 3.3 rescue response: the player's next exchange with her answers this fall
                self::noteFall($dynamics, $at);
                RelationshipDynamics::log(sprintf('Bleedout: %s fight=%s fear=%s passion=%+.2f valence=%+.2f arousal=%+.2f',
                    $npc, $fall['fight'] === null ? 'n/a' : round($fall['fight'], 3), $fall['fear'] === null ? 'n/a' : round($fall['fear'], 3),
                    $gain, $fall['applied']['valence'], $fall['applied']['arousal']));
            } else {
                // Fighting together is an activity the NPC appraises (decisions §6); a fight she was
                // in beside the player is shared with the player pair, one she only saw is hers alone
                $prefs = RelDynFacets::preferences($dynamics, $npc);
                $appraisal = RelDynFacets::experienceThing($npc, $dynamics, 'activity', 'combat', $prefs, $at > 0 ? $at : RelationshipDynamics::currentGamets(),
                    null, $isWitness ? null : RelDynFulfillment::PLAYER);
                $gain = RelationshipDynamics::calculatePassionGain($dynamics, RelationshipDynamics::LL_SERVICE, $appraisal);
                RelationshipDynamics::log(sprintf('Combat appraisal: %s valence=%+.3f dominant=%s',
                    $npc, floatval($appraisal['valence'] ?? 0), (string) ($appraisal['dominant'] ?? 'none')));
                if ($isWitness) $gain *= floatval($cfg['witness_mult']);
                // Enemy threat (MDD 3.3 Stage 2): a skeever is nothing, a centurion a shared
                // survival moment
                $threat = self::threatClass($type, $victim, $cfg);
                $threatMult = self::threatMult($threat, $cfg);
                if (abs($threatMult - 1.0) > 1e-9) {
                    $gain *= $threatMult;
                    RelationshipDynamics::log(sprintf('Enemy threat: %s %s (%s) x%.2f', $npc, $threat, $victim ?? $type, $threatMult));
                }

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
            $out[$npc] = ['gain' => round($gain, 4), 'witness' => $isWitness, 'fought' => $fought, 'kind' => $fall !== null ? 'fall' : 'gain',
                'threat' => $threat];
        }

        if ($type === 'death' && $victim !== null && !empty(RelationshipDynamics::configValue('grief_system_enabled') ?? true)) {
            // Grief (PR 10, reldyn_protocols.php): the RelDyn NPCs around (people and party) with
            // a bond to the deceased, at the death's game time
            RelDynProtocols::onDeath($victim, array_merge($nearby, self::partyNames($party)), $at, $player);
        }
        return $out;
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

    // =====================================================================
    // RESCUE RESPONSE (MDD 3.3 "Player Rescue Response", roadmap rescue-bonus)
    // =====================================================================

    /** dynamics key: her fall waiting for the player's next exchange ['fall_gamets', 'claimed_gamets' (null = none yet)]. */
    const RESCUE_PENDING_KEY = '_rescue_pending';
    /** dynamics key: the last fall the player answered with care ['gamets', 'bonus', 'gain', 'felt', 'via'] (felt window, Jev). */
    const RESCUE_LAST_KEY = '_rescue_last';
    /** Attachment corners of the MDD table (RelationshipDynamics::attachmentWeights keys). */
    const RESCUE_CORNERS = ['secure', 'anxious', 'avoidant', 'toxic'];

    /**
     * Config combat.rescue. MDD 3.3: "If the player's next interaction after NPC bleedout is
     * caring/protective, a rescue_response passion bonus fires. Scaled by attachment style":
     * Scholar / Guarded +4.0 (walls crack), Warrior / Bold +1.0 (a nod of respect), Anxious +5.0
     * (massive bonding, creates dependency), Avoidant +2.0 (grateful but ashamed), Independent
     * +1.5 (respected, will not admit need). Decisions §12 split the table's two kinds of rows:
     * Anxious / Avoidant are attachment (the axes' corners), Guarded / Bold / Independent are
     * temperament (the trait vector). bonus = sum over the corners of weight x value, the secure
     * corner's value read from the traits (secureBonus). Units: passion points; minutes of real
     * play on the eventlog game clock; axis units for the dependency event (attachment drift).
     */
    public static function rescueDefaults(): array
    {
        return [
            'enabled' => true,
            // "The player's next interaction after NPC bleedout": the first exchange with her after
            // her fall, when it comes within this many minutes of real play; after that the moment
            // has passed and the fall waits for nothing
            'window_play_minutes' => 10.0,
            // Caring / protective: an eval item that is a positive interaction carrying one of these
            // tags (the eval contract's: rescue = "saved or protected them from danger")
            'caring_tags' => ['rescue', 'help', 'reassurance', 'touch'],
            // ... or, for an exchange the eval does not score: the mood she answered in (core's
            // moods_issued) says she took it as care, or the local classifier read one of these
            // love languages (a hug or a kiss). Not acts of service: the classifier reads any
            // line right after a fight as service, whatever was said.
            'caring_moods' => ['grateful', 'relieved', 'safe', 'protective', 'loving', 'affectionate'],
            'caring_love_languages' => [RelationshipDynamics::LL_TOUCH],
            // MDD 3.3 attachment rows (passion points) at the corners of the axes. The fearful
            // corner is RelDyn's: the MDD names none; wanting and fearing closeness at once is
            // both rows, their mean.
            'bonus' => ['anxious' => 5.0, 'avoidant' => 2.0, 'toxic' => 3.5],
            // MDD 3.3 temperament rows (passion points) at their presets: the secure corner is a
            // linear model on secure_traits through these points (walls crack with guard G; one
            // who is sure of themself, C, does not need saving), exact at each preset
            'secure_anchors' => ['Guarded' => 4.0, 'Bold' => 1.0, 'Independent' => 1.5],
            'secure_traits' => ['G', 'C'],
            'secure_range' => [0.0, 5.0],
            // The secure value of an NPC without a trait vector (a label that is no preset)
            'no_vector_secure' => 2.0,
            // "Creates dependency pattern": config attachment.drift.events row, x the NPC's anxious
            // lean (the anxious + fearful corner weights)
            'dependency_event' => 'rescue_dependency',
            // The felt read after a rescue lasts this many minutes of real play
            'felt_play_minutes' => 5.0,
            // Felt text per response (decisions §3: behaviour, never numbers); the secure corner
            // speaks as the temperament row nearest her trait vector (secure_felt)
            'secure_felt' => ['Guarded' => 'walls', 'Bold' => 'nod', 'Independent' => 'grudging'],
            'felt_text' => [
                'walls'    => "{PLAYER} pulled {NAME} back from the edge and stayed; something in {NAME}'s guard has cracked, softer with {PLAYER} and letting them closer than before",
                'nod'      => "{NAME} gives {PLAYER} a short nod of respect for the save; glad of it, not about to make a fuss",
                'grudging' => "{NAME} knows {PLAYER} saved them and will not say so; gruff, brushes it off, but stays a little closer to {PLAYER}",
                'cling'    => "{PLAYER} saved {NAME} and {NAME} cannot let go of it; clings close, keeps checking {PLAYER} is still there, afraid to be left",
                'ashamed'  => "grateful to {PLAYER} for the rescue and ashamed of having needed it; {NAME} avoids {PLAYER}'s eyes and puts a little distance back between them",
                'torn'     => "{NAME} wants to hold on to {PLAYER} after the rescue and hates needing to; warm one moment, pulling away the next",
            ],
        ];
    }

    /** combat.rescue over its defaults (per key). */
    public static function rescueConfig(): array
    {
        $stored = self::config()['rescue'] ?? null;
        return is_array($stored) ? array_replace(self::rescueDefaults(), $stored) : self::rescueDefaults();
    }

    /** Minutes of real play as eventlog gamets. */
    private static function playMinutesGamets(float $minutes): float
    {
        return max(0.0, $minutes) * 60.0 * RelationshipDynamics::GAMETS_PER_REAL_SECOND;
    }

    /**
     * The linear model of the secure corner: ['base' => b, trait => coefficient] through the
     * secure_anchors preset points on secure_traits (one more anchor than traits), or null
     * (logged) when the config cannot define it. Pure.
     */
    public static function secureModel(array $cfg): ?array
    {
        $traits = array_values(array_map('strval', (array) ($cfg['secure_traits'] ?? [])));
        $anchors = (array) ($cfg['secure_anchors'] ?? []);
        $n = count($traits) + 1;
        if (count($anchors) !== $n) {
            error_log('[RelDyn] ERROR combat.rescue: ' . count($anchors) . ' secure_anchors for ' . count($traits) . ' secure_traits (needs one more anchor than traits)');
            return null;
        }
        $rows = [];
        foreach ($anchors as $preset => $value) {
            $p = RelDynTraits::presetPoint((string) $preset);
            if ($p === null || !is_numeric($value)) {
                error_log("[RelDyn] ERROR combat.rescue: secure anchor '{$preset}' is not a preset with a number");
                return null;
            }
            $row = [1.0];
            foreach ($traits as $t) $row[] = floatval($p[$t] ?? 0.5);
            $row[] = floatval($value);
            $rows[] = $row;
        }
        // Gauss-Jordan elimination with partial pivoting on the n x (n + 1) system
        for ($c = 0; $c < $n; $c++) {
            $pivot = $c;
            for ($r = $c + 1; $r < $n; $r++) if (abs($rows[$r][$c]) > abs($rows[$pivot][$c])) $pivot = $r;
            if (abs($rows[$pivot][$c]) < 1e-12) {
                error_log('[RelDyn] ERROR combat.rescue: the secure anchors do not define a model on ' . implode(', ', $traits));
                return null;
            }
            [$rows[$c], $rows[$pivot]] = [$rows[$pivot], $rows[$c]];
            for ($r = 0; $r < $n; $r++) {
                if ($r === $c) continue;
                $f = $rows[$r][$c] / $rows[$c][$c];
                for ($k = $c; $k <= $n; $k++) $rows[$r][$k] -= $f * $rows[$c][$k];
            }
        }
        $model = ['base' => $rows[0][$n] / $rows[0][0]];
        foreach ($traits as $i => $t) $model[$t] = $rows[$i + 1][$n] / $rows[$i + 1][$i + 1];
        return $model;
    }

    /** The secure corner's bonus (passion points) at trait vector $x (null: no vector), within secure_range. */
    public static function secureBonus(?array $x, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::rescueConfig();
        $range = array_values((array) ($cfg['secure_range'] ?? [0.0, 5.0]));
        $clamp = fn(float $v) => max(floatval($range[0] ?? 0.0), min(floatval($range[1] ?? 5.0), $v));
        $model = $x !== null ? self::secureModel($cfg) : null;
        if ($model === null) return $clamp(floatval($cfg['no_vector_secure'] ?? 2.0));
        $v = $model['base'];
        foreach ($model as $t => $k) if ($t !== 'base') $v += $k * floatval($x[$t] ?? 0.5);
        return $clamp($v);
    }

    /**
     * Who this NPC is when the player answers her fall with care (pure): the bonus (passion
     * points) = sum over the attachment corners of weight x value (secure: secureBonus of her
     * traits), the corner that speaks (the largest share; the secure one as the temperament row
     * nearest her trait vector) and the anxious lean (anxious + fearful weight) for the dependency.
     *
     * @return array ['bonus', 'weights' => corner => 0..1, 'secure' => points, 'felt' => text key, 'anxious_lean' => 0..1]
     */
    public static function rescueResponse(array $dynamics, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::rescueConfig();
        $w = RelationshipDynamics::attachmentWeights($dynamics);
        $x = RelDynTraits::vectorFor($dynamics['inferred_temperament'] ?? null, $dynamics);
        $secure = self::secureBonus($x, $cfg);
        $values = ['secure' => $secure] + array_map('floatval', (array) ($cfg['bonus'] ?? []));
        $bonus = 0.0;
        $share = [];
        foreach (self::RESCUE_CORNERS as $corner) {
            $share[$corner] = floatval($w[$corner] ?? 0.0) * floatval($values[$corner] ?? 0.0);
            $bonus += $share[$corner];
        }
        arsort($share);
        $top = (string) array_key_first($share);
        if ($top === 'secure') {
            // the temperament row she is nearest in trait space (without a vector: by value)
            $anchors = (array) ($cfg['secure_anchors'] ?? []);
            $nearest = null;
            $best = INF;
            foreach ($anchors as $preset => $v) {
                $p = $x !== null ? RelDynTraits::presetPoint((string) $preset) : null;
                $dist = $p !== null ? RelDynTraits::distance($x, $p) : abs($secure - floatval($v));
                if ($dist < $best) [$best, $nearest] = [$dist, (string) $preset];
            }
            $felt = (string) (((array) ($cfg['secure_felt'] ?? []))[$nearest] ?? 'nod');
        } else {
            $felt = ['anxious' => 'cling', 'avoidant' => 'ashamed', 'toxic' => 'torn'][$top];
        }
        return ['bonus' => round($bonus, 4), 'weights' => array_map(fn($v) => round($v, 4), $w), 'secure' => round($secure, 4),
                'felt' => $felt, 'anxious_lean' => round(floatval($w['anxious'] ?? 0.0) + floatval($w['toxic'] ?? 0.0), 4)];
    }

    /** Her fall at game time $at (route: bleedout) waits for the player's next exchange. */
    public static function noteFall(array &$dynamics, float $at): void
    {
        if ($at <= 0 || empty(self::rescueConfig()['enabled'])) return;
        $dynamics[self::RESCUE_PENDING_KEY] = ['fall_gamets' => $at, 'claimed_gamets' => null];
    }

    /**
     * The pending fall, or null: none, or the moment passed by game time $at (the pending entry
     * is dropped then and $changed set).
     */
    private static function pendingFall(string $npc, array &$dynamics, float $at, array $cfg, bool &$changed): ?array
    {
        $p = $dynamics[self::RESCUE_PENDING_KEY] ?? null;
        if (!is_array($p) || !is_numeric($p['fall_gamets'] ?? null)) return null;
        $fall = floatval($p['fall_gamets']);
        if ($at > 0 && $at - $fall > self::playMinutesGamets(floatval($cfg['window_play_minutes']))) {
            unset($dynamics[self::RESCUE_PENDING_KEY]);
            $changed = true;
            RelationshipDynamics::log("[RESCUE] {$npc}: the moment after the fall has passed (no exchange within the window)");
            return null;
        }
        return $p + ['claimed_gamets' => null];
    }

    /**
     * postrequest, a player exchange with $npc at game time $at (the exchange's gamets): the
     * first one after her fall is the player's response. Scored by the eval: claimed, its item
     * decides (onEvalItem). Not scored: caring when she answered in one of caring_moods ($mood,
     * core's moods_issued) or the local classifier read one of caring_love_languages. Returns
     * the resolution (resolve) or null; $changed when state moved.
     */
    public static function onExchange(string $npc, array &$dynamics, bool $evalOwns, ?string $loveLanguage, ?string $mood, float $at, bool &$changed = false): ?array
    {
        $cfg = self::rescueConfig();
        if (empty($cfg['enabled'])) return null;
        $p = self::pendingFall($npc, $dynamics, $at, $cfg, $changed);
        if ($p === null || ($at > 0 && $at < floatval($p['fall_gamets'])) || $p['claimed_gamets'] !== null) return null;
        if ($evalOwns) {
            $dynamics[self::RESCUE_PENDING_KEY]['claimed_gamets'] = $at;
            $changed = true;
            RelationshipDynamics::log("[RESCUE] {$npc}: the player's first exchange after the fall goes to the eval (gamets {$at})");
            return null;
        }
        $mood = $mood !== null ? strtolower(trim($mood)) : null;
        $caring = ($mood !== null && in_array($mood, array_map('strtolower', (array) $cfg['caring_moods']), true))
            || ($loveLanguage !== null && in_array($loveLanguage, (array) $cfg['caring_love_languages'], true));
        $changed = true;
        return self::resolve($npc, $dynamics, $caring, RelDynAttraction::loveLanguageChannelTags($loveLanguage), $at,
            'local:' . ($loveLanguage ?? 'none') . '/' . ($mood ?? 'no mood'), $cfg);
    }

    /**
     * processEvalContractItem, one applied item ($n normalized): the item of the player's first
     * exchange after her fall (the claimed one; or, unclaimed, the first item after the fall
     * within the window) decides: caring = a positive interaction tagged with a caring tag.
     */
    public static function onEvalItem(string $npc, array $n, array &$dynamics): ?array
    {
        $cfg = self::rescueConfig();
        if (empty($cfg['enabled'])) return null;
        $at = floatval($n['gamets'] ?? 0);
        if ($at <= 0) return null;
        $changed = false;
        $p = self::pendingFall($npc, $dynamics, $at, $cfg, $changed);
        if ($p === null || $at < floatval($p['fall_gamets'])) return null;
        if ($p['claimed_gamets'] !== null && abs($at - floatval($p['claimed_gamets'])) >= 1.0) return null;
        $tags = array_values(array_map('strval', (array) ($n['tags'] ?? [])));
        $caring = !empty($n['positive_interaction']) && array_intersect($tags, array_map('strval', (array) $cfg['caring_tags'])) !== [];
        return self::resolve($npc, $dynamics, $caring, $tags, $at, 'eval:' . ($tags ? implode(',', $tags) : 'none'), $cfg);
    }

    /**
     * The player's response to her fall is in: the pending fall is consumed either way; a caring
     * one gives the rescue bonus (gainPassion 'rescue': the attraction, the tier's governor), the
     * dependency for an anxious lean, and the felt read. Returns ['caring', 'bonus', 'gain', 'felt'].
     */
    private static function resolve(string $npc, array &$dynamics, bool $caring, array $tags, float $at, string $via, array $cfg): array
    {
        unset($dynamics[self::RESCUE_PENDING_KEY]);
        if (!$caring) {
            RelationshipDynamics::log("[RESCUE] {$npc}: the first exchange after the fall was not caring ({$via}); no rescue response");
            return ['caring' => false, 'bonus' => 0.0, 'gain' => 0.0, 'felt' => null];
        }
        $r = self::rescueResponse($dynamics, $cfg);
        $gain = RelationshipDynamics::gainPassion($npc, $dynamics, $r['bonus'], 'rescue', $tags);
        $when = $at > 0 ? $at : RelationshipDynamics::currentGamets();
        $event = trim((string) ($cfg['dependency_event'] ?? ''));
        if ($event !== '' && $r['anxious_lean'] > 0.0) {
            RelationshipDynamics::attachmentExperience($dynamics, $event, $when, $r['anxious_lean'], $npc);
        }
        $dynamics[self::RESCUE_LAST_KEY] = ['gamets' => $when, 'bonus' => $r['bonus'], 'gain' => round($gain, 4), 'felt' => $r['felt'], 'via' => $via];
        RelationshipDynamics::log(sprintf('[RESCUE] %s: caring response (%s) bonus=%.2f gain=%.2f secure=%.2f felt=%s weights=%s',
            $npc, $via, $r['bonus'], $gain, $r['secure'], $r['felt'], json_encode($r['weights'])));
        return ['caring' => true, 'bonus' => $r['bonus'], 'gain' => $gain, 'felt' => $r['felt']];
    }

    /** The felt read of a rescue answered with care, for felt_play_minutes after it (RelDynFelt::compose), or null. */
    public static function rescueFeltText(array $dynamics, array $vars, float $now): ?string
    {
        $cfg = self::rescueConfig();
        $last = $dynamics[self::RESCUE_LAST_KEY] ?? null;
        if (empty($cfg['enabled']) || !is_array($last) || $now <= 0) return null;
        $at = floatval($last['gamets'] ?? 0);
        if ($at <= 0 || $now < $at || $now - $at > self::playMinutesGamets(floatval($cfg['felt_play_minutes']))) return null;
        $text = ((array) ($cfg['felt_text'] ?? []))[(string) ($last['felt'] ?? '')] ?? null;
        return is_string($text) && trim($text) !== '' ? strtr($text, $vars) : null;
    }

    /** Jev's rescue block (numbers): null when she has neither a fall waiting nor a rescue behind her. */
    public static function jev(array $dynamics): ?array
    {
        $pending = is_array($dynamics[self::RESCUE_PENDING_KEY] ?? null);
        $last = is_array($dynamics[self::RESCUE_LAST_KEY] ?? null) ? $dynamics[self::RESCUE_LAST_KEY] : null;
        if (!$pending && $last === null) return null;
        return ['pending' => $pending, 'last_bonus' => $last !== null ? round(floatval($last['bonus'] ?? 0), 2) : null,
                'last_gamets' => $last !== null ? floatval($last['gamets'] ?? 0) : null];
    }
}
