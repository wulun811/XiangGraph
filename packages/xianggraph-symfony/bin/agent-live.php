<?php

declare(strict_types=1);

/**
 * Live Symfony AI agent over the XiangGraph ledger MCP server.
 *
 * This is the only script in the G3 slice that spends money: it calls a real
 * model. It refuses to start without explicit configuration and writes a
 * receipt directory containing the prompt, tool calls, answer and usage.
 *
 * Required environment:
 *   --provider=openai  (default) OPENAI_API_KEY
 *   --provider=generic XG_MODEL_BASE_URL + XG_MODEL_API_KEY (OpenAI-compatible /v1/chat/completions)
 *
 * Usage:
 *   OPENAI_API_KEY=... php packages/xianggraph-symfony/bin/agent-live.php \
 *     --model=gpt-4o-mini --run=p3-real-03 --scope=W1
 */

use Composer\InstalledVersions;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use XiangGraph\SymfonyBridge\Ai\McpLedgerToolbox;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$options = getopt('', ['run::', 'runs-root::', 'out::', 'scope::', 'model::', 'prompt::', 'max-tool-calls::', 'max-tokens::', 'provider::']);
$provider = is_string($options['provider'] ?? null) ? $options['provider'] : 'openai';
$runId = is_string($options['run'] ?? null) ? $options['run'] : 'p3-real-03';
$scope = is_string($options['scope'] ?? null) ? $options['scope'] : 'W1';
$model = is_string($options['model'] ?? null) ? $options['model'] : (string) getenv('XG_MODEL_NAME');
$maxToolCalls = is_string($options['max-tool-calls'] ?? null) ? (int) $options['max-tool-calls'] : 8;
$maxTokens = is_string($options['max-tokens'] ?? null) ? (int) $options['max-tokens'] : 800;
$runsRoot = is_string($options['runs-root'] ?? null) ? $options['runs-root'] : $root . '/experiments/demo01/runs';
$outDir = is_string($options['out'] ?? null)
    ? $options['out']
    : $root . '/experiments/real_model_runs/' . gmdate('Ymd\THis\Z') . '-mcp-agent';

$apiKey = $provider === 'generic'
    ? (string) (getenv('XG_MODEL_API_KEY') ?: getenv('OPENAI_API_KEY'))
    : (string) getenv('OPENAI_API_KEY');
if ($apiKey === '') {
    fwrite(STDERR, "BLOCKED_BY_MODEL_ACCESS: set the API key for --provider={$provider}; no request was sent.\n");
    exit(2);
}
if ($model === '') {
    fwrite(STDERR, "model is required: pass --model=... or set XG_MODEL_NAME; no request was sent.\n");
    exit(2);
}

$prompt = is_string($options['prompt'] ?? null)
    ? $options['prompt']
    : sprintf(
        'You are auditing a recorded multi-agent run. Use the xianggraph tools, not memory. '
        . 'Call xg_reconcile for run %s and report whether the chain matches. '
        . 'Then call xg_observe_goal and xg_explain_block for scope %s. '
        . 'Report only what the tool results show; if a tool returns NO_BLOCK_RECORDED, say so. '
        . 'End with one line: VERDICT=<MATCH|MISMATCH|UNKNOWN> BLOCKED=<yes|no>.',
        $runId,
        $scope,
    );

$events = new EventDispatcher();
$toolCalls = [];
$modelCalls = [];
$events->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$toolCalls): void {
    $toolCalls[] = ['name' => $event->getToolCall()->getName(), 'arguments' => $event->getToolCall()->getArguments(), 'status' => 'requested'];
});
$events->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use (&$toolCalls): void {
    $toolCalls[] = ['name' => $event->getDefinition()->getName(), 'arguments' => $event->getArguments(), 'status' => 'succeeded'];
});
$events->addListener(ToolCallFailed::class, static function (ToolCallFailed $event) use (&$toolCalls): void {
    $toolCalls[] = ['name' => $event->getDefinition()->getName(), 'arguments' => $event->getArguments(), 'status' => 'failed', 'error' => $event->getException()->getMessage()];
});

if ($provider === 'generic' && (string) getenv('XG_MODEL_BASE_URL') === '') {
    fwrite(STDERR, "--provider=generic needs XG_MODEL_BASE_URL; no request was sent.\n");
    exit(2);
}

