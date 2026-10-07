#!/usr/bin/env bash
# Monthly restore drill. Fetches the newest backup (or uses the file in $1), decrypts, restores the database into a SCRATCH
# database, then verifies (a) the nimikh_certificates row count and (b) the sha256 of one certificate PDF taken from the
# restored uploads against the sha256 stored in its database row. Exits non-zero on any mismatch.
# It never writes to the production database: DRILL_DB_NAME must differ from DB_NAME, and the script refuses otherwise.
#
# Usage: restore-drill.sh [path/to/nimikh-<stamp>.tar[.age]]
# Config (env or BACKUP_ENV_FILE): DB_HOST DB_PORT DB_NAME TABLE_PREFIX RCLONE_REMOTE BACKUP_AGE_IDENTITY_FILE
#   DRILL_DB_NAME (default nimikh_restore_drill)  DRILL_DB_HOST/DRILL_DB_USER/DRILL_DB_PASSWORD (default: DB_*; needs CREATE/DROP)
#   DRILL_MAX_COUNT_DRIFT  tolerated difference between manifest and restored row count (default 0)
set -euo pipefail

BACKUP_ENV_FILE="${BACKUP_ENV_FILE:-/etc/nimikh/backup.env}"
if [ -f "$BACKUP_ENV_FILE" ]; then
  set -a
  # shellcheck source=/dev/null
  . "$BACKUP_ENV_FILE"
  set +a
fi

DB_NAME="${DB_NAME:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
TABLE_PREFIX="${TABLE_PREFIX:-wp_}"
RCLONE_REMOTE="${RCLONE_REMOTE:-}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/nimikh}"
BACKUP_AGE_IDENTITY_FILE="${BACKUP_AGE_IDENTITY_FILE:-}"
DRILL_DB_NAME="${DRILL_DB_NAME:-nimikh_restore_drill}"
DRILL_DB_HOST="${DRILL_DB_HOST:-$DB_HOST}"
DRILL_DB_USER="${DRILL_DB_USER:-${DB_USER:-}}"
DRILL_DB_PASSWORD="${DRILL_DB_PASSWORD:-${DB_PASSWORD:-}}"
DRILL_MAX_COUNT_DRIFT="${DRILL_MAX_COUNT_DRIFT:-0}"
DRILL_PING_URL="${DRILL_PING_URL:-}"
: "${DRILL_DB_USER:?DRILL_DB_USER (or DB_USER) is required}"
: "${DRILL_DB_PASSWORD:?DRILL_DB_PASSWORD (or DB_PASSWORD) is required}"

log() { printf '%s drill: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
ping_url() { [ -n "$DRILL_PING_URL" ] || return 0; curl -fsS -m 10 -o /dev/null "${DRILL_PING_URL}${1}" || log "warning: ping failed"; }
sql() { MYSQL_PWD="$DRILL_DB_PASSWORD" mysql --host="$DRILL_DB_HOST" --port="$DB_PORT" --user="$DRILL_DB_USER" -N -B "$@"; }

# Safety: the scratch DB must be a different database, and must look like a scratch name.
if [ -n "$DB_NAME" ] && [ "$DRILL_DB_NAME" = "$DB_NAME" ]; then
  log "refusing: DRILL_DB_NAME equals production DB_NAME"; exit 2
fi
case "$DRILL_DB_NAME" in
  *drill*|*scratch*|*restore*) ;;
  *) log "refusing: DRILL_DB_NAME must contain 'drill', 'scratch' or 'restore'"; exit 2 ;;
esac
if [ "$DRILL_DB_HOST" = "$DB_HOST" ] && [ -z "${DRILL_ALLOW_SAME_HOST:-}" ]; then
  log "note: restoring on the production DB host (into a separate schema). Set DRILL_DB_HOST to a scratch server for stronger isolation."
fi

work=""
scratch_created=0
cleanup() {
  rc=$?
  if [ "$scratch_created" = 1 ]; then sql -e "DROP DATABASE IF EXISTS \`${DRILL_DB_NAME}\`" || log "warning: could not drop scratch DB"; fi
  [ -n "$work" ] && rm -rf "$work"
  if [ "$rc" -ne 0 ]; then log "DRILL FAILED (exit $rc)"; ping_url "/fail"; fi
}
trap cleanup EXIT

for tool in mysql tar sha256sum gzip; do command -v "$tool" >/dev/null || { log "missing tool: $tool"; exit 127; }; done
umask 077
work="$(mktemp -d)"
ping_url "/start"

