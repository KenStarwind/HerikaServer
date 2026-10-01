<?php
/**
 * Relationship Dynamics — plain-word labels and hints for the settings hub (roadmap settings-page).
 *
 * The hub's form is generated from RelationshipDynamics::defaultConfig(): thousands of leaves, most of
 * them keys of a table (a class, a keyword, a trait code, "+" / "-"). A bare key means nothing out of
 * context, so every field gets:
 *
 *   a label   what it is in words: abbreviations spelled out (mult, poi, mf), trait codes by name
 *             (E = expressiveness), "+" / "-" as positive / negative reading, "stat:Spells Learned" as
 *             a game stat; where a short label would repeat inside its section (Beauty x17) it is
 *             qualified by the keys above it until it is unique ("Floors › Beauty"); a switch is named
 *             by what it switches ("Memory translation › Commit (switch)").
 *   a hint    one or two sentences: an explicit one (HINTS, RelDynSettings::LABELS) where a person needs
 *             one to decide, a family rule for the big keyword / weight tables (PATTERNS), else what the
 *             section does (SECTION_HELP) with what kind of value this is and its unit.
 *
 * Pure text: nothing here reads or writes state. RelDynSettings::fields() calls it once per process.
 */

final class RelDynSettingsText
{
    /** Whole words spelled out in a label (lower case key => replacement). */
    const WORDS = [
        'mult' => 'multiplier', 'min' => 'minimum', 'max' => 'maximum', 'pct' => 'percent', 'poi' => 'place of interest',
        'hwm' => 'high-water mark', 'll' => 'love language', 'npc' => 'NPC', 'npcs' => 'NPCs', 'aff' => 'affinity',
        'cfg' => 'config', 'llm' => 'LLM', 'tts' => 'TTS', 'ssc' => 'social sensitivity curve', 'mf' => 'M/F coordinates',
        'conf' => 'confidence', 'attr' => 'attraction', 'dim' => 'dimension', 'dims' => 'dimensions', 'src' => 'source',
        'k' => 'rate (k)', 'log2' => 'log2',
    ];

    /** Keys that stand for something else (exact key => words). */
    const SEGMENTS = [
        '+' => 'Positive', '-' => 'Negative',
        '+M/+F' => 'M coordinate up, F coordinate up', '+M/-F' => 'M coordinate up, F coordinate down',
        '-M/+F' => 'M coordinate down, F coordinate up', '-M/-F' => 'M coordinate down, F coordinate down',
        'ex' => 'Ex-partner',
    ];

    /** Prefix before a colon in a key (stat:Murders) => words. */
    const PREFIXES = [
        'stat' => 'Game stat', 'questline' => 'Questline', 'eventlog' => 'Event count', 'ledger' => 'Ledger',
        'form' => 'Form', 'journal' => 'Journal', 'kills' => 'Kills', 'survival' => 'Survival',
    ];

