<?php
/**
 * Insights > Results, the sales side and the AI bill: the pipeline from first message to signed deal, week by week, by where leads came from and by kind of business,
 * and which agents used the AI tokens. Arithmetic on stored data only: no AI, no network, instant.
 *   pm_ins_weeks($brand, $n)     the last $n weeks: new leads, messages sent, first replies, proposals, signed
 *   pm_ins_sales_html($brand)    the "Sales" section at the top of Results
 *   pm_ins_ai_html($brand)       the AI use by agent (7 days) at the bottom of Results
 */

/** Monday of the week a time falls in (Y-m-d). */
function pm_ins_week(int $t): string
{
    return date('Y-m-d', strtotime('monday this week', $t));
}

/** The last $n weeks, oldest first: Monday => [leads, sent (emails and WhatsApp), replies (first answer from a lead), proposals, signed]. */
function pm_ins_weeks(string $brand, int $n = 8): array
{
    $weeks = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $weeks[pm_ins_week(strtotime("-$i weeks"))] = ['leads' => 0, 'sent' => 0, 'replies' => 0, 'proposals' => 0, 'signed' => 0];
    }
    $add = function (string $at, string $k) use (&$weeks): void {
        $t = strtotime($at);
        if ($t && isset($weeks[$w = pm_ins_week($t)])) {
            $weeks[$w][$k]++;
        }
    };
    foreach (pm_leads_read() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        $add((string)($l['created'] ?? ''), 'leads');
        foreach (array_merge((array)($l['sent'] ?? []), (array)($l['wa_sent'] ?? [])) as $at) {
            $add((string)$at, 'sent');
        }
        foreach ((array)($l['thread'] ?? []) as $m) {
            if (($m['dir'] ?? '') === 'in') {
                $add((string)($m['at'] ?? ''), 'replies');
                break; // the first answer only
            }
        }
    }
    $tpl = pm_template();
    foreach (pm_history() as $h) {
        if (pm_brand_of_type((string)($tpl['packages'][(int)($h['proposal']['package'] ?? 0)]['type'] ?? '')) !== $brand) {
            continue;
        }
        if (str_starts_with((string)($h['status'] ?? ''), 'Emailed') || !empty($h['signed_file'])) {
            $add((string)($h['when'] ?? ''), 'proposals');
        }
        if (!empty($h['signed_file'])) {
            $add((string)($h['signed_at'] ?? $h['when'] ?? ''), 'signed');
        }
    }
    return $weeks;
}

/** A share as a whole percent, or '–' when there is nothing to divide by. */
function pm_ins_pct(int $part, int $whole): string
{
    return $whole > 0 ? (string)round(100 * $part / $whole) . '%' : '–';
}

/** Signed value by currency, as text ("MWK 3,360,000 · USD 500"), '–' when nothing is signed. */
function pm_ins_money(array $byCur): string
{
    $out = [];
    foreach ($byCur as $cur => $v) {
        if ((float)$v > 0) {
            $out[] = $cur . ' ' . number_format((float)$v);
        }
    }
    return $out ? implode(' · ', $out) : '–';
}

