<?php
/**
 * The team's sit-down about a lead: the agents talk it over and decide how best to approach it. Nia (Researcher) says what we know, Mphatso (Replies) what they said,
 * Tiwo (Writer) how to open, Chiso (Follow-up) when, and Amara (Director) decides: send the draft as it is, rewrite it with a new angle, wait, call them, or drop them.
 * Up to six leads are talked over in ONE cheap call (the day's top leads, in the morning run; or one lead when the owner asks). The talk goes into the agents'
 * conversation (Audit) and on the lead; the decision acts by itself where it is safe (a rewrite, a pause) and is left for the owner where it is not (a call, a drop).
 */

const PM_SD_ACTIONS = ['send' => 'Send it as it is', 'rewrite' => 'Rewrite with a new angle', 'wait' => 'Wait', 'call' => 'Call them', 'drop' => 'Drop them'];

/** The rules every writer keeps so an email reads as a real person's, never as spam or phishing (a few dozen tokens in each writer's instructions). */
const PM_TRUST_RULES = 'It must read as a real, trusted person\'s email to one owner: professional, friendly, specific to them and detailed enough to be useful, with who we are and why we are writing to them; nothing a spam filter or a careful owner would flag: no urgency or pressure, no ALL CAPS, no "click here", no link without saying what it is, no attachment they did not ask for, never ask for money, passwords or bank details.';

/** What in an email could make an owner (or a spam filter) think spam or phishing, checked by code, free: ['too many links', ...]. */
function pm_trust_flags(string $subject, string $body): array
{
    $t = $subject . "\n" . $body;
    $f = [];
    preg_match_all('#https?://#i', $body, $lk);
    count($lk[0]) > 2 && $f[] = count($lk[0]) . ' links';
    preg_match('#\\b(bit\\.ly|tinyurl|t\\.co|goo\\.gl|ow\\.ly|is\\.gd)/#i', $t) && $f[] = 'a shortened link';
    preg_match_all('/\\b[A-Z]{4,}\\b/', preg_replace('/\\b(MWK|USD|VAT|WhatsApp|SEO|CRM|ICT)\\b/', '', $t), $cp);
    count($cp[0]) >= 2 && $f[] = 'words in capitals';
    substr_count($t, '!') >= 2 && $f[] = 'exclamation marks';
    preg_match('/\\b(act now|urgent|limited time|click here|winner|risk[- ]free|100% free|guarantee[sd]?|last chance|don\'t miss|exclusive offer|verify your|confirm your account|password|bank details|send money)\\b/i', $t, $sw) && $f[] = 'the words "' . strtolower($sw[0]) . '"';
    preg_match('/^\\s*(dear (sir|madam|sir\\/madam)|to whom it may concern)/i', $body) && $f[] = 'a nameless greeting';
    str_word_count($body) < 35 && $f[] = 'very short';
    str_word_count($body) > 260 && $f[] = 'very long';
    !str_contains($body, '?') && !preg_match('/\b(reply|let me know|would you|shall i|happy to|get in touch|write back)\b/i', $body) && $f[] = 'nothing inviting them to answer';
    return $f;
}

