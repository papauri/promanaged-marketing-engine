<?php
/** Social > Accounts & branding: every platform's status, link, setup steps, and its sized profile and cover pictures. */
pm_brand_set($vb);
$bname = $vb === 'travel' ? $settings['travel']['company_name'] : 'ProManaged IT';
$cfg = pm_social_settings($vb);
$fb = pm_social_cfg($vb);
$li = pm_linkedin_cfg($vb);
$pre = $vb === 'travel' ? 'TM_' : '';
$hero = pm_hero_path($vb);
$kitUrl = fn($f, $dl = false) => '?kit=' . urlencode(basename($f)) . '&b=' . $vb . '&v=' . @filemtime($f) . ($dl ? '&dl=1' : '');
$status = function (string $k) use ($fb, $li, $cfg): array {
    $hasUrl = trim((string)($cfg['urls'][$k] ?? '')) !== '';
    return match ($k) {
        'facebook' => $fb['ready'] ? ['Connected', 'hot'] : ($hasUrl ? ['Page added, app not connected', 'warn'] : ['Not set up', '']),
        'instagram' => $fb['ig_id'] !== '' ? ['Linked', 'hot'] : ($hasUrl ? ['Account added, not linked', 'warn'] : ['Not set up', '']),
        'linkedin' => $li['ready'] ? ['Connected', 'hot'] : ($hasUrl ? ['Page added', 'warn'] : ['Not set up', '']),
        default => $hasUrl ? ['Added', 'hot'] : ['Not set up', ''],
    };
};
$built = is_dir(pm_kit_dir($vb)) && glob(pm_kit_dir($vb) . '/*.png');
?>
<h1>Social · <?= pm_h($bname) ?></h1>
<?= pm_social_nav('accounts') ?>

<?php $tinfo = $fb['ready'] ? pm_fb_token_info($fb['token']) : null; $e0 = pm_env(); ?>
<div class="card">
  <h2>Facebook connection</h2>
  <?php if ($tinfo): ?>
    <p><?= !empty($tinfo['ok']) && $tinfo['type'] === 'PAGE' && !$tinfo['missing'] ? '<span class="pill hot">Healthy</span> Permanent Page token with every permission the app needs.'
        : '<span class="pill warn">Needs attention</span> ' . (empty($tinfo['ok']) ? pm_h($tinfo['error']) : ($tinfo['type'] !== 'PAGE' ? 'The saved token is a personal (user) token' . ($tinfo['expires'] ? ' that expires ' . date('j M H:i', $tinfo['expires']) : '') . ', not a Page token.' : '') . ($tinfo['missing'] ? ' Missing: ' . pm_h(implode(', ', $tinfo['missing'])) . '.' : '')) ?></p>
  <?php else: ?><p class="hint" style="margin-top:0">Not connected yet.</p><?php endif; ?>
  <?php $gg = pm_graph_guard(); ?><p class="hint">Facebook calls today: <?= (int)$gg['calls'] ?> (<?= (int)$gg['cached'] ?> answered from memory instead) · Facebook limit used: <?= (int)$gg['pct'] ?>%<?= (int)$gg['until'] > time() ? ' · <b>paused by Facebook until ' . date('H:i', (int)$gg['until']) . '</b>' : '' ?>. Above 75% the app shows the last known data instead of calling.</p>
  <?php if (!empty($_SESSION['fb_pages'])): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbm"><input type="hidden" name="do" value="connect_pick"><input type="hidden" name="view" value="accounts">
      <label>Which Page is <?= pm_h($bname) ?>?</label>
      <?php foreach ($_SESSION['fb_pages'] as $pgx): ?><label class="check"><input type="radio" name="page" value="<?= pm_h($pgx['id']) ?>"> <?= pm_h($pgx['name']) ?> <span class="muted">(<?= pm_h($pgx['id']) ?>)</span></label><?php endforeach; ?>
      <div class="btns"><button class="btn primary">Use this Page</button></div></form>
  <?php else: ?>
  <details <?= (!$tinfo || $tinfo['type'] !== 'PAGE' || $tinfo['missing']) ? 'open' : '' ?>><summary>Connect in one step (recommended)</summary>
    <ol class="steps">
      <li><b>Get the App ID and App Secret:</b>
        <ol>
          <li>Open <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">developers.facebook.com/apps</a> and log in with the Facebook account that manages the Page.</li>
          <li>Click the app you made for the Page (the one chosen in Graph API Explorer). No app yet? Press <b>Create app</b>, choose "Other" then "Business", give it a name such as "<?= pm_h($bname) ?> Manager", and press Create.</li>
          <li>In the left menu open <b>App settings</b> > <b>Basic</b>.</li>
          <li><b>App ID</b> is the number at the top. Copy it.</li>
          <li><b>App Secret</b> is the dotted box next to it. Press <b>Show</b>, type your Facebook password if asked, then copy it. Never share it or post it anywhere.</li>
        </ol></li>
      <li>Tools > Graph API Explorer: choose the app, add the permissions <code><?= pm_h(implode(', ', array_keys(pm_fb_scopes()))) ?>, business_management</code>, press Generate Access Token and allow it for the Page.</li>
      <li>Paste the token below. The app makes it permanent, finds your Pages and saves the right one in .env. (Your token and secret stay on this computer.)</li>
    </ol>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbm"><input type="hidden" name="do" value="connect"><input type="hidden" name="view" value="accounts">
      <div class="row">
        <div><label>App ID</label><input type="text" name="app_id" value="<?= pm_h((string)($e0['FB_APP_ID'] ?? '')) ?>"></div>
        <div><label>App Secret</label><input type="password" name="app_secret" placeholder="<?= !empty($e0['FB_APP_SECRET']) ? 'Saved, leave blank to keep' : '' ?>" autocomplete="new-password"></div>
      </div>
      <label>Token from Graph API Explorer</label><textarea name="user_token" rows="2" autocomplete="off"></textarea>
      <div class="btns"><button class="btn primary">Connect</button></div>
    </form>
  </details>
  <?php endif; ?>
