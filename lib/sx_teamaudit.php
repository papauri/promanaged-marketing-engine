<?php
/**
 * The Audit department (admin only): oversight of the marketing team.
 *   Activity log   every sign-in, failed sign-in, sign-out, action (with what it said back), page opened and agent line, kept for good in data/audit/YYYY-MM.jsonl
 *   Follow-ups     first messages, follow-ups, answers, replies and wins over any period, per person, and the follow-ups that are late right now (read from the leads)
 *   AI auditor     one AI call reads the period's numbers (never the messages themselves) and writes the audit: findings, each person, what to check next. Weekly by itself.
 *   pm_ta_log($kind, $what, $detail, $x)   one line in the log (never throws)
 *   pm_ta_post_done($action, $post, $vb)    index.php: what a POST did, written when the request ends
 *   pm_ta_read($from, $to)                  the log lines of a period, newest first
 *   pm_ta_followups($range, $brand, $by)    the follow-up report
 *   pm_ta_ai_run($range, $by)               the AI audit, saved in data/audit_reports.json
 */

const PM_TA_KEEP_MONTHS = 24;
const PM_TA_KINDS = ['signin' => 'Signed in', 'signin_fail' => 'Failed sign-in', 'signout' => 'Signed out', 'action' => 'Action', 'denied' => 'Refused', 'view' => 'Opened a page', 'agent' => 'Agent'];

function pm_ta_dir(): string
{
    return PM_DATA . '/audit';
}

/** Who is acting now: [username, name, role]. The scheduler and the agents are "system". */
function pm_ta_actor(): array
{
    $u = function_exists('pm_user') ? pm_user() : null;
    if ($u) {
        return [(string)$u['username'], (string)$u['name'], (string)$u['role']];
    }
    if (PHP_SAPI === 'cli' || !isset($_SERVER['REMOTE_ADDR'])) {
        return ['system', 'System', 'system'];
    }
    $who = trim((string)($GLOBALS['PM_WHO'] ?? ''));
    return ['', $who !== '' ? $who : 'Owner (no accounts)', 'admin'];
}

