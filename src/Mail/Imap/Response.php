<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

/**
 * One server response (a tagged, untagged or continuation line) with its
 * `{n}` literals kept as separate binary strings.
 *
 * Internally the line is "flattened": every literal is cut out and
 * replaced by a NUL-delimited marker (`\0<index>\0`; IMAP text never
 * carries NUL, and any stray NUL is stripped), so the line can be matched
 * with regexes and tokenised cheaply while multi-megabyte literals are
 * never scanned. {@see self::values()} and {@see self::fetch()} turn the
 * markers back into the literal bytes; {@see self::resolve()} substitutes
 * them as quoted strings (for the raw BODYSTRUCTURE handed to the MIME
 * layer).
 *
 * Parsed values: `NIL` => null, atoms / quoted strings / literals =>
 * string, parenthesised lists => list of values.
 */
final class Response
{
    private const MAX_DEPTH = 64;

    private readonly string $flat;

    /**
     * @param array<int, string> $texts    line pieces between literals (count = literals + 1), `{n}` markers removed
     * @param array<int, string> $literals literal payloads
     */
    public function __construct(array $texts, private readonly array $literals)
    {
        $flat = '';

        foreach (array_values($texts) as $i => $text) {
            $flat .= str_replace("\0", '', $text);

            if ($i < count($literals)) {
                $flat .= "\0{$i}\0";
            }
        }

        $this->flat = $flat;
    }

    /**
     * The line with literals as `\0<index>\0` markers.
     */
    public function line(): string
    {
        return $this->flat;
    }

    /**
     * The line with every literal substituted in as a quoted string.
     */
    public function text(): string
    {
        return $this->resolve($this->flat);
    }

    public function resolve(string $s): string
    {
        if (!str_contains($s, "\0")) {
            return $s;
        }

        return (string) preg_replace_callback(
            '/\x00(\d+)\x00/',
            fn (array $m): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $this->literals[(int) $m[1]] ?? '') . '"',
            $s,
        );
    }

    /**
     * Every top-level value from byte offset $pos to the end of the line.
     *
     * @return array<int, mixed>
     */
    public function values(int $pos): array
    {
        $out = [];
        $len = strlen($this->flat);

        while (true) {
            $this->skipSpaces($pos);

            if ($pos >= $len) {
                return $out;
            }

            if (')' === $this->flat[$pos]) {
                ++$pos;

                continue;
            }

            $out[] = $this->value($pos, 0);
        }
    }

    /**
     * Parses `* <seq> FETCH (<item> <value> ...)` into item => value.
     * Item names are uppercased with any `<origin>` partial suffix
     * removed, e.g. `BODY[1.2]<0>` => `BODY[1.2]`. The values of
     * BODYSTRUCTURE and BODY (without a section) are the raw
     * parenthesised text with literals resolved to quoted strings.
     *
     * @return ?array<string, mixed> null when this is not a FETCH response
     */
    public function fetch(): ?array
    {
        if (1 !== preg_match('/^\* \d+ FETCH \(/i', $this->flat, $m)) {
            return null;
        }

        $pos = strlen($m[0]);
        $len = strlen($this->flat);
        $items = [];

        while (true) {
            $this->skipSpaces($pos);

            if ($pos >= $len || ')' === $this->flat[$pos]) {
                return $items;
            }

            $name = $this->atom($pos);

            if ('' === $name) {
                // Not an item name (stray token): skip one value to make progress.
                $this->value($pos, 0);

                continue;
            }

            $key = (string) preg_replace('/<\d+>$/', '', strtoupper($name));
            $this->skipSpaces($pos);
            $start = $pos;
            $value = $this->value($pos, 0);

            if ('BODYSTRUCTURE' === $key || 'BODY' === $key) {
                $value = $this->resolve(substr($this->flat, $start, $pos - $start));
            }

            $items[$key] = $value;
        }
    }

    private function skipSpaces(int &$pos): void
    {
        $len = strlen($this->flat);

        while ($pos < $len && ' ' === $this->flat[$pos]) {
            ++$pos;
        }
    }

    private function value(int &$pos, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ImapException('Malformed IMAP response: nesting too deep');
        }

        $len = strlen($this->flat);

        if ($pos >= $len) {
            return null;
        }

        $ch = $this->flat[$pos];

        if ('(' === $ch) {
            ++$pos;
            $list = [];

            while (true) {
                $this->skipSpaces($pos);

                if ($pos >= $len) {
                    return $list;
                }

                if (')' === $this->flat[$pos]) {
                    ++$pos;

                    return $list;
                }

                $list[] = $this->value($pos, $depth + 1);
            }
        }

        if ('"' === $ch) {
            ++$pos;
            $out = '';

            while ($pos < $len && '"' !== $this->flat[$pos]) {
                if ('\\' === $this->flat[$pos] && $pos + 1 < $len) {
                    ++$pos;
                }

                $out .= $this->flat[$pos];
                ++$pos;
            }

            ++$pos;

            return $out;
        }

        if ("\0" === $ch) {
            $end = strpos($this->flat, "\0", $pos + 1);

            if (false === $end) {
                $pos = $len;

                return '';
            }

            $index = (int) substr($this->flat, $pos + 1, $end - $pos - 1);
            $pos = $end + 1;

            return $this->literals[$index] ?? '';
        }

        if (')' === $ch) {
            // Unbalanced close at this level: consume it, yield nothing useful.
            ++$pos;

            return null;
        }

        $atom = $this->atom($pos);

        return 'NIL' === strtoupper($atom) ? null : $atom;
    }

    /**
     * An atom; `[...]` sections (which may hold spaces and parens) and a
     * trailing `<n>` are part of it, e.g. `BODY[HEADER.FIELDS (FROM)]<0>`.
     */
    private function atom(int &$pos): string
    {
        $len = strlen($this->flat);
        $start = $pos;
        $bracket = 0;

        while ($pos < $len) {
            $ch = $this->flat[$pos];

            if ('[' === $ch) {
                ++$bracket;
            } elseif (']' === $ch && $bracket > 0) {
                --$bracket;
            } elseif (0 === $bracket && (' ' === $ch || '(' === $ch || ')' === $ch || '"' === $ch || "\0" === $ch)) {
                break;
            } elseif ("\0" === $ch) {
                break;
            }

            ++$pos;
        }

        return substr($this->flat, $start, $pos - $start);
    }
}
