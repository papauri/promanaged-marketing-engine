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
$roles = [['Researcher', 'Reads the facts, the voice and the brand colour', true], ['Creative director', 'Proposes several kits in one AI call', $aiOn], ['Copy editor', 'Holds the words to the human-voice rules', true],
    ['Art critic', 'Scores every kit and sends weak ones back', true], ['Producer', 'Draws the best three for you', true], ['Copywriter', 'Writes the missing profile words', $aiOn]];
$pv = fn(string $kind, string $id, int $w, int $h = 0) => '?studio=' . $kind . '&b=' . urlencode($vb) . '&id=' . $id . '&w=' . $w . ($kind === 'cover' ? '&h=' . $h . '&t=facebook' : '') . '&v=' . PM_SD_VERSION;
$btx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">' . $extra;
?>
<div class="card bt" id="team">
  <h2>Your brand team</h2>
  <p class="hint" style="margin-top:0">A small team works in a loop so you choose between good kits instead of building one from nothing. The <b>Creative director</b> proposes kits; the <b>Copy editor</b> and the <b>Art critic</b> score each one (readable, short, human, not a repeat, close to the brand colour); anything weak goes back for a second round. You get the best three. <b>Nothing changes until you press Use this kit.</b></p>
  <div class="crew-row"><?php foreach ($roles as [$rn, $rd, $on]): ?><span class="crew-chip<?= $on ? '' : ' off' ?>" title="<?= pm_h($rd . ($on ? '' : ' (needs an AI key)')) ?>"><i></i><?= pm_h($rn) ?></span><?php endforeach; ?></div>
  <form method="post" class="findrow bt-run"><?= $btx('bt_run') ?>
    <button class="btn primary" <?= pm_studio_can_draw() ? '' : 'disabled title="This server cannot draw pictures"' ?>><?= $waiting ? 'Ask for a fresh set' : 'Ask the team for kits' ?></button>
    <label class="check" style="margin:0"><input type="checkbox" name="words" value="1" <?= $aiOn ? 'checked' : 'disabled' ?>> also write the missing profile words</label>
    <span class="hint" style="margin:0"><?= $aiOn ? 'About 2 to 3 AI calls, about a minute.' : 'No AI key: the team varies the look in code and keeps your wording.' ?></span>
  </form>
  <form method="post" class="inl"><?= $btx('bt_auto') ?>
    <label class="check" style="margin:8px 0 0"><input type="checkbox" name="on" value="1" <?= !empty($bt['auto']) ? 'checked' : '' ?> onchange="this.form.requestSubmit()"> Let the team make a fresh set about once a month (it only proposes)</label></form>
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
      <?php if (!empty($p['why'])): ?><p class="hint" style="margin:0"><?= pm_h((string)$p['why']) ?></p><?php endif; ?>
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

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_accounts"><input type="hidden" name="_sec" value="kit">
  <div class="card">
    <h2>Wording and photo</h2>
    <p class="hint" style="margin-top:0">Want something far better than a plain colour with words? <a href="?tab=social&amp;view=studio"><b>Open the Studio</b></a>: styles, your colours and words, and a new design with one press (or from the AI). What you choose there is used for every picture below.<?= $inUse ? ' <b>A Studio design is in use now</b>, so the pictures below come from it.' : '' ?></p>
    <div class="split">
      <div>
        <label>Cover headline</label><input type="text" name="cover_head" value="<?= pm_h($cfg['cover_head']) ?>">
        <label>Cover line</label><input type="text" name="cover_line" value="<?= pm_h($cfg['cover_line']) ?>">
      </div>
      <div>
        <label>Hero photo for covers (JPG or PNG, a real photo of your work or a stay)</label>
        <?php if ($hero !== ''): ?><div class="logoprev"><img src="<?= str_starts_with($hero, PM_ROOT . '/assets/') ? 'assets/' . basename($hero) . '?v=' . filemtime($hero) : '?thumb=' . $vb ?>" alt="Hero photo" style="height:60px"><span class="hint"><?= str_starts_with($hero, PM_ROOT . '/assets/') ? 'Your photo' : 'Using the website preview picture' ?></span><?php if (str_starts_with($hero, PM_ROOT . '/assets/')): ?><label class="check"><input type="checkbox" name="remove_hero" value="1"> Remove</label><?php endif; ?></div><?php endif; ?>
        <input type="file" name="hero" accept="image/jpeg,image/png">
      </div>
    </div>
    <div class="btns"><button class="btn small">Save</button><button class="btn small primary" name="do" value="kit">Make the pictures and covers for every platform</button></div>
  </div>

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
    <?php else: ?><p class="hint">Change it on the platform itself: download, then upload in its profile settings.</p><?php endif; ?>
  </details>
  <?php endforeach; ?>
</form>
