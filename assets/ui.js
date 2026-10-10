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
