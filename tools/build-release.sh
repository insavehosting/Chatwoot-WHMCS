#!/usr/bin/env bash
# Builds the release zip: modules/addons/chatwoot/ with the three module files
# and the LICENSE, ready to unzip in the WHMCS root.
#
#   tools/build-release.sh [output directory]     (default: dist/)
#
# Writes chatwoot-by-insave.zip. The name carries no version on purpose: the
# "latest release" download link stays the same for every version:
# https://github.com/insavehosting/Chatwoot-WHMCS/releases/latest/download/chatwoot-by-insave.zip
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="$(mkdir -p "${1:-$root/dist}" && cd "${1:-$root/dist}" && pwd)"
version="$(sed -n 's/^ *"version" *=> *"\([^"]*\)".*/\1/p' "$root/modules/addons/chatwoot/chatwoot.php")"
[ -n "$version" ] || { echo "version not found in chatwoot.php" >&2; exit 1; }

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/modules/addons/chatwoot"
cp "$root"/modules/addons/chatwoot/{chatwoot,hooks,app}.php "$root/LICENSE" "$stage/modules/addons/chatwoot/"
find "$stage" -exec touch -t 202001010000 {} +   # same bytes on every build

zip="$out/chatwoot-by-insave.zip"
rm -f "$zip"
(cd "$stage" && zip -q -X -r "$zip" modules)

echo "$zip (version $version)"
(cd "$root" && md5sum modules/addons/chatwoot/{chatwoot,hooks,app}.php)
