<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/core/npc_master.class.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** Minimal in-memory `sql` stand-in: only conf_opts reads. */
final class RelDynRequestScopeFakeDb
{
    public array $confOpts = [];
    public int $configReads = 0;

    public function escape($s) { return str_replace("'", "''", (string) $s); }
    public function execQuery($q) { $this->fetchOne($q); return true; }
    public function fetchAll($q, $log = false) { $r = $this->fetchOne($q); return $r ? [$r] : []; }

    public function fetchOne($q, array $params = [])
    {
        if (preg_match("/FROM conf_opts WHERE id = '([^']+)'/", (string) $q, $m)) {
            if ($m[1] === 'relationship_dynamics_config') {
                $this->configReads++;
            }
            return array_key_exists($m[1], $this->confOpts) ? ['value' => $this->confOpts[$m[1]]] : [];
        }
        return [];
    }
}

/**
 * The config/bond cache may only live inside a request scope. A scope that nobody closes
 * (a hook that returned early, a long-lived process that ran a hook once) must not keep
 * the process on stale config forever.
 */
final class RelDynRequestScopeTest extends TestCase
{
    private RelDynRequestScopeFakeDb $db;
    private $savedDb;

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['db'] ?? null;
        $this->db = new RelDynRequestScopeFakeDb();
        $this->setConfig(['log_enabled' => false]);
        $GLOBALS['db'] = $this->db;
    }

    protected function tearDown(): void
    {
        RelationshipDynamics::endRequest();
        $GLOBALS['db'] = $this->savedDb;
    }

    private function setConfig(array $overrides): void
    {
        $this->db->confOpts['relationship_dynamics_config'] =
            json_encode(array_merge(RelationshipDynamics::defaultConfig(), $overrides));
    }

    public function testClearConfigCacheAlsoClosesAnOpenScope(): void
    {
        // A hook ran earlier in this process and never closed its scope.
        RelationshipDynamics::beginRequest();
        RelationshipDynamics::clearConfigCache();

        $this->assertFalse((bool) RelationshipDynamics::getConfig()['log_enabled']);
        $this->setConfig(['log_enabled' => true]);
        $this->assertTrue((bool) RelationshipDynamics::getConfig()['log_enabled'],
            'after clearConfigCache() the process must not keep caching config');
    }

    public function testAnUnclosedScopeExpires(): void
    {
        RelationshipDynamics::beginRequest();
        RelationshipDynamics::getConfig();
        RelationshipDynamics::getConfig();
        $this->assertSame(1, $this->db->configReads, 'cached inside a live scope');

        // The scope was opened long ago and never closed (hook returned early, worker job).
        $started = new ReflectionProperty(RelationshipDynamics::class, 'requestScopeStartedAt');
        $started->setAccessible(true);
        $started->setValue(null, microtime(true) - RelationshipDynamics::REQUEST_SCOPE_MAX_SECONDS - 1);

        $this->setConfig(['log_enabled' => true]);
        $this->assertTrue((bool) RelationshipDynamics::getConfig()['log_enabled'],
            'an expired scope must not serve cached config');
        $this->assertSame(2, $this->db->configReads);
    }
}
