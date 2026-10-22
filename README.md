# mailbox-mcp

A small, auditable MCP server that lets a Claude client — claude.ai (web
and mobile, via a custom connector), Claude Code, or the MCP Inspector —
read and search an ordinary IMAP mailbox, prepare replies as drafts, and
send a draft only after a human has seen it. It runs as plain PHP on
shared hosting: one short HTTP request per MCP call, no daemon, no
database, no Composer at runtime, no `ext-imap`.

## Why

- **Your own mailbox, your own identity.** Each configured account is one
  mailbox with exactly one sending identity (address, display name,
  signature). Nothing is copied to a third-party inbox and replies are
  sent from the address you actually run the business on.
- **Built for shared hosting.** Pure PHP, zero runtime dependencies, one
  file per request. Upload it to a cPanel/DirectAdmin account next to an
  existing mailbox and it works.
- **Send only what you saw.** The model can never send free text to an
  arbitrary address. It can only ask to send an existing draft, which you
  (or the model, in the same conversation) created and can inspect first.

## Security model

- **Token auth with 404 on failure.** A long random bearer token
  (`Authorization: Bearer <token>`) gates every call; any failure —
  wrong token, no token, unknown path — returns the same generic 404 as
  an unmapped route, so the endpoint's existence is not revealed.
- **Rate limiting and lockout.** Per-IP and global per-minute limits
  (`429` with `Retry-After`), plus a per-IP lockout after repeated auth
  failures.
- **Untrusted content, clearly marked.** Every field that comes from a
  message (from, to, subject, body, attachment names, …) is wrapped in
  `<email_content untrusted="true" …>` tags with nested tag-like
  substrings neutralised, and the server's own instructions to the model
  state plainly that mail content is data, never instructions.
- **Sending is draft-only, policed server-side.** There is no tool that
  sends free text. `send_draft` only sends an existing draft from the
  Drafts folder, after checking a per-account recipient policy
  (allowed/denied domains, a maximum recipient count) and a daily send
  cap — all enforced by the server, not by asking the model nicely. Its
  tool description also tells the model never to call it outside an
  explicit, in-conversation approval.
- **Audit log.** Every tool call and every auth failure is written to a
  JSONL log outside the web root. The log never contains mail content —
  only facts such as account, tool name, argument counts, duration and
  outcome.
- **No runtime dependencies.** Nothing to update for a security
  advisory in some unrelated package; the attack surface is this
  repository.

See [`docs/threat-model.md`](docs/threat-model.md) for the full
threat/control table.

## Requirements

- PHP 8.2, 8.3, 8.4 or 8.5, with `ext-json`, `ext-openssl` and
  `ext-mbstring` (required), and `ext-iconv` and `ext-intl` (recommended:
  used for legacy-charset decoding and internationalized recipient
  domains; the server degrades gracefully without them, and
  `bin/diag.php` / `public/diag.php` report whether each is loaded).
- An IMAP mailbox and SMTP credentials for sending (can be the same
  server). STARTTLS, implicit TLS or, for local testing only, plaintext
  to `127.0.0.1`/`::1`/`localhost`.
- HTTPS in front of the endpoint (a plain shared-hosting SSL certificate
  is enough).
- Shared or any other hosting that can run plain PHP scripts and lets
  you place one directory outside the public web root (or, failing
  that, behind a `Deny from all` rule — see
  [`docs/deployment.md`](docs/deployment.md)).

## Install on shared hosting

In short: create the subdomain, then keep the code, config and state
inside the domain directory but outside its web root (shared hosts with
a per-domain `open_basedir`, e.g. CloudLinux, only let web PHP reach
that directory): `src/`, `bin/` and `var/` in
`~/domains/mcp.example.com/app/`, `config.php` (from
`config/config.example.php`, `chmod 600`) in
`~/domains/mcp.example.com/config/`. Upload `public/index.php`,
`public/.htaccess` and `public/diag.php` to the subdomain's
`public_html/`, and point them at the app and config with a
`mailbox-mcp.paths.php` next to them (copy
`public/mailbox-mcp.paths.example.php`; `SetEnv MAILBOX_MCP_APP` /
`MAILBOX_MCP_CONFIG` in `.htaccess` is the alternative where it reaches
PHP). Generate a token with `php bin/diag.php --new-token`, run the
diagnostics page once to confirm everything connects, then disable it
again.

Full step-by-step walkthrough (cPanel/DirectAdmin, SSH/rsync and
file-manager upload, file modes, troubleshooting): see
[`docs/deployment.md`](docs/deployment.md).

## Connect from Claude

- **claude.ai (web/mobile):** Settings → Connectors → Add custom
  connector, with your endpoint URL and `Authorization: Bearer <token>`
  as a request header. Full walkthrough, the URL-token fallback and a
  first test prompt: [`docs/claude-ai.md`](docs/claude-ai.md).
- **Claude Code:**

  ```
  claude mcp add --transport http mailbox https://mcp.example.com/mcp --header "Authorization: Bearer <token>"
  ```

- **MCP Inspector:**

  ```
  npx @modelcontextprotocol/inspector
  ```

  then connect to `https://mcp.example.com/mcp` with the same
  `Authorization: Bearer <token>` header.

## Tools

