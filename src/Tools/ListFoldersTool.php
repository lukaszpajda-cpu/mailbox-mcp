<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Mail\Imap\ImapException;

/**
 * Lists every folder for an account with its resolved special-use role
 * (via {@see \MailboxMcp\Mail\MailSession::folders()}) and its message
 * counts (`STATUS (MESSAGES UNSEEN)`). A folder whose `STATUS` fails
 * (e.g. a `\Noselect` folder) still appears, with `total=?  unseen=?`
 * rather than aborting the whole listing.
 */
final class ListFoldersTool implements ToolInterface
{
    public function name(): string
    {
        return 'list_folders';
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
            'title' => 'List folders',
            'description' => 'Lists every IMAP folder for an account with its detected role (inbox, drafts, sent, trash, junk, archive, or none) and its total and unseen message counts.',
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
        $folders = $ctx->session->folders();

        $lines = [
            "account: {$ctx->account->id}",
            'folders: ' . count($folders),
        ];

        foreach ($folders as $folder) {
            $role = $folder->role ?? 'none';

            try {
                $status = $ctx->session->imap()->status($folder->name);
                $total = (string) $status['messages'];
                $unseen = (string) $status['unseen'];
            } catch (ImapException) {
                $total = '?';
                $unseen = '?';
            }

            $lines[] = "- {$folder->name}  role={$role}  total={$total}  unseen={$unseen}";
        }

        return ToolResult::text(implode("\n", $lines));
    }
}
