<?php
/**
 * The Shop: the business's product catalogue, kept here and read by Facebook's Commerce Manager from a data-feed address (a scheduled feed), so the Facebook and
 * Instagram Shop always shows what we really sell. Social owns it:
 *   - the catalogue: each item's title, description, price and sale price (in the local money), availability, condition, brand, category and picture;
 *   - the feed: shop.php?b=<business>&feed=xml (RSS 2.0 with Google's g: fields, which Commerce Manager reads), or feed=csv; one public page per item (shop.php?b=..&i=..)
 *     with the picture, the price and "Order on WhatsApp" / "Message us", which is the item's link in the feed;
 *   - the shop desk (the head of Social): it finds the items we are selling in our posts (Facebook Page and the app's own) that are not in the shop yet, with the
 *     title, words, price and picture taken from the post, for the owner to add with one press; it checks every item against what Commerce Manager needs; it writes
 *     better words when asked (one small call, the same facts and price) and turns an item into a post for the Plan; it reports on the Overview and tells the Writers.
 * Public functions used by shop.php need only lib/store.php. Data: data/shop.json {brand: {items, candidates, shop_url, catalog_id, currency, at, scan_at, error}};
 * pictures in data/shop/<brand>/ (served by shop.php?img=).
 */

// the values Meta's Commerce Manager template accepts (catalog_products.csv)
const PM_SHOP_AVAIL = ['in stock' => 'In stock', 'out of stock' => 'Out of stock'];
const PM_SHOP_COND = ['new' => 'New', 'used' => 'Used'];

function pm_shop_state(string $brand): array
{
    return (array)(pm_load('shop', fn() => [])[$brand] ?? []) + ['items' => [], 'candidates' => [], 'shop_url' => '', 'catalog_id' => '', 'currency' => '', 'at' => '', 'scan_at' => '', 'error' => ''];
}

function pm_shop_update(string $brand, callable $fn): array
{
    $out = [];
    pm_update('shop', function (array $all) use ($brand, $fn, &$out) {
        $all[$brand] = $out = $fn((array)($all[$brand] ?? []) + ['items' => [], 'candidates' => [], 'shop_url' => '', 'catalog_id' => '', 'currency' => '', 'at' => '', 'scan_at' => '', 'error' => '']);
        return $all;
    }, fn() => []);
    return $out;
}

/** The money the shop prices in: the one saved for the shop, else the business's own (Malawi: MWK). */
function pm_shop_currency(string $brand): string
{
    $c = strtoupper(trim((string)pm_shop_state($brand)['currency']));
    if (preg_match('/^[A-Z]{3}$/', $c)) {
        return $c;
    }
    $was = pm_brand();
    pm_brand_set($brand);
    $s = pm_settings();
    pm_brand_set($was);
    $c = strtoupper(trim((string)($s['currency'] ?? '')));
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'MWK';
}

function pm_shop_dir(string $brand): string
{
    $d = PM_DATA . '/shop/' . preg_replace('/[^a-z0-9_-]/', '', $brand);
    is_dir($d) || @mkdir($d, 0775, true);
    return $d;
}

/** The public addresses (from APP_URL in .env): the shop page, the feed, an item's page, an item's picture. '' when APP_URL is not set. */
function pm_shop_base(): string
{
    return pm_app_url() !== '' ? pm_app_url() . '/shop.php' : '';
}

function pm_shop_feed_url(string $brand, string $fmt = 'xml'): string
{
    return pm_shop_base() !== '' ? pm_shop_base() . '?b=' . rawurlencode($brand) . '&feed=' . $fmt : '';
}

function pm_shop_item_url(string $brand, string $id): string
{
    return pm_shop_base() !== '' ? pm_shop_base() . '?b=' . rawurlencode($brand) . '&i=' . rawurlencode($id) : '';
}

function pm_shop_image_url(string $brand, array $it): string
{
    return pm_shop_base() !== '' && ($it['image'] ?? '') !== '' ? pm_shop_base() . '?b=' . rawurlencode($brand) . '&img=' . rawurlencode((string)$it['image']) : '';
}

/** "Gioteck WX4 Wireless Controller" -> "gioteck-wx4-wireless-controller" (the item's id in the feed: it never changes once made). */
function pm_shop_slug(string $title, array $taken): string
{
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title))), '-');
    $s = substr($s !== '' ? $s : 'item', 0, 48);
    $id = $s;
    for ($i = 2; isset($taken[$id]); $i++) {
        $id = $s . '-' . $i;
    }
    return $id;
}

