#!/usr/bin/env bash
# Builds the frontend and packs the app for installation into a Nextcloud
# extra-apps directory. Output: build/bettersharing-<version>.tar.gz
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
version="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$root/appinfo/info.xml")"
out="$root/build"
stage="$out/stage/bettersharing"

(cd "$root" && npm run build)
rm -rf "$out/stage"
mkdir -p "$stage"
cp -R "$root/appinfo" "$root/lib" "$root/js" "$root/css" "$stage/"
find "$stage" -name '*.map' -delete
tar -C "$out/stage" -czf "$out/bettersharing-$version.tar.gz" bettersharing
rm -rf "$out/stage"
echo "$out/bettersharing-$version.tar.gz"
