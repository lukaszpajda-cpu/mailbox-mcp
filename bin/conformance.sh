#!/usr/bin/env bash
#
# Runs the MCP conformance suite (https://github.com/modelcontextprotocol/conformance)
# against tests/Conformance/server.php: starts it with PHP's built-in
# server, waits for it to accept connections, runs the suite with the
# expected-failures baseline, prints the result, stops the server, and
# exits with the suite's status.
#
# Usage: bin/conformance.sh (or `npm run conformance`)

set -euo pipefail

# Pre-release build of the conformance CLI that understands the modern
# (2026-07-28, per-request _meta) protocol era; the stable run below keeps
# using the pinned package.json devDependency (legacy eras only).
MODERN_CONFORMANCE_VERSION="0.2.0-alpha.12"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="127.0.0.1"
PORT="8765"
SERVER_SCRIPT="$ROOT_DIR/tests/Conformance/server.php"
CONFIG_SCRIPT="$ROOT_DIR/tests/Conformance/config.php"
EXPECTED_FAILURES="$ROOT_DIR/tests/Conformance/expected-failures.yaml"
EXPECTED_FAILURES_MODERN="$ROOT_DIR/tests/Conformance/expected-failures-modern.yaml"

TOKEN="$(php -r '$c = require $argv[1]; echo $c["auth"]["token"];' "$CONFIG_SCRIPT")"
VAR_DIR="$(php -r '$c = require $argv[1]; echo $c["paths"]["var"];' "$CONFIG_SCRIPT")"

# Start from a clean state (rate limit / lockout counters) every run.
rm -rf "$VAR_DIR"

LOG_FILE="$(mktemp -t mailbox-mcp-conformance-server.XXXXXX)"

php -S "$HOST:$PORT" "$SERVER_SCRIPT" >"$LOG_FILE" 2>&1 &
SERVER_PID=$!

cleanup() {
    kill "$SERVER_PID" >/dev/null 2>&1 || true
    wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

READY=0

for _ in $(seq 1 50); do
    if curl -s -o /dev/null "http://$HOST:$PORT/mcp"; then
        READY=1
        break
    fi

    sleep 0.1
done

if [ "$READY" -ne 1 ]; then
    echo "mailbox-mcp conformance: server did not start; log follows:" >&2
    cat "$LOG_FILE" >&2
    exit 1
fi

URL="http://$HOST:$PORT/t/$TOKEN/mcp"

set +e
npx --yes @modelcontextprotocol/conformance server \
    --url "$URL" \
    --expected-failures "$EXPECTED_FAILURES" \
    --verbose
STABLE_STATUS=$?
set -e

echo
echo "=== modern suite (spec-version 2026-07-28, conformance@$MODERN_CONFORMANCE_VERSION) ==="
echo

set +e
npx -y "@modelcontextprotocol/conformance@${MODERN_CONFORMANCE_VERSION}" server \
    --url "$URL" \
    --suite all \
    --spec-version 2026-07-28 \
    --expected-failures "$EXPECTED_FAILURES_MODERN" \
    --verbose
MODERN_STATUS=$?
set -e

if [ "$STABLE_STATUS" -ne 0 ] || [ "$MODERN_STATUS" -ne 0 ]; then
    exit 1
fi

exit 0
