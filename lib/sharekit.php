<?php
/**
 * The share kit: what a business hands to the people it writes to. PDFs uploaded once (a host starter pack, a brochure) and the important pages of its own
 * website (list your property, how it works, contact), kept per business in data/share_kit.json, with the files in data/share/<business>/.
 *
 *   emails     a PDF marked "attach" goes with every first email, and a short "Direct links" block (the PDF's link and the pages switched on) is added under the message
 *   WhatsApp   a chat link cannot carry a file, so the first message gets the same links (the PDF opens from doc.php on the app's own address)
 *   replies    an answer to someone who asks for the pack can attach it in one tick; quick replies hold the links
 *
 * The links come from the business's own website (pm_share_scan): the page's links, its sitemap and, for a site that draws itself in the browser (a single-page app),
 * the routes in its script. Only public pages of that site are kept; pages for staff, bookings, accounts and surveys are left out. The owner sees every link,
 * renames it, switches it on or off and adds their own. Nothing here makes up a link: each one was read from the site or typed by the owner.
 */

const PM_SHARE_MAX_BYTES = 10 * 1024 * 1024;
const PM_SHARE_ROLES = ['pack' => 'Starter pack or guide', 'signup' => 'Sign up or list', 'how' => 'How it works', 'contact' => 'Contact', 'about' => 'About', 'faq' => 'Questions', 'other' => 'Other'];
const PM_SHARE_AUTO_ON = ['pack', 'signup', 'how'];
const PM_SHARE_EMAIL_MAX = 4;
const PM_SHARE_WA_MAX = 3;
const PM_SHARE_LINKS_MAX = 14;

/* ---------------- storage ---------------- */

function pm_share_norm(array $k): array
{
    $docs = array_values(array_filter((array)($k['docs'] ?? []), fn($d) => is_array($d) && preg_match('/^[a-f0-9]{20}$/', (string)($d['id'] ?? '')) === 1));
    $links = array_values(array_filter((array)($k['links'] ?? []), fn($l) => is_array($l) && pm_clean_url((string)($l['url'] ?? '')) !== ''));
    return ['docs' => $docs, 'links' => $links, 'scan' => (array)($k['scan'] ?? []), 'check' => (array)($k['check'] ?? [])];
}

/** One business's kit: ['docs' => [...], 'links' => [...], 'scan' => [...], 'check' => [...]]. */
function pm_share(string $brand = ''): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    return pm_share_norm((array)(pm_load('share_kit', fn() => [])[$brand] ?? []));
}

function pm_share_update(string $brand, callable $fn): void
{
    pm_update('share_kit', function (array $all) use ($brand, $fn) {
        $all[$brand] = $fn(pm_share_norm((array)($all[$brand] ?? [])));
        return $all;
    }, fn() => []);
}

function pm_share_dir(string $brand): string
{
    return PM_DATA . '/share/' . preg_replace('/[^a-z0-9]/', '', $brand);
}

/* ---------------- documents (PDFs) ---------------- */

/** The doc with this id in any business, with its business: [brand, doc] or null. Used by the public page doc.php. */
function pm_share_find_doc(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{20}$/', $id)) {
        return null;
    }
    foreach (pm_load('share_kit', fn() => []) as $brand => $k) {
        foreach (pm_share_norm((array)$k)['docs'] as $d) {
            if ($d['id'] === $id) {
                return [(string)$brand, $d];
            }
        }
    }
    return null;
}

function pm_share_doc_path(array $d): string
{
    $f = (string)($d['file'] ?? '');
    return preg_match('#^[a-z0-9]{2,16}/[a-f0-9]{20}\.pdf$#', $f) === 1 ? PM_DATA . '/share/' . $f : '';
}

/** The name the PDF carries when it is attached or downloaded: "Host-starter-pack.pdf". */
function pm_share_friendly_name(string $title): string
{
    $s = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title), '-');
    return ($s !== '' ? ucfirst($s) : 'Document') . '.pdf';
}

/** The address people open the PDF from: the owner's own link when one is given, else this app's public page. '' when there is neither. */
function pm_share_doc_url(array $d): string
{
    $u = pm_clean_url((string)($d['url'] ?? ''));
    if ($u !== '') {
        return $u;
    }
    $app = pm_app_url();
    $p = pm_share_doc_path($d);
    return $app !== '' && $p !== '' && is_file($p) ? $app . '/doc.php?d=' . $d['id'] : '';
}

