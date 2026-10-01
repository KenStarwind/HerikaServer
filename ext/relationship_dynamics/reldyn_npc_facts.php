<?php
/**
 * Relationship Dynamics — tiered personality facts for core's NPC-to-NPC eval (roadmap
 * npc-npc-tiered-eval; the April design's tiers, ported onto core's own data-driven seam).
 *
 * Core's NPC-to-NPC eval (ext/relationship_system/relationship_llm.php evaluateNpcToNpcContext)
 * judges two NPCs' exchange on a flat prompt. It already asks conf_opts 'chim_character_facts_sources'
 * for extension-provided facts of BOTH parties (extensionCharacterFacts: a list of
 * {table, name_column, facts: {label: sql_expression}, skip_values}) and puts them in the user
 * prompt. RelDyn registers one source, so the eval of two NPCs knows who they are, in proportion to
 * how well the PLAYER knows each of them (the April tiers, by the NPC's own core affinity toward the
 * player; the seam is per NPC, so "the higher of the two" is each NPC's own tier):
 *
 *     below tier2_min (20)             nothing: a stranger to the player is a bare prompt
 *     tier2_min .. tier3_min (20-50)   temperament, attachment style, the top trait keywords
 *     above tier3_min (50)             adds the felt personality and the NPC's speech style
 *
 * The facts are SQL expressions over the NPC's own row, so the standalone relationship worker sees
 * them with no code hook: the tier is a CASE on extended_data.relationships.Player.aff, the words are
 * read from plugin_extended_data.reldyn.dynamics._npc_facts (RelDyn writes them at every prerequest,
 * from the trait vector and the attachment axes the engine already holds, so what the eval is told is
 * what the engine uses), the speech style is core's own speechstyle column. No numbers reach the
 * prompt, only words.
 *
 * REGISTRATION (install path and the settings switch): register() merges RelDyn's entry into the
 * conf_opts row without touching any other extension's entries (the entry carries owner =
 * 'relationship_dynamics'; an existing one is replaced, never duplicated); unregister() removes only
 * it; sync() does whichever the npc_npc_facts.enabled switch says. A row another extension wrote
 * that is not valid JSON is left alone (refused, logged). Core reads the row once per process, so
 * the long-lived relationship worker sees a change after its next start.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynNpcFacts
{
    const CONF_ID = 'chim_character_facts_sources';
    const OWNER = 'relationship_dynamics';
    /** dynamics key: the words the facts source reads. */
    const KEY = '_npc_facts';

    /** Trait code => [low word, high word] for the trait keywords (bands as RelDynTraits::TRAIT_BAND_EDGES). */
    const TRAIT_WORDS = [
        'G'  => ['open', 'guarded'],
        'E'  => ['reserved', 'expressive'],
        'C'  => ['unsure', 'self-assured'],
        'Pd' => ['modest', 'proud'],
        'Rs' => ['fragile', 'resilient'],
        'L'  => ['steady', 'reactive'],
        'W'  => ['cool', 'warm'],
        'D'  => ['impulsive', 'dutiful'],
        'Po' => ['unpossessive', 'possessive'],
        'Pr' => ['hands-off', 'protective'],
    ];

    // =====================================================================
    // CONFIG
    // =====================================================================

    public static function configDefaults(): array
    {
        return [
            'enabled' => true,
            'tier2_min' => 20,           // core affinity toward the player: from here, temperament / attachment / traits
            'tier3_min' => 50,           // core affinity toward the player: above here, the felt personality and speech style
            'trait_count' => 3,          // trait keywords in tier 2
            'speech_max_chars' => 200,   // characters of core's speechstyle in tier 3
        ];
    }

    /** The section as RelDyn reads it: the stored row laid over the defaults key by key. */
    public static function config(): array
    {
        $defaults = self::configDefaults();
        $stored = RelationshipDynamics::configValue('npc_npc_facts');
        return is_array($stored) ? array_replace($defaults, $stored) : $defaults;
    }

    /** The numbers the SQL and the words are built from: whole, in range, tier 3 never below tier 2. */
    public static function numbers(?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $t2 = max(-100, min(100, intval($cfg['tier2_min'])));
        return [
            'tier2_min' => $t2,
            'tier3_min' => max($t2, min(100, intval($cfg['tier3_min']))),
            'trait_count' => max(0, min(10, intval($cfg['trait_count']))),
            'speech_max_chars' => max(0, min(2000, intval($cfg['speech_max_chars']))),
        ];
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    // =====================================================================
    // THE WORDS (written into the NPC's state, read by the facts source)
    // =====================================================================

    /** The strongest trait keywords of a vector (most extreme first), at most $n; traits in the middle band are not named. */
    public static function traitKeywords(array $vector, int $n): array
    {
        $edges = RelDynTraits::TRAIT_BAND_EDGES;
        $cands = [];
        foreach (self::TRAIT_WORDS as $code => [$low, $high]) {
            $v = floatval($vector[$code] ?? 0.5);
            if ($v >= $edges['high']) $cands[] = [$v - 0.5, $high];
            elseif ($v <= $edges['low']) $cands[] = [0.5 - $v, $low];
        }
        usort($cands, fn($a, $b) => $b[0] <=> $a[0]);
        return array_slice(array_column($cands, 1), 0, max(0, $n));
    }

    /**
     * The facts' words from the NPC's own state: temperament, attachment style (the named region of
     * the two axes; the toxic corner reads "fearful"), the top trait keywords, the personality in
     * words (RelDynTraits::describe: no numbers). An entry is left out when the engine holds nothing for it.
     */
    public static function words(array $dynamics): array
    {
        $cfg = self::numbers();
        $out = [];
        $t = $dynamics['inferred_temperament'] ?? null;
        if (is_string($t) && trim($t) !== '') $out['temperament'] = trim($t);
        if (!empty(RelationshipDynamics::configValue('attachment_style_enabled'))) {
            $style = RelationshipDynamics::getAttachmentStyle($dynamics);
            $out['attachment'] = $style === 'toxic' ? 'fearful' : $style;
        }
        $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $dynamics);
        if (is_array($vector)) {
            $kw = self::traitKeywords($vector, $cfg['trait_count']);
            if ($kw !== []) $out['traits'] = implode(', ', $kw);
            $out['personality'] = RelDynTraits::describe($vector);
        }
        return $out;
    }

    /**
     * Keep the words current on the NPC's state (call before its save; prerequest does). Returns
     * true when they changed, so the caller knows the state is dirty.
     */
    public static function refresh(array &$dynamics): bool
    {
        if (!self::enabled()) {
            if (!array_key_exists(self::KEY, $dynamics)) return false;
            unset($dynamics[self::KEY]);
            return true;
        }
        $words = self::words($dynamics);
        if (($dynamics[self::KEY] ?? null) === $words) return false;
        $dynamics[self::KEY] = $words;
        return true;
    }

    // =====================================================================
    // THE FACTS SOURCE (the conf_opts entry)
    // =====================================================================

    /** SQL: the NPC's own core affinity toward the player (0 when it has none). */
    private static function affSql(): string
    {
        $aff = "extended_data -> 'relationships' -> '" . RelationshipDynamics::PLAYER_RELATIONSHIP_KEY . "' -> 'aff'";
        return "(CASE WHEN jsonb_typeof({$aff}) = 'number' THEN ({$aff} #>> '{}')::numeric ELSE 0 END)";
    }

    /** The entry core's extensionCharacterFacts reads (table, name_column, facts: label => SQL expression). */
    public static function sourceEntry(?array $cfg = null): array
    {
        $cfg = self::numbers($cfg);
        $aff = self::affSql();
        $words = "plugin_extended_data -> 'reldyn' -> 'dynamics' -> '" . self::KEY . "'";
        $t2 = intval($cfg['tier2_min']);
        $t3 = intval($cfg['tier3_min']);
        $word = fn(string $k) => "NULLIF({$words} ->> '{$k}', '')";
        $n = intval($cfg['speech_max_chars']);
        $facts = [
            'Temperament' => "CASE WHEN {$aff} >= {$t2} THEN {$word('temperament')} END",
            'Attachment style' => "CASE WHEN {$aff} >= {$t2} THEN {$word('attachment')} END",
            'Strongest traits' => "CASE WHEN {$aff} >= {$t2} THEN {$word('traits')} END",
            'Personality' => "CASE WHEN {$aff} > {$t3} THEN {$word('personality')} END",
        ];
        if ($n > 0) {
            $facts['Speech'] = "CASE WHEN {$aff} > {$t3} THEN NULLIF(left(btrim(regexp_replace(coalesce(speechstyle, ''), '\\s+', ' ', 'g')), {$n}), '') END";
        }
        return ['owner' => self::OWNER, 'table' => 'core_npc_master', 'name_column' => 'npc_name', 'facts' => $facts];
    }

    // =====================================================================
    // REGISTRATION (idempotent merge into conf_opts chim_character_facts_sources)
    // =====================================================================

    /** @return string added | updated | unchanged | refused */
    public static function register($db = null): string
    {
        return self::mutate($db, self::sourceEntry());
    }

    /** @return string removed | absent | refused */
    public static function unregister($db = null): string
    {
        return self::mutate($db, null);
    }

    /** Register or unregister by the npc_npc_facts.enabled switch. */
    public static function sync($db = null): string
    {
        return self::enabled() ? self::register($db) : self::unregister($db);
    }

    /** Replace RelDyn's entry with $entry (null: remove it), everything else in the row exactly as it was. */
    private static function mutate($db, ?array $entry): string
    {
        $db = $db ?? ($GLOBALS['db'] ?? null);
        if (!$db) {
            error_log('[RelDyn] ERROR npc-npc facts: no database connection');
            return 'refused';
        }
        $id = self::CONF_ID;
        $began = false;
        try {
            if ($db->execQuery('BEGIN') === false) throw new RuntimeException('BEGIN failed');
            $began = true;
            // the row is locked until COMMIT, so two registrations (or another extension's) cannot interleave
            $row = $db->fetchOne("SELECT value FROM conf_opts WHERE id = '{$id}' FOR UPDATE");
            $raw = is_array($row) && array_key_exists('value', $row) ? $row['value'] : null;
            $exists = is_array($row) && array_key_exists('value', $row);
            $list = [];
            if (is_string($raw) && trim($raw) !== '') {
                $list = json_decode($raw, true);
                if (!is_array($list)) {
                    $db->execQuery('ROLLBACK');
                    error_log("[RelDyn] ERROR npc-npc facts: conf_opts {$id} is not valid JSON; left as it is");
                    return 'refused';
                }
            }
            $isList = array_is_list($list);
            // find our entry: by owner in a list, by key in an object keyed by extension
            $at = null;
            foreach ($list as $k => $e) {
                if ($isList ? (is_array($e) && ($e['owner'] ?? null) === self::OWNER) : ($k === self::OWNER)) {
                    $at = $k;
                    break;
                }
            }
            if ($entry === null) {
                if ($at === null) {
                    $db->execQuery('ROLLBACK');
                    return 'absent';
                }
                unset($list[$at]);
                $list = $isList ? array_values($list) : $list;
                $status = 'removed';
            } elseif ($at === null) {
                if ($isList) $list[] = $entry; else $list[self::OWNER] = $entry;
                $status = 'added';
            } elseif ($list[$at] == $entry) {
                $db->execQuery('ROLLBACK');
                return 'unchanged';
            } else {
                $list[$at] = $entry;
                $status = 'updated';
            }
            $json = json_encode($list === [] ? [] : $list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $e = $db->escape($json);
            $res = $exists
                ? $db->execQuery("UPDATE conf_opts SET value = '{$e}' WHERE id = '{$id}'")
                : $db->execQuery("INSERT INTO conf_opts (id, value) VALUES ('{$id}', '{$e}') ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value");
            if ($res === false) throw new RuntimeException('writing conf_opts failed');
            if ($db->execQuery('COMMIT') === false) throw new RuntimeException('COMMIT failed');
            return $status;
        } catch (\Throwable $ex) {
            if ($began) $db->execQuery('ROLLBACK');
            RelationshipDynamics::logError('npc-npc facts registration', $ex);
            return 'refused';
        }
    }
}
