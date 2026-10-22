# Connecting from claude.ai

mailbox-mcp speaks MCP over Streamable HTTP, so it works as a claude.ai
**custom connector**. Connectors are added on the web app and then sync
to the iOS/Android apps automatically — no separate mobile setup step.

## Add the connector (Request headers — preferred)

1. On claude.ai: **Settings → Connectors → Add custom connector**.
2. **Name**: anything you like, e.g. "Mailbox".
3. **URL**: `https://mcp.example.com/mcp`
4. **Authentication**: choose **"No sign in"** — mailbox-mcp uses its
   own bearer token, not OAuth, so no sign-in flow is needed.
5. **Request headers**: add

   ```
   Authorization: Bearer <your-token>
   ```

6. Save, then open a chat and try the connector.

> The "Request headers" field for custom connectors is a beta feature
> that Anthropic is rolling out gradually; if you don't see it on your
> account yet, use the URL-token fallback below instead.

## URL-token fallback

If your claude.ai account does not yet have the "Request headers"
field, you can instead put the token in the URL path:

1. In `config.php`, set `auth.allow_url_token = true`.
2. Use this URL as the connector's URL instead of `/mcp`:

   ```
   https://mcp.example.com/t/<your-token>/mcp
   ```

3. Leave Authentication as "No sign in" and add no request headers.

**Risks of the URL-token form** — understand these before enabling it:

- The token ends up in your browser's history and autofill.
- Any HTTP proxy, corporate network appliance, or ad-blocker/extension
  that inspects URLs can see it (HTTPS protects it in transit, but not
  from anything running inside the browser or the connector client
  itself).
- It appears in access logs on your own server (and potentially on any
  intermediate CDN/proxy you use) exactly like any other URL path
  segment.

If the token leaks, rotate it immediately: run
`php bin/diag.php --new-token`, put the new value in `config.php`, and
update the connector URL (or header) on every client.

Prefer the Authorization-header route whenever it's available; treat
the URL-token route as a temporary workaround.

## First test prompt

Once the connector shows as connected, try:

> Show my 5 latest emails.

Claude should call `list_accounts` and/or `search_emails` and show you
a short, wrapped summary of the newest messages — confirming the
connector, the token and the mailbox connection all work.

## Troubleshooting

- **404 on every call** — almost always a wrong token, a wrong path
  (should end in `/mcp`, or `/t/<token>/mcp` with the fallback), or the
  URL is not HTTPS (mailbox-mcp refuses plain HTTP by default). Run
  `public/diag.php` (see [`docs/deployment.md`](deployment.md)) to
  confirm the token and config are correct.
- **Locked out?** — after `security.lockout_failures` wrong tokens
  (e.g. while you were still pasting the header) your IP gets a plain
  404 for `security.lockout_minutes`, on every URL including
  `diag.php`, even once the token is right. Wait for it to expire, or
  delete `var/lockout/*.json` on the server.
- **429 responses** — you've hit the per-IP or global rate limit
  (`security.rate_limit_per_minute` / `rate_limit_global_per_minute`);
  wait for the `Retry-After` period or raise the limit in `config.php`.
- **403 origin rejected** — only relevant if `security.allowed_origins`
  is non-empty and a browser-originated request sent an `Origin` header
  that isn't on the list; non-browser clients (Claude Code, MCP
  Inspector) normally send no `Origin` at all, so this should not
  affect them. Add the exact `scheme://host[:port]` you're calling from
  if you do see it.
