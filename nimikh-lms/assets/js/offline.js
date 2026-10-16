/* Offline lessons: download a lesson's video into Cache Storage under a time-limited licence. The service worker plays it back
 * (with range support) and refuses it after expiry; the server limits devices and revokes on the next online visit.
 * This is not DRM: it bounds, it does not prevent copying. */
(function () {
  'use strict';
  var cfg = window.NimikhOffline;
  if (!cfg) { return; }
  var T = cfg.i18n || {}, OFF = 'nimikh-offline', ORIGIN = location.origin;

  function key(id, name) { return ORIGIN + '/nimikh-offline-media/' + id + '/' + name; }
  function supported() { return 'caches' in window && 'serviceWorker' in navigator; }
  function el(tag, attrs) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); } });
    return n;
  }
  function deviceId() {
    try {
      var d = localStorage.getItem('nimikh-device');
      if (d && /^[a-f0-9]{32}$/.test(d)) { return d; }
      var a = new Uint8Array(16); window.crypto.getRandomValues(a);
      d = Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      localStorage.setItem('nimikh-device', d);
      return d;
    } catch (e) { return '0'.repeat(32); }
  }
  function api(path, method, body) {
    return fetch(cfg.restUrl + path, { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function fmt(ts) { try { return new Date(ts * 1000).toLocaleDateString(); } catch (e) { return ''; } }

  function purge(id) {
    return caches.open(OFF).then(function (c) {
      var prefix = ORIGIN + '/nimikh-offline-media/' + id + '/';
      return c.keys().then(function (keys) { return Promise.all(keys.filter(function (k) { return k.url.indexOf(prefix) === 0; }).map(function (k) { return c.delete(k); })); });
    });
  }
  /** The valid licence stored for a lesson, or null (an expired one is deleted). */
  function licenseOf(id) {
    return caches.open(OFF).then(function (c) { return c.match(key(id, 'license.json')); }).then(function (r) { return r ? r.json() : null; }).then(function (lic) {
      if (lic && lic.expires_at * 1000 < Date.now()) { return purge(id).then(function () { return null; }); }
      return lic;
    }).catch(function () { return null; });
  }
  function allLicenses() {
    return caches.open(OFF).then(function (c) { return c.keys(); }).then(function (keys) {
      var ids = keys.map(function (k) { var m = /\/nimikh-offline-media\/(\d+)\/license\.json$/.exec(k.url); return m ? parseInt(m[1], 10) : 0; }).filter(Boolean);
      return Promise.all(ids.map(licenseOf));
    }).then(function (list) { return list.filter(Boolean); });
  }

  /** Cache this lesson page and the files it needs, so it can open with no connection. */
  function cachePage(cache, id) {
    var url = location.href.split('#')[0];
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) { return null; }
      var headers = new Headers(r.headers); headers.set('x-nimikh-lesson', String(id));
      return r.blob().then(function (b) { return cache.put(url, new Response(b, { status: 200, headers: headers })); });
    }).then(function () {
      var urls = {};
      Array.prototype.forEach.call(document.querySelectorAll('link[rel~="stylesheet"][href], script[src], img[src], link[rel~="icon"][href]'), function (n) {
        var u = n.href || n.src; if (u && u.indexOf(ORIGIN) === 0) { urls[u.split('#')[0]] = 1; }
      });
      return Promise.all(Object.keys(urls).map(function (u) { return fetch(u, { credentials: 'same-origin' }).then(function (r) { return r.ok ? cache.put(u, r) : null; }).catch(function () { return null; }); }));
    });
  }

  function download(id, onProgress) {
    var device = deviceId(), grant;
    return api('/lessons/' + id + '/offline', 'POST', { device: device }).then(function (g) {
      grant = g;
      return caches.open(OFF);
    }).then(function (cache) {
      return fetch(grant.url, { credentials: 'omit' }).then(function (res) {
        if (!res.ok || !res.body) { throw { code: 'http', message: T.failed }; }
        var total = parseInt(res.headers.get('Content-Length') || '0', 10);
        var room = (navigator.storage && navigator.storage.estimate) ? navigator.storage.estimate() : Promise.resolve(null);
        return room.then(function (est) {
          if (est && total && est.quota && est.quota - est.usage < total * 1.1) { throw { code: 'space', message: T.noSpace }; }
          var copy = res.clone(), reader = res.body.getReader(), got = 0;
          var stored = cache.put(key(id, 'video.mp4'), copy);
          function pump() { return reader.read().then(function (r) { if (r.done) { return; } got += r.value.length; onProgress(total ? Math.min(99, Math.round(got / total * 100)) : 0); return pump(); }); }
          return Promise.all([stored, pump()]);
        });
      }).then(function () {
        return fetch(cfg.restUrl + '/lessons/' + id + '/player', { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } });
      }).then(function (r) {
        if (!r.ok) { throw { code: 'http', message: T.failed }; }
        return r.text();
      }).then(function (text) {
        return cache.put(key(id, 'player.json'), new Response(text, { headers: { 'Content-Type': 'application/json' } }));
      }).then(function () { return cachePage(cache, id); }).then(function () {
        var lic = { lesson_id: id, expires_at: grant.expires_at, device: device, title: grant.title || '', downloaded_at: Math.floor(Date.now() / 1000) };
        return cache.put(key(id, 'license.json'), new Response(JSON.stringify(lic), { headers: { 'Content-Type': 'application/json' } })).then(function () { return lic; });
      });
    }).catch(function (e) {
      // Leave nothing half-downloaded, and give the server slot back.
      return purge(id).then(function () { return api('/lessons/' + id + '/offline', 'DELETE', { device: device }).catch(function () {}); }).then(function () { throw e; });
    });
  }

  function remove(id) {
    return purge(id).then(function () { return api('/lessons/' + id + '/offline', 'DELETE', { device: deviceId() }).catch(function () {}); });
  }

  /** Drop local copies the server no longer honours (access ended, licence removed from another device). */
  function sync() {
    if (!navigator.onLine || !supported()) { return Promise.resolve(); }
    return Promise.all([allLicenses(), api('/me/offline?device=' + deviceId())]).then(function (res) {
      var live = {}; (res[1].licenses || []).forEach(function (l) { live[l.lesson_id] = 1; });
      return Promise.all(res[0].filter(function (l) { return !live[l.lesson_id]; }).map(function (l) { return purge(l.lesson_id); }));
    }).catch(function () {});
  }

  function mountLesson(root) {
    var id = parseInt(root.getAttribute('data-lesson'), 10);
    var text = el('span', { 'class': 'nk-offline__text', role: 'status' });
    var btn = el('button', { type: 'button', 'class': 'nk-btn nk-btn--ghost' });
    var bar = el('progress', { max: '100', value: '0', hidden: 'hidden' });
    root.appendChild(text); root.appendChild(btn); root.appendChild(bar);

    function show(lic) {
      bar.hidden = true; btn.disabled = false;
      if (lic) {
        text.textContent = (T.ready || '%s').replace('%s', fmt(lic.expires_at));
        btn.textContent = T.remove; btn.onclick = function () { btn.disabled = true; remove(id).then(function () { show(null); }); };
      } else {
        text.textContent = T.note || '';
        btn.textContent = T.download; btn.onclick = start;
        if (!navigator.onLine) { btn.disabled = true; }
      }
    }
    function start() {
      if (!supported() || !navigator.serviceWorker.controller) { text.textContent = T.noSupport; return; }
      btn.disabled = true; bar.hidden = false; bar.value = 0; text.textContent = (T.downloading || '%d').replace('%d', 0);
      download(id, function (p) { bar.value = p; text.textContent = (T.downloading || '%d').replace('%d', p); })
        .then(function (lic) { show(lic); })
        .catch(function (e) { show(null); text.textContent = (e && e.message) || T.failed; });
    }
    if (!supported()) { show(null); return; }
    licenseOf(id).then(show);
  }

  function mountList(root) {
    function draw() {
      allLicenses().then(function (list) {
        root.textContent = '';
        if (!list.length) { root.appendChild(el('p', { text: T.none })); return; }
        var ul = el('ul');
        list.forEach(function (l) {
          var li = el('li'), b = el('button', { type: 'button', 'class': 'nk-btn nk-btn--ghost', text: T.remove });
          b.addEventListener('click', function () { b.disabled = true; remove(l.lesson_id).then(draw); });
          li.appendChild(el('span', { text: (l.title || ('#' + l.lesson_id)) + ' — ' + (T.ready || '%s').replace('%s', fmt(l.expires_at)) })); li.appendChild(b); ul.appendChild(li);
        });
        root.appendChild(ul); root.appendChild(el('p', { 'class': 'nk-offline__text', text: T.note }));
      });
    }
    if (supported()) { draw(); } else { root.appendChild(el('p', { text: T.noSupport })); }
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('.nk-offline[data-lesson]'), mountLesson);
    Array.prototype.forEach.call(document.querySelectorAll('.nk-downloads'), mountList);
    sync();
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
  window.NimikhOfflineApi = { download: download, remove: remove, licenseOf: licenseOf, sync: sync, deviceId: deviceId };
})();
