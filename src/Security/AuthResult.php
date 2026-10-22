<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

/**
 * Outcome of an {@see Authenticator} check. `reason` is one of the fixed
 * strings consumed by the audit log: `ok`, `no_token`, `bad_header_token`,
 * `bad_path_token`, `path_token_disabled`.
 */
final class AuthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = '',
    ) {
    }
}
