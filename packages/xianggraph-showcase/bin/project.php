<?php

declare(strict_types=1);

/**
 * Generate the Observatory projection from sealed Demo-01 runs.
 *
 * Usage:
 *   php packages/xianggraph-showcase/bin/project.php
 *   php packages/xianggraph-showcase/bin/project.php --out=showcase/v2 --pair=p5-pair-01
 *
 * Writes:
 *   <out>/projection.json   machine-readable projection
 *   <out>/projection.js     same document as window.XG_PROJECTION (file:// friendly)
 *   <out>/manifest.json     per-node evidence index with source hashes
 *
 * Read-only with respect to experiments/: it never writes into a sealed run.
 * Exits non-zero when any event chain does not reconcile to MATCH.
 */

use XiangGraph\Showcase\EvidenceProjector;

$root = dirname(__DIR__, 3);
$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // Standalone review subpackages may omit composer's vendor/ tree; map only the
    // two namespaces the projector needs. `composer install` remains the canonical path.
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefixes = [
            'XiangGraph\\Showcase\\' => $root . '/packages/xianggraph-showcase/src/',
            'XiangGraph\\Mcp\\' => $root . '/packages/xianggraph-mcp/src/',
        ];
        foreach ($prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}

$options = getopt('', ['out::', 'pair::', 'extra-pairs::', 'runs::']);
$out = is_string($options['out'] ?? null) ? $options['out'] : $root . '/showcase/v2';
$pair = is_string($options['pair'] ?? null) ? $options['pair'] : '';
$extraOption = is_string($options['extra-pairs'] ?? null) ? $options['extra-pairs'] : '';
$extraPairs = array_values(array_filter(array_map('trim', explode(',', $extraOption))));
$runsOption = is_string($options['runs'] ?? null) ? $options['runs'] : 'p3-real-03,p4-real-01,p6-pair-01r3-xg';

$runs = array_values(array_filter(array_map('trim', explode(',', $runsOption))));
if ($runs === []) {
    fwrite(STDERR, "no runs given\n");
    exit(2);
}

$runsRoot = $root . '/experiments/demo01/runs';
$projector = new EvidenceProjector($runsRoot);

$projection = $projector->project($runs, $pair === '' ? null : $pair, $extraPairs);

$failures = [];
foreach ($projection['scenarios'] as $scenario) {
    if (($scenario['chain']['status'] ?? '') !== 'MATCH') {
        $failures[] = $scenario['run_id'] . ' chain=' . ($scenario['chain']['status'] ?? 'UNKNOWN');
    }
}
if ($failures !== []) {
    fwrite(STDERR, "hash chain mismatch: " . implode(', ', $failures) . "\n");
    exit(1);
}

if (!is_dir($out) && !mkdir($out, 0775, true) && !is_dir($out)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(1);
}

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
$json = json_encode($projection, $jsonFlags) . "\n";
file_put_contents($out . '/projection.json', $json);
file_put_contents($out . '/projection.js', 'window.XG_PROJECTION = ' . json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . ";\n");

$manifest = [
    'schema' => 'xg-showcase-evidence-manifest/v0.1',
    'generated_at_utc' => $projection['generated_at_utc'],
    'source_commit' => $projection['source_commit'],
    'semantic_sha256' => $projection['semantic_sha256'],
    'runs' => [],
    'nodes' => [],
    'compare' => null,
];

foreach ($projection['scenarios'] as $scenario) {
    $manifest['runs'][$scenario['run_id']] = [
        'chain_status' => $scenario['chain']['status'],
        'head_hash' => $scenario['chain']['head_hash'],
        'event_count' => $scenario['chain']['event_count'],
        'claim_level' => $scenario['claim_level'],
        'sources' => $scenario['sources'],
        'not_evidenced' => $scenario['not_evidenced'],
    ];
    foreach ($scenario['nodes'] as $node) {
        $manifest['nodes'][] = [
            'node_id' => $node['node_id'],
            'run_id' => $scenario['run_id'],
            'event_ref' => $node['event_ref'],
            'event_seq' => $node['event_seq'],
            'action' => $node['action'],
            'actor' => $node['actor'],
            'source_file' => $node['source_file'],
        ];
    }
}

if (is_array($projection['compare'])) {
    $manifest['compare'] = [
        'pair_id' => $projection['compare']['id'],
        'status' => $projection['compare']['status'],
        'sources' => $projection['compare']['sources'],
    ];
}

file_put_contents($out . '/manifest.json', json_encode($manifest, $jsonFlags) . "\n");

printf("projection=%s\n", $out . '/projection.json');
printf("manifest=%s\n", $out . '/manifest.json');
printf("semantic_sha256=%s\n", $projection['semantic_sha256']);
foreach ($projection['scenarios'] as $scenario) {
    printf(
        "%-18s chain=%-7s nodes=%-3d edges=%-3d stages=%d agents=%d\n",
        $scenario['run_id'],
        $scenario['chain']['status'],
        count($scenario['nodes']),
        count($scenario['edges']),
        count($scenario['stages']),
        count(array_filter($scenario['lanes'], static fn (array $l): bool => str_starts_with($l['id'], 'agent:'))),
    );
}
if (is_array($projection['compare'])) {
    printf("compare=%s status=%s\n", $projection['compare']['id'], $projection['compare']['status']);
}
foreach ($projection['compare_extra'] ?? [] as $extra) {
    printf(
        "pair=%s xg_calls=%d b3_calls=%d xg_cost=%.6f b3_cost=%.6f\n",
        $extra['id'],
        $extra['xg']['calls'],
        $extra['b3']['calls'],
        $extra['xg']['cost_usd_estimate'],
        $extra['b3']['cost_usd_estimate'],
    );
}
printf("files=%s projection.json projection.js manifest.json\n", $out);

exit(0);