/** What the team knows about one lead, kept short for the sit-down. */
function pm_sitdown_brief(array $l): array
{
    $last = function (string $dir) use ($l): string {
        foreach (array_reverse((array)($l['thread'] ?? [])) as $m) {
            if (($m['dir'] ?? '') === $dir && trim((string)($m['text'] ?? '')) !== '') {
                return mb_substr(trim((string)$m['text']), 0, 220);
            }
        }
        return '';
    };
    $r = (array)($l['research'] ?? []);
    $d = ($l['status'] ?? '') === 'contacted' && !empty($l['followup_draft']['email_body']) ? (array)$l['followup_draft'] : (array)($l['drafts'] ?? []);
    $lt = strtotime((string)($l['last_contacted'] ?? ''));
    return ['id' => (string)$l['id'], 'name' => (string)$l['name'], 'kind' => (string)($l['type'] ?? ''), 'town' => (string)($l['city'] ?? ''), 'score' => (int)($l['score'] ?? 0),
        'stage' => (string)($l['status'] ?? ''), 'owner_name_known' => trim((string)($l['contact'] ?? '')) !== '', 'has_email' => (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL),
        'has_phone' => trim((string)($l['phone'] ?? '') . (string)($l['whatsapp'] ?? '')) !== '', 'emails_sent' => count((array)($l['sent'] ?? [])),
        'days_since_last_email' => $lt ? (int)floor((time() - $lt) / 86400) : null, 'facts' => array_slice(array_map(fn($x) => (string)($x['fact'] ?? ''), (array)($r['discoveries'] ?? [])), 0, 3),
        'personal_line' => (string)($r['personal'] ?? ''), 'their_last_message' => $last('in'), 'our_waiting_email' => pm_sitdown_email_view($l, (string)($d['email_subject'] ?? ''), (string)($d['email_body'] ?? '')),
        'trust_flags' => pm_trust_flags((string)($d['email_subject'] ?? ''), (string)($d['email_body'] ?? '')), 'our_waiting_reply' => mb_substr((string)($l['reply_draft']['body'] ?? ''), 0, 2000),
        'lead_with' => (string)($l['pain'] ?? '')];
}

/** The sit-down for up to six leads in one cheap AI call: [id => ['lines' => [[who, says]], 'action', 'angle', 'wait_days', 'why']]. */
function pm_sitdown_ask(array $leads): array
{
    $items = array_map('pm_sitdown_brief', array_slice($leads, 0, 6));
    if (!$items) {
        return [];
    }
    $sys = pm_agents_company_brief('tiny') . "\nYou chair a short sit-down of our marketing team about each lead: research (what we know), replies (what they said, and whether our waiting reply answers it), writers (how to open), sender (whether our waiting email could look like spam or phishing to this owner: read trust_flags and the email itself), followup (when), then director decides. "
        . 'If the sender sees anything that would make this owner distrust the email, or the email is not professional, warm, specific and useful, the decision is rewrite, with exactly what to fix. ' . PM_TRUST_RULES . ' '
        . 'For each lead write 4 to 6 lines of the team talking to EACH OTHER about the lead (never to the lead), each line an object {"who","says"}: one short sentence in the first person, sharp and specific to that lead, from the facts given only (no invented facts), the director last. '
        . 'Then the decision: action "send" (our waiting email is right), "rewrite" (give the new angle in one sentence: what to open with and why it will land), "wait" (wait_days 2 to 21), "call" (a phone call would win it), or "drop" (not a fit). '
        . 'Think like the best salespeople in the world: the angle that this owner, in this town, will care about today. ' . pm_firm_line(pm_brand()) . ' ' . pm_tb_line(pm_brand(), 'writers', 3) . ' ' . PM_AGENT_RULES;
    $out = pm_agent_json(pm_claude($sys, json_encode($items, JSON_UNESCAPED_UNICODE) . "\nJSON: {\"items\":[{\"id\":\"\",\"lines\":[{\"who\":\"research|replies|writers|sender|followup|director\",\"says\":\"\"}],\"action\":\"send|rewrite|wait|call|drop\",\"angle\":\"\",\"wait_days\":0,\"why\":\"one sentence\"}]}", false, 220 + 260 * count($items), 'cheap'));
    $res = [];
    foreach ((array)($out['items'] ?? (is_array($out) && array_is_list($out) ? $out : [])) as $x) {
        $id = is_array($x) ? (string)($x['id'] ?? '') : '';
        $act = strtolower((string)($x['action'] ?? ''));
        if ($id === '' || !isset(PM_SD_ACTIONS[$act])) {
            continue;
        }
        $lines = [];
        $order = ['research', 'replies', 'writers', 'sender', 'followup', 'director'];
        foreach (array_values(array_slice((array)($x['lines'] ?? []), 0, 6)) as $li => $ln) {
            if (is_string($ln)) { // a line without a name: the team speaks in its usual order
                $ln = ['who' => $order[$li] ?? 'director', 'says' => $ln];
            }
            $who = is_array($ln) ? strtolower(trim((string)($ln['who'] ?? ''))) : '';
            $who = (string)preg_replace('/^.*\((\w[\w -]*)\)$/', '$1', $who); // "Pemba (Sender)" is the sender
            $who = ['researcher' => 'research', 'writer' => 'writers', 'follow-up' => 'followup', 'scout' => 'scouts'][$who] ?? $who;
            $says = is_array($ln) ? mb_substr(trim(strip_tags((string)($ln['says'] ?? ''))), 0, 220) : '';
            if ($says !== '' && in_array($who, ['research', 'replies', 'writers', 'sender', 'followup', 'director', 'social', 'scouts'], true)) {
                $lines[] = [$who, $says];
            }
        }
        $res[$id] = ['lines' => $lines, 'action' => $act, 'angle' => mb_substr(trim(strip_tags((string)($x['angle'] ?? ''))), 0, 220),
            'wait_days' => max(2, min(21, (int)($x['wait_days'] ?? 0) ?: 5)), 'why' => mb_substr(trim(strip_tags((string)($x['why'] ?? ''))), 0, 220)];
    }
    return $res;
}

