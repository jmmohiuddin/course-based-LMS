# Blueprint audit

Audit of the repository against *Nimikh LMS — Product & Technical Blueprint* (7 Oct 2026), and what was done about the gaps.

**Verdict:** the plugin already implemented every *Must* functional requirement (FR-01 – FR-10, FR-12) and all *Should* items except
where they are owned by Tutor or an external service. The audit found a handful of smaller gaps in the UX/security/tooling sections;
the ones that can be built and tested in the plugin are now closed.

## Requirement coverage

| Blueprint | Status | Where |
| --- | --- | --- |
| FR-01 – FR-04 in-video MCQ, settings, anti-skip, watch tracking | Done | `src/Video`, `src/Questions`, `src/Progress`, `assets/js/player.js` |
| FR-05 final exam | Tutor quizzes; score read by the rule engine | `TutorAdapter::bestQuizPercent` |
| FR-06 completion rule, FR-07 certificate, FR-08 verify | Done | `src/Certificates` |
| FR-09 learner profile | Done (dashboard + public profile) | `src/Profile` |
| FR-10 payments | Tutor checkout + gateway plugins (configuration) | — |
| FR-11 analytics, FR-14 batch enrolment | Done | `src/Reports`, `src/Support/BatchEnrol.php` |
| FR-12 Bangla / English | Done: 397 strings translated (draft, needs native review) | `languages/` |
| FR-13 notifications | Email + SMS | `src/Sms` |
| FR-15 content protection | Signed URLs + watermark (no DRM, as the blueprint advises) | `src/Video/BunnyUrlSigner.php` |
| 6.5 rate limits: attempts 30/min/user, OTP | Attempts + heartbeat limited | `src/Support/RateLimiter.php` |
| 6.5 rate limit: login 5/min/IP | **Added** | `src/Support/LoginLimiter.php` |
| UX principle 4 "show the finish line" (checklist of what is left for the certificate) | **Added** | `src/Certificates/Checklist.php`, `[nimikh_checklist]`, `GET /me/courses/{id}/checklist` |
| UX flow A step 3: next lesson auto-plays after a 5 s countdown | **Added** (cancellable; `next_url` in the player payload) | `player.js` `offerNext` |
| 6.7 CI: PHPCS + PHPStan level 6 | **Added** as an advisory job and config files | `phpcs.xml.dist`, `phpstan.neon.dist`, `.github/workflows/ci.yml` |
| 6.7 CI: enrol → watch → MCQ → exam → certificate → verify | Present (`e2e.php`, `player.spec.cjs`) | `tests/` |
| 6.7 Deployment, 5.6 scaling, 6.8 monitoring | **Documented** | `docs/DEPLOYMENT.md` |

## Built after the first audit pass

| Item | What was built | Where |
| --- | --- | --- |
| Final-exam retake cooldown (default 24 h, per course) | Enforced on Tutor's start-quiz ajax/form actions; the last-attempt time is recorded by the plugin, not read from Tutor. Fail-open if Tutor renames the action (reported by the contract checker). | `src/Exam/`, `src/Integrations/Tutor/ExamGuard.php` |
| After a failed exam: what to rewatch | Lessons ranked by the learner's own in-video question accuracy and watch %, shown in the checklist and `GET /me/courses/{id}/exam`. Tutor's exam questions have no topic, so this is lesson-based, not topic-based. | `src/Exam/Rewatch.php` |
| Phone-OTP and Google sign-up/sign-in | `[nimikh_login]`, `/auth/otp/request`, `/auth/otp/verify`, `/auth/google`. Codes are hashed, expire in 5 min, burn after 5 wrong guesses, 3 per 15 min per phone. Google tokens are checked for audience, issuer, expiry and verified email. Admins/editors can never sign in this way. | `src/Auth/`, `assets/js/auth.js` |
| Visual certificate template editor | Drag-and-drop A4 editor (mouse, touch, keyboard) on the template screen; layout is validated server-side and rendered through the existing placeholder pipeline (QR, logo, institute colour). | `src/Certificates/Layout.php`, `CertificateEditor.php`, `assets/js/cert-editor.js` |
| `/health` endpoint, Sentry error reporting, weekly ops email | Public `GET /nimikh/v1/health`; SDK-free Sentry reporter for fatals in this plugin and JS errors (off until a DSN is saved); optional weekly report. | `src/Ops/`, `src/Rest/HealthController.php` |
| Local env, Nginx, Cloudflare, backups + restore drill, monitoring, deploy pipeline | Config files and scripts | `ops/`, `.github/workflows/deploy.yml` |
| Interview guides, survey, usability plan, clickable prototype | Discovery kit | `docs/discovery/`, `docs/prototype/` |

## Still open

| Item | Reason |
| --- | --- |
| Running interviews, the survey and the usability test | They need real people. The kit is ready; no research has been done, and the personas remain hypotheses. |
| Applying the infrastructure | `ops/` was validated with linters and stub-based smoke tests only: it has not been run against a real server, Cloudflare zone, bucket or GitHub environment. |
| A Figma file | The clickable HTML prototype replaces it for testing; a design file still needs a designer. |
| Items the blueprint puts out of MVP (native app, SCORM, leaderboards, multisite white-label) | Deliberately skipped, unchanged. |

## What could not be verified here

The audit environment had no WordPress, MySQL or Tutor LMS, so the integration suites (`tests/integration/*`) and the new
`e2e-gaps.php` were not run locally; they run in `.github/workflows/integration.yml`. Verified locally: PHP lint of every file,
the 54 PHPUnit tests (including the Bangla-translation guards), JS syntax checks and all three Playwright browser specs (player, phase 2-3, certificate editor + phone sign-in form). The Tutor start-quiz hook names in `ExamGuard` are unverified against a real Tutor.
