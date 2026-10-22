<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Mime;

/**
 * A single parsed mailbox: an addr-spec (`local@domain`) with an optional
 * display name. Used wherever mail headers carry recipients or senders —
 * search results, get_email, draft building, and send_draft's recipient
 * extraction.
 *
 * {@see self::parseList()} is the only entry point that should ever see
 * untrusted header text; a display name is never allowed to introduce a
 * spurious recipient (a comma inside a quoted display name must not be
 * treated as a separator), and every returned email has already passed
 * {@see self::isValid()}.
 */
final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
    ) {
    }

    /**
     * Parses an RFC 5322 address-list header value (the body of a `To`,
     * `Cc`, `Bcc` or `From` header) into a flat list of addresses.
     *
     * Handles: bare addr-spec, angle-addr with a quoted or unquoted
     * display name (RFC 2047 encoded words decoded), comments in
     * parentheses (ignored), and groups (`name: a@b, c@d;`), whose
     * members are flattened into the result and whose group name is
     * discarded. Entries whose address fails {@see self::isValid()} are
     * skipped rather than causing the whole header to fail.
     *
     * @return list<self>
     */
    public static function parseList(string $header): array
    {
        $clean = self::stripComments($header);
        $segments = self::splitTopLevel($clean);

        $addresses = [];

        foreach ($segments as $segment) {
            foreach (self::parseItem($segment) as $addr) {
                $addresses[] = $addr;
            }
        }

        return $addresses;
    }

    public static function isValid(string $email): bool
    {
        if ('' === $email || strlen($email) > 254) {
            return false;
        }

        if (1 === preg_match('/[\s<>]/', $email)) {
            return false;
        }

        if (1 !== substr_count($email, '@')) {
            return false;
        }

        $atPos = strpos($email, '@');

        if (false === $atPos) {
            return false;
        }

        $local = substr($email, 0, $atPos);
        $domain = substr($email, $atPos + 1);

        return self::isValidLocalPart($local) && self::isValidDomain($domain);
    }

    public function lower(): string
    {
        return mb_strtolower($this->email, 'UTF-8');
    }

    private static function isValidLocalPart(string $local): bool
    {
        $len = strlen($local);

        if ($len < 1 || $len > 64) {
            return false;
        }

        if (1 !== preg_match('(^[A-Za-z0-9!#$%&\'*+/=?^_`{|}~.-]+$)', $local)) {
            return false;
        }

        if (str_starts_with($local, '.') || str_ends_with($local, '.')) {
            return false;
        }

        if (str_contains($local, '..')) {
            return false;
        }

        return true;
    }

    private static function isValidDomain(string $domain): bool
    {
        if ('' === $domain) {
            return false;
        }

        if (1 !== preg_match('/^[\x00-\x7F]*$/', $domain)) {
            if (!function_exists('idn_to_ascii')) {
                return false;
            }

            $ascii = idn_to_ascii($domain, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);

            if (false === $ascii) {
                return false;
            }

            $domain = $ascii;
        }

        if ('localhost' === strtolower($domain)) {
            return true;
        }

        if (!str_contains($domain, '.')) {
            return false;
        }

        foreach (explode('.', $domain) as $label) {
            if (!self::isValidDomainLabel($label)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidDomainLabel(string $label): bool
    {
        if ('' === $label) {
            return false;
        }

        if (1 !== preg_match('/^[A-Za-z0-9-]+$/', $label)) {
            return false;
        }

        return !str_starts_with($label, '-') && !str_ends_with($label, '-');
    }

    /**
     * Strips RFC 5322 comments (parenthesised text, nesting and
     * quoted-pair escapes honoured), leaving a single space in their
     * place. Comments inside a quoted-string are left untouched since
     * they are ordinary characters there, not comments.
     */
    private static function stripComments(string $value): string
    {
        $out = '';
        $len = strlen($value);
        $inQuotes = false;
        $depth = 0;

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($inQuotes) {
                $out .= $char;

                if ('\\' === $char && $i + 1 < $len) {
                    ++$i;
                    $out .= $value[$i];
                    continue;
                }

                if ('"' === $char) {
                    $inQuotes = false;
                }

                continue;
            }

            if ($depth > 0) {
                if ('\\' === $char && $i + 1 < $len) {
                    ++$i;
                    continue;
                }

                if ('(' === $char) {
                    ++$depth;
                } elseif (')' === $char) {
                    --$depth;
                }

                continue;
            }

            if ('"' === $char) {
                $inQuotes = true;
                $out .= $char;
                continue;
            }

            if ('(' === $char) {
                ++$depth;
                $out .= ' ';
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Splits on top-level commas (quote-, angle-addr- and group-aware),
     * retrying with the offending character (an unterminated quote, or
     * an unterminated group's opening colon) neutralised to a literal
     * if the scan ends still "open" — see {@see self::splitTopLevelOnce()}.
     * Either failure is an unreliable parse, not one we should trust:
     * the damage is confined to its own entry instead of swallowing
     * every following address into one quoted blob or one ungrouped
     * group.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $value): array
    {
        $excluded = [];

        for ($attempt = 0; $attempt < 8; $attempt++) {
            [$segments, $unterminatedAt] = self::splitTopLevelOnce($value, $excluded);

            if (null === $unterminatedAt) {
                return $segments;
            }

            $excluded[] = $unterminatedAt;
        }

        return $segments;
    }

    /**
     * One pass of top-level splitting. A comma is a separator unless it
     * is inside a quoted-string, inside an angle-addr, or inside a
     * group's member list (between an unquoted `:` and its terminating
     * unquoted `;`) — group commas are deliberately kept in the same
     * segment so {@see self::parseGroup()} can see the whole group,
     * including its `;` terminator, per RFC 5322 group syntax. A
     * semicolon ends both the group and the top-level segment.
     *
     * Positions listed in $excluded are treated as plain literal
     * characters rather than quote delimiters or group-opening colons,
     * for the retry in {@see self::splitTopLevel()}. That retry handles
     * both an unterminated quote (swallows everything after it into one
     * segment) and an unterminated group (a `:` with no `;` anywhere
     * after it swallows every later address, comma-splitting included,
     * into one segment that {@see self::parseGroup()} then rejects
     * outright) — either failure mode must cost only the one malformed
     * entry, never the well-formed entries after it.
     *
     * @param list<int> $excluded
     *
     * @return array{0: list<string>, 1: int|null} the segments, and the
     *               byte offset of the unterminated quote or
     *               group-opening colon still open at end of string (or
     *               null if the scan ended clean)
     */
    private static function splitTopLevelOnce(string $value, array $excluded): array
    {
        $segments = [];
        $current = '';
        $len = strlen($value);
        $inQuotes = false;
        $angleDepth = 0;
        $inGroup = false;
        $openQuoteAt = null;
        $openColonAt = null;

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];
            $isRealQuote = '"' === $char && !in_array($i, $excluded, true);

            if ($inQuotes) {
                $current .= $char;

                if ('\\' === $char && $i + 1 < $len) {
                    ++$i;
                    $current .= $value[$i];
                    continue;
                }

                if ($isRealQuote) {
                    $inQuotes = false;
                    $openQuoteAt = null;
                }

                continue;
            }

            if ($isRealQuote) {
                $inQuotes = true;
                $openQuoteAt = $i;
                $current .= $char;
                continue;
            }

            if ('<' === $char) {
                ++$angleDepth;
                $current .= $char;
                continue;
            }

            if ('>' === $char) {
                if ($angleDepth > 0) {
                    --$angleDepth;
                }

                $current .= $char;
                continue;
            }

            if (0 === $angleDepth && !$inGroup && ':' === $char && !in_array($i, $excluded, true)) {
                $inGroup = true;
                $openColonAt = $i;
                $current .= $char;
                continue;
            }

            if (0 === $angleDepth && $inGroup && ';' === $char) {
                $inGroup = false;
                $openColonAt = null;
                $current .= $char;
                $segments[] = $current;
                $current = '';
                continue;
            }

            if (0 === $angleDepth && !$inGroup && ',' === $char) {
                $segments[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        if ($inQuotes) {
            return [$segments, $openQuoteAt];
        }

        if ($inGroup) {
            return [$segments, $openColonAt];
        }

        return [$segments, null];
    }

    /**
     * Splits a group's member-list text on unquoted, non-angle-addr
     * commas. Unlike {@see self::splitTopLevelOnce()} this never treats
     * a colon/semicolon specially, since RFC 5322 groups do not nest.
     *
     * @return list<string>
     */
    private static function splitUnquotedCommas(string $value): array
    {
        $segments = [];
        $current = '';
        $len = strlen($value);
        $inQuotes = false;
        $angleDepth = 0;

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($inQuotes) {
                $current .= $char;

                if ('\\' === $char && $i + 1 < $len) {
                    ++$i;
                    $current .= $value[$i];
                    continue;
                }

                if ('"' === $char) {
                    $inQuotes = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inQuotes = true;
                $current .= $char;
                continue;
            }

            if ('<' === $char) {
                ++$angleDepth;
                $current .= $char;
                continue;
            }

            if ('>' === $char) {
                if ($angleDepth > 0) {
                    --$angleDepth;
                }

                $current .= $char;
                continue;
            }

            if (0 === $angleDepth && ',' === $char) {
                $segments[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return $segments;
    }

    /**
     * A CR or LF anywhere in a top-level segment is an injection signal
     * (header values reach this parser already unfolded), so the whole
     * segment is rejected outright — before any group or mailbox
     * structure is even considered, so a trailing `Bcc: ...`-style
     * injection can never be parsed out of it.
     *
     * @return list<self>
     */
    private static function parseItem(string $segment): array
    {
        if (self::containsCrlf($segment)) {
            return [];
        }

        if ('' === trim($segment)) {
            return [];
        }

        $colonPos = self::findUnquoted($segment, ':');

        if (null === $colonPos) {
            $addr = self::parseMailbox(trim($segment));

            return null === $addr ? [] : [$addr];
        }

        return self::parseGroup($segment, $colonPos);
    }

    /**
     * Group syntax (`name: member, member;`) is recognised only when the
     * text before the colon cannot be mistaken for part of an address
     * (no `@`, `<` or `>`) and the group is properly terminated by an
     * unquoted `;`. Otherwise the unquoted colon makes the whole segment
     * invalid — it is never reinterpreted as a plain mailbox, since that
     * is exactly how a `Bcc:`-style injection would otherwise slip the
     * real address past this check.
     *
     * @return list<self>
     */
    private static function parseGroup(string $segment, int $colonPos): array
    {
        $prefix = substr($segment, 0, $colonPos);

        if (str_contains($prefix, '@') || str_contains($prefix, '<') || str_contains($prefix, '>')) {
            return [];
        }

        $semiPos = self::findUnquoted($segment, ';', $colonPos + 1);

        if (null === $semiPos) {
            return [];
        }

        $membersText = substr($segment, $colonPos + 1, $semiPos - $colonPos - 1);

        $addresses = [];

        foreach (self::splitUnquotedCommas($membersText) as $member) {
            $member = trim($member);

            if ('' === $member) {
                continue;
            }

            $addr = self::parseMailbox($member);

            if (null !== $addr) {
                $addresses[] = $addr;
            }
        }

        return $addresses;
    }

    /**
     * Parses a single mailbox, either a bare addr-spec or a
     * `display-name <addr-spec>`. $text is expected already trimmed.
     *
     * The LAST unquoted `<` is used as the angle-addr opener, its
     * matching `>` must exist and nothing but whitespace may follow it,
     * and the display-name portion before that `<` must itself contain
     * no unquoted `<` or `>` — otherwise the segment is structurally
     * invalid and rejected rather than guessed at. A bare addr-spec (no
     * `<` at all) must be a single whitespace-free token. The address is
     * always taken from the addr-spec between `<` and `>` (or the bare
     * token); the display name is decoded only afterwards, purely for
     * display, and never influences which text is treated as the
     * address.
     */
    private static function parseMailbox(string $text): ?self
    {
        $ltPos = self::findLastUnquoted($text, '<');

        if (null === $ltPos) {
            if (1 === preg_match('/\s/', $text)) {
                return null;
            }

            return self::isValid($text) ? new self($text, null) : null;
        }

        $gtPos = strpos($text, '>', $ltPos);

        if (false === $gtPos) {
            return null;
        }

        if ('' !== trim(substr($text, $gtPos + 1))) {
            return null;
        }

        $rawName = substr($text, 0, $ltPos);

        if (null !== self::findUnquoted($rawName, '<') || null !== self::findUnquoted($rawName, '>')) {
            return null;
        }

        $email = trim(substr($text, $ltPos + 1, $gtPos - $ltPos - 1));

        if (!self::isValid($email)) {
            return null;
        }

        return new self($email, self::decodeDisplayName(trim($rawName)));
    }

    private static function containsCrlf(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }

    private static function decodeDisplayName(string $raw): ?string
    {
        if ('' === $raw) {
            return null;
        }

        $unquoted = str_starts_with($raw, '"') ? self::unquote($raw) : $raw;
        $decoded = HeaderDecoder::decode($unquoted);

        return '' === $decoded ? null : $decoded;
    }

    private static function unquote(string $raw): string
    {
        $len = strlen($raw);

        if ($len < 2 || '"' !== $raw[0]) {
            return $raw;
        }

        $out = '';

        for ($i = 1; $i < $len; $i++) {
            $char = $raw[$i];

            if ('\\' === $char && $i + 1 < $len) {
                $out .= $raw[++$i];
                continue;
            }

            if ('"' === $char) {
                break;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Finds the first occurrence of $needle at or after $start that is
     * outside a quoted-string, or null if none exists. $start must be a
     * position known not to be inside an open quote (e.g. right after
     * an unquoted delimiter already found by this same method).
     */
    private static function findUnquoted(string $value, string $needle, int $start = 0): ?int
    {
        $len = strlen($value);
        $inQuotes = false;

        for ($i = $start; $i < $len; $i++) {
            $char = $value[$i];

            if ($inQuotes) {
                if ('\\' === $char && $i + 1 < $len) {
                    ++$i;
                    continue;
                }

                if ('"' === $char) {
                    $inQuotes = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inQuotes = true;
                continue;
            }

            if ($char === $needle) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Finds the LAST occurrence of $needle outside a quoted-string, or
     * null if none exists. Used to locate the angle-addr opener: a
     * crafted display name may contain its own unquoted `<...>`, and the
     * real mailbox delimiter is always the final one.
     */
    private static function findLastUnquoted(string $value, string $needle): ?int
    {
        $len = strlen($value);
        $inQuotes = false;
        $last = null;

        for ($i = 0; $i < $len; $i++) {
            $char = $value[$i];

            if ($inQuotes) {
                if ('\\' === $char && $i + 1 < $len) {
                    ++$i;
                    continue;
                }

                if ('"' === $char) {
                    $inQuotes = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inQuotes = true;
                continue;
            }

            if ($char === $needle) {
                $last = $i;
            }
        }

        return $last;
    }
}
