<?php

declare(strict_types=1);

namespace MailboxMcp\Config;

/**
 * Default values for every configuration key from design spec §10,
 * except the ones the operator must always provide explicitly:
 * `auth.token`, `paths.var` and `accounts` (no sensible default exists
 * for a secret token, a filesystem path or the mailbox list).
 *
 * `server.public_url` and `server.instructions_extra` default to the
 * empty string rather than the spec's illustrative example value.
 */
final class Defaults
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'auth' => [
                'allow_url_token' => false,
            ],
            'server' => [
                'public_url' => '',
                'instructions_extra' => '',
            ],
            'security' => [
                'allowed_origins' => [],
                'rate_limit_per_minute' => 60,
                'rate_limit_global_per_minute' => 120,
                'lockout_failures' => 10,
                'lockout_window_minutes' => 15,
                'lockout_minutes' => 60,
                'max_body_bytes' => 262144,
                'trusted_proxies' => [],
                'allow_http' => false,
            ],
            'limits' => [
                'max_results' => 50,
                'max_chars' => 50000,
                'max_recipients' => 10,
                'daily_send_cap' => 50,
                'imap_timeout' => 15,
                'smtp_timeout' => 20,
                'request_budget_seconds' => 60,
            ],
            'paths' => [],
            'audit' => [
                'enabled' => true,
                'retention_days' => 30,
                'hash_ip' => false,
            ],
            'diag' => [
                'enabled' => false,
            ],
        ];
    }
}
