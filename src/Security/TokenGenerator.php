<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

/**
 * Generates cryptographically random tokens suitable for `auth.token`:
 * 256 bits of entropy, base64url-encoded without padding (43 characters).
 */
final class TokenGenerator
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
