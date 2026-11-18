<?php

declare(strict_types=1);

namespace XiangGraph\Mcp\Ledger;

/**
 * A request the ledger cannot serve as asked.
 *
 * The MCP server maps this to a tool-level error (CallToolResult::isError) so
 * the caller sees the refusal without the transport treating it as a protocol
 * fault.
 */
final class LedgerException extends \RuntimeException
{
}
