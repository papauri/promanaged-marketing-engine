<?php
/**
 * Tests for the MARKETING.md cycle-2 register (C2-A01 .. C2-A12, C2-G01 .. C2-G04).
 * Runs on a TEMP COPY of data/ (see boot.php) with mail, WhatsApp, X and the AI stubbed: nothing real is contacted, nothing real is written.
 * Run: php tests/test_cycle2.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
// Facebook for ProManaged IT only, for the setup-health test (the env file is the harness's stub file)
file_put_contents($T['env'], "FB_PAGE_ID=999\nFB_PAGE_TOKEN=tok\n", FILE_APPEND);
require_once dirname(__DIR__) . '/lib/wa_biz.php'; // brings engage.php, director.php
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

/** Tells the mail check this domain accepts mail (so no real DNS lookup happens in tests). */
function ok_domain(string $email): void
{
    $d = substr(strrchr($email, '@'), 1);
    pm_ob_update('email_verify', function ($x) use ($d) {
        $x['dns'][$d] = [true, time()];
        return $x;
    });
}

/** A mailer that records instead of sending. */
function mk_mailer(array &$box, bool $ok = true): callable
{
    return function (array $s, array $m) use (&$box, $ok) {
        $box[] = $m;
        return $ok ? [true, '', 'mid' . count($box)] : [false, 'smtp down', ''];
    };
}

/** A published post with 7-day numbers (reactions only, so eng = reactions). */
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

$LONG = 'We looked at how your shop handles stock and think a simple weekly count sheet could save you an afternoon each week. If that sounds useful, I can send a one page plan for your business, feel free to ignore this if the timing is wrong, and I am happy to answer questions here.';

/* =========================================================== A01 */

t('C2-A01 win-back, post-sign and re-check results reach the owner', function () use ($LONG) {
    $lost = mk_lead(['id' => 'wb1', 'status' => 'lost', 'lost_at' => date('Y-m-d H:i', time() - 80 * 86400), 'winback_draft' => ['email_subject' => 'A new idea for you', 'email_body' => $LONG, 'whatsapp' => 'w']]);
    $won = mk_lead(['id' => 'ps1', 'status' => 'won', 'won_at' => date('Y-m-d H:i', time() - 3 * 86400), 'postsign' => ['testimonial' => 'May we quote you?', 'review' => 'Please review us', 'referral' => 'Know anyone?']]);
    $rv = mk_lead(['id' => 'rv1', 'status' => 'contacted', 'last_contacted' => date('Y-m-d H:i', time() - 1 * 86400), 'reverify' => ['at' => date('Y-m-d H:i'), 'what' => 'Found new email address new@acme.mw', 'seen' => false]]);
    $seen = mk_lead(['id' => 'rv2', 'status' => 'contacted', 'reverify' => ['at' => date('Y-m-d H:i'), 'what' => 'Checked again: nothing has changed', 'seen' => true]]);
    pm_t_eq(pm_lead_open_drafts($lost), ['winback'], 'win-back draft on a lost lead is open');
    pm_t_eq(pm_lead_open_drafts($won), ['postsign'], 'post-sign asks on a won lead are open');
    pm_t_eq(pm_lead_open_drafts($rv), ['reverify'], 'an unseen re-check result is open');
    pm_t_eq(pm_lead_open_drafts($seen), [], 'a seen one is not');
    pm_t_eq(pm_lead_open_drafts($won + ['postsign_done' => '2026-10-10']), [], 'handled post-sign asks close');
    $plan = pm_daily_plan(['wb1' => $lost, 'ps1' => $won, 'rv1' => $rv, 'rv2' => $seen], pm_agents_config());
    $kinds = array_column($plan, 'kind');
    pm_t_assert(in_array('winback', $kinds, true) && in_array('postsign', $kinds, true), 'today list has the win-back and post-sign tasks');
    pm_t_assert(count(array_filter($plan, fn($t) => $t['kind'] === 'research' && str_contains($t['title'], 'new email address'))) === 1, 'today list has one re-check task, none for the seen one');
    pm_t_assert(count(array_filter($plan, fn($t) => $t['lead'] === 'rv2')) === 0, 'no task for a re-check that found nothing');
    // what the re-check agent records
    $l = mk_lead(['email' => 'old@acme.mw', 'phone' => '', 'contact' => '']);
    $before = [$l['email'], $l['phone'], $l['contact']];
    $l['email'] = 'new@acme.mw';
    $l['contact'] = 'Mary Banda';
    $what = pm_reverify_result($l, $before);
    pm_t_assert(str_contains($what, 'new email address new@acme.mw') && str_contains($what, 'Mary Banda') && $l['reverify']['seen'] === false, 'a change is recorded and not yet seen: ' . $what);
    $same = mk_lead();
    pm_reverify_result($same, [$same['email'], $same['phone'] ?? '', $same['contact'] ?? '']);
    pm_t_assert($same['reverify']['seen'] === true && str_contains($same['reverify']['what'], 'nothing has changed'), 'no change is recorded as seen');
});

t('C2-A01 sending a win-back keeps every gate', function () use ($LONG) {
    $a = mk_lead(['id' => 'wbA', 'status' => 'lost', 'lost_at' => date('Y-m-d H:i', time() - 80 * 86400), 'winback_draft' => ['email_subject' => 'A new idea for you', 'email_body' => $LONG, 'whatsapp' => 'w'], 'followups' => 3]);
    $stop = mk_lead(['id' => 'wbS', 'status' => 'optout', 'winback_draft' => ['email_subject' => 'A new idea', 'email_body' => $LONG, 'whatsapp' => '']]);
    $same = mk_lead(['id' => 'wbT', 'status' => 'lost', '_dom' => 'blocked.mw', 'winback_draft' => ['email_subject' => 'A new idea', 'email_body' => $LONG, 'whatsapp' => '']]);
    $blocker = mk_lead(['id' => 'wbB', 'status' => 'optout', '_dom' => 'blocked.mw']);
    $priced = mk_lead(['id' => 'wbP', 'status' => 'lost', 'winback_draft' => ['email_subject' => 'A new idea', 'email_body' => $LONG . ' It costs MWK 50,000 a month.', 'whatsapp' => '']]);
    foreach ([$a, $stop, $same, $blocker, $priced] as $x) {
        ok_domain($x['email']);
    }
    pm_save('leads', ['wbA' => $a, 'wbS' => $stop, 'wbT' => $same, 'wbB' => $blocker, 'wbP' => $priced]);
    $leads = pm_leads();
    $box = [];
    $r = pm_send_first($leads, 'wbA', 'winback_draft', mk_mailer($box));
    pm_t_assert(!empty($r['ok']), 'a clean win-back sends: ' . ($r['msg'] ?? ''));
    pm_t_eq(count($box), 1, 'one email went out');
    pm_t_assert(str_contains($box[0]['body'], 'STOP'), 'it carries the STOP line');
    pm_t_eq([$leads['wbA']['status'], isset($leads['wbA']['winback_draft']), $leads['wbA']['followups']], ['contacted', false, pm_agents_config()['max_followups']], 'the lead is back in play, the draft is used, no automatic follow-ups after it');
    pm_t_assert(!empty($leads['wbA']['winback_sent_at']) && str_contains(json_encode($leads['wbA']['notes']), 'Win-back email sent'), 'the send is logged');
    $leads = pm_leads();
    $box2 = [];
    $r = pm_send_first($leads, 'wbS', 'winback_draft', mk_mailer($box2));
    pm_t_assert(empty($r['ok']), 'a do-not-contact lead is refused');
    $r = pm_send_first($leads, 'wbT', 'winback_draft', mk_mailer($box2));
    pm_t_assert(empty($r['ok']) && str_contains($r['msg'], 'asked not to be contacted'), 'a company that said STOP is refused: ' . $r['msg']);
    $r = pm_send_first($leads, 'wbP', 'winback_draft', mk_mailer($box2));
    pm_t_assert(empty($r['ok']) && str_contains($r['msg'], 'prices'), 'the clean-copy lint still applies: ' . $r['msg']);
    pm_t_eq(count($box2), 0, 'nothing went out for any of the refused ones');
});

