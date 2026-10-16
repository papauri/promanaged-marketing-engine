<?php
/**
 * Brand > Brand kit: the brand team (a loop of agents that proposes whole kits for you to choose from), the cover wording, a hero photo, and the profile picture and cover for every platform at the size it asks for. The look comes from the Studio design in use (or the plain
 * classic one). Where each profile lives and how to connect it is Operations > Connections. Needs $vb, $settings, $csrf.
 */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$cfg = pm_social_settings($vb);
$fb = pm_social_cfg($vb);
$hero = pm_hero_path($vb);
$kitUrl = fn($f, $dl = false) => '?kit=' . urlencode(basename($f)) . '&b=' . $vb . '&v=' . @filemtime($f) . ($dl ? '&dl=1' : '');
$built = is_dir(pm_kit_dir($vb)) && glob(pm_kit_dir($vb) . '/*.png');
$inUse = function_exists('pm_studio_saved') ? pm_studio_saved($vb) : null;
?>
<?= pm_social_nav('kit') ?>
<?php
$bt = pm_bt_store($vb);
$waiting = array_values(array_filter((array)$bt['proposals'], fn($p) => ($p['status'] ?? '') === 'new'));
$lastRun = (array)($bt['runs'][0] ?? []);
$aiOn = pm_agents_ready();
$pv = fn(string $kind, string $id, int $w, int $h = 0) => '?studio=' . $kind . '&b=' . urlencode($vb) . '&id=' . $id . '&w=' . $w . ($kind === 'cover' ? '&h=' . $h . '&t=facebook' : '') . '&v=' . PM_SD_VERSION;
$btx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">' . $extra;
?>
<div class="card bt" id="team">
  <h2>Your brand team</h2>
  <p class="hint mt-0">A small team proposes whole kits (style, colours, wording), scores them and gives you the best three. <b>Nothing changes until you press Use this kit.</b></p>
  <form method="post" class="findrow bt-run"><?= $btx('bt_run') ?>
    <button class="btn primary" <?= pm_studio_can_draw() ? '' : 'disabled title="This server cannot draw pictures"' ?>><?= $waiting ? 'Ask for a fresh set' : 'Ask the team for kits' ?></button>
    <label class="check m-0"><input type="checkbox" name="words" value="1" <?= $aiOn ? 'checked' : 'disabled' ?>> also write the missing profile words</label>
    <span class="hint m-0"><?= $aiOn ? 'About 2 to 3 AI calls, about a minute.' : 'No AI key: the team varies the look in code and keeps your wording.' ?></span>
  </form>
  <form method="post" class="inl"><?= $btx('bt_auto') ?>
    <label class="check mt-2"><input type="checkbox" name="on" value="1" <?= !empty($bt['auto']) ? 'checked' : '' ?> onchange="this.form.requestSubmit()"> Let the team make a fresh set about once a month (it only proposes)</label></form>
  <?php if ($lastRun): ?>
  <details class="more"><summary class="hint" style="cursor:pointer">How the team worked on <?= pm_h(date('j M H:i', strtotime((string)$lastRun['at']))) ?> · best <?= (int)$lastRun['best'] ?> of 100 · <?= (int)$lastRun['calls'] ?> AI call<?= (int)$lastRun['calls'] === 1 ? '' : 's' ?></summary>
    <ul class="hint"><?php foreach ((array)$lastRun['log'] as $ln): ?><li><?= pm_h((string)$ln) ?></li><?php endforeach; ?></ul></details>
  <?php endif; ?>
  <?php if ($waiting): ?>
  <div class="btgrid">
    <?php foreach ($waiting as $p): ?>
    <div class="btkit">
      <div class="btpics"><img class="btcover" src="<?= pm_h($pv('cover', (string)$p['id'], 440, 167)) ?>" alt="Cover of <?= pm_h((string)$p['name']) ?>" loading="lazy"><img class="btprof" src="<?= pm_h($pv('profile', (string)$p['id'], 140)) ?>" alt="" loading="lazy"></div>
      <div class="btmeta"><b><?= pm_h((string)$p['name']) ?></b> <span class="sx-chip <?= (int)$p['score'] >= PM_BT_THRESHOLD ? 'ok' : 'warn' ?>" title="The art critic's score"><?= (int)$p['score'] ?>/100</span>
        <?= ($p['how'] ?? '') === 'code' ? '<span class="sx-chip">made in code</span>' : '' ?><?= (int)($p['round'] ?? 1) > 1 ? '<span class="sx-chip" title="Sent back by the critics and improved">round 2</span>' : '' ?></div>
      <p class="btwords"><b><?= pm_h((string)$p['headline']) ?></b><br><?= pm_h((string)$p['subline']) ?></p>
      <?php if (!empty($p['why'])): ?><p class="hint m-0"><?= pm_h((string)$p['why']) ?></p><?php endif; ?>
      <div class="sx-chips"><?php foreach ((array)$p['praise'] as $pr): ?><span class="sx-chip ok"><?= pm_h((string)$pr) ?></span><?php endforeach; ?><?php foreach (array_slice((array)$p['issues'], 0, 2) as $is): ?><span class="sx-chip warn" title="<?= pm_h((string)$is) ?>">to watch: <?= pm_h(mb_substr((string)$is, 0, 46)) ?></span><?php endforeach; ?></div>
      <div class="btns">
        <form method="post" class="inl" onsubmit="return confirm('Use this kit for every profile picture and cover, and as the cover wording?')"><?= $btx('bt_use', '<input type="hidden" name="id" value="' . pm_h((string)$p['id']) . '">') ?><button class="btn small primary">Use this kit</button></form>
        <a class="btn small" href="?tab=social&amp;view=studio&amp;d=<?= pm_h((string)$p['id']) ?>">Adjust in the Studio</a>
        <form method="post" class="inl"><?= $btx('bt_dismiss', '<input type="hidden" name="id" value="' . pm_h((string)$p['id']) . '">') ?><button class="btn small ghost">Put aside</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php elseif (!$lastRun): ?><p class="hint">No kits yet. Ask the team: it takes about a minute.</p><?php else: ?><p class="hint">Every kit from the last set was used or put aside. Ask for a fresh set whenever you like.</p><?php endif; ?>
