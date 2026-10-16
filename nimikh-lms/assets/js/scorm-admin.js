/* Upload / remove a SCORM package from the lesson screen. The server validates everything; this only sends the file. */
(function () {
  'use strict';
  var cfg = window.NimikhScormAdmin, box = document.getElementById('nk-scorm-admin');
  if (!cfg || !box) { return; }
  var T = cfg.i18n, lesson = box.getAttribute('data-lesson');
  var msg = document.getElementById('nk-scorm-msg');
  function say(t, bad) { msg.textContent = t; msg.style.color = bad ? '#B91C1C' : ''; }
  function call(method, body) {
    return fetch(cfg.restUrl + '/lessons/' + lesson + '/scorm', { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: body })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  var up = document.getElementById('nk-scorm-upload'), rm = document.getElementById('nk-scorm-remove');
  up.addEventListener('click', function () {
    var f = document.getElementById('nk-scorm-file').files[0];
    if (!f) { say(T.choose, true); return; }
    var fd = new FormData(); fd.append('package', f);
    say(T.uploading); up.disabled = true;
    call('POST', fd).then(function () { say(T.done); window.location.reload(); })
      .catch(function (e) { say((e && e.message) || T.error, true); up.disabled = false; });
  });
  if (rm) {
    rm.addEventListener('click', function () {
      if (!window.confirm(T.confirm)) { return; }
      call('DELETE').then(function () { say(T.removed); window.location.reload(); }).catch(function (e) { say((e && e.message) || T.error, true); });
    });
  }
})();
