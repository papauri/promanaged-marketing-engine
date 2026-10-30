<?php
/**
 * The promotion sit-down: three times a week (Monday, Wednesday and Friday, the first scheduler run after 08:00), or when the owner asks, the department heads
 * sit down to decide how to promote the business over the next few days. Amara (Director) chairs; each head brings what they see. One cheap AI call, from
 * plain numbers the code gathers (the pipeline, who is waiting, new customers, the planned posts, what sells and what does not, the heads behind on their
 * goal) and the business's own facts; a meeting with nothing new since the last one is skipped, so it costs nothing.
 * It decides three to five moves, each owned by one department and pointing at the page where it is done. Each move becomes that head's order on the team
 * board (every agent reads its orders), the talk goes into the agents' conversation (Audit), and the Overview shows the moves with Done.
 * Between meetings the heads check in every three hours (code only, no AI): their goals are checked again, the ones behind ask for help, and Today's board is rebuilt.
 * Data: data/huddle.json {brand: {at, by, hash, moves: [{dept, what, why, where, done}], lines: [[dept, says]], checkin}}.
 */

/** Where a move is done: key => [label, link]. */
const PM_HD_WHERE = ['leads' => ['Leads', '?tab=agents'], 'followups' => ['Follow-ups', '?tab=agents&status=followups'], 'whatsapp' => ['WhatsApp', '?tab=whatsapp'],
    'plan' => ['Social plan', '?tab=social'], 'campaign' => ['Campaigns', '?tab=social&view=campaign'], 'onepager' => ['One-page ad', '?tab=social&view=explainer'],
    'scouting' => ['Scouting', '?tab=social&view=scout'], 'socialleads' => ['Social leads', '?tab=social&view=leads'], 'content' => ['Content', '?tab=social&view=content'],
    'profiles' => ['Profiles', '?tab=social&view=optimise'], 'ads' => ['Google Ads', '?tab=social&view=gads'], 'targeting' => ['Targeting', '?tab=targeting']];

/** The heads who take moves. */
const PM_HD_DEPTS = ['director', 'scouts', 'research', 'contact', 'writers', 'sender', 'followup', 'replies', 'social'];

function pm_hd_state(string $brand): array
{
    return (array)(pm_load('huddle', fn() => [])[$brand] ?? []) + ['at' => '', 'by' => '', 'hash' => '', 'moves' => [], 'lines' => [], 'checkin' => ''];
}

function pm_hd_put(string $brand, array $st): void
{
    pm_update('huddle', function (array $all) use ($brand, $st) {
        $all[$brand] = $st;
        return $all;
    }, fn() => []);
}

