<?php
/**
 * Relationship Dynamics — player profile (shared contract, 2026-09-24 batch).
 *
 * MINIMAL STAND-IN written by the attraction lane: the stats lane owns this file and its
 * real RelDynPlayer::profile() (skills, deeds, economic footprint, appearance). The
 * integrator keeps the stats lane's version; only the signature and the shape below are
 * the contract the Attraction Matrix codes against:
 *
 *   RelDynPlayer::profile(): array = [
 *     'archetypes' => ['warrior','mage','druid','thief','bard','scholar','smith','hunter','healer','noble' => 0..1],
 *     'pillars'    => ['strength' => 0..1, 'status' => 0..1, 'competence' => 0..1, 'beauty' => 0..1|null],
 *     'facts'      => [...raw inputs with source...],
 *     'known'      => bool,
 *   ]
 *
 * This stand-in reads only CHIM 3.4.1 core_player rows the plugin already writes:
 * 'skills' ({skill: level 0..100}) and 'stats' ({level, ...}). It has no status source
 * (status 0) and no appearance (beauty null).
 */

class RelDynPlayer
{
    const ARCHETYPES = ['warrior', 'mage', 'druid', 'thief', 'bard', 'scholar', 'smith', 'hunter', 'healer', 'noble'];

    /** Stand-in only: archetype => the Skyrim skills that show it (core_player.skills keys). */
    const STANDIN_ARCHETYPE_SKILLS = [
        'warrior' => ['onehanded', 'twohanded', 'block', 'heavyarmor'],
        'mage'    => ['destruction', 'conjuration', 'alteration', 'illusion'],
        'druid'   => ['alchemy', 'restoration', 'alteration'],
        'thief'   => ['sneak', 'lockpicking', 'pickpocket'],
        'bard'    => ['speech', 'illusion'],
        'scholar' => ['enchanting', 'alteration'],
        'smith'   => ['smithing'],
        'hunter'  => ['archery', 'sneak', 'lightarmor'],
        'healer'  => ['restoration', 'alchemy'],
        'noble'   => ['speech'],
    ];

    /** Stand-in only: the skills that make generic strength ("CAN you do things", MDD 2.2). */
    const STANDIN_STRENGTH_SKILLS = ['onehanded', 'twohanded', 'archery', 'block', 'heavyarmor', 'lightarmor', 'destruction'];

    /** Skyrim skill level a new character starts at (level 0..100 scale). */
    const STANDIN_SKILL_START = 15;

    public static function profile(): array
    {
        $empty = [
            'archetypes' => array_fill_keys(self::ARCHETYPES, 0.0),
            'pillars'    => ['strength' => 0.0, 'status' => 0.0, 'competence' => 0.0, 'beauty' => null],
            'facts'      => [],
            'known'      => false,
        ];
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return $empty;
        }
        try {
            $skillsRow = $db->fetchOne("SELECT value FROM core_player WHERE id = 'skills' LIMIT 1");
            $statsRow = $db->fetchOne("SELECT value FROM core_player WHERE id = 'stats' LIMIT 1");
        } catch (Throwable $e) {
            error_log('[RelDyn] RelDynPlayer::profile: core_player read failed: ' . $e->getMessage());
            return $empty;
        }
        $skills = is_array($skillsRow) ? json_decode((string) ($skillsRow['value'] ?? ''), true) : null;
        if (!is_array($skills) || $skills === []) {
            return $empty;
        }
        $skills = array_change_key_case(array_map('floatval', array_filter($skills, 'is_numeric')), CASE_LOWER);
        $stats = is_array($statsRow) ? json_decode((string) ($statsRow['value'] ?? ''), true) : null;
        $level = is_array($stats) && is_numeric($stats['level'] ?? null) ? floatval($stats['level']) : 1.0;

        $norm = fn(float $lvl) => max(0.0, min(1.0, ($lvl - self::STANDIN_SKILL_START) / (100.0 - self::STANDIN_SKILL_START)));
        $archetypes = [];
        foreach (self::STANDIN_ARCHETYPE_SKILLS as $arch => $names) {
            $levels = array_map(fn($n) => $norm($skills[$n] ?? 0.0), $names);
            rsort($levels);
            $top = array_slice($levels, 0, 2);
            $archetypes[$arch] = round(array_sum($top) / count($top), 4);
        }
        $combat = array_map(fn($n) => $norm($skills[$n] ?? 0.0), self::STANDIN_STRENGTH_SKILLS);
        rsort($combat);
        $top3 = array_slice($combat, 0, 3);

        return [
            'archetypes' => $archetypes,
            'pillars' => [
                'strength'   => round(array_sum($top3) / max(1, count($top3)), 4),
                'status'     => 0.0,
                'competence' => round(max(0.0, min(1.0, ($level - 1.0) / 49.0)), 4),
                'beauty'     => null,
            ],
            'facts' => [
                'skills' => ['value' => $skills, 'source' => 'core_player.skills'],
                'level'  => ['value' => $level, 'source' => 'core_player.stats'],
            ],
            'known' => true,
        ];
    }
}
