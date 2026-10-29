#!/usr/bin/env bash
# Builds dist/abacatepay-for-woocommerce-v<version>.zip from the working tree, leaving out what .distignore lists.
# The files go inside an abacatepay-for-woocommerce/ folder, so WordPress installs the plugin under its own slug.
set -euo pipefail

cd "$(dirname "$0")"

SLUG="abacatepay-for-woocommerce"
VERSION=$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' "$SLUG.php")

if [ -z "$VERSION" ]; then
  echo "Could not read the version from $SLUG.php" >&2
  exit 1
fi

ZIP="dist/$SLUG-v$VERSION.zip"
BUILD=$(mktemp -d)
trap 'rm -rf "$BUILD"' EXIT

mkdir -p "$BUILD/$SLUG" dist
rsync -a --exclude-from=.distignore ./ "$BUILD/$SLUG/"

rm -f "$ZIP"
(cd "$BUILD" && zip -rqX - "$SLUG") > "$ZIP"

echo "$ZIP"
