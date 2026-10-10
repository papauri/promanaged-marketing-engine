<?php
/**
 * ProManaged IT — Proposals
 * Fill in a client, generate the proposal/contract PDF, email it, and let the client sign online.
 * Locally: start.bat. On a website: needs APP_PASSWORD (login) and APP_URL (signing links) in .env.
 */
declare(strict_types=1);
session_start();
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/pdf.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/agents.php';
require __DIR__ . '/lib/engage.php';
require_once __DIR__ . '/lib/wa_biz.php'; // WhatsApp Business: approved answers and campaigns are sent from here (off without keys in .env)
require __DIR__ . '/lib/social_growth.php';
require __DIR__ . '/lib/social_modules.php'; // Social extension registry + every lib/sx_*.php module

// ---------- Access ----------
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$appPassword = (string)(pm_env()['APP_PASSWORD'] ?? '');
if (!$isLocal) {
    if ($appPassword === '') {
        http_response_code(403);
        exit('This app is only open on the computer it runs on. Set APP_PASSWORD in .env to use it from other devices.');
    }
    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: index.php');
        exit;
    }
    if (empty($_SESSION['pm_auth'])) {
        $bad = false;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
            usleep(400000); // slow down guessing
            if (hash_equals($appPassword, (string)$_POST['login_password'])) {
                session_regenerate_id(true);
                $_SESSION['pm_auth'] = true;
                header('Location: index.php');
                exit;
            }
            $bad = true;
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Sign in</title>'
            . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7f8;font:15px -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#16181d}'
            . 'form{background:#fff;border:1px solid #e3e5e8;border-radius:12px;padding:32px;width:min(360px,90vw)}img{height:40px;margin-bottom:18px}'
            . 'input{width:100%;padding:11px;border:1px solid #e3e5e8;border-radius:8px;font:inherit;margin:6px 0 14px;box-sizing:border-box}'
            . 'button{width:100%;padding:12px;border:0;border-radius:8px;background:#17375E;color:#fff;font:inherit;font-weight:600;cursor:pointer}.e{color:#a33;font-size:13px}</style></head><body>'
            . '<form method="post"><img src="assets/logo.png" alt=""><div><b>Proposals</b></div><label style="font-size:12px;color:#6b7078">Password</label>'
            . '<input type="password" name="login_password" autofocus required>' . ($bad ? '<p class="e">That password is not right.</p>' : '') . '<button>Sign in</button></form></body></html>';
        exit;
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

$settings = pm_settings();
$tpl = pm_template();
$tab = $_GET['tab'] ?? 'agents';
if (isset($_GET['brand'])) {
    $_SESSION['abrand'] = $_GET['brand'] === 'travel' ? 'travel' : 'promanaged';
}
$vb = ($_SESSION['abrand'] ?? 'promanaged') === 'travel' ? 'travel' : 'promanaged'; // which brand's screens to show
$GLOBALS['PM_WHO'] = (string)($_SESSION['who'] ?? ''); // which team member is working (for notes and ownership)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function pm_redirect(string $tab, string $msg = '', string $kind = 'ok'): never
{
    if ($msg !== '') {
        $_SESSION['flash'] = [$kind, $msg];
    }
    header('Location: index.php?tab=' . (preg_match('/^[a-z]+([&#][A-Za-z0-9_=&%.+\-#]*)?$/', $tab) ? $tab : urlencode($tab))); // $tab may carry filters or a #anchor
    exit;
}

function pm_next_ref(array $s, bool $consume): string
{
    $ref = sprintf('%s-%s-%03d', $s['ref_prefix'] ?: 'PM', date('Y'), (int)$s['next_ref']);
    if ($consume) { // each business has its own numbering
        $stored = pm_load('settings', 'pm_default_settings');
        if (pm_brand() === 'travel') {
            $stored['travel']['next_ref'] = (int)$s['next_ref'] + 1;
        } else {
            $stored['next_ref'] = (int)$s['next_ref'] + 1;
        }
        pm_save('settings', $stored);
    }
    return $ref;
}

function pm_proposal_from_post(array $in, array $settings): array
{
    $t = fn($k) => trim((string)($in[$k] ?? ''));
    $extras = [];
    foreach ((array)($in['extras'] ?? []) as $i => $q) {
        if ((int)$q > 0) {
            $extras[(int)$i] = (int)$q;
        }
    }
    $num = fn($x) => (is_numeric($x) && (float)$x >= 0) ? (string)(float)$x : '';
    $extraPrice = [];
    foreach ((array)($in['extra_price'] ?? []) as $i => $x) {
        $extraPrice[(int)$i] = $num($x);
    }
    $cur = pm_currency($settings, $t('currency') ?: (string)($settings['default_quote_currency'] ?? $settings['currency']));
    $rate = (float)($in['rate'] ?? 0);
    return [
        'business' => $t('business'), 'contact' => $t('contact'), 'contact_title' => $t('contact_title'),
        'email' => $t('email'), 'phone' => $t('phone'), 'address' => $t('address'), 'tpin' => $t('tpin'),
        'package' => (int)($in['package'] ?? 0), 'extras' => $extras,
        'currency' => $cur['code'], 'rate' => $rate > 0 ? $rate : (float)$cur['rate'],
        'price_setup' => $num($in['price_setup'] ?? ''), 'price_monthly' => $num($in['price_monthly'] ?? ''),
        'extra_price' => $extraPrice,
        'discount_setup' => (float)($in['discount_setup'] ?? 0), 'discount_monthly' => (float)($in['discount_monthly'] ?? 0),
        'doc_type' => in_array($in['doc_type'] ?? '', ['pitch', 'contract', 'both'], true) ? $in['doc_type'] : 'both',
        'date' => $t('date') ?: date('Y-m-d'), 'start_date' => $t('start_date'), 'personal_note' => $t('personal_note'),
        'cc' => $t('cc'), 'subject' => $t('subject'), 'body' => (string)($in['body'] ?? ''),
        'ai_edited' => !empty($in['edit_use']) ? pm_parse_edit((array)($in['edit'] ?? [])) : null,
        'charge' => !empty($in['charge']), 'price_mode' => ($in['price_mode'] ?? '') === 'from' ? 'from' : 'exact',
        'ai' => !empty($in['clear_ai']) ? null : (!empty($in['edit_use']) ? pm_parse_edit((array)($in['edit'] ?? [])) : ($_SESSION['draft']['ai'] ?? null)), 'lead_id' => (string)($_SESSION['draft']['lead_id'] ?? ''),
    ];
}

/** Turn the "edit the wording" fields into the same structure the AI produces. */
function pm_parse_edit(array $e): ?array
{
    $lines = fn($k) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($e[$k] ?? '')))));
    $pains = [];
    foreach ($lines('pain_points') as $row) {
        $c = array_pad(array_map('trim', explode('|', $row, 4)), 4, '');
        if ($c[0] !== '' && $c[1] !== '') {
            $pains[] = ['pain' => $c[0], 'cost' => $c[1], 'fix' => $c[2], 'fact' => $c[3]];
        }
    }
    $ai = ['cover_hook' => trim((string)($e['cover_hook'] ?? '')), 'intro' => trim((string)($e['intro'] ?? '')), 'what_we_know' => $lines('what_we_know'), 'pain_points' => $pains, 'gains' => $lines('gains')];
    return ($ai['cover_hook'] || $ai['intro'] || $ai['what_we_know'] || $pains || $ai['gains']) ? $ai : null;
}

/** The template in this proposal's currency. */
function pm_tpl_for(array $settings, array $tpl, array $p): array
{
    $c = pm_currency($settings, $p['currency']);
    $type = (string)($tpl['packages'][(int)$p['package']]['type'] ?? '');
    $tpl['charging'] = !empty($p['charge']); // Travel Malawi onboarding: free unless "charge" is ticked
    $tpl['price_from'] = ($p['price_mode'] ?? 'exact') === 'from'; // quotation shows "From" prices
    $t = pm_template_in(pm_apply_line($tpl, $type), (float)$p['rate'], (float)$c['round']);
    return pm_apply_ai($t, $p['ai'] ?? null, $type);
}

