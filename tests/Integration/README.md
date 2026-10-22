# Integration tests

Everything under `tests/Integration` exercises the public tool surface
(`Registry::call()`, built with the real `MailSessionFactory`) against a
real IMAP/SMTP server. Nothing here is mocked; every test talks to actual
sockets.

Every test skips, with a clear message, unless `MAILBOX_MCP_TEST_IMAP_HOST`
is set:

```
vendor/bin/phpunit --testsuite Integration
```

## Environment variables

| Variable                          | Required | Default        |
|------------------------------------|----------|----------------|
| `MAILBOX_MCP_TEST_IMAP_HOST`        | yes      | —              |
| `MAILBOX_MCP_TEST_IMAP_PORT`        | yes      | —              |
| `MAILBOX_MCP_TEST_IMAP_ENCRYPTION`  | no       | `none`         |
| `MAILBOX_MCP_TEST_SMTP_HOST`        | yes      | —              |
| `MAILBOX_MCP_TEST_SMTP_PORT`        | yes      | —              |
| `MAILBOX_MCP_TEST_SMTP_ENCRYPTION`  | no       | `none`         |
| `MAILBOX_MCP_TEST_USER`             | yes      | —              |
| `MAILBOX_MCP_TEST_PASSWORD`         | yes      | —              |
| `MAILBOX_MCP_TEST_EMAIL`            | no       | `$MAILBOX_MCP_TEST_USER` |
| `MAILBOX_MCP_TEST_VERIFY_PEER`      | no       | `0`            |

`MAILBOX_MCP_TEST_EMAIL` is the account's sending identity (the `From`
address of every draft, and the address the self-send test mails to). It
only needs to differ from `MAILBOX_MCP_TEST_USER` when the server's login
name isn't itself a usable email address.

`encryption: none` is only accepted (by `Config\Validator`) when the host
is `127.0.0.1`, `::1` or `localhost` — fine for a local test server, never
for a real mailbox over the network.

## Running locally against GreenMail

[GreenMail](https://greenmail-mail-test.github.io/greenmail/) is a small
Java mail server for exactly this purpose: it needs no setup beyond one
user.

### Docker

```
docker run --rm -it \
  -p 3025:3025 -p 3143:3143 -p 3465:3465 -p 3587:3587 -p 3993:3993 \
  -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 -Dgreenmail.auth.disabled=false -Dgreenmail.users=test:test@localhost -Dgreenmail.verbose' \
  greenmail/standalone:2.1.3
```

### Standalone jar (no Docker; needs a JRE)

```
curl -L -o greenmail-standalone.jar \
  https://repo1.maven.org/maven2/com/icegreen/greenmail-standalone/1.6.15/greenmail-standalone-1.6.15.jar
java -Dgreenmail.setup.test.all -Dgreenmail.hostname=127.0.0.1 \
  -Dgreenmail.users=test:test@localhost -Dgreenmail.verbose \
  -jar greenmail-standalone.jar
```

(1.6.15 is the latest release that still runs on a Java 8 JRE; any newer
JRE can run the Docker image's 2.1.3 jar directly instead.)

GreenMail's default ports with `greenmail.setup.test.all` are IMAP 3143,
IMAPS 3993, SMTP 3025, SMTPS 3465, POP3 3110, POP3S 3995. CI (GreenMail
2.1.3, with `-Dgreenmail.auth.disabled=false`) logs in with the **full
address** `test@localhost` as the username, password `test`.

The standalone **1.6.15 jar used for a local, no-Docker run behaves
differently**: with no `greenmail.auth.disabled` property at all, only
the short login `test` (not `test@localhost`) authenticates over IMAP;
adding `-Dgreenmail.auth.disabled=false` to try to force full-address
login instead makes the *second* login attempt crash the handling thread
with `IllegalStateException: Mailbox … already exists` (a GreenMail 1.6.15
bug — `UserManager.test()` tries to re-create the already-configured
mailbox). So for a local 1.6.15 run, set `MAILBOX_MCP_TEST_USER=test`
(the short login) and leave `MAILBOX_MCP_TEST_EMAIL=test@localhost` (the
identity used for `From`/self-send) — this is exactly the username/
identity split `MAILBOX_MCP_TEST_EMAIL` exists for. SMTP delivery to
`test@localhost` lands in the same mailbox reachable via the short IMAP
login, so the self-send round trip still works correctly. The Docker
image (2.1.3) was not available to verify locally in this environment;
if it turns out to share 1.6.15's short-login requirement, use the same
split there too.

### Run the suite

Against the Docker image (2.1.3), or any server that logs in with a full
address:

```
export MAILBOX_MCP_TEST_USER=test@localhost
```

Against the standalone 1.6.15 jar started as shown above (see the note
on its login quirk):

```
export MAILBOX_MCP_TEST_USER=test
```

Either way:

```
export MAILBOX_MCP_TEST_IMAP_HOST=127.0.0.1
export MAILBOX_MCP_TEST_IMAP_PORT=3143
export MAILBOX_MCP_TEST_IMAP_ENCRYPTION=none
export MAILBOX_MCP_TEST_SMTP_HOST=127.0.0.1
export MAILBOX_MCP_TEST_SMTP_PORT=3025
export MAILBOX_MCP_TEST_SMTP_ENCRYPTION=none
export MAILBOX_MCP_TEST_PASSWORD=test
export MAILBOX_MCP_TEST_EMAIL=test@localhost
export MAILBOX_MCP_TEST_VERIFY_PEER=0

vendor/bin/phpunit --testsuite Integration
```

GreenMail starts with only `INBOX`; it advertises no `SPECIAL-USE`, so the
tests create `Drafts` and `Sent` themselves (via the new
`ImapClientInterface::createFolder()`) the first time they need them, and
tolerate a tagged `NO` when a previous run already created them.

Tests append their own fixture messages and leave everything else alone;
nothing is cleaned up afterwards, and each run uses a fresh random tag in
its subjects so repeated runs never collide with earlier leftovers. A
throwaway GreenMail instance can simply be discarded between runs.

## Running against a real mailbox

**Use a dedicated test account, never a real mailbox you care about.**
These tests append messages to `INBOX`, create `Drafts`/`Sent` folders if
missing, create and send drafts, and — because the self-send test mails
the account's own identity address — deliver real mail into that same
account. They never touch any other recipient (`send_draft`'s
denied-domain test never actually sends), but the account under test will
accumulate test messages across runs.

Point the environment variables in the table above at the real server
(with `encryption: tls` or `starttls` and a real port — `none` is refused
for anything but localhost) and an app password or dedicated test
mailbox's credentials, then run:

```
vendor/bin/phpunit --testsuite Integration
```
