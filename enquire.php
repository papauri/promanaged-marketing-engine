<?php
/**
 * Public page, no login: enquiry form, free website check (?mode=check) and Travel Malawi host sign-up (?b=travel&mode=host).
 * ?b=travel picks Travel Malawi, ?src= or ?ref= records where the visitor came from, ?embed=1 hides the header for iframes.
 * Protected by a signed form token (min 3 s fill time, 2 h life), a hidden honeypot field and per-IP rate limits.
 */
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/agents.php';
if (!function_exists('pm_mail')) {
    require_once __DIR__ . '/lib/mail.php';
}
require_once __DIR__ . '/lib/outbound.php';
require_once __DIR__ . '/lib/leadoffers.php'; // the public page of a lead offer (?mode=offer&o=...)

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
@set_time_limit(90);

$brand = pm_brand_norm($_GET['b'] ?? $_POST['b'] ?? '');
$custom = pm_brand_is_custom($brand);
pm_brand_set($brand);
$mode = (string)($_GET['mode'] ?? '');
$offer = $mode === 'offer' ? pm_lp_public($brand, (string)($_GET['o'] ?? $_POST['o'] ?? '')) : null; // unknown, paused or ended: the normal enquiry page instead
$mode = ($mode === 'check' && !$custom) || ($mode === 'host' && $brand === 'travel') || ($mode === 'offer' && $offer) ? $mode : '';
$src = substr(preg_replace('/[^a-z0-9_.-]/i', '', (string)($_GET['src'] ?? $_GET['ref'] ?? $_POST['src'] ?? '')), 0, 40);
$embed = !empty($_GET['embed']);
$s = pm_settings();
$co = (string)$s['company_name'];
$tr = $brand === 'travel';
$ip = pm_visitor_ip();

function pm_e_field(string $label, string $name, string $val = '', array $o = []): string
{
    $req = !empty($o['required']);
    $tag = !empty($o['area'])
        ? '<textarea name="' . $name . '" rows="4" maxlength="1500"' . ($req ? ' required' : '') . '>' . pm_h($val) . '</textarea>'
        : '<input type="' . ($o['type'] ?? 'text') . '" name="' . $name . '" value="' . pm_h($val) . '" maxlength="' . (int)($o['max'] ?? 120) . '"' . ($req ? ' required' : '')
            . (isset($o['auto']) ? ' autocomplete="' . $o['auto'] . '"' : '') . (isset($o['mode']) ? ' inputmode="' . $o['mode'] . '"' : '') . '>';
    return '<div class="f"><label>' . pm_h($label) . ($req ? '' : ' <span class="opt">(optional)</span>') . '</label>' . $tag . '</div>';
}

/** Hidden anti-bot fields: honeypot (people never see it) and the signed token. */
/** The optional WhatsApp permission box (C3-A01): only a ticked box makes a WhatsApp campaign allowed to reach this person. */
function pm_e_wa_box(): string
{
    return '<div class="f"><label style="display:flex;gap:8px;align-items:flex-start;color:var(--ink)"><input type="checkbox" name="wa_ok" value="1" style="width:auto;margin-top:4px"> <span>You may message me on WhatsApp on this number <span class="opt">(optional)</span></span></label></div>';
}

function pm_e_guard(string $ctx): string
{
    return '<div class="hp" aria-hidden="true"><label>Leave this empty</label><input type="text" name="company_site" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="tk" value="' . pm_h(pm_form_token($ctx)) . '">';
}

