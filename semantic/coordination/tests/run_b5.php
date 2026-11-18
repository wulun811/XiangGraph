<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__, 3) . '/experiments/coord-r0/baselines/bootstrap.php';

use XiangGraph\Coord\EscalationRouter;
use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolContract;
use XiangGraph\Coord\ProtocolPatchValidator;
use XiangGraph\Coord\Replay;
use XiangGraph\CoordR0\BaselineReducer;
use XiangGraph\CoordR0\StrongBaselineReducer;
use XiangGraph\S1\Canonical;

$root = dirname(__DIR__, 3);
$plans = [
    'partial' => Replay::loadPlan($root . '/semantic/coordination/plans/partial-refund.v0.1.json'),
    'closure' => Replay::loadPlan($root . '/semantic/coordination/plans/b5-dependency-closure.v0.1.json'),
    'canary' => Replay::loadPlan($root . '/semantic/coordination/plans/canary-release.v0.1.json'),
];
$planByScenario = [
    'B5-01-overlapping-holds' => 'partial',
    'B5-02-independent-scopes' => 'partial',
    'B5-03-dedupe-and-late-reply' => 'partial',
    'B5-04-missed-escalation' => 'partial',
    'B5-05-dependency-closure' => 'closure',
    'B5-06-canary-frozen-challenge' => 'canary',
];
$passes = 0;
$failures = [];

function check(string $label, bool $condition, mixed $detail = null): void
{
    global $passes, $failures;
    if ($condition) {
        ++$passes;
        echo 'PASS ' . $label . "\n";
        return;
    }
    $failures[] = $label;
    echo 'FAIL ' . $label;
    if ($detail !== null) {
        echo ' :: ' . json_encode($detail, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
    echo "\n";
}

function challenge(string $id): array
{
    global $root;

    return Replay::loadScenario($root . '/semantic/coordination/challenges/' . $id . '.json');
}

function xg(string $id): array
{
    global $plans, $planByScenario;

    return (new Replay())->run($plans[$planByScenario[$id]], challenge($id), 'COORD-B5-XG-' . $id);
}

function xgPartial(string $id): array
{
    global $plans;

    return (new Replay())->run($plans['partial'], challenge($id), 'COORD-B5-XG-' . $id);
}

function baseline(string $id, string $mode): array
{
    global $plans, $planByScenario;
    $scenario = challenge($id);
    $options = ['run_id' => 'COORD-B5-' . $mode . '-' . $id, 'scenario_id' => $id];
    $events = array_values($scenario['events']);
    if ($mode === 'B3') {
        return (new StrongBaselineReducer(new EscalationRouter(), new ProtocolPatchValidator()))->reduce($plans[$planByScenario[$id]], $events, $options);
    }

    return (new BaselineReducer($mode, new EscalationRouter(), new ProtocolPatchValidator()))->reduce($plans[$planByScenario[$id]], $events, $options);
}

function actions(array $step): array
{
    return array_map(static fn (array $action): string => (string) ($action['action'] ?? ''), $step['actions']);
}

function stepBySeq(array $trace, int $seq): ?array
{
    foreach ($trace['steps'] as $step) {
        if ((int) ($step['seq'] ?? 0) === $seq) {
            return $step;
        }
    }

    return null;
}

function holdStatus(array $trace, string $reasonId): ?string
{
    foreach ($trace['state']['holds'] as $hold) {
        if (($hold['reason_id'] ?? '') === $reasonId) {
            return (string) $hold['status'];
        }
    }

    return null;
}

function reasonCodes(array $trace): array
{
    return array_map(static fn (array $rejection): string => (string) $rejection['code'], $trace['rejections']);
}

// 1. Contracts for the B.5 plans.
check('closure plan is valid', ProtocolContract::validate($plans['closure']->data)['valid'] === true);
check('canary plan is valid', ProtocolContract::validate($plans['canary']->data)['valid'] === true);
$cycle = $plans['closure']->data;
$cycle['works'][1]['depends_on'] = ['C'];
check('dependency cycle is rejected', ProtocolContract::validate($cycle)['valid'] === false);
$unknownDependency = $plans['closure']->data;
$unknownDependency['works'][1]['depends_on'] = ['Z'];
check('unknown dependency is rejected', ProtocolContract::validate($unknownDependency)['valid'] === false);
check('canary plan uses exactly the five protocol types', array_values(array_unique(array_column($plans['canary']->data['protocol_composition'], 'protocol'))) === ['P-DEP', 'P-REVIEW', 'P-CONFLICT', 'P-RECOVER', 'P-ESCALATE']);

// 2. B5-01 overlapping holds.
$b501 = xg('B5-01-overlapping-holds');
check('B5-01 has zero Director wakeups', $b501['metrics']['director_wakeups'] === 0, $b501['metrics']);
check('B5-01 dependency hold is released by dependency evidence only', holdStatus($b501, 'dependency:dep-x') === 'RELEASED', $b501['state']['holds']);
check('B5-01 review hold is independent of the dependency hold', holdStatus($b501, 'review-required:review-x') === 'RELEASED', $b501['state']['holds']);
check('B5-01 permit hold is independent of both evidence holds', holdStatus($b501, 'permit:grant-b-1') === 'RELEASED', $b501['state']['holds']);
check('B5-01 publish denied after dependency evidence alone', in_array('DENY_PUBLISH', actions(stepBySeq($b501, 7)), true), stepBySeq($b501, 7));
check('B5-01 publish denied after both evidence items but before the new grant', in_array('DENY_PUBLISH', actions(stepBySeq($b501, 9)), true), stepBySeq($b501, 9));
check('B5-01 publish allowed only after the new grant', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($b501, 11)), true), stepBySeq($b501, 11));
check('B5-01 revoked grant is superseded, not left authoritative', ($b501['grants'][0]['status'] ?? '') === 'SUPERSEDED', $b501['grants']);
check('B5-01 never reports an unsafe allow', $b501['metrics']['unsafe_allows'] === 0);
check('B5-01 never commits publication', $b501['publication_committed'] === false);

