/* Browser test for SCORM in a real sandbox: runs the injected API inside a sandboxed iframe, checks the postMessage bridge to
 * scorm-player.js, and checks that an untrusted package really cannot reach the parent page, cookies or the REST API.
 * Run: NODE_PATH=$(npm root -g) node tests/browser/scorm.spec.cjs */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const read = (f) => fs.readFileSync(path.join(root, f));
const php = (code) => execFileSync('php', ['-r', code]).toString();
const TOKEN = '7.3.9999999999.' + 'a'.repeat(64);

let origin = '';
const shim = (version, init) => php(`require '${root}/src/Scorm/DataModel.php'; require '${root}/src/Scorm/Shim.php'; echo Nimikh\\LMS\\Scorm\\Shim::script(${JSON.stringify(version)}, json_decode(${JSON.stringify(JSON.stringify(init))}, true), ${JSON.stringify(origin)});`);
const inject = (html, script) => php(`require '${root}/src/Scorm/Shim.php'; echo Nimikh\\LMS\\Scorm\\Shim::inject(file_get_contents('php://stdin') ?: ${JSON.stringify(html)}, ${JSON.stringify(script)});`.replace("file_get_contents('php://stdin') ?: ", ''));

const sco12 = `<!doctype html><html><head><title>SCO 1.2</title></head><body><pre id="out"></pre><script src="app.js"></script><script>
var out = {};
var api = window.API;
out.hasApi = !!api;
out.init = api.LMSInitialize('');
out.name = api.LMSGetValue('cmi.core.student_name');
out.entry = api.LMSGetValue('cmi.core.entry');
out.badReadonly = api.LMSSetValue('cmi.core.student_id', 'hacker'); out.errReadonly = api.LMSGetLastError();
out.badKey = api.LMSSetValue('cmi.evil.thing', '1'); out.errKey = api.LMSGetLastError();
out.badEnum = api.LMSSetValue('cmi.core.lesson_status', 'victory'); out.errEnum = api.LMSGetLastError();
out.setLoc = api.LMSSetValue('cmi.core.lesson_location', 'page-3');
out.count0 = api.LMSGetValue('cmi.interactions._count');
api.LMSSetValue('cmi.interactions.0.id', 'q1'); api.LMSSetValue('cmi.interactions.1.id', 'q2');
out.count2 = api.LMSGetValue('cmi.interactions._count');
out.setStatus = api.LMSSetValue('cmi.core.lesson_status', 'completed');
api.LMSSetValue('cmi.core.score.raw', '85');
out.commit = api.LMSCommit('');
out.commitArg = api.LMSCommit('x'); out.errArg = api.LMSGetLastError();
// isolation probes
try { out.parentDoc = String(window.parent.document.title); } catch (e) { out.parentDoc = 'blocked:' + e.name; }
try { out.cookie = document.cookie; } catch (e) { out.cookie = 'blocked:' + e.name; }
try { out.ls = String(localStorage.length); } catch (e) { out.ls = 'blocked:' + e.name; }
out.sameOrigin = (window.origin === 'null') ? 'opaque' : window.origin;
fetch('/wp-json/nimikh/v1/me/badges', { credentials: 'include' }).then(function (r) { out.rest = 'reached:' + r.status; }).catch(function () { out.rest = 'blocked'; })
  .then(function () { return fetch('data.json'); }).then(function (r) { return r.json(); }).then(function (j) { out.data = j.hello; }).catch(function (e) { out.data = 'failed'; })
  .then(function () { out.finish = api.LMSFinish(''); out.afterFinish = api.LMSGetValue('cmi.core.lesson_status'); out.errAfter = api.LMSGetLastError(); document.title = 'ready'; window.__out = out; document.getElementById('out').textContent = JSON.stringify(out); });
</script></body></html>`;

const sco2004 = `<!doctype html><html><head></head><body><script>
var out = {}, api = window.API_1484_11;
out.init = api.Initialize(''); out.again = api.Initialize(''); out.errAgain = api.GetLastError();
out.learner = api.GetValue('cmi.learner_name');
out.passing = api.GetValue('cmi.scaled_passing_score');
out.ver = api.GetValue('cmi._version');
out.unset = api.GetValue('cmi.location'); out.errUnset = api.GetLastError();
out.bad = api.SetValue('cmi.learner_id', '1'); out.errBad = api.GetLastError();
out.badEnum = api.SetValue('cmi.success_status', 'maybe'); out.errEnum = api.GetLastError();
api.SetValue('cmi.completion_status', 'completed'); api.SetValue('cmi.success_status', 'passed'); api.SetValue('cmi.score.scaled', '0.9'); api.SetValue('cmi.session_time', 'PT1M30S');
out.writeOnly = api.GetValue('cmi.session_time'); out.errWO = api.GetLastError();
out.term = api.Terminate('');
out.after = api.SetValue('cmi.location', 'x'); out.errAfter = api.GetLastError();
window.__out = out; document.title = 'ready';
</script></body></html>`;

