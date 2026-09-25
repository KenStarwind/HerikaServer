<?php
/**
 * RelDyn personality traits, phase 2: the before / after report for the seeded NPCs.
 *
 *   RELDYN_LIVE_PG_DSN="..." php ext/relationship_dynamics/tools/trait_read_report.php --out=FILE [--extra=JSON]
 *
 * Reads the committed seed (data/trait_reads_seed.json) and, READ ONLY, the live bio templates
 * (for the old vote's text keywords and the attachment text hits, never printed) and
 * npc_templates_v2 voice types. Ashe is skip-listed: her template is never fetched here; she
 * appears as her conclusion only.
 *
 * Before = the label assignment (phase 1): the old core-data vote's preset point. After = the
 * read assignment: bio read blended over the priors. On this fresh install core_npc_master is
 * empty, so class / faction / skills / race come from the game later: both columns use the
 * template (and the voice type) only, except the NPCs given an assumed vanilla core row
 * (ASSUMED below), where both use it. --extra: a JSON file of experiment reads to show beside
 * the seed (key => validated result), labelled as an experiment.
 * Writes markdown to --out. Pure computation after the reads; no LLM call.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$enginePath = realpath(__DIR__ . '/../../../') . '/';
require_once $enginePath . 'lib/logger.php';
require_once __DIR__ . '/../relationship_dynamics.php';
require_once __DIR__ . '/readonly_pg.php';

$opts = getopt('', ['out:', 'extra:']);
$out = $opts['out'] ?? null;
if (!$out) { fwrite(STDERR, "--out=FILE required\n"); exit(2); }
$extra = isset($opts['extra']) ? (json_decode((string) file_get_contents($opts['extra']), true) ?: []) : [];

/** Assumed vanilla core rows (class, factions, top skills, race) for the test beds and Ken's review list. */
const ASSUMED = [
    'aela_the_huntress' => ['NordRace', 'Hunter', ['CompanionsFaction', 'CompanionsCircle'], ['archery' => 72, 'sneak' => 56, 'lightarmor' => 52]],
    'muiri'             => ['BretonRace', 'Apothecary', [], ['alchemy' => 45, 'restoration' => 30]],
    'lynly_star-sung'   => ['NordRace', 'Bard', [], ['speech' => 40]],
    'lydia'             => ['NordRace', 'Warrior', ['HousecarlWhiterunFaction'], ['heavyarmor' => 50, 'block' => 45, 'onehanded' => 40]],
    'serana'            => ['NordRaceVampire', 'VampireSpellsword', [], ['destruction' => 55, 'conjuration' => 50]],
    'nazeem'            => ['RedguardRace', 'Citizen', [], ['speech' => 30]],
    'ysolda'            => ['NordRace', 'Citizen', [], ['speech' => 40]],
    'farkas'            => ['NordRace', 'Warrior', ['CompanionsFaction', 'CompanionsCircle'], ['twohanded' => 60, 'heavyarmor' => 55]],
    'mjoll_the_lioness' => ['NordRace', 'Warrior', [], ['onehanded' => 55, 'block' => 45]],
];
const REVIEW = ['aela_the_huntress', 'lydia', 'serana', 'nazeem', 'ysolda', 'farkas', 'mjoll_the_lioness'];
const BEDS = ['aela_the_huntress', 'muiri', 'lynly_star-sung'];

$seed = RelDynTraitRead::loadSeedFile();
$keys = array_keys($seed['reads']);

// ---- live data (read only), then no DB at all: defaults only for the computation
$db = new RelDynReadOnlyPg(getenv('RELDYN_LIVE_PG_DSN') ?: 'dbname=dwemer');
$tpl = [];
$voice = [];
foreach ($keys as $k) {
    if (RelDynTraitRead::isSkipped($k)) continue;
    $r = $db->fetchOne('SELECT npc_name, core, personality, relationships, npc_static_bio, speechstyle, goals, occupation FROM combined_bio_templates WHERE lower(npc_name) = lower($1)', [$k]);
    $tpl[$k] = $r;
    $v = $db->fetchOne("SELECT xvasynth_voiceid FROM npc_templates_v2 WHERE lower(regexp_replace(npc_name, '[^a-zA-Z0-9]', '', 'g')) = \$1 LIMIT 1", [RelDynTraitRead::matchKey($k)]);
    $voice[$k] = trim((string) ($v['xvasynth_voiceid'] ?? ''));
}
$db = null;
unset($GLOBALS['db']);
RelDynTraits::$assignmentOverride = 'read';
$acfg = RelationshipDynamics::getTemperamentAutogenConfig();
$attr = RelDynAttraction::defaults();