</div>

<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_accounts">

  <div class="card">
    <h2>Branding for every profile</h2>
    <div class="split">
      <div>
        <label>Cover headline</label><input type="text" name="cover_head" value="<?= pm_h($cfg['cover_head']) ?>">
        <label>Cover line</label><input type="text" name="cover_line" value="<?= pm_h($cfg['cover_line']) ?>">
        <label>Hero photo for covers (JPG or PNG, a real photo of your work or a stay)</label>
        <?php if ($hero !== ''): ?><div class="logoprev"><img src="<?= str_starts_with($hero, PM_ROOT . '/assets/') ? 'assets/' . basename($hero) . '?v=' . filemtime($hero) : '?thumb=' . $vb ?>" alt="Hero photo" style="height:60px"><span class="hint"><?= str_starts_with($hero, PM_ROOT . '/assets/') ? 'Your photo' : 'Using the website preview picture' ?></span><?php if (str_starts_with($hero, PM_ROOT . '/assets/')): ?><label class="check"><input type="checkbox" name="remove_hero" value="1"> Remove</label><?php endif; ?></div><?php endif; ?>
        <input type="file" name="hero" accept="image/jpeg,image/png">
      </div>
      <div>
        <label>Post automatically to</label>
        <label class="check"><input type="checkbox" name="channels[facebook]" value="1" <?= !empty($cfg['channels']['facebook']) ? 'checked' : '' ?>> Facebook <?= $fb['ready'] ? '' : '<span class="muted">(not connected)</span>' ?></label>
        <label class="check"><input type="checkbox" name="channels[instagram]" value="1" <?= !empty($cfg['channels']['instagram']) ? 'checked' : '' ?>> Instagram <?= $fb['ig_id'] !== '' ? '' : '<span class="muted">(not linked)</span>' ?></label>
        <label class="check"><input type="checkbox" name="channels[linkedin]" value="1" <?= !empty($cfg['channels']['linkedin']) ? 'checked' : '' ?>> LinkedIn <?= $li['ready'] ? '' : '<span class="muted">(not connected)</span>' ?></label>
        <p class="hint">TikTok, YouTube, X, Google and WhatsApp Status: use "Copy caption" and "Download picture" on each post.</p>
      </div>
    </div>
    <div class="btns"><button class="btn small">Save</button><button class="btn small primary" name="do" value="kit">Make profile pictures and covers for every platform</button></div>
  </div>

  <?php foreach (pm_platforms() as $k => $p): [$st, $cls] = $status($k);
      $pf = pm_kit_dir($vb) . "/profile-{$p['profile'][0]}.png";
      $cf = $p['cover'] ? pm_kit_dir($vb) . "/cover-{$p['cover'][0]}x{$p['cover'][1]}.png" : ''; ?>
  <details class="card plat" <?= $k === 'facebook' ? 'open' : '' ?>>
    <summary><b><?= pm_h($p['name']) ?></b> <span class="pill <?= $cls ?>"><?= pm_h($st) ?></span></summary>
    <div class="split">
      <div>
        <label>Profile link</label><input type="text" name="url[<?= $k ?>]" value="<?= pm_h((string)($cfg['urls'][$k] ?? '')) ?>" placeholder="https://...">
        <p class="hint"><b>What the app does here:</b> <?= pm_h($p['auto']) ?></p>
        <?php if ($p['env']): ?><p class="hint"><b>.env lines:</b> <code><?= pm_h(implode('=…  ', array_map(fn($x) => $pre . $x, $p['env']))) ?>=…</code></p><?php endif; ?>
        <ol class="steps"><?php foreach ($p['steps'] as $stp): ?><li><?= pm_h($stp) ?></li><?php endforeach; ?></ol>
      </div>
      <div class="kit">
        <label>Profile picture <?= $p['profile'][0] ?>×<?= $p['profile'][1] ?></label>
        <?php if (is_file($pf)): ?><img class="kprof" src="<?= pm_h($kitUrl($pf)) ?>" alt=""><a class="btn small" href="<?= pm_h($kitUrl($pf, true)) ?>">Download</a><?php else: ?><p class="hint">Press "Make profile pictures and covers".</p><?php endif; ?>
        <?php if ($p['cover']): ?>
          <label style="margin-top:12px"><?= $k === 'youtube' ? 'Banner' : 'Cover' ?> <?= $p['cover'][0] ?>×<?= $p['cover'][1] ?></label>
          <?php if (is_file($cf)): ?><img class="kcover" src="<?= pm_h($kitUrl($cf)) ?>" alt=""><a class="btn small" href="<?= pm_h($kitUrl($cf, true)) ?>">Download</a><?php endif; ?>
        <?php endif; ?>
        <?php if ($k === 'facebook'): ?>
          <div class="btns"><button class="btn small" name="do" value="apply_profile" <?= $fb['ready'] ? '' : 'disabled' ?> onclick="return confirm('Change the Facebook Page profile picture now?')">Set as Facebook profile picture</button>
            <button class="btn small" name="do" value="apply_cover" <?= $fb['ready'] ? '' : 'disabled' ?> onclick="return confirm('Change the Facebook Page cover now?')">Set as Facebook cover</button></div>
          <?php if (!$fb['ready']): ?><p class="hint">These buttons work once the Page is connected. Until then, download and upload by hand.</p><?php endif; ?>
        <?php else: ?><p class="hint">Change it on the platform itself (download, then upload in its profile settings).</p><?php endif; ?>
      </div>
    </div>
  </details>
  <?php endforeach; ?>
  <div class="btns"><button class="btn primary">Save links and settings</button></div>
