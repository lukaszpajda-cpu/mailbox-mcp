<?php

declare(strict_types=1);

namespace MailboxMcp\Security;

use MailboxMcp\Http\Request;

/**
 * Checks a request against a single shared bearer token. The
 * `Authorization` header is tried first; a path-supplied token is only
 * considered when the header is entirely absent, and only when
 * {@see self::$allowUrlToken} is enabled.
 *
 * Any *present* `Authorization` header is authoritative: if it is not
 * exactly `Bearer <token>` (case-insensitive scheme, a single token made of
 * `[A-Za-z0-9_-]`), that already fails the request with `bad_header_token`
 * and the path token is never consulted. This prevents a malformed or
 * wrong-scheme header from being used to smuggle a request through the
 * path-token channel.
 *
 * Token comparisons always go through {@see hash_equals()}, including when
 * the supplied value's length differs from the configured token, so that
 * timing does not reveal information about the secret.
 */
final class BearerTokenAuthenticator implements Authenticator
{
    public function __construct(
        private readonly string $token,
        private readonly bool $allowUrlToken,
    ) {
    }

    public function authenticate(Request $request, ?string $pathToken): AuthResult
    {
        $authorizationHeader = $request->header('authorization');

        if (null !== $authorizationHeader) {
            $headerToken = self::extractBearerToken($authorizationHeader);

            if (null === $headerToken) {
                return new AuthResult(false, 'bad_header_token');
            }

            return hash_equals($this->token, $headerToken)
                ? new AuthResult(true, 'ok')
                : new AuthResult(false, 'bad_header_token');
        }

        if (null !== $pathToken) {
            if (!$this->allowUrlToken) {
                return new AuthResult(false, 'path_token_disabled');
            }

            return hash_equals($this->token, $pathToken)
                ? new AuthResult(true, 'ok')
                : new AuthResult(false, 'bad_path_token');
        }

        return new AuthResult(false, 'no_token');
    }

    private static function extractBearerToken(string $authorizationHeader): ?string
    {
        if (1 !== preg_match('/^bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }

        return $token;
    }
}
