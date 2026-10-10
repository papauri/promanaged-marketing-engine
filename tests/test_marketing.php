<?php
/**
 * Tests for the MARKETING.md cycle-1 layer (sx_learn.php + the agents/director upgrades):
 * outcome weights, weighted scouting, subject experiments, provider failover, watcher/reverify/winback/postsign agents,
 * research queue, engagement-aware follow-ups, inbox sync, deliverability, adaptive caps, privacy tools, shared learnings.
 * Run: php tests/test_marketing.php
 */
require __DIR__ . '/boot.php';
$T = pm_test_boot();
require_once dirname(__DIR__) . '/lib/wa_biz.php';
pm_brand_set('promanaged');

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
    return $o + ['id' => 'l' . bin2hex(random_bytes(4)), 'brand' => 'promanaged', 'name' => 'Test Business ' . bin2hex(random_bytes(2)),
        'type' => 'Shops & supermarkets', 'city' => 'Lilongwe', 'status' => 'new', 'email' => 'hello@' . bin2hex(random_bytes(4)) . '.mw',
        'created' => date('Y-m-d H:i', time() - 90 * 86400), 'updated' => date('Y-m-d H:i', time() - 90 * 86400), 'sent' => [], 'offering' => 'build'];
}

t('Provider failover', function () {
    pm_t_eq(pm_ai_alt('gemini'), 'anthropic', 'alt of gemini');
    pm_t_eq(pm_ai_alt('anthropic'), 'gemini', 'alt of anthropic');
    $ok = pm_ai_call_with(fn() => 'first', fn() => 'second', 'key-present');
    pm_t_eq($ok, 'first', 'primary wins when it works');
    $ok = pm_ai_call_with(fn() => throw new RuntimeException('down'), fn() => 'second', 'key-present');
    pm_t_eq($ok, 'second', 'falls back when the alt key exists');
    $thrown = false;
    try {
        pm_ai_call_with(fn() => throw new RuntimeException('down'), fn() => 'second', '');
    } catch (RuntimeException $e) {
        $thrown = str_contains($e->getMessage(), 'down');
    }
    pm_t_assert($thrown, 'no retry without an alt key');
});

t('Outcome weights', function () {
    pm_save('leads', [
        'w1' => mk_lead(['type' => 'Hotels & lodges', 'city' => 'Blantyre', 'status' => 'won', 'offering' => 'build']),
        'w2' => mk_lead(['type' => 'Hotels & lodges', 'city' => 'Lilongwe', 'status' => 'replied', 'offering' => 'build']),
        'w3' => mk_lead(['type' => 'Shops & supermarkets', 'city' => 'Lilongwe', 'status' => 'lost']),
        'w4' => mk_lead(['type' => 'Shops & supermarkets', 'city' => 'Lilongwe', 'status' => 'optout']),
        'w5' => mk_lead(['brand' => 'travel', 'type' => 'Hotels & lodges', 'city' => 'Lilongwe', 'status' => 'won']),
    ]);
    $w = pm_outcome_weights('promanaged');
    pm_t_assert(($w['sector']['Hotels & lodges'] ?? 0) > ($w['sector']['Shops & supermarkets'] ?? 1), 'winning sector weighted higher');
    pm_t_assert(!isset($w['sector']['optout-only']), 'no bogus sectors');
    pm_t_assert(isset($w['source']['other']), 'source dimension exists');
    pm_t_assert(min($w['sector']) >= 0.2, 'weights never below the floor');
    // cached version is fresh on second call
    pm_t_eq(pm_outcome_weights_fresh('promanaged'), $w, 'fresh cache returns the same weights');
    $pick = pm_weighted_pick(['A', 'B'], ['A' => 1.0, 'B' => 0.25], 5, 0);
    pm_t_assert(count($pick) === 5 && in_array('A', $pick, true) && in_array('B', $pick, true), 'weighted pick includes everyone');
    pm_t_assert(substr_count(implode('', $pick), 'A') > substr_count(implode('', $pick), 'B'), 'heavy weight gets more slots');
});

