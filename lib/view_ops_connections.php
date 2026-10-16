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
  <?php $usd1 = pm_usage_usd(1); $usd30 = pm_usage_usd(30); ?>
  <p class="hint mt-0">Shared by every business. <b>Spent:</b> about <?= pm_usd_fmt($usd1['total']) ?> today, <?= pm_usd_fmt($usd30['total']) ?> in 30 days<?= $usd30['by_brand'] && count($usd30['by_brand']) > 1 ? ' (' . pm_h(implode(', ', array_map(fn($b, $v) => pm_brand_name((string)$b) . ' ' . pm_usd_fmt($v), array_keys($usd30['by_brand']), $usd30['by_brand']))) . ')' : '' ?>; estimates from list prices.
    <b>Today:</b> <?= number_format($us['total']) ?> tokens in <?= (int)$us['calls'] ?> calls<?= pm_budget() ? ' of a ' . number_format(pm_budget()) . ' budget' : '' ?><?= $us['agents'] ? ' (' . pm_h(implode(', ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ' ' . number_format($v), array_keys(array_slice($us['agents'], 0, 6, true)), array_slice($us['agents'], 0, 6, true)))) . ')' : '' ?>.
    Searching with <?= pm_provider(true) === 'gemini' ? 'Gemini' : 'Claude' ?>, writing with <?= pm_provider(false) === 'gemini' ? 'Gemini' : 'Claude' ?>.
    <?php $cst = pm_load('ai_cache_stats', fn() => []); $cHit = (int)($cst[date('Y-m-d')]['hits'] ?? 0); $cWeek = array_sum(array_map(fn($r) => (int)($r['tokens'] ?? 0), array_slice($cst, -7, null, true))); ?>
    <?php if ($cHit || $cWeek): ?><b>Answered from memory:</b> <?= $cHit ?> repeat question<?= $cHit === 1 ? '' : 's' ?> today, about <?= number_format($cWeek) ?> tokens saved in 7 days.<?php endif; ?></p>
  <div class="row">
    <div><label>Quality</label><select name="cfg[quality]"><option value="economy" <?= $acfg['quality'] === 'economy' ? 'selected' : '' ?>>Economy (cheapest)</option><option value="balanced" <?= $acfg['quality'] === 'balanced' ? 'selected' : '' ?>>Balanced (a stronger model for research and proposals)</option></select></div>
    <div><label>Gemini model</label><select name="cfg[gemini_model]"><option value="economy" <?= $acfg['gemini_model'] === 'economy' ? 'selected' : '' ?>>Economy (recommended)</option><option value="auto" <?= $acfg['gemini_model'] === 'auto' ? 'selected' : '' ?>>Spread across the newest three</option>
      <?php foreach (pm_gemini_top_models() as $gm): ?><option value="<?= pm_h($gm) ?>" <?= $acfg['gemini_model'] === $gm ? 'selected' : '' ?>><?= pm_h($gm) ?></option><?php endforeach; ?></select></div>
    <div><label>Daily budget (tokens; 0 = no limit)</label><input type="number" name="cfg[daily_token_budget]" value="<?= (int)$acfg['daily_token_budget'] ?>"></div>
    <div><label>Daily spend limit (US$; 0 = no limit)</label><input type="number" step="0.05" min="0" name="cfg[daily_usd_budget]" value="<?= pm_h((string)($acfg['daily_usd_budget'] ?? 1)) ?>"></div>
    <div><label>Agents at once</label><input type="number" name="cfg[parallel]" value="<?= (int)$acfg['parallel'] ?>" min="1" max="6"></div>
  </div>
  <div class="btns"><button class="btn primary">Save the AI settings</button>
    <button class="btn" name="do" value="refresh_models" title="Look up the newest Gemini models">Refresh the model list</button></div>
</form>
<?php
$fbHid = '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="fbm"><input type="hidden" name="view" value="accounts">';
$fbAppOk = preg_replace('/\D/', '', (string)($e0['FB_APP_ID'] ?? '')) !== '' && trim((string)($e0['FB_APP_SECRET'] ?? '')) !== '';
$fbState = (string)($_GET['fbpick'] ?? '');
$fbBack = $fbState !== '' ? pm_fbo_get($fbState) : null;
$fbSaved = $fbAppOk && !$fbBack ? pm_fbo_saved_pages() : [];
$fbHealthy = $tinfo && !empty($tinfo['ok']) && $tinfo['type'] === 'PAGE' && !$tinfo['missing'];
$fbRedirect = pm_fbo_redirect_uri();
$fbList = function (array $pages, string $do, string $extra) use ($fbHid, $vb, $bname, $fb): string { // the Pages, the one named like the business first and ticked
    usort($pages, fn($a, $b) => pm_fbo_name_match($b['name'], $vb) <=> pm_fbo_name_match($a['name'], $vb));
    $h = '<form method="post" class="fbpick">' . $fbHid . '<input type="hidden" name="do" value="' . $do . '">' . $extra . '<p class="mt-0"><b>Which Page is ' . pm_h($bname) . '?</b></p>';
    foreach ($pages as $i => $pg) {
        $on = $fb['page_id'] === $pg['id'];
        $h .= '<label class="check fbpage"><input type="radio" name="page" value="' . pm_h($pg['id']) . '"' . ($i === 0 ? ' checked' : '') . '> <b>' . pm_h($pg['name']) . '</b>' . ($on ? ' <span class="pill hot">connected now</span>' : '') . '</label>';
    }
    return $h . '<div class="btns"><button class="btn primary">Use this Page for ' . pm_h($bname) . '</button></div></form>';
};
?>
<div class="card" id="facebook">
  <div class="fb-head">
    <div><h2 class="mt-0 mb-0">Facebook and Instagram</h2>
      <p class="hint mt-0 mb-0"><?= $fbHealthy ? '<span class="pill hot">Connected</span> ' . pm_h($bname) . '\'s Page' . ($fb['ig_id'] !== '' ? ' and its Instagram account are' : ' is') . ' connected, with a token that does not expire.' : ($tinfo ? '<span class="pill warn">Needs attention</span> ' . (empty($tinfo['ok']) ? pm_h((string)$tinfo['error']) : ($tinfo['missing'] ? 'Facebook has not given: ' . pm_h(implode(', ', $tinfo['missing'])) . '.' : 'The saved token is not a Page token.')) . ' Press Continue with Facebook to fix it.' : 'Not connected yet: one press, then choose the Page.') ?></p></div>
    <?php if ($fbAppOk): ?><form method="post"><?= $fbHid ?><input type="hidden" name="do" value="oauth_start"><button class="btn primary fb-go"><span aria-hidden="true">f</span> <?= $fb['ready'] ? 'Reconnect with Facebook' : 'Continue with Facebook' ?></button></form><?php endif; ?>
  </div>

  <?php if ($fbState !== '' && !$fbBack): ?><p class="flash warn">That Facebook login has expired (30 minutes). Press Continue with Facebook again.</p>
  <?php elseif ($fbBack && $fbBack['error'] !== ''): ?><p class="flash warn"><?= pm_h((string)$fbBack['error']) ?></p>
  <?php elseif ($fbBack && $fbBack['pages']): ?><?= $fbList((array)$fbBack['pages'], 'connect_state', '<input type="hidden" name="state" value="' . pm_h($fbState) . '">') ?>
  <?php elseif ($fbSaved && !$fbHealthy): ?><p class="hint">From your last Facebook login, no need to log in again:</p><?= $fbList($fbSaved, 'connect_saved', '') ?>
  <?php elseif (!empty($_SESSION['fb_pages'])): ?>
    <form method="post"><?= $fbHid ?><input type="hidden" name="do" value="connect_pick">
      <label>Which Page is <?= pm_h($bname) ?>?</label>
      <?php foreach ($_SESSION['fb_pages'] as $pgx): ?><label class="check"><input type="radio" name="page" value="<?= pm_h($pgx['id']) ?>"> <?= pm_h($pgx['name']) ?> <span class="muted">(<?= pm_h($pgx['id']) ?>)</span></label><?php endforeach; ?>
      <div class="btns"><button class="btn primary">Use this Page</button></div></form>
  <?php endif; ?>

  <?php if ($fb['ready']): ?><div class="btns"><form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_check"><input type="hidden" name="brand" value="<?= pm_h($vb) ?>"><button class="btn small">Check the connection now</button></form></div><?php endif; ?>
  <?php $gg = pm_graph_guard(); ?><p class="hint">Facebook calls today: <?= (int)$gg['calls'] ?> (<?= (int)$gg['cached'] ?> answered from memory instead) · limit used: <?= (int)$gg['pct'] ?>%<?= (int)$gg['until'] > time() ? ' · <b>paused by Facebook until ' . date('H:i', (int)$gg['until']) . '</b>' : '' ?>.</p>

  <details class="fbconnect" <?= !$fbAppOk ? 'open' : '' ?>><summary><b>First time only:</b> your Facebook app <?= $fbAppOk ? '<span class="pill hot">set</span>' : '<span class="pill warn">needed once</span>' ?></summary>
    <ol class="fbsteps">
      <li>
        <h4>Copy two things from your Facebook app</h4>
        <p><a class="btn small" href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer">Open Facebook apps &#8599;</a> Click your app (no app yet? <b>Create app</b>, name it "<?= pm_h($bname) ?> Manager"), open <b>App settings &gt; Basic</b> and copy:</p>
        <form method="post"><?= $fbHid ?><input type="hidden" name="do" value="app_save">
          <div class="row">
            <div><label>App ID <span class="muted">(the number at the top)</span></label><input type="text" name="app_id" value="<?= pm_h((string)($e0['FB_APP_ID'] ?? '')) ?>" inputmode="numeric"></div>
            <div><label>App Secret <span class="muted">(press Show, then copy)</span></label><input type="password" name="app_secret" placeholder="<?= !empty($e0['FB_APP_SECRET']) ? 'Saved, leave blank to keep' : '' ?>" autocomplete="new-password"></div>
          </div>
          <div class="btns"><button class="btn small primary">Save</button></div>
        </form>
      </li>
      <li>
        <h4>Let Facebook send you back to this app</h4>
        <p>In the same app: <b>Add product</b> (or Use cases) &gt; <b>Facebook Login</b> &gt; <b>Settings</b>. Under <b>Valid OAuth Redirect URIs</b> paste this address and save:</p>
        <div class="fb-uri"><code id="fburi"><?= pm_h($fbRedirect) ?></code><button type="button" class="btn small" data-copy-target="#fburi">Copy</button></div>
        <p class="hint"><?= str_starts_with($fbRedirect, 'http://localhost') ? 'This is the address on this computer: Facebook allows it while the app is in development. On the website, open this page there and paste that address too.' : 'Add the address on this computer too if you also run the app there.' ?>
          Your app shows <b>Facebook Login for Business</b> instead? Make a configuration with the Page permissions and paste its ID here: </p>
        <form method="post" class="inl"><?= $fbHid ?><input type="hidden" name="do" value="app_save"><input type="text" name="config_id" value="<?= pm_h((string)($e0['FB_LOGIN_CONFIG_ID'] ?? '')) ?>" placeholder="Configuration ID (only for Facebook Login for Business)" style="max-width:360px"><button class="btn small">Save</button></form>
      </li>
      <li><h4>Press Continue with Facebook</h4><p class="hint mb-0">Log in, tick your Pages (and Instagram) and allow. Back here, choose the Page: done. The login is kept, so your other businesses connect with one press.</p></li>
    </ol>
  </details>

  <?php $scopes = pm_fb_scopes(); $needScopes = array_diff_key($scopes, array_flip(PM_FB_OPTIONAL_SCOPES)); $extraScopes = array_intersect_key($scopes, array_flip(PM_FB_OPTIONAL_SCOPES)) + ['instagram_content_publish' => 'publish posts to Instagram']; ?>
  <details class="more"><summary>Another way: paste a token from Graph API Explorer</summary>
    <form method="post"><?= $fbHid ?><input type="hidden" name="do" value="connect"><input type="hidden" name="app_id" value="<?= pm_h((string)($e0['FB_APP_ID'] ?? '')) ?>">
      <p><a class="btn small" href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener noreferrer">Open Graph API Explorer &#8599;</a> Choose your app, add these permissions, press <b>Generate Access Token</b> and allow it for your Page:</p>
      <ul class="perms"><?php foreach ($needScopes as $scn => $why): ?><li><code><?= pm_h($scn) ?></code> <span class="muted"><?= pm_h($why) ?></span></li><?php endforeach; ?><li><code>business_management</code> <span class="muted">when the Page is in a Meta business</span></li></ul>
      <details class="more"><summary>Optional: Instagram, ads and reach numbers</summary><ul class="perms"><?php foreach ($extraScopes as $scn => $why): ?><li><code><?= pm_h($scn) ?></code> <span class="muted"><?= pm_h($why) ?></span></li><?php endforeach; ?></ul></details>
      <label>Token from Graph API Explorer</label><textarea name="user_token" rows="2" autocomplete="off" placeholder="Starts with EAA..."></textarea>
      <div class="btns"><button class="btn">Connect with this token</button></div>
    </form>
  </details>
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
