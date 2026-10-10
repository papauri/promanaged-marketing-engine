<?php
/** Social screen (Plan): readiness, numbers, AI post planner, approval queue grouped by day, published posts, comments to answer. Modules add panels via pm_social_panels(). */
pm_brand_set($vb);
$sc = pm_social_cfg($vb);
$page = $sc['ready'] ? pm_social_page($vb) : ['ok' => false];
$all = array_filter(pm_social_posts(), fn($p) => $p['brand'] === $vb);
uasort($all, fn($a, $b) => strcmp($a['when'], $b['when']));
$QSTATUS = ['draft', 'review', 'approved', 'failed', 'publishing', 'needs_edit', 'needs_video', 'needs_check', 'needs_asset'];
$queue = array_filter($all, fn($p) => in_array($p['status'], $QSTATUS, true));
$expired = array_filter($all, fn($p) => $p['status'] === 'expired');
$igBad = array_filter($all, fn($p) => $p['status'] === 'published' && ($p['ig_post'] ?? '') === '' && preg_match('/^(failed|check|pending)/', (string)($p['ig'] ?? '')));
$canAll = count(array_filter($queue, fn($p) => in_array($p['status'], ['draft', 'review'], true)));
$spill = ['approved' => 'hot', 'failed' => 'warn', 'needs_edit' => 'warn', 'needs_video' => 'warn', 'needs_check' => 'warn', 'needs_asset' => 'warn', 'review' => 'warn'];
$slabel = ['needs_edit' => 'Needs an edit', 'needs_video' => 'Needs video', 'needs_check' => 'Check the Page', 'publishing' => 'Posting now', 'review' => 'Waiting for approval', 'needs_asset' => 'Needs a photo', 'expired' => 'Expired'];
$done = array_reverse(array_filter($all, fn($p) => $p['status'] === 'published'), true);
$recent = $sc['ready'] ? pm_social_recent($vb) : ['posts' => [], 'open_comments' => [], 'error' => ''];
$auto = !empty(pm_brand_block($settings, $vb)['social_auto']);
$bname = pm_brand_title($settings, $vb);
$canApprove = pm_social_can_approve();
$sp = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="social"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$weekPosts = count(array_filter($done, fn($p) => strtotime($p['published'] ?? '') > time() - 7 * 86400));
$ready = pm_social_readiness($vb);
$notReady = array_filter($ready, fn($r) => !$r['ok']);
$maxMb = pm_social_upload_max_mb();
$today = date('Y-m-d');
$byDay = [];
foreach ($queue as $p) {
    $byDay[substr($p['when'], 0, 10)][] = $p;
}
ksort($byDay);
$isVideo = fn($f) => (bool)preg_match('/\.(mp4|mov|m4v)$/i', (string)$f);
?>
<?php $nav = pm_social_nav(''); echo $nav; if (!str_contains($nav, 'sx-banner')) { echo pm_social_banner(); } ?>
<?= pm_social_panels('plan_top', $vb) ?>

<details class="card sx-ready" <?= $notReady ? 'open' : '' ?>>
  <summary><b>Ready to post well?</b> <span class="sx-chip <?= $notReady ? 'warn' : 'ok' ?>"><?= count($ready) - count($notReady) ?> of <?= count($ready) ?> ready</span></summary>
  <ul class="sx-checks">
    <?php foreach ($ready as $r): ?><li><span class="sx-chip <?= $r['ok'] ? 'ok' : 'warn' ?>"><?= $r['ok'] ? 'OK' : 'To do' ?></span> <?= pm_h($r['label']) ?><?php if (!$r['ok']): ?> <a href="<?= pm_h($r['fix']) ?>">Fix</a><?php endif; ?></li><?php endforeach; ?>
  </ul>
</details>

<?php if (!$sc['ready']): ?>
  <div class="card setup">
    <b>Connect the <?= pm_h($bname) ?> Facebook Page</b>
    <p class="hint">You can already plan and prepare posts below. To publish them, read numbers and answer comments, the app needs to be connected to the Page. It takes about 5 minutes and you do it once.</p>
    <div class="btns"><a class="btn primary" href="?tab=social&amp;view=accounts">Connect Facebook in 3 steps</a></div>
  </div>
<?php elseif (empty($page['ok'])): ?>
  <div class="flash err">Facebook did not accept the connection: <?= pm_h((string)($page['error'] ?? '')) ?></div>
<?php endif; ?>

