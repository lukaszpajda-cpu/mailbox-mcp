<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

use MailboxMcp\Support\Clock;
use MailboxMcp\Support\JsonFile;

/**
 * Tracks authentication failures per IP in `<dir>/<sha256(ip)>.json`
 * (`{"failures": [ts...], "locked_until": ts|null}`) and locks an IP out
 * once it accumulates `maxFailures` failures within `windowMinutes`.
 */
final class Lockout
{
    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly int $maxFailures,
        private readonly int $windowMinutes,
        private readonly int $lockMinutes,
    ) {
    }

    public function isLocked(string $ip): bool
    {
        $data = JsonFile::read($this->path($ip));
        $lockedUntil = $data['locked_until'] ?? null;

        if (!is_int($lockedUntil)) {
            return false;
        }

        return $this->clock->now()->getTimestamp() < $lockedUntil;
    }

    public function recordFailure(string $ip): void
    {
        $now = $this->clock->now()->getTimestamp();
        $windowSeconds = $this->windowMinutes * 60;
        $maxFailures = $this->maxFailures;
        $lockSeconds = $this->lockMinutes * 60;

        JsonFile::update(
            $this->path($ip),
            static function (array $data) use ($now, $windowSeconds, $maxFailures, $lockSeconds): array {
                $failures = self::extractFailures($data['failures'] ?? null);
                $failures = array_values(array_filter(
                    $failures,
                    static fn (int $ts): bool => $ts > $now - $windowSeconds,
                ));
                $failures[] = $now;

                $lockedUntil = count($failures) >= $maxFailures ? $now + $lockSeconds : null;

                return ['failures' => $failures, 'locked_until' => $lockedUntil];
            },
        );
    }

    public function clear(string $ip): void
    {
        $path = $this->path($ip);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function gc(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $windowSeconds = $this->windowMinutes * 60;

        foreach (glob($this->dir . '/*.json') ?: [] as $path) {
            $data = JsonFile::read($path);
            $lockedUntil = $data['locked_until'] ?? null;
            $failures = self::extractFailures($data['failures'] ?? null);

            $lockExpired = !is_int($lockedUntil) || $lockedUntil <= $now;
            $newestFailure = $failures === [] ? null : max($failures);
            $failuresStale = null === $newestFailure || $newestFailure <= $now - $windowSeconds;

            if ($lockExpired && $failuresStale) {
                unlink($path);
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function extractFailures(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $failures = [];

        foreach ($raw as $value) {
            if (is_int($value)) {
                $failures[] = $value;
            }
        }

        return $failures;
    }

    private function path(string $ip): string
    {
        return $this->dir . '/' . hash('sha256', $ip) . '.json';
    }
}
