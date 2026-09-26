<?php
/**
 * Relationship Dynamics — save-load consistency (roadmap save-load-rollback, "Dragon Break").
 *
 * What CHIM 3.4.1 core does when the player loads a save (processor/comm.php, request 'init'
 * with gamets T = the loaded save's game time; main.php runs ext prerequest hooks BEFORE it):
 *   1. init with gamets 10000000 is ignored (Skyrim briefly reports a level-1 prisoner).
 *   2. Dragon Break / playthrough guard may save a playthrough, or set pgr_skip_rollback and
 *      end the request: then nothing below happens and no 'init' eventlog row is written.
 *   3. Deletes eventlog / speech / currentmission / diarylog / actions_issued / moods_issued /
 *      rumors / named_cell / sneq_quests_saved / bgl_history rows with gamets >= T (plus rows
 *      with localts in the future), memory_summary / memory past T, all of responselog and
 *      rolemaster, then inserts ONE eventlog row type 'init' (gamets T, localts now).
 *   4. NpcMaster::restoreNPC(T): every unlocked row with gamets_last_updated > 0 is replaced by
 *      its newest core_npc_master_history snapshot at or before T, plugin_extended_data (so
 *      RelDyn's whole 'reldyn' namespace) included. Snapshots are written by infosave
 *      (backupAllNpcs: every NPC, stamped T of the save), by core's relationship writes
 *      (chimRelationshipTimelineStamp, one NPC) and by the dynamic profile scheduler. RelDyn's
 *      own writes never stamp or snapshot, so RelDyn state rolls back to core's last snapshot.
 *      NEVER_CLEAR_RELATIONSHIP_DATA off (default): extended_data.relationships (Player.aff,
 *      type, notes) is restored from the newest eligible snapshot too, and a row with no
 *      snapshot at or before T is deleted outright. On: a row with no eligible snapshot is
 *      left as it is, and restored rows keep their CURRENT relationships keys while
 *      plugin_extended_data still rolls back: core affinity stays in the future, RelDyn does
 *      not. Background-life flags are reset.
 *   5. relationship_eval_queue and relationship_init_queue are emptied (core's paradox
 *      prevention, whatever NEVER_CLEAR says). SNQE quests reload.
 *   'playerdied' has a similar prune keyed on the last infosave, but its lookup selects gamets
 *   and reads ts, so it never runs in 3.4.1; RelDyn does nothing for it either.
 *
 * RelDyn follows with the same signal: the eventlog 'init' row core writes after its prune.
 *   - beforeCoreLoad() (init prerequest, before core's restore): stash every NPC's 'reldyn'
 *     namespace in reldyn_load_stash, so the reconcile can keep it or rescue pending evals.
 *   - reconcileIfLoaded() (first RelDyn entry point after the load: core's poll
 *     (RelationshipDynamics::onPollRequest), prerequest, the eval worker, and beforeCoreLoad
 *     itself for a previous load): once per 'init' row, under an
 *     advisory lock, marked in conf_opts relationship_dynamics_timeline. Per state class:
 *       relationship state (the dynamics blob: dimensions, passion, jealousy, resentment,
 *         conflict, walkaway, fulfillment, romance; the published romance state and the last
 *         core type change): FOLLOWS core's restore when NEVER_CLEAR_RELATIONSHIP_DATA is off,
 *         KEPT from the stash when it is on (config save_load.relationship_state); a row core
 *         did not restore keeps what it has, as core does.
 *       pending work (reldyn_eval_queue jobs, eval inbox items, pending romance moments) whose
 *         exchange the load discarded: DROPPED always, as core empties its queues always. An
 *         item is discarded when an 'init' row logged after it loaded a game time at or
 *         before the exchange (anchor rowid, else real-time order of queued_at vs localts).
 *         Pending items that were not applied yet survive (restored inbox + stashed inbox).
 *       game-calendar checkpoints and timers: REBASELINED, anything later than T moves to T
 *         (an absolute window keeps its length), so no negative, future or double interval.
 *       affinity mirror: ALWAYS core's value after the load (never a stale mirror or pending
 *         delta written back); core's type re-read; the conflict session starts over.
 *       gold ledger (core_player.reldyn_gold_ledger): FOLLOWS the game, rewound to its newest
 *         checkpoint at or before T.
 *       play clocks (_accumulated_time play seconds, the play heartbeat, _accumulated_play_gamets
 *         and play-clock timers), static knowledge (facet classifier tables, config) and dead
 *         letters: KEPT. The init prerequest beats the heartbeat first, so the play in the rows
 *         core is about to prune is banked; the loaded game's 'init' row restarts its baseline.
 *   - Core writes its 'init' row BEFORE restoreNPC (a soundcache sweep in between), so the row
 *     alone does not prove the restore is done. beforeCoreLoad takes a session advisory lock
 *     (LOCK_KEY_LOADING) that the init request holds until its database session ends, after
 *     core's restore; while another session holds it, reconcileIfLoaded() defers (the request
 *     skips RelDyn, the worker pauses) and nothing is saved (saveGate): the reconcile runs on
 *     the first entry after the restore.
 *   - Copies of dynamics read before a load, or before its reconcile, are never saved after
 *     it, and their affinity is never pushed into core (saveDynamics / commitPlayerAffinity
 *     check saveGate), and the worker / inbox consumer drop rolled-back items at use time too.
 *   - Exactly once across a load: an eval item applied after core's snapshot for an exchange the
 *     load keeps (before the save) was rolled back with the state; following core's restore, it
 *     is re-queued from the stash's applied log (dynamics _eval_applied_log) unless the
 *     restored state already holds it (its fingerprint in _eval_applied).
 *
 * Units: gamets are raw game-calendar gamets (1 game day = RelationshipDynamics::GAMETS_PER_DAY);
 * rowids are eventlog rowids; localts / queued_at / taken_localts are unix seconds, used only to
 * order events, never as durations.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynTimeline
{
    /** conf_opts row: the last load RelDyn reconciled {"init_rowid", "gamets", "localts", "policy", "summary"}. */
    const MARKER_ROW_ID = 'relationship_dynamics_timeline';
    /** Pre-restore copies of every NPC's 'reldyn' namespace, one batch per init request. */
    const STASH_TABLE = 'reldyn_load_stash';
    /** Two-int4 advisory lock serialising the reconcile ('RDvL', key 1). */
    const LOCK_CLASS = 1380218444;
    const LOCK_KEY = 1;
    /** Session advisory lock the init request holds while core prunes and restores (key 2). */
    const LOCK_KEY_LOADING = 2;
    /** comm.php ignores an init with this gamets (level-1 prisoner glitch). */
    const CORE_IGNORED_INIT_GAMETS = 10000000;

    /** Top-level dynamics keys holding a game-calendar checkpoint (raw gamets). */
    const CALENDAR_CLOCK_KEYS = [
        '_last_contact_gamets', '_previous_contact_gamets', '_decay_last_game_gamets', '_last_gamets',
        '_weather_gravity_gamets', '_presence_scan_gamets', '_neglect_resentment_since_gamets',
        '_walkaway_started_calendar_gamets', '_walkaway_activated_calendar_gamets',
        '_boundary_test_started_calendar_gamets', '_walkaway_recovery_calendar_gamets',
        '_walkaway_permanent_calendar_gamets',
        // absence (reldyn_absence.php): the rot clock's last positive interaction, a conflict's opening
        '_last_positive_gamets', '_conflict_entered_gamets',
    ];
    /** [start, end] of an absolute calendar window in the dynamics: a start past the load shifts both. */
    const CALENDAR_WINDOWS = [
        ['_plasticity_override_start_gamets', '_plasticity_override_expires_gamets'],
        // the breaking arc's closure of derived warmth (RelDynPassion::breakingOpenness)
        ['_breaking_warmth_start_gamets', '_breaking_warmth_until_gamets'],
    ];
    /** Fulfillment boundary stamps (raw gamets) clamped to the load point. */
    const BOUNDARY_STAMPS = ['decided_gamets', 'failed_gamets', 'resolved_gamets', 'dropped_gamets'];

    /** Request-scoped cache of loadGeneration(): ['scope' => token, 'gen' => ?int]. */
    private static $generation = null;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /** Defaults for config key 'save_load' (a stored config replaces single settings). */
    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            // Relationship state on a load: 'core' = as core's NEVER_CLEAR_RELATIONSHIP_DATA
            // (off: roll back with core's restore, on: keep the pre-load state);
            // 'follow' = always roll back; 'keep' = always keep.
            'relationship_state' => 'core',
            // Gold ledger checkpoints kept (count, one per wallet change) to rewind it on a load.
            'gold_ledger_checkpoints' => 200,
            // Compare-and-set attempts (count) per NPC when another writer changes the row mid-reconcile.
            'write_attempts' => 5,
            // Applied eval items (count, per NPC) kept in dynamics _eval_applied_log so a load
            // that follows core's restore can re-queue the ones its snapshot predates.
            'applied_log_keep' => 8,
        ];
    }

    public static function config(): array
    {
        $stored = RelationshipDynamics::configValue('save_load');
        return is_array($stored) ? array_replace(self::configDefaults(), $stored) : self::configDefaults();
    }

    public static function enabled(): bool
    {
        return RelationshipDynamics::isEnabled() && !empty(self::config()['enabled']);
    }

    /** Does this load keep the pre-load relationship state? Read in the init request, as core reads it. */
    public static function keepsRelationshipState(): bool
    {
        $mode = (string) self::config()['relationship_state'];
        if ($mode === 'keep') return true;
        if ($mode === 'follow') return false;
        return filter_var($GLOBALS['NEVER_CLEAR_RELATIONSHIP_DATA'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private static function db()
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            throw new RuntimeException('RelDynTimeline: no database connection in $GLOBALS[\'db\']');
        }
        return $db;
    }

    // =====================================================================
    // THE LOAD SIGNAL (core's eventlog 'init' row)
    // =====================================================================

    /** Newest load core processed: ['rowid' => int, 'gamets' => float, 'localts' => int], or null. */
    public static function latestLoad(): ?array
    {
        $row = self::db()->fetchOne("SELECT rowid, gamets, localts FROM eventlog WHERE type = 'init' ORDER BY rowid DESC LIMIT 1");
        if (!is_array($row) || !isset($row['rowid']) || !is_numeric($row['rowid'])) {
            return null;
        }
        return ['rowid' => intval($row['rowid']), 'gamets' => floatval($row['gamets']), 'localts' => intval($row['localts'])];
    }

    /**
     * Load generation = rowid of the newest 'init' row (0 = none yet), cached for the open
     * request scope; null when it cannot be read (the stale-copy guard is then off, logged).
     */
    public static function loadGeneration(): ?int
    {
        $scope = RelationshipDynamics::requestScopeToken();
        if ($scope !== null && is_array(self::$generation) && self::$generation['scope'] === $scope) {
            return self::$generation['gen'];
        }
        $gen = self::freshLoadGeneration();
        self::$generation = $scope !== null ? ['scope' => $scope, 'gen' => $gen] : null;
        return $gen;
    }

    /** loadGeneration() read now, never cached. */
    public static function freshLoadGeneration(): ?int
    {
        if (!self::enabled()) {
            return null;
        }
        try {
            $row = self::db()->fetchOne("SELECT max(rowid) AS r FROM eventlog WHERE type = 'init'");
        } catch (\Throwable $e) {
            if (!self::$generationWarned) {
                self::$generationWarned = true;
                error_log('[RelDyn] WARN save-load generation unreadable (' . get_class($e) . ': ' . $e->getMessage() . '); saves are not checked against loads in this process');
            }
            return null;
        }
        if (!is_array($row) || !array_key_exists('r', $row)) {
            self::warnGenerationUnreadable('eventlog query failed');
            return null;
        }
        return is_numeric($row['r']) ? intval($row['r']) : 0;
    }

    /** Logged once per process: without the generation the stale-copy guard is off (degraded, not failed). */
    private static $generationWarned = false;

    private static function warnGenerationUnreadable(string $why): void
    {
        if (!self::$generationWarned) {
            self::$generationWarned = true;
            error_log("[RelDyn] WARN save-load generation unreadable ({$why}); saves are not checked against loads in this process");
        }
    }

    /**
     * Is another session processing a load right now (between RelDyn's init prerequest and the
     * end of that init request, core's restoreNPC included)? The init session holds
     * LOCK_KEY_LOADING; a shared try-lock from here fails while it does. The holder itself
     * (the same session) reads false.
     */
    public static function loadInProgress(): bool
    {
        $db = self::db();
        $row = $db->fetchOne('SELECT pg_try_advisory_lock_shared($1::int, $2::int) AS got', [self::LOCK_CLASS, self::LOCK_KEY_LOADING]);
        if (!array_key_exists('got', (array) $row)) {
            throw new RuntimeException('RelDynTimeline::loadInProgress: advisory lock query failed');
        }
        if (in_array($row['got'], ['t', true], true)) {
            $db->fetchOne('SELECT pg_advisory_unlock_shared($1::int, $2::int) AS released', [self::LOCK_CLASS, self::LOCK_KEY_LOADING]);
            return false;
        }
        return true;
    }

    /**
     * What a save (or an affinity push into core) must check, read now:
     *   gen        the newest load (init rowid, 0 = none; null = unreadable, the guard is off)
     *   reconciled false while that load is not reconciled yet (a marker exists and is older):
     *              core may still be restoring, and a copy read now belongs to no timeline yet.
     * No marker yet (RelDyn never reconciled any load) reads reconciled.
     *
     * @return array ['gen' => ?int, 'reconciled' => bool]
     */
    public static function saveGate(): array
    {
        if (!self::enabled()) {
            return ['gen' => null, 'reconciled' => true];
        }
        $gen = self::freshLoadGeneration();   // null: unreadable (logged there), the guard is off
        if ($gen === null || $gen === 0) {
            return ['gen' => $gen, 'reconciled' => true];
        }
        $marker = self::readMarker();
        $reconciled = $marker === null || intval($marker['init_rowid'] ?? 0) >= $gen;
        return ['gen' => $gen, 'reconciled' => $reconciled];
    }

    /** Remember a generation read elsewhere in this request (the reconcile) for loadGeneration(). */
    private static function setRequestGeneration(int $gen): void
    {
        $scope = RelationshipDynamics::requestScopeToken();
        self::$generation = $scope !== null ? ['scope' => $scope, 'gen' => $gen] : null;
    }

    /**
     * Did a load discard this exchange? True when an 'init' row logged after it (rowid above
     * its anchor, else localts at or after its queued_at: in the second of a load the player
     * cannot have talked yet) loaded a game time at or before it
     * (core deletes eventlog rows with gamets >= the loaded gamets).
     *
     * @param float|null $gamets      raw game time of the exchange (null: cannot tell, false)
     * @param int|null   $anchorRowid eventlog rowid the exchange was anchored to
     * @param int|null   $queuedAt    unix seconds it was queued (order only), when no anchor
     */
    public static function rolledBack(?float $gamets, ?int $anchorRowid, ?int $queuedAt): bool
    {
        if ($gamets === null || $gamets <= 0 || ($anchorRowid === null && $queuedAt === null)) {
            return false;
        }
        $row = $anchorRowid !== null
            ? self::db()->fetchOne("SELECT 1 AS hit FROM eventlog WHERE type = 'init' AND rowid > \$1 AND gamets <= \$2 LIMIT 1",
                [$anchorRowid, sprintf('%.0F', floor($gamets))])
            : self::db()->fetchOne("SELECT 1 AS hit FROM eventlog WHERE type = 'init' AND localts >= \$1 AND gamets <= \$2 LIMIT 1",
                [$queuedAt, sprintf('%.0F', floor($gamets))]);
        if ($row === false) {
            throw new RuntimeException('RelDynTimeline::rolledBack: eventlog query failed');
        }
        return isset($row['hit']);
    }

    /** The newest eventlog rowid (null when unreadable): an anchor that orders what is queued now against later loads. */
    public static function newestEventlogRowid(): ?int
    {
        $row = self::db()->fetchOne('SELECT max(rowid) AS r FROM eventlog');
        return (is_array($row) && is_numeric($row['r'] ?? null)) ? intval($row['r']) : null;
    }

    /** rolledBack() for an eval inbox item ({queued_at, eval, anchor_rowid?} or a bare contract item). */
    public static function inboxItemRolledBack(array $item): bool
    {
        $eval = is_array($item['eval'] ?? null) ? $item['eval'] : $item;
        $gamets = is_numeric($eval['gamets'] ?? null) ? floatval($eval['gamets']) : null;
        $anchor = is_numeric($item['anchor_rowid'] ?? null) ? intval($item['anchor_rowid']) : null;
        $queued = is_numeric($item['queued_at'] ?? null) ? intval($item['queued_at']) : null;
        return self::rolledBack($gamets, $anchor, $queued);
    }

    // =====================================================================
    // BEFORE CORE'S RESTORE (init prerequest)
    // =====================================================================

    /**
     * The init request's prerequest, before core prunes and restores: reconcile a previous
     * load still pending, then stash every NPC's 'reldyn' namespace for this load.
     *
     * @return int rows stashed (0 when off or ignored)
     */
    public static function beforeCoreLoad(float $loadGamets): int
    {
        if (!self::enabled() || $loadGamets <= 0 || (int) $loadGamets === self::CORE_IGNORED_INIT_GAMETS) {
            return 0;
        }
        self::reconcileIfLoaded();
        // Held by this (the init) request's session until it ends, i.e. past core's prune, its
        // 'init' row and restoreNPC: other sessions defer their reconcile meanwhile. Never waited
        // for: a load is never held up by RelDyn (another init still holding it covers this one).
        $held = self::db()->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [self::LOCK_CLASS, self::LOCK_KEY_LOADING]);
        if (!array_key_exists('got', (array) $held)) {
            throw new RuntimeException('RelDynTimeline: the loading lock query failed');
        }
        if (!in_array($held['got'], ['t', true], true)) {
            error_log('[RelDyn] save load: another session still holds the loading lock (an earlier load in progress); this load is covered by it');
        }
        self::ensureStashTable();
        $prev = self::latestLoad();
        $after = $prev['rowid'] ?? 0;
        $db = self::db();
        // Stashes of earlier loads were consumed by the reconcile above, or core skipped them
        $db->fetchOne('DELETE FROM ' . self::STASH_TABLE . ' WHERE after_init_rowid < $1', [$after]);
        $row = $db->fetchOne(
            'WITH s AS (INSERT INTO ' . self::STASH_TABLE . ' (batch, after_init_rowid, load_gamets, taken_localts, keep, npc_id, reldyn)
                 SELECT $1, $2, $3, $4, $5, id, plugin_extended_data -> \'reldyn\'
                 FROM core_npc_master WHERE jsonb_typeof(plugin_extended_data -> \'reldyn\') = \'object\'
                 RETURNING 1)
             SELECT count(*) AS n FROM s',
            [bin2hex(random_bytes(8)), $after, sprintf('%.6F', $loadGamets), time(), self::keepsRelationshipState() ? 't' : 'f']
        );
        if (!isset($row['n'])) {
            throw new RuntimeException('RelDynTimeline::beforeCoreLoad: stash insert failed');
        }
        $n = intval($row['n']);
        error_log("[RelDyn] save load to gamets {$loadGamets}: stashed {$n} NPC namespace(s) before core's restore (keep=" . (self::keepsRelationshipState() ? 'yes' : 'no') . ')');
        return $n;
    }

    public static function ensureStashTable(): void
    {
        $db = self::db();
        $row = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::STASH_TABLE]);
        if (in_array($row['present'] ?? null, ['t', true], true)) {
            return;
        }
        $db->fetchOne('CREATE TABLE IF NOT EXISTS ' . self::STASH_TABLE . ' (
            id bigserial PRIMARY KEY,
            batch text NOT NULL,
            after_init_rowid bigint NOT NULL,
            load_gamets float8 NOT NULL,
            taken_localts bigint NOT NULL,
            keep boolean NOT NULL,
            npc_id integer NOT NULL,
            reldyn jsonb NOT NULL)');
        $check = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::STASH_TABLE]);
        if (!in_array($check['present'] ?? null, ['t', true], true)) {
            throw new RuntimeException('RelDynTimeline: could not create ' . self::STASH_TABLE);
        }
    }

    // =====================================================================
    // AFTER THE LOAD: reconcile once per 'init' row
    // =====================================================================

    /**
     * Reconcile RelDyn with the newest load core processed, once. Returns the summary when
     * this call reconciled, null when there was nothing to do, ['deferred' => true, ...] while
     * another session is still processing the load (loadInProgress: the caller skips RelDyn
     * for this entry; nothing is marked, the next entry reconciles). The first time RelDyn sees any
     * load (no marker yet) it adopts the newest one without touching state: whatever came
     * after that load was written by a RelDyn without this reconcile and is the present.
     */
    public static function reconcileIfLoaded(): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $load = self::latestLoad();
        if ($load === null) {
            return null;
        }
        self::setRequestGeneration($load['rowid']);
        $marker = self::readMarker();
        if ($marker !== null && intval($marker['init_rowid'] ?? 0) >= $load['rowid']) {
            return null;
        }
        // Core may still be between its 'init' row and restoreNPC: wait for the next entry
        if (self::loadInProgress()) {
            error_log("[RelDyn] save load (init row {$load['rowid']}) is still being processed by core; reconcile deferred, RelDyn skips this entry");
            return ['deferred' => true, 'init_rowid' => $load['rowid']];
        }
        $db = self::db();
        $got = $db->fetchOne('SELECT pg_advisory_lock($1::int, $2::int) IS NOT NULL AS got', [self::LOCK_CLASS, self::LOCK_KEY]);
        if (!in_array($got['got'] ?? null, ['t', true], true)) {
            throw new RuntimeException('RelDynTimeline: reconcile lock failed');
        }
        try {
            $load = self::latestLoad() ?? $load;   // a load that landed while this waited
            self::setRequestGeneration($load['rowid']);
            $marker = self::readMarker();
            if ($marker !== null && intval($marker['init_rowid'] ?? 0) >= $load['rowid']) {
                return null;   // another request reconciled it while this one waited
            }
            if ($marker === null) {
                $summary = ['adopted' => true];
                self::writeMarker($load, 'adopted', $summary);
                error_log("[RelDyn] save load: first load seen (init row {$load['rowid']}, gamets {$load['gamets']}); adopted without changes");
                return $summary;
            }
            $summary = self::reconcile($load);
            self::writeMarker($load, $summary['policy'], $summary);
            error_log("[RelDyn] save load reconciled to gamets {$load['gamets']} (init row {$load['rowid']}): " . json_encode($summary));
            return $summary;
        } finally {
            $db->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int) AS released', [self::LOCK_CLASS, self::LOCK_KEY]);
        }
    }

    private static function reconcile(array $load): array
    {
        $T = $load['gamets'];
        $stash = self::stashFor($load);
        $keep = $stash !== null && $stash['keep'];
        if ($stash === null && self::keepsRelationshipState()) {
            error_log("[RelDyn] ERROR save load to gamets {$T}: no pre-load stash (init prerequest did not run); the relationship state follows core's restore");
        }
        $summary = ['policy' => $keep ? 'keep' : 'follow', 'gamets' => $T, 'jobs_dropped' => self::dropFutureJobs(),
                    'npcs' => 0, 'written' => 0, 'inbox_dropped' => 0, 'inbox_rescued' => 0, 'conflicts' => 0];

        $rows = self::db()->fetchAll("SELECT id FROM core_npc_master WHERE jsonb_typeof(plugin_extended_data -> 'reldyn') = 'object' ORDER BY id");
        if (!is_array($rows)) {
            throw new RuntimeException('RelDynTimeline: reading the NPC rows failed');
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[intval($r['id'])] = true;
        }
        foreach (array_keys($stash['rows'] ?? []) as $id) {
            $ids[$id] = true;
        }
        foreach (array_keys($ids) as $id) {
            $r = self::reconcileNpc($id, $T, $stash['rows'][$id] ?? null, $keep, intval($stash['taken_localts'] ?? 0));
            $summary['npcs']++;
            $summary['written'] += $r['outcome'] === 'written' ? 1 : 0;
            $summary['conflicts'] += $r['outcome'] === 'conflict' ? 1 : 0;
            $summary['inbox_dropped'] += $r['inbox_dropped'];
            $summary['inbox_rescued'] += $r['inbox_rescued'];
        }
        $summary['gold_ledger'] = RelDynPlayer::rewindGoldLedger($T);
        if (self::stashTableExists()) {
            self::db()->fetchOne('DELETE FROM ' . self::STASH_TABLE . ' WHERE after_init_rowid < $1', [$load['rowid']]);
        }
        return $summary;
    }

    /**
     * One NPC: pick the namespace (core's restore, or the stash when kept), merge the pending
     * inbox, drop what the load discarded, rebaseline clocks, re-read core's affinity; one
     * compare-and-set write, retried when another writer got in between.
     *
     * @return array ['outcome' => 'written'|'unchanged'|'gone'|'conflict', 'inbox_dropped' => int, 'inbox_rescued' => int]
     */
    private static function reconcileNpc(int $npcId, float $T, ?array $stashed, bool $keep, int $takenAt): array
    {
        $attempts = max(1, intval(self::config()['write_attempts']));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $cur = RelDynStorage::readNamespace($npcId);
            if ($cur === null) {
                return ['outcome' => 'gone', 'inbox_dropped' => 0, 'inbox_rescued' => 0];
            }
            $current = is_array($cur['value']) ? $cur['value'] : [];
            $source = ($keep && $stashed !== null) ? $stashed : $current;

            // Pending inbox: every item not applied in the chosen state.
            $inbox = self::listOf($source[RelDynStorage::KEY_EVAL_INBOX] ?? null);
            $extra = [];
            if ($keep && $stashed !== null) {
                // appended after the stash (a worker that was mid-drain): not in the kept state
                foreach (self::listOf($current[RelDynStorage::KEY_EVAL_INBOX] ?? null) as $item) {
                    if (is_numeric($item['queued_at'] ?? null) && intval($item['queued_at']) >= $takenAt) $extra[] = $item;
                }
            } elseif ($stashed !== null) {
                // pending before the restore, so never applied in the restored (older) state
                $extra = self::listOf($stashed[RelDynStorage::KEY_EVAL_INBOX] ?? null);
                // applied in the discarded timeline after core's snapshot: the restore took their
                // effect back; the ones whose exchange the load keeps apply again (exactly once)
                $restoredApplied = (array) ($current[RelDynStorage::KEY_DYNAMICS]['_eval_applied'] ?? []);
                foreach (self::listOf($stashed[RelDynStorage::KEY_DYNAMICS]['_eval_applied_log'] ?? null) as $entry) {
                    $fp = (string) ($entry['fp'] ?? '');
                    if ($fp === '' || !is_array($entry['item'] ?? null) || in_array($fp, $restoredApplied, true)) continue;
                    $extra[] = $entry['item'];
                }
            }
            $seen = [];
            foreach ($inbox as $item) $seen[json_encode($item)] = true;
            $rescued = 0;
            foreach ($extra as $item) {
                $k = json_encode($item);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $inbox[] = $item;
                $rescued++;
            }
            $kept = [];
            $dropped = 0;
            foreach ($inbox as $item) {
                $bare = !isset($item['queued_at']) && !isset($item['anchor_rowid']);
                $gamets = is_array($item['eval'] ?? null) ? ($item['eval']['gamets'] ?? null) : ($item['gamets'] ?? null);
                $gone = $bare ? (is_numeric($gamets) && floatval($gamets) >= $T) : self::inboxItemRolledBack($item);
                if ($gone) {
                    $dropped++;
                    error_log("[RelDyn] save load: dropped an eval inbox item of npc {$npcId} (exchange at gamets {$gamets}, discarded by the load)");
                    continue;
                }
                $kept[] = $item;
            }
            $ns = $source;
            if ($kept === []) unset($ns[RelDynStorage::KEY_EVAL_INBOX]); else $ns[RelDynStorage::KEY_EVAL_INBOX] = $kept;

            $ns = self::rebaselineNamespace($ns, $T, self::corePlayerRelationship($npcId));
            if ($ns == $current) {
                return ['outcome' => 'unchanged', 'inbox_dropped' => $dropped, 'inbox_rescued' => $rescued];
            }
            if (RelDynStorage::replaceNamespaceIfUnchanged($npcId, $cur['expected'], $ns)) {
                return ['outcome' => 'written', 'inbox_dropped' => $dropped, 'inbox_rescued' => $rescued];
            }
        }
        error_log("[RelDyn] ERROR save load: npc {$npcId} changed under every one of {$attempts} reconcile attempts; left as is");
        return ['outcome' => 'conflict', 'inbox_dropped' => 0, 'inbox_rescued' => 0];
    }

    /** Pending reldyn_eval_queue jobs whose exchange a load discarded. Returns how many were deleted. */
    private static function dropFutureJobs(): int
    {
        $db = self::db();
        $present = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', ['reldyn_eval_queue']);
        if (!in_array($present['present'] ?? null, ['t', true], true)) {
            return 0;
        }
        $row = $db->fetchOne(
            "WITH d AS (
                 DELETE FROM reldyn_eval_queue q
                 WHERE q.status = 'pending'
                   AND jsonb_typeof(q.job -> 'gamets') = 'number'
                   AND jsonb_typeof(q.job -> 'anchor_rowid') = 'number'
                   AND EXISTS (SELECT 1 FROM eventlog e
                               WHERE e.type = 'init'
                                 AND e.rowid > (q.job ->> 'anchor_rowid')::bigint
                                 AND e.gamets <= floor((q.job ->> 'gamets')::float8))
                 RETURNING q.id)
             SELECT count(*) AS n FROM d"
        );
        if (!isset($row['n'])) {
            throw new RuntimeException('RelDynTimeline: dropping discarded eval jobs failed');
        }
        return intval($row['n']);
    }

    /** Core's Player relationship on this row as core left it (restored or kept), or null. */
    private static function corePlayerRelationship(int $npcId): ?array
    {
        $row = self::db()->fetchOne('SELECT extended_data::text AS ext FROM core_npc_master WHERE id = $1', [$npcId]);
        $ext = is_string($row['ext'] ?? null) ? json_decode($row['ext'], true) : null;
        return RelationshipDynamics::getPlayerRelationshipFromExtended(is_array($ext) ? $ext : []);
    }

    private static function stashTableExists(): bool
    {
        $row = self::db()->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [self::STASH_TABLE]);
        return in_array($row['present'] ?? null, ['t', true], true);
    }

    /** The newest stash batch taken for this load: ['keep', 'taken_localts', 'rows' => npc_id => namespace], or null. */
    private static function stashFor(array $load): ?array
    {
        if (!self::stashTableExists()) {
            return null;
        }
        $db = self::db();
        $head = $db->fetchOne(
            'SELECT batch, keep, taken_localts FROM ' . self::STASH_TABLE . '
             WHERE after_init_rowid < $1 AND load_gamets = $2 ORDER BY id DESC LIMIT 1',
            [$load['rowid'], sprintf('%.6F', $load['gamets'])]
        );
        if (!isset($head['batch'])) {
            return null;
        }
        $rows = [];
        $batch = $db->escapeLiteral((string) $head['batch']);
        $stashRows = $db->fetchAll('SELECT npc_id, reldyn::text AS ns FROM ' . self::STASH_TABLE . " WHERE batch = {$batch}");
        if (!is_array($stashRows)) {
            throw new RuntimeException('RelDynTimeline: reading the load stash failed');
        }
        foreach ($stashRows as $r) {
            $ns = json_decode((string) $r['ns'], true);
            if (is_array($ns)) $rows[intval($r['npc_id'])] = $ns;
        }
        return ['keep' => in_array($head['keep'], ['t', true], true), 'taken_localts' => intval($head['taken_localts']), 'rows' => $rows];
    }

    private static function readMarker(): ?array
    {
        $row = self::db()->fetchOne('SELECT value FROM conf_opts WHERE id = $1', [self::MARKER_ROW_ID]);
        if (!isset($row['value'])) {
            return null;
        }
        $v = json_decode((string) $row['value'], true);
        if (!is_array($v)) {
            error_log('[RelDyn] ERROR conf_opts ' . self::MARKER_ROW_ID . ' is not JSON; treated as absent');
            return null;
        }
        return $v;
    }

    private static function writeMarker(array $load, string $policy, array $summary): void
    {
        $value = json_encode(['init_rowid' => $load['rowid'], 'gamets' => $load['gamets'], 'localts' => $load['localts'],
                              'policy' => $policy, 'summary' => $summary]);
        $row = self::db()->fetchOne(
            'INSERT INTO conf_opts (id, value) VALUES ($1, $2) ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value RETURNING id',
            [self::MARKER_ROW_ID, $value]
        );
        if (!isset($row['id'])) {
            throw new RuntimeException('RelDynTimeline: writing the reconcile marker failed');
        }
    }

    // =====================================================================
    // PURE REBASELINE
    // =====================================================================

    /** The 'reldyn' namespace with every clock past $T moved to $T and the affinity mirror on core's value. */
    public static function rebaselineNamespace(array $ns, float $T, ?array $coreRel): array
    {
        if (is_array($ns[RelDynStorage::KEY_DYNAMICS] ?? null)) {
            $ns[RelDynStorage::KEY_DYNAMICS] = self::rebaselineDynamics($ns[RelDynStorage::KEY_DYNAMICS], $T, $coreRel);
        }
        self::clampIn($ns, [RelDynStorage::KEY_CALENDAR, 'checked_gamets'], $T);
        self::clampIn($ns, ['eval_producer', 'last_enqueued_gamets'], $T);
        self::clampIn($ns, ['romance', 'gamets'], $T);
        self::clampIn($ns, ['core_type_change', 'gamets'], $T);
        return $ns;
    }

    public static function rebaselineDynamics(array $d, float $T, ?array $coreRel): array
    {
        foreach (self::CALENDAR_CLOCK_KEYS as $key) {
            self::clampIn($d, [$key], $T);
        }
        foreach (self::CALENDAR_WINDOWS as [$start, $end]) {
            self::shiftWindow($d, $start, $end, $T);
        }
        // A load starts a new conflict session (the high was seen in another timeline)
        unset($d['_conflict_aff_session']);
        // Protocols (reldyn_protocols.php): a crisis window that opened after the loaded time
        // starts there, its length kept; the ick's trigger stamp and the parasite's passion clock
        // come back to it. A grief from a death after the loaded time is kept (the policy kept the
        // state) but its phases count from the loaded time.
        if (is_array($d['_unstable_window'] ?? null)) {
            self::clampIn($d, ['_unstable_window', 'start_gamets'], $T);
        }
        self::clampIn($d, ['_ick_tracker', 'ick_triggered_gamets'], $T);
        self::clampIn($d, [RelDynProtocols::PARASITE_CLOCK_KEY], $T);
        // Absence (reldyn_absence.php): the rot's condition onsets and its per-absence ledger, the
        // last bond break's stamps, all on the loaded time at the latest
        foreach (array_keys(is_array($d[RelDynAbsence::ROT_KEY]['since'] ?? null) ? $d[RelDynAbsence::ROT_KEY]['since'] : []) as $c) {
            self::clampIn($d, [RelDynAbsence::ROT_KEY, 'since', $c], $T);
        }
        self::clampIn($d, [RelDynAbsence::ROT_KEY, 'last_gamets'], $T);
        self::clampIn($d, [RelDynAbsence::ROT_KEY, 'absence', 'contact'], $T);
        self::clampIn($d, [RelDynAbsence::BREAK_KEY, 'since_gamets'], $T);
        self::clampIn($d, [RelDynAbsence::BREAK_KEY, 'at_gamets'], $T);
        // Combat's rescue response (reldyn_combat.php): a fall the load discarded never happened (no
        // rescue waits for it), nor did an exchange that claimed one after the loaded time; the felt
        // moment of a rescue answered after it is gone (its passion stays as the policy kept it)
        $rescue = $d[RelDynCombat::RESCUE_PENDING_KEY] ?? null;
        if (is_array($rescue) && is_numeric($rescue['fall_gamets'] ?? null) && floatval($rescue['fall_gamets']) > $T) {
            unset($d[RelDynCombat::RESCUE_PENDING_KEY]);
        } elseif (is_array($rescue) && is_numeric($rescue['claimed_gamets'] ?? null) && floatval($rescue['claimed_gamets']) > $T) {
            $d[RelDynCombat::RESCUE_PENDING_KEY]['claimed_gamets'] = null;
        }
        $last = $d[RelDynCombat::RESCUE_LAST_KEY] ?? null;
        if (is_array($last) && is_numeric($last['gamets'] ?? null) && floatval($last['gamets']) > $T) {
            unset($d[RelDynCombat::RESCUE_LAST_KEY]);
        }
        // The short band (impulses) starts over; the loneliness timer and places seen come back to it
        if (is_array($d[RelDynImpulse::KEY] ?? null)) {
            RelDynImpulse::rebaseline($d[RelDynImpulse::KEY], $T);
        }
        foreach (array_keys(is_array($d['_grief_bonds'] ?? null) ? $d['_grief_bonds'] : []) as $deceased) {
            self::clampIn($d, ['_grief_bonds', $deceased, 'death_gamets'], $T);
        }
        // Her drinks, uses and drunk nights the loaded game never lived (RelDynSubstances); the held
        // offsets are re-held from what is left on her next update
        if (is_array($d[RelDynSubstances::KEY] ?? null)) {
            $d[RelDynSubstances::KEY] = RelDynSubstances::rebaseline($d[RelDynSubstances::KEY], $T);
        }
        // Pending romance moments from exchanges the load discarded
        if (is_array($d['_romance']['pending'] ?? null)) {
            $pending = array_values(array_filter($d['_romance']['pending'], static fn($m) =>
                !(is_array($m) && is_numeric($m['gamets'] ?? null) && floatval($m['gamets']) >= $T)));
            if ($pending === []) unset($d['_romance']['pending']); else $d['_romance']['pending'] = $pending;
        }
        // Every relationship pair's fulfillment (rulings §11), each on the same load
        foreach (RelDynFulfillment::pairs($d) as $target => $state) {
            RelDynFulfillment::setPairState($d, (string) $target, self::rebaselineFulfillment($state, $T));
        }
        // Natural exclusivity's suitor ledger (decisions §17): a move the load discarded never
        // happened (it no longer steers her), and no entry is stamped after the loaded time
        if (is_array($d[RelDynExclusivity::STATE_KEY]['suitors'] ?? null)) {
            foreach ($d[RelDynExclusivity::STATE_KEY]['suitors'] as $key => $e) {
                if (!is_array($e)) continue;
                if (is_numeric($e['last_move_gamets'] ?? null) && floatval($e['last_move_gamets']) > $T) unset($e['last_move_gamets']);
                self::clampIn($e, ['gamets'], $T);
                $d[RelDynExclusivity::STATE_KEY]['suitors'][$key] = $e;
            }
        }
        // Baseline drift samples (one per game day of contact) of days after the loaded one: the
        // loaded game never lived them
        if (is_array($d['_baseline_drift_samples'] ?? null)) {
            $loadDay = (int) floor($T / RelationshipDynamics::GAMETS_PER_DAY);
            foreach ($d['_baseline_drift_samples'] as $dim => $list) {
                $d['_baseline_drift_samples'][$dim] = array_values(array_filter((array) $list, static fn($s) =>
                    !(is_array($s) && is_numeric($s['day'] ?? null) && intval($s['day']) > $loadDay)));
            }
        }
        // Affinity: core's value is the truth after a load; nothing uncommitted is pushed back.
        RelationshipDynamics::refreshAffinityMirror($d, intval($coreRel['aff'] ?? 0));
        $d['_pending_aff_delta'] = 0.0;
        RelationshipDynamics::setCoreRelationshipType($d, $coreRel['type'] ?? 'neutral');
        return $d;
    }

    private static function rebaselineFulfillment(array $f, float $T): array
    {
        $day = (float) RelationshipDynamics::GAMETS_PER_DAY;
        foreach (['since', 'gamets', 'low_since_gamets'] as $key) {
            self::clampIn($f, [$key], $T);
        }
        if (is_numeric($f['sampled_gamets'] ?? null) && floatval($f['sampled_gamets']) > $T) {
            $f['sampled_gamets'] = floor($T / $day) * $day;   // the last day-end at or before T
        }
        if (is_array($f['days'] ?? null)) {
            // [game day d, band]: day d ended at (d + 1) days
            $f['days'] = array_values(array_filter($f['days'], static fn($s) =>
                !(is_array($s) && is_numeric($s[0] ?? null) && (intval($s[0]) + 1) * $day > $T)));
        }
        if (is_array($f['contact_days'] ?? null)) {
            $today = (int) floor($T / $day);
            $f['contact_days'] = array_values(array_filter($f['contact_days'], static fn($d) => !(is_int($d) && $d > $today)));
        }
        if (is_array($f['boundary'] ?? null)) {
            self::shiftWindow($f['boundary'], 'started_gamets', 'until_gamets', $T);
            foreach (self::BOUNDARY_STAMPS as $key) {
                self::clampIn($f['boundary'], [$key], $T);
            }
        }
        return $f;
    }

    /** $a[path] = min($a[path], $T) when it is a number. */
    private static function clampIn(array &$a, array $path, float $T): void
    {
        $ref = &$a;
        foreach ($path as $i => $k) {
            if (!is_array($ref) || !array_key_exists($k, $ref)) return;
            if ($i < count($path) - 1) {
                $ref = &$ref[$k];
                continue;
            }
            if ((is_int($ref[$k]) || is_float($ref[$k])) && $ref[$k] > $T) {
                $ref[$k] = is_int($ref[$k]) ? (int) floor($T) : $T;
            }
        }
    }

    /** A window whose start is past $T moves back so it starts at $T, its length kept. */
    private static function shiftWindow(array &$a, string $start, string $end, float $T): void
    {
        $s = $a[$start] ?? null;
        if (!is_numeric($s) || floatval($s) <= $T) return;
        $shift = floatval($s) - $T;
        $a[$start] = $T;
        if (is_numeric($a[$end] ?? null)) {
            $a[$end] = floatval($a[$end]) - $shift;
        }
    }

    private static function listOf($v): array
    {
        return (is_array($v) && array_is_list($v)) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
