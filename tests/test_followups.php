<?php
/**
 * Tests for follow-ups with AI and for today's strategy keeping up: which leads are due, drafting them on demand (all or one), the morning job, what the Director sees
 * of the work on the desk, and when it rewrites the strategy by itself. Runs on a TEMP COPY of data/ with the AI stubbed; nothing is sent.
 * Run: php tests/test_followups.php
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

$ago = fn(int $d) => date('Y-m-d H:i', strtotime("-$d days"));
$mk = fn(string $id, array $x = []) => $x + ['id' => $id, 'brand' => 'promanaged', 'name' => "Lead $id", 'type' => 'hotel', 'city' => 'Zomba', 'status' => 'contacted', 'email' => "$id@stay.mw", 'contact' => '', 'score' => 70,
    'last_contacted' => $ago(6), 'followups' => 0, 'sent' => [$ago(6)], 'drafts' => ['email_subject' => 'First', 'email_body' => 'Our first email to them.', 'whatsapp' => 'wa'], 'notes' => [], 'thread' => []];
$cfg = ['followup_days' => 4, 'max_followups' => 2];
pm_save('leads', []);

t('Which leads are due for a follow-up', function () use ($mk, $ago, $cfg) {
    $leads = [
        'a' => $mk('a'), 'b' => $mk('b', ['last_contacted' => $ago(2)]), 'c' => $mk('c', ['followups' => 2]), 'd' => $mk('d', ['snooze_until' => date('Y-m-d', strtotime('+3 days'))]),
        'e' => $mk('e', ['email_bad' => true, 'phone' => '']), 'f' => $mk('f', ['status' => 'replied']), 'g' => $mk('g', ['followup_draft' => ['email_body' => 'Already drafted', 'email_subject' => 'S']]), 'h' => $mk('h', ['last_contacted' => '']),
    ];
    pm_t_eq(array_keys(pm_followup_due($leads, $cfg)), ['a', 'g'], 'a quiet contacted lead under the limit is due; not one contacted 2 days ago, at the limit, snoozed, with only a dead email, replied, or never dated');
    pm_t_eq(array_keys(pm_followup_due($leads, $cfg, false)), ['a'], 'and the ones still needing a draft are those without one');
    pm_t_eq(array_keys(pm_followup_due(['x' => $mk('x', ['last_contacted' => $ago(4)])], $cfg)), ['x'], 'exactly the configured number of quiet days counts');
});

t('Drafting all due follow-ups with AI, six to a call, drafts only', function () use ($mk, $ago) {
    $calls = [];
    $missing = [];
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls, &$missing) {
        $ids = array_column(json_decode(substr($user, 0, strpos($user, "\nJSON array")), true), 'id');
        $calls[] = ['sys' => $sys, 'ids' => $ids];
        return json_encode(array_values(array_map(fn($id) => ['id' => $id, 'email_subject' => "Following up $id", 'email_body' => "A new angle for $id: one fresh reason, no pressure.", 'whatsapp' => "Quick nudge $id"], array_diff($ids, $missing))));
    };
    $leads = [];
    foreach (range(1, 15) as $i) {
        $leads["l$i"] = $mk("l$i");
    }
    $leads['own'] = $mk('own', ['brand' => 'travel']);
    $leads['drafted'] = $mk('drafted', ['followup_draft' => ['email_body' => 'Mine', 'email_subject' => 'S', 'whatsapp' => '']]);
    $leads['young'] = $mk('young', ['last_contacted' => $ago(1)]);
    pm_save('leads', $leads);
    $r = pm_followup_draft_now();
    pm_t_eq([$r['drafted'], $r['failed'], $r['due']], [12, 0, 15], 'at most 12 are drafted in one go, of the 15 that are due (others, a draft already, too recent, another business, are not counted)');
    pm_t_eq(array_map(fn($c) => count($c['ids']), $calls), [6, 6], 'six leads to a call: two calls');
    pm_t_assert(str_contains($calls[0]['sys'], 'You are the Follow-up agent'), 'it is the Follow-up agent that writes them');
    $l = pm_leads();
    pm_t_assert(str_contains($l['l1']['followup_draft']['email_body'], 'A new angle for l1') && $l['l1']['followup_draft']['whatsapp'] === 'Quick nudge l1' && !empty($l['l1']['followup_arm']) && str_contains(json_encode($l['l1']['notes']), 'Follow-up drafted with AI'),
        'each lead gets its email, its WhatsApp message, the subject style it used, and a note');
    pm_t_eq([$l['drafted']['followup_draft']['email_body'], isset($l['young']['followup_draft']), isset($l['own']['followup_draft'])], ['Mine', false, false], 'a draft that was already there, a lead that is too recent and another business\'s lead are untouched');
    pm_t_assert(count($l['l1']['sent']) === 1 && $l['l1']['followups'] === 0 && $l['l1']['status'] === 'contacted', 'nothing was sent and no follow-up was counted: they are drafts for the owner');
    pm_t_eq(count(array_filter($l, fn($x) => !empty($x['followup_draft']))), 13, '12 new drafts, and the one that was already there');
    // the AI leaves one out
    pm_save('leads', ['m1' => $mk('m1'), 'm2' => $mk('m2')]);
    $missing = ['m2'];
    pm_t_eq(array_slice(pm_followup_draft_now(), 0, 2), ['drafted' => 1, 'failed' => 1], 'a lead the AI left out is counted as not drafted, and the other is');
    $missing = [];
    // the AI fails
    pm_save('leads', ['f1' => $mk('f1')]);
    $GLOBALS['PM_AI_STUB'] = function () { throw new RuntimeException('quota exceeded'); };
    pm_t_eq(array_slice(pm_followup_draft_now(), 0, 2), ['drafted' => 0, 'failed' => 1], 'an AI that fails is reported and changes nothing');
    pm_t_assert(empty(pm_leads()['f1']['followup_draft']), 'the lead has no draft');
    // only some
    $GLOBALS['PM_AI_STUB'] = fn($s, $u) => json_encode(array_map(fn($x) => ['id' => $x['id'], 'email_subject' => 'S', 'email_body' => 'Body for ' . $x['id'], 'whatsapp' => ''], json_decode(substr($u, 0, strpos($u, "\nJSON array")), true)));
    pm_save('leads', ['p1' => $mk('p1'), 'p2' => $mk('p2'), 'p3' => $mk('p3')]);
    pm_t_eq(pm_followup_draft_now(['p2'])['drafted'], 1, 'a chosen lead can be drafted alone');
    pm_t_eq(array_keys(array_filter(pm_leads(), fn($x) => !empty($x['followup_draft']))), ['p2'], 'and only that one');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('One lead, whenever the owner asks', function () use ($mk, $ago) {
    $GLOBALS['PM_AI_STUB'] = fn($s, $u) => json_encode([['id' => 'q1', 'email_subject' => 'Fresh angle', 'email_body' => 'A different reason to talk.', 'whatsapp' => 'Hi again']]);
    pm_save('leads', ['q1' => $mk('q1', ['last_contacted' => $ago(1), 'followup_draft' => ['email_subject' => 'Old', 'email_body' => 'Old draft', 'whatsapp' => '']]), 'q2' => $mk('q2', ['status' => 'replied']),
        'q3' => $mk('q3', ['email_bad' => true, 'phone' => '']), 'q4' => $mk('q4', ['brand' => 'travel'])]);
    [$ok, $msg] = pm_followup_draft_one('q1');
    pm_t_assert($ok && str_contains($msg, 'Follow-up drafted for Lead q1') && pm_leads()['q1']['followup_draft']['email_body'] === 'A different reason to talk.', 'a lead that is not even due yet can be drafted now, and its older draft is replaced');
    pm_t_assert(!pm_followup_draft_one('q2')[0] && str_contains(pm_followup_draft_one('q2')[1], 'already written to'), 'a lead that replied is refused with the reason');
    pm_t_assert(!pm_followup_draft_one('q3')[0] && str_contains(pm_followup_draft_one('q3')[1], 'no working email'), 'so is one with no working email');
    pm_t_eq(pm_followup_draft_one('nobody'), [false, 'That lead was not found.'], 'and an unknown one');
    $GLOBALS['PM_AI_STUB'] = fn($s, $u) => json_encode([['id' => 'q4', 'email_subject' => 'S', 'email_body' => 'Travel angle', 'whatsapp' => '']]);
    pm_t_assert(pm_followup_draft_one('q4')[0] && pm_brand() === 'promanaged', 'another business\'s lead is drafted as that business, and the app is back on the one it was on');
    $GLOBALS['PM_AI_STUB'] = function () { throw new RuntimeException('AI is down'); };
    [$ok, $msg] = pm_followup_draft_one('q1');
    pm_t_assert(!$ok && str_contains($msg, 'AI is down') && pm_leads()['q1']['followup_draft']['email_body'] === 'A different reason to talk.', 'an AI that fails says so and keeps the draft that was there');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('The morning job drafts what is due, once a day, in working hours', function () use ($mk, $ago) {
    $n = 0;
    $GLOBALS['PM_AI_STUB'] = function ($s, $u) use (&$n) {
        $n++;
        return json_encode(array_map(fn($x) => ['id' => $x['id'], 'email_subject' => 'S', 'email_body' => 'Job draft for ' . $x['id'], 'whatsapp' => ''], json_decode(substr($u, 0, strpos($u, "\nJSON array")), true)));
    };
    pm_t_assert(in_array('pm_job_followup_drafts', pm_social_registered('pm_job_'), true), 'the scheduler runs it with every other job');
    pm_save('leads', ['j1' => $mk('j1'), 'j2' => $mk('j2')]);
    pm_save('followup_job', []);
    putenv('PM_TEST_NOW=2026-10-10 09:00:00'); // a Saturday
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'not at the weekend');
    putenv('PM_TEST_NOW=2026-10-12 06:30:00');
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'not before 08:00');
    putenv('PM_TEST_NOW=2026-10-12 18:00:00');
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'nor in the evening');
    pm_t_eq($n, 0, 'and no AI is called outside working hours');
    putenv('PM_TEST_NOW=2026-10-12 09:00:00'); // Monday morning
    $cfgs = pm_load('agents_config', 'pm_agents_default_config');
    $cfgs['enabled']['followup'] = false;
    pm_save('agents_config', $cfgs);
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'not when the Follow-up switch is off in the agent settings');
    $cfgs['enabled']['followup'] = true;
    pm_save('agents_config', $cfgs);
    pm_run_state(['state' => 'running', 'started' => date('Y-m-d H:i:s'), 'beat' => time()]);
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'nor while the daily agent run is going (it drafts them too)');
    pm_run_state(['state' => 'done', 'started' => date('Y-m-d H:i:s'), 'finished' => date('Y-m-d H:i:s'), 'beat' => time()]);
    $out = pm_job_followup_drafts('promanaged');
    pm_t_assert($out === '2 follow-up drafts written with AI' && $n === 1, 'on a working morning the due ones are drafted: ' . $out);
    pm_t_assert(!empty(pm_leads()['j1']['followup_draft']) && !empty(pm_leads()['j2']['followup_draft']), 'and are waiting on the leads');
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'once a day: the next pass does nothing');
    pm_t_eq($n, 1, 'and calls no AI');
    putenv('PM_TEST_NOW=2026-10-13 09:00:00'); // the next day, with nothing new due
    pm_t_eq(pm_job_followup_drafts('promanaged'), '', 'the next day with nothing due, it says nothing and calls no AI');
    pm_t_eq($n, 1, 'no AI call');
    // the AI failing does not use up the day
    pm_save('leads', ['k1' => $mk('k1')]);
    pm_save('followup_job', []);
    $GLOBALS['PM_AI_STUB'] = function () { throw new RuntimeException('down'); };
    pm_t_assert(str_contains(pm_job_followup_drafts('promanaged'), '0 follow-up drafts written with AI, 1 could not be'), 'a failing AI is reported by the job');
    $GLOBALS['PM_AI_STUB'] = fn($s, $u) => json_encode([['id' => 'k1', 'email_subject' => 'S', 'email_body' => 'Second try', 'whatsapp' => '']]);
    pm_t_eq(pm_job_followup_drafts('promanaged'), '1 follow-up draft written with AI', 'and the next pass tries again the same day');
    putenv('PM_TEST_NOW');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('What the Director sees of the work on the desk', function () use ($mk, $ago) {
    $leads = [
        'a' => $mk('a'), 'b' => $mk('b', ['followup_draft' => ['email_body' => 'x', 'email_subject' => 's', 'whatsapp' => '']]), 'r' => $mk('r', ['status' => 'replied']), 'w' => $mk('w', ['status' => 'contacted', 'awaiting_reply_since' => $ago(0), 'last_contacted' => $ago(1)]),
        'd1' => $mk('d1', ['status' => 'drafted', 'sent' => [], 'last_contacted' => '']), 'd2' => $mk('d2', ['status' => 'qualified', 'sent' => [], 'last_contacted' => '', 'approved_at' => $ago(0)]), 'o' => $mk('o', ['brand' => 'travel']),
        's' => $mk('s', ['sent' => [date('Y-m-d H:i')], 'last_contacted' => date('Y-m-d H:i')]),
    ];
    pm_save('leads', $leads);
    $st = pm_pipeline_stats('promanaged');
    pm_t_eq([$st['awaiting_reply'], $st['followups_due'], $st['followups_to_draft'], $st['drafts_unsent'], $st['approved_waiting'], $st['sent_today']], [2, 2, 1, 1, 1, 1],
        'replies waiting, follow-ups due (and how many still need a draft), drafts to read, the approved queue and what was sent today, for this business only');
});

t('Today\'s strategy rewrites itself when the work on the desk changes, at most every 90 minutes', function () use ($mk, $ago) {
    $calls = 0;
    $seen = '';
    $GLOBALS['PM_AI_STUB'] = function ($sys, $user) use (&$calls, &$seen) {
        $calls++;
        $seen = $sys . "\n" . $user;
        return json_encode(['headline' => "Strategy $calls", 'priorities' => ['Review the follow-ups'], 'focus' => [], 'drop' => [], 'experiment' => '']);
    };
    pm_save('director', []);
    pm_save('leads', ['a' => $mk('a'), 'b' => $mk('b')]);
    $b1 = pm_director_brief();
    pm_t_assert($calls === 1 && $b1['headline'] === 'Strategy 1' && abs($b1['at'] - time()) < 5 && !empty($b1['sig']), 'the first view of the day writes it, with the time it was written');
    pm_t_assert(str_contains($seen, 'followups_due') && str_contains($seen, 'never tell the owner to send something that is not drafted yet') && str_contains($seen, '"followups_due":2') && str_contains($seen, '"followups_to_draft":2'), 'the AI is shown the follow-ups that are due and told what to say about them');
    pm_director_brief();
    pm_t_eq($calls, 1, 'looking again changes nothing: the advice is kept');
    pm_save('leads', ['a' => $mk('a'), 'b' => $mk('b'), 'r' => $mk('r', ['status' => 'replied'])]);
    pm_director_brief();
    pm_t_eq($calls, 1, 'a reply arrives, but the advice is under 90 minutes old: kept');
    $d = pm_load('director', fn() => []);
    $d['promanaged']['at'] = time() - 6000;
    pm_save('director', $d);
    $b3 = pm_director_brief();
    pm_t_assert($calls === 2 && $b3['headline'] === 'Strategy 2' && str_contains($seen, '"awaiting_reply":1'), 'older than 90 minutes and the desk changed: rewritten, and it now knows a reply is waiting');
    $d = pm_load('director', fn() => []);
    $d['promanaged']['at'] = time() - 6000;
    pm_save('director', $d);
    pm_director_brief();
    pm_t_eq($calls, 2, 'older but nothing changed since: not rewritten (no AI spent for nothing)');
    pm_director_brief(true);
    pm_t_eq($calls, 3, 'the Refresh button always rewrites it');
    $GLOBALS['PM_AI_STUB'] = null;
});

pm_t_done();