function coreRow(string $key, string $name, array $t, ?array $assumed): array
{
    $row = ['npc_name' => $name, 'personality' => $t['personality'] ?? '', 'speechstyle' => $t['speechstyle'] ?? '',
            'core' => $t['core'] ?? '', 'npc_static_bio' => $t['npc_static_bio'] ?? '', 'voiceid' => '', 'race' => ''];
    if ($assumed) {
        [$race, $class, $factions, $skills] = $assumed;
        $row['race'] = $race;
        $row['metadata'] = json_encode(['skills' => array_map('strval', $skills)]);
        $f = [];
        foreach ($factions as $n) $f[] = ['rank' => 0, 'name' => $n];
        $row['extended_data'] = json_encode(['class' => ['name' => $class], 'factions' => $f]);
    }
    return $row;
}

/** The parameters the report shows, at a vector. $label = the NPC's label (display, maturity-type fallback). */
function params(array $x, string $label, ?string $archetype, array $row, array $acfg, array $attr, array $npcPreset, bool $isRead): array
{
    $o = RelDynTraits::opennessAt($x, $attr['temperament_openness'], $attr['openness_levels']);
    $classType = $archetype !== null ? (((array) $acfg['archetype_maturity_type'])[$archetype] ?? null) : null;
    $type = $npcPreset['maturity_type'] ?? $classType;
    if ($type !== null) {
        $y = RelationshipDynamics::MATURITY_PLASTICITY_VALUES[$type];
        $mt = "{$type} " . sprintf('%.2f/%.2f', $y['Y_up'], $y['Y_down']) . ($npcPreset['maturity_type'] ?? null ? ' (npc preset)' : ' (class)');
    } else {
        [$u, $d] = RelDynTraits::maturityY($x['Rs'], $x['L']);
        $mt = RelDynTraits::maturityCorner($x) . sprintf('~ %.2f/%.2f', $u, $d);
        if (!$isRead) $mt = RelDynTraits::maturityCorner($x) . sprintf(' %.2f/%.2f', $u, $d);
    }
    $tags = $isRead ? RelDynTraitAssign::tagsOf($x) : (array) (((array) $acfg['temperament_traits'])[$label] ?? []);
    if ($archetype !== null) $tags = array_merge($tags, (array) (((array) $acfg['archetype_traits'])[$archetype] ?? []));
    $pa = $npcPreset['attachment_axes'] ?? null;
    $axes = (is_array($pa) && is_numeric($pa['anxiety'] ?? null) && is_numeric($pa['avoidance'] ?? null)) ? ['anxiety' => floatval($pa['anxiety']), 'avoidance' => floatval($pa['avoidance'])] : null;
    if ($axes === null) {
        $in = ['archetype' => $archetype, 'temperament' => $label, 'traits' => array_values(array_diff($tags, $isRead ? RelDynTraitAssign::tagsOf($x) : $tags)),
               'losses' => 0, 'text_hits' => RelationshipDynamics::attachmentTextHits($row)];
        if ($isRead) $in['dynamics'] = ['trait_vector' => RelDynTraits::toStored($x), '_trait_vector_src' => ['assignment' => 'read']];
        $a = RelationshipDynamics::deriveAttachmentAxes($in);
        $axes = ['anxiety' => $a['anxiety'], 'avoidance' => $a['avoidance']];
    }
    return [
        'passion' => RelDynTraits::value($x, 'passion_mult'),
        'open' => $o['o'], 'band' => $o['band'],
        'maturity' => $mt,
        'mat_start' => floatval($x['maturity_start'] ?? RelDynTraits::value($x, 'baseline_maturity')),
        'jeal' => RelDynTraits::value($x, 'jealousy_mult'), 'Po' => $x['Po'], 'Pr' => $x['Pr'],
        'trust' => RelDynTraits::value($x, 'baseline_trust'),
        'att' => sprintf('%.2f/%.2f %s', $axes['anxiety'], $axes['avoidance'], RelationshipDynamics::attachmentStyleOf($axes['anxiety'], $axes['avoidance'])),
        'tags' => implode(',', array_unique($tags)) ?: '-',
    ];
}

