<?php

declare(strict_types=1);

namespace MailboxMcp\Tools;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use MailboxMcp\Support\HttpFetcherInterface;
use MailboxMcp\Support\HttpFetchException;

/**
 * Checks a requested stay against the account's availability source: an
 * operator-configured HTTPS endpoint (never a URL from tool input) that
 * returns the occupied night ranges as `occupied-json` (see
 * docs/availability.md). The whole list is fetched and filtered locally,
 * so the requested dates never leave this server.
 *
 * A range `{from, to}` occupies the nights from `from` (check-in) up to
 * but excluding `to` (check-out); the requested stay is read the same way.
 */
final class CheckAvailabilityTool implements ToolInterface
{
    private const DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

    private const MAX_BYTES = 262144;

    private const MAX_RANGES = 2000;

    private const MAX_NIGHTS = 60;

    private const MAX_YEARS_AHEAD = 2;

    private const NEARBY_DAYS = 45;

    /**
     * Accepted `generated` shapes, format => whether it carries an offset;
     * anything else (or an impossible value) is shown as `unknown`.
     *
     * @var array<string, bool>
     */
    private const GENERATED_FORMATS = [
        'Y-m-d' => false,
        'Y-m-d\\TH:i:sP' => true,
        'Y-m-d\\TH:iP' => true,
        'Y-m-d\\TH:i:s' => false,
        'Y-m-d H:i:s' => false,
    ];

    public function __construct(private readonly HttpFetcherInterface $fetcher)
    {
    }

    public function name(): string
    {
        return 'check_availability';
    }

