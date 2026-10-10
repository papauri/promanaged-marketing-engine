/* Shell behaviour: drop-down menus, and sections that fold. Nothing here is needed to use the app: links work without it. */
(function () {
  'use strict';

  /* ---- drop-down menus (business switcher, main navigation) ---- */
  function closeAll(except) {
    document.querySelectorAll('.dd.open').forEach(function (d) {
      if (d === except) return;
      d.classList.remove('open');
      var b = d.querySelector('.ddbtn');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.ddbtn') : null;
    if (b) {
      var d = b.closest('.dd');
      var on = !d.classList.contains('open');
      closeAll(d);
      d.classList.toggle('open', on);
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
      e.preventDefault();
      return;
    }
    if (!(e.target.closest && e.target.closest('.dd .menu'))) closeAll();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var open = document.querySelector('.dd.open > .ddbtn');
      closeAll();
      if (open) open.focus();
    }
  });

  /* ---- type-ahead for "find a business": businesses we know at once, then a web search after a pause (server: ?suggest=) ---- */
  function typeahead(inp) {
    var form = inp.form;
    var city = form ? form.querySelector('input[name=city]') : null;
    var box = document.createElement('div');
    box.className = 'suggest';
    inp.parentNode.insertBefore(box, inp);
    box.appendChild(inp);
    var list = document.createElement('ul');
    list.className = 'suglist';
    list.id = 'sug' + Math.random().toString(36).slice(2, 8);
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    box.appendChild(list);
    inp.setAttribute('role', 'combobox');
    inp.setAttribute('aria-autocomplete', 'list');
    inp.setAttribute('aria-controls', list.id);
    inp.setAttribute('aria-expanded', 'false');
    inp.setAttribute('autocomplete', 'off');
    var local = [], web = [], status = '', active = -1, tL = 0, tW = 0, cL = null, cW = null, shown = [];
    function brand() {
      var b = form && form.querySelector('[name=brand]');
      return b && b.value ? b.value : (new URLSearchParams(location.search).get('brand') || '');
    }
    function url(q, mode) { return '?suggest=' + encodeURIComponent(q) + '&mode=' + mode + '&brand=' + encodeURIComponent(brand()); }
    function close() {
      list.hidden = true;
      active = -1;
      inp.setAttribute('aria-expanded', 'false');
    }
    function row(r, i) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.id = list.id + '-' + i;
      var b = document.createElement('b');
      b.textContent = r.name;
      var s = document.createElement('small');
      s.textContent = r.meta || '';
      li.appendChild(b);
      li.appendChild(s);
      li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(i); });
      return li;
    }
    function render() {
      while (list.firstChild) list.removeChild(list.firstChild);
      shown = [];
      var key = function (r) { return (r.name + '|' + (r.city || '')).toLowerCase(); };
      var seen = {};
      local.forEach(function (r) { seen[key(r)] = 1; });
      var more = web.filter(function (r) { return !seen[key(r)]; });
      function head(t) {
        var h = document.createElement('li');
        h.className = 'head';
        h.setAttribute('role', 'presentation');
        h.textContent = t;
        list.appendChild(h);
      }
      if (local.length) {
        head('Businesses you know');
        local.forEach(function (r) { shown.push(r); list.appendChild(row(r, shown.length - 1)); });
      }
      if (more.length) {
        head('From a web search: check before you use');
        more.forEach(function (r) { shown.push(r); list.appendChild(row(r, shown.length - 1)); });
      }
      if (status) {
        var n = document.createElement('li');
        n.className = 'note';
        n.setAttribute('role', 'presentation');
        n.textContent = status;
        list.appendChild(n);
      }
      var open = (shown.length > 0 || status !== '') && document.activeElement === inp; // answers that arrive after the box lost focus wait for it to get focus back
      list.hidden = !open;
      inp.setAttribute('aria-expanded', open ? 'true' : 'false');
      active = -1;
    }
    function mark() {
      Array.prototype.forEach.call(list.querySelectorAll('li[role=option]'), function (li, i) { li.classList.toggle('on', i === active); });
      if (active >= 0) {
        inp.setAttribute('aria-activedescendant', list.id + '-' + active);
        var el = document.getElementById(list.id + '-' + active);
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
      } else {
        inp.removeAttribute('aria-activedescendant');
      }
    }
    function choose(i) {
      var r = shown[i];
      if (!r) return;
      if (r.href) { location.href = r.href; return; }
      inp.value = r.name;
      if (city && r.city && !city.value) city.value = r.city;
      close();
      inp.focus();
    }
    function ask(q, mode, ctl, done) {
      if (!window.fetch) return;
      fetch(url(q, mode), { credentials: 'same-origin', signal: ctl.signal, headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { rows: [] }; })
        .then(function (j) { if (inp.value.trim() === q) done((j && j.rows) || []); })
        .catch(function () { if (inp.value.trim() === q) done(null); });
    }
    inp.addEventListener('input', function () {
      var q = inp.value.trim();
      clearTimeout(tL);
      clearTimeout(tW);
      if (cL) cL.abort();
      if (cW) cW.abort();
      web = [];
      status = '';
      if (q.length < 2) { local = []; render(); return; }
      tL = setTimeout(function () {
        cL = window.AbortController ? new AbortController() : { abort: function () {}, signal: undefined };
        ask(q, 'local', cL, function (rows) { local = rows || []; render(); });
      }, 120);
      if (inp.dataset.web && q.length >= 4) {
        tW = setTimeout(function () {
          cW = window.AbortController ? new AbortController() : { abort: function () {}, signal: undefined };
          status = 'Searching the web…';
          render();
          ask(q, 'web', cW, function (rows) { web = rows || []; status = rows === null ? 'The web search did not answer.' : (web.length ? '' : 'Nothing more found on the web.'); render(); });
        }, 800);
      }
    });
    inp.addEventListener('keydown', function (e) {
      if (list.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(shown.length - 1, active + 1); mark(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(-1, active - 1); mark(); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
      else if (e.key === 'Escape' || e.key === 'Tab') close();
    });
    inp.addEventListener('blur', function () { setTimeout(close, 120); });
    inp.addEventListener('focus', function () { if (shown.length || status) { list.hidden = false; inp.setAttribute('aria-expanded', 'true'); } });
  }
  Array.prototype.forEach.call(document.querySelectorAll('input[data-suggest]'), typeahead);

  /* ---- sections that fold: every card that starts with a heading becomes a drop-down; open/closed is remembered per page ---- */
  var page = (location.search || '').replace(/([?&])brand=[^&]*&?/g, '$1').replace(/[?&]$/, '') + '|' + (location.pathname.split('/').pop() || '');
  function store(key, val) {
    try { if (val === undefined) return localStorage.getItem('pm_sec2:' + key); localStorage.setItem('pm_sec2:' + key, val); } catch (e) { return null; }
    return null;
  }
  function fold(card) {
    var h = card.firstElementChild;
    if (!h || h.tagName !== 'H2' || card.classList.contains('nofold') || card.closest('details') || card.closest('.lead') || card.closest('.sticky') || card.dataset.fold === 'off') return;
    var title = (h.textContent || '').trim();
    if (!title) return;
    var d = document.createElement('details');
    Array.prototype.forEach.call(card.attributes, function (a) { if (a.name !== 'class' && a.name !== 'id') d.setAttribute(a.name, a.value); });
    d.className = card.className + ' sec';
    if (card.id) { d.id = card.id; card.removeAttribute('id'); }
    var s = document.createElement('summary');
    s.innerHTML = h.innerHTML;
    var body = document.createElement('div');
    body.className = 'secbody';
    h.remove();
    while (card.firstChild) body.appendChild(card.firstChild);
    d.appendChild(s);
    d.appendChild(body);
    var key = page + '|' + title.toLowerCase().slice(0, 60);
    var saved = store(key);
    var startClosed = card.dataset.fold === 'closed';
    d.open = saved === null ? !startClosed : saved === '1';
    s.addEventListener('click', function () { setTimeout(function () { store(key, d.open ? '1' : '0'); }, 0); }); // only a person's click is remembered, never the starting state
    card.replaceWith(d);
  }
  function run() {
    document.querySelectorAll('main .card').forEach(function (c) { if (c.tagName === 'DIV') fold(c); });
    // a link to #something opens the section that holds it
    if (location.hash) {
      var t = document.getElementById(location.hash.slice(1));
      if (t) { var p = t.closest('details'); while (p) { p.open = true; p = p.parentElement ? p.parentElement.closest('details') : null; } t.scrollIntoView(); }
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  window.addEventListener('hashchange', run);
})();