function vec(array $x): string
{
    $o = [];
    foreach (RelDynTraits::TRAITS as $c => $_) $o[] = sprintf('%s %.2f', $c, $x[$c]);
    return implode(' · ', $o);
}

function near3(array $x): string
{
    $d = [];
    foreach (RelDynTraits::points() as $n => $p) $d[$n] = RelDynTraits::distance($x, $p);
    asort($d);
    $o = [];
    foreach (array_slice($d, 0, 3, true) as $n => $v) $o[] = sprintf('%s %.2f', $n, $v);
    return implode(', ', $o);
}

$rows = [];
$stats = ['traits' => 0, 'bio' => 0, 'mismatch' => 0, 'centred' => 0, 'noev' => 0, 'extreme' => 0, 'screened' => 0, 'weak_extreme' => 0, 'rules' => []];
foreach ($keys as $k) {
    $name = RelDynTraitRead::displayName($k);
    $t = $tpl[$k] ?? [];
    $assumed = ASSUMED[$k] ?? null;
    $row = coreRow($k, $name, $t, $assumed);
    $npcPreset = (array) (((array) $acfg['npc_overrides'])[strtolower($name)] ?? []);
    // before: the old vote (label assignment)
    RelDynTraits::$assignmentOverride = 'label';
    $prof = RelationshipDynamics::deriveNpcProfile($name, $row, ['config' => $acfg]);
    $beforeLabel = $prof['temperament'];
    $archetype = $prof['archetype'];
    $bx = RelDynTraits::points()[$beforeLabel];
    $before = params($bx, $beforeLabel, $archetype, $row, $acfg, $attr, $npcPreset, false);
    // after: the read assignment
    RelDynTraits::$assignmentOverride = 'read';
    $ext = RelationshipDynamics::decodeProfileJson($row['extended_data'] ?? null);
    $fac = array_map(fn($f) => $f['name'], (array) ($ext['factions'] ?? []));
    $meta = RelationshipDynamics::decodeProfileJson($row['metadata'] ?? null);
    $prior = ['voice' => $voice[$k] ?? null, 'class' => $assumed ? RelationshipDynamics::profileArchetypes($ext, $meta, $acfg)[0] : null,
              'factions' => $fac, 'skills' => (array) ($meta['skills'] ?? []), 'race' => $row['race'] ?: null];
    $res = $seed['reads'][$k]['result'];
    $auto = RelDynTraitAssign::resolve(['prior_in' => $prior, 'read' => $res,
        'hand_set' => $npcPreset['trait_vector'] ?? null, 'maturity_start' => $npcPreset['maturity_start'] ?? null]);
    $after = params($auto['x'], $auto['label'], $archetype, $row, $acfg, $attr, $npcPreset, true);
    foreach ($res['traits'] as $tn => $tv) {
        $stats['traits']++;
        $note = $tv['note'] ?? '';
        if ($tv['conf'] > 0) $stats['bio']++; elseif ($note === '') $stats['noev']++;
        if ($note === 'quote_mismatch') $stats['mismatch']++;
        if ($note === 'centred' && !isset($tv['screen'])) $stats['centred']++;
        if ($tv['conf'] >= 0.7 && ($tv['value'] < 0.25 || $tv['value'] > 0.75)) $stats['extreme']++;
        if (isset($tv['screen'])) {
            $stats[$note === 'screened' ? 'screened' : 'weak_extreme']++;
            $stats['rules'][$tv['screen']['rule']] = ($stats['rules'][$tv['screen']['rule']] ?? 0) + 1;
        }
    }
    $rows[$k] = compact('name', 'beforeLabel', 'before', 'after', 'auto', 'res', 'archetype', 'prior', 'assumed', 'bx');
}

