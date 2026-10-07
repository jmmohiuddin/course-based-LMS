// Playwright flow test for docs/prototype/index.html.
// Run: NODE_PATH=$(npm root -g) node docs/prototype/test-flow.js   (uses the preinstalled Chromium; no downloads)
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const OUT = path.join(__dirname, 'screens');
fs.mkdirSync(OUT, { recursive: true });
const FILE = 'file://' + path.join(__dirname, 'index.html');
const results = [];
function check(name, cond, extra) { results.push([cond ? 'PASS' : 'FAIL', name + (extra ? ' (' + extra + ')' : '')]); console.log((cond ? 'PASS ' : 'FAIL ') + name + (extra ? ' (' + extra + ')' : '')); }

function findChrome() {
  const base = '/opt/pw-browsers';
  for (const d of fs.readdirSync(base).filter((n) => n.startsWith('chromium')).sort()) {
    const p = path.join(base, d, 'chrome-linux', 'chrome');
    if (fs.existsSync(p)) { return p; }
    if (fs.existsSync(path.join(base, d)) && fs.statSync(path.join(base, d)).isFile()) { return path.join(base, d); }
  }
  return path.join(base, 'chromium');
}

(async () => {
  const browser = await chromium.launch({ executablePath: findChrome(), args: ['--no-sandbox'] });
  const ctxOpts = { viewport: { width: 360, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
  let ctx = await browser.newContext(ctxOpts);
  const external = [];
  await ctx.route('**/*', (route) => { const u = route.request().url(); if (!u.startsWith('file://') && !u.startsWith('data:')) { external.push(u); } route.continue(); });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
  const shot = (n) => page.screenshot({ path: path.join(OUT, n + '.png') });
  const scrollW = () => page.evaluate(() => document.documentElement.scrollWidth);
  const sheet = () => page.locator('#sheet');

  await page.goto(FILE + '?speed=5#home');
  check('home loads, no horizontal scroll at 360px', (await scrollW()) <= 360, 'scrollWidth=' + (await scrollW()));
  await shot('01-home');
  await page.click('[data-go=course]'); await shot('02-course');
  await page.click('[data-go=player]');
  check('player screen visible', await page.locator('#s-player').isVisible());
  await shot('03-player-start');

  // Seek bar cannot pass the unanswered required question (blocked at t=0, gate=12)
  await page.locator('#seek').evaluate((el) => { el.value = 55; el.dispatchEvent(new Event('input', { bubbles: true })); });
  check('seek past required question shows tooltip', (await page.locator('#tip').innerText()) === 'Answer the question to continue');
  check('seek position unchanged', (await page.locator('#seek').inputValue()) === '0');
  await shot('04-seek-blocked-tooltip');

  // Play -> Q1 (required) pauses at 0:12
  await page.click('#btn-play');
  await sheet().waitFor({ timeout: 8000 });
  check('dialog opens with role=dialog + aria-modal', (await sheet().getAttribute('aria-modal')) === 'true');
  check('focus moved into dialog (first radio)', await page.evaluate(() => document.activeElement.type === 'radio' && !!document.activeElement.closest('#sheet')));
  check('video paused at 0:12', (await page.locator('#time').innerText()).startsWith('0:12'));
  check('options are real radios (4)', (await page.locator('#sheet input[type=radio]').count()) === 4);
  const box = await page.locator('#sheet .opt').first().boundingBox();
  check('option target >= 44px high', box.height >= 44, box.height + 'px');
  check('page behind dialog is inert', await page.evaluate(() => document.getElementById('page').inert));
  check('required question has no Skip', (await page.locator('#q-skip').count()) === 0);
  await page.keyboard.press('Escape');
  check('Esc does NOT close required question', await sheet().isVisible());
  await shot('05-mcq-open');
  // Wrong answer -> retry
  await page.locator('#sheet .opt').nth(0).click();
  await page.click('#q-submit');
  check('wrong shows icon + text + feedback', (await page.locator('.opt[data-state=wrong] .mark').innerText()).includes('Wrong') && (await page.locator('#q-fb').innerText()).includes('Try again'));
  check('retry: radios re-enabled and cleared', (await page.locator('#sheet input:checked').count()) === 0 && !(await page.locator('#sheet input').first().isDisabled()));
  await shot('06-mcq-wrong-retry');
  // Correct answer -> explanation + auto continue after 3 s
  await page.locator('#sheet .opt').nth(1).click();
  await page.click('#q-submit');
  check('correct shows explanation', (await page.locator('#q-fb').innerText()).includes('A dot selects by class'));
  check('correct shows icon+text mark', (await page.locator('.opt[data-state=correct] .mark').innerText()).includes('Correct'));
  await shot('07-mcq-correct');
  const t0 = Date.now();
  await sheet().waitFor({ state: 'detached', timeout: 6000 });
  const dt = Date.now() - t0;
  check('auto-continue closes sheet in ~3s', dt > 1500 && dt < 4500, dt + 'ms');

  // Q2 optional at 0:30 -> Esc closes (skip)
  await sheet().waitFor({ timeout: 8000 });
  check('optional question shows Skip', (await page.locator('#q-skip').count()) === 1);
  await shot('08-mcq-optional');
  await page.keyboard.press('Escape');
  check('Esc closes optional question', !(await sheet().count()));
  check('video resumes after skip', await page.evaluate(() => document.getElementById('btn-play').textContent.trim()) === 'Pause');

  // Q3 required with rewind at 0:48: wrong -> rewind jump
  await sheet().waitFor({ timeout: 8000 });
  await page.locator('#sheet .opt').nth(1).click(); await page.click('#q-submit');
  check('wrong + rewind offers Rewind button', (await page.locator('#q-rewind').count()) === 1);
  await shot('09-mcq-wrong-rewind');
  await page.click('#q-rewind');
  check('rewind jumps to 0:36 and plays', (await page.locator('#time').innerText()).startsWith('0:36'));
  // Seek bar: required question 0:48 unresolved -> cannot seek past it
  await page.locator('#seek').evaluate((el) => { el.value = 59; el.dispatchEvent(new Event('input', { bubbles: true })); });
  check('seek blocked past unanswered Q3', (await page.locator('#tip').innerText()) === 'Answer the question to continue');
  await sheet().waitFor({ timeout: 10000 });
  await page.locator('#sheet .opt').nth(1).click(); await page.click('#q-submit');
  check('2nd wrong shows correct answer then Continue', (await page.locator('#q-fb').innerText()).includes('The answer is') && (await page.locator('#q-cont').count()) === 1);
  await shot('10-mcq-answer-revealed');
  await page.click('#q-cont');

  // End -> next-lesson 5 s countdown
  await page.waitForSelector('#nextbar:not([hidden])', { timeout: 10000 });
  check('next-lesson countdown visible', (await page.locator('#next-txt').innerText()).startsWith('Next lesson in'));
  await shot('11-next-countdown');
  await page.waitForTimeout(5600);
  check('auto-advanced to Lesson 4', (await page.locator('#pl-title').innerText()).includes('Lesson 4'));

  // Reload mid-question resumes 5s before
  await page.click('#btn-play');
  await sheet().waitFor({ timeout: 8000 });
  await page.reload();
  check('reload lands on player', await page.locator('#s-player').isVisible());
  check('reload resumes 5s before question (0:15)', (await page.locator('#time').innerText()).startsWith('0:15'), await page.locator('#time').innerText());
  check('resume notice shown', (await page.locator('#status').innerText()).includes('5 seconds'));
  check('no dialog immediately after reload (paused)', !(await sheet().count()));
  await shot('12-resumed-after-reload');
  await page.click('#btn-play');
  await sheet().waitFor({ timeout: 8000 });
  await page.locator('#sheet .opt').nth(2).click(); await page.click('#q-submit');
  await page.click('#q-cont');
  await page.waitForSelector('#nextbar:not([hidden])', { timeout: 10000 });
  check('last lesson counts down to exam', (await page.locator('#next-txt').innerText()).startsWith('Exam in'));
  await page.click('#next-stay');
  check('Stay here cancels countdown', await page.locator('#nextbar').isHidden());
  await page.click('[data-go=exam]');
  await shot('13-exam-fail');
  check('fail shows suggested lessons to rewatch', (await page.locator('[data-rw]').count()) === 2);
  check('exam: no horizontal scroll', (await scrollW()) <= 360);
  await page.click('[data-rw="0:36"]');
  check('rewatch jumps to lesson 3 at 0:36', (await page.locator('#pl-title').innerText()).includes('Lesson 3') && (await page.locator('#time').innerText()).startsWith('0:36'));
  await page.click('[data-go=exam]');
  await page.click('#seg-pass'); await shot('14-exam-pass');
  await page.click('#exam-cert');
  await page.waitForTimeout(1500);
  check('confetti canvas animating (motion allowed)', await page.locator('#confetti').isVisible());
  await shot('15-certificate-confetti');
  await page.waitForTimeout(4500);
  check('confetti stops by itself', await page.locator('#confetti').isHidden());
  await shot('16-certificate');
  await page.click('#cert-verify'); await shot('17-verify-valid');
  check('valid page has icon + text', (await page.locator('#s-valid .vbadge').innerText()).includes('Valid'));
  await page.click('[data-go=revoked]'); await shot('18-verify-revoked');
  check('revoked page has icon + text', (await page.locator('#s-revoked .vbadge').innerText()).includes('Revoked'));

  // Bangla
  await page.click('#btn-lang');
  check('Bangla toggle sets lang=bn', (await page.evaluate(() => document.documentElement.lang)) === 'bn');
  check('Bangla font stack has no web font', await page.evaluate(() => /Hind Siliguri/.test(getComputedStyle(document.body).fontFamily) && !document.fonts.size));
  await page.click('[data-go=course]'); await shot('19-course-bangla');
  // Dark theme + English MCQ
  await page.click('#btn-theme'); await page.click('#btn-theme'); // auto -> light -> dark
  check('theme toggle sets dark', (await page.getAttribute('html', 'data-theme')) === 'dark');
  await page.click('#btn-lang'); // English
  await page.click('#btn-reset'); await page.click('[data-go=player]');
  await page.click('#btn-play');
  await sheet().waitFor({ timeout: 8000 });
  await shot('20-mcq-dark');
  await page.locator('#sheet .opt').nth(0).click(); await page.click('#q-submit');
  await shot('21-mcq-wrong-dark');
  for (let i = 0; i < 5; i++) { await page.keyboard.press('Tab'); }
  check('Tab stays trapped inside dialog', await page.evaluate(() => !!document.activeElement.closest('#sheet')));
  await page.keyboard.press('Shift+Tab');
  check('Shift+Tab stays trapped inside dialog', await page.evaluate(() => !!document.activeElement.closest('#sheet')));
  // Bangla MCQ (language toggle is behind the modal, so switch before opening)
  await page.locator('#sheet .opt').nth(1).click(); await page.click('#q-submit'); await page.click('#q-cont');
  await page.click('#btn-play'); // pause
  await page.click('#btn-lang'); // Bangla
  await page.click('#btn-reset'); await page.click('[data-go=player]'); await page.click('#btn-play');
  await sheet().waitFor({ timeout: 8000 });
  await shot('22-mcq-bangla-dark');
  check('Bangla MCQ stem rendered', (await page.locator('#q-stem').innerText()).includes('ক্লাস'));
  check('Bangla: no horizontal scroll', (await scrollW()) <= 360);

  check('no external requests (offline-safe)', external.length === 0, external.join(', '));
  check('no page errors', errors.length === 0, errors.join(' | '));
  await ctx.close();

  // Reduced motion: no confetti canvas, static decoration instead; sheet not animated
  ctx = await browser.newContext({ ...ctxOpts, reducedMotion: 'reduce' });
  const p2 = await ctx.newPage();
  await p2.goto(FILE + '#cert');
  await p2.waitForTimeout(800);
  check('reduced motion: no confetti canvas', await p2.locator('#confetti').isHidden());
  check('reduced motion: static decoration shown', await p2.locator('#confetti-static').isVisible());
  await p2.screenshot({ path: path.join(OUT, '23-certificate-reduced-motion.png') });
  await ctx.close();

  // localStorage blocked: must still work
  ctx = await browser.newContext(ctxOpts);
  await ctx.addInitScript(() => { Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } }); });
  const p3 = await ctx.newPage(); const e3 = []; p3.on('pageerror', (e) => e3.push(e.message));
  await p3.goto(FILE + '?speed=5#player'); await p3.click('#btn-play'); await p3.locator('#sheet').waitFor({ timeout: 8000 });
  check('works with localStorage blocked', e3.length === 0, e3.join('|'));
  await ctx.close();

  // Dark OS preference, no manual theme
  ctx = await browser.newContext({ ...ctxOpts, colorScheme: 'dark' });
  const p4 = await ctx.newPage(); await p4.goto(FILE + '#home');
  check('prefers-color-scheme dark applies tokens', (await p4.evaluate(() => getComputedStyle(document.body).backgroundColor)) === 'rgb(11, 17, 32)');
  await p4.screenshot({ path: path.join(OUT, '24-home-dark.png') });
  await ctx.close();

  await browser.close();
  const failed = results.filter((r) => r[0] === 'FAIL');
  console.log('\n' + (results.length - failed.length) + '/' + results.length + ' checks passed');
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