/** The active items, in order: newest first. */
function pm_shop_items(string $brand, bool $activeOnly = false): array
{
    $items = pm_shop_state($brand)['items'];
    $activeOnly && $items = array_filter($items, fn($it) => empty($it['hidden']));
    uasort($items, fn($a, $b) => strcmp((string)($b['created'] ?? ''), (string)($a['created'] ?? '')));
    return $items;
}

/** What Commerce Manager would refuse or warn about for one item ([] = fine). */
function pm_shop_problems(string $brand, array $it): array
{
    $p = [];
    $t = trim((string)($it['title'] ?? ''));
    $t === '' && $p[] = 'No title.';
    mb_strlen($t) > 200 && $p[] = 'The title is over 200 characters.';
    trim((string)($it['description'] ?? '')) === '' && $p[] = 'No description.';
    (float)($it['price'] ?? 0) <= 0 && $p[] = 'No price.';
    if (($it['sale_price'] ?? '') !== '' && (float)$it['sale_price'] >= (float)($it['price'] ?? 0)) {
        $p[] = 'The sale price is not below the price.';
    }
    $img = ($it['image'] ?? '') !== '' ? pm_shop_dir($brand) . '/' . $it['image'] : '';
    if ($img === '' || !is_file($img)) {
        $p[] = 'No picture.';
    } elseif (($sz = @getimagesize($img)) && ($sz[0] < 500 || $sz[1] < 500)) {
        $p[] = 'The picture is ' . $sz[0] . '×' . $sz[1] . ' pixels; Facebook wants at least 500×500.';
    }
    pm_shop_base() === '' && $p[] = 'The app has no public address (APP_URL in .env), so the item has no link.';
    return $p;
}

/** A price for the feed: "150000.00 MWK". */
function pm_shop_money(float $v, string $cur): string
{
    return number_format($v, 2, '.', '') . ' ' . $cur;
}

/** The feed Commerce Manager reads: RSS 2.0 with Google's product fields (g:), every visible item that has a title, a price and a picture. */
function pm_shop_feed_xml(string $brand): string
{
    $cur = pm_shop_currency($brand);
    $was = pm_brand();
    pm_brand_set($brand);
    $s = pm_settings();
    pm_brand_set($was);
    $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n<channel>\n"
        . '<title>' . $x((string)$s['company_name']) . "</title>\n<link>" . $x(pm_shop_base() . '?b=' . rawurlencode($brand)) . "</link>\n<description>" . $x((string)$s['company_name'] . ' shop') . "</description>\n";
    foreach (pm_shop_items($brand, true) as $id => $it) {
        if (trim((string)$it['title']) === '' || (float)$it['price'] <= 0 || pm_shop_image_url($brand, $it) === '') {
            continue;
        }
        $out .= "<item>\n"
            . '<g:id>' . $x($id) . "</g:id>\n<g:title>" . $x(mb_substr((string)$it['title'], 0, 200)) . "</g:title>\n<g:description>" . $x(mb_substr(trim((string)$it['description']), 0, 9999)) . "</g:description>\n"
            . '<g:availability>' . $x(isset(PM_SHOP_AVAIL[$it['availability'] ?? '']) ? $it['availability'] : 'in stock') . "</g:availability>\n<g:condition>" . $x(isset(PM_SHOP_COND[$it['condition'] ?? '']) ? $it['condition'] : 'new') . "</g:condition>\n"
            . '<g:price>' . $x(pm_shop_money((float)$it['price'], $cur)) . "</g:price>\n"
            . (($it['sale_price'] ?? '') !== '' && (float)$it['sale_price'] > 0 && (float)$it['sale_price'] < (float)$it['price'] ? '<g:sale_price>' . $x(pm_shop_money((float)$it['sale_price'], $cur)) . "</g:sale_price>\n" : '')
            . '<g:link>' . $x(pm_shop_item_url($brand, $id)) . "</g:link>\n<g:image_link>" . $x(pm_shop_image_url($brand, $it)) . "</g:image_link>\n"
            . '<g:brand>' . $x(trim((string)($it['brand'] ?? '')) !== '' ? $it['brand'] : $s['company_name']) . "</g:brand>\n"
            . (trim((string)($it['category'] ?? '')) !== '' ? '<g:google_product_category>' . $x($it['category']) . "</g:google_product_category>\n" : '')
            . (trim((string)($it['fb_category'] ?? '')) !== '' ? '<fb_product_category>' . $x($it['fb_category']) . "</fb_product_category>\n" : '')
            . (trim((string)($it['group'] ?? '')) !== '' ? '<g:product_type>' . $x($it['group']) . "</g:product_type>\n" : '')
            . "</item>\n";
    }
    return $out . "</channel>\n</rss>\n";
}

