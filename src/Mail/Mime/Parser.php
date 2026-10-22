<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Parses a raw RFC 5322 / MIME message (or a bare header block such as
 * the result of `BODY.PEEK[HEADER]`) into a {@see ParsedMessage}.
 *
 * Used for drafts the server created itself, for `send_draft`'s header
 * surgery and for fetched header blocks — not as a general-purpose
 * reader of arbitrary large mail (bodies are held in memory). Every
 * entry point tolerates malformed or binary input without throwing or
 * emitting warnings: a missing or unparsable Content-Type is
 * `text/plain; charset=us-ascii`, a multipart without a boundary is read
 * as plain text, an unterminated multipart ends at end of input, and
 * nesting deeper than {@see self::MAX_DEPTH} is treated as a text leaf.
 */
final class Parser
{
    private const MAX_DEPTH = 32;

    private const HEADER_LINE = '/^([!-9;-~]+)[ \t]*:(.*)$/s';

    public static function parse(string $raw): ParsedMessage
    {
        [$rawHeaders, $rawBody] = self::split($raw);
        $headers = self::parseHeaderBlock($rawHeaders);

        $text = null;
        $html = null;
        $attachments = [];

        self::walk($headers, $rawBody, '', 0, $text, $html, $attachments);

        return new ParsedMessage($headers, $text, $html, $attachments, $rawHeaders, $rawBody);
    }

    /**
     * Unfolds a header block into `[name, rawValue]` pairs, preserving
     * order and duplicates. `rawValue` is the text after the colon with
     * line breaks removed (continuation whitespace kept) and outer
     * blanks trimmed; it is not RFC 2047 decoded. Lines that are neither
     * a `Name: value` header nor a continuation are skipped.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parseHeaderBlock(string $raw): array
    {
        $headers = [];

        foreach (self::headerLineGroups($raw) as $group) {
            if (null === $group['name']) {
                continue;
            }

            $first = $group['lines'][0];
            $colon = strpos($first, ':');
            $value = false === $colon ? '' : substr($first, $colon + 1);

            foreach (array_slice($group['lines'], 1) as $continuation) {
                $value .= $continuation;
            }

            $headers[] = [$group['name'], trim($value, " \t")];
        }

        return $headers;
    }

    /**
     * Groups physical header lines (line endings stripped) into logical
     * fields: a `Name:` line plus its folded continuation lines. Lines
     * that are not headers form groups with a null name so they can be
     * re-serialised untouched.
     *
     * @internal used by {@see ParsedMessage} for header surgery
     *
     * @return list<array{name: string|null, lines: non-empty-list<string>}>
     */
    public static function headerLineGroups(string $raw): array
    {
        $groups = [];
        $name = null;
        $lines = [];

        foreach ('' === $raw ? [] : explode("\n", $raw) as $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            if ('' === $line) {
                continue;
            }

            if ((' ' === $line[0] || "\t" === $line[0]) && [] !== $lines) {
                $lines[] = $line;
                continue;
            }

            if ([] !== $lines) {
                $groups[] = ['name' => $name, 'lines' => $lines];
            }

            $name = 1 === preg_match(self::HEADER_LINE, $line, $m) ? $m[1] : null;
            $lines = [$line];
        }

        if ([] !== $lines) {
            $groups[] = ['name' => $name, 'lines' => $lines];
        }

        return $groups;
    }

