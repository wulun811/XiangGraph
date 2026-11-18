<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__, 3) . '/experiments/coord-r0/baselines/bootstrap.php';

use XiangGraph\Coord\DecisionRights;
use XiangGraph\Coord\EscalationRouter;
use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolContract;
use XiangGraph\Coord\ProtocolPatchValidator;
use XiangGraph\Coord\Replay;
use XiangGraph\CoordR0\StrongBaselineReducer;

$root = dirname(__DIR__, 3);
$plan = Replay::loadPlan($root . '/semantic/coordination/plans/c0-parity-canary.v0.1.json');
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

    return Replay::loadScenario($root . '/semantic/coordination/challenges/' . $id . '.json');
}

function xg(string $id): array
{
    global $plan;

    return (new Replay())->run($plan, scenario($id), 'C0-PARITY-01-XG-' . $id);
}

function b3(string $id): array
{
    global $plan;
    $scenario = scenario($id);

    return (new StrongBaselineReducer(new EscalationRouter(), new ProtocolPatchValidator()))->reduce(
        $plan,
        array_values($scenario['events']),
        ['run_id' => 'C0-PARITY-01-B3-' . $id, 'scenario_id' => $id]
    );
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

function requestReasons(array $trace): array
{
    return array_map(static fn (array $request): string => (string) ($request['reason'] ?? ''), $trace['director_requests']);
}

function planAccepted(array $data): bool
{
    try {
        new Plan($data);

        return true;
    } catch (\InvalidArgumentException) {
        return false;
    }
}

function rightsEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => 'unit-1',
        'seq' => 1,
        'type' => 'CONTRACT_CHANGED',
        'scope' => ['R2'],
        'origin_scope' => ['R2'],
        'actor' => 'monitor-agent',
    ], $overrides);
}

$ids = [
    'A' => 'C0-PARITY-01-A-signed-adoption',
    'B' => 'C0-PARITY-01-B-open-tradeoff',
    'C1' => 'C0-PARITY-01-C1-delegated-in-range',
    'C2' => 'C0-PARITY-01-C2-delegated-out-of-range',
];

// 1. Contract and plan validation.
check('C0 parity plan is valid', ProtocolContract::validate($plan->data)['valid'] === true, ProtocolContract::validate($plan->data));
check('C0 parity plan keeps exactly the five protocol types', array_values(array_unique(array_column($plan->data['protocol_composition'], 'protocol'))) === ['P-DEP', 'P-REVIEW', 'P-CONFLICT', 'P-RECOVER', 'P-ESCALATE']);
check('C0 parity plan has one signed contract', \count($plan->decisionRights()['signed_contracts'] ?? []) === 1);
check('C0 parity plan has one bounded delegation', \count($plan->decisionRights()['delegations'] ?? []) === 1);
check('canary plan without decision_rights remains valid', ProtocolContract::validate(Replay::loadPlan($root . '/semantic/coordination/plans/canary-release.v0.1.json')->data)['valid'] === true);

$unknownSigner = $plan->data;
$unknownSigner['decision_rights']['signed_contracts'][0]['signer'] = 'self-appointed-agent';
check('unknown contract signer is rejected at plan load', planAccepted($unknownSigner) === false);

$duplicateContract = $plan->data;
$duplicateContract['decision_rights']['signed_contracts'][] = $duplicateContract['decision_rights']['signed_contracts'][0];
check('duplicate signed contract id is rejected', planAccepted($duplicateContract) === false);

$badRange = $plan->data;
$badRange['decision_rights']['delegations'][0]['range'] = ['min' => 30, 'max' => 10];
check('delegation range min > max is rejected', planAccepted($badRange) === false);

$unknownDelegationScope = $plan->data;
$unknownDelegationScope['decision_rights']['delegations'][0]['scope'] = ['R9'];
check('delegation scope outside the plan is rejected', planAccepted($unknownDelegationScope) === false);

$missingDelegatedKeys = $plan->data;
$missingDelegatedKeys['decision_rights']['delegations'][0]['allowed_keys'] = [];
check('delegation without allowed keys is rejected', planAccepted($missingDelegatedKeys) === false);

$malformedRights = $plan->data;
$malformedRights['decision_rights'] = 'not-an-object';
check('malformed decision_rights is rejected', planAccepted($malformedRights) === false);

// 2. Classifier units: labels and actor strings are not authority.
$signed = DecisionRights::classify($plan, rightsEvent([
    'contract_ref' => 'threshold-v2-signed',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]));
check('signed contract reference is classified as external adoption', ($signed['decision'] ?? '') === DecisionRights::ADOPT_EXTERNAL, $signed);