/** One line in the log. $x may set user, name, role, brand, ok, lead, flag. Never throws: the log must not break the app. */
function pm_ta_log(string $kind, string $what, string $detail = '', array $x = []): void
{
    try {
        [$u, $n, $r] = pm_ta_actor();
        $row = ['at' => date('Y-m-d H:i:s'), 'kind' => $kind, 'user' => $u, 'name' => $n, 'role' => $r, 'brand' => function_exists('pm_brand') ? pm_brand() : '',
            'what' => mb_substr($what, 0, 160), 'detail' => mb_substr(preg_replace('/\s+/u', ' ', $detail), 0, 400), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
        $row = array_merge($row, array_intersect_key($x, array_flip(['user', 'name', 'role', 'brand', 'ok', 'lead', 'flag', 'area', 'ua'])));
        $dir = pm_ta_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . '/' . date('Y-m') . '.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
    }
}

/** Which part of the firm a POST action belongs to. */
function pm_ta_area(string $action): string
{
    static $map = [
        'Sales' => ['agents', 'wa_log', 'proposal', 'reuse', 'clear', 'template', 'sendschedule', 'msgdefault', 'share', 'lines', 'reset_line'],
        'Social' => ['social', 'social_x', 'social_ext', 'fbm', 'fbg', 'social_check', 'social_accounts'],
        'Brand' => ['brand_cancel', 'brand_draft', 'brand_redraft', 'brand_create', 'brand_restudy', 'brand_study_apply', 'brand_archive', 'brand_unhide', 'brand_delete', 'brand_save'],
        'AI help' => ['compose', 'reword_text', 'suggest_words'],
        'Operations' => ['settings', 'access', 'smtp_check', 'health_check', 'archive_restore', 'link_refresh', 'page_reset'],
        'Deletes' => ['admin_delete'],
        'Audit' => ['audit'],
        'Account' => ['who'],
    ];
    foreach ($map as $area => $acts) {
        if (in_array($action, $acts, true)) {
            return $area;
        }
    }
    return 'Other';
}

/** Actions an auditor looks at twice: deleting, accounts, businesses removed or hidden, data restored, pages reset. */
function pm_ta_risky(string $action, string $do): bool
{
    return in_array($action, ['admin_delete', 'access', 'brand_delete', 'brand_archive', 'archive_restore', 'page_reset'], true)
        || ($action === 'agents' && in_array($do, ['delete', 'lead_forget'], true));
}

/** index.php, on every POST: written when the request ends, with what the app said back (the flash) or how it ended. Passwords and message texts are never kept. */
function pm_ta_post_done(string $action, array $post, string $vb): void
{
    $do = preg_replace('/[^a-z0-9_]/', '', (string)($post['do'] ?? $post['what'] ?? $post['_sec'] ?? ''));
    $what = str_replace('_', ' ', $action) . ($do !== '' ? ' · ' . str_replace('_', ' ', $do) : '');
    $target = [];
    $ids = array_values(array_filter(array_map('strval', array_merge((array)($post['ids'] ?? []), [$post['id'] ?? '', $post['lead'] ?? '', $post['lead_id'] ?? ''])), fn($v) => $v !== '' && preg_match('/^[A-Za-z0-9_-]{4,40}$/', $v)));
    $leadId = '';
    if ($ids) {
        $leads = function_exists('pm_leads_read') ? pm_leads_read() : [];
        $names = [];
        foreach (array_unique($ids) as $id) {
            if (isset($leads[$id])) {
                $names[] = (string)($leads[$id]['name'] ?? $id);
                $leadId = $leadId ?: $id;
            }
        }
        if ($names) {
            $target[] = count($names) > 3 ? implode(', ', array_slice($names, 0, 3)) . ' and ' . (count($names) - 3) . ' more' : implode(', ', $names);
        }
    }
    if ($action === 'access' && !empty($post['username'])) {
        $target[] = 'account ' . preg_replace('/[^a-z0-9._-]/', '', strtolower((string)$post['username']));
    }
    if (!empty($post['view']) && is_string($post['view'])) {
        $target[] = 'page ' . preg_replace('/[^a-z]/', '', $post['view']);
    }
    $flash = $_SESSION['flash'] ?? null;
    $err = error_get_last();
    $crashed = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    $code = http_response_code();
    $ok = $crashed ? false : (is_array($flash) ? ($flash[0] ?? '') !== 'err' : (!is_int($code) || $code < 400));
    $detail = $crashed ? 'Crashed: ' . (string)$err['message'] : (is_array($flash) ? (string)($flash[1] ?? '') : '');
    pm_ta_log('action', $what . ($target ? ' — ' . implode('; ', $target) : ''), $detail, ['ok' => $ok, 'brand' => $vb, 'area' => pm_ta_area($action), 'lead' => $leadId,
        'flag' => pm_ta_risky($action, (string)($post['do'] ?? '')) ? 'risk' : '']);
}

/** A page opened (a full screen, not pictures or background calls). The same page again within 15 minutes is not written twice. */
function pm_ta_view(string $tab, string $view, string $section, string $vb): void
{
    $page = $tab . ($view !== '' ? '/' . $view : '') . ($section !== '' ? '/' . $section : '');
    $last = (array)($_SESSION['ta_view'] ?? []);
    if (($last[0] ?? '') === $page . '|' . $vb && time() - (int)($last[1] ?? 0) < 900) {
        return;
    }
    $_SESSION['ta_view'] = [$page . '|' . $vb, time()];
    $label = ['home' => 'Overview', 'guide' => 'Guide'][$tab] ?? '';
    foreach (function_exists('pm_ui_nav') ? pm_ui_nav() : [] as $d) { // the name the person sees: "Sales › Leads"
        foreach ($d['groups'] as $items) {
            foreach ($items as $href => $lab) {
                if ($label === '' && pm_ui_href_on($href, $tab, $view, $section)) {
                    $label = $d['label'] . ' › ' . $lab;
                }
            }
        }
    }
    pm_ta_log('view', $label !== '' ? $label : $page, '', ['brand' => $vb, 'area' => 'Pages']);
}

/** The log lines between two dates (Y-m-d, both included), newest first. */
function pm_ta_read(string $from, string $to): array
{
    $rows = [];
    $m = substr($from, 0, 7);
    $end = substr($to, 0, 7);
    $lo = $from . ' 00:00:00';
    $hi = $to . ' 23:59:59';
    for ($i = 0; $m <= $end && $i < 60; $i++) {
        $f = pm_ta_dir() . "/$m.jsonl";
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $r = json_decode($line, true);
                if (is_array($r) && ($r['at'] ?? '') >= $lo && ($r['at'] ?? '') <= $hi) {
                    $rows[] = $r;
                }
            }
        }
        $m = date('Y-m', strtotime($m . '-01 +1 month'));
    }
    return array_reverse($rows);
}

/** Months of log older than the keep period are removed (weekly job). */
function pm_ta_prune(): void
{
    $min = date('Y-m', strtotime('first day of this month -' . PM_TA_KEEP_MONTHS . ' months'));
    foreach (glob(pm_ta_dir() . '/*.jsonl') ?: [] as $f) {
        if (basename($f, '.jsonl') < $min) {
            @unlink($f);
        }
    }
}

/* ---------------- Periods ---------------- */

const PM_TA_PRESETS = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'month' => 'This month', 'lastmonth' => 'Last month', '12m' => 'Last 12 months', 'custom' => 'Pick dates'];

