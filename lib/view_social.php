<?php
/** Social screen: connection and numbers, AI post planner, approval queue, published posts, comments to answer. */
pm_brand_set($vb);
$sc = pm_social_cfg($vb);
$page = $sc['ready'] ? pm_social_page($vb) : ['ok' => false];
$all = array_filter(pm_social_posts(), fn($p) => $p['brand'] === $vb);
uasort($all, fn($a, $b) => strcmp($a['when'], $b['when']));
$queue = array_filter($all, fn($p) => in_array($p['status'], ['draft', 'approved', 'failed', 'publishing', 'needs_edit', 'needs_video', 'needs_check'], true));
$igBad = array_filter($all, fn($p) => $p['status'] === 'published' && str_starts_with((string)($p['ig'] ?? ''), 'failed'));
$canAll = count(array_filter($queue, fn($p) => $p['status'] === 'draft'));
$spill = ['approved' => 'hot', 'failed' => 'warn', 'needs_edit' => 'warn', 'needs_video' => 'warn', 'needs_check' => 'warn'];
$slabel = ['needs_edit' => 'Needs an edit', 'needs_video' => 'Needs video', 'needs_check' => 'Check the Page', 'publishing' => 'Posting now'];
$done = array_reverse(array_filter($all, fn($p) => $p['status'] === 'published'), true);
$recent = $sc['ready'] ? pm_social_recent($vb) : ['posts' => [], 'open_comments' => [], 'error' => ''];
$auto = !empty(($vb === 'travel' ? $settings['travel'] : $settings)['social_auto']);
$bname = $vb === 'travel' ? $settings['travel']['company_name'] : 'ProManaged IT';
$sp = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="social"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$weekPosts = count(array_filter($done, fn($p) => strtotime($p['published'] ?? '') > time() - 7 * 86400));
?>
<h1>Social · <?= pm_h($bname) ?></h1>
<?= pm_social_nav('') ?>

<?php if (!$sc['ready']): ?>
  <div class="card setup">
    <b>Connect the <?= pm_h($bname) ?> Facebook Page</b>
    <p class="hint">You can already plan and prepare posts below. To publish them, read numbers and answer comments, the app needs a Page access token. Add these lines to <code>.env</code> (Settings shows how to get them):</p>
    <code class="blockcode"><?= $vb === 'travel' ? 'TM_FB_PAGE_ID=...<br>TM_FB_PAGE_TOKEN=...<br>TM_IG_USER_ID=...   (optional, Instagram)' : 'FB_PAGE_ID=...<br>FB_PAGE_TOKEN=...<br>IG_USER_ID=...   (optional, Instagram)' ?></code>
  </div>
<?php elseif (empty($page['ok'])): ?>
  <div class="flash err">Facebook did not accept the connection: <?= pm_h((string)($page['error'] ?? '')) ?></div>
<?php endif; ?>

<div class="kpis">
  <div><b><?= !empty($page['ok']) ? number_format((int)$page['followers']) : '—' ?></b><span><?= !empty($page['ok']) ? 'followers · ' . pm_h($page['name']) : 'Page not connected' ?></span></div>
  <div><b><?= $weekPosts ?></b><span>posts published this week</span></div>
  <div><b><?= count($queue) ?></b><span>planned · <?= count(array_filter($queue, fn($p) => $p['status'] === 'approved')) ?> approved</span></div>
  <div><b><?= count($recent['open_comments']) ?></b><span>comments to answer</span></div>
</div>

<div class="card">
  <h2>Plan posts with AI</h2>
  <form method="post" class="findrow"><?= $sp('splan') ?>
    <select name="n" aria-label="How many"><?php foreach ([3, 5, 7, 10] as $k): ?><option value="<?= $k ?>" <?= $k === 5 ? 'selected' : '' ?>><?= $k ?> posts</option><?php endforeach; ?></select>
    <select name="focus" aria-label="Goal"><?php foreach (PM_SOCIAL_FOCUS as $k => $l): ?><option value="<?= $k ?>"><?= pm_h($l) ?></option><?php endforeach; ?></select>
    <input type="date" name="start" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" aria-label="Start date" class="narrow">
    <button class="btn primary">Plan posts</button>
  </form>
  <p class="hint">One AI call plans a week: branded picture posts, a short status and a phone-video script. <?= $auto ? '<b>Auto-publish is on:</b> planned posts go out at their time without approval.' : 'Each post waits for your approval.' ?> Publishing to Facebook happens on schedule even when nobody has the app open, if the scheduler is set up.</p>
