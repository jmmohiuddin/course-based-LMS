#!/usr/bin/env bash
# Deploy a built plugin zip to one server over SSH + rsync, keeping the previous release for rollback.
# Used by .github/workflows/deploy.yml but runnable by hand:  ops/deploy/remote-deploy.sh deploy|rollback <zip>
#
# Env: SSH_HOST SSH_USER WP_ROOT (required); SSH_PORT (22); SSH_PRIVATE_KEY (key material) or SSH_KEY_FILE (path);
#      SSH_KNOWN_HOSTS (pinned host keys, required); RELOAD_CMD (optional command run on the server after the swap).
# Layout on the server:
#   $WP_ROOT/wp-content/plugins/nimikh-lms          live
#   $WP_ROOT/.nimikh-releases/incoming              rsync target (outside plugins/ so WordPress never sees a duplicate plugin)
#   $WP_ROOT/.nimikh-releases/previous              the release that was live before the last deploy
set -euo pipefail

mode="${1:?usage: remote-deploy.sh deploy|rollback [zip]}"
: "${SSH_HOST:?}" "${SSH_USER:?}" "${WP_ROOT:?}" "${SSH_KNOWN_HOSTS:?}"
SSH_PORT="${SSH_PORT:-22}"
RELOAD_CMD="${RELOAD_CMD:-}"
case "$WP_ROOT" in /*) ;; *) echo "WP_ROOT must be an absolute path" >&2; exit 2 ;; esac
case "$WP_ROOT" in *[!A-Za-z0-9_./-]*) echo "WP_ROOT contains unexpected characters" >&2; exit 2 ;; esac

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
chmod 700 "$tmp"
if [ -n "${SSH_KEY_FILE:-}" ]; then
  key="$SSH_KEY_FILE"
else
  : "${SSH_PRIVATE_KEY:?SSH_PRIVATE_KEY or SSH_KEY_FILE is required}"
  key="$tmp/id"
  printf '%s\n' "$SSH_PRIVATE_KEY" >"$key"
  chmod 600 "$key"
fi
printf '%s\n' "$SSH_KNOWN_HOSTS" >"$tmp/known_hosts"
ssh_opts=(-i "$key" -p "$SSH_PORT" -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$tmp/known_hosts" -o BatchMode=yes -o ConnectTimeout=15)
# shellcheck disable=SC2029 # client-side expansion of "$@" is intended
remote() { ssh "${ssh_opts[@]}" "$SSH_USER@$SSH_HOST" "$@"; }

plugins="$WP_ROOT/wp-content/plugins"
rel="$WP_ROOT/.nimikh-releases"

swap_in() { # run on the server: incoming -> live, live -> previous
  remote bash -s -- "$plugins" "$rel" "$RELOAD_CMD" <<'REMOTE'
set -euo pipefail
plugins="$1"; rel="$2"; reload="$3"
[ -d "$rel/incoming" ] || { echo "nothing to deploy in $rel/incoming" >&2; exit 1; }
rm -rf "$rel/previous"
if [ -d "$plugins/nimikh-lms" ]; then mv "$plugins/nimikh-lms" "$rel/previous"; fi
mv "$rel/incoming" "$plugins/nimikh-lms"
if [ -n "$reload" ]; then bash -c "$reload"; fi
echo "swapped in new release"
REMOTE
}

case "$mode" in
  deploy)
    zip="${2:?zip path required}"
    [ -f "$zip" ] || { echo "zip not found: $zip" >&2; exit 1; }
    mkdir "$tmp/unz"
    unzip -q "$zip" -d "$tmp/unz"
    [ -f "$tmp/unz/nimikh-lms/nimikh-lms.php" ] || { echo "zip does not contain nimikh-lms/nimikh-lms.php" >&2; exit 1; }
    remote "mkdir -p '$rel' && rm -rf '$rel/incoming'"
    # --delete matters only against the (just removed) incoming dir, so no stale files can survive a release.
    rsync -az --delete -e "ssh ${ssh_opts[*]}" "$tmp/unz/nimikh-lms/" "$SSH_USER@$SSH_HOST:$rel/incoming/"
    swap_in
    ;;
  rollback)
    remote bash -s -- "$plugins" "$rel" "$RELOAD_CMD" <<'REMOTE'
set -euo pipefail
plugins="$1"; rel="$2"; reload="$3"
[ -d "$rel/previous" ] || { echo "no previous release to roll back to" >&2; exit 1; }
rm -rf "$rel/failed"
mv "$plugins/nimikh-lms" "$rel/failed"
mv "$rel/previous" "$plugins/nimikh-lms"
if [ -n "$reload" ]; then bash -c "$reload"; fi
echo "rolled back (failed release kept in $rel/failed)"
REMOTE
    ;;
  *) echo "unknown mode: $mode" >&2; exit 2 ;;
esac
