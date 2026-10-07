/* Phone-code and Google sign-in. Vanilla JS; Google's script loads only when a client ID is configured. */
(function () {
  'use strict';
  var cfg = window.NimikhAuth, root = document.querySelector('.nk-auth');
  if (!cfg || !root) { return; }
  var T = cfg.i18n || {};

  function post(path, body) {
    return fetch(cfg.restUrl + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }
  var status = el('p', { 'class': 'nk-auth__status', role: 'status' });
  function say(msg) { status.textContent = msg || ''; }
  function fail(e) { say((e && e.message) || T.error); }
  function done(r) { window.location.href = r.redirect || cfg.redirect; }

  root.appendChild(el('h3', { text: T.heading }));

  if (cfg.otp) {
    var phone = el('input', { type: 'tel', inputmode: 'tel', autocomplete: 'tel', id: 'nk-auth-phone', required: 'required' });
    var send = el('button', { type: 'button', 'class': 'nk-btn', text: T.sendCode });
    var step1 = el('div', { 'class': 'nk-auth__step' }, [el('label', { 'for': 'nk-auth-phone', text: T.phone }), phone, send]);

    var code = el('input', { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', id: 'nk-auth-code' });
    var verify = el('button', { type: 'button', 'class': 'nk-btn', text: T.verify });
    var change = el('button', { type: 'button', 'class': 'nk-btn nk-btn--ghost', text: T.change });
    var step2 = el('div', { 'class': 'nk-auth__step', hidden: 'hidden' }, [el('label', { 'for': 'nk-auth-code', text: T.code }), code, verify, change]);

    send.addEventListener('click', function () {
      send.disabled = true; say('');
      post('/auth/otp/request', { phone: phone.value }).then(function () {
        step1.hidden = true; step2.hidden = false; say(T.codeSent); code.focus();
      }).catch(fail).then(function () { send.disabled = false; });
    });
    verify.addEventListener('click', function () {
      verify.disabled = true; say('');
      post('/auth/otp/verify', { phone: phone.value, code: code.value, redirect: cfg.redirect }).then(done).catch(fail).then(function () { verify.disabled = false; });
    });
    change.addEventListener('click', function () { step2.hidden = true; step1.hidden = false; code.value = ''; say(''); phone.focus(); });
    root.appendChild(step1); root.appendChild(step2);
  }

  if (cfg.google) {
    if (cfg.otp) { root.appendChild(el('p', { 'class': 'nk-auth__or', text: T.or })); }
    var slot = el('div', { 'class': 'nk-auth__google' });
    root.appendChild(slot);
    var s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client'; s.async = true; s.defer = true;
    s.onload = function () {
      if (!window.google || !window.google.accounts) { return; }
      window.google.accounts.id.initialize({
        client_id: cfg.google,
        callback: function (resp) { post('/auth/google', { credential: resp.credential, redirect: cfg.redirect }).then(done).catch(fail); }
      });
      window.google.accounts.id.renderButton(slot, { theme: 'outline', size: 'large', width: 280 });
    };
    document.head.appendChild(s);
  }
  root.appendChild(status);
})();
