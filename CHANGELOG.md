# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-22

### Added

- Project scaffold: Composer manifest, own PSR-4 autoloader (`src/autoload.php`), `Version`, and the `Clock` / `SystemClock` / `FrozenClock` abstractions.
- MIME layer: RFC 5322/2047/2231 message parsing, `BODYSTRUCTURE` parsing, charset decoding, and HTML-to-text conversion.
- Socket-based IMAP and SMTP clients (pure PHP streams; no `ext-imap`, no runtime dependencies).
- Stateless MCP protocol core: JSON-RPC parsing, legacy (`2025-03-26`/`2025-06-18`/`2025-11-25`) and modern (`2026-07-28`) era detection, tool registry and dispatcher, DNS-rebinding `Origin` guard.
- Security layer: config validation, bearer-token authentication with 404-on-failure, per-IP/global rate limiting, per-IP lockout.
- JSONL audit log with retention and optional IP hashing.
- Nine MCP tools: `list_accounts`, `list_folders`, `search_emails`, `get_email`, `get_thread`, `create_draft`, `send_draft`, `mark_processed`, `get_reply_guidelines`, with untrusted-content wrapping for every message-derived field.
- `send_draft` recipient policy (allowed/denied domains, max recipients) and daily send cap, fail-closed and IDN-aware.
- Scripted-socket unit test harness and an MCP conformance test suite (`bin/conformance.sh`).
- Diagnostics (`src/Diag/Report.php`, `public/diag.php`, `bin/diag.php`), the release build script (`bin/build.sh`), deployment/claude.ai/scheduled-task documentation, the threat model, a security policy and CI.