/** The same feed as CSV (Commerce Manager's own column names), for a download or a second feed. */
function pm_shop_feed_csv(string $brand): string
{
    $cur = pm_shop_currency($brand);
    $h = fopen('php://temp', 'r+');
    fputcsv($h, ['id', 'title', 'description', 'availability', 'condition', 'link', 'image_link', 'brand', 'price', 'google_product_category', 'fb_product_category', 'sale_price', 'item_group_id', 'product_tags[0]']);
    $was = pm_brand();
    pm_brand_set($brand);
    $co = (string)pm_settings()['company_name'];
    pm_brand_set($was);
    foreach (pm_shop_items($brand, true) as $id => $it) {
        if (trim((string)$it['title']) === '' || (float)$it['price'] <= 0 || pm_shop_image_url($brand, $it) === '') {
            continue;
        }
        fputcsv($h, [$id, mb_substr((string)$it['title'], 0, 200), trim((string)$it['description']), isset(PM_SHOP_AVAIL[$it['availability'] ?? '']) ? $it['availability'] : 'in stock',
            isset(PM_SHOP_COND[$it['condition'] ?? '']) ? $it['condition'] : 'new', pm_shop_item_url($brand, $id), pm_shop_image_url($brand, $it), trim((string)($it['brand'] ?? '')) ?: $co,
            pm_shop_money((float)$it['price'], $cur), (string)($it['category'] ?? ''), (string)($it['fb_category'] ?? ''),
            ($it['sale_price'] ?? '') !== '' && (float)$it['sale_price'] > 0 && (float)$it['sale_price'] < (float)$it['price'] ? pm_shop_money((float)$it['sale_price'], $cur) : '', '', (string)($it['group'] ?? '')]);
    }
    rewind($h);
    return (string)stream_get_contents($h);
}

/* ---------------- the shop desk: finding what we sell in our posts ---------------- */

