<?php
/**
 * Tests for the MARKETING.md cycle-3 register (C3-A01 .. C3-A09, C3-G01 .. C3-G04).
 * Runs on a TEMP COPY of data/ (see boot.php) with mail, WhatsApp, X and the AI stubbed: nothing real is contacted, nothing real is written.
 * Run: php tests/test_cycle3.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
// Facebook and the inbox for ProManaged IT only, for the setup-health test (the env file is read once, so it is written before anything asks for it)
file_put_contents($T['env'], "IMAP_HOST=mail.example\nIMAP_USER=u@example.mw\nIMAP_PASS=p\nFB_PAGE_ID=999\nFB_PAGE_TOKEN=tok\n", FILE_APPEND);
require_once dirname(__DIR__) . '/lib/wa_biz.php'; // brings engage.php, director.php
require_once dirname(__DIR__) . '/lib/view_drafts.php';
pm_brand_set('promanaged');
$GLOBALS['PM_AI_STUB'] = null;

function t(string $name, callable $fn): void
{
    echo "\n$name\n";
    try {
        $fn();
    } catch (Throwable $e) {
        pm_t_assert(false, "$name threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

function mk_lead(array $o = []): array
{
    $dom = $o['_dom'] ?? (bin2hex(random_bytes(4)) . '.mw');
    unset($o['_dom']);
    return $o + ['id' => 'l' . bin2hex(random_bytes(4)), 'brand' => 'promanaged', 'name' => 'Test Business ' . bin2hex(random_bytes(2)),
        'type' => 'Shops & supermarkets', 'city' => 'Lilongwe', 'status' => 'new', 'email' => 'hello@' . $dom,
        'created' => date('Y-m-d H:i', time() - 30 * 86400), 'updated' => date('Y-m-d H:i', time() - 30 * 86400), 'sent' => [], 'offering' => 'build', 'notes' => [], 'thread' => []];
}

function ok_domain(string $email): void
{
    $d = substr(strrchr($email, '@'), 1);
    pm_ob_update('email_verify', function ($x) use ($d) {
        $x['dns'][$d] = [true, time()];
        return $x;
    });
}

function mk_mailer(array &$box, bool $ok = true): callable
{
    return function (array $s, array $m) use (&$box, $ok) {
        $box[] = $m;
        return $ok ? [true, '', 'mid' . count($box)] : [false, 'smtp down', ''];
    };
}

function wa_on(): void
{
    putenv('WA_BIZ_TOKEN=tok');
    putenv('WA_BIZ_PHONE_ID=999');
    putenv('WA_BIZ_VERIFY=v');
}

function fx_pub(array $o = []): array
{
    $ago = $o['_ago'] ?? 24 * 20;
    unset($o['_ago']);
    $eng = $o['_eng'] ?? 10;
    unset($o['_eng']);
    $ts = time() - $ago * 3600;
    return pm_t_fixture_post($o + ['status' => 'published', 'published' => date('Y-m-d H:i', $ts), 'when' => date('Y-m-d H:i', $ts), 'fb_id' => '999_' . bin2hex(random_bytes(3)),
        'metrics' => ['d7' => ['at' => date('Y-m-d H:i', $ts + 7 * 86400), 'reactions' => $eng, 'comments' => 0, 'shares' => 0, 'reach' => null, 'clicks' => null, 'saves' => null, 'eng' => $eng]], 'metrics_src' => 'api']);
}

/* =========================================================== A01 */

t('C3-A01 WhatsApp opt-in: who agreed, and how', function () {
    $none = mk_lead(['id' => 'o1', 'whatsapp' => '+265 999 200 001']);
    pm_t_eq(pm_wa_optin($none), [], 'a lead that never wrote on WhatsApp has no opt-in');
    $wrote = mk_lead(['thread' => [['dir' => 'in', 'at' => '2026-10-01 09:00', 'text' => 'hi', 'ch' => 'wa']]]);
    pm_t_eq(pm_wa_optin($wrote)['source'], 'wrote_first', 'a WhatsApp message from them, with nothing sent by us before, means they wrote first');
    $replied = mk_lead(['wa_sent' => ['2026-09-30 10:00'], 'thread' => [['dir' => 'in', 'at' => '2026-10-01 09:00', 'text' => 'ok', 'ch' => 'wa']]]);
    pm_t_eq(pm_wa_optin($replied)['source'], 'replied', 'a WhatsApp message after ours is a reply');
    pm_t_eq(pm_wa_optin(mk_lead(['thread' => [['dir' => 'in', 'at' => '2026-10-01 09:00', 'text' => 'hi', 'ch' => 'email']]])), [], 'an email reply is not a WhatsApp opt-in');
    $l = mk_lead();
    pm_t_assert(pm_wa_optin_record($l, 'form', '2026-10-02 08:00') && pm_wa_optin($l)['source'] === 'form', 'a recorded opt-in counts');
    pm_t_assert(!pm_wa_optin_record($l, 'owner') && $l['wa_optin']['source'] === 'form', 'the first record is kept');
    pm_t_assert(!pm_wa_optin_record($l, 'nonsense') , 'an unknown source is refused');
    pm_wa_optin_clear($l);
    pm_t_eq(pm_wa_optin($l), [], 'removing it ends it');
    $l['thread'][] = ['dir' => 'in', 'at' => date('Y-m-d H:i', time() + 60), 'text' => 'hello again', 'ch' => 'wa'];
    pm_t_eq(pm_wa_optin($l)['source'], 'wrote_first', 'and a new WhatsApp message from them brings it back');
    pm_t_assert(str_contains(pm_wa_optin_line(mk_lead()), 'No WhatsApp opt-in'), 'the lead card says plainly when there is none');
    // a WhatsApp reply is recorded by the reply handler; a STOP never is
    $a = mk_lead(['status' => 'contacted', 'sent' => ['2026-10-01 08:00']]);
    pm_apply_reply($a, 'Yes please, tell me more', ['intent' => 'interested', 'summary' => 'wants more'], 'draft', null, 'wa');
    pm_t_eq(pm_wa_optin($a)['source'] ?? '', 'wrote_first', 'a first WhatsApp message from a lead counts as opt-in');
    $b = mk_lead(['status' => 'contacted', 'wa_sent' => ['2026-10-01 08:00']]);
    pm_apply_reply($b, 'Sounds good', ['intent' => 'interested', 'summary' => 'ok'], 'draft', null, 'wa');
    pm_t_eq(pm_wa_optin($b)['source'] ?? '', 'replied', 'a WhatsApp reply to our message counts as a reply');
    $c = mk_lead(['status' => 'contacted']);
    pm_apply_reply($c, 'STOP', ['intent' => 'stop', 'summary' => 'stop'], 'draft', null, 'wa');
    pm_t_eq(pm_wa_optin($c), [], 'STOP is never an opt-in');
    $e = mk_lead(['status' => 'contacted']);
    pm_apply_reply($e, 'Thanks', ['intent' => 'interested', 'summary' => 'x'], 'draft', null, 'email');
    pm_t_eq(pm_wa_optin($e), [], 'an email reply is not one either');
    // the website form box
    pm_save('leads', []);
    $r = pm_web_lead(['brand' => 'promanaged', 'kind' => 'enquiry', 'name' => 'Grace', 'business' => 'Grace Shop', 'phone' => '0999 300 001', 'message' => 'Please call me about stock', 'wa_ok' => '1']);
    pm_t_eq($r['lead']['wa_optin']['source'] ?? '', 'form', 'a ticked WhatsApp box on the form is an opt-in');
    $r2 = pm_web_lead(['brand' => 'promanaged', 'kind' => 'enquiry', 'name' => 'Peter', 'business' => 'Peter Shop', 'phone' => '0999 300 002', 'message' => 'Please call me about stock']);
    pm_t_assert(empty($r2['lead']['wa_optin']), 'an unticked box is not');
    $r3 = pm_web_lead(['brand' => 'promanaged', 'kind' => 'enquiry', 'name' => 'Ann', 'business' => 'Ann Shop', 'email' => 'ann@annshop.mw', 'message' => 'Please email me about stock', 'wa_ok' => '1']);
    pm_t_assert(empty($r3['lead']['wa_optin']), 'a ticked box with no phone number records nothing');
});

