<?php

declare(strict_types=1);

namespace XiangGraph\S1;

/**
 * Pure, deterministic semantic composition prototype for S1.
 *
 * It does not write shared state, call a model, touch a network, or commit
 * anything. It only evaluates a normalized scenario and returns a traceable
 * decision plus the impacted subgraph.
 */
final class Engine
{
    /**
     * @return array<string, mixed>
     */
    public function decide(array $scenario): array
    {
        $trace = [];
        $world = $scenario['world'] ?? [];
        $candidate = $scenario['candidate'] ?? [];
        $action = $scenario['action'] ?? ['type' => 'publish'];
        $worldRevision = $world['revision'] ?? 0;
        $contractRevision = $world['contract_revision'] ?? 0;
        $candidateHash = $this->candidateHash($candidate);

        $changedFacts = array_keys($candidate['changes'] ?? []);
        $candidateFacts = array_merge($world['facts'] ?? [], $candidate['changes'] ?? []);
        [$affectedFacts, $unknownDependency, $impactTrace, $patternMatches] = $this->impact(
            $changedFacts,
            $scenario['relations'] ?? [],
            $scenario['patterns'] ?? [],
            $candidateFacts
        );
        $workImpact = $this->workImpact($scenario['work_graph'] ?? [], $affectedFacts);
        $affectedFacts = array_values(array_unique(array_merge($affectedFacts, $workImpact['affected_facts'])));
        $derivations = $this->deriveFacts(
            $scenario['derivations'] ?? [],
            $candidateFacts,
            $scenario['execution'] ?? []
        );
        $candidateFacts = array_merge($candidateFacts, $derivations['facts']);
        $analysisScope = $this->analyzeScope($scenario, $changedFacts, $patternMatches);

        $trace[] = [
            'step' => 'normalize',
            'detail' => [
                'world_revision' => $worldRevision,
                'contract_revision' => $contractRevision,
                'candidate_id' => $candidate['id'] ?? null,
                'candidate_base_revision' => $candidate['base_revision'] ?? null,
                'candidate_hash' => $candidateHash,
                'changed_facts' => $changedFacts,
            ],
        ];
        $trace[] = [
            'step' => 'impact',
            'detail' => [
                'affected_facts' => $affectedFacts,
                'unknown_dependency' => $unknownDependency,
                'edges' => $impactTrace,
                'pattern_matches' => $patternMatches,
                'work_nodes' => $workImpact['affected_nodes'],
                'scope' => $analysisScope['status'],
            ],
        ];
        if ($derivations['trace'] !== []) {
            $trace[] = ['step' => 'derivations', 'detail' => $derivations['trace']];
        }
        $obligations = [];
        $invariantResults = [];
        $affectedInvariantIds = [];

        foreach ($scenario['invariants'] ?? [] as $invariant) {
            $affected = array_intersect($invariant['inputs'] ?? [], $affectedFacts) !== [];
            if (!$affected) {
                continue;
            }

            $affectedInvariantIds[] = $invariant['id'];
            $predicate = Predicates::evaluate(
                (string) $invariant['kind'],
                $invariant['inputs'] ?? [],
                $candidateFacts,
                $invariant['params'] ?? []
            );
            $invariantResults[$invariant['id']] = $predicate;

            $evidence = $this->evidenceStatus($invariant, $scenario, true, $candidateHash);
            $status = match (true) {
                $predicate['status'] === 'fail' => 'violated',
                $predicate['status'] === 'unknown' => 'unknown',
                $evidence['status'] === 'discharged' => 'discharged',
                $evidence['status'] === 'failed' => 'failed',
                $evidence['status'] === 'unknown' => 'unknown',
                default => 'open',
            };

            $obligations[] = [
                'id' => 'obl:' . $invariant['id'],
                'invariant' => $invariant['id'],
                'kind' => $invariant['kind'],
                'scope' => $invariant['scope'] ?? [],
                'source' => $invariant['source'] ?? null,
                'status' => $status,
                'predicate' => $predicate,
                'evidence' => $evidence,
            ];

            $trace[] = [
                'step' => 'obligation',
                'detail' => [
                    'invariant' => $invariant['id'],
                    'predicate' => $predicate,
                    'evidence' => $evidence,
                    'status' => $status,
                ],
            ];
        }

        // Unaffected invariants are still checked when they have evidence, so
        // the report can show that unaffected evidence stays valid.
        foreach ($scenario['invariants'] ?? [] as $invariant) {
            if (\in_array($invariant['id'], $affectedInvariantIds, true)) {
                continue;
            }
            $predicate = Predicates::evaluate(
                (string) $invariant['kind'],
                $invariant['inputs'] ?? [],
                $candidateFacts,
                $invariant['params'] ?? []
            );
            $invariantResults[$invariant['id']] = $predicate;
            $evidence = $this->evidenceStatus($invariant, $scenario, false, $candidateHash);
            $trace[] = [
                'step' => 'unaffected_invariant',
                'detail' => [
                    'invariant' => $invariant['id'],
                    'predicate' => $predicate,
                    'evidence' => $evidence,
                ],
            ];
        }

        $permit = $this->permitStatus($scenario);
        $effect = $this->effectStatus($scenario);
        $trace[] = ['step' => 'permit', 'detail' => $permit];
        $trace[] = ['step' => 'effect', 'detail' => $effect];

        $reasons = [];
        $hardFailure = false;
        $wait = false;
        $unknown = false;

        foreach ($obligations as $obligation) {
            if ($obligation['status'] === 'violated') {
                $hardFailure = true;
                $reasons[] = [
                    'code' => 'INVARIANT_VIOLATION',
                    'ref' => $obligation['invariant'],
                    'detail' => $obligation['predicate']['detail'],
                ];
            } elseif ($obligation['status'] === 'failed') {
                $hardFailure = true;
                $reasons[] = [
                    'code' => 'EVIDENCE_FAILED',
                    'ref' => $obligation['invariant'],
                    'detail' => 'applicable evidence reports failure',
                ];
            } elseif ($obligation['status'] === 'open') {
                $wait = true;
                $reasons[] = [
                    'code' => 'EVIDENCE_STALE_OR_MISSING',
                    'ref' => $obligation['invariant'],
                    'detail' => $obligation['evidence']['status'] ?? 'open',
                ];
            } elseif ($obligation['status'] === 'unknown') {
                $unknown = true;
                $reasons[] = [
                    'code' => 'OBLIGATION_UNKNOWN',
                    'ref' => $obligation['invariant'],
                    'detail' => $obligation['predicate']['detail'] ?? 'unknown',
                ];
            }
        }

        if (($candidate['base_revision'] ?? null) !== $worldRevision
            || ($candidate['contract_revision'] ?? null) !== $contractRevision) {
            $wait = true;
            $reasons[] = [
                'code' => 'REBASE_REQUIRED',
                'ref' => 'candidate',
                'detail' => \sprintf(
                    'candidate base=%s contract=%s; world revision=%s contract=%s',
                    (string) ($candidate['base_revision'] ?? 'null'),
                    (string) ($candidate['contract_revision'] ?? 'null'),
                    (string) $worldRevision,
                    (string) $contractRevision
                ),
            ];
        }

        if ($unknownDependency) {
            $unknown = true;
            $reasons[] = [
                'code' => 'DEPENDENCY_UNKNOWN',
                'ref' => 'relations',
                'detail' => 'an affected relation has certainty=unknown; do not default to ALLOW',
            ];
        }

        if ($analysisScope['status'] === 'OPEN_UNMAPPED') {
            $unknown = true;
            $reasons[] = [
                'code' => 'OPEN_SCOPE_UNPROVEN',
                'ref' => 'analysis_scope',
                'detail' => 'changed facts fall in an open scope with no registered dependency pattern; absence of an edge is not proof of absence',
                'unmapped_changed_facts' => $analysisScope['unmapped_changed'],
            ];
        }

        $actionType = $action['type'] ?? 'publish';
        if ($actionType === 'publish') {
            if ($permit['status'] !== 'valid') {
                $hardFailure = true;
                $reasons[] = [
                    'code' => 'PERMIT_' . strtoupper((string) $permit['status']),
                    'ref' => 'action',
                    'detail' => $permit,
                ];
            }

            if ($effect['status'] === 'idempotency_key_reuse') {
                $hardFailure = true;
                $reasons[] = [
                    'code' => 'IDEMPOTENCY_KEY_REUSE',
                    'ref' => $effect['op_key'] ?? null,
                    'detail' => 'the same op_key is bound to a different payload/kind/scope; a shared key is not a shared operation',
                    'effect' => $effect,
                ];
            } elseif ($effect['status'] === 'rejected') {
                $hardFailure = true;
                $reasons[] = [
                    'code' => 'EFFECT_REJECTED',
                    'ref' => $effect['op_key'] ?? null,
                    'detail' => $effect,
                ];
            } elseif ($effect['status'] === 'pending') {
                $wait = true;
                $reasons[] = [
                    'code' => 'EFFECT_PENDING',
                    'ref' => $effect['op_key'] ?? null,
                    'detail' => $effect,
                ];
            } elseif ($effect['status'] === 'unknown') {
                if (!empty($effect['receiver_idempotent'])) {
                    $wait = true;
                    $reasons[] = [
                        'code' => 'UNKNOWN_EFFECT_RECONCILE_SAME_KEY',
                        'ref' => $effect['op_key'] ?? null,
                        'detail' => 'receiver is idempotent: reconcile or safely resend with the same op_key; do not create a new key',
                    ];
                } else {
                    $unknown = true;
                    $reasons[] = [
                        'code' => 'UNKNOWN_EFFECT_MANUAL_RECONCILE',
                        'ref' => $effect['op_key'] ?? null,
                        'detail' => 'receiver has no idempotency contract: keep UNKNOWN and reconcile manually; do not send a second effect',
                    ];
                }
            }
        }

        $decision = match (true) {
            $hardFailure => 'DENY',
            $wait => 'WAIT',
            $unknown => 'UNKNOWN',
            default => 'ALLOW',
        };

        // "Prepared" only means the candidate is well-formed and bound to a
        // base/contract. It does not mean the candidate is valid, verified,
        // or authorized. This keeps the three states from collapsing into one.
        $candidatePrepared = isset($candidate['id'])
            && array_key_exists('base_revision', $candidate)
            && array_key_exists('contract_revision', $candidate);

        $allDischarged = $obligations !== [] && array_reduce(
            $obligations,
            static fn (bool $carry, array $obligation): bool => $carry && $obligation['status'] === 'discharged',
            true
        );

        $states = [
            'candidate_prepared' => $candidatePrepared,
            'obligations_verified' => $allDischarged,
            'publication_eligible' => $actionType === 'publish' && $decision === 'ALLOW',
            // This pure engine never performs a real publication.
            'publication_committed' => false,
        ];

        $trace[] = [
            'step' => 'decide',
            'detail' => [
                'decision' => $decision,
                'reasons' => $reasons,
                'states' => $states,
            ],
        ];

        $result = [
            'scenario_id' => $scenario['scenario_id'] ?? 'unnamed',
            'engine_version' => 's1-0.2-r3',
            'decision' => $decision,
            'states' => $states,
            'reasons' => $reasons,
            'changed_facts' => $changedFacts,
            'affected_facts' => $affectedFacts,
            'unknown_dependency' => $unknownDependency,
            'affected_invariants' => array_values($affectedInvariantIds),
            'invariants' => $invariantResults,
            'obligations' => $obligations,
            'permit' => $permit,
            'effect' => $effect,
            'candidate_hash' => $candidateHash,
            'pattern_matches' => $patternMatches,
            'analysis_scope' => $analysisScope,
            'work_impact' => $workImpact,
            'derivations' => $derivations['trace'],
            'trace' => $trace,
        ];
        $protocol = $this->buildProtocol($scenario, $result);
        $protocol['protocol_hash'] = Canonical::hash($protocol);
        $result['protocol'] = $protocol;
        $trace[] = [
            'step' => 'protocol',
            'detail' => [
                'status' => $protocol['status'],
                'new_obligations' => array_column($protocol['new_obligations'], 'id'),
                'must_wait_for' => $protocol['must_wait_for'],
                'residual_unknowns' => $protocol['residual_unknowns'],
            ],
        ];
        $result['trace'] = $trace;
        $result['caveat'] = 'Pure S1 computation only. No shared state, model call, external effect, or publication is performed.';

        return $result;
    }

