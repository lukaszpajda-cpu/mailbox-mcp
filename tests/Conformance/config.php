<?php

declare(strict_types=1);

// Fixed configuration for the MCP conformance harness
// (tests/Conformance/server.php), consumed by bin/conformance.sh.
//
// Nothing here is a secret: the server this config drives only ever
// listens on 127.0.0.1, for the lifetime of a single bin/conformance.sh
// run. `auth.token` is a fixed, non-random constant on purpose, so
// bin/conformance.sh can read it back (via `require`) to build the
// `--url http://127.0.0.1:8765/t/<token>/mcp` the conformance CLI is
// pointed at.
//
// The dummy account's imap/smtp host is 127.0.0.1 with encryption
// `none`, but it is never actually connected to: tests/Conformance/
// server.php wires the Kernel to the same MailboxMcp\Tests\Support\
// FakeSessionFactory the Task 1-10 unit tests use, and none of the
// tools in tests/Conformance/tools.php touch the mail session.

return [
    'auth' => [
        // 43 chars of [A-Za-z0-9_-], same shape auth.token always requires
        // (see Config\Validator); fixed rather than generated so repeated
        // runs don't need to re-derive it.
        'token' => 'P2NZxarZt7ViMWSlVg-xHYV8yob5b2nj3iday8t9yaM',
        'allow_url_token' => true,
    ],
    'server' => [
        'instructions_extra' => 'MCP conformance harness; not a real mailbox.',
    ],
    'security' => [
        'allow_http' => true,
        // The DNS-rebinding-protection scenario sends one request with a
        // valid localhost Origin (matching the server's own host:port)
        // and expects it accepted, and one with a non-localhost Origin
        // and expects it rejected. An empty allow-list would reject both
        // (OriginGuard rejects every non-null Origin when the list is
        // empty), so the server's own origin must be listed explicitly.
        'allowed_origins' => ['http://127.0.0.1:8765'],
        'rate_limit_per_minute' => 10000,
        'rate_limit_global_per_minute' => 10000,
        'lockout_failures' => 10000,
        'max_body_bytes' => 1048576,
    ],
    'limits' => [
        'request_budget_seconds' => 30,
    ],
    'paths' => [
        'var' => sys_get_temp_dir() . '/mailbox-mcp-conformance/var',
    ],
    'audit' => [
        'enabled' => false,
    ],
    'accounts' => [
        'conformance' => [
            'email' => 'conformance@example.com',
            'name' => 'Conformance',
            'imap' => [
                'host' => '127.0.0.1',
                'port' => 143,
                'encryption' => 'none',
                'username' => 'conformance',
                'password' => 'conformance',
            ],
            'smtp' => [
                'host' => '127.0.0.1',
                'port' => 25,
                'encryption' => 'none',
                'username' => 'conformance',
                'password' => 'conformance',
            ],
        ],
    ],
];
