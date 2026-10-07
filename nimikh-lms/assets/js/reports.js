/* Instructor analytics: KPI tiles, retention curve (inline SVG) with question markers, accuracy tables. */
(function () {
  'use strict';
  var cfg = window.NimikhReports;
  var root = document.getElementById('nk-reports');
  if (!cfg || !root) { return; }
  var SVG = 'http://www.w3.org/2000/svg';

  function api(path) {
    return fetch(cfg.restUrl + path, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
  }
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); } });
    (kids || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }
  function s(tag, attrs) { var n = document.createElementNS(SVG, tag); Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); return n; }
  function mmss(sec) { return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2); }

  /** Retention curve: share of learners (0-100%) who watched each 10 s bucket; dashed lines mark questions. */
  function retentionChart(lesson) {
    var W = 720, H = 220, L = 40, R = 12, T = 12, B = 28;
    var pts = lesson.retention;
    var dur = pts.length ? pts[pts.length - 1].start + 10 : 0;
    var svg = s('svg', { 'class': 'nkr-chart', viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Retention for ' + lesson.title });
    var x = function (sec) { return L + sec / dur * (W - L - R); };
    var y = function (pct) { return T + (100 - pct) / 100 * (H - T - B); };
    [0, 50, 100].forEach(function (g) {
      svg.appendChild(s('line', { 'class': 'axis', x1: L, x2: W - R, y1: y(g), y2: y(g) }));
      var t = s('text', { x: L - 6, y: y(g) + 4, 'text-anchor': 'end' }); t.textContent = g + '%'; svg.appendChild(t);
    });
    [0, 0.5, 1].forEach(function (f) {
      var t = s('text', { x: x(dur * f), y: H - 8, 'text-anchor': f === 0 ? 'start' : (f === 1 ? 'end' : 'middle') }); t.textContent = mmss(Math.round(dur * f)); svg.appendChild(t);
    });
    var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + x(p.start).toFixed(1) + ' ' + y(p.percent).toFixed(1); }).join(' ');
    var lastX = x(pts[pts.length - 1].start).toFixed(1);
    svg.appendChild(s('path', { 'class': 'area', d: d + ' L' + lastX + ' ' + y(0) + ' L' + x(0) + ' ' + y(0) + ' Z' }));
    svg.appendChild(s('path', { 'class': 'line', d: d }));
    lesson.questions.forEach(function (q) {
      svg.appendChild(s('line', { 'class': 'q', x1: x(q.at_second), x2: x(q.at_second), y1: T, y2: H - B }));
      svg.appendChild(s('circle', { 'class': 'qdot', cx: x(q.at_second), cy: T + 4, r: 4 }));
    });
    return svg;
  }

  function render(rep) {
    root.querySelectorAll('.nkr-body').forEach(function (n) { n.remove(); });
    var body = el('div', { 'class': 'nkr-body' });
    var sum = rep.summary;
    body.appendChild(el('div', { 'class': 'nkr-kpis' }, [
      kpi(sum.enrolled, 'Enrolled'), kpi(sum.completed, 'Completed'), kpi(sum.completion_rate + '%', 'Completion rate')
    ]));
    rep.lessons.forEach(function (lesson) {
      body.appendChild(el('h2', { text: lesson.title }));
      if (lesson.retention.length) {
        body.appendChild(retentionChart(lesson));
        body.appendChild(el('p', { text: 'Dashed lines mark in-video questions. A steep drop just before one means learners leave rather than answer.' }));
      } else { body.appendChild(el('p', { text: 'No watch data yet.' })); }
      if (lesson.questions.length) {
        var rows = lesson.questions.map(function (q) {
          var hard = q.first_try_correct !== null && q.first_try_correct < 50;
          return el('tr', {}, [
            el('td', { text: mmss(q.at_second) }), el('td', { text: q.stem }), el('td', { text: String(q.learners) }),
            el('td', { 'class': hard ? 'nkr-hard' : '', text: q.first_try_correct === null ? '–' : q.first_try_correct + '%' + (hard ? ' (hard)' : '') })
          ]);
        });
        body.appendChild(el('table', { 'class': 'nkr-table' }, [
          el('thead', {}, [el('tr', {}, ['At', 'Question', 'Learners', 'Right first try'].map(function (h) { return el('th', { text: h }); }))]),
          el('tbody', {}, rows)
        ]));
      }
    });
    body.appendChild(el('h2', { text: 'Learners' }));
    body.appendChild(el('table', { 'class': 'nkr-table' }, [
      el('thead', {}, [el('tr', {}, ['Name', 'Lessons', 'MCQ %', 'Exam %', 'Certificate'].map(function (h) { return el('th', { text: h }); }))]),
      el('tbody', {}, rep.learners.map(function (l) {
        return el('tr', {}, [el('td', { text: l.name }), el('td', { text: l.lessons_completed + '/' + l.lessons_total }),
          el('td', { text: l.mcq_percent === null ? '–' : l.mcq_percent + '%' }), el('td', { text: l.exam_percent === null ? '–' : l.exam_percent + '%' }), el('td', { text: l.certificate || '–' })]);
      }))
    ]));
    root.appendChild(body);
  }
  function kpi(value, label) { return el('div', { 'class': 'nkr-kpi' }, [el('strong', { text: String(value) }), el('span', { text: label })]); }

  var select = el('select', { 'aria-label': 'Course' });
  var csv = el('button', { type: 'button', 'class': 'button', text: 'Download CSV' });
  var msg = el('p', { role: 'status' });
  root.appendChild(el('div', { 'class': 'nkr-bar' }, [select, csv]));
  root.appendChild(msg);

  function load(id) {
    msg.textContent = 'Loading…';
    api('/reports/course/' + id).then(function (rep) { msg.textContent = ''; render(rep); }).catch(function (e) { msg.textContent = (e && e.message) || 'Could not load the report.'; });
  }
  csv.addEventListener('click', function () {
    fetch(cfg.restUrl + '/reports/course/' + select.value + '?format=csv', { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
      .then(function (r) { return r.blob(); }).then(function (b) {
        var a = el('a', { href: URL.createObjectURL(b), download: 'course-' + select.value + '-report.csv' }); document.body.appendChild(a); a.click(); a.remove();
      });
  });
  select.addEventListener('change', function () { load(select.value); });
  api('/me/managed-courses').then(function (courses) {
    if (!courses.length) { msg.textContent = 'You do not manage any courses yet.'; return; }
    courses.forEach(function (c) { select.appendChild(el('option', { value: String(c.id), text: c.title })); });
    load(courses[0].id);
  }).catch(function (e) { msg.textContent = (e && e.message) || 'Could not load courses.'; });
})();