t('C3-A01 campaigns choose "agreed to WhatsApp only" (on by default)', function () {
    wa_on();
    putenv('WA_BIZ_TEMPLATE');
    $mk = fn($id, $o = []) => mk_lead($o + ['id' => $id, 'status' => 'contacted', 'whatsapp' => '+265 999 4' . str_pad((string)(crc32($id) % 100000), 5, '0', STR_PAD_LEFT), 'contact' => 'Person ' . $id, 'name' => 'Biz ' . $id, 'score' => 70]);
    $leads = ['in1' => $mk('in1', ['wa_optin' => ['source' => 'owner', 'at' => '2026-09-01 10:00']]), 'in2' => $mk('in2', ['thread' => [['dir' => 'in', 'at' => '2026-10-01 09:00', 'text' => 'hi', 'ch' => 'wa']]]),
        'out1' => $mk('out1')];
    pm_save('leads', $leads);
    pm_save('wa_campaigns', ['rows' => []]);
    $GLOBALS['PM_WHO'] = '';
    $text = 'We are checking in with the businesses we have spoken to, {first_name}. If anything about your computers or backups has become a headache, reply here and we will gladly look.';
    $_POST = ['name' => 'Default', 'text' => $text, 'statuses' => ['contacted'], 'optin_form' => '1', 'optin_only' => '1', 'send_from' => date('Y-m-d\TH:i', time() - 60)];
    pm_do_wac_create('promanaged');
    $c = pm_wac_campaigns('promanaged')[0];
    pm_t_eq($c['aud']['optin_only'], true, 'the campaign stores "agreed only"');
    [$ok, $skip] = pm_wac_audience('promanaged', $c['aud']);
    $ids = array_column($ok, 'id');
    sort($ids);
    pm_t_eq($ids, ['in1', 'in2'], 'only people with an opt-in are in it');
    pm_t_eq($skip['Biz out1'] ?? '', 'no WhatsApp opt-in on record', 'the others say why they were held back');
    $_POST = ['name' => 'Everyone', 'text' => $text, 'statuses' => ['contacted'], 'optin_form' => '1', 'send_from' => date('Y-m-d\TH:i', time() - 60)]; // box unticked
    pm_do_wac_create('promanaged');
    $c2 = pm_wac_campaigns('promanaged')[1];
    pm_t_eq($c2['aud']['optin_only'], false, 'switching it off is the owner\'s choice, per campaign');
    pm_t_eq(count(pm_wac_audience('promanaged', $c2['aud'])[0]), 3, 'then everyone allowed is included');
    $_POST = ['name' => 'Old form', 'text' => $text, 'statuses' => ['contacted'], 'send_from' => date('Y-m-d\TH:i', time() - 60)]; // no marker at all
    pm_do_wac_create('promanaged');
    pm_t_eq(pm_wac_campaigns('promanaged')[2]['aud']['optin_only'], true, 'a request without the box defaults to the safe choice');
    pm_t_eq(pm_wac_audience('promanaged', ['statuses' => ['contacted']])[0] === [] ? 0 : count(pm_wac_audience('promanaged', ['statuses' => ['contacted']])[0]), 2, 'an older campaign with no setting is treated as "agreed only"');
    // re-checked at send time: the opt-in removed after approval stops the send
    $_POST = ['id' => $c['id']];
    pm_do_wac_approve('promanaged');
    pm_update('leads', function ($all) {
        pm_wa_optin_clear($all['in1']);
        return $all;
    });
    $sent = [];
    $n = pm_wac_send_pass($c['id'], function ($num, $text, $lead) use (&$sent) {
        $sent[] = $lead['id'];
        return [true, 'wamid'];
    });
    pm_t_eq($sent, ['in2'], 'a person whose opt-in was removed after approval is skipped at send time');
    $rec = array_column(pm_wac_get($c['id'])['recipients'], null, 'id');
    pm_t_eq($rec['in1']['note'] ?? '', 'no WhatsApp opt-in on record', 'and the campaign says why');
});

/* =========================================================== A02 */

t('C3-A02 several WhatsApp templates, one per campaign', function () {
    wa_on();
    putenv('WA_BIZ_TEMPLATE=checkin_v1');
    putenv('WA_BIZ_TEMPLATE_LANG=en');
    pm_t_eq(array_keys(pm_wa_templates()), ['checkin_v1'], 'the .env template is the default');
    $_POST = ['tname' => 'Promo_Oct', 'tlang' => 'en_GB'];
    $r = pm_do_wa_template_add('promanaged');
    pm_t_eq($r['kind'], 'ok', 'a second template can be named: ' . $r['msg']);
    pm_t_eq(array_keys(pm_wa_templates()), ['checkin_v1', 'promo_oct'], 'it joins the list, lower-cased');
    pm_t_eq(pm_wa_templates()['promo_oct']['lang'], 'en_GB', 'with its language');
    $_POST = ['tname' => 'bad name!', 'tlang' => 'en'];
    pm_t_eq(pm_do_wa_template_add('promanaged')['kind'], 'err', 'a name WhatsApp would not accept is refused');
    $_POST = ['tname' => 'promo_oct', 'tlang' => 'en'];
    pm_t_eq(pm_do_wa_template_add('promanaged')['kind'], 'err', 'a duplicate is refused');
    $_POST = ['tname' => 'x_y', 'tlang' => 'english'];
    pm_t_eq(pm_do_wa_template_add('promanaged')['kind'], 'err', 'a language that is not a code is refused');
    // a campaign remembers its template
    $lead = mk_lead(['id' => 'tp1', 'status' => 'contacted', 'whatsapp' => '+265 999 500 001', 'contact' => 'Tina', 'name' => 'Biz tp1', 'score' => 70, 'wa_optin' => ['source' => 'owner', 'at' => '2026-09-01 10:00']]);
    pm_save('leads', ['tp1' => $lead]);
    pm_save('wa_campaigns', ['rows' => []]);
    $text = 'We are checking in with the businesses we have spoken to, {first_name}. If anything about your computers or backups has become a headache, reply here and we will gladly look.';
    $_POST = ['name' => 'Promo', 'text' => $text, 'statuses' => ['contacted'], 'optin_form' => '1', 'optin_only' => '1', 'template' => 'promo_oct', 'send_from' => date('Y-m-d\TH:i', time() - 60)];
    pm_do_wac_create('promanaged');
    $_POST = ['name' => 'Plain', 'text' => $text, 'statuses' => ['contacted'], 'optin_form' => '1', 'optin_only' => '1', 'template' => 'not_a_template', 'send_from' => date('Y-m-d\TH:i', time() - 60)];
    pm_do_wac_create('promanaged');
    [$c1, $c2] = pm_wac_campaigns('promanaged');
    pm_t_eq([$c1['template'], $c2['template']], ['promo_oct', ''], 'the chosen template is stored; an unknown one falls back to the default');
    // the sender uses it outside the 24-hour window
    $out = [];
    $GLOBALS['PM_WA_STUB'] = function ($to, $text) use (&$out) {
        $out[] = $text;
        return [true, 'wamid'];
    };
    $s = pm_wac_api_sender('promo_oct');
    [$ok] = $s('26599950001', 'x', $lead);
    $s2 = pm_wac_api_sender('');
    $s2('26599950001', 'x', $lead);
    $s3 = pm_wac_api_sender('removed_one');
    $s3('26599950001', 'x', $lead);
    pm_t_eq([$ok, $out], [true, ['[template promo_oct] Tina', '[template checkin_v1] Tina', '[template checkin_v1] Tina']], 'the campaign\'s template is used; the default fills in when none or a removed one is named');
    $_POST = ['tname' => 'promo_oct'];
    pm_do_wa_template_remove('promanaged');
    pm_t_eq(array_keys(pm_wa_templates()), ['checkin_v1'], 'a template can be removed from the list');
    putenv('WA_BIZ_TEMPLATE');
    pm_save('wa_campaigns', ['rows' => []]);
    pm_t_eq(pm_wac_api_sender('')('26599950001', 'x', $lead)[0], false, 'with no template anywhere, a send outside the window is refused with a clear reason');
    $GLOBALS['PM_WA_STUB'] = null;
});

