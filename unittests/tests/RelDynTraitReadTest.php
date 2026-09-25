<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/reldyn_trait_read.php';

/**
 * Personality traits phase 2, the bio read (design §4.2, rulings §16 #4 and #10): the parser
 * and validator, the quote rule, centring, the prompt's inputs, the template key and hash,
 * and the skip list. No LLM: every output here is written by the test.
 * Units: trait values / confidences 0..1 (0.05 steps); maturity_start 0..100.
 */
final class RelDynTraitReadTest extends TestCase
{
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        unset($GLOBALS['db']);
        RelationshipDynamics::clearConfigCache();
        RelDynTraitRead::reset();
    }

    protected function tearDown(): void
    {
        if ($this->savedDb !== null) $GLOBALS['db'] = $this->savedDb;
        RelationshipDynamics::clearConfigCache();
        RelDynTraitRead::reset();
        RelDynTraitRead::$llm = null;
    }

    /** A made-up template (no real bio). */
    private static function fields(): array
    {
        return [
            'personality'    => 'Bryn is a cheerful, open-hearted  smith who trusts easily. She is fiercely protective of her little brother.',
            'relationships'  => '{"Tomas":{"aff":80,"type":"familial","note":"Her younger brother; she worries whenever he goes hunting"}}',
            'npc_static_bio' => 'Born in Riverwood, Bryn took over her father\'s forge after his death and never looked back.',
            'speechstyle'    => 'Speaks loudly and laughs often, with "honest" warmth.',
            'goals'          => '* Protect the village from bandits as her father swore to do',
            'occupation'     => 'Blacksmith of Riverwood.',
            'core'           => 'Director note: secretly plans to leave for Cyrodiil.',
        ];
    }

    private static function entry($value, $conf = 0, $field = null, $evidence = null): array
    {
        return ['value' => $value, 'conf' => $conf, 'field' => $field, 'evidence' => $evidence];
    }

    /** A complete, valid read; $over replaces traits. */
    private static function raw(array $over = [], ?array $maturity = null, array $top = []): string
    {
        $traits = [];
        foreach (RelDynTraitRead::TRAIT_KEYS as $k) $traits[$k] = self::entry(0.5);
        $traits = array_replace($traits, $over);
        $out = ['v' => 1, 'traits' => $traits, 'maturity_start' => $maturity ?? self::entry(55, 0.6, 'npc_static_bio', 'never looked back')];
        return json_encode(array_replace($out, $top));
    }

    public function testValidReadParsesRoundsAndKeepsTheVerbatimQuote(): void
    {
        $raw = self::raw([
            'warmth' => self::entry(0.83, 0.9, 'personality', 'cheerful, open-hearted smith'),
            'protectiveness' => self::entry(0.8, 0.9, 'personality', 'fiercely protective of her little brother'),
            'guard' => self::entry(0.2, 0.72, 'personality', 'trusts easily'),
        ]);
        $r = RelDynTraitRead::parse($raw, self::fields(), $why);
        $this->assertNotNull($r, (string) $why);
        $this->assertSame(0.85, $r['traits']['warmth']['value'], 'rounded to 0.05');
        $this->assertSame(0.9, $r['traits']['warmth']['conf']);
        $this->assertSame('cheerful, open-hearted smith', $r['traits']['warmth']['evidence'], 'whitespace in the bio does not matter');
        $this->assertSame('personality', $r['traits']['warmth']['field']);
        $this->assertSame(0.8, $r['traits']['protectiveness']['value']);
        $this->assertSame(0.2, $r['traits']['guard']['value'], 'extreme kept: conf 0.7 with a real quote');
        $this->assertSame(55.0, $r['maturity_start']['value']);
        $this->assertSame(0.5, $r['traits']['pride']['value']);
        $this->assertSame(0.0, $r['traits']['pride']['conf']);
        $this->assertArrayNotHasKey('note', $r['traits']['warmth']);
    }

    public function testQuoteNotInItsFieldZeroesThatTraitOnly(): void
    {
        $raw = self::raw([
            'warmth' => self::entry(0.7, 0.9, 'personality', 'a kind and gentle soul'),                // invented
            'protectiveness' => self::entry(0.7, 0.8, 'goals', 'fiercely protective of her little brother'),   // wrong field
            'confidence' => self::entry(0.6, 0.6, 'speechstyle', 'Speaks loudly and laughs often'),     // fine
        ]);
        $r = RelDynTraitRead::parse($raw, self::fields(), $why);
        $this->assertNotNull($r, (string) $why);
        foreach (['warmth', 'protectiveness'] as $k) {
            $this->assertSame(0.0, $r['traits'][$k]['conf'], $k);
            $this->assertNull($r['traits'][$k]['evidence'], $k);
            $this->assertSame('quote_mismatch', $r['traits'][$k]['note'], $k);
        }
        $this->assertSame(0.6, $r['traits']['confidence']['conf']);
    }

    public function testQuoteOverTwelveWordsOrWithAnEllipsisIsRejected(): void
    {
        $long = "Born in Riverwood, Bryn took over her father's forge after his death and never";   // 14 words
        $r = RelDynTraitRead::parse(self::raw([
            'resilience' => self::entry(0.7, 0.9, 'npc_static_bio', $long),
            'restraint' => self::entry(0.6, 0.9, 'npc_static_bio', 'took over ... forge'),
        ]), self::fields());
        $this->assertSame(0.0, $r['traits']['resilience']['conf']);
        $this->assertSame(0.0, $r['traits']['restraint']['conf']);
        $this->assertTrue(RelDynTraitRead::quoteMatches("took over her father's forge after his death", self::fields()['npc_static_bio']));
    }

    public function testCoreIsNeverEvidenceAndNeverSent(): void
    {
        $r = RelDynTraitRead::parse(self::raw([
            'guard' => self::entry(0.8, 0.9, 'core', 'secretly plans to leave for Cyrodiil'),
        ]), self::fields());
        $this->assertSame(0.0, $r['traits']['guard']['conf']);
        $this->assertSame('core_field', $r['traits']['guard']['note']);
        $this->assertSame(0.75, $r['traits']['guard']['value'], 'no evidence: centred');
        $msgs = RelDynTraitRead::buildMessages('Bryn', self::fields());
        $all = json_encode($msgs);
        $this->assertStringNotContainsString('Cyrodiil', $all);
        $this->assertStringNotContainsString('[core]', $all);
        $this->assertStringContainsString('[relationships]', $all);
        $this->assertStringContainsString('Character: Bryn', $all);
        $this->assertSame(RelDynTraitRead::FIELDS, ['personality', 'relationships', 'npc_static_bio', 'speechstyle', 'goals', 'occupation']);
    }

    public function testRelationshipsFieldJsonTextIsAccepted(): void
    {
        $r = RelDynTraitRead::parse(self::raw([
            'protectiveness' => self::entry(0.75, 0.8, 'relationships', 'she worries whenever he goes hunting'),
        ]), self::fields());
        $this->assertSame(0.8, $r['traits']['protectiveness']['conf']);
        $this->assertSame('she worries whenever he goes hunting', $r['traits']['protectiveness']['evidence']);
    }

    public function testNormalizationCaseCurlyQuotesAndEdgePunctuation(): void
    {
        $f = self::fields();
        $this->assertTrue(RelDynTraitRead::quoteMatches("\u{201C}Speaks LOUDLY and laughs often,\u{201D}", $f['speechstyle']));
        $this->assertTrue(RelDynTraitRead::quoteMatches("took over her father\u{2019}s forge", $f['npc_static_bio']));
        $this->assertTrue(RelDynTraitRead::quoteMatches('with "honest" warmth', $f['speechstyle']));
        $this->assertFalse(RelDynTraitRead::quoteMatches('ok', $f['speechstyle']), 'too short to be evidence');
        $this->assertFalse(RelDynTraitRead::quoteMatches(null, $f['speechstyle']));
    }

    /** Ruling #10: extremes need strong evidence (conf >= 0.7); otherwise the band edge. */
    public function testReadsCentreUnlessTheEvidenceIsStrong(): void
    {
        $r = RelDynTraitRead::parse(self::raw([
            'warmth' => self::entry(0.95, 0.6, 'personality', 'cheerful, open-hearted smith'),
            'guard' => self::entry(0.05, 0.9, 'personality', 'trusts easily'),
            'pride' => self::entry(0.1, 0.0),
            'reactivity' => self::entry(0.7, 0.3, 'speechstyle', 'laughs often'),
        ], self::entry(95, 0.5, 'npc_static_bio', 'never looked back')), self::fields());
        $this->assertSame(0.75, $r['traits']['warmth']['value']);
        $this->assertSame('centred', $r['traits']['warmth']['note']);
        $this->assertSame(0.05, $r['traits']['guard']['value'], 'strong evidence may go extreme');
        $this->assertSame(0.25, $r['traits']['pride']['value']);
        $this->assertSame(0.7, $r['traits']['reactivity']['value'], 'inside the band: untouched');
        $this->assertSame(70.0, $r['maturity_start']['value'], 'maturity centres the same way (30..70)');
    }

    public function testMalformedOutputFails(): void
    {
        $fields = self::fields();
        $this->assertNull(RelDynTraitRead::parse('no json here', $fields, $why));
        $this->assertSame('no JSON object', $why);
        $missing = json_decode(self::raw(), true);
        unset($missing['traits']['warmth']);
        $this->assertNull(RelDynTraitRead::parse(json_encode($missing), $fields, $why));
        $this->assertSame('trait keys differ', $why);
        $extra = json_decode(self::raw(), true);
        $extra['traits']['charm'] = self::entry(0.5);
        $this->assertNull(RelDynTraitRead::parse(json_encode($extra), $fields, $why));
        $this->assertNull(RelDynTraitRead::parse(self::raw([], null, ['notes' => 'x']), $fields, $why));
        $this->assertStringStartsWith('unexpected keys', $why);
        $this->assertNull(RelDynTraitRead::parse(self::raw(['warmth' => self::entry(1.4, 0.5)]), $fields, $why));
        $this->assertNull(RelDynTraitRead::parse(self::raw(['warmth' => self::entry('high', 0.5)]), $fields, $why));
        $this->assertNull(RelDynTraitRead::parse(self::raw([], null, ['v' => 2]), $fields, $why));
        // code fences and a chatty lead-in around the object are fine
        $this->assertNotNull(RelDynTraitRead::parse("Here you go:\n```json\n" . self::raw() . "\n```", $fields, $why), (string) $why);
    }

    public function testSkipListAlwaysHoldsAsheAndNeverCallsTheLlm(): void
    {
        $this->assertTrue(RelDynTraitRead::isSkipped('ashe'));
        $this->assertTrue(RelDynTraitRead::isSkipped('Ashe'));
        $this->assertFalse(RelDynTraitRead::isSkipped('aela_the_huntress'));
        $cfg = RelDynTraitRead::config();
        $cfg['skip'] = [];   // even a config without her
        $this->assertTrue(RelDynTraitRead::isSkipped('ashe', RelDynTraitRead::config()));
        $called = false;
        $res = RelDynTraitRead::readOnce('ashe', self::fields(), function () use (&$called) { $called = true; return self::raw(); }, RelDynTraitRead::config());
        $this->assertFalse($called);
        $this->assertFalse($res['ok']);
        $this->assertSame('skip-listed', $res['error']);
    }

    public function testReadOnceParsesTheLlmOutputAndReportsItsModel(): void
    {
        $seen = null;
        $res = RelDynTraitRead::readOnce('bryn_the_smith', self::fields(), function (array $messages, array $params) use (&$seen) {
            $seen = [$messages, $params];
            return ['text' => self::raw(['warmth' => self::entry(0.7, 0.9, 'personality', 'cheerful, open-hearted smith')]), 'model' => 'stub-model'];
        }, RelDynTraitRead::config());
        $this->assertTrue($res['ok'], (string) $res['error']);
        $this->assertSame('stub-model', $res['model']);
        $this->assertSame(0.7, $res['result']['traits']['warmth']['value']);
        $this->assertSame('Character: Bryn the Smith', substr($seen[0][1]['content'], 0, 25));
        $this->assertSame(900, $seen[1]['MAX_TOKENS']);
        $bad = RelDynTraitRead::readOnce('bryn_the_smith', self::fields(), fn() => 'sorry', RelDynTraitRead::config());
        $this->assertFalse($bad['ok']);
        $this->assertStringStartsWith('malformed', $bad['error']);
    }

    // ------------------------------------------------------------------ the evidence screen (GATE_V 2)

    /**
     * A made-up template shaped like the live misreads the review found (no real bio): a quest
     * object in the goals, a daughter's entry, a housecarl's oath, a wife's resentment, job text.
     */
    private static function misreadFields(): array
    {
        return [
            'personality'    => 'Hild takes pride in mentoring young hunters. Stern and deeply suspicious of outsiders. '
                              . 'Unwavering loyalty to her order. Deeply protective of her daughter. Jealous of every rival.',
            'relationships'  => json_encode([
                'Mila'   => ['aff' => 95, 'type' => 'familial', 'relation' => 'daughter',
                             'note' => 'Fiercely devoted to raising her alone; refuses to let any man come between them'],
                'Thane'  => ['aff' => 80, 'type' => 'protective', 'relation' => 'housecarl',
                             'note' => 'Sworn to serve and protect with her life after the Jarl appointed her'],
                'Jarl Hrolf' => ['aff' => 60, 'type' => 'professional', 'relation' => 'Jarl and appointer',
                             'note' => 'Trusts his housecarl above all others; she serves his hold with pride'],
                'Ahlam'  => ['aff' => 15, 'type' => 'estranged', 'relation' => 'wife',
                             'note' => 'Marriage of convenience; she openly resents his arrogance',
                             'worst' => 'Her constant complaints about him'],
            ]),
            'npc_static_bio' => 'Born in the Pale.',
            'speechstyle'    => 'Speaks curtly.',
            'goals'          => "* Protect the Skeleton Key\n* Protect Morthal from bandits\n* Maintain order and security",
            'occupation'     => 'Commands all the hold guards.',
        ];
    }

    private static function screened(array $over, ?string $gender = null): array
    {
        $r = RelDynTraitRead::parse(self::raw($over), self::misreadFields(), $why, $gender);
        self::assertNotNull($r, (string) $why);
        return $r;
    }

    /** Rule O: possessiveness is jealousy over a partner; not a quest object, not a daughter. */
    public function testPossessivenessNeedsJealousyOverAPartner(): void
    {
        $r = self::screened(['possessiveness' => self::entry(0.8, 0.9, 'goals', 'protect the Skeleton Key')]);
        $po = $r['traits']['possessiveness'];
        $this->assertSame(0.0, $po['conf'], 'an object is not a partner');
        $this->assertSame('screened', $po['note']);
        $this->assertSame('no_jealousy', $po['screen']['rule']);
        $this->assertSame(['value' => 0.8, 'conf' => 0.9, 'field' => 'goals', 'evidence' => 'protect the Skeleton Key'],
            array_diff_key($po['screen'], ['rule' => 1]), 'what the read said is kept for the report');
        $this->assertSame(0.75, $po['value'], 'no evidence: centred');

        $r = self::screened(['possessiveness' => self::entry(0.8, 0.9, 'relationships', 'refuses to let any man come between them')]);
        $this->assertSame(0.0, $r['traits']['possessiveness']['conf'], "a daughter's entry is not a partner's");
        $this->assertSame('not_a_partner', $r['traits']['possessiveness']['screen']['rule']);

        $r = self::screened(['possessiveness' => self::entry(0.3, 0.3, 'relationships', 'Sworn to serve and protect with her life')]);
        $this->assertSame('no_jealousy', $r['traits']['possessiveness']['screen']['rule'], 'an oath says nothing about jealousy either way');

        $r = self::screened(['possessiveness' => self::entry(0.8, 0.9, 'personality', 'Jealous of every rival')]);
        $this->assertSame(0.8, $r['traits']['possessiveness']['value'], 'real jealousy, from the character: kept');
        $this->assertSame(0.9, $r['traits']['possessiveness']['conf']);
        $this->assertArrayNotHasKey('screen', $r['traits']['possessiveness']);
    }

    /** Rule P: a sworn duty, a post or a place is restraint; worry for loved ones is protectiveness. */
    public function testDutyToAPostOrAPlaceIsNotProtectiveness(): void
    {
        foreach ([
            ['relationships', 'Sworn to serve and protect with her life', 'duty'],
            ['goals', 'Protect Morthal from bandits', 'duty'],
            ['relationships', 'Trusts his housecarl above all others', 'no_care'],
        ] as [$field, $quote, $rule]) {
            $r = self::screened(['protectiveness' => self::entry(0.9, 0.9, $field, $quote)], 'female');
            $this->assertSame(0.0, $r['traits']['protectiveness']['conf'], $quote);
            $this->assertSame($rule, $r['traits']['protectiveness']['screen']['rule'], $quote);
        }
        $r = self::screened(['protectiveness' => self::entry(0.9, 0.9, 'relationships', 'Fiercely devoted to raising her alone')], 'female');
        $this->assertSame(0.9, $r['traits']['protectiveness']['value'], "her daughter's entry: a loved one");
        $this->assertSame(0.9, $r['traits']['protectiveness']['conf']);
        $r = self::screened(['protectiveness' => self::entry(0.9, 0.9, 'personality', 'Deeply protective of her daughter')]);
        $this->assertSame(0.9, $r['traits']['protectiveness']['value']);
        $this->assertArrayNotHasKey('screen', $r['traits']['protectiveness']);
    }

    /** Rule S: a relationships note led by the other person's pronoun is about that person. */
    public function testAQuoteAboutSomeoneElseIsNotEvidence(): void
    {
        foreach (['male', null] as $gender) {
            $r = self::screened([
                'warmth' => self::entry(0.2, 0.9, 'relationships', 'she openly resents his'),
                'reactivity' => self::entry(0.6, 0.6, 'relationships', 'Her constant complaints'),
            ], $gender);
            $this->assertSame(0.0, $r['traits']['warmth']['conf'], "his wife's resentment is not his warmth (gender " . var_export($gender, true) . ')');
            $this->assertSame('someone_else', $r['traits']['warmth']['screen']['rule']);
            $this->assertSame(0.25, $r['traits']['warmth']['value']);
            $this->assertSame(0.0, $r['traits']['reactivity']['conf'], "the wife's complaints");
        }
        // the NPC's own pronoun in a note about her Jarl is her own
        $r = self::screened(['pride' => self::entry(0.65, 0.6, 'relationships', 'she serves his hold with pride')], 'female');
        $this->assertSame(0.6, $r['traits']['pride']['conf']);
        $this->assertArrayNotHasKey('screen', $r['traits']['pride']);
    }

    /** Rules J and D: job text is not evidence; pride in one's work is not ego. */
    public function testJobTextAndPrideInWorkAreNotEvidence(): void
    {
        $r = self::screened([
            'restraint' => self::entry(0.8, 0.9, 'occupation', 'Commands all the hold guards'),
            'pride' => self::entry(0.7, 0.6, 'personality', 'takes pride in mentoring young hunters'),
        ]);
        $this->assertSame(0.0, $r['traits']['restraint']['conf']);
        $this->assertSame('job', $r['traits']['restraint']['screen']['rule']);
        $this->assertSame(0.0, $r['traits']['pride']['conf']);
        $this->assertSame('pride_in_work', $r['traits']['pride']['screen']['rule']);
        $this->assertSame(0.7, $r['traits']['pride']['value'], 'inside the band: the value is left as read (conf 0 ignores it)');
    }

    /**
     * Rule E (ruling #10 in code): an extreme needs strong evidence, not just conf 0.9: a quote
     * from the character (not goals / occupation) that names the quality. Otherwise the band
     * edge at conf 0.6, like any read without strong evidence.
     */
    public function testExtremesNeedAQuoteThatNamesTheQuality(): void
    {
        $r = self::screened([
            'restraint' => self::entry(0.8, 0.7, 'goals', 'Maintain order and security'),
            'confidence' => self::entry(0.8, 0.7, 'personality', 'Unwavering loyalty to her order'),
            'guard' => self::entry(0.8, 0.9, 'personality', 'deeply suspicious of outsiders'),
        ]);
        $this->assertSame(0.75, $r['traits']['restraint']['value']);
        $this->assertSame(0.6, $r['traits']['restraint']['conf'], 'aims are not strong evidence');
        $this->assertSame('extreme_field', $r['traits']['restraint']['screen']['rule']);
        $this->assertSame(0.75, $r['traits']['confidence']['value']);
        $this->assertSame(0.6, $r['traits']['confidence']['conf']);
        $this->assertSame('extreme_cue', $r['traits']['confidence']['screen']['rule'], 'loyalty names no confidence');
        $this->assertSame('centred', $r['traits']['confidence']['note']);
        $this->assertSame(0.8, $r['traits']['guard']['value'], 'suspicious of outsiders: guard, stated outright');
        $this->assertSame(0.9, $r['traits']['guard']['conf']);
        // high protectiveness also needs a loved one: "protect" alone is not "fiercely guards the people they love"
        $r = self::screened(['protectiveness' => self::entry(0.9, 0.9, 'personality', 'Deeply protective of her daughter')]);
        $this->assertSame(0.9, $r['traits']['protectiveness']['value']);
    }

    public function testTheScreenIsIdempotentAndVersioned(): void
    {
        $r = self::screened([
            'possessiveness' => self::entry(0.8, 0.9, 'goals', 'protect the Skeleton Key'),
            'confidence' => self::entry(0.8, 0.7, 'personality', 'Unwavering loyalty to her order'),
            'guard' => self::entry(0.8, 0.9, 'personality', 'deeply suspicious of outsiders'),
        ]);
        $this->assertSame(RelDynTraitRead::GATE_V, $r['gate']);
        $this->assertSame($r, RelDynTraitRead::screen($r, self::misreadFields()), 'screening twice changes nothing');
        // a stored gate-1 result (no screen yet) gets the same answer
        $old = json_decode(json_encode($r), true);
        foreach ($old['traits'] as $k => $t) {
            if (isset($t['screen'])) $old['traits'][$k] = array_diff_key($t['screen'], ['rule' => 1]);
        }
        unset($old['gate']);
        $this->assertEquals($r, RelDynTraitRead::screen($old, self::misreadFields()));
    }

    public function testGenderOfAVoiceType(): void
    {
        $this->assertSame('female', RelDynTraitRead::genderOfVoice('sk_femalecommander'));
        $this->assertSame('female', RelDynTraitRead::genderOfVoice('FemaleEvenToned'));
        $this->assertSame('male', RelDynTraitRead::genderOfVoice('sk_malecondescending'));
        $this->assertNull(RelDynTraitRead::genderOfVoice('sk_serana'));
        $this->assertNull(RelDynTraitRead::genderOfVoice(null));
    }

    public function testTemplateKeyAndHash(): void
    {
        $this->assertSame(['aela_the_huntress'], RelDynTraitRead::keyCandidates('Aela the Huntress'));
        $this->assertSame(['j+zargo', "j'zargo"], RelDynTraitRead::keyCandidates("J'zargo"));
        $this->assertSame(['lynly_star-sung'], RelDynTraitRead::keyCandidates('Lynly Star-Sung'));
        $f = self::fields();
        $h = RelDynTraitRead::srcHash($f);
        $this->assertSame(40, strlen($h));
        $this->assertSame($h, RelDynTraitRead::srcHash($f + ['appearance' => 'tall']), 'only the six read fields count');
        $g = $f;
        $g['core'] = 'something else';
        $this->assertSame($h, RelDynTraitRead::srcHash($g), 'core is not part of the read');
        $g = $f;
        $g['goals'] .= ' And to marry.';
        $this->assertNotSame($h, RelDynTraitRead::srcHash($g), 'a text change is a new read');
        $this->assertSame('Aela the Huntress', RelDynTraitRead::displayName('aela_the_huntress'));
        $this->assertSame('Lynly Star-Sung', RelDynTraitRead::displayName('lynly_star-sung'));
        $this->assertSame("J'zargo", RelDynTraitRead::displayName("j'zargo"));
    }
}
