<?php

declare(strict_types=1);

namespace MailboxMcp\Audit;

use DateTimeImmutable;
use MailboxMcp\Support\Clock;
use RuntimeException;
use Throwable;

/**
 * Appends one JSON line per event to `<dir>/<Y-m-d>.jsonl`. Every
 * {@see self::log()} call gets a `ts` (ISO 8601 with offset, from the
 * injected {@see Clock}) and, when `hashIp` is enabled, has its `ip`
 * entry replaced by `sha256(ip)`.
 *
 * Never throws: a broken audit log (unwritable directory, encoding
 * failure, …) must never break the request it is trying to record, so
 * any failure is swallowed and reported via {@see error_log()} instead.
 */
final class Logger
{
    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $retentionDays,
        private readonly bool $hashIp,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function log(array $entry): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $now = $this->clock->now();
            $entry['ts'] = $now->format(DATE_ATOM);

            $ip = $entry['ip'] ?? null;

            if ($this->hashIp && is_string($ip)) {
                $entry['ip'] = hash('sha256', $ip);
            }

            if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                throw new RuntimeException('unable to create directory: ' . $this->dir);
            }

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if (false === $line) {
                throw new RuntimeException('unable to encode audit entry');
            }

            $path = $this->dir . '/' . $now->format('Y-m-d') . '.jsonl';

            if (false === file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX)) {
                throw new RuntimeException('unable to write audit log: ' . $path);
            }
        } catch (Throwable $e) {
            error_log('mailbox-mcp: audit log error: ' . $e->getMessage());
        }
    }

    /**
     * Deletes `*.jsonl` files whose date (taken from the file name) is
     * older than {@see self::$retentionDays}, relative to the clock's
     * current time. A file whose name does not parse as a date is left
     * alone.
     */
    public function gc(): void
    {
        try {
            $cutoff = $this->clock->now()->getTimestamp() - ($this->retentionDays * 86400);

            foreach (glob($this->dir . '/*.jsonl') ?: [] as $path) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', basename($path, '.jsonl'));

                if (false === $date) {
                    continue;
                }

                if ($date->getTimestamp() < $cutoff) {
                    unlink($path);
                }
            }
        } catch (Throwable $e) {
            error_log('mailbox-mcp: audit gc error: ' . $e->getMessage());
        }
    }
}
