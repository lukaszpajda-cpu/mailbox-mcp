<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

use MailboxMcp\Support\Clock;
use MailboxMcp\Support\JsonFile;

/**
 * Fixed per-minute window rate limiter backed by one JSON file per IP plus
 * a shared global file, all under a single directory. Every {@see self::hit()}
 * call always counts against the per-IP window; the global window is only
 * touched when the per-IP check already allows the request, so a denied
 * hit is never counted twice.
 */
final class RateLimiter
{
    private const MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $perIpPerMinute,
        private readonly int $globalPerMinute,
    ) {
    }

    public function hit(string $ip): RateLimitResult
    {
        $now = $this->clock->now()->getTimestamp();
        $minute = intdiv($now, 60);
        $retryAfterSeconds = 60 - ($now % 60);

        $ipCount = $this->increment($this->path(hash('sha256', $ip)), $minute);

        if ($ipCount > $this->perIpPerMinute) {
            return new RateLimitResult(false, $retryAfterSeconds, $ipCount === $this->perIpPerMinute + 1);
        }

        $globalCount = $this->increment($this->path('_global'), $minute);

        if ($globalCount > $this->globalPerMinute) {
            return new RateLimitResult(false, $retryAfterSeconds, $globalCount === $this->globalPerMinute + 1);
        }

        return new RateLimitResult(true, $retryAfterSeconds);
    }

    /**
     * Opportunistic cleanup: removes every window file (per-IP and global)
     * whose stored minute is before the current one — such a file would
     * be reset to a fresh window on its next hit anyway — plus any file
     * older than 24 h by mtime (covers unreadable or corrupt files).
     * Without this, every distinct client IP leaves one file behind.
     */
    public function gc(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $minute = intdiv($now, 60);

        foreach (glob($this->dir . '/*.json') ?: [] as $path) {
            $data = JsonFile::read($path);
            $stored = $data['minute'] ?? null;
            $mtime = @filemtime($path);

            $staleWindow = is_int($stored) && $stored < $minute;
            $staleFile = false !== $mtime && $mtime < $now - self::MAX_AGE_SECONDS;

            if ($staleWindow || $staleFile) {
                @unlink($path);
            }
        }
    }

    private function increment(string $path, int $minute): int
    {
        $data = JsonFile::update($path, static function (array $data) use ($minute): array {
            $currentMinute = $data['minute'] ?? null;
            $count = is_int($currentMinute) && $currentMinute === $minute && is_int($data['count'] ?? null)
                ? (int) $data['count']
                : 0;

            return ['minute' => $minute, 'count' => $count + 1];
        });

        $count = $data['count'] ?? 0;

        return is_int($count) ? $count : 0;
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name . '.json';
    }
}