</div>

<?php
$cd = (array)(pm_swarm_store($vb)['wording'] ?? []);
$cdOpts = pm_swarm_waiting($vb, 'wording');
$cdRun = (array)($cd['runs'][0] ?? []);
$pd = (array)(pm_swarm_store($vb)['photo'] ?? []);
$pdPhotos = (array)($pd['photos'] ?? []);
$pdShots = pm_swarm_waiting($vb, 'photo');
$pdRun = (array)($pd['runs'][0] ?? []);
$inUseHead = ($sd = pm_studio_saved($vb)) ? (string)$sd['headline'] : (string)$cfg['cover_head'];
$inUseLine = $sd ? (string)$sd['subline'] : (string)$cfg['cover_line'];
$kind = ['place' => 'The place', 'work' => 'The work', 'people' => 'The people'];
?>
<div class="card bt" id="wording">
  <h2>Cover wording: your writers' room</h2>
  <p class="hint mt-0">Four writers each write the cover headline and supporting line, and an editor scores them. Anything that adds a number or claim your facts do not give is thrown out. <b>Nothing changes until you press Use this wording.</b></p>
  <p class="hint mb-2">In use now: <b><?= pm_h($inUseHead) ?></b><?= $inUseLine !== '' ? ' · ' . pm_h($inUseLine) : '' ?></p>
  <form method="post" class="findrow bt-run"><?= $btx('cd_run') ?>
    <button class="btn primary"><?= $cdOpts ? 'Ask for fresh wording' : 'Ask the writers' ?></button>
    <span class="hint m-0"><?= $aiOn ? 'One or two AI calls, about 20 seconds.' : 'No AI key: the desk re-cuts your own words (tagline, facts, about line).' ?></span>
  </form>
  <?php if ($cdRun): ?><details class="more"><summary class="hint" style="cursor:pointer">How the desk worked on <?= pm_h(date('j M H:i', strtotime((string)$cdRun['at']))) ?> · best <?= (int)$cdRun['best'] ?> of 100 · <?= (int)$cdRun['calls'] ?> AI call<?= (int)$cdRun['calls'] === 1 ? '' : 's' ?></summary>
    <ul class="hint"><?php foreach ((array)$cdRun['log'] as $ln): ?><li><?= pm_h((string)$ln) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <?php if ($cdOpts): ?>
  <div class="btgrid">
    <?php foreach ($cdOpts as $o): ?>
    <div class="btkit">
      <div class="btmeta"><span class="sx-chip"><?= pm_h((string)$o['writer']) ?></span> <span class="sx-chip <?= (int)$o['score'] >= PM_CD_THRESHOLD ? 'ok' : 'warn' ?>" title="The copy editor's score"><?= (int)$o['score'] ?>/100</span><?= (int)($o['round'] ?? 1) > 1 ? '<span class="sx-chip" title="Sent back by the editor and improved">round 2</span>' : '' ?></div>
      <p class="btwords"><b><?= pm_h((string)$o['headline']) ?></b><br><?= pm_h((string)$o['line']) ?></p>
      <?php if (!empty($o['why'])): ?><p class="hint m-0"><?= pm_h((string)$o['why']) ?></p><?php endif; ?>
      <div class="sx-chips"><?php foreach ((array)$o['praise'] as $pr): ?><span class="sx-chip ok"><?= pm_h((string)$pr) ?></span><?php endforeach; ?><?php foreach (array_slice((array)$o['issues'], 0, 2) as $is): ?><span class="sx-chip warn" title="<?= pm_h((string)$is) ?>">to watch: <?= pm_h(mb_substr((string)$is, 0, 46)) ?></span><?php endforeach; ?></div>
      <div class="btns">
        <form method="post" class="inl" onsubmit="return confirm('Use this as the cover wording everywhere?')"><?= $btx('cd_use', '<input type="hidden" name="id" value="' . pm_h((string)$o['id']) . '">') ?><button class="btn small primary">Use this wording</button></form>
        <form method="post" class="inl"><?= $btx('cd_dismiss', '<input type="hidden" name="id" value="' . pm_h((string)$o['id']) . '">') ?><button class="btn small ghost">Put aside</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php elseif (!$cdRun): ?><p class="hint">No options yet. Ask the writers: it takes about twenty seconds.</p><?php endif; ?>
