#!/bin/sh
# export-assets — atomically copy public/build to ASSET_EXPORT_DIR.
set -eu

SRC=/var/www/html/public/build
DEST="${ASSET_EXPORT_DIR:-/var/www/html/public/build-export}"

if [ ! -d "$SRC" ]; then
    echo "[export-assets] $SRC missing; nothing to export" >&2
    exit 0
fi

mkdir -p "$(dirname "$DEST")"
rm -rf "$DEST.new"
cp -a "$SRC" "$DEST.new"
rm -rf "$DEST"
mv "$DEST.new" "$DEST"
echo "[export-assets] exported frontend build to $DEST" >&2
