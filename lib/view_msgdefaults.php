<?php
/**
 * Sales > Targeting and messages > "Default first message": the email, the WhatsApp message and the signatures a business writes once, apart from the messages the AI writes
 * per lead (lib/msgdefaults.php). One form per business; the preview shows each message exactly as it goes out (the share-kit links, the PDF and the signature included).
 */
function pm_view_msgdefaults(string $vb, string $csrf): void
{
    $c = pm_dm_cfg($vb);
    $pv = pm_dm_as($vb, fn() => pm_dm_preview());
    $bn = pm_brand_name($vb);
    $sig = $c['signature'] ?? $c['signature_seed'];
    $wsig = $c['wa_signature'] ?? $c['wa_signature_seed'];
    $vars = implode(' ', array_map(fn($k) => '<code>{' . $k . '}</code>', array_keys(PM_DM_VARS)));
    ?>
<form method="post" id="defaultmsg">
  <input type="hidden" name="csrf" value="<?= pm_h($csrf) ?>"><input type="hidden" name="action" value="msgdefault"><input type="hidden" name="brand" value="<?= pm_h($vb) ?>">
  <div class="card"><h2>Default first message · <?= pm_h($bn) ?></h2>
    <p class="hint mt-0">Written once, the same for every lead. A lead's card has <b>Use default message</b>, or choose below to draft every new lead from it with no AI. Where a lead's own words go, write
      <?= $vars ?>. The business's links and PDF are added under the email and the WhatsApp message by themselves (Operations &gt; Business details).</p>
    <label>New leads are drafted by</label>
    <div class="checks mt-0">
      <label class="check"><input type="radio" name="dm[use]" value="ai" <?= $c['use'] === 'ai' ? 'checked' : '' ?>> <?= $vb === 'travel' ? 'The AI: it writes the subject, the WhatsApp message and the <code>{opening}</code> line for each lead; the rest of the email is the text below' : 'The AI: a message written for each lead (the default message stays one press away on every lead)' ?></label>
      <label class="check"><input type="radio" name="dm[use]" value="default" <?= $c['use'] === 'default' ? 'checked' : '' ?>> The default message below, for every lead, with no AI</label>
    </div>
    <label>Email subject</label><input type="text" name="dm[email_subject]" value="<?= pm_h($c['email_subject']) ?>" maxlength="120">
    <label>Email<?= $vb === 'travel' ? ' <span class="muted">· <code>{opening}</code> is the line written for each lead</span>' : '' ?></label><textarea name="dm[email_body]" rows="14" data-grow><?= pm_h($c['email_body']) ?></textarea>
    <label>WhatsApp message</label><textarea name="dm[whatsapp]" rows="3" data-grow><?= pm_h($c['whatsapp']) ?></textarea>
    <p class="hint <?= $pv['problems'] ? 'warnt' : '' ?>">The email is <b><?= (int)$pv['words'] ?></b> words with an example lead (first emails may be 20 to 190, with at most one link in the text).<?= $pv['problems'] ? ' Fix before it can be sent: ' . pm_h(implode(' ', $pv['problems'])) : ' It passes the checks.' ?></p>
  </div>
  <div class="card"><h2>Signatures</h2>
    <p class="hint mt-0">The signature ends every first email and reply, and the sign-off ends every WhatsApp message, whether the AI or the default message wrote it. Until you save, messages end as they always did (your name and details).
      A line whose words are missing, such as <code>{phone}</code> with no phone number, is left out. Your name comes from the person working (the team menu), else the signatory in Business details.</p>
    <div class="row">
      <div><label>Email signature</label><textarea name="dm[signature]" rows="6"><?= pm_h((string)$sig) ?></textarea></div>
      <div><label>WhatsApp sign-off</label><textarea name="dm[wa_signature]" rows="3"><?= pm_h((string)$wsig) ?></textarea>
        <p class="hint">WhatsApp also adds a line telling them to reply STOP.</p></div>
    </div>
  </div>
  <details class="card more"><summary>How it goes out (an example lead)</summary>
    <div class="split mt-2">
      <div><label>Email · <?= pm_h($pv['subject']) ?></label><pre><?= pm_h($pv['email']) ?></pre></div>
      <div><label>WhatsApp</label><pre><?= pm_h($pv['whatsapp']) ?></pre></div>
    </div>
  </details>
  <div class="btns formbar"><button class="btn primary">Save the default message</button>
    <?php if (!empty($c['saved'])): ?><button class="btn ghost" name="reset" value="1" data-confirm="Go back to the starting text for <?= pm_h($bn) ?>? What you wrote here is lost.">Back to the starting text</button><?php endif; ?></div>
</form>
<?php
}
