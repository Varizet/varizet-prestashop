#!/usr/bin/env bash
# Prints the PHP SDK directory (varizet/varizet-php) that the tests and the zip use, in this order:
# $VARIZET_SDK_DIR; the SDK next to this module in the Varizet monorepo; or, in a standalone checkout,
# .sdk/varizet-php cloned from github.com/Varizet/varizet-php at the version in .sdk-version.
#   bin/sdk.sh        # fetches on first use, then prints the path
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [ -n "${VARIZET_SDK_DIR:-}" ]; then echo "$VARIZET_SDK_DIR"; exit 0; fi
if [ -f "$ROOT/../../packages/sdk-php/autoload.php" ]; then (cd "$ROOT/../../packages/sdk-php" && pwd); exit 0; fi
VERSION="$(tr -d '[:space:]' < "$ROOT/.sdk-version")"
DEST="$ROOT/.sdk/varizet-php"
if [ "$(cat "$DEST/.fetched-version" 2>/dev/null || true)" != "$VERSION" ]; then
  rm -rf "$DEST"
  git clone --quiet --depth 1 --branch "v$VERSION" https://github.com/Varizet/varizet-php.git "$DEST" >&2
  echo "$VERSION" > "$DEST/.fetched-version"
fi
echo "$DEST"
