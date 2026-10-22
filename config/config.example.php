<?php

declare(strict_types=1);

// mailbox-mcp configuration.
//
// Copy this file outside the web root (see docs/deployment.md), fill in
// real values, chmod 600 it, and point MAILBOX_MCP_CONFIG (or
// public/index.php's path constant) at it. It is loaded by
// MailboxMcp\Config\Loader::load(), which validates every key below at
// boot; a bad config yields a generic 500 to the client and a precise
// line in the audit log.
//
// Keys not shown here (or left out entirely) fall back to the defaults
// in MailboxMcp\Config\Defaults::all() / Validator's per-account
// defaults. auth.token, paths.var and accounts have no default: the
// server refuses to start without them.

return [
    'auth' => [
        // >= 43 chars of [A-Za-z0-9_-] (43 base64url chars = 32 random
        // bytes). Generate with e.g.:
        //   php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "="), PHP_EOL;'
        'token' => 'REPLACE_WITH_43_PLUS_CHAR_BASE64URL',
        // When true, the MCP endpoint is also reachable at /t/<token>/mcp
        // (convenient for clients that cannot send a fixed Authorization
        // header). Off by default: the token would then appear in server
        // logs and proxies.
        'allow_url_token' => false,
    ],
    'server' => [
        // Used in diagnostics / instructions text only; not security-sensitive.
        'public_url' => 'https://mcp.example.com/mcp',
        // Appended to the fixed, English server instructions returned to
        // the model (initialize / server/discover).
        'instructions_extra' => '',
    ],
    'security' => [
        // Non-browser clients send no Origin header, so an empty list is
        // fine for Claude Code / MCP Inspector. Add an origin
        // (scheme://host[:port], exact match) only if a browser-based
        // client (e.g. claude.ai) will call this endpoint directly.
        'allowed_origins' => [],
        // Requests per minute, per client IP.
        'rate_limit_per_minute' => 60,
        // Requests per minute, across all clients.
        'rate_limit_global_per_minute' => 120,
        // Failed-auth attempts from one IP before it is locked out.
        'lockout_failures' => 10,
        // Window (minutes) over which lockout_failures is counted.
        'lockout_window_minutes' => 15,
        // How long (minutes) a locked-out IP stays locked out.
        'lockout_minutes' => 60,
        // Hard cap on request body size, in bytes.
        'max_body_bytes' => 262144,
        // IPs allowed to set X-Forwarded-For / X-Forwarded-Proto (reverse
        // proxies in front of this host). Empty: trust only REMOTE_ADDR.
        'trusted_proxies' => [],
        // Refuse plain HTTP unless true (only for local testing; the
        // real redirect to HTTPS belongs in .htaccess).
        'allow_http' => false,
    ],
    'limits' => [
        // Max messages returned by a single list/search call.
        'max_results' => 50,
        // Max characters returned for a message body/snippet.
        'max_chars' => 50000,
        // Max To+Cc+Bcc recipients accepted on a draft/send.
        'max_recipients' => 10,
        // Max send_draft calls per account per day.
        'daily_send_cap' => 50,
        // IMAP socket timeout, seconds.
        'imap_timeout' => 15,
        // SMTP socket timeout, seconds.
        'smtp_timeout' => 20,
        // Overall per-request time budget, seconds (the server enforces
        // its own budget rather than relying on the host's limit).
        'request_budget_seconds' => 60,
    ],
    'paths' => [
        // Writable directory for state (rate limiting, lockout, send
        // counters) and audit logs. Must be outside the web root; if the
        // host cannot do that, use a "Deny from all" directory inside it
        // instead (the diagnostics page reports this either way).
        'var' => '/home/user/mailbox-mcp/var',
    ],
    'audit' => [
        'enabled' => true,
        // Audit log files (var/audit/YYYY-MM-DD.jsonl) older than this
        // many days are deleted opportunistically.
        'retention_days' => 30,
        // Store sha256(ip) in the audit log instead of the raw IP.
        'hash_ip' => false,
    ],
    'diag' => [
        // Diagnostics page (config sanity, var/ permissions, PHP
        // extensions). Off by default; also requires the token when on.
        'enabled' => false,
    ],
    // One entry per mailbox. The first entry is the default account used
    // when a tool call omits `account`. Each account is one mailbox with
    // exactly one sending identity; a mailbox with two identities is two
    // accounts here.
    'accounts' => [
        // Account id: key used as the `account` tool argument; must
        // match /^[a-z0-9][a-z0-9_-]{0,31}$/.
        'example' => [
            'email' => 'contact@example.com',
            'name' => 'Example Co',
            'imap' => [
                'host' => 'mail.example.com',
                'port' => 993,
                // One of tls, starttls, none. none is only accepted when
                // host is 127.0.0.1, ::1 or localhost.
                'encryption' => 'tls',
                'username' => 'contact@example.com',
                'password' => '…',
                'verify_peer' => true,
            ],
            'smtp' => [
                'host' => 'mail.example.com',
                'port' => 587,
                'encryption' => 'starttls',
                'username' => 'contact@example.com',
                'password' => '…',
                'verify_peer' => true,
            ],
            // Special-use folders, when the server does not advertise
            // them via IMAP SPECIAL-USE and auto-detection should be
            // skipped. null means "auto-detect".
            'folders' => ['drafts' => null, 'sent' => null, 'trash' => null, 'junk' => null],
            // Appended to every draft this account creates.
            'signature' => "Best regards,\nExample Co",
            // Markdown file returned verbatim by get_reply_guidelines;
            // see config/guidelines.example.md for the expected shape.
            'guidelines_file' => '/home/user/mailbox-mcp-config/guidelines/example.md',
            // IMAP keyword set/cleared by mark_processed. Must match
            // /^\$?[A-Za-z0-9_]{1,64}$/.
            'processed_keyword' => '$ClaudeSeen',
            // send_draft recipient policy for this account. Empty lists
            // mean "no restriction" / "nothing denied".
            'send' => ['allowed_domains' => [], 'denied_domains' => []],
        ],
    ],
];
