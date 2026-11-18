<?php

declare(strict_types=1);

/**
 * Symfony AI -> MCP -> XiangGraph ledger bridge probe.
 *
 * Runs the official Symfony AI MCP toolbox against the real ledger server over
 * stdio. No model is called: this records the adapter/tool layer only, so it
 * cannot be presented as a real-model run.
 *
 * Usage:
 *   php packages/xianggraph-symfony/bin/bridge-probe.php --run=p3-real-03 --expect-block=W1
 */

use Composer\InstalledVersions;
use Symfony\AI\Platform\Result\ToolCall;
use XiangGraph\SymfonyBridge\Ai\McpLedgerToolbox;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$options = getopt('', ['run::', 'runs-root::', 'out::', 'expect-block::']);
$runId = is_string($options['run'] ?? null) ? $options['run'] : 'p3-real-03';
$runsRoot = is_string($options['runs-root'] ?? null) ? $options['runs-root'] : $root . '/experiments/demo01/runs';
$expectBlock = is_string($options['expect-block'] ?? null) ? $options['expect-block'] : null;
$outDir = is_string($options['out'] ?? null)
    ? $options['out']
    : $root . '/experiments/symfony_ai_bridge/' . gmdate('Ymd\THis\Z') . '-' . $runId;

$rows = [];
$notes = [];
$row = static function (array $row) use (&$rows): void {
    $row['n'] = \count($rows) + 1;
    $row['at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $rows[] = $row;
};

$toolbox = McpLedgerToolbox::create($root . '/packages/xianggraph-mcp/bin/mcp-server.php', $runsRoot);

$tools = [];
foreach ($toolbox->getTools() as $tool) {
    $tools[] = [
        'name' => $tool->getName(),
        'description' => $tool->getDescription(),
        'input_schema' => $tool->getParameters(),
    ];
}
$row(['kind' => 'toolbox/list', 'tool_count' => \count($tools), 'tools' => $tools]);
$notes[] = 'tool names are prefixed by the Symfony AI MCP bridge (xg_)';

/**
 * @param array<string, mixed> $arguments
 */
$call = static function (string $tool, array $arguments, string $expect = 'ok', ?callable $check = null) use ($toolbox, $row): void {
    $started = hrtime(true);
    $observed = 'ok';
    $payload = null;
    $error = null;

    try {
        $result = $toolbox->execute(new ToolCall('probe-' . bin2hex(random_bytes(3)), $tool, $arguments));
        $payload = $result->getResult();
    } catch (\Throwable $e) {
        $observed = 'exception';
        $error = ['class' => $e::class, 'message' => $e->getMessage()];
    }

    $passed = $observed === $expect && ($check === null || (bool) $check($payload));

    $row([
        'kind' => 'toolbox/execute',
        'tool' => $tool,
        'arguments' => $arguments,
        'expect' => $expect,
        'observed' => $observed,
        'pass' => $passed,
        'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        'result' => $payload,
        'error' => $error,
    ]);
};

$call('xg_reconcile', ['run_id' => $runId], 'ok', static fn (mixed $payload): bool => \is_array($payload) && ($payload['status'] ?? null) === 'MATCH');
$call('xg_observe_goal', ['run_id' => $runId], 'ok', static fn (mixed $payload): bool => \is_array($payload) && ($payload['run_id'] ?? null) === $runId);
$call('xg_observe_timeline', ['run_id' => $runId, 'from_seq' => 1, 'limit' => 3], 'ok', static fn (mixed $payload): bool => \is_array($payload) && ($payload['returned'] ?? null) === 3);

if ($expectBlock !== null) {
    $call('xg_explain_block', ['run_id' => $runId, 'scope' => $expectBlock], 'ok', static fn (mixed $payload): bool => \is_array($payload) && ($payload['blocked'] ?? null) === true);
    $notes[] = 'xg_explain_block(' . $expectBlock . ') was required to report blocked=true';
}

// The bridge forwards arguments unchanged; the server must still refuse an
// undeclared authority field instead of accepting it.
$call('xg_observe_goal', ['run_id' => $runId, 'qualified' => true], 'exception');
$call('xg_explain_block', ['run_id' => $runId, 'scope' => 'W99'], 'exception');

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create output directory: {$outDir}\n");
    exit(1);
}

$transcriptPath = $outDir . '/transcript.jsonl';
$handle = fopen($transcriptPath, 'wb');
foreach ($rows as $entry) {
    fwrite($handle, json_encode($entry, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
}
fclose($handle);

$calls = array_values(array_filter($rows, static fn (array $entry): bool => $entry['kind'] === 'toolbox/execute'));
$failed = array_values(array_filter($calls, static fn (array $entry): bool => $entry['pass'] !== true));

$manifest = [
    'schema' => 'xg-symfony-ai-bridge/v0.1',
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'run_id' => $runId,
    'runs_root' => $runsRoot,
    'bridge' => 'symfony/ai-mcp-tool McpToolbox + ClientToolset',
    'environment' => [
        'php' => PHP_VERSION,
        'symfony_ai_agent' => InstalledVersions::getPrettyVersion('symfony/ai-agent'),
        'symfony_ai_mcp_tool' => InstalledVersions::getPrettyVersion('symfony/ai-mcp-tool'),
        'mcp_sdk' => InstalledVersions::getPrettyVersion('mcp/sdk'),
    ],
    'model_calls' => 0,
    'scope' => 'adapter and MCP tool layer only; no model invocation, not an E2 run',
    'tools' => array_column($tools, 'name'),
    'calls' => ['total' => \count($calls), 'pass' => \count($calls) - \count($failed), 'fail' => \count($failed)],
    'transcript' => ['file' => 'transcript.jsonl', 'sha256' => 'sha256:' . hash_file('sha256', $transcriptPath)],
    'notes' => $notes,
];

file_put_contents($outDir . '/manifest.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");

printf("Symfony AI MCP bridge: %s\n", $failed === [] ? 'PASS' : 'FAIL');
printf("tools=%d calls=%d pass=%d fail=%d model_calls=0\n", \count($tools), $manifest['calls']['total'], $manifest['calls']['pass'], $manifest['calls']['fail']);
printf("receipts=%s\n", $outDir);
printf("transcript=%s\n", $manifest['transcript']['sha256']);

if ($failed !== []) {
    foreach ($failed as $entry) {
        printf("FAILED %s args=%s expect=%s observed=%s\n", $entry['tool'], json_encode($entry['arguments'], \JSON_UNESCAPED_SLASHES), $entry['expect'], $entry['observed']);
    }
    exit(1);
}

exit(0);