function pm_e_page(string $title, string $body, int $code = 200): never
{
    global $s, $co, $tr, $embed, $brand;
    $logo = pm_brand_asset($brand, 'logo');
    http_response_code($code);
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($s['accent_color'] ?? '')) ? $s['accent_color'] : '#17375E';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . pm_h($title . ' · ' . $co) . '</title><style>'
        . ':root{--ink:#14161a;--muted:#6b7078;--line:#e4e6e9;--soft:#f6f7f9;--accent:' . $accent . '}*{box-sizing:border-box}'
        . 'body{margin:0;background:' . ($embed ? '#fff' : 'var(--soft)') . ';color:var(--ink);font:16px/1.55 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
        . '.wrap{max-width:640px;margin:0 auto;padding:' . ($embed ? '8px' : '24px') . ' 16px 48px}.top{display:flex;align-items:center;gap:10px;margin-bottom:18px}.top img{height:38px;border-radius:6px}.top b{font:700 19px Georgia,serif}'
        . '.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px 22px;margin-bottom:16px}h1{font:400 28px/1.2 Georgia,"Times New Roman",serif;margin:0 0 8px}'
        . '.lead{color:#4a4f57;margin:0 0 18px}.f{margin:0 0 14px}label{display:block;font-size:13px;color:var(--muted);margin:0 0 5px}.opt{color:#9aa0a8}'
        . 'input[type=text],input[type=email],input[type=tel],input[type=url],textarea,select{width:100%;padding:12px;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff}'
        . 'input:focus,textarea:focus,select:focus{outline:2px solid var(--accent);outline-offset:0}.btn{display:block;width:100%;border:0;border-radius:8px;background:var(--accent);color:#fff;font:inherit;font-weight:600;padding:14px;cursor:pointer}'
        . '.err{background:#fbf3f2;border:1px solid #ecd0cc;border-radius:8px;padding:12px 14px;margin-bottom:16px}.hp{position:absolute;left:-5000px;height:0;overflow:hidden}'
        . '.facts{margin:0 0 18px;padding:0;list-style:none}.facts li{padding:9px 0 9px 20px;border-bottom:1px solid var(--line);position:relative}.facts li:before{content:"";position:absolute;left:2px;top:17px;width:8px;height:8px;border-radius:50%;background:var(--accent)}'
        . '.alt{font-size:14px;color:var(--muted);text-align:center}.alt a{color:var(--accent)}.small{font-size:12px;color:var(--muted)}'
        . '</style></head><body><div class="wrap">' . ($embed ? '' : '<div class="top">' . (is_file(__DIR__ . '/' . $logo) ? '<img src="' . $logo . '" alt="">' : '') . '<b>' . pm_h($co) . '</b></div>')
        . $body . ($embed ? '' : '<p class="small" style="text-align:center">' . pm_h(implode('  ·  ', array_filter([$co, $s['phone'] ?? '', $s['email'] ?? '']))) . '</p>') . '</div></body></html>';
    exit;
}

