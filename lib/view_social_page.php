<?php
/** Social > Inbox (everyone waiting for an answer, and what to hide or delete) and Page posts (the Page's own posts with their comments), and Insights > Audit (the AI health check). */
pm_brand_set($vb);
$view = (string)($_GET['view'] ?? 'page');
if (in_array($view, ['page', 'inbox'], true)) {
    pm_fb_judge_if_stale($vb);
}
$bname = pm_brand_title($settings, $vb);
$sc = pm_social_cfg($vb);
$fp = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="fbm"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="view" value="' . pm_h($view) . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$ti = $sc['ready'] ? pm_fb_token_info($sc['token']) : ['ok' => false];
$autoDrafts = pm_fb_drafts() + array_filter(array_map(fn($k) => (string)($k['reply'] ?? ''), (array)(pm_load('fb_judged', fn() => [])[$vb] ?? [])));
?>
<?= pm_social_nav($view) ?>

<?php if (!$sc['ready']): ?>
  <div class="card empty">The <?= pm_h($bname) ?> Facebook Page is not connected yet. Use <a href="?tab=settings&section=connections">Operations &gt; Connections</a> > Facebook > Connect.</div>
<?php elseif (empty($ti['ok']) || $ti['type'] !== 'PAGE' || $ti['missing']): ?>
  <div class="flash err"><b>The Facebook connection is limited.</b>
    <?= empty($ti['ok']) ? pm_h($ti['error'] ?? 'The token is not valid.') : '' ?>
    <?= !empty($ti['ok']) && $ti['type'] !== 'PAGE' ? 'The saved token is a personal (user) token, not a Page token, and expires ' . ($ti['expires'] ? 'on ' . date('j M H:i', $ti['expires']) : 'soon') . '.' : '' ?>
    <?= !empty($ti['missing']) ? 'Missing permissions: ' . pm_h(implode(', ', $ti['missing'])) . '.' : '' ?>
    Fix it in one step: <a href="?tab=settings&section=connections">Operations &gt; Connections</a> > Facebook > Connect.</div>
<?php endif; ?>