/** The Sales section of Insights > Results: the funnel, the weeks, the sources and the kinds of business, with one plain line of advice. */
function pm_ins_sales_html(string $brand): string
{
    $ps = pm_pipeline_stats($brand);
    $leads = (int)$ps['leads'];
    if ($leads === 0) {
        return '<h3>Sales</h3><div class="card empty">The sales numbers appear once the first leads are in: <a href="?tab=agents">Sales &gt; Leads</a>.</div>';
    }
    $outreach = $leads - (int)$ps['inbound']['leads'];
    $stages = [['Leads found', $outreach], ['Written to', (int)$ps['contacted']], ['Answered', (int)$ps['replied']], ['Proposal sent', (int)$ps['proposals_sent']], ['Signed', (int)$ps['signed']]];
    $h = '<h3>Sales</h3><div class="kpis">'
        . '<div><b>' . $leads . '</b><span>leads · ' . (int)$ps['inbound']['leads'] . ' came to us (Facebook, website)</span></div>'
        . '<div><b>' . pm_ins_pct((int)$ps['replied'], (int)$ps['contacted']) . '</b><span>answered · ' . (int)$ps['replied'] . ' of ' . (int)$ps['contacted'] . ' written to</span></div>'
        . '<div><b>' . (int)$ps['proposals_sent'] . '</b><span>proposals sent · ' . (int)$ps['sent_7d'] . ' emails in the last 7 days</span></div>'
        . '<div><b>' . (int)$ps['signed'] . '</b><span>signed · ' . pm_h(pm_ins_money((array)$ps['signed_value'])) . ' (set-up + 12 months)</span></div></div>';
    // the funnel: each step against the one before
    $h .= '<div class="card"><h2>From first message to signed</h2><div class="rs-wrap"><table class="rs"><tr><th>Step</th><th class="n">Leads</th><th style="width:45%"></th><th class="n">Of the step before</th></tr>';
    $prev = null;
    foreach ($stages as [$lab, $n]) {
        $w = $stages[0][1] > 0 ? max(1, (int)round(100 * $n / $stages[0][1])) : 0;
        $h .= '<tr><td>' . pm_h($lab) . '</td><td class="n">' . $n . '</td><td><span class="sx-bar"><i style="width:' . ($n ? $w : 0) . '%"></i></span></td><td class="n">' . ($prev === null ? '' : pm_ins_pct($n, $prev)) . '</td></tr>';
        $prev = $n;
    }
    $h .= '</table></div><p class="rs-note">Leads who came to us (Facebook, the website) are counted in the Social section below, not in this funnel.</p></div>';
    // week by week
    $wk = pm_ins_weeks($brand, 8);
    $h .= '<div class="card"><h2>Week by week</h2><div class="rs-wrap"><table class="rs"><tr><th>Week of</th><th class="n">New leads</th><th class="n">Messages sent</th><th class="n">First answers</th><th class="n">Proposals</th><th class="n">Signed</th></tr>';
    foreach (array_reverse($wk, true) as $mon => $r) {
        $h .= '<tr' . ($mon === pm_ins_week(time()) ? ' class="thin"' : '') . '><td>' . pm_h(date('j M', strtotime($mon))) . ($mon === pm_ins_week(time()) ? ' (this week)' : '') . '</td><td class="n">' . $r['leads'] . '</td><td class="n">' . $r['sent'] . '</td><td class="n">' . $r['replies'] . '</td><td class="n">' . $r['proposals'] . '</td><td class="n">' . $r['signed'] . '</td></tr>';
    }
    $sum = fn(string $k) => array_sum(array_column($wk, $k));
    $h .= '<tr><td><b>8 weeks</b></td><td class="n"><b>' . $sum('leads') . '</b></td><td class="n"><b>' . $sum('sent') . '</b></td><td class="n"><b>' . $sum('replies') . '</b></td><td class="n"><b>' . $sum('proposals') . '</b></td><td class="n"><b>' . $sum('signed') . '</b></td></tr></table></div>'
        . '<p class="rs-note">Messages sent counts emails and WhatsApp messages; a first answer is the first time a lead wrote back.</p></div>';
    // where they came from, and which kinds of business answer
    $src = (array)$ps['by_src'];
    uasort($src, fn($a, $b) => [$b['won'], $b['replied'], $b['leads']] <=> [$a['won'], $a['replied'], $a['leads']]);
    $h .= '<div class="sxcols"><div class="card"><h2>Where the leads came from</h2><div class="rs-wrap"><table class="rs"><tr><th>Source</th><th class="n">Leads</th><th class="n">Answered</th><th class="n">Signed</th><th class="n">Value (MWK)</th></tr>';
    foreach (array_slice($src, 0, 8, true) as $k => $r) {
        $v = (int)($ps['won_value_by_src'][$k] ?? 0);
        $h .= '<tr><td>' . pm_h((string)$k) . '</td><td class="n">' . (int)$r['leads'] . '</td><td class="n">' . (int)$r['replied'] . ' <small class="muted">' . pm_ins_pct((int)$r['replied'], (int)$r['leads']) . '</small></td><td class="n">' . (int)$r['won'] . '</td><td class="n">' . ($v ? number_format($v) : '–') . '</td></tr>';
    }
    $h .= '</table></div></div><div class="card"><h2>Which kinds of business answer</h2><div class="rs-wrap"><table class="rs"><tr><th>Kind</th><th class="n">Written to</th><th class="n">Answered</th><th class="n">Rate</th><th class="n">Signed</th></tr>';
    $best = null;
    $worst = null;
    foreach ((array)$ps['segments'] as $k => $r) {
        $h .= '<tr><td>' . pm_h((string)$k) . '</td><td class="n">' . (int)$r['contacted'] . '</td><td class="n">' . (int)$r['replied'] . '</td><td class="n">' . pm_ins_pct((int)$r['replied'], (int)$r['contacted']) . '</td><td class="n">' . (int)$r['won'] . '</td></tr>';
        if ((int)$r['contacted'] >= 5) {
            $rate = (int)$r['replied'] / (int)$r['contacted'];
            if ($best === null || $rate > $best[1]) {
                $best = [$k, $rate];
            }
            if ($worst === null || $rate < $worst[1]) {
                $worst = [$k, $rate];
            }
        }
    }
    $h .= '</table></div>';
    if ($best && $worst && $best[0] !== $worst[0]) {
        $h .= '<p class="rs-note">So far <b>' . pm_h((string)$best[0]) . '</b> answer most (' . round(100 * $best[1]) . '%) and <b>' . pm_h((string)$worst[0]) . '</b> least (' . round(100 * $worst[1]) . '%). Lean the search and the follow-ups towards the first.</p>';
    } else {
        $h .= '<p class="rs-note">A kind of business is compared once at least 5 of them have been written to.</p>';
    }
    return $h . '</div></div><h3>Social</h3>';
}