<div class="kpis">
  <div><b><?= !empty($page['ok']) ? number_format((int)$page['followers']) : '—' ?></b><span><?= !empty($page['ok']) ? 'followers · ' . pm_h($page['name']) : 'Page not connected' ?></span></div>
  <div><b><?= $weekPosts ?></b><span>posts published this week</span></div>
  <div><b><?= count($queue) ?></b><span>planned · <?= count(array_filter($queue, fn($p) => $p['status'] === 'approved')) ?> approved</span></div>
  <div><b><?= count($recent['open_comments']) ?></b><span>comments to answer</span></div>
  <?= pm_social_panels('plan_kpi', $vb) ?>
</div>

<div class="card">
  <h2>Plan posts with AI</h2>
  <form method="post" class="findrow"><?= $sp('splan') ?>
    <select name="n" aria-label="How many"><?php foreach ([3, 5, 7, 10] as $k): ?><option value="<?= $k ?>" <?= $k === 5 ? 'selected' : '' ?>><?= $k ?> posts</option><?php endforeach; ?></select>
    <select name="focus" aria-label="Goal"><?php foreach (PM_SOCIAL_FOCUS as $k => $l): ?><option value="<?= $k ?>"><?= pm_h($l) ?></option><?php endforeach; ?></select>
    <input type="date" name="start" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" aria-label="Start date" class="narrow">
    <button class="btn primary">Plan posts</button>
  </form>
  <p class="hint">One short AI call plans a week: branded pictures, carousels, a short status and phone-video scripts, from your Marketing brain and real facts only. <?= $auto ? '<b>Auto-publish is on:</b> planned posts go out at their time without approval.' : 'Each post waits for your approval.' ?> Publishing happens on schedule even when nobody has the app open, if the scheduler is set up (README: SOCIAL).<?= $canApprove ? '' : ' <b>You are an editor:</b> press "Request approval" and an approver will check the post.' ?></p>
</div>

<?php if ($queue): ?><h3>Planned posts</h3>
<div class="btns sx-topbar">
  <?php if ($canApprove && $canAll): ?><form method="post"><?= $sp('approve_all') ?><button class="btn primary">Approve all that pass checks (<?= $canAll ?>)</button></form><?php endif; ?>
  <label class="check"><input type="checkbox" data-pick-all=".sx-pick"> Select all</label>
  <span class="hint">Posts that break a rule (prices, repeats, no consented proof, missing video or picture) stay held with the reason shown.</span>
</div>
<?php endif; ?>
<form id="bulk" method="post"><?= $sp('approve_selected') ?></form>
<?php foreach ($byDay as $day => $dayPosts): $dn = strtotime($day); ?>
<h4 class="sx-day"><?= pm_h(date('l j M', $dn)) ?> <?= $day === $today ? '<span class="sx-chip ok">today</span>' : ($day === date('Y-m-d', strtotime('+1 day')) ? '<span class="sx-chip">tomorrow</span>' : ($day < $today ? '<span class="sx-chip warn">past</span>' : '')) ?></h4>
<?php foreach ($dayPosts as $p):
    $p += ['headline' => '', 'sub' => '', 'hashtags' => [], 'script' => '', 'caption' => '', 'pillar' => '', 'error' => '', 'tries' => 0, 'format' => 'text'];
    $img = pm_social_has_media($p) ? pm_social_media($p) : (is_file(pm_social_dir() . '/' . $p['id'] . '.png') ? pm_social_dir() . '/' . $p['id'] . '.png' : '');
    $slides = array_values(array_filter((array)($p['slides'] ?? []), 'is_array'));
    $logTail = array_slice((array)($p['log'] ?? []), -3);
    $unsent = in_array($p['status'], ['draft', 'review', 'needs_edit', 'needs_video', 'needs_asset'], true);
    $panel = pm_social_panels('plan_card', $vb, ['post' => $p]);