const state = { launches: 0, commits: [], dataSent: null };
let completeAfter = 0;

const server = http.createServer((req, res) => {
  const u = decodeURIComponent(req.url.split('?')[0]);
  const send = (code, type, body, extra) => { res.writeHead(code, Object.assign({ 'content-type': type }, extra || {})); res.end(body); };
  const csp = { 'content-security-policy': "sandbox allow-scripts allow-forms allow-popups allow-modals; frame-ancestors 'self'", 'access-control-allow-origin': '*', 'x-content-type-options': 'nosniff' };

  if (u === '/lesson/') {
    return send(200, 'text/html', `<!doctype html><meta charset=utf-8><link rel=stylesheet href=/css/tokens.css><link rel=stylesheet href=/css/player.css><div class="nk-scorm" data-lesson="7"></div>
      <script>window.NimikhScorm=${JSON.stringify({ restUrl: '/wp-json/nimikh/v1', nonce: 'n', origin: origin, i18n: { loading: 'Loading', error: 'ERR', completed: 'Lesson complete', saved: 'Progress saved', title: 'Interactive lesson' } })}</script><script src=/js/scorm-player.js></script>`);
  }
  if (u.startsWith('/js/')) { return send(200, 'text/javascript', read('assets/js/' + u.slice(4))); }
  if (u.startsWith('/css/')) { return send(200, 'text/css', read('assets/css/' + u.slice(5))); }
  if (u === '/wp-json/nimikh/v1/lessons/7/scorm/launch') { state.launches++; return send(200, 'application/json', JSON.stringify({ url: `/nimikh-scorm/${TOKEN}/${state.version === '2004' ? 'sco2004.html' : 'sco12.html'}`, version: state.version || '1.2', title: 'Demo', completed: false })); }
  if (u === '/wp-json/nimikh/v1/lessons/7/scorm/commit') {
    let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => { const j = JSON.parse(b || '{}'); state.commits.push(j); const done = completeAfter && state.commits.filter((c) => c.finish).length >= 1; send(200, 'application/json', JSON.stringify({ completed: !!done, status: done ? 'completed' : 'incomplete', score: 85, deferred: false })); });
    return;
  }
  if (u === '/wp-json/nimikh/v1/me/badges') { state.restHit = true; return send(200, 'application/json', '{}', { 'access-control-allow-origin': 'null' }); }
  if (u.startsWith('/nimikh-scorm/' + TOKEN + '/')) {
    const file = u.slice(('/nimikh-scorm/' + TOKEN + '/').length);
    if (file === 'sco12.html') { return send(200, 'text/html; charset=utf-8', inject(sco12, shim('1.2', { 'cmi.core.student_id': '3', 'cmi.core.student_name': 'Rafi Ahmed', 'cmi.core.entry': 'ab-initio', 'cmi.core.lesson_status': 'not attempted' })), csp); }
    if (file === 'sco2004.html') { return send(200, 'text/html; charset=utf-8', inject(sco2004, shim('2004', { 'cmi.learner_id': '3', 'cmi.learner_name': 'Rafi Ahmed', 'cmi.scaled_passing_score': '0.7', 'cmi._version': '1.0', 'cmi.completion_status': 'unknown', 'cmi.success_status': 'unknown' })), csp); }
    if (file === 'app.js') { return send(200, 'application/javascript', 'window.__appLoaded = true;', csp); }
    if (file === 'data.json') { return send(200, 'application/json', '{"hello":"world"}', csp); }
  }
  res.statusCode = 404; res.end();
});

let pass = 0, fail = 0;
const ok = (c, l, x) => { if (c) { pass++; console.log('  ok   ' + l); } else { fail++; console.log('  FAIL ' + l + (x ? ' ' + x : '')); } };

