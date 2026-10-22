<?php

declare(strict_types=1);

namespace MailboxMcp\Mail;

use MailboxMcp\Mail\Imap\ImapClientInterface;
use MailboxMcp\Mail\Mime\BodyStructure;
use MailboxMcp\Mail\Mime\Charset;
use MailboxMcp\Mail\Mime\HtmlToText;
use MailboxMcp\Mail\Mime\Parser;
use MailboxMcp\Mail\Mime\Part;
use MailboxMcp\Mail\Mime\Transfer;
use MailboxMcp\Tools\Render;
use MailboxMcp\Tools\ToolException;

/**
 * Reads one message by folder and uid into a fully decoded
 * {@see ReadMessage}: headers, a text rendering of the body (the first
 * text/plain part, or text/html converted to text when no plain part
 * exists), truncated to a caller-chosen character limit, and the list of
 * attachments (names and sizes only — content is never fetched). The
 * folder is always selected read-only, so nothing is ever marked `\Seen`.
 */
final class MessageReader
{
    public function __construct(private readonly ImapClientInterface $imap)
    {
    }

    public function read(string $folder, int $uid, int $maxChars): ReadMessage
    {
        $this->imap->select($folder, true);

        $summary = $this->imap->fetchSummaries([$uid])[$uid] ?? null;

        if (null === $summary) {
            throw new ToolException("Message uid {$uid} not found in folder '{$folder}'");
        }

        $parsed = Parser::parse($summary['headers'] . "\r\n\r\n");
        $root = BodyStructure::tryParse($summary['bodystructure']);

        [$body, $truncated] = $this->readBody($uid, $root, $maxChars);
        $attachments = null === $root ? [] : $this->readAttachments($root);

        return new ReadMessage(
            $uid,
            $folder,
            Render::date($parsed->header('Date'), $summary['internaldate']),
            $summary['flags'],
            $summary['size'],
            $parsed->messageId() ?? '',
            $parsed->inReplyTo() ?? '',
            $parsed->references(),
            $parsed->header('From') ?? '',
            $parsed->header('To') ?? '',
            $parsed->header('Cc') ?? '',
            $parsed->header('Reply-To') ?? '',
            $parsed->header('Subject') ?? '',
            $body,
            $attachments,
            $truncated,
            $parsed->date(),
        );
    }

    /**
     * @return array{0: string, 1: bool} [body, truncated]
     */
    private function readBody(int $uid, ?Part $root, int $maxChars): array
    {
        if (null === $root) {
            return ['', false];
        }

        $part = BodyStructure::firstText($root);

        if (null === $part) {
            return ['', false];
        }

        $byteLimit = $maxChars * 4;
        $raw = $this->imap->fetchSection($uid, $part->section, $byteLimit);
        $decoded = Transfer::decode($raw, $part->encoding);
        $text = Charset::toUtf8($decoded, $part->charset);

        if ('html' === $part->subtype) {
            $text = HtmlToText::convert($text);
        }

        // Detecting truncation from the decoded length alone misses a
        // part that was cut at the byte limit but whose decoded (and,
        // for HTML, converted-to-text) result happens to be short: the
        // server-reported part size, or the fetch having reached the
        // byte limit, are both signs there may be more we never fetched.
        $byteBoundHit = $part->size > $byteLimit || strlen($raw) >= $byteLimit;
        $truncated = $byteBoundHit || mb_strlen($text) > $maxChars;
        $body = mb_substr($text, 0, $maxChars);

        if ($truncated) {
            $body .= "\n[truncated at {$maxChars} characters]";
        }

        return [$body, $truncated];
    }

    /**
     * @return array<int, array{name: string, mime: string, size: int, section: string}>
     */
    private function readAttachments(Part $root): array
    {
        $result = [];

        foreach (BodyStructure::attachments($root) as $part) {
            $result[] = [
                'name' => $part->filename ?? '(unnamed)',
                'mime' => $part->mime(),
                'size' => $part->size,
                'section' => $part->section,
            ];
        }

        return $result;
    }
}
