<?php
/** Social > Page, Inbox and Audit: run the Facebook Page from here. */
pm_brand_set($vb);
$view = (string)($_GET['view'] ?? 'page');
if ($view === 'page') {
    pm_fb_judge_if_stale($vb);
}
$bname = pm_brand_title($settings, $vb);
$sc = pm_social_cfg($vb);
$fp = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="fbm"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="view" value="' . pm_h($view) . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$ti = $sc['ready'] ? pm_fb_token_info($sc['token']) : ['ok' => false];
$autoDrafts = pm_fb_drafts() + array_filter(array_map(fn($k) => (string)($k['reply'] ?? ''), (array)(pm_load('fb_judged', fn() => [])[$vb] ?? [])));
?>
<h1>Social · <?= pm_h($bname) ?></h1>
<?= pm_social_nav($view) ?>

<?php if (!$sc['ready']): ?>
  <div class="card empty">The <?= pm_h($bname) ?> Facebook Page is not connected yet. Use <a href="?tab=social&view=accounts">Accounts &amp; branding</a> > Facebook > Connect.</div>
<?php elseif (empty($ti['ok']) || $ti['type'] !== 'PAGE' || $ti['missing']): ?>
  <div class="flash err"><b>The Facebook connection is limited.</b>
    <?= empty($ti['ok']) ? pm_h($ti['error'] ?? 'The token is not valid.') : '' ?>
    <?= !empty($ti['ok']) && $ti['type'] !== 'PAGE' ? 'The saved token is a personal (user) token, not a Page token, and expires ' . ($ti['expires'] ? 'on ' . date('j M H:i', $ti['expires']) : 'soon') . '.' : '' ?>
    <?= !empty($ti['missing']) ? 'Missing permissions: ' . pm_h(implode(', ', $ti['missing'])) . '.' : '' ?>
    Fix it in one step: <a href="?tab=social&view=accounts">Accounts &amp; branding</a> > Facebook > Connect.</div>
<?php endif; ?>

