<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * Replayable projection of a coordination plan. It is derived from the event
 * log; it is not a second authoritative ledger and it never commits anything.
 *
 * Policy revisions are tracked per work (a "policy slice"). A patch to one
 * scope must not invalidate an unrelated request or evidence merely because a
 * global counter moved; only the affected slices change.
 */
final class State
{
    public Plan $plan;

    /**
     * Host-provided authority facts, when the reducer is not running in the
     * frozen plan-simulation mode. Protocol handlers must use this provider
     * rather than reading an event's self-declared actor.
     */
    public ?AuthorityProvider $authority = null;

    public int $policyRevision;

    /** @var array<string, int> work id => last revision that changed its policy slice */
    public array $policySlices = [];

    /** @var array<string, array<string, mixed>> */
    public array $works = [];

    /** @var array<string, array<string, mixed>> */
    public array $evidence = [];

    /** @var array<string, array<string, mixed>> */
    public array $obligations = [];

    /** @var array<string, array<string, mixed>> */
    public array $holds = [];

    /** @var array<string, array<string, mixed>> */
    public array $negotiations = [];

    /** @var array<string, array<string, mixed>> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $patches = [];

    /** @var list<array<string, mixed>> */
    public array $localRebases = [];

    /** @var array<string, array<string, mixed>> */
    public array $grants = [];

    /** @var array<string, bool> */
    public array $processedEvents = [];

    /** @var array<string, string> event id => payload fingerprint (excluding event_id) */
    public array $eventFingerprints = [];

    /** @var array<string, int> root cause => first observed seq */
    public array $rootCauseSeen = [];

    /** @var list<array<string, mixed>> */
    public array $actions = [];

    /** @var list<array<string, mixed>> */
    public array $denials = [];

    /** @var list<array<string, mixed>> */
    public array $rejections = [];

    /** @var list<array<string, mixed>> */
    public array $notes = [];

    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function __construct(Plan $plan)
    {
        $this->plan = $plan;
        $this->policyRevision = $plan->revision();
        foreach ($plan->works() as $work) {
            $id = (string) $work['id'];
            $this->works[$id] = [
                'id' => $id,
                'purpose' => $work['purpose'] ?? null,
                'owners' => array_values($work['owners'] ?? []),
                'depends_on' => array_values($work['depends_on'] ?? []),
                'status' => 'ASSIGNED',
                'publish_held' => false,
            ];
            $this->policySlices[$id] = $this->policyRevision;
        }
    }

    /**
     * @param list<string> $scope
     * @return array<string, int>
     */
    public function scopeRevisions(array $scope): array
    {
        $revisions = [];
        foreach ($this->normalizeScope($scope) as $workId) {
            if (isset($this->policySlices[$workId])) {
                $revisions[$workId] = $this->policySlices[$workId];
            }
        }

        return $revisions;
    }

    /**
     * The revision relevant to a scope is the newest slice in that scope.
     *
     * @param list<string> $scope
     */
    public function scopeRevision(array $scope): int
    {
        $revisions = $this->scopeRevisions($scope);

        return $revisions === [] ? 0 : max(array_values($revisions));
    }

    /**
     * @param list<string> $scope
     */
    public function bumpPolicySlices(array $scope, int $revision): void
    {
        foreach ($this->normalizeScope($scope) as $workId) {
            if (isset($this->policySlices[$workId])) {
                $this->policySlices[$workId] = max($this->policySlices[$workId], $revision);
            }
        }
    }

    /**
     * @param list<string> $scope
     */
    public function addEvidence(string $evidenceId, array $scope, string $result, string $source, int $policyRevision): void
    {
        $this->evidence[$evidenceId] = [
            'evidence_id' => $evidenceId,
            'scope' => self::normalizeScope($scope),
            'scope_revision' => $this->scopeRevision($scope),
            'result' => $result,
            'status' => $result === 'pass' ? 'CURRENT' : 'FAILED',
            'source' => $source,
            'policy_revision' => $policyRevision,
            'recorded_at_revision' => $this->policyRevision,
        ];
    }

