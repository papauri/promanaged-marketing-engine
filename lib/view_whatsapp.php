<?php
/** WhatsApp rapid-fire queue: one tap opens WhatsApp with the message ready; the app logs it and moves to the next lead. */
pm_brand_set($vb);
$acfg = pm_agents_config();
$who = (string)($GLOBALS['PM_WHO'] ?? '');
$queue = pm_wa_queue(pm_leads());
$wtype = (string)($_GET['type'] ?? '');
$wtypes = [];
foreach ($queue as $q) { $tk = strtolower(trim((string)($q['lead']['type'] ?: 'other'))); $wtypes[$tk] = ($wtypes[$tk] ?? 0) + 1; }
arsort($wtypes);
if ($wtype !== '') { $queue = array_values(array_filter($queue, fn($q) => strtolower(trim((string)($q['lead']['type'] ?: 'other'))) === strtolower($wtype))); }
$sent = pm_wa_sent_today(pm_leads());
$cap = (int)$acfg['wa_cap'];
$left = max(0, $cap - $sent);
$bname = pm_brand_title($settings, $vb);
$waBiz = pm_wa_biz_cfg()['ready'];
// Leads that wrote to us and have not been answered (replied, or a customer / lost lead who wrote again), oldest first
$wrote = array_values(array_filter(pm_leads(), fn($x) => ($x['brand'] ?? 'promanaged') === $vb && ($x['status'] ?? '') !== 'optout' && (($x['status'] ?? '') === 'replied' || !empty($x['awaiting_reply_since']))));
usort($wrote, fn($a, $b) => strcmp((string)($a['awaiting_reply_since'] ?? $a['last_reply'] ?? ''), (string)($b['awaiting_reply_since'] ?? $b['last_reply'] ?? '')));
?>
<h1>WhatsApp · <?= pm_h($bname) ?></h1>
<p class="sub">Tap <b>Send on WhatsApp</b>: WhatsApp opens with the message ready, you press send there, and the lead moves on. <?= $sent ?> sent today · <?= $left ?> left of <?= $cap ?>.</p>
<?php if ($wrote): ?>
<h2 class="repttl">They replied <span class="pill warn"><?= count($wrote) ?></span></h2>
<?php foreach (array_slice($wrote, 0, 15) as $l):
    $lastIn = '';
    foreach (array_reverse((array)($l['thread'] ?? [])) as $tm) { if (($tm['dir'] ?? '') === 'in') { $lastIn = (string)$tm['text']; break; } }
    $rp = (array)($l['reply_draft'] ?? []);
    $waDraft = (string)($rp['whatsapp'] ?? '');
    $waUrl = pm_wa_link($l, '');
    $hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
?>
  <div class="card repcard" id="r<?= pm_h($l['id']) ?>">
    <div class="wahead"><div><b><?= pm_h($l['name']) ?></b> <span class="muted">· <?= pm_h($l['city']) ?> · <?= pm_h(PM_LEAD_STATUSES[$l['status']] ?? $l['status']) ?><?= !empty($l['awaiting_reply_since']) ? ' · waiting since ' . pm_h(date('j M, H:i', strtotime((string)$l['awaiting_reply_since']))) : '' ?></span></div>
      <a class="btn small" href="?tab=agents&lead=<?= pm_h($l['id']) ?>">Open lead</a></div>
    <?php if ($lastIn !== ''): ?><p class="hint in"><b>They wrote:</b> <?= nl2br(pm_h(mb_substr($lastIn, 0, 400))) ?></p><?php endif; ?>
    <?php if (!empty($rp['next_step'])): ?><p class="hint">Next: <?= pm_h($rp['next_step']) ?></p><?php endif; ?>
    <?php if (!empty($rp['needs_human'])): ?><p class="hint warnt">Needs your judgement before you answer.</p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="send_reply_wa"><?= $hid('id', $l['id']) . $hid('rl', '1') . $hid('bt', 'whatsapp') ?>
    <label class="hint">Your answer on WhatsApp <?= $waDraft === '' ? '(none drafted yet: paste their message below, or write it)' : '(drafted: edit it, then send)' ?></label>
    <textarea name="whatsapp" rows="3" aria-label="Answer"><?= pm_h($waDraft) ?></textarea>
    <div class="btns">
      <?php if ($waBiz && pm_wa_biz_window_open($l)): ?><button class="btn primary" onclick="return confirm('Send this answer on WhatsApp now?')">Send on WhatsApp Business</button><?php endif; ?>
      <?php if ($waUrl !== ''): ?><a class="btn<?= $waBiz && pm_wa_biz_window_open($l) ? '' : ' primary' ?>" data-wa-log data-id="<?= pm_h($l['id']) ?>" data-csrf="<?= pm_h($csrf) ?>" data-url="<?= pm_h($waUrl) ?>" href="<?= pm_h($waUrl) ?>" target="_blank" rel="noopener noreferrer">Open WhatsApp</a>
      <?php else: ?><span class="hint">No mobile number for this lead: answer by email from the lead.</span><?php endif; ?>
      <button type="button" class="btn small" data-copy-from="textarea[name=whatsapp]">Copy</button>
    </div></form>
    <details class="aibox"><summary>Paste what they wrote, get a drafted answer</summary>
      <form method="post"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="paste_reply">
        <?= $hid('id', $l['id']) . $hid('rl', '1') . $hid('bt', 'whatsapp') . $hid('ch', 'wa') ?>
        <textarea name="text" rows="3" placeholder="Paste their WhatsApp message here" required></textarea><button class="btn small">Draft my answer</button></form></details>
  </div>