    /** What each top-level section is, in a sentence (the hub shows it above the section's tables). */
    const SECTION_HELP = [
        'felt_steering' => 'How her feelings are put into words for the prompt: how many lines each bond depth gets, how strong a feeling must be to be spoken, and the wording of each. Never numbers.',
        'attraction' => 'The Attraction Matrix: what she finds attractive in the player (looks, strength, status, competence), how demanding she is, and how that gates romance and scales passion.',
        'preference_jealousy_mult' => 'How much more or less jealous she is, by what kind of relationship she wants.',
        'quests' => 'How quests touch her: the duty override that softens negative reactions during obligations, what counts as hostile, and which quest stages change a life.',
        'intrinsic_goals' => 'Goals she forms on her own from her backstory and how the bond is going: how many at once, how they are chosen, when they renew.',
        'reputation' => 'What the player is known for (fame, infamy, wealth, the named fames) and how word of it reaches people who have not met them.',
        'memory_translation' => 'How the relationship\'s numbers become memory-like words, and the anchor moments (first meeting, first gift...) she keeps.',
        'player_mirror' => 'The player\'s behaviour profile built from how NPCs experience them (the Player profile page): its window, evidence, character mode, and whether it reaches prompts.',
        'prompt_gating' => 'Who knows the player: what strangers may know of the name, the story and rumours, by bond depth.',
        'item_modifiers' => 'How items move her dimensions: which dimensions an item is appraised on, and how many event-log rows are read per request.',
        'baseline_drift' => 'How her resting point (baseline) slowly moves when behaviour keeps pulling the same way.',
        'per_bond_display' => 'How the depth and type of a bond colour the values she shows toward the player (display only, never stored).',
        'self_confidence' => 'When high self-confidence with low maturity reads as arrogance.',
        'social_masking' => 'Who wears a social mask (maturity, attachment style, status trait) and how much she must trust someone to drop it.',
        'charisma' => 'How the player\'s style (rock, catalyst, charmer) is recognised from graded exchanges.',
        'autonomy' => 'The autonomy override: how likely she is to refuse a command (from distrust, disrespect, resentment...), and which actions she may deny.',
        'director_goals' => 'How long a goal assigned by the Director stays active.',
        'temperament_autogen' => 'The tables that guess her temperament, maturity type and trait tags from class, faction, skills, voice, race and the words of her bio.',
        'traits' => 'How her ten trait values are assigned (read from her bio, or from labels) and how far the read reaches past the presets.',
        'trait_reader' => 'The background reader that reads her bio once with a model to set her traits: connector, jobs per run, limits.',
        'attachment' => 'Attachment on two axes (anxiety and avoidance): how they are derived, how experience moves them, the thresholds between styles, and the words used.',
        'facet_preferences' => 'How her likes and dislikes (the interests) are derived from class, faction, skills, archetype, temperament and traits.',
        'facet_appraisal' => 'How she reacts to places and things she meets: the felt reactions, comfort and mood changes, weather feeding, discomfort in places she dislikes.',
        'walkaway_sever_types' => 'What a bond type becomes when a permanent walkaway severs it.',
        'passion_absence_attachment_mult' => 'How attachment style changes how fast passion fades while apart.',
        'passion_dynamics' => 'Passion\'s floor and spike, the desire loop and derived warmth.',
        'bleedout_response' => 'How she reacts when the player falls in combat: rage against panic, humiliation, shame.',
        'neglect_bond_types' => 'Per kind of bond: how many game days without contact before neglect starts, and how fast resentment grows after that.',
        'neglect_severity' => 'How hard neglect and the fade of warmth hit her, from codependence, maturity and pride.',
        'bond_break' => 'What happens on return from an absence long enough to break the bond: resentment, lower comfort and trust, and how she shows it.',
        'affinity_rot' => 'Core affinity bleeding away in a romance with open conflict or low passion and no positive contact.',
        'affinity_tag_love_language' => 'Which love language each kind of evaluated event counts as.',
        'jealousy_bystander_commitment' => 'How much more jealous bystanders get, by their bond type, when the player is intimate with someone else.',
        'jealousy_bystander' => 'Who the rival is and who the bystander is: how much more it stings when the rival is close to the player (threat, from her affinity, bond type and passion), and how maturity softens it, by the same curve that blunts a mature NPC\'s hurt, never to nothing.',
        'power_gap_core_types' => 'How little she can leave, by bond type (servant, fanatical, indebted, fearful).',
        'place_facets' => 'How places (by tag, name, inside or outside, time of day, weather) map onto the interests.',
        'physical_states' => 'When the player reads as injured, which states (hunger, rest, dirt, a fire) have no game signal and stay inert, and the trust an NPC earns by healing the player.',
        'environment_facet_effects' => 'What certain kinds of place (danger, dark) do to anyone\'s dimensions.',
        'environment_time_effects' => 'What the hour (dawn, dusk...) does to anyone\'s dimensions.',
        'facet_classifier' => 'The tables that sort places, things, creatures, activities and spells into interests: keywords, priors and the text-matching settings.',
        'thing_appraisal' => 'How topics and gifts are appraised: the interest multiplier range and the match threshold.',
        'player_profile' => 'How the player\'s archetype and pillars (looks, strength, status, competence) are read from the game\'s player data.',
        'fulfillment' => 'What she needs from the relationship and how well it has covered that: needs, deliveries, decay, bands, the mature boundary.',
        'concern' => 'Protective concern: how risk is appraised, how fast concern builds and fades, and how she voices it.',
        'pullback' => 'Letting in and pulling back: how far she has let the player in (comfort and trust), and the temporary closing off when the weather turns inclement and she feels unfulfilled: what presses on her, how maturity and guard change the threshold, how she is met, and how she shows it.',
        'keeping' => 'The fear of losing the relationship: what there is to lose, who she is (attachment, insecurity, possessiveness), what looks like losing it, how fast it builds and eases, what it costs the bond, and how she shows it.',
        'consent' => 'Whether intimacy happens at all, the decision Sharmat defers to: what the bond wants, what weighs on it (conflict, the ick, withdrawal, pulling back, not being let in, fear), the bar this NPC needs, and who gives in anyway.',
        'resentment_arc' => 'Resentment\'s turning points: when she confronts the player, resentment toward herself, guilt bleeding into other bonds.',
        'intimacy_need' => 'How much physical and emotional closeness she needs (from her traits), and what deprivation does.',
        'impulse' => 'The want layer: short-term urges (romantic, protective, social, survival, curiosity), how they decay, and how she acts on them.',
        'creatures' => 'Vampire and werewolf mood changes: detection, the moon, night and day, the return from beast form.',
        'combat' => 'Combat as bonding: witness, shared and danger multipliers, kill streaks, rescue. There is no flat cost for a defeat: her own fall in a fight (the bleedout response) drains or fires her by who she is.',
        'governors' => 'The passion floor and ceiling of each bond tier, and the attracted NPC\'s raise.',
        'exclusivity' => 'The natural pull toward the player (drive, disposition, title), how suitors change it, and the words between NPCs.',
        'romance_promotion' => 'How a bond climbs the romance ladder: moments, confession, momentum, and the handoff to Sharmat.',
        'save_load' => 'What survives loading an earlier save: relationship state, gold ledger checkpoints.',
        'diary_reflection' => 'Her reflection on core\'s diary: how deep it goes, what each verdict changes, the moments it keeps.',
        'poll' => 'What each core poll runs: the play heartbeat, the save-load check, creature forms.',
        'protocols' => 'Divine intervention, grief and the widow\'s lock, the ick, and the parasite check.',
        'substances' => 'Her own drinking and addiction: drunk levels, tolerance, craving, withdrawal, recovery.',
        'mood_axes' => 'The M and F coordinates, and arousal and valence as live state: how they are derived, settle and are fed.',
        'post_intimacy' => 'Aftermath states: afterglow, the sober morning (uncertainty after a drunken night, guilt, distance), and their words.',
        'gift_delta' => 'How a gift moves affinity: the base points, love language, and the stolen and re-gifted cases.',
        'cascade' => 'The cascading affinity network: who hears of what the player did to an NPC (how strong her bond to that NPC must be, enemies inverted), what counts as a defining moment, how long the news takes to reach someone who was not there (by hold distance and bond), and the felt line she says when she hears.',
        'npc_npc_facts' => 'Which personality facts the NPC-to-NPC evaluation of core is told, by how well the player knows each NPC: where the tiers start, how many trait words, how much of her speech style.',
    ];