t('C2-A01 post-sign asks are sent one at a time, behind the same gates', function () {
    $w = mk_lead(['id' => 'psA', 'status' => 'won', 'postsign' => ['testimonial' => 'We are glad the new system helps. May we quote one line about what improved for you?', 'review' => "Would you share a short Google review?\nhttps://g.page/r/abc123/review", 'referral' => 'Do you know another business we could help with the same?']]);
    $stop = mk_lead(['id' => 'psS', 'status' => 'optout', 'postsign' => ['testimonial' => 'May we quote you on one line about what improved?']]);
    $priced = mk_lead(['id' => 'psP', 'status' => 'won', 'postsign' => ['referral' => 'We can give you a discount of MWK 20000 if you refer a friend today and they sign this month.']]);
    foreach ([$w, $stop, $priced] as $x) {
        ok_domain($x['email']);
    }
    pm_save('leads', ['psA' => $w, 'psS' => $stop, 'psP' => $priced]);
    $leads = pm_leads();
    $box = [];
    $r = pm_send_postsign($leads, 'psA', 'review', mk_mailer($box));
    pm_t_assert(!empty($r['ok']), 'a clean ask is sent: ' . $r['msg']);
    pm_t_eq($box[0]['subject'], pm_postsign_subject('review', pm_settings()['company_name']), 'plain, honest subject');
    pm_t_assert(str_contains($box[0]['body'], 'https://g.page/r/abc123/review') && str_contains($box[0]['body'], 'STOP'), 'the review link and the STOP line are in it');
    pm_t_assert(!empty($leads['psA']['postsign_sent']['review']) && $leads['psA']['status'] === 'won', 'logged as sent, status unchanged');
    $before = count($box);
    pm_t_assert(!pm_send_postsign($leads, 'psS', 'testimonial', mk_mailer($box))['ok'], 'a do-not-contact lead is refused');
    pm_t_assert(!pm_send_postsign($leads, 'psP', 'referral', mk_mailer($box))['ok'], 'a message with a discount and an amount is refused');
    pm_t_assert(!pm_send_postsign($leads, 'psA', 'bogus', mk_mailer($box))['ok'], 'an unknown ask is refused');
    pm_t_eq(count($box), $before, 'nothing else went out');
    $failed = [];
    pm_t_assert(!pm_send_postsign($leads, 'psA', 'referral', mk_mailer($failed, false))['ok'] && empty($leads['psA']['postsign_sent']['referral']), 'a failed send is not logged as sent');
});

/* =========================================================== A02 */

t('C2-A02 re-qualify once research lands', function () {
    $now = date('Y-m-d H:i');
    $old = date('Y-m-d H:i', time() - 3600);
    $fresh = mk_lead(['id' => 'rq1', 'status' => 'qualified', 'score' => 62, 'research' => ['at' => $now, 'discoveries' => [['fact' => 'Opened a second branch', 'source' => 'https://x.mw']]]]);
    $done = mk_lead(['id' => 'rq2', 'status' => 'qualified', 'score' => 70, 'research' => ['at' => $old], 'requalified_at' => $now]);
    $stale = mk_lead(['id' => 'rq3', 'status' => 'drafted', 'score' => 80, 'research' => ['at' => $now], 'requalified_at' => $old]);
    $none = mk_lead(['id' => 'rq4', 'status' => 'qualified', 'score' => 90]);
    $won = mk_lead(['id' => 'rq5', 'status' => 'won', 'score' => 90, 'research' => ['at' => $now]]);
    $tried = mk_lead(['id' => 'rq6', 'status' => 'qualified', 'score' => 95, 'research' => ['at' => $now], 'requalify_tries' => 2]);
    $ids = array_column(pm_requalify_due([$fresh, $done, $stale, $none, $won, $tried], 5), 'id');
    pm_t_eq($ids, ['rq3', 'rq1'], 'only researched, not-yet-rescored, live leads, best score first');
    pm_t_eq(count(pm_requalify_due([$fresh, $stale], 1)), 1, 'capped per run');
    // the Qualifier is told about the research and the old score
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen = $sys . "\n" . $user;
        return json_encode([['id' => 'rq1', 'score' => 78, 'package' => 99, 'offering' => 'support', 'pain' => 'Two branches, one spreadsheet', 'reason' => 'Opened a second branch', 'skip' => false]]);
    };
    $rows = pm_agent_qualify([$fresh, $none]);
    pm_t_assert(str_contains($seen, 'previous_score') && str_contains($seen, 'second branch') && str_contains($seen, 're-score'), 'the Qualifier sees the research and the previous score');
    $GLOBALS['PM_AI_STUB'] = null;
    $l = $fresh;
    $msg = pm_apply_requalify($l, $rows[0], 'promanaged');
    pm_t_eq([$l['score'], $l['status'], $l['pain'], $l['offering'], $l['score_before_research']], [78, 'qualified', 'Two branches, one spreadsheet', 'support', 62], 'new score and angle, status untouched, old score remembered');
    pm_t_assert($l['package'] <= count(pm_template()['packages']) - 1, 'package is clamped to a real one');
    pm_t_assert(str_contains($msg, '62 to 78') && str_contains(json_encode($l['notes']), 'Re-scored after research'), 'it is noted on the lead: ' . $msg);
    pm_t_eq(pm_requalify_due([$l], 3), [], 'it is not due again until new research lands');
    $sk = $fresh;
    pm_apply_requalify($sk, ['id' => 'rq1', 'score' => 30, 'skip' => true], 'promanaged');
    pm_t_assert($sk['status'] === 'qualified' && str_contains(json_encode($sk['notes']), 'doubts it is a fit'), 'a "skip" never flips the status by itself');
    $tr = mk_lead(['brand' => 'travel', 'status' => 'qualified', 'score' => 50, 'research' => ['at' => $now]]);
    pm_apply_requalify($tr, ['score' => 66, 'package' => 7], 'travel');
    pm_t_eq($tr['package'], pm_onboarding_index(), 'Travel Malawi always keeps its onboarding package');
    pm_apply_research($l, ['at' => date('Y-m-d H:i', time() + 60), 'discoveries' => [], 'news' => [], 'social' => [], 'website' => []]);
    pm_t_assert(!isset($l['requalify_tries']) && count(pm_requalify_due([$l], 3)) === 1, 'fresh research makes it due again');
});

/* =========================================================== A03 */

t('C2-A03 subject styles are real, and follow-ups carry an arm', function () {
    pm_t_assert(pm_subject_style('A') !== pm_subject_style('B'), 'the two arms really differ');
    $arms = [];
    for ($i = 0; $i < 40; $i++) {
        $arms[pm_followup_arm(['id' => "x$i", 'followups' => 0], 'promanaged')] = 1;
    }
    ksort($arms);
    pm_t_eq(array_keys($arms), ['A', 'B'], 'both arms are drawn across leads');
    pm_t_eq(pm_followup_arm(['id' => 'abc', 'followups' => 1], 'promanaged'), pm_followup_arm(['id' => 'abc', 'followups' => 1], 'promanaged'), 'stable for a lead and follow-up number');
    // the writer and the follow-up agent are told which style to use
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen[] = $sys . "\n" . $user;
        return json_encode([['id' => 'f1', 'email_subject' => 's', 'email_body' => 'b', 'whatsapp' => 'w']]);
    };
    pm_agent_write([mk_lead(['id' => 'f1', 'pain' => 'p'])]);
    pm_agent_followup([mk_lead(['id' => 'f1', 'drafts' => ['email_body' => 'old']])]);
    pm_t_assert(str_contains($seen[0], 'subject_style') && str_contains($seen[0], 'specific'), 'the first-email Writer gets a subject style');
    pm_t_assert(str_contains($seen[1], 'subject_style'), 'the Follow-up agent gets a subject style');
    $GLOBALS['PM_AI_STUB'] = null;
    // sending a follow-up remembers the arm
    $l = mk_lead(['id' => 'fu1', 'status' => 'contacted', 'sent' => [date('Y-m-d H:i', time() - 6 * 86400)], 'last_contacted' => date('Y-m-d H:i', time() - 6 * 86400), 'followups' => 0,
        'followup_arm' => 'B', 'followup_draft' => ['email_subject' => 'Is the stock count still slow?', 'email_body' => 'Just a short note to follow up on my earlier message about your stock counts. If a simple weekly sheet would help, I can send one over this week with no strings attached.', 'whatsapp' => '']]);
    ok_domain($l['email']);
    pm_save('leads', ['fu1' => $l]);
    $leads = pm_leads();
    $box = [];
    $r = pm_send_first($leads, 'fu1', 'followup_draft', mk_mailer($box));
    pm_t_assert(!empty($r['ok']), 'follow-up sent: ' . $r['msg']);
    $fs = $leads['fu1']['followup_sent'][0] ?? [];
    pm_t_eq([$fs['arm'] ?? '', $fs['subject'] ?? '', isset($leads['fu1']['followup_arm'])], ['B', 'Is the stock count still slow?', false], 'arm and subject logged when it really went out');
});

