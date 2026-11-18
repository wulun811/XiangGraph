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
use XiangGraph\S1\Canonical;

$root = dirname(__DIR__, 3);
$planPath = $root . '/semantic/coordination/plans/partial-refund.v0.1.json';
$plan = Replay::loadPlan($planPath);
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

function scenario(string $id): array
{
    global $root;

    return Replay::loadScenario($root . '/semantic/coordination/scenarios/' . $id . '.json');
}

function runXg(Plan $plan, string $id): array
{
    return (new Replay())->run($plan, scenario($id), 'COORD-R0-TEST-20261113-01');
}

function runBaseline(Plan $plan, string $id, string $mode): array
{
    return (new BaselineReducer($mode, new EscalationRouter(), new ProtocolPatchValidator()))->reduce(
        $plan,
        array_values(scenario($id)['events']),
        ['run_id' => 'COORD-R0-TEST-' . $mode, 'scenario_id' => $id]
    );
}

function actionNames(array $trace): array
{
    $names = [];
    foreach ($trace['steps'] as $step) {
        foreach ($step['actions'] as $action) {
            $names[] = $action['action'];
        }
    }

    return $names;
}

function reasonCodes(array $trace): array
{
    return array_map(static fn (array $rejection): string => (string) $rejection['code'], $trace['rejections']);
}

// 1. Plan contract.
$valid = ProtocolContract::validate($plan->data);
check('valid coordination plan is accepted', $valid['valid'] === true, $valid);
$unknownProtocol = $plan->data;
$unknownProtocol['protocol_composition'][0]['protocol'] = 'P-REFUND';
check('unknown protocol type is rejected', ProtocolContract::validate($unknownProtocol)['valid'] === false);
$duplicateWork = $plan->data;
$duplicateWork['works'][] = $duplicateWork['works'][0];
check('duplicate work id is rejected', ProtocolContract::validate($duplicateWork)['valid'] === false);
$missingRisk = $plan->data;
unset($missingRisk['risk_boundary']['production_write']);
check('missing risk boundary key is rejected', ProtocolContract::validate($missingRisk)['valid'] === false);
$missingAuthority = $plan->data;
unset($missingAuthority['authority']);
check('missing authority is rejected', ProtocolContract::validate($missingAuthority)['valid'] === false);
$badEscalation = $plan->data;
$badEscalation['escalation_policy']['director_only_when'][] = 'ORDINARY_PROGRESS';
check('ordinary progress cannot be director-only', ProtocolContract::validate($badEscalation)['valid'] === false);
check('plan hash is deterministic', $plan->hash === (new Plan($plan->data))->hash);

// 2. Composer has five generic protocol types and no business switch.
$composer = new XiangGraph\Coord\ProtocolComposer();
check('composer exposes five protocol types', $composer->protocolTypes() === ['P-DEP', 'P-REVIEW', 'P-CONFLICT', 'P-RECOVER', 'P-ESCALATE']);
$coreFiles = array_merge(
    glob($root . '/semantic/coordination/src/Protocols/*.php') ?: [],
    [
        $root . '/semantic/coordination/src/ProtocolComposer.php',
        $root . '/semantic/coordination/src/EventReducer.php',
        $root . '/semantic/coordination/src/EscalationRouter.php',
        $root . '/semantic/coordination/src/ProtocolPatchValidator.php',
    ]
);
$businessHits = [];
foreach ($coreFiles as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/\b(if|match|switch)\b[^\n]*(refund|canary|symfony)/i', $source) === 1) {
        $businessHits[] = basename($file) . ':business-branch';
    }
    if (preg_match('/scenario_id\s*(===|==|!==|!=)/', $source) === 1) {
        $businessHits[] = basename($file) . ':scenario-id-branch';
    }
}
check('core coordination code has no business-name or scenario-id branch', $businessHits === [], $businessHits);
$networkHits = [];
foreach ($coreFiles as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/\b(curl_|fsockopen|stream_socket|proc_open|shell_exec|exec|popen|HttpClient)\b|->chat\(|model_call/i', $source) === 1) {
        $networkHits[] = basename($file);
    }
}
check('core coordination code has no network, process or model call', $networkHits === [], $networkHits);