/** The landing page of a lead offer: a bold headline, what it is, big buttons, and a short form. Nothing here promises more than the owner wrote. */
function pm_e_offer_page(array $o, string $errHtml, array $in, string $action): never
{
    global $s, $tr;
    $phone = trim((string)($s['phone'] ?? ''));
    $tel = $phone !== '' ? pm_tel_link(['phone' => $phone]) : '';
    $key = (string)$o['button_key'];
    $primary = $key === 'call' && $tel !== '' ? [$tel, $o['button']] : ($o['wa'] !== '' ? [$o['wa'], $o['button'] === 'Call us' ? 'Send WhatsApp message' : $o['button']] : ['#form', $o['button']]);
    $v = fn(string $k) => pm_h((string)($in[$k] ?? ''));
    $bul = '';
    foreach (array_slice((array)$o['bullets'], 0, 5) as $b) {
        $bul .= '<li>' . pm_h((string)$b) . '</li>';
    }
    $qs = '';
    foreach ((array)$o['questions'] as $i => $q) {
        $val = (string)($in['qa'][$i] ?? '');
        if ($q['options']) {
            $qs .= '<div class="f"><label>' . pm_h($q['label']) . '</label><select name="qa[' . $i . ']"><option value="">Choose…</option>'
                . implode('', array_map(fn($op) => '<option' . ($val === $op ? ' selected' : '') . '>' . pm_h($op) . '</option>', $q['options'])) . '</select></div>';
        } else {
            $qs .= pm_e_field($q['label'], 'qa[' . $i . ']', $val, ['max' => 200]);
        }
    }
    $body = '<style>.offer h1{font:700 34px/1.1 Georgia,"Times New Roman",serif;letter-spacing:-.01em;margin:0 0 10px}.offer .sub{font-size:18px;color:#3b4048;margin:0 0 16px}'
        . '.offer ul{list-style:none;margin:0 0 18px;padding:0}.offer li{padding:8px 0 8px 30px;position:relative;border-bottom:1px solid var(--line)}.offer li:before{content:"✓";position:absolute;left:2px;color:var(--accent);font-weight:700}'
        . '.cta{display:block;text-align:center;padding:17px 18px;border-radius:999px;background:var(--accent);color:#fff;font-weight:700;font-size:18px;text-decoration:none;margin:10px 0}'
        . '.cta.alt{background:#fff;color:var(--accent);border:2px solid var(--accent)}.or{text-align:center;color:var(--muted);margin:16px 0 6px;font-size:14px}</style>'
        . '<div class="card offer"><h1>' . pm_h((string)$o['headline']) . '</h1>' . ($o['sub'] !== '' ? '<p class="sub">' . pm_h((string)$o['sub']) . '</p>' : '') . ($bul !== '' ? '<ul>' . $bul . '</ul>' : '')
        . '<a class="cta" href="' . pm_h($primary[0]) . '"' . (str_starts_with($primary[0], 'http') ? ' target="_blank" rel="noopener"' : '') . '>' . pm_h($primary[1]) . '</a>'
        . ($tel !== '' && $key !== 'call' ? '<a class="cta alt" href="' . pm_h($tel) . '">Call ' . pm_h($phone) . '</a>' : '')
        . '<p class="or">or leave your details and a person will reply</p>' . $errHtml
        . '<form method="post" id="form" action="' . pm_h($action) . '">' . pm_e_guard($GLOBALS['brand'] . '|offer') . '<input type="hidden" name="o" value="' . pm_h((string)$o['id']) . '">'
        . pm_e_field('Your name', 'name', (string)($in['name'] ?? ''), ['required' => true, 'auto' => 'name', 'max' => 80]) . pm_e_field('Business name', 'business', (string)($in['business'] ?? ''), ['max' => 100])
        . pm_e_field('Phone or WhatsApp', 'phone', (string)($in['phone'] ?? ''), ['type' => 'tel', 'auto' => 'tel', 'mode' => 'tel', 'max' => 40]) . pm_e_wa_box()
        . pm_e_field('Email', 'email', (string)($in['email'] ?? ''), ['type' => 'email', 'auto' => 'email']) . $qs
        . pm_e_field('Anything else we should know?', 'message', (string)($in['message'] ?? ''), ['area' => true])
        . '<button class="btn">' . pm_h($o['button'] === 'Call us' ? 'Send my details' : $o['button']) . '</button><p class="small">We use your details only to reply to you. Give us a phone number or an email so we can.</p></form></div>';
    pm_e_page((string)$o['headline'], $body);
}

function pm_e_thanks(string $msg): never
{
    pm_e_page('Thank you', '<div class="card"><h1>Thank you.</h1><p class="lead">' . pm_h($msg) . '</p></div>');
}

/** Normalise an optional website; '' when it is not a plain http(s) address. */
function pm_e_site(string $v): string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    $u = preg_match('#^[a-z][a-z0-9+.-]*://#i', $v) ? $v : 'http://' . $v;
    $p = parse_url($u);
    return $p && in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) && str_contains((string)($p['host'] ?? ''), '.') && !preg_match('/\s/', $v) ? mb_substr($v, 0, 200) : '';
}

/** Validate contact details shared by every form; returns [data, error]. */
function pm_e_contact(array $p): array
{
    $d = ['name' => pm_web_clean($p['name'] ?? '', 80), 'business' => pm_web_clean($p['business'] ?? '', 100), 'phone' => pm_web_clean($p['phone'] ?? '', 40),
        'email' => strtolower(pm_web_clean($p['email'] ?? '', 120)), 'message' => pm_web_clean($p['message'] ?? '', 1500), 'website' => pm_e_site((string)($p['website'] ?? '')),
        'city' => pm_web_clean($p['city'] ?? '', 60), 'type' => pm_web_clean($p['type'] ?? '', 40), 'wa_ok' => !empty($p['wa_ok'])];
    if (trim((string)($p['website'] ?? '')) !== '' && $d['website'] === '') {
        return [$d, 'That website address does not look right. Leave it empty or use something like www.example.com.'];
    }
    if ($d['phone'] !== '' && strlen(preg_replace('/\D+/', '', $d['phone'])) < 7) {
        return [$d, 'That phone number looks too short.'];
    }
    if ($d['email'] !== '') {
        $v = pm_verify_email($d['email'], '', false);
        if (!$v['ok']) {
            return [$d, 'That email address does not look right (' . rtrim($v['why'], '.') . ').'];
        }
    }
    if ($d['phone'] === '' && $d['email'] === '') {
        return [$d, 'Please give us a phone or WhatsApp number or an email address so we can reply.'];
    }
    return [$d, ''];
}

