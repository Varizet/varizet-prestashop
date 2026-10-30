#!/usr/bin/env bash
# Builds dist/varizet-prestashop-<version>.zip with a single varizet/ folder (what PrestaShop's
# module uploader expects), the PHP SDK bundled in varizet/lib/varizet-php and the usual
# index.php guard in every directory.
#   bin/build-zip.sh                 # zip
#   bin/build-zip.sh --stage <dir>   # only assemble the module into <dir>/varizet (dev/smoke)
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SDK="$("$ROOT/bin/sdk.sh")"
VERSION="$(sed -n "s/.*public const VERSION = '\(.*\)';/\1/p" "$ROOT/varizet/src/Settings.php")"
[ -n "$VERSION" ] || { echo "no VERSION in Settings.php" >&2; exit 1; }
grep -q "^## \[$VERSION\]" "$ROOT/CHANGELOG.md" || { echo "CHANGELOG.md has no [$VERSION] entry" >&2; exit 1; }

assemble() {
  local dest="$1/varizet"
  rm -rf "$dest"
  mkdir -p "$dest"
  (cd "$ROOT/varizet" && tar cf - --exclude ./lib .) | (cd "$dest" && tar xf -)
  "$SDK/bin/bundle.sh" "$dest/lib/varizet-php" >/dev/null
  cp "$ROOT/CHANGELOG.md" "$dest/"
  find "$dest" \( -name '.DS_Store' -o -name '.gitkeep' \) -delete
  find "$dest" -type d | while read -r d; do
    [ -f "$d/index.php" ] || cat > "$d/index.php" <<'PHP'
<?php
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ../');
exit;
PHP
  done
}

if [ "${1:-}" = "--stage" ]; then
  assemble "${2:?usage: build-zip.sh --stage <dir>}"
  echo "staged $2/varizet"
  exit 0
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
assemble "$STAGE"
if command -v php >/dev/null 2>&1; then
  find "$STAGE" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
else
  docker run --rm -v "$STAGE":/p -w /p php:8.1-cli sh -c 'find . -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null'
fi
mkdir -p "$ROOT/dist"
ZIP="$ROOT/dist/varizet-prestashop-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr -X "$ZIP" varizet)
(cd "$ROOT/dist" && shasum -a 256 "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")
echo "built $ZIP ($(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files) sha256 $(cut -d' ' -f1 "$ZIP.sha256")"
