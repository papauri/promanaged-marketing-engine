<?php
/**
 * Operations > Connections: what the app is plugged into. The Facebook connection, every platform's profile link and setup steps, the picture engine, Instagram's picture address, the scheduler
 * and the website enquiry form. (The pictures and covers themselves are Brand > Brand kit; what to post and where is Social.) Included by view_settings.php.
 */
pm_brand_set($vb);
$cfg = pm_social_settings($vb);
$fb = pm_social_cfg($vb);
$li = pm_linkedin_cfg($vb);
$pre = pm_brand_env_prefix($vb);
$status = function (string $k) use ($fb, $li, $cfg): array {
    $hasUrl = trim((string)($cfg['urls'][$k] ?? '')) !== '';
    return match ($k) {
        'facebook' => $fb['ready'] ? ['Connected', 'hot'] : ($hasUrl ? ['Page added, app not connected', 'warn'] : ['Not set up', '']),
        'instagram' => $fb['ig_id'] !== '' ? ['Linked', 'hot'] : ($hasUrl ? ['Account added, not linked', 'warn'] : ['Not set up', '']),
        'linkedin' => $li['ready'] ? ['Connected', 'hot'] : ($hasUrl ? ['Page added', 'warn'] : ['Not set up', '']),
        default => $hasUrl ? ['Added', 'hot'] : ['Not set up', ''],
    };
};
$stepHtml = fn(string $t): string => preg_match('/^([^:]{3,48}):\s(.+)$/su', $t, $m) ? '<b>' . pm_h($m[1]) . ':</b> ' . pm_h($m[2]) : pm_h($t); // bold the lead-in so the steps scan quickly
$tinfo = $fb['ready'] ? pm_fb_token_info($fb['token']) : null;
$e0 = pm_env();
?>
<?php
$acfg = pm_agents_config($vb);
$us = pm_usage_today();
arsort($us['agents']);
$aiHid = '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="config"><input type="hidden" name="bt" value="ops"><input type="hidden" name="cfg[_group]" value="ai">';
?>
<h3><?= pm_h($bname) ?></h3>
<form method="post" class="card" id="ai"><?= $aiHid ?>
  <h2>The AI that writes and searches</h2>
  <p class="hint mt-0">Shared by every business. <b>Today:</b> <?= number_format($us['total']) ?> tokens in <?= (int)$us['calls'] ?> calls<?= pm_budget() ? ' of a ' . number_format(pm_budget()) . ' budget' : '' ?><?= $us['agents'] ? ' (' . pm_h(implode(', ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ' ' . number_format($v), array_keys(array_slice($us['agents'], 0, 6, true)), array_slice($us['agents'], 0, 6, true)))) . ')' : '' ?>.
    Searching with <?= pm_provider(true) === 'gemini' ? 'Gemini' : 'Claude' ?>, writing with <?= pm_provider(false) === 'gemini' ? 'Gemini' : 'Claude' ?>.
    <?php $cst = pm_load('ai_cache_stats', fn() => []); $cHit = (int)($cst[date('Y-m-d')]['hits'] ?? 0); $cWeek = array_sum(array_map(fn($r) => (int)($r['tokens'] ?? 0), array_slice($cst, -7, null, true))); ?>
    <?php if ($cHit || $cWeek): ?><b>Answered from memory:</b> <?= $cHit ?> repeat question<?= $cHit === 1 ? '' : 's' ?> today, about <?= number_format($cWeek) ?> tokens saved in 7 days.<?php endif; ?></p>
  <div class="row">
    <div><label>Quality</label><select name="cfg[quality]"><option value="economy" <?= $acfg['quality'] === 'economy' ? 'selected' : '' ?>>Economy (cheapest)</option><option value="balanced" <?= $acfg['quality'] === 'balanced' ? 'selected' : '' ?>>Balanced (a stronger model for research and proposals)</option></select></div>
    <div><label>Gemini model</label><select name="cfg[gemini_model]"><option value="economy" <?= $acfg['gemini_model'] === 'economy' ? 'selected' : '' ?>>Economy (recommended)</option><option value="auto" <?= $acfg['gemini_model'] === 'auto' ? 'selected' : '' ?>>Spread across the newest three</option>
      <?php foreach (pm_gemini_top_models() as $gm): ?><option value="<?= pm_h($gm) ?>" <?= $acfg['gemini_model'] === $gm ? 'selected' : '' ?>><?= pm_h($gm) ?></option><?php endforeach; ?></select></div>
    <div><label>Daily budget (tokens; 0 = no limit)</label><input type="number" name="cfg[daily_token_budget]" value="<?= (int)$acfg['daily_token_budget'] ?>"></div>
    <div><label>Agents at once</label><input type="number" name="cfg[parallel]" value="<?= (int)$acfg['parallel'] ?>" min="1" max="6"></div>
  </div>
  <div class="btns"><button class="btn primary">Save the AI settings</button>
    <button class="btn" name="do" value="refresh_models" title="Look up the newest Gemini models">Refresh the model list</button></div>
</form>
<div class="card">
  <h2>Facebook connection</h2>
  <?php if ($tinfo): ?>
    <p><?= !empty($tinfo['ok']) && $tinfo['type'] === 'PAGE' && !$tinfo['missing'] ? '<span class="pill hot">Healthy</span> Permanent Page token with every permission the app needs.'
        : '<span class="pill warn">Needs attention</span> ' . (empty($tinfo['ok']) ? pm_h($tinfo['error']) : ($tinfo['type'] !== 'PAGE' ? 'The saved token is a personal (user) token' . ($tinfo['expires'] ? ' that expires ' . date('j M H:i', $tinfo['expires']) : '') . ', not a Page token.' : '') . ($tinfo['missing'] ? ' Missing: ' . pm_h(implode(', ', $tinfo['missing'])) . '.' : '')) ?></p>
  <?php else: ?><p class="hint mt-0">Not connected yet.</p><?php endif; ?>
  <?php $gg = pm_graph_guard(); ?><p class="hint">Facebook calls today: <?= (int)$gg['calls'] ?> (<?= (int)$gg['cached'] ?> answered from memory instead) · Facebook limit used: <?= (int)$gg['pct'] ?>%<?= (int)$gg['until'] > time() ? ' · <b>paused by Facebook until ' . date('H:i', (int)$gg['until']) . '</b>' : '' ?>. Above 75% the app shows the last known data instead of calling.</p>
  <?php if ($fb['ready']): ?><form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_check"><input type="hidden" name="brand" value="<?= pm_h($vb) ?>"><button class="btn small">Check the connection now</button></form><?php endif; ?>
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
  <p class="hint">Scheduler: <?= $hb === null ? '<b>has not run yet</b>' : 'last ran ' . (intdiv($hb, 60) >= 120 ? intdiv($hb, 3600) . ' h' : intdiv($hb, 60) . ' min') . ' ago' ?>. Posts go out when it runs (every 15 to 30 minutes) or when the app is open. On a website: add a cPanel cron line, see README: Scheduling on a server.</p>
</div>

<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_accounts"><input type="hidden" name="_sec" value="links">
  <h3>Every platform</h3>
  <p class="hint mt-0">Where the business is on each platform, and how to connect it. What gets posted where is chosen under <a href="?tab=social">Social &gt; Plan</a>; the pictures are made in <a href="?tab=social&amp;view=kit">Brand &gt; Brand kit</a>.</p>
  <?php foreach (pm_platforms() as $k => $p): [$st, $cls] = $status($k); ?>
  <details class="card plat">
    <summary><b><?= pm_h($p['name']) ?></b> <span class="pill <?= $cls ?>"><?= pm_h($st) ?></span></summary>
    <label>Profile link</label><input type="text" name="url[<?= $k ?>]" value="<?= pm_h((string)($cfg['urls'][$k] ?? '')) ?>" placeholder="https://...">
    <p class="hint"><b>What the app does here:</b> <?= pm_h($p['auto']) ?></p>
    <?php $envHint = $p['env'] ? '<p class="hint"><b>.env lines:</b> <code>' . pm_h(implode('=…  ', array_map(fn($x) => $pre . $x, $p['env']))) . '=…</code></p>' : ''; ?>
    <?php if (empty($p['manual'])) { echo $envHint; } ?>
    <details class="more"><summary>Set-up steps</summary><ol class="steps"><?php foreach ($p['steps'] as $stp): ?><li><?= $stepHtml($stp) ?></li><?php endforeach; ?></ol></details>
    <?php if (!empty($p['manual'])): ?><details class="more"><summary>Connecting by hand (only if the Connect box does not work)</summary>
      <ol class="steps"><?php foreach ($p['manual'] as $stp): ?><li><?= $stepHtml($stp) ?></li><?php endforeach; ?></ol><?= $envHint ?></details><?php endif; ?>
  </details>
  <?php endforeach; ?>
  <div class="btns formbar"><button class="btn primary">Save the links</button></div>
</form>

<?php require_once __DIR__ . '/view_outbound.php'; pm_brand_set($vb); pm_view_embed_card(); ?>