/* =========================================================== A03 */

t('C3-A03 win-back and post-sign asks on WhatsApp Business, inside the 24-hour window', function () {
    wa_on();
    $sent = [];
    $GLOBALS['PM_WA_STUB'] = function ($to, $text) use (&$sent) {
        $sent[] = [$to, $text];
        return [true, 'wamid'];
    };
    $inWindow = [['dir' => 'in', 'at' => date('Y-m-d H:i', time() - 3600), 'text' => 'hello', 'ch' => 'wa']];
    $good = 'Hello, we have a fresh idea that could help with your stock counts. If that sounds useful, I can send a short plan, and feel free to ignore this if the timing is wrong.';
    $a = mk_lead(['id' => 'wa1', 'status' => 'lost', 'whatsapp' => '+265 999 600 001', 'thread' => $inWindow, 'winback_draft' => ['email_subject' => 's', 'email_body' => 'b', 'whatsapp' => $good]]);
    $b = mk_lead(['id' => 'wa2', 'status' => 'won', 'whatsapp' => '+265 999 600 002', 'thread' => $inWindow, 'postsign' => ['testimonial' => 'May we quote you?', 'review' => 'Please review us', 'referral' => 'Know anyone?']]);
    $old = mk_lead(['id' => 'wa3', 'status' => 'lost', 'whatsapp' => '+265 999 600 003', 'thread' => [['dir' => 'in', 'at' => date('Y-m-d H:i', time() - 3 * 86400), 'text' => 'x', 'ch' => 'wa']], 'winback_draft' => ['whatsapp' => $good]]);
    $stop = mk_lead(['id' => 'wa4', 'status' => 'optout', 'whatsapp' => '+265 999 600 004', 'thread' => $inWindow, 'winback_draft' => ['whatsapp' => $good]]);
    $price = mk_lead(['id' => 'wa5', 'status' => 'lost', 'whatsapp' => '+265 999 600 005', 'thread' => $inWindow, 'winback_draft' => ['whatsapp' => $good . ' It costs MWK 50,000 a month.']]);
    $nonum = mk_lead(['id' => 'wa6', 'status' => 'lost', 'whatsapp' => '', 'phone' => '', 'thread' => $inWindow, 'winback_draft' => ['whatsapp' => $good]]);
    pm_save('leads', ['wa1' => $a, 'wa2' => $b, 'wa3' => $old, 'wa4' => $stop, 'wa5' => $price, 'wa6' => $nonum]);
    $leads = pm_leads();
    $r = pm_send_wa_draft($leads, 'wa1', 'winback');
    pm_t_assert(!empty($r['ok']) && count($sent) === 1 && str_contains($sent[0][1], 'fresh idea') && str_contains($sent[0][1], 'STOP'), 'a win-back goes out on WhatsApp Business with the STOP line: ' . $r['msg']);
    pm_t_eq([$leads['wa1']['status'], isset($leads['wa1']['winback_draft']), count($leads['wa1']['wa_sent']), $leads['wa1']['thread'][1]['ch']], ['contacted', false, 1, 'wa'], 'the lead is back in play and the send is logged on it');
    pm_t_eq(pm_leads()['wa1']['status'], 'contacted', 'and saved');
    $leads = pm_leads();
    $r = pm_send_wa_draft($leads, 'wa2', 'review');
    pm_t_assert(!empty($r['ok']) && !empty($leads['wa2']['postsign_sent']['review']) && $leads['wa2']['status'] === 'won', 'a post-sign ask goes out and never changes the status');
    $n = count($sent);
    foreach ([['wa3', 'winback', 'More than 24 hours'], ['wa4', 'winback', 'asked not to be contacted'], ['wa5', 'winback', 'Not sent'], ['wa6', 'winback', 'mobile number']] as [$id, $k, $why]) {
        $leads = pm_leads();
        $r = pm_send_wa_draft($leads, $id, $k);
        pm_t_assert(empty($r['ok']) && str_contains($r['msg'], $why), "$id refused: " . $r['msg']);
    }
    pm_t_eq(count($sent), $n, 'nothing was sent for any refused one');
    $leads = pm_leads();
    pm_t_assert(empty(pm_send_wa_draft($leads, 'nobody', 'winback')['ok']) && empty(pm_send_wa_draft($leads, 'wa2', 'bogus')['ok']), 'an unknown lead or ask is refused');
    putenv('WA_BIZ_TOKEN');
    $leads = pm_leads();
    pm_t_assert(str_contains(pm_send_wa_draft($leads, 'wa2', 'referral')['msg'], 'not connected'), 'without WhatsApp Business keys the button does nothing');
    wa_on();
    // the daily WhatsApp limit applies
    $c = pm_load('agents_config', fn() => []);
    $cap = (int)pm_agents_config()['wa_cap'];
    $leads = pm_leads();
    $leads['wa2']['wa_sent'] = array_fill(0, $cap, date('Y-m-d H:i'));
    pm_t_assert(str_contains(pm_send_wa_draft($leads, 'wa2', 'referral')['msg'], 'limit'), 'the daily limit stops it');
    // the screen offers the button only inside the window
    $html = pm_view_lead_drafts(pm_leads()['wa2'] + ['id' => 'wa2'], 'tok', fn($id, $do, $x = '') => '', fn($n, $v) => '');
    pm_t_assert(str_contains($html, 'postsign_wa_biz') && str_contains($html, 'Send on WhatsApp Business'), 'the post-sign card shows "Send on WhatsApp Business" while the window is open');
    $html2 = pm_view_lead_drafts(mk_lead(['id' => 'wa7', 'status' => 'won', 'postsign' => ['review' => 'x'], 'thread' => []]), 'tok', fn($id, $do, $x = '') => '', fn($n, $v) => '');
    pm_t_assert(!str_contains($html2, 'postsign_wa_biz'), 'and hides it when the window is closed');
    $GLOBALS['PM_WA_STUB'] = null;
});

/* =========================================================== A04 */