<?php if ($sc['ready'] && $view === 'page'):
    $res = pm_fb_posts($vb, 25);
    $open = (string)($_GET['post'] ?? '');
    $judged = (array)(pm_load('fb_judged', fn() => [])[$vb] ?? []);
    $vb2 = ['delete' => 'Delete (scam)', 'hide' => 'Hide', 'reply' => 'Post AI reply', 'like' => 'Like']; ?>
  <div class="withpage"><div class="wpmain">
  <?php foreach (array_filter($judged, fn($k) => $k['kind'] === 'visitor_post' && $k['state'] === '') as $vp): ?>
    <div class="card fbc"><p><b><?= pm_h($vp['from']) ?></b> posted on our Page <span class="pill <?= in_array($vp['verdict'], ['scam', 'abusive', 'off_topic'], true) ? 'warn' : '' ?>">AI: <?= pm_h(str_replace('_', '-', $vp['verdict'])) ?></span></p><p><?= pm_h($vp['text']) ?></p>
      <form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbg"><input type="hidden" name="do" value="item"><input type="hidden" name="view" value="growth"><input type="hidden" name="id" value="<?= pm_h($vp['id']) ?>">
        <?php if ($vp['action'] !== 'ignore'): ?><button class="btn small primary" name="how" value="<?= pm_h($vp['action']) ?>">AI suggests: <?= pm_h($vb2[$vp['action']] ?? $vp['action']) ?></button><?php endif; ?>
        <button class="btn small" name="how" value="hide">Hide</button><button class="btn small" name="how" value="delete" data-confirm="Delete this post from the Page?">Delete</button><button class="btn small" name="how" value="skip">Leave it</button></form></div>
  <?php endforeach; ?>
  <?php if ($res['error']): ?><div class="flash err"><?= pm_h($res['error']) ?></div><?php endif; ?>
  <?php if (!$res['posts'] && !$res['error']): ?><div class="card empty">No posts on the Page yet. Plan some under <a href="?tab=social">Plan</a>.</div><?php endif; ?>
  <?php foreach ($res['posts'] as $p): ?>
  <details class="card fbpost" id="p<?= pm_h($p['id']) ?>" <?= $open === $p['id'] ? 'open' : '' ?>>
    <summary>
      <?php if ($p['picture']): ?><img src="<?= pm_h($p['picture']) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
      <span class="txt"><b><?= pm_h(date('D j M, H:i', strtotime($p['at']))) ?></b><?= pm_h(mb_substr($p['message'] ?: '(picture or video)', 0, 140)) ?></span>
      <span class="eng"><?= $p['reactions'] ?> ♥ · <?= $p['comments'] ?> 💬 · <?= $p['shares'] ?> ↗</span>
    </summary>
    <form method="post" class="fbedit"><?= $fp('edit', $hid('id', $p['id'])) ?>
      <textarea name="text" rows="4"><?= pm_h($p['message']) ?></textarea>
      <div class="btns"><button class="btn small">Save the new text</button>
        <a class="btn small" href="<?= pm_h($p['url']) ?>" target="_blank" rel="noopener noreferrer">Open on Facebook</a>
        <button class="btn small danger" name="do" value="delete" onclick="return confirm('Delete this post from the Facebook Page? This cannot be undone.')">Delete post</button></div>
    </form>
    <?php if ($open === $p['id'] || $p['comments'] > 0): $cms = $p['comments'] > 0 ? pm_fb_comments($vb, $p['id']) : ['comments' => [], 'error' => '']; ?>
      <?php if ($cms['error']): ?><p class="hint warnt"><?= pm_h($cms['error']) ?></p><?php endif; ?>
      <?php foreach ($cms['comments'] as $cm): ?>
      <div class="fbc <?= $cm['hidden'] ? 'hiddenc' : '' ?>">
        <?php $jv = $judged[$cm['id']] ?? null; ?>
        <p><b><?= pm_h($cm['from']) ?></b> <span class="muted"><?= pm_h(date('j M H:i', strtotime($cm['at']))) ?><?= $cm['hidden'] ? ' · hidden' : '' ?><?= $cm['answered'] ? ' · answered' : '' ?></span>
          <?php if ($jv): ?><span class="pill <?= in_array($jv['verdict'], ['scam', 'abusive', 'off_topic'], true) ? 'warn' : ($jv['verdict'] === 'buyer' ? 'hot' : '') ?>" title="<?= pm_h($jv['reason']) ?>">AI: <?= pm_h(str_replace('_', '-', $jv['verdict'])) ?></span><?php endif; ?><br><?= pm_h($cm['message']) ?></p>
        <?php if ($jv && $jv['state'] === '' && $jv['action'] !== 'ignore'): ?><form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbg"><input type="hidden" name="do" value="item"><input type="hidden" name="view" value="growth"><input type="hidden" name="id" value="<?= pm_h($cm['id']) ?>">
          <button class="btn small primary" name="how" value="<?= pm_h($jv['action']) ?>" <?= $jv['action'] === 'delete' ? 'data-confirm="Delete this comment?"' : '' ?>>AI suggests: <?= pm_h($vb2[$jv['action']] ?? $jv['action']) ?></button>
          <button class="btn small" name="how" value="skip">Leave it</button></form><?php endif; ?>
        <?php foreach ($cm['replies'] as $r): ?><p class="rep"><b><?= pm_h($r['from']) ?></b> <?= pm_h($r['message']) ?></p><?php endforeach; ?>
        <form method="post"><?= $fp('reply', $hid('id', $cm['id']) . $hid('post', $p['id']) . $hid('post_text', mb_substr($p['message'], 0, 200)) . $hid('from', $cm['from']) . $hid('comment', $cm['message'])) ?>
          <?php if (!$cm['ours']): ?><textarea name="text" rows="2" placeholder="Your reply"><?= pm_h($_SESSION['fbdraft'][$cm['id']] ?? $autoDrafts[$cm['id']] ?? '') ?></textarea><?php endif; ?>
          <div class="btns">
            <?php if (!$cm['ours']): ?><button class="btn small" name="do" value="draft_reply">Draft with AI</button><button class="btn small primary" name="do" value="reply">Reply</button><?php endif; ?>
            <button class="btn small" name="do" value="<?= $cm['liked'] ? 'unlike' : 'like' ?>"><?= $cm['liked'] ? 'Unlike' : 'Like' ?></button>
            <?php if (!$cm['ours']): ?><button class="btn small" name="do" value="<?= $cm['hidden'] ? 'unhide' : 'hide' ?>"><?= $cm['hidden'] ? 'Unhide' : 'Hide' ?></button><?php endif; ?>
            <button class="btn small danger" name="do" value="delete_comment" onclick="return confirm('Delete this comment?')">Delete</button>
          </div>
        </form>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>
  </div><?= pm_fb_page_embed($vb) ?></div>