/** Stores a PDF from a path. [ok, message, id]. */
function pm_share_add_file(string $brand, string $path, string $origName, string $title, bool $attach, bool $moveUploaded = false): array
{
    if (!pm_brand_valid($brand)) {
        return [false, 'Choose a business first.', ''];
    }
    $size = (int)@filesize($path);
    if ($size < 100) {
        return [false, 'That file is empty.', ''];
    }
    if ($size > PM_SHARE_MAX_BYTES) {
        return [false, 'That PDF is ' . round($size / 1048576, 1) . ' MB. Keep it under ' . (PM_SHARE_MAX_BYTES / 1048576) . ' MB (a PDF made with "Save as PDF" is usually well under 1 MB; compress a large one first).', ''];
    }
    if ((string)@file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
        return [false, 'That is not a PDF. In your browser use Print > Save as PDF, then upload the file.', ''];
    }
    $title = trim($title) !== '' ? mb_substr(trim($title), 0, 80) : mb_substr(ucfirst(trim((string)preg_replace('/[-_]+/', ' ', pathinfo($origName, PATHINFO_FILENAME)))), 0, 80);
    $title = $title !== '' ? $title : 'Document';
    $dir = pm_share_dir($brand);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return [false, 'The folder for the file could not be created. Check that PHP may write to data/.', ''];
    }
    $id = bin2hex(random_bytes(10));
    $dest = $dir . '/' . $id . '.pdf';
    if (!($moveUploaded ? @move_uploaded_file($path, $dest) : @copy($path, $dest))) {
        return [false, 'The file could not be saved. Check that PHP may write to data/.', ''];
    }
    $role = preg_match('/start|guide|pack|onboard|welcome|leaflet|brochure|toolkit/i', $title) ? 'pack' : 'other';
    pm_share_update($brand, function (array $k) use ($id, $title, $origName, $size, $attach, $role, $brand) {
        $k['docs'][] = ['id' => $id, 'title' => $title, 'file' => preg_replace('/[^a-z0-9]/', '', $brand) . '/' . $id . '.pdf', 'orig' => mb_substr(basename($origName), 0, 120), 'size' => $size,
            'attach' => $attach, 'link' => true, 'role' => $role, 'url' => '', 'added' => date('Y-m-d H:i')];
        return $k;
    });
    return [true, '', $id];
}

/** The upload form's file (one PDF). [ok, message]. */
function pm_share_upload(string $brand): array
{
    $f = (array)($_FILES['pdf'] ?? []);
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE || empty($f['tmp_name'])) {
        return [false, 'Choose a PDF first.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [false, $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'That file is bigger than this server accepts (upload_max_filesize is ' . ini_get('upload_max_filesize') . ').' : 'The upload did not finish. Try again.'];
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) {
        return [false, 'The upload was not accepted.'];
    }
    [$ok, $msg, $id] = pm_share_add_file($brand, (string)$f['tmp_name'], (string)($f['name'] ?? 'document.pdf'), trim((string)($_POST['title'] ?? '')), !empty($_POST['attach']), true);
    if (!$ok) {
        return [false, $msg];
    }
    $d = pm_share_get_doc($brand, $id);
    $line = 'Uploaded. ';
    if ($d && pm_share_doc_url($d) === '') {
        $line .= 'It will be attached to emails. For WhatsApp it needs a web link: set APP_URL in .env, or paste a link to a copy of it on your website.';
    } elseif ($d) {
        [$up, $why] = pm_share_url_check(pm_share_doc_url($d));
        $line .= $up ? 'Its link opens from the internet, so emails and WhatsApp messages can use it.' : 'Its link does not open from the internet yet (' . $why . '). If the app runs on a website, copy the data folder there; or paste a link to a copy of it on your website below.';
    }
    return [true, $line];
}

function pm_share_get_doc(string $brand, string $id): ?array
{
    foreach (pm_share($brand)['docs'] as $d) {
        if ($d['id'] === $id) {
            return $d;
        }
    }
    return null;
}

/** Can this address be opened from here? [ok, why]. HEAD first, then a tiny GET (some hosts refuse HEAD). */
function pm_share_url_check(string $url): array
{
    if (isset($GLOBALS['PM_SHARE_CHECK_STUB']) && is_callable($GLOBALS['PM_SHARE_CHECK_STUB'])) {
        return (array)$GLOBALS['PM_SHARE_CHECK_STUB']($url);
    }
    if (pm_clean_url($url) === '') {
        return [false, 'no address'];
    }
    if (function_exists('pm_url_public') && pm_url_public($url) === null) {
        return [false, 'that address is not on the public internet, so a lead could not open it either'];
    }
    foreach ([true, false] as $head) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => $head, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LinkCheck)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $dn) => $dn > 300000 ? 1 : 0, CURLOPT_NOPROGRESS => false]);
        function_exists('pm_curl_native_ca') && pm_curl_native_ca($ch);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return [true, ''];
        }
        if ($head && $code !== 0 && $code !== 405 && $code !== 403) {
            return [false, $code === 404 ? 'the page was not found there' : 'the server said ' . $code];
        }
        if (!$head) {
            return [false, $code ? ($code === 404 ? 'the page was not found there' : 'the server said ' . $code) : ($errno === 6 ? 'that address could not be found' : 'no answer')];
        }
    }
    return [false, 'no answer'];
}

/* ---------------- what goes into a message ---------------- */

