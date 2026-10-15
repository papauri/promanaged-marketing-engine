<?php
/**
 * Public, no login: the business's shop (lib/sx_shop.php).
 *   ?b=<business>&feed=xml   the catalogue feed for Facebook Commerce Manager (RSS 2.0 with g: fields); &feed=csv the same as CSV in Meta's own columns
 *   ?b=<business>&img=<file> an item's picture (the feed's image_link)
 *   ?b=<business>&i=<id>     one item's page (the feed's link): picture, price, availability, words, and Order on WhatsApp / Message us / Call / Email
 *   ?b=<business>            every item
 * Only items the owner keeps visible are shown. Nothing here writes anything.
 */
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/sx_shop.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$brand = pm_brand_norm($_GET['b'] ?? 'promanaged');
pm_brand_set($brand);
$st = pm_shop_state($brand);

// the feed Commerce Manager reads on its schedule
$feed = (string)($_GET['feed'] ?? '');
if ($feed === 'xml' || $feed === 'csv') {
    header('Cache-Control: public, max-age=900');
    if ($feed === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        exit(pm_shop_feed_xml($brand));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $brand . '_catalog.csv"');
    exit(pm_shop_feed_csv($brand));
}

// an item's picture
$img = (string)($_GET['img'] ?? '');
if ($img !== '') {
    $path = pm_shop_dir($brand) . '/' . $img;
    if (!preg_match('/^[a-f0-9]{16}\.(jpg|jpeg|png|webp)$/', $img) || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . filesize($path));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($path);
    }
    exit;
}