| Tool | What it does | Read-only? |
| --- | --- | --- |
| `list_accounts` | Lists every configured mailbox account: email, display name, default flag, whether reply guidelines are configured. | Yes |
| `list_folders` | Lists an account's IMAP folders with detected role (inbox/drafts/sent/trash/junk/archive/none) and message/unseen counts. | Yes |
| `search_emails` | Searches a folder by sender, recipient, subject, body text and/or date range; returns the newest matches with a short snippet each. Never marks messages read. | Yes |
| `get_email` | Returns one message by folder and uid: headers, a text rendering of the body, and attachment metadata (no content). Never marks the message read. | Yes |
| `get_thread` | Collects the conversation around a message via Message-ID/In-Reply-To/References, oldest first. | Yes |
| `create_draft` | Stores a new draft (optionally as a reply, via `in_reply_to_uid`) in the Drafts folder. Nothing is sent. | No |
| `send_draft` | Sends an existing draft after the recipient policy and daily cap pass. Only ever call after explicit, in-conversation user approval — never in an automated run. | No |
| `mark_processed` | Sets or clears the account's processed keyword on messages, so scheduled runs can skip what has already been handled. | No |
| `get_reply_guidelines` | Returns the operator-authored reply-guidelines file for an account, verbatim. | Yes |

## Configuration

Every key is documented inline in
[`config/config.example.php`](config/config.example.php); summary below
(all except `auth.token`, `paths.var` and `accounts` have sane
defaults).

| Key | Meaning |
| --- | --- |
| `auth.token` | The bearer token; ≥ 43 chars of `[A-Za-z0-9_-]`. Generate with `bin/diag.php --new-token`. |
| `auth.allow_url_token` | Also accept the token in the URL at `/t/<token>/mcp`, for clients that cannot send a fixed header. Off by default. |
| `server.public_url` | Informational only; shown in diagnostics. |
| `server.instructions_extra` | Appended to the fixed safety instructions sent to the model. |
| `security.allowed_origins` | Browser `Origin` allow-list (`scheme://host[:port]`); leave empty for non-browser clients. |
| `security.rate_limit_per_minute` | Requests per minute, per client IP. |
| `security.rate_limit_global_per_minute` | Requests per minute, across all clients. |
| `security.lockout_failures` | Failed-auth attempts from one IP before it is locked out. |
| `security.lockout_window_minutes` | Window over which `lockout_failures` is counted. |
| `security.lockout_minutes` | How long a locked-out IP stays locked out. |
| `security.max_body_bytes` | Hard cap on request body size. |
| `security.trusted_proxies` | IPs allowed to set `X-Forwarded-For`/`X-Forwarded-Proto`; empty trusts only `REMOTE_ADDR`. |
| `security.allow_http` | Allow plain HTTP (local testing only; real hosting should redirect to HTTPS in `.htaccess`). |
| `limits.max_results` | Max messages returned by one list/search call. |
| `limits.max_chars` | Max characters returned for a message body/snippet. |
| `limits.max_recipients` | Max To+Cc+Bcc recipients on a draft/send. |
| `limits.daily_send_cap` | Max `send_draft` calls per account per day. |
| `limits.imap_timeout` | IMAP socket timeout, seconds. |
| `limits.smtp_timeout` | SMTP socket timeout, seconds. |
| `limits.request_budget_seconds` | Overall per-request time budget. |
| `paths.var` | Writable directory for rate-limit/lockout/send-counter state and audit logs; should be outside the web root. |
| `audit.enabled` | Whether the JSONL audit log is written. |
| `audit.retention_days` | Audit log files older than this are deleted opportunistically. |
| `audit.hash_ip` | Store `sha256(ip)` in the audit log instead of the raw IP. |
| `diag.enabled` | Enables `public/diag.php`. Off by default; turn on only for setup, then back off. |
| `accounts.<id>` | One mailbox: `email`, `name`, `imap`/`smtp` connection settings, special-use `folders` overrides, `signature`, `guidelines_file`, `processed_keyword`, and the `send` recipient policy (`allowed_domains`/`denied_domains`). The first entry is the default account. |

## Scheduled task

A Claude scheduled task can poll the mailbox hourly, draft replies and
mark mail processed — but must never call `send_draft` itself. The
ready-to-use prompt and recommended schedule: see
[`docs/scheduled-task.md`](docs/scheduled-task.md).

## Limitations

- IMAP/SMTP only — no Gmail/Outlook vendor APIs.
- One operator, one token (no multi-user/multi-tenant support in v1).
- Attachments are listed (name, type, size) but their content is never
  downloaded.
- No OAuth yet — a single long-lived bearer token (see the
  [roadmap](#roadmap)).

## Development

```
composer install
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer check --diff
bin/conformance.sh          # MCP conformance suite (requires Node)
```

Unit tests include a scripted-socket harness for the IMAP/SMTP clients
(`tests/Support/ScriptedSocketServer.php`) so the socket protocol is
exercised without a real mail server. `tests/Integration` runs the
tools against a real (or GreenMail) IMAP/SMTP server given by
`MAILBOX_MCP_TEST_*` environment variables.

## Roadmap

1. OAuth 2.1 per the MCP authorization spec, so no long-lived secret is
   stored in the client.
2. Out-of-band send approval: `send_draft` returns a one-time HTTPS
   link; the user opens it to send.
3. Attachment download tool with a size cap.
4. Mark the original message `\Answered` after a reply is sent.
5. Per-token account scopes (read-only tokens).
6. `structuredContent` alongside the text output.

## License

MIT — see [`LICENSE`](LICENSE). Two classes (the socket IMAP and SMTP
clients) are derived from a BSD-2-Clause-licensed project; see
[`NOTICE`](NOTICE) for their original copyright.
