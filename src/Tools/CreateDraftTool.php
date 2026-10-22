<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Mail\Draft\Builder;
use MailboxMcp\Mail\Draft\HtmlSanitizer;
use MailboxMcp\Mail\Imap\ImapClientInterface;
use MailboxMcp\Mail\Imap\SearchCriteria;
use MailboxMcp\Mail\Mime\Address;
use MailboxMcp\Mail\Mime\HtmlToText;
use MailboxMcp\Mail\Mime\Parser;

/**
 * Builds a draft ({@see \MailboxMcp\Mail\Draft\Builder}) and appends it to
 * the account's Drafts folder with the `\Draft \Seen` flags. The account
 * signature (when configured) is appended to every draft. With
 * `in_reply_to_uid` set, the original message's headers (fetched
 * read-only) supply `In-Reply-To`/`References` and the `Re:` prefix.
 * Never sends mail and
 * never touches SMTP: the resulting message sits in Drafts until
 * `send_draft` (a later tool) is called on it.
 */
final class CreateDraftTool implements ToolInterface
{
    private const ADDRESS_PATTERN = '^[^\s<>,;"\']+@[^\s<>,;"\']+$';

    public function name(): string
    {
        return 'create_draft';
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
        $addressItems = [
            'type' => 'string',
            'pattern' => self::ADDRESS_PATTERN,
        ];

        return [
            'name' => $this->name(),
            'title' => 'Create draft',
            'description' => 'Stores a new draft in the Drafts folder and returns its uid. The account signature, when configured, is appended to every draft, so do not write one into the body. Use in_reply_to_uid to reply: In-Reply-To, References and the Re: prefix are set automatically. Nothing is sent.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                    'to' => [
                        'type' => 'array',
                        'items' => $addressItems,
                        'minItems' => 1,
                        'maxItems' => 10,
                    ],
                    'cc' => [
                        'type' => 'array',
                        'items' => $addressItems,
                        'maxItems' => 10,
                    ],
                    'subject' => ['type' => 'string', 'maxLength' => 998],
                    'body' => ['type' => 'string', 'maxLength' => 100000],
                    'html' => ['type' => 'boolean', 'default' => false],
                    'in_reply_to_uid' => ['type' => 'integer', 'minimum' => 1],
                    'in_reply_to_folder' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'pattern' => '^[^\r\n\0]+$',
                    ],
                ],
                'required' => ['to', 'subject', 'body'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
        ];
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $to = self::addressList($args, 'to');
        $cc = self::addressList($args, 'cc');

        $maxRecipients = is_int($ctx->limits['max_recipients'] ?? null) ? $ctx->limits['max_recipients'] : 10;
        $total = count($to) + count($cc);

        if ($total > $maxRecipients) {
            throw new ToolException("Too many recipients ({$total} > {$maxRecipients})");
        }

        foreach ([...$to, ...$cc] as $address) {
            if (!Address::isValid($address)) {
                throw new ToolException("Invalid address: {$address}");
            }
        }

        $subjectArg = is_string($args['subject'] ?? null) ? $args['subject'] : '';
        $bodyArg = is_string($args['body'] ?? null) ? $args['body'] : '';
        $html = true === ($args['html'] ?? false);

        $inReplyToUid = is_int($args['in_reply_to_uid'] ?? null) ? $args['in_reply_to_uid'] : null;

        $inReplyTo = null;
        $references = [];
        $subject = $subjectArg;

        if (null !== $inReplyToUid) {
            [$inReplyTo, $references, $subject] = $this->resolveReply($args, $ctx, $inReplyToUid, $subjectArg);
        }

        $builder = new Builder($ctx->account->identity, $ctx->clock);
        $raw = $builder->build($to, $cc, $subject, $bodyArg, $html, $inReplyTo, $references, $ctx->account->signature);
        $messageId = Parser::parse($raw)->messageId() ?? '';

        $draftsFolder = $ctx->session->folderForRole('drafts');
        $imap = $ctx->session->imap();
        $uid = $imap->append($draftsFolder->name, $raw, ['\\Draft', '\\Seen']);

        if (null === $uid) {
            $uid = $this->findAppendedUid($imap, $draftsFolder->name, $messageId);
        }

        $displayBody = $html ? HtmlToText::convert(HtmlSanitizer::clean($bodyArg)) : $bodyArg;

        if ('' !== $ctx->account->signature) {
            $displayBody .= "\n\n-- \n" . str_replace("\r\n", "\n", $ctx->account->signature);
        }

        return ToolResult::text(self::render(
            $ctx->account->id,
            $draftsFolder->name,
            $uid,
            $messageId,
            $to,
            $cc,
            $subject,
            $displayBody,
        ));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: ?string, 1: list<string>, 2: string} [inReplyTo, references, subject]
     */
    private function resolveReply(array $args, ToolContext $ctx, int $uid, string $subjectArg): array
    {
        $folderArg = is_string($args['in_reply_to_folder'] ?? null) ? $args['in_reply_to_folder'] : null;
        $folder = null !== $folderArg
            ? $ctx->session->folderByName($folderArg)
            : $ctx->session->folderForRole('inbox');

        $imap = $ctx->session->imap();
        $imap->select($folder->name, true);
        $rawHeaders = $imap->fetchHeaders($uid);

        if ('' === trim($rawHeaders)) {
            throw new ToolException("Message uid {$uid} not found in folder '{$folder->name}'");
        }

        $parsed = Parser::parse($rawHeaders . "\r\n\r\n");

        $originalMessageId = $parsed->messageId();
        $references = $parsed->references();

        if (null !== $originalMessageId) {
            $references[] = $originalMessageId;
        }

        return [$originalMessageId, $references, Builder::replySubject($subjectArg)];
    }

    private function findAppendedUid(ImapClientInterface $imap, string $draftsFolder, string $messageId): int
    {
        $imap->select($draftsFolder, true);
        $found = $imap->search('HEADER Message-ID ' . SearchCriteria::quote($messageId), null);

        if ([] === $found) {
            throw new ToolException('Draft stored but its uid could not be determined');
        }

        return max($found);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return list<string>
     */
    private static function addressList(array $args, string $key): array
    {
        $raw = $args[$key] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $value) {
            if (is_string($value)) {
                $out[] = trim($value);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    private static function render(
        string $accountId,
        string $draftsFolder,
        int $uid,
        string $messageId,
        array $to,
        array $cc,
        string $subject,
        string $displayBody,
    ): string {
        $facts = [
            'draft_uid' => $uid,
            'folder' => $draftsFolder,
            'message_id' => $messageId,
        ];

        $untrustedAttrs = [
            'account' => $accountId,
            'folder' => $draftsFolder,
            'uid' => (string) $uid,
        ];

        $headerLines = [];
        $headerLines[] = 'To: ' . implode(', ', $to);

        if ([] !== $cc) {
            $headerLines[] = 'Cc: ' . implode(', ', $cc);
        }

        $headerLines[] = 'Subject: ' . $subject;

        return Render::messageBlockLines($facts, $untrustedAttrs, $headerLines, $displayBody);
    }
}