/** The period asked for: from, to (Y-m-d), preset, label, days. */
function pm_ta_range(array $q, string $default = '7d'): array
{
    $p = isset(PM_TA_PRESETS[$q['range'] ?? '']) ? (string)$q['range'] : $default;
    $today = date('Y-m-d');
    $ago = fn(int $d) => date('Y-m-d', strtotime("-$d days"));
    [$from, $to] = match ($p) {
        'today' => [$today, $today],
        '30d' => [$ago(29), $today],
        '90d' => [$ago(89), $today],
        'month' => [date('Y-m-01'), $today],
        'lastmonth' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
        '12m' => [date('Y-m-d', strtotime('-12 months +1 day')), $today],
        default => [$ago(6), $today],
    };
    if ($p === 'custom') {
        $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
        $from = $ok($q['from'] ?? null) ? (string)$q['from'] : $ago(6);
        $to = $ok($q['to'] ?? null) ? (string)$q['to'] : $today;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > 731) {
            $from = date('Y-m-d', strtotime($to . ' -731 days'));
        }
    }
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $label = $p === 'custom' ? date('j M Y', strtotime($from)) . ' to ' . date('j M Y', strtotime($to)) : PM_TA_PRESETS[$p];
    return ['from' => $from, 'to' => $to, 'preset' => $p, 'label' => $label, 'days' => $days];
}

/** The period as query-string parts, for links that keep it. */
function pm_ta_range_qs(array $r): string
{
    return '&range=' . $r['preset'] . ($r['preset'] === 'custom' ? '&from=' . $r['from'] . '&to=' . $r['to'] : '');
}

/** Day, week (starting Monday) or month a time falls in. */
function pm_ta_bucket(string $at, string $by): string
{
    $d = substr($at, 0, 10);
    return match ($by) {
        'month' => substr($d, 0, 7),
        'week' => date('Y-m-d', strtotime($d . ' -' . ((int)date('N', strtotime($d)) - 1) . ' days')),
        default => $d,
    };
}

function pm_ta_bucket_label(string $k, string $by): string
{
    return match ($by) {
        'month' => date('M Y', strtotime($k . '-01')),
        'week' => 'Week of ' . date('j M', strtotime($k)),
        default => date('D j M', strtotime($k)),
    };
}

/** Every bucket of the period, oldest first. */
function pm_ta_buckets(array $r, string $by): array
{
    $out = [];
    for ($t = strtotime($r['from']); $t <= strtotime($r['to']) && count($out) < 800; $t = strtotime('+1 day', $t)) {
        $out[pm_ta_bucket(date('Y-m-d', $t), $by)] = true;
    }
    return array_keys($out);
}

/** The grouping that reads best for a period: days up to two weeks, weeks up to four months, then months. */
function pm_ta_auto_by(array $r, string $asked = ''): string
{
    return in_array($asked, ['day', 'week', 'month'], true) ? $asked : ($r['days'] <= 14 ? 'day' : ($r['days'] <= 120 ? 'week' : 'month'));
}

/* ---------------- Activity ---------------- */

/** Per person: sign-ins, failed sign-ins, actions, pages, deletes and other risky actions, actions out of hours, active minutes, last seen. Agents are left out. */
function pm_ta_people(array $rows): array
{
    $out = [];
    $times = [];
    foreach ($rows as $r) {
        if (($r['kind'] ?? '') === 'agent' || ($r['user'] ?? '') === 'system') {
            continue;
        }
        $key = (string)(($r['name'] ?? '') !== '' ? $r['name'] : ($r['user'] ?? '?'));
        $p = &$out[$key];
        $p ??= ['name' => $key, 'user' => (string)($r['user'] ?? ''), 'signins' => 0, 'fails' => 0, 'actions' => 0, 'failed_actions' => 0, 'views' => 0, 'risky' => 0, 'late' => 0, 'last' => '', 'minutes' => 0, 'areas' => []];
        $k = (string)($r['kind'] ?? '');
        $p['signins'] += $k === 'signin' ? 1 : 0;
        $p['fails'] += $k === 'signin_fail' ? 1 : 0;
        $p['views'] += $k === 'view' ? 1 : 0;
        if ($k === 'action') {
            $p['actions']++;
            $p['failed_actions'] += ($r['ok'] ?? true) === false ? 1 : 0;
            $p['risky'] += ($r['flag'] ?? '') === 'risk' ? 1 : 0;
            $a = (string)($r['area'] ?? 'Other');
            $p['areas'][$a] = ($p['areas'][$a] ?? 0) + 1;
            $h = (int)substr((string)$r['at'], 11, 2);
            $p['late'] += ($h < 7 || $h >= 20 || (int)date('N', strtotime((string)$r['at'])) > 5) ? 1 : 0;
        }
        if ($k !== 'signin_fail') {
            $p['last'] = max($p['last'], (string)$r['at']);
            $times[$key][] = strtotime((string)$r['at']);
        }
        unset($p);
    }
    foreach ($times as $key => $ts) { // active time: the gaps between a person's clicks that are under 20 minutes, plus a minute per stretch
        sort($ts);
        $sec = 0;
        foreach ($ts as $i => $t) {
            $gap = $i ? $t - $ts[$i - 1] : PHP_INT_MAX;
            $sec += $gap <= 1200 ? $gap : 60;
        }
        $out[$key]['minutes'] = (int)round($sec / 60);
    }
    uasort($out, fn($a, $b) => [$b['actions'], $b['last']] <=> [$a['actions'], $a['last']]);
    return $out;
}

