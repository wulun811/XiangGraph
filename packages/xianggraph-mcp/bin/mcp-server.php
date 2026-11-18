<?php

declare(strict_types=1);

/**
 * XiangGraph ledger MCP server (read-only, stdio).
 *
 * Usage:
 *   XG_MCP_RUNS_ROOT=/path/to/runs php packages/xianggraph-mcp/bin/mcp-server.php
 *
 * The server projects frozen demo01 run directories. It exposes no write tool
 * and never modifies a run. Tool exposure is not authorization: every call is
 * re-validated server-side against the runs root, and unknown run ids, scopes
 * or extra arguments are refused before any file read.
 */

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use XiangGraph\Mcp\Ledger\LedgerStore;
use XiangGraph\Mcp\Server\LedgerTools;

$root = dirname(__DIR__, 3);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "missing vendor/autoload.php; run composer install\n");
    exit(1);
}
require $autoload;

$runsRoot = getenv('XG_MCP_RUNS_ROOT');
if (!is_string($runsRoot) || $runsRoot === '') {
    $runsRoot = $root . '/experiments/demo01/runs';
}

$store = new LedgerStore($runsRoot);
$tools = new LedgerTools($store);
$readOnly = new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false);

$server = Server::builder()
    ->setServerInfo('xianggraph-ledger', '0.1.0', 'Read-only projection over frozen XiangGraph demo01 evidence')
    ->setInstructions(
        'Read-only evidence access. Returned data is recorded evidence, not an authorization decision. '
        . 'No tool writes to a run, publishes a candidate or changes authority state.'
    )
    ->addTool(
        [$tools, 'list_runs'],
        name: 'list_runs',
        title: 'List recorded runs',
        description: 'List run ids available under the configured evidence root. Read-only.',
        annotations: $readOnly,
        inputSchema: [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ],
    )
    ->addTool(
        [$tools, 'observe_goal'],
        name: 'observe_goal',
        title: 'Observe run goal and plan',
        description: 'Return the recorded goal, plan id, model, works and observed scopes for one run. Read-only.',
        annotations: $readOnly,
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['run_id'],
            'additionalProperties' => false,
        ],
    )
    ->addTool(
        [$tools, 'observe_timeline'],
        name: 'observe_timeline',
        title: 'Observe event timeline',
        description: 'Return recorded events in seq order, paginated by from_seq and limit (max 200). Read-only.',
        annotations: $readOnly,
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'from_seq' => ['type' => 'integer', 'minimum' => 1],
                'to_seq' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
            ],
            'required' => ['run_id'],
            'additionalProperties' => false,
        ],
    )
    ->addTool(
        [$tools, 'explain_block'],
        name: 'explain_block',
        title: 'Explain scope blockage',
        description: 'Return failed resource receipts recorded for a plan scope (for example W1). Read-only; returns NO_BLOCK_RECORDED when nothing failed.',
        annotations: $readOnly,
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'scope' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['run_id', 'scope'],
            'additionalProperties' => false,
        ],
    )
    ->addTool(
        [$tools, 'reconcile'],
        name: 'reconcile',
        title: 'Reconcile event chain',
        description: 'Recompute the event hash chain and compare it with the stored head and event-chain.json. Returns MATCH, MISMATCH or UNKNOWN. Read-only.',
        annotations: $readOnly,
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['run_id'],
            'additionalProperties' => false,
        ],
    )
    ->addTool(
        [$tools, 'read_receipt'],
        name: 'read_receipt',
        title: 'Read one event receipt',
        description: 'Return the full recorded JSON for one event seq, including payload and hash linkage. Read-only.',
        annotations: $readOnly,
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'seq' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['run_id', 'seq'],
            'additionalProperties' => false,
        ],
    )
    ->build();

$server->run(new StdioTransport());
