<?php

declare(strict_types=1);

namespace XiangGraph\Mcp\Server;

use Mcp\Exception\ToolCallException;
use XiangGraph\Mcp\Ledger\LedgerException;
use XiangGraph\Mcp\Ledger\LedgerStore;

/**
 * MCP tool surface over the read-only ledger projection.
 *
 * Every method is a pure read. Refusals are raised as ToolCallException so the
 * MCP client receives a tool-level error instead of a protocol fault, and the
 * message never includes server paths or stack traces.
 */
final class LedgerTools
{
    public function __construct(private readonly LedgerStore $store)
    {
    }

    /** @return array<string, mixed> */
    public function list_runs(): array
    {
        return [
            'runs' => $this->store->listRuns(),
        ];
    }

    /** @return array<string, mixed> */
    public function observe_goal(string $run_id): array
    {
        return $this->guard(fn (): array => $this->store->goal($run_id));
    }

    /** @return array<string, mixed> */
    public function observe_timeline(string $run_id, int $from_seq = 1, ?int $to_seq = null, int $limit = 50): array
    {
        return $this->guard(fn (): array => $this->store->timeline($run_id, $from_seq, $to_seq, $limit));
    }

    /** @return array<string, mixed> */
    public function explain_block(string $run_id, string $scope): array
    {
        return $this->guard(fn (): array => $this->store->explainBlock($run_id, $scope));
    }

    /** @return array<string, mixed> */
    public function reconcile(string $run_id): array
    {
        return $this->guard(fn (): array => $this->store->reconcile($run_id));
    }

    /** @return array<string, mixed> */
    public function read_receipt(string $run_id, int $seq): array
    {
        return $this->guard(fn (): array => $this->store->receipt($run_id, $seq));
    }

    /**
     * @param callable(): array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (LedgerException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
