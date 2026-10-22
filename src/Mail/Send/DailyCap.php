<?php

declare(strict_types=1);

namespace MailboxMcp\Mail\Send;

use DateTimeImmutable;
use DateTimeZone;
use MailboxMcp\Support\Clock;
use MailboxMcp\Support\JsonFile;
use MailboxMcp\Tools\ToolException;
use Throwable;

/**
 * Per-account, per-UTC-day count of messages sent by send_draft, kept in
 * `<stateDir>/send-<accountId>-<Ymd>.count` as `{"count": n}`.
 *
 * {@see assertAvailable()} runs before SMTP and {@see increment()} only
 * after SMTP accepted the message, so a failed send never consumes the
 * budget. The two are not one atomic step: two concurrent sends at
 * `cap - 1` can both pass the check, overshooting by at most the number
 * of concurrent requests.
 *
 * Fails closed: when the state directory cannot be created or written
 * (or the counter file is not writable), {@see assertAvailable()} refuses
 * the send instead of treating the missing counter as zero, and
 * {@see increment()} throws so the caller can report it.
 */
final class DailyCap
{
    private const NOT_WRITABLE = 'Send counter storage is not writable; sending refused';

    public function __construct(
        private readonly string $stateDir,
        private readonly Clock $clock,
        private readonly int $cap,
    ) {
    }

    /**
     * @throws ToolException when today's count has reached the cap
     */
    public function assertAvailable(string $accountId): void
    {
        $path = $this->path($accountId);
        $this->ensureWritable($path);

        try {
            // read under the lock; creates the file, proving it is writable
            $count = self::count(JsonFile::update($path, static fn (array $data): array => $data));
        } catch (Throwable) {
            throw new ToolException(self::NOT_WRITABLE);
        }

        if ($count >= $this->cap) {
            throw new ToolException("Daily send cap reached ({$this->cap})");
        }
    }

    /**
     * @return int the new count for today
     */
    public function increment(string $accountId): int
    {
        $path = $this->path($accountId);
        $this->ensureWritable($path);

        $data = JsonFile::update(
            $path,
            static fn (array $data): array => ['count' => self::count($data) + 1],
        );

        return self::count($data);
    }

    /**
     * Opportunistic cleanup: removes `send-*.count` files whose day (from
     * the file name, or the mtime when the name carries no parseable
     * day) is more than $retentionDays days before today (UTC). Today's
     * counter is never removed, whatever the retention.
     */
    public function gc(int $retentionDays): void
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $cutoff = $today->getTimestamp() - max(0, $retentionDays) * 86400;

        foreach (glob($this->stateDir . '/send-*.count') ?: [] as $path) {
            $day = null;

            if (1 === preg_match('/-(\d{8})\.count$/', $path, $m)) {
                $parsed = DateTimeImmutable::createFromFormat('!Ymd', $m[1], new DateTimeZone('UTC'));
                $day = false === $parsed ? null : $parsed->getTimestamp();
            }

            if (null === $day) {
                $mtime = @filemtime($path);
                $day = false === $mtime ? null : $mtime;
            }

            if (null !== $day && $day < $cutoff) {
                @unlink($path);
            }
        }
    }

    /**
     * Checked up front (with mkdir warnings suppressed) so an unwritable
     * location yields a clean ToolException rather than a PHP warning.
     */
    private function ensureWritable(string $path): void
    {
        if (!is_dir($this->stateDir) && !file_exists($this->stateDir)) {
            @mkdir($this->stateDir, 0700, true);
        }

        if (!is_dir($this->stateDir)
            || !is_writable($this->stateDir)
            || (file_exists($path) && (!is_file($path) || !is_writable($path)))
        ) {
            throw new ToolException(self::NOT_WRITABLE);
        }
    }

    private function path(string $accountId): string
    {
        $day = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Ymd');

        return $this->stateDir . '/send-' . $accountId . '-' . $day . '.count';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function count(array $data): int
    {
        $count = $data['count'] ?? 0;

        return is_int($count) && $count > 0 ? $count : 0;
    }
}
