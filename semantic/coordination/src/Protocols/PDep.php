<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolEffect;
use XiangGraph\Coord\State;

/**
 * P-DEP: dependency and waiting.
 *
 * It invalidates only the evidence that depends on a changed prerequisite and
 * keeps unrelated work available. It never treats "zero current matches" as
 * "no dependency".
 */
final class PDep extends AbstractProtocol
{
    public function type(): string
    {
        return 'P-DEP';
    }

    public function signals(): array
    {
        return [
            'WORKS_ASSIGNED',
            'DEP_CHANGED',
            'SCHEMA_CONTRACT_CHANGED',
            'DEPENDENCY_ADDED',
            'DEPENDENCY_REMOVED',
            'UNMAPPED_CHANGE',
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

        if ($type === 'WORKS_ASSIGNED') {
            $effect->action('ALLOW_PREPARE', $scope, 'LOCAL_DETERMINISTIC', 'works may start independent preparation');
            $effect->notes[] = 'preparation is separate from publication';

            return $effect;
        }

        if ($type === 'DEPENDENCY_ADDED') {
            $rootCause = $this->rootCause($event, 'dependency');
            $reasonId = 'dependency:' . $rootCause;
            $effect->obligation('RESOLVE_DEPENDENCY', $scope, $source, 'a new dependency must be resolved before dependent publication', $rootCause, $reasonId);
            $effect->hold($scope, 'DEPENDENCY_ADDED', $source, 'PUBLISH', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
            $effect->action('WAIT_FOR_DEPENDENCY', $scope, 'LOCAL_DETERMINISTIC', 'dependent publication waits; preparation may continue', [
                'dependency_closure' => $plan->dependencyClosure($scope),
            ]);

            return $effect;
        }

        if ($type === 'DEPENDENCY_REMOVED') {
            $effect->notes[] = 'dependency removal is recorded; it does not retroactively validate old evidence';

            return $effect;
        }

        if ($type === 'UNMAPPED_CHANGE') {
            $effect->action('INVESTIGATE', $scope, 'LOCAL_DETERMINISTIC', 'the change is outside the registered dependency scope');
            if ((bool) ($event['material'] ?? false)) {
                $effect->escalate('IMPACT_UNKNOWN_MATERIAL', $scope, $this->rootCause($event, 'unmapped'), [$event['event_id'] ?? '']);
            }

            return $effect;
        }

        // DEP_CHANGED and SCHEMA_CONTRACT_CHANGED.
        $rootCause = $this->rootCause($event, 'dep');
        $reasonId = 'dep:' . $rootCause;
        $effect->invalidate($scope, $type, $source);
        $effect->obligation('REVALIDATE_DEPENDENT', $scope, $source, 'dependent evidence became stale', $rootCause, $reasonId);
        $effect->hold($scope, $type, $source, 'PUBLISH', 'CURRENT_EVIDENCE', $reasonId, 'PROTOCOL', 'OBLIGATION_DISCHARGED', $reasonId, $rootCause);
        $effect->action('REVALIDATE', $scope, 'LOCAL_DETERMINISTIC', 'only the dependent evidence is stale', [
            'root_cause' => $rootCause,
            'dependency_closure' => $plan->dependencyClosure($scope),
        ]);

        return $effect;
    }
}
