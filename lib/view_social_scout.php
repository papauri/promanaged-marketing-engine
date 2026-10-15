<?php
/**
 * Social > Scouting: the places on social media where our buyers gather and a move from us can bring sales, found by the head of Social's desk (lib/sx_scout.php),
 * each with why it matters, the move, the department that would do it and the words to use. The owner decides each one; nothing is posted by itself.
 * Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$st = pm_sf_state($vb);
$c = pm_sf_counts($vb);
$nm = pm_agent_first('social');
$tab = isset(PM_SF_STATUS[$_GET['st'] ?? '']) ? (string)$_GET['st'] : 'new';
$sx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h($v) . '">';
$items = array_filter($st['items'], fn($f) => ($f['status'] ?? 'new') === $tab);
uasort($items, fn($a, $b) => $tab === 'new' ? [(int)$b['fit'], (string)$b['found']] <=> [(int)$a['fit'], (string)$a['found']] : strcmp((string)($b['decided_at'] ?? ''), (string)($a['decided_at'] ?? '')));
$goalOk = $c['acted7'] >= $c['goal'];
?>
<?= pm_social_nav('scout') ?>
<section class="card sf-desk">
  <div class="sf-desk-head">
    <div>
      <h2 class="mt-0"><?= pm_h($nm) ?>'s scouting desk</h2>
      <p class="hint mt-0">We look across social media for the places where <?= pm_h(pm_sf_buyers($vb)) ?> gather, and where a move from us could bring sales: active groups, pages and creators with an engaged audience, busy conversations and events. Each find says why it matters, the move, who would do it and the words to use. You decide; nothing is posted by itself.</p>
    </div>
    <form method="post" class="sf-scoutnow" data-confirm="Scout social media now (one short AI web search and one cheap call)?"><?= $sx('sf_scout') ?>
      <button class="btn primary"<?= pm_agents_ready() ? '' : ' disabled title="Add an AI key to .env first"' ?>>Scout now</button>
      <small class="muted"><?= $c['at'] !== '' ? 'Last scouted ' . pm_h(date('j M, H:i', strtotime($c['at']))) : 'Not scouted yet' ?><?= $c['error'] !== '' ? '<br><span class="warnt">Last try failed: ' . pm_h(mb_substr($c['error'], 0, 90)) . '</span>' : '' ?></small>
    </form>
  </div>
  <div class="sf-stats">
    <a class="sf-stat<?= $c['new'] ? ' warn' : '' ?>" href="?tab=social&amp;view=scout&amp;st=new"><span>To decide</span><b><?= $c['new'] ?></b><small><?= $c['new'] ? 'your call' : 'nothing waiting' ?></small></a>
    <a class="sf-stat" href="?tab=social&amp;view=scout&amp;st=handed"><span>With the team</span><b><?= $c['handed'] ?></b><small>being acted on</small></a>
    <div class="sf-stat<?= $goalOk ? ' ok' : '' ?>"><span>Acted on this week</span><b><?= $c['acted7'] ?> <em>of <?= $c['goal'] ?></em></b>
      <form method="post" class="sf-goal"><?= $sx('sf_goal') ?><label class="muted">Goal a week <input type="number" name="goal" min="1" max="50" value="<?= (int)$c['goal'] ?>" aria-label="Finds to act on each week"></label><button class="btn small ghost">Set</button></form></div>
    <a class="sf-stat<?= $c['people'] ? ' warn' : '' ?>" href="?tab=agents&amp;status=people"><span>People asking</span><b><?= $c['people'] ?></b><small>on public posts, with a reply to paste</small></a>
  </div>
  <?php if ($c['new'] > 0 && !$goalOk): ?><p class="hint mb-0"><b><?= pm_h($nm) ?>:</b> <?= $c['new'] ?> find<?= $c['new'] === 1 ? '' : 's' ?> wait<?= $c['new'] === 1 ? 's' : '' ?> for your call, and we have acted on <?= $c['acted7'] ?> of <?= $c['goal'] ?> this week. Hand the good ones to the team, or tell us to look for more like them.</p>
  <?php elseif (!$c['new'] && !$goalOk): ?><p class="hint mb-0"><b><?= pm_h($nm) ?>:</b> nothing waits for a decision and we are short of this week's goal: press <b>Scout now</b>, or mark the ones the team finished as done.</p><?php endif; ?>
</section>

<?php $tabs = [];
foreach (PM_SF_STATUS as $k => $lab) {
    $tabs[$k] = [pm_h($lab) . ' (' . (int)($c[$k] ?? 0) . ')', '?tab=social&view=scout&st=' . $k];
}
echo pm_ui_tabs($tabs, $tab, 'Finds'); ?>

<?php if (!$items): ?>
<div class="card"><p class="muted mt-0 mb-0"><?= $tab === 'new' ? ($c['at'] === '' ? 'Nothing scouted yet. Press Scout now, or the scheduler scouts on the next working day.' : 'Nothing waits for a decision. The desk scouts again every other working day, or press Scout now.') : 'Nothing here.' ?></p></div>
<?php endif; ?>
<?php [$page, $pager] = pm_ui_page($items, 12); ?>
<div class="sf-grid">
<?php foreach ($page as $f): $id = (string)$f['id']; $box = 'sft-' . $id; $dn = pm_talk_name((string)$f['dept']); ?>
  <article class="card sf-card">
    <div class="sf-tags"><span class="pill"><?= pm_h(PM_SF_KINDS[$f['kind']] ?? $f['kind']) ?></span><span class="pill"><?= pm_h((string)$f['platform']) ?></span>
      <span class="pill <?= (int)$f['fit'] >= 70 ? 'hot' : '' ?>" title="How much it could bring us, out of 100"><?= (int)$f['fit'] ?></span><?= !empty($f['liked']) ? '<span class="pill ok">more like this</span>' : '' ?></div>
    <h3 class="sf-name"><a href="<?= pm_h((string)$f['url']) ?>" target="_blank" rel="noopener noreferrer"><?= pm_h((string)$f['name']) ?> <span aria-hidden="true">↗</span></a></h3>
    <?php if ($f['audience'] !== '' || $f['activity'] !== ''): ?><p class="sf-meta"><?= pm_h(implode(' · ', array_filter([(string)$f['audience'], (string)$f['activity']]))) ?></p><?php endif; ?>
    <p class="sf-why"><?= pm_h((string)$f['why']) ?></p>
    <div class="sf-move"><b><?= pm_h(PM_SF_MOVES[$f['move']] ?? $f['move']) ?></b> <span class="muted">· <?= pm_h($dn) ?></span><?= $f['plan'] !== '' ? '<p>' . pm_h((string)$f['plan']) . '</p>' : '' ?></div>
    <?php if ($f['text'] !== ''): ?>
      <label for="<?= $box ?>" class="t-sm muted">The words to use</label>
      <textarea id="<?= $box ?>" rows="4" readonly><?= pm_h((string)$f['text']) ?></textarea>
    <?php endif; ?>
    <?php if (($f['status'] ?? 'new') !== 'new'): ?><p class="hint"><?= pm_h(PM_SF_STATUS[$f['status']]) ?><?= !empty($f['decided_at']) ? ' since ' . pm_h(date('j M', strtotime((string)$f['decided_at']))) : '' ?><?= !empty($f['by']) ? ' · ' . pm_h((string)$f['by']) : '' ?><?= !empty($f['note']) ? ': ' . pm_h((string)$f['note']) : '' ?></p><?php endif; ?>
    <form method="post" class="sf-act"><?= $sx('sf_decide', $hid('id', $id) . $hid('st', $tab)) ?>
      <?php if ($f['status'] === 'new' || $f['status'] === 'handed'): ?><input type="text" name="note" maxlength="300" placeholder="<?= $f['status'] === 'new' ? 'A note for the team, or why it is not for us (optional)' : 'What happened? (optional)' ?>" aria-label="Note"><?php endif; ?>
      <div class="btns">
        <?php if ($f['text'] !== ''): ?><button type="button" class="btn small" data-copy-target="#<?= $box ?>">Copy the words</button><?php endif; ?>
        <?php if ($f['status'] === 'new'): ?>
          <button class="btn small primary" name="sf" value="hand" title="<?= pm_h($dn) ?> gets it on the team board and reports back">Hand to <?= pm_h(explode(' ', $dn)[0]) ?></button>
          <button class="btn small" name="sf" value="acted" title="You did it yourself">I did it</button>
          <button class="btn small" name="sf" value="more" title="The next scouting looks for more like this">More like this</button>
          <button class="btn small ghost" name="sf" value="skip">Not for us</button>
        <?php elseif ($f['status'] === 'handed'): ?>
          <button class="btn small primary" name="sf" value="acted">Done</button>
          <button class="btn small ghost" name="sf" value="reopen">Back to decide</button>
        <?php else: ?>
          <button class="btn small ghost" name="sf" value="reopen">Back to decide</button>
        <?php endif; ?>
      </div>
    </form>
  </article>
<?php endforeach; ?>
</div>
<?= $pager ?>
