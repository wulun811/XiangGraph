<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Tests;

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__, 3) . '/experiments/coord-r0/baselines/bootstrap.php';

use XiangGraph\Coord\AuthorityFacts;
use XiangGraph\Coord\AuthorityProvider;
use XiangGraph\Coord\DecisionRights;
use XiangGraph\Coord\EscalationRouter;
use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolPatchValidator;
use XiangGraph\Coord\Replay;
use XiangGraph\Coord\State;
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

function hostEvent(array $overrides = []): array
{
    return array_merge([
        'event_id' => 'auth-1',
        'seq' => 1,
        'type' => 'CONTRACT_CHANGED',
        'scope' => ['R2'],
        'origin_scope' => ['R2'],
        'principal' => 'monitor-agent',
    ], $overrides);
}

function baseFact(string $id, string $kind, array $overrides = []): array
{
    if ($kind === AuthorityFacts::KIND_SIGNED) {
        $fact = [
            'fact_id' => $id,
            'kind' => $kind,
            'scope' => ['R2'],
            'allowed_keys' => ['threshold_percent'],
            'status' => 'ACTIVE',
            'signer' => 'human-owner',
            'version' => 'threshold-v2',
            'authorized_principals' => ['monitor-agent'],
            'expires_at' => '2035-01-01T00:00:00Z',
        ];
    } else {
        $fact = [
            'fact_id' => $id,
            'kind' => $kind,
            'scope' => ['R2'],
            'allowed_keys' => ['threshold_percent'],
            'status' => 'ACTIVE',
            'delegate' => 'monitor-agent',
            'version' => 'v1',
            'range' => ['min' => 10, 'max' => 30],
            'expires_at' => '2035-01-01T00:00:00Z',
        ];
    }

    return array_merge($fact, $overrides);
}

function signedLedger(array $facts, string $key, string $issuer = 'c0-test-host'): array
{
    $signed = [];
    foreach ($facts as $fact) {
        $fact['signature'] = AuthorityFacts::signFact($fact, $key, $issuer);
        $signed[] = $fact;
    }

    return [
        'schema_version' => AuthorityFacts::SCHEMA_VERSION,
        'issuer' => $issuer,
        'key_id' => 'c0-test-key-1',
        'facts' => $signed,
    ];
}

function scenarioWithHostRef(array $scenario, string $ref, string $principal = 'monitor-agent'): array
{
    foreach ($scenario['events'] as &$event) {
        if (($event['type'] ?? '') === 'CONTRACT_CHANGED') {
            $event['authority_ref'] = $ref;
            $event['principal'] = $principal;
        }
    }
    unset($event);

    return $scenario;
}

$key = 'c0-test-host-key-0123456789abcdef';
$signedFact = baseFact('afact-threshold-v2', AuthorityFacts::KIND_SIGNED);
$delegatedFact = baseFact('afact-threshold-band', AuthorityFacts::KIND_DELEGATION);
$revokedFact = baseFact('afact-revoked', AuthorityFacts::KIND_SIGNED, ['status' => 'REVOKED']);
$expiredFact = baseFact('afact-expired', AuthorityFacts::KIND_SIGNED, ['expires_at' => '2020-01-01T00:00:00Z']);
$ledgerData = signedLedger([$signedFact, $delegatedFact, $revokedFact, $expiredFact], $key);

$facts = AuthorityFacts::fromHostLedger($ledgerData, $key);
check('host ledger loads and reports its source', $facts->source() === AuthorityProvider::SOURCE_HOST);
check('host ledger keeps all four signed facts', \count($facts->facts()) === 4);
check('host ledger resolves a signed contract by fact id', $facts->resolve('afact-threshold-v2') !== null);

// 1. Signed contract: host fact + host principal.
$signedDecision = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-threshold-v2',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('signed host fact adopts externally', ($signedDecision['decision'] ?? '') === DecisionRights::ADOPT_EXTERNAL, $signedDecision);
check('signed host decision carries the host source', ($signedDecision['authority_source'] ?? '') === AuthorityProvider::SOURCE_HOST);
check('signed host decision keeps the fact id as authority_ref', ($signedDecision['authority_ref'] ?? '') === 'afact-threshold-v2');

