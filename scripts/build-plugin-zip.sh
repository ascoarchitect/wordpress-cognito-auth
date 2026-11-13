#!/bin/bash
# Builds the installable plugin zip, plugin/wp-cognito-auth.zip, from the
# COMMITTED plugin/ tree at HEAD (git archive), so local edits, vendor/ and other
# untracked files never reach a site.
#
#   scripts/build-plugin-zip.sh            # writes plugin/wp-cognito-auth.zip
#   scripts/build-plugin-zip.sh <out.zip>  # writes elsewhere
#
# The zip's top folder is wp-cognito-auth/, so WordPress installs it as
# wp-content/plugins/wp-cognito-auth and offers "Replace current with uploaded"
# over an existing install. The plugin has no runtime Composer dependencies (its
# own autoloader loads includes/), so tests, PHPUnit config and Composer files
# are left out. Refuses to run when the header Version and WP_COGNITO_AUTH_VERSION
# disagree, or when any PHP file fails `php -l`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/plugin/wp-cognito-auth.zip}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/wp-cognito-auth"
mkdir -p "$STAGE"

git -C "$ROOT" archive HEAD:plugin | tar -x -C "$STAGE"
rm -rf "$STAGE/tests" "$STAGE/phpunit.xml" "$STAGE/composer.json" "$STAGE/composer.lock" \
       "$STAGE/vendor" "$STAGE/wp-cognito-auth.zip"
find "$STAGE" \( -name .DS_Store -o -name '._*' \) -delete

HEADER="$(sed -n 's/^ \* Version: *//p' "$STAGE/wp-cognito-auth.php" | head -1)"
CONST="$(sed -n "s/^define( *'WP_COGNITO_AUTH_VERSION', *'\([^']*\)' *);.*/\1/p" "$STAGE/wp-cognito-auth.php" | head -1)"
[ -n "$HEADER" ] && [ "$HEADER" = "$CONST" ] \
  || { echo "Version mismatch: header '$HEADER' vs WP_COGNITO_AUTH_VERSION '$CONST'" >&2; exit 1; }

if command -v php >/dev/null; then
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null || { php -l "$f" >&2; exit 1; }
  done < <(find "$STAGE" -name '*.php' -print0)
else
  echo "warning: php not found; skipping php -l" >&2
fi

rm -f "$OUT"
(cd "$TMP" && zip -qrX "$OUT" wp-cognito-auth)
echo "Built $OUT: wp-cognito-auth $HEADER from $(git -C "$ROOT" rev-parse --short HEAD), $(unzip -l "$OUT" | tail -1 | awk '{print $2}') files"