    /**
     * Compare two operation orders under a declared observation function.
     *
     * @return array<string, mixed>
     */
    public function composeOperations(array $scenario, array $order): array
    {
        $operations = $scenario['composition']['operations'] ?? [];
        $facts = $scenario['world']['facts'] ?? [];
        $applied = [];
        $conflicts = [];
        $events = [];
        $eventIndex = [];
        $eventIdentity = [];

        foreach ($order as $name) {
            $operation = $operations[$name] ?? null;
            if ($operation === null) {
                $conflicts[] = \sprintf('unknown operation "%s"', $name);
                continue;
            }

            foreach ($operation['sets'] ?? [] as $key => $value) {
                if (\array_key_exists($key, $applied) && Canonical::encode($applied[$key]) !== Canonical::encode($value)) {
                    $conflicts[] = \sprintf(
                        'same key "%s" receives different values: %s vs %s',
                        $key,
                        Canonical::encode($applied[$key]),
                        Canonical::encode($value)
                    );
                }
                $applied[$key] = $value;
            }

            foreach ($operation['emits'] ?? [] as $event) {
                $key = \is_array($event) ? (string) ($event['op_key'] ?? Canonical::hash($event)) : (string) $event;
                $idempotent = \is_array($event) ? (bool) ($event['idempotent'] ?? false) : false;
                $identity = Canonical::hash([
                    'op_key' => $key,
                    'payload' => \is_array($event) ? ($event['payload'] ?? null) : null,
                    'kind' => \is_array($event) ? ($event['kind'] ?? null) : null,
                    'scope' => \is_array($event) ? ($event['scope'] ?? null) : null,
                ]);

                if ($idempotent && isset($eventIndex[$key])) {
                    if (($eventIdentity[$key] ?? null) === $identity) {
                        continue;
                    }
                    $conflicts[] = \sprintf(
                        'idempotency key "%s" reused with a different payload/kind/scope: %s vs %s',
                        $key,
                        (string) ($eventIdentity[$key] ?? 'unknown'),
                        $identity
                    );
                }
                $eventIndex[$key] = \count($events);
                $eventIdentity[$key] = $identity;
                $events[] = [
                    'op_key' => $key,
                    'idempotent' => $idempotent,
                    'identity_hash' => $identity,
                    'payload' => \is_array($event) ? ($event['payload'] ?? null) : null,
                    'kind' => \is_array($event) ? ($event['kind'] ?? null) : null,
                    'scope' => \is_array($event) ? ($event['scope'] ?? null) : null,
                    'source' => $name,
                ];
            }
        }

        $finalFacts = array_merge($facts, $applied);
        $invariants = [];
        $hasFail = false;
        $hasUnknown = false;
        foreach ($scenario['invariants'] ?? [] as $invariant) {
            $result = Predicates::evaluate(
                (string) $invariant['kind'],
                $invariant['inputs'] ?? [],
                $finalFacts,
                $invariant['params'] ?? []
            );
            $invariants[$invariant['id']] = $result;
            $hasFail = $hasFail || $result['status'] === 'fail';
            $hasUnknown = $hasUnknown || $result['status'] === 'unknown';
        }

        $eventOrderMatters = (bool) ($scenario['composition']['observation']['event_order_matters'] ?? false);
        // The semantic observation of an event is its op_key, idempotency, and
        // payload identity, not which operation happened to emit it first.
        $projectionEvents = array_map(
            static fn (array $event): array => [
                'op_key' => $event['op_key'],
                'idempotent' => $event['idempotent'],
                'identity_hash' => $event['identity_hash'],
                'payload' => $event['payload'],
                'kind' => $event['kind'],
                'scope' => $event['scope'],
            ],
            $events
        );
        if (!$eventOrderMatters) {
            usort($projectionEvents, static fn (array $a, array $b): int => strcmp($a['op_key'], $b['op_key']));
        }

        $projection = [
            'facts' => Canonical::normalize($finalFacts),
            'invariants' => $invariants,
            'events' => $projectionEvents,
            'decision' => $hasFail ? 'DENY' : ($hasUnknown ? 'UNKNOWN' : 'ALLOW'),
        ];

        return [
            'scenario_id' => $scenario['scenario_id'] ?? 'unnamed',
            'order' => $order,
            'conflicts' => $conflicts,
            'facts' => $finalFacts,
            'events' => $events,
            'invariants' => $invariants,
            'event_order_matters' => $eventOrderMatters,
            'projection' => $projection,
            'projection_hash' => Canonical::hash($projection),
        ];
    }