function pm_share_url_key(string $u): string
{
    return strtolower(rtrim((string)preg_replace('#^https?://(www\.)?#i', '', trim($u)), '/'));
}

/** The business's main website link, as it is already added to messages. */
function pm_share_main_url(string $brand): string
{
    return pm_link_cfg($brand)['url'];
}

/** The pieces a message may carry, best first: ['label','url','doc' => id|'','role']. Documents with a web address, then the pages switched on; no repeats, never the main website. */
function pm_share_items(string $brand = ''): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $k = pm_share($brand);
    $rank = array_flip(['pack', 'signup', 'how', 'contact', 'about', 'faq', 'other']);
    $rows = [];
    foreach ($k['docs'] as $d) {
        $u = !empty($d['link']) ? pm_share_doc_url($d) : '';
        if ($u !== '') {
            $rows[] = ['label' => (string)$d['title'] . ' (PDF)', 'url' => $u, 'doc' => (string)$d['id'], 'role' => (string)($d['role'] ?? 'other'), 'w' => 0];
        }
    }
    foreach ($k['links'] as $l) {
        if (!empty($l['on'])) {
            $rows[] = ['label' => (string)$l['label'], 'url' => pm_clean_url((string)$l['url']), 'doc' => '', 'role' => (string)($l['role'] ?? 'other'), 'w' => 1];
        }
    }
    usort($rows, fn($a, $b) => [$rank[$a['role']] ?? 9, $a['w']] <=> [$rank[$b['role']] ?? 9, $b['w']]);
    $packDoc = count(array_filter($rows, fn($r) => $r['w'] === 0 && $r['role'] === 'pack')) > 0;
    $rows = array_values(array_filter($rows, fn($r) => !($packDoc && $r['w'] === 1 && $r['role'] === 'pack'))); // a PDF of the pack is shared: its web page is not repeated beside it
    $seen = [pm_share_url_key(pm_share_main_url($brand)) => 1];
    $out = [];
    foreach ($rows as $r) {
        $key = pm_share_url_key($r['url']);
        if ($key !== '' && !isset($seen[$key])) {
            $seen[$key] = 1;
            unset($r['w']);
            $out[] = $r;
        }
    }
    return $out;
}

/** PDFs that go with a first email: [[path, friendly name, title]]. Only files that exist and are small enough for a mailbox to accept. */
function pm_share_attachments(string $brand = '', ?array $ids = null): array
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $out = [];
    foreach (pm_share($brand)['docs'] as $d) {
        if (($ids === null ? empty($d['attach']) : !in_array($d['id'], $ids, true)) || ($p = pm_share_doc_path($d)) === '' || !is_file($p) || filesize($p) > PM_SHARE_MAX_BYTES) {
            continue;
        }
        $out[] = [$p, pm_share_friendly_name((string)$d['title']), (string)$d['title']];
    }
    return $out;
}

/** The lines added under a first email (before the sign-off): what is attached, then the direct links. '' when the kit is empty. $body: links it already carries are not repeated. */
function pm_share_email_block(string $body = '', string $brand = ''): string
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $parts = [];
    if ($att = pm_share_attachments($brand)) {
        $parts[] = 'Attached: ' . implode(', ', array_map(fn($a) => $a[2] . ' (PDF)', $att)) . '.';
    }
    $lines = [];
    foreach (pm_share_items($brand) as $it) {
        if (count($lines) < PM_SHARE_EMAIL_MAX && !str_contains($body, $it['url'])) {
            $lines[] = $it['label'] . ': ' . $it['url'];
        }
    }
    if ($lines) {
        $parts[] = "Direct links:\n" . implode("\n", $lines);
    }
    return $parts ? "\n\n" . implode("\n\n", $parts) : '';
}

/** The lines added to a first WhatsApp message: the first few direct links. '' when there are none. */
function pm_share_wa_block(string $text = '', string $brand = ''): string
{
    $lines = [];
    foreach (pm_share_items($brand) as $it) {
        if (count($lines) < PM_SHARE_WA_MAX && !str_contains($text, $it['url'])) {
            $lines[] = $it['label'] . ': ' . $it['url'];
        }
    }
    return $lines ? "\n\n" . implode("\n", $lines) : '';
}

/** 'attached' (a pack PDF goes with the email), 'linked' (a pack can be opened from a link), or 'none'. */
function pm_share_pack_state(string $brand = ''): string
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $k = pm_share($brand);
    foreach ($k['docs'] as $d) {
        if (($d['role'] ?? '') === 'pack' && !empty($d['attach']) && ($p = pm_share_doc_path($d)) !== '' && is_file($p)) {
            return 'attached';
        }
    }
    foreach ($k['docs'] as $d) {
        if (($d['role'] ?? '') === 'pack' && !empty($d['link']) && pm_share_doc_url($d) !== '') {
            return 'linked';
        }
    }
    foreach ($k['links'] as $l) {
        if (($l['role'] ?? '') === 'pack' && !empty($l['on'])) {
            return 'linked';
        }
    }
    return 'none';
}

