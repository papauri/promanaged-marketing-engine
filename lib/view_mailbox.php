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
// the round initials avatar of a mail row, its colour fixed by a hash of the sender (same palette as the Leads rows)
$mbAv = fn(string $s) => ['#0f6cbd', '#8764b8', '#038387', '#c239b3', '#ca5010', '#498205', '#4f6bed', '#986f0b'][crc32(mb_strtolower(trim($s))) % 8];
$mbIni = function (string $s): string {
    $s = trim(str_contains($s, ' ') ? $s : preg_replace('/@.*$|[._\-+]+/', ' ', $s));
    $w = preg_split('/\s+/u', preg_replace('/[^\p{L}\p{N}\s]+/u', '', $s), -1, PREG_SPLIT_NO_EMPTY);
    return mb_strtoupper($w ? mb_substr($w[0], 0, 1) . (isset($w[1]) ? mb_substr($w[1], 0, 1) : mb_substr($w[0], 1, 1)) : '?');
};
?>
<?php
$nOf = fn(string $k) => count(array_filter($items, fn($it) => $in($it, $k)));
$fpItem = fn(string $k, string $label, bool $hot = false) => ['label' => $label, 'n' => $nOf($k), 'href' => '?tab=mailbox&f=' . $k, 'on' => $f === $k, 'hot' => $hot];
$fpTitles = ['look' => 'Need a look', 'leads' => 'Linked to leads', 'out' => 'Sent to people not in leads', 'rest' => 'Everything else', 'all' => 'All mail'];
$rest = array_filter($items, fn($it) => $in($it, 'rest')); $rk = array_count_values(array_map(fn($it) => (string)$it['kind'], $rest)); arsort($rk);
$readNow = '<form method="post" class="sf-scoutnow">' . $sx('mb_read') . '<button class="btn primary">Read the mailbox now</button><small class="muted">'
    . ($c['at'] !== '' ? 'Last read ' . pm_h(date('j M, H:i', strtotime($c['at']))) : 'Not read yet') . ($c['error'] !== '' ? '<br><span class="warnt">' . pm_h(mb_substr($c['error'], 0, 100)) . '</span>' : '') . '</small></form>';
?>
<?= pm_ui_head('Mailbox · ' . pm_h($bname), 'Every 30 minutes ' . pm_h($nm) . ' reads the Inbox and Sent folder and links each email to its lead, so a business we already talk to is never cold-emailed. Read-only: nothing is marked read, moved or deleted.', $readNow) ?>
<?= pm_ui_strip('mailbox') ?>
<div class="mailview">
<aside class="mailside"><?= pm_ui_folderpane(['Last 30 days' => [$fpItem('look', 'Need a look', true), $fpItem('leads', 'Linked to leads'), $fpItem('out', 'Sent, not to leads'), $fpItem('rest', 'Everything else'), $fpItem('all', 'All mail')]], 'Mailbox folders') ?></aside>
<div class="mailmain">
<div class="listbar mt-0"><h2><?= pm_h($fpTitles[$f]) ?> <span class="muted tnum"><?= count($rows) ?></span></h2>
  <?php if ($f === 'rest' && $rk): ?><span class="muted t-sm"><?= pm_h(implode(', ', array_map(fn($k, $n) => $n . ' ' . strtolower(PM_MB_KINDS[$k] ?? $k), array_keys(array_slice($rk, 0, 4, true)), array_slice($rk, 0, 4, true)))) ?></span><?php endif; ?></div>
