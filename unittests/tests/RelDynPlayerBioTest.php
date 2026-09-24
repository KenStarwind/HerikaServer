<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/data_functions.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/** In-memory core_player / conf_opts tables keyed by id. */
final class RelDynBioFakeDb
{
    public array $corePlayer = [];
    public array $confOpts = [];

    public function fetchOne($q)
    {
        if (preg_match("/FROM\s+core_player\s+WHERE\s+id\s*=\s*'([^']+)'/i", $q, $m)) {
            return array_key_exists($m[1], $this->corePlayer) ? ['value' => $this->corePlayer[$m[1]]] : null;
        }
        if (preg_match("/FROM\s+conf_opts\s+WHERE\s+id\s*=\s*'([^']+)'/i", $q, $m)) {
            return array_key_exists($m[1], $this->confOpts) ? ['value' => $this->confOpts[$m[1]]] : null;
        }
        return null;
    }
    public function fetchAll($q) { $row = $this->fetchOne($q); return $row ? [$row] : []; }
    public function execQuery($q) { return true; }
    public function query($q) { return true; }
    public function escape($s) { return str_replace("'", "''", (string)$s); }
    public function escapeLiteral($s) { return "'" . $this->escape($s) . "'"; }
}

/**
 * 3.4.1 dropped the PLAYER_BIOS global; the player bio lives in core_player 'bio'
 * (core ResolvePlayerBackstory). RelDyn's appearance lookup must still find it.
 */
final class RelDynPlayerBioTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['db', 'PLAYER_BIOS'] as $k) {
            $this->saved[$k] = array_key_exists($k, $GLOBALS) ? [$GLOBALS[$k]] : null;
            unset($GLOBALS[$k]);
        }
        $GLOBALS['db'] = new RelDynBioFakeDb();
        RelationshipDynamics::clearConfigCache();
        Logger::setCustomLog(sys_get_temp_dir() . '/reldyn_bio_test.log');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v[0];
            }
        }
        RelationshipDynamics::clearConfigCache();
        Logger::unsetCustomLog();
    }

    public function testFallsBackToCorePlayerBioWithoutPlayerBiosGlobal(): void
    {
        $GLOBALS['db']->corePlayer['bio'] = 'A Nord hunter with a scarred jaw.';
        $this->assertSame('A Nord hunter with a scarred jaw.', RelationshipDynamics::getPlayerAppearance());
    }

    public function testAppearanceRowStillWins(): void
    {
        $GLOBALS['db']->corePlayer['appearance'] = 'Tall, braided red hair.';
        $GLOBALS['db']->corePlayer['bio'] = 'A Nord hunter.';
        $this->assertSame('Tall, braided red hair.', RelationshipDynamics::getPlayerAppearance());
    }

    public function testEmptyWhenNothingKnown(): void
    {
        $this->assertSame('', RelationshipDynamics::getPlayerAppearance());
    }
}
