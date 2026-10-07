#!/usr/bin/env bash
# Builds an installable plugin zip without tests, tools or dev files. Needs only bash, cp and zip.
# Usage: tools/build-zip.sh [output-dir]            -> <output-dir>/nimikh-lms-<version>.zip
#        WITH_VENDOR=1 tools/build-zip.sh           -> also bundles production Composer deps (mPDF, QR)
set -euo pipefail
cd "$(dirname "$0")/.."
version=$(grep -m1 "Version:" nimikh-lms.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
mkdir -p "${1:-dist}"
out=$(cd "${1:-dist}" && pwd)
stage=$(mktemp -d); trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/nimikh-lms"
for item in * .[!.]*; do
  [ -e "$item" ] || continue
  case "$item" in vendor|dist|composer.lock|.phpunit*|tools) continue ;; esac
  grep -qxF "$item" .distignore && continue
  cp -R "$item" "$stage/nimikh-lms/"
done
if [ "${WITH_VENDOR:-0}" = "1" ]; then
  (cd "$stage/nimikh-lms" && COMPOSER_ALLOW_SUPERUSER=1 composer require --no-interaction -q mpdf/mpdf endroid/qr-code && composer install --no-dev --optimize-autoloader --no-interaction -q)
fi
rm -f "$out/nimikh-lms-$version.zip"
(cd "$stage" && zip -qr "$out/nimikh-lms-$version.zip" nimikh-lms)
echo "$out/nimikh-lms-$version.zip"