    /**
     * @return array{0: list<string>, 1: bool, 2: list<array<string, mixed>>, 3: list<array<string, mixed>>}
     */
    private function impact(array $changedFacts, array $relations, array $patterns, array $facts): array
    {
        $affected = array_fill_keys($changedFacts, true);
        $queue = $changedFacts;
        $unknown = false;
        $trace = [];
        $matches = [];
        $matchIndex = [];

        $addTarget = function (string $from, mixed $to, string $kind, string $source, string $certainty, array $meta = []) use (&$affected, &$queue, &$unknown, &$trace): void {
            $trace[] = array_merge([
                'from' => $from,
                'to' => $to,
                'kind' => $kind,
                'source' => $source,
                'certainty' => $certainty,
            ], $meta);
            if ($certainty === 'unknown') {
                $unknown = true;
            }
            if (!\is_string($to) || $to === '' || isset($affected[$to])) {
                return;
            }
            $affected[$to] = true;
            $queue[] = $to;
        };

        while ($queue !== []) {
            $from = array_shift($queue);

            foreach ($relations as $relation) {
                $source = (string) ($relation['source'] ?? 'unknown');
                $certainty = (string) ($relation['certainty'] ?? 'confirmed');
                $kind = (string) ($relation['kind'] ?? 'affects');
                if (($relation['from'] ?? null) === $from) {
                    $addTarget($from, $relation['to'] ?? null, $kind, $source, $certainty, ['edge' => 'explicit']);
                }
                if ($kind === 'shares_invariant' && ($relation['to'] ?? null) === $from) {
                    $addTarget($from, $relation['from'] ?? null, $kind, $source, $certainty, ['edge' => 'explicit_reverse']);
                }
            }

            foreach ($patterns as $pattern) {
                if (($pattern['status'] ?? 'registered') !== 'registered') {
                    continue;
                }
                $patternId = (string) ($pattern['id'] ?? 'unnamed-pattern');
                $patternFact = $pattern['pattern_fact'] ?? null;
                $prefix = (string) ($pattern['fact_prefix'] ?? '');
                $glob = (string) ($pattern['glob'] ?? '');
                $source = (string) ($pattern['source'] ?? 'unknown');
                $certainty = (string) ($pattern['certainty'] ?? 'confirmed');
                $dependsOn = $pattern['depends_on'] ?? [];
                $observed = $pattern['observed_matches'] ?? [];
                $patternChanged = \is_string($patternFact) && $from === $patternFact;

                $recordMatch = function (string $factKey, bool $isPatternChange) use (&$matches, &$matchIndex, $patternId, $glob, $prefix, $observed, $source, $certainty): void {
                    $path = $prefix !== '' && str_starts_with($factKey, $prefix)
                        ? substr($factKey, \strlen($prefix))
                        : $factKey;
                    $matchKey = $patternId . '|' . $factKey;
                    if (isset($matchIndex[$matchKey])) {
                        return;
                    }
                    $matchIndex[$matchKey] = true;
                    $matches[] = [
                        'pattern_id' => $patternId,
                        'fact' => $factKey,
                        'path' => $path,
                        'glob' => $glob,
                        'newly_matched' => !\in_array($path, $observed, true),
                        'pattern_changed' => $isPatternChange,
                        'source' => $source,
                        'certainty' => $certainty,
                    ];
                };

                if ($patternChanged) {
                    foreach ($dependsOn as $target) {
                        $addTarget($from, $target, 'pattern_changed', $source, $certainty, [
                            'edge' => 'open_world_pattern',
                            'pattern_id' => $patternId,
                            'pattern_changed' => true,
                        ]);
                    }
                    foreach (array_keys($facts) as $factKey) {
                        if ($prefix !== '' && !str_starts_with((string) $factKey, $prefix)) {
                            continue;
                        }
                        if ($glob !== '' && !$this->globMatches($glob, substr((string) $factKey, \strlen($prefix)))) {
                            continue;
                        }
                        $recordMatch((string) $factKey, true);
                    }
                } elseif ($prefix !== '' && str_starts_with($from, $prefix) && $glob !== '') {
                    $path = substr($from, \strlen($prefix));
                    if ($this->globMatches($glob, $path)) {
                        $recordMatch($from, false);
                    }
                }

                foreach ($matches as $match) {
                    if (($match['pattern_id'] ?? null) !== $patternId) {
                        continue;
                    }
                    if ($patternChanged || ($match['fact'] ?? null) === $from) {
                        foreach ($dependsOn as $target) {
                            $addTarget($from, $target, 'pattern_match', $source, $certainty, [
                                'edge' => 'open_world_pattern',
                                'pattern_id' => $patternId,
                                'matched_fact' => $match['fact'],
                                'newly_matched' => $match['newly_matched'],
                            ]);
                        }
                    }
                }
            }
        }

        return [array_keys($affected), $unknown, $trace, $matches];
    }

