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
    if (pm_brand_is_custom($brand)) { // an added business has no proposals: a lead won this month is a signed client
        foreach (pm_leads_read() as $l) {
            $signed += ($l['brand'] ?? '') === $brand && ($l['status'] ?? '') === 'won' && substr((string)($l['won_at'] ?? $l['updated'] ?? ''), 0, 7) === $month ? 1 : 0;
        }
    }
    $tpl = pm_template();
    foreach (pm_brand_is_custom($brand) ? [] : pm_history() as $h) {
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

/* ---------------- everything we offer, kept in view ---------------- */

const PM_OFFER_STOP = ['including', 'items', 'stock', 'locally', 'local', 'friendly', 'payment', 'international', 'orders', 'finding', 'buying', 'delivering', 'everyday', 'sessions', 'products',
    'accounts', 'industry', 'system', 'systems', 'other', 'their', 'small', 'business', 'businesses', 'services', 'service', 'custom', 'internal', 'multi', 'tenant', 'management', 'their', 'about', 'where', 'which', 'user', 'users', 'post', 'launch', 'care', 'gear', 'tools', 'things', 'stuff'];

/** Each offering (Sales > Targeting and messages) with the words that show a text is about it: [['name', 'text', 'keys' => [...]]]. Words shared by two offerings do not count. */
function pm_offer_keys(string $brand): array
{
    $rows = [];
    $seen = [];
    foreach ((array)(pm_agents_config($brand)['offerings'] ?? []) as $o) {
        [$name, $desc] = array_map('trim', explode(':', (string)$o, 2)) + ['', ''];
        $words = array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($desc !== '' ? $desc : $name)) ?: [], fn($w) => mb_strlen($w) >= 4 && !in_array($w, PM_OFFER_STOP, true)));
        foreach ($words as $w) {
            $seen[$w] = ($seen[$w] ?? 0) + 1;
        }
        $rows[] = ['name' => $name, 'text' => (string)$o, 'keys' => array_values($words)];
    }
    foreach ($rows as $i => $r) {
        $rows[$i]['keys'] = array_values(array_filter($r['keys'], fn($w) => $seen[$w] === 1));
    }
    return $rows;
}

/** Which offerings a text speaks about: [offering name => true/false]. */
function pm_offer_in_text(string $brand, string $text): array
{
    $t = ' ' . mb_strtolower($text) . ' ';
    $out = [];
    foreach (pm_offer_keys($brand) as $r) {
        $hit = false;
        foreach ($r['keys'] as $w) {
            if (preg_match('/(?<![\p{L}])' . preg_quote(mb_strlen($w) >= 6 ? rtrim($w, 's') : $w, '/') . '/u', $t)) { // "laptops" also finds "laptop"
                $hit = true;
                break;
            }
        }
        $out[$r['name']] = $hit;
    }
    return $out;
}

/** How often each offering came up in this business's posts of the last $days days (published, approved or planned), least first: [name => n]. */
function pm_offer_coverage(string $brand, int $days = 30): array
{
    $n = array_fill_keys(array_column(pm_offer_keys($brand), 'name'), 0);
    if (!$n || !function_exists('pm_social_posts')) {
        return $n;
    }
    foreach (pm_social_posts() as $p) {
        if (($p['brand'] ?? 'promanaged') !== $brand || strtotime((string)($p['when'] ?? $p['created'] ?? '')) < strtotime("-$days days") || in_array($p['status'] ?? '', ['deleted', 'rejected'], true)) {
            continue;
        }
        foreach (pm_offer_in_text($brand, ($p['headline'] ?? '') . ' ' . ($p['sub'] ?? '') . ' ' . ($p['caption'] ?? '')) as $k => $hit) {
            $n[$k] += $hit ? 1 : 0;
        }
    }
    asort($n);
    return $n;
}

