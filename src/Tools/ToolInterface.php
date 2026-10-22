<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

/**
 * Contract every mail tool implements. `definition()` is advertised to the
 * model via MCP `tools/list`; `execute()` runs the tool against already
 * schema-validated arguments.
 */
interface ToolInterface
{
    public function name(): string;

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
    public function definition(): array;

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, ToolContext $ctx): ToolResult;
}