    /** Explicit hints (dotted path => sentence). Every switch has one; the plain top-level settings have one. */
    const HINTS = [
        // ---- switches ---------------------------------------------------
        'attraction.standards.enabled' => 'Her standards depend on who she is (how open, how mature, how proud): a demanding NPC wants more of the player before she warms. Off: every NPC uses the same flat floor.',
        'attraction.spike_prereq.enabled' => 'A passion moment (something flirty landing) skips the slow uphill only when she already finds the player very attractive, or when the player stands well above her in standing and she is attracted and values it. Off: every moment skips it, as before.',
        'attraction.interest.enabled' => 'Her interest in the player counts whether or not she shows it: a drawn but shy NPC is still interested for the Ick and the like, and low self-confidence needs a deeper bond before her felt text lets her say it. Off: no shyness gating, and no hidden interest.',
        'fulfillment.shared_fight.enabled' => 'Fighting beside the player counts as time together for the NPC\'s needs, by how much they like fighting (combat, adventure, danger tastes): Aela nearly fully, a scholar a sliver. Off: a fight feeds only their own facet needs, and is neither contact nor a missed wish.',
        'fulfillment.shared_fight.contact' => 'A fight beside the player is contact for the neglect and absence rules (the neglect counts from it, the affinity decay does not run through it) and a day the two were together. Off: only the player\'s own words are contact.',
        'fulfillment.shared_fight.contact_window_game_hours' => 'How long before the end of a fight the two were together, for the affinity decay (game hours).',
        'fulfillment.shared_fight.unmet.units' => 'For an NPC who does not enjoy fighting: the most (delivery units) one fight takes off the things they do enjoy ("I wish it was something I enjoy"), at full dislike.',
        'fulfillment.shared_fight.unmet.from' => 'How far under zero the NPC\'s liking of a fight (-1..+1) must be before it takes anything: a shrug is not a wish.',
        'fulfillment.shared_fight.unmet.axes' => 'How many of the NPC\'s enjoyed things (strongest first) a disliked fight takes its units from, shared by weight.',
        'keeping.response.withdraw_at' => 'How far the NPC\'s avoidance must outrun their anxiety (attachment axes, 0..1) before the fear of losing the player makes them withdraw, quiet and distant, instead of cling. A people-pleaser appeases whatever their axes.',
        'keeping.response.conflict.enabled' => 'An immature NPC whose way is control may start a conflict with the player at the worst of the fear of losing them (a people-pleaser appeases instead, one who withdraws goes quiet). Off: it stays in the NPC\'s words. Never a refused command.',
        'keeping.response.conflict.hold_game_hours' => 'How long the fear must have held at its worst (the controlling band) before such an NPC starts a conflict (game hours).',
        'keeping.response.conflict.cooldown_game_days' => 'The shortest time between two conflicts the fear starts (game days).',
        'keeping.response_text' => 'What the NPC does about the fear where it is not the maturity / trait expression: appease (a people-pleaser), withdraw (voiced if mature, raw otherwise), conflict. Behaviour only, no numbers.',
        'consent.enabled' => 'RelDyn decides whether intimacy happens at all and publishes the answer for Sharmat, which defers to it. Closed for an asexual or aromantic NPC, a friendzone and a walkaway; otherwise by what the NPC wants and what weighs on it, by who they are. Off: no decision is published and Sharmat keeps its own consent.',
        'consent.appeasement.enabled' => 'An NPC with little confidence and maturity, anxious attachment and a deep fear of losing the player may say yes when they should not. Off: every NPC who falls short of their bar says no.',
        'keeping.enabled' => 'She fears losing the player and acts to keep the relationship, by who she is (attachment, insecurity, possessiveness): a secure NPC barely, a toxic one possessive and controlling even to the bond\'s detriment. Off: no such fear, and what it held down is lifted.',
        'attraction.respect_mult_enabled' => 'How fast the player earns her respect scales with their standing in her eyes: a legend earns it up to twice as fast, a nobody at half speed (losses are not scaled). Off: respect gains come at the raw rate.',
        'intrinsic_goals.enabled' => 'She forms goals of her own (bond seeking, purpose, mastery, safety, independence, revenge) from her backstory and how the bond is going.',
        'memory_translation.enabled' => 'Turns the relationship\'s numbers into memory-like words for her prompt.',
        'memory_translation.commit.enabled' => 'Also writes the memory wrapper and anchor notes into core\'s memory table. Ships off: it is a new write into a core table.',
        'memory_translation.anchors.enabled' => 'Keeps the anchor moments (first meeting, first gift, first fight beside the player, first rescue, first intimacy) as memories she can return to.',
        'memory_translation.anchors.revisit.enabled' => 'Lets an anchor moment come back in conversation now and then (with a cooldown); it can warm her passion a little.',
        'player_mirror.enabled' => 'Builds the player\'s behaviour profile from how NPCs experience them (the Player profile page). Off: nothing new is observed.',
        'player_mirror.reputation.enabled' => 'Lets the profile set a stranger\'s first impression: new NPCs start with a little trust or distrust from how the player has treated others.',
        'player_mirror.prompt.enabled' => 'NPCs sense the player\'s profile: the most telling bands reach their prompts as one felt line, in words. Ships off (opt-in); the Player profile page has the same switch.',
        'trait_reader.reingest.enabled' => 'Every ~90 game days, or after a milestone (romance, bond break, betrayal, marriage), checks whether the live bio of an NPC changed (the dynamic profile of CHIM rewrites bios). Only a changed bio is read again, in the same background queue, and the new read moves the traits by an amount set by who the NPC is: set-in-their-ways NPCs move less, nobody is immune. Hand-set vectors and editor presets are exempt. Off: no check, no re-read.',
        'trait_reader.reingest.every_game_days' => 'Game days between bio checks (a milestone checks sooner, never within min_gap_days of the last check).',
        'trait_reader.enabled' => 'Reads each NPC\'s bio once with a model to set her traits (a background queue that runs after the evaluation). Off: no bio is read.',
        'attachment.drift.enabled' => 'Lived experience slowly moves her attachment axes (earned security, and the slingshot back).',
        'passion_dynamics.spike.enabled' => 'Passion spikes: fast, event-driven passion on top of the slowly earned floor. Off: only the floor.',
        'passion_dynamics.derived_warmth_enabled' => 'Warmth is derived from passion and comfort instead of being stored on its own.',
        'bond_break.enabled' => 'On return from an absence long enough to break the bond, she shows it (resentment, lower comfort and trust, in her own way). Off: absence only decays the bond.',
        'affinity_rot.enabled' => 'In a romance with an open conflict or low passion, affinity bleeds away when no positive interaction comes for a while.',
        'fulfillment.enabled' => 'She has needs from the relationship (from her interests, love languages, traits and intimacy need) and tracks how well the player covers them.',
        'concern.enabled' => 'Protective concern for the player: appraising risk, building concern and voicing it.',
        'felt_steering.bridge.guarded_let_in_max' => 'Let-in (sqrt of comfort times trust, points 0..100) at or below which a strong pull still reads as "drawn but guarded". Used while the pull-back section is on; guarded_warmth_max is the old reading from passion-derived warmth.',
        'felt_steering.bridge.closed_warmth_max' => 'The old "cares but closed" rule: derived warmth (passion x comfort) at or below this at friend and above, for good. Used only when the pull-back section is off; with it on, closed means she has not let the player in yet (pullback.let_in.low).',
        'pullback.enabled' => 'She can pull back for a while when the weather is inclement and she feels unfulfilled, even at a high tier, and says so by who she is (maturity, traits, attachment). Off: the old rule, a bond with no passion reads closed for good (derived warmth under the closed limit).',
        'pullback.let_in.low' => 'Let-in (sqrt of comfort times trust, points 0..100) below which she has not let the player in yet. Below it the line reads "not let in yet" at any tier; at or above it a bad stretch can make her pull back.',
        'pullback.weights.weather' => 'How much the internal weather (and the gravity it has built up) presses toward pulling back at full, before her mood gain.',
        'pullback.weights.deficit' => 'How much unmet needs (the fulfillment deficit) press toward pulling back at full.',
        'pullback.weights.grievance' => 'How much unresolved resentment or an open conflict presses toward pulling back at full.',
        'pullback.weights.aftermath' => 'How much the morning after intimacy presses toward pulling back at the full push, for an NPC who fears the closeness (see the aftermath settings). Above 1 the NPC half as fearful already presses in full.',
        'pullback.aftermath.enabled' => 'After intimacy an NPC who fears closeness (anxious and avoidant at once; the avoidant part way, the anxious a little) pulls back for a while, by how fearful they are: distance, not shame, fading like any pull-back. Off: no such push.',
        'pullback.aftermath.fearful' => 'How fearful of closeness each attachment corner is (0..1): the NPC reads these blended at their own two axes, so a half-fearful NPC gets half. Secure is small, never zero.',
        'pullback.aftermath.push' => 'The pull-back input (0..1) at full fearfulness, the morning after.',
        'pullback.aftermath.hold_game_hours' => 'How long the push holds after the last scene request of an encounter (game hours) before it starts to fade.',
        'pullback.aftermath.fade_game_hours' => 'How long it then takes to fall to nothing (game hours).',
        'pullback.aftermath.met_relief' => 'Share of what is left of the push that a reassuring exchange takes off (the NPC being met), pulled back or not.',
        'pullback.weather_push' => 'How hard each weather pushes, 0 (none) to 1 (full).',
        'pullback.gravity.share' => 'Share (0..1) of the weather input that is the gravity it has built up, which lingers after the weather clears.',
        'pullback.gravity.valence_full' => 'The held mood pull (valence points, downward) that counts as full gravity.',
        'pullback.grievance.resentment_from' => 'Resentment points from which it starts to press.',
        'pullback.grievance.resentment_full' => 'Resentment points at which it presses in full.',
        'pullback.grievance.conflict' => 'How hard an open conflict presses (0..1) before any repair.',
        'pullback.grievance.repair_relief' => 'Share of an open conflict taken off by each positive exchange since it opened.',
        'pullback.mood_gain.immature' => 'Multiplier on the weather input for an immature NPC: she lets her mood decide.',
        'pullback.mood_gain.mature' => 'Multiplier on the weather input for a mature NPC: damped, never to nothing.',
        'pullback.threshold.on' => 'Pressure (0..1) at which she pulls back, for an average guard and a let-in of 50.',
        'pullback.threshold.gap' => 'How far under the on-threshold the off-threshold sits: the gap that stops it flickering.',
        'pullback.threshold.guard_shift' => 'How much a guarded NPC (trait G) lowers the thresholds, per unit of guard above the middle.',
        'pullback.threshold.let_in_shift' => 'How much a deeper let-in raises the thresholds (per 50 points above 50): the more she has let the player in, the more it takes.',
        'pullback.threshold.off_min' => 'The lowest the off-threshold goes.',
        'pullback.rates.rise_per_game_hour' => 'Share of the gap to the target the pressure closes per game hour while it rises.',
        'pullback.rates.fall_per_game_hour' => 'Share of the gap to the target the pressure closes per game hour while it eases.',
        'pullback.rates.max_step_game_hours' => 'The longest stretch of game time one update counts.',
        'pullback.met.relief' => 'Pressure (0..1) a positive exchange takes off while she is pulled back, at full significance, for a mature NPC who has voiced it.',
        'pullback.met.significance_floor' => 'Share of the relief an exchange of no significance still gives.',
        'pullback.met.immature_mult' => 'Share of the relief an immature NPC takes (a mature one takes all).',
        'pullback.met.unvoiced_mult' => 'Share of the relief when she has not voiced it yet.',
        'pullback.met.ease' => 'Share the target is eased by for a while after she is met (the needs just met are not unmet again at once).',
        'pullback.met.ease_game_hours' => 'How long that ease lasts.',
        'pullback.met.tags' => 'Eval tags that meet her (besides a positive exchange or an addressed goal).',
        'pullback.met.flags' => 'Eval fields that meet her when true.',
        'pullback.styles' => 'How an immature NPC shows it: a fight (high reactivity) or a sulk (low reactivity, expressiveness and confidence); the highest trait score wins.',
        'pullback.needs_fallback' => 'What she names as missing when no single need stands out.',
        'resentment_arc.enabled' => 'Resentment\'s turning points: confrontation, resentment toward herself and guilt bleed (each has its own switch below).',
        'resentment_arc.confrontation.enabled' => 'At her own threshold she confronts the player with the grievance instead of simmering.',
        'resentment_arc.self.enabled' => 'Resentment toward herself (people-pleasers) has its own thresholds, crisis and recovery.',
        'resentment_arc.guilt_bleed.enabled' => 'Guilt from her self-resentment spills over into her other bonds.',
        'intimacy_need.enabled' => 'How much physical and emotional closeness she needs (from her traits), and what deprivation does.',
        'impulse.enabled' => 'Short-term urges (romantic, protective, social, survival, curiosity) that she may act on, in her own style.',
        'combat.rescue.enabled' => 'Rescue moments in combat (who pulled whom out of trouble) move the bond.',
        'governors.enabled' => 'Passion stays inside a floor and a ceiling set by the bond\'s tier. Off: no tier limits.',
        'diary_reflection.prompt.enabled' => 'Her depth (by maturity) and the moments marked since she last wrote reach core\'s diary prompt, so the entry itself carries them. Off: core writes the entry from its own prompt alone.',
        'exclusivity.enabled' => 'A natural pull toward the player that grows as the bond deepens, with its own reactions around suitors.',
        'romance_promotion.enabled' => 'A romance can climb its ladder (moments, confession, momentum) and hand over to Sharmat.',
        'cascade.felt.enabled' => 'When she hears what the player did to someone she cares about, she says so once at her next turn with the player (a felt line, no numbers). Off: the affinity still ripples, silently.',
        'save_load.enabled' => 'Loading an earlier save puts RelDyn\'s state back in step with it (relationship state, gold ledger).',
        'substances.enabled' => 'Her own drinking and addiction: drunk levels, tolerance, craving, withdrawal.',
        'mood_axes.derived_coords.enabled' => 'The M and F coordinates are derived from respect, self-confidence, trust and comfort.',
        'mood_axes.settle.enabled' => 'Arousal and valence settle back toward rest over play time after an event.',
        'mood_axes.social.enabled' => 'Ships off. Social events (grievance, jealousy, rescue, insult, betrayal, praise...) also push arousal and valence.',
        'post_intimacy.enabled' => 'After intimacy she is in an aftermath state (afterglow, the sober morning: uncertainty after a drunken night, guilt, distance) with its own words.',
        'gift_delta.value_base.enabled' => 'Ships off. A gift\'s worth in gold scales how much it moves her (between the minimum and maximum multiplier below). Off: every gift counts the same.',
        'gift_delta.stolen.enabled' => 'A gift that was stolen costs the player trust and respect (and a grievance).',
        'gift_delta.regift.enabled' => 'Giving her back something she gave away costs the player trust and respect.',
        'gift_delta.handover_significance' => 'How much a handed-over item counts toward what she needs from the player (0 to 1): a gift fulfills gifts, food and potions fulfill being looked after.',
        'gift_delta.eval_pair_game_hours' => 'Game hours within which an item handover and the evaluation\'s gift or help tag for the same exchange count as one handover, not two.',

        // ---- plain top-level settings ----------------------------------------
        'dimension_max_context_lines' => 'Most lines of dimension feeling that may be put into the prompt at once.',
        'attraction_beauty_weight' => 'How much looks count in what she finds attractive (1 = normal).',
        'attraction_strength_weight' => 'How much strength counts in what she finds attractive (1 = normal).',
        'attraction_status_weight' => 'How much status counts in what she finds attractive (1 = normal).',
        'attraction_competence_weight' => 'How much competence counts in what she finds attractive (1 = normal).',
        'cascade_threshold' => 'How big a change in core affinity (points) must be before it ripples to NPCs bonded to her.',
        'cascade_decay' => 'The fraction (0 to 1) of a ripple that survives being told rather than seen: what a witness saw is not damped, what she was told or heard of later is multiplied by this.',
        'social_sensitivity_signals' => 'Which evaluated signals are scaled by how deep the bond is (how much the player\'s words land); one per line.',
        'walkaway_return_grace_contacts' => 'How many of the player\'s contacts she waits through, after a resolved boundary test, before she may walk away again while still resentful.',
        'walkaway_parting_game_minutes' => 'Game minutes after she walks away in which the player\'s lines count as the parting conversation.',
        'walkaway_affinity_at' => 'Core affinity (-100 to 100) at or below which she walks away, in a bond that once existed.',
        'walkaway_affinity_min_tier' => 'How deep the bond must once have been (0 stranger to 3 bonded) for low affinity to make her walk away.',
        'reunion_min_play_minutes' => 'Real minutes of play the time apart must hold for a reunion (a wait or a sleep alone is not enough).',
        'fester_resentment_per_game_day' => 'Resentment points a not-yet-mature NPC gains per game day while a conflict stays open.',
        'fester_maturity_below' => 'Maturity (0 to 100) below which an open conflict festers.',
        'passion_absence_grace_game_hours' => 'Game hours without contact before passion starts to fade.',
        'passion_absence_fade_per_game_day' => 'Passion points lost per game day after the grace period (before the attachment multiplier).',
        'warmth_absence_grace_game_hours' => 'Game hours without contact before warmth starts to fade (times her neglect grace).',
        'warmth_absence_fade_per_game_day' => 'Warmth points lost per game day after the grace period (times her neglect rate).',
        'calendar_scan_interval_game_hours' => 'NPCs whose calendar was last advanced this many game hours ago are brought up to date on any request.',
        'calendar_scan_max_npcs' => 'Most NPCs brought up to date per request.',
        'eval_significance_clamp' => 'Most points one evaluated exchange can move a dimension by (times the exchange\'s significance, 0 to 1).',
        'affinity_modifier_min' => 'Lowest the combined affinity multiplier may go.',
        'affinity_modifier_max' => 'Highest the combined affinity multiplier may go.',
        'affinity_modifiers' => 'Rules that multiply affinity changes (by gain or loss, tags, conditions). Advanced: edit as JSON.',
        'grievance_resentment_raw' => 'Resentment points for each flagged grievance, before the severity multiplier.',
        'grievance_severity_mult' => 'Multiplier by grievance severity: the numbers are for severity 0, 1, 2 and 3, in that order.',
        'resentment_positive_decay' => 'Resentment points removed by each positive interaction.',
        'jealousy_resentment_k' => 'How fast sustained jealousy turns into resentment (higher is faster).',
        'jealousy_resentment_above' => 'Jealousy level (0 to 100) above which jealousy starts turning into resentment.',
        'jealousy_eval_gain' => 'Jealousy points from one evaluated jealousy event at the lowest intensity, before the multipliers.',
        'jealousy_intensity_mult' => 'Multiplier by jealousy intensity: the numbers are for intensity 0, 1, 2 and 3, in that order.',
        'jealousy_trust_damping' => 'How strongly trust calms possessive jealousy (0 = not at all).',
        'jealousy_grievance_kinds' => 'Grievance kinds from the evaluation that are really jealousy (a rival); one per line.',
        'jealousy_bystander_tags' => 'Event tags that make nearby NPCs jealous when the player is intimate with someone; one per line.',
        'jealousy_romantic_intent_min' => 'How openly the player must court someone (0 to 3 from the evaluation; 2 is clear flirting) before the committed NPCs who saw it are jealous, welcomed or not.',
        'jealousy_scene_cooldown_game_minutes' => 'Game minutes in which one scene reported by the plugin (Sharmat, OStim) makes its witnesses jealous only once, however many stages it has.',
        'jealousy_walkaway_at' => 'Jealousy level (0 to 100) at or above which she walks away.',
        'power_gap_in_party' => 'How little she can leave (0 to 1) while she is in the player\'s party.',
        'power_gap_factions' => 'Faction rules that set how little a member can leave. Advanced: edit as JSON.',
        'conflict_session_gap_game_hours' => 'Game hours of silence that end a "session" when watching for a conflict from an affinity drop.',
    ];

