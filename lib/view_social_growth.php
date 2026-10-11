<?php
/** Social > Playbook (who to engage today, with the words; the people on the Page), Brand > Page clean-up (AI editor for the Page's old posts) and Social > Ads (targeted campaigns, created paused). Moderation and autopilot are in the Inbox. */
pm_brand_set($vb);
$view = (string)($_GET['view'] ?? 'growth');
$bname = pm_brand_title($settings, $vb);
$sc = pm_social_cfg($vb);
$fg = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="fbg"><input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="view" value="' . pm_h($view) . '">' . $extra;
$chan = ['reply_comment' => 'Reply to their comment', 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'call' => 'Call', 'comment_on_their_page' => 'Comment on their Page', 'email' => 'Email'];
?>
<?= pm_social_nav($view) ?>

<?php if (!$sc['ready']): ?>
  <div class="card empty">Connect the <?= pm_h($bname) ?> Facebook Page first: <a href="?tab=settings&section=connections">Operations &gt; Connections</a>.</div>

<?php elseif ($view === 'growth' || $view === 'cleanup'): ?><div class="withpage"><div class="wpmain">
<?php endif; ?>
<?php if (!$sc['ready']): ?>
<?php elseif ($view === 'growth'):
    try {
        pm_playbook_ensure($vb); // a playbook from a past day is brought up to date now (carried over, or written again if the people changed)
    } catch (Throwable) {
    }
    $pb = pm_load('engage_playbook', fn() => [])[$vb] ?? null;
    $people = isset($_GET['people']) ? pm_fb_people($vb) : null; ?>
  <div class="card">
    <div class="findrow" style="justify-content:space-between">
      <h2 class="m-0" id="playbook">Today's playbook <?= $pb ? '<span class="muted t-sm" style="font-weight:400">· made ' . pm_h($pb['at']) . '</span>' : '' ?></h2>
      <form method="post"><?= $fg('playbook') ?><button class="btn primary small"><?= $pb && $pb['day'] === date('Y-m-d') ? 'Make it again' : 'Make today\'s playbook' ?></button></form>
    </div>
    <?php if (!$pb): ?><p class="hint">Who to engage today, when, where and with which words: built from everyone who reacted, commented or messaged, plus your warm leads. One AI call.</p>
    <?php else: ?>
      <?php if ($pb['day'] !== date('Y-m-d')): ?><p class="hint warnt">This playbook is from <?= pm_h($pb['day']) ?>. Make today's.</p><?php elseif (!empty($pb['carried_from'])): ?><p class="hint">Brought up to date this morning: <?= (int)($pb['carried'] ?? 0) ?> task<?= (int)($pb['carried'] ?? 0) === 1 ? '' : 's' ?> still open from <?= pm_h(date('j M', strtotime((string)$pb['carried_from']))) ?> <?= (int)($pb['carried'] ?? 0) ? '(what was done is gone)' : '' ?>; nobody new has needed a message.</p><?php endif; ?>
      <?php if (!$pb['tasks']): ?><p class="hint">No tasks today.</p><?php endif; ?>
      <?php if ($pb['focus']): ?><p class="lead"><?= pm_h($pb['focus']) ?></p><?php endif; ?>
      <p class="hint">Best times: <?= $pb['times']['hours'] ? pm_h(implode(', ', array_map(fn($h) => sprintf('%02d:00', $h), $pb['times']['hours']))) . ' · ' : '' ?><?= pm_h($pb['times']['note']) ?></p>
      <?php foreach ($pb['tasks'] as $t): ?>
      <div class="ptask <?= $t['done'] ? 'done' : '' ?>">
        <div class="pwhen"><?= pm_h($t['when']) ?></div>
        <div>
          <p><b><?= pm_h($t['who']) ?></b> · <?= pm_h($chan[$t['channel']] ?? $t['channel']) ?> <span class="muted">· <?= pm_h($t['why']) ?></span></p>
          <div class="msgbox"><span class="copytext"><?= pm_h($t['message']) ?></span></div>
          <p class="hint">Goal: <?= pm_h($t['goal']) ?></p>
          <div class="btns">
            <button type="button" class="btn small" data-copy="<?= pm_h($t['message']) ?>">Copy words</button>
            <?php if ($t['link'] !== ''): ?><a class="btn small" href="<?= pm_h($t['link']) ?>" <?= str_starts_with($t['link'], 'http') ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>Open</a><?php endif; ?>
            <?php if ($t['channel'] === 'messenger'): ?><a class="btn small" href="?tab=social&view=inbox">Inbox</a><?php endif; ?>
            <form method="post" style="display:inline"><?= $fg('task_done', '<input type="hidden" name="n" value="' . (int)$t['n'] . '">') ?><button class="btn small <?= $t['done'] ? '' : 'primary' ?>"><?= $t['done'] ? 'Undo' : 'Done' ?></button></form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!empty($pb['moves'])): ?><h3>Page moves today</h3><ul><?php foreach ($pb['moves'] as $m): ?><li><b><?= pm_h((string)($m['when'] ?? '')) ?></b> <?= pm_h((string)($m['what'] ?? (is_string($m) ? $m : ''))) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="findrow" style="justify-content:space-between"><h2 class="m-0">People on the Page</h2>
      <?php if (!$people): ?><a class="btn small" href="?tab=social&view=growth&people=1#people">Show everyone, ranked</a><?php endif; ?>
      <form method="post"><?= $fg('capture') ?><button class="btn small">Add buyers to leads</button></form></div>
    <?php if ($people !== null): ?>
      <table class="grid compact" id="people"><tr><th>Name</th><th>Signals</th><th>Said</th><th></th></tr>
      <?php foreach (array_slice($people, 0, 40) as $p): ?>
        <tr><td><b><?= pm_h($p['name']) ?></b><?= $p['buyer'] ? ' <span class="pill hot">buyer</span>' : '' ?><?= $p['spam'] ? ' <span class="pill">spam</span>' : '' ?><?= $p['unanswered'] ? ' <span class="pill warn">waiting</span>' : '' ?></td>
          <td class="muted"><?= $p['reacted'] ? $p['reacted'] . ' reaction(s) ' : '' ?><?= $p['commented'] ? $p['commented'] . ' comment(s) ' : '' ?><?= $p['messaged'] ? $p['messaged'] . ' message(s)' : '' ?></td>
          <td><?= pm_h(implode(' · ', $p['said'])) ?></td>
          <td><?php if ($p['links']): ?><a href="<?= pm_h($p['links'][0]) ?>" target="_blank" rel="noopener noreferrer">Post</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </table>
      <?php if (!$people): ?><p class="hint">Nobody has reacted, commented or messaged yet. Post and boost first.</p><?php endif; ?>
    <?php else: ?><p class="hint">Ranked by buying signals: buying words, unanswered messages, comments, reactions, how recent. Buyers become leads (status "Replied") so the sales team follows them up.</p><?php endif; ?>
  </div>

  <?= pm_social_panels('growth_bottom', $vb) ?>

