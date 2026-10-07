# ops/ - infrastructure for Nimikh LMS

Real config files for blueprint sections 5.6, 6.1, 6.7 and 6.8. Prose context lives in `../docs/DEPLOYMENT.md`.

## Honest status: what has and has not been tested

No servers, Cloudflare account, S3/R2 bucket or Docker daemon were available when this was written.

| Piece | Checked | NOT checked |
|---|---|---|
| `docker-compose.yml` | `docker compose config` parses it | never started; image tags, the cron sidecar loop and the Mailpit mu-plugin are untested |
| `nginx/nimikh.conf` | read by hand only | never run through `nginx -t`; no cache-bypass behaviour observed. Cloudflare IP list is from memory: regenerate with `update-cloudflare-ips.sh` |
| `cron/nimikh.crontab` | read by hand | never installed |
| `backup/*.sh` | `bash -n`, `shellcheck` clean; smoke-tested end to end with stub `mysql`/`mysqldump` (backup, archive checksum, row count, sha256 sample, production-DB refusal) | real MySQL, `rclone` upload/prune against S3/R2, `age` encryption, a real restore |
| `cloudflare/` | `terraform fmt` and `terraform validate` pass (Terraform 1.9.8, provider 4.52.1) | no `plan`/`apply`; rule expressions never evaluated by Cloudflare; plan-tier limits (below) not confirmed |
| `monitoring/check.sh` | shellcheck clean; run against a local fake server (ok and degraded cases) | the real `/health` endpoint (its JSON shape is assumed from the spec) |
| `deploy/*.sh`, `.github/workflows/deploy.yml` | shellcheck, YAML parses, `health-wait.sh` run against a fake server | the workflow has never run; SSH/rsync/rollback never executed; GitHub environment setup is manual |

Treat everything here as a reviewed first draft for a staging run, not as proven production config.

## 1. Local dev (`docker-compose.yml`)

```
cd ops
cp .env.example .env        # optional, defaults work
docker compose up -d
```

