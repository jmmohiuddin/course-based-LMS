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
| FR-12 Bangla / English | Done: 327 strings translated (draft, needs native review) | `languages/` |
| FR-13 notifications | Email + SMS | `src/Sms` |
| FR-15 content protection | Signed URLs + watermark (no DRM, as the blueprint advises) | `src/Video/BunnyUrlSigner.php` |
| 6.5 rate limits: attempts 30/min/user, OTP | Attempts + heartbeat limited | `src/Support/RateLimiter.php` |
| 6.5 rate limit: login 5/min/IP | **Added** | `src/Support/LoginLimiter.php` |
| UX principle 4 "show the finish line" (checklist of what is left for the certificate) | **Added** | `src/Certificates/Checklist.php`, `[nimikh_checklist]`, `GET /me/courses/{id}/checklist` |
| UX flow A step 3: next lesson auto-plays after a 5 s countdown | **Added** (cancellable; `next_url` in the player payload) | `player.js` `offerNext` |
| 6.7 CI: PHPCS + PHPStan level 6 | **Added** as an advisory job and config files | `phpcs.xml.dist`, `phpstan.neon.dist`, `.github/workflows/ci.yml` |
| 6.7 CI: enrol → watch → MCQ → exam → certificate → verify | Present (`e2e.php`, `player.spec.cjs`) | `tests/` |
| 6.7 Deployment, 5.6 scaling, 6.8 monitoring | **Documented** | `docs/DEPLOYMENT.md` |

## Still open (not built, and why)

| Item | Reason |
| --- | --- |
| Final-exam retake cooldown (default 24 h) and "topic breakdown + lessons to rewatch" after a failed exam | Both live inside Tutor's quiz engine; there is no stable Tutor hook to enforce a cooldown, and building on a guessed hook would break silently. Needs a decision on whether to configure Tutor's attempt limits or wrap the quiz. |
| Phone-OTP and Google sign-up | Identity plugin configuration (e.g. a WordPress social/OTP login plugin), not product code. OTP rate limiting (3 per 15 min per phone) should be configured in that plugin. |
| Drag-and-drop certificate template editor | Templates are HTML posts with `{{placeholders}}` (works, previewable). A visual editor is a phase-2 candidate. |
| Cloudflare, Redis, Sentry, backups, uptime checks | Infrastructure; see `docs/DEPLOYMENT.md`. |
| Figma prototype, user interviews (blueprint phase 0) | Not code. |
| Items the blueprint puts out of MVP (native app, SCORM, leaderboards, multisite white-label) | Deliberately skipped, unchanged. |

## What could not be verified here

The audit environment had no WordPress, MySQL or Tutor LMS, so the integration suites (`tests/integration/*`) and the new
`e2e-gaps.php` were not run locally; they run in `.github/workflows/integration.yml`. Verified locally: PHP lint of every file,
the 38 PHPUnit tests (including the Bangla-translation guards and the new checklist tests) and a JS syntax check.