// 3. K01/K02 dependency scope.
$k01 = runXg($plan, 'K01-K02-schema-scope');
check('K01/K02 has zero Director wakeups', $k01['metrics']['director_wakeups'] === 0, $k01['metrics']);
check('K01/K02 schema change invalidates only B/C', ($k01['steps'][1]['invalidations'][0]['scope'] ?? []) === ['B', 'C'], $k01['steps'][1]['invalidations'] ?? []);
check('K01/K02 A evidence remains current', ($k01['state']['evidence']['ev-A-schema']['status'] ?? null) === 'CURRENT', $k01['state']['evidence']['ev-A-schema'] ?? null);
check('K01/K02 no unsafe allow', $k01['metrics']['unsafe_allows'] === 0);
check('K01/K02 publish denied before current evidence', in_array('DENY_PUBLISH', actionNames($k01), true));
check('K01/K02 publish allowed after current evidence', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actionNames($k01), true));
check('K01/K02 never commits publication', $k01['publication_committed'] === false);
check('K01/K02 D is never held', $k01['state']['works']['D']['publish_held'] === false);
$wrongClaim = scenario('K01-K02-schema-scope');
$wrongClaim['scenario_id'] = 'wrong-claim';
foreach ($wrongClaim['events'] as &$event) {
    if (($event['event_id'] ?? '') === 'e05') {
        $event['claims'] = ['UNRELATED_CLAIM'];
    }
}
unset($event);
$wrongClaimTrace = (new Replay())->run($plan, $wrongClaim, 'COORD-R0-TEST-CLAIM');
$wrongClaimAllowsBc = false;
foreach ($wrongClaimTrace['steps'] as $step) {
    foreach ($step['actions'] as $action) {
        if (($action['action'] ?? '') === 'ALLOW_PUBLISH_CURRENT_SNAPSHOT' && array_intersect(['B', 'C'], $action['scope'] ?? []) !== []) {
            $wrongClaimAllowsBc = true;
        }
    }
}
check('evidence with an unrelated claim does not discharge the obligation', $wrongClaimAllowsBc === false);
$wrongClaimOpen = false;
foreach ($wrongClaimTrace['state']['obligations'] as $obligation) {
    if (($obligation['kind'] ?? '') === 'REVALIDATE_DEPENDENT' && ($obligation['status'] ?? '') === 'OPEN') {
        $wrongClaimOpen = true;
    }
}
check('unrelated claim leaves REVALIDATE_DEPENDENT open', $wrongClaimOpen);

// 4. K03 bounded local negotiation.
$k03 = runXg($plan, 'K03-local-negotiation');
check('K03 resolves locally with zero Director wakeups', $k03['metrics']['director_wakeups'] === 0, $k03['metrics']);
check('K03 records two negotiation rounds or a resolution', ($k03['negotiations'][0]['rounds'] ?? 0) >= 1 && ($k03['negotiations'][0]['status'] ?? '') === 'RESOLVED', $k03['negotiations']);
check('K03 never creates a Director request', $k03['director_requests'] === []);
check('K03 publish stays non-committed', $k03['publication_committed'] === false);