// 3. B5-02 independent scopes and policy slices.
$b502 = xg('B5-02-independent-scopes');
check('B5-02 creates exactly two Director requests', $b502['metrics']['director_wakeups'] === 2, $b502['metrics']);
check('B5-02 applies both independent Director patches', \count($b502['patches']) === 2, $b502['patches']);
check('B5-02 does not stale the second request after the first patch', !in_array('REQUEST_STALE', reasonCodes($b502), true), $b502['rejections']);
check('B5-02 second patch gets the next global revision', ($b502['patches'][1]['new_policy_revision'] ?? 0) === 3, $b502['patches']);
check('B5-02 first patch touches only A/B', ($b502['patches'][0]['affected_scope'] ?? []) === ['A', 'B'], $b502['patches'][0] ?? null);
check('B5-02 second patch touches only C/D', ($b502['patches'][1]['affected_scope'] ?? []) === ['C', 'D'], $b502['patches'][1] ?? null);
check('B5-02 policy slices keep A/B at revision 2 and C/D at revision 3', ($b502['state']['policy_slices'] ?? []) === ['A' => 2, 'B' => 2, 'C' => 3, 'D' => 3], $b502['state']['policy_slices']);
check('B5-02 A is publishable and B is held', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($b502, 6)), true) && in_array('DENY_PUBLISH', actions(stepBySeq($b502, 8)), true));
check('B5-02 stalled work is recorded, not hidden', ($b502['metrics']['stalled_work'] ?? []) === ['B', 'D'], $b502['metrics']['stalled_work']);
check('B5-02 necessary escalation recall is complete', (float) ($b502['metrics']['necessary_escalation_recall'] ?? 0) === 1.0, $b502['metrics']);
check('B5-02 has no false escalation', ($b502['metrics']['false_escalations'] ?? null) === [], $b502['metrics']);
check('B5-02 never reports an unsafe allow', $b502['metrics']['unsafe_allows'] === 0);

