<?php
/**
 * Public page where a client reviews and signs their proposal / agreement.
 * Reached with a private link: sign.php?t=<32-character token>. No login.
 */
declare(strict_types=1);
session_start();
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/pdf.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/engage.php'; // loads agents.php: pm_funnel_mark moves the lead through the pipeline

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$r = pm_record_load($token);
pm_brand_set((string)($r['brand'] ?? 'promanaged')); // a Travel Malawi proposal is signed on a Travel Malawi page
pm_set_free(!empty($r['tpl']['free']));      // free onboarding shows "Free", never "MWK 0"
pm_set_from(!empty($r['tpl']['price_from'])); // "From" quotations stay "From"
$s = pm_settings();

function pm_page(array $s, string $title, string $body): never
{
    $accent = pm_h($s['accent_color'] ?? '#17375E');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . pm_h($title) . ' · ' . pm_h($s['company_name']) . '</title><style>'
        . ':root{--ink:#14161a;--muted:#6b7078;--line:#e4e6e9;--soft:#f6f7f9;--accent:' . $accent . '}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--soft);color:var(--ink);font:15px/1.6 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
        . '.wrap{max-width:760px;margin:0 auto;padding:28px 18px 60px}.top{display:flex;align-items:center;justify-content:space-between;margin-bottom:26px}.top img{height:42px}'
        . '.ref{font-size:12px;color:var(--muted);letter-spacing:.06em}.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:30px 32px;margin-bottom:18px}'
        . '.eyebrow{font-size:11px;font-weight:700;letter-spacing:.14em;color:var(--accent);text-transform:uppercase;margin:0 0 6px}'
        . 'h1{font:400 34px/1.15 Georgia,"Times New Roman",serif;margin:0 0 10px}h2{font:400 22px/1.25 Georgia,"Times New Roman",serif;margin:0 0 14px}'
        . '.lead{color:#4a4f57;margin:0}.deal{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin:24px 0 6px}'
        . '.deal div{padding:14px 14px 14px 0}.deal div+div{border-left:1px solid var(--line);padding-left:14px}.deal small{display:block;font-size:10.5px;letter-spacing:.1em;color:var(--muted);text-transform:uppercase}'
        . '.deal b{display:block;font:400 17px/1.3 Georgia,serif;margin-top:3px;overflow-wrap:anywhere}.points{margin:18px 0 0;padding:0;list-style:none}.points li{padding:8px 0 8px 22px;border-bottom:1px solid var(--line);position:relative;color:#3b4047}'
        . '.points li:before{content:"";position:absolute;left:2px;top:17px;width:8px;height:8px;border-radius:50%;background:var(--accent)}'
        . '.btn{display:inline-block;border-radius:8px;padding:13px 22px;font-weight:600;font-size:15px;text-decoration:none;border:1px solid var(--line);color:var(--ink);background:#fff;cursor:pointer}'
        . '.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff;width:100%;font-size:16px;padding:15px}.btn:disabled{opacity:.45;cursor:not-allowed}'
        . 'label{display:block;font-size:12px;color:var(--muted);margin:0 0 5px}input[type=text],input[type=email]{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:8px;font:inherit}'
        . 'input:focus{outline:none;border-color:var(--accent)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.tabs{display:flex;gap:6px;margin:18px 0 10px}'
        . '.tabs button{flex:0 0 auto;border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 16px;font:inherit;font-size:13px;cursor:pointer}.tabs button.on{background:var(--ink);color:#fff;border-color:var(--ink)}'
        . '.pad{position:relative;border:1px solid var(--line);border-radius:10px;background:#fbfbfc;height:180px;touch-action:none}.pad canvas{width:100%;height:100%;display:block;border-radius:10px}'
        . '.pad .line{position:absolute;left:24px;right:24px;bottom:42px;border-bottom:1px solid #cfd3d8;pointer-events:none}.pad .hint{position:absolute;left:24px;bottom:16px;font-size:12px;color:#a2a7ae;pointer-events:none}'
        . '.pad .clear{position:absolute;right:10px;top:10px;border:0;background:none;color:var(--muted);font:inherit;font-size:13px;cursor:pointer}'
        . '.typed{height:180px;border:1px solid var(--line);border-radius:10px;background:#fbfbfc;display:flex;align-items:center;justify-content:center;font:italic 40px Georgia,serif;color:#1c2333;padding:0 20px;text-align:center}'
        . '.agree{display:flex;gap:12px;align-items:flex-start;background:var(--soft);border-radius:10px;padding:14px 16px;margin:18px 0;font-size:14px;color:#2f343b}.agree input{margin-top:4px;width:18px;height:18px;flex:0 0 auto}'
        . '.err{background:#fbf3f2;border:1px solid #ecd0cc;border-radius:8px;padding:12px 14px;margin-bottom:16px}.ok{color:var(--accent)}'
        . '.foot{text-align:center;font-size:12px;color:var(--muted);margin-top:22px}.foot a{color:var(--muted)}'
        . '@media(max-width:620px){.card{padding:22px 18px}h1{font-size:28px}.deal{grid-template-columns:1fr 1fr}.deal div:nth-child(3){border-left:0;padding-left:0}.grid{grid-template-columns:1fr}}'
        . '</style></head><body><div class="wrap"><div class="top">' . (pm_logo_path() !== '' ? '<span style="display:flex;align-items:center;gap:10px"><img src="assets/' . (pm_brand() === 'travel' ? 'travel_logo.png' : 'logo.png') . '" alt="" style="' . (pm_brand() === 'travel' ? 'height:34px;border-radius:6px' : '') . '">' . (pm_brand() === 'travel' ? '<b style="font:700 20px Georgia,serif">' . pm_h($s['company_name']) . '</b>' : '') . '</span>' : '<b>' . pm_h($s['company_name']) . '</b>') . '<span class="ref">' . pm_h($title) . '</span></div>'
        . $body
        . '<div class="foot">' . pm_h(implode('  ·  ', array_filter([$s['company_name'], $s['phone'] ?? '', $s['email'] ?? '']))) . '</div></div></body></html>';
    exit;
}