t('Qualifier line + scout targets', function () {
    pm_save('leads', ['q1' => mk_lead(['type' => 'Hotels & lodges', 'status' => 'won'])]);
    $line = pm_learn_qualifier_line('promanaged');
    pm_t_assert(str_contains($line, 'Hotels & lodges'), 'qualifier line names the winning type');
    $cfg = pm_agents_config();
    $cfg['sectors'] = ['hotels and lodges', 'shops and supermarkets'];
    $cfg['cities'] = ['Lilongwe', 'Blantyre', 'Zomba'];
    $cfg['scouts_per_day'] = 6;
    $targets = pm_scout_targets($cfg, ['hotels and lodges' => 100, 'shops and supermarkets' => 25]);
    pm_t_eq(count($targets), 6, 'six targets');
    pm_t_assert(!array_diff(array_column($targets, 0), $cfg['sectors']), 'only allowed sectors are picked');
    $full = pm_weighted_pick($cfg['sectors'], ['hotels and lodges' => 100, 'shops and supermarkets' => 25], 500, 0);
    $c = array_count_values($full);
    pm_t_assert($c['hotels and lodges'] > $c['shops and supermarkets'], 'heavy type gets more of the full cycle');
    // no weights: the old rotation still works
    $plain = pm_scout_targets($cfg);
    pm_t_eq(count($plain), 6, 'plain rotation still returns targets');
});

t('Subject experiments', function () {
    $arm = pm_email_exp_arm('someleadid', 'promanaged');
    pm_t_assert(in_array($arm, ['A', 'B'], true), 'arm is A or B');
    pm_t_eq(pm_email_exp_arm('someleadid', 'promanaged'), $arm, 'arm is stable for a lead');
    $leads = [];
    for ($i = 0; $i < 24; $i++) {
        $l = mk_lead(['status' => 'contacted', 'sent' => [date('Y-m-d H:i', time() - 86400)], 'drafts' => ['email_subject' => 'subj ' . $i]]);
        $l['email_arm'] = $i % 2 ? 'B' : 'A';
        if ($i % 4 === 0) {
            $l['last_reply'] = date('Y-m-d H:i', time() - 3600);
        }
        $leads['e' . $i] = $l;
    }
    pm_save('leads', $leads);
    $msg = pm_jobg_email_exp();
    pm_t_assert(str_contains($msg, 'subject experiment'), 'job ran: ' . $msg);
    $stored = pm_load('email_exp', fn() => []);
    pm_t_assert(!empty($stored['example']), 'a winning example subject is stored');
    $line = pm_email_exp_example('promanaged');
    pm_t_assert(str_contains($line, 'subj '), 'writer line carries the example style');
});

