<?php
/**
 * Social > Leads: everyone who came through social media, by channel and by where the conversation stands, with the head of Social's draft for each (lib/sx_socleads.php).
 * Only the channels this business has connected are shown. Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$on = pm_sl_connected($vb);
$all = pm_sl_rows($vb);
$sx = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$chOn = isset($on[$_GET['ch'] ?? '']) ? (string)$_GET['ch'] : '';
$stOn = isset(PM_SL_STAGES[$_GET['stage'] ?? '']) ? (string)$_GET['stage'] : '';
$counts = pm_sl_counts($vb);
$listen = function_exists('pm_listen_state') ? (array)(pm_listen_state($vb)['day'] ?? []) : [];
$url = fn(string $ch, string $st) => '?tab=social&view=leads' . ($ch !== '' ? '&ch=' . $ch : '') . ($st !== '' ? '&stage=' . $st : '');
?>
<?= pm_social_nav('leads') ?>
<h3>Social leads</h3>
<?php if (!$on): ?>
<div class="card"><p>No social channel is connected for this business yet, so there is nothing to show. Connect the Facebook Page (Messenger, comments and Instagram), WhatsApp, LinkedIn or X under <a href="?tab=settings&amp;section=connections">Connections</a>, or add an AI key so <?= pm_h(pm_agent_first('social')) ?> can find people asking on public posts.</p></div>
<?php return; endif; ?>
<div class="card">
  <p class="mt-0"><b><?= pm_h(pm_agent_first('social')) ?>, head of Social:</b>
    <?= count($all) ? count($all) . ' ' . (count($all) === 1 ? 'person' : 'people') . ' came to us on ' . pm_h(implode(', ', array_values($on))) . '. ' : 'Nobody from ' . pm_h(implode(', ', array_values($on))) . ' yet. ' ?>
    <?= $counts['answer'] ? '<b>' . $counts['answer'] . ' wait for an answer</b>' . ($counts['followup'] ? ', ' : '. ') : '' ?><?= $counts['followup'] ? '<b>' . $counts['followup'] . ' follow-up' . ($counts['followup'] === 1 ? ' is' : 's are') . ' due</b>. ' : '' ?>
    <?= $counts['waiting'] ? $counts['waiting'] . ' waiting on them. ' : '' ?>
    <?= !empty($listen['date']) ? 'I drafted the answers and follow-ups at the morning huddle (' . pm_h(date('j M H:i', strtotime((string)$listen['at']))) . ').' : 'My drafts come with the morning huddle; until then each one has a plain draft.' ?></p>
  <p class="hint">Each channel keeps its own rules: Messenger and WhatsApp answer freely for 24 hours (Messenger allows one human follow-up until day 7), a comment gets one private message, a public post is answered under the post. At most <?= PM_SL_MAX_FOLLOWUPS ?> follow-ups each, then close them. Nothing is sent by itself.</p>
</div>
<?php
$tabs = ['' => ['All (' . count($all) . ')', $url('', $stOn)]];
foreach ($on as $k => $label) {
    $n = count(array_filter($all, fn($r) => $r['ch'] === $k));
    $due = ($counts['by_channel'][$k]['answer'] ?? 0) + ($counts['by_channel'][$k]['followup'] ?? 0);
    $tabs[$k] = [pm_h($label) . ' (' . $n . ')' . ($due ? ' <span class="pill warn">' . $due . '</span>' : ''), $url($k, $stOn)];
}
echo pm_ui_tabs($tabs, $chOn, 'Channels');
$inCh = array_filter($all, fn($r) => $chOn === '' || $r['ch'] === $chOn);
?>
<div class="btns my-1"><a class="btn small<?= $stOn === '' ? ' primary' : '' ?>" href="<?= pm_h($url($chOn, '')) ?>">Every stage</a>
  <?php foreach (PM_SL_STAGES as $k => $label): $n = count(array_filter($inCh, fn($r) => $r['stage'] === $k)); if (!$n) continue; ?>
  <a class="btn small<?= $stOn === $k ? ' primary' : '' ?>" href="<?= pm_h($url($chOn, $k)) ?>"><?= pm_h($label) ?> (<?= $n ?>)</a>
  <?php endforeach; ?></div>
<?php
$rows = array_filter($inCh, fn($r) => $stOn === '' || $r['stage'] === $stOn);
[$page, $pager] = pm_ui_page($rows, 12);
if (!$page): ?><div class="card"><p class="muted">Nobody here<?= $stOn !== '' ? ' at this stage' : '' ?>.</p></div><?php endif;
foreach ($page as $id => $r):
    $l = $r['lead'];
    $ch = $r['ch'];
    $st = $r['stage'];
    [$in, $out] = pm_sl_times($l);
    $act = in_array($st, ['answer', 'followup'], true);
    [$draft, $by] = $act ? pm_sl_draft($l, $ch, $st) : ['', ''];
    $box = 'sl-' . preg_replace('/[^a-z0-9]/i', '', (string)$id);
    $open = match ($ch) {
        'public' => (string)($l['listen']['url'] ?? ''),
        'whatsapp' => $act && function_exists('pm_wa_link') ? pm_wa_link($l, $draft) : '',
        'messenger', 'facebook', 'instagram' => '?tab=social&view=inbox',
        default => '',
    };
    $rule = $act ? pm_sl_rule($l, $ch) : '';
    $psid = preg_replace('/\D/', '', (string)($l['fb_from_id'] ?? ''));
    $canMsg = $ch === 'messenger' && $psid !== '' && $in !== '' && strtotime($in) > time() - 7 * 86400;
?>
<div class="card">
  <h2 class="mt-0"><?= pm_h((string)$l['name']) ?> <span class="pill"><?= pm_h(PM_SL_CHANNELS[$ch][0]) ?></span> <span class="pill<?= $act ? ' warn' : '' ?>"><?= pm_h(PM_SL_STAGES[$st]) ?></span>
    <?= (int)($l['sl_followups'] ?? 0) ? '<span class="muted t-sm">' . (int)$l['sl_followups'] . ' of ' . PM_SL_MAX_FOLLOWUPS . ' follow-ups sent</span>' : '' ?></h2>
  <?php if (($w = pm_sl_wants($l)) !== ''): ?><p class="mt-0"><b>Wants:</b> <?= pm_h($w) ?></p><?php endif; ?>
  <p class="hint mt-0"><?= $in !== '' ? 'They last wrote ' . pm_h(date('j M H:i', strtotime($in))) : '' ?><?= $out !== '' ? ($in !== '' ? ' · ' : '') . 'we last wrote ' . pm_h(date('j M H:i', strtotime($out))) : ($in !== '' ? ' · we have not answered yet' : '') ?>
    <?= trim((string)($l['phone'] ?? '')) !== '' ? ' · ' . pm_h((string)$l['phone']) : '' ?><?= filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) ? ' · ' . pm_h((string)$l['email']) : '' ?></p>
  <?php if ($act): ?>
    <?php if ($rule !== ''): ?><p class="hint"><?= pm_h($rule) ?></p><?php endif; ?>
    <form method="post"><?= $sx('sl_sent', $hid('id', $id)) ?>
      <label for="<?= $box ?>"><?= $st === 'answer' ? 'Answer' : 'Follow-up' ?> <span class="muted t-sm">· <?= $by === 'ai' ? 'drafted by ' . pm_h(pm_agent_first('social')) : 'plain draft' ?>; edit before you send</span></label>
      <textarea id="<?= $box ?>" name="text" rows="3"><?= pm_h($draft) ?></textarea>
      <div class="btns mt-1">
        <button type="button" class="btn small" data-copy-target="#<?= $box ?>">Copy</button>
        <?php if ($open !== ''): ?><a class="btn small" href="<?= pm_h($open) ?>"<?= str_starts_with($open, 'http') ? ' target="_blank" rel="noopener"' : '' ?>><?= $ch === 'public' ? 'Open their post' : ($ch === 'whatsapp' ? 'Open WhatsApp' : 'Open the Inbox') ?></a><?php endif; ?>
        <?php if ($canMsg): ?><button class="btn small primary" name="do" value="sl_messenger" title="Sends this on Messenger now">Send on Messenger</button><?php endif; ?>
        <button class="btn small<?= $canMsg ? '' : ' primary' ?>" title="You sent it or posted it yourself: recorded on the lead">I sent it</button>
      </div>
    </form>
  <?php endif; ?>
  <div class="btns mt-1">
    <a class="btn small ghost" href="?tab=agents&amp;lead=<?= pm_h((string)$id) ?>">Open the lead</a>
    <?php if (in_array($st, ['quiet', 'waiting', 'followup'], true)): ?><form method="post" class="inline" onsubmit="return confirm('Close this lead as no answer?')"><?= $sx('sl_close', $hid('id', $id)) ?><button class="btn small ghost">Close: no answer</button></form><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
<?= $pager ?>
