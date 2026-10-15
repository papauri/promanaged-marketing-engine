<?php
/**
 * Social > Leads: everyone who came to us through social media, sorted by channel (Messenger, Facebook comments, Instagram, WhatsApp, public posts, LinkedIn, X) and by
 * where the conversation stands: answer them, follow up, waiting on them, gone quiet, won, closed. Only the channels this business has connected are shown.
 * The head of Social owns it: each morning, in the same call as her huddle (no extra AI call), she drafts the answers and follow-ups that are due, talks about them with the
 * heads and tells Replies and Follow-up what waits; when there is no AI a plain draft is used. Each channel keeps its own rhythm and limits (Messenger's 24-hour and
 * 7-day windows, WhatsApp's 24-hour window, one private reply to a comment, a public post answered under the post). Nothing is sent by itself: the owner copies, opens and
 * presses "I sent it", or sends on Messenger from here; every contact is recorded on the lead's one timeline (pm_touch), at most two follow-ups each.
 * Lead fields: sl_draft {text, kind answer|followup, at, by ai|plain}, sl_followups (follow-ups sent), sl_last_out.
 */

/** The social channels: key => [label, what connects it, days between follow-ups]. */
const PM_SL_CHANNELS = [
    'messenger' => ['Messenger', 'fb', 1], 'facebook' => ['Facebook comments', 'fb', 1], 'instagram' => ['Instagram', 'ig', 1], 'whatsapp' => ['WhatsApp', 'wa', 2],
    'public' => ['Public posts', 'ai', 3], 'linkedin' => ['LinkedIn', 'li', 3], 'x' => ['X', 'x', 3],
];
const PM_SL_STAGES = ['answer' => 'Answer them', 'followup' => 'Follow up', 'waiting' => 'Waiting on them', 'quiet' => 'Gone quiet', 'won' => 'Won', 'closed' => 'Closed'];
const PM_SL_MAX_FOLLOWUPS = 2;

/** The channels this business has connected: key => label, in order. */
function pm_sl_connected(string $brand): array
{
    function_exists('pm_social_cfg') || require_once __DIR__ . '/social_modules.php'; // the daily run does not load the social screens
    $fb = pm_social_cfg($brand);
    $on = [
        'fb' => (bool)$fb['ready'],
        'ig' => $fb['ready'] && (string)($fb['ig_id'] ?? '') !== '',
        'wa' => function_exists('pm_wa_on') && pm_wa_on($brand),
        'ai' => function_exists('pm_agents_ready') && pm_agents_ready(),
        'li' => function_exists('pm_linkedin_cfg') && (pm_linkedin_cfg($brand)['ready'] ?? false),
        'x' => function_exists('pm_x_cfg') && (pm_x_cfg($brand)['ready'] ?? false),
    ];
    $out = [];
    foreach (PM_SL_CHANNELS as $k => [$label, $need]) {
        !empty($on[$need]) && $out[$k] = $label;
    }
    return $out;
}

/** The social channel a lead came through, or '' when it did not come through social media. */
function pm_sl_channel(array $l): string
{
    if (!empty($l['listen'])) {
        return 'public';
    }
    $tag = (string)($l['src_tag'] ?? '');
    $ch = strtolower((string)($l['channel'] ?? ''));
    $src = strtolower((string)($l['source'] ?? ''));
    $ref = strtolower((string)($l['source_ref'] ?? ''));
    return match (true) {
        $tag === 'fb-messenger' => 'messenger',
        $tag === 'fb-comment' => 'facebook',
        str_starts_with($tag, 'ig-') || $ch === 'instagram' || $src === 'instagram' => 'instagram',
        $ch === 'whatsapp' || $src === 'whatsapp' || in_array($tag, ['page-wa', 'wa-status'], true) => 'whatsapp',
        $ch === 'facebook' || $src === 'facebook' => 'messenger', // a Messenger enquiry logged by hand
        $ch === 'linkedin' || str_starts_with($ref, 'li-') => 'linkedin',
        $ch === 'x' || str_starts_with($ref, 'x-') => 'x',
        str_starts_with($ref, 'fb-') => 'facebook',
        str_starts_with($ref, 'ig-') => 'instagram',
        str_starts_with($ref, 'wa-') => 'whatsapp',
        default => '',
    };
}

