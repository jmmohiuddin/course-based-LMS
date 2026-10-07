# Usability test plan: lesson player prototype

**Goal**: find where learners get stuck with in-video questions, the seek lock and the certificate flow, before building more. Prototype: `../prototype/index.html` (simulated video). Not a test of the learner's ability.

## Setup
- 5 learners (mix: 2 who finished an online course recently, 2 who started but quit, 1 low data-plan user). Recruit via `recruiting-tracker.csv`. 5 is for finding problems, not for statistics.
- Real Android phone (Chrome), ideally one low-end device, normal brightness. Prototype loaded from a local file or private URL, `?speed=1` (default). Test the Bangla version for at least 3 participants (after native-speaker review of strings).
- Screen recording only with consent. Facilitator + note-taker. 30 minutes each, plus 5 for consent and 5 for debrief.
- Pilot once with a colleague first. Use "Reset demo" between participants.

## Script (read aloud)
"We are testing the app, not you. There are no wrong answers. Please think aloud. I will not help unless you ask, and I may not answer." Then tasks. Do not explain the prototype's rules.

## Tasks, success criteria
| # | Task (as said to participant) | Success | Time box |
|---|---|---|---|
| 1 | "You want to continue your course. Open your lesson and start watching." | Reaches Player and presses Play unaided | 1 min |
| 2 | "Keep watching. A question will come up. Answer it." | Selects an option and submits without help; says what happened after | 2 min |
| 3 | "Answer the next one wrongly on purpose, then carry on." | Understands the feedback, retries or follows Rewind; no confusion about what to do next | 2 min |
| 4 | "You want to skip ahead to the end of the video." | Tries the seek bar; after the tooltip, explains why they cannot skip (in own words) | 1 min |
| 5 | "An optional question appears. What would you do?" | Finds Skip or Esc/ignores correctly; can tell it was optional | 1 min |
| 6 | "Your phone call interrupts. Close the page and come back." (reload) | Recognises where they resumed; says it is acceptable | 2 min |
| 7 | "You failed the exam. What would you do next?" | Finds the suggested lessons and uses Rewatch | 2 min |
| 8 | "Show your certificate to an employer so they can check it." | Finds the verify page; correctly reads Valid vs Revoked (both shown) | 3 min |
Target: tasks 1-6, 8 completed unaided by at least 4 of 5; any critical failure (cannot proceed) by 2+ participants is a must-fix.

## Debrief questions
1. What was easiest, what was most annoying? 2. Were the questions too frequent, too few, or about right? 3. How did you feel when you got one wrong? 4. Did the wording and colours make sense without reading carefully? 5. Would you pay for a course like this? How much, and how? (keep to what they say; do not lead) 6. SUS 10 questions (optional, English or Bangla).

## Observation sheet (one per participant)
Participant ID: ___ Date: ___ Device/OS/Browser: ___ Language: EN / BN Facilitator: ___ Note-taker: ___
| Task | Done unaided (Y/N/Help) | Time (s) | Errors / wrong taps | Hesitations, quotes | Severity 0-3 |
|---|---|---|---|---|---|
| 1 Start | | | | | |
| 2 Answer | | | | | |
| 3 Wrong + retry/rewind | | | | | |
| 4 Seek blocked | | | | | |
| 5 Optional skip | | | | | |
| 6 Reload resume | | | | | |
| 7 Exam fail | | | | | |
| 8 Verify | | | | | |
Also note: touch targets missed, text hard to read (outdoors/dark mode), Bangla wording confusion, anything the participant says unprompted. Severity: 0 none, 1 cosmetic, 2 slows them, 3 blocks them.

## Reporting
Per issue: description, tasks affected, participants hit (n of 5), severity, proposed fix. Use `synthesis-template.md`. Known items worth watching: Skip behaviour (prototype resumes playback, real player stays paused), dark-mode button contrast.