/** Minutes as "3 h 20 min". */
function pm_ta_dur(int $min): string
{
    return $min >= 60 ? intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : '') : $min . ' min';
}

/* ---------------- Follow-ups ---------------- */

/**
 * Everything that happened to the leads, as events: first (the first message to a lead, email or WhatsApp), followup (every message after it), answer (our answer to
 * something they wrote), reply (they wrote), proposal, won, lost. "by" is who sent it (from the lead's note); a message the drip sent on its own is "Automatic".
 * "owner" is who owns the lead. $brands: the businesses to read.
 */
function pm_ta_lead_events(array $leads, array $brands): array
{
    $ev = [];
    foreach ($leads as $id => $l) {
        $b = (string)($l['brand'] ?? 'promanaged');
        if (!is_array($l) || !in_array($b, $brands, true)) {
            continue;
        }
        $owner = trim((string)($l['owner'] ?? ''));
        $base = ['lead' => (string)($l['id'] ?? $id), 'name' => (string)($l['name'] ?? ''), 'brand' => $b, 'owner' => $owner !== '' ? $owner : 'Nobody'];
        $noteBy = [];
        foreach ((array)($l['notes'] ?? []) as $n) {
            if (is_array($n) && preg_match('/\bsent\b/i', (string)($n['text'] ?? ''))) {
                $noteBy[substr((string)($n['at'] ?? ''), 0, 16)] = trim((string)($n['by'] ?? '')) !== '' ? trim((string)$n['by']) : 'Automatic';
            }
        }
        $out = [];
        foreach ((array)($l['sent'] ?? []) as $t) {
            $out[] = [substr((string)$t, 0, 16), 'email'];
        }
        foreach ((array)($l['wa_sent'] ?? []) as $t) {
            $out[] = [substr((string)$t, 0, 16), 'wa'];
        }
        usort($out, fn($x, $y) => strcmp($x[0], $y[0]));
        $outTimes = [];
        $prev = '';
        foreach ($out as $i => [$t, $ch]) {
            if ($t === '') {
                continue;
            }
            $outTimes[$t] = true;
            $ev[] = $base + ['at' => $t, 'type' => $i === 0 ? 'first' : 'followup', 'ch' => $ch, 'by' => $noteBy[$t] ?? ($owner !== '' ? $owner : 'Unknown'),
                'gap_h' => $prev !== '' ? (int)round((strtotime($t) - strtotime($prev)) / 3600) : null, 'winback' => $t === substr((string)($l['winback_sent_at'] ?? ''), 0, 16)];
            $prev = $t;
        }
        $lastIn = '';
        foreach ((array)($l['thread'] ?? []) as $m) {
            $t = substr((string)($m['at'] ?? ''), 0, 16);
            if (!is_array($m) || $t === '') {
                continue;
            }
            if (($m['dir'] ?? '') === 'in') {
                $ev[] = $base + ['at' => $t, 'type' => 'reply', 'ch' => (string)($m['ch'] ?? 'email'), 'by' => $base['owner']];
                $lastIn = $lastIn ?: $t;
            } elseif (($m['dir'] ?? '') === 'out' && !isset($outTimes[$t])) {
                $ev[] = $base + ['at' => $t, 'type' => 'answer', 'ch' => (string)($m['ch'] ?? 'email'), 'by' => $noteBy[$t] ?? $base['owner'],
                    'wait_h' => $lastIn !== '' ? round((strtotime($t) - strtotime($lastIn)) / 3600, 1) : null];
                $lastIn = '';
            }
        }
        foreach (['proposal_sent_at' => 'proposal', 'won_at' => 'won', 'lost_at' => 'lost'] as $f => $type) {
            if (!empty($l[$f]) && is_string($l[$f])) {
                $ev[] = $base + ['at' => substr($l[$f], 0, 16), 'type' => $type, 'ch' => '', 'by' => $base['owner']];
            }
        }
    }
    return $ev;
}