</div>

<div class="card bt" id="photo">
  <h2>Cover photo: your photo desk</h2>
  <p class="hint mt-0">Reads the photos you already have (cover in use, the picture your website shares, your library) and says which would work as a cover, with a plain fix for each problem. Real photos only: nothing is generated or bought.</p>
  <form method="post" class="findrow bt-run"><?= $btx('pd_run') ?>
    <button class="btn primary"><?= $pdPhotos || $pdShots ? 'Look again' : 'Ask the photo desk' ?></button>
    <label class="check m-0"><input type="checkbox" name="brief" value="1" <?= $aiOn ? 'checked' : '' ?>> also write shot briefs<?= $aiOn ? '' : ' (plain ones without an AI key)' ?></label>
    <span class="hint m-0">Reading photos is free; briefs are one or two AI calls.</span>
  </form>
  <?php if ($pdRun): ?><details class="more"><summary class="hint" style="cursor:pointer">How the desk worked on <?= pm_h(date('j M H:i', strtotime((string)$pdRun['at']))) ?> · <?= (int)$pdRun['calls'] ?> AI call<?= (int)$pdRun['calls'] === 1 ? '' : 's' ?></summary>
    <ul class="hint"><?php foreach ((array)$pdRun['log'] as $ln): ?><li><?= pm_h((string)$ln) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <?php if ($pdPhotos): ?>
  <h3>The photos you have</h3>
  <div class="btgrid pdgrid">
    <?php foreach ($pdPhotos as $ph): ?>
    <div class="btkit">
      <img class="pdthumb" src="?pdimg=<?= pm_h((string)$ph['key']) ?>&amp;b=<?= pm_h($vb) ?>&amp;v=<?= (int)$ph['mtime'] ?>" alt="<?= pm_h((string)$ph['label']) ?>" loading="lazy">
      <div class="btmeta"><b><?= pm_h((string)$ph['label']) ?></b> <span class="sx-chip <?= (int)$ph['score'] >= PM_PD_THRESHOLD ? 'ok' : ((int)$ph['score'] >= 55 ? 'warn' : 'bad') ?>" title="The photo critic's score for a cover"><?= (int)$ph['score'] ?>/100</span><?= $ph['key'] === 'current' ? '<span class="sx-chip">in use</span>' : '' ?></div>
      <p class="hint m-0"><?= !empty($ph['w']) ? (int)$ph['w'] . ' × ' . (int)$ph['h'] . ' pixels' : '' ?></p>
      <div class="sx-chips"><?php foreach ((array)$ph['praise'] as $pr): ?><span class="sx-chip ok"><?= pm_h((string)$pr) ?></span><?php endforeach; ?></div>
      <?php if (!empty($ph['issues'])): ?><ul class="hint pdissues"><?php foreach (array_slice((array)$ph['issues'], 0, 3) as $is): ?><li><?= pm_h((string)$is) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if (!empty($ph['fixes'])): ?><p class="hint m-0"><b>Fix:</b> <?= pm_h(implode(' ', array_slice((array)$ph['fixes'], 0, 2))) ?></p><?php endif; ?>
      <?php if ($ph['key'] !== 'current'): ?><div class="btns"><form method="post" class="inl" onsubmit="return confirm('Use this photo as the cover photo and draw the kit again?')"><?= $btx('pd_use', '<input type="hidden" name="key" value="' . pm_h((string)$ph['key']) . '">') ?><button class="btn small primary">Use as the cover photo</button></form></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php elseif ($pdRun): ?><p class="hint">No photos to read yet. Add pictures to the photo library (Social > Plan > Photo library) or upload a cover photo below.</p><?php endif; ?>
  <?php if ($pdShots): ?>
  <h3>Photos to take next</h3>
  <div class="btgrid">
    <?php foreach ($pdShots as $sh): ?>
    <div class="btkit">
      <div class="btmeta"><span class="sx-chip"><?= pm_h($kind[$sh['kind']] ?? 'Shot') ?></span> <b><?= pm_h((string)$sh['title']) ?></b> <span class="sx-chip <?= (int)$sh['score'] >= PM_PD_THRESHOLD ? 'ok' : 'warn' ?>" title="The code critic's score for the brief"><?= (int)$sh['score'] ?>/100</span><?= ($sh['how'] ?? '') === 'code' ? '<span class="sx-chip">plain brief</span>' : '' ?></div>
      <dl class="shot">
        <dt>In the frame</dt><dd><?= pm_h((string)$sh['subject']) ?></dd>
        <dt>Where</dt><dd><?= pm_h((string)$sh['setting']) ?></dd>
        <dt>Hold the phone</dt><dd><?= pm_h((string)$sh['angle']) ?></dd>
        <dt>Light</dt><dd><?= pm_h((string)$sh['light']) ?></dd>
        <dt>Keep calm for the headline</dt><dd><?= pm_h((string)$sh['text_space']) ?></dd>
        <dt>Avoid</dt><dd><?= pm_h((string)$sh['avoid']) ?></dd>
      </dl>
      <?php if (!empty($sh['why'])): ?><p class="hint m-0"><?= pm_h((string)$sh['why']) ?></p><?php endif; ?>
      <div class="sx-chips"><?php foreach ((array)$sh['praise'] as $pr): ?><span class="sx-chip ok"><?= pm_h((string)$pr) ?></span><?php endforeach; ?><?php foreach (array_slice((array)$sh['issues'], 0, 2) as $is): ?><span class="sx-chip warn" title="<?= pm_h((string)$is) ?>">to watch: <?= pm_h(mb_substr((string)$is, 0, 46)) ?></span><?php endforeach; ?></div>
      <div class="btns"><a class="btn small" href="#manual" onclick="document.getElementById('manual').open=true">I took it: upload it</a>
        <form method="post" class="inl"><?= $btx('pd_dismiss', '<input type="hidden" name="id" value="' . pm_h((string)$sh['id']) . '">') ?><button class="btn small ghost">Put aside</button></form></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php $sl = function_exists('pm_site_look_cached') ? pm_site_look_cached($vb) : []; $slx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra; ?>