// 2. Self-declared actor/basis is not authority in host mode.
$noRef = DecisionRights::classify($plan, hostEvent([
    'actor' => 'human-owner',
    'contract_ref' => 'afact-threshold-v2',
    'decision_basis' => 'EXTERNAL_AUTHORITY_DECIDED',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('host mode rejects a contract_ref without authority_ref', ($noRef['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $noRef);
check('host mode reports the missing host reference', ($noRef['basis'] ?? '') === 'NO_HOST_AUTHORITY_REF', $noRef);

$missingPrincipal = DecisionRights::classify($plan, hostEvent([
    'principal' => null,
    'actor' => 'monitor-agent',
    'authority_ref' => 'afact-threshold-v2',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('host mode never falls back to a self-declared actor', ($missingPrincipal['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $missingPrincipal);
check('missing host principal is reported', ($missingPrincipal['basis'] ?? '') === 'PRINCIPAL_NOT_AUTHORIZED', $missingPrincipal);

$wrongPrincipal = DecisionRights::classify($plan, hostEvent([
    'principal' => 'release-agent',
    'authority_ref' => 'afact-threshold-v2',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('a different agent cannot spend a signed contract', ($wrongPrincipal['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $wrongPrincipal);

$unknown = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-does-not-exist',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('unknown host fact is rejected', ($unknown['basis'] ?? '') === 'UNKNOWN_AUTHORITY_REF', $unknown);

// 3. Delegation: host-stamped principal, scope, key, range.
$delegated = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 25,
]), $facts);
check('delegation inside range adopts locally', ($delegated['decision'] ?? '') === DecisionRights::ADOPT_DELEGATED, $delegated);

$delegatedWrongPrincipal = DecisionRights::classify($plan, hostEvent([
    'principal' => 'release-agent',
    'authority_ref' => 'afact-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 25,
]), $facts);
check('delegation cannot be used by another principal', ($delegatedWrongPrincipal['basis'] ?? '') === 'DELEGATE_NOT_AUTHORIZED', $delegatedWrongPrincipal);

$outOfRange = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 40,
]), $facts);
check('delegation outside range escalates', ($outOfRange['decision'] ?? '') === DecisionRights::ESCALATE_OUT_OF_RANGE, $outOfRange);
check('out-of-range keeps its distinct basis', ($outOfRange['basis'] ?? '') === 'OUT_OF_DELEGATED_RANGE', $outOfRange);

$wrongKey = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-threshold-band',
    'change_key' => 'rollback_percent',
    'change_value' => 25,
]), $facts);
check('delegation is limited to its declared key', ($wrongKey['basis'] ?? '') === 'DELEGATION_KEY_MISMATCH', $wrongKey);

$wrongScope = DecisionRights::classify($plan, hostEvent([
    'origin_scope' => ['R1'],
    'authority_ref' => 'afact-threshold-band',
    'change_key' => 'threshold_percent',
    'change_value' => 25,
]), $facts);
check('delegation is limited to its declared scope', ($wrongScope['basis'] ?? '') === 'DELEGATION_SCOPE_MISMATCH', $wrongScope);

// 4. Revoked and expired facts fail closed.
$revoked = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-revoked',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('revoked host fact is not adopted', ($revoked['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $revoked);
check('revoked host fact reports inactive status', ($revoked['basis'] ?? '') === 'CONTRACT_NOT_ACTIVE', $revoked);

$expired = DecisionRights::classify($plan, hostEvent([
    'authority_ref' => 'afact-expired',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $facts);
check('expired host fact is not adopted', ($expired['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $expired);
check('expired host fact reports expiry', ($expired['basis'] ?? '') === 'AUTHORITY_EXPIRED', $expired);

// 5. Ledger integrity: tamper and structural errors are rejected.
$tampered = $ledgerData;
$tampered['facts'][0]['scope'] = ['R1'];
$tamperRejected = false;
try {
    AuthorityFacts::fromHostLedger($tampered, $key);
} catch (\InvalidArgumentException) {
    $tamperRejected = true;
}
check('tampered host fact is rejected at load', $tamperRejected);

$missingPrincipals = signedLedger([baseFact('afact-no-principals', AuthorityFacts::KIND_SIGNED, ['authorized_principals' => []])], $key);
$missingPrincipalsRejected = false;
try {
    AuthorityFacts::fromHostLedger($missingPrincipals, $key);
} catch (\InvalidArgumentException) {
    $missingPrincipalsRejected = true;
}
check('signed host fact without authorized principals is rejected', $missingPrincipalsRejected);

$duplicate = signedLedger([$signedFact, $signedFact], $key);
$duplicateRejected = false;
try {
    AuthorityFacts::fromHostLedger($duplicate, $key);
} catch (\InvalidArgumentException) {
    $duplicateRejected = true;
}
check('duplicate host fact ids are rejected', $duplicateRejected);

$shortKeyRejected = false;
try {
    AuthorityFacts::fromHostLedger($ledgerData, 'short');
} catch (\InvalidArgumentException) {
    $shortKeyRejected = true;
}
check('short host keys are rejected', $shortKeyRejected);

// 6. Application-time re-check: a fact that was valid at observation can be
// revoked before the local adoption is applied.
$rebase = [
    'scope' => ['R2', 'R3', 'R4'],
    'basis' => 'SIGNED_EXTERNAL_CONTRACT',
    'authority_ref' => 'afact-threshold-v2',
    'decision' => 'ADOPT_EXTERNAL',
];
$applyValid = $facts->revalidateForApply($plan, $rebase, hostEvent([
    'authority_ref' => 'afact-threshold-v2',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]));
check('application-time re-check accepts a still-valid fact', ($applyValid['valid'] ?? false) === true, $applyValid);

$applyExpired = $facts->revalidateForApply($plan, $rebase, hostEvent([
    'authority_ref' => 'afact-threshold-v2',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), \strtotime('2036-01-01T00:00:00Z'));
check('application-time re-check rejects a fact that expired after observation', ($applyExpired['valid'] ?? true) === false, $applyExpired);
check('application-time expiry is explicit', ($applyExpired['basis'] ?? '') === 'AUTHORITY_EXPIRED', $applyExpired);

// 6b. Patch-time re-check: a Director patch that changes a contract or
// threshold cannot rely on the Director's role alone.
$patchState = new State($plan);
$router = new EscalationRouter();
$route = $router->route($plan, $patchState, 'CONTRACT_CHANGE', ['R2'], 'patch-authority', ['patch-test'], ['event_id' => 'patch-e1', 'seq' => 1]);
$requestId = (string) ($route['request_id'] ?? '');
$patchState->requests[$requestId]['options'][] = [
    'id' => 'set-threshold',
    'effect' => 'test threshold patch',
    'patch' => [[
        'op' => 'SET_THRESHOLD',
        'scope' => ['R2'],
        'authority_ref' => 'afact-threshold-v2',
        'change_key' => 'threshold_percent',
        'change_value' => 20,
    ]],
];
$patchResponse = [
    'request_id' => $requestId,
    'policy_revision' => $patchState->policyRevision,
    'actor' => 'director',
    'principal' => 'monitor-agent',
    'chosen_option' => 'set-threshold',
    'scope' => ['R2'],
];
$validator = new ProtocolPatchValidator($facts);
$validPatch = $validator->validate($plan, $patchState, $patchResponse);
check('a threshold patch with a valid host fact is accepted', ($validPatch['valid'] ?? false) === true, $validPatch);

$noRefResponse = $patchResponse;
$patchState->requests[$requestId]['options'][2]['patch'][0] = ['op' => 'SET_THRESHOLD', 'scope' => ['R2'], 'change_key' => 'threshold_percent', 'change_value' => 20];
$noRefPatch = $validator->validate($plan, $patchState, $noRefResponse);
check('a threshold patch without an authority ref is rejected', ($noRefPatch['reason'] ?? '') === 'AUTHORITY_FACT_REQUIRED', $noRefPatch);

$patchState->requests[$requestId]['options'][2]['patch'][0] = ['op' => 'SET_THRESHOLD', 'scope' => ['R2'], 'authority_ref' => 'afact-revoked', 'change_key' => 'threshold_percent', 'change_value' => 20];
$revokedPatch = $validator->validate($plan, $patchState, $noRefResponse);
check('a threshold patch with a revoked fact is rejected', ($revokedPatch['reason'] ?? '') === 'CONTRACT_NOT_ACTIVE', $revokedPatch);

$patchState->requests[$requestId]['options'][2]['patch'][0] = ['op' => 'SET_THRESHOLD', 'scope' => ['R2'], 'authority_ref' => 'afact-threshold-v2', 'change_key' => 'threshold_percent', 'change_value' => 20];
$wrongPrincipalResponse = $patchResponse;
$wrongPrincipalResponse['principal'] = 'release-agent';
$wrongPrincipalPatch = $validator->validate($plan, $patchState, $wrongPrincipalResponse);
check('a threshold patch by the wrong principal is rejected', ($wrongPrincipalPatch['reason'] ?? '') === 'PRINCIPAL_NOT_AUTHORIZED', $wrongPrincipalPatch);

// 7. Reducer integration: host facts reach XG and B3, and a failed apply
// re-check escalates instead of producing a local adoption.
$scenarioA = json_decode((string) file_get_contents($root . '/semantic/coordination/challenges/C0-PARITY-01-A-signed-adoption.json'), true, 512, \JSON_THROW_ON_ERROR);
$hostScenarioA = scenarioWithHostRef($scenarioA, 'afact-threshold-v2');

$xg = (new Replay())->run($plan, $hostScenarioA, 'C0-AUTH-XG-A', $facts);
check('XG adopts a host-signed contract with zero wakeups', ($xg['metrics']['director_wakeups'] ?? -1) === 0, $xg['metrics']);
check('XG records one local adoption under host facts', ($xg['metrics']['local_contract_adoptions'] ?? 0) === 1, $xg['metrics']);
check('XG has no apply-time authority failure on a valid fact', ($xg['metrics']['authority_revalidation_failures'] ?? 0) === 0, $xg['metrics']);

$b3 = (new StrongBaselineReducer(new EscalationRouter(), new ProtocolPatchValidator(), $facts))->reduce(
    $plan,
    array_values($hostScenarioA['events']),
    ['run_id' => 'C0-AUTH-B3-A', 'scenario_id' => 'C0-AUTH-B3-A']
);
check('B3 adopts the same host-signed contract with zero wakeups', ($b3['metrics']['director_wakeups'] ?? -1) === 0, $b3['metrics']);
check('B3 records one local adoption under host facts', ($b3['metrics']['local_contract_adoptions'] ?? 0) === 1, $b3['metrics']);
check('B3 also reports no apply-time authority failure', ($b3['metrics']['authority_revalidation_failures'] ?? 0) === 0, $b3['metrics']);

$flaky = new class ($facts) implements AuthorityProvider {
    public function __construct(private AuthorityFacts $inner)
    {
    }

    public function source(): string
    {
        return AuthorityProvider::SOURCE_HOST;
    }

    public function resolve(string $ref): ?array
    {
        return $this->inner->resolve($ref);
    }

    public function revalidate(Plan $plan, array $fact, array $event, ?int $now = null): array
    {
        return $this->inner->revalidate($plan, $fact, $event, $now);
    }

    public function revalidateForApply(Plan $plan, array $rebase, array $event, ?int $now = null): array
    {
        return ['valid' => false, 'basis' => 'AUTHORITY_REVOKED_BEFORE_APPLY', 'reason' => 'test revocation between observation and application'];
    }

    public function principal(array $event): string
    {
        return $this->inner->principal($event);
    }
};

$xgBlocked = (new Replay())->run($plan, $hostScenarioA, 'C0-AUTH-XG-BLOCKED', $flaky);
check('XG escalates when apply-time authority re-check fails', ($xgBlocked['metrics']['director_wakeups'] ?? 0) === 1, $xgBlocked['metrics']);
check('XG does not adopt when apply-time authority re-check fails', ($xgBlocked['metrics']['local_contract_adoptions'] ?? -1) === 0, $xgBlocked['metrics']);
check('XG counts the apply-time authority failure', ($xgBlocked['metrics']['authority_revalidation_failures'] ?? 0) === 1, $xgBlocked['metrics']);
$blockedActions = [];
foreach ($xgBlocked['steps'] as $step) {
    foreach ($step['actions'] as $action) {
        $blockedActions[] = (string) ($action['action'] ?? '');
    }
}
check('XG exposes the blocked adoption as an action', \in_array('ADOPT_CONTRACT_BLOCKED', $blockedActions, true), $blockedActions);

$b3Blocked = (new StrongBaselineReducer(new EscalationRouter(), new ProtocolPatchValidator(), $flaky))->reduce(
    $plan,
    array_values($hostScenarioA['events']),
    ['run_id' => 'C0-AUTH-B3-BLOCKED', 'scenario_id' => 'C0-AUTH-B3-BLOCKED']
);
check('B3 also escalates when apply-time re-check fails', ($b3Blocked['metrics']['director_wakeups'] ?? 0) === 1, $b3Blocked['metrics']);
check('B3 also refuses the local adoption', ($b3Blocked['metrics']['local_contract_adoptions'] ?? -1) === 0, $b3Blocked['metrics']);

// 8. The frozen plan simulation remains byte-compatible with C0-PARITY-01.
$planFacts = AuthorityFacts::fromPlan($plan);
$planSigned = DecisionRights::classify($plan, hostEvent([
    'principal' => null,
    'actor' => 'human-owner',
    'contract_ref' => 'threshold-v2-signed',
    'change_key' => 'threshold_percent',
    'change_value' => 20,
]), $planFacts);
check('plan simulation still classifies its signed contract', ($planSigned['decision'] ?? '') === DecisionRights::ADOPT_EXTERNAL, $planSigned);
check('plan simulation does not add host-only fields', !\array_key_exists('authority_source', $planSigned), $planSigned);

$planOpen = DecisionRights::classify($plan, hostEvent([
    'principal' => null,
    'actor' => 'monitor-agent',
    'decision_basis' => 'EXTERNAL_AUTHORITY_DECIDED',
    'change_key' => 'threshold_percent',
    'change_value' => 40,
]), $planFacts);
check('plan simulation still rejects a self-claimed open tradeoff', ($planOpen['decision'] ?? '') === DecisionRights::ESCALATE_OPEN, $planOpen);

echo "\n" . $passes . ' checks passed, ' . \count($failures) . " failed\n";
if ($failures !== []) {
    echo 'FAILURES: ' . implode(' | ', $failures) . "\n";
    exit(1);
}