<?php if ($sc['ready'] && $view === 'page'):
    $res = pm_fb_posts($vb, 25);
    $open = (string)($_GET['post'] ?? '');
    $judged = (array)(pm_load('fb_judged', fn() => [])[$vb] ?? []);
    $vb2 = ['delete' => 'Delete (scam)', 'hide' => 'Hide', 'reply' => 'Post AI reply', 'like' => 'Like']; ?>
  <div class="withpage"><div class="wpmain">
  <?php foreach (array_filter($judged, fn($k) => $k['kind'] === 'visitor_post' && $k['state'] === '') as $vp): ?>
    <div class="card fbc"><p><b><?= pm_h($vp['from']) ?></b> posted on our Page <span class="pill <?= in_array($vp['verdict'], ['scam', 'abusive', 'off_topic'], true) ? 'warn' : '' ?>">AI: <?= pm_h(str_replace('_', '-', $vp['verdict'])) ?></span></p><p><?= pm_h($vp['text']) ?></p>
      <form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbg"><input type="hidden" name="do" value="item"><input type="hidden" name="view" value="page"><input type="hidden" name="id" value="<?= pm_h($vp['id']) ?>">
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
        <?php if ($jv && $jv['state'] === '' && $jv['action'] !== 'ignore'): ?><form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbg"><input type="hidden" name="do" value="item"><input type="hidden" name="view" value="page"><input type="hidden" name="id" value="<?= pm_h($cm['id']) ?>">
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
    $ib = pm_fb_inbox($vb);
    $recent = pm_social_recent($vb);
    $today = pm_social_today($vb);
    $lvl = pm_autopilot_level($vb);
    $ap = pm_load('autopilot', fn() => [])[$vb]['at'] ?? 0;
    $vlabel = ['buyer' => 'buyer', 'question' => 'question', 'complaint' => 'complaint', 'fan' => 'fan', 'off_topic' => 'off-topic', 'abusive' => 'abusive', 'scam' => 'scam'];
    $aBtn = ['delete' => 'Delete', 'hide' => 'Hide', 'reply' => 'Post reply', 'like' => 'Like'];
    $fg = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="fbg"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="view" value="inbox">' . $extra;
    $styleItems = pm_aa_items_for($vb, ['reply_style']);
    $nThreads = count(array_filter($ib['threads'], fn($t) => $t['waiting']));
    $nComments = count($recent['open_comments']);
    $nMod = array_sum(array_map(fn($g) => count($g['items']), $today['suggestions'])); ?>
  <?= pm_social_panels('inbox_top', $vb) ?>
  <div class="card inboxhead">
    <div class="findrow" style="justify-content:space-between">
      <span><b><?= $nThreads + $nComments + $nMod ?></b> waiting: <?= $nThreads ?> message<?= $nThreads === 1 ? '' : 's' ?>, <?= $nComments ?> comment<?= $nComments === 1 ? '' : 's' ?> to answer, <?= $nMod ?> to tidy.
        <span class="muted">Autopilot is <b><?= pm_h($lvl) ?></b> (<a href="#rules">change</a>).</span></span>
      <form method="post"><?= $fg('judge') ?><button class="btn small" title="Reads the newest comments and posts on the Page and sorts them">Check the Page now</button></form>
    </div>
  </div>

  <?php if ($today['suggestions']): ?>
  <div class="card today" id="tidy">
    <h2>To tidy on the Page</h2>
    <p class="hint" style="margin-top:0">Comments and posts the AI sorted: scams to delete, junk to hide, fans to like, buyers and questions to answer. Nothing happens until you press a button (or Autopilot does the safe ones).</p>
    <?php foreach ($today['suggestions'] as $act => $g): ?>
    <details class="sugg <?= $act ?>">
      <summary><b><?= pm_h($g['label']) ?></b> <span class="pill <?= $act === 'delete' ? 'warn' : ($act === 'reply' ? 'hot' : '') ?>"><?= count($g['items']) ?></span> <span class="muted"><?= pm_h($g['why']) ?></span>
        <form method="post" class="inl"><?= $fg('group', '<input type="hidden" name="act" value="' . $act . '">') ?><?php $ask = $act === 'delete' ? 'Delete all ' . count($g['items']) . ' from the Page? Deleted comments cannot be brought back.' : ($act === 'reply' ? 'Post all ' . count($g['items']) . ' replies as they are? Open the list to read or change them first.' : ''); ?><button class="btn small primary" <?= $ask !== '' ? 'onclick="return confirm(' . pm_h(json_encode($ask)) . ')"' : '' ?>><?= $aBtn[$act] ?> all</button></form></summary>
      <?php foreach ($g['items'] as $k): ?>
      <form method="post" class="sitem"><?= $fg('item', '<input type="hidden" name="id" value="' . pm_h($k['id']) . '">') ?>
        <p><b><?= pm_h($k['from']) ?></b> <span class="pill"><?= pm_h($vlabel[$k['verdict']] ?? $k['verdict']) ?></span> <span class="muted"><?= pm_h(date('j M H:i', strtotime($k['at']))) ?> · <?= $k['kind'] === 'visitor_post' ? 'post on our Page' : 'comment' ?> · <?= pm_h($k['reason']) ?></span>
          <?php if ($k['link']): ?><a class="muted" href="<?= pm_h($k['link']) ?>" target="_blank" rel="noopener noreferrer">view</a><?php endif; ?></p>
        <p class="said"><?= pm_h(mb_substr($k['text'], 0, 300)) ?></p>
        <?php if ($act === 'reply'): ?><textarea name="text" rows="2"><?= pm_h($k['reply']) ?></textarea><?php endif; ?>
        <div class="btns"><button class="btn small primary" name="how" value="<?= $act ?>"><?= $aBtn[$act] ?></button>
          <?php if ($act !== 'hide' && $act !== 'delete' && $k['verdict'] !== 'fan'): ?><button class="btn small" name="how" value="hide">Hide</button><?php endif; ?>
          <?php if ($act === 'hide'): ?><button class="btn small" name="how" value="delete" onclick="return confirm('Delete this from the Page?')">Delete instead</button><?php endif; ?>
          <button class="btn small" name="how" value="skip">Leave it</button></div>
      </form>
      <?php endforeach; ?>
    </details>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($recent['open_comments']): ?>
  <div class="card" id="comments"><h2>Comments to answer</h2>
    <?php foreach (array_slice($recent['open_comments'], 0, 12) as $cm): ?>
    <form method="post" class="cmt"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social"><input type="hidden" name="back" value="social&amp;view=inbox">
      <?= $hid('cid', $cm['id']) . $hid('post', $cm['post']) . $hid('from', $cm['from']) . $hid('message', $cm['message']) ?>
      <p><b><?= pm_h($cm['from']) ?></b> <span class="muted">on "<?= pm_h(mb_substr($cm['post'], 0, 60)) ?>…"</span><br><?= pm_h($cm['message']) ?></p>
      <textarea name="reply" rows="2" placeholder="Your reply"><?= pm_h($_SESSION['sreply'][$cm['id']] ?? '') ?></textarea>
      <div class="btns"><button class="btn small" name="do" value="draft_reply">Draft with AI</button><button class="btn small primary" name="do" value="reply" data-confirm="Post this reply on Facebook?">Post reply</button></div>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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

  <details class="card" id="rules">
    <summary><b>Community rules</b> <span class="muted">· autopilot and how replies sound</span></summary>
    <form method="post" class="findrow"><?= $fg('autopilot') ?>
      <select name="level" aria-label="Autopilot">
        <option value="off" <?= $lvl === 'off' ? 'selected' : '' ?>>Off: I do everything myself</option>
        <option value="safe" <?= $lvl === 'safe' ? 'selected' : '' ?>>Safe: delete clear scams, hide junk, like fans, catch buyers, daily playbook; replies wait for one click</option>
        <option value="bold" <?= $lvl === 'bold' ? 'selected' : '' ?>>Bold: Safe + posts replies to questions and buyers by itself (complaints stay yours)</option>
      </select>
      <button class="btn small">Save</button>
      <button class="btn small" name="do" value="autopilot_now">Run it now</button>
    </form>
    <p class="hint">Runs every hour with the scheduled task<?= $ap ? ', last at ' . date('j M H:i', (int)$ap) : '' ?>. The AI only looks at comments and posts it has not seen before, so a quiet hour costs nothing. It never deletes our own posts, never sends private messages and never spends money.</p>
    <?php foreach ($styleItems as $si): ?><?= pm_opt_item_html($si, $vb, $csrf, 'social&view=inbox') ?><?php endforeach; ?>
    <?php if (!$styleItems): ?><p class="hint">The audit suggests a reply style once it has judged how our replies sound. Run it under <a href="?tab=social&amp;view=audit">Insights &gt; Audit</a>.</p><?php endif; ?>
  </details>