// 5. K04/K05/K06 Director loop.
$k04 = runXg($plan, 'K04-K05-K06-director-loop');
check('K04 exactly one Director wakeup', $k04['metrics']['director_wakeups'] === 1, $k04['metrics']);
check('K04 duplicate root cause is deduplicated', $k04['metrics']['deduplicated_requests'] === 1, $k04['metrics']);
check('K04 stale response is rejected', $k04['metrics']['stale_responses_rejected'] === 1, $k04['metrics']);
check('K04 unnecessary escalation is rejected', $k04['metrics']['rejected_unnecessary_escalations'] === 1, $k04['metrics']);
check('K04 patch is scoped to B/C', ($k04['patches'][0]['affected_scope'] ?? []) === ['B', 'C'], $k04['patches'][0] ?? null);
check('K04 patch increments policy revision', ($k04['patches'][0]['new_policy_revision'] ?? 0) === 2, $k04['patches'][0] ?? null);
check('K04 patch never commits publication', ($k04['patches'][0]['publication_committed'] ?? true) === false);
check('K04 exhausted negotiation holds publish', in_array('NEGOTIATION_EXHAUSTED', array_column($k04['steps'][3]['holds'] ?? [], 'reason'), true), $k04['steps'][3]['holds'] ?? []);
check('K04 request is answered and not left open', ($k04['director_requests'][0]['status'] ?? '') === 'ANSWERED', $k04['director_requests']);
check('K04 stale response is recorded as a rejection', in_array('REQUEST_NOT_OPEN', reasonCodes($k04), true) || in_array('STALE_POLICY_REVISION', reasonCodes($k04), true), $k04['rejections']);
check('K04 D work is not in the patch scope', !in_array('D', $k04['patches'][0]['affected_scope'], true));
$gap = ['scenario_id' => 'protocol-gap', 'events' => [
    ['event_id' => 'e01', 'seq' => 1, 'type' => 'WORKS_ASSIGNED', 'scope' => ['A', 'B', 'C', 'D']],
    ['event_id' => 'e02', 'seq' => 2, 'type' => 'PROTOCOL_GAP', 'scope' => ['B'], 'root_cause' => 'novel-policy'],
    ['event_id' => 'e03', 'seq' => 3, 'type' => 'PUBLISH_REQUESTED', 'scope' => ['B']],
]];
$gapTrace = (new Replay())->run($plan, $gap, 'COORD-R0-TEST-GAP');
check('protocol gap escalates once and holds publish', $gapTrace['metrics']['director_wakeups'] === 1 && $gapTrace['state']['works']['B']['publish_held'] === true, $gapTrace['metrics']);
check('protocol gap never allows publish', !in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actionNames($gapTrace), true));

// 6. K07 timeout.
$k07 = runXg($plan, 'K07-director-timeout');
check('K07 has exactly one Director wakeup', $k07['metrics']['director_wakeups'] === 1, $k07['metrics']);
check('K07 holds only B/C', $k07['state']['works']['B']['publish_held'] === true && $k07['state']['works']['C']['publish_held'] === true && $k07['state']['works']['D']['publish_held'] === false, $k07['state']['works']);
check('K07 timeout action is recorded', in_array('HOLD_AFFECTED_SCOPE', actionNames($k07), true));
check('K07 request is timed out', ($k07['director_requests'][0]['status'] ?? '') === 'TIMED_OUT', $k07['director_requests']);

// 7. K09 unknown external effect and reconciliation.
$k09 = runXg($plan, 'K09-external-effect-reconcile');
check('K09 has zero Director wakeups', $k09['metrics']['director_wakeups'] === 0, $k09['metrics']);
check('K09 denies a new operation key', $k09['metrics']['blind_retry_denied'] === 1, $k09['metrics']);
check('K09 permits same-key conditional retry', in_array('RETRY_SAME_KEY', actionNames($k09), true));
check('K09 reconciliation discharges the obligation', ($k09['state']['obligations'][array_key_first($k09['state']['obligations'])]['status'] ?? '') === 'DISCHARGED', $k09['state']['obligations']);
check('K09 publish is allowed only after reconciliation', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actionNames($k09), true));
check('K09 never commits publication', $k09['publication_committed'] === false);

// 8. K10 deadlock.
$k10 = runXg($plan, 'K10-deadlock');
check('K10 has exactly one Director wakeup', $k10['metrics']['director_wakeups'] === 1, $k10['metrics']);
check('K10 duplicate deadlock report is deduplicated', $k10['metrics']['deduplicated_requests'] === 1, $k10['metrics']);
check('K10 patch is scoped to B/C', ($k10['patches'][0]['affected_scope'] ?? []) === ['B', 'C']);
check('K10 publish is allowed after scoped evidence', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actionNames($k10), true));

