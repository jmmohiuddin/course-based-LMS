# Deployment notes

Operational settings from blueprint sections 5.6, 6.1, 6.5–6.8. None of this is code in the plugin; it is what the servers need.

Config files for most of it now live in [`ops/`](../ops/README.md) (dev stack, Nginx, cron, backups and restore drill, Cloudflare Terraform, monitoring, deploy scripts) with `.github/workflows/deploy.yml`. They have not been run against real servers; `ops/README.md` lists exactly what was and was not checked.

## Server (MVP: up to ~2,000 active learners)

- 1 VPS, 4 vCPU / 8 GB, Singapore region; Ubuntu 24.04, Nginx, PHP-FPM 8.3, MySQL 8, Redis 7, behind Cloudflare.
- WordPress: `define('DISABLE_WP_CRON', true);` and a real cron every minute:
  `* * * * * cd /var/www/site && php wp-cron.php >/dev/null 2>&1` (or `wp cron event run --due-now`).
  Certificate PDFs render through Action Scheduler, so a stopped cron means late certificates (target: < 60 s).
- Cron line and Nginx site: `ops/cron/nimikh.crontab`, `ops/nginx/nimikh.conf`. Local dev stack: `ops/docker-compose.yml`.
- Pretty permalinks are required (`/verify`, `/institute/{slug}`, the PWA files are rewrite rules).
- Install the Redis object cache plugin; logged-in LMS pages depend on it. The plugin's rate limiter uses transients, which become
  cheap Redis keys once an object cache is present.
- Set `WP_ENVIRONMENT_TYPE` to `production` on the live site (the demo seeder refuses to run there) and `staging` elsewhere.

## Cloudflare

- Cache everything public: catalogue, course pages and `/verify/*` (the verify page is `noindex` and safe to cache).
- **Bypass cache** for `/wp-json/nimikh/v1/*`, `/wp-admin`, `/wp-login.php` and logged-in cookies. The player's heartbeat must never be cached.
- WAF rule: rate-limit `/wp-login.php` and `/wp-json/nimikh/v1/*/attempt`. The plugin also limits logins to 5/min/IP (filter
  `nimikh_lms_login_limit_per_minute`) and reads the client IP from `CF-Connecting-IP`; restrict origin access to Cloudflare's ranges so
  that header cannot be spoofed.

Terraform for these rules: `ops/cloudflare/`.

## Video

Bunny Stream with token authentication: set the pull-zone host and token key in *Nimikh LMS → Settings*, enable hotlink protection
on the pull zone. WordPress never serves video bytes.

## Payments, email, SMS

- Payment gateways (SSLCommerz/bKash, Stripe) are Tutor/WooCommerce plugins; confirm merchant rates and callback URLs on staging.
  Subscriptions grant access through `do_action('nimikh_subscription_paid', $user_id, $plan_id, $reference)`.
- Email through SES (FluentSMTP). SMS: paste the gateway URL template in Settings and test with a real number on staging.

## Security checklist

- 2FA for every staff account; least privilege roles `nimikh_instructor` and `nimikh_institute_admin`.
- Wordfence or Patchstack; updates pinned and tested on staging first (run `php tools/check-tutor-contract.php <tutor dir>` before any Tutor upgrade).
- Daily off-site backups kept 30 days; restore drill monthly. Certificates under `uploads/nimikh-certificates` are part of the backup
  (or move them to R2); the `sha256` in the database detects tampering.
  Scripts: `ops/backup/backup.sh` (nightly, off-site via rclone, optional age encryption) and `ops/backup/restore-drill.sh` (monthly).

## Monitoring

- Uptime check every minute on `/wp-json/nimikh/v1/health`, one verify URL and one lesson page: `ops/monitoring/`. Sentry DSN goes in *Nimikh LMS -> Settings -> sentry_dsn*.
- Sentry for PHP and JS; weekly report of slow queries (> 100 ms), failed Action Scheduler jobs and failed payments.
- Heartbeat target: < 50 ms p95. If it climbs at ~2,000 concurrent learners, follow the growth stage of blueprint 5.6 (managed MySQL,
  Redis server, two app servers).

## Release

`WITH_VENDOR=1 nimikh-lms/tools/build-zip.sh` builds the installable zip (mPDF and the QR library bundled). Deploy from Git through CI (`.github/workflows/deploy.yml`: staging, then production after manual approval, with a health check and rollback); do not edit in wp-admin.