<?php if ($outsideLeads): ?><p class="hint mt-0"><b>Caught in time:</b> <?php foreach (array_slice($outsideLeads, 0, 8, true) as $oid => $ol): ?><a class="pill ok" href="?tab=agents&amp;lead=<?= pm_h((string)$oid) ?>"><?= pm_h((string)$ol['name']) ?></a> <?php endforeach; ?> were already talking to us (or emailed by hand), so they get no cold email or follow-up from the app.</p><?php endif; ?>
<?php [$page, $pager] = pm_ui_page($rows, 25); ?>
<?php if (!$page): ?><div class="card"><p class="muted mt-0 mb-0"><?= $c['at'] === '' ? 'The mailbox has not been read yet: press Read the mailbox now.' : 'Nothing here.' ?></p></div><?php else: ?>
<div class="card mb-list">
<?php foreach ($page as $key => $it): $lid = (string)($it['lead'] ?? ''); $ln = $lid !== '' ? (string)($leadsB[$lid]['name'] ?? '') : ''; $look = pm_mb_needs_look($it); $who = $it['dir'] === 'in' && (string)$it['name'] !== '' ? (string)$it['name'] : (string)$it['addr']; $ts = strtotime((string)$it['at']); ?>
  <div class="mb-row<?= $look ? ' look' : '' ?><?= !empty($it['done']) ? ' done' : '' ?>" style="--av:<?= $mbAv($who) ?>">
    <span class="av-in" aria-hidden="true"><?= pm_h($mbIni($who)) ?></span>
    <div class="mb-main">
      <div class="mb-top"><b class="mb-from" title="<?= pm_h((string)$it['addr']) ?>"><?= pm_h(($it['dir'] === 'in' ? '' : 'To ') . $who) ?></b>
        <?php if ($who !== (string)$it['addr']): ?><span class="mb-addr"><?= pm_h((string)$it['addr']) ?></span><?php endif; ?>
        <?php $hm = strlen(trim((string)$it['at'])) > 10 ? ' H:i' : ''; // some sent mail carries the day only ?><time datetime="<?= pm_h(date($hm ? 'c' : 'Y-m-d', $ts)) ?>" title="<?= pm_h(date('j M Y' . ($hm ? ',' . $hm : ''), $ts)) ?>"><?= pm_h(date('Y-m-d', $ts) === date('Y-m-d') ? ($hm ? date('H:i', $ts) : 'Today') : ($ts > strtotime('-6 days') ? date('D' . $hm, $ts) : date('j M', $ts))) ?></time></div>
      <div class="mb-line"><span class="mb-subj"><?= pm_h((string)$it['subject'] !== '' ? (string)$it['subject'] : '(no subject)') ?></span>
        <span class="mb-tags"><?php if ($look): ?><span class="pill <?= $tone[$it['kind']] ?? '' ?>"><?= pm_h(PM_MB_KINDS[$it['kind']] ?? $it['kind']) ?></span><?php elseif ($ln === ''): ?><span class="mb-kind"><?= pm_h(PM_MB_KINDS[$it['kind']] ?? $it['kind']) ?></span><?php endif; ?>
          <?php if ($ln !== ''): ?><a class="mb-lead" href="?tab=agents&amp;lead=<?= pm_h($lid) ?>" title="Matched by <?= pm_h((string)($it['how'] ?? '')) ?>">▸ <?= pm_h($ln) ?></a><?php endif; ?>
          <?php if (!empty($it['business']) && $ln === ''): ?><span class="mb-kind">writes for <?= pm_h((string)$it['business']) ?></span><?php endif; ?>
          <?php if (!empty($it['done'])): ?><span class="mb-kind">put aside</span><?php endif; ?></span></div>
      <?php if (!empty($it['summary']) || (string)$it['snippet'] !== ''): ?><div class="mb-snip"><?= pm_h(!empty($it['summary']) ? (string)$it['summary'] : mb_substr((string)$it['snippet'], 0, 180)) ?></div><?php endif; ?>
    </div>
    <?php if ($lid === '' && ($it['dir'] === 'in' ? !in_array($it['kind'], ['own', 'bounce'], true) : $it['kind'] === 'sent')): // any email we received can become a lead, even one put aside or sorted as a supplier, newsletter or spam ?>
    <form method="post" class="mb-act"><?= $sx('mb_act', $hid('key', (string)$key) . $hid('f', $f)) ?>
      <select name="lead" aria-label="The lead this email belongs to"><option value="">Link to a lead…</option>
        <?php foreach ((array)($it['suggest'] ?? []) as $sg): if (isset($leadsB[$sg])): ?><option value="<?= pm_h((string)$sg) ?>">★ <?= pm_h((string)$leadsB[$sg]['name']) ?></option><?php endif; endforeach; ?>
        <?php foreach ($leadsB as $oid => $ol): ?><option value="<?= pm_h((string)$oid) ?>"><?= pm_h((string)$ol['name']) ?></option><?php endforeach; ?></select>
      <div class="btns"><button class="btn small primary" name="mb" value="link">Link</button>
        <?php if ($it['dir'] === 'in'): ?><button class="btn small" name="mb" value="new" title="A new lead that waits for our answer">Make a lead</button><?php endif; ?>
        <?php if (empty($it['done'])): ?><button class="btn small ghost" name="mb" value="aside">Not a lead</button><?php endif; ?></div>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?= $pager ?>
<?php endif; ?>
</div></div>