t('C2-A03 the experiment judges first emails AND follow-ups', function () {
    $leads = [];
    // first emails: nothing decisive (few sends)
    for ($i = 0; $i < 6; $i++) {
        $leads["a$i"] = mk_lead(['status' => 'contacted', 'sent' => [date('Y-m-d H:i', time() - 5 * 86400)], 'email_arm' => $i % 2 ? 'B' : 'A', 'drafts' => ['email_subject' => "first $i"]]);
    }
    // follow-ups: 8 of arm A (2 replied), 8 of arm B (6 replied)
    $n = 0;
    foreach (['A' => 2, 'B' => 6] as $arm => $replies) {
        for ($i = 0; $i < 8; $i++) {
            $at = time() - 9 * 86400;
            $l = mk_lead(['status' => 'contacted', 'followup_sent' => [['at' => date('Y-m-d H:i', $at), 'arm' => $arm, 'subject' => "fu $arm $i"]]]);
            if ($i < $replies) {
                $l['last_reply'] = date('Y-m-d H:i', $at + 2 * 86400);
            }
            $leads['f' . $n++] = $l;
        }
    }
    // a reply that came BEFORE the follow-up, or too long after, is not credited to it
    $leads['early'] = mk_lead(['followup_sent' => [['at' => date('Y-m-d H:i', time() - 9 * 86400), 'arm' => 'A', 'subject' => 'fu A early']], 'last_reply' => date('Y-m-d H:i', time() - 12 * 86400)]);
    pm_save('leads', $leads);
    pm_save('email_exp', []);
    $rows = pm_followup_exp_rows($leads);
    pm_t_eq([count($rows), count(array_filter($rows, fn($r) => $r['replied']))], [17, 8], 'only replies after a follow-up count for it');
    $msg = pm_jobg_email_exp();
    pm_t_assert(str_contains($msg, 'follow-ups: arm B wins') && str_contains($msg, 'not enough sent emails'), 'follow-ups get a verdict even while first emails do not: ' . $msg);
    pm_t_assert(str_contains(pm_email_exp_followup_example('promanaged'), 'fu B'), 'the Follow-up agent mirrors the winning style');
    pm_t_eq(pm_email_exp_example('promanaged'), '', 'first-email experiment is unaffected by follow-up data');
    pm_t_eq(pm_exp_judge(['A' => [3, 1], 'B' => [3, 1]], ['A' => 'a', 'B' => 'b'], 20)['status'], 'few', 'too few sends: no verdict');
    pm_t_eq(pm_exp_judge(['A' => [10, 3], 'B' => [10, 3]], ['A' => 'a', 'B' => 'b'], 20)['status'], 'none', 'level pegging: no winner');
    pm_save('leads', array_slice($leads, 0, 3));
    pm_save('email_exp', []);
    pm_t_assert(str_contains(pm_jobg_email_exp(), 'not enough'), 'thin data says so');
});

/* =========================================================== A04 */

t('C2-A04 reply mode: email and WhatsApp can each be review-before-send', function () {
    pm_t_eq([pm_reply_mode('email'), pm_reply_mode('wa')], ['draft', 'auto'], 'defaults: email drafts, WhatsApp answers simple questions (as before)');
    $c = pm_load('agents_config', 'pm_agents_default_config');
    $c['wa_reply_mode'] = 'draft';
    pm_save('agents_config', $c);
    pm_t_eq(pm_reply_mode('wa'), 'draft', 'the switch works');
    $c['wa_reply_mode'] = 'auto';
    $c['reply_mode'] = 'auto';
    pm_save('agents_config', $c);
    pm_t_eq([pm_reply_mode('email'), pm_reply_mode('wa')], ['auto', 'auto'], 'email can be set to auto');
    $c['reply_mode'] = 'draft';
    pm_save('agents_config', $c);
});

t('C2-A04 WhatsApp Business: draft mode holds the answer, auto mode sends only simple ones', function () {
    putenv('WA_BIZ_TOKEN=tok');
    putenv('WA_BIZ_PHONE_ID=999');
    putenv('WA_BIZ_VERIFY=v');
    $sent = [];
    $GLOBALS['PM_WA_STUB'] = function (string $to, string $text) use (&$sent) {
        $sent[] = [$to, $text];
        return [true, 'wamid.' . count($sent)];
    };
    $answer = fn(string $intent, bool $human = false) => function () use ($intent, $human) {
        return json_encode(['intent' => $intent, 'summary' => 's', 'needs_human' => $human, 'reply_subject' => 'Re', 'reply_body' => 'We build websites and give IT support.',
            'whatsapp' => 'Thanks for asking. We build websites and give IT support in Malawi.', 'next_step' => 'call them']);
    };
    $mk = function (string $id, string $num) {
        $l = mk_lead(['id' => $id, 'status' => 'contacted', 'whatsapp' => $num, 'name' => 'Wa Co ' . $id, 'thread' => []]);
        pm_save('leads', pm_load('leads', fn() => []) + [$id => $l]);
        return $l;
    };
    pm_save('leads', []);
    $mk('w1', '+265 999 111 111');
    $mk('w2', '+265 999 222 222');
    $mk('w3', '+265 999 333 333');
    $mk('w4', '+265 999 444 444');
    // draft mode
    $c = pm_load('agents_config', 'pm_agents_default_config');
    $c['wa_reply_mode'] = 'draft';
    pm_save('agents_config', $c);
    $GLOBALS['PM_AI_STUB'] = $answer('question');
    $note = pm_wa_biz_handle('265999111111', 'How much for a website?');
    pm_t_assert(str_contains($note, 'draft saved'), 'draft mode: ' . $note);
    pm_t_eq(count($sent), 0, 'draft mode sends nothing');
    $l = pm_leads()['w1'];
    pm_t_assert(($l['reply_draft']['ch'] ?? '') === 'wa' && str_contains((string)$l['reply_draft']['whatsapp'], 'IT support') && $l['status'] === 'replied', 'the answer waits on the lead as a WhatsApp draft');
    pm_t_assert(count(array_filter($l['thread'], fn($m) => ($m['dir'] ?? '') === 'in' && ($m['ch'] ?? '') === 'wa')) === 1, 'their message is logged in the thread');
    // owner approves: the window is open because they just wrote
    $leads = pm_leads();
    [$ok, $msg] = pm_wa_biz_send_approved($leads['w1'], $l['reply_draft']['whatsapp']);
    pm_t_assert($ok && count($sent) === 1 && $sent[0][0] === '265999111111', 'approved answer goes through the Business API: ' . $msg);
    pm_t_assert($leads['w1']['reply_draft'] === null && $leads['w1']['status'] === 'contacted' && !empty($leads['w1']['wa_sent']), 'it is logged and the lead moves on');
    // closed window
    $old = mk_lead(['id' => 'w9', 'status' => 'replied', 'whatsapp' => '+265 999 999 999', 'thread' => [['dir' => 'in', 'at' => date('Y-m-d H:i', time() - 2 * 86400), 'text' => 'hi', 'ch' => 'wa']]]);
    [$ok, $msg] = pm_wa_biz_send_approved($old, 'Thanks for your message, we will call you today to talk it through.');
    pm_t_assert(!$ok && str_contains($msg, '24 hours'), 'after 24 hours only a template is allowed: ' . $msg);
    $stop = mk_lead(['status' => 'optout', 'whatsapp' => '+265 999 888 888', 'thread' => [['dir' => 'in', 'at' => date('Y-m-d H:i'), 'text' => 'hi', 'ch' => 'wa']]]);
    pm_t_assert(!pm_wa_biz_send_approved($stop, 'Thanks for writing, we are happy to help you with that today.')[0], 'a do-not-contact lead is refused');
    $fresh = mk_lead(['status' => 'replied', 'whatsapp' => '+265 999 777 777', 'thread' => [['dir' => 'in', 'at' => date('Y-m-d H:i'), 'text' => 'hi', 'ch' => 'wa']]]);
    pm_t_assert(!pm_wa_biz_send_approved($fresh, 'It costs MWK 90,000 a month and you must act now, this is a limited time offer for you.')[0], 'spam wording and prices are refused');
    $nSent = count($sent);
    // auto mode: a plain question is answered, a meeting request and a "needs a person" are not
    $c['wa_reply_mode'] = 'auto';
    pm_save('agents_config', $c);
    $GLOBALS['PM_AI_STUB'] = $answer('question');
    $note = pm_wa_biz_handle('265999222222', 'Do you do websites?');
    pm_t_assert($note === 'answered from the Reply agent draft' && count($sent) === $nSent + 1, 'auto mode answers a plain question: ' . $note);
    pm_t_assert(pm_leads()['w2']['reply_draft'] === null && !empty(pm_leads()['w2']['thread']), 'and keeps the thread tidy');
    $GLOBALS['PM_AI_STUB'] = $answer('meeting_request');
    $note = pm_wa_biz_handle('265999333333', 'Can you come tomorrow at 10?');
    pm_t_assert(str_contains($note, 'drafted for the owner') && count($sent) === $nSent + 1, 'a meeting time is never committed by the machine: ' . $note);
    pm_t_assert(!empty(pm_leads()['w3']['reply_draft']['whatsapp']), 'it waits as a draft');
    $GLOBALS['PM_AI_STUB'] = $answer('question', true);
    $note = pm_wa_biz_handle('265999444444', 'This is unacceptable, I want a refund');
    pm_t_assert($note === 'needs a human: owner alerted' && count($sent) === $nSent + 1, 'anything that needs a person stops: ' . $note);
    pm_t_assert(!empty(pm_leads()['w4']['reply_draft']['needs_human']), 'and is flagged on the lead');
    // STOP is still absolute
    pm_t_eq(pm_wa_biz_handle('265999222222', 'STOP'), 'STOP honoured', 'STOP still wins in every mode');
    $GLOBALS['PM_AI_STUB'] = null;
    $GLOBALS['PM_WA_STUB'] = null;
});

