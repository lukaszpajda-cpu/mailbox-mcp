<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Encodes UTF-8 text for use in MIME header field bodies: RFC 2047
 * encoded words for non-ASCII text, and RFC 5322 display-name / mailbox
 * formatting for the address headers Identity and the draft/send tools
 * build (`From`, `To`, `Cc`, ...).
 */
final class HeaderEncoder
{
    /**
     * RFC 5322 "specials" that force a display-name to be quoted.
     */
    private const SPECIALS = '()<>[]:;@\\,."';

    /**
     * Maximum header line length per RFC 2047/5322 folding conventions.
     */
    private const MAX_WORD_LENGTH = 75;

    /**
     * Fixed overhead of a "=?UTF-8?B??=" wrapper around the base64 payload.
     */
    private const WRAPPER_OVERHEAD = 12; // strlen('=?UTF-8?B?') + strlen('?=')

    /** RFC 5322 §2.1.1 recommended maximum physical header line length, used by {@see self::fold()}. */
    private const FOLD_SOFT_LIMIT = 78;

    /** RFC 5322 §2.1.1 hard maximum physical header line length, used by {@see self::fold()}. */
    private const FOLD_HARD_LIMIT = 998;

    public static function encode(string $value): string
    {
        if (self::isAsciiPrintable($value)) {
            return $value;
        }

        $maxBase64Chars = self::MAX_WORD_LENGTH - self::WRAPPER_OVERHEAD;
        $maxBytesPerChunk = intdiv($maxBase64Chars, 4) * 3;

        $chunks = self::splitOnCharBoundary($value, $maxBytesPerChunk);

        $words = array_map(
            static fn (string $chunk): string => '=?UTF-8?B?' . base64_encode($chunk) . '?=',
            $chunks,
        );

        return implode(' ', $words);
    }

    /**
     * @param array<Address|array{0: string, 1?: string|null}> $addrs
     */
    public static function foldAddressList(array $addrs): string
    {
        $parts = [];

        foreach ($addrs as $addr) {
            if ($addr instanceof Address) {
                $parts[] = self::nameAddr($addr->name ?? '', $addr->email);
                continue;
            }

            $email = $addr[0];
            $name = $addr[1] ?? '';
            $parts[] = self::nameAddr($name, $email);
        }

        return implode(', ', $parts);
    }

    public static function nameAddr(string $name, string $email): string
    {
        if ('' === $name) {
            return $email;
        }

        if (!self::isAsciiPrintable($name)) {
            return self::encode($name) . " <{$email}>";
        }

        if (self::hasSpecials($name)) {
            $escaped = str_replace('\\', '\\\\', $name);
            $escaped = str_replace('"', '\"', $escaped);

            return "\"{$escaped}\" <{$email}>";
        }

        return "{$name} <{$email}>";
    }

    /**
     * Folds a complete header line (`"Name: value"`, no trailing line
     * break) per RFC 5322 §2.2.3: a space already present in the value is
     * turned into a fold point (`"\r\n" . ' '`) wherever the physical
     * line would otherwise exceed {@see self::FOLD_SOFT_LIMIT} bytes, so
     * every space-delimited token — an RFC 2047 encoded word in
     * particular, since {@see self::encode()} already separates its
     * words with plain spaces — stays intact and each continuation line
     * is left starting with that same space (required by RFC 5322's
     * folding-whitespace rule, and exactly what
     * {@see Parser::parseHeaderBlock()} expects when it reassembles a
     * folded value by plain concatenation). A token with no space
     * anywhere near its start that alone would exceed
     * {@see self::FOLD_HARD_LIMIT} bytes (e.g. one absurdly long URL) is
     * force-split there instead, purely so the hard limit is never
     * exceeded; this is the only case where folding is not reversible by
     * simple concatenation.
     */
    public static function fold(string $headerLine): string
    {
        $lines = [];

        foreach (explode("\r\n", $headerLine) as $line) {
            array_push($lines, ...self::foldPhysicalLine($line));
        }

        return implode("\r\n", $lines);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function foldPhysicalLine(string $line): array
    {
        if (strlen($line) <= self::FOLD_SOFT_LIMIT) {
            return [$line];
        }

        $tokens = preg_split('/( )/', $line, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $tokens = false === $tokens ? [$line] : $tokens;

        $lines = [];
        $current = '';

        foreach ($tokens as $token) {
            // Only ever fold right before a word, by relocating the
            // space that already precedes it onto the new line: that
            // way the continuation line's leading space is the original
            // one, never an invented extra byte.
            if (' ' !== $token
                && '' !== $current
                && str_ends_with($current, ' ')
                && strlen($current) + strlen($token) > self::FOLD_SOFT_LIMIT
            ) {
                $lines[] = substr($current, 0, -1);
                $current = ' ';
            }

            if (strlen($token) > self::FOLD_HARD_LIMIT) {
                if ('' !== $current) {
                    $lines[] = $current;
                    $current = '';
                }

                array_push($lines, ...self::hardSplit($token));
                continue;
            }

            $current .= $token;
        }

        if ('' !== $current) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Force-splits a single space-free token that alone exceeds
     * {@see self::FOLD_HARD_LIMIT} bytes, since there is no space to fold
     * at. Every chunk after the first gets a synthetic leading space
     * (the one byte-for-byte reconstruction compromise {@see self::fold()}
     * ever makes), so each one is still a valid folding-whitespace
     * continuation line.
     *
     * @return non-empty-list<string>
     */
    private static function hardSplit(string $token): array
    {
        $chunkSize = self::FOLD_HARD_LIMIT - 1;
        $chunks = [];

        for ($offset = 0, $len = strlen($token); $offset < $len; $offset += $chunkSize) {
            $chunks[] = substr($token, $offset, $chunkSize);
        }

        for ($i = 1; $i < count($chunks); $i++) {
            $chunks[$i] = ' ' . $chunks[$i];
        }

        return [] === $chunks ? [''] : $chunks;
    }

    /**
     * @return list<string>
     */
    private static function splitOnCharBoundary(string $value, int $maxBytesPerChunk): array
    {
        $chars = mb_str_split($value, 1, 'UTF-8');
        $chunks = [];
        $current = '';
        $currentLen = 0;

        foreach ($chars as $char) {
            $charLen = strlen($char);

            if ($currentLen > 0 && $currentLen + $charLen > $maxBytesPerChunk) {
                $chunks[] = $current;
                $current = '';
                $currentLen = 0;
            }

            $current .= $char;
            $currentLen += $charLen;
        }

        if ('' !== $current) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function isAsciiPrintable(string $value): bool
    {
        return 1 === preg_match('/^[\x20-\x7E]*$/', $value);
    }

    private static function hasSpecials(string $value): bool
    {
        $len = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            if (str_contains(self::SPECIALS, $value[$i])) {
                return true;
            }
        }

        return false;
    }
}
