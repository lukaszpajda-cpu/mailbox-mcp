<?php

declare(strict_types=1);

namespace MailboxMcp\Http;

final class ClientIp
{
    /**
     * @param array<string, mixed> $server
     * @param array<int, string>   $trustedProxies
     */
    public static function resolve(array $server, array $trustedProxies): string
    {
        $remoteAddr = self::stringOrDefault($server['REMOTE_ADDR'] ?? null, '0.0.0.0');

        if (!in_array($remoteAddr, $trustedProxies, true)) {
            return $remoteAddr;
        }

        $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? null;

        if (!is_string($forwardedFor) || $forwardedFor === '') {
            return $remoteAddr;
        }

        $firstEntry = trim(explode(',', $forwardedFor, 2)[0]);

        if (filter_var($firstEntry, FILTER_VALIDATE_IP) === false) {
            return $remoteAddr;
        }

        return $firstEntry;
    }

    private static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