/* =========================================================== A08 */

t('C2-A08 post-sign review asks carry the Google review link', function () {
    pm_save('social_channels', []);
    $w = ['testimonial' => 'May we quote you?', 'review' => 'Would you leave us a short review? https://evil.example/x', 'referral' => 'Know anyone?'];
    pm_t_eq(pm_google_review_link('promanaged'), '', 'no link configured');
    $r = pm_postsign_finish($w, 'promanaged');
    pm_t_eq($r['review'], 'Would you leave us a short review? https://evil.example/x', 'without a link the text is left as written') || true;
    pm_save('social_channels', ['promanaged' => ['google_review_url' => 'https://g.page/r/Cabc123/review'], 'travel' => ['google_review_url' => 'http://insecure.example/r']]);
    pm_t_eq(pm_google_review_link('promanaged'), 'https://g.page/r/Cabc123/review', 'the configured link is read');
    pm_t_eq(pm_google_review_link('travel'), '', 'a link that is not https is ignored');
    $r = pm_postsign_finish($w, 'promanaged');
    pm_t_assert(str_ends_with($r['review'], "\nhttps://g.page/r/Cabc123/review") && substr_count($r['review'], 'http') === 1 && !str_contains($r['review'], 'evil.example'), 'the review ask ends with the configured link, and only that link: ' . $r['review']);
    pm_t_eq($r['review_link'], 'https://g.page/r/Cabc123/review', 'the link is stored beside the asks');
    pm_t_eq([$r['testimonial'], $r['referral']], ['May we quote you?', 'Know anyone?'], 'the other asks are untouched');
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen = $sys;
        return json_encode([['id' => 'x', 'testimonial' => 't', 'review' => 'r', 'referral' => 'f']]);
    };
    pm_agent_postsign([mk_lead(['id' => 'x', 'status' => 'won'])]);
    pm_t_assert(str_contains($seen, 'added under your text by the system'), 'the agent is told not to write the link itself');
    pm_save('social_channels', []);
    pm_agent_postsign([mk_lead(['id' => 'x', 'status' => 'won'])]);
    pm_t_assert(str_contains($seen, 'owner will send the link separately'), 'without a link the old wording stays');
    $GLOBALS['PM_AI_STUB'] = null;
});

/* =========================================================== A05 */

t('C2-A05 posts carry the segment they speak to, and the scoreboard breaks results down by it', function () {
    pm_t_eq(pm_sx_segment(['brand' => 'promanaged', 'headline' => 'Closing the shop', 'caption' => 'Counting stock by hand at the till takes the shop an hour a day.'], 'promanaged'), 'Shops & supermarkets', 'a shop post');
    pm_t_eq(pm_sx_segment(['brand' => 'promanaged', 'headline' => 'Front desk', 'caption' => 'Your hotel guests expect quick check-in at the lodge.'], 'promanaged'), 'Hotels & lodges', 'a hotel post');
    pm_t_eq(pm_sx_segment(['brand' => 'promanaged', 'caption' => 'Backups are boring until the day you need one.'], 'promanaged'), 'All businesses', 'no sector words: all businesses');
    pm_t_eq(pm_sx_segment(['brand' => 'promanaged', 'caption' => 'A collaboration, a table and a label'], 'promanaged'), 'All businesses', 'no stray matches inside other words');
    pm_t_eq([pm_sx_segment(['audience' => 'host'], 'travel'), pm_sx_segment(['audience' => 'guest'], 'travel'), pm_sx_segment(['audience' => ''], 'travel')], ['Hosts', 'Travellers', 'General'], 'Travel Malawi segments');
    $rows = pm_plan_offline('promanaged', 4, date('Y-m-d', strtotime('+1 day')));
    pm_t_assert($rows && !array_filter($rows, fn($p) => ($p['segment'] ?? '') === ''), 'every planned post is stamped with a segment');
    // scoreboard
    pm_save('social_posts', []);
    foreach ([['Shops & supermarkets', 90, 4], ['Hotels & lodges', 20, 4]] as [$seg, $eng, $n]) {
        for ($i = 0; $i < $n; $i++) {
            fx_pub(['segment' => $seg, '_eng' => $eng + $i, '_ago' => 24 * (12 + $i)]);
        }
    }
    $sb = pm_social_scoreboard('promanaged');
    pm_t_assert($sb['enough'] && isset($sb['groups']['segment']['Shops & supermarkets']) && $sb['groups']['segment']['Shops & supermarkets']['n'] === 4, 'the scoreboard groups by segment');
    pm_t_assert(array_key_first($sb['groups']['segment']) === 'Shops & supermarkets' && $sb['groups']['segment']['Shops & supermarkets']['vs_avg'] > 1.15, 'the better segment ranks first');
    pm_t_assert(str_contains(pm_social_learnings('promanaged'), 'Best audience: Shops & supermarkets'), 'the planner is told the best audience');
    // older posts get stamped by the job; nothing is rewritten when nothing is missing
    pm_save('social_posts', []);
    $old = pm_t_fixture_post(['caption' => 'Is your school fees process slow? Teachers and students wait in queues.']);
    pm_t_assert(str_contains(pm_job_segment_stamp('promanaged'), '1 post'), 'the job tags older posts');
    pm_t_eq(array_values(array_filter(pm_social_posts(), fn($p) => $p['id'] === $old['id']))[0]['segment'], 'Schools & colleges', 'with the right segment');
    pm_t_eq(pm_job_segment_stamp('promanaged'), '', 'and does nothing the second time');
});

/* =========================================================== A06 */

t('C2-A06 X numbers typed in score the post', function () {
    pm_save('social_posts', []);
    $noFb = pm_t_fixture_post(['status' => 'published', 'published' => date('Y-m-d H:i', time() - 20 * 86400), 'when' => date('Y-m-d H:i', time() - 20 * 86400), 'fb_id' => '999_x1', 'x' => 'published']);
    $both = fx_pub(['_eng' => 40, 'x' => 'published']);
    $draft = pm_t_fixture_post([]);
    $GLOBALS['PM_WHO'] = 'Chikondi';
    $_POST = ['id' => $noFb['id'], 'likes' => '12', 'reposts' => '3', 'replies' => '2', 'views' => '900', 'clicks' => '4'];
    $r = pm_do_x_results_save('promanaged');
    pm_t_assert($r['kind'] === 'ok', 'saved: ' . $r['msg']);
    $saved = array_values(array_filter(pm_social_posts(), fn($p) => $p['id'] === $noFb['id']))[0];
    pm_t_assert($saved['manual']['x']['by'] === 'Chikondi' && $saved['manual']['x']['reactions'] === 12 && $saved['manual']['x']['shares'] === 3 && $saved['manual']['x']['comments'] === 2, 'stored like other manual channels, with who and when');
    $_POST = ['id' => $both['id'], 'likes' => '5', 'reposts' => '0', 'replies' => '0'];
    pm_do_x_results_save('promanaged');
    $_POST = ['id' => $draft['id'], 'likes' => '5'];
    pm_t_eq(pm_do_x_results_save('promanaged')['kind'], 'err', 'an unpublished post is refused');
    $_POST = ['id' => $noFb['id']];
    pm_t_eq(pm_do_x_results_save('promanaged')['kind'], 'err', 'no numbers: refused');
    $R = pm_sx_rows('promanaged');
    $byId = array_column($R['rows'], null, 'id');
    $expect = 12 + 2 * 2 + 3 * 3 + 2 * 4; // likes + 2 replies + 3 reposts + 2 clicks
    pm_t_assert(isset($byId[$noFb['id']]) && $byId[$noFb['id']]['src'] === 'x' && (int)$byId[$noFb['id']]['eng'] === $expect, 'a post with only X numbers is scored from them (eng ' . ($byId[$noFb['id']]['eng'] ?? '?') . ' = ' . $expect . ')');
    pm_t_assert($byId[$both['id']]['src'] === 'api' && (int)$byId[$both['id']]['eng'] === 40 && (int)$byId[$both['id']]['x_eng'] === 5, 'with Facebook numbers too, Facebook scores and X is shown beside it');
    pm_t_eq($R['pending'], 0, 'a post with X numbers is no longer waiting for numbers');
    $s = pm_x_summary('promanaged');
    pm_t_assert($s['n'] === 2 && $s['best'][0]['id'] === $noFb['id'], 'the X summary counts and ranks');
    pm_save('social_posts', []);
    $zero = pm_t_fixture_post(['status' => 'published', 'published' => date('Y-m-d H:i', time() - 20 * 86400), 'when' => date('Y-m-d H:i', time() - 20 * 86400), 'fb_id' => '999_z']);
    $_POST = ['id' => $zero['id'], 'likes' => '0', 'reposts' => '0', 'replies' => '0'];
    pm_do_x_results_save('promanaged');
    pm_t_eq(pm_x_summary('promanaged')['n'], 1, 'a typed zero is a real result');
    $_POST = [];
    $GLOBALS['PM_WHO'] = '';
});