t('Watcher, reverify, winback, postsign agents', function () {
    $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode([
        ['name' => 'Fresh Ltd', 'type' => 'Shops & supermarkets', 'city' => 'Zomba', 'website' => 'https://fresh.mw',
            'phone' => '+265 999 000 111', 'email' => 'hi@fresh.mw', 'facebook' => 'https://www.facebook.com/fresh',
            'social_gaps' => ['no website'], 'evidence' => ['new site + source'], 'need_signals' => ['moving online'], 'offering' => 'build'],
    ]);
    $found = pm_agent_watch('promanaged', ['Old Co']);
    pm_t_assert(count($found) === 1 && $found[0]['name'] === 'Fresh Ltd', 'watcher returns fresh signals');
    $stale = pm_reverify_due([
        's1' => mk_lead(['id' => 's1', 'status' => 'drafted', 'created' => date('Y-m-d H:i', time() - 90 * 86400)]),
        's2' => mk_lead(['id' => 's2', 'status' => 'drafted', 'created' => date('Y-m-d H:i', time() - 5 * 86400)]),
        's3' => mk_lead(['id' => 's3', 'status' => 'drafted', 'contact_checked_at' => date('Y-m-d'), 'created' => date('Y-m-d H:i', time() - 90 * 86400)]),
    ], 3);
    pm_t_eq(array_column($stale, 'id'), ['s1'], 'only old unchecked leads are re-verified');
    $out = pm_agent_reverify([$stale[0]]);
    pm_t_eq($out[0]['id'], 's1', 'reverify passes ids through');
    // win-back selector
    $lost = mk_lead(['status' => 'lost', 'lost_at' => date('Y-m-d H:i', time() - 70 * 86400), 'lost_reason' => 'too busy']);
    $freshLost = mk_lead(['status' => 'lost', 'lost_at' => date('Y-m-d H:i', time() - 10 * 86400)]);
    $already = mk_lead(['status' => 'lost', 'lost_at' => date('Y-m-d H:i', time() - 120 * 86400), 'winback_at' => date('Y-m-d', time() - 10 * 86400)]);
    pm_t_eq(array_column(pm_winback_due([$lost, $freshLost, $already], 2), 'id'), [$lost['id']], 'winback picks only lost-60d-plus leads not asked recently');
    $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode([['id' => $lost['id'], 'email_subject' => 'Re: us', 'email_body' => 'b', 'whatsapp' => 'w']]);
    $wb = pm_agent_winback([$lost]);
    pm_t_eq($wb[0]['id'] ?? '', $lost['id'], 'winback drafts return the lead id');
    // post-sign selector
    $won = mk_lead(['status' => 'won', 'won_at' => date('Y-m-d H:i', time() - 3 * 86400)]);
    $oldWon = mk_lead(['status' => 'won', 'won_at' => date('Y-m-d H:i', time() - 60 * 86400)]);
    $asked = mk_lead(['status' => 'won', 'won_at' => date('Y-m-d H:i', time() - 3 * 86400), 'postsign' => ['x']]);
    pm_t_eq(array_column(pm_postsign_due([$won, $oldWon, $asked], 3), 'id'), [$won['id']], 'post-sign picks recent wins not yet asked');
    $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode([['id' => $won['id'], 'testimonial' => 'May we quote you?', 'review' => 'Please review us', 'referral' => 'Know anyone else?']]);
    $ps = pm_agent_postsign([$won]);
    pm_t_assert(!empty($ps[0]['testimonial'] ?? ''), 'post-sign returns testimonial text');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Research queue', function () {
    $cfg = pm_agents_config();
    $soon = mk_lead(['status' => 'contacted', 'score' => 80, 'last_contacted' => date('Y-m-d H:i', time() - (($cfg['followup_days'] ?? 4) - 1) * 86400)]);
    $later = mk_lead(['status' => 'contacted', 'score' => 80, 'last_contacted' => date('Y-m-d H:i', time() - 1 * 86400)]);
    $done = mk_lead(['status' => 'contacted', 'score' => 80, 'last_contacted' => date('Y-m-d H:i', time() - 30 * 86400), 'research' => ['discoveries' => []]]);
    $proposal = mk_lead(['status' => 'replied', 'score' => 70, 'want_proposal' => true]);
    $got = array_column(pm_research_queue([$soon, $later, $done, $proposal], 2), 'id');
    pm_t_assert(in_array($soon['id'], $got, true) && in_array($proposal['id'], $got, true), 'follow-up-bound and proposal-bound leads are queued');
    pm_t_assert(!in_array($later['id'], $got, true) && !in_array($done['id'], $got, true), 'early and already-researched leads stay out');
});

