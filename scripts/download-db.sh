#!/usr/bin/env bash
set -euo pipefail

REPO="tag1consulting/scolta-demo-drupal-pedia"
DUMP_FILE="db/dump.sql.gz"
ASSET_NAME="dump.sql.gz"

if [ -f "$DUMP_FILE" ]; then
    echo "Database dump already exists at $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"
    read -p "Re-download? [y/N] " confirm
    [[ "$confirm" =~ ^[Yy]$ ]] || exit 0
fi

echo "Downloading database dump from GitHub Releases..."
mkdir -p db

DOWNLOAD_URL=$(curl -s "https://api.github.com/repos/$REPO/releases/latest" \
    | grep -o "https://[^\"]*${ASSET_NAME}[^\"]*" \
    | head -1)

if [ -z "$DOWNLOAD_URL" ]; then
    echo "ERROR: Could not find $ASSET_NAME in latest release of $REPO"
    echo "Check: https://github.com/$REPO/releases"
    exit 1
fi

curl -L --progress-bar -o "$DUMP_FILE" "$DOWNLOAD_URL"

echo "Downloaded: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"
echo "Run 'ddev start' to import the database automatically."
