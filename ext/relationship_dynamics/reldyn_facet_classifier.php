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
 *     knowledge_class_prior, category_prior, tag_keywords). Always available. A magic entry
 *     (a spell, a shout, a school's lore) reads by its school and SUBJECT instead of "magic, so
 *     scholarly" (decisions §10, config facet_classifier.spell_subjects, spellReading()): nature
 *     magic is nature / wild, healing is spiritual, destruction is combat, daedra are dangerous
 *     learning. The same reading classifies spell tomes, staves and the player's own spells.
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
 * Results are precomputed into RelDyn's own table reldyn_oghma_facets (build(), idempotent,
 * versioned by the config tables + method so a change recomputes). The postrequest hook starts
 * it in the background by itself (maybeLaunchBuild: table missing, Oghma rows added or removed,
 * a mapping edited; a prior-only build retries the embedding on the game calendar); the CLI
 * tool tools/build_oghma_facets.php runs the same build by hand. The game-time path
 * (thingFacets) never calls the embedding service: it reads the table, and when the table or the
 * row is missing it computes the prior live from core's oghma row, then falls back to the keyword
 * tables for items, creatures and activities.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynFacetClassifier
{
    const TABLE = 'reldyn_oghma_facets';
    const ANCHOR_TABLE = 'reldyn_facet_anchors';
    /** key => value state of the background build (last_launch_gamets). */
    const BUILD_STATE_TABLE = 'reldyn_facet_build';
    const KINDS = ['item', 'topic', 'creature', 'place', 'activity'];
    /** Bumped when the math (not the tables) changes, so every row is recomputed. */
    const ALGORITHM = 1;

    /** @var callable|null fn(string $text): ?array  -- the embedding HTTP call (tests stub it) */
    private static $embedder = null;
    /** @var callable|null fn(string $reason): void  -- the background process spawn (tests stub it) */
    private static $buildLauncher = null;

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
            // The background build (maybeLaunchBuild, postrequest). Game hours on the game calendar.
            'build' => [
                'auto'                       => true,
                'min_gap_game_hours'         => 1.0,   // no second launch this soon (the first may still run)
                'embedding_retry_game_hours' => 24.0,  // a build that fell back to the prior retries the embedding this often
            ],
            // How much each source of the prior counts (x its table weight): the entry's own
            // name (topic, aliases) says what it IS; its tags name related things (a sabre cat's
            // tags mention 'alchemy eyes', Whiterun's mention the Skyforge and a temple).
            'prior_weights' => ['knowledge_class' => 0.6, 'category' => 1.0, 'name' => 1.0, 'tags' => 0.6],
            // Oghma knowledge_class is WHO may know a topic (core oghma_parity.php
            // chimOghmaKnowledgeClassDecision), a comma-separated audience, not what the topic is
            // about. Only a narrow audience says something: each listed class that has a row here
            // counts x prior_weights.knowledge_class / n^knowledge_class_dilution (n = the number of
            // such classes in the row), so 'alchemist' alone reads alchemy while Solitude's six
            // audiences (nobles, travelers, the Legion, the Thalmor ...) say next to nothing.
            // Broad audiences (scholar, holds, races) have no row: scholars may know Alduin,
            // Balgruuf and Skyrim without those being scholarly (live 3.4.1: 473 of 1,615 rows
            // list 'scholar').
            'knowledge_class_dilution' => 0.5,
            'knowledge_class_prior' => [
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
            // A row whose ONLY class is one of these: knowledge nobody but that audience has.
            // Only-scholars-know is esoteric learning (in-game histories, treatises).
            'knowledge_class_sole_prior' => [
                'scholar' => ['scholarly' => 1.0],
            ],
            // Oghma category (one per row). Hold categories (whiterun, rift, ...) carry no facet:
            // the place's own tags say what kind of place it is.
            'category_prior' => [
                // No 'lore' row: that category holds the in-game books and histories (those are
                // scholar-only, knowledge_class_sole_prior) but also factions, races and gods
                // (companions, bosmer, the Silver Hand), so it says nothing about what an entry is.
                'spells'    => ['enchanting' => 0.8, 'scholarly' => 0.3],
                'figures'   => ['social' => 0.3],
                'creatures' => ['nature' => 0.4, 'danger' => 0.5, 'combat' => 0.4, 'wild' => 0.4],
                'equipment' => ['combat' => 0.8, 'crafting' => 0.4],
                'artifacts' => ['adventure' => 0.5, 'wealth' => 0.4, 'enchanting' => 0.3],
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
                'bards college'   => ['social' => 0.8],
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
            'spell_subjects' => self::spellSubjectDefaults(),
        ];
    }

    /**
     * Default config facet_classifier.spell_subjects (decisions §10: a spell's facets follow its
     * SUBJECT, not "it's magic, so scholar"). Read by spellReading() for Oghma spell rows, item
     * and topic names, and the player's held / cast spells (RelDynPlayer).
     *
     *   categories       Oghma categories whose rows are magic ('spells' also holds diseases
     *                    and dragon shouts). An Oghma row in any OTHER category is what its
     *                    category says, whatever its name: a lore book about the Elder Scrolls
     *                    or war magic is a book, the Staff of Magnus an artifact, a potion of
     *                    resist magic an item (lore stays scholarly).
     *   magic_markers    keywords that make a NAME magic when nothing else says what it is (a
     *                    spell tome or a staff named in dialogue or a gift, no Oghma row; school
     *                    words also do). Tags never do: a history book tagged 'Magic' is a book.
     *   replaced_keywords  tag_keywords / item_keywords entries the spell reading replaces on a
     *                    magic entry (the generic "magic = enchanting + scholarly" words, and the
     *                    college that teaches it)
     *   ignore_knowledge_classes  audiences that say nothing about a spell (every spell lists
     *                    'mage', the college spells list the college; its school and subject say
     *                    what it is)
     *   school_weight_with_subject  x the school's reading when a subject speaks (a familiar
     *                    is conjuration, but it is a wolf first)
     *   tag_subject_weight  x a subject matched only in an Oghma row's tags
     *   default          a magic entry with neither school nor subject (the old spells prior)
     *   schools          school => keywords (found in the name first, then the tags; the
     *                    earliest wins), facets, archetypes (RelDynPlayer::ARCHETYPES, 0..1:
     *                    the player signal of casting it)
     *   subjects         keyword => facets, archetypes; 'exclusive' => true: when any exclusive
     *                    subject matches, only exclusive subjects count (a disease's tags name
     *                    its carriers: wolves, bears), and the prior skips the generic tag
     *                    keywords of its tags for the same reason; 'ignore' => true: matches nothing, only
     *                    shadows the shorter keywords inside it (ingredients: Bear Claws,
     *                    Spriggan Sap, Fire Salts in potion-effect tags)
     * Keywords match whole words (a trailing s/es plural too); a keyword inside a longer matched
     * keyword yields to it ('fire' to 'resist fire').
     */
    public static function spellSubjectDefaults(): array
    {
        $nature = ['nature' => 0.9, 'wild' => 0.6];
        $beast = ['nature' => 0.8, 'wild' => 0.7];
        $shape = ['wild' => 0.9, 'nature' => 0.6, 'danger' => 0.4, 'combat' => 0.4];
        $weather = ['nature' => 0.8, 'wild' => 0.6, 'danger' => 0.2];
        $elemental = ['combat' => 0.7, 'danger' => 0.5];
        $heal = ['spiritual' => 0.6, 'sacred' => 0.3, 'alchemy' => 0.2];
        $holy = ['spiritual' => 0.6, 'sacred' => 0.7, 'combat' => 0.3];
        // Daedra are Oblivion's beings: danger and darkness first, a trace of the binding craft;
        // the dead are dark (decisions §10: no "it's magic, so scholar" trace on either)
        $daedra = ['danger' => 0.7, 'dark' => 0.5, 'enchanting' => 0.4, 'scholarly' => 0.15];
        $necro = ['dark' => 0.9, 'danger' => 0.5];
        $calm = ['social' => 0.6, 'quiet' => 0.5];
        $fear = ['danger' => 0.5, 'social' => 0.4, 'dark' => 0.3];
        $frenzy = ['danger' => 0.6, 'combat' => 0.4];
        $rally = ['social' => 0.5, 'combat' => 0.4];
        $stealth = ['adventure' => 0.5, 'dark' => 0.4];
        $flesh = ['combat' => 0.5, 'enchanting' => 0.3];
        $explore = ['adventure' => 0.5];
        $disease = ['danger' => 0.5, 'alchemy' => 0.4, 'spiritual' => 0.2];
        $vampire = ['dark' => 0.9, 'danger' => 0.6];
        $druid = ['druid' => 0.9];
        $s = fn(array $facets, array $archetypes, array $extra = []) => ['facets' => $facets, 'archetypes' => $archetypes] + $extra;
        $ignore = ['ignore' => true];
        return [
            'categories' => ['spells'],
            'magic_markers' => ['spell', 'spells', 'spell tome', 'scroll', 'staff', 'magic', 'arcane', 'shout', "thu'um"],
            'replaced_keywords' => ['spell', 'spell tome', 'scroll', 'staff', 'magic', 'arcane', 'conjuration', 'destruction',
                'illusion', 'alteration', 'restoration', 'college'],
            // the college teaches spells: where one is learned says nothing of what it does
            'ignore_knowledge_classes' => ['mage', 'scholar', 'college_of_winterhold', 'collegeofwinterhold'],
            'school_weight_with_subject' => 0.35,
            // x a subject found only in the tags (tags also name targets and carriers: a holy
            // weapon's tags say 'vampires'); a subject in the name counts in full
            'tag_subject_weight' => 0.6,
            'default' => $s(['enchanting' => 0.6, 'scholarly' => 0.3], ['mage' => 0.5, 'scholar' => 0.3]),
            'schools' => [
                'destruction' => ['keywords' => ['destruction'],
                    'facets' => ['combat' => 0.7, 'danger' => 0.5, 'enchanting' => 0.3], 'archetypes' => ['mage' => 0.9]],
                'conjuration' => ['keywords' => ['conjuration', 'conjure', 'summon', 'summoning'],
                    // binding what comes through: danger and the craft, a trace of learning
                    'facets' => ['danger' => 0.5, 'enchanting' => 0.5, 'dark' => 0.3, 'scholarly' => 0.15], 'archetypes' => ['mage' => 0.9, 'scholar' => 0.3]],
                'alteration'  => ['keywords' => ['alteration'],
                    'facets' => ['enchanting' => 0.5, 'scholarly' => 0.5], 'archetypes' => ['mage' => 0.7, 'scholar' => 0.5]],
                'illusion'    => ['keywords' => ['illusion'],
                    'facets' => ['enchanting' => 0.4, 'social' => 0.4, 'scholarly' => 0.2], 'archetypes' => ['mage' => 0.6, 'bard' => 0.4]],
                'restoration' => ['keywords' => ['restoration'],
                    'facets' => ['spiritual' => 0.6, 'sacred' => 0.3, 'enchanting' => 0.2], 'archetypes' => ['healer' => 0.9]],
                // Dragon shouts (the Voice): Thu'um is a warrior's and a pilgrim's art, not a book's
                'voice'       => ['keywords' => ["thu'um", 'dragon shout', 'shout', 'words of power', 'word of power'],
                    'facets' => ['spiritual' => 0.4, 'combat' => 0.4, 'danger' => 0.3], 'archetypes' => ['warrior' => 0.4]],
            ],
            'subjects' => [
                // --- nature magic: animals, plants, weather, shapeshifting, beast calls (druid)
                'animal' => $s($nature, $druid), 'beast' => $s($beast, ['druid' => 0.8]),
                'wolf' => $s($beast, ['druid' => 0.7]), 'wolves' => $s($beast, ['druid' => 0.7]),
                'bear' => $s($beast, ['druid' => 0.7]), 'sabre cat' => $s($beast, ['druid' => 0.7]),
                'spriggan' => $s(['nature' => 1.0, 'wild' => 0.6, 'spiritual' => 0.3], ['druid' => 1.0]),
                'familiar' => $s(['nature' => 0.8, 'wild' => 0.5], ['druid' => 0.6]),
                'pet' => $s(['nature' => 0.6, 'domestic' => 0.3], ['druid' => 0.4]),
                'nature' => $s(['nature' => 1.0, 'wild' => 0.6], ['druid' => 1.0]),
                'kyne' => $s(['nature' => 0.9, 'spiritual' => 0.5, 'wild' => 0.5], $druid),
                'hircine' => $s(['wild' => 0.8, 'nature' => 0.6, 'danger' => 0.4], ['druid' => 0.6, 'hunter' => 0.5]),
                'plant' => $s($nature, $druid), 'vine' => $s($nature, $druid), 'thorn' => $s($nature + ['danger' => 0.3], $druid),
                'bramble' => $s($nature + ['danger' => 0.3], $druid), 'entangle' => $s($nature, $druid), 'grove' => $s($nature, $druid),
                'weather' => $s($weather, ['druid' => 0.8]), 'storm call' => $s($weather, ['druid' => 0.8]),
                'call storm' => $s($weather, ['druid' => 0.8]), 'call lightning' => $s($weather, ['druid' => 0.8]),
                'clear skies' => $s($weather, ['druid' => 0.8]),
                'shapeshift' => $s($shape, ['druid' => 0.7]), 'shapeshifting' => $s($shape, ['druid' => 0.7]),
                'beast form' => $s($shape, ['druid' => 0.7]), 'werebeast' => $s($shape, ['druid' => 0.7], ['exclusive' => true]),
                'werewolf' => $s($shape, ['druid' => 0.7], ['exclusive' => true]), 'werebear' => $s($shape, ['druid' => 0.7], ['exclusive' => true]),
                'lycanthropy' => $s($shape, ['druid' => 0.7], ['exclusive' => true]),
                'hunter' => $s(['nature' => 0.6, 'combat' => 0.4, 'wild' => 0.4], ['hunter' => 0.8]),
                'huntsman' => $s(['nature' => 0.6, 'combat' => 0.4, 'wild' => 0.4], ['hunter' => 0.8]),
                // --- destruction: elemental harm reads as combat and danger
                'fire' => $s($elemental, ['mage' => 0.8]), 'flame' => $s($elemental, ['mage' => 0.8]),
                'firebolt' => $s($elemental, ['mage' => 0.8]), 'fireball' => $s($elemental, ['mage' => 0.8]),
                'incinerate' => $s($elemental, ['mage' => 0.8]), 'ignite' => $s($elemental, ['mage' => 0.8]),
                'frost' => $s($elemental, ['mage' => 0.8]), 'frostbite' => $s($elemental, ['mage' => 0.8]),
                'ice' => $s($elemental, ['mage' => 0.8]), 'icy' => $s($elemental, ['mage' => 0.8]), 'blizzard' => $s($elemental, ['mage' => 0.8]),
                'shock' => $s($elemental, ['mage' => 0.8]), 'spark' => $s($elemental, ['mage' => 0.8]),
                'lightning' => $s($elemental, ['mage' => 0.8]), 'thunderbolt' => $s($elemental, ['mage' => 0.8]),
                'rune' => $s(['combat' => 0.5, 'danger' => 0.5], ['mage' => 0.6]),
                // --- restoration: the healer's and the priest's craft
                'heal' => $s($heal, ['healer' => 1.0]), 'healing' => $s($heal, ['healer' => 1.0]),
                'cure' => $s($heal, ['healer' => 0.8]), 'cure disease' => $s($heal, ['healer' => 0.8]),
                'ward' => $s(['spiritual' => 0.5, 'sacred' => 0.3, 'combat' => 0.2], ['healer' => 0.6, 'mage' => 0.3]),
                'turn undead' => $s($holy, ['healer' => 0.6]), 'turn lesser undead' => $s($holy, ['healer' => 0.6]),
                'turn greater undead' => $s($holy, ['healer' => 0.6]), 'repel undead' => $s($holy, ['healer' => 0.6]),
                'repel lesser undead' => $s($holy, ['healer' => 0.6]), 'bane of the undead' => $s($holy, ['healer' => 0.6]),
                "vampire's bane" => $s($holy, ['healer' => 0.6]), 'stendarr' => $s($holy, ['healer' => 0.6]),
                'sun fire' => $s($holy, ['healer' => 0.6]), 'sun damage' => $s($holy, ['healer' => 0.6]),
                'light damage' => $s($holy, ['healer' => 0.6]),
                'circle of protection' => $s($holy, ['healer' => 0.6]), 'guardian circle' => $s($holy, ['healer' => 0.6]),
                // --- conjuration: daedra are dangerous learning; the dead are dark; bound arms are combat
                'daedra' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]), 'daedric' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]),
                'dremora' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]), 'atronach' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]),
                'oblivion' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]), 'seeker' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]),
                'banish' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]), 'expel' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]),
                'flame thrall' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]), 'frost thrall' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]),
                'storm thrall' => $s($daedra, ['mage' => 0.9, 'scholar' => 0.3]),
                'bound' => $s(['combat' => 0.6, 'enchanting' => 0.4], ['mage' => 0.6, 'warrior' => 0.2]),
                'reanimate' => $s($necro, ['mage' => 0.7]), 'raise zombie' => $s($necro, ['mage' => 0.7]),
                'zombie' => $s($necro, ['mage' => 0.7]), 'thrall' => $s($necro, ['mage' => 0.7]), 'revenant' => $s($necro, ['mage' => 0.7]),
                'necromancy' => $s($necro, ['mage' => 0.7]), 'necromantic' => $s($necro, ['mage' => 0.7]),
                'corpse' => $s($necro, ['mage' => 0.7]), 'mistman' => $s($necro, ['mage' => 0.7]), 'shade' => $s($necro, ['mage' => 0.7]),
                'ash spawn' => $s($daedra, ['mage' => 0.9]), 'ash guardian' => $s($daedra, ['mage' => 0.9]),
                'soul trap' => $s(['enchanting' => 1.0, 'dark' => 0.3], ['mage' => 0.5, 'scholar' => 0.3]),
                // --- illusion: minds, not books
                'calm' => $s($calm, ['bard' => 0.6, 'mage' => 0.3]), 'pacify' => $s($calm, ['bard' => 0.6, 'mage' => 0.3]),
                'harmony' => $s($calm, ['bard' => 0.6, 'mage' => 0.3]), 'peace' => $s($calm, ['bard' => 0.6, 'mage' => 0.3]),
                'fear' => $s($fear, ['mage' => 0.4, 'bard' => 0.3]), 'rout' => $s($fear, ['mage' => 0.4, 'bard' => 0.3]),
                'hysteria' => $s($fear, ['mage' => 0.4, 'bard' => 0.3]), 'dismay' => $s($fear, ['mage' => 0.4, 'bard' => 0.3]),
                'fury' => $s($frenzy, ['mage' => 0.4]), 'frenzy' => $s($frenzy, ['mage' => 0.4]), 'mayhem' => $s($frenzy, ['mage' => 0.4]),
                'courage' => $s($rally, ['bard' => 0.5, 'warrior' => 0.2]), 'rally' => $s($rally, ['bard' => 0.5, 'warrior' => 0.2]),
                'call to arms' => $s($rally, ['bard' => 0.5, 'warrior' => 0.2]),
                'invisibility' => $s($stealth, ['thief' => 0.7]), 'muffle' => $s($stealth, ['thief' => 0.7]),
                'clairvoyance' => $s($explore, ['mage' => 0.3]),
                // --- alteration: armour of the flesh, light, sight, hands at a distance
                'oakflesh' => $s($flesh, ['mage' => 0.5, 'warrior' => 0.2]), 'stoneflesh' => $s($flesh, ['mage' => 0.5, 'warrior' => 0.2]),
                'ironflesh' => $s($flesh, ['mage' => 0.5, 'warrior' => 0.2]), 'ebonyflesh' => $s($flesh, ['mage' => 0.5, 'warrior' => 0.2]),
                'dragonhide' => $s($flesh, ['mage' => 0.5, 'warrior' => 0.2]),
                'light' => $s($explore, ['mage' => 0.4]), 'candlelight' => $s($explore, ['mage' => 0.4]), 'magelight' => $s($explore, ['mage' => 0.4]),
                'detect' => $s($explore + ['wild' => 0.2], ['mage' => 0.3, 'hunter' => 0.2]),
                'waterbreathing' => $s($explore + ['nature' => 0.3], ['mage' => 0.3]), 'waterwalking' => $s($explore + ['nature' => 0.3], ['mage' => 0.3]),
                'paralyze' => $s(['danger' => 0.5, 'combat' => 0.4], ['mage' => 0.5]), 'paralysis' => $s(['danger' => 0.5, 'combat' => 0.4], ['mage' => 0.5]),
                'armor' => $s($flesh, ['mage' => 0.5, 'warrior' => 0.2]),
                'telekinesis' => $s(['scholarly' => 0.5, 'enchanting' => 0.4], ['mage' => 0.5, 'scholar' => 0.4]),
                'transmute' => $s(['crafting' => 0.6, 'wealth' => 0.5], ['smith' => 0.3, 'mage' => 0.3]),
                // --- potion and enchantment effects (fortify skill X): the effect, not the school
                'fortify' => $s(['alchemy' => 0.5, 'enchanting' => 0.5], ['healer' => 0.3]),
                // --- diseases and vampirism (category spells): what they are, not what carries them
                'disease' => $s($disease, ['healer' => 0.3], ['exclusive' => true]),
                'vampirism' => $s($vampire, []), 'vampire' => $s($vampire, []), 'sanguinare' => $s($vampire, []),
                'vampiric' => $s($vampire, ['mage' => 0.4]),
                // --- shadows: ingredient and effect names that contain a subject word
                'bear claws' => $ignore, 'sabre cat tooth' => $ignore, 'sabre cat eye' => $ignore, 'eye of sabre cat' => $ignore,
                'spriggan sap' => $ignore, 'burnt spriggan wood' => $ignore, 'fire salts' => $ignore, 'frost salts' => $ignore,
                'frost mirriam' => $ignore, 'daedra heart' => $ignore, 'ice wraith teeth' => $ignore, 'wolf pelt' => $ignore, 'light armor' => $ignore,
                'resist fire' => $s(['spiritual' => 0.4, 'alchemy' => 0.3], ['healer' => 0.5]),
                'resist frost' => $s(['spiritual' => 0.4, 'alchemy' => 0.3], ['healer' => 0.5]),
                'resist shock' => $s(['spiritual' => 0.4, 'alchemy' => 0.3], ['healer' => 0.5]),
            ],
        ];
    }

    /**
     * Default config 'thing_appraisal': what an appraisal of a topic or a gift does. The
     * multiplier itself is MDD 1.2's 0.5x .. 2.0x through RelDynFacets::interestMultiplier().
     */
    public static function appraisalDefaults(): array
    {
        return [
            // valence (-1..+1) from which a topic counts as a match: _last_topic_match, and it
            // feeds flirt-in-context (whether it is felt at all is RelDynFacets::feltText's call)
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
            if (!is_array($cfg[$key])) {   // a number setting (knowledge_class_dilution)
                if (is_numeric($value)) {
                    $cfg[$key] = (float) $value;
                } else {
                    error_log("[RelDyn-FACETS] ERROR config facet_classifier.{$key} is not a number; using its default");
                }
                continue;
            }
            if (!is_array($value)) {
                error_log("[RelDyn-FACETS] ERROR config facet_classifier.{$key} is not an object; using its default");
                continue;
            }
            $cfg[$key] = in_array($key, ['embedding', 'build'], true) ? array_replace($cfg[$key], $value) : $value;
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
        unset($shape['embedding']['timeout_s'], $shape['build']);
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
    public static function keywordFacets(string $text, array $table, array $exclude = []): array
    {
        $matched = self::keywordMatches($text, $table);
        foreach ($exclude as $kw) {
            unset($matched[self::nameKey((string) $kw)]);   // after shadowing: 'spell tome' still hides 'tome'
        }
        return self::maxMerge(array_values($matched));
    }

    /**
     * The entries of a keyword table (keyword => array) found in $text, keyed by their name key:
     * whole words, a trailing s/es plural too; a keyword inside a longer matched keyword yields to it.
     */
    public static function keywordMatches(string $text, array $table): array
    {
        $text = self::nameKey($text);
        if ($text === '') {
            return [];
        }
        $matched = [];
        foreach ($table as $kw => $value) {
            $k = self::nameKey((string) $kw);
            if ($k === '' || !is_array($value)) {
                continue;
            }
            if (self::wordOffset($text, $k) !== null) {
                $matched[$k] = $value;
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
        return $matched;
    }

    /** Offset of the first whole-word match of name key $k (plural s/es too) in $text, or null. */
    private static function wordOffset(string $text, string $k): ?int
    {
        $re = '/(?<![\p{L}\p{N}])' . preg_quote($k, '/') . '(?:s|es)?(?![\p{L}\p{N}])/u';
        return preg_match($re, $text, $m, PREG_OFFSET_CAPTURE) === 1 ? (int) $m[0][1] : null;
    }

    // =========================================================================
    // Spells by subject (decisions §10: nature magic is not bookish)
    // =========================================================================

    /**
     * What a spell (or a magic entry) is about: its school and subjects through config
     * facet_classifier.spell_subjects (see spellSubjectDefaults()). Null when it is not magic, or
     * when it is only assumed to be magic ($assumeMagic: a held or cast spell) and nothing in its
     * name is known.
     *
     * An entry is magic when its Oghma category is a magic category, when $assumeMagic, or when
     * it has no category and its NAME carries a magic marker or a school keyword (tags never make
     * an entry magic; an Oghma row in another category is what that category says: the Elder
     * Scrolls books, The Art of War Magic, the Staff of Magnus, a potion of resist magic).
     *   school    the school whose keyword comes first in the name, else first in the tags
     *   subjects  subject keywords in the name (x 1) and in the tags only (x tag_subject_weight);
     *             ignore rows only shadow; an exclusive row (a disease, lycanthropy) silences the
     *             other subjects found only in the tags (the carriers a disease's tags name)
     *   facets    subjects' facets, plus the school's x school_weight_with_subject; no subject:
     *             the school's facets; neither: the default reading (per-facet maximum throughout)
     *   archetypes  the same composition over each row's player archetypes (0..1)
     *   exclusive  an exclusive subject spoke (its tags name carriers, not what it is)
     *
     * @return array|null ['school' => ?string, 'subjects' => string[], 'facets' => facet => 0..1,
     *                     'archetypes' => archetype => 0..1, 'exclusive' => bool]
     */
    public static function spellReading(string $name, string $tags = '', ?string $category = null, bool $assumeMagic = false, ?array $cfg = null): ?array
    {
        $cfg = $cfg ?? self::config();
        $sc = (array) ($cfg['spell_subjects'] ?? []);
        $nameText = self::nameKey(str_replace(',', ' | ', $name));
        $tagText = self::nameKey(str_replace(',', ' | ', $tags));
        if ($nameText === '' && $tagText === '') {
            return null;
        }
        $schools = (array) ($sc['schools'] ?? []);
        $schoolInName = self::firstSchool($nameText, $schools);
        $school = $schoolInName ?? self::firstSchool($tagText, $schools);
        $categories = array_map(fn($c) => strtolower(trim((string) $c)), (array) ($sc['categories'] ?? []));
        $category = $category !== null ? strtolower(trim($category)) : '';
        $categoryMagic = $category !== '' && in_array($category, $categories, true);
        if ($category !== '' && !$categoryMagic) {
            return null;   // an Oghma row of another category: a book, an artifact, an item
        }
        $marked = self::keywordMatches($nameText, array_fill_keys((array) ($sc['magic_markers'] ?? []), [])) !== [];
        if (!$categoryMagic && !$assumeMagic && !$marked && $schoolInName === null) {
            return null;
        }

        $table = (array) ($sc['subjects'] ?? []);
        $weighted = [];   // keyword => [row, weight]
        foreach (self::keywordMatches($nameText, $table) as $k => $row) {
            $weighted[$k] = [$row, 1.0];
        }
        $tagWeight = max(0.0, min(1.0, (float) ($sc['tag_subject_weight'] ?? 1.0)));
        foreach (self::keywordMatches($tagText, $table) as $k => $row) {
            $weighted[$k] = $weighted[$k] ?? [$row, $tagWeight];
        }
        $weighted = array_filter($weighted, fn($m) => empty($m[0]['ignore']));
        $exclusive = array_filter($weighted, fn($m) => !empty($m[0]['exclusive']));
        if ($exclusive !== []) {
            // the carriers a disease's tags name are silenced; what its NAME says still counts
            $inName = array_filter($weighted, fn($m) => $m[1] >= 1.0);
            $weighted = $exclusive + $inName;
        }

        $facets = [];
        $archetypes = [];
        foreach ($weighted as [$row, $w]) {
            $facets[] = self::scaled((array) ($row['facets'] ?? []), $w);
            $archetypes[] = self::scaled((array) ($row['archetypes'] ?? []), $w);
        }
        $schoolRow = $school !== null ? (array) $schools[$school] : null;
        if ($weighted !== [] && $schoolRow !== null) {
            $k = max(0.0, min(1.0, (float) ($sc['school_weight_with_subject'] ?? 0.0)));
            $facets[] = self::scaled((array) ($schoolRow['facets'] ?? []), $k);
            $archetypes[] = self::scaled((array) ($schoolRow['archetypes'] ?? []), $k);
        } elseif ($weighted === [] && $schoolRow !== null) {
            $facets[] = (array) ($schoolRow['facets'] ?? []);
            $archetypes[] = (array) ($schoolRow['archetypes'] ?? []);
        } elseif ($weighted === []) {
            if (!$categoryMagic && !$marked) {
                return null;   // assumed magic, nothing known about it
            }
            $facets[] = (array) ($sc['default']['facets'] ?? []);
            $archetypes[] = (array) ($sc['default']['archetypes'] ?? []);
        }
        return [
            'school' => $school,
            'subjects' => array_keys($weighted),
            'facets' => self::maxMerge($facets),
            'archetypes' => self::mergeWeights($archetypes),
            'exclusive' => $exclusive !== [],
        ];
    }

    /** The school whose keyword comes first in $text (ties: table order), or null. */
    private static function firstSchool(string $text, array $schools): ?string
    {
        if ($text === '') {
            return null;
        }
        $best = null;
        $bestAt = PHP_INT_MAX;
        foreach ($schools as $school => $row) {
            foreach ((array) ($row['keywords'] ?? []) as $kw) {
                $k = self::nameKey((string) $kw);
                $at = $k === '' ? null : self::wordOffset($text, $k);
                if ($at !== null && $at < $bestAt) {
                    $bestAt = $at;
                    $best = (string) $school;
                }
            }
        }
        return $best;
    }

    /** Per-key maximum of several key => 0..1 maps (archetype weights), clamped, zeros dropped. */
    private static function mergeWeights(array $maps): array
    {
        $out = [];
        foreach ($maps as $map) {
            foreach ((array) $map as $key => $w) {
                if (!is_numeric($w)) {
                    continue;
                }
                $w = round(max(0.0, min(1.0, (float) $w)), 3);
                if ($w > 0.0) {
                    $out[(string) $key] = max($out[(string) $key] ?? 0.0, $w);
                }
            }
        }
        arsort($out);
        return $out;
    }

    /**
     * The deterministic prior of one Oghma row: knowledge_class (an audience: the sole-class
     * table, then each class row diluted by the number of such classes, see
     * knowledge_class_prior), category, the words of its name (topic, aliases) and of its tags
     * through the tables, each source x its prior_weights entry; per-facet maximum over the sources.
     * A magic entry (spellReading) takes its spell reading (x the name weight) in place of the
     * category row, drops spell_subjects.ignore_knowledge_classes and the replaced_keywords; one
     * read by an exclusive subject (a disease) also skips the tag keywords of its tags (they
     * name its carriers: rats, bears, wolves).
     */
    public static function priorFacets(array $row, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $w = array_replace(['knowledge_class' => 1.0, 'category' => 1.0, 'name' => 1.0, 'tags' => 1.0], (array) ($cfg['prior_weights'] ?? []));
        $name = str_replace('_', ' ', (string) ($row['topic'] ?? '')) . ' | ' . str_replace(',', ' | ', (string) ($row['aliases'] ?? ''));
        $tags = str_replace(',', ' | ', (string) ($row['tags'] ?? ''));
        $cat = strtolower(trim((string) ($row['category'] ?? '')));
        // Decisions §10: a magic entry reads by its school and subject; that reading replaces
        // the generic "magic" category prior, the mage audience and the generic magic keywords.
        $spell = self::spellReading($name, $tags, $cat, false, $cfg);
        $sc = (array) ($cfg['spell_subjects'] ?? []);
        $replaced = $spell !== null ? (array) ($sc['replaced_keywords'] ?? []) : [];
        $ignoredClasses = $spell !== null ? array_map('strtolower', (array) ($sc['ignore_knowledge_classes'] ?? [])) : [];

        $parts = [];
        $classes = array_values(array_unique(array_filter(
            array_map(fn($t) => strtolower(trim($t)), explode(',', (string) ($row['knowledge_class'] ?? ''))),
            fn($t) => $t !== ''
        )));
        $sole = (array) ($cfg['knowledge_class_sole_prior'] ?? []);
        if (count($classes) === 1 && isset($sole[$classes[0]]) && !in_array($classes[0], $ignoredClasses, true)) {
            $parts[] = self::scaled((array) $sole[$classes[0]], (float) $w['knowledge_class']);
        }
        $table = (array) ($cfg['knowledge_class_prior'] ?? []);
        $mapped = array_values(array_filter($classes, fn($t) => isset($table[$t]) && !in_array($t, $ignoredClasses, true)));
        if ($mapped !== []) {
            $k = (float) $w['knowledge_class'] / pow(count($mapped), max(0.0, (float) ($cfg['knowledge_class_dilution'] ?? 0.0)));
            foreach ($mapped as $t) {
                $parts[] = self::scaled((array) $table[$t], $k);
            }
        }
        if ($spell !== null) {
            $parts[] = self::scaled($spell['facets'], (float) $w['name']);
        } elseif ($cat !== '' && isset($cfg['category_prior'][$cat])) {
            $parts[] = self::scaled((array) $cfg['category_prior'][$cat], (float) $w['category']);
        }
        $parts[] = self::scaled(self::keywordFacets($name, (array) $cfg['tag_keywords'], $replaced), (float) $w['name']);
        if (empty($spell['exclusive'])) {
            $parts[] = self::scaled(self::keywordFacets($tags, (array) $cfg['tag_keywords'], $replaced), (float) $w['tags']);
        }
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
        $db->execQuery('CREATE TABLE IF NOT EXISTS ' . self::BUILD_STATE_TABLE . ' (key text PRIMARY KEY, value text)');
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
        // One build at a time (the background launch and the CLI tool): a session advisory lock.
        $lock = "hashtext('" . self::TABLE . "')";
        $got = $db->fetchOne("SELECT pg_try_advisory_lock({$lock}) AS got");
        if (!in_array($got['got'] ?? null, ['t', true, 1, '1'], true)) {
            error_log('[RelDyn-FACETS] another Oghma facet build is running; this one is skipped');
            return ['method' => null, 'skipped' => 'locked'];
        }
        try {
            return self::buildUnlocked($db, $opts);
        } finally {
            $db->fetchOne("SELECT pg_advisory_unlock({$lock}) AS released");
        }
    }

    private static function buildUnlocked($db, array $opts): array
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

    // =========================================================================
    // Background build (oghma-facet-classifier review 2026-09-24: only a CLI tool ran it)
    // =========================================================================

    /** Replace the background process spawn (tests); null restores the real one. */
    public static function setBuildLauncher(?callable $fn): void
    {
        self::$buildLauncher = $fn;
    }

    private static function buildState($db, string $key): ?string
    {
        if (!self::tableExists($db, self::BUILD_STATE_TABLE)) return null;
        $row = $db->fetchOne('SELECT value FROM ' . self::BUILD_STATE_TABLE . ' WHERE key = $1', [$key]);
        return isset($row['value']) ? (string) $row['value'] : null;
    }

    /**
     * Why the stored facets need a build now, or null: 'missing' (no table), 'rows' (Oghma
     * entries added or removed), 'config' (a row built from other mapping tables / anchors),
     * 'embedding' (rows only have the prior, and the last launch is embedding_retry_game_hours
     * of game time ago).
     */
    public static function buildNeeded($db, float $now, ?float $lastLaunch = null): ?string
    {
        if (!self::tableExists($db, self::TABLE)) return 'missing';
        $n = $db->fetchOne("SELECT (SELECT count(*) FROM oghma WHERE trim(topic) <> '') AS oghma, (SELECT count(*) FROM " . self::TABLE . ') AS stored');
        if (intval($n['oghma'] ?? 0) !== intval($n['stored'] ?? 0)) return 'rows';
        $cfg = self::config();
        $prior = self::version($cfg, 'prior');
        $current = [$prior, self::version($cfg, 'embedding'), self::version($cfg, 'embedding_raw')];
        $versions = array_column((array) $db->fetchAll('SELECT DISTINCT version FROM ' . self::TABLE), 'version');
        if (array_diff($versions, $current) !== []) return 'config';
        if (in_array($prior, $versions, true)) {
            $hours = floatval($cfg['build']['embedding_retry_game_hours']);
            if ($lastLaunch === null || ($now - $lastLaunch) >= $hours * RelationshipDynamics::GAMETS_PER_DAY / 24.0) return 'embedding';
        }
        return null;
    }

    /**
     * The postrequest hook's check: when the stored facets need a build (buildNeeded), start
     * it in the background (tools/build_oghma_facets.php, detached) and stamp the launch.
     * Never twice within build.min_gap_game_hours of game time (one may still be running;
     * build() itself also locks). Returns true when it launched one.
     */
    public static function maybeLaunchBuild($db, float $now): bool
    {
        $b = self::config()['build'];
        if (!$db || empty($b['auto']) || $now <= 0) return false;
        try {
            if (!self::tableExists($db, 'oghma')) return false;
            $last = self::buildState($db, 'last_launch_gamets');
            $last = is_numeric($last) ? floatval($last) : null;
            if ($last !== null && $now > $last && ($now - $last) < floatval($b['min_gap_game_hours']) * RelationshipDynamics::GAMETS_PER_DAY / 24.0) {
                return false;
            }
            $reason = self::buildNeeded($db, $now, $last);
            if ($reason === null) return false;
            self::ensureTables($db);
            $db->fetchOne('INSERT INTO ' . self::BUILD_STATE_TABLE . ' (key, value) VALUES ($1, $2)'
                . ' ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value RETURNING key', ['last_launch_gamets', json_encode($now)]);   // exact round trip
        } catch (\Throwable $e) {
            RelationshipDynamics::logError('RelDynFacetClassifier::maybeLaunchBuild', $e);
            return false;
        }
        RelationshipDynamics::log("[FACETS] Oghma facet build launched in the background ({$reason})");
        return self::launchBuild($reason);
    }

    /** Start tools/build_oghma_facets.php detached (as RelDynEval::launchWorker starts its worker). */
    private static function launchBuild(string $reason): bool
    {
        if (self::$buildLauncher !== null) {
            (self::$buildLauncher)($reason);
            return true;
        }
        if (getenv('PHPUNIT_TEST')) {
            RelationshipDynamics::log('Oghma facet build not launched under PHPUNIT_TEST');
            return false;
        }
        $enginePath = $GLOBALS['ENGINE_PATH'] ?? (dirname(__DIR__, 2) . '/');
        $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
        $log = rtrim($enginePath, '/') . '/log/reldyn_oghma_facets.log';
        $cmd = (is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '')
            . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/tools/build_oghma_facets.php')
            . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!is_resource($proc)) {
            error_log('[RelDyn-FACETS] ERROR launchBuild: proc_open failed');
            return false;
        }
        proc_close($proc);   // returns once the shell has backgrounded the build
        return true;
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
        if ($kind === 'activity') {
            return [];
        }
        // Decisions §10: a spell tome, a scroll, a staff or a spell named as a topic reads by
        // its subject; the generic magic words it replaces drop out of the keyword reading.
        $spell = in_array($kind, ['item', 'topic'], true) ? self::spellReading($name, '', null, false, $cfg) : null;
        if ($spell === null) {
            return self::keywordFacets($name, (array) $cfg[$table]);
        }
        $replaced = (array) ($cfg['spell_subjects']['replaced_keywords'] ?? []);
        return self::maxMerge([self::keywordFacets($name, (array) $cfg[$table], $replaced), $spell['facets']]);
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
        $out['bonus'] = RelDynFacets::interestMultiplier($v);   // MDD 1.2 0.5x..2.0x, the one mapping
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
            'mult' => RelDynFacets::interestMultiplier((float) $a['valence']),   // MDD 1.2 0.5x..2.0x
            'appraisal' => $a,
            'felt' => RelDynFacets::feltText($npcName, $a, 'item', $itemName),
        ];
    }
}