t('C3-A04 engagement raises the score (capped, rule-based)', function () {
    $l = mk_lead(['id' => 'e1', 'status' => 'contacted', 'score' => 60, 'view_count' => 1, 'last_viewed_at' => date('Y-m-d H:i', time() - 3600)]);
    pm_t_eq(pm_engagement_signals($l)['kinds'], [], 'one view is not a signal');
    $l['view_count'] = 2;
    $sig = pm_engagement_signals($l);
    pm_t_eq([$sig['kinds'], $sig['bonus']], [['opened the proposal 2 times'], PM_ENGAGE_OPENED_BUMP], 'two views raise it by 8');
    $l['view_count'] = 5;
    pm_t_eq(pm_engagement_signals($l)['bonus'], PM_ENGAGE_OPENED_BUMP + PM_ENGAGE_OPENED_MORE, 'four or more by 14');
    $l['last_reply'] = date('Y-m-d H:i', time() - 600);
    pm_t_eq(pm_engagement_signals($l)['bonus'], PM_ENGAGE_CAP, 'with a reply on top (26) it stops at the cap of 25');
    $note = pm_apply_engagement($l);
    pm_t_eq([$l['score'], $l['engage_bonus']], [85, 25], 'the score goes from 60 to 85: ' . $note);
    pm_t_assert(str_contains($note, 'opened the proposal 5 times and wrote back'), 'and the note says why');
    pm_t_eq(pm_apply_engagement($l), '', 'applying again changes nothing');
    pm_t_eq(pm_engagement_due(['e1' => $l]), [], 'and it is not due again until something new happens');
    pm_t_eq([PM_ENGAGE_OPENED_BUMP, PM_ENGAGE_OPENED_MORE, PM_ENGAGE_REPLY_BUMP], [8, 6, 12], 'the points are 8, 6 and 12');
    $l['view_count'] = 6;
    $l['last_viewed_at'] = date('Y-m-d H:i', time() + 120);
    pm_t_eq(count(pm_engagement_due(['e1' => $l])), 1, 'a newer signal makes it due again');
    $hi = mk_lead(['status' => 'replied', 'score' => 90, 'last_reply' => date('Y-m-d H:i')]);
    pm_apply_engagement($hi);
    pm_t_eq($hi['score'], PM_ENGAGE_MAX_SCORE, 'never above 95');
    $same = mk_lead(['status' => 'replied', 'score' => 96, 'last_reply' => date('Y-m-d H:i')]);
    pm_apply_engagement($same);
    pm_t_eq($same['score'], 96, 'and a score already above is never lowered');
    foreach (['won', 'lost', 'optout'] as $st) {
        pm_t_eq(pm_engagement_due([mk_lead(['status' => $st, 'last_reply' => date('Y-m-d H:i')])]), [], "a $st lead is never touched");
    }
    $many = [];
    for ($i = 0; $i < 30; $i++) {
        $many[] = mk_lead(['status' => 'contacted', 'last_reply' => date('Y-m-d H:i', time() - $i * 60)]);
    }
    pm_t_eq(count(pm_engagement_due($many, 20)), 20, 'a run handles at most 20');
    // re-scoring after research starts from the engagement-free number
    $q = mk_lead(['status' => 'contacted', 'score' => 70, 'engage_bonus' => 12, 'research' => ['at' => date('Y-m-d H:i')]]);
    pm_apply_requalify($q, ['score' => 50], 'promanaged');
    pm_t_eq([$q['score'], isset($q['engage_bonus'])], [50, false], 'a fresh AI score replaces the bump, which is added again from the same signals');
    // the stored leads, by the scheduler job
    pm_save('leads', ['j1' => mk_lead(['id' => 'j1', 'status' => 'contacted', 'score' => 50, 'last_reply' => date('Y-m-d H:i')]), 'j2' => mk_lead(['id' => 'j2', 'brand' => 'travel', 'status' => 'contacted', 'score' => 50, 'last_reply' => date('Y-m-d H:i')])]);
    pm_t_assert(str_contains(pm_job_engagement_scores('promanaged'), '1 lead'), 'the scheduler job raises this business\'s leads: ' . pm_job_engagement_scores('travel'));
    pm_t_eq([pm_leads()['j1']['score'], pm_leads()['j2']['score']], [62, 62], 'each business is handled on its own turn');
    // Today
    $plan = pm_daily_plan(['w1' => mk_lead(['id' => 'w1', 'status' => 'contacted', 'name' => 'Warm Co', 'score' => 80, 'engage_at' => date('Y-m-d H:i'), 'engage_why' => 'wrote back', 'last_contacted' => date('Y-m-d H:i', time() - 86400), 'phone' => '0999 700 001'])], pm_agents_config());
    $warm = array_values(array_filter($plan, fn($t) => str_contains($t['title'], 'Warm lead')));
    pm_t_assert(count($warm) === 1 && $warm[0]['prio'] === 1 && str_contains($warm[0]['title'], 'wrote back'), 'Today puts a warm lead near the top: ' . ($warm[0]['title'] ?? '-'));
});

/* =========================================================== A05 */

t('C3-A05 a clearly losing first-email subject style stops early', function () {
    pm_t_assert(abs(pm_fisher_p(5, 1, 0, 6) - 0.0152) < 0.001 && abs(pm_fisher_p(4, 2, 0, 6) - 0.0606) < 0.001, 'Fisher exact p is right on known tables');
    $ex = ['A' => 'Your stock, sorted', 'B' => 'Is stock a headache?'];
    pm_t_eq(pm_exp_early(['A' => [5, 0], 'B' => [5, 4]], $ex), null, 'under 6 sends an arm: nothing');
    pm_t_eq(pm_exp_early(['A' => [6, 0], 'B' => [6, 3]], $ex), null, '3 replies against none could still be luck (p 0.18): nothing');
    pm_t_eq(pm_exp_early(['A' => [6, 0], 'B' => [6, 4]], $ex), null, '4 against none is still just over the bar (p 0.06): nothing');
    $v = pm_exp_early(['A' => [6, 0], 'B' => [6, 5]], $ex);
    pm_t_assert($v && $v['winner'] === 'B' && $v['loser'] === 'A' && $v['early'] === true, '5 against none is clear: B wins early');
    pm_t_eq(pm_exp_early(['A' => [10, 3], 'B' => [10, 5]], $ex), null, 'a clear gap needs the loser at half or less: not here');
    pm_t_eq(pm_exp_judge(['A' => [6, 0], 'B' => [6, 5]], $ex, 20)['status'], 'few', 'by default the judge still waits for 20 sends');
    pm_t_eq(pm_exp_judge(['A' => [6, 0], 'B' => [6, 5]], $ex, 20, true)['status'], 'win', 'with early stopping allowed it can stop before 20');
    // the weekly job and the arms
    $rows = [];
    for ($i = 0; $i < 6; $i++) {
        $rows['a' . $i] = mk_lead(['id' => 'a' . $i, 'email_arm' => 'A', 'sent' => ['2026-10-01 09:00'], 'drafts' => ['email_subject' => 'Your stock, sorted'], 'status' => 'contacted']);
        $rows['b' . $i] = mk_lead(['id' => 'b' . $i, 'email_arm' => 'B', 'sent' => ['2026-10-01 09:00'], 'drafts' => ['email_subject' => 'Is stock a headache?'], 'status' => $i < 5 ? 'replied' : 'contacted', 'last_reply' => $i < 5 ? '2026-10-03 10:00' : '']);
    }
    pm_save('leads', $rows);
    pm_save('email_exp', []);
    $msg = pm_jobg_email_exp();
    $st = pm_load('email_exp', fn() => []);
    pm_t_assert(str_contains($msg, 'stopped early') && ($st['stopped']['winner'] ?? '') === 'B', 'the weekly job stops arm A early: ' . $msg);
    $arms = [];
    for ($i = 0; $i < 12; $i++) {
        $arms[pm_email_exp_arm('lead' . $i, 'promanaged')] = 1;
    }
    pm_t_eq(array_keys($arms), ['B'], 'every new first email now gets the winning style');
    $fa = [];
    for ($i = 0; $i < 20; $i++) {
        $fa[pm_followup_arm(['id' => 'fu' . $i, 'followups' => 0], 'promanaged')] = 1;
    }
    pm_t_eq(count($fa), 2, 'follow-up subjects are their own experiment and keep alternating');
    $st['stopped']['until'] = date('Y-m-d', strtotime('-1 day'));
    pm_save('email_exp', $st);
    $arms = [];
    for ($i = 0; $i < 24; $i++) {
        $arms[pm_email_exp_arm('lead' . $i, 'promanaged')] = 1;
    }
    pm_t_eq(count($arms), 2, 'after the 30-day pause both styles are tried again');
    pm_save('email_exp', []);
});

