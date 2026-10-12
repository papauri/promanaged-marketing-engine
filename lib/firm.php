<?php
/**
 * The firm as one team: what every department works towards, what wins, and one contact timeline per lead. Arithmetic on stored data only: no AI.
 *   pm_firm_goal($brand)     this month's goal (signed clients, replies) against what happened, and whether we are on pace
 *   pm_win_line($brand)      what has won replies so far (channel and message format), for the writers; '' until there is enough to say
 *   pm_touches($lead)        every contact with a lead from any department (emails, WhatsApp, answers, Social's comments and messages), newest first
 *   pm_touch($lead, ...)     records a contact made outside the email and WhatsApp senders (the Social playbook)
 * Loaded by lib/agents.php, so the Director, the writers, the playbook and the worker processes all read the same.
 */

/* ---------------- one goal ---------------- */

/** This month's goal and progress: ['signed' => [got, target], 'replies' => [got, target], 'pace' => ahead|on track|behind|no goal, 'line' => text for the agents]. */
function pm_firm_goal(string $brand): array
{
    $g = (array)(pm_load('social_goals', fn() => [])[$brand] ?? []);
    $tSigned = max(0, (int)($g['signed_per_month'] ?? 2));
    $tReplies = max(0, (int)($g['replies_per_month'] ?? 10));
    $month = date('Y-m');
    $replies = 0;
    foreach (pm_leads_read() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        foreach ((array)($l['thread'] ?? []) as $m) {
            if (($m['dir'] ?? '') === 'in') {
                $replies += substr((string)($m['at'] ?? ''), 0, 7) === $month ? 1 : 0;
                break; // the first answer only
            }
        }
    }
    $signed = 0;
    $tpl = pm_template();
    foreach (pm_history() as $h) {
        if (!empty($h['signed_file']) && substr((string)($h['signed_at'] ?? $h['when'] ?? ''), 0, 7) === $month
            && pm_brand_of_type((string)($tpl['packages'][(int)($h['proposal']['package'] ?? 0)]['type'] ?? '')) === $brand) {
            $signed++;
        }
    }
    $part = (int)date('j') / (int)date('t'); // how far through the month we are
    $score = ($tSigned ? $signed / ($tSigned * $part) : 1) * 0.6 + ($tReplies ? $replies / max(0.01, $tReplies * $part) : 1) * 0.4;
    $pace = !$tSigned && !$tReplies ? 'no goal' : ($score >= 1.15 ? 'ahead' : ($score >= 0.8 ? 'on track' : 'behind'));
    $left = (int)date('t') - (int)date('j');
    $line = $pace === 'no goal' ? '' : "Firm goal this month: $tSigned signed clients and $tReplies replies; so far $signed signed and $replies replies with $left days left ($pace). "
        . ($pace === 'behind' ? 'Put the leads closest to buying first and favour the channels that win replies.' : 'Keep the rhythm.');
    return ['signed' => [$signed, $tSigned], 'replies' => [$replies, $tReplies], 'pace' => $pace, 'line' => $line];
}

/* ---------------- what wins ---------------- */

/** The format a lead's first message was written in: a Write with AI format, the default message, or the agents' own draft. */
function pm_win_fmt(array $l): string
{
    $d = (array)($l['drafts'] ?? []);
    $f = (string)($d['fmt'] ?? '');
    if ($f !== '' && defined('PM_COMPOSE_TONES') && isset(PM_COMPOSE_TONES[$f])) {
        return PM_COMPOSE_TONES[$f];
    }
    return ($d['source'] ?? '') === 'default' ? 'Default message' : 'Agent draft';
}

/** Reply rates by first channel and by message format, from leads we wrote to first: ['channel' => [label => [written, replied]], 'fmt' => [...]]. */
function pm_win_stats(string $brand): array
{
    $out = ['channel' => [], 'fmt' => []];
    foreach (pm_leads_read() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || in_array($l['source'] ?? '', ['facebook', 'web'], true)) {
            continue; // they wrote to us first: not a test of our messages
        }
        $em = (string)(((array)($l['sent'] ?? []))[0] ?? '');
        $wa = (string)(((array)($l['wa_sent'] ?? []))[0] ?? '');
        if ($em === '' && $wa === '') {
            continue;
        }
        $ch = $wa !== '' && ($em === '' || strcmp($wa, $em) < 0) ? 'WhatsApp first' : 'Email first';
        $won = pm_lead_replied($l) || in_array($l['status'] ?? '', ['replied', 'proposal', 'won'], true) ? 1 : 0;
        foreach (['channel' => $ch, 'fmt' => pm_win_fmt($l)] as $k => $v) {
            $out[$k][$v] ??= [0, 0];
            $out[$k][$v][0]++;
            $out[$k][$v][1] += $won;
        }
    }
    return $out;
}