// ---- the conclusion-only row: Ashe (never read, template never fetched)
$asheCfg = $acfg['npc_overrides']['ashe'];
$ashe = RelDynTraitAssign::resolve(['hand_set' => $asheCfg['trait_vector'], 'maturity_start' => $asheCfg['maturity_start']]);
$asheBefore = params(RelDynTraits::points()['Guarded'], 'Guarded', 'Mage', ['npc_name' => 'Ashe'], $acfg, $attr, ['maturity_type' => 'Resilient', 'attachment_axes' => $asheCfg['attachment_axes']], false);
$asheAfter = params($ashe['x'], $ashe['label'], 'Mage', ['npc_name' => 'Ashe'], $acfg, $attr, ['maturity_type' => 'Resilient', 'attachment_axes' => $asheCfg['attachment_axes']], true);

// ---- surprises
$flags = [];
foreach ($rows as $k => $r) {
    $f = [];
    $x = $r['auto']['x'];
    $moved = RelDynTraits::distance($x, $r['bx']);
    if ($moved > 0.75) $f[] = sprintf('far from the old preset (%.2f)', $moved);
    if ($r['after']['open'] < RelDynTraits::OPENNESS_LOW_REGIME) $f[] = sprintf('openness %.3f: below the won-over switch (0.45)', $r['after']['open']);
    elseif ($r['after']['open'] < 0.47) $f[] = sprintf('openness %.3f: just above the won-over switch', $r['after']['open']);
    $rej = count(array_filter($r['res']['traits'], fn($t) => ($t['note'] ?? '') === 'quote_mismatch'));
    if ($rej >= 3) $f[] = "{$rej} quotes rejected";
    $ev = count(array_filter($r['res']['traits'], fn($t) => $t['conf'] > 0));
    if ($ev <= 3) $f[] = "thin evidence ({$ev} of 10 traits)";
    // what still moves her most (after the evidence screen): the top jealousy and protectiveness
    if ($r['after']['jeal'] >= 1.2) {
        $po = $r['auto']['src']['possessiveness'];
        $f[] = sprintf('jealousy x%.2f (possessiveness %.2f from %s)', $r['after']['jeal'], $r['after']['Po'],
            ($po['source'] ?? '') === 'bio' ? "\"{$po['evidence']}\"" : 'the prior: no jealousy quote');
    }
    $pr = $r['auto']['src']['protectiveness'];
    if ($r['after']['Pr'] >= 0.72 && ($pr['source'] ?? '') === 'bio') $f[] = sprintf('protectiveness %.2f from "%s"', $r['after']['Pr'], $pr['evidence']);
    $scr = array_filter($r['res']['traits'], fn($t) => isset($t['screen']));
    if (count($scr) >= 3) $f[] = count($scr) . ' quotes failed the evidence screen';
    if ($f) $flags[$k] = $f;
}