<?php elseif ($sc['ready'] && $view === 'audit'):
    $au = pm_load('page_audit', fn() => [])[$vb] ?? null;
    $au = is_array($au) ? $au : null;
    $data = pm_aa_data($vb, $au);
    $setItems = pm_aa_settings_items($vb, $au, $data);
    $st = pm_opt_store($vb);
    $item = fn(array $i): string => pm_opt_item_html($i, $vb, $csrf, 'audit');
    $aa = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">' . $extra;
    $bulkN = count(array_filter($setItems, fn($i) => $i['state'] !== 'ok' && $i['bulk']));
    $kindLabel = ['about' => 'Facebook intro', 'description' => 'Page description', 'hours' => 'Opening hours', 'phone' => 'Phone', 'website' => 'Website', 'email' => 'Email', 'cover' => 'Cover', 'picture' => 'Profile picture',
        'posting' => 'Posting rhythm', 'replies' => 'Replies', 'messages' => 'Messenger', 'cta' => 'Enquiries', 'formats' => 'Pictures and video', 'pin' => 'Pinned post', 'reviews' => 'Reviews', 'timing' => 'Posting times', 'other' => 'Other'];
    $ideaTexts = array_map(fn($x) => trim((string)($x['text'] ?? '')), (array)pm_bank_get($vb)['ideas']); ?>
  <style>
  .opt-it{margin:8px 0}.opt-it .sxh{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.opt-it input[type=text],.opt-it textarea,.opt-it select{width:100%;margin-top:6px}
  .opt-pre{white-space:pre-wrap;word-break:break-word;margin:8px 0;padding:9px 11px;background:var(--bg,#f6f7f8);border-radius:8px;font:13px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  .opt-hours>div{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:4px 0}.opt-hours .check{min-width:130px}.opt-hours input[type=time]{width:auto}
  .opt-chans{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 0}.opt-chans a{text-decoration:none}
  .aa-fix{margin:10px 0}.aa-fix>.sxh{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.aa-fix .opt-it{margin:8px 0 0;box-shadow:none}.aa-post textarea{width:100%}
  </style>
  <div class="card">
    <form method="post" class="findrow"><?= $fp('audit') ?><button class="btn primary"><?= $au ? 'Audit the Page again' : 'Audit the Page' ?></button>
      <span class="hint">Judges the profile, posting rhythm, every recent post, engagement and the tone of our replies. One AI call (about 3,500 tokens). Every finding below can be acted on from this page, and the planner learns from it.</span></form>
    <?php if (!$au): ?><p class="hint" style="margin:8px 0 0">The settings suggestions just below come from the Page's own numbers and need no AI, so they are here already. The AI audit adds a prioritised fix list, a verdict on each post and ideas.</p><?php endif; ?>
  </div>
  <?php $lastOpt = (array)($st['last'] ?? []); if (!empty($lastOpt['channels'])): ?>
  <div class="card"><b>Every channel</b> <span class="muted">· from Optimise, <?= pm_h(date('j M H:i', strtotime((string)$lastOpt['at']))) ?> · page health <?= (int)$lastOpt['score'] ?>%</span>
    <div class="opt-chans"><?php foreach ((array)$lastOpt['channels'] as $chk => $chs): ?><a href="?tab=social&amp;view=optimise#c-<?= pm_h($chk) ?>" class="sx-chip <?= $chs === null ? '' : ($chs >= 85 ? 'ok' : ($chs >= 55 ? 'warn' : 'bad')) ?>"><?= pm_h(PM_OPT_CHANNELS[$chk] ?? $chk) ?><?= $chs === null ? ' · not connected' : ' ' . (int)$chs . '%' ?></a><?php endforeach; ?></div>
    <p class="hint" style="margin:8px 0 0">Open a channel to fix what is missing on it: profile details, pictures, and the texts to copy for the platforms that do not let apps edit.</p></div>
  <?php endif; ?>
  <?php if ($au): $sc2 = (array)($au['scores'] ?? []); ?>
  <div class="kpis audit">
    <div><b><?= (int)$au['score'] ?>/100</b><span>overall · <?= pm_h($au['at']) ?></span></div>
    <?php foreach (['profile' => 'Profile', 'consistency' => 'Consistency', 'content' => 'Content', 'engagement' => 'Engagement', 'responsiveness' => 'Replies'] as $k => $l): ?>
      <div><b><?= (int)($sc2[$k] ?? 0) ?></b><span><?= $l ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="card"><p class="lead" style="margin:0"><?= pm_h((string)($au['headline'] ?? '')) ?></p>
    <?php if (!empty($au['working'])): ?><h3>What is working</h3><ul><?php foreach ($au['working'] as $w): ?><li><?= pm_h($w) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <?php endif; ?>

  <div class="card" id="settings">
    <h2>Where each setting lives</h2>
    <p class="hint" style="margin-top:0">The audit judges; the department that owns a setting changes it, so nothing is edited in two places. A finding below that can be fixed on the spot has its own button.</p>
    <ul class="whereto">
      <li><b>Profile details, pictures and the profile words:</b> <a href="?tab=social&amp;view=optimise">Brand &gt; Profiles</a> and <a href="?tab=social&amp;view=studio">Brand &gt; Studio</a></li>
      <li><b>Auto-publish, where posts go, and when:</b> <a href="?tab=social#rules">Social &gt; Plan &gt; Publishing rules</a></li>
      <li><b>What we post about and the mix:</b> <a href="?tab=social&amp;view=content">Social &gt; Content</a></li>
      <li><b>How replies sound, autopilot:</b> <a href="?tab=social&amp;view=inbox#rules">Social &gt; Inbox &gt; Community rules</a></li>
      <li><b>Posting goals and limits:</b> <a href="?tab=social&amp;view=results">Insights &gt; Results &gt; Goals and limits</a></li>
    </ul>
  </div>
  <?php if ($au && !empty($au['fixes'])): ?>
  <div class="card" id="fixes">
    <h2>Fix these first</h2>
    <p class="hint" style="margin-top:0">The audit's list, in order. A fix that can be applied has its button right here; the others open the screen that fixes them, or can show the steps.</p>
    <?php foreach ($au['fixes'] as $n => $fx0): $fix = is_array($fx0) ? $fx0 : ['action' => (string)$fx0]; $card = pm_aa_fix_card($vb, $fix, $setItems);
        $howto = (array)(($st['howto'] ?? [])[md5((string)($fix['action'] ?? $n))]['steps'] ?? []); ?>
    <div class="sx-card aa-fix" id="fix<?= (int)$n ?>">
      <div class="sxh"><b><?= (int)$n + 1 ?>. <?= pm_h((string)($fix['action'] ?? '')) ?></b><span class="sx-chip"><?= pm_h($kindLabel[$card['kind']] ?? 'Other') ?></span></div>
      <?php if (!empty($fix['why'])): ?><p class="hint" style="margin:4px 0 0"><?= pm_h((string)$fix['why']) ?></p><?php endif; ?>
      <?php if ($card['item'] && $card['item']['state'] === 'ok'): ?><p style="margin:8px 0 0"><span class="sx-chip ok">Already done</span> <span class="muted"><?= pm_h(mb_substr((string)$card['item']['title'] . ($card['item']['current'] !== '' ? ': ' . $card['item']['current'] : ''), 0, 160)) ?></span></p>
      <?php elseif ($card['item']): ?><?= $item($card['item']) ?>
      <?php else: ?>
        <?php if ($howto): ?><ol class="steps" style="margin:8px 0 0"><?php foreach ($howto as $stp): ?><li><?= pm_h($stp) ?></li><?php endforeach; ?></ol>
        <?php else: ?><form method="post" class="inl" style="margin-top:8px"><?= $aa('aa_howto', $hid('n', (string)$n)) ?><button class="btn small" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Show me how (AI)</button></form><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($au && !empty($au['posts'])): ?>
  <div class="card" id="posts">
    <h2>Post by post</h2>
    <p class="hint" style="margin-top:0">"Rewrite with AI" makes one small call: a better version of that post that follows the audit's note, using only what the post and your facts already say. Read it, edit it, and save it to Facebook when you are happy.</p>
    <?php foreach ($au['posts'] as $pp): $post = pm_aa_post_for($vb, (array)$pp); $pid = $post['id'] ?? ''; $rw = $pid !== '' ? (array)(($st['rewrites'] ?? [])[$pid] ?? []) : []; ?>
    <div class="sx-card aa-post" id="p<?= pm_h($pid) ?>">
      <div class="sxh"><b><?= pm_h(mb_substr((string)($pp['post'] ?? ''), 0, 70)) ?>…</b><?php if ($post): ?><span class="muted"><?= (int)$post['reactions'] ?> ♥ · <?= (int)$post['comments'] ?> 💬 · <?= (int)$post['shares'] ?> ↗</span><?php endif; ?></div>
      <p style="margin:6px 0 0"><?= pm_h((string)($pp['verdict'] ?? '')) ?></p>
      <?php if (!empty($pp['improve'])): ?><p class="hint" style="margin:4px 0 0">Improve: <?= pm_h((string)$pp['improve']) ?></p><?php endif; ?>
      <?php if (!$post): ?><p class="hint">This post is no longer in the latest posts on the Page.</p>
      <?php elseif ($rw): ?>
        <form method="post"><?= $aa('aa_post_save', $hid('post', $pid)) ?>
          <label class="hint">New version (written <?= pm_h($rw['at']) ?>; edit it as you like)</label>
          <textarea name="text" rows="6"><?= pm_h($rw['text']) ?></textarea>
          <details class="more"><summary class="hint" style="cursor:pointer">The post as it is now</summary><pre class="opt-pre"><?= pm_h($post['message']) ?></pre></details>
          <div class="btns"><button class="btn small primary" onclick="return confirm('Replace the text of this post on Facebook now?')">Save to Facebook</button><a class="btn small" href="<?= pm_h($post['url']) ?>" target="_blank" rel="noopener noreferrer">Open on Facebook</a></div></form>
      <?php else: ?>
        <form method="post" class="inl" style="margin-top:8px"><?= $aa('aa_rewrite', $hid('post', $pid) . $hid('improve', (string)($pp['improve'] ?? $pp['verdict'] ?? ''))) ?><button class="btn small" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Rewrite with AI</button></form>
        <a class="btn small" style="margin-top:8px" href="?tab=social&amp;view=page&amp;post=<?= pm_h($pid) ?>#p<?= pm_h($pid) ?>">Edit by hand</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($au && (!empty($au['replies']['verdict']) || !empty($au['timing']))): ?>
  <div class="card">
    <?php if (!empty($au['replies']['verdict'])): ?><h3 style="margin-top:0">Our replies</h3><p><?= pm_h($au['replies']['verdict']) ?></p><?php if (!empty($au['replies']['better_example'])): ?><p class="hint">Better: “<?= pm_h($au['replies']['better_example']) ?>” <button type="button" class="btn small" data-copy="<?= pm_h($au['replies']['better_example']) ?>">Copy</button> · set the style under <a href="?tab=social&amp;view=inbox#rules">Social &gt; Inbox &gt; Community rules</a>.</p><?php endif; ?><?php endif; ?>
    <?php if (!empty($au['timing'])): ?><h3>When and what to post</h3><p><?= pm_h($au['timing']) ?></p><p class="hint">The times can be changed under <a href="?tab=social#rules">Social &gt; Plan &gt; Publishing rules</a>.</p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($au && !empty($au['ideas'])): ?>
  <div class="card" id="ideas">
    <h2>Ideas for next week</h2>
    <p class="hint" style="margin-top:0">"Add to content ideas" puts an idea in your idea bank (Content): the planner writes a post from it in the next plan.</p>
    <?php foreach ($au['ideas'] as $n => $idea):
        $txt = trim(is_array($idea) ? implode(' - ', array_filter(array_map('strval', array_values($idea)))) : (string)$idea);
        if ($txt === '') { continue; }
        $in = in_array(mb_substr($txt, 0, 240), $ideaTexts, true);
        echo $item(pm_opt_it('audit', 'idea_' . (int)$n, 'Idea ' . ((int)$n + 1), $in ? 'ok' : 'weak', 1, '', ['do' => 'aa_apply', 'how' => 'app', 'input' => 'textarea', 'proposal' => mb_substr($txt, 0, 240), 'limit' => 240, 'rows' => 2, 'button' => 'Add to content ideas', 'current' => $in ? 'Already in your content ideas' : '']));
    endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
