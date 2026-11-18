<?php

declare(strict_types=1);

require_once \dirname(__DIR__) . '/src/bootstrap.php';

use XiangGraph\G1\FileTree;
use XiangGraph\G1\ProtocolCompiler;
use XiangGraph\G1\SourceFacts;

$root = \dirname(__DIR__);
$workspace = $root . '/workspaces/symfony-routing-glob';
$contract = json_decode((string) file_get_contents($root . '/contracts/symfony-routing-glob.json'), true, 512, \JSON_THROW_ON_ERROR);
$compiler = new ProtocolCompiler();
$passed = 0;
$failed = 0;

function check(string $label, bool $condition, mixed $detail = null): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo 'PASS ' . $label . "\n";
        return;
    }
    ++$failed;
    echo 'FAIL ' . $label;
    if ($detail !== null) {
        echo ' :: ' . json_encode($detail, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
    echo "\n";
}

function compileChange(string $workspace, string $root, array $contract, string $changeId, ?array $verification = null): array
{
    global $compiler;
    $path = $root . '/changes/' . $changeId . '/change.json';
    $change = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    $change['_manifest_path'] = $path;

    return $compiler->compile($workspace, $change, $contract, $verification);
}

function containsKeyRecursive(array $value, string $key): bool
{
    foreach ($value as $k => $v) {
        if ($k === $key) {
            return true;
        }
        if (\is_array($v) && containsKeyRecursive($v, $key)) {
            return true;
        }
    }

    return false;
}

// 1. Source facts are extracted from the controlled snapshot.
$files = FileTree::read($workspace);
$facts = SourceFacts::scan($files, $contract, 'test');
check('extracts exactly two literal imports', \count($facts['imports']) === 2, $facts['imports']);
$routingImport = null;
$diImport = null;
foreach ($facts['imports'] as $import) {
    if ($import['kind'] === 'routing_import') {
        $routingImport = $import;
    }
    if ($import['kind'] === 'di_import') {
        $diImport = $import;
    }
}
check('routing import comes from config/routes.php line 8', $routingImport !== null && $routingImport['source_file'] === 'config/routes.php' && $routingImport['source_line'] === 8, $routingImport);
check('routing glob is config/routes/**/*.yaml', $routingImport['resolved_glob'] === 'config/routes/**/*.yaml', $routingImport);
check('extracted pattern carries a source version', str_starts_with((string) ($routingImport['version'] ?? ''), 'v1:'), $routingImport['version'] ?? null);
check('routing import observes api.yaml only', $routingImport['observed_matches'] === ['config/routes/api.yaml'], $routingImport);
check('DI import comes from config/services.yaml', $diImport !== null && $diImport['source_file'] === 'config/services.yaml', $diImport);
check('DI import observes mailer.yaml', $diImport['observed_matches'] === ['config/services/mailer.yaml'], $diImport);
check('service ids are extracted', $facts['services'] === ['App\\Kernel', 'App\\Mailer'], $facts['services']);
check('route names are extracted', $facts['routes'] === ['api_home'], $facts['routes']);
check('no unsupported syntax in the base snapshot', $facts['unsupported'] === [], $facts['unsupported']);

// 2. A new file that matches the extracted glob becomes a new relation.
$add = compileChange($workspace, $root, $contract, '01-add-route-file');
$protocol = $add['engine_result']['protocol'];
check('new route file yields WAIT before verification', $add['decision'] === 'WAIT', $add['decision']);
check('new route file is newly matched', (bool) ($add['engine_result']['pattern_matches'][0]['newly_matched'] ?? false), $add['engine_result']['pattern_matches']);
check('new route file creates routing obligations', array_column($add['generated_obligations'], 'invariant') === ['inv.routing-index', 'inv.routing-cache'], $add['generated_obligations']);
check('protocol pattern keeps its source version', str_starts_with((string) ($add['engine_result']['protocol']['unresolved_dependency_scope']['tracked_patterns'][0]['version'] ?? ''), 'v1:'), $add['engine_result']['protocol']['unresolved_dependency_scope']);
check('new route file invalidates routing evidence', array_column($add['invalidated_evidence'], 'obligation') === ['inv.routing-index', 'inv.routing-cache'], $add['invalidated_evidence']);
check('new route file waits for routing nodes', \in_array('T02', $protocol['must_wait_for'], true) && \in_array('T03', $protocol['must_wait_for'], true), $protocol['must_wait_for']);
check('docs node can run concurrently', \in_array('T04', $protocol['can_run_concurrently'], true), $protocol['can_run_concurrently']);
check('current snapshot not eligible before verification', $add['current_snapshot_eligible'] === false);
check('future tracking is declared but not enforced', $add['future_change_tracking_status'] === 'DECLARED_NOT_ENFORCED', $add['future_change_tracking_status']);
check('publication is never committed', $add['publication_committed'] === false);
check('remote exactly-once is not claimed', $add['remote_exactly_once'] === false);

// 3. The extraction, not a hand-written pattern, drives the relation:
// changing the import literal changes the extracted glob.
$zero = compileChange($workspace, $root, $contract, '03-glob-rule-zero-match');
$zeroPattern = null;
foreach ($zero['engine_result']['analysis_scope']['closed'] as $fact) {
    if ($fact === 'symfony.import.config/routes.php#8') {
        $zeroPattern = $fact;
    }
}
check('changed literal is reflected as a zero-match glob', $zero['engine_result']['pattern_matches'] === [], $zero['engine_result']['pattern_matches']);
check('zero-match rule still creates routing obligations', array_column($zero['generated_obligations'], 'invariant') === ['inv.routing-index', 'inv.routing-cache'], $zero['generated_obligations']);
check('zero-match rule still yields WAIT', $zero['decision'] === 'WAIT', $zero['decision']);
check('zero-match rule keeps current snapshot ineligible', $zero['current_snapshot_eligible'] === false);
check('changed import fact is in the closed analysis scope', $zeroPattern !== null);

// 4. A local docs change must not invalidate routing evidence.
$docs = compileChange($workspace, $root, $contract, '02-docs-only');
check('docs-only change yields WAIT for docs', $docs['decision'] === 'WAIT', $docs['decision']);
check('docs-only change does not create routing obligations', !\in_array('inv.routing-index', array_column($docs['generated_obligations'], 'invariant'), true), $docs['generated_obligations']);
check('docs-only change does not invalidate routing evidence', !\in_array('inv.routing-index', array_column($docs['invalidated_evidence'], 'obligation'), true), $docs['invalidated_evidence']);
check('docs-only change invalidates docs evidence', \in_array('inv.docs', array_column($docs['invalidated_evidence'], 'obligation'), true), $docs['invalidated_evidence']);
check('routing nodes can run during docs-only change', \in_array('T03', $docs['concurrency']['can_run_concurrently'], true), $docs['concurrency']);
check('docs work waits', \in_array('T04', $docs['concurrency']['must_wait_for'], true), $docs['concurrency']);

// 5. An unmapped open range must stay UNKNOWN.
$plugin = compileChange($workspace, $root, $contract, '04-plugin-unknown');
$pluginCodes = array_column($plugin['engine_result']['reasons'], 'code');
check('plugin change yields UNKNOWN', $plugin['decision'] === 'UNKNOWN', $plugin['decision']);
check('plugin change reports OPEN_SCOPE_UNPROVEN', \in_array('OPEN_SCOPE_UNPROVEN', $pluginCodes, true), $pluginCodes);
check('plugin change marks unmapped fact', ($plugin['engine_result']['analysis_scope']['unmapped_changed'] ?? []) === ['file:config/plugins/analytics.yaml'], $plugin['engine_result']['analysis_scope']);
check('plugin change has no complete publish protocol', $plugin['current_snapshot_eligible'] === false && $plugin['generated_obligations'] === []);
check('plugin change exposes the unresolved scope', $plugin['unresolved_dependency_scope']['unmapped_changed'] === ['file:config/plugins/analytics.yaml'], $plugin['unresolved_dependency_scope']);
check('plugin precondition is unproven', \in_array('unproven', array_column($plugin['preconditions'], 'status'), true), $plugin['preconditions']);

$src = compileChange($workspace, $root, $contract, '05-unmapped-src');
check('unmapped existing src change yields UNKNOWN', $src['decision'] === 'UNKNOWN', $src['decision']);
check('unmapped src change is recorded', ($src['engine_result']['analysis_scope']['unmapped_changed'] ?? []) === ['file:src/Controller/HomeController.php'], $src['engine_result']['analysis_scope']);
check('source file is excluded from closed analysis scope', !\in_array('file:src/Controller/HomeController.php', $src['engine_result']['analysis_scope']['closed'], true), $src['engine_result']['analysis_scope']['closed']);

// 6. A real local consumer check can discharge obligations for the current
// snapshot, but cannot turn future tracking or publication into a fact.
$checkPath = sys_get_temp_dir() . '/xg-g1-check-' . getmypid() . '.json';
$command = sprintf(
    'php %s %s %s %s > %s',
    escapeshellarg($root . '/consumer/check_routes.php'),
    escapeshellarg($workspace),
    escapeshellarg($root . '/changes/01-add-route-file/change.json'),
    escapeshellarg($root . '/contracts/symfony-routing-glob.json'),
    escapeshellarg($checkPath)
);
exec($command, $output, $exitCode);
$verification = json_decode((string) file_get_contents($checkPath), true, 512, \JSON_THROW_ON_ERROR);
@unlink($checkPath);
check('local consumer check passes', $exitCode === 0 && $verification['result'] === 'pass', ['exit' => $exitCode, 'verification' => $verification]);
$verified = compileChange($workspace, $root, $contract, '01-add-route-file', $verification);
check('verified current snapshot is eligible', $verified['decision'] === 'ALLOW' && $verified['current_snapshot_eligible'] === true, $verified['decision']);
check('verified plan has no open obligations', $verified['generated_obligations'] === [], $verified['generated_obligations']);
check('verified plan still declares future tracking unenforced', $verified['future_change_tracking_status'] === 'DECLARED_NOT_ENFORCED', $verified['future_change_tracking_status']);
check('verified plan never commits publication', $verified['publication_committed'] === false);
check('verified plan never claims remote exactly-once', $verified['remote_exactly_once'] === false);
check('unverified plan keeps a residual open-world unknown', \in_array('RESIDUAL_OPEN_WORLD', array_column($add['unknowns'], 'code'), true), $add['unknowns']);
check('verified plan still records the residual open-world unknown', \in_array('RESIDUAL_OPEN_WORLD', array_column($verified['unknowns'], 'code'), true), $verified['unknowns']);

// 7. Provenance and derivation chain are explicit.
$kinds = array_column($add['derivation_trace'], 'kind');
check('derivation trace starts with a source fact', ($kinds[0] ?? null) === 'source_fact', $kinds);
check('derivation trace contains pattern registration', \in_array('pattern', $kinds, true), $kinds);
check('derivation trace contains an impact edge', \in_array('impact_edge', $kinds, true), $kinds);
check('derivation trace contains an obligation', \in_array('obligation', $kinds, true), $kinds);
check('derivation trace contains an action constraint', \in_array('action_constraint', $kinds, true), $kinds);
$sourceFact = $add['derivation_trace'][0];
check('source fact points to config/routes.php:8', $sourceFact['file'] === 'config/routes.php' && $sourceFact['line'] === 8, $sourceFact);
check('source fact records the extracted literal', $sourceFact['value'] === 'routes/**/*.yaml', $sourceFact);

// 8. Unsupported syntax is reported, never guessed.
$virtual = [
    'config/routes.php' => "<?php\nreturn function (\$routes) {\n    \$routes->import(__DIR__ . '/routes/*.yaml');\n};\n",
    'config/routes/api.yaml' => "api_home:\n    path: /api\n",
];
$scanned = SourceFacts::scan($virtual, $contract, 'unsupported-test');
check('concatenated import is reported unsupported', \count($scanned['unsupported']) === 1, $scanned['unsupported']);
check('concatenated import produces no pattern', $scanned['patterns'] === [], $scanned['patterns']);

// 9. Real upstream source is read but unresolved placeholders/concatenations
// are refused rather than guessed.
$upstreamLoader = \dirname(__DIR__) . '/upstream/FileLoaderTest.php';
$upstreamRouting = \dirname(__DIR__) . '/upstream/PhpFileLoaderTest.php';
$loaderScan = \XiangGraph\G1\PhpImportScanner::extractImports((string) file_get_contents($upstreamLoader), 'FileLoaderTest.php');
$routingScan = \XiangGraph\G1\PhpImportScanner::extractImports((string) file_get_contents($upstreamRouting), 'PhpFileLoaderTest.php');
check('upstream FileLoaderTest literal is seen at line 68', ($loaderScan['imports'][0]['line'] ?? null) === 68 && ($loaderScan['imports'][0]['resource'] ?? '') === '{F}ixtures/{xml,yaml}/services2.{yml,xml}', $loaderScan);
check('upstream placeholder import is reported unsupported', \count($loaderScan['unsupported']) === 1 && str_contains($loaderScan['unsupported'][0]['snippet'], 'self::$fixturesPath'), $loaderScan['unsupported']);
check('upstream concatenated routing import is not accepted', $routingScan['imports'] === [] && \count($routingScan['unsupported']) === 1, $routingScan);
$upstreamFacts = SourceFacts::scan(['FileLoaderTest.php' => (string) file_get_contents($upstreamLoader)], $contract, 'upstream');
$placeholderReasons = array_values(array_filter($upstreamFacts['unsupported'], static fn (array $item): bool => str_contains((string) $item['reason'], 'placeholder')));
check('upstream placeholder yields no invented pattern', $upstreamFacts['patterns'] === [] && \count($placeholderReasons) === 1, $upstreamFacts['unsupported']);

// 10. Compilation is deterministic and has no publish authority field.
$again = compileChange($workspace, $root, $contract, '01-add-route-file');
check('plan hash is deterministic', $add['plan_hash'] === $again['plan_hash'], [$add['plan_hash'], $again['plan_hash']]);
check('plan contains no can_publish authority field', !containsKeyRecursive($add, 'can_publish'));
check('plan records a scope snapshot with source commit', ($add['scope_snapshot']['source_commit'] ?? '') === 'b82dd4e6f9eef23fe12f5a5512bdb0a70462d1c0', $add['scope_snapshot']);

echo "\n";
if ($failed > 0) {
    echo 'FAILED: ' . $failed . ' check(s) failed, ' . $passed . " passed\n";
    exit(1);
}

echo 'OK: ' . $passed . " G1 checks passed\n";
