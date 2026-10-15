<?php
/**
 * Social > Shop: the catalogue Facebook and Instagram Shops show, kept here (lib/sx_shop.php). The head of Social's shop desk finds what we sell in our posts,
 * checks every item against what Commerce Manager needs, writes better words and turns items into posts; the owner decides. Commerce Manager reads the feed address.
 * Shares index.php's variables ($vb, $settings, $csrf).
 */
pm_brand_set($vb);
$st = pm_shop_state($vb);
$c = pm_shop_counts($vb);
$cur = pm_shop_currency($vb);
$nm = pm_agent_first('social');
$items = pm_shop_items($vb);
$sx = fn(string $do, string $extra = '') => '<input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra;
$hid = fn(string $n, string $v) => '<input type="hidden" name="' . $n . '" value="' . pm_h($v) . '">';
$feedXml = pm_shop_feed_url($vb, 'xml');
$feedCsv = pm_shop_feed_url($vb, 'csv');
$sel = fn(string $name, array $opts, string $on) => '<select name="' . $name . '">' . implode('', array_map(fn($k, $l) => '<option value="' . pm_h($k) . '"' . ($k === $on ? ' selected' : '') . '>' . pm_h($l) . '</option>', array_keys($opts), $opts)) . '</select>';
$img = fn(array $it) => ($it['image'] ?? '') !== '' && is_file(pm_shop_dir($vb) . '/' . $it['image']) ? 'shop.php?b=' . rawurlencode($vb) . '&img=' . rawurlencode((string)$it['image']) : '';
$form = function (string $id, array $it) use ($sx, $hid, $sel, $cur) {
    return '<form method="post" enctype="multipart/form-data" class="shop-form">' . $sx('shop_item', $hid('id', $id))
        . '<label>Title</label><input type="text" name="title" maxlength="200" required value="' . pm_h((string)($it['title'] ?? '')) . '">'
        . '<label>Description</label><textarea name="description" rows="5" maxlength="5000">' . pm_h((string)($it['description'] ?? '')) . '</textarea>'
        . '<div class="row"><div><label>Price (' . pm_h($cur) . ')</label><input type="text" inputmode="decimal" name="price" required value="' . pm_h(($it['price'] ?? '') !== '' && (float)($it['price'] ?? 0) > 0 ? (string)(0 + $it['price']) : '') . '"></div>'
        . '<div><label>Sale price <span class="muted">(optional)</span></label><input type="text" inputmode="decimal" name="sale_price" value="' . pm_h(($it['sale_price'] ?? '') !== '' ? (string)(0 + $it['sale_price']) : '') . '"></div></div>'
        . '<div class="row"><div><label>Availability</label>' . $sel('availability', PM_SHOP_AVAIL, (string)($it['availability'] ?? 'in stock')) . '</div>'
        . '<div><label>Condition</label>' . $sel('condition', PM_SHOP_COND, (string)($it['condition'] ?? 'new')) . '</div></div>'
        . '<div class="row"><div><label>Brand</label><input type="text" name="brand" maxlength="100" value="' . pm_h((string)($it['brand'] ?? '')) . '" placeholder="the maker, e.g. Gioteck"></div>'
        . '<div><label>Type <span class="muted">(groups items in the shop)</span></label><input type="text" name="group" maxlength="60" value="' . pm_h((string)($it['group'] ?? '')) . '" placeholder="e.g. Game controllers"></div></div>'
        . '<details class="more"><summary>Categories (optional)</summary><label>Google product category</label><input type="text" name="category" maxlength="250" value="' . pm_h((string)($it['category'] ?? '')) . '" placeholder="e.g. Electronics > Video Game Console Accessories">'
        . '<label>Facebook product category</label><input type="text" name="fb_category" maxlength="250" value="' . pm_h((string)($it['fb_category'] ?? '')) . '" placeholder="e.g. Electronics > Video Games & Consoles"></details>'
        . '<label>Picture <span class="muted">(JPG, PNG or WebP, at least 500×500)</span></label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"' . ($id === '' ? ' required' : '') . '>'
        . '<label class="check"><input type="checkbox" name="hidden" value="1"' . (!empty($it['hidden']) ? ' checked' : '') . '> Keep it out of the shop for now</label>'
        . '<div class="btns"><button class="btn primary">' . ($id === '' ? 'Add to the shop' : 'Save') . '</button></div></form>';
};
?>
<?= pm_social_nav('shop') ?>
<section class="card sf-desk">
  <div class="sf-desk-head">
    <div><h2 class="mt-0"><?= pm_h($nm) ?>'s shop desk</h2>
      <p class="hint mt-0">Everything we sell, kept here and shown in the Facebook and Instagram Shop. Commerce Manager reads the feed address below on its schedule, so a change here reaches the shop by itself. <?= pm_h($nm) ?> looks through our posts every day for items that are not in the shop yet, checks every item against what Facebook needs, writes better words when you ask and turns an item into a post.</p></div>
    <form method="post" class="sf-scoutnow"><?= $sx('shop_scan') ?><button class="btn primary">Look through our posts</button>
      <small class="muted"><?= $c['scan_at'] !== '' ? 'Last looked ' . pm_h(date('j M, H:i', strtotime($c['scan_at']))) : 'Not looked yet' ?></small></form>
  </div>
  <div class="sf-stats">
    <a class="sf-stat<?= $c['live'] ? ' ok' : '' ?>" href="#items"><span>In the shop</span><b><?= $c['live'] ?></b><small><?= $c['items'] - $c['live'] ? ($c['items'] - $c['live']) . ' kept out' : 'all visible' ?></small></a>
    <a class="sf-stat<?= $c['fix'] ? ' warn' : '' ?>" href="#items"><span>Need a fix</span><b><?= $c['fix'] ?></b><small>before Facebook shows them</small></a>
    <a class="sf-stat<?= $c['candidates'] ? ' warn' : '' ?>" href="#found"><span>Found in our posts</span><b><?= $c['candidates'] ?></b><small>to add with one press</small></a>
    <div class="sf-stat"><span>Out of stock</span><b><?= $c['out'] ?></b><small>shown as out of stock</small></div>
  </div>
