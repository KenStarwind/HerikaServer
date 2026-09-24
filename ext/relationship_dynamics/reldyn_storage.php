<?php
/**
 * Relationship Dynamics — per-plugin storage (CHIM 3.4.1 plugin_extended_data)
 *
 * Layout: core_npc_master.plugin_extended_data -> 'reldyn' -> {
 *     "dynamics":   { ...engine state (the former extended_data.relationship_dynamics blob)... },
 *     "eval_inbox": [ {"queued_at": <unix ts>, "eval": { ...eval result... }}, ... ]
 * }
 *
 * Reads go through NpcMaster::getPluginData. Writes do NOT use NpcMaster::setPluginData,
 * because that replaces the whole 'reldyn' object and would let a dynamics save clobber an
 * eval queued in between. Instead every writer changes only its own top-level key with a
 * single UPDATE (jsonb_set on the same column), which PostgreSQL applies atomically per row:
 *   - setKeyIfUnchanged() replaces one key only if nobody wrote it since it was read
 *                          (dynamics saves: RelationshipDynamics::saveDynamics() merges and retries)
 *   - setKey()     replaces one key unconditionally
 *   - appendItem() appends to a list key     (eval producers)
 *   - appendItemConsumingRow() appends and deletes the queue job, one statement (eval worker)
 *   - peekItems() + dropFirstItems() under tryLockInbox(): the eval consumer reads, applies,
 *                  saves, then removes what it applied (nothing lost if it dies in between)
 *   - takeItems()  returns and removes a list key under a row lock (drop with the engine off)
 *
 * No process-level caching: every call reads the database, so long-lived processes
 * (the relationship worker daemon) never write from stale data.
 */

class RelDynStorage
{
    const PLUGIN_ID      = 'reldyn';
    const KEY_DYNAMICS   = 'dynamics';
    const KEY_EVAL_INBOX = 'eval_inbox';
    // Inbox items that failed to apply EVAL_ITEM_MAX_FAILURES times: [{failed_at, error, eval}]
    const KEY_EVAL_DEAD  = 'eval_inbox_dead';
    // {"checked_gamets": raw game-calendar gamets up to which the calendar step has run}
    const KEY_CALENDAR   = 'calendar';

    // Fresh start on 3.4.1 (decisions 2026-09-23 section 3): the April blob in
    // extended_data.relationship_dynamics is never read or copied.

