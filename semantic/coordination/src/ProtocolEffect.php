<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\S1\Canonical;

/**
 * Normalized output of one situational protocol before composition.
 */
final class ProtocolEffect
{
    /** @var list<array<string, mixed>> */
    public array $actions = [];

    /** @var list<array<string, mixed>> */
    public array $invalidations = [];

    /** @var list<array<string, mixed>> */
    public array $obligations = [];

    /** @var list<array<string, mixed>> */
    public array $holds = [];

    /** @var array<string, mixed>|null */
    public ?array $negotiation = null;

    /** @var list<array<string, mixed>> */
    public array $escalations = [];

    /** @var list<array<string, mixed>> */
    public array $denials = [];

    /** @var list<array<string, mixed>> */
    public array $localRebases = [];

    /** @var list<string> */
    public array $notes = [];

    public static function for(string $protocol): self
    {
        return new self();
    }

    /**
     * @param list<string> $scope
     * @param array<string, mixed> $extra
     */
    public function action(string $action, array $scope, string $zone, string $reason, array $extra = []): void
    {
        $this->actions[] = array_merge([
            'action' => $action,
            'scope' => array_values($scope),
            'zone' => $zone,
            'reason' => $reason,
        ], $extra);
    }

    /**
     * @param list<string> $scope
     */
    public function invalidate(array $scope, string $reason, string $source): void
    {
        $this->invalidations[] = [
            'scope' => array_values($scope),
            'reason' => $reason,
            'source' => $source,
        ];
    }

    /**
     * @param list<string> $scope
     */
    public function obligation(string $kind, array $scope, string $source, string $reason, string $rootCause = '', string $reasonId = ''): void
    {
        $this->obligations[] = [
            'kind' => $kind,
            'scope' => array_values($scope),
            'source' => $source,
            'reason' => $reason,
            'root_cause' => $rootCause,
            'reason_id' => $reasonId === '' ? $kind : $reasonId,
        ];
    }

    /**
     * @param list<string> $scope
     */
    public function hold(
        array $scope,
        string $reason,
        string $source,
        string $purpose = 'PUBLISH',
        string $until = 'CURRENT_EVIDENCE',
        string $reasonId = '',
        string $owner = 'PROTOCOL',
        string $condition = '',
        string $conditionRef = '',
        string $rootCause = ''
    ): void {
        $this->holds[] = [
            'purpose' => $purpose,
            'scope' => array_values($scope),
            'reason' => $reason,
            'source' => $source,
            'until' => $until,
            'reason_id' => $reasonId === '' ? $reason : $reasonId,
            'owner' => $owner,
            'condition' => $condition,
            'condition_ref' => $conditionRef,
            'root_cause' => $rootCause,
        ];
    }

    /**
     * @param list<string> $scope
     */
    public function escalate(string $reason, array $scope, string $rootCause, array $facts = [], bool $humanRequired = false): void
    {
        $this->escalations[] = [
            'reason' => $reason,
            'scope' => array_values($scope),
            'root_cause' => $rootCause,
            'facts' => array_values($facts),
            'human_required' => $humanRequired,
        ];
    }

    /**
     * @param list<string> $scope
     */
    public function localRebase(array $scope, string $basis, string $authorityRef, string $decision): void
    {
        $this->localRebases[] = [
            'scope' => array_values($scope),
            'basis' => $basis,
            'authority_ref' => $authorityRef,
            'decision' => $decision,
        ];
    }

    /**
     * @param list<string> $scope
     */
    public function deny(string $code, array $scope, string $reason): void
    {
        $this->denials[] = [
            'code' => $code,
            'scope' => array_values($scope),
            'reason' => $reason,
        ];
    }

    public function merge(self $other): void
    {
        $this->actions = self::unique(array_merge($this->actions, $other->actions));
        $this->invalidations = self::unique(array_merge($this->invalidations, $other->invalidations));
        $this->obligations = self::unique(array_merge($this->obligations, $other->obligations));
        $this->holds = self::unique(array_merge($this->holds, $other->holds));
        $this->escalations = self::unique(array_merge($this->escalations, $other->escalations));
        $this->denials = self::unique(array_merge($this->denials, $other->denials));
        $this->localRebases = self::unique(array_merge($this->localRebases, $other->localRebases));
        $this->notes = array_values(array_unique(array_merge($this->notes, $other->notes)));

        if ($other->negotiation !== null) {
            if ($this->negotiation === null) {
                $this->negotiation = $other->negotiation;
            } else {
                $this->negotiation['rounds'] = max((int) $this->negotiation['rounds'], (int) $other->negotiation['rounds']);
                $this->negotiation['max_rounds'] = min((int) $this->negotiation['max_rounds'], (int) $other->negotiation['max_rounds']);
                $this->negotiation['allowed'] = (bool) $this->negotiation['allowed'] && (bool) $other->negotiation['allowed'];
                $this->negotiation['scope'] = array_values(array_unique(array_merge($this->negotiation['scope'], $other->negotiation['scope'])));
                $statuses = [(string) $this->negotiation['status'], (string) $other->negotiation['status']];
                $this->negotiation['status'] = \in_array('EXHAUSTED', $statuses, true)
                    ? 'EXHAUSTED'
                    : (\in_array('RESOLVED', $statuses, true) ? 'RESOLVED' : 'ACTIVE');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actions' => $this->actions,
            'invalidations' => $this->invalidations,
            'obligations' => $this->obligations,
            'holds' => $this->holds,
            'negotiation' => $this->negotiation,
            'escalations' => $this->escalations,
            'denials' => $this->denials,
            'local_rebases' => $this->localRebases,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function unique(array $items): array
    {
        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $hash = Canonical::hash($item);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $unique[] = $item;
        }

        return $unique;
    }
}
