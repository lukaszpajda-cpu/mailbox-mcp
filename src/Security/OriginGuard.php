<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

/**
 * Checks a request's `Origin` header against a configured allow-list.
 *
 * A missing `Origin` header (`null`) is always allowed — browsers omit it
 * for same-origin and non-CORS requests, so this guard only rejects
 * cross-origin browser requests that *do* send one. An unparsable origin,
 * or one carrying anything beyond `scheme://host[:port]`, is rejected. An
 * empty allow-list rejects every non-null origin.
 */
final class OriginGuard
{
    /** @var list<string> */
    private readonly array $normalizedAllowed;

    /**
     * @param list<string> $allowedOrigins `scheme://host[:port]` strings
     */
    public function __construct(array $allowedOrigins)
    {
        $normalized = [];

        foreach ($allowedOrigins as $origin) {
            $value = self::normalize($origin);

            if (null !== $value) {
                $normalized[] = $value;
            }
        }

        $this->normalizedAllowed = $normalized;
    }

    public function allows(?string $origin): bool
    {
        if (null === $origin) {
            return true;
        }

        $normalized = self::normalize($origin);

        if (null === $normalized) {
            return false;
        }

        return in_array($normalized, $this->normalizedAllowed, true);
    }

    private static function normalize(string $value): ?string
    {
        $parts = parse_url($value);

        if (false === $parts || !isset($parts['scheme']) || !isset($parts['host'])) {
            return null;
        }

        foreach (['user', 'pass', 'path', 'query', 'fragment'] as $key) {
            if (isset($parts[$key]) && '' !== $parts[$key]) {
                return null;
            }
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        return $scheme . '://' . $host . (null !== $port ? ':' . $port : '');
    }
}
