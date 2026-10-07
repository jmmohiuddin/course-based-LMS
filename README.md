# Nimikh LMS

Implementation of the *Nimikh LMS — Product & Technical Blueprint*: a WordPress LMS built on
**Tutor LMS Pro** with one custom plugin, **`nimikh-lms`**, that adds the differentiators:

- **In-video MCQ pop-ups** (timed, graded server-side, retry / rewind / explanation)
- **Anti-skip video** (seek guard, speed cap, heartbeat validation, gated by required questions)
- **Watch tracking & completion rules** that feed Tutor's native lesson completion
- **Verifiable certificates** (random 10-char code, PDF + SHA-256, public `/verify/{code}`, revocation)
- Learner dashboard / public profile, instructor reports, batch enrolment, admin settings

Phase 2–3 additions (all inside the same plugin):

- **Badges & streaks**, **lesson discussion** (moderated Q&A), **SMS notifications** (Bangladeshi gateways via a URL template)
- **Subscriptions** (plans unlock courses for N days; payment gateways grant access with one action hook)
- **Institute portals** (branded `/institute/{slug}` page, branded certificates, institute admins who only see their own members)
- **Live classes** (Jitsi room created for you, or Zoom/Meet/other link; server-mediated join with attendance; reminders)
- **AI question suggestions** (Anthropic Messages API; drafts the instructor reviews, never auto-saved)
- **PWA** (manifest, service worker, offline page, install button, offline-safe watch progress)
- **Analytics dashboard** (KPI tiles, retention curve with question markers, per-question accuracy)

Everything else in the blueprint (courses, enrolment, checkout, quizzes/exams, instructor dashboard)
is Tutor LMS configuration, not custom code.

## Layout