$genericBaseUrl = null;
if ($provider === 'generic') {
    $endpoint = rtrim((string) getenv('XG_MODEL_BASE_URL'), '/');
    $completionsPath = '/v1/chat/completions';
    $genericBaseUrl = str_ends_with($endpoint, $completionsPath)
        ? substr($endpoint, 0, -\strlen($completionsPath))
        : $endpoint;
    if ($genericBaseUrl === '' || $genericBaseUrl === false) {
        fwrite(STDERR, "XG_MODEL_BASE_URL must include a host, not only the completions path; no request was sent.\n");
        exit(2);
    }
}

$events->addListener(ResultConvertedEvent::class, static function (ResultConvertedEvent $event) use (&$modelCalls): void {
    $result = $event->getResult();
    $data = $result->getRawResult()?->getData() ?? [];
    $modelCalls[] = [
        'model' => $event->getModel()->getName(),
        'finish_reason' => $result->getMetadata()->get('finish_reason'),
        'usage' => \is_array($data['usage'] ?? null) ? $data['usage'] : null,
    ];
});

$platform = match ($provider) {
    'openai' => new Platform([Factory::createProvider($apiKey, eventDispatcher: $events)]),
    'generic' => GenericFactory::createPlatform(
        $genericBaseUrl,
        $apiKey,
        completionsPath: '/v1/chat/completions',
        eventDispatcher: $events,
        name: 'xianggraph-generic',
    ),
    default => throw new \InvalidArgumentException("unknown provider '{$provider}'; use openai or generic"),
};
$toolbox = McpLedgerToolbox::create(
    $root . '/packages/xianggraph-mcp/bin/mcp-server.php',
    $runsRoot,
    eventDispatcher: $events,
);
$agent = new Agent(
    $platform,
    $model,
    name: 'xianggraph-ledger-agent',
    toolbox: $toolbox,
    maxToolCalls: $maxToolCalls,
);

$startedAt = gmdate('Y-m-d\TH:i:s\Z');
$started = hrtime(true);
$status = 'PASS';
$answer = null;
$failure = null;
$metadata = [];

try {
    $execution = $agent->call($prompt, ['max_tokens' => $maxTokens]);
    $answer = $execution->getContent();
    $metadata = $execution->getMetadata()->all();
} catch (\Throwable $e) {
    $status = 'FAIL';
    $failure = ['class' => $e::class, 'message' => $e->getMessage()];
}

$elapsedMs = round((hrtime(true) - $started) / 1_000_000, 1);

$verdict = null;
if (is_string($answer) && preg_match('/VERDICT=(MATCH|MISMATCH|UNKNOWN)\s+BLOCKED=(yes|no)/', $answer, $m)) {
    $verdict = ['verdict' => $m[1], 'blocked' => $m[2]];
}
if ($failure === null && $verdict === null) {
    $status = 'INCOMPLETE';
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create output directory: {$outDir}\n");
    exit(1);
}

$receipt = [
    'schema' => 'xg-real-model-mcp-agent/v0.1',
    'status' => $status,
    'run_id' => $runId,
    'scope' => $scope,
    'started_at_utc' => $startedAt,
    'elapsed_ms' => $elapsedMs,
    'provider' => $provider,
    'model' => $model,
    'temperature' => null,
    'max_tokens' => $maxTokens,
    'max_tool_calls' => $maxToolCalls,
    'prompt' => $prompt,
    'tool_calls' => $toolCalls,
    'model_calls' => $modelCalls,
    'answer' => $answer,
    'verdict' => $verdict,
    'metadata' => $metadata,
    'failure' => $failure,
    'environment' => [
        'php' => PHP_VERSION,
        'symfony_ai_agent' => InstalledVersions::getPrettyVersion('symfony/ai-agent'),
        'symfony_ai_mcp_tool' => InstalledVersions::getPrettyVersion('symfony/ai-mcp-tool'),
        'mcp_sdk' => InstalledVersions::getPrettyVersion('mcp/sdk'),
    ],
    'notes' => [
        'live model call; see metadata for provider-reported usage',
        'the API key is never written to this receipt',
    ],
];

file_put_contents($outDir . '/receipt.json', json_encode($receipt, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");

printf("live agent: %s\n", $status);
printf("model=%s run=%s tool_calls=%d elapsed_ms=%s\n", $model, $runId, \count(array_filter($toolCalls, static fn (array $call): bool => $call['status'] === 'succeeded')), $elapsedMs);
printf("receipt=%s\n", $outDir . '/receipt.json');
if ($failure !== null) {
    printf("failure=%s: %s\n", $failure['class'], $failure['message']);
    exit(1);
}

if ($verdict === null) {
    fwrite(STDERR, "answer did not contain the required verdict line; receipt kept for review\n");
    exit(1);
}
printf("verdict=%s blocked=%s\n", $verdict['verdict'], $verdict['blocked']);

exit(0);