$wrongScope = DecisionRights::classify($plan, rightsEvent([
    'origin_scope' => ['R1'],
    'scope' => ['R1'],
    'contract_ref' => 'threshold-v2-signed',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]));
check('signed contract outside its scope is not adopted', ($wrongScope['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $wrongScope);

$revokedData = $plan->data;
$revokedData['decision_rights']['signed_contracts'][0]['status'] = 'REVOKED';
$revokedPlan = new Plan($revokedData);
$revoked = DecisionRights::classify($revokedPlan, rightsEvent([
    'contract_ref' => 'threshold-v2-signed',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]));
check('revoked signed contract is not adopted', ($revoked['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $revoked);

$unknownRef = DecisionRights::classify($plan, rightsEvent([
    'contract_ref' => 'threshold-v9-unknown',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]));
check('unknown contract reference is not adopted', ($unknownRef['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $unknownRef);

$selfClaim = DecisionRights::classify($plan, rightsEvent([
    'decision_basis' => 'EXTERNAL_AUTHORITY_DECIDED',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]));
check('self-claimed decision basis without a reference is not authority', ($selfClaim['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $selfClaim);

$delegated = DecisionRights::classify($plan, rightsEvent([
    'delegation_ref' => 'monitor-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 25,
]));
check('value inside the delegation is classified as delegated adoption', ($delegated['decision'] ?? '') === DecisionRights::ADOPT_DELEGATED, $delegated);

$outOfRange = DecisionRights::classify($plan, rightsEvent([
    'delegation_ref' => 'monitor-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 40,
]));
check('value outside the delegation is classified as escalation', ($outOfRange['decision'] ?? '') === DecisionRights::ESCALATE_OUT_OF_RANGE, $outOfRange);

$wrongActor = DecisionRights::classify($plan, rightsEvent([
    'actor' => 'release-agent',
    'delegation_ref' => 'monitor-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 25,
]));
check('delegation is not usable by another actor', ($wrongActor['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $wrongActor);

$wrongKey = DecisionRights::classify($plan, rightsEvent([
    'delegation_ref' => 'monitor-threshold-band',
    'change_key' => 'rollback_percent',
    'change_value' => 25,
]));
check('delegation is limited to its declared keys', ($wrongKey['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $wrongKey);

// 3. A: signed external adoption fact.
$aXg = xg($ids['A']);
check('A XG has zero Director wakeups', $aXg['metrics']['director_wakeups'] === 0, $aXg['metrics']);
check('A XG records one local contract adoption', $aXg['metrics']['local_contract_adoptions'] === 1, $aXg['metrics']);
check('A XG creates no Director request', $aXg['director_requests'] === [], $aXg['director_requests']);
check('A XG holds R2/R3/R4 after adoption and before evidence', (($aXg['steps'][1]['holds'][0]['status'] ?? '') === 'ACTIVE') && (($aXg['steps'][1]['holds'][0]['scope'] ?? []) === ['R2', 'R3', 'R4']), $aXg['steps'][1]['holds'] ?? null);
check('A XG allows unrelated R1 before evidence', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($aXg, 3)), true), stepBySeq($aXg, 3));
check('A XG allows R2/R3/R4 only after revalidation evidence', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($aXg, 5)), true), stepBySeq($aXg, 5));
check('A XG keeps R1 at revision 1 and the affected closure at revision 2', ($aXg['state']['policy_slices'] ?? []) === ['R1' => 1, 'R2' => 2, 'R3' => 2, 'R4' => 2], $aXg['state']['policy_slices']);
check('A XG never reports an unsafe allow', $aXg['metrics']['unsafe_allows'] === 0);

$aB3 = b3($ids['A']);
check('A B3 has zero Director wakeups', $aB3['metrics']['director_wakeups'] === 0, $aB3['metrics']);
check('A B3 records one local contract adoption', $aB3['metrics']['local_contract_adoptions'] === 1, $aB3['metrics']);
check('A B3 reaches the same policy slices as XG', ($aB3['metrics']['policy_slices'] ?? []) === ($aXg['state']['policy_slices'] ?? []), $aB3['metrics']);
check('A B3 completes all four works like XG', ($aB3['metrics']['completed_work'] ?? []) === ($aXg['metrics']['completed_work'] ?? []), $aB3['metrics']);

// 4. B: open tradeoff, even with a self-claimed basis.
$bXg = xg($ids['B']);
check('B XG escalates an open tradeoff exactly once', $bXg['metrics']['director_wakeups'] === 1, $bXg['metrics']);
check('B XG does not adopt the open tradeoff locally', $bXg['metrics']['local_contract_adoptions'] === 0, $bXg['metrics']);
check('B XG request reason is CONTRACT_CHANGE', in_array('CONTRACT_CHANGE', requestReasons($bXg), true), requestReasons($bXg));
check('B XG allows unrelated R1 while the tradeoff is open', in_array('ALLOW_PUBLISH_CURRENT_SNAPSHOT', actions(stepBySeq($bXg, 3)), true), stepBySeq($bXg, 3));
check('B XG applies the Director patch', (stepBySeq($bXg, 4)['result'] ?? '') === 'PATCH_APPLIED', stepBySeq($bXg, 4));
check('B XG completes all works after patch and evidence', ($bXg['metrics']['completed_work'] ?? []) === ['R1', 'R2', 'R3', 'R4'], $bXg['metrics']);
check('B XG never reports an unsafe allow', $bXg['metrics']['unsafe_allows'] === 0);

$bB3 = b3($ids['B']);
check('B B3 escalates an open tradeoff exactly once', $bB3['metrics']['director_wakeups'] === 1, $bB3['metrics']);
check('B B3 does not adopt the open tradeoff locally', $bB3['metrics']['local_contract_adoptions'] === 0, $bB3['metrics']);
check('B B3 request reason is CONTRACT_CHANGE', in_array('CONTRACT_CHANGE', requestReasons($bB3), true), requestReasons($bB3));
check('B B3 completes all works like XG', ($bB3['metrics']['completed_work'] ?? []) === ($bXg['metrics']['completed_work'] ?? []), $bB3['metrics']);
check('B B3 never reports an unsafe allow', $bB3['metrics']['unsafe_allows'] === 0);

// 5. C1: inside a bounded delegation.
$c1Xg = xg($ids['C1']);
check('C1 XG has zero Director wakeups', $c1Xg['metrics']['director_wakeups'] === 0, $c1Xg['metrics']);
check('C1 XG records one delegated local adoption', $c1Xg['metrics']['local_contract_adoptions'] === 1, $c1Xg['metrics']);
check('C1 XG keeps the delegation out of the Director path', $c1Xg['director_requests'] === [], $c1Xg['director_requests']);
check('C1 XG completes all works after evidence', ($c1Xg['metrics']['completed_work'] ?? []) === ['R1', 'R2', 'R3', 'R4'], $c1Xg['metrics']);
$c1B3 = b3($ids['C1']);
check('C1 B3 also adopts inside the delegation', ($c1B3['metrics']['director_wakeups'] ?? -1) === 0 && ($c1B3['metrics']['local_contract_adoptions'] ?? 0) === 1, $c1B3['metrics']);
check('C1 B3 reaches the same slices as XG', ($c1B3['metrics']['policy_slices'] ?? []) === ($c1Xg['state']['policy_slices'] ?? []), $c1B3['metrics']);

// 6. C2: outside the delegation.
$c2Xg = xg($ids['C2']);
check('C2 XG escalates an out-of-range delegation exactly once', $c2Xg['metrics']['director_wakeups'] === 1, $c2Xg['metrics']);
check('C2 XG marks the escalation as out of range', $c2Xg['metrics']['delegation_range_escalations'] === 1, $c2Xg['metrics']);
check('C2 XG does not adopt out of range', $c2Xg['metrics']['local_contract_adoptions'] === 0, $c2Xg['metrics']);
check('C2 XG applies the Director patch', (stepBySeq($c2Xg, 4)['result'] ?? '') === 'PATCH_APPLIED', stepBySeq($c2Xg, 4));
check('C2 XG completes all works after patch and evidence', ($c2Xg['metrics']['completed_work'] ?? []) === ['R1', 'R2', 'R3', 'R4'], $c2Xg['metrics']);
check('C2 XG never reports an unsafe allow', $c2Xg['metrics']['unsafe_allows'] === 0);
$c2B3 = b3($ids['C2']);
check('C2 B3 also escalates out of range exactly once', ($c2B3['metrics']['director_wakeups'] ?? -1) === 1 && ($c2B3['metrics']['delegation_range_escalations'] ?? 0) === 1, $c2B3['metrics']);
check('C2 B3 does not adopt out of range', ($c2B3['metrics']['local_contract_adoptions'] ?? -1) === 0, $c2B3['metrics']);
check('C2 B3 completes all works like XG', ($c2B3['metrics']['completed_work'] ?? []) === ($c2Xg['metrics']['completed_work'] ?? []), $c2B3['metrics']);
check('C2 B3 never reports an unsafe allow', $c2B3['metrics']['unsafe_allows'] === 0);

// 7. Decision-rights parity: same outcome for the same case.
foreach ($ids as $label => $id) {
    $xgTrace = xg($id);
    $b3Trace = b3($id);
    $same = (int) $xgTrace['metrics']['director_wakeups'] === (int) $b3Trace['metrics']['director_wakeups']
        && (int) ($xgTrace['metrics']['director_decisions'] ?? 0) === (int) ($b3Trace['metrics']['director_decisions'] ?? 0)
        && ($xgTrace['metrics']['completed_work'] ?? []) === ($b3Trace['metrics']['completed_work'] ?? [])
        && ($xgTrace['state']['policy_slices'] ?? []) === ($b3Trace['metrics']['policy_slices'] ?? [])
        && (int) $xgTrace['metrics']['unsafe_allows'] === (int) $b3Trace['metrics']['unsafe_allows'];
    check('parity ' . $label . ': XG and B3 have the same wake/decision/outcome profile', $same, [
        'xg' => $xgTrace['metrics'],
        'b3' => $b3Trace['metrics'],
    ]);
}

// 8. B.5 history remains as recorded; C0 does not rewrite it.
$legacyPlan = Replay::loadPlan($root . '/semantic/coordination/plans/canary-release.v0.1.json');
$legacyScenario = scenario('B5-06-canary-frozen-challenge');
$legacyXg = (new Replay())->run($legacyPlan, $legacyScenario, 'C0-LEGACY-XG');
$legacyB3 = (new StrongBaselineReducer(new EscalationRouter(), new ProtocolPatchValidator()))->reduce(
    $legacyPlan,
    array_values($legacyScenario['events']),
    ['run_id' => 'C0-LEGACY-B3', 'scenario_id' => 'B5-06-canary-frozen-challenge']
);
check('legacy B5-06 XG still has two wakeups', (int) $legacyXg['metrics']['director_wakeups'] === 2, $legacyXg['metrics']);
check('legacy B5-06 B3 still has one wakeup', (int) $legacyB3['metrics']['director_wakeups'] === 1, $legacyB3['metrics']);
check('C0 explains the legacy difference as missing decision-rights authority, not by editing B5', ($bXg['metrics']['director_wakeups'] === $bB3['metrics']['director_wakeups'] && $legacyB3['metrics']['director_wakeups'] < $legacyXg['metrics']['director_wakeups']));

// 9. Global honesty and no business branch.
$all = [$aXg, $aB3, $bXg, $bB3, $c1Xg, $c1B3, $c2Xg, $c2B3];
$realModel = array_filter($all, static fn (array $trace): bool => $trace['real_model'] !== false);
$committed = array_filter($all, static fn (array $trace): bool => $trace['publication_committed'] !== false);
$tokens = array_filter($all, static fn (array $trace): bool => $trace['measurement']['model_tokens'] !== null);
$clock = array_filter($all, static fn (array $trace): bool => $trace['measurement']['wall_clock_ms'] !== null);
check('all C0 traces are tagged not-real-model', $realModel === [], array_keys($realModel));
check('all C0 traces leave publication uncommitted', $committed === [], array_keys($committed));
check('all C0 traces have null model tokens', $tokens === [], array_keys($tokens));
check('all C0 traces have null wall-clock time', $clock === [], array_keys($clock));

$coreFiles = glob($root . '/semantic/coordination/src/*.php') ?: [];
$coreFiles = array_merge($coreFiles, glob($root . '/semantic/coordination/src/Protocols/*.php') ?: []);
$businessTokens = ['canary', 'C0-PARITY', 'threshold_percent', 'R1', "\$event['scenario_id']", "=== 'B5-", "=== 'C0-"];
$found = [];
foreach ($coreFiles as $file) {
    $source = (string) file_get_contents($file);
    foreach ($businessTokens as $token) {
        if (str_contains($source, $token)) {
            $found[] = basename($file) . ':' . $token;
        }
    }
}
check('core coordination code has no C0 scenario or business-name branch', $found === [], $found);
check('shared decision-rights classifier is used by both XG and B3', str_contains((string) file_get_contents($root . '/semantic/coordination/src/Protocols/PEscalate.php'), 'DecisionRights::classify') && str_contains((string) file_get_contents($root . '/experiments/coord-r0/baselines/StrongBaselineReducer.php'), 'DecisionRights::classify'));

echo "\n" . $passes . ' checks passed, ' . \count($failures) . " failed\n";
if ($failures !== []) {
    echo 'FAILURES: ' . implode(' | ', $failures) . "\n";
    exit(1);
}