```
nimikh-lms/
  nimikh-lms.php          bootstrap
  src/                    PSR-4 Nimikh\LMS  (Video, Questions, Progress, Certificates, Rest, Admin, Reports, Profile, Integrations/Tutor, Support,
                          Badges, Sms, Discussion, Subscriptions, Orgs, Live, Ai, Pwa, Frontend)
  migrations/             versioned dbDelta schema (001: blueprint 5.3 tables, 002: phase 2-3 tables)
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
3. **Use pretty permalinks** (Settings → Permalinks → anything but "Plain"). `/verify`, `/institute/{slug}` and the
   PWA files (`/nimikh-sw.js`, `/nimikh-manifest.json`) are rewrite rules and do not work with plain permalinks.
4. Activate. This creates the tables, the `nimikh_instructor` / `nimikh_institute_admin` roles and the rewrite rules.
5. **Nimikh LMS → Settings**: Bunny pull-zone host + token key, issuer name, optional Gotenberg URL.
   Set `DISABLE_WP_CRON` and run a real cron every minute (certificate rendering uses Action Scheduler).

## Authoring (instructor)

- Course screen → **Nimikh completion rules**: min % watched, min average MCQ %, exam pass mark,
  certificate template, which quiz is the final exam.
- Lesson screen → **Interactive video**: tick *Use the interactive player*, set the Bunny video ID (or a
  direct URL), save, then play the video, pause, click **Add question here**. Markers can be dragged.
- Embed anywhere with `[nimikh_player lesson="ID"]`; lessons with the option on get the player automatically.

## Phase 2–3 features: how to use them

**Reports** (Nimikh LMS → Reports): pick a course; KPI tiles, a retention curve per lesson with dashed lines at each
question, and a per-question table that flags questions under 50% right-first-try. CSV download included.

**Discussion**: appears under every lesson for logged-in learners (turn off in Settings). Course staff can hide or delete
any post; learners can delete their own. A learner's new top-level question emails the course author.

**SMS**: Settings → enable, then paste the gateway's HTTPS URL using `{to}` `{message}` `{sender}` `{api_key}`
(or choose POST). Numbers are read from user meta `nimikh_phone` / `billing_phone` / `phone`, normalised to `8801XXXXXXXXX`
(Bangla digits accepted). Events: enrolled, exam result, certificate issued, live-class reminder. Templates are editable;
learners can opt out with user meta `nimikh_sms_optout`. Delivery is logged in `nimikh_sms_log`.

**Plans & subscriptions** (Nimikh LMS → Plans): create a plan (courses + days), grant by email, or let your payment
integration call `do_action('nimikh_subscription_paid', $user_id, $plan_id, $reference)`. Granting enrols the learner in Tutor;
expiry (hourly cron) cancels only the enrolments the subscription itself created, never a course the learner bought.
Renewing early adds days to the current expiry. `GET /nimikh/v1/plans` is a public catalogue.

**Institutes** (Nimikh LMS → Institutes): create an institute (name, colour, https logo, admin email), assign courses, add
members. Its portal is `/institute/{slug}`; certificates for its courses carry its name, colour and logo, and the public
verify page shows it as the issuer. Institute admins use `/orgs/{id}/enrol` (batch-create + enrol members) and
`/orgs/{id}/report`, which only ever lists that institute's own members. This is single-site multi-tenancy; WordPress
multisite remains the option for tenants that need full isolation.

**Live classes** (Nimikh LMS → Live classes, or `POST /courses/{id}/live`): Jitsi rooms are created automatically
(host configurable); Zoom/Meet/other need an https link. Learners see sessions via `[nimikh_live course="ID"]` and join
through the server 10 minutes before the start, which records attendance; the join link is never in the course listing.
A 5-minute cron emails (and SMS-es) enrolled learners once, within an hour of the start.

**AI suggestions** (Settings → enable + Anthropic API key + model ID): in the lesson's Interactive video editor click
*Suggest questions (AI)*. It uses pasted text or the lesson's WebVTT captions, returns up to 10 draft questions with
suggested timestamps, and the server discards anything malformed (bad option counts or indexes, duplicates, questions in
the first 30 s, ones closer than 30 s). Nothing is saved until the instructor clicks *Review & add*. Transcripts are sent to
the Anthropic API, so the feature is off by default and rate-limited to 10 requests/user/hour.

**PWA**: enabled by default. Adds the manifest link, registers the service worker, and `[nimikh_install_button]` shows an
install button when the browser offers one. Caching is deliberately narrow: plugin CSS/JS and an offline page only. REST
calls, video, wp-admin and login are never cached. Watch progress is kept in `localStorage` until the server acknowledges
it, so a lesson watched on a train syncs when the connection returns.

## Learner / public pages

`[nimikh_dashboard]` (streak, badges, active subscriptions, certificates, public-profile toggle), `[nimikh_discussion]`,
`[nimikh_live]`, `[nimikh_install_button]`, `[nimikh_profile user="login"]`,
`/verify`, `/verify/{code}`, `/certificate/{code}/download` (owner only).

## REST (`nimikh/v1`)

As in blueprint 6.3, plus `POST /lessons/{id}/duration` (editor sets duration from video metadata) and
`GET /reports/course/{id}?format=csv`. Phase 2–3 adds: `/me/badges`, `/me/subscriptions`, `/me/managed-courses`,
`/lessons/{id}/discussion`, `/discussion/{id}[/hide]`, `/plans`, `/subscriptions[/{id}/cancel]`, `/orgs[/{id}/members|courses|enrol|report]`,
`/courses/{id}/live`, `/live/{id}/join|cancel|attendance`, `/lessons/{id}/ai-questions`. Write routes are cookie+nonce
authenticated and capability-checked.

## Tests

```bash
cd nimikh-lms && composer install
vendor/bin/phpunit -c phpunit.xml.dist                          # 26 unit tests (pure logic)
NODE_PATH=$(npm root -g) node tests/browser/player.spec.cjs      # 23 browser checks: player (needs ffmpeg + Playwright Chromium)
NODE_PATH=$(npm root -g) node tests/browser/phase23.spec.cjs     # 23 browser checks: PWA offline, discussion, live, reports
php tests/integration/e2e.php                                    # 52 checks on a real WordPress (phase 1)
php tests/integration/e2e-phase23.php                            # 95 checks on a real WordPress (phase 2-3)
```

## Blueprint coverage

| Req | Status |
| --- | --- |
| FR-01 – FR-04 in-video MCQ, behaviour settings, anti-skip, watch tracking | Built, tested |
| FR-05 final exam | Tutor quizzes (question bank, timer, attempts, pass mark); exam score read by the rule engine |
| FR-06 completion rule, FR-07 certificate, FR-08 verify page | Built, tested |
| FR-09 learner profile | Dashboard + opt-in public profile built; visual polish left to the theme |
| FR-10 payments | Tutor checkout; SSLCommerz/bKash/Stripe gateway plugins to be installed and configured |
| FR-11 analytics, FR-14 CSV batch enrolment | Built, including the charts dashboard |
| FR-12 Bangla/English | All strings translatable (`nimikh-lms` text domain); no `.po` files written yet |
| FR-13 notifications | Email + SMS (configurable gateway) for enrolment, exam result, certificate, live-class reminder |
| FR-15 content protection | Signed expiring Bunny URLs + moving dynamic watermark. DRM not included |

## Known limits / things to verify

- **Tutor hook names and storage keys** (`tutor_quiz/attempt_ended`, `tutor_lesson_completed_after`,
  `tutor_action_tutor_complete_lesson`, `_tutor_course_id_for_lesson`, `tutor_quiz_attempts`, …) follow
  Tutor 2.x/3.x conventions but were not run against a real Tutor install (it was not available). All Tutor
  access is isolated in `Integrations/Tutor/`; verify on staging and pin the Tutor version.
- The heartbeat rule is the blueprint's (progress ≤ elapsed × max-rate × 1.1 + 15 s). A learner can still
  idle with the page open and later claim that elapsed time as watched; stricter proof needs a signed
  segment-level scheme.
- Phase 2–3 modules were verified end to end on real WordPress (SQLite) and Chromium, but external services were
  not: no real SMS gateway, Anthropic API, Jitsi/Zoom, or payment gateway was called (they are mocked at the HTTP
  boundary). Check each once with real credentials in staging. The AI request format follows the public Messages API
  (`x-api-key`, `anthropic-version: 2023-06-01`); set the model ID your account has access to.
- Subscriptions are payment-agnostic on purpose: there is no checkout. Connect your gateway to `nimikh_subscription_paid`.
- Institute isolation is application-level (roles + queries scoped to members), not a separate database or site.
- Live classes link out to the provider; there is no embedded video room, recording, or provider-side attendance import.
- The PWA is installable and offline-tolerant but there is no offline video download (HLS segments are deliberately not cached).
- Instructor editor lives on the lesson edit screen. Tutor's React course builder has no stable hook for a
  custom tab, so embedding it there is future work.
- Question text is plain text (no rich text/LaTeX) in this version.
- Not built: native mobile apps (the PWA is the mobile story), leaderboards, WordPress-multisite white-labelling,
  SCORM import, DevOps (Cloudflare, Redis, CI deploy, Sentry).