<div class="card" id="site">
  <div class="sl-head">
    <div><h2 class="mt-0 mb-0">From your website</h2>
      <p class="hint mt-0 mb-0">The brand team designs the profile picture and cover from your website's own colours, fonts and photos, so the profiles look like they belong to it.<?= !empty($sl['url']) ? ' Read from <a href="' . pm_h((string)$sl['url']) . '" target="_blank" rel="noopener noreferrer">' . pm_h(preg_replace('#^https?://#', '', (string)$sl['url'])) . '</a>' . (!empty($sl['at']) ? ' on ' . pm_h(date('j M, H:i', strtotime((string)$sl['at']))) : '') . '.' : '' ?></p></div>
    <form method="post"><?= $slx('site_look') ?><button class="btn small"><?= empty($sl['at']) ? 'Read the website' : 'Read it again' ?></button></form>
  </div>
  <?php if (!empty($sl['error'])): ?><p class="hint warnt"><?= pm_h((string)$sl['error']) ?></p><?php endif; ?>
  <?php if (!empty($sl['palette'])): ?>
  <div class="sl-row"><span class="sl-k">Colours</span><span class="sl-sw"><?php foreach ((array)$sl['palette'] as $pc): ?><i style="background:<?= pm_h((string)$pc['hex']) ?>" title="<?= pm_h((string)$pc['hex']) ?>"></i><?php endforeach; ?></span>
    <span class="muted t-sm"><?= pm_h(($sl['base'] ?? 'light') === 'dark' ? 'on a dark background' : 'on a light background') ?><?= !empty($sl['fonts']) ? ' · fonts ' . pm_h(implode(', ', (array)$sl['fonts'])) : '' ?></span></div>
  <?php if (!empty($sl['h1']) || !empty($sl['title'])): ?><div class="sl-row"><span class="sl-k">Its headline</span><span>“<?= pm_h((string)($sl['h1'] ?: $sl['title'])) ?>”</span></div><?php endif; ?>
  <?php endif; ?>
  <?php if (!empty($sl['photos'])): $heroNow = $hero; ?>
  <div class="sl-photos">
    <?php foreach ((array)$sl['photos'] as $pi => $ph): $pf = pm_sl_dir($vb) . '/' . basename((string)$ph['file']); if (!is_file($pf)) continue; $isHero = $heroNow !== '' && (realpath($heroNow) === realpath($pf) || (is_file($heroNow) && md5_file($heroNow) === md5_file($pf))); ?>
    <figure class="sl-ph<?= $isHero ? ' on' : '' ?>"><img src="?sitepic=<?= pm_h(basename($pf)) ?>&amp;b=<?= pm_h($vb) ?>&amp;v=<?= (int)filemtime($pf) ?>" alt="<?= pm_h((string)$ph['alt']) ?>" loading="lazy">
      <figcaption><span class="t-sm"><?= pm_h((string)$ph['alt'] ?: 'Website photo') ?> · <?= (int)$ph['w'] ?>×<?= (int)$ph['h'] ?></span>
        <?php if ($isHero): ?><span class="sx-chip ok">the hero photo</span><?php else: ?><form method="post" class="inl"><?= $slx('site_hero', '<input type="hidden" name="i" value="' . (int)$pi . '">') ?><button class="btn small">Use as the hero photo</button></form><?php endif; ?></figcaption></figure>
    <?php endforeach; ?>
  </div>
  <p class="hint mb-0">A photo of a client's place is theirs: use it on a cover only with their permission.</p>
  <?php elseif (!empty($sl['at']) && empty($sl['error'])): ?><p class="hint mb-0">No photo on the website is big enough for a cover (at least 900 × 450, wider than tall): upload one below.</p><?php endif; ?>
