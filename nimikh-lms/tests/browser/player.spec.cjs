/* Browser test for assets/js/player.js: real Chromium, real <video>, mocked REST contract.
 * Run: node tests/browser/player.spec.cjs   (needs ffmpeg and a global/local `playwright`) */
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '../..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'nk-player-'));
const videoPath = path.join(tmp, 'v.webm');
execFileSync('ffmpeg', ['-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=15:duration=30', '-c:v', 'libvpx', '-b:v', '200k', '-an', videoPath]);

// ---- mock of the nimikh/v1 contract the player depends on --------------------
const state = { beats: [], attempts: [], resolved: false, tries: 0 };
const lesson = {
  lesson_id: 7, title: 'Demo', duration: 30,
  source: { type: 'mp4', url: '/v.webm', expires: 0 },
  captions: [], resume_second: 0, furthest: 0, percent: 0, completed: false, gate: 8, watermark: 'rafi@example.com',
  settings: { max_rate: 2, heartbeat_interval: 10 },
  interactions: [
    { id: 1, at_second: 8, required: true, allow_retry: true, points: 1, rewind_to_second: 2, resolved: false,
      question: { stem: 'What does SUM do?', options: ['Adds', 'Multiplies', 'Counts'], lang: 'en' } },
    { id: 2, at_second: 20, required: false, allow_retry: true, points: 1, rewind_to_second: null, resolved: false,
      question: { stem: 'Optional question?', options: ['Yes', 'No'], lang: 'en' } },
  ],
};
const page = `<!doctype html><meta charset=utf-8><link rel=stylesheet href=/player.css>
<div class="nk-player" data-lesson="7" data-mode="learner"></div>
<script>window.NimikhPlayer=${JSON.stringify({ restUrl: '/api', nonce: 'n', hlsSrc: '', i18n: {
  quickCheck: 'Quick check!', continue: 'Continue', submit: 'Check answer', skip: 'Skip', correct: 'Correct', tryAgain: 'Not quite. Have another go.',
  rewind: "Let's watch that part again", answerIs: 'The correct answer is highlighted.', mustAnswer: 'Answer the question to continue',
  speedCapped: 'Playback speed is limited for this course', completed: 'Lesson complete', loadError: 'err', saving: 'saved' } })}</script>
<script src=/player.js></script>`;

