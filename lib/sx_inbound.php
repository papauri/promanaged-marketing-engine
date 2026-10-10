<?php
/**
 * Inbound speed: owner alerts (throttled, working hours only), response-time numbers, "buyers waiting", the traveller/host split,
 * and the form handlers for private replies and the Messenger human follow-up.
 */
require_once __DIR__ . '/sx_replies.php';

function pm_inb_now(): int { return function_exists('pm_now') ? pm_now() : time(); }

/** Alerts only between 07:00 and 20:00 Africa/Blantyre. */
function pm_inb_alert_hours_ok(?int $ts = null): bool
{
    $h = (int)date('G', $ts ?? pm_inb_now());
    return $h >= 7 && $h < 20;
}

/** Owner message. $GLOBALS['PM_INB_NOTIFY_STUB'] (tests) replaces the mail. */
function pm_inb_notify(string $subject, string $text): bool
{
    if (isset($GLOBALS['PM_INB_NOTIFY_STUB']) && is_callable($GLOBALS['PM_INB_NOTIFY_STUB'])) {
        return (bool)$GLOBALS['PM_INB_NOTIFY_STUB']($subject, $text);
    }
    if (function_exists('pm_social_notify')) {
        pm_social_notify($subject, $text);
        return true;
    }
    return false;
}

function pm_inb_hours_left(string $iso): float
{
    $t = strtotime($iso);
    return $t ? round(($t - pm_inb_now()) / 3600, 1) : 0.0;
}

/** Queue judged-item ids (or "t:<psid>" for a Messenger buyer) for the next owner alert. */
function pm_inbound_alert_queue(string $brand, array $ids): void
{
    $judged = (array)(pm_load('fb_judged', fn() => [])[$brand] ?? []);
    $ids = array_values(array_filter(array_unique(array_filter(array_map('strval', $ids))), fn($id) => empty($judged[$id]['alerted_at']) && empty($judged[$id]['answered'])));
    if (!$ids) {
        return;
    }
    pm_inb_update(function (array $d) use ($brand, $ids) {
        $q = (array)($d['alerts'][$brand]['queue'] ?? []);
        $d['alerts'][$brand]['queue'] = array_values(array_unique(array_merge($q, $ids)));
        $d['alerts'][$brand]['last_sent'] = (int)($d['alerts'][$brand]['last_sent'] ?? 0);
        return $d;
    });
}

/** One Messenger thread by the sender's id (from the cached inbox). */
function pm_inb_thread(string $brand, string $psid): ?array
{
    foreach (pm_fb_inbox($brand)['threads'] as $t) {
        if ($t['psid'] === $psid) {
            return $t;
        }
    }
    return null;
}

/** [subject, body, itemIds] for queued ids that still need an answer. */
function pm_inbound_alert_build(string $brand, array $ids): array
{
    $judged = (array)(pm_load('fb_judged', fn() => [])[$brand] ?? []);
    $label = pm_inb_label($brand);
    $app = pm_app_url();
    $lines = [];
    $sent = [];
    foreach ($ids as $id) {
        if (str_starts_with($id, 't:')) {
            $t = null;
            try {
                $t = pm_inb_thread($brand, substr($id, 2));
            } catch (Throwable) {
            }
            if (!$t || !$t['waiting']) {
                continue;
            }
            $last = '';
            $at = null;
            foreach ($t['messages'] as $m) {
                if (!$m['ours']) {
                    $last = $m['text'];
                    $at = $m['at'];
                }
            }
            $tpl = pm_reply_template($brand, $last, $t['who']);
            $left = isset($at) ? pm_inb_hours_left(date('c', strtotime($at) + 86400)) : 0;
            $lines[] = '- ' . $t['who'] . ' wrote on Messenger: "' . mb_substr(preg_replace('/\s+/', ' ', $last), 0, 160) . '"'
                . ($tpl ? "\n  Suggested reply: " . $tpl['text'] : '')
                . "\n  " . ($left > 0 ? "$left hours left to answer in Messenger (24-hour limit)" : 'The 24-hour Messenger window has passed: answer from the Facebook app')
                . "\n  Open: " . ($app !== '' ? $app . '/index.php?tab=social&view=inbox' : 'Social > Inbox');
            $sent[] = $id;
            continue;
        }
        $k = $judged[$id] ?? null;
        if (!$k || ($k['state'] ?? '') !== '' || !empty($k['answered']) || !empty($k['private_reply_at'])) {
            continue;
        }
        $tpl = trim((string)($k['reply'] ?? '')) !== '' ? $k['reply'] : (pm_reply_template($brand, (string)$k['text'], (string)$k['from'])['text'] ?? '');
        $left = ($k['kind'] ?? '') === 'comment' ? round((strtotime((string)$k['at']) + 7 * 86400 - pm_inb_now()) / 86400, 1) : 0;
        $lines[] = '- ' . $k['from'] . ' (' . ($k['verdict'] ?? 'question') . (($k['platform'] ?? 'fb') === 'ig' ? ', Instagram' : '') . ') wrote: "' . mb_substr(preg_replace('/\s+/', ' ', (string)$k['text']), 0, 160) . '"'
            . (($k['link'] ?? '') !== '' ? "\n  Post: " . $k['link'] : '')
            . ($tpl !== '' ? "\n  Suggested reply: " . $tpl : '')
            . ($left > 0 ? "\n  Can also be answered privately for $left more days (one private reply per comment)" : '')
            . "\n  Open: " . ($app !== '' ? $app . '/index.php?tab=social&view=growth' : 'Social > Growth');
        $sent[] = $id;
    }
    $n = count($sent);
    return ["[$label] $n on Facebook waiting for an answer (" . date('H:i', pm_inb_now()) . ')',
        "People are asking on the $label Page. Fast answers win sales.\n\n" . implode("\n\n", $lines), $sent];
}

