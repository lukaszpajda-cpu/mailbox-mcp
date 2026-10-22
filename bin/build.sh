#!/usr/bin/env bash
#
# Runs the test suite and static analysis, then packages a release zip
# at dist/mailbox-mcp-<version>.zip containing everything an operator
# needs to deploy: public/, src/, the CLI diagnostics script, this
# build script itself, the example config/guidelines, the
# license/notice, the README/changelog and the docs (excluding
# docs/superpowers, which is this project's own planning material and
# never ships).
#
# Usage: bin/build.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

vendor/bin/phpunit --testsuite Unit
vendor/bin/phpstan analyse

VERSION="$(php -r 'require "src/Version.php"; echo MailboxMcp\Version::STRING;')"

DIST_DIR="$ROOT_DIR/dist"
ZIP_NAME="mailbox-mcp-$VERSION.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT

PKG_DIR="$STAGE_DIR/mailbox-mcp-$VERSION"
mkdir -p "$PKG_DIR"

cp -R "$ROOT_DIR/public" "$PKG_DIR/public"
# a developer's local paths file is never shipped (only the .example one)
rm -f "$PKG_DIR/public/mailbox-mcp.paths.php"
cp -R "$ROOT_DIR/src" "$PKG_DIR/src"

mkdir -p "$PKG_DIR/bin"
cp "$ROOT_DIR/bin/diag.php" "$PKG_DIR/bin/diag.php"
cp "$ROOT_DIR/bin/build.sh" "$PKG_DIR/bin/build.sh"

mkdir -p "$PKG_DIR/config"
cp "$ROOT_DIR/config/config.example.php" "$PKG_DIR/config/config.example.php"
cp "$ROOT_DIR/config/guidelines.example.md" "$PKG_DIR/config/guidelines.example.md"

cp "$ROOT_DIR/LICENSE" "$PKG_DIR/LICENSE"
cp "$ROOT_DIR/NOTICE" "$PKG_DIR/NOTICE"
cp "$ROOT_DIR/README.md" "$PKG_DIR/README.md"
cp "$ROOT_DIR/CHANGELOG.md" "$PKG_DIR/CHANGELOG.md"

mkdir -p "$PKG_DIR/docs"
find "$ROOT_DIR/docs" -mindepth 1 -maxdepth 1 ! -name superpowers -exec cp -R {} "$PKG_DIR/docs/" \;

(cd "$STAGE_DIR" && zip -rq "$ZIP_PATH" "mailbox-mcp-$VERSION")

SIZE="$(du -h "$ZIP_PATH" | cut -f1 | tr -d '[:space:]')"

echo "Built $ZIP_PATH ($SIZE)"
