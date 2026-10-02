<?php
/**
 * Relationship Dynamics — the per-NPC parameter editor (decisions 2026-09-23 §4, roadmap
 * full-parameter-editor; supersedes the April npc_editor_section.php / api_save_npc.php, which
 * no core page included on 3.4.1).
 *
 * Every RelDyn parameter of one NPC is viewable and editable: dimensions (raw, baseline, the value
 * as it reads toward the player, the part held by temporary states), the trait vector (10 traits,
 * the preset picker, and where each trait came from: the bio read's quote, the priors, a preset or
 * a hand-set vector; read-only), attachment axes, maturity type, trait tags, love languages,
 * facet preferences, intimacy need, the attraction profile (rigidity, weights, floors, lens share,
 * gate, openness, gender preference, beauty keywords), jealousy, resentment, the fulfillment
 * spider per relationship pair, flags and timers, and the active states (held offsets, grief,
 * concern, the two boundaries, walkaway, the passion spike). Numbers are for this page only
 * (decisions §3: "feelings not numbers" governs LLM text, not the UI).
 *
 * Every field shows the value in effect. Changing it writes a per-NPC override through the
 * engine's own API (setProfileOverride, RelDynFacets::setPreferenceOverride,
 * RelDynIntimacy::setNeedOverride, the attraction_overrides layer RelDynAttraction::definition
 * reads, setPassion / setJealousy / setCoreAffinityValue, resetWalkawayState, the fulfillment
 * pair API); a value posted unchanged is no edit (an untouched field stays derived). Reset
 * clears the override so the field follows its derivation again: per field, per section, or the
 * whole NPC (a fresh start). Every write is one getDynamics() copy saved with saveDynamics()
 * (the three-way merge with compare-and-set), never a raw blob.
 *
 * The page never queues a trait read (no LLM work from opening a page): each request opens a
 * RelDyn request scope and looks the read up with enqueue off first, so the profile resolution
 * inside getDynamics() finds it memoized.
 *
 * A form is a snapshot, the game keeps moving: every section form carries the values it showed
 * (one hidden 'snap' field), and a field posted back as shown is no edit even when the game has moved
 * that value since the page loaded (only what the user changed is written, so a save never reverts an
 * eval that landed meanwhile). Number inputs take any step (the browser would otherwise refuse
 * a value like 32.77 against step 0.1) and range inputs sit on the 0.01 grid their values are
 * shown at (a browser snaps a range value to its step, which would pin untouched sliders); the forms
 * are novalidate, the server is the validator (it names the field and the range).
 *
 * Security: writes are POST only with a per-session CSRF token (core's pattern,
 * ui/playthrough_manager.php: random_bytes token in the session, hash_equals), all output is
 * HTML-escaped (h()), the one list query escapes its search literal.
 *
 * Pure PHP; npc.php is the page (bootstrap, session, CHIM chrome) around handle().
 */

require_once __DIR__ . '/relationship_dynamics.php';
require_once __DIR__ . '/reldyn_ui_charts.php';

final class RelDynEditor
{
    const CSRF_SESSION_KEY = 'reldyn_editor_csrf';
    const FLASH_SESSION_KEY = 'reldyn_editor_flash';
    const LIST_LIMIT = 200;
    const PAGE = 'npc.php';

    /** Dimensions in display order (DIMENSION_DEFS). */
    const DIMENSIONS = ['affinity', 'passion', 'warmth', 'trust', 'comfort', 'respect', 'maturity', 'self_confidence',
        'resentment', 'resentment_self', 'arousal', 'valence', 'coord_m', 'coord_f'];

    const DIMENSION_LABELS = [
        'affinity' => 'Affinity', 'passion' => 'Passion', 'warmth' => 'Warmth', 'trust' => 'Trust', 'comfort' => 'Comfort',
        'respect' => 'Respect', 'maturity' => 'Maturity', 'self_confidence' => 'Self-confidence', 'resentment' => 'Resentment',
        'resentment_self' => 'Resentment (self)', 'arousal' => 'Arousal', 'valence' => 'Valence', 'coord_m' => 'M coordinate',
        'coord_f' => 'F coordinate',
    ];

    const SECTIONS = [
        'overview'    => 'Overview',
        'dimensions'  => 'Dimensions',
        'personality' => 'Personality traits',
        'attachment'  => 'Attachment',
        'love'        => 'Love languages & preference',
        'facets'      => 'Interests & facet preferences',
        'intimacy'    => 'Intimacy need',
        'attraction'  => 'Attraction profile',
        'jealousy'    => 'Jealousy',
        'resentment'  => 'Resentment',
        'fulfillment' => 'Fulfillment',
        'flags'       => 'Flags & timers',
        'states'      => 'Active states',
    ];

    /** The temporary-offset sources (RelationshipDynamics::heldTemporarySources), by name. */
    const HELD_SOURCES = [
        'creature' => 'Creature (night / moon)', 'physical' => 'Physical states', 'environment' => 'Environment',
        'guilt' => 'Guilt bleed', 'reputation' => 'Reputation heard', 'consumables' => 'Consumables',
        'grief' => 'Acute grief', 'post_intimacy' => 'Afterglow', 'weather' => 'Weather gravity', 'substances' => 'Drink / withdrawal',
    ];

    const STATE_LABELS = [
        'override' => 'override', 'derived' => 'derived', 'preset' => 'preset', 'edited' => 'edited', 'state' => 'state',
        'core' => 'core', 'legacy' => 'April editor', 'readonly' => 'read-only', 'differs' => 'not derived',
    ];

    // =====================================================================
    // SMALL HELPERS
    // =====================================================================