t('Engagement-aware follow-ups', function () {
    pm_t_eq(pm_lead_engagement(['last_reply' => '2026-01-01']), 'replied', 'replied');
    pm_t_eq(pm_lead_engagement(['view_count' => 2]), 'opened', 'opened');
    pm_t_eq(pm_lead_engagement([]), 'silent', 'silent');
    $GLOBALS['PM_AI_STUB'] = fn($sys, $user) => json_encode([['id' => 'x1', 'email_subject' => 's', 'email_body' => 'b', 'whatsapp' => 'w']]);
    $out = pm_agent_followup([['id' => 'x1', 'name' => 'N', 'view_count' => 3, 'drafts' => ['email_body' => 'old']]]);
    pm_t_eq($out[0]['id'] ?? '', 'x1', 'follow-up still returns rows');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Unified inbox sync', function () {
    $threads = [
        ['psid' => '111', 'who' => 'Test Business A', 'messages' => [
            ['ours' => false, 'at' => '2026-10-01T10:00:00+02:00', 'text' => 'Hello?'],
            ['ours' => true, 'at' => '2026-10-01T10:05:00+02:00', 'text' => 'Hi, how can we help?'],
        ]],
        ['psid' => '222', 'who' => 'Somebody Else', 'messages' => [['ours' => false, 'at' => 'x', 'text' => 'nope']]],
    ];
    $lead = ['name' => 'test business a', 'psid' => ''];
    $rows = pm_lead_fb_threads($lead, $threads);
    pm_t_eq(count($rows), 2, 'name match finds the thread');
    pm_t_eq($rows[0]['ch'], 'fb', 'rows are tagged facebook');
    $lead2 = ['name' => 'Unrelated Co', 'psid' => '222'];
    pm_t_eq(count(pm_lead_fb_threads($lead2, $threads)), 1, 'psid match works');
    pm_t_eq(pm_lead_fb_threads(['name' => 'Nobody'], $threads), [], 'no match, no rows');
});

t('Deliverability verdicts', function () {
    $v = pm_deliv_verdict(['v=spf1 include:x ~all', 'v=DMARC1; p=reject', 'v=DKIM1; k=rsa; p=abc']);
    pm_t_eq([$v['spf'], $v['dmarc'], $v['dkim'], $v['ok']], ['pass', 'pass', 'pass', true], 'all pass');
    $v = pm_deliv_verdict(['v=spf1 +all']);
    pm_t_eq([$v['spf'], $v['dmarc'], $v['dkim']], ['fail', 'missing', 'missing'], 'weak setup is flagged');
    pm_t_assert(!$v['ok'], 'weak setup is not ok');
});

t('Adaptive send caps', function () {
    $mk = fn($bounces) => array_map(fn($i) => mk_lead(['sent' => [date('Y-m-d H:i', time() - 86400)]] + ($bounces && $i % 5 === 0 ? ['bounced_at' => date('Y-m-d H:i', time() - 3600)] : [])), range(1, 20));
    pm_save('leads', $mk(true));
    pm_ob_update('send_caps', fn() => []);
    $c1 = pm_adaptive_send_cap(10, 'promanaged');
    pm_t_assert($c1 < 10, 'a bouncy week pulls the cap down: ' . $c1);
    pm_save('leads', $mk(false));
    pm_ob_update('send_caps', fn() => []);
    $c2 = pm_adaptive_send_cap(10, 'promanaged');
    pm_t_assert($c2 === 10, 'a clean week keeps the ceiling: ' . $c2);
    pm_t_eq(pm_bounce_rate7($mk(true), 'promanaged')['bounces'], 4, 'bounces are counted');
});

t('Privacy: forget and export', function () {
    $lead = mk_lead(['name' => 'Forget Me Ltd']);
    pm_save('leads', ['fm1' => $lead]);
    pm_save('history', [['business' => 'Forget Me Ltd', 'status' => 'Signed', 'token' => 'x'], ['business' => 'Keep Me', 'status' => 'Sent', 'token' => 'y']]);
    $r = pm_lead_forget('fm1');
    pm_t_assert($r['ok'], 'forget works: ' . $r['msg']);
    pm_t_assert(!isset(pm_leads()['fm1']), 'lead is gone');
    $h = pm_history();
    pm_t_assert(str_contains($h[0]['business'] ?? '', 'Former client'), 'history row anonymised');
    pm_t_eq($h[1]['business'] ?? '', 'Keep Me', 'other rows untouched');
    $out = pm_data_export('promanaged');
    $j = json_decode($out, true);
    pm_t_assert(isset($j['leads']) && isset($j['settings']) && !isset($j['settings']['smtp']), 'export has leads and settings, never smtp');
    pm_t_assert(!str_contains($out, 'SMTP_PASS'), 'export carries no secrets');
});

