# Security policy

## Supported versions

Only the latest released version of mailbox-mcp is supported with
security fixes. Please upgrade to the latest release before reporting
an issue.

## Reporting a vulnerability

Please do not open a public issue for a security vulnerability.
Instead, report it privately through **GitHub private vulnerability
reporting**: open the repository's "Security" tab and use "Report a
vulnerability" to start a private advisory.

Please include:

- A description of the issue and its impact.
- Steps to reproduce (a minimal config and request/tool call sequence
  if possible).
- The version/commit you tested against.

We will acknowledge reports as soon as practical and aim to provide a
fix or mitigation before any public disclosure.

## Threat model

See [`docs/threat-model.md`](docs/threat-model.md) for the full list of
threats this project defends against (and, in its last section, what it
explicitly does not protect against).
