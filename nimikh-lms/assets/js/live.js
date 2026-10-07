/* Upcoming live classes for a course, with a server-mediated Join button (records attendance). */
(function () {
  'use strict';
  var cfg = window.NimikhApp;
  if (!cfg) { return; }
  var T = cfg.i18n;

  function api(path, method) {
    return fetch(cfg.restUrl + path, { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); } });
    (kids || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  document.querySelectorAll('.nk-live').forEach(function (root) {
    var course = root.getAttribute('data-course');
    var msg = el('p', { 'class': 'nk-feedback', role: 'status' });
    root.appendChild(msg);
    api('/courses/' + course + '/live').then(function (sessions) {
      if (!sessions.length) { root.appendChild(el('p', { 'class': 'nk-empty', text: T.noLive })); return; }
      var ul = el('ul', { 'class': 'nk-certs' });
      sessions.forEach(function (s) {
        var when = new Date(s.starts_at.replace(' ', 'T') + 'Z');
        var btn = el('button', { type: 'button', 'class': 'nk-btn', text: T.join });
        var canJoin = s.state === 'open' || s.state === 'live';
        btn.disabled = !canJoin;
        btn.addEventListener('click', function () {
          btn.disabled = true;
          api('/live/' + s.id + '/join', 'POST').then(function (r) { window.open(r.url, '_blank', 'noopener'); })
            .catch(function (e) { msg.setAttribute('data-kind', 'wrong'); msg.textContent = (e && e.message) || T.error; })
            .then(function () { btn.disabled = false; });
        });
        var note = s.state === 'live' ? T.liveNow : (canJoin ? '' : T.opensSoon);
        ul.appendChild(el('li', { 'class': 'nk-cert' }, [
          el('strong', { text: s.title }), el('br'),
          el('small', { text: when.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) + ' · ' + s.duration_min + ' min' + (note ? ' · ' + note : '') }), el('br'), btn
        ]));
      });
      root.appendChild(ul);
    }).catch(function (e) { msg.setAttribute('data-kind', 'wrong'); msg.textContent = (e && e.message) || T.error; });
  });
})();