t('Shared learnings', function () {
    pm_save('social_learnings_shared', ['rows' => []]);
    pm_t_assert(pm_shared_learnings_promote('[travel] WhatsApp us beat Ask a question'), 'first promote works');
    pm_t_assert(!pm_shared_learnings_promote('[travel] WhatsApp us beat Ask a question'), 'duplicates are refused');
    pm_t_eq(count(pm_shared_learnings()), 1, 'one shared learning');
});

t('Director focus weights', function () {
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['headline' => 'H', 'priorities' => [], 'focus' => [], 'drop' => [], 'experiment' => 'e',
        'focus_weights' => ['Hotels & lodges' => 90, 'made up type' => 50], 'social_focus' => 'mix', 'social_note' => 'n']);
    $b = pm_director_brief(true);
    $w = pm_director_focus_weights();
    pm_t_assert(isset($w['hotels and lodges']) && $w['hotels and lodges'] === 90, 'valid weights kept (normalised matching)');
    pm_t_assert(!isset($w['made up type']), 'unknown types dropped');
    pm_t_assert(isset($b['focus_weights']), 'brief stores the weights');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Trends radar', function () {
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['items' => ['Fuel prices are up this week', 'Schools open next Monday', 'Rain expected in the South']]);
    $msg = pm_jobg_trends();
    pm_t_assert(str_contains($msg, 'stored'), 'trends job stored: ' . $msg);
    $t = pm_social_trends();
    pm_t_eq(count($t['items']), 3, 'three trend items');
    pm_t_assert(str_contains(pm_trend_line(), 'Fuel prices'), 'trend line feeds the planner');
    pm_t_assert(str_contains(pm_jobg_trends(), 'already refreshed'), 'same-day job is gated');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('Auto-repurposing', function () {
    pm_save('social_posts', []);
    $p = pm_t_fixture_post(['status' => 'published', 'published' => date('Y-m-d H:i', time() - 20 * 86400), 'when' => date('Y-m-d H:i', time() - 20 * 86400),
        'fb_id' => '999_' . bin2hex(random_bytes(3)), 'caption' => 'Closing the shop takes an hour every day. Here is a simpler routine. Try it tonight. Tell us how it goes in the comments below.',
        'metrics' => ['d7' => ['at' => date('Y-m-d H:i'), 'reactions' => 40, 'comments' => 2, 'shares' => 1, 'reach' => null, 'clicks' => null, 'saves' => null, 'eng' => 50]], 'metrics_src' => 'api']);
    $c = pm_repurpose_candidates('promanaged');
    pm_t_eq(count($c), 1, 'the top performer is a candidate');
    $made = pm_repurpose_make($c[0], 1);
    pm_t_eq($made['format'], 'carousel', 'carousel re-cut');
    pm_t_eq(count($made['slides']), 3, 'three slides from the caption');
    pm_t_eq(pm_repurpose_make($c[0], 0)['format'], 'story', 'story re-cut');
    pm_t_eq(pm_repurpose_make($c[0], 2)['format'], 'text', 'text re-cut');
    $msg = pm_job_repurpose('promanaged');
    pm_t_assert(str_contains($msg, 'repurposed draft'), 'repurpose job ran: ' . $msg);
    $new = array_values(array_filter(pm_social_posts(), fn($x) => ($x['repurposed_from'] ?? '') === $p['id']));
    pm_t_eq(count($new), 2, 'two fresh drafts');
    pm_t_assert(in_array($new[0]['status'] ?? '', ['draft', 'needs_edit'], true), 'drafts only, never auto-published');
});

t('Proactive engagement drafts', function () {
    $GLOBALS['PM_AI_STUB'] = fn() => json_encode(['items' => [
        ['where' => 'Malawi business groups', 'comment' => 'A tip for anyone counting stock by hand: photograph the shelf labels once a week and compare from the photos. It really does save an afternoon.'],
        ['where' => 'Local restaurant pages', 'comment' => 'Your new menu looks great, and the photo of the grilled fish is making me hungry. Wishing you a busy weekend full of customers.'],
        ['where' => 'Hotel pages', 'comment' => 'Beautiful view from the rooms, and the rates are clearly shown. Adding your WhatsApp number to the About box would help guests book even faster.'],
    ]]);
    $msg = pm_jobg_proactive();
    pm_t_assert(str_contains($msg, 'suggestion'), 'proactive job ran: ' . $msg);
    $rows = pm_proactive('promanaged');
    pm_t_eq(count($rows), 3, 'three linted suggestions stored');
    pm_t_eq(count(pm_today_proactive('promanaged')), 1, 'a today item points the owner at them');
    $GLOBALS['PM_AI_STUB'] = null;
});

