<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolEffect;
use XiangGraph\Coord\State;

/**
 * P-REVIEW: review, evidence and obligation discharge.
 */
final class PReview extends AbstractProtocol
{
    public function type(): string
    {
        return 'P-REVIEW';
    }

    public function signals(): array
    {
        return [
            'CURRENT_EVIDENCE_RECEIVED',
            'EVIDENCE_INVALIDATED',
            'PUBLISH_REQUESTED',
            'REVIEW_REQUIRED',
            'REVIEW_COMPLETED',
        ];
    }

    public function evaluate(Plan $plan, array $protocol, array $event, State $state): ProtocolEffect
    {
        $effect = ProtocolEffect::for($this->type());
        $scope = $this->scope($event, $plan);
        $type = (string) ($event['type'] ?? '');
        $source = $this->type() . ':' . (string) ($protocol['version'] ?? 'v0.1');

        if ($scope === []) {
            return $effect;
        }

        if ($type === 'CURRENT_EVIDENCE_RECEIVED') {
            $effect->action('BIND_EVIDENCE', $scope, 'LOCAL_DETERMINISTIC', 'evidence is bound to the current plan revision');
            $effect->notes[] = 'evidence binding is not publication';

            return $effect;
        }

        if ($type === 'PUBLISH_REQUESTED') {
            if ($state->isPublishHeldDeep($scope) || $state->openObligationsFor($scope) !== [] || $state->hasPermitRevocation($scope)) {
                $effect->action('DENY_PUBLISH', $scope, 'LOCAL_DETERMINISTIC', 'publish is held or an obligation is still open', [
                    'publication_committed' => false,
                    'dependency_closure' => $plan->dependencyClosure($scope),
                ]);
            } else {
                $effect->action('ALLOW_PUBLISH_CURRENT_SNAPSHOT', $scope, 'LOCAL_DETERMINISTIC', 'current snapshot obligations are discharged', [
                    'publication_committed' => false,
                    'dependency_closure' => $plan->dependencyClosure($scope),
                ]);
            }

            return $effect;
        }

        if ($type === 'EVIDENCE_INVALIDATED') {
            $rootCause = $this->rootCause($event, 'evidence');
            $reasonId = 'review:' . $rootCause;
            $effect->invalidate($scope, 'EVIDENCE_INVALIDATED', $source);
            $effect->obligation('REVALIDATE', $scope, $source, 'the referenced evidence is no longer current', $rootCause, $reasonId);
            $effect->hold($scope, 'EVIDENCE_INVALIDATED', $source, 'PUBLISH', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
            $effect->action('REVALIDATE', $scope, 'LOCAL_DETERMINISTIC', 'only the affected obligation is reopened');

            return $effect;
        }

        if ($type === 'REVIEW_REQUIRED') {
            $rootCause = $this->rootCause($event, 'review');
            $reasonId = 'review-required:' . $rootCause;
            $effect->obligation('REVIEW', $scope, $source, 'a review obligation is required', $rootCause, $reasonId);
            $effect->hold($scope, 'REVIEW_REQUIRED', $source, 'PUBLISH', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
            $effect->action('REQUEST_REVIEW', $scope, 'AGENT_DISCRETION', 'the owning agent may choose how to review');

            return $effect;
        }

        if ($type === 'REVIEW_COMPLETED') {
            $result = (string) ($event['result'] ?? 'unknown');
            $rootCause = $this->rootCause($event, 'review');
            $reasonId = 'review-result:' . $rootCause;
            if ($result === 'pass') {
                $effect->action('REVIEW_ACCEPTED', $scope, 'LOCAL_DETERMINISTIC', 'review result is a candidate for evidence binding');
            } elseif ($result === 'fail') {
                $effect->obligation('FIX_REVIEW_FINDINGS', $scope, $source, 'review failed', $rootCause, $reasonId);
                $effect->hold($scope, 'REVIEW_FAILED', $source, 'PUBLISH', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
                $effect->action('FIX', $scope, 'AGENT_DISCRETION', 'the owning agent chooses the fix');
            } else {
                $effect->obligation('VERIFY_REVIEW_RESULT', $scope, $source, 'review result is unknown', $rootCause, $reasonId);
                $effect->hold($scope, 'REVIEW_RESULT_UNKNOWN', $source, 'PUBLISH', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
                $effect->action('HOLD_FOR_REVIEW', $scope, 'LOCAL_DETERMINISTIC', 'review result is unknown; do not infer pass');
            }
        }

        return $effect;
    }
}
