<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

/**
 * JSON-RPC and MCP-specific error codes used across the protocol layer.
 */
final class McpError
{
    public const PARSE = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL = -32603;
    public const HEADER_MISMATCH = -32020;
    public const MISSING_CAPABILITY = -32021;
    public const UNSUPPORTED_VERSION = -32022;
}