t('X posting', function () use ($T) {
    pm_t_assert(!pm_x_cfg()['ready'], 'X off without keys');
    pm_t_eq(pm_x_post('hello')[0], false, 'no send without keys');
    pm_t_eq(pm_job_channels_x('promanaged'), 'X not connected', 'job gated');
    putenv('X_API_KEY=k');
    putenv('X_API_SECRET=s');
    putenv('X_ACCESS_TOKEN=t');
    putenv('X_ACCESS_SECRET=ts');
    pm_t_assert(pm_x_cfg()['ready'], 'X ready with keys');
    $seen = [];
    $GLOBALS['PM_X_STUB'] = function (string $text) use (&$seen) {
        $seen[] = $text;
        return [true, '12345'];
    };
    [$ok, $mid] = pm_x_post('Test tweet');
    pm_t_assert($ok && $mid === '12345' && $seen === ['Test tweet'], 'stubbed post goes through');
    pm_t_assert(str_starts_with(pm_x_sign('POST', 'https://api.x.com/2/tweets', [], pm_x_cfg()), 'OAuth '), 'oauth header builds');
    $GLOBALS['PM_X_STUB'] = null;
});

t('Audience segments', function () {
    pm_t_eq(pm_pro_audience(['brand' => 'promanaged', 'type' => 'hotel and restaurant', 'name' => 'X']), 'Hotels & lodges', 'hotel type groups');
    pm_t_eq(pm_pro_audience(['brand' => 'promanaged', 'type' => 'pharmacy', 'name' => 'Y']), 'Health & pharmacies', 'pharmacy groups');
    pm_save('leads', ['a1' => mk_lead(['type' => 'pharmacy', 'name' => 'P Ltd', 'created' => date('Y-m-d H:i', time() - 5 * 86400)])]);
    pm_t_assert(str_contains(pm_job_audience_segments('promanaged'), 'tagged'), 'segment job tags leads');
    pm_t_eq(pm_leads()['a1']['audience'] ?? '', 'Health & pharmacies', 'audience stamped on the lead');
    pm_t_assert(str_contains(pm_audience_line('promanaged'), 'Health & pharmacies'), 'audience line feeds the planner');
});

t('Custom experiments', function () {
    pm_save('social_experiments', ['rows' => []]); // the harness copies real data; start clean
    $e = pm_experiment_start_custom('promanaged', ['slug' => 'longvshort', 'hypothesis' => 'Short captions beat long ones', 'field' => 'cta',
        'min_posts' => 4, 'A' => ['label' => 'Short caption', 'value' => 'short', 'instruction' => 'Caption: under 30 words'],
        'B' => ['label' => 'Long caption', 'value' => 'long', 'instruction' => 'Caption: 60 to 80 words']]);
    pm_t_assert($e && $e['status'] === 'active' && $e['field'] === 'cta', 'custom experiment starts');
    pm_t_assert(pm_experiment_start_custom('promanaged', ['slug' => 'x', 'hypothesis' => 'h', 'field' => 'bogus',
        'A' => ['label' => 'a', 'instruction' => 'i'], 'B' => ['label' => 'b', 'instruction' => 'j']]) === null, 'unknown field refused');
    pm_t_assert(pm_experiment_start_custom('promanaged', ['slug' => 'x', 'hypothesis' => 'h', 'field' => 'hour',
        'A' => ['label' => 'a', 'instruction' => 'i'], 'B' => ['label' => 'b', 'instruction' => 'j']]) === null, 'only one at a time');
    $slot = pm_experiment_for_slot('promanaged', 0);
    pm_t_assert($slot && str_contains($slot['instruction'], 'Caption'), 'slot instruction carries the custom arm');
});

