<?php
/**
 * Relationship Dynamics — Player profile (player-stats-pipeline; rulings 2026-09-24 §9)
 *
 * What CHIM 3.4.1 actually knows about the player, and what it adds up to. The Attraction
 * Matrix (MDD §2) is NPC-subjective: this file only measures the PLAYER; each NPC's
 * weights, rigidity and ceilings are the attraction lane's (attractionFor()).
 *
 * Sources (all written by CHIM core from the AIAgent plugin; RelDyn never guesses):
 *   core_player.skills      gamedata.php 'skills' (18 actor values, raw 0..100+)
 *   core_player.stats       gamedata.php 'stats' (level, health/magicka/stamina)
 *   core_player.equipment   gamedata.php 'equipment' (per slot: name, baseid, keywords)
 *   core_player.inventory   gamedata.php 'inventory' (gold = baseid 0000000F)
 *   tracked stats           Skyrim's misc stats ("Quests Completed", "People Killed", ...):
 *                           core_player.<stat> (gamedata.php 'skyrim_stats')
 *                           else conf_opts.<stat> (AIAgentPapyrusFunctions OnTrackedStatsEvent
 *                           -> 'setconf', comm.php). Both are playthrough state in CHIM 3.4.1:
 *                           core_player is a playthrough table and an unknown conf_opts key is a
 *                           gameplay row (lib/playthrough_selection.sql is_global_setting), so a
 *                           fresh playthrough empties them and a save carries them. A stat the
 *                           game has not reported in this playthrough is unknown, never 0.
 *   eventlog 'death'        "(Context location: X)<Player> has defeated <victim>[(powerful enemy)]
 *                           [(powerful DRAGON)] with <weapon>" (Plugin.cpp TESDeathEvent)
 *   eventlog infoplayer / playerinfo   level, race (newest line); gender: core_player.gender
 *                           (Player Management), else the newest line that carries one (only
 *                           'infoplayer' does; the 'playerinfo' line every game load sends has none)
 *   quests (journal)        comm.php '_quest' from the plugin's ProcedureSendActiveQuests: active
 *                           quests with an objective DISPLAYED in the journal; id_quest = quest
 *                           editor id (C00, MG01, TG02...). questlog is not used: it also records
 *                           quests the engine starts in the background (live 2026-09-24: seven
 *                           Companions radiant quests at stage 0/1 for a level-1 prisoner).
 *   core_player.appearance  Player Management text (beauty input; beauty itself is per NPC)
 *   eventlog 'npcspellcast' "<player> casts <spell>[ on <target>]" (Plugin.cpp TESSpellCastEvent for
 *                           the player, logged while DETECT_MAGIC_EVENT is on): the magic the player
 *                           uses, with a staff in either hand (core_player.equipment), read by
 *                           subject (decisions §10: nature magic is the druid's, not the scholar's).
 *                           The plugin fills left_hand / right_hand from GetInventory() only, so a
 *                           hand holds a staff, a weapon, a shield or a torch, never a spell: the
 *                           player's spells are known from their casts alone.
 *   core_player.transformation_state   is_werewolf_form: beast form (druid deed)
 *   core_player.reldyn_gold_ledger     RelDyn's own ledger (recordGoldSnapshot): gold moved
 *                           = sum of |wallet change| between inventory snapshots, plus the wallet
 *                           at the first snapshot (gold earned before RelDyn watched). Economic
 *                           footprint = lifetime earned + spent >= opening + moved (attraction
 *                           design: "gold moved, not the wallet"); a footprint of 0 is unknown.
 * No core source in 3.4.1: thane titles, faction ranks (factions.player_rank is the vendor
 * faction's REACTION to the player, not membership), gold spent at merchants.
 *
 * Output (shared contract, RelDynPlayer::profile()):
 *   'archetypes' => [warrior, mage, druid, thief, bard, scholar, smith, hunter, healer, noble
 *                    => 0..1 | null]   identity: which kinds of person the player is. The
 *                    dominant one reads 1.0 once the character is formed (raw >= identity
 *                    floor); an unformed character reads low everywhere. null = no input known.
 *   'archetype_raw' => same keys, the raw score before normalisation: magnitude as that archetype.
 *   'evidence'   => evidence key => count|null (NPC-subjective status markers, evidenceScore).
 *   'pillars'    => ['strength' => 0..1|null, 'status' => 0..1|null, 'competence' => 0..1|null,
 *                    'beauty' => null]   magnitude, NPC-independent (the NPC weights them).
 *   'facts'      => [key => ['value' => ..., 'source' => string|null, 'note'? => string]]
 *   'derivation' => how each archetype / pillar was built (component values, null = unknown)
 *   'known'      => true when any real player input exists.
 *
 * Math (all tunables in config 'player_profile', see configDefaults()):
 *   skill progress  p(s) = clamp((skill - skill_floor) / (skill_cap - skill_floor), 0, 1)
 *   saturation      sat(n, half) = n / (n + half)            (half = count that reads 0.5)
 *   evidence (or)   E = 1 - prod(1 - w_i * sat(n_i, half_i)) over the KNOWN inputs
 *   combine         weighted mean of the KNOWN components (unknown ones are left out)
 *   archetype raw   combine(skills: mean of the top-N weighted p(s), deeds: E, gear: E(worn keywords))
 *   archetype       raw / max(max raw over archetypes, archetype_identity_floor)
 */

require_once __DIR__ . '/relationship_dynamics.php';

class RelDynPlayer
{
    /** Contract archetypes, in contract order. */
    const ARCHETYPES = ['warrior', 'mage', 'druid', 'thief', 'bard', 'scholar', 'smith', 'hunter', 'healer', 'noble'];

    /** The 18 skill keys the AIAgent plugin sends (gamedata.php 'skills'). */
    const SKILLS = ['alchemy', 'alteration', 'archery', 'block', 'conjuration', 'destruction', 'enchanting',
        'heavyarmor', 'illusion', 'lightarmor', 'lockpicking', 'onehanded', 'pickpocket', 'restoration',
        'smithing', 'sneak', 'speechcraft', 'twohanded'];

    /** Tracked-stat kill categories summed into total kills. */
    const KILL_STATS = ['People Killed', 'Animals Killed', 'Creatures Killed', 'Undead Killed', 'Daedra Killed', 'Automatons Killed'];

    /** core_player row RelDyn keeps its gold ledger in (playthrough-scoped like every core_player row). */
    const LEDGER_KEY = 'reldyn_gold_ledger';

    /** Skyrim's Gold001 form id as the plugin formats baseids ({:08X}). */
    const GOLD_BASEID = '0000000F';

    /** Per-request cache: [scope token, profile]. Only used inside a RelationshipDynamics request scope. */
    private static $cache = null;

    // =====================================================================
    // CONFIG
    // =====================================================================

