<?php
/**
 * Brand > Brand kit: the cover wording, a hero photo, and the profile picture and cover for every platform at the size it asks for. The look comes from the Studio design in use (or the plain
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
