#!/usr/bin/env bash
# Post-deploy gate: poll /wp-json/nimikh/v1/health until it returns status "ok" and db true, or fail after the deadline.
# "degraded" is a failure here: a fresh deploy should come up clean.
# Env: BASE_URL (required), HEALTH_TIMEOUT_SECONDS (default 180), HEALTH_INTERVAL_SECONDS (default 10), MAX_CRON_LAG (default 300; wp-cron
# may be stale for a few minutes right after a swap, so this is looser than the steady-state monitor).
set -euo pipefail
: "${BASE_URL:?BASE_URL is required}"
BASE_URL="${BASE_URL%/}"
deadline=$(( $(date +%s) + ${HEALTH_TIMEOUT_SECONDS:-180} ))
interval="${HEALTH_INTERVAL_SECONDS:-10}"
max_lag="${MAX_CRON_LAG:-300}"
last="no response"
while [ "$(date +%s)" -lt "$deadline" ]; do
  body="$(curl -sS --max-time 10 -H 'Cache-Control: no-cache' -w '\n%{http_code}' "$BASE_URL/wp-json/nimikh/v1/health" 2>&1 || true)"
  code="$(printf '%s' "$body" | tail -n 1)"
  json="$(printf '%s' "$body" | sed '$d')"
  if [ "$code" = "200" ] && printf '%s' "$json" | jq -e --argjson lag "$max_lag" \
      '.status == "ok" and .db == true and (.cron_lag_seconds | type == "number" and . <= $lag)' >/dev/null 2>&1; then
    echo "healthy: $json"
    # Also make sure the pretty-permalink rewrite rules survived the swap.
    vcode="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$BASE_URL/verify/" || echo 000)"
    [ "$vcode" = "200" ] || { echo "health ok but /verify/ returned $vcode (rewrite rules not flushed?)" >&2; exit 1; }
    exit 0
  fi
  last="HTTP $code $json"
  echo "not healthy yet: $last"
  sleep "$interval"
done
echo "health check failed after deadline; last: $last" >&2
exit 1
