<?php

declare(strict_types=1);

require_once \dirname(__DIR__) . '/src/bootstrap.php';

use XiangGraph\G1\FileTree;
use XiangGraph\G1\SourceFacts;

$args = $argv;
array_shift($args);
if (\count($args) < 3) {
    fwrite(\STDERR, "usage: php check_routes.php <workspace-dir> <change.json> <contract.json>\n");
    exit(2);
}

[$workspace, $changePath, $contractPath] = $args;
$change = json_decode((string) file_get_contents($changePath), true, 512, \JSON_THROW_ON_ERROR);
$contract = json_decode((string) file_get_contents($contractPath), true, 512, \JSON_THROW_ON_ERROR);

$baseFiles = FileTree::read($workspace);
$overlay = (string) ($change['overlay_dir'] ?? '');
if ($overlay !== '' && !str_starts_with($overlay, '/')) {
    $overlay = \dirname(realpath($changePath) ?: $changePath) . '/' . $overlay;
}
$candidateFiles = FileTree::overlay($baseFiles, $overlay, $change['delete'] ?? []);
$base = SourceFacts::scan($baseFiles, $contract, 'check');
$candidate = SourceFacts::scan($candidateFiles, $contract, 'check');

$expected = array_values($change['expected_routes'] ?? []);
$missing = array_values(array_diff($expected, $candidate['routes']));
$unexpected = array_values(array_diff($candidate['routes'], $expected));
$unsupported = $candidate['unsupported'];
$cacheRebuildRequired = $base['index']['routes'] !== $candidate['index']['routes'];
$ok = $missing === [] && $unexpected === [] && $unsupported === [] && ($expected === [] || $cacheRebuildRequired);

$claims = [];
if ($expected !== [] && $missing === [] && $unexpected === []) {
    $claims[] = 'inv.routing-index';
}
if ($cacheRebuildRequired) {
    $claims[] = 'inv.routing-cache';
}

$result = [
    'result' => $ok ? 'pass' : 'fail',
    'claims' => $ok ? $claims : [],
    'expected_routes' => $expected,
    'actual_routes' => $candidate['routes'],
    'missing_routes' => $missing,
    'unexpected_routes' => $unexpected,
    'cache_rebuild_required' => $cacheRebuildRequired,
    'unsupported' => $unsupported,
    'route_files' => $candidate['route_files'],
    'source' => 'local_consumer_check',
];

echo json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";
exit($ok ? 0 : 1);