?>
<div class="card spost" id="s<?= pm_h($p['id']) ?>">
  <div class="sgrid">
    <div class="sthumb">
      <?php if ($p['format'] === 'reel'): ?><div class="reel">Phone video<br><small>record from the script</small></div>
      <?php elseif ($p['format'] === 'text'): ?><div class="reel">Text status</div>
      <?php elseif ($img !== '' && $isVideo($img)): ?><div class="reel">Video attached</div>
      <?php elseif ($img !== ''): ?><img src="?simg=<?= pm_h($p['id']) ?>&v=<?= @filemtime($img) ?>" alt="Post picture">
      <?php elseif ($p['status'] === 'needs_asset'): ?><div class="reel">Waiting for a photo</div>
      <?php else: ?><div class="reel">Picture is drawn when you approve</div><?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data"><?= $sp('save', $hid('id', $p['id'])) ?>
      <div class="shead">
        <label class="check sx-pickbox" title="Select"><input type="checkbox" class="sx-pick" name="ids[]" value="<?= pm_h($p['id']) ?>" form="bulk"></label>
        <span class="pill <?= $spill[$p['status']] ?? '' ?>"><?= pm_h($slabel[$p['status']] ?? ucfirst($p['status'])) ?></span> <span class="pill"><?= pm_h($p['format']) ?></span><?php if (!empty($p['pillar'])): ?> <span class="pill"><?= pm_h($p['pillar']) ?></span><?php endif; ?><?php if (!empty($p['audience'])): ?> <span class="pill"><?= $p['audience'] === 'host' ? 'for hosts' : 'for guests' ?></span><?php endif; ?><?php if (!empty($p['segment']) && !in_array($p['segment'], ['All businesses', 'General', 'Hosts', 'Travellers'], true)): ?> <span class="pill" title="The audience this post speaks to"><?= pm_h((string)$p['segment']) ?></span><?php endif; ?><?php if (!empty($p['offline'])): ?> <span class="pill" title="Made from templates, no AI">template</span><?php endif; ?> <span class="sx-chip" title="Ref code: people who WhatsApp or click from this post carry it">ref <?= pm_h(pm_post_ref($p)) ?></span>
        <input type="datetime-local" name="when" value="<?= pm_h(str_replace(' ', 'T', $p['when'])) ?>" aria-label="When"></div>
      <?php if ($p['status'] === 'failed'): ?><p class="hint warnt">Not posted: <?= pm_h($p['error']) ?></p><?php endif; ?>
      <?php if ($p['status'] === 'needs_edit'): ?>
        <?php if (!empty($p['lint'])): ?><p class="hint warnt">Fix before it can be approved:</p><div class="sx-chips"><?php foreach ($p['lint'] as $lm): ?><span class="sx-chip bad"><?= pm_h($lm) ?></span> <?php endforeach; ?></div>
        <?php elseif (!empty($p['error'])): ?><p class="hint warnt"><?= pm_h($p['error']) ?></p><?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($p['review_note'])): ?><p class="hint warnt"><b>Changes requested:</b> <?= pm_h($p['review_note']) ?></p><?php endif; ?>
      <?php if (!empty($p['warn'])): ?><ul class="sx-warn"><?php foreach ($p['warn'] as $wm): ?><li><?= pm_h($wm) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($p['status'] === 'needs_video'): ?><p class="hint warnt">A reel needs its video. Record it from the script, then attach it below. It will not be posted without it.</p><?php endif; ?>
      <?php if ($p['status'] === 'needs_asset'): ?><p class="hint warnt">This post needs a real photo (and permission to use it). Add one in Content > Photo library, or upload your own below.</p><?php endif; ?>
      <?php if ($p['status'] === 'needs_check'): ?><p class="hint warnt"><?= pm_h((string)($p['error'] ?: 'Posting was interrupted.')) ?> Check the Page, then press Mark posted (paste the post link if you can) or Retry.</p>
        <input type="url" name="post_url" placeholder="Link to the post on Facebook (optional)"><?php endif; ?>
      <?php if ($p['status'] === 'approved' && !empty($p['retry_at'])): ?><p class="hint warnt">Facebook is busy. Retry <?= pm_h(substr($p['retry_at'], 11)) ?> (try <?= (int)$p['tries'] ?> of 4): <?= pm_h($p['error']) ?></p><?php endif; ?>
      <?php if (!empty($p['note'])): ?><p class="hint"><?= pm_h($p['note']) ?></p><?php endif; ?>
      <?php if ($slides || $p['format'] === 'carousel'): ?><div class="sx-slides" title="Cover and slides"><?php for ($si = 0; $si <= count($slides); $si++): ?><img loading="lazy" src="?simg=<?= pm_h($p['id']) ?>&size=4x5&slide=<?= $si ?>" alt="Slide <?= $si + 1 ?>"><?php endfor; ?></div><?php endif; ?>
      <?php if (in_array($p['format'], ['image', 'carousel', 'story'], true)): ?><div class="two"><input type="text" name="headline" value="<?= pm_h($p['headline']) ?>" placeholder="Picture headline"><input type="text" name="sub" value="<?= pm_h($p['sub']) ?>" placeholder="Picture subline"></div><?php endif; ?>
      <textarea name="caption" rows="4" aria-label="Caption"><?= pm_h($p['caption']) ?></textarea>
      <input type="text" name="hashtags" value="<?= pm_h(implode(' ', (array)$p['hashtags'])) ?>" placeholder="#Malawi">
      <?php if ($p['format'] === 'reel'): ?><label>Video script</label><textarea name="script" rows="4"><?= pm_h($p['script']) ?></textarea><?php endif; ?>
      <details class="sx-more"><summary>More: picture description, first comment, your own picture or video</summary>
        <label>Picture description for screen readers (alt text)</label><input type="text" name="alt" value="<?= pm_h((string)($p['alt'] ?? '')) ?>" maxlength="400">
        <label>First comment (posted right after the post)</label><textarea name="first_comment" rows="2"><?= pm_h((string)($p['first_comment'] ?? '')) ?></textarea>
        <label>Use your own picture or video (optional, up to <?= $maxMb ?> MB on this server)</label><input type="file" name="media" accept="image/png,image/jpeg,video/mp4,video/quicktime" data-max-mb="<?= $maxMb ?>">
      </details>
      <?php if ($logTail): ?><ul class="sx-log"><?php foreach ($logTail as $lg): ?><li><span><?= pm_h(substr((string)$lg['at'], 5)) ?></span> <?= pm_h((string)$lg['by']) ?>: <?= pm_h(str_replace('_', ' ', (string)$lg['act'])) ?><?= !empty($lg['note']) ? ' · ' . pm_h((string)$lg['note']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
      <div class="btns">
        <button class="btn small">Save</button>
        <?php if ($p['status'] === 'needs_check'): ?><button class="btn small primary" name="do" value="mark_posted">Mark posted</button><button class="btn small" name="do" value="retry" data-confirm="Only retry if the post is NOT on the Page. Retry now?">Retry</button>
        <?php elseif ($p['status'] === 'publishing'): ?><span class="hint">Posting now...</span>
        <?php elseif ($p['status'] === 'approved'): ?><button class="btn small" name="do" value="unapprove">Hold back</button>
        <?php elseif ($canApprove && $p['status'] !== 'needs_asset'): ?><button class="btn small primary" name="do" value="approve"<?= $p['status'] === 'needs_video' ? ' disabled title="Attach the video first"' : '' ?>>Approve</button>
        <?php elseif (!$canApprove && in_array($p['status'], ['draft', 'needs_edit'], true)): ?><button class="btn small primary" name="do" value="request_approval">Request approval</button><?php endif; ?>
        <?php if ($canApprove && in_array($p['status'], ['review', 'approved', 'draft'], true)): ?><button class="btn small" name="do" value="request_changes" data-ask="review_note" title="Send it back with a note">Request changes</button><?php endif; ?>
        <?php if ($canApprove && in_array($p['status'], ['draft', 'approved', 'failed'], true)): ?><button class="btn small" name="do" value="publish" data-confirm="Publish this post on the Facebook Page now?">Publish now</button><?php endif; ?>
        <?php if (in_array($p['format'], ['image', 'carousel', 'story'], true) && $unsent): ?><button class="btn small" name="do" value="redraw">Redraw picture</button><?php endif; ?>
        <?php if ($panel === ''): ?>
          <button type="button" class="btn small" data-copy="<?= pm_h(pm_social_caption($p)) ?>" title="For Instagram, LinkedIn, TikTok, X, Google or WhatsApp Status">Copy caption</button>
          <?php if ($img !== '' && !$isVideo($img)): ?><a class="btn small" href="?simg=<?= pm_h($p['id']) ?>&dl=1">Download picture</a><?php endif; ?>
          <?php if ($slides): ?><a class="btn small" href="?sslides=<?= pm_h($p['id']) ?>">Download slides (zip)</a><?php endif; ?>
        <?php endif; ?>
        <?php if ($canApprove): ?><button class="btn small danger" name="do" value="delete" data-confirm="Delete this post?">Delete</button><?php endif; ?>
      </div>
      <input type="hidden" name="review_note" value="">
    </form>
    <?= $panel ?>
    <?php if ($p['status'] === 'needs_video'): ?>
    <form method="post" enctype="multipart/form-data" class="btns"><?= $sp('attach_video', $hid('id', $p['id'])) ?><input type="file" name="video" accept="video/mp4,video/quicktime" aria-label="Video file" data-max-mb="<?= $maxMb ?>"><button class="btn small primary">Attach video</button></form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; endforeach; ?>

<?php if ($queue): ?>
<div class="sx-bulk" role="toolbar" aria-label="Selected posts">
  <span class="sx-bulkn" data-pick-count=".sx-pick">0 selected</span>
  <?php if ($canApprove): ?><button class="btn small primary" form="bulk" name="do" value="approve_selected">Approve selected</button><?php endif; ?>
  <button class="btn small" form="bulk" name="do" value="hold">Hold</button>
  <?php if ($canApprove): ?><button class="btn small danger" form="bulk" name="do" value="delete" data-confirm="Delete the selected posts?">Delete</button><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($expired): ?>
<details class="card"><summary><b><?= count($expired) ?> expired post(s)</b> <span class="muted">· the date they were for has passed, so they will not post</span></summary>
  <?php foreach ($expired as $p): ?>
  <form method="post" class="cmt"><?= $sp('delete', $hid('id', $p['id']) . $hid('ids[]', $p['id'])) ?>
    <p><b><?= pm_h(mb_substr($p['headline'] ?: $p['caption'], 0, 70)) ?></b> <span class="muted">· was for <?= pm_h($p['expires'] ?? substr($p['when'], 0, 10)) ?></span></p>
    <?php if ($canApprove): ?><div class="btns"><button class="btn small danger" data-confirm="Delete this expired post?">Delete</button></div><?php endif; ?>
  </form>
  <?php endforeach; ?>
</details>
<?php endif; ?>

<?php if ($igBad): ?>
<div class="card"><h2>Instagram needs attention</h2>
  <?php foreach ($igBad as $p): $igs = (string)$p['ig']; ?>
  <form method="post" class="cmt"><?= $sp('retry_ig', $hid('id', $p['id'])) ?>
    <p><b>Instagram: <?= str_starts_with($igs, 'pending') ? 'still processing' : (str_starts_with($igs, 'check') ? 'check Instagram' : 'failed') ?></b> <span class="muted">· on Facebook <?= pm_h($p['published'] ?? '') ?> · <?= pm_h(mb_substr($p['caption'], 0, 70)) ?>…</span><br><span class="hint"><?= pm_h($igs) ?></span></p>
    <div class="btns">
      <?php if (str_starts_with($igs, 'check')): ?><button class="btn small primary" name="do" value="ig_done">It is on Instagram</button><button class="btn small" data-confirm="Only retry if it is NOT on Instagram. Retry now?">Retry Instagram</button>
      <?php elseif (!str_starts_with($igs, 'pending')): ?><button class="btn small primary">Retry Instagram</button><?php endif; ?>
    </div>
  </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($recent['open_comments']): ?>
<div class="card"><h2>Comments to answer</h2>
  <?php foreach (array_slice($recent['open_comments'], 0, 12) as $cm): ?>
  <form method="post" class="cmt"><?= $sp('reply', $hid('cid', $cm['id']) . $hid('post', $cm['post']) . $hid('from', $cm['from']) . $hid('message', $cm['message'])) ?>
    <p><b><?= pm_h($cm['from']) ?></b> <span class="muted">on "<?= pm_h(mb_substr($cm['post'], 0, 60)) ?>…"</span><br><?= pm_h($cm['message']) ?></p>
    <textarea name="reply" rows="2" placeholder="Your reply"><?= pm_h($_SESSION['sreply'][$cm['id']] ?? '') ?></textarea>
    <div class="btns"><button class="btn small" name="do" value="draft_reply">Draft with AI</button><button class="btn small primary" name="do" value="reply" data-confirm="Post this reply on Facebook?">Post reply</button></div>
  </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($recent['posts']): ?>
<div class="card"><h2>On the Page now</h2>
  <?php foreach ($recent['posts'] as $rp): ?>
    <p class="rp"><span><?= pm_h(date('j M', strtotime($rp['at']))) ?></span> <?= pm_h(mb_substr($rp['message'] ?: '(picture or video)', 0, 90)) ?> <span class="muted">· <?= $rp['reactions'] ?> reactions · <?= $rp['comments'] ?> comments · <?= $rp['shares'] ?> shares</span><?php if ($rp['url']): ?> <a href="<?= pm_h($rp['url']) ?>" target="_blank" rel="noopener noreferrer">open</a><?php endif; ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($done): ?>
<div class="card"><h2>Published from here</h2>
  <?php foreach (array_slice($done, 0, 10) as $p): ?>
  <div class="sx-done"><p class="rp"><span><?= pm_h($p['published'] ?? '') ?></span> <?= pm_h(mb_substr($p['caption'], 0, 90)) ?> <span class="sx-chip">ref <?= pm_h(pm_post_ref($p)) ?></span><?php if (!empty($p['ig'])): ?> <span class="muted">· Instagram: <?= pm_h(mb_substr($p['ig'], 0, 60)) ?></span><?php endif; ?></p>
    <?= pm_social_panels('plan_done', $vb, ['post' => $p]) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?= pm_social_panels('plan_bottom', $vb) ?>