/** What the heads bring to the table, in plain lines (code, free). */
function pm_hd_inputs(string $brand): array
{
    $L = array_filter(pm_leads_read(), fn($l) => pm_brand_norm((string)($l['brand'] ?? 'promanaged')) === $brand && empty($l['listen']));
    $by = array_count_values(array_map(fn($l) => (string)($l['status'] ?? 'new'), $L));
    $in = [];
    $in[] = 'Leads by stage: ' . implode(', ', array_map(fn($k, $n) => (PM_LEAD_STATUSES[$k] ?? $k) . " $n", array_keys($by), $by)) . '.';
    $wait = array_filter($L, fn($l) => !empty($l['awaiting_reply_since']) && ($l['status'] ?? '') !== 'optout');
    $fu = array_filter($L, fn($l) => ($l['status'] ?? '') === 'contacted' && !empty($l['followup_draft']));
    $in[] = count($wait) . ' waiting for our answer; ' . count($fu) . ' follow-ups written and waiting to go.';
    $won = array_filter($L, fn($l) => ($l['status'] ?? '') === 'won');
    if ($won) {
        $in[] = 'Customers: ' . implode(', ', array_map(fn($l) => (string)$l['name'] . (!empty($l['live_listing']) ? ' (live on our website)' : '') . (empty($l['welcome_sent']) ? ' (not welcomed yet)' : ''), array_slice($won, 0, 6))) . '.';
    }
    $hint = array_filter($L, fn($l) => !empty($l['win_hint']) && ($l['status'] ?? '') !== 'won');
    $hint && $in[] = 'Look won, waiting for the owner to confirm: ' . implode(', ', array_map(fn($l) => (string)$l['name'], array_slice($hint, 0, 4))) . '.';
    $posts = array_filter((array)pm_load('social_posts', fn() => []), fn($p) => is_array($p) && ($p['brand'] ?? '') === $brand);
    $ps = array_count_values(array_map(fn($p) => (string)($p['status'] ?? ''), $posts));
    $in[] = 'Posts: ' . (int)($ps['approved'] ?? 0) . ' approved, ' . (int)($ps['draft'] ?? 0) . ' drafts, ' . (int)($ps['needs_edit'] ?? 0) . ' held for new words, '
        . count(array_filter($posts, fn($p) => in_array($p['status'] ?? '', ['posted', 'published'], true) && strtotime((string)($p['when'] ?? '')) > strtotime('-7 days'))) . ' posted in the last 7 days.';
    $down = (array)(pm_social_state()['auth_down'] ?? []);
    !empty($down[$brand]) && $in[] = 'Publishing to Facebook is paused: the Page connection needs renewing.';
    if (function_exists('pm_adv_director_lines')) {
        foreach (array_slice(pm_adv_director_lines($brand), 0, 4) as $a) {
            $in[] = strtr($a, ['Double down on (' => 'Sells, do more of it (', 'Drop (' => 'Does not sell, stop aiming at it (', 'Why deals were lost' => 'Why deals were lost']);
        }
    }
    foreach ((array)(pm_load('heads', fn() => [])[$brand]['goals'] ?? []) as $dept => $g) {
        if (empty($g['ok'])) {
            $in[] = 'Behind on goal: ' . (PM_AGENT_ROLES[$dept] ?? $dept) . ': ' . (string)($g['goal'] ?? '') . ', now ' . (string)($g['have'] ?? '') . '.';
        }
    }
    $last = pm_hd_state($brand);
    if ($last['moves']) {
        $in[] = 'Last sit-down (' . substr((string)$last['at'], 0, 10) . '): ' . implode(' | ', array_map(fn($m) => (!empty($m['done']) ? 'done: ' : 'not done: ') . $m['what'], $last['moves'])) . '.';
    }
    return $in;
}

/**
 * Holds the sit-down. $force: the owner asked (held even when nothing changed). Returns [ok, message].
 */
