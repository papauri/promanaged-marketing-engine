<?php
/**
 * Social > Results: what happened, what it cost, what to do next. Everything here is plain arithmetic on your own numbers:
 * no AI is used to build this page. Figures typed in by you are labelled as yours; gaps are shown as gaps.
 */
pm_brand_set($vb);
$bname = $vb === 'travel' ? ($settings['travel']['company_name'] ?? 'Travel Malawi') : 'ProManaged IT';
$sc = pm_social_cfg($vb);
$sb = pm_social_scoreboard($vb);
$g = pm_social_goals($vb);
$wr = pm_social_week_report($vb);
$fun = pm_social_funnel($vb, 90);
$mix = pm_sx_mix($vb);
$exps = pm_experiments($vb);
$activeExp = pm_experiment_active($vb);
$rec = pm_recycle_candidates($vb);
$cost28 = pm_social_cost($vb, 28);
$ads = $wr['ads'];
$fol = $sb['followers'];
$resp = (array)$fun['response'];
$sx = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$num = fn($v, $dash = '–') => $v === null || $v === '' ? $dash : number_format((float)$v);
$sgn = fn($v) => $v === null ? 'no reading yet' : ($v >= 0 ? '+' : '') . $v;
$mwk = fn($v) => 'MWK ' . number_format((float)$v);
$tz = fn($s) => $s === '' ? '–' : pm_h($s);
?>
<style>
.rs-wrap{overflow-x:auto}.rs{width:100%;border-collapse:collapse;font-size:13px}.rs th,.rs td{padding:6px 8px;border-bottom:1px solid var(--line,#e5e7eb);text-align:left;vertical-align:top}
.rs th{font-weight:600;color:var(--muted,#667085);font-size:12px}.rs td.n,.rs th.n{text-align:right;white-space:nowrap}.rs tr.thin td{color:var(--muted,#667085)}
.rs-report{white-space:pre-wrap;font:13px/1.55 inherit;margin:0;background:var(--bg,#f6f7f9);border-radius:8px;padding:12px 14px}
.rs-heat td{text-align:center;min-width:64px;border:1px solid var(--line,#e5e7eb)}.rs-heat td small{display:block;color:var(--muted,#667085);font-size:11px}
.rs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}.rs-grid label{font-size:12px;color:var(--muted,#667085);display:block}
.rs-grid input,.rs-grid select{width:100%}.rs-note{font-size:12px;color:var(--muted,#667085);margin:6px 0}
.rs-chip{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;border:1px solid var(--line,#e5e7eb);color:var(--muted,#667085);white-space:nowrap}
.rs-chip.me{background:var(--warnbg,#fff7e0);border-color:#f0dcaa}
@media (max-width:640px){.rs th,.rs td{padding:5px 4px;font-size:12px}}
</style>
<h1>Social · <?= pm_h($bname) ?></h1>
<?= pm_social_nav('results') ?>

<?php if (!$sc['ready']): ?>
  <div class="card empty">Connect the <?= pm_h($bname) ?> Facebook Page first (<a href="?tab=social&amp;view=accounts">Accounts &amp; branding</a>) so numbers can be read. Anything you type in below still counts.</div>
<?php endif; ?>

<div class="kpis">
  <div><b><?= $num($fol['now']) ?><?php if ($fol['d7'] !== null): ?> <small style="font-weight:500"><?= $sgn($fol['d7']) ?> in 7 days</small><?php endif; ?></b>
    <span>Facebook followers <?= pm_sx_spark(array_values($fol['series'])) ?><?= $fol['d7'] === null ? ' · the weekly change shows after a week of readings' : '' ?><?= $fol['ig'] !== null ? ' · Instagram ' . $num($fol['ig']) : '' ?><?= $fol['li'] !== null ? ' · LinkedIn ' . $num($fol['li']) . ' (entered by you)' : '' ?><?= $fol['tt'] !== null ? ' · TikTok ' . $num($fol['tt']) . ' (entered by you)' : '' ?></span></div>
  <div><b><?= $sb['eng_rate'] !== null ? $sb['eng_rate'] . '%' : '–' ?></b><span><?= $sb['eng_rate'] !== null ? 'average engagement per post, as % of followers (7-day numbers)' : 'engagement per post: not enough data yet' ?></span></div>
  <div><b><?= $sb['enq_per_100'] !== null ? $sb['enq_per_100'] : '–' ?></b><span>enquiries per 100 followers (28 days) · <?= (int)pm_sx_social_leads_count($vb, 28) ?> enquiries</span></div>
  <div><b><?= isset($resp['median_min']) && !empty($resp['n']) ? (int)$resp['median_min'] . ' min' : ($fun['reply_median_min'] !== null ? $fun['reply_median_min'] . ' min' : '–') ?></b><span>median time to first reply<?= isset($resp['unanswered_over_2h']) ? ' · ' . (int)$resp['unanswered_over_2h'] . ' waiting over 2 h' : '' ?></span></div>
</div>

<?= pm_sx_goal_strip($vb) ?>

<details class="card"<?= !array_filter([$g['followers_target'], $g['max_cost_per_conv_mwk'], $g['daily_token_budget']]) ? ' open' : '' ?>>
  <summary>Goals and limits</summary>
  <form method="post"><?= $sx('goals_save') ?>
    <div class="rs-grid">
      <div><label>Followers target (0 = none)</label><input type="number" min="0" name="followers_target" value="<?= (int)$g['followers_target'] ?>"></div>
      <div><label>…by this date</label><input type="date" name="followers_date" value="<?= pm_h($g['followers_date']) ?>"></div>
      <div><label>Enquiries a week from social</label><input type="number" min="0" name="enquiries_per_week" value="<?= (int)$g['enquiries_per_week'] ?>"></div>
      <div><label>Leads a month from social</label><input type="number" min="0" name="leads_per_month" value="<?= (int)$g['leads_per_month'] ?>"></div>
      <div><label>Posts a week</label><input type="number" min="0" name="posts_per_week" value="<?= (int)$g['posts_per_week'] ?>"></div>
      <div><label>Most you will pay per ad conversation, MWK (0 = no limit)</label><input type="number" min="0" name="max_cost_per_conv_mwk" value="<?= (int)$g['max_cost_per_conv_mwk'] ?>"></div>
      <div><label>AI tokens a day for this business (0 = only the global budget)</label><input type="number" min="0" name="daily_token_budget" value="<?= (int)$g['daily_token_budget'] ?>"></div>
      <div><label>AI cost estimate, MWK per 1,000 tokens</label><input type="number" min="0" step="0.5" name="mwk_per_1k_tokens" value="<?= pm_h((string)$g['mwk_per_1k_tokens']) ?>"></div>
    </div>
    <p class="rs-note">Goals only colour the bars and the advice below. They never change what is posted. The token limit stops AI calls for this business for the rest of the day when it is reached.</p>
    <button class="btn primary small">Save goals</button>
  </form>
</details>

<div class="card">
  <div class="findrow" style="justify-content:space-between"><h2 style="margin:0">This week</h2>
    <button type="button" class="btn small" data-copy="<?= pm_h(pm_social_week_text($wr)) ?>">Copy report</button></div>
  <pre class="rs-report"><?= pm_h(pm_social_week_text($wr)) ?></pre>
  <p class="rs-note">Built from your stored numbers only. A copy is emailed to the owner on Mondays (once a week). Figures you typed in are marked as entered by you.</p>
</div>

<h3>Scoreboard</h3>
<?php if (!$sb['enough']): ?>
  <div class="card empty"><?= pm_h($sb['note']) ?><br><span class="rs-note">Each post is scored once, from its 7-day numbers, so young posts never look better or worse than old ones. Nothing is ranked until 8 posts have their numbers.</span></div>
<?php else: ?>
  <div class="card">
    <div class="rs-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
      <?php foreach (['top5' => 'Top 5 posts', 'bottom5' => 'Bottom 5 posts'] as $k => $title): ?>
      <div><h2><?= $title ?></h2><div class="rs-wrap"><table class="rs"><tr><th>Post</th><th class="n">Engagement</th><th>Why</th></tr>
        <?php foreach ($sb[$k] as $r): ?><tr><td><b><?= pm_h($r['headline']) ?></b><br><span class="rs-note"><?= pm_h($r['when']) ?> <span class="rs-chip<?= $r['src'] === 'api' ? '' : ' me' ?>"><?= $r['src'] === 'api' ? 'Facebook numbers' : pm_h($r['src'] === 'manual' ? 'entered by you' : 'Facebook + yours') ?></span></span></td>
          <td class="n"><?= $num($r['eng']) ?></td><td class="rs-note"><?= pm_h($r['reason']) ?></td></tr><?php endforeach; ?>
      </table></div></div>
      <?php endforeach; ?>
    </div>
    <p class="rs-note">Engagement = reactions + 2 comments + 3 shares + 3 saves + 2 clicks, +5 for each enquiry the post brought. Average across the <?= (int)$sb['n'] ?> scored posts: <?= pm_h((string)$sb['avg_eng']) ?>.</p>
  </div>
<?php endif; ?>

<?php
$tbl = function (string $title, array $grp, string $key) use ($num) {
    if (!$grp) {
        return '';
    }
    $h = '<div><h2>' . pm_h($title) . '</h2><div class="rs-wrap"><table class="rs"><tr><th>' . pm_h($key) . '</th><th class="n">Posts</th><th class="n">Avg engagement</th><th class="n">per 1,000 followers</th><th class="n">vs average</th><th class="n">Enquiries</th><th class="n">Signed</th></tr>';
    foreach ($grp as $k => $b) {
        $h .= '<tr' . ($b['enough'] ? '' : ' class="thin"') . '><td>' . pm_h($k) . '</td><td class="n">' . $b['n'] . '</td>';
        $h .= $b['enough'] ? '<td class="n">' . $b['avg_eng'] . '</td><td class="n">' . ($b['per_1000_followers'] ?? '–') . '</td><td class="n">' . ($b['vs_avg'] !== null ? $b['vs_avg'] . 'x' : '–') . '</td>'
            : '<td class="n" colspan="3">not enough data (needs ' . PM_SX_MIN_BUCKET . ' posts)</td>';
        $h .= '<td class="n">' . $b['leads'] . '</td><td class="n">' . $b['won'] . '</td></tr>';
    }
    return $h . '</table></div></div>';
};
if ($sb['n']): ?>
<div class="card"><div class="rs-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">
  <?= $tbl('By pillar', $sb['groups']['pillar'], 'Pillar') ?>
  <?= $tbl('By call to action', $sb['groups']['cta'], 'Call to action') ?>
  <?= $tbl('By format', $sb['groups']['format'], 'Format') ?>
  <?= $tbl('By hook', $sb['groups']['hook_pattern'], 'Hook') ?>
  <?= $tbl('By picture layout', $sb['groups']['layout'], 'Layout') ?>
  <?= $tbl('By time of day', $sb['groups']['daypart'], 'Time') ?>
  <?= $tbl('By weekday', $sb['groups']['weekday'], 'Day') ?>
</div></div>

<div class="card"><h2>Best times (weekday × time of day)</h2>
  <?php $maxv = 0; foreach ($sb['heat'] as $row) { foreach ($row as $c) { $maxv = $c['enough'] ? max($maxv, $c['avg']) : $maxv; } } ?>
  <div class="rs-wrap"><table class="rs rs-heat heat"><tr><th></th><?php foreach (PM_SX_DAYPARTS as $d): ?><th><?= pm_h($d[2]) ?></th><?php endforeach; ?></tr>
  <?php foreach (PM_SX_DOW as $di => $dn): ?><tr><th><?= $dn ?></th>
    <?php foreach (array_keys(PM_SX_DAYPARTS) as $part): $c = $sb['heat'][$di][$part] ?? null;
        $v = $c && $c['enough'] && $maxv > 0 ? $c['avg'] / $maxv : 0; ?>
      <td data-v="<?= $c ? pm_h((string)$c['avg']) : '' ?>"<?= $v > 0 ? ' style="background:rgba(37,99,235,' . round(0.08 + 0.5 * $v, 2) . ')"' : '' ?>><?= $c ? ($c['enough'] ? '<b>' . $c['avg'] . '</b>' : '<span class="rs-note">' . $c['avg'] . '?</span>') . '<small>n=' . $c['n'] . '</small>' : '<small>n=0</small>' ?></td>
    <?php endforeach; ?></tr><?php endforeach; ?>
  </table></div>
  <p class="rs-note">Average engagement per post; blue shows the stronger cells. A cell needs <?= PM_SX_MIN_BUCKET ?> posts before it is trusted (shown with a ?). The planner uses these times, leaning on the Malawi habit until the Page has its own data:
    <?php $sl = pm_social_slots($vb); echo pm_h(implode(', ', array_map(fn($s) => PM_SX_DOW[$s['dow']] . ' ' . $s['time'] . ($s['src'] === 'explore' ? ' (trying)' : ($s['src'] === 'prior' ? ' (habit)' : '')), $sl))); ?>.</p>
</div>
<?php endif; ?>

<div class="card"><h2>Suggested content mix</h2>
<?php if (!$mix): ?><p class="hint">Not enough results yet: at least 8 scored posts, and 3 in each of two pillars, are needed before a change is suggested.</p>
<?php else: ?>
  <div class="rs-wrap"><table class="rs"><tr><th>Pillar</th><th class="n">Now</th><th class="n">Suggested</th><th class="n">Posts scored</th><th class="n">Avg engagement</th></tr>
  <?php foreach ($mix as $name => $m): ?><tr><td><?= pm_h($name) ?></td><td class="n"><?= round($m['old'] * 100) ?>%</td><td class="n"><b><?= round($m['new'] * 100) ?>%</b></td><td class="n"><?= (int)$m['n'] ?></td><td class="n"><?= $m['avg'] ?? '–' ?></td></tr><?php endforeach; ?>
  </table></div>
  <form method="post" class="btns"><?= $sx('apply_weights') ?><button class="btn primary small" data-confirm="Change the content mix to these shares? You can change it back under Accounts.">Apply suggested mix</button>
    <span class="rs-note">70% of today's mix + 30% of what performed, at least 10% for every pillar. Nothing changes until you press the button.</span></form>
<?php endif; ?></div>

<h3>Enquiries and sales from social</h3>
<div class="card">
  <?php $T = $fun['total']; ?>
  <div class="kpis" style="margin-bottom:8px">
    <div><b><?= (int)$T['leads'] ?></b><span>enquiries (90 days)</span></div>
    <div><b><?= (int)$T['replied'] ?></b><span>replied · <?= (int)$T['proposal'] ?> reached a proposal</span></div>
    <div><b><?= (int)$T['won'] ?></b><span>signed</span></div>
    <div><b><?= $mwk($T['value']) ?></b><span>signed value (set-up + 12 months)</span></div>
  </div>
  <?php if (!$T['leads']): ?><p class="hint">No enquiries are linked to social yet. Enquiries count once they carry a source (a post code, a link tag or a logged enquiry): use "Log an enquiry" below for WhatsApp chats and calls.</p>
  <?php else: ?>
  <div class="rs-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
  <?php foreach (['source' => 'Source', 'tag' => 'Link / code', 'post' => 'Post', 'pillar' => 'Pillar', 'format' => 'Format', 'channel' => 'Channel'] as $d => $title): if (!$fun['by'][$d]) { continue; } ?>
    <div><h2><?= $title ?></h2><div class="rs-wrap"><table class="rs"><tr><th><?= $title ?></th><th class="n">Leads</th><th class="n">Replied</th><th class="n">Proposal</th><th class="n">Signed</th><th class="n">Value</th></tr>
    <?php foreach (array_slice($fun['by'][$d], 0, 8, true) as $k => $b): ?><tr><td><?= pm_h($d === 'post' ? ($fun['post_labels'][$k] ?? $k) : $k) ?></td><td class="n"><?= $b['leads'] ?></td><td class="n"><?= $b['replied'] ?></td><td class="n"><?= $b['proposal'] ?></td><td class="n"><?= $b['won'] ?></td><td class="n"><?= $b['value'] > 0 ? $mwk($b['value']) : '–' ?></td></tr><?php endforeach; ?>
    </table></div></div>
  <?php endforeach; ?></div>
  <?php endif; ?>
  <p class="rs-note">First reply: <?= $fun['reply_median_min'] !== null ? 'median ' . $fun['reply_median_min'] . ' min from first message to first reply (' . $fun['reply_n'] . ' lead' . ($fun['reply_n'] === 1 ? '' : 's') . ' with both times)' : 'no lead has both times recorded yet' ?><?= !empty($resp['n']) ? ' · comments and messages: median ' . (int)$resp['median_min'] . ' min' . (isset($resp['p90_min']) ? ', 90% within ' . (int)$resp['p90_min'] . ' min' : '') . (isset($resp['pct_under_2h']) ? ', ' . (int)$resp['pct_under_2h'] . '% answered under 2 hours' : '') : '' ?>.
    Signed value comes from signed proposals only.</p>
</div>

<h3>Experiments</h3>
<div class="card" id="experiments">
  <?php if ($activeExp): $ev = pm_experiment_eval($activeExp['id']); $cnt = pm_sx_exp_counts($activeExp['id'], $vb); ?>
    <p><b>Running:</b> <?= pm_h($activeExp['hypothesis']) ?> <span class="rs-chip">since <?= pm_h($activeExp['start']) ?></span></p>
    <div class="rs-wrap"><table class="rs"><tr><th>Way</th><th class="n">Planned</th><th class="n">Scored (7-day numbers)</th><th class="n">Avg engagement</th></tr>
    <?php foreach (['A' => 'Current way', 'B' => 'Challenger'] as $arm => $lab): ?><tr><td><?= $lab ?>: <?= pm_h($activeExp['arms'][$arm]['label']) ?></td><td class="n"><?= (int)$cnt[$arm] ?></td><td class="n"><?= (int)($ev['figures'][$arm]['n'] ?? 0) ?></td><td class="n"><?= ($ev['figures'][$arm]['avg_eng'] ?? null) !== null ? $ev['figures'][$arm]['avg_eng'] : '–' ?></td></tr><?php endforeach; ?>
    </table></div>
    <p class="rs-note"><?= pm_h($ev['summary']) ?> It needs <?= (int)$activeExp['min_posts'] ?> scored posts on each side, so the answer arrives in a few weeks. Small samples can mislead: a result needs 15% and a clear gap.</p>
    <form method="post"><?= $sx('experiment_stop', '<input type="hidden" name="id" value="' . pm_h($activeExp['id']) . '">') ?><button class="btn small" data-confirm="Stop this experiment? Nothing will be concluded from it.">Stop it</button></form>
  <?php else: ?>
    <p class="hint">No experiment is running. The Director starts the next one by itself from this fixed list, or you can start one:</p>
    <?php foreach (PM_SX_EXP_MENU as $key => $m): ?>
      <form method="post" class="findrow" style="margin:4px 0;align-items:center"><?= $sx('experiment_start', '<input type="hidden" name="key" value="' . pm_h($key) . '">') ?><span style="flex:1 1 280px"><?= pm_h($m['hypothesis']) ?></span><button class="btn small">Start</button></form>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php $done = array_filter($exps, fn($e) => ($e['status'] ?? '') === 'done'); if ($done): ?>
    <h3>Finished</h3>
    <?php foreach (array_reverse($done) as $e): ?><p style="margin:4px 0"><span class="rs-chip"><?= pm_h($e['verdict'] ?: 'done') ?></span> <?= pm_h($e['learning'] !== '' ? $e['learning'] : $e['hypothesis']) ?> <span class="rs-note"><?= pm_h($e['start']) ?> to <?= pm_h($e['end'] ?? '') ?></span></p><?php endforeach; ?>
  <?php endif; ?>
</div>

<h3>Bring back what worked</h3>
<div class="card">
<?php if (!$rec): ?><p class="hint">No post is ready to come back yet. A post can return when it is among the best 20% by 7-day engagement, older than 45 days, not about a date or offer, and not used again in the last 90 days. At least 5 such posts are needed before one is picked.</p>
<?php else: ?>
  <div class="rs-wrap"><table class="rs"><tr><th>Post</th><th class="n">Engagement</th><th class="n">Age</th><th></th></tr>
  <?php foreach ($rec as $c): ?><tr><td><?= pm_h((string)(($c['post']['headline'] ?? '') ?: mb_substr((string)$c['post']['caption'], 0, 60))) ?><br><span class="rs-note"><?= pm_h((string)($c['post']['pillar'] ?? '')) ?></span></td><td class="n"><?= $num($c['eng']) ?></td><td class="n"><?= (int)$c['age_days'] ?> days</td>
    <td><form method="post"><?= $sx('recycle', '<input type="hidden" name="id" value="' . pm_h($c['post']['id']) . '">') ?><button class="btn small">Recycle</button></form></td></tr><?php endforeach; ?>
  </table></div>
  <p class="rs-note">A recycled post becomes a new draft with a fresh opening line and hashtags. It goes through the same checks and waits for your approval (unless Auto-publish is on).</p>
<?php endif; ?></div>

<h3 id="manual">Numbers you type in</h3>
<div class="card">
  <p class="hint">Facebook does not always give reach, saves and clicks, and nothing reaches TikTok, WhatsApp Status or Google. Type what you see in the app; it is stored as entered by you and shown with that label.</p>
  <?php
  $pubs = array_slice(array_reverse(array_values(array_filter(pm_sx_posts($vb), fn($p) => ($p['status'] ?? '') === 'published' && pm_sx_pub_ts($p) > time() - 35 * 86400))), 0, 10);
  $chs = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube_short' => 'YouTube Shorts', 'whatsapp_status' => 'WhatsApp Status', 'google' => 'Google profile', 'x' => 'X'];
  if (!$pubs): ?><p class="hint">No published posts in the last 5 weeks.</p><?php endif;
  foreach ($pubs as $i => $p):
      $d7 = $p['metrics']['d7'] ?? null; $man = (array)($p['manual'] ?? []); ?>
    <details style="margin:6px 0"<?= ($p['metrics_src'] ?? '') === 'manual' && !$man && $i < 3 ? ' open' : '' ?>>
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
        <button class="btn small" style="margin-top:6px">Save as entered by me</button>
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

<h3>Ads</h3>
<div class="card">
  <?php if ($ads['source'] === 'none'): ?><p class="hint"><?= $ads['connected'] ? 'No ad spend in the last 7 days.' : 'Ads are not connected, so there are no ad numbers. If you run ads in Ads Manager, type the week in below.' ?><?= $ads['error'] !== '' ? ' Facebook said: ' . pm_h($ads['error']) : '' ?></p>
  <?php else: ?>
    <div class="kpis" style="margin-bottom:8px">
      <div><b><?= $mwk($ads['spend_mwk']) ?></b><span>spent, last 7 days</span></div>
      <div><b><?= (int)$ads['conversations'] ?></b><span>chats started</span></div>
      <div><b><?= $ads['cost_per_conversation'] !== null ? $mwk($ads['cost_per_conversation']) : '–' ?></b><span>per chat<?= (int)$g['max_cost_per_conv_mwk'] > 0 ? ' · limit ' . $mwk($g['max_cost_per_conv_mwk']) : '' ?></span></div>
      <div><b><?= $ads['cost_per_click'] !== null ? $mwk($ads['cost_per_click']) : '–' ?></b><span>per click · reach <?= $num($ads['reach']) ?></span></div>
    </div>
    <p class="rs-note">Source: <?= pm_h(['api' => 'Facebook ads insights (read only)', 'manual' => 'figures entered by ' . ($ads['manual_by'] ?: 'you'), 'both' => 'Facebook ads insights plus figures entered by ' . ($ads['manual_by'] ?: 'you')][$ads['source']] ?? '') ?>. This page never changes ad spend.</p>
    <?php foreach ($ads['hints'] as $h): ?><p class="hint warnt"><?= pm_h($h) ?></p><?php endforeach; ?>
  <?php endif; ?>
  <details><summary class="rs-note">Type in a week's ad results</summary>
    <form method="post" class="rs-grid" style="margin-top:8px"><?= $sx('ads_manual') ?>
      <div><label>Date (the week's last day)</label><input type="date" name="day" value="<?= date('Y-m-d') ?>"></div>
      <div><label>Spent, MWK</label><input type="number" min="0" name="spend"></div>
      <div><label>Chats started</label><input type="number" min="0" name="chats"></div>
      <div><label>Chats that were real prospects</label><input type="number" min="0" name="qualified"></div>
      <div><label>Signed</label><input type="number" min="0" name="signed"></div>
      <div style="align-self:end"><button class="btn small">Save as entered by me</button></div>
    </form>
  </details>
</div>

<h3>What it costs</h3>
<div class="card">
  <div class="kpis" style="margin-bottom:8px">
    <div><b><?= $cost28['tokens_per_post'] !== null ? number_format($cost28['tokens_per_post']) : '–' ?></b><span>AI tokens per published post (<?= $cost28['tokens_per_post_src'] === 'planner' ? 'as the planner recorded' : 'estimate: social AI use ÷ posts' ?>)</span></div>
    <div><b><?= number_format($cost28['social_tokens']) ?></b><span>social AI tokens, 28 days (all AI for this business: <?= number_format($cost28['tokens']) ?>)</span></div>
    <div><b><?= $mwk($cost28['token_cost_mwk']) ?></b><span>estimated AI cost at MWK <?= pm_h((string)$cost28['rate']) ?> per 1,000 tokens</span></div>
    <div><b><?= $cost28['cost_per_lead'] !== null ? $mwk($cost28['cost_per_lead']) : '–' ?></b><span>per social enquiry (ads + AI) · <?= (int)$cost28['leads'] ?> enquiries</span></div>
  </div>
  <p class="rs-note">The AI is only used to write short posts and replies and to read comments; this page, the scoreboard, the times and the report cost no tokens.</p>
</div>

<?= pm_social_panels('results', $vb) ?>