/** The newest time of a list of date strings ('' when none). */
function pm_sl_latest(array $times): string
{
    $best = 0;
    foreach ($times as $t) {
        $best = max($best, strtotime((string)$t) ?: 0);
    }
    return $best ? date('Y-m-d H:i', $best) : '';
}

/** When they last spoke to us, and when we last spoke to them. */
function pm_sl_times(array $l): array
{
    $in = [(string)($l['first_touch_at'] ?? ''), (string)($l['last_reply'] ?? ''), (string)($l['listen']['posted'] ?? ''), (string)($l['awaiting_reply_since'] ?? '')];
    $out = [(string)($l['first_reply_at'] ?? ''), (string)($l['sl_last_out'] ?? ''), (string)($l['last_out'] ?? ''), (string)($l['last_contacted'] ?? ''), (string)(pm_touches($l)[0]['at'] ?? '')];
    foreach ((array)($l['thread'] ?? []) as $m) {
        ($m['dir'] ?? '') === 'in' ? $in[] = (string)($m['at'] ?? '') : $out[] = (string)($m['at'] ?? '');
    }
    return [pm_sl_latest($in), pm_sl_latest($out)];
}

/** Where a social lead stands: a PM_SL_STAGES key. */
function pm_sl_stage(array $l, string $ch = ''): string
{
    $st = (string)($l['status'] ?? '');
    if ($st === 'won') {
        return 'won';
    }
    if (in_array($st, ['lost', 'optout'], true)) {
        return 'closed';
    }
    [$in, $out] = pm_sl_times($l);
    if ($out === '' || ($in !== '' && strtotime($in) > strtotime($out)) || !empty($l['awaiting_reply_since'])) {
        return 'answer';
    }
    if ((int)($l['sl_followups'] ?? 0) >= PM_SL_MAX_FOLLOWUPS) {
        return 'quiet';
    }
    $gap = (int)(PM_SL_CHANNELS[$ch !== '' ? $ch : pm_sl_channel($l)][2] ?? 2);
    return strtotime($out) <= strtotime("-$gap days") ? 'followup' : 'waiting';
}

/** What a channel allows right now, in a few words for the owner ('' when nothing to say). */
function pm_sl_rule(array $l, string $ch): string
{
    [$in] = pm_sl_times($l);
    $age = $in !== '' ? (time() - strtotime($in)) / 86400 : 99;
    $other = trim((string)($l['whatsapp'] ?? '') ?: (string)($l['phone'] ?? '')) !== '' ? 'WhatsApp or a call' : (filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) ? 'email' : '');
    return match ($ch) {
        'messenger' => $age <= 1 ? 'Messenger is open (they wrote in the last 24 hours).' : ($age <= 7 ? 'Past 24 hours: one human follow-up on Messenger is allowed until day 7.' : 'Messenger is closed (over 7 days)' . ($other !== '' ? ": move to $other." : ': wait for them to write again.')),
        'facebook', 'instagram' => $age <= 7 ? 'One private message to their comment is allowed for 7 days; then answer under the comment.' : 'Answer under their comment' . ($other !== '' ? " or move to $other." : '.'),
        'whatsapp' => $age <= 1 ? 'WhatsApp is open (they wrote in the last 24 hours).' : 'Past 24 hours: send from your own phone, or the approved template.',
        'public' => 'Answer under their post; one more helpful comment as a follow-up, never a private message they did not ask for.',
        default => '',
    };
}

/** The first name we greet them by ('' for "Someone in ..."). */
function pm_sl_first(array $l): string
{
    $n = trim((string)($l['contact'] ?? '') ?: (string)($l['name'] ?? ''));
    return str_starts_with($n, 'Someone ') ? '' : trim(explode(' ', $n)[0] ?? '');
}

