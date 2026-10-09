<?php
/** Social > Channels: hand-post tasks, Status pack, Film day, community groups, share kits, profile health and bios, Google pack, catalogue. Nothing here posts by itself. */
pm_brand_set($vb);
$bname = $vb === 'travel' ? ($settings['travel']['company_name'] ?? 'Travel Malawi') : 'ProManaged IT';
$cfg = pm_channels_cfg($vb);
$tasks = pm_manual_tasks($vb, 7);
$fx = fn(string $do, string $extra = '') => pm_ch_form($do) . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . pm_h($n) . '" value="' . pm_h($v) . '">';
$cp = fn(string $text, string $label = 'Copy') => '<button type="button" class="btn small" data-copy="' . pm_h($text) . '">' . pm_h($label) . '</button>';
?>
<h1>Social · <?= pm_h($bname) ?></h1>
<?= pm_social_nav('channels') ?>
<style>
.sxv pre{white-space:pre-wrap;word-break:break-word;margin:8px 0;padding:9px 11px;background:var(--bg,#f6f7f8);border-radius:8px;font:13px/1.45 -apple-system,"Segoe UI",Roboto,Arial,sans-serif}
.sxv .sxh{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.sxv .sxb{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:6px 0}
.sxv .sx-card{padding:12px 14px;margin:8px 0}.sxv .warnt{color:var(--warn,#8a5a00);font-size:12px;margin:2px 0}.sxv form.inl{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}
.sxv .score{font-size:30px;font-weight:700}.sxv .sxcols{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}.sxv img.kitp{max-width:170px;border-radius:8px;display:block;margin:6px 0}
</style>
<div class="sxv">

<div class="card"><b>Instagram</b>
  <p class="hint"><?= pm_h(pm_ig_next_step($vb)) ?></p>
  <p class="hint">Honest limits: the app posts to Facebook, Instagram (when linked and online) and LinkedIn. TikTok, YouTube Shorts, Google, WhatsApp Status and Channels, X and your personal LinkedIn have no safe automatic route for a small business (posting on them needs audited API access or a personal number), so this page prepares the exact text and picture and you press Copy, post, then Mark posted.</p>
</div>

<div class="card" id="tasks">
  <h2>Today's hand-post tasks</h2>
  <?php if (!$tasks): ?><p class="hint">Nothing waiting. Tasks appear when a post is approved or published: one per channel, at that channel's best time.</p><?php endif; ?>
  <?php foreach ($tasks as $t): ?>
  <div class="sx-card">
    <div class="sxh"><b><?= pm_h($t['label']) ?></b>
      <span class="sx-chip <?= $t['overdue'] ? 'bad' : '' ?>"><?= $t['overdue'] ? 'overdue · ' : '' ?><?= pm_h(date('D j M H:i', strtotime($t['when']))) ?></span>
      <span class="muted"><?= pm_h($t['headline']) ?></span> <span class="sx-chip">ref <?= pm_h($t['ref']) ?></span>
      <span class="sx-chip <?= $t['chars'] > $t['limit'] ? 'bad' : 'ok' ?>"><?= (int)$t['chars'] ?>/<?= (int)$t['limit'] ?></span></div>
    <pre><?= pm_h($t['text']) ?></pre>
    <?php foreach ($t['warn'] as $w): ?><div class="warnt"><?= pm_h($w) ?></div><?php endforeach; ?>
    <div class="sxb"><?= $cp($t['text'], 'Copy text') ?>
      <?php foreach ($t['parts'] as $lbl => $txt): ?><?= $cp($txt, 'Copy ' . strtolower($lbl)) ?><?php endforeach; ?>
      <?php if ($t['asset_url'] !== ''): ?><a class="btn small" href="<?= pm_h($t['asset_url']) ?>">Download picture (<?= pm_h($t['size']) ?>)</a><?php endif; ?>
      <?php if ($t['deep_link'] !== ''): ?><a class="btn small" href="<?= pm_h($t['deep_link']) ?>" target="_blank" rel="noopener noreferrer">Open <?= pm_h($t['label']) ?></a><?php endif; ?>
    </div>
    <form method="post" class="inl"><?= $fx('task_done', $hid('id', $t['post_id']) . $hid('ch', $t['channel'])) ?>
      <input type="text" name="url" placeholder="Link to your post (optional)" aria-label="Link to your post" style="min-width:220px">
      <button class="btn small primary">Mark posted</button><button class="btn small" name="how" value="skipped">Skip</button></form>
  </div>
  <?php endforeach; ?>
  <details><summary class="hint" style="cursor:pointer">Channels I post by hand</summary>
    <form method="post"><?= $fx('channels_use') ?>
      <?php foreach (PM_CH_MANUAL as $ch): if ($ch === 'linkedin_personal') { continue; } ?>
      <label class="check"><input type="checkbox" name="use[<?= $ch ?>]" value="1" <?= empty($cfg['ticks']['off_' . $ch]) ? 'checked' : '' ?>> <?= pm_h(PM_CH_LABEL[$ch]) ?></label>
      <?php endforeach; ?>
      <label class="check"><input type="checkbox" name="use[linkedin_personal]" value="1" <?= empty($cfg['ticks']['off_linkedin_personal']) ? 'checked' : '' ?>> LinkedIn from my own profile</label>
      <label class="check"><input type="checkbox" name="founder_voice" value="1" <?= !empty($cfg['founder_voice']) ? 'checked' : '' ?>> Founder voice: add a first-person version for my own LinkedIn profile</label>
      <div class="btns"><button class="btn small">Save</button></div>
      <p class="hint">Switch off the channels you do not use and their tasks disappear. Stories and Status do not use up the feed's one-post-a-day slot.</p>
    </form></details>
</div>

<?php $pack = pm_status_pack($vb, preg_replace('/[^a-f0-9]/', '', (string)($_GET['pack'] ?? ''))); ?>
<div class="card" id="statuspack">
  <h2>WhatsApp Status pack</h2>
  <p class="hint">Status is seen by people who already have your number, and it is free. Post the next three planned posts as Status (picture + text), and one simple idea each day.</p>
  <div class="sxcols">
  <?php foreach ($pack['posts'] as $s): ?>
    <div class="sx-card"><b><?= pm_h($s['headline'] ?: 'Post') ?></b> <span class="sx-chip"><?= pm_h(date('D j M', strtotime($s['when']))) ?></span> <span class="sx-chip <?= $s['chars'] > 700 ? 'bad' : 'ok' ?>"><?= (int)$s['chars'] ?>/700</span>
      <pre><?= pm_h($s['text']) ?></pre>
      <div class="sxb"><?= $cp($s['text'], 'Copy text') ?><?php if ($s['card'] !== ''): ?><a class="btn small" href="<?= pm_h($s['card']) ?>">Download story card</a><?php endif; ?></div></div>
  <?php endforeach; ?>
  <?php if (!$pack['posts']): ?><p class="hint">No upcoming posts to turn into Status yet.</p><?php endif; ?>
  </div>
  <h3>Idea for each day</h3>
  <table class="sx-table"><?php foreach ($pack['ideas'] as $i): ?>
    <tr><td style="white-space:nowrap"><?= pm_h(date('D j M', strtotime($i['date']))) ?></td><td><b><?= pm_h($i['label']) ?></b><br><span style="white-space:pre-wrap"><?= pm_h($i['text']) ?></span>
      <?php if ($i['note'] !== ''): ?><br><span class="hint"><?= pm_h($i['note']) ?></span><?php endif; ?></td>
      <td style="white-space:nowrap"><?php if ($i['text'] !== ''): ?><?= $cp($i['text']) ?><?php endif; ?>
        <form method="post" class="inl"><?= $fx('status_done', $hid('date', $i['date']) . ($i['done'] ? $hid('how', 'undo') : '')) ?><button class="btn small <?= $i['done'] ? '' : 'primary' ?>"><?= $i['done'] ? 'Done · undo' : 'Mark posted' ?></button></form></td></tr>
  <?php endforeach; ?></table>
</div>

<?php $reels = pm_film_day($vb); ?>
<div class="card" id="film">
  <h2>Film day</h2>
  <?php if (!$reels): ?><p class="hint">No reels are waiting for a video.</p>
  <?php else: $secs = 0; $nshots = 0; foreach ($reels as $r) { $secs += (int)($r['video']['total_secs'] ?? 0); $nshots += count((array)($r['video']['shots'] ?? [])); } ?>
  <p class="hint"><?= count($reels) ?> reel<?= count($reels) > 1 ? 's' : '' ?> to film, <?= $nshots ?> shots, about <?= $secs ?> seconds of video. Film them in one go: same place, same light, one take per shot. TikTok and YouTube cannot be posted to from here without audited API access, so use Copy and the Studio buttons.</p>
  <?php foreach ($reels as $k => $r): $v = (array)($r['video'] ?? []); $late = pm_reel_late($r); ?>
  <div class="sx-card">
    <div class="sxh"><b><?= pm_h($r['headline'] ?: 'Reel') ?></b> <span class="sx-chip <?= $late ? 'bad' : '' ?>">slot <?= pm_h(date('D j M H:i', strtotime($r['when']))) ?></span> <span class="sx-chip"><?= pm_h($r['status']) ?></span></div>
    <?= pm_film_card_html($r, $k < 3) ?>
    <?php $srt = pm_srt_from_video($v); ?>
    <div class="sxb">
      <?php if ($srt !== ''): ?><a class="btn small" download="reel-<?= pm_h($r['id']) ?>.srt" href="data:text/plain;charset=utf-8,<?= rawurlencode($srt) ?>">Download captions (.srt)</a><?php endif; ?>
      <?= $cp(pm_variant_caption($r, 'tiktok'), 'Copy TikTok caption') ?>
      <?= $cp(pm_var_build($r, 'youtube_short')['text'] . "\n\n" . (pm_var_build($r, 'youtube_short')['parts']['Description'] ?? ''), 'Copy Shorts title and description') ?>
      <a class="btn small" href="https://www.tiktok.com/upload" target="_blank" rel="noopener noreferrer">Open TikTok upload</a>
      <a class="btn small" href="https://studio.youtube.com/" target="_blank" rel="noopener noreferrer">Open YouTube Studio</a>
    </div>
    <?php $nshot = count((array)($v['shots'] ?? [])); if ($nshot > 0 && $k < 2 && function_exists('pm_card_special')): ?>
    <details><summary class="hint" style="cursor:pointer">Shot cards (one picture per shot)</summary>
      <?php for ($i = 0; $i < min($nshot, 8); $i++): $u = pm_film_shot_card($r, $i); if ($u !== ''): ?><img class="kitp" src="<?= $u ?>" alt="Shot <?= $i + 1 ?>"><?php endif; endfor; ?></details>
    <?php endif; ?>
    <?php if ($late): ?>
    <p class="warnt">Less than 48 hours to the slot and no video yet. Choose a plan B:</p>
    <div class="sxb"><form method="post" class="inl" onsubmit="return confirm('Turn this reel into a slideshow post? It goes back to draft for approval.')"><?= $fx('reel_to_carousel', $hid('id', $r['id'])) ?><button class="btn small primary">Turn into slideshow post</button></form>
      <a class="btn small" href="?tab=social&view=channels&pack=<?= pm_h($r['id']) ?>#statuspack">Make a Status pack</a></div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php $gt = pm_group_tasks($vb); $groups = (array)$cfg['groups']; ?>
<div class="card" id="groups">
  <h2>Community groups</h2>
  <p class="hint">Groups you already belong to. Nothing is posted or scraped automatically. Once a week, post the week's tip as a tip, not a link-drop, and reply to 3 threads. One value post per group every 3 days at most. Read each group's rules first.</p>
  <?php foreach ($gt as $t): $g = $t['group']; ?>
  <div class="sx-card"><div class="sxh"><b><?= pm_h($g['name']) ?></b> <span class="sx-chip"><?= pm_h($g['platform']) ?></span> <span class="sx-chip"><?= $g['role'] === 'person' ? 'as me' : 'as the Page' ?></span>
      <?php if ($g['url'] !== ''): ?><a href="<?= pm_h($g['url']) ?>" target="_blank" rel="noopener noreferrer">open</a><?php endif; ?></div>
    <p class="hint"><?= pm_h($t['note']) ?><?= $g['rule_note'] !== '' ? ' Group rule: ' . pm_h($g['rule_note']) : '' ?></p>
    <?php if ($t['text'] !== ''): ?><pre><?= pm_h($t['text']) ?></pre><?php else: ?><p class="hint">No Tip post this week yet: plan one first.</p><?php endif; ?>
    <div class="sxb"><?php if ($t['text'] !== ''): ?><?= $cp($t['text']) ?><?php endif; ?>
      <form method="post" class="inl"><?= $fx('group_done', $hid('gid', $g['id'])) ?><button class="btn small primary">Mark posted</button></form></div></div>
  <?php endforeach; ?>
  <?php if ($groups && !$gt): ?><p class="hint">All groups are up to date for this week.</p><?php endif; ?>
  <details><summary class="hint" style="cursor:pointer">Edit my groups</summary>
    <form method="post"><?= $fx('group_save') ?>
      <?php foreach (array_merge($groups, [[], []]) as $i => $g): ?>
      <div class="sx-card"><?= $hid("group[$i][id]", (string)($g['id'] ?? '')) ?>
        <input type="text" name="group[<?= $i ?>][name]" value="<?= pm_h((string)($g['name'] ?? '')) ?>" placeholder="Group name" aria-label="Group name">
        <input type="text" name="group[<?= $i ?>][url]" value="<?= pm_h((string)($g['url'] ?? '')) ?>" placeholder="https://..." aria-label="Group link">
        <select name="group[<?= $i ?>][platform]" aria-label="Platform"><?php foreach (['facebook', 'whatsapp', 'linkedin', 'other'] as $pl): ?><option <?= ($g['platform'] ?? 'facebook') === $pl ? 'selected' : '' ?>><?= $pl ?></option><?php endforeach; ?></select>
        <select name="group[<?= $i ?>][role]" aria-label="Post as"><option value="page">as the Page</option><option value="person" <?= ($g['role'] ?? '') === 'person' ? 'selected' : '' ?>>as me</option></select>
        <input type="text" name="group[<?= $i ?>][rule_note]" value="<?= pm_h((string)($g['rule_note'] ?? '')) ?>" placeholder="Group rule to remember (e.g. no links)" aria-label="Rule">
        <?php if (!empty($g['id'])): ?><label class="check"><input type="checkbox" name="group[<?= $i ?>][delete]" value="1"> Remove</label><?php endif; ?></div>
      <?php endforeach; ?>
      <div class="btns"><button class="btn small primary">Save groups</button></div>
    </form></details>
</div>

<?php $kits = pm_host_kits($vb); $travel = $vb === 'travel'; ?>
<div class="card" id="kits">
  <h2><?= $travel ? 'Host share kits' : 'Client share kits' ?></h2>
  <p class="hint">When a published post features a <?= $travel ? 'host' : 'client' ?> who gave consent, send them the post with a picture and a badge. Sharing is their choice, and tagging is a manual step: the message never promises one.</p>
  <?php if (!$kits): ?><p class="hint">No kits waiting.</p><?php endif; ?>
  <?php foreach ($kits as $kit): $cards = pm_host_kit_cards($kit); ?>
  <div class="sx-card"><div class="sxh"><b><?= pm_h((string)($kit['row']['client_name'] ?: 'Featured')) ?></b> <span class="muted"><?= pm_h((string)$kit['post']['headline']) ?></span></div>
    <pre><?= pm_h($kit['msg']) ?></pre>
    <div class="sxb"><?= $cp($kit['msg'], 'Copy message') ?><?php if ($kit['wa'] !== ''): ?><a class="btn small" href="<?= pm_h($kit['wa']) ?>" target="_blank" rel="noopener noreferrer">Send on WhatsApp</a><?php else: ?><span class="hint">No WhatsApp number on file: copy the message.</span><?php endif; ?></div>
    <div class="sxcols"><?php if ($cards['featured'] !== ''): ?><div><img class="kitp" src="<?= $cards['featured'] ?>" alt="Featured card"><a class="btn small" download="featured.png" href="<?= $cards['featured'] ?>">Download "Featured on" card</a></div><?php endif; ?>
      <?php if ($cards['badge'] !== ''): ?><div><img class="kitp" src="<?= $cards['badge'] ?>" alt="Badge"><a class="btn small" download="badge.png" href="<?= $cards['badge'] ?>">Download badge</a>
        <?php if ($cards['link'] !== ''): ?><p class="hint">Link for the badge: <?= pm_h($cards['link']) ?> <?= $cp($cards['link'], 'Copy link') ?></p><?php endif; ?></div><?php endif; ?></div>
    <form method="post" class="inl"><?= $fx('task_done', $hid('id', (string)$kit['post']['id']) . $hid('ch', 'host_kit')) ?><button class="btn small primary">Mark sent</button><button class="btn small" name="how" value="skipped">Skip</button></form></div>
  <?php endforeach; ?>
</div>

<?php $ps = pm_profile_score($vb); $bios = pm_bio_pack($vb); $pin = pm_pin_pick($vb); ?>
<div class="card" id="profile">
  <h2>Profile health</h2>
  <div class="sxh"><span class="score"><?= (int)$ps['score'] ?>%</span><span class="muted"><?= (int)$ps['pass'] ?> of <?= (int)$ps['total'] ?> checks pass<?= $ps['page_read'] ? '' : ' (the Facebook Page could not be read, so its checks are not counted)' ?></span></div>
  <?php foreach ($ps['fixes'] as $f): ?><p class="hint">Fix: <b><?= pm_h($f['label']) ?></b>. <?= pm_h($f['fix']) ?><?php if ($f['link'] !== ''): ?> <a href="<?= pm_h($f['link']) ?>" <?= str_starts_with($f['link'], 'http') ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>open</a><?php endif; ?></p><?php endforeach; ?>
  <form method="post"><?= $fx('ticks_save', $hid('ticks_form', '1')) ?>
    <p class="hint">Tick what you have done (the app cannot see these):</p>
    <?php foreach (pm_profile_ticks($vb) as $k => [$label, $grp]): ?>
    <label class="check"><input type="checkbox" name="tick[<?= $k ?>]" value="1" <?= !empty($cfg['ticks'][$k]) || ($k === 'ig_linked' && pm_social_cfg($vb)['ig_id'] !== '') ? 'checked' : '' ?>> <?= pm_h($label) ?> <span class="muted">· <?= pm_h($grp) ?></span></label>
    <?php endforeach; ?>
    <div class="btns"><button class="btn small">Save ticks</button></div>
  </form>
  <h3>Bio pack</h3>
  <table class="sx-table"><?php foreach ($bios as $b): ?>
    <tr><td style="white-space:nowrap"><b><?= pm_h($b['label']) ?></b><br><span class="sx-chip <?= $b['chars'] > $b['limit'] ? 'bad' : 'ok' ?>"><?= (int)$b['chars'] ?>/<?= (int)$b['limit'] ?></span></td><td><?= pm_h($b['text']) ?></td><td><?= $cp($b['text']) ?></td></tr>
  <?php endforeach; ?></table>
  <h3>Pin this post</h3>
  <?php if ($pin): ?><div class="sxh"><b><?= pm_h($pin['headline']) ?></b> <span class="muted"><?= pm_h($pin['why']) ?></span> <a href="<?= pm_h($pin['url']) ?>" target="_blank" rel="noopener noreferrer">open on Facebook</a>
    <form method="post" class="inl"><?= $fx('pin_done') ?><button class="btn small">Pinned this month</button></form></div>
    <p class="hint">On Facebook: the post's "..." menu > Pin post. Re-pin the current best post once a month.</p>
  <?php else: ?><p class="hint">Nothing to pin yet: needs a published evergreen post with some engagement.</p><?php endif; ?>
</div>

<?php $gp = pm_gbp_pack($vb, 2); $rd = (array)($cfg['review_draft'] ?? []); ?>
<div class="card" id="google">
  <h2>Google Business pack</h2>
  <p class="hint">Post one update a week on your Google profile (1,500 characters, no phone numbers in the text, one picture, one button).</p>
  <?php foreach ($gp as $g): $q = $g['post']; ?>
  <div class="sx-card"><div class="sxh"><span class="sx-chip"><?= pm_h($q['type']) ?></span> <b><?= pm_h($q['title']) ?></b> <span class="sx-chip <?= $q['chars'] > 1500 ? 'bad' : 'ok' ?>"><?= (int)$q['chars'] ?>/1500</span>
      <?php if ($q['end'] !== ''): ?><span class="muted"><?= pm_h($q['start']) ?> to <?= pm_h($q['end']) ?></span><?php endif; ?></div>
    <pre><?= pm_h($q['text']) ?></pre><?php foreach ($q['warn'] as $w): ?><div class="warnt"><?= pm_h($w) ?></div><?php endforeach; ?>
    <div class="sxb"><?= $cp($q['text']) ?><span class="sx-chip">Button: <?= pm_h($q['button']) ?></span><?php if ($q['button_link'] !== ''): ?><?= $cp($q['button_link'], 'Copy button link') ?><?php endif; ?>
      <a class="btn small" href="<?= pm_h($q['image_url']) ?>">Download picture (1200x900)</a><a class="btn small" href="https://business.google.com/" target="_blank" rel="noopener noreferrer">Open Google Business</a></div></div>
  <?php endforeach; ?>
  <?php if (!$gp): ?><p class="hint">No upcoming posts to turn into a Google update.</p><?php endif; ?>
  <form method="post" class="findrow"><?= $fx('ticks_save') ?>
    <input type="text" name="google_review_url" value="<?= pm_h((string)$cfg['google_review_url']) ?>" placeholder="Your Google review link (https://g.page/r/...)" aria-label="Google review link" style="min-width:280px">
    <button class="btn small">Save review link</button></form>
  <p class="hint">Ask every happy client for a review with this link. Never offer anything in return for a review.</p>
  <h3>Reply to a review</h3>
  <form method="post"><?= $fx('review_reply') ?>
    <textarea name="review" rows="3" placeholder="Paste the review text here" aria-label="Review text"></textarea>
    <input type="text" name="first" placeholder="Reviewer's first name (optional)" aria-label="First name" style="max-width:260px">
    <div class="btns"><button class="btn small primary">Draft a reply (one small AI call)</button></div>
  </form>
  <?php if (!empty($rd['reply'])): ?><div class="sx-card"><span class="hint">Latest draft, <?= pm_h($rd['at']) ?><?= $rd['first'] !== '' ? ' for ' . pm_h($rd['first']) : '' ?>:</span><pre><?= pm_h($rd['reply']) ?></pre><div class="sxb"><?= $cp($rd['reply']) ?></div></div><?php endif; ?>
</div>

<?php $cat = (array)$cfg['catalogue']; $items = pm_catalogue_items($vb); ?>
<div class="card" id="catalogue">
  <h2>WhatsApp Business catalogue</h2>
  <form method="post" class="findrow"><?= $fx('catalogue_make') ?>
    <select name="key" aria-label="Product or package"><?php foreach ($items as $k => $it): ?><option value="<?= pm_h($k) ?>"><?= pm_h(mb_substr($it[0], 0, 70)) ?></option><?php endforeach; ?></select>
    <input type="text" name="notes" placeholder="Anything to add? (a price only if you type it)" aria-label="Notes" style="min-width:260px">
    <button class="btn small primary">Write entry (one small AI call)</button></form>
  <?php foreach ($cat as $r): ?>
  <div class="sx-card"><div class="sxh"><b><?= pm_h($r['name']) ?></b> <span class="sx-chip"><?= mb_strlen($r['name']) ?>/60</span> <span class="sx-chip"><?= mb_strlen($r['desc']) ?>/250</span></div><p><?= pm_h($r['desc']) ?></p>
    <div class="sxb"><?= $cp($r['name'], 'Copy name') ?><?= $cp($r['desc'], 'Copy description') ?>
      <form method="post" class="inl"><?= $fx('catalogue_delete', $hid('cid', (string)$r['id'])) ?><button class="btn small danger">Remove</button></form></div></div>
  <?php endforeach; ?>
</div>

<?= pm_social_panels('channels', $vb) ?>
</div>
