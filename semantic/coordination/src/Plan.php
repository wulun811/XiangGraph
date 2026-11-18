<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * A coordination plan is the Director's proposal: goal, works, situational
 * protocols, risk boundary and escalation policy. It is not a fact ledger.
 */
final class Plan
{
    /** @var array<string, mixed> */
    public array $data;

    public string $hash;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $validation = ProtocolContract::validate($data);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException('invalid coordination plan: ' . implode('; ', $validation['errors']));
        }

        $this->data = $data;
        $this->hash = Canonical::hash($data);
    }

    public function id(): string
    {
        return (string) $this->data['plan_id'];
    }

    public function revision(): int
    {
        return (int) $this->data['policy_revision'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function works(): array
    {
        return array_values($this->data['works']);
    }

    /**
     * @return list<string>
     */
    public function workIds(): array
    {
        return array_map(static fn (array $work): string => (string) $work['id'], $this->works());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function workIndex(): array
    {
        $index = [];
        foreach ($this->works() as $work) {
            $index[(string) $work['id']] = $work;
        }

        return $index;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function protocols(): array
    {
        return array_values($this->data['protocol_composition']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function protocolsOf(string $type): array
    {
        return array_values(array_filter(
            $this->protocols(),
            static fn (array $protocol): bool => (string) $protocol['protocol'] === $type
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function risk(): array
    {
        return $this->data['risk_boundary'];
    }

    /**
     * @return array<string, mixed>
     */
    public function escalationPolicy(): array
    {
        return $this->data['escalation_policy'];
    }

    /**
     * @return array<string, mixed>
     */
    public function authority(): array
    {
        $authority = $this->data['authority'] ?? [];

        return \is_array($authority) ? $authority : [];
    }

    /**
     * Signed contracts and bounded delegations. This is data, not a new
     * protocol primitive; an event reference is matched against this registry.
     *
     * @return array<string, mixed>
     */
    public function decisionRights(): array
    {
        $rights = $this->data['decision_rights'] ?? [];

        return \is_array($rights) ? $rights : [];
    }

    public function maxNegotiationRounds(): int
    {
        $max = (int) ($this->risk()['max_negotiation_rounds'] ?? 0);
        foreach ($this->protocolsOf('P-CONFLICT') as $protocol) {
            $max = max($max, (int) ($protocol['max_rounds'] ?? 0));
        }

        return $max;
    }

    public function isHumanOnlyOption(string $option): bool
    {
        return \in_array($option, array_map('strval', $this->risk()['human_only_options'] ?? []), true);
    }

    /**
     * @param list<string> $scope
     */
    public function scopeWithinWorks(array $scope): bool
    {
        return array_diff($scope, $this->workIds()) === [];
    }

    /**
     * @return list<string>
     */
    public function unaffectedScope(array $scope): array
    {
        return array_values(array_diff($this->workIds(), $this->dependencyClosure($scope)));
    }

    /**
     * Transitive dependents of a scope. If X declares depends_on [Y], a change
     * to Y also affects X; a hold must follow that edge even when X was not
     * named in the incoming event.
     *
     * @param list<string> $scope
     * @return list<string> scope plus all transitive dependents, sorted
     */
    public function dependencyClosure(array $scope): array
    {
        $closure = [];
        foreach ($scope as $workId) {
            if (\in_array((string) $workId, $this->workIds(), true)) {
                $closure[(string) $workId] = true;
            }
        }

        do {
            $changed = false;
            foreach ($this->works() as $work) {
                $id = (string) $work['id'];
                if (isset($closure[$id])) {
                    continue;
                }
                foreach (array_map('strval', $work['depends_on'] ?? []) as $dependency) {
                    if (isset($closure[$dependency])) {
                        $closure[$id] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);

        $ids = array_keys($closure);
        sort($ids, \SORT_STRING);

        return $ids;
    }

    /**
     * Transitive prerequisites of a scope. Publishing a work is unsafe if one
     * of its prerequisites is held, but publishing a prerequisite is not
     * blocked merely because a dependent is held.
     *
     * @param list<string> $scope
     * @return list<string>
     */
    public function dependencyPrerequisites(array $scope): array
    {
        $index = $this->workIndex();
        $closure = [];
        $queue = [];
        foreach ($scope as $workId) {
            if (isset($index[(string) $workId])) {
                $closure[(string) $workId] = true;
                $queue[] = (string) $workId;
            }
        }
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach (array_map('strval', $index[$current]['depends_on'] ?? []) as $dependency) {
                if (!isset($closure[$dependency]) && isset($index[$dependency])) {
                    $closure[$dependency] = true;
                    $queue[] = $dependency;
                }
            }
        }
        $ids = array_keys($closure);
        sort($ids, \SORT_STRING);

        return $ids;
    }
}
