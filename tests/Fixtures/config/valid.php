<?php

declare(strict_types=1);

// Minimal valid config: just enough to pass Validator::validate() so
// tests can mutate a single field per case and assert the failure.

return [
    'auth' => [
        'token' => str_repeat('A', 43),
    ],
    'paths' => [
        'var' => '/tmp/mailbox-mcp-tests/var',
    ],
    'accounts' => [
        'example' => [
            'email' => 'contact@example.com',
            'name' => 'Example Co',
            'imap' => [
                'host' => 'mail.example.com',
                'port' => 993,
                'encryption' => 'tls',
                'username' => 'contact@example.com',
                'password' => 'secret',
            ],
            'smtp' => [
                'host' => 'mail.example.com',
                'port' => 587,
                'encryption' => 'starttls',
                'username' => 'contact@example.com',
                'password' => 'secret',
            ],
        ],
    ],
];
