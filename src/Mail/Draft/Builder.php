<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Draft;

use MailboxMcp\Accounts\Identity;
use MailboxMcp\Mail\Mime\HeaderEncoder;
use MailboxMcp\Mail\Mime\HtmlToText;
use MailboxMcp\Support\Clock;
use MailboxMcp\Version;

/**
 * Builds the raw CRLF-terminated MIME source of a draft (plain text or
 * `multipart/alternative`), ready for `APPEND` into the Drafts folder.
 * Never talks to IMAP/SMTP itself; {@see \MailboxMcp\Tools\CreateDraftTool}
 * is the only caller.
 */
final class Builder
{
    private const DATE_FORMAT = 'D, d M Y H:i:s O';

    /** {@see self::capReferences()} never returns more ids than this. */
    private const MAX_REFERENCES = 20;

    public function __construct(
        private readonly Identity $identity,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $references earliest ancestor first
     */
    public function build(
        array $to,
        array $cc,
        string $subject,
        string $body,
        bool $html,
        ?string $inReplyTo,
        array $references,
        string $signature,
    ): string {
        $headerLines = [];
        $headerLines[] = 'From: ' . $this->identity->header();
        $headerLines[] = HeaderEncoder::fold('To: ' . HeaderEncoder::foldAddressList(self::plainAddresses($to)));

        if ([] !== $cc) {
            $headerLines[] = HeaderEncoder::fold('Cc: ' . HeaderEncoder::foldAddressList(self::plainAddresses($cc)));
        }

        $headerLines[] = HeaderEncoder::fold('Subject: ' . HeaderEncoder::encode($subject));
        $headerLines[] = 'Date: ' . $this->clock->now()->format(self::DATE_FORMAT);
        $headerLines[] = 'Message-ID: ' . self::messageId($this->identity->domain());
        $headerLines[] = 'MIME-Version: 1.0';
        $headerLines[] = 'User-Agent: mailbox-mcp/' . Version::STRING;

        if (null !== $inReplyTo) {
            $headerLines[] = 'In-Reply-To: ' . $inReplyTo;
        }

        $refs = self::capReferences($references);

        if ([] !== $refs) {
            $headerLines[] = HeaderEncoder::fold('References: ' . implode(' ', $refs));
        }

        $normalisedBody = self::toCrlf($body);

        return $html
            ? self::assembleHtml($headerLines, $normalisedBody, $signature)
            : self::assemblePlain($headerLines, $normalisedBody, $signature);
    }

    /**
     * Unchanged when the subject already carries a reply/forward prefix
     * (`Re:`, `Odp:`, `Aw:`, `Sv:`, `Fw:`/`Fwd:`, case-insensitive, any
     * leading whitespace), else `'Re: ' . trim($subject)`.
     */
    public static function replySubject(string $subject): string
    {
        if (1 === preg_match('/^\s*(re|odp|aw|sv|fwd?)\s*:/i', $subject)) {
            return $subject;
        }

        return 'Re: ' . trim($subject);
    }

    public static function messageId(string $domain): string
    {
        return '<' . bin2hex(random_bytes(8)) . '.' . time() . '@' . $domain . '>';
    }

    /**
     * Deduplicates (first occurrence wins) and keeps only the last
     * {@see self::MAX_REFERENCES} ids, so the closest ancestors (the ones
     * nearest the end of an "earliest ancestor first" list) survive.
     *
     * @param array<int, string> $ids
     *
     * @return list<string>
     */
    public static function capReferences(array $ids): array
    {
        $seen = [];
        $unique = [];

        foreach ($ids as $id) {
            if (!in_array($id, $seen, true)) {
                $seen[] = $id;
                $unique[] = $id;
            }
        }

        return count($unique) > self::MAX_REFERENCES
            ? array_slice($unique, -self::MAX_REFERENCES)
            : $unique;
    }

    /**
     * @param list<string> $addresses
     *
     * @return list<array{0: string}>
     */
    private static function plainAddresses(array $addresses): array
    {
        return array_map(static fn (string $email): array => [$email], $addresses);
    }

    /**
     * @param list<string> $headerLines
     */
    private static function assemblePlain(array $headerLines, string $body, string $signature): string
    {
        $headerLines[] = 'Content-Type: text/plain; charset=UTF-8';
        $headerLines[] = 'Content-Transfer-Encoding: quoted-printable';

        $content = quoted_printable_encode(self::withSignature($body, $signature));

        return self::render($headerLines, $content);
    }

    /**
     * @param list<string> $headerLines
     */
    private static function assembleHtml(array $headerLines, string $body, string $signature): string
    {
        $boundary = self::boundary();
        $headerLines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $sanitisedHtml = HtmlSanitizer::clean($body);

        $text = self::withSignature(self::toCrlf(HtmlToText::convert($sanitisedHtml)), $signature);
        $htmlBody = $sanitisedHtml;

        if ('' !== $signature) {
            $htmlBody .= '<br><br>-- <br>' . self::htmlSignatureLines($signature);
        }

        $parts = [];
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text);
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(self::toCrlf($htmlBody));

        $body = implode("\r\n", $parts) . "\r\n--{$boundary}--";

        return self::render($headerLines, $body);
    }

    /**
     * @param list<string> $headerLines
     */
    private static function render(array $headerLines, string $body): string
    {
        return implode("\r\n", $headerLines) . "\r\n\r\n" . $body;
    }

    private static function withSignature(string $body, string $signature): string
    {
        if ('' === $signature) {
            return $body;
        }

        return $body . "\r\n\r\n-- \r\n" . self::toCrlf($signature);
    }

    private static function htmlSignatureLines(string $signature): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $signature));

        $escaped = array_map(
            static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $lines,
        );

        return implode('<br>', $escaped);
    }

    private static function boundary(): string
    {
        return 'mailbox-mcp-' . bin2hex(random_bytes(16));
    }

    private static function toCrlf(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        return str_replace("\n", "\r\n", $s);
    }
}
