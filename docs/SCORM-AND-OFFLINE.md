# SCORM import and offline downloads

Both features are built into the `nimikh-lms` plugin (v0.5.0). This page is for the people who run the site: what each one does,
how it is protected, and what it cannot do.

## SCORM 1.2 / SCORM 2004

**Use it:** edit a lesson → *SCORM package* box → upload the `.zip`. The lesson then shows the package instead of the video player.
The package reports completion and score; completing it completes the lesson in Tutor LMS and feeds the same certificate rules.

### Why it is safe to run someone else's JavaScript

A SCORM package is third-party code. Three layers keep it away from the site:

1. **Validated upload.** Allowlist of file types (no PHP, executables, `.htaccess`, dotfiles), path-traversal and symlink checks,
   file-count / size / compression-ratio limits (300 MB total, 150 MB per file, ratio 200), and the manifest is parsed with no
   entity loading. The folder is never served directly (`.htaccess` deny, plus the nginx rule in `ops/nginx/nimikh.conf`).
2. **Sandboxed delivery.** Files come from `/nimikh-scorm/{signed token}/…` with a CSP `sandbox` header and the iframe is created
   without `allow-same-origin`, so every page has an opaque origin: it cannot read WordPress cookies or `localStorage`, touch the
   parent page, or call the REST API as the learner. This is checked in a real browser (`tests/browser/scorm.spec.cjs`).
3. **A narrow channel back.** The SCORM API is injected into each page and talks to the player only through `postMessage`. The
   player accepts messages from its own iframe only, and the server stores only whitelisted data-model keys (size-limited).

### Limits you should know about

- **Completion is self-reported.** The package says it is complete; a determined learner can fake that. Mitigation: completion is
  ignored until a minimum time after launch (Settings → *SCORM: least seconds…*, default 20). For content that must be
  tamper-resistant use the interactive video, which is graded on the server.
- Framesets where the API lives in a different frame from the content are not supported (each page gets its own API copy).
- SCORM 1.2 and 2004 only (no xAPI/cmi5/AICC). Sequencing rules in 2004 manifests are not interpreted; the first launchable item runs.
- Single launch file: multi-SCO packages open their first SCO.
- Needs the PHP `zip` extension. Large uploads are limited by `upload_max_filesize` / `post_max_size`.

## Offline downloads

**Use it:** edit a lesson → *Interactive video* box → *Let learners download this lesson for offline viewing*. Learners then see a
**Download for offline** button under the player, and `[nimikh_downloads]` lists what is on their device.

**Requirements:** the lesson needs a single downloadable file: Bunny's **MP4 fallback** (enable it on the library; Settings picks the
resolution, default 480p) or a direct `.mp4`/`.webm` URL. HLS-only lessons are not offered. The video host must send CORS headers
that allow your site (Bunny pull zone → CORS). The installed app / service worker must be active (Settings → *Installable app*).

**What learners get:** the video, the lesson page and the lesson data are saved on the device. It plays with no connection,
including seeking. In-video questions still appear; answers given offline are saved on the device and graded by the server when
the learner is back online (offline there is no way to show right/wrong, because answers are never sent to the browser).
Watch progress syncs the same way it already did.

**Controls (Settings):** days a copy stays playable (7), lessons per learner (10), devices per lesson (2). A copy stops working after
expiry (the service worker deletes it), when the learner removes it, when enrolment/subscription ends (revoked at the next online
visit), or when the server no longer lists it.

### The honest limit: this is not DRM

The file sits in the learner's browser storage. While a licence is valid a determined person can copy it. What the feature does give
you: opt-in per lesson, fixed lifetime, device and lesson caps, revocation, and the moving watermark (learner email/phone) staying on
screen. If you need real protection, use Bunny DRM (about USD 99/month) and do not enable offline downloads for those lessons.

On a shared computer the copies stay until they expire or the learner removes them, even after logging out; tell learners to use
*Remove download* on shared devices.

## Tests

`tests/Unit/ScormTest.php`, `ScormPackageTest.php` (real zips, hostile packages), `OfflinePolicyTest.php`;
`tests/browser/scorm.spec.cjs` (sandbox isolation, SCORM 1.2/2004 conformance, postMessage bridge) and
`tests/browser/offline.spec.cjs` (real service worker, Cache Storage and the browser's offline mode); integration checks in
`tests/integration/e2e-gaps.php`.