/**
 * Sends the queued alert: at most one per 15 minutes per brand, only 07:00-20:00 (earlier items wait for 07:00).
 * Returns the number of items sent.
 */
function pm_inbound_alert_flush(string $brand, bool $force = false): int
{
    $now = pm_inb_now();
    if (!$force && !pm_inb_alert_hours_ok($now)) {
        return 0;
    }
    $st = (array)(pm_inb_get()['alerts'][$brand] ?? []);
    $queue = (array)($st['queue'] ?? []);
    if (!$queue || (!$force && $now - (int)($st['last_sent'] ?? 0) < 900)) {
        return 0;
    }
    [$subject, $body, $ids] = pm_inbound_alert_build($brand, $queue);
    pm_inb_update(function (array $d) use ($brand, $queue, $ids, $now) { // the queue is emptied either way: stale items are not worth an alert
        $d['alerts'][$brand]['queue'] = array_values(array_diff((array)($d['alerts'][$brand]['queue'] ?? []), $queue));
        if ($ids) {
            $d['alerts'][$brand]['last_sent'] = $now;
        }
        return $d;
    });
    if (!$ids) {
        return 0;
    }
    pm_inb_notify($subject, $body);
    pm_update('fb_judged', function (array $all) use ($brand, $ids, $now) {
        foreach ($ids as $id) {
            if (isset($all[$brand][$id])) {
                $all[$brand][$id]['alerted_at'] = date('c', $now);
            }
        }
        return $all;
    }, fn() => []);
    return count($ids);
}

function pm_job_inbound_alerts(string $brand): string
{
    $n = pm_inbound_alert_flush($brand);
    return $n ? "inbound alert sent for $n item(s)" : 'no inbound alerts due';
}

/* ---------------- speed numbers ---------------- */

/** How fast we answer buyers, questions and complaints on the Page (last 30 days, judged items). */
function pm_response_stats(string $brand): array
{
    $rows = (array)(pm_load('fb_judged', fn() => [])[$brand] ?? []);
    $now = pm_inb_now();
    $mins = [];
    $un = 0;
    foreach ($rows as $r) {
        if (!in_array($r['verdict'] ?? '', ['buyer', 'question', 'complaint'], true)) {
            continue;
        }
        $at = strtotime((string)($r['at'] ?? ''));
        if (!$at || $at < $now - 30 * 86400) {
            continue;
        }
        if (!empty($r['answered_at']) || !empty($r['private_reply_at'])) {
            $a = strtotime((string)($r['answered_at'] ?: $r['private_reply_at']));
            if ($a && $a >= $at) {
                $mins[] = ($a - $at) / 60;
            }
        } elseif (($r['state'] ?? '') === '' && empty($r['answered']) && $now - $at > 7200) {
            $un++;
        }
    }
    sort($mins);
    $n = count($mins);
    $pick = fn(float $q) => $n ? $mins[(int)min($n - 1, max(0, ceil($q * $n) - 1))] : 0;
    $under = count(array_filter($mins, fn($m) => $m <= 120));
    return ['n' => $n, 'median_min' => round((float)$pick(0.5), 1), 'p90_min' => round((float)$pick(0.9), 1),
        'pct_under_2h' => ($n + $un) ? (int)round(100 * $under / ($n + $un)) : 0, 'unanswered_over_2h' => $un];
}