<?php endforeach; ?>
<h2 class="repttl">To message</h2>
<?php endif; ?>
<form method="get" class="typesel" style="margin:0 0 10px"><input type="hidden" name="tab" value="whatsapp">
  <select name="type" onchange="this.form.submit()" aria-label="Business type"><option value="">All business types</option>
  <?php foreach ($wtypes as $tk => $tn): ?><option value="<?= pm_h($tk) ?>" <?= strtolower($wtype) === $tk ? 'selected' : '' ?>><?= pm_h(ucfirst($tk)) ?> (<?= $tn ?>)</option><?php endforeach; ?></select></form>
<div class="prog"><i style="width:<?= $cap ? min(100, (int)(100 * $sent / $cap)) : 0 ?>%"></i></div>

<?php if (!$queue): ?>
  <div class="card empty">Nothing to send right now. <?= $vb === 'travel' ? 'Run the agents to find more stays.' : 'Run the agents to find more leads.' ?> Leads need a mobile number and a drafted message.</div>
<?php endif; ?>

<div id="waq" data-csrf="<?= pm_h($csrf) ?>" data-left="<?= $left ?>">
<?php foreach ($queue as $q): $l = $q['lead']; ?>
  <div class="card wacard" data-id="<?= pm_h($l['id']) ?>" data-url="<?= pm_h($q['url']) ?>">
    <div class="wahead">
      <div><b><?= pm_h($l['name']) ?></b> <span class="muted">· <?= pm_h($l['city']) ?> · <?= $q['kind'] === 'followup' ? 'follow-up' : 'first message' ?></span>
        <div class="person"><?= !empty($l['contact']) ? pm_h($l['contact']) . (!empty($l['contact_title']) ? ' · ' . pm_h($l['contact_title']) : '') : '<span class="muted">no name found: message says "Hello"</span>' ?> · <span class="muted"><?= pm_h($q['display']) ?></span></div></div>
      <span class="pill <?= ($l['score'] ?? 0) >= 70 ? 'hot' : '' ?>"><?= (int)($l['score'] ?? 0) ?></span>
    </div>
    <textarea rows="4" aria-label="Message"><?= pm_h($q['text']) ?></textarea>
    <details class="aibox"><summary>Rewrite with AI</summary><?= pm_compose_controls($l['id'], $csrf, false) ?></details>
    <div class="btns">
      <button type="button" class="btn primary wasend">Send on WhatsApp</button>
      <button type="button" class="btn waskip">Skip</button>
      <button type="button" class="btn small" data-copy="<?= pm_h($q['text']) ?>">Copy</button>
      <a class="btn small" href="?tab=agents&status=all#l<?= pm_h($l['id']) ?>">Open lead</a>
    </div>
  </div>
<?php endforeach; ?>
</div>

<details class="card" style="margin-top:14px"><summary>Quick replies (tap to copy, then paste into the chat)</summary>
  <?php foreach (pm_wa_quick_replies() as $t => $m): ?>
    <div class="qr"><b><?= pm_h($t) ?></b><span><?= pm_h($m) ?></span><button type="button" class="btn small" data-copy="<?= pm_h($m) ?>">Copy</button></div>
  <?php endforeach; ?>
  <p class="hint">When they reply, paste their message under <b>They replied</b> above (or in the lead under <b>More → Paste their reply</b>): the AI classifies it and drafts your answer.</p>
</details>

<?php function_exists('pm_view_wa_campaigns') && pm_view_wa_campaigns($vb, (string)$csrf); // C2-A07: scheduled batches, approved first ?>

<script>
(function () {
  var box = document.getElementById('waq'); if (!box) return;
  var left = +box.dataset.left, csrf = box.dataset.csrf;
  box.addEventListener('click', function (e) {
    var card = e.target.closest('.wacard'); if (!card) return;
    if (e.target.closest('.waskip')) { card.remove(); return; }
    if (!e.target.closest('.wasend')) return;
    if (left <= 0) { alert('Daily WhatsApp limit reached. Raise it in Agents > More > settings if you want more.'); return; }
    var text = card.querySelector('textarea').value.trim(); if (!text) return;
    var url = card.dataset.url.split('?text=')[0] + '?text=' + encodeURIComponent(text);
    var w = window.open('about:blank', '_blank');                 // opened inside the click so the browser allows it; WhatsApp loads once the checks pass
    var fd = new FormData(); fd.append('csrf', csrf); fd.append('action', 'wa_log'); fd.append('id', card.dataset.id); fd.append('text', text);
    fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok) { left = j.left; if (w) { w.opener = null; w.location.href = url; } else { window.open(url, '_blank', 'noopener'); } card.remove(); }
      else { if (w) w.close(); alert((j && j.error) || 'Could not log it.'); }
    }).catch(function () { if (w) w.close(); alert('Network problem. Nothing was logged: try again.'); });
  });
})();
</script>