function pm_e_limit(string $bucket, int $max): void
{
    global $ip;
    if (!pm_rate_hit($bucket, $ip, $max)) {
        pm_e_page('Please try later', '<div class="card"><h1>Please try again later.</h1><p class="lead">We have had several requests from your network in the last hour. Please try again in a little while, or contact us directly.</p></div>', 429);
    }
}

/** Create the lead, tell the owner, acknowledge the visitor, then go to the thank-you page. */
function pm_e_finish(array $d, string $kind): never
{
    global $brand, $src, $embed;
    $r = pm_web_lead($d + ['brand' => $brand, 'kind' => $kind, 'src' => $src]);
    pm_web_notify($r['lead'], $r['merged']);
    pm_web_ack($r['lead']);
    header('Location: enquire.php?b=' . $brand . '&done=1' . ($embed ? '&embed=1' : ''), true, 303);
    exit;
}

$act = $mode === 'offer' ? 'offer' : ($mode === 'check' ? (isset($_POST['facts']) ? 'checklead' : 'check') : ($mode === 'host' ? 'host' : 'enquiry'));
$err = '';
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$facts = null;
$checkUrl = '';

if (isset($_GET['done'])) {
    header('X-Robots-Tag: noindex');
    pm_e_thanks('We have your message and a member of our team will read it and reply to you personally.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pm_form_token_ok((string)($_POST['tk'] ?? ''), "$brand|$act")) {
        $err = 'This form expired or was sent too quickly. Please check your details and send it again.';
    } elseif (trim((string)($_POST['company_site'] ?? '')) !== '') {
        pm_e_thanks('We have your message.'); // a bot filled the hidden field: look like success, create nothing
    } elseif ($act === 'check') {
        pm_e_limit('chk', 10);
        $safe = pm_url_safe_final(pm_web_clean($_POST['url'] ?? '', 300));
        if (!$safe['ok']) {
            $err = $safe['why'];
        } else {
            $res = pm_site_check($safe['url'], true); // code-computed facts only, no AI
            $checkUrl = $safe['url'];
            $facts = !empty($res['facts']) ? array_slice(array_map(fn($f) => pm_web_clean($f, 200), (array)$res['facts']), 0, 10) : [];
            if (!$facts) {
                $err = 'We could not open that website just now, so we have nothing to report. Please check the address and try again later.';
                $facts = null;
            }
        }
    } else {
        [$d, $err] = pm_e_contact($_POST);
        if ($err === '') {
            if ($act === 'checklead') {
                $json = (string)base64_decode((string)($_POST['facts'] ?? ''), true);
                if (!hash_equals(hash_hmac('sha256', 'facts|' . $json, pm_form_secret()), (string)($_POST['fsig'] ?? '')) || !is_array($fl = json_decode($json, true))) {
                    $err = 'Something went wrong with the check result. Please run the check again.';
                } else {
                    pm_e_limit('enq', 5);
                    pm_e_limit('enqall', 60);
                    $site = pm_e_site((string)($fl['url'] ?? ''));
                    pm_e_finish(['website' => $site, 'business' => $d['business'] !== '' ? $d['business'] : (pm_host_key($site) ?: 'Website check'), 'message' => 'Asked us to fix the problems found by the free website check of ' . $site . '.',
                        'evidence' => array_map(fn($f) => 'Site check: ' . $f, array_slice(array_map('strval', (array)($fl['facts'] ?? [])), 0, 10))] + $d, 'check');
                }
            } elseif ($act === 'offer' && $offer) {
                if (trim($d['name']) === '') {
                    $err = 'Please give your name.';
                } else {
                    pm_e_limit('enq', 5);
                    pm_e_limit('enqall', 60);
                    $full = pm_lp_get((string)$offer['id']) ?: [];
                    $ref = preg_match('/^(?:fb|ig|li|wa|x|tt|gb|yt)-([A-Za-z0-9]{4})$/i', $src, $rm) ? strtoupper($rm[1]) : '';
                    pm_e_finish(['message' => pm_lp_message($full + ['title' => $offer['title'], 'questions' => $offer['questions']], (array)($_POST['qa'] ?? []), (string)($_POST['message'] ?? '')),
                        'label' => 'Asked via the offer: ' . $offer['title'], 'src_tag' => 'offer-' . $offer['id'], 'ref' => $ref, 'business' => $d['business'] !== '' ? $d['business'] : ''] + $d, 'offer');
                }
            } else {
                if (trim($d['name']) === '' || ($act === 'enquiry' && mb_strlen($d['message']) < 5) || ($act === 'host' && ($d['business'] === '' || $d['city'] === ''))) {
                    $err = $act === 'host' ? 'Please give your name, your property name and the town.' : 'Please give your name and tell us a little about what you need.';
                } else {
                    pm_e_limit('enq', 5);
                    pm_e_limit('enqall', 60);
                    if ($act === 'host') {
                        $d['message'] = 'Wants to list a stay with Travel Malawi. Type: ' . ($d['type'] ?: 'not given') . '. Town: ' . $d['city'] . ($d['message'] !== '' ? '. ' . $d['message'] : '');
                    }
                    pm_e_finish($d, $act === 'host' ? 'host' : 'enquiry');
                }
            }
        }
    }
}

