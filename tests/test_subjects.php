<?php
/**
 * Tests for subject lines: capital letters and names put right by code (never a changed word), the AI's capitalising accepted only when every word is unchanged, the writers told how and
 * their output tidied, what is already drafted fixed on request (hand-edited drafts left alone), and what is sent always carrying a proper subject.
 * Runs on a TEMP COPY of data/ with the AI stubbed; nothing is sent. Run: php tests/test_subjects.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
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

$norm = fn(string $s) => trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));

t('Code puts capitals and names right without changing a word', function () use ($norm) {
    $cases = [
        ['your lakeside lodge south of nkhotakota', [], 'Your lakeside lodge south of Nkhotakota'],
        ['rooms near the shire river barrage?', [], 'Rooms near the Shire River barrage?'],
        ['stays on mitundu circle in area 44?', ['address' => 'Mitundu Circle, Area 44, Lilongwe'], 'Stays on Mitundu Circle in Area 44?'],
        ['DIRECT BOOKINGS FOR YOUR LODGE!', [], 'Direct bookings for your lodge'],
        ['10 rooms in zomba', [], '10 rooms in Zomba'],
        ['"Subject: vat and levy at blend lodge."', ['name' => 'Blend Lodge & Kitchen'], 'VAT and levy at Blend Lodge'],
        ['taking direct bookings along the m5?', [], 'Taking direct bookings along the M5?'],
        ["direct bookings for rosalyn\u{2019}s beach hotel", ['name' => "Rosalyn's Beach Hotel"], "Direct bookings for Rosalyn's Beach Hotel"],
        ['a lodge on the bua river', ['name' => 'Bua River Lodge'], 'A lodge on the Bua River'],
        ['your riverfront lodge', ['name' => 'Bua River Lodge'], 'Your riverfront lodge'],
        ['direct bookings at vwaza marsh?', [], 'Direct bookings at Vwaza Marsh?'],
        ['whatsapp and facebook for your lodge', [], 'WhatsApp and Facebook for your lodge'],
        ['your hotel along the M5 road', ['address' => 'Along M5 Road, Liwonde'], 'Your hotel along the M5 road'],
        ['direct bookings for mayoka village?', ['name' => 'Mayoka Village Beach Lodge'], 'Direct bookings for Mayoka Village?'],
        ['Already fine, in Zomba', [], 'Already fine, in Zomba'],
        ['  ', [], ''],
    ];
    foreach ($cases as [$in, $lead, $want]) {
        $got = pm_subject_tidy($in, $lead);
        pm_t_eq($got, $want, "\"" . trim($in) . '"');
        if ($got !== '' && !str_contains($in, '!')) {
            pm_t_assert($norm(preg_replace('/^\s*"?subject\s*:\s*/i', '', $in)) === $norm($got) || $norm($got) === '', 'no word was changed in "' . $got . '"');
        }
    }
    pm_t_assert(pm_subject_same_words('Rooms in Zomba?', 'rooms in zomba') && !pm_subject_same_words('Rooms in Zomba', 'Rooms in Lilongwe') && !pm_subject_same_words('', ''), 'the same-words test ignores capitals and punctuation only');
});

