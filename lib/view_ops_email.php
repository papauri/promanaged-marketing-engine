<?php
/** Operations > Email: the mailbox, and whether the mail will reach inboxes. Included by view_settings.php. */
require_once __DIR__ . '/view_outbound.php';
pm_brand_set($vb);
?>
<h3><?= pm_h($bname) ?></h3>
<?php if ($isCustom):
    $b = $vb;
    $c = (array)$settings['brands'][$b];
    $sm = (array)($c['smtp'] ?? []);
    $im = (array)($c['imap'] ?? []);
    $pre = pm_brand_env_prefix($b);
    $envMail = ((pm_env()[$pre . 'SMTP_HOST'] ?? '') !== '');
    $name = (string)$c['company_name']; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="brand_save"><input type="hidden" name="id" value="<?= pm_h($b) ?>"><input type="hidden" name="_sec" value="email">
  <div class="card"><h2>Sending</h2>
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
        <div><label>Send from (name)</label><input type="text" name="b[smtp][from_name]" value="<?= pm_h((string)($sm['from_name'] ?? $name)) ?>"></div>
      </div>
    </fieldset>
    <label class="check"><input type="checkbox" name="b[smtp][bcc_self]" value="1" <?= !empty($sm['bcc_self']) ? 'checked' : '' ?>> Send me a copy of every email</label>
  </div>
  <div class="card"><h2>Reading replies</h2>
    <div class="row">
      <div><label>Inbox for replies (IMAP host; blank = same as the mail server)</label><input type="text" name="b[imap][host]" value="<?= pm_h((string)($im['host'] ?? '')) ?>" placeholder="mail.example.com"></div>
      <div><label>IMAP port</label><input type="number" name="b[imap][port]" value="<?= (int)($im['port'] ?? 993) ?>"></div>
      <div><label>IMAP security</label><select name="b[imap][secure]"><?php foreach (['ssl' => 'SSL (993)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($im['secure'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
  </div>
  <div class="btns formbar"><button class="btn primary">Save</button><button class="btn" form="smtpcheck-<?= pm_h($b) ?>">Check the mail login</button>
    <button class="btn" name="test_email" value="1">Save and send a test email</button><input type="email" name="test_to" placeholder="Send the test to (blank: the mailbox itself)" aria-label="Send the test email to" style="width:auto;min-width:240px"></div>
</form>
<?php else:
    $card = function (string $b) use ($settings, $vb): void {
        $tr = $b === 'travel';
        $c = $tr ? (array)$settings['travel'] : $settings;
        $n = fn(string $k) => $tr ? "s[travel][$k]" : "s[$k]";
        $sm = $tr ? (array)($c['smtp'] ?? []) : (array)$settings['smtp'];
        $env = $tr ? pm_tm_smtp_from_env() : pm_smtp_from_env();
        $e = pm_env();
        if ($env && $tr) {
            $sm = array_merge($sm, ['host' => $e['TM_SMTP_HOST'] ?? '', 'port' => $e['TM_SMTP_PORT'] ?? 465, 'encryption' => strtolower($e['TM_SMTP_SECURE'] ?? 'ssl'),
                'username' => $e['TM_SMTP_USER'] ?? '', 'from_email' => $e['TM_SMTP_FROM'] ?? '', 'from_name' => $e['TM_SMTP_FROM_NAME'] ?? ($sm['from_name'] ?? '')]);
        }
        $name = $tr ? $c['company_name'] : 'ProManaged IT';
        $hide = $vb === $b ? '' : 'hidden';
        ?>
    <div class="card" <?= $hide ?>><h2><?= pm_h($name) ?> · Sending</h2>
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
      <div class="row"><div><label>Send from (name)</label><input type="text" name="<?= $n('smtp') ?>[from_name]" value="<?= pm_h((string)($sm['from_name'] ?? $name)) ?>"></div></div>
      <label class="check"><input type="checkbox" name="<?= $n('smtp') ?>[bcc_self]" value="1" <?= !empty($sm['bcc_self']) ? 'checked' : '' ?>> Send me a copy of every email</label>
    </div>
        <?php
    }; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="_sec" value="email"><input type="hidden" name="test_brand" value="<?= pm_h($vb) ?>">
  <?php $card('promanaged'); $card('travel'); ?>
  <div class="btns formbar"><button class="btn primary">Save</button><button class="btn" form="smtpcheck-<?= pm_h($vb) ?>">Check the mail login</button>
    <button class="btn" name="test_email" value="1">Save and send a test email</button><input type="email" name="test_to" placeholder="Send the test to (blank: the mailbox itself)" aria-label="Send the test email to" style="width:auto;min-width:260px"></div>
</form>
<?php endif; ?>

<?php function_exists('pm_view_email_health') && pm_view_email_health(); ?>
<p class="hint">Alerts (a reply, a bounce, a proposal opened) are emailed to the Email address under <a href="?tab=settings&amp;section=business">Business details</a>, from the mail login above, at most once per subject every 6 hours. Run <code>php lib/poll_run.php</code> every 15 minutes (schedule_agents.bat does this) to check replies without opening the app. The approved emails waiting to go out are in the send queue on <a href="?tab=agents">Sales &gt; Leads</a>.</p>
