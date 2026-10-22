# Deployment (shared hosting, cPanel/DirectAdmin style)

This walks through installing mailbox-mcp on a typical shared-hosting
account with cPanel or DirectAdmin, Apache or LiteSpeed, and SSH access.
Replace every `example.com` / `mcp.example.com` below with your own
domain; nothing here should ever contain a real hostname, mailbox
address or token once filled in on your own machine.

## 1. Layout

Keep the application code, its config and its writable state **outside**
the web root, and copy only the three files the web server needs to
serve into the subdomain's document root.

**Recommended: everything inside the domain directory.** On
CloudLinux/LiteSpeed shared hosting, web PHP runs with a per-domain
`open_basedir` (for example
`/home/youruser/domains/mcp.example.com/:/tmp:...`), so PHP served for
`mcp.example.com` cannot read anything in your home directory outside
that domain's folder. Put the app and config next to `public_html`, not
in it:

```
~/domains/mcp.example.com/public_html/          docroot: index.php, .htaccess, diag.php (from public/)
                                                 + mailbox-mcp.paths.php (see section 3)
~/domains/mcp.example.com/app/                   src/, bin/, var/   (chmod 700 var)
~/domains/mcp.example.com/config/config.php      chmod 600
~/domains/mcp.example.com/config/guidelines/*.md
```

This still keeps code, config and state out of the web root. `diag.php`
reports the effective `open_basedir` value, so you can see which
directories web PHP may use.

**Alternative, when `open_basedir` allows it** (no `open_basedir`, or
one that includes your home directory):

```
~/domains/mcp.example.com/public_html/   docroot: index.php, .htaccess, diag.php
~/mailbox-mcp/                            src/, bin/, var/   (chmod 700 var)
~/mailbox-mcp-config/config.php           chmod 600
~/mailbox-mcp-config/guidelines/*.md
```

The commands below use the recommended layout; substitute your own
paths for the alternative one.

`public_html` here is whatever directory your host maps to
`https://mcp.example.com/` — on DirectAdmin that is typically
`~/domains/<subdomain>/public_html/`, on cPanel usually
`~/public_html/<subdomain>/` or a dedicated "Subdomains" document
root. Create the subdomain and its SSL certificate (Let's Encrypt via
AutoSSL/DirectAdmin SSL is usually one click) before continuing.

## 2. Upload