function pm_hd_hold(string $brand, bool $force = false, string $by = 'scheduler'): array
{
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        return [false, 'The sit-down needs an AI key (Operations > Connections).'];
    }
    function_exists('pm_heads_check') && pm_heads_check($brand); // the goals as they stand now, not as they stood this morning
    $in = pm_hd_inputs($brand);
    $hash = md5(json_encode(array_slice($in, 0, -1))); // the last line (the previous moves) changes every time: it does not count
    $st = pm_hd_state($brand);
    if (!$force && $hash === $st['hash'] && strtotime((string)$st['at']) > strtotime('-6 days')) {
        return [true, 'Nothing new since the last sit-down: no meeting needed.'];
    }
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $s = pm_settings();
        $heads = implode(', ', array_map(fn($d) => pm_agent_first($d) . ' (' . (PM_AGENT_ROLES[$d] ?? $d) . ", key $d)", PM_HD_DEPTS));
        $sys = pm_agents_company_brief('tiny') . "\nThis is the promotion sit-down of {$s['company_name']}'s department heads: $heads. " . pm_agent_first('director') . ' chairs. '
            . 'Decide 3 to 5 moves for the next few days that promote the business and bring in customers: what to post or say, whom to reach, who to follow up or welcome, which offer to push. '
            . 'Each move is concrete, can be done in the app this week, and is owned by the one head whose job it is (dept = exactly one of ' . implode(', ', PM_HD_DEPTS) . '; the director only for what no other head does). where = one of: ' . implode(', ', array_keys(PM_HD_WHERE)) . '. '
            . 'why = the number from the table behind it, in a few words (for example "23 due now"). Skip a move that only checks on the others. Put first what brings a customer soonest. A new customer not welcomed yet is welcomed first (where = leads), and a customer live with us is proof worth one post (ask them first). Never aim at what the table says does not sell. Never repeat a move marked done; a move not done may come back if it still matters. '
            . 'Use only the facts above and the table: no new numbers, prices, dates, features or claims. lines: 4 to 6 short lines, each head that owns a move speaking for itself (who = its dept key), the director last with the decision. ' . PM_AGENT_RULES;
        $out = pm_agent_json(pm_claude($sys, "The table:\n- " . implode("\n- ", $in) . "\nJSON: {\"moves\":[{\"dept\":\"\",\"what\":\"\",\"why\":\"\",\"where\":\"\"}],\"lines\":[{\"who\":\"\",\"says\":\"\"}]}", false, 1100, 'cheap'));
    } catch (Throwable $e) {
        pm_brand_set($was);
        return [false, 'The sit-down could not be held: ' . $e->getMessage()];
    }
    pm_brand_set($was);
    $dept = function ($v): string { // the key, the role or the first name: all mean the same head
        $v = mb_strtolower(trim(is_scalar($v) ? (string)$v : ''));
        foreach (PM_HD_DEPTS as $d) {
            if (in_array($v, [$d, mb_strtolower(PM_AGENT_ROLES[$d] ?? $d), mb_strtolower(pm_agent_first($d))], true) || str_starts_with($v, mb_strtolower(pm_agent_first($d)) . ' ')) {
                return $d;
            }
        }
        return 'director';
    };
    $clean = fn($v, int $n) => function_exists('pm_swarm_clean') ? pm_swarm_clean($v, $n) : mb_substr(trim(strip_tags(is_scalar($v) ? (string)$v : '')), 0, $n);
    if (is_array($out) && array_is_list($out)) { // the moves alone, without the wrapper
        $out = ['moves' => $out];
    }
    $moves = [];
    foreach ((array)($out['moves'] ?? []) as $m) {
        $what = $clean($m['what'] ?? '', 220);
        if (!is_array($m) || $what === '') {
            continue;
        }
        $moves[] = ['dept' => $dept($m['dept'] ?? ''), 'what' => $what, 'why' => $clean($m['why'] ?? '', 140),
            'where' => isset(PM_HD_WHERE[$m['where'] ?? '']) ? (string)$m['where'] : '', 'done' => ''];
        if (count($moves) === 5) {
            break;
        }
    }
    if (!$moves) {
        return [false, 'The heads did not agree on any moves this time. Try again.'];
    }
    $lines = [];
    foreach ((array)($out['lines'] ?? []) as $l) {
        if (is_array($l) && ($t = $clean($l['says'] ?? '', 240)) !== '') {
            $lines[] = [$dept($l['who'] ?? ''), $t];
        }
    }
    pm_hd_put($brand, ['at' => date('Y-m-d H:i'), 'by' => $by, 'hash' => $hash, 'moves' => $moves, 'lines' => array_slice($lines, 0, 6), 'checkin' => $st['checkin']]);
    if (function_exists('pm_tb_post')) {
        for ($i = 0; $i < 5; $i++) {
            if (isset($moves[$i])) {
                pm_tb_post($brand, 'director', $moves[$i]['dept'], 'From the promotion sit-down: ' . $moves[$i]['what'] . ($moves[$i]['why'] !== '' ? ' (' . $moves[$i]['why'] . ')' : ''), 'huddle:' . $i);
            } else {
                pm_tb_take($brand, 'huddle:' . $i); // fewer moves than last time: the old order comes down
            }
        }
    }
    if (function_exists('pm_talk')) {
        foreach ($lines as [$who, $t]) {
            pm_talk($brand, $who, $t, $who === 'director' ? '' : 'director');
        }
    }
    pm_agent_log('Director', 'Promotion sit-down: ' . implode(' | ', array_map(fn($m) => $m['what'], $moves)));
    return [true, 'The heads sat down and agreed ' . count($moves) . ' moves. Each head has their order.'];
}

/** Scheduler job: the sit-down on Monday, Wednesday and Friday (first run after 08:00), and the heads' check-in every three hours. */
function pm_job_huddle(string $brand): string
{
    $st = pm_hd_state($brand);
    $out = '';
    if ((strtotime((string)$st['checkin']) ?: 0) < time() - 10800 && (int)date('G') >= 7 && (int)date('G') < 20) { // between meetings, no AI
        function_exists('pm_heads_check') && pm_heads_check($brand);
        function_exists('pm_board_refresh') && pm_board_refresh($brand);
        $st['checkin'] = date('Y-m-d H:i');
        pm_hd_put($brand, $st);
    }
    if (in_array((int)date('N'), [1, 3, 5], true) && (int)date('G') >= 8 && substr((string)$st['at'], 0, 10) !== date('Y-m-d')) {
        [$ok, $msg] = pm_hd_hold($brand);
        $out = $ok ? $msg : 'sit-down: ' . $msg;
        if ($ok && substr((string)pm_hd_state($brand)['at'], 0, 10) !== date('Y-m-d')) { // nothing new: mark the day, so it is not asked again every half hour
            pm_update('huddle', function (array $all) use ($brand) {
                $all[$brand]['at'] = date('Y-m-d H:i');
                return $all;
            }, fn() => []);
        }
    }
    return $out;
}

