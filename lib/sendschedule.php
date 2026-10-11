<?php
/**
 * The schedule for sending emails. Approved emails are not sent at once: a task that runs every 15 minutes (lib/send_due.php) sends one when sending is open. This file says when
 * it is open and how fast, per business, and holds the optional "send no earlier than" time of a single email.
 *
 * Per business (data/send_schedule.json): days (1 Monday ... 7 Sunday), from and to (HH:MM, Africa/Blantyre, the server's time), gap (about this many minutes between emails; the
 * task checks every 15 minutes, so it is never closer than that) and paused (hold everything). A business that never saved one sends Mon-Fri 08:00-16:30, as before.
 * Per email: the lead's send_after ("Y-m-d H:i"), set when approving or on the send queue; the email waits for that time and then for the next open slot.
 */

const PM_SS_DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

function pm_ss_defaults(): array
{
    return ['days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '16:30', 'gap' => 15, 'paused' => false];
}

/** "08:30" -> 510 (minutes since midnight), or -1 when it is not a time. */
function pm_ss_minutes(string $t): int
{
    return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($t), $m) ? (int)$m[1] * 60 + (int)$m[2] : -1;
}

/** The schedule of a business as it stands: ['days', 'from', 'to', 'gap', 'paused', 'saved']. */
function pm_ss_cfg(?string $brand = null): array
{
    $brand ??= pm_brand();
    $st = (array)(pm_load('send_schedule', fn() => [])[$brand] ?? []);
    $d = pm_ss_defaults();
    $days = isset($st['days']) && is_array($st['days']) ? array_values(array_unique(array_filter(array_map('intval', $st['days']), fn($x) => $x >= 1 && $x <= 7))) : $d['days'];
    sort($days);
    $from = pm_ss_minutes((string)($st['from'] ?? '')) >= 0 ? (string)$st['from'] : $d['from'];
    $to = pm_ss_minutes((string)($st['to'] ?? '')) >= 0 ? (string)$st['to'] : $d['to'];
    if (pm_ss_minutes($from) >= pm_ss_minutes($to)) {
        [$from, $to] = [$d['from'], $d['to']];
    }
    return ['days' => $days, 'from' => $from, 'to' => $to, 'gap' => max(5, min(240, (int)($st['gap'] ?? $d['gap']))), 'paused' => !empty($st['paused']), 'saved' => $st !== []];
}

/** Is sending open for this business at this moment? */
function pm_ss_open(string $brand, int $ts): bool
{
    $c = pm_ss_cfg($brand);
    $m = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    return !$c['paused'] && in_array((int)date('N', $ts), $c['days'], true) && $m >= pm_ss_minutes($c['from']) && $m <= pm_ss_minutes($c['to']);
}

/** The first moment at or after $ts when sending is open, or 0 when it never is (paused, or no day ticked). */
function pm_ss_next_open(string $brand, int $ts): int
{
    $c = pm_ss_cfg($brand);
    if ($c['paused'] || !$c['days']) {
        return 0;
    }
    for ($i = 0; $i <= 8; $i++) {
        $day = strtotime(date('Y-m-d', $ts) . " +$i day");
        if (!in_array((int)date('N', $day), $c['days'], true)) {
            continue;
        }
        $start = $day + pm_ss_minutes($c['from']) * 60;
        $end = $day + pm_ss_minutes($c['to']) * 60;
        if ($ts <= $end) {
            return max($ts, $start);
        }
    }
    return 0;
}

/** Seconds to wait after sending one email before the next may go: about the gap, a little short of it so the next 15-minute check qualifies. */
function pm_ss_next_gap(string $brand): int
{
    return max(0, pm_ss_cfg($brand)['gap'] * 60 - 180) + random_int(0, 120);
}

/** The time an email was told to wait for ("Y-m-d H:i"), as a timestamp, or 0 when it was not. */
function pm_ss_after(array $lead): int
{
    $a = trim((string)($lead['send_after'] ?? ''));
    return $a !== '' && ($t = strtotime($a)) !== false ? $t : 0;
}

/** May this email go now as far as its own time goes? */
function pm_ss_due(array $lead, int $now): bool
{
    return pm_ss_after($lead) <= $now;
}

/** A "datetime-local" value (2026-10-14T09:00) or "Y-m-d H:i" as "Y-m-d H:i"; '' for nothing, a time in the past or nonsense. */
function pm_ss_parse(string $v): string
{
    $v = trim(str_replace('T', ' ', $v));
    $t = $v !== '' ? strtotime($v) : false;
    return $t !== false && $t > pm_now() ? date('Y-m-d H:i', $t) : '';
}

/** "~14:30" today, "~Tue 08:00" this week, "~14 Oct 08:00" later. */
function pm_ss_fmt(int $t): string
{
    $now = pm_now();
    return date('Y-m-d', $t) === date('Y-m-d', $now) ? date('H:i', $t) : ($t - $now < 6 * 86400 ? date('D H:i', $t) : date('j M H:i', $t));
}

