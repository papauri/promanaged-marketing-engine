<?php
/**
 * Settings screen. Both businesses use exactly the same three cards (Company, Signing, Email sending), drawn by one function,
 * so they can never drift apart. Currencies and the marketing team are shared and folded away at the bottom.
 */
$brandCards = function (string $b) use ($settings, $vb): void {
    $tr = $b === 'travel';
    $c = $tr ? (array)$settings['travel'] : $settings;                  // this business's values
    $n = fn(string $k) => $tr ? "s[travel][$k]" : "s[$k]";             // its field names
    $sm = $tr ? (array)($c['smtp'] ?? []) : (array)$settings['smtp'];
    $env = $tr ? pm_tm_smtp_from_env() : pm_smtp_from_env();
    $e = pm_env();
    if ($env && $tr) {
        $sm = array_merge($sm, ['host' => $e['TM_SMTP_HOST'] ?? '', 'port' => $e['TM_SMTP_PORT'] ?? 465, 'encryption' => strtolower($e['TM_SMTP_SECURE'] ?? 'ssl'),
            'username' => $e['TM_SMTP_USER'] ?? '', 'from_email' => $e['TM_SMTP_FROM'] ?? '', 'from_name' => $e['TM_SMTP_FROM_NAME'] ?? ($sm['from_name'] ?? '')]);
    }
    $name = $tr ? $c['company_name'] : 'ProManaged IT';
    $logo = $tr ? 'assets/travel_logo.png' : 'assets/logo.png';
    $sig = $tr ? 'assets/travel_signature.png' : 'assets/signature.png';
    $hide = $vb === $b ? '' : 'hidden';
    $lp = pm_link_preview($b);
    ?>
    <div class="card" <?= $hide ?>><h2><?= pm_h($name) ?> · Company</h2>
      <div class="row">
        <div><label>Business name</label><input type="text" name="<?= $n('company_name') ?>" value="<?= pm_h($c['company_name'] ?? '') ?>"></div>
        <div><label>Tagline</label><input type="text" name="<?= $n('tagline') ?>" value="<?= pm_h($c['tagline'] ?? '') ?>"></div>
        <div><label>Phone</label><input type="text" name="<?= $n('phone') ?>" value="<?= pm_h($c['phone'] ?? '') ?>"></div>
        <div><label>Email</label><input type="text" name="<?= $n('email') ?>" value="<?= pm_h($c['email'] ?? '') ?>"></div>
        <div><label>Website</label><input type="text" name="<?= $n('website') ?>" value="<?= pm_h($c['website'] ?? '') ?>"></div>
        <div><label>Address</label><input type="text" name="<?= $n('address') ?>" value="<?= pm_h($c['address'] ?? '') ?>"></div>
        <div><label>Who signs for you</label><input type="text" name="<?= $n('signatory_name') ?>" value="<?= pm_h($c['signatory_name'] ?? '') ?>"></div>
        <div><label>Their title</label><input type="text" name="<?= $n('signatory_title') ?>" value="<?= pm_h($c['signatory_title'] ?? '') ?>"></div>
        <div><label>Accent colour</label><input type="text" name="<?= $n('accent_color') ?>" value="<?= pm_h($c['accent_color'] ?? '') ?>"></div>
        <div><label>Reference prefix</label><input type="text" name="<?= $n('ref_prefix') ?>" value="<?= pm_h($c['ref_prefix'] ?? '') ?>"></div>
        <div><label>Next reference number</label><input type="number" name="<?= $n('next_ref') ?>" value="<?= (int)($c['next_ref'] ?? 1) ?>"></div>
      </div>
      <div class="split">
        <div>
          <label>Logo (PNG or JPG)</label>
          <?php if (is_file(PM_ROOT . '/' . $logo)): ?><div class="logoprev"><img class="<?= $tr ? 'sq' : '' ?>" src="<?= $logo ?>?v=<?= @filemtime(PM_ROOT . '/' . $logo) ?>" alt="Current logo"><span class="hint">Current logo</span></div><?php endif; ?>
          <input type="file" name="<?= $tr ? 'travel_logo' : 'logo' ?>" accept="image/png,image/jpeg">
        </div>
        <div class="linkbox">
          <label>Website link in emails and WhatsApp</label>
          <input type="text" name="<?= $n('link_url') ?>" value="<?= pm_h($c['link_url'] ?? '') ?>" placeholder="https://...">
          <label class="check"><input type="checkbox" name="<?= $n('link_on') ?>" value="1" <?= !empty($c['link_on']) ? 'checked' : '' ?>> Add it to messages</label>
          <?php if (($lp['url'] ?? '') !== ''): ?>
          <div class="lpcard"><?php if (!empty($lp['image'])): ?><img src="?thumb=<?= $b ?>&v=<?= urlencode((string)$lp['at']) ?>" alt="Link thumbnail"><?php endif; ?>
            <div><b><?= pm_h($lp['title'] ?: $lp['url']) ?></b><small><?= pm_h((string)parse_url($lp['url'], PHP_URL_HOST)) ?></small></div></div>
          <?php endif; ?>
          <button type="submit" class="btn small" form="linkrefresh-<?= $b ?>">Refresh preview</button>
        </div>
      </div>
      <?php if ($tr): ?>
        <label class="check" style="margin-top:12px"><input type="checkbox" name="s[travel][charging]" value="1" <?= !empty($c['charging']) ? 'checked' : '' ?>> We are charging for onboarding <span class="muted">(off = proposals say Free)</span></label>
      <?php endif; ?>
    </div>

    <div class="card" <?= $hide ?> data-fold="closed"><h2><?= pm_h($name) ?> · Signing</h2>
      <label class="check"><input type="checkbox" name="<?= $n('online_signing') ?>" value="1" <?= !empty($c['online_signing']) ? 'checked' : '' ?>> Let clients accept and sign online</label>
      <label class="check"><input type="checkbox" name="<?= $n('esign_tags') ?>" value="1" <?= !empty($c['esign_tags']) ? 'checked' : '' ?>> Add DocuSign and Adobe Acrobat Sign tags</label>
      <label>Your signature (PNG with a transparent background), shown on every agreement</label>
      <?php if (is_file(PM_ROOT . '/' . $sig)): ?><div class="logoprev"><img src="<?= $sig ?>?v=<?= filemtime(PM_ROOT . '/' . $sig) ?>" alt="Current signature"><label class="check"><input type="checkbox" name="<?= $tr ? 'remove_travel_signature' : 'remove_signature' ?>" value="1"> Remove</label></div><?php endif; ?>
      <input type="file" name="<?= $tr ? 'travel_signature' : 'signature' ?>" accept="image/png,image/jpeg">
      <?php if (pm_app_url() === ''): ?><p class="hint">Online signing links need the app on your website (APP_URL in .env). Until then, PDFs carry a fillable acceptance page.</p><?php endif; ?>
    </div>

    <?php $brn = pm_brain($b); ?>
    <div class="card" <?= $hide ?> data-fold="closed"><h2><?= pm_h($name) ?> · Marketing brain</h2>
      <p class="hint" style="margin-top:0">Everything the AI agents know about this business, and all they may claim. Change these and every agent, email, post and proposal follows. To market a different business, rewrite them.</p>
      <label>Who we are and what we do</label><textarea name="<?= $n('brain') ?>[about]" rows="2"><?= pm_h($brn['about']) ?></textarea>
      <label>Facts the AI may use (one per line; nothing else is ever claimed)</label><textarea name="<?= $n('brain') ?>[facts]" rows="5"><?= pm_h($brn['facts']) ?></textarea>
      <div class="row">
        <div><label>Who we are trying to reach</label><input type="text" name="<?= $n('brain') ?>[audience]" value="<?= pm_h($brn['audience']) ?>"></div>
        <div><label>Tone of voice</label><input type="text" name="<?= $n('brain') ?>[voice]" value="<?= pm_h($brn['voice']) ?>"></div>
        <div><label>Never say</label><input type="text" name="<?= $n('brain') ?>[never]" value="<?= pm_h($brn['never']) ?>"></div>
      </div>
    </div>

    <?php $soc = pm_social_cfg($b); $pre = $tr ? 'TM_' : ''; ?>
    <div class="card" <?= $hide ?> data-fold="closed"><h2><?= pm_h($name) ?> · Social media</h2>
      <p class="hint" style="margin-top:0">Facebook: <b><?= $soc['ready'] ? 'connected' : 'not connected' ?></b> · Instagram: <b><?= $soc['ig_id'] !== '' ? 'linked' : 'not linked' ?></b> · LinkedIn: <b><?= pm_linkedin_cfg($b)['ready'] ? 'connected' : 'not connected' ?></b>.
        Setup steps for every platform, profile and cover pictures, and links are in <a href="?tab=social&view=accounts&brand=<?= $b ?>">Social &gt; Accounts &amp; branding</a>.</p>
      <label class="check"><input type="checkbox" name="<?= $n('social_auto') ?>" value="1" <?= !empty($c['social_auto']) ? 'checked' : '' ?>> Auto-publish: posts the AI plans go out at their time without my approval</label>
      <div class="btns"><button class="btn small" form="socialcheck-<?= $b ?>">Check the Facebook connection</button></div>
    </div>

    <div class="card" <?= $hide ?> data-fold="closed"><h2><?= pm_h($name) ?> · Email sending</h2>
      <?php if ($env): ?><p class="hint" style="margin-top:0">Read-only here: these come from the .env file (<?= $tr ? 'TM_' : '' ?>SMTP_*).</p>
      <?php elseif ($tr && pm_mail_source('travel') === 'shared'): ?><p class="hint warnt" style="margin-top:0">Travel Malawi has no mail login of its own, so it sends through ProManaged IT's mailbox. Add TM_SMTP_HOST, TM_SMTP_USER and TM_SMTP_PASS to .env (or fill in the boxes below) to send from its own address.</p><?php endif; ?>
      <fieldset <?= $env ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
      <div class="row">
        <div><label>Mail server (SMTP host)</label><input type="text" name="<?= $n('smtp') ?>[host]" value="<?= pm_h((string)($sm['host'] ?? '')) ?>"></div>
        <div><label>Port</label><input type="number" name="<?= $n('smtp') ?>[port]" value="<?= (int)($sm['port'] ?? 465) ?>"></div>
        <div><label>Security</label><select name="<?= $n('smtp') ?>[encryption]"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($sm['encryption'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div><label>Username</label><input type="text" name="<?= $n('smtp') ?>[username]" value="<?= pm_h((string)($sm['username'] ?? '')) ?>" autocomplete="off"></div>
        <div><label>Password</label><input type="password" name="<?= $n('smtp') ?>[password]" value="" placeholder="<?= ($env || ($sm['password'] ?? '') !== '') ? 'Saved, leave blank to keep' : '' ?>" autocomplete="new-password"></div>
        <div><label>Send from (email)</label><input type="text" name="<?= $n('smtp') ?>[from_email]" value="<?= pm_h((string)($sm['from_email'] ?? '')) ?>"></div>
      </div>
      </fieldset>
      <div class="row">
        <div><label>Send from (name)</label><input type="text" name="<?= $n('smtp') ?>[from_name]" value="<?= pm_h((string)($sm['from_name'] ?? $name)) ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="<?= $n('smtp') ?>[bcc_self]" value="1" <?= !empty($sm['bcc_self']) ? 'checked' : '' ?>> Send me a copy of every email</label>
      <div class="btns"><button class="btn small" form="smtpcheck-<?= $b ?>">Check the mail login</button></div>
    </div>
    <?php
};
?>
  <?= pm_ui_head('Settings', 'Details, mailbox and voice for each business, and how the whole app is set up.', '<a class="btn primary" href="?tab=business&amp;new=1">+ Add a business</a>') ?>
  <?php pm_view_biz_grid($settings, $vb, $csrf); ?>
  <h3><?= pm_h(pm_brand_title($settings, $vb)) ?></h3>
  <?php $isCustom = pm_brand_is_custom($vb); if ($isCustom) { require __DIR__ . '/view_brand_settings.php'; } ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="settings">
    <?php $brandCards('promanaged'); $brandCards('travel'); ?>

    <details class="card shared" id="team"><summary>Team and currencies, shared by every business: <?= count((array)($settings['team'] ?? [])) ?> on the team, <?= count($settings['currencies']) ?> currencies</summary>
      <h3>Currencies</h3>
      <p class="hint">Prices are in <b><?= pm_h($settings['currency']) ?></b>. Another currency is one pick on a proposal.</p>
      <table class="grid compact" data-next="<?= count($settings['currencies']) ?>"><thead><tr><th>Code</th><th>Name</th><th><?= pm_h($settings['currency']) ?> per 1</th><th>Round to</th><th></th></tr></thead><tbody>
      <?php foreach ($settings['currencies'] as $i => $c): ?>
        <tr><td><input type="text" name="s[currencies][<?= $i ?>][code]" value="<?= pm_h($c['code']) ?>" maxlength="3"></td><td><input type="text" name="s[currencies][<?= $i ?>][name]" value="<?= pm_h($c['name']) ?>"></td>
          <td><input type="number" step="any" name="s[currencies][<?= $i ?>][mpu]" value="<?= pm_h((string)round(1 / max(0.0000001, (float)$c['rate']), 4)) ?>" <?= $c['code'] === $settings['currency'] ? 'readonly' : '' ?>></td>
          <td><input type="number" step="any" name="s[currencies][<?= $i ?>][round]" value="<?= pm_h((string)$c['round']) ?>"></td>
          <td><button type="button" class="icon" onclick="this.closest('tr').remove()" title="Remove">&times;</button></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <template><tr><td><input type="text" name="s[currencies][__i__][code]" maxlength="3"></td><td><input type="text" name="s[currencies][__i__][name]"></td><td><input type="number" step="any" name="s[currencies][__i__][mpu]"></td><td><input type="number" step="any" name="s[currencies][__i__][round]" value="1"></td><td><button type="button" class="icon" onclick="this.closest('tr').remove()" title="Remove">&times;</button></td></tr></template>
      <button type="button" class="link" onclick="addRow(this)">+ Add currency</button>
      <div class="row" style="margin-top:10px">
        <div><label>Base currency</label><input type="text" name="s[currency]" value="<?= pm_h($settings['currency']) ?>"></div>
        <div><label>Quote in by default</label><select name="s[default_quote_currency]"><?php foreach ($settings['currencies'] as $c): ?><option value="<?= pm_h($c['code']) ?>" <?= $c['code'] === ($settings['default_quote_currency'] ?? $settings['currency']) ? 'selected' : '' ?>><?= pm_h($c['code']) ?></option><?php endforeach; ?></select></div>
        <div><label>Rates checked on</label><input type="date" name="s[rates_date]" value="<?= pm_h($settings['rates_date'] ?? '') ?>"></div>
      </div>
      <h3>Marketing team</h3>
      <textarea name="s[team]" rows="3" placeholder="One name per line"><?= pm_h(implode("\n", (array)($settings['team'] ?? []))) ?></textarea>
      <p class="hint">Names appear in the "Working as" menu: messages are signed with the name, leads get an owner, and each person can filter to "Mine".</p>
    </details>

    <input type="hidden" name="test_brand" value="<?= pm_h($vb) ?>">
    <div class="btns">
      <button class="btn primary"><?= $isCustom ? 'Save the team and currencies' : 'Save settings' ?></button>
      <?php if (!$isCustom): ?><button class="btn" name="test_email" value="1">Save and send a test email</button>
        <input type="email" name="test_to" placeholder="Send the test to (blank: the mailbox itself)" aria-label="Send the test email to" style="width:auto;min-width:260px"><?php endif; ?>
    </div>
  </form>

  <h3>Health and backups</h3>
  <div id="health"><?php function_exists('pm_view_setup_health') && pm_view_setup_health(); // C2-G01: what is connected and what to add ?></div>
  <div id="backups"><?php function_exists('pm_view_archives') && pm_view_archives(); // C3-G01: weekly backups, download and restore ?></div>

  <?php if (is_file(__DIR__ . '/view_outbound.php')): require_once __DIR__ . '/view_outbound.php'; pm_brand_set($vb); // sending health and the public enquiry form, for the business shown above ?>
    <?php function_exists('pm_view_email_health') && pm_view_email_health(); ?>
    <?php function_exists('pm_view_embed_card') && pm_view_embed_card(); ?>
  <?php endif; ?>
  <p class="hint">Alerts (a reply, a bounce, a proposal opened) are emailed to the Email address under Company, from the mail login above, at most once per subject every 6 hours. Run <code>php lib/poll_run.php</code> every 15 minutes (schedule_agents.bat does this) to check replies without opening the app.</p>

  <?php foreach (array_reverse(pm_brand_ids()) as $bk): ?>
  <form method="post" id="linkrefresh-<?= $bk ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="link_refresh"><input type="hidden" name="brand" value="<?= $bk ?>"></form>
  <form method="post" id="socialcheck-<?= $bk ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="social_check"><input type="hidden" name="brand" value="<?= $bk ?>"></form>
  <form method="post" id="smtpcheck-<?= $bk ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="smtp_check"><input type="hidden" name="brand" value="<?= $bk ?>"></form>
  <?php endforeach; ?>
