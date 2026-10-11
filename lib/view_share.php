<?php
/**
 * Operations > Business details > "Documents and links to share": the share kit of one business (lib/sharekit.php). Two cards, each its own form:
 * the PDFs (upload once, attach to first emails, link in WhatsApp) and the links read from the website (switch on, rename, add your own).
 * Drawn after the business form, never inside it (forms cannot be nested).
 */
function pm_view_share(string $b, string $csrf): void
{
    $k = pm_share($b);
    $bn = pm_brand_name($b);
    $site = pm_share_site($b);
    $app = pm_app_url();
    $hid = fn(string $do) => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="share"><input type="hidden" name="brand" value="' . pm_h($b) . '"><input type="hidden" name="do" value="' . $do . '">';
    $roles = function (string $name, string $cur): string {
        $o = '';
        foreach (PM_SHARE_ROLES as $v => $lab) {
            $o .= '<option value="' . $v . '"' . ($v === $cur ? ' selected' : '') . '>' . pm_h($lab) . '</option>';
        }
        return '<select name="' . pm_h($name) . '" aria-label="Kind of page">' . $o . '</select>';
    };
    $state = pm_share_pack_state($b);
    ?>
<div id="share"></div>
<form method="post" enctype="multipart/form-data" class="card mt-3"><?= $hid('upload') ?>
  <h2>Documents to share · <?= pm_h($bn) ?></h2>
  <p class="hint mt-0">Upload a PDF once, for example the host starter pack (<i>Print &gt; Save as PDF</i> makes one from any page). First emails attach it and carry its link; WhatsApp messages carry the link only. <a href="?tab=targeting#defaultmsg">See how the messages come out</a>.</p>
  <?php if ($state === 'none'): ?><p class="hint">No starter pack is ready yet. Until one is, messages only mention the website.</p>
  <?php else: ?><p class="hint"><span class="chip ok dot">Pack ready</span> <?= $state === 'attached' ? 'It is attached to first emails and its link goes in WhatsApp.' : 'Its link goes in first emails and WhatsApp messages.' ?></p><?php endif; ?>
  <div class="row">
    <div><label>PDF file (up to <?= (int)(PM_SHARE_MAX_BYTES / 1048576) ?> MB)</label><input type="file" name="pdf" accept="application/pdf,.pdf" required></div>
    <div><label>Name people see</label><input type="text" name="title" maxlength="80" placeholder="Host starter pack"></div>
  </div>
  <label class="check"><input type="checkbox" name="attach" value="1" checked> Attach it to every first email</label>
  <div class="btns"><button class="btn primary">Upload the PDF</button></div>
</form>
<?php if ($k['docs']): ?>
<form method="post" class="card mt-3"><?= $hid('docs_save') ?>
  <h2>Uploaded documents</h2>
  <div class="rs-wrap"><table class="grid compact"><thead><tr><th>Name</th><th>Kind</th><th>Attach to emails</th><th>Link in messages</th><th>Opens from</th><th>Remove</th></tr></thead><tbody>
  <?php foreach ($k['docs'] as $d): $u = pm_share_doc_url($d); $ck = $k['check'][$d['id']] ?? null; $n = 'd[' . $d['id'] . ']'; $miss = pm_share_doc_path($d) === '' || !is_file(pm_share_doc_path($d)); ?>
    <tr>
      <td><input type="text" name="<?= $n ?>[title]" value="<?= pm_h((string)$d['title']) ?>" maxlength="80" aria-label="Name">
        <span class="hint"><?= $miss ? '<span class="chip bad">file missing: upload it again</span>' : pm_h(round(((int)$d['size']) / 1024) . ' KB · ' . (string)($d['orig'] ?? '') . ' · ' . substr((string)$d['added'], 0, 10)) ?></span></td>
      <td><?= $roles($n . '[role]', (string)($d['role'] ?? 'other')) ?></td>
      <td><label class="check"><input type="checkbox" name="<?= $n ?>[attach]" value="1" <?= !empty($d['attach']) ? 'checked' : '' ?>> Attach</label></td>
      <td><label class="check"><input type="checkbox" name="<?= $n ?>[link]" value="1" <?= !empty($d['link']) ? 'checked' : '' ?>> Add link</label></td>
      <td><input type="text" name="<?= $n ?>[url]" value="<?= pm_h((string)($d['url'] ?? '')) ?>" placeholder="<?= $app !== '' ? pm_h($app . '/doc.php?d=…') : 'https://… (a copy on your website)' ?>" aria-label="Web address of a copy">
        <span class="hint"><?php if ($u === ''): ?><span class="chip warn">no web link</span> set APP_URL in .env or paste one above
          <?php else: ?><a href="<?= pm_h($u) ?>" target="_blank" rel="noopener noreferrer">Open the link</a> <button type="button" class="btn small ghost" data-copy="<?= pm_h($u) ?>">Copy</button>
          <?php if ($ck): ?><span class="chip <?= !empty($ck['ok']) ? 'ok' : 'bad' ?> dot"><?= !empty($ck['ok']) ? 'opens' : 'does not open: ' . pm_h((string)$ck['why']) ?></span><?php endif; ?><?php endif; ?></span></td>
      <td><label class="check"><input type="checkbox" name="<?= $n ?>[remove]" value="1"> Remove</label></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <div class="btns"><button class="btn primary">Save documents</button><button class="btn" name="do" value="check" title="Opens each link from this server, as a lead would">Check the links open</button></div>
</form>
<?php endif; ?>

<form method="post" class="card mt-3"><?= $hid('links_save') ?>
  <h2>Links from the website · <?= pm_h($bn) ?></h2>
  <p class="hint mt-0">Read from <?= $site !== '' ? '<b>' . pm_h(pm_study_host($site)) . '</b>' : 'the website' ?>: the pages a person needs next, such as the sign-up page, the starter pack and how it works. The ones switched on are added under the message in first emails (up to <?= PM_SHARE_EMAIL_MAX ?>) and in first WhatsApp messages (up to <?= PM_SHARE_WA_MAX ?>), after the PDF.</p>
  <?php if (!empty($k['scan']['at'])): ?><p class="hint">Last read <?= pm_h((string)$k['scan']['at']) ?>: <?= !empty($k['scan']['ok']) ? (int)($k['scan']['found'] ?? 0) . ' page(s) found' . (!empty($k['scan']['spa']) ? ' (the site draws itself in the browser, so its menu was read from its script)' : '') . '.' : pm_h((string)($k['scan']['note'] ?? 'could not be read.')) ?></p><?php endif; ?>
  <?php if ($k['links']): ?>
  <div class="rs-wrap"><table class="grid compact"><thead><tr><th>Add</th><th>Name</th><th>Kind</th><th>Address</th><th>Remove</th></tr></thead><tbody>
  <?php foreach ($k['links'] as $l): $n = 'l[' . $l['id'] . ']'; ?>
    <tr>
      <td><label class="check"><input type="checkbox" name="<?= $n ?>[on]" value="1" <?= !empty($l['on']) ? 'checked' : '' ?> aria-label="Add to messages"></label></td>
      <td><input type="text" name="<?= $n ?>[label]" value="<?= pm_h((string)$l['label']) ?>" maxlength="60" aria-label="Name"></td>
      <td><?= $roles($n . '[role]', (string)($l['role'] ?? 'other')) ?></td>
      <td><a href="<?= pm_h((string)$l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= pm_h(preg_replace('#^https?://(www\.)?#', '', (string)$l['url'])) ?></a></td>
      <td><label class="check"><input type="checkbox" name="<?= $n ?>[remove]" value="1"> Remove</label></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php else: ?><p class="hint">Nothing yet. Press <b>Read the website</b>, or add a link below.</p><?php endif; ?>
  <div class="row mt-3">
    <div><label>Add a link by hand: name</label><input type="text" name="new[label]" maxlength="60" placeholder="List your property"></div>
    <div><label>Address</label><input type="text" name="new[url]" placeholder="https://…"></div>
    <div><label>Kind</label><?= $roles('new[role]', 'signup') ?></div>
  </div>
  <div class="btns"><button class="btn primary">Save links</button><button class="btn" name="do" value="scan" title="Reads the home page, the sitemap and the menu; adds pages it has not seen">Read the website</button></div>
</form>
<?php
}
