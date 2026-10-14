<?php
/** Operations > Business details: who each business is, how to reach it, its logo and how it signs. Included by view_settings.php. */
require_once __DIR__ . '/view_share.php';
pm_view_hidden_businesses($csrf);
$was = pm_brand();
pm_brand_set($vb);
$missing = pm_brand_missing(pm_settings());
pm_brand_set($was);
$missKeys = array_column($missing, 'key');
$need = fn(string $k) => in_array($k, $missKeys, true) ? ' <span class="chip warn">missing</span>' : '';
if ($missing): ?>
<div class="card nofold" id="missing"><h2>Missing details for <?= pm_h($bname) ?></h2>
  <p class="hint mt-0">Clients can only reach you through what is filled in here. Add these below, then press Save.</p>
  <ul class="nextlist"><?php foreach ($missing as $m): ?><li><span class="grow"><b><?= pm_h($m['label']) ?></b><br><span class="muted t-sm">Used for: <?= pm_h($m['why']) ?>.</span></span> <span class="chip warn">missing</span></li><?php endforeach; ?></ul>
</div>
<?php endif;

if ($isCustom): // a business added in the app: one form, saved by brand_save (the details part only)
    $b = $vb;
    $c = (array)$settings['brands'][$b];
    $logo = pm_brand_asset($b, 'logo');
    $fld = fn(string $k, string $label, string $ph = '', string $mark = '') => '<div><label>' . pm_h($label) . $mark . '</label><input type="text" name="b[' . $k . ']" value="' . pm_h((string)($c[$k] ?? '')) . '" placeholder="' . pm_h($ph) . '"></div>';
    $lp = pm_link_preview($b);
    ?>
<h3><?= pm_h($bname) ?></h3>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_save"><input type="hidden" name="id" value="<?= pm_h($b) ?>"><input type="hidden" name="_sec" value="details">
  <div class="card"><h2>How clients reach you</h2>
    <div class="row">
      <?= $fld('company_name', 'Business name', '', $need('company_name')) ?>
      <?= $fld('whatsapp', 'WhatsApp number', '0999 123 456', $need('whatsapp')) ?>
      <?= $fld('phone', 'Phone number (for calls)', '0999 123 456', $need('phone')) ?>
      <?= $fld('email', 'Email', 'info@example.com', $need('email')) ?>
      <?= $fld('website', 'Website', 'www.example.com', $need('website')) ?>
      <?= $fld('address', 'Address or town', '', $need('address')) ?>
      <?= $fld('tagline', 'Tagline', 'One line under the name') ?>
    </div>
    <p class="hint">The WhatsApp number is what campaigns, flyers, QR codes and WhatsApp buttons use. Leave it empty if your phone number is also your WhatsApp.</p>
  </div>
  <div class="card"><h2>Signing and numbering</h2>
    <div class="row">
      <?= $fld('signatory_name', 'Who signs for the business') ?>
      <?= $fld('signatory_title', 'Their title') ?>
      <div><label>Accent colour</label><input type="text" name="b[accent_color]" value="<?= pm_h((string)$c['accent_color']) ?>" maxlength="7" placeholder="#17375E"></div>
      <?= $fld('ref_prefix', 'Reference prefix', 'e.g. SUN') ?>
    </div>
  </div>
  <div class="card"><h2>Logo and the link in messages</h2>
    <div class="split">
      <div>
        <label>Logo (PNG or JPG)</label>
        <?php if (is_file(PM_ROOT . '/' . $logo)): ?><div class="logoprev"><img src="<?= pm_h($logo) ?>?v=<?= (int)@filemtime(PM_ROOT . '/' . $logo) ?>" alt="Current logo"><span class="hint">Current logo</span></div><?php endif; ?>
        <input type="file" name="logo" accept="image/png,image/jpeg">
      </div>
      <div class="linkbox">
        <label>Website link in emails and WhatsApp messages</label>
        <input type="text" name="b[link_url]" value="<?= pm_h((string)($c['link_url'] ?? '')) ?>" placeholder="https://...">
        <label class="check"><input type="checkbox" name="b[link_on]" value="1" <?= !empty($c['link_on']) ? 'checked' : '' ?>> Add it to messages</label>
        <?php if (($lp['url'] ?? '') !== ''): ?><div class="lpcard"><?php if (!empty($lp['image'])): ?><img src="?thumb=<?= pm_h($b) ?>&v=<?= urlencode((string)$lp['at']) ?>" alt="Link thumbnail"><?php endif; ?>
          <div><b><?= pm_h($lp['title'] ?: $lp['url']) ?></b><small><?= pm_h((string)parse_url($lp['url'], PHP_URL_HOST)) ?></small></div></div><?php endif; ?>
        <button type="submit" class="btn small" form="linkrefresh-<?= pm_h($b) ?>">Refresh preview</button>
      </div>
    </div>
  </div>
  <div class="btns formbar"><button class="btn primary">Save <?= pm_h($bname) ?></button></div>