/**
 * What the sit-down decided, put on the lead: the talk is kept on the lead and in the agents' conversation; "rewrite" takes the waiting email away with the new angle as
 * the reason (the Writers write it again in the same run), "wait" pauses the lead, "call" and "drop" are filed for the owner to decide (folders "To call" and
 * "Team says drop"); "send" marks the email as agreed. A draft the owner edited or approved is never touched. Returns the action.
 */
function pm_sitdown_apply(array &$lead, array $s, string $brand): string
{
    $lead['sitdown'] = ['at' => date('Y-m-d H:i'), 'lines' => $s['lines'], 'action' => $s['action'], 'angle' => $s['angle'], 'why' => $s['why']];
    if (function_exists('pm_talk')) {
        foreach ($s['lines'] as $i => [$who, $says]) {
            pm_talk($brand, $who, ($i === 0 ? 'About ' . ($lead['name'] ?? 'a lead') . ': ' : '') . $says);
        }
        pm_talk($brand, 'director', 'Decision on ' . ($lead['name'] ?? 'the lead') . ': ' . strtolower(PM_SD_ACTIONS[$s['action']]) . ($s['angle'] !== '' ? ' (' . $s['angle'] . ')' : '') . '.');
    }
    $first = ($lead['status'] ?? '') !== 'contacted';
    $draft = $first ? (array)($lead['drafts'] ?? []) : (array)($lead['followup_draft'] ?? []);
    $locked = !empty($lead['approved_at']) || !empty($draft['edited_at']);
    $file = function (string $folder) use (&$lead) {
        if (!in_array($folder, (array)($lead['folders'] ?? []), true)) {
            $lead['folders'][] = $folder;
        }
    };
    switch ($s['action']) {
        case 'rewrite':
            if (!empty($lead['reply_draft']['body']) && $s['angle'] !== '' && in_array($lead['status'] ?? '', ['replied', 'proposal'], true)) {
                $lead['reply_rewrite'] = $s['angle']; // the answer to their message is written again with this angle (pm_sitdown_reply_rewrites)
                break;
            }
            if (!$locked && !empty($draft['email_body']) && $s['angle'] !== '') {
                if ($first && empty($lead['sent'])) {
                    unset($lead['drafts']);
                    $lead['status'] = 'qualified';
                } else {
                    unset($lead['followup_draft'], $lead['followup_approved']);
                }
                $lead['rewrite_reason'] = 'The team decided a new angle: ' . $s['angle'];
            }
            break;
        case 'wait':
            if (!$locked) {
                $lead['snooze_until'] = date('Y-m-d', strtotime('+' . $s['wait_days'] . ' days'));
            }
            break;
        case 'call':
            $file('To call');
            break;
        case 'drop':
            $file('Team says drop'); // the owner decides: Move to Lost, or keep it
            break;
    }
    pm_lead_note($lead, 'Team sit-down: ' . PM_SD_ACTIONS[$s['action']] . ($s['angle'] !== '' ? ' (' . $s['angle'] . ')' : '') . ($s['why'] !== '' ? ': ' . $s['why'] : ''));
    return $s['action'];
}