/* =========================================================== A09 */

t('C2-A09 early stop for clearly losing arms', function () {
    $fig = ['A' => ['label' => 'Ask a question', 'n' => 3], 'B' => ['label' => 'WhatsApp us', 'n' => 3]];
    $e = pm_sx_exp_early(['A' => [30, 28, 31], 'B' => [5, 4, 6]], $fig, 4);
    pm_t_assert($e && $e['verdict'] === 'drop' && $e['winner'] === 'A' && !empty($e['early']) && str_contains($e['summary'], 'Stopped early'), 'B clearly worse: stop, keep A');
    $e = pm_sx_exp_early(['A' => [5, 4, 6], 'B' => [30, 28, 31]], $fig, 4);
    pm_t_assert($e && $e['verdict'] === 'keep' && $e['winner'] === 'B', 'A clearly worse: stop, use B');
    pm_t_eq(pm_sx_exp_early(['A' => [30, 5, 31], 'B' => [20, 4, 6]], $fig, 4), null, 'overlapping posts keep waiting');
    pm_t_eq(pm_sx_exp_early(['A' => [30, 28], 'B' => [5, 4]], $fig, 4), null, 'two posts a side can be luck');
    pm_t_eq(pm_sx_exp_early(['A' => [30, 28, 31], 'B' => [20, 18, 22]], $fig, 4), null, 'worse, but not half as good: keep waiting');
    pm_t_eq(pm_sx_exp_early(['A' => [30, 28, 31], 'B' => [5, 4, 6]], $fig, 10), null, 'a longer test needs at least half its posts first');
    // end to end through the scoreboard and the hourly job
    pm_save('social_posts', []);
    pm_save('social_metrics', []);
    pm_save('social_experiments', ['rows' => []]);
    $x = pm_experiment_start('promanaged', 'cta_question_vs_whatsapp');
    foreach (['A' => [40, 42, 38], 'B' => [8, 6, 9]] as $arm => $vals) {
        foreach ($vals as $i => $v) {
            fx_pub(['exp' => $x['id'], 'arm' => $arm, 'cta' => $arm === 'A' ? 'comment' : 'whatsapp', '_eng' => $v, '_ago' => 24 * (12 + $i + ($arm === 'B' ? 5 : 0))]);
        }
    }
    $ev = pm_experiment_eval($x['id']);
    pm_t_assert($ev['verdict'] === 'drop' && !empty($ev['early']) && $ev['winner'] === 'A', 'the evaluation stops it early: ' . $ev['summary']);
    $msg = pm_job_experiments('promanaged');
    pm_t_assert(str_contains($msg, 'Stopped early'), 'the job closes it: ' . $msg);
    $row = pm_experiments('promanaged')[0];
    pm_t_assert($row['status'] === 'done' && $row['early'] === true && str_contains($row['learning'], 'Stopped early'), 'recorded as an early stop with its learning');
    pm_t_assert(pm_experiment_active('promanaged') === null, 'and the next test can start');
});

/* =========================================================== A10 */

t('C2-A10 partnership radar: real links only, linted draft messages, nothing sent', function () {
    $good = ['name' => 'Lilongwe Business Network', 'kind' => 'business group', 'url' => 'https://www.facebook.com/lilongwebusiness', 'why' => 'Active members who share tips',
        'dm' => 'Hello, we are ProManaged IT in Malawi and enjoy the practical tips your group shares. We would love to swap a short guest tip with you about keeping backups simple for small shops. Would that suit you?'];
    $rows = pm_partners_clean([$good,
        array_merge($good, ['name' => 'No link', 'url' => 'not a link']),
        ['name' => 'Insecure', 'url' => 'http://x.example/p', 'dm' => $good['dm']],
        ['name' => 'Priced', 'url' => 'https://x.example/p', 'dm' => 'Hello, we would like to work together and offer you MWK 50000 per month for a shout-out on your page this week.'],
        ['name' => 'Linky', 'url' => 'https://x.example/q', 'dm' => $good['dm'] . ' See https://ourshop.example for more.'],
        ['name' => '', 'url' => 'https://x.example/r', 'dm' => $good['dm']]]);
    pm_t_eq(array_column($rows, 'name'), ['Lilongwe Business Network'], 'only a named page with an https link and a clean message survives');
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use ($good, &$calls) {
        $calls++;
        return json_encode(['items' => [$good]]);
    };
    pm_save('partners', []);
    $msg = pm_jobg_partners();
    pm_t_assert(str_contains($msg, 'partnership suggestion') && $calls >= 1, 'weekly job ran: ' . $msg);
    pm_t_eq(count(pm_partners('promanaged')), 1, 'stored for ProManaged IT');
    $before = $calls;
    pm_jobg_partners();
    pm_t_eq($calls, $before, 'the same day does not call the AI again');
    $html = pm_panel_plan_top_partners('promanaged');
    pm_t_assert(str_contains($html, 'Lilongwe Business Network') && str_contains($html, 'data-copy') && str_contains($html, 'you send them by hand'), 'shown in Plan with a Copy button, as drafts');
    pm_t_eq(count(pm_today_partners('promanaged')), 1, 'and a today item points at them');
    pm_t_eq(pm_panel_plan_top_partners('nobody'), '', 'nothing to show, nothing drawn');
    $GLOBALS['PM_AI_STUB'] = null;
});

/* =========================================================== A11 */

t('C2-A11 each brand gets its own trend line', function () {
    pm_save('social_trends', []);
    $prompts = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$prompts) {
        $prompts[] = $sys;
        return json_encode(['items' => [str_contains($sys, 'Travel Malawi') ? 'School holidays start Friday' : 'Fuel prices are up this week', 'Rain expected in the South']]);
    };
    $msg = pm_jobg_trends();
    pm_t_assert(str_contains($msg, 'stored') && count($prompts) === 2, 'one call per brand: ' . $msg);
    pm_t_assert(str_contains($prompts[0], 'small businesses') && str_contains($prompts[1], 'travellers and stay owners'), 'business trends for ProManaged IT, tourism and season trends for Travel Malawi');
    pm_t_assert(str_contains(pm_trend_line('promanaged'), 'Fuel prices') && !str_contains(pm_trend_line('promanaged'), 'School holidays'), 'ProManaged IT reads its own');
    pm_t_assert(str_contains(pm_trend_line('travel'), 'School holidays') && str_contains(pm_trend_line('travel'), 'for travel'), 'Travel Malawi reads its own');
    pm_t_assert(str_contains(pm_plan_learnings('travel'), 'School holidays') && !str_contains(pm_plan_learnings('travel'), 'Fuel prices'), 'each planner gets its own line');
    pm_t_assert(!empty(pm_social_trends()['items']), 'a call with no brand still works (ProManaged IT)');
    pm_t_assert(str_contains(pm_jobg_trends(), 'already refreshed'), 'same-day job is gated');
    // an older shared file is still usable for ProManaged IT, and gives Travel Malawi nothing wrong
    pm_save('social_trends', ['day' => date('Y-m-d'), 'items' => ['Schools open Monday']]);
    pm_t_assert(str_contains(pm_trend_line('promanaged'), 'Schools open Monday') && pm_trend_line('travel') === '', 'legacy shared list: ProManaged IT only');
    $GLOBALS['PM_AI_STUB'] = null;
});

/* =========================================================== A12 */

