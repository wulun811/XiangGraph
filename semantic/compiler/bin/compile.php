<?php

declare(strict_types=1);

require_once \dirname(__DIR__) . '/src/bootstrap.php';

use XiangGraph\G1\ProtocolCompiler;

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

if (\count($positional) < 2) {
    fwrite(\STDERR, "usage: php compile.php <workspace-dir> <change.json> --contract=<contract.json> [--verification=<check.json>] [--out=<plan.json>]\n");
    exit(2);
}

$workspace = $positional[0];
$changePath = $positional[1];
$contractPath = $options['contract'] ?? '';
if ($contractPath === '') {
    fwrite(\STDERR, "--contract is required; the consumer contract is intentionally outside the source snapshot\n");
    exit(2);
}

$change = json_decode((string) file_get_contents($changePath), true, 512, \JSON_THROW_ON_ERROR);
$contract = json_decode((string) file_get_contents($contractPath), true, 512, \JSON_THROW_ON_ERROR);
$change['_manifest_path'] = realpath($changePath) ?: $changePath;

$verification = null;
if (isset($options['verification'])) {
    $verification = json_decode((string) file_get_contents($options['verification']), true, 512, \JSON_THROW_ON_ERROR);
}

$plan = (new ProtocolCompiler())->compile($workspace, $change, $contract, $verification);
$json = json_encode($plan, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n";

if (isset($options['out'])) {
    file_put_contents($options['out'], $json);
} else {
    echo $json;
}
