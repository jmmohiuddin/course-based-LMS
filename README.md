# Nimikh LMS

Implementation of the *Nimikh LMS — Product & Technical Blueprint*: a WordPress LMS built on
**Tutor LMS Pro** with one custom plugin, **`nimikh-lms`**, that adds the differentiators:

- **In-video MCQ pop-ups** (timed, graded server-side, retry / rewind / explanation)
- **Anti-skip video** (seek guard, speed cap, heartbeat validation, gated by required questions)
- **Watch tracking & completion rules** that feed Tutor's native lesson completion
- **Verifiable certificates** (random 10-char code, PDF + SHA-256, public `/verify/{code}`, revocation)
- Learner dashboard / public profile, instructor reports, batch enrolment, admin settings

Everything else in the blueprint (courses, enrolment, checkout, quizzes/exams, instructor dashboard)
is Tutor LMS configuration, not custom code.

## Layout

```
nimikh-lms/
  nimikh-lms.php          bootstrap
  src/                    PSR-4 Nimikh\LMS  (Video, Questions, Progress, Certificates, Rest, Admin, Reports, Profile, Integrations/Tutor, Support)
  migrations/             versioned dbDelta schema (5 tables from blueprint 5.3)
  templates/              default certificate + public verify page
  assets/js, assets/css   vanilla-JS player + instructor timeline editor, design tokens (blueprint 9)
  theme/theme.json        the same tokens for the block theme / child theme
  tests/                  unit (PHPUnit), integration (real WordPress), browser (Playwright)
```

## Install

1. WordPress 6.4+, PHP 8.1+ (8.3 recommended), Tutor LMS (Pro for the frontend instructor dashboard).
2. Copy `nimikh-lms/` to `wp-content/plugins/`, then optionally `composer install --no-dev` inside it and
   `composer require mpdf/mpdf endroid/qr-code` for in-process PDF + QR. Without them certificates are
   stored as print-ready HTML (Save as PDF) and show no QR image; a Gotenberg URL in settings also works.
3. Activate. This creates the tables, the `nimikh_instructor` / `nimikh_institute_admin` roles and the
   `/verify` rewrite rules.
4. **Nimikh LMS → Settings**: Bunny pull-zone host + token key, issuer name, optional Gotenberg URL.
   Set `DISABLE_WP_CRON` and run a real cron every minute (certificate rendering uses Action Scheduler).

## Authoring (instructor)

- Course screen → **Nimikh completion rules**: min % watched, min average MCQ %, exam pass mark,
  certificate template, which quiz is the final exam.
- Lesson screen → **Interactive video**: tick *Use the interactive player*, set the Bunny video ID (or a
  direct URL), save, then play the video, pause, click **Add question here**. Markers can be dragged.
- Embed anywhere with `[nimikh_player lesson="ID"]`; lessons with the option on get the player automatically.

## Learner / public pages

`[nimikh_dashboard]` (progress + certificates + public-profile toggle), `[nimikh_profile user="login"]`,
`/verify`, `/verify/{code}`, `/certificate/{code}/download` (owner only).

## REST (`nimikh/v1`)

As in blueprint 6.3, plus `POST /lessons/{id}/duration` (editor sets duration from video metadata) and
`GET /reports/course/{id}?format=csv`. Write routes are cookie+nonce authenticated and capability-checked.

## Tests

```bash
cd nimikh-lms && composer install
vendor/bin/phpunit -c phpunit.xml.dist                       # 14 unit tests (pure logic)
NODE_PATH=$(npm root -g) node tests/browser/player.spec.cjs   # 20 browser checks (needs ffmpeg + Playwright Chromium)
php tests/integration/e2e.php                                 # 52 checks on a real WordPress, see tests/integration/README.md
```

## Blueprint coverage

| Req | Status |
| --- | --- |
| FR-01 – FR-04 in-video MCQ, behaviour settings, anti-skip, watch tracking | Built, tested |
| FR-05 final exam | Tutor quizzes (question bank, timer, attempts, pass mark); exam score read by the rule engine |
| FR-06 completion rule, FR-07 certificate, FR-08 verify page | Built, tested |
| FR-09 learner profile | Dashboard + opt-in public profile built; visual polish left to the theme |
| FR-10 payments | Tutor checkout; SSLCommerz/bKash/Stripe gateway plugins to be installed and configured |
| FR-11 analytics, FR-14 CSV batch enrolment | Built (JSON/CSV report; admin CSV tool). No charts UI yet |
| FR-12 Bangla/English | All strings translatable (`nimikh-lms` text domain); no `.po` files written yet |
| FR-13 notifications | Email on certificate issue; SMS exposed as the `nimikh_notify_sms` action only |
| FR-15 content protection | Signed expiring Bunny URLs + moving dynamic watermark. DRM not included |

## Known limits / things to verify

- **Tutor hook names and storage keys** (`tutor_quiz/attempt_ended`, `tutor_lesson_completed_after`,
  `tutor_action_tutor_complete_lesson`, `_tutor_course_id_for_lesson`, `tutor_quiz_attempts`, …) follow
  Tutor 2.x/3.x conventions but were not run against a real Tutor install (it was not available). All Tutor
  access is isolated in `Integrations/Tutor/`; verify on staging and pin the Tutor version.
- The heartbeat rule is the blueprint's (progress ≤ elapsed × max-rate × 1.1 + 15 s). A learner can still
  idle with the page open and later claim that elapsed time as watched; stricter proof needs a signed
  segment-level scheme.
- Instructor editor lives on the lesson edit screen. Tutor's React course builder has no stable hook for a
  custom tab, so embedding it there is future work.
- Question text is plain text (no rich text/LaTeX) in this version.
- Not built (blueprint phases 2–3): multi-tenant institute portals, PWA/mobile app, live classes, AI question
  generation, subscriptions, discussion forum, DevOps (Cloudflare, Redis, CI deploy, Sentry).
