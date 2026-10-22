<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Converts raw bytes in a named charset to UTF-8. Used by
 * {@see HeaderDecoder} for RFC 2047 encoded-word payloads, and available
 * for body-part charset conversion elsewhere.
 *
 * `ext-iconv` and `ext-intl` are optional; only `ext-mbstring` is a hard
 * dependency of this project. When `iconv` is unavailable, conversion
 * falls back to `mb_convert_encoding`, guarded against unsupported
 * encoding names. Any conversion failure returns the input unchanged
 * rather than throwing, since header decoding must never blow up on
 * malformed or exotic mail.
 */
final class Charset
{
    /**
     * Common charset aliases seen in the wild, normalised to the names
     * `iconv`/`mbstring` expect.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'LATIN2' => 'ISO-8859-2',
        'CP1250' => 'Windows-1250',
        'WINDOWS-1250' => 'Windows-1250',
        'CP1252' => 'Windows-1252',
        'LATIN1' => 'ISO-8859-1',
    ];

    /**
     * Charset names that pass through unchanged (already UTF-8, or
     * ASCII which is a strict subset of UTF-8).
     *
     * @var list<string>
     */
    private const PASSTHROUGH = ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII', ''];

    public static function toUtf8(string $data, string $charset): string
    {
        $normalized = strtoupper(trim($charset));

        if (in_array($normalized, self::PASSTHROUGH, true)) {
            return $data;
        }

        $resolved = self::ALIASES[$normalized] ?? $charset;

        if (function_exists('iconv')) {
            $converted = @iconv($resolved, 'UTF-8//IGNORE', $data);

            if (false !== $converted) {
                return $converted;
            }
        }

        if (!self::isSupportedByMbstring($resolved)) {
            return $data;
        }

        try {
            $converted = @mb_convert_encoding($data, 'UTF-8', $resolved);
        } catch (\ValueError) {
            return $data;
        }

        return false === $converted ? $data : $converted;
    }

    private static function isSupportedByMbstring(string $charset): bool
    {
        /** @var list<string>|null $known */
        static $known = null;

        if (null === $known) {
            $known = array_map('strtoupper', mb_list_encodings());
        }

        return in_array(strtoupper($charset), $known, true);
    }
}
