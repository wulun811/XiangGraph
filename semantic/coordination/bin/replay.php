<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use XiangGraph\Coord\Replay;

$args = $argv;
array_shift($args);
$positional = [];
$options = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = explode('=', substr($arg, 2), 2) + [1 => '1'];
        $options[$key] = $value;
        continue;
    }
    $positional[] = $arg;
}

if ($positional === []) {
    fwrite(\STDERR, "usage: php replay.php <scenario.json> --plan=<plan.json> [--out=<trace.json>] [--run-id=<id>]\n");
    exit(2);
}

$scenarioPath = $positional[0];
$planPath = $options['plan'] ?? dirname(__DIR__) . '/plans/partial-refund.v0.1.json';
$runId = $options['run-id'] ?? 'COORD-R0-REPLAY-20261113-01';

try {
    $plan = Replay::loadPlan($planPath);
    $scenario = Replay::loadScenario($scenarioPath);
    $trace = (new Replay())->run($plan, $scenario, $runId);
} catch (\Throwable $error) {
    fwrite(\STDERR, 'ERROR: ' . $error->getMessage() . "\n");
    exit(2);
}

$json = json_encode($trace, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
if (isset($options['out'])) {
    file_put_contents($options['out'], $json);
} else {
    echo $json;
}

fwrite(\STDERR, sprintf(
    "scenario=%s mode=%s decisions(wakeups=%d, dedup=%d, stale_rejected=%d, unsafe_allows=%d) publication_committed=false\n",
    $trace['scenario_id'],
    $trace['mode'],
    $trace['metrics']['director_wakeups'],
    $trace['metrics']['deduplicated_requests'],
    $trace['metrics']['stale_responses_rejected'],
    $trace['metrics']['unsafe_allows']
));

exit(($trace['invalid_events'] === [] && $trace['metrics']['unsafe_allows'] === 0) ? 0 : 1);
