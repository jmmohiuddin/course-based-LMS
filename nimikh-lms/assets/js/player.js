/* Nimikh learner player: in-video MCQ pop-ups, anti-skip, watch tracking.
 * Vanilla JS on purpose (blueprint budget: < 60 KB gz); hls.js is lazy-loaded only for HLS sources. */
(function () {
  'use strict';
  var cfg = window.NimikhPlayer;
  if (!cfg) { return; }
  var T = cfg.i18n || {};

  function api(path, method, body) {
    return fetch(cfg.restUrl + path, {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) { throw j; } return j; });
    });
  }

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; }
      else if (k === 'class') { n.className = attrs[k]; }
      else { n.setAttribute(k, attrs[k]); }
    });
    (children || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }

  var hlsPromise;
  function loadHls() {
    if (window.Hls) { return Promise.resolve(window.Hls); }
    if (!hlsPromise) {
      hlsPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = cfg.hlsSrc; s.async = true;
        s.onload = function () { resolve(window.Hls); };
        s.onerror = reject;
        document.head.appendChild(s);
      });
    }
    return hlsPromise;
  }

  function Player(root) {
    this.root = root;
    this.lessonId = parseInt(root.getAttribute('data-lesson'), 10);
    this.pending = [];      // watched ranges not yet acknowledged by the server
    this.open = null;       // currently-playing range [start, end]
    this.shown = null;      // interaction currently on screen
    this.skipped = {};      // optional questions dismissed this session
    this.lastTime = 0;
    this.seekingBack = false;
    this.beating = false;
    this.init();
  }

  Player.prototype.init = function () {
    var self = this;
    this.statusEl = el('p', { 'class': 'nk-player__status', 'aria-live': 'polite' });
    api('/lessons/' + this.lessonId + '/player').then(function (d) {
      self.data = d;
      self.furthest = d.furthest || 0;
      self.gate = d.gate;
      self.build();
    }).catch(function (e) {
      self.root.appendChild(el('p', { 'class': 'nk-notice', text: (e && e.message) || T.loadError }));
    });
  };

  Player.prototype.build = function () {
    var self = this, d = this.data;
    var video = this.video = el('video', { controls: '', playsinline: '', preload: 'metadata', controlsList: 'nodownload noplaybackrate' });
    video.setAttribute('disablepictureinpicture', '');
    video.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    (d.captions || []).forEach(function (c, i) {
      video.appendChild(el('track', { kind: 'subtitles', src: c.url, srclang: c.lang, label: c.label || c.lang, 'default': i === 0 ? '' : null }));
    });

    this.stage = el('div', { 'class': 'nk-player__stage' }, [video]);
    this.watermark = el('div', { 'class': 'nk-player__watermark', text: d.watermark || '', 'aria-hidden': 'true' });
    this.stage.appendChild(this.watermark);
    this.markers = el('div', { 'class': 'nk-markers', 'aria-hidden': 'true' });
    this.root.appendChild(this.stage);
    this.root.appendChild(this.markers);
    this.root.appendChild(this.statusEl);
    this.drawMarkers();

    this.attachSource(video, d.source).then(function () {
      video.addEventListener('loadedmetadata', function () {
        if (d.resume_second > 0 && d.resume_second < (video.duration || Infinity)) { video.currentTime = d.resume_second; }
        self.lastTime = video.currentTime;
      }, { once: true });
    });

    video.addEventListener('play', function () { self.open = [video.currentTime, video.currentTime]; });
    video.addEventListener('pause', function () { self.closeRange(); self.heartbeat(); });
    video.addEventListener('ended', function () { self.closeRange(); self.heartbeat(); });
    video.addEventListener('timeupdate', function () { self.onTime(); });
    video.addEventListener('seeking', function () { self.onSeeking(); });
    video.addEventListener('ratechange', function () {
      if (video.playbackRate > d.settings.max_rate) { video.playbackRate = d.settings.max_rate; self.say(T.speedCapped, 'warning'); }
    });

    this.timer = setInterval(function () { if (!video.paused) { self.closeRange(); self.open = [video.currentTime, video.currentTime]; } self.heartbeat(); },
      (d.settings.heartbeat_interval || 10) * 1000);
    this.moveWatermark(); setInterval(function () { self.moveWatermark(); }, 15000);

    document.addEventListener('visibilitychange', function () { if (document.hidden) { self.flushBeacon(); } });
    window.addEventListener('pagehide', function () { self.flushBeacon(); });
  };

  Player.prototype.attachSource = function (video, src) {
    if (src.type === 'hls' && !video.canPlayType('application/vnd.apple.mpegurl')) {
      return loadHls().then(function (Hls) {
        if (Hls && Hls.isSupported()) { var h = new Hls(); h.loadSource(src.url); h.attachMedia(video); }
        else { video.src = src.url; }
      }).catch(function () { video.src = src.url; });
    }
    video.src = src.url;
    return Promise.resolve();
  };

  Player.prototype.say = function (msg, kind) { this.statusEl.textContent = msg || ''; this.statusEl.setAttribute('data-kind', kind || ''); };

  Player.prototype.moveWatermark = function () {
    this.watermark.style.top = (8 + Math.random() * 70) + '%';
    this.watermark.style.left = (4 + Math.random() * 60) + '%';
  };

  Player.prototype.drawMarkers = function () {
    var self = this, dur = this.data.duration || 1;
    this.markers.textContent = '';
    this.data.interactions.forEach(function (it) {
      var state = it.resolved ? 'answered' : (self.shown && self.shown.id === it.id ? 'current' : 'pending');
      var m = el('span', { 'class': 'nk-marker', 'data-state': state });
      m.style.left = Math.min(100, it.at_second / dur * 100) + '%';
      self.markers.appendChild(m);
    });
  };

  /** Highest second the learner may currently reach. */
  Player.prototype.allowedMax = function () {
    var cap = this.gate === null || this.gate === undefined ? Infinity : this.gate;
    return Math.min(Math.max(this.furthest, this.lastTime), cap);
  };

  Player.prototype.onSeeking = function () {
    var v = this.video, max = this.allowedMax();
    this.closeRange();
    if (v.currentTime > max + 1) {
      var blocked = this.gate !== null && this.gate !== undefined && v.currentTime > this.gate;
      v.currentTime = max;
      this.say(blocked ? T.mustAnswer : '', blocked ? 'warning' : '');
    }
    this.lastTime = v.currentTime;
    if (!v.paused) { this.open = [v.currentTime, v.currentTime]; }
  };

  Player.prototype.onTime = function () {
    var v = this.video, t = v.currentTime;
    if (v.seeking) { return; }
    if (this.open) { this.open[1] = Math.max(this.open[1], t); }
    if (!v.paused && t > this.lastTime) {
      this.furthest = Math.max(this.furthest, t);
      if (this.gate !== null && this.gate !== undefined) { this.furthest = Math.min(this.furthest, this.gate); }
    }
    this.lastTime = t;
    if (this.shown) { return; }
    var due = this.nextDue(t);
    if (due) { this.ask(due); }
  };

  Player.prototype.nextDue = function (t) {
    var list = this.data.interactions;
    for (var i = 0; i < list.length; i++) {
      var it = list[i];
      if (!it.resolved && !this.skipped[it.id] && t >= it.at_second - 0.25 && t <= it.at_second + 3) { return it; }
    }
    return null;
  };

  Player.prototype.closeRange = function () {
    if (this.open && this.open[1] - this.open[0] >= 1) { this.pending.push([Math.floor(this.open[0]), Math.ceil(this.open[1])]); }
    this.open = null;
  };

  Player.prototype.payload = function () {
    var ranges = this.pending.slice();
    if (this.open && this.open[1] - this.open[0] >= 1) {
      ranges.push([Math.floor(this.open[0]), Math.ceil(this.open[1])]);
      this.open = [this.video.currentTime, this.video.currentTime];
    }
    return { second: Math.floor(this.video.currentTime), ranges: ranges };
  };

  Player.prototype.heartbeat = function () {
    var self = this;
    if (self.beating || !self.video) { return; }
    var body = this.payload();
    if (!body.ranges.length && self.data.completed) { return; }
    this.pending = [];
    self.beating = true;
    api('/lessons/' + this.lessonId + '/heartbeat', 'POST', body).then(function (r) {
      self.beating = false;
      self.furthest = Math.max(self.furthest, r.furthest || 0);
      self.gate = r.gate;
      if (r.rejected && self.video.currentTime > self.furthest + 2) { self.video.currentTime = self.furthest; }
      if (r.completed && !self.data.completed) {
        self.data.completed = true; self.say(T.completed, 'success');
        document.dispatchEvent(new CustomEvent('nimikh:lesson-complete', { detail: { lessonId: self.lessonId } }));
      }
    }).catch(function () {
      self.beating = false;
      self.pending = body.ranges.concat(self.pending); // keep for the next beat (offline batching)
    });
  };

  Player.prototype.flushBeacon = function () {
    if (!this.video || !navigator.sendBeacon) { return; }
    this.closeRange();
    if (!this.pending.length) { return; }
    var body = { second: Math.floor(this.video.currentTime), ranges: this.pending };
    var url = cfg.restUrl + '/lessons/' + this.lessonId + '/heartbeat?_wpnonce=' + encodeURIComponent(cfg.nonce);
    if (navigator.sendBeacon(url, new Blob([JSON.stringify(body)], { type: 'application/json' }))) { this.pending = []; }
  };

  // ---- MCQ sheet ---------------------------------------------------------------

  Player.prototype.ask = function (it) {
    var self = this, v = this.video;
    v.pause();
    if (document.fullscreenElement && document.exitFullscreen) { document.exitFullscreen().catch(function () {}); }
    this.shown = it;
    this.drawMarkers();
    this.returnFocus = document.activeElement;

    var titleId = 'nk-q-' + it.id;
    var sheet = el('div', { 'class': 'nk-sheet', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId });
    sheet.appendChild(el('p', { 'class': 'nk-sheet__title', text: T.quickCheck }));
    sheet.appendChild(el('h3', { 'class': 'nk-sheet__stem', id: titleId, text: it.question.stem }));

    var group = el('fieldset', { 'class': 'nk-options' });
    var opts = [];
    it.question.options.forEach(function (text, i) {
      var input = el('input', { type: 'radio', name: 'nk-opt-' + it.id, value: String(i) });
      var mark = el('span', { 'class': 'nk-option__mark', 'aria-hidden': 'true' });
      var label = el('label', { 'class': 'nk-option' }, [input, el('span', { text: text }), mark]);
      group.appendChild(label); opts.push({ label: label, input: input, mark: mark });
    });
    sheet.appendChild(group);

    var feedback = el('p', { 'class': 'nk-feedback', role: 'status', 'aria-live': 'polite' });
    sheet.appendChild(feedback);
    var submit = el('button', { 'class': 'nk-btn', type: 'button', text: T.submit });
    var actions = el('div', { 'class': 'nk-sheet__actions' }, [submit]);
    if (!it.required) {
      var skip = el('button', { 'class': 'nk-btn nk-btn--ghost', type: 'button', text: T.skip });
      skip.addEventListener('click', function () { self.skipped[it.id] = true; self.closeSheet(sheet, false); });
      actions.appendChild(skip);
    }
    sheet.appendChild(actions);
    this.stage.appendChild(sheet);
    this.sheet = sheet;
    (opts[0] && opts[0].input).focus();

    sheet.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !it.required) { self.skipped[it.id] = true; self.closeSheet(sheet, false); }
      if (e.key === 'Tab') { self.trapFocus(e, sheet); }
    });

    function setBusy(b) { submit.disabled = b; opts.forEach(function (o) { o.input.disabled = b; }); }

    submit.addEventListener('click', function () {
      var chosen = opts.findIndex(function (o) { return o.input.checked; });
      if (chosen < 0) { return; }
      setBusy(true);
      api('/interactions/' + it.id + '/attempt', 'POST', { selected_index: chosen }).then(function (r) {
        self.onAnswer(it, r, chosen, opts, feedback, submit, actions, sheet, setBusy);
      }).catch(function (e) {
        feedback.setAttribute('data-kind', 'wrong'); feedback.textContent = (e && e.message) || T.loadError; setBusy(false);
      });
    });
  };

  Player.prototype.onAnswer = function (it, r, chosen, opts, feedback, submit, actions, sheet, setBusy) {
    var self = this;
    opts.forEach(function (o) { o.label.removeAttribute('data-state'); o.mark.textContent = ''; });

    function mark(i, state, glyph) { opts[i].label.setAttribute('data-state', state); opts[i].mark.textContent = glyph; }

    if (r.correct) {
      mark(chosen, 'correct', '✔');
      feedback.setAttribute('data-kind', 'correct');
      feedback.textContent = '✔ ' + T.correct + (r.explanation ? ' — ' + r.explanation : '');
    } else {
      mark(chosen, 'wrong', '✖');
      feedback.setAttribute('data-kind', 'wrong');
      if (r.resolved) {
        if (typeof r.correct_index === 'number' && opts[r.correct_index]) { mark(r.correct_index, 'correct', '✔'); }
        feedback.textContent = '✖ ' + T.answerIs + (r.explanation ? ' ' + r.explanation : '');
      } else {
        feedback.textContent = '✖ ' + (r.rewind_to !== undefined ? T.rewind : T.tryAgain);
      }
    }

    if (!r.resolved) {
      // Retry (or rewind first when the instructor set a rewind point).
      if (r.rewind_to !== undefined) {
        submit.textContent = T.rewind; submit.disabled = false;
        var onRewind = function () {
          submit.removeEventListener('click', onRewind);
          self.closeSheet(sheet, false);
          self.video.currentTime = r.rewind_to; self.lastTime = r.rewind_to; self.video.play();
        };
        // Replace the original handler by cloning the button.
        var clone = submit.cloneNode(true); submit.parentNode.replaceChild(clone, submit);
        clone.addEventListener('click', onRewind);
      } else {
        setBusy(false);
        opts.forEach(function (o) { o.input.checked = false; });
      }
      return;
    }

    // Resolved: update local state, offer Continue (auto-continues after 3 s on a correct answer).
    it.resolved = true;
    this.recomputeGate();
    if (r.lesson_completed && !this.data.completed) { this.data.completed = true; this.say(T.completed, 'success'); }
    var cont = el('button', { 'class': 'nk-btn', type: 'button', text: T.continue });
    actions.textContent = ''; actions.appendChild(cont); cont.focus();
    var done = false, finish = function () { if (done) { return; } done = true; self.closeSheet(sheet, true); };
    cont.addEventListener('click', finish);
    if (r.correct) { setTimeout(finish, 3000); }
  };

  Player.prototype.recomputeGate = function () {
    var gate = null;
    this.data.interactions.forEach(function (it) { if (gate === null && it.required && !it.resolved) { gate = it.at_second; } });
    this.gate = gate;
  };

  Player.prototype.closeSheet = function (sheet, resume) {
    if (sheet.parentNode) { sheet.parentNode.removeChild(sheet); }
    this.shown = null; this.sheet = null;
    this.drawMarkers();
    if (this.returnFocus && this.returnFocus.focus) { this.returnFocus.focus(); }
    if (resume) { this.video.play(); this.heartbeat(); }
  };

  Player.prototype.trapFocus = function (e, sheet) {
    var f = sheet.querySelectorAll('input:not([disabled]), button:not([disabled])');
    if (!f.length) { return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  };

  function boot() {
    document.querySelectorAll('.nk-player[data-mode="learner"]').forEach(function (n) {
      if (!n.getAttribute('data-ready')) { n.setAttribute('data-ready', '1'); new Player(n); }
    });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
