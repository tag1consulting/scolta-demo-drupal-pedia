#!/usr/bin/env bash
#
# verify-browse-and-map.sh — local regression check for the Era/Region browse
# pages and the interactive article Map.
#
# Targets the RUNNING DDEV site at https://the-athenaeum.ddev.site (self-signed
# cert, hence `curl -k`). Run it manually/locally:
#
#     bash tests/verify-browse-and-map.sh
#
# It is INTENTIONALLY NOT wired into tests/smoke-test.sh (CI). CI builds the
# Docker image and ships a pre-built database downloaded from the
# scolta-demo-drupal-pedia GitHub release. That database does NOT yet contain
# the era_landing_page/region_landing_page content types, the landing-page
# nodes, the article coordinate data, or the enabled drupal/leaflet module —
# those only exist once the DB dump is regenerated (a release-adjacent step
# Jeremy performs). Adding these assertions to the CI smoke test would turn it
# red. Keep them here, run locally against the live ddev site.

set -uo pipefail

BASE="https://the-athenaeum.ddev.site"
CURL="curl -ks"
fails=0

pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; fails=$((fails + 1)); }

# Fetch a path; echo body to stdout.
fetch() { $CURL "$BASE/$1"; }
http_code() { $CURL -o /dev/null -w '%{http_code}' "$BASE/$1"; }

echo "==> Verifying against $BASE"

articles_html="$(fetch articles)"
articles_md5="$(printf '%s' "$articles_html" | md5)"

# ---------------------------------------------------------------------------
# 1. /eras and /regions are landing-page index GRIDS, distinct from /articles.
# ---------------------------------------------------------------------------
for browse in eras regions; do
  html="$(fetch "$browse")"
  code="$(http_code "$browse")"
  md5="$(printf '%s' "$html" | md5)"

  if [ "$code" != "200" ]; then
    fail "/$browse returned HTTP $code (expected 200)"
  elif [ "$md5" = "$articles_md5" ]; then
    fail "/$browse is byte-identical to /articles (the original bug)"
  elif ! printf '%s' "$html" | grep -q 'topic-grid'; then
    fail "/$browse is missing the grid markup (topic-grid)"
  elif ! printf '%s' "$html" | grep -q 'topic-tile'; then
    fail "/$browse is missing landing-page tiles (topic-tile)"
  else
    pass "/$browse is a distinct landing-page grid with tiles"
  fi
done

# ---------------------------------------------------------------------------
# 2. At least one era and one region landing page returns 200 and embeds a
#    filtered article list (article-grid).
# ---------------------------------------------------------------------------
verify_landing() {
  local browse="$1" eyebrow="$2"
  local html href landing code
  html="$(fetch "$browse")"
  # First tile link (rendered as <a href="/node/NID" class="topic-tile">).
  href="$(printf '%s' "$html" | grep -oE '<a href="[^"]+"[^>]*class="topic-tile"' | head -1 | grep -oE 'href="[^"]+"' | sed -E 's/href="([^"]+)"/\1/')"
  if [ -z "$href" ]; then
    fail "could not find a landing-page tile link on /$browse"
    return
  fi
  href="${href#/}"
  code="$(http_code "$href")"
  landing="$(fetch "$href")"
  if [ "$code" != "200" ]; then
    fail "landing page /$href returned HTTP $code"
  elif ! printf '%s' "$landing" | grep -q "article-grid"; then
    fail "landing page /$href does not embed a filtered article list (article-grid)"
  elif ! printf '%s' "$landing" | grep -q ">$eyebrow<"; then
    fail "landing page /$href is missing the '$eyebrow' eyebrow"
  else
    pass "$eyebrow landing page /$href embeds a filtered article list"
  fi
}
verify_landing eras Era
verify_landing regions Region

# ---------------------------------------------------------------------------
# 3. /map returns 200 and contains the Leaflet map container markup.
# ---------------------------------------------------------------------------
map_html="$(fetch map)"
map_code="$(http_code map)"
# The /map drupalSettings JSON is one ~1.6MB line, which overruns some grep
# implementations' line buffer — use bash substring matching instead.
if [ "$map_code" != "200" ]; then
  fail "/map returned HTTP $map_code (expected 200)"
elif [[ "$map_html" != *"leaflet-map"* ]]; then
  fail "/map is missing the Leaflet map container markup"
elif [[ "$map_html" != *'"leaflet":'* ]]; then
  fail "/map is missing the Leaflet drupalSettings (no map data)"
else
  pass "/map renders a Leaflet map container with marker data"
fi

# ---------------------------------------------------------------------------
echo
if [ "$fails" -eq 0 ]; then
  echo "All browse-and-map checks passed."
  exit 0
else
  echo "$fails check(s) FAILED."
  exit 1
fi