- WordPress (PHP 8.3, Apache): http://localhost:8080, finish the installer, then activate **Nimikh LMS** (the repo's `nimikh-lms/` is bind-mounted into `wp-content/plugins/nimikh-lms`, so there is no zip step; run `composer install` inside `nimikh-lms/` on the host if you need vendor libs).
- `WP_ENVIRONMENT_TYPE=local` (env var and `define`), so the demo seeder is allowed.
- `DISABLE_WP_CRON` is true; the `cron` service runs `php wp-cron.php` every 60 seconds (this is what drives Action Scheduler, i.e. certificate PDFs). Check: `docker compose logs cron`.
- MySQL 8, Redis 7 (host `redis`, install the Redis Object Cache plugin and it picks up `WP_REDIS_HOST`), Gotenberg 8 (from WordPress use `http://gotenberg:3000`), Mailpit (UI http://localhost:8025; `dev/mailpit-mu-plugin.php` points `wp_mail()` at it).
- Data lives in the `wp_data` and `db_data` volumes: `docker compose down -v` wipes both.
- Not for production: debug is on, MySQL root password is `root`, ports are published.

## 2. Nginx (`nginx/`)

`nimikh.conf` (site, FastCGI micro-cache, pretty permalinks, `xmlrpc.php` denied, certificate directory not served), `security-headers.conf`, `cloudflare-realip.conf` (Cloudflare ranges + `real_ip_header CF-Connecting-IP`), `update-cloudflare-ips.sh`.

Install: put the three `.conf` snippets under `/etc/nginx/snippets/nimikh/` (nimikh.conf goes in `conf.d/`, and expects to be included inside `http {}`), edit `server_name`, `root`, certificate paths and the php-fpm socket, then `nginx -t && systemctl reload nginx`.

- Cache is skipped for non-GET, `wordpress_logged_in_*`, `wp-postpass_*`, `comment_author_*`, WooCommerce cart/session cookies, `/wp-admin`, `/wp-login.php`, and the whole `/wp-json/` tree (a superset of `/wp-json/nimikh/v1/*`, so the heartbeat and `/health` are never cached). Debug with the `X-Nimikh-Cache` response header.
- `real_ip_header CF-Connecting-IP` is only honoured for connections from Cloudflare ranges. Also firewall port 443 to Cloudflare ranges at the host or provider level, otherwise anyone can reach the origin directly.
- No CSP header on purpose (the player embeds Bunny iframes); add one in report-only mode first.
- `update-cloudflare-ips.sh` regenerates `cloudflare-realip.conf` from cloudflare.com/ips-v4 and -v6; run it monthly from cron.

## 3. Cron (`cron/nimikh.crontab`)

Install to `/etc/cron.d/nimikh`: wp-cron every minute (with `flock`, no overlap), nightly backup 02:10, monthly restore drill on the 1st at 04:30. Edit paths (`/var/www/site`, `/opt/nimikh-ops`) first.

## 4. Backups (`backup/`)

- `backup.sh`: `mysqldump --single-transaction` + tarball of the whole uploads directory (which includes `nimikh-certificates/`) + `manifest.json` + `SHA256SUMS`, packed into `nimikh-<UTC stamp>.tar`. If `BACKUP_AGE_RECIPIENT` is set the archive is encrypted with [age](https://age-encryption.org) (`.tar.age`); otherwise it warns, because the dump holds learner PII. Then `rclone copyto` to `RCLONE_REMOTE`, `rclone check`, remote retention `REMOTE_RETENTION_DAYS` (default 30), local retention `LOCAL_RETENTION_DAYS` (default 3). Optional heartbeat pings via `BACKUP_PING_URL` (healthchecks.io style, `/fail` appended on error).
- `restore-drill.sh [archive]`: downloads the newest backup (or uses the file you pass), verifies `SHA256SUMS`, decrypts with `BACKUP_AGE_IDENTITY_FILE`, restores into a scratch database (`DRILL_DB_NAME`, must contain `drill`, `scratch` or `restore` and must differ from `DB_NAME`; dropped afterwards), then checks (a) restored `nimikh_certificates` row count equals the count recorded in the manifest (`DRILL_MAX_COUNT_DRIFT` tolerance, default 0) and (b) the sha256 of one random certificate PDF from the restored uploads equals the `sha256` column. Non-zero exit and `/fail` ping on any problem.
- Config: copy `backup/backup.env.example` to `/etc/nimikh/backup.env` (root, mode 600). Every variable can also be passed in the environment. The rclone remote is defined by `RCLONE_CONFIG_<NAME>_*` variables, with an R2 example in the file; the DB user needs `SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER`. The drill user needs `CREATE`/`DROP` on the scratch schema.
- Known limits: the manifest row count is read just *after* the dump, so on a busy site a certificate issued in between makes the drill fail by one (raise `DRILL_MAX_COUNT_DRIFT` to 1-2 if that is noisy). If certificates are moved off-site through the `nimikh_lms_certificate_storage` filter, the sha256 sample is skipped when no local file exists. Keep the age *private* key off the server and test that you can actually decrypt with it; the drill only proves it if run where the identity is available. Run the first drill by hand and read the output.
- Run it for real once: `sudo BACKUP_ENV_FILE=/etc/nimikh/backup.env ops/backup/backup.sh` then `ops/backup/restore-drill.sh`.

## 5. Cloudflare (`cloudflare/`)

Terraform, `cloudflare/cloudflare` provider `~> 4.52` (v4 syntax; v5 needs a port).

```
cd ops/cloudflare
export CLOUDFLARE_API_TOKEN=...      # Zone > Zone Rulesets > Edit
cp terraform.tfvars.example terraform.tfvars   # zone_id, hostname
terraform init && terraform plan
```

- Cache rules: bypass for `/wp-json/` (incl. `/wp-json/nimikh/v1/*`), `/wp-admin`, `/wp-login.php`, cron, xmlrpc, logged-in/postpass/commenter/WooCommerce cookies and non-GET; cache `/verify` and `/verify/*`; cache other anonymous pages. Edge TTLs (300 s) are literals in `main.tf` because provider 4.52 rejects variable-derived `edge_ttl.default` at validate time.
- Rate limits: `/wp-login.php` and `POST /wp-json/nimikh/v1/*/attempt`, per IP, block. Defaults (10 s window, 10 s block) are what Free plans accept; on paid plans set `rate_limit_period_seconds = 60` and a longer mitigation (see `terraform.tfvars.example`). Plan-tier limits for rate limiting and cache rules change; check the dashboard if `apply` is rejected.
- Both rulesets are zone-wide resources of their phase: applying will replace any cache or rate-limit rules you already created by hand in that phase. Import or recreate them first.
- The plugin also rate-limits logins itself (5/min/IP). The Cloudflare rule is the second layer.

## 6. Monitoring (`monitoring/`)

- `monitors.json`: tool-neutral definition of three checks every 60 s: `/wp-json/nimikh/v1/health` (JSON assertions on `status`, `db`, `cron_lag_seconds`, `failed_jobs`), one `/verify/` URL, one lesson page. Not an import file for any product; copy into Uptime Kuma / Better Stack by hand.
- `check.sh`: the same checks as a script (curl + jq), exit 1 on failure, optional `PING_URL` heartbeat and `ALERT_WEBHOOK`. Run it from a machine *outside* the origin, e.g. `* * * * * BASE_URL=https://lms.example.com /opt/nimikh-ops/monitoring/check.sh`. Env: `VERIFY_PATH`, `LESSON_PATH`, `MAX_CRON_LAG`, `MAX_FAILED_JOBS`.
- The health endpoint is assumed to return `{"status":"ok"|"degraded","db":bool,"cron_lag_seconds":int,"failed_jobs":int}`. The verify page is Cloudflare-cached, so it proves the edge; only `/health` proves the origin. Use a real lesson URL and, once available, a real certificate code.
- **Sentry**: create a Sentry project (PHP) and, optionally, a browser JS project. Paste the DSN into *Nimikh LMS -> Settings -> `sentry_dsn`* (the setting is being added to the plugin; until it ships there is nothing to paste into). Set the Sentry environment from `WP_ENVIRONMENT_TYPE` (`production`/`staging`) so staging noise is separable, and add an alert rule on new issues. Do not put the DSN in this repo.
- Weekly report items from the blueprint (slow queries > 100 ms, failed Action Scheduler jobs, failed payments) are not implemented here; `failed_jobs` in the health JSON covers the second one.

## 7. Deploy (`../.github/workflows/deploy.yml`, `deploy/`)

Flow: **build** (`WITH_VENDOR=1 nimikh-lms/tools/build-zip.sh`, checks the tag matches the plugin version and that `vendor/` is in the zip) -> **staging** (rsync to the server, swap, health check, rollback on failure) -> **production** (same, pauses for approval). Triggers: pushing a `v*` tag, or *Run workflow* (tick `deploy_production` to continue past staging).

Setup you must do by hand in GitHub:
1. Create environments `staging` and `production`. On `production` add **Required reviewers** (this is the manual approval; the workflow file cannot enforce it).
2. Per environment, secrets `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS` (`ssh-keyscan -p PORT HOST`, verify the fingerprint yourself) and variables `SSH_HOST`, `SSH_USER`, `SSH_PORT` (optional), `WP_ROOT`, `BASE_URL`, `RELOAD_CMD` (optional, e.g. `sudo -n systemctl reload php8.3-fpm` for opcache, and/or `wp rewrite flush`).
3. The SSH user needs write access to `WP_ROOT/wp-content/plugins` and `WP_ROOT/.nimikh-releases`.

How the swap works (`deploy/remote-deploy.sh`): rsync to `WP_ROOT/.nimikh-releases/incoming`, then two `mv`s (live -> `previous`, `incoming` -> live). Not atomic: there is a few-millisecond window with no plugin. The previous release stays in `.nimikh-releases/previous` for `remote-deploy.sh rollback`, which the workflow runs automatically if the health check fails. Database migrations are not rolled back. `deploy/health-wait.sh` polls `/health` for up to 3 minutes (needs `status: ok`, `db: true`, cron lag <= 300 s) and then requires `/verify/` to return 200 (catches un-flushed rewrite rules).

The workflow does not run tests; `ci.yml` does. Gate production tags on a green CI run yourself.
