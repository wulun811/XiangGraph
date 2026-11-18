<?php

declare(strict_types=1);

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(\STDERR, "usage: php summarize.php <plan.json> [...]\n");
    exit(2);
}

printf("%-26s %-9s %-9s %-26s %6s %6s %6s\n", 'change', 'decision', 'eligible', 'future_tracking', 'obl', 'wait', 'unk');
foreach ($files as $file) {
    $plan = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
    printf(
        "%-26s %-9s %-9s %-26s %6d %6d %6d\n",
        (string) ($plan['change_id'] ?? basename($file)),
        (string) ($plan['decision'] ?? '?'),
        !empty($plan['current_snapshot_eligible']) ? 'true' : 'false',
        (string) ($plan['future_change_tracking_status'] ?? '?'),
        \count($plan['generated_obligations'] ?? []),
        \count($plan['concurrency']['must_wait_for'] ?? []),
        \count($plan['unknowns'] ?? [])
    );
}
