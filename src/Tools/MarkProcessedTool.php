<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

/**
 * Adds (or, with `processed: false`, removes) the account's processed
 * keyword on up to 100 messages in one folder, so scheduled runs can skip
 * messages they already handled (`search_emails exclude_processed`).
 * Only ever touches that single keyword; never system flags.
 */
final class MarkProcessedTool implements ToolInterface
{
    public function name(): string
    {
        return 'mark_processed';
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
            'title' => 'Mark processed',
            'description' => "Sets or clears the account's processed keyword (e.g. \$ClaudeSeen) on messages so scheduled runs skip them; search_emails exclude_processed hides marked messages.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                    'folder' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'pattern' => '^[^\r\n\0]+$',
                    ],
                    'uids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'minItems' => 1,
                        'maxItems' => 100,
                    ],
                    'processed' => ['type' => 'boolean', 'default' => true],
                ],
                'required' => ['folder', 'uids'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $keyword = $ctx->account->processedKeyword;

        if ('' === $keyword) {
            throw new ToolException('No processed keyword configured for this account');
        }

        $folderArg = is_string($args['folder'] ?? null) ? $args['folder'] : '';
        $processed = false !== ($args['processed'] ?? true);

        $uids = [];

        foreach (is_array($args['uids'] ?? null) ? $args['uids'] : [] as $uid) {
            if (is_int($uid) && $uid >= 1) {
                $uids[] = $uid;
            }
        }

        $uids = array_values(array_unique($uids));

        if ([] === $uids) {
            throw new ToolException('No uids given');
        }

        $folder = $ctx->session->folderByName($folderArg);
        $imap = $ctx->session->imap();
        $imap->select($folder->name, false);
        $changed = $imap->storeFlags($uids, [$keyword], $processed);

        return ToolResult::text(implode("\n", [
            'account: ' . $ctx->account->id,
            'folder: ' . $folder->name,
            'keyword: ' . $keyword,
            'processed: ' . ($processed ? 'true' : 'false'),
            'changed: ' . $changed,
        ]));
    }
}