// 8b. K08 revoked permit.
$k08 = runXg($plan, 'K08-revoked-permit');
check('K08 has zero Director wakeups', $k08['metrics']['director_wakeups'] === 0, $k08['metrics']);
check('K08 keeps B/C held after old evidence', $k08['state']['works']['B']['publish_held'] === true && $k08['state']['works']['C']['publish_held'] === true, $k08['state']['works']);
$k08AllowsBc = false;
foreach ($k08['steps'] as $step) {
    foreach ($step['actions'] as $action) {
        if (($action['action'] ?? '') === 'ALLOW_PUBLISH_CURRENT_SNAPSHOT' && array_intersect(['B', 'C'], $action['scope'] ?? []) !== []) {
            $k08AllowsBc = true;
        }
    }
}
check('K08 never allows publication under a revoked permit', $k08AllowsBc === false);
check('K08 D remains publishable', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', array_column(array_merge(...array_map(static fn (array $s): array => $s['actions'], $k08['steps'])), 'action'), true));

// 8c. K11 contract change.
$k11 = runXg($plan, 'K11-contract-change');
check('K11 has exactly one Director wakeup', $k11['metrics']['director_wakeups'] === 1, $k11['metrics']);
check('K11 publish is denied before revalidation', in_array('DENY_PUBLISH', array_column(array_merge(...array_map(static fn (array $step): array => $step['actions'], array_slice($k11['steps'], 0, 3))), 'action'), true));
check('K11 patch is scoped to C only', ($k11['patches'][0]['affected_scope'] ?? []) === ['C'], $k11['patches'][0] ?? null);
check('K11 A/B/D are not in the patch', array_intersect(['A', 'B', 'D'], $k11['patches'][0]['affected_scope'] ?? []) === []);
check('K11 publish is allowed after scoped revalidation', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actionNames($k11), true));
check('K11 never commits publication', $k11['publication_committed'] === false);

// 9. K12 human authorization.
$k12 = runXg($plan, 'K12-human-authorization');
check('K12 Director alone cannot choose a human-only option', in_array('HUMAN_AUTHORIZATION_REQUIRED', reasonCodes($k12), true), $k12['rejections']);
check('K12 first Director answer is rejected before the human grant', in_array('REJECTED_HUMAN_AUTHORIZATION_REQUIRED', array_column($k12['steps'], 'result'), true), array_column($k12['steps'], 'result'));
check('K12 human grant is recorded', ($k12['grants'][0]['grant_id'] ?? '') === 'grant-risk-1', $k12['grants']);
check('K12 patch is applied after the grant', in_array('APPLY_PROTOCOL_PATCH', actionNames($k12), true));
check('K12 still never commits publication', $k12['publication_committed'] === false);
$wrongActor = scenario('K12-human-authorization');
$wrongActor['scenario_id'] = 'wrong-actor';
foreach ($wrongActor['events'] as &$event) {
    if (($event['event_id'] ?? '') === 'e05') {
        $event['actor'] = 'not-the-director';
    }
}
unset($event);
$wrongActorTrace = (new Replay())->run($plan, $wrongActor, 'COORD-R0-TEST-ACTOR');
check('wrong Director actor is rejected', in_array('DIRECTOR_ACTOR_NOT_AUTHORIZED', reasonCodes($wrongActorTrace), true), $wrongActorTrace['rejections']);
$wrongGrant = scenario('K12-human-authorization');
$wrongGrant['scenario_id'] = 'wrong-human-actor';
foreach ($wrongGrant['events'] as &$event) {
    if (($event['event_id'] ?? '') === 'e06') {
        $event['actor'] = 'director';
    }
}
unset($event);
$wrongGrantTrace = (new Replay())->run($plan, $wrongGrant, 'COORD-R0-TEST-HUMAN');
check('wrong human authorizer is rejected', in_array('HUMAN_GRANT_ACTOR_NOT_AUTHORIZED', reasonCodes($wrongGrantTrace), true), $wrongGrantTrace['rejections']);