/** A post's words as an item, by code alone: the first line as the title (no emojis), the rest as the description, the first money amount as the price. */
function pm_shop_guess(string $text): array
{
    $clean = fn($s) => trim((string)preg_replace(['/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '/\s+/u'], ['', ' '], (string)$s));
    $lines = array_values(array_filter(array_map($clean, preg_split('/\R/u', $text) ?: []), fn($l) => $l !== '' && !str_starts_with($l, '#')));
    $title = (string)preg_replace('/^(upgrade|power up|level up|go wireless on [^ ]+ with)\b.*?\bwith (the )?/i', '', $lines[0] ?? '');
    $amounts = function_exists('pm_money_amounts') ? pm_money_amounts($text) : [];
    $price = $amounts ? (float)preg_replace('/[^\d.]/', '', str_replace(',', '', (string)preg_replace('/\.\d+$/', '', $amounts[0]))) : 0.0;
    $desc = implode("\n", array_filter(array_slice($lines, 1), fn($l) => !preg_match('/(price|order online|inbox us|www\.|https?:)/i', $l)));
    return ['title' => mb_substr(trim($title, " -–—:!"), 0, 150), 'description' => mb_substr($desc, 0, 2000), 'price' => $price];
}

/**
 * Looks through our posts (the Page's and the app's own) for items we sell (a picture and a price) that are not in the shop yet, and keeps them as candidates
 * for the owner, with the title, words, price, brand and condition taken from the post (one small AI call for all of them; by code without AI). Returns how many were found.
 */
function pm_shop_scan(string $brand): int
{
    if (!function_exists('pm_rp_sources')) {
        return 0;
    }
    $st = pm_shop_state($brand);
    $known = [];
    foreach (array_merge($st['items'], $st['candidates']) as $x) {
        foreach ((array)($x['from'] ?? []) as $k) {
            $known[$k] = 1;
        }
        $known['t:' . strtolower(trim((string)($x['title'] ?? '')))] = 1;
    }
    $todo = [];
    foreach (pm_rp_sources($brand, 60) as $k => $src) {
        if (isset($known[$k]) || !empty($src['video']) || trim((string)$src['text']) === '' || !(function_exists('pm_money_amounts') && pm_money_amounts((string)$src['text']))) {
            continue; // known, a video, no words, or nothing for sale in it
        }
        $todo[$k] = $src;
    }
    $found = [];
    if ($todo && function_exists('pm_agents_ready') && pm_agents_ready()) {
        try {
            $was = pm_brand();
            pm_brand_set($brand);
            $rows = array_map(fn($k, $s) => ['id' => $k, 'post' => mb_substr((string)$s['text'], 0, 1200)], array_keys($todo), $todo);
            $sys = pm_agents_company_brief('tiny') . "\nYou turn our own sales posts into shop items. For each post that sells one specific item at a stated price: "
                . 'title (the product\'s real name, at most 80 characters, no emojis, no hype words, e.g. "Gioteck WX4 Wireless Controller for Nintendo Switch"), '
                . 'description (2 to 5 plain sentences from the post\'s facts only: what it is, what it works with, what is in the box or special about it; no emojis, no price, no links, no "inbox us"), '
                . 'price (the number in the post, digits only), brand (the maker named in the post, else empty), condition (new|used, from the post; new when it says new or says nothing), '
                . 'group (a short product type such as "Game controllers", "Gaming headsets"). Skip a post that sells several things or no specific item (sale: false). Facts from the post only. ' . PM_AGENT_RULES;
            $out = pm_agent_json(pm_claude($sys, json_encode($rows, JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"sale\":true,\"title\",\"description\",\"price\",\"brand\",\"condition\",\"group\"}", false, 2500));
            pm_brand_set($was);
            foreach (is_array($out) ? $out : [] as $o) {
                $k = (string)($o['id'] ?? '');
                if (isset($todo[$k]) && !empty($o['sale']) && trim((string)($o['title'] ?? '')) !== '' && (float)preg_replace('/[^\d.]/', '', (string)($o['price'] ?? '')) > 0) {
                    $found[$k] = ['title' => mb_substr(trim(strip_tags((string)$o['title'])), 0, 150), 'description' => mb_substr(trim(strip_tags((string)($o['description'] ?? ''))), 0, 3000),
                        'price' => (float)preg_replace('/[^\d.]/', '', (string)$o['price']), 'brand' => mb_substr(trim((string)($o['brand'] ?? '')), 0, 70),
                        'condition' => isset(PM_SHOP_COND[$o['condition'] ?? '']) ? (string)$o['condition'] : 'new', 'group' => mb_substr(trim((string)($o['group'] ?? '')), 0, 60), 'by' => 'ai', 'picture' => (string)($todo[$k]['picture'] ?? '')];
                }
                unset($todo[$k]); // the AI looked at it: not a single item for sale
            }
        } catch (Throwable) {
            // without the AI this time: by code below
        }
    }
    foreach ($todo as $k => $src) {
        $g = pm_shop_guess((string)$src['text']);
        if ($g['title'] !== '' && $g['price'] > 0) {
            $found[$k] = $g + ['brand' => '', 'condition' => 'new', 'group' => '', 'by' => 'code', 'picture' => (string)($src['picture'] ?? '')];
        }
    }
    $n = 0;
    pm_shop_update($brand, function (array $s) use ($found, $todo, &$n) {
        foreach ($found as $k => $f) {
            $title = strtolower($f['title']);
            if (array_filter(array_merge($s['items'], $s['candidates']), fn($x) => strtolower(trim((string)($x['title'] ?? ''))) === $title)) {
                continue; // the same item from another post
            }
            $s['candidates'][md5($k)] = $f + ['from' => [$k], 'found' => date('Y-m-d H:i')];
            $n++;
        }
        $s['scan_at'] = date('Y-m-d H:i');
        return $s;
    });
    if ($n && function_exists('pm_tb_post')) {
        pm_tb_post($brand, 'social', 'director', "Shop: I found {$n} item" . ($n === 1 ? '' : 's') . ' we sell in our posts that are not in the shop yet. The owner adds them with one press on Social > Shop.', 'shop:found');
    }
    return $n;
}

/** A candidate into the shop: the picture from the post (full size), the words and price as found. Returns [ok, message, id]. */
function pm_shop_add_candidate(string $brand, string $cid): array
{
    $c = pm_shop_state($brand)['candidates'][$cid] ?? null;
    if (!$c) {
        return [false, 'That item is no longer in the list.', ''];
    }
    $src = pm_rp_sources($brand, 100)[(string)($c['from'][0] ?? '')] ?? null;
    $img = '';
    if ($src) {
        [$path] = pm_rp_media($brand, $src, 'shop_' . bin2hex(random_bytes(6)));
        if ($path !== '' && is_file($path)) {
            $img = bin2hex(random_bytes(8)) . '.' . (strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg');
            @rename($path, pm_shop_dir($brand) . '/' . $img) || ($img = '');
        }
    }
    $id = '';
    pm_shop_update($brand, function (array $s) use ($cid, $c, $img, &$id) {
        $id = pm_shop_slug((string)$c['title'], $s['items']);
        $s['items'][$id] = ['title' => $c['title'], 'description' => $c['description'], 'price' => $c['price'], 'sale_price' => '', 'availability' => 'in stock', 'condition' => $c['condition'] ?? 'new',
            'brand' => $c['brand'] ?? '', 'category' => '', 'group' => $c['group'] ?? '', 'image' => $img, 'from' => $c['from'] ?? [], 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i'), 'by' => 'shop desk'];
        unset($s['candidates'][$cid]);
        $s['at'] = date('Y-m-d H:i');
        return $s;
    });
    function_exists('pm_agent_log') && pm_agent_log('Social', 'Shop: added ' . $c['title'] . ($img === '' ? ' (no picture yet)' : ''));
    return [true, $c['title'] . ' is in the shop' . ($img === '' ? ', but its picture could not be fetched: add one.' : '.') . ' Commerce Manager picks it up at its next feed update.', $id];
}

/** Better words for an item (one small AI call): the same facts and price, a clear title and a plain description. Returns [ok, message]. */
function pm_shop_ai_words(string $brand, string $id): array
{
    $it = pm_shop_state($brand)['items'][$id] ?? null;
    if (!$it) {
        return [false, 'That item is not in the shop.'];
    }
    if (!function_exists('pm_agents_ready') || !pm_agents_ready()) {
        return [false, 'The AI needs a key in .env first.'];
    }
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $post = '';
        foreach ((array)($it['from'] ?? []) as $k) { // the post the item came from: its facts (what it works with, what is in the box) are the source
            $post = $post !== '' ? $post : (string)((function_exists('pm_rp_sources') ? pm_rp_sources($brand, 100) : [])[$k]['text'] ?? '');
        }
        $sys = "You write shop listings that sell, for Facebook and Instagram Shops, for " . pm_settings()['company_name'] . ". From the item and the post it came from, and their facts only: "
            . 'title: the product\'s real name with what it works with (e.g. "for PS4", "for Nintendo Switch") when the facts say it, at most 80 characters, no emojis, no hype; description: 3 to 6 short plain sentences about the item only: what it is, '
            . 'what it works with, its features, what makes it a good buy, ending with "Message us to order."; no price, no links, no emojis, nothing about the company, nothing invented. ' . PM_AGENT_RULES;
        for ($try = 0, $t = $d = ''; $try < 2 && ($t === '' || $d === ''); $try++) { // the cheap model now and then answers with nothing usable: one more try
            $o = pm_agent_json(pm_claude($sys, json_encode(['title' => $it['title'], 'description' => $it['description'], 'brand' => $it['brand'] ?? '', 'condition' => $it['condition'] ?? 'new', 'post' => mb_substr($post, 0, 1500)] + ($try ? ['attempt' => $try + 1] : []), JSON_UNESCAPED_UNICODE) . "\nJSON {\"title\",\"description\"}", false, 900));
            $t = mb_substr(trim(strip_tags((string)($o['title'] ?? ''))), 0, 150);
            $d = mb_substr(trim(strip_tags((string)($o['description'] ?? ''))), 0, 3000);
            $t !== '' && $d !== '' && function_exists('pm_money_amounts') && pm_money_amounts($t . ' ' . $d) && $t = ''; // a price in the words: not usable
        }
    } finally {
        pm_brand_set($was);
    }
    if ($t === '' || $d === '') {
        return [false, 'The AI did not return usable words. Try again.'];
    }
    pm_shop_update($brand, function (array $s) use ($id, $t, $d) {
        $s['items'][$id]['title'] = $t;
        $s['items'][$id]['description'] = $d;
        $s['items'][$id]['updated'] = date('Y-m-d H:i');
        return $s;
    });
    return [true, 'New words for ' . $t . '. Read them; change anything you like.'];
}

/** An item as a post in the Plan (a draft for the owner to approve): its picture, words written to sell it with the price, the item's link. Returns [ok, message]. */
function pm_shop_promote(string $brand, string $id): array
{
    $it = pm_shop_state($brand)['items'][$id] ?? null;
    if (!$it || ($it['image'] ?? '') === '' || !is_file(pm_shop_dir($brand) . '/' . $it['image'])) {
        return [false, 'The item needs a picture before it can be posted.'];
    }
    $cur = pm_shop_currency($brand);
    $price = $cur . ' ' . number_format((float)(($it['sale_price'] ?? '') !== '' && (float)$it['sale_price'] > 0 ? $it['sale_price'] : $it['price']));
    $link = pm_shop_item_url($brand, $id);
    $caption = trim((string)$it['title']) . "\n\n" . trim((string)$it['description']) . "\n\n" . $price . ($link !== '' ? "\n\nSee it and order: " . $link : '');
    if (function_exists('pm_regen_words') && function_exists('pm_agents_ready') && pm_agents_ready()) {
        try {
            $was = pm_brand();
            pm_brand_set($brand);
            $ask = 'A short post that sells this item: a first line that stops the scroll (a question or the buyer\'s problem), what it is, two or three reasons to buy, the price exactly as written, and the link exactly as written as the last line.';
            $new = pm_regen_words(['caption' => $caption], 'post', $ask);
            ($new['caption'] ?? '') !== '' && $caption = (string)$new['caption'];
            $probe = fn(string $c) => pm_social_lint_post(['caption' => $c, 'hashtags' => [], 'brand' => $brand, 'format' => 'image', 'allowed_amounts' => pm_money_amounts($c)]);
            if ($left = $probe($caption)) { // it breaks a posting rule (an opening used lately, too long...): one more try, told exactly what to fix
                $again = (string)(pm_regen_words(['caption' => $caption], 'post', $ask . ' Fix these before it can be posted: ' . implode(' ', $left))['caption'] ?? '');
                $again !== '' && count($probe($again)) < count($left) && $caption = $again;
            }
            pm_brand_set($was);
        } catch (Throwable) {
        }
    }
    $pid = bin2hex(random_bytes(10));
    $ext = strtolower(pathinfo((string)$it['image'], PATHINFO_EXTENSION)) ?: 'jpg';
    $dest = pm_social_dir() . '/' . $pid . '.' . $ext;
    if (!@copy(pm_shop_dir($brand) . '/' . $it['image'], $dest)) {
        return [false, 'The picture could not be copied for the post.'];
    }
    $slot = pm_social_free_slot(pm_social_posts(), $brand, time() + 3600);
    $post = ['id' => $pid, 'brand' => $brand, 'status' => 'draft', 'when' => $slot, 'format' => 'image', 'caption' => $caption, 'hashtags' => [], 'media' => pm_social_media_rel($dest),
        'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'script' => '', 'created' => date('Y-m-d H:i'), 'by' => function_exists('pm_social_who') ? (pm_social_who() ?: 'owner') : 'owner',
        'src' => 'shop', 'shop_item' => $id, 'words_by' => 'ai', 'tokens' => 0, 'allowed_amounts' => function_exists('pm_money_amounts') ? pm_money_amounts($caption) : []];
    $post = pm_social_resolve($post, pm_social_lint_post($post));
    pm_social_add_planned([$post], false);
    pm_shop_update($brand, function (array $s) use ($id) {
        $s['items'][$id]['promoted'] = date('Y-m-d H:i');
        return $s;
    });
    return [true, 'A post for ' . $it['title'] . ' is a draft in the Plan for ' . date('D j M, H:i', strtotime($slot)) . '. Read it and approve it.'];
}

/** The shop desk's numbers: items in the shop, visible, needing a fix, out of stock; items found in posts waiting to be added. */
function pm_shop_counts(string $brand): array
{
    $st = pm_shop_state($brand);
    $c = ['items' => count($st['items']), 'live' => 0, 'fix' => 0, 'out' => 0, 'candidates' => count($st['candidates']), 'scan_at' => (string)$st['scan_at'], 'shop_url' => (string)$st['shop_url']];
    foreach ($st['items'] as $it) {
        if (!empty($it['hidden'])) {
            continue;
        }
        $c['live']++;
        $c['fix'] += pm_shop_problems($brand, $it) ? 1 : 0;
        $c['out'] += ($it['availability'] ?? '') === 'out of stock' ? 1 : 0;
    }
    return $c;
}

/* ---------------- forms (Social > Shop) ---------------- */

function pm_do_shop_settings(string $vb): array
{
    $url = trim((string)($_POST['shop_url'] ?? ''));
    $cat = preg_replace('/\D/', '', (string)($_POST['catalog_id'] ?? ''));
    $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['currency'] ?? '')));
    if ($url !== '' && !preg_match('#^https://[^\s]+$#', $url)) {
        return ['msg' => 'The shop link must start with https://', 'kind' => 'err', 'to' => 'social&view=shop'];
    }
    pm_shop_update($vb, fn($s) => ['shop_url' => $url, 'catalog_id' => $cat, 'currency' => strlen($cur) === 3 ? $cur : ''] + $s);
    return ['msg' => 'Shop details saved.', 'kind' => 'ok', 'to' => 'social&view=shop'];
}

function pm_do_shop_item(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    $st = pm_shop_state($vb);
    $new = $id === '';
    if (!$new && !isset($st['items'][$id])) {
        return ['msg' => 'That item is not in the shop.', 'kind' => 'err', 'to' => 'social&view=shop'];
    }
    $t = fn(string $k, int $max) => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max);
    $num = fn(string $k) => ($v = preg_replace('/[^\d.]/', '', str_replace(',', '', (string)($_POST[$k] ?? '')))) === '' ? '' : (float)$v;
    $title = $t('title', 200);
    if ($title === '') {
        return ['msg' => 'Give the item a title.', 'kind' => 'err', 'to' => 'social&view=shop'];
    }
    $img = '';
    $f = $_FILES['image'] ?? null;
    if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $type = @getimagesize((string)$f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type['mime'] ?? ''] ?? '';
        if ($ext === '' || (int)$f['size'] > 8 * 1048576) {
            return ['msg' => 'The picture must be a JPG, PNG or WebP under 8 MB.', 'kind' => 'err', 'to' => 'social&view=shop'];
        }
        $img = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file((string)$f['tmp_name'], pm_shop_dir($vb) . '/' . $img)) {
            return ['msg' => 'The picture could not be saved.', 'kind' => 'err', 'to' => 'social&view=shop'];
        }
    }
    $fields = ['title' => $title, 'description' => $t('description', 5000), 'price' => $num('price') === '' ? 0 : $num('price'), 'sale_price' => $num('sale_price'),
        'availability' => isset(PM_SHOP_AVAIL[$_POST['availability'] ?? '']) ? (string)$_POST['availability'] : 'in stock', 'condition' => isset(PM_SHOP_COND[$_POST['condition'] ?? '']) ? (string)$_POST['condition'] : 'new',
        'brand' => $t('brand', 100), 'category' => $t('category', 250), 'fb_category' => $t('fb_category', 250), 'group' => $t('group', 60), 'hidden' => !empty($_POST['hidden']), 'updated' => date('Y-m-d H:i')];
    $saved = '';
    pm_shop_update($vb, function (array $s) use ($id, $new, $fields, $img, $vb, &$saved) {
        $saved = $new ? pm_shop_slug($fields['title'], $s['items']) : $id;
        $old = (array)($s['items'][$saved] ?? []);
        $img !== '' && !empty($old['image']) && @unlink(pm_shop_dir($vb) . '/' . $old['image']);
        $s['items'][$saved] = $fields + ($img !== '' ? ['image' => $img] : []) + $old + ['image' => '', 'created' => date('Y-m-d H:i'), 'by' => 'owner', 'from' => []];
        $s['at'] = date('Y-m-d H:i');
        return $s;
    });
    $p = pm_shop_problems($vb, pm_shop_state($vb)['items'][$saved] ?? []);
    return ['msg' => ($new ? 'Added ' : 'Saved ') . $title . '.' . ($p ? ' Before Facebook shows it: ' . implode(' ', $p) : ' It is ready for the shop.'), 'kind' => $p ? 'err' : 'ok', 'to' => 'social&view=shop#it-' . $saved];
}