    private static function db()
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            throw new RuntimeException('RelDynStorage: no database connection in $GLOBALS[\'db\']');
        }
        return $db;
    }

    private static function npcMaster()
    {
        if (!class_exists('NpcMaster')) {
            require_once dirname(__DIR__, 2) . '/lib/core/npc_master.class.php';
        }
        return new NpcMaster();
    }

    /** Convert NpcMaster's stdClass-preserving values to the assoc arrays the engine uses. */
    private static function toAssoc($value)
    {
        if (is_object($value) || is_array($value)) {
            return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        return $value;
    }

    private static function encode($value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Resolve an NPC name (case-insensitive, as the engine always did) to core_npc_master.id.
     */
    public static function resolveNpcId($npcName): ?int
    {
        if (!is_string($npcName) || trim($npcName) === '') {
            return null;
        }
        $row = self::db()->fetchOne(
            'SELECT id FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1',
            [$npcName]
        );
        $id = intval($row['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** Whole 'reldyn' namespace as assoc arrays ([] when absent). */
    public static function getAll(int $npcId): array
    {
        $data = self::npcMaster()->getPluginData($npcId, self::PLUGIN_ID);
        return is_array($data) ? self::toAssoc($data) : [];
    }

    /** Stored dynamics, or null when this NPC has none in plugin storage. */
    public static function loadDynamics(int $npcId): ?array
    {
        $all = self::getAll($npcId);
        $dyn = $all[self::KEY_DYNAMICS] ?? null;
        return (is_array($dyn) && !empty($dyn)) ? $dyn : null;
    }

    /**
     * Unconditional overwrite of the dynamics key. Engine code must not use this for a
     * loaded-and-modified copy (lost updates); RelationshipDynamics::saveDynamics() merges.
     */
    public static function saveDynamics(int $npcId, array $dynamics): bool
    {
        return self::setKey($npcId, self::KEY_DYNAMICS, $dynamics);
    }

    /** Replace one top-level key of the 'reldyn' namespace; other keys are untouched. */
    public static function setKey(int $npcId, string $key, $value): bool
    {
        $row = self::db()->fetchOne(
            "UPDATE core_npc_master
             SET plugin_extended_data = jsonb_set(
                 plugin_extended_data,
                 ARRAY[\$2::text],
                 (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text) = 'object'
                       THEN plugin_extended_data -> \$2::text ELSE '{}'::jsonb END)
                 || jsonb_build_object(\$3::text, \$4::jsonb),
                 true)
             WHERE id = \$1
             RETURNING id",
            [$npcId, self::PLUGIN_ID, $key, self::encode(is_array($value) && empty($value) ? new stdClass() : $value)]
        );
        return isset($row['id']);
    }

    /**
     * Current value of one key, for a compare-and-set write.
     *
     * Returns null when the NPC row does not exist, otherwise
     *   ['value' => assoc value or null, 'expected' => JSON of the stored value or null when absent].
     * 'expected' keeps JSON objects as objects (NpcMaster preserves stdClass), so PostgreSQL's
     * jsonb equality in setKeyIfUnchanged() matches exactly when nobody wrote the key since.
     */
    public static function readKeyForUpdate(int $npcId, string $key): ?array
    {
        $row = self::db()->fetchOne(
            'SELECT plugin_extended_data -> $2::text AS plugin_data FROM core_npc_master WHERE id = $1',
            [$npcId, self::PLUGIN_ID]
        );
        if (!is_array($row) || !array_key_exists('plugin_data', $row)) {
            return null;
        }
        $ns = ($row['plugin_data'] === null || $row['plugin_data'] === '')
            ? null
            : json_decode($row['plugin_data'], false, 512, JSON_THROW_ON_ERROR);
        if (!$ns instanceof stdClass || !property_exists($ns, $key)) {
            return ['value' => null, 'expected' => null];
        }
        return ['value' => self::toAssoc($ns->$key), 'expected' => self::encode($ns->$key)];
    }

    /**
     * Replace one key only if it still holds $expected (JSON from readKeyForUpdate(), null = absent).
     * Single statement, so the check and the write are atomic. Returns false when another
     * writer changed the key first; the caller re-reads, merges and tries again.
     */
    public static function setKeyIfUnchanged(int $npcId, string $key, ?string $expected, $value): bool
    {
        $row = self::db()->fetchOne(
            "UPDATE core_npc_master
             SET plugin_extended_data = jsonb_set(
                 plugin_extended_data,
                 ARRAY[\$2::text],
                 (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text) = 'object'
                       THEN plugin_extended_data -> \$2::text ELSE '{}'::jsonb END)
                 || jsonb_build_object(\$3::text, \$4::jsonb),
                 true)
             WHERE id = \$1
               AND (plugin_extended_data -> \$2::text -> \$3::text) IS NOT DISTINCT FROM \$5::jsonb
             RETURNING id",
            [$npcId, self::PLUGIN_ID, $key, self::encode(is_array($value) && empty($value) ? new stdClass() : $value), $expected]
        );
        return isset($row['id']);
    }

    /** Append one item to a list key in a single statement (concurrent appends all survive). */
    public static function appendItem(int $npcId, string $key, array $item): bool
    {
        $row = self::db()->fetchOne(
            "UPDATE core_npc_master
             SET plugin_extended_data = jsonb_set(
                 plugin_extended_data,
                 ARRAY[\$2::text],
                 (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text) = 'object'
                       THEN plugin_extended_data -> \$2::text ELSE '{}'::jsonb END)
                 || jsonb_build_object(\$3::text,
                        (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text -> \$3::text) = 'array'
                              THEN plugin_extended_data -> \$2::text -> \$3::text ELSE '[]'::jsonb END)
                        || jsonb_build_array(\$4::jsonb)),
                 true)
             WHERE id = \$1
             RETURNING id",
            [$npcId, self::PLUGIN_ID, $key, self::encode($item)]
        );
        return isset($row['id']);
    }

    /**
     * Append one item to a list key AND delete row $rowId of $table, in one statement (so
     * both happen or neither does, whatever kills the process in between): the append only
     * runs while that row exists (locked), the delete only when the append wrote. Returns
     * false when nothing was written (the row or the NPC is gone, or the statement failed).
     *
     * @param string $table a queue table name (identifier, not user input)
     */
    public static function appendItemConsumingRow(int $npcId, string $key, array $item, string $table, int $rowId): bool
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("RelDynStorage::appendItemConsumingRow: bad table name '{$table}'");
        }
        $row = self::db()->fetchOne(
            "WITH job AS (
                 SELECT id FROM {$table} WHERE id = \$5 FOR UPDATE
             ), app AS (
                 UPDATE core_npc_master
                 SET plugin_extended_data = jsonb_set(
                     plugin_extended_data,
                     ARRAY[\$2::text],
                     (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text) = 'object'
                           THEN plugin_extended_data -> \$2::text ELSE '{}'::jsonb END)
                     || jsonb_build_object(\$3::text,
                            (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text -> \$3::text) = 'array'
                                  THEN plugin_extended_data -> \$2::text -> \$3::text ELSE '[]'::jsonb END)
                            || jsonb_build_array(\$4::jsonb)),
                     true)
                 WHERE id = \$1 AND EXISTS (SELECT 1 FROM job)
                 RETURNING id
             )
             DELETE FROM {$table} AS q USING app WHERE q.id = \$5
             RETURNING q.id AS id",
            [$npcId, self::PLUGIN_ID, $key, self::encode($item), $rowId]
        );
        return isset($row['id']);
    }

    /**
     * Atomically return and remove every item of a list key. The row is locked for the
     * statement, so two overlapping consumers can never both receive the same item and an
     * append that commits first is always included.
     */
    public static function takeItems(int $npcId, string $key): array
    {
        $row = self::db()->fetchOne(
            "WITH cur AS (
                 SELECT id, plugin_extended_data -> \$2::text -> \$3::text AS inbox
                 FROM core_npc_master WHERE id = \$1 FOR UPDATE
             )
             UPDATE core_npc_master AS n
             SET plugin_extended_data = n.plugin_extended_data #- ARRAY[\$2::text, \$3::text]
             FROM cur
             WHERE n.id = cur.id
             RETURNING cur.inbox AS inbox",
            [$npcId, self::PLUGIN_ID, $key]
        );
        if (!isset($row['inbox']) || $row['inbox'] === '') {
            return [];
        }
        $items = json_decode($row['inbox'], true, 512, JSON_THROW_ON_ERROR);
        return (is_array($items) && array_is_list($items)) ? $items : [];
    }

    /**
     * Remove the first $count items of a list key (the ones a consumer peeked and applied);
     * items appended since stay. The key is removed when nothing is left. One statement.
     */
    public static function dropFirstItems(int $npcId, string $key, int $count): bool
    {
        if ($count <= 0) {
            return true;
        }
        $row = self::db()->fetchOne(
            "UPDATE core_npc_master
             SET plugin_extended_data = CASE
                 WHEN jsonb_array_length(plugin_extended_data -> \$2::text -> \$3::text) <= \$4::int
                     THEN plugin_extended_data #- ARRAY[\$2::text, \$3::text]
                 ELSE jsonb_set(plugin_extended_data, ARRAY[\$2::text, \$3::text],
                     (SELECT jsonb_agg(t.e ORDER BY t.i)
                      FROM jsonb_array_elements(plugin_extended_data -> \$2::text -> \$3::text) WITH ORDINALITY AS t(e, i)
                      WHERE t.i > \$4::int))
                 END
             WHERE id = \$1 AND jsonb_typeof(plugin_extended_data -> \$2::text -> \$3::text) = 'array'
             RETURNING id",
            [$npcId, self::PLUGIN_ID, $key, $count]
        );
        return isset($row['id']);
    }

    /**
     * pg advisory lock (two-int4 key space: INBOX_LOCK_CLASS, npc id) held by the one request
     * applying an NPC's eval inbox. Session-level: a request that dies drops its connection
     * and with it the lock. Returns false when another request holds it.
     */
    const INBOX_LOCK_CLASS = 1380218441;   // int4 constant, 'RDvI'

    public static function tryLockInbox(int $npcId): bool
    {
        $row = self::db()->fetchOne('SELECT pg_try_advisory_lock($1::int, $2::int) AS got', [self::INBOX_LOCK_CLASS, $npcId]);
        return in_array($row['got'] ?? null, ['t', true], true);
    }

    public static function unlockInbox(int $npcId): void
    {
        $row = self::db()->fetchOne('SELECT pg_advisory_unlock($1::int, $2::int) AS released', [self::INBOX_LOCK_CLASS, $npcId]);
        if (!in_array($row['released'] ?? null, ['t', true], true)) {
            error_log("[RelDyn] ERROR unlockInbox: eval inbox lock of npc {$npcId} was not held");
        }
    }

    /**
     * NPCs with stored dynamics whose calendar step is unset or at/before $dueBefore (raw
     * gamets), oldest first, at most $limit. One row per name, the lowest id, which is the
     * row resolveNpcId() picks. Returns [['id' => int, 'npc_name' => string], ...].
     */
    public static function dueForCalendar(float $dueBefore, int $limit): array
    {
        $plugin = self::PLUGIN_ID;
        $cal = self::KEY_CALENDAR;
        $dyn = self::KEY_DYNAMICS;
        $sql = sprintf(
            "SELECT id, npc_name FROM (
                 SELECT DISTINCT ON (lower(npc_name)) id, npc_name,
                        jsonb_typeof(plugin_extended_data -> '%s' -> '%s') = 'object' AS has_dynamics,
                        CASE WHEN jsonb_typeof(plugin_extended_data -> '%s' -> '%s' -> 'checked_gamets') = 'number'
                             THEN (plugin_extended_data -> '%s' -> '%s' ->> 'checked_gamets')::float8 END AS checked
                 FROM core_npc_master
                 ORDER BY lower(npc_name), id
             ) n
             WHERE has_dynamics AND (checked IS NULL OR checked <= %.6F)
             ORDER BY checked ASC NULLS FIRST, id
             LIMIT %d",
            $plugin, $dyn, $plugin, $cal, $plugin, $cal, $dueBefore, max(0, $limit)
        );
        $rows = self::db()->fetchAll($sql);
        if (!is_array($rows)) {
            throw new RuntimeException('RelDynStorage::dueForCalendar: query failed');
        }
        return array_map(fn($r) => ['id' => intval($r['id']), 'npc_name' => (string) $r['npc_name']], $rows);
    }

    /**
     * The whole 'reldyn' namespace for a compare-and-set replace (save-load reconcile).
     * Returns null when the NPC row does not exist, otherwise
     *   ['value' => assoc namespace or null when absent, 'expected' => its JSON or null].
     */
    public static function readNamespace(int $npcId): ?array
    {
        $row = self::db()->fetchOne(
            'SELECT id, (plugin_extended_data -> $2::text)::text AS ns FROM core_npc_master WHERE id = $1',
            [$npcId, self::PLUGIN_ID]
        );
        if (!isset($row['id'])) {
            return null;
        }
        $ns = $row['ns'] ?? null;
        if ($ns === null || $ns === '') {
            return ['value' => null, 'expected' => null];
        }
        return ['value' => self::toAssoc(json_decode($ns, false, 512, JSON_THROW_ON_ERROR)), 'expected' => $ns];
    }

    /**
     * Replace the whole 'reldyn' namespace only if it still holds $expected (from
     * readNamespace(), null = absent). One statement. False when another writer got in first.
     */
    public static function replaceNamespaceIfUnchanged(int $npcId, ?string $expected, array $namespace): bool
    {
        $row = self::db()->fetchOne(
            "UPDATE core_npc_master
             SET plugin_extended_data = jsonb_set(plugin_extended_data, ARRAY[\$2::text], \$3::jsonb, true)
             WHERE id = \$1 AND (plugin_extended_data -> \$2::text) IS NOT DISTINCT FROM \$4::jsonb
             RETURNING id",
            [$npcId, self::PLUGIN_ID, self::encode(empty($namespace) ? new stdClass() : $namespace), $expected]
        );
        return isset($row['id']);
    }

    /** Read a list key without consuming it. */
    public static function peekItems(int $npcId, string $key): array
    {
        $items = self::getAll($npcId)[$key] ?? [];
        return (is_array($items) && array_is_list($items)) ? $items : [];
    }
}