/** The pack's name for the words around it ("host starter pack"): the first pack document's title, else the first pack link's label, else 'pack'. */
function pm_share_pack_name(string $brand = ''): string
{
    $k = pm_share($brand);
    foreach ($k['docs'] as $d) {
        if (($d['role'] ?? '') === 'pack') {
            return (string)$d['title'];
        }
    }
    foreach ($k['links'] as $l) {
        if (($l['role'] ?? '') === 'pack' && !empty($l['on'])) {
            return (string)$l['label'];
        }
    }
    return 'pack';
}

/** Short answers that hold a link, for the WhatsApp quick replies: ['Send: <label>' => message]. */
function pm_share_quick_replies(string $brand = ''): array
{
    $out = [];
    foreach (array_slice(pm_share_items($brand), 0, 5) as $it) {
        $name = trim((string)preg_replace('/\s*\(PDF\)$/', '', $it['label']));
        $out['Send: ' . $name] = 'Here is ' . (str_starts_with(strtolower($name), 'the ') ? '' : 'the ') . $name . ($it['doc'] !== '' ? ' (a PDF)' : '') . ': ' . $it['url'] . "\nTell me if anything is unclear and I will help.";
    }
    return $out;
}

/** The docs a lead is asking for in a message ("please send the starter guide"): ids of the brand's documents. Only a plain request for the pack or a document, never a guess. */
function pm_share_wanted_docs(string $brand, string $text): array
{
    if (!preg_match('/\b(starter|guide|pack|brochure|leaflet|pdf|attachment|document|send (me|us) (the |your |some )?(details|info|information))\b/i', mb_substr($text, 0, 1500))) {
        return [];
    }
    $ids = [];
    foreach (pm_share($brand)['docs'] as $d) {
        if (($d['role'] ?? '') === 'pack' && pm_share_doc_path($d) !== '' && is_file(pm_share_doc_path($d))) {
            $ids[] = (string)$d['id'];
        }
    }
    return array_slice($ids, 0, 1);
}

/** An answer to someone who asked for a document, with the document named: the email says it is attached, the WhatsApp answer carries its link. [email body, whatsapp text]. */
function pm_share_reply_text(string $brand, array $ids, string $body, string $wa): array
{
    $att = pm_share_attachments($brand, $ids);
    if ($body !== '' && $att) {
        $line = 'Attached: ' . implode(', ', array_map(fn($a) => $a[2] . ' (PDF)', $att)) . '.';
        $body = str_contains($body, $line) ? $body : rtrim($body) . "\n\n" . $line;
    }
    foreach ($ids as $id) {
        $d = pm_share_get_doc($brand, (string)$id);
        $u = $d ? pm_share_doc_url($d) : '';
        if ($wa !== '' && $u !== '' && !str_contains($wa, $u)) {
            $wa = rtrim($wa) . "\n" . $d['title'] . ' (PDF): ' . $u;
        }
    }
    return [$body, $wa];
}

/** The reply box's extras. $part 'email': a tick box per PDF to attach (ticked: $picked). $part 'wa': a button per document or page that adds its link to the WhatsApp answer. '' when the kit is empty. */
function pm_share_reply_controls(string $brand, array $picked = [], string $part = 'email'): string
{
    $h = '';
    foreach ($part === 'email' ? pm_share($brand)['docs'] : [] as $d) {
        if (($p = pm_share_doc_path($d)) !== '' && is_file($p)) {
            $h .= '<label class="check"><input type="checkbox" name="attach[]" value="' . pm_h((string)$d['id']) . '"' . (in_array($d['id'], $picked, true) ? ' checked' : '') . '> Attach ' . pm_h((string)$d['title']) . ' (PDF) to the email</label>';
        }
    }
    $chips = '';
    foreach ($part === 'wa' ? array_slice(pm_share_items($brand), 0, 5) : [] as $it) {
        $chips .= '<button type="button" class="btn small ghost" data-ins="' . pm_h($it['label'] . ': ' . $it['url']) . '">+ ' . pm_h($it['label']) . '</button> ';
    }
    return $h . ($chips !== '' ? '<div class="btns" aria-label="Add a link to the WhatsApp answer"><span class="hint">Add to WhatsApp:</span> ' . $chips . '</div>' : '');
}

/** A hint for the AI that writes replies: which documents exist, and that the app attaches them. '' when none. */
function pm_share_reply_hint(string $brand = ''): string
{
    $titles = array_map(fn($d) => (string)$d['title'], array_filter(pm_share($brand)['docs'], fn($d) => ($d['role'] ?? '') === 'pack' && pm_share_doc_path($d) !== ''));
    return $titles ? ' We can send this PDF: "' . implode('", "', $titles) . '". If they ask for it, or for help getting started, say it is attached (email) or that the link follows (WhatsApp): the app adds the file and the link, so do not write a link yourself.' : '';
}

