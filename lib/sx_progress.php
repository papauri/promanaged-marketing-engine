<?php
/**
 * Customer progress: after someone says yes, the owner keeps them posted on what they bought (a website, a system, an order, a Travel Malawi listing).
 * The steps are the ones their proposal promised ("Getting started" in Template and prices, by business line: for Travel Malawi create the account,
 * add the property, review, go live, receive requests). On the lead the owner marks where it stands (in progress, waiting for them, done) with an optional
 * note or link; the Follow-up head writes the update straight away, by code, no AI (Talk it over can rework it), and it goes out only when the owner
 * presses Send (by email, in their thread, with the usual signature) or WhatsApp.
 * The swarm hears it: the Director gets each change on the team board, and when a customer goes live Social is told so it can welcome them on the Page.
 * The first mark makes the lead Won. Data on the lead: progress {steps, at, state, note, msg {subject, body, whatsapp}, log [{at, step, state, sent}]}.
 */

const PM_PG_STATES = ['working' => 'In progress', 'waiting' => 'Waiting for them', 'done' => 'Done'];

/** The steps the lead was promised: [[name, when, what], ...]. A business added in the app (no proposals) gets four plain ones. */
function pm_pg_steps(array $lead): array
{
    if (!empty($lead['progress']['steps'])) {
        return (array)$lead['progress']['steps'];
    }
    $b = pm_brand_norm((string)($lead['brand'] ?? 'promanaged'));
    if (!pm_brand_is_custom($b)) {
        $tpl = pm_template();
        $pk = $b === 'travel' ? pm_onboarding_index() : (int)($lead['package'] ?? 0);
        $type = (string)($tpl['packages'][$pk]['type'] ?? '');
        $rows = array_values(array_filter((array)(pm_apply_line($tpl, $type)['steps'] ?? []), fn($r) => trim((string)($r[0] ?? '')) !== ''));
        if ($rows) {
            return array_map(fn($r) => [trim((string)$r[0]), trim((string)($r[1] ?? '')), trim((string)($r[2] ?? ''))], array_slice($rows, 0, 8));
        }
    }
    return [['Getting started', 'First', 'We agree what is needed and start.'], ['In progress', 'Main part', 'We do the work and keep you posted.'],
        ['Ready for you', 'Before the end', 'You check it and we make any changes.'], ['Done', 'Final', 'It is delivered and ready to use.']];
}

/** What they bought, as said in a message: "your listing of X on Travel Malawi", "the Business Website for X". */
function pm_pg_thing(array $lead): string
{
    $b = pm_brand_norm((string)($lead['brand'] ?? 'promanaged'));
    $name = trim((string)($lead['name'] ?? ''));
    if ($b === 'travel') {
        return 'your listing of ' . $name . ' on ' . pm_brand_name('travel');
    }
    $pk = (string)(pm_template()['packages'][(int)($lead['package'] ?? -1)]['name'] ?? '');
    return pm_brand_is_custom($b) || $pk === '' ? 'your work with ' . pm_brand_name($b) : 'the ' . $pk . ' for ' . $name;
}

/** Is this step the one where it goes live (the update says "you are live")? */
function pm_pg_is_live(string $step): bool
{
    return (bool)preg_match('/\b(go(es)? live|live|launch(ed)?)\b/i', $step);
}

