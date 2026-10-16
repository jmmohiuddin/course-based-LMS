/* Hosts a SCORM package in a sandboxed iframe. The package cannot reach this page; it can only post messages, and
 * this script accepts them only from that iframe's window, forwards a whitelist-checked copy to the server, and shows the result. */
(function () {
  'use strict';
  var cfg = window.NimikhScorm;
  if (!cfg) { return; }
  var T = cfg.i18n || {};

  function api(path, body) {
    return fetch(cfg.restUrl + path, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function el(tag, attrs) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); } });
    return n;
  }

  function mount(root) {
    var lessonId = parseInt(root.getAttribute('data-lesson'), 10);
    var status = el('p', { 'class': 'nk-scorm__status', role: 'status', text: T.loading });
    root.appendChild(status);
    var frame = null, done = false, queue = Promise.resolve();

    function say(msg, kind) { status.textContent = msg || ''; status.setAttribute('data-kind', kind || ''); }
    function result(r) {
      if (r.completed && !done) {
        done = true; say(T.completed, 'success');
        document.dispatchEvent(new CustomEvent('nimikh:lesson-complete', { detail: { lessonId: lessonId } }));
      } else if (!done) { say(T.saved, ''); }
    }
    function commit(data, finish) {
      // one request at a time, in order, so a slow response cannot overwrite a newer one
      queue = queue.then(function () { return api('/lessons/' + lessonId + '/scorm/commit', { data: data || {}, finish: !!finish }); }).then(result).catch(function () { say(T.error, 'error'); });
      return queue;
    }

    window.addEventListener('message', function (e) {
      var m = e.data;
      if (!frame || e.source !== frame.contentWindow || !m || m.nimikh !== 'scorm' || m.type !== 'commit') { return; }
      commit(m.data && typeof m.data === 'object' ? m.data : {}, m.finish);
    });

    api('/lessons/' + lessonId + '/scorm/launch').then(function (r) {
      // sandbox without allow-same-origin: the package gets an opaque origin and cannot touch this page, its cookies or the REST API
      frame = el('iframe', { 'class': 'nk-scorm__frame', src: r.url, title: r.title || T.title, sandbox: 'allow-scripts allow-forms allow-popups allow-modals', allow: 'fullscreen', referrerpolicy: 'no-referrer', loading: 'eager' });
      root.insertBefore(frame, status);
      done = !!r.completed;
      say(done ? T.completed : '', done ? 'success' : '');
      // re-check completion while the learner is still in the package: a package may report "completed" before the minimum time has passed
      setInterval(function () { if (!done && !document.hidden) { commit({}, false); } }, 15000);
    }).catch(function (e) { say((e && e.message) || T.error, 'error'); });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.nk-scorm'), mount);
})();
