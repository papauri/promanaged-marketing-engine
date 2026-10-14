<?php
/**
 * Saving the settings of ProManaged IT and Travel Malawi. One form used to save everything at once, so every field had to be on the same screen. Now each department page saves only
 * its own part, named by the hidden field _sec, and leaves everything else as it is:
 *   business  company details, signing, link in messages, logo and signature     (Operations > Business details)
 *   email     mail server and login, copy to me                                   (Operations > Email)
 *   team      the marketing team, currencies and rates                            (Operations > Team and currencies)
 *   voice     what the AI knows: about, facts, audience, tone, never say          (Brand > Voice)
 *   messages  the standard part of Travel Malawi's first email                    (Sales > Targeting and messages)
 *   social    auto-publish                                                        (Social > Plan)
 * With no _sec (an older form) everything is saved, as before.
 * pm_settings_save() reads $_POST and $_FILES and returns [msg, kind, to]; the caller redirects.
 */

/** A PNG or JPG upload written as a PNG at $dest. Returns '' when fine (or nothing was sent), else why not. */
function pm_settings_image(string $field, string $dest): string
{
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return '';
    }
    $info = @getimagesize($_FILES[$field]['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
        return 'the picture must be a PNG or JPG image';
    }
    $img = $info[2] === IMAGETYPE_PNG ? imagecreatefrompng($_FILES[$field]['tmp_name']) : imagecreatefromjpeg($_FILES[$field]['tmp_name']);
    imagesavealpha($img, true);
    imagepng($img, $dest);
    return '';
}

