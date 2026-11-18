<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * Creates at most one open Director request per root cause / scope / policy
 * revision. The request carries options and impact; it is not a log forward.
 */
final class EscalationRouter
{
    /**
     * @param list<string> $scope
     * @param list<string> $facts
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function route(
        Plan $plan,
        State $state,
        string $reason,
        array $scope,
        string $rootCause,
        array $facts = [],
        array $event = [],
        string $dedupeSuffix = ''
    ): array {
        $policy = $plan->escalationPolicy();
        $directorReasons = array_map('strval', $policy['director_only_when'] ?? []);
        $localReasons = array_map('strval', $policy['do_not_escalate_for'] ?? []);

        if (\in_array($reason, $localReasons, true)) {
            return [
                'status' => 'REJECTED_UNNECESSARY',
                'reason' => $reason,
                'root_cause' => $rootCause,
                'detail' => 'this reason is explicitly local and must not wake the Director',
            ];
        }
        if ($directorReasons !== [] && !\in_array($reason, $directorReasons, true)) {
            return [
                'status' => 'REJECTED_REASON_NOT_AUTHORIZED',
                'reason' => $reason,
                'root_cause' => $rootCause,
                'detail' => 'the plan does not authorize this escalation reason',
            ];
        }

        $scope = array_values(array_intersect($state->normalizeScope($scope), $plan->workIds()));
        if ($scope === []) {
            return [
                'status' => 'REJECTED_SCOPE_NOT_IN_PLAN',
                'reason' => $reason,
                'root_cause' => $rootCause,
                'detail' => 'the escalation scope does not reference any plan work',
            ];
        }
        $facts = array_values(array_unique(array_map('strval', $facts)));
        $scopeRevision = $state->scopeRevision($scope);
        $scopeRevisions = $state->scopeRevisions($scope);
        $dedupeKey = self::dedupeKey($reason, $scope, $rootCause, $scopeRevision);
        $existing = $state->openRequestForRootCauseScope($rootCause, $scope);
        if ($existing !== null && $dedupeSuffix === '') {
            $sameReason = $dedupeKey === ($existing['dedupe_key'] ?? '');
            $newFacts = array_values(array_diff($facts, array_map('strval', $existing['facts'] ?? [])));
            if ($sameReason && $newFacts === []) {
                return [
                    'status' => 'DEDUPLICATED',
                    'request_id' => $existing['request_id'],
                    'reason' => $reason,
                    'root_cause' => $rootCause,
                    'detail' => 'an open request already covers this root cause, scope and scope revision with no new facts',
                ];
            }
            if ($sameReason) {
                $existing['facts'] = array_values(array_unique(array_merge($existing['facts'] ?? [], $newFacts)));
                $existing['fact_epoch'] = (int) ($existing['fact_epoch'] ?? 0) + 1;
                $existing['reanswer_required'] = true;
                $existing['updated_at_revision'] = $state->policyRevision;
                $existing['updated_at_seq'] = (int) ($event['seq'] ?? 0);
                $state->requests[(string) $existing['request_id']] = $existing;

                return [
                    'status' => 'UPDATED_EXISTING',
                    'request_id' => $existing['request_id'],
                    'reason' => $reason,
                    'root_cause' => $rootCause,
                    'new_facts' => $newFacts,
                    'fact_epoch' => $existing['fact_epoch'],
                    'detail' => 'new facts were attached to the existing open request and the decision must be re-evaluated',
                ];
            }
        }

        $options = $this->optionsFor($plan, $reason, $scope);
        $requestId = 'dirreq-' . substr(Canonical::hash([
            'plan' => $plan->id(),
            'reason' => $reason,
            'scope' => $scope,
            'root_cause' => $rootCause,
            'scope_revision' => $scopeRevision,
            'facts' => $facts,
            'dedupe_suffix' => $dedupeSuffix,
        ]), 0, 12);

        $previous = $state->requestByRootCauseScope($rootCause, $scope);
        $request = [
            'request_id' => $requestId,
            'reason' => $reason,
            'plan_id' => $plan->id(),
            'policy_revision' => $state->policyRevision,
            'scope_revision' => $scopeRevision,
            'scope_revisions' => $scopeRevisions,
            'scope' => $scope,
            'root_cause' => $rootCause,
            'facts' => $facts,
            'fact_epoch' => 0,
            'reanswer_required' => false,
            'attempted_local_resolutions' => $this->attemptedResolutions($state, $rootCause),
            'conflict' => (string) ($event['conflict'] ?? $event['detail'] ?? $reason),
            'options' => $options,
            'recommended' => $options[0]['id'] ?? null,
            'blocked_scope' => $scope,
            'unblocked_scope' => $plan->unaffectedScope($scope),
            'response_contract' => ['CHOOSE_OPTION', 'REQUEST_EVIDENCE', 'PATCH_PROTOCOL', 'PAUSE_SCOPE', 'ABORT_SCOPE'],
            'timeout_action' => (string) ($plan->risk()['director_unavailable_default'] ?? 'HOLD_AFFECTED_SCOPE'),
            'status' => 'OPEN',
            'created_at_revision' => $state->policyRevision,
            'created_at_seq' => (int) ($event['seq'] ?? 0),
            'trigger_event_id' => (string) ($event['event_id'] ?? ''),
            'previous_request_id' => $previous['request_id'] ?? null,
            'reopened_after_timeout' => ($previous['status'] ?? '') === 'TIMED_OUT',
            'dedupe_key' => $dedupeKey . ($dedupeSuffix === '' ? '' : '#' . $dedupeSuffix),
        ];

        $state->addRequest($request);

        return [
            'status' => 'CREATED',
            'request_id' => $requestId,
            'reason' => $reason,
            'root_cause' => $rootCause,
            'request' => $request,
        ];
    }

    /**
     * @param list<string> $scope
     */
    public static function dedupeKey(string $reason, array $scope, string $rootCause, int $scopeRevision): string
    {
        $scope = array_values(array_unique($scope));
        sort($scope, \SORT_STRING);

        return Canonical::hash([
            'reason' => $reason,
            'scope' => $scope,
            'root_cause' => $rootCause,
            'scope_revision' => $scopeRevision,
        ]);
    }

