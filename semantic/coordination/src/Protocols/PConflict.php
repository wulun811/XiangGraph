<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolEffect;
use XiangGraph\Coord\State;

/**
 * P-CONFLICT: bounded local negotiation.
 *
 * The protocol allows a finite number of rounds within the approved scope.
 * Exhaustion produces a structured Director proposal, not an unbounded loop.
 */
final class PConflict extends AbstractProtocol
{
    public function type(): string
    {
        return 'P-CONFLICT';
    }

    public function signals(): array
    {
        return ['CONFLICT_REPORTED', 'NEGOTIATION_ROUND', 'NEGOTIATION_EXHAUSTED', 'CONFLICT_RESOLVED'];
    }

    public function evaluate(Plan $plan, array $protocol, array $event, State $state): ProtocolEffect
    {
        $effect = ProtocolEffect::for($this->type());
        $scope = $this->scope($event, $plan);
        $type = (string) ($event['type'] ?? '');
        $rootCause = $this->rootCause($event, 'conflict');
        $max = $this->maxRounds($plan, $protocol);
        $scopeKey = implode(',', $scope);
        $negotiationKey = $rootCause . '|' . $scopeKey;
        $existing = $state->negotiations[$negotiationKey] ?? null;
        $rounds = (int) ($existing['rounds'] ?? 0);
        $reasonId = 'negotiation:' . $rootCause . '|' . $scopeKey;

        if ($scope === []) {
            return $effect;
        }

        if ($type === 'CONFLICT_RESOLVED') {
            $effect->negotiation = [
                'root_cause' => $rootCause,
                'scope' => $scope,
                'rounds' => $rounds,
                'max_rounds' => $max,
                'allowed' => true,
                'status' => 'RESOLVED',
                'resolved' => true,
            ];
            $effect->action('NEGOTIATION_RESOLVED', $scope, 'AGENT_DISCRETION', 'the agents resolved the conflict locally');

            return $effect;
        }

        if ($type === 'NEGOTIATION_EXHAUSTED') {
            $effect->negotiation = [
                'root_cause' => $rootCause,
                'scope' => $scope,
                'rounds' => max($rounds, $max),
                'max_rounds' => $max,
                'allowed' => false,
                'status' => 'EXHAUSTED',
                'resolved' => false,
            ];
            $effect->hold($scope, 'NEGOTIATION_EXHAUSTED', $this->type(), 'PUBLISH', 'DIRECTOR_DECISION', $reasonId, 'DIRECTOR', 'SAME_ROOT_CAUSE_DECISION', $rootCause, $rootCause);
            $effect->escalate('NEGOTIATION_EXHAUSTED', $scope, $rootCause, $this->facts($event));

            return $effect;
        }

        if ($type === 'CONFLICT_REPORTED' && $existing !== null && ($existing['status'] ?? '') === 'RESOLVED') {
            $effect->notes[] = 'conflict root cause already resolved; no new negotiation';

            return $effect;
        }

        if ($type === 'NEGOTIATION_ROUND' && (bool) ($event['resolved'] ?? false)) {
            $effect->negotiation = [
                'root_cause' => $rootCause,
                'scope' => $scope,
                'rounds' => $rounds + 1,
                'max_rounds' => $max,
                'allowed' => true,
                'status' => 'RESOLVED',
                'resolved' => true,
            ];
            $effect->action('NEGOTIATION_RESOLVED', $scope, 'AGENT_DISCRETION', 'the agents resolved the conflict locally');

            return $effect;
        }

        $nextRound = $rounds + 1;
        if ($nextRound > $max) {
            $effect->negotiation = [
                'root_cause' => $rootCause,
                'scope' => $scope,
                'rounds' => max($rounds, $max),
                'max_rounds' => $max,
                'allowed' => false,
                'status' => 'EXHAUSTED',
                'resolved' => false,
            ];
            $effect->hold($scope, 'NEGOTIATION_EXHAUSTED', $this->type(), 'PUBLISH', 'DIRECTOR_DECISION', $reasonId, 'DIRECTOR', 'SAME_ROOT_CAUSE_DECISION', $rootCause, $rootCause);
            $effect->escalate('NEGOTIATION_EXHAUSTED', $scope, $rootCause, $this->facts($event));
            $effect->notes[] = 'bounded negotiation exhausted; no further local rounds';

            return $effect;
        }

        $effect->negotiation = [
            'root_cause' => $rootCause,
            'scope' => $scope,
            'rounds' => $nextRound,
            'max_rounds' => $max,
            'allowed' => true,
            'status' => 'ACTIVE',
            'resolved' => false,
        ];
        $effect->action('ALLOW_NEGOTIATION', $scope, 'AGENT_DISCRETION', 'agents may exchange proposals within the approved round limit', [
            'round' => $nextRound,
            'max_rounds' => $max,
            'root_cause' => $rootCause,
        ]);

        return $effect;
    }
}