function pm_settings_save(array $settings, string $vb): array
{
    $in = (array)($_POST['s'] ?? []);
    $sec = preg_replace('/[^a-z]/', '', (string)($_POST['_sec'] ?? ''));
    $part = fn(string $n) => $sec === '' || $sec === $n;
    $new = $settings;
    $tin = (array)($in['travel'] ?? []);
    $tv = (array)($new['travel'] ?? pm_default_settings()['travel']);
    $bad = '';
    $text = fn(array $from, string $k) => trim((string)($from[$k] ?? ''));

    if ($part('business')) {
        foreach (['company_name', 'tagline', 'address', 'phone', 'whatsapp', 'email', 'website', 'signatory_name', 'signatory_title', 'ref_prefix'] as $k) {
            $new[$k] = $text($in, $k);
        }
        $new['company_name'] = $new['company_name'] !== '' ? mb_substr($new['company_name'], 0, 80) : ((string)($settings['company_name'] ?? '') ?: 'ProManaged IT'); // a business always has a name
        $new['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['accent_color'] ?? '')) ? $in['accent_color'] : $settings['accent_color'];
        $new['next_ref'] = max(1, (int)($in['next_ref'] ?? 1));
        $new['link_url'] = pm_clean_url((string)($in['link_url'] ?? ''));
        $new['link_on'] = !empty($in['link_on']);
        $new['esign_tags'] = !empty($in['esign_tags']);
        $new['online_signing'] = !empty($in['online_signing']);
        foreach (['company_name', 'tagline', 'address', 'phone', 'whatsapp', 'email', 'website', 'signatory_name', 'signatory_title'] as $k) {
            $tv[$k] = $text($tin, $k);
        }
        $tv['from_email'] = '';
        $tv['ref_prefix'] = $text($tin, 'ref_prefix') ?: 'TM';
        $tv['next_ref'] = max(1, (int)($tin['next_ref'] ?? ($tv['next_ref'] ?? 1)));
        $tv['online_signing'] = !empty($tin['online_signing']);
        $tv['esign_tags'] = !empty($tin['esign_tags']);
        $tv['company_name'] = $tv['company_name'] !== '' ? mb_substr($tv['company_name'], 0, 80) : ((string)($settings['travel']['company_name'] ?? '') ?: 'Travel Malawi');
        $tv['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($tin['accent_color'] ?? '')) ? $tin['accent_color'] : ($tv['accent_color'] ?? '#047857');
        $tv['charging'] = !empty($tin['charging']);
        $tv['link_url'] = pm_clean_url((string)($tin['link_url'] ?? ''));
        $tv['link_on'] = !empty($tin['link_on']);
        if (!empty($_POST['remove_travel_signature']) && is_file(PM_ROOT . '/assets/travel_signature.png')) {
            unlink(PM_ROOT . '/assets/travel_signature.png');
        }
        if (!empty($_POST['remove_signature']) && is_file(PM_ROOT . '/assets/signature.png')) {
            unlink(PM_ROOT . '/assets/signature.png');
        }
        pm_settings_image('travel_signature', PM_ROOT . '/assets/travel_signature.png'); // a bad signature picture is skipped, as before
        pm_settings_image('signature', PM_ROOT . '/assets/signature.png');
        pm_settings_image('travel_logo', PM_ROOT . '/assets/travel_logo.png');
        $bad = pm_settings_image('logo', PM_ROOT . '/assets/logo.png');
    }

    if ($part('social')) { // editors cannot switch Auto-publish
        $new['social_auto'] = pm_social_can_approve() ? !empty($in['social_auto']) : !empty($settings['social_auto']);
        $tv['social_auto'] = pm_social_can_approve() ? !empty($tin['social_auto']) : !empty($settings['travel']['social_auto']);
    }

    if ($part('voice')) { // what the AI knows: only the fields that were sent
        foreach (['about', 'facts', 'audience', 'voice', 'never'] as $bk) {
            if (isset($in['brain'][$bk])) {
                $new['brain'][$bk] = trim((string)$in['brain'][$bk]);
            }
            if (isset($tin['brain'][$bk])) {
                $tv['brain'][$bk] = trim((string)$tin['brain'][$bk]);
            }
        }
    }

    if ($part('messages') && !empty($tin['host_email']) && is_array($tin['host_email'])) { // the standard part of the first email to a stay
        $he = $tin['host_email'];
        $heLines = fn($v) => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 240), preg_split('/\R/', (string)$v) ?: []))), 0, 6);
        $tv['host_email'] = ['intro' => mb_substr(trim((string)($he['intro'] ?? '')), 0, 400), 'benefits_title' => mb_substr(trim((string)($he['benefits_title'] ?? '')), 0, 60), 'benefits' => $heLines($he['benefits'] ?? ''),
            'steps_title' => mb_substr(trim((string)($he['steps_title'] ?? '')), 0, 60), 'steps' => $heLines($he['steps'] ?? ''), 'link' => pm_clean_url((string)($he['link'] ?? '')) ?: PM_TM_LINK,
            'free' => mb_substr(trim((string)($he['free'] ?? '')), 0, 240), 'why' => mb_substr(trim((string)($he['why'] ?? '')), 0, 300), 'closing' => mb_substr(trim((string)($he['closing'] ?? '')), 0, 200)];
    }

    if ($part('email')) {
        $tsm = (array)($tin['smtp'] ?? []);
        $tv['smtp'] = (array)($tv['smtp'] ?? pm_default_settings()['travel']['smtp']);
        $tv['smtp']['bcc_self'] = !empty($tsm['bcc_self']);
        $tv['smtp']['from_name'] = $text($tsm, 'from_name') ?: ($tv['company_name'] ?: 'Travel Malawi');
        if (!pm_tm_smtp_from_env()) {
            foreach (['host', 'username', 'from_email'] as $k) {
                $tv['smtp'][$k] = $text($tsm, $k);
            }
            $tv['smtp']['port'] = (int)($tsm['port'] ?? 465);
            $tv['smtp']['encryption'] = in_array($tsm['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $tsm['encryption'] : 'ssl';
            if (($tsm['password'] ?? '') !== '') {
                $tv['smtp']['password'] = (string)$tsm['password'];
            }
        }
        $sm = (array)($in['smtp'] ?? []);
        $new['smtp']['bcc_self'] = !empty($sm['bcc_self']);
        if (!pm_smtp_from_env()) { // with .env, the mail server fields are read-only
            foreach (['host', 'username', 'from_email', 'from_name'] as $k) {
                $new['smtp'][$k] = $text($sm, $k);
            }
            $new['smtp']['port'] = (int)($sm['port'] ?? 587);
            $new['smtp']['encryption'] = in_array($sm['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $sm['encryption'] : 'tls';
            if (($sm['password'] ?? '') !== '') {
                $new['smtp']['password'] = (string)$sm['password'];
            }
        }
    }

    if ($part('team')) {
        $new['currency'] = $text($in, 'currency');
        $new['team'] = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string)($in['team'] ?? ''))))));
        if (isset($in['agent_names']) && defined('PM_AGENT_ROLES')) { // the AI agents' names: one word each, no two alike (the usual name when left empty)
            $an = [];
            $taken = [];
            foreach (array_keys(PM_AGENT_ROLES) as $d) {
                $v = (string)preg_replace('/[^\p{L}\'-]+/u', '', (string)(preg_split('/\s+/u', trim((string)($in['agent_names'][$d] ?? '')))[0] ?? '')); // the first word, letters only
                $v = mb_substr($v, 0, 20);
                $v = $v !== '' ? mb_strtoupper(mb_substr($v, 0, 1)) . mb_substr($v, 1) : '';
                $use = $v !== '' && !isset($taken[mb_strtolower($v)]) ? $v : PM_AGENT_FIRST[$d]; // a name already given goes back to the usual one
                $taken[mb_strtolower($use)] = true;
                if ($use !== PM_AGENT_FIRST[$d]) {
                    $an[$d] = $use;
                }
            }
            $new['agent_names'] = $an;
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
    }

    $new['travel'] = $tv;
    pm_save('settings', $new);

    $brandQ = $vb === 'promanaged' ? '' : '&brand=' . $vb;
    $back = (string)($_POST['back'] ?? '');
    $to = preg_match('/^[a-z]+(&[a-z]+=[a-z0-9]+)*$/', $back) ? $back : 'settings&section=' . ($sec !== '' && in_array($sec, ['business', 'email', 'team'], true) ? $sec : 'business');
    $to .= str_contains($to, 'brand=') ? '' : $brandQ;
    if (!empty($_POST['test_email'])) { // the mailbox of the business on screen, saved first, tested with the same function as every real email
        $tb = pm_brand_valid((string)($_POST['test_brand'] ?? '')) ? (string)$_POST['test_brand'] : 'promanaged';
        [$ok, $tmsg] = pm_mail_test($tb, (string)($_POST['test_to'] ?? ''));
        return ['msg' => 'Settings saved. ' . $tmsg, 'kind' => $ok ? 'ok' : 'err', 'to' => 'settings&section=email' . ($tb === 'promanaged' ? '' : '&brand=' . $tb)];
    }
    if ($bad !== '') {
        return ['msg' => 'Settings saved, but ' . $bad . '.', 'kind' => 'err', 'to' => $to];
    }
    return ['msg' => 'Settings saved.', 'kind' => 'ok', 'to' => $to];
}

