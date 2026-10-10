<?php
/**
 * Settings for a business that was added in the app (not ProManaged IT or Travel Malawi, which have their own cards on the Settings screen).
 * Included by view_settings.php with $vb (the business id), $settings and $csrf in scope. One form, saved by action=brand_save in index.php.
 */
$b = $vb;
$c = (array)$settings['brands'][$b];
$prof = pm_brand_profile($b);
$brn = pm_brain($b);
$acfg = pm_agents_config($b);
$sm = (array)($c['smtp'] ?? []);
$im = (array)($c['imap'] ?? []);
$pre = pm_brand_env_prefix($b);
$envMail = ((pm_env()[$pre . 'SMTP_HOST'] ?? '') !== '');
$row = pm_brands_custom()[$b] ?? [];
$logo = pm_brand_asset($b, 'logo');
$name = (string)$c['company_name'];
$tg = pm_brand_targeting($b);
$learned = (array)(((array)($c['profile'] ?? []))['learned'] ?? []);
$fld = fn(string $k, string $label, string $ph = '', string $type = 'text') => '<div><label>' . pm_h($label) . '</label><input type="' . $type . '" name="b[' . $k . ']" value="' . pm_h((string)($c[$k] ?? '')) . '" placeholder="' . pm_h($ph) . '"></div>';
?>
<form method="post" enctype="multipart/form-data" id="brandform">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_save"><input type="hidden" name="id" value="<?= pm_h($b) ?>">

  <div class="card"><h2>Business details</h2>
    <div class="row">
      <?= $fld('company_name', 'Business name') ?>
      <?= $fld('tagline', 'Tagline', 'One line under the name') ?>
      <?= $fld('email', 'Email', 'info@example.com') ?>
      <?= $fld('phone', 'Phone or WhatsApp', '0999 123 456') ?>
      <?= $fld('website', 'Website', 'www.example.com') ?>
      <?= $fld('address', 'Address or town') ?>
      <?= $fld('signatory_name', 'Who signs for the business') ?>
      <?= $fld('signatory_title', 'Their title') ?>
      <div><label>Accent colour</label><input type="text" name="b[accent_color]" value="<?= pm_h((string)$c['accent_color']) ?>" maxlength="7"></div>
      <?= $fld('ref_prefix', 'Reference prefix', 'e.g. SUN') ?>
    </div>
    <label>Logo (PNG or JPG)</label>
    <?php if (is_file(PM_ROOT . '/' . $logo)): ?><div class="logoprev"><img src="<?= pm_h($logo) ?>?v=<?= (int)@filemtime(PM_ROOT . '/' . $logo) ?>" alt="Current logo"><span class="hint">Current logo</span></div><?php endif; ?>
    <input type="file" name="logo" accept="image/png,image/jpeg">
  </div>

  <div class="card"><h2>What the AI knows</h2>
    <p class="hint" style="margin-top:0">Everything the agents and the post writer know about this business, and all they may claim. They use nothing beyond these lines and never invent numbers, prices or customers.</p>
    <label>Who we are and what we do</label><textarea name="b[brain][about]" rows="2"><?= pm_h((string)$brn['about']) ?></textarea>
    <label>True facts the AI may use (one per line)</label><textarea name="b[brain][facts]" rows="5"><?= pm_h((string)$brn['facts']) ?></textarea>
    <div class="row">
      <div><label>Who we are trying to reach</label><input type="text" name="b[brain][audience]" value="<?= pm_h((string)$brn['audience']) ?>"></div>
      <div><label>Tone of voice</label><input type="text" name="b[brain][voice]" value="<?= pm_h((string)$brn['voice']) ?>"></div>
      <div><label>Never say</label><input type="text" name="b[brain][never]" value="<?= pm_h((string)$brn['never']) ?>"></div>
    </div>
  </div>

  <div class="card"><h2>Who the agents look for</h2>
    <label>What the business offers (one per line, "Name: what it is")</label>
    <textarea name="b[offerings]" rows="3"><?= pm_h(implode("\n", (array)$acfg['offerings'])) ?></textarea>
    <div class="row">
      <div><label>Kinds of business or people to look for (comma separated)</label><input type="text" name="b[sectors]" value="<?= pm_h(implode(', ', (array)$acfg['sectors'])) ?>"></div>
      <div><label>Cities to search (comma separated)</label><input type="text" name="b[cities]" value="<?= pm_h(implode(', ', (array)$acfg['cities'])) ?>"></div>
      <div><label>Who buys from you</label><input type="text" name="b[customers]" value="<?= pm_h((string)$prof['customers']) ?>"></div>
    </div>
    <label>Who do you mainly sell to?</label>
    <div class="checks" style="margin-top:2px">
      <?php foreach (['business' => 'Other businesses (agents find and write to them)', 'public' => 'The public (no cold emails; social, enquiries and partners)', 'both' => 'Both'] as $k => $l): ?>
        <label class="check"><input type="radio" name="b[sell_to]" value="<?= $k ?>" <?= $prof['sell_to'] === $k ? 'checked' : '' ?>> <?= pm_h($l) ?></label>
      <?php endforeach; ?>
    </div>
    <label>Existing clients (one per line; they are never cold-pitched)</label>
    <textarea name="b[existing_clients]" rows="2"><?= pm_h(implode("\n", (array)($acfg['existing_clients'] ?? []))) ?></textarea>
    <label class="check"><input type="checkbox" name="b[daily_run]" value="1" <?= !empty($row['daily_run']) ? 'checked' : '' ?>> Run the agents every weekday morning (the scheduler starts them by itself)</label>
    <p class="hint">Limits (emails and WhatsApp messages a day, follow-ups) are on the Leads screen under More &gt; Agent settings. They start low on purpose.</p>
  </div>

  <div class="card"><h2>Who to target, and why</h2>
    <p class="hint" style="margin-top:0">What the AI learned, which the scouts and the qualifier read to pick better leads. It is advice, not a claim about you: change anything. The kinds listed in "Kinds of business or people to look for" above are the ones that get searched.</p>
    <label>Why each kind buys <span class="muted">(one per line: kind | why they buy | a sign they need it; another sign)</span></label>
    <textarea name="b[tg_segments]" rows="5" placeholder="schools | They lose lessons when the power fails | runs on a diesel generator; has a computer lab"><?= pm_h(pm_study_segments_text($tg['segments'])) ?></textarea>
    <div class="row">
      <div><label>Not a fit (one per line; the agents skip them)</label><textarea name="b[tg_skip]" rows="3" placeholder="international chains"><?= pm_h(implode("\n", $tg['skip'])) ?></textarea></div>
      <div><label>What matters to these buyers (one per line; the qualifier leads with them)</label><textarea name="b[tg_angles]" rows="3"><?= pm_h(implode("\n", $tg['angles'])) ?></textarea></div>
    </div>
    <p class="hint"><?= !empty($learned['at']) ? 'Last studied ' . pm_h((string)$learned['at']) . (!empty($learned['from']) ? ' from ' . pm_h(implode(', ', array_map('pm_study_host', (array)$learned['from']))) : ' from your answers') . '.' : 'Not studied by the AI yet.' ?>
      <button class="btn small" form="restudy-<?= pm_h($b) ?>" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?>>Study the business again</button></p>
  </div>

  <div class="card"><h2>Email</h2>
    <?php if ($envMail): ?><p class="hint" style="margin-top:0">The mail login comes from .env (<?= pm_h($pre) ?>SMTP_*), so it is read-only here.</p>
    <?php else: ?><p class="hint" style="margin-top:0">Outreach and replies go from this business's own mailbox. Ask the person who runs the mail for the server details.</p><?php endif; ?>
    <fieldset <?= $envMail ? 'disabled' : '' ?> style="border:0;padding:0;margin:0">
      <div class="row">
        <div><label>Mail server (SMTP host)</label><input type="text" name="b[smtp][host]" value="<?= pm_h((string)($sm['host'] ?? '')) ?>"></div>
        <div><label>Port</label><input type="number" name="b[smtp][port]" value="<?= (int)($sm['port'] ?? 465) ?>"></div>
        <div><label>Security</label><select name="b[smtp][encryption]"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($sm['encryption'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div><label>Username</label><input type="text" name="b[smtp][username]" value="<?= pm_h((string)($sm['username'] ?? '')) ?>" autocomplete="off"></div>
        <div><label>Password</label><input type="password" name="b[smtp][password]" value="" placeholder="<?= ($sm['password'] ?? '') !== '' ? 'Saved, leave blank to keep' : '' ?>" autocomplete="new-password"></div>
        <div><label>Send from (email)</label><input type="text" name="b[smtp][from_email]" value="<?= pm_h((string)($sm['from_email'] ?? '')) ?>"></div>
      </div>
    </fieldset>
    <div class="row">
      <div><label>Send from (name)</label><input type="text" name="b[smtp][from_name]" value="<?= pm_h((string)($sm['from_name'] ?? $name)) ?>"></div>
      <div><label>Inbox for replies (IMAP host; blank = same as the mail server)</label><input type="text" name="b[imap][host]" value="<?= pm_h((string)($im['host'] ?? '')) ?>" placeholder="mail.example.com"></div>
      <div><label>IMAP port</label><input type="number" name="b[imap][port]" value="<?= (int)($im['port'] ?? 993) ?>"></div>
      <div><label>IMAP security</label><select name="b[imap][secure]"><?php foreach (['ssl' => 'SSL (993)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($im['secure'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <label class="check"><input type="checkbox" name="b[smtp][bcc_self]" value="1" <?= !empty($sm['bcc_self']) ? 'checked' : '' ?>> Send me a copy of every email</label>
    <div class="btns"><button class="btn small" form="smtpcheck-<?= pm_h($b) ?>">Check the mail login</button><button class="btn small" name="test_email" value="1">Save and send a test email</button></div>
  </div>

  <div class="card"><h2>WhatsApp, links and social</h2>
    <div class="row">
      <div><label>Free first step you offer</label><input type="text" name="b[magnet]" value="<?= pm_h((string)$prof['magnet']) ?>" placeholder="e.g. a free site visit"></div>
      <div><label>WhatsApp keyword for it</label><input type="text" name="b[cta_keyword]" value="<?= pm_h((string)$prof['cta_keyword']) ?>" maxlength="10" placeholder="QUOTE"></div>
      <div><label>Website link in emails and WhatsApp messages</label><input type="text" name="b[link_url]" value="<?= pm_h((string)($c['link_url'] ?? '')) ?>" placeholder="https://..."></div>
    </div>
    <label class="check"><input type="checkbox" name="b[link_on]" value="1" <?= !empty($c['link_on']) ? 'checked' : '' ?>> Add that link to messages</label>
    <?php $soc = pm_social_cfg($b); ?>
    <p class="hint">Facebook: <b><?= $soc['ready'] ? 'connected' : 'not connected' ?></b> · Instagram: <b><?= $soc['ig_id'] !== '' ? 'linked' : 'not linked' ?></b>. Set up the Page, pictures and links in <a href="?tab=social&amp;view=accounts&amp;brand=<?= pm_h($b) ?>">Social &gt; Accounts &amp; branding</a>.</p>
    <label class="check"><input type="checkbox" name="b[social_auto]" value="1" <?= !empty($c['social_auto']) ? 'checked' : '' ?>> Auto-publish: posts the AI plans go out at their time without my approval</label>
  </div>

  <?php $opS = pm_onepager_status($b); ?>
  <div class="card"><h2>One-page offer</h2>
    <p class="hint" style="margin-top:0">One page about the business, made from the details and facts above: what it does, what it offers, a free first step and how to reach it. It has no prices. It takes the place of a proposal for this business, and is emailed from a lead's card once they have written to you.</p>
    <?php if ($opS['problems']): ?><p class="hint warnt"><?= pm_h(implode(' ', $opS['problems'])) ?></p><?php endif; ?>
    <a class="btn small" href="?onepager=<?= pm_h($b) ?>">Download the PDF</a>
  </div>

  <div class="btns"><button class="btn primary">Save <?= pm_h($name) ?></button></div>
</form>
<form method="post" id="restudy-<?= pm_h($b) ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_restudy"><input type="hidden" name="id" value="<?= pm_h($b) ?>"></form>
<form method="post" id="smtpcheck-<?= pm_h($b) ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="smtp_check"><input type="hidden" name="brand" value="<?= pm_h($b) ?>"></form>

<details class="card" style="margin-top:14px"><summary>Hide this business</summary>
  <p class="hint">Hiding stops its daily agent run and removes it from the menus. Its leads, posts and settings are kept in the data folder, and you can bring it back by asking for it to be unhidden (clear "archived" in data/brands.json).</p>
  <form method="post" onsubmit="return confirm('Hide <?= pm_h(addslashes($name)) ?>?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_archive"><input type="hidden" name="id" value="<?= pm_h($b) ?>">
    <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, hide <?= pm_h($name) ?></label>
    <div class="btns"><button class="btn danger">Hide this business</button></div></form>
</details>
