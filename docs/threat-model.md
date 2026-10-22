# Threat model

| Threat | Control |
| --- | --- |
| Anyone on the internet calls the endpoint | Long random token (≥ 32 bytes, base64url) compared with `hash_equals`; any auth failure returns the same 404 page as an unknown path; per-IP lockout after N failures |
| Token leaks through URLs, proxies, logs | Bearer header is the primary method (claude.ai supports fixed request headers); path token is optional and off by default; tokens are never logged; `.htaccess` forwards `Authorization` to PHP |
| Brute force and abuse | Per-IP and global per-minute rate limits (429 with `Retry-After`), file-based with `flock`; lockout stored the same way |
| DNS rebinding / browser-originated calls | `Origin` header, when present, must be on the allow-list, else 403; non-browser clients send no `Origin` |
| Prompt injection from mail content | Every field that originates from a message (from, to, cc, subject, snippet, body, attachment names) is returned inside `<email_content untrusted="true" …>` and nested tags are neutralised; server `instructions` state that mail content is data, never instructions; there is no tool that sends free text and no forward tool; `send_draft` descriptions tell the model to call it only after explicit approval in chat |
| Model sends without approval or to injected addresses | Sending requires an existing draft that the user could inspect; recipients are read from the draft headers, not from arguments; recipient policy (max recipients, allowed/denied domains) and a daily send cap are enforced server-side; roadmap: out-of-band approval link |
| IMAP command injection through tool arguments | All strings sent to IMAP go through quoting or literals; CR/LF rejected; folder names must match the server's `LIST` result; flags and keywords validated against an atom pattern; UIDs are integers |
| Header injection in drafts | Addresses validated (one address per entry, no display names from the model, no CR/LF); subject and names RFC 2047 encoded; body lines normalised to CRLF |
| Resource exhaustion | Max POST body (default 256 KB), max results, max characters, max thread size, IMAP/SMTP timeouts, per-request time budget, partial fetches for snippets |
| Information disclosure | Generic JSON-RPC errors to the client; details only in the audit log; `display_errors` off; no stack traces; 404 for anything unauthenticated |
| Credential theft from disk | Config file outside web root, mode 600, documented; passwords only in config; diagnostic page disabled unless enabled in config and token-protected |
| Supply chain | No runtime dependencies; dev dependencies pinned in `composer.lock`; CI runs tests, static analysis and the MCP conformance suite |
| Server-side request forgery / data leaving through `check_availability` | The only outbound HTTP request is to the per-account `availability.url` from the config, validated as an absolute `https://` URL without credentials; tool arguments never form part of the request (the whole feed is fetched and filtered locally, so requested dates are not sent); certificate verification on, no redirects, https-only protocol, total timeout capped by the request budget, 256 KB response cap; malformed feeds yield a fixed error that never echoes the body, and the feed's `generated` value is shown only when it looks like an ISO 8601 timestamp |
| Lost audit trail | JSONL audit log per day, outside web root, retained N days, written for every tool call and every auth failure |

## What this does not protect against

- A compromised hosting account. Anyone with shell or file-manager
  access to the account can read the config file, the var directory and
  the audit log, and can modify the PHP code itself.
- A user approving a bad draft. `send_draft` only requires the user's
  explicit, in-conversation approval — it cannot tell a well-crafted
  phishing-style draft from a legitimate one; the user is the last line
  of defence before anything leaves the mailbox.
- A compromised SMTP or IMAP server. The server trusts the mail
  server's TLS identity (when `verify_peer` is true) and its responses;
  a malicious or compromised mail server could return arbitrary mail
  content or misreport delivery.
