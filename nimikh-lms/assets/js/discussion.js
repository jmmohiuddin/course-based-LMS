/* Lesson discussion: questions + replies. All text rendered with textContent. */
(function () {
  'use strict';
  var cfg = window.NimikhApp;
  if (!cfg) { return; }
  var T = cfg.i18n;

  function api(path, method, body) {
    return fetch(cfg.restUrl + path, {
      method: method || 'GET', credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  function Discussion(root) {
    this.root = root;
    this.lesson = parseInt(root.getAttribute('data-lesson'), 10);
    this.me = parseInt(root.getAttribute('data-user'), 10);
    this.msg = el('p', { 'class': 'nk-feedback', role: 'status' });
    this.list = el('div', { 'class': 'nk-thread' });
    var self = this;
    this.root.appendChild(this.form(0, T.askPlaceholder, T.ask));
    this.root.appendChild(this.msg);
    this.root.appendChild(this.list);
    this.load();
  }

  Discussion.prototype.say = function (t, kind) { this.msg.textContent = t || ''; this.msg.setAttribute('data-kind', kind || ''); };

  Discussion.prototype.load = function () {
    var self = this;
    api('/lessons/' + this.lesson + '/discussion').then(function (items) { self.items = items; self.draw(); })
      .catch(function (e) { self.say((e && e.message) || T.error, 'wrong'); });
  };

  Discussion.prototype.form = function (parentId, placeholder, label) {
    var self = this;
    var ta = el('textarea', { rows: '2', maxlength: '2000', placeholder: placeholder, 'aria-label': placeholder, style: 'width:100%' });
    var btn = el('button', { type: 'button', 'class': 'nk-btn', text: label });
    btn.addEventListener('click', function () {
      if (ta.value.trim().length < 2) { return; }
      btn.disabled = true;
      api('/lessons/' + self.lesson + '/discussion', 'POST', { body: ta.value, parent_id: parentId })
        .then(function () { ta.value = ''; self.say('', ''); return self.load(); })
        .catch(function (e) { self.say((e && e.message) || T.error, 'wrong'); })
        .then(function () { btn.disabled = false; });
    });
    return el('div', { 'class': 'nk-thread__form' }, [ta, btn]);
  };

  Discussion.prototype.post = function (p, isReply) {
    var self = this;
    var head = el('div', { 'class': 'nk-thread__head' }, [el('strong', { text: p.author })]);
    if (p.is_staff) { head.appendChild(el('span', { 'class': 'nk-badge nk-badge--valid', text: T.staff })); }
    if (p.status === 'hidden') { head.appendChild(el('em', { text: T.hidden })); }
    var actions = el('div', { 'class': 'nk-thread__actions' });
    if (p.user_id === self.me || self.canModerate) {
      var del = el('button', { type: 'button', 'class': 'nk-btn nk-btn--ghost', text: T['delete'] });
      del.addEventListener('click', function () { api('/discussion/' + p.id, 'DELETE').then(function () { self.load(); }).catch(function () { self.say(T.error, 'wrong'); }); });
      actions.appendChild(del);
    }
    if (self.canModerate) {
      var hide = el('button', { type: 'button', 'class': 'nk-btn nk-btn--ghost', text: p.status === 'hidden' ? T.unhide : T.hide });
      hide.addEventListener('click', function () { api('/discussion/' + p.id + '/hide', 'POST', { hidden: p.status !== 'hidden' }).then(function () { self.load(); }); });
      actions.appendChild(hide);
    }
    return el('div', { 'class': isReply ? 'nk-thread__reply' : 'nk-thread__post' }, [head, el('p', { text: p.body, style: 'white-space:pre-wrap' }), actions]);
  };

  Discussion.prototype.draw = function () {
    var self = this;
    this.list.textContent = '';
    this.canModerate = this.root.getAttribute('data-moderator') === '1'; // set server-side; the API re-checks every action
    if (!this.items.length) { this.list.appendChild(el('p', { 'class': 'nk-empty', text: T.empty })); return; }
    this.items.forEach(function (t) {
      var box = self.post(t, false);
      t.replies.forEach(function (r) { box.appendChild(self.post(r, true)); });
      box.appendChild(self.form(t.id, T.reply, T.send));
      self.list.appendChild(box);
    });
  };

  document.querySelectorAll('.nk-discussion').forEach(function (n) { new Discussion(n); });
})();
