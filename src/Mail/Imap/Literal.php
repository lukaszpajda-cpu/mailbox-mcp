<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

/**
 * String helpers for the IMAP wire format: quoting, the
 * "does this need a literal" test, modified UTF-7 mailbox names
 * (RFC 3501 §5.1.3) and UID set compression.
 */
final class Literal
{
    /**
     * Quotes a string for the wire: rejects CR/LF/NUL (which could end the
     * command and inject another) and escapes `\` and `"`.
     *
     * @throws ImapException
     */
    public static function quote(string $s): string
    {
        self::assertSafe($s);

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /**
     * @throws ImapException when $s holds CR, LF or NUL
     */
    public static function assertSafe(string $s, string $what = 'IMAP argument'): void
    {
        if (strpbrk($s, "\r\n\0") !== false) {
            throw new ImapException("{$what} contains forbidden characters (CR, LF or NUL)");
        }
    }

    /**
     * True when $s cannot travel as a plain quoted string: it holds `"`,
     * `\`, a control character or a non-ASCII byte.
     */
    public static function needsLiteral(string $s): bool
    {
        return 1 === preg_match('/[\x00-\x1f\x7f-\xff"\\\\]/', $s);
    }

    /**
     * UTF-8 folder name => quoted modified UTF-7 mailbox argument.
     *
     * @throws ImapException
     */
    public static function mailbox(string $name): string
    {
        self::assertSafe($name, 'Folder name');

        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new ImapException('Folder name is not valid UTF-8');
        }

        return self::quote(self::encodeMailbox($name));
    }

    public static function encodeMailbox(string $name): string
    {
        if (1 !== preg_match('/[&\x80-\xff]/', $name)) {
            return $name;
        }

        return mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }

    public static function decodeMailbox(string $name): string
    {
        if (!str_contains($name, '&')) {
            return $name;
        }

        return mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    }

    /**
     * [5, 1, 3, 2] => "1:3,5"; non-positive uids are dropped.
     *
     * @param array<int, int> $uids
     */
    public static function uidSet(array $uids): string
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        sort($uids);

        $ranges = [];
        $count = count($uids);

        for ($i = 0; $i < $count; ++$i) {
            $start = $uids[$i];

            while ($i + 1 < $count && $uids[$i + 1] === $uids[$i] + 1) {
                ++$i;
            }

            $ranges[] = $start === $uids[$i] ? (string) $start : "{$start}:{$uids[$i]}";
        }

        return implode(',', $ranges);
    }
}