/* =========================================================== A06 */

t('C3-A06 partnership outreach is tracked and the radar learns from it', function () {
    pm_save('partners', []);
    $items = [['name' => 'Lake Travel Blog', 'kind' => 'Travel blog', 'url' => 'https://example.mw/lake', 'why' => 'Posts about the lake', 'dm' => 'Hello, we love your lake posts and would like to share a guest tip with your readers. Would that be of interest to you?'],
        ['name' => 'Tourism Forum', 'kind' => 'Facebook group', 'url' => 'https://example.mw/forum', 'why' => 'Active group', 'dm' => 'Hello, we run a small business and enjoy your forum. Could we share one helpful tip with the group, if you allow it?']];
    $rows = pm_partners_clean($items);
    pm_t_assert(preg_match('/^[a-f0-9]{10}$/', $rows[0]['id']) && $rows[0]['status'] === '', 'each suggestion has an id and an empty status');
    pm_save('partners', ['promanaged' => ['day' => date('Y-m-d'), 'rows' => $rows]]);
    pm_t_eq(count(pm_partners('promanaged')), 2, 'the suggestions show');
    pm_t_assert(pm_partner_set_status('promanaged', $rows[0]['id'], 'sent'), 'Sent can be recorded');
    pm_t_assert(pm_partner_set_status('promanaged', $rows[0]['id'], 'replied'), 'and changed to They replied');
    pm_t_assert(!pm_partner_set_status('promanaged', $rows[0]['id'], 'maybe') && !pm_partner_set_status('promanaged', 'zzzzzzzzzz', 'sent'), 'an unknown status or id is refused');
    pm_t_eq(pm_partners('promanaged')[0]['status'], 'replied', 'the status shows on the card');
    pm_partner_set_status('promanaged', $rows[1]['id'], 'no');
    // a stored row from before ids existed can still be tracked
    pm_save('partners', ['promanaged' => ['day' => date('Y-m-d'), 'rows' => [['name' => 'Old Row', 'kind' => 'Blog', 'url' => 'https://example.mw/old', 'why' => '', 'dm' => 'x']]]]);
    $oldId = pm_partners('promanaged')[0]['id'];
    pm_t_assert(pm_partner_set_status('promanaged', $oldId, 'sent') && pm_partners('promanaged')[0]['status'] === 'sent', 'rows made before C3 can be tracked too');
    // history, and what the radar is told
    $hist = [['id' => 'a1', 'name' => 'A', 'kind' => 'Travel blog', 'status' => 'replied'], ['id' => 'a2', 'name' => 'B', 'kind' => 'Travel blog', 'status' => 'sent'],
        ['id' => 'a3', 'name' => 'C', 'kind' => 'Facebook group', 'status' => 'no'], ['id' => 'a4', 'name' => 'D', 'kind' => 'Facebook group', 'status' => 'sent'], ['id' => 'a5', 'name' => 'E', 'kind' => 'Photographer', 'status' => 'sent']];
    $line = pm_partners_learn_line($hist);
    pm_t_assert(str_contains($line, 'travel blog (1 of 2 answered)') && str_contains($line, 'facebook group (0 of 2 answered)') && !str_contains($line, 'photographer'), 'the line names kinds that answered and kinds that did not (a single try proves nothing): ' . $line);
    pm_t_eq(pm_partners_learn_line([]), '', 'with no outcomes there is nothing to say');
    // the weekly job
    pm_save('partners', ['promanaged' => ['day' => date('Y-m-d', strtotime('-9 days')), 'history' => $hist, 'rows' => [array_merge($rows[0], ['status' => 'replied']), array_merge($rows[1], ['status' => ''])]]]);
    putenv('PM_TEST_NOW=2026-10-12 09:00');
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen, $items) {
        $seen[] = $sys;
        return json_encode(['items' => $items]);
    };
    pm_jobg_partners();
    putenv('PM_TEST_NOW');
    $GLOBALS['PM_AI_STUB'] = null;
    $mine = array_values(array_filter($seen, fn($s) => str_contains($s, 'Partnership radar for ProManaged')));
    pm_t_assert($mine && str_contains($mine[0], 'travel blog (2 of 3 answered)'), 'the radar is told what happened before, tracked suggestions of the old list included');
    $d = pm_load('partners', fn() => [])['promanaged'];
    pm_t_assert(in_array($rows[0]['id'], array_column($d['history'], 'id'), true) && !in_array($rows[1]['id'], array_column($d['history'], 'id'), true), 'the tracked old suggestion joined the history; the untouched one was dropped');
    pm_t_eq(array_column($d['rows'], 'id'), [$rows[1]['id']], 'a page already acted on is not suggested again');
    $r = pm_partner_buttons('promanaged', $d['rows'][0]);
    pm_t_assert(str_contains($r, 'partner_status') && str_contains($r, 'They replied') && str_contains($r, 'Sent'), 'the card has the three buttons');
    $_POST = ['id' => $d['rows'][0]['id'], 'status' => 'sent'];
    pm_t_eq(pm_do_partner_status('promanaged')['kind'], 'ok', 'the form handler saves it');
});

/* =========================================================== A07 */

t('C3-A07 reviews won are counted and the client is thanked', function () {
    $l = mk_lead(['id' => 'rv1', 'status' => 'won', 'contact' => 'Dr Mary Banda', 'name' => 'Banda Clinic', 'postsign' => ['testimonial' => 't', 'review' => 'Please review us', 'referral' => 'r'], 'postsign_sent' => ['review' => '2026-10-05 10:00']]);
    pm_t_assert(pm_review_mark_posted($l) && $l['review_posted_at'] === date('Y-m-d'), 'ticking it records today');
    pm_t_assert(str_starts_with($l['postsign']['thanks'], 'Hello Mary, thank you') && !preg_match('#https?://|MWK|\d{3}#', $l['postsign']['thanks']), 'a plain thank-you is drafted for them: ' . $l['postsign']['thanks']);
    pm_t_assert(!pm_review_mark_posted($l), 'it is never counted twice');
    pm_t_assert(in_array('postsign', pm_lead_open_drafts($l + ['postsign_done' => '2026-10-06']), true), 'the thank-you stays on the lead card even after the asks were marked done');
    $l2 = $l;
    $l2['postsign_done'] = '2026-10-06';
    $l2['postsign_sent']['thanks'] = '2026-10-07';
    pm_t_eq(pm_lead_open_drafts($l2), [], 'until it is sent');
    // by month
    $mk = fn($id, $when, $o = []) => mk_lead($o + ['id' => $id, 'status' => 'won', 'review_posted_at' => $when]);
    $leads = ['m1' => $mk('m1', date('Y-m-d')), 'm2' => $mk('m2', date('Y-m-d', strtotime('-1 month'))), 'm3' => $mk('m3', date('Y-m-01', strtotime('-1 month'))), 'm4' => $mk('m4', date('Y-m-d'), ['brand' => 'travel']),
        'm5' => $mk('m5', date('Y-m-d', strtotime('-9 months')))];
    $by = pm_reviews_by_month('promanaged', 6, $leads);
    pm_t_eq([count($by), $by[date('Y-m')], $by[date('Y-m', strtotime(date('Y-m-01') . ' -1 month'))]], [6, 1, 2], 'six months, the right counts, another business\'s review not included, one older than that left out');
    pm_save('leads', $leads + ['ask' => mk_lead(['id' => 'ask', 'status' => 'won', 'postsign_sent' => ['review' => '2026-10-01 09:00']])]);
    $h = pm_panel_results_reviews('promanaged');
    pm_t_assert(str_contains($h, 'Google reviews won') && str_contains($h, '4 in all') && str_contains($h, '1 ask sent, no review counted yet'), 'the Results card shows the total and the asks still waiting');
    pm_save('leads', []);
    pm_t_assert(str_contains(pm_panel_results_reviews('promanaged'), 'Nothing counted yet'), 'and says so honestly when there is nothing');
    // sending the thank-you by email uses the same gates
    $t = mk_lead(['id' => 'rv2', 'status' => 'won', 'name' => 'Thanks Co', 'postsign' => ['thanks' => 'Hello, thank you for taking the time to review us. It means a lot to a small business like ours, and we are glad to be working with you.']]);
    ok_domain($t['email']);
    pm_save('leads', ['rv2' => $t]);
    $leads = pm_leads();
    $box = [];
    $r = pm_send_postsign($leads, 'rv2', 'thanks', mk_mailer($box));
    pm_t_assert(!empty($r['ok']) && $box[0]['subject'] === 'Thank you' && str_contains($box[0]['body'], 'STOP'), 'the thank-you email sends with the STOP line: ' . $r['msg']);
    pm_t_assert(!empty(pm_leads()['rv2']['postsign_sent']['thanks']) && pm_leads()['rv2']['status'] === 'won', 'it is logged and the status stays Won');
    // the card on the lead
    $html = pm_view_lead_drafts($l + ['id' => 'rv1'], 'tok', fn($id, $do, $x = '') => '', fn($n, $v) => '');
    pm_t_assert(str_contains($html, 'Review counted') && str_contains($html, 'Thank-you for their review'), 'the lead card shows it was counted and offers the thank-you');
    $html2 = pm_view_lead_drafts(mk_lead(['id' => 'rv3', 'status' => 'won', 'postsign' => ['review' => 'x']]), 'tok', fn($id, $do, $x = '') => '', fn($n, $v) => '');
    pm_t_assert(str_contains($html2, 'review_posted') && str_contains($html2, 'They posted the review'), 'and offers the button before it is counted');
});