// 4. B5-03 root-cause dedupe, new facts, scopes, late reply and event-id conflict.
$b503 = xg('B5-03-dedupe-and-late-reply');
check('B5-03 deduplicates a repeated root cause without new facts', $b503['metrics']['deduplicated_requests'] === 1, $b503['metrics']);
check('B5-03 attaches new facts to the open request without a new wakeup', $b503['metrics']['updated_existing_requests'] === 1, $b503['metrics']);
check('B5-03 marks the updated request for re-answer', (bool) ($b503['steps'][3]['escalation']['fact_epoch'] ?? 0) > 0, $b503['steps'][3]['escalation'] ?? null);
check('B5-03 new facts are stored on the request', in_array('fact-2', $b503['director_requests'][0]['facts'] ?? [], true), $b503['director_requests'][0] ?? null);
check('B5-03 a new fact after the answer creates a new request', ($b503['metrics']['director_wakeups'] ?? 0) === 4 && ($b503['director_requests'][1]['previous_request_id'] ?? '') === ($b503['director_requests'][0]['request_id'] ?? 'x'), $b503['director_requests']);
check('B5-03 same root cause on a different scope is not merged', \count(array_filter($b503['director_requests'], static fn (array $r): bool => ($r['root_cause'] ?? '') === 'rc-1' && ($r['scope'] ?? []) === ['B', 'D'])) === 1, $b503['director_requests']);
check('B5-03 timeout closes the request and holds the scope', ($b503['director_requests'][3]['status'] ?? '') === 'TIMED_OUT' && $b503['state']['works']['A']['publish_held'] === true, $b503['director_requests'][3] ?? null);
check('B5-03 late reply is rejected after timeout', $b503['metrics']['late_responses_rejected'] === 1 && in_array('REQUEST_NOT_OPEN', reasonCodes($b503), true), $b503['metrics']);
check('B5-03 event-id reuse with a different payload is a conflict, not a silent duplicate', $b503['metrics']['duplicate_event_conflicts'] === 1 && in_array('EVENT_ID_PAYLOAD_CONFLICT', reasonCodes($b503), true), $b503['metrics']);
check('B5-03 conflicting duplicate does not change state', ($b503['steps'][10]['state_digest'] ?? 'a') === ($b503['steps'][9]['state_digest'] ?? 'b'), [$b503['steps'][9]['state_digest'] ?? null, $b503['steps'][10]['state_digest'] ?? null]);
check('B5-03 never reports an unsafe allow', $b503['metrics']['unsafe_allows'] === 0);

// 5. B5-04 missed escalation and untrusted wake flag.
$b504 = xg('B5-04-missed-escalation');
check('B5-04 mislabeled strategic progress creates exactly one escalation', $b504['metrics']['director_wakeups'] === 1, $b504['metrics']);
check('B5-04 emits UNKNOWN_REQUIRES_REVIEW', in_array('UNKNOWN_REQUIRES_REVIEW', actions(stepBySeq($b504, 2)), true), stepBySeq($b504, 2));
check('B5-04 creates a classification obligation', ($b504['metrics']['unverified_classification_held'] ?? 0) === 1, $b504['metrics']);
check('B5-04 publish is denied before any evidence', in_array('DENY_PUBLISH', actions(stepBySeq($b504, 3)), true));
check('B5-04 classification evidence alone does not release the Director decision hold', in_array('DENY_PUBLISH', actions(stepBySeq($b504, 5)), true), stepBySeq($b504, 5));
check('B5-04 a Director answer releases only its decision hold; the patch hold remains', holdStatus($b504, 'classification-decision:classification:b8a3eb52f8') === 'RELEASED' && $b504['state']['works']['B']['publish_held'] === true, $b504['state']['holds']);
check('B5-04 a bare requires_director flag is rejected as a proposal', $b504['metrics']['rejected_unnecessary_escalations'] === 1 && \count($b504['director_requests']) === 1, $b504['metrics']);
check('B5-04 never reports an unsafe allow', $b504['metrics']['unsafe_allows'] === 0);
check('B5-04 never commits publication', $b504['publication_committed'] === false);