    /**
     * Splits a structured header value such as a Content-Type into its
     * lowercased main value and its parameters (lowercased names, quoted
     * strings unquoted, RFC 2231 extended and continued values merged and
     * decoded to UTF-8).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function parseParams(string $value): array
    {
        $segments = self::splitSemicolons($value);
        $main = strtolower(trim(array_shift($segments) ?? ''));
        $raw = [];

        foreach ($segments as $segment) {
            $eq = strpos($segment, '=');

            if (false === $eq) {
                continue;
            }

            $key = strtolower(trim(substr($segment, 0, $eq)));

            if ('' === $key) {
                continue;
            }

            $raw[$key] = self::unquote(trim(substr($segment, $eq + 1)));
        }

        return [$main, self::decodeParams($raw)];
    }

    /**
     * Merges RFC 2231 parameter forms — `name*=charset'lang'%XX…` and
     * continuations `name*0*=…; name*1=…` — into plain `name => UTF-8`
     * entries. An extended or continued value wins over a plain one of
     * the same name.
     *
     * @param array<string, string> $raw lowercased names
     *
     * @return array<string, string>
     */
    public static function decodeParams(array $raw): array
    {
        $plain = [];
        $extended = [];
        $continued = [];

        foreach ($raw as $key => $value) {
            $key = (string) $key;

            if (1 === preg_match('/^(.+?)\*(\d{1,3})(\*?)$/', $key, $m)) {
                $continued[$m[1]][(int) $m[2]] = [$value, '*' === $m[3]];
            } elseif (1 === preg_match('/^(.+)\*$/', $key, $m)) {
                $extended[$m[1]] = self::decodeExtended($value);
            } else {
                $plain[$key] = $value;
            }
        }

        foreach ($continued as $name => $segments) {
            ksort($segments);
            $charset = '';
            $bytes = '';
            $first = true;

            foreach ($segments as [$value, $encoded]) {
                if ($encoded && $first) {
                    $pieces = explode("'", $value, 3);

                    if (3 === count($pieces)) {
                        $charset = $pieces[0];
                        $value = $pieces[2];
                    }
                }

                $bytes .= $encoded ? rawurldecode($value) : $value;
                $first = false;
            }

            if (!isset($extended[$name])) {
                $extended[$name] = Charset::toUtf8($bytes, $charset);
            }
        }

        foreach ($extended as $name => $value) {
            $plain[$name] = $value;
        }

        return $plain;
    }

    /**
     * The attachment filename: Content-Disposition `filename` (RFC 2231
     * forms already merged by {@see self::decodeParams()}), else
     * Content-Type `name`; RFC 2047 encoded words decoded, control
     * characters removed, invalid UTF-8 scrubbed. Null when absent/empty.
     *
     * @param array<string, string> $dispositionParams
     * @param array<string, string> $typeParams
     */
    public static function filenameFrom(array $dispositionParams, array $typeParams): ?string
    {
        $name = $dispositionParams['filename'] ?? $typeParams['name'] ?? null;

        if (null === $name) {
            return null;
        }

        if (str_contains($name, '=?')) {
            $name = HeaderDecoder::decode($name);
        }

        $name = trim(self::scrub(preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? ''));

        return '' === $name ? null : $name;
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param list<Part>                        $attachments
     */
    private static function walk(
        array $headers,
        string $body,
        string $section,
        int $depth,
        ?string &$text,
        ?string &$html,
        array &$attachments,
    ): void {
        $contentType = self::first($headers, 'content-type');
        [$mime, $params] = null === $contentType ? ['text/plain', []] : self::parseParams($contentType);

        $slash = strpos($mime, '/');
        $type = false === $slash ? '' : trim(substr($mime, 0, $slash));
        $subtype = false === $slash ? '' : trim(substr($mime, $slash + 1));

        if ('' === $type || '' === $subtype) {
            $type = 'text';
            $subtype = 'plain';
        }

        $boundary = $params['boundary'] ?? '';

        if ('multipart' === $type && '' !== $boundary && $depth < self::MAX_DEPTH) {
            $index = 0;

            foreach (self::splitMultipart($body, $boundary) as $entity) {
                ++$index;
                [$childHeaders, $childBody] = self::split($entity);
                $childSection = '' === $section ? (string) $index : $section . '.' . $index;
                self::walk(self::parseHeaderBlock($childHeaders), $childBody, $childSection, $depth + 1, $text, $html, $attachments);
            }

            return;
        }

        if ('multipart' === $type) {
            $type = 'text';
            $subtype = 'plain';
        }

        $part = new Part();
        $part->section = '' === $section ? '1' : $section;
        $part->type = $type;
        $part->subtype = $subtype;
        $part->params = $params;
        $part->encoding = strtolower(trim(self::first($headers, 'content-transfer-encoding') ?? ''));
        $part->charset = strtolower($params['charset'] ?? ('text' === $type ? 'us-ascii' : ''));

        $dispositionParams = [];
        $disposition = self::first($headers, 'content-disposition');

        if (null !== $disposition) {
            [$dispositionValue, $dispositionParams] = self::parseParams($disposition);
            $part->disposition = '' === $dispositionValue ? null : $dispositionValue;
        }

        $part->filename = self::filenameFrom($dispositionParams, $params);

        $decoded = Transfer::decode($body, $part->encoding);
        $part->size = strlen($decoded);

        $isBodyText = 'attachment' !== $part->disposition && 'text' === $type;

        if ($isBodyText && 'plain' === $subtype) {
            if (null === $text) {
                $text = self::scrub(Charset::toUtf8($decoded, $part->charset));

                return;
            }
        } elseif ($isBodyText && 'html' === $subtype) {
            if (null === $html) {
                $html = self::scrub(Charset::toUtf8($decoded, $part->charset));

                return;
            }
        }

        // Every other leaf — including a second text/plain or text/html.

        $attachments[] = $part;
    }

    /**
     * Splits at the first blank line into [rawHeaders, rawBody]. A
     * leading blank line means no headers; with no blank line at all the
     * input is all headers if it starts like a header, else all body.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $raw): array
    {
        if (str_starts_with($raw, "\r\n")) {
            return ['', substr($raw, 2)];
        }

        if (str_starts_with($raw, "\n")) {
            return ['', substr($raw, 1)];
        }

        $crlf = strpos($raw, "\r\n\r\n");
        $lf = strpos($raw, "\n\n");

        if (false !== $lf && (false === $crlf || $lf < $crlf)) {
            return [substr($raw, 0, $lf), substr($raw, $lf + 2)];
        }

        if (false !== $crlf) {
            return [substr($raw, 0, $crlf), substr($raw, $crlf + 4)];
        }

        $firstLine = strtok($raw, "\n");

        if (false !== $firstLine && 1 === preg_match(self::HEADER_LINE, rtrim($firstLine, "\r"))) {
            return [rtrim($raw, "\r\n"), ''];
        }

        return ['', $raw];
    }

    /**
     * Body parts between `--boundary` lines, stopping at `--boundary--`
     * (or end of input when the close delimiter is missing). The line
     * break preceding a delimiter belongs to the delimiter.
     *
     * @return list<string>
     */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $open = '--' . $boundary;
        $close = $open . '--';
        $parts = [];
        $current = null;

        foreach (explode("\n", $body) as $line) {
            $trimmed = rtrim($line, "\r \t");

            if ($trimmed === $close) {
                if (null !== $current) {
                    $parts[] = self::joinLines($current);
                }

                return $parts;
            }

            if ($trimmed === $open) {
                if (null !== $current) {
                    $parts[] = self::joinLines($current);
                }

                $current = [];
                continue;
            }

            if (null !== $current) {
                $current[] = $line;
            }
        }

        if (null !== $current) {
            $parts[] = self::joinLines($current);
        }

        return $parts;
    }