/** Follow-ups that are late right now, for the businesses given: lead, business, owner, days silent, days late, whether a follow-up is drafted. Latest first. */
function pm_ta_overdue(array $leads, array $brands): array
{
    $rows = [];
    if (!function_exists('pm_followup_due')) {
        return $rows;
    }
    foreach ($brands as $b) {
        $cfg = pm_agents_config($b);
        $mine = array_filter($leads, fn($l) => is_array($l) && ($l['brand'] ?? 'promanaged') === $b);
        foreach (pm_followup_due($mine, $cfg) as $id => $l) {
            $silent = (int)floor((time() - (strtotime((string)($l['last_contacted'] ?? '')) ?: time())) / 86400);
            $rows[] = ['lead' => (string)($l['id'] ?? $id), 'name' => (string)($l['name'] ?? ''), 'brand' => $b, 'owner' => trim((string)($l['owner'] ?? '')) ?: 'Nobody',
                'silent' => $silent, 'late' => max(0, $silent - (int)($cfg['followup_days'] ?? 4)), 'drafted' => !empty($l['followup_draft']), 'n' => (int)($l['followups'] ?? 0)];
        }
    }
    usort($rows, fn($a, $b) => $b['late'] <=> $a['late']);
    return $rows;
}

/**
 * The follow-up report for a period. $brand '' = every business. $by: day, week or month.
 * tot and each bucket: first, followups, leads (followed up, distinct), answers, replies, proposals, won, lost, ontime (follow-ups sent within the follow-up wait + 2 days), auto.
 * people: per person, what they sent (by) and what came back on the leads they own (owner).
 */
function pm_ta_followups(array $r, string $brand, string $by): array
{
    $brands = $brand !== '' && in_array($brand, pm_brand_ids(), true) ? [$brand] : pm_brand_ids();
    $leads = function_exists('pm_leads_read') ? pm_leads_read() : pm_load('leads', fn() => []);
    $lo = $r['from'] . ' 00:00';
    $hi = $r['to'] . ' 23:59';
    $zero = ['first' => 0, 'followups' => 0, 'leads' => [], 'answers' => 0, 'replies' => 0, 'proposals' => 0, 'won' => 0, 'lost' => 0, 'ontime' => 0, 'auto' => 0, 'wa' => 0];
    $tot = $zero;
    $series = array_fill_keys(pm_ta_buckets($r, $by), $zero);
    $people = [];
    $wait = [];
    $waitBy = [];
    $fdays = [];
    foreach ($brands as $b) {
        $fdays[$b] = (int)(pm_agents_config($b)['followup_days'] ?? 4);
    }
    $person = function (string $n) use (&$people, $zero) {
        $people[$n] ??= $zero + ['name' => $n, 'owned_due' => 0, 'owned_late' => 0];
    };
    foreach (pm_ta_lead_events($leads, $brands) as $e) {
        if ($e['at'] < $lo || $e['at'] > $hi) {
            continue;
        }
        $k = pm_ta_bucket($e['at'], $by);
        $add = function (array &$x) use ($e, $fdays) {
            switch ($e['type']) {
                case 'first':
                    $x['first']++;
                    break;
                case 'followup':
                    $x['followups']++;
                    $x['leads'][$e['lead']] = true;
                    $x['ontime'] += $e['gap_h'] !== null && $e['gap_h'] <= ($fdays[$e['brand']] + 2) * 24 ? 1 : 0;
                    break;
                case 'answer':
                    $x['answers']++;
                    break;
                case 'reply':
                    $x['replies']++;
                    break;
                case 'proposal':
                    $x['proposals']++;
                    break;
                default:
                    $x[$e['type']]++;
            }
            if (in_array($e['type'], ['first', 'followup'], true)) {
                $x['auto'] += $e['by'] === 'Automatic' ? 1 : 0;
                $x['wa'] += $e['ch'] === 'wa' ? 1 : 0;
            }
        };
        $add($tot);
        if (isset($series[$k])) {
            $add($series[$k]);
        }
        $who = in_array($e['type'], ['first', 'followup', 'answer'], true) ? $e['by'] : $e['owner']; // what you sent, and what came back on your leads
        $person($who);
        $add($people[$who]);
        if ($e['type'] === 'answer' && $e['wait_h'] !== null) {
            $wait[] = $e['wait_h'];
            $waitBy[$who][] = $e['wait_h'];
        }
    }
    $overdue = pm_ta_overdue($leads, $brands);
    foreach ($overdue as $o) {
        $person($o['owner']);
        $people[$o['owner']]['owned_due']++;
        $people[$o['owner']]['owned_late'] += $o['late'] >= 3 ? 1 : 0;
    }
    $fin = function (array $x) {
        $x['leads'] = count($x['leads']);
        $x['ontime_pct'] = $x['followups'] ? (int)round(100 * $x['ontime'] / $x['followups']) : null;
        return $x;
    };
    foreach ($people as $n => $p) {
        $people[$n] = $fin($p) + ['answer_h' => !empty($waitBy[$n]) ? round(array_sum($waitBy[$n]) / count($waitBy[$n]), 1) : null];
    }
    uasort($people, fn($a, $b) => [$b['followups'] + $b['first'], $b['owned_due']] <=> [$a['followups'] + $a['first'], $a['owned_due']]);
    return ['tot' => $fin($tot) + ['answer_h' => $wait ? round(array_sum($wait) / count($wait), 1) : null], 'series' => array_map($fin, $series), 'people' => $people, 'overdue' => $overdue, 'brands' => $brands, 'by' => $by];
}

