<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * A Director answer is a proposal. This validator checks that the proposal is
 * still current, in scope, within authority, and does not weaken safety
 * invariants before it can become a ProtocolPatch.
 */
final class ProtocolPatchValidator
{
    public function __construct(private ?AuthorityProvider $authority = null)
    {
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function validate(Plan $plan, State $state, array $response): array
    {
        $request = $this->resolveRequest($state, $response);
        if ($request === null) {
            return $this->reject('REQUEST_NOT_FOUND', 'no request matches this response');
        }
        if (($request['status'] ?? '') !== 'OPEN') {
            return $this->reject('REQUEST_NOT_OPEN', 'the request is already closed');
        }
        $responseRevision = (int) ($response['policy_revision'] ?? -1);
        if ($responseRevision < (int) ($request['policy_revision'] ?? 0)) {
            return $this->reject('STALE_POLICY_REVISION', 'the response refers to a revision older than the request');
        }
        if ($responseRevision > $state->policyRevision) {
            return $this->reject('FUTURE_POLICY_REVISION', 'the response refers to a revision that has not happened');
        }
        foreach (($request['scope_revisions'] ?? []) as $workId => $revision) {
            if ((int) ($state->policySlices[(string) $workId] ?? -1) !== (int) $revision) {
                return $this->reject('REQUEST_STALE', 'a policy slice in the request scope changed after the request was issued');
            }
        }
        $directorActor = $plan->authority()['director'] ?? '';
        if ((string) ($response['actor'] ?? '') !== $directorActor) {
            return $this->reject('DIRECTOR_ACTOR_NOT_AUTHORIZED', 'the response did not come from the configured Director actor');
        }

        $chosen = (string) ($response['chosen_option'] ?? '');
        $option = null;
        foreach ($request['options'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $chosen) {
                $option = $candidate;
                break;
            }
        }
        if ($option === null) {
            return $this->reject('OPTION_NOT_IN_REQUEST', 'the chosen option is not one of the offered options');
        }

        $responseScope = $response['scope'] ?? [];
        if (!\is_array($responseScope) || $responseScope === []) {
            $responseScope = $request['scope'];
        }
        $responseScope = array_values(array_unique(array_map('strval', $responseScope)));
        if (array_diff($responseScope, $request['scope']) !== []) {
            return $this->reject('SCOPE_ESCALATION', 'the response widens the scope beyond the request');
        }

        if (($option['requires_authorization'] ?? null) === 'HUMAN') {
            $grant = $this->matchingGrant($state, $chosen, $responseScope);
            if ($grant === null) {
                return $this->reject(
                    'HUMAN_AUTHORIZATION_REQUIRED',
                    'this option requires a human authorization grant; a Director answer alone is not enough',
                    ['option' => $chosen, 'scope' => $responseScope]
                );
            }
        }

        $changes = $option['patch'] ?? [];
        if (!\is_array($changes) || $changes === []) {
            return $this->reject('EMPTY_PATCH', 'the chosen option has no valid patch');
        }
        foreach ($changes as $change) {
            if (!\is_array($change) || !\is_string($change['op'] ?? null)) {
                return $this->reject('INVALID_PATCH_CHANGE', 'each patch change needs an op');
            }
            $changeScope = $change['scope'] ?? [];
            if (\is_array($changeScope) && array_diff(array_map('strval', $changeScope), $request['scope']) !== []) {
                return $this->reject('SCOPE_ESCALATION', 'a patch change widens the scope beyond the request');
            }
            if (($change['op'] ?? null) === 'SET_RISK_BOUNDARY') {
                return $this->reject('SAFETY_INVARIANT', 'the patch cannot rewrite the risk boundary');
            }

            $authorityOp = \in_array((string) ($change['op'] ?? ''), ['SET_CONTRACT', 'SET_THRESHOLD', 'REVOKE_CONTRACT'], true);
            if ($authorityOp || isset($change['authority_ref'])) {
                $ref = $change['authority_ref'] ?? null;
                if (!\is_string($ref) || $ref === '') {
                    return $this->reject('AUTHORITY_FACT_REQUIRED', 'a contract/threshold patch needs a host-issued authority reference');
                }
                if ($this->authority === null) {
                    return $this->reject('HOST_AUTHORITY_NOT_CONFIGURED', 'no host authority provider is configured for this patch');
                }
                $fact = $this->authority->resolve($ref);
                if ($fact === null) {
                    return $this->reject('UNKNOWN_AUTHORITY_REF', 'the patch authority reference is not registered');
                }
                $authorityEvent = [
                    'event_id' => 'patch:' . $chosen,
                    'type' => 'CONTRACT_CHANGED',
                    'scope' => $request['scope'],
                    'origin_scope' => \is_array($changeScope) && $changeScope !== [] ? $changeScope : $request['scope'],
                    'principal' => (string) ($response['principal'] ?? ''),
                    'change_key' => $change['change_key'] ?? $change['key'] ?? null,
                    'change_value' => $change['change_value'] ?? $change['value'] ?? null,
                ];
                $authorityCheck = $this->authority->revalidate($plan, $fact, $authorityEvent);
                if (!$authorityCheck['valid']) {
                    return $this->reject(
                        (string) $authorityCheck['basis'],
                        (string) $authorityCheck['reason'],
                        ['authority_ref' => $ref]
                    );
                }
            }
        }

        $patch = [
            'patch_id' => 'patch-' . substr(Canonical::hash([
                'request' => $request['request_id'],
                'option' => $chosen,
                'scope' => $responseScope,
                'base_revision' => $state->policyRevision,
            ]), 0, 12),
            'request_id' => $request['request_id'],
            'option' => $chosen,
            'base_policy_revision' => $state->policyRevision,
            'new_policy_revision' => $state->policyRevision + 1,
            'affected_scope' => $responseScope,
            'scope_revisions_before' => $state->scopeRevisions($responseScope),
            'slice_updates' => array_fill_keys($responseScope, $state->policyRevision + 1),
            'changes' => array_values($changes),
            'source' => 'director_response',
            'validated_by' => 'ProtocolPatchValidator',
            'publication_committed' => false,
        ];

        return [
            'valid' => true,
            'reason' => null,
            'request' => $request,
            'patch' => $patch,
            'authorization' => $option['requires_authorization'] ?? 'DIRECTOR',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function resolveRequest(State $state, array $response): ?array
    {
        $requestId = $response['request_id'] ?? null;
        if (\is_string($requestId) && $requestId !== '') {
            return $state->request($requestId);
        }
        $rootCause = $response['root_cause'] ?? null;
        if (\is_string($rootCause) && $rootCause !== '') {
            $scope = $response['scope'] ?? [];
            if (\is_array($scope) && $scope !== []) {
                $byScope = $state->openRequestForRootCauseScope($rootCause, array_map('strval', $scope));
                if ($byScope !== null) {
                    return $byScope;
                }
            }
            $open = [];
            foreach ($state->requests as $request) {
                if (($request['root_cause'] ?? null) === $rootCause && ($request['status'] ?? null) === 'OPEN') {
                    $open[] = $request;
                }
            }
            if (\count($open) === 1) {
                return $open[0];
            }
            if (\count($open) > 1) {
                return null;
            }

            return $state->requestByRootCause($rootCause);
        }

        return null;
    }

    /**
     * @param list<string> $scope
     * @return array<string, mixed>|null
     */
    private function matchingGrant(State $state, string $option, array $scope): ?array
    {
        foreach ($state->grants as $grant) {
            if (($grant['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                continue;
            }
            if ((string) ($grant['option'] ?? '') !== $option) {
                continue;
            }
            if (!State::covers(array_map('strval', $grant['scope'] ?? []), $scope)) {
                continue;
            }
            if ((int) ($grant['epoch'] ?? 0) < $state->scopeRevision($scope)) {
                continue;
            }

            return $grant;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function reject(string $reason, string $detail, array $extra = []): array
    {
        return array_merge([
            'valid' => false,
            'reason' => $reason,
            'detail' => $detail,
            'request' => null,
            'patch' => null,
            'authorization' => null,
        ], $extra);
    }
}