/** What the owner sees under a draft: what will be added when it is sent. '' when nothing. */
function pm_share_added_hint(string $channel, string $brand = ''): string
{
    $brand = $brand !== '' ? $brand : pm_brand();
    $text = $channel === 'whatsapp' ? pm_share_wa_block('', $brand) : pm_share_email_block('', $brand);
    if (trim($text) === '') {
        return '';
    }
    return '<details class="aibox"><summary>Added when sent</summary><pre class="t-sm">' . pm_h(trim($text)) . '</pre><p class="hint">From the share kit of this business (Operations &gt; Business details). Change it there.</p></details>';
}

/* ---------------- reading the website ---------------- */

/** The address of the business's website: the link added to messages, else the website in its details. */
function pm_share_site(string $brand): string
{
    $u = pm_link_cfg($brand)['url'];
    if ($u !== '') {
        return $u;
    }
    return pm_study_url((string)(pm_brand_block(pm_settings(), $brand)['website'] ?? ''));
}

/** A readable name from a path: "/host-starter-pack" -> "Host starter pack". */
function pm_share_slug_label(string $path): string
{
    $s = trim((string)preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename(rtrim($path, '/'))), '-_ ');
    return ucfirst(strtolower(trim((string)preg_replace('/[-_]+/', ' ', rawurldecode($s)))));
}

/** Which kind of page this is: [role, score], or ['', 0] for a page that is not for the people we write to (staff, accounts, bookings, surveys, legal). */
function pm_share_classify(string $path, string $label = ''): array
{
    $p = strtolower(rawurldecode($path));
    $s = $p . ' ' . strtolower($label);
    if (preg_match('#(admin|dashboard|log-?in|sign-?in|register|cart|checkout|profile|account|password|my-|mybooking|booking|trips?\b|saved|\buat\b|survey|concept|validation|marketing|operations|internal|privacy|terms|cookie|refund|wp-|/api/|/cdn|/feed|/tag/|/category/|\.(jpe?g|png|gif|svg|webp|zip|css|js|xml|json)$)#', $s)) {
        return ['', 0];
    }
    if (preg_match('#(starter|host[- ]?guide|onboarding|welcome[- ]pack|brochure|leaflet|toolkit|listing[- ]guide|\.pdf$)#', $s)) {
        return ['pack', 5];
    }
    if (preg_match('#(how[- ](it|hosting|to|we)|getting[- ]started|get[- ]started|how[- ].{0,12}works)#', $s)) {
        return ['how', 3];
    }
    if (preg_match('#(list[- ]your|become[- ]a[- ]?host|for[- ]hosts|get[- ]listed|add[- ]your|partner|join|apply|sign[- ]?up)#', $s)) {
        return ['signup', 4];
    }
    if (preg_match('#(contact|reach[- ]us|get[- ]in[- ]touch|support|\bhelp\b)#', $s)) {
        return ['contact', 2];
    }
    if (preg_match('#(about|who[- ]we|our[- ]story)#', $s)) {
        return ['about', 1];
    }
    if (preg_match('#(faq|questions)#', $s)) {
        return ['faq', 1];
    }
    return ['', 0];
}

/** Same-site address with no fragment and no trailing slash, or '' (another site, the home page, a file that is not a page). */
function pm_share_page_url(string $href, string $base, string $host): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || preg_match('#^(mailto:|tel:|javascript:|whatsapp:|data:|\#)#i', $href)) {
        return '';
    }
    $abs = pm_url_join($base, $href);
    $abs = preg_replace('/[#?].*$/', '', $abs);
    if (!preg_match('#^https?://#i', (string)$abs) || pm_study_host((string)$abs) !== $host) {
        return '';
    }
    $abs = rtrim((string)$abs, '/');
    return strlen((string)parse_url($abs, PHP_URL_PATH)) > 1 ? $abs : '';
}

/**
 * Reads the website and merges what it finds into the kit: new pages are added (the pack, sign-up and how-it-works pages switched on, the rest off), pages the owner
 * already has keep their name and switch. Returns ['ok', 'note', 'found', 'added']. $GLOBALS['PM_PAGE_STUB'] (tests) replaces the network as for pm_study_fetch.
 */
