#!/usr/bin/env bash
# Prints the CHANGELOG.md entry of a version, without its "#### x.y.z (date)" heading.
#
#   scripts/release-notes.sh 1.2.3
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:?Usage: scripts/release-notes.sh <x.y.z>}"

[ -f CHANGELOG.md ] || exit 0

awk -v heading="#### $VERSION " '
  index($0, heading) == 1 { found = 1; next }
  found && /^#### / { exit }
  found { print }
' CHANGELOG.md | sed -e '/./,$!d'
