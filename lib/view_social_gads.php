<?php
/**
 * Social > Google Ads: the Search campaign planner (lib/sx_gads.php). Write the plan once (one AI call), change any line, download it for Google Ads Editor or paste it in,
 * track the enquiries, and type each week's numbers. Nothing is created or spent from here. Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$g = pm_gads_get($vb);
$plan = (array)($g['plan'] ?? []);
$set = pm_gads_settings($vb);
[, $srcNow] = pm_gads_inputs($vb);
$stale = $plan && ($g['sig'] ?? '') !== md5($srcNow);
$sum = pm_gads_summary($vb);
$hosted = pm_app_url() !== '';
$fx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$lines = fn(array $a) => pm_h(implode("\n", $a));
$count = fn(array $a, int $lim) => count($a) . ' · longest ' . ($a ? max(array_map('mb_strlen', $a)) : 0) . '/' . $lim;
?>
<?= pm_social_nav('gads') ?>
<div class="card">
  <h2 class="mt-0">Google Ads planner</h2>
  <p class="hint mt-0">A complete Search campaign for <?= pm_h(pm_brand_name($vb)) ?>: ad groups, keywords, negative keywords, responsive search ads, sitelinks, callouts and the settings, written from your facts and the words real people used when they asked for what you sell. Google's limits and editorial rules and our facts-only rule are checked in code. The app is not connected to Google Ads, so nothing is created or spent from here: download the file for Google Ads Editor, or paste it in.</p>
  <?php if ($plan): ?><p class="mt-0">Written <?= pm_h(date('j M Y H:i', strtotime((string)$g['at']))) ?> by <?= pm_h((string)($g['by'] ?? '')) ?>: <?= count((array)$plan['groups']) ?> ad groups, <?= array_sum(array_map(fn($x) => count($x['keywords']), (array)$plan['groups'])) ?> keywords.</p><?php endif; ?>
  <?php if ($stale): ?><p class="flash warn">What you sell, your towns or your facts changed since the plan was written. Write it again so the ads match.</p><?php endif; ?>
  <form method="post"><?= $fx('gads_write') ?>
    <label>Anything to focus on (optional)</label><input type="text" name="note" maxlength="300" placeholder="e.g. push CCTV in Lilongwe this month; we do not do printers">
    <div class="btns"><button class="btn primary" <?= pm_agents_ready() ? '' : 'disabled title="Add an AI key to .env first"' ?> <?= $plan ? 'onclick="return confirm(\'Write the whole plan again? Your edits to it will be replaced.\')"' : '' ?>><?= $plan ? 'Write the plan again' : 'Write the plan' ?> (1 AI call)</button>
      <?php if ($plan): ?><a class="btn" href="?gads_csv=1">Download for Google Ads Editor (CSV)</a><?php endif; ?></div>
  </form>
</div>

<div class="card" id="settings">
  <h2>Budget, landing page and tracking</h2>
  <form method="post"><?= $fx('gads_settings') ?>
    <div class="row">
      <div><label>Most to spend a day (Kwacha)</label><input type="number" name="cap" min="500" max="500000" step="100" value="<?= pm_gads_cap($vb) ?>"></div>
      <div><label>Send clicks to</label><select name="land">
        <option value="enquiry"<?= ($g['land'] ?? 'enquiry') === 'enquiry' ? ' selected' : '' ?><?= $hosted ? '' : ' disabled' ?>>Our enquiry page (each enquiry becomes a lead marked Google Ads)<?= $hosted ? '' : ': needs the app online (APP_URL)' ?></option>
        <option value="site"<?= ($g['land'] ?? '') === 'site' || !$hosted ? ' selected' : '' ?>>Our website (tagged, but enquiries are not counted here)</option></select></div>
    </div>
    <div class="row">
      <div><label>Google tag ID (optional)</label><input type="text" name="tag" value="<?= pm_h((string)($g['tag'] ?? '')) ?>" placeholder="AW-123456789" maxlength="20"></div>
      <div><label>Conversion label (optional)</label><input type="text" name="label" value="<?= pm_h((string)($g['label'] ?? '')) ?>" placeholder="AbC-D_efG-h12_34" maxlength="40"></div>
    </div>
    <p class="hint">With both saved, our enquiry page loads the Google tag and tells Google Ads each time someone sends an enquiry, so Google can find more people like them. You find both in Google Ads under Goals › Conversions › New conversion action › Website, when you choose to set it up manually: the ID starts with AW- and the label is the part after the slash.</p>
    <div class="btns"><button class="btn small primary">Save</button></div>
  </form>
</div>

<div class="card">
  <h2>The campaign</h2>
  <table class="sx-table">
    <?php foreach (['Campaign' => $set['name'], 'Type' => 'Search', 'Networks' => $set['networks'], 'Budget' => $set['budget'], 'Bidding' => $set['bidding'], 'Locations' => $set['locations'], 'Language' => $set['languages'], 'Ad schedule' => $set['schedule'],
        'Final URL' => $set['final_url'] !== '' ? $set['final_url'] : 'No website or online app yet: add the website in Operations › Business details', 'Call asset' => $set['phone'] !== '' ? $set['phone'] : 'No phone in the business details', 'Status' => $set['status']] as $k => $v): ?>
    <tr><th style="text-align:left;white-space:nowrap;vertical-align:top"><?= pm_h($k) ?></th><td><?= pm_h($v) ?><?= $k === 'Final URL' && $set['final_url'] !== '' ? ' <button type="button" class="btn small" data-copy="' . pm_h($v) . '">Copy</button>' : '' ?></td></tr>
    <?php endforeach; ?>
  </table>
  <details class="mt-2"><summary class="hint" style="cursor:pointer"><b>How to put it into Google Ads</b></summary>
    <ol class="t-sm">
      <li>Open or create your Google Ads account in expert mode (not a Smart campaign) and set the account currency.</li>
      <li>Easiest: install Google Ads Editor, open your account, choose Account › Import › From file, pick the downloaded CSV, check the preview, then Post. The campaign arrives paused.</li>
      <li>Or by hand: New campaign › Search, with the settings above; for each ad group below paste the keywords (as phrase match), the negatives, then a responsive search ad with its headlines and descriptions.</li>
      <li>Add the sitelinks, callouts, structured snippet and call asset from the bottom of this page.</li>
      <li>Check the keywords' search volumes in Google's Keyword Planner: the app cannot see them, and a keyword nobody types is simply never shown.</li>
      <li>Save the Google tag ID and conversion label above, then enable the campaign. Type each week's numbers at the bottom so the Director can compare it with the other channels.</li>
    </ol></details>
</div>

<?php foreach ((array)($plan['groups'] ?? []) as $i => $gr): ?>
<details class="card" id="g<?= $i ?>" <?= $i === 0 ? 'open' : '' ?>>
  <summary><b>Ad group: <?= pm_h($gr['name']) ?></b> <span class="muted t-sm">· <?= count($gr['keywords']) ?> keywords, <?= count($gr['headlines']) ?> headlines, <?= count($gr['descriptions']) ?> descriptions</span></summary>
  <form method="post"><?= $fx('gads_group', '<input type="hidden" name="g" value="' . $i . '">') ?>
    <label>Name</label><input type="text" name="name" value="<?= pm_h($gr['name']) ?>" maxlength="60">
    <div class="row">
      <div><label>Keywords, one a line (phrase match) <span class="muted t-sm"><?= $count($gr['keywords'], 80) ?></span></label><textarea name="keywords" rows="8" id="gk<?= $i ?>"><?= $lines($gr['keywords']) ?></textarea><button type="button" class="btn small" data-copy-target="#gk<?= $i ?>">Copy</button></div>
      <div><label>Negative keywords for this group</label><textarea name="negatives" rows="8" id="gn<?= $i ?>"><?= $lines($gr['negatives']) ?></textarea><button type="button" class="btn small" data-copy-target="#gn<?= $i ?>">Copy</button></div>
    </div>
    <label>Headlines, one a line, up to 15 <span class="muted t-sm"><?= $count($gr['headlines'], 30) ?></span></label><textarea name="headlines" rows="8" id="gh<?= $i ?>"><?= $lines($gr['headlines']) ?></textarea>
    <label>Descriptions, one a line, up to 4 <span class="muted t-sm"><?= $count($gr['descriptions'], 90) ?></span></label><textarea name="descriptions" rows="4" id="gd<?= $i ?>"><?= $lines($gr['descriptions']) ?></textarea>
    <div class="row"><div><label>Display path 1</label><input type="text" name="path1" maxlength="15" value="<?= pm_h($gr['path1']) ?>"></div><div><label>Display path 2</label><input type="text" name="path2" maxlength="15" value="<?= pm_h($gr['path2']) ?>"></div></div>
    <div class="btns"><button class="btn small primary">Save this ad group</button><button type="button" class="btn small" data-copy-target="#gh<?= $i ?>">Copy headlines</button><button type="button" class="btn small" data-copy-target="#gd<?= $i ?>">Copy descriptions</button>
      <button class="btn small ghost" name="remove" value="1" onclick="return confirm('Remove this ad group from the plan?')">Remove</button></div>
    <p class="hint">Each line is checked when you save: headlines up to 30 characters with no exclamation mark, descriptions up to 90, no words in capitals, phone numbers, links, or numbers and claims your facts do not carry.</p>
  </form>
</details>
<?php endforeach; ?>

<?php if ($plan): ?>
<div class="card" id="assets">
  <h2>Assets and campaign negatives</h2>
  <form method="post"><?= $fx('gads_assets') ?>
    <div class="row">
      <div><label>Negative keywords for the whole campaign</label><textarea name="negatives" rows="8"><?= $lines((array)$plan['negatives']) ?></textarea></div>
      <div><label>Sitelinks: text | line 1 | line 2</label><textarea name="sitelinks" rows="8"><?= pm_h(implode("\n", array_map(fn($s) => $s['text'] . ' | ' . $s['d1'] . ' | ' . $s['d2'], (array)$plan['sitelinks']))) ?></textarea></div>
    </div>
    <div class="row">
      <div><label>Callouts (up to 25 characters)</label><textarea name="callouts" rows="5"><?= $lines((array)$plan['callouts']) ?></textarea></div>
      <div><label>Structured snippet: <?= pm_h((string)$plan['snippet_header']) ?></label><textarea name="snippets" rows="5"><?= $lines((array)$plan['snippets']) ?></textarea></div>
    </div>
    <div class="btns"><button class="btn small primary">Save</button></div>
  </form>
  <?php if (!empty($plan['dropped'])): ?><details><summary class="hint" style="cursor:pointer">Lines the checks left out when the plan was written (<?= count($plan['dropped']) ?>)</summary><ul class="t-sm"><?php foreach ($plan['dropped'] as $d): ?><li><?= pm_h($d) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</div>
<?php endif; ?>

<div class="card" id="results">
  <h2>Results</h2>
  <?php if ($sum): ?>
  <p class="mt-0">Last 30 days: MWK <?= number_format($sum['spend']) ?> spent, <?= number_format($sum['clicks']) ?> clicks<?= $sum['cost_per_click'] !== null ? ' (MWK ' . number_format($sum['cost_per_click']) . ' a click)' : '' ?>, <?= (int)$sum['conv'] ?> conversions in Google Ads,
    <b><?= (int)$sum['leads'] ?> lead<?= $sum['leads'] === 1 ? '' : 's' ?> in the app</b><?= $sum['cost_per_lead'] !== null ? ' at MWK ' . number_format($sum['cost_per_lead']) . ' each' : '' ?>. The Director compares this with the other channels.</p>
  <?php else: ?><p class="hint mt-0">Once the ads run, type each week's numbers from Google Ads here. The leads are counted by the app from the enquiry page.</p><?php endif; ?>
  <form method="post"><?= $fx('gads_results') ?>
    <div class="row">
      <div><label>Week of</label><input type="date" name="week" value="<?= date('Y-m-d', strtotime('monday this week')) ?>"></div>
      <div><label>Spent (Kwacha)</label><input type="number" name="spend" min="0" step="1"></div>
      <div><label>Clicks</label><input type="number" name="clicks" min="0"></div>
      <div><label>Impressions</label><input type="number" name="impr" min="0"></div>
      <div><label>Conversions</label><input type="number" name="conv" min="0"></div>
    </div>
    <div class="btns"><button class="btn small primary">Save the week</button></div>
  </form>
  <?php if (!empty($g['results'])): ?><table class="sx-table mt-2"><tr><th>Week of</th><th class="n">Spent</th><th class="n">Clicks</th><th class="n">Impressions</th><th class="n">Conversions</th></tr>
    <?php foreach (array_slice((array)$g['results'], 0, 8, true) as $wk => $r): ?><tr><td><?= pm_h(date('j M Y', strtotime((string)$wk))) ?></td><td class="n"><?= number_format((float)$r['spend']) ?></td><td class="n"><?= (int)$r['clicks'] ?></td><td class="n"><?= (int)$r['impr'] ?></td><td class="n"><?= (int)$r['conv'] ?></td></tr><?php endforeach; ?>
  </table><?php endif; ?>
</div>
