#!/usr/bin/env bash
set -euo pipefail

REPO="tag1consulting/scolta-demo-drupal-pedia"
ASSET_NAME="article-images.tar.gz"
EXTRACT_TO="web/sites/default/files"
IMAGES_DIR="$EXTRACT_TO/article-images"

if [ -d "$IMAGES_DIR" ] && [ -n "$(ls -A "$IMAGES_DIR" 2>/dev/null)" ]; then
    echo "Article images already exist at $IMAGES_DIR ($(du -sh "$IMAGES_DIR" | cut -f1))"
    read -p "Re-download? [y/N] " confirm
    [[ "$confirm" =~ ^[Yy]$ ]] || exit 0
fi

echo "Downloading article images from GitHub Releases..."
mkdir -p "$EXTRACT_TO"

DOWNLOAD_URL=$(curl -s "https://api.github.com/repos/$REPO/releases/latest" \
    | grep -o "https://[^\"]*${ASSET_NAME}[^\"]*" \
    | head -1)

if [ -z "$DOWNLOAD_URL" ]; then
    echo "ERROR: Could not find $ASSET_NAME in latest release of $REPO"
    echo "Check: https://github.com/$REPO/releases"
    exit 1
fi

TARBALL="/tmp/${ASSET_NAME}"
curl -L --progress-bar -o "$TARBALL" "$DOWNLOAD_URL"
tar -xzf "$TARBALL" -C "$EXTRACT_TO"
rm "$TARBALL"

IMAGE_COUNT=$(ls -1 "$IMAGES_DIR" | wc -l | tr -d ' ')
echo "Extracted to $IMAGES_DIR ($IMAGE_COUNT files, $(du -sh "$IMAGES_DIR" | cut -f1))"

# Sanity check: the release asset should contain all article lead images.
MIN_IMAGES=1900
if [ "$IMAGE_COUNT" -lt "$MIN_IMAGES" ]; then
    echo "ERROR: Expected at least $MIN_IMAGES article images, found only $IMAGE_COUNT."
    echo "The release asset may be incomplete. Check: https://github.com/$REPO/releases"
    exit 1
fi

echo "Run 'ddev start' to import the database and rebuild the search index."
