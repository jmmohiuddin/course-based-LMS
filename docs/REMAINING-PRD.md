# What is left from the PRD, and why

Status after plugin v0.4.0. Everything in the PRD that is code, and that the PRD does not defer or leave to a decision, is built.
The sections below are what remains. None of it is hidden or half-built.

## Needs people, not code (PRD phase 0)

| Item | State | Next step |
| --- | --- | --- |
| 15–20 interviews, 150+ survey responses, 5-learner usability test | The kit is ready: `docs/discovery/` | Recruit with `recruiting-tracker.csv`, run the sessions, fill `synthesis-template.md` |
| Figma prototype | A clickable HTML prototype exists (`docs/prototype/`) and is enough to test the player and MCQ sheet | A designer can rebuild it in Figma if the team wants a design file |
| Confirm the blueprint's assumptions (market, business model, payments) | Needs the founder | Decide, then update `docs/BLUEPRINT-AUDIT.md` |

## Needs infrastructure or accounts

| Item | State | Next step |
| --- | --- | --- |
| Servers, Cloudflare, backups, monitoring, deploy pipeline | Config, scripts and a workflow are in `ops/` and `.github/workflows/deploy.yml`, validated with linters and stubs only | Provision, set the secrets listed in `ops/README.md`, add required reviewers on the `production` environment, run the first deploy to staging |
| Payment gateways (SSLCommerz / bKash / Stripe, FR-10) | By design these are Tutor-compatible gateway plugins (blueprint 5.2); the plugin exposes `nimikh_subscription_paid` and `nimikh_lms_paid_enrolments` | Install and configure the gateways; then connect the paid-enrolment count to the Success metrics page |
| Real credentials for SMS, Google sign-in, Sentry | All three are built and tested against mocks | Add the credentials on staging and test once with a real phone and Google account |
| Tutor LMS compatibility | `tools/check-tutor-contract.php` and the real-Tutor CI job check every hook, table and column we rely on, including the exam-cooldown action names | Let CI run once against real Tutor and fix whatever it names |

## Deferred by the PRD itself (phase 3 and "out of MVP")

These need a product decision or a security design before anyone should build them.

| Item | Why it is not built yet |
| --- | --- |
| SCORM import | Serving third-party SCORM packages means running untrusted JavaScript. On the same origin as WordPress that is an XSS route to admin accounts, so it needs a separate sandbox domain first. Decide whether SCORM is really needed before paying for that design. |
| Offline video downloads | Conflicts with the PRD's own content-protection requirement (signed, expiring URLs and a watermark): an offline copy cannot expire. Needs a DRM decision (Bunny DRM is USD 99/month) before any design. |
| Native mobile app | The PRD says PWA first. The PWA is built (installable, offline-safe progress). A native app is only worth starting after the PWA's usage data says it is needed. |
| WordPress multisite white-label | Institute portals work inside one site today (branded pages, certificates, scoped admins). True per-institute sites are a hosting and operations change, to be taken once an institute actually needs full isolation. |

## Built in this round

- Opt-in course leaderboard (`[nimikh_leaderboard]`). The PRD lists gamification leaderboards as later phase. It is off for everyone by default, shows first name and initial only, and every learner can see their own private rank without joining.
