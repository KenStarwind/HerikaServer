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
 *   - setKey()     replaces one key          (dynamics saves)
 *   - appendItem() appends to a list key     (eval producers)
 *   - takeItems()  returns and removes a list key under a row lock (eval consumer)
 *
 * No process-level caching: every call reads the database, so long-lived processes
 * (the relationship worker daemon) never write from stale data.
 */

class RelDynStorage
{
    const PLUGIN_ID      = 'reldyn';
    const KEY_DYNAMICS   = 'dynamics';
    const KEY_EVAL_INBOX = 'eval_inbox';

    // Pre-3.4.1 location of the whole state blob (kept in place after migration).
    const LEGACY_EXTENDED_KEY = 'relationship_dynamics';

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

    public static function saveDynamics(int $npcId, array $dynamics): bool
    {
        return self::setKey($npcId, self::KEY_DYNAMICS, $dynamics);
    }

    /**
     * One-time, idempotent migration: copy extended_data.relationship_dynamics into
     * plugin_extended_data.reldyn.dynamics only when the plugin key is absent. The old key
     * is left untouched. Returns true when a copy happened.
     */
    public static function migrateLegacy(int $npcId): bool
    {
        $row = self::db()->fetchOne(
            "UPDATE core_npc_master
             SET plugin_extended_data = jsonb_set(
                 plugin_extended_data,
                 ARRAY[\$2::text],
                 (CASE WHEN jsonb_typeof(plugin_extended_data -> \$2::text) = 'object'
                       THEN plugin_extended_data -> \$2::text ELSE '{}'::jsonb END)
                 || jsonb_build_object(\$3::text, extended_data -> 'relationship_dynamics'),
                 true)
             WHERE id = \$1
               AND (plugin_extended_data -> \$2::text -> \$3::text) IS NULL
               AND jsonb_typeof(extended_data -> 'relationship_dynamics') = 'object'
               AND extended_data -> 'relationship_dynamics' <> '{}'::jsonb
             RETURNING id",
            [$npcId, self::PLUGIN_ID, self::KEY_DYNAMICS]
        );
        return isset($row['id']);
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

    /** Read a list key without consuming it. */
    public static function peekItems(int $npcId, string $key): array
    {
        $items = self::getAll($npcId)[$key] ?? [];
        return (is_array($items) && array_is_list($items)) ? $items : [];
    }
}
