<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Config row only (conf_opts): everything else reads as absent. */
final class RelDynAutonomyFilterConfigDb
{
    public ?string $value = null;
    public function fetchOne($sql, $params = null) { return (strpos((string) $sql, 'conf_opts') !== false && $this->value !== null) ? ['value' => $this->value] : []; }
    public function fetchAll($sql) { return []; }
    public function escape($s) { return addslashes((string) $s); }
}

/**
 * autonomy-command-denial (MDD 6.4, PR16 plan "Action List Filtering", autonomy design memory):
 * a refusing or walking-away NPC's LLM does not get the follow / trade / give actions.
 *
 * On CHIM 3.4.1 the prerequest hooks (main.php:1125) run before prompt.includes.php (main.php:1634)
 * defines unsetFunction() and loads the action list, and functions/functions.php rebuilds
 * ENABLED_FUNCTIONS from the action catalog anyway. The one point where a plugin can take an
 * action off is core's ext/<plugin>/functions.php scan: after the enabled codes are loaded, before
 * core drops every function definition whose code is not enabled. RelDyn's hook
 * (ext/relationship_dynamics/functions.php) removes the codes the prerequest's autonomy
 * evaluation denied, for that request's NPC only. The denied lists, weights and thresholds are
 * config ('autonomy').
 */