    /**
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     annotations: array{
     *         readOnlyHint: bool,
     *         destructiveHint: bool,
     *         idempotentHint: bool,
     *         openWorldHint: bool,
     *     },
     * }
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'title' => 'Check availability',
            'description' => "Checks whether a stay is free according to the account's availability source (the operator's own calendar feed). `from` is the check-in date and `to` the check-out date (YYYY-MM-DD). Returns whether all nights are free, which occupied ranges overlap, and the occupied ranges around the requested dates so you can suggest alternatives. Read-only; data comes from the operator's system, not from email.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string'],
                    'from' => ['type' => 'string', 'pattern' => self::DATE_PATTERN],
                    'to' => ['type' => 'string', 'pattern' => self::DATE_PATTERN],
                ],
                'required' => ['from', 'to'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => true,
            ],
        ];
    }

    public function execute(array $args, ToolContext $ctx): ToolResult
    {
        $source = $ctx->account->availability;

        if (null === $source) {
            throw new ToolException("Account '{$ctx->account->id}' has no availability source configured");
        }

        $from = self::argDate($args, 'from');
        $to = self::argDate($args, 'to');

        if ($to <= $from) {
            throw new ToolException("'to' (check-out) must be after 'from' (check-in)");
        }

        $nights = (int) $from->diff($to)->days;

        if ($nights > self::MAX_NIGHTS) {
            throw new ToolException('Stay too long: at most ' . self::MAX_NIGHTS . ' nights can be checked at once');
        }

        $today = self::date($ctx->clock->now()->format('Y-m-d'));

        if (null !== $today && $from > $today->modify('+' . self::MAX_YEARS_AHEAD . ' years')) {
            throw new ToolException("'from' must be at most " . self::MAX_YEARS_AHEAD . ' years from today');
        }

        [$generated, $ranges] = $this->load($source, $ctx->deadlineNs);

        $fromKey = $from->format('Y-m-d');
        $toKey = $to->format('Y-m-d');
        $windowStart = $from->modify('-' . self::NEARBY_DAYS . ' days')->format('Y-m-d');
        $windowEnd = $to->modify('+' . self::NEARBY_DAYS . ' days')->format('Y-m-d');

        usort($ranges, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $overlapping = [];
        $nearby = [];

        foreach ($ranges as [$rangeFrom, $rangeTo]) {
            // Half-open night intervals [from, to): Y-m-d strings compare chronologically.
            if ($rangeFrom < $toKey && $rangeTo > $fromKey) {
                $overlapping[] = "- {$rangeFrom} → {$rangeTo}";
            } elseif ($rangeFrom < $windowEnd && $rangeTo > $windowStart) {
                $nearby[] = "- {$rangeFrom} → {$rangeTo}";
            }
        }

        $lines = [
            "account: {$ctx->account->id}",
            "requested: {$fromKey} → {$toKey} ({$nights} " . (1 === $nights ? 'night' : 'nights') . ')',
            'available: ' . ([] === $overlapping ? 'yes' : 'no'),
            'source_generated: ' . ($generated ?? 'unknown'),
            'overlapping: ' . count($overlapping),
            ...$overlapping,
            'occupied_nearby: ' . count($nearby),
            ...$nearby,
        ];

        return ToolResult::text(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function argDate(array $args, string $key): DateTimeImmutable
    {
        $value = $args[$key] ?? null;
        $date = is_string($value) ? self::date($value) : null;

        if (null === $date) {
            throw new ToolException("Invalid '{$key}' date: must be a real calendar date (YYYY-MM-DD)");
        }

        return $date;
    }

    /**
     * Strict YYYY-MM-DD parse at UTC midnight; null for anything that is
     * not exactly a real calendar date (e.g. 2026-02-30).
     */
    private static function date(string $value): ?DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * The feed's `generated` value, re-rendered from a strict parse: the
     * date alone, `DATE_ATOM` when it carries an offset, or
     * `Y-m-d\TH:i:s` when it does not (no zone is invented); null when it
     * is not exactly one of {@see self::GENERATED_FORMATS} or names an
     * impossible date/time, so nothing else from the feed reaches the model.
     */
    private static function normaliseGenerated(string $value): ?string
    {
        foreach (self::GENERATED_FORMATS as $format => $hasOffset) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));

            if (false === $parsed || $parsed->format($format) !== $value) {
                continue;
            }

            if ('Y-m-d' === $format) {
                return $parsed->format('Y-m-d');
            }

            return $parsed->format($hasOffset ? DATE_ATOM : 'Y-m-d\\TH:i:s');
        }

        return null;
    }

    /**
     * @param array{url: string, format: string, timeout: int} $source
     *
     * @return array{0: ?string, 1: list<array{0: string, 1: string}>} generated timestamp (null if absent/unusable), occupied ranges
     */
    private function load(array $source, ?float $deadlineNs): array
    {
        $timeout = $source['timeout'];

        if (null !== $deadlineNs) {
            $remaining = (int) floor(($deadlineNs - hrtime(true)) / 1e9);

            if ($remaining < 1) {
                error_log('mailbox-mcp: check_availability: request time budget exhausted before fetch');

                throw new ToolException('Availability source could not be fetched');
            }

            $timeout = min($timeout, $remaining);
        }

        try {
            $body = $this->fetcher->get($source['url'], $timeout, self::MAX_BYTES);
        } catch (HttpFetchException $e) {
            error_log('mailbox-mcp: check_availability: ' . $e->getMessage());

            throw new ToolException('Availability source could not be fetched');
        }

        return self::parseOccupiedJson($body);
    }

    /**
     * @return array{0: ?string, 1: list<array{0: string, 1: string}>}
     */
    private static function parseOccupiedJson(string $body): array
    {
        $unexpected = new ToolException('Availability source returned unexpected data');

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $unexpected;
        }

        if (!is_array($data) || !is_bool($data['success'] ?? null)) {
            throw $unexpected;
        }

        if (false === $data['success']) {
            throw new ToolException('Availability source temporarily unavailable');
        }

        $generated = $data['generated'] ?? null;

        if (null !== $generated && !is_string($generated)) {
            throw $unexpected;
        }

        $occupied = $data['occupied'] ?? null;

        if (!is_array($occupied) || !array_is_list($occupied) || count($occupied) > self::MAX_RANGES) {
            throw $unexpected;
        }

        $ranges = [];

        foreach ($occupied as $range) {
            if (!is_array($range) || !is_string($range['from'] ?? null) || !is_string($range['to'] ?? null)) {
                throw $unexpected;
            }

            $rangeFrom = self::date($range['from']);
            $rangeTo = self::date($range['to']);

            if (null === $rangeFrom || null === $rangeTo || $rangeTo <= $rangeFrom) {
                throw $unexpected;
            }

            $ranges[] = [$range['from'], $range['to']];
        }

        return [null === $generated ? null : self::normaliseGenerated($generated), $ranges];
    }
}
