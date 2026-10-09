/* Shared behaviour: a loader while any task runs (so two tasks cannot clash), and live progress while the agents search. */
(function () {
  var busy = document.getElementById('busy');
  var msgEl = document.getElementById('busyMsg');
  var timeEl = document.getElementById('busyTime');
  var timer = null, started = 0;

  var MSG = {
    lookup: 'Searching the web for this business',
    splan: 'Planning posts and drawing the pictures', publish: 'Publishing to Facebook', approve: 'Approving and drawing the picture', approve_all: 'Approving and drawing the pictures', approve_selected: 'Approving and drawing the pictures', hold: 'Holding back', request_approval: 'Sending for approval', request_changes: 'Sending the note', mark_posted: 'Saving', ig_url_test: 'Checking the Instagram picture address', redraw: 'Drawing the picture', draft_reply: 'Writing a reply', reply: 'Posting the reply', social_check: 'Checking the Facebook connection',
    kit: 'Making the profile pictures and covers', apply_profile: 'Updating the Facebook profile picture', apply_cover: 'Updating the Facebook cover',
    research: 'Studying their website, social pages and the news',
    audit: 'Auditing the Facebook Page', judge: 'Checking everything people put on the Page', group: 'Working through the list on Facebook', item: 'Doing it on Facebook', playbook: 'Working out who to engage today', cleanup: 'Reviewing every post on the Page', cleanup_apply: 'Changing the Page', ads_plan: 'Planning targeted ads', ads_create: 'Creating the campaign in Ads Manager (paused)', autopilot_now: 'Running the autopilot', capture: 'Looking for buyers', profile_apply: 'Updating the Page profile', connect: 'Connecting to Facebook', draft_message: 'Writing an answer', message: 'Sending the answer', edit: 'Updating the post', delete: 'Deleting', delete_comment: 'Deleting the comment', hide: 'Hiding the comment', unhide: 'Showing the comment', like: 'Liking', unlike: 'Removing the like',
    run: 'Starting the agents', find_names: 'Starting the name search',
    polish: 'Writing a tailored proposal',
    check_replies: 'Checking your inbox for replies',
    draft_outreach: 'Writing the outreach',
    paste_reply: 'Reading their reply',
    director: 'Reviewing the pipeline',
    refresh_models: 'Refreshing the model list',
    attach_unmatched: 'Reading the reply', dismiss_unmatched: 'Dismissing', approve_send: 'Approving for sending', unapprove_send: 'Updating the send queue',
    send: 'Sending the email', send_reply: 'Sending the reply', email: 'Creating the proposal and sending it',
    download: 'Creating the PDF', smtp_check: 'Logging in to the mail server',
    save_draft: 'Saving', save_reply: 'Saving', config: 'Saving', proposal: 'Preparing the proposal'
  };

  function show(text, auto) {
    if (!busy) return;
    msgEl.textContent = text + '…';
    busy.classList.add('on');
    started = Date.now();
    timeEl.textContent = '';
    clearInterval(timer);
    timer = setInterval(function () {
      var s = Math.round((Date.now() - started) / 1000);
      timeEl.textContent = s >= 3 ? s + ' seconds' : '';
    }, 1000);
    if (auto) setTimeout(hide, auto); // downloads leave the page where it is, so hide the loader again
  }
  function hide() {
    if (!busy) return;
    busy.classList.remove('on');
    clearInterval(timer);
    document.querySelectorAll('form[data-busy]').forEach(function (f) {
      delete f.dataset.busy;
      f.querySelectorAll('button[disabled][data-was]').forEach(function (b) { b.disabled = false; b.removeAttribute('data-was'); });
    });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || f.method !== 'post' || f.hasAttribute('data-quiet')) return;
    var sub = e.submitter;
    if (sub && sub.formTarget === '_blank') return;      // previews open in a new tab
    if (f.dataset.busy) { e.preventDefault(); return; }  // already submitted: ignore double clicks
    var d = (sub && sub.name === 'do' && sub.value) || (f.querySelector('[name=do]') || {}).value || (f.querySelector('[name=action]') || {}).value || '';
    var text = MSG[d] || 'Working';
    var nm = f.querySelector('input[name=name]');
    if (d === 'lookup' && nm && nm.value.trim()) text = 'Searching the web for "' + nm.value.trim() + '"';
    f.dataset.busy = '1';
    setTimeout(function () { f.querySelectorAll('button').forEach(function (b) { if (!b.disabled) { b.disabled = true; b.setAttribute('data-was', '1'); } }); }, 0);
    show(text, (d === 'download' || d === 'preview') ? 5000 : 0);
  }, false);

  function toast(m) {                                         // small message at the bottom of the screen
    var t = document.createElement('div');
    t.className = 'sx-toast'; t.textContent = m; document.body.appendChild(t);
    setTimeout(function () { t.classList.add('on'); }, 10);
    setTimeout(function () { t.classList.remove('on'); setTimeout(function () { t.remove(); }, 300); }, 1600);
  }
  window.pmToast = toast;

  document.addEventListener('click', function (e) {          // "Copy" buttons: data-copy="text" or data-copy-target="#id" (a box's value or text)
    var b = e.target.closest('[data-copy],[data-copy-target]');
    if (!b) return;
    var t = b.getAttribute('data-copy'), old = b.textContent;
    if (t === null) {
      var el = document.querySelector(b.getAttribute('data-copy-target'));
      t = el ? (typeof el.value === 'string' ? el.value : el.textContent) : '';
    }
    var done = function () { toast('Copied'); if (b.tagName === 'BUTTON') { b.textContent = 'Copied'; setTimeout(function () { b.textContent = old; }, 1500); } };
    if (navigator.clipboard) { navigator.clipboard.writeText(t).then(done); }
    else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); done(); }
  });

  document.addEventListener('change', function (e) {         // files bigger than the server accepts are refused before uploading
    var f = e.target;
    if (!f || f.type !== 'file' || !f.getAttribute('data-max-mb') || !f.files || !f.files[0]) return;
    var max = parseFloat(f.getAttribute('data-max-mb'));
    if (f.files[0].size > max * 1024 * 1024) {
      alert('That file is ' + (f.files[0].size / 1048576).toFixed(1) + ' MB. This server accepts at most ' + max + ' MB. Use a smaller file.');
      f.value = '';
    }
  });

  document.addEventListener('click', function (e) {          // "Request changes": ask for the note first
    var b = e.target.closest('[data-ask]');
    if (!b || !b.form) return;
    var note = prompt('What should change?', '');
    if (!note || !note.trim()) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    var h = b.form.querySelector('input[name=' + b.getAttribute('data-ask') + ']');
    if (h) h.value = note.trim();
  }, true);

  document.addEventListener('change', function (e) {         // post selection: "select all" and the live count in the action bar
    var all = e.target.closest('[data-pick-all]');
    var sel = all ? all.getAttribute('data-pick-all') : (e.target.matches('.sx-pick') ? '.sx-pick' : '');
    if (!sel) return;
    if (all) document.querySelectorAll(sel).forEach(function (c) { c.checked = all.checked; });
    document.querySelectorAll('[data-pick-count]').forEach(function (n) {
      var k = document.querySelectorAll(n.getAttribute('data-pick-count') + ':checked').length;
      n.textContent = k + ' selected';
      n.parentNode.classList.toggle('on', k > 0);
    });
  });

  document.addEventListener('click', function (e) {          // "Copy" from a box next to the button
    var b = e.target.closest('[data-copy-from]');
    if (!b) return;
    var src = b.closest('.card, form') && b.closest('.card, form').querySelector(b.getAttribute('data-copy-from'));
    if (src) { b.setAttribute('data-copy', src.value); }
  }, true);

  document.addEventListener('click', function (e) {          // "Reply on WhatsApp": log the answer, then WhatsApp opens with it ready
    var a = e.target.closest('[data-wa-log]');
    if (!a) return;
    var scope = a.closest('.replybox, .repcard'), ta = scope && scope.querySelector('textarea[name=whatsapp]');
    var text = ta ? ta.value.trim() : '';
    if (!text) { e.preventDefault(); alert('Write the answer first.'); return; }
    a.href = a.getAttribute('data-url').split('?text=')[0] + '?text=' + encodeURIComponent(text);   // link opens in a new tab as normal
    var fd = new FormData();
    fd.append('csrf', a.getAttribute('data-csrf')); fd.append('action', 'wa_log'); fd.append('id', a.getAttribute('data-id')); fd.append('text', text); fd.append('reply', '1');
    fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) { alert((j && j.error) || 'Could not log the reply.'); return; }
      if (j.warn) alert('Logged. A note on the wording: ' + j.warn);
      a.textContent = 'Logged'; if (scope) scope.style.opacity = '.55';
    }).catch(function () { alert('Network problem: the reply was not logged. Reload and press Reply on WhatsApp again.'); });
  });

  document.addEventListener('click', function (e) {          // "Put in email / WhatsApp": a research starter goes into this lead's message box
    var b = e.target.closest('[data-fill]');
    if (!b) return;
    var card = b.closest('.lead'), f = card && card.querySelector('form.msg');
    if (!f) return;
    var box = f.querySelector(b.getAttribute('data-fill') === 'email' ? 'textarea[name=email_body]' : 'textarea[name=whatsapp]');
    if (box.value.trim() !== '' && !confirm('Replace the message that is in the box now?')) return;
    box.value = b.getAttribute('data-text');
    var subj = f.querySelector('input[name=email_subject]');
    if (b.getAttribute('data-fill') === 'email' && subj && b.getAttribute('data-subject')) subj.value = b.getAttribute('data-subject');
    box.classList.add('filled'); box.focus();
    setTimeout(function () { box.classList.remove('filled'); }, 1600);
    var save = f.querySelector('.btns .btn'); if (save) { save.textContent = 'Save edits (new message)'; save.classList.add('primary'); }
  });

  document.addEventListener('click', function (e) {          // live Page refresh; buttons that ask first
    var r = e.target.closest('[data-reframe]');
    if (r) { var f = r.closest('aside').querySelector('iframe'); f.src = f.src || f.getAttribute('data-src'); return; }
    var c = e.target.closest('[data-confirm]');
    if (c && !confirm(c.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
  }, true);

  // The live Facebook Page fills the window height; Facebook's own timeline scrolls inside it.
  var fitPlug = function (force) {
    document.querySelectorAll('iframe.fbplug').forEach(function (f) {
      var stacked = window.innerWidth <= 1180;
      var h = stacked ? 700 : Math.max(420, Math.min(2000, window.innerHeight - 190));
      var w = Math.min(500, Math.max(180, Math.floor(f.parentNode.clientWidth - 4)));
      if (!force && f.getAttribute('data-h') === String(h) && f.getAttribute('data-w') === String(w)) return;
      f.setAttribute('data-h', h); f.setAttribute('data-w', w);
      f.style.height = h + 'px';
      f.src = f.getAttribute('data-src').replace(/height=\d+/, 'height=' + h).replace(/width=\d+/, 'width=' + w);
    });
  };
  fitPlug(true);
  var fitT; window.addEventListener('resize', function () { clearTimeout(fitT); fitT = setTimeout(function () { fitPlug(false); }, 400); });

  // Landing on #l<id>: open that lead and bring it into view.
  if (location.hash.indexOf('#l') === 0) {
    var tgt = document.getElementById(location.hash.slice(1));
    if (tgt && tgt.tagName === 'DETAILS') { tgt.open = true; setTimeout(function () { tgt.scrollIntoView({ block: 'start' }); window.scrollBy(0, -70); }, 30); }
  }

  // AI message composer: fills the message boxes in place, no page reload, so you stay on the business.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.aigen');
    if (!b) return;
    var box = b.closest('.composer'), msg = box.querySelector('.cmsg'), old = b.textContent;
    var fd = new FormData();
    fd.append('csrf', box.dataset.csrf); fd.append('action', 'compose'); fd.append('id', box.dataset.id); fd.append('channel', b.dataset.channel);
    box.querySelectorAll('select[data-k]').forEach(function (s) { fd.append(s.dataset.k, s.value); });
    var card = box.closest('.wacard');
    if (card) fd.append('signed', '1');
    box.querySelectorAll('.aigen').forEach(function (x) { x.disabled = true; });
    b.textContent = 'Writing…'; msg.textContent = '';
    fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { msg.textContent = j.error || 'Could not write it.'; return; }
      var scope = card || box.closest('form') || document;
      if (b.dataset.channel === 'email') {
        var sj = scope.querySelector('[name=email_subject]'), bd = scope.querySelector('[name=email_body]');
        if (sj) sj.value = j.subject; if (bd) bd.value = j.body;
      } else {
        var ta = card ? card.querySelector('textarea') : scope.querySelector('[name=whatsapp]');
        if (ta) ta.value = j.body;
      }
      msg.textContent = j.words + ' words. Read it, then ' + (card ? 'send.' : 'press Save edits.');
    }).catch(function () { msg.textContent = 'Network problem. Try again.'; }).then(function () {
      b.textContent = old; box.querySelectorAll('.aigen').forEach(function (x) { x.disabled = false; });
    });
  });

  // Heat maps (table.heat): each td[data-v] is shaded by its value relative to the largest in the table.
  document.querySelectorAll('table.heat').forEach(function (t) {
    var cells = t.querySelectorAll('td[data-v]'), max = 0;
    cells.forEach(function (c) { max = Math.max(max, parseFloat(c.getAttribute('data-v')) || 0); });
    cells.forEach(function (c) { c.style.setProperty('--v', max > 0 ? ((parseFloat(c.getAttribute('data-v')) || 0) / max).toFixed(2) : 0); });
  });

  window.addEventListener('pageshow', hide);              // back button: never leave the loader stuck

  // Live progress while the agents search.
  var rb = document.getElementById('runbar');
  if (rb && rb.dataset.running === '1') {
    var fill = document.getElementById('runfill'), sub = document.getElementById('runsub');
    var tick = function () {
      fetch('index.php?runstatus=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (s) {
        if (s.state !== 'running') { location.reload(); return; }
        if (sub) sub.textContent = (s.phase || 'Working') + (s.new_leads ? ' · ' + s.new_leads + ' new leads so far' : '');
        if (fill) fill.style.width = Math.max(6, Math.round(100 * (s.step || 0) / Math.max(1, s.steps || 7))) + '%';
        setTimeout(tick, 2000);
      }).catch(function () { setTimeout(tick, 4000); });
    };
    setTimeout(tick, 1500);
  }
})();