/** One line for the writers on what has won replies, using only groups of 5 or more; '' until there is enough to say. */
function pm_win_line(string $brand): string
{
    $parts = [];
    foreach (pm_win_stats($brand) as $rows) {
        $ok = array_filter($rows, fn($r) => $r[0] >= 5);
        if (count($ok) < 2) {
            continue;
        }
        uasort($ok, fn($a, $b) => $b[1] / $b[0] <=> $a[1] / $a[0]);
        $parts[] = implode(', ', array_map(fn($k, $r) => $k . ' ' . round(100 * $r[1] / $r[0]) . '% (' . $r[0] . ')', array_keys($ok), $ok));
    }
    return $parts ? 'What has won replies so far (reply rate, how many): ' . implode('; ', $parts) . '. Lean towards what wins.' : '';
}

/** The best message format so far (a PM_COMPOSE_TONES key), or '' while there is not enough to say. */
function pm_win_best_fmt(string $brand): string
{
    $rows = array_filter(pm_win_stats($brand)['fmt'], fn($r) => $r[0] >= 5);
    if (!$rows || !defined('PM_COMPOSE_TONES')) {
        return '';
    }
    uasort($rows, fn($a, $b) => $b[1] / $b[0] <=> $a[1] / $a[0]);
    $k = array_search(array_key_first($rows), PM_COMPOSE_TONES, true);
    return $k === false ? '' : (string)$k;
}

/* ---------------- one timeline per lead ---------------- */

/** Records a contact made outside the email and WhatsApp senders (the Social playbook): channel, department, a few words. */
function pm_touch(array &$lead, string $ch, string $dept, string $note = ''): void
{
    $lead['touches'][] = ['at' => date('Y-m-d H:i'), 'ch' => $ch, 'by' => $dept, 'note' => mb_substr($note, 0, 120)];
    $lead['touches'] = array_slice($lead['touches'], -30);
    $lead['last_out'] = date('Y-m-d H:i');
}

/** Every contact with a lead from any department, newest first: [at, ch, by]. Emails and WhatsApp come from what was sent, answers from the conversation, the rest from pm_touch. */
function pm_touches(array $l): array
{
    $t = [];
    foreach ((array)($l['sent'] ?? []) as $at) {
        $t[] = ['at' => (string)$at, 'ch' => 'email', 'by' => 'Sales'];
    }
    foreach ((array)($l['wa_sent'] ?? []) as $at) {
        $t[] = ['at' => (string)$at, 'ch' => 'whatsapp', 'by' => 'Sales'];
    }
    foreach ((array)($l['touches'] ?? []) as $x) {
        $t[] = ['at' => (string)($x['at'] ?? ''), 'ch' => (string)($x['ch'] ?? ''), 'by' => (string)($x['by'] ?? '')];
    }
    usort($t, fn($a, $b) => strcmp($b['at'], $a['at']));
    return array_values(array_filter($t, fn($x) => $x['at'] !== ''));
}

/** A channel key in words. */
function pm_touch_label(string $ch): string
{
    return ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'call' => 'Call', 'reply_comment' => 'Reply on Facebook', 'messenger' => 'Messenger', 'instagram_reply' => 'Reply on Instagram',
        'instagram_dm' => 'Instagram message', 'comment_on_their_page' => 'Comment on their Facebook Page'][$ch] ?? ucfirst(str_replace('_', ' ', $ch));
}

/** Contacted by any department in the last $days days. */
function pm_touched_recently(array $l, int $days = 2): bool
{
    $last = pm_touches($l)[0]['at'] ?? '';
    return $last !== '' && strtotime($last) > strtotime("-$days days");
}
