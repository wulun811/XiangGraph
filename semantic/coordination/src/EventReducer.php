<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * Deterministic discrete-event reducer for the XG coordination policy.
 *
 * It consumes authenticated event records and produces a replayable projection
 * plus actions/holds/escalations. It never executes a tool, calls a model,
 * commits a publication, or writes an external effect.
 */
final class EventReducer
{
    public function __construct(
        private ProtocolComposer $composer,
        private EscalationRouter $router,
        private ProtocolPatchValidator $validator,
        private ?AuthorityProvider $authority = null
    ) {
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function reduce(Plan $plan, array $events, array $options = []): array
    {
        $state = new State($plan);
        $state->authority = $this->authority;
        $steps = [];
        $invalidEvents = [];
        $metrics = [
            'director_wakeups' => 0,
            'director_decisions' => 0,
            'director_timeouts' => 0,
            'deduplicated_requests' => 0,
            'suppressed_requests' => 0,
            'updated_existing_requests' => 0,
            'rejected_unnecessary_escalations' => 0,
            'stale_responses_rejected' => 0,
            'late_responses_rejected' => 0,
            'duplicate_events_ignored' => 0,
            'duplicate_event_conflicts' => 0,
            'closure_expansions' => 0,
            'continue_blocked_by_hold' => 0,
            'unverified_classification_held' => 0,
            'reopened_after_timeout' => 0,
            'human_grants' => 0,
            'out_of_order_held' => 0,
            'local_contract_adoptions' => 0,
            'contract_change_escalations' => 0,
            'delegation_range_escalations' => 0,
            'unsafe_allows' => 0,
            'blind_retry_denied' => 0,
        ];
        if ($this->authority !== null && $this->authority->source() === AuthorityProvider::SOURCE_HOST) {
            $metrics['authority_revalidation_failures'] = 0;
        }

        $ordered = $events;
        usort($ordered, static function (array $left, array $right): int {
            $bySeq = ((int) ($left['seq'] ?? 0)) <=> ((int) ($right['seq'] ?? 0));

            return $bySeq !== 0 ? $bySeq : strcmp((string) ($left['event_id'] ?? ''), (string) ($right['event_id'] ?? ''));
        });

        $buffer = [];
        foreach ($ordered as $event) {
            $errors = ProtocolContract::eventErrors($event);
            if ($errors !== []) {
                $invalidEvents[] = ['event' => $event, 'errors' => $errors];
                continue;
            }
            if (isset($event['causal_parent']) && !isset($state->processedEvents[(string) $event['causal_parent']])) {
                $buffer[] = $event;
                continue;
            }
            $steps[] = $this->processEvent($plan, $state, $event, $metrics);
            $this->drainBuffer($plan, $state, $buffer, $steps, $metrics);
        }

        foreach ($buffer as $event) {
            ++$metrics['out_of_order_held'];
            $steps[] = [
                'seq' => $event['seq'] ?? null,
                'event_id' => $event['event_id'] ?? null,
                'event_type' => $event['type'] ?? null,
                'event_scope' => $event['scope'] ?? [],
                'zone' => 'LOCAL_DETERMINISTIC',
                'result' => 'OUT_OF_ORDER_HELD',
                'detail' => 'the causal parent was not observed; the event was not applied',
                'actions' => [],
                'holds' => [],
                'obligations' => [],
                'invalidations' => [],
                'escalation' => null,
                'state_digest' => $state->digest(),
            ];
        }

        $requests = array_values($state->requests);
        $preservedWork = [];
        foreach ($state->works as $id => $work) {
            if ($work['status'] !== 'ABORTED' && !$work['publish_held']) {
                $preservedWork[] = $id;
            }
        }
        $expected = $options['expected_escalations'] ?? [];
        $expectedRoots = [];
        foreach (\is_array($expected) ? $expected : [] as $item) {
            if (\is_string($item) && $item !== '') {
                $expectedRoots[] = $item;
            } elseif (\is_array($item) && \is_string($item['root_cause'] ?? null)) {
                $expectedRoots[] = (string) $item['root_cause'];
            }
        }
        $requestRoots = array_values(array_unique(array_map(
            static fn (array $request): string => (string) ($request['root_cause'] ?? ''),
            $requests
        )));
        $matched = array_values(array_intersect($expectedRoots, $requestRoots));
        $missed = array_values(array_diff($expectedRoots, $requestRoots));
        $false = $expectedRoots === [] ? [] : array_values(array_diff($requestRoots, $expectedRoots));
        $latencies = [];
        foreach ($requests as $request) {
            $root = (string) ($request['root_cause'] ?? '');
            if (isset($state->rootCauseSeen[$root])) {
                $latencies[] = max(0, (int) ($request['created_at_seq'] ?? 0) - $state->rootCauseSeen[$root]);
            }
        }
        $publishAllowedScopes = [];
        foreach ($steps as $step) {
            foreach ($step['actions'] as $action) {
                if (($action['action'] ?? '') === 'ALLOW_PUBLISH_CURRENT_SNAPSHOT') {
                    $publishAllowedScopes = array_merge($publishAllowedScopes, $action['scope'] ?? []);
                }
            }
        }
        $stalledWork = [];
        foreach ($state->works as $workId => $work) {
            if ($work['publish_held'] === true) {
                $stalledWork[] = (string) $workId;
            }
        }
        sort($stalledWork, \SORT_STRING);
        $completedWork = array_values(array_unique($publishAllowedScopes));
        sort($completedWork, \SORT_STRING);

        $trace = [
            'run_id' => (string) ($options['run_id'] ?? 'COORD-R0-REPLAY-20261113-01'),
            'scenario_id' => (string) ($options['scenario_id'] ?? 'unnamed'),
            'mode' => 'XG',
            'plan_id' => $plan->id(),
            'plan_hash' => $plan->hash,
            'execution_zone' => 'SIMULATED_EVENTS',
            'real_model' => false,
            'publication_committed' => false,
            'remote_effect' => 'SIMULATED_ONLY',
            'measurement' => [
                'execution' => 'SIMULATED_EVENTS / FIXED_AGENT_STANDINS / NOT_REAL_MODEL',
                'model_tokens' => null,
                'wall_clock_ms' => null,
                'not_measured' => ['model_tokens', 'wall_clock_ms', 'real_agent_cost'],
            ],
            'event_count' => \count($events),
            'processed_event_count' => \count($state->processedEvents),
            'invalid_events' => $invalidEvents,
            'steps' => $steps,
            'director_requests' => $requests,
            'patches' => $state->patches,
            'rejections' => $state->rejections,
            'denials' => $state->denials,
            'grants' => array_values($state->grants),
            'negotiations' => array_values($state->negotiations),
            'notes' => $state->notes,
            'state' => $state->projection(),
            'metrics' => array_merge($metrics, [
                'director_wakeups' => $metrics['director_wakeups'],
                'director_zero_wakeups' => $metrics['director_wakeups'] === 0,
                'open_director_requests' => \count(array_filter($requests, static fn (array $request): bool => ($request['status'] ?? '') === 'OPEN')),
                'preserved_work' => $preservedWork,
                'stalled_work' => $stalledWork,
                'completed_work' => $completedWork,
                'expected_escalation_roots' => $expectedRoots,
                'necessary_escalations' => \count($matched),
                'missed_escalations' => $missed,
                'false_escalations' => $false,
                'necessary_escalation_recall' => $expectedRoots === [] ? null : \count($matched) / \count($expectedRoots),
                'escalation_latencies' => $latencies,
                'escalation_latency_max' => $latencies === [] ? null : max($latencies),
                'policy_revision' => $state->policyRevision,
                'policy_slices' => $state->policySlices,
            ]),
        ];
        $trace['trace_hash'] = Canonical::hash([
            'plan_hash' => $plan->hash,
            'steps' => $steps,
            'state' => $trace['state'],
            'requests' => $requests,
            'patches' => $state->patches,
        ]);

        return $trace;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function processEvent(Plan $plan, State $state, array $event, array &$metrics): array
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $payload = $event;
        unset($payload['event_id'], $payload['seq']);
        $fingerprint = Canonical::hash($payload);
        if (isset($state->processedEvents[$eventId])) {
            if (($state->eventFingerprints[$eventId] ?? '') !== $fingerprint) {
                ++$metrics['duplicate_event_conflicts'];
                $state->rejections[] = [
                    'code' => 'EVENT_ID_PAYLOAD_CONFLICT',
                    'event_id' => $eventId,
                    'detail' => 'the same event_id was observed with a different payload; it was not silently ignored',
                ];

                return [
                    'seq' => $event['seq'] ?? null,
                    'event_id' => $eventId,
                    'event_type' => $event['type'] ?? null,
                    'event_scope' => $event['scope'] ?? [],
                    'zone' => 'LOCAL_DETERMINISTIC',
                    'result' => 'REJECTED_EVENT_ID_PAYLOAD_CONFLICT',
                    'detail' => 'event_id reused with a different payload',
                    'actions' => [],
                    'holds' => [],
                    'obligations' => [],
                    'invalidations' => [],
                    'escalation' => null,
                    'state_digest' => $state->digest(),
                ];
            }
            ++$metrics['duplicate_events_ignored'];

            return [
                'seq' => $event['seq'] ?? null,
                'event_id' => $eventId,
                'event_type' => $event['type'] ?? null,
                'event_scope' => $event['scope'] ?? [],
                'zone' => 'LOCAL_DETERMINISTIC',
                'result' => 'DUPLICATE_IGNORED',
                'detail' => 'event_id and payload were already processed; no state change',
                'actions' => [],
                'holds' => [],
                'obligations' => [],
                'invalidations' => [],
                'escalation' => null,
                'state_digest' => $state->digest(),
            ];
        }

        $state->processedEvents[$eventId] = true;
        $state->eventFingerprints[$eventId] = $fingerprint;
        $state->events[] = $event;
        $type = (string) ($event['type'] ?? '');
        $scope = $event['scope'] ?? [];
        if (!\is_array($scope)) {
            $scope = [];
        }
        $scope = $state->normalizeScope($scope);
        $originScope = $scope;
        $rootCause = $event['root_cause'] ?? null;
        if (\is_string($rootCause) && $rootCause !== '' && !isset($state->rootCauseSeen[$rootCause])) {
            $state->rootCauseSeen[$rootCause] = (int) ($event['seq'] ?? 0);
        }
        $unknownScope = array_values(array_diff($scope, $plan->workIds()));
        if ($unknownScope !== []) {
            $state->rejections[] = [
                'code' => 'UNKNOWN_WORK_SCOPE',
                'event_id' => $eventId,
                'unknown_scope' => $unknownScope,
            ];
            $scope = array_values(array_intersect($scope, $plan->workIds()));
            if ($scope === []) {
                return [
                    'seq' => $event['seq'] ?? null,
                    'event_id' => $eventId,
                    'event_type' => $type,
                    'event_scope' => [],
                    'zone' => 'LOCAL_DETERMINISTIC',
                    'result' => 'REJECTED_UNKNOWN_WORK_SCOPE',
                    'detail' => 'the event references work ids outside the plan; it was not applied',
                    'actions' => [],
                    'holds' => [],
                    'obligations' => [],
                    'invalidations' => [],
                    'escalation' => null,
                    'state_digest' => $state->digest(),
                ];
            }
        }
        $zone = 'LOCAL_DETERMINISTIC';
        $scopeAddedByClosure = [];
        $propagationTypes = [
            'DEP_CHANGED',
            'SCHEMA_CONTRACT_CHANGED',
            'DEPENDENCY_ADDED',
            'DEPENDENCY_REMOVED',
            'CONTRACT_CHANGED',
            'UNMAPPED_CHANGE',
        ];
        if (\in_array($type, $propagationTypes, true)) {
            $closure = $plan->dependencyClosure($scope);
            $scopeAddedByClosure = array_values(array_diff($closure, $scope));
            if ($scopeAddedByClosure !== []) {
                $scope = $state->normalizeScope($closure);
                ++$metrics['closure_expansions'];
            }
        }
        $event['scope'] = $scope;
        $event['origin_scope'] = $originScope;
        $result = 'APPLIED';
        $detail = null;
        $actions = [];
        $holds = [];
        $obligations = [];
        $invalidations = [];
        $escalation = null;
        $patch = null;
        $negotiation = null;
        $protocols = [];
        $localRebases = [];

        if ($type === 'CURRENT_EVIDENCE_RECEIVED') {
            [$actions, $obligations, $holds, $invalidations, $detail, $result] = $this->applyCurrentEvidence($state, $event, $scope);
        } elseif ($type === 'DIRECTOR_RESPONSE') {
            [$actions, $patch, $detail, $result] = $this->applyDirectorResponse($plan, $state, $event, $metrics);
            $zone = 'DIRECTOR_DECISION';
        } elseif ($type === 'HUMAN_GRANT') {
            $zone = 'HUMAN_AUTHORIZATION';
            $humanActor = $plan->authority()['human_authorizer'] ?? '';
            if ((string) ($event['actor'] ?? '') !== $humanActor) {
                $state->rejections[] = [
                    'code' => 'HUMAN_GRANT_ACTOR_NOT_AUTHORIZED',
                    'event_id' => $eventId,
                    'actor' => $event['actor'] ?? null,
                ];
                $actions[] = [
                    'action' => 'REJECT_HUMAN_GRANT',
                    'scope' => $scope,
                    'zone' => 'HUMAN_AUTHORIZATION',
                    'reason' => 'the grant did not come from the configured human authorizer',
                ];
                $detail = 'human grant rejected: actor is not the configured human authorizer';
                $result = 'REJECTED_HUMAN_GRANT_ACTOR';
            } else {
                $grant = [
                    'grant_id' => (string) ($event['grant_id'] ?? ('grant:' . $eventId)),
                    'scope' => $scope,
                    'option' => (string) ($event['option'] ?? ''),
                    'status' => 'ACTIVE',
                    'epoch' => (int) ($event['epoch'] ?? $state->policyRevision),
                    'source' => 'human_authorization',
                    'recorded_at_revision' => $state->policyRevision,
                ];
                $state->grants[$grant['grant_id']] = $grant;
                ++$metrics['human_grants'];
                $released = $state->releaseGrantHolds($grant);
                $actions[] = [
                    'action' => 'HUMAN_AUTHORIZATION_RECORDED',
                    'scope' => $scope,
                    'zone' => 'HUMAN_AUTHORIZATION',
                    'reason' => 'an explicit human grant was recorded; it is not a Director claim',
                    'grant_id' => $grant['grant_id'],
                    'option' => $grant['option'],
                    'released_holds' => $released,
                ];
                foreach ($released as $holdId) {
                    $holds[] = $state->holds[$holdId];
                }
                $detail = 'human authorization recorded';
            }
        } elseif ($type === 'DIRECTOR_TIMEOUT') {
            ++$metrics['director_timeouts'];
            [$actions, $holds, $detail, $result] = $this->applyDirectorTimeout($state, $event);
        } elseif ($type === 'PERMIT_REVOKED') {
            $grantId = (string) ($event['grant_id'] ?? '');
            if ($grantId !== '') {
                if (!isset($state->grants[$grantId])) {
                    $state->grants[$grantId] = [
                        'grant_id' => $grantId,
                        'scope' => $scope,
                        'option' => null,
                        'status' => 'REVOKED',
                        'epoch' => 0,
                        'source' => 'authority_revocation',
                        'recorded_at_revision' => $state->policyRevision,
                    ];
                } else {
                    $state->grants[$grantId]['status'] = 'REVOKED';
                }
            }
            $holdId = $state->addHold(
                'PUBLISH',
                $scope,
                'PERMIT_REVOKED',
                'authority',
                'NEW_GRANT',
                'permit:' . $grantId,
                'AUTHORITY',
                'NEW_GRANT',
                $grantId,
                'permit:' . $grantId
            );
            $holds[] = $state->holds[$holdId];
            $actions[] = [
                'action' => 'DENY_PUBLISH',
                'scope' => $scope,
                'zone' => 'LOCAL_DETERMINISTIC',
                'reason' => 'the permit was revoked; past evidence may be shown but dangerous publication is denied',
                'publication_committed' => false,
            ];
            $detail = 'permit revoked; publish denied';
        } else {
            $composition = $this->composer->compose($plan, $event, $state);
            $zone = (string) $composition['zone'];
            $protocols = $composition['participants'];
            $negotiation = $composition['negotiation'];
            $detail = $composition['notes'] !== [] ? implode(' | ', $composition['notes']) : null;

            foreach ($composition['invalidations'] as $invalidation) {
                $invalidated = $state->invalidateEvidenceForScope($invalidation['scope'], $invalidation['reason'], $invalidation['source']);
                $invalidations[] = [
                    'scope' => $invalidation['scope'],
                    'reason' => $invalidation['reason'],
                    'source' => $invalidation['source'],
                    'evidence_ids' => $invalidated,
                ];
            }
            foreach ($composition['obligations'] as $obligation) {
                $id = $state->addObligation(
                    (string) $obligation['kind'],
                    $obligation['scope'],
                    (string) $obligation['source'],
                    (string) $obligation['reason'],
                    (string) ($obligation['root_cause'] ?? ''),
                    (string) ($obligation['reason_id'] ?? '')
                );
                $obligations[] = $state->obligations[$id];
            }
            foreach ($composition['holds'] as $hold) {
                $id = $state->addHold(
                    (string) ($hold['purpose'] ?? 'PUBLISH'),
                    $hold['scope'],
                    (string) $hold['reason'],
                    (string) $hold['source'],
                    (string) ($hold['until'] ?? 'CURRENT_EVIDENCE'),
                    (string) ($hold['reason_id'] ?? ''),
                    (string) ($hold['owner'] ?? 'PROTOCOL'),
                    (string) ($hold['condition'] ?? ''),
                    (string) ($hold['condition_ref'] ?? ''),
                    (string) ($hold['root_cause'] ?? '')
                );
                $holds[] = $state->holds[$id];
            }
            if (\is_array($negotiation)) {
                $rootCause = (string) $negotiation['root_cause'];
                $negotiationKey = $rootCause . '|' . implode(',', array_map('strval', $negotiation['scope']));
                $state->negotiations[$negotiationKey] = [
                    'root_cause' => $rootCause,
                    'scope' => $negotiation['scope'],
                    'rounds' => (int) $negotiation['rounds'],
                    'max_rounds' => (int) $negotiation['max_rounds'],
                    'allowed' => (bool) $negotiation['allowed'],
                    'status' => (string) $negotiation['status'],
                ];
            }

            $actions = $composition['allowable_actions'];
            foreach ($composition['denied_actions'] as $denial) {
                $state->denials[] = $denial;
                if (($denial['code'] ?? '') === 'DENY_NEW_KEY_BLIND_RETRY') {
                    ++$metrics['blind_retry_denied'];
                }
            }
            foreach ($actions as $action) {
                if (\in_array($action['action'], ['ALLOW_PUBLISH_CURRENT_SNAPSHOT', 'PUBLISH', 'COMMIT'], true)
                    && $state->isPublishHeldDeep($action['scope'])) {
                    ++$metrics['unsafe_allows'];
                    $state->denials[] = [
                        'code' => 'UNSAFE_ALLOW_BLOCKED',
                        'scope' => $action['scope'],
                        'reason' => 'a held scope, a dependency of that scope, or an open obligation cannot be allowed to publish',
                    ];
                }
                $state->actions[] = $action;
                if (($action['action'] ?? '') === 'REJECT_UNNECESSARY_ESCALATION') {
                    ++$metrics['rejected_unnecessary_escalations'];
                }
                if (($action['action'] ?? '') === 'UNKNOWN_REQUIRES_REVIEW') {
                    ++$metrics['unverified_classification_held'];
                }
                if (($action['action'] ?? '') === 'CONTINUE_BLOCKED_BY_HOLD') {
                    ++$metrics['continue_blocked_by_hold'];
                }
                if (($action['action'] ?? '') === 'ESCALATE_DELEGATION_OUT_OF_RANGE') {
                    ++$metrics['delegation_range_escalations'];
                }
                if (($action['action'] ?? '') === 'ALLOW_PREPARE' || ($action['action'] ?? '') === 'ALLOW_UNRELATED_WORK') {
                    foreach ($action['scope'] as $workId) {
                        if (isset($state->works[$workId]) && $state->works[$workId]['status'] === 'ASSIGNED') {
                            $state->works[$workId]['status'] = 'PREPARING';
                        }
                    }
                }
            }
            foreach ($composition['local_rebases'] as $rebase) {
                $rebaseScope = $state->normalizeScope(array_map('strval', $rebase['scope'] ?? []));
                if ($rebaseScope === []) {
                    continue;
                }

                // Application-time re-check. A fact that was valid when the
                // event was observed can be revoked or expired before the
                // local adoption is applied; never trust the earlier verdict.
                $applyCheck = DecisionRights::revalidateForApply($plan, $rebase, $event, $state->authority);
                if (!$applyCheck['valid']) {
                    ++$metrics['authority_revalidation_failures'];
                    $authorityReason = (string) ($applyCheck['basis'] ?? 'AUTHORITY_REVALIDATION_FAILED');
                    $authorityDetail = (string) ($applyCheck['reason'] ?? 'the authority fact is no longer valid');
                    $state->denials[] = [
                        'code' => 'AUTHORITY_REVALIDATION_FAILED',
                        'scope' => $rebaseScope,
                        'reason' => $authorityDetail,
                        'basis' => $authorityReason,
                    ];
                    $state->releaseHoldsByReasonPrefix('CONTRACT_CHANGED', $rebaseScope);
                    $holdId = $state->addHold(
                        'PUBLISH',
                        $rebaseScope,
                        'AUTHORITY_REVALIDATION_FAILED',
                        'HOST_AUTHORITY',
                        'DIRECTOR_DECISION',
                        'authority:' . $eventId,
                        'DIRECTOR',
                        'SAME_ROOT_CAUSE_DECISION',
                        (string) ($event['root_cause'] ?? $eventId),
                        (string) ($event['root_cause'] ?? $eventId)
                    );
                    $holds[] = $state->holds[$holdId];
                    $actions[] = [
                        'action' => 'ADOPT_CONTRACT_BLOCKED',
                        'scope' => $rebaseScope,
                        'zone' => 'HOST_AUTHORITY',
                        'reason' => $authorityDetail,
                        'basis' => $authorityReason,
                    ];
                    $routed = $this->router->route(
                        $plan,
                        $state,
                        'CONTRACT_CHANGE',
                        $rebaseScope,
                        (string) ($event['root_cause'] ?? $eventId),
                        array_values(array_unique(array_merge(
                            [$eventId, 'authority_revalidation_failed', $authorityReason],
                            \is_array($event['facts'] ?? null) ? array_map('strval', $event['facts']) : []
                        ))),
                        $event
                    );
                    $escalation = $routed;
                    if (($routed['status'] ?? '') === 'CREATED') {
                        ++$metrics['director_wakeups'];
                        ++$metrics['contract_change_escalations'];
                    }
                    continue;
                }

                $newRevision = $state->policyRevision + 1;
                $state->policyRevision = $newRevision;
                $state->bumpPolicySlices($rebaseScope, $newRevision);
                $record = [
                    'scope' => $rebaseScope,
                    'basis' => (string) ($rebase['basis'] ?? ''),
                    'authority_ref' => (string) ($rebase['authority_ref'] ?? ''),
                    'decision' => (string) ($rebase['decision'] ?? ''),
                    'new_policy_revision' => $newRevision,
                    'source_event_id' => $eventId,
                ];
                $state->localRebases[] = $record;
                $localRebases[] = $record;
                $actions[] = [
                    'action' => 'ADOPT_CONTRACT_LOCALLY',
                    'scope' => $rebaseScope,
                    'zone' => 'LOCAL_DETERMINISTIC',
                    'reason' => 'a registered authority already decided the contract change; only revalidation remains',
                    'basis' => $record['basis'],
                    'authority_ref' => $record['authority_ref'],
                    'new_policy_revision' => $newRevision,
                    'publication_committed' => false,
                ];
                ++$metrics['local_contract_adoptions'];
            }
            foreach ($composition['escalation_proposals'] as $proposal) {
                $routed = $this->router->route(
                    $plan,
                    $state,
                    (string) $proposal['reason'],
                    $proposal['scope'],
                    (string) $proposal['root_cause'],
                    $proposal['facts'] ?? [$eventId],
                    $event
                );
                $escalation = $routed;
                if (($routed['status'] ?? '') === 'CREATED') {
                    ++$metrics['director_wakeups'];
                    if (($routed['request']['reopened_after_timeout'] ?? false) === true) {
                        ++$metrics['reopened_after_timeout'];
                    }
                } elseif (($routed['status'] ?? '') === 'DEDUPLICATED') {
                    ++$metrics['deduplicated_requests'];
                } elseif (($routed['status'] ?? '') === 'SUPPRESSED_NO_NEW_FACTS') {
                    ++$metrics['suppressed_requests'];
                } elseif (($routed['status'] ?? '') === 'UPDATED_EXISTING') {
                    ++$metrics['updated_existing_requests'];
                } elseif (str_starts_with((string) ($routed['status'] ?? ''), 'REJECTED')) {
                    ++$metrics['rejected_unnecessary_escalations'];
                }
            }
            if ($type === 'CONTRACT_CHANGED' && $localRebases === [] && ($escalation['status'] ?? '') === 'CREATED') {
                ++$metrics['contract_change_escalations'];
            }

            if ($type === 'RECONCILED') {
                $opKey = (string) ($event['op_key'] ?? '');
                $rootCause = 'effect:' . $opKey;
                $discharged = $state->dischargeObligationsByRootCause($rootCause, (string) ($event['evidence_id'] ?? ('reconcile:' . $eventId)));
                $released = $state->releaseHoldsByReasonPrefix('EXTERNAL_EFFECT_UNKNOWN:' . $opKey, $scope);
                foreach ($discharged as $obligationId) {
                    $obligations[] = $state->obligations[$obligationId];
                }
                foreach ($released as $holdId) {
                    $holds[] = $state->holds[$holdId];
                }
                $detail = 'reconciliation discharged ' . \count($discharged) . ' obligation(s) and released ' . \count($released) . ' hold(s)';
            }
        }

        $state->refreshPublishHeld();

        return [
            'seq' => $event['seq'] ?? null,
            'event_id' => $eventId,
            'event_type' => $type,
            'event_scope' => $scope,
            'scope_expanded_by_closure' => $scopeAddedByClosure,
            'zone' => $zone,
            'result' => $result,
            'detail' => $detail,
            'protocols' => $protocols,
            'negotiation' => $negotiation,
            'actions' => $actions,
            'holds' => $holds,
            'obligations' => $obligations,
            'invalidations' => $invalidations,
            'escalation' => $escalation,
            'patch' => $patch,
            'local_rebases' => $localRebases,
            'state_digest' => $state->digest(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $buffer
     * @param list<array<string, mixed>> $steps
     * @param array<string, mixed> $metrics
     */
    private function drainBuffer(Plan $plan, State $state, array &$buffer, array &$steps, array &$metrics): void
    {
        do {
            $progress = false;
            foreach ($buffer as $index => $event) {
                $parent = (string) ($event['causal_parent'] ?? '');
                if ($parent !== '' && !isset($state->processedEvents[$parent])) {
                    continue;
                }
                unset($buffer[$index]);
                $buffer = array_values($buffer);
                $steps[] = $this->processEvent($plan, $state, $event, $metrics);
                $progress = true;
            }
        } while ($progress && $buffer !== []);
    }

    /**
     * @param list<string> $scope
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>, 4: string, 5: string}
     */
    private function applyCurrentEvidence(State $state, array $event, array $scope): array
    {
        $evidenceId = (string) ($event['evidence_id'] ?? ('ev:' . substr(Canonical::hash($event), 0, 12)));
        $result = (string) ($event['result'] ?? 'unknown');
        $revision = (int) ($event['policy_revision'] ?? $state->policyRevision);
        $scopeRevision = $state->scopeRevision($scope);
        $actions = [];
        $obligations = [];
        $holds = [];
        $invalidations = [];

        if ($revision < $scopeRevision) {
            $state->rejections[] = [
                'code' => 'STALE_EVIDENCE',
                'evidence_id' => $evidenceId,
                'policy_revision' => $revision,
                'scope_revision' => $scopeRevision,
                'scope_revisions' => $state->scopeRevisions($scope),
            ];
            $actions[] = [
                'action' => 'REJECT_STALE_EVIDENCE',
                'scope' => $scope,
                'zone' => 'LOCAL_DETERMINISTIC',
                'reason' => 'evidence was produced against an older policy slice',
            ];

            return [$actions, $obligations, $holds, $invalidations, 'stale evidence was not bound', 'REJECTED_STALE_EVIDENCE'];
        }
        if ($revision > $state->policyRevision) {
            $state->rejections[] = [
                'code' => 'FUTURE_EVIDENCE',
                'evidence_id' => $evidenceId,
                'policy_revision' => $revision,
                'current_revision' => $state->policyRevision,
            ];
            $actions[] = [
                'action' => 'REJECT_FUTURE_EVIDENCE',
                'scope' => $scope,
                'zone' => 'LOCAL_DETERMINISTIC',
                'reason' => 'evidence claims a policy revision that has not happened',
            ];

            return [$actions, $obligations, $holds, $invalidations, 'future evidence was not bound', 'REJECTED_FUTURE_EVIDENCE'];
        }

        $state->addEvidence($evidenceId, $scope, $result, (string) ($event['source'] ?? 'agent'), $revision);
        if ($result !== 'pass') {
            $reasonId = 'evidence:' . $evidenceId;
            $id = $state->addObligation('FIX_EVIDENCE', $scope, 'P-REVIEW', 'evidence result is not pass', $evidenceId, $reasonId);
            $obligations[] = $state->obligations[$id];
            $holdId = $state->addHold('PUBLISH', $scope, 'EVIDENCE_NOT_CURRENT', 'P-REVIEW', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $evidenceId);
            $holds[] = $state->holds[$holdId];
            $actions[] = [
                'action' => 'HOLD_PUBLISH',
                'scope' => $scope,
                'zone' => 'LOCAL_DETERMINISTIC',
                'reason' => 'evidence did not pass; publication stays held',
            ];

            return [$actions, $obligations, $holds, $invalidations, 'evidence result is ' . $result, 'EVIDENCE_HELD'];
        }

        $claims = $event['claims'] ?? [];
        $claims = \is_array($claims) ? array_values(array_map('strval', $claims)) : [];
        $evidenceRootCause = $event['root_cause'] ?? null;
        $evidenceRootCause = \is_string($evidenceRootCause) && $evidenceRootCause !== '' ? $evidenceRootCause : null;
        $discharged = $state->dischargeObligations($scope, $evidenceId, $claims, $evidenceRootCause);
        $released = $state->releaseHoldsWhenDischarged($scope, $discharged);
        $actions[] = [
            'action' => 'BIND_EVIDENCE',
            'scope' => $scope,
            'zone' => 'LOCAL_DETERMINISTIC',
            'reason' => 'current evidence is bound to the plan revision',
            'evidence_id' => $evidenceId,
            'discharged_obligations' => $discharged,
            'released_holds' => $released,
            'publication_committed' => false,
        ];
        foreach ($discharged as $obligationId) {
            $obligations[] = $state->obligations[$obligationId];
        }
        foreach ($released as $holdId) {
            $holds[] = $state->holds[$holdId];
        }

        return [$actions, $obligations, $holds, $invalidations, 'current evidence discharged ' . \count($discharged) . ' obligation(s)', 'EVIDENCE_BOUND'];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>|null, 2: string, 3: string}
     */
    private function applyDirectorResponse(Plan $plan, State $state, array $event, array &$metrics): array
    {
        $validation = $this->validator->validate($plan, $state, $event);
        if (!$validation['valid']) {
            $reason = (string) $validation['reason'];
            $requestId = $event['request_id'] ?? null;
            $existing = \is_string($requestId) ? $state->request($requestId) : null;
            if ($reason === 'REQUEST_NOT_OPEN' && $existing === null && \is_string($event['root_cause'] ?? null)) {
                $existing = $state->requestByRootCauseScope(
                    (string) $event['root_cause'],
                    \is_array($event['scope'] ?? null) ? array_map('strval', $event['scope']) : []
                );
            }
            if ($reason === 'REQUEST_NOT_OPEN' && ($existing['status'] ?? '') === 'TIMED_OUT') {
                ++$metrics['late_responses_rejected'];
            }
            if (\in_array($reason, ['STALE_POLICY_REVISION', 'REQUEST_STALE', 'REQUEST_NOT_OPEN'], true)) {
                ++$metrics['stale_responses_rejected'];
            }
            $state->rejections[] = [
                'code' => $reason,
                'detail' => $validation['detail'],
                'event_id' => $event['event_id'] ?? null,
                'response_id' => $event['response_id'] ?? null,
            ];

            return [[[
                'action' => 'REJECT_DIRECTOR_RESPONSE',
                'scope' => $event['scope'] ?? [],
                'zone' => 'DIRECTOR_DECISION',
                'reason' => $reason . ': ' . $validation['detail'],
                'accepted' => false,
            ]], null, (string) $validation['detail'], 'REJECTED_' . $reason];
        }

        $patch = $validation['patch'];
        $request = $validation['request'];
        $approvedScope = array_map('strval', $patch['affected_scope']);
        $safetyScope = $plan->dependencyClosure($approvedScope);
        $releasedDecisionHolds = $state->releaseDecisionHolds((string) $request['root_cause'], $approvedScope);
        foreach ($patch['changes'] as $change) {
            $op = (string) ($change['op'] ?? '');
            $changeScope = array_values(array_map('strval', $change['scope'] ?? []));
            $changeSafetyScope = $plan->dependencyClosure($changeScope);
            if ($op === 'REBASE_SCOPE') {
                $state->invalidateEvidenceForScope($changeSafetyScope, 'PROTOCOL_PATCH_REBASE', 'ProtocolPatchValidator');
                $state->addObligation('REVALIDATE_AFTER_PATCH', $changeSafetyScope, 'ProtocolPatchValidator', 'the protocol was revised locally', (string) $request['root_cause']);
                foreach ($changeSafetyScope as $workId) {
                    if (isset($state->works[$workId]) && $state->works[$workId]['status'] !== 'ABORTED') {
                        $state->works[$workId]['status'] = 'PREPARING';
                    }
                }
            } elseif ($op === 'SET_REVIEW_REQUIREMENT') {
                $state->invalidateEvidenceForScope($changeSafetyScope, 'REVIEW_REQUIREMENT_CHANGED', 'ProtocolPatchValidator');
                $state->addObligation('REVALIDATE_UNDER_NEW_REVIEW', $changeSafetyScope, 'ProtocolPatchValidator', 'the review requirement changed', (string) $request['root_cause']);
                $state->notes[] = 'review requirement changed to ' . (string) ($change['value'] ?? 'unknown');
            } elseif ($op === 'HOLD_SCOPE') {
                $state->addHold('PUBLISH', $changeSafetyScope, 'DIRECTOR_PATCH_HOLD', 'ProtocolPatchValidator', 'MANUAL_RELEASE');
            } elseif ($op === 'CONTINUE_SCOPE') {
                $blocked = $state->isPublishHeldDeep($changeSafetyScope)
                    || $state->openObligationsFor($changeSafetyScope) !== []
                    || $state->hasPermitRevocation($changeSafetyScope);
                foreach ($changeSafetyScope as $workId) {
                    if (isset($state->works[$workId]) && $state->works[$workId]['status'] !== 'ABORTED') {
                        $state->works[$workId]['status'] = 'PREPARING';
                    }
                }
                if ($blocked) {
                    ++$metrics['continue_blocked_by_hold'];
                    $state->denials[] = [
                        'code' => 'CONTINUE_BLOCKED_BY_HOLD',
                        'scope' => $changeSafetyScope,
                        'reason' => 'CONTINUE_SCOPE cannot clear another active safety hold or obligation',
                    ];
                }
            } elseif ($op === 'ABORT_SCOPE') {
                foreach ($changeSafetyScope as $workId) {
                    if (isset($state->works[$workId])) {
                        $state->works[$workId]['status'] = 'ABORTED';
                        $state->works[$workId]['publish_held'] = false;
                    }
                }
                $state->invalidateEvidenceForScope($changeSafetyScope, 'SCOPE_ABORTED', 'ProtocolPatchValidator');
            }
        }

        $patch['propagated_scope'] = $safetyScope;
        $patch['slice_updates'] = array_fill_keys($safetyScope, (int) $patch['new_policy_revision']);
        $state->applyPatch($patch);
        $state->bumpPolicySlices($safetyScope, (int) $patch['new_policy_revision']);
        $state->closeRequest((string) $request['request_id'], 'ANSWERED');
        ++$metrics['director_decisions'];

        return [[[
            'action' => 'APPLY_PROTOCOL_PATCH',
            'scope' => $approvedScope,
            'propagated_scope' => $safetyScope,
            'zone' => 'DIRECTOR_DECISION',
            'reason' => 'the Director response passed revision, scope, authority and invariant checks',
            'patch_id' => $patch['patch_id'],
            'option' => $patch['option'],
            'new_policy_revision' => $patch['new_policy_revision'],
            'released_decision_holds' => $releasedDecisionHolds,
            'publication_committed' => false,
        ]], $patch, 'protocol patch applied to the approved scope and its dependency closure only', 'PATCH_APPLIED'];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: string, 3: string}
     */
    private function applyDirectorTimeout(State $state, array $event): array
    {
        $request = null;
        $requestId = $event['request_id'] ?? null;
        if (\is_string($requestId) && $requestId !== '') {
            $request = $state->request($requestId);
        }
        if ($request === null && \is_string($event['root_cause'] ?? null)) {
            $request = $state->openRequestForRootCause((string) $event['root_cause']);
        }
        if ($request === null || ($request['status'] ?? '') !== 'OPEN') {
            return [[[
                'action' => 'NO_OPEN_REQUEST',
                'scope' => $event['scope'] ?? [],
                'zone' => 'LOCAL_DETERMINISTIC',
                'reason' => 'timeout observed but no matching open request',
            ]], [], 'no matching open request', 'NO_OPEN_REQUEST'];
        }

        $state->closeRequest((string) $request['request_id'], 'TIMED_OUT');
        $holdId = $state->addHold(
            'PUBLISH',
            $request['scope'],
            'DIRECTOR_TIMEOUT',
            'EscalationRouter',
            'DIRECTOR_DECISION',
            'timeout:' . $request['request_id'],
            'DIRECTOR',
            'SAME_ROOT_CAUSE_DECISION',
            (string) $request['root_cause'],
            (string) $request['root_cause']
        );
        $hold = $state->holds[$holdId];

        return [[[
            'action' => 'HOLD_AFFECTED_SCOPE',
            'scope' => $request['scope'],
            'zone' => 'LOCAL_DETERMINISTIC',
            'reason' => 'the Director did not answer; only the affected scope is held',
            'timeout_action' => $request['timeout_action'] ?? 'HOLD_AFFECTED_SCOPE',
        ]], [$hold], 'affected scope held after Director timeout', 'DIRECTOR_TIMEOUT'];
    }
}