/** "Buyers waiting" for the Today list, with the countdown. */
function pm_today_buyers(string $brand): array
{
    $rows = array_filter((array)(pm_load('fb_judged', fn() => [])[$brand] ?? []), fn($r) => in_array($r['verdict'] ?? '', ['buyer', 'question'], true)
        && ($r['state'] ?? '') === '' && empty($r['answered']) && empty($r['private_reply_at']) && strtotime((string)$r['at']) > pm_inb_now() - 14 * 86400);
    $threads = [];
    try {
        if (pm_social_cfg($brand)['ready']) {
            $threads = array_filter(pm_fb_inbox($brand)['threads'], fn($t) => $t['waiting'] && $t['window']);
        }
    } catch (Throwable) {
    }
    $n = count($rows) + count($threads);
    if (!$n) {
        return [];
    }
    $oldest = pm_inb_now();
    foreach ($rows as $r) {
        $oldest = min($oldest, strtotime((string)$r['at']));
    }
    $win = null;
    foreach ($threads as $t) {
        $last = 0;
        foreach ($t['messages'] as $m) {
            $last = !$m['ours'] ? strtotime($m['at']) : $last;
        }
        $oldest = min($oldest, $last ?: $oldest);
        $left = $last ? ($last + 86400 - pm_inb_now()) / 3600 : null;
        $win = $left === null ? $win : ($win === null ? $left : min($win, $left));
    }
    $age = round((pm_inb_now() - $oldest) / 3600, 1);
    $txt = "$n buyer(s) or question(s) waiting for an answer" . ($age >= 0.5 ? " (oldest $age h ago)" : '') . ($win !== null ? '; Messenger window closes in ' . max(0, round($win, 1)) . ' h' : '');
    return [['text' => $txt, 'href' => '?tab=social&view=' . ($rows ? 'growth' : 'inbox'), 'urgency' => ($age > 2 || ($win !== null && $win < 6)) ? 2 : 1]];
}

/* ---------------- traveller / host split ---------------- */

/** 'traveller' (wants a room), 'host' (owns or runs a stay and wants listing) or 'unknown'. */
function pm_classify_audience(string $text): string
{
    $host = '/\b(list(ing)? (my|our)|want to list|(my|our) (lodge|guest ?house|b&b|bnb|hotel|camp|chalet|cottage|property|resort|hostel|stay)|i (own|run|manage|operate)|we (own|run|manage|operate)|(guest ?house|lodge|hotel|camp) owner|owner of|sign me up|register (my|our)|advertise (my|our)|add (my|our))\b/iu';
    $trav = '/\b(rooms?|nights?|book(ing)?|reserv\w+|dates?|adults?|kids?|children|people|persons?|pax|guests?|family|couple|weekend|honeymoon|check.?in|from (mon|tue|wed|thu|fri|sat|sun|\d)|per night|rates?|prices?|available|availability|how much)\b/iu';
    if (preg_match($host, $text)) {
        return 'host';
    }
    return preg_match($trav, $text) ? 'traveller' : 'unknown';
}

