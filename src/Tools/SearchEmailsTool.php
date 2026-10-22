<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Mail\Imap\ImapClientInterface;
use MailboxMcp\Mail\Imap\SearchCriteria;
use MailboxMcp\Mail\Mime\BodyStructure;
use MailboxMcp\Mail\Mime\Parser;

/**
 * Searches a folder and returns, for the newest `limit` matches, the
 * server facts (uid, date, flags, size, attachment count) plus the
 * from/to/subject headers and a short body snippet wrapped as untrusted
 * content. Never marks anything `\Seen` (the folder is selected
 * read-only and only `BODY.PEEK` sections are fetched); `exclude_processed`
 * hides messages already marked by `mark_processed`, it does not mark
 * anything itself.
 */
final class SearchEmailsTool implements ToolInterface
{
    public function name(): string
    {
        return 'search_emails';
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
            'title' => 'Search emails',
            'description' => 'Searches a folder by sender, recipient, subject, body text and/or date range, returning the newest matches with a short snippet of each. Never marks messages read; exclude_processed hides messages previously marked by mark_processed.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                    'folder' => [
                        'type' => 'string',
                        'default' => 'INBOX',
                        'maxLength' => 255,
                        'pattern' => '^[^\r\n\0]+$',
                    ],
                    'from' => ['type' => 'string', 'maxLength' => 998],
                    'to' => ['type' => 'string', 'maxLength' => 998],
                    'subject' => ['type' => 'string', 'maxLength' => 998],
                    'text' => ['type' => 'string', 'maxLength' => 200],
                    'since' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                    'before' => ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                    'unseen_only' => ['type' => 'boolean', 'default' => false],
                    'exclude_processed' => ['type' => 'boolean', 'default' => false],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
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
        $folderArg = is_string($args['folder'] ?? null) ? $args['folder'] : 'INBOX';
        $folder = $ctx->session->folderByName($folderArg);

        $imap = $ctx->session->imap();
        $imap->select($folder->name, true);

        $criteria = SearchCriteria::fromArgs($args, $ctx->account->processedKeyword);
        $uids = $imap->search($criteria->toImap(), $criteria->needsCharset() ? 'UTF-8' : null);

        $matched = count($uids);

        rsort($uids);

        $limitArg = is_int($args['limit'] ?? null) ? $args['limit'] : 20;
        $maxResults = is_int($ctx->limits['max_results'] ?? null) ? $ctx->limits['max_results'] : $limitArg;
        $limit = min($limitArg, $maxResults);

        $shown = array_slice($uids, 0, $limit);
        $summaries = $imap->fetchSummaries($shown);

        $blocks = [];

        foreach ($shown as $uid) {
            if (!isset($summaries[$uid])) {
                continue;
            }

            $blocks[] = $this->renderMessage($ctx->account->id, $folder->name, $uid, $summaries[$uid], $imap);
        }

        $lines = [
            "account: {$ctx->account->id}",
            "folder: {$folder->name}",
            "matched: {$matched}",
            'results: ' . count($blocks),
        ];

        $body = implode("\n", $lines);

        if ([] !== $blocks) {
            $body .= "\n\n" . implode("\n\n", $blocks);
        }

        return ToolResult::text($body);
    }

    /**
     * @param array{flags: array<int, string>, size: int, internaldate: ?string, headers: string, bodystructure: string} $summary
     */
    private function renderMessage(string $accountId, string $folderName, int $uid, array $summary, ImapClientInterface $imap): string
    {
        $parsed = Parser::parse($summary['headers'] . "\r\n\r\n");
        $root = BodyStructure::tryParse($summary['bodystructure']);
        $attachments = null === $root ? 0 : count(BodyStructure::attachments($root));

        $facts = [
            'date' => Render::date($parsed->header('Date'), $summary['internaldate']),
            'flags' => Render::flags($summary['flags']),
            'size' => $summary['size'],
            'attachments' => $attachments,
        ];

        $untrustedAttrs = [
            'account' => $accountId,
            'folder' => $folderName,
            'uid' => (string) $uid,
        ];

        $untrustedHeaders = [];

        foreach (['From', 'To', 'Subject'] as $headerName) {
            $value = $parsed->header($headerName);

            if (null !== $value) {
                $untrustedHeaders[$headerName] = $value;
            }
        }

        $snippet = Snippet::fromSummary($imap, $uid, $summary['bodystructure']);

        return "## uid {$uid}\n" . Render::messageBlock($facts, $untrustedAttrs, $untrustedHeaders, $snippet);
    }
}