    /**
     * @param list<string> $scope
     * @return list<array<string, mixed>>
     */
    private function optionsFor(Plan $plan, string $reason, array $scope): array
    {
        $configured = $plan->escalationPolicy()['decision_options'][$reason] ?? null;
        if (\is_array($configured) && $configured !== []) {
            $options = array_values($configured);
            // The plan can mark an option as human-only; the request mirrors that.
            foreach ($options as &$option) {
                if ($plan->isHumanOnlyOption((string) $option['id'])) {
                    $option['requires_authorization'] = 'HUMAN';
                }
            }
            unset($option);

            return $options;
        }

        $first = $scope[0] ?? null;
        $rest = array_slice($scope, 1);

        $options = match ($reason) {
            'NEGOTIATION_EXHAUSTED' => [
                [
                    'id' => 'preserve-existing-review',
                    'effect' => 'keep the current review invariant; rebase the API proposal and re-test only the affected scope',
                    'patch' => [['op' => 'REBASE_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'change-review-requirement',
                    'effect' => 'relax the review requirement; requires business/risk authorization before activation',
                    'patch' => [['op' => 'SET_REVIEW_REQUIREMENT', 'scope' => $scope, 'value' => 'relaxed']],
                    'requires_authorization' => 'HUMAN',
                ],
            ],
            'UNRESOLVED_GOAL_CONFLICT' => [
                [
                    'id' => 'prioritize-first',
                    'effect' => 'prioritize ' . (string) $first . ' and hold the remaining scope',
                    'patch' => array_merge(
                        [['op' => 'CONTINUE_SCOPE', 'scope' => $first === null ? [] : [$first]]],
                        $rest === [] ? [] : [['op' => 'HOLD_SCOPE', 'scope' => $rest]]
                    ),
                ],
                [
                    'id' => 'prioritize-remaining',
                    'effect' => 'hold ' . (string) $first . ' and continue the remaining scope',
                    'patch' => array_merge(
                        $first === null ? [] : [['op' => 'HOLD_SCOPE', 'scope' => [$first]]],
                        $rest === [] ? [] : [['op' => 'CONTINUE_SCOPE', 'scope' => $rest]]
                    ),
                ],
            ],
            'RECOVERY_STRATEGY_EXHAUSTED' => [
                [
                    'id' => 'manual-reconcile',
                    'effect' => 'keep the effect held and perform manual reconciliation',
                    'patch' => [['op' => 'HOLD_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'abort-scope',
                    'effect' => 'abort the affected branch and preserve the recovery anchor',
                    'patch' => [['op' => 'ABORT_SCOPE', 'scope' => $scope]],
                ],
            ],
            'IMPACT_UNKNOWN_MATERIAL' => [
                [
                    'id' => 'investigate-scope',
                    'effect' => 'hold publication and investigate the unmapped dependency',
                    'patch' => [['op' => 'HOLD_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'narrow-scope',
                    'effect' => 'narrow the deliverable and record the excluded scope',
                    'patch' => [['op' => 'ABORT_SCOPE', 'scope' => $scope]],
                ],
            ],
            'BUDGET_OR_DEADLINE_DECISION' => [
                [
                    'id' => 'reduce-scope',
                    'effect' => 'reduce scope and stop new spend',
                    'patch' => [['op' => 'ABORT_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'extend-budget',
                    'effect' => 'request a budget extension; requires human authorization',
                    'patch' => [['op' => 'HOLD_SCOPE', 'scope' => $scope]],
                    'requires_authorization' => 'HUMAN',
                ],
            ],
            'CONTRACT_CHANGE' => [
                [
                    'id' => 'rebase-contract',
                    'effect' => 'invalidate old evidence and rebase the affected scope',
                    'patch' => [['op' => 'REBASE_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'pause-scope',
                    'effect' => 'pause the affected scope until the contract is clarified',
                    'patch' => [['op' => 'HOLD_SCOPE', 'scope' => $scope]],
                ],
            ],
            'DEADLOCK_OR_STARVATION' => [
                [
                    'id' => 'break-cycle',
                    'effect' => 'rebase one branch and keep the other',
                    'patch' => [['op' => 'REBASE_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'abort-scope',
                    'effect' => 'abort the minimal cycle and preserve evidence',
                    'patch' => [['op' => 'ABORT_SCOPE', 'scope' => $scope]],
                ],
            ],
            default => [
                [
                    'id' => 'hold-scope',
                    'effect' => 'hold the affected scope and collect more evidence',
                    'patch' => [['op' => 'HOLD_SCOPE', 'scope' => $scope]],
                ],
                [
                    'id' => 'pause-scope',
                    'effect' => 'pause the affected scope without changing facts',
                    'patch' => [['op' => 'HOLD_SCOPE', 'scope' => $scope]],
                ],
            ],
        };

        // The plan can mark an option as human-only; the request mirrors that.
        foreach ($options as &$option) {
            if ($plan->isHumanOnlyOption((string) $option['id'])) {
                $option['requires_authorization'] = 'HUMAN';
            }
        }
        unset($option);

        return array_values($options);
    }

    /**
     * @return list<string>
     */
    private function attemptedResolutions(State $state, string $rootCause): array
    {
        $attempts = [];
        foreach ($state->negotiations as $key => $negotiation) {
            if ((string) ($negotiation['root_cause'] ?? '') !== $rootCause) {
                continue;
            }
            for ($round = 1; $round <= (int) $negotiation['rounds']; ++$round) {
                $attempts[] = 'negotiation-round-' . $round . '@' . $key;
            }
        }
        foreach ($state->holds as $hold) {
            if (($hold['status'] ?? '') === 'ACTIVE' && str_contains((string) $hold['reason'], $rootCause)) {
                $attempts[] = 'hold:' . $hold['reason'];
            }
        }

        return array_values(array_unique($attempts));
    }
}
