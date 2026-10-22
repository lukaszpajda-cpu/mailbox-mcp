<?php

declare(strict_types=1);

namespace MailboxMcp\Tests\Support;

use MailboxMcp\Tools\ToolContext;
use MailboxMcp\Tools\ToolException;
use MailboxMcp\Tools\ToolInterface;
use MailboxMcp\Tools\ToolResult;
use RuntimeException;

/**
 * Minimal tool used across protocol/registry tests. Echoes `text` back
 * unchanged, except for two sentinel values used to exercise error
 * handling: `fail` raises a {@see ToolException} (an execution error, shown
 * to the model as an isError result) and `crash` raises a plain
 * {@see RuntimeException} (an unexpected internal error).
 */
final class EchoTool implements ToolInterface
{
    public function name(): string
    {
        return 'echo';
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
            'name' => 'echo',
            'title' => 'Echo',
            'description' => 'Echoes text',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'text' => ['type' => 'string'],
                ],
                'required' => ['text'],
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
        $text = $args['text'] ?? null;

        if (!is_string($text)) {
            throw new ToolException('text must be a string');
        }

        if ('fail' === $text) {
            throw new ToolException('echo failed');
        }

        if ('crash' === $text) {
            throw new RuntimeException('boom');
        }

        return ToolResult::text($text);
    }
}
