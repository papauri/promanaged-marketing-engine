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
      <p class="hint mt-0">We look across social media for the places where <?= pm_h(pm_sf_buyers($vb)) ?> gather, and where a move from us could bring sales: active groups, pages and creators with an engaged audience, busy conversations and events. We also read individual posts for people asking for something we offer (<?= pm_h(mb_substr(implode(', ', array_slice(pm_listen_items($vb), 0, 6)), 0, 120)) ?>…) and give you a reply to paste under each. Each find says why it matters, the move, who would do it and the words to use. You decide; nothing is posted by itself.</p>
    </div>
    <form method="post" class="sf-scoutnow" data-confirm="Scout social media now: places where our buyers gather, and posts where people ask for what we offer (two short AI web searches and two cheap calls)?"><?= $sx('sf_scout') ?>
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

<?php
$pst = (array)(pm_listen_state($vb)['posts'] ?? []);
$asking = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $vb && !empty($l['listen']) && ($l['status'] ?? '') === 'new');
uasort($asking, fn($a, $b) => strcmp((string)($b['listen']['found'] ?? ''), (string)($a['listen']['found'] ?? '')));
?>
<section class="card sf-posts" id="posts">
  <h2 class="mt-0">Posts asking for what we offer <span class="muted t-sm">· <?= count($asking) ?> waiting for a reply<?= !empty($pst['at']) ? ' · posts last scouted ' . pm_h(date('j M, H:i', strtotime((string)$pst['at']))) : '' ?></span></h2>
  <p class="hint mt-0">People on public posts, in groups and forums asking for something we sell. Open the post, paste the reply under it, then press I replied. No app can comment under other people's posts, so this step is yours.<?= !empty($pst['error']) ? ' <span class="warnt">Last try failed: ' . pm_h(mb_substr((string)$pst['error'], 0, 90)) . '</span>' : '' ?></p>
  <?php if (!$asking): ?><p class="muted mb-0">Nobody waiting. The desk reads the posts again every few hours in the day, or press Scout now.</p><?php endif; ?>
  <div class="sf-grid">
  <?php foreach (array_slice($asking, 0, 6, true) as $al): $lz = (array)$al['listen']; $rb = 'sfp-' . pm_h((string)$al['id']); ?>
    <article class="sf-card sf-post">
      <div class="sf-tags"><span class="pill"><?= pm_h((string)$lz['platform']) ?></span><?php if (!empty($lz['posted'])): ?><span class="pill"><?= pm_h(date('j M', strtotime((string)$lz['posted']))) ?></span><?php endif; ?><span class="pill"><?= pm_h((string)$al['city']) ?></span></div>
      <h3 class="sf-name"><a href="<?= pm_h((string)$lz['url']) ?>" target="_blank" rel="noopener noreferrer"><?= pm_h((string)$al['name']) ?> <span aria-hidden="true">↗</span></a></h3>
      <p class="sf-why">“<?= pm_h((string)$lz['wants']) ?>”</p>
      <label for="<?= $rb ?>" class="t-sm muted">Our reply, to paste under their post</label>
      <textarea id="<?= $rb ?>" rows="3" readonly><?= pm_h((string)$lz['reply']) ?></textarea>
      <div class="btns"><button type="button" class="btn small" data-copy-target="#<?= $rb ?>">Copy the reply</button><a class="btn small primary" href="<?= pm_h((string)$lz['url']) ?>" target="_blank" rel="noopener noreferrer">Open the post</a>
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= pm_h((string)$al['id']) ?>"><input type="hidden" name="status" value="contacted"><input type="hidden" name="bt" value="scout"><button class="btn small" title="You answered under their post: it moves to Contacted">I replied</button></form>
        <a class="btn small ghost" href="?tab=agents&amp;lead=<?= pm_h(rawurlencode((string)$al['id'])) ?>">Open the lead</a></div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if (count($asking) > 6): ?><p class="hint mb-0"><a href="?tab=agents&amp;status=people">All <?= count($asking) ?> people looking</a></p><?php endif; ?>
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