    /**
     * @return array{affected_nodes: list<string>, unaffected_nodes: list<string>, affected_facts: list<string>, edges: list<array<string, mixed>>}
     */
    private function workImpact(array $graph, array $affectedFacts): array
    {
        $nodes = $graph['nodes'] ?? [];
        if ($nodes === []) {
            return ['affected_nodes' => [], 'unaffected_nodes' => [], 'affected_facts' => [], 'edges' => []];
        }

        $affected = array_fill_keys($affectedFacts, true);
        $impacted = [];
        do {
            $changed = false;
            foreach ($nodes as $node) {
                $id = (string) ($node['id'] ?? '');
                if ($id === '' || isset($impacted[$id])) {
                    continue;
                }
                $reads = $node['reads'] ?? [];
                $writes = $node['writes'] ?? [];
                $dependsOn = $node['depends_on'] ?? [];
                $readsAffected = array_intersect($reads, array_keys($affected)) !== [];
                $writesAffected = array_intersect($writes, array_keys($affected)) !== [];
                $dependsImpacted = array_intersect($dependsOn, array_keys($impacted)) !== [];
                if (!$readsAffected && !$writesAffected && !$dependsImpacted) {
                    continue;
                }
                $impacted[$id] = true;
                $changed = true;
                foreach ($writes as $write) {
                    $affected[$write] = true;
                }
            }
        } while ($changed);

        $edges = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            foreach ($node['depends_on'] ?? [] as $dependency) {
                $edges[] = [
                    'from' => $dependency,
                    'to' => $id,
                    'both_impacted' => isset($impacted[$dependency], $impacted[$id]),
                ];
            }
        }