function pm_do_shop_add(string $vb): array
{
    @set_time_limit(120);
    [$ok, $msg, $id] = pm_shop_add_candidate($vb, (string)($_POST['cid'] ?? ''));
    return ['msg' => $msg, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=shop' . ($id !== '' ? '#it-' . $id : '')];
}

function pm_do_shop_add_all(string $vb): array
{
    @set_time_limit(300);
    $n = 0;
    foreach (array_keys(pm_shop_state($vb)['candidates']) as $cid) {
        $n += pm_shop_add_candidate($vb, (string)$cid)[0] ? 1 : 0;
    }
    return ['msg' => "$n item" . ($n === 1 ? '' : 's') . ' added to the shop. Check each one, then give Commerce Manager the feed address.', 'kind' => $n ? 'ok' : 'err', 'to' => 'social&view=shop'];
}

function pm_do_shop_skip(string $vb): array
{
    $cid = (string)($_POST['cid'] ?? '');
    pm_shop_update($vb, function (array $s) use ($cid) {
        if (isset($s['candidates'][$cid])) {
            $s['skipped'] = array_slice(array_merge((array)($s['skipped'] ?? []), (array)($s['candidates'][$cid]['from'] ?? [])), -200);
            unset($s['candidates'][$cid]);
        }
        return $s;
    });
    return ['msg' => 'Left out of the shop.', 'kind' => 'ok', 'to' => 'social&view=shop'];
}

function pm_do_shop_scan(string $vb): array
{
    @set_time_limit(180);
    $n = pm_shop_scan($vb);
    return ['msg' => $n ? "Found $n item" . ($n === 1 ? '' : 's') . ' we sell in our posts that are not in the shop yet.' : 'Nothing new for the shop in our posts.', 'kind' => 'ok', 'to' => 'social&view=shop'];
}

function pm_do_shop_ai(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    [$ok, $msg] = pm_shop_ai_words($vb, $id);
    return ['msg' => $msg, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=shop#it-' . $id];
}

function pm_do_shop_promote(string $vb): array
{
    @set_time_limit(120);
    [$ok, $msg] = pm_shop_promote($vb, (string)($_POST['id'] ?? ''));
    return ['msg' => $msg, 'kind' => $ok ? 'ok' : 'err', 'to' => $ok ? 'social' : 'social&view=shop'];
}

function pm_do_shop_delete(string $vb): array
{
    $id = (string)($_POST['id'] ?? '');
    pm_shop_update($vb, function (array $s) use ($id, $vb) {
        if (!empty($s['items'][$id]['image'])) {
            @unlink(pm_shop_dir($vb) . '/' . $s['items'][$id]['image']);
        }
        unset($s['items'][$id]);
        return $s;
    });
    return ['msg' => 'Taken out of the shop: Commerce Manager removes it at its next feed update.', 'kind' => 'ok', 'to' => 'social&view=shop'];
}

/* ---------------- the scheduler and the other screens ---------------- */

/** Scheduler job: once a day the shop desk looks through our posts for items not in the shop yet (one small AI call only when there are new posts with a price). */
function pm_job_shop(string $brand): string
{
    $st = pm_shop_state($brand);
    if (!$st['items'] && !$st['candidates'] && $st['scan_at'] === '' && $brand !== 'promanaged') {
        return ''; // a business that sells nothing in its posts: the owner starts the shop from Social > Shop
    }
    if ((strtotime($st['scan_at']) ?: 0) > time() - 20 * 3600 || (int)date('G') < 8) {
        return '';
    }
    $n = pm_shop_scan($brand);
    return $n ? "shop: $n new item" . ($n === 1 ? '' : 's') . ' found in our posts' : '';
}

/** Social > Plan, at the top: the shop in one line, when there is something to do. */
function pm_panel_plan_top_shop(string $vb, array $ctx = []): string
{
    $c = pm_shop_counts($vb);
    if (!$c['candidates'] && !$c['fix']) {
        return '';
    }
    return '<div class="card"><p class="mt-0"><b>Shop:</b> ' . ($c['candidates'] ? '<a href="?tab=social&amp;view=shop">' . $c['candidates'] . ' item' . ($c['candidates'] === 1 ? '' : 's') . ' from our posts to add</a>. ' : '')
        . ($c['fix'] ? $c['fix'] . ' item' . ($c['fix'] === 1 ? ' needs' : 's need') . ' a fix before Facebook shows ' . ($c['fix'] === 1 ? 'it' : 'them') . '.' : '') . '</p></div>';
}