</form>
<?php pm_view_share($b, $csrf); ?>
<p class="hint">What the AI knows is under <a href="?tab=social&amp;view=voice">Brand &gt; Voice</a>, who the agents look for under <a href="?tab=targeting">Sales &gt; Targeting and messages</a>, and the mail login under <a href="?tab=settings&amp;section=email">Email</a>.</p>

<details class="card mt-3"><summary>Hide this business</summary>
  <p class="hint">Hiding stops its daily agent run and removes it from the menus. Its leads, posts and settings are kept, and you can bring it back any time from Operations &gt; Business details &gt; Hidden businesses.</p>
  <form method="post" onsubmit="return confirm('Hide <?= pm_h(addslashes($bname)) ?>?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_archive"><input type="hidden" name="id" value="<?= pm_h($b) ?>">
    <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, hide <?= pm_h($bname) ?></label>
    <div class="btns"><button class="btn danger">Hide this business</button></div></form>
</details>
<details class="card mt-3"><summary>Delete this business for good</summary>
  <?= pm_brand_delete_form($b, $csrf) ?>
</details>

<?php else: // ProManaged IT and Travel Malawi: one form holds both (the one not on screen is hidden), saved by settings (the business part only)
    $card = function (string $b) use ($settings, $vb, $need): void {
        $tr = $b === 'travel';
        $c = $tr ? (array)$settings['travel'] : $settings;
        $n = fn(string $k) => $tr ? "s[travel][$k]" : "s[$k]";
        $name = trim((string)($c['company_name'] ?? '')) ?: pm_brand_name($b); // the name as typed: renaming the business renames it everywhere
        $logo = $tr ? 'assets/travel_logo.png' : 'assets/logo.png';
        $sig = $tr ? 'assets/travel_signature.png' : 'assets/signature.png';
        $hide = $vb === $b ? '' : 'hidden';
        $lp = pm_link_preview($b);
        $mk = fn(string $k) => $vb === $b ? $need($k) : '';
        ?>
    <div class="card" <?= $hide ?>><h2><?= pm_h($name) ?> · How clients reach you</h2>
      <div class="row">
        <div><label>Business name<?= $mk('company_name') ?></label><input type="text" name="<?= $n('company_name') ?>" value="<?= pm_h($c['company_name'] ?? '') ?>"></div>
        <div><label>WhatsApp number<?= $mk('whatsapp') ?></label><input type="text" name="<?= $n('whatsapp') ?>" value="<?= pm_h($c['whatsapp'] ?? '') ?>" placeholder="0999 123 456"></div>
        <div><label>Phone number (for calls)<?= $mk('phone') ?></label><input type="text" name="<?= $n('phone') ?>" value="<?= pm_h($c['phone'] ?? '') ?>" placeholder="0999 123 456"></div>
        <div><label>Email<?= $mk('email') ?></label><input type="text" name="<?= $n('email') ?>" value="<?= pm_h($c['email'] ?? '') ?>" placeholder="info@example.com"></div>
        <div><label>Website<?= $mk('website') ?></label><input type="text" name="<?= $n('website') ?>" value="<?= pm_h($c['website'] ?? '') ?>" placeholder="www.example.com"></div>
        <div><label>Address or town<?= $mk('address') ?></label><input type="text" name="<?= $n('address') ?>" value="<?= pm_h($c['address'] ?? '') ?>"></div>
        <div><label>Tagline</label><input type="text" name="<?= $n('tagline') ?>" value="<?= pm_h($c['tagline'] ?? '') ?>"></div>
      </div>
      <p class="hint">The WhatsApp number is what campaigns, flyers, QR codes and WhatsApp buttons use. Leave it empty if your phone number is also your WhatsApp.</p>
    </div>
    <div class="card" <?= $hide ?>><h2><?= pm_h($name) ?> · Signing and numbering</h2>
      <div class="row">
        <div><label>Who signs for you</label><input type="text" name="<?= $n('signatory_name') ?>" value="<?= pm_h($c['signatory_name'] ?? '') ?>"></div>
        <div><label>Their title</label><input type="text" name="<?= $n('signatory_title') ?>" value="<?= pm_h($c['signatory_title'] ?? '') ?>"></div>
        <div><label>Accent colour</label><input type="text" name="<?= $n('accent_color') ?>" value="<?= pm_h($c['accent_color'] ?? '') ?>" placeholder="#17375E"></div>
        <div><label>Reference prefix</label><input type="text" name="<?= $n('ref_prefix') ?>" value="<?= pm_h($c['ref_prefix'] ?? '') ?>"></div>
        <div><label>Next reference number</label><input type="number" name="<?= $n('next_ref') ?>" value="<?= (int)($c['next_ref'] ?? 1) ?>"></div>
      </div>
      <label class="check"><input type="checkbox" name="<?= $n('online_signing') ?>" value="1" <?= !empty($c['online_signing']) ? 'checked' : '' ?>> Let clients accept and sign online</label>
      <label class="check"><input type="checkbox" name="<?= $n('esign_tags') ?>" value="1" <?= !empty($c['esign_tags']) ? 'checked' : '' ?>> Add DocuSign and Adobe Acrobat Sign tags</label>
      <?php if ($tr): ?><label class="check"><input type="checkbox" name="s[travel][charging]" value="1" <?= !empty($c['charging']) ? 'checked' : '' ?>> We are charging for onboarding <span class="muted">(off = proposals say Free)</span></label><?php endif; ?>
      <label>Your signature (PNG with a transparent background), shown on every agreement</label>
      <?php if (is_file(PM_ROOT . '/' . $sig)): ?><div class="logoprev"><img src="<?= $sig ?>?v=<?= filemtime(PM_ROOT . '/' . $sig) ?>" alt="Current signature"><label class="check"><input type="checkbox" name="<?= $tr ? 'remove_travel_signature' : 'remove_signature' ?>" value="1"> Remove</label></div><?php endif; ?>
      <input type="file" name="<?= $tr ? 'travel_signature' : 'signature' ?>" accept="image/png,image/jpeg">
      <?php if (pm_app_url() === ''): ?><p class="hint">Online signing links need the app on your website (APP_URL in .env). Until then, PDFs carry a fillable acceptance page.</p><?php endif; ?>
    </div>
    <div class="card" <?= $hide ?>><h2><?= pm_h($name) ?> · Logo and the link in messages</h2>
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
    </div>
        <?php
    };
    ?>
<h3><?= pm_h($bname) ?></h3>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="_sec" value="business">
  <?php $card('promanaged'); $card('travel'); ?>
  <div class="btns formbar"><button class="btn primary">Save <?= pm_h($bname) ?></button></div>
</form>
<?php pm_view_share($vb, $csrf); ?>
<p class="hint">What the AI knows is under <a href="?tab=social&amp;view=voice">Brand &gt; Voice</a>, the first-email wording under <a href="?tab=targeting">Sales &gt; Targeting and messages</a>, and the mail login under <a href="?tab=settings&amp;section=email">Email</a>.</p>
<?php endif; ?>