/* =========================================================== A08 */

t('C3-A08 the planner leans toward a segment that clearly outperforms (within a cap)', function () {
    pm_brand_set('promanaged');
    $SC = 'Schools & colleges';
    $SH = 'Shops & supermarkets';
    // a fresh scoreboard each time: the board is cached on file size and time, so every scenario writes a file of its own size
    $board = function (array $list, int $k) {
        pm_save('social_posts', []);
        foreach ($list as $i => [$seg, $eng]) {
            fx_pub(['segment' => $seg, 'pillar' => 'Proof', '_eng' => $eng, '_ago' => 24 * (20 + $i), 'caption' => 'Scenario ' . $k . ' post ' . $i . str_repeat('x', 23 * $k)]);
        }
    };
    $board([[$SC, 30], [$SC, 32], [$SC, 34], [$SH, 10], [$SH, 12], [$SH, 11]], 1);
    $b = pm_sx_segment_boost('promanaged');
    pm_t_assert($b && $b['segment'] === $SC && $b['n'] === 3 && $b['ratio'] >= 1.3 && $b['share'] === 0.4, 'a segment with 3 scored posts at 1.3x the average is the boost: ' . json_encode($b));
    $board([[$SC, 30], [$SC, 32], [$SH, 10], [$SH, 12], [$SH, 11], [$SH, 9]], 2);
    pm_t_eq(pm_sx_segment_boost('promanaged'), null, 'two posts is not enough');
    $board([[$SC, 12], [$SC, 13], [$SC, 14], [$SH, 10], [$SH, 12], [$SH, 11]], 3);
    pm_t_eq(pm_sx_segment_boost('promanaged'), null, 'a segment only a little above average is not');
    $board([['All businesses', 40], ['All businesses', 42], ['All businesses', 44], [$SH, 10], [$SH, 12], [$SH, 11]], 4);
    pm_t_eq(pm_sx_segment_boost('promanaged'), null, 'the catch-all "All businesses" is never a segment to boost');
    // the picker: seeds for the winning segment come first, at most 40% of the plan
    $board([[$SC, 30], [$SC, 32], [$SC, 34], [$SH, 10], [$SH, 12], [$SH, 11]], 5);
    $raw = pm_load('social_bank', fn() => []);
    $ideas = [];
    foreach (['Check the school fees ledger every week before term starts.', 'Teach pupils to log in with their own account at school.', 'Give every teacher a separate login at school.',
        'Count the shop till every day at closing.', 'Check shop stock against records weekly.', 'Keep shop prices in one place so every shop till agrees.',
        'Back up the shop sales to a second place each night.', 'Count shop takings against the system total daily.'] as $i => $text) {
        $ideas[] = ['id' => 'i' . $i, 'text' => $text, 'pillar' => 'Tip/How-to', 'active' => true];
    }
    $raw['promanaged']['ideas'] = $ideas;
    $raw['promanaged']['builtin_ideas'] = false;
    pm_save('social_bank', $raw);
    $segs = array_map(fn($i) => pm_sx_seed_segment(['topic' => $i['text'], 'facts' => [$i['text']], 'audience' => ''], 'promanaged'), $ideas);
    pm_t_eq(array_count_values($segs)[$SC] ?? 0, 3, 'three of the ideas speak to schools by their own words');
    $slots = pm_social_seed_pick('promanaged', 5, pm_social_posts(), 'mix', date('Y-m-d', strtotime('+3 days')));
    $boosted = array_filter($slots, fn($s) => !empty($s['segment_boost']));
    pm_t_assert(count($boosted) >= 1 && count($boosted) <= 2, 'between one and two of five slots (the 40% cap) go to the winning segment: ' . count($boosted) . ' of ' . count($slots));
    pm_t_assert(!array_filter($boosted, fn($s) => pm_sx_seed_segment($s['seed'], 'promanaged') !== $SC), 'and they really are about schools');
    $line = pm_plan_learnings('promanaged');
    pm_t_assert(str_contains($line, 'Audience boost: posts for Schools & colleges averaged') && str_contains($line, 'up to 40%'), 'the "Why this week\'s mix" text says so: ' . substr($line, 0, 200));
    $board([['All businesses', 10], ['All businesses', 11]], 6);
    $slots = pm_social_seed_pick('promanaged', 3, [], 'mix', date('Y-m-d', strtotime('+3 days')));
    pm_t_assert(!array_filter($slots, fn($s) => !empty($s['segment_boost'])), 'with no measured segment nothing is boosted');
    pm_save('social_posts', []);
    pm_save('social_bank', []);
});

/* =========================================================== A09 */

