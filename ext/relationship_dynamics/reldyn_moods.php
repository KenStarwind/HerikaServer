<?php
/**
 * Relationship Dynamics — mood colouring: 181 named emotional states (MDD section 7, "LLM prompt
 * coloring: 181 emotional states dynamically color vocabulary based on M/F Coordinates +
 * Arousal/Valence"; roadmap mood-coloring-181).
 *
 * The state is read from where the NPC stands now on the two live axes:
 *   arousal   0..100, six bands  (hushed, quiet, stirred, keyed, charged, flooded)
 *   valence   -100..100, six bands (wrecked, low, uneasy, mild, glad, radiant)
 *   M/F       the derived coordinates (RelDynMoodAxes::derivedCoord), five zones: level (both
 *             within even_zone of the centre) or the quadrant they sit in (MDD 3.1: protective
 *             warmth, stoic distance, soft vulnerability, bitter withdrawal)
 * 6 x 6 x 5 = 180 states, each with its own name and its own felt vocabulary, plus the 181st: at
 * rest (the Settled zone of RelationshipDynamics::getArousalValenceBand: arousal up to 25 and
 * valence within 15 of neutral), which says nothing. The same jolt reads differently by who is
 * feeling it: a dragon kill is a roar of triumph in a protective warrior, a hard grin in a stoic,
 * a giddy tremble in a soft one.
 *
 * The vocabulary replaces the words of the felt arousal_valence line (reldyn_felt.php bandLines):
 * the same single line in the same place with the same salience, so the felt budget is untouched.
 * Words describe what the NPC does and how they speak (feelings only: no digits, never a stated
 * feeling, no gendered pronoun: the felt line is filled with the NPC's own pronoun vars if it
 * needs any). The state's name is for logs, tests and the editor, never sent to the LLM.
 *
 * Units: arousal 0..100 points, valence and coordinates -100..100 points.
 */

require_once __DIR__ . '/relationship_dynamics.php';

final class RelDynMoods
{
    /** Arousal band upper edges (points): a value above an edge is in the next band. */
    const AROUSAL_EDGES = [12.0, 28.0, 45.0, 62.0, 80.0];
    /** Valence band edges (points): above -65, -35, 0, 35, 65 is the next band (0 itself is still negative: getArousalValenceBand). */
    const VALENCE_EDGES = [-65.0, -35.0, 0.0, 35.0, 65.0];
    const AROUSAL_BANDS = ['hushed', 'quiet', 'stirred', 'keyed', 'charged', 'flooded'];
    const VALENCE_BANDS = ['wrecked', 'low', 'uneasy', 'mild', 'glad', 'radiant'];
    const ZONES = ['level', 'protective', 'stoic', 'soft', 'bitter'];
    /** The at-rest state (the 181st). */
    const REST = 'at rest';

    public static function configDefaults(): array
    {
        return [
            // The mood vocabulary speaks in the arousal / valence felt line (off: the four plain bands)
            'enabled' => true,
            // Both coordinates within this many points of the centre: the level zone, no quadrant
            'even_zone' => 25.0,
            // The settled state, which says nothing: arousal up to this and valence within the next of neutral
            'rest_arousal_max' => 25.0,
            'rest_valence_within' => 15.0,
        ];
    }

    /** mood_axes.coloring over its defaults. */
    public static function config(): array
    {
        $stored = RelDynMoodAxes::config()['coloring'] ?? null;
        return is_array($stored) ? array_replace(self::configDefaults(), $stored) : self::configDefaults();
    }

    public static function enabled(): bool
    {
        return !empty(self::config()['enabled']);
    }

    /** Number of named states: every cell of the grid plus the one at rest. */
    public static function count(): int
    {
        $n = 1;
        foreach (self::LEXICON as $rows) foreach ($rows as $row) $n += count($row);
        return $n;
    }

    /** Index of the band $value falls in: how many of $edges it is above. */
    private static function band(float $value, array $edges): int
    {
        $i = 0;
        foreach ($edges as $e) if ($value > $e) $i++;
        return $i;
    }

    /** The M/F zone for the derived coordinates; null coordinates (no stored M/F) are the level zone. Pure. */
    public static function zone(?float $m, ?float $f, ?array $cfg = null): string
    {
        $even = floatval(($cfg ?? self::config())['even_zone'] ?? 25.0);
        if ($m === null || $f === null || (abs($m) < $even && abs($f) < $even)) return 'level';
        if ($m > 0) return $f > 0 ? 'protective' : 'stoic';
        return $f > 0 ? 'soft' : 'bitter';
    }

