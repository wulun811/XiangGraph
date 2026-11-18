<?php

declare(strict_types=1);

/**
 * Real model -> structured coordination plan -> existing validator.
 *
 * The model is asked for a plan JSON only. The script extracts the first JSON
 * object, validates it with XiangGraph\Coord\ProtocolContract, and gives the
 * model at most one repair round with the validation errors. Every attempt and
 * its provider-reported usage is kept in the receipt; a plan is only "PASS"
 * when the existing validator accepts it and Plan can hash it.
 */

use Composer\InstalledVersions;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Event\ResultConvertedEvent;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use XiangGraph\Coord\Plan;
use XiangGraph\Coord\ProtocolContract;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$options = getopt('', ['provider::', 'model::', 'goal::', 'goal-file::', 'out::', 'max-tokens::', 'max-attempts::']);
$provider = is_string($options['provider'] ?? null) ? $options['provider'] : 'generic';
$model = is_string($options['model'] ?? null) ? $options['model'] : (string) getenv('XG_MODEL_NAME');
$maxTokens = is_string($options['max-tokens'] ?? null) ? (int) $options['max-tokens'] : 4000;
$maxAttempts = is_string($options['max-attempts'] ?? null) ? max(1, min(3, (int) $options['max-attempts'])) : 2;
$goalFile = is_string($options['goal-file'] ?? null) ? $options['goal-file'] : null;
$goal = is_string($options['goal'] ?? null) ? $options['goal'] : null;
if ($goal === null && $goalFile !== null && is_file($goalFile)) {
    $goal = trim((string) file_get_contents($goalFile));
}
if ($goal === null || $goal === '') {
    fwrite(STDERR, "provide --goal or --goal-file; no request was sent.\n");
    exit(2);
}

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

$outDir = is_string($options['out'] ?? null)
    ? $options['out']
    : $root . '/experiments/real_model_graphs/' . gmdate('Ymd\THis\Z') . '-graph';

$events = new EventDispatcher();
$modelCalls = [];
$events->addListener(ResultConvertedEvent::class, static function (ResultConvertedEvent $event) use (&$modelCalls): void {
    $result = $event->getResult();
    $data = $result->getRawResult()?->getData() ?? [];
    $modelCalls[] = [
        'model' => $event->getModel()->getName(),
        'finish_reason' => $result->getMetadata()->get('finish_reason'),
        'usage' => \is_array($data['usage'] ?? null) ? $data['usage'] : null,
    ];
});

if ($provider === 'generic') {
    $endpoint = rtrim((string) getenv('XG_MODEL_BASE_URL'), '/');
    $completionsPath = '/v1/chat/completions';
    $baseUrl = str_ends_with($endpoint, $completionsPath)
        ? substr($endpoint, 0, -\strlen($completionsPath))
        : $endpoint;
    if ($baseUrl === '' || $baseUrl === false) {
        fwrite(STDERR, "--provider=generic needs XG_MODEL_BASE_URL; no request was sent.\n");
        exit(2);
    }
    $platform = GenericFactory::createPlatform(
        $baseUrl,
        $apiKey,
        completionsPath: $completionsPath,
        eventDispatcher: $events,
        name: 'xianggraph-generic',
    );
} elseif ($provider === 'openai') {
    $platform = new Platform([Factory::createProvider($apiKey, eventDispatcher: $events)]);
} else {
    fwrite(STDERR, "unknown provider '{$provider}'; use openai or generic\n");
    exit(2);
}

$agent = new Agent($platform, $model, name: 'xianggraph-graph-author');

$schemaHint = <<<'TEXT'
Return exactly one JSON object, no markdown fence, no reasoning and no prose.
Keep the whole JSON under 900 tokens: short strings, at most two items in
resources and outputs, exactly three works. Shape:

{
  "schema_version": "xg-coordination-plan/v0.1",
  "plan_id": "<slug>-plan",
  "policy_revision": 1,
  "goal": "<the goal, restated in one sentence>",
  "works": [
    {
      "id": "W1",
      "title": "<short title>",
      "owners": ["<agent-role>"],
      "depends_on": [],
      "resources": ["<path or contract>"],
      "outputs": ["<path or artifact>"]
    }
  ],
  "protocol_composition": [
    {"protocol": "P-DEP", "version": "v0.1", "scope": ["W1", "W2"]}
  ],
  "authority": {"director": "director", "human_authorizer": "human:owner"},
  "risk_boundary": {
    "external_money_transfer": "PROHIBITED",
    "production_write": "PROHIBITED",
    "unknown_effect_retry": "REQUIRE_IDEMPOTENCY_KEY",
    "max_negotiation_rounds": 2,
    "director_unavailable_default": "STOP",
    "human_only_options": []
  },
  "escalation_policy": {
    "director_only_when": ["<reason>"],
    "do_not_escalate_for": ["<ordinary progress>"],
    "deduplicate_by": ["<key>"],
    "response_requires": ["<field>"],
    "response_must_pass": ["<check>"]
  }
}

