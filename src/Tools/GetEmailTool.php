<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Mail\MessageReader;
use MailboxMcp\Mail\ReadMessage;

/**
 * Returns one message by folder and uid: server facts (date, flags, size,
 * threading ids, attachment count), then the headers and body wrapped as
 * untrusted content. The folder is selected read-only and only
 * `BODY.PEEK` sections are fetched, so the message is never marked
 * `\Seen`; attachment content is never fetched, only its name, mime type,
 * size and IMAP section.
 */
final class GetEmailTool implements ToolInterface
{
    private const DEFAULT_MAX_CHARS = 20000;

    public function name(): string
    {
        return 'get_email';
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
            'title' => 'Get email',
            'description' => 'Returns one message by folder and uid: headers, a text rendering of the body (HTML converted), and the list of attachments (names and sizes only). Never marks the message as read.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                    'folder' => [
                        'type' => 'string',
                        'maxLength' => 255,
                        'pattern' => '^[^\r\n\0]+$',
                    ],
                    'uid' => ['type' => 'integer', 'minimum' => 1],
                    'max_chars' => ['type' => 'integer', 'minimum' => 500, 'maximum' => 50000, 'default' => self::DEFAULT_MAX_CHARS],
                ],
                'required' => ['folder', 'uid'],
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
        $folderArg = is_string($args['folder'] ?? null) ? $args['folder'] : '';
        $folder = $ctx->session->folderByName($folderArg);

        $uid = is_int($args['uid'] ?? null) ? $args['uid'] : 0;
        $maxChars = self::effectiveMaxChars($args, $ctx);

        $reader = new MessageReader($ctx->session->imap());
        $message = $reader->read($folder->name, $uid, $maxChars);

        return ToolResult::text(self::render($ctx->account->id, $message));
    }

    /**
     * @param array<string, mixed> $args already schema-validated
     */
    public static function effectiveMaxChars(array $args, ToolContext $ctx): int
    {
        $maxCharsArg = is_int($args['max_chars'] ?? null) ? $args['max_chars'] : self::DEFAULT_MAX_CHARS;
        $maxCharsLimit = is_int($ctx->limits['max_chars'] ?? null) ? $ctx->limits['max_chars'] : $maxCharsArg;

        return min($maxCharsArg, $maxCharsLimit);
    }

    /**
     * Renders one message exactly the way `get_email` does: server facts,
     * then headers and body wrapped as untrusted content, with one
     * `Attachment: …` line per attachment inside the wrapper. Shared with
     * {@see GetThreadTool}, which renders every message of a thread the
     * same way.
     */
    public static function render(string $accountId, ReadMessage $message): string
    {
        $facts = [
            'uid' => $message->uid,
            'folder' => $message->folder,
            'date' => $message->date,
            'flags' => Render::flags($message->flags),
            'size' => $message->size,
            // Mail-derived, so neutralized even though ParsedMessage::ids()
            // already restricts them to RFC 5322 msg-id characters:
            // belt-and-braces against anything escaping this outside-the-
            // wrapper fact line (see ParsedMessage::ID_PATTERN).
            'message_id' => '' === $message->messageId ? 'none' : Untrusted::neutralize($message->messageId),
            'in_reply_to' => '' === $message->inReplyTo ? 'none' : Untrusted::neutralize($message->inReplyTo),
            'references' => [] === $message->references ? 'none' : Untrusted::neutralize(implode(' ', $message->references)),
            'attachments' => count($message->attachments),
        ];

        $untrustedAttrs = [
            'account' => $accountId,
            'folder' => $message->folder,
            'uid' => (string) $message->uid,
        ];

        $headerLines = [
            "From: {$message->from}",
            "To: {$message->to}",
        ];

        if ('' !== $message->cc) {
            $headerLines[] = "Cc: {$message->cc}";
        }

        if ('' !== $message->replyTo) {
            $headerLines[] = "Reply-To: {$message->replyTo}";
        }

        $headerLines[] = "Subject: {$message->subject}";

        foreach ($message->attachments as $attachment) {
            $headerLines[] = "Attachment: {$attachment['name']} ({$attachment['mime']}, {$attachment['size']} bytes, part {$attachment['section']})";
        }

        return Render::messageBlockLines($facts, $untrustedAttrs, $headerLines, $message->body);
    }
}
