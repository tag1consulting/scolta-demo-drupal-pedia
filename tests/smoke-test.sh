#!/usr/bin/env bash
set -euo pipefail

PORT=8080
IMAGE="scolta-smoke-$$"

echo "==> Building Docker image..."
docker build -t "$IMAGE" .

cleanup() {
  docker stop "$IMAGE" 2>/dev/null || true
  docker rm "$IMAGE" 2>/dev/null || true
  docker rmi "$IMAGE" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Starting container on port $PORT..."
docker run -d --name "$IMAGE" -p "${PORT}:8080" "$IMAGE"

echo "==> Waiting for HTTP server (up to 60s)..."
for i in $(seq 1 30); do
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${PORT}/" 2>/dev/null || true)
  if [ -n "$HTTP_CODE" ] && [ "$HTTP_CODE" != "000" ]; then
    echo "==> Container responded: HTTP $HTTP_CODE — image build and start OK"
    break
  fi
  sleep 2
done

if [ "${HTTP_CODE:-000}" = "000" ]; then
  echo "==> FAIL: no HTTP response after 60s"
  docker logs "$IMAGE" || true
  exit 1
fi

echo "==> Checking article images in container..."
IMAGE_COUNT=$(docker exec "$IMAGE" sh -c 'ls /var/www/html/web/sites/default/files/article-images/ | wc -l' | tr -d ' ')
MIN_IMAGES=1900
if [ "${IMAGE_COUNT:-0}" -lt "$MIN_IMAGES" ]; then
  echo "==> FAIL: Expected at least $MIN_IMAGES article images, found only $IMAGE_COUNT"
  exit 1
fi
echo "==> Article images OK: $IMAGE_COUNT files"

echo "==> Checking a sample of article images are served..."
SAMPLE_IMAGES=(
  "george-harrison.jpg"
  "space-shuttle.jpg"
  "vladimir-lenin.jpg"
  "american-goldfinch.jpg"
  "general-relativity.jpg"
)
for img in "${SAMPLE_IMAGES[@]}"; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${PORT}/sites/default/files/article-images/$img")
  if [ "$STATUS" != "200" ]; then
    echo "==> FAIL: Image $img returned HTTP $STATUS (expected 200)"
    exit 1
  fi
done
echo "==> Sample images OK"

echo "==> All checks passed"
