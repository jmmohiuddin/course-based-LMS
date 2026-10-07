/* Nimikh instructor editor: add an MCQ at a video timestamp in one click (blueprint flow B).
 * All user-supplied text is rendered with textContent, never innerHTML. */
(function () {
  'use strict';
  var cfg = window.NimikhEditor;
  if (!cfg) { return; }

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
  function fmt(s) { s = Math.max(0, Math.round(s)); var m = Math.floor(s / 60), r = s % 60; return m + ':' + (r < 10 ? '0' : '') + r; }

  function Editor(root) {
    this.root = root;
    this.lessonId = parseInt(root.getAttribute('data-lesson'), 10);
    this.permalink = root.getAttribute('data-permalink');
    this.items = [];
    this.duration = 0;
    this.msg = el('p', { 'class': 'nk-editor__msg', role: 'status' });
    var self = this;
    Promise.all([
      api('/lessons/' + this.lessonId + '/interactions'),
      api('/lessons/' + this.lessonId + '/player').catch(function () { return null; })
    ]).then(function (res) {
      self.items = res[0].interactions; self.duration = res[0].duration;
      if (!res[1]) { root.appendChild(el('p', { text: 'Save a video ID or URL on this lesson first, then reload.' })); return; }
      self.build(res[1].source);
    }).catch(function (e) { root.appendChild(el('p', { text: (e && e.message) || 'Could not load the editor.' })); });
  }

  Editor.prototype.say = function (t, kind) { this.msg.textContent = t || ''; this.msg.setAttribute('data-kind', kind || ''); };

  Editor.prototype.build = function (src) {
    var self = this;
    this.video = el('video', { controls: '', playsinline: '', preload: 'metadata' });
    this.track = el('div', { 'class': 'nk-editor__track' });
    this.playhead = el('div', { 'class': 'nk-editor__playhead' });
    this.track.appendChild(this.playhead);
    var add = el('button', { type: 'button', 'class': 'button button-primary', text: 'Add question here' });
    var preview = el('a', { 'class': 'button', href: this.permalink || '#', target: '_blank', rel: 'noopener', text: 'Preview as learner' });
    this.clock = el('span', { text: '0:00' });
    this.list = el('ul', { 'class': 'nk-editor__list' });
    this.formHost = el('div');
    this.root.appendChild(this.video);
    this.root.appendChild(this.track);
    var ai = el('button', { type: 'button', 'class': 'button', text: 'Suggest questions (AI)' });
    ai.addEventListener('click', function () { self.openAi(); });
    this.aiHost = el('div');
    this.root.appendChild(el('div', { 'class': 'nk-editor__bar' }, [add, ai, preview, this.clock]));
    this.root.appendChild(this.aiHost);
    this.root.appendChild(this.msg);
    this.root.appendChild(this.formHost);
    this.root.appendChild(this.list);

    if (src.type === 'hls' && !this.video.canPlayType('application/vnd.apple.mpegurl')) {
      var s = document.createElement('script');
      s.src = cfg.hlsSrc;
      s.onload = function () { if (window.Hls && window.Hls.isSupported()) { var h = new window.Hls(); h.loadSource(src.url); h.attachMedia(self.video); } };
      document.head.appendChild(s);
    } else { this.video.src = src.url; }

    this.video.addEventListener('loadedmetadata', function () {
      var d = Math.round(self.video.duration);
      if (isFinite(d) && d > 0 && d !== self.duration) {
        api('/lessons/' + self.lessonId + '/duration', 'POST', { duration: d }).then(function () { self.duration = d; self.drawTrack(); });
      } else { self.drawTrack(); }
    });
    this.video.addEventListener('timeupdate', function () {
      self.clock.textContent = fmt(self.video.currentTime);
      if (self.duration) { self.playhead.style.left = (self.video.currentTime / self.duration * 100) + '%'; }
    });
    this.track.addEventListener('click', function (e) {
      if (e.target !== self.track || !self.duration) { return; }
      var r = self.track.getBoundingClientRect();
      self.video.currentTime = (e.clientX - r.left) / r.width * self.duration;
    });
    add.addEventListener('click', function () { self.video.pause(); self.openForm({ at_second: Math.round(self.video.currentTime), required: true, allow_retry: true, points: 1, rewind_to_second: null, question: { stem: '', options: ['', ''], correct_index: 0, explanation: '' } }); });
    this.drawList();
  };

  /** AI drafts: generated server-side, reviewed here, saved only through the normal form. */
  Editor.prototype.openAi = function () {
    var self = this;
    this.aiHost.textContent = '';
    var transcript = el('textarea', { rows: '4', placeholder: 'Optional: paste a transcript. Leave empty to use the lesson captions.', style: 'width:100%;max-width:720px' });
    var count = el('input', { type: 'number', min: '1', max: '10', value: '5', style: 'width:60px' });
    var lang = el('select', {}, [el('option', { value: 'en', text: 'English' }), el('option', { value: 'bn', text: 'Bangla' })]);
    var go = el('button', { type: 'button', 'class': 'button button-primary', text: 'Generate drafts' });
    var out = el('ul', { 'class': 'nk-editor__list' });
    this.aiHost.appendChild(el('div', { 'class': 'nk-editor__form' }, [
      el('p', { text: 'Drafts are suggestions. Check each one before adding it. Transcript text is sent to the AI provider.' }),
      transcript, el('p', {}, [document.createTextNode('Questions '), count, document.createTextNode(' Language '), lang, document.createTextNode(' '), go])
    ]));
    this.aiHost.appendChild(out);
    go.addEventListener('click', function () {
      go.disabled = true; self.say('Generating…', '');
      api('/lessons/' + self.lessonId + '/ai-questions', 'POST', { transcript: transcript.value, count: parseInt(count.value, 10) || 5, language: lang.value })
        .then(function (r) {
          out.textContent = '';
          self.say(r.questions.length ? r.questions.length + ' draft(s). Review each before adding.' : 'The AI returned nothing usable. Try again or add more transcript.', r.questions.length ? 'ok' : 'error');
          r.questions.forEach(function (q) {
            var review = el('button', { type: 'button', 'class': 'button', text: 'Review & add' });
            review.addEventListener('click', function () {
              self.video.currentTime = Math.max(0, q.at_second - 3);
              self.openForm({ at_second: q.at_second, required: true, allow_retry: true, points: 1, rewind_to_second: null, question: { stem: q.stem, options: q.options, correct_index: q.correct_index, explanation: q.explanation } });
            });
            out.appendChild(el('li', {}, [el('strong', { text: fmt(q.at_second) }), el('span', { text: q.stem }), review]));
          });
        })
        .catch(function (e) { self.say((e && e.message) || 'Could not generate questions.', 'error'); })
        .then(function () { go.disabled = false; });
    });
  };

  Editor.prototype.drawTrack = function () {
    var self = this;
    Array.prototype.slice.call(this.track.querySelectorAll('.nk-editor__marker')).forEach(function (m) { m.remove(); });
    if (!this.duration) { return; }
    this.items.forEach(function (it) {
      var m = el('span', { 'class': 'nk-editor__marker', title: fmt(it.at_second) + ' ' + it.stem, 'data-selected': self.editing && self.editing.id === it.id ? '1' : '0' });
      m.style.left = (it.at_second / self.duration * 100) + '%';
      m.addEventListener('pointerdown', function (e) { self.dragMarker(e, it, m); });
      self.track.appendChild(m);
    });
  };

  /** Drag a marker to retime a question; saved on release. */
  Editor.prototype.dragMarker = function (e, it, node) {
    var self = this, rect = this.track.getBoundingClientRect(), moved = false;
    node.setPointerCapture(e.pointerId);
    function move(ev) {
      moved = true;
      var pct = Math.min(1, Math.max(0, (ev.clientX - rect.left) / rect.width));
      it.at_second = Math.min(Math.round(pct * self.duration), Math.max(0, self.duration - 1));
      node.style.left = (it.at_second / self.duration * 100) + '%';
    }
    function up() {
      node.removeEventListener('pointermove', move); node.removeEventListener('pointerup', up);
      if (!moved) { self.openForm(it); return; }
      api('/lessons/' + self.lessonId + '/interactions/' + it.id, 'PUT', { at_second: it.at_second, required: it.required, allow_retry: it.allow_retry, points: it.points, rewind_to_second: it.rewind_to_second })
        .then(function () { self.say('Moved to ' + fmt(it.at_second), 'ok'); self.drawList(); })
        .catch(function (err) { self.say((err && err.message) || 'Could not move the question', 'error'); self.reload(); });
    }
    node.addEventListener('pointermove', move); node.addEventListener('pointerup', up);
  };

  Editor.prototype.reload = function () {
    var self = this;
    return api('/lessons/' + this.lessonId + '/interactions').then(function (r) { self.items = r.interactions; self.drawList(); });
  };

  Editor.prototype.drawList = function () {
    var self = this;
    this.list.textContent = '';
    this.items.forEach(function (it) {
      var edit = el('button', { type: 'button', 'class': 'button', text: 'Edit' });
      var del = el('button', { type: 'button', 'class': 'button', text: 'Delete' });
      edit.addEventListener('click', function () { self.video.currentTime = Math.max(0, it.at_second - 3); self.openForm(it); });
      del.addEventListener('click', function () {
        if (!window.confirm('Delete this question from the video?')) { return; }
        api('/lessons/' + self.lessonId + '/interactions/' + it.id, 'DELETE').then(function () { self.say('Deleted', 'ok'); return self.reload(); });
      });
      self.list.appendChild(el('li', {}, [el('strong', { text: fmt(it.at_second) }), el('span', { text: it.stem + (it.required ? '' : ' (optional)') }), edit, del]));
    });
    this.drawTrack();
  };

  Editor.prototype.openForm = function (it) {
    var self = this, isNew = !it.id;
    this.editing = it;
    var q = it.question || { stem: it.stem, options: it.options.slice(), correct_index: it.correct_index, explanation: it.explanation || '' };
    var state = { options: q.options.slice(), correct: q.correct_index };

    var stem = el('textarea', { rows: '2' }); stem.value = q.stem;
    var optsHost = el('div');
    var explanation = el('textarea', { rows: '2' }); explanation.value = q.explanation || '';
    var at = el('input', { type: 'number', min: '0' }); at.value = it.at_second;
    var points = el('input', { type: 'number', min: '0' }); points.value = it.points;
    var required = el('input', { type: 'checkbox' }); required.checked = !!it.required;
    var retry = el('input', { type: 'checkbox' }); retry.checked = it.allow_retry !== false;
    var rewindOn = el('input', { type: 'checkbox' }); rewindOn.checked = it.rewind_to_second !== null && it.rewind_to_second !== undefined;
    var rewind = el('input', { type: 'number', min: '0' }); rewind.value = rewindOn.checked ? it.rewind_to_second : Math.max(0, it.at_second - 20);

    function drawOpts() {
      optsHost.textContent = '';
      state.options.forEach(function (text, i) {
        var radio = el('input', { type: 'radio', name: 'nk-correct', 'aria-label': 'Correct option ' + (i + 1) }); radio.checked = state.correct === i;
        var input = el('input', { type: 'text', placeholder: 'Option ' + (i + 1) }); input.value = text;
        var rm = el('button', { type: 'button', 'class': 'button', text: '×', 'aria-label': 'Remove option ' + (i + 1) });
        radio.addEventListener('change', function () { state.correct = i; });
        input.addEventListener('input', function () { state.options[i] = input.value; });
        rm.addEventListener('click', function () { if (state.options.length > 2) { state.options.splice(i, 1); if (state.correct >= state.options.length) { state.correct = 0; } drawOpts(); } });
        optsHost.appendChild(el('div', { 'class': 'nk-editor__opt' }, [radio, input, rm]));
      });
      if (state.options.length < 6) {
        var more = el('button', { type: 'button', 'class': 'button', text: 'Add option' });
        more.addEventListener('click', function () { state.options.push(''); drawOpts(); });
        optsHost.appendChild(more);
      }
    }
    drawOpts();

    function row(label, node) { return el('div', {}, [el('label', { text: label }), node]); }
    function check(label, node) { return el('label', {}, [node, document.createTextNode(' ' + label)]); }
    var save = el('button', { type: 'button', 'class': 'button button-primary', text: isNew ? 'Add to video' : 'Save changes' });
    var cancel = el('button', { type: 'button', 'class': 'button', text: 'Cancel' });
    var form = el('div', { 'class': 'nk-editor__form' }, [
      row('Appears at (seconds)', at), row('Question', stem), el('label', { text: 'Options (select the correct one)' }), optsHost,
      row('Explanation shown after answering', explanation),
      check('Required (learner cannot skip past it)', required), check('Allow a second try', retry),
      check('Rewind on wrong answer to (seconds):', rewindOn), rewind, row('Points', points),
      el('p', {}, [save, document.createTextNode(' '), cancel])
    ]);
    this.formHost.textContent = ''; this.formHost.appendChild(form); this.drawTrack();
    stem.focus();

    cancel.addEventListener('click', function () { self.editing = null; self.formHost.textContent = ''; self.drawTrack(); });
    save.addEventListener('click', function () {
      var body = {
        at_second: parseInt(at.value, 10), required: required.checked, allow_retry: retry.checked,
        points: parseInt(points.value, 10) || 0, rewind_to_second: rewindOn.checked ? parseInt(rewind.value, 10) : null,
        question: { stem: stem.value, options: state.options, correct_index: state.correct, explanation: explanation.value }
      };
      var req = isNew ? api('/lessons/' + self.lessonId + '/interactions', 'POST', body)
                      : api('/lessons/' + self.lessonId + '/interactions/' + it.id, 'PUT', body);
      req.then(function () { self.say('Saved', 'ok'); self.editing = null; self.formHost.textContent = ''; return self.reload(); })
         .catch(function (e) { self.say((e && e.message) || 'Could not save', 'error'); });
    });
  };

  document.querySelectorAll('.nk-editor').forEach(function (n) { if (n.getAttribute('data-can') === '1') { new Editor(n); } });
})();
