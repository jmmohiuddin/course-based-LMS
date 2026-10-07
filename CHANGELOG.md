# Changelog

## 0.1.1

Blueprint audit follow-up (see `docs/BLUEPRINT-AUDIT.md`).

- Certificate checklist: what is left before the certificate (`[nimikh_checklist]`, `GET /me/courses/{id}/checklist`), built on the same rule engine that issues certificates
- Player: cancellable 5-second countdown to the next lesson after a lesson completes
- Login rate limit of 5 attempts per minute per IP (filter `nimikh_lms_login_limit_per_minute`)
- PHPCS and PHPStan level 6 configuration with an advisory CI job; deployment notes in `docs/DEPLOYMENT.md`
- 10 new strings, translated into Bangla

## 0.1.0

First release of the `nimikh-lms` plugin, built from the Nimikh LMS product & technical blueprint.

**Core (blueprint phase 1)**
- In-video MCQ pop-ups graded server-side (retry, rewind, explanation); anti-skip player with speed cap and heartbeat validation
- Watch tracking and completion rules wired into Tutor LMS; certificates with random IDs, SHA-256 integrity, public `/verify/{code}` and revocation
- Instructor timeline editor, course reports (CSV), batch enrolment, settings, signed Bunny Stream URLs, dynamic watermark

**Phase 2**
- Badges and streaks, lesson discussion, SMS notifications, subscription plans, analytics dashboard (charts)

**Phase 3**
- Institute portals with branded certificates, live classes (Jitsi/Zoom/Meet, attendance, reminders), AI question suggestions (Anthropic API, drafts only), installable PWA with offline-safe progress

**Quality and tooling**
- Complete Bangla translation (all 317 strings), PHP-only tools to build it, and unit tests guarding it
- Tutor contract checker (`tools/check-tutor-contract.php`) and a CI workflow for MySQL, real Tutor LMS, uninstall and browser tests

**Also**
- Timestamped video notes, LinkedIn/Facebook/WhatsApp sharing, privacy export/erase, Bangla (bn_BD) translation, demo-data seeder, `uninstall.php` (data kept unless explicitly opted in)

See README.md "Known limits / things to verify" before going live.
