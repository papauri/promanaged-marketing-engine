<?php
/**
 * Sales > Mailbox: what is in the business's mailbox (Inbox and Sent, last 30 days), what sort of communication each email is and which lead it belongs to,
 * read by the head of Replies (lib/sx_mailbox.php). The owner places what code could not: link it to a lead, make a lead of it, or put it aside.
 * Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$st = pm_mb_state($vb);
$c = pm_mb_counts($vb);
$nm = pm_agent_first('replies');
$leadsB = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $vb && empty($l['listen']));
uasort($leadsB, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
$f = in_array($_GET['f'] ?? '', ['look', 'leads', 'out', 'rest', 'all'], true) ? (string)$_GET['f'] : ($c['look'] ? 'look' : 'leads');
$cut = date('Y-m-d H:i', strtotime('-30 days'));
$items = array_filter($st['items'], fn($it) => (string)($it['at'] ?? '') >= $cut);
$in = fn(array $it, string $k) => match ($k) {
    'look' => pm_mb_needs_look($it),
    'leads' => !empty($it['lead']),
    'out' => $it['dir'] === 'out' && empty($it['lead']),
    'rest' => $it['dir'] === 'in' && empty($it['lead']) && !pm_mb_needs_look($it),
    default => true,
};
$rows = array_filter($items, fn($it) => $in($it, $f));
uasort($rows, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
$outsideLeads = array_filter($leadsB, fn($l) => !empty($l['outside']) && strtotime((string)$l['outside']['at']) > strtotime('-30 days'));
$sx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h($v) . '">';
$tone = ['enquiry' => 'warn', 'client' => 'ok', 'lead' => 'ok', 'partner' => 'info', 'spam' => 'bad', 'bounce' => 'bad'];
?>
<?= pm_ui_head('Mailbox · ' . pm_h($bname), 'What is in our mailbox, what sort of email each one is and whose it is, so a business we already talk to is never cold-emailed. Read-only: nothing is marked read, moved or deleted.') ?>
<?= pm_ui_strip('mailbox') ?>
<section class="card mb-desk">
  <div class="sf-desk-head">
    <div><h2 class="mt-0"><?= pm_h($nm) ?>'s mailbox desk</h2>
      <p class="hint mt-0">Every 30 minutes <?= pm_h($nm) ?> reads the Inbox and the Sent folder, links each email to its lead (by address, a colleague's address, the company's domain or the thread) and says what the rest are. A lead we have not written to that is already talking to us, or that someone emailed by hand, is taken out of cold outreach straight away.</p></div>
    <form method="post" class="sf-scoutnow"><?= $sx('mb_read') ?><button class="btn primary">Read the mailbox now</button>
      <small class="muted"><?= $c['at'] !== '' ? 'Last read ' . pm_h(date('j M, H:i', strtotime($c['at']))) : 'Not read yet' ?><?= $c['error'] !== '' ? '<br><span class="warnt">' . pm_h(mb_substr($c['error'], 0, 100)) . '</span>' : '' ?></small></form>
  </div>
  <div class="sf-stats">
    <a class="sf-stat<?= $c['look'] ? ' warn' : '' ?>" href="?tab=mailbox&amp;f=look"><span>Need a look</span><b><?= $c['look'] ?></b><small>people we do not know as leads yet</small></a>
    <a class="sf-stat" href="?tab=mailbox&amp;f=leads"><span>Linked to leads</span><b><?= $c['linked'] ?></b><small>of <?= $c['in'] + $c['out'] ?> emails in 30 days</small></a>
    <div class="sf-stat<?= $c['outside'] ? ' ok' : '' ?>"><span>Caught in time</span><b><?= $c['outside'] ?></b><small>leads already in touch outside the app, kept out of cold outreach</small></div>
    <?php $rest = array_filter($items, fn($it) => $in($it, 'rest')); $rk = array_count_values(array_map(fn($it) => (string)$it['kind'], $rest)); arsort($rk); ?>
    <a class="sf-stat" href="?tab=mailbox&amp;f=rest"><span>Everything else</span><b><?= count($rest) ?></b><small><?= $rk ? pm_h(implode(', ', array_map(fn($k, $n) => $n . ' ' . strtolower(PM_MB_KINDS[$k] ?? $k), array_keys(array_slice($rk, 0, 4, true)), array_slice($rk, 0, 4, true)))) : 'nothing' ?></small></a>
  </div>
  <?php if ($outsideLeads): ?><p class="hint mb-0"><b>Caught in time:</b> <?php foreach (array_slice($outsideLeads, 0, 8, true) as $oid => $ol): ?><a class="pill ok" href="?tab=agents&amp;lead=<?= pm_h((string)$oid) ?>"><?= pm_h((string)$ol['name']) ?></a> <?php endforeach; ?> were already talking to us (or emailed by hand), so they get no cold email or follow-up from the app.</p><?php endif; ?>
</section>

<?php
$tabs = [];
foreach (['look' => 'Need a look', 'leads' => 'Linked to leads', 'out' => 'Sent to people not in leads', 'rest' => 'Everything else', 'all' => 'All'] as $k => $lab) {
    $tabs[$k] = [pm_h($lab) . ' (' . count(array_filter($items, fn($it) => $in($it, $k))) . ')', '?tab=mailbox&f=' . $k];
}
echo pm_ui_tabs($tabs, $f, 'Mailbox');
[$page, $pager] = pm_ui_page($rows, 25);
?>
<?php if (!$page): ?><div class="card"><p class="muted mt-0 mb-0"><?= $c['at'] === '' ? 'The mailbox has not been read yet: press Read the mailbox now.' : 'Nothing here.' ?></p></div><?php else: ?>
<div class="card mb-list">
<?php foreach ($page as $key => $it): $lid = (string)($it['lead'] ?? ''); $ln = $lid !== '' ? (string)($leadsB[$lid]['name'] ?? '') : ''; ?>
  <div class="mb-row<?= pm_mb_needs_look($it) ? ' look' : '' ?>">
    <div class="mb-dir" title="<?= $it['dir'] === 'in' ? 'Came in' : 'We sent it' ?>"><?= $it['dir'] === 'in' ? '↘' : '↗' ?></div>
    <div class="mb-main">
      <div class="mb-top"><b><?= pm_h($it['dir'] === 'in' ? ((string)$it['name'] !== '' ? (string)$it['name'] : (string)$it['addr']) : 'To ' . (string)$it['addr']) ?></b>
        <?php if ($it['dir'] === 'in' && (string)$it['name'] !== ''): ?><span class="muted t-sm"><?= pm_h((string)$it['addr']) ?></span><?php endif; ?>
        <time class="muted t-sm"><?= pm_h(date('j M H:i', strtotime((string)$it['at']))) ?></time></div>
      <div class="mb-subj"><?= pm_h((string)$it['subject'] !== '' ? (string)$it['subject'] : '(no subject)') ?></div>
      <?php if (!empty($it['summary']) || (string)$it['snippet'] !== ''): ?><div class="mb-snip"><?= pm_h(!empty($it['summary']) ? (string)$it['summary'] : mb_substr((string)$it['snippet'], 0, 180)) ?></div><?php endif; ?>
      <div class="mb-tags"><span class="pill <?= $tone[$it['kind']] ?? '' ?>"><?= pm_h(PM_MB_KINDS[$it['kind']] ?? $it['kind']) ?></span>
        <?php if ($ln !== ''): ?><a class="pill ok" href="?tab=agents&amp;lead=<?= pm_h($lid) ?>" title="Matched by <?= pm_h((string)($it['how'] ?? '')) ?>">▸ <?= pm_h($ln) ?></a><span class="muted t-sm">by <?= pm_h((string)($it['how'] ?? '')) ?></span><?php endif; ?>
        <?php if (!empty($it['business']) && $ln === ''): ?><span class="muted t-sm">writes for <?= pm_h((string)$it['business']) ?></span><?php endif; ?>
        <?php if (!empty($it['done'])): ?><span class="muted t-sm">put aside</span><?php endif; ?></div>
    </div>
    <?php if ($lid === '' && in_array($it['kind'], ['enquiry', 'client', 'partner', 'other', 'sent'], true) && empty($it['done'])): ?>
    <form method="post" class="mb-act"><?= $sx('mb_act', $hid('key', (string)$key) . $hid('f', $f)) ?>
      <select name="lead" aria-label="The lead this email belongs to"><option value="">Link to a lead…</option>
        <?php foreach ((array)($it['suggest'] ?? []) as $sg): if (isset($leadsB[$sg])): ?><option value="<?= pm_h((string)$sg) ?>">★ <?= pm_h((string)$leadsB[$sg]['name']) ?></option><?php endif; endforeach; ?>
        <?php foreach ($leadsB as $oid => $ol): ?><option value="<?= pm_h((string)$oid) ?>"><?= pm_h((string)$ol['name']) ?></option><?php endforeach; ?></select>
      <div class="btns"><button class="btn small primary" name="mb" value="link">Link</button>
        <?php if ($it['dir'] === 'in' && in_array($it['kind'], ['enquiry', 'client', 'partner', 'other'], true)): ?><button class="btn small" name="mb" value="new" title="A new lead that waits for our answer">Make a lead</button><?php endif; ?>
        <button class="btn small ghost" name="mb" value="aside">Not a lead</button></div>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?= $pager ?>
<?php endif; ?>
