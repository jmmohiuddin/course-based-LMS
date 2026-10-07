#!/usr/bin/env bash
# Nightly backup: mysqldump + uploads (including uploads/nimikh-certificates) -> one tarball, optionally age-encrypted,
# shipped off-site with rclone, remote and local retention applied. Configuration is entirely by environment variables
# (optionally loaded from BACKUP_ENV_FILE, default /etc/nimikh/backup.env). See ops/backup/backup.env.example.
#
# Required: DB_NAME DB_USER DB_PASSWORD RCLONE_REMOTE (set RCLONE_REMOTE=none for local-only, e.g. first test)
# Output name: nimikh-<UTC timestamp>.tar[.age]   plus a sidecar manifest (manifest.json inside the archive).
set -euo pipefail

BACKUP_ENV_FILE="${BACKUP_ENV_FILE:-/etc/nimikh/backup.env}"
if [ -f "$BACKUP_ENV_FILE" ]; then
  set -a
  # shellcheck source=/dev/null
  . "$BACKUP_ENV_FILE"
  set +a
fi

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
TABLE_PREFIX="${TABLE_PREFIX:-wp_}"
UPLOADS_DIR="${UPLOADS_DIR:-/var/www/site/wp-content/uploads}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/nimikh}"
LOCAL_RETENTION_DAYS="${LOCAL_RETENTION_DAYS:-3}"
REMOTE_RETENTION_DAYS="${REMOTE_RETENTION_DAYS:-30}"
RCLONE_REMOTE="${RCLONE_REMOTE:-}"
BACKUP_AGE_RECIPIENT="${BACKUP_AGE_RECIPIENT:-}"
BACKUP_PING_URL="${BACKUP_PING_URL:-}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${RCLONE_REMOTE:?RCLONE_REMOTE is required (use RCLONE_REMOTE=none for local-only)}"

log() { printf '%s backup: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
ping_url() { # $1 = suffix ("" or "/fail"); never lets a monitoring outage fail the backup
  [ -n "$BACKUP_PING_URL" ] || return 0
  curl -fsS -m 10 -o /dev/null "${BACKUP_PING_URL}${1}" || log "warning: ping failed"
}

work=""
cleanup() {
  rc=$?
  [ -n "$work" ] && rm -rf "$work"
  if [ "$rc" -ne 0 ]; then
    log "FAILED (exit $rc)"
    ping_url "/fail"
  fi
}
trap cleanup EXIT

for tool in mysqldump tar sha256sum gzip; do
  command -v "$tool" >/dev/null || { log "missing required tool: $tool"; exit 127; }
done
[ -d "$UPLOADS_DIR" ] || { log "UPLOADS_DIR not found: $UPLOADS_DIR"; exit 1; }
if [ "$RCLONE_REMOTE" != "none" ]; then command -v rclone >/dev/null || { log "rclone not installed"; exit 127; }; fi
if [ -n "$BACKUP_AGE_RECIPIENT" ]; then command -v age >/dev/null || { log "age not installed"; exit 127; }; fi

umask 077
mkdir -p "$BACKUP_DIR"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work="$(mktemp -d "$BACKUP_DIR/.work.XXXXXX")"
ping_url "/start"

# 1. Database. Password via MYSQL_PWD so it never shows in the process list. --single-transaction = consistent InnoDB snapshot
#    without locking the live site.
log "dumping database $DB_NAME"
MYSQL_PWD="$DB_PASSWORD" mysqldump --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
  --single-transaction --quick --routines --triggers --events --no-tablespaces --default-character-set=utf8mb4 \
  "$DB_NAME" | gzip -9 >"$work/db.sql.gz"
[ -s "$work/db.sql.gz" ] || { log "empty dump"; exit 1; }

# Record what the restore drill should find: certificate row count at dump time (approximate, see README) and a sample.
cert_count="$(MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" -N -B \
  -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.\`${TABLE_PREFIX}nimikh_certificates\`" 2>/dev/null || echo "-1")"

# 2. Uploads, including nimikh-certificates (the DB stores a sha256 of each PDF; the restore drill checks one).
log "archiving uploads from $UPLOADS_DIR"
tar --create --gzip --file "$work/uploads.tar.gz" --directory "$(dirname "$UPLOADS_DIR")" "$(basename "$UPLOADS_DIR")"

# 3. Manifest + checksums, then one outer tarball (so there is a single object to ship, encrypt and age out).
{
  printf '{\n'
  printf '  "created_utc": "%s",\n' "$stamp"
  printf '  "db_name": "%s",\n' "$DB_NAME"
  printf '  "table_prefix": "%s",\n' "$TABLE_PREFIX"
  printf '  "uploads_basename": "%s",\n' "$(basename "$UPLOADS_DIR")"
  printf '  "certificates_row_count": %s\n' "$cert_count"
  printf '}\n'
} >"$work/manifest.json"
( cd "$work" && sha256sum db.sql.gz uploads.tar.gz manifest.json >SHA256SUMS )

out="$BACKUP_DIR/nimikh-$stamp.tar"
tar --create --file "$out" --directory "$work" db.sql.gz uploads.tar.gz manifest.json SHA256SUMS
if [ -n "$BACKUP_AGE_RECIPIENT" ]; then
  age --recipient "$BACKUP_AGE_RECIPIENT" --output "$out.age" "$out"
  rm -f "$out"
  out="$out.age"
  log "encrypted with age"
else
  log "WARNING: BACKUP_AGE_RECIPIENT not set, archive is NOT encrypted (contains learner PII)"
fi
log "created $out ($(du -h "$out" | cut -f1))"

# 4. Off-site. `rclone copyto` + `rclone check` proves the object arrived intact before we expire anything.
if [ "$RCLONE_REMOTE" != "none" ]; then
  log "uploading to $RCLONE_REMOTE"
  rclone copyto "$out" "$RCLONE_REMOTE/$(basename "$out")" --s3-no-check-bucket
  rclone check "$(dirname "$out")" "$RCLONE_REMOTE" --one-way --include "$(basename "$out")" --size-only
  log "pruning remote objects older than ${REMOTE_RETENTION_DAYS}d"
  rclone delete "$RCLONE_REMOTE" --min-age "${REMOTE_RETENTION_DAYS}d" --include "nimikh-*.tar" --include "nimikh-*.tar.age"
else
  log "RCLONE_REMOTE=none: skipping off-site upload"
fi

# 5. Local retention (keep a few days so a restore does not need the network).
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'nimikh-*.tar' -o -name 'nimikh-*.tar.age' \) -mtime "+${LOCAL_RETENTION_DAYS}" -delete

ping_url ""
log "done"