// ---------- Branding kit pictures (download) ----------
if (isset($_GET['kit']) && preg_match('/^(promanaged|travel)$/', (string)($_GET['b'] ?? '')) && preg_match('/^(profile-\d+|cover-\d+x\d+)\.png$/', (string)$_GET['kit'])) {
    $kf = pm_kit_dir($_GET['b']) . '/' . $_GET['kit'];
    if (!is_file($kf)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    if (isset($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="' . ($_GET['b'] === 'travel' ? 'TravelMalawi' : 'ProManagedIT') . '-' . $_GET['kit'] . '"');
    }
    readfile($kf);
    exit;
}

// ---------- Social post pictures ----------
if (isset($_GET['simg']) && preg_match('/^[a-f0-9]{20}$/', (string)$_GET['simg'])) {
    $sf = pm_social_img_file((string)$_GET['simg'], (string)($_GET['size'] ?? 'sq'), (int)($_GET['slide'] ?? 0)); // unknown sizes fall back to the square
    if ($sf === '') {
        http_response_code(404);
        exit;
    }
    $sjpg = (bool)preg_match('/\.jpe?g$/i', $sf);
    header('Content-Type: ' . ($sjpg ? 'image/jpeg' : 'image/png'));
    header('Cache-Control: private, max-age=300');
    if (isset($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="post-' . $_GET['simg'] . (preg_match('/^[A-Za-z0-9]{1,8}$/', (string)($_GET['size'] ?? '')) ? '-' . $_GET['size'] : '') . ((int)($_GET['slide'] ?? 0) > 0 ? '-' . (int)$_GET['slide'] : '') . ($sjpg ? '.jpg' : '.png') . '"');
    }
    readfile($sf);
    exit;
}
if (isset($_GET['sslides']) && preg_match('/^[a-f0-9]{20}$/', (string)$_GET['sslides'])) { // all slides of a carousel as one zip
    $sz = array_values(array_filter(pm_social_posts(), fn($p) => $p['id'] === $_GET['sslides']))[0] ?? null;
    $spaths = $sz && function_exists('pm_card_slides') ? array_values(array_filter((array)pm_card_slides($sz, '4x5'), 'is_file')) : [];
    if (!$spaths || !class_exists('ZipArchive')) {
        http_response_code($spaths ? 500 : 404);
        exit($spaths ? 'The server has no zip support (php-zip).' : 'No slides for this post.');
    }
    $ztmp = tempnam(sys_get_temp_dir(), 'sl');
    $zip = new ZipArchive();
    if ($zip->open($ztmp, ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Could not make the zip.');
    }
    foreach ($spaths as $zi => $zf) {
        $zip->addFile($zf, sprintf('slide-%02d.png', $zi + 1));
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="slides-' . $_GET['sslides'] . '.zip"');
    header('Content-Length: ' . filesize($ztmp));
    readfile($ztmp);
    @unlink($ztmp);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Scheduled posts go out when the app is in use too (throttled), unless the scheduler (cron) ran in the last 5 minutes.
    // The session is released first: a slow publish must not freeze every other page of this user.
    $hbAge = pm_social_heartbeat_age();
    if ($hbAge === null || $hbAge >= 300) {
        session_write_close();
        try {
            pm_social_due();
        } catch (Throwable) {
        }
        @session_start();
    }
}

// ---------- Link thumbnail (data/ is private, so it is served here) ----------
if (isset($_GET['thumb'])) {
    $tf = pm_link_thumb_path($_GET['thumb'] === 'travel' ? 'travel' : 'promanaged');
    if (!is_file($tf)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    readfile($tf);
    exit;
}

// ---------- Live progress of the agents (polled by the Agents screen) ----------
if (isset($_GET['runstatus'])) {
    pm_brand_set((string)($_SESSION['abrand'] ?? 'promanaged'));
    $rs = pm_run_state();
    $new = 0;
    if (($rs['state'] ?? '') === 'running') {
        $since = (string)substr((string)($rs['started'] ?? ''), 0, 16);
        foreach (pm_leads() as $lx) {
            $new += (($lx['brand'] ?? 'promanaged') === pm_brand() && (string)($lx['created'] ?? '') >= $since) ? 1 : 0;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['state' => $rs['state'] ?? 'idle', 'phase' => $rs['phase'] ?? '', 'step' => (int)($rs['step'] ?? 0), 'steps' => (int)($rs['steps'] ?? 7), 'new_leads' => $new]);
    exit;
}

// ---------- Serve a saved PDF ----------
if (isset($_GET['file'])) {
    $f = PM_OUT . '/' . basename((string)$_GET['file']);
    if (!is_file($f) || strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'pdf') {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . basename($f) . '"');
    readfile($f);
    exit;
}

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        pm_redirect($tab, 'That upload is bigger than this server accepts (post_max_size ' . ini_get('post_max_size') . ', upload_max_filesize ' . ini_get('upload_max_filesize') . '). Use a smaller file.', 'err');
    }
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        pm_redirect($tab, 'Your session expired. Please try again.', 'err');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'proposal') {
        $p = pm_proposal_from_post($_POST, $settings);
        pm_brand_set(pm_brand_of_type((string)($tpl['packages'][$p['package']]['type'] ?? ''))); // Travel Malawi packages speak as Travel Malawi
        $settings = pm_settings();
        $_SESSION['draft'] = $p;
        $do = $_POST['do'] ?? 'preview';
        if (!empty($_POST['save_prices']) || $do === 'save_prices') {
            // Prices on this screen are in the quote currency; the standard price list is in the base currency.
            $toBase = fn($v) => round((float)$v / max(0.000001, (float)$p['rate']), 2);
            $pi = $p['package'];
            if (isset($tpl['packages'][$pi])) {
                if ($p['price_setup'] !== '') { $tpl['packages'][$pi]['setup'] = $toBase($p['price_setup']); }
                if ($p['price_monthly'] !== '') { $tpl['packages'][$pi]['monthly'] = $toBase($p['price_monthly']); }
            }
            foreach ($p['extra_price'] as $ei => $ep) {
                if ($ep !== '' && isset($tpl['extras'][$ei])) { $tpl['extras'][$ei]['price'] = $toBase($ep); }
            }
            pm_save('template', $tpl);
            if ($do === 'save_prices') {
                pm_redirect('proposal', 'Standard prices updated (stored in ' . $settings['currency'] . '). Every new proposal uses them from now on.');
            }
        }
        if ($p['business'] === '') {
            pm_redirect('proposal', 'Enter the client\'s business name first.', 'err');
        }

        if ($do === 'polish') {
            @set_time_limit(300); // two AI passes can take a minute
            $pkg = $tpl['packages'][(int)$p['package']] ?? [];
            $lead = pm_leads()[$p['lead_id']] ?? null;
            try {
                $ai = pm_agent_polish($p, pm_apply_line($tpl, (string)($pkg['type'] ?? '')), $pkg, $lead);
            } catch (Throwable $e) {
                pm_redirect('proposal', 'The AI could not tailor this proposal: ' . $e->getMessage(), 'err');
            }
            $p['ai'] = ['cover_hook' => (string)($ai['cover_hook'] ?? ''), 'intro' => (string)($ai['intro'] ?? ''), 'pain_points' => (array)$ai['pain_points']];
            $p['personal_note'] = trim((string)($ai['personal_note'] ?? '')) ?: $p['personal_note'];
            $p['subject'] = trim((string)($ai['email_subject'] ?? '')) ?: $p['subject'];
            $p['body'] = trim((string)($ai['email_body'] ?? '')) ?: $p['body'];
            $_SESSION['draft'] = $p;
            pm_redirect('proposal', 'Tailored for ' . $p['business'] . '. Preview the PDF and read the email before you send: the AI only used facts we hold about them.');
        }

        $tplQ = pm_tpl_for($settings, $tpl, $p);
        pm_set_free(!empty($tplQ['free']));
        pm_set_from(!empty($tplQ['price_from']));
        // A pitch has nothing to sign but still gets a tracked link (opens are counted, the client can answer with one tap).
        $canSign = pm_app_url() !== '' && ($p['doc_type'] === 'pitch' || !empty($settings['online_signing']));

        if ($do === 'preview') {
            $ref = pm_next_ref($settings, false) . '-DRAFT';
            $file = pm_build_pdf($settings, $tplQ, $p, $ref, ['sign_url' => $canSign ? pm_sign_url(str_repeat('0', 32)) : '']);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($file) . '"');
            readfile($file);
            @unlink($file);
            exit;
        }

        if ($do === 'email') { // never to someone who asked us to stop
            $lx = pm_leads();
            $lxl = $lx[$p['lead_id']] ?? null;
            if (($lxl['status'] ?? '') === 'optout' || pm_suppressed($lx, (string)$p['email'], (string)($lxl['id'] ?? ''))) {
                pm_redirect('proposal', ($p['business'] ?: 'That address') . ' asked not to be contacted. Nothing was sent.', 'err');
            }
        }
        if ($do === 'email') { // email bodies never carry prices: they are in the attached proposal
            $rawMail = $tplQ['emails'][$p['doc_type']] ?? $tplQ['emails']['both'];
            $baseM = $tpl['emails'][$p['doc_type']] ?? $tpl['emails']['both'];
            $rawBody = (trim($p['body']) === '' || trim($p['body']) === trim($baseM['body'])) ? $rawMail['body'] : $p['body'];
            if (pm_has_price($rawBody)) {
                pm_redirect('proposal', 'The email text mentions a price. Prices belong in the attached proposal, not the email: remove the amount from the email and try again.', 'err');
            }
        }

        // Guard against prices that are missing zeros or missing altogether.
        $chk = pm_quote($tplQ, $p);
        $step = (float)pm_currency($settings, $p['currency'])['round'];
        foreach (['price_setup' => 'setup', 'price_monthly' => 'monthly'] as $f => $lab) {
            if ($p[$f] !== '' && (float)$p[$f] > 0 && $step >= 100 && (float)$p[$f] < $step * 10) {
                pm_redirect('proposal', "The $lab price you typed is {$p['currency']} " . number_format((float)$p[$f]) . '. Prices on this form are in ' . $p['currency'] . ' and are normally in the hundreds of thousands. Add the missing zeros, or clear the box to use the standard price.', 'err');
            }
        }
        if ($chk['setup'] <= 0 && $chk['monthly'] <= 0 && empty($tplQ['free'])) {
            pm_redirect('proposal', 'This package has no price yet. Type the setup and monthly price on the form, or set the package price in the Template tab.', 'err');
        }

        $ref = pm_next_ref($settings, true);
        $token = bin2hex(random_bytes(16));
        $p['sign_url'] = $canSign ? pm_sign_url($token) : '';
        $file = pm_build_pdf($settings, $tplQ, $p, $ref, ['sign_url' => $p['sign_url']]);
        $q = pm_quote($tplQ, $p);
        $docLabel = $p['doc_type'] === 'pitch' ? 'Proposal' : ($p['doc_type'] === 'contract' ? 'Service Agreement' : 'Proposal and Service Agreement');
        pm_record_save([
            'token' => $token, 'ref' => $ref, 'created' => date('Y-m-d H:i:s'), 'sent_at' => null,
            'doc_label' => $docLabel, 'brand' => pm_brand(), 'p' => $p, 'tpl' => $tplQ, 'file' => basename($file), 'hash' => hash_file('sha256', $file),
            'valid_until' => date('Y-m-d', strtotime($p['date'] . ' +' . (int)$tpl['valid_days'] . ' days')),
            'status' => 'created', 'viewed_at' => null, 'signed' => null,
        ]);
        $entry = [
            'token' => $token, 'ref' => $ref, 'when' => date('Y-m-d H:i'), 'business' => $p['business'], 'contact' => $p['contact'],
            'email' => $p['email'], 'package' => $q['package']['name'], 'currency' => $p['currency'], 'setup' => $q['setup'], 'monthly' => $q['monthly'],
            'doc_type' => $p['doc_type'], 'file' => basename($file), 'status' => 'Downloaded', 'proposal' => $p,
            'sign_url' => $p['sign_url'],
        ];

        if ($do === 'email') {
            $vars = pm_email_vars($settings, $tplQ, $p, $q, $ref);
            $mail = $tplQ['emails'][$p['doc_type']] ?? $tplQ['emails']['both'];
            $baseMail = $tpl['emails'][$p['doc_type']] ?? $tpl['emails']['both'];
            // The form is pre-filled with the standard email. If you left it unchanged, use this package's own wording.
            $useOwn = fn($posted, $base) => trim($posted) === '' || trim($posted) === trim($base);
            $subject = pm_fill($useOwn($p['subject'], $baseMail['subject']) ? $mail['subject'] : $p['subject'], $vars);
            $body = pm_fill($useOwn($p['body'], $baseMail['body']) ? $mail['body'] : $p['body'], $vars);
            $mres = pm_mail($settings, [
                'to' => $p['email'], 'cc' => $p['cc'], 'subject' => $subject, 'body' => $body, 'attachments' => [$file],
                'button' => $p['sign_url'] ? [$p['doc_type'] === 'pitch' ? 'View your proposal online' : 'Review and sign online', $p['sign_url']] : null, 'link' => pm_link_card(),
            ]);
            [$ok, $err] = $mres;
            $msgId = (string)($mres[2] ?? '');
            $entry['status'] = $ok ? 'Emailed ' . date('j M, H:i') : 'Email failed';
            if ($ok) {
                $rec = pm_record_load($token);
                $rec['sent_at'] = date('Y-m-d H:i:s');
                $rec['status'] = 'sent';
                pm_record_save($rec);
                $nowM = date('Y-m-d H:i');
                pm_funnel_mark((string)($p['lead_id'] ?? ''), (string)$p['email'], (string)$p['business'], 'proposal', [ // the pipeline moves on its own
                    'proposal_ref' => $ref, 'proposal_sent_at' => $nowM, 'last_out' => $nowM, 'awaiting_reply_since' => null,
                    'msg_ids' => pm_lead_msgids_merged((string)($p['lead_id'] ?? ''), (string)$p['email'], $msgId)]);
            }
            $h = pm_history();
            array_unshift($h, $entry);
            pm_save('history', $h);
            if ($ok) {
                unset($_SESSION['draft']);
                pm_redirect('history', "Sent $ref to {$p['email']}.");
            }
            pm_redirect('proposal', "The PDF was created ($ref) but the email did not send: $err", 'err');
        }

        $h = pm_history();
        array_unshift($h, $entry);
        pm_save('history', $h);
        if (!empty($p['lead_id'])) {
            pm_funnel_mark((string)$p['lead_id'], '', (string)$p['business'], 'proposal', ['proposal_ref' => $ref, 'proposal_sent_at' => date('Y-m-d H:i')]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        readfile($file);
        exit;
    }

    if ($action === 'agents') {
        @set_time_limit(300); // reading the inbox and asking the AI can take a while
        pm_brand_set((string)($_SESSION['abrand'] ?? 'promanaged'));
        $settings = pm_settings();
        $do = (string)($_POST['do'] ?? '');
        $acfg = pm_agents_config();
        $leads = pm_leads();
        $id = (string)($_POST['id'] ?? '');
        $lead = $leads[$id] ?? null;
        // After any action, return to exactly where the user was: the same lead (focused or in the list) with the same filters.
        $ctxS = preg_match('/^[a-z]+$/', (string)($_POST['rs'] ?? '')) ? $_POST['rs'] : '';
        $ctxT = (string)($_POST['rt'] ?? '');
        $keep = ($ctxS !== '' ? '&status=' . $ctxS : '') . ($ctxT !== '' ? '&type=' . urlencode($ctxT) : '');
        $back = function (string $m = '', string $k = 'ok') use (&$id, $do, $keep) {
            if (($_POST['bt'] ?? '') === 'whatsapp') {
                pm_redirect('whatsapp', $m, $k);
            }
            $noLead = in_array($do, ['delete', 'lead_forget', 'plan', 'run', 'find_names', 'config', 'director', 'refresh_models', 'check_replies', 'add', 'approve_send', 'unapprove_send', 'dismiss_unmatched'], true);
            if ($id !== '' && !$noLead) {
                pm_redirect(!empty($_POST['rl']) ? 'agents&lead=' . $id : 'agents' . $keep . '#l' . $id, $m, $k);
            }
            pm_redirect('agents' . ($do === 'delete' || $do === 'plan' ? $keep : ''), $m, $k);
        };

        if ($do === 'run') {
            if (!pm_agents_ready()) {
                $back('Add ' . pm_agents_missing_key() . ' to .env first (see the notice on this page).', 'err');
            }
            $back(pm_agents_launch() ? 'The agents are out searching. This page shows progress; it takes a few minutes.' : 'The agents are already running.');
        }
        if ($do === 'find_names') {
            if (!pm_agents_ready()) {
                $back('Add ' . pm_agents_missing_key() . ' to .env first.', 'err');
            }
            $back(pm_agents_launch('names') ? 'Looking for owner and manager names. This page shows progress.' : 'The agents are already running.');
        }
        if ($do === 'lead_forget' && $id !== '') { // MARKETING.md MG-G03: privacy
            $r = function_exists('pm_lead_forget') ? pm_lead_forget($id) : ['ok' => false, 'msg' => 'Not available.'];
            pm_redirect('agents', $r['msg'], $r['ok'] ? 'ok' : 'err');
        }
        if ($do === 'restore_lead' && $id !== '') { // MARKETING.md C2-G02: bring a lead back from the archive
            [$okR, $msgR] = function_exists('pm_lead_unarchive') ? pm_lead_unarchive($id) : [false, 'Not available.'];
            pm_redirect('agents' . ($okR ? '&status=all#l' . $id : ''), $msgR, $okR ? 'ok' : 'err');
        }
        if ($do === 'export') { // MARKETING.md MG-G03: JSON export (no secrets)
            $json = function_exists('pm_data_export') ? pm_data_export(pm_brand()) : '{}';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="promanaged-' . pm_brand() . '-export-' . date('Y-m-d') . '.json"');
            echo $json;
            exit;
        }
        if ($do === 'config') {
            $list = fn($k) => array_values(array_filter(array_map('trim', explode(',', (string)($_POST['cfg'][$k] ?? '')))));
            $c = $acfg;
            $c['cities'] = $list('cities');
            $c['sectors'] = $list('sectors');
            $c['offerings'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['cfg']['offerings'] ?? '')))));
            $c['daily_token_budget'] = max(0, (int)($_POST['cfg']['daily_token_budget'] ?? 400000));
            $c['quality'] = ($_POST['cfg']['quality'] ?? '') === 'balanced' ? 'balanced' : 'economy';
            $gm = (string)($_POST['cfg']['gemini_model'] ?? 'economy');
            $c['reply_mode'] = ($_POST['cfg']['reply_mode'] ?? '') === 'auto' ? 'auto' : 'draft';
            $c['wa_reply_mode'] = ($_POST['cfg']['wa_reply_mode'] ?? '') === 'draft' ? 'draft' : 'auto';
            $c['gemini_model'] = ($gm === 'auto' || $gm === 'economy' || preg_match('/^gemini-[a-z0-9.\-]+$/', $gm)) ? $gm : 'economy';
            foreach (['new_per_day' => [1, 50], 'scouts_per_day' => [1, 12], 'send_cap' => [0, 50], 'wa_cap' => [0, 150], 'followup_days' => [1, 30], 'max_followups' => [0, 5], 'parallel' => [1, 6]] as $k => [$lo, $hi]) {
                $c[$k] = max($lo, min($hi, (int)($_POST['cfg'][$k] ?? $c[$k])));
            }
            foreach (array_keys($c['enabled']) as $k) {
                $c['enabled'][$k] = !empty($_POST['cfg']['enabled'][$k]);
            }
            $raw = pm_load('agents_config', 'pm_agents_default_config');
            if (pm_brand() === 'travel') { // Travel Malawi keeps its own targets and limits; the AI settings are shared
                $raw['reply_mode'] = $c['reply_mode'];
                $raw['wa_reply_mode'] = $c['wa_reply_mode'];
                $raw['gemini_model'] = $c['gemini_model'];
                $raw['parallel'] = $c['parallel'];
                $raw['daily_token_budget'] = $c['daily_token_budget'];
                $raw['quality'] = $c['quality'];
                $raw['travel'] = array_intersect_key($c, array_flip(['sectors', 'cities', 'new_per_day', 'scouts_per_day', 'send_cap', 'wa_cap', 'followup_days', 'max_followups', 'proposals_per_day', 'enabled']));
                pm_save('agents_config', $raw);
            } else {
                $c['travel'] = $raw['travel'] ?? [];
                pm_save('agents_config', $c);
            }
            $back('Agent settings saved.');
        }
        if ($do === 'lookup') {
            $b = in_array($_POST['brand'] ?? '', ['travel', 'promanaged'], true) ? $_POST['brand'] : pm_brand();
            pm_brand_set($b);
            $_SESSION['abrand'] = $b;
            $settings = pm_settings();
            $acfg = pm_agents_config();
            $name = trim((string)($_POST['name'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            if ($name === '') {
                $back('Type the business name.', 'err');
            }
            try {
                $r = pm_agent_lookup($name, $city);
            } catch (Throwable $e) {
                $back('The search failed: ' . $e->getMessage(), 'err');
            }
            if (!$r) {
                $back("Could not find \"$name\"" . ($b === 'travel' ? ' as a place to stay' : '') . ' on the web. Check the spelling, add the city, or add it yourself below.', 'err');
            }
            $city = trim((string)($r['city'] ?? '')) ?: $city ?: 'Malawi';
            $nid = pm_lead_id((string)$r['name'], $city, $b);
            $dupe = function_exists('pm_lead_find_dupe') ? pm_lead_find_dupe($leads, ['name' => (string)$r['name'], 'city' => $city, 'website' => (string)($r['website'] ?? ''),
                'phone' => (string)($r['phone'] ?? ''), 'email' => (string)($r['email'] ?? '')], $b) : null;
            if ($dupe !== null && isset($leads[$dupe])) {
                $nid = $dupe; // the same business under another name or town: update it instead of adding a twin
            }
            $isNew = !isset($leads[$nid]);
            $nowLead = $leads[$nid] ?? ['id' => $nid, 'brand' => $b, 'name' => trim((string)$r['name']), 'type' => '', 'city' => $city, 'address' => '', 'website' => '', 'phone' => '', 'email' => '',
                'whatsapp' => '', 'contact' => '', 'contact_title' => '', 'evidence' => [], 'need_signals' => [], 'score' => 0, 'status' => 'qualified', 'notes' => [], 'drafts' => [], 'sent' => [],
                'followups' => 0, 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i')];
            foreach (['type', 'address', 'website', 'phone', 'email', 'contact', 'contact_title'] as $f) {
                if (trim((string)($r[$f] ?? '')) !== '' && trim((string)($nowLead[$f] ?? '')) === '') {
                    $nowLead[$f] = trim((string)$r[$f]);
                }
            }
            if ($nowLead['email'] !== '' && !filter_var($nowLead['email'], FILTER_VALIDATE_EMAIL)) {
                $nowLead['email'] = '';
            }
            $nowLead['evidence'] = array_values(array_unique(array_merge((array)$nowLead['evidence'], array_map('strval', (array)($r['evidence'] ?? [])))));
            foreach (['facebook', 'instagram'] as $sf) {
                if (trim((string)($r[$sf] ?? '')) !== '') {
                    $nowLead[$sf] = trim((string)$r[$sf]);
                }
            }
            $nowLead['social_gaps'] = array_values(array_filter(array_map('strval', (array)($r['social_gaps'] ?? []))));
            $nowLead['need_signals'] = array_values((array)($r['need_signals'] ?? []));
            $nowLead['offering'] = pm_clean_offering($r['offering'] ?? '');
            $nowLead['score'] = max(0, min(100, (int)($r['score'] ?? 0)));
            $nowLead['pain'] = (string)($r['pain'] ?? '');
            $nowLead['reason'] = (string)($r['reason'] ?? '');
            $maxPk = count($tpl['packages']) - 1;
            $nowLead['package'] = $b === 'travel' ? pm_onboarding_index() : max(0, min($maxPk, (int)($r['package'] ?? 0)));
            if (in_array($nowLead['status'], ['new'], true)) {
                $nowLead['status'] = 'qualified';
            }
            pm_lead_note($nowLead, 'Found by name search');
            $leads[$nid] = $nowLead;
            pm_leads_save($leads);
            if (empty($_POST['proposal'])) {
                $id = $nid;
                $_POST['rl'] = '1'; // jump straight into this business
                $back('Found ' . $nowLead['name'] . ' (' . $nowLead['city'] . '). Draft the outreach or create the proposal below.');
            }
            try {
                $pk = $tpl['packages'][$nowLead['package']] ?? [];
                $ai = pm_agent_polish(pm_lead_to_draft($nowLead), pm_apply_line($tpl, (string)($pk['type'] ?? '')), $pk, $nowLead);
            } catch (Throwable $e) {
                $back('Found ' . $nowLead['name'] . ', but the proposal step failed: ' . $e->getMessage(), 'err');
            }
            $leads[$nid]['proposal_ai'] = [
                'ai' => ['cover_hook' => (string)($ai['cover_hook'] ?? ''), 'intro' => (string)($ai['intro'] ?? ''), 'what_we_know' => (array)($ai['what_we_know'] ?? []), 'pain_points' => (array)$ai['pain_points'], 'gains' => (array)($ai['gains'] ?? [])],
                'personal_note' => (string)($ai['personal_note'] ?? ''), 'subject' => (string)($ai['email_subject'] ?? ''), 'body' => (string)($ai['email_body'] ?? ''), 'at' => date('Y-m-d H:i'),
            ];
            pm_leads_save($leads);
            $_SESSION['draft'] = pm_lead_to_draft($leads[$nid]);
            pm_redirect('proposal', 'Found ' . $nowLead['name'] . ' and tailored a proposal for ' . ($b === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . '. Read it, edit anything below, preview the PDF, then send.');
        }
        if ($do === 'director') {
            @set_time_limit(120);
            pm_director_brief(true);
            $back("Today's strategy refreshed.");
        }
        if ($do === 'check_replies') {
            try {
                [, $msg] = pm_inbox_poll('pm_send_reply');
            } catch (Throwable $e) {
                $back('Could not read the inbox: ' . $e->getMessage(), 'err');
            }
            $back($msg);
        }
        if ($do === 'approve_send' || $do === 'unapprove_send') {
            $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
            if (!$ids) {
                $back('Tick the leads first.', 'err');
            }
            if ($do === 'unapprove_send') {
                $n = 0;
                foreach ($ids as $i2) {
                    if (!empty($leads[$i2]['approved_at'])) {
                        unset($leads[$i2]['approved_at']);
                        pm_lead_note($leads[$i2], 'Taken out of the send queue');
                        $n++;
                    }
                }
                $n && pm_leads_save($leads);
                $back("$n taken out of the send queue.");
            }
            if (!function_exists('pm_approve_for_send')) {
                $back('Approve-and-send is not available in this install.', 'err');
            }
            $n = pm_approve_for_send($leads, $ids);
            $back($n . ' of ' . count($ids) . ' approved. They go out slowly during working hours' . ($n < count($ids) ? '; the rest need a verified email address and a clean draft.' : '.'), $n ? 'ok' : 'err');
        }
        if ($do === 'attach_unmatched' || $do === 'dismiss_unmatched') {
            $uid = (string)($_POST['uid'] ?? '');
            if ($do === 'dismiss_unmatched') {
                pm_inbox_unmatched_take($uid);
                $back('Dismissed.');
            }
            $to = (string)($_POST['to'] ?? '');
            $item = null;
            foreach (pm_inbox_unmatched_list() as $it) {
                if (($it['uid'] ?? '') === $uid) {
                    $item = $it;
                }
            }
            if (!$item || !isset($leads[$to])) {
                $back($item ? 'Pick the lead this reply belongs to.' : 'That message is already handled.', 'err');
            }
            pm_brand_set((string)($leads[$to]['brand'] ?? 'promanaged'));
            $id = $to;
            try {
                [$what] = pm_handle_incoming($leads[$to], (string)($item['text'] ?: $item['snippet']), 'email', [], 'draft', null);
            } catch (Throwable $e) {
                $back('The reply agent failed: ' . $e->getMessage(), 'err');
            }
            $from = strtolower((string)$item['from']);
            if ($from !== '' && $from !== strtolower((string)($leads[$to]['email'] ?? '')) && ($leads[$to]['status'] ?? '') !== 'optout') {
                $leads[$to]['alt_emails'] = array_slice(array_values(array_unique(array_merge((array)($leads[$to]['alt_emails'] ?? []), [$from]))), -5); // their next mail matches
            }
            if (!empty($item['msgid'])) {
                $leads[$to]['last_in_id'] = (string)$item['msgid'];
            }
            pm_leads_save($leads);
            pm_inbox_unmatched_take($uid);
            $back($leads[$to]['name'] . ': ' . $what . '.');
        }
        if ($do === 'refresh_models') {
            $m = pm_gemini_top_models(true);
            $back('Top Gemini models today: ' . implode(', ', $m) . '.');
        }
        if ($do === 'plan') {
            pm_plan_mark((string)($_POST['task'] ?? ''), !empty($_POST['done']));
            $back();
        }
        if ($do === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            if ($name === '' || $city === '') {
                $back('Give the business a name and a city.', 'err');
            }
            $nid = pm_lead_id($name, $city, pm_brand());
            if (isset($leads[$nid])) {
                $back('That business is already in your leads.', 'err');
            }
            $dupe = function_exists('pm_lead_find_dupe') ? pm_lead_find_dupe($leads, ['name' => $name, 'city' => $city, 'website' => trim((string)($_POST['website'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')), 'email' => trim((string)($_POST['email'] ?? ''))], pm_brand()) : null;
            if ($dupe !== null && isset($leads[$dupe])) {
                $back('That looks like ' . $leads[$dupe]['name'] . ' (' . $leads[$dupe]['city'] . '), already in your leads: same website, phone or email.', 'err');
            }
            $type = mb_substr(trim((string)($_POST['type'] ?? '')), 0, 60) ?: 'business';
            $leads[$nid] = ['id' => $nid, 'brand' => pm_brand(), 'name' => $name, 'type' => $type, 'city' => $city, 'address' => '', 'website' => trim((string)($_POST['website'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')), 'email' => trim((string)($_POST['email'] ?? '')), 'whatsapp' => '', 'contact' => trim((string)($_POST['contact'] ?? '')),
                'contact_title' => '', 'evidence' => [], 'need_signals' => [], 'score' => 0, 'status' => 'new', 'notes' => [], 'drafts' => [], 'sent' => [], 'followups' => 0,
                'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i')];
            pm_leads_save($leads);
            $back("Added $name. The next agent run will qualify it and draft a message.");
        }
        if (!$lead) {
            $back('That lead was not found.', 'err');
        }
        pm_brand_set((string)($lead['brand'] ?? 'promanaged')); // act as the brand this lead was approached by
        $settings = pm_settings();
        $acfg = pm_agents_config();
        if ($do === 'save_draft') {
            $key = ($_POST['which'] ?? '') === 'followup' ? 'followup_draft' : 'drafts';
            $leads[$id][$key] = ['email_subject' => trim((string)$_POST['email_subject']), 'email_body' => trim((string)$_POST['email_body']), 'whatsapp' => trim((string)$_POST['whatsapp'])];
            foreach (['email', 'phone', 'contact'] as $f) {
                $leads[$id][$f] = trim((string)($_POST[$f] ?? $lead[$f] ?? ''));
            }
            if ($key === 'drafts' && in_array($lead['status'], ['new', 'qualified'], true)) {
                $leads[$id]['status'] = 'drafted';
            }
            pm_leads_save($leads);
            $back('Draft saved.');
        }
        if ($do === 'send') {
            $which = ($_POST['which'] ?? '') === 'followup' ? 'followup_draft' : 'drafts';
            if (function_exists('pm_send_first')) {
                $res = pm_send_first($leads, $id, $which);
                if (!empty($res['ok'])) {
                    $leads[$id]['last_out'] = date('Y-m-d H:i');
                    unset($leads[$id]['awaiting_reply_since']);
                    pm_leads_save($leads);
                }
                $back((string)$res['msg'], !empty($res['ok']) ? 'ok' : 'err');
            }
            $d = $lead[$which] ?? [];
            if ($lead['status'] === 'optout') {
                $back($lead['name'] . ' asked not to be contacted.', 'err');
            }
            if (pm_sent_today($leads) >= $acfg['send_cap']) {
                $back("You have reached today's limit of {$acfg['send_cap']} emails. Raise it in Agent settings if you want more.", 'err');
            }
            if (empty($d['email_body']) || !filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $back('This lead needs a valid email address and a draft first.', 'err');
            }
            if (pm_suppressed($leads, $lead['email'], $id)) {
                $back('That address or company asked not to be contacted.', 'err');
            }
            if ($which === 'drafts' && !empty($lead['sent'])) {
                $back('You have already sent this business a first email. Use a follow-up instead.', 'err');
            }
            $dom = pm_email_domain($lead['email']);
            if (!in_array($dom, PM_FREE_MAIL, true)) {
                foreach ($leads as $o) {
                    foreach ((array)($o['sent'] ?? []) as $at) {
                        if (($o['id'] ?? '') !== $id && pm_email_domain((string)($o['email'] ?? '')) === $dom && strtotime((string)$at) > time() - 7 * 86400) {
                            $back("Someone at $dom was already emailed this week ({$o['name']}). Wait a week so one company is not contacted twice.", 'err');
                        }
                    }
                }
            }
            if ($problems = pm_outreach_lint((string)$d['email_subject'], (string)$d['email_body'], $which === 'drafts')) {
                $back('Not sent. ' . implode(' ', $problems) . ' Edit the draft and try again.', 'err');
            }
            $fromAddr = $settings['smtp']['from_email'] ?: $settings['email'];
            [$ok, $why, $msgId] = pm_mail($settings, ['to' => $lead['email'], 'subject' => $d['email_subject'], 'body' => $d['email_body'] . pm_outreach_footer($settings),
                'headers' => ['List-Unsubscribe' => '<mailto:' . $fromAddr . '?subject=STOP>', 'Auto-Submitted' => 'no'], 'link' => pm_link_card()]);
            if (!$ok) {
                $back('Could not send: ' . $why, 'err');
            }
            if (empty($leads[$id]['owner']) && ($GLOBALS['PM_WHO'] ?? '') !== '') {
                $leads[$id]['owner'] = $GLOBALS['PM_WHO']; // whoever sends it owns the follow-up
            }
            $leads[$id]['sent'][] = date('Y-m-d H:i');
            $leads[$id]['thread'][] = ['dir' => 'out', 'at' => date('Y-m-d H:i'), 'text' => $d['email_body']];
            $leads[$id]['last_contacted'] = $leads[$id]['last_out'] = date('Y-m-d H:i');
            pm_lead_add_msgid($leads[$id], (string)$msgId);
            $leads[$id]['status'] = 'contacted';
            if ($which === 'followup_draft') {
                $leads[$id]['followups'] = (int)($lead['followups'] ?? 0) + 1;
                unset($leads[$id]['followup_draft']);
            }
            pm_lead_note($leads[$id], ($which === 'drafts' ? 'First email' : 'Follow-up') . ' sent to ' . $lead['email']);
            pm_leads_save($leads);
            $back('Sent to ' . $lead['name'] . '.');
        }
        if ($do === 'paste_reply') {
            $text = trim((string)($_POST['text'] ?? ''));
            if ($text === '') {
                $back('Paste what they wrote first.', 'err');
            }
            try {
                [$what] = pm_handle_incoming($leads[$id], $text, ($_POST['ch'] ?? '') === 'wa' ? 'wa' : 'email', [], 'draft', null);
            } catch (Throwable $e) {
                $back('The reply agent failed: ' . $e->getMessage(), 'err');
            }
            pm_leads_save($leads);
            $back($lead['name'] . ': ' . $what . '.');
        }
        if ($do === 'save_reply') {
            $leads[$id]['reply_draft'] = array_merge((array)($lead['reply_draft'] ?? []), ['subject' => trim((string)($_POST['subject'] ?? ($lead['reply_draft']['subject'] ?? ''))), 'body' => trim((string)($_POST['body'] ?? ($lead['reply_draft']['body'] ?? ''))),
                'whatsapp' => trim((string)($_POST['whatsapp'] ?? ($lead['reply_draft']['whatsapp'] ?? '')))]);
            pm_leads_save($leads);
            $back('Reply saved.');
        }
        if ($do === 'send_reply') {
            $rd = (array)($lead['reply_draft'] ?? []);
            $rd['subject'] = trim((string)($_POST['subject'] ?? $rd['subject'] ?? ''));
            $rd['body'] = trim((string)($_POST['body'] ?? $rd['body'] ?? '')); // what is on screen is what gets sent
            if (trim((string)($rd['body'] ?? '')) === '' || !filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $back('This lead needs a valid email address and a reply to send.', 'err');
            }
            if ($problems = pm_outreach_lint((string)$rd['subject'], (string)$rd['body'], false)) {
                $back('Not sent. ' . implode(' ', $problems), 'err');
            }
            [$ok, $why, $msgId] = pm_send_reply($lead, (string)$rd['subject'], (string)$rd['body']);
            if (!$ok) {
                $back('Could not send: ' . $why, 'err');
            }
            pm_reply_sent($leads[$id], (string)$rd['body'], 'email', (string)$msgId); // replied -> contacted, followups 0, last_out, no longer awaiting
            $leads[$id]['last_contacted'] = date('Y-m-d H:i');
            pm_lead_note($leads[$id], 'Reply sent to ' . $lead['email']);
            pm_leads_save($leads);
            $back('Reply sent to ' . $lead['name'] . '.');
        }
        if ($do === 'send_reply_wa') { // C2-A04: an approved, drafted WhatsApp answer goes out through the Business API (inside the 24-hour window)
            $txt = trim((string)($_POST['whatsapp'] ?? ($lead['reply_draft']['whatsapp'] ?? '')));
            [$okW, $msgW] = pm_wa_biz_send_approved($leads[$id], $txt);
            $okW && pm_leads_save($leads);
            $back($msgW, $okW ? 'ok' : 'err');
        }
        if (in_array($do, ['save_winback', 'send_winback', 'discard_winback', 'winback_wa_sent'], true)) { // C2-A01: the win-back draft, reviewed by the owner
            $wb = (array)($lead['winback_draft'] ?? []);
            if ($do !== 'discard_winback' && isset($_POST['email_body'])) {
                $wb = ['email_subject' => trim((string)($_POST['email_subject'] ?? '')), 'email_body' => trim((string)$_POST['email_body']), 'whatsapp' => trim((string)($_POST['whatsapp'] ?? ''))];
                $leads[$id]['winback_draft'] = $wb;
            }
            if ($do === 'save_winback') {
                pm_leads_save($leads);
                $back('Win-back draft saved.');
            }
            if ($do === 'discard_winback') {
                unset($leads[$id]['winback_draft']);
                $leads[$id]['winback_at'] = date('Y-m-d'); // not drafted again for 90 days
                pm_lead_note($leads[$id], 'Win-back draft discarded');
                pm_leads_save($leads);
                $back('Win-back draft discarded.');
            }
            if ($do === 'winback_wa_sent') {
                $now = date('Y-m-d H:i');
                $leads[$id]['wa_sent'][] = $now;
                $leads[$id]['thread'][] = ['dir' => 'out', 'at' => $now, 'text' => mb_substr((string)($wb['whatsapp'] ?? ''), 0, 1500), 'ch' => 'wa'];
                $leads[$id]['last_contacted'] = $leads[$id]['last_out'] = $leads[$id]['winback_sent_at'] = $now;
                $leads[$id]['status'] = 'contacted';
                $leads[$id]['followups'] = (int)$acfg['max_followups'];
                unset($leads[$id]['winback_draft']);
                pm_lead_note($leads[$id], 'Win-back WhatsApp sent');
                pm_leads_save($leads);
                $back('Logged the win-back WhatsApp to ' . $lead['name'] . '. The lead is back in play.');
            }
            $res = pm_send_first($leads, $id, 'winback_draft');
            if (empty($res['ok'])) {
                pm_leads_save($leads); // keep the edits even when the send was refused
            }
            $back((string)$res['msg'], !empty($res['ok']) ? 'ok' : 'err');
        }
        if (in_array($do, ['save_postsign', 'postsign_send', 'postsign_done'], true)) { // C2-A01: testimonial, review and referral asks after a signed deal
            $ps = (array)($lead['postsign'] ?? []);
            foreach (['testimonial', 'review', 'referral'] as $k) {
                if (isset($_POST['ps_' . $k])) {
                    $ps[$k] = trim((string)$_POST['ps_' . $k]);
                }
            }
            $leads[$id]['postsign'] = $ps;
            if ($do === 'postsign_done') {
                $leads[$id]['postsign_done'] = date('Y-m-d');
                pm_lead_note($leads[$id], 'Post-sign asks handled');
                pm_leads_save($leads);
                $back('Marked the thank-you asks as handled.');
            }
            if ($do === 'save_postsign') {
                pm_leads_save($leads);
                $back('Saved.');
            }
            $res = pm_send_postsign($leads, $id, (string)($_POST['which'] ?? ''));
            if (empty($res['ok'])) {
                pm_leads_save($leads); // keep the edits even when the send was refused
            }
            $back((string)$res['msg'], !empty($res['ok']) ? 'ok' : 'err');
        }
        if ($do === 'reverify_seen') {
            $leads[$id]['reverify']['seen'] = true;
            pm_leads_save($leads);
            $back();
        }
        if ($do === 'research') {
            @set_time_limit(180);
            try {
                $rr = pm_agent_research($lead);
            } catch (Throwable $e) {
                $back('The research failed: ' . $e->getMessage(), 'err');
            }
            pm_apply_research($leads[$id], $rr);
            pm_lead_note($leads[$id], 'Researched: ' . count($rr['discoveries']) . ' discoveries, ' . count($rr['news']) . ' news items');
            pm_leads_save($leads);
            $back('Research done for ' . $lead['name'] . '. Draft the outreach now and it will use what we found.');
        }
        if ($do === 'draft_outreach') {
            try {
                $w = pm_agent_write([$lead])[0] ?? null;
            } catch (Throwable $e) {
                $back('The writer failed: ' . $e->getMessage(), 'err');
            }
            if (!$w || empty($w['email_body'])) {
                $back('The writer did not return a draft. Try again.', 'err');
            }
            $leads[$id]['drafts'] = ['email_subject' => (string)$w['email_subject'], 'email_body' => (string)$w['email_body'], 'whatsapp' => (string)($w['whatsapp'] ?? '')];
            if (in_array($lead['status'], ['new', 'qualified'], true)) {
                $leads[$id]['status'] = 'drafted';
            }
            pm_leads_save($leads);
            $back('Outreach drafted for ' . $lead['name'] . '. Read it before you send.');
        }
        if ($do === 'assign') {
            $o = (string)($_POST['owner'] ?? '');
            $leads[$id]['owner'] = in_array($o, (array)($settings['team'] ?? []), true) ? $o : '';
            pm_lead_note($leads[$id], $leads[$id]['owner'] !== '' ? 'Assigned to ' . $leads[$id]['owner'] : 'Unassigned');
            pm_leads_save($leads);
            $back();
        }
        if ($do === 'whatsapp_sent') {
            $leads[$id]['last_contacted'] = date('Y-m-d H:i');
            if (in_array($lead['status'], ['new', 'qualified', 'drafted'], true)) {
                $leads[$id]['status'] = 'contacted';
            }
            pm_lead_note($leads[$id], 'WhatsApp message sent');
            pm_leads_save($leads);
            $back('Logged the WhatsApp message to ' . $lead['name'] . '.');
        }
        if ($do === 'status') {
            $st = (string)($_POST['status'] ?? '');
            if (array_key_exists($st, PM_LEAD_STATUSES)) {
                $lr = (string)($_POST['lost_reason'] ?? '');
                $leads[$id]['status'] = $st;
                if ($st === 'lost') {
                    $leads[$id]['lost_reason'] = isset(pm_lost_reasons()[$lr]) ? $lr : 'other';
                } else {
                    unset($leads[$id]['lost_reason']);
                }
                if ($st === 'won' && empty($lead['won_at'])) {
                    $leads[$id]['won_at'] = date('Y-m-d H:i');
                }
                if ($st === 'optout' && ($lead['status'] ?? '') !== 'optout') {
                    $leads[$id]['prev_status'] = (string)($lead['status'] ?? '');
                    $leads[$id]['optout_at'] = date('Y-m-d H:i');
                }
                pm_lead_note($leads[$id], 'Marked ' . PM_LEAD_STATUSES[$st] . ($st === 'lost' ? ' (' . (pm_lost_reasons()[$leads[$id]['lost_reason']] ?? '') . ')' : ''));
                pm_leads_save($leads);
            }
            $back();
        }
        if ($do === 'note') {
            if (trim((string)$_POST['text']) !== '') {
                pm_lead_note($leads[$id], trim((string)$_POST['text']));
                pm_leads_save($leads);
            }
            $back();
        }
        if ($do === 'proposal') {
            $_SESSION['draft'] = pm_lead_to_draft($lead);
            $_SESSION['abrand'] = (string)($lead['brand'] ?? 'promanaged');
            $pkg = $tpl['packages'][(int)($lead['package'] ?? 0)] ?? [];
            $unpriced = empty($pkg['setup']) && empty($pkg['monthly']);
            pm_redirect('proposal', 'Loaded ' . $lead['name'] . ' from your leads. Check the package and generate the proposal.' . ($unpriced ? ' This package has no price yet: type the price on the form or set it in the Template tab.' : ''), $unpriced ? 'err' : 'ok');
        }
        if ($do === 'delete') {
            unset($leads[$id]);
            pm_leads_save($leads);
            $back('Removed.');
        }
        $back();
    }

    if ($action === 'reuse') {
        $h = pm_history();
        $i = (int)($_POST['i'] ?? -1);
        if (isset($h[$i]['proposal'])) {
            $_SESSION['draft'] = $h[$i]['proposal'];
            $_SESSION['draft']['date'] = date('Y-m-d');
            $_SESSION['abrand'] = pm_brand_of_type((string)($tpl['packages'][(int)($h[$i]['proposal']['package'] ?? 0)]['type'] ?? ''));
        }
        pm_redirect('proposal', 'Loaded ' . ($h[$i]['business'] ?? 'proposal') . '. Change what you need and generate again.');
    }

    if ($action === 'clear') {
        unset($_SESSION['draft']);
        pm_redirect('proposal');
    }

    if ($action === 'template') {
        $in = $_POST['tpl'] ?? [];
        $rows = fn($k) => array_values(array_filter((array)($in[$k] ?? []), fn($r) => is_array($r) && implode('', array_map('strval', $r)) !== ''));
        $new = $tpl;
        foreach (['title', 'intro', 'cover_hook', 'pain_title', 'pain_intro', 'anchor_note', 'benefits_title', 'fees_note', 'steps_note', 'support_intro', 'support_note'] as $k) {
            $new[$k] = trim((string)($in[$k] ?? ''));
        }
        foreach (['pitch', 'contract', 'both'] as $et) {
            $new['emails'][$et] = [
                'subject' => trim((string)($in['emails'][$et]['subject'] ?? '')),
                'body' => str_replace("\r\n", "\n", (string)($in['emails'][$et]['body'] ?? '')),
            ];
        }
        $new['pain_points'] = array_map(fn($r) => [
            'type' => array_key_exists($r['type'] ?? '', pm_types()) ? $r['type'] : 'all',
            'pain' => trim($r['pain'] ?? ''), 'cost' => trim($r['cost'] ?? ''), 'fix' => trim($r['fix'] ?? ''),
        ], $rows('pain_points'));
        $new['valid_days'] = max(1, (int)($in['valid_days'] ?? 30));
        $new['packages'] = array_map(fn($r) => ['name' => trim($r['name'] ?? ''), 'type' => array_key_exists($r['type'] ?? '', pm_types()) ? $r['type'] : pm_guess_type($r['name'] ?? ''), 'suited' => trim($r['suited'] ?? ''), 'setup' => (float)($r['setup'] ?? 0), 'monthly' => (float)($r['monthly'] ?? 0)], $rows('packages'));
        $new['extras'] = array_map(fn($r) => ['name' => trim($r['name'] ?? ''), 'price' => (float)($r['price'] ?? 0), 'period' => in_array($r['period'] ?? '', ['month', 'once', 'note'], true) ? $r['period'] : 'note', 'note' => trim($r['note'] ?? '')], $rows('extras'));
        $pos = fn($k) => array_map(fn($r) => array_map(fn($v) => trim((string)$v), array_values($r)), $rows($k));
        $new['benefits'] = $pos('benefits');
        $new['steps'] = $pos('steps');
        $new['support'] = $pos('support');
        $new['terms'] = $pos('terms');
        $new['module_columns'] = array_values(array_filter(array_map('trim', explode(',', (string)($in['module_columns'] ?? '')))));
        $new['modules'] = array_map(function ($r) use ($new) {
            $r = array_map(fn($v) => trim((string)$v), array_values($r));
            return array_pad(array_slice($r, 0, 2 + count($new['module_columns'])), 2 + count($new['module_columns']), '');
        }, $rows('modules'));
        pm_save('template', $new);
        pm_redirect('template', 'Template saved. New PDFs use it straight away.');
    }

    if ($action === 'compose') { // AI message composer (fetch): returns JSON, saves nothing
        header('Content-Type: application/json');
        @set_time_limit(90);
        $lw = pm_leads()[(string)($_POST['id'] ?? '')] ?? null;
        $pick = fn($k, $allowed, $def) => in_array($_POST[$k] ?? '', $allowed, true) ? $_POST[$k] : $def;
        if (!$lw) {
            echo json_encode(['ok' => false, 'error' => 'Lead not found.']);
            exit;
        }
        try {
            $channel = $pick('channel', ['email', 'whatsapp'], 'email');
            $m = pm_agent_compose($lw, $channel, $pick('tone', array_keys(PM_COMPOSE_TONES), 'human'), $pick('length', array_keys(PM_COMPOSE_LENGTHS), 'short'),
                $pick('angle', array_keys(PM_COMPOSE_ANGLES), 'need'), $pick('lang', ['english', 'chichewa'], 'english'));
            if ($channel === 'whatsapp' && !empty($_POST['signed'])) {
                $m['body'] = pm_wa_text($lw, $m['body']); // queue cards show the message exactly as it will be sent
            }
            echo json_encode(['ok' => true, 'subject' => $m['subject'], 'body' => $m['body'], 'words' => str_word_count($m['body'])]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'wa_log') { // WhatsApp queue / reply box: checked and logged just before the chat opens (the browser then opens WhatsApp)
        header('Content-Type: application/json');
        $leadsW = pm_leads();
        $idw = (string)($_POST['id'] ?? '');
        if (!isset($leadsW[$idw])) {
            echo json_encode(['ok' => false, 'error' => 'Lead not found.']);
            exit;
        }
        pm_brand_set((string)($leadsW[$idw]['brand'] ?? 'promanaged'));
        $textW = trim((string)($_POST['text'] ?? ''));
        $isReply = !empty($_POST['reply']);
        $lw = &$leadsW[$idw];
        if (($lw['status'] ?? '') === 'optout') {
            echo json_encode(['ok' => false, 'error' => $lw['name'] . ' asked not to be contacted.']);
            exit;
        }
        $lintW = pm_outreach_lint('WhatsApp message', $textW, !$isReply && empty($lw['wa_sent']));
        if ($isReply) { // an answer to what they wrote: not a first message, not counted in the daily cap; the lint is advice only
            $lintW = array_values(array_filter($lintW, fn($x) => !str_starts_with($x, 'The message is')));
            pm_reply_sent($lw, $textW, 'wa');
            pm_lead_note($lw, 'WhatsApp reply sent');
            unset($lw);
            pm_leads_save($leadsW);
            echo json_encode(['ok' => true, 'warn' => implode(' ', $lintW)]);
            exit;
        }
        $capW = (int)pm_agents_config()['wa_cap'];
        if (pm_wa_sent_today($leadsW) >= $capW) {
            echo json_encode(['ok' => false, 'error' => 'Daily WhatsApp limit reached.']);
            exit;
        }
        if (empty($lw['wa_sent'])) { // a first message: same rules as the first email
            $lwCopy = $lw;
            if (function_exists('pm_company_contacted_recently') && pm_company_contacted_recently($leadsW, $lwCopy, 7)) {
                echo json_encode(['ok' => false, 'error' => 'Someone at this company was already contacted this week. Wait a week so one company is not messaged twice.']);
                exit;
            }
            if ($lintW) {
                echo json_encode(['ok' => false, 'error' => 'Not sent. ' . implode(' ', $lintW) . ' Edit the message and try again.']);
                exit;
            }
        }
        $lw['wa_sent'][] = date('Y-m-d H:i');
        $lw['last_contacted'] = $lw['last_out'] = date('Y-m-d H:i');
        if (in_array($lw['status'], ['new', 'qualified', 'drafted'], true)) {
            $lw['status'] = 'contacted';
        }
        if (empty($lw['owner']) && ($GLOBALS['PM_WHO'] ?? '') !== '') {
            $lw['owner'] = $GLOBALS['PM_WHO'];
        }
        if (count($lw['wa_sent']) > 1) {
            $lw['followups'] = (int)($lw['followups'] ?? 0) + 1;
            unset($lw['followup_draft']);
        }
        $lw['thread'][] = ['dir' => 'out', 'at' => date('Y-m-d H:i'), 'text' => mb_substr($textW, 0, 1500), 'ch' => 'wa'];
        pm_lead_note($lw, 'WhatsApp message sent');
        unset($lw);
        pm_leads_save($leadsW);
        echo json_encode(['ok' => true, 'left' => max(0, $capW - pm_wa_sent_today($leadsW))]);
        exit;
    }

    if ($action === 'social' || $action === 'social_x') {
        require __DIR__ . '/lib/social_actions.php';
        pm_social_action($vb, $action);
    }

    if ($action === 'social_ext') { // module forms: POST action=social_ext&do=<name> -> pm_do_<name>($vb) returns [msg, kind, to]
        @set_time_limit(300);
        pm_brand_set($vb);
        $xr = pm_social_ext_run((string)($_POST['do'] ?? ''), $vb);
        pm_redirect($xr['to'], $xr['msg'], $xr['kind']);
    }

    if ($action === 'social_accounts') { // links, cover wording, channels, hero photo, branding kit, Facebook picture updates
        $b = $vb;
        $cfg = pm_social_settings($b);
        $do = (string)($_POST['do'] ?? 'save');
        $back = fn($m, $k = 'ok') => pm_redirect('social&view=accounts', $m, $k);
        if ($do === 'apply_profile' || $do === 'apply_cover') {
            [$ok, $m] = pm_fb_apply($b, $do === 'apply_cover' ? 'cover' : 'profile');
            $back($m, $ok ? 'ok' : 'err');
        }
        foreach (array_keys(pm_platforms()) as $pk) {
            $u = trim((string)($_POST['url'][$pk] ?? ''));
            $cfg['urls'][$pk] = $u === '' ? '' : (preg_match('#^https?://#i', $u) ? $u : 'https://' . $u);
        }
        $cfg['cover_head'] = mb_substr(trim((string)($_POST['cover_head'] ?? $cfg['cover_head'])), 0, 80);
        $cfg['cover_line'] = mb_substr(trim((string)($_POST['cover_line'] ?? $cfg['cover_line'])), 0, 90);
        foreach (['facebook', 'instagram', 'linkedin'] as $chn) {
            $cfg['channels'][$chn] = !empty($_POST['channels'][$chn]);
        }
        if (!empty($_FILES['hero']['tmp_name']) && is_uploaded_file($_FILES['hero']['tmp_name'])) {
            $hi = @getimagesize($_FILES['hero']['tmp_name']);
            if (!$hi || !in_array($hi[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
                $back('The hero photo must be a JPG or PNG.', 'err');
            }
            foreach (glob(PM_ROOT . '/assets/' . $b . '_hero.*') as $old) {
                @unlink($old);
            }
            move_uploaded_file($_FILES['hero']['tmp_name'], PM_ROOT . '/assets/' . $b . '_hero.' . ($hi[2] === IMAGETYPE_PNG ? 'png' : 'jpg'));
        }
        if (!empty($_POST['remove_hero'])) {
            foreach (glob(PM_ROOT . '/assets/' . $b . '_hero.*') as $old) {
                @unlink($old);
            }
        }
        pm_social_settings_save($b, $cfg);
        if ($do === 'kit' || !empty($_FILES['hero']['tmp_name']) || !empty($_POST['remove_hero'])) {
            pm_kit_build($b);
            $back('Saved. The profile pictures and covers for every platform were made again.');
        }
        $back('Saved.');
    }

    if ($action === 'fbm') { // manage the Facebook Page: posts, comments, inbox, audit, connect
        @set_time_limit(180);
        pm_brand_set($vb);
        $do = (string)($_POST['do'] ?? '');
        $view = in_array($_POST['view'] ?? '', ['page', 'inbox', 'audit', 'accounts'], true) ? $_POST['view'] : 'page';
        $id = (string)($_POST['id'] ?? '');
        $to = 'social&view=' . $view . (($_POST['post'] ?? '') !== '' && preg_match('/^[0-9_]+$/', $_POST['post']) ? '&post=' . $_POST['post'] . '#p' . $_POST['post'] : ($id !== '' && $do !== 'delete' ? '&post=' . $id . '#p' . $id : ''));
        if ($do === 'connect') { // turn a token from Graph API Explorer into a permanent Page token
            $appId = preg_replace('/\D/', '', (string)($_POST['app_id'] ?? ''));
            $secret = trim((string)($_POST['app_secret'] ?? '')) ?: (string)(pm_env()['FB_APP_SECRET'] ?? '');
            $appId = $appId ?: (string)(pm_env()['FB_APP_ID'] ?? '');
            if ($appId === '' || $secret === '' || trim((string)($_POST['user_token'] ?? '')) === '') {
                pm_redirect('social&view=accounts', 'Fill in the App ID, App Secret and the token from Graph API Explorer.', 'err');
            }
            pm_env_set(['FB_APP_ID' => $appId, 'FB_APP_SECRET' => $secret]);
            [$ok, $pages, $longTok] = pm_fb_connect_pages($appId, $secret, trim((string)$_POST['user_token'])) + [2 => ''];
            if (!$ok) {
                pm_redirect('social&view=accounts', $pages, 'err');
            }
            if ($longTok !== '') {
                pm_env_set(['FB_USER_TOKEN' => $longTok]); // for ads (about 60 days)
            }
            $_SESSION['fb_pages'] = array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'token' => $p['access_token']], $pages);
            pm_redirect('social&view=accounts', count($pages) . ' Page(s) found. Choose which one belongs to ' . ($vb === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . '.');
        }
        if ($do === 'connect_pick') {
            $pick = null;
            foreach ((array)($_SESSION['fb_pages'] ?? []) as $p) {
                if ($p['id'] === ($_POST['page'] ?? '')) {
                    $pick = $p;
                }
            }
            if (!$pick) {
                pm_redirect('social&view=accounts', 'Choose a Page.', 'err');
            }
            $pre = $vb === 'travel' ? 'TM_' : '';
            pm_env_set([$pre . 'FB_PAGE_ID' => $pick['id'], $pre . 'FB_PAGE_TOKEN' => $pick['token']]);
            // Instagram account linked to the Page, if any
            [$okI, $dI] = pm_graph('GET', $pick['id'], ['fields' => 'instagram_business_account'], $pick['token']);
            if ($okI && !empty($dI['instagram_business_account']['id'])) {
                pm_env_set([$pre . 'IG_USER_ID' => $dI['instagram_business_account']['id']]);
            }
            unset($_SESSION['fb_pages']);
            pm_update('social_page', function (array $all) use ($vb) {
                unset($all[$vb]);
                return $all;
            }, fn() => []);
            $info = pm_fb_token_info($pick['token']);
            pm_redirect('social&view=accounts', 'Connected "' . $pick['name'] . '" with a permanent Page token' . ($okI && !empty($dI['instagram_business_account']['id']) ? ' and its linked Instagram account' : '') . '.'
                . ($info['missing'] ? ' Still missing: ' . implode(', ', $info['missing']) . ' (generate the token again with those ticked).' : ''), $info['missing'] ? 'err' : 'ok');
        }
        if ($do === 'audit') {
            try {
                pm_agent_page_audit($vb);
            } catch (Throwable $e) {
                pm_redirect('social&view=audit', 'Audit failed: ' . $e->getMessage(), 'err');
            }
            pm_redirect('social&view=audit', 'Audit done.');
        }
        if ($do === 'draft_reply') {
            try {
                $_SESSION['fbdraft'][$id] = pm_agent_social_reply(['post' => (string)($_POST['post_text'] ?? ''), 'from' => (string)($_POST['from'] ?? ''), 'message' => (string)($_POST['comment'] ?? '')]);
            } catch (Throwable $e) {
                pm_redirect($to, 'Could not draft: ' . $e->getMessage(), 'err');
            }
            pm_redirect($to, 'Reply drafted. Read it, then press Reply.');
        }
        if ($do === 'draft_message') {
            $tid = (string)($_POST['tid'] ?? '');
            try {
                $_SESSION['fbdraft'][$tid] = pm_agent_social_reply(['post' => 'A private Messenger conversation with our Page', 'from' => (string)($_POST['from'] ?? ''), 'message' => (string)($_POST['last'] ?? '')]);
            } catch (Throwable $e) {
                pm_redirect('social&view=inbox', 'Could not draft: ' . $e->getMessage(), 'err');
            }
            pm_redirect('social&view=inbox', 'Answer drafted. Read it, then press Send.');
        }
        if ($do === 'message') {
            $txt = trim((string)($_POST['text'] ?? ''));
            if ($txt === '') {
                pm_redirect('social&view=inbox', 'Write the answer first.', 'err');
            }
            [$ok, $m] = pm_fb_message($vb, (string)($_POST['psid'] ?? ''), $txt);
            unset($_SESSION['fbdraft'][(string)($_POST['tid'] ?? '')]);
            pm_agent_log('Social', ($ok ? 'Messenger answer sent to ' : 'Messenger answer failed for ') . (string)($_POST['from'] ?? ''));
            pm_redirect('social&view=inbox', $m, $ok ? 'ok' : 'err');
        }
        if (in_array($do, ['edit', 'delete', 'reply', 'like', 'unlike', 'hide', 'unhide', 'delete_comment'], true)) {
            $txt = trim((string)($_POST['text'] ?? ''));
            if (in_array($do, ['edit', 'reply'], true) && $txt === '') {
                pm_redirect($to, 'Write the text first.', 'err');
            }
            [$ok, $m] = pm_fb_action($vb, $do, $id, $txt);
            if ($ok && $do === 'reply') {
                unset($_SESSION['fbdraft'][$id]);
                $dr = pm_fb_drafts();
                unset($dr[$id]);
                pm_save('fb_drafts', $dr);
            }
            pm_agent_log('Social', 'Page ' . $do . ($ok ? '' : ' failed') . ': ' . mb_substr($txt ?: $id, 0, 50));
            pm_redirect($do === 'delete' ? 'social&view=page' : $to, $m, $ok ? 'ok' : 'err');
        }
        pm_redirect('social&view=' . $view);
    }

    if ($action === 'fbg') { // growth: playbook, autopilot, clean-up, ads
        @set_time_limit(240);
        pm_brand_set($vb);
        $do = (string)($_POST['do'] ?? '');
        $view = in_array($_POST['view'] ?? '', ['growth', 'cleanup', 'ads'], true) ? $_POST['view'] : 'growth';
        $to = 'social&view=' . $view;
        try {
            if ($do === 'autopilot') {
                $c = pm_social_settings($vb);
                $c['autopilot'] = in_array($_POST['level'] ?? '', ['off', 'safe', 'bold'], true) ? $_POST['level'] : 'safe';
                pm_social_settings_save($vb, $c);
                pm_redirect($to, 'Autopilot set to ' . $c['autopilot'] . '.');
            }
            if ($do === 'judge') {
                $k = pm_agent_junk_judge($vb);
                $n = array_sum(array_map(fn($g) => count($g['items']), pm_fb_suggestions($vb)));
                pm_redirect($to, $n ? "$n suggestion(s) ready." : 'Checked: nothing needs you on the Page.');
            }
            if ($do === 'group') {
                [$n, $f] = pm_fb_do_group($vb, (string)($_POST['act'] ?? ''));
                pm_redirect($to, "$n done" . ($f ? ", $f failed (see the list)" : '') . '.', $f ? 'err' : 'ok');
            }
            if ($do === 'item') {
                [$ok, $m] = pm_fb_do_item($vb, (string)($_POST['id'] ?? ''), (string)($_POST['how'] ?? ''), (string)($_POST['text'] ?? ''));
                pm_redirect($to, $m, $ok ? 'ok' : 'err');
            }
            if ($do === 'autopilot_now') {
                pm_redirect($to, pm_social_autopilot($vb, true) . '.');
            }
            if ($do === 'playbook') {
                $pb = pm_agent_engage_playbook($vb, true);
                pm_redirect($to, count($pb['tasks']) . ' engagement task(s) for today.');
            }
            if ($do === 'task_done') {
                $all = pm_load('engage_playbook', fn() => []);
                foreach ($all[$vb]['tasks'] ?? [] as $k => $t) {
                    if ($t['n'] === (int)($_POST['n'] ?? -1)) {
                        $all[$vb]['tasks'][$k]['done'] = !$t['done'];
                    }
                }
                pm_save('engage_playbook', $all);
                pm_redirect($to);
            }
            if ($do === 'capture') {
                $n = pm_fb_capture_buyers($vb);
                pm_redirect($to, $n ? "$n buyer(s) added to the leads (status Replied)." : 'No new buyers found.');
            }
            if ($do === 'cleanup') {
                $cl = pm_agent_page_cleanup($vb);
                pm_redirect($to, 'Review done: ' . count(array_filter($cl['items'], fn($i) => $i['action'] !== 'keep')) . ' change(s) proposed. Tick the ones you agree with.');
            }
            if ($do === 'cleanup_apply') {
                [$n, $fail] = pm_page_cleanup_apply($vb, array_map('strval', (array)($_POST['ids'] ?? [])), (array)($_POST['text'] ?? []));
                pm_redirect($to, "$n change(s) made on the Page." . ($fail ? ' Failed: ' . implode(' | ', array_slice($fail, 0, 2)) : ''), $fail ? 'err' : 'ok');
            }
            if ($do === 'profile_apply') {
                [$ok, $m] = pm_page_profile_apply($vb, (string)($_POST['about'] ?? ''), (string)($_POST['description'] ?? ''));
                pm_redirect($to, $m, $ok ? 'ok' : 'err');
            }
            if ($do === 'ads_account') {
                pm_env_set([($vb === 'travel' ? 'TM_' : '') . 'FB_AD_ACCOUNT_ID' => preg_replace('/\D/', '', (string)($_POST['acct'] ?? ''))]);
                pm_redirect($to, 'Ad account connected.');
            }
            if ($do === 'ads_plan') {
                $p = pm_agent_ads_plan($vb);
                pm_redirect($to, count($p['campaigns']) . ' campaign(s) planned.');
            }
            if ($do === 'ads_create') {
                [$ok, $m] = pm_ads_create($vb, (int)($_POST['i'] ?? -1));
                pm_redirect($to, $m, $ok ? 'ok' : 'err');
            }
        } catch (Throwable $e) {
            pm_redirect($to, $e->getMessage(), 'err');
        }
        pm_redirect($to);
    }

    if ($action === 'social_check') {
        $b = ($_POST['brand'] ?? '') === 'travel' ? 'travel' : 'promanaged';
        $pg = pm_social_page($b, true);
        pm_redirect('settings&brand=' . $b, !empty($pg['ok']) ? 'Connected to the Facebook Page "' . $pg['name'] . '" (' . number_format($pg['followers']) . ' followers).' : 'Facebook: ' . ($pg['error'] ?? 'not connected'), !empty($pg['ok']) ? 'ok' : 'err');
    }

    if ($action === 'who') {
        $w = (string)($_POST['who'] ?? '');
        $_SESSION['who'] = in_array($w, (array)($settings['team'] ?? []), true) ? $w : '';
        pm_redirect(in_array($_POST['back'] ?? '', ['agents', 'whatsapp', 'proposal', 'history', 'template', 'settings'], true) ? $_POST['back'] : 'agents');
    }

    if ($action === 'link_refresh') {
        $b = ($_POST['brand'] ?? '') === 'travel' ? 'travel' : 'promanaged';
        $m = pm_link_refresh($b);
        pm_redirect('settings&brand=' . $b, $m['url'] === '' ? 'Add a link first.' : ($m['ok'] ? 'Preview updated from ' . $m['url'] . '.' : 'Could not read ' . $m['url'] . '. Check the address.'), $m['ok'] ? 'ok' : 'err');
    }

    if ($action === 'smtp_check') { // log in to the mail server without sending anything
        $b = ($_POST['brand'] ?? '') === 'travel' ? 'travel' : 'promanaged';
        pm_brand_set($b);
        $ss = pm_settings();
        $sm = $ss['smtp'];
        if (trim($sm['host']) === '' || trim($sm['username']) === '') {
            pm_redirect('settings&brand=' . $b, ($b === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . ': no mail server is set up yet.', 'err');
        }
        try {
            $mm = new PHPMailer\PHPMailer\PHPMailer(true);
            $mm->isSMTP();
            $mm->Host = $sm['host'];
            $mm->Port = (int)$sm['port'];
            $mm->SMTPAuth = true;
            $mm->Username = $sm['username'];
            $mm->Password = $sm['password'];
            $mm->SMTPSecure = $sm['encryption'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : ($sm['encryption'] === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
            $mm->Timeout = 20;
            $ok = $mm->smtpConnect();
            $mm->smtpClose();
        } catch (Throwable $e) {
            pm_redirect('settings&brand=' . $b, ($b === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . ' mail: could not log in. ' . $e->getMessage(), 'err');
        }
        pm_redirect('settings&brand=' . $b, ($b === 'travel' ? 'Travel Malawi' : 'ProManaged IT') . ' mail: logged in to ' . $sm['host'] . ' as ' . $sm['username'] . '. Nothing was sent.');
    }

    if ($action === 'lines') {
        $ln = (string)($_POST['line'] ?? '');
        if (!isset(pm_line_names()[$ln])) {
            pm_redirect('template', 'Unknown business line.', 'err');
        }
        $in = $_POST['ln'] ?? [];
        $cur = $tpl['lines'][$ln];
        foreach (array_keys(PM_LINE_TEXT) as $k) {
            $cur[$k] = trim((string)($in[$k] ?? ''));
        }
        foreach (PM_LINE_LISTS as $k => [, $cols]) {
            $cur[$k] = pm_text_to_rows((string)($in[$k] ?? ''), $cols);
        }
        foreach (['pitch', 'contract', 'both'] as $et) {
            $cur['emails'][$et] = ['subject' => trim((string)($in['emails'][$et]['subject'] ?? '')), 'body' => str_replace("\r\n", "\n", (string)($in['emails'][$et]['body'] ?? ''))];
        }
        $cur['show_first_year'] = !empty($in['show_first_year']);
        $tpl['lines'][$ln] = $cur;
        pm_save('template', $tpl);
        pm_redirect('template', pm_line_names()[$ln] . ' wording saved. Proposals for those packages use it straight away.');
    }

    if ($action === 'reset_line') {
        $ln = (string)($_POST['line'] ?? '');
        if (isset(pm_line_names()[$ln])) {
            $tpl['lines'][$ln] = pm_default_lines()[$ln];
            pm_save('template', $tpl);
        }
        pm_redirect('template', 'Wording put back to the original.');
    }

    if ($action === 'settings') {
        $in = $_POST['s'] ?? [];
        $new = $settings;
        foreach (['company_name', 'tagline', 'address', 'phone', 'email', 'website', 'signatory_name', 'signatory_title', 'currency', 'ref_prefix'] as $k) {
            $new[$k] = trim((string)($in[$k] ?? ''));
        }
        $new['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['accent_color'] ?? '')) ? $in['accent_color'] : $settings['accent_color'];
        $new['next_ref'] = max(1, (int)($in['next_ref'] ?? 1));
        $new['link_url'] = pm_clean_url((string)($in['link_url'] ?? ''));
        $new['link_on'] = !empty($in['link_on']);
        $new['social_auto'] = pm_social_can_approve() ? !empty($in['social_auto']) : !empty($settings['social_auto']); // editors cannot switch Auto-publish
        $new['team'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string)($in['team'] ?? ''))))));
        $tin = (array)($in['travel'] ?? []);
        $tv = (array)($new['travel'] ?? pm_default_settings()['travel']);
        foreach (['company_name', 'tagline', 'address', 'phone', 'email', 'website', 'signatory_name', 'signatory_title'] as $k) {
            $tv[$k] = trim((string)($tin[$k] ?? ''));
        }
        $tv['from_email'] = '';
        $tv['ref_prefix'] = trim((string)($tin['ref_prefix'] ?? 'TM')) ?: 'TM';
        $tv['next_ref'] = max(1, (int)($tin['next_ref'] ?? ($tv['next_ref'] ?? 1)));
        $tv['online_signing'] = !empty($tin['online_signing']);
        $tv['esign_tags'] = !empty($tin['esign_tags']);
        $tsm = (array)($tin['smtp'] ?? []);
        $tv['smtp'] = (array)($tv['smtp'] ?? pm_default_settings()['travel']['smtp']);
        $tv['smtp']['bcc_self'] = !empty($tsm['bcc_self']);
        $tv['smtp']['from_name'] = trim((string)($tsm['from_name'] ?? '')) ?: ($tv['company_name'] ?: 'Travel Malawi');
        if (!pm_tm_smtp_from_env()) {
            foreach (['host', 'username', 'from_email'] as $k) {
                $tv['smtp'][$k] = trim((string)($tsm[$k] ?? ''));
            }
            $tv['smtp']['port'] = (int)($tsm['port'] ?? 465);
            $tv['smtp']['encryption'] = in_array($tsm['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $tsm['encryption'] : 'ssl';
            if (($tsm['password'] ?? '') !== '') {
                $tv['smtp']['password'] = (string)$tsm['password'];
            }
        }
        if (!empty($_POST['remove_travel_signature']) && is_file(PM_ROOT . '/assets/travel_signature.png')) {
            unlink(PM_ROOT . '/assets/travel_signature.png');
        }
        if (!empty($_FILES['travel_signature']['tmp_name']) && is_uploaded_file($_FILES['travel_signature']['tmp_name'])) {
            $tsi = @getimagesize($_FILES['travel_signature']['tmp_name']);
            if ($tsi && in_array($tsi[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $timg = $tsi[2] === IMAGETYPE_PNG ? imagecreatefrompng($_FILES['travel_signature']['tmp_name']) : imagecreatefromjpeg($_FILES['travel_signature']['tmp_name']);
                imagesavealpha($timg, true);
                imagepng($timg, PM_ROOT . '/assets/travel_signature.png');
            }
        }
        $tv['company_name'] = $tv['company_name'] ?: 'Travel Malawi';
        $tv['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($tin['accent_color'] ?? '')) ? $tin['accent_color'] : ($tv['accent_color'] ?? '#047857');
        $tv['charging'] = !empty($tin['charging']);
        $tv['link_url'] = pm_clean_url((string)($tin['link_url'] ?? ''));
        $tv['link_on'] = !empty($tin['link_on']);
        $tv['social_auto'] = pm_social_can_approve() ? !empty($tin['social_auto']) : !empty($settings['travel']['social_auto']);
        foreach (['about', 'facts', 'audience', 'voice', 'never'] as $bk) {
            if (isset($in['brain'][$bk])) {
                $new['brain'][$bk] = trim((string)$in['brain'][$bk]);
            }
            if (isset($tin['brain'][$bk])) {
                $tv['brain'][$bk] = trim((string)$tin['brain'][$bk]);
            }
        }
        $new['travel'] = $tv;
        if (!empty($_FILES['travel_logo']['tmp_name']) && is_uploaded_file($_FILES['travel_logo']['tmp_name'])) {
            $li = @getimagesize($_FILES['travel_logo']['tmp_name']);
            if ($li && in_array($li[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $im = $li[2] === IMAGETYPE_PNG ? imagecreatefrompng($_FILES['travel_logo']['tmp_name']) : imagecreatefromjpeg($_FILES['travel_logo']['tmp_name']);
                imagesavealpha($im, true);
                imagepng($im, PM_ROOT . '/assets/travel_logo.png');
            }
        }
        $curs = [];
        foreach ((array)($in['currencies'] ?? []) as $c) {
            $code = strtoupper(trim((string)($c['code'] ?? '')));
            $mpu = (float)($c['mpu'] ?? 0); // base-currency units per 1 of this currency
            if (preg_match('/^[A-Z]{3}$/', $code) && $mpu > 0) {
                $curs[] = ['code' => $code, 'name' => trim((string)($c['name'] ?? $code)) ?: $code, 'rate' => 1 / $mpu, 'round' => max(0.01, (float)($c['round'] ?? 1))];
            }
        }
        if (!array_filter($curs, fn($c) => $c['code'] === strtoupper($new['currency']))) {
            array_unshift($curs, ['code' => strtoupper($new['currency']), 'name' => strtoupper($new['currency']), 'rate' => 1, 'round' => 1]);
        }
        $new['currencies'] = $curs;
        $new['default_quote_currency'] = strtoupper(trim((string)($in['default_quote_currency'] ?? $new['currency'])));
        $new['rates_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['rates_date'] ?? '')) ? $in['rates_date'] : '';
        $new['esign_tags'] = !empty($in['esign_tags']);
        $new['online_signing'] = !empty($in['online_signing']);
        if (!empty($_POST['remove_signature']) && is_file(PM_ROOT . '/assets/signature.png')) {
            unlink(PM_ROOT . '/assets/signature.png');
        }
        if (!empty($_FILES['signature']['tmp_name']) && is_uploaded_file($_FILES['signature']['tmp_name'])) {
            $si = @getimagesize($_FILES['signature']['tmp_name']);
            if ($si && in_array($si[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $simg = $si[2] === IMAGETYPE_PNG ? imagecreatefrompng($_FILES['signature']['tmp_name']) : imagecreatefromjpeg($_FILES['signature']['tmp_name']);
                imagesavealpha($simg, true);
                imagepng($simg, PM_ROOT . '/assets/signature.png');
            }
        }
        $sm = $in['smtp'] ?? [];
        $new['smtp']['bcc_self'] = !empty($sm['bcc_self']);
        if (!pm_smtp_from_env()) { // with .env, the mail server fields are read-only
            foreach (['host', 'username', 'from_email', 'from_name'] as $k) {
                $new['smtp'][$k] = trim((string)($sm[$k] ?? ''));
            }
            $new['smtp']['port'] = (int)($sm['port'] ?? 587);
            $new['smtp']['encryption'] = in_array($sm['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $sm['encryption'] : 'tls';
            if (($sm['password'] ?? '') !== '') {
                $new['smtp']['password'] = (string)$sm['password'];
            }
        }
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $info = @getimagesize($_FILES['logo']['tmp_name']);
            if ($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $img = $info[2] === IMAGETYPE_PNG ? imagecreatefrompng($_FILES['logo']['tmp_name']) : imagecreatefromjpeg($_FILES['logo']['tmp_name']);
                imagesavealpha($img, true);
                imagepng($img, PM_ROOT . '/assets/logo.png');
            } else {
                pm_save('settings', $new);
                pm_redirect('settings', 'Settings saved, but the logo must be a PNG or JPG image.', 'err');
            }
        }
        pm_save('settings', $new);
        if (!empty($_POST['test_email']) && ($_POST['test_brand'] ?? '') === 'travel') { // test the Travel Malawi mailbox
            pm_brand_set('travel');
            $ts = pm_settings();
            $to = $ts['smtp']['from_email'] ?: $ts['smtp']['username'];
            [$ok, $err] = pm_mail($ts, ['to' => $to, 'subject' => 'Test email from ' . $ts['company_name'], 'body' => 'This is a test from the ' . $ts['company_name'] . ' mailbox. If you can read this, email is working.', 'link' => pm_link_card()]);
            pm_redirect('settings&brand=travel', $ok ? 'Settings saved and a test email was sent to ' . $to . '.' : 'Settings saved, but the test email failed: ' . $err, $ok ? 'ok' : 'err');
        }
        if (!empty($_POST['test_email'])) {
            $file = pm_build_pdf($new, $tpl, ['business' => 'Test Business', 'contact' => 'Test', 'contact_title' => '', 'email' => '', 'phone' => '', 'address' => '', 'tpin' => '', 'package' => 0, 'extras' => [], 'discount_setup' => 0, 'discount_monthly' => 0, 'doc_type' => 'pitch', 'date' => date('Y-m-d'), 'start_date' => '', 'personal_note' => ''], 'TEST');
            [$ok, $err] = pm_send_mail($new, $new['smtp']['from_email'] ?: $new['smtp']['username'], '', 'Test email from ProManaged Proposals', "This is a test. If you can read this, email sending works.", $file);
            @unlink($file);
            pm_redirect('settings', $ok ? 'Settings saved and a test email was sent to ' . ($new['smtp']['from_email'] ?: $new['smtp']['username']) . '.' : 'Settings saved, but the test email failed: ' . $err, $ok ? 'ok' : 'err');
        }
        pm_redirect('settings' . ($vb === 'travel' ? '&brand=travel' : ''), 'Settings saved.');
    }

    if ($action === 'reset_template') {
        pm_save('template', pm_default_template());
        pm_redirect('template', 'Template reset to the original wording and prices.');
    }
}

$d = $_SESSION['draft'] ?? [];
if ($d && !isset($d['currency'])) {
    // Saved before quotes had a currency: those prices were in the base currency, so let the
    // form fill them again in the quote currency instead of showing USD figures as Kwacha.
    unset($d['price_setup'], $d['price_monthly'], $d['extra_price'], $d['rate']);
}
if (str_contains((string)($d['body'] ?? ''), 'Thank you for your time. Please find attached our proposal')) {
    unset($d['body'], $d['subject']); // wording from the first version of the app
}
$v = fn($k, $def = '') => pm_h((string)($d[$k] ?? $def));
$history = pm_history();
$cur = $settings['currency'];

/** Editable rows for a list in the template. $cols = [key => [label, type, width]] */
function pm_rows_editor(string $name, array $cols, array $rows, string $addLabel = 'Add row'): string
{
    $head = '<tr>';
    foreach ($cols as [$label, , $w]) {
        $head .= '<th style="width:' . $w . '">' . pm_h($label) . '</th>';
    }
    $head .= '<th style="width:36px"></th></tr>';
    $cell = function ($key, $col, $val, $i) use ($name) {
        [$label, $type] = $col;
        $n = "tpl[$name][$i][$key]";
        if (is_array($type)) {
            $o = '';
            foreach ($type as $optVal => $optLabel) {
                $o .= '<option value="' . pm_h((string)$optVal) . '"' . ((string)$val === (string)$optVal ? ' selected' : '') . '>' . pm_h($optLabel) . '</option>';
            }
            return '<td><select name="' . $n . '">' . $o . '</select></td>';
        }
        if ($type === 'textarea') {
            return '<td><textarea name="' . $n . '" rows="2">' . pm_h((string)$val) . '</textarea></td>';
        }
        return '<td><input type="' . ($type === 'number' ? 'number" step="any' : 'text') . '" name="' . $n . '" value="' . pm_h((string)$val) . '"></td>';
    };
    $body = '';
    foreach (array_values($rows) as $i => $r) {
        $body .= '<tr>';
        foreach ($cols as $key => $col) {
            $body .= $cell($key, $col, $r[$key] ?? '', $i);
        }
        $body .= '<td><button type="button" class="icon" onclick="this.closest(\'tr\').remove()" title="Remove">&times;</button></td></tr>';
    }
    $blank = '<tr>';
    foreach ($cols as $key => $col) {
        $blank .= $cell($key, $col, '', '__i__');
    }
    $blank .= '<td><button type="button" class="icon" onclick="this.closest(\'tr\').remove()" title="Remove">&times;</button></td></tr>';
    return '<table class="grid" data-next="' . count($rows) . '"><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table>'
        . '<template>' . $blank . '</template><button type="button" class="link" onclick="addRow(this)">+ ' . pm_h($addLabel) . '</button>';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Proposals · <?= pm_h($settings['company_name']) ?></title>
<style>
:root { --ink:#16181d; --muted:#6b7078; --line:#e3e5e8; --bg:#f6f7f8; --accent:<?= pm_h($settings['accent_color']) ?>; }
* { box-sizing:border-box; }
body { margin:0; font:14px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:var(--ink); background:var(--bg); }
header { background:#fff; border-bottom:1px solid var(--line); }
.bar { max-width:1180px; margin:0 auto; padding:14px 24px; display:flex; align-items:center; gap:18px; }
.bar img { height:34px; }
.bar .name { font-weight:600; letter-spacing:.02em; }
nav { margin-left:auto; display:flex; gap:4px; }
nav a { padding:8px 14px; border-radius:6px; color:var(--muted); text-decoration:none; }
nav a.on { color:var(--ink); background:var(--bg); font-weight:600; }
main { max-width:1180px; margin:0 auto; padding:24px; }
h1 { font:400 26px/1.2 Georgia, "Times New Roman", serif; margin:0 0 4px; }
.sub { color:var(--muted); margin:0 0 22px; }
.card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:20px 22px; margin-bottom:18px; }
.card h2 { font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin:0 0 14px; font-weight:600; }
.row { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
label { display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
input[type=text], input[type=email], input[type=number], input[type=date], input[type=password], select, textarea {
  width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:6px; font:inherit; color:var(--ink); background:#fff; }
input:focus, select:focus, textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 15%, transparent); }
textarea { resize:vertical; }
.layout { display:grid; grid-template-columns:1fr 340px; gap:18px; align-items:start; }
.sticky { position:sticky; top:16px; }
.pk { display:grid; gap:8px; }
.pk label.opt { display:flex; justify-content:space-between; gap:10px; padding:10px 12px; border:1px solid var(--line); border-radius:8px; cursor:pointer; color:var(--ink); font-size:14px; margin:0; }
.pk label.opt:has(input:checked) { border-color:var(--accent); background:color-mix(in srgb, var(--accent) 5%, #fff); }
.pk small { color:var(--muted); display:block; font-size:12px; }
.pk .price { text-align:right; white-space:nowrap; font-size:13px; }
.ex { display:grid; grid-template-columns:1fr 90px 70px; gap:8px; align-items:center; padding:7px 0; border-bottom:1px solid var(--line); }
.ex:last-child { border-bottom:0; }
.ex { grid-template-columns:1fr 130px 70px; }
.ex .fee { color:var(--muted); font-size:11px; text-align:right; }
.ex .fee input { text-align:right; }
.ex .fee small { display:block; margin-top:2px; }
.pricebox { margin-top:16px; padding-top:16px; border-top:1px solid var(--line); }
.pricebar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap; }
label.check { display:flex; gap:8px; align-items:center; color:var(--ink); font-size:13px; margin:0; }
.seg { display:flex; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
.seg label { flex:1; margin:0; text-align:center; padding:9px; cursor:pointer; color:var(--ink); font-size:13px; border-right:1px solid var(--line); }
.seg label:last-child { border-right:0; }
.seg input { display:none; }
.seg label:has(input:checked) { background:var(--ink); color:#fff; }
.total { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid var(--line); }
.total.big { font-size:18px; border-bottom:0; padding-top:12px; }
.total span:last-child { font-variant-numeric:tabular-nums; }
.btn { display:inline-block; width:100%; padding:11px 14px; border-radius:7px; border:1px solid var(--line); background:#fff; font:inherit; font-weight:600; cursor:pointer; color:var(--ink); text-align:center; text-decoration:none; }
.btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn + .btn { margin-top:8px; }
.btn.small { width:auto; padding:6px 12px; font-size:13px; }
.flash { padding:12px 14px; border-radius:8px; margin-bottom:18px; border:1px solid; }
.flash.ok { background:#f2f7f3; border-color:#cfe3d4; }
.flash.err { background:#fbf3f2; border-color:#ecd0cc; }
table.grid { width:100%; border-collapse:collapse; }
table.grid th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); font-weight:600; padding:6px; border-bottom:1px solid var(--line); }
table.grid td { padding:5px 6px; vertical-align:top; border-bottom:1px solid var(--line); }
table.grid td input, table.grid td select, table.grid td textarea { padding:7px 8px; }
button.icon { border:0; background:none; font-size:20px; color:var(--muted); cursor:pointer; line-height:1; padding:6px; }
button.link { border:0; background:none; color:var(--accent); cursor:pointer; padding:10px 0 0; font:inherit; font-weight:600; }
.hist td { padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:middle; }
.muted { color:var(--muted); }
details summary { cursor:pointer; color:var(--muted); font-size:13px; margin-top:6px; }
.hint { font-size:12px; color:var(--muted); margin-top:6px; }
@media (max-width:900px) { .layout { grid-template-columns:1fr; } .sticky { position:static; } }
</style>
<link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(PM_ROOT . '/assets/app.css') ?>">
<?php if ($vb === 'travel'): ?><link rel="icon" href="assets/travel_favicon.png"><style>:root{--accent:<?= pm_h($settings['travel']['accent_color'] ?? '#047857') ?>}</style><?php endif; ?>
</head>
<body>
<?php
$hdrLogo = $vb === 'travel' ? 'assets/travel_logo.png' : 'assets/logo.png';
$hdrName = $vb === 'travel' ? $settings['travel']['company_name'] : $settings['company_name'];
$team = array_values(array_filter((array)($settings['team'] ?? [])));
?>
<header><div class="bar">
  <img class="<?= $vb === 'travel' ? 'sq' : '' ?>" src="<?= $hdrLogo ?>?v=<?= @filemtime(PM_ROOT . '/' . $hdrLogo) ?>" alt="">
  <span class="name"><?= pm_h($hdrName) ?></span>
  <span class="brandsw"><a href="?tab=<?= pm_h($tab) ?>&brand=promanaged" class="<?= $vb === 'promanaged' ? 'on' : '' ?>">ProManaged IT</a><a href="?tab=<?= pm_h($tab) ?>&brand=travel" class="<?= $vb === 'travel' ? 'on' : '' ?>">Travel Malawi</a></span>
  <?php $hbAge = pm_social_heartbeat_age(); $hbMin = $hbAge === null ? 0 : intdiv($hbAge, 60);
  $hbTxt = $hbAge === null ? 'Scheduler has not run yet' : 'Scheduler last ran ' . ($hbMin >= 120 ? intdiv($hbMin, 60) . ' h' : $hbMin . ' min') . ' ago'; ?>
  <a class="sx-chip <?= $hbAge === null || $hbAge >= 2700 ? ($hbAge !== null && $hbAge >= 7200 ? 'bad' : 'warn') : 'ok' ?>" href="?tab=social&view=accounts" title="Posts go out when the scheduler runs (every 15 to 30 minutes), or when the app is open. See README: SOCIAL."><?= pm_h($hbTxt) ?></a>
  <?php if ($team): ?><form method="post" class="who" data-quiet><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="who"><input type="hidden" name="back" value="<?= pm_h($tab) ?>">
    <select name="who" onchange="this.form.submit()" aria-label="Who is working"><option value="">Working as…</option><?php foreach ($team as $m): ?><option <?= $GLOBALS['PM_WHO'] === $m ? 'selected' : '' ?>><?= pm_h($m) ?></option><?php endforeach; ?></select></form><?php endif; ?>
</div></header>
<div class="tabsbar"><nav aria-label="Main">
  <?php foreach (['agents' => 'Agents', 'whatsapp' => 'WhatsApp', 'social' => 'Social', 'proposal' => 'New proposal', 'history' => 'History', 'template' => 'Template', 'settings' => 'Settings'] as $k => $l): ?>
    <a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= $l ?></a>
  <?php endforeach; ?>
</nav></div>
<main>
<?php if ($flash): ?><div class="flash <?= pm_h($flash[0]) ?>"><?= pm_h($flash[1]) ?></div><?php endif; ?>

<?php if ($tab === 'proposal'): ?>
  <?php if (pm_app_url() === ''): ?><div class="flash err"><b>Clients cannot open links or be tracked until the app is hosted.</b> APP_URL is empty in .env, so proposal links, the "opened" alert and online signing do not work yet. PDFs and emails still go out.</div><?php endif; ?>
  <details class="card" style="padding:14px 20px"><summary style="margin:0;font-weight:600;color:var(--ink)">Find a business by name and prepare its proposal</summary>
    <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="agents"><input type="hidden" name="do" value="lookup"><input type="hidden" name="proposal" value="1">
      <div class="row">
        <div><label>For</label><select name="brand"><option value="promanaged">ProManaged IT</option><option value="travel">Travel Malawi (stays)</option></select></div>
        <div><label>Business name *</label><input type="text" name="name" required></div>
        <div><label>City (helps)</label><input type="text" name="city"></div>
      </div>
      <div class="btns" style="display:flex;gap:8px;margin-top:10px"><button class="btn small primary" style="width:auto">Find it and prepare the proposal</button></div>
      <p class="hint">Searches the web for that business, works out what it needs, and fills in the form below with a tailored proposal. About two AI calls.</p>
    </form></details>

  <h1>New proposal</h1>
  <p class="sub">Fill in the client, choose their package, then preview, download or email the PDF. Next reference: <b><?= pm_h(pm_next_ref($settings, false)) ?></b></p>
  <form method="post" id="pf">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="proposal">
    <div class="layout">
      <div>
        <div class="card"><h2>Client</h2>
          <div class="row">
            <div><label>Business name *</label><input type="text" name="business" value="<?= $v('business') ?>" required></div>
            <div><label>Location / address</label><input type="text" name="address" value="<?= $v('address') ?>" placeholder="e.g. Mangochi, Malawi"></div>
          </div><br>
          <div class="row">
            <div><label>Contact person</label><input type="text" name="contact" value="<?= $v('contact') ?>"></div>
            <div><label>Their title</label><input type="text" name="contact_title" value="<?= $v('contact_title') ?>" placeholder="e.g. General Manager"></div>
          </div><br>
          <div class="row">
            <div><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
            <div><label>Phone</label><input type="text" name="phone" value="<?= $v('phone') ?>"></div>
            <div><label>TPIN (optional)</label><input type="text" name="tpin" value="<?= $v('tpin') ?>"></div>
          </div>
        </div>

        <?php $qcCode = (string)($d['currency'] ?? ($settings['default_quote_currency'] ?? $settings['currency'])); $qc = pm_currency($settings, $qcCode); ?>
        <div class="card"><h2>Currency</h2>
          <div class="row">
            <div><label>Quote in</label><select name="currency" id="qcur">
              <?php foreach ($settings['currencies'] as $c): ?><option value="<?= pm_h($c['code']) ?>" <?= $c['code'] === $qc['code'] ? 'selected' : '' ?>><?= pm_h($c['code'] . ' · ' . $c['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div id="qratebox" <?= $qc['code'] === $settings['currency'] ? 'hidden' : '' ?>><label>Rate: 1 <span id="qcode"><?= pm_h($qc['code']) ?></span> = <?= pm_h($settings['currency']) ?></label>
              <input type="number" min="0" step="any" id="qmpu" value="<?= pm_h((string)round(1 / max(0.0000001, (float)($d['rate'] ?? $qc['rate'])), 2)) ?>">
              <input type="hidden" name="rate" id="qrate" value="<?= pm_h((string)($d['rate'] ?? $qc['rate'])) ?>"></div>
          </div>
          <p class="hint">Prices are in Kwacha. Pick another currency only when a client needs it.</p>
        </div>

        <div class="card"><h2>Package</h2>
          <div class="pk">
            <?php
            $pkSel = (int)($d['package'] ?? -1);
            if ($pkSel < 0 || pm_brand_of_type((string)($tpl['packages'][$pkSel]['type'] ?? '')) !== $vb) { // default to the first package of the brand on screen
                foreach ($tpl['packages'] as $pi => $pp) { if (pm_brand_of_type((string)($pp['type'] ?? '')) === $vb) { $pkSel = $pi; break; } }
            }
            foreach ($tpl['packages'] as $i => $pk): if (pm_brand_of_type((string)($pk['type'] ?? '')) !== $vb) continue; ?>
              <label class="opt"><span><input type="radio" name="package" value="<?= $i ?>" <?= $pkSel === $i ? 'checked' : '' ?> hidden>
                <b><?= pm_h($pk['name']) ?></b><small><?= pm_h($pk['suited']) ?></small></span>
                <span class="price" data-pk="<?= $i ?>"><?php if (($pk['type'] ?? '') === 'onboarding'): ?>Free<br><span class="muted">while onboarding is free</span><?php else: ?><?= pm_h(pm_money((float)$pk['setup'], $cur)) ?> setup<br><span class="muted"><?= pm_h(pm_money((float)$pk['monthly'], $cur)) ?> / month</span><?php endif; ?></span></label>
            <?php endforeach; ?>
          </div>
          <div class="pricebox">
            <div class="row">
              <div><label>Setup fee for this client (<span class="curcode"><?= pm_h($qc['code']) ?></span>)</label><input type="number" min="0" step="any" name="price_setup" id="priceSetup" value="<?= $v('price_setup') ?>"></div>
              <div><label>Monthly fee for this client (<span class="curcode"><?= pm_h($qc['code']) ?></span>)</label><input type="number" min="0" step="any" name="price_monthly" id="priceMonthly" value="<?= $v('price_monthly') ?>"></div>
            </div>
            <div class="pricebar">
              <label class="check"><input type="checkbox" name="save_prices" value="1"> Also make these my standard prices</label>
              <button class="btn small" name="do" value="save_prices" formnovalidate>Save as standard prices</button>
            </div>
            <div style="margin-top:10px"><label>Show prices in the quotation as</label>
              <select name="price_mode"><option value="exact" <?= ($d['price_mode'] ?? 'exact') === 'exact' ? 'selected' : '' ?>>Exact amounts</option><option value="from" <?= ($d['price_mode'] ?? '') === 'from' ? 'selected' : '' ?>>"From" prices (final price confirmed before they sign)</option></select></div>
            <p class="hint">Prices fill in from the package you pick. Change them to quote this client differently; the PDF shows the price you enter here.</p>
          </div>
        </div>

        <div class="card"><h2>Travel Malawi onboarding</h2>
          <label class="check"><input type="checkbox" name="charge" value="1" <?= (array_key_exists('charge', $d) ? !empty($d['charge']) : !empty($settings['travel']['charging'])) ? 'checked' : '' ?>> Charge for onboarding</label>
          <p class="hint">Only applies to the Stay Onboarding package. Leave it unticked while onboarding is free: the proposal then says Free and states there is no listing fee or commission. Tick it when you start charging, and set the price on the package.</p>
        </div>

        <div class="card"><h2>Extras</h2>
          <?php foreach ($tpl['extras'] as $i => $ex): if ($ex['period'] === 'note') continue; ?>
            <div class="ex"><span><?= pm_h($ex['name']) ?></span>
              <span class="fee"><input type="number" min="0" step="any" name="extra_price[<?= $i ?>]" value="<?= pm_h((string)($d['extra_price'][$i] ?? '')) ?>" title="Price"><small><?= $ex['period'] === 'month' ? 'per month' : 'once' ?></small></span>
              <input type="number" min="0" step="1" name="extras[<?= $i ?>]" value="<?= (int)($d['extras'][$i] ?? 0) ?>" title="Quantity"></div>
          <?php endforeach; ?>
          <div class="row" style="margin-top:14px">
            <div><label>Discount on setup (%)</label><input type="number" min="0" max="100" step="any" name="discount_setup" value="<?= $v('discount_setup', '0') ?>"></div>
            <div><label>Discount on monthly (%)</label><input type="number" min="0" max="100" step="any" name="discount_monthly" value="<?= $v('discount_monthly', '0') ?>"></div>
          </div>
        </div>

        <div class="card"><h2>Document</h2>
          <div class="seg">
            <?php foreach (['pitch' => 'Pitch only', 'contract' => 'Contract only', 'both' => 'Pitch + contract'] as $k => $l): ?>
              <label><input type="radio" name="doc_type" value="<?= $k ?>" <?= ($d['doc_type'] ?? 'both') === $k ? 'checked' : '' ?>><?= $l ?></label>
            <?php endforeach; ?>
          </div><br>
          <div class="row">
            <div><label>Proposal date</label><input type="date" name="date" value="<?= $v('date', date('Y-m-d')) ?>"></div>
            <div><label>Proposed start date</label><input type="date" name="start_date" value="<?= $v('start_date') ?>"></div>
          </div><br>
          <label>Personal note at the top of the pitch (optional)</label>
          <textarea name="personal_note" rows="2" placeholder="e.g. Following our meeting on Tuesday, here is how the system would run your lodge and bar."><?= $v('personal_note') ?></textarea>
        </div>

        <?php
        $selPk = $tpl['packages'][$pkSel] ?? [];
        $selLine = pm_apply_line($tpl, (string)($selPk['type'] ?? ''));
        $ea = $d['ai'] ?? null;
        $eaPains = $ea ? $ea['pain_points'] : array_map(fn($x) => ['pain' => $x['pain'], 'cost' => $x['cost'], 'fix' => $x['fix'], 'fact' => ''], array_slice($selLine['pain_points'] ?? [], 0, 3));
        ?>
        <details class="card" <?= $ea ? 'open' : '' ?>><summary style="margin:0;font-weight:600;color:var(--ink)">Edit the proposal wording before it goes out (optional)</summary>
          <p class="hint">Change anything the client will read. Tick the box to use your version; leave it unticked to use the standard wording. Use "Preview PDF" to check.</p>
          <label class="check"><input type="checkbox" name="edit_use" value="1" <?= $ea ? 'checked' : '' ?>> Use my edited wording below</label>
          <label style="margin-top:8px">Cover line</label><input type="text" name="edit[cover_hook]" value="<?= pm_h($ea['cover_hook'] ?? $selLine['cover_hook'] ?? '') ?>">
          <label style="margin-top:8px">Introduction</label><textarea name="edit[intro]" rows="2"><?= pm_h($ea['intro'] ?? $selLine['intro'] ?? '') ?></textarea>
          <label style="margin-top:8px">What we understand about them (one per line)</label><textarea name="edit[what_we_know]" rows="3"><?= pm_h(implode("\n", $ea['what_we_know'] ?? [])) ?></textarea>
          <label style="margin-top:8px">Their problems (one per line: problem | what it costs them | how we fix it | what we know that shows it)</label>
          <textarea name="edit[pain_points]" rows="5"><?= pm_h(pm_rows_to_text($eaPains, ['pain', 'cost', 'fix', 'fact'])) ?></textarea>
          <label style="margin-top:8px">What they gain (one per line)</label><textarea name="edit[gains]" rows="3"><?= pm_h(implode("\n", $ea['gains'] ?? [])) ?></textarea>
        </details>

        <div class="card"><h2>Email</h2>
          <div class="row">
            <div><label>CC (optional)</label><input type="text" name="cc" value="<?= $v('cc') ?>" placeholder="colleague@example.com"></div>
            <?php $pkEmails = pm_apply_line($tpl, (string)($tpl['packages'][$pkSel]['type'] ?? ''))['emails']; ?>
            <div><label>Subject</label><input type="text" name="subject" value="<?= pm_h($d['subject'] ?? $pkEmails[$d['doc_type'] ?? 'both']['subject']) ?>"></div>
          </div><br>
          <label>Message</label>
          <textarea name="body" rows="14"><?= pm_h($d['body'] ?? $pkEmails[$d['doc_type'] ?? 'both']['body']) ?></textarea>
          <p class="hint">The message changes with the document type above. Words in braces fill in automatically when it is sent, e.g. {business} {contact} {monthly} {daily} {pain_title} {signature}. Edit freely for this client.</p>
        </div>
      </div>

      <div class="sticky">
        <div class="card"><h2>Quotation</h2>
          <div class="total"><span>Setup (once)</span><span id="tSetup">—</span></div>
          <div class="total"><span>Monthly</span><span id="tMonthly">—</span></div>
          <div class="total big"><span>First year</span><span id="tYear">—</span></div>
        </div>
        <div class="card">
          <button class="btn" name="do" value="polish" formnovalidate title="Rewrites the cover line, problems and email for this client, then reviews them like a sceptical buyer">Tailor with AI (pitch perfect)</button>
          <?php if (!empty($d['ai'])): ?><label class="check" style="margin:2px 0 8px"><input type="checkbox" name="clear_ai" value="1"> Remove the AI tailoring (use the standard wording)</label><?php endif; ?>
          <button class="btn" name="do" value="preview" formtarget="_blank">Preview PDF</button>
          <button class="btn" name="do" value="download">Generate and download</button>
          <button class="btn primary" name="do" value="email" onclick="return confirmSend()">Generate and email to client</button>
          <p class="hint">Preview does not use up a reference number. Download and email do, and are saved in History.</p>
        </div>
      </div>
    </div>
  </form>
  <form method="post" style="margin-top:-6px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="clear"><button class="btn small">Clear form</button></form>
  <script>
  const PK = <?= json_encode(array_map(fn($p) => [(float)$p['setup'], (float)$p['monthly']], $tpl['packages'])) ?>;
  const PKT = <?= json_encode(array_map(fn($p) => (string)($p['type'] ?? ''), $tpl['packages'])) ?>;
  const EX = <?= json_encode(array_map(fn($e) => [(float)$e['price'], $e['period']], $tpl['extras'])) ?>;
  const CURS = <?= json_encode(array_column($settings['currencies'], null, 'code')) ?>;
  let CUR = document.getElementById('qcur').value;
  const roundTo = (v, step) => step >= 1 ? Math.round(v / step) * step : Math.round(v * 100) / 100;
  const conv = v => roundTo(v * (+document.getElementById('qrate').value || 0), +((CURS[CUR] || {}).round || 1));
  const fmt = n => CUR + ' ' + (Math.round(n) === Math.round(n * 100) / 100 ? Math.round(n).toLocaleString('en-US') : n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
  function recalc() {
    const f = document.getElementById('pf');
    const i = +(f.querySelector('input[name=package]:checked') || {value: 0}).value;
    const ps = f.price_setup.value, pm = f.price_monthly.value;
    let s = ps !== '' ? +ps : (PK[i] ? conv(PK[i][0]) : 0), m = pm !== '' ? +pm : (PK[i] ? conv(PK[i][1]) : 0);
    EX.forEach((e, k) => { const el = f.querySelector(`[name="extras[${k}]"]`); if (!el) return; const q = Math.max(0, +el.value || 0);
      const pe = f.querySelector(`[name="extra_price[${k}]"]`); const unit = pe && pe.value !== '' ? +pe.value : conv(e[0]);
      if (e[1] === 'month') m += q * unit; else if (e[1] === 'once') s += q * unit; });
    s *= 1 - Math.min(100, +f.discount_setup.value || 0) / 100;
    m *= 1 - Math.min(100, +f.discount_monthly.value || 0) / 100;
    tSetup.textContent = fmt(s); tMonthly.textContent = fmt(m); tYear.textContent = fmt(s + 12 * m);
  }
  function confirmSend() {
    const e = document.getElementById('pf').email.value.trim();
    if (!e) { alert('Add the client\'s email address first.'); return false; }
    return confirm('Generate the PDF and email it to ' + e + '?');
  }
  function fillPackagePrice() {
    const f = document.getElementById('pf');
    const i = +(f.querySelector('input[name=package]:checked') || {value: 0}).value;
    if (PK[i]) { f.price_setup.value = conv(PK[i][0]); f.price_monthly.value = conv(PK[i][1]); }
  }
  function fillAllPrices(keepClientPrices) {
    const f = document.getElementById('pf');
    document.querySelectorAll('.curcode').forEach(el => el.textContent = CUR);
    document.querySelectorAll('[data-pk]').forEach(el => { const k = +el.dataset.pk;
      el.innerHTML = PKT[k] === 'onboarding' ? 'Free<br><span class="muted">while onboarding is free</span>' : fmt(conv(PK[k][0])) + ' setup<br><span class="muted">' + fmt(conv(PK[k][1])) + ' / month</span>'; });
    EX.forEach((e, k) => { const pe = f.querySelector(`[name="extra_price[${k}]"]`); if (pe && (!keepClientPrices || pe.value === '')) pe.value = conv(e[0]); });
    if (!keepClientPrices || f.price_setup.value === '') fillPackagePrice();
    recalc();
  }
  document.getElementById('qcur').addEventListener('change', e => {
    CUR = e.target.value; document.getElementById('qrate').value = (CURS[CUR] || {}).rate || 1; fillAllPrices(false); });
  document.getElementById('qrate').addEventListener('change', () => fillAllPrices(false));
  const BASE = <?= json_encode($settings['currency']) ?>, qmpu = document.getElementById('qmpu'), qbox = document.getElementById('qratebox');
  function syncRate() { const r = +document.getElementById('qrate').value || 1; qmpu.value = Math.round(100 / r) / 100; qbox.hidden = (CUR === BASE); document.getElementById('qcode').textContent = CUR; }
  document.getElementById('qcur').addEventListener('change', syncRate);
  qmpu.addEventListener('input', () => { const v = +qmpu.value; if (v > 0) { document.getElementById('qrate').value = 1 / v; fillAllPrices(false); } });
  document.querySelectorAll('#pf input[name=package]').forEach(r => r.addEventListener('change', () => { fillPackagePrice(); recalc(); }));
  fillAllPrices(true);
  const PKMAIL = <?= json_encode(array_map(fn($p) => pm_apply_line($tpl, (string)($p['type'] ?? ''))['emails'], $tpl['packages']), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  let EMAILS = PKMAIL[+((document.querySelector('#pf input[name=package]:checked') || {value: 0}).value)] || <?= json_encode($tpl['emails'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  document.querySelectorAll('#pf input[name=package]').forEach(r => r.addEventListener('change', () => { // new package: swap to its email unless you edited it
    const f = document.getElementById('pf'), t = (document.querySelector('#pf input[name=doc_type]:checked') || {value: 'both'}).value, old = EMAILS[t] || {}, nx = PKMAIL[+r.value] || EMAILS;
    if (f.subject.value === old.subject || f.subject.value.trim() === '') f.subject.value = nx[t].subject;
    if (f.body.value === old.body || f.body.value.trim() === '') f.body.value = nx[t].body;
    EMAILS = nx;
  }));
  let lastType = (document.querySelector('#pf input[name=doc_type]:checked') || {value: 'both'}).value;
  document.querySelectorAll('#pf input[name=doc_type]').forEach(r => r.addEventListener('change', () => {
    const f = document.getElementById('pf'), t = r.value, old = EMAILS[lastType] || {};
    // Swap to the new type's email unless you have edited the text for this client.
    if (f.subject.value === old.subject || f.subject.value.trim() === '') f.subject.value = EMAILS[t].subject;
    if (f.body.value === old.body || f.body.value.trim() === '') f.body.value = EMAILS[t].body;
    lastType = t;
  }));
  document.getElementById('pf').addEventListener('input', recalc);
  recalc();
  </script>

<?php elseif ($tab === 'template'): ?>
  <h1>Template</h1>
  <p class="sub">Everything the PDF says. Change prices, wording or terms here; every new PDF uses them straight away.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="template">
    <div class="card"><h2>Packages and prices</h2>
      <?= pm_rows_editor('packages', ['name' => ['Package', 'text', '20%'], 'type' => ['Business type', pm_types(), '16%'], 'suited' => ['Suited to', 'text', '32%'], 'setup' => ['Setup (' . $cur . ')', 'number', '14%'], 'monthly' => ['Monthly (' . $cur . ')', 'number', '14%']], $tpl['packages'], 'Add package') ?>
    </div>
    <div class="card"><h2>Extras</h2>
      <?= pm_rows_editor('extras', ['name' => ['Extra', 'text', '38%'], 'price' => ['Price', 'number', '12%'], 'period' => ['Charged', ['month' => 'Per month', 'once' => 'Once', 'note' => 'Text only'], '14%'], 'note' => ['Fee wording', 'text', '32%']], $tpl['extras'], 'Add extra') ?>
      <p class="hint">"Per month" and "Once" extras can be added to a quote with a quantity. "Text only" just appears in the price list.</p>
      <br><label>Note under the fees</label><textarea name="tpl[fees_note]" rows="3"><?= pm_h($tpl['fees_note']) ?></textarea>
    </div>
    <div class="card"><h2>Cover</h2>
      <div class="row"><div><label>Title</label><input type="text" name="tpl[title]" value="<?= pm_h($tpl['title']) ?>"></div>
        <div><label>Offer valid for (days)</label><input type="number" name="tpl[valid_days]" value="<?= (int)$tpl['valid_days'] ?>"></div></div><br>
      <label>Introduction</label><textarea name="tpl[intro]" rows="3"><?= pm_h($tpl['intro']) ?></textarea>
    </div>
    <div class="card"><h2>Pain points (the pitch opens with these)</h2>
      <div class="row"><div><label>Line on the cover</label><textarea name="tpl[cover_hook]" rows="2"><?= pm_h($tpl['cover_hook']) ?></textarea></div>
        <div><label>Section title</label><input type="text" name="tpl[pain_title]" value="<?= pm_h($tpl['pain_title']) ?>"><label style="margin-top:8px">Introduction</label><textarea name="tpl[pain_intro]" rows="2"><?= pm_h($tpl['pain_intro']) ?></textarea></div></div><br>
      <?= pm_rows_editor('pain_points', ['type' => ['Shown to', ['all' => 'Everyone'] + pm_types(), '14%'], 'pain' => ['The leak', 'textarea', '22%'], 'cost' => ['What it costs them', 'textarea', '34%'], 'fix' => ['How it stops', 'textarea', '26%']], $tpl['pain_points'], 'Add pain point') ?>
      <p class="hint">The PDF shows the client's own business type first, then the "Everyone" points, up to 8 in total. The first one for their type also goes into the pitch email.</p>
      <br><label>Line under the daily cost in the quotation</label><input type="text" name="tpl[anchor_note]" value="<?= pm_h($tpl['anchor_note']) ?>">
    </div>
    <div class="card"><h2>What changes on day one</h2>
      <label>Section title</label><input type="text" name="tpl[benefits_title]" value="<?= pm_h($tpl['benefits_title'] ?? 'What changes on day one') ?>"><br><br>
      <?= pm_rows_editor('benefits', [0 => ['Heading', 'text', '26%'], 1 => ['Text', 'textarea', '54%'], 2 => ['Shown to', ['all' => 'Everyone'] + pm_types(), '16%']], $tpl['benefits'], 'Add point') ?>
    </div>
    <div class="card"><h2>What is included</h2>
      <label>Business-type columns (comma separated)</label>
      <input type="text" name="tpl[module_columns]" value="<?= pm_h(implode(', ', $tpl['module_columns'])) ?>">
      <p class="hint">After changing the columns, save once so the grid below shows the right number of columns.</p><br>
      <?php $mc = [0 => ['Area', 'text', '18%'], 1 => ['What it covers', 'textarea', '30%']];
      foreach ($tpl['module_columns'] as $ci => $cn) { $mc[2 + $ci] = [$cn, ['' => '—', 'Yes' => 'Yes', 'Optional' => 'Optional'], (int)floor(48 / max(1, count($tpl['module_columns']))) . '%']; }
      echo pm_rows_editor('modules', $mc, $tpl['modules'], 'Add area'); ?>
    </div>
    <div class="card"><h2>Getting started</h2>
      <?= pm_rows_editor('steps', [0 => ['Step', 'text', '22%'], 1 => ['When', 'text', '14%'], 2 => ['What happens', 'textarea', '60%']], $tpl['steps'], 'Add step') ?>
      <br><label>Note under the steps</label><textarea name="tpl[steps_note]" rows="2"><?= pm_h($tpl['steps_note']) ?></textarea>
    </div>
    <div class="card"><h2>Support</h2>
      <label>Introduction</label><textarea name="tpl[support_intro]" rows="2"><?= pm_h($tpl['support_intro']) ?></textarea><br><br>
      <?= pm_rows_editor('support', [0 => ['Priority', 'text', '14%'], 1 => ['Example', 'textarea', '40%'], 2 => ['First response', 'text', '20%'], 3 => ['Target fix', 'text', '20%']], $tpl['support'], 'Add level') ?>
      <br><label>Note under the table</label><textarea name="tpl[support_note]" rows="2"><?= pm_h($tpl['support_note']) ?></textarea>
    </div>
    <div class="card"><h2>Terms of service</h2>
      <?= pm_rows_editor('terms', [0 => ['Clause', 'text', '20%'], 1 => ['Wording', 'textarea', '76%']], $tpl['terms'], 'Add clause') ?>
    </div>
    <div class="card"><h2>Emails</h2>
      <p class="hint" style="margin-top:0">One email per document type. Placeholders: {business} {contact} {reference} {package} {setup} {monthly} {daily} {first_year} {valid_until} {type_plural} {pain_title} {pain_cost} {or_call} {signature}</p>
      <?php foreach (['pitch' => 'Pitch only', 'contract' => 'Contract only', 'both' => 'Pitch + contract'] as $ek => $el): ?>
        <br><label><b style="color:var(--ink)"><?= $el ?></b> &middot; subject</label><input type="text" name="tpl[emails][<?= $ek ?>][subject]" value="<?= pm_h($tpl['emails'][$ek]['subject']) ?>">
        <label style="margin-top:8px">Message</label><textarea name="tpl[emails][<?= $ek ?>][body]" rows="12"><?= pm_h($tpl['emails'][$ek]['body']) ?></textarea>
      <?php endforeach; ?>
    </div>
    <button class="btn primary" style="max-width:260px">Save template</button>
  </form>
  <form method="post" style="margin-top:12px" onsubmit="return confirm('Put every price and word back to the original template?')">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="reset_template">
    <button class="btn small">Reset to original</button>
  </form>

  <h1 style="margin-top:34px">Business lines</h1>
  <p class="sub">The wording above is for the industry management system. Packages for our other lines (software, websites, hardware, IT support) use their own wording below. Review every commitment, timeline and clause before you send one.</p>
  <?php foreach (pm_line_names() as $ln => $lname): $L = $tpl['lines'][$ln]; ?>
  <details class="card" style="padding:0"><summary style="padding:16px 22px;margin:0;font-weight:600;color:var(--ink)"><?= pm_h($lname) ?></summary>
    <form method="post" style="padding:0 22px 20px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="lines"><input type="hidden" name="line" value="<?= $ln ?>">
      <?php foreach (PM_LINE_TEXT as $k => $lab): ?><label style="margin-top:10px"><?= pm_h($lab) ?></label><textarea name="ln[<?= $k ?>]" rows="<?= in_array($k, ['intro', 'fees_note', 'steps_note', 'support_note', 'pain_intro'], true) ? 3 : 1 ?>"><?= pm_h((string)($L[$k] ?? '')) ?></textarea><?php endforeach; ?>
      <label class="check" style="margin-top:10px"><input type="checkbox" name="ln[show_first_year]" value="1" <?= ($L['show_first_year'] ?? true) ? 'checked' : '' ?>> Show "first-year cost" under the quotation</label>
      <?php foreach (PM_LINE_LISTS as $k => [$lab, $cols]): ?><label style="margin-top:10px"><?= pm_h($lab) ?></label><textarea name="ln[<?= $k ?>]" rows="<?= max(3, min(9, count($L[$k] ?? []) + 1)) ?>"><?= pm_h(pm_rows_to_text($L[$k] ?? [], $cols)) ?></textarea><?php endforeach; ?>
      <?php foreach (['pitch' => 'Pitch only', 'contract' => 'Contract only', 'both' => 'Pitch + contract'] as $ek => $el): ?>
        <label style="margin-top:14px"><b style="color:var(--ink)">Email: <?= $el ?></b> &middot; subject</label><input type="text" name="ln[emails][<?= $ek ?>][subject]" value="<?= pm_h($L['emails'][$ek]['subject'] ?? '') ?>">
        <textarea name="ln[emails][<?= $ek ?>][body]" rows="9" style="margin-top:6px"><?= pm_h($L['emails'][$ek]['body'] ?? '') ?></textarea>
      <?php endforeach; ?>
      <div style="display:flex;gap:8px;margin-top:14px"><button class="btn primary" style="max-width:240px">Save <?= pm_h($lname) ?></button></div>
    </form>
    <form method="post" style="padding:0 22px 18px" onsubmit="return confirm('Put this line wording back to the original?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="reset_line"><input type="hidden" name="line" value="<?= $ln ?>"><button class="btn small">Reset to original</button></form>
  </details>
  <?php endforeach; ?>

<?php elseif ($tab === 'agents'): require __DIR__ . '/lib/view_agents.php'; ?>

<?php elseif ($tab === 'whatsapp'): require __DIR__ . '/lib/view_whatsapp.php'; ?>

<?php elseif ($tab === 'social'): $sv = (string)($_GET['view'] ?? ''); require pm_social_view_file($sv); ?>

<?php elseif ($tab === 'settings'): require __DIR__ . '/lib/view_settings.php'; ?>

<?php else: ?>
  <h1>History</h1>
  <p class="sub">Every proposal you downloaded or emailed. Open the PDF, or load it back into the form to send an updated version.</p>
  <div class="card">
  <?php $history = array_filter($history, fn($h) => pm_brand_of_type((string)($tpl['packages'][(int)($h['proposal']['package'] ?? -1)]['type'] ?? '')) === $vb); ?>
  <?php if (!$history): ?><p class="muted">No <?= $vb === 'travel' ? 'Travel Malawi' : 'ProManaged IT' ?> proposals yet.</p><?php else: ?>
    <table class="grid hist"><thead><tr><th>Reference</th><th>Date</th><th>Client</th><th>Package</th><th style="text-align:right">Setup</th><th style="text-align:right">Monthly</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($history as $i => $h): ?>
      <tr><td><b><?= pm_h($h['ref']) ?></b></td><td class="muted"><?= pm_h($h['when']) ?></td>
        <td><?= pm_h($h['business']) ?><br><span class="muted"><?= pm_h($h['contact']) ?></span></td>
        <td><?= pm_h($h['package']) ?></td>
        <td style="text-align:right"><?= pm_h(pm_money((float)$h['setup'], $h['currency'] ?? $cur)) ?></td>
        <td style="text-align:right"><?= pm_h(pm_money((float)$h['monthly'], $h['currency'] ?? $cur)) ?></td>
        <td><?php $signedRow = !empty($h['signed_file']); ?><span style="<?= $signedRow ? 'color:#1d6b3a;font-weight:600' : '' ?>"><?= pm_h($h['status']) ?></span>
          <?php if (!$signedRow && !empty($h['sign_url'])): ?><br><a class="muted" style="font-size:12px" href="<?= pm_h($h['sign_url']) ?>" target="_blank">Signing link</a><?php endif; ?></td>
        <td style="white-space:nowrap">
          <?php if (!empty($h['signed_file']) && is_file(PM_OUT . '/' . $h['signed_file'])): ?><a class="btn small" href="?file=<?= urlencode($h['signed_file']) ?>" target="_blank" style="border-color:#1d6b3a;color:#1d6b3a">Signed copy</a><?php endif; ?>
          <?php if (is_file(PM_OUT . '/' . $h['file'])): ?><a class="btn small" href="?file=<?= urlencode($h['file']) ?>" target="_blank">Open</a><?php endif; ?>
          <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="reuse"><input type="hidden" name="i" value="<?= $i ?>"><button class="btn small">Use again</button></form>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
  </div>
<?php endif; ?>
</main>
<div id="busy" role="status" aria-live="polite"><div class="bcard"><div class="spin"></div><b id="busyMsg">Working…</b><span id="busyTime"></span><span>Please wait. Other buttons are paused so two tasks cannot clash.</span></div></div>
<script src="assets/app.js?v=<?= @filemtime(PM_ROOT . '/assets/app.js') ?>"></script>
<script>
function addRow(btn) {
  const table = btn.previousElementSibling.previousElementSibling;
  const tpl = btn.previousElementSibling;
  const i = +table.dataset.next; table.dataset.next = i + 1;
  table.tBodies[0].insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__i__', 'n' + i));
}
</script>
</body>
</html>
