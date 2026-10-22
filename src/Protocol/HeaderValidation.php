<?php

declare(strict_types=1);

namespace MailboxMcp\Protocol;

use MailboxMcp\Http\Request;

/**
 * Validates that the HTTP-level headers of a request agree with the
 * JSON-RPC body (modern era), or that an optional legacy version header
 * names a revision this server supports.
 */
final class HeaderValidation
{
    private const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';

    public static function validateModern(Request $request, Message $message): void
    {
        $meta = $message->params['_meta'] ?? null;
        $meta = is_array($meta) ? $meta : [];

        $version = $meta[self::META_PROTOCOL_VERSION] ?? null;

        if (!is_string($version)) {
            throw new ProtocolException(
                McpError::INVALID_PARAMS,
                'Missing required _meta field: io.modelcontextprotocol/protocolVersion',
                400,
            );
        }

        $headerVersion = self::trimmedHeader($request, 'MCP-Protocol-Version');

        // A header that disagrees with the body is a header/body inconsistency
        // (HEADER_MISMATCH) regardless of whether the body's own version is
        // one we support; a header that agrees with an unsupported body
        // version, or is simply absent, falls through to the unsupported-
        // version / missing-header checks below.
        if (null !== $headerVersion && $headerVersion !== $version) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                sprintf(
                    "Header mismatch: MCP-Protocol-Version header value '%s' does not match body value '%s'",
                    $headerVersion,
                    $version,
                ),
                400,
            );
        }

        if (!in_array($version, Versions::MODERN, true)) {
            throw new ProtocolException(
                McpError::UNSUPPORTED_VERSION,
                'Unsupported protocol version',
                400,
                ['supported' => Versions::MODERN, 'requested' => $version],
            );
        }

        if (null === $headerVersion) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                'Header mismatch: missing required MCP-Protocol-Version header',
                400,
            );
        }

        $headerMethod = self::trimmedHeader($request, 'Mcp-Method');

        if (null === $headerMethod) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                'Header mismatch: missing required Mcp-Method header',
                400,
            );
        }

        if ($headerMethod !== $message->method) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                sprintf(
                    "Header mismatch: Mcp-Method header value '%s' does not match body value '%s'",
                    $headerMethod,
                    $message->method,
                ),
                400,
            );
        }

        if ('tools/call' === $message->method) {
            self::validateToolName($request, $message);
        }

        $clientCapabilities = $meta[self::META_CLIENT_CAPABILITIES] ?? null;

        if (!is_array($clientCapabilities)) {
            throw new ProtocolException(
                McpError::INVALID_PARAMS,
                'Missing required _meta field: io.modelcontextprotocol/clientCapabilities',
                400,
            );
        }
    }

    private static function validateToolName(Request $request, Message $message): void
    {
        $name = $message->params['name'] ?? null;

        if (!is_string($name)) {
            // Not a string: the dispatcher will reject the params shape later.
            return;
        }

        $headerName = self::trimmedHeader($request, 'Mcp-Name');

        if (null === $headerName) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                'Header mismatch: missing required Mcp-Name header',
                400,
            );
        }

        if (self::decodeSentinel($headerName) !== $name) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                sprintf(
                    "Header mismatch: Mcp-Name header value '%s' does not match body value '%s'",
                    $headerName,
                    $name,
                ),
                400,
            );
        }
    }

    public static function decodeSentinel(string $value): string
    {
        $prefix = '=?base64?';
        $suffix = '?=';

        if (!str_starts_with($value, $prefix) || !str_ends_with($value, $suffix)) {
            return $value;
        }

        $encoded = substr($value, strlen($prefix), -strlen($suffix));
        $decoded = base64_decode($encoded, true);

        if (false === $decoded) {
            throw new ProtocolException(
                McpError::HEADER_MISMATCH,
                'Header mismatch: invalid base64 sentinel',
                400,
            );
        }

        return $decoded;
    }

    public static function validateLegacy(Request $request): void
    {
        $headerVersion = self::trimmedHeader($request, 'MCP-Protocol-Version');

        if (null !== $headerVersion && !in_array($headerVersion, Versions::LEGACY, true)) {
            throw new ProtocolException(
                McpError::INVALID_REQUEST,
                'Unsupported protocol version',
                400,
                ['supported' => Versions::LEGACY, 'requested' => $headerVersion],
            );
        }
    }

    /**
     * Reads a header value and trims leading/trailing spaces and tabs
     * (RFC 9110 §5.5 optional whitespace), so a client that pads a header
     * value (e.g. `Mcp-Name: test_simple_text  `) is not rejected as a
     * mismatch.
     */
    private static function trimmedHeader(Request $request, string $name): ?string
    {
        $value = $request->header($name);

        return null === $value ? null : trim($value, " \t");
    }
}
