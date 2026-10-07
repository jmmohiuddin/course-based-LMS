/* Reports uncaught errors from Nimikh scripts to Sentry. No SDK, no cookies, no user data; at most 5 reports per page. */
(function () {
  'use strict';
  var cfg = window.NimikhErrors, sent = 0;
  if (!cfg || !cfg.endpoint) { return; }

  function id() { var a = new Uint8Array(16); (window.crypto || {}).getRandomValues && window.crypto.getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }

  function report(message, file, line) {
    if (sent >= 5 || !/nimikh-lms/.test(String(file || ''))) { return; } // only our own scripts
    sent += 1;
    var eid = id(), now = new Date().toISOString();
    var event = { event_id: eid, timestamp: now, platform: 'javascript', level: 'error', release: cfg.release, environment: cfg.environment,
      exception: { values: [{ type: 'Error', value: String(message).slice(0, 1000), stacktrace: { frames: [{ filename: String(file).replace(/^https?:\/\/[^/]+/, ''), lineno: line || 0 }] } }] } };
    var body = JSON.stringify({ event_id: eid, sent_at: now }) + '\n' + JSON.stringify({ type: 'event' }) + '\n' + JSON.stringify(event) + '\n';
    try { fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, mode: 'no-cors', credentials: 'omit' }); } catch (e) { /* reporting must never throw */ }
  }

  window.addEventListener('error', function (e) { report(e.message, e.filename, e.lineno); });
  window.addEventListener('unhandledrejection', function (e) { var r = e.reason || {}; report(r.message || r, (r.stack || '').split('\n').filter(function (l) { return /nimikh-lms/.test(l); })[0] || '', 0); });
})();