</div>

<?php if ($queue): ?><h3>Planned posts</h3>
<?php if ($canAll): ?><form method="post" class="btns"><?= $sp('approve_all') ?><button class="btn primary">Approve all that pass checks (<?= $canAll ?>)</button> <span class="hint">Posts that break a rule (prices, repeats, no consented proof, missing video) stay held with the reason shown.</span></form><?php endif; ?>
<?php endif; ?>
<?php foreach ($queue as $p): $img = $p['media'] !== '' && is_file($p['media']) ? $p['media'] : (is_file(pm_social_dir() . '/' . $p['id'] . '.png') ? pm_social_dir() . '/' . $p['id'] . '.png' : ''); ?>
<div class="card spost" id="s<?= pm_h($p['id']) ?>">
  <div class="sgrid">
    <div class="sthumb">
      <?php if ($p['format'] === 'reel'): ?><div class="reel">Phone video<br><small>record from the script</small></div>
      <?php elseif ($p['format'] === 'text'): ?><div class="reel">Text status</div>
      <?php elseif ($img !== '' && preg_match('/\.(mp4|mov|m4v)$/i', $img)): ?><div class="reel">Video attached</div>
      <?php elseif ($img !== ''): ?><img src="?simg=<?= pm_h($p['id']) ?>&v=<?= @filemtime($img) ?>" alt="Post picture">
      <?php else: ?><div class="reel">Picture is drawn when you approve</div><?php endif; ?>
    </div>
    <form method="post" enctype="multipart/form-data"><?= $sp('save', $hid('id', $p['id'])) ?>
      <div class="shead"><span class="pill <?= $spill[$p['status']] ?? '' ?>"><?= pm_h($slabel[$p['status']] ?? ucfirst($p['status'])) ?></span> <span class="pill"><?= pm_h($p['format']) ?></span><?php if (!empty($p['pillar'])): ?> <span class="pill"><?= pm_h($p['pillar']) ?></span><?php endif; ?>
        <input type="datetime-local" name="when" value="<?= pm_h(str_replace(' ', 'T', $p['when'])) ?>" aria-label="When"></div>
      <?php if ($p['status'] === 'failed'): ?><p class="hint warnt">Not posted: <?= pm_h($p['error']) ?></p><?php endif; ?>
      <?php if ($p['status'] === 'needs_edit' && !empty($p['lint'])): ?><p class="hint warnt">Fix before it can be approved: <?= pm_h(implode(' ', $p['lint'])) ?></p><?php endif; ?>
      <?php if ($p['status'] === 'needs_video'): ?><p class="hint warnt">A reel needs its video. Record it from the script, then attach it below. It will not be posted without it.</p><?php endif; ?>
      <?php if ($p['status'] === 'needs_check'): ?><p class="hint warnt"><?= pm_h((string)($p['error'] ?: 'Posting was interrupted.')) ?> Check the Page, then press Mark posted or Retry.</p><?php endif; ?>
      <?php if ($p['status'] === 'approved' && !empty($p['retry_at'])): ?><p class="hint warnt">Facebook is busy. Retry <?= pm_h(substr($p['retry_at'], 11)) ?> (try <?= (int)$p['tries'] ?> of 4): <?= pm_h($p['error']) ?></p><?php endif; ?>
      <?php if (!empty($p['note'])): ?><p class="hint"><?= pm_h($p['note']) ?></p><?php endif; ?>
      <?php if ($p['format'] === 'image'): ?><div class="two"><input type="text" name="headline" value="<?= pm_h($p['headline']) ?>" placeholder="Picture headline"><input type="text" name="sub" value="<?= pm_h($p['sub']) ?>" placeholder="Picture subline"></div><?php endif; ?>
      <textarea name="caption" rows="4" aria-label="Caption"><?= pm_h($p['caption']) ?></textarea>
      <input type="text" name="hashtags" value="<?= pm_h(implode(' ', $p['hashtags'])) ?>" placeholder="#Malawi">
      <?php if ($p['format'] === 'reel'): ?><label>Video script</label><textarea name="script" rows="4"><?= pm_h($p['script']) ?></textarea><?php endif; ?>
      <label>Use your own picture or video (optional)</label><input type="file" name="media" accept="image/png,image/jpeg,video/mp4,video/quicktime">
      <div class="btns">
        <button class="btn small">Save</button>
        <?php if ($p['status'] === 'needs_check'): ?><button class="btn small primary" name="do" value="mark_posted">Mark posted</button><button class="btn small" name="do" value="retry" onclick="return confirm('Only retry if the post is NOT on the Page. Retry now?')">Retry</button>
        <?php elseif ($p['status'] === 'publishing'): ?><span class="hint">Posting now...</span>
        <?php elseif ($p['status'] !== 'approved'): ?><button class="btn small primary" name="do" value="approve"<?= $p['status'] === 'needs_video' ? ' disabled title="Attach the video first"' : '' ?>>Approve</button><?php else: ?><button class="btn small" name="do" value="unapprove">Hold back</button><?php endif; ?>
        <?php if (in_array($p['status'], ['draft', 'approved', 'failed'], true)): ?><button class="btn small" name="do" value="publish" onclick="return confirm('Publish this post on the Facebook Page now?')">Publish now</button><?php endif; ?>
        <?php if ($p['format'] === 'image'): ?><button class="btn small" name="do" value="redraw">Redraw picture</button><?php endif; ?>
        <button type="button" class="btn small" data-copy="<?= pm_h(pm_social_caption($p)) ?>" title="For Instagram, LinkedIn, TikTok, X, Google or WhatsApp Status">Copy caption</button>
        <?php if ($img !== '' && !preg_match('/\.(mp4|mov|m4v)$/i', $img)): ?><a class="btn small" href="?simg=<?= pm_h($p['id']) ?>" download="post-<?= pm_h($p['id']) ?>.png">Download picture</a><?php endif; ?>
        <button class="btn small danger" name="do" value="delete" onclick="return confirm('Delete this post?')">Delete</button>
      </div>
    </form>
    <?php if ($p['status'] === 'needs_video'): ?>
    <form method="post" enctype="multipart/form-data" class="btns"><?= $sp('attach_video', $hid('id', $p['id'])) ?><input type="file" name="video" accept="video/mp4,video/quicktime" aria-label="Video file"><button class="btn small primary">Attach video</button></form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($igBad): ?>