t('The AI capitalises; an answer that changes a word is thrown away', function () {
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls) {
        $calls++;
        $in = json_decode(substr($user, 0, strpos($user, "\nJSON")), true);
        $out = [];
        foreach ($in as $x) {
            $s = $x['subject'];
            $out[] = ['id' => $x['id'], 'subject' => match ($s) {
                'soul rebel lodge in nkhata bay' => 'Soul Rebel Lodge in Nkhata Bay',
                'it support in zomba' => 'IT support in Zomba',
                'direct bookings in katoto' => 'Direct bookings in Katoto town', // a word added: refused
                'stock tracking at hostaria' => 'Stock tracking at HOSTARIA!', // only capitals and a mark changed: the code tidies it
                default => $s,
            }];
        }
        $out[] = ['id' => 'nobody', 'subject' => 'Not asked for'];
        return json_encode(['items' => $out]);
    };
    $rows = ['a' => ['subject' => 'soul rebel lodge in nkhata bay', 'name' => 'Soul Rebel Malawi'], 'b' => ['subject' => 'it support in zomba'], 'c' => ['subject' => 'direct bookings in katoto'], 'd' => ['subject' => 'stock tracking at hostaria'], 'e' => ['subject' => 'Fine already']];
    $fix = pm_subjects_ai_case($rows);
    pm_t_eq($fix['a'] ?? null, 'Soul Rebel Lodge in Nkhata Bay', 'names the code could not know are capitalised by the AI');
    pm_t_eq($fix['b'] ?? null, 'IT support in Zomba', 'acronyms too');
    pm_t_assert(!isset($fix['c']), 'an answer with an extra word is refused');
    pm_t_assert(($fix['d'] ?? '') === 'Stock tracking at HOSTARIA', 'a shouted name is tidied once more by code: ' . ($fix['d'] ?? 'none'));
    pm_t_assert(!isset($fix['e']) && !isset($fix['nobody']), 'an unchanged subject and an id nobody asked about give nothing');
    pm_t_eq($calls, 1, 'one call for the batch');
    $calls = 0;
    $many = [];
    foreach (range(1, 25) as $i) {
        $many["k$i"] = ['subject' => "rooms in zomba $i"];
    }
    pm_subjects_ai_case($many);
    pm_t_eq($calls, 2, '25 subjects are asked in two calls of at most 20');
    $GLOBALS['PM_AI_STUB'] = fn() => 'not json at all';
    pm_t_eq(pm_subjects_ai_case($rows), [], 'a reply that is not JSON changes nothing');
    $GLOBALS['PM_AI_STUB'] = function () {
        throw new RuntimeException('down');
    };
    pm_t_eq(pm_subjects_ai_case($rows), [], 'a failing AI changes nothing');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The writers are told how, and their subjects are tidied', function () {
    $seen = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen[] = $sys;
        return json_encode([['id' => 'w1', 'email_subject' => 'direct bookings for macondo camp?', 'opening' => 'I came across Macondo while looking at stays in Mangochi.', 'email_body' => 'Hello, a short note about your camp in Mangochi. Would a call suit you?', 'whatsapp' => 'Hello, quick question.']]);
    };
    $lead = ['id' => 'w1', 'brand' => 'promanaged', 'name' => 'Macondo Camp', 'type' => 'camp', 'city' => 'Mangochi', 'status' => 'qualified', 'email' => 'a@macondo.mw', 'drafts' => [], 'sent' => [], 'notes' => []];
    $r = pm_agent_write([$lead]);
    pm_t_eq($r[0]['email_subject'], 'Direct bookings for Macondo Camp?', 'the first-email writer: capital letter and the name');
    pm_t_assert(str_contains($seen[0], 'a capital letter first and a capital letter on every town') && str_contains($seen[0], 'Rooms near the Shire River barrage?'), 'and it was told how to write a subject');
    $f = pm_agent_followup([$lead + ['drafts' => ['email_body' => 'old']]]);
    pm_t_eq($f[0]['email_subject'], 'Direct bookings for Macondo Camp?', 'the follow-up writer too');
    pm_t_assert(str_contains($seen[1], 'a capital letter first and a capital letter on every town'), 'and it was told how');
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$seen) {
        $seen[] = $sys;
        return json_encode([['id' => 'w1', 'email_subject' => 'rooms near the shire river barrage?', 'opening' => 'I came across it while looking at stays near Liwonde.', 'whatsapp' => 'Hello there.']]);
    };
    pm_brand_set('travel');
    $r = pm_agent_write([$lead + ['brand' => 'travel', 'name' => 'Liwonde Country Lodge']]);
    pm_brand_set('promanaged');
    pm_t_eq($r[0]['email_subject'], 'Rooms near the Shire River barrage?', 'the Travel Malawi writer too');
    pm_t_assert(str_contains(end($seen), 'a capital letter first and a capital letter on every town'), 'and it was told how');
    $before = count($seen);
    pm_subjects_polish([['id' => 'w1', 'email_subject' => 'a subject']], [$lead]);
    pm_t_eq(count($seen), $before, 'writing a draft costs no extra AI call');
    pm_subjects_polish([['id' => 'w1', 'email_subject' => 'a subject']], [$lead], true);
    pm_t_eq(count($seen), $before + 1, 'unless the AI look is asked for');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Fixing the subjects of drafts already written', function () {
    $d = fn(string $s, array $x = []) => ['email_subject' => $s, 'email_body' => 'Body of the email, long enough.', 'whatsapp' => 'wa'] + $x;
    $L = fn(string $id, array $x) => $x + ['id' => $id, 'brand' => 'promanaged', 'name' => "Lodge $id", 'type' => 'lodge', 'city' => 'Zomba', 'status' => 'drafted', 'email' => "$id@x.mw", 'sent' => [], 'notes' => [], 'thread' => []];
    pm_save('leads', [
        'a' => $L('a', ['drafts' => $d('your cottages in cape maclear')]),
        'b' => $L('b', ['drafts' => $d('soul rebel lodge in nkhata bay'), 'name' => 'Soul Rebel Malawi']),
        'c' => $L('c', ['drafts' => $d('written by hand in lowercase', ['edited_at' => '2026-10-01 10:00'])]),
        'd' => $L('d', ['drafts' => $d('already sent lowercase'), 'sent' => ['2026-10-01 10:00'], 'status' => 'contacted']),
        'e' => $L('e', ['brand' => 'travel', 'drafts' => $d('another business lowercase')]),
        'f' => $L('f', ['status' => 'contacted', 'sent' => ['2026-10-01 10:00'], 'followup_draft' => $d('a nudge for zomba'), 'drafts' => $d('Fine Subject')]),
        'g' => $L('g', ['drafts' => $d('Already right in Zomba')]),
        'h' => $L('h', ['status' => 'lost', 'drafts' => $d('a lost one lowercase')]),
    ]);
    $p = pm_subjects_pending(pm_leads());
    pm_t_eq(array_keys($p), ['a|drafts', 'b|drafts', 'f|followup_draft'], 'pending: unsent drafts of this business whose subject code can improve (not hand-edited, sent, other business, lost, or already right)');
    pm_t_eq($p['a|drafts']['tidy'], 'Your cottages in Cape Maclear', 'with what it would become');
    $calls = 0;
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls) {
        $calls++;
        $in = json_decode(substr($user, 0, strpos($user, "\nJSON")), true);
        return json_encode(['items' => array_map(fn($x) => ['id' => $x['id'], 'subject' => str_replace('Soul Rebel lodge', 'Soul Rebel Lodge', $x['subject'])], $in)]);
    };
    $r = pm_subjects_fix_now();
    pm_t_eq([$r['fixed'], $r['checked'], $r['ai'], $calls], [3, 4, true, 1], 'fixing: three subjects changed of the four unsent, unedited drafts looked at (the one already right too), one AI call');
    $L2 = pm_leads();
    pm_t_eq([$L2['a']['drafts']['email_subject'], $L2['b']['drafts']['email_subject'], $L2['f']['followup_draft']['email_subject']], ['Your cottages in Cape Maclear', 'Soul Rebel Lodge in Nkhata Bay', 'A nudge for Zomba'], 'the AI got the name code could not know');
    pm_t_eq([$L2['c']['drafts']['email_subject'], $L2['d']['drafts']['email_subject'], $L2['e']['drafts']['email_subject'], $L2['h']['drafts']['email_subject']], ['written by hand in lowercase', 'already sent lowercase', 'another business lowercase', 'a lost one lowercase'], 'hand-edited, sent, other-business and lost drafts are untouched');
    pm_t_assert(!empty($L2['a']['drafts']['subject_checked']) && !empty($L2['g']['drafts']['subject_checked']), 'what the AI looked at is marked, so it is not asked again');
    pm_t_eq(pm_subjects_pending(pm_leads()), [], 'nothing is pending any more');
    $r2 = pm_subjects_fix_now();
    pm_t_eq([$r2['fixed'], $r2['checked'], $calls], [0, 0, 1], 'a second press changes nothing and asks nothing');
    // without an AI key code still does its part
    pm_save('leads', ['z' => $L('z', ['drafts' => $d('rooms in liwonde')])]);
    $GLOBALS['PM_AI_STUB'] = null;
    $r3 = pm_subjects_fix_now();
    pm_t_eq([$r3['fixed'], $r3['ai'], pm_leads()['z']['drafts']['email_subject']], [1, false, 'Rooms in Liwonde'], 'with no AI the code still fixes capitals and names, and says the AI was not asked');
});