if (!$r) {
    http_response_code(404);
    pm_page($s, 'Not found', '<div class="card"><p class="eyebrow">Link not recognised</p><h1>We could not find this document</h1><p class="lead">The link may be incomplete. Please use the link in your email, or contact us and we will send it again.</p></div>');
}

$p = $r['p'];
$tpl = $r['tpl'];
$q = pm_quote($tpl, $p);
$cur = (string)($p['currency'] ?? $s['currency']);
$isSigned = !empty($r['signed']);

// ---- Serve the PDF (original, or signed copy) ----
if (isset($_GET['pdf'])) {
    $file = PM_OUT . '/' . basename((string)($isSigned && isset($_GET['signed']) ? $r['signed_file'] : $r['file']));
    if (!is_file($file)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    readfile($file);
    exit;
}

// ---- Count real openings only: not link scanners, previews, prefetches or bots ----
$ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$prefetch = preg_match('/prefetch|preview/i', (string)($_SERVER['HTTP_PURPOSE'] ?? '') . ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ($_SERVER['HTTP_X_PURPOSE'] ?? '') . ($_SERVER['HTTP_X_MOZ'] ?? ''));
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|curl|wget|python|monitor|headless|scanner|safelinks|mimecast|proofpoint|barracuda/', $ua);
$leadId = (string)($r['p']['lead_id'] ?? '');
if (!$isSigned && !$isBot && !$prefetch && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $lockV = @fopen(PM_REC . '/' . $token . '.lock', 'c');
    $lockV && flock($lockV, LOCK_EX);
    $r = pm_record_load($token) ?: $r;
    $firstView = (int)($r['views'] ?? 0) === 0;
    $prevView = (string)($r['last_view_at'] ?? '');
    $now = date('Y-m-d H:i:s');
    $r['views'] = (int)($r['views'] ?? 0) + 1;
    $r['last_view_at'] = $now;
    if ($firstView) {
        $r['viewed_at'] = $now;
    }
    pm_record_save($r);
    if ($lockV) {
        flock($lockV, LOCK_UN);
        fclose($lockV);
    }
    if ($firstView || $prevView === '' || strtotime($prevView) < time() - 600) { // the lead is updated on the first look, then at most every 10 minutes
        pm_funnel_mark($leadId, (string)($r['p']['email'] ?? ''), (string)$r['p']['business'], '',
            ['viewed_at' => $r['viewed_at'], 'view_count' => $r['views'], 'last_viewed_at' => date('Y-m-d H:i', strtotime($now))]);
    }
    if ($firstView) {
        pm_history_update($token, ['status' => 'Opened ' . date('j M, H:i')]);
        pm_notify_owner($r['p']['business'] . ' opened the proposal', $r['p']['business'] . ' has just opened ' . $r['ref'] . ' (' . $r['doc_label'] . ")

Contact: " . trim(($r['p']['contact'] ?? '') . ' ' . ($r['p']['email'] ?? ''))
            . "

They are looking at it now: a good moment to follow up personally.");
    }
}

$expired = !$isSigned && date('Y-m-d') > (string)$r['valid_until'];
$deal = '<div class="deal"><div><small>Package</small><b>' . pm_h($q['package']['name']) . '</b></div>'
    . '<div><small>Setup, once</small><b>' . pm_h(pm_money($q['setup'], $cur)) . '</b></div>'
    . '<div><small>Monthly</small><b>' . pm_h(pm_money($q['monthly'], $cur)) . '</b></div>'
    . '<div><small>Start date</small><b>' . pm_h(!empty($p['start_date']) ? date('j M Y', strtotime($p['start_date'])) : 'To be agreed') . '</b></div></div>';
$pdfLink = '?t=' . $token . '&pdf=1';

if ($isSigned) {
    $sg = $r['signed'];
    pm_page($s, $r['ref'], '<div class="card"><p class="eyebrow">Signed</p><h1>Thank you, ' . pm_h(explode(' ', $sg['name'])[0]) . '.</h1>'
        . '<p class="lead">' . pm_h($p['business']) . ' accepted this ' . pm_h(strtolower($r['doc_label'])) . ' on ' . pm_h(date('j F Y \a\t H:i', strtotime($sg['at']))) . '. A signed copy has been emailed to ' . pm_h($sg['email']) . '. We will be in touch shortly with your setup invoice and start date.</p>'
        . $deal . '<p style="margin:22px 0 0"><a class="btn" href="' . pm_h($pdfLink) . '&signed=1" target="_blank">Download the signed copy (PDF)</a></p></div>');
}

if ($expired) {
    pm_page($s, $r['ref'], '<div class="card"><p class="eyebrow">Offer expired</p><h1>This offer ended on ' . pm_h(date('j F Y', strtotime($r['valid_until']))) . '</h1>'
        . '<p class="lead">Prices and availability may have changed. Contact us and we will send you an updated proposal straight away.</p>'
        . '<p style="margin:22px 0 0"><a class="btn" href="mailto:' . pm_h($s['email']) . '?subject=' . rawurlencode('Updated proposal for ' . $p['business'] . ' (' . $r['ref'] . ')') . '">Ask for an updated proposal</a></p></div>');
}

if (empty($_SESSION['sign_csrf'])) {
    $_SESSION['sign_csrf'] = bin2hex(random_bytes(16));
}
$consent = 'I confirm that I have read the ' . strtolower($r['doc_label']) . ' (reference ' . $r['ref'] . '), including the quotation and the terms of service, and I agree to them on behalf of ' . $p['business'] . '. I understand that signing electronically is as binding as signing on paper.';
$errors = [];
$in = ['name' => $p['contact'] ?? '', 'title' => $p['contact_title'] ?? '', 'email' => $p['email'] ?? '', 'tpin' => $p['tpin'] ?? ''];

// ---- A pitch has nothing to sign: the client answers with one tap ----
$isPitch = ($p['doc_type'] ?? '') === 'pitch';
if ($isPitch && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = ($_POST['act'] ?? '') === 'yes' ? 'yes' : 'question';
    if (hash_equals($_SESSION['sign_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = mb_substr(trim((string)($_POST['msg'] ?? '')), 0, 500);
        $lockP = @fopen(PM_REC . '/' . $token . '.lock', 'c');
        $lockP && flock($lockP, LOCK_EX);
        $r = pm_record_load($token) ?: $r;
        $again = ($r['response']['act'] ?? '') === $act && ($r['response']['msg'] ?? '') === $msg;
        if (!$again) {
            $r['response'] = ['act' => $act, 'msg' => $msg, 'at' => date('Y-m-d H:i:s')];
            pm_record_save($r);
        }
        if ($lockP) {
            flock($lockP, LOCK_UN);
            fclose($lockP);
        }
        if (!$again) {
            $label = $act === 'yes' ? 'wants the agreement' : 'has a question';
            pm_history_update($token, ['status' => ucfirst($label) . ' ' . date('j M, H:i')]);
            pm_funnel_mark($leadId, (string)($p['email'] ?? ''), (string)$p['business'], 'replied', ['want_proposal' => true, 'reply_intent' => $act === 'yes' ? 'interested' : 'question',
                'last_reply' => date('Y-m-d H:i'), 'awaiting_reply_since' => date('Y-m-d H:i'), 'pitch_response' => ['act' => $act, 'msg' => $msg, 'at' => date('Y-m-d H:i'), 'ref' => $r['ref']]]);
            pm_notify_owner($p['business'] . ' ' . $label, $p['business'] . ' answered ' . $r['ref'] . ' on the proposal page: "' . ($act === 'yes' ? 'Yes, send me the agreement' : 'I have a question') . '".'
                . ($msg !== '' ? "

Their message:
" . $msg : '') . "

Contact: " . trim(($p['contact'] ?? '') . ' ' . ($p['email'] ?? '') . ' ' . ($p['phone'] ?? '')) . "

Reply to them today.");
        }
    }
    header('Location: sign.php?t=' . $token . '&thanks=1');
    exit;
}

// ---- Sign ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = [
        'name' => trim((string)($_POST['name'] ?? '')), 'title' => trim((string)($_POST['title'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')), 'tpin' => trim((string)($_POST['tpin'] ?? '')),
    ];
    $method = ($_POST['method'] ?? '') === 'typed' ? 'typed' : 'drawn';
    if (!hash_equals($_SESSION['sign_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please sign again.';
    }
    if (mb_strlen($in['name']) < 3 || mb_strlen($in['name']) > 100) {
        $errors[] = 'Enter your full name.';
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address so we can send you the signed copy.';
    }
    if (empty($_POST['agree'])) {
        $errors[] = 'Tick the box to confirm you agree.';
    }
    $sigFile = '';
    $typed = '';
    if ($method === 'typed') {
        $typed = trim((string)($_POST['typed'] ?? ''));
        if (mb_strlen($typed) < 3 || mb_strlen($typed) > 60) {
            $errors[] = 'Type your name as your signature.';
        }
    } else {
        $data = (string)($_POST['sig'] ?? '');
        $raw = str_starts_with($data, 'data:image/png;base64,') ? base64_decode(substr($data, 22), true) : false;
        $info = $raw && strlen($raw) < 600000 ? @getimagesizefromstring($raw) : false;
        if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 100 || ($_POST['strokes'] ?? '0') === '0') {
            $errors[] = 'Draw your signature in the box, or switch to "Type".';
        } else {
            $sigFile = PM_REC . '/' . $token . '_signature.png';
            if (!is_dir(PM_REC)) {
                mkdir(PM_REC, 0775, true);
            }
            file_put_contents($sigFile, $raw);
        }
    }

    if (!$errors) {
        // Re-check under a lock so two submissions cannot both sign.
        $lock = fopen(PM_REC . '/' . $token . '.lock', 'c');
        flock($lock, LOCK_EX);
        $r = pm_record_load($token);
        if (empty($r['signed'])) {
            $sg = [
                'name' => $in['name'], 'title' => $in['title'], 'email' => $in['email'], 'tpin' => $in['tpin'],
                'at' => date('Y-m-d H:i:s'), 'ip' => pm_client_ip(), 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
                'method' => $method, 'image' => $sigFile, 'typed' => $typed, 'hash' => $r['hash'],
                'sent_at' => $r['sent_at'] ?? $r['created'], 'viewed_at' => $r['viewed_at'], 'consent' => $consent,
            ];
            $p2 = $p;
            $p2['tpin'] = $in['tpin'] !== '' ? $in['tpin'] : ($p['tpin'] ?? '');
            $signedFile = pm_build_pdf($s, $tpl, $p2, $r['ref'], ['signed' => $sg]);
            $r['signed'] = $sg;
            $r['signed_file'] = basename($signedFile);
            $r['signed_hash'] = hash_file('sha256', $signedFile);
            $r['status'] = 'signed';
            pm_record_save($r);
            pm_history_update($token, ['status' => 'Signed ' . date('j M, H:i') . ' by ' . $in['name'], 'signed_file' => basename($signedFile)]);
            // The deal is won: the lead moves to Won (never backwards) with what was signed for (setup + 12 months of the monthly fee).
            pm_funnel_mark($leadId, (string)($p['email'] ?? '') ?: $in['email'], (string)$p['business'], 'won',
                ['won_at' => date('Y-m-d H:i'), 'won_ref' => $r['ref'], 'won_value' => round((float)$q['setup'] + 12 * (float)$q['monthly'], 2), 'won_currency' => $cur, 'awaiting_reply_since' => null]);

            $first = explode(' ', $in['name'])[0];
            $isFree = !empty($r['tpl']['free']);
            // What happens next, matching what was signed: free onboarding, a subscription, or a one-off fee.
            $next = $isFree ? 'What happens next: we will help you get your listing ready and checked, and let you know as soon as it is live.'
                : ($q['setup'] > 0 ? 'What happens next: we will send the invoice for ' . pm_money($q['setup'], $cur) . ' as set out in the agreement, and agree your start date with you.'
                : 'What happens next: we will agree your start date with you.');
            $owNext = $isFree ? 'Next step: help them complete the listing.' : ($q['setup'] > 0 ? 'Next step: send the setup invoice.' : 'Next step: agree the start date.');
            $cc = (strcasecmp((string)($p['email'] ?? ''), $in['email']) !== 0) ? (string)($p['email'] ?? '') : '';
            pm_mail($s, [
                'to' => $in['email'], 'cc' => $cc,
                'subject' => 'Signed: ' . $r['doc_label'] . ' for ' . $p['business'] . ' (' . $r['ref'] . ')',
                'body' => "Dear $first,\n\nThank you. Your signed copy is attached, with a signature certificate on the last page.\n\n"
                    . $next . "\n\n"
                    . "If you have any questions, just reply to this email.\n\nKind regards,\n" . implode("\n", array_filter([$s['signatory_name'], $s['company_name'], $s['phone']])),
                'attachments' => [$signedFile], 'bcc_self' => false,
            ]);
            $owner = $s['smtp']['from_email'] ?: $s['email'];
            if ($owner) {
                pm_mail($s, [
                    'to' => $owner, 'bcc_self' => false,
                    'subject' => 'SIGNED: ' . $p['business'] . ' accepted ' . $r['ref'] . ($isFree ? ' (free onboarding)' : ($q['monthly'] > 0 ? ' (' . pm_money($q['monthly'], $cur) . '/month)' : '')),
                    'body' => $p['business'] . ' has signed ' . $r['ref'] . ".\n\nSigned by: " . $in['name'] . ($in['title'] ? ', ' . $in['title'] : '') . "\nEmail: " . $in['email']
                        . "\nPackage: " . $q['package']['name'] . ($isFree ? "\nFees: free onboarding" : "\nSetup: " . pm_money($q['setup'], $cur) . "\nMonthly: " . pm_money($q['monthly'], $cur))
                        . "\nSigned at: " . date('j F Y, H:i', strtotime($sg['at'])) . "\n\n" . $owNext . ' The signed copy is attached.',
                    'attachments' => [$signedFile],
                ]);
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        header('Location: sign.php?t=' . $token);
        exit;
    }
}

$err = $errors ? '<div class="err">' . implode('<br>', array_map('pm_h', $errors)) . '</div>' : '';
// Key points match what was actually offered: free onboarding, a one-off fee, a subscription, or "From" prices.
$tq = (array)($r['tpl'] ?? []);
$pts = [];
if (!empty($tq['free'])) {
    $pts[] = 'Onboarding and listing are free: no listing fee, no commission and no monthly charge.';
    $pts[] = 'You can remove your listing at any time.';
} else {
    if ($q['setup'] > 0) {
        $pts[] = pm_money($q['setup'], $cur) . ' once, invoiced as set out in the agreement.';
    }
    if ($q['monthly'] > 0) {
        $pts[] = pm_money($q['monthly'], $cur) . ' a month, invoiced in advance.';
    }
    if (!empty($tq['price_from'])) {
        $pts[] = 'These are starting prices: the final price is confirmed in writing before you sign.';
    }
}
$pts[] = 'Your data is always yours.';
$pts[] = 'This offer is valid until ' . date('j F Y', strtotime($r['valid_until'])) . '.';
$points = '<ul class="points">' . implode('', array_map(fn($x) => '<li>' . pm_h($x) . '</li>', $pts)) . '</ul>';

if ($isPitch) {
    $answered = !empty($r['response']) || isset($_GET['thanks']);
    pm_page($s, $r['ref'], '<div class="card"><p class="eyebrow">' . pm_h($r['doc_label']) . '</p><h1>' . pm_h($p['business']) . '</h1>'
        . '<p class="lead">Prepared for ' . pm_h($p['contact'] ?: $p['business']) . ' by ' . pm_h($s['company_name']) . '. Read the proposal, then tell us what you would like next.</p>'
        . $deal . $points . '<p style="margin:22px 0 0"><a class="btn" href="' . pm_h($pdfLink) . '" target="_blank">Read the full proposal (PDF)</a></p></div>'
        . '<div class="card"><p class="eyebrow">Your answer</p>'
        . ($answered ? '<h2>Thank you, we have your answer.</h2><p class="lead">We will be in touch with you shortly. You can also reply to the email we sent or call ' . pm_h($s['phone'] ?? '') . '.</p>'
            : '<h2>What would you like to do?</h2><form method="post"><input type="hidden" name="t" value="' . pm_h($token) . '"><input type="hidden" name="csrf" value="' . pm_h($_SESSION['sign_csrf']) . '">'
            . '<label>Anything you would like us to know (optional)</label><textarea name="msg" rows="3" maxlength="500" style="width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:8px;font:inherit;margin-bottom:14px"></textarea>'
            . '<div class="grid"><button class="btn primary" name="act" value="yes">Yes, send me the agreement</button><button class="btn" name="act" value="question">I have a question</button></div></form>')
        . '</div>');
}
$body = '<div class="card"><p class="eyebrow">' . pm_h($r['doc_label']) . '</p><h1>' . pm_h($p['business']) . '</h1>'
    . '<p class="lead">Prepared for ' . pm_h($p['contact'] ?: $p['business']) . ' by ' . pm_h($s['company_name']) . '. Review the details below, read the full document, and sign when you are ready.</p>'
    . $deal . $points
    . '<p style="margin:22px 0 0"><a class="btn" href="' . pm_h($pdfLink) . '" target="_blank">Read the full document (PDF)</a></p></div>'
    . '<div class="card"><p class="eyebrow">Accept and sign</p><h2>Sign for ' . pm_h($p['business']) . '</h2>' . $err
    . '<form method="post" id="sf"><input type="hidden" name="t" value="' . pm_h($token) . '"><input type="hidden" name="csrf" value="' . pm_h($_SESSION['sign_csrf']) . '">'
    . '<input type="hidden" name="sig" id="sig"><input type="hidden" name="strokes" id="strokes" value="0"><input type="hidden" name="method" id="method" value="drawn">'
    . '<div class="grid"><div><label>Full name</label><input type="text" name="name" id="name" value="' . pm_h($in['name']) . '" required autocomplete="name"></div>'
    . '<div><label>Title / position</label><input type="text" name="title" value="' . pm_h($in['title']) . '" autocomplete="organization-title"></div>'
    . '<div><label>Email for your signed copy</label><input type="email" name="email" value="' . pm_h($in['email']) . '" required autocomplete="email"></div>'
    . '<div><label>Business TPIN (optional)</label><input type="text" name="tpin" value="' . pm_h($in['tpin']) . '"></div></div>'
    . '<div class="tabs"><button type="button" class="on" data-m="drawn">Draw</button><button type="button" data-m="typed">Type</button></div>'
    . '<div class="pad" id="padbox"><canvas id="pad"></canvas><div class="line"></div><div class="hint">Sign above the line with your finger or mouse</div><button type="button" class="clear" id="clear">Clear</button></div>'
    . '<div id="typedbox" style="display:none"><div class="typed" id="typedpreview">' . pm_h($in['name']) . '</div><input type="hidden" name="typed" id="typed" value="' . pm_h($in['name']) . '"></div>'
    . '<label class="agree"><input type="checkbox" name="agree" value="1" id="agree"><span>' . pm_h($consent) . '</span></label>'
    . '<button class="btn primary" id="go" disabled>Accept and sign</button>'
    . '<p style="font-size:12px;color:var(--muted);margin:12px 0 0;text-align:center">We record the time, your IP address and device with your signature, and email a signed copy to you and to ' . pm_h($s['company_name']) . '.</p>'
    . '</form></div>'
    . '<script>
(function(){
  var c=document.getElementById("pad"),box=document.getElementById("padbox"),ctx=c.getContext("2d"),drawing=false,strokes=0,last=null;
  function size(){var r=box.getBoundingClientRect(),d=window.devicePixelRatio||1,img=strokes?c.toDataURL():null;c.width=r.width*d;c.height=r.height*d;ctx.setTransform(d,0,0,d,0,0);ctx.lineWidth=2.4;ctx.lineCap="round";ctx.lineJoin="round";ctx.strokeStyle="#16203a";if(img){var i=new Image();i.onload=function(){ctx.drawImage(i,0,0,r.width,r.height)};i.src=img}}
  size();window.addEventListener("resize",size);
  function pos(e){var r=c.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top}}
  c.addEventListener("pointerdown",function(e){drawing=true;last=pos(e);c.setPointerCapture(e.pointerId);e.preventDefault()});
  c.addEventListener("pointermove",function(e){if(!drawing)return;var p=pos(e);ctx.beginPath();ctx.moveTo(last.x,last.y);ctx.lineTo(p.x,p.y);ctx.stroke();last=p;e.preventDefault()});
  function end(){if(drawing){drawing=false;strokes++;document.getElementById("strokes").value=strokes;check()}}
  c.addEventListener("pointerup",end);c.addEventListener("pointercancel",end);
  document.getElementById("clear").onclick=function(){ctx.clearRect(0,0,c.width,c.height);strokes=0;document.getElementById("strokes").value=0;check()};
  var method=document.getElementById("method"),typed=document.getElementById("typed"),prev=document.getElementById("typedpreview"),name=document.getElementById("name");
  document.querySelectorAll(".tabs button").forEach(function(b){b.onclick=function(){document.querySelectorAll(".tabs button").forEach(function(x){x.classList.remove("on")});b.classList.add("on");method.value=b.dataset.m;
    box.style.display=b.dataset.m==="drawn"?"":"none";document.getElementById("typedbox").style.display=b.dataset.m==="typed"?"":"none";if(b.dataset.m==="drawn")size();check()}});
  name.addEventListener("input",function(){typed.value=name.value;prev.textContent=name.value||" ";check()});
  var agree=document.getElementById("agree"),go=document.getElementById("go");
  function check(){var ok=agree.checked&&name.value.trim().length>2&&(method.value==="typed"?typed.value.trim().length>2:strokes>0);go.disabled=!ok}
  agree.addEventListener("change",check);
  document.getElementById("sf").addEventListener("submit",function(){if(method.value==="drawn"){document.getElementById("sig").value=c.toDataURL("image/png")}go.disabled=true;go.textContent="Signing…"});
  check();
})();
</script>';
pm_page($s, $r['ref'], $body);
