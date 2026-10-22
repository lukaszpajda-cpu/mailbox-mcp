<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Content-Transfer-Encoding decoding for MIME body parts.
 */
final class Transfer
{
    /**
     * Decodes `base64` (whitespace and stray non-alphabet bytes ignored,
     * best effort on damaged input) and `quoted-printable`. `7bit`,
     * `8bit`, `binary`, an empty value and unknown encodings return the
     * data unchanged.
     */
    public static function decode(string $data, string $encoding): string
    {
        switch (strtolower(trim($encoding))) {
            case 'base64':
                return self::base64($data);
            case 'quoted-printable':
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }

    /**
     * Padding ends a chunk: some encoders concatenate separately padded
     * blocks, so each `…=` run is decoded on its own. Bytes outside the
     * alphabet are dropped and a dangling single sextet (which cannot
     * encode a byte) is ignored.
     */
    private static function base64(string $data): string
    {
        $clean = preg_replace('~[^A-Za-z0-9+/=]~', '', $data) ?? '';

        if (false === preg_match_all('~[A-Za-z0-9+/]+~', $clean, $m)) {
            return '';
        }

        $out = '';

        foreach ($m[0] as $chunk) {
            if (1 === strlen($chunk) % 4) {
                $chunk = substr($chunk, 0, -1);
            }

            $out .= base64_decode($chunk, false);
        }

        return $out;
    }
}