t('C3-A09 the directory scout adds leads from public member lists, weekly', function () {
    pm_brand_set('promanaged');
    $rows = pm_directory_clean([
        ['name' => 'Bright Clinic', 'type' => 'clinic', 'city' => 'Zomba', 'email' => 'info@brightclinic.mw', 'phone' => '0999 111 000', 'website' => 'brightclinic.mw', 'source' => 'https://zombachamber.example/members', 'evidence' => ['Listed as a member']],
        ['name' => 'No Source Ltd', 'source' => ''],
        ['name' => 'Plain Http Ltd', 'source' => 'http://example.mw/list'],
        ['name' => '', 'source' => 'https://zombachamber.example/members'],
        ['name' => 'Bad Email Co', 'email' => 'not-an-email', 'source' => 'https://zombachamber.example/members'],
        'junk',
    ]);
    pm_t_eq(array_column($rows, 'name'), ['Bright Clinic', 'Bad Email Co'], 'only rows with a name and an https listing page are kept');
    pm_t_eq([$rows[0]['email'], $rows[0]['website'], $rows[1]['email']], ['info@brightclinic.mw', 'https://brightclinic.mw', ''], 'a valid email stays, the website is tidied, an invalid email is blanked not repaired');
    $cfg = pm_agents_config('promanaged');
    $dupeId = pm_lead_id('Bad Email Co', 'Zomba', 'promanaged');
    $pool = [$dupeId => mk_lead(['id' => $dupeId, 'name' => 'Bad Email Co', 'city' => 'Zomba'])];
    $new = pm_directory_leads($rows, $pool, 'promanaged', $cfg, 'Zomba', 5);
    pm_t_eq(array_column($new, 'name'), ['Bright Clinic'], 'a business we already know is not added again');
    $l = array_values($new)[0];
    pm_t_assert($l['src']['kind'] === 'directory' && $l['src']['url'] === 'https://zombachamber.example/members' && $l['status'] === 'new' && $l['brand'] === 'promanaged', 'the lead is tagged src directory with its source link');
    pm_t_assert(str_contains($l['evidence'][0], 'https://zombachamber.example/members'), 'and the evidence names the page that listed it');
    $clients = ['Bright Clinic'];
    $cfg2 = $cfg;
    $cfg2['existing_clients'] = $clients;
    pm_t_eq(pm_directory_leads($rows, [], 'promanaged', $cfg2, 'Zomba', 5) === [] ? 1 : count(pm_directory_leads($rows, [], 'promanaged', $cfg2, 'Zomba', 5)), 1, 'an existing client is never added as a new lead');
    pm_t_eq(count(pm_directory_leads($rows, [], 'promanaged', $cfg, 'Zomba', 1)), 1, 'no more than the room left today');
    // the weekly rhythm
    pm_save('directory_state', []);
    pm_t_assert(pm_directory_due('promanaged'), 'due on the first run');
    pm_directory_mark('promanaged');
    pm_t_assert(!pm_directory_due('promanaged') && pm_directory_due('travel'), 'then not for a week, and each business has its own');
    pm_save('directory_state', ['promanaged' => date('Y-m-d', strtotime('-8 days'))]);
    pm_t_assert(pm_directory_due('promanaged'), 'and due again after a week');
    // the agent asks for public pages only and names no other business
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen[] = $sys . "\n" . $user;
        return json_encode([['name' => 'Bright Clinic', 'source' => 'https://zombachamber.example/members']]);
    };
    $out = pm_agent_directory('promanaged', 'Zomba', ['clinics and pharmacies', 'schools and colleges'], ['Old Co (Zomba)']);
    $GLOBALS['PM_AI_STUB'] = null;
    pm_t_assert(count($out) === 1 && str_contains($seen[0], 'PUBLIC business directories') && str_contains($seen[0], 'Never guess') && str_contains($seen[0], 'Old Co') && str_contains($seen[0], 'clinics and pharmacies'), 'the scout is told to read public lists, never guess, and skip what is known');
});

/* =========================================================== G01 */

t('C3-G01 restore one file from a weekly archive', function () {
    pm_save('t3_sample', ['a' => 1, 'note' => 'old', 'smtp' => ['password' => 'secret-old', 'host' => 'h']]);
    [$path, $n] = pm_data_archive_make('2026-09-01');
    pm_t_assert($path !== '' && $n > 0, 'an archive is made');
    $name = basename($path);
    pm_t_assert(pm_archive_path($name) === $path && pm_archive_path('../settings.json') === '' && pm_archive_path('data-2099-01-01.json.gz') === '', 'only a listed archive is found: never a path');
    pm_t_assert(isset(pm_archive_read($path)['t3_sample.json']), 'the archive can be read');
    pm_t_assert(!isset(pm_archive_read($path)['t3_sample.json']['smtp']), 'the mail login was never in it');
    pm_save('t3_sample', ['a' => 2, 'note' => 'new', 'extra' => true, 'smtp' => ['password' => 'secret-new', 'host' => 'h2']]);
    [$ok, $msg] = pm_archive_restore($name, 't3_sample.json');
    pm_t_assert($ok && str_contains($msg, 'Restored t3_sample.json'), 'restore works: ' . $msg);
    $now = pm_load('t3_sample', fn() => []);
    pm_t_eq([$now['a'], $now['note'], isset($now['extra'])], [1, 'old', false], 'the file is back as it was in the archive');
    pm_t_eq($now['smtp']['password'], 'secret-new', 'but the current login is kept: a restore never wipes a password');
    $kept = glob(PM_DATA . '/backup/t3_sample.before-restore-*.json');
    pm_t_assert(count($kept) === 1 && json_decode((string)file_get_contents($kept[0]), true)['note'] === 'new', 'the file it replaced was kept in the backup folder');
    foreach ([['nope.zip', 't3_sample.json', 'not in the list'], [$name, '../leads.json', 'not a data file'], [$name, 'absent.json', 'not in that archive'], [$name, 'T3.JSON', 'not a data file']] as [$a, $f, $why]) {
        $r = pm_archive_restore($a, $f);
        pm_t_assert(!$r[0] && str_contains($r[1], $why), "refused ($a, $f): " . $r[1]);
    }
    pm_t_eq(pm_load('t3_sample', fn() => [])['note'], 'old', 'nothing changed after the refusals');
    pm_t_eq(pm_restore_keep_secrets(['api_key' => 'k', 'a' => ['page_token' => 't', 'x' => 1]], ['a' => ['x' => 2], 'b' => 1]), ['a' => ['x' => 2, 'page_token' => 't'], 'b' => 1, 'api_key' => 'k'], 'secret keys are carried over, at any depth');
    ob_start();
    pm_view_archives();
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'Backups') && str_contains($h, 'archive=' . $name) && str_contains($h, 'archive_restore') && str_contains($h, 't3_sample.json'), 'Settings lists the archive with a download link and a restore form');
});

/* =========================================================== G02 */

t('C3-G02 setup-health test buttons', function () {
    $GLOBALS['PM_SMTP_CHECK_STUB'] = fn($sm) => [true, 'logged in to ' . $sm['host']];
    $GLOBALS['PM_IMAP_CHECK_STUB'] = fn($c) => [false, 'the mail server refused the login'];
    $GLOBALS['PM_WA_CHECK_STUB'] = fn($c) => [true, 'connected to +265 999 000 000'];
    $GLOBALS['PM_GRAPH_STUB'] = function ($method, $path, $params, $token, $files) {
        return [true, ['name' => 'Test Page', 'link' => 'https://fb.example/p', 'fan_count' => 10, 'followers_count' => 12]];
    };
    $raw = pm_load('settings', 'pm_default_settings');
    $raw['smtp'] = ['host' => 'mail.example', 'port' => 587, 'encryption' => 'tls', 'username' => 'u@example.mw', 'password' => 'p', 'from_email' => 'u@example.mw', 'from_name' => 'X', 'bcc_self' => false];
    pm_save('settings', $raw);
    putenv('IMAP_HOST=mail.example');
    putenv('IMAP_USER=u@example.mw');
    putenv('IMAP_PASS=p');
    wa_on();
    $rows = array_column(pm_setup_health(), null, 'key');
    pm_t_assert(!empty($rows['smtp_promanaged']['testable']) && !empty($rows['imap_promanaged']['testable']) && !empty($rows['fb_promanaged']['testable']) && !empty($rows['wa_promanaged']['testable']), 'rows that are set up have a Test button');
    pm_t_assert(empty($rows['ai']['testable']) && empty($rows['scheduler']['testable']) && empty($rows['li_promanaged']['testable']), 'rows with no check behind them do not');
    pm_t_assert(empty($rows['fb_travel']['testable']), 'and a row that is not set up cannot be tested yet');
    [$ok, $m] = pm_health_check('smtp_promanaged');
    pm_t_assert($ok && str_contains($m, 'mail.example'), 'the mail check reports ok: ' . $m);
    [$ok, $m] = pm_health_check('imap_promanaged');
    pm_t_assert(!$ok && str_contains($m, 'refused'), 'a failing check gives the reason: ' . $m);
    [$ok, $m] = pm_health_check('fb_promanaged');
    pm_t_assert($ok && str_contains($m, 'Test Page'), 'the Facebook check names the Page: ' . $m);
    [$ok, $m] = pm_health_check('wa_promanaged');
    pm_t_assert($ok && str_contains($m, '+265'), 'the WhatsApp check names the number: ' . $m);
    pm_t_assert(!pm_health_check('smtp_nobody')[0] && !pm_health_check('bogus')[0] && !pm_health_check('')[0], 'an unknown business or row is refused');
    $last = pm_health_last();
    pm_t_assert(!empty($last['smtp_promanaged']['ok']) && empty($last['imap_promanaged']['ok']) && $last['imap_promanaged']['msg'] !== '', 'the last result is remembered per row');
    $rows = array_column(pm_setup_health(), null, 'key');
    pm_t_assert(!empty($rows['imap_promanaged']['last']) && $rows['imap_promanaged']['last']['ok'] === false, 'and shown with the row');
    pm_brand_set('promanaged');
    pm_t_eq(pm_brand(), 'promanaged', 'a check never leaves the app on another business');
    ob_start();
    pm_view_setup_health();
    $h = ob_get_clean();
    pm_t_assert(str_contains($h, 'name="action" value="health_check"') && str_contains($h, 'Test now') && str_contains($h, 'Last test'), 'the panel draws the button and the last result');
    foreach (['PM_SMTP_CHECK_STUB', 'PM_IMAP_CHECK_STUB', 'PM_WA_CHECK_STUB', 'PM_GRAPH_STUB'] as $k) {
        $GLOBALS[$k] = null;
    }
});