/** Which agents used the AI for this business in the last 7 days, and what the answer memory saved. */
function pm_ins_ai_html(string $brand): string
{
    $d = pm_load('ai_usage_daily', fn() => []);
    $rows = [];
    for ($i = 0; $i < 7; $i++) {
        foreach ((array)($d[date('Y-m-d', strtotime("-$i days"))][$brand] ?? []) as $who => $r) {
            $rows[$who] ??= ['calls' => 0, 'tokens' => 0];
            $rows[$who]['calls'] += (int)($r['calls'] ?? 0);
            $rows[$who]['tokens'] += (int)($r['in'] ?? 0) + (int)($r['out'] ?? 0);
        }
    }
    uasort($rows, fn($a, $b) => $b['tokens'] <=> $a['tokens']);
    $total = array_sum(array_column($rows, 'tokens'));
    $saved = array_sum(array_map(fn($r) => (int)($r['tokens'] ?? 0), array_slice(pm_load('ai_cache_stats', fn() => []), -7, null, true)));
    $h = '<div class="card"><h2>AI use by agent, last 7 days</h2>';
    if (!$rows) {
        return $h . '<p class="hint">No AI use recorded for this business in the last 7 days.</p></div>';
    }
    $h .= '<div class="rs-wrap"><table class="rs"><tr><th>Agent</th><th class="n">Calls</th><th class="n">Tokens</th><th class="n">Share</th></tr>';
    foreach (array_slice($rows, 0, 12, true) as $who => $r) {
        $h .= '<tr><td>' . pm_h(ucfirst(str_replace('_', ' ', (string)$who))) . '</td><td class="n">' . $r['calls'] . '</td><td class="n">' . number_format($r['tokens']) . '</td><td class="n">' . pm_ins_pct($r['tokens'], $total) . '</td></tr>';
    }
    return $h . '<tr><td><b>All</b></td><td class="n"><b>' . array_sum(array_column($rows, 'calls')) . '</b></td><td class="n"><b>' . number_format($total) . '</b></td><td></td></tr></table></div>'
        . '<p class="rs-note">' . ($saved ? 'Repeat questions answered from memory saved about ' . number_format($saved) . ' tokens in 7 days (all businesses). ' : '') . 'The AI budget and model are under <a href="?tab=settings&amp;section=connections">Operations &gt; Connections</a>.</p></div>';
}