function pm_share_scan(string $brand): array
{
    $site = pm_share_site($brand);
    $res = ['ok' => false, 'note' => '', 'found' => 0, 'added' => 0];
    $scan = fn(array $x) => pm_share_update($brand, function (array $k) use ($x) {
        $k['scan'] = $x + ['at' => date('Y-m-d H:i')];
        return $k;
    });
    if ($site === '') {
        $res['note'] = 'Add the website address first (the link in messages, above).';
        return $res;
    }
    $home = pm_study_fetch($site);
    if (!$home['ok'] || trim($home['html']) === '') {
        $res['note'] = 'We could not open ' . pm_study_host($site) . ' (' . ($home['why'] ?: 'no content') . ').';
        $scan(['url' => $site, 'ok' => false, 'note' => $res['note'], 'found' => 0]);
        return $res;
    }
    $base = $home['url'];
    $host = pm_study_host($base);
    $html = (string)$home['html'];
    $cand = []; // url => ['label', 'from']
    $add = function (string $u, string $label, string $from) use (&$cand) {
        $u !== '' && !isset($cand[$u]) && ($cand[$u] = ['label' => trim((string)preg_replace('/\s+/', ' ', strip_tags($label))), 'from' => $from]);
        $u !== '' && $label !== '' && ($cand[$u]['label'] ?? '') === '' && ($cand[$u]['label'] = trim((string)preg_replace('/\s+/', ' ', strip_tags($label))));
    };
    $anchors = 0;
    if (preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $am, PREG_SET_ORDER)) {
        foreach ($am as $m) {
            $href = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('#\.pdf([?\#]|$)#i', $href) && preg_match('#^(https?://|/|[a-z0-9])#i', $href)) { // a PDF the site already links, wherever it is kept
                $anchors++;
                $abs = pm_url_join($base, $href);
                preg_match('#^https?://#i', $abs) && $add($abs, (string)$m[2], 'pdf');
                continue;
            }
            if (($u = pm_share_page_url($href, $base, $host)) !== '') {
                $anchors++;
                $add($u, (string)$m[2], 'link');
            }
        }
    }
    $root = rtrim((string)preg_replace('#^(https?://[^/]+).*$#i', '$1', $base), '/');
    $sm = pm_study_fetch($root . '/sitemap.xml');
    $smOk = $sm['ok'] && preg_match('#<(urlset|sitemapindex)\b#i', $sm['html']);
    if ($smOk && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $sm['html'], $lm)) {
        foreach (array_slice($lm[1], 0, 80) as $loc) {
            ($u = pm_share_page_url($loc, $base, $host)) !== '' && $add($u, '', 'sitemap');
        }
    }
    // A site drawn in the browser (few links in the page itself): its routes are in its script. Aliases of one page share one component, so keep the best name of each.
    $spa = $anchors < 6 && preg_match('#<div[^>]+id=["\'](root|app|__next)["\']#i', $html) === 1;
    $labels = []; // every name the script gives a route (menu entries and breadcrumbs)
    $menu = []; // the menu entries only
    $groups = [];
    if ($spa) {
        $scripts = [];
        if (preg_match_all('#<(?:script[^>]+src|link[^>]+rel=["\']modulepreload["\'][^>]+href)=["\']([^"\']+\.m?js[^"\']*)["\']#i', $html, $sm2)) {
            foreach (array_slice(array_unique($sm2[1]), 0, 3) as $src) {
                $su = pm_url_join($base, html_entity_decode($src));
                pm_study_host($su) === $host && $scripts[] = $su;
            }
        }
        foreach ($scripts as $su) {
            $js = pm_study_fetch($su, 9000000);
            if (!$js['ok']) {
                continue;
            }
            $code = (string)$js['html'];
            if (preg_match_all('/\{path:"([a-z0-9][a-z0-9\-_\/]*)",element:[\w$.]+\(\s*([\w$]+)/i', $code, $rm, PREG_SET_ORDER)) {
                foreach ($rm as $m) {
                    $groups[$m[2]][] = '/' . $m[1];
                }
            }
            if (preg_match_all('/\{label:"([^"]{2,50})",to:"(\/[a-z0-9\-_\/]*)"\}/i', $code, $lb, PREG_SET_ORDER)) {
                foreach ($lb as $m) {
                    $labels[$m[2]] = $menu[$m[2]] = $m[1];
                }
            }
            if (preg_match_all('/((?:\w+==="\/[a-z0-9\-_\/]+"(?:\|\|)?)+)\?\[[^\]]{0,300}?\{label:"([^"]{2,60})"\}\]/i', $code, $bm, PREG_SET_ORDER)) {
                foreach ($bm as $m) {
                    preg_match_all('#"(/[a-z0-9\-_/]+)"#i', $m[1], $pm);
                    foreach ($pm[1] as $pp) {
                        $labels[$pp] = $m[2];
                    }
                }
            }
        }
        foreach ($groups as $paths) {
            $paths = array_values(array_unique($paths));
            foreach ($paths as $pp) { // one alias that is for staff or surveys makes the whole page one
                if (pm_share_classify($pp, $labels[$pp] ?? '')[0] === '' && preg_match('#(admin|dashboard|uat|survey|concept|marketing|operations|booking|trips|saved|profile|terms|privacy|refund)#i', $pp)) {
                    continue 2;
                }
            }
            usort($paths, fn($a, $b) => [pm_share_classify($b, $labels[$b] ?? '')[1], strlen($b)] <=> [pm_share_classify($a, $labels[$a] ?? '')[1], strlen($a)]);
            $best = $paths[0];
            $add($root . $best, $labels[$best] ?? '', 'route');
        }
        $aliased = array_merge(...array_values($groups ?: [[]]));
        foreach ($menu as $pp => $lb) { // a link in the menu that is not an alias of a page already taken
            in_array(ltrim($pp, '/'), array_map(fn($x) => ltrim($x, '/'), $aliased), true) || $add($root . $pp, $lb, 'route');
        }
    }
    // Only pages for the people we write to, best first; verify on a normal site (a site drawn in the browser answers every address, so its routes are trusted)
    $catchAll = false;
    if (!$spa) {
        $probe = pm_study_fetch($root . '/pm-' . bin2hex(random_bytes(4)));
        $catchAll = $probe['ok'] && abs(strlen($probe['html']) - strlen($html)) < max(200, strlen($html) * 0.05);
    }
    $keep = [];
    foreach ($cand as $u => $c) {
        $path = (string)parse_url($u, PHP_URL_PATH);
        [$role, $score] = pm_share_classify($path, (string)$c['label']);
        if ($c['from'] === 'pdf' && $role === '') {
            [$role, $score] = ['pack', 4]; // a PDF the site links is a document it wants shared
        }
        if ($role !== '') {
            $keep[$u] = ['role' => $role, 'score' => $score, 'label' => $c['label'], 'from' => $c['from']];
        }
    }
    uasort($keep, fn($a, $b) => $b['score'] <=> $a['score']);
    $keep = array_slice($keep, 0, 12, true);
    $found = [];
    foreach ($keep as $u => $c) {
        $label = $c['label'];
        if ($c['from'] !== 'pdf' && !$spa && !$catchAll) {
            $pg = pm_study_fetch($u);
            if (!$pg['ok']) {
                continue; // a link the site no longer answers is not shared
            }
            if ($label === '' || mb_strlen($label) > 60) {
                $t = pm_study_text((string)$pg['html'])['title'];
                $label = trim((string)preg_split('/\s[|\x{2013}\x{2014}-]\s/u', $t)[0]);
            }
        }
        $label = trim($label) !== '' && mb_strlen($label) <= 60 ? trim($label) : pm_share_slug_label((string)parse_url($u, PHP_URL_PATH));
        $found[$u] = ['label' => mb_substr($label, 0, 60), 'role' => $c['role']];
    }
    $added = 0;
    $onByRole = [];
    pm_share_update($brand, function (array $k) use ($found, &$added, &$onByRole, $site, $spa) {
        $have = [];
        foreach ($k['links'] as $l) {
            $have[pm_share_url_key((string)$l['url'])] = 1;
            if (!empty($l['on'])) {
                $onByRole[$l['role'] ?? 'other'] = true;
            }
        }
        foreach ($found as $u => $f) {
            if (isset($have[pm_share_url_key($u)])) {
                continue;
            }
            $on = in_array($f['role'], PM_SHARE_AUTO_ON, true) && empty($onByRole[$f['role']]);
            $onByRole[$f['role']] = $onByRole[$f['role']] ?? $on;
            $k['links'][] = ['id' => substr(md5($u), 0, 10), 'label' => $f['label'], 'url' => $u, 'role' => $f['role'], 'on' => $on, 'auto' => true];
            $added++;
        }
        $k['links'] = array_slice($k['links'], 0, PM_SHARE_LINKS_MAX * 2);
        $k['scan'] = ['at' => date('Y-m-d H:i'), 'url' => $site, 'ok' => true, 'found' => count($found), 'spa' => $spa, 'note' => ''];
        return $k;
    });
    $res['ok'] = true;
    $res['found'] = count($found);
    $res['added'] = $added;
    $res['note'] = $found ? 'Read ' . pm_study_host($site) . ': ' . count($found) . ' page' . (count($found) === 1 ? '' : 's') . ' for the people you write to' . ($added ? ", $added new" : ', nothing new') . '.'
        : 'Read ' . pm_study_host($site) . ' but found no pages to share (a pack, sign-up, how it works or contact page). Add a link by hand below.';
    return $res;
}