$errHtml = $err !== '' ? '<div class="err">' . pm_h($err) . '</div>' : '';
$v = fn(string $k) => (string)($in[$k] ?? '');
$qs = fn(array $extra = []) => 'enquire.php?' . http_build_query(array_filter(['b' => $brand !== 'promanaged' ? $brand : '', 'src' => $src, 'embed' => $embed ? '1' : ''] + $extra));

if ($act === 'check' && $facts === null || $act === 'check' && $_SERVER['REQUEST_METHOD'] !== 'POST') { // check mode: ask for the address
    pm_e_page('Free website check', '<div class="card"><h1>Free website check</h1><p class="lead">Type your website address. We look at the home page and tell you plainly what we find: speed, phones, WhatsApp, contact and booking. It is done by software on the page as it is today, nothing is invented.</p>'
        . $errHtml . '<form method="post" action="' . pm_h($qs(['mode' => 'check'])) . '">' . pm_e_guard("$brand|check") . pm_e_field('Your website address', 'url', $v('url'), ['required' => true, 'type' => 'text', 'max' => 300, 'mode' => 'url'])
        . '<button class="btn">Check my website</button></form></div><p class="alt"><a href="' . pm_h($qs()) . '">Or send us a message instead</a></p>');
}
if ($act === 'check' && $facts !== null) { // results + offer to fix
    $fj = (string)json_encode(['url' => $checkUrl, 'facts' => $facts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    pm_e_page('Website check result', '<div class="card"><h1>What we found</h1><p class="lead">' . pm_h(preg_replace('#^https?://#', '', $checkUrl)) . ', checked just now:</p><ul class="facts">'
        . implode('', array_map(fn($f) => '<li>' . pm_h($f) . '</li>', $facts)) . '</ul></div>'
        . '<div class="card"><h2 style="font:400 21px Georgia,serif;margin:0 0 6px">Want us to fix these?</h2><p class="lead">Leave your WhatsApp number and a person will get back to you. There is no obligation.</p>'
        . '<form method="post" action="' . pm_h($qs(['mode' => 'check'])) . '">' . pm_e_guard("$brand|checklead")
        . '<input type="hidden" name="facts" value="' . pm_h(base64_encode($fj)) . '"><input type="hidden" name="fsig" value="' . pm_h(hash_hmac('sha256', 'facts|' . $fj, pm_form_secret())) . '">'
        . pm_e_field('Your name', 'name', '', ['required' => true, 'auto' => 'name', 'max' => 80]) . pm_e_field('Business name', 'business', '', ['max' => 100])
        . pm_e_field('WhatsApp number', 'phone', '', ['type' => 'tel', 'auto' => 'tel', 'mode' => 'tel', 'max' => 40]) . pm_e_wa_box() . pm_e_field('Email', 'email', '', ['type' => 'email', 'auto' => 'email'])
        . '<button class="btn">Yes, contact me</button></form></div>');
}
if ($act === 'checklead') { // a failed check-lead post: back to a fresh check
    pm_e_page('Free website check', '<div class="card"><h1>Free website check</h1>' . $errHtml . '<p><a href="' . pm_h($qs(['mode' => 'check'])) . '">Run the check again</a></p></div>');
}

if ($act === 'offer' && $offer) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pm_lp_view((string)$offer['id']);
    }
    pm_e_offer_page($offer, $errHtml, $in, $qs(['mode' => 'offer', 'o' => $offer['id']]));
}