const server = http.createServer((req, res) => {
  const send = (code, type, body) => { res.writeHead(code, { 'Content-Type': type }); res.end(body); };
  const json = (o) => send(200, 'application/json', JSON.stringify(o));
  if (req.url === '/') return send(200, 'text/html', page);
  if (req.url === '/player.js') return send(200, 'text/javascript', fs.readFileSync(path.join(root, 'assets/js/player.js')));
  if (req.url === '/player.css') return send(200, 'text/css', fs.readFileSync(path.join(root, 'assets/css/tokens.css')) + fs.readFileSync(path.join(root, 'assets/css/player.css')));
  if (req.url === '/v.webm') {
    const buf = fs.readFileSync(videoPath); const range = req.headers.range;
    if (range) {
      const [s, e] = range.replace('bytes=', '').split('-'); const start = +s; const end = e ? +e : buf.length - 1;
      res.writeHead(206, { 'Content-Type': 'video/webm', 'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${start}-${end}/${buf.length}`, 'Content-Length': end - start + 1 });
      return res.end(buf.subarray(start, end + 1));
    }
    res.writeHead(200, { 'Content-Type': 'video/webm', 'Accept-Ranges': 'bytes', 'Content-Length': buf.length }); return res.end(buf);
  }
  let body = ''; req.on('data', (c) => (body += c)); req.on('end', () => {
    const data = body ? JSON.parse(body) : {};
    if (req.url === '/api/lessons/7/player') return json(lesson);
    if (req.url.startsWith('/api/lessons/7/heartbeat')) { state.beats.push(data); return json({ ok: true, rejected: false, furthest: 8, percent: 0, completed: false, gate: lesson.interactions[0].resolved ? null : 8 }); }
    if (req.url === '/api/interactions/1/attempt') {
      state.attempts.push(data.selected_index); state.tries++;
      if (data.selected_index === 0) { lesson.interactions[0].resolved = true; return json({ correct: true, resolved: true, tries_left: 0, explanation: 'SUM adds numbers.', lesson_completed: false }); }
      if (state.tries >= 2) { lesson.interactions[0].resolved = true; return json({ correct: false, resolved: true, correct_index: 0, explanation: 'SUM adds numbers.', tries_left: 0 }); }
      return json({ correct: false, resolved: false, tries_left: 1, rewind_to: 2 });
    }
    send(404, 'application/json', '{}');
  });
});

(async () => {
  let chromium; try { ({ chromium } = require('playwright')); } catch { ({ chromium } = require(path.join(execFileSync('npm', ['root', '-g']).toString().trim(), 'playwright'))); }
  await new Promise((r) => server.listen(0, r));
  const url = `http://127.0.0.1:${server.address().port}/`;
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--autoplay-policy=no-user-gesture-required', '--no-sandbox'] });
  const pg = await browser.newPage({ viewport: { width: 390, height: 800 } });
  let failed = 0; const check = (c, l, x = '') => { console.log(`  ${c ? 'ok  ' : 'FAIL'} ${l} ${c ? '' : x}`); if (!c) failed++; };
  pg.on('pageerror', (e) => { console.log('  PAGE ERROR', e.message); failed++; });

  await pg.goto(url);
  await pg.waitForSelector('.nk-player video');
  await pg.waitForFunction(() => document.querySelector('video').readyState >= 1);
  check(await pg.locator('.nk-marker').count() === 2, 'two question markers drawn on the timeline');
  check((await pg.locator('.nk-player__watermark').innerText()) === 'rafi@example.com', 'watermark shows learner identity');

  // Speed cap
  await pg.evaluate(() => { const v = document.querySelector('video'); v.playbackRate = 4; });
  await pg.waitForTimeout(200);
  check(await pg.evaluate(() => document.querySelector('video').playbackRate) <= 2, 'playback speed clamped to the cap');

  // Anti-skip: seek past the unanswered required question at 8 s
  await pg.evaluate(() => { const v = document.querySelector('video'); v.currentTime = 25; });
  await pg.waitForTimeout(500);
  const afterSeek = await pg.evaluate(() => document.querySelector('video').currentTime);
  check(afterSeek <= 9, 'seeking past a required question is blocked', `currentTime=${afterSeek}`);
  check((await pg.locator('.nk-player__status').innerText()).includes('Answer the question'), 'learner told to answer the question');

  // MCQ pops up at 8 s and pauses the video
  await pg.evaluate(() => { const v = document.querySelector('video'); v.currentTime = 6; v.playbackRate = 2; v.play(); });
  await pg.waitForSelector('[role=dialog]', { timeout: 10000 });
  check(await pg.evaluate(() => document.querySelector('video').paused), 'video paused when the question appears');
  check((await pg.locator('.nk-sheet__stem').innerText()) === 'What does SUM do?', 'question text shown');
  check((await pg.locator('input[type=radio]').count()) === 3, 'three radio options');
  check(!(await pg.content()).includes('SUM adds numbers'), 'explanation not in the DOM before answering');
  check(await pg.evaluate(() => document.activeElement && document.activeElement.type === 'radio'), 'focus moved into the dialog');

  // Wrong answer -> rewind
  await pg.locator('label.nk-option').nth(1).click();
  await pg.getByRole('button', { name: 'Check answer' }).click();
  await pg.waitForSelector('.nk-feedback[data-kind=wrong]');
  check((await pg.locator('.nk-feedback').innerText()).includes("Let's watch that part again"), 'wrong answer offers rewind');
  await pg.getByRole('button', { name: "Let's watch that part again" }).click();
  await pg.waitForTimeout(400);
  const rewound = await pg.evaluate(() => document.querySelector('video').currentTime);
  check(rewound < 6, 'video rewound to the segment', `currentTime=${rewound}`);
  check((await pg.locator('[role=dialog]').count()) === 0, 'dialog closed after rewind');

  // Question returns; correct answer resolves it
  await pg.waitForSelector('[role=dialog]', { timeout: 15000 });
  await pg.locator('label.nk-option').nth(0).click();
  await pg.getByRole('button', { name: 'Check answer' }).click();
  await pg.waitForSelector('.nk-feedback[data-kind=correct]');
  check((await pg.locator('.nk-feedback').innerText()).includes('SUM adds numbers.'), 'correct answer reveals the explanation');
  await pg.getByRole('button', { name: 'Continue' }).click();
  await pg.waitForTimeout(500);
  check((await pg.locator('[role=dialog]').count()) === 0 && !(await pg.evaluate(() => document.querySelector('video').paused)), 'playback resumes after answering');
  check(await pg.locator('.nk-marker[data-state=answered]').count() === 1, 'marker turns to answered');

  // Learner can now seek forward to the optional question's region (gate cleared client-side)
  await pg.evaluate(() => { const v = document.querySelector('video'); v.pause(); });
  await pg.waitForTimeout(300);
  check(state.beats.length > 0 && Array.isArray(state.beats.at(-1).ranges), 'heartbeats sent with watched ranges', JSON.stringify(state.beats.at(-1)));
  check(state.attempts.join() === '1,0', 'attempts posted to the server', state.attempts.join());

  // Optional question can be skipped with Escape
  await pg.evaluate(() => { const v = document.querySelector('video'); v.currentTime = 19; v.play(); });
  await pg.waitForSelector('[role=dialog]', { timeout: 10000 });
  check(await pg.getByRole('button', { name: 'Skip' }).count() === 1, 'optional question has a Skip button');
  await pg.keyboard.press('Escape');
  await pg.waitForTimeout(300);
  check((await pg.locator('[role=dialog]').count()) === 0, 'Escape dismisses an optional question');

  console.log(failed ? `\n${failed} failed` : '\nall browser checks passed');
  await browser.close(); server.close(); fs.rmSync(tmp, { recursive: true, force: true });
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
