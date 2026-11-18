<?php

declare(strict_types=1);

/**
 * Real MCP stdio roundtrip probe.
 *
 * Usage:
 *   php packages/xianggraph-mcp/bin/mcp-roundtrip.php --run=p3-real-03 --expect-block=W1
 *
 * Options:
 *   --run=<id>          run directory to probe (default p3-real-03)
 *   --runs-root=<path>  evidence root (default experiments/demo01/runs)
 *   --out=<path>        receipt directory (default experiments/mcp_roundtrips/<utc>-<run>)
 *   --expect-block=<s>  require explain_block(scope) to report blocked=true
 *   --no-tamper         skip the tampered-copy reconcile check
 */

use XiangGraph\Mcp\Client\RoundtripProbe;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$options = getopt('', ['run::', 'runs-root::', 'out::', 'expect-block::', 'no-tamper']);
$runId = is_string($options['run'] ?? null) ? $options['run'] : 'p3-real-03';
$runsRoot = is_string($options['runs-root'] ?? null) ? $options['runs-root'] : $root . '/experiments/demo01/runs';
$expectBlock = is_string($options['expect-block'] ?? null) ? $options['expect-block'] : null;
$outDir = is_string($options['out'] ?? null)
    ? $options['out']
    : $root . '/experiments/mcp_roundtrips/' . gmdate('Ymd\THis\Z') . '-' . $runId;

$probe = new RoundtripProbe($root, $outDir, !isset($options['no-tamper']));
$manifest = $probe->run($runId, $runsRoot, $expectBlock);

printf("MCP roundtrip: %s\n", $manifest['calls']['fail'] === 0 ? 'PASS' : 'FAIL');
printf("run=%s calls=%d pass=%d fail=%d refusals=%d\n",
    $manifest['run_id'],
    $manifest['calls']['total'],
    $manifest['calls']['pass'],
    $manifest['calls']['fail'],
    $manifest['calls']['expected_refusals'],
);
printf("server=%s %s protocol=%s\n", $manifest['server']['name'], $manifest['server']['version'], $manifest['server']['protocol']);
printf("receipts=%s\n", $outDir);
printf("transcript=%s\n", $manifest['transcript']['sha256']);

if ($manifest['failed_calls'] ?? null) {
    foreach ($manifest['failed_calls'] as $failed) {
        printf("FAILED %s args=%s expect=%s observed=%s\n",
            $failed['tool'] ?? '?',
            json_encode($failed['arguments'] ?? [], \JSON_UNESCAPED_SLASHES),
            $failed['expect'] ?? '?',
            $failed['observed'] ?? '?',
        );
    }
    exit(1);
}

exit(0);
