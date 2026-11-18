<?php

declare(strict_types=1);

namespace XiangGraph\SymfonyBridge\Ai;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Wires the XiangGraph ledger MCP server into a Symfony AI toolbox.
 *
 * The bridge is the official symfony/ai-mcp-tool package: Symfony AI discovers
 * the server's tool list and forwards calls through the official PHP MCP SDK
 * client. No XiangGraph logic is re-implemented here; this factory only decides
 * which server process to spawn and how its tools are named.
 */
final class McpLedgerToolbox
{
    public const PREFIX = 'xg_';

    public static function create(
        string $serverPath,
        string $runsRoot,
        string $prefix = self::PREFIX,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): McpToolbox {
        if (!is_file($serverPath)) {
            throw new \RuntimeException('MCP server entrypoint not found: ' . $serverPath);
        }

        $env = getenv();
        $env['XG_MCP_RUNS_ROOT'] = $runsRoot;

        $client = Client::builder()
            ->setClientInfo('xianggraph-symfony-ai', '0.1.0')
            ->build();

        $toolset = new ClientToolset(
            'xianggraph-ledger',
            $client,
            new StdioTransport(command: PHP_BINARY, args: [$serverPath], env: $env),
        );

        return new McpToolbox($toolset, $prefix, eventDispatcher: $eventDispatcher);
    }
}
