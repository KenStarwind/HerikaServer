<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Just enough of `sql` for a stored RelDyn config row; every other read finds nothing. */
final class RelDynEmergentEmotionsConfigDb
{
    public function __construct(public array $config) {}
    public function escape($s): string { return str_replace("'", "''", (string) $s); }
    public function escapeLiteral($s): string { return "'" . $this->escape($s) . "'"; }
    public function fetchOne($sql, $params = null)
    {
        return str_contains((string) $sql, "conf_opts WHERE id = 'relationship_dynamics_config'")
            ? ['value' => json_encode($this->config)] : [];
    }
    public function fetchAll($sql, $log = false) { return []; }
    public function execQuery($sql) { return true; }
}

/**
 * Emergent emotions (roadmap emergent-emotions; dimension draft "Other Emergent Emotions"): named
 * combinations of the dimensions, never stored. The ten the draft names, each with the draft's
 * inputs (the table below is the draft, one row per emotion, and a row's `breaks` are the same
 * state with one input moved out of its range), the six code-only extras the draft does not name,
 * and the three readings a dimension range cannot say: a short window of recent respect /
 * resentment (a respect drop, resentment rising), the absence still on her (warmth fade held), her
 * sensitivity curve (Inner Circle). A dimension she has not got is no match, not a zero. At most
 * two speak, the deepest, and the romantic ones only inside a romance. Units: dimension points 0..100.
 * No database: config is the defaults, with derived warmth off so warmth is a stored dimension.
 */
final class RelDynEmergentEmotionsTest extends TestCase
{
    private const DAY = RelationshipDynamics::GAMETS_PER_DAY;
    private const HOUR = RelationshipDynamics::GAMETS_PER_DAY / 24;
    private const T0 = 400 * self::DAY;

    private array $saved = [];
    private RelDynEmergentEmotionsConfigDb $db;