    /**
     * @param list<string> $scope
     * @return list<string> invalidated evidence ids
     */
    public function invalidateEvidenceForScope(array $scope, string $reason, string $source): array
    {
        $invalidated = [];
        foreach ($this->evidence as $id => $evidence) {
            if (self::intersects($evidence['scope'], $scope) && $evidence['status'] === 'CURRENT') {
                $this->evidence[$id]['status'] = 'STALE';
                $this->evidence[$id]['stale_reason'] = $reason;
                $this->evidence[$id]['stale_source'] = $source;
                $invalidated[] = $id;
            }
        }

        return $invalidated;
    }

    /**
     * @param list<string> $scope
     */
    public function addObligation(
        string $kind,
        array $scope,
        string $source,
        string $reason,
        string $rootCause = '',
        string $reasonId = ''
    ): string {
        $normalized = self::normalizeScope($scope);
        $reasonId = $reasonId === '' ? $kind : $reasonId;
        $id = 'obl:' . substr(Canonical::hash([
            'kind' => $kind,
            'scope' => $normalized,
            'source' => $source,
            'reason' => $reason,
            'root_cause' => $rootCause,
            'reason_id' => $reasonId,
        ]), 0, 12);

        if (!isset($this->obligations[$id])) {
            $this->obligations[$id] = [
                'obligation_id' => $id,
                'kind' => $kind,
                'reason_id' => $reasonId,
                'scope' => $normalized,
                'source' => $source,
                'reason' => $reason,
                'root_cause' => $rootCause,
                'status' => 'OPEN',
                'created_revision' => $this->policyRevision,
                'discharged_by' => null,
            ];
        }

        return $id;
    }

    /**
     * @param list<string> $providedScope
     * @param list<string>|null $claims obligation kinds or ids; null means "all in scope"
     * @param string|null $rootCause when set, only obligations with this root cause are eligible
     * @return list<string>
     */
    public function dischargeObligations(array $providedScope, string $evidenceId, ?array $claims = null, ?string $rootCause = null): array
    {
        $discharged = [];
        foreach ($this->obligations as $id => $obligation) {
            if ($obligation['status'] !== 'OPEN') {
                continue;
            }
            if (!self::covers($providedScope, $obligation['scope'])) {
                continue;
            }
            if ($claims !== null
                && !\in_array((string) $obligation['kind'], $claims, true)
                && !\in_array((string) $obligation['obligation_id'], $claims, true)
                && !\in_array((string) ($obligation['reason_id'] ?? ''), $claims, true)) {
                continue;
            }
            if ($rootCause !== null && (string) ($obligation['root_cause'] ?? '') !== $rootCause) {
                continue;
            }
            $this->obligations[$id]['status'] = 'DISCHARGED';
            $this->obligations[$id]['discharged_by'] = $evidenceId;
            $discharged[] = $id;
        }

        return $discharged;
    }