    /**
     * @param list<string> $lines
     */
    private static function joinLines(array $lines): string
    {
        $joined = implode("\n", $lines);

        return str_ends_with($joined, "\r") ? substr($joined, 0, -1) : $joined;
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function first(array $headers, string $name): ?string
    {
        foreach ($headers as [$headerName, $value]) {
            if (0 === strcasecmp($headerName, $name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Splits on semicolons outside quoted strings.
     *
     * @return list<string>
     */
    private static function splitSemicolons(string $value): array
    {
        $segments = [];
        $current = '';
        $inQuotes = false;
        $len = strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];

            if ($inQuotes && '\\' === $ch && $i + 1 < $len) {
                $current .= $ch . $value[++$i];
                continue;
            }

            if ('"' === $ch) {
                $inQuotes = !$inQuotes;
            } elseif (';' === $ch && !$inQuotes) {
                $segments[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $segments[] = $current;

        return $segments;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) < 2 || '"' !== $value[0]) {
            return $value;
        }

        $inner = str_ends_with($value, '"') ? substr($value, 1, -1) : substr($value, 1);

        return preg_replace('/\\\\(.)/s', '$1', $inner) ?? $inner;
    }

    private static function decodeExtended(string $value): string
    {
        $pieces = explode("'", $value, 3);

        if (3 !== count($pieces)) {
            return rawurldecode($value);
        }

        return Charset::toUtf8(rawurldecode($pieces[2]), $pieces[0]);
    }

    private static function scrub(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_scrub($value, 'UTF-8');
    }
}
