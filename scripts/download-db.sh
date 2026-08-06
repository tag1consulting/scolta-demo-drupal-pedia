#!/usr/bin/env bash
set -euo pipefail

REPO="tag1consulting/scolta-demo-drupal-pedia"
DUMP_FILE="db/dump.sql.gz"
ASSET_NAME="dump.sql.gz"

if [ -f "$DUMP_FILE" ]; then
    echo "Database dump already exists at $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"
    # Only prompt when there is a terminal to prompt. Inside a Docker build
    # there is no stdin, so `read` fails immediately and `set -euo pipefail`
    # kills the build. Keeping the existing dump is the right default there:
    # the file is already present, which is exactly what this script is for.
    if [ "${FORCE_DOWNLOAD:-0}" = "1" ]; then
        echo "FORCE_DOWNLOAD=1: re-downloading."
    elif [ -t 0 ]; then
        read -r -p "Re-download? [y/N] " confirm
        [[ "$confirm" =~ ^[Yy]$ ]] || exit 0
    else
        echo "Not an interactive terminal: keeping the existing dump."
        echo "Set FORCE_DOWNLOAD=1 to re-download without a prompt."
        exit 0
    fi
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
