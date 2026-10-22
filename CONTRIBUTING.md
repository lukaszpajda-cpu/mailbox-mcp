# Contributing to mailbox-mcp

Thanks for your interest. This project is small, security-sensitive and
maintained in spare time, so a short note before you spend effort on a
change is always welcome: open an issue describing what you want to do
and why, and we will agree on the shape before you write code.

## What helps most

- Reports from other hosting providers and mail servers: which IMAP/SMTP
  quirks you hit, what `bin/diag.php` printed, what the fix was.
- Bug reports with a minimal reproduction (a scripted IMAP dialogue in the
  style of `tests/Unit/Mail/Imap/ClientTest.php` is ideal).
- Documentation fixes, translations of the operator docs, and clearer
  error messages.
- Items from the roadmap in `README.md` (OAuth 2.1, out-of-band send
  approval, attachment download) — please discuss these first, they touch
  the security model.

## Ground rules

- **Security first.** Every string that comes from a mail message is
  untrusted and must stay inside the `<email_content untrusted="true">`
  wrapper or be neutralised; nothing may ever let a tool argument or mail
  content influence the SMTP envelope; no secrets in logs, errors or
  diagnostics. A change that weakens any of this needs a very good reason
  and a test that pins the new behaviour. See `docs/threat-model.md`.
- **No runtime dependencies.** The server must keep running from a plain
  upload on shared hosting: PHP 8.2+, `json`, `openssl`, `mbstring`.
  Dev dependencies (PHPUnit, PHPStan, php-cs-fixer, the MCP conformance
  CLI) are fine.
- **Tests with every change.** Unit tests for logic, scripted-socket
  tests for anything that touches the wire, and the integration suite
  when behaviour against a real server changes. PHPStan level 8 and
  php-cs-fixer must stay clean.
- **Small, focused pull requests** with a clear description of the
  problem, the fix and how you verified it.

## Development setup

```
composer install
npm ci                       # only for the MCP conformance suite
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer check --diff
bin/conformance.sh           # both protocol eras must stay green
```

Integration tests run against a real or GreenMail IMAP/SMTP server given
by `MAILBOX_MCP_TEST_*` environment variables; see
`tests/Integration/README.md`. Use a dedicated test mailbox: the suite
appends messages and sends mail to the identity address.

## Reporting security issues

Please do not open a public issue for a vulnerability. Use GitHub's
private vulnerability reporting as described in `SECURITY.md`.

## How this project is developed

The code was written with substantial help from Claude Code (Anthropic's
agentic coding tool): each change is planned from the written design in
`docs/superpowers/specs/`, implemented with tests, and reviewed by an
independent review pass before it lands. Human judgement decides what
gets built and what ships. Contributions written with or without AI
assistance are equally welcome as long as they meet the rules above and
you have read and understood what you submit.

## License

By contributing you agree that your contribution is licensed under the
MIT license of this repository. Files derived from third-party code keep
their original notices (see `NOTICE`).