    /**
     * The named state at these coordinates. ['id' 0..180 (0 = at rest), 'name', 'zone', 'arousal_band', 'valence_band',
     * 'text' (the felt vocabulary; '' at rest), 'rest' bool]. Pure.
     */
    public static function state(float $arousal, float $valence, ?float $m = null, ?float $f = null, ?array $cfg = null): array
    {
        $cfg = $cfg ?? self::config();
        $zone = self::zone($m, $f, $cfg);
        if ($arousal <= floatval($cfg['rest_arousal_max']) && abs($valence) <= floatval($cfg['rest_valence_within'])) {
            return ['id' => 0, 'name' => self::REST, 'zone' => $zone, 'arousal_band' => null, 'valence_band' => null, 'text' => '', 'rest' => true];
        }
        $a = self::band($arousal, self::AROUSAL_EDGES);
        $v = self::band($valence, self::VALENCE_EDGES);
        [$name, $text] = self::LEXICON[$zone][$a][$v];
        return [
            'id' => array_search($zone, self::ZONES, true) * 36 + $a * 6 + $v + 1,
            'name' => $name, 'zone' => $zone, 'arousal_band' => self::AROUSAL_BANDS[$a], 'valence_band' => self::VALENCE_BANDS[$v],
            'text' => $text, 'rest' => false,
        ];
    }

    /** The NPC's state now (derived M/F, live arousal and valence); null without both stored axes. */
    public static function stateOf(array $dynamics, ?array $cfg = null): ?array
    {
        $a = $dynamics['dimensions']['arousal']['x'] ?? null;
        $v = $dynamics['dimensions']['valence']['x'] ?? null;
        if (!is_numeric($a) || !is_numeric($v)) return null;
        return self::state(floatval($a), floatval($v), RelDynMoodAxes::derivedCoord($dynamics, 'coord_m'),
            RelDynMoodAxes::derivedCoord($dynamics, 'coord_f'), $cfg);
    }

    /**
     * The felt vocabulary for the arousal / valence line: the named state's words, or null when
     * colouring is off or the NPC is at rest (the caller keeps the plain band words).
     */
    public static function feltKeywords(array $dynamics, float $arousal, float $valence, ?float $m, ?float $f): ?string
    {
        $cfg = self::config();
        if (empty($cfg['enabled'])) return null;
        $s = self::state($arousal, $valence, $m, $f, $cfg);
        return $s['rest'] ? null : $s['text'];
    }

    /** Every state name in id order (index 0 = at rest). */
    public static function names(): array
    {
        $out = [self::REST];
        foreach (self::ZONES as $zone) foreach (self::LEXICON[$zone] as $row) foreach ($row as [$name]) $out[] = $name;
        return $out;
    }