    protected function setUp(): void
    {
        foreach (['db', 'gameRequest', 'PLAYER_NAME'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['PLAYER_NAME'] = 'Kaida';
        $cfg = RelationshipDynamics::defaultConfig();
        $cfg['passion_dynamics'] = array_merge(RelDynPassion::configDefaults(), ['derived_warmth_enabled' => false]);
        $this->db = new RelDynEmergentEmotionsConfigDb($cfg);
        $GLOBALS['db'] = $this->db;
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** The state that is none of the sixteen: every dimension mid-way, nothing recent, an NPC that rests low. */
    private const NEUTRAL = [
        'dims' => ['affinity' => 50.0, 'trust' => 50.0, 'comfort' => 50.0, 'respect' => 50.0, 'passion' => 30.0, 'maturity' => 50.0,
                   'self_confidence' => 50.0, 'resentment' => 0.0, 'arousal' => 30.0, 'valence' => 0.0, 'warmth' => 45.0],
        'baseline' => ['comfort' => 50.0, 'affinity' => 10.0],
        'extra' => ['social_sensitivity_curve' => 'open_heart', '_core_rel_type' => 'platonic'],
    ];

    /** NEUTRAL with $spec laid over it: dims / baseline entries replace, extra entries replace (null removes). */
    private function state(array $spec = []): array
    {
        $d = RelationshipDynamics::migrateDimensions(array_merge(RelationshipDynamics::defaultDynamics(), ['inferred_temperament' => 'Guarded']));
        $dims = array_replace(self::NEUTRAL['dims'], $spec['dims'] ?? []);
        $base = array_replace(self::NEUTRAL['baseline'], $spec['baseline'] ?? []);
        foreach ($dims as $dim => $x) {
            if ($dim === 'affinity') {
                $d['dimensions']['affinity']['x'] = $x;
            } elseif ($dim === 'passion') {
                RelationshipDynamics::setPassion($d, $x);
            } else {
                $d['dimensions'][$dim]['x'] = $x;
            }
        }
        foreach ($base as $dim => $b) $d['dimensions'][$dim]['baseline'] = $b;
        foreach (array_replace(self::NEUTRAL['extra'], $spec['extra'] ?? []) as $k => $v) {
            if ($v === null) unset($d[$k]); else $d[$k] = $v;
        }
        return $d;
    }

    /** $spec with $patch laid over it, section by section (a section entry of null removes it). */
    private static function patch(array $spec, array $patch): array
    {
        foreach ($patch as $section => $entries) $spec[$section] = array_replace($spec[$section] ?? [], $entries);
        return $spec;
    }

    private function emotions(array $spec): array
    {
        return RelationshipDynamics::detectEmergentEmotions($this->state($spec));
    }

    private static function sample(float $hoursAgo, float $respect, float $resentment): array
    {
        return ['t' => self::T0 - $hoursAgo * self::HOUR, 'respect' => $respect, 'resentment' => $resentment];
    }

    /**
     * The draft's ten: each a state that matches, and the same state with each input moved out of
     * its range (and, where the old code's rule differed from the draft, the old rule's input left
     * where it no longer matters).
     */
    private static function draft(): array
    {
        return [
            // Low comfort + low respect + high affinity: "I love you but I don't think I'm good enough"
            'insecurity' => [
                'match' => ['dims' => ['affinity' => 75.0, 'comfort' => 30.0, 'respect' => 30.0]],
                'breaks' => ['affinity' => ['dims' => ['affinity' => 55.0]], 'comfort' => ['dims' => ['comfort' => 45.0]], 'respect' => ['dims' => ['respect' => 40.0]]],
            ],
            // High affinity + low maturity + low comfort BASELINE (not the moment's comfort, not self-confidence)
            'codependency' => [
                'match' => ['dims' => ['affinity' => 90.0, 'maturity' => 30.0], 'baseline' => ['comfort' => 20.0]],
                'breaks' => ['affinity' => ['dims' => ['affinity' => 70.0]], 'maturity' => ['dims' => ['maturity' => 45.0]],
                             'baseline' => ['baseline' => ['comfort' => 40.0], 'dims' => ['comfort' => 10.0]]],
            ],
            // High affinity + LOW comfort + rising resentment
            'suffocation' => [
                'match' => ['dims' => ['affinity' => 70.0, 'comfort' => 30.0, 'resentment' => 40.0],
                            'extra' => ['_emotion_window' => [['t' => self::T0 - 6 * self::HOUR, 'respect' => 50.0, 'resentment' => 30.0], ['t' => self::T0, 'respect' => 50.0, 'resentment' => 40.0]]]],
                'breaks' => ['affinity' => ['dims' => ['affinity' => 50.0]],
                             'comfort (80+ was the old rule)' => ['dims' => ['comfort' => 85.0]],
                             'resentment' => ['dims' => ['resentment' => 20.0]],
                             'not rising' => ['extra' => ['_emotion_window' => [['t' => self::T0 - 6 * self::HOUR, 'respect' => 50.0, 'resentment' => 40.0], ['t' => self::T0, 'respect' => 50.0, 'resentment' => 40.0]]]],
                             'no window' => ['extra' => ['_emotion_window' => null]]],
            ],
            // Low warmth + high affinity BASELINE + the absence still on her
            'loneliness' => [
                'match' => ['dims' => ['warmth' => 20.0], 'baseline' => ['affinity' => 35.0], 'extra' => ['_warmth_fade' => -6.0]],
                'breaks' => ['warmth' => ['dims' => ['warmth' => 50.0]], 'baseline' => ['baseline' => ['affinity' => 15.0]], 'no absence' => ['extra' => ['_warmth_fade' => null]]],
            ],
            // Low respect + high resentment + HIGH maturity (no affinity input)
            'contempt' => [
                'match' => ['dims' => ['resentment' => 70.0, 'respect' => 15.0, 'maturity' => 70.0, 'affinity' => 20.0]],
                'breaks' => ['maturity' => ['dims' => ['maturity' => 40.0]], 'respect' => ['dims' => ['respect' => 30.0]], 'resentment' => ['dims' => ['resentment' => 40.0]]],
            ],
            // High passion + low trust + LOW maturity (no affinity input)
            'infatuation' => [
                'match' => ['dims' => ['passion' => 70.0, 'trust' => 30.0, 'maturity' => 30.0, 'affinity' => 50.0]],
                'breaks' => ['maturity' => ['dims' => ['maturity' => 60.0]], 'trust' => ['dims' => ['trust' => 60.0]], 'passion' => ['dims' => ['passion' => 50.0]]],
            ],
            // High trust + comfort + maturity + self-confidence + a committed bond
            'earned_security' => [
                'match' => ['dims' => ['trust' => 80.0, 'comfort' => 80.0, 'maturity' => 75.0, 'self_confidence' => 70.0], 'extra' => ['_core_rel_type' => 'romantic']],
                'breaks' => ['trust' => ['dims' => ['trust' => 60.0]], 'comfort' => ['dims' => ['comfort' => 60.0]], 'maturity' => ['dims' => ['maturity' => 50.0]],
                             'self_confidence' => ['dims' => ['self_confidence' => 50.0]], 'bond' => ['extra' => ['_core_rel_type' => 'platonic']]],
            ],
            // High respect + high maturity + low self-confidence
            'imposter_syndrome' => [
                'match' => ['dims' => ['respect' => 75.0, 'maturity' => 65.0, 'self_confidence' => 30.0]],
                'breaks' => ['respect' => ['dims' => ['respect' => 50.0]], 'maturity' => ['dims' => ['maturity' => 40.0]], 'self_confidence' => ['dims' => ['self_confidence' => 60.0]]],
            ],
            // High self-confidence + a sudden respect loss (the short window) + low maturity
            'narcissistic_collapse' => [
                'match' => ['dims' => ['self_confidence' => 80.0, 'maturity' => 30.0, 'respect' => 45.0],
                            'extra' => ['_emotion_window' => [self::sample(3, 70.0, 0.0), self::sample(0, 45.0, 0.0)]]],
                'breaks' => ['self_confidence' => ['dims' => ['self_confidence' => 50.0]], 'maturity' => ['dims' => ['maturity' => 60.0]],
                             'a small drop' => ['extra' => ['_emotion_window' => [self::sample(3, 50.0, 0.0), self::sample(0, 45.0, 0.0)]]],
                             'a slow drop (older than a day)' => ['extra' => ['_emotion_window' => [self::sample(30, 70.0, 0.0), self::sample(0, 45.0, 0.0)]]],
                             'no window' => ['extra' => ['_emotion_window' => null]]],
            ],
            // Low self-confidence + Inner Circle sensitivity + high affinity toward the validator
            'validation_addiction' => [
                'match' => ['dims' => ['self_confidence' => 20.0, 'affinity' => 85.0], 'extra' => ['social_sensitivity_curve' => 'inner_circle']],
                'breaks' => ['self_confidence' => ['dims' => ['self_confidence' => 45.0]], 'affinity' => ['dims' => ['affinity' => 55.0]],
                             'curve' => ['extra' => ['social_sensitivity_curve' => 'open_heart']]],
            ],
        ];
    }

    public function testTheDraftsTenAreAllThereWithTheSixCodeOnlyExtrasKept(): void
    {
        $all = array_keys(RelationshipDynamics::EMERGENT_EMOTIONS);
        $draft = ['insecurity', 'codependency', 'suffocation', 'loneliness', 'contempt', 'infatuation', 'earned_security', 'imposter_syndrome',
                  'narcissistic_collapse', 'validation_addiction'];
        $extras = ['longing', 'protective_fury', 'quiet_devotion', 'bitter_nostalgia', 'grudging_respect', 'volatile_passion'];
        $this->assertSame([], array_diff($draft, $all));
        $this->assertSame([], array_diff($extras, $all));
        $this->assertCount(16, $all);
        $this->assertSame($draft, array_keys(self::draft()), 'the table here is the draft');
        $this->assertSame([], $this->emotions([]), 'the neutral state is none of them');
    }

    public function testEachDraftEmotionMatchesItsInputsAndNeedsEveryOne(): void
    {
        foreach (self::draft() as $id => $row) {
            $this->assertContains($id, $this->emotions($row['match']), "{$id}: matches the draft's inputs");
            foreach ($row['breaks'] as $why => $patch) {
                $this->assertNotContains($id, $this->emotions(self::patch($row['match'], $patch)), "{$id}: not without {$why}");
            }
        }
    }

    public function testAMissingDimensionIsNoMatchNotAZero(): void
    {
        // codependency's old self-confidence rule read a never-set value as 0 and matched
        foreach (self::draft() as $id => $row) {
            $rules = array_filter(array_keys(RelationshipDynamics::EMERGENT_EMOTIONS[$id]['rules']), fn($k) => !str_contains($k, '.'));
            foreach (array_intersect($rules, array_keys($row['match']['dims'] ?? [])) as $dim) {
                $d = $this->state($row['match']);
                $d['dimensions'][$dim]['x'] = null;
                $this->assertNotContains($id, RelationshipDynamics::detectEmergentEmotions($d), "{$id}: no {$dim} to read");
                unset($d['dimensions'][$dim]);
                $this->assertNotContains($id, RelationshipDynamics::detectEmergentEmotions($d), "{$id}: no {$dim} at all");
            }
        }
        // the unset self-confidence of a fresh NPC does not make her dependent
        $d = $this->state(['dims' => ['affinity' => 90.0, 'comfort' => 20.0]]);
        $d['dimensions']['self_confidence']['x'] = null;
        $this->assertSame([], array_intersect(['codependency', 'validation_addiction', 'imposter_syndrome'], RelationshipDynamics::detectEmergentEmotions($d)));
    }

    public function testTheSixCodeOnlyExtrasStillMatchTheirOwnRules(): void
    {
        $extras = [
            'longing' => ['dims' => ['affinity' => 70.0, 'warmth' => 60.0, 'passion' => 10.0]],
            'protective_fury' => ['dims' => ['affinity' => 80.0, 'arousal' => 70.0, 'valence' => -40.0]],
            'quiet_devotion' => ['dims' => ['affinity' => 90.0, 'maturity' => 80.0, 'resentment' => 0.0, 'passion' => 20.0]],
            'bitter_nostalgia' => ['dims' => ['affinity' => 20.0, 'warmth' => 60.0, 'resentment' => 50.0]],
            'grudging_respect' => ['dims' => ['respect' => 70.0, 'affinity' => 10.0, 'resentment' => 40.0]],
            'volatile_passion' => ['dims' => ['passion' => 80.0, 'maturity' => 20.0, 'arousal' => 60.0]],
        ];
        foreach ($extras as $id => $spec) $this->assertContains($id, $this->emotions($spec), $id);
    }

    public function testTheLoneliestNeedsTheAbsenceStillOnHerAndTheContactHealsIt(): void
    {
        $d = $this->state(self::draft()['loneliness']['match']);
        $this->assertContains('loneliness', RelationshipDynamics::detectEmergentEmotions($d));
        // a positive exchange gives back some of the warmth the absence faded (RelDynPassion::regainWarmthFade)
        for ($i = 0; $i < 40; $i++) RelDynPassion::regainWarmthFade($d);
        $this->assertArrayNotHasKey(RelDynPassion::WARMTH_FADE_KEY, $d);
        $this->assertNotContains('loneliness', RelationshipDynamics::detectEmergentEmotions($d));
    }

    public function testTheContactThatEndsALongAbsenceIsTheAbsenceOnHerToo(): void
    {
        // no warmth fade held (she was already at where her warmth rests), but the player was gone a
        // month: the return turn finds her lonely; a contact an hour after the last one does not
        $match = self::patch(self::draft()['loneliness']['match'], ['extra' => ['_warmth_fade' => null, '_core_rel_type' => 'romantic']]);
        $gone = $this->state(self::patch($match, ['extra' => ['_previous_contact_gamets' => self::T0 - 30 * self::DAY, '_last_contact_gamets' => self::T0]]));
        $this->assertContains('loneliness', RelationshipDynamics::detectEmergentEmotions($gone));
        $here = $this->state(self::patch($match, ['extra' => ['_previous_contact_gamets' => self::T0 - self::HOUR, '_last_contact_gamets' => self::T0]]));
        $this->assertNotContains('loneliness', RelationshipDynamics::detectEmergentEmotions($here));
        $never = $this->state($match);
        $this->assertNotContains('loneliness', RelationshipDynamics::detectEmergentEmotions($never), 'no contact on record, no absence');
        // her warmth and her nature still decide: a cold, guarded one (low affinity baseline) is not lonely for company
        $guarded = $this->state(self::patch($match, ['extra' => ['_previous_contact_gamets' => self::T0 - 30 * self::DAY, '_last_contact_gamets' => self::T0], 'baseline' => ['affinity' => 10.0]]));
        $this->assertNotContains('loneliness', RelationshipDynamics::detectEmergentEmotions($guarded));
    }

    // ------------------------------------------------------------------ the short window

    public function testTheWindowKeepsRespectAndResentmentWhereTheyMovedAndForgetsTheOld(): void
    {
        $d = $this->state(['dims' => ['respect' => 60.0, 'resentment' => 10.0]]);
        $this->assertTrue(RelationshipDynamics::noteEmotionWindow($d, self::T0), 'the first sample');
        $this->assertEquals([['t' => self::T0, 'respect' => 60.0, 'resentment' => 10.0]], $d[RelationshipDynamics::EMERGENT_WINDOW_KEY]);
        $this->assertFalse(RelationshipDynamics::noteEmotionWindow($d, self::T0 + self::HOUR), 'nothing moved: no new sample, no save');
        $d['dimensions']['respect']['x'] = 50.0;
        $this->assertTrue(RelationshipDynamics::noteEmotionWindow($d, self::T0 + 2 * self::HOUR));
        $this->assertCount(2, $d[RelationshipDynamics::EMERGENT_WINDOW_KEY]);
        $this->assertEquals(50.0, $d[RelationshipDynamics::EMERGENT_WINDOW_KEY][1]['respect']);
        // a movement under the sample delta is no new sample
        $d['dimensions']['respect']['x'] = 49.9;
        $this->assertFalse(RelationshipDynamics::noteEmotionWindow($d, self::T0 + 3 * self::HOUR));
        // three game days on, the first samples have aged out of the window
        $d['dimensions']['respect']['x'] = 40.0;
        $this->assertTrue(RelationshipDynamics::noteEmotionWindow($d, self::T0 + 3.5 * self::DAY));
        $times = array_column($d[RelationshipDynamics::EMERGENT_WINDOW_KEY], 't');
        $this->assertEquals([self::T0 + 3.5 * self::DAY], $times, 'only the sample inside the window stays');
    }

    public function testTheWindowIsCappedAndNeedsBothReadings(): void
    {
        $d = $this->state(['dims' => ['respect' => 60.0, 'resentment' => 0.0]]);
        $cap = RelationshipDynamics::EMERGENT_EXTRA['window_samples'];
        for ($i = 0; $i < $cap + 6; $i++) {
            $d['dimensions']['resentment']['x'] = (float) $i;
            RelationshipDynamics::noteEmotionWindow($d, self::T0 + $i * 60);
        }
        $this->assertCount($cap, $d[RelationshipDynamics::EMERGENT_WINDOW_KEY]);
        $this->assertEquals((float) ($cap + 5), $d[RelationshipDynamics::EMERGENT_WINDOW_KEY][$cap - 1]['resentment'], 'the newest are kept');

        $e = $this->state();
        $e['dimensions']['respect']['x'] = null;
        $this->assertFalse(RelationshipDynamics::noteEmotionWindow($e, self::T0), 'no respect to sample');
        $this->assertArrayNotHasKey(RelationshipDynamics::EMERGENT_WINDOW_KEY, $e);
        $this->assertFalse(RelationshipDynamics::noteEmotionWindow($d, 0.0), 'no game clock');
    }

    public function testNoWindowIsKeptWithEmergentEmotionsOff(): void
    {
        $this->db->config['emergent_emotions_enabled'] = false;
        RelationshipDynamics::clearConfigCache();
        $d = $this->state();
        $this->assertFalse(RelationshipDynamics::noteEmotionWindow($d, self::T0));
        $this->assertSame([], RelationshipDynamics::detectEmergentEmotions($this->state(self::draft()['insecurity']['match'])));
    }

    // ------------------------------------------------------------------ the two that speak

    /** A state that is three of them: insecurity, codependency and suffocation. */
    private static function crowded(): array
    {
        return ['dims' => ['affinity' => 95.0, 'maturity' => 20.0, 'comfort' => 10.0, 'respect' => 20.0, 'resentment' => 60.0],
                'baseline' => ['comfort' => 10.0],
                'extra' => ['_emotion_window' => [['t' => self::T0 - 6 * self::HOUR, 'respect' => 20.0, 'resentment' => 40.0], ['t' => self::T0, 'respect' => 20.0, 'resentment' => 60.0]]]];
    }

    public function testTheDeepestOnesLeadAndOnlyTwoSpeak(): void
    {
        $d = $this->state(self::crowded());
        $found = RelationshipDynamics::detectEmergentEmotions($d);
        $this->assertContains('insecurity', $found);
        $this->assertContains('codependency', $found);
        $this->assertContains('suffocation', $found);
        $this->assertGreaterThanOrEqual(3, count($found));

        $text = RelationshipDynamics::generateEmergentEmotionContext('Muiri', $found);
        $spoken = 0;
        foreach (RelationshipDynamics::EMERGENT_EMOTIONS as $id => $spec) {
            if (str_contains($text, str_replace('{NAME}', 'Muiri', $spec['context']))) $spoken++;
        }
        $this->assertSame(2, $spoken, "two of " . json_encode($found) . ":\n{$text}");
        $first = str_replace('{NAME}', 'Muiri', RelationshipDynamics::EMERGENT_EMOTIONS[$found[0]]['context']);
        $this->assertStringStartsWith($first, $text, 'the deepest leads');
        // deterministic: the same state reads the same way
        $this->assertSame($found, RelationshipDynamics::detectEmergentEmotions($this->state(self::crowded())));
    }

    public function testEveryContextIsWhatSheDoesNeverANumberOrALabel(): void
    {
        foreach (RelationshipDynamics::EMERGENT_EMOTIONS as $id => $spec) {
            $this->assertStringContainsString('{NAME}', $spec['context'], $id);
            $text = str_replace('{NAME}', 'Muiri', $spec['context']);
            $this->assertDoesNotMatchRegularExpression('/\d/', $text, "{$id}: a number reached the LLM");
            $this->assertDoesNotMatchRegularExpression('/\b(is|feels|has) (infatuat|contempt|insecur|lonel|codependen|suffocat|an imposter|narcissis|addict)|\bsyndrome\b|\bcollapse\b|feels trapped|This is/i', $text, "{$id}: a label, not behavior");
            $this->assertGreaterThanOrEqual(2, count(preg_split('/[,:;]/', $text)), "{$id}: a few behaviors");
        }
    }

    // ------------------------------------------------------------------ the felt line

    /** What compose() puts in its 'emergent' line for $d (the player speaking), null for none. */
    private function felt(array &$d): ?string
    {
        $c = RelDynFelt::compose('Muiri', 'Kaida', $d, self::T0, ['player_addressed' => true]);
        return array_column($c['lines'], 'text', 'key')['emergent'] ?? null;
    }

    public function testTheFeltLineCarriesAtMostTwoAndSamplesTheWindow(): void
    {
        $d = $this->state(self::crowded());
        unset($d[RelationshipDynamics::EMERGENT_WINDOW_KEY]);
        $first = $this->felt($d);
        $this->assertNotNull($first, 'insecurity and codependency do not need the window');
        $first = str_replace('this stranger', 'the player', $first);   // her tier-0 name for the one she feels it toward
        $this->assertNotEmpty($d[RelationshipDynamics::EMERGENT_WINDOW_KEY] ?? null, 'the compose took a sample');
        $spoken = 0;
        foreach (RelationshipDynamics::EMERGENT_EMOTIONS as $spec) {
            if (str_contains($first, str_replace('{NAME}', 'Muiri', $spec['context']))) $spoken++;
        }
        $this->assertSame(2, $spoken, $first);
        $this->assertDoesNotMatchRegularExpression('/\d/', $first);
    }

    public function testTheRomanticOnesSpeakOnlyInsideARomance(): void
    {
        $infatuated = ['dims' => ['passion' => 75.0, 'trust' => 30.0, 'maturity' => 30.0]];
        $platonic = $this->state($infatuated);
        $platonic['_attraction'] = ['enabled' => true, 'hard_zero' => true];   // not that kind of pull
        $this->assertContains('infatuation', RelationshipDynamics::detectEmergentEmotions($platonic), 'she is still infatuated by the dimensions');
        $this->assertNull($this->felt($platonic), 'but a romantic read does not belong to a bond that is not one');

        $romance = $this->state($infatuated);
        $line = $this->felt($romance);
        $this->assertNotNull($line);
        $this->assertStringContainsString('idealizes', $line);

        // a core romance reads as one whatever the passion
        $partner = $this->state(['dims' => ['passion' => 75.0, 'trust' => 30.0, 'maturity' => 30.0], 'extra' => ['_core_rel_type' => 'romantic']]);
        $partner['_attraction'] = ['enabled' => true, 'hard_zero' => true];
        $this->assertNotNull($this->felt($partner));

        // the ones that are not romantic-only still speak between friends
        $friend = $this->state(self::draft()['insecurity']['match']);
        $this->assertNotNull($this->felt($friend));
    }
    // ------------------------------------------------------------------ a backstory goal keeps its place

    private static function felt1(string $key, float $sal, string $text, bool $keep = false): array
    {
        return ['key' => $key, 'scope' => RelDynFelt::SCOPE_SELF, 'lane' => RelDynFelt::LANE_TURN, 'salience' => $sal, 'must' => false,
                'keep' => $keep, 'intense' => false, 'handwritten' => false, 'tier0' => false, 'tag' => null, 'text' => $text];
    }

    public function testALineThatKeepsItsPlaceSurvivesTheCapAndTheBudgetUntilNothingElseIsLeft(): void
    {
        // a new, more salient composite must not push a persistent backstory goal out of the prompt
        $cfg = RelDynFelt::config();
        $cfg['tier_max_lines'] = [2 => 3];
        $cfg['tier_token_budget'] = [2 => 100000];
        $lines = [self::felt1('a', 0.9, 'one'), self::felt1('b', 0.8, 'two'), self::felt1('c', 0.7, 'three'), self::felt1('goal', 0.4, 'the old vow', true)];
        $keys = array_column(RelDynFelt::select('Aela', 'Kaida', $lines, 2, [], $cfg), 'key');
        $this->assertSame(['a', 'b', 'c', 'goal'], $keys, 'the goal keeps a place beside the cap');
        // the token budget cuts the least salient of the others first
        $cfg['tier_token_budget'] = [2 => RelDynFelt::estimateTokens((string) RelDynFelt::renderSubtext('Aela', 'Kaida', [$lines[0], $lines[3]], $cfg))];
        $keys = array_column(RelDynFelt::select('Aela', 'Kaida', $lines, 2, [], $cfg), 'key');
        $this->assertSame(['a', 'goal'], $keys, 'c and b go before the goal does');
        // and a line that does not keep its place still goes first, as before
        $lines[3]['keep'] = false;
        $keys = array_column(RelDynFelt::select('Aela', 'Kaida', $lines, 2, [], $cfg), 'key');
        $this->assertSame(['a'], array_slice($keys, 0, 1));
        $this->assertNotContains('goal', $keys);
    }
}