</section>

<section class="card" id="connect">
  <h2 class="mt-0">Connect to Facebook Commerce Manager</h2>
  <?php if ($feedXml === ''): ?>
    <p class="warnt mt-0">The app has no public address yet (APP_URL in .env), so Facebook cannot read the feed. Set it on the server, then come back here for the address.</p>
  <?php else: ?>
  <ol class="t-sm">
    <li>In Commerce Manager, open your catalogue, then <b>Data sources › Add items › Data feed</b>, and choose <b>Commerce Manager</b> as the format.</li>
    <li>Choose <b>Use a URL</b> and paste this address:
      <div class="copyrow"><input type="text" readonly value="<?= pm_h($feedCsv) ?>" id="feedcsv" aria-label="The feed address"><button type="button" class="btn small" data-copy-target="#feedcsv">Copy</button></div>
      <span class="muted">It is in Meta's own template columns, with where you sell (a centre point and a radius) on every item, which Meta needs before it uploads an item. The same items as XML: <a href="<?= pm_h($feedXml) ?>" target="_blank" rel="noopener"><?= pm_h($feedXml) ?></a></span></li>
    <li>Set the schedule to <b>daily</b>, in <?= pm_h($cur) ?>. Facebook then picks up every change made here by itself.</li>
    <li>When the shop is live, paste its link below, so the team and the profiles can point people to it.</li>
  </ol>
  <p class="hint">Look at the feed now: <a href="shop.php?b=<?= pm_h(rawurlencode($vb)) ?>&amp;feed=xml" target="_blank" rel="noopener">XML</a> · <a href="shop.php?b=<?= pm_h(rawurlencode($vb)) ?>&amp;feed=csv" target="_blank" rel="noopener">CSV</a> · <a href="shop.php?b=<?= pm_h(rawurlencode($vb)) ?>" target="_blank" rel="noopener">the shop page</a></p>
  <?php endif; ?>
  <form method="post" class="row"><?= $sx('shop_settings') ?>
    <div><label>Your Facebook Shop link</label><input type="url" name="shop_url" value="<?= pm_h((string)$st['shop_url']) ?>" placeholder="https://www.facebook.com/commerce/shops/…"></div>
    <div><label>Catalogue ID <span class="muted">(optional)</span></label><input type="text" name="catalog_id" inputmode="numeric" value="<?= pm_h((string)$st['catalog_id']) ?>"></div>
    <div><label>Currency</label><input type="text" name="currency" maxlength="3" value="<?= pm_h($cur) ?>"></div>
    <?php [$aLat, $aLng, $aKm, $aTown] = pm_shop_area($vb); ?>
    <div><label>We sell from</label><select name="area_town"><?php foreach (array_keys(PM_SHOP_TOWNS) as $tw): ?><option value="<?= pm_h($tw) ?>"<?= $tw === $aTown ? ' selected' : '' ?>><?= pm_h($tw) ?></option><?php endforeach; ?></select></div>
    <div><label>And deliver within (km)</label><input type="number" name="area_km" min="1" max="2000" value="<?= (int)$aKm ?>" title="500 km from Lilongwe covers all of Malawi"></div>
    <div><label>Or our exact point <span class="muted">(latitude, longitude)</span></label><div class="two"><input type="text" name="area_lat" inputmode="decimal" placeholder="-13.9626" value="<?= pm_h(is_numeric($st['area_lat'] ?? null) ? (string)$st['area_lat'] : '') ?>"><input type="text" name="area_lng" inputmode="decimal" placeholder="33.7741" value="<?= pm_h(is_numeric($st['area_lng'] ?? null) ? (string)$st['area_lng'] : '') ?>"></div></div>
    <div class="btns"><button class="btn">Save</button><?php if ($st['shop_url'] !== ''): ?><a class="btn ghost" href="<?= pm_h((string)$st['shop_url']) ?>" target="_blank" rel="noopener">Open the shop</a><?php endif; ?></div>
  </form>