    /**
     * Defaults for config 'player_profile'. Deed tables map an evidence key to [half, weight]:
     *   'stat:<Tracked Stat Name>'   Skyrim tracked stat (case-insensitive)
     *   'questline:<id>'             distinct quests of that questline in the journal (quests)
     *   'journal:quests'             distinct quests in the journal, any line (quests)
     *   'kills:total'                sum of the tracked kill stats, else eventlog player kills
     *   'dragons'                    'Dragon Souls Collected', else eventlog dragon kills
     *   'eventlog:powerful_kills'    player kills the plugin marked "(powerful enemy)"
     *   'ledger:moved'               economic footprint: opening wallet + gold moved (RelDyn
     *                                gold ledger); unknown while it is 0 (a lower bound of 0 says nothing)
     *   'form:beast'                 1 while the player is in beast form (core_player.transformation_state)
     * Gear tables map an equipment keyword (core_player.equipment *_keywords) to a weight.
     * The 'spells' component (decisions §10: a spell reads by its subject, not by its school):
     * the player's magic is every staff held in either hand (staff_held_uses uses; the plugin
     * reports inventory items there, never a spell) and every spell the player cast (eventlog 'npcspellcast' "<player> casts <spell>[ on <target>]",
     * one use each), each read by RelDynFacetClassifier::spellReading (school + subject ->
     * archetype weights); spells = share x volume, share = sum(uses x weight) / sum(uses),
     * volume = sat(sum(uses), spell_uses_half). No magic read at all: unknown.
     * An archetype's 'requires' lists components that must be known, else the archetype is null.
     * An archetype's 'anchor' is what it cannot be without: raw x min(1, anchor / full), anchor =
     * the largest of its 'components' (component values, unknown = 0) and the soft-or of its
     * 'evidence' table (the deed format); full = the anchor (0..1) at which it counts in full.
     */
    public static function configDefaults(): array
    {
        return [
            'skill_floor' => 15.0,             // skill points: Skyrim's base for a non-racial skill (progress 0)
            'skill_cap' => 100.0,              // skill points: progress 1.0
            'level_cap' => 50,                 // character level that reads 1.0 in the strength pillar
            'archetype_identity_floor' => 0.25, // raw archetype score (0..1) a character needs to be "formed"
            // Default component weights of an archetype (an archetype may override with 'components').
            'archetype_components' => ['skills' => 0.6, 'deeds' => 0.3, 'gear' => 0.1],
            // The spells component (decisions §10)
            'staff_held_uses' => 5,            // uses a staff in hand counts for (a cast counts 1)
            'spell_uses_half' => 10,           // total uses at which the magic reads half its volume
            'spell_cast_scan_limit' => 2000,   // newest player spell-cast lines read from the eventlog
            // Questline -> quest editor-id prefixes (quests.id_quest). Vanilla + DLC.
            'questlines' => [
                'main'             => ['MQ'],
                'companions'       => ['C00', 'C01', 'C02', 'C03', 'C04', 'C05', 'C06', 'CR'],
                'college'          => ['MG'],
                'thieves_guild'    => ['TG'],
                'dark_brotherhood' => ['DB'],
                'civil_war'        => ['CW'],
                'daedric'          => ['DA'],
                'dawnguard'        => ['DLC1'],
                'dragonborn_dlc'   => ['DLC2'],
            ],
            'archetypes' => [
                'warrior' => [
                    'skills' => ['onehanded' => 1.0, 'twohanded' => 1.0, 'block' => 0.8, 'heavyarmor' => 0.8], 'top' => 2,
                    'deeds' => [
                        'stat:People Killed' => [50, 0.6], 'stat:Creatures Killed' => [60, 0.5], 'stat:Brawls Won' => [3, 0.4],
                        'stat:The Companions Quests Completed' => [3, 1.0], 'stat:Civil War Quests Completed' => [4, 0.7],
                        'questline:companions' => [3, 0.8], 'questline:civil_war' => [4, 0.6],
                    ],
                    'gear' => ['ArmorHeavy' => 1.0, 'ArmorShield' => 0.7, 'WeapTypeGreatsword' => 0.8, 'WeapTypeBattleaxe' => 0.8,
                        'WeapTypeWarhammer' => 0.8, 'WeapTypeSword' => 0.6, 'WeapTypeWarAxe' => 0.6, 'WeapTypeMace' => 0.6],
                ],
                'mage' => [
                    'components' => ['skills' => 0.5, 'deeds' => 0.25, 'gear' => 0.1, 'spells' => 0.15],
                    'skills' => ['destruction' => 1.0, 'conjuration' => 1.0, 'alteration' => 0.8, 'illusion' => 0.8], 'top' => 2,
                    'deeds' => [
                        'stat:Spells Learned' => [15, 0.7], 'stat:Souls Trapped' => [20, 0.4],
                        'stat:College of Winterhold Quests Completed' => [3, 1.0], 'questline:college' => [3, 0.8],
                    ],
                    'gear' => ['WeapTypeStaff' => 1.0, 'MagicDamageFire' => 0.8, 'MagicDamageFrost' => 0.8, 'MagicDamageShock' => 0.8],
                ],
                'druid' => [
                    // Decisions §10: nature magic reads by subject (animals, plants, weather,
                    // shapeshifting, beast calls), never by school, so no magic school counts
                    // here: herb lore (alchemy), harvesting, beast form, and the nature share of
                    // the magic the player holds and casts (spells).
                    'components' => ['skills' => 0.25, 'deeds' => 0.35, 'gear' => 0.1, 'spells' => 0.3],
                    'skills' => ['alchemy' => 1.0], 'top' => 1,
                    'deeds' => [
                        // every character picks plants on the road: 300 is the half, not 100
                        'stat:Ingredients Harvested' => [300, 0.5], 'stat:Nirnroots Found' => [5, 0.6],
                        'stat:Wings Plucked' => [20, 0.4], 'stat:Werewolf Transformations' => [5, 0.5],
                        'form:beast' => [1, 0.6],
                    ],
                    'gear' => ['ArmorMaterialForsworn' => 0.8, 'ArmorMaterialHide' => 0.3],
                    // Ken: "Nature spells no, hence the druid": nature magic (the spells
                    // component) or beast blood is what makes a druid; herb lore, harvesting and
                    // hide armour only support one. Without them the player is an herbalist.
                    'anchor' => [
                        'components' => ['spells'],
                        'evidence' => ['stat:Werewolf Transformations' => [5, 0.5], 'form:beast' => [1, 0.6]],
                        'full' => 0.3,
                    ],
                ],
                'thief' => [
                    'skills' => ['sneak' => 1.0, 'lockpicking' => 1.0, 'pickpocket' => 1.0, 'lightarmor' => 0.5], 'top' => 2,
                    'deeds' => [
                        'stat:Locks Picked' => [40, 0.7], 'stat:Pockets Picked' => [20, 0.8], 'stat:Items Stolen' => [50, 0.7],
                        'stat:Backstabs' => [10, 0.5], "stat:Thieves' Guild Quests Completed" => [3, 1.0],
                        'stat:The Dark Brotherhood Quests Completed' => [3, 0.8],
                        'questline:thieves_guild' => [3, 0.8], 'questline:dark_brotherhood' => [3, 0.7],
                    ],
                    'gear' => ['WeapTypeDagger' => 0.6, 'ArmorLight' => 0.3],
                ],
                'bard' => [
                    'skills' => ['speechcraft' => 1.0, 'illusion' => 0.5], 'top' => 1,
                    'deeds' => ['stat:Persuasions' => [10, 1.0], 'stat:Bribes' => [5, 0.6], 'stat:Intimidations' => [5, 0.5]],
                    'gear' => [],
                ],
                'scholar' => [
                    'skills' => ['enchanting' => 1.0, 'alteration' => 0.8, 'illusion' => 0.6, 'restoration' => 0.5, 'alchemy' => 0.5], 'top' => 2,
                    'deeds' => [
                        'stat:Books Read' => [40, 1.0], 'stat:Skill Books Read' => [10, 0.8],
                        'stat:College of Winterhold Quests Completed' => [3, 0.6], 'questline:college' => [3, 0.5],
                        'stat:Locations Discovered' => [60, 0.3],
                    ],
                    'gear' => [],
                ],
                'smith' => [
                    'skills' => ['smithing' => 1.0, 'enchanting' => 0.4], 'top' => 1,
                    'deeds' => ['stat:Weapons Made' => [15, 1.0], 'stat:Armor Made' => [15, 1.0],
                        'stat:Weapons Improved' => [20, 0.7], 'stat:Armor Improved' => [20, 0.7]],
                    'gear' => [],
                ],
                'hunter' => [
                    'skills' => ['archery' => 1.0, 'sneak' => 0.7, 'lightarmor' => 0.5], 'top' => 2,
                    'deeds' => ['stat:Animals Killed' => [40, 1.0], 'stat:Sneak Attacks' => [20, 0.4]],
                    'gear' => ['WeapTypeBow' => 1.0, 'ArmorMaterialHide' => 0.5, 'ArmorMaterialLeather' => 0.3],
                ],
                'healer' => [
                    'components' => ['skills' => 0.5, 'deeds' => 0.25, 'gear' => 0.1, 'spells' => 0.15],
                    'skills' => ['restoration' => 1.0, 'alchemy' => 0.7], 'top' => 1,
                    'deeds' => ['stat:Potions Mixed' => [20, 0.7]],
                    'gear' => ['MagicRestoreHealth' => 1.0],
                ],
                'noble' => [
                    // Standing and means, not a skill set: deeds and dress carry it, and without
                    // any standing evidence (houses, wealth) noble is unknown, not read off clothes.
                    'components' => ['skills' => 0.2, 'deeds' => 0.6, 'gear' => 0.2],
                    'requires' => ['deeds'],
                    'skills' => ['speechcraft' => 0.6], 'top' => 1,
                    'deeds' => ['stat:Houses Owned' => [1, 1.0], 'stat:Stores Invested In' => [2, 0.7],
                        'stat:Most Gold Carried' => [10000, 0.6], 'ledger:moved' => [20000, 0.6], 'stat:Bribes' => [5, 0.3]],
                    'gear' => ['ClothingRich' => 1.0, 'ClothingCirclet' => 0.6, 'ArmorJewelry' => 0.4],
                ],
            ],
            // Pillar = weighted mean of its KNOWN components. level / combat_skills / mastery are
            // skill-derived (params below); every other component is an evidence table.
            'pillars' => [
                'strength'   => ['level' => 0.25, 'combat_skills' => 0.4, 'kills' => 0.2, 'dragons' => 0.15],
                'status'     => ['wealth' => 0.4, 'property' => 0.3, 'standing' => 0.3],
                'competence' => ['quests' => 0.35, 'exploration' => 0.2, 'mastery' => 0.25, 'craft' => 0.1, 'combat_record' => 0.1],
            ],
            'combat_skills' => ['onehanded', 'twohanded', 'archery', 'block', 'heavyarmor', 'lightarmor', 'destruction', 'conjuration'],
            'combat_skills_top' => 3,          // best N combat skills averaged
            'mastery_top' => 5,                // best N skills (any) averaged
            'pillar_components' => [
                'kills'         => ['kills:total' => [150, 1.0]],
                'dragons'       => ['dragons' => [3, 1.0]],
                'wealth'        => ['ledger:moved' => [20000, 1.0], 'stat:Gold Found' => [20000, 1.0],
                                    'stat:Most Gold Carried' => [10000, 0.7], 'stat:Barters' => [150, 0.5]],
                'property'      => ['stat:Houses Owned' => [1, 1.0], 'stat:Stores Invested In' => [2, 0.5]],
                'standing'      => ['stat:Questlines Completed' => [2, 1.0], 'stat:Main Quests Completed' => [10, 0.6]],
                'quests'        => ['stat:Quests Completed' => [25, 1.0], 'stat:Questlines Completed' => [2, 0.8],
                                    'journal:quests' => [10, 0.6]],
                'exploration'   => ['stat:Dungeons Cleared' => [15, 1.0], 'stat:Locations Discovered' => [60, 0.5]],
                'craft'         => ['stat:Weapons Made' => [20, 1.0], 'stat:Armor Made' => [20, 1.0],
                                    'stat:Potions Mixed' => [20, 1.0], 'stat:Magic Items Made' => [20, 1.0]],
                'combat_record' => ['kills:total' => [150, 1.0], 'eventlog:powerful_kills' => [10, 0.7]],
            ],
        ];
    }