/**
 * When an approved email is expected to go, in words: 'sends ~14:30', 'sends ~Tue 08:00', 'scheduled ~Wed 09:00', 'sending is paused'. $pos is its place in the queue (0 = next);
 * $left is how many may still go today under the daily limit.
 */
function pm_ss_eta(array $lead, int $pos = 0, ?int $left = null): string
{
    if (empty($lead['approved_at'])) {
        return '';
    }
    $brand = (string)($lead['brand'] ?? pm_brand());
    $c = pm_ss_cfg($brand);
    if ($c['paused']) {
        return 'sending is paused';
    }
    if (!$c['days']) {
        return 'no sending day is ticked';
    }
    $now = pm_now();
    $after = pm_ss_after($lead);
    $t0 = max($now, $after, (int)(pm_ob_read('send_state')['next_send_at'] ?? 0));
    if ($left !== null && $pos >= $left) {
        $t0 = max($t0, strtotime('tomorrow'));
    }
    $slot = (int)(ceil(($t0 + $pos * $c['gap'] * 60) / 900) * 900); // the task runs every 15 minutes
    $t = pm_ss_next_open($brand, $slot);
    $t = $t > $slot ? (int)(ceil($t / 900) * 900) : $t;
    return $t === 0 ? 'no open time found' : ($after > $now ? 'scheduled ~' : 'sends ~') . pm_ss_fmt($t);
}

/** Saves one business's schedule from the form ($_POST['ss']). Returns [message, kind]. */
function pm_ss_save(string $brand): array
{
    if (!pm_brand_valid($brand)) {
        return ['Choose a business first.', 'err'];
    }
    $in = (array)($_POST['ss'] ?? []);
    $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['days'] ?? [])), fn($x) => $x >= 1 && $x <= 7)));
    sort($days);
    $from = trim((string)($in['from'] ?? ''));
    $to = trim((string)($in['to'] ?? ''));
    if (pm_ss_minutes($from) < 0 || pm_ss_minutes($to) < 0 || pm_ss_minutes($from) >= pm_ss_minutes($to)) {
        return ['Sending hours: "from" must be earlier than "to" (for example 08:00 and 16:30).', 'err'];
    }
    $row = ['days' => $days, 'from' => $from, 'to' => $to, 'gap' => max(5, min(240, (int)($in['gap'] ?? 15))), 'paused' => !empty($in['paused'])];
    pm_update('send_schedule', function (array $all) use ($brand, $row) {
        $all[$brand] = $row;
        return $all;
    }, fn() => []);
    $c = pm_ss_cfg($brand);
    return ['Saved the sending schedule for ' . pm_brand_name($brand) . ': ' . ($c['paused'] ? 'paused' : ($c['days'] ? implode(', ', array_map(fn($d) => PM_SS_DAYS[$d], $c['days'])) . ' ' . $c['from'] . ' to ' . $c['to'] . ', about every ' . $c['gap'] . ' minutes' : 'no sending day is ticked, so nothing will be sent')) . '.', $days || $c['paused'] ? 'ok' : 'err'];
}

/** Gives the approved emails among $ids a "send no earlier than" time ('' clears it). Returns how many changed. */
function pm_ss_set_after(array &$leads, array $ids, string $when): int
{
    $n = 0;
    foreach (array_unique($ids) as $id) {
        if (!empty($leads[$id]['approved_at']) && empty($leads[$id]['sent'])) {
            if ($when !== '') {
                $leads[$id]['send_after'] = $when;
                pm_lead_note($leads[$id], 'Scheduled to go no earlier than ' . $when);
            } elseif (!empty($leads[$id]['send_after'])) {
                unset($leads[$id]['send_after']);
                pm_lead_note($leads[$id], 'Send time cleared: goes out at the next open time');
            } else {
                continue;
            }
            $n++;
        }
    }
    $n && pm_leads_save($leads);
    return $n;
}

/** The schedule in a few words: "Mon-Fri 08:00-16:30, about every 15 minutes" or "paused". */
function pm_ss_summary(string $brand): string
{
    $c = pm_ss_cfg($brand);
    if ($c['paused']) {
        return 'but sending is paused';
    }
    if (!$c['days']) {
        return 'but no sending day is ticked';
    }
    $names = array_map(fn($d) => PM_SS_DAYS[$d], $c['days']);
    $run = $c['days'] === range($c['days'][0], $c['days'][count($c['days']) - 1]) && count($c['days']) > 2 ? $names[0] . '-' . $names[count($names) - 1] : implode(', ', $names);
    return $run . ' ' . $c['from'] . '-' . $c['to'] . ', about every ' . $c['gap'] . ' minutes';
}
