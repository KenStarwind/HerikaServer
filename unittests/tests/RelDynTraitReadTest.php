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