</section>

<?php if ($st['candidates']): ?>
<section class="card" id="found">
  <h2 class="mt-0">Found in our posts <span class="pill warn"><?= count($st['candidates']) ?></span></h2>
  <p class="hint mt-0">Items we are selling in our posts that are not in the shop yet, with the title, words and price taken from the post<?= array_filter($st['candidates'], fn($x) => ($x['by'] ?? '') === 'ai') ? ' (read by the AI)' : '' ?>. Adding one also takes the post's full-size picture.</p>
  <form method="post" class="btns mt-0"><?= $sx('shop_add_all') ?><button class="btn primary">Add all <?= count($st['candidates']) ?></button></form>
  <div class="shop-cands">
  <?php foreach ($st['candidates'] as $cid => $x): ?>
    <div class="shop-cand">
      <?php if (!empty($x['picture']) && str_starts_with((string)$x['picture'], 'http')): ?><img src="<?= pm_h((string)$x['picture']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
      <div><b><?= pm_h((string)$x['title']) ?></b><div class="shop-price"><?= pm_h($cur . ' ' . number_format((float)$x['price'])) ?></div>
        <p class="t-sm muted"><?= pm_h(mb_substr((string)$x['description'], 0, 180)) ?></p>
        <div class="btns"><form method="post" class="inline"><?= $sx('shop_add', $hid('cid', (string)$cid)) ?><button class="btn small primary">Add to the shop</button></form>
          <form method="post" class="inline"><?= $sx('shop_skip', $hid('cid', (string)$cid)) ?><button class="btn small ghost">Leave out</button></form></div></div>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<h3 id="items">The shop <span class="muted">· <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span></h3>
<?php if (!$items): ?><div class="card"><p class="muted mt-0 mb-0">Nothing in the shop yet. Press <b>Look through our posts</b>, or add an item below.</p></div><?php endif; ?>
<div class="shop-grid">
<?php foreach ($items as $id => $it): $p = pm_shop_problems($vb, $it); $src = $img($it); $sale = ($it['sale_price'] ?? '') !== '' && (float)$it['sale_price'] > 0; ?>
  <details class="card shop-item<?= !empty($it['hidden']) ? ' muted-card' : '' ?>" id="it-<?= pm_h((string)$id) ?>">
    <summary>
      <?= $src !== '' ? '<img src="' . pm_h($src) . '" alt="" loading="lazy">' : '<span class="shop-noimg">No picture</span>' ?>
      <span class="shop-sum"><b><?= pm_h((string)$it['title']) ?></b>
        <span class="shop-price"><?= pm_h($cur . ' ' . number_format((float)($sale ? $it['sale_price'] : $it['price']))) ?><?= $sale ? ' <s class="muted">' . pm_h(number_format((float)$it['price'])) . '</s>' : '' ?></span>
        <span class="mb-tags"><span class="pill <?= ($it['availability'] ?? '') === 'out of stock' ? 'warn' : 'ok' ?>"><?= pm_h(PM_SHOP_AVAIL[$it['availability'] ?? ''] ?? 'In stock') ?></span>
          <?= !empty($it['hidden']) ? '<span class="pill">kept out</span>' : '' ?><?= $p ? '<span class="pill bad">' . count($p) . ' to fix</span>' : '' ?><?= !empty($it['promoted']) ? '<span class="pill">posted ' . pm_h(date('j M', strtotime((string)$it['promoted']))) . '</span>' : '' ?></span></span>
    </summary>
    <?php if ($p): ?><ul class="t-sm warnt"><?php foreach ($p as $pp): ?><li><?= pm_h($pp) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?= $form((string)$id, $it) ?>
    <div class="btns">
      <form method="post" class="inline"><?= $sx('shop_ai', $hid('id', (string)$id)) ?><button class="btn small"<?= pm_agents_ready() ? '' : ' disabled title="Add an AI key to .env first"' ?> title="One small AI call: a clear title and plain words a buyer wants, the same facts">Write better words</button></form>
      <form method="post" class="inline"><?= $sx('shop_promote', $hid('id', (string)$id)) ?><button class="btn small" title="A draft post in the Plan with this picture, the price and the item's link">Post it</button></form>
      <?php if (pm_shop_item_url($vb, (string)$id) !== ''): ?><a class="btn small ghost" href="shop.php?b=<?= pm_h(rawurlencode($vb)) ?>&amp;i=<?= pm_h(rawurlencode((string)$id)) ?>" target="_blank" rel="noopener">See its page</a><?php endif; ?>
      <form method="post" class="inline" data-confirm="Take this item out of the shop? Facebook removes it at its next feed update."><?= $sx('shop_delete', $hid('id', (string)$id)) ?><button class="btn small danger">Remove</button></form>
    </div>
  </details>
<?php endforeach; ?>
</div>

<details class="card more" id="additem"><summary><b>Add an item by hand</b></summary><?= $form('', []) ?></details>