/**
 * Saving a business that was added in the app. The same sections as above, plus targeting (Sales > Targeting and messages); each page sends only its own part (_sec).
 *   details  name, tagline, contact details, accent colour, reference prefix, link in messages, logo
 *   email    mail server, mailbox for replies, copy to me
 *   voice    what the AI knows
 *   targeting  who buys, the free first step and its keyword, who not to pitch, daily run (what is sold, the types and the cities are the agent settings, saved by the agents handler)
 *   social   auto-publish
 */
function pm_brand_save_custom(string $bid): array
{
    $in = (array)($_POST['b'] ?? []);
    $sec = preg_replace('/[^a-z]/', '', (string)($_POST['_sec'] ?? ''));
    $part = fn(string $n) => $sec === '' || $sec === $n;
    $cur = (array)pm_settings()['brands'][$bid];
    $t = fn($k, $d = '') => trim((string)($in[$k] ?? $d));
    $byLine = fn($v) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string)$v))));
    $blk = $cur;
    $bad = '';
    if ($part('details')) {
        foreach (['tagline', 'address', 'phone', 'whatsapp', 'email', 'website', 'signatory_name', 'signatory_title'] as $k) {
            $blk[$k] = $t($k);
        }
        $blk['company_name'] = $t('company_name') ?: (string)$cur['company_name'];
        $blk['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $t('accent_color')) ? $t('accent_color') : (string)$cur['accent_color'];
        $blk['ref_prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $t('ref_prefix'))) ?: (string)$cur['ref_prefix'];
        $blk['link_url'] = pm_clean_url($t('link_url'));
        $blk['link_on'] = !empty($in['link_on']);
    }
    if ($part('social')) {
        $blk['social_auto'] = pm_social_can_approve() ? !empty($in['social_auto']) : !empty($cur['social_auto']);
    }
    if ($part('email')) {
        $sm = (array)($in['smtp'] ?? []);
        $blk['smtp'] = (array)$cur['smtp'];
        foreach (['host', 'username', 'from_email', 'from_name'] as $k) {
            $blk['smtp'][$k] = trim((string)($sm[$k] ?? ''));
        }
        $blk['smtp']['port'] = max(1, min(65535, (int)($sm['port'] ?? 465)));
        $blk['smtp']['encryption'] = in_array($sm['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $sm['encryption'] : 'ssl';
        $blk['smtp']['bcc_self'] = !empty($sm['bcc_self']);
        if (($sm['password'] ?? '') !== '') {
            $blk['smtp']['password'] = (string)$sm['password'];
        }
        $im = (array)($in['imap'] ?? []);
        $blk['imap'] = ['host' => trim((string)($im['host'] ?? '')), 'port' => max(1, min(65535, (int)($im['port'] ?? 993))), 'secure' => in_array($im['secure'] ?? '', ['ssl', 'none'], true) ? $im['secure'] : 'ssl'];
    }
    if ($part('voice')) {
        $blk['brain'] = [];
        foreach (['about', 'facts', 'audience', 'voice', 'never'] as $k) {
            $blk['brain'][$k] = trim(str_replace("\r\n", "\n", (string)($in['brain'][$k] ?? '')));
        }
    }
    if ($part('targeting')) {
        $prof = (array)($cur['profile'] ?? []);
        $prof['magnet'] = $t('magnet');
        $prof['cta_keyword'] = strtoupper(preg_replace('/[^A-Za-z]/', '', $t('cta_keyword')));
        $prof['customers'] = $t('customers');
        $prof['sell_to'] = in_array($in['sell_to'] ?? '', ['business', 'public', 'both'], true) ? $in['sell_to'] : ($prof['sell_to'] ?? 'business');
        if (isset($in['tg_segments'])) { // who to target and why: the scouts and the qualifier read it
            $prof['targeting'] = ['segments' => pm_study_segments_parse((string)$in['tg_segments'], pm_brand_targeting($bid)['segments']), 'skip' => array_slice($byLine($in['tg_skip'] ?? ''), 0, 8), 'angles' => array_slice($byLine($in['tg_angles'] ?? ''), 0, 8)];
        }
        $blk['profile'] = $prof;
    }
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['brands'][$bid] = $blk;
    pm_save('settings', $raw);
    if ($part('targeting')) {
        pm_update('brands', function (array $all) use ($bid, $in) {
            $all[$bid]['daily_run'] = !empty($in['daily_run']);
            $all[$bid]['name'] = $all[$bid]['name'] ?? $bid;
            return $all;
        }, fn() => []);
    }
    if ($part('details')) {
        $bad = pm_settings_image('logo', PM_ROOT . '/' . pm_brand_asset($bid, 'logo'));
    }
    $back = (string)($_POST['back'] ?? '');
    $to = (preg_match('/^[a-z]+(&[a-z]+=[a-z0-9]+)*$/', $back) ? $back : 'settings&section=' . ($sec === 'email' ? 'email' : 'business')) . '&brand=' . $bid;
    if (!empty($_POST['test_email']) && $part('email')) {
        [$tok, $tmsg] = pm_mail_test($bid, (string)($_POST['test_to'] ?? ''));
        return ['msg' => 'Saved. ' . $tmsg, 'kind' => $tok ? 'ok' : 'err', 'to' => 'settings&section=email&brand=' . $bid];
    }
    return $bad !== '' ? ['msg' => 'Saved, but ' . $bad . '.', 'kind' => 'err', 'to' => $to] : ['msg' => 'Saved.', 'kind' => 'ok', 'to' => $to];
}