    public static function config(): array
    {
        $cfg = RelationshipDynamics::getConfig()['player_profile'] ?? [];
        return array_replace(self::configDefaults(), is_array($cfg) ? $cfg : []);
    }

    // =====================================================================
    // PROFILE
    // =====================================================================

    /** The player's profile (shared contract). Cached for the current request scope only. */
    public static function profile(): array
    {
        $token = RelationshipDynamics::requestScopeToken();
        if ($token !== null && is_array(self::$cache) && self::$cache[0] === $token) {
            return self::$cache[1];
        }
        $profile = self::build(self::config());
        self::$cache = $token !== null ? [$token, $profile] : null;
        return $profile;
    }

    private static function build(array $cfg): array
    {
        $facts = self::readFacts($cfg);
        $ev = self::evidence($facts);

        // ---- archetypes
        $raw = [];
        $archDerivation = [];
        foreach (self::ARCHETYPES as $name) {
            $spec = $cfg['archetypes'][$name] ?? [];
            $parts = [
                'skills' => self::skillComponent($facts, (array) ($spec['skills'] ?? []), intval($spec['top'] ?? 1), $cfg),
                'deeds'  => self::evidenceOr($ev, (array) ($spec['deeds'] ?? [])),
                'gear'   => self::gearComponent($facts, (array) ($spec['gear'] ?? [])),
                'spells' => self::spellComponent($facts, $name, $cfg),
            ];
            $weights = (array) ($spec['components'] ?? $cfg['archetype_components']);
            $missing = array_filter((array) ($spec['requires'] ?? []), fn($c) => ($parts[$c] ?? null) === null);
            $raw[$name] = $missing ? null : self::weightedKnownMean($parts, $weights);
            $anchor = is_array($spec['anchor'] ?? null) ? self::anchorFactor($spec['anchor'], $parts, $ev) : null;
            if ($anchor !== null && $raw[$name] !== null) $raw[$name] *= $anchor['factor'];
            $archDerivation[$name] = $parts + ($anchor !== null ? ['anchor' => $anchor] : []) + ['raw' => $raw[$name]];
        }
        $knownRaw = array_filter($raw, fn($v) => $v !== null);
        $scale = $knownRaw ? max(max($knownRaw), floatval($cfg['archetype_identity_floor'])) : 1.0;
        $archetypes = [];
        foreach ($raw as $name => $v) {
            $archetypes[$name] = $v === null ? null : ($scale > 0 ? min(1.0, $v / $scale) : 0.0);
        }

        // ---- pillars
        $pillars = [];
        $pillarDerivation = [];
        foreach (['strength', 'status', 'competence'] as $pillar) {
            $components = [];
            foreach ((array) ($cfg['pillars'][$pillar] ?? []) as $component => $w) {
                $components[$component] = self::pillarComponent($component, $facts, $ev, $cfg);
            }
            $pillars[$pillar] = self::weightedKnownMean($components, (array) $cfg['pillars'][$pillar]);
            $pillarDerivation[$pillar] = ['components' => $components];
        }
        // MDD 2.1: beauty is "do *I* find you attractive" — cosine of the appearance text against
        // each NPC's keywords, so there is no player-only number. The text is in facts['appearance'].
        $pillars['beauty'] = null;
        $pillarDerivation['beauty'] = ['components' => [], 'note' => 'NPC-subjective: attraction scores facts.appearance per NPC'];

        return [
            'archetypes' => $archetypes,
            // Magnitude as each archetype (raw, before the identity normalisation): how much of
            // it the player has, for NPC-weighted pillars (MDD 2.2); null = unknown
            'archetype_raw' => array_map(fn($v) => $v === null ? null : round($v, 4), $raw),
            'pillars'    => $pillars,
            'facts'      => $facts,
            'derivation' => ['archetypes' => $archDerivation, 'pillars' => $pillarDerivation],
            // Evidence key => count (null = unknown), for NPC-subjective markers (evidenceScore)
            'evidence'   => $ev,
            'known'      => self::anyKnown($facts),
        ];
    }