$title = $act === 'host' ? 'List your stay with Travel Malawi' : ($tr ? 'Ask Travel Malawi about a stay' : 'Tell us what you need');
$lead = $act === 'host' ? 'Tell us about your lodge, guest house or camp. A member of our team will contact you to talk about getting it listed.'
    : ($tr ? 'Planning a trip to Malawi? Ask us about a stay and a person will reply.' : ($custom ? (trim(rtrim((string)(pm_brain($brand)['about'] ?? ''), '.')) !== '' ? rtrim((string)pm_brain($brand)['about'], '.') . '. ' : '') . 'Send a few lines and a person will reply to you.' : 'Software, websites, IT support and hardware for Malawian businesses. Send a few lines and a person will reply to you.'));
$form = pm_e_guard("$brand|$act");
if ($act === 'host') {
    $types = ['Lodge', 'Guest house', 'Bed and breakfast', 'Hotel', 'Safari camp', 'Cottage or chalet', 'Hostel', 'Other'];
    $form .= pm_e_field('Your name', 'name', $v('name'), ['required' => true, 'auto' => 'name', 'max' => 80]) . pm_e_field('Property name', 'business', $v('business'), ['required' => true, 'max' => 100])
        . '<div class="f"><label>Type of stay</label><select name="type">' . implode('', array_map(fn($t) => '<option' . ($v('type') === $t ? ' selected' : '') . '>' . pm_h($t) . '</option>', $types)) . '</select></div>'
        . pm_e_field('Town or area', 'city', $v('city'), ['required' => true, 'max' => 60]) . pm_e_field('Phone or WhatsApp', 'phone', $v('phone'), ['type' => 'tel', 'auto' => 'tel', 'mode' => 'tel', 'max' => 40]) . pm_e_wa_box()
        . pm_e_field('Email', 'email', $v('email'), ['type' => 'email', 'auto' => 'email']) . pm_e_field('Website or Facebook page', 'website', $v('website'), ['max' => 200])
        . pm_e_field('Anything we should know', 'message', $v('message'), ['area' => true, 'required' => false]);
} else {
    $form .= pm_e_field('Your name', 'name', $v('name'), ['required' => true, 'auto' => 'name', 'max' => 80]) . pm_e_field('Business name', 'business', $v('business'), ['max' => 100])
        . pm_e_field('Phone or WhatsApp', 'phone', $v('phone'), ['type' => 'tel', 'auto' => 'tel', 'mode' => 'tel', 'max' => 40]) . pm_e_wa_box() . pm_e_field('Email', 'email', $v('email'), ['type' => 'email', 'auto' => 'email'])
        . pm_e_field('Your website', 'website', $v('website'), ['max' => 200]) . pm_e_field($tr ? 'What would you like to know?' : 'What do you need?', 'message', $v('message'), ['area' => true, 'required' => true]);
}
$alts = $tr ? '<a href="' . pm_h($qs(['mode' => $act === 'host' ? '' : 'host'])) . '">' . ($act === 'host' ? 'Looking for a stay instead?' : 'Own a lodge or guest house? List it with us') . '</a>'
    : ($custom ? '' : '<a href="' . pm_h($qs(['mode' => 'check'])) . '">Not ready to write? Try the free website check</a>');
pm_e_page($title, '<div class="card"><h1>' . pm_h($title) . '</h1><p class="lead">' . pm_h($lead) . '</p>' . $errHtml
    . '<form method="post" action="' . pm_h($qs($act === 'host' ? ['mode' => 'host'] : [])) . '">' . $form . '<button class="btn">Send</button>'
    . '<p class="small">We use your details only to reply to you. Give us a phone number or an email so we can.</p></form></div><p class="alt">' . $alts . '</p>');
