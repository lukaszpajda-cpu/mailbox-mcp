<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use MailboxMcp\Mail\Imap\ImapClientInterface;
use MailboxMcp\Mail\Imap\ImapException;
use MailboxMcp\Mail\Mime\BodyStructure;
use MailboxMcp\Mail\Mime\Charset;
use MailboxMcp\Mail\Mime\HtmlToText;
use MailboxMcp\Mail\Mime\Transfer;

/**
 * Builds the short preview text shown for a search result: the first
 * text part (plain preferred, HTML converted to text otherwise),
 * fetched partially, decoded, whitespace-collapsed and cut to 200
 * characters. Never throws: a message with no text part, an unparsable
 * BODYSTRUCTURE, or an IMAP failure while fetching the section all
 * yield an empty string rather than failing the whole search.
 */
final class Snippet
{
    private const MAX_FETCH_BYTES = 4096;

    private const MAX_CHARS = 200;

    public static function fromSummary(ImapClientInterface $imap, int $uid, string $bodystructure): string
    {
        $root = BodyStructure::tryParse($bodystructure);

        if (null === $root) {
            return '';
        }

        $part = BodyStructure::firstText($root);

        if (null === $part) {
            return '';
        }

        try {
            $raw = $imap->fetchSection($uid, $part->section, self::MAX_FETCH_BYTES);
        } catch (ImapException) {
            return '';
        }

        $decoded = Transfer::decode($raw, $part->encoding);
        $text = Charset::toUtf8($decoded, $part->charset);

        if ('html' === $part->subtype) {
            $text = HtmlToText::convert($text);
        }

        $collapsed = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_substr($collapsed, 0, self::MAX_CHARS);
    }
}