// 10. K13 unknown scope.
$k13 = runXg($plan, 'K13-unknown-scope');
check('K13 has exactly one Director wakeup', $k13['metrics']['director_wakeups'] === 1, $k13['metrics']);
check('K13 B remains held after the Director patch', $k13['state']['works']['B']['publish_held'] === true, $k13['state']['works']);
$k13AllowsB = false;
foreach ($k13['steps'] as $step) {
    foreach ($step['actions'] as $action) {
        if (($action['action'] ?? '') === 'ALLOW_PUBLISH_CURRENT_SNAPSHOT' && in_array('B', $action['scope'] ?? [], true)) {
            $k13AllowsB = true;
        }
    }
}
check('K13 B is never allowed to publish', $k13AllowsB === false);
check('K13 D is not held', $k13['state']['works']['D']['publish_held'] === false);

// 11. K14 over-escalation guard.
$k14 = runXg($plan, 'K14-over-escalation');
check('K14 has zero Director wakeups', $k14['metrics']['director_wakeups'] === 0, $k14['metrics']);
check('K14 rejects the unnecessary escalation', $k14['metrics']['rejected_unnecessary_escalations'] === 1, $k14['metrics']);
check('K14 no Director request is created', $k14['director_requests'] === []);

// 12. Event identity and ordering.
$duplicate = scenario('K01-K02-schema-scope');
$duplicate['events'][] = $duplicate['events'][0];
$dupTrace = (new Replay())->run($plan, $duplicate, 'COORD-R0-TEST-DUP');
check('duplicate event id is ignored', $dupTrace['metrics']['duplicate_events_ignored'] === 1, $dupTrace['metrics']);
$outOfOrder = [
    'scenario_id' => 'out-of-order',
    'events' => [
        ['event_id' => 'e02', 'seq' => 2, 'type' => 'CURRENT_EVIDENCE_RECEIVED', 'scope' => ['A'], 'causal_parent' => 'e01', 'evidence_id' => 'ev-A', 'result' => 'pass'],
        ['event_id' => 'e01', 'seq' => 1, 'type' => 'WORKS_ASSIGNED', 'scope' => ['A']],
    ],
];
$orderTrace = (new Replay())->run($plan, $outOfOrder, 'COORD-R0-TEST-ORDER');
check('child event is replayed after its parent arrives', $orderTrace['processed_event_count'] === 2 && $orderTrace['metrics']['out_of_order_held'] === 0, $orderTrace['metrics']);
$orphan = [
    'scenario_id' => 'orphan',
    'events' => [
        ['event_id' => 'e02', 'seq' => 2, 'type' => 'CURRENT_EVIDENCE_RECEIVED', 'scope' => ['A'], 'causal_parent' => 'missing', 'evidence_id' => 'ev-orphan', 'result' => 'pass'],
    ],
];
$orphanTrace = (new Replay())->run($plan, $orphan, 'COORD-R0-TEST-ORPHAN');
check('orphan event is held, not guessed', $orphanTrace['metrics']['out_of_order_held'] === 1 && $orphanTrace['processed_event_count'] === 0, $orphanTrace['metrics']);
check('orphan evidence is not bound', !isset($orphanTrace['state']['evidence']['ev-orphan']));
$unknownWork = ['scenario_id' => 'unknown-work', 'events' => [
    ['event_id' => 'e01', 'seq' => 1, 'type' => 'WORKS_ASSIGNED', 'scope' => ['Z']],
]];
$unknownWorkTrace = (new Replay())->run($plan, $unknownWork, 'COORD-R0-TEST-UNKNOWN-WORK');
check('unknown work scope is rejected', in_array('UNKNOWN_WORK_SCOPE', reasonCodes($unknownWorkTrace), true), $unknownWorkTrace['rejections']);
check('unknown work scope does not create a work', !isset($unknownWorkTrace['state']['works']['Z']));
$futureEvidence = scenario('K01-K02-schema-scope');
$futureEvidence['scenario_id'] = 'future-evidence';
foreach ($futureEvidence['events'] as &$event) {
    if (($event['event_id'] ?? '') === 'e05') {
        $event['policy_revision'] = 9;
    }
}
unset($event);
$futureTrace = (new Replay())->run($plan, $futureEvidence, 'COORD-R0-TEST-FUTURE');
check('future evidence revision is rejected', in_array('FUTURE_EVIDENCE', reasonCodes($futureTrace), true), $futureTrace['rejections']);