    private static function anyKnown(array $facts): bool
    {
        foreach (['skills', 'level', 'equipment_keywords', 'gold_carried', 'gold_footprint', 'spells'] as $k) {
            if (($facts[$k]['value'] ?? null) !== null) return true;
        }
        foreach ($facts as $k => $f) {
            if (strpos($k, 'stat:') === 0 && $f['value'] !== null) return true;
        }
        return intval($facts['eventlog_player_kills']['value'] ?? 0) > 0
            || intval($facts['questlines']['journal_quests'] ?? 0) > 0;
    }

    // =====================================================================
    // FACTS (raw inputs, each with its source)
    // =====================================================================

    private static function fact($value, ?string $source, ?string $note = null): array
    {
        $f = ['value' => $value, 'source' => $value === null ? null : $source];
        if ($note !== null) $f['note'] = $note;
        return $f;
    }

    /** Every stat name any config table references (canonical spelling from the config). */
    private static function statNames(array $cfg): array
    {
        $names = self::KILL_STATS;
        $names[] = 'Dragon Souls Collected';
        $names[] = 'Level Increases';
        $tables = [];
        foreach ((array) $cfg['archetypes'] as $spec) $tables[] = (array) ($spec['deeds'] ?? []);
        foreach ((array) $cfg['pillar_components'] as $table) $tables[] = (array) $table;
        // the reputation layer's fame / infamy evidence (reldyn_reputation.php) reads tracked stats too
        if (class_exists('RelDynReputation', false)) {
            foreach (RelDynReputation::evidenceTables() as $table) $tables[] = (array) $table;
        }
        foreach ($tables as $table) {
            foreach (array_keys($table) as $key) {
                if (strpos($key, 'stat:') === 0) $names[] = substr($key, 5);
            }
        }
        $byLower = [];
        foreach ($names as $n) $byLower[strtolower($n)] = $byLower[strtolower($n)] ?? $n;
        return $byLower;
    }