/* ---------------- AI auditor ---------------- */

function pm_ta_reports(): array
{
    return pm_load('audit_reports', fn() => []);
}

function pm_ta_conf(?array $set = null): array
{
    if ($set !== null) {
        pm_save('audit_conf', $set);
        return $set;
    }
    return pm_load('audit_conf', fn() => ['weekly' => true]) + ['weekly' => true];
}

/** The numbers the AI auditor reads for a period: activity per person, follow-ups (overall, per person, trend), late follow-ups, security signals, agent health. No message text. */
function pm_ta_ai_facts(array $r): array
{
    $rows = pm_ta_read($r['from'], $r['to']);
    $people = pm_ta_people($rows);
    $by = pm_ta_auto_by($r);
    $fu = pm_ta_followups($r, '', $by);
    $accounts = function_exists('pm_users') ? pm_users() : [];
    $team = [];
    foreach ($accounts as $u => $a) { // everyone who can sign in, also those who did nothing
        $p = null;
        foreach ($people as $pp) {
            if ($pp['user'] === $u || $pp['name'] === $a['name']) {
                $p = $pp;
            }
        }
        $f = $fu['people'][$a['name']] ?? null;
        $team[] = ['name' => $a['name'], 'role' => $a['role'], 'account_on' => $a['on'], 'signins' => $p['signins'] ?? 0, 'failed_signins' => $p['fails'] ?? 0,
            'active_time' => pm_ta_dur((int)($p['minutes'] ?? 0)), 'last_seen' => $p['last'] ?? 'not in this period', 'actions' => $p['actions'] ?? 0, 'failed_actions' => $p['failed_actions'] ?? 0,
            'actions_by_area' => $p['areas'] ?? [], 'pages_opened' => $p['views'] ?? 0, 'risky_actions' => $p['risky'] ?? 0, 'actions_out_of_hours' => $p['late'] ?? 0,
            'first_messages_sent' => $f['first'] ?? 0, 'followups_sent' => $f['followups'] ?? 0, 'followups_on_time_pct' => $f['ontime_pct'] ?? null, 'answers_to_replies' => $f['answers'] ?? 0,
            'avg_hours_to_answer' => $f['answer_h'] ?? null, 'replies_on_their_leads' => $f['replies'] ?? 0, 'won_on_their_leads' => $f['won'] ?? 0,
            'their_leads_due_followup_now' => $f['owned_due'] ?? 0, 'their_leads_late_3plus_days' => $f['owned_late'] ?? 0];
    }
    foreach (['Automatic', 'Nobody', 'Unknown'] as $n) {
        if (isset($fu['people'][$n])) {
            $f = $fu['people'][$n];
            $team[] = ['name' => $n === 'Automatic' ? 'Automatic sending (drip)' : "Leads with no owner ($n)", 'first_messages_sent' => $f['first'], 'followups_sent' => $f['followups'],
                'their_leads_due_followup_now' => $f['owned_due'], 'their_leads_late_3plus_days' => $f['owned_late']];
        }
    }
    $risky = array_values(array_map(fn($x) => $x['at'] . ' ' . $x['name'] . ': ' . $x['what'] . ($x['detail'] !== '' ? ' (' . mb_substr($x['detail'], 0, 120) . ')' : ''),
        array_slice(array_filter($rows, fn($x) => ($x['flag'] ?? '') === 'risk'), 0, 25)));
    $agentErr = [];
    foreach ($rows as $x) {
        if (($x['kind'] ?? '') === 'agent' && ($x['ok'] ?? true) === false) {
            $key = mb_substr(preg_replace('/\d+/', '#', (string)$x['what'] . ': ' . (string)$x['detail']), 0, 110);
            $agentErr[$key] = ($agentErr[$key] ?? 0) + 1;
        }
    }
    arsort($agentErr);
    $t = $fu['tot'];
    $trend = [];
    foreach ($fu['series'] as $k => $s) {
        $trend[pm_ta_bucket_label($k, $by)] = ['first' => $s['first'], 'followups' => $s['followups'], 'replies' => $s['replies'], 'won' => $s['won']];
    }
    return [
        'period' => ['from' => $r['from'], 'to' => $r['to'], 'days' => $r['days']],
        'businesses' => array_map('pm_brand_name', $fu['brands']),
        'rules' => ['followup_after_days' => array_combine(array_map('pm_brand_name', $fu['brands']), array_map(fn($b) => (int)(pm_agents_config($b)['followup_days'] ?? 4), $fu['brands'])),
            'max_followups' => (int)(pm_agents_config('promanaged')['max_followups'] ?? 3), 'on_time_means' => 'sent within the follow-up wait plus 2 days of the previous message'],
        'followups' => ['first_messages' => $t['first'], 'followups_sent' => $t['followups'], 'leads_followed_up' => $t['leads'], 'on_time_pct' => $t['ontime_pct'], 'sent_automatically' => $t['auto'],
            'by_whatsapp' => $t['wa'], 'replies_received' => $t['replies'], 'answers_sent' => $t['answers'], 'avg_hours_to_answer' => $t['answer_h'], 'proposals_sent' => $t['proposals'], 'won' => $t['won'], 'lost' => $t['lost'],
            'due_now' => count($fu['overdue']), 'late_3plus_days_now' => count(array_filter($fu['overdue'], fn($o) => $o['late'] >= 3)), 'late_without_a_draft' => count(array_filter($fu['overdue'], fn($o) => !$o['drafted']))],
        'trend' => $trend,
        'team' => $team,
        'security' => ['failed_signins' => count(array_filter($rows, fn($x) => ($x['kind'] ?? '') === 'signin_fail')), 'refused' => count(array_filter($rows, fn($x) => ($x['kind'] ?? '') === 'denied')),
            'signin_ips' => count(array_unique(array_map(fn($x) => $x['ip'] ?? '', array_filter($rows, fn($x) => ($x['kind'] ?? '') === 'signin')))), 'risky_actions' => $risky],
        'agents' => ['lines' => count(array_filter($rows, fn($x) => ($x['kind'] ?? '') === 'agent')), 'errors' => array_sum($agentErr), 'top_errors' => array_slice($agentErr, 0, 6, true)],
        'log_started' => pm_ta_log_start(),
    ];
}