t('What is sent always has a proper subject', function () {
    $body = 'I came across Bright Schools while looking at schools in Lilongwe and wondered how you keep the lights on during power cuts. If that is a pain, there are one or two simple things that could help. Would a ten minute call suit you this week?';
    $lead = ['id' => 's1', 'brand' => 'promanaged', 'name' => 'Bright Schools', 'type' => 'school', 'city' => 'Lilongwe', 'status' => 'drafted', 'score' => 80, 'email' => 'office@brightschools.mw', 'website' => '',
        'drafts' => ['email_subject' => 'power at bright schools in lilongwe', 'email_body' => $body], 'sent' => [], 'notes' => [], 'thread' => [], 'approved_at' => date('Y-m-d H:i'), 'created' => date('Y-m-d H:i')];
    pm_ob_update('email_verify', function ($x) {
        $x['dns']['brightschools.mw'] = [true, time()];
        return $x;
    });
    pm_save('leads', ['s1' => $lead]);
    $leads = pm_leads();
    $sent = [];
    $r = pm_send_first($leads, 's1', 'drafts', function (array $s, array $m) use (&$sent) {
        $sent[] = $m['subject'];
        return [true, '', 'mid'];
    });
    pm_t_assert(!empty($r['ok']), 'the email sends: ' . ($r['msg'] ?? ''));
    pm_t_eq($sent, ['Power at Bright Schools in Lilongwe'], 'and goes out with a capital letter and the names in capitals, whatever the draft said');
});

pm_t_done();