</form>

<?php $pil = pm_social_pillars($vb); $pil[] = ['name' => '', 'weight' => '']; $pil[] = ['name' => '', 'weight' => '']; $proofRows = pm_social_proof_all($vb); $proofRows[] = ['type' => 'quote']; $proofRows[] = ['type' => 'offer']; ?>
<div class="card">
  <h2>Content pillars</h2>
  <p class="hint">The planner writes exactly this mix of posts (weights are shares, not percentages that must add to 100). Proof and Offer posts only happen when the proof bank below has a usable item.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_x">
    <?php foreach ($pil as $r): ?><div class="row"><div><input type="text" name="pillar_name[]" value="<?= pm_h((string)$r['name']) ?>" placeholder="Pillar name" aria-label="Pillar name"></div><div><input type="number" min="0" max="100" name="pillar_weight[]" value="<?= pm_h((string)$r['weight']) ?>" placeholder="Weight" aria-label="Weight"></div></div><?php endforeach; ?>
    <div class="btns"><button class="btn small primary" name="do" value="pillars_save">Save pillars</button><button class="btn small" name="do" value="pillars_reset">Back to the defaults</button></div>
  </form>
</div>

<div class="card">
  <h2>Proof bank</h2>
  <p class="hint">Real quotes, results, photos and offers the planner may use. Text is posted word for word. A quote or result is used only with the client's consent ticked; offers need an end date to get a "last days" post and stop being used after it. Clients who sign get a blank row here to ask for a one-line quote.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_x">
    <?php foreach ($proofRows as $i => $r): ?>
    <div class="split" style="margin-bottom:12px">
      <div>
        <input type="hidden" name="proof[<?= $i ?>][id]" value="<?= pm_h((string)($r['id'] ?? '')) ?>"><input type="hidden" name="proof[<?= $i ?>][lead_id]" value="<?= pm_h((string)($r['lead_id'] ?? '')) ?>">
        <select name="proof[<?= $i ?>][type]" aria-label="Type"><?php foreach (['quote' => 'Quote', 'result' => 'Result', 'photo' => 'Photo or story', 'offer' => 'Offer'] as $k => $l): ?><option value="<?= $k ?>" <?= ($r['type'] ?? 'quote') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <textarea name="proof[<?= $i ?>][text]" rows="2" placeholder="Exact words (quote, result or offer)" aria-label="Text"><?= pm_h((string)($r['text'] ?? '')) ?></textarea>
      </div>
      <div>
        <input type="text" name="proof[<?= $i ?>][client_name]" value="<?= pm_h((string)($r['client_name'] ?? '')) ?>" placeholder="Client name" aria-label="Client name">
        <input type="text" name="proof[<?= $i ?>][consent_note]" value="<?= pm_h((string)($r['consent_note'] ?? '')) ?>" placeholder="How consent was given" aria-label="Consent note">
        <input type="date" name="proof[<?= $i ?>][expires]" value="<?= pm_h((string)($r['expires'] ?? '')) ?>" aria-label="Ends on">
        <label class="check"><input type="checkbox" name="proof[<?= $i ?>][consent]" value="1" <?= !empty($r['consent']) ? 'checked' : '' ?>> They agreed to be featured</label>
        <?php if (!empty($r['id'])): ?><label class="check"><input type="checkbox" name="proof[<?= $i ?>][delete]" value="1"> Delete</label><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="btns"><button class="btn small primary" name="do" value="proof_save">Save proof bank</button></div>
  </form>
</div>