    private static function readFacts(array $cfg): array
    {
        $db = $GLOBALS['db'] ?? null;
        $facts = [];
        $player = self::coreRows($db, ['skills', 'stats', 'equipment', 'inventory', 'appearance', 'transformation_state', 'gender', self::LEDGER_KEY]);

        // skills (raw actor values)
        $skills = self::decodeRow($player, 'skills');
        $clean = null;
        if (is_array($skills)) {
            $clean = [];
            foreach (self::SKILLS as $s) {
                if (isset($skills[$s]) && is_numeric($skills[$s])) $clean[$s] = floatval($skills[$s]);
            }
            if (!$clean) $clean = null;
        }
        $facts['skills'] = self::fact($clean, 'core_player.skills');

        // equipment keywords worn
        $equipment = self::decodeRow($player, 'equipment');
        $keywords = null;
        if (is_array($equipment)) {
            $keywords = [];
            foreach ($equipment as $slot => $v) {
                if (substr((string) $slot, -9) === '_keywords' && is_array($v)) {
                    foreach ($v as $kw) $keywords[(string) $kw] = true;
                }
            }
            $keywords = array_keys($keywords);
        }
        $facts['equipment_keywords'] = self::fact($keywords, 'core_player.equipment');
        $held = null;
        if (is_array($equipment)) {
            $held = [];
            foreach (['left_hand', 'right_hand'] as $slot) {
                $name = trim((string) ($equipment[$slot] ?? ''));
                if ($name !== '') $held[] = $name;
            }
        }
        $facts['held_names'] = self::fact($held, 'core_player.equipment (left_hand / right_hand)',
            'what is held (inventory items: a staff reads by its subject, decisions §10)');
        $facts['spells'] = self::spellFacts($db, is_array($equipment) ? $equipment : null, $cfg);

        // wallet (a fact, not status) and the gold ledger (footprint)
        $inventory = self::decodeRow($player, 'inventory');
        $facts['gold_carried'] = self::fact(is_array($inventory) ? self::goldIn($inventory) : null, 'core_player.inventory',
            'wallet at the last inventory snapshot: not used for status (economic footprint is gold moved)');
        $ledger = self::decodeRow($player, self::LEDGER_KEY);
        $facts['gold_moved'] = is_array($ledger) && isset($ledger['moved'])
            ? ['value' => intval($ledger['moved']), 'source' => 'core_player.' . self::LEDGER_KEY,
               'gained' => intval($ledger['gained'] ?? 0), 'spent' => intval($ledger['spent'] ?? 0),
               'snapshots' => intval($ledger['snapshots'] ?? 0), 'since_gamets' => $ledger['since_gamets'] ?? null,
               'opening' => isset($ledger['opening']) ? intval($ledger['opening']) : null,
               'note' => 'sum of |wallet change| between inventory snapshots since RelDyn started watching; save reloads re-baseline']
            : self::fact(null, null, 'no inventory snapshot recorded yet');
        // Lifetime earned + spent is at least the opening wallet (earned before RelDyn watched)
        // plus what moved since. A lower bound of 0 says nothing: unknown, not "no standing".
        $footprint = $facts['gold_moved']['value'] === null ? null
            : intval($facts['gold_moved']['opening'] ?? 0) + intval($facts['gold_moved']['value']);
        $facts['gold_footprint'] = self::fact(($footprint ?? 0) > 0 ? $footprint : null, 'core_player.' . self::LEDGER_KEY,
            'economic footprint lower bound: opening wallet + gold moved; 0 = not known yet');

        // latest infoplayer / playerinfo line: level, race, gender
        $info = self::latestPlayerInfo($db);

        // level: core_player.stats, else infoplayer, else 1 + "Level Increases"
        $stats = self::decodeRow($player, 'stats');
        $trackedStats = self::trackedStats($db, self::statNames($cfg));
        if (is_array($stats) && isset($stats['level']) && is_numeric($stats['level'])) {
            $facts['level'] = self::fact(intval($stats['level']), 'core_player.stats');
        } elseif (isset($info['level'])) {
            $facts['level'] = self::fact(intval($info['level']), 'eventlog ' . $info['type'] . ' (latest)');
        } elseif (isset($trackedStats['level increases'])) {
            $facts['level'] = self::fact(1 + $trackedStats['level increases']['value'], '1 + tracked stat Level Increases (' . $trackedStats['level increases']['source'] . ')');
        } else {
            $facts['level'] = self::fact(null, null);
        }
        $transformation = self::decodeRow($player, 'transformation_state');
        $facts['beast_form'] = self::fact(is_array($transformation) && array_key_exists('is_werewolf_form', $transformation)
            ? (bool) $transformation['is_werewolf_form'] : null, 'core_player.transformation_state');
        if (is_array($transformation) && !empty($transformation['race_name'])) {
            $facts['race'] = self::fact((string) $transformation['race_name'], 'core_player.transformation_state');
        } else {
            $facts['race'] = self::fact($info['race'] ?? null, 'eventlog ' . ($info['type'] ?? 'infoplayer') . ' (latest)');
        }
        $coreGender = isset($player['gender']) ? trim((string) $player['gender']) : '';
        if ($coreGender !== '') {
            $facts['gender'] = self::fact($coreGender, 'core_player.gender (Player Management)');
        } else {
            $gender = self::latestPlayerGender($db);
            $facts['gender'] = self::fact($gender, 'eventlog infoplayer (latest line with a gender)');
        }
        $appearance = isset($player['appearance']) ? trim((string) $player['appearance']) : '';
        $facts['appearance'] = self::fact($appearance !== '' ? $appearance : null, 'core_player.appearance',
            'beauty input: scored per NPC against their keywords (MDD 2.1)');

        // tracked stats
        foreach (self::statNames($cfg) as $lower => $name) {
            $facts['stat:' . $name] = isset($trackedStats[$lower])
                ? self::fact($trackedStats[$lower]['value'], $trackedStats[$lower]['source'])
                : self::fact(null, null);
        }

        // eventlog: the player's kills
        $kills = self::playerKills($db);
        $killNote = 'death lines since the eventlog began (reloads prune the abandoned timeline)';
        $facts['eventlog_player_kills'] = self::fact($kills['kills'] ?? null, 'eventlog death "<player> has defeated"', $killNote);
        $facts['eventlog_powerful_kills'] = self::fact($kills['powerful'] ?? null, 'eventlog death "(powerful enemy)"', $killNote);
        $facts['eventlog_dragon_kills'] = self::fact($kills['dragons'] ?? null, 'eventlog death "(powerful DRAGON)"', $killNote);

        // journal: questlines the player has taken up
        $facts['questlines'] = self::questlines($db, (array) $cfg['questlines']);

        // No CHIM 3.4.1 source at all.
        $facts['thane_holds'] = self::fact(null, null, 'no core source in CHIM 3.4.1 (needs a game-side bridge)');
        $facts['faction_ranks'] = self::fact(null, null,
            'no core source in CHIM 3.4.1: factions.player_rank is the vendor faction\'s reaction, not membership');

        return $facts;
    }

    /** core_player rows by id (text values). A failed read is logged and reads as absent. */
    private static function coreRows($db, array $ids): array
    {
        if (!$db) return [];
        $list = implode(', ', array_map(fn($id) => $db->escapeLiteral($id), $ids));
        try {
            $rows = $db->fetchAll("SELECT id, value FROM core_player WHERE id IN ({$list}) LIMIT " . count($ids));
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer core_player', $e);
            return [];
        }
        $out = [];
        foreach ((array) $rows as $r) $out[$r['id']] = $r['value'];
        return $out;
    }

    private static function decodeRow(array $rows, string $id): ?array
    {
        if (!isset($rows[$id]) || $rows[$id] === '') return null;
        $v = json_decode((string) $rows[$id], true);
        if (!is_array($v)) {
            error_log("[RelDyn] ERROR RelDynPlayer: core_player.{$id} is not JSON; treating it as unknown");
            return null;
        }
        return $v;
    }

    private static function goldIn(array $inventory): int
    {
        $gold = 0;
        foreach ($inventory as $item) {
            if (is_array($item) && strtoupper((string) ($item['baseid'] ?? '')) === self::GOLD_BASEID) {
                $gold += intval($item['count'] ?? 0);
            }
        }
        return $gold;
    }

    /**
     * Tracked stats by lower-case name => ['value' => int, 'source' => string]. core_player
     * (playthrough-scoped, gamedata 'skyrim_stats') wins over conf_opts (setconf, global).
     */
    private static function trackedStats($db, array $namesByLower): array
    {
        if (!$db || !$namesByLower) return [];
        $list = implode(', ', array_map(fn($n) => $db->escapeLiteral($n), array_keys($namesByLower)));
        $limit = count($namesByLower) * 4;
        $out = [];
        foreach ([
            'conf_opts'   => 'conf_opts (tracked stat via setconf; gameplay row of this playthrough)',
            'core_player' => 'core_player (tracked stat via gamedata skyrim_stats)',
        ] as $table => $source) {
            try {
                $rows = $db->fetchAll("SELECT id, value FROM {$table} WHERE lower(id) IN ({$list}) LIMIT {$limit}");
            } catch (\Throwable $e) {
                RelationshipDynamics::logError("RelDynPlayer tracked stats {$table}", $e);
                continue;
            }
            foreach ((array) $rows as $r) {
                if (!is_numeric($r['value'] ?? null)) continue;
                $out[strtolower($r['id'])] = ['value' => intval($r['value']), 'source' => $source];   // core_player read last: wins
            }
        }
        return $out;
    }