<div class="card"><h2>Instagram needs attention</h2>
  <?php foreach ($igBad as $p): ?>
  <form method="post" class="cmt"><?= $sp('retry_ig', $hid('id', $p['id'])) ?>
    <p><b>Instagram: failed</b> <span class="muted">· on Facebook <?= pm_h($p['published'] ?? '') ?> · <?= pm_h(mb_substr($p['caption'], 0, 70)) ?>…</span><br><span class="hint"><?= pm_h($p['ig']) ?></span></p>
    <div class="btns"><button class="btn small primary">Retry Instagram</button></div>
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
    <div class="btns"><button class="btn small" name="do" value="draft_reply">Draft with AI</button><button class="btn small primary" name="do" value="reply" onclick="return confirm('Post this reply on Facebook?')">Post reply</button></div>
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
<?php elseif ($done): ?>
<div class="card"><h2>Published from here</h2>
  <?php foreach (array_slice($done, 0, 10) as $p): ?><p class="rp"><span><?= pm_h($p['published'] ?? '') ?></span> <?= pm_h(mb_substr($p['caption'], 0, 90)) ?><?php if (!empty($p['ig'])): ?> <span class="muted">· Instagram: <?= pm_h(mb_substr($p['ig'], 0, 60)) ?></span><?php endif; ?></p><?php endforeach; ?>
</div>
<?php endif; ?>