<?php elseif ($view === 'cleanup'):
    $cl = pm_load('page_cleanup', fn() => [])[$vb] ?? null; ?>
  <div class="card">
    <form method="post" class="findrow"><?= $fg('cleanup') ?><button class="btn primary"><?= $cl ? 'Review the Page again' : 'Review the Page' ?></button>
      <span class="hint">An AI editor reads every post and the profile and proposes keep, rewrite or delete. Nothing changes until you tick and apply.</span></form>
  </div>
  <?php if ($cl): $todo = array_filter($cl['items'], fn($i) => $i['action'] !== 'keep' && $i['state'] === ''); ?>
  <div class="card">
    <p class="lead"><?= pm_h($cl['summary']) ?></p>
    <p class="hint"><?= count(array_filter($cl['items'], fn($i) => $i['action'] === 'keep')) ?> to keep · <?= count(array_filter($cl['items'], fn($i) => $i['action'] === 'rewrite')) ?> to rewrite · <?= count(array_filter($cl['items'], fn($i) => $i['action'] === 'delete')) ?> to delete · reviewed <?= pm_h($cl['at']) ?></p>
  </div>
  <form method="post"><?= $fg('cleanup_apply') ?>
    <?php foreach ($cl['items'] as $it): if ($it['action'] === 'keep') { continue; } ?>
    <div class="card cl <?= $it['action'] ?> <?= $it['state'] ? 'applied' : '' ?>">
      <div class="clhead">
        <?php if ($it['state'] === ''): ?><label class="check"><input type="checkbox" name="ids[]" value="<?= pm_h($it['id']) ?>"> <b><?= $it['action'] === 'delete' ? 'Delete' : 'Rewrite' ?></b></label><?php else: ?><span class="pill hot"><?= pm_h($it['state']) ?></span><?php endif; ?>
        <span class="muted"><?= pm_h(date('j M Y', strtotime($it['at']))) ?> · <?= pm_h($it['reason']) ?></span>
        <a href="<?= pm_h($it['url']) ?>" target="_blank" rel="noopener noreferrer" class="muted">view</a>
      </div>
      <div class="split">
        <div><?php if ($it['picture']): ?><img src="<?= pm_h($it['picture']) ?>" alt="" referrerpolicy="no-referrer" class="clpic"><?php endif; ?><p class="old"><?= nl2br(pm_h(mb_substr($it['old_text'], 0, 500))) ?></p></div>
        <div><?php if ($it['action'] === 'rewrite'): ?><textarea name="text[<?= pm_h($it['id']) ?>]" rows="6"><?= pm_h($it['new_text']) ?></textarea><?php endif; ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if ($todo): ?><div class="btns stick"><button type="button" class="btn small" onclick="document.querySelectorAll('.cl input[type=checkbox]').forEach(c=>c.checked=true)">Tick all</button>
      <button class="btn primary" onclick="return confirm('Apply the ticked changes to the Facebook Page? Deleted posts cannot be brought back.')">Apply the ticked changes</button></div><?php endif; ?>
  </form>
  <div class="card"><p class="hint m-0">The Page's about line and description are written and applied under <a href="?tab=social&amp;view=optimise">Brand &gt; Profiles</a>.</p></div>
  <?php endif; ?>

