<?php

declare(strict_types=1);

namespace XiangGraph\G1;

use XiangGraph\S1\Canonical;
use XiangGraph\S1\Engine;

/**
 * Builds a no-side-effect ProtocolPlan from a controlled Symfony source
 * snapshot, a change overlay, and an explicit consumer contract.
 *
 * The compiler does not execute the plan, write the workspace, call a model,
 * or publish anything. Its only semantic engine is the pure S1/R3 engine.
 */
final class ProtocolCompiler
{
    public const RUN_ID = 'G1-VERTICAL-20261113-01';

    /**
     * @param array<string, mixed> $change
     * @param array<string, mixed> $contract
     * @param array<string, mixed>|null $verification
     * @return array<string, mixed>
     */
    public function compile(
        string $workspaceDirectory,
        array $change,
        array $contract,
        ?array $verification = null
    ): array {
        $baseFiles = FileTree::read($workspaceDirectory);
        $overlayDirectory = (string) ($change['overlay_dir'] ?? '');
        if ($overlayDirectory !== '' && !str_starts_with($overlayDirectory, '/')) {
            $manifest = (string) ($change['_manifest_path'] ?? '');
            $overlayDirectory = \dirname($manifest) . '/' . $overlayDirectory;
        }
        $candidateFiles = FileTree::overlay($baseFiles, $overlayDirectory, $change['delete'] ?? []);
        $workspaceId = (string) ($contract['contract_id'] ?? basename($workspaceDirectory));
        $base = SourceFacts::scan($baseFiles, $contract, $workspaceId);
        $candidate = SourceFacts::scan($candidateFiles, $contract, $workspaceId);

        $world = $contract['world'] ?? ['revision' => 1, 'contract_revision' => 1];
        $revision = (int) ($world['revision'] ?? 1);
        $contractRevision = (int) ($world['contract_revision'] ?? 1);

        $facts = $base['facts'];
        $facts['routing.routes.index'] = $base['index']['routes'];
        $facts['routing.routes.expected'] = $base['index']['routes'];
        $facts['routing.cache'] = 'warm';
        $facts['routing.cache.expected'] = 'warm';
        $facts['di.services.index'] = $base['index']['di'];
        $facts['di.services.expected'] = $base['index']['di'];
        $facts['di.cache'] = 'warm';
        $facts['di.cache.expected'] = 'warm';
        $facts['docs.content'] = $base['index']['docs'];
        $facts['docs.published'] = $base['index']['docs'];

        $changes = $this->fileChanges($baseFiles, $candidateFiles);
        $baseImports = $this->indexImports($base['imports']);
        $candidateImports = $this->indexImports($candidate['imports']);
        $routingRuleChanged = false;
        $diRuleChanged = false;

        foreach (array_unique(array_merge(array_keys($baseImports), array_keys($candidateImports))) as $patternFact) {
            $before = $baseImports[$patternFact] ?? null;
            $after = $candidateImports[$patternFact] ?? null;
            $changed = $before === null || $after === null
                || $before['resource_raw'] !== $after['resource_raw']
                || $before['resolved_glob'] !== $after['resolved_glob'];
            if (!$changed) {
                continue;
            }
            $changes[$patternFact] = $after['resource_raw'] ?? null;
            $kind = $after['kind'] ?? $before['kind'] ?? '';
            if ($kind === 'routing_import') {
                $routingRuleChanged = true;
            }
            if ($kind === 'di_import') {
                $diRuleChanged = true;
            }
        }

        $routingIndexChanged = $base['index']['routes'] !== $candidate['index']['routes'];
        $diIndexChanged = $base['index']['di'] !== $candidate['index']['di'];
        $docsChanged = $base['index']['docs'] !== $candidate['index']['docs'];

        if ($routingIndexChanged || $routingRuleChanged) {
            $changes['routing.routes.index'] = $candidate['index']['routes'];
            $changes['routing.routes.expected'] = $candidate['index']['routes'];
            $changes['routing.cache'] = 'cold';
            $changes['routing.cache.expected'] = 'cold';
        }
        if ($diIndexChanged || $diRuleChanged) {
            $changes['di.services.index'] = $candidate['index']['di'];
            $changes['di.services.expected'] = $candidate['index']['di'];
            $changes['di.cache'] = 'cold';
            $changes['di.cache.expected'] = 'cold';
        }
        if ($docsChanged) {
            $changes['docs.content'] = $candidate['index']['docs'];
            $changes['docs.published'] = $candidate['index']['docs'];
        }

        $patterns = $this->patterns($candidate['imports'], $baseImports);
        $scope = $this->analysisScope($base, $candidate, $candidateFiles, $contract);
        $invariants = $this->invariants($contract, $base, $candidate);
        $workGraph = $this->workGraph($contract, $base, $candidate, $candidateFiles);
        $changedPaths = array_values(array_filter(
            array_map(static fn (string $fact): string => substr($fact, 5), array_keys($changes)),
            static fn (string $path): bool => str_contains($path, '/') && pathinfo($path, \PATHINFO_EXTENSION) !== ''
        ));
        $actionScope = $changedPaths === [] ? ['config/'] : array_values(array_unique($changedPaths));
        $grant = $contract['grant'] ?? ['actor' => 'g1-compiler', 'epoch' => 1];
        $actor = (string) ($grant['actor'] ?? 'g1-compiler');

        $candidateDefinition = [
            'id' => 'cand:' . (string) ($change['id'] ?? 'unnamed'),
            'base_revision' => $revision,
            'contract_revision' => $contractRevision,
            'changes' => $changes,
            'scope' => $actionScope,
        ];
        $candidateHash = Canonical::hash([
            'base_revision' => $revision,
            'contract_revision' => $contractRevision,
            'changes' => $changes,
        ]);

        $evidence = $this->evidence($contract, $invariants, $revision, $contractRevision, $candidateHash, $verification);

        $scenario = [
            'scenario_id' => 'g1:' . (string) ($change['id'] ?? 'unnamed'),
            'origin_kind' => 'CONTROLLED_SYMFONY_SOURCE',
            'source_ref' => $contract['source']['pr_url'] ?? null,
            'world' => [
                'revision' => $revision,
                'contract_revision' => $contractRevision,
                'facts' => $facts,
            ],
            'relations' => [],
            'patterns' => $patterns,
            'analysis_scope' => $scope,
            'invariants' => $invariants,
            'candidate' => $candidateDefinition,
            'evidence' => $evidence,
            'actors' => [$actor => ['epoch' => (int) ($grant['epoch'] ?? 1)]],
            'grants' => [[
                'grant_id' => 'grant:' . $actor,
                'actor' => $actor,
                'action' => 'publish',
                'scope' => $actionScope,
                'epoch' => (int) ($grant['epoch'] ?? 1),
                'revoked' => false,
            ]],
            'action' => [
                'type' => 'publish',
                'actor' => $actor,
                'scope' => $actionScope,
            ],
            'effects' => [],
            'work_graph' => $workGraph,
            'execution' => [],
        ];

        $result = (new Engine())->decide($scenario);
        $plan = $this->plan($scenario, $result, $base, $candidate, $contract, $change, $actionScope);
        $plan['plan_hash'] = Canonical::hash($plan);

        return $plan;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, mixed>
     */
    private function fileChanges(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
            if (!isset($before[$path]) && isset($after[$path])) {
                $changes['file:' . $path] = hash('sha256', $after[$path]);
                continue;
            }
            if (isset($before[$path]) && !isset($after[$path])) {
                $changes['file:' . $path] = null;
                continue;
            }
            if (hash('sha256', $before[$path]) !== hash('sha256', $after[$path])) {
                $changes['file:' . $path] = hash('sha256', $after[$path]);
            }
        }

        return $changes;
    }

