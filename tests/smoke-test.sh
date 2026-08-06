#!/usr/bin/env bash
#
# Docker smoke test.
#
# Two notes on why this script looks the way it does.
#
# First, the status assertion must be able to fail. An earlier version treated
# any response other than the curl failure code 000 as liveness, so a site
# returning HTTP 500 on every request still printed "image build and start OK"
# and the script exited 0. A test that cannot fail on a broken site is worse
# than no test, because every later run inherits the false confidence.
#
# Second, this image has no database of its own: it ships a mysql client and
# reads DB_HOST and friends from the environment, exactly as the production
# deployment does. Running it bare therefore always yields HTTP 500, and a 2xx
# assertion would be unsatisfiable. So the test provisions the same thing the
# Helm chart provisions: a MariaDB instance loaded from the dump. The dump is
# gitignored here and is fetched into the image at build time, so it is read
# back out of the built image rather than from the working tree.
set -euo pipefail

PORT=8080
IMAGE="scolta-smoke-$$"
NETWORK="scolta-smoke-net-$$"
DB_CONTAINER="scolta-smoke-db-$$"

DB_NAME="drupal"
DB_USERNAME="drupal"
DB_PASSWORD="drupal"

BASE_URL="http://localhost:${PORT}"
SEARCH_URL="${BASE_URL}/search"

echo "==> Building Docker image..."
docker build -t "$IMAGE" .

cleanup() {
  docker stop "$IMAGE" "$DB_CONTAINER" 2>/dev/null || true
  docker rm "$IMAGE" "$DB_CONTAINER" 2>/dev/null || true
  docker rmi "$IMAGE" 2>/dev/null || true
  docker network rm "$NETWORK" 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Creating network and starting the database..."
docker network create "$NETWORK" >/dev/null
# The image's own healthcheck is the reliable readiness signal. MariaDB's
# entrypoint runs a temporary server while it initialises, which answers pings
# before the real server exists and before the root password is set, so probing
# by hand races that startup and intermittently gets "Access denied".
docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MARIADB_ROOT_PASSWORD=root \
  -e MARIADB_DATABASE="$DB_NAME" \
  -e MARIADB_USER="$DB_USERNAME" \
  -e MARIADB_PASSWORD="$DB_PASSWORD" \
  --health-cmd="healthcheck.sh --connect --innodb_initialized" \
  --health-interval=5s \
  --health-timeout=5s \
  --health-retries=24 \
  mariadb:11 >/dev/null

echo "==> Waiting for the database to become healthy (up to 120s)..."
DB_READY=0
for _ in $(seq 1 60); do
  if [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER" 2>/dev/null)" = "healthy" ]; then
    DB_READY=1
    break
  fi
  sleep 2
done
if [ "$DB_READY" -ne 1 ]; then
  echo "FAIL: the database never became healthy"
  docker logs "$DB_CONTAINER" 2>&1 | tail -30
  exit 1
fi

echo "==> Loading the dump out of the image into the database (this one is large)..."
docker run --rm --entrypoint sh "$IMAGE" -c 'cat /var/www/html/db/dump.sql.gz' \
  | gunzip -c \
  | docker exec -i "$DB_CONTAINER" mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_NAME"

echo "==> Starting container on port $PORT..."
docker run -d --name "$IMAGE" --network "$NETWORK" -p "${PORT}:8080" \
  -e DB_HOST="$DB_CONTAINER" \
  -e DB_PORT=3306 \
  -e DB_NAME="$DB_NAME" \
  -e DB_USERNAME="$DB_USERNAME" \
  -e DB_PASSWORD="$DB_PASSWORD" \
  -e DRUPAL_HASH_SALT="smoke-test-not-a-secret" \
  "$IMAGE" >/dev/null

# Poll a URL until it returns a 2xx, following redirects. Prints the last status
# code seen. Returns non-zero if no 2xx arrived before the timeout, so the caller
# can tell "never came up" (000) from "came up broken" (5xx).
await_success() {
  local url="$1" tries="${2:-45}" code="000"
  for _ in $(seq 1 "$tries"); do
    code=$(curl -sS -L --max-redirs 5 -o /dev/null -w "%{http_code}" "$url" 2>/dev/null || echo "000")
    case "$code" in
      2*) echo "$code"; return 0 ;;
    esac
    sleep 2
  done
  echo "$code"
  return 1
}

# Explain a terminal status, then dump logs and fail.
fail_status() {
  local what="$1" url="$2" code="$3"
  echo "FAIL: $what did not return a success status (last seen: HTTP $code) at $url"
  case "$code" in
    000) echo "      No HTTP response at all: the container never served a request." ;;
    5*)  echo "      A 5xx means Apache answered but the application cannot serve the site." \
              "This is a broken demo, not a slow one." ;;
    4*)  echo "      A 4xx means the route is not being served as expected." ;;
  esac
  docker logs "$IMAGE" 2>&1 | tail -40
  exit 1
}

echo "==> Waiting for the site root to return a success status (up to 90s)..."
ROOT_CODE=$(await_success "${BASE_URL}/") || fail_status "site root" "${BASE_URL}/" "$ROOT_CODE"
echo "PASS: site root returned HTTP $ROOT_CODE"

echo "==> Checking the search route..."
SEARCH_CODE=$(await_success "$SEARCH_URL" 15) || fail_status "search route" "$SEARCH_URL" "$SEARCH_CODE"
echo "PASS: search route returned HTTP $SEARCH_CODE"

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

echo "==> Verifying search index prerequisites..."
# The pagefind index is gitignored and rebuilt at runtime (ddev start / deploy).
# Verify the pagefind binary and Scolta module are present in the image.
HAS_PAGEFIND=$(docker exec "$IMAGE" sh -c 'pagefind --version 2>/dev/null && echo ok || echo missing')
if echo "$HAS_PAGEFIND" | grep -q "missing"; then
  echo "FAIL: pagefind binary not found in container"
  exit 1
fi
echo "PASS: pagefind binary available ($(echo "$HAS_PAGEFIND" | head -1))"

HAS_MODULE=$(docker exec "$IMAGE" sh -c 'test -f /var/www/html/web/modules/contrib/scolta/scolta.info.yml && echo ok || echo missing')
if [ "$HAS_MODULE" != "ok" ]; then
  echo "FAIL: scolta-drupal module not found in container"
  exit 1
fi
echo "PASS: scolta-drupal module installed"

echo "==> Verifying search corpus excludes the About page..."
# The index must contain only featured_article nodes (scolta:build runs with
# --bundle=featured_article). The pagefind index is gitignored and rebuilt at
# runtime, so this guard runs against the local build output when present.
FRAGMENT_DIR="web/sites/default/files/scolta-pagefind/pagefind/fragment"
if [ -d "$FRAGMENT_DIR" ] && ls "$FRAGMENT_DIR" >/dev/null 2>&1; then
  if zcat -f "$FRAGMENT_DIR"/*.pf_fragment 2>/dev/null | grep -q "About The Athenaeum"; then
    echo "FAIL: exported corpus contains the About page (About The Athenaeum)"
    exit 1
  fi
  echo "PASS: exported corpus does not contain the About page"
else
  echo "SKIP: no local pagefind index built ($FRAGMENT_DIR missing)"
fi

echo "==> All checks passed"
