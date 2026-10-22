<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

use Throwable;

/**
 * Parses an IMAP `BODYSTRUCTURE` response (RFC 3501 §7.4.2 / RFC 9051
 * §7.5.2) into a {@see Part} tree with IMAP section numbers, so callers
 * can pick the body text part to fetch (partially) and list attachments
 * without downloading them.
 *
 * Input is the parenthesised list exactly as it follows `BODYSTRUCTURE `
 * in a FETCH response, with any `{n}` literals already resolved into
 * quoted strings by the IMAP client. The parser never throws: missing
 * fields fall back to defaults, unbalanced parentheses are closed at end
 * of input, unknown trailing extension data is ignored and nesting is
 * capped at {@see self::MAX_DEPTH}.
 */
final class BodyStructure
{
    private const MAX_DEPTH = 64;

    public static function parse(string $raw): Part
    {
        $tokens = self::tokenize($raw);
        $pos = 0;
        $tree = self::readList($tokens, $pos, 0, false);

        // `(...)` normally yields a single top-level list; tolerate input
        // given without the outer parentheses.
        if (1 === count($tree) && is_array($tree[0])) {
            $tree = array_values($tree[0]);
        }

        return self::buildNode($tree, '', 0);
    }

    /**
     * A defensive variant of {@see self::parse()} for callers that cannot
     * tell a real `BODYSTRUCTURE` from garbage by any other means (e.g. a
     * server bug, or a fake in a test): returns null, rather than a
     * best-effort (and misleading) `Part`, when `$raw` is not at least
     * plausibly a parenthesised list — empty, not starting with `(`, or
     * with parentheses that do not balance when counted outside quoted
     * strings — or when {@see self::parse()} unexpectedly throws.
     */
    public static function tryParse(string $raw): ?Part
    {
        $trimmed = trim($raw);

        if ('' === $trimmed || !str_starts_with($trimmed, '(') || !self::parenthesesBalance($trimmed)) {
            return null;
        }

        try {
            return self::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True when every `(` has a matching `)`, counting only parentheses
     * that fall outside a quoted string (backslash-escaped characters
     * inside a quoted string, including `\"`, never end it).
     */
    private static function parenthesesBalance(string $s): bool
    {
        $depth = 0;
        $inQuotes = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; ++$i) {
            $ch = $s[$i];

            if ($inQuotes) {
                if ('\\' === $ch && $i + 1 < $len) {
                    ++$i;
                } elseif ('"' === $ch) {
                    $inQuotes = false;
                }

                continue;
            }

            if ('"' === $ch) {
                $inQuotes = true;
            } elseif ('(' === $ch) {
                ++$depth;
            } elseif (')' === $ch) {
                --$depth;

                if ($depth < 0) {
                    return false;
                }
            }
        }

        return 0 === $depth && !$inQuotes;
    }

    /**
     * Depth-first: the first `text/plain` leaf that is not an attachment,
     * else the first such `text/html` leaf, else null.
     */
    public static function firstText(Part $root): ?Part
    {
        return self::findLeaf($root, 'text/plain') ?? self::findLeaf($root, 'text/html');
    }

    /**
     * Every leaf part for which {@see Part::isAttachment()} holds, in
     * section order.
     *
     * @return list<Part>
     */
    public static function attachments(Part $root): array
    {
        $out = [];
        self::collect($root, $out);

        return $out;
    }

    private static function findLeaf(Part $part, string $mime): ?Part
    {
        if ([] === $part->children) {
            return $part->mime() === $mime && !$part->isAttachment() ? $part : null;
        }

        foreach ($part->children as $child) {
            $found = self::findLeaf($child, $mime);

            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<Part> $out
     */
    private static function collect(Part $part, array &$out): void
    {
        if ([] === $part->children) {
            if (!$part->isMultipart() && $part->isAttachment()) {
                $out[] = $part;
            }

            return;
        }

        foreach ($part->children as $child) {
            self::collect($child, $out);
        }
    }

    /**
     * Splits into `(`, `)`, quoted strings (`\"` / `\\` unescaped),
     * `NIL` and other atoms. Each token is `[kind, value]` where kind is
     * `(`, `)`, `s` (string or atom) or `n` (NIL).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;

        while ($i < $len) {
            $ch = $s[$i];

            if ('(' === $ch || ')' === $ch) {
                $tokens[] = [$ch, $ch];
                ++$i;
                continue;
            }

            if (' ' === $ch || "\t" === $ch || "\r" === $ch || "\n" === $ch) {
                ++$i;
                continue;
            }

            if ('"' === $ch) {
                ++$i;
                $str = '';

                while ($i < $len && '"' !== $s[$i]) {
                    if ('\\' === $s[$i] && $i + 1 < $len) {
                        ++$i;
                    }

                    $str .= $s[$i];
                    ++$i;
                }

                ++$i; // closing quote (or end of input)
                $tokens[] = ['s', $str];
                continue;
            }

            $start = $i;

            while ($i < $len && false === strpos("() \t\r\n\"", $s[$i])) {
                ++$i;
            }

            $atom = substr($s, $start, $i - $start);
            $tokens[] = 0 === strcasecmp($atom, 'NIL') ? ['n', ''] : ['s', $atom];
        }

        return $tokens;
    }

    /**
     * Reads items until the matching `)` (or end of input) into a nested
     * array where NIL is null, strings are strings and lists are arrays.
     * Lists nested deeper than MAX_DEPTH are skipped wholesale.
     *
     * @param list<array{0: string, 1: string}> $tokens
     *
     * @return list<mixed>
     */
    private static function readList(array $tokens, int &$pos, int $depth, bool $nested): array
    {
        $items = [];
        $count = count($tokens);

        while ($pos < $count) {
            [$kind, $value] = $tokens[$pos];
            ++$pos;

            if (')' === $kind) {
                if ($nested) {
                    return $items;
                }

                continue; // stray close paren at top level
            }

            if ('(' === $kind) {
                if ($depth >= self::MAX_DEPTH) {
                    self::skipList($tokens, $pos);
                    $items[] = [];
                    continue;
                }

                $items[] = self::readList($tokens, $pos, $depth + 1, true);
                continue;
            }

            $items[] = 'n' === $kind ? null : $value;
        }

        return $items;
    }

    /**
     * @param list<array{0: string, 1: string}> $tokens
     */
    private static function skipList(array $tokens, int &$pos): void
    {
        $depth = 1;
        $count = count($tokens);

        while ($pos < $count && $depth > 0) {
            $kind = $tokens[$pos][0];

            if ('(' === $kind) {
                ++$depth;
            } elseif (')' === $kind) {
                --$depth;
            }

            ++$pos;
        }
    }

    /**
     * @param list<mixed> $node
     */
    private static function buildNode(array $node, string $section, int $depth): Part
    {
        $part = new Part();

        if (isset($node[0]) && is_array($node[0])) {
            return self::buildMultipart($part, $node, $section, $depth);
        }

        $part->section = '' === $section ? '1' : $section;
        $part->type = self::lower($node[0] ?? null, 'text');
        $part->subtype = self::lower($node[1] ?? null, 'plain');
        $part->params = self::params($node[2] ?? null);
        $part->encoding = self::lower($node[5] ?? null, '');
        $part->size = self::int($node[6] ?? null);

        // Leaf extension data starts after the type-specific fields.
        $extAt = 7;

        if ('text' === $part->type) {
            $extAt = 8; // body-fld-lines
        } elseif ('message' === $part->type && 'rfc822' === $part->subtype) {
            $extAt = 10; // envelope, body, body-fld-lines — not descended into
        }

        // Extension: md5, then disposition, language, location, unknown…
        $disposition = $node[$extAt + 1] ?? null;
        $dispParams = [];

        if (is_array($disposition) && isset($disposition[0]) && is_string($disposition[0])) {
            $part->disposition = strtolower($disposition[0]);
            $dispParams = self::params($disposition[1] ?? null);
        }

        $part->filename = Parser::filenameFrom($dispParams, $part->params);
        $part->charset = $part->params['charset'] ?? ('text' === $part->type ? 'us-ascii' : '');
        $part->charset = strtolower($part->charset);

        return $part;
    }

    /**
     * @param list<mixed> $node
     */
    private static function buildMultipart(Part $part, array $node, string $section, int $depth): Part
    {
        $part->section = $section;
        $part->type = 'multipart';

        $i = 0;
        $index = 0;
        $count = count($node);

        while ($i < $count && is_array($node[$i])) {
            ++$index;
            $childSection = '' === $section ? (string) $index : $section . '.' . $index;
            $child = $node[$i];
            $part->children[] = $depth < self::MAX_DEPTH && [] !== $child
                ? self::buildNode(array_values($child), $childSection, $depth + 1)
                : self::emptyLeaf($childSection);
            ++$i;
        }

        $part->subtype = self::lower($node[$i] ?? null, 'mixed');
        $part->params = self::params($node[$i + 1] ?? null);

        $disposition = $node[$i + 2] ?? null;

        if (is_array($disposition) && isset($disposition[0]) && is_string($disposition[0])) {
            $part->disposition = strtolower($disposition[0]);
        }

        return $part;
    }

    private static function emptyLeaf(string $section): Part
    {
        $part = new Part();
        $part->section = $section;
        $part->type = 'application';
        $part->subtype = 'octet-stream';

        return $part;
    }

    /**
     * A `("key" "value" …)` list as lowercased-key map, with RFC 2231
     * extended values (`name*`, `name*0*`, …) merged and decoded.
     *
     * @return array<string, string>
     */
    private static function params(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $raw = [];
        $values = array_values($list);
        $count = count($values);

        for ($i = 0; $i + 1 < $count; $i += 2) {
            $key = $values[$i];
            $value = $values[$i + 1];

            if (is_string($key) && is_string($value) && '' !== $key) {
                $raw[strtolower($key)] = $value;
            }
        }

        return Parser::decodeParams($raw);
    }

    private static function lower(mixed $value, string $default): string
    {
        return is_string($value) && '' !== $value ? strtolower($value) : $default;
    }

    private static function int(mixed $value): int
    {
        return is_string($value) && 1 === preg_match('/^\d{1,18}$/', $value) ? (int) $value : 0;
    }
}
