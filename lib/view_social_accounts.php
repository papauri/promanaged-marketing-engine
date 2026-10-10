<?php
/** Social > Accounts & branding: every platform's status, link, setup steps, and its sized profile and cover pictures. */
pm_brand_set($vb);
$bname = pm_brand_title($settings, $vb);
$cfg = pm_social_settings($vb);
$fb = pm_social_cfg($vb);
$li = pm_linkedin_cfg($vb);
$pre = pm_brand_env_prefix($vb);
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
$stepHtml = fn(string $t): string => preg_match('/^([^:]{3,48}):\s(.+)$/su', $t, $m) ? '<b>' . pm_h($m[1]) . ':</b> ' . pm_h($m[2]) : pm_h($t); // bold the lead-in so the steps scan quickly
?>
<?php $nav = pm_social_nav('accounts'); echo $nav; if (!str_contains($nav, 'sx-banner')) { echo pm_social_banner(); } ?>

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
  <?php $scopes = pm_fb_scopes(); $needScopes = array_diff_key($scopes, array_flip(PM_FB_OPTIONAL_SCOPES));
        $extraScopes = array_intersect_key($scopes, array_flip(PM_FB_OPTIONAL_SCOPES)) + ['instagram_content_publish' => 'publish posts to Instagram']; ?>
  <details class="fbconnect" <?= (!$tinfo || $tinfo['type'] !== 'PAGE' || $tinfo['missing']) ? 'open' : '' ?>><summary><?= $tinfo ? 'Reconnect Facebook, or add permissions' : 'Connect Facebook in 3 steps' ?></summary>
    <p class="hint">About 5 minutes, once. Your App Secret and token stay on this computer.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="fbm"><input type="hidden" name="do" value="connect"><input type="hidden" name="view" value="accounts">
    <ol class="fbsteps">
      <li>
        <h4>Open your Facebook app and copy two things</h4>
        <p><a class="btn small" href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">Open Facebook apps &#8599;</a></p>
        <p>Click your app. No app yet? Press <b>Create app</b>, choose <b>Other</b>, then <b>Business</b>, and name it "<?= pm_h($bname) ?> Manager". Then open <b>App settings &gt; Basic</b> and copy:</p>
        <div class="row">
          <div><label>App ID <span class="muted">(the number at the top)</span></label><input type="text" name="app_id" value="<?= pm_h((string)($e0['FB_APP_ID'] ?? '')) ?>" inputmode="numeric"></div>
          <div><label>App Secret <span class="muted">(press Show, then copy)</span></label><input type="password" name="app_secret" placeholder="<?= !empty($e0['FB_APP_SECRET']) ? 'Saved, leave blank to keep' : '' ?>" autocomplete="new-password"></div>
        </div>
      </li>
      <li>
        <h4>Make a token in Graph API Explorer</h4>
        <p><a class="btn small" href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener noreferrer">Open Graph API Explorer &#8599;</a></p>
        <p>Choose your app on the right. Under <b>Permissions</b> add the ones below, press <b>Generate Access Token</b>, and allow it for your Page.</p>
        <ul class="perms"><?php foreach ($needScopes as $scn => $why): ?><li><code><?= pm_h($scn) ?></code> <span class="muted"><?= pm_h($why) ?></span></li><?php endforeach; ?></ul>
        <p class="hint">Step 3 finds no Pages? Your Page is in a Meta business, so also add <code>business_management</code> and generate the token again.</p>
        <details class="more"><summary>Optional: Instagram, ads and reach numbers</summary>
          <ul class="perms"><?php foreach ($extraScopes as $scn => $why): ?><li><code><?= pm_h($scn) ?></code> <span class="muted"><?= pm_h($why) ?></span></li><?php endforeach; ?></ul></details>
      </li>
      <li>
        <h4>Paste the token and connect</h4>
        <label>Token from Graph API Explorer</label><textarea name="user_token" rows="2" autocomplete="off" placeholder="Starts with EAA..."></textarea>
        <div class="btns"><button class="btn primary">Connect</button></div>
        <p class="hint">The app makes the token permanent and lists your Pages. You pick the right one next, and it is saved for you (no .env editing).</p>
      </li>
    </ol>
    </form>
  </details>
  <?php endif; ?>
</div>

