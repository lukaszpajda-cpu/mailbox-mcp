<?php

declare(strict_types=1);

namespace MailboxMcp\Support;

/**
 * URL checks shared by config validation and the outbound HTTP fetcher.
 */
final class Url
{
    /**
     * True for an absolute `https://` URL (scheme case-insensitive) with a
     * host, no `user:pass@` part, and no whitespace or control characters.
     */
    public static function isPlainHttps(string $url): bool
    {
        if (0 !== strncasecmp($url, 'https://', 8) || 1 === preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && '' !== ($parts['host'] ?? '')
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
