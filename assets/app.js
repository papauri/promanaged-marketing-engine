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
    research: 'Studying their website, social pages and the news (about a minute; wait here)',
    audit: 'Auditing the Facebook Page', judge: 'Checking everything people put on the Page', group: 'Working through the list on Facebook', item: 'Doing it on Facebook', playbook: 'Working out who to engage today', cleanup: 'Reviewing every post on the Page', cleanup_apply: 'Changing the Page', ads_plan: 'Planning targeted ads', ads_create: 'Creating the campaign in Ads Manager (paused)', autopilot_now: 'Running the autopilot', capture: 'Looking for buyers', profile_apply: 'Updating the Page profile', connect: 'Connecting to Facebook', draft_message: 'Writing an answer', message: 'Sending the answer', edit: 'Updating the post', delete: 'Deleting', delete_comment: 'Deleting the comment', hide: 'Hiding the comment', unhide: 'Showing the comment', like: 'Liking', unlike: 'Removing the like',
    run: 'Starting the agents', find_names: 'Starting the name search', sync: 'Comparing your Sent folders with the leads', move_to: 'Moving the ticked leads', turn_down: 'Telling the Writers why and writing them again', sitdown: 'The team is sitting down over this lead', folder_add: 'Filing the ticked leads',
    ex_meet: 'The department heads are meeting about the ad', ex_chat: 'Changing the ad and drawing it again', ex_save: 'Drawing the ad again', ex_undo: 'Drawing the ad again', ex_approve: 'Approving and putting it in the plan',
    polish: 'Writing a tailored proposal',
    check_replies: 'Checking your inbox for replies',
    draft_outreach: 'Writing the outreach',
    paste_reply: 'Reading their reply',
    director: 'Reviewing the pipeline',
    refresh_models: 'Refreshing the model list',
    attach_unmatched: 'Reading the reply', dismiss_unmatched: 'Dismissing', approve_send: 'Putting the ticked emails in the send queue', send_now: 'Sending the ticked emails, one after another', delete_ticked: 'Deleting the ticked leads', unapprove_send: 'Updating the send queue',
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

  document.addEventListener('click', function (e) {          // "New wording" on any text box: data-reword="<the box>" data-kind="post|message|email"; a second press puts the old words back
    var b = e.target.closest('[data-reword]');
    if (!b) return;
    e.preventDefault();
    var scope = b.closest('form, .card'), ta = scope && scope.querySelector(b.getAttribute('data-reword'));
    if (!ta) return;
    b.dataset.label = b.dataset.label || b.textContent;
    if (b.dataset.undo === '1') { ta.value = b.dataset.prev; b.dataset.undo = ''; b.textContent = b.dataset.label; return; }
    var note = prompt('Anything to change? Leave it empty for a fresh version.', '');
    if (note === null) return;
    var c = scope.querySelector('input[name=csrf]') || document.querySelector('input[name=csrf]');
    var fd = new FormData();
    fd.append('csrf', c ? c.value : ''); fd.append('action', 'reword_text'); fd.append('text', ta.value); fd.append('kind', b.getAttribute('data-kind') || 'message'); fd.append('note', note.trim());
    b.disabled = true; b.textContent = 'Writing…';
    fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not reword it.'); b.textContent = b.dataset.label; return; }
      b.dataset.prev = ta.value; ta.value = j.text; ta.dispatchEvent(new Event('input', { bubbles: true }));
      b.dataset.undo = '1'; b.textContent = 'Undo new wording';
    }).catch(function () { toast('Network problem. Try again.'); b.textContent = b.dataset.label; }).then(function () { b.disabled = false; });
  });

  document.addEventListener('click', function (e) {          // the WhatsApp test uses the saved words: ask for a save first when the box was changed
    var a = e.target.closest('[data-wa-saved]');
    if (!a) return;
    var ta = a.closest('form') && a.closest('form').querySelector('textarea[name=whatsapp]');
    if (ta && ta.value.trim() !== a.getAttribute('data-wa-saved').trim()) { e.preventDefault(); toast('Press Save edits first, then test the new words.'); }
  }, true);

  document.addEventListener('click', function (e) {          // "Suggest the words with AI": fills the boxes marked data-sg in the same form; a second press puts the old words back
    var b = e.target.closest('[data-aiwords]');
    if (!b) return;
    e.preventDefault();
    var form = b.closest('form'), boxes = form ? form.querySelectorAll('[data-sg]') : [];
    if (!boxes.length) return;
    b.dataset.label = b.dataset.label || b.textContent;
    if (b.dataset.undo === '1') { var old = JSON.parse(b.dataset.prev || '{}'); boxes.forEach(function (x) { if (old[x.dataset.sg] !== undefined) x.value = old[x.dataset.sg]; }); b.dataset.undo = ''; b.textContent = b.dataset.label; return; }
    var note = prompt('Anything to stress? Leave it empty to let the AI choose.', '');
    if (note === null) return;
    var c = form.querySelector('input[name=csrf]') || document.querySelector('input[name=csrf]'), fd = new FormData(), prev = {};
    fd.append('csrf', c ? c.value : ''); fd.append('action', 'suggest_words'); fd.append('kind', b.getAttribute('data-aiwords')); fd.append('note', note.trim());
    boxes.forEach(function (x) { prev[x.dataset.sg] = x.value; fd.append('f[' + x.dataset.sg + ']', x.value); });
    b.disabled = true; b.textContent = 'Writing…';
    fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { toast(j.error || 'Could not write them.'); b.textContent = b.dataset.label; return; }
      boxes.forEach(function (x) { if (typeof j.fields[x.dataset.sg] === 'string') { x.value = j.fields[x.dataset.sg]; x.dispatchEvent(new Event('input', { bubbles: true })); } });
      b.dataset.prev = JSON.stringify(prev); b.dataset.undo = '1'; b.textContent = 'Undo the suggestion'; toast('Suggested. Read them, then save.');
    }).catch(function () { toast('Network problem. Try again.'); b.textContent = b.dataset.label; }).then(function () { b.disabled = false; });
  });

  document.addEventListener('click', function (e) {          // WhatsApp and email links that take the words from the box next to them (as edited)
    var a = e.target.closest('[data-wa-open],[data-mail-open]');
    if (!a) return;
    var ta = a.closest('form, .card') && a.closest('form, .card').querySelector('textarea[name=text]'), t = ta ? ta.value : '';
    if (a.hasAttribute('data-wa-open')) { a.href = a.getAttribute('data-wa-open') + '?text=' + encodeURIComponent(t); }
    else { a.href = 'mailto:' + a.getAttribute('data-mail-open') + '?subject=' + encodeURIComponent(a.getAttribute('data-subject') || '') + '&body=' + encodeURIComponent(t); }
  }, true);

  document.addEventListener('click', function (e) {          // "New wording": an optional note (empty = just a fresh version; Cancel = do nothing)
    var b = e.target.closest('[data-ask-opt]');
    if (!b || !b.form) return;
    var note = prompt('Anything to change? Leave it empty for a fresh version.', '');
    if (note === null) { e.preventDefault(); e.stopImmediatePropagation(); return; }
    var h = b.form.querySelector('input[name=' + b.getAttribute('data-ask-opt') + ']');
    if (h) h.value = note.trim();
  }, true);

  document.addEventListener('change', function (e) {         // post selection: "select all" and the live count in the action bar
    var all = e.target.closest('[data-pick-all]');
    var sel = all ? all.getAttribute('data-pick-all') : (e.target.matches('.sx-pick') ? '.sx-pick' : (e.target.matches('.lsel') ? '.lsel' : ''));
    if (!sel) return;
    if (all) document.querySelectorAll(sel).forEach(function (c) { c.checked = all.checked; });
    document.querySelectorAll('[data-pick-count]').forEach(function (n) {
      var k = document.querySelectorAll(n.getAttribute('data-pick-count') + ':checked').length;
      n.textContent = k + (n.closest('.selbar') ? ' ticked' : ' selected');
      n.parentNode.classList.toggle('on', k > 0);
    });
  });

  document.addEventListener('click', function (e) {          // "Copy" from a box next to the button
    var b = e.target.closest('[data-copy-from]');
    if (!b) return;
    var src = b.closest('.card, form') && b.closest('.card, form').querySelector(b.getAttribute('data-copy-from'));
    if (src) { b.setAttribute('data-copy', src.value); }
  }, true);

  document.addEventListener('click', function (e) {          // "+ link" buttons: add data-ins (a name and its link) on a new line at the end of the WhatsApp box in the same form or card
    var b = e.target.closest('[data-ins]');
    if (!b) return;
    var box = b.closest('form, .card') && b.closest('form, .card').querySelector('textarea[name=whatsapp]');
    if (!box) return;
    var add = b.getAttribute('data-ins');
    if (box.value.indexOf(add) === -1) { box.value = (box.value.replace(/\s+$/, '') + '\n' + add).replace(/^\n/, ''); }
    box.focus();
  });

  document.addEventListener('click', function (e) {          // "Reply on WhatsApp": log the answer, then WhatsApp opens with it ready
    var a = e.target.closest('[data-wa-log]');
    if (!a) return;
    var scope = a.closest('.replybox, .repcard'), ta = scope && scope.querySelector('textarea[name=whatsapp]');
    var text = ta ? ta.value.trim() : (a.getAttribute('data-text') || '').trim();   // a lead card's button carries its message
    if (!text) { e.preventDefault(); alert('Write the answer first.'); return; }
    var url = a.getAttribute('data-url').split('?text=')[0] + '?text=' + encodeURIComponent(text);
    var first = a.hasAttribute('data-first'), w = null;
    if (first) { e.preventDefault(); w = window.open('about:blank', '_blank'); }   // a first message opens only once the checks pass (limit, STOP, wording)
    else { a.href = url; }                                                          // an answer opens at once, as a normal link
    var fd = new FormData();
    fd.append('csrf', a.getAttribute('data-csrf')); fd.append('action', 'wa_log'); fd.append('id', a.getAttribute('data-id')); fd.append('text', text); if (!first) fd.append('reply', '1');
    fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) { if (w) w.close(); alert((j && j.error) || 'Could not log the message.'); return; }
      if (w) { w.opener = null; w.location.href = url; }
      if (j.warn) alert('Logged. A note on the wording: ' + j.warn);
      a.textContent = 'Logged'; if (scope) scope.style.opacity = '.55';
    }).catch(function () { if (w) w.close(); alert('Network problem: nothing was logged. Reload and try again.'); });
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
      var fm = scope.querySelector('input[name=fmt]'), tsel = box.querySelector('select[data-k=tone]');
      if (fm && tsel) fm.value = tsel.value; // the format goes with the draft when it is saved
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
  function heat() {
    document.querySelectorAll('table.heat').forEach(function (t) {
      var cells = t.querySelectorAll('td[data-v]'), max = 0;
      cells.forEach(function (c) { max = Math.max(max, parseFloat(c.getAttribute('data-v')) || 0); });
      cells.forEach(function (c) { c.style.setProperty('--v', max > 0 ? ((parseFloat(c.getAttribute('data-v')) || 0) / max).toFixed(2) : 0); });
    });
  }
  heat();
  document.addEventListener('pm:swap', heat);

  window.addEventListener('pageshow', hide);              // back button: never leave the loader stuck

  // Live progress while the agents search (set up again when a filter changes the page without reloading it).
  var runTimer = null;
  function runbar() {
  clearTimeout(runTimer);
  var rb = document.getElementById('runbar');
  if (rb && rb.dataset.running === '1') {
    document.querySelectorAll('.leadlist.locked form button, .leadlist.locked form input[type=checkbox], .selbar.locked button, .selbar.locked input, .lsel').forEach(function (b) { // one job at a time: lead actions wait for the run
      b.disabled = true; b.title = 'Wait for the agents to finish';
    });
    var fill = document.getElementById('runfill'), sub = document.getElementById('runsub');
    var tick = function () {
      fetch('index.php?runstatus=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (s) {
        if (s.state !== 'running') { location.reload(); return; }
        if (sub) sub.textContent = (s.phase || 'Working') + (s.total ? ' · ' + s.done + ' of ' + s.total + ' done' : '') + (s.new_leads ? ' · ' + s.new_leads + ' new leads so far' : '');
        var st = Math.max(1, s.steps || 7), part = s.total ? Math.min(1, (s.done || 0) / s.total) : 0; // inside a step, its jobs done move the bar on
        if (fill) fill.style.width = Math.max(6, Math.round(100 * (Math.max(0, (s.step || 1) - 1) + part) / st)) + '%';
        runTimer = setTimeout(tick, 2000);
      }).catch(function () { runTimer = setTimeout(tick, 4000); });
    };
    runTimer = setTimeout(tick, 1500);
  }
  }
  runbar();
  document.addEventListener('pm:swap', runbar);
})();

