/* Browser tests: certificate layout editor (drag, keyboard, panel, save JSON -> PHP sanitiser -> HTML) and the phone-code sign-in form.
 * Run: NODE_PATH=$(npm root -g) node tests/browser/admin-auth.spec.cjs */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const read = (f) => fs.readFileSync(path.join(root, f));
const php = (code) => execFileSync('php', ['-r', code]).toString();
const defaultLayout = php(`require '${root}/src/Certificates/Layout.php'; echo json_encode(Nimikh\\LMS\\Certificates\\Layout::defaultLayout());`);

const cfg = {
  pageW: 297, pageH: 210,
  types: { name: 'Learner name', course: 'Course title', date: 'Issue date', score: 'Score', code: 'Certificate ID', url: 'Verify link', issuer: 'Issuer', text: 'Text', qr: 'QR code', logo: 'Logo', image: 'Image (signature)', line: 'Line' },
  sample: { name: 'Rafi Ahmed', course: 'Excel for Work', date: '7 Oct 2026', score: '91%', code: 'K7M2Q9XA4B', url: 'https://x/verify/K7M2Q9XA4B', issuer: 'Nimikh' },
  i18n: { add: 'Add', remove: 'Remove', reset: 'Reset', x: 'Left (mm)', y: 'Top (mm)', w: 'Width (mm)', size: 'Text size', color: 'Colour', brand: 'Use institute colour', align: 'Alignment', bold: 'Bold', text: 'Text', src: 'Image URL', background: 'Page colour', frame: 'Border width', left: 'Left', center: 'Centre', right: 'Right', select: 'Select an item', layers: 'Items' },
};
const esc = (s) => s.replace(/&/g, '&amp;').replace(/"/g, '&quot;');
const authCfg = { restUrl: '/wp-json/nimikh/v1', otp: true, google: '', redirect: '/done/', i18n: { phone: 'Mobile number', sendCode: 'Send code', code: '6-digit code', verify: 'Sign in', codeSent: 'We sent a code to your phone.', change: 'Use another number', or: 'or', error: 'Something went wrong.', heading: 'Sign in' } };

const calls = [];
const pages = {
  '/editor/': `<!doctype html><meta charset=utf-8><link rel=stylesheet href=/css/cert-editor.css><form onsubmit="return false"><div id="nk-certed" data-layout="${esc(defaultLayout)}" data-default="${esc(defaultLayout)}"></div><input type=hidden id=nk-layout-json></form>
    <script>window.NimikhCertEditor=${JSON.stringify(cfg)}</script><script src=/js/cert-editor.js></script>`,
  '/login/': `<!doctype html><meta charset=utf-8><link rel=stylesheet href=/css/tokens.css><link rel=stylesheet href=/css/player.css><section class="nk-auth"></section><script>window.NimikhAuth=${JSON.stringify(authCfg)}</script><script src=/js/auth.js></script>`,
  '/done/': '<!doctype html><h1 id=done>Signed in</h1>',
};

const server = http.createServer((req, res) => {
  const u = req.url.split('?')[0];
  if (pages[u]) { res.setHeader('content-type', 'text/html'); return res.end(pages[u]); }
  if (u.startsWith('/js/')) { res.setHeader('content-type', 'text/javascript'); return res.end(read('assets/js/' + u.slice(4))); }
  if (u.startsWith('/css/')) { res.setHeader('content-type', 'text/css'); return res.end(read('assets/css/' + u.slice(5))); }
  if (u.startsWith('/wp-json/nimikh/v1/auth/')) {
    let body = ''; req.on('data', (c) => (body += c)); req.on('end', () => {
      const j = JSON.parse(body || '{}'); calls.push([u, j]); res.setHeader('content-type', 'application/json');
      if (u.endsWith('/request')) { res.end(JSON.stringify({ sent: true, ttl: 300 })); }
      else if (j.code === '123456') { res.end(JSON.stringify({ ok: true, redirect: 'http://127.0.0.1:' + server.address().port + '/done/' })); }
      else { res.statusCode = 400; res.end(JSON.stringify({ code: 'nimikh_otp_invalid', message: 'That code is not valid.' })); }
    });
    return;
  }
  res.statusCode = 404; res.end();
});

let pass = 0, fail = 0;
const ok = (c, l) => { if (c) { pass++; console.log('  ok   ' + l); } else { fail++; console.log('  FAIL ' + l); } };

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
  const page = await browser.newPage({ viewport: { width: 1000, height: 800 } });
  const errors = []; page.on('pageerror', (e) => errors.push(e.message));

  console.log('== certificate editor');
  await page.goto(base + '/editor/');
  ok(await page.locator('.nk-certed__item').count() === 11, 'default layout draws 11 items');
  const layoutOf = async () => JSON.parse(await page.inputValue('#nk-layout-json'));
  const nameBefore = (await layoutOf()).elements.find((e) => e.type === 'name');
  const box = await page.locator('.nk-certed__item', { hasText: 'Rafi Ahmed' }).boundingBox();
  await page.mouse.move(box.x + 20, box.y + 10); await page.mouse.down(); await page.mouse.move(box.x + 20 + 60, box.y + 10 + 30, { steps: 5 }); await page.mouse.up();
  const nameAfter = (await layoutOf()).elements.find((e) => e.type === 'name');
  ok(nameAfter.x > nameBefore.x && nameAfter.y > nameBefore.y, 'dragging moves the item and updates the saved JSON (' + nameBefore.x + ',' + nameBefore.y + ' -> ' + nameAfter.x + ',' + nameAfter.y + ')');
  ok(await page.locator('#nk-ce-x').count() === 1, 'selecting shows the properties panel');

  await page.fill('#nk-ce-x', '50'); await page.fill('#nk-ce-y', '55');
  const typed = (await layoutOf()).elements.find((e) => e.type === 'name');
  ok(typed.x === 50 && typed.y === 55, 'typing positions in the panel works');

  await page.locator('.nk-certed__item', { hasText: 'Rafi Ahmed' }).focus();
  await page.keyboard.press('ArrowRight'); await page.keyboard.press('Shift+ArrowDown');
  const keyed = (await layoutOf()).elements.find((e) => e.type === 'name');
  ok(keyed.x === 51 && keyed.y === 60, 'arrow keys nudge by 1 mm, Shift by 5 mm');

  await page.click('button:has-text("Add Text")');
  await page.fill('#nk-ce-text', 'Signed by <b>Director</b>');
  const added = (await layoutOf()).elements.at(-1);
  ok(added.type === 'text' && added.text.includes('Director'), 'adding a text item and editing it');
  await page.click('button:has-text("Remove")');
  ok(await page.locator('.nk-certed__item').count() === 11, 'removing an item');

  await page.fill('#nk-ce-bg', '#fff8e1');
  ok((await layoutOf()).bg.toLowerCase() === '#fff8e1', 'page colour is saved');

  // The saved JSON goes through the PHP sanitiser and renderer.
  const html = php(`require '${root}/src/Certificates/Layout.php'; $l = Nimikh\\LMS\\Certificates\\Layout::sanitize(json_decode(file_get_contents('php://stdin') ?: '', true)); echo $l ? Nimikh\\LMS\\Certificates\\Layout::toHtml($l) : 'NULL';`.replace("file_get_contents('php://stdin') ?: ''", JSON.stringify(JSON.stringify(await layoutOf()))));
  ok(html.includes('{{name}}') && html.includes('left:51mm;top:60mm') && html.includes('background:#FFF8E1'), 'editor output renders through the PHP layout engine');

  const small = await browser.newPage({ viewport: { width: 380, height: 700 } });
  await small.goto(base + '/editor/');
  const w = await small.evaluate(() => document.querySelector('.nk-certed__wrap').getBoundingClientRect().width);
  ok(w <= 380, 'the editor fits a phone-width screen (' + Math.round(w) + 'px)');

  console.log('== phone sign-in form');
  const login = await browser.newPage({ viewport: { width: 360, height: 700 } });
  login.on('pageerror', (e) => errors.push(e.message));
  await login.goto(base + '/login/');
  ok(await login.locator('#nk-auth-code').isHidden(), 'code field is hidden until a code is requested');
  await login.fill('#nk-auth-phone', '01712345678'); await login.click('button:has-text("Send code")');
  await login.waitForSelector('#nk-auth-code', { state: 'visible' });
  ok(calls[0][0].endsWith('/otp/request') && calls[0][1].phone === '01712345678', 'requests a code for the typed number');
  ok((await login.textContent('.nk-auth__status')).includes('We sent a code'), 'tells the learner a code was sent');
  await login.fill('#nk-auth-code', '000000'); await login.click('button:has-text("Sign in")');
  await login.waitForFunction(() => document.querySelector('.nk-auth__status').textContent.includes('not valid'));
  ok(true, 'a wrong code shows the server message and stays on the form');
  await login.fill('#nk-auth-code', '123456'); await login.click('button:has-text("Sign in")');
  await login.waitForSelector('#done');
  ok(true, 'the right code redirects after sign-in');
  const sw = await login.evaluate(() => document.documentElement.scrollWidth);
  ok(sw <= 360, 'no horizontal scroll at 360 px');

  ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join('; ') : ''));
  await browser.close(); server.close();
  console.log(`\n${pass + fail} checks, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
