#!/usr/bin/env bash
# Publishes a zip built by pack.sh to the WordPress.org plugin directory: replaces trunk and creates tags/<version>.
#
#   SVN_USERNAME=... SVN_PASSWORD=... scripts/svn-release.sh dist/abacatepay-for-woocommerce-v1.2.3.zip
#
# SVN_REPO_URL defaults to https://plugins.svn.wordpress.org/abacatepay-for-woocommerce.
set -euo pipefail

SLUG="abacatepay-for-woocommerce"
ZIP="${1:?Usage: scripts/svn-release.sh <zip>}"
SVN_REPO_URL="${SVN_REPO_URL:-https://plugins.svn.wordpress.org/$SLUG}"
SVN_REPO_URL="${SVN_REPO_URL%/}"

: "${SVN_USERNAME:?SVN_USERNAME is not set}"
: "${SVN_PASSWORD:?SVN_PASSWORD is not set}"

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

unzip -q "$ZIP" -d "$WORK/zip"
PLUGIN="$WORK/zip/$SLUG"
VERSION=$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' "$PLUGIN/$SLUG.php")

svn_auth() {
  svn "$@" --username "$SVN_USERNAME" --password "$SVN_PASSWORD" --non-interactive --no-auth-cache
}

if svn_auth ls "$SVN_REPO_URL/tags/$VERSION" > /dev/null 2>&1; then
  echo "tags/$VERSION already exists on WordPress.org, nothing to do."
  exit 0
fi

echo "Checking out $SVN_REPO_URL/trunk..."
svn_auth checkout -q "$SVN_REPO_URL/trunk" "$WORK/trunk"

rsync -a --delete --exclude=.svn "$PLUGIN/" "$WORK/trunk/"

cd "$WORK/trunk"
svn status | awk '/^\?/ { print substr($0, 9) }' | while IFS= read -r path; do svn add -q "$path"; done
svn status | awk '/^!/ { print substr($0, 9) }' | while IFS= read -r path; do svn rm -q "$path"; done
svn status

echo "Committing trunk..."
svn_auth commit -m "Release $VERSION"

echo "Creating tags/$VERSION..."
svn_auth copy "$SVN_REPO_URL/trunk" "$SVN_REPO_URL/tags/$VERSION" -m "Tag $VERSION"

echo "Version $VERSION published on WordPress.org."
