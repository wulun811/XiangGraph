<?php

declare(strict_types=1);

namespace XiangGraph\Mcp\Ledger;

/**
 * Read-only projection over frozen demo01 run directories.
 *
 * Every method answers from files on disk (events.json, summary.json, plan
 * files). Nothing is inferred, repaired or written back. A run id that does not
 * resolve inside the configured runs root is refused before any file is opened,
 * so a caller cannot walk out of the evidence directory.
 */
final class LedgerStore
{
    private const RUN_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private string $root;

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(string $runsRoot)
    {
        $root = realpath($runsRoot);
        if ($root === false || !is_dir($root)) {
            throw new LedgerException('runs root does not exist: ' . $runsRoot);
        }

        $this->root = $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return list<string>
     */
    public function listRuns(): array
    {
        $runs = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!preg_match(self::RUN_ID_PATTERN, $entry)) {
                continue;
            }
            if (is_file($this->root . '/' . $entry . '/events.json')) {
                $runs[] = $entry;
            }
        }
        sort($runs);

        return $runs;
    }

    /**
     * @return array<string, mixed>
     */
    public function goal(string $runId): array
    {
        $run = $this->load($runId);
        $plan = $run['plan'];
        $summary = $run['summary'];
        $first = $run['events'][0] ?? [];
        $firstPayload = \is_array($first) && \is_array($first['payload'] ?? null) ? $first['payload'] : [];

        $works = [];
        foreach ($this->planWorks($plan) as $work) {
            $works[] = [
                'id' => $work['id'] ?? null,
                'title' => $work['title'] ?? null,
                'owners' => $work['owners'] ?? [],
                'depends_on' => $work['depends_on'] ?? [],
                'outputs' => $work['outputs'] ?? [],
            ];
        }

        $scopes = [];
        foreach ($run['events'] as $event) {
            if (!\is_array($event) || !\is_array($event['scope'] ?? null)) {
                continue;
            }
            foreach ($event['scope'] as $scope) {
                if (\is_string($scope) && $scope !== '') {
                    $scopes[$scope] = true;
                }
            }
        }
        $scopes = array_keys($scopes);
        sort($scopes);

        return [
            'run_id' => $runId,
            'goal' => $plan['goal'] ?? null,
            'plan_id' => $plan['plan_id'] ?? null,
            'policy_revision' => $plan['policy_revision'] ?? null,
            'plan_source' => $summary['plan_source'] ?? $firstPayload['plan_source'] ?? null,
            'mode' => $summary['mode'] ?? $firstPayload['mode'] ?? null,
            'model' => $firstPayload['model'] ?? $summary['model'] ?? null,
            'status' => $summary['status'] ?? null,
            'works' => $works,
            'observed_scopes' => $scopes,
            'event_count' => \count($run['events']),
            'head_hash' => $run['head_hash'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function timeline(string $runId, int $fromSeq = 1, ?int $toSeq = null, int $limit = 50): array
    {
        if ($fromSeq < 1) {
            throw new LedgerException('from_seq must be >= 1');
        }
        if ($limit < 1 || $limit > 200) {
            throw new LedgerException('limit must be between 1 and 200');
        }
        if ($toSeq !== null && $toSeq < $fromSeq) {
            throw new LedgerException('to_seq must be >= from_seq');
        }

        $run = $this->load($runId);
        $matched = [];
        foreach ($run['events'] as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $seq = (int) ($event['seq'] ?? 0);
            if ($seq < $fromSeq) {
                continue;
            }
            if ($toSeq !== null && $seq > $toSeq) {
                continue;
            }
            $matched[] = $event;
        }

        $slice = \array_slice($matched, 0, $limit);
        $lastSeq = $slice === [] ? null : (int) ($slice[\count($slice) - 1]['seq'] ?? 0);

        return [
            'run_id' => $runId,
            'total_events' => \count($run['events']),
            'matched_events' => \count($matched),
            'returned' => \count($slice),
            'next_seq' => \count($matched) > \count($slice) ? $lastSeq + 1 : null,
            'events' => array_map(static fn (array $event): array => [
                'seq' => $event['seq'] ?? null,
                'type' => $event['type'] ?? null,
                'principal' => $event['principal'] ?? null,
                'scope' => $event['scope'] ?? [],
                'recorded_at_utc' => $event['recorded_at_utc'] ?? null,
                'event_hash' => $event['event_hash'] ?? null,
                'payload' => $event['payload'] ?? [],
            ], $slice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function explainBlock(string $runId, string $scope): array
    {
        $scope = trim($scope);
        if ($scope === '') {
            throw new LedgerException('scope must not be empty');
        }

        $run = $this->load($runId);
        $resources = [];
        $knownScopes = [];
        foreach ($this->planWorks($run['plan']) as $work) {
            $id = (string) ($work['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $knownScopes[] = $id;
            if ($id !== $scope) {
                continue;
            }
            foreach (($work['resources'] ?? []) as $resource) {
                if (\is_string($resource) && $resource !== '') {
                    $resources[$resource] = true;
                }
            }
        }
        $resources = array_keys($resources);
        sort($resources);

        if (!\in_array($scope, $knownScopes, true)) {
            throw new LedgerException(
                "unknown scope '" . $scope . "'; known scopes: " . ($knownScopes === [] ? '(none in plan)' : implode(', ', $knownScopes))
            );
        }

        $blocks = [];
        foreach ($run['events'] as $event) {
            if (!\is_array($event) || ($event['type'] ?? '') !== 'RESOURCE_RECEIPT') {
                continue;
            }
            $receipt = $event['payload']['receipt'] ?? null;
            if (!\is_array($receipt) || ($receipt['ok'] ?? true) !== false) {
                continue;
            }
            $resource = (string) ($receipt['resource'] ?? '');
            if (!\in_array($resource, $resources, true)) {
                continue;
            }
            $blocks[] = [
                'seq' => $event['seq'] ?? null,
                'receipt_id' => $receipt['receipt_id'] ?? null,
                'action' => $receipt['action'] ?? null,
                'resource' => $resource,
                'reason' => $receipt['reason'] ?? null,
                'holder' => $receipt['holder'] ?? null,
                'lease_id' => $receipt['lease_id'] ?? null,
                'epoch' => $receipt['epoch'] ?? null,
                'recorded_at_utc' => $event['recorded_at_utc'] ?? null,
            ];
        }

        return [
            'run_id' => $runId,
            'scope' => $scope,
            'resources' => $resources,
            'blocked' => $blocks !== [],
            'blocks' => $blocks,
            'note' => $blocks === []
                ? 'NO_BLOCK_RECORDED: this run has no failed resource receipt for the scope resources'
                : 'blocked by ' . \count($blocks) . ' failed resource receipt(s)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcile(string $runId): array
    {
        $run = $this->load($runId);
        $chain = Chain::verify($run['events']);
        $storedHead = \is_string($run['document']['head_hash'] ?? null) ? $run['document']['head_hash'] : null;
        $chainFile = $run['chain_file'];

        $checks = [[
            'name' => 'recomputed_chain_valid',
            'ok' => $chain['valid'],
            'detail' => $chain['errors'],
        ]];

        if ($storedHead !== null) {
            $checks[] = [
                'name' => 'events_json_head_matches',
                'ok' => hash_equals($storedHead, $chain['head_hash']),
                'detail' => 'events.json declares head_hash ' . $storedHead,
            ];
        }

        if (\is_array($chainFile)) {
            $checks[] = [
                'name' => 'event_chain_file_valid',
                'ok' => ($chainFile['valid'] ?? null) === true,
                'detail' => $chainFile['errors'] ?? [],
            ];
            if (\is_string($chainFile['head_hash'] ?? null)) {
                $checks[] = [
                    'name' => 'event_chain_head_matches',
                    'ok' => hash_equals($chainFile['head_hash'], $chain['head_hash']),
                    'detail' => 'event-chain.json declares head_hash ' . $chainFile['head_hash'],
                ];
            }
        }

        $allOk = true;
        foreach ($checks as $check) {
            $allOk = $allOk && $check['ok'] === true;
        }
        $hasStoredEvidence = $storedHead !== null || \is_array($chainFile);

        return [
            'run_id' => $runId,
            'status' => !$hasStoredEvidence ? 'UNKNOWN' : ($allOk ? 'MATCH' : 'MISMATCH'),
            'recomputed_head' => $chain['head_hash'],
            'stored_head' => $storedHead,
            'event_chain_file' => \is_array($chainFile)
                ? ['valid' => $chainFile['valid'] ?? null, 'head_hash' => $chainFile['head_hash'] ?? null]
                : null,
            'event_count' => $chain['event_count'],
            'checks' => $checks,
            'note' => $hasStoredEvidence
                ? 'hash linkage recomputed from events.json and compared with the stored head'
                : 'no stored head hash in this run; integrity cannot be anchored',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receipt(string $runId, int $seq): array
    {
        if ($seq < 1) {
            throw new LedgerException('seq must be >= 1');
        }

        $run = $this->load($runId);
        foreach ($run['events'] as $event) {
            if (\is_array($event) && (int) ($event['seq'] ?? 0) === $seq) {
                return [
                    'run_id' => $runId,
                    'event' => $event,
                ];
            }
        }

        throw new LedgerException("no event with seq " . $seq . " in run '" . $runId . "'");
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $runId): array
    {
        if (isset($this->cache[$runId])) {
            return $this->cache[$runId];
        }

        if (!preg_match(self::RUN_ID_PATTERN, $runId)) {
            throw new LedgerException('invalid run_id: expected letters, digits, dot, dash or underscore');
        }

        $real = realpath($this->root . '/' . $runId);
        $rootPrefix = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($real === false || !is_dir($real) || !str_starts_with($real, $rootPrefix) || $real === $this->root) {
            throw new LedgerException("unknown run_id '" . $runId . "'");
        }

        $eventsPath = $real . '/events.json';
        if (!is_file($eventsPath)) {
            throw new LedgerException("run '" . $runId . "' has no events.json");
        }

        try {
            $document = json_decode((string) file_get_contents($eventsPath), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LedgerException('events.json is not valid JSON: ' . $e->getMessage());
        }

        if (!\is_array($document) || !\is_array($document['events'] ?? null)) {
            throw new LedgerException('events.json has no events list');
        }

        $events = $document['events'];
        $summary = $this->readJson($real . '/summary.json');
        $plan = null;
        foreach (['host/plan.json', 'director-plan.json'] as $relative) {
            $candidate = $this->readJson($real . '/' . $relative);
            if (!\is_array($candidate)) {
                continue;
            }
            $plan = \is_array($candidate['plan'] ?? null) ? $candidate['plan'] : $candidate;
            break;
        }
        if (!\is_array($plan)) {
            foreach ($events as $event) {
                if (\is_array($event) && ($event['type'] ?? '') === 'DIRECTOR_PLAN_LOADED' && \is_array($event['payload'] ?? null)) {
                    $plan = $event['payload'];
                    break;
                }
            }
        }

        $lastEvent = $events === [] ? null : $events[\count($events) - 1];
        $headHash = \is_array($lastEvent) && \is_string($lastEvent['event_hash'] ?? null) ? $lastEvent['event_hash'] : '';

        return $this->cache[$runId] = [
            'dir' => $real,
            'document' => $document,
            'events' => $events,
            'summary' => \is_array($summary) ? $summary : [],
            'plan' => \is_array($plan) ? $plan : [],
            'chain_file' => $this->readJson($real . '/event-chain.json'),
            'head_hash' => $headHash,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        try {
            $value = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $plan
     * @return list<array<string, mixed>>
     */
    private function planWorks(array $plan): array
    {
        $works = [];
        foreach (($plan['works'] ?? []) as $work) {
            if (\is_array($work)) {
                $works[] = $work;
            }
        }

        return $works;
    }
}