// 6. B5-05 transitive dependency closure.
$b505 = xg('B5-05-dependency-closure');
check('B5-05 expands a change on B to C and D', ($b505['steps'][1]['event_scope'] ?? []) === ['B', 'C', 'D'], $b505['steps'][1]);
check('B5-05 records the closure expansion', $b505['metrics']['closure_expansions'] === 1, $b505['metrics']);
check('B5-05 invalidates only the dependent closure', ($b505['steps'][1]['invalidations'][0]['scope'] ?? []) === ['B', 'C', 'D'], $b505['steps'][1]['invalidations']);
check('B5-05 A is unaffected by the B failure', $b505['state']['works']['A']['publish_held'] === false, $b505['state']['works']);
check('B5-05 D publication is denied through indirect dependency', in_array('DENY_PUBLISH', actions(stepBySeq($b505, 3)), true), stepBySeq($b505, 3));
check('B5-05 B-only evidence discharges no closure obligation', (($b505['steps'][3]['actions'][0]['discharged_obligations'] ?? null) === []), $b505['steps'][3]['actions'][0] ?? null);
check('B5-05 B-only evidence still cannot publish D', in_array('DENY_PUBLISH', actions(stepBySeq($b505, 5)), true), stepBySeq($b505, 5));
check('B5-05 full closure evidence releases D', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($b505, 7)), true), stepBySeq($b505, 7));
check('B5-05 never reports an unsafe allow', $b505['metrics']['unsafe_allows'] === 0);

// 7. B5-06 frozen canary challenge.
$b506 = xg('B5-06-canary-frozen-challenge');
check('B5-06 uses the frozen five-protocol composer', (new XiangGraph\Coord\ProtocolComposer())->protocolTypes() === ['P-DEP', 'P-REVIEW', 'P-CONFLICT', 'P-RECOVER', 'P-ESCALATE']);
check('B5-06 escalates the threshold change and the full-rollout conflict', $b506['metrics']['director_wakeups'] === 2, $b506['metrics']);
check('B5-06 resolves the rollback/threshold conflict locally', $b506['metrics']['director_wakeups'] === 2 && !in_array('rollback-vs-threshold', array_column($b506['director_requests'], 'root_cause'), true), $b506['director_requests']);
check('B5-06 first Director answer is blocked by the human production gate', in_array('HUMAN_AUTHORIZATION_REQUIRED', reasonCodes($b506), true), $b506['rejections']);
check('B5-06 second Director answer is accepted only after the human grant', ($b506['patches'][1]['option'] ?? '') === 'prioritize-first', $b506['patches']);
check('B5-06 threshold patch propagates to R2/R3/R4', ($b506['patches'][0]['propagated_scope'] ?? []) === ['R2', 'R3', 'R4'], $b506['patches'][0] ?? null);
check('B5-06 R1 is publishable after the decision', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($b506, 14)), true), stepBySeq($b506, 14));
check('B5-06 R2 is held by the explicit patch hold', in_array('DENY_PUBLISH', actions(stepBySeq($b506, 15)), true), stepBySeq($b506, 15));
check('B5-06 R4 is denied through the R2 dependency', in_array('DENY_PUBLISH', actions(stepBySeq($b506, 16)), true), stepBySeq($b506, 16));
check('B5-06 never reports an unsafe allow', $b506['metrics']['unsafe_allows'] === 0);
check('B5-06 never commits publication', $b506['publication_committed'] === false);
check('B5-06 necessary escalation recall is complete', (float) ($b506['metrics']['necessary_escalation_recall'] ?? 0) === 1.0, $b506['metrics']);
check('B5-06 has no missed or false escalation', ($b506['metrics']['missed_escalations'] ?? null) === [] && ($b506['metrics']['false_escalations'] ?? null) === [], $b506['metrics']);
check('B5-06 does not measure model tokens', array_key_exists('model_tokens', $b506['measurement']) && $b506['measurement']['model_tokens'] === null, $b506['measurement'] ?? null);
check('B5-06 records stalls instead of hiding them', ($b506['metrics']['stalled_work'] ?? []) === ['R2', 'R3', 'R4'], $b506['metrics']['stalled_work']);

