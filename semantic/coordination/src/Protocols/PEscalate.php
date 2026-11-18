<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\DecisionRights;
use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolEffect;
use XiangGraph\Coord\State;
use XiangGraph\S1\Canonical;

/**
 * P-ESCALATE: strategic escalation and over-escalation guard.
 */
final class PEscalate extends AbstractProtocol
{
    public function type(): string
    {
        return 'P-ESCALATE';
    }

    public function signals(): array
    {
        return [
            'GOAL_CONFLICT',
            'PROTOCOL_GAP',
            'BUDGET_EXCEEDED',
            'DEADLOCK_DETECTED',
            'CONTRACT_CHANGED',
            'ORDINARY_PROGRESS',
            'UNMAPPED_CHANGE',
        ];
    }

    public function evaluate(Plan $plan, array $protocol, array $event, State $state): ProtocolEffect
    {
        $effect = ProtocolEffect::for($this->type());
        $scope = $this->scope($event, $plan);
        $type = (string) ($event['type'] ?? '');
        $source = $this->type() . ':' . (string) ($protocol['version'] ?? 'v0.1');
        $rootCause = $this->rootCause($event, 'escalate');
        $doNotEscalate = array_map('strval', $plan->escalationPolicy()['do_not_escalate_for'] ?? []);

        if ($scope === []) {
            return $effect;
        }

        $reason = match ($type) {
            'GOAL_CONFLICT' => 'UNRESOLVED_GOAL_CONFLICT',
            'PROTOCOL_GAP' => 'PROTOCOL_GAP',
            'BUDGET_EXCEEDED' => 'BUDGET_OR_DEADLINE_DECISION',
            'DEADLOCK_DETECTED' => 'DEADLOCK_OR_STARVATION',
            'CONTRACT_CHANGED' => 'CONTRACT_CHANGE',
            'ORDINARY_PROGRESS' => 'ORDINARY_PROGRESS',
            default => null,
        };

        if ($type === 'ORDINARY_PROGRESS') {
            $indicators = $event['strategic_indicators'] ?? [];
            if (\is_string($indicators)) {
                $indicators = [$indicators];
            }
            $indicators = \is_array($indicators) ? array_values(array_map('strval', $indicators)) : [];
            foreach (['contract_change', 'acceptance_change', 'risk_change'] as $flag) {
                if ((bool) ($event[$flag] ?? false)) {
                    $indicators[] = $flag;
                }
            }
            $indicators = array_values(array_unique($indicators));

            if ($indicators !== []) {
                sort($indicators, \SORT_STRING);
                $classificationRoot = 'classification:' . substr(Canonical::hash([
                    'scope' => $scope,
                    'indicators' => $indicators,
                ]), 0, 10);
                $reasonId = 'classification:' . $classificationRoot;
                $effect->obligation(
                    'VERIFY_STRATEGIC_CLASSIFICATION',
                    $scope,
                    $source,
                    'an ordinary-progress label conflicts with strategic indicators',
                    $classificationRoot,
                    $reasonId
                );
                $effect->hold(
                    $scope,
                    'CLASSIFICATION_UNKNOWN',
                    $source,
                    'PUBLISH',
                    'CURRENT_EVIDENCE',
                    $reasonId,
                    'PROTOCOL',
                    'OBLIGATION_DISCHARGED',
                    $reasonId,
                    $classificationRoot
                );
                $effect->hold(
                    $scope,
                    'CLASSIFICATION_DECISION',
                    $source,
                    'PUBLISH',
                    'DIRECTOR_DECISION',
                    'classification-decision:' . $classificationRoot,
                    'DIRECTOR',
                    'SAME_ROOT_CAUSE_DECISION',
                    $classificationRoot,
                    $classificationRoot
                );
                $effect->action('UNKNOWN_REQUIRES_REVIEW', $scope, 'LOCAL_DETERMINISTIC', 'the progress label is not accepted as proof that the event is ordinary', [
                    'indicators' => $indicators,
                    'requires_director_proposal' => (bool) ($event['requires_director'] ?? false),
                ]);
                $effect->action('HOLD_FOR_CLASSIFICATION_REVIEW', $scope, 'LOCAL_DETERMINISTIC', 'publication is held until the classification is verified');
                $effect->escalate(
                    'IMPACT_UNKNOWN_MATERIAL',
                    $scope,
                    $classificationRoot,
                    array_merge($indicators, [(string) ($event['event_id'] ?? '')])
                );

                return $effect;
            }

            if ((bool) ($event['requires_director'] ?? false)) {
                $effect->action('REJECT_UNNECESSARY_ESCALATION', $scope, 'LOCAL_DETERMINISTIC', 'an untrusted requires_director flag is only a proposal; ordinary progress is not a Director decision');
                $effect->notes[] = 'over-escalation guard rejected ordinary progress; the flag has no authority';
            } else {
                $effect->notes[] = 'ordinary progress recorded without a Director request';
            }

            return $effect;
        }

        if ($reason !== null && \in_array($reason, $doNotEscalate, true)) {
            $effect->action('REJECT_UNNECESSARY_ESCALATION', $scope, 'LOCAL_DETERMINISTIC', 'this reason is explicitly local');
            $effect->notes[] = 'over-escalation guard rejected: ' . $reason;

            return $effect;
        }

        if ($type === 'UNMAPPED_CHANGE') {
            $effect->action('INVESTIGATE', $scope, 'LOCAL_DETERMINISTIC', 'collect facts before any schema or scope change');
            $effect->hold($scope, 'UNMAPPED_CHANGE', $source, 'PUBLISH', 'DIRECTOR_DECISION', 'unmapped:' . $rootCause, 'DIRECTOR', 'SAME_ROOT_CAUSE_DECISION', $rootCause, $rootCause);
            if ((bool) ($event['material'] ?? false)) {
                $effect->escalate('IMPACT_UNKNOWN_MATERIAL', $scope, $rootCause, $this->facts($event));
            }

            return $effect;
        }

        if ($type === 'CONTRACT_CHANGED') {
            $rights = DecisionRights::classify($plan, $event, $state->authority);
            $reasonId = 'contract:' . $rootCause;
            $effect->invalidate($scope, 'CONTRACT_CHANGED', $source);
            $effect->obligation('REVALIDATE_UNDER_NEW_CONTRACT', $scope, $source, 'the acceptance contract changed', $rootCause, $reasonId);

            if ($rights['decision'] === DecisionRights::ADOPT_EXTERNAL || $rights['decision'] === DecisionRights::ADOPT_DELEGATED) {
                $effect->hold(
                    $scope,
                    'CONTRACT_CHANGED',
                    $source,
                    'PUBLISH',
                    'CURRENT_EVIDENCE',
                    $reasonId,
                    'PROTOCOL',
                    'OBLIGATION_DISCHARGED',
                    $reasonId,
                    $rootCause
                );
                $effect->localRebase(
                    $scope,
                    (string) $rights['basis'],
                    (string) ($rights['authority_ref'] ?? ''),
                    (string) $rights['decision']
                );
                $effect->notes[] = 'contract change adopted locally under ' . $rights['basis'];

                return $effect;
            }

            $effect->hold($scope, 'CONTRACT_CHANGED', $source, 'PUBLISH', 'DIRECTOR_DECISION', $reasonId, 'DIRECTOR', 'SAME_ROOT_CAUSE_DECISION', $rootCause, $rootCause);
            $effect->escalate('CONTRACT_CHANGE', $scope, $rootCause, $this->facts($event));
            $effect->action('FREEZE_AFFECTED_PUBLISH', $scope, 'LOCAL_DETERMINISTIC', 'old evidence is not valid under the new contract');
            $effect->action('ESCALATE_OPEN_TRADEOFF', $scope, 'DIRECTOR_DECISION', 'the change is not covered by a signed contract or bounded delegation', [
                'decision_rights' => $rights,
            ]);
            if ($rights['decision'] === DecisionRights::ESCALATE_OUT_OF_RANGE) {
                $effect->action('ESCALATE_DELEGATION_OUT_OF_RANGE', $scope, 'DIRECTOR_DECISION', 'the proposed value is outside the delegated range', [
                    'decision_rights' => $rights,
                ]);
            }
            $effect->notes[] = 'contract change escalated: ' . $rights['basis'];

            return $effect;
        }

        if ($type === 'DEADLOCK_DETECTED') {
            $cycle = $event['cycle'] ?? $scope;
            $cycle = \is_array($cycle) && $cycle !== [] ? array_values(array_map('strval', $cycle)) : $scope;
            $reasonId = 'deadlock:' . $rootCause;
            $effect->hold($cycle, 'DEADLOCK', $source, 'PUBLISH', 'DIRECTOR_DECISION', $reasonId, 'DIRECTOR', 'SAME_ROOT_CAUSE_DECISION', $rootCause, $rootCause);
            $effect->escalate('DEADLOCK_OR_STARVATION', $cycle, $rootCause, $this->facts($event));
            $effect->action('PRESERVE_DEADLOCK_EVIDENCE', $cycle, 'LOCAL_DETERMINISTIC', 'record the minimal wait cycle');

            return $effect;
        }

        if ($type === 'BUDGET_EXCEEDED') {
            $effect->escalate('BUDGET_OR_DEADLINE_DECISION', $scope, $rootCause, $this->facts($event), true);
            $effect->action('STOP_NEW_SPEND', $scope, 'LOCAL_DETERMINISTIC', 'stop new resource consumption before asking for a decision');

            return $effect;
        }

        if ($type === 'GOAL_CONFLICT') {
            $reasonId = 'goal:' . $rootCause;
            $effect->escalate('UNRESOLVED_GOAL_CONFLICT', $scope, $rootCause, $this->facts($event));
            $effect->hold($scope, 'GOAL_CONFLICT', $source, 'PUBLISH', 'DIRECTOR_DECISION', $reasonId, 'DIRECTOR', 'SAME_ROOT_CAUSE_DECISION', $rootCause, $rootCause);
            $effect->action('HOLD_AFFECTED_SCOPE', $scope, 'LOCAL_DETERMINISTIC', 'only the conflicting branch is held');

            return $effect;
        }

        if ($type === 'PROTOCOL_GAP') {
            $reasonId = 'gap:' . $rootCause;
            $effect->obligation('DEFINE_PROTOCOL', $scope, $source, 'no existing protocol covers this situation', $rootCause, $reasonId);
            $effect->hold($scope, 'PROTOCOL_GAP', $source, 'PUBLISH', 'DIRECTOR_DECISION', $reasonId, 'DIRECTOR', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
            $effect->escalate('PROTOCOL_GAP', $scope, $rootCause, $this->facts($event));
            $effect->action('PRESERVE_LOCAL_PROGRESS', $scope, 'LOCAL_DETERMINISTIC', 'facts and local progress are preserved; no guessed state');

            return $effect;
        }

        return $effect;
    }
}