/* Text boxes grow to show everything typed in them, so a message is never cut off (up to 70% of the window, then it scrolls). */
(function () {
  function grow(t) {
    if (!t.offsetParent && !t.getClientRects().length) return;
    t.style.height = 'auto';
    var h = t.scrollHeight + 2;
    if (h > 40) t.style.height = Math.min(h, Math.round(window.innerHeight * 0.7)) + 'px';
  }
  function all(root) { Array.prototype.forEach.call((root || document).querySelectorAll('textarea:not([data-nogrow])'), grow); }
  document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('textarea:not([data-nogrow])')) grow(e.target); });
  document.addEventListener('toggle', function (e) { if (e.target.open) all(e.target); }, true);
  window.addEventListener('load', function () { all(); setTimeout(all, 300); });
  window.addEventListener('resize', function () { all(); });
  document.addEventListener('pm:swap', function () { all(); });
})();

/* Pages of 10: every table with more than 10 rows, and every list marked data-page-items="<selector of its rows>", shows 10 at a time with
   Previous / Next and "Show all". A link to a row on a later page (#l<id>) opens that page. data-nopage on a table leaves it whole. */
(function () {
  var PER = 10;
  function pager(rows, after) {
    if (rows.length <= PER) return;
    var pages = Math.ceil(rows.length / PER), at = 0, all = false;
    var bar = document.createElement('div');
    bar.className = 'pager';
    bar.innerHTML = '<button type="button" class="btn small" data-p="-1">‹ Previous</button><span class="muted t-sm"></span><button type="button" class="btn small" data-p="1">Next ›</button><button type="button" class="btn small ghost" data-p="all">Show all</button>';
    var info = bar.querySelector('span'), prev = bar.querySelector('[data-p="-1"]'), next = bar.querySelector('[data-p="1"]'), tog = bar.querySelector('[data-p="all"]');
    function show() {
      rows.forEach(function (r, i) { r.classList.toggle('pg-off', !all && Math.floor(i / PER) !== at); });
      info.textContent = all ? 'All ' + rows.length : (at * PER + 1) + '–' + Math.min(rows.length, (at + 1) * PER) + ' of ' + rows.length;
      prev.disabled = all || at === 0; next.disabled = all || at === pages - 1;
      tog.textContent = all ? 'Show 10 at a time' : 'Show all';
    }
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('[data-p]');
      if (!b) return;
      var p = b.getAttribute('data-p');
      if (p === 'all') all = !all; else at = Math.max(0, Math.min(pages - 1, at + (+p)));
      show();
    });
    after.parentNode.insertBefore(bar, after.nextSibling);
    function goHash() {
      var id = location.hash.slice(1), t = id && document.getElementById(id);
      if (!t) return;
      for (var i = 0; i < rows.length; i++) { if (rows[i] === t || rows[i].contains(t)) { at = Math.floor(i / PER); all = false; show(); t.scrollIntoView(); return; } }
    }
    show();
    goHash();
    window.addEventListener('hashchange', goHash);
  }
  function init() {
    document.querySelectorAll('table:not([data-nopage])').forEach(function (t) {
      var body = t.tBodies[0];
      if (!body) return;
      var rows = Array.prototype.filter.call(body.rows, function (r) { return r.querySelector('td'); });
      pager(rows, t.closest('.rs-wrap') || t);
    });
    document.querySelectorAll('[data-page-items]').forEach(function (box) {
      var rows = Array.prototype.filter.call(box.children, function (c) { return c.matches(box.getAttribute('data-page-items')); });
      pager(rows, box);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('pm:swap', init);
})();

/* A card that is still being built (data-reload-every="<seconds>") reloads the page until the work is done. */
(function () {
  var el = document.querySelector('[data-reload-every]');
  if (el) setTimeout(function () { location.reload(); }, Math.max(3, +el.getAttribute('data-reload-every') || 5) * 1000);
})();

/* Leads come 10 to a page from the server. A link to a lead (#l<id>) from elsewhere that is not on this page asks again with ?at=<id>, which opens its page. */
(function () {
  var m = location.hash.match(/^#l([^&#?\/]+)$/);
  if (!m || document.getElementById('l' + m[1]) || !/[?&]tab=agents(&|$)/.test(location.search) || /[?&]at=/.test(location.search)) return;
  location.replace(location.pathname + location.search + '&at=' + encodeURIComponent(decodeURIComponent(m[1])) + location.hash);
})();

/* Filters without a reload, everywhere in the app: a filter, a search, a sort, a tab or a page inside the same screen fetches that screen and swaps only <main>
   (the address bar, Back and Forward work as before). Another screen, a different business, a download or anything that fails falls back to a normal page load. */
(function () {
  if (!window.fetch || !window.DOMParser || !history.pushState) return;
  var mainEl = function () { return document.querySelector('main'); };
  var here = function () { return new URL(location.href); };
  function sameScreen(u) {
    var h = here(), m = mainEl();
    if (u.origin !== h.origin || u.pathname !== h.pathname) return false;
    if ((u.searchParams.get('tab') || '') !== (h.searchParams.get('tab') || '')) return false;
    if (u.searchParams.get('section') !== h.searchParams.get('section') && (u.searchParams.has('section') || h.searchParams.has('section'))) return false;
    var b = u.searchParams.get('brand');
    if (b && m && m.dataset.brand && b !== m.dataset.brand) return false; // another business: the whole page changes
    return true;
  }
  var seq = 0;
  function load(url, push, scrollTo) {
    var m = mainEl();
    if (!m) { location.href = url; return; }
    var mine = ++seq, y = window.scrollY;
    m.classList.add('swapping');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'pm-swap' } }).then(function (r) {
      if (r.redirected) { location.href = r.url; throw 0; } // signed out, or sent elsewhere: follow it properly
      if (!r.ok) throw 1;
      return r.text();
    }).then(function (html) {
      if (mine !== seq) return; // a newer filter won
      var doc = new DOMParser().parseFromString(html, 'text/html'), nm = doc.querySelector('main');
      if (!nm || nm.querySelector('script')) { location.href = url; return; } // a screen with its own scripts loads the normal way
      m.innerHTML = nm.innerHTML;
      if (nm.dataset.brand) m.dataset.brand = nm.dataset.brand;
      document.title = doc.title;
      if (push) history.pushState({ pmSwap: 1 }, '', url);
      var u = new URL(url, location.href), t = u.hash && document.getElementById(u.hash.slice(1));
      if (t) t.scrollIntoView();
      else if (scrollTo === 'list') { var p = m.querySelector('nav.pager, .leadlist, table'); window.scrollTo(0, p ? Math.max(0, p.getBoundingClientRect().top + window.scrollY - 90) : 0); }
      else window.scrollTo(0, y);
      document.dispatchEvent(new CustomEvent('pm:swap'));
    }).catch(function (e) { if (e !== 0) location.href = url; }).then(function () { m.classList.remove('swapping'); });
  }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest('a[href]');
    if (!a || !a.closest('main') || a.target || a.hasAttribute('download') || a.hasAttribute('data-nosoft') || a.closest('[data-nosoft]')) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return;
    var u = new URL(a.href, location.href);
    if (!sameScreen(u) || (u.search === location.search && u.hash)) return; // only the place on the page changes: the browser does that itself
    e.preventDefault();
    load(u.href, true, a.closest('nav.pager, .pager') ? 'list' : 'keep');
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f || (f.method || 'get').toLowerCase() !== 'get' || !f.closest('main') || f.hasAttribute('data-nosoft') || f.target) return;
    var u = new URL(f.getAttribute('action') || location.pathname, location.href);
    var ps = new URLSearchParams(new FormData(f));
    Array.from(ps.keys()).forEach(function (k) { if (ps.get(k) === '') ps.delete(k); }); // a tidy address: no empty filters
    u.search = ps.toString();
    if (!sameScreen(u)) return;
    e.preventDefault();
    load(u.href, true, 'keep');
  });
  window.addEventListener('popstate', function () { load(location.href, false, 'keep'); });
})();
/* Talk it over: a chat with the agent who wrote it, under every message box (lib/sx_chat.php). The agent's new version goes straight into the box, Undo puts the
   old one back; nothing is saved until the form is saved. A wish that holds for every message can be remembered as a standing rule (Social > Voice). */