    /** HTML-escape anything for text and attribute context. */
    public static function h($v): string
    {
        if (is_bool($v)) $v = $v ? 'yes' : 'no';
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function num($v, int $decimals = 2): string
    {
        if (!is_numeric($v)) return '—';
        $s = number_format(round(floatval($v), $decimals), $decimals, '.', '');
        if ($decimals > 0) $s = rtrim(rtrim($s, '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    private static function decimalsOf($step): int
    {
        if (!is_numeric($step)) return 0;
        $s = rtrim(rtrim(number_format(floatval($step), 6, '.', ''), '0'), '.');
        $p = strpos($s, '.');
        return $p === false ? 0 : strlen($s) - $p - 1;
    }

    private static function gameDays($gamets): string
    {
        if (!is_numeric($gamets) || floatval($gamets) == 0.0) return '—';
        return self::num(floatval($gamets) / RelationshipDynamics::GAMETS_PER_DAY, 2) . ' game days';
    }

    /**
     * Core affinity toward the player (-100..100): RelDyn's mirror when it has one (with its
     * uncommitted change), else core's own relationships.Player.aff.
     */
    public static function coreAffinity(string $npc, array $d): float
    {
        if (is_numeric($d['_aff_mirror_x'] ?? null)) return RelationshipDynamics::getCoreAffinity($d);
        try {
            $rel = RelationshipDynamics::getPlayerRelationship($npc);
        } catch (Throwable $e) {
            RelationshipDynamics::logError("editor core affinity for {$npc}", $e);
            return 0.0;
        }
        return is_array($rel) && is_numeric($rel['aff'] ?? null) ? max(-100.0, min(100.0, floatval($rel['aff']))) : 0.0;
    }

    private static function pageUrl(?string $npc = null, ?string $anchor = null): string
    {
        $url = self::PAGE . ($npc !== null ? '?npc=' . rawurlencode($npc) : '');
        return $url . ($anchor !== null ? '#' . $anchor : '');
    }

    // =====================================================================
    // CSRF (core's pattern: ui/playthrough_manager.php)
    // =====================================================================

    public static function csrfToken(array &$session): string
    {
        if (empty($session[self::CSRF_SESSION_KEY]) || !is_string($session[self::CSRF_SESSION_KEY])) {
            $session[self::CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $session[self::CSRF_SESSION_KEY];
    }

    public static function csrfValid(array $session, $posted): bool
    {
        $token = $session[self::CSRF_SESSION_KEY] ?? null;
        return is_string($token) && $token !== '' && is_string($posted) && $posted !== '' && hash_equals($token, $posted);
    }

    // =====================================================================
    // NPC LIST
    // =====================================================================

    /**
     * NPCs of core_npc_master (one row per name, the lowest id as RelDynStorage resolves it),
     * RelDyn-tracked first, filtered by a case-insensitive substring of the name.
     *
     * @return array ['rows' => [['name', 'tracked', 'interactions', 'stage', 'core_type', 'temperament']], 'limited' => bool]
     */
    public static function listNpcs(string $search = '', int $limit = self::LIST_LIMIT): array
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return ['rows' => [], 'limited' => false];
        $where = '';
        $search = trim($search);
        if ($search !== '') {
            $like = '%' . strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $where = 'WHERE npc_name ILIKE ' . $db->escapeLiteral($like) . " ESCAPE '\\'";
        }
        $limit = max(1, min(1000, $limit));
        $sql = "SELECT * FROM (
                  SELECT DISTINCT ON (lower(npc_name)) npc_name,
                    (plugin_extended_data -> 'reldyn' -> 'dynamics') IS NOT NULL AS tracked,
                    plugin_extended_data -> 'reldyn' -> 'dynamics' ->> 'interaction_count' AS interactions,
                    plugin_extended_data -> 'reldyn' -> 'dynamics' ->> 'stage' AS stage,
                    plugin_extended_data -> 'reldyn' -> 'dynamics' ->> '_core_rel_type' AS core_type,
                    plugin_extended_data -> 'reldyn' -> 'dynamics' ->> 'inferred_temperament' AS temperament
                  FROM core_npc_master {$where}
                  ORDER BY lower(npc_name), id
                ) t
                ORDER BY tracked DESC, lower(npc_name)
                LIMIT " . ($limit + 1);
        try {
            $rows = $db->fetchAll($sql);
        } catch (Throwable $e) {
            RelationshipDynamics::logError('editor listNpcs', $e);
            return ['rows' => [], 'limited' => false];
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $tracked = $r['tracked'] ?? false;
            $out[] = [
                'name' => (string) ($r['npc_name'] ?? ''),
                'tracked' => $tracked === true || $tracked === 't' || $tracked === '1' || $tracked === 1,
                'interactions' => is_numeric($r['interactions'] ?? null) ? intval($r['interactions']) : null,
                'stage' => $r['stage'] ?? null,
                'core_type' => $r['core_type'] ?? null,
                'temperament' => $r['temperament'] ?? null,
            ];
        }
        $limited = count($out) > $limit;
        return ['rows' => array_slice($out, 0, $limit), 'limited' => $limited];
    }

    /** The NPC's name as core stores it, or null when core has no such NPC. */
    public static function canonicalName(string $npc): ?string
    {
        $db = $GLOBALS['db'] ?? null;
        $npc = trim($npc);
        if (!$db || $npc === '') return null;
        $row = $db->fetchOne('SELECT npc_name FROM core_npc_master WHERE lower(npc_name) = lower($1) ORDER BY id LIMIT 1', [$npc]);
        return is_array($row) && isset($row['npc_name']) ? (string) $row['npc_name'] : null;
    }

    // =====================================================================
    // SCOPE
    // =====================================================================

    /** Run $fn inside a RelDyn request scope with the NPC's trait read looked up without queueing. */
    private static function scoped(string $npc, callable $fn)
    {
        RelationshipDynamics::beginRequest();
        try {
            RelDynTraitRead::stateFor($npc, false);   // memoized: the profile resolution queues nothing
            return $fn();
        } finally {
            RelationshipDynamics::endRequest();
        }
    }

    // =====================================================================
    // MODEL
    // =====================================================================

    /**
     * The editor's view of one NPC: sections of fields (value in effect, derivation, state) and
     * read-only blocks. Null when core has no such NPC. Reads only.
     */
    public static function model(string $npc, ?float $now = null): ?array
    {
        $name = self::canonicalName($npc);
        if ($name === null) return null;
        return self::scoped($name, function () use ($name, $now) {
            $d = RelationshipDynamics::getDynamics($name);
            $now = $now ?? RelationshipDynamics::currentGamets();
            $cat = self::catalog($name, $d, $now);
            foreach ($cat['sections'] as &$s) {
                foreach ($s['fields'] as &$f) {
                    $f['editable'] = $f['set'] !== null;
                    $f['resettable'] = $f['reset'] !== null;
                    unset($f['set'], $f['reset']);
                }
                unset($f);
                $s['resettable'] = $s['reset'] !== null;
                unset($s['reset']);
            }
            unset($s);
            return $cat;
        });
    }

    private static function field(string $id, string $label, string $type, $value, array $opt = []): array
    {
        return $opt + [
            'id' => $id, 'label' => $label, 'type' => $type, 'value' => $value,
            'state' => 'state', 'derived' => null, 'min' => null, 'max' => null, 'step' => null,
            'options' => [], 'hint' => '', 'set' => null, 'reset' => null, 'group' => null, 'col' => null,
        ];
    }

    private static function section(string $id, array $fields, array $opt = []): array
    {
        $out = $opt + ['id' => $id, 'title' => self::SECTIONS[$id] ?? $id, 'intro' => '', 'blocks' => [], 'reset' => null, 'reset_label' => 'Reset section'];
        $out['fields'] = [];
        foreach ($fields as $f) $out['fields'][$f['id']] = $f;
        return $out;
    }

    /** Number setter clamped to the field's range (null = accepted). */
    private static function numberSetter(callable $write, float $min, float $max): callable
    {
        return function (array &$d, $v) use ($write, $min, $max): ?string {
            if (!is_numeric($v)) return 'not a number';
            $v = floatval($v);
            if ($v < $min || $v > $max || is_nan($v)) return "outside {$min}..{$max}";
            $write($d, $v);
            return null;
        };
    }

    /**
     * Every section with its fields: value in effect, derived value, override state, and the
     * set / reset closures apply() calls on a getDynamics() copy.
     */
    private static function catalog(string $npc, array $d, float $now): array
    {
        $jev = RelDynJev::state($npc, $d, $now);
        $sections = [];
        $sections['overview'] = self::overviewSection($npc, $d, $jev);
        $sections['dimensions'] = self::dimensionSection($npc, $d);
        $sections['personality'] = self::personalitySection($npc, $d);
        $sections['attachment'] = self::attachmentSection($d, $jev);
        $sections['love'] = self::loveSection($npc, $d);
        $sections['facets'] = self::facetSection($npc, $d);
        $sections['intimacy'] = self::intimacySection($npc, $d);
        $sections['attraction'] = self::attractionSection($npc, $d, $jev);
        $sections['jealousy'] = self::jealousySection($d);
        $sections['resentment'] = self::resentmentSection($d, $jev);
        $sections['fulfillment'] = self::fulfillmentSection($npc, $d, $now);
        $sections['flags'] = self::flagSection($d);
        $sections['states'] = self::stateSection($d, $jev, $now);
        $stored = RelationshipDynamics::loadStoredDynamics($npc);
        return ['npc' => $npc, 'tracked' => is_array($stored) && $stored !== [], 'now' => $now, 'jev_text' => $jev['text'], 'sections' => $sections];
    }

    // ---- overview -------------------------------------------------------

    private static function overviewSection(string $npc, array $d, array $jev): array
    {
        $ro = fn(string $id, string $label, $v, string $hint = '') => self::field("ov:{$id}", $label, 'readonly', $v, ['state' => 'readonly', 'hint' => $hint]);
        $pref = trim((string) ($d['relationship_preference'] ?? ''));
        return self::section('overview', [
            $ro('type', 'Relationship type (RelDyn)', $jev['relationship_type']),
            $ro('core_type', 'Core relationship type', $jev['core_type'] ?? '—', 'core owns it (relationships.Player.type)'),
            $ro('affinity', 'Core affinity', self::num($aff = self::coreAffinity($npc, $d), 1) . ' (' . RelationshipDynamics::getCurrentTier($aff) . ')', 'core units -100..100; edit it under Dimensions'),
            $ro('interactions', 'Interactions (lifetime)', RelationshipDynamics::interactionClock($d) . ' total, ' . intval($d['total_positive_interactions'] ?? 0) . ' positive'),
            $ro('stage', 'Stage', (string) ($d['stage'] ?? RelationshipDynamics::STAGE_EARLY)),
            $ro('context_tier', 'Context tier', $jev['context_tier'] . ' (high-water ' . intval($d['context_tier_hwm'] ?? 0) . ')'),
            $ro('temperament', 'Temperament (nearest preset)', $jev['temperament'] ?? '—'),
            $ro('warmth', 'Warmth', self::num($jev['warmth'], 1), 'derived: sqrt(effective passion x comfort) plus held states; read-only'),
            $ro('weather', 'Internal weather', $jev['weather']),
            $ro('walkaway', 'Walkaway', $jev['walkaway']),
            $ro('autonomy', 'Autonomy', $jev['autonomy']['state'] . ' (' . self::num($jev['autonomy']['score'], 1) . ')'),
            $ro('preference', 'Relationship preference', $pref !== '' ? $pref : 'none'),
        ], ['intro' => 'Where the relationship stands now. Numbers are for this page only; the dialogue model is steered by feelings, never by these values.']);
    }

    // ---- dimensions -----------------------------------------------------

    private static function dimensionSection(string $npc, array $d): array
    {
        $temperament = RelationshipDynamics::validTemperament($d['inferred_temperament'] ?? null) ?? 'Stoic';
        $read = RelDynTraits::readVector($d) !== null;
        $derivedWarmth = RelDynPassion::derivedWarmthEnabled();
        $fields = [];
        $resetDims = [];
        foreach (self::DIMENSIONS as $dim) {
            $def = RelationshipDynamics::getDimensionDefinition($dim);
            if (!$def) continue;
            $min = floatval($def['range_min']);
            $max = floatval($def['range_max']);
            $label = self::DIMENSION_LABELS[$dim] ?? $dim;
            $x = $d['dimensions'][$dim]['x'] ?? null;
            $baseline = $d['dimensions'][$dim]['baseline'] ?? null;
            $effective = RelationshipDynamics::getEffectiveDimensionValue($d, $dim);
            $held = $dim === 'affinity' ? 0.0 : RelationshipDynamics::heldTemporaryOffset($d, $dim);
            $derived = floatval(RelationshipDynamics::getTemperamentBaseline($temperament, $dim, $read ? $d : null));
            $common = ['group' => $dim, 'effective' => $effective, 'held' => $held];

            if ($dim === 'affinity') {
                $core = self::coreAffinity($npc, $d);
                $fields[] = self::field('dim:affinity:x', $label, 'number', round($core, 1), ['effective' => $core] + $common + [
                    'col' => 'x', 'state' => 'core', 'min' => -100, 'max' => 100, 'step' => 0.1,
                    'hint' => 'core affinity (-100..100); RelDyn commits the change to core on the NPC\'s next turn',
                    'set' => self::numberSetter(function (array &$dd, float $v) use ($npc) {
                        // no mirror yet (RelDyn never ran her turn): mirror core's value first, so only the edit is committed
                        if (!is_numeric($dd['_aff_mirror_x'] ?? null)) RelationshipDynamics::refreshAffinityMirror($dd, self::coreAffinity($npc, $dd));
                        RelationshipDynamics::setCoreAffinityValue($dd, $v);
                    }, -100, 100),
                ]);
                $fields[] = self::field('dim:affinity:baseline', $label, 'readonly', $baseline, $common + ['col' => 'baseline', 'state' => 'readonly']);
                continue;
            }
            if ($dim === 'warmth' && $derivedWarmth) {
                $fields[] = self::field('dim:warmth:x', $label, 'readonly', $effective, $common + [
                    'col' => 'x', 'state' => 'readonly', 'hint' => 'derived from passion and comfort (roadmap derived-warmth)']);
                $fields[] = self::field('dim:warmth:baseline', $label, 'readonly', $baseline, $common + ['col' => 'baseline', 'state' => 'readonly']);
                continue;
            }
            if ($dim === 'passion') {
                $fields[] = self::field('dim:passion:x', $label, 'number', round(RelationshipDynamics::getPassion($d), 2), $common + [
                    'col' => 'x', 'state' => 'state', 'min' => 0, 'max' => 100, 'step' => 0.1, 'derived' => 0.0,
                    'hint' => 'the floor earned through play; the spike rides on top (Active states)',
                    'set' => self::numberSetter(fn(array &$dd, float $v) => RelationshipDynamics::setPassion($dd, $v), 0, 100),
                    'reset' => function (array &$dd) { RelationshipDynamics::setPassion($dd, 0.0); },
                ]);
                $fields[] = self::field('dim:passion:baseline', $label, 'readonly', $baseline, $common + ['col' => 'baseline', 'state' => 'readonly']);
                $resetDims[] = 'passion';
                continue;
            }
            // a stored baseline off the derivation: edited, drifted, or seeded before the traits moved
            $bState = is_numeric($baseline) && abs(floatval($baseline) - $derived) > 0.005 ? 'differs' : 'derived';
            $fields[] = self::field("dim:{$dim}:x", $label, 'number', is_numeric($x) ? round(floatval($x), 2) : null, $common + [
                'col' => 'x', 'state' => 'state', 'min' => $min, 'max' => $max, 'step' => 0.1,
                'derived' => is_numeric($baseline) ? floatval($baseline) : $derived,
                'hint' => 'raw stored value (includes the held part); reset returns it to its baseline',
                'set' => self::numberSetter(function (array &$dd, float $v) use ($dim) { $dd['dimensions'][$dim]['x'] = $v; }, $min, $max),
                'reset' => function (array &$dd) use ($dim, $derived) {
                    $b = $dd['dimensions'][$dim]['baseline'] ?? null;
                    $dd['dimensions'][$dim]['x'] = is_numeric($b) ? floatval($b) : $derived;
                },
            ]);
            $fields[] = self::field("dim:{$dim}:baseline", $label, 'number', is_numeric($baseline) ? round(floatval($baseline), 2) : null, $common + [
                'col' => 'baseline', 'state' => $bState, 'min' => $min, 'max' => $max, 'step' => 0.1, 'derived' => $derived,
                'hint' => 'where the dimension rests; derived from the traits / temperament (drift and edits move it)',
                'set' => self::numberSetter(function (array &$dd, float $v) use ($dim) { $dd['dimensions'][$dim]['baseline'] = $v; }, $min, $max),
                'reset' => function (array &$dd) use ($dim, $derived) { $dd['dimensions'][$dim]['baseline'] = $derived; },
            ]);
            $resetDims[] = $dim;
        }
        $reset = function (array &$dd) use ($resetDims, $temperament, $read) {
            foreach ($resetDims as $dim) {
                if ($dim === 'passion') {
                    RelationshipDynamics::setPassion($dd, 0.0);
                    continue;
                }
                $b = floatval(RelationshipDynamics::getTemperamentBaseline($temperament, $dim, $read ? $dd : null));
                $dd['dimensions'][$dim]['x'] = $b;
                $dd['dimensions'][$dim]['baseline'] = $b;
            }
        };
        return self::section('dimensions', $fields, [
            'intro' => 'Raw is the stored value; "toward player" is how it reads in this bond (per-bond display multiplier, never stored); '
                . '"held" is the part temporary states hold on it right now. Section reset puts every dimension back on its derived '
                . 'baseline (affinity stays: core owns it).',
            'reset' => $reset, 'reset_label' => 'Reset all dimensions to derived baselines',
        ]);
    }

    // ---- personality ----------------------------------------------------

    private static function personalitySection(string $npc, array $d): array
    {
        $over = (array) ($d['profile_overrides'] ?? []);
        $auto = (array) ($d['_profile_autogen'] ?? []);
        $src = is_array($d['_trait_vector_src'] ?? null) ? $d['_trait_vector_src'] : [];
        $vector = RelDynTraits::vectorFor(RelDynTraits::FROM_DYNAMICS, $d);
        $temps = RelationshipDynamics::TEMPERAMENT_TYPES;
        $fields = [];

        $current = RelationshipDynamics::validTemperament($d['inferred_temperament'] ?? null);
        $fields[] = self::field('prof:temperament', 'Temperament preset', 'select', $current ?? '', [
            'options' => ['' => '(none yet)'] + array_combine($temps, $temps),
            'state' => isset($over['temperament']) ? 'override' : (($auto['temperament_source'] ?? null) === 'preset' ? 'preset' : 'derived'),
            'derived' => $auto['base_temperament'] ?? null,
            'hint' => 'picking a preset moves the whole vector to that preset point; per-trait sliders still win',
            'set' => function (array &$dd, $v): ?string {
                if ($v === '') return 'pick a preset (reset clears the override)';
                return RelationshipDynamics::setProfileOverride($dd, 'temperament', $v) ? null : 'unknown preset';
            },
            'reset' => function (array &$dd) { RelationshipDynamics::setProfileOverride($dd, 'temperament', null); },
        ]);

        $autoVec = is_array($src['auto'] ?? null) ? $src['auto'] : [];
        $traitSrc = is_array($src['traits'] ?? null) ? $src['traits'] : [];
        foreach (RelDynTraits::TRAITS as $code => $name) {
            $value = $vector !== null ? floatval($vector[$code] ?? 0.5) : null;
            $o = $over['trait_vector'][$name] ?? null;
            $s = (array) ($traitSrc[$name] ?? []);
            $fields[] = self::field("trait:{$name}", ucfirst($name), 'range', $value !== null ? round($value, 2) : null, [
                'min' => 0, 'max' => 1, 'step' => 0.01, 'group' => 'trait',
                'state' => $o !== null ? 'override' : (($s['source'] ?? null) === 'preset' ? 'preset' : 'derived'),
                'derived' => is_numeric($autoVec[$name] ?? null) ? floatval($autoVec[$name]) : null,
                'source' => $s,
                'set' => function (array &$dd, $v) use ($name): ?string {
                    if (!is_numeric($v) || floatval($v) < 0.0 || floatval($v) > 1.0) return 'outside 0..1';
                    $map = (array) ($dd['profile_overrides']['trait_vector'] ?? []);
                    $map[$name] = floatval($v);
                    return RelationshipDynamics::setProfileOverride($dd, 'trait_vector', $map) ? null : 'rejected';
                },
                'reset' => function (array &$dd) use ($name) {
                    $map = (array) ($dd['profile_overrides']['trait_vector'] ?? []);
                    unset($map[$name]);
                    RelationshipDynamics::setProfileOverride($dd, 'trait_vector', $map === [] ? null : $map);
                },
            ]);
        }
        $fields[] = self::field('prof:maturity_start', 'Starting maturity', 'readonly',
            $vector !== null && isset($vector['maturity_start']) ? self::num($vector['maturity_start'], 1) : '—', ['state' => 'readonly',
            'hint' => 'from the bio read, a preset, or the restraint / resilience model']);

        $mt = $d['dimensions']['maturity']['plasticity_type'] ?? null;
        $types = array_keys(RelationshipDynamics::MATURITY_PLASTICITY_VALUES);
        $fields[] = self::field('prof:maturity_type', 'Maturity type', 'select', is_string($mt) ? $mt : '', [
            'options' => ['' => '(none yet)'] + array_combine($types, $types),
            'state' => isset($over['maturity_type']) ? 'override' : (($auto['maturity_type_source'] ?? null) === 'preset' ? 'preset' : 'derived'),
            'derived' => $auto['auto']['maturity_type'] ?? null,
            'set' => function (array &$dd, $v): ?string {
                if ($v === '') return 'pick a type (reset clears the override)';
                return RelationshipDynamics::setProfileOverride($dd, 'maturity_type', $v) ? null : 'unknown maturity type';
            },
            'reset' => function (array &$dd) { RelationshipDynamics::setProfileOverride($dd, 'maturity_type', null); },
        ]);

        $vocab = array_map('strtolower', (array) (RelationshipDynamics::getTemperamentAutogenConfig()['trait_vocabulary'] ?? []));
        $tags = RelationshipDynamics::getTraits($d);
        $fields[] = self::field('prof:traits', 'Trait tags', 'text', implode(', ', $tags), [
            'state' => array_key_exists('traits', $over) ? 'override' : (($auto['traits_source'] ?? null) === 'preset' ? 'preset' : 'derived'),
            'derived' => implode(', ', (array) ($auto['auto']['traits'] ?? [])),
            'hint' => 'comma-separated; known tags: ' . implode(', ', $vocab) . ' (empty = no tags)',
            'set' => function (array &$dd, $v): ?string {
                $list = array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn($t) => $t !== ''));
                return RelationshipDynamics::setProfileOverride($dd, 'traits', $list) ? null : 'unknown tag';
            },
            'reset' => function (array &$dd) { RelationshipDynamics::setProfileOverride($dd, 'traits', null); },
        ]);

        $nearest = $vector !== null ? RelDynTraits::nearestPreset($vector) : null;
        $readState = RelDynTraitRead::stateFor($npc, false);
        $blocks = [['type' => 'trait_sources', 'assignment' => $src['assignment'] ?? RelDynTraits::assignment(),
            'label_source' => $src['auto_source'] ?? null, 'read_status' => $src['read_status'] ?? $readState['status'],
            'template_key' => $src['template_key'] ?? $readState['key'], 'model' => $src['model'] ?? $readState['model'],
            'nearest' => $nearest, 'traits' => $traitSrc, 'screened' => ($readState['status'] ?? null) === 'skip']];
        // when the profile was last read, a queued re-read, the history, and the "read again" button (op reread)
        $blocks[] = ['type' => 'reingest'] + RelDynTraitReingest::describe($npc, $d, RelationshipDynamics::currentGamets());

        $reset = function (array &$dd) {
            foreach (['trait_vector', 'temperament', 'maturity_type', 'traits'] as $f) RelationshipDynamics::setProfileOverride($dd, $f, null);
        };
        return self::section('personality', $fields, [
            'intro' => 'The trait vector (0..1 per trait) drives baselines, plasticity, openness and more. The value shown is the one in effect; '
                . 'moving a slider stores a per-trait override. Where each trait came from is shown read-only below.',
            'blocks' => $blocks, 'reset' => $reset, 'reset_label' => 'Clear all personality overrides',
        ]);
    }

    // ---- attachment -----------------------------------------------------

    private static function attachmentSection(array $d, array $jev): array
    {
        $axes = RelationshipDynamics::getAttachmentAxes($d);
        $base = (array) ($axes['base'] ?? []);
        $over = (array) ($d['profile_overrides'] ?? []);
        $hasOver = isset($over['attachment_axes']) || isset($over['attachment_style']);
        $autoAxes = (array) ($d['_profile_autogen']['attachment'] ?? []);
        $fields = [];
        foreach (RelationshipDynamics::ATTACHMENT_AXES as $axis) {
            $other = $axis === 'anxiety' ? 'avoidance' : 'anxiety';
            $fields[] = self::field("att:{$axis}", ucfirst($axis) . ' (base)', 'number', round(floatval($base[$axis] ?? 0.5), 3), [
                'min' => 0, 'max' => 1, 'step' => 0.01,
                'state' => $hasOver ? 'override' : (($axes['source'] ?? null) === 'preset' ? 'preset' : 'derived'),
                'derived' => is_numeric($autoAxes[$axis] ?? null) ? floatval($autoAxes[$axis]) : null,
                'hint' => $axis === 'anxiety' ? 'fear of abandonment' : 'discomfort with closeness once someone is in',
                'set' => function (array &$dd, $v) use ($axis, $other): ?string {
                    if (!is_numeric($v) || floatval($v) < 0 || floatval($v) > 1) return 'outside 0..1';
                    $b = (array) (RelationshipDynamics::getAttachmentAxes($dd)['base'] ?? []);
                    $pair = [$axis => floatval($v), $other => floatval($b[$other] ?? 0.5)];
                    return RelationshipDynamics::setProfileOverride($dd, 'attachment_axes', $pair) ? null : 'rejected';
                },
                'reset' => function (array &$dd) {
                    RelationshipDynamics::setProfileOverride($dd, 'attachment_axes', null);
                    RelationshipDynamics::setProfileOverride($dd, 'attachment_style', null);
                },
            ]);
        }
        $styles = RelationshipDynamics::ATTACHMENT_STYLE_TYPES;
        $baseStyle = RelationshipDynamics::attachmentStyleOf(floatval($base['anxiety'] ?? 0.5), floatval($base['avoidance'] ?? 0.5));
        $fields[] = self::field('att:style', 'Style (base region)', 'select', $baseStyle, [
            'options' => array_combine($styles, array_map(fn($s) => $s === 'toxic' ? 'toxic (fearful)' : $s, $styles)),
            'state' => isset($over['attachment_style']) ? 'override' : ($hasOver ? 'override' : 'derived'),
            'hint' => 'picking a style sets both axes to its textbook point',
            'set' => fn(array &$dd, $v): ?string => RelationshipDynamics::setProfileOverride($dd, 'attachment_style', $v) ? null : 'unknown style',
            'reset' => function (array &$dd) {
                RelationshipDynamics::setProfileOverride($dd, 'attachment_axes', null);
                RelationshipDynamics::setProfileOverride($dd, 'attachment_style', null);
            },
        ]);
        $drift = $d[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] ?? null;
        $driftText = is_array($drift)
            ? 'anxiety ' . self::num($drift['anxiety'] ?? 0, 3) . ', avoidance ' . self::num($drift['avoidance'] ?? 0, 3)
            : 'none';
        $fields[] = self::field('att:drift', 'Drift from experience', 'readonly', $driftText, ['state' => 'state',
            'hint' => 'lived experience moves the axes slowly; reset forgets it',
            'reset' => function (array &$dd) { $dd[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] = null; }]);
        $fields[] = self::field('att:effective', 'In effect now', 'readonly',
            'anxiety ' . self::num($jev['attachment_anxiety'], 3) . ', avoidance ' . self::num($jev['attachment_avoidance'], 3) . ' (' . $jev['attachment'] . ')',
            ['state' => 'readonly', 'hint' => 'source: ' . (string) ($axes['source'] ?? '?')]);
        return self::section('attachment', $fields, [
            'intro' => 'Two axes (Fraley & Shaver). The base comes from traits, text and losses unless overridden; drift is added on top.',
            'reset' => function (array &$dd) {
                RelationshipDynamics::setProfileOverride($dd, 'attachment_axes', null);
                RelationshipDynamics::setProfileOverride($dd, 'attachment_style', null);
                $dd[RelationshipDynamics::ATTACHMENT_DRIFT_KEY] = null;
            },
            'reset_label' => 'Clear the override and the drift',
        ]);
    }

    // ---- love languages & preference ------------------------------------

    private static function loveSection(string $npc, array $d): array
    {
        $lls = RelDynFulfillment::LOVE_LANGUAGES;
        $llOptions = array_combine($lls, array_map(fn($l) => str_replace('_', ' ', $l), $lls));
        $probe = $d;
        unset($probe['love_language_primary'], $probe['love_language_secondary'], $probe['warmth_curve']);
        $derived = ['love_language_primary' => null, 'love_language_secondary' => null, 'warmth_curve' => null];
        try {
            RelationshipDynamics::ensureLoveLanguage($npc, $probe);
            foreach ($derived as $k => $_) $derived[$k] = $probe[$k] ?? null;
        } catch (Throwable $e) {
            RelationshipDynamics::logError('editor love language derivation', $e);
        }
        $fields = [];
        $plain = function (string $key, string $label, array $options, string $hint = '') use ($d, $derived): array {
            $v = $d[$key] ?? null;
            return self::field("love:{$key}", $label, 'select', is_string($v) ? $v : '', [
                'options' => ['' => '(not set)'] + $options,
                'state' => $v === null ? 'derived' : ($v === $derived[$key] ? 'derived' : 'edited'),
                'derived' => $derived[$key], 'hint' => $hint,
                'set' => function (array &$dd, $val) use ($key, $options): ?string {
                    if (!array_key_exists($val, $options)) return 'unknown value';
                    $dd[$key] = $val;
                    return null;
                },
                'reset' => function (array &$dd) use ($key, $derived) { $dd[$key] = $derived[$key]; },
            ]);
        };
        $fields[] = $plain('love_language_primary', 'Primary love language', $llOptions, 'gains x2.0 (MDD 1.2)');
        $fields[] = $plain('love_language_secondary', 'Secondary love language', $llOptions, 'gains x1.5');
        $curves = array_keys(RelationshipDynamics::CURVE_PARAMS);
        $fields[] = $plain('warmth_curve', 'Warmth curve', array_combine($curves, $curves));

        $prefs = RelDynAttraction::PREFERENCES;
        $pref = trim((string) ($d['relationship_preference'] ?? ''));
        $fields[] = self::field('love:relationship_preference', 'Relationship preference', 'select', $pref, [
            'options' => ['' => 'none (no filter)'] + array_combine($prefs, array_map(fn($p) => str_replace('_', ' ', $p), $prefs)),
            'state' => $pref === '' ? 'derived' : 'override', 'derived' => '',
            'hint' => 'filters the romance axis (Attraction Matrix type filter); asexual closes the physical intimacy need',
            'set' => function (array &$dd, $v) use ($prefs): ?string {
                if ($v === '') { $dd['relationship_preference'] = null; return null; }
                if (!in_array($v, $prefs, true)) return 'unknown preference';
                $dd['relationship_preference'] = $v;
                return null;
            },
            'reset' => function (array &$dd) { $dd['relationship_preference'] = null; },
        ]);
        $temperament = RelationshipDynamics::validTemperament($d['inferred_temperament'] ?? null) ?? 'Stoic';
        $probe2 = $d;
        unset($probe2['social_sensitivity_curve']);
        $sscDerived = RelationshipDynamics::getSocialSensitivityCurve($temperament, $probe2);
        $ssc = ['inner_circle', 'open_heart', 'uniform', 'inverse_tolerance', 'romantic_mid'];
        $cur = trim((string) ($d['social_sensitivity_curve'] ?? ''));
        $fields[] = self::field('love:social_sensitivity_curve', 'Social sensitivity curve', 'select', $cur !== '' ? $cur : $sscDerived, [
            'options' => array_combine($ssc, array_map(fn($s) => str_replace('_', ' ', $s), $ssc)),
            'state' => $cur !== '' ? 'override' : 'derived', 'derived' => $sscDerived,
            'hint' => 'how much the bond depth scales what the NPC lets in',
            'set' => function (array &$dd, $v) use ($ssc): ?string {
                if (!in_array($v, $ssc, true)) return 'unknown curve';
                $dd['social_sensitivity_curve'] = $v;
                return null;
            },
            'reset' => function (array &$dd) { unset($dd['social_sensitivity_curve']); },
        ]);
        return self::section('love', $fields, [
            'intro' => 'Love languages are generated once (MARAS / Sharmat / race) and stored; changing one is an edit, reset regenerates it.',
            'reset' => function (array &$dd) use ($derived) {
                foreach ($derived as $k => $v) $dd[$k] = $v;
                $dd['relationship_preference'] = null;
                unset($dd['social_sensitivity_curve']);
            },
        ]);
    }

    // ---- facets ---------------------------------------------------------

    private static function facetSection(string $npc, array $d): array
    {
        $prefs = RelDynFacets::preferences($d, $npc);
        $bare = $d;
        $bare['facet_pref_overrides'] = [];
        $derived = RelDynFacets::preferences($bare, $npc);
        $over = (array) ($d['facet_pref_overrides'] ?? []);
        $fields = [];
        foreach (RelDynFacets::FACETS as $facet) {
            $isInterest = in_array($facet, RelDynFacets::INTERESTS, true);
            $v = floatval($prefs[$facet] ?? 0.0);
            $fields[] = self::field("facet:{$facet}", ucfirst($facet), 'range', round($v, 2), [
                'min' => -1, 'max' => 1, 'step' => 0.05, 'group' => $isInterest ? 'interest' : 'situational',
                'state' => array_key_exists($facet, $over) ? 'override' : 'derived',
                'derived' => round(floatval($derived[$facet] ?? 0.0), 3),
                'hint' => $isInterest ? 'interest multiplier x' . self::num(RelDynFacets::interestMultiplier($v), 2) . ' (MDD 1.2)' : 'situational facet',
                'set' => function (array &$dd, $val) use ($facet): ?string {
                    if (!is_numeric($val)) return 'not a number';
                    return RelDynFacets::setPreferenceOverride($dd, $facet, floatval($val)) ? null : 'outside -1..+1';
                },
                'reset' => function (array &$dd) use ($facet) { RelDynFacets::setPreferenceOverride($dd, $facet, null); },
            ]);
        }
        return self::section('facets', $fields, [
            'intro' => 'Signed preferences, -1 (hates) to +1 (loves), derived from class, skills and traits. They drive place and gift '
                . 'appraisal, the attraction lens and the fulfillment needs.',
            'reset' => function (array &$dd) { $dd['facet_pref_overrides'] = []; },
            'reset_label' => 'Clear every facet override',
        ]);
    }

    // ---- intimacy need --------------------------------------------------

    private static function intimacySection(string $npc, array $d): array
    {
        // The need as her next turn would store it (on a copy, reads only): race / creature from
        // core and her love languages are inputs, which need()'s stateless fallback leaves out.
        $probe = $d;
        try {
            RelationshipDynamics::ensureLoveLanguage($npc, $probe);
            RelDynIntimacy::ensureNeed($npc, $probe);
        } catch (Throwable $e) {
            RelationshipDynamics::logError('editor intimacy need derivation', $e);
        }
        $need = RelDynIntimacy::need($probe);
        $bare = $probe;
        unset($bare['intimacy_need_overrides']);
        $derived = RelDynIntimacy::need($bare);
        $over = (array) ($d['intimacy_need_overrides'] ?? []);
        $fields = [];
        foreach (RelDynIntimacy::KEYS as $axis => $_) {
            $fields[] = self::field("need:{$axis}", ucfirst($axis) . ' need', 'number', round(floatval($need[$axis] ?? 0), 3), [
                'min' => 0, 'max' => 1, 'step' => 0.01,
                'state' => array_key_exists($axis, $over) ? 'override' : 'derived', 'derived' => round(floatval($derived[$axis] ?? 0), 3),
                'set' => function (array &$dd, $v) use ($axis): ?string {
                    if (!is_numeric($v)) return 'not a number';
                    return RelDynIntimacy::setNeedOverride($dd, $axis, floatval($v)) ? null : 'outside 0..1';
                },
                'reset' => function (array &$dd) use ($axis) { RelDynIntimacy::setNeedOverride($dd, $axis, null); },
            ]);
        }
        return self::section('intimacy', $fields, [
            'intro' => 'How much physical and emotional intimacy the NPC needs (0..1), derived from their traits. A preference that closes an axis wins over an override.',
            'reset' => function (array &$dd) { unset($dd['intimacy_need_overrides']); },
        ]);
    }

    // ---- attraction -----------------------------------------------------

    /** Write one attraction_overrides entry (per pillar when $pillar is given) and drop the cached matrix. */
    private static function setAttraction(array &$d, string $key, $value, ?string $pillar = null): void
    {
        $over = is_array($d['attraction_overrides'] ?? null) ? $d['attraction_overrides'] : [];
        if ($pillar === null) {
            if ($value === null) unset($over[$key]); else $over[$key] = $value;
        } else {
            $table = is_array($over[$key] ?? null) ? $over[$key] : [];
            if ($value === null) unset($table[$pillar]); else $table[$pillar] = $value;
            if ($table === []) unset($over[$key]); else $over[$key] = $table;
        }
        $d['attraction_overrides'] = $over;
        $d['_attraction_matrix_cache'] = null;
    }

    /** Clear one key from every editor layer (attraction_overrides and the April attraction_profile). */
    private static function clearAttraction(array &$d, string $key, ?string $legacyKey, ?string $pillar = null): void
    {
        self::setAttraction($d, $key, null, $pillar);
        if ($legacyKey !== null && is_array($d['attraction_profile'] ?? null)) {
            if ($pillar === null) {
                unset($d['attraction_profile'][$legacyKey]);
            } elseif (is_array($d['attraction_profile'][$legacyKey] ?? null)) {
                unset($d['attraction_profile'][$legacyKey][$pillar]);
            }
        }
        if ($key === 'openness') unset($d['openness']);
    }

    private static function attractionSection(string $npc, array $d, array $jev): array
    {
        $def = RelDynAttraction::definition($npc, $d);
        $bare = $d;
        unset($bare['attraction_overrides'], $bare['attraction_profile'], $bare['openness']);
        $base = RelDynAttraction::definition($npc, $bare);
        $over = is_array($d['attraction_overrides'] ?? null) ? $d['attraction_overrides'] : [];
        $legacy = is_array($d['attraction_profile'] ?? null) ? $d['attraction_profile'] : [];
        $cfg = RelDynAttraction::config();
        $preset = (array) (((array) ($cfg['npc_overrides'] ?? []))[strtolower(trim($npc))] ?? []);
        $state = function (string $key, ?string $legacyKey, ?string $pillar = null) use ($over, $legacy, $preset, $d): string {
            $in = fn(array $t, ?string $k) => $k !== null && ($pillar === null ? array_key_exists($k, $t) : (is_array($t[$k] ?? null) && array_key_exists($pillar, $t[$k])));
            if ($in($over, $key)) return 'override';
            if ($in($legacy, $legacyKey) || ($key === 'openness' && isset($d['openness']))) return 'legacy';
            if ($in($preset, $key)) return 'preset';
            return 'derived';
        };
        $fields = [];
        $gates = RelDynAttraction::GATES;
        $fields[] = self::field('attr:gate', 'Intimacy gate', 'select', $def['gate'], [
            'options' => array_combine($gates, $gates), 'state' => $state('gate', 'intimacy_gate'), 'derived' => $base['gate'],
            'hint' => 'visceral: body first; bond: connection first; balanced: both',
            'set' => function (array &$dd, $v) use ($gates): ?string {
                if (!in_array($v, $gates, true)) return 'unknown gate';
                self::setAttraction($dd, 'gate', $v);
                return null;
            },
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'gate', 'intimacy_gate'); },
        ]);
        $bands = RelDynAttraction::OPENNESS_BANDS;
        $fields[] = self::field('attr:openness', 'Openness', 'select', $def['openness_sober'] ?? $def['openness'], [
            'options' => array_combine($bands, $bands), 'state' => $state('openness', null), 'derived' => $base['openness_sober'] ?? $base['openness'],
            'hint' => 'o = ' . self::num($def['openness_o'], 3) . ' (MDD 1.4: low = hard block, medium / high tolerate near misses)',
            'set' => function (array &$dd, $v) use ($bands): ?string {
                if (!in_array($v, $bands, true)) return 'unknown band';
                self::setAttraction($dd, 'openness', $v);
                return null;
            },
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'openness', null); },
        ]);
        $genders = ['heterosexual', 'homosexual', 'bisexual'];
        $fields[] = self::field('attr:gender_pref', 'Gender preference', 'select', $def['gender_pref'], [
            'options' => array_combine($genders, $genders), 'state' => $state('gender_pref', 'gender_pref'), 'derived' => $base['gender_pref'],
            'set' => function (array &$dd, $v) use ($genders): ?string {
                if (!in_array($v, $genders, true)) return 'unknown preference';
                self::setAttraction($dd, 'gender_pref', $v);
                return null;
            },
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'gender_pref', 'gender_pref'); },
        ]);
        $fields[] = self::field('attr:status_share', 'Status share', 'number', round(floatval($def['status_share']), 2), [
            'min' => 0, 'max' => 1, 'step' => 0.01, 'state' => $state('status_share', null), 'derived' => $base['status_share'],
            'hint' => 'how much of the status pillar is their own markers (faction standing) vs the generic footprint',
            'set' => self::numberSetter(fn(array &$dd, float $v) => self::setAttraction($dd, 'status_share', $v), 0, 1),
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'status_share', null); },
        ]);
        // Decisions §23 (Ken, 2026-10-01): the NPC's own standing is an editor field now (the thane / rank reporter
        // comes later). It writes attraction_overrides.standing, which RelDynAttraction::definition reads over the
        // preset and the derivation; the derived value (archetype + a status faction) is shown beside it.
        $standingMax = floatval(((array) (RelDynAttraction::spikePrereqConfig($cfg)['own_standing'] ?? []))['max'] ?? 1.0);
        $standingMax = $standingMax > 0 ? $standingMax : 1.0;
        $fields[] = self::field('attr:standing', 'Own standing', 'number', round(floatval($def['own_standing']), 3), [
            'min' => 0, 'max' => $standingMax, 'step' => 0.01, 'state' => $state('standing', null), 'derived' => round(floatval($base['own_standing']), 3),
            'hint' => 'the NPC\'s own social standing, 0..' . self::num($standingMax, 2) . ' (a thane or a Circle member high, a barmaid or a whelp low): '
                . 'the status gap to the player, where the NPC admires them, lets a spike skip the uphill. Derived from archetype and a status faction ('
                . (string) ($def['sources']['standing'] ?? '?') . ') until the thane / rank reporter exists',
            'set' => self::numberSetter(fn(array &$dd, float $v) => self::setAttraction($dd, 'standing', $v), 0, $standingMax),
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'standing', null); },
        ]);
        $fields[] = self::field('attr:beauty_keywords', 'Beauty keywords', 'text', implode(', ', (array) $def['beauty_keywords']), [
            'state' => $state('beauty_keywords', 'beauty_keywords'), 'derived' => implode(', ', (array) $base['beauty_keywords']),
            'hint' => 'comma-separated words the NPC finds beautiful in the player\'s appearance text',
            'set' => function (array &$dd, $v): ?string {
                $kw = array_values(array_filter(array_map(fn($k) => strtolower(trim($k)), explode(',', (string) $v)), fn($k) => $k !== ''));
                self::setAttraction($dd, 'beauty_keywords', $kw);
                return null;
            },
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'beauty_keywords', 'beauty_keywords'); },
        ]);
        $pillars = RelDynAttraction::PILLARS;
        $fields[] = self::field('attr:passion_pillars', 'Passion pillars', 'text', implode(', ', (array) $def['passion_pillars']), [
            'state' => $state('passion_pillars', 'passion_pillars'), 'derived' => implode(', ', (array) $base['passion_pillars']),
            'hint' => 'which pillars gate passion (' . implode(', ', $pillars) . ')',
            'set' => function (array &$dd, $v) use ($pillars): ?string {
                $list = array_values(array_unique(array_filter(array_map(fn($k) => strtolower(trim($k)), explode(',', (string) $v)), fn($k) => $k !== '')));
                if ($list === [] || array_diff($list, $pillars) !== []) return 'list pillars from: ' . implode(', ', $pillars);
                self::setAttraction($dd, 'passion_pillars', $list);
                return null;
            },
            'reset' => function (array &$dd) { self::clearAttraction($dd, 'passion_pillars', 'passion_pillars'); },
        ]);
        $markers = [];
        foreach ((array) $def['status_markers'] as $k => $spec) $markers[] = (string) $k;
        $fields[] = self::field('attr:status_markers', 'Status markers', 'readonly', $markers ? implode(', ', $markers) : 'none',
            ['state' => 'readonly', 'hint' => 'from their factions (' . (string) ($def['sources']['status_markers'] ?? '?') . '); set per NPC in the attraction config']);
        $lens = [];
        foreach ((array) $def['lens'] as $p => $table) {
            arsort($table);
            $top = [];
            foreach (array_slice($table, 0, 3, true) as $arch => $w) $top[] = $arch . ' ' . self::num($w, 2);
            $lens[] = $p . ': ' . implode(', ', $top);
        }
        $fields[] = self::field('attr:lens', 'Lens (what counts as strength / competence)', 'readonly', $lens ? implode('; ', $lens) : 'none',
            ['state' => 'readonly', 'hint' => 'player archetypes the NPC values, derived from their facet preferences (edit those)']);
        $rig = RelDynAttraction::RIGIDITIES;
        foreach ($pillars as $p) {
            $fields[] = self::field("attr:rigidity:{$p}", ucfirst($p), 'select', $def['rigidity'][$p], [
                'options' => array_combine($rig, $rig), 'group' => $p, 'col' => 'rigidity',
                'state' => $state('rigidity', 'pillar_rigidity', $p), 'derived' => $base['rigidity'][$p],
                'set' => function (array &$dd, $v) use ($rig, $p): ?string {
                    if (!in_array($v, $rig, true)) return 'unknown rigidity';
                    self::setAttraction($dd, 'rigidity', $v, $p);
                    return null;
                },
                'reset' => function (array &$dd) use ($p) { self::clearAttraction($dd, 'rigidity', 'pillar_rigidity', $p); },
            ]);
            $fields[] = self::field("attr:weight:{$p}", ucfirst($p), 'number', round(floatval($def['weights'][$p]), 2), [
                'min' => 0, 'max' => 5, 'step' => 0.05, 'group' => $p, 'col' => 'weight',
                'state' => $state('weights', null, $p), 'derived' => round(floatval($base['weights'][$p]), 3),
                'set' => self::numberSetter(fn(array &$dd, float $v) => self::setAttraction($dd, 'weights', $v, $p), 0, 5),
                'reset' => function (array &$dd) use ($p) { self::clearAttraction($dd, 'weights', null, $p); },
            ]);
            $fields[] = self::field("attr:floor:{$p}", ucfirst($p), 'number', round(floatval($def['floors'][$p]), 1), [
                'min' => 1, 'max' => 100, 'step' => 0.5, 'group' => $p, 'col' => 'floor',
                'state' => $state('floors', 'pillar_floors', $p), 'derived' => round(floatval($base['floors'][$p]), 2),
                'set' => self::numberSetter(fn(array &$dd, float $v) => self::setAttraction($dd, 'floors', $v, $p), 1, 100),
                'reset' => function (array &$dd) use ($p) { self::clearAttraction($dd, 'floors', 'pillar_floors', $p); },
            ]);
            $fields[] = self::field("attr:lens_share:{$p}", ucfirst($p), 'number', round(floatval($def['lens_share'][$p]), 2), [
                'min' => 0, 'max' => 1, 'step' => 0.01, 'group' => $p, 'col' => 'lens_share',
                'state' => $state('lens_share', null, $p), 'derived' => round(floatval($base['lens_share'][$p]), 3),
                'set' => self::numberSetter(fn(array &$dd, float $v) => self::setAttraction($dd, 'lens_share', $v, $p), 0, 1),
                'reset' => function (array &$dd) use ($p) { self::clearAttraction($dd, 'lens_share', null, $p); },
            ]);
        }
        $a = $jev['attraction'];
        $units = [];
        foreach ((array) $a['units'] as $key => $u) {
            $units[] = ['unit' => (string) $key, 'score' => $u['score'], 'floor' => $u['floor'], 'met' => $u['met'], 'm' => $u['m']];
        }
        $blocks = [['type' => 'attraction_eval', 'enabled' => $a['enabled'], 'outcome' => $a['outcome'], 'curve' => $a['curve'],
            'spark' => $a['spark'], 'passion_mult' => $a['passion_mult'], 'hard_zero' => $a['hard_zero'], 'won_over' => $a['won_over'],
            'friendzoned' => $a['friendzoned'], 'units' => $units, 'standards' => $def['standards'],
            'passes' => (array) ($d['_attraction']['passes'] ?? [])]];
        return self::section('attraction', $fields, [
            'intro' => 'The Attraction Matrix is a bouncer, not an emotion: how the player scores on their four pillars as the NPC defines them, '
                . 'and how steep the uphill to passion is. Overrides are stored per NPC (attraction_overrides) over the named preset and '
                . 'the derivation. "Match" below is the player\'s pillar score in their eyes (points 0..100) against their floor.',
            'blocks' => $blocks,
            'reset' => function (array &$dd) {
                unset($dd['attraction_overrides'], $dd['attraction_profile'], $dd['openness']);
                $dd['_attraction_matrix_cache'] = null;
            },
            'reset_label' => 'Clear every attraction override',
        ]);
    }

    // ---- jealousy -------------------------------------------------------

    private static function jealousySection(array $d): array
    {
        $cfg = RelationshipDynamics::getConfig();
        $max = floatval($cfg['jealousy_max'] ?? 100);
        $pref = strtolower(trim((string) ($d['relationship_preference'] ?? '')));
        $mult = ((array) ($cfg['preference_jealousy_mult'] ?? []))[$pref] ?? 1.0;
        $fields = [
            self::field('jeal:level', 'Jealousy', 'number', round(floatval($d['jealousy_anger'] ?? 0), 2), [
                'min' => 0, 'max' => $max, 'step' => 0.1, 'derived' => 0.0,
                'hint' => "jealousy points 0..{$max}; decays in contact, feeds resentment above 30",
                'set' => self::numberSetter(fn(array &$dd, float $v) => RelationshipDynamics::setJealousy($dd, $v), 0, $max),
                'reset' => function (array &$dd) { RelationshipDynamics::setJealousy($dd, 0.0); $dd['jealousy_updated_at'] = 0; },
            ]),
            self::field('jeal:rival', 'Rival', 'text', (string) ($d['jealousy_trigger_npc'] ?? ''), [
                'derived' => '',
                'set' => function (array &$dd, $v): ?string { $v = trim((string) $v); $dd['jealousy_trigger_npc'] = $v !== '' ? $v : null; return null; },
                'reset' => function (array &$dd) { $dd['jealousy_trigger_npc'] = null; },
            ]),
            self::field('jeal:mult', 'Preference multiplier', 'readonly', 'x' . self::num($mult, 2), ['state' => 'readonly',
                'hint' => 'from the relationship preference (settings: preference_jealousy_mult)']),
        ];
        return self::section('jealousy', $fields, [
            'intro' => 'A feeling about a rival or a situation (decisions §5), not the grievance accumulator.',
            'reset' => function (array &$dd) {
                RelationshipDynamics::setJealousy($dd, 0.0);
                $dd['jealousy_updated_at'] = 0;
                $dd['jealousy_trigger_npc'] = null;
            },
        ]);
    }

    // ---- resentment -----------------------------------------------------

    private static function resentmentSection(array $d, array $jev): array
    {
        $res = (array) ($d['dimensions']['resentment'] ?? []);
        $log = array_merge((array) ($res['pending_grievances'] ?? []), (array) ($res['grievance_log'] ?? []));
        // what the page lists: the eval's pending ones are bare strings (not yet taken into the accumulator)
        $rows = [];
        foreach ((array) ($res['pending_grievances'] ?? []) as $g) {
            $rows[] = ['pending' => true, 'text' => is_scalar($g) ? (string) $g : json_encode($g, JSON_UNESCAPED_UNICODE)];
        }
        foreach ((array) ($res['grievance_log'] ?? []) as $g) $rows[] = is_array($g) ? $g : ['text' => (string) $g];
        $arc = $jev['resentment_arc'];
        $fields = [
            self::field('res:values', 'Resentment / toward self', 'readonly',
                self::num($jev['resentment'], 1) . ' / ' . self::num($jev['resentment_self'], 1), ['state' => 'readonly', 'hint' => 'edit both under Dimensions']),
            self::field('res:grievances', 'Grievances on record', 'readonly', count($log) . ' entr' . (count($log) === 1 ? 'y' : 'ies'), [
                'state' => 'state', 'hint' => 'reset forgets the log; the resentment value stays',
                'reset' => function (array &$dd) {
                    if (is_array($dd['dimensions']['resentment'] ?? null)) {
                        $dd['dimensions']['resentment']['pending_grievances'] = [];
                        $dd['dimensions']['resentment']['grievance_log'] = [];
                    }
                },
            ]),
            self::field('res:arc', 'Confrontation arc', 'readonly',
                'said ' . intval($arc['confrontations']) . ', threshold ' . self::num($arc['confrontation_threshold'], 1)
                . ($arc['confrontation_pending'] !== null ? ', due (' . $arc['confrontation_pending'] . ')' : '')
                . ($arc['self_crisis'] ? ', self crisis' : '') . ($arc['guilt_bleed'] != 0.0 ? ', guilt ' . self::num($arc['guilt_bleed'], 1) : ''),
                ['state' => 'state', 'hint' => 'reset releases the guilt bleed and forgets the arc',
                 'reset' => function (array &$dd) {
                     self::releaseHeld($dd, 'guilt');
                     unset($dd[RelDynResentment::STATE_KEY]);
                 }]),
        ];
        return self::section('resentment', $fields, [
            'intro' => 'The grievance accumulator of the relationship (MDD 15.5). Sustained jealousy converts into it.',
            'blocks' => [['type' => 'grievances', 'rows' => array_slice($rows, -20)]],
            'reset' => function (array &$dd) {
                self::releaseHeld($dd, 'guilt');
                unset($dd[RelDynResentment::STATE_KEY]);
                $dd['dimensions']['resentment'] = ['x' => 0, 'baseline' => 0, 'active' => true, 'pending_grievances' => [], 'grievance_log' => [], 'last_decay_tick' => 0];
                $dd['dimensions']['resentment_self'] = ['x' => 0, 'baseline' => 0, 'active' => true];
            },
            'reset_label' => 'Clean slate (both resentments, log, arc)',
        ]);
    }

    // ---- fulfillment ----------------------------------------------------

    private static function fulfillmentSection(string $npc, array $d, float $now): array
    {
        $pairs = RelDynFulfillment::pairs($d);
        if (!isset($pairs[RelDynFulfillment::PLAYER])) $pairs = [RelDynFulfillment::PLAYER => null] + $pairs;
        $fields = [];
        $blocks = [];
        foreach ($pairs as $key => $state) {
            $key = (string) $key;
            $prefs = is_array($state['w'] ?? null) ? [] : RelDynFacets::preferences($d, $npc);
            $graph = RelDynFulfillment::graph($npc, $d, $prefs, $now, $key);
            $blocks[] = ['type' => 'spider', 'target' => $key, 'graph' => $graph];
            $label = $key === RelDynFulfillment::PLAYER ? 'the player' : $key;
            $fields[] = self::field("ful:pair:{$key}", "Pair with {$label}", 'readonly',
                'band ' . self::num($graph['band'], 2) . ', trend ' . self::num($graph['trend'], 3) . '/day' . ($graph['low'] ? ' (low)' : ''),
                ['state' => 'state', 'hint' => 'reset forgets what this pair delivered (coverage starts over)',
                 'reset' => $state === null ? null : function (array &$dd) use ($key) { RelDynFulfillment::setPairState($dd, $key, null); }]);
            $b = (string) ($graph['boundary']['state'] ?? 'none');
            if ($key === RelDynFulfillment::PLAYER) {
                $fields[] = self::field("ful:boundary:{$key}", 'Mature boundary', 'readonly', $b, [
                    'state' => 'state', 'hint' => 'a reset does not undo a step-back already written to core',
                    'reset' => $b === 'none' ? null : function (array &$dd) use ($key) {
                        $s = RelDynFulfillment::pairState($dd, $key);
                        if (!is_array($s)) return;
                        $s['boundary'] = ['state' => 'none'];
                        RelDynFulfillment::setPairState($dd, $key, $s);
                    }]);
            }
        }
        return self::section('fulfillment', $fields, [
            'intro' => 'Per relationship pair: their needs (the spider\'s axes, from facet preferences, love languages, traits and intimacy need) '
                . 'and how well this relationship has been covering them lately (-1..+1). Needs are edited through those sections; coverage is earned.',
            'blocks' => $blocks,
        ]);
    }

    // ---- flags & timers -------------------------------------------------

    private static function flagSection(array $d): array
    {
        $bool = function (string $key, string $label, string $hint = '') use ($d): array {
            return self::field("flag:{$key}", $label, 'bool', !empty($d[$key]), [
                'derived' => false, 'hint' => $hint,
                'set' => function (array &$dd, $v) use ($key): ?string { $dd[$key] = (bool) $v; return null; },
                'reset' => function (array &$dd) use ($key) { $dd[$key] = false; },
            ]);
        };
        $int = function (string $key, string $label, int $max, string $hint = '') use ($d): array {
            return self::field("flag:{$key}", $label, 'number', intval($d[$key] ?? 0), [
                'min' => 0, 'max' => $max, 'step' => 1, 'derived' => 0, 'hint' => $hint,
                'set' => self::numberSetter(function (array &$dd, float $v) use ($key) { $dd[$key] = intval(round($v)); }, 0, $max),
                'reset' => function (array &$dd) use ($key) { $dd[$key] = 0; },
            ]);
        };
        $stages = [RelationshipDynamics::STAGE_EARLY, RelationshipDynamics::STAGE_ESTABLISHED, RelationshipDynamics::STAGE_DEEP];
        $types = array_keys(RelationshipDynamics::RELATIONSHIP_TYPE_MODIFIERS);
        $typeOver = $d['_relationship_type_override'] ?? null;
        $creature = $d['creature_type'] ?? null;
        $goal = RelationshipDynamics::getActiveDirectorGoal($d);
        $fields = [
            $bool('in_conflict', 'Open conflict', 'repairs since: ' . intval($d['conflict_positive_count'] ?? 0)),
            $int('conflict_positive_count', 'Conflict repairs', 1000),
            self::field('flag:stage', 'Stage', 'select', (string) ($d['stage'] ?? RelationshipDynamics::STAGE_EARLY), [
                'options' => array_combine($stages, $stages), 'derived' => RelationshipDynamics::STAGE_EARLY,
                'set' => function (array &$dd, $v) use ($stages): ?string {
                    if (!in_array($v, $stages, true)) return 'unknown stage';
                    $dd['stage'] = $v;
                    return null;
                },
                'reset' => function (array &$dd) { $dd['stage'] = RelationshipDynamics::STAGE_EARLY; },
            ]),
            $int('interaction_count', 'Interaction count (diminishing returns: decays with play time)', 1000000),
            $int('total_positive_interactions', 'Positive interactions', 1000000),
            $bool('reunion_spike_given', 'Reunion spike given'),
            $int('context_tier_hwm', 'Context tier high-water mark', 3, 'tier 2 is permanent once reached'),
            self::field('flag:type_override', 'Relationship type override', 'select', is_string($typeOver) ? $typeOver : '', [
                'options' => ['' => '(none)'] + array_combine($types, $types), 'state' => $typeOver ? 'override' : 'derived', 'derived' => '',
                'hint' => 'core hostility still outranks it',
                'set' => function (array &$dd, $v) use ($types): ?string {
                    if ($v !== '' && !in_array($v, $types, true)) return 'unknown type';
                    $dd['_relationship_type_override'] = $v !== '' ? $v : null;
                    return null;
                },
                'reset' => function (array &$dd) { $dd['_relationship_type_override'] = null; },
            ]),
            self::field('flag:creature_type', 'Creature', 'select', is_string($creature) ? $creature : '', [
                'options' => ['' => '(from the game)', 'none' => 'not a creature'] + array_combine(RelDynCreatures::TYPES, RelDynCreatures::TYPES),
                'state' => $creature !== null ? 'override' : 'derived', 'derived' => '',
                'hint' => 'a Companions member cured of the blood ("Purity") is still a werewolf by faction: choose "not a creature" for the NPC',
                'set' => function (array &$dd, $v): ?string {
                    if ($v !== '' && $v !== 'none' && !in_array($v, RelDynCreatures::TYPES, true)) return 'unknown creature';
                    $dd['creature_type'] = $v !== '' ? $v : null;
                    return null;
                },
                'reset' => function (array &$dd) { $dd['creature_type'] = null; },
            ]),
            self::field('flag:home_location', 'Home location', 'text', (string) ($d['home_location'] ?? ''), [
                'derived' => '', 'hint' => 'where a walkaway goes home to',
                'set' => function (array &$dd, $v): ?string { $v = trim((string) $v); $dd['home_location'] = $v !== '' ? $v : null; return null; },
                'reset' => function (array &$dd) { $dd['home_location'] = null; },
            ]),
            $bool('_director_goal_disabled', 'Director goals off for the NPC'),
            self::field('flag:director_goal', 'Director goal', 'readonly', is_array($goal) ? (string) ($goal['text'] ?? '') : 'none', [
                'state' => 'state', 'reset' => is_array($goal) ? function (array &$dd) { $dd['_director_goal'] = null; } : null]),
        ];
        $timers = [];
        foreach ([
            'last_seen_at' => 'Last seen (play clock)', 'last_interaction_at' => 'Last interaction (play clock)',
            '_last_gamets' => 'Last turn (game calendar)', '_accumulated_play_gamets' => 'Played together (play clock)',
            'passion_updated_at' => 'Passion updated (play clock)', 'jealousy_updated_at' => 'Jealousy updated (play clock)',
            'conflict_entered_at' => 'Conflict opened (play clock)', '_decay_last_game_gamets' => 'Last absence-decay check (game calendar)',
            '_resentment_last_play_gamets' => 'Last resentment decay (play clock)',
        ] as $key => $label) {
            $timers[] = ['key' => $key, 'label' => $label, 'raw' => $d[$key] ?? 0, 'days' => self::gameDays($d[$key] ?? 0)];
        }
        $timers[] = ['key' => '_accumulated_time', 'label' => 'Play seconds with the NPC', 'raw' => $d['_accumulated_time'] ?? 0,
            'days' => self::num(floatval($d['_accumulated_time'] ?? 0) / 3600, 2) . ' h'];
        return self::section('flags', $fields, [
            'intro' => 'Counters, switches and the clocks (read-only; raw gamets and game days).',
            'blocks' => [['type' => 'timers', 'rows' => $timers]],
            'reset' => function (array &$dd) {
                $dd['in_conflict'] = false;
                $dd['conflict_entered_at'] = 0;
                $dd['conflict_positive_count'] = 0;
                $dd['stage'] = RelationshipDynamics::STAGE_EARLY;
                $dd['reunion_spike_given'] = false;
                $dd['_relationship_type_override'] = null;
                $dd['creature_type'] = null;
                $dd['_director_goal_disabled'] = false;
                $dd['_director_goal'] = null;
            },
            'reset_label' => 'Reset switches (counters stay)',
        ]);
    }

    // ---- active states --------------------------------------------------

    /** The held offsets by source: source => [dimension => points]. */
    public static function heldBySource(array $d): array
    {
        $num = fn($m): array => is_array($m) ? array_map('floatval', array_filter($m, 'is_numeric')) : [];
        $sum = function (array $maps) use ($num): array {
            $out = [];
            foreach ($maps as $m) foreach ($num($m) as $k => $v) $out[$k] = ($out[$k] ?? 0.0) + $v;
            return $out;
        };
        $consumables = [];
        foreach ((array) ($d['_active_consumables'] ?? []) as $c) if (is_array($c)) $consumables[] = $c['immediate'] ?? null;
        $out = [
            'creature' => $num($d[RelDynCreatures::STATE_KEY]['applied'] ?? null),
            'physical' => $sum((array) ($d['_applied_physical_deltas'] ?? [])),
            'environment' => $num($d['_env_applied_effects'] ?? null),
            'guilt' => ['comfort' => floatval($d[RelDynResentment::STATE_KEY]['guilt']['applied'] ?? 0.0)],
            'reputation' => $num($d[RelDynReputation::KEY]['effective'] ?? null),
            'consumables' => $sum($consumables),
            'grief' => $num($d[RelDynProtocols::GRIEF_HELD_KEY] ?? null),
            'post_intimacy' => $num($d[RelDynPostIntimacy::KEY]['held'] ?? null),
            'weather' => $num($d['_weather_gravity']['applied'] ?? null),
            'substances' => $num($d[RelDynSubstances::KEY]['held'] ?? null),
        ];
        foreach ($out as $k => $m) {
            $m = array_filter($m, fn($v) => abs($v) > 1e-9);
            if ($m === []) unset($out[$k]); else $out[$k] = $m;
        }
        return $out;
    }

    /**
     * Release held temporary offsets (one source, or all with null): take the held points back
     * out of each dimension's stored x and forget the source's record, so its owner starts over
     * from the value without it (the owner re-applies what still holds at its next tick).
     */
    public static function releaseHeld(array &$d, ?string $source = null): array
    {
        $released = [];
        foreach (self::heldBySource($d) as $src => $map) {
            if ($source !== null && $src !== $source) continue;
            foreach ($map as $dim => $pts) {
                if (!is_array($d['dimensions'][$dim] ?? null) || !is_numeric($d['dimensions'][$dim]['x'] ?? null)) continue;
                $def = RelationshipDynamics::getDimensionDefinition($dim);
                $min = $def ? floatval($def['range_min']) : 0.0;
                $max = $def ? floatval($def['range_max']) : 100.0;
                $d['dimensions'][$dim]['x'] = round(max($min, min($max, floatval($d['dimensions'][$dim]['x']) - $pts)), 6);
                if ($dim === 'passion') RelationshipDynamics::setPassion($d, $d['dimensions']['passion']['x']);
                $released[$src][$dim] = $pts;
            }
            switch ($src) {
                case 'creature': $d[RelDynCreatures::STATE_KEY]['applied'] = []; break;
                case 'physical': $d['_applied_physical_deltas'] = []; break;
                case 'environment': $d['_env_applied_effects'] = []; break;
                case 'guilt': $d[RelDynResentment::STATE_KEY]['guilt']['applied'] = 0.0; break;
                case 'reputation': $d[RelDynReputation::KEY]['effective'] = []; break;
                case 'consumables': unset($d['_active_consumables']); break;
                case 'grief': unset($d[RelDynProtocols::GRIEF_HELD_KEY]); break;
                case 'post_intimacy': $d[RelDynPostIntimacy::KEY]['held'] = []; break;
                case 'weather': $d['_weather_gravity']['applied'] = []; break;
                case 'substances': $d[RelDynSubstances::KEY]['held'] = []; break;
            }
        }
        return $released;
    }

    private static function stateSection(array $d, array $jev, float $now): array
    {
        $held = self::heldBySource($d);
        $fields = [];
        foreach (self::HELD_SOURCES as $src => $label) {
            if (!isset($held[$src])) continue;
            $parts = [];
            foreach ($held[$src] as $dim => $pts) $parts[] = $dim . ' ' . ($pts >= 0 ? '+' : '') . self::num($pts, 2);
            $fields[] = self::field("state:held:{$src}", "Held: {$label}", 'readonly', implode(', ', $parts), [
                'state' => 'state', 'hint' => 'reset takes these points back out of the dimensions',
                'reset' => function (array &$dd) use ($src) { self::releaseHeld($dd, $src); }]);
        }
        $spike = RelDynPassion::spike($d);
        $fields[] = self::field('state:spike', 'Passion spike', 'readonly', self::num($spike, 2), ['state' => 'state',
            'hint' => 'the moment on top of the floor; fades per exchange',
            'reset' => $spike > 0 ? function (array &$dd) { unset($dd[RelDynPassion::SPIKE_KEY], $dd[RelDynPassion::SPIKE_TRIGGER_KEY], $dd[RelDynPassion::SPIKE_CLOCK_KEY]); } : null]);
        $grief = (array) ($jev['protocols']['grief'] ?? []);
        $gtext = [];
        foreach ($grief as $name => $g) $gtext[] = $name . ' (phase ' . $g['phase'] . ($g['widow_lock'] ? ', widow lock' : '') . ')';
        $fields[] = self::field('state:grief', 'Grief', 'readonly', $gtext ? implode('; ', $gtext) : 'none', ['state' => 'state',
            'hint' => 'reset ends every grief bond, its held offsets and the widow lock',
            'reset' => ($grief || isset($d[RelDynProtocols::GRIEF_HELD_KEY]) || floatval($d['_widow_lock_ceiling'] ?? 100) < 100) ? function (array &$dd) {
                self::releaseHeld($dd, 'grief');
                $dd['_grief_bonds'] = [];
                $dd['_widow_lock_ceiling'] = 100;
            } : null]);
        $c = $jev['concern'];
        $fields[] = self::field('state:concern', 'Concern', 'readonly', self::num($c['level'], 1) . ' (' . $c['band'] . ')', ['state' => 'state',
            'hint' => 'protective worry and the pattern it counts; reset forgets both (and its values boundary)',
            'reset' => isset($d[RelDynConcern::STATE_KEY]) ? function (array &$dd) { unset($dd[RelDynConcern::STATE_KEY]); } : null]);
        $vb = (string) ($d[RelDynConcern::STATE_KEY]['boundary']['state'] ?? 'none');
        $fields[] = self::field('state:values_boundary', 'Values boundary', 'readonly', $vb, ['state' => 'state',
            'hint' => 'a reset does not undo a step-back already written to core',
            'reset' => $vb !== 'none' ? function (array &$dd) { $dd[RelDynConcern::STATE_KEY]['boundary'] = ['state' => 'none']; } : null]);
        $fields[] = self::field('state:boundary', 'Fulfillment boundary', 'readonly', (string) $jev['boundary'], ['state' => 'state',
            'hint' => 'reset it under Fulfillment']);
        // Let in (durable, earned) and pulling back (temporary): reldyn_pullback.php
        $pb = $jev['pullback'];
        $fields[] = self::field('state:let_in', 'Let in', 'readonly', self::num($pb['let_in'], 1) . ($pb['not_let_in_yet'] ? ' (not yet)' : ''), ['state' => 'state',
            'hint' => 'how far the NPC has let the player in: sqrt(comfort x trust), any bond type. Derived; change comfort or trust to move it']);
        $fields[] = self::field('state:pullback', 'Pulling back', 'readonly', $pb['active']
            ? 'yes, ' . self::num($pb['pressure'], 2) . ' (on ' . self::num((float) $pb['on'], 2) . ', off ' . self::num((float) $pb['off'], 2) . '), '
                . $pb['band'] . '/' . $pb['style'] . ($pb['attachment'] !== null ? '/' . $pb['attachment'] : '')
                . ($pb['since_game_hours'] !== null ? ', ' . self::num($pb['since_game_hours'], 1) . ' game hours' : '') . ($pb['voiced'] ? ', voiced' : '')
                . (($pb['cause'] ?? null) === 'aftermath' ? ', the morning after intimacy' : '')
            : 'no (pressure ' . self::num($pb['pressure'], 2) . ')', ['state' => 'state',
            'hint' => 'temporary: the weather, unmet needs, resentment and, after intimacy, a fear of closeness press on the NPC; reset lets them open up at once (it comes back if the pressure does)',
            'reset' => isset($d[RelDynPullback::KEY]) ? function (array &$dd) { unset($dd[RelDynPullback::KEY]); } : null]);
        $walk = (string) ($d['_walkaway_state'] ?? 'normal');
        $fields[] = self::field('state:walkaway', 'Walkaway', 'readonly', $walk . (isset($d['_walkaway_reason']) ? ' (' . (string) $d['_walkaway_reason'] . ')' : ''), [
            'state' => 'state', 'hint' => 'reset brings the NPC back to normal (resetWalkawayState)',
            'reset' => $walk !== 'normal' ? function (array &$dd) { RelationshipDynamics::resetWalkawayState($dd); } : null]);
        return self::section('states', $fields, [
            'intro' => 'Temporary and situational states on top of who the NPC is. Held offsets are part of the raw dimension values.',
            'blocks' => [['type' => 'held', 'rows' => $held]],
            'reset' => function (array &$dd) use ($walk) {
                self::releaseHeld($dd);
                unset($dd[RelDynPassion::SPIKE_KEY], $dd[RelDynPassion::SPIKE_TRIGGER_KEY], $dd[RelDynPassion::SPIKE_CLOCK_KEY], $dd[RelDynConcern::STATE_KEY],
                    $dd[RelDynPullback::KEY]);
                $dd['_grief_bonds'] = [];
                $dd['_widow_lock_ceiling'] = 100;
                if ($walk !== 'normal') RelationshipDynamics::resetWalkawayState($dd);
            },
            'reset_label' => 'Release every state',
        ]);
    }

    // =====================================================================
    // WRITES
    // =====================================================================

    /** A posted value as its field compares it (null = unparseable). */
    private static function normalize(array $f, $v)
    {
        if (is_array($v)) $v = end($v);
        switch ($f['type']) {
            case 'number':
            case 'range':
                if (!is_scalar($v) || !is_numeric(trim((string) $v))) return null;
                return round(floatval($v), self::decimalsOf($f['step']));
            case 'bool':
                return !in_array(strtolower(trim((string) $v)), ['', '0', 'off', 'false', 'no'], true);
            case 'text':
                return trim((string) $v);
            default:
                return is_scalar($v) ? (string) $v : null;
        }
    }

    private static function sameValue(array $f, $posted): bool
    {
        $cur = $f['value'];
        if ($f['type'] === 'number' || $f['type'] === 'range') {
            return is_numeric($cur) && abs(round(floatval($cur), self::decimalsOf($f['step'])) - $posted) < 1e-9;
        }
        if ($f['type'] === 'bool') return (bool) $cur === $posted;
        if ($f['type'] === 'text') {
            $norm = fn($s) => implode(',', array_map('trim', explode(',', strtolower(trim((string) $s)))));
            return $norm($cur) === $norm($posted);
        }
        return (string) $cur === (string) $posted;
    }

    /** The value a field showed, as its form posts it back (a checkbox: '1' or ''). */
    private static function shownText(array $f): string
    {
        $v = $f['value'];
        if ($f['type'] === 'bool') return $v ? '1' : '';
        return $v === null ? '' : (is_bool($v) ? ($v ? '1' : '') : (string) $v);
    }

    /** The form's 'snap' field: field id => the text it showed (a malformed or oversized one is no snapshot at all). */
    private static function decodeSnapshot($raw): array
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 262144) return [];
        $j = json_decode($raw, true);
        if (!is_array($j)) return [];
        $out = [];
        foreach ($j as $id => $v) if (is_string($v)) $out[(string) $id] = $v;
        return $out;
    }

    /** Was $posted sent back exactly as the form showed $shown (the browser may have re-formatted it: 32.7700 for 32.77)? */
    private static function postedAsShown(array $f, string $shown, $posted): bool
    {
        if (is_array($posted)) $posted = end($posted);
        if (is_scalar($posted) && (string) $posted === $shown) return true;
        $n = self::normalize($f, $posted);
        return $n !== null && self::sameValue(['value' => $shown] + $f, $n);
    }

    /**
     * Apply one POST to the NPC (CSRF already checked):
     *   op = save               section = id, f[field id] = value, snap = JSON of the values the form showed
     *                           (only fields the user changed are applied: those posted differently from what the form
     *                           showed, and from the value in effect; without a snapshot, those differing from the value in effect)
     *   op = reset_field|<id>   clear one field's override
     *   op = reset_section      section = id
     *   op = reset_npc          confirm = 'yes': a fresh start (the affinity mirror is kept, core is not touched)
     *   op = reread             queue a fresh read of the NPC's live bio (RelDynTraitReingest::requestRead); the model runs
     *                           later, in the background reader, never in this request
     * Returns ['ok' => bool, 'changed' => field ids, 'errors' => [id => message], 'message' => string, 'section' => ?string].
     */
    public static function apply(string $npc, array $post): array
    {
        $name = self::canonicalName($npc);
        if ($name === null) return ['ok' => false, 'changed' => [], 'errors' => ['npc' => 'unknown NPC'], 'message' => 'Unknown NPC.', 'section' => null];
        $op = is_string($post['op'] ?? null) ? $post['op'] : '';
        $section = is_string($post['section'] ?? null) ? $post['section'] : null;
        return self::scoped($name, function () use ($name, $op, $section, $post) {
            $d = RelationshipDynamics::getDynamics($name);
            $now = RelationshipDynamics::currentGamets();
            $changed = [];
            $errors = [];

            if ($op === 'reset_npc') {
                if (($post['confirm'] ?? '') !== 'yes') {
                    return ['ok' => false, 'changed' => [], 'errors' => ['confirm' => 'tick the confirmation'], 'message' => 'Whole-NPC reset needs the confirmation ticked.', 'section' => null];
                }
                $token = $d[RelationshipDynamics::LOAD_TOKEN_KEY] ?? null;
                $fresh = RelationshipDynamics::defaultDynamics();
                // the affinity mirror pair stays: resetting RelDyn must not move core affinity
                if (isset($d['_aff_mirror_x'])) $fresh['_aff_mirror_x'] = $d['_aff_mirror_x'];
                if (is_array($d['dimensions']['affinity'] ?? null)) $fresh['dimensions']['affinity'] = $d['dimensions']['affinity'];
                if (isset($d['_core_rel_type'])) $fresh['_core_rel_type'] = $d['_core_rel_type'];
                if ($token !== null) $fresh[RelationshipDynamics::LOAD_TOKEN_KEY] = $token;
                $d = $fresh;
                $changed[] = '*';
            } else {
                $cat = self::catalog($name, $d, $now);
                if ($op === 'save') {
                    $sec = $cat['sections'][$section] ?? null;
                    if ($sec === null) return ['ok' => false, 'changed' => [], 'errors' => ['section' => 'unknown section'], 'message' => 'Unknown section.', 'section' => $section];
                    $posted = is_array($post['f'] ?? null) ? $post['f'] : [];
                    $shown = self::decodeSnapshot($post['snap'] ?? null);
                    foreach ($sec['fields'] as $id => $f) {
                        if ($f['set'] === null || !array_key_exists($id, $posted)) continue;
                        // posted back as the form showed it: not an edit, whatever the game did to the value since the page loaded
                        if (array_key_exists($id, $shown) && self::postedAsShown($f, $shown[$id], $posted[$id])) continue;
                        $v = self::normalize($f, $posted[$id]);
                        if ($v === null) { $errors[$id] = 'not a valid value'; continue; }
                        if (self::sameValue($f, $v)) continue;   // unchanged: no edit, the field stays derived
                        $err = ($f['set'])($d, $v);
                        if ($err !== null) $errors[$id] = $err; else $changed[] = $id;
                    }
                } elseif (str_starts_with($op, 'reset_field|')) {
                    $id = substr($op, strlen('reset_field|'));
                    $found = null;
                    foreach ($cat['sections'] as $sid => $s) if (isset($s['fields'][$id])) { $found = $s['fields'][$id]; $section = $sid; }
                    if ($found === null || $found['reset'] === null) {
                        return ['ok' => false, 'changed' => [], 'errors' => [$id => 'nothing to reset'], 'message' => 'That field has nothing to reset.', 'section' => $section];
                    }
                    ($found['reset'])($d);
                    $changed[] = $id;
                } elseif ($op === 'reread') {
                    $section = 'personality';
                    $r = RelDynTraitReingest::requestRead($name, $d);
                    if (!$r['ok']) return ['ok' => false, 'changed' => [], 'errors' => ['reread' => $r['message']], 'message' => $r['message'], 'section' => $section];
                    if (!RelationshipDynamics::saveDynamics($name, $d)) {
                        return ['ok' => false, 'changed' => [], 'errors' => ['save' => 'save failed'], 'section' => $section,
                            'message' => 'The save did not go through (the NPC changed under every retry, or a save load is pending). Nothing was queued; reload and try again.'];
                    }
                    return ['ok' => true, 'changed' => ['reread'], 'errors' => [], 'message' => $r['message'], 'section' => $section];
                } elseif ($op === 'reset_section') {
                    $sec = $cat['sections'][$section] ?? null;
                    if ($sec === null || $sec['reset'] === null) {
                        return ['ok' => false, 'changed' => [], 'errors' => ['section' => 'nothing to reset'], 'message' => 'That section has nothing to reset.', 'section' => $section];
                    }
                    ($sec['reset'])($d);
                    $changed[] = $section;
                } else {
                    return ['ok' => false, 'changed' => [], 'errors' => ['op' => 'unknown action'], 'message' => 'Unknown action.', 'section' => $section];
                }
            }

            if ($changed === []) {
                $msg = $errors ? 'Nothing saved: ' . self::errorText($errors) : 'No changes.';
                return ['ok' => $errors === [], 'changed' => [], 'errors' => $errors, 'message' => $msg, 'section' => $section];
            }
            if (!RelationshipDynamics::saveDynamics($name, $d)) {
                return ['ok' => false, 'changed' => [], 'errors' => ['save' => 'save failed'], 'section' => $section,
                    'message' => 'The save did not go through (the NPC changed under every retry, or a save load is pending). Nothing was written; reload and try again.'];
            }
            $msg = $op === 'reset_npc' ? 'Fresh start: every RelDyn value of this NPC is back to its derivation.'
                : ($op === 'save' ? 'Saved ' . count($changed) . ' change' . (count($changed) === 1 ? '' : 's') . '.' : 'Reset done.');
            if ($errors) $msg .= ' Not saved: ' . self::errorText($errors);
            return ['ok' => $errors === [], 'changed' => $changed, 'errors' => $errors, 'message' => $msg, 'section' => $section];
        });
    }

    private static function errorText(array $errors): string
    {
        $parts = [];
        foreach ($errors as $id => $e) $parts[] = "{$id}: {$e}";
        return implode('; ', $parts);
    }

    // =====================================================================
    // REQUEST
    // =====================================================================

    /**
     * One page request. Returns ['status' => HTTP status, 'location' => ?redirect URL,
     * 'title' => string, 'body' => inner HTML]. GET never writes; POST needs the session's
     * CSRF token and redirects back (303) with a flash message.
     */
    public static function handle(string $method, array $get, array $post, array &$session): array
    {
        $csrf = self::csrfToken($session);
        $method = strtoupper($method);
        if ($method === 'POST') {
            $npc = is_string($post['npc'] ?? null) ? trim($post['npc']) : '';
            if (!self::csrfValid($session, $post['csrf_token'] ?? null)) {
                return ['status' => 403, 'location' => null, 'title' => 'Relationship Dynamics',
                    'body' => self::renderMessagePage('Security check failed (missing or expired form token). No changes were made. Reload the page and try again.', $npc)];
            }
            $r = self::apply($npc, $post);
            $session[self::FLASH_SESSION_KEY] = ['ok' => $r['ok'], 'message' => $r['message']];
            $name = self::canonicalName($npc);
            $anchor = is_string($r['section']) && isset(self::SECTIONS[$r['section']]) ? 'sec-' . $r['section'] : null;   // a known section only
            return ['status' => 303, 'location' => self::pageUrl($name ?? ($npc !== '' ? $npc : null), $anchor),
                'title' => 'Relationship Dynamics', 'body' => ''];
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return ['status' => 405, 'location' => null, 'title' => 'Relationship Dynamics', 'body' => self::renderMessagePage('Method not allowed.', '')];
        }
        $flash = $session[self::FLASH_SESSION_KEY] ?? null;
        unset($session[self::FLASH_SESSION_KEY]);
        $npc = is_string($get['npc'] ?? null) ? trim($get['npc']) : '';
        if ($npc === '') {
            $q = is_string($get['q'] ?? null) ? $get['q'] : '';
            return ['status' => 200, 'location' => null, 'title' => 'RelDyn NPCs', 'body' => self::renderListPage(self::listNpcs($q), $q, $flash)];
        }
        $model = self::model($npc);
        if ($model === null) {
            return ['status' => 404, 'location' => null, 'title' => 'Relationship Dynamics', 'body' => self::renderMessagePage('No NPC by that name in CHIM\'s NPC table.', '')];
        }
        return ['status' => 200, 'location' => null, 'title' => 'RelDyn: ' . $model['npc'], 'body' => self::renderNpcPage($model, $csrf, is_array($flash) ? $flash : null)];
    }

    // =====================================================================
    // RENDER
    // =====================================================================

    public static function styles(): string
    {
        return <<<'CSS'
<style>
html, body { background: #1a1a1a; }
.rd-wrap { padding: 80px 5% 60px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #e0e0e0; max-width: 1100px; margin: 0 auto; }
.rd-header { background: linear-gradient(180deg, rgba(42,42,42,.95), rgba(28,28,28,.98)); padding: 18px 22px; border-radius: 10px; margin-bottom: 16px; border: 1px solid #3a3a3a; }
.rd-header h1 { font-family: 'MagicCards', serif; color: rgb(242,124,17); margin: 0 0 4px; font-size: 1.6em; letter-spacing: 1px; word-break: break-word; }
.rd-header p { color: #9fb1c9; margin: 0; font-size: .9em; }
.rd-header a { color: #9fb1c9; }
.rd-nav { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
.rd-nav a { font-size: .8em; padding: 4px 10px; border-radius: 12px; background: #262626; border: 1px solid #3a3a3a; color: #c8c8c8; text-decoration: none; }
.rd-nav a:hover { border-color: rgb(242,124,17); color: #fff; }
.rd-section { background: linear-gradient(180deg, rgba(42,42,42,.95), rgba(34,34,34,.98)); padding: 18px 20px; border-radius: 10px; border: 1px solid #3a3a3a; margin-bottom: 16px; }
.rd-section h2 { font-family: 'MagicCards', serif; color: rgb(242,124,17); font-size: 1.15em; margin: 0 0 8px; padding-bottom: 8px; border-bottom: 1px solid rgba(242,124,17,.2); letter-spacing: 1px; }
.rd-intro { color: #8f8f8f; font-size: .84em; margin: 0 0 12px; }
.rd-field { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: 7px 0; border-bottom: 1px solid #2c2c2c; }
.rd-field > label, .rd-field > .rd-label { flex: 0 0 220px; font-size: .88em; color: #c8c8c8; }
.rd-control { flex: 1 1 200px; display: flex; align-items: center; gap: 8px; min-width: 0; }
.rd-control input[type=number], .rd-control input[type=text], .rd-control select, table.rd-table input, table.rd-table select { background: #1a1a1a; border: 1px solid #4a4a4a; color: #f0f0f0; padding: 5px 8px; border-radius: 6px; font-size: .9em; max-width: 100%; }
.rd-control input[type=number] { width: 110px; }
.rd-control input[type=text] { width: 100%; }
.rd-control input[type=range] { flex: 1 1 140px; accent-color: rgb(242,124,17); min-width: 0; }
.rd-control output { font-family: Consolas, monospace; font-size: .85em; min-width: 3.5em; color: #f0f0f0; }
.rd-meta { flex: 1 1 100%; font-size: .76em; color: #7d7d7d; }
.rd-badge { display: inline-block; font-size: .72em; padding: 1px 7px; border-radius: 9px; border: 1px solid #555; color: #aaa; white-space: nowrap; }
.rd-badge.override { border-color: rgb(242,124,17); color: rgb(242,124,17); }
.rd-badge.edited, .rd-badge.differs { border-color: #d6a44a; color: #d6a44a; }
.rd-badge.preset { border-color: #6a9fd8; color: #8ab8ea; }
.rd-badge.core { border-color: #a07ad0; color: #c2a2ea; }
.rd-badge.legacy { border-color: #c06060; color: #e08a8a; }
.rd-badge.state { border-color: #4aa39a; color: #6cc9bf; }
.rd-reset { background: none; border: 1px solid #555; color: #bbb; border-radius: 6px; font-size: .75em; padding: 2px 8px; cursor: pointer; }
.rd-reset:hover { border-color: rgb(242,124,17); color: #fff; }
.rd-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; }
.rd-save { background: linear-gradient(180deg, rgb(242,124,17), rgb(200,95,5)); color: #fff; border: none; padding: 8px 22px; border-radius: 8px; font-weight: 600; cursor: pointer; }
.rd-danger { background: #3a1f1f; color: #f0a0a0; border: 1px solid #7a3a3a; padding: 7px 16px; border-radius: 8px; cursor: pointer; }
.rd-msg { font-size: .9em; padding: 8px 14px; border-radius: 6px; margin-bottom: 14px; }
.rd-msg.ok { background: rgba(40,167,69,.15); color: #5ddf7e; border: 1px solid rgba(40,167,69,.3); }
.rd-msg.err { background: rgba(220,53,69,.15); color: #f08090; border: 1px solid rgba(220,53,69,.3); }
.rd-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 6px 0; }
table.rd-table { width: 100%; border-collapse: collapse; font-size: .85em; }
table.rd-table th { text-align: left; padding: 6px 8px; color: rgb(242,124,17); border-bottom: 1px solid #4a4a4a; font-weight: 600; white-space: nowrap; }
table.rd-table td { padding: 5px 8px; border-bottom: 1px solid #2a2a2a; color: #c0c0c0; vertical-align: middle; }
table.rd-table td.num { font-family: Consolas, monospace; white-space: nowrap; }
table.rd-table input[type=number] { width: 84px; }
.rd-quote { font-style: italic; color: #d8d8d8; }
.rd-spider { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; margin: 8px 0 14px; }
.rd-spider > svg { flex: 1 1 300px; min-width: 0; width: auto; height: auto; }
.rd-spider .rd-scroll { flex: 1 1 260px; }
.rd-search { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.rd-search input { flex: 1 1 200px; background: #1a1a1a; border: 1px solid #4a4a4a; color: #f0f0f0; padding: 7px 10px; border-radius: 6px; }
.rd-list a { color: #f0f0f0; }
.rd-confirm { font-size: .85em; color: #ccc; display: flex; gap: 6px; align-items: center; }
@media (max-width: 640px) {
  .rd-wrap { padding: 70px 10px 40px; }
  .rd-field > label, .rd-field > .rd-label { flex: 1 1 100%; }
  .rd-section { padding: 14px 12px; }
}
</style>
CSS;
    }

    private static function flashHtml(?array $flash): string
    {
        if (!is_array($flash) || !isset($flash['message'])) return '';
        return '<div class="rd-msg ' . (!empty($flash['ok']) ? 'ok' : 'err') . '" role="status">' . self::h($flash['message']) . '</div>';
    }

    public static function renderMessagePage(string $message, string $npc): string
    {
        $back = $npc !== '' ? self::pageUrl($npc) : self::pageUrl();
        return self::styles() . '<div class="rd-wrap"><div class="rd-header"><h1>Relationship Dynamics</h1></div>'
            . '<div class="rd-msg err" role="alert">' . self::h($message) . '</div>'
            . '<p><a href="' . self::h($back) . '" style="color:#9fb1c9">Back</a></p></div>';
    }

    public static function renderListPage(array $list, string $search, ?array $flash = null): string
    {
        $out = self::styles() . '<div class="rd-wrap"><div class="rd-header"><h1>Relationship Dynamics: NPCs</h1>'
            . '<p>Pick an NPC to see and edit every RelDyn parameter. <a href="settings.php">Global settings</a> · '
            . '<a href="player.php">Player profile</a> · <a href="debug_pipeline.php">Pipeline dry run</a></p></div>';
        $out .= self::flashHtml($flash);
        $out .= '<form method="get" action="' . self::h(self::PAGE) . '" class="rd-search" role="search">'
            . '<input type="search" name="q" value="' . self::h($search) . '" placeholder="Search NPC names" aria-label="Search NPC names">'
            . '<button type="submit" class="rd-save">Search</button></form>';
        $rows = (array) ($list['rows'] ?? []);
        if ($rows === []) {
            $out .= '<div class="rd-section"><p class="rd-intro">No NPC matches' . ($search !== '' ? ' "' . self::h($search) . '"' : '') . '.</p></div></div>';
            return $out;
        }
        $out .= '<div class="rd-section rd-list"><div class="rd-scroll"><table class="rd-table"><thead><tr><th>NPC</th><th>RelDyn</th>'
            . '<th>Interactions</th><th>Stage</th><th>Core type</th><th>Temperament</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr><td><a href="' . self::h(self::pageUrl($r['name'])) . '">' . self::h($r['name']) . '</a></td>'
                . '<td>' . ($r['tracked'] ? '<span class="rd-badge state">tracked</span>' : '<span class="rd-badge">not yet</span>') . '</td>'
                . '<td class="num">' . self::h($r['interactions'] ?? '—') . '</td><td>' . self::h($r['stage'] ?? '—') . '</td>'
                . '<td>' . self::h($r['core_type'] ?? '—') . '</td><td>' . self::h($r['temperament'] ?? '—') . '</td></tr>';
        }
        $out .= '</tbody></table></div>';
        if (!empty($list['limited'])) $out .= '<p class="rd-intro">Showing the first ' . self::LIST_LIMIT . '; search to narrow.</p>';
        return $out . '</div></div>';
    }

    private static function badge(string $state): string
    {
        $label = self::STATE_LABELS[$state] ?? $state;
        return '<span class="rd-badge ' . self::h($state) . '" title="' . self::h($label) . '">' . self::h($label) . '</span>';
    }

    private static function resetButton(array $f): string
    {
        if (empty($f['resettable']) || $f['state'] === 'derived') return '';   // nothing to clear
        return '<button type="submit" class="rd-reset" name="op" value="' . self::h('reset_field|' . $f['id']) . '" formnovalidate '
            . 'title="Reset this field" aria-label="Reset ' . self::h($f['label']) . '">reset</button>';
    }

    private static function control(array $f): string
    {
        $name = 'f[' . $f['id'] . ']';
        $domId = 'rd-' . preg_replace('/[^a-z0-9_-]/i', '-', $f['id']);
        $attr = fn(string $k) => $f[$k] !== null ? ' ' . $k . '="' . self::h($f[$k]) . '"' : '';
        if (empty($f['editable'])) {
            $v = $f['value'];
            return '<span id="' . self::h($domId) . '">' . self::h(is_float($v) ? self::num($v, 2) : ($v === null ? '—' : $v)) . '</span>';
        }
        switch ($f['type']) {
            case 'number':
                // step=any: the value shown (32.77) is never off a step the browser would refuse; the server rounds and ranges it
                return '<input type="number" id="' . self::h($domId) . '" name="' . self::h($name) . '" value="' . self::h($f['value'] ?? '') . '"'
                    . $attr('min') . $attr('max') . ' step="any">';
            case 'range':
                // a browser snaps a range value to its step: the 0.01 grid the value is shown on, so an untouched slider posts what it showed
                $v = $f['value'] ?? 0;
                return '<input type="range" id="' . self::h($domId) . '" name="' . self::h($name) . '" value="' . self::h($v) . '"'
                    . $attr('min') . $attr('max') . ' step="0.01" oninput="this.nextElementSibling.value=this.value">'
                    . '<output for="' . self::h($domId) . '">' . self::h($v) . '</output>';
            case 'select':
                $html = '<select id="' . self::h($domId) . '" name="' . self::h($name) . '">';
                $opts = (array) $f['options'];
                if (!array_key_exists((string) $f['value'], $opts)) $opts = [(string) $f['value'] => (string) $f['value']] + $opts;
                foreach ($opts as $k => $label) {
                    $html .= '<option value="' . self::h($k) . '"' . ((string) $k === (string) $f['value'] ? ' selected' : '') . '>' . self::h($label) . '</option>';
                }
                return $html . '</select>';
            case 'bool':
                return '<input type="hidden" name="' . self::h($name) . '" value="">'
                    . '<input type="checkbox" id="' . self::h($domId) . '" name="' . self::h($name) . '" value="1"' . ($f['value'] ? ' checked' : '') . '>';
            case 'text':
            default:
                return '<input type="text" id="' . self::h($domId) . '" name="' . self::h($name) . '" value="' . self::h($f['value'] ?? '') . '" maxlength="500">';
        }
    }

    private static function derivedText(array $f): string
    {
        if ($f['derived'] === null || $f['derived'] === '' || self::sameDisplay($f, $f['value'], $f['derived'])) return '';
        $d = $f['derived'];
        return 'derived: ' . (is_float($d) || is_int($d) ? self::num($d, 3) : (is_bool($d) ? ($d ? 'on' : 'off') : (string) $d));
    }

    /** Equal as the field shows them (numbers at the field's step). */
    private static function sameDisplay(array $f, $a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            $tol = is_numeric($f['step']) ? floatval($f['step']) / 2 : 1e-6;
            return abs(floatval($a) - floatval($b)) < $tol + 1e-9;
        }
        return (string) $a === (string) $b;
    }

    private static function fieldRow(array $f): string
    {
        $domId = 'rd-' . preg_replace('/[^a-z0-9_-]/i', '-', $f['id']);
        $meta = array_filter([self::derivedText($f), (string) $f['hint']], fn($s) => $s !== '');
        return '<div class="rd-field" data-field="' . self::h($f['id']) . '">'
            . (!empty($f['editable']) ? '<label for="' . self::h($domId) . '">' . self::h($f['label']) . '</label>' : '<span class="rd-label">' . self::h($f['label']) . '</span>')
            . '<div class="rd-control">' . self::control($f) . ' ' . self::badge($f['state']) . ' ' . self::resetButton($f) . '</div>'
            . ($meta ? '<div class="rd-meta">' . self::h(implode(' · ', $meta)) . '</div>' : '')
            . '</div>';
    }

    public static function renderNpcPage(array $model, string $csrf, ?array $flash = null): string
    {
        $npc = $model['npc'];
        $out = self::styles() . '<div class="rd-wrap"><div class="rd-header"><h1>' . self::h($npc) . '</h1>'
            . '<p><a href="' . self::h(self::pageUrl()) . '">All NPCs</a> · <a href="settings.php">Global settings</a> · '
            . '<a href="' . self::h('debug_pipeline.php?npc=' . rawurlencode($npc)) . '">Dry run</a> · '
            . '<a href="' . self::h('debug_compose.php?npc=' . rawurlencode($npc)) . '">State dump</a> · '
            . ($model['tracked'] ? 'RelDyn has state for this NPC' : 'RelDyn has not seen this NPC yet: values shown are derived, a save stores them') . '</p></div>';
        $out .= self::flashHtml($flash);
        $out .= '<nav class="rd-nav" aria-label="Sections">';
        foreach ($model['sections'] as $id => $s) $out .= '<a href="#sec-' . self::h($id) . '">' . self::h($s['title']) . '</a>';
        $out .= '</nav>';
        $hidden = '<input type="hidden" name="csrf_token" value="' . self::h($csrf) . '"><input type="hidden" name="npc" value="' . self::h($npc) . '">';
        foreach ($model['sections'] as $id => $s) {
            $out .= self::renderSection($s, $hidden);
        }
        $out .= '<div class="rd-section" id="sec-reset"><h2>Fresh start</h2><p class="rd-intro">Forget everything RelDyn holds for ' . self::h($npc)
            . ': overrides, dimensions, states, counters. The next turn derives the NPC again from their bio and traits. Core affinity is not touched.</p>'
            . '<form method="post" action="' . self::h(self::PAGE) . '">' . $hidden . '<input type="hidden" name="op" value="reset_npc">'
            . '<div class="rd-actions"><label class="rd-confirm"><input type="checkbox" name="confirm" value="yes" required> I understand this cannot be undone</label>'
            . '<button type="submit" class="rd-danger">Reset the whole NPC</button></div></form></div>';
        return $out . '</div>';
    }

    private static function renderSection(array $s, string $hidden): string
    {
        $id = $s['id'];
        $editable = false;
        foreach ($s['fields'] as $f) if (!empty($f['editable'])) { $editable = true; break; }
        $out = '<section class="rd-section" id="sec-' . self::h($id) . '"><h2>' . self::h($s['title']) . '</h2>';
        if ($s['intro'] !== '') $out .= '<p class="rd-intro">' . self::h($s['intro']) . '</p>';
        $snap = [];
        foreach ($s['fields'] as $fid => $f) if (!empty($f['editable'])) $snap[$fid] = self::shownText($f);
        $out .= '<form method="post" action="' . self::h(self::PAGE) . '#sec-' . self::h($id) . '" novalidate>' . $hidden
            . '<input type="hidden" name="section" value="' . self::h($id) . '">'
            . ($snap !== [] ? '<input type="hidden" name="snap" value="' . self::h(json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">' : '');
        if ($id === 'dimensions') {
            $out .= self::renderDimensionTable($s['fields']);
        } elseif ($id === 'attraction') {
            $pillarFields = array_filter($s['fields'], fn($f) => $f['col'] !== null);
            foreach ($s['fields'] as $f) if ($f['col'] === null) $out .= self::fieldRow($f);
            $out .= self::renderPillarTable($pillarFields);
        } else {
            foreach ($s['fields'] as $f) $out .= self::fieldRow($f);
        }
        foreach ($s['blocks'] as $b) $out .= self::renderBlock($b);
        $out .= '<div class="rd-actions">';
        if ($editable) $out .= '<button type="submit" class="rd-save" name="op" value="save">Save ' . self::h(strtolower($s['title'])) . '</button>';
        if (!empty($s['resettable'])) $out .= '<button type="submit" class="rd-reset" name="op" value="reset_section" formnovalidate>' . self::h($s['reset_label']) . '</button>';
        return $out . '</div></form></section>';
    }

    private static function renderDimensionTable(array $fields): string
    {
        $rows = [];
        foreach ($fields as $f) $rows[$f['group']][$f['col']] = $f;
        $out = '<div class="rd-scroll"><table class="rd-table"><thead><tr><th>Dimension</th><th>Raw</th><th></th><th>Baseline</th><th></th>'
            . '<th>Derived baseline</th><th>Toward player</th><th>Held now</th></tr></thead><tbody>';
        foreach ($rows as $dim => $cols) {
            $x = $cols['x'];
            $b = $cols['baseline'];
            $out .= '<tr data-dim="' . self::h($dim) . '"><td>' . self::h($x['label']) . '</td>'
                . '<td>' . self::control($x) . '</td><td>' . self::badge($x['state']) . ' ' . self::resetButton($x) . '</td>'
                . '<td>' . self::control($b) . '</td><td>' . (!empty($b['editable']) ? self::badge($b['state']) . ' ' . self::resetButton($b) : '') . '</td>'
                . '<td class="num">' . self::h(is_numeric($b['derived']) ? self::num($b['derived'], 2) : '—') . '</td>'
                . '<td class="num">' . self::h(self::num($x['effective'] ?? null, 2)) . '</td>'
                . '<td class="num">' . self::h(abs(floatval($x['held'] ?? 0)) > 1e-9 ? self::num($x['held'], 2) : '—') . '</td></tr>';
        }
        return $out . '</tbody></table></div><p class="rd-intro">Affinity is shown in core units (-100..100). Warmth is derived and read-only.</p>';
    }

    private static function renderPillarTable(array $fields): string
    {
        $rows = [];
        foreach ($fields as $f) $rows[$f['group']][$f['col']] = $f;
        $out = '<div class="rd-scroll"><table class="rd-table"><thead><tr><th>Pillar</th><th>Rigidity</th><th>Weight</th><th>Passion floor</th><th>Lens share</th></tr></thead><tbody>';
        foreach ($rows as $p => $cols) {
            $out .= '<tr data-pillar="' . self::h($p) . '"><td>' . self::h(ucfirst((string) $p)) . '</td>';
            foreach (['rigidity', 'weight', 'floor', 'lens_share'] as $c) {
                $f = $cols[$c];
                $derived = self::derivedText($f);
                $out .= '<td>' . self::control($f) . ' ' . self::badge($f['state']) . ' ' . self::resetButton($f)
                    . ($derived !== '' ? '<div class="rd-meta">' . self::h($derived) . '</div>' : '') . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    private static function renderBlock(array $b): string
    {
        switch ($b['type']) {
            case 'trait_sources': return self::renderTraitSources($b);
            case 'reingest': return self::renderReingest($b);
            case 'attraction_eval': return self::renderAttractionEval($b);
            case 'spider': return self::renderSpider($b);
            case 'timers':
                $out = '<h3 class="rd-intro">Clocks</h3><div class="rd-scroll"><table class="rd-table"><thead><tr><th>Clock</th><th>Raw</th><th>Reads as</th></tr></thead><tbody>';
                foreach ($b['rows'] as $r) {
                    $out .= '<tr><td>' . self::h($r['label']) . '</td><td class="num">' . self::h(self::num($r['raw'], 0)) . '</td><td class="num">' . self::h($r['days']) . '</td></tr>';
                }
                return $out . '</tbody></table></div>';
            case 'grievances':
                if ($b['rows'] === []) return '';
                $out = '<div class="rd-scroll"><table class="rd-table"><thead><tr><th>Grievance</th><th>Kind</th><th>Points</th><th>When</th></tr></thead><tbody>';
                foreach ($b['rows'] as $g) {
                    $g = (array) $g;
                    // recordGrievance writes kind + text + amount + gamets, neglect tag + text + raw, the first accumulator
                    // text + timestamp + amount, the eval's pending ones a bare string
                    $what = $g['text'] ?? $g['reason'] ?? $g['kind'] ?? $g['tag'] ?? $g['type'] ?? $g['source'] ?? '(no description)';
                    $kind = !empty($g['pending']) ? 'pending' : ($g['kind'] ?? $g['tag'] ?? $g['type'] ?? '—');
                    $pts = $g['amount'] ?? $g['points'] ?? $g['delta'] ?? $g['raw'] ?? null;
                    if (isset($g['gamets']) || isset($g['at'])) $when = self::gameDays($g['gamets'] ?? $g['at']);
                    elseif (isset($g['timestamp']) && is_numeric($g['timestamp'])) $when = gmdate('Y-m-d H:i', intval($g['timestamp'])) . ' UTC';
                    else $when = '—';
                    $out .= '<tr><td>' . self::h($what) . '</td><td>' . self::h($kind) . '</td><td class="num">' . self::h(self::num($pts, 2))
                        . '</td><td class="num">' . self::h($when) . '</td></tr>';
                }
                return $out . '</tbody></table></div>';
            case 'held':
                if ($b['rows'] === []) return '<p class="rd-intro">Nothing is held on their dimensions right now.</p>';
                return '';
        }
        return '';
    }

    private static function renderTraitSources(array $b): string
    {
        $out = '<h3 class="rd-intro">Where the traits came from (read-only)</h3><p class="rd-intro">Assignment: ' . self::h($b['assignment'] ?? '?')
            . '; label source: ' . self::h($b['label_source'] ?? '?') . '; bio read: ' . self::h($b['read_status'] ?? '?')
            . (!empty($b['model']) ? ' (' . self::h($b['model']) . ')' : '');
        if (is_array($b['nearest'])) $out .= '; nearest preset: ' . self::h($b['nearest']['name']) . ' (distance ' . self::h(self::num($b['nearest']['distance'], 2)) . ')';
        $out .= '</p>';
        if (!empty($b['screened'])) $out .= '<p class="rd-intro">This NPC is on the read skip list: their vector is hand-set, no bio is read and no quote is stored.</p>';
        if ($b['traits'] === []) return $out;
        $out .= '<div class="rd-scroll"><table class="rd-table"><thead><tr><th>Trait</th><th>Source</th><th>Prior</th><th>Read</th><th>Conf.</th><th>Quote</th></tr></thead><tbody>';
        foreach ($b['traits'] as $name => $s) {
            $s = (array) $s;
            $source = (string) ($s['source'] ?? '?');
            if ($source === 'preset' && isset($s['preset'])) $source .= ' (' . $s['preset'] . ')';
            $quote = is_string($s['evidence'] ?? null) && $s['evidence'] !== ''
                ? '<span class="rd-quote">&ldquo;' . self::h($s['evidence']) . '&rdquo;</span>' . (isset($s['field']) ? ' <span class="rd-meta">(' . self::h($s['field']) . ')</span>' : '')
                : '—';
            $out .= '<tr><td>' . self::h($name) . '</td><td>' . self::h($source) . '</td><td class="num">' . self::h(self::num($s['prior'] ?? null, 2)) . '</td>'
                . '<td class="num">' . self::h(self::num($s['read'] ?? null, 2)) . '</td><td class="num">' . self::h(self::num($s['conf'] ?? null, 2)) . '</td><td>' . $quote . '</td></tr>';
        }
        return $out . '</tbody></table></div>';
    }

    /** The bio re-ingest's view: when the profile was last read, what is queued, the history, and the button (op reread). */
    private static function renderReingest(array $b): string
    {
        $perDay = floatval(RelationshipDynamics::GAMETS_PER_DAY);
        $day = fn($g) => is_numeric($g) ? 'game day ' . self::num(floatval($g) / $perDay, 1) : '—';
        $out = '<h3 class="rd-intro">Bio read</h3><p class="rd-intro" data-reingest="status">';
        if (($b['read_status'] ?? '') !== 'done') {
            $out .= 'No bio read has landed yet; the priors are in use.';
        } elseif ($b['last_read'] === null) {
            $out .= 'Profile last read: with the first bio read (the day was not recorded).';
        } else {
            $now = floatval(RelationshipDynamics::currentGamets());
            $ago = $now > 0 ? ' (' . self::num(max(0.0, $now - floatval($b['last_read'])) / $perDay, 1) . ' game days ago)' : '';
            $out .= 'Profile last read: ' . self::h($day($b['last_read'])) . $ago . '; ' . intval($b['reads']) . ' re-read'
                . (intval($b['reads']) === 1 ? '' : 's') . ' so far.';
        }
        $out .= '</p>';
        if (!empty($b['exempt'])) {
            return $out . '<p class="rd-intro">This NPC is not re-read: ' . self::h($b['exempt']) . '.</p>';
        }
        if (empty($b['enabled'])) {
            return $out . '<p class="rd-intro">Re-reading changed bios is switched off (trait_reader.reingest.enabled).</p>';
        }
        $out .= '<p class="rd-intro">The bio is checked for changes every ' . self::h(self::num($b['every_days'], 0)) . ' game days and after a milestone '
            . '(a romance, a bond break, a betrayal, a marriage); only a changed bio is read again. Last check: ' . self::h($day($b['last_check'])) . '.</p>';
        if (is_array($b['pending'])) {
            $out .= '<p class="rd-intro" data-reingest="pending">A re-read is queued since ' . self::h($day($b['pending']['since'] ?? null))
                . ' (' . self::h((string) ($b['pending']['reason'] ?? '')) . '). It lands the next time the background reader runs.</p>';
        }
        if (is_string($b['last_error']) && $b['last_error'] !== '') {
            $out .= '<p class="rd-intro">The last re-read failed: ' . self::h($b['last_error']) . '. It is tried again at the next check.</p>';
        }
        if ($b['history'] !== []) {
            $out .= '<div class="rd-scroll"><table class="rd-table" data-reingest="history"><thead><tr><th>Blended</th><th>Why</th><th>Inertia (share moved)</th><th>Moved</th></tr></thead><tbody>';
            foreach (array_reverse((array) $b['history']) as $h) {
                $h = (array) $h;
                $moved = [];
                foreach ((array) ($h['moved'] ?? []) as $name => $delta) $moved[] = $name . ' ' . ($delta >= 0 ? '+' : '') . self::num($delta, 2);
                $out .= '<tr><td>' . self::h($day($h['at'] ?? null)) . '</td><td>' . self::h((string) ($h['reason'] ?? '')) . '</td><td class="num">'
                    . self::h(self::num($h['k'] ?? null, 2)) . '</td><td>' . self::h($moved === [] ? 'nothing' : implode(', ', $moved)) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }
        if (!empty($b['can_reread'])) {
            $out .= '<div class="rd-actions"><button type="submit" class="rd-reset" name="op" value="reread" formnovalidate '
                . 'title="Queue a fresh read of the current bio. Unsaved slider edits above are not saved.">Read again</button></div>';
        }
        return $out;
    }

    private static function renderAttractionEval(array $b): string
    {
        if (empty($b['enabled'])) return '<p class="rd-intro">The Attraction Matrix has not evaluated the NPC yet (or it is off).</p>';
        $out = '<h3 class="rd-intro">Live evaluation (read-only)</h3><p class="rd-intro">Outcome: ' . self::h($b['outcome'] ?? '?')
            . '; curve ' . self::h(self::num($b['curve'], 2)) . '; spark ' . self::h(self::num($b['spark'], 1)) . '; passion gain x'
            . self::h(self::num($b['passion_mult'], 2)) . ($b['hard_zero'] !== null ? '; hard zero: ' . self::h($b['hard_zero']) : '')
            . ($b['won_over'] ? '; won over' : '') . ($b['friendzoned'] ? '; friendzoned' : '');
        if (is_array($b['standards'])) $out .= '; standards floor ' . self::h(self::num($b['standards']['floor'] ?? null, 1));
        $out .= '</p>';
        if ($b['units'] === []) return $out;
        $out .= '<div class="rd-scroll"><table class="rd-table"><thead><tr><th>Passion unit</th><th>Match</th><th>Floor</th><th>Met</th><th>Multiplier</th></tr></thead><tbody>';
        foreach ($b['units'] as $u) {
            $out .= '<tr><td>' . self::h($u['unit']) . '</td><td class="num">' . self::h(self::num($u['score'], 1)) . '%</td><td class="num">'
                . self::h(self::num($u['floor'], 1)) . '</td><td>' . ($u['met'] ? 'yes' : 'no') . '</td><td class="num">x' . self::h(self::num($u['m'], 2)) . '</td></tr>';
        }
        return $out . '</tbody></table></div>';
    }

    private static function renderSpider(array $b): string
    {
        $g = $b['graph'];
        $label = $b['target'] === RelDynFulfillment::PLAYER ? 'the player' : $b['target'];
        $out = '<h3 class="rd-intro">Pair with ' . self::h($label) . ': band ' . self::h(self::num($g['band'], 2)) . ($g['known'] ? '' : ' (nothing delivered yet)')
            . '; boundary ' . self::h($g['boundary']['state'] ?? 'none') . '</h3>';
        if ($g['axes'] === []) return $out . '<p class="rd-intro">No needs derived.</p>';
        // the same drawing player.php shows (RelDynUiCharts::fulfillmentSpider): needs relative to her largest, coverage -1..+1, values per axis
        $out .= '<div class="rd-spider" data-target="' . self::h($b['target']) . '">' . RelDynUiCharts::fulfillmentSpider($g)
            . '<div class="rd-scroll"><table class="rd-table"><thead><tr><th>Need</th><th>Kind</th><th>Weight</th><th>Coverage</th></tr></thead><tbody>';
        foreach ($g['axes'] as $a) {
            $out .= '<tr><td>' . self::h($a['label']) . '</td><td>' . self::h($a['kind']) . '</td><td class="num">' . self::h(self::num($a['need'], 2))
                . '</td><td class="num">' . self::h(self::num($a['coverage'], 2)) . '</td></tr>';
        }
        return $out . '</tbody></table></div></div><p class="rd-meta">Dashed: how much the NPC needs it (drawn relative to their largest need); solid: how well the bond covers it (centre -1, middle ring even, rim +1).</p>';
    }
}