$s = pm_settings();
$co = (string)$s['company_name'];
$cur = pm_shop_currency($brand);
$accent = pm_ui_accent(pm_load('settings', fn() => []), $brand);
$items = pm_shop_items($brand, true);
$one = (string)($_GET['i'] ?? '');
$it = $one !== '' ? ($items[$one] ?? null) : null;
if ($one !== '' && !$it) {
    http_response_code(404);
}
$wa = preg_replace('/\D/', '', pm_our_wa_number($s));
$wa = str_starts_with($wa, '0') ? '265' . substr($wa, 1) : $wa;
$fb = (string)(pm_load('social_page', fn() => [])[$brand]['link'] ?? '');
$fbId = preg_match('#facebook\.com/(?:profile\.php\?id=)?([^/?\#]+)#', $fb, $fm) ? $fm[1] : '';
$money = fn(array $x) => $cur . ' ' . number_format((float)$x['price']);
$onSale = fn(array $x) => ($x['sale_price'] ?? '') !== '' && (float)$x['sale_price'] > 0 && (float)$x['sale_price'] < (float)$x['price'];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$title = $it ? $it['title'] . ' · ' . $co : $co . ' shop';
$pic = fn(array $x) => ($x['image'] ?? '') !== '' ? '?b=' . rawurlencode($brand) . '&img=' . rawurlencode((string)$x['image']) : ''; // on the page itself: the same address, wherever the app runs
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?></title>
<?php if ($it): ?>
<meta property="og:type" content="product"><meta property="og:title" content="<?= $h($it['title']) ?>"><meta property="og:description" content="<?= $h(mb_substr((string)$it['description'], 0, 200)) ?>">
<meta property="og:image" content="<?= $h(pm_shop_image_url($brand, $it)) ?>"><meta property="og:url" content="<?= $h(pm_shop_item_url($brand, $one)) ?>">
<meta property="product:price:amount" content="<?= $h(number_format((float)($onSale($it) ? $it['sale_price'] : $it['price']), 2, '.', '')) ?>"><meta property="product:price:currency" content="<?= $h($cur) ?>">
<meta property="product:availability" content="<?= $h($it['availability'] ?? 'in stock') ?>"><meta property="product:condition" content="<?= $h($it['condition'] ?? 'new') ?>"><meta property="product:retailer_item_id" content="<?= $h($one) ?>">
<?php endif; ?>
<style>
:root { --a:<?= $h($accent) ?>; --ink:#15161a; --muted:#6e6d73; --line:#e9e7e1; --bg:#f5f4f0 }
* { box-sizing:border-box } body { margin:0; font:16px/1.5 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; color:var(--ink); background:var(--bg) }
header { background:#fff; border-bottom:1px solid var(--line) } .wrap { max-width:1080px; margin:0 auto; padding:16px }
header .wrap { display:flex; align-items:center; justify-content:space-between; gap:12px } header a { color:var(--ink); text-decoration:none; font-weight:700; font-size:18px }
.grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 240px), 1fr)); gap:16px }
.card { background:#fff; border:1px solid var(--line); border-radius:16px; overflow:hidden; text-decoration:none; color:var(--ink); display:flex; flex-direction:column }
.card img { width:100%; aspect-ratio:1; object-fit:cover; background:#eee } .card div { padding:12px 14px 16px } .card b { display:block; line-height:1.3 } .price { font-weight:700; color:var(--a); margin-top:6px }
.price s { color:var(--muted); font-weight:400; margin-left:6px } .out { color:#9a5b00; font-size:14px }
.item { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:28px; background:#fff; border:1px solid var(--line); border-radius:18px; padding:20px }
.item img { width:100%; border-radius:12px; background:#eee } h1 { font-size:26px; line-height:1.25; margin:0 0 8px } .item .price { font-size:24px }
.desc { white-space:pre-line; color:#3a3b42 } .btns { display:flex; flex-wrap:wrap; gap:10px; margin:18px 0 } .btn { display:inline-flex; align-items:center; padding:12px 18px; border-radius:999px; border:1px solid var(--line); color:var(--ink); text-decoration:none; font-weight:600; background:#fff }
.btn.primary { background:var(--a); border-color:var(--a); color:#fff } .meta { color:var(--muted); font-size:14px } .back { display:inline-block; margin-bottom:14px; color:var(--muted) }
@media (max-width:720px) { .item { grid-template-columns:1fr; padding:14px } }
</style></head><body>
<header><div class="wrap"><a href="?b=<?= $h(rawurlencode($brand)) ?>"><?= $h($co) ?></a><span class="meta"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span></div></header>
<main class="wrap">
<?php if ($it): $msg = 'Hello ' . $co . ', I would like to order: ' . $it['title'] . ' (' . ($onSale($it) ? $cur . ' ' . number_format((float)$it['sale_price']) : $money($it)) . ').'; ?>
  <a class="back" href="?b=<?= $h(rawurlencode($brand)) ?>">&larr; All items</a>
  <article class="item">
    <img src="<?= $h($pic($it)) ?>" alt="<?= $h($it['title']) ?>">
    <div>
      <h1><?= $h($it['title']) ?></h1>
      <div class="price"><?= $onSale($it) ? $h($cur . ' ' . number_format((float)$it['sale_price'])) . '<s>' . $h($money($it)) . '</s>' : $h($money($it)) ?></div>
      <p class="meta"><?= $h((PM_SHOP_AVAIL[$it['availability'] ?? ''] ?? 'In stock') . ' · ' . (PM_SHOP_COND[$it['condition'] ?? ''] ?? 'New') . (trim((string)($it['brand'] ?? '')) !== '' ? ' · ' . $it['brand'] : '')) ?></p>
      <div class="btns">
        <?php if ($wa !== ''): ?><a class="btn primary" href="https://wa.me/<?= $h($wa) ?>?text=<?= $h(rawurlencode($msg)) ?>" rel="noopener">Order on WhatsApp</a><?php endif; ?>
        <?php if ($fbId !== ''): ?><a class="btn<?= $wa === '' ? ' primary' : '' ?>" href="https://m.me/<?= $h($fbId) ?>" rel="noopener">Message us</a><?php endif; ?>
        <?php if (trim((string)($s['phone'] ?? '')) !== ''): ?><a class="btn" href="tel:<?= $h(preg_replace('/[^\d+]/', '', (string)$s['phone'])) ?>">Call</a><?php endif; ?>
        <?php if (filter_var($s['email'] ?? '', FILTER_VALIDATE_EMAIL)): ?><a class="btn" href="mailto:<?= $h($s['email']) ?>?subject=<?= $h(rawurlencode('Order: ' . $it['title'])) ?>">Email</a><?php endif; ?>
      </div>
      <div class="desc"><?= $h($it['description']) ?></div>
    </div>
  </article>
<?php elseif ($one !== ''): ?>
  <p>That item is not in the shop any more. <a href="?b=<?= $h(rawurlencode($brand)) ?>">See everything we sell</a>.</p>
<?php elseif (!$items): ?>
  <p>Nothing in the shop yet.</p>
<?php else: ?>
  <div class="grid">
  <?php foreach ($items as $id => $x): ?>
    <a class="card" href="?b=<?= $h(rawurlencode($brand)) ?>&amp;i=<?= $h(rawurlencode((string)$id)) ?>">
      <img src="<?= $h($pic($x)) ?>" alt="" loading="lazy">
      <div><b><?= $h($x['title']) ?></b><div class="price"><?= $onSale($x) ? $h($cur . ' ' . number_format((float)$x['sale_price'])) . '<s>' . $h($money($x)) . '</s>' : $h($money($x)) ?></div>
        <?= ($x['availability'] ?? '') === 'out of stock' ? '<div class="out">Out of stock</div>' : '' ?></div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</main></body></html>
