/* Certificate layout editor: drag items on an A4-landscape page; positions are millimetres.
 * Writes the layout JSON into #nk-layout-json; the server re-validates everything on save. */
(function () {
  'use strict';
  var cfg = window.NimikhCertEditor, host = document.getElementById('nk-certed'), field = document.getElementById('nk-layout-json');
  if (!cfg || !host || !field) { return; }
  var T = cfg.i18n, PX = 3; // canvas pixels per millimetre
  var layout, selected = -1;
  try { layout = JSON.parse(host.getAttribute('data-layout')); } catch (e) { layout = JSON.parse(host.getAttribute('data-default')); }

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'class') { n.className = attrs[k]; } else { n.setAttribute(k, attrs[k]); }
    });
    (kids || []).forEach(function (c) { n.appendChild(c); });
    return n;
  }
  function clamp(v, a, b) { v = parseFloat(v); if (isNaN(v)) { v = a; } return Math.max(a, Math.min(b, v)); }
  function round(v) { return Math.round(v * 10) / 10; }
  function color(c) { return c === 'brand' ? '#1E4FD8' : c; }
  function save() { field.value = JSON.stringify(layout); }

  var page = el('div', { 'class': 'nk-certed__page' });
  var canvasWrap = el('div', { 'class': 'nk-certed__wrap' }, [page]);
  var panel = el('div', { 'class': 'nk-certed__panel' });
  var palette = el('div', { 'class': 'nk-certed__palette' });
  host.appendChild(canvasWrap); host.appendChild(panel);

  function fit() {
    var w = canvasWrap.clientWidth || 891, s = Math.min(1, w / (cfg.pageW * PX));
    page.style.transform = 'scale(' + s + ')';
    canvasWrap.style.height = (cfg.pageH * PX * s) + 'px';
    page.dataset.scale = s;
  }
  window.addEventListener('resize', fit);

  function label(e) {
    if (e.type === 'text') { return e.text; }
    return cfg.sample[e.type] || cfg.types[e.type];
  }

  function draw() {
    page.textContent = '';
    page.style.width = cfg.pageW * PX + 'px'; page.style.height = cfg.pageH * PX + 'px';
    page.style.background = layout.bg;
    if (layout.frame_width > 0) {
      var f = el('div', { 'class': 'nk-certed__frame' });
      var b = layout.frame_width * PX;
      f.style.cssText = 'left:' + 6 * PX + 'px;top:' + 6 * PX + 'px;width:' + (cfg.pageW - 12 - 2 * layout.frame_width) * PX + 'px;height:' + (cfg.pageH - 12 - 2 * layout.frame_width) * PX + 'px;border:' + b + 'px double ' + color(layout.frame_color);
      page.appendChild(f);
    }
    layout.elements.forEach(function (e, i) {
      var n = el('div', { 'class': 'nk-certed__item' + (i === selected ? ' is-selected' : ''), tabindex: '0', 'data-i': i, role: 'button', 'aria-label': cfg.types[e.type] });
      n.style.left = e.x * PX + 'px'; n.style.top = e.y * PX + 'px'; n.style.width = e.w * PX + 'px';
      if (e.type === 'qr') { n.style.height = e.w * PX + 'px'; n.classList.add('is-qr'); n.textContent = 'QR'; }
      else if (e.type === 'line') { n.style.borderTop = '1px solid ' + color(e.color); n.style.height = '6px'; }
      else if (e.type === 'image') { n.classList.add('is-box'); n.style.height = e.w * PX * 0.4 + 'px'; n.textContent = cfg.types.image; }
      else if (e.type === 'logo') { n.classList.add('is-box'); n.style.height = '48px'; n.textContent = cfg.types.logo; }
      else {
        n.textContent = label(e);
        n.style.fontSize = e.size + 'px'; n.style.color = color(e.color); n.style.textAlign = e.align; n.style.fontWeight = e.bold ? '700' : '400';
      }
      n.addEventListener('pointerdown', function (ev) { startDrag(ev, i, n); });
      n.addEventListener('focus', function () { if (selected !== i) { select(i, true); } });
      n.addEventListener('keydown', function (ev) { nudge(ev, i); });
      page.appendChild(n);
    });
    save();
  }

  function startDrag(ev, i, node) {
    ev.preventDefault();
    var e = layout.elements[i], s = parseFloat(page.dataset.scale) || 1, sx = ev.clientX, sy = ev.clientY, ox = e.x, oy = e.y;
    select(i, true);
    node = page.querySelector('[data-i="' + i + '"]');
    node.setPointerCapture(ev.pointerId);
    function move(m) {
      e.x = round(clamp(ox + (m.clientX - sx) / (PX * s), 0, cfg.pageW - e.w));
      e.y = round(clamp(oy + (m.clientY - sy) / (PX * s), 0, cfg.pageH - 3));
      node.style.left = e.x * PX + 'px'; node.style.top = e.y * PX + 'px';
    }
    function up() { node.removeEventListener('pointermove', move); node.removeEventListener('pointerup', up); node.removeEventListener('pointercancel', up); save(); buildPanel(); }
    node.addEventListener('pointermove', move); node.addEventListener('pointerup', up); node.addEventListener('pointercancel', up);
  }

  function nudge(ev, i) {
    var e = layout.elements[i], d = ev.shiftKey ? 5 : 1, hit = true;
    if (ev.key === 'ArrowLeft') { e.x = round(clamp(e.x - d, 0, cfg.pageW - e.w)); }
    else if (ev.key === 'ArrowRight') { e.x = round(clamp(e.x + d, 0, cfg.pageW - e.w)); }
    else if (ev.key === 'ArrowUp') { e.y = round(clamp(e.y - d, 0, cfg.pageH)); }
    else if (ev.key === 'ArrowDown') { e.y = round(clamp(e.y + d, 0, cfg.pageH)); }
    else if (ev.key === 'Delete' || ev.key === 'Backspace') { layout.elements.splice(i, 1); selected = -1; hit = true; draw(); buildPanel(); ev.preventDefault(); return; }
    else { hit = false; }
    if (hit) { ev.preventDefault(); draw(); buildPanel(); var n = page.querySelector('[data-i="' + i + '"]'); if (n) { n.focus(); } }
  }

  function select(i, keepFocus) { selected = i; if (!keepFocus) { draw(); } else { Array.prototype.forEach.call(page.children, function (c) { c.classList.toggle('is-selected', c.getAttribute('data-i') === String(i)); }); } buildPanel(); }

  function input(labelText, key, type, e, extra) {
    var id = 'nk-ce-' + key;
    var inp = el('input', Object.assign({ id: id, type: type, value: e[key] === undefined ? '' : e[key] }, extra || {}));
    inp.addEventListener('input', function () {
      var v = inp.value;
      if (type === 'number') { v = clamp(v, parseFloat(inp.min), parseFloat(inp.max)); }
      e[key] = v; draw();
    });
    return el('p', {}, [el('label', { 'for': id, text: labelText }), inp]);
  }

  function buildPanel() {
    panel.textContent = '';
    palette.textContent = '';
    Object.keys(cfg.types).forEach(function (t) {
      var b = el('button', { type: 'button', 'class': 'button', text: T.add + ' ' + cfg.types[t] });
      b.addEventListener('click', function () {
        var base = { type: t, x: 20, y: 20, w: t === 'qr' ? 30 : 100, size: 18, color: '#0F172A', align: 'center', bold: false };
        if (t === 'text') { base.text = cfg.types.text; }
        if (t === 'image') { base.src = 'https://'; base.w = 40; }
        layout.elements.push(base); selected = layout.elements.length - 1; draw(); buildPanel();
      });
      palette.appendChild(b);
    });
    panel.appendChild(el('h4', { text: T.add }));
    panel.appendChild(palette);

    var bg = input(T.background, 'bg', 'color', layout);
    var fr = input(T.frame, 'frame_width', 'number', layout, { min: 0, max: 8, step: '0.5' });
    panel.appendChild(bg); panel.appendChild(fr);

    var e = layout.elements[selected];
    if (!e) { panel.appendChild(el('p', { 'class': 'description', text: T.select })); }
    else {
      panel.appendChild(el('h4', { text: cfg.types[e.type] }));
      if (e.type === 'text') { panel.appendChild(input(T.text, 'text', 'text', e, { maxlength: 200 })); }
      if (e.type === 'image') { panel.appendChild(input(T.src, 'src', 'url', e)); }
      panel.appendChild(input(T.x, 'x', 'number', e, { min: 0, max: cfg.pageW, step: '0.5' }));
      panel.appendChild(input(T.y, 'y', 'number', e, { min: 0, max: cfg.pageH, step: '0.5' }));
      panel.appendChild(input(T.w, 'w', 'number', e, { min: 3, max: cfg.pageW, step: '0.5' }));
      if (e.type !== 'qr' && e.type !== 'image' && e.type !== 'logo') {
        if (e.type !== 'line') { panel.appendChild(input(T.size, 'size', 'number', e, { min: 6, max: 120 })); }
        var brand = el('input', { type: 'checkbox', id: 'nk-ce-brand' }); brand.checked = e.color === 'brand';
        brand.addEventListener('change', function () { e.color = brand.checked ? 'brand' : '#0F172A'; draw(); buildPanel(); });
        panel.appendChild(el('p', {}, [brand, el('label', { 'for': 'nk-ce-brand', text: ' ' + T.brand })]));
        if (e.color !== 'brand') { panel.appendChild(input(T.color, 'color', 'color', e)); }
        var sel = el('select', { id: 'nk-ce-align' });
        ['left', 'center', 'right'].forEach(function (a) { var o = el('option', { value: a, text: T[a] }); if (e.align === a) { o.selected = true; } sel.appendChild(o); });
        sel.addEventListener('change', function () { e.align = sel.value; draw(); });
        panel.appendChild(el('p', {}, [el('label', { 'for': 'nk-ce-align', text: T.align }), sel]));
        var bold = el('input', { type: 'checkbox', id: 'nk-ce-bold' }); bold.checked = !!e.bold;
        bold.addEventListener('change', function () { e.bold = bold.checked; draw(); });
        panel.appendChild(el('p', {}, [bold, el('label', { 'for': 'nk-ce-bold', text: ' ' + T.bold })]));
      }
      var rm = el('button', { type: 'button', 'class': 'button', text: T.remove });
      rm.addEventListener('click', function () { layout.elements.splice(selected, 1); selected = -1; draw(); buildPanel(); });
      panel.appendChild(rm);
    }
    var reset = el('button', { type: 'button', 'class': 'button-link-delete', text: T.reset });
    reset.addEventListener('click', function () { layout = JSON.parse(host.getAttribute('data-default')); selected = -1; draw(); buildPanel(); });
    panel.appendChild(el('p', {}, [reset]));
  }

  draw(); buildPanel(); fit();
  host.closest('form') && host.closest('form').addEventListener('submit', save);
})();
