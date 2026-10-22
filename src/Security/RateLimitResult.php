<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

/**
 * Outcome of a {@see RateLimiter::hit()} check. `firstDenial` is true only
 * for the hit that first crosses the limit (per-IP or global) in the
 * current window, so a caller stuck above the limit for the rest of the
 * window can be audited once instead of on every subsequent request.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $retryAfterSeconds,
        public readonly bool $firstDenial = false,
    ) {
    }
}
