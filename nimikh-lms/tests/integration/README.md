# Integration test (real WordPress)

`e2e.php` boots WordPress and walks the whole product flow through the real REST routes:
author a video question, learner is gated, anti-skip heartbeat rules, MCQ retry/rewind/reveal,
lesson completion, exam, certificate issue + render + SHA-256, public verification, revoke, reports.

It needs a WordPress install with this plugin in `wp-content/plugins/nimikh-lms` and
`tutor-stub.php` in `wp-content/mu-plugins/` (a stand-in for the few Tutor LMS post types,
meta and the quiz-attempts table the adapter reads; real Tutor is not required).
It also expects a `wp_tutor_quiz_attempts` table (see the stub for columns).

```bash
cd /path/to/wordpress   # or set WP_ROOT=/path/to/wordpress
php wp-content/plugins/nimikh-lms/tests/integration/e2e.php           # phase 1 flow
php wp-content/plugins/nimikh-lms/tests/integration/e2e-phase23.php   # badges, SMS, discussion, subscriptions, institutes, live, AI, PWA
php wp-content/plugins/nimikh-lms/tests/integration/e2e-features.php  # notes, sharing, privacy export/erase, Bangla, demo-data seed/remove
```

`e2e-phase23.php` mocks every outbound HTTP call (`pre_http_request`), mail (`pre_wp_mail`) and SMS delivery
(`nimikh_lms_sms_deliver`), so it needs no network or credentials.

The script resets the plugin's own tables at the start of each run, so run it only against a throwaway site.
It was developed against WordPress 6.9 on SQLite; the stub contains a small test-only query filter for
that driver's `ON DUPLICATE KEY` parsing. On MySQL/MariaDB that filter is a no-op and can be removed.

`e2e-features.php` defines `NIMIKH_ALLOW_DEMO` to exercise the demo seeder, which creates and then deletes ~35 posts and 14 users;
run it on a throwaway site. The Tutor stand-in decides enrolment from `tutor_enrolled` posts like Tutor does.

**On MySQL / real Tutor.** `e2e-tutor.php` expects real Tutor LMS (no stub) and `e2e-uninstall.php` expects MySQL/MariaDB and is
destructive; both are driven by `.github/workflows/integration.yml`. `tutor-stub.php` is only for the stub jobs: on SQLite it
carries a test-only query rewrite, on MySQL it just registers post types and creates a minimal `tutor_quiz_attempts` table.
