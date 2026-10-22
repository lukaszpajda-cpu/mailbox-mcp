<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Conformance;

use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolException;
use MailboxMcp\Tools\ToolInterface;
use MailboxMcp\Tools\ToolResult;
use stdClass;

/**
 * The two tools the MCP conformance suite's `tools-call-simple-text` and
 * `tools-call-error` scenarios require (see
 * `node_modules/@modelcontextprotocol/conformance/dist/index.js`): a tool
 * named `test_simple_text` that returns a fixed text content block, and a
 * tool named `test_error_handling` that always fails with `isError: true`.
 * Neither takes arguments or touches a mail session.
 */
final class SimpleTextTool implements ToolInterface
{
    public function name(): string
    {
        return 'test_simple_text';
    }

    /**
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     annotations: array{
     *         readOnlyHint: bool,
     *         destructiveHint: bool,
     *         idempotentHint: bool,
     *         openWorldHint: bool,
     *     },
     * }
     */
    public function definition(): array
    {
        return [
            'name' => 'test_simple_text',
            'title' => 'Test Simple Text',
            'description' => 'Conformance harness tool: always returns a fixed text response.',
            'inputSchema' => [
                'type' => 'object',
                // An empty stdClass (not []), so this serialises as the
                // JSON object {} that a JSON Schema requires here; the
                // conformance suite's tools-list check rejects an empty
                // JSON array in its place.
                'properties' => new stdClass(),
                'required' => [],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        return ToolResult::text('This is a simple text response for testing.');
    }
}

/**
 * @see SimpleTextTool
 */
final class ErrorTool implements ToolInterface
{
    public function name(): string
    {
        return 'test_error_handling';
    }

    /**
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     annotations: array{
     *         readOnlyHint: bool,
     *         destructiveHint: bool,
     *         idempotentHint: bool,
     *         openWorldHint: bool,
     *     },
     * }
     */
    public function definition(): array
    {
        return [
            'name' => 'test_error_handling',
            'title' => 'Test Error Handling',
            'description' => 'Conformance harness tool: always returns an error result.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        throw new ToolException('This tool intentionally returns an error for testing');
    }
}