/* ---------------- editing the kit (the forms in Operations > Business details) ---------------- */

/** One form of the kit's card. $do: upload, docs_save, scan, links_save, check. Returns [message, kind 'ok'|'err']. */
function pm_share_handle(string $do, string $brand): array
{
    if (!pm_brand_valid($brand)) {
        return ['Choose a business first.', 'err'];
    }
    if ($do === 'upload') {
        [$ok, $msg] = pm_share_upload($brand);
        $ok && function_exists('pm_tm_pack_refresh_drafts') && $brand === 'travel' && ($n = pm_tm_pack_refresh_drafts()) > 0 && ($msg .= " $n unsent first email" . ($n === 1 ? '' : 's') . ' now mention it.');
        return [$msg, $ok ? 'ok' : 'err'];
    }
    if ($do === 'scan') {
        $r = pm_share_scan($brand);
        $r['ok'] && function_exists('pm_tm_pack_refresh_drafts') && $brand === 'travel' && ($n = pm_tm_pack_refresh_drafts()) > 0 && ($r['note'] .= " $n unsent first email" . ($n === 1 ? '' : 's') . ' now mention the pack.');
        return [$r['note'], $r['ok'] ? 'ok' : 'err'];
    }
    if ($do === 'check') {
        $bad = [];
        $n = 0;
        $out = [];
        foreach (pm_share($brand)['docs'] as $d) {
            if (($u = pm_share_doc_url($d)) !== '') {
                [$ok, $why] = pm_share_url_check($u);
                $out[$d['id']] = ['at' => date('Y-m-d H:i'), 'ok' => $ok, 'why' => $why];
                $n++;
                $ok || $bad[] = $d['title'] . ' (' . $why . ')';
            }
        }
        pm_share_update($brand, function (array $k) use ($out) {
            $k['check'] = array_merge($k['check'], $out);
            return $k;
        });
        return [$n === 0 ? 'No document has a web link yet.' : ($bad ? 'Not opening from the internet: ' . implode('; ', $bad) . '.' : 'Every document link opens.'), $bad || $n === 0 ? 'err' : 'ok'];
    }
    if ($do === 'docs_save') {
        $in = (array)($_POST['d'] ?? []);
        $gone = 0;
        pm_share_update($brand, function (array $k) use ($in, &$gone) {
            $keep = [];
            foreach ($k['docs'] as $d) {
                $r = (array)($in[$d['id']] ?? []);
                if (!empty($r['remove'])) {
                    ($p = pm_share_doc_path($d)) !== '' && @unlink($p);
                    $gone++;
                    continue;
                }
                if ($r) {
                    $t = mb_substr(trim((string)($r['title'] ?? '')), 0, 80);
                    $d['title'] = $t !== '' ? $t : $d['title'];
                    $d['attach'] = !empty($r['attach']);
                    $d['link'] = !empty($r['link']);
                    $d['role'] = isset(PM_SHARE_ROLES[$r['role'] ?? '']) ? $r['role'] : ($d['role'] ?? 'other');
                    $d['url'] = pm_clean_url((string)($r['url'] ?? ''));
                }
                $keep[] = $d;
            }
            $k['docs'] = $keep;
            return $k;
        });
        return [$gone ? "Saved. $gone document" . ($gone === 1 ? '' : 's') . ' removed.' : 'Saved.', 'ok'];
    }
    if ($do === 'links_save') {
        $in = (array)($_POST['l'] ?? []);
        $new = (array)($_POST['new'] ?? []);
        $bad = '';
        pm_share_update($brand, function (array $k) use ($in, $new, &$bad) {
            $keep = [];
            foreach ($k['links'] as $l) {
                $r = (array)($in[$l['id'] ?? ''] ?? []);
                if (!empty($r['remove'])) {
                    continue;
                }
                if ($r) {
                    $t = mb_substr(trim((string)($r['label'] ?? '')), 0, 60);
                    $l['label'] = $t !== '' ? $t : $l['label'];
                    $l['on'] = !empty($r['on']);
                    $l['role'] = isset(PM_SHARE_ROLES[$r['role'] ?? '']) ? $r['role'] : ($l['role'] ?? 'other');
                }
                $keep[] = $l;
            }
            $nu = pm_clean_url((string)($new['url'] ?? ''));
            if (trim((string)($new['url'] ?? '')) !== '' && $nu === '') {
                $bad = 'That link is not a web address.';
            } elseif ($nu !== '') {
                $have = array_map(fn($l) => pm_share_url_key((string)$l['url']), $keep);
                if (!in_array(pm_share_url_key($nu), $have, true)) {
                    $lab = mb_substr(trim((string)($new['label'] ?? '')), 0, 60);
                    $keep[] = ['id' => substr(md5($nu . microtime()), 0, 10), 'label' => $lab !== '' ? $lab : (pm_share_slug_label((string)parse_url($nu, PHP_URL_PATH)) ?: pm_study_host($nu)),
                        'url' => $nu, 'role' => isset(PM_SHARE_ROLES[$new['role'] ?? '']) ? $new['role'] : 'other', 'on' => true, 'auto' => false];
                }
            }
            $k['links'] = $keep;
            return $k;
        });
        if ($bad !== '') {
            return [$bad, 'err'];
        }
        $n = count(array_filter(pm_share($brand)['links'], fn($l) => !empty($l['on'])));
        return ['Saved. ' . $n . ' link' . ($n === 1 ? '' : 's') . ' will be added to first emails (up to ' . PM_SHARE_EMAIL_MAX . ') and WhatsApp messages (up to ' . PM_SHARE_WA_MAX . ').', 'ok'];
    }
    return ['Nothing to do.', 'err'];
}
