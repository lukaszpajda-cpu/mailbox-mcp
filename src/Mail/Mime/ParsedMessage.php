<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * Result of {@see Parser::parse()}: decoded header accessors, the first
 * text/plain and text/html bodies (UTF-8), attachments as {@see Part}
 * objects, and the raw header/body bytes for header surgery
 * ({@see self::withoutHeader()}, {@see self::withHeaderReplaced()}).
 */
final class ParsedMessage
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Matches a `<…>` msg-id token, byte-wise (no `u` modifier, so any
     * non-ASCII byte of a multi-byte UTF-8 sequence fails the class and
     * breaks the match): RFC 5322 msg-id characters only (excludes `<`,
     * `>`, whitespace, non-ASCII and control bytes), length-capped at 250
     * interior characters, and required to contain an `@` — real
     * Message-IDs are always `id-left "@" id-right`, and requiring it is
     * what keeps a caller-controlled token with no `@` at all (e.g.
     * `</email_content>` or `<IGNORE_ALL_PREVIOUS_INSTRUCTIONS>`, both of
     * which consist entirely of otherwise-legal msg-id characters) from
     * ever being read back as a Message-ID/In-Reply-To/References value.
     */
    private const ID_PATTERN = '/<(?=[\x21-\x3B\x3D\x3F-\x7E]*@)[\x21-\x3B\x3D\x3F-\x7E]{1,250}>/';

    /** {@see self::references()} never returns more than this many ids. */
    private const MAX_REFERENCES = 50;

    /** RFC 5322 §4.3 obsolete zone names. */
    private const ZONES = [
        'UT' => '+0000', 'UTC' => '+0000', 'GMT' => '+0000', 'Z' => '+0000',
        'EST' => '-0500', 'EDT' => '-0400', 'CST' => '-0600', 'CDT' => '-0500',
        'MST' => '-0700', 'MDT' => '-0600', 'PST' => '-0800', 'PDT' => '-0700',
    ];

    /**
     * @param list<array{0: string, 1: string}> $headerList [name, rawValue] in order
     * @param list<Part>                        $attachments
     */
    public function __construct(
        private readonly array $headerList,
        public readonly ?string $text,
        public readonly ?string $html,
        public readonly array $attachments,
        public readonly string $rawHeaders,
        public readonly string $rawBody,
    ) {
    }

    /** First occurrence, RFC 2047 decoded; null when absent. */
    public function header(string $name): ?string
    {
        $raw = $this->rawHeader($name);

        return null === $raw ? null : HeaderDecoder::decode($raw);
    }

    /**
     * All occurrences, decoded, in order.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        $out = [];

        foreach ($this->headerList as [$headerName, $value]) {
            if (0 === strcasecmp($headerName, $name)) {
                $out[] = HeaderDecoder::decode($value);
            }
        }

        return $out;
    }

    /**
     * Addresses of the first occurrence, parsed from the raw (undecoded)
     * value so encoded display names cannot inject separators.
     *
     * @return list<Address>
     */
    public function addresses(string $name): array
    {
        $raw = $this->rawHeader($name);

        return null === $raw ? [] : Address::parseList($raw);
    }

    public function messageId(): ?string
    {
        return $this->ids('Message-ID')[0] ?? null;
    }

    public function inReplyTo(): ?string
    {
        return $this->ids('In-Reply-To')[0] ?? null;
    }

    /**
     * The last {@see self::MAX_REFERENCES} ids only (the ones closest to
     * the immediate parent, per RFC 5322's "earliest ancestor first"
     * convention), so a message with an absurdly long References header
     * cannot force unbounded downstream work (e.g. {@see \MailboxMcp\Mail\ThreadCollector}).
     *
     * @return list<string>
     */
    public function references(): array
    {
        $ids = $this->ids('References');

        return count($ids) > self::MAX_REFERENCES ? array_slice($ids, -self::MAX_REFERENCES) : $ids;
    }

    /**
     * The Date header per RFC 5322 (obsolete forms included): the day
     * name is ignored, two-digit years map 00–49 → 20xx and 50–99 → 19xx,
     * named zones (UT, GMT, EST…) are honoured and unknown ones read as
     * +0000. Anything else — relative phrases, rolled-over dates such as
     * 31 Feb, out-of-range times — gives null rather than a guess.
     */
    public function date(): ?\DateTimeImmutable
    {
        $raw = $this->rawHeader('Date');

        if (null === $raw) {
            return null;
        }

        $clean = preg_replace('/\([^()]*\)/', ' ', $raw) ?? $raw;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
        $clean = preg_replace('/^[A-Za-z]{3},\s*/', '', $clean) ?? $clean;

        $pattern = '/^(\d{1,2}) ([A-Za-z]{3}) (\d{2,4}) (\d{1,2}):(\d{2})(?::(\d{2}))?(?: ([+-]\d{4}|[A-Za-z]{1,5}))?$/';

        if (1 !== preg_match($pattern, $clean, $m)) {
            return null;
        }

        $month = self::MONTHS[strtolower($m[2])] ?? null;
        $day = (int) $m[1];
        $year = (int) $m[3];
        $hour = (int) $m[4];
        $minute = (int) $m[5];
        $second = '' === ($m[6] ?? '') ? 0 : (int) $m[6];

        if (2 === strlen($m[3])) {
            $year += $year < 50 ? 2000 : 1900;
        } elseif (3 === strlen($m[3])) {
            $year += 1900;
        }

        if (null === $month || !checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }

        $second = min($second, 59); // leap second

        $zone = $m[7] ?? '';

        if ('' === $zone) {
            $offset = '+0000';
        } elseif ('+' === $zone[0] || '-' === $zone[0]) {
            if ((int) substr($zone, 3, 2) > 59) {
                return null;
            }

            $offset = $zone;
        } else {
            $offset = self::ZONES[strtoupper($zone)] ?? '+0000';
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s O',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d %s', $year, $month, $day, $hour, $minute, $second, $offset),
        );

        return false === $date ? null : $date;
    }

    /**
     * The message with every occurrence of the header (including its
     * folded continuation lines) removed; header lines re-emitted with
     * CRLF, followed by a blank line and the untouched raw body.
     */
    public function withoutHeader(string $name): string
    {
        $lines = [];

        foreach (Parser::headerLineGroups($this->rawHeaders) as $group) {
            if (null !== $group['name'] && 0 === strcasecmp($group['name'], $name)) {
                continue;
            }

            array_push($lines, ...$group['lines']);
        }

        return $this->assemble($lines);
    }

    /**
     * Replaces the first occurrence of the header in place (later
     * duplicates are kept, the existing name spelling is reused), or appends it at the end of the header block.
     * Line breaks in $value are flattened to a space so the value cannot
     * inject further headers; $value is emitted as given otherwise (the
     * caller encodes non-ASCII via {@see HeaderEncoder}).
     */
    public function withHeaderReplaced(string $name, string $value): string
    {
        if (1 !== preg_match('/^[!-9;-~]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid header name.');
        }

        $value = trim(preg_replace('/[\r\n]+[ \t]*/', ' ', $value) ?? '');
        $lines = [];
        $replaced = false;

        foreach (Parser::headerLineGroups($this->rawHeaders) as $group) {
            if (!$replaced && null !== $group['name'] && 0 === strcasecmp($group['name'], $name)) {
                $lines[] = $group['name'] . ': ' . $value; // keep the existing spelling
                $replaced = true;
                continue;
            }

            array_push($lines, ...$group['lines']);
        }

        if (!$replaced) {
            $lines[] = $name . ': ' . $value;
        }

        return $this->assemble($lines);
    }

    private function rawHeader(string $name): ?string
    {
        foreach ($this->headerList as [$headerName, $value]) {
            if (0 === strcasecmp($headerName, $name)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function ids(string $header): array
    {
        $raw = $this->rawHeader($header);

        if (null === $raw || false === preg_match_all(self::ID_PATTERN, $raw, $m)) {
            return [];
        }

        return $m[0];
    }

    /**
     * @param list<string> $lines
     */
    private function assemble(array $lines): string
    {
        $head = [] === $lines ? '' : implode("\r\n", $lines) . "\r\n";

        return $head . "\r\n" . $this->rawBody;
    }
}