<?php elseif ($sc['ready'] && $view === 'inbox'):
    $ib = pm_fb_inbox($vb); ?>
  <?php if ($ib['error']): ?><div class="flash err"><?= pm_h($ib['error']) ?><?= str_contains($ib['error'], 'pages_messaging') || str_contains($ib['error'], 'permission') ? ' The token needs pages_messaging.' : '' ?></div><?php endif; ?>
  <?php if (!$ib['threads'] && !$ib['error']): ?><div class="card empty">No Messenger conversations yet.</div><?php endif; ?>
  <?php foreach ($ib['threads'] as $t): ?>
  <div class="card fbthread">
    <h2><?= pm_h($t['who']) ?> <?= $t['waiting'] ? '<span class="pill warn">waiting for us</span>' : '' ?></h2>
    <?php foreach ($t['messages'] as $m): ?><p class="<?= $m['ours'] ? 'us' : 'them' ?>"><?= pm_h($m['text']) ?> <span class="muted"><?= pm_h(date('j M H:i', strtotime($m['at']))) ?></span></p><?php endforeach; ?>
    <form method="post"><?= $fp('message', $hid('psid', $t['psid']) . $hid('tid', $t['id']) . $hid('last', (string)(end($t['messages'])['text'] ?? '')) . $hid('from', $t['who'])) ?>
      <textarea name="text" rows="2" placeholder="Your answer"><?= pm_h($_SESSION['fbdraft'][$t['id']] ?? '') ?></textarea>
      <div class="btns"><button class="btn small" name="do" value="draft_message">Draft with AI</button><button class="btn small primary" <?= $t['window'] ? '' : 'disabled title="Facebook allows replies only within 24 hours of their last message"' ?>>Send</button></div>
      <?php if (!$t['window']): ?><p class="hint">More than 24 hours since their last message: Facebook does not allow a reply from apps now. Answer them from the Facebook app.</p><?php endif; ?>
    </form>
  </div>
  <?php endforeach; ?>

<?php elseif ($sc['ready'] && $view === 'audit'):
    $au = pm_load('page_audit', fn() => [])[$vb] ?? null; ?>
  <div class="card">
    <form method="post" class="findrow"><?= $fp('audit') ?><button class="btn primary"><?= $au ? 'Audit the Page again' : 'Audit the Page' ?></button>
      <span class="hint">Judges the profile, posting rhythm, every recent post, engagement and the tone of our replies. One AI call (about 3,000 tokens). The planner learns from it.</span></form>
  </div>
  <?php if ($au): $sc2 = (array)($au['scores'] ?? []); ?>
  <div class="kpis audit">
    <div><b><?= (int)$au['score'] ?>/100</b><span>overall · <?= pm_h($au['at']) ?></span></div>
    <?php foreach (['profile' => 'Profile', 'consistency' => 'Consistency', 'content' => 'Content', 'engagement' => 'Engagement', 'responsiveness' => 'Replies'] as $k => $l): ?>
      <div><b><?= (int)($sc2[$k] ?? 0) ?></b><span><?= $l ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <p class="lead"><?= pm_h((string)($au['headline'] ?? '')) ?></p>
    <?php if (!empty($au['fixes'])): ?><h3>Fix these first</h3><ol><?php foreach ($au['fixes'] as $f): ?><li><b><?= pm_h(is_array($f) ? ($f['action'] ?? '') : $f) ?></b><?php if (is_array($f) && !empty($f['why'])): ?> <span class="muted">· <?= pm_h($f['why']) ?></span><?php endif; ?></li><?php endforeach; ?></ol><?php endif; ?>
    <?php if (!empty($au['working'])): ?><h3>What is working</h3><ul><?php foreach ($au['working'] as $w): ?><li><?= pm_h($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (!empty($au['timing'])): ?><h3>When and what to post</h3><p><?= pm_h($au['timing']) ?></p><?php endif; ?>
    <?php if (!empty($au['replies']['verdict'])): ?><h3>Our replies</h3><p><?= pm_h($au['replies']['verdict']) ?></p><?php if (!empty($au['replies']['better_example'])): ?><p class="hint">Better: “<?= pm_h($au['replies']['better_example']) ?>”</p><?php endif; ?><?php endif; ?>
    <?php if (!empty($au['posts'])): ?><h3>Post by post</h3><?php foreach ($au['posts'] as $pp): ?><p><b><?= pm_h(mb_substr((string)($pp['post'] ?? ''), 0, 60)) ?>…</b> <?= pm_h((string)($pp['verdict'] ?? '')) ?><?php if (!empty($pp['improve'])): ?> <span class="muted">· Improve: <?= pm_h($pp['improve']) ?></span><?php endif; ?></p><?php endforeach; ?><?php endif; ?>
    <?php if (!empty($au['ideas'])): ?><h3>Ideas for next week</h3><ul><?php foreach ($au['ideas'] as $i): ?><li><?= pm_h(is_array($i) ? implode(' · ', $i) : $i) ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
