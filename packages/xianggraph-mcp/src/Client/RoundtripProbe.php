<?php

declare(strict_types=1);

namespace XiangGraph\Mcp\Client;

use Composer\InstalledVersions;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * Runs a real MCP client against the XiangGraph ledger server over stdio and
 * records every call, including refused ones, as a receipt transcript.
 *
 * The transcript exists so a reviewer can re-run the probe and compare hashes
 * instead of trusting a screenshot.
 */
final class RoundtripProbe
{
    private const PROBE_VERSION = '0.1.0';

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $outDir,
        private readonly bool $withTamperCheck = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $runId, string $runsRoot, ?string $expectBlock): array
    {
        $serverPath = $this->repoRoot . '/packages/xianggraph-mcp/bin/mcp-server.php';
        $client = $this->connect($serverPath, $runsRoot);

        $serverInfo = $client->getServerInfo();
        $protocol = $client->getProtocolVersion();
        $this->row([
            'kind' => 'initialize',
            'server' => $serverInfo?->name,
            'server_version' => $serverInfo?->version,
            'protocol' => $protocol?->value,
            'instructions' => $client->getInstructions(),
        ]);

        $tools = [];
        foreach ($client->listTools()->tools as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'description' => $tool->description,
                'read_only' => $tool->annotations?->readOnlyHint,
                'input_schema' => $tool->inputSchema,
            ];
        }
        $this->row(['kind' => 'tools/list', 'tool_count' => \count($tools), 'tools' => $tools]);

        $this->call($client, 'list_runs', []);
        $this->call($client, 'observe_goal', ['run_id' => $runId]);
        $this->call($client, 'observe_timeline', ['run_id' => $runId, 'from_seq' => 1, 'limit' => 5], 'ok', static function (mixed $payload): bool {
            return \is_array($payload)
                && ($payload['returned'] ?? null) === 5
                && ($payload['next_seq'] ?? null) === 6;
        });
        $this->call($client, 'read_receipt', ['run_id' => $runId, 'seq' => 1]);
        $this->call($client, 'reconcile', ['run_id' => $runId], 'ok', static function (mixed $payload): bool {
            return \is_array($payload) && ($payload['status'] ?? null) === 'MATCH';
        });

        if ($expectBlock !== null) {
            $this->call($client, 'explain_block', ['run_id' => $runId, 'scope' => $expectBlock], 'ok', static function (mixed $payload) use ($expectBlock): bool {
                return \is_array($payload)
                    && ($payload['scope'] ?? null) === $expectBlock
                    && ($payload['blocked'] ?? null) === true;
            });
            $this->notes[] = 'explain_block(' . $expectBlock . ') was required to report blocked=true';
        }

        // Refusals: the server must reject these before reading outside the root
        // or accepting arguments its schema never declared.
        $this->call($client, 'observe_goal', ['run_id' => '../' . $runId], 'tool_error');
        $this->call($client, 'observe_goal', ['run_id' => 'no-such-run'], 'tool_error');
        $this->call($client, 'explain_block', ['run_id' => $runId, 'scope' => 'W99'], 'tool_error');
        $this->call($client, 'observe_goal', ['run_id' => $runId, 'qualified' => true], 'protocol_error');
        $this->call($client, 'observe_timeline', ['run_id' => $runId, 'limit' => 0], 'protocol_error');

        $client->disconnect();

        if ($this->withTamperCheck) {
            $this->tamperCheck($runId, $runsRoot, $serverPath);
        } else {
            $this->notes[] = 'tamper check skipped by caller';
        }

        return $this->writeReceipts($runId, $runsRoot, $serverInfo?->name, $serverInfo?->version, $protocol?->value);
    }

    private function connect(string $serverPath, string $runsRoot): Client
    {
        $env = getenv();
        $env['XG_MCP_RUNS_ROOT'] = $runsRoot;

        $client = Client::builder()
            ->setClientInfo('xianggraph-roundtrip-probe', self::PROBE_VERSION)
            ->build();
        $client->connect(new StdioTransport(command: PHP_BINARY, args: [$serverPath], env: $env));

        return $client;
    }

    private function tamperCheck(string $runId, string $runsRoot, string $serverPath): void
    {
        $tamperRoot = $this->repoRoot . '/var/mcp-roundtrip-tamper-' . bin2hex(random_bytes(4));
        $runDir = $tamperRoot . '/' . $runId;
        if (!mkdir($runDir, 0775, true) && !is_dir($runDir)) {
            $this->notes[] = 'tamper check skipped: could not create temp root';

            return;
        }

        try {
            $source = $runsRoot . '/' . $runId;
            $document = json_decode((string) file_get_contents($source . '/events.json'), true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($document['events'] ?? null) || \count($document['events']) < 2) {
                $this->notes[] = 'tamper check skipped: run has fewer than two events';

                return;
            }
            $document['events'][1]['payload']['tampered_by_probe'] = true;
            file_put_contents(
                $runDir . '/events.json',
                json_encode($document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
            );
            foreach (['event-chain.json', 'summary.json'] as $name) {
                if (is_file($source . '/' . $name)) {
                    copy($source . '/' . $name, $runDir . '/' . $name);
                }
            }

            $client = $this->connect($serverPath, $tamperRoot);
            $this->call(
                $client,
                'reconcile',
                ['run_id' => $runId],
                'ok',
                static fn (mixed $payload): bool => \is_array($payload) && ($payload['status'] ?? null) === 'MISMATCH',
                'tampered copy must reconcile as MISMATCH',
            );
            $client->disconnect();
        } finally {
            $this->removeTree($tamperRoot);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(
        Client $client,
        string $tool,
        array $arguments,
        string $expect = 'ok',
        ?callable $check = null,
        ?string $checkNote = null,
    ): void {
        $started = hrtime(true);
        $observed = 'ok';
        $payload = null;
        $error = null;
        $passed = false;

        try {
            $result = $client->callTool($tool, $arguments);
            $payload = self::payloadOf($result);
            if ($result->isError) {
                $observed = 'tool_error';
                $error = ['type' => 'tool_error', 'message' => self::firstText($result)];
            }
        } catch (RequestException $e) {
            $observed = 'protocol_error';
            $error = ['type' => 'protocol_error', 'code' => $e->getCode(), 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $observed = 'transport_error';
            $error = ['type' => 'transport_error', 'message' => $e->getMessage()];
        }

        $passed = $observed === $expect;
        if ($passed && $check !== null) {
            $passed = (bool) $check($payload);
        }

        $this->row([
            'kind' => 'tools/call',
            'tool' => $tool,
            'arguments' => $arguments,
            'expect' => $expect,
            'observed' => $observed,
            'pass' => $passed,
            'check' => $checkNote,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            'result' => $payload,
            'error' => $error,
        ]);
    }

    private static function payloadOf(CallToolResult $result): mixed
    {
        if ($result->structuredContent !== null) {
            return $result->structuredContent;
        }

        $text = self::firstText($result);
        if ($text === null) {
            return null;
        }

        $decoded = json_decode($text, true);

        return $decoded ?? $text;
    }

    private static function firstText(CallToolResult $result): ?string
    {
        foreach ($result->content as $content) {
            if ($content instanceof TextContent) {
                return (string) $content->text;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function row(array $row): void
    {
        $row['n'] = \count($this->rows) + 1;
        $row['at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->rows[] = $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function writeReceipts(string $runId, string $runsRoot, ?string $serverName, ?string $serverVersion, ?string $protocol): array
    {
        if (!is_dir($this->outDir) && !mkdir($this->outDir, 0775, true) && !is_dir($this->outDir)) {
            throw new \RuntimeException('cannot create output directory: ' . $this->outDir);
        }

        $transcriptPath = $this->outDir . '/transcript.jsonl';
        $handle = fopen($transcriptPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('cannot write transcript');
        }
        foreach ($this->rows as $row) {
            fwrite($handle, json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");
        }
        fclose($handle);

        $calls = array_values(array_filter($this->rows, static fn (array $row): bool => ($row['kind'] ?? '') === 'tools/call'));
        $failed = array_values(array_filter($calls, static fn (array $row): bool => ($row['pass'] ?? false) !== true));

        $manifest = [
            'schema' => 'xg-mcp-roundtrip/v0.1',
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'run_id' => $runId,
            'runs_root' => $runsRoot,
            'server' => ['name' => $serverName, 'version' => $serverVersion, 'protocol' => $protocol],
            'client' => ['name' => 'xianggraph-roundtrip-probe', 'version' => self::PROBE_VERSION],
            'environment' => [
                'php' => PHP_VERSION,
                'mcp_sdk' => InstalledVersions::getPrettyVersion('mcp/sdk'),
                'symfony_ai_mcp_tool' => InstalledVersions::getPrettyVersion('symfony/ai-mcp-tool'),
            ],
            'calls' => [
                'total' => \count($calls),
                'pass' => \count($calls) - \count($failed),
                'fail' => \count($failed),
                'expected_refusals' => \count(array_filter($calls, static fn (array $row): bool => ($row['expect'] ?? 'ok') !== 'ok')),
            ],
            'transcript' => [
                'file' => 'transcript.jsonl',
                'sha256' => 'sha256:' . hash_file('sha256', $transcriptPath),
            ],
            'notes' => $this->notes,
        ];

        file_put_contents(
            $this->outDir . '/manifest.json',
            json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n"
        );

        if ($failed !== []) {
            $manifest['failed_calls'] = $failed;
        }

        return $manifest;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
