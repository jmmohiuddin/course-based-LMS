#!/usr/bin/env bash
# Poll the Nimikh LMS health endpoint, one /verify URL and one lesson page. Exit 0 = all good, 1 = something failed.
# Run it from a host OUTSIDE the origin (a second VPS, or the GitHub Actions workflow below), every minute.
# Needs: bash, curl, jq.
#
# Env (all have defaults except BASE_URL):
#   BASE_URL              e.g. https://lms.example.com                       (required)
#   VERIFY_PATH           default /verify/                                    (use a real code, /verify/ABCDE12345)
#   LESSON_PATH           default /                                           (set to a public lesson path)
#   LESSON_EXPECT_STATUS  default 200
#   MAX_CRON_LAG          default 120   seconds
#   MAX_FAILED_JOBS       default 4
#   TIMEOUT               default 10    seconds per request
#   PING_URL              optional heartbeat (Better Stack / healthchecks.io style); "/fail" is appended on failure
#   ALERT_WEBHOOK         optional URL; receives a JSON {"text": "..."} POST on failure (Slack/Mattermost compatible)
set -euo pipefail

: "${BASE_URL:?BASE_URL is required}"
BASE_URL="${BASE_URL%/}"
VERIFY_PATH="${VERIFY_PATH:-/verify/}"
LESSON_PATH="${LESSON_PATH:-/}"
LESSON_EXPECT_STATUS="${LESSON_EXPECT_STATUS:-200}"
MAX_CRON_LAG="${MAX_CRON_LAG:-120}"
MAX_FAILED_JOBS="${MAX_FAILED_JOBS:-4}"
TIMEOUT="${TIMEOUT:-10}"
PING_URL="${PING_URL:-}"
ALERT_WEBHOOK="${ALERT_WEBHOOK:-}"

failures=()
fail() { failures+=("$1"); }

for tool in curl jq; do command -v "$tool" >/dev/null || { echo "missing tool: $tool" >&2; exit 127; }; done

status_of() { curl -sS -o /dev/null -w '%{http_code}' --max-time "$TIMEOUT" "$1" 2>/dev/null || echo 000; }

# 1. Health endpoint
health_body="$(mktemp)"
trap 'rm -f "$health_body"' EXIT
code="$(curl -sS -o "$health_body" -w '%{http_code}' --max-time "$TIMEOUT" -H 'Cache-Control: no-cache' "$BASE_URL/wp-json/nimikh/v1/health" 2>/dev/null || echo 000)"
if [ "$code" != "200" ]; then
  fail "health: HTTP $code"
elif ! jq -e . "$health_body" >/dev/null 2>&1; then
  fail "health: response is not JSON"
else
  st="$(jq -r '.status // "missing"' "$health_body")"
  db="$(jq -r '.db // "missing"' "$health_body")"
  lag="$(jq -r '.cron_lag_seconds // "missing"' "$health_body")"
  fj="$(jq -r '.failed_jobs // "missing"' "$health_body")"
  [ "$st" = "ok" ] || fail "health: status=$st"
  [ "$db" = "true" ] || fail "health: db=$db"
  if [[ "$lag" =~ ^[0-9]+$ ]]; then [ "$lag" -le "$MAX_CRON_LAG" ] || fail "health: cron_lag_seconds=$lag (max $MAX_CRON_LAG)"; else fail "health: cron_lag_seconds=$lag"; fi
  if [[ "$fj" =~ ^[0-9]+$ ]]; then [ "$fj" -le "$MAX_FAILED_JOBS" ] || fail "health: failed_jobs=$fj (max $MAX_FAILED_JOBS)"; else fail "health: failed_jobs=$fj"; fi
fi

# 2. Verify page, 3. lesson page
code="$(status_of "$BASE_URL$VERIFY_PATH")"
[ "$code" = "200" ] || fail "verify page $VERIFY_PATH: HTTP $code"
code="$(status_of "$BASE_URL$LESSON_PATH")"
[ "$code" = "$LESSON_EXPECT_STATUS" ] || fail "lesson page $LESSON_PATH: HTTP $code (expected $LESSON_EXPECT_STATUS)"

if [ "${#failures[@]}" -eq 0 ]; then
  echo "$(date -u +%FT%TZ) OK $BASE_URL"
  [ -z "$PING_URL" ] || curl -fsS -m 10 -o /dev/null "$PING_URL" || true
  exit 0
fi

msg="Nimikh LMS check FAILED for $BASE_URL: $(printf '%s; ' "${failures[@]}")"
echo "$(date -u +%FT%TZ) $msg" >&2
[ -z "$PING_URL" ] || curl -fsS -m 10 -o /dev/null "$PING_URL/fail" || true
if [ -n "$ALERT_WEBHOOK" ]; then
  curl -fsS -m 10 -o /dev/null -H 'Content-Type: application/json' -d "$(jq -n --arg t "$msg" '{text:$t}')" "$ALERT_WEBHOOK" || true
fi
exit 1