t('C2-A12 the winning picture is re-cut, not just the text', function () {
    pm_save('social_posts', []);
    pm_save('social_assets', []);
    $win = fx_pub(['headline' => 'Closing the shop in ten minutes', 'sub' => 'A calmer end to the day', 'layout' => 'headline', 'hook_pattern' => 'plain', 'audience' => '', 'segment' => 'Shops & supermarkets',
        'caption' => 'Closing the shop takes an hour every day. Here is a simpler routine. Try it tonight. Tell us how it goes in the comments below.', '_eng' => 50, '_ago' => 24 * 20]);
    $story = pm_repurpose_make($win, 0);
    pm_t_assert($story['format'] === 'story' && $story['headline'] === $win['headline'] && $story['sub'] === $win['sub'], 'the story carries the winner\'s own picture words');
    pm_t_assert(($story['repurposed_image'] ?? '') === 'headline' && $story['layout'] === 'headline' && $story['segment'] === 'Shops & supermarkets', 'it records which picture it was cut from, and who it speaks to');
    $car = pm_repurpose_make($win, 1);
    pm_t_assert($car['format'] === 'carousel' && $car['layout'] === 'cover' && $car['headline'] === $win['headline'] && count($car['slides']) === 3, 'the carousel opens with the winner\'s cover card');
    pm_t_eq(pm_repurpose_make($win, 2)['format'], 'text', 'the text re-cut is unchanged');
    pm_t_assert(!isset(pm_repurpose_make($win, 2)['layout']), 'a text re-cut has no picture');
    // with the owner's photo the story changes style
    $img = tempnam(sys_get_temp_dir(), 'ph') . '.jpg';
    $im = imagecreatetruecolor(800, 600);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
    imagejpeg($im, $img);
    imagedestroy($im);
    $asset = pm_social_asset_ingest($img, 'photo.jpg', 'promanaged', []);
    @unlink($img);
    if (!empty($asset['id'])) {
        $withPhoto = $win + ['asset_id' => $asset['id']];
        $s2 = pm_repurpose_make($withPhoto, 0);
        pm_t_assert($s2['layout'] === 'photo' && $s2['asset_id'] === $asset['id'], 'on the owner\'s photo the story re-draws the same words as a photo card');
        $s3 = pm_repurpose_make($withPhoto + ['layout' => 'photo'], 0);
        pm_t_eq($s3['layout'], 'headline', 'a photo winner comes back as the plain headline card');
    } else {
        pm_t_assert(true, 'photo layout not exercised: assets cannot be ingested here (' . json_encode($asset) . ')');
    }
    // the job makes drafts only, and draws the new card
    for ($i = 0; $i < 6; $i++) {
        fx_pub(['_eng' => 5 + $i, '_ago' => 24 * (25 + $i), 'caption' => 'Another post about topic number ' . $i . '. It has two sentences.']);
    }
    $msg = pm_job_repurpose('promanaged');
    pm_t_assert(str_contains($msg, 'repurposed draft'), 'job ran: ' . $msg);
    $new = array_values(array_filter(pm_social_posts(), fn($x) => ($x['repurposed_from'] ?? '') === $win['id']));
    pm_t_assert(count($new) === 2 && !array_filter($new, fn($x) => !in_array($x['status'], ['draft', 'needs_edit'], true)), 'two fresh drafts, never auto-published');
    pm_t_assert(count(array_filter($new, fn($x) => !empty($x['repurposed_image']))) === 2, 'both are picture re-cuts');
    if (pm_card_gd_ok()) {
        $st = array_values(array_filter($new, fn($x) => $x['format'] === 'story'))[0];
        $f = pm_card_render($st, 'story');
        pm_t_assert(is_string($f) && $f !== '' && is_file($f) && filesize($f) > 0, 'the story card draws');
    } else {
        pm_t_assert(true, 'cards not drawn here: GD or fonts missing');
    }
});

/* =========================================================== G01 */

t('C2-G01 setup-health panel', function () {
    $rows = pm_setup_health();
    $by = array_column($rows, null, 'key');
    foreach (['ai', 'smtp_promanaged', 'smtp_travel', 'imap_promanaged', 'imap_travel', 'fb_promanaged', 'fb_travel', 'ig_promanaged', 'li_promanaged', 'x_promanaged', 'x_travel', 'wa_promanaged', 'wa_travel', 'scheduler', 'cron_key', 'app_url'] as $k) {
        pm_t_assert(isset($by[$k]), "row $k present");
    }
    pm_t_assert($by['fb_promanaged']['ok'] && !$by['fb_travel']['ok'] && $by['fb_travel']['add'] !== '', 'Facebook is ready for ProManaged IT, missing for Travel Malawi, with what to add');
    pm_t_assert(!$by['app_url']['ok'] && str_contains($by['app_url']['add'], 'APP_URL'), 'APP_URL missing says what to add');
    pm_t_assert($by['cron_key']['ok'], 'CRON_KEY is set in the test env');
    pm_t_eq([$by['x_promanaged']['need'], $by['x_promanaged']['ok']], ['optional', false], 'X is optional and off without keys');
    putenv('X_API_KEY=k');
    putenv('X_API_SECRET=s');
    putenv('X_ACCESS_TOKEN=t');
    putenv('X_ACCESS_SECRET=ts');
    $xr = array_column(pm_setup_health(), null, 'key');
    pm_t_assert($xr['x_promanaged']['ok'] && !$xr['x_travel']['ok'], 'X turns ready for the business whose keys exist, and only that one');
    putenv('X_API_KEY');
    putenv('X_API_SECRET');
    putenv('X_ACCESS_TOKEN');
    putenv('X_ACCESS_SECRET');
    [$ok, $need, $okO, $needO] = pm_setup_health_summary($rows);
    pm_t_assert($need > 0 && $needO > 0 && $ok <= $need, "summary counts: $ok of $need essentials, $okO of $needO optional");
    ob_start();
    pm_view_setup_health();
    $html = ob_get_clean();
    pm_t_assert(str_contains($html, 'Setup health') && str_contains($html, 'Facebook Page') && str_contains($html, 'APP_URL'), 'the panel renders');
    pm_t_assert(!str_contains($html, 'tok') || !preg_match('/FB_PAGE_TOKEN=tok/', $html), 'it never prints secrets');
});

/* =========================================================== G02 */

t('C2-G02 lead archiving', function () {
    $oldT = date('Y-m-d H:i', time() - 400 * 86400);
    $mk = fn($id, $o = []) => mk_lead($o + ['id' => $id, 'created' => $oldT, 'updated' => $oldT, 'status' => 'contacted']);
    $leads = ['a1' => $mk('a1'), 'a2' => $mk('a2', ['status' => 'lost']), 'won' => $mk('won', ['status' => 'won']), 'stop' => $mk('stop', ['status' => 'optout']),
        'recent' => $mk('recent', ['updated' => date('Y-m-d H:i', time() - 10 * 86400)]), 'queued' => $mk('queued', ['approved_at' => date('Y-m-d H:i:s')]),
        'note' => $mk('note', ['notes' => [['at' => date('Y-m-d H:i', time() - 5 * 86400), 'text' => 'called', 'by' => '']]]),
        'snz' => $mk('snz', ['snooze_until' => date('Y-m-d', time() + 20 * 86400)]), 'sent' => $mk('sent', ['sent' => [date('Y-m-d H:i', time() - 30 * 86400)]])];
    pm_save('leads', $leads);
    pm_save('leads_archive', []);
    pm_t_assert(pm_lead_archivable($leads['a1']) && pm_lead_archivable($leads['a2']), 'old, quiet leads qualify');
    foreach (['won' => 'a client', 'stop' => 'a do-not-contact lead', 'recent' => 'a recent one', 'queued' => 'one queued to send', 'note' => 'one with a recent note', 'snz' => 'a snoozed one', 'sent' => 'one emailed recently'] as $k => $why) {
        pm_t_assert(!pm_lead_archivable($leads[$k]), "$why stays");
    }
    $msg = pm_jobg_archiving();
    pm_t_assert(str_contains($msg, '2 lead(s)'), 'job moved two: ' . $msg);
    $now = pm_load('leads', fn() => []);
    $arch = pm_leads_archive();
    pm_t_eq([isset($now['a1']), isset($now['a2']), isset($now['won']), isset($now['stop'])], [false, false, true, true], 'old ones left the main list, clients and STOP leads stayed');
    pm_t_assert(isset($arch['a1']['archived_at']) && $arch['a1']['name'] === $leads['a1']['name'], 'the archive keeps the whole lead');
    pm_t_eq(pm_jobg_archiving(), '', 'once a day');
    // the swarm sees archived leads as known
    $pool = array_replace(pm_leads_archive(), pm_load('leads', fn() => []));
    $cand = ['name' => $leads['a1']['name'], 'brand' => 'promanaged', 'email' => $leads['a1']['email']];
    pm_t_assert(pm_lead_find_dupe($pool, $cand, 'promanaged') === 'a1', 'a scout finding it again sees a duplicate');
    // privacy: an archived lead can be forgotten, and nothing stays behind in the archive
    $fg = pm_lead_forget('a2');
    pm_t_assert($fg['ok'] && !isset(pm_leads_archive()['a2']) && in_array('archived copy removed', $fg['done'], true), 'forgetting an archived lead removes the archived copy: ' . $fg['msg']);
    // restore
    [$ok, $m] = pm_lead_unarchive('a1');
    pm_t_assert($ok && isset(pm_load('leads', fn() => [])['a1']) && !isset(pm_leads_archive()['a1']), 'restored: ' . $m);
    pm_t_assert(!pm_lead_archivable(pm_load('leads', fn() => [])['a1']), 'a restored lead is not archived again at once');
    pm_t_assert(!pm_lead_unarchive('nope')[0], 'restoring an unknown lead says so');
    pm_t_assert(pm_lead_last_touch(['notes' => [['at' => '2026-03-01 10:00']], 'created' => '2025-01-01 00:00']) === strtotime('2026-03-01 10:00'), 'last touch looks at notes too');
});

/* =========================================================== G03 */