/** The day's leads worth a sit-down: who wrote back first, then follow-ups due, then the best emails ready to send; not one talked over in the last 3 days unless they wrote since. */
function pm_sitdown_due(array $leads, array $cfg, int $max = 12): array
{
    $fresh = fn($l) => !empty($l['sitdown']['at']) && strtotime((string)$l['sitdown']['at']) > strtotime('-3 days')
        && strcmp((string)($l['last_reply'] ?? ''), (string)$l['sitdown']['at']) <= 0;
    $open = array_filter($leads, fn($l) => !in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true) && !$fresh($l) && empty($l['approved_at']));
    $replied = array_filter($open, fn($l) => ($l['status'] ?? '') === 'replied' || !empty($l['awaiting_reply_since']));
    $fu = function_exists('pm_followup_due') ? array_intersect_key($open, pm_followup_due($open, $cfg, true)) : [];
    $ready = array_filter($open, fn($l) => in_array($l['status'] ?? '', ['qualified', 'drafted'], true) && empty($l['sent']) && !empty($l['drafts']['email_body']) && filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL));
    uasort($ready, fn($x, $y) => ($y['score'] ?? 0) <=> ($x['score'] ?? 0));
    $out = [];
    foreach ([$replied, $fu, $ready] as $group) {
        foreach ($group as $id => $l) {
            if (count($out) >= $max) {
                break 2;
            }
            $out[(string)$id] = ['id' => (string)$id] + $l;
        }
    }
    return array_values($out);
}

/** The answers to their messages that a sit-down said to write again: one small call each, with the angle. Returns how many were rewritten. */
function pm_sitdown_reply_rewrites(array &$leads): int
{
    $n = 0;
    foreach ($leads as $id => $l) {
        if (empty($l['reply_rewrite']) || empty($l['reply_draft']['body']) || !function_exists('pm_regen_lead')) {
            continue;
        }
        try {
            $leads[$id] = pm_regen_lead($l, 'reply_draft', ['subject' => (string)($l['reply_draft']['subject'] ?? ''), 'body' => (string)$l['reply_draft']['body']], (string)$l['reply_rewrite'] . ' ' . PM_TRUST_RULES);
            $n++;
        } catch (Throwable) {
        }
        unset($leads[$id]['reply_rewrite']);
    }
    return $n;
}

/** The waiting email as the sit-down reads it: whole when short; for Travel Malawi the part written for this owner, with the fixed part named, not cut (so nobody judges it unfinished). */
function pm_sitdown_email_view(array $l, string $subject, string $body): string
{
    if ($body === '') {
        return '';
    }
    if (($l['brand'] ?? '') === 'travel' && defined('PM_TM_AIM') && str_contains($body, 'What you get:') && ($p = mb_strpos($body, mb_substr(PM_TM_AIM, 0, 40))) !== false) { // the old long format; the short one is read whole
        $end = (string)preg_replace('/^.*\R\R/su', '', trim($body)); // the closing line, after the fixed part
        return 'Subject: ' . $subject . "
" . trim(mb_substr($body, 0, $p)) . "
[then our fixed, complete part: what Travel Malawi is, what a host gets, three steps with the link, that it is free]
" . $end;
    }
    return 'Subject: ' . $subject . "
" . (mb_strlen($body) > 1500 ? mb_substr($body, 0, 1500) . ' [shortened here for the meeting: the email itself is complete]' : $body);
}