/** Every offering in one compact line for the agents' instructions, so no writer forgets one ('' when the business lists none). */
function pm_offer_line(string $brand): string
{
    $o = array_values(array_filter(array_map(fn($x) => trim(mb_substr((string)$x, 0, 220)), (array)(pm_agents_config($brand)['offerings'] ?? []))));
    return $o ? 'Everything we offer (never present us as only one of these): ' . implode(' | ', $o) . '.' : '';
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
    if (($l['variant']['from'] ?? '') === 'starter') { // the first draft came free from the day's study
        return 'Study starter';
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

/* ---------------- one message: what every department knows about selling ---------------- */

/** What our own website checks found across the business's studied leads (code facts, no AI): [['need' => words, 'n' => leads, 'of' => studied]], most common first. */
const PM_FIRM_NEEDS = [
    'no way to book or reserve on their website' => '/no way to book or reserve/i',
    'no WhatsApp button on their website' => '/no WhatsApp button or link/i',
    'a website not set up for phones' => '/not set up for phones/i',
    'a website that would not open' => '/did not open for us/i',
    'a website without a secure https address' => '/without a secure https address/i',
    'an out-of-date website' => '/footer still says/i',
    'no contact form or email on their website' => '/No contact form or email address/i',
];

function pm_firm_needs(string $brand): array
{
    $count = array_fill_keys(array_keys(PM_FIRM_NEEDS), 0);
    $of = 0;
    $noSite = 0;
    foreach (pm_leads_read() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || empty($l['research']['at'])) {
            continue;
        }
        $of++;
        $facts = implode("\n", array_map(fn($d) => (string)($d['fact'] ?? ''), (array)($l['research']['discoveries'] ?? [])));
        $noSite += trim((string)($l['website'] ?? '')) === '' ? 1 : 0;
        foreach (PM_FIRM_NEEDS as $need => $re) {
            $count[$need] += preg_match($re, $facts) ? 1 : 0;
        }
    }
    $count['no website at all'] = $noSite;
    arsort($count);
    $out = [];
    foreach ($count as $need => $n) {
        if ($of >= 5 && $n * 4 >= $of) { // a need at least a quarter of the studied leads share, once enough are studied to say
            $out[] = ['need' => $need, 'n' => $n, 'of' => $of];
        }
    }
    return array_slice($out, 0, 3);
}

/**
 * One line every department's writer reads, so Sales, Social, campaigns and the Director pull the same way: the month's goal and pace, what is happening where we sell
 * (the market pulse: forex, fuel, the season...), and what the businesses we study really lack (our own checks). No AI; about 80 tokens.
 */
function pm_firm_line(string $brand): string
{
    static $memo = [];
    if (isset($memo[$brand])) {
        return $memo[$brand];
    }
    $g = pm_firm_goal($brand);
    $bits = [];
    if ($g['line'] !== '') {
        $bits[] = 'Common goal: selling. ' . ($g['pace'] === 'behind' ? 'We are behind this month, so every piece must ask for an enquiry (reply, message or sign up), not only build the brand.' : 'Every piece should move people towards an enquiry.');
    }
    $needs = pm_firm_needs($brand);
    if ($needs) {
        $bits[] = 'What the businesses we study lack most (our own checks; use as the angle, never quote the numbers or name anyone): ' . implode('; ', array_map(fn($x) => $x['need'] . ' (' . $x['n'] . ' of ' . $x['of'] . ')', $needs)) . '.';
    }
    if (function_exists('pm_market_pulse_line') && ($p = pm_market_pulse_line($brand)) !== '') {
        $bits[] = $p;
    }
    if (function_exists('pm_tb_line') && ($tb = pm_tb_line($brand, 'social', 3)) !== '') {
        $bits[] = $tb;
    }
    return $memo[$brand] = implode(' ', $bits);
}

/** For the owner: the one message every department writes with (Sales, Social posts, campaigns, lead posts, the idea room, the advisor and the Director). '' when there is nothing yet. */
function pm_firm_card_html(string $brand): string
{
    $g = pm_firm_goal($brand);
    $needs = pm_firm_needs($brand);
    $pulse = function_exists('pm_market_pulse') ? (array)(pm_market_pulse($brand)['items'] ?? []) : [];
    if ($g['pace'] === 'no goal' && !$needs && !$pulse) {
        return '';
    }
    $h = '<h3 class="mt-2">What every department writes with</h3><p class="hint mt-0">Sales emails, Social posts, campaigns, lead posts, the idea room, this advisor and the Director all read the same three things, so the whole firm pushes one way: selling.</p><ul class="t-sm">';
    if ($g['pace'] !== 'no goal') {
        $h .= '<li><b>Goal:</b> ' . $g['signed'][0] . ' of ' . $g['signed'][1] . ' signed and ' . $g['replies'][0] . ' of ' . $g['replies'][1] . ' replies this month, ' . pm_h($g['pace']) . ($g['pace'] === 'behind' ? ': every piece asks for an enquiry' : '') . '.</li>';
    }
    if ($needs) {
        $h .= '<li><b>What the businesses we study lack:</b> ' . pm_h(implode('; ', array_map(fn($x) => $x['need'] . ' (' . $x['n'] . ' of ' . $x['of'] . ')', $needs))) . '.</li>';
    }
    foreach ($pulse as $x) {
        $h .= '<li><b>Where we sell now:</b> ' . pm_h($x['issue']) . ' — we help: ' . pm_h($x['helps']) . ' <a href="' . pm_h($x['source']) . '" target="_blank" rel="noopener noreferrer">source</a></li>';
    }
    return $h . '</ul>';
}