/** What they asked for, in a few words. */
function pm_sl_wants(array $l): string
{
    $w = (string)($l['listen']['wants'] ?? '') ?: (string)(((array)($l['need_signals'] ?? []))[0] ?? '') ?: (string)($l['reason'] ?? '');
    $w = trim((string)preg_replace('/^(Asked us on \w+|Asked via [^:]+|Wrote to us on WhatsApp[^:]*):?\s*/i', '', $w));
    if ($w === '' && preg_match('/"([^"]{3,})"/', (string)(((array)($l['evidence'] ?? []))[0] ?? ''), $m)) { // what they said on our Page, in their words
        $w = $m[1];
    }
    return mb_substr($w, 0, 90);
}

/** A plain draft when the head of Social has not written one (no AI): an answer or a follow-up, as the business, ending with an easy question. */
function pm_sl_plain(array $l, string $ch, string $kind): string
{
    $hi = ($f = pm_sl_first($l)) !== '' ? "Hi $f, " : 'Hi, ';
    $w = pm_sl_wants($l);
    $about = $w === '' ? '' : (str_ends_with($w, '?') ? ($kind === 'answer' ? ' with your question' : ' to your question') : ' about ' . lcfirst(rtrim($w, '.')));
    if ($kind === 'answer') {
        if ($ch === 'public' && !empty($l['listen']['reply'])) {
            return (string)$l['listen']['reply'];
        }
        if (!empty($l['reply_draft']['body'])) {
            return (string)$l['reply_draft']['body'];
        }
        return $hi . "thank you for getting in touch$about. We can help with that. Could you tell me a little more about what you need, so I can send you the right details?";
    }
    return $ch === 'public'
        ? "Just checking in on this one: if you are still looking$about, we are happy to help. What would be most useful to know?"
        : $hi . 'I wanted to check you saw my ' . ($about === ' to your question' ? 'answer' : 'message') . $about . '. Is it still something you need? Happy to answer any questions.';
}

/** The draft to send now: the head of Social's when it is for this stage, else a plain one. [text, by]. */
function pm_sl_draft(array $l, string $ch, string $stage): array
{
    $kind = $stage === 'answer' ? 'answer' : 'followup';
    if ($kind === 'answer' && $ch === 'public' && trim((string)($l['listen']['reply'] ?? '')) !== '') {
        return [(string)$l['listen']['reply'], 'ai']; // she wrote it when she found the post
    }
    $d = (array)($l['sl_draft'] ?? []);
    if (($d['kind'] ?? '') === $kind && trim((string)($d['text'] ?? '')) !== '' && strtotime((string)($d['at'] ?? '')) >= strtotime((string)(pm_sl_times($l)[1] ?: '1970-01-01'))) {
        return [(string)$d['text'], (string)($d['by'] ?? 'ai')];
    }
    return [pm_sl_plain($l, $ch, $kind), 'plain'];
}

/** This business's social leads on connected channels: [id => ['lead', 'ch', 'stage']], those needing us first, oldest first. */
function pm_sl_rows(string $brand, ?array $leads = null): array
{
    $on = pm_sl_connected($brand);
    $rows = [];
    foreach ($leads ?? pm_leads_read() as $id => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || ($ch = pm_sl_channel($l)) === '' || !isset($on[$ch])) {
            continue;
        }
        $rows[(string)($l['id'] ?? $id)] = ['lead' => $l, 'ch' => $ch, 'stage' => pm_sl_stage($l, $ch), 'in' => pm_sl_times($l)[0]];
    }
    $order = array_flip(array_keys(PM_SL_STAGES));
    uasort($rows, fn($a, $b) => [$order[$a['stage']], $a['in']] <=> [$order[$b['stage']], $b['in']]);
    return $rows;
}