/** When the activity log began (its first line), or '' when it has none. */
function pm_ta_log_start(): string
{
    $files = glob(pm_ta_dir() . '/*.jsonl') ?: [];
    sort($files);
    foreach ($files as $f) {
        $h = @fopen($f, 'r');
        $line = $h ? fgets($h) : false;
        if ($h) {
            fclose($h);
        }
        $r = $line ? json_decode($line, true) : null;
        if (is_array($r) && !empty($r['at'])) {
            return (string)$r['at'];
        }
    }
    return '';
}

/** The AI audit for a period: one AI call over the numbers; saved and returned. $by: who asked ('weekly' for the scheduler). Throws when the AI cannot be reached. */
function pm_ta_ai_run(array $r, string $by): array
{
    if (function_exists('pm_agents_ready') && !pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        throw new RuntimeException('There is no AI key yet (Operations > Connections).');
    }
    $facts = pm_ta_ai_facts($r);
    $s = pm_settings();
    $system = 'You are the internal auditor of a small marketing firm (' . $s['company_name'] . ') that sells for these businesses: ' . implode(', ', $facts['businesses']) . '. '
        . 'You report to the owner (the admin). You read activity and follow-up numbers for a period and write a fair, specific audit of the marketing team and its AI agents. '
        . 'Rules: use only the numbers given; quote them as evidence; never invent people, leads, numbers or events; if the log started inside the period, say the early part is not covered; '
        . 'judge follow-up discipline first (leads due or late now, on-time rate, speed of answering replies), then effort and focus (active time, actions by area), then control and security '
        . '(failed sign-ins, refused access, deletes and account changes, work out of hours), then the agents (errors). A person with no activity is a finding, not a crime. '
        . 'Be plain and short: no jargon, no exclamation marks. Every finding ends with one concrete action the owner can take this week. '
        . 'Reply JSON only: {"score":0-100,"grade":"A|B|C|D|E","headline":"one sentence","followups":"two or three sentences on follow-up discipline",'
        . '"findings":[{"severity":"high|medium|low","area":"Follow-ups|Effort|Control|Security|Agents|Data","finding":"...","evidence":"the numbers","action":"..."}],'
        . '"people":[{"name":"exact name from team","verdict":"good|watch|concern|inactive","summary":"one or two sentences","coaching":"one thing to do better"}],'
        . '"next_checks":["what to look at in the next audit"]}. At most 8 findings, most serious first.';
    $out = pm_agent_json(pm_claude($system, json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, 2600, 'std'));
    if (!is_array($out) || !isset($out['headline'])) {
        throw new RuntimeException('The AI did not return an audit. Try again.');
    }
    $str = fn($v, int $n) => mb_substr(trim(strip_tags(is_scalar($v) ? (string)$v : '')), 0, $n);
    $names = array_column($facts['team'], 'name');
    $rep = [
        'id' => substr(md5(uniqid('', true)), 0, 10), 'at' => date('Y-m-d H:i'), 'from' => $r['from'], 'to' => $r['to'], 'label' => $r['label'], 'by' => $by,
        'score' => max(0, min(100, (int)($out['score'] ?? 0))), 'grade' => in_array($out['grade'] ?? '', ['A', 'B', 'C', 'D', 'E'], true) ? $out['grade'] : '',
        'headline' => $str($out['headline'], 300), 'followups' => $str($out['followups'] ?? '', 700),
        'findings' => array_slice(array_values(array_filter(array_map(fn($f) => is_array($f) ? ['severity' => in_array($f['severity'] ?? '', ['high', 'medium', 'low'], true) ? $f['severity'] : 'low',
            'area' => $str($f['area'] ?? '', 30), 'finding' => $str($f['finding'] ?? '', 400), 'evidence' => $str($f['evidence'] ?? '', 300), 'action' => $str($f['action'] ?? '', 300)] : null, (array)($out['findings'] ?? [])),
            fn($f) => $f && $f['finding'] !== '')), 0, 8),
        'people' => array_values(array_filter(array_map(fn($p) => is_array($p) && in_array($p['name'] ?? '', $names, true) ? ['name' => (string)$p['name'],
            'verdict' => in_array($p['verdict'] ?? '', ['good', 'watch', 'concern', 'inactive'], true) ? $p['verdict'] : 'watch', 'summary' => $str($p['summary'] ?? '', 400), 'coaching' => $str($p['coaching'] ?? '', 300)] : null,
            (array)($out['people'] ?? [])))),
        'next_checks' => array_slice(array_values(array_filter(array_map(fn($x) => $str($x, 200), (array)($out['next_checks'] ?? [])))), 0, 5),
        'facts' => $facts,
    ];
    pm_update('audit_reports', function (array $all) use ($rep) {
        array_unshift($all, $rep);
        return array_slice($all, 0, 60);
    }, fn() => []);
    return $rep;
}