    /**
     * Family rules for the big tables: [regex on the dotted path, hint with {1}.. = the captured keys in words].
     * First match wins.
     */
    const PATTERNS = [
        // evidence tables: key => [half, weight]
        ['/^(?:reputation\.(?:fame|infamy)|reputation\.fames\.[^.]+\.evidence|attraction\.status_markers\.[^.]+|player_profile\.archetypes\.[^.]+\.(?:deeds|anchor\.evidence)|player_profile\.pillar_components\.[^.]+)\.[^.]+$/',
            'One kind of evidence about the player (a game stat, a questline, a count). Two numbers: the amount at which it counts for half its weight, then its weight (0 to 1). Several kinds combine; each adds less than the last.'],
        ['/^facet_appraisal\.felt_text\.(place|thing)\.([^.]+)\.\+(?:\.(mild|strong))?$/',
            'The words she is told when a {1} touches her interest in {2} and she likes it{3}. Feeling in words; {NAME} and {THING} are filled in.'],
        ['/^facet_appraisal\.felt_text\.(place|thing)\.([^.]+)\.-(?:\.(mild|strong))?$/',
            'The words she is told when a {1} touches her interest in {2} and she dislikes it{3}. Feeling in words; {NAME} and {THING} are filled in.'],
        ['/^facet_classifier\.anchors\.([^.]+)$/', 'Describes the {1} interest in words; the classifier compares places and things to it to see how much {1} they carry.'],
        ['/^facet_classifier\.embedding\.(.+)$/', 'A setting of the text-matching model the classifier uses ({1}).'],
        ['/^facet_classifier\.build\.(.+)$/', 'A setting for how the classifier\'s table is built ({1}).'],
        ['/^facet_classifier\.prior_weights\.(.+)$/', 'How much the {1} prior counts when the classifier blends its evidence (0 to 1).'],
        ['/^facet_classifier\.knowledge_class_prior\.([^.]+)\.([^.]+)$/', 'How strongly lore of the "{1}" kind counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.category_prior\.([^.]+)\.([^.]+)$/', 'How strongly the "{1}" category counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.tag_keywords\.([^.]+)\.([^.]+)$/', 'How strongly a place whose tag or name has "{1}" in it counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.item_keywords\.([^.]+)\.([^.]+)$/', 'How strongly an item with "{1}" in its name counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.creature_keywords\.([^.]+)\.([^.]+)$/', 'How strongly a creature with "{1}" in its name counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.activity_keywords\.([^.]+)\.([^.]+)$/', 'How strongly an activity with "{1}" in it counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.spell_subjects\.schools\.([^.]+)\.archetypes\.([^.]+)$/', 'How strongly spells of the {1} school point to the {2} archetype (0 to 1).'],
        ['/^facet_classifier\.spell_subjects\.subjects\.([^.]+)\.facets\.([^.]+)$/', 'How strongly a spell about "{1}" counts toward the {2} interest (0 to 1).'],
        ['/^facet_classifier\.spell_subjects\.subjects\.([^.]+)\.archetypes\.([^.]+)$/', 'How strongly a spell about "{1}" points to the {2} archetype (0 to 1).'],
        ['/^facet_classifier\.spell_subjects\.subjects\.([^.]+)\.archetypes$/', 'The archetypes a spell about "{1}" points to; one per line.'],
        ['/^facet_classifier\.spell_subjects\.(.+)$/', 'How spells are read for their subject ({1}).'],
        ['/^place_facets\.tags\.([^.]+)\.([^.]+)$/', 'How strongly a location tagged "{1}" carries the {2} interest (0 to 1).'],
        ['/^(?:felt_steering|memory_translation\.translation)\.salience\.([^.]+)$/', 'How prominent a "{1}" line is, from 0 to 1: when the prompt has no room for every line, the more salient ones are kept and come first.'],
        ['/^place_facets\.name_keywords\.([^.]+)$/', 'The interests a location with "{1}" in its name carries (interest => 0 to 1); empty means none.'],
        ['/^place_facets\.name_keywords\.([^.]+)\.([^.]+)$/', 'How strongly a location with "{1}" in its name carries the {2} interest (0 to 1).'],
        ['/^place_facets\.(interior|exterior|wilderness)\.([^.]+)$/', 'How much of the {2} interest a place carries just by being {1} (0 to 1).'],
        ['/^place_facets\.time_of_day\.([^.]+)\.([^.]+)$/', 'How much of the {2} interest places carry at {1} (0 to 1).'],
        ['/^place_facets\.weather\.([^.]+)\.([^.]+)$/', 'How much of the {2} interest places carry in {1} weather (0 to 1).'],
        ['/^place_facets\.time_of_day_hours\.([^.]+)$/', 'The game hours (start, end) that count as {1}.'],
        ['/^facet_preferences\.archetype_prefs\.([^.]+)\.([^.]+)$/', 'How much a {1} likes the {2} interest, from -1 (hates it) to +1 (loves it).'],
        ['/^facet_preferences\.skill_facets\.([^.]+)\.([^.]+)$/', 'How much a high {1} skill points her toward the {2} interest (0 to 1).'],
        ['/^facet_preferences\.temperament_prefs\.([^.]+)\.([^.]+)$/', 'How much a {1} temperament likes the {2} interest, from -1 (hates it) to +1 (loves it).'],
        ['/^facet_preferences\.trait_prefs\.([^.]+)\.([^.]+)$/', 'How much a "{1}" trait tag pulls her toward the {2} interest, from -1 to +1.'],
        ['/^attraction\.beauty\.keywords\.([^.]+)$/', 'Words in her view of the player\'s looks that she likes when she is a {1}; one per line.'],
        ['/^attraction\.facet_archetypes\.([^.]+)\.([^.]+)$/', 'How much a {2} archetype counts toward the {1} interest in the attraction lens (0 to 1).'],
        ['/^attraction\.lens_share\.([^.]+)$/', 'How much of her attraction lens looks at the player\'s {1} (0 to 1).'],
        ['/^temperament_autogen\.(class|faction|skill)_archetypes\.(.+)$/', 'Which archetype a {1} matching "{2}" suggests.'],
        ['/^temperament_autogen\.(archetype|voice|race)_temperament\.(.+)$/', 'Which temperament a {1} "{2}" suggests.'],
        ['/\.felt_text(?:\.|$)/', 'Wording that reaches her prompt for this feeling ({NAME} and {PLAYER} are filled in). Words, never numbers.'],
        ['/(?:^|\.)(?:text|style_text|strength_text|resolution_text)\./', 'Wording that reaches her prompt ({NAME}, {PLAYER} and similar are filled in). Words, never numbers.'],
    ];