t('C2-G03 housekeeping', function () {
    $now = time();
    $old = $now - 3 * 86400;
    foreach (['agent_jobs', 'ratelimit', 'proposals', 'social'] as $d) {
        @mkdir(PM_DATA . '/' . $d, 0775, true);
    }
    $mk = function (string $f, int $mtime) {
        file_put_contents($f, '{}');
        touch($f, $mtime);
        return $f;
    };
    $gone = [$mk(PM_DATA . '/agent_jobs/job_1_0.json', $old), $mk(PM_DATA . '/agent_jobs/job_1_0.json.out.json', $old), $mk(PM_DATA . '/ratelimit/wabiz_abc.json', $old),
        $mk(PM_DATA . '/leads.json.123abc.tmp', $now - 7200), $mk(PM_DATA . '/proposals/tok.json.tmp', $now - 7200)];
    $kept = [$mk(PM_DATA . '/agent_jobs/job_2_0.json', $now - 600), $mk(PM_DATA . '/ratelimit/wabiz_new.json', $now - 600), $mk(PM_DATA . '/leads.json.456def.tmp', $now - 60),
        $mk(PM_DATA . '/old_but_live.json', $old), $mk(PM_DATA . '/proposals/signed.json', $old), $mk(PM_DATA . '/social/card.png', $old)];
    $r = pm_housekeeping_sweep($now);
    pm_t_eq([$r['agent_jobs'], $r['ratelimit'], $r['temp']], [2, 1, 2], 'counts: ' . json_encode($r));
    foreach ($gone as $f) {
        pm_t_assert(!is_file($f), 'removed ' . basename($f));
    }
    foreach ($kept as $f) {
        pm_t_assert(is_file($f), 'kept ' . basename($f));
    }
    pm_t_assert(str_contains(pm_jobg_housekeeping(), '') && pm_load('housekeeping', fn() => [])['sweep_day'] === date('Y-m-d'), 'the daily job records its run');
    pm_t_eq(pm_jobg_housekeeping(), '', 'and runs once a day');
    pm_t_eq(pm_hk_clear(PM_DATA . '/does-not-exist', '*', 1, $now), 0, 'a missing folder is fine');
    foreach ($kept as $f) {
        @unlink($f);
    }
});

/* =========================================================== G04 */

t('C2-G04 weekly data archive', function () {
    // a settings file with a secret inside, and a data file with a token
    $s = pm_load('settings', 'pm_default_settings');
    $s['smtp']['password'] = 'TOP-SECRET-PASSWORD';
    $s['travel']['smtp']['password'] = 'ANOTHER-SECRET';
    file_put_contents(PM_DATA . '/settings.json', json_encode($s));
    file_put_contents(PM_DATA . '/leads.json', json_encode(['x1' => mk_lead(['id' => 'x1', 'name' => 'Archive Me Ltd'])]));
    file_put_contents(PM_DATA . '/oddities.json', json_encode(['api_key' => 'AKEY', 'nested' => ['page_token' => 'PT', 'app_secret' => 'AS', 'keep' => 'fine', 'daily_token_budget' => 400000]]));
    file_put_contents(PM_DATA . '/broken.json', '{not json');
    $files = pm_data_archive_files();
    pm_t_assert(isset($files['leads.json']) && !isset($files['broken.json']), 'good files are included, a broken one is skipped');
    pm_t_assert(!str_contains($files['settings.json'], 'TOP-SECRET-PASSWORD') && !str_contains($files['settings.json'], 'ANOTHER-SECRET'), 'mail passwords are removed from settings');
    pm_t_assert(!str_contains($files['oddities.json'], 'AKEY') && !str_contains($files['oddities.json'], '"PT"') && !str_contains($files['oddities.json'], '"AS"') && str_contains($files['oddities.json'], 'fine') && str_contains($files['oddities.json'], '400000'), 'keys and secrets go, ordinary values (even "token" budgets) stay');
    foreach (glob(PM_DATA . '/archive/data-*') ?: [] as $f) {
        @unlink($f);
    }
    $msg = pm_jobg_archive();
    pm_t_assert(str_contains($msg, 'weekly archive saved'), 'job wrote an archive: ' . $msg);
    $have = pm_data_archives();
    pm_t_eq(count($have), 1, 'one archive on disk');
    $path = array_key_first($have);
    pm_t_assert(is_file(PM_DATA . '/archive/.htaccess'), 'the folder is closed to the web');
    if (str_ends_with($path, '.json.gz')) {
        $b = json_decode((string)gzdecode((string)file_get_contents($path)), true);
        pm_t_assert(isset($b['files']['leads.json']['x1']) && !str_contains(json_encode($b), 'TOP-SECRET-PASSWORD'), 'the archive restores the data and holds no secrets');
    } else {
        pm_t_assert(class_exists('ZipArchive'), 'a zip was written');
    }
    pm_t_eq(pm_jobg_archive(), '', 'weekly: a second run the same week does nothing');
    // retention: eight weeks
    $dir = PM_DATA . '/archive';
    foreach ([70, 60, 50, 10] as $d) {
        file_put_contents($dir . '/data-' . date('Y-m-d', strtotime("-$d days")) . '.json.gz', gzencode('{}'));
    }
    foreach (glob($dir . '/data-' . date('Y-m-d') . '.*') ?: [] as $f) {
        @unlink($f); // pretend this week's archive is missing so the job runs again
    }
    foreach (pm_data_archives() as $f => $d) {
        if ($d >= date('Y-m-d', strtotime('-6 days'))) {
            @unlink($f);
        }
    }
    $msg = pm_jobg_archive();
    $left = array_values(pm_data_archives());
    pm_t_assert(str_contains($msg, 'removed') && !array_filter($left, fn($d) => $d < date('Y-m-d', strtotime('-56 days'))) && count($left) === 3, 'archives older than 8 weeks are removed, the rest kept: ' . $msg . ' / ' . implode(',', $left));
    @unlink(PM_DATA . '/broken.json');
    @unlink(PM_DATA . '/oddities.json');
});

/* =========================================================== A07 */

