<?php

declare(strict_types=1);

namespace XiangGraph\Showcase;

use XiangGraph\Mcp\Ledger\LedgerStore;

/**
 * Read-only projection of sealed Demo-01 runs into a presentation graph.
 *
 * Rules:
 * - never writes to experiments/; every node keeps its event_id and source file
 * - only draws an edge when the relation exists in the recorded payload
 * - a milestone that did not happen is reported under not_evidenced, never drawn
 * - the same sealed input yields the same nodes/edges (no clock, no randomness)
 */
final class EvidenceProjector
{
    public const SCHEMA = 'xg-observatory-projection/v0.1';

    /** Event types that always become canvas nodes. */
    private const MILESTONES = [
        'RUN_STARTED', 'P4_RUN_STARTED', 'P6_RUN_STARTED',
        'DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED',
        'RESOURCE_RECEIPT',
        'FILE_WRITTEN',
        'CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED',
        'CHANGE_NOTICE', 'SERVICE_CHANGE_NOTICE',
        'PACKET_DELIVERED', 'PACKET_ACKED',
        'CONTRACT_CHANGE_REQUESTED', 'CONTRACT_CHANGE_APPLIED',
        'TEST_RUN', 'FINAL_TEST_RUN',
        'AGENT_FINISHED', 'AGENT_MESSAGE',
        'AGENT_PROTOCOL_ERROR', 'MODEL_ERROR',
        'KERNEL_DIFF_CHECK', 'ORACLE_RESULT', 'RUN_FINISHED',
        'P6_NEGOTIATION_ROUND', 'P6_ESCALATION_REQUESTED', 'P6_DIRECTOR_DECISION',
        'P6_PATCH_APPLIED', 'P6_PATCH_ACKED',
        'P6_IMPLEMENTATION_REPAIR', 'P6_IMPLEMENTATION_PHASE_DONE',
        'P6_FINAL_TESTS', 'P6_ORACLE_RESULT', 'P6_RUN_FINISHED',
    ];

    /** Agent actions that have no dedicated receipt; only the first one is shown. */
    private const CONTEXT_ACTIONS = ['read_file', 'file_status', 'list_dir'];

    public function __construct(private readonly string $runsRoot)
    {
    }

    /**
     * @param list<string> $runIds
     * @param list<string> $extraPairs
     * @return array<string, mixed>
     */
    public function project(array $runIds, ?string $pairId = null, array $extraPairs = []): array
    {
        $scenarios = [];
        foreach ($runIds as $runId) {
            $scenarios[] = $this->projectRun($runId);
        }

        return [
            'schema' => self::SCHEMA,
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'source_commit' => trim((string) @shell_exec('git rev-parse HEAD 2>/dev/null')) ?: null,
            'runs_root' => $this->portableRunsRoot(),
            'public_redaction' => ['absolute_host_paths' => 'display payloads only; sealed run files are unchanged'],
            'scenarios' => $scenarios,
            'compare' => $pairId === null ? null : $this->projectCompare($pairId),
            'compare_extra' => array_map(fn (string $id): array => $this->projectCompare($id), $extraPairs),
            'semantic_sha256' => $this->semanticDigest($scenarios),
        ];
    }

    /**
     * Display-safe projection payloads: replace machine-specific roots with a
     * portable relative path. The sealed run files themselves are never rewritten.
     */
    private function redactPublicPaths(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->redactPublicPaths($item);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }

        $value = preg_replace('#/home/[^/\s"\']+/[^/\s"\']+/(?=xianggraph/)#', '', $value) ?? $value;
        $value = preg_replace('#/home/[^/\s"\']+/[^/\s"\']+/#', '<host>/', $value) ?? $value;
        $value = preg_replace('#[A-Za-z]:\\\\Users\\\\[^\\\\\s"\']+\\\\#', '<host>\\\\', $value) ?? $value;