</div>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_accounts"><input type="hidden" name="_sec" value="kit">
  <details class="card" id="manual">
    <summary><b>Write the wording or upload a photo yourself</b> <span class="muted">· the same boxes, for when you know what you want</span></summary>
    <p class="hint mt-0">Want something far better than a plain colour with words? <a href="?tab=social&amp;view=studio"><b>Open the Studio</b></a>: styles, your colours and words, and a new design with one press (or from the AI). What you choose there is used for every picture below.<?= $inUse ? ' <b>A Studio design is in use now</b>, so the pictures below come from it.' : '' ?></p>
    <div class="split">
      <div>
        <label>Cover headline</label><input type="text" name="cover_head" data-sg="cover_head" value="<?= pm_h($cfg['cover_head']) ?>">
        <label>Cover line</label><input type="text" name="cover_line" data-sg="cover_line" value="<?= pm_h($cfg['cover_line']) ?>"><?= pm_suggest_button('cover', 'Suggest the cover words with AI') ?>
      </div>
      <div>
        <label>Hero photo for covers (JPG or PNG, a real photo of your work or a stay)</label>
        <?php if ($hero !== ''): $heroSite = function_exists('pm_sl_dir') && str_starts_with(strtr($hero, '\\', '/'), strtr(pm_sl_dir($vb), '\\', '/')); ?><div class="logoprev"><img src="<?= str_starts_with($hero, PM_ROOT . '/assets/') ? 'assets/' . basename($hero) . '?v=' . filemtime($hero) : ($heroSite ? '?sitepic=' . basename($hero) . '&b=' . $vb . '&v=' . filemtime($hero) : '?thumb=' . $vb) ?>" alt="Hero photo" style="height:60px"><span class="hint"><?= str_starts_with($hero, PM_ROOT . '/assets/') ? 'Your photo' : ($heroSite ? 'The best photo on your website (pick another above)' : 'Using the website preview picture') ?></span><?php if (str_starts_with($hero, PM_ROOT . '/assets/')): ?><label class="check"><input type="checkbox" name="remove_hero" value="1"> Remove</label><?php endif; ?></div><?php endif; ?>
        <input type="file" name="hero" accept="image/jpeg,image/png">
      </div>
    </div>
    <div class="btns"><button class="btn small">Save</button><button class="btn small primary" name="do" value="kit">Make the pictures and covers for every platform</button></div>
  </details>

  <?php foreach (pm_platforms() as $k => $p):
      $pf = pm_kit_dir($vb) . "/profile-{$p['profile'][0]}.png";
      $cf = $p['cover'] ? pm_kit_dir($vb) . "/cover-{$p['cover'][0]}x{$p['cover'][1]}.png" : ''; ?>
  <details class="card plat" <?= $k === 'facebook' ? 'open' : '' ?>>
    <summary><b><?= pm_h($p['name']) ?></b> <span class="muted">· profile <?= $p['profile'][0] ?>×<?= $p['profile'][1] ?><?= $p['cover'] ? ' · ' . ($k === 'youtube' ? 'banner' : 'cover') . ' ' . $p['cover'][0] . '×' . $p['cover'][1] : '' ?></span></summary>
    <div class="kit">
      <?php if (is_file($pf)): ?><div><label>Profile picture</label><img class="kprof" src="<?= pm_h($kitUrl($pf)) ?>" alt=""><a class="btn small" href="<?= pm_h($kitUrl($pf, true)) ?>">Download</a></div>
      <?php else: ?><p class="hint">Press "Make the pictures and covers".</p><?php endif; ?>
      <?php if ($p['cover'] && is_file($cf)): ?><div><label><?= $k === 'youtube' ? 'Banner' : 'Cover' ?></label><img class="kcover" src="<?= pm_h($kitUrl($cf)) ?>" alt=""><a class="btn small" href="<?= pm_h($kitUrl($cf, true)) ?>">Download</a></div><?php endif; ?>
    </div>
    <?php if ($k === 'facebook'): ?>
      <div class="btns"><button class="btn small" name="do" value="apply_profile" <?= $fb['ready'] ? '' : 'disabled' ?> onclick="return confirm('Change the Facebook Page profile picture now?')">Set as Facebook profile picture</button>
        <button class="btn small" name="do" value="apply_cover" <?= $fb['ready'] ? '' : 'disabled' ?> onclick="return confirm('Change the Facebook Page cover now?')">Set as Facebook cover</button></div>
      <?php if (!$fb['ready']): ?><p class="hint">These buttons work once the Page is connected (<a href="?tab=settings&amp;section=connections">Operations &gt; Connections</a>). Until then, download and upload by hand.</p><?php endif; ?>
      <?php $copySt = pm_copy_store($vb); $live = $fb['ready'] ? pm_profile_page_fields($vb) : null; $chosenAi = (array)(pm_opt_store($vb)['ai'] ?? []); ?>
      <div class="fbbio" id="fbbio">
        <h3 class="mt-3 mb-1">Facebook bio</h3>
        <p class="hint mt-0">The words beside the pictures: the intro under the Page name and the description on the About tab. Written from your facts only, in your voice.</p>
        <?php foreach (['fb_about', 'fb_description'] as $bk): [$bl, $blim, $bwhat] = PM_COPY_FIELDS[$bk]; $fid = 'fbb-' . $bk; $opts = (array)($copySt[$bk]['options'] ?? []); $now = trim((string)($live[$bk === 'fb_about' ? 'about' : 'description'] ?? ''));
            $start = trim((string)($chosenAi[$bk] ?? '')) ?: (string)($opts[0]['text'] ?? '') ?: pm_copy_plain($vb, $bk); ?>
        <div class="fbbio-f">
          <div class="fbbio-h"><b><?= pm_h($bl) ?></b> <span class="muted t-sm">· up to <?= (int)$blim ?> characters · <?= pm_h($bwhat) ?></span></div>
          <?php if ($live !== null): ?><p class="t-sm my-1"><span class="muted">On Facebook now:</span> <?= $now !== '' ? pm_h($now) : '<span class="warnt">nothing yet</span>' ?></p><?php endif; ?>
          <?php foreach ($opts as $oi => $o): ?>
          <div class="fbbio-o"><span class="sx-chip"><?= pm_h(PM_COPY_VOICES[$o['voice']] ?? $o['voice']) ?> · <?= mb_strlen($o['text']) ?></span><span><?= pm_h($o['text']) ?></span>
            <span class="btns"><button class="btn small" form="<?= $fid ?>" name="text" value="<?= pm_h($o['text']) ?>" <?= $fb['ready'] ? 'onclick="return confirm(\'Put this on the Facebook Page now?\')"' : '' ?>><?= $fb['ready'] ? 'Set on Facebook' : 'Use this' ?></button><button type="button" class="btn small ghost" data-copy="<?= pm_h($o['text']) ?>">Copy</button></span></div>
          <?php endforeach; ?>
          <textarea form="<?= $fid ?>" name="own" rows="<?= $bk === 'fb_about' ? 2 : 3 ?>" maxlength="<?= (int)$blim ?>" aria-label="<?= pm_h($bl) ?>"><?= pm_h(mb_substr($start, 0, $blim)) ?></textarea>
          <div class="btns mt-1"><button class="btn small primary" form="<?= $fid ?>" <?= $fb['ready'] ? 'onclick="return confirm(\'Put these words on the Facebook Page now?\')"' : '' ?>><?= $fb['ready'] ? 'Set these words on Facebook' : 'Save these words' ?></button><button type="button" class="btn small ghost" data-copy-target="textarea[form=<?= $fid ?>]">Copy</button></div>
        </div>
        <?php endforeach; ?>
        <div class="btns"><button class="btn small" form="fbb-write" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?> title="Three options each (their outcome first, who it is for with proof, short with an easy next step), from your facts only: one AI call"><?= array_filter([$copySt['fb_about'] ?? null, $copySt['fb_description'] ?? null]) ? 'Write 3 new options for each (AI)' : 'Write 3 options for each (AI)' ?></button></div>
      </div>
    <?php else: ?><p class="hint">Change it on the platform itself: download, then upload in its profile settings.</p><?php endif; ?>
  </details>
  <?php endforeach; ?>
</form>
<?php // the Facebook bio's own forms: its boxes and buttons sit inside the Facebook section above (form="...") ?>
<?php foreach (['fb_about', 'fb_description'] as $bk): ?><form method="post" id="fbb-<?= $bk ?>"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="kit_bio"><input type="hidden" name="key" value="<?= $bk ?>"></form><?php endforeach; ?>
<form method="post" id="fbb-write"><input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="copy_write"><input type="hidden" name="back" value="kit"><input type="hidden" name="keys[]" value="fb_about"><input type="hidden" name="keys[]" value="fb_description"></form>