    /** Unit notes by the last key of a setting (regex => sentence). */
    const UNITS = [
        ['/_per_game_day$/', 'Change per game day.'], ['/_per_game_hour$/', 'Change per game hour.'], ['/_per_play_minute$/', 'Change per minute of play.'],
        ['/_per_hour$/', 'Change per real hour.'],
        ['/_game_hours$/', 'In game hours.'], ['/_game_days$/', 'In game days.'], ['/_game_minutes$/', 'In game minutes.'],
        ['/_play_minutes$/', 'In minutes of play.'], ['/_hours$/', 'In hours.'], ['/_mult$/', 'A multiplier (1 = no change).'],
        ['/half_life/', 'Time for the effect to fall by half.'],
    ];

    // =====================================================================
    // LABELS
    // =====================================================================

    /** One key in words. $path is the whole path, $i the key's position in it. */
    public static function segment(string $seg, array $path, int $i): string
    {
        if (isset(self::SEGMENTS[$seg])) return self::SEGMENTS[$seg];
        // a trait code under a table that is keyed by traits (E = expressiveness)
        if ($i >= 1 && isset(RelDynTraits::TRAITS[$seg]) && self::traitTable($path, $i)) {
            return ucfirst(RelDynTraits::TRAITS[$seg]) . ' (' . $seg . ')';
        }
        if (ctype_digit($seg)) return 'Level ' . $seg;
        $p = strpos($seg, ':');
        if ($p !== false && $p > 0 && isset(self::PREFIXES[strtolower(substr($seg, 0, $p))])) {
            $rest = trim(substr($seg, $p + 1));
            $rest = strpos($rest, '_') !== false || strtolower($rest) === $rest ? str_replace('_', ' ', $rest) : $rest;
            return self::PREFIXES[strtolower(substr($seg, 0, $p))] . ' "' . $rest . '"';
        }
        return self::humanize($seg);
    }