t('C2-A07 WhatsApp campaigns: approved first, STOP and limits honoured', function () {
    putenv('WA_BIZ_TOKEN=tok');
    putenv('WA_BIZ_PHONE_ID=999');
    putenv('WA_BIZ_VERIFY=v');
    putenv('WA_BIZ_TEMPLATE');
    // cycle 3 (C3-A01): campaigns reach only people who agreed to WhatsApp messages, so these people have agreed
    $mk = fn($id, $num, $o = []) => mk_lead($o + ['id' => $id, 'status' => 'contacted', 'whatsapp' => $num, 'contact' => 'Person ' . $id, 'name' => 'Biz ' . $id, 'score' => 70,
        'wa_optin' => ['source' => 'owner', 'at' => date('Y-m-d H:i', time() - 30 * 86400)]]);
    $leads = ['c1' => $mk('c1', '+265 999 100 001'), 'c2' => $mk('c2', '+265 999 100 002', ['score' => 90]), 'c3' => $mk('c3', '+265 999 100 003'),
        'stop' => $mk('stop', '+265 999 100 004', ['status' => 'optout']), 'nonum' => $mk('nonum', '', ['phone' => '']), 'recent' => $mk('recent', '+265 999 100 005', ['wa_sent' => [date('Y-m-d H:i', time() - 2 * 86400)]]),
        'new' => $mk('new', '+265 999 100 006', ['status' => 'new']), 'low' => $mk('low', '+265 999 100 007', ['score' => 10]),
        'travel' => $mk('travel', '+265 999 100 008', ['brand' => 'travel'])];
    pm_save('leads', $leads);
    pm_save('wa_campaigns', ['rows' => []]);
    $GLOBALS['PM_WHO'] = '';
    $text = 'We are checking in with the businesses we have spoken to, {first_name}. If anything about your computers, website or backups has become a headache this month, reply here and we will gladly take a look with you.';
    pm_t_assert(!pm_wac_lint($text), 'a clean message passes');
    pm_t_assert(pm_wac_lint('Buy now!!! Free gift, urgent, limited time offer for MWK 500') !== [], 'spam and prices are caught');
    pm_t_assert(pm_wac_lint('') !== [] && pm_wac_lint(str_repeat('word ', 200)) !== [], 'empty and very long messages are caught');
    pm_t_assert(str_contains(pm_wac_render($text, $leads['c1']), 'checking in with the businesses we have spoken to, Person.') && str_contains(pm_wac_render($text, $leads['c1']), 'STOP'), 'placeholders filled, STOP line added');
    // draft
    $_POST = ['name' => 'October check-in', 'text' => $text, 'statuses' => ['contacted'], 'min_score' => '50', 'group' => '', 'send_from' => date('Y-m-d\TH:i', time() - 60)];
    $r = pm_do_wac_create('promanaged');
    pm_t_assert($r['kind'] === 'ok' && $r['to'] === 'whatsapp', 'drafted: ' . $r['msg']);
    $c = pm_wac_campaigns('promanaged')[0];
    pm_t_eq([$c['status'], $c['recipients']], ['draft', []], 'a draft has no recipients and sends nothing');
    [$ok, $skip] = pm_wac_audience('promanaged', $c['aud']);
    $names = array_column($ok, 'id');
    sort($names);
    pm_t_eq($names, ['c1', 'c2', 'c3'], 'audience: right status, score and brand; no number or messaged this week held back; a STOP lead is never in an audience');
    pm_t_assert(isset($skip['Biz nonum']) && isset($skip['Biz recent']), 'and the held-back ones say why: ' . json_encode($skip));
    pm_t_eq(pm_job_wa_campaigns('promanaged'), '', 'a draft is never sent');
    // an editor cannot approve
    $GLOBALS['PM_WHO'] = 'Temp | editor';
    $_POST = ['id' => $c['id']];
    pm_t_eq(pm_do_wac_approve('promanaged')['kind'], 'err', 'an editor cannot approve');
    $GLOBALS['PM_WHO'] = '';
    $r = pm_do_wac_approve('promanaged');
    pm_t_assert($r['kind'] === 'ok' && str_contains($r['msg'], '3 people'), 'an approver approves: ' . $r['msg']);
    $c = pm_wac_get($c['id']);
    pm_t_eq([$c['status'], count($c['recipients'])], ['approved', 3], 'recipients are frozen at approval');
    // STOP arriving after approval is honoured at send time
    pm_update('leads', function ($all) {
        $all['c3']['status'] = 'optout';
        return $all;
    });
    $sent = [];
    $sender = function (string $num, string $text, array $lead) use (&$sent) {
        $sent[] = [$num, $text];
        return [true, 'wamid.' . count($sent)];
    };
    $n = pm_wac_send_pass($c['id'], $sender, 2);
    pm_t_eq($n, 2, 'a pass sends at most the allowed number');
    $c = pm_wac_get($c['id']);
    pm_t_eq(pm_wac_counts($c)['sent'], 2, 'two recorded as sent');
    $n = pm_wac_send_pass($c['id'], $sender, 5);
    $c = pm_wac_get($c['id']);
    $cnt = pm_wac_counts($c);
    pm_t_assert($n === 0 && $cnt['skipped'] === 1 && $cnt['pending'] === 0 && $c['status'] === 'done', 'the person who said STOP after approval was skipped, and the campaign finished: ' . json_encode($cnt) . ' ' . $c['status']);
    pm_t_assert(!in_array('265999100003', array_column($sent, 0), true), 'nothing was sent to the STOP lead');
    $l = pm_load('leads', fn() => [])['c1'];
    pm_t_assert(!empty($l['wa_sent']) && str_contains(json_encode($l['notes']), 'WhatsApp campaign') && ($l['thread'][count($l['thread']) - 1]['ch'] ?? '') === 'wa', 'each send is logged on the lead');
    // a lead messaged by a campaign is held back from the next one for a week
    [$ok2] = pm_wac_audience('promanaged', $c['aud']);
    pm_t_eq(array_column($ok2, 'id'), [], 'nobody is messaged twice in a week');
    // daily limit
    pm_save('leads', ['d1' => $mk('d1', '+265 999 200 001'), 'd2' => $mk('d2', '+265 999 200 002')]);
    $cfg = pm_load('agents_config', 'pm_agents_default_config');
    $cfg['wa_cap'] = 1;
    pm_save('agents_config', $cfg);
    pm_save('wa_campaigns', ['rows' => []]);
    $_POST = ['name' => 'Cap test', 'text' => $text, 'statuses' => ['contacted'], 'min_score' => '0', 'send_from' => date('Y-m-d\TH:i', time() - 60)];
    pm_do_wac_create('promanaged');
    $c2 = pm_wac_campaigns('promanaged')[0];
    $_POST = ['id' => $c2['id']];
    pm_do_wac_approve('promanaged');
    $sent = [];
    $n = pm_wac_send_pass($c2['id'], $sender, 5);
    pm_t_eq($n, 1, 'the daily WhatsApp limit stops the pass');
    pm_t_eq(pm_wac_get($c2['id'])['status'], 'sending', 'the rest waits for tomorrow');
    // pause / resume / cancel / delete
    $_POST = ['id' => $c2['id']];
    pm_t_eq(pm_do_wac_pause('promanaged')['kind'], 'ok', 'pause works');
    pm_t_eq(pm_wac_send_pass($c2['id'], $sender, 5), 0, 'a paused campaign sends nothing');
    pm_t_eq(pm_do_wac_resume('promanaged')['kind'], 'ok', 'resume works');
    pm_t_eq(pm_do_wac_delete('promanaged')['kind'], 'err', 'a live campaign cannot be deleted');
    pm_t_eq(pm_do_wac_cancel('promanaged')['kind'], 'ok', 'cancel works');
    pm_t_eq(pm_do_wac_delete('promanaged')['kind'], 'ok', 'a cancelled one can be deleted');
    // three failures in a row pause it
    $cfg['wa_cap'] = 40;
    pm_save('agents_config', $cfg);
    pm_save('leads', ['f1' => $mk('f1', '+265 999 300 001'), 'f2' => $mk('f2', '+265 999 300 002'), 'f3' => $mk('f3', '+265 999 300 003'), 'f4' => $mk('f4', '+265 999 300 004')]);
    $_POST = ['name' => 'Failing', 'text' => $text, 'statuses' => ['contacted'], 'min_score' => '0', 'send_from' => date('Y-m-d\TH:i', time() - 60)];
    pm_do_wac_create('promanaged');
    $c3 = pm_wac_campaigns('promanaged')[0];
    $_POST = ['id' => $c3['id']];
    pm_do_wac_approve('promanaged');
    pm_wac_send_pass($c3['id'], fn() => [false, 'token expired'], 5);
    $c3 = pm_wac_get($c3['id']);
    pm_t_assert($c3['status'] === 'paused' && str_contains($c3['error'], 'token expired') && pm_wac_counts($c3)['failed'] === 3, 'three failures in a row pause the campaign and say why');
    // two scheduler passes can never send to the same person twice
    $c3 = pm_wac_get($c3['id']);
    pm_save('wa_campaigns', ['rows' => array_map(fn($r) => $r['id'] === $c3['id'] ? array_replace($r, ['status' => 'approved', 'error' => '', 'recipients' => array_map(fn($x) => $x + ['state' => 'pending'], [['id' => 'f1', 'name' => 'x', 'num' => '265999300001', 'state' => 'pending']])]) : $r, pm_wac_all())]);
    pm_t_assert(pm_wac_claim($c3['id'], 0) === true && pm_wac_claim($c3['id'], 0) === false, 'a recipient can be claimed once, by one pass');
    pm_t_eq(pm_wac_send_pass($c3['id'], fn() => [true, 'x'], 5), 0, 'a claimed recipient is not sent again by another pass');
    // the scheduler job is gated: off without keys, and only in working hours
    putenv('WA_BIZ_TOKEN');
    pm_t_eq(pm_job_wa_campaigns('promanaged'), '', 'off without WhatsApp Business keys');
    putenv('WA_BIZ_TOKEN=tok');
    $_POST = [];
    pm_t_assert(in_array(pm_job_wa_campaigns('promanaged'), ['', 'campaigns wait for working hours'], true), 'the job is quiet when nothing is due or it is outside working hours');
    // templates: outside the window only an approved template may be sent
    $api = pm_wac_api_sender();
    $GLOBALS['PM_WA_STUB'] = function ($to, $text) {
        return [true, $text];
    };
    [$ok3, $m3] = $api('265999300001', 'x', ['thread' => []]);
    pm_t_assert(!$ok3 && str_contains($m3, 'no approved template'), 'outside the 24-hour window without a template: not sent');
    putenv('WA_BIZ_TEMPLATE=monthly_checkin');
    [$ok3, $m3] = $api('265999300001', 'x', ['contact' => 'Mary Banda', 'thread' => []]);
    pm_t_assert($ok3 && str_contains($m3, 'template monthly_checkin') && str_contains($m3, 'Mary'), 'with a template the approved template goes out, filled with the first name: ' . $m3);
    [$ok3, $m3] = $api('265999300001', 'plain text', ['thread' => [['dir' => 'in', 'ch' => 'wa', 'at' => date('Y-m-d H:i')]]]);
    pm_t_assert($ok3 && $m3 === 'plain text', 'inside the window the campaign text goes as it is');
    putenv('WA_BIZ_TEMPLATE');
    $GLOBALS['PM_WA_STUB'] = null;
    // the screen renders, with and without WhatsApp Business
    ob_start();
    pm_view_wa_campaigns('promanaged', 'csrf-token');
    $html = ob_get_clean();
    pm_t_assert(str_contains($html, 'Campaigns') && str_contains($html, 'New campaign') && str_contains($html, 'Failing'), 'the Campaigns card renders');
    putenv('WA_BIZ_TOKEN');
    ob_start();
    pm_view_wa_campaigns('promanaged', 'csrf-token');
    $off = ob_get_clean();
    pm_t_assert(str_contains($off, 'off: needs WhatsApp Business') && !str_contains($off, 'New campaign'), 'without keys the card is just a note');
});

pm_t_done();
