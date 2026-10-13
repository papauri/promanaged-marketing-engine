<?php
/**
 * Social > Campaigns: one message to every channel at once (lib/sx_campaign.php). A short form makes the feed post, a three-frame story set, a carousel, the
 * WhatsApp broadcast and a flyer; every item is shown with its picture and words, can be changed, and is approved with one press. Shares index.php's variables.
 */
pm_brand_set($vb);
$sx = fn($do, $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn($n, $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h((string)$v) . '">';
$cid = preg_replace('/[^a-z0-9]/', '', (string)($_GET['c'] ?? ''));
$camp = $cid !== '' ? pm_camp_get($cid) : null;
if ($camp && ($camp['brand'] ?? '') !== $vb) {
    $camp = null;
}
$stateChip = fn(string $s) => '<span class="chip ' . (['published' => 'ok', 'approved' => 'info', 'draft' => '', 'waiting' => 'warn', 'held' => 'warn', 'failed' => 'bad', 'off' => ''][$s] ?? '') . '">'
    . pm_h(['published' => 'published', 'approved' => 'approved', 'draft' => 'draft', 'waiting' => 'hand-post waiting', 'held' => 'needs an edit', 'failed' => 'failed', 'off' => 'not connected'][$s] ?? $s) . '</span>';
echo pm_social_nav('campaign');

if ($camp):
    $items = pm_camp_items($camp);
    $files = pm_camp_flyer_files($camp);
    $drafts = count(array_filter($items, fn($i) => in_array($i['state'], ['draft', 'held'], true)));
    $fl = (array)($camp['copy']['flyer'] ?? []);
    $v = '&amp;v=' . substr(md5((string)@filemtime($files['share'] ?? '')), 0, 6);
    ?>
<div class="card">
  <div class="cluster"><h2 class="m-0"><?= pm_h((string)$camp['name']) ?></h2> <?= $stateChip(match ((string)$camp['status']) { 'approved' => 'approved', 'partly approved' => 'held', default => 'draft' }) ?>
    <span class="muted t-sm">starts <?= pm_h(date('D j M, H:i', strtotime((string)$camp['when']))) ?> · <?= pm_h(PM_CAMP_PICS[$camp['pic'] ?? 'card'] ?? '') ?><?= !empty($camp['ai']) ? ' · words by the AI from your facts' : ' · your own words' ?></span></div>
  <?php if (($camp['note'] ?? '') !== ''): ?><p class="hint"><?= pm_h((string)$camp['note']) ?></p><?php endif; ?>
  <p class="hint">The feed post goes out at the start time, the three story frames right after it (Facebook and Instagram stories, and WhatsApp Status as a hand-post task), the carousel the next day, and the WhatsApp broadcast a few people at a time in working hours. Nothing goes out until it is approved.</p>
  <div class="btns">
    <?php if ($drafts && pm_social_can_approve()): ?><form method="post" class="inl"><?= $sx('camp_approve', $hid('id', $camp['id'])) ?><button class="btn primary">Approve all <?= count(array_filter($items, fn($i) => $i['post'] !== null || $i['role'] === 'wac')) ?> items</button></form>
    <?php elseif ($drafts): ?><span class="hint">An approver has to approve this campaign.</span><?php endif; ?>
    <?php if (class_exists('ZipArchive')): ?><a class="btn" href="?camp=<?= pm_h($camp['id']) ?>&amp;f=pack">Download stories and flyer (zip)</a><?php endif; ?>
    <a class="btn ghost" href="?tab=social&amp;view=campaign">All campaigns</a>
    <form method="post" class="inl"><?= $sx('camp_delete', $hid('id', $camp['id'])) ?><button class="btn danger" data-confirm="Delete this campaign? Items that are not published yet are removed; anything already published stays on the Page.">Delete</button></form>
  </div>
</div>

<div class="card"><h2>Every item</h2>
  <ul class="nextlist">
  <?php foreach ($items as $it): ?>
    <li><span class="grow"><b><?= pm_h($it['label']) ?></b><?= $it['note'] !== '' ? '<br><span class="muted t-sm">' . pm_h($it['note']) . '</span>' : '' ?></span> <?= $stateChip($it['state']) ?>
      <?php if ($it['role'] === 'wac'): ?> <a class="btn small" href="?tab=whatsapp#wacampaigns">Open</a><?php elseif (str_contains($it['role'], ':')): ?> <a class="btn small" href="?tab=social&amp;view=channels">Open</a><?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
</div>

<div class="btgrid">
  <?php foreach ($items as $it): if (!$it['post']) { continue; } $p = $it['post']; $story = ($p['format'] ?? '') === 'story'; $car = ($p['format'] ?? '') === 'carousel';
      $src = '?simg=' . $p['id'] . '&size=' . ($story ? 'story' : ($car ? '4x5' : 'sq')) . ($car ? '&slide=0' : '') . '&v=' . substr(md5((string)($p['headline'] ?? '') . ($p['sub'] ?? '')), 0, 6);
      $locked = !in_array($p['status'] ?? '', ['draft', 'review', 'needs_edit', 'approved'], true); ?>
  <div class="btkit" id="i<?= pm_h($p['id']) ?>">
    <img class="cppv<?= $story ? ' story' : '' ?>" src="<?= pm_h($src) ?>" alt="<?= pm_h(trim(($p['headline'] ?? '') . ' ' . ($p['sub'] ?? ''))) ?>" loading="lazy">
    <div class="cluster"><b><?= pm_h($it['label']) ?></b> <?= $stateChip($it['state']) ?><?= !empty($p['bg_ai']) && ($p['layout'] ?? '') === 'photo' ? ' <span class="chip info">AI picture</span>' : '' ?></div>
    <?php if ($car): ?><p class="hint m-0"><?php foreach (array_keys(array_values((array)($p['slides'] ?? []))) as $si): ?><a href="?simg=<?= pm_h($p['id']) ?>&amp;size=4x5&amp;slide=<?= $si + 1 ?>" target="_blank" rel="noopener">slide <?= $si + 2 ?></a> · <?php endforeach; ?><a href="?sslides=<?= pm_h($p['id']) ?>">all slides (zip)</a></p><?php endif; ?>
    <?php if ($locked): ?><p class="hint m-0"><?= pm_h(mb_substr((string)$p['caption'], 0, 300)) ?></p>
    <?php else: ?>
    <form method="post"><?= $sx('camp_save', $hid('id', $camp['id']) . $hid('post', $p['id'])) ?>
      <label>Words on the picture</label><input type="text" name="headline" maxlength="90" value="<?= pm_h((string)($p['headline'] ?? '')) ?>" aria-label="Words on the picture">
      <label>Second line</label><input type="text" name="sub" maxlength="160" value="<?= pm_h((string)($p['sub'] ?? '')) ?>" aria-label="Second line">
      <?php if (!$story): ?><label>Post text</label><textarea name="caption" rows="4" aria-label="Post text"><?= pm_h((string)($p['caption'] ?? '')) ?></textarea><?php else: ?><label>WhatsApp Status text</label><textarea name="caption" rows="2" aria-label="WhatsApp Status text"><?= pm_h((string)($p['caption'] ?? '')) ?></textarea><?php endif; ?>
      <div class="btns"><button class="btn small">Save and redraw</button><a class="btn small ghost" href="?simg=<?= pm_h($p['id']) ?>&amp;size=<?= $story ? 'story' : 'sq' ?>&amp;dl=1">Download picture</a></div>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="btkit" id="flyer">
    <?php if (isset($files['share'])): ?><img class="cppv" src="?camp=<?= pm_h($camp['id']) ?>&amp;f=share<?= $v ?>" alt="The flyer" loading="lazy"><?php else: ?><p class="hint">The flyer could not be drawn on this server (it needs GD and the fonts).</p><?php endif; ?>
    <div class="cluster"><b>Flyer</b> <span class="chip">print and share</span></div>
    <div class="btns">
      <?php foreach (['a4' => 'A4 PDF', 'a5' => 'A5 PDF', 'print' => 'Print picture', 'share' => 'Share picture'] as $fk => $fn): if (isset($files[$fk])): ?><a class="btn small" href="?camp=<?= pm_h($camp['id']) ?>&amp;f=<?= $fk ?>&amp;dl=1"><?= $fn ?></a><?php endif; endforeach; ?>
    </div>
    <form method="post"><?= $sx('camp_flyer', $hid('id', $camp['id'])) ?>
      <label>Headline</label><input type="text" name="f_headline" maxlength="90" value="<?= pm_h((string)($fl['headline'] ?? '')) ?>" aria-label="Flyer headline">
      <label>Second line</label><input type="text" name="f_sub" maxlength="160" value="<?= pm_h((string)($fl['sub'] ?? '')) ?>" aria-label="Flyer second line">
      <label>Three points (one per line)</label><textarea name="f_points" rows="3" aria-label="Flyer points"><?= pm_h(implode("\n", (array)($fl['points'] ?? []))) ?></textarea>
      <div class="btns"><button class="btn small">Save and redraw the flyer</button></div>
    </form>
    <p class="hint m-0">The QR code opens <?= pm_h(pm_camp_cta($vb, $camp)['link'] !== '' ? (str_contains(pm_camp_cta($vb, $camp)['link'], 'wa.me') ? 'a WhatsApp chat with you' : 'the offer\'s landing page') : 'nothing yet: add a mobile number in Business details') ?>. The share picture also goes with the WhatsApp broadcast.</p>
  </div>
</div>
<?php
    return;
endif;

// ---------- the list and the new-campaign form ----------
$offers = function_exists('pm_lp_for_brand') ? array_reverse(pm_lp_for_brand($vb, true)) : [];
$aiPic = function_exists('pm_aiimg_ready') && pm_aiimg_ready($vb);
$waOn = function_exists('pm_wa_biz_cfg') && pm_wa_biz_cfg($vb)['ready'];
$prof = pm_brand_profile($vb);
?>
<div class="card"><h2>New campaign</h2>
  <form method="post" enctype="multipart/form-data"><?= $sx('camp_create') ?>
    <div class="row">
      <div><label>What does it say? *</label><input type="text" name="headline" maxlength="90" placeholder="e.g. Free website check this month"></div>
      <div><label>Or start from an offer</label><select name="offer"><option value="">No offer</option><?php foreach ($offers as $o): ?><option value="<?= pm_h((string)$o['id']) ?>"><?= pm_h((string)$o['title']) ?></option><?php endforeach; ?></select></div>
    </div>
    <label>One more line (optional)</label><input type="text" name="message" maxlength="240" placeholder="What you do for them, in plain words">
    <div class="row">
      <div><label>WhatsApp keyword</label><input type="text" name="keyword" maxlength="12" value="<?= pm_h((string)$prof['cta_keyword']) ?>" placeholder="QUOTE"></div>
      <div><label>Starts</label><input type="datetime-local" name="when" value="<?= pm_h(date('Y-m-d\TH:00', time() + 3600)) ?>"></div>
      <div><label>Picture</label><select name="pic"><?php foreach (PM_CAMP_PICS as $k => $l): if (($k === 'ai' && !$aiPic) || ($k === 'ailib' && !pm_camp_ai_library($vb))) { continue; } ?><option value="<?= $k ?>"><?= pm_h($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Your photo (if you picked it)</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
    </div>
    <label>WhatsApp broadcast goes to <span class="muted">(only people who agreed to WhatsApp messages<?= $waOn ? '' : '; WhatsApp Business is not connected yet, so it is kept as a draft' ?>)</span></label>
    <div class="checks mt-0"><?php foreach (PM_WAC_AUDIENCE_STATUSES as $k => $l): ?><label class="check"><input type="checkbox" name="statuses[]" value="<?= $k ?>" <?= in_array($k, ['contacted', 'replied', 'proposal', 'won'], true) ? 'checked' : '' ?>> <?= pm_h($l) ?></label><?php endforeach; ?></div>
    <div class="btns"><button class="btn primary">Make the campaign</button></div>
    <p class="hint">Makes a feed post, three story frames, a carousel, a WhatsApp broadcast and a flyer (A4 and A5 PDF, share picture), all saying the same thing. The words use only your facts<?= $aiPic ? '' : '; AI pictures appear here once they are set up' ?>. You check everything and approve it with one press.</p>
  </form>
</div>

<?php $all = pm_camp_for($vb); if ($all): [$rows, $pager] = pm_ui_page($all, 10, 'cp'); ?>
<div class="card"><h2>Campaigns</h2>
  <ul class="nextlist">
  <?php foreach ($rows as $c): $its = pm_camp_items($c); $pub = count(array_filter($its, fn($i) => $i['state'] === 'published')); ?>
    <li><span class="grow"><a href="?tab=social&amp;view=campaign&amp;c=<?= pm_h($c['id']) ?>"><b><?= pm_h((string)$c['name']) ?></b></a><br><span class="muted t-sm"><?= pm_h(date('j M Y, H:i', strtotime((string)$c['when']))) ?> · <?= $pub ?> of <?= count($its) ?> items out</span></span>
      <?= $stateChip(match ((string)$c['status']) { 'approved' => 'approved', 'partly approved' => 'held', default => 'draft' }) ?> <a class="btn small primary" href="?tab=social&amp;view=campaign&amp;c=<?= pm_h($c['id']) ?>">Open</a></li>
  <?php endforeach; ?>
  </ul>
  <?= $pager ?>
</div>
<?php endif; ?>