/* ---------------- the owner ---------------- */

function pm_do_hd_now(string $vb): array
{
    @set_time_limit(90);
    [$ok, $msg] = pm_hd_hold($vb, true, (string)($GLOBALS['PM_WHO'] ?? '') ?: 'owner');
    return ['msg' => $msg, 'kind' => $ok ? 'ok' : 'err', 'to' => 'home#huddle'];
}

function pm_do_hd_done(string $vb): array
{
    $i = (int)($_POST['i'] ?? -1);
    $st = pm_hd_state($vb);
    if (!isset($st['moves'][$i])) {
        return ['msg' => 'That move is no longer there.', 'kind' => 'err', 'to' => 'home#huddle'];
    }
    $st['moves'][$i]['done'] = empty($st['moves'][$i]['done']) ? date('Y-m-d H:i') : '';
    pm_hd_put($vb, $st);
    if (function_exists('pm_tb_take') && $st['moves'][$i]['done'] !== '') {
        pm_tb_take($vb, 'huddle:' . $i); // done: the head stops working on it
    }
    return ['msg' => $st['moves'][$i]['done'] !== '' ? 'Marked done.' : 'Back on the list.', 'to' => 'home#huddle'];
}

/** The card on the Overview. */
function pm_hd_card(string $vb, string $csrf): string
{
    $st = pm_hd_state($vb);
    $form = fn(string $do, string $extra, string $btn) => '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . $do . '">' . $extra . $btn . '</form>';
    $next = '';
    foreach (range(0, 7) as $d) {
        $t = strtotime("+$d days");
        if (in_array((int)date('N', $t), [1, 3, 5], true) && date('Y-m-d', $t) !== substr((string)$st['at'], 0, 10)) {
            $next = $d === 0 ? 'today' : date('D j M', $t);
            break;
        }
    }
    $h = '<section class="card" id="huddle"><div class="card-head"><h2>Promotion sit-down <span class="muted t-sm">· ' . ($st['at'] !== '' ? 'held ' . pm_h(date('D j M, H:i', strtotime((string)$st['at']))) . ' · ' : '') . 'next ' . pm_h($next) . '</span></h2>'
        . $form('hd_now', '', '<button class="btn small" data-busy title="The heads sit down now and agree the next moves (one small AI call)">Hold one now</button>') . '</div>';
    if (!$st['moves']) {
        return $h . '<p class="hint mb-0">Three times a week the heads decide how to promote the business over the next few days; their moves show here.</p></section>';
    }
    $h .= '<ul class="hd-moves">';
    foreach ($st['moves'] as $i => $m) {
        $w = PM_HD_WHERE[$m['where']] ?? null;
        $h .= '<li class="' . ($m['done'] !== '' ? 'done' : '') . '"><span class="chip">' . pm_h(pm_agent_first($m['dept'])) . '</span><span class="grow"><b>' . pm_h($m['what']) . '</b>'
            . ($m['why'] !== '' ? '<small class="muted"> · ' . pm_h($m['why']) . '</small>' : '') . '</span>'
            . ($w ? '<a class="btn small ghost" href="' . pm_h($w[1]) . '">' . pm_h($w[0]) . '</a>' : '')
            . $form('hd_done', '<input type="hidden" name="i" value="' . $i . '">', '<button class="btn small' . ($m['done'] !== '' ? ' ghost' : '') . '">' . ($m['done'] !== '' ? 'Undo' : 'Done') . '</button>') . '</li>';
    }
    $h .= '</ul>';
    if ($st['lines']) {
        $h .= '<details class="more"><summary>How the heads talked it over</summary><ul class="talklist">';
        foreach ($st['lines'] as [$who, $t]) {
            $h .= '<li><b>' . pm_h(pm_talk_name($who)) . ':</b><span>' . pm_h($t) . '</span></li>';
        }
        $h .= '</ul></details>';
    }
    return $h . '</section>';
}