/** Counts for the head of Social, the board and the goals: ['answer' => n, 'followup' => n, ..., 'by_channel' => [ch => [stage => n]]]. */
function pm_sl_counts(string $brand, ?array $leads = null): array
{
    $c = array_fill_keys(array_keys(PM_SL_STAGES), 0) + ['by_channel' => []];
    foreach (pm_sl_rows($brand, $leads) as $r) {
        $c[$r['stage']]++;
        $c['by_channel'][$r['ch']][$r['stage']] = ($c['by_channel'][$r['ch']][$r['stage']] ?? 0) + 1;
    }
    return $c;
}

/** The social leads the head of Social drafts for in her morning call: those to answer or follow up that have no draft of hers for that stage yet (up to $n). */
function pm_sl_due(string $brand, array $leads, int $n = 8): array
{
    $out = [];
    foreach (pm_sl_rows($brand, $leads) as $id => $r) {
        if (!in_array($r['stage'], ['answer', 'followup'], true) || pm_sl_draft($r['lead'], $r['ch'], $r['stage'])[1] !== 'plain') {
            continue;
        }
        $l = $r['lead'];
        $theirs = '';
        $ours = '';
        foreach ((array)($l['thread'] ?? []) as $m) {
            ($m['dir'] ?? '') === 'in' ? $theirs = (string)$m['text'] : $ours = (string)$m['text'];
        }
        $out[] = ['id' => $id, 'channel' => PM_SL_CHANNELS[$r['ch']][0], 'stage' => $r['stage'] === 'answer' ? 'answer them' : 'follow up (number ' . ((int)($l['sl_followups'] ?? 0) + 1) . ')',
            'name' => pm_sl_first($l), 'wants' => pm_sl_wants($l), 'they_said' => mb_substr($theirs ?: (string)(((array)($l['evidence'] ?? []))[0] ?? ''), 0, 200), 'we_said' => mb_substr($ours ?: (string)($l['listen']['reply'] ?? ''), 0, 160)];
        if (count($out) >= $n) {
            break;
        }
    }
    return $out;
}

/** Keeps the head of Social's drafts: [{id, text}] from her morning call, checked like any answer. Returns how many were kept. */
function pm_sl_keep_drafts(string $brand, array &$all, array $drafts, array $due): int
{
    $kinds = array_column($due, 'stage', 'id');
    $n = 0;
    foreach (array_slice($drafts, 0, 8) as $d) {
        $id = (string)($d['id'] ?? '');
        $text = trim(strip_tags((string)($d['text'] ?? '')));
        if (!isset($kinds[$id], $all[$id]) || $text === '' || (function_exists('pm_reply_lint') && pm_reply_lint($text, $brand, 'dm'))) {
            continue; // not one we asked for, empty, or would not pass the answer check: the plain draft stays
        }
        $all[$id]['sl_draft'] = ['text' => mb_substr($text, 0, 600), 'kind' => str_starts_with($kinds[$id], 'answer') ? 'answer' : 'followup', 'at' => date('Y-m-d H:i'), 'by' => 'ai'];
        $n++;
    }
    return $n;
}

/** The owner sent it (or answered under the post): recorded on the lead's timeline; a follow-up counts towards the two. */
function pm_sl_sent(array &$lead, string $ch, string $stage, string $text = ''): void
{
    pm_touch($lead, ['public' => 'reply_comment', 'facebook' => 'reply_comment', 'instagram' => 'instagram_reply'][$ch] ?? $ch, 'social', $stage === 'answer' ? 'Answered' : 'Followed up');
    $lead['sl_last_out'] = date('Y-m-d H:i');
    $stage === 'followup' && $lead['sl_followups'] = (int)($lead['sl_followups'] ?? 0) + 1;
    unset($lead['awaiting_reply_since'], $lead['sl_draft']);
    if (!empty($lead['listen']) && ($lead['status'] ?? '') === 'new') {
        pm_lead_set_status($lead, 'contacted');
    }
    empty($lead['first_reply_at']) && $lead['first_reply_at'] = date('c');
    $lead['updated'] = date('Y-m-d H:i');
    pm_lead_note($lead, ($stage === 'answer' ? 'Answered' : 'Followed up') . ' on ' . (PM_SL_CHANNELS[$ch][0] ?? $ch) . ($text !== '' ? ': ' . mb_substr($text, 0, 140) : ''));
}

