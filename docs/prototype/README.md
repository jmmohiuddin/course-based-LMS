# Clickable prototype (lesson player + MCQ bottom sheet)

Open `index.html` in any browser (works offline from `file://`; one file, inline CSS/JS, no external requests, no web fonts).
Best viewed at 360 px width (phone, or browser devtools). It is a design/validation aid for blueprint sections 3.4, 7.4, 8 and 9, not product code. Nothing under `nimikh-lms/` was changed.

## What it simulates
- **Screen switcher** (top tabs): Home, Course, Player, Exam result, Certificate, Verify: Valid, Verify: Revoked. Plus Theme (Auto/Light/Dark), Bangla/English, Reset demo.
- **Player**: a fake clock instead of video (60 s lesson 3, 40 s lesson 4). Questions: Q1 at 0:12 required, Q2 at 0:30 optional, Q3 at 0:48 required with rewind point 0:36 (lesson 4 has one required question at 0:20).
  - Playback pauses at each question; the bottom sheet is a real `role="dialog" aria-modal`, focus moves to the first radio, Tab is trapped, the page behind is `inert`. **Esc closes optional questions only.**
  - Options are real radio buttons (48 px rows); correct/wrong shown with icon + word + border, not colour alone.
  - Correct: explanation, then Continue (auto after 3 s). Wrong: retry, max 2 attempts, then the correct answer is shown. Wrong with a rewind point: "Rewind and rewatch" jumps back to 0:36; the question is asked again on the way through.
  - Seek bar cannot pass an unanswered required question; tooltip "Answer the question to continue".
  - Close tab / reload while a question is open: resumes 5 s before it (state in `localStorage`, wrapped in try/catch, falls back to memory).
  - End of lesson: 5 s "Next lesson" countdown with Go now / Stay here (last lesson counts down to the exam).
- **Exam result**: Fail (58%) with suggested lessons to rewatch (jump into the player); Pass (82%) leads to the certificate.
- **Certificate**: calm confetti for about 4.5 s; with `prefers-reduced-motion: reduce` there is no animation, only a static decoration.
- **Verify**: public page, Valid and Revoked states (icon + text).
- Colours are copied from `nimikh-lms/assets/css/tokens.css` (light and dark). Font stack is `'Hind Siliguri', 'Inter', system-ui, ... 'Noto Sans Bengali'`; it is only used if installed on the device (no font request is made).

## Test hooks
`index.html?speed=5#player` runs the fake video clock 5x faster (UI timers such as the 3 s auto-continue and the 5 s countdown stay real-time). The URL hash selects the screen.

## Automated check
```
NODE_PATH=$(npm root -g) node docs/prototype/test-flow.js
```
Uses the preinstalled Chromium at 360x740, blocks and records any non-file request, writes screenshots to `screens/` (24 images) and exits non-zero on failure. Last run: 52 of 52 checks passed.

## Differences from the real player (`nimikh-lms/assets/js/player.js`) and findings
- Dark mode: the real `.nk-btn` uses white text on `--nk-primary`; in dark mode that colour is `#6E93FF`, which gives low contrast (about 2.9:1). The prototype uses `--nk-on-primary` (`#0B1120` in dark). Suggest the same fix in `player.css`.
- Real player: Skip on an optional question leaves the video paused. The prototype resumes playback; check this in usability testing.
- Real player keeps the player clickable (native video controls); here the seek bar is a styled range input with markers beneath it.
- Rewatch/attempt counts are modelled locally; in the real product the server owns them.
- Prices, names, scores and certificate IDs are sample data. The price/payment chips are placeholders for the interview questions, not validated figures.
- Bangla strings were drafted by the author and need a native-speaker review before any test with learners.
