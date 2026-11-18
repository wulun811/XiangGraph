<?php

declare(strict_types=1);

namespace XiangGraph\Coord;

use XiangGraph\Coord\Protocols\PConflict;
use XiangGraph\Coord\Protocols\PDep;
use XiangGraph\Coord\Protocols\PEscalate;
use XiangGraph\Coord\Protocols\PRecover;
use XiangGraph\Coord\Protocols\PReview;
use XiangGraph\Coord\Protocols\ProtocolHandler;
use XiangGraph\S1\Canonical;

/**
 * Composes situational protocols into one normalized coordination response.
 *
 * There is deliberately no central business switch. Each protocol type has a
 * generic handler; a new situation adds protocol data or a new handler, not a
 * new central business-specific branch.
 */
final class ProtocolComposer
{
    /** @var array<string, ProtocolHandler> */
    private array $handlers = [];

    public function __construct()
    {
        foreach ([new PDep(), new PReview(), new PConflict(), new PRecover(), new PEscalate()] as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
    }

    /**
     * @return list<string>
     */
    public function protocolTypes(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function compose(Plan $plan, array $event, State $state): array
    {
        $eventScope = $event['scope'] ?? [];
        if (!\is_array($eventScope) || $eventScope === []) {
            $eventScope = $plan->workIds();
        }
        $eventScope = array_values(array_unique(array_map('strval', $eventScope)));

        $merged = ProtocolEffect::for('composition');
        $participants = [];
        $errors = [];

        foreach ($plan->protocols() as $protocol) {
            $type = (string) ($protocol['protocol'] ?? '');
            $handler = $this->handlers[$type] ?? null;
            if ($handler === null) {
                $errors[] = 'no handler for protocol ' . $type;
                continue;
            }
            if (!\in_array((string) ($event['type'] ?? ''), $handler->signals(), true)) {
                continue;
            }
            $protocolScope = array_map('strval', $protocol['scope'] ?? []);
            if (!State::intersects($protocolScope, $eventScope)) {
                continue;
            }

            $participants[] = [
                'protocol' => $type,
                'version' => (string) ($protocol['version'] ?? ''),
                'scope' => $protocolScope,
            ];
            $merged->merge($handler->evaluate($plan, $protocol, $event, $state));
        }

        $unaffected = array_values(array_diff($plan->workIds(), $eventScope));
        if ($unaffected !== [] && \in_array((string) ($event['type'] ?? ''), [
            'SCHEMA_CONTRACT_CHANGED',
            'DEP_CHANGED',
            'CONFLICT_REPORTED',
            'NEGOTIATION_EXHAUSTED',
            'EXTERNAL_EFFECT_UNKNOWN',
            'GOAL_CONFLICT',
            'DEADLOCK_DETECTED',
            'CONTRACT_CHANGED',
        ], true)) {
            $merged->action('ALLOW_UNRELATED_WORK', $unaffected, 'LOCAL_DETERMINISTIC', 'unaffected work continues while the scoped branch is coordinated');
        }

        $denied = $merged->denials;
        $allowed = [];
        foreach ($merged->actions as $action) {
            if (\in_array($action['action'], ['ALLOW_PUBLISH_CURRENT_SNAPSHOT', 'PUBLISH', 'COMMIT'], true)
                && $state->isPublishHeld($action['scope'])) {
                $denied[] = [
                    'code' => 'PUBLISH_HELD',
                    'scope' => $action['scope'],
                    'reason' => 'an active hold covers this scope',
                ];
                continue;
            }
            $allowed[] = $action;
        }

        return [
            'event_id' => $event['event_id'] ?? null,
            'event_type' => $event['type'] ?? null,
            'event_scope' => $eventScope,
            'zone' => $this->zone($allowed, $merged->escalations),
            'allowable_actions' => $this->unique($allowed),
            'denied_actions' => $this->unique($denied),
            'holds' => $this->unique($merged->holds),
            'obligations' => $this->unique($merged->obligations),
            'invalidations' => $this->unique($merged->invalidations),
            'negotiation' => $merged->negotiation,
            'escalation_proposals' => $this->unique($merged->escalations),
            'local_rebases' => $this->unique($merged->localRebases),
            'notes' => array_values(array_unique($merged->notes)),
            'participants' => $participants,
            'protocol_versions' => $this->protocolVersions($participants),
            'composition_hash' => Canonical::hash([
                'plan' => $plan->hash,
                'event' => $event['event_id'] ?? null,
                'participants' => $participants,
                'actions' => $allowed,
                'holds' => $merged->holds,
                'escalations' => $merged->escalations,
                'local_rebases' => $merged->localRebases,
            ]),
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param list<array<string, mixed>> $escalations
     */
    private function zone(array $actions, array $escalations): string
    {
        if ($escalations !== []) {
            return 'DIRECTOR_DECISION';
        }
        foreach ($actions as $action) {
            if (($action['zone'] ?? '') === 'AGENT_DISCRETION') {
                return 'AGENT_DISCRETION';
            }
        }

        return 'LOCAL_DETERMINISTIC';
    }

    /**
     * @param list<array<string, mixed>> $participants
     * @return array<string, string>
     */
    private function protocolVersions(array $participants): array
    {
        $versions = [];
        foreach ($participants as $participant) {
            $versions[(string) $participant['protocol']] = (string) $participant['version'];
        }

        return $versions;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function unique(array $items): array
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