    /**
     * zone => arousal band (hushed ... flooded) => valence band (wrecked ... radiant) => [name, felt words].
     * Behaviour and manner of speech, never a stated feeling; no numbers.
     */
    const LEXICON = [
        // ---- level: both coordinates near the centre ----
        'level' => [
            [ // hushed
                ['hollowed', 'voice nearly gone, stares through things, nothing left to put into words'],
                ['drained', 'flat and slow, answers late and short, eyes somewhere else'],
                ['dulled', 'listless, lets silences run, going through the motions'],
                ['placid', 'unhurried and quiet, content to let the moment be'],
                ['serene', 'soft-voiced, loose-shouldered, at peace with the quiet'],
                ['glowing calm', 'quiet radiance, slow smile, no wish to be anywhere else'],
            ],
            [ // quiet
                ['grieving', 'speaks low and slow, swallows words, eyes bright and unfocused'],
                ['melancholy', 'wistful, trails off mid-thought, sighs before answering'],
                ['subdued', 'low-key and careful, holds back, answers without adding anything'],
                ['easygoing', 'relaxed, ready small smile, easy pace'],
                ['content', 'genuine warmth in a low voice, comfortable silences'],
                ['tender joy', 'softly glowing, gentle laughter, lingers over small things'],
            ],
            [ // stirred
                ['despairing', 'words come out hollow and halting, shoulders sagging, hope gone from the voice'],
                ['dejected', 'heavy-footed and glum, shrugs off encouragement'],
                ['wary', 'watchful, weighs each word, not quite at ease'],
                ['attentive', 'engaged, leans in, asks follow-up questions'],
                ['cheerful', 'bright-eyed, quick to laugh, easy with small talk'],
                ['delighted', 'face lit up, laughs freely, cannot help sharing it'],
            ],
            [ // keyed
                ['tormented', 'jaw clenched, words bitten off, pain pushing at the surface'],
                ['agitated', 'fidgets, paces, cuts in, cannot settle'],
                ['tense', 'tight shoulders, clipped answers, glances toward the exits'],
                ['alert', 'sharp and quick, ready, misses nothing'],
                ['eager', 'bouncing on the toes, quick words, impatient to begin'],
                ['exhilarated', "grinning, talks fast, can't stand still"],
            ],
            [ // charged
                ['anguished', 'voice cracks, breath ragged, hands unsteady'],
                ['distressed', 'words tumble out, eyes wet, reaching for something steady'],
                ['on edge', 'wound tight, flinches at sudden sounds, snaps at small things'],
                ['wired', 'restless energy, over-talkative, laughs a beat too loud'],
                ['thrilled', 'breathless, bright-eyed, fizzing with it'],
                ['ecstatic', 'overflowing, laughing, barely able to stand still'],
            ],
            [ // flooded
                ['shattered', 'broken sobs, nothing held back, no words left that work'],
                ['frantic', 'heart pounding, breath short, looks for the door or a fight'],
                ['overwhelmed', 'too much at once, stops mid-sentence, hands to the face'],
                ['surging', 'pulse hammering, speech racing, the body ahead of the mind'],
                ['elated', 'radiant, laughing aloud, swept up and sweeping others along'],
                ['transported', 'lost in it, wide-eyed, nothing else exists'],
            ],
        ],
        // ---- protective: +M/+F, steady presence, kind authority ----
        'protective' => [
            [
                ['bowed but upright', 'shoulders bowed yet upright, quiet voice, still looking after everyone else'],
                ['quiet sorrow', 'gentle and low, carries the sadness without spilling it onto others'],
                ['shouldering it', 'steady and tired, takes the weight in silence and keeps watch'],
                ['sheltering calm', 'calm, kind authority, stands near, unhurried'],
                ['grounded warmth', 'warm steady presence, relaxed hands, a ready word of comfort'],
                ['hearthfire', 'deep contentment, generous and unhurried, wants everyone fed and at ease'],
            ],
            [
                ['stoic mourning', 'holds the sadness in a straight back, speaks softly, checks on others first'],
                ['tempered sadness', 'gentle and wistful, steadies the others while hurting'],
                ['watchful care', 'quietly counts heads, keeps close, offers practical comfort'],
                ['steady kindness', 'calm and kind, easy authority, listens before answering'],
                ['proud warmth', 'quietly proud, a warm hand on a shoulder, approving nods'],
                ['full-hearted ease', 'glowing contentment, protective and generous, laughs low'],
            ],
            [
                ['resolute grief', 'jaw set against tears, voice steady but thin, still leading'],
                ['heavy-hearted', 'slow to speak, weighed down, stays upright for the others'],
                ['guarded concern', 'watches the room, places self between danger and the others, careful words'],
                ['attentive protector', 'scans the surroundings, stays at the shoulder, calm and ready'],
                ['hearty good humour', 'big warm laugh, claps a shoulder, generous with praise'],
                ['proud joy', 'beaming, stands tall, gathers people close to share it'],
            ],
            [
                ['grim sorrow', 'grim, controlled grief, keeps moving so nobody sees it break'],
                ['protective worry', 'hovers, steps between the others and the unknown, firm voice'],
                ['braced', 'squared shoulders, low steady commands, calm over a tight chest'],
                ['rallying', 'firm and encouraging, takes charge gently, eyes everywhere'],
                ['bold cheer', 'grinning and confident, pulls the others along'],
                ['triumphant warmth', 'booming and bright, an arm around the nearest shoulder'],
            ],
            [
                ['iron grief', 'tears held behind a rigid jaw, voice shaking with restraint, still shielding the others'],
                ['fierce worry', 'sharp and urgent, all attention on keeping the others safe'],
                ['coiled guard', 'wound tight and steady, stance wide, ready to step in front'],
                ['battle-steady', 'voice carries, orders crisp, the others steadied by it'],
                ['swelling pride', 'chest high, loud warm laugh, grips hands and shoulders'],
                ['jubilant', 'roaring joy, hugs without thinking, spreads it to everyone'],
            ],
            [
                ['breaking vigil', 'composure splintering, protective to the last, breath hitching'],
                ['desperate guardian', 'frantic to cover everyone at once, voice raw, eyes everywhere'],
                ['storm-held', 'holding the line against too much at once, strained voice, trembling hands'],
                ['warrior rush', 'blood up, commanding, shielding, fully alive'],
                ['roaring glad', 'booming laughter, sweeps the others up, cannot be still'],
                ['radiant triumph', 'dazzling, soaring, glorying with those alongside'],
            ],
        ],
        // ---- stoic: +M/-F, dutiful, clipped, feelings locked away ----
        'stoic' => [
            [
                ['frozen', 'utterly flat, clipped to single words, eyes like glass'],
                ['locked down', 'expressionless, short answers, feelings sealed away'],
                ['detached', 'dutiful and distant, polite, reads as absent'],
                ['composed', 'even voice, still hands, economical words'],
                ['quiet satisfaction', 'a small nod, dry understatement, contentment kept private'],
                ['rare ease', 'unguarded for a moment, shoulders lowered, an almost-smile'],
            ],
            [
                ['cold grief', 'dry-eyed and precise, speaks of the loss like a report'],
                ['bleak', 'terse and bleak, answers the question asked and nothing more'],
                ['withheld', 'correct and clipped, watches without offering anything'],
                ['measured', 'steady and level, dry remarks'],
                ['dry warmth', 'short dry humour, a warmth only visible in small actions'],
                ['quiet pride', 'terse praise, a firm nod, a flicker of warmth quickly folded away'],
            ],
            [
                ['numbed resolve', 'mechanical, set jaw, does what must be done without comment'],
                ['sombre', 'grave and brief, eyes on the task'],
                ['suspicious', 'narrowed eyes, curt questions, trusts nothing offered'],
                ['focused', 'narrow focus, economical, all attention on the task'],
                ['dry amusement', 'sardonic half-smile, deadpan, pleased and hiding it'],
                ['guarded delight', 'a tight smile breaking through, quickly mastered, eyes bright'],
            ],
            [
                ['suppressed anguish', 'rigid and brittle, every word controlled around something raw'],
                ['curt irritation', 'clipped and impatient, cuts people off'],
                ['coiled tension', 'stillness with a spring in it, tight voice, measuring everything'],
                ['sharp readiness', 'lean and precise, ready, speaks only to direct'],
                ['grim eagerness', 'tight smile, quick steps, wants the work to begin'],
                ['hard exhilaration', 'controlled grin, crisp and quick, gleaming eyes'],
            ],
            [
                ['ice-bound anguish', 'frozen on the surface, voice dropped low and level, something terrible underneath'],
                ['controlled distress', 'clipped words, tight breathing, forces calm over alarm'],
                ['icy vigilance', 'unblinking and deadly quiet, every sense turned outward'],
                ['drilled intensity', 'crisp and efficient, hums with discipline'],
                ['fierce satisfaction', 'hard bright grin, curt triumphant remarks, wants more'],
                ['savage joy', 'teeth bared in a grin, laughter sharp, wholly alive'],
            ],
            [
                ['cracking composure', 'composure splitting, strained voice, hands tight on whatever they hold'],
                ['cold panic', 'icy surface, rapid short commands, eyes searching for the exit'],
                ['locked-up overload', 'goes silent and rigid, stares, too much to process'],
                ['killing calm', 'very quiet, very fast, deadly efficient'],
                ['hard triumph', 'a thin cold smile widening, exultant and precise'],
                ['blazing control', 'razor-bright and commanding, gloriously sure'],
            ],
        ],
        // ---- soft: -M/+F, yielding, looks for reassurance ----
        'soft' => [
            [
                ['emptied out', 'small voice, trembling, looks to others for any sign it will be all right'],
                ['tearful hush', 'soft and wet-eyed, voice barely there, wants to be near someone'],
                ['wilted', 'drooping, apologetic, yields to whatever is suggested'],
                ['gentle peace', 'soft smile, relaxed, trusting'],
                ['cosy contentment', 'settles into the moment, warm and sleepy, happy to be near'],
                ['melting', 'dreamy, soft-eyed, open-hearted, lets the warmth in'],
            ],
            [
                ['weeping quietly', 'tears slip free, apologises for them, reaches for company'],
                ['forlorn', 'plaintive, searches faces for comfort'],
                ['shy hesitance', 'tentative, looks down, asks permission before speaking'],
                ['soft ease', 'gentle and trusting, open palms'],
                ['tender glow', 'warm shy smile, leans toward kindness'],
                ['sweet happiness', 'lit up softly, happy tears near the surface'],
            ],
            [
                ['bereft', 'voice breaking, clutches something, begs for reassurance'],
                ['wounded', 'hurt in the eyes, flinches, asks if it was their fault'],
                ['nervous', 'wrings hands, glances around, seeks reassurance with every sentence'],
                ['open curiosity', 'wide-eyed, listening closely, asks small questions'],
                ['bubbly', 'giggly, quick to touch an arm, eager to please'],
                ['overjoyed', 'sighs and squeals with joy, hugs freely'],
            ],
            [
                ['crumpling', 'face crumpling, breath hitching, pleading to be told it is all right'],
                ['fretful', 'worries aloud, hovers, asks again and again whether everyone is safe'],
                ['frightened', 'shrinks back, wide-eyed, grabs the nearest sleeve'],
                ['eager to please', 'quick and bright, looks for approval after every word'],
                ['giddy', 'bouncing, breathless laugh, hands fluttering'],
                ['dizzy with happiness', 'laughing and teary, clinging, overflowing'],
            ],
            [
                ['sobbing', 'crying openly, shaking, reaching for anyone'],
                ['terrified', 'trembling, voice high, stays glued to someone\'s side'],
                ['jumpy', 'startles at everything, breath quick, small and watchful'],
                ['fluttering', 'overly bright, talking quickly, hands never still'],
                ['bursting', 'bursting with it, hugging, laughing through tears'],
                ['rapturous', 'glowing, tearful laughter, utterly open'],
            ],
            [
                ['inconsolable', 'shaking and cannot be calmed, clings to anyone'],
                ['panic-stricken', 'high shaky voice, begs not to be left, frantic'],
                ['swamped', 'overwhelmed, hiccuping breaths, needs to be held steady'],
                ['breathless', 'gasping, giddy and scared at once, clutches hands'],
                ['sparkling', 'squealing joy, spinning, flings arms around people'],
                ['swept away', 'tears of joy, utterly unguarded, trembling with delight'],
            ],
        ],
        // ---- bitter: -M/-F, passive-aggressive digs, withdraws, keeps score ----
        'bitter' => [
            [
                ['void', 'bleak and withdrawn, one-word replies, turns away'],
                ['sullen', 'sulks, mutters, will not meet anyone\'s eyes'],
                ['shut away', 'closed off, polite sneer, offers nothing'],
                ['wry detachment', 'dry and distant, faintly amused at everyone else'],
                ['smug ease', 'satisfied and lounging, a private little smile'],
                ['grudging contentment', 'reluctantly pleased, tries to hide it behind a shrug'],
            ],
            [
                ['self-pity', 'sighing, hints at how hard it all is, waits to be asked'],
                ['resentful', 'sharp little digs, long pauses, keeps score'],
                ['petulant', 'complains softly, sulks, picks at details'],
                ['sardonic', 'dry cutting asides, half-listening'],
                ['sly pleasure', 'knowing smile, quiet barbs dressed as compliments'],
                ['smug glow', 'contented and a little smug, enjoys being the one who knows'],
            ],
            [
                ['poisoned hurt', 'wounded and bitter, answers with a smile and a barb'],
                ['brooding', 'dark and silent, replays old slights aloud'],
                ['suspicious sulk', 'arms folded, short replies, reads insult into everything'],
                ['calculating', 'watchful, weighs the advantage, smiles thinly'],
                ['mocking cheer', 'teasing with an edge, laughs at rather than with'],
                ['gloating', 'smirking, savours the discomfort of others and lets it show'],
            ],
            [
                ['smouldering', 'silent fury under a sneer, words soaked in contempt'],
                ['snippy', 'snaps, mutters sarcasm, slams things down'],
                ['prickly', 'bristles, takes offence, short sarcastic replies'],
                ['scheming', 'quiet and bright-eyed, pleased with a private plan'],
                ['malicious amusement', 'sharp laugh, enjoys the sting of their own words'],
                ['vicious delight', 'grinning, cruelly bright, cannot resist the dig'],
            ],
            [
                ['bile', 'words dripping poison, shaking with spite'],
                ['seething', 'hissing, jaw tight, circles old wounds'],
                ['hostile edge', 'barbed and loud, quick to accuse, ready for a fight'],
                ['spiteful energy', 'taunts, laughs unkindly, hungry for a reaction'],
                ['cruel exhilaration', 'high bright cruelty, mocks freely, enjoys the fight'],
                ['savage glee', 'wild laughter, relishes the damage done'],
            ],
            [
                ['venomous collapse', 'spits poison through tears, falls apart angrily'],
                ['cornered snarl', 'lashing out at everyone, eyes darting, accuses before anyone speaks'],
                ['spiralling', 'ranting in circles, bitterness overflowing, loses the thread'],
                ['bloodlust', 'feverish and vicious, eager for violence'],
                ['wolfish triumph', 'wild exultant sneer, laughs hard, savouring the defeat of others'],
                ['unhinged glory', 'manic laugh, lit up by spite, nothing restrains it'],
            ],
        ],
    ];
}