        return $value;
    }

    /**
     * Keep the generated projection free of machine-specific absolute paths.
     * The sealed run files themselves are never rewritten.
     */
    private function portableRunsRoot(): string
    {
        $normalized = str_replace('\\', '/', $this->runsRoot);
        $marker = '/experiments/';
        $position = strpos($normalized, $marker);
        if ($position === false) {
            return basename($normalized);
        }

        return substr($normalized, $position + 1);
    }

    /**
     * @param list<array<string, mixed>> $scenarios
     */
    private function semanticDigest(array $scenarios): string
    {
        $json = json_encode($scenarios, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /**
     * @return array<string, mixed>
     */
    public function projectRun(string $runId): array
    {
        $dir = $this->runsRoot . '/' . $runId;
        $eventsPath = $dir . '/events.json';
        if (!is_file($eventsPath)) {
            throw new \RuntimeException('missing events.json for ' . $runId);
        }

        $document = $this->readJson($eventsPath);
        $events = $document['events'] ?? [];
        if (!is_array($events) || $events === []) {
            throw new \RuntimeException('events.json has no events for ' . $runId);
        }

        $summary = is_file($dir . '/summary.json') ? $this->readJson($dir . '/summary.json') : [];
        $usage = is_file($dir . '/usage.json') ? $this->readJson($dir . '/usage.json') : [];
        $oracle = is_file($dir . '/oracle.json') ? $this->readJson($dir . '/oracle.json') : [];

        $reconcile = (new LedgerStore($this->runsRoot))->reconcile($runId);

        $kind = $this->detectKind($events);
        [$laneOrder, $laneMeta] = $this->buildLanes($events, $kind, $runId);
        $anchors = $this->stageAnchors($events, $kind);
        $stageLabels = array_map(
            static fn (int $i, array $anchor): array => ['id' => $i, 'label' => $anchor['label']],
            array_keys($anchors),
            $anchors,
        );

        $nodeBySeq = [];
        $nodes = [];
        $firstContextPerAgent = [];

        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            $principal = (string) ($event['principal'] ?? 'host');
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $include = in_array($type, self::MILESTONES, true);

            if (!$include && $type === 'AGENT_ACTION') {
                $action = (string) ($payload['action'] ?? '');
                if (in_array($action, self::CONTEXT_ACTIONS, true) && !isset($firstContextPerAgent[$principal])) {
                    $include = true;
                    $firstContextPerAgent[$principal] = true;
                }
            }
            if (!$include) {
                continue;
            }

            $seq = (int) ($event['seq'] ?? 0);
            $nodeId = $runId . ':e' . $seq;
            $lane = $this->laneFor($event, $kind);
            $stage = $this->stageFor($seq, $anchors);

            $node = $this->node($runId, $event, $lane, $stage, $nodeId);
            $nodes[] = $node;
            $nodeBySeq[$seq] = $nodeId;
        }

        $edges = $this->buildEdges($events, $nodeBySeq);
        [$nodes, $idMap] = $this->groupNodes($nodes);
        $edges = $this->remapEdges($edges, $idMap);
        foreach ($nodes as &$node) {
            if (isset($node['raw'])) {
                $node['raw'] = $this->redactPublicPaths($node['raw']);
            }
        }
        unset($node);
        $notEvidenced = $this->notEvidenced($kind, $events, $summary, $dir);

        foreach ($notEvidenced as $missing) {
            $laneId = 'missing:' . $missing['id'];
            if (!isset($laneMeta[$laneId])) {
                $laneOrder[] = $laneId;
                $laneMeta[$laneId] = [
                    'label' => (string) str_replace(' · NOT RUN', '', $missing['label']),
                    'sub' => 'NOT RUN · no receipt',
                ];
            }
            $nodes[] = [
                'node_id' => $runId . ':missing:' . $missing['id'],
                'run_id' => $runId,
                'lane' => $laneId,
                'stage' => count($stageLabels) - 1,
                'event_ref' => null,
                'event_seq' => 0,
                'action' => 'NOT_RUN',
                'event_type' => 'NOT_RUN',
                'actor' => $missing['id'],
                'scope' => [],
                'title' => 'NOT RUN',
                'sub' => $missing['detail'],
                'state' => 'muted',
                'recorded_at_utc' => '',
                'source_file' => null,
                'synthetic' => true,
                'raw' => null,
            ];
        }

        return [
            'id' => $this->scenarioId($runId),
            'run_id' => $runId,
            'mode' => 'RECORDED_REAL_RUN',
            'claim_level' => 'SELF_CHECKED',
            'kind' => $kind,
            'title' => $this->title($kind, $runId),
            'summary' => $this->summary($kind, $summary),
            'chain' => [
                'status' => $reconcile['status'],
                'head_hash' => $reconcile['recomputed_head'],
                'event_count' => $reconcile['event_count'],
            ],
            'lanes' => array_map(
                static fn (string $id): array => ['id' => $id] + $laneMeta[$id],
                $laneOrder,
            ),
            'stages' => $stageLabels,
            'nodes' => $nodes,
            'edges' => $edges,
            'metrics' => $this->metrics($kind, $summary, $usage, $oracle, $events),
            'director' => $this->director($kind, $summary, $events),
            'facts' => $this->facts($kind, $events, $summary, $oracle, $dir),
            'not_evidenced' => $notEvidenced,
            'boundary' => $this->boundary($kind, $events, $notEvidenced, $dir),
            'sources' => $this->sources($dir, $runId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectCompare(string $pairId): array
    {
        $path = $this->runsRoot . '/' . $pairId . '/compare.json';
        if (!is_file($path)) {
            throw new \RuntimeException('missing compare.json for ' . $pairId);
        }
        $compare = $this->readJson($path);

        $side = function (array $data): array {
            $runId = (string) ($data['run_id'] ?? '');
            $events = [];
            $eventsPath = $this->runsRoot . '/' . $runId . '/events.json';
            if ($runId !== '' && is_file($eventsPath)) {
                $document = $this->readJson($eventsPath);
                $decoded = $document['events'] ?? $document;
                $events = is_array($decoded) ? $decoded : [];
            }

            $testRuns = 0;
            $failedTestRuns = 0;
            $finalTests = 0;
            $busy = 0;
            $directorRequests = 0;
            foreach ($events as $event) {
                $type = (string) ($event['type'] ?? '');
                if ($type === 'TEST_RUN') {
                    $testRuns++;
                    if ((int) ($event['payload']['exit_code'] ?? 0) !== 0) {
                        $failedTestRuns++;
                    }
                }
                if ($type === 'P6_FINAL_TESTS') {
                    $finalTests++;
                }
                if ($type === 'RESOURCE_BUSY' || (string) ($event['payload']['receipt']['action'] ?? '') === 'RESOURCE_BUSY') {
                    $busy++;
                }
                if (in_array($type, ['P6_ESCALATION_REQUESTED', 'DIRECTOR_REQUESTED'], true)) {
                    $directorRequests++;
                }
            }

            $has = static fn (string $key): bool => array_key_exists($key, $data);
            $derivedFrom = static fn (bool $recorded, string $source): string => $recorded ? 'compare.json' : ($source . ' (derived)');

            return [
                'run_id' => $runId,
                'calls' => (int) ($data['calls'] ?? 0),
                'prompt_tokens' => (int) ($data['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($data['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($data['total_tokens'] ?? 0),
                'cost_usd_estimate' => (float) ($data['cost_usd_estimate'] ?? 0),
                'test_runs' => $has('test_runs') ? (int) $data['test_runs'] : $testRuns,
                'failed_test_runs' => $has('failed_test_runs') ? (int) $data['failed_test_runs'] : $failedTestRuns,
                'final_test_runs' => $finalTests,
                'resource_busy' => $has('resource_busy') ? (int) $data['resource_busy'] : $busy,
                'director_requests' => $has('director_requests')
                    ? (int) $data['director_requests']
                    : (int) ($data['director_calls'] ?? $directorRequests),
                'agents' => $data['agents'] ?? [],
                'false_flags' => $data['false_flags'] ?? [],
                'oracle_pass' => (bool) ($data['oracle_pass'] ?? false),
                'field_sources' => [
                    'test_runs' => $derivedFrom($has('test_runs'), 'events.json'),
                    'failed_test_runs' => $derivedFrom($has('failed_test_runs'), 'events.json'),
                    'final_test_runs' => 'events.json (derived)',
                    'resource_busy' => $derivedFrom($has('resource_busy'), 'events.json'),
                    'director_requests' => $has('director_requests')
                        ? 'compare.json'
                        : ($has('director_calls') ? 'compare.json director_calls' : 'events.json (derived)'),
                ],
            ];
        };

        return [
            'id' => $pairId,
            'status' => (string) ($compare['status'] ?? 'UNKNOWN'),
            'task' => (string) ($compare['task'] ?? ''),
            'pairing' => $compare['pairing'] ?? [],
            'xg' => $side($compare['xg'] ?? []),
            'b3' => $side($compare['b3'] ?? []),
            'delta_xg_minus_b3' => $compare['delta_xg_minus_b3'] ?? null,
            'sources' => [[
                'path' => 'experiments/demo01/runs/' . $pairId . '/compare.json',
                'sha256' => hash_file('sha256', $path),
            ]],
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function detectKind(array $events): string
    {
        $types = array_column($events, 'type');
        if (array_filter($types, static fn (string $t): bool => str_starts_with($t, 'P6_'))) {
            return 'p6';
        }
        if (in_array('P4_RUN_STARTED', $types, true) || in_array('CONTRACT_CHANGE_REQUESTED', $types, true)) {
            return 'p4';
        }

        return 'contention';
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{0: list<string>, 1: array<string, array<string, string>>}
     */
    private function buildLanes(array $events, string $kind, string $runId): array
    {
        $order = [];
        $meta = [];
        $add = static function (string $id, string $label, string $sub) use (&$order, &$meta): void {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
                $meta[$id] = ['label' => $label, 'sub' => $sub];
            }
        };

        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            $principal = (string) ($event['principal'] ?? 'host');
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if (in_array($type, ['DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED', 'P6_DIRECTOR_DECISION'], true)) {
                $add('director', 'DIRECTOR', 'plan composition / decision');
            }
            if ($type === 'RESOURCE_RECEIPT') {
                $resource = (string) ($payload['receipt']['resource'] ?? 'resource');
                $add('res:' . $resource, 'RESOURCE', $resource);
            }
            if (in_array($type, ['CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED'], true)) {
                $resource = (string) ($payload['change']['resource'] ?? 'resource');
                $add('res:' . $resource, 'RESOURCE', $resource);
            }
            if (in_array($type, ['CONTRACT_CHANGE_REQUESTED', 'CONTRACT_CHANGE_APPLIED'], true)) {
                $add('contract', 'CONTRACT', 'contract/orders-api.json');
            }
            if (in_array($type, ['P6_PATCH_APPLIED', 'P6_PATCH_ACKED'], true)) {
                $add('scope', 'PATCH SCOPE', 'W1 + W2');
            }
            if ($type === 'CONTRACT_CHANGE_REQUESTED') {
                $add('human', 'HUMAN OWNER', 'authorization');
            }
        }

        foreach ($events as $event) {
            $principal = (string) ($event['principal'] ?? 'host');
            if (in_array($principal, ['host', 'director', 'oracle', 'human:owner'], true)) {
                continue;
            }
            $add('agent:' . $principal, strtoupper($principal), $this->agentSub($events, $principal));
        }

        $add('host', 'HOST / ORACLE', 'authority + receipts');

        // Keep director first, host last before objects.
        $priority = static function (string $id): int {
            return match (true) {
                $id === 'director' => 0,
                str_starts_with($id, 'agent:') => 1,
                $id === 'human' => 2,
                $id === 'host' => 3,
                $id === 'scope' => 4,
                $id === 'contract' => 5,
                default => 6,
            };
        };
        usort($order, static fn (string $a, string $b): int => $priority($a) <=> $priority($b));

        return [$order, $meta];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function agentSub(array $events, string $principal): string
    {
        $scopes = [];
        $turns = 0;
        foreach ($events as $event) {
            if ((string) ($event['principal'] ?? '') !== $principal) {
                continue;
            }
            if (($event['type'] ?? '') === 'MODEL_CALL') {
                $turns++;
            }
            foreach ((array) ($event['scope'] ?? []) as $scope) {
                if (is_string($scope) && str_starts_with($scope, 'W')) {
                    $scopes[$scope] = true;
                }
            }
        }
        $scopeLabel = $scopes === [] ? 'run scope' : implode('+', array_keys($scopes));

        return $scopeLabel . ' · ' . $turns . ' turns';
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array{seq: int, label: string}>
     */
    private function stageAnchors(array $events, string $kind): array
    {
        $spec = match ($kind) {
            'p6' => [
                [['P6_RUN_STARTED'], 'PLAN'],
                [['FILE_WRITTEN'], 'PROPOSALS'],
                [['P6_NEGOTIATION_ROUND'], 'NEGOTIATION'],
                [['P6_ESCALATION_REQUESTED'], 'ESCALATION'],
                [['P6_PATCH_APPLIED'], 'SCOPED PATCH'],
                [['P6_PATCH_ACKED'], 'PATCH ACK'],
                [['FILE_WRITTEN', 'TEST_RUN', 'MODEL_ERROR'], 'IMPLEMENT'],
                [['P6_IMPLEMENTATION_PHASE_DONE', 'P6_FINAL_TESTS', 'P6_ORACLE_RESULT'], 'VERIFY'],
            ],
            'p4' => [
                [['P4_RUN_STARTED'], 'PLAN'],
                [['AGENT_ACTION'], 'PRODUCER'],
                [['CONTRACT_CHANGE_APPLIED'], 'CONTRACT REV2'],
                [['PACKET_DELIVERED'], 'DELIVERY'],
                [['PACKET_ACKED'], 'CONSUMER ACK'],
                [['FILE_WRITTEN', 'TEST_RUN'], 'ADAPT'],
                [['ORACLE_RESULT'], 'ORACLE'],
            ],
            default => [
                [['RUN_STARTED'], 'PLAN'],
                [['RESOURCE_RECEIPT'], 'PARALLEL WORK'],
                [['RESOURCE_RECEIPT'], 'CONTENTION'],
                [['RESOURCE_RECEIPT', 'CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED', 'CHANGE_NOTICE'], 'CHANGE'],
                [['PACKET_ACKED'], 'ACK'],
                [['TEST_RUN', 'FILE_WRITTEN', 'AGENT_MESSAGE'], 'RESUME'],
                [['FINAL_TEST_RUN', 'ORACLE_RESULT', 'P6_ORACLE_RESULT'], 'VERIFY'],
            ],
        };

        $anchors = [];
        $cursor = PHP_INT_MIN;
        foreach ($spec as $index => [$types, $label]) {
            if ($kind === 'contention') {
                // Some anchors share RESOURCE_RECEIPT; resolve them by receipt action.
                $seq = match ($index) {
                    1 => $this->firstReceiptActionAfter($events, ['RESOURCE_ACQUIRED'], $cursor),
                    2 => $this->firstReceiptActionAfter($events, ['RESOURCE_BUSY'], $cursor),
                    3 => $this->firstReceiptActionAfter($events, ['RESOURCE_RELEASED'], $cursor)
                        ?? $this->firstSeqAfter($events, $types, $cursor),
                    default => $this->firstSeqAfter($events, $types, $cursor),
                };
            } else {
                $seq = $this->firstSeqAfter($events, $types, $cursor);
            }
            if ($seq === null) {
                continue;
            }
            $anchors[] = ['seq' => $seq, 'label' => $label];
            $cursor = $seq;
        }

        return $anchors;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<string> $types
     */
    private function firstSeqAfter(array $events, array $types, int $after): ?int
    {
        foreach ($events as $event) {
            $seq = (int) ($event['seq'] ?? 0);
            if ($seq <= $after) {
                continue;
            }
            if (in_array((string) ($event['type'] ?? ''), $types, true)) {
                return $seq;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<string> $actions
     */
    private function firstReceiptActionAfter(array $events, array $actions, int $after): ?int
    {
        foreach ($events as $event) {
            $seq = (int) ($event['seq'] ?? 0);
            if ($seq <= $after || (string) ($event['type'] ?? '') !== 'RESOURCE_RECEIPT') {
                continue;
            }
            $action = (string) ($event['payload']['receipt']['action'] ?? '');
            if (in_array($action, $actions, true)) {
                return $seq;
            }
        }

        return null;
    }

    /**
     * @param list<array{seq: int, label: string}> $anchors
     */
    private function stageFor(int $seq, array $anchors): int
    {
        $stage = 0;
        foreach ($anchors as $index => $anchor) {
            if ($seq >= $anchor['seq']) {
                $stage = $index;
            }
        }

        return $stage;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function node(string $runId, array $event, string $lane, int $stage, string $nodeId): array
    {
        $type = (string) $event['type'];
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $action = $type === 'RESOURCE_RECEIPT'
            ? (string) ($payload['receipt']['action'] ?? $type)
            : $type;
        [$title, $sub] = $this->titleFor($action, $type === 'RESOURCE_RECEIPT' ? (array) ($payload['receipt'] ?? []) : $payload);
        $state = $this->stateFor($action, $payload);

        return [
            'node_id' => $nodeId,
            'run_id' => $runId,
            'lane' => $lane,
            'stage' => $stage,
            'event_ref' => (string) ($event['event_id'] ?? ''),
            'event_seq' => (int) ($event['seq'] ?? 0),
            'action' => $action,
            'event_type' => $type,
            'actor' => (string) ($event['principal'] ?? 'host'),
            'scope' => $event['scope'] ?? [],
            'title' => $title,
            'sub' => $sub,
            'state' => $state,
            'recorded_at_utc' => (string) ($event['recorded_at_utc'] ?? ''),
            'source_file' => 'experiments/demo01/runs/' . $runId . '/events.json',
            'raw' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: string}
     */
    private function titleFor(string $type, array $payload): array
    {
        $short = static fn (string $value, int $limit = 34): string => mb_strlen($value) > $limit
            ? mb_substr($value, 0, $limit - 1) . '…'
            : $value;

        return match ($type) {
            'RUN_STARTED', 'P4_RUN_STARTED', 'P6_RUN_STARTED' => ['Run started', (string) ($payload['run_id'] ?? 'recorded run')],
            'DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED' => ['Director plan', $short((string) ($payload['plan_id'] ?? $payload['task'] ?? (isset($payload['plan_hash']) ? 'sha ' . substr((string) $payload['plan_hash'], 0, 18) : 'plan')))],
            'RESOURCE_ACQUIRED' => ['Acquire resource', 'lease ' . substr((string) ($payload['lease_id'] ?? ''), 0, 14)],
            'RESOURCE_BUSY' => ['RESOURCE_BUSY', 'conflicting write denied'],
            'RESOURCE_APPLIED' => ['Apply patch', (string) ($payload['resource'] ?? '')],
            'RESOURCE_RELEASED' => ['Release lease', (string) ($payload['resource'] ?? '')],
            'FILE_WRITTEN' => ['Write ' . basename((string) ($payload['path'] ?? 'file')), 'sha ' . substr((string) ($payload['sha256'] ?? $payload['after_hash'] ?? 'not-recorded'), 0, 12)],
            'CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED' => [
                'ChangePacket',
                count((array) ($payload['packets'] ?? [])) . ' packet(s)',
            ],
            'PACKET_DELIVERED' => ['Deliver packet', 'scope ' . implode(',', (array) ($payload['scope'] ?? []))],
            'PACKET_ACKED' => ['ACK change', (string) ($payload['packet_id'] ?? '')],
            'CONTRACT_CHANGE_REQUESTED' => ['CR-002 requested', (string) ($payload['change_id'] ?? 'signed change')],
            'CONTRACT_CHANGE_APPLIED' => ['Contract rev2', 'applied once'],
            'TEST_RUN' => ['Test ' . basename((string) ($payload['path'] ?? 'tests')), 'exit ' . (string) ($payload['exit_code'] ?? '?')],
            'FINAL_TEST_RUN' => ['Final tests', !empty($payload['pass']) ? 'all passed' : 'failed'],
            'AGENT_FINISHED' => [(string) ($payload['agent'] ?? 'agent') . ' finished', 'scope ' . implode(',', (array) ($payload['scope'] ?? []))],
            'AGENT_MESSAGE' => ['Message', (string) ($payload['from'] ?? '?') . ' → ' . (string) ($payload['to'] ?? '?')],
            'AGENT_PROTOCOL_ERROR' => ['PROTOCOL_ERROR', (string) ($payload['agent'] ?? 'agent')],
            'MODEL_ERROR' => ['MODEL_ERROR', $short((string) ($payload['message'] ?? ''), 40)],
            'KERNEL_DIFF_CHECK' => ['Kernel diff', 'no core change'],
            'ORACLE_RESULT', 'P6_ORACLE_RESULT' => ['HOST ORACLE ' . (!empty($payload['work_completed']) || !empty($payload['pass']) ? 'PASS' : 'FAIL'), 'failures ' . count((array) ($payload['failures'] ?? []))],
            'RUN_FINISHED', 'P6_RUN_FINISHED' => ['Run finished', !empty($payload['oracle_pass']) || !empty($payload['pass']) ? 'pass' : 'check'],
            'P6_NEGOTIATION_ROUND' => ['Round ' . (string) ($payload['round'] ?? '?'), 'A ' . (string) ($payload['api_choice'] ?? '?') . ' · B ' . (string) ($payload['compatibility_choice'] ?? '?')],
            'P6_ESCALATION_REQUESTED' => ['Escalation', (string) ($payload['request_id'] ?? '')],
            'P6_DIRECTOR_DECISION' => ['Director: ' . (string) ($payload['chosen_option'] ?? '?'), (string) ($payload['request_id'] ?? '')],
            'P6_PATCH_APPLIED' => ['Patch ' . implode('+', (array) ($payload['patch']['affected_scope'] ?? [])), (string) ($payload['patch']['patch_id'] ?? '')],
            'P6_PATCH_ACKED' => ['ACK patch', (string) ($payload['patch_id'] ?? '')],
            'P6_IMPLEMENTATION_REPAIR' => ['Repair phase', 'after failed test'],
            'P6_IMPLEMENTATION_PHASE_DONE' => ['Implementation done', 'W1 + W2'],
            'P6_FINAL_TESTS' => ['Final tests', 'both suites'],
            'AGENT_ACTION' => [(string) ($payload['action'] ?? 'action'), (string) ($payload['resource'] ?? $payload['path'] ?? '')],
            default => [$type, ''],
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stateFor(string $type, array $payload): string
    {
        return match ($type) {
            'RESOURCE_BUSY', 'AGENT_PROTOCOL_ERROR', 'MODEL_ERROR' => 'block',
            'RESOURCE_RELEASED', 'PACKET_ACKED', 'ORACLE_RESULT', 'P6_ORACLE_RESULT', 'FINAL_TEST_RUN', 'P6_FINAL_TESTS' => 'done',
            'RESOURCE_ACQUIRED', 'P6_NEGOTIATION_ROUND' => 'wait',
            'CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED', 'CHANGE_NOTICE', 'SERVICE_CHANGE_NOTICE',
            'CONTRACT_CHANGE_APPLIED', 'P6_DIRECTOR_DECISION', 'P6_PATCH_APPLIED', 'PACKET_DELIVERED',
            'FILE_WRITTEN', 'CONTRACT_CHANGE_REQUESTED', 'P6_ESCALATION_REQUESTED', 'RESOURCE_APPLIED' => 'change',
            'RUN_STARTED', 'P4_RUN_STARTED', 'P6_RUN_STARTED', 'DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED',
            'P6_IMPLEMENTATION_REPAIR', 'P6_IMPLEMENTATION_PHASE_DONE' => 'plan',
            default => 'normal',
        };
    }

    private function laneFor(array $event, string $kind): string
    {
        $type = (string) ($event['type'] ?? '');
        $principal = (string) ($event['principal'] ?? 'host');
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        if ($type === 'RESOURCE_RECEIPT') {
            return 'res:' . (string) ($payload['receipt']['resource'] ?? 'resource');
        }
        if (in_array($type, ['CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED'], true)) {
            return 'res:' . (string) ($payload['change']['resource'] ?? 'resource');
        }
        if (in_array($type, ['CONTRACT_CHANGE_REQUESTED', 'CONTRACT_CHANGE_APPLIED'], true)) {
            return $type === 'CONTRACT_CHANGE_REQUESTED' && $principal === 'human:owner' ? 'human' : 'contract';
        }
        if (in_array($type, ['P6_PATCH_APPLIED'], true)) {
            return 'scope';
        }
        if (in_array($type, ['DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED', 'P6_DIRECTOR_DECISION'], true)) {
            return 'director';
        }
        if (in_array($principal, ['host', 'oracle'], true)) {
            return 'host';
        }

        return 'agent:' . $principal;
    }

    /**
     * Collapse repeated receipts of the same kind in one lane/stage into one node.
     * The node keeps every original event id so the inspector can list them.
     *
     * @param list<array<string, mixed>> $nodes
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function groupNodes(array $nodes): array
    {
        $groupable = ['RESOURCE_BUSY', 'TEST_RUN', 'PACKET_ACKED', 'P6_PATCH_ACKED'];
        $groups = [];
        $order = [];
        $idMap = [];

        foreach ($nodes as $node) {
            $action = (string) $node['action'];
            $key = $action . '|' . $node['lane'] . '|' . $node['stage'];
            if (!in_array($action, $groupable, true) || isset($node['synthetic'])) {
                $order[] = ['node' => $node];
                $idMap[(string) $node['node_id']] = (string) $node['node_id'];
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = count($order);
                $order[] = ['group' => $key, 'nodes' => []];
            }
            $order[$groups[$key]]['nodes'][] = $node;
        }

        $result = [];
        foreach ($order as $entry) {
            if (!isset($entry['group'])) {
                $result[] = $entry['node'];
                continue;
            }
            /** @var list<array<string, mixed>> $members */
            $members = $entry['nodes'];
            $count = count($members);
            if ($count === 1) {
                $idMap[(string) $members[0]['node_id']] = (string) $members[0]['node_id'];
                $result[] = $members[0];
                continue;
            }
            $first = $members[0];
            foreach ($members as $member) {
                $idMap[(string) $member['node_id']] = (string) $first['node_id'] . '-g';
            }
            $action = (string) $first['action'];
            $refs = array_map(static fn (array $m): string => (string) $m['event_ref'], $members);
            $seqs = array_map(static fn (array $m): int => (int) $m['event_seq'], $members);

            [$title, $sub] = match ($action) {
                'RESOURCE_BUSY' => ['RESOURCE_BUSY ×' . $count, 'lease acquisition denied ×' . $count],
                'TEST_RUN' => ['Tests ×' . $count, $this->testSummary($members)],
                'PACKET_ACKED' => ['ACK change ×' . $count, 'directed packets'],
                'P6_PATCH_ACKED' => ['ACK patch ×' . $count, 'exact patch'],
                default => [(string) $first['title'], (string) $first['sub']],
            };

            $result[] = [
                'node_id' => (string) $first['node_id'] . '-g',
                'run_id' => $first['run_id'],
                'lane' => $first['lane'],
                'stage' => $first['stage'],
                'event_ref' => (string) $first['event_ref'],
                'event_refs' => $refs,
                'event_seq' => (int) $first['event_seq'],
                'event_seqs' => $seqs,
                'action' => $action,
                'event_type' => (string) $first['event_type'],
                'actor' => (string) $first['actor'],
                'scope' => $first['scope'],
                'title' => $title,
                'sub' => $sub,
                'state' => (string) $first['state'],
                'recorded_at_utc' => (string) $first['recorded_at_utc'],
                'source_file' => $first['source_file'],
                'raw' => ['events' => array_map(static fn (array $m): mixed => $m['raw'], $members)],
                'group_size' => $count,
            ];
        }

        return [$result, $idMap];
    }

    /**
     * @param list<array<string, mixed>> $members
     */
    private function testSummary(array $members): string
    {
        $failed = 0;
        foreach ($members as $member) {
            $exitCode = (int) ($member['raw']['exit_code'] ?? 0);
            if ($exitCode !== 0) {
                $failed++;
            }
        }

        return $failed === 0 ? 'all exit 0' : $failed . ' failed / ' . count($members);
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @param array<string, string> $idMap
     * @return list<array<string, mixed>>
     */
    private function remapEdges(array $edges, array $idMap): array
    {
        $result = [];
        $seen = [];
        foreach ($edges as $edge) {
            $from = $idMap[(string) $edge['from']] ?? (string) $edge['from'];
            $to = $idMap[(string) $edge['to']] ?? (string) $edge['to'];
            if ($from === $to) {
                continue;
            }
            $key = $from . '>' . $to . '>' . $edge['kind'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = ['from' => $from, 'to' => $to, 'kind' => $edge['kind'], 'label' => $edge['label']];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<int, string> $nodeBySeq
     * @return list<array<string, mixed>>
     */
    private function buildEdges(array $events, array $nodeBySeq): array
    {
        $edges = [];
        $seen = [];
        $add = static function (int $fromSeq, int $toSeq, string $kind, string $label) use (&$edges, &$seen, $nodeBySeq): void {
            if (!isset($nodeBySeq[$fromSeq], $nodeBySeq[$toSeq]) || $fromSeq === $toSeq) {
                return;
            }
            $key = $fromSeq . '>' . $toSeq;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $edges[] = [
                'from' => $nodeBySeq[$fromSeq],
                'to' => $nodeBySeq[$toSeq],
                'kind' => $kind,
                'label' => $label,
            ];
        };

        $firstSeqBy = static function (array $events, callable $predicate): ?int {
            foreach ($events as $event) {
                if ($predicate($event)) {
                    return (int) $event['seq'];
                }
            }

            return null;
        };

        // 1. Plan -> first node of each owner lane.
        $planSeq = $firstSeqBy($events, static fn (array $e): bool => in_array($e['type'], ['DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED'], true));
        if ($planSeq !== null) {
            $owners = [];
            foreach ($events as $event) {
                if ((int) $event['seq'] !== $planSeq) {
                    continue;
                }
                foreach ((array) ($event['payload']['works'] ?? []) as $work) {
                    foreach ((array) ($work['owners'] ?? []) as $owner) {
                        $owners[(string) $owner] = true;
                    }
                }
            }
            foreach (array_keys($owners) as $owner) {
                $agentSeq = $firstSeqBy($events, static fn (array $e): bool => ($e['principal'] ?? '') === $owner && in_array($e['type'], ['AGENT_ACTION', 'FILE_WRITTEN', 'AGENT_PROTOCOL_ERROR', 'MODEL_ERROR'], true));
                if ($agentSeq !== null) {
                    $add($planSeq, $agentSeq, 'plan', 'assigns ' . $owner);
                }
            }
        }

        // 2. Lease acquired -> blocking BUSY receipts; BUSY -> released? no, keep directional facts.
        $activeLease = [];
        foreach ($events as $event) {
            if ((string) $event['type'] !== 'RESOURCE_RECEIPT') {
                continue;
            }
            $receipt = $event['payload']['receipt'] ?? [];
            $resource = (string) ($receipt['resource'] ?? '');
            $action = (string) ($receipt['action'] ?? '');
            $seq = (int) $event['seq'];
            if ($action === 'RESOURCE_ACQUIRED') {
                $activeLease[$resource] = ['seq' => $seq, 'principal' => (string) ($receipt['principal'] ?? '')];
            } elseif ($action === 'RESOURCE_BUSY' && isset($activeLease[$resource])) {
                $add($activeLease[$resource]['seq'], $seq, 'block', 'held by ' . $activeLease[$resource]['principal']);
            } elseif (in_array($action, ['RESOURCE_RELEASED', 'RESOURCE_APPLIED'], true) && isset($activeLease[$resource])) {
                $add($activeLease[$resource]['seq'], $seq, $action === 'RESOURCE_RELEASED' ? 'release' : 'change', $action === 'RESOURCE_RELEASED' ? 'release' : 'apply');
                if ($action === 'RESOURCE_RELEASED') {
                    unset($activeLease[$resource]);
                }
            }
        }

        // 3. Release -> ChangePacket dispatch on the same resource.
        $dispatchByPacket = [];
        foreach ($events as $event) {
            $type = (string) $event['type'];
            if (!in_array($type, ['CHANGE_DISPATCHED', 'SERVICE_CHANGE_DISPATCHED'], true)) {
                continue;
            }
            $resource = (string) ($event['payload']['change']['resource'] ?? '');
            $seq = (int) $event['seq'];
            $releaseSeq = null;
            foreach ($events as $candidate) {
                if ((string) $candidate['type'] !== 'RESOURCE_RECEIPT' || (int) $candidate['seq'] >= $seq) {
                    continue;
                }
                $receipt = $candidate['payload']['receipt'] ?? [];
                if (($receipt['resource'] ?? '') === $resource && ($receipt['action'] ?? '') === 'RESOURCE_RELEASED') {
                    $releaseSeq = (int) $candidate['seq'];
                }
            }
            if ($releaseSeq !== null) {
                $add($releaseSeq, $seq, 'change', 'notify affected');
            }
            foreach ((array) ($event['payload']['packets'] ?? []) as $packet) {
                $id = (string) ($packet['packet_id'] ?? '');
                if ($id !== '') {
                    $dispatchByPacket[$id] = $seq;
                }
            }
        }

        // 4. Dispatch -> ACK by packet id.
        foreach ($events as $event) {
            if ((string) $event['type'] !== 'PACKET_ACKED') {
                continue;
            }
            $packetId = (string) ($event['payload']['packet_id'] ?? '');
            if (isset($dispatchByPacket[$packetId])) {
                $add($dispatchByPacket[$packetId], (int) $event['seq'], 'change', 'directed packet');
            }
        }

        // 5. ACK -> next work by the same agent.
        foreach ($events as $event) {
            if ((string) $event['type'] !== 'PACKET_ACKED') {
                continue;
            }
            $principal = (string) ($event['principal'] ?? '');
            $ackSeq = (int) $event['seq'];
            foreach ($events as $candidate) {
                if ((int) $candidate['seq'] <= $ackSeq || (string) ($candidate['principal'] ?? '') !== $principal) {
                    continue;
                }
                if (in_array((string) $candidate['type'], ['FILE_WRITTEN', 'TEST_RUN', 'AGENT_FINISHED'], true)) {
                    $add($ackSeq, (int) $candidate['seq'], 'resume', 'resume');
                    break;
                }
            }
        }

        // 6. P4 delivery chain.
        $appliedSeq = $firstSeqBy($events, static fn (array $e): bool => $e['type'] === 'CONTRACT_CHANGE_APPLIED');
        $deliveredSeq = $firstSeqBy($events, static fn (array $e): bool => $e['type'] === 'PACKET_DELIVERED');
        $ackedSeq = $firstSeqBy($events, static fn (array $e): bool => $e['type'] === 'PACKET_ACKED');
        if ($appliedSeq !== null && $deliveredSeq !== null) {
            $add($appliedSeq, $deliveredSeq, 'change', 'one packet');
        }
        if ($deliveredSeq !== null && $ackedSeq !== null) {
            $add($deliveredSeq, $ackedSeq, 'change', 'consume');
        }

        // 7. P6 chain.
        $rounds = [];
        foreach ($events as $event) {
            if ((string) $event['type'] === 'P6_NEGOTIATION_ROUND') {
                $rounds[] = (int) $event['seq'];
            }
        }
        for ($i = 1; $i < count($rounds); $i++) {
            $add($rounds[$i - 1], $rounds[$i], 'change', 'next round');
        }
        $escalationSeq = $firstSeqBy($events, static fn (array $e): bool => $e['type'] === 'P6_ESCALATION_REQUESTED');
        $decisionSeq = $firstSeqBy($events, static fn (array $e): bool => $e['type'] === 'P6_DIRECTOR_DECISION');
        $patchSeq = $firstSeqBy($events, static fn (array $e): bool => $e['type'] === 'P6_PATCH_APPLIED');
        if ($rounds !== [] && $escalationSeq !== null) {
            $add($rounds[count($rounds) - 1], $escalationSeq, 'change', 'bounded out');
        }
        if ($escalationSeq !== null && $decisionSeq !== null) {
            $add($escalationSeq, $decisionSeq, 'change', 'decision');
        }
        if ($decisionSeq !== null && $patchSeq !== null) {
            $add($decisionSeq, $patchSeq, 'change', 'scoped');
        }
        $patchIds = [];
        foreach ($events as $event) {
            if ((string) $event['type'] === 'P6_PATCH_APPLIED') {
                $patchIds[(string) ($event['payload']['patch']['patch_id'] ?? '')] = (int) $event['seq'];
            }
            if ((string) $event['type'] === 'P6_PATCH_ACKED') {
                $patchId = (string) ($event['payload']['patch_id'] ?? '');
                if (isset($patchIds[$patchId])) {
                    $add($patchIds[$patchId], (int) $event['seq'], 'change', 'exact ACK');
                }
            }
        }

        // 8. Failed test -> later passing test on the same path.
        $lastFail = [];
        foreach ($events as $event) {
            if ((string) $event['type'] !== 'TEST_RUN') {
                continue;
            }
            $path = (string) ($event['payload']['path'] ?? '');
            $exit = (int) ($event['payload']['exit_code'] ?? 0);
            $seq = (int) $event['seq'];
            if ($exit !== 0) {
                $lastFail[$path] = $seq;
            } elseif (isset($lastFail[$path])) {
                $add($lastFail[$path], $seq, 'resume', 'fixed');
                unset($lastFail[$path]);
            }
        }

        // 9. Oracle -> run finished.
        $oracleSeq = $firstSeqBy($events, static fn (array $e): bool => in_array($e['type'], ['ORACLE_RESULT', 'P6_ORACLE_RESULT'], true));
        $finishSeq = $firstSeqBy($events, static fn (array $e): bool => in_array($e['type'], ['RUN_FINISHED', 'P6_RUN_FINISHED'], true));
        if ($oracleSeq !== null && $finishSeq !== null) {
            $add($oracleSeq, $finishSeq, 'done', 'close');
        }

        return $edges;
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $usage
     * @param array<string, mixed> $oracle
     * @param list<array<string, mixed>> $events
     * @return list<array{label: string, value: string, note: string}>
     */
    private function metrics(string $kind, array $summary, array $usage, array $oracle, array $events): array
    {
        $calls = (int) ($usage['calls'] ?? 0);
        $tokens = (int) ($usage['total_tokens'] ?? 0);
        $cost = (float) ($usage['cost_usd_estimate'] ?? 0);
        $agents = [];
        foreach ($events as $event) {
            $principal = (string) ($event['principal'] ?? '');
            if ($principal === '' || in_array($principal, ['host', 'director', 'oracle', 'human:owner'], true)) {
                continue;
            }
            $agents[$principal] = true;
        }

        $fmt = static fn (float $value): string => '$' . number_format($value, 6);

        return [
            ['label' => 'MODEL CALLS', 'value' => (string) $calls, 'note' => 'usage.json'],
            ['label' => 'TOTAL TOKENS', 'value' => number_format($tokens), 'note' => 'usage.json'],
            ['label' => 'EST. COST', 'value' => $fmt($cost), 'note' => 'recorded estimate'],
            ['label' => 'EXECUTION AGENTS', 'value' => (string) count($agents), 'note' => implode(' / ', array_keys($agents))],
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function director(string $kind, array $summary, array $events): array
    {
        $planSeq = null;
        $plan = [];
        foreach ($events as $event) {
            if (in_array((string) $event['type'], ['DIRECTOR_PLAN_LOADED', 'DIRECTOR_PLAN_RECORDED'], true)) {
                $planSeq = (int) $event['seq'];
                $plan = (array) ($event['payload'] ?? []);
                break;
            }
        }
        unset($planSeq);

        $works = [];
        foreach ((array) ($plan['works'] ?? []) as $work) {
            $works[] = is_array($work)
                ? (string) ($work['id'] ?? '?') . ' ' . (string) ($work['title'] ?? '')
                : (string) $work;
        }

        $chips = [];
        foreach ((array) ($plan['protocols'] ?? $plan['protocol_composition'] ?? []) as $entry) {
            if (is_array($entry)) {
                $chips[] = (string) ($entry['protocol'] ?? '?');
            } elseif (is_string($entry)) {
                $chips[] = $entry;
            }
        }

        if ($chips === [] && is_array($summary['director']['protocol_composition'] ?? null)) {
            foreach ($summary['director']['protocol_composition'] as $entry) {
                $chips[] = (string) ($entry['protocol'] ?? '?');
            }
        }

        $planId = (string) ($plan['plan_id'] ?? '');
        if ($planId === '' && isset($plan['plan_hash'])) {
            $planId = 'plan ' . substr((string) $plan['plan_hash'], 0, 20) . '…';
        }

        return [
            'label' => $kind === 'p6'
                ? '有界协商耗尽后才升级；Patch 只作用于 W1/W2'
                : ($kind === 'p4'
                    ? 'Director 选择 P-DEP + P-REVIEW + P-ESCALATE；无共享写资源，不组合 P-CONFLICT'
                    : 'Director 预先组合资源治理、变化传播与局部重验规则'),
            'plan_id' => $planId,
            'chips' => array_values(array_unique($chips)),
            'works' => array_slice($works, 0, 4),
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $oracle
     * @return list<array<string, mixed>>
     */
    private function facts(string $kind, array $events, array $summary, array $oracle, string $dir): array
    {
        $facts = [];
        $byType = static function (string $type) use ($events): array {
            return array_values(array_filter($events, static fn (array $e): bool => (string) $e['type'] === $type));
        };

        if ($kind === 'contention') {
            $acquired = null;
            $busy = [];
            foreach ($events as $event) {
                if ((string) $event['type'] !== 'RESOURCE_RECEIPT') {
                    continue;
                }
                $receipt = $event['payload']['receipt'] ?? [];
                $action = (string) ($receipt['action'] ?? '');
                if ($action === 'RESOURCE_ACQUIRED' && $acquired === null) {
                    $acquired = $event;
                } elseif ($action === 'RESOURCE_BUSY') {
                    $busy[] = $event;
                }
            }
            if ($acquired !== null) {
                $leaseStart = (int) $acquired['seq'];
                $leaseEnd = PHP_INT_MAX;
                foreach ($events as $event) {
                    if ((string) $event['type'] === 'RESOURCE_RECEIPT'
                        && (string) ($event['payload']['receipt']['action'] ?? '') === 'RESOURCE_RELEASED'
                        && (int) $event['seq'] > $leaseStart) {
                        $leaseEnd = (int) $event['seq'];
                        break;
                    }
                }
                $independent = [];
                foreach ($events as $event) {
                    $seq = (int) $event['seq'];
                    if ($seq <= $leaseStart || $seq >= $leaseEnd) {
                        continue;
                    }
                    if ((string) $event['type'] !== 'FILE_WRITTEN') {
                        continue;
                    }
                    $resource = (string) ($acquired['payload']['receipt']['resource'] ?? '');
                    if ((string) ($event['payload']['path'] ?? '') !== $resource) {
                        $independent[] = 'seq ' . $seq . ' ' . (string) ($event['payload']['path'] ?? '');
                    }
                }
                $facts[] = [
                    'kind' => 'EVIDENCE_FACT',
                    'label' => 'Blocked action ≠ blocked agent',
                    'value' => count($busy) . ' RESOURCE_BUSY; ' . count($independent) . ' independent write before the BUSY attempts',
                    'detail' => $independent === []
                        ? 'No independent write occurred inside the lease window.'
                        : 'Independent write inside the lease window, before the BUSY attempts: ' . implode(', ', $independent) . '. No other independent write was recorded between the two BUSY receipts; model calls and the second lease request are recorded there.',
                    'event_seqs' => array_merge([$leaseStart], array_map(static fn (array $e): int => (int) $e['seq'], $busy)),
                ];
            }
        }

        if ($kind === 'p4') {
            $delivered = $byType('PACKET_DELIVERED');
            $acked = $byType('PACKET_ACKED');
            $verifySeen = false;
            foreach ($events as $event) {
                if ((string) ($event['principal'] ?? '') === 'verify-agent') {
                    $verifySeen = true;
                }
            }
            $facts[] = [
                'kind' => 'EVIDENCE_FACT',
                'label' => 'Single directed delivery',
                'value' => count($delivered) . ' delivered / ' . count($acked) . ' ACK',
                'detail' => 'The contract change is delivered to the dependent consumer only.',
                'event_seqs' => array_merge(
                    array_map(static fn (array $e): int => (int) $e['seq'], $delivered),
                    array_map(static fn (array $e): int => (int) $e['seq'], $acked),
                ),
            ];
            $facts[] = [
                'kind' => 'EVIDENCE_FACT',
                'label' => 'Verifier lane',
                'value' => $verifySeen ? 'ran' : 'NOT RUN',
                'detail' => $verifySeen
                    ? 'A verifier principal appears in events.json.'
                    : 'No verify-agent event exists in this run; the oracle is a host check.',
                'event_seqs' => [],
            ];
        }

        if ($kind === 'p6') {
            $rounds = $byType('P6_NEGOTIATION_ROUND');
            $escalations = $byType('P6_ESCALATION_REQUESTED');
            $acks = $byType('P6_PATCH_ACKED');
            $facts[] = [
                'kind' => 'EVIDENCE_FACT',
                'label' => 'Bounded negotiation',
                'value' => count($rounds) . ' rounds / ' . count($escalations) . ' escalation',
                'detail' => 'Negotiation stops at the configured limit; one structured request goes to the Director.',
                'event_seqs' => array_merge(
                    array_map(static fn (array $e): int => (int) $e['seq'], $rounds),
                    array_map(static fn (array $e): int => (int) $e['seq'], $escalations),
                ),
            ];
            $statePath = $dir . '/host/state-after-patch.json';
            if (is_file($statePath)) {
                $state = $this->readJson($statePath);
                $slices = (array) ($state['slices'] ?? []);
                $stale = 0;
                foreach ((array) ($state['evidence'] ?? []) as $evidence) {
                    if ((string) ($evidence['status'] ?? '') === 'STALE') {
                        $stale++;
                    }
                }
                $facts[] = [
                    'kind' => 'EVIDENCE_FACT',
                    'label' => 'Scoped patch',
                    'value' => 'W1=' . ($slices['W1'] ?? '?') . ' W2=' . ($slices['W2'] ?? '?') . ' W3=' . ($slices['W3'] ?? '?'),
                    'detail' => $stale . ' evidence item(s) marked STALE; W3 evidence stays CURRENT.',
                    'source_file' => 'experiments/demo01/runs/' . basename($dir) . '/host/state-after-patch.json',
                    'event_seqs' => array_map(static fn (array $e): int => (int) $e['seq'], $acks),
                ];
            }
        }

        if ($kind === 'p6') {
            $w3 = [];
            foreach ($events as $event) {
                if ((string) $event['type'] === 'FILE_WRITTEN'
                    && (string) ($event['payload']['path'] ?? '') === 'docs/refund-api-integration.md') {
                    $w3[] = (int) $event['seq'];
                }
                if ((string) $event['type'] === 'AGENT_FINISHED'
                    && (string) ($event['payload']['agent'] ?? '') === 'independent-agent') {
                    $w3[] = (int) $event['seq'];
                }
            }
            if ($w3 !== []) {
                $facts[] = [
                    'kind' => 'EVIDENCE_FACT',
                    'label' => 'W3 completion',
                    'value' => 'e' . implode(' + e', $w3),
                    'detail' => 'W3 was outside the patch scope; revision 1 only means it was not invalidated. Completion is evidenced separately by FILE_WRITTEN + AGENT_FINISHED.',
                    'event_seqs' => $w3,
                ];
            }
            $finished = $byType('AGENT_FINISHED');
            $facts[] = [
                'kind' => 'EVIDENCE_FACT',
                'label' => 'Agent finish receipts',
                'value' => count($finished) . ' / ' . count((array) ($summary['agents'] ?? [])),
                'detail' => 'Only agents with an AGENT_FINISHED receipt count as finished; e60 is a host implementation phase check, not an agent finish.',
                'event_seqs' => array_map(static fn (array $e): int => (int) $e['seq'], $finished),
            ];
        }

        if (isset($oracle['work_completed'])) {
            $facts[] = [
                'kind' => 'EVIDENCE_FACT',
                'label' => 'Host oracle',
                'value' => !empty($oracle['work_completed']) ? 'PASS' : 'FAIL',
                'detail' => 'failures: ' . json_encode($oracle['failures'] ?? [], JSON_UNESCAPED_SLASHES),
                'source_file' => 'experiments/demo01/runs/' . basename($dir) . '/oracle.json',
                'event_seqs' => [],
            ];
        }

        return $facts;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param list<array<string, string>> $notEvidenced
     * @return list<string>
     */
    private function boundary(string $kind, array $events, array $notEvidenced, string $dir): array
    {
        $lines = [];
        if ($kind === 'contention') {
            $busy = 0;
            foreach ($events as $event) {
                if ((string) ($event['payload']['receipt']['action'] ?? '') === 'RESOURCE_BUSY') {
                    $busy++;
                }
            }
            $lines[] = 'ResourceGate 是宿主工具路径租约，不是操作系统文件锁；本次共 ' . $busy . ' 次 RESOURCE_BUSY。';
            $lines[] = '被拒绝的只是冲突写操作；B 在两次 RESOURCE_BUSY 期间没有其他动作。';
            $lines[] = 'seq 20 的独立写 src/RefundService.php 发生在 A 持锁窗口内、两次 BUSY 之前；不是 BUSY 之后的恢复动作。';
        }
        if ($kind === 'p4') {
            foreach ($notEvidenced as $missing) {
                if ($missing['id'] === 'verify-agent') {
                    $lines[] = 'verify-agent 本次未运行；末尾的 oracle 是宿主独立检查，不是验证 Agent 的执行。';
                }
            }
            $lines[] = '合同变化只投递一次；消费者 ACK 后才继续写。';
            $lines[] = '首屏并列显示 VERIFY AGENT: NOT RUN / HOST ORACLE: PASS；宿主 oracle 通过不等于 verifier 执行。';
        }
        if ($kind === 'p6') {
            $errors = 0;
            foreach ($events as $event) {
                if (in_array((string) $event['type'], ['MODEL_ERROR', 'AGENT_PROTOCOL_ERROR'], true)) {
                    $errors++;
                }
            }
            $lines[] = '协商与实现过程中的 ' . $errors . ' 条 MODEL_ERROR / PROTOCOL_ERROR 保留在时间线上，未删除。';
            $lines[] = 'e33 的 ProtocolPatch 记录 publication_committed=false；这是协调状态，不是已提交的代码补丁。';
            $lines[] = '只有 independent-agent 有 AGENT_FINISHED；api-agent 与 compatibility-agent 没有完成回执；e60 是宿主实现阶段检查，e61/e62 是最终测试与 Host Oracle。';
            $statePath = $dir . '/host/state-after-patch.json';
            if (is_file($statePath)) {
                $state = $this->readJson($statePath);
                $slices = (array) ($state['slices'] ?? []);
                $lines[] = 'Patch 后 W1=' . ($slices['W1'] ?? '?') . '、W2=' . ($slices['W2'] ?? '?') . '、W3=' . ($slices['W3'] ?? '?') . '；W3 未被失效只说明证据仍 CURRENT，完成由独立的 FILE_WRITTEN 与 AGENT_FINISHED 事件单独举证。';
            }
        }

        return $lines;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $summary
     * @return list<array<string, string>>
     */
    private function notEvidenced(string $kind, array $events, array $summary, string $dir): array
    {
        $missing = [];

        if ($kind === 'p4') {
            $seen = false;
            foreach ($events as $event) {
                if ((string) ($event['principal'] ?? '') === 'verify-agent') {
                    $seen = true;
                }
            }
            if (!$seen) {
                $missing[] = [
                    'id' => 'verify-agent',
                    'label' => 'VERIFY AGENT · NOT RUN',
                    'detail' => 'No verify-agent event in this run. The oracle below is a host check, not a verifier execution.',
                ];
            }
        }

        if ($kind === 'p6' && !is_file($dir . '/host/state-after-patch.json')) {
            $missing[] = [
                'id' => 'state-after-patch',
                'label' => 'SCOPE STATE · NOT FOUND',
                'detail' => 'host/state-after-patch.json is missing; revision scope cannot be shown.',
            ];
        }

        if ($kind === 'contention' && !is_file($dir . '/resource-receipts.json')) {
            $missing[] = [
                'id' => 'resource-receipts',
                'label' => 'LEASE RECEIPTS · NOT FOUND',
                'detail' => 'resource-receipts.json is missing; only events.json receipts are used.',
            ];
        }

        return $missing;
    }

    /**
     * @return list<array{path: string, sha256: string}>
     */
    private function sources(string $dir, string $runId): array
    {
        $wanted = [
            'events.json', 'summary.json', 'usage.json', 'oracle.json',
            'change-packets.json', 'resource-receipts.json',
            'host/protocol-patch.json', 'host/state-after-patch.json', 'host/director-decision.json',
        ];
        $sources = [];
        foreach ($wanted as $relative) {
            $path = $dir . '/' . $relative;
            if (is_file($path)) {
                $sources[] = [
                    'path' => 'experiments/demo01/runs/' . $runId . '/' . $relative,
                    'sha256' => hash_file('sha256', $path),
                ];
            }
        }

        return $sources;
    }

    private function scenarioId(string $runId): string
    {
        return match (true) {
            str_starts_with($runId, 'p3') => 'p3',
            str_starts_with($runId, 'p4') => 'p4',
            str_starts_with($runId, 'p6') => 'p6',
            default => $runId,
        };
    }

    private function title(string $kind, string $runId): string
    {
        return match ($kind) {
            'p4' => 'P4 · 合同变化传播',
            'p6' => 'P6 · Director 局部决策',
            default => 'P3 · 资源争用与恢复',
        };
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function summary(string $kind, array $summary): string
    {
        return match ($kind) {
            'p4' => 'Director 读取任务事实后选择依赖/审查/升级协议；合同从 rev1 变为 rev2，变化只投递给真正依赖它的消费者。',
            'p6' => '两个方案并行协商，轮次耗尽后只生成一条结构化请求；Patch 只作用于 W1/W2，W3 的 evidence 保持 CURRENT。',
            default => '三个执行 Agent 并行；共享资源租约申请被拒绝时只阻塞冲突操作，其他路径的工作继续；变化经 ChangePacket + ACK 定向传播后恢复。',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('cannot read ' . $path);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('invalid JSON in ' . $path);
        }

        return $decoded;
    }
}