    /**
     * @param list<array<string, mixed>> $imports
     * @return array<string, array<string, mixed>>
     */
    private function indexImports(array $imports): array
    {
        $indexed = [];
        foreach ($imports as $import) {
            $indexed[(string) $import['pattern_fact']] = $import;
        }

        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $candidateImports
     * @param array<string, array<string, mixed>> $baseImports
     * @return list<array<string, mixed>>
     */
    private function patterns(array $candidateImports, array $baseImports): array
    {
        $patterns = [];
        foreach ($candidateImports as $import) {
            $patternFact = (string) $import['pattern_fact'];
            $observed = $baseImports[$patternFact]['observed_matches'] ?? [];
            $patterns[] = [
                'id' => (string) $import['pattern_id'],
                'pattern_fact' => $patternFact,
                'glob' => (string) $import['resolved_glob'],
                'fact_prefix' => 'file:',
                'depends_on' => $import['depends_on'],
                'observed_matches' => $observed,
                'coverage' => $import['coverage'],
                'status' => 'registered',
                'source' => 'symfony-source:' . $import['source_ref'],
                'certainty' => $import['certainty'],
                'version' => $import['version'],
            ];
        }

        return $patterns;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @param array<string, string> $candidateFiles
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function analysisScope(array $base, array $candidate, array $candidateFiles, array $contract): array
    {
        $closed = [];
        $analyzedFiles = [];
        foreach (array_merge($base['imports'], $candidate['imports']) as $import) {
            $closed[] = (string) $import['pattern_fact'];
            $analyzedFiles[(string) $import['source_file']] = true;
            foreach ($import['observed_matches'] as $match) {
                $analyzedFiles[(string) $match] = true;
            }
        }
        foreach ([
            'routing.routes.index',
            'routing.routes.expected',
            'routing.cache',
            'routing.cache.expected',
            'di.services.index',
            'di.services.expected',
            'di.cache',
            'di.cache.expected',
            'docs.content',
            'docs.published',
        ] as $fact) {
            $closed[] = $fact;
        }

        $analyzedPrefixes = array_values($contract['analyzed_prefixes'] ?? []);
        foreach (array_keys($candidateFiles) as $path) {
            if (isset($analyzedFiles[$path])) {
                $closed[] = 'file:' . $path;
                continue;
            }
            foreach ($analyzedPrefixes as $prefix) {
                if (str_starts_with($path, (string) $prefix)) {
                    $closed[] = 'file:' . $path;
                    break;
                }
            }
        }

        return [
            'closed' => array_values(array_unique($closed)),
            'open_prefixes' => array_values($contract['open_prefixes'] ?? []),
            'unmapped_policy' => 'UNKNOWN',
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function invariants(array $contract, array $base, array $candidate): array
    {
        $scopes = $this->scopes($base, $candidate);
        $invariants = [];
        foreach ($contract['invariants'] ?? [] as $template) {
            $scopeKey = (string) ($template['scope_ref'] ?? '');
            $invariants[] = [
                'id' => (string) $template['id'],
                'kind' => (string) $template['kind'],
                'inputs' => array_values($template['inputs'] ?? []),
                'params' => $template['params'] ?? [],
                'scope' => $scopes[$scopeKey] ?? [],
                'source' => $template['source'] ?? null,
                'hard' => (bool) ($template['hard'] ?? true),
            ];
        }

        return $invariants;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @return array<string, list<string>>
     */
    private function scopes(array $base, array $candidate): array
    {
        $routingImports = array_values(array_filter($candidate['imports'], static fn (array $i): bool => $i['kind'] === 'routing_import'));
        $diImports = array_values(array_filter($candidate['imports'], static fn (array $i): bool => $i['kind'] === 'di_import'));
        $docsFiles = [];
        foreach (array_keys($candidate['hashes']) as $path) {
            if (str_starts_with($path, 'docs/')) {
                $docsFiles[] = $path;
            }
        }
        $routing = [];
        $di = [];
        foreach ($routingImports as $import) {
            $routing[] = (string) $import['source_file'];
            $routing[] = (string) $import['pattern_fact'];
        }
        foreach ($diImports as $import) {
            $di[] = (string) $import['source_file'];
            $di[] = (string) $import['pattern_fact'];
        }

        return [
            'routing' => array_values(array_unique(array_merge(
                $routing,
                $candidate['route_files'],
                ['routing.routes.index', 'routing.routes.expected', 'routing.cache', 'routing.cache.expected']
            ))),
            'di' => array_values(array_unique(array_merge(
                $di,
                $candidate['service_files'] ?? [],
                ['di.services.index', 'di.services.expected', 'di.cache', 'di.cache.expected']
            ))),
            'docs' => array_values(array_unique(array_merge($docsFiles, ['docs.content', 'docs.published']))),
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @param array<string, string> $candidateFiles
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function workGraph(array $contract, array $base, array $candidate, array $candidateFiles): array
    {
        $routingPatternFacts = [];
        $diPatternFacts = [];
        $routingFiles = [];
        $diFiles = [];
        foreach ($candidate['imports'] as $import) {
            if ($import['kind'] === 'routing_import') {
                $routingPatternFacts[] = (string) $import['pattern_fact'];
                $routingFiles = array_merge($routingFiles, $import['observed_matches']);
            }
            if ($import['kind'] === 'di_import') {
                $diPatternFacts[] = (string) $import['pattern_fact'];
                $diFiles = array_merge($diFiles, $import['observed_matches']);
            }
        }
        $placeholders = [
            '$routing_pattern_facts' => array_values(array_unique($routingPatternFacts)),
            '$di_pattern_facts' => array_values(array_unique($diPatternFacts)),
            '$routing_files' => array_values(array_unique($routingFiles)),
            '$di_files' => array_values(array_unique($diFiles)),
        ];

        $nodes = [];
        foreach ($contract['work_graph']['nodes'] ?? [] as $node) {
            $reads = [];
            foreach ($node['reads'] ?? [] as $read) {
                $reads = array_merge($reads, $placeholders[$read] ?? [$read]);
            }
            $nodes[$node['id']] = [
                'id' => (string) $node['id'],
                'reads' => array_values(array_unique($reads)),
                'writes' => array_values($node['writes'] ?? []),
                'depends_on' => array_values($node['depends_on'] ?? []),
            ];
        }

        foreach (array_keys($candidateFiles) as $path) {
            $isNew = !isset($base['hashes'][$path]);
            if (!$isNew) {
                continue;
            }
            $matchesRouting = false;
            foreach ($candidate['imports'] as $import) {
                if ($import['kind'] === 'routing_import' && SourceFacts::globMatches((string) $import['resolved_glob'], $path)) {
                    $matchesRouting = true;
                    break;
                }
            }
            if ($matchesRouting) {
                $id = 'T.R.' . substr(hash('sha256', $path), 0, 8);
                $nodes[$id] = [
                    'id' => $id,
                    'reads' => ['file:' . $path],
                    'writes' => ['routing.routes.index'],
                    'depends_on' => isset($nodes['T01']) ? ['T01'] : [],
                ];
                continue;
            }
            $matchesDi = false;
            foreach ($candidate['imports'] as $import) {
                if ($import['kind'] === 'di_import' && SourceFacts::globMatches((string) $import['resolved_glob'], $path)) {
                    $matchesDi = true;
                    break;
                }
            }
            if ($matchesDi) {
                $id = 'T.D.' . substr(hash('sha256', $path), 0, 8);
                $nodes[$id] = [
                    'id' => $id,
                    'reads' => ['file:' . $path],
                    'writes' => ['di.services.index'],
                    'depends_on' => isset($nodes['T05']) ? ['T05'] : [],
                ];
            }
        }

        return ['nodes' => array_values($nodes)];
    }

    /**
     * @param array<string, mixed> $contract
     * @param list<array<string, mixed>> $invariants
     * @param array<string, mixed>|null $verification
     * @return list<array<string, mixed>>
     */
    private function evidence(
        array $contract,
        array $invariants,
        int $revision,
        int $contractRevision,
        string $candidateHash,
        ?array $verification
    ): array {
        $byId = [];
        foreach ($invariants as $invariant) {
            $byId[(string) $invariant['id']] = $invariant;
        }

        $evidence = [];
        if ($verification !== null) {
            $result = (string) ($verification['result'] ?? 'unknown');
            foreach ($verification['claims'] ?? [] as $claim) {
                if (!isset($byId[$claim])) {
                    continue;
                }
                $evidence[] = [
                    'id' => 'ev.g1.check.' . $claim,
                    'claims' => [$claim],
                    'result' => $result,
                    'base_revision' => $revision,
                    'contract_revision' => $contractRevision,
                    'scope' => $byId[$claim]['scope'],
                    'source' => 'local_consumer_check',
                    'subject' => 'candidate',
                    'subject_hash' => $candidateHash,
                ];
            }
        }

        foreach ($contract['evidence'] ?? [] as $template) {
            $claims = array_values($template['claims'] ?? []);
            if ($claims === [] || !isset($byId[$claims[0]])) {
                continue;
            }
            $evidence[] = [
                'id' => (string) ($template['id'] ?? 'ev.unnamed'),
                'claims' => $claims,
                'result' => (string) ($template['result'] ?? 'unknown'),
                'base_revision' => $revision,
                'contract_revision' => $contractRevision,
                'scope' => $byId[$claims[0]]['scope'],
                'source' => $template['source'] ?? 'baseline_contract',
                'subject' => 'base',
            ];
        }

        return $evidence;
    }

    /**
     * @param array<string, mixed> $scenario
     * @param array<string, mixed> $result
     * @param array<string, mixed> $base
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $change
     * @param list<string> $actionScope
     * @return array<string, mixed>
     */
    private function plan(
        array $scenario,
        array $result,
        array $base,
        array $candidate,
        array $contract,
        array $change,
        array $actionScope
    ): array {
        $protocol = $result['protocol'];
        $obligations = [];
        foreach ($result['obligations'] as $obligation) {
            if (($obligation['status'] ?? 'open') === 'discharged') {
                continue;
            }
            $obligations[] = [
                'id' => $obligation['id'],
                'invariant' => $obligation['invariant'],
                'status' => $obligation['status'],
                'rule_source' => $obligation['source'],
                'object' => $obligation['scope'],
                'coverage' => $obligation['scope'],
                'verifier' => $obligation['source'],
                'because' => $obligation['evidence']['status'] ?? ($obligation['predicate']['detail'] ?? null),
            ];
        }

        $derivation = [];
        foreach ($candidate['imports'] as $import) {
            $derivation[] = [
                'kind' => 'source_fact',
                'file' => $import['source_file'],
                'line' => $import['source_line'],
                'snippet' => $import['snippet'],
                'extractor' => $import['extractor'],
                'fact' => $import['pattern_fact'],
                'value' => $import['resource_raw'],
                'version' => $import['version'],
                'source_hash' => $import['source_hash'],
            ];
            $derivation[] = [
                'kind' => 'pattern',
                'pattern_id' => $import['pattern_id'],
                'glob' => $import['resolved_glob'],
                'version' => $import['version'],
                'observed_matches' => $import['observed_matches'],
                'because' => 'a literal Symfony import resource is registered as a dependency rule, including zero-match globs',
            ];
        }
        foreach ($result['pattern_matches'] as $match) {
            $derivation[] = [
                'kind' => 'pattern_match',
                'pattern_id' => $match['pattern_id'],
                'fact' => $match['fact'],
                'newly_matched' => (bool) $match['newly_matched'],
                'pattern_changed' => (bool) $match['pattern_changed'],
                'because' => 'the changed file is under the pattern prefix and matches the registered glob',
            ];
        }
        foreach ($result['trace'] as $step) {
            if (($step['step'] ?? '') !== 'impact') {
                continue;
            }
            foreach ($step['detail']['edges'] ?? [] as $edge) {
                $derivation[] = [
                    'kind' => 'impact_edge',
                    'from' => $edge['from'] ?? null,
                    'to' => $edge['to'] ?? null,
                    'edge' => $edge['kind'] ?? null,
                    'pattern_id' => $edge['pattern_id'] ?? null,
                    'because' => 'the consumer contract maps this resource rule to the affected fact',
                ];
            }
        }
        foreach ($result['obligations'] as $obligation) {
            if (($obligation['status'] ?? 'open') === 'discharged') {
                continue;
            }
            $derivation[] = [
                'kind' => 'obligation',
                'invariant' => $obligation['invariant'],
                'status' => $obligation['status'],
                'scope' => $obligation['scope'],
                'because' => $obligation['evidence']['status'] ?? 'no current evidence bound to this candidate',
            ];
        }
        foreach ($protocol['must_wait_for'] as $node) {
            $derivation[] = [
                'kind' => 'action_constraint',
                'node' => $node,
                'constraint' => 'must_wait',
                'because' => 'this node reads or writes a fact whose obligation is open',
            ];
        }

        $unknowns = $protocol['unknowns'];
        foreach ($protocol['residual_unknowns'] as $residual) {
            $unknowns[] = [
                'code' => 'RESIDUAL_OPEN_WORLD',
                'ref' => 'patterns',
                'detail' => $residual,
            ];
        }
        $unsupported = array_merge($base['unsupported'], $candidate['unsupported']);
        $plan = [
            'plan_id' => 'plan:' . $result['candidate_hash'],
            'run_id' => self::RUN_ID,
            'task' => 'XG-G1-COMPILER-VERTICAL-001',
            'workspace_id' => $candidate['workspace_id'],
            'change_id' => $change['id'] ?? null,
            'change_description' => $change['description'] ?? null,
            'decision' => $result['decision'],
            'scope_snapshot' => [
                'extractor_version' => SourceFacts::EXTRACTOR_VERSION,
                'supported_syntax' => [
                    'PHP: $routes->import(\'literal\') / $loader->import(resource: \'literal\')',
                    'YAML: imports: [{ resource: \'literal\' }] or resource: <literal>',
                    'glob syntax: *, **, ?, {a,b}',
                ],
                'unsupported_observed' => $unsupported,
                'source_pr' => $contract['source']['pr_url'] ?? null,
                'source_commit' => $contract['source']['commit'] ?? null,
                'upstream_evidence' => $contract['source']['upstream_evidence'] ?? null,
                'base_tree_hash' => $base['index']['tree'],
                'candidate_tree_hash' => $candidate['index']['tree'],
                'base_snapshot_hash' => $base['snapshot_hash'],
                'candidate_snapshot_hash' => $candidate['snapshot_hash'],
                'contract_hash' => Canonical::hash($contract),
                'action_scope' => $actionScope,
            ],
            'current_snapshot_eligible' => (bool) $protocol['current_snapshot_eligible'],
            'future_change_tracking_status' => $protocol['future_change_tracking_status'],
            'unresolved_dependency_scope' => $protocol['unresolved_dependency_scope'],
            'preconditions' => $protocol['preconditions'],
            'concurrency' => [
                'can_run_concurrently' => $protocol['can_run_concurrently'],
                'can_run_after_preconditions' => $protocol['can_run_after_preconditions'],
                'must_wait_for' => $protocol['must_wait_for'],
            ],
            'generated_obligations' => $obligations,
            'invalidated_evidence' => $protocol['evidence_invalidated'],
            'ordered_steps' => $protocol['steps'],
            'recovery_conditions' => $protocol['recovery_conditions'],
            'unknowns' => $unknowns,
            'residual_unknowns' => $protocol['residual_unknowns'],
            'derivation_trace' => $derivation,
            'publication_committed' => false,
            'remote_exactly_once' => false,
            'authority_boundary' => 'ProtocolPlan is a proposal. current_snapshot_eligible is not an authority to commit; no real publisher, watcher, or remote effect exists in this slice.',
            'engine_result' => $result,
        ];

        return $plan;
    }
}
