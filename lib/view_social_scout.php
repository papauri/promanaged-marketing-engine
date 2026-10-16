<?php
/**
 * Social > Scouting: one list at a time, each row one line that opens for the details, so the page stays short however much the desk finds.
 *   People asking   individual posts where someone asks for something we offer (our connected Facebook and Instagram, and public posts found by search), each with a reply to paste
 *   Places          where our buyers gather and a move from us can bring sales (lib/sx_scout.php), by decision: to decide, with the team, done, not for us
 * The owner decides; nothing is posted by itself. Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$st = pm_sf_state($vb);
$c = pm_sf_counts($vb);
$nm = pm_agent_first('social');
$tab = (string)($_GET['st'] ?? '');
$tab = $tab === 'posts' || isset(PM_SF_STATUS[$tab]) ? $tab : 'posts';
$sx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h($v) . '">';
$goalOk = $c['acted7'] >= $c['goal'];
$fb = pm_social_cfg($vb);
$pst = (array)(pm_listen_state($vb)['posts'] ?? []);
$srcs = (array)($pst['sources'] ?? []);
$asking = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $vb && !empty($l['listen']) && ($l['status'] ?? '') === 'new');
uasort($asking, fn($a, $b) => strcmp((string)($b['listen']['found'] ?? ''), (string)($a['listen']['found'] ?? '')));
$ago = fn(string $at) => $at === '' ? '' : date('j M, H:i', strtotime($at));
?>
<?= pm_social_nav('scout') ?>
<section class="card sc-head">
  <div class="sc-top">
    <div>
      <h2 class="mt-0 mb-0"><?= pm_h($nm) ?>'s scouting desk</h2>
      <p class="hint mt-0 mb-0">People on social media asking for what we sell, and the places where our buyers gather. You decide; nothing is posted by itself.</p>
    </div>
    <form method="post" class="sc-now" data-confirm="Scout now: posts where people ask for what we offer, and places where our buyers gather (two short AI web searches and two calls)?"><?= $sx('sf_scout') ?>
      <button class="btn primary"<?= pm_agents_ready() ? '' : ' disabled title="Add an AI key to .env first"' ?>>Scout now</button>
      <small class="muted"><?= !empty($pst['at']) ? 'Posts read ' . pm_h($ago((string)$pst['at'])) : 'Posts not read yet' ?><?= $c['at'] !== '' ? ' · places ' . pm_h($ago($c['at'])) : '' ?></small>
    </form>
  </div>
  <div class="sc-src" aria-label="Where the desk reads">
    <span class="sc-chip <?= $fb['ready'] ? 'ok' : 'off' ?>" title="Posts in which people tag or mention our Page. Comments and messages on our own posts are in the Inbox."><b>Facebook Page</b> <?= $fb['ready'] ? pm_h((string)($srcs['facebook'] ?? 'connected')) : '<a href="?tab=settings&amp;section=connections">connect it</a>' ?></span>
    <span class="sc-chip <?= $fb['ready'] && $fb['ig_id'] !== '' ? 'ok' : 'off' ?>" title="The newest public posts under our hashtags (#<?= pm_h(implode(', #', pm_listen_hashtags($vb))) ?>), and posts that tag us"><b>Instagram</b> <?= $fb['ready'] && $fb['ig_id'] !== '' ? pm_h((string)($srcs['instagram'] ?? 'linked')) : 'not linked: <a href="?tab=settings&amp;section=connections">link it</a> to read hashtag posts' ?></span>
    <span class="sc-chip ok" title="Public Facebook posts and groups, Instagram, TikTok and X, searched for people asking"><b>Web search</b> public social media posts</span>
  </div>
  <?php if (!empty($pst['error'])): ?><p class="hint warnt mb-0">Last read failed: <?= pm_h(mb_substr((string)$pst['error'], 0, 120)) ?></p><?php endif; ?>
</section>

<?php
$tabs = ['posts' => ['People asking (' . count($asking) . ')', '?tab=social&view=scout&st=posts']];
foreach (PM_SF_STATUS as $k => $lab) {
    $tabs[$k] = [($k === 'new' ? 'Places to decide' : pm_h($lab)) . ' (' . (int)($c[$k] ?? 0) . ')', '?tab=social&view=scout&st=' . $k];
}
echo pm_ui_tabs($tabs, $tab, 'Scouting');
?>

<?php if ($tab === 'posts'): [$page, $pager] = pm_ui_page($asking, 15); ?>
<section class="card sc-list">
  <p class="hint mt-0">Open the post, paste the reply under it, then press <b>I replied</b>: no app may comment under other people's posts, so that step is yours. Read again every few hours in the day.</p>
  <?php if (!$asking): ?><p class="muted mb-0">Nobody waiting for a reply. Press Scout now, or the desk reads the posts again later today.</p><?php endif; ?>
  <?php foreach ($page as $al): $lz = (array)$al['listen']; $rb = 'sfp-' . pm_h((string)$al['id']); ?>
  <details class="sc-row">
    <summary>
      <span class="pill"><?= pm_h((string)$lz['platform']) ?></span>
      <span class="sc-main"><b><?= pm_h((string)$al['name']) ?></b><span class="sc-sub">“<?= pm_h((string)$lz['wants']) ?>”</span></span>
      <span class="sc-meta"><?= !empty($lz['posted']) ? pm_h(date('j M', strtotime((string)$lz['posted']))) : '' ?></span>
    </summary>
    <div class="sc-body">
      <label for="<?= $rb ?>" class="t-sm muted">Our reply, to paste under their post</label>
      <textarea id="<?= $rb ?>" rows="3" readonly><?= pm_h((string)$lz['reply']) ?></textarea>
      <div class="btns"><button type="button" class="btn small" data-copy-target="#<?= $rb ?>">Copy the reply</button><a class="btn small primary" href="<?= pm_h((string)$lz['url']) ?>" target="_blank" rel="noopener noreferrer">Open the post ↗</a>
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= pm_h((string)$al['id']) ?>"><input type="hidden" name="status" value="contacted"><input type="hidden" name="bt" value="scout"><button class="btn small" title="You answered under their post: it moves to Contacted">I replied</button></form>
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= pm_h((string)$al['id']) ?>"><input type="hidden" name="status" value="lost"><input type="hidden" name="lost_reason" value="not_interested"><input type="hidden" name="bt" value="scout"><button class="btn small ghost" title="Not someone we can help: off the list">Not for us</button></form>
        <a class="btn small ghost" href="?tab=agents&amp;lead=<?= pm_h(rawurlencode((string)$al['id'])) ?>">Open the lead</a></div>
    </div>
  </details>
  <?php endforeach; ?>
  <?= $pager ?>
</section>

<?php else: $items = array_filter($st['items'], fn($f) => ($f['status'] ?? 'new') === $tab);
  uasort($items, fn($a, $b) => $tab === 'new' ? [(int)$b['fit'], (string)$b['found']] <=> [(int)$a['fit'], (string)$a['found']] : strcmp((string)($b['decided_at'] ?? ''), (string)($a['decided_at'] ?? '')));
  [$page, $pager] = pm_ui_page($items, 15); ?>
<section class="card sc-list">
  <div class="sc-goal">
    <span class="hint mt-0 mb-0"><?= $tab === 'new' ? 'Groups, pages, events and busy conversations where a move from us can bring sales.' : '' ?> Acted on <b class="<?= $goalOk ? 'okt' : '' ?>"><?= $c['acted7'] ?> of <?= $c['goal'] ?></b> this week.</span>
    <form method="post" class="sf-goal"><?= $sx('sf_goal') ?><label class="muted">Goal a week <input type="number" name="goal" min="1" max="50" value="<?= (int)$c['goal'] ?>" aria-label="Finds to act on each week"></label><button class="btn small ghost">Set</button></form>
  </div>
  <?php if (!$items): ?><p class="muted mb-0"><?= $tab === 'new' ? ($c['at'] === '' ? 'Nothing scouted yet. Press Scout now.' : 'Nothing waits for a decision. Press Scout now, or the desk looks again on the next working day.') : 'Nothing here.' ?></p><?php endif; ?>
  <?php foreach ($page as $f): $id = (string)$f['id']; $box = 'sft-' . $id; $dn = pm_talk_name((string)$f['dept']); ?>
  <details class="sc-row">
    <summary>
      <span class="pill <?= (int)$f['fit'] >= 70 ? 'hot' : '' ?>" title="How much it could bring us, out of 100"><?= (int)$f['fit'] ?></span>
      <span class="sc-main"><b><?= pm_h((string)$f['name']) ?></b><span class="sc-sub"><?= pm_h(PM_SF_KINDS[$f['kind']] ?? $f['kind']) ?> · <?= pm_h((string)$f['platform']) ?> · <?= pm_h(PM_SF_MOVES[$f['move']] ?? $f['move']) ?>, <?= pm_h(explode(' ', $dn)[0]) ?></span></span>
      <span class="sc-meta"><?= !empty($f['liked']) ? 'more like this' : pm_h(date('j M', strtotime((string)($f['found'] ?? 'now')))) ?></span>
    </summary>
    <div class="sc-body">
      <?php if ($f['audience'] !== '' || $f['activity'] !== ''): ?><p class="sf-meta"><?= pm_h(implode(' · ', array_filter([(string)$f['audience'], (string)$f['activity']]))) ?></p><?php endif; ?>
      <p class="sf-why"><?= pm_h((string)$f['why']) ?></p>
      <?php if ($f['plan'] !== ''): ?><p class="sf-move"><b><?= pm_h(PM_SF_MOVES[$f['move']] ?? $f['move']) ?>:</b> <?= pm_h((string)$f['plan']) ?></p><?php endif; ?>
      <?php if ($f['text'] !== ''): ?><label for="<?= $box ?>" class="t-sm muted">The words to use</label><textarea id="<?= $box ?>" rows="3" readonly><?= pm_h((string)$f['text']) ?></textarea><?php endif; ?>
      <?php if (($f['status'] ?? 'new') !== 'new'): ?><p class="hint"><?= pm_h(PM_SF_STATUS[$f['status']]) ?><?= !empty($f['decided_at']) ? ' since ' . pm_h(date('j M', strtotime((string)$f['decided_at']))) : '' ?><?= !empty($f['by']) ? ' · ' . pm_h((string)$f['by']) : '' ?><?= !empty($f['note']) ? ': ' . pm_h((string)$f['note']) : '' ?></p><?php endif; ?>
      <form method="post" class="sf-act"><?= $sx('sf_decide', $hid('id', $id) . $hid('st', $tab)) ?>
        <?php if ($f['status'] === 'new' || $f['status'] === 'handed'): ?><input type="text" name="note" maxlength="300" placeholder="<?= $f['status'] === 'new' ? 'A note for the team, or why it is not for us (optional)' : 'What happened? (optional)' ?>" aria-label="Note"><?php endif; ?>
        <div class="btns">
          <a class="btn small" href="<?= pm_h((string)$f['url']) ?>" target="_blank" rel="noopener noreferrer">Open ↗</a>
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
    </div>
  </details>
  <?php endforeach; ?>
  <?= $pager ?>
</section>
<?php endif; ?>