final class RelDynAutonomyActionFilterTest extends TestCase
{
    private const CORE_LIST = ['MoveTo', 'OpenInventory', 'OpenInventory2', 'Attack', 'Follow', 'Inspect', 'TravelTo',
        'FollowPlayer', 'ComeCloser', 'ReturnBackHome', 'GiveGoldTo', 'GiveItemTo', 'MakeFollower', 'EndConversation',
        'IncreaseWalkSpeed', 'DecreaseWalkSpeed'];

    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'HERIKA_NAME', 'ENABLED_FUNCTIONS', 'RELDYN_AUTONOMY_EVAL', 'RELDYN_AUTONOMY_NPC'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        RelationshipDynamics::clearConfigCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) unset($GLOBALS[$k]); else $GLOBALS[$k] = $v[0];
        }
        RelationshipDynamics::clearConfigCache();
    }

    /** core functions/functions.php's scan: require_once of every ext functions.php, global scope. */
    private function runHook(): void
    {
        (static function (): void { require __DIR__ . '/../../ext/relationship_dynamics/functions.php'; })();
    }

    private static function npc(array $dims, array $extra = []): array
    {
        $d = $extra + ['inferred_temperament' => 'Stoic', 'profile_overrides' => ['attachment_style' => 'secure'], 'dimensions' => []];
        foreach ($dims + ['maturity' => 60.0, 'self_confidence' => 60.0, 'trust' => 50.0, 'respect' => 50.0,
                          'comfort' => 50.0, 'resentment' => 0.0] as $dim => $x) {
            $d['dimensions'][$dim] = ['x' => $x, 'baseline' => $x];
        }
        return $d;
    }

    public function testCoreLoadsPluginFunctionHooksBetweenTheEnabledListAndItsFilter(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../functions/functions.php');
        $loaded = strpos($src, '$GLOBALS["ENABLED_FUNCTIONS"] = herikaActionCatalogDbReady()');
        $scan = strpos($src, 'requireFunctionFilesRecursively($folderPath);');
        $filter = strpos($src, '// Delete non wanted functions');
        $this->assertNotFalse($loaded);
        $this->assertNotFalse($scan);
        $this->assertNotFalse($filter);
        $this->assertTrue($loaded < $scan && $scan < $filter, 'enabled codes loaded, then the ext functions.php hooks, then the filter');
        $main = (string) file_get_contents(__DIR__ . '/../../main.php');
        $this->assertLessThan(strpos($main, 'prompt.includes.php'), strpos($main, '"prerequest.php"'),
            'the autonomy evaluation (prerequest) runs before the action list is built');
    }

    public function testARefusingNpcLosesTheFollowTradeAndGiveActions(): void
    {
        $eval = RelationshipDynamics::evaluateAutonomyState(self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0]), 'Stoic');
        $this->assertSame('refusing', $eval['state']);
        $GLOBALS['HERIKA_NAME'] = 'Aela the Huntress';
        $GLOBALS['RELDYN_AUTONOMY_NPC'] = 'Aela the Huntress';
        $GLOBALS['RELDYN_AUTONOMY_EVAL'] = $eval;
        $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_LIST;
        $this->runHook();
        $left = $GLOBALS['ENABLED_FUNCTIONS'];
        foreach (['FollowPlayer', 'Follow', 'MakeFollower', 'OpenInventory', 'OpenInventory2', 'GiveItemTo', 'GiveGoldTo'] as $code) {
            $this->assertNotContains($code, $left, $code);
        }
        foreach (['EndConversation', 'ReturnBackHome', 'TravelTo', 'Attack', 'ComeCloser'] as $code) {
            $this->assertContains($code, $left, "{$code}: she can still leave, end it or defend herself");
        }
        $this->assertSame(array_values($left), $left, 'a list, as core keeps it');
    }

    public function testACompliantNpcAndAnotherSpeakerKeepEveryAction(): void
    {
        $GLOBALS['ENABLED_FUNCTIONS'] = self::CORE_LIST;
        $GLOBALS['HERIKA_NAME'] = 'Muiri';
        $GLOBALS['RELDYN_AUTONOMY_NPC'] = 'Muiri';
        $GLOBALS['RELDYN_AUTONOMY_EVAL'] = RelationshipDynamics::evaluateAutonomyState(self::npc(['trust' => 80.0, 'respect' => 80.0]), 'Stoic');
        $this->assertSame('compliant', $GLOBALS['RELDYN_AUTONOMY_EVAL']['state']);
        $this->runHook();
        $this->assertSame(self::CORE_LIST, $GLOBALS['ENABLED_FUNCTIONS']);

        // The evaluation was for Muiri; the request now speaks as someone else (core re-synced
        // the profile, e.g. to the Narrator): her denial is not theirs
        $GLOBALS['RELDYN_AUTONOMY_EVAL'] = RelationshipDynamics::evaluateAutonomyState(self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0]), 'Stoic');
        $GLOBALS['HERIKA_NAME'] = 'The Narrator';
        $this->runHook();
        $this->assertSame(self::CORE_LIST, $GLOBALS['ENABLED_FUNCTIONS']);

        // No RelDyn evaluation this request (radiant, disabled, another plugin's page): untouched
        unset($GLOBALS['RELDYN_AUTONOMY_EVAL'], $GLOBALS['RELDYN_AUTONOMY_NPC']);
        $GLOBALS['HERIKA_NAME'] = 'Muiri';
        $this->runHook();
        $this->assertSame(self::CORE_LIST, $GLOBALS['ENABLED_FUNCTIONS']);
    }

    public function testAPeoplePleaserSwallowsTheRefusalAndKeepsTheActions(): void
    {
        $eval = RelationshipDynamics::evaluateAutonomyState(self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0,
            'self_confidence' => 20.0, 'maturity' => 30.0]), 'Anxious');
        $this->assertSame('compliant', $eval['state']);
        $this->assertTrue($eval['swallowed'], 'the refusal is internalized (resentment_self), not acted on');
        $this->assertSame([], $eval['deny_actions']);
    }

    public function testDeniedListsWeightsAndThresholdsAreConfig(): void
    {
        $defaults = RelationshipDynamics::defaultConfig()['autonomy'];
        $this->assertSame(['FollowPlayer', 'Follow', 'MakeFollower', 'OpenInventory', 'OpenInventory2', 'GiveItemTo', 'GiveGoldTo'],
            $defaults['denied_actions']['refusing']);
        $this->assertEqualsWithDelta(0.25, $defaults['weights']['distrust'], 1e-12);
        $this->assertSame(55, $defaults['thresholds']['resistant']);

        $db = new RelDynAutonomyFilterConfigDb();
        $db->value = json_encode(['autonomy' => ['denied_actions' => ['refusing' => ['FollowPlayer']]]]);
        $GLOBALS['db'] = $db;
        RelationshipDynamics::clearConfigCache();
        $eval = RelationshipDynamics::evaluateAutonomyState(self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0]), 'Stoic');
        $this->assertSame(['FollowPlayer'], $eval['deny_actions'], 'a stored list replaces that state\'s list');
        $this->assertSame('refusing', $eval['state'], 'weights and thresholds keep their defaults');

        $db->value = json_encode(['autonomy' => ['thresholds' => ['compliant' => 30, 'resistant' => 55, 'refusing' => 60]]]);
        RelationshipDynamics::clearConfigCache();
        $this->assertSame('walkaway', RelationshipDynamics::evaluateAutonomyState(self::npc(['trust' => 0.0, 'respect' => 10.0, 'resentment' => 60.0]), 'Stoic')['state']);
    }
}