Rules enforced by the validator:
- 3 to 4 works, unique ids, every work has at least one owner, dependencies form a DAG.
- protocol_composition is non-empty; each entry uses one of P-DEP, P-CONFLICT, P-REVIEW, P-RECOVER, P-ESCALATE and has a version and a non-empty scope of existing work ids.
- P-CONFLICT may include "max_rounds" (integer); risk_boundary.max_negotiation_rounds also satisfies it.
- external_money_transfer and production_write must be exactly "PROHIBITED".
- authority.director and authority.human_authorizer are required strings.
- escalation_policy keys director_only_when, do_not_escalate_for, deduplicate_by, response_requires, response_must_pass must all be lists.
- ORDINARY_PROGRESS must not appear in director_only_when.
TEXT;

$basePrompt = "You are the Director in a multi-agent coding system. Produce a coordination plan for this goal:\n\n"
    . $goal . "\n\n"
    . "Use only the protocols listed and make the scope boundaries explicit. "
    . "Do not invent authority fields beyond the schema. Do not add commentary.\n\n"
    . $schemaHint;

/**
 * @return array<string, mixed>|null
 */
function extract_json_object(string $text): ?array
{
    $text = trim($text);
    if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $match)) {
        $text = $match[1];
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

    return \is_array($decoded) ? $decoded : null;
}

$attempts = [];
$finalPlan = null;
$finalHash = null;
$status = 'FAIL';
$prompt = $basePrompt;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    $startedAt = gmdate('Y-m-d\TH:i:s\Z');
    $started = hrtime(true);
    $answer = null;
    $error = null;
    try {
        $execution = $agent->call($prompt, ['max_tokens' => $maxTokens]);
        $answer = $execution->getContent();
    } catch (\Throwable $e) {
        $error = ['class' => $e::class, 'message' => $e->getMessage()];
    }
    $elapsedMs = round((hrtime(true) - $started) / 1_000_000, 1);
    $plan = \is_string($answer) ? extract_json_object($answer) : null;
    $validation = $plan === null
        ? ['valid' => false, 'errors' => ['no JSON object found in the answer']]
        : ProtocolContract::validate($plan);
    $planHash = null;

    if ($validation['valid'] && $plan !== null) {
        try {
            $planObj = new Plan($plan);
            $planHash = 'sha256:' . $planObj->hash;
            $finalPlan = $plan;
            $finalHash = $planHash;
            $status = 'PASS';
        } catch (\Throwable $e) {
            $validation = ['valid' => false, 'errors' => ['Plan rejected: ' . $e->getMessage()]];
        }
    }

    $attempts[] = [
        'attempt' => $attempt,
        'started_at_utc' => $startedAt,
        'elapsed_ms' => $elapsedMs,
        'prompt' => $prompt,
        'answer' => $answer,
        'json' => $plan,
        'validation' => $validation,
        'plan_hash' => $planHash,
        'usage' => array_pop($modelCalls),
        'error' => $error,
    ];

    if ($status === 'PASS' || $attempt === $maxAttempts) {
        break;
    }

    $prompt = "The JSON you returned was rejected by the existing validator:\n- "
        . implode("\n- ", $validation['errors'])
        . "\n\nReturn a corrected complete JSON object only. Keep the same goal:\n\n"
        . $goal . "\n\n" . $schemaHint;
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create output directory: {$outDir}\n");
    exit(1);
}

$receipt = [
    'schema' => 'xg-real-model-graph/v0.1',
    'status' => $status,
    'goal' => $goal,
    'provider' => $provider,
    'model' => $model,
    'max_tokens' => $maxTokens,
    'max_attempts' => $maxAttempts,
    'final_plan' => $finalPlan,
    'final_plan_hash' => $finalHash,
    'attempts' => $attempts,
    'environment' => [
        'php' => PHP_VERSION,
        'symfony_ai_agent' => InstalledVersions::getPrettyVersion('symfony/ai-agent'),
        'symfony_ai_platform' => InstalledVersions::getPrettyVersion('symfony/ai-platform'),
    ],
    'notes' => [
        'validation is XiangGraph\\Coord\\ProtocolContract::validate, not a new checker',
        'only a validator-accepted plan is labelled PASS; failures keep the raw answer',
        'the API key is never written to this receipt',
    ],
];

file_put_contents($outDir . '/receipt.json', json_encode($receipt, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");

printf("graph task: %s\n", $status);
printf("model=%s attempts=%d plan_hash=%s\n", $model, \count($attempts), $finalHash ?? '(none)');
printf("receipt=%s\n", $outDir . '/receipt.json');

if ($status !== 'PASS') {
    $last = $attempts[\count($attempts) - 1];
    printf("last_errors=%s\n", json_encode($last['validation']['errors'] ?? [], \JSON_UNESCAPED_SLASHES));
    exit(1);
}

exit(0);