/* =========================================================== G03 */

t('C3-G03 archive search and bulk restore', function () {
    $mk = fn($id, $name, $city, $type, $o = []) => mk_lead($o + ['id' => $id, 'name' => $name, 'city' => $city, 'type' => $type, 'status' => 'contacted', 'archived_at' => '2026-10-0' . random_int(1, 9) . ' 10:00']);
    pm_save('leads_archive', ['a1' => $mk('a1', 'Zomba Clinic', 'Zomba', 'clinic'), 'a2' => $mk('a2', 'Zomba School', 'Zomba', 'school'), 'a3' => $mk('a3', 'Lilongwe Clinic', 'Lilongwe', 'clinic'),
        'a4' => $mk('a4', 'Travel Stay', 'Zomba', 'lodge', ['brand' => 'travel'])]);
    pm_save('leads', []);

    $ids = fn($q, $c = '', $t = '') => (function () use ($q, $c, $t) { $k = array_keys(pm_archive_search('promanaged', $q, $c, $t)); sort($k); return $k; })();
    pm_t_eq($ids('', 'zomba'), ['a1', 'a2'], 'by city, ignoring case, only this business');
    pm_t_eq($ids('', '', 'clinic'), ['a1', 'a3'], 'by type');
    pm_t_eq($ids('lilongwe'), ['a3'], 'by words in the name');
    pm_t_eq($ids('', 'zomba', 'clinic'), ['a1'], 'combined');
    pm_t_eq($ids('nothing'), [], 'no match, no rows');
    pm_t_eq(count(pm_archive_search('promanaged', '', '', '', 2)), 2, 'a search is capped');
    [$n, $msg] = pm_lead_unarchive_many(['a1', 'a3', 'a1', 'ghost']);
    pm_t_assert($n === 2 && str_contains($msg, '2 leads are back') && str_contains($msg, '1 could not be restored'), 'several at once, each exactly like a single restore: ' . $msg);
    pm_t_eq(array_keys(pm_leads()), ['a1', 'a3'], 'they are back in the main list');
    pm_t_eq(array_keys(pm_leads_archive()), ['a2', 'a4'], 'and gone from the archive');
    pm_t_eq(pm_lead_unarchive_many([])[1], 'Tick the leads to restore first.', 'nothing ticked says so');
    ob_start();
    $csrf = 'tok';
    $post = fn($id, $do, $extra = '') => '<input type="hidden" name="do" value="' . $do . '">';
    $hid = fn($n, $v) => '';
    $src = (string)file_get_contents(dirname(__DIR__) . '/lib/view_agents.php');
    pm_t_assert(str_contains($src, "'restore_leads'") && str_contains($src, 'name="aq"') && str_contains($src, 'Restore the ticked leads'), 'the Agents screen has the search box and the bulk button');
    ob_end_clean();
});

/* =========================================================== G04 */

t('C3-G04 old published posts are archived, their numbers kept', function () {
    pm_save('social_posts', []);
    pm_save('social_posts_archive', ['posts' => [], 'summary' => []]);
    $old1 = fx_pub(['_eng' => 20, '_ago' => 24 * 600, 'caption' => 'Old one about stock counts at close.']);
    $old2 = fx_pub(['_eng' => 40, '_ago' => 24 * 600, 'caption' => 'Old two about staff logins on shared tills.']);
    $oldT = fx_pub(['_eng' => 5, '_ago' => 24 * 580, 'brand' => 'travel', 'pillar' => 'Stay spotlight', 'caption' => 'An old stay spotlight post.']);
    $young = fx_pub(['_eng' => 10, '_ago' => 24 * 100, 'caption' => 'A recent post about prices in one place.']);
    $draft = pm_t_fixture_post(['status' => 'draft', 'when' => date('Y-m-d', strtotime('-24 months')) . ' 07:30', 'caption' => 'An old draft nobody published, so it stays.']);
    pm_t_eq(pm_posts_archive_run(), 3, 'three published posts older than 18 months move');
    $live = array_column(pm_social_posts(), 'id');
    pm_t_assert(in_array($young['id'], $live, true) && in_array($draft['id'], $live, true) && !in_array($old1['id'], $live, true), 'recent posts and unpublished drafts stay in the live file');
    $a = pm_posts_archive_summary('promanaged');
    pm_t_assert($a['posts'] === 2 && $a['eng'] === 60 && $a['measured'] === 2 && $a['avg'] === 30.0, 'the summary keeps the count, the engagement and the average: ' . json_encode($a));
    pm_t_eq(pm_posts_archive_summary('travel')['posts'], 1, 'each business has its own summary');
    pm_t_eq(pm_posts_archive_run(), 0, 'running it again changes nothing');
    pm_t_eq(pm_posts_archive_summary('promanaged')['posts'], 2, 'and never double-counts');
    pm_t_eq(pm_social_scoreboard('promanaged')['archived']['posts'], 2, 'the scoreboard carries the summary');
    $h = pm_panel_results_archived('promanaged');
    pm_t_assert(str_contains($h, '2 published posts') && str_contains($h, '60 engagements') && str_contains($h, 'Restore them'), 'the Results screen says how many were archived and offers to restore');
    pm_t_eq(pm_panel_results_archived('nobody'), '', 'nothing to say for a business with none');
    $_POST = [];
    $r = pm_do_posts_archive_restore('promanaged');
    pm_t_assert(str_contains($r['msg'], '2 archived post'), 'restoring puts them back: ' . $r['msg']);
    $live = array_column(pm_social_posts(), 'id');
    pm_t_assert(in_array($old1['id'], $live, true) && in_array($old2['id'], $live, true) && !in_array($oldT['id'], $live, true), 'only this business\'s posts came back');
    pm_t_eq([pm_posts_archive_summary('promanaged')['posts'], pm_posts_archive_summary('travel')['posts']], [0, 1], 'and its summary went with them');
    pm_t_eq(pm_jobg_posts_archive(), '2 published post(s) older than 18 months archived (their numbers are kept)', 'the monthly job archives what is old again');
    pm_t_eq(pm_jobg_posts_archive(), '', 'once a month');
});

pm_t_done();