// ---- markdown
$fmt = fn($v) => sprintf('%.2f', $v);
$md = [];
$md[] = '## Every read NPC (before = old vote preset, after = trait vector)';
$md[] = '';
$md[] = 'Columns: passion = passion multiplier (A1); open = openness on the MDD 1.4 scale (band); maturity = type Y_up/Y_down (after: the exact formula at the NPC\'s resilience and reactivity, `~` = between corners); jeal = jealousy multiplier (A4); Po / Pr = possessiveness / protectiveness; trust = starting trust; attach = attachment anxiety/avoidance and style. Attraction floor: not yet, standards-scaled floors are phase 3 (flat 45 today).';
$md[] = '';
$md[] = '| NPC | before | after (nearest 3) | passion | open | maturity | jeal · Po · Pr | trust | attach |';
$md[] = '|---|---|---|---|---|---|---|---|---|';
foreach ($rows as $k => $r) {
    $b = $r['before'];
    $a = $r['after'];
    $md[] = sprintf('| %s%s | %s | %s | %s → **%s** | %s → **%s** (%s) | %s → **%s** | %s → **%s** · %s · %s | %s → **%s** | %s → **%s** |',
        $r['name'], $r['assumed'] ? ' *' : '', $r['beforeLabel'], near3($r['auto']['x']),
        $fmt($b['passion']), $fmt($a['passion']), sprintf('%.3f', $b['open']), sprintf('%.3f', $a['open']), $a['band'],
        $b['maturity'], $a['maturity'], $fmt($b['jeal']), $fmt($a['jeal']), $fmt($a['Po']), $fmt($a['Pr']),
        sprintf('%.0f', $b['trust']), sprintf('%.0f', $a['trust']), $b['att'], $a['att']);
}
$md[] = '';
$md[] = '\\* assumed vanilla core row (class, factions, top skills, race) used for both columns; everyone else: template and voice type only (core_npc_master is empty on this install; the game fills class, factions, skills and race when she is met).';
$md[] = '';
$md[] = '## Evidence per NPC';
$md[] = '';
$md[] = 'Each trait: the read value, its confidence, and the verbatim quote (field). `rejected` = the quote was not in that field or was over 12 words, so the trait kept its prior; `centred` = pulled into 0.25..0.75 for want of strong evidence; **not evidence** = the evidence screen (gate 2) found the quote does not show this trait of this character (duty, a quest object, someone else, job text, pride in work), so the trait kept its prior; **extreme without strong evidence** = kept at the band edge with conf 0.6. Traits with no evidence are left out.';
foreach ($rows as $k => $r) {
    $md[] = '';
    $md[] = "### {$r['name']}";
    $md[] = '';
    $md[] = 'Vector: ' . vec($r['auto']['x']) . sprintf(' · maturity_start %.0f. Prior: %s.', $r['auto']['x']['maturity_start'],
        implode(', ', $r['auto']['prior']['signals']) ?: 'base only');
    foreach ($r['res']['traits'] + ['maturity_start' => $r['res']['maturity_start']] as $tn => $t) {
        $note = $t['note'] ?? '';
        if (isset($t['screen'])) {
            $sc = $t['screen'];
            $said = $tn === 'maturity_start' ? sprintf('%.0f', $sc['value']) : $fmt($sc['value']);
            $md[] = $note === 'screened'
                ? sprintf('- %s: read %s (conf %s) from "%s" (%s): **not evidence** (%s), the prior stands', $tn, $said, $fmt($sc['conf']), $sc['evidence'], $sc['field'], $sc['rule'])
                : sprintf('- %s: read %s (conf %s) from "%s" (%s): **extreme without strong evidence** (%s), kept at %s conf %s', $tn, $said, $fmt($sc['conf']), $sc['evidence'], $sc['field'], $sc['rule'], $fmt($t['value']), $fmt($t['conf']));
            continue;
        }
        if ($t['conf'] <= 0 && $note !== 'quote_mismatch') continue;
        if ($note === 'quote_mismatch') { $md[] = sprintf('- %s: read %s, rejected quote', $tn, $tn === 'maturity_start' ? sprintf('%.0f', $t['value']) : $fmt($t['value'])); continue; }
        $md[] = sprintf('- %s: %s (conf %s%s) "%s" (%s)', $tn, $tn === 'maturity_start' ? sprintf('%.0f', $t['value']) : $fmt($t['value']), $fmt($t['conf']),
            $note === 'centred' ? ', centred' : '', $t['evidence'], $t['field']);
    }
    if (isset($flags[$k])) $md[] = '- **Flag:** ' . implode('; ', $flags[$k]);
}

$near = [];
$jb = $ja = $pb = $pa = [];
$closed = [];
foreach ($rows as $k => $r) {
    $near[$r['auto']['nearest']['name']] = ($near[$r['auto']['nearest']['name']] ?? 0) + 1;
    $jb[] = $r['before']['jeal']; $ja[] = $r['after']['jeal']; $pb[] = $r['before']['passion']; $pa[] = $r['after']['passion'];
    if ($r['after']['open'] < RelDynTraits::OPENNESS_LOW_REGIME) $closed[$r['name']] = round($r['after']['open'], 3);
}
arsort($near);
asort($closed);
$median = function (array $v) { sort($v); $n = count($v); return $n ? ($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2) : null; };
$summary = ['nearest' => $near, 'jealousy_median' => [$median($jb), $median($ja)], 'passion_median' => [$median($pb), $median($pa)], 'closed' => $closed];
$data = ['summary' => $summary, 'rows' => $rows, 'ashe' => ['vector' => $ashe, 'before' => $asheBefore, 'after' => $asheAfter], 'flags' => $flags, 'stats' => $stats, 'seed' => ['llm_calls' => $seed['llm_calls'], 'connector' => $seed['connector']]];
file_put_contents($out, implode("\n", $md) . "\n");
file_put_contents($out . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
echo "wrote {$out} (" . count($rows) . " NPCs)\n";
