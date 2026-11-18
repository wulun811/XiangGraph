<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use XiangGraph\Coord\Replay;

$root = dirname(__DIR__, 3);
$plan = Replay::loadPlan($root . '/semantic/coordination/plans/partial-refund.v0.1.json');
$replay = new Replay();

$normal = $replay->run($plan, Replay::loadScenario($root . '/semantic/coordination/scenarios/K01-K02-schema-scope.json'), 'COORD-R0-DEMO-NORMAL');
$exception = $replay->run($plan, Replay::loadScenario($root . '/semantic/coordination/scenarios/K04-K05-K06-director-loop.json'), 'COORD-R0-DEMO-EXCEPTION');

echo "XG-COORD-R0 stage B demo (simulated events; no model call)\n";
echo "================================================================\n";
printf(
    "normal path : %s | director wakeups=%d | unsafe=%d | committed=%s\n",
    $normal['scenario_id'],
    $normal['metrics']['director_wakeups'],
    $normal['metrics']['unsafe_allows'],
    $normal['publication_committed'] ? 'true' : 'false'
);
printf(
    "exception   : %s | director wakeups=%d | dedup=%d | stale_rejected=%d | patch_scope=%s | committed=%s\n",
    $exception['scenario_id'],
    $exception['metrics']['director_wakeups'],
    $exception['metrics']['deduplicated_requests'],
    $exception['metrics']['stale_responses_rejected'],
    implode(',', $exception['patches'][0]['affected_scope'] ?? []),
    $exception['publication_committed'] ? 'true' : 'false'
);
echo "\nNormal path actions:\n";
foreach ($normal['steps'] as $step) {
    $actions = array_map(static fn (array $action): string => $action['action'], $step['actions']);
    if ($actions !== []) {
        printf("  %2d %-26s %s\n", $step['seq'], $step['event_type'], implode(', ', $actions));
    }
}
echo "\nException path actions:\n";
foreach ($exception['steps'] as $step) {
    $actions = array_map(static fn (array $action): string => $action['action'], $step['actions']);
    if ($actions !== []) {
        printf("  %2d %-26s %s\n", $step['seq'], $step['event_type'], implode(', ', $actions));
    }
}
echo "\nDirector request:\n";
foreach ($exception['director_requests'] as $request) {
    printf(
        "  %s reason=%s scope=%s blocked=%s unblocked=%s options=%s\n",
        $request['request_id'],
        $request['reason'],
        implode(',', $request['scope']),
        implode(',', $request['blocked_scope']),
        implode(',', $request['unblocked_scope']),
        implode(',', array_column($request['options'], 'id'))
    );
}
echo "\nBoundary: simulated events / fixed agent stand-ins; no model, no tool execution, no publication.\n";
