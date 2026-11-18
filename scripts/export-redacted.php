<?php

declare(strict_types=1);

use XiangGraph\Mcp\Ledger\Chain;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__) . '/experiments/demo01/runs';
$runs = ['p3-real-03', 'p4-real-01', 'p6-pair-01r3-xg'];
$hostPrefix = $argv[1] ?? '';
if ($hostPrefix === '' || !str_ends_with($hostPrefix, '/')) {
    throw new InvalidArgumentException('Pass the original absolute host prefix ending with /');
}
$replace = static function (mixed $value) use (&$replace, $hostPrefix): mixed {
    if (is_array($value)) {
        return array_map($replace, $value);
    }
    if (is_string($value)) {
        return str_replace($hostPrefix, '[redacted-host]/xianggraph/', $value);
    }
    return $value;
};
$heads = [];
foreach ($runs as $run) {
    $dir = $root . '/' . $run;
    $path = $dir . '/events.json';
    $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $oldHead = $document['head_hash'] ?? Chain::verify($document['events'])['head_hash'];
    $before = Chain::verify($document['events']);
    if (!$before['valid']) {
        throw new RuntimeException('Input event chain invalid: ' . $run);
    }
    $document = $replace($document);
    $previous = '';
    foreach ($document['events'] as &$event) {
        $event['previous_hash'] = $previous;
        $event['event_hash'] = Chain::hash($event, $previous);
        $previous = $event['event_hash'];
    }
    unset($event);
    if (array_key_exists('head_hash', $document)) {
        $document['head_hash'] = $previous;
    }
    file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    $heads[$run] = ['original_head' => $oldHead, 'public_head' => $previous];
    $chainPath = $dir . '/event-chain.json';
    $chain = json_decode(file_get_contents($chainPath), true, 512, JSON_THROW_ON_ERROR);
    $chain['head_hash'] = $previous;
    $chain['valid'] = true;
    $chain['errors'] = [];
    file_put_contents($chainPath, json_encode($chain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $filePath = $file->getPathname();
        if (!$file->isFile() || $filePath === $path || $filePath === $chainPath || $file->getExtension() !== 'json') {
            continue;
        }
        $raw = file_get_contents($filePath);
        $updated = str_replace([$hostPrefix, $oldHead], ['[redacted-host]/xianggraph/', $previous], $raw);
        if ($updated !== $raw) {
            file_put_contents($filePath, $updated);
        }
    }
    if (!Chain::verify($document['events'])['valid']) {
        throw new RuntimeException('Public event chain invalid: ' . $run);
    }
}
file_put_contents(dirname(__DIR__) . '/experiments/demo01/public-redaction-heads.json', json_encode($heads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "Redacted public copies and rebuilt event chains.\n";
