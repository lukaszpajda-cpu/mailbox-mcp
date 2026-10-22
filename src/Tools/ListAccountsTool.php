<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Accounts\AccountResolver;

/**
 * Lists every configured account: id, email, display name, which one is
 * the default (the first configured), and whether a guidelines file is
 * configured. Unlike every other tool, this one is not scoped to a
 * single account, so it is constructed with the full {@see AccountResolver}
 * rather than reading {@see ToolContext::$account}.
 */
final class ListAccountsTool implements ToolInterface
{
    public function __construct(private readonly AccountResolver $accounts)
    {
    }

    public function name(): string
    {
        return 'list_accounts';
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
            'title' => 'List accounts',
            'description' => 'Lists every configured mailbox account, with its email, display name, whether it is the default account, and whether a reply-guidelines file is configured.',
            'inputSchema' => [
                'type' => 'object',
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
        $accounts = $this->accounts->all();
        $defaultId = $this->accounts->default()->id;

        $lines = ['accounts: ' . count($accounts)];

        foreach ($accounts as $account) {
            $marker = $account->id === $defaultId ? ' (default)' : '';
            $lines[] = "- id: {$account->id}{$marker}";
            $lines[] = "  email: {$account->identity->email}";
            $lines[] = "  name: {$account->identity->name}";
            $lines[] = '  guidelines: ' . (null !== $account->guidelinesFile ? 'yes' : 'no');
        }

        return ToolResult::text(implode("\n", $lines));
    }
}
