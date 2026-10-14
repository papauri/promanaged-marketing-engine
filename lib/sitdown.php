<?php
/**
 * The team's sit-down about a lead: the agents talk it over and decide how best to approach it. Nia (Researcher) says what we know, Mphatso (Replies) what they said,
 * Tiwo (Writer) how to open, Chiso (Follow-up) when, and Amara (Director) decides: send the draft as it is, rewrite it with a new angle, wait, call them, or drop them.
 * Up to six leads are talked over in ONE cheap call (the day's top leads, in the morning run; or one lead when the owner asks). The talk goes into the agents'
 * conversation (Audit) and on the lead; the decision acts by itself where it is safe (a rewrite, a pause) and is left for the owner where it is not (a call, a drop).
 */

const PM_SD_ACTIONS = ['send' => 'Send it as it is', 'rewrite' => 'Rewrite with a new angle', 'wait' => 'Wait', 'call' => 'Call them', 'drop' => 'Drop them'];

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
        'personal_line' => (string)($r['personal'] ?? ''), 'their_last_message' => $last('in'), 'our_waiting_email' => mb_substr(trim((string)($d['email_subject'] ?? '') . ' | ' . (string)($d['email_body'] ?? '')), 0, 260),
        'lead_with' => (string)($l['pain'] ?? '')];
}

/** The sit-down for up to six leads in one cheap AI call: [id => ['lines' => [[who, says]], 'action', 'angle', 'wait_days', 'why']]. */
function pm_sitdown_ask(array $leads): array
{
    $items = array_map('pm_sitdown_brief', array_slice($leads, 0, 6));
    if (!$items) {
        return [];
    }
    $sys = pm_agents_company_brief('tiny') . "\nYou chair a short sit-down of our marketing team about each lead: research (what we know), replies (what they said), writers (how to open), followup (when), then director decides. "
        . 'For each lead write 3 to 5 lines, each one short sentence in the first person, sharp and specific to that lead, from the facts given only (no invented facts). '
        . 'Then the decision: action "send" (our waiting email is right), "rewrite" (give the new angle in one sentence: what to open with and why it will land), "wait" (wait_days 2 to 21), "call" (a phone call would win it), or "drop" (not a fit). '
        . 'Think like the best salespeople in the world: the angle that this owner, in this town, will care about today. ' . pm_firm_line(pm_brand()) . ' ' . pm_tb_line(pm_brand(), 'writers', 3) . ' ' . PM_AGENT_RULES;
    $out = pm_agent_json(pm_claude($sys, json_encode($items, JSON_UNESCAPED_UNICODE) . "\nJSON: {\"items\":[{\"id\":\"\",\"lines\":[{\"who\":\"research|replies|writers|followup|director\",\"says\":\"\"}],\"action\":\"send|rewrite|wait|call|drop\",\"angle\":\"\",\"wait_days\":0,\"why\":\"one sentence\"}]}", false, 220 + 260 * count($items), 'cheap'));
    $res = [];
    foreach ((array)($out['items'] ?? (is_array($out) && array_is_list($out) ? $out : [])) as $x) {
        $id = is_array($x) ? (string)($x['id'] ?? '') : '';
        $act = strtolower((string)($x['action'] ?? ''));
        if ($id === '' || !isset(PM_SD_ACTIONS[$act])) {
            continue;
        }
        $lines = [];
        foreach (array_slice((array)($x['lines'] ?? []), 0, 6) as $ln) {
            $who = is_array($ln) ? strtolower((string)($ln['who'] ?? '')) : '';
            $says = is_array($ln) ? mb_substr(trim(strip_tags((string)($ln['says'] ?? ''))), 0, 220) : '';
            if ($says !== '' && in_array($who, ['research', 'replies', 'writers', 'followup', 'director', 'social', 'scouts'], true)) {
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
