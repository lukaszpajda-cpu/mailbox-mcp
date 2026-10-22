<?php

declare(strict_types=1);

namespace MailboxMcp\Mail;

use DateTimeImmutable;

/**
 * One fully decoded message, as produced by {@see MessageReader::read()}:
 * server facts (uid, date, flags, size), the ids used to thread
 * conversations, the decoded headers shown to the model, the rendered body
 * text, and attachment metadata (names and sizes only — content is never
 * fetched). `rawDate` is the parsed `Date` header, kept alongside the
 * rendered `date` string so callers (e.g. {@see \MailboxMcp\Tools\GetThreadTool})
 * can sort chronologically without re-parsing.
 */
final class ReadMessage
{
    /**
     * @param array<int, string>                                                      $flags
     * @param array<int, string>                                                      $references
     * @param array<int, array{name: string, mime: string, size: int, section: string}> $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $folder,
        public readonly string $date,
        public readonly array $flags,
        public readonly int $size,
        public readonly string $messageId,
        public readonly string $inReplyTo,
        public readonly array $references,
        public readonly string $from,
        public readonly string $to,
        public readonly string $cc,
        public readonly string $replyTo,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $attachments,
        public readonly bool $truncated,
        public readonly ?DateTimeImmutable $rawDate,
    ) {
    }
}