(function () {
  if (document.body.getAttribute('data-ai') !== '1') return;
  var SEL = 'textarea[data-chat], textarea[name=email_body], textarea[name=body], textarea[name=whatsapp], textarea[name=caption], textarea[name=first_comment], textarea[name=reply], textarea[name=own], textarea[name=text]';
  var QUICK = ['Shorter', 'Warmer', 'More direct', 'Simpler words'];
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt) e.textContent = txt; return e; }
  function field(scope, sel) { var x = scope && scope.querySelector(sel); return x ? x.value : ''; }
  function attach(ta) {
    if (ta.dataset.ccOn || ta.closest('.cc-panel') || (/^Paste/i.test(ta.placeholder || '') && !ta.value.trim())) return;
    ta.dataset.ccOn = '1';
    var form = ta.form || ta.closest('form, .card'), chat = [];
    var bar = el('div', 'cc-bar'), open = el('button', 'btn small ghost cc-open', 'Talk it over');
    open.type = 'button'; open.title = 'Chat with the agent who wrote it: ask for changes, or ask why';
    bar.appendChild(open);
    var panel = el('div', 'cc-panel'); panel.hidden = true;
    var log = el('div', 'cc-log'); log.setAttribute('aria-live', 'polite');
    var chips = el('div', 'cc-chips');
    var row = el('div', 'cc-in'), box = el('textarea'), send = el('button', 'btn small primary', 'Send');
    box.rows = 1; box.placeholder = 'What should change? Or ask why it says something.'; box.setAttribute('aria-label', 'Message to the writer'); send.type = 'button';
    row.appendChild(box); row.appendChild(send);
    QUICK.forEach(function (q) { var c = el('button', 'chip cc-chip', q); c.type = 'button'; c.addEventListener('click', function () { go(q); }); chips.appendChild(c); });
    panel.appendChild(log); panel.appendChild(chips); panel.appendChild(row);
    var after = ta.nextElementSibling && ta.nextElementSibling.classList.contains('sigprev') ? ta.nextElementSibling : ta;
    after.insertAdjacentElement('afterend', bar); bar.insertAdjacentElement('afterend', panel);
    open.addEventListener('click', function () { panel.hidden = !panel.hidden; open.textContent = panel.hidden ? 'Talk it over' : 'Close the chat'; if (!panel.hidden) box.focus(); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); go(box.value); } });
    send.addEventListener('click', function () { go(box.value); });
    function bubble(who, text, cls) {
      var b = el('div', 'cc-msg ' + cls); if (who) b.appendChild(el('b', '', who)); b.appendChild(el('span', '', text)); log.appendChild(b); log.scrollTop = log.scrollHeight; return b;
    }
    function go(ask) {
      ask = (ask || '').trim();
      if (!ask || send.disabled) return;
      bubble('', ask, 'me'); box.value = '';
      var wait = bubble('', 'Writing…', 'agent wait');
      var c = document.querySelector('input[name=csrf]'), fd = new FormData();
      fd.append('csrf', c ? c.value : ''); fd.append('action', 'craft_chat'); fd.append('ask', ask); fd.append('text', ta.value);
      fd.append('kind', ta.getAttribute('data-chat') || ''); fd.append('name', ta.name || ''); fd.append('max', ta.maxLength > 0 ? ta.maxLength : 0);
      fd.append('do', field(form, 'input[name=do]')); fd.append('which', field(form, 'input[name=which]')); fd.append('id', field(form, 'input[name=id]'));
      fd.append('subject', field(form, 'input[name=subject], input[name=email_subject]')); fd.append('chat', JSON.stringify(chat.slice(-8)));
      send.disabled = true;
      fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        wait.remove();
        if (!j.ok) { bubble('', j.error || 'Could not reach the writer. Try again.', 'agent err'); return; }
        chat.push({ who: 'owner', said: ask }); chat.push({ who: 'agent', said: j.say + (j.text ? ' [new version: ' + j.text + ']' : '') });
        var b = bubble(j.agent, j.say, 'agent');
        if (j.text) {
          var prev = ta.value; ta.value = j.text; ta.dispatchEvent(new Event('input', { bubbles: true }));
          ta.classList.add('cc-new'); setTimeout(function () { ta.classList.remove('cc-new'); }, 1200);
          var acts = el('div', 'cc-acts'), undo = el('button', 'btn small ghost', 'Undo');
          undo.type = 'button';
          undo.addEventListener('click', function () { var now = ta.value; ta.value = prev; prev = now; ta.dispatchEvent(new Event('input', { bubbles: true })); undo.textContent = undo.textContent === 'Undo' ? 'Redo' : 'Undo'; });
          acts.appendChild(el('span', 'muted t-sm', 'In the box now. Save the form to keep it.')); acts.appendChild(undo); b.appendChild(acts);
        }
        if (j.lesson) {
          var rm = el('button', 'btn small cc-rem', 'Remember for every message: “' + j.lesson + '”'); rm.type = 'button';
          rm.addEventListener('click', function () {
            var f2 = new FormData(); f2.append('csrf', c ? c.value : ''); f2.append('action', 'craft_remember'); f2.append('lesson', j.lesson); f2.append('for', j.lesson_for);
            rm.disabled = true;
            fetch('index.php', { method: 'POST', body: f2, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (k) { rm.textContent = k.ok ? k.msg : (k.error || 'Not saved.'); })
              .catch(function () { rm.disabled = false; if (window.pmToast) window.pmToast('Network problem. Try again.'); });
          });
          b.appendChild(rm);
        }
      }).catch(function () { wait.remove(); bubble('', 'Network problem. Try again.', 'agent err'); }).then(function () { send.disabled = false; box.focus(); });
    }
  }
  function init() { document.querySelectorAll(SEL).forEach(attach); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('pm:swap', init);
})();
