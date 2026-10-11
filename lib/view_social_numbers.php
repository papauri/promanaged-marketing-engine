<?php
/**
 * Insights > Enter numbers: the numbers the apps do not give us. Facebook does not always give reach, saves and clicks, and nothing reaches TikTok, WhatsApp Status or Google, so they are typed
 * in here, stored as entered by you, and shown with that label on the Results. Needs $vb, $csrf.
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$sx = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$num = fn($v, $dash = '–') => $v === null || $v === '' ? $dash : number_format((float)$v);
?>
<?= pm_social_nav('numbers') ?>
<h3 id="manual">Numbers you type in</h3>
<div class="card">
  <p class="hint">Facebook does not always give reach, saves and clicks, and nothing reaches TikTok, WhatsApp Status or Google. Type what you see in the app; it is stored as entered by you and shown with that label.</p>
  <?php
  $pubs = array_slice(array_reverse(array_values(array_filter(pm_sx_posts($vb), fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > time() - 35 * 86400))), 0, 10);
  $chs = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube_short' => 'YouTube Shorts', 'whatsapp_status' => 'WhatsApp Status', 'google' => 'Google profile', 'x' => 'X'];
  if (!$pubs): ?><p class="hint">No published posts in the last 5 weeks.</p><?php endif;
  foreach ($pubs as $i => $p):
      $d7 = $p['metrics']['d7'] ?? null; $man = (array)($p['manual'] ?? []); ?>
    <details class="my-1"<?= ($p['metrics_src'] ?? '') === 'manual' && !$man && $i < 3 ? ' open' : '' ?>>
      <summary><b><?= pm_h((string)(($p['headline'] ?? '') ?: mb_substr((string)$p['caption'], 0, 50))) ?></b> <span class="rs-note"><?= pm_h(date('j M', pm_sx_pub_ts($p))) ?>
        · <?= $d7 ? 'Facebook 7-day: ' . (int)$d7['reactions'] . ' reactions, ' . (int)$d7['comments'] . ' comments, ' . (int)$d7['shares'] . ' shares' . (($d7['reach'] ?? null) !== null ? ', reach ' . number_format((int)$d7['reach']) : '') : 'no 7-day numbers yet' ?><?= $man ? ' · ' . count($man) . ' channel(s) entered by you' : '' ?></span></summary>
      <?php foreach ($man as $ch => $mm): ?><p class="rs-note"><span class="rs-chip me">entered by <?= pm_h((string)($mm['by'] ?? 'owner')) ?>, <?= pm_h((string)($mm['at'] ?? '')) ?></span> <?= pm_h($chs[$ch] ?? $ch) ?>:
        <?= implode(', ', array_map(fn($k) => $k . ' ' . (int)$mm[$k], array_filter(['views', 'reactions', 'comments', 'saves', 'shares', 'clicks', 'enquiries'], fn($k) => isset($mm[$k]) && $mm[$k] !== ''))) ?><?= !empty($mm['note']) ? ' · ' . pm_h($mm['note']) : '' ?></p><?php endforeach; ?>
      <form method="post"><?= $sx('results_save', '<input type="hidden" name="id" value="' . pm_h($p['id']) . '">') ?>
        <div class="rs-grid">
          <div><label>Channel</label><select name="channel"><?php foreach ($chs as $k => $l): ?><option value="<?= $k ?>"><?= pm_h($l) ?></option><?php endforeach; ?></select></div>
          <?php foreach (['views' => 'Views / reach', 'reactions' => 'Reactions', 'comments' => 'Comments', 'saves' => 'Saves', 'shares' => 'Shares', 'clicks' => 'Link clicks', 'enquiries' => 'Enquiries it brought'] as $k => $l): ?>
          <div><label><?= $l ?></label><input type="number" min="0" name="<?= $k ?>"></div><?php endforeach; ?>
          <div style="grid-column:span 2"><label>Note</label><input type="text" name="note" maxlength="160"></div>
        </div>
        <button class="btn small mt-1">Save as entered by me</button>
      </form>
    </details>
  <?php endforeach; ?>
  <h3>Followers on other channels</h3>
  <form method="post" class="findrow" style="align-items:end"><?= $sx('followers_manual') ?>
    <div><label class="rs-note">LinkedIn followers</label><input type="number" min="0" name="li_followers" class="narrow"></div>
    <div><label class="rs-note">TikTok followers</label><input type="number" min="0" name="tt_followers" class="narrow"></div>
    <div><label class="rs-note">WhatsApp Status views (last post)</label><input type="number" min="0" name="wa_status_views" class="narrow"></div>
    <button class="btn small">Save today's numbers</button>
  </form>
</div>

<?php if (function_exists('pm_x_summary')): $xs = pm_x_summary($vb); $xrecent = array_slice(array_reverse(array_values(array_filter(pm_sx_posts($vb), fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > time() - 35 * 86400))), 0, 14); ?>
<h3 id="xnumbers">X (Twitter)</h3>
<div class="card">
  <p class="hint"><?= pm_x_cfg()['ready'] ? 'X is connected: approved text and picture posts are sent there.' : 'X is not connected, so post there by hand with "Copy caption" and "Download picture" on each post.' ?> X gives this app no numbers: type what you see on X for a post (likes, reposts, replies). They count toward that post's score and are marked as yours.</p>
  <?php if ($xs['n']): ?>
    <div class="kpis mb-2"><div><b><?= (int)$xs['n'] ?></b><span>posts with X numbers</span></div><div><b><?= $num($xs['avg']) ?></b><span>average X engagement per post</span></div></div>
    <div class="rs-wrap"><table class="rs"><tr><th>Post</th><th class="n">Likes</th><th class="n">Reposts</th><th class="n">Replies</th><th class="n">Views</th><th class="n">Engagement</th></tr>
    <?php foreach (array_slice($xs['rows'], 0, 8) as $r): ?><tr><td><b><?= pm_h($r['headline']) ?></b><br><span class="rs-note"><?= pm_h($r['when']) ?> <span class="rs-chip me">entered by <?= pm_h($r['by'] ?: 'you') ?></span></span></td>
      <td class="n"><?= (int)$r['n']['reactions'] ?></td><td class="n"><?= (int)$r['n']['shares'] ?></td><td class="n"><?= (int)$r['n']['comments'] ?></td><td class="n"><?= $num($r['n']['reach']) ?></td><td class="n"><?= $num($r['eng']) ?></td></tr><?php endforeach; ?>
    </table></div>
  <?php endif; ?>
  <?php if (!$xrecent): ?><p class="hint">No published posts in the last 5 weeks.</p><?php else: ?>
  <form class="mt-2" method="post"><?= $sx('x_results_save') ?>
    <div class="rs-grid">
      <div style="grid-column:span 2"><label>Post</label><select name="id"><?php foreach ($xrecent as $p): ?><option value="<?= pm_h($p['id']) ?>"><?= pm_h(date('j M', pm_sx_pub_ts($p)) . ' · ' . ((string)(($p['headline'] ?? '') ?: mb_substr((string)($p['caption'] ?? ''), 0, 40))) . (($p['x'] ?? '') === 'published' ? ' (sent to X)' : '')) ?></option><?php endforeach; ?></select></div>
      <?php foreach (['likes' => 'Likes', 'reposts' => 'Reposts', 'replies' => 'Replies', 'views' => 'Views', 'clicks' => 'Link clicks'] as $k => $l): ?><div><label><?= $l ?></label><input type="number" min="0" name="<?= $k ?>"></div><?php endforeach; ?>
    </div>
    <button class="btn small mt-1">Save as entered by me</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>


<h3 id="ads">Ads: a week's results</h3>
<div class="card">
  <p class="hint">If you ran ads in Ads Manager and the app could not read the numbers, type them in. They are shown on Results with the label "entered by you".</p>
  <details><summary class="rs-note">Type in a week's ad results</summary>
    <form method="post" class="rs-grid mt-2"><?= $sx('ads_manual') ?>
      <div><label>Date (the week's last day)</label><input type="date" name="day" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Spent, MWK</label><input type="number" min="0" name="spend"></div>
      <div><label>Chats started</label><input type="number" min="0" name="chats"></div>
      <div><label>Chats that were real prospects</label><input type="number" min="0" name="qualified"></div>
      <div><label>Signed</label><input type="number" min="0" name="signed"></div>
      <div style="align-self:end"><button class="btn small">Save as entered by me</button></div>
    </form>
  </details>
</div>
