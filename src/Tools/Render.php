<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use DateTimeImmutable;
use MailboxMcp\Mail\Mime\Parser;

/**
 * Shared rendering helpers for mail tools: ISO 8601 dates, a flags
 * summary, and the "server facts, then untrusted wrapper" message layout
 * used by every tool that shows a message (spec §8).
 */
final class Render
{
    private const INTERNALDATE_FORMAT = '!d-M-Y H:i:s O';

    private const ISO_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * ISO 8601 with offset, preferring the `Date` header (parsed with the
     * same RFC 5322 semantics as {@see \MailboxMcp\Mail\Mime\ParsedMessage::date()}),
     * then the IMAP `INTERNALDATE` (`d-M-Y H:i:s O`), else `unknown`.
     */
    public static function date(?string $headerDate, ?string $internalDate): string
    {
        if (null !== $headerDate) {
            $parsed = Parser::parse("Date: {$headerDate}\r\n\r\n")->date();

            if (null !== $parsed) {
                return $parsed->format(self::ISO_FORMAT);
            }
        }

        if (null !== $internalDate) {
            // RFC 3501's date-day-fixed allows a space-padded single digit
            // (e.g. " 5-Oct-2026 …") instead of a leading zero; `d` only
            // accepts the latter, so the leading space is trimmed first.
            $parsed = DateTimeImmutable::createFromFormat(self::INTERNALDATE_FORMAT, ltrim($internalDate));

            if (false !== $parsed) {
                return $parsed->format(self::ISO_FORMAT);
            }
        }

        return 'unknown';
    }

    /**
     * @param array<int, string> $flags
     */
    public static function flags(array $flags): string
    {
        return [] === $flags ? 'none' : implode(' ', $flags);
    }

    /**
     * Renders one `key: value` line per fact (in the given order), then
     * the untrusted wrapper: one `Name: value` line per untrusted header
     * (in the given order), a `---` separator, and the body.
     *
     * @param array<string, string|int>  $facts
     * @param array<string, string>      $untrustedAttrs
     * @param array<string, string>      $untrustedHeaders header name => value, e.g. 'From' => '...'
     */
    public static function messageBlock(array $facts, array $untrustedAttrs, array $untrustedHeaders, string $body): string
    {
        $headerLines = [];

        foreach ($untrustedHeaders as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        return self::messageBlockLines($facts, $untrustedAttrs, $headerLines, $body);
    }

    /**
     * Like {@see self::messageBlock()}, but the untrusted header lines are
     * supplied already formatted (e.g. "Name: value", or several
     * "Attachment: …" lines for the same name) instead of a name => value
     * map, so a message with a repeated line — several attachments, in
     * particular — can be rendered.
     *
     * @param array<string, string|int> $facts
     * @param array<string, string>     $untrustedAttrs
     * @param array<int, string>        $headerLines already formatted lines, in order
     */
    public static function messageBlockLines(array $facts, array $untrustedAttrs, array $headerLines, string $body): string
    {
        $lines = [];

        foreach ($facts as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }

        $headerBlock = [] === $headerLines ? '' : implode("\n", $headerLines) . "\n";

        $wrapped = Untrusted::wrap($untrustedAttrs, $headerBlock . "---\n" . $body);

        $lines[] = $wrapped;

        return implode("\n", $lines);
    }
}
