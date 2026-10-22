<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Decodes raw MIME header field bodies: unfolds continuation lines and
 * decodes RFC 2047 encoded words (`=?charset?B?...?=` / `=?charset?Q?...?=`)
 * into a single UTF-8 string.
 */
final class HeaderDecoder
{
    private const ENCODED_WORD = '/=\?([^?\s]+)\?([BbQq])\?([^?]*)\?=/';

    public static function decode(string $value): string
    {
        $unfolded = self::unfold($value);
        $joined = self::decodeEncodedWords($unfolded);
        $collapsed = preg_replace('/\s+/', ' ', $joined) ?? $joined;

        return trim($collapsed);
    }

    private static function unfold(string $value): string
    {
        $unfolded = preg_replace('/(?:\r\n|\r|\n)[ \t]+/', ' ', $value);

        return $unfolded ?? $value;
    }

    private static function decodeEncodedWords(string $value): string
    {
        $result = '';
        $offset = 0;
        $lastWasEncoded = false;

        while (preg_match(self::ENCODED_WORD, $value, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = (int) $m[0][1];
            $matchText = (string) $m[0][0];
            $between = substr($value, $offset, $matchStart - $offset);

            $decoded = self::decodeWord((string) $m[1][0], (string) $m[2][0], (string) $m[3][0]);

            $isWhitespaceGap = '' !== $between && '' === trim($between, " \t");

            if (!($lastWasEncoded && null !== $decoded && $isWhitespaceGap)) {
                $result .= $between;
            }

            if (null === $decoded) {
                $result .= $matchText;
                $lastWasEncoded = false;
            } else {
                $result .= $decoded;
                $lastWasEncoded = true;
            }

            $offset = $matchStart + strlen($matchText);
        }

        $result .= substr($value, $offset);

        return $result;
    }

    private static function decodeWord(string $charset, string $encoding, string $text): ?string
    {
        $bytes = match (strtoupper($encoding)) {
            'B' => self::decodeBase64($text),
            'Q' => self::decodeQuotedPrintable($text),
            default => null,
        };

        if (null === $bytes) {
            return null;
        }

        return Charset::toUtf8($bytes, $charset);
    }

    private static function decodeBase64(string $text): ?string
    {
        if ('' === $text) {
            return '';
        }

        if (1 !== preg_match('#^[A-Za-z0-9+/]*={0,2}$#', $text)) {
            return null;
        }

        $decoded = base64_decode($text, true);

        return false === $decoded ? null : $decoded;
    }

    private static function decodeQuotedPrintable(string $text): ?string
    {
        $result = '';
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];

            if ('_' === $char) {
                $result .= ' ';
                continue;
            }

            if ('=' === $char) {
                $hex = substr($text, $i + 1, 2);

                if (1 !== preg_match('/^[0-9A-Fa-f]{2}$/', $hex)) {
                    return null;
                }

                $result .= chr((int) hexdec($hex) & 0xFF);
                $i += 2;
                continue;
            }

            $result .= $char;
        }

        return $result;
    }
}