    /** Is the key at $i of $path one of a table keyed by trait codes (default_traits, trait_weights, styles.*)? */
    private static function traitTable(array $path, int $i): bool
    {
        foreach (array_slice($path, 0, $i) as $parent) {
            if (in_array((string) $parent, ['default_traits', 'trait_weights', 'trait_gain', 'styles', 'traits'], true)) return true;
        }
        return false;
    }

    /** An underscored key as words, abbreviations spelled out. */
    public static function humanize(string $key): string
    {
        $s = trim((string) preg_replace('/[_\s]+/', ' ', $key));
        if ($s === '') return $key;
        $words = [];
        foreach (explode(' ', $s) as $w) {
            // at100 = at 100
            $words[] = self::WORDS[strtolower($w)] ?? (preg_match('/^([A-Za-z]+)(\d+)$/', $w, $m) ? $m[1] . ' ' . $m[2] : $w);
        }
        $out = implode(' ', $words);
        return ucfirst($out);
    }

    /**
     * Label for every field of $fields (code => field with 'path', 'dotted'), qualified where a label
     * would repeat inside its top-level section. Fields with an explicit label (RelDynSettings::LABELS)
     * are not touched. Returns code => label.
     */
    public static function labels(array $fields, array $explicit): array
    {
        $out = [];
        $bySection = [];
        foreach ($fields as $code => $f) {
            $path = $f['path'];
            if (isset($explicit[$f['dotted']])) { $out[$code] = $explicit[$f['dotted']][0]; continue; }
            if (count($path) > 1 && (string) end($path) === 'enabled') {
                // a switch is named by what it switches
                $parents = [];
                for ($i = 0; $i < count($path) - 1; $i++) $parents[] = self::segment((string) $path[$i], $path, $i);
                $out[$code] = implode(' › ', array_slice($parents, -3)) . ' (switch)';
                continue;
            }
            $segs = [];
            foreach ($path as $i => $seg) $segs[] = self::segment((string) $seg, $path, $i);
            $bySection[(string) $path[0]][$code] = $segs;
        }
        foreach ($bySection as $codes) {
            $depth = [];
            // a label with no real word in it (At 0, a bare number) always says what it belongs to
            foreach ($codes as $code => $segs) $depth[$code] = !preg_match('/\p{L}{4}/u', (string) end($segs)) && count($segs) > 1 ? 2 : 1;
            for ($pass = 0; $pass < 12; $pass++) {
                $groups = [];
                foreach ($codes as $code => $segs) $groups[mb_strtolower(self::compose($segs, $depth[$code]))][] = $code;
                $changed = false;
                foreach ($groups as $members) {
                    if (count($members) < 2) continue;
                    foreach ($members as $code) {
                        if ($depth[$code] < count($codes[$code])) { $depth[$code]++; $changed = true; }
                    }
                }
                if (!$changed) break;
            }
            foreach ($codes as $code => $segs) $out[$code] = self::compose($segs, $depth[$code]);
        }
        return $out;
    }

