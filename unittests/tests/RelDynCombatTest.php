<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../ext/relationship_dynamics/relationship_dynamics.php';

/**
 * combat-passion, the pure parts: who a CHIM 3.4.1 core combat row names (core's own strings:
 * main.php death / bleedout, Papyrus RecoverFromCombat's instruction, the RPG combat end), who is
 * around (core's people string, core's party JSON), the config it runs on, and the
 * post-combat glow that lasts five minutes of play, not forever.
 */
final class RelDynCombatTest extends TestCase
{
    private const PLAYER = 'Kaida';

    public function testAKillByAnNpcNamesTheKillerAsParticipantAndTheVictim(): void
    {
        [$direct, $killer, $victim] = RelDynCombat::parse('Aela the Huntress has defeated Bandit Chief with Ancient Nord Bow in an awesome move.', self::PLAYER);
        $this->assertSame(['Aela the Huntress'], $direct);
        $this->assertSame('Aela the Huntress', $killer);
        $this->assertSame('Bandit Chief', $victim);
    }

    public function testThePlayersKillHasNoNpcParticipant(): void
    {
        [$direct, $killer, $victim] = RelDynCombat::parse('Kaida has defeated Frost Troll', self::PLAYER);
        $this->assertSame([], $direct, 'the RelDyn NPCs around are witnesses, not the killer');
        $this->assertSame('Kaida', $killer);
        $this->assertSame('Frost Troll', $victim);
    }

    public function testFallsAndLostCombatNameTheFallenNpc(): void
    {
        $this->assertSame(['Lynly Star-Sung'], RelDynCombat::parse('Lynly Star-Sung falls to the ground almost unconscious', self::PLAYER)[0]);
        $this->assertSame(['Lynly Star-Sung'], RelDynCombat::parse('Lynly Star-Sung has lost combat and is wounded bleedingout.', self::PLAYER)[0]);
        $this->assertSame([], RelDynCombat::parse('Kaida falls to the ground almost unconscious', self::PLAYER)[0], 'the player is not an NPC');
    }

    public function testNarratorPrefixAndTeamUp(): void
    {
        $this->assertSame(['Muiri'], RelDynCombat::parse('The Narrator: Muiri is teamed up with Kaida against a wolf', self::PLAYER)[0]);
        $this->assertSame([], RelDynCombat::parse('(Context location: Whiterun) The fight is over', self::PLAYER)[0]);
    }

    public function testPeopleAndPartyNames(): void
    {
        $this->assertSame(['Ashe', 'Muiri', 'Kaida'], RelDynCombat::names('|Ashe|Muiri||Kaida|Ashe|'));
        $this->assertSame([], RelDynCombat::names(''));
        $this->assertSame(['Aela the Huntress', 'Ashe'], RelDynCombat::partyNames('{"Aela the Huntress":{"name":"Aela the Huntress"},"Ashe":{}}'));
        $this->assertSame([], RelDynCombat::partyNames('not json'));
        $this->assertSame([], RelDynCombat::partyNames(null));
    }

    public function testConfigDefaultsAreTheAprilRulesAndShipInTheConfig(): void
    {
        $d = RelDynCombat::configDefaults();
        $this->assertSame(0.5, $d['witness_mult']);
        $this->assertSame(1.3, $d['shared_mult']);
        $this->assertSame(1.5, $d['danger_mult']);
        $this->assertSame(0.5, $d['streak_per_kill']);
        $this->assertSame(2.0, $d['streak_cap']);
        $this->assertTrue($d['consume_eventlog']);
        $this->assertSame($d, RelationshipDynamics::defaultConfig()['combat'], 'the shipped config carries the combat block');
        $this->assertSame(RelationshipDynamics::CORE_COMBAT_REQUEST_TYPES, RelDynCombat::REQUEST_TYPES);
    }

    public function testThePostCombatGlowWindowIsFiveMinutesOfPlay(): void
    {
        // The glow window is the kill-streak window: 300 real seconds of play on the game clock
        $this->assertSame(300 * RelationshipDynamics::GAMETS_PER_REAL_SECOND, RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS);
        $this->assertSame(RelationshipDynamics::COMBAT_KILL_STREAK_WINDOW_GAMETS, RelationshipDynamics::POST_COMBAT_GLOW_GAMETS);
    }
}
