<?php

declare(strict_types=1);

namespace XiangGraph\Coord\Protocols;

use XiangGraph\Coord\Plan;
use XiangGraph\S1\Canonical;

abstract class AbstractProtocol implements ProtocolHandler
{
    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    protected function scope(array $event, Plan $plan): array
    {
        $scope = $event['scope'] ?? [];
        if (!\is_array($scope) || $scope === []) {
            return $plan->workIds();
        }

        return array_values(array_filter(
            array_map('strval', $scope),
            static fn (string $id): bool => \in_array($id, $plan->workIds(), true)
        ));
    }

    /**
     * @param array<string, mixed> $event
     */
    protected function rootCause(array $event, string $fallback): string
    {
        $root = $event['root_cause'] ?? null;
        if (\is_string($root) && $root !== '') {
            return $root;
        }

        return $fallback . ':' . substr(Canonical::hash([
            'type' => $event['type'] ?? null,
            'scope' => $event['scope'] ?? [],
            'seq' => $event['seq'] ?? null,
        ]), 0, 10);
    }

    /**
     * Semantic facts reported by an event. The event id is not a fact: a
     * repeated report of the same problem must not look like new information.
     *
     * @param array<string, mixed> $event
     * @return list<string>
     */
    protected function facts(array $event): array
    {
        $facts = $event['facts'] ?? [];
        if (\is_string($facts)) {
            $facts = [$facts];
        }
        if (!\is_array($facts)) {
            return [];
        }

        $normalized = [];
        foreach ($facts as $fact) {
            if (\is_scalar($fact)) {
                $normalized[] = (string) $fact;
            } else {
                $normalized[] = json_encode($fact, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '';
            }
        }

        return array_values(array_filter(array_unique($normalized), static fn (string $fact): bool => $fact !== ''));
    }

    /**
     * @param array<string, mixed> $protocol
     */
    protected function maxRounds(Plan $plan, array $protocol): int
    {
        return max((int) ($protocol['max_rounds'] ?? 0), $plan->maxNegotiationRounds());
    }
}
