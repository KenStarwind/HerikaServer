<?php
/**
 * Relationship Dynamics — Quests: the duty override and the quest event hook, from CHIM core's
 * own quest data.
 *
 * Core data (CHIM 3.4.1, processor/comm.php; plugin Misc.cpp ProcedureSendActiveQuests and the
 * TESQuestStageEvent sink in Plugin.cpp):
 *   quests    the player's journal: active quests with an objective DISPLAYED ('_quest'),
 *             id_quest = the quest's editor id (C00, MG01...), name, stage, briefing = the displayed
 *             objectives with their alias tags resolved (names), data = JSON of currentbrief2:
 *             alias name => the resolved name (e.g. "QuestGiver" => "Aela the Huntress") and the
 *             current objective. Replaced on every journal push.
 *   questlog  one row per quest stage change ('_uquest'): id_quest (editor id), stage, briefing =
 *             the objective's raw text (alias tags unresolved). Quests the engine runs in the
 *             background log stages here too; only a quest in the journal names anyone.
 * Both are fast commands (comm.php ends the request before any ext hook), so the stage changes
 * are read here with a watermark on questlog.rowid, on the next prerequest (the combat rows'
 * pattern).
 *
 * duty-override (MDD 9, dimension draft "Duty Override"): when the game makes the player deal
 * with an NPC who is hostile to them (autonomy refusing / walking away, a hostile core type, or
 * core affinity in the hostile tier) because an active journal quest names her, she does the
 * quest's business coldly and professionally: the felt 'duty' line replaces her refusal, and the
 * negative signals of that exchange's eval land at duty_dampen (quest-scripted friction is not
 * held against the bond). Her refusal still takes the follow / trade / give actions off.
 * conf_opts '_duty_override_active' ({"active": true, "dampening": 0.1}) stays as an explicit
 * game-side override.
 *
 * quest-event-hook: each journal quest's stage change reaches
 * RelationshipDynamics::onQuestEvent for every RelDyn NPC the quest names (its aliases).
 *
 * Config 'quests' (configDefaults) over its defaults.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynQuests
{
    /** conf_opts row of the questlog watermark (last routed questlog.rowid). */
    const WATERMARK_ROW_ID = 'reldyn_questlog_watermark';

    /** Explicit game-side duty flag (conf_opts; no writer in CHIM 3.4.1). */
    const DUTY_FLAG_ROW_ID = '_duty_override_active';

    /** Dynamics key: the duty override of the NPC's current request (null when none). */
    const DUTY_KEY = '_duty';

    /** Dynamics key: recent quest stage events that named the NPC. */
    const EVENTS_KEY = '_quest_events';

    /**
     * Defaults for config 'quests'.
     *   consume_questlog         route questlog stage changes to onQuestEvent (prerequest)
     *   batch                    questlog rows routed per request (the rest on the next ones)
     *   journal_rows             journal (quests) rows read per lookup, newest first
     *   events_keep              quest events kept per NPC (EVENTS_KEY)
     *   duty_dampen              0..1 factor on the NPC's NEGATIVE eval signals of a duty exchange
     *                            (1 = no dampening, 0 = paused)
     *   duty_hostile_states      evaluateAutonomyState states that count as hostile
     *   duty_hostile_core_types  core relationships.Player.type values that count as hostile
     *   duty_hostile_affinity_max  core affinity (-100..100) at or below which she is hostile
     *                            (-6: the top of RelDyn's 'hostile' tier, RELATIONSHIP_TIERS)
     *   life_changing            quest stages that are life-changing for an NPC (divine
     *                            intervention, severity 1..5): list of ['quest' => editor id,
     *                            'stage_min' => stage, 'npcs' => [names] (empty: every NPC the
     *                            quest names), 'severity' => int]. Empty until Ken picks them.
     *   goal_progress            intrinsic goal progress (0..1) per quest stage whose name or
     *                            objective matches the goal's keywords
     */
    public static function configDefaults(): array
    {
        return [
            'consume_questlog' => true,
            'batch' => 50,
            'journal_rows' => 200,
            'events_keep' => 10,
            'duty_dampen' => 0.1,
            'duty_hostile_states' => ['refusing', 'walkaway'],
            'duty_hostile_core_types' => ['enemy', 'nemesis', 'estranged', 'ex'],
            'duty_hostile_affinity_max' => -6,
            'life_changing' => [],
            'goal_progress' => 0.25,
        ];
    }

    public static function config(): array
    {
        $stored = RelationshipDynamics::configValue('quests');
        return array_replace(self::configDefaults(), is_array($stored) ? $stored : []);
    }

    // =====================================================================
    // THE JOURNAL: which active quests name an NPC
    // =====================================================================

    /** True when $text names $name as a whole name (not "Ashe" inside "ashes"). */
    public static function names(string $text, string $name): bool
    {
        $name = trim($name);
        if ($name === '' || $text === '') return false;
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $text);
    }

    /**
     * Names a journal row resolves (its alias map, core's currentbrief2): the string values of
     * the data JSON. [] when the data is not a JSON object.
     */
    public static function aliasNames(?string $data): array
    {
        $map = is_string($data) && $data !== '' ? json_decode($data, true) : null;
        if (!is_array($map)) return [];
        $out = [];
        foreach ($map as $v) {
            if (is_string($v) && trim($v) !== '') $out[] = trim($v);
        }
        return array_values(array_unique($out));
    }

    /**
     * Active journal quests (core quests) that name $npcName in their resolved aliases or their
     * displayed objective: list of ['id' => editor id, 'name' => quest name, 'stage' => int].
     * A failed read is logged and reads as none.
     */
    public static function questsNaming(string $npcName): array
    {
        $db = $GLOBALS['db'] ?? null;
        $npcName = trim($npcName);
        // a database without core's journal table (not CHIM 3.4.1 core): no quest names anyone
        if (!$db || $npcName === '' || !RelDynTraitRead::tableExists('quests', $db)) return [];
        $like = $db->escape(RelationshipDynamics::escapeLike($npcName));
        $limit = max(1, intval(self::config()['journal_rows']));
        try {
            $rows = $db->fetchAll("SELECT id_quest, name, stage, briefing, data FROM quests "
                . "WHERE (briefing ILIKE '%{$like}%' ESCAPE '\\' OR data ILIKE '%{$like}%' ESCAPE '\\') "
                . "ORDER BY gamets DESC LIMIT {$limit}");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('quests journal lookup', $e);
            return [];
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $named = false;
            foreach (self::aliasNames($r['data'] ?? null) as $alias) {
                if (strcasecmp($alias, $npcName) === 0) { $named = true; break; }
            }
            if (!$named && !self::names((string) ($r['briefing'] ?? ''), $npcName)) continue;
            $id = (string) ($r['id_quest'] ?? '');
            if ($id === '' || isset($out[$id])) continue;
            $out[$id] = ['id' => $id, 'name' => trim((string) ($r['name'] ?? '')), 'stage' => intval($r['stage'] ?? 0)];
        }
        return array_values($out);
    }

    // =====================================================================
    // DUTY OVERRIDE (MDD 9)
    // =====================================================================

    /**
     * Why the NPC counts as hostile to the player, or null: her autonomy state
     * (duty_hostile_states), a walkaway under way, core's Player.type (duty_hostile_core_types),
     * or core affinity at or below duty_hostile_affinity_max (known only once the mirror is).
     */
    public static function hostileReason(array $dynamics, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? self::config();
        $walk = (string) ($dynamics['_walkaway_state'] ?? 'normal');
        if ($walk !== 'normal' && $walk !== '') return "walkaway:{$walk}";
        $temperament = (string) ($dynamics['inferred_temperament'] ?? $dynamics['temperament'] ?? 'Stoic');
        $state = RelationshipDynamics::evaluateAutonomyState($dynamics, $temperament)['state'];
        if (in_array($state, (array) $cfg['duty_hostile_states'], true)) return "autonomy:{$state}";
        $type = strtolower(trim((string) ($dynamics['_core_rel_type'] ?? '')));
        if ($type !== '' && in_array($type, array_map('strtolower', (array) $cfg['duty_hostile_core_types']), true)) return "core_type:{$type}";
        if (is_numeric($dynamics['_aff_mirror_x'] ?? null)
            && RelationshipDynamics::getCoreAffinity($dynamics) <= floatval($cfg['duty_hostile_affinity_max'])) {
            return 'affinity';
        }
        return null;
    }

    /**
     * The duty override of this request for $npcName, or null:
     * ['factor' => 0..1, 'quest' => name, 'quest_id' => editor id, 'hostile' => reason, 'source' => journal|flag].
     */
    public static function dutyState(string $npcName, array $dynamics): ?array
    {
        if (empty(RelationshipDynamics::configValue('duty_override_enabled'))) return null;
        $cfg = self::config();
        $flag = self::dutyFlag();
        if ($flag !== null) {
            return ['factor' => $flag, 'quest' => null, 'quest_id' => null, 'hostile' => null, 'source' => 'flag'];
        }
        $hostile = self::hostileReason($dynamics, $cfg);
        if ($hostile === null) return null;
        $quests = self::questsNaming($npcName);
        if ($quests === []) return null;
        return ['factor' => max(0.0, min(1.0, floatval($cfg['duty_dampen']))), 'quest' => $quests[0]['name'] !== '' ? $quests[0]['name'] : null,
            'quest_id' => $quests[0]['id'], 'hostile' => $hostile, 'source' => 'journal'];
    }

    /** The explicit game-side flag's dampening (0..1), or null when it is not set. */
    private static function dutyFlag(): ?float
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return null;
        try {
            $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id = '" . self::DUTY_FLAG_ROW_ID . "' LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('duty override flag', $e);
            return null;
        }
        $v = is_array($row) && isset($row['value']) ? json_decode((string) $row['value'], true) : null;
        if (!is_array($v) || empty($v['active'])) return null;
        return max(0.0, min(1.0, floatval($v['dampening'] ?? self::config()['duty_dampen'])));
    }

    /**
     * Prerequest: this request's duty override into the NPC's dynamics (DUTY_KEY, for the eval
     * job, the context and Jev) and the request globals RELDYN_DUTY_FACTOR / RELDYN_DUTY.
     */
    public static function onPrerequest(string $npcName, array &$dynamics): ?array
    {
        $duty = self::dutyState($npcName, $dynamics);
        if ($duty === null) {
            unset($dynamics[self::DUTY_KEY]);
            return null;
        }
        $dynamics[self::DUTY_KEY] = $duty + ['gamets' => RelationshipDynamics::currentGamets()];
        $GLOBALS['RELDYN_DUTY_FACTOR'] = floatval($duty['factor']);
        $GLOBALS['RELDYN_DUTY'] = $duty;
        RelationshipDynamics::log("[RelDyn-DUTY] {$npcName}: duty override (" . ($duty['quest'] ?? $duty['source']) . ', hostile '
            . ($duty['hostile'] ?? 'n/a') . '), negative eval signals x' . round($duty['factor'], 2));
        return $duty;
    }

    // =====================================================================
    // QUEST EVENT HOOK
    // =====================================================================

    /**
     * RelDyn NPCs a journal quest names (its alias map, else a whole-name match in its
     * objective against the aliases' names): names as core_npc_master has them.
     */
    public static function involvedNpcs(string $questId): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db || trim($questId) === '' || !RelDynTraitRead::tableExists('quests', $db)) return [];
        $row = $db->fetchOne('SELECT name, briefing, data FROM quests WHERE id_quest = $1 ORDER BY gamets DESC LIMIT 1', [$questId]);
        if (!is_array($row) || $row === []) return [];
        $out = [];
        foreach (self::aliasNames($row['data'] ?? null) as $name) {
            if (strcasecmp($name, (string) ($GLOBALS['PLAYER_NAME'] ?? '')) === 0) continue;
            $npc = $db->fetchOne('SELECT npc_name FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1', [$name]);
            if (is_array($npc) && isset($npc['npc_name'])) $out[strtolower($npc['npc_name'])] = (string) $npc['npc_name'];
        }
        return array_values($out);
    }

    /**
     * Route the questlog stage changes since the watermark (prerequest, before this NPC's
     * dynamics load) to RelationshipDynamics::onQuestEvent for every NPC the quest names. Each
     * row once (compare-and-set watermark); a first run anchors at the newest row. Returns the
     * number of rows read.
     */
    public static function consumeQuestlog(): int
    {
        $db = $GLOBALS['db'] ?? null;
        $cfg = self::config();
        // a database without core's questlog (not CHIM 3.4.1 core): no stage changes to route
        if (!$db || empty($cfg['consume_questlog']) || !RelDynTraitRead::tableExists('questlog', $db)) return 0;
        try {
            $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::WATERMARK_ROW_ID]);
            $raw = is_array($row) && isset($row['value']) ? (string) $row['value'] : null;
            $mark = $raw !== null ? json_decode($raw, true) : null;
            if ($raw !== null && !is_numeric($mark['rowid'] ?? null)) {
                error_log('[RelDyn] ERROR questlog watermark: conf_opts ' . self::WATERMARK_ROW_ID . ' unreadable; re-anchoring');
            }
            $top = $db->fetchOne('SELECT rowid FROM questlog ORDER BY rowid DESC LIMIT 1');
            $newest = intval($top['rowid'] ?? 0);
            $from = is_numeric($mark['rowid'] ?? null) ? intval($mark['rowid']) : null;
            if ($from === null || $newest < $from) {
                self::claim($raw, $newest);
                return 0;
            }
            if ($newest === $from) return 0;
            $limit = max(1, intval($cfg['batch']));
            $rows = $db->fetchAll('SELECT rowid, id_quest, stage, briefing, gamets FROM questlog WHERE rowid > ' . $from
                . ' AND rowid <= ' . $newest . ' ORDER BY rowid LIMIT ' . $limit);
            $rows = is_array($rows) ? $rows : [];
            $to = count($rows) >= $limit ? intval(end($rows)['rowid']) : $newest;
            if (!self::claim($raw, $to)) return 0;   // another request routes them
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('questlog consumer', $e);
            return 0;
        }
        foreach ($rows as $r) {
            $questId = trim((string) ($r['id_quest'] ?? ''));
            if ($questId === '') continue;
            try {
                $journal = $db->fetchOne('SELECT name FROM quests WHERE id_quest = $1 ORDER BY gamets DESC LIMIT 1', [$questId]);
                $ctx = ['name' => trim((string) ($journal['name'] ?? '')), 'objective' => (string) ($r['briefing'] ?? ''),
                    'gamets' => floatval($r['gamets'] ?? 0)];
                foreach (self::involvedNpcs($questId) as $npc) {
                    $dynamics = RelationshipDynamics::getDynamics($npc);
                    RelationshipDynamics::onQuestEvent($npc, $questId, intval($r['stage'] ?? 0), $dynamics, $ctx);
                    RelationshipDynamics::saveDynamics($npc, $dynamics);
                }
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("quest event questlog row {$r['rowid']}", $e);
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

    /** Config life_changing entries that match this quest stage for $npcName. */
    public static function lifeChanging(string $npcName, string $questId, int $stage): ?array
    {
        foreach ((array) self::config()['life_changing'] as $e) {
            if (!is_array($e) || strcasecmp(trim((string) ($e['quest'] ?? '')), $questId) !== 0) continue;
            if ($stage < intval($e['stage_min'] ?? 0)) continue;
            $npcs = array_map(fn($n) => strtolower(trim((string) $n)), (array) ($e['npcs'] ?? []));
            if ($npcs !== [] && !in_array(strtolower(trim($npcName)), $npcs, true)) continue;
            return ['severity' => max(1, min(5, intval($e['severity'] ?? 3)))];
        }
        return null;
    }

    /** Jev's view of the duty override: null or ['quest' => ?string, 'factor' => 0..1, 'hostile' => ?string]. */
    public static function jev(array $dynamics): ?array
    {
        $d = $dynamics[self::DUTY_KEY] ?? null;
        if (!is_array($d)) return null;
        return ['quest' => isset($d['quest']) ? (string) $d['quest'] : null, 'factor' => round(floatval($d['factor'] ?? 1.0), 3),
            'hostile' => isset($d['hostile']) ? (string) $d['hostile'] : null];
    }
}
