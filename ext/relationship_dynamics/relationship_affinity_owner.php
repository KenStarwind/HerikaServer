<?php
/**
 * Relationship Dynamics — registration for the CHIM fork hook in core's relationship eval
 * (ext/relationship_system/relationship_llm.php, chimRelationshipAffinityOwned()).
 *
 * Core loads this file itself (every ext/<name>/relationship_affinity_owner.php), also in its
 * standalone relationship worker, which loads no other extension file. The RelDyn classes
 * load only when core asks. RelDynEval::ownsCoreAffinity() decides: the Player target while
 * RelDyn, the dimension engine and the RelDyn eval are on.
 */

$GLOBALS['CHIM_RELATIONSHIP_AFFINITY_OWNERS']['reldyn'] = static function (int $npcId, string $target): bool {
    require_once __DIR__ . '/eval_producer.php';
    return RelDynEval::ownsCoreAffinity($npcId, $target);
};