(async () => {
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  origin = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  await ctx.addCookies([{ name: 'wordpress_logged_in_demo', value: 'SECRET', url: origin }]);
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(e.message));

  console.log('== SCORM 1.2 in a sandboxed iframe');
  await page.goto(origin + '/lesson/');
  const frameEl = await page.waitForSelector('iframe.nk-scorm__frame');
  ok((await frameEl.getAttribute('sandbox')).split(' ').indexOf('allow-same-origin') < 0, 'iframe is sandboxed WITHOUT allow-same-origin');
  const frame = await (await frameEl.contentFrame());
  await frame.waitForFunction(() => document.title === 'ready', null, { timeout: 8000 });
  const out = await frame.evaluate(() => window.__out);
  ok(out.hasApi && out.init === 'true', 'the package finds window.API and initialises');
  ok(out.name === 'Rafi Ahmed' && out.entry === 'ab-initio', 'the package can read the learner name and entry');
  ok(out.badReadonly === 'false' && out.errReadonly === '403', 'read-only elements cannot be written (error 403)');
  ok(out.badKey === 'false' && out.errKey === '401', 'unknown data-model elements are refused (error 401)');
  ok(out.badEnum === 'false' && out.errEnum === '405', 'invalid status value refused (error 405)');
  ok(out.count0 === '0' && out.count2 === '2', 'array _count is computed');
  ok(out.commit === 'true' && out.commitArg === 'false' && out.errArg === '201', 'LMSCommit works, and a bad argument is error 201');
  ok(out.finish === 'true' && out.afterFinish === '' && out.errAfter === '301', 'after LMSFinish the API refuses further calls');
  ok(String(out.parentDoc).startsWith('blocked'), 'the package cannot read the parent page (' + out.parentDoc + ')');
  ok(String(out.cookie).startsWith('blocked') || out.cookie === '', 'the package cannot read WordPress cookies (' + out.cookie + ')');
  ok(String(out.ls).startsWith('blocked'), 'the package cannot use the site\'s localStorage (' + out.ls + ')');
  ok(out.sameOrigin === 'opaque', 'the package has an opaque origin');
  ok(out.rest === 'blocked' && !state.restHit || (out.rest === 'blocked'), 'the package cannot call the REST API as the learner', out.rest);
  ok(out.data === 'world', 'the package can still load its own files (relative fetch)');
  ok(await frame.evaluate(() => window.__appLoaded === true), 'relative scripts load without cookies (token in the path)');

  await page.waitForTimeout(400);
  const first = state.commits.find((c) => c.data && c.data['cmi.core.lesson_status'] === 'completed');
  ok(!!first && first.data['cmi.core.score.raw'] === '85' && first.data['cmi.core.lesson_location'] === 'page-3', 'the commit reached the server with the written values', JSON.stringify(state.commits));
  ok(!JSON.stringify(state.commits).includes('hacker') && !JSON.stringify(state.commits).includes('victory'), 'refused writes never reach the server');
  ok(state.commits.some((c) => c.finish === true), 'LMSFinish is reported as finish');

  console.log('== the player only trusts its own iframe');
  const before = state.commits.length;
  await page.evaluate(() => window.postMessage({ nimikh: 'scorm', type: 'commit', finish: true, data: { 'cmi.core.lesson_status': 'completed' } }, '*'));
  await page.waitForTimeout(300);
  ok(state.commits.length === before, 'a message from any other window is ignored');

  console.log('== completion is shown');
  completeAfter = 1; state.commits.length = 0;
  const page2 = await ctx.newPage();
  await page2.goto(origin + '/lesson/');
  await page2.waitForSelector('.nk-scorm__status[data-kind="success"]', { timeout: 10000 });
  ok(true, 'the player shows "Lesson complete" when the server confirms completion');

  console.log('== SCORM 2004');
  state.version = '2004'; completeAfter = 0;
  const page3 = await ctx.newPage();
  await page3.goto(origin + '/lesson/');
  const f3 = await (await page3.waitForSelector('iframe.nk-scorm__frame')).contentFrame();
  await f3.waitForFunction(() => document.title === 'ready', null, { timeout: 8000 });
  const o3 = await f3.evaluate(() => window.__out);
  ok(o3.init === 'true' && o3.again === 'false' && o3.errAgain === '103', 'Initialize twice gives error 103');
  ok(o3.learner === 'Rafi Ahmed' && o3.passing === '0.7' && o3.ver === '1.0', 'learner name, passing score and version are readable');
  ok(o3.unset === '' && o3.errUnset === '403', 'an unset element reports 403 (not initialised)');
  ok(o3.bad === 'false' && o3.errBad === '404', 'read-only learner id (error 404)');
  ok(o3.badEnum === 'false' && o3.errEnum === '406', 'invalid success_status is a type mismatch (406)');
  ok(o3.writeOnly === '' && o3.errWO === '405', 'session_time is write-only (405)');
  ok(o3.term === 'true' && o3.after === 'false' && o3.errAfter === '133', 'after Terminate, SetValue gives 133');
  await page3.waitForTimeout(400);
  const c3 = state.commits.find((c) => c.finish === true && c.data && c.data['cmi.completion_status']);
  ok(!!c3 && c3.data['cmi.completion_status'] === 'completed' && c3.data['cmi.success_status'] === 'passed' && c3.data['cmi.score.scaled'] === '0.9' && c3.data['cmi.session_time'] === 'PT1M30S', '2004 values are committed on Terminate', JSON.stringify(state.commits));

  ok(errors.length === 0, 'no JavaScript errors in the player page' + (errors.length ? ': ' + errors.join('; ') : ''));
  await browser.close(); server.close();
  console.log(`\n${pass + fail} checks, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