/** Adds a traveller request to data/traveller_demand.json (never the host funnel). Returns the stored row. */
function pm_traveller_demand_add(array $d): array
{
    $brand = pm_brand_norm($d['brand'] ?? 'travel');
    $clip = fn($v, int $n) => mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string)$v)), 0, $n);
    $row = ['id' => (string)($d['id'] ?? substr(md5($brand . '|' . ($d['contact'] ?? '') . '|' . ($d['msg'] ?? '') . '|' . date('Y-m-d') . '|' . random_int(1, PHP_INT_MAX)), 0, 12)),
        'brand' => $brand, 'created' => date('Y-m-d H:i'), 'name' => $clip($d['name'] ?? '', 80), 'contact' => $clip($d['contact'] ?? '', 120), 'town' => $clip($d['town'] ?? '', 60),
        'dates' => $clip($d['dates'] ?? '', 80), 'party' => $clip($d['party'] ?? '', 40), 'stay_asked' => $clip($d['stay_asked'] ?? '', 100), 'consent_forward' => !empty($d['consent_forward']),
        'src_tag' => $clip($d['src_tag'] ?? '', 40), 'ref' => $clip($d['ref'] ?? '', 8), 'msg' => $clip($d['msg'] ?? '', 1500)];
    $dupe = false;
    pm_update('traveller_demand', function (array $rows) use ($row, &$dupe) {
        foreach ($rows as $r) {
            if (($r['id'] ?? '') === $row['id']) {
                $dupe = true;
                return $rows;
            }
        }
        $rows[] = $row;
        return array_values(array_slice($rows, -2000));
    }, fn() => []);
    return $row + ['dupe' => $dupe];
}

/** Traveller requests per town over the last $days days (towns as typed, first letter capital). */
function pm_traveller_demand_counts(string $brand, int $days = 30): array
{
    $cut = date('Y-m-d H:i', pm_inb_now() - $days * 86400);
    $out = [];
    foreach (pm_load('traveller_demand', fn() => []) as $r) {
        if (($r['brand'] ?? 'travel') === $brand && ($r['created'] ?? '') >= $cut) {
            $t = trim((string)($r['town'] ?? ''));
            $t = $t === '' ? 'Not given' : mb_strtoupper(mb_substr($t, 0, 1)) . mb_strtolower(mb_substr($t, 1));
            $out[$t] = ($out[$t] ?? 0) + 1;
        }
    }
    arsort($out);
    return $out;
}

/** Manual-assist WhatsApp share of a traveller request (opens the contact picker). Only with the traveller's consent. */
function pm_traveller_forward_link(array $r): string
{
    if (empty($r['consent_forward'])) {
        return '';
    }
    $t = 'A traveller asked Travel Malawi about a stay' . ($r['stay_asked'] !== '' ? ' (' . $r['stay_asked'] . ')' : '') . '. ' . implode(', ', array_filter([$r['town'] ? 'Town: ' . $r['town'] : '', $r['dates'] ? 'Dates: ' . $r['dates'] : '',
        $r['party'] ? 'People: ' . $r['party'] : '', 'Contact: ' . $r['name'] . ' ' . $r['contact']])) . '. They agreed to be contacted by the host.';
    return 'https://wa.me/?text=' . rawurlencode($t);
}

/** Tells the owner about a traveller request. */
function pm_traveller_notify(array $r): void
{
    $link = pm_traveller_forward_link($r);
    pm_inb_as('travel', fn() => pm_inb_notify('[Travel Malawi] Traveller asking for a stay: ' . ($r['name'] ?: 'someone'),
        "A traveller (not a host) asked for a stay. Not added to the host leads.\n\nName: {$r['name']}\nContact: {$r['contact']}\nTown: {$r['town']}\nDates: {$r['dates']}\nPeople: {$r['party']}\nStay asked about: {$r['stay_asked']}\n\nWhat they wrote:\n{$r['msg']}\n\nCame from: " . ($r['src_tag'] ?: 'direct')
        . ($link !== '' ? "\n\nThey agreed to be passed to a host. Forward by WhatsApp (you press send): $link" : "\n\nThey did not tick permission to pass their details on: reply to them yourself.")));
}

/* ---------------- handlers and panels ---------------- */

function pm_do_private_reply(string $vb): array
{
    $id = preg_replace('/[^0-9_]/', '', (string)($_POST['id'] ?? ''));
    $platform = ($_POST['platform'] ?? '') === 'ig' ? 'ig' : 'fb';
    $back = in_array($_POST['back'] ?? '', ['growth', 'page'], true) ? $_POST['back'] : 'growth';
    $row = (array)(pm_load('fb_judged', fn() => [])[$vb][$id] ?? []);
    $text = trim((string)($_POST['text'] ?? ''));
    if ($text === '') {
        $text = pm_reply_private_text($vb, (string)($row['text'] ?? $_POST['comment'] ?? ''), (string)($row['from'] ?? $_POST['from'] ?? ''));
    }
    [$ok, $m] = pm_fb_private_reply($vb, $id, $text, $platform);
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=' . $back];
}

