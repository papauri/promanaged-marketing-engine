<?php
/** Brand > Studio: design the profile picture and cover (and every size every platform wants) from styles, colours and words. Drawn by code; "Surprise me" and "New idea from AI" make new designs. */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$canDraw = pm_studio_can_draw();
$saved = pm_studio_saved($vb);
$d = preg_replace('/[^a-z0-9]/', '', (string)($_GET['d'] ?? ''));
if ($d === '') {
    $d = $saved ? 'saved' : 'new';
}
$spec = pm_studio_get($vb, $d);
$st = pm_studio_store($vb);
$pals = pm_sd_palettes((string)($settings['accent_color'] ?? '#17375E'));
$fb = pm_social_cfg($vb);
$hasHero = pm_hero_path($vb) !== '';
$fx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '"><input type="hidden" name="d" value="' . pm_h($d) . '">' . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . pm_h($n) . '" value="' . pm_h($v) . '">';
$img = fn(string $kind, int $w, int $h = 0, string $extra = '', string $id = '') => '?studio=' . $kind . '&b=' . urlencode($vb) . '&id=' . ($id !== '' ? $id : $d) . '&w=' . $w . ($kind === 'cover' ? '&h=' . $h : '') . $extra . '&v=' . PM_SD_VERSION;
$sameAsSaved = $saved && json_encode(array_diff_key($saved, ['at' => 1, 'by' => 1, '_dropped' => 1])) === json_encode(array_diff_key($spec, ['at' => 1, 'by' => 1, '_dropped' => 1]));
$how = ['ai' => 'AI idea', 'shuffle' => 'Surprise', 'you' => 'Edited', 'start' => 'Start'];
?>
<?= pm_social_nav('studio') ?>
<style>
.stu .prevwrap{position:relative;max-width:820px;margin:8px 0 17%}.stu .crop{aspect-ratio:820/312;overflow:hidden;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.18);position:relative;background:#222}
.stu .crop img{position:absolute;left:0;top:50%;transform:translateY(-50%);width:100%;display:block}
.stu .avatar{position:absolute;left:2.6%;bottom:0;transform:translateY(62%);width:20.5%;aspect-ratio:1;border-radius:50%;border:5px solid #fff;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,.22);background:#fff}
.stu .avatar img{width:100%;height:100%;display:block}.stu .top{display:grid;grid-template-columns:minmax(0,820px) 1fr;gap:28px;align-items:start}
@media(max-width:980px){.stu .top{grid-template-columns:1fr}}
.stu .gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(176px,1fr));gap:10px;margin-top:8px}.stu .gal button{all:unset;cursor:pointer;display:block;width:100%;border-radius:10px;overflow:hidden;border:2px solid transparent;position:relative}
.stu .gal button.on{border-color:var(--accent,#1f6feb)}.stu .gal img{display:block;width:100%}.stu .gal span{display:block;padding:4px 6px;font-size:12px;background:var(--bg,#f6f7f8)}
.stu .pgal{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}.stu .pgal button{all:unset;cursor:pointer;text-align:center;font-size:12px;width:104px}.stu .pgal img{width:96px;height:96px;border-radius:50%;display:block;margin:0 auto 4px;border:3px solid transparent}
.stu .pgal button.on img{border-color:var(--accent,#1f6feb)}
.stu .pal{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}.stu .pal button{all:unset;cursor:pointer;text-align:center;font-size:12px;width:92px}
.stu .pal i{display:block;height:44px;border-radius:10px;border:2px solid transparent;position:relative}.stu .pal i b{position:absolute;right:8px;bottom:8px;width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,.7)}
.stu .pal button.on i{border-color:var(--accent,#1f6feb)}
.stu .all{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-top:8px}.stu .all img{width:100%;display:block;border-radius:8px;border:1px solid var(--line,#e3e6ea)}
.stu .sizes{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-top:8px}.stu .sizes figure{margin:0;text-align:center;font-size:12px}.stu .sizes img{display:block;border-radius:50%;margin:0 auto 4px}
.stu .hist{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}.stu .hist a{text-decoration:none;font-size:12px;width:150px}.stu .hist img{width:100%;border-radius:8px;display:block;border:2px solid transparent}.stu .hist a.on img{border-color:var(--accent,#1f6feb)}
.stu .ed input[type=text],.stu .ed textarea,.stu .ed select{width:100%}.stu .ed .cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}.stu .colors{display:flex;gap:14px;flex-wrap:wrap;margin-top:6px}
.stu .colors label{display:flex;align-items:center;gap:6px;font-size:13px}.stu .colors input{width:44px;height:30px;padding:0}
</style>
<div class="stu">
<?php if (!$canDraw): ?>
  <div class="flash err"><b>This server cannot draw pictures.</b> The Studio needs the GD extension with FreeType and the fonts in assets/fonts (see Operations &gt; Connections &gt; Pictures).</div>
<?php endif; ?>

<div class="card">
  <h2>Cover studio for <?= pm_h($bname) ?></h2>
  <p class="hint" style="margin-top:0">Not generated pictures: every cover and profile picture is <b>designed by code</b> from a style, four colours and your own words (a quiet name line, a headline, a supporting line and your contact details), then drawn at the exact size each platform wants, with the writing kept inside the part that is always shown. The look is deliberately <b>human</b>: earthy colours, paper grain, cut shapes and a serif headline, never the glowing purple-and-blue gradients that give generated art away. <b>Surprise me</b> re-rolls the look. <b>New idea from the AI</b> asks the AI to art-direct a design in one small call; code draws it, colours that look generated are softened, and any words that add a claim or a number are not used.</p>
  <p class="hint"><?= $saved ? 'In use everywhere now: <b>' . pm_h(PM_SD_STYLES[$saved['style']] ?? 'custom') . '</b> (' . pm_h($saved['by'] === 'ai' ? 'AI idea' : ($saved['by'] === 'shuffle' ? 'surprise' : 'your design')) . ($saved['at'] !== '' ? ', saved ' . pm_h($saved['at']) : '') . ').' : 'No design saved yet: the plain classic cover is in use. Choose one below and press <b>Use this design</b>.' ?>
    <?= $sameAsSaved ? ' You are looking at the saved design.' : ($saved ? ' You are looking at a new draft: it is not in use until you press Use.' : '') ?></p>
  <div class="btns">
    <form method="post" class="inl"><?= $fx('studio', $hid('op', 'shuffle')) ?><button class="btn primary" <?= $canDraw ? '' : 'disabled' ?>>Surprise me</button></form>
    <form method="post" class="inl"><?= $fx('studio_ai') ?><button class="btn" <?= $canDraw && pm_agents_ready() ? '' : 'disabled' ?> title="<?= pm_agents_ready() ? 'One small AI call chooses the style, colours and words; code draws it.' : 'Add an AI key to .env first.' ?>">New idea from the AI</button></form>
    <form method="post" class="inl"><?= $fx('studio', $hid('op', 'seed')) ?><button class="btn" title="Same style and colours, a different arrangement" <?= $canDraw ? '' : 'disabled' ?>>Reshuffle the shapes</button></form>
    <form method="post" class="inl"><?= $fx('studio_use') ?><button class="btn primary" <?= $canDraw && !$sameAsSaved ? '' : 'disabled' ?>>Use this design everywhere</button></form>
    <form method="post" class="inl" onsubmit="return confirm('Change the Facebook Page profile picture and cover to this design now?')"><?= $fx('studio_fb', $hid('what', 'both')) ?><button class="btn" <?= $canDraw && $fb['ready'] ? '' : 'disabled title="Connect the Facebook Page first (Operations > Connections)"' ?>>Use it and set it on Facebook</button></form>
    <?php if ($saved): ?><form method="post" class="inl" onsubmit="return confirm('Go back to the classic cover everywhere?')"><?= $fx('studio_reset') ?><button class="btn small">Back to the classic cover</button></form><?php endif; ?>
  </div>
</div>

<?php if ($canDraw): ?>
<div class="card">
  <div class="top">
    <div>
      <b>How the Facebook Page will look on a computer</b>
      <div class="prevwrap"><div class="crop"><img src="<?= pm_h($img('cover', 820, 428, '&t=facebook')) ?>" alt="Cover preview"></div><div class="avatar"><img src="<?= pm_h($img('profile', 240)) ?>" alt="Profile picture preview"></div></div>
      <p class="hint" style="margin-top:-8px">Facebook shows the middle of the cover and lays the profile picture over its lower left, so the writing is kept clear of both. The whole picture is below.</p>
      <img src="<?= pm_h($img('cover', 560, 292, '&t=facebook')) ?>" alt="The whole cover" style="width:100%;max-width:560px;border-radius:8px;display:block;margin-bottom:8px">
      <div class="btns"><a class="btn small" href="<?= pm_h($img('cover', 1640, 856, '&t=facebook&dl=1')) ?>">Download the cover (1640×856)</a><a class="btn small" href="<?= pm_h($img('profile', 720, 0, '&dl=1')) ?>">Download the profile picture (720×720)</a></div>
    </div>
    <div>
      <b>Profile picture</b>
      <div class="sizes"><figure><img src="<?= pm_h($img('profile', 220)) ?>" width="160" height="160" style="border-radius:12px" alt=""><span>As drawn</span></figure>
        <figure><img src="<?= pm_h($img('profile', 220)) ?>" width="110" height="110" alt=""><span>Round, as shown</span></figure>
        <figure><img src="<?= pm_h($img('profile', 220)) ?>" width="48" height="48" alt=""><span>In comments</span></figure></div>
      <div class="pgal"><?php foreach (PM_SD_PSTYLES as $k => $l): ?>
        <form method="post"><?= $fx('studio', $hid('op', 'pstyle') . $hid('pstyle', $k)) ?><button class="<?= ($spec['pstyle'] ?? '') === $k ? 'on' : '' ?>"><img src="<?= pm_h($img('profile', 120, 0, '&pstyle=' . $k)) ?>" alt="" loading="lazy"><?= pm_h($l) ?></button></form>
      <?php endforeach; ?></div>
    </div>
  </div>
</div>

<div class="card">
  <h2>Style</h2>
  <p class="hint" style="margin-top:0">Made by hand: paper, cut shapes, flecks and woven bands in earthy colours with a visible grain, the look of things that were printed and cut rather than generated.</p>
  <div class="gal"><?php foreach (PM_SD_STYLES as $k => $l): if (in_array($k, PM_SD_DIGITAL, true) || ($k === 'photo' && !$hasHero)) { continue; } ?>
    <form method="post"><?= $fx('studio', $hid('op', 'style') . $hid('style', $k)) ?><button class="<?= $spec['style'] === $k ? 'on' : '' ?>"><img src="<?= pm_h($img('cover', 340, 178, '&t=facebook&style=' . $k)) ?>" alt="" loading="lazy"><span><?= pm_h($l) ?></span></button></form>
  <?php endforeach; ?></div>
  <?php if (!$hasHero): ?><p class="hint">"Your photo" appears once you add a hero photo under <a href="?tab=social&amp;view=kit">Brand &gt; Brand kit</a>.</p><?php endif; ?>
  <details class="more"><summary class="hint" style="cursor:pointer">Digital looks (glows, circuits, facets): for a technology business, used on purpose</summary>
    <div class="gal"><?php foreach (PM_SD_DIGITAL as $k): $l = PM_SD_STYLES[$k]; ?>
      <form method="post"><?= $fx('studio', $hid('op', 'style') . $hid('style', $k)) ?><button class="<?= $spec['style'] === $k ? 'on' : '' ?>"><img src="<?= pm_h($img('cover', 340, 178, '&t=facebook&style=' . $k)) ?>" alt="" loading="lazy"><span><?= pm_h($l) ?></span></button></form>
    <?php endforeach; ?></div></details>
</div>
<div class="card">
  <h2>Colours</h2>
  <div class="pal"><?php foreach ($pals as $k => [$l, $a, $b, $c, $ink]): ?>
    <form method="post"><?= $fx('studio', $hid('op', 'pal') . $hid('pal', $k)) ?><button class="<?= ($spec['pal'] ?? '') === $k ? 'on' : '' ?>"><i style="background:linear-gradient(135deg,<?= pm_h($a) ?>,<?= pm_h($b) ?>)"><b style="background:<?= pm_h($c) ?>"></b></i><?= pm_h($l) ?></button></form>
  <?php endforeach; ?></div>
</div>

<div class="card ed">
  <h2>Words and details</h2>
  <p class="hint" style="margin-top:0">Quiet writing, kept inside the part of each picture that is always shown. Leave a box empty and that line is left out.</p>
  <form method="post"><?= $fx('studio', $hid('op', 'edit')) ?>
    <div class="cols">
      <div><label>Small line above (the name)</label><input type="text" name="eyebrow" value="<?= pm_h($spec['eyebrow']) ?>" maxlength="40"></div>
      <div><label>Headline</label><input type="text" name="headline" value="<?= pm_h($spec['headline']) ?>" maxlength="70"></div>
      <div><label>Supporting line</label><input type="text" name="subline" value="<?= pm_h($spec['subline']) ?>" maxlength="110"></div>
      <div><label>Contact strip (one item per line, up to three)</label><textarea name="whisper" rows="3"><?= pm_h(implode("\n", (array)$spec['whisper'])) ?></textarea></div>
      <div><label>Monogram (up to 3 letters, for the profile picture)</label><input type="text" name="monogram" value="<?= pm_h($spec['monogram']) ?>" maxlength="3"></div>
      <div><label>Texture</label><select name="texture"><?php foreach (PM_SD_TEXTURES as $k => $l): ?><option value="<?= $k ?>" <?= $spec['texture'] === $k ? 'selected' : '' ?>><?= pm_h($l) ?></option><?php endforeach; ?></select>
        <label>Writing sits</label><select name="align"><?php foreach (['left' => 'On the left', 'center' => 'In the middle', 'right' => 'On the right'] as $k => $l): ?><option value="<?= $k ?>" <?= $spec['align'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <details class="more"><summary class="hint" style="cursor:pointer">Exact colours</summary>
      <div class="colors"><?php foreach (['bg1' => 'Background', 'bg2' => 'Second tone', 'accent' => 'Highlight', 'ink' => 'Writing'] as $k => $l): ?><label><input type="color" name="c_<?= $k ?>" value="<?= pm_h($spec[$k]) ?>"> <?= $l ?></label><?php endforeach; ?></div>
      <p class="hint">If the writing colour cannot be read on the background, it is changed for you.</p></details>
    <div class="btns"><button class="btn primary">Update the pictures</button></div>
  </form>
</div>

<?php $insKw = rawurlencode(trim(preg_replace('/\s+/', ' ', mb_substr((string)($settings['company_name'] ?? ''), 0, 30)))); ?>
<div class="card">
  <h2>Look for inspiration</h2>
  <p class="hint" style="margin-top:0">Great covers rarely look new: they look <i>made</i>. These searches open Pinterest and Behance in a new tab with the looks this Studio is built on. Pick a few you like, then match the style and colours here.</p>
  <div class="btns" style="margin-top:0">
    <?php foreach (['Neo-naturalism brand covers' => 'neo naturalism brand identity earthy palette', 'Earthy colour palettes' => 'earthy brand color palette terracotta sage ochre', 'Editorial social banners' => 'editorial social media banner design paper texture', 'Cut-paper graphics' => 'cut paper collage graphic design organic shapes', 'Terrazzo and grain' => 'terrazzo pattern brand design grain texture', 'Travel poster landscapes' => 'flat landscape poster design sun hills lake'] as $l => $q): ?>
      <a class="btn small" href="https://www.pinterest.com/search/pins/?q=<?= rawurlencode($q) ?>" target="_blank" rel="noopener noreferrer"><?= pm_h($l) ?></a>
    <?php endforeach; ?>
    <a class="btn small" href="https://www.behance.net/search/projects?search=<?= rawurlencode('brand identity social media') ?>" target="_blank" rel="noopener noreferrer">Behance: brand identity</a>
  </div>
  <details class="more"><summary class="hint" style="cursor:pointer">Why these looks read as human</summary>
    <ul class="hint"><li>A calm earthy base with <b>one</b> stronger accent, not a rainbow gradient.</li><li>Visible <b>grain and paper fibre</b>: real print is never perfectly clean.</li><li>Shapes that look <b>cut by hand</b>, with slightly uneven edges and a soft shadow.</li><li>A serif headline, small spaced-out capitals for the details, and room to breathe.</li><li>No glow, no glass, no purple-to-blue: those are what make generated work look generic. <a href="https://www.925studios.co/blog/ai-slop-design-tells" target="_blank" rel="noopener noreferrer">The tells</a> · <a href="https://www.designandpaper.com/?p=189953" target="_blank" rel="noopener noreferrer">2026 branding trends</a> · <a href="https://fairwindcreative.com/blog/neo-naturalism-the-down-to-earth-graphic-design-trend-defining-2026/" target="_blank" rel="noopener noreferrer">Neo-naturalism</a></li></ul></details>
</div>
<div class="card">
  <h2>Every size, ready to use</h2>
  <p class="hint" style="margin-top:0">Each one is drawn at the exact size the platform asks for. "Use this design everywhere" also puts them in the <a href="?tab=social&amp;view=kit">Brand kit</a>. The words for each profile are written under <a href="?tab=social&amp;view=optimise#words">Profiles</a>.</p>
  <div class="all"><?php foreach (pm_studio_targets() as $k => [$label, $kind, $w, $h]): $pw = $w > $h ? 420 : 200; $ph = (int)round($pw * $h / $w); ?>
    <figure style="margin:0"><img src="<?= pm_h($img('cover', $pw, $ph, '&t=' . $k)) ?>" alt="<?= pm_h($label) ?>" loading="lazy" <?= $w < $h ? 'style="max-width:200px"' : '' ?>>
      <figcaption class="hint"><b><?= pm_h($label) ?></b> · <?= $w ?>×<?= $h ?> · <a href="<?= pm_h($img('cover', $w, $h, '&t=' . $k . '&dl=1')) ?>">Download</a></figcaption></figure>
  <?php endforeach; ?></div>
  <h3>Profile pictures</h3>
  <div class="sizes"><?php foreach (pm_studio_profile_sizes() as $sz => $names): ?>
    <figure><img src="<?= pm_h($img('profile', 120)) ?>" width="84" height="84" alt="" loading="lazy"><span><?= $sz ?>×<?= $sz ?><br><?= pm_h(implode(', ', array_slice($names, 0, 3))) ?><br><a href="<?= pm_h($img('profile', $sz, 0, '&dl=1')) ?>">Download</a></span></figure>
  <?php endforeach; ?></div>
</div>

<?php $hist = array_slice((array)$st['history'], 0, 8); if (count($hist) > 1): ?>
<div class="card">
  <h2>Earlier designs</h2>
  <div class="hist"><?php foreach ($hist as $h): ?>
    <a href="?tab=social&view=studio&d=<?= pm_h($h['id']) ?>" class="<?= $h['id'] === $d ? 'on' : '' ?>"><img src="<?= pm_h($img('cover', 220, 115, '&t=facebook', $h['id'])) ?>" alt="" loading="lazy"><?= pm_h($how[$h['how']] ?? $h['how']) ?> · <?= pm_h(date('j M H:i', strtotime($h['at']))) ?></a>
  <?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