// 13. Determinism.
$again = runXg($plan, 'K04-K05-K06-director-loop');
check('replay is deterministic', $k04['trace_hash'] === $again['trace_hash'], [$k04['trace_hash'], $again['trace_hash']]);
check('trace hash is stable across re-instantiation', $k04['trace_hash'] === Canonical::hash([
    'plan_hash' => $plan->hash,
    'steps' => $k04['steps'],
    'state' => $k04['state'],
    'requests' => $k04['director_requests'],
    'patches' => $k04['patches'],
]));

// 14. Baselines are fair on safety and show the coordination delta.
$k03b1 = runBaseline($plan, 'K03-local-negotiation', 'B1');
$k03b2 = runBaseline($plan, 'K03-local-negotiation', 'B2');
$k04b1 = runBaseline($plan, 'K04-K05-K06-director-loop', 'B1');
$k04b2 = runBaseline($plan, 'K04-K05-K06-director-loop', 'B2');
$k09b1 = runBaseline($plan, 'K09-external-effect-reconcile', 'B1');
$k13b1 = runBaseline($plan, 'K13-unknown-scope', 'B1');
check('B1 wakes on a local conflict that XG resolves', $k03b1['metrics']['director_wakeups'] === 1 && $k03['metrics']['director_wakeups'] === 0, [$k03b1['metrics']['director_wakeups'], $k03['metrics']['director_wakeups']]);
check('B2 wakes more than once on repeated conflict events', $k03b2['metrics']['director_wakeups'] > 1, $k03b2['metrics']);
check('B2 leaves duplicate open requests', $k04b2['metrics']['open_director_requests'] > 0, $k04b2['metrics']);
check('B1 global replan leaks scope to A/D', ($k04b1['metrics']['scope_leak_works'] ?? []) === ['A', 'D'], $k04b1['metrics']);
check('B1 wakes on an unknown effect that XG reconciles', $k09b1['metrics']['director_wakeups'] === 1 && $k09['metrics']['director_wakeups'] === 0, [$k09b1['metrics']['director_wakeups'], $k09['metrics']['director_wakeups']]);
check('B1 global replan leaks scope on UNKNOWN', in_array('C', $k13b1['metrics']['scope_leak_works'] ?? [], true));
foreach (['XG' => $k04, 'B1' => $k04b1, 'B2' => $k04b2] as $mode => $trace) {
    check($mode . ' never reports an unsafe allow', $trace['metrics']['unsafe_allows'] === 0, $trace['metrics']);
    check($mode . ' never commits publication', $trace['publication_committed'] === false);
    check($mode . ' never claims a real model', $trace['real_model'] === false);
}

// 15. Required trace sections.
foreach (['metrics', 'director_requests', 'patches', 'steps', 'state', 'trace_hash', 'publication_committed', 'real_model'] as $field) {
    check('XG trace has ' . $field, array_key_exists($field, $k04));
}
check('every step has a state digest', array_reduce($k04['steps'], static fn (bool $carry, array $step): bool => $carry && \is_string($step['state_digest'] ?? null), true));

echo "\n";
if ($failures !== []) {
    echo 'FAILED: ' . \count($failures) . ' check(s) failed, ' . $passes . " passed\n";
    exit(1);
}
echo 'OK: ' . $passes . " coordination checks passed\n";
