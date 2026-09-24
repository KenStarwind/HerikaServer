<?php
/**
 * RelDyn facet classifier (decisions 2026-09-23 §6, "Ken's April vector idea"): gives every
 * Oghma entry, item, creature and activity a facet vector (facet => weight 0..1 over
 * RelDynFacets::FACETS), so the appraisal lane can turn it into a feeling for one NPC.
 *
 * Two sources of evidence, both from CHIM core data (no MinAI):
 *
 *  1. The deterministic PRIOR: an Oghma entry's knowledge_class tokens, its category and its
 *     topic / aliases / tags words through editable mapping tables (config facet_classifier:
 *     knowledge_class_prior, category_prior, tag_keywords). Always available.
 *  2. The EMBEDDING: every facet has a short anchor description (config facet_classifier.anchors,
 *     e.g. "scholarly: books, research, ancient lore, study"). The anchors are embedded once,
 *     every Oghma entry's text is embedded once (or its oghma.vector384 is reused when core
 *     filled it), and the entry gets a facet mix by cosine similarity against the anchors:
 *
 *        s_f  = cos(entry, anchor_f)
 *        z_f  = (s_f - mean_f) / std_f        calibrated: mean/std of anchor f's cosine over the
 *                                             whole Oghma corpus (MiniLM cosines are small and
 *                                             some anchors -- dark, alchemy -- sit close to
 *                                             everything; measured on the 3.4.1 corpus, raw
 *                                             cosines made the Dwemer race 'dark' and 'wild')
 *        w_f  = exp((z_f - max_g z_g) / z_temperature)   softmax relative to the best facet:
 *                                             the best facet is 1.0, a facet one z_temperature
 *                                             below it is e^-1 = 0.37
 *        keep w_f >= min_weight; nothing at all when max_g z_g < min_top_z (no facet stands out)
 *
 *     With fewer than calibration_min_entries entries there is no corpus to calibrate on: z_f =
 *     s_f with temperature / min_similarity (cosine units) instead.
 *
 *     Service: FEATURES.MEMORY_EMBEDDING.TXTAI_URL (general_settings; http://127.0.0.1:8082 on
 *     DwemerDistro) + '/embed', POST {"text": ...} -> {"embedding": [384 floats]}, the MiniMe
 *     service's sentence-transformers/all-MiniLM-L6-v2 (the same endpoint core's memory
 *     embeddings use). The April interest-vector call to a route MiniMe does not have (404) is gone.
 *
 *  The two combine by noisy-OR, each source independent evidence that the facet is present,
 *  the embedding discounted by embedding.weight:
 *
 *        facet_f = 1 - (1 - prior_f) x (1 - weight x embed_f)
 *
 *  so either source alone keeps its own strength (the embedding at most `weight`) and agreement
 *  raises it. Weights stay absolute (not rescaled to a max of 1): weak evidence reads weak.
 *
 * Results are precomputed into RelDyn's own table reldyn_oghma_facets (tools/build_oghma_facets.php,
 * idempotent, versioned by the config tables + method so a change recomputes). The game-time path
 * (thingFacets) never calls the embedding service: it reads the table, and when the table or the
 * row is missing it computes the prior live from core's oghma row, then falls back to the keyword
 * tables for items, creatures and activities.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynFacetClassifier
{
    const TABLE = 'reldyn_oghma_facets';
    const ANCHOR_TABLE = 'reldyn_facet_anchors';
    const KINDS = ['item', 'topic', 'creature', 'place', 'activity'];
    /** Bumped when the math (not the tables) changes, so every row is recomputed. */
    const ALGORITHM = 1;

    /** @var callable|null fn(string $text): ?array  -- the embedding HTTP call (tests stub it) */
    private static $embedder = null;

    // =========================================================================
    // Config
    // =========================================================================

    /**
     * Default config 'facet_classifier'. Every table is facet => weight 0..1 per key; the
     * keyword tables match whole words (a trailing s/es plural also matches) and a keyword
     * inside a longer matched keyword yields to it ('amulet' to 'amulet of mara').
     */
    public static function configDefaults(): array
    {
        return [
            'anchors' => [
                'combat'     => 'combat: fighting, weapons, battle, warriors, sparring, slaying enemies',
                'crafting'   => 'crafting: smithing at the forge and anvil, ore, tools, making and repairing gear',
                'alchemy'    => 'alchemy: potions, poisons, ingredients, herbs, brewing, apothecary',
                'enchanting' => 'enchanting: magic, soul gems, arcane enchantments, spells, staves',
                'scholarly'  => 'scholarly: books, research, ancient lore, study, history, libraries',
                'nature'     => 'nature: forests, wilderness, animals, hunting, rivers, mountains, plants',
                'social'     => 'social: taverns, conversation, friends, music, feasts, gossip, company',
                'domestic'   => 'domestic: home, cooking, food, family, farming, hearth, chores',
                'adventure'  => 'adventure: exploring ruins, dungeons and caves, travel, treasure, quests',
                'spiritual'  => 'spiritual: gods, the Divines, temples, prayer, faith, shrines, souls',
                'wealth'     => 'wealth: gold, jewels, riches, trade, valuable goods, money',
                'danger'     => 'danger: deadly threats, monsters, traps, dragons, death, peril',
                'crowd'      => 'crowd: busy crowded city streets, markets, many people, noise',
                'wild'       => 'wild: untamed wilderness far from any town, beasts, open land',
                'confined'   => 'confined: cramped underground tunnels, enclosed rooms, no open sky',
                'dark'       => 'dark: darkness, shadows, night, undead, necromancy, vampires',
                'sacred'     => 'sacred: holy ground, altars, blessed relics, reverence',
                'luxury'     => 'luxury: palaces, fine clothes, nobles, opulence, comfort',
                'quiet'      => 'quiet: silence, calm, solitude, peaceful reading, meditation',
            ],
            'embedding' => [
                'model'          => 'sentence-transformers/all-MiniLM-L6-v2',  // what the MiniMe /embed serves
                'dimensions'     => 384,
                // calibrated (z-scores of each anchor's cosine over the corpus)
                'z_temperature'  => 0.5,    // z units: a facet this far below the best keeps e^-1
                'min_top_z'      => 1.0,    // best z below this: no facet stands out, the entry says nothing
                'calibration_min_entries' => 30,  // fewer Oghma entries: uncalibrated cosines instead
                // uncalibrated (raw cosines)
                'temperature'    => 0.05,   // cosine units: a facet this far below the best keeps e^-1
                'min_similarity' => 0.15,   // best cosine below this: the entry says nothing to the anchors
                'min_weight'     => 0.25,   // relative softmax weight below this is dropped
                'weight'         => 0.7,    // noisy-OR discount of the embedding evidence (0..1)
                'text_chars'     => 600,    // entry text sent to the embedder (MiniLM reads ~256 tokens)
                'timeout_s'      => 20,     // HTTP timeout per embed call (seconds, builder only)
            ],
            // How much each source of the prior counts (x its table weight): the entry's own
            // name (topic, aliases) says what it IS; its tags name related things (a sabre cat's
            // tags mention 'alchemy eyes', Whiterun's mention the Skyforge and a temple).
            'prior_weights' => ['knowledge_class' => 0.6, 'category' => 1.0, 'name' => 1.0, 'tags' => 0.6],
            // Oghma knowledge_class tokens ("who knows this"), comma-separated in the row.
            'knowledge_class_prior' => [
                'scholar'               => ['scholarly' => 1.0],
                'mage'                  => ['enchanting' => 0.7, 'scholarly' => 0.5],
                'college_of_winterhold' => ['scholarly' => 0.7, 'enchanting' => 0.6],
                'collegeofwinterhold'   => ['scholarly' => 0.7, 'enchanting' => 0.6],
                'psijic_order'          => ['scholarly' => 0.7, 'enchanting' => 0.6, 'spiritual' => 0.3],
                'alchemist'             => ['alchemy' => 1.0],
                'healer'                => ['alchemy' => 0.5, 'spiritual' => 0.4],
                'blacksmith'            => ['crafting' => 0.6, 'combat' => 0.3],
                'warrior'               => ['combat' => 1.0],
                'hunter'                => ['nature' => 0.8, 'combat' => 0.4, 'wild' => 0.5],
                'fisher'                => ['nature' => 0.6, 'quiet' => 0.4, 'domestic' => 0.3],
                'priest'                => ['spiritual' => 1.0, 'sacred' => 0.6],
                'daedra'                => ['spiritual' => 0.4, 'danger' => 0.5, 'dark' => 0.3],
                'daedric'               => ['spiritual' => 0.4, 'danger' => 0.5, 'dark' => 0.3],
                'dragon'                => ['combat' => 0.5, 'danger' => 0.7, 'adventure' => 0.5],
                'noble'                 => ['wealth' => 0.6, 'social' => 0.5, 'luxury' => 0.6],
                'merchant'              => ['wealth' => 0.8, 'social' => 0.4],
                'east_empire_company'   => ['wealth' => 0.8, 'social' => 0.3],
                'dealer'                => ['wealth' => 0.6, 'danger' => 0.3],
                'innkeeper'             => ['social' => 0.9, 'domestic' => 0.4, 'crowd' => 0.4],
                'bard'                  => ['social' => 0.9],
                'thief'                 => ['adventure' => 0.6, 'wealth' => 0.6],
                'thieves_guild'         => ['adventure' => 0.6, 'wealth' => 0.6],
                'dark_brotherhood'      => ['combat' => 0.5, 'danger' => 0.6, 'dark' => 0.6],
                'morag_tong'            => ['combat' => 0.5, 'danger' => 0.6, 'dark' => 0.5],
                'vampire'               => ['dark' => 0.9, 'danger' => 0.6],
                'volkihar'              => ['dark' => 0.9, 'danger' => 0.6],
                'werewolf'              => ['wild' => 0.8, 'nature' => 0.5, 'combat' => 0.5],
                'witch'                 => ['alchemy' => 0.5, 'dark' => 0.5, 'wild' => 0.3],
                'companions'            => ['combat' => 0.9],
                'imperial_legion'       => ['combat' => 0.8],
                'legion'                => ['combat' => 0.8],
                'stormcloaks'           => ['combat' => 0.8],
                'guard'                 => ['combat' => 0.6],
                'blades'                => ['combat' => 0.8, 'adventure' => 0.3],
                'dawnguard'             => ['combat' => 0.8, 'dark' => 0.3],
                'forsworn'              => ['combat' => 0.6, 'wild' => 0.6, 'danger' => 0.5],
                'traveler'              => ['adventure' => 0.3],
                'sailor'                => ['adventure' => 0.5, 'nature' => 0.3],
                'dwemer'                => ['scholarly' => 0.6, 'crafting' => 0.5, 'adventure' => 0.5],
                'snow_elf'              => ['scholarly' => 0.4, 'adventure' => 0.3, 'dark' => 0.3],
                'skaal'                 => ['nature' => 0.6, 'spiritual' => 0.5],
                'miraak_cult'           => ['spiritual' => 0.4, 'danger' => 0.6, 'dark' => 0.4],
                'thalmor'               => ['danger' => 0.4],
            ],
            // Oghma category (one per row). Hold categories (whiterun, rift, ...) carry no facet:
            // the place's own tags say what kind of place it is.
            'category_prior' => [
                'spells'    => ['enchanting' => 0.8, 'scholarly' => 0.3],
                'lore'      => ['scholarly' => 0.8],
                'figures'   => ['social' => 0.3, 'scholarly' => 0.3],
                'creatures' => ['nature' => 0.4, 'danger' => 0.5, 'combat' => 0.4, 'wild' => 0.4],
                'equipment' => ['combat' => 0.8, 'crafting' => 0.4],
                'artifacts' => ['adventure' => 0.5, 'scholarly' => 0.4, 'wealth' => 0.4, 'enchanting' => 0.3],
            ],
            // Words in an Oghma entry's topic, aliases and tags (and topic / place names without
            // an Oghma entry). No 'mountain': lore tags name Red Mountain / Velothi Mountains as
            // geography, which would make Dwemer lore 'nature'.
            'tag_keywords' => [
                'dwemer'          => ['scholarly' => 0.6, 'crafting' => 0.5, 'enchanting' => 0.3, 'adventure' => 0.6, 'danger' => 0.4, 'confined' => 0.5],
                'dwarven'         => ['scholarly' => 0.6, 'crafting' => 0.5, 'enchanting' => 0.3, 'adventure' => 0.6, 'danger' => 0.4, 'confined' => 0.5],
                'deep elves'      => ['scholarly' => 0.6, 'crafting' => 0.4],
                'nordic ruin'     => ['adventure' => 0.8, 'danger' => 0.6, 'dark' => 0.6, 'confined' => 0.6, 'combat' => 0.5],
                'barrow'          => ['adventure' => 0.8, 'danger' => 0.6, 'dark' => 0.6, 'confined' => 0.6, 'combat' => 0.5],
                'tomb'            => ['adventure' => 0.8, 'danger' => 0.6, 'dark' => 0.6, 'confined' => 0.6, 'combat' => 0.5],
                'crypt'           => ['adventure' => 0.8, 'danger' => 0.6, 'dark' => 0.6, 'confined' => 0.6, 'combat' => 0.5],
                'draugr'          => ['adventure' => 0.6, 'danger' => 0.6, 'dark' => 0.7, 'combat' => 0.5],
                'ruin'            => ['adventure' => 0.7, 'danger' => 0.4],
                'dungeon'         => ['adventure' => 0.8, 'danger' => 0.6, 'confined' => 0.6, 'dark' => 0.5],
                'cave'            => ['adventure' => 0.5, 'dark' => 0.5, 'confined' => 0.6],
                'cavern'          => ['adventure' => 0.5, 'dark' => 0.5, 'confined' => 0.6],
                'underground'     => ['adventure' => 0.4, 'dark' => 0.5, 'confined' => 0.7],
                'mine'            => ['crafting' => 0.4, 'wealth' => 0.3, 'confined' => 0.6, 'dark' => 0.4],
                'forest'          => ['nature' => 0.9, 'wild' => 0.7],
                'woods'           => ['nature' => 0.9, 'wild' => 0.7],
                'grove'           => ['nature' => 0.9, 'wild' => 0.5, 'quiet' => 0.4],
                'wilderness'      => ['nature' => 0.9, 'wild' => 0.9],
                'tundra'          => ['nature' => 0.8, 'wild' => 0.7],
                'marsh'           => ['nature' => 0.7, 'wild' => 0.7],
                'river'           => ['nature' => 0.8, 'wild' => 0.4, 'quiet' => 0.4],
                'lake'            => ['nature' => 0.8, 'wild' => 0.4, 'quiet' => 0.4],
                'hot springs'     => ['nature' => 0.8, 'quiet' => 0.5],
                'coast'           => ['nature' => 0.7, 'wild' => 0.5],
                'wildlife'        => ['nature' => 0.8, 'wild' => 0.5],
                'animal'          => ['nature' => 0.7, 'wild' => 0.4],
                'pelt'            => ['nature' => 0.6, 'wild' => 0.4],
                'hunting'         => ['nature' => 0.7, 'combat' => 0.4, 'wild' => 0.5],
                'hunter'          => ['nature' => 0.7, 'combat' => 0.4, 'wild' => 0.5],
                'fishing'         => ['nature' => 0.6, 'quiet' => 0.5, 'domestic' => 0.3],
                'camp'            => ['nature' => 0.5, 'wild' => 0.4],
                'library'         => ['scholarly' => 1.0, 'quiet' => 0.8, 'confined' => 0.4],
                'arcanaeum'       => ['scholarly' => 1.0, 'enchanting' => 0.4, 'quiet' => 0.8, 'confined' => 0.4],
                'book'            => ['scholarly' => 1.0, 'quiet' => 0.4],
                'research'        => ['scholarly' => 1.0, 'quiet' => 0.4],
                'scholar'         => ['scholarly' => 1.0],
                'history'         => ['scholarly' => 0.8],
                'museum'          => ['scholarly' => 0.7, 'adventure' => 0.3, 'quiet' => 0.4],
                'artifact'        => ['scholarly' => 0.5, 'adventure' => 0.5],
                'college'         => ['scholarly' => 0.7, 'enchanting' => 0.6],
                'magic'           => ['enchanting' => 0.7, 'scholarly' => 0.3],
                'arcane'          => ['enchanting' => 0.7, 'scholarly' => 0.4],
                'spell'           => ['enchanting' => 0.7, 'scholarly' => 0.3],
                'conjuration'     => ['enchanting' => 0.7],
                'destruction'     => ['enchanting' => 0.6, 'combat' => 0.4],
                'illusion'        => ['enchanting' => 0.7],
                'alteration'      => ['enchanting' => 0.7],
                'restoration'     => ['enchanting' => 0.5, 'spiritual' => 0.3],
                'enchanting'      => ['enchanting' => 1.0],
                'enchantment'     => ['enchanting' => 1.0],
                'soul gem'        => ['enchanting' => 1.0],
                'soul trapping'   => ['enchanting' => 0.9, 'dark' => 0.4],
                'alchemy'         => ['alchemy' => 1.0],
                'potion'          => ['alchemy' => 1.0],
                'poison'          => ['alchemy' => 0.9, 'danger' => 0.4],
                'ingredient'      => ['alchemy' => 1.0],
                'apothecary'      => ['alchemy' => 1.0],
                'herb'            => ['alchemy' => 0.8, 'nature' => 0.5],
                'smithing'        => ['crafting' => 1.0],
                'blacksmith'      => ['crafting' => 1.0],
                'blacksmithing'   => ['crafting' => 1.0],
                'forge'           => ['crafting' => 1.0],
                'skyforge'        => ['crafting' => 1.0, 'combat' => 0.4],
                'smelter'         => ['crafting' => 0.9],
                'anvil'           => ['crafting' => 0.9],
                'ore'             => ['crafting' => 0.7, 'wealth' => 0.3],
                'ingot'           => ['crafting' => 0.8],
                'weapon'          => ['combat' => 0.8, 'crafting' => 0.3],
                'heavy armor'     => ['combat' => 0.7, 'crafting' => 0.4],
                'temple'          => ['spiritual' => 1.0, 'sacred' => 0.9, 'quiet' => 0.4],
                'shrine'          => ['spiritual' => 1.0, 'sacred' => 0.9, 'quiet' => 0.4],
                'chapel'          => ['spiritual' => 1.0, 'sacred' => 0.9, 'quiet' => 0.4],
                'hall of the dead'=> ['spiritual' => 0.9, 'sacred' => 0.8, 'dark' => 0.4, 'quiet' => 0.6],
                'altar'           => ['spiritual' => 0.8, 'sacred' => 0.9],
                'divines'         => ['spiritual' => 1.0, 'sacred' => 0.6],
                'aedric'          => ['spiritual' => 0.9, 'sacred' => 0.6],
                'prayer'          => ['spiritual' => 1.0, 'quiet' => 0.4],
                'worship'         => ['spiritual' => 1.0, 'sacred' => 0.6],
                'sacred'          => ['spiritual' => 0.8, 'sacred' => 1.0],
                'monastery'       => ['spiritual' => 0.7, 'quiet' => 0.9, 'sacred' => 0.5],
                'greybeards'      => ['spiritual' => 0.6, 'quiet' => 1.0, 'sacred' => 0.5],
                'daedra'          => ['spiritual' => 0.5, 'danger' => 0.6, 'dark' => 0.4],
                'daedric'         => ['spiritual' => 0.5, 'danger' => 0.6, 'dark' => 0.4],
                'oblivion'        => ['spiritual' => 0.4, 'danger' => 0.7, 'dark' => 0.5],
                'inn'             => ['social' => 1.0, 'crowd' => 0.5],
                'tavern'          => ['social' => 1.0, 'crowd' => 0.5],
                'meadery'         => ['social' => 0.8, 'crafting' => 0.3],
                'bard'            => ['social' => 0.9],
                'music'           => ['social' => 0.8],
                'feast'           => ['social' => 0.9, 'domestic' => 0.4, 'crowd' => 0.5],
                'market'          => ['wealth' => 0.6, 'social' => 0.5, 'crowd' => 0.8],
                'merchant'        => ['wealth' => 0.7, 'social' => 0.4],
                'trade'           => ['wealth' => 0.7, 'social' => 0.3],
                'caravan'         => ['wealth' => 0.5, 'adventure' => 0.4, 'social' => 0.3],
                'city'            => ['social' => 0.6, 'crowd' => 0.6],
                'town'            => ['social' => 0.5, 'crowd' => 0.4],
                'village'         => ['social' => 0.5, 'domestic' => 0.4],
                'palace'          => ['social' => 0.5, 'wealth' => 0.6, 'luxury' => 0.9],
                'jarl'            => ['social' => 0.5, 'wealth' => 0.4, 'luxury' => 0.6],
                'court'           => ['social' => 0.6, 'luxury' => 0.6],
                'noble'           => ['social' => 0.5, 'wealth' => 0.5, 'luxury' => 0.7],
                'gold'            => ['wealth' => 1.0, 'luxury' => 0.4],
                'jewel'           => ['wealth' => 1.0, 'luxury' => 0.6],
                'gem'             => ['wealth' => 0.9, 'luxury' => 0.5],
                'treasure'        => ['wealth' => 0.9, 'adventure' => 0.6],
                'riches'          => ['wealth' => 1.0, 'luxury' => 0.5],
                'farm'            => ['domestic' => 0.9, 'nature' => 0.4],
                'home'            => ['domestic' => 1.0],
                'cooking'         => ['domestic' => 1.0],
                'food'            => ['domestic' => 0.8],
                'hearth'          => ['domestic' => 0.9, 'quiet' => 0.3],
                'family'          => ['domestic' => 0.9, 'social' => 0.4],
                'war'             => ['combat' => 0.9, 'danger' => 0.6],
                'civil war'       => ['combat' => 0.9, 'danger' => 0.6],
                'battle'          => ['combat' => 1.0, 'danger' => 0.6],
                'military'        => ['combat' => 0.8],
                'fort'            => ['combat' => 0.7, 'danger' => 0.4],
                'soldier'         => ['combat' => 0.8],
                'rebellion'       => ['combat' => 0.7, 'danger' => 0.5],
                'companions'      => ['combat' => 0.9, 'social' => 0.3],
                'fighters guild'  => ['combat' => 0.9, 'social' => 0.3],
                'mercenaries'     => ['combat' => 0.9],
                'bandit'          => ['combat' => 0.7, 'danger' => 0.7],
                'outlaw'          => ['combat' => 0.6, 'danger' => 0.7],
                'forsworn'        => ['combat' => 0.7, 'danger' => 0.7, 'wild' => 0.5],
                'necromancer'     => ['danger' => 0.7, 'dark' => 0.9, 'enchanting' => 0.3],
                'necromancy'      => ['danger' => 0.6, 'dark' => 0.9, 'enchanting' => 0.4],
                'warlock'         => ['danger' => 0.6, 'dark' => 0.7, 'enchanting' => 0.4],
                'undead'          => ['danger' => 0.7, 'dark' => 0.9],
                'vampire'         => ['danger' => 0.7, 'dark' => 0.9],
                'skeleton'        => ['danger' => 0.5, 'dark' => 0.7],
                'ghost'           => ['danger' => 0.5, 'dark' => 0.7],
                'dragon'          => ['combat' => 0.6, 'danger' => 0.8, 'adventure' => 0.6],
                'dragon priest'   => ['combat' => 0.6, 'danger' => 0.9, 'dark' => 0.6, 'adventure' => 0.6],
                "thu'um"          => ['spiritual' => 0.5, 'combat' => 0.4],
                'giant'           => ['danger' => 0.6, 'wild' => 0.6, 'nature' => 0.4],
                'mammoth'         => ['wild' => 0.6, 'nature' => 0.5],
                'troll'           => ['danger' => 0.6, 'wild' => 0.6, 'combat' => 0.4],
                'thieves guild'   => ['adventure' => 0.6, 'wealth' => 0.6, 'dark' => 0.3],
                'thief'           => ['adventure' => 0.5, 'wealth' => 0.5],
                'dark brotherhood'=> ['combat' => 0.5, 'danger' => 0.7, 'dark' => 0.7],
                'assassin'        => ['combat' => 0.5, 'danger' => 0.7, 'dark' => 0.6],
                'ship'            => ['adventure' => 0.5, 'nature' => 0.3],
                'harbor'          => ['adventure' => 0.4, 'social' => 0.3, 'crowd' => 0.4],
                'dock'            => ['adventure' => 0.4, 'social' => 0.3],
                'stables'         => ['nature' => 0.4, 'domestic' => 0.3],
                'prison'          => ['confined' => 0.9, 'danger' => 0.4, 'dark' => 0.4],
                'jail'            => ['confined' => 0.9, 'danger' => 0.4],
            ],
            // Item names as the game reports them (gifts, itemfound "gave X to Y").
            'item_keywords' => [
                'sword'         => ['combat' => 1.0, 'crafting' => 0.3],
                'greatsword'    => ['combat' => 1.0, 'crafting' => 0.3],
                'dagger'        => ['combat' => 0.9, 'crafting' => 0.3],
                'axe'           => ['combat' => 1.0, 'crafting' => 0.3],
                'war axe'       => ['combat' => 1.0, 'crafting' => 0.3],
                'battleaxe'     => ['combat' => 1.0, 'crafting' => 0.3],
                'mace'          => ['combat' => 1.0, 'crafting' => 0.3],
                'warhammer'     => ['combat' => 1.0, 'crafting' => 0.3],
                'bow'           => ['combat' => 0.9, 'nature' => 0.3, 'crafting' => 0.3],
                'crossbow'      => ['combat' => 0.9, 'crafting' => 0.4],
                'arrow'         => ['combat' => 0.8, 'nature' => 0.2],
                'armor'         => ['combat' => 0.7, 'crafting' => 0.4],
                'cuirass'       => ['combat' => 0.7, 'crafting' => 0.4],
                'helmet'        => ['combat' => 0.7, 'crafting' => 0.4],
                'gauntlets'     => ['combat' => 0.6, 'crafting' => 0.4],
                'boots'         => ['combat' => 0.5, 'crafting' => 0.3],
                'shield'        => ['combat' => 0.8, 'crafting' => 0.4],
                'daedric'       => ['combat' => 0.5, 'danger' => 0.5, 'dark' => 0.4],
                'dwarven'       => ['crafting' => 0.5, 'scholarly' => 0.4],
                'staff'         => ['enchanting' => 0.8, 'combat' => 0.3],
                'spell tome'    => ['enchanting' => 0.7, 'scholarly' => 0.7],
                'book'          => ['scholarly' => 1.0, 'quiet' => 0.3],
                'tome'          => ['scholarly' => 1.0, 'quiet' => 0.3],
                'journal'       => ['scholarly' => 0.9, 'quiet' => 0.3],
                'notes'         => ['scholarly' => 0.7],
                'letter'        => ['scholarly' => 0.5, 'social' => 0.4],
                'map'           => ['adventure' => 0.7, 'scholarly' => 0.4],
                'scroll'        => ['enchanting' => 0.7, 'scholarly' => 0.3],
                'potion'        => ['alchemy' => 1.0],
                'elixir'        => ['alchemy' => 1.0],
                'philter'       => ['alchemy' => 1.0],
                'draught'       => ['alchemy' => 1.0],
                'poison'        => ['alchemy' => 0.9, 'danger' => 0.4, 'dark' => 0.3],
                'skooma'        => ['alchemy' => 0.3, 'danger' => 0.5, 'dark' => 0.4],
                'root'          => ['alchemy' => 0.8, 'nature' => 0.4],
                'cap'           => ['alchemy' => 0.7, 'nature' => 0.4],
                'mushroom'      => ['alchemy' => 0.8, 'nature' => 0.4],
                'flower'        => ['alchemy' => 0.6, 'nature' => 0.6, 'luxury' => 0.2],
                'petals'        => ['alchemy' => 0.7, 'nature' => 0.5],
                'salts'         => ['alchemy' => 0.8],
                'dust'          => ['alchemy' => 0.7],
                'wing'          => ['alchemy' => 0.7, 'nature' => 0.4],
                'eye of'        => ['alchemy' => 0.7],
                'nirnroot'      => ['alchemy' => 1.0, 'nature' => 0.5],
                'lavender'      => ['alchemy' => 0.7, 'nature' => 0.6],
                'soul gem'      => ['enchanting' => 1.0],
                'ring'          => ['wealth' => 0.9, 'luxury' => 0.7],
                'necklace'      => ['wealth' => 0.9, 'luxury' => 0.7],
                'circlet'       => ['wealth' => 0.9, 'luxury' => 0.8],
                'amulet'        => ['wealth' => 0.8, 'luxury' => 0.6],
                'amulet of mara'      => ['spiritual' => 1.0, 'sacred' => 0.6],
                'amulet of dibella'   => ['spiritual' => 1.0, 'sacred' => 0.6, 'luxury' => 0.3],
                'amulet of kynareth'  => ['spiritual' => 1.0, 'sacred' => 0.6, 'nature' => 0.4],
                'amulet of akatosh'   => ['spiritual' => 1.0, 'sacred' => 0.6],
                'amulet of arkay'     => ['spiritual' => 1.0, 'sacred' => 0.6],
                'amulet of julianos'  => ['spiritual' => 1.0, 'sacred' => 0.6, 'scholarly' => 0.4],
                'amulet of stendarr'  => ['spiritual' => 1.0, 'sacred' => 0.6],
                'amulet of talos'     => ['spiritual' => 1.0, 'sacred' => 0.6],
                'amulet of zenithar'  => ['spiritual' => 1.0, 'sacred' => 0.6, 'wealth' => 0.4],
                'jewel'         => ['wealth' => 1.0, 'luxury' => 0.7],
                'gem'           => ['wealth' => 1.0, 'luxury' => 0.6],
                'diamond'       => ['wealth' => 1.0, 'luxury' => 0.8],
                'ruby'          => ['wealth' => 1.0, 'luxury' => 0.7],
                'sapphire'      => ['wealth' => 1.0, 'luxury' => 0.7],
                'emerald'       => ['wealth' => 1.0, 'luxury' => 0.7],
                'amethyst'      => ['wealth' => 0.9, 'luxury' => 0.6],
                'garnet'        => ['wealth' => 0.9, 'luxury' => 0.6],
                'pearl'         => ['wealth' => 0.9, 'luxury' => 0.7],
                'gold'          => ['wealth' => 1.0],
                'septim'        => ['wealth' => 1.0],
                'coin'          => ['wealth' => 1.0],
                'ore'           => ['crafting' => 0.9],
                'ingot'         => ['crafting' => 0.9],
                'leather'       => ['crafting' => 0.8],
                'leather strips'=> ['crafting' => 0.9],
                'hide'          => ['crafting' => 0.6, 'nature' => 0.4],
                'firewood'      => ['crafting' => 0.4, 'domestic' => 0.6],
                'pelt'          => ['nature' => 0.8, 'wild' => 0.4, 'crafting' => 0.3],
                'antler'        => ['nature' => 0.8, 'wild' => 0.4],
                'claw'          => ['nature' => 0.7, 'wild' => 0.5],
                'feather'       => ['nature' => 0.7],
                'tusk'          => ['nature' => 0.7, 'wild' => 0.5],
                'bone'          => ['nature' => 0.5, 'crafting' => 0.4],
                'scale'         => ['nature' => 0.5, 'crafting' => 0.4],
                'dragon bone'   => ['crafting' => 0.8, 'combat' => 0.5, 'danger' => 0.3],
                'dragon scale'  => ['crafting' => 0.8, 'combat' => 0.5, 'danger' => 0.3],
                'bread'         => ['domestic' => 0.9],
                'cheese'        => ['domestic' => 0.9],
                'apple'         => ['domestic' => 0.8, 'nature' => 0.3],
                'cabbage'       => ['domestic' => 0.8],
                'potato'        => ['domestic' => 0.8],
                'leek'          => ['domestic' => 0.8],
                'carrot'        => ['domestic' => 0.8],
                'tomato'        => ['domestic' => 0.8],
                'meat'          => ['domestic' => 0.8],
                'venison'       => ['domestic' => 0.8, 'nature' => 0.3],
                'stew'          => ['domestic' => 1.0],
                'soup'          => ['domestic' => 1.0],
                'pie'           => ['domestic' => 0.9],
                'sweetroll'     => ['domestic' => 0.9, 'luxury' => 0.2],
                'sweet roll'    => ['domestic' => 0.9, 'luxury' => 0.2],
                'cake'          => ['domestic' => 0.8, 'luxury' => 0.3],
                'salmon'        => ['domestic' => 0.8, 'nature' => 0.3],
                'honey'         => ['domestic' => 0.7, 'nature' => 0.3],
                'mead'          => ['social' => 0.8, 'domestic' => 0.3],
                'ale'           => ['social' => 0.8, 'domestic' => 0.3],
                'wine'          => ['social' => 0.7, 'luxury' => 0.4],
                'brandy'        => ['social' => 0.7, 'luxury' => 0.3],
                'lockpick'      => ['adventure' => 0.7],
                'torch'         => ['adventure' => 0.4],
                'lantern'       => ['adventure' => 0.3, 'domestic' => 0.3],
                'lute'          => ['social' => 0.9],
                'drum'          => ['social' => 0.9],
                'flute'         => ['social' => 0.9],
                'fine clothes'  => ['luxury' => 0.9, 'wealth' => 0.4, 'social' => 0.3],
                'robes'         => ['luxury' => 0.3, 'enchanting' => 0.3],
                'clothes'       => ['luxury' => 0.4, 'domestic' => 0.3],
                'pickaxe'       => ['crafting' => 0.6],
                "woodcutter's axe" => ['crafting' => 0.5, 'domestic' => 0.5],
                'tankard'       => ['domestic' => 0.6, 'social' => 0.3],
                'goblet'        => ['domestic' => 0.5, 'luxury' => 0.4],
            ],
            // Creature names.
            'creature_keywords' => [
                'wolf'          => ['nature' => 0.7, 'wild' => 0.8, 'danger' => 0.5, 'combat' => 0.4],
                'wolves'        => ['nature' => 0.7, 'wild' => 0.8, 'danger' => 0.5, 'combat' => 0.4],
                'bear'          => ['nature' => 0.7, 'wild' => 0.8, 'danger' => 0.6, 'combat' => 0.5],
                'sabre cat'     => ['nature' => 0.7, 'wild' => 0.8, 'danger' => 0.6, 'combat' => 0.5],
                'deer'          => ['nature' => 0.9, 'wild' => 0.4, 'quiet' => 0.3],
                'elk'           => ['nature' => 0.9, 'wild' => 0.4, 'quiet' => 0.3],
                'fox'           => ['nature' => 0.9, 'wild' => 0.4],
                'hare'          => ['nature' => 0.9, 'wild' => 0.3],
                'rabbit'        => ['nature' => 0.9, 'wild' => 0.3],
                'goat'          => ['nature' => 0.6, 'domestic' => 0.4],
                'cow'           => ['domestic' => 0.6, 'nature' => 0.4],
                'horse'         => ['nature' => 0.6, 'adventure' => 0.3],
                'dog'           => ['domestic' => 0.6, 'nature' => 0.4],
                'chicken'       => ['domestic' => 0.7],
                'fish'          => ['nature' => 0.7, 'quiet' => 0.3],
                'butterfly'     => ['nature' => 0.9, 'quiet' => 0.5],
                'dragonfly'     => ['nature' => 0.9, 'quiet' => 0.5],
                'mudcrab'       => ['nature' => 0.4, 'combat' => 0.3, 'wild' => 0.3],
                'slaughterfish' => ['nature' => 0.4, 'danger' => 0.4, 'wild' => 0.3],
                'skeever'       => ['danger' => 0.3, 'dark' => 0.3],
                'horker'        => ['nature' => 0.6, 'wild' => 0.6],
                'mammoth'       => ['nature' => 0.6, 'wild' => 0.7, 'danger' => 0.3],
                'giant'         => ['wild' => 0.7, 'danger' => 0.6, 'nature' => 0.4],
                'troll'         => ['danger' => 0.7, 'wild' => 0.6, 'combat' => 0.5],
                'spriggan'      => ['nature' => 0.8, 'wild' => 0.7, 'spiritual' => 0.3, 'danger' => 0.4],
                'hagraven'      => ['dark' => 0.6, 'danger' => 0.6, 'alchemy' => 0.3],
                'draugr'        => ['dark' => 0.8, 'danger' => 0.6, 'combat' => 0.5, 'adventure' => 0.4],
                'skeleton'      => ['dark' => 0.8, 'danger' => 0.5, 'combat' => 0.4],
                'ghost'         => ['dark' => 0.8, 'danger' => 0.5, 'spiritual' => 0.3],
                'wraith'        => ['dark' => 0.8, 'danger' => 0.6],
                'lich'          => ['dark' => 0.9, 'danger' => 0.8, 'enchanting' => 0.3],
                'vampire'       => ['dark' => 0.9, 'danger' => 0.7],
                'death hound'   => ['dark' => 0.8, 'danger' => 0.6],
                'werewolf'      => ['wild' => 0.8, 'danger' => 0.7, 'combat' => 0.5],
                'dragon'        => ['combat' => 0.7, 'danger' => 0.9, 'adventure' => 0.6],
                'dragon priest' => ['dark' => 0.7, 'danger' => 0.9, 'combat' => 0.6, 'adventure' => 0.6],
                'dwarven sphere'    => ['crafting' => 0.5, 'scholarly' => 0.4, 'danger' => 0.6, 'combat' => 0.4],
                'dwarven spider'    => ['crafting' => 0.5, 'scholarly' => 0.4, 'danger' => 0.5, 'combat' => 0.4],
                'dwarven centurion' => ['crafting' => 0.5, 'scholarly' => 0.4, 'danger' => 0.8, 'combat' => 0.5],
                'centurion'     => ['crafting' => 0.5, 'scholarly' => 0.4, 'danger' => 0.7, 'combat' => 0.5],
                'automaton'     => ['crafting' => 0.5, 'scholarly' => 0.4, 'danger' => 0.5],
                'falmer'        => ['danger' => 0.7, 'dark' => 0.7, 'combat' => 0.5, 'confined' => 0.4],
                'chaurus'       => ['danger' => 0.7, 'dark' => 0.6, 'confined' => 0.4],
                'atronach'      => ['enchanting' => 0.5, 'danger' => 0.6],
                'dremora'       => ['danger' => 0.7, 'spiritual' => 0.3, 'combat' => 0.5, 'dark' => 0.4],
                'daedra'        => ['danger' => 0.7, 'spiritual' => 0.4, 'dark' => 0.4],
                'bandit'        => ['combat' => 0.7, 'danger' => 0.6],
                'forsworn'      => ['combat' => 0.7, 'danger' => 0.6, 'wild' => 0.4],
                'necromancer'   => ['dark' => 0.9, 'danger' => 0.6, 'enchanting' => 0.3],
                'warlock'       => ['dark' => 0.7, 'danger' => 0.6, 'enchanting' => 0.4],
                'spider'        => ['danger' => 0.6, 'dark' => 0.5, 'confined' => 0.4],
                'netch'         => ['wild' => 0.5, 'nature' => 0.5],
                'riekling'      => ['wild' => 0.5, 'danger' => 0.4],
                'ash spawn'     => ['danger' => 0.6, 'dark' => 0.4],
                'lurker'        => ['danger' => 0.8, 'dark' => 0.6, 'spiritual' => 0.3],
                'seeker'        => ['danger' => 0.6, 'dark' => 0.6, 'scholarly' => 0.3],
            ],
            // Activities (what the NPC and the player are doing together).
            'activity_keywords' => [
                'hunting'   => ['nature' => 0.9, 'combat' => 0.5, 'wild' => 0.7],
                'hunt'      => ['nature' => 0.9, 'combat' => 0.5, 'wild' => 0.7],
                'fishing'   => ['nature' => 0.7, 'quiet' => 0.7, 'domestic' => 0.3],
                'reading'   => ['scholarly' => 1.0, 'quiet' => 0.7],
                'studying'  => ['scholarly' => 1.0, 'quiet' => 0.6],
                'research'  => ['scholarly' => 1.0, 'quiet' => 0.5],
                'smithing'  => ['crafting' => 1.0],
                'forging'   => ['crafting' => 1.0],
                'tempering' => ['crafting' => 0.9, 'combat' => 0.3],
                'crafting'  => ['crafting' => 1.0],
                'cooking'   => ['domestic' => 1.0],
                'brewing'   => ['alchemy' => 0.9],
                'alchemy'   => ['alchemy' => 1.0],
                'enchanting'=> ['enchanting' => 1.0],
                'drinking'  => ['social' => 0.9, 'crowd' => 0.3],
                'feast'     => ['social' => 0.9, 'domestic' => 0.4, 'crowd' => 0.5],
                'party'     => ['social' => 1.0, 'crowd' => 0.6],
                'dancing'   => ['social' => 0.9],
                'singing'   => ['social' => 0.9],
                'praying'   => ['spiritual' => 1.0, 'sacred' => 0.6, 'quiet' => 0.6],
                'prayer'    => ['spiritual' => 1.0, 'sacred' => 0.6, 'quiet' => 0.6],
                'meditating'=> ['spiritual' => 0.7, 'quiet' => 1.0],
                'fighting'  => ['combat' => 1.0, 'danger' => 0.5],
                'sparring'  => ['combat' => 1.0],
                'training'  => ['combat' => 0.8],
                'battle'    => ['combat' => 1.0, 'danger' => 0.7],
                'exploring' => ['adventure' => 1.0, 'danger' => 0.3],
                'delving'   => ['adventure' => 1.0, 'danger' => 0.5, 'confined' => 0.5],
                'camping'   => ['nature' => 0.8, 'wild' => 0.6, 'quiet' => 0.4],
                'swimming'  => ['nature' => 0.7],
                'trading'   => ['wealth' => 0.7, 'social' => 0.5, 'crowd' => 0.4],
                'shopping'  => ['wealth' => 0.6, 'social' => 0.5, 'crowd' => 0.5],
                'haggling'  => ['wealth' => 0.8, 'social' => 0.5],
                'walking'   => ['nature' => 0.6, 'quiet' => 0.3],
                'hiking'    => ['nature' => 0.8, 'adventure' => 0.5, 'wild' => 0.5],
                'riding'    => ['nature' => 0.6, 'adventure' => 0.5],
                'travel'    => ['adventure' => 0.8, 'nature' => 0.4],
                'gardening' => ['domestic' => 0.7, 'nature' => 0.6],
                'farming'   => ['domestic' => 0.8, 'nature' => 0.5],
                'stargazing'=> ['nature' => 0.6, 'quiet' => 0.8, 'spiritual' => 0.3],
                'resting'   => ['domestic' => 0.5, 'quiet' => 0.6],
                'looting'   => ['wealth' => 0.7, 'adventure' => 0.7],
            ],
        ];
    }

    /**
     * Default config 'thing_appraisal': what an appraisal of a topic or a gift does.
     * MDD 1.2: interests act as a 0.5x .. 2.0x multiplier.
     */
    public static function appraisalDefaults(): array
    {
        return [
            // valence -1 -> interest_mult_min, 0 -> 1.0, +1 -> interest_mult_max (linear each side)
            'interest_mult_min' => 0.5,
            'interest_mult_max' => 2.0,
            // |valence| (-1..+1) at which a topic counts as a match (positive) / a turn-off
            // (negative): the felt read reaches the LLM, a positive match feeds flirt-in-context
            'topic_match_min_valence' => 0.3,
            // gift multiplier when the item has no facets at all (old processGift: a generic
            // gift feels transactional)
            'gift_unclassified_mult' => 0.5,
        ];
    }

    /** Stored config 'facet_classifier' over the defaults, one level deep (a stored table replaces its default). */
    public static function config(): array
    {
        $stored = RelationshipDynamics::configValue('facet_classifier');
        $cfg = self::configDefaults();
        if ($stored !== null && !is_array($stored)) {
            error_log('[RelDyn-FACETS] ERROR config facet_classifier is not an object; using the defaults');
            return $cfg;
        }
        foreach ((array) $stored as $key => $value) {
            if (!array_key_exists($key, $cfg)) {
                continue;
            }
            if (!is_array($value)) {
                error_log("[RelDyn-FACETS] ERROR config facet_classifier.{$key} is not an object; using its default");
                continue;
            }
            $cfg[$key] = ($key === 'embedding') ? array_replace($cfg[$key], $value) : $value;
        }
        return $cfg;
    }

    /** Stored config 'thing_appraisal' over its defaults. */
    public static function appraisalConfig(): array
    {
        $stored = RelationshipDynamics::configValue('thing_appraisal');
        if ($stored !== null && !is_array($stored)) {
            error_log('[RelDyn-FACETS] ERROR config thing_appraisal is not an object; using the defaults');
            $stored = [];
        }
        return array_replace(self::appraisalDefaults(), (array) $stored);
    }

    /**
     * Version stamp of a stored row: algorithm, method and a hash of everything that shapes the
     * result (tables, anchors, embedding parameters). A config edit changes it, so the builder
     * recomputes the rows it stamped.
     */
    public static function version(array $cfg, string $method): string
    {
        $shape = $cfg;
        unset($shape['embedding']['timeout_s']);
        if ($method === 'prior') {
            unset($shape['anchors'], $shape['embedding']);
        }
        return 'a' . self::ALGORITHM . ':' . $method . ':' . substr(sha1((string) json_encode($shape)), 0, 16);
    }

    // =========================================================================
    // Facet vector helpers
    // =========================================================================

    /** Only known facets, weights clamped to 0..1, zeros dropped. */
    public static function sanitize(array $facets): array
    {
        $out = [];
        foreach ($facets as $facet => $w) {
            if (!in_array($facet, RelDynFacets::FACETS, true) || !is_numeric($w)) {
                continue;
            }
            $w = max(0.0, min(1.0, (float) $w));
            if ($w > 0.0) {
                $out[$facet] = $w;
            }
        }
        return $out;
    }

    /** Per-facet maximum of several facet vectors. */
    public static function maxMerge(array $vectors): array
    {
        $out = [];
        foreach ($vectors as $v) {
            foreach (self::sanitize((array) $v) as $facet => $w) {
                $out[$facet] = max($out[$facet] ?? 0.0, $w);
            }
        }
        return self::rounded($out);
    }

    private static function rounded(array $facets): array
    {
        $out = [];
        foreach ($facets as $facet => $w) {
            $w = round((float) $w, 3);
            if ($w > 0.0) {
                $out[$facet] = $w;
            }
        }
        arsort($out);
        return $out;
    }

    /** Lowercased name key: underscores are spaces, whitespace collapsed ('Ebony_Mace ' -> 'ebony mace'). */
    public static function nameKey(string $name): string
    {
        $k = mb_strtolower(str_replace('_', ' ', $name), 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $k));
    }

    /**
     * Facets from a keyword table (keyword => facet vector) over $text: whole-word matches (a
     * trailing s/es plural also matches), per-facet maximum over the matches; a keyword that is
     * part of a longer matched keyword yields to it.
     */
    public static function keywordFacets(string $text, array $table): array
    {
        $text = self::nameKey($text);
        if ($text === '') {
            return [];
        }
        $matched = [];
        foreach ($table as $kw => $facets) {
            $k = self::nameKey((string) $kw);
            if ($k === '' || !is_array($facets)) {
                continue;
            }
            $re = '/(?<![\p{L}\p{N}])' . preg_quote($k, '/') . '(?:s|es)?(?![\p{L}\p{N}])/u';
            if (preg_match($re, $text) === 1) {
                $matched[$k] = $facets;
            }
        }
        foreach (array_keys($matched) as $k) {
            foreach (array_keys($matched) as $other) {
                if ($k !== $other && str_contains((string) $other, (string) $k)) {
                    unset($matched[$k]);
                    break;
                }
            }
        }
        return self::maxMerge(array_values($matched));
    }

    /**
     * The deterministic prior of one Oghma row: knowledge_class tokens, category, the words of
     * its name (topic, aliases) and of its tags through the tables, each source x its
     * prior_weights entry; per-facet maximum over the sources.
     */
    public static function priorFacets(array $row, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $w = array_replace(['knowledge_class' => 1.0, 'category' => 1.0, 'name' => 1.0, 'tags' => 1.0], (array) ($cfg['prior_weights'] ?? []));
        $parts = [];
        foreach (explode(',', (string) ($row['knowledge_class'] ?? '')) as $token) {
            $t = strtolower(trim($token));
            if ($t !== '' && isset($cfg['knowledge_class_prior'][$t])) {
                $parts[] = self::scaled((array) $cfg['knowledge_class_prior'][$t], (float) $w['knowledge_class']);
            }
        }
        $cat = strtolower(trim((string) ($row['category'] ?? '')));
        if ($cat !== '' && isset($cfg['category_prior'][$cat])) {
            $parts[] = self::scaled((array) $cfg['category_prior'][$cat], (float) $w['category']);
        }
        $name = str_replace('_', ' ', (string) ($row['topic'] ?? '')) . ' | ' . str_replace(',', ' | ', (string) ($row['aliases'] ?? ''));
        $parts[] = self::scaled(self::keywordFacets($name, (array) $cfg['tag_keywords']), (float) $w['name']);
        $tags = str_replace(',', ' | ', (string) ($row['tags'] ?? ''));
        $parts[] = self::scaled(self::keywordFacets($tags, (array) $cfg['tag_keywords']), (float) $w['tags']);
        return self::maxMerge($parts);
    }

    /** Every weight x $k. */
    private static function scaled(array $facets, float $k): array
    {
        return array_map(fn($x) => is_numeric($x) ? (float) $x * $k : $x, $facets);
    }

    /** Cosine similarity of every anchor with $vec: facet => cosine. */
    public static function anchorSimilarities(array $vec, array $anchorVecs): array
    {
        $sims = [];
        foreach ($anchorVecs as $facet => $anchor) {
            $sims[$facet] = (float) RelationshipDynamics::cosineSimilarity($vec, $anchor);
        }
        return $sims;
    }

    /**
     * Facet mix of one embedding against the anchor embeddings (see the file comment). With
     * $calibration (facet => ['mean' => .., 'std' => ..], the builder's corpus statistics):
     * z-scores, z_temperature, min_top_z; without: raw cosines, temperature, min_similarity.
     */
    public static function embeddingFacets(array $vec, array $anchorVecs, ?array $params = null, ?array $calibration = null): array
    {
        $p = $params ?? self::config()['embedding'];
        $scores = self::anchorSimilarities($vec, $anchorVecs);
        if ($scores === []) {
            return [];
        }
        if ($calibration !== null) {
            foreach ($scores as $facet => $s) {
                $c = $calibration[$facet] ?? null;
                if (!is_array($c) || !is_numeric($c['mean'] ?? null) || !is_numeric($c['std'] ?? null) || (float) $c['std'] <= 0.0) {
                    error_log("[RelDyn-FACETS] ERROR no calibration for facet {$facet}; entry left unclassified by the embedding");
                    return [];
                }
                $scores[$facet] = ($s - (float) $c['mean']) / (float) $c['std'];
            }
        }
        $top = max($scores);
        $floor = $calibration !== null ? (float) $p['min_top_z'] : (float) $p['min_similarity'];
        if ($top < $floor) {
            return [];
        }
        $t = max(1e-6, (float) ($calibration !== null ? $p['z_temperature'] : $p['temperature']));
        $out = [];
        foreach ($scores as $facet => $z) {
            $w = exp(($z - $top) / $t);
            if ($w >= (float) $p['min_weight']) {
                $out[$facet] = $w;
            }
        }
        return self::rounded(self::sanitize($out));
    }

    /**
     * Corpus statistics of each anchor's cosine: facet => ['mean', 'std'] over $vectors
     * (population std). Null when there are fewer than two vectors.
     */
    public static function calibrate(array $vectors, array $anchorVecs): ?array
    {
        if (count($vectors) < 2) {
            return null;
        }
        $cols = [];
        foreach ($vectors as $vec) {
            foreach (self::anchorSimilarities($vec, $anchorVecs) as $facet => $s) {
                $cols[$facet][] = $s;
            }
        }
        $out = [];
        foreach ($cols as $facet => $xs) {
            $m = array_sum($xs) / count($xs);
            $var = array_sum(array_map(fn($x) => ($x - $m) ** 2, $xs)) / count($xs);
            $out[$facet] = ['mean' => $m, 'std' => sqrt($var)];
        }
        return $out;
    }

    /** Noisy-OR of the prior and the embedding evidence: 1 - (1 - prior) x (1 - weight x embed). */
    public static function combine(array $embed, array $prior, ?array $params = null): array
    {
        $p = $params ?? self::config()['embedding'];
        $k = max(0.0, min(1.0, (float) $p['weight']));
        $embed = self::sanitize($embed);
        $prior = self::sanitize($prior);
        $out = [];
        foreach (array_unique(array_merge(array_keys($embed), array_keys($prior))) as $facet) {
            $out[$facet] = 1.0 - (1.0 - ($prior[$facet] ?? 0.0)) * (1.0 - $k * ($embed[$facet] ?? 0.0));
        }
        return self::rounded($out);
    }

    /** Text embedded for one Oghma entry: name, aliases, category, classes, tags, then the start of the article. */
    public static function entryText(array $row, ?array $cfg = null): string
    {
        $cfg = $cfg ?? self::config();
        $head = array_filter([
            str_replace('_', ' ', (string) ($row['topic'] ?? '')),
            (string) ($row['aliases'] ?? ''),
            (string) ($row['category'] ?? ''),
            (string) ($row['knowledge_class'] ?? ''),
            (string) ($row['tags'] ?? ''),
        ], fn($s) => trim($s) !== '');
        $text = implode('. ', $head) . '. ' . trim((string) preg_replace('/\s+/u', ' ', (string) ($row['topic_desc'] ?? '')));
        return mb_substr(trim($text), 0, max(64, (int) $cfg['embedding']['text_chars']), 'UTF-8');
    }

    // =========================================================================
    // Embedding service (builder only; never on the game-time path)
    // =========================================================================

    /** Replace the embedding HTTP call (tests); null restores the real one. */
    public static function setEmbedder(?callable $fn): void
    {
        self::$embedder = $fn;
    }

    /** FEATURES.MEMORY_EMBEDDING.TXTAI_URL (core general_settings), or null when not configured. */
    public static function serviceUrl(): ?string
    {
        $url = $GLOBALS['FEATURES']['MEMORY_EMBEDDING']['TXTAI_URL'] ?? null;
        return (is_string($url) && trim($url) !== '') ? rtrim(trim($url), '/') : null;
    }

    /** One embedding (the configured dimension count of floats), or null (logged) when the service fails. */
    public static function embed(string $text): ?array
    {
        $vec = self::$embedder !== null ? (self::$embedder)($text) : self::httpEmbed($text);
        if (!is_array($vec)) {
            return null;
        }
        $dims = (int) self::config()['embedding']['dimensions'];
        if (count($vec) !== $dims || !array_is_list($vec)) {
            error_log('[RelDyn-FACETS] ERROR embedding has ' . count($vec) . " values, expected {$dims}; ignored");
            return null;
        }
        return array_map('floatval', $vec);
    }

    /** POST {TXTAI_URL}/embed {"text": ...} -> {"embedding": [...]} (the MiniMe service core uses). */
    private static function httpEmbed(string $text): ?array
    {
        $base = self::serviceUrl();
        if ($base === null) {
            error_log('[RelDyn-FACETS] embedding service not configured (FEATURES.MEMORY_EMBEDDING.TXTAI_URL is empty)');
            return null;
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => (string) json_encode(['text' => $text]),
            'timeout' => (float) self::config()['embedding']['timeout_s'],
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($base . '/embed', false, $ctx);
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($body === false || $status !== 200) {
            error_log("[RelDyn-FACETS] ERROR embedding service {$base}/embed failed (HTTP " . ($status ?: 'no response') . ')');
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['embedding'] ?? null)) {
            error_log("[RelDyn-FACETS] ERROR embedding service {$base}/embed returned no embedding");
            return null;
        }
        return $data['embedding'];
    }

    // =========================================================================
    // Storage (RelDyn-owned tables; idempotent create)
    // =========================================================================

    public static function ensureTables($db): void
    {
        $db->execQuery('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            topic text PRIMARY KEY,
            keys text[] NOT NULL,
            facets jsonb NOT NULL,
            method text NOT NULL,
            version text NOT NULL,
            source_sha1 text NOT NULL)');
        $db->execQuery('CREATE INDEX IF NOT EXISTS ' . self::TABLE . '_keys ON ' . self::TABLE . ' USING gin (keys)');
        $db->execQuery('CREATE TABLE IF NOT EXISTS ' . self::ANCHOR_TABLE . ' (
            facet text PRIMARY KEY,
            description text NOT NULL,
            vector jsonb NOT NULL,
            version text NOT NULL,
            mean double precision,
            std double precision)');
    }

    private static function tableExists($db, string $table): bool
    {
        $row = $db->fetchOne('SELECT to_regclass($1) IS NOT NULL AS present', [$table]);
        return in_array($row['present'] ?? null, ['t', true, 1, '1'], true);
    }

    /** Lookup keys of an Oghma row: its topic, then each alias (name keys). */
    public static function rowKeys(array $row): array
    {
        $keys = [self::nameKey((string) ($row['topic'] ?? ''))];
        foreach (explode(',', (string) ($row['aliases'] ?? '')) as $alias) {
            $k = self::nameKey($alias);
            if ($k !== '') {
                $keys[] = $k;
            }
        }
        return array_values(array_unique(array_filter($keys, fn($k) => $k !== '')));
    }

    /** Embed every anchor; null when any anchor cannot be embedded (service unavailable). */
    private static function embedAnchors(array $cfg): ?array
    {
        $out = [];
        foreach (RelDynFacets::FACETS as $facet) {
            $text = $cfg['anchors'][$facet] ?? null;
            if (!is_string($text) || trim($text) === '') {
                error_log("[RelDyn-FACETS] ERROR anchor for facet {$facet} is missing; embedding classification needs one per facet");
                return null;
            }
            $vec = self::embed($text);
            if ($vec === null) {
                return null;
            }
            $out[$facet] = $vec;
        }
        return $out;
    }

    /** True when the oghma table has core's vector384 column (pgvector). */
    private static function oghmaHasVectors($db): bool
    {
        $row = $db->fetchOne(
            "SELECT 1 AS present FROM information_schema.columns WHERE table_name = 'oghma' AND column_name = 'vector384'"
            . ' AND table_schema = ANY (current_schemas(false)) LIMIT 1'
        );
        return !empty($row['present']);
    }

    /** '[0.1,0.2,...]' (pgvector text) -> floats; null when empty. */
    private static function parseVector($text): ?array
    {
        if (!is_string($text) || trim($text) === '') {
            return null;
        }
        $parts = explode(',', trim($text, "[] \t\n"));
        return count($parts) > 1 ? array_map('floatval', $parts) : null;
    }

    /** The anchors' stored corpus calibration for $version (facet => mean/std), or null when incomplete. */
    private static function storedCalibration($db, string $version): ?array
    {
        $out = [];
        foreach ((array) $db->fetchAll('SELECT facet, mean, std FROM ' . self::ANCHOR_TABLE
            . ' WHERE version = ' . $db->escapeLiteral($version)) as $r) {
            if (is_numeric($r['mean'] ?? null) && is_numeric($r['std'] ?? null)) {
                $out[$r['facet']] = ['mean' => (float) $r['mean'], 'std' => (float) $r['std']];
            }
        }
        return count(array_intersect(RelDynFacets::FACETS, array_keys($out))) === count(RelDynFacets::FACETS) ? $out : null;
    }

    /** One entry's vector: core's oghma.vector384 when filled, else the embedding service. Counts into $stats. */
    private static function rowVector(array $row, array $cfg, array &$stats): ?array
    {
        $vec = self::parseVector($row['vector384'] ?? null);
        if ($vec !== null) {
            $stats['reused_vectors']++;
            return $vec;
        }
        $vec = self::embed(self::entryText($row, $cfg));
        $vec === null ? $stats['embed_failed']++ : $stats['embedded']++;
        return $vec;
    }

    /**
     * Precompute facet vectors for every Oghma entry into reldyn_oghma_facets.
     *
     * Embedding mode when the service answers for every anchor (and $opts['prior_only'] is not
     * set); otherwise every row gets the deterministic prior. With at least
     * calibration_min_entries entries the embedding is calibrated on the corpus: the anchors'
     * mean/std are computed over every entry (all of them embedded, all rows recomputed) when
     * none are stored for the current version or $opts['force'], and reused otherwise.
     * A row already stored with the current version and the same source text is skipped unless
     * $opts['force']. A row whose entry cannot be embedded gets the prior with the prior
     * version, so the next embedding run retries it. Rows for topics no longer in oghma are removed.
     *
     * @return array stats: method (prior | embedding | embedding_raw), version, rows, written,
     *               skipped_current, embedded, reused_vectors, embed_failed, calibrated_on, removed
     */
    public static function build($db, array $opts = []): array
    {
        $cfg = self::config();
        $p = $cfg['embedding'];
        $force = !empty($opts['force']);
        self::ensureTables($db);
        $stats = ['method' => 'prior', 'version' => null, 'rows' => 0, 'written' => 0, 'skipped_current' => 0,
            'embedded' => 0, 'reused_vectors' => 0, 'embed_failed' => 0, 'calibrated_on' => 0, 'removed' => 0];

        $vecCol = self::oghmaHasVectors($db) ? ', vector384::text AS vector384' : '';
        $rows = $db->fetchAll("SELECT topic, aliases, knowledge_class, tags, category, topic_desc{$vecCol} FROM oghma ORDER BY topic");
        if (!is_array($rows)) {
            error_log('[RelDyn-FACETS] ERROR could not read the oghma table');
            $stats['error'] = 'oghma unreadable';
            return $stats;
        }
        $rows = array_values(array_filter($rows, fn($r) => trim((string) ($r['topic'] ?? '')) !== ''));

        $anchors = null;
        if (empty($opts['prior_only'])) {
            $anchors = self::embedAnchors($cfg);
            if ($anchors === null) {
                error_log('[RelDyn-FACETS] embedding service unavailable: facets come from the knowledge_class / category / tags prior only');
            }
        }
        $calibrate = $anchors !== null && count($rows) >= (int) $p['calibration_min_entries'];
        $method = $anchors === null ? 'prior' : ($calibrate ? 'embedding' : 'embedding_raw');
        $version = self::version($cfg, $method);
        $priorVersion = self::version($cfg, 'prior');
        $stats['method'] = $method;
        $stats['version'] = $version;

        $vectors = [];
        $cal = null;
        $recomputeAll = $force;
        if ($calibrate) {
            $cal = $force ? null : self::storedCalibration($db, $version);
            if ($cal === null) {
                foreach ($rows as $row) {
                    $vectors[$row['topic']] = self::rowVector($row, $cfg, $stats);
                }
                $cal = self::calibrate(array_values(array_filter($vectors)), $anchors);
                $stats['calibrated_on'] = count(array_filter($vectors));
                $recomputeAll = true;
            }
        }
        if ($anchors !== null) {
            foreach ($anchors as $facet => $vec) {
                $db->fetchOne(
                    'INSERT INTO ' . self::ANCHOR_TABLE . ' (facet, description, vector, version, mean, std) VALUES ($1, $2, $3::jsonb, $4, $5, $6)
                     ON CONFLICT (facet) DO UPDATE SET description = EXCLUDED.description, vector = EXCLUDED.vector,
                         version = EXCLUDED.version, mean = EXCLUDED.mean, std = EXCLUDED.std
                     RETURNING facet',
                    [$facet, $cfg['anchors'][$facet], json_encode($vec), $version, $cal[$facet]['mean'] ?? null, $cal[$facet]['std'] ?? null]
                );
            }
        }

        $existing = [];
        foreach ((array) $db->fetchAll('SELECT topic, version, source_sha1 FROM ' . self::TABLE) as $r) {
            $existing[$r['topic']] = $r;
        }
        foreach ($rows as $row) {
            $topic = (string) $row['topic'];
            $stats['rows']++;
            $sha = sha1((string) json_encode([$topic, $row['aliases'] ?? null, $row['knowledge_class'] ?? null,
                $row['tags'] ?? null, $row['category'] ?? null, $row['topic_desc'] ?? null, $row['vector384'] ?? null]));
            $old = $existing[$topic] ?? null;
            if (!$recomputeAll && $old !== null && $old['source_sha1'] === $sha && $old['version'] === $version) {
                $stats['skipped_current']++;
                continue;
            }

            $prior = self::priorFacets($row, $cfg);
            $embed = [];
            $rowVersion = $priorVersion;
            $rowMethod = 'prior';
            if ($anchors !== null) {
                $vec = array_key_exists($topic, $vectors) ? $vectors[$topic] : self::rowVector($row, $cfg, $stats);
                if ($vec !== null) {
                    $embed = self::embeddingFacets($vec, $anchors, $p, $cal);
                    $rowVersion = $version;
                    $rowMethod = $method;
                }
            }
            $facets = self::combine($embed, $prior, $p);

            $written = $db->fetchOne(
                'INSERT INTO ' . self::TABLE . ' (topic, keys, facets, method, version, source_sha1)
                 VALUES ($1, $2::text[], $3::jsonb, $4, $5, $6)
                 ON CONFLICT (topic) DO UPDATE SET keys = EXCLUDED.keys, facets = EXCLUDED.facets,
                     method = EXCLUDED.method, version = EXCLUDED.version, source_sha1 = EXCLUDED.source_sha1
                 RETURNING topic',
                [$topic, self::pgTextArray(self::rowKeys($row)), json_encode((object) $facets), $rowMethod, $rowVersion, $sha]
            );
            if (($written['topic'] ?? null) !== $topic) {
                error_log("[RelDyn-FACETS] ERROR could not store facets for oghma topic '{$topic}'");
                continue;
            }
            $stats['written']++;
        }

        $removed = $db->fetchAll('DELETE FROM ' . self::TABLE . ' f WHERE NOT EXISTS (SELECT 1 FROM oghma o WHERE o.topic = f.topic) RETURNING f.topic');
        $stats['removed'] = is_array($removed) ? count($removed) : 0;
        return $stats;
    }

    /** PostgreSQL text[] literal for a bound parameter: {"a","b"} with " and \ escaped. */
    private static function pgTextArray(array $values): string
    {
        return '{' . implode(',', array_map(
            fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"',
            $values
        )) . '}';
    }

    // =========================================================================
    // Game-time lookup
    // =========================================================================

    /**
     * Facets of one Oghma entry named $name (topic or alias): the precomputed row, else the
     * prior computed live from core's oghma row; null when Oghma has no such entry.
     *
     * @return array|null ['topic' => canonical topic, 'facets' => [...], 'source' => embedding|prior|live_prior]
     */
    public static function oghmaFacets(string $name): ?array
    {
        $db = $GLOBALS['db'] ?? null;
        $key = self::nameKey($name);
        if (!$db || $key === '') {
            return null;
        }
        try {
            if (self::tableExists($db, self::TABLE)) {
                $row = $db->fetchOne(
                    'SELECT topic, facets::text AS facets, method FROM ' . self::TABLE
                    . ' WHERE $1 = ANY (keys) ORDER BY (keys[1] = $1) DESC, topic LIMIT 1',
                    [$key]
                );
                if (!empty($row['topic'])) {
                    $facets = json_decode((string) $row['facets'], true);
                    return ['topic' => $row['topic'], 'facets' => self::sanitize(is_array($facets) ? $facets : []), 'source' => $row['method']];
                }
            }
            if (!self::tableExists($db, 'oghma')) {
                return null;
            }
            $row = $db->fetchOne(
                "SELECT topic, aliases, knowledge_class, tags, category FROM oghma"
                . " WHERE lower(replace(topic, '_', ' ')) = \$1 ORDER BY topic LIMIT 1",
                [$key]
            );
            if (empty($row['topic'])) {
                $candidates = $db->fetchAll(
                    "SELECT topic, aliases, knowledge_class, tags, category FROM oghma WHERE aliases ILIKE "
                    . $db->escapeLiteral('%' . RelationshipDynamics::escapeLike($key) . '%') . " ESCAPE '\\' ORDER BY topic LIMIT 20"
                );
                $row = null;
                foreach ((array) $candidates as $c) {
                    if (in_array($key, array_slice(self::rowKeys($c), 1), true)) {
                        $row = $c;
                        break;
                    }
                }
            }
            if (empty($row['topic'])) {
                return null;
            }
            return ['topic' => $row['topic'], 'facets' => self::priorFacets($row), 'source' => 'live_prior'];
        } catch (\Throwable $e) {
            RelationshipDynamics::logError("RelDynFacetClassifier::oghmaFacets('{$key}')", $e);
            return null;
        }
    }

    /**
     * RelDynFacets::thingFacets: facet vector of a thing the NPC experiences, [] when unknown.
     *   item / topic / creature / place: the Oghma entry (precomputed, else live prior), then
     *                                     the kind's keyword table (topic and place: tag_keywords);
     *   activity:                         activity_keywords first, then Oghma.
     */
    public static function thingFacets(string $kind, string $name): array
    {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, self::KINDS, true)) {
            error_log("[RelDyn-FACETS] ERROR thingFacets: unknown kind '{$kind}'");
            return [];
        }
        if (self::nameKey($name) === '') {
            return [];
        }
        $cfg = self::config();
        $table = [
            'item' => 'item_keywords', 'creature' => 'creature_keywords', 'activity' => 'activity_keywords',
            'topic' => 'tag_keywords', 'place' => 'tag_keywords',
        ][$kind];
        if ($kind === 'activity') {
            $facets = self::keywordFacets($name, (array) $cfg[$table]);
            if ($facets !== []) {
                return $facets;
            }
        }
        $oghma = self::oghmaFacets($name);
        if ($oghma !== null && $oghma['facets'] !== []) {
            return $oghma['facets'];
        }
        return $kind === 'activity' ? [] : self::keywordFacets($name, (array) $cfg[$table]);
    }

    // =========================================================================
    // Hand-off to the appraisal (RelDynFacets::appraise / feltText)
    // =========================================================================

    /**
     * Appraise one thing for an NPC's preferences: thingFacets -> RelDynFacets::appraise.
     * Null when the thing has no facets (unknown).
     *
     * @return array|null appraise()'s result plus kind, name, facets
     */
    public static function thingAppraisal(array $prefs, string $kind, string $name): ?array
    {
        $facets = RelDynFacets::thingFacets($kind, $name);
        if ($facets === []) {
            return null;
        }
        $a = RelDynFacets::appraise($prefs, $facets);
        $a['kind'] = $kind;
        $a['name'] = $name;
        $a['facets'] = $facets;
        return $a;
    }

    /**
     * MDD 1.2 interest multiplier of a valence: -1 -> interest_mult_min (0.5), 0 -> 1.0,
     * +1 -> interest_mult_max (2.0), linear on each side.
     */
    public static function interestMultiplier(float $valence, ?array $cfg = null): float
    {
        $cfg = $cfg ?? self::appraisalConfig();
        $v = max(-1.0, min(1.0, $valence));
        return $v >= 0.0
            ? 1.0 + $v * ((float) $cfg['interest_mult_max'] - 1.0)
            : 1.0 + $v * (1.0 - (float) $cfg['interest_mult_min']);
    }

    /**
     * The Oghma topics core grounded for THIS turn: processor/oghma.php runs before the ext
     * postrequest hooks in the same request (main.php) and leaves them in
     * $GLOBALS['OGHMA_PARITY_RESULT']['topics'] (conversation topics; forced-context articles
     * are not topics). conf_opts current_oghma_topic is not read: core writes it only on a turn
     * that finds a topic, so it keeps an old topic for every later turn.
     */
    public static function turnTopics(): array
    {
        $result = $GLOBALS['OGHMA_PARITY_RESULT'] ?? null;
        if (!is_array($result) || !is_array($result['topics'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($result['topics'] as $t) {
            if (is_string($t) && trim($t) !== '') {
                $out[] = trim($t);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Appraise this turn's conversation topics for an NPC: the topic with the strongest
     * feeling (largest |valence|) speaks; null when no topic has facets.
     */
    public static function topicAppraisal(array $prefs, array $topics): ?array
    {
        $best = null;
        foreach ($topics as $topic) {
            $a = self::thingAppraisal($prefs, 'topic', (string) $topic);
            if ($a !== null && ($best === null || abs((float) $a['valence']) > abs((float) $best['valence']))) {
                $best = $a;
            }
        }
        return $best;
    }

    /**
     * Topic talk for one turn (postrequest 1b): the NPC's appraisal of what core's Oghma
     * grounded this turn, as the passion multiplier (MDD 1.2 range), whether it is a match for
     * flirt-in-context, and the felt read for the next context (never numbers).
     *
     * @return array ['bonus' => multiplier, 'match' => topic|null (valence >= topic_match_min_valence),
     *                'felt' => text|null (RelDynFacets::feltText), 'appraisal' => array|null]
     */
    public static function topicTurn(array $dynamics, string $npcName, array $topics): array
    {
        $out = ['bonus' => 1.0, 'match' => null, 'felt' => null, 'appraisal' => null];
        if ($topics === []) {
            return $out;
        }
        $cfg = self::appraisalConfig();
        $a = self::topicAppraisal(RelDynFacets::preferences($dynamics, $npcName), $topics);
        if ($a === null) {
            return $out;
        }
        $v = (float) $a['valence'];
        $out['appraisal'] = $a;
        $out['bonus'] = self::interestMultiplier($v, $cfg);
        $min = (float) $cfg['topic_match_min_valence'];
        if ($v >= $min) {
            $out['match'] = $a['name'];
        }
        // Whether the talk is felt at all is feltText's call (facet_appraisal felt_min_* thresholds),
        // the same rule as for places and gifts.
        $out['felt'] = RelDynFacets::feltText($npcName, $a, 'topic', str_replace('_', ' ', (string) $a['name']));
        return $out;
    }

    /**
     * Gift multiplier for processGift: the NPC's appraisal of the item mapped into the MDD 1.2
     * range; gift_unclassified_mult when the item has no facets.
     *
     * @return array ['mult' => multiplier, 'appraisal' => array|null, 'felt' => text|null]
     */
    public static function giftAppraisal(array $dynamics, string $npcName, string $itemName): array
    {
        $cfg = self::appraisalConfig();
        $a = self::thingAppraisal(RelDynFacets::preferences($dynamics, $npcName), 'item', $itemName);
        if ($a === null) {
            return ['mult' => (float) $cfg['gift_unclassified_mult'], 'appraisal' => null, 'felt' => null];
        }
        return [
            'mult' => self::interestMultiplier((float) $a['valence'], $cfg),
            'appraisal' => $a,
            'felt' => RelDynFacets::feltText($npcName, $a, 'item', $itemName),
        ];
    }

    /** The strongest of the 11 MDD interests in a facet vector (legacy interest-string callers), or null. */
    public static function dominantInterest(array $facets): ?string
    {
        $best = null;
        $bestW = 0.0;
        foreach (RelDynFacets::INTERESTS as $interest) {
            $w = (float) ($facets[$interest] ?? 0.0);
            if ($w > $bestW) {
                $best = $interest;
                $bestW = $w;
            }
        }
        return $best;
    }
}