    /** The newest infoplayer / playerinfo line: level:N,name:"..",race:"..",gender:".." */
    private static function latestPlayerInfo($db): array
    {
        if (!$db) return [];
        try {
            $rows = $db->fetchAll("SELECT type, data FROM eventlog WHERE type IN ('infoplayer', 'playerinfo') "
                . "ORDER BY gamets DESC, rowid DESC LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer infoplayer', $e);
            return [];
        }
        if (!$rows) return [];
        $data = (string) $rows[0]['data'];
        $out = ['type' => (string) $rows[0]['type']];
        if (preg_match('/level:(\d+)/', $data, $m)) $out['level'] = intval($m[1]);
        if (preg_match('/race:"([^"]*)"/', $data, $m) && $m[1] !== '') $out['race'] = $m[1];
        if (preg_match('/gender:"([^"]*)"/', $data, $m) && $m[1] !== '') $out['gender'] = $m[1];
        return $out;
    }

    /**
     * The player's gender from the newest infoplayer / playerinfo line that carries one. Only
     * the plugin's 'infoplayer' line has gender:"..."; the 'playerinfo' line every game load
     * sends has none, so the newest line overall would lose it after any load.
     */
    private static function latestPlayerGender($db): ?string
    {
        if (!$db) return null;
        try {
            $rows = $db->fetchAll("SELECT data FROM eventlog WHERE type IN ('infoplayer', 'playerinfo') "
                . "AND data LIKE '%gender:\"_%' ORDER BY gamets DESC, rowid DESC LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer infoplayer gender', $e);
            return null;
        }
        if (!$rows || !preg_match('/gender:"([^"]+)"/', (string) $rows[0]['data'], $m)) return null;
        return $m[1];
    }

    /** The player's kills from core death lines; [] (unknown) when there is no player name or no death line at all. */
    private static function playerKills($db): array
    {
        $name = trim((string) ($GLOBALS['PLAYER_NAME'] ?? ''));
        if (!$db || $name === '') return [];
        $like = RelationshipDynamics::escapeLike($name) . ' has defeated %';
        $atStart = $db->escapeLiteral($like);
        $afterContext = $db->escapeLiteral('%)' . $like);
        $mine = "(data LIKE {$atStart} ESCAPE '\\' OR data LIKE {$afterContext} ESCAPE '\\')";
        try {
            $rows = $db->fetchAll("SELECT count(*) AS deaths, "
                . "count(*) FILTER (WHERE {$mine}) AS kills, "
                . "count(*) FILTER (WHERE {$mine} AND data LIKE '%(powerful enemy)%') AS powerful, "
                . "count(*) FILTER (WHERE {$mine} AND data LIKE '%(powerful DRAGON)%') AS dragons "
                . "FROM eventlog WHERE type = 'death' LIMIT 1");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer eventlog kills', $e);
            return [];
        }
        $r = $rows[0] ?? null;
        if (!$r || intval($r['deaths']) === 0) return [];   // no death line yet: kill tracking has nothing to say
        return ['kills' => intval($r['kills']), 'powerful' => intval($r['powerful']), 'dragons' => intval($r['dragons'])];
    }

    /**
     * The player's magic (decisions §10): each staff held in either hand (core_player.equipment;
     * the plugin fills the hands from the inventory, so the only magic a hand can report is a
     * staff, keyword WeapTypeStaff) and each spell the player cast (eventlog 'npcspellcast', written by the plugin's
     * TESSpellCastEvent for the player as "<player> casts <spell> [on <target>]" while core's
     * DETECT_MAGIC_EVENT is on), read by RelDynFacetClassifier::spellReading. A name the reading
     * does not know is listed under 'unread' and counts for nothing.
     *
     * value: spell name => [held, casts, uses, school, subjects, archetypes]; null when there is
     * no magic read (no staff that reads by its subject, no cast line). 'facets': the use-weighted
     * mean facet mix of the player's magic.
     */
    private static function spellFacts($db, ?array $equipment, array $cfg): array
    {
        $uses = [];   // name => [held, casts]
        foreach (['left_hand', 'right_hand'] as $slot) {
            $name = trim((string) ($equipment[$slot] ?? ''));
            if ($name === '' || !self::holdsStaff((array) ($equipment[$slot . '_keywords'] ?? []))) continue;
            $uses[$name] = [true, $uses[$name][1] ?? 0];
        }
        foreach (self::playerCasts($db, intval($cfg['spell_cast_scan_limit'])) as $name => $n) {
            $uses[$name] = [$uses[$name][0] ?? false, $n];
        }
        $held = max(0.0, floatval($cfg['staff_held_uses']));
        $value = [];
        $unread = [];
        $mix = [];
        $total = 0.0;
        foreach ($uses as $name => [$isHeld, $casts]) {
            $r = RelDynFacetClassifier::spellReading((string) $name, '', null, true);
            if ($r === null) {
                $unread[] = (string) $name;
                continue;
            }
            $u = ($isHeld ? $held : 0.0) + $casts;
            $value[(string) $name] = ['held' => $isHeld, 'casts' => $casts, 'uses' => $u, 'school' => $r['school'],
                'subjects' => $r['subjects'], 'archetypes' => $r['archetypes']];
            foreach ($r['facets'] as $facet => $w) $mix[$facet] = ($mix[$facet] ?? 0.0) + $u * $w;
            $total += $u;
        }
        $facets = [];
        foreach ($mix as $facet => $sum) $facets[$facet] = round($total > 0 ? $sum / $total : 0.0, 4);
        arsort($facets);
        $known = $value !== [] && $total > 0;
        $f = self::fact($known ? $value : null, 'core_player.equipment (hands) + eventlog npcspellcast (player casts)',
            'magic reads by its subject (decisions §10); unread names count for nothing');
        $f['facets'] = $known ? $facets : null;
        $f['unread'] = $unread;
        return $f;
    }

    /** A hand holds a staff (keyword WeapTypeStaff): the only magic an inventory item in hand is. */
    private static function holdsStaff(array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (strtolower((string) $kw) === 'weaptypestaff') return true;
        }
        return false;
    }

    /** Spell name => times the player cast it (newest $limit cast lines); [] without a player name. */
    private static function playerCasts($db, int $limit): array
    {
        $name = trim((string) ($GLOBALS['PLAYER_NAME'] ?? ''));
        if (!$db || $name === '' || $limit <= 0) return [];
        $prefix = $name . ' casts ';
        $like = $db->escapeLiteral(RelationshipDynamics::escapeLike($prefix) . '%');
        try {
            $rows = $db->fetchAll("SELECT data FROM eventlog WHERE type = 'npcspellcast' AND data LIKE {$like} ESCAPE '\\' "
                . "ORDER BY rowid DESC LIMIT {$limit}");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer eventlog spell casts', $e);
            return [];
        }
        $out = [];
        foreach ((array) $rows as $r) {
            $rest = substr((string) $r['data'], strlen($prefix));
            $at = strpos($rest, ' on ');
            $spell = trim($at === false ? $rest : substr($rest, 0, $at));
            if ($spell !== '') $out[$spell] = ($out[$spell] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Distinct journal quests per questline (quests.id_quest = editor id). Unknown until the
     * journal has a row. Only ACTIVE quests are in the journal table (the plugin skips completed
     * ones), so finished questlines are counted by the "<Guild> Quests Completed" tracked stats.
     */
    private static function questlines($db, array $lines): array
    {
        $unknown = ['value' => null, 'source' => null, 'journal_quests' => null];
        if (!$db) return $unknown;
        try {
            $rows = $db->fetchAll("SELECT DISTINCT id_quest FROM quests WHERE id_quest IS NOT NULL AND id_quest <> '' LIMIT 5000");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer quests journal', $e);
            return $unknown;
        }
        if (!$rows) return $unknown;
        $counts = array_fill_keys(array_keys($lines), 0);
        foreach ($rows as $r) {
            $id = strtoupper((string) $r['id_quest']);
            foreach ($lines as $line => $prefixes) {
                foreach ((array) $prefixes as $prefix) {
                    if ($prefix !== '' && strpos($id, strtoupper((string) $prefix)) === 0) {
                        $counts[$line]++;
                        continue 3;
                    }
                }
            }
        }
        return ['value' => $counts, 'source' => 'quests (active journal quests, distinct editor ids)', 'journal_quests' => count($rows)];
    }

    // =====================================================================
    // DERIVATION
    // =====================================================================

    /** Evidence key => count (null = unknown). */
    private static function evidence(array $facts): array
    {
        $ev = [];
        foreach ($facts as $k => $f) {
            if (strpos($k, 'stat:') === 0) $ev[strtolower($k)] = $f['value'];
        }
        // Evidence keys are matched lower-case (config tables may spell them either way).
        $killStats = array_filter(array_map(fn($s) => $facts['stat:' . $s]['value'] ?? null, self::KILL_STATS), fn($v) => $v !== null);
        $ev['kills:total'] = $killStats ? array_sum($killStats) : $facts['eventlog_player_kills']['value'];
        $ev['dragons'] = $facts['stat:Dragon Souls Collected']['value'] ?? $facts['eventlog_dragon_kills']['value'];
        $ev['eventlog:powerful_kills'] = $facts['eventlog_powerful_kills']['value'];
        $ev['ledger:moved'] = $facts['gold_footprint']['value'];
        $ev['journal:quests'] = $facts['questlines']['journal_quests'];
        $ev['form:beast'] = $facts['beast_form']['value'] === null ? null : ($facts['beast_form']['value'] ? 1 : 0);
        foreach ((array) ($facts['questlines']['value'] ?? []) as $line => $n) $ev['questline:' . strtolower((string) $line)] = $n;
        return $ev;
    }

    /**
     * NPC-subjective markers (attraction lane): the soft-or of an evidence table (evidence
     * key => [half, weight], the config tables' format) over a profile's evidence. null when
     * none of it is known.
     */
    public static function evidenceScore(array $profile, array $table): ?float
    {
        return self::evidenceOr((array) ($profile['evidence'] ?? []), $table);
    }

    /** Soft-or of the known evidence: 1 - prod(1 - w * n/(n+half)). null when none of it is known. */
    private static function evidenceOr(array $ev, array $table): ?float
    {
        $none = 1.0;
        $known = false;
        foreach ($table as $key => $spec) {
            $n = $ev[strtolower((string) $key)] ?? null;
            if ($n === null) continue;
            $known = true;
            [$half, $w] = [max(1e-9, floatval($spec[0] ?? 1)), floatval($spec[1] ?? 1)];
            $n = max(0.0, floatval($n));
            $none *= 1.0 - max(0.0, min(1.0, $w)) * ($n / ($n + $half));
        }
        return $known ? 1.0 - $none : null;
    }

    private static function progress(float $skill, array $cfg): float
    {
        $floor = floatval($cfg['skill_floor']);
        $span = max(1e-9, floatval($cfg['skill_cap']) - $floor);
        return max(0.0, min(1.0, ($skill - $floor) / $span));
    }

    /** Mean of the best $top weighted skill progresses. null when skills are unknown. */
    private static function skillComponent(array $facts, array $weights, int $top, array $cfg): ?float
    {
        $skills = $facts['skills']['value'];
        if ($skills === null || !$weights) return null;
        $vals = [];
        foreach ($weights as $skill => $w) {
            $vals[] = isset($skills[$skill]) ? floatval($w) * self::progress($skills[$skill], $cfg) : 0.0;
        }
        rsort($vals);
        $vals = array_slice($vals, 0, max(1, $top));
        return array_sum($vals) / count($vals);
    }

    /** Soft-or of the worn keywords' weights. null when equipment is unknown. */
    private static function gearComponent(array $facts, array $table): ?float
    {
        $keywords = $facts['equipment_keywords']['value'];
        if ($keywords === null || !$table) return null;
        $worn = array_flip(array_map('strtolower', $keywords));
        $none = 1.0;
        foreach ($table as $kw => $w) {
            if (isset($worn[strtolower((string) $kw)])) $none *= 1.0 - max(0.0, min(1.0, floatval($w)));
        }
        return 1.0 - $none;
    }

    /**
     * The player's magic as archetype $archetype (0..1): share x volume over the spells read
     * (see configDefaults). null when no spell of the player's is read at all.
     */
    private static function spellComponent(array $facts, string $archetype, array $cfg): ?float
    {
        $spells = $facts['spells']['value'] ?? null;
        if (!is_array($spells) || $spells === []) return null;
        $total = 0.0;
        $mine = 0.0;
        foreach ($spells as $spell) {
            $uses = max(0.0, floatval($spell['uses'] ?? 0));
            $total += $uses;
            $mine += $uses * max(0.0, min(1.0, floatval($spell['archetypes'][$archetype] ?? 0.0)));
        }
        if ($total <= 0.0) return null;
        $half = max(1e-9, floatval($cfg['spell_uses_half']));
        return ($mine / $total) * ($total / ($total + $half));
    }

    /**
     * An archetype's anchor (configDefaults): strength = max(anchor components, soft-or of the
     * anchor evidence), unknown counting 0; factor = min(1, strength / full).
     *
     * @return array ['strength' => 0..1, 'factor' => 0..1]
     */
    private static function anchorFactor(array $anchor, array $parts, array $ev): array
    {
        $strength = 0.0;
        foreach ((array) ($anchor['components'] ?? []) as $c) {
            $strength = max($strength, floatval($parts[$c] ?? 0.0));
        }
        $strength = max($strength, floatval(self::evidenceOr($ev, (array) ($anchor['evidence'] ?? [])) ?? 0.0));
        $full = floatval($anchor['full'] ?? 1.0);
        $factor = $full > 0 ? min(1.0, $strength / $full) : 1.0;
        return ['strength' => round($strength, 4), 'factor' => round($factor, 4)];
    }

    /** Weighted mean over the components that are known (non-null); null when none is. */
    private static function weightedKnownMean(array $parts, array $weights): ?float
    {
        $sum = 0.0;
        $wsum = 0.0;
        foreach ($parts as $k => $v) {
            if ($v === null) continue;
            $w = max(0.0, floatval($weights[$k] ?? 0));
            $sum += $w * $v;
            $wsum += $w;
        }
        return $wsum > 0 ? max(0.0, min(1.0, $sum / $wsum)) : null;
    }

    private static function pillarComponent(string $component, array $facts, array $ev, array $cfg): ?float
    {
        switch ($component) {
            case 'level':
                $level = $facts['level']['value'];
                return $level === null ? null : max(0.0, min(1.0, ($level - 1) / max(1, intval($cfg['level_cap']) - 1)));
            case 'combat_skills':
                $w = array_fill_keys((array) $cfg['combat_skills'], 1.0);
                return self::skillComponent($facts, $w, intval($cfg['combat_skills_top']), $cfg);
            case 'mastery':
                return self::skillComponent($facts, array_fill_keys(self::SKILLS, 1.0), intval($cfg['mastery_top']), $cfg);
            default:
                return self::evidenceOr($ev, (array) ($cfg['pillar_components'][$component] ?? []));
        }
    }

    // =====================================================================
    // GOLD LEDGER (economic footprint)
    // =====================================================================

    /**
     * Fold the current wallet (core_player.inventory gold) into the gold ledger: gold moved +=
     * |change| since the last snapshot (gained / spent kept apart). The game clock going
     * backwards is a save reload: re-baseline without counting. An unchanged wallet writes
     * nothing. One atomic upsert, so concurrent requests cannot double count a change.
     * Each write appends a checkpoint [gamets, gold, moved, gained, spent] (the newest
     * save_load.gold_ledger_checkpoints kept; checkpoints later than this snapshot's game time
     * are from a discarded timeline and dropped), so a save load can rewind the ledger
     * (rewindGoldLedger). Called once per request from prerequest.php.
     */
    public static function recordGoldSnapshot(): void
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return;
        $gamets = RelationshipDynamics::currentGamets();
        if ($gamets <= 0) {
            RelationshipDynamics::log('[RelDyn-PLAYER] gold ledger: no game clock, snapshot skipped');
            return;
        }
        $rows = self::coreRows($db, ['inventory', self::LEDGER_KEY]);
        $inventory = self::decodeRow($rows, 'inventory');
        if (!is_array($inventory)) return;   // no snapshot from the game yet
        $gold = self::goldIn($inventory);
        $t = (int) $gamets;
        $ledger = self::decodeRow($rows, self::LEDGER_KEY);
        if (is_array($ledger) && intval($ledger['last_gold'] ?? -1) === $gold && $t >= intval($ledger['last_gamets'] ?? 0)) {
            return;   // same wallet, clock moving forward: nothing to record
        }

        $old = "core_player.value::jsonb";
        $delta = "({$gold} - ({$old}->>'last_gold')::numeric)";
        $rewound = "({$t} < ({$old}->>'last_gamets')::numeric)";
        $moved = "({$old}->>'moved')::numeric + CASE WHEN {$rewound} THEN 0 ELSE abs({$delta}) END";
        $gained = "({$old}->>'gained')::numeric + CASE WHEN {$rewound} THEN 0 ELSE greatest({$delta}, 0) END";
        $spent = "({$old}->>'spent')::numeric + CASE WHEN {$rewound} THEN 0 ELSE greatest(-{$delta}, 0) END";
        $keep = max(1, intval(RelDynTimeline::config()['gold_ledger_checkpoints']));   // count
        $checkpoints = "(SELECT COALESCE(jsonb_agg(c.e ORDER BY c.i), '[]'::jsonb) FROM ("
            . "SELECT a.e, a.i FROM jsonb_array_elements("
            . "(CASE WHEN jsonb_typeof({$old}->'checkpoints') = 'array' THEN {$old}->'checkpoints' ELSE '[]'::jsonb END) "
            . "|| jsonb_build_array(jsonb_build_array({$t}, {$gold}, {$moved}, {$gained}, {$spent}))) WITH ORDINALITY AS a(e, i) "
            . "WHERE (a.e->>0)::numeric <= {$t} ORDER BY a.i DESC LIMIT {$keep}) c)";
        // 'opening': the wallet RelDyn first saw, gold earned before it watched (footprint lower bound)
        $init = $db->escapeLiteral(json_encode(['last_gold' => $gold, 'last_gamets' => $t, 'moved' => 0, 'gained' => 0,
            'spent' => 0, 'snapshots' => 1, 'rebaselines' => 0, 'since_gamets' => $t, 'opening' => $gold,
            'checkpoints' => [[$t, $gold, 0, 0, 0]]]));
        $key = $db->escapeLiteral(self::LEDGER_KEY);
        try {
            $db->fetchAll("INSERT INTO core_player (id, value) VALUES ({$key}, {$init}) "
                . "ON CONFLICT (id) DO UPDATE SET value = jsonb_build_object("
                . "'last_gold', {$gold}, 'last_gamets', {$t}, "
                . "'moved', {$moved}, 'gained', {$gained}, 'spent', {$spent}, "
                . "'snapshots', ({$old}->>'snapshots')::int + 1, "
                . "'rebaselines', ({$old}->>'rebaselines')::int + CASE WHEN {$rewound} THEN 1 ELSE 0 END, "
                . "'since_gamets', {$old}->'since_gamets', 'opening', {$old}->'opening', "
                . "'checkpoints', {$checkpoints})::text "
                . "WHERE ({$old}->>'last_gold')::numeric IS DISTINCT FROM {$gold} OR {$rewound} "
                . "RETURNING value");
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynPlayer gold ledger', $e);
        }
    }

    /**
     * Save load (save-load-rollback): the ledger follows the game back to $loadGamets (raw
     * gamets). Totals and the wallet return to the newest checkpoint at or before it, later
     * checkpoints go. A ledger opened after that point is deleted (the next snapshot opens it
     * again from the loaded wallet). Without a usable checkpoint (a ledger older than the
     * checkpoints, or all of them trimmed away) the totals stay and the next snapshot
     * re-baselines as a reload (logged). One compare-and-set write.
     *
     * @return string 'none' | 'current' | 'rewound' | 'reopened' | 'kept' | 'conflict'
     */
    public static function rewindGoldLedger(float $loadGamets): string
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) return 'none';
        $row = $db->fetchOne('SELECT value FROM core_player WHERE id = $1', [self::LEDGER_KEY]);
        $raw = $row['value'] ?? null;
        $ledger = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($ledger)) return 'none';
        if (floatval($ledger['last_gamets'] ?? 0) <= $loadGamets) return 'current';

        $checkpoints = array_values(array_filter((array) ($ledger['checkpoints'] ?? []),
            static fn($c) => is_array($c) && count($c) >= 5 && is_numeric($c[0])));
        $at = null;
        foreach ($checkpoints as $c) {
            if (floatval($c[0]) <= $loadGamets) $at = $c;
        }
        if ($at === null && is_numeric($ledger['since_gamets'] ?? null) && floatval($ledger['since_gamets']) > $loadGamets) {
            $gone = $db->fetchOne('DELETE FROM core_player WHERE id = $1 AND value = $2 RETURNING id', [self::LEDGER_KEY, $raw]);
            return isset($gone['id']) ? 'reopened' : 'conflict';
        }
        if ($at === null) {
            error_log('[RelDyn-PLAYER] gold ledger has no checkpoint at or before gamets ' . $loadGamets . '; totals kept, the next snapshot re-baselines');
            return 'kept';
        }
        $ledger['last_gamets'] = intval($at[0]);
        $ledger['last_gold'] = intval($at[1]);
        $ledger['moved'] = $at[2];
        $ledger['gained'] = $at[3];
        $ledger['spent'] = $at[4];
        $ledger['rebaselines'] = intval($ledger['rebaselines'] ?? 0) + 1;
        $ledger['checkpoints'] = array_values(array_filter($checkpoints, static fn($c) => floatval($c[0]) <= $loadGamets));
        $won = $db->fetchOne('UPDATE core_player SET value = $2 WHERE id = $1 AND value = $3 RETURNING id',
            [self::LEDGER_KEY, json_encode($ledger), $raw]);
        return isset($won['id']) ? 'rewound' : 'conflict';
    }
}
