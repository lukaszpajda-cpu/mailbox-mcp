<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Imap;

use DateTimeImmutable;
use InvalidArgumentException;
use MailboxMcp\Tools\ToolException;

/**
 * Turns validated `search_emails` tool arguments into an IMAP UID SEARCH
 * criteria string. This is the only path by which model-supplied text
 * reaches an IMAP command, so every free-text criterion is quoted via
 * {@see self::quote()}, which rejects control characters that could
 * otherwise break out of the quoted string.
 */
final class SearchCriteria
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const KEYWORD_PATTERN = '/^\$?[A-Za-z0-9_]{1,64}$/';

    private const MAX_LENGTH = 998;

    /**
     * @param ?string $from    already quoted, e.g. '"a@example.com"'
     * @param ?string $to      already quoted
     * @param ?string $subject already quoted
     * @param ?string $text    already quoted
     * @param ?string $since   IMAP date, e.g. '20-Oct-2026'
     * @param ?string $before  IMAP date
     */
    private function __construct(
        private readonly ?string $from,
        private readonly ?string $to,
        private readonly ?string $subject,
        private readonly ?string $text,
        private readonly ?string $since,
        private readonly ?string $before,
        private readonly bool $unseenOnly,
        private readonly bool $excludeProcessed,
        private readonly string $processedKeyword,
    ) {
    }

    /**
     * @param array<string, mixed> $args already schema-validated by the tool
     *                                   (from, to, subject, text, since,
     *                                   before, unseen_only, exclude_processed,
     *                                   all optional); validated defensively
     *                                   here regardless
     */
    public static function fromArgs(array $args, string $processedKeyword): self
    {
        if (1 !== preg_match(self::KEYWORD_PATTERN, $processedKeyword)) {
            throw new InvalidArgumentException("Invalid processed keyword atom: '{$processedKeyword}'");
        }

        return new self(
            self::quotedStringArg($args, 'from'),
            self::quotedStringArg($args, 'to'),
            self::quotedStringArg($args, 'subject'),
            self::quotedStringArg($args, 'text'),
            self::dateArg($args, 'since'),
            self::dateArg($args, 'before'),
            self::boolArg($args, 'unseen_only'),
            self::boolArg($args, 'exclude_processed'),
            $processedKeyword,
        );
    }

    public function toImap(): string
    {
        $parts = [];

        if (null !== $this->from) {
            $parts[] = 'FROM ' . $this->from;
        }

        if (null !== $this->to) {
            $parts[] = 'TO ' . $this->to;
        }

        if (null !== $this->subject) {
            $parts[] = 'SUBJECT ' . $this->subject;
        }

        if (null !== $this->text) {
            $parts[] = 'TEXT ' . $this->text;
        }

        if (null !== $this->since) {
            $parts[] = 'SINCE ' . $this->since;
        }

        if (null !== $this->before) {
            $parts[] = 'BEFORE ' . $this->before;
        }

        if ($this->unseenOnly) {
            $parts[] = 'UNSEEN';
        }

        if ($this->excludeProcessed) {
            $parts[] = 'NOT KEYWORD ' . $this->processedKeyword;
        }

        return [] === $parts ? 'ALL' : implode(' ', $parts);
    }

    /**
     * True when FROM/TO/SUBJECT/TEXT carries a non-ASCII byte, in which
     * case the SEARCH command needs an explicit CHARSET (the already-quoted
     * strings still carry the original bytes; quoting only adds ASCII
     * backslashes and quotes).
     */
    public function needsCharset(): bool
    {
        foreach ([$this->from, $this->to, $this->subject, $this->text] as $value) {
            if (null !== $value && 1 === preg_match('/[\x80-\xFF]/', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quotes a single IMAP search string: rejects CR/LF/NUL and anything
     * over 998 bytes, escapes `\` and `"`, and wraps the result in `"`.
     */
    public static function quote(string $s): string
    {
        if (str_contains($s, "\r") || str_contains($s, "\n") || str_contains($s, "\0")) {
            throw new ToolException('Search text contains forbidden characters');
        }

        if (strlen($s) > self::MAX_LENGTH) {
            throw new ToolException('Search text is too long');
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $s);

        return '"' . $escaped . '"';
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function quotedStringArg(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new ToolException("Invalid {$key}: expected a string");
        }

        return self::quote($value);
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function dateArg(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value) || 1 !== preg_match(self::DATE_PATTERN, $value)) {
            throw new ToolException('Invalid date: ' . (is_string($value) ? $value : $key));
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (!checkdate($month, $day, $year)) {
            throw new ToolException("Invalid date: {$value}");
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $date) {
            throw new ToolException("Invalid date: {$value}");
        }

        return $date->format('j-M-Y');
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function boolArg(array $args, string $key): bool
    {
        return isset($args[$key]) && true === $args[$key];
    }
}