// 8. Policy-slice precision: a same-scope change must stale the request; an
// unrelated scope must not.
$sliceTrace = xgPartial('B5-02b-same-scope-stale');
check('a same-scope patch stales the other open request', in_array('REQUEST_STALE', reasonCodes($sliceTrace), true), $sliceTrace['rejections']);
check('the stale request cannot produce a second patch', \count($sliceTrace['patches']) === 1, $sliceTrace['patches']);
check('the slice change is local to C', ($sliceTrace['state']['policy_slices'] ?? []) === ['A' => 1, 'B' => 1, 'C' => 2, 'D' => 1], $sliceTrace['state']['policy_slices']);

// 9. CONTINUE_SCOPE cannot clear another root cause's safety hold.
$continueTrace = xgPartial('B5-01b-continue-blocked');
check('CONTINUE_SCOPE is blocked by another active hold', ($continueTrace['metrics']['continue_blocked_by_hold'] ?? 0) === 1, $continueTrace['metrics']);
check('CONTINUE_SCOPE leaves the other root cause hold active', $continueTrace['state']['works']['A']['publish_held'] === true, $continueTrace['state']['works']);
check('CONTINUE_SCOPE does not produce an unsafe allow', $continueTrace['metrics']['unsafe_allows'] === 0 && in_array('DENY_PUBLISH', actions(stepBySeq($continueTrace, 5)), true), $continueTrace['metrics']);
$allHoldsHaveIdentity = true;
foreach ($continueTrace['state']['holds'] as $hold) {
    if (($hold['reason_id'] ?? '') === '' || ($hold['owner'] ?? '') === '' || ($hold['condition'] ?? '') === '' || (int) ($hold['version'] ?? 0) < 1) {
        $allHoldsHaveIdentity = false;
    }
}
check('every hold carries reason_id, owner, condition and version', $allHoldsHaveIdentity, $continueTrace['state']['holds']);

// 10. Cross-mode safety and honest baseline positioning.
$allChallenges = array_keys($planByScenario);
foreach ($allChallenges as $id) {
    $xgTrace = xg($id);
    foreach (['B1', 'B2', 'B3'] as $mode) {
        $trace = baseline($id, $mode);
        check($id . ' ' . $mode . ' has no unsafe allow', $trace['metrics']['unsafe_allows'] === 0, $trace['metrics']);
        check($id . ' ' . $mode . ' never commits publication', $trace['publication_committed'] === false);
        check($id . ' ' . $mode . ' never claims a real model', $trace['real_model'] === false);
    }
    check($id . ' XG never reports an unsafe allow', $xgTrace['metrics']['unsafe_allows'] === 0);
    check($id . ' XG never commits publication', $xgTrace['publication_committed'] === false);
}
$b3Canary = baseline('B5-06-canary-frozen-challenge', 'B3');
check('B3 records its local contract rebase instead of pretending it escalated', ($b3Canary['metrics']['contract_rebases_locally'] ?? 0) === 1, $b3Canary['metrics']);
check('B3 still has no unsafe allow on the canary challenge', $b3Canary['metrics']['unsafe_allows'] === 0);

// 9. Determinism.
$b506Again = xg('B5-06-canary-frozen-challenge');
check('B5-06 replay is deterministic', $b506['trace_hash'] === $b506Again['trace_hash'], [$b506['trace_hash'], $b506Again['trace_hash']]);
$b3Again = baseline('B5-06-canary-frozen-challenge', 'B3');
check('B3 replay is deterministic', $b3Canary['trace_hash'] === $b3Again['trace_hash']);

// 10. Measurement shape.
foreach (['model_tokens' => null, 'wall_clock_ms' => null] as $field => $value) {
    check('B5 trace leaves ' . $field . ' unmeasured', array_key_exists($field, $b506['measurement']) && $b506['measurement'][$field] === $value, $b506['measurement'][$field] ?? 'missing');
}
check('B5 trace declares simulated fixed stand-ins', str_contains((string) $b506['measurement']['execution'], 'SIMULATED_EVENTS') && str_contains((string) $b506['measurement']['execution'], 'NOT_REAL_MODEL'));

echo "\n";
if ($failures !== []) {
    echo 'FAILED: ' . \count($failures) . ' check(s) failed, ' . $passes . " passed\n";
    exit(1);
}
echo 'OK: ' . $passes . " coordination B.5 checks passed\n";