function pm_do_human_followup(string $vb): array
{
    $txt = trim((string)($_POST['text'] ?? ''));
    if ($txt === '') {
        return ['msg' => 'Write the answer first.', 'kind' => 'err', 'to' => 'social&view=inbox'];
    }
    [$ok, $m] = pm_fb_message($vb, preg_replace('/\D/', '', (string)($_POST['psid'] ?? '')), $txt, true);
    pm_agent_log('Social', ($ok ? 'Messenger follow-up sent to ' : 'Messenger follow-up failed for ') . (string)($_POST['from'] ?? ''));
    return ['msg' => $m, 'kind' => $ok ? 'ok' : 'err', 'to' => 'social&view=inbox'];
}

/** Ticks a one-off to-do note (for example the Page WhatsApp button reference). */
function pm_do_inb_tick(string $vb): array
{
    $k = preg_replace('/[^a-z_]/', '', (string)($_POST['key'] ?? ''));
    pm_inb_update(function (array $d) use ($vb, $k) {
        $d['notes'][$vb][$k] = empty($d['notes'][$vb][$k]);
        return $d;
    });
    return ['msg' => 'Saved.', 'kind' => 'ok', 'to' => 'social&view=growth'];
}

function pm_inb_form(string $do, string $inner, string $cls = ''): string
{
    $csrf = (string)($GLOBALS['csrf'] ?? ($_SESSION['csrf'] ?? ''));
    return '<form method="post" class="' . pm_h($cls) . '"><input type="hidden" name="csrf" value="' . pm_h($csrf) . '"><input type="hidden" name="action" value="social_ext"><input type="hidden" name="do" value="' . pm_h($do) . '">' . $inner . '</form>';
}

/** Inbox tab: how fast we answer, and the Instagram state. */
function pm_panel_inbox_top_speed(string $vb, array $ctx = []): string
{
    $s = pm_response_stats($vb);
    $h = '<div class="card"><h2>Reply speed</h2>';
    if (!$s['n'] && !$s['unanswered_over_2h']) {
        $h .= '<p class="hint">No buyer or question answered yet in the last 30 days, so there is nothing to measure.</p>';
    } else {
        $h .= '<p>Median <b>' . pm_h((string)$s['median_min']) . ' min</b> · slowest tenth ' . pm_h((string)$s['p90_min']) . ' min · <b>' . (int)$s['pct_under_2h'] . '%</b> answered within 2 hours · <b>'
            . (int)$s['unanswered_over_2h'] . '</b> still waiting after 2 hours.</p>';
    }
    $ig = function_exists('pm_ig_state') ? pm_ig_state($vb) : 'off';
    if ($ig === 'needs_scope') {
        $h .= '<p class="hint warnt">Instagram comments and messages need more permissions (instagram_basic, instagram_manage_comments, instagram_manage_messages). Reconnect in Accounts &amp; branding with those ticked.</p>';
    }
    $h .= '<p class="hint">Facebook allows a normal reply within 24 hours of their last message. After that, and up to 7 days, you can press <b>Send as human follow-up</b> once you have a real answer. A comment can get one private reply within 7 days.</p></div>';
    return $h;
}

/** Growth tab: how to make Page WhatsApp-button enquiries traceable (cannot be done through the API). */
function pm_panel_growth_bottom_pagebtn(string $vb, array $ctx = []): string
{
    $done = !empty(pm_inb_get()['notes'][$vb]['page_btn_ref']);
    $wa = pm_inb_wa_digits($vb);
    return '<div class="card"><h2>Page WhatsApp button</h2><p class="hint">' . ($wa === '' ? 'Set the business phone in Settings first. ' : '')
        . 'In Facebook: Page &gt; Add action button &gt; Send WhatsApp message. Use the number ' . pm_h($wa !== '' ? '+' . $wa : '') . ' and, if Facebook lets you set a ready-made message, use <code>Hi, I found you on your Page (ref PAGE)</code> so enquiries from the button show where they came from. Facebook does not let this app set the button.</p>'
        . pm_inb_form('inb_tick', '<input type="hidden" name="key" value="page_btn_ref"><button class="btn small">' . ($done ? 'Done: undo' : 'I did this') . '</button>')
        . '</div>';
}