/** The update for where it stands now: ['subject', 'body' (no sign-off: the signature is added), 'whatsapp' (no greeting or sign-off: added when sent)]. Code only. */
function pm_pg_message(array $lead, array $pg): array
{
    $steps = pm_pg_steps($lead);
    $i = max(0, min(count($steps) - 1, (int)($pg['at'] ?? 0)));
    $state = (string)($pg['state'] ?? 'working');
    [$step, , $what] = $steps[$i] + ['', '', ''];
    $next = $steps[$i + 1] ?? null;
    $last = $next === null;
    $note = trim((string)($pg['note'] ?? ''));
    $thing = pm_pg_thing($lead);
    $Thing = mb_strtoupper(mb_substr($thing, 0, 1)) . mb_substr($thing, 1);
    $live = $state === 'done' && pm_pg_is_live($step);
    $contact = trim(preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', trim((string)($lead['contact'] ?? ''))));
    $first = pm_contact_name_ok($contact) ? (explode(' ', $contact)[0] ?? '') : '';
    $what = rtrim($what, '.') !== '' ? rtrim($what, '.') . '.' : '';
    if ($live) {
        $open = "Good news: $thing is live. $what";
        $subject = 'Good news: ' . $thing . ' is live';
    } elseif ($state === 'done' && $last) {
        $open = "Good news: $thing is complete. $step is done. $what";
        $subject = $Thing . ' is complete';
    } elseif ($state === 'done') {
        $open = "A quick update on $thing: $step is done.";
        $subject = 'Update: ' . $step . ' is done';
    } elseif ($state === 'waiting') {
        $open = "A quick update on $thing: we are at $step, and we need something from you to keep going.";
        $subject = 'We need something from you: ' . $step;
    } else {
        $open = "A quick update on $thing: we are now on $step. $what";
        $subject = 'Update: we are on ' . $step;
    }
    $then = $state === 'done' && $next ? 'Next: ' . $next[0] . '. ' . rtrim((string)$next[2], '.') . '.' : '';
    $close = $state === 'waiting' ? 'Once we have it, we carry on straight away.' : ($live || ($state === 'done' && $last) ? 'Thank you for working with us. If anything comes up, just reply to this message.' : 'We will let you know as soon as the next step is done.');
    $list = [];
    foreach ($steps as $k => $s) {
        $list[] = '- ' . $s[0] . ': ' . ($k < $i || ($k === $i && $state === 'done') ? 'done' : ($k === $i ? ($state === 'waiting' ? 'waiting for you' : 'in progress') : 'to come'));
    }
    $body = ($first !== '' ? "Hello $first," : 'Hello,') . "\n\n" . trim($open) . "\n\n" . ($note !== '' ? $note . "\n\n" : '') . ($then !== '' ? $then . "\n\n" : '')
        . "Where things stand:\n" . implode("\n", $list) . "\n\n" . $close;
    $wa = trim(trim($open) . ($note !== '' ? "\n\n" . $note : '') . ($then !== '' ? "\n\n" . $then : '') . "\n\n" . $close);
    return ['subject' => mb_substr($subject, 0, 120), 'body' => $body, 'whatsapp' => $wa];
}

/**
 * The owner marks where it stands. Writes the update (waiting to be sent), makes the lead Won on the first mark, and tells the swarm.
 * Returns the message for the owner.
 */
function pm_pg_mark(array &$lead, int $at, string $state, string $note): string
{
    $steps = pm_pg_steps($lead);
    $at = max(0, min(count($steps) - 1, $at));
    $state = isset(PM_PG_STATES[$state]) ? $state : 'working';
    $note = mb_substr(trim(str_replace("\r\n", "\n", $note)), 0, 600);
    if ($state === 'waiting' && $note === '') {
        return 'Say what you need from them in the note, so the update can ask for it.';
    }
    $pg = (array)($lead['progress'] ?? []);
    $pg = ['steps' => $steps, 'at' => $at, 'state' => $state, 'note' => $note, 'log' => (array)($pg['log'] ?? []), 'started' => (string)($pg['started'] ?? date('Y-m-d H:i'))];
    $pg['msg'] = pm_pg_message($lead, $pg);
    $pg['log'][] = ['at' => date('Y-m-d H:i'), 'step' => $steps[$at][0], 'state' => $state, 'sent' => ''];
    $pg['log'] = array_slice($pg['log'], -30);
    $lead['progress'] = $pg;
    $won = false;
    if (!in_array((string)($lead['status'] ?? ''), ['won', 'lost', 'optout'], true)) {
        $lead['status'] = 'won';
        $won = true;
    }
    $where = $steps[$at][0] . ': ' . strtolower(PM_PG_STATES[$state]);
    pm_lead_note($lead, 'Progress: ' . $where . ($note !== '' ? ' (' . mb_substr($note, 0, 80) . ')' : ''));
    $b = pm_brand_norm((string)($lead['brand'] ?? 'promanaged'));
    $nm = (string)($lead['name'] ?? '');
    pm_tb_post($b, 'followup', 'director', $nm . ': ' . $where . '.', 'pg:' . ($lead['id'] ?? $nm));
    if ($state === 'done' && pm_pg_is_live($steps[$at][0])) {
        pm_tb_post($b, 'followup', 'social', $nm . ($b === 'travel' ? ' in ' . trim((string)($lead['city'] ?? 'Malawi')) . ' is now live on ' . pm_brand_name('travel') : ' is now live with us')
            . ': a welcome post for them on the Page would show others it works (ask them first, and tag their Page if they have one).', 'pglive:' . ($lead['id'] ?? $nm));
    }
    pm_talk($b, 'followup', $nm . ': ' . $where . '. I wrote the update for them; it waits for your Send.');
    return 'Saved: ' . $where . '.' . ($won ? ' ' . $nm . ' is now Won.' : '') . ' The update is written below: read it, then send it.';
}

/** The update went out (or was skipped). */
function pm_pg_sent(array &$lead, string $how): void
{
    if (empty($lead['progress']['msg'])) {
        return;
    }
    $n = count((array)($lead['progress']['log'] ?? []));
    if ($n > 0) {
        $lead['progress']['log'][$n - 1]['sent'] = $how;
    }
    unset($lead['progress']['msg']);
    $how !== 'skipped' && pm_lead_note($lead, 'Progress update sent by ' . $how);
    $lead['last_contacted'] = date('Y-m-d H:i');
}

/** A chip for the lead's row: where their order stands. */
function pm_pg_chip(array $lead): string
{
    $pg = (array)($lead['progress'] ?? []);
    if (empty($pg['steps'])) {
        return '';
    }
    $s = (string)($pg['steps'][(int)$pg['at']][0] ?? '');
    $st = (string)($pg['state'] ?? 'working');
    $live = $st === 'done' && pm_pg_is_live($s);
    return '<span class="pill ' . ($live || ($st === 'done' && (int)$pg['at'] === count($pg['steps']) - 1) ? 'hot' : ($st === 'waiting' ? 'warn' : '')) . '" title="Their progress">'
        . pm_h($live ? 'live' : $s . ': ' . strtolower(PM_PG_STATES[$st] ?? '')) . (!empty($pg['msg']) ? ' · update to send' : '') . '</span>';
}

/** The progress card on a lead (Sales > Leads). $post(do) gives the hidden fields of a lead form. */
function pm_pg_panel(array $lead, callable $post, bool $waOn): string
{
    $st = (string)($lead['status'] ?? '');
    $pg = (array)($lead['progress'] ?? []);
    if (empty($pg['steps']) && !in_array($st, ['replied', 'proposal', 'won', 'contacted'], true)) {
        return '';
    }
    $steps = pm_pg_steps($lead);
    $at = (int)($pg['at'] ?? 0);
    $state = (string)($pg['state'] ?? '');
    $open = !empty($pg['steps']) || $st === 'won';
    $h = '<details class="lc pgbox"' . ($open ? ' open' : '') . '><summary class="lc-h">Their progress' . (empty($pg['steps']) ? ' <span class="muted t-sm">· when they say yes, keep them posted</span>' : '') . '</summary>';
    $h .= '<ol class="stepper pgsteps">';
    foreach ($steps as $k => $s) {
        $done = !empty($pg['steps']) && ($k < $at || ($k === $at && $state === 'done'));
        $on = !empty($pg['steps']) && $k === $at && $state !== 'done';
        $h .= '<li class="' . ($done ? 'done' : ($on ? 'on' : '')) . '" title="' . pm_h($s[2]) . '"><b>' . ($done ? '&#10003;' : $k + 1) . '</b>' . pm_h($s[0]) . ($on ? ' <span class="muted t-sm">· ' . pm_h(strtolower(PM_PG_STATES[$state] ?? '')) . '</span>' : '') . '</li>';
    }
    $h .= '</ol>';
    $next = empty($pg['steps']) ? 0 : ($state === 'done' ? min(count($steps) - 1, $at + 1) : $at);
    $h .= '<form method="post" class="pgform">' . $post('progress_mark') . '<div class="row">'
        . '<div><label>Step</label><select name="pg_at">';
    foreach ($steps as $k => $s) {
        $h .= '<option value="' . $k . '"' . ($k === $next ? ' selected' : '') . '>' . ($k + 1) . '. ' . pm_h($s[0]) . '</option>';
    }
    $h .= '</select></div><div><label>Where it stands</label><select name="pg_state">';
    foreach (PM_PG_STATES as $k => $lab) {
        $h .= '<option value="' . $k . '"' . ($k === (empty($pg['steps']) ? 'working' : ($state === 'done' ? 'working' : $state)) ? ' selected' : '') . '>' . pm_h($lab) . '</option>';
    }
    $h .= '</select></div></div><label>For them (optional: a link, what is next, what you need from them)</label><textarea name="pg_note" rows="2" placeholder="e.g. Your page: https://... or Please send 3 more photos of the rooms"></textarea>'
        . '<div class="btns"><button class="btn small primary">Save and write the update</button></div>'
        . '<p class="hint mb-0">The steps are the ones their proposal promised (Template and prices › Getting started). The first mark makes them Won; nothing is sent until you press Send.</p></form>';
    if (!empty($pg['msg'])) {
        $m = (array)$pg['msg'];
        $mail = (bool)filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $wa = $waOn ? pm_wa_link($lead, '') : '';
        $h .= '<form method="post" class="pgmsg">' . $post('progress_send') . '<p class="rx-k mt-2">The update, ready to send</p>'
            . '<input type="text" name="subject" value="' . pm_h((string)$m['subject']) . '" aria-label="Subject">'
            . '<textarea name="body" rows="9" data-chat="followup" aria-label="Update by email">' . pm_h((string)$m['body']) . '</textarea>'
            . ($wa !== '' ? '<label class="hint">WhatsApp version</label><textarea name="whatsapp" rows="4" data-chat="whatsapp" aria-label="Update on WhatsApp">' . pm_h((string)$m['whatsapp']) . '</textarea>' : '')
            . '<div class="btns">' . ($mail ? '<button class="btn small primary" onclick="return confirm(\'Send this update by email now?\')">Send by email</button>' : '<span class="hint">No email address: send it on WhatsApp.</span>')
            . ($wa !== '' ? '<button class="btn small" name="do" value="progress_wa" formtarget="_blank">Send on WhatsApp</button>' : '')
            . '<button class="btn small ghost" name="do" value="progress_skip" formnovalidate>Do not send this one</button></div></form>';
    }
    if (!empty($pg['log'])) {
        $h .= '<details class="more"><summary>History (' . count($pg['log']) . ')</summary><ul class="rx-list">';
        foreach (array_reverse((array)$pg['log']) as $e) {
            $h .= '<li>' . pm_h((string)$e['at'] . ' · ' . (string)$e['step'] . ': ' . strtolower(PM_PG_STATES[$e['state']] ?? '')) . ($e['sent'] !== '' ? ' <span class="muted">· ' . pm_h($e['sent'] === 'skipped' ? 'not sent' : 'sent by ' . $e['sent']) . '</span>' : '') . '</li>';
        }
        $h .= '</ul></details>';
    }
    return $h . '</details>';
}

/** The WhatsApp update as it goes out: their first name, the words, our WhatsApp sign-off (no STOP line: they are a customer). */
function pm_pg_wa_text(array $lead, string $text): string
{
    $contact = trim(preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', trim((string)($lead['contact'] ?? ''))));
    $first = pm_contact_name_ok($contact) ? (explode(' ', $contact)[0] ?? '') : '';
    $text = ($first !== '' ? "Hello $first, " : 'Hello, ') . lcfirst(trim($text));
    $s = pm_settings();
    $mine = function_exists('pm_dm_wa_signature') ? pm_dm_wa_signature() : null;
    return $text . ($mine !== null ? ($mine !== '' ? "\n\n" . $mine : '') : "\n\n— " . $s['company_name']);
}