        $unaffected = [];
        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');
            if ($id !== '' && !isset($impacted[$id])) {
                $unaffected[] = $id;
            }
        }

        return [
            'affected_nodes' => array_keys($impacted),
            'unaffected_nodes' => $unaffected,
            'affected_facts' => array_keys($affected),
            'edges' => $edges,
        ];
    }

    /**
     * Compute a derived fact from the inputs that actually participated.
     *
     * The participant set is data, not a hard-coded "all inputs" rule. This is
     * what keeps a short-circuited max(resetAt_i) from being reported as an
     * unconditional max over every limiter.
     *
     * @return array{facts: array<string, mixed>, trace: list<array<string, mixed>>}
     */
    private function deriveFacts(array $derivations, array $facts, array $execution): array
    {
        $derived = [];
        $trace = [];
        $checks = $execution['checks'] ?? [];

        foreach ($derivations as $derivation) {
            $id = (string) ($derivation['id'] ?? 'unnamed-derivation');
            $kind = (string) ($derivation['kind'] ?? 'max');
            $inputs = $derivation['inputs'] ?? [];
            $selected = $derivation['participants'] ?? null;
            if (!\is_array($selected) || $selected === []) {
                $selected = [];
                foreach ($checks as $check) {
                    if (!empty($check['participates'])) {
                        $selected[] = (string) ($check['id'] ?? '');
                    }
                }
                if ($selected === []) {
                    $selected = array_keys($inputs);
                }
            }

            $excluded = [];
            foreach ($checks as $check) {
                if (empty($check['participates'])) {
                    $excluded[] = (string) ($check['id'] ?? '');
                }
            }

            $values = [];
            $selectedFacts = [];
            $missing = [];
            foreach ($selected as $participant) {
                $factKey = $inputs[$participant] ?? null;
                if (!\is_string($factKey) || !\array_key_exists($factKey, $facts)) {
                    $missing[] = (string) $participant;
                    continue;
                }
                $value = $facts[$factKey];
                if (!\is_int($value) && !\is_float($value)) {
                    $missing[] = (string) $participant;
                    continue;
                }
                $values[$participant] = $value;
                $selectedFacts[$factKey] = $value;
            }

            $value = null;
            if ($missing === [] && $values !== []) {
                $value = match ($kind) {
                    'min' => min($values),
                    'sum' => array_sum($values),
                    default => max($values),
                };
            }

            $output = $derivation['output'] ?? null;
            if (\is_string($output) && $value !== null) {
                $derived[$output] = $value;
            }
            $outputParticipants = $derivation['output_participants'] ?? null;
            if (\is_string($outputParticipants)) {
                $derived[$outputParticipants] = array_values($selected);
            }

            $trace[] = [
                'id' => $id,
                'kind' => $kind,
                'condition' => $derivation['condition'] ?? null,
                'selected_participants' => array_values($selected),
                'excluded_participants' => $excluded,
                'selected_facts' => $selectedFacts,
                'value' => $value,
                'output' => $output,
                'conditional' => $excluded !== [],
                'unconditional_claim_allowed' => $excluded === [],
                'missing' => $missing,
                'status' => $missing === [] ? 'computed' : 'unknown',
            ];
        }

        return ['facts' => $derived, 'trace' => $trace];
    }

    /**
     * @return array{status: string, open_prefixes: list<string>, closed: list<string>, unmapped_changed: list<string>, tracked_patterns: list<string>, residual_unknowns: list<string>}
     */
    private function analyzeScope(array $scenario, array $changedFacts, array $patternMatches): array
    {
        $scope = $scenario['analysis_scope'] ?? [];
        if ($scope === []) {
            return [
                'status' => 'CLOSED',
                'open_prefixes' => [],
                'closed' => [],
                'unmapped_changed' => [],
                'tracked_patterns' => [],
                'residual_unknowns' => [],
            ];
        }

        $prefixes = $scope['open_prefixes'] ?? [];
        $closed = $scope['closed'] ?? [];
        $matchedFacts = array_map(static fn (array $match): string => (string) $match['fact'], $patternMatches);
        $trackedPatterns = [];
        $residual = [];
        foreach ($scenario['patterns'] ?? [] as $pattern) {
            if (($pattern['status'] ?? 'registered') !== 'registered') {
                continue;
            }
            if (($pattern['coverage'] ?? 'closed') !== 'open_world_tracked') {
                continue;
            }
            $patternId = (string) ($pattern['id'] ?? 'unnamed-pattern');
            $trackedPatterns[] = $patternId;
            $residual[] = \sprintf(
                'pattern "%s" (%s) is tracked as an open-world dependency; future matching files will create new obligations, not retroactively invalidate this snapshot',
                $patternId,
                (string) ($pattern['glob'] ?? '')
            );
        }

        $unmapped = [];
        foreach ($changedFacts as $fact) {
            $fact = (string) $fact;
            if (\in_array($fact, $matchedFacts, true)) {
                continue;
            }
            $underOpenPrefix = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($fact, (string) $prefix)) {
                    $underOpenPrefix = true;
                    break;
                }
            }
            if ($underOpenPrefix) {
                $unmapped[] = $fact;
                continue;
            }
            if ($closed !== [] && !\in_array($fact, $closed, true)) {
                $unmapped[] = $fact;
            }
        }

        $status = match (true) {
            $unmapped !== [] => 'OPEN_UNMAPPED',
            $trackedPatterns !== [] => 'OPEN_WORLD_TRACKED',
            default => 'CLOSED',
        };

        return [
            'status' => $status,
            'open_prefixes' => array_values($prefixes),
            'closed' => array_values($closed),
            'unmapped_changed' => array_values(array_unique($unmapped)),
            'tracked_patterns' => $trackedPatterns,
            'residual_unknowns' => $residual,
        ];
    }

    private function globMatches(string $glob, string $path): bool
    {
        $quoted = preg_quote($glob, '#');
        $quoted = str_replace('\*\*/', '(?:.*/)?', $quoted);
        $quoted = str_replace(['\*\*', '\*', '\?'], ['.*', '[^/]*', '[^/]'], $quoted);
        $quoted = preg_replace('#\\\{([^}]+)\\\}#', '($1)', $quoted) ?? $quoted;

        return preg_match('#^' . $quoted . '$#', $path) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProtocol(array $scenario, array $result): array
    {
        $obligations = $result['obligations'] ?? [];
        $workImpact = $result['work_impact'] ?? [];
        $scope = $result['analysis_scope'] ?? ['status' => 'CLOSED', 'residual_unknowns' => []];
        $nodes = $scenario['work_graph']['nodes'] ?? [];
        $nodeById = [];
        foreach ($nodes as $node) {
            $nodeById[(string) ($node['id'] ?? '')] = $node;
        }

        $preconditions = [[
            'id' => 'pre.candidate.binding',
            'statement' => 'candidate is bound to the current world/contract revision',
            'status' => (($result['reasons'] ?? []) !== [] && \in_array('REBASE_REQUIRED', array_column($result['reasons'], 'code'), true)) ? 'pending' : 'satisfied',
        ]];
        foreach ($scenario['patterns'] ?? [] as $pattern) {
            if (($pattern['status'] ?? 'registered') !== 'registered') {
                continue;
            }
            $preconditions[] = [
                'id' => 'pre.pattern.' . (string) ($pattern['id'] ?? 'unnamed'),
                'statement' => \sprintf(
                    'glob "%s" is registered as a dependency even when it currently matches zero or more files',
                    (string) ($pattern['glob'] ?? '')
                ),
                'status' => 'satisfied',
            ];
        }
        foreach ($result['pattern_matches'] ?? [] as $match) {
            if (empty($match['newly_matched'])) {
                continue;
            }
            $preconditions[] = [
                'id' => 'pre.new-match.' . (string) $match['fact'],
                'statement' => \sprintf(
                    'newly matched resource "%s" is included in the dependency closure of [%s]',
                    (string) $match['fact'],
                    implode(', ', $scenario['patterns'][0]['depends_on'] ?? [])
                ),
                'status' => 'satisfied',
            ];
        }
        if ($scope['status'] === 'OPEN_WORLD_TRACKED') {
            $preconditions[] = [
                'id' => 'pre.open-world',
                'statement' => 'open-world scope is tracked by a registered pattern; current snapshot can be evaluated, future matches remain residual unknowns',
                'status' => 'satisfied_with_residual_unknown',
            ];
        } elseif ($scope['status'] === 'OPEN_UNMAPPED') {
            $preconditions[] = [
                'id' => 'pre.open-world',
                'statement' => 'changed facts fall in an open scope with no registered pattern; only a bounded analysis is possible',
                'status' => 'unproven',
            ];
        }

        $newObligations = [];
        foreach ($obligations as $obligation) {
            if (($obligation['status'] ?? 'open') === 'discharged') {
                continue;
            }
            $newObligations[] = [
                'id' => (string) $obligation['id'],
                'invariant' => (string) $obligation['invariant'],
                'status' => (string) $obligation['status'],
                'scope' => $obligation['scope'] ?? [],
                'because' => $obligation['evidence']['status'] ?? ($obligation['predicate']['detail'] ?? null),
            ];
        }

        $invalidated = [];
        $seenEvidence = [];
        foreach ($obligations as $obligation) {
            foreach ($obligation['evidence']['all'] ?? [] as $evidence) {
                if (!empty($evidence['applicable']) || isset($seenEvidence[$evidence['id'] ?? ''])) {
                    continue;
                }
                $seenEvidence[$evidence['id'] ?? ''] = true;
                $invalidated[] = [
                    'evidence_id' => $evidence['id'] ?? null,
                    'obligation' => $obligation['invariant'],
                    'reason' => 'bound subject/base/contract/scope does not cover the current candidate',
                ];
            }
        }

        $invariantInputs = [];
        foreach ($scenario['invariants'] ?? [] as $invariant) {
            $invariantInputs[(string) ($invariant['id'] ?? '')] = $invariant['inputs'] ?? [];
        }
        $blockedInputs = [];
        foreach ($obligations as $obligation) {
            if (($obligation['status'] ?? 'discharged') === 'discharged') {
                continue;
            }
            $blockedInputs = array_merge($blockedInputs, $invariantInputs[(string) $obligation['invariant']] ?? []);
        }
        $blockedInputs = array_values(array_unique($blockedInputs));

        $mustWaitFor = [];
        foreach ($workImpact['affected_nodes'] ?? [] as $nodeId) {
            $node = $nodeById[$nodeId] ?? [];
            if (array_intersect($node['reads'] ?? [], $blockedInputs) !== [] || array_intersect($node['writes'] ?? [], $blockedInputs) !== []) {
                $mustWaitFor[] = $nodeId;
            }
        }
        $canRunAfterPreconditions = array_values(array_diff($workImpact['affected_nodes'] ?? [], $mustWaitFor));

        $recovery = [];
        foreach ($obligations as $obligation) {
            if (($obligation['status'] ?? 'discharged') === 'discharged') {
                continue;
            }
            $recovery[] = [
                'obligation' => (string) $obligation['invariant'],
                'condition' => match ($obligation['status']) {
                    'open' => 'provide current evidence bound to the candidate hash, base, contract, and scope',
                    'unknown' => 'resolve missing facts or the participant set before re-evaluating',
                    'failed' => 'fix the failing evidence or narrow the claim',
                    'violated' => 'change the candidate so the invariant holds',
                    default => 're-evaluate the obligation',
                },
                'evidence_source' => $obligation['source'] ?? null,
            ];
        }

        if (($scope['status'] ?? 'CLOSED') === 'OPEN_UNMAPPED') {
            $recovery[] = [
                'obligation' => 'analysis_scope',
                'condition' => 'register a pattern or provide a bounded analysis for the unmapped changed facts before re-evaluating',
                'evidence_source' => 'analysis_scope',
            ];
        } elseif (($scope['status'] ?? 'CLOSED') === 'OPEN_WORLD_TRACKED') {
            $recovery[] = [
                'obligation' => 'future_change_tracking',
                'condition' => 'when a new matching file or rule change appears, re-run source extraction and bind fresh evidence before relying on this snapshot',
                'evidence_source' => 'source_observation',
            ];
        }

        $unknowns = [];
        foreach ($result['reasons'] ?? [] as $reason) {
            if (\in_array($reason['code'], ['DEPENDENCY_UNKNOWN', 'OPEN_SCOPE_UNPROVEN', 'OBLIGATION_UNKNOWN', 'UNKNOWN_EFFECT_RECONCILE_SAME_KEY', 'UNKNOWN_EFFECT_MANUAL_RECONCILE'], true)) {
                $unknowns[] = ['code' => $reason['code'], 'ref' => $reason['ref'] ?? null, 'detail' => $reason['detail'] ?? null];
            }
        }

        $steps = [];
        if (($result['pattern_matches'] ?? []) !== []) {
            $steps[] = [
                'order' => 1,
                'action' => 'recognize_dependencies',
                'refs' => array_map(static fn (array $match): string => (string) $match['fact'], $result['pattern_matches']),
                'because' => 'a changed fact or a glob rule matched registered resource patterns; the dependency must grow before evaluation',
            ];
        }
        if ($newObligations !== []) {
            $steps[] = [
                'order' => \count($steps) + 1,
                'action' => 'verify_obligations',
                'refs' => array_column($newObligations, 'invariant'),
                'because' => 'at least one obligation is not discharged for the current candidate/scope',
            ];
        }
        if ($mustWaitFor !== []) {
            $steps[] = [
                'order' => \count($steps) + 1,
                'action' => 'wait_for_dependent_work',
                'refs' => $mustWaitFor,
                'because' => 'these work nodes read or write facts whose obligations are still open/unknown/failed',
            ];
        }
        $steps[] = [
            'order' => \count($steps) + 1,
            'action' => match ($result['decision'] ?? 'UNKNOWN') {
                'ALLOW' => 'publish_candidate_for_current_snapshot',
                'WAIT' => 'hold_publication_until_recovery',
                'DENY' => 'do_not_publish',
                default => 'hold_as_unknown',
            },
            'refs' => [],
            'because' => 'decision is ' . (string) ($result['decision'] ?? 'UNKNOWN') . '; publication_committed remains false in this pure prototype',
        ];

        $actionType = $scenario['action']['type'] ?? 'publish';
        $currentSnapshotEligible = $actionType === 'publish' && ($result['decision'] ?? 'UNKNOWN') === 'ALLOW';
        $futureTracking = match ($scope['status'] ?? 'CLOSED') {
            'OPEN_WORLD_TRACKED' => 'DECLARED_NOT_ENFORCED',
            'OPEN_UNMAPPED' => 'UNPROVEN',
            default => 'NOT_APPLICABLE',
        };
        $unresolvedScope = [
            'tracked_patterns' => [],
            'unmapped_changed' => $scope['unmapped_changed'] ?? [],
            'statement' => 'No real watcher or cache-invalidation trigger is running. A tracked pattern is a declaration, not enforcement.',
        ];
        foreach ($scenario['patterns'] ?? [] as $pattern) {
            if (($pattern['status'] ?? 'registered') !== 'registered'
                || ($pattern['coverage'] ?? 'closed') !== 'open_world_tracked') {
                continue;
            }
            $unresolvedScope['tracked_patterns'][] = [
                'pattern_id' => $pattern['id'] ?? null,
                'glob' => $pattern['glob'] ?? null,
                'version' => $pattern['version'] ?? null,
                'tracking_status' => 'DECLARED_NOT_ENFORCED',
            ];
        }

        $protocol = [
            'protocol_id' => 'proto:' . (string) ($scenario['scenario_id'] ?? 'unnamed') . ':' . (string) ($result['candidate_hash'] ?? ''),
            'status' => match ($result['decision'] ?? 'UNKNOWN') {
                'ALLOW' => 'READY_FOR_CURRENT_SNAPSHOT',
                'WAIT' => 'WAITING_FOR_EVIDENCE',
                'DENY' => 'BLOCKED',
                default => 'UNKNOWN',
            },
            'current_snapshot_eligible' => $currentSnapshotEligible,
            'future_change_tracking_status' => $futureTracking,
            'unresolved_dependency_scope' => $unresolvedScope,
            'preconditions' => $preconditions,
            'can_run_concurrently' => $workImpact['unaffected_nodes'] ?? [],
            'can_run_after_preconditions' => $canRunAfterPreconditions,
            'new_obligations' => $newObligations,
            'must_wait_for' => $mustWaitFor,
            'evidence_invalidated' => $invalidated,
            'recovery_conditions' => $recovery,
            'unknowns' => $unknowns,
            'residual_unknowns' => $scope['residual_unknowns'] ?? [],
            'steps' => $steps,
            'publication_committed' => false,
            'remote_exactly_once' => false,
        ];

        return $protocol;
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceStatus(array $invariant, array $scenario, bool $affected, string $candidateHash): array
    {
        $candidates = [];
        foreach ($scenario['evidence'] ?? [] as $evidence) {
            if (!\in_array($invariant['id'], $evidence['claims'] ?? [], true)) {
                continue;
            }

            $checks = [
                'result' => ($evidence['result'] ?? 'unknown') === 'pass',
                'base' => ($evidence['base_revision'] ?? null) === ($scenario['world']['revision'] ?? null),
                'contract' => ($evidence['contract_revision'] ?? null) === ($scenario['world']['contract_revision'] ?? null),
                'scope' => $this->scopeCovers($evidence['scope'] ?? [], $invariant['scope'] ?? []),
            ];

            $subject = $evidence['subject'] ?? 'base';
            if ($affected) {
                $checks['subject'] = \in_array($subject, ['candidate', 'candidate:' . ($scenario['candidate']['id'] ?? '')], true);
                if (isset($evidence['subject_hash'])) {
                    $checks['hash'] = hash_equals($candidateHash, (string) $evidence['subject_hash']);
                }
            }

            $applicable = !\in_array(false, array_diff_key($checks, ['result' => true]), true);
            $result = (string) ($evidence['result'] ?? 'unknown');

            $entry = [
                'id' => $evidence['id'] ?? 'unnamed',
                'result' => $result,
                'applicable' => $applicable,
                'checks' => $checks,
                'source' => $evidence['source'] ?? null,
                'subject' => $subject,
            ];
            $candidates[] = $entry;

            if ($applicable && $result === 'pass') {
                return ['status' => 'discharged', 'evidence' => $entry, 'all' => $candidates];
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['applicable'] && $candidate['result'] === 'fail') {
                return ['status' => 'failed', 'evidence' => $candidate, 'all' => $candidates];
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate['applicable'] && $candidate['result'] === 'unknown') {
                return ['status' => 'unknown', 'evidence' => $candidate, 'all' => $candidates];
            }
        }

        return [
            'status' => $candidates === [] ? 'missing' : 'stale',
            'evidence' => $candidates[0] ?? null,
            'all' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function permitStatus(array $scenario): array
    {
        $action = $scenario['action'] ?? [];
        $actor = $action['actor'] ?? null;
        $actionType = $action['type'] ?? 'publish';
        if (!\is_string($actor) || !isset($scenario['actors'][$actor])) {
            return ['status' => 'unknown_actor', 'actor' => $actor];
        }

        $epoch = $scenario['actors'][$actor]['epoch'] ?? 0;
        $granted = false;
        $revoked = false;
        $staleEpoch = false;
        $scopeMismatch = false;

        foreach ($scenario['grants'] ?? [] as $grant) {
            if (($grant['actor'] ?? null) !== $actor || ($grant['action'] ?? null) !== $actionType) {
                continue;
            }
            $granted = true;
            if (!empty($grant['revoked'])) {
                $revoked = true;
                continue;
            }
            if (($grant['epoch'] ?? 0) !== $epoch) {
                $staleEpoch = true;
                continue;
            }
            if (!$this->scopeCovers($grant['scope'] ?? [], $action['scope'] ?? [])) {
                $scopeMismatch = true;
                continue;
            }

            return ['status' => 'valid', 'grant_id' => $grant['grant_id'] ?? null, 'epoch' => $epoch];
        }

        if (!$granted) {
            return ['status' => 'missing', 'actor' => $actor];
        }
        if ($revoked) {
            return ['status' => 'revoked', 'actor' => $actor];
        }
        if ($staleEpoch) {
            return ['status' => 'stale_epoch', 'actor' => $actor, 'current_epoch' => $epoch];
        }
        if ($scopeMismatch) {
            return ['status' => 'scope_mismatch', 'actor' => $actor];
        }

        return ['status' => 'missing', 'actor' => $actor];
    }

    /**
     * @return array<string, mixed>
     */
    private function effectStatus(array $scenario): array
    {
        $action = $scenario['action'] ?? [];
        $opKey = $action['effect_op_key'] ?? null;
        if (!\is_string($opKey)) {
            return ['status' => 'none'];
        }

        foreach ($scenario['effects'] ?? [] as $effect) {
            if (($effect['op_key'] ?? null) !== $opKey) {
                continue;
            }

            $expectedPayload = $action['effect_payload_fingerprint'] ?? null;
            $actualPayload = $effect['payload_fingerprint'] ?? null;
            $expectedKind = $action['effect_kind'] ?? null;
            $actualKind = $effect['kind'] ?? null;
            $expectedScope = $action['effect_scope'] ?? null;
            $actualScope = $effect['scope'] ?? null;

            $payloadMatch = $expectedPayload === null
                || (\is_string($expectedPayload) && \is_string($actualPayload) && hash_equals($expectedPayload, $actualPayload));
            $kindMatch = $expectedKind === null
                || (\is_string($expectedKind) && \is_string($actualKind) && hash_equals($expectedKind, $actualKind));
            $scopeMatch = $expectedScope === null
                || (\is_string($expectedScope) && \is_string($actualScope) && hash_equals($expectedScope, $actualScope));
            $identityMatch = $payloadMatch && $kindMatch && $scopeMatch;

            return [
                'status' => $identityMatch ? ($effect['state'] ?? 'unknown') : 'idempotency_key_reuse',
                'op_key' => $opKey,
                'receiver_idempotent' => (bool) ($effect['receiver_idempotent'] ?? false),
                'receiver' => $effect['receiver'] ?? null,
                'identity_basis' => 'event_content_only',
                'authoritative_receiver_check_required' => true,
                'remote_exactly_once' => false,
                'identity_match' => $identityMatch,
                'payload_match' => $payloadMatch,
                'kind_match' => $kindMatch,
                'scope_match' => $scopeMatch,
                'expected' => [
                    'payload_fingerprint' => $expectedPayload,
                    'kind' => $expectedKind,
                    'scope' => $expectedScope,
                ],
                'actual' => [
                    'payload_fingerprint' => $actualPayload,
                    'kind' => $actualKind,
                    'scope' => $actualScope,
                ],
            ];
        }

        return ['status' => 'unknown', 'op_key' => $opKey, 'receiver_idempotent' => false, 'reason' => 'effect_not_found'];
    }

    private function candidateHash(array $candidate): string
    {
        return Canonical::hash([
            'base_revision' => $candidate['base_revision'] ?? null,
            'contract_revision' => $candidate['contract_revision'] ?? null,
            'changes' => $candidate['changes'] ?? [],
        ]);
    }

    private function scopeCovers(array $provided, array $required): bool
    {
        if ($required === []) {
            return true;
        }
        foreach ($required as $item) {
            if (!\in_array($item, $provided, true)) {
                return false;
            }
        }

        return true;
    }
}