t('Ads trend hints', function () {
    $g = ['max_cost_per_conv_mwk' => 1000];
    $cur = ['spend' => 5000.0, 'conversations' => 4];
    $prev = ['spend' => 5000.0, 'conversations' => 10];
    $h = pm_sx_ads_trend_hints($cur, $prev, $g);
    pm_t_assert(count($h) === 1 && str_contains($h[0], 'refresh'), 'falling conversations trigger a creative refresh hint');
    $h2 = pm_sx_ads_trend_hints(['spend' => 2000.0, 'conversations' => 10], ['spend' => 1000.0, 'conversations' => 5], $g);
    pm_t_assert(count($h2) === 1 && str_contains($h2[0], 'more budget'), 'cheap conversations suggest more budget');
    pm_t_eq(pm_sx_ads_trend_hints(['spend' => 0, 'conversations' => 0], ['spend' => 0, 'conversations' => 0], $g), [], 'no noise on empty data');
});

t('Plan learnings composition', function () {
    pm_save('leads', ['b1' => mk_lead(['type' => 'hotel and restaurant', 'name' => 'H Ltd', 'status' => 'won', 'created' => date('Y-m-d H:i', time() - 5 * 86400)])]);
    pm_save('social_trends', ['day' => date('Y-m-d'), 'items' => ['Schools open Monday']]);
    pm_save('social_learnings_shared', ['rows' => ['[travel] WhatsApp us beat Ask a question']]);
    $line = pm_plan_learnings('promanaged');
    pm_t_assert(str_contains($line, 'Schools open Monday'), 'trends are in the planner input');
    pm_t_assert(str_contains($line, 'Cross-brand'), 'shared learnings are in the planner input');
    pm_t_assert(str_contains($line, 'Hotels & lodges'), 'audience mix is in the planner input');
    $panel = pm_panel_plan_top_strategy('promanaged');
    pm_t_assert(str_contains($panel, 'Why this week') && !str_contains($panel, 'Warning:'), 'strategy panel renders');
});

t('WhatsApp Business responder', function () {
    putenv('WA_BIZ_TOKEN=tok');
    putenv('WA_BIZ_PHONE_ID=999');
    putenv('WA_BIZ_VERIFY=verify-me');
    pm_t_assert(pm_wa_biz_cfg()['ready'], 'WA business ready with keys');
    pm_t_eq(pm_wa_biz_norm('+265 999 123 456'), '265999123456', 'number normalised');
    pm_t_eq(pm_wa_biz_norm('00265 999 123 456'), '265999123456', '00 prefix stripped');
    $lead = mk_lead(['id' => 'w1', 'whatsapp' => '+265 999 123 456', 'name' => 'Whats Co']);
    pm_save('leads', ['w1' => $lead]);
    $found = pm_wa_biz_find_lead(pm_leads(), '265999123456');
    pm_t_assert($found && ($found['id'] ?? '') === 'w1', 'number matches the lead');
    pm_t_assert(pm_wa_biz_find_lead(pm_leads(), '265888000000') === null, 'unknown number matches nobody');
    $sent = [];
    $GLOBALS['PM_WA_STUB'] = function (string $to, string $text) use (&$sent) {
        $sent[] = [$to, $text];
        return [true, 'wamid.1'];
    };
    pm_t_eq(pm_wa_biz_handle('265888000000', 'hello?'), 'unknown number: logged as unmatched', 'unknown numbers logged');
    pm_t_eq(pm_wa_biz_handle('265999123456', 'STOP please'), 'STOP honoured', 'STOP is honoured on WhatsApp');
    pm_t_eq(pm_leads()['w1']['status'] ?? '', 'optout', 'lead is now optout');
    pm_t_assert(count($sent) === 1, 'only the STOP confirmation went out');
    pm_t_assert(str_contains($sent[0][1], 'will not contact'), 'STOP confirmation text');
    $GLOBALS['PM_WA_STUB'] = null;
});

pm_t_done();