/* ---------------- handlers ---------------- */

/** One social lead from the form, with its channel and stage, or an error. [lead id, leads, ch, stage] */
function pm_sl_post(string $vb): array
{
    $id = preg_replace('/[^a-z0-9_.-]/i', '', (string)($_POST['id'] ?? ''));
    $leads = pm_leads();
    if ($id === '' || !isset($leads[$id]) || ($leads[$id]['brand'] ?? 'promanaged') !== $vb || ($ch = pm_sl_channel($leads[$id])) === '') {
        return ['', $leads, '', ''];
    }
    return [$id, $leads, $ch, pm_sl_stage($leads[$id], $ch)];
}

function pm_do_sl_sent(string $vb): array
{
    [$id, $leads, $ch, $stage] = pm_sl_post($vb);
    if ($id === '') {
        return ['msg' => 'That lead is not here any more.', 'kind' => 'err', 'to' => 'social&view=leads'];
    }
    pm_sl_sent($leads[$id], $ch, $stage, trim((string)($_POST['text'] ?? '')));
    pm_leads_save($leads);
    return ['msg' => 'Recorded: ' . $leads[$id]['name'] . ($stage === 'answer' ? ' answered.' : ' followed up.'), 'kind' => 'ok', 'to' => 'social&view=leads&ch=' . $ch];
}

function pm_do_sl_messenger(string $vb): array
{
    [$id, $leads, $ch, $stage] = pm_sl_post($vb);
    $psid = preg_replace('/\D/', '', (string)($leads[$id]['fb_from_id'] ?? ''));
    $text = trim((string)($_POST['text'] ?? ''));
    if ($id === '' || $ch !== 'messenger' || $psid === '' || $text === '') {
        return ['msg' => 'Write the message first, to someone who wrote to us on Messenger.', 'kind' => 'err', 'to' => 'social&view=leads&ch=messenger'];
    }
    [$in] = pm_sl_times($leads[$id]);
    [$ok, $m] = pm_fb_message($vb, $psid, $text, $in === '' || strtotime($in) < time() - 86400); // past 24 hours it goes as a human follow-up (allowed until day 7)
    if ($ok) {
        pm_sl_sent($leads[$id], $ch, $stage, $text);
        pm_leads_save($leads);
    }
    pm_agent_log('Social', ($ok ? 'Messenger ' . ($stage === 'answer' ? 'answer' : 'follow-up') . ' sent to ' : 'Messenger message failed for ') . $leads[$id]['name']);
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=leads&ch=messenger'];
}

function pm_do_sl_close(string $vb): array
{
    [$id, $leads, $ch] = pm_sl_post($vb);
    if ($id === '') {
        return ['msg' => 'That lead is not here any more.', 'kind' => 'err', 'to' => 'social&view=leads'];
    }
    pm_lead_set_status($leads[$id], 'lost', 'no_response');
    pm_lead_note($leads[$id], 'Closed on Social > Leads: no answer after our follow-ups');
    pm_leads_save($leads);
    return ['msg' => $leads[$id]['name'] . ' closed (no answer).', 'kind' => 'ok', 'to' => 'social&view=leads&ch=' . $ch];
}

/** The day's to-do on the Social screens: who waits for an answer or a follow-up. */
function pm_today_socleads(string $brand): array
{
    $c = pm_sl_counts($brand);
    $out = [];
    $c['answer'] && $out[] = ['text' => $c['answer'] . ' ' . ($c['answer'] === 1 ? 'person' : 'people') . ' from social media wait for an answer', 'href' => '?tab=social&view=leads&stage=answer', 'urgency' => 2];
    $c['followup'] && $out[] = ['text' => $c['followup'] . ' social follow-up' . ($c['followup'] === 1 ? ' is' : 's are') . ' due', 'href' => '?tab=social&view=leads&stage=followup', 'urgency' => 1];
    return $out;
}
