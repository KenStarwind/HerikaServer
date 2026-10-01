<?php
/**
 * Relationship Dynamics — the settings hub's HTML (settings.php composes it). Everything printed goes
 * through h(): stored config, defaults, NPC names and preview text alike. Forms are POST with the
 * session's CSRF token and end in the '_complete' sentinel (RelDynSettings::handlePost).
 */

require_once __DIR__ . '/reldyn_settings.php';

final class RelDynSettingsView
{
    /** Pages other lanes build (agreed paths, relative to this page). */
    const NPC_PAGE = 'npc.php';
    const PLAYER_PAGE = 'player.php';
    const DRY_RUN_PAGE = 'debug_pipeline.php';
    const STATE_DUMP_PAGE = 'debug_compose.php';

    /** Sections with more settings than this render on request (?open=section). */
    const LAZY_LEAVES = 400;

    /** The four test beds, first in the NPC links. */
    const TEST_BEDS = ['Aela the Huntress', 'Ashe', 'Muiri', 'Lynly Star-Sung'];

    public static function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function npcUrl(string $npc): string
    {
        return self::NPC_PAGE . '?npc=' . rawurlencode($npc);
    }

    /** settings.php?… with the page's own parameters (embed kept). */
    public static function url(array $params, bool $embed = false): string
    {
        if ($embed) $params['embed'] = '1';
        $params = array_filter($params, fn($v) => $v !== null && $v !== '');
        return 'settings.php' . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '');
    }

    // =====================================================================
    // FIELDS
    // =====================================================================

    private static function fieldId(string $form, string $code): string
    {
        return 'rdf-' . substr(md5($form . '|' . $code), 0, 14);
    }

    /** Short text of a value for the "default" / "in effect" notes. */
    public static function shortValue(array $field, $v): string
    {
        if ($field['kind'] === 'bool') return $v ? 'on' : 'off';
        if ($v === null) return '(none)';
        $s = RelDynSettings::formValue($field, $v);
        if ($field['kind'] === 'lines') $s = str_replace("\n", ', ', $s);
        return $s === '' ? '(empty)' : $s;
    }

    /** The input for one leaf, showing $value. */
    public static function input(array $field, string $code, $value, string $id): string
    {
        $name = 'f[' . $code . ']';
        $n = self::h($name);
        $v = RelDynSettings::formValue($field, $value);
        switch ($field['kind']) {
            case 'bool':
                return '<input type="hidden" name="' . $n . '" value="">'
                    . '<input type="checkbox" class="rd-check" name="' . $n . '" id="' . $id . '" value="1"' . ($value ? ' checked' : '') . '>';
            case 'int':
            case 'float':
                $attrs = ' step="' . ($field['kind'] === 'int' ? '1' : 'any') . '"';
                if ($field['min'] !== null) $attrs .= ' min="' . self::h(RelDynSettings::numberText($field['min'])) . '"';
                if ($field['max'] !== null) $attrs .= ' max="' . self::h(RelDynSettings::numberText($field['max'])) . '"';
                return '<input type="number" class="rd-num" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '"' . $attrs . '>';
            case 'enum':
                $out = '<select name="' . $n . '" id="' . $id . '">';
                $options = (array) $field['options'];
                if (!in_array((string) $value, $options, true)) $options[] = (string) $value;
                foreach ($options as $o) {
                    $out .= '<option value="' . self::h($o) . '"' . ((string) $o === (string) $value ? ' selected' : '') . '>' . self::h($o) . '</option>';
                }
                return $out . '</select>';
            case 'string':
                return '<input type="text" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '">';
            case 'any':
                return '<input type="text" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '" placeholder="empty = none">';
            case 'numbers':
                if (strpos($v, "\n") === false) {
                    return '<input type="text" inputmode="decimal" name="' . $n . '" id="' . $id . '" value="' . self::h($v) . '">';
                }
                // not a plain list of numbers any more: shown (and taken back) as JSON
                return '<textarea class="rd-json" name="' . $n . '" id="' . $id . '" rows="' . min(12, substr_count($v, "\n") + 1) . '">' . self::h($v) . '</textarea>';
            case 'text':
            case 'lines':
                $rows = max(2, min(10, substr_count($v, "\n") + 1 + intdiv(strlen($v), 90)));
                return '<textarea name="' . $n . '" id="' . $id . '" rows="' . $rows . '">' . self::h($v) . '</textarea>';
            default:
                $rows = max(2, min(16, substr_count($v, "\n") + 1));
                return '<textarea class="rd-json" name="' . $n . '" id="' . $id . '" rows="' . $rows . '" spellcheck="false">' . self::h($v) . '</textarea>';
        }
    }

    /**
     * One setting: label, input (what RelDyn reads), its default, "changed" with a reset when the stored
     * row differs from the default, and "RelDyn reads" when its reader adjusts the stored value.
     */
    public static function fieldRow(string $code, array $field, $display, $stored, string $form, ?string $label = null): string
    {
        $changed = !RelDynSettings::valuesEqual($stored, $field['default']);
        $id = self::fieldId($form, $code);
        $label = $label ?? $field['label'];
        $search = strtolower($field['dotted'] . ' ' . $label);
        $out = '<div class="rd-field' . ($changed ? ' rd-changed' : '') . ($field['kind'] === 'bool' ? ' rd-field-bool' : '')
            . '" data-search="' . self::h($search) . '">';
        $out .= '<div class="rd-field-head"><label for="' . $id . '">' . self::h($label) . '</label>'
            . '<code class="rd-path">' . self::h($field['dotted']) . '</code></div>';
        $out .= '<div class="rd-field-body">' . self::input($field, $code, $display, $id) . '</div>';
        $default = self::shortValue($field, $field['default']);
        $out .= '<div class="rd-field-meta">';
        if (strlen($default) > 80 || strpos($default, "\n") !== false) {
            $out .= '<details class="rd-default"><summary>Default</summary><pre>' . self::h($default) . '</pre></details>';
        } else {
            $out .= '<span class="rd-default">Default: <span class="rd-dv">' . self::h($default) . '</span></span>';
        }
        if ($changed) {
            $out .= ' <span class="rd-badge">changed</span>'
                . ' <button type="submit" class="rd-reset" name="reset" value="' . self::h($code) . '" formnovalidate'
                . ' title="Back to the default (other edits in this form are not saved)">Reset</button>';
        }
        if (!RelDynSettings::valuesEqual($display, $stored)) {
            $out .= ' <span class="rd-note">RelDyn reads: ' . self::h(self::shortValue($field, $display)) . '</span>';
        }
        $out .= '</div>';
        if ($field['hint'] !== '') $out .= '<div class="rd-hint">' . self::h($field['hint']) . '</div>';
        return $out . '</div>';
    }

    // =====================================================================
    // NODES, FORMS, CHUNKS
    // =====================================================================

    /** Leaves (code => field) under $path. */
    public static function leavesUnder(array $path): array
    {
        $fields = RelDynSettings::fields();
        $out = [];
        foreach (RelDynSettings::leafIndex()[json_encode(array_values($path))] ?? [] as $code) $out[$code] = $fields[$code];
        return $out;
    }

    public static function varsUnder(array $path): int
    {
        return array_sum(array_map([RelDynSettings::class, 'varCount'], self::leavesUnder($path)));
    }

    /** Changed leaves (stored row differs from the default) under $path. */
    public static function changedUnder(array $path, array $overlay): int
    {
        $n = 0;
        foreach (self::leavesUnder($path) as $f) {
            if (!RelDynSettings::valuesEqual(RelDynSettings::valueAt($overlay, $f['path']), $f['default'])) $n++;
        }
        return $n;
    }

    /**
     * $path split into lists of sibling paths, each list at most MAX_FORM_VARS inputs: one form each.
     */
    public static function chunks(array $path): array
    {
        if (self::varsUnder($path) <= RelDynSettings::MAX_FORM_VARS) return [[$path]];
        $node = RelDynSettings::valueAt(RelDynSettings::defaults(), $path);
        if (!RelDynSettings::isAssoc($node)) return [[$path]];
        $out = [];
        $cur = [];
        $sum = 0;
        foreach (array_keys($node) as $k) {
            $child = array_merge($path, [$k]);
            $n = self::varsUnder($child);
            if ($n > RelDynSettings::MAX_FORM_VARS) {
                if ($cur) { $out[] = $cur; $cur = []; $sum = 0; }
                foreach (self::chunks($child) as $c) $out[] = $c;
                continue;
            }
            if ($sum + $n > RelDynSettings::MAX_FORM_VARS && $cur) { $out[] = $cur; $cur = []; $sum = 0; }
            $cur[] = $child;
            $sum += $n;
        }
        if ($cur) $out[] = $cur;
        return $out;
    }

    /** A node's fields: a leaf row, or a collapsible table of its children. */
    public static function node(array $path, array $display, array $overlay, string $form, int $depth = 0, bool $forceOpen = false): string
    {
        $default = RelDynSettings::valueAt(RelDynSettings::defaults(), $path);
        if (!RelDynSettings::isAssoc($default)) {
            $code = RelDynSettings::encodePath($path);
            $field = RelDynSettings::fields()[$code] ?? null;
            if ($field === null) return '';
            $d = RelDynSettings::valueAt($display, $path, $found);
            if (!$found) $d = $field['default'];
            $s = RelDynSettings::valueAt($overlay, $path, $sFound);
            if (!$sFound) $s = $field['default'];
            return self::fieldRow($code, $field, $d, $s, $form);
        }
        $changed = self::changedUnder($path, $overlay);
        $open = $forceOpen || $depth === 0 || $changed > 0 || count(self::leavesUnder($path)) <= 6;
        $out = '<details class="rd-node rd-depth-' . min($depth, 4) . '"' . ($open ? ' open' : '') . '>'
            . '<summary><span class="rd-node-name">' . self::h(RelDynSettings::humanize((string) end($path))) . '</span>'
            . ' <code class="rd-path">' . self::h(implode('.', array_map('strval', $path))) . '</code>'
            . ($changed ? ' <span class="rd-badge">' . $changed . ' changed</span>' : '') . '</summary><div class="rd-node-body">';
        foreach (array_keys($default) as $k) {
            $out .= self::node(array_merge($path, [$k]), $display, $overlay, $form, $depth + 1);
        }
        return $out . '</div></details>';
    }

    /**
     * A POST form with the session token. A save form starts with an off-screen Save button: Enter in a
     * field submits the form's first button, which must be Save, not the Reset of a row above it.
     */
    public static function formOpen(string $action, string $csrf, string $class = 'rd-form'): string
    {
        return '<form method="post" action="' . self::h($action) . '" class="' . self::h($class) . '">'
            . ($class === 'rd-form' ? '<button type="submit" name="save" value="1" class="rd-default-submit" tabindex="-1" aria-hidden="true">Save</button>' : '')
            . '<input type="hidden" name="csrf_token" value="' . self::h($csrf) . '">';
    }

    /** The save bar and the sentinel that proves the POST arrived whole. */
    public static function formClose(string $label = 'Save', string $extra = ''): string
    {
        return '<div class="rd-save-bar"><button type="submit" class="rd-save" name="save" value="1">' . self::h($label) . '</button>'
            . '<span class="rd-save-note">Only the settings you change are stored; the rest follow RelDyn\'s defaults.</span></div>'
            . $extra . '<input type="hidden" name="_complete" value="1"></form>';
    }

    /** The form's snapshot of what it showed (RelDynSettings::handlePost: a field posted back as shown is no edit). */
    public static function snapshotInput(array $codes, array $display): string
    {
        return '<input type="hidden" name="snap" value="' . self::h(json_encode(RelDynSettings::snapshot($codes, $display), JSON_UNESCAPED_SLASHES)) . '">';
    }

    /** A form with the given paths (nodes or leaves) rendered in order ($open: their tables start open). */
    public static function pathsForm(array $paths, array $display, array $overlay, string $action, string $csrf, string $formKey,
                                     bool $open = false): string
    {
        $out = self::formOpen($action, $csrf);
        $codes = [];
        foreach ($paths as $p) {
            $node = RelDynSettings::valueAt(RelDynSettings::defaults(), $p);
            if (count($p) === 1 && RelDynSettings::isAssoc($node)) {
                // a whole section: its card has the title, its tables open below it
                foreach (array_keys($node) as $k) $out .= self::node(array_merge($p, [$k]), $display, $overlay, $formKey, 1);
            } else {
                $out .= self::node($p, $display, $overlay, $formKey, count($p) - 1, $open);
            }
            foreach (array_keys(self::leavesUnder($p)) as $code) $codes[] = (string) $code;
        }
        return $out . self::formClose('Save', self::snapshotInput($codes, $display));
    }

    /** A reset button in a form of its own (a whole section back to its defaults). */
    public static function resetForm(array $path, string $action, string $csrf, string $label): string
    {
        return self::formOpen($action, $csrf, 'rd-form-inline')
            . '<button type="submit" class="rd-reset" name="reset" value="' . self::h(RelDynSettings::encodePath($path)) . '">' . self::h($label) . '</button>'
            . '<input type="hidden" name="_complete" value="1"></form>';
    }

    // =====================================================================
    // SETTINGS TAB
    // =====================================================================

    public static function groupNav(string $current, array $overlay, bool $embed): string
    {
        $keys = RelDynSettings::groupKeys();
        $out = '<nav class="rd-groups" aria-label="Subsystems">';
        foreach (RelDynSettings::GROUPS as $id => [$label]) {
            if ($id !== 'features' && !$keys[$id]) continue;
            if ($id === 'features') {
                $changed = 0;
                foreach (RelDynSettings::featureFields() as $f) {
                    if (!RelDynSettings::valuesEqual(RelDynSettings::valueAt($overlay, $f['path']), $f['default'])) $changed++;
                }
            } else {
                $changed = 0;
                foreach ($keys[$id] as $k) $changed += self::changedUnder([$k], $overlay);
            }
            $out .= '<a class="rd-pill' . ($id === $current ? ' active' : '') . '" href="' . self::h(self::url(['tab' => 'settings', 'group' => $id], $embed)) . '">'
                . self::h($label) . ($changed ? ' <span class="rd-pill-count">' . $changed . '</span>' : '') . '</a>';
        }
        return $out . '</nav>';
    }

    public static function filterBox(): string
    {
        return '<div class="rd-filter"><input type="search" id="rd-filter" placeholder="Filter settings by name or path…" aria-label="Filter settings"></div>';
    }

    /** Every on/off switch, one form; switched-off features stand out. */
    public static function featuresPanel(array $display, array $overlay, string $action, string $csrf): string
    {
        $out = self::formOpen($action, $csrf) . '<div class="rd-switches">';
        $codes = [];
        foreach (RelDynSettings::featureFields() as $code => $f) {
            $codes[] = (string) $code;
            $d = RelDynSettings::valueAt($display, $f['path'], $found);
            if (!$found) $d = $f['default'];
            $s = RelDynSettings::valueAt($overlay, $f['path'], $sFound);
            if (!$sFound) $s = $f['default'];
            $row = self::fieldRow((string) $code, $f, $d, $s, 'features');
            $classes = ($d ? '' : ' rd-off') . ($f['default'] ? '' : ' rd-ships-off');
            $out .= '<div class="rd-switch' . $classes . '">' . $row
                . ($f['default'] ? '' : '<span class="rd-ships-off-tag">ships off</span>') . '</div>';
        }
        return $out . '</div>' . self::formClose('Save switches', self::snapshotInput($codes, $display));
    }

    /** One group: its plain keys in one form, each table section in its own card (split into forms by size). */
    public static function groupPanel(string $group, array $display, array $overlay, string $action, string $csrf,
                                      ?string $open = null, bool $embed = false): string
    {
        $defaults = RelDynSettings::defaults();
        $keys = RelDynSettings::groupKeys()[$group] ?? [];
        $plain = array_values(array_filter($keys, fn($k) => !RelDynSettings::isAssoc($defaults[$k])));
        $sections = array_values(array_filter($keys, fn($k) => RelDynSettings::isAssoc($defaults[$k])));
        $out = '';
        if ($plain) {
            $out .= '<section class="rd-section" id="rd-sec-' . self::h($group) . '-plain"><div class="rd-section-head"><h2>Settings</h2></div>';
            foreach (array_chunk($plain, 200) as $i => $chunk) {
                $out .= self::pathsForm(array_map(fn($k) => [$k], $chunk), $display, $overlay, $action, $csrf, "plain-{$group}-{$i}");
            }
            $out .= '</section>';
        }
        foreach ($sections as $key) {
            $total = count(self::leavesUnder([$key]));
            $changed = self::changedUnder([$key], $overlay);
            $out .= '<section class="rd-section" id="rd-sec-' . self::h($key) . '"><div class="rd-section-head">'
                . '<h2>' . self::h(RelDynSettings::humanize($key)) . '</h2>'
                . '<span class="rd-count"><code class="rd-path">' . self::h($key) . '</code> · ' . $total . ' settings'
                . ($changed ? ', <b>' . $changed . ' changed</b>' : '') . '</span>'
                . ($changed ? self::resetForm([$key], $action, $csrf, 'Reset section') : '') . '</div>'
                . (isset(RelDynSettingsText::SECTION_HELP[$key]) ? '<p class="rd-section-help">' . self::h(RelDynSettingsText::SECTION_HELP[$key]) . '</p>' : '');
            if ($total > self::LAZY_LEAVES && $open !== $key) {
                // a big table (the facet classifier's) loads on demand: the page stays light on a phone
                $out .= '<a class="rd-link-card" href="' . self::h(self::url(['tab' => 'settings', 'group' => $group, 'open' => $key], $embed))
                    . '#rd-sec-' . self::h($key) . '">Show its ' . $total . ' settings</a></section>';
                continue;
            }
            foreach (self::chunks([$key]) as $i => $paths) {
                $out .= self::pathsForm($paths, $display, $overlay, $action, $csrf, "{$key}-{$i}");
            }
            $out .= '</section>';
        }
        return $out;
    }

    // =====================================================================
    // PROMPT GATING TAB
    // =====================================================================

    /** The prompt-gating settings, by what they do: paths per card. */
    public static function gatingCards(): array
    {
        $pg = fn(string ...$k) => array_merge(['prompt_gating'], $k);
        $tiers = [];
        foreach (['name_min_tier', 'floor_tier', 'bio_min_tier', 'met_min_speech', 'fame_min_core_aff', 'fame_max_tier',
                     'unknown_hold_distance', 'fame_max_lines', 'token_budget'] as $k) $tiers[] = $pg($k);
        $felt = RelDynSettings::defaults()['felt_steering'];
        $tierText = [['felt_steering', 'text', 'knowledge']];
        foreach (['player_ref_stranger', 'player_ref_hostile'] as $k) {
            if (array_key_exists($k, $felt['text'])) $tierText[] = ['felt_steering', 'text', $k];
        }
        $tierText[] = ['felt_steering', 'text', 'bridge'];
        if (array_key_exists('knowledge_token_budget', $felt)) $tierText[] = ['felt_steering', 'knowledge_token_budget'];
        $fames = [];
        foreach (array_keys(RelDynSettings::defaults()['reputation']['fames']) as $k) $fames[] = ['reputation', 'fames', $k];
        return [
            'switches' => ['Switches & tiers', 'Who knows the player: the name from name_min_tier, the story (core\'s player bio) from bio_min_tier; once at floor_tier the name is never forgotten (lapsed). Fame is heard between fame_min_core_aff and fame_max_tier.',
                array_merge([$pg('enabled'), ['context_pre_enabled'], ['reputation', 'enabled']], $tiers)],
            'tiers' => ['Tier fragments', 'The <knowledge_of_player> sentence per knowledge tier, and the tension bridge added to it. {NAME} = the NPC, {PLAYER} = the player as she knows them.',
                $tierText],
            'notes' => ['Core notes & the name instruction', 'The familiarity note on the player\'s nearby-actors entry by knowledge level, the rumour referent, and the COMMAND_PROMPT line for an NPC who does not know the name ({PLAYER_NAME} = the name).',
                [$pg('text')]],
            'fames' => ['Fame fragments', 'What the player is known for, heard where: each fame\'s line, its home hold (empty = heard everywhere), its reach in hold steps and the score it needs.',
                $fames],
            'holds' => ['Hold map', 'Borders between the nine holds (core\'s canonical names); a border listed on either side counts both ways.',
                [$pg('hold_adjacency')]],
        ];
    }

    public static function gatingPanel(array $display, array $overlay, string $action, string $csrf): string
    {
        $cfg = RelDynGating::config();
        $status = [
            'Prompt gating' => !empty($cfg['enabled']),
            'Core fork hook (strangers do not see the name)' => RelDynGating::coreHookPresent(),
            'Knowledge in <character> (context_pre)' => !empty(RelationshipDynamics::getConfig()['context_pre_enabled']),
            'Reputation / fame axis' => !empty(RelDynReputation::config()['enabled']),
        ];
        $out = '<section class="rd-section"><div class="rd-section-head"><h2>Status</h2></div><ul class="rd-status">';
        foreach ($status as $label => $on) {
            $out .= '<li class="' . ($on ? 'on' : 'off') . '"><span class="rd-dot"></span>' . self::h($label) . ': <b>' . ($on ? 'on' : 'off') . '</b></li>';
        }
        $out .= '</ul></section>';
        foreach (self::gatingCards() as $id => [$title, $blurb, $paths]) {
            $out .= '<section class="rd-section" id="rd-gating-' . self::h($id) . '"><div class="rd-section-head"><h2>' . self::h($title) . '</h2></div>'
                . '<p class="rd-blurb">' . self::h($blurb) . '</p>'
                . self::pathsForm($paths, $display, $overlay, $action, $csrf, "gating-{$id}", $id !== 'holds') . '</section>';
        }
        return $out;
    }

    /** The read-only preview: what one NPC knows, and the text at every tier. */
    public static function gatingPreviewPanel(string $npc, ?float $aff, array $names, bool $embed): string
    {
        $out = '<section class="rd-section" id="rd-gating-preview"><div class="rd-section-head"><h2>Preview for an NPC</h2></div>'
            . '<p class="rd-blurb">Read-only: nothing is saved and no LLM is called. Affinity override: core points, -100..100 (empty = her real bond).</p>'
            . '<form method="get" action="settings.php" class="rd-preview-form">'
            . '<input type="hidden" name="tab" value="gating">' . ($embed ? '<input type="hidden" name="embed" value="1">' : '')
            . '<label for="rd-preview-npc">NPC</label><input type="text" id="rd-preview-npc" name="npc" list="rd-npc-names" value="' . self::h($npc) . '" required>'
            . '<label for="rd-preview-aff">Affinity</label><input type="number" id="rd-preview-aff" name="aff" min="-100" max="100" step="1" value="'
            . ($aff === null ? '' : self::h(RelDynSettings::numberText($aff))) . '">'
            . '<button type="submit" class="rd-save">Preview</button></form>' . self::datalist($names);
        if ($npc === '') return $out . '</section>';
        try {
            $p = RelDynSettings::gatingPreview($npc, $aff);
            $tiers = RelDynSettings::gatingTierPreview($npc);
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('settings: gating preview', $e);
            return $out . '<div class="rd-msg err">The preview failed (see the server log).</div></section>';
        }
        $k = $p['knowledge'];
        $state = ['stored' => 'her RelDyn state', 'core' => 'core\'s Player entry (no RelDyn state yet)', 'none' => 'no bond on record'][$p['state']] ?? $p['state'];
        $rows = [
            'Knowledge level' => $k['level'],
            'Knows the name' => $k['name'] ? 'yes' : 'no',
            'Knows the story (bio)' => $k['bio'] ? 'yes' : 'no',
            'Core affinity now / peak' => RelDynSettings::numberText($k['core_aff']) . ' / ' . RelDynSettings::numberText($k['peak_core_aff']),
            'Read from' => $aff !== null ? 'the override' : $state,
            'Her hold' => $k['hold'] !== '' ? $k['hold'] : '(not heard / unknown)',
            'Fames heard there' => $k['fames'] ? implode(', ', array_keys($k['fames'])) : 'none',
        ];
        $out .= '<div class="rd-preview"><h3>' . self::h($p['npc']) . '</h3><table class="rd-table rd-kv">';
        foreach ($rows as $label => $v) $out .= '<tr><th>' . self::h($label) . '</th><td>' . self::h($v) . '</td></tr>';
        $out .= '</table>';
        if (!$p['gating_on']) $out .= '<div class="rd-msg err">Prompt gating is off: RelDyn does not add this text to her prompt now.</div>';
        $out .= '<h4>&lt;knowledge_of_player&gt;</h4><pre class="rd-pre">' . self::h($p['text']) . '</pre>'
            . '<h4>Note on the player\'s nearby-actors entry</h4><pre class="rd-pre">'
            . self::h($p['note'] ?? '(core\'s own note)') . '</pre>'
            . '<h4>COMMAND_PROMPT</h4><pre class="rd-pre">' . self::h($p['name_unknown'] ?? '(nothing: she knows the name)') . '</pre>';
        $out .= '<h4>At every tier</h4><p class="rd-blurb">This table changes only what she knows of the player, tier by tier. Her current feelings (let-in, a pull-back, resentment) are held fixed, so the line under each tier is what she would say about the player if only the bond were that deep.</p><div class="rd-scroll"><table class="rd-table rd-tiers"><tr><th>Tier</th><th>Affinity</th><th>Level</th><th>&lt;knowledge_of_player&gt;</th></tr>';
        foreach ($tiers as $tier => $t) {
            $out .= '<tr><td>' . self::h($tier) . '</td><td>' . self::h(RelDynSettings::numberText($t['core_aff'])) . '</td><td>'
                . self::h($t['knowledge']['level']) . '</td><td>' . self::h($t['text']) . '</td></tr>';
        }
        return $out . '</table></div></div></section>';
    }

    public static function datalist(array $names): string
    {
        $out = '<datalist id="rd-npc-names">';
        foreach ($names as $n) $out .= '<option value="' . self::h($n) . '">';
        return $out . '</datalist>';
    }

    // =====================================================================
    // NPCs & PLAYER TAB
    // =====================================================================

    public static function peoplePanel(array $names, bool $embed): string
    {
        $out = '<section class="rd-section"><div class="rd-section-head"><h2>Player profile</h2></div>'
            . '<p class="rd-blurb">How NPCs experience the player: the behavioural mirror, and the shareable spider-graph card.</p>'
            . '<a class="rd-link-card" href="' . self::h(self::PLAYER_PAGE) . '">Open the player profile</a></section>';
        $out .= '<section class="rd-section"><div class="rd-section-head"><h2>Debug tools</h2></div>'
            . '<p class="rd-blurb">Read-only: run an evaluation through the pipeline without saving it, or dump one NPC\'s current state as text.</p>'
            . '<a class="rd-link-card" href="' . self::h(self::DRY_RUN_PAGE) . '">Pipeline dry run</a> '
            . '<a class="rd-link-card" href="' . self::h(self::STATE_DUMP_PAGE) . '">NPC state dump</a></section>';
        $out .= '<section class="rd-section"><div class="rd-section-head"><h2>NPC editor</h2></div>'
            . '<p class="rd-blurb">Every RelDyn parameter of one NPC: dimensions, baselines, traits, attachment, love languages, interests, jealousy, resentment, flags and timers.</p>'
            . '<form method="get" action="' . self::h(self::NPC_PAGE) . '" class="rd-preview-form">'
            . '<label for="rd-open-npc">NPC</label><input type="text" id="rd-open-npc" name="npc" list="rd-npc-names" required>'
            . '<button type="submit" class="rd-save">Open</button></form>' . self::datalist($names);
        $out .= '<h3>Test beds</h3><ul class="rd-npc-links rd-beds">';
        foreach (self::TEST_BEDS as $n) $out .= '<li><a href="' . self::h(self::npcUrl($n)) . '">' . self::h($n) . '</a></li>';
        $out .= '</ul>';
        $out .= '<h3>All NPCs <span class="rd-count">' . count($names) . '</span></h3>';
        if (!$names) {
            $out .= '<p class="rd-blurb">No NPCs in core_npc_master yet.</p>';
        } else {
            $out .= '<div class="rd-filter"><input type="search" id="rd-npc-filter" placeholder="Filter NPCs…" aria-label="Filter NPCs"></div><ul class="rd-npc-links" id="rd-npc-list">';
            foreach ($names as $n) {
                $out .= '<li data-search="' . self::h(strtolower($n)) . '"><a href="' . self::h(self::npcUrl($n)) . '">' . self::h($n) . '</a></li>';
            }
            $out .= '</ul>';
        }
        return $out . '</section>';
    }

    // =====================================================================
    // CHROME
    // =====================================================================

    public static function messages(array $result): string
    {
        $out = '';
        foreach ((array) ($result['errors'] ?? []) as $e) $out .= '<div class="rd-msg err" role="alert">' . self::h($e) . '</div>';
        foreach ((array) ($result['messages'] ?? []) as $m) $out .= '<div class="rd-msg ok" role="status">' . self::h($m) . '</div>';
        return $out;
    }

    public static function tabs(string $current, bool $embed): string
    {
        $tabs = ['settings' => 'Settings', 'gating' => 'Prompt gating', 'people' => 'NPCs & player', 'reference' => 'Reference'];
        $out = '<nav class="rd-tabs" aria-label="RelDyn hub">';
        foreach ($tabs as $id => $label) {
            $out .= '<a class="rd-tab' . ($id === $current ? ' active' : '') . '" href="' . self::h(self::url(['tab' => $id], $embed)) . '"'
                . ($id === $current ? ' aria-current="page"' : '') . '>' . self::h($label) . '</a>';
        }
        return $out . '</nav>';
    }
}
