#!/usr/bin/env bash
# Build the distributable zip and publish it as a GitHub Release.
#
# The zip is what every installed site downloads through the bundled
# plugin-update-checker, so its top-level folder MUST be the plugin slug.
# `git archive` guarantees only committed files ship and honours the
# export-ignore rules in .gitattributes.
#
#   bin/build-release.sh          build build/<slug>-<version>.zip only
#   bin/build-release.sh --publish  also tag v<version> and create the Release
set -euo pipefail

cd "$(dirname "$0")/.."
MAIN=$(ls ./*.php | grep -v uninstall.php | head -1)
SLUG=$(sed -n 's/^ \* Text Domain:[[:space:]]*//p' "$MAIN" | head -1)
VERSION=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$MAIN" | head -1)
CONST=$(sed -n "s/^define( '[A-Z]*_VERSION', '\([^']*\)' );/\1/p" "$MAIN" | head -1)
STABLE=$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | head -1)

[ -n "$VERSION" ] || { echo "no Version header in $MAIN" >&2; exit 1; }
[ "$VERSION" = "$CONST" ] || { echo "Version header $VERSION != constant $CONST" >&2; exit 1; }
[ "$VERSION" = "$STABLE" ] || { echo "Version header $VERSION != readme Stable tag $STABLE" >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "working tree not clean - commit first" >&2; exit 1; }

mkdir -p build
ZIP="build/$SLUG-$VERSION.zip"
rm -f "$ZIP"
git archive --format=zip --prefix="$SLUG/" -o "$ZIP" HEAD
echo "built $ZIP ($(du -h "$ZIP" | cut -f1))"
unzip -l "$ZIP" | awk 'NR>3 {print $4}' | grep -q "^$SLUG/lib/plugin-update-checker/plugin-update-checker.php$" \
  || { echo "update checker missing from zip" >&2; exit 1; }

if [ "${1:-}" = "--publish" ]; then
  TAG="v$VERSION"
  git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || git tag -a "$TAG" -m "$TAG"
  git push origin HEAD "$TAG"
  NOTES=$(awk -v v="= $VERSION =" '$0==v {f=1; next} /^= [0-9]/ {if(f) exit} f' readme.txt)
  gh release create "$TAG" "$ZIP" --title "$TAG" --notes "${NOTES:-$TAG}"
  echo "published $TAG"
fi
