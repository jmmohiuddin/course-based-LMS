# Changelog

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

**Also**
- Timestamped video notes, LinkedIn/Facebook/WhatsApp sharing, privacy export/erase, Bangla (bn_BD) translation, demo-data seeder, `uninstall.php` (data kept unless explicitly opted in)

See README.md "Known limits / things to verify" before going live.
