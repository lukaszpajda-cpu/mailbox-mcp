<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

/**
 * Returns the operator-authored reply-guidelines file for an account
 * verbatim, as trusted text (not wrapped): the file is configuration,
 * never mail content. The only path ever read is the one configured for
 * the resolved account; the tool takes no path argument.
 */
final class GetReplyGuidelinesTool implements ToolInterface
{
    private const MAX_BYTES = 32768;

    public function name(): string
    {
        return 'get_reply_guidelines';
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
            'name' => $this->name(),
            'title' => 'Get reply guidelines',
            'description' => 'Returns the operator-authored reply-guidelines file configured for an account, verbatim and as trusted text.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                ],
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
        $path = $ctx->account->guidelinesFile;

        if (null === $path) {
            throw new ToolException("Account '{$ctx->account->id}' has no guidelines file configured");
        }

        $content = is_readable($path) ? @file_get_contents($path) : false;

        if (false === $content) {
            throw new ToolException("Guidelines file for account '{$ctx->account->id}' is not readable");
        }

        if (strlen($content) > self::MAX_BYTES) {
            $content = substr($content, 0, self::MAX_BYTES) . "\n[truncated]";
        }

        return ToolResult::text($content);
    }
}