    /**
     * @param list<string> $scope
     */
    public function addHold(
        string $purpose,
        array $scope,
        string $reason,
        string $source,
        string $until = 'MANUAL_RELEASE',
        string $reasonId = '',
        string $owner = 'PROTOCOL',
        string $condition = '',
        string $conditionRef = '',
        string $rootCause = ''
    ): string {
        $normalized = self::normalizeScope($scope);
        $reasonId = $reasonId === '' ? $reason : $reasonId;
        if ($condition === '' && $until === 'MANUAL_RELEASE') {
            $condition = 'MANUAL_RELEASE';
        }
        $id = 'hold:' . substr(Canonical::hash([
            'purpose' => $purpose,
            'scope' => $normalized,
            'reason' => $reason,
            'source' => $source,
            'reason_id' => $reasonId,
            'condition_ref' => $conditionRef,
        ]), 0, 12);

        if (!isset($this->holds[$id])) {
            $this->holds[$id] = [
                'hold_id' => $id,
                'reason_id' => $reasonId,
                'purpose' => $purpose,
                'owner' => $owner,
                'scope' => $normalized,
                'reason' => $reason,
                'source' => $source,
                'condition' => $condition,
                'condition_ref' => $conditionRef,
                'root_cause' => $rootCause,
                'until' => $until,
                'status' => 'ACTIVE',
                'created_revision' => $this->policyRevision,
            ];
        }
        foreach ($normalized as $workId) {
            if (isset($this->works[$workId])) {
                $this->works[$workId]['publish_held'] = true;
            }
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    public function dischargeObligationsByRootCause(string $rootCause, string $evidenceId): array
    {
        $discharged = [];
        foreach ($this->obligations as $id => $obligation) {
            if ($obligation['status'] === 'OPEN' && ($obligation['root_cause'] ?? '') === $rootCause) {
                $this->obligations[$id]['status'] = 'DISCHARGED';
                $this->obligations[$id]['discharged_by'] = $evidenceId;
                $discharged[] = $id;
            }
        }

        return $discharged;
    }

    /**
     * Release only holds whose own reason_id has no remaining open obligation.
     * Discharging one obligation must not release a differently rooted hold.
     *
     * @param list<string> $scope
     * @param list<string> $obligationIds
     * @return list<string>
     */
    public function releaseHoldsWhenDischarged(array $scope, array $obligationIds): array
    {
        $released = [];
        $reasonIds = [];
        foreach ($obligationIds as $obligationId) {
            $reasonId = (string) ($this->obligations[$obligationId]['reason_id'] ?? '');
            if ($reasonId !== '') {
                $reasonIds[$reasonId] = true;
            }
        }

        foreach ($this->holds as $id => $hold) {
            if ($hold['status'] !== 'ACTIVE' || $hold['purpose'] !== 'PUBLISH') {
                continue;
            }
            if (($hold['until'] ?? '') !== 'CURRENT_EVIDENCE') {
                continue;
            }
            if (!self::intersects($hold['scope'], $scope)) {
                continue;
            }
            $holdReason = (string) ($hold['reason_id'] ?? '');
            if ($holdReason !== '') {
                if (!isset($reasonIds[$holdReason])) {
                    continue;
                }
                if ($this->hasOpenObligationForReason($holdReason, $hold['scope'])) {
                    continue;
                }
            } elseif ($this->openObligationsFor($hold['scope']) !== []) {
                continue;
            }
            $this->holds[$id]['status'] = 'RELEASED';
            $released[] = $id;
        }
        $this->refreshPublishHeld();

        return $released;
    }

    /**
     * @param list<string> $scope
     */
    public function hasOpenObligationForReason(string $reasonId, array $scope): bool
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation['status'] !== 'OPEN') {
                continue;
            }
            if ((string) ($obligation['reason_id'] ?? '') !== $reasonId) {
                continue;
            }
            if (self::intersects($obligation['scope'], $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Director decision releases only the holds created for that root cause.
     * Holds for another root cause in the same work remain active.
     *
     * @param list<string> $scope
     * @return list<string>
     */
    public function releaseDecisionHolds(string $rootCause, array $scope): array
    {
        $released = [];
        foreach ($this->holds as $id => $hold) {
            if ($hold['status'] !== 'ACTIVE' || ($hold['until'] ?? '') !== 'DIRECTOR_DECISION') {
                continue;
            }
            if ((string) ($hold['root_cause'] ?? '') !== $rootCause) {
                continue;
            }
            if (!self::intersects($hold['scope'], $scope)) {
                continue;
            }
            $this->holds[$id]['status'] = 'RELEASED';
            $released[] = $id;
        }
        $this->refreshPublishHeld();

        return $released;
    }

    /**
     * @param list<string> $scope
     * @return list<string>
     */
    public function releaseHoldsByReasonPrefix(string $prefix, array $scope): array
    {
        $released = [];
        foreach ($this->holds as $id => $hold) {
            if ($hold['status'] !== 'ACTIVE' || !str_starts_with((string) $hold['reason'], $prefix)) {
                continue;
            }
            if (!self::intersects($hold['scope'], $scope)) {
                continue;
            }
            $this->holds[$id]['status'] = 'RELEASED';
            $released[] = $id;
        }
        $this->refreshPublishHeld();

        return $released;
    }

    /**
     * Release a permit-revocation hold only when a new active grant covers the
     * revoked scope (and, when known, the same option).
     *
     * @param array<string, mixed> $grant
     * @return list<string>
     */
    public function releaseGrantHolds(array $grant): array
    {
        $released = [];
        $grantScope = array_map('strval', $grant['scope'] ?? []);
        $grantOption = $grant['option'] ?? null;
        foreach ($this->holds as $id => $hold) {
            if ($hold['status'] !== 'ACTIVE' || ($hold['until'] ?? '') !== 'NEW_GRANT') {
                continue;
            }
            if (!self::covers($grantScope, $hold['scope'])) {
                continue;
            }
            $revokedGrantId = (string) ($hold['condition_ref'] ?? '');
            $revokedOption = $this->grants[$revokedGrantId]['option'] ?? null;
            if ($revokedOption !== null && $grantOption !== null && (string) $revokedOption !== (string) $grantOption) {
                continue;
            }
            $this->holds[$id]['status'] = 'RELEASED';
            if ($revokedGrantId !== '' && isset($this->grants[$revokedGrantId])) {
                $this->grants[$revokedGrantId]['status'] = 'SUPERSEDED';
                $this->grants[$revokedGrantId]['superseded_by'] = (string) ($grant['grant_id'] ?? '');
            }
            $released[] = $id;
        }
        $this->refreshPublishHeld();

        return $released;
    }

    /**
     * @param list<string> $scope
     * @return list<string>
     */
    public function releaseHoldsByUntil(array $scope, string $until): array
    {
        $released = [];
        foreach ($this->holds as $id => $hold) {
            if ($hold['status'] !== 'ACTIVE' || ($hold['until'] ?? '') !== $until) {
                continue;
            }
            if (!self::intersects($hold['scope'], $scope)) {
                continue;
            }
            $this->holds[$id]['status'] = 'RELEASED';
            $released[] = $id;
        }
        $this->refreshPublishHeld();

        return $released;
    }

    /**
     * @param list<string> $scope
     * @return list<array<string, mixed>>
     */
    public function openObligationsFor(array $scope): array
    {
        $open = [];
        foreach ($this->obligations as $obligation) {
            if ($obligation['status'] === 'OPEN' && self::intersects($obligation['scope'], $scope)) {
                $open[] = $obligation;
            }
        }

        return $open;
    }

    public function refreshPublishHeld(): void
    {
        foreach ($this->works as $id => $work) {
            $this->works[$id]['publish_held'] = $this->isPublishHeld([$id]);
        }
    }

    /**
     * @param list<string> $scope
     */
    public function isPublishHeld(array $scope): bool
    {
        foreach ($this->holds as $hold) {
            if ($hold['status'] === 'ACTIVE' && $hold['purpose'] === 'PUBLISH' && self::intersects($hold['scope'], $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Publication of a work is blocked by a hold on any work in its transitive
     * dependency closure. A hold on a prerequisite is not invisible to its
     * dependents.
     *
     * @param list<string> $scope
     */
    public function isPublishHeldDeep(array $scope): bool
    {
        foreach ($scope as $workId) {
            $prerequisites = $this->plan->dependencyPrerequisites([(string) $workId]);
            if ($this->isPublishHeld($prerequisites)
                || $this->openObligationsFor($prerequisites) !== []
                || $this->hasPermitRevocation($prerequisites)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $scope
     */
    public function hasPermitRevocation(array $scope): bool
    {
        foreach ($this->grants as $grant) {
            if (($grant['status'] ?? 'ACTIVE') === 'REVOKED' && self::intersects($grant['scope'] ?? [], $scope)) {
                return true;
            }
        }

        return false;
    }

    public function addRequest(array $request): void
    {
        $this->requests[(string) $request['request_id']] = $request;
    }

    public function openRequestForRootCause(string $rootCause): ?array
    {
        foreach ($this->requests as $request) {
            if (($request['root_cause'] ?? null) === $rootCause && ($request['status'] ?? null) === 'OPEN') {
                return $request;
            }
        }

        return null;
    }

    /**
     * Dedupe identity includes the scope. The same root-cause label on two
     * disjoint scopes is two open decisions, not one merged decision.
     *
     * @param list<string> $scope
     */
    public function openRequestForRootCauseScope(string $rootCause, array $scope): ?array
    {
        $scope = self::normalizeScope($scope);
        foreach ($this->requests as $request) {
            if (($request['root_cause'] ?? null) !== $rootCause || ($request['status'] ?? null) !== 'OPEN') {
                continue;
            }
            if (self::normalizeScope($request['scope'] ?? []) === $scope) {
                return $request;
            }
        }

        return null;
    }

    public function request(string $requestId): ?array
    {
        return $this->requests[$requestId] ?? null;
    }

    public function requestByRootCause(string $rootCause): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if (($request['root_cause'] ?? null) === $rootCause) {
                return $request;
            }
        }

        return null;
    }

    /**
     * @param list<string> $scope
     */
    public function requestByRootCauseScope(string $rootCause, array $scope): ?array
    {
        $scope = self::normalizeScope($scope);
        foreach (array_reverse($this->requests) as $request) {
            if (($request['root_cause'] ?? null) !== $rootCause) {
                continue;
            }
            if (self::normalizeScope($request['scope'] ?? []) === $scope) {
                return $request;
            }
        }

        return null;
    }

    public function closeRequest(string $requestId, string $status): void
    {
        if (isset($this->requests[$requestId])) {
            $this->requests[$requestId]['status'] = $status;
            $this->requests[$requestId]['closed_at_revision'] = $this->policyRevision;
        }
    }

    public function applyPatch(array $patch): void
    {
        $this->patches[] = $patch;
        $this->policyRevision = max($this->policyRevision, (int) $patch['new_policy_revision']);
        $this->bumpPolicySlices(array_map('strval', $patch['affected_scope'] ?? []), (int) $patch['new_policy_revision']);
    }

    /**
     * @return list<string>
     */
    public function workIds(): array
    {
        return array_keys($this->works);
    }

    /**
     * @param list<string> $scope
     * @return list<string>
     */
    public function normalizeScope(array $scope): array
    {
        $normalized = array_values(array_unique(array_map('strval', $scope)));
        sort($normalized, \SORT_STRING);

        return $normalized;
    }

    /**
     * @param list<string> $required
     * @param list<string> $provided
     */
    public static function covers(array $provided, array $required): bool
    {
        return array_diff($required, $provided) === [];
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    public static function intersects(array $left, array $right): bool
    {
        return array_intersect($left, $right) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function projection(): array
    {
        $works = [];
        foreach ($this->works as $id => $work) {
            $works[$id] = [
                'status' => $work['status'],
                'publish_held' => $work['publish_held'],
                'depends_on' => $work['depends_on'],
                'policy_slice' => $this->policySlices[$id] ?? $this->policyRevision,
            ];
        }
        $evidence = [];
        foreach ($this->evidence as $id => $item) {
            $evidence[$id] = [
                'scope' => $item['scope'],
                'scope_revision' => $item['scope_revision'],
                'status' => $item['status'],
                'result' => $item['result'],
            ];
        }
        $obligations = [];
        foreach ($this->obligations as $id => $item) {
            $obligations[$id] = [
                'kind' => $item['kind'],
                'reason_id' => $item['reason_id'],
                'scope' => $item['scope'],
                'status' => $item['status'],
            ];
        }
        $holds = [];
        foreach ($this->holds as $id => $item) {
            $holds[$id] = [
                'reason_id' => $item['reason_id'],
                'purpose' => $item['purpose'],
                'owner' => $item['owner'],
                'scope' => $item['scope'],
                'condition' => $item['condition'],
                'created_revision' => $item['created_revision'],
                'version' => $item['created_revision'],
                'status' => $item['status'],
            ];
        }
        $negotiations = [];
        foreach ($this->negotiations as $id => $item) {
            $negotiations[$id] = [
                'scope' => $item['scope'],
                'rounds' => $item['rounds'],
                'status' => $item['status'],
            ];
        }
        $requests = [];
        foreach ($this->requests as $id => $request) {
            $requests[$id] = [
                'reason' => $request['reason'],
                'scope' => $request['scope'],
                'scope_revision' => $request['scope_revision'] ?? null,
                'status' => $request['status'],
            ];
        }

        return [
            'policy_revision' => $this->policyRevision,
            'policy_slices' => $this->policySlices,
            'works' => $works,
            'evidence' => $evidence,
            'obligations' => $obligations,
            'holds' => $holds,
            'negotiations' => $negotiations,
            'requests' => $requests,
            'local_rebases' => $this->localRebases,
            'patches' => array_map(static fn (array $patch): array => [
                'patch_id' => $patch['patch_id'],
                'new_policy_revision' => $patch['new_policy_revision'],
                'affected_scope' => $patch['affected_scope'],
                'slice_updates' => $patch['slice_updates'] ?? [],
            ], $this->patches),
        ];
    }

    public function digest(): string
    {
        return Canonical::hash($this->projection());
    }
}