# 1. Get the archive.
archive="${1:-}"
if [ -z "$archive" ]; then
  [ -n "$RCLONE_REMOTE" ] && [ "$RCLONE_REMOTE" != "none" ] || { log "no archive argument and no RCLONE_REMOTE"; exit 1; }
  command -v rclone >/dev/null || { log "rclone not installed"; exit 127; }
  latest="$(rclone lsf "$RCLONE_REMOTE" --files-only | grep -E '^nimikh-[0-9]{8}T[0-9]{6}Z\.tar(\.age)?$' | sort | tail -n 1 || true)"
  [ -n "$latest" ] || { log "no backups found at $RCLONE_REMOTE"; exit 1; }
  log "downloading $latest"
  rclone copyto "$RCLONE_REMOTE/$latest" "$work/$latest"
  archive="$work/$latest"
fi
[ -f "$archive" ] || { log "archive not found: $archive"; exit 1; }

# 2. Decrypt and unpack.
case "$archive" in
  *.age)
    [ -n "$BACKUP_AGE_IDENTITY_FILE" ] && [ -f "$BACKUP_AGE_IDENTITY_FILE" ] || { log "encrypted archive needs BACKUP_AGE_IDENTITY_FILE"; exit 1; }
    command -v age >/dev/null || { log "age not installed"; exit 127; }
    age --decrypt --identity "$BACKUP_AGE_IDENTITY_FILE" --output "$work/outer.tar" "$archive" ;;
  *) cp "$archive" "$work/outer.tar" ;;
esac
mkdir "$work/x"
tar --extract --file "$work/outer.tar" --directory "$work/x"
rm -f "$work/outer.tar"
( cd "$work/x" && sha256sum --check --quiet SHA256SUMS ) || { log "archive checksum mismatch"; exit 1; }
log "archive integrity OK"

# 3. Restore into scratch DB.
sql -e "DROP DATABASE IF EXISTS \`${DRILL_DB_NAME}\`; CREATE DATABASE \`${DRILL_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
scratch_created=1
log "restoring into $DRILL_DB_NAME"
gzip -dc "$work/x/db.sql.gz" | sql "$DRILL_DB_NAME"

# 4a. Row count of nimikh_certificates against the manifest.
expected="$(sed -n 's/.*"certificates_row_count": *\(-\{0,1\}[0-9]\{1,\}\).*/\1/p' "$work/x/manifest.json")"
actual="$(sql -e "SELECT COUNT(*) FROM \`${DRILL_DB_NAME}\`.\`${TABLE_PREFIX}nimikh_certificates\`")"
log "nimikh_certificates rows: restored=$actual manifest=$expected"
[[ "$actual" =~ ^[0-9]+$ ]] || { log "could not count restored certificates"; exit 1; }
if [ -z "$expected" ] || [ "$expected" -lt 0 ]; then
  log "manifest has no usable row count (table missing at backup time?); count check skipped"
else
  diff=$(( actual > expected ? actual - expected : expected - actual ))
  [ "$diff" -le "$DRILL_MAX_COUNT_DRIFT" ] || { log "row count mismatch (drift $diff > $DRILL_MAX_COUNT_DRIFT)"; exit 1; }
fi

# 4b. Sample sha256: a random certificate row that has a stored hash; hash the PDF from the restored uploads, compare.
if [ "$actual" -eq 0 ]; then
  log "no certificates yet, sha256 sample check skipped"
else
  row="$(sql -e "SELECT pdf_key, sha256 FROM \`${DRILL_DB_NAME}\`.\`${TABLE_PREFIX}nimikh_certificates\` WHERE pdf_key IS NOT NULL AND pdf_key <> '' AND sha256 IS NOT NULL AND sha256 <> '' ORDER BY RAND() LIMIT 1")"
  if [ -z "$row" ]; then
    log "certificates exist but none has pdf_key+sha256 (stored off-site?); sample check skipped"
  else
    pdf_key="$(printf '%s' "$row" | cut -f1)"
    want="$(printf '%s' "$row" | cut -f2)"
    case "$pdf_key" in */*|*..*) log "unexpected pdf_key: $pdf_key"; exit 1 ;; esac
    mkdir "$work/up"
    tar --extract --gzip --file "$work/x/uploads.tar.gz" --directory "$work/up"
    file="$(find "$work/up" -type f -path "*/nimikh-certificates/$pdf_key" | head -n 1)"
    [ -n "$file" ] || { log "certificate file $pdf_key missing from uploads backup"; exit 1; }
    got="$(sha256sum "$file" | cut -d' ' -f1)"
    if [ "$got" != "$want" ]; then log "sha256 MISMATCH for $pdf_key: db=$want file=$got"; exit 1; fi
    log "sha256 OK for $pdf_key"
  fi
fi

log "DRILL PASSED"
ping_url ""