    private static function compose(array $segs, int $depth): string
    {
        return implode(' › ', array_slice($segs, -max(1, $depth)));
    }

    // =====================================================================
    // HINTS
    // =====================================================================

    /** Is there a hint written for this exact setting (not one composed from its section)? */
    public static function isExplicit(string $dotted): bool
    {
        return isset(self::HINTS[$dotted]) || isset(RelDynSettings::LABELS[$dotted]);
    }

    /** The hint of one setting: explicit, else its family's rule, else its section's help with the kind of value and unit. */
    public static function hint(array $path, string $kind, $default): string
    {
        $dotted = implode('.', array_map('strval', $path));
        if (isset(RelDynSettings::LABELS[$dotted]) && RelDynSettings::LABELS[$dotted][1] !== '') return RelDynSettings::LABELS[$dotted][1];
        if (isset(self::HINTS[$dotted])) return self::HINTS[$dotted];
        foreach (self::PATTERNS as [$regex, $text]) {
            if (!preg_match($regex, $dotted, $m)) continue;
            // the evidence rule is for the [half, weight] tables only
            if (str_contains($text, 'Two numbers') && $kind !== 'numbers') continue;
            $words = [];
            foreach ($m as $k => $v) if ($k > 0) $words[$k] = $v !== '' ? ($k === 3 && in_array($v, ['mild', 'strong'], true) ? ', ' . $v . 'ly' : str_replace('_', ' ', $v)) : '';
            return preg_replace_callback('/\{(\d)\}/', fn($mm) => $words[(int) $mm[1]] ?? '', $text);
        }
        $top = (string) $path[0];
        $help = self::SECTION_HELP[$top] ?? null;
        $last = (string) end($path);
        $notes = [];
        foreach (self::UNITS as [$regex, $note]) {
            if (preg_match($regex, $last)) { $notes[] = $note; break; }
        }
        switch ($kind) {
            case 'bool': $notes[] = 'On or off.'; break;
            case 'int': $notes[] = 'A whole number.'; break;
            case 'float': $notes[] = 'A number.'; break;
            case 'numbers': $notes[] = 'A list of numbers, separated by commas.'; break;
            case 'lines': $notes[] = 'One entry per line.'; break;
            case 'enum': $notes[] = 'Pick one.'; break;
            case 'json': $notes[] = 'Advanced: structured data (JSON).'; break;
            case 'any': $notes[] = 'Leave empty for none.'; break;
            default:
                if (is_string($default) && strpbrk($default, '{') !== false) $notes[] = 'Wording for her prompt; placeholders like {NAME} are filled in.';
        }
        // a setting straight under its section says what the section is; deeper ones say where they sit (the hub shows
        // the section's own help above its tables)
        $parents = [];
        for ($i = 0; $i < count($path) - 1; $i++) $parents[] = self::segment((string) $path[$i], $path, $i);
        if (count($path) === 2 && $help !== null) return trim('Part of "' . $parents[0] . '": ' . $help . ' ' . implode(' ', $notes));
        if ($parents !== []) return trim('Part of ' . implode(' › ', $parents) . '. ' . implode(' ', $notes));
        return trim(implode(' ', $notes) . ($help !== null ? ' ' . $help : ''));
    }
}
