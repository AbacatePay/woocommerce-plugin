#!/usr/bin/env bash
# Prepares a release: bumps the version, writes the changelog and opens the release PR.
#
#   scripts/release.sh patch|minor|major   next version after the current one
#   scripts/release.sh 1.2.3               exact version
#
# When the PR is merged, .github/workflows/changelog.yml tags the merge commit, builds the zip and
# publishes the GitHub release.
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="abacatepay-for-woocommerce"
MAIN_BRANCH="main"
BUMP="${1:-}"

if [ -z "$BUMP" ]; then
  echo "Usage: scripts/release.sh patch|minor|major|<x.y.z>" >&2
  exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Commit or stash your changes first." >&2
  exit 1
fi

git fetch origin "$MAIN_BRANCH" --tags

CURRENT=$(git show "origin/$MAIN_BRANCH:$SLUG.php" | sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p')
IFS=. read -r MAJOR MINOR PATCH <<< "$CURRENT"

case "$BUMP" in
  major) VERSION="$((MAJOR + 1)).0.0" ;;
  minor) VERSION="$MAJOR.$((MINOR + 1)).0" ;;
  patch) VERSION="$MAJOR.$MINOR.$((PATCH + 1))" ;;
  *)
    if ! [[ "$BUMP" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
      echo "Invalid version: $BUMP" >&2
      exit 1
    fi
    VERSION="$BUMP"
    ;;
esac

TAG="v$VERSION"
BRANCH="release/$TAG"

if git rev-parse -q --verify "refs/tags/$TAG" > /dev/null; then
  echo "Tag $TAG already exists." >&2
  exit 1
fi

LAST_TAG=$(git describe --tags --abbrev=0 --match 'v*' "origin/$MAIN_BRANCH" 2> /dev/null || true)
RANGE="${LAST_TAG:+$LAST_TAG..}origin/$MAIN_BRANCH"
DATE=$(date +%Y-%m-%d)

echo "Releasing $TAG (current: $CURRENT, changes since: ${LAST_TAG:-the first commit})"

git checkout --no-track -b "$BRANCH" "origin/$MAIN_BRANCH"

# Conventional Commits subjects, grouped like generate-changelog does.
SUBJECTS=$(git log --no-merges --pretty=format:%s "$RANGE" | grep -vE "^build\((changelog|release)\):" || true)

section() {
  local title="$1" pattern="$2" lines
  lines=$(printf '%s\n' "$SUBJECTS" | grep -E "$pattern" || true)

  if [ -n "$lines" ]; then
    printf '##### %s\n\n' "$title"
    printf '%s\n' "$lines" | sed -E 's/^[a-z]+\(([^)]+)\)!?: /* **\1:** /; s/^[a-z]+!?: /* /'
    printf '\n'
  fi
}

ENTRY=$(
  printf '#### %s (%s)\n\n' "$VERSION" "$DATE"
  section 'New Features' '^feat(\(|!|:)'
  section 'Bug Fixes' '^fix(\(|!|:)'
  section 'Performance Improvements' '^perf(\(|!|:)'
  section 'Refactors' '^refactor(\(|!|:)'
  section 'Other Changes' '^(chore|build|ci|docs|style|test)(\(|!|:)'
)

{
  printf '%s\n\n' "$ENTRY"
  [ -f CHANGELOG.md ] && cat CHANGELOG.md
} > CHANGELOG.md.new
mv CHANGELOG.md.new CHANGELOG.md

# readme.txt gets the user-facing part (features and fixes), unless the version already has a hand-written entry.
if ! grep -qE "^= $VERSION( |=)" readme.txt; then
  ITEMS=$(printf '%s\n' "$SUBJECTS" | grep -E '^(feat|fix)(\(|!|:)' | sed -E 's/^[a-z]+(\([^)]+\))?!?: /* /' || true)
  ITEMS="${ITEMS:-* Melhorias internas.}" \
  HEADING="= $VERSION - $DATE =" \
    perl -0pi -e 's/(== Changelog ==\n\n)/$1$ENV{HEADING}\n$ENV{ITEMS}\n\n/' readme.txt
fi

CURRENT="$CURRENT" VERSION="$VERSION" perl -pi -e '
  s/^( \* Version: *)\Q$ENV{CURRENT}\E$/${1}$ENV{VERSION}/;
  s/(define\( .ABACATEPAY_WC_VERSION., .)\Q$ENV{CURRENT}\E(.)/${1}$ENV{VERSION}$2/;
' "$SLUG.php"
CURRENT="$CURRENT" VERSION="$VERSION" perl -pi -e 's/^(Stable tag: *)\Q$ENV{CURRENT}\E$/${1}$ENV{VERSION}/' readme.txt

git add CHANGELOG.md readme.txt "$SLUG.php"
git commit -m "build(changelog): $TAG"
git push -u origin "$BRANCH"

gh pr create \
  --title "build(release): $TAG" \
  --body "" \
  --base "$MAIN_BRANCH" \
  --assignee @me