<?php endif; ?>
<?php if ($sc['ready'] && ($view === 'growth' || $view === 'cleanup')): ?></div><?= pm_fb_page_embed($vb) ?></div>
<?php endif; ?>
<?php if (!$sc['ready']): ?>
<?php elseif ($view === 'ads'):
    $ac = pm_ads_cfg($vb);
    $info = $ac['ready'] ? pm_ads_account_info($vb) : [];
    $plan = pm_load('ads_plan', fn() => [])[$vb] ?? null; ?>
  <div class="card">
    <h2>Ad account</h2>
    <?php if ($ac['ready'] && empty($info['error'])): ?>
      <p><span class="pill hot">Connected</span> <?= pm_h((string)($info['name'] ?? '')) ?> · <?= pm_h((string)($info['currency'] ?? '')) ?> · status <?= (int)($info['account_status'] ?? 0) === 1 ? 'active' : 'needs attention in Ads Manager' ?></p>
    <?php else:
        [$okA, $accts] = pm_ads_accounts(); ?>
      <?php if (!empty($info['error'])): ?><p class="hint warnt"><?= pm_h($info['error']) ?></p><?php endif; ?>
      <?php if ($okA && $accts): ?>
        <form method="post"><?= $fg('ads_account') ?><label>Which ad account pays for <?= pm_h($bname) ?>'s ads?</label>
          <select name="acct"><?php foreach ($accts as $a): ?><option value="<?= pm_h($a['account_id']) ?>"><?= pm_h($a['name'] . ' · ' . $a['currency']) ?></option><?php endforeach; ?></select>
          <button class="btn small primary">Use it</button></form>
      <?php else: ?>
        <ol class="steps">
          <li>Open <a href="https://adsmanager.facebook.com" target="_blank" rel="noopener noreferrer">adsmanager.facebook.com</a> and make sure an ad account exists with a payment method (only you add that).</li>
          <li>Reconnect in <a href="?tab=settings&section=connections">Operations &gt; Connections</a> with <code>ads_management</code> and <code>ads_read</code> ticked too. The app keeps that connection for about 60 days, then asks again.</li>
          <li>Come back here and choose the ad account.</li>
        </ol>
        <p class="hint">You can still plan campaigns now and set them up by hand with the plan.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="card">
    <form method="post" class="findrow"><?= $fg('ads_plan') ?><button class="btn primary"><?= $plan ? 'Plan again' : 'Plan targeted ads' ?></button>
      <span class="hint">Small, careful tests aimed at real enquiries. Campaigns are created <b>paused</b>: you check them in Ads Manager and switch them on. Money is only spent when you do.</span></form>
  </div>
  <?php if ($plan): foreach ($plan['campaigns'] as $i => $c): ?>
  <div class="card">
    <h2><?= pm_h((string)$c['name']) ?> <span class="pill"><?= pm_h((string)$c['goal']) ?></span> <?= ($c['state'] ?? '') === 'created' ? '<span class="pill hot">in Ads Manager, paused</span>' : '' ?></h2>
    <div class="split">
      <div>
        <p><b>Audience:</b> <?= pm_h(implode(', ', (array)($c['cities'] ?? []))) ?: 'Malawi' ?> · age <?= (int)($c['age_min'] ?? 18) ?>–<?= (int)($c['age_max'] ?? 65) ?><?= !empty($c['interests']) ? ' · interests: ' . pm_h(implode(', ', (array)$c['interests'])) : '' ?></p>
        <p><b>Budget:</b> <?= pm_h($plan['currency']) ?> <?= pm_h((string)($c['daily_budget'] ?? '')) ?> a day for <?= (int)($c['days'] ?? 7) ?> days (total <?= pm_h($plan['currency']) ?> <?= round((float)($c['daily_budget'] ?? 0) * (int)($c['days'] ?? 7), 2) ?>)</p>
        <p><b>Why:</b> <?= pm_h((string)($c['why'] ?? '')) ?></p>
        <p class="hint"><b>Expect:</b> <?= pm_h((string)($c['expect'] ?? '')) ?></p>
      </div>
      <div>
        <?php if (!empty($c['boost_post_id'])): ?><p><b>Boost this post:</b> <a href="https://www.facebook.com/<?= pm_h((string)$c['boost_post_id']) ?>" target="_blank" rel="noopener noreferrer">open</a></p><?php endif; ?>
        <?php if (!empty($c['primary_text'])): ?><div class="msgbox"><b><?= pm_h((string)($c['headline'] ?? '')) ?></b><br><?= pm_h((string)$c['primary_text']) ?></div><?php endif; ?>
        <?php if (!empty($c['error'])): ?><p class="hint warnt"><?= pm_h($c['error']) ?></p><?php endif; ?>
        <div class="btns">
          <?php if (($c['state'] ?? '') !== 'created'): ?><form method="post" style="display:inline"><?= $fg('ads_create', '<input type="hidden" name="i" value="' . $i . '">') ?><button class="btn small primary" <?= $ac['ready'] ? '' : 'disabled title="Connect an ad account first"' ?>>Create in Ads Manager (paused)</button></form><?php endif; ?>
          <a class="btn small" href="https://adsmanager.facebook.com/adsmanager/manage/campaigns<?= $ac['account'] ? '?act=' . pm_h($ac['account']) : '' ?>" target="_blank" rel="noopener noreferrer">Open Ads Manager</a>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!empty($plan['rules'])): ?><div class="card"><h3>Testing rules</h3><ul><?php foreach ($plan['rules'] as $r): ?><li><?= pm_h(is_string($r) ? $r : implode(' · ', (array)$r)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