<?php $gi = pm_social_gd_info(); $igT = (array)(pm_social_state()['ig_url_test'][$vb] ?? []); $hb = pm_social_heartbeat_age(); ?>
<div class="card">
  <h2>Pictures, Instagram and the scheduler</h2>
  <p><?= pm_social_gd_ok() ? '<span class="pill hot">Pictures can be drawn</span>' : '<span class="pill warn">Pictures cannot be drawn</span>' ?>
    GD <?= $gi['gd'] ? 'on' : '<b>missing</b>' ?> · FreeType <?= $gi['freetype'] ? 'on' : '<b>missing</b>' ?> · fonts: <?= $gi['bundled'] ? 'bundled with the app' : ($gi['serif'] !== '' && $gi['sans'] !== '' ? 'from this computer (' . pm_h(basename($gi['serif'])) . ', ' . pm_h(basename($gi['sans'])) . ')' : '<b>none found</b>') ?></p>
  <?php if (!pm_social_gd_ok()): ?><p class="hint warnt">Posts with a picture wait as "Needs an edit" instead of going out as plain text. Fix: enable the GD extension with FreeType in php.ini, and make sure assets/fonts/serif.ttf and sans.ttf exist (or a system font such as DejaVu is installed).</p><?php endif; ?>
  <form method="post" class="btns"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_x"><input type="hidden" name="do" value="ig_url_test">
    <button class="btn small"<?= pm_app_url() === '' ? ' disabled title="Set APP_URL in .env first"' : '' ?>>Test Instagram image URL</button>
    <span class="hint"><?= pm_app_url() === '' ? 'APP_URL is empty in .env: Instagram cannot fetch pictures until the app is online.' : (empty($igT) ? 'Instagram downloads each picture from ' . pm_h(pm_app_url()) . '/media.php. Press to check it can be reached from outside.' : (!empty($igT['ok']) ? 'OK: ' : 'Problem: ') . pm_h((string)$igT['msg']) . ' · tested ' . date('j M H:i', (int)$igT['at'])) ?></span></form>
  <p class="hint">Scheduler: <?= $hb === null ? '<b>has not run yet</b>' : 'last ran ' . (intdiv($hb, 60) >= 120 ? intdiv($hb, 3600) . ' h' : intdiv($hb, 60) . ' min') . ' ago' ?>. Posts go out when it runs (every 15 to 30 minutes) or when the app is open. On a website: add a cPanel cron line, see README: SOCIAL.</p>
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
        <?php $envHint = $p['env'] ? '<p class="hint"><b>.env lines:</b> <code>' . pm_h(implode('=…  ', array_map(fn($x) => $pre . $x, $p['env']))) . '=…</code></p>' : ''; ?>
        <?php if (empty($p['manual'])) { echo $envHint; } ?>
        <ol class="steps"><?php foreach ($p['steps'] as $stp): ?><li><?= $stepHtml($stp) ?></li><?php endforeach; ?></ol>
        <?php if (!empty($p['manual'])): ?><details class="more"><summary>Connecting by hand (only if the Connect box does not work)</summary>
          <ol class="steps"><?php foreach ($p['manual'] as $stp): ?><li><?= $stepHtml($stp) ?></li><?php endforeach; ?></ol><?= $envHint ?></details><?php endif; ?>
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
        <select name="proof[<?= $i ?>][type]" aria-label="Type"><?php foreach (['quote' => 'Quote', 'result' => 'Result', 'photo' => 'Photo or story', 'offer' => 'Offer', 'stay' => 'Stay (host we may feature)'] as $k => $l): ?><option value="<?= $k ?>" <?= ($r['type'] ?? 'quote') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <textarea name="proof[<?= $i ?>][text]" rows="2" placeholder="Exact words (quote, result or offer)" aria-label="Text"><?= pm_h((string)($r['text'] ?? '')) ?></textarea>
      </div>
      <div>
        <input type="text" name="proof[<?= $i ?>][client_name]" value="<?= pm_h((string)($r['client_name'] ?? '')) ?>" placeholder="Client name" aria-label="Client name">
        <input type="text" name="proof[<?= $i ?>][consent_note]" value="<?= pm_h((string)($r['consent_note'] ?? '')) ?>" placeholder="How consent was given" aria-label="Consent note">
        <input type="date" name="proof[<?= $i ?>][expires]" value="<?= pm_h((string)($r['expires'] ?? '')) ?>" aria-label="Ends on">
        <input type="text" name="proof[<?= $i ?>][fb_url]" value="<?= pm_h((string)($r['fb_url'] ?? '')) ?>" placeholder="Their Facebook page link" aria-label="Facebook page link">
        <input type="text" name="proof[<?= $i ?>][ig_handle]" value="<?= pm_h((string)($r['ig_handle'] ?? '')) ?>" placeholder="Instagram handle (no @)" aria-label="Instagram handle">
        <label class="check"><input type="checkbox" name="proof[<?= $i ?>][tag_ok]" value="1" <?= !empty($r['tag_ok']) ? 'checked' : '' ?>> They are happy to be tagged</label>
        <input type="hidden" name="proof[<?= $i ?>][host_id]" value="<?= pm_h((string)($r['host_id'] ?? '')) ?>">
        <?php if (!empty($r['consent_at'])): ?><span class="hint">Consent recorded <?= pm_h((string)$r['consent_at']) ?></span><?php endif; ?>
        <label class="check"><input type="checkbox" name="proof[<?= $i ?>][consent]" value="1" <?= !empty($r['consent']) ? 'checked' : '' ?>> They agreed to be featured</label>
        <?php if (!empty($r['id'])): ?><label class="check"><input type="checkbox" name="proof[<?= $i ?>][delete]" value="1"> Delete</label><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="btns"><button class="btn small primary" name="do" value="proof_save">Save proof bank</button></div>
  </form>
</div>
<?= pm_social_panels('accounts', $vb) ?>