/** Scheduler (once per run): the weekly AI audit of the last 7 days, the first time the scheduler runs on or after Monday 07:00; and old log months are removed. */
function pm_jobg_ta_weekly(): string
{
    $week = date('o-W');
    $conf = pm_ta_conf();
    if (empty($conf['weekly']) || ($conf['week'] ?? '') === $week || ((int)date('N') === 1 && (int)date('G') < 7)) {
        return '';
    }
    if (time() - (int)($conf['tried'] ?? 0) < 6 * 3600 || (function_exists('pm_agents_ready') && !pm_agents_ready())) {
        return '';
    }
    $conf['tried'] = time();
    pm_ta_conf($conf);
    pm_ta_prune();
    $r = ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => date('Y-m-d', strtotime('-1 day')), 'preset' => 'custom', 'days' => 7];
    $r['label'] = 'Week of ' . date('j M', strtotime($r['from']));
    try {
        $rep = pm_ta_ai_run($r, 'weekly');
    } catch (Throwable $e) {
        return 'weekly audit failed: ' . $e->getMessage();
    }
    $conf['week'] = $week;
    pm_ta_conf($conf);
    return 'weekly audit written (score ' . $rep['score'] . ')';
}

/** The admin's buttons on the Audit pages (action=audit): run the AI audit now, weekly audit on or off. Returns [message, ok, back]. */
function pm_ta_action(array $in): array
{
    $do = (string)($in['do'] ?? '');
    if ($do === 'weekly') {
        $c = pm_ta_conf();
        $c['weekly'] = !empty($in['on']);
        pm_ta_conf($c);
        return [$c['weekly'] ? 'The AI audit now runs by itself every Monday.' : 'The weekly AI audit is off. Run it by hand when you want.', true, 'audit&section=ai'];
    }
    if ($do === 'run') {
        @set_time_limit(240);
        $r = pm_ta_range($in, '7d');
        try {
            $rep = pm_ta_ai_run($r, (string)($GLOBALS['PM_WHO'] ?? 'admin'));
        } catch (Throwable $e) {
            return ['The AI audit could not run: ' . $e->getMessage(), false, 'audit&section=ai' . pm_ta_range_qs($r)];
        }
        return ['Audit written: ' . $rep['headline'], true, 'audit&section=ai&report=' . $rep['id']];
    }
    return ['Nothing to do.', false, 'audit'];
}