**Recommended: the release zip** (built by `bin/build.sh`, or downloaded
from the project's GitHub releases). It contains only what runs on the
server — no `vendor/`, tests or development files.

**Via SSH/rsync** from a checkout of this repository, excluding
everything that must not end up on the server:

```
rsync -av \
    --exclude='vendor/' --exclude='node_modules/' --exclude='tests/' \
    --exclude='.superpowers/' --exclude='.git/' --exclude='dist/' \
    --exclude='var/' --exclude='config/config.php' \
    ./ youruser@yourhost:~/domains/mcp.example.com/app/
```

Then copy the three web files into the docroot:

```
APP=~/domains/mcp.example.com/app
DOCROOT=~/domains/mcp.example.com/public_html
cp $APP/public/index.php  $DOCROOT/index.php
cp $APP/public/.htaccess  $DOCROOT/.htaccess
cp $APP/public/diag.php   $DOCROOT/diag.php
cp -n $APP/public/mailbox-mcp.paths.example.php $DOCROOT/mailbox-mcp.paths.php   # first install only
```

**Via the hosting file manager** (when SSH is not available): upload
the release zip into `~/domains/mcp.example.com/`, extract it there with
the file manager's "Extract" action and rename the extracted folder to
`app`, then use its copy/move function to place `index.php`,
`.htaccess` and `diag.php` from `app/public/` into `public_html/` (and
`mailbox-mcp.paths.example.php` as `public_html/mailbox-mcp.paths.php`
— first install only; on an upgrade keep your filled-in file).
Leave everything else (`src/`, `bin/`, `config/`) outside the web root.

## 3. Configure

```
APP=~/domains/mcp.example.com/app
CONF=~/domains/mcp.example.com/config
mkdir -p $CONF/guidelines $APP/var
cp $APP/config/config.example.php $CONF/config.php
cp $APP/config/guidelines.example.md $CONF/guidelines/example.md
chmod 700 $APP/var
chmod 600 $CONF/config.php
```

Edit `config.php`: fill in `paths.var` (the `var` directory above, e.g.
`/home/youruser/domains/mcp.example.com/app/var`), your account(s)
(`imap`/`smtp` host, port, encryption, username, password),
`guidelines_file` paths, and generate a token:

```
php ~/domains/mcp.example.com/app/bin/diag.php --new-token
```

Paste the printed value into `auth.token`.

### Pointing the app at the config

`public/index.php` and `public/diag.php` resolve two paths — the
application directory (containing `src/`, `bin/`) and the config file —
taking, for each, the first of:

1. the environment variables `MAILBOX_MCP_APP` / `MAILBOX_MCP_CONFIG`;
2. a `mailbox-mcp.paths.php` file next to `index.php`;
3. the defaults: one level above the docroot, and
   `<app>/config/config.php`.

**Recommended: the paths file.** Edit the
`public_html/mailbox-mcp.paths.php` you copied in step 2 (from
`public/mailbox-mcp.paths.example.php`) so it returns your absolute
paths:

```php
<?php

return [
    'app' => '/home/youruser/domains/mcp.example.com/app',
    'config' => '/home/youruser/domains/mcp.example.com/config/config.php',
];
```

It holds no secret, and the shipped `.htaccess` never serves it (only
`index.php` and `diag.php` are reachable). It is in `.gitignore` and is
never included in a release zip, so an upgrade does not overwrite it —
as long as you do not copy the example over it again (the `cp -n` in
step 2 never does).

**Alternative: `SetEnv`**, on hosts where Apache environment variables
reach PHP (they never do on LiteSpeed/CloudLinux): add to the docroot
`.htaccess`

```
SetEnv MAILBOX_MCP_APP /home/youruser/domains/mcp.example.com/app
SetEnv MAILBOX_MCP_CONFIG /home/youruser/domains/mcp.example.com/config/config.php
```

**Last resort:** edit the two `$appDir = …` / `$configPath = …` lines in
`index.php` and `diag.php` directly, replacing the `dirname(__DIR__)`
and `$appDir . '/config/config.php'` defaults with your paths. Every
upgrade overwrites this, so prefer the paths file.

## 4. Verify with diagnostics

Set `diag.enabled` to `true` in `config.php`, then:

```
php ~/domains/mcp.example.com/app/bin/diag.php --config ~/domains/mcp.example.com/config/config.php
```

This prints a JSON report: PHP version, the effective `open_basedir`,
loaded extensions, whether
`config.php` and `var/` are outside the docroot and have safe
permissions, and (unless you pass `--no-mail`) a live IMAP/SMTP
connection test per account with detected folder roles. It exits 1 if
anything required is missing or any account probe failed.

Then open `https://mcp.example.com/diag.php` in a browser, enter the
token in the form, and confirm the same report renders — this also
confirms the whole web stack (`.htaccess`, PHP, TLS) works end to end.

Once everything looks good, **set `diag.enabled` back to `false`** and
save the config again (`chmod 600` stays as-is).

### If `authorization_header_reaches_php` is false

claude.ai and Claude Code send the bearer token as an `Authorization`
header, but some Apache/LiteSpeed configurations strip it before PHP
ever sees it. Check:

- The docroot `.htaccess` must contain (already included in
  `public/.htaccess`):

  ```
  RewriteCond %{HTTP:Authorization} ^(.+)$
  RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]
  SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
  ```

- On Apache with `mod_php`/PHP-FPM via a proxy, also confirm
  `CGIPassAuth On` is not disabled by a parent config, or ask your host
  to enable it.
- On LiteSpeed (and LSAPI), the `SetEnvIf`/`RewriteRule` pair above is
  usually enough; if not, LiteSpeed's `Rewrite` rules support the same
  `E=HTTP_AUTHORIZATION:%1` mechanism in a `.htaccess`-equivalent panel
  rule — ask your host to confirm `Authorization` passthrough is not
  blocked at the web server level.
- As a fallback, enable `auth.allow_url_token` and use the
  `/t/<token>/mcp` URL form (see
  [`docs/claude-ai.md`](claude-ai.md#url-token-fallback) for the
  trade-offs) until the header issue is resolved.

### Every URL returns 500, even `diag.php`

Both entry points fail before they can produce a proper response when
PHP cannot find the app directory (or the config): the
`require …/src/autoload.php` fails. Check, in order:

- `mailbox-mcp.paths.php` exists next to `index.php`, returns an array,
  and both paths are absolute and correct (`ls` them over SSH).
- If you used `SetEnv` instead: on LiteSpeed/CloudLinux it never
  reaches PHP — switch to the paths file.
- The PHP error log (or a one-line probe script in the docroot that
  `require`s your `src/autoload.php`) shows
  `open_basedir restriction in effect`: web PHP is confined to the
  domain directory, so the app and config must move inside
  `~/domains/mcp.example.com/` (the recommended layout in section 1).

### Locked out?

After `security.lockout_failures` wrong tokens (within
`security.lockout_window_minutes`) the client IP gets a plain 404 for
every request for `security.lockout_minutes` — including `diag.php`,
even with the right token. Either wait for the lockout to expire, or
delete the lockout files over SSH:

```
rm ~/domains/mcp.example.com/app/var/lockout/*.json
```

### Behind a TLS-terminating proxy

If a reverse proxy or CDN terminates HTTPS and talks plain HTTP to the
host, `%{HTTPS}` is never `on` and the HTTPS redirect at the top of
`.htaccess` loops forever. In that setup remove the two lines

```
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

and list the proxy's address(es) in `security.trusted_proxies`, so the
server takes the client IP and the HTTPS status from the proxy's
forwarding headers.

## 5. Fallback: nothing can live outside the web root

If your host only serves one directory and gives no way to place files
above it, keep everything under the docroot but deny direct access to
everything except the two entry scripts:

```
~/public_html/
  index.php
  .htaccess
  diag.php
  app/            <- src/, bin/
  config/         <- config.php (still chmod 600)
  var/            <- chmod 700
```

Add a `.htaccess` with `Deny from all` (or, on Apache 2.4+,
`Require all denied`) inside `app/`, `config/` and `var/` so a direct
request for e.g. `/config/config.php` or `/var/audit/2026-10-22.jsonl`
never reaches the file. `public/diag.php`'s `config_outside_docroot`
and `var_outside_docroot` fields will show `false` in this layout —
that is the expected warning for this fallback, not a bug; it means
those paths are reachable in principle if the `Deny from all` rule
were ever misconfigured or unsupported by the server, so double-check
those directories are not listable and the deny rule is actually in
effect (request `/var/` and `/config/config.php` directly from a
browser — both should return 403 or 404, never the file).
