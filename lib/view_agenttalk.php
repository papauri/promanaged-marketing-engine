<?php
/**
 * Audit > Agent conversations (admin only: the whole Audit department is). One day at a time: what the AI agents told each other and did, as a short chat with their
 * names, in plain words. Built by code from what the agents log and the notes they pass (lib/agenttalk.php): no AI. A day can be deleted. Needs $csrf.
 */
$days = pm_talk_days();
$day = in_array((string)($_GET['day'] ?? ''), $days, true) ? (string)$_GET['day'] : ($days[0] ?? date('Y-m-d'));
$tb = in_array((string)($_GET['biz'] ?? ''), pm_brand_ids(), true) ? (string)$_GET['biz'] : '';
$lines = $days ? pm_talk_day($day, $tb) : [];
echo pm_ui_head('Agent conversations', 'How the AI agents talked to each other and decided, day by day, in a few lines. Only an admin sees this.') . pm_ui_strip('audit', '', 'talk');
?>
<nav class="filters" aria-label="Day">
  <?php foreach (array_slice($days, 0, 10) as $d): ?><a href="?tab=audit&amp;section=talk&amp;day=<?= pm_h($d) ?><?= $tb !== '' ? '&amp;biz=' . pm_h($tb) : '' ?>"<?= $d === $day ? ' class="on" aria-current="true"' : '' ?>><?= pm_h($d === date('Y-m-d') ? 'Today' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('D j M', strtotime($d)))) ?></a><?php endforeach; ?>
  <form method="get" class="typesel"><input type="hidden" name="tab" value="audit"><input type="hidden" name="section" value="talk"><input type="hidden" name="day" value="<?= pm_h($day) ?>">
    <select name="biz" onchange="(this.form.requestSubmit || this.form.submit).call(this.form)" aria-label="Business"><option value="">Every business</option><?php foreach (pm_brand_ids() as $bid): ?><option value="<?= pm_h($bid) ?>" <?= $tb === $bid ? 'selected' : '' ?>><?= pm_h(pm_brand_name($bid)) ?></option><?php endforeach; ?></select></form>
</nav>
<div class="card talk">
  <?php if (!$lines): ?><p class="muted">No conversation for this day yet. Lines appear as the agents work: the morning run, the Director's orders, replies, your decisions.</p>
  <?php else: ?>
  <p class="hint mt-0"><?= pm_h(date('l j F Y', strtotime($day))) ?> · <?= count($lines) ?> lines<?= $tb === '' ? ' · every business' : ' · ' . pm_h(pm_brand_name($tb)) ?>. Lines in a row from the same agent are joined, so the day reads in a minute.</p>
  <ul class="talklist">
    <?php foreach ($lines as $l): $me = pm_talk_name($l['who']); ?>
      <li class="<?= $l['who'] === 'owner' ? 'you' : '' ?>"><time><?= pm_h($l['at']) ?></time>
        <b><?= pm_h($me) ?><?= $l['to'] !== '' && $l['to'] !== 'all' ? ' → ' . pm_h(pm_talk_name($l['to'])) : '' ?>:</b>
        <span><?= pm_h($l['text']) ?></span><?php if ($tb === '' && count(pm_brand_ids()) > 1): ?> <small class="muted"><?= pm_h(pm_brand_name($l['brand'])) ?></small><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" class="btns mt-2"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="audit"><input type="hidden" name="do" value="talk_delete"><input type="hidden" name="day" value="<?= pm_h($day) ?>">
    <button class="btn small danger" data-confirm="Delete the agents' conversation of <?= pm_h(date('j M Y', strtotime($day))) ?> for every business? It cannot be brought back.">Delete this day</button>
    <span class="hint">Deletes only this conversation. Leads, emails and the activity log are not touched.</span></form>
  <?php endif; ?>
</div>
