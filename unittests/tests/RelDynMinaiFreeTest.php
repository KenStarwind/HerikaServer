<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * minai-sensor-bridge rule (feedback_minai_role, decisions §6): RelDyn reads CHIM core data,
 * never MinAI. No _minai_ conf_opts keys, no minai_items / minai_combat_ tables or rows, no
 * calls to MinAI's GetActorValue / IsEnabled / IsInFaction, no MinAI event types in logic.
 * The one MinAI name left is 'minai_force_rechat' in the radiant skip lists: skipping a
 * MinAI event if one ever arrives reads nothing from MinAI.
 */
final class RelDynMinaiFreeTest extends TestCase
{
    private const ALLOWED_STRINGS = ["'minai_force_rechat'"];

    public function testNoMinaiReadsOrCallsInRelDyn(): void
    {
        $found = [];
        foreach (glob(__DIR__ . '/../../ext/relationship_dynamics/*.php') as $file) {
            $toks = token_get_all(file_get_contents($file));
            foreach ($toks as $i => $t) {
                if (!is_array($t)) continue;
                [$id, $text, $line] = $t;
                $where = basename($file) . ':' . $line;
                if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && stripos($text, 'minai') !== false && !in_array($text, self::ALLOWED_STRINGS, true)) {
                    $found[] = "{$where} string {$text}";
                }
                if ($id === T_STRING && in_array(strtolower($text), ['getactorvalue', 'isenabled', 'isinfaction'], true)) {
                    // A call of the global function: not a method (::isEnabled / ->x) or a declaration.
                    $prev = null;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) continue;
                        $prev = $toks[$j];
                        break;
                    }
                    $isMember = is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION], true);
                    if (!$isMember) $found[] = "{$where} MinAI function {$text}";
                }
            }
        }
        $this->assertSame([], $found, 'MinAI reads left in RelDyn');
    }
}
