<?php
/**
 * sx_learn — the learning & trust layer (MARKETING.md register, cycle 1).
 * Loaded from agents.php (and thus everywhere agents run); also auto-loaded by lib/social_modules.php.
 * Pure/rule-based where possible so every piece is testable without a network.
 */

/* ---------------- MG-A11 · provider failover ---------------- */

function pm_ai_alt(string $prov): string
{
    return $prov === 'gemini' ? 'anthropic' : 'gemini';
}

/** Retry helper: runs $primary; on RuntimeException, runs $alt only when $altKey is present. Testable. */
function pm_ai_call_with(callable $primary, callable $alt, string $altKey): string
{
    try {
        return (string)$primary();
    } catch (RuntimeException $e) {
        if ($altKey === '') {
            throw $e;
        }
        return (string)$alt();
    }
}

/** One agent call with cross-provider failover: if the configured provider fails and the other key exists, retry once. */
function pm_ai_call(bool $web, string $system, string $user, int $maxTokens, string $tier): string
{
    $prov = pm_provider($web);
    $key = pm_key($prov === 'gemini' ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY');
    $altProv = pm_ai_alt($prov);
    $altKey = pm_key($altProv === 'gemini' ? 'GEMINI_API_KEY' : 'ANTHROPIC_API_KEY');
    $call = fn(string $p) => $p === 'gemini'
        ? pm_gemini($system, $user, $web, $maxTokens, $tier)
        : pm_anthropic($system, $user, $web, $maxTokens, $tier);
    if ($key === '' && $altKey !== '') { // only the other key exists: use it directly
        return $call($altProv);
    }
    try {
        return pm_ai_call_with(fn() => $call($prov), fn() => $call($altProv), $altKey);
    } catch (RuntimeException $e) {
        try {
            pm_agent_log('AI', 'Provider ' . $prov . ' failed' . ($altKey !== '' ? ' and the other provider also failed' : ' (no second provider)') . ': ' . mb_substr($e->getMessage(), 0, 120), true);
        } catch (Throwable) {
        }
        throw $e;
    }
}

/* ---------------- MG-A01 · outcome-weighted scouting ---------------- */

/**
 * Per-key weights from real outcomes: sector, city, source and offering, from leads that replied, won or were lost.
 * 1.0 = average; winners rise above, cold duds fall to a 0.2 floor. Zero AI.
 */
function pm_outcome_weights(string $brand): array
{
    $w = ['sector' => [], 'city' => [], 'source' => [], 'offering' => []];
    foreach (pm_load('leads', fn() => []) as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || ($l['status'] ?? '') === 'optout') {
            continue;
        }
        $inc = match ((string)($l['status'] ?? '')) {
            'won' => 3.0,
            'proposal', 'replied' => 1.5,
            'lost' => -0.6,
            'drafted', 'contacted', 'qualified' => 0.5,
            default => 0.2, // new
        };
        if (!empty($l['sent']) || !empty($l['wa_sent'])) {
            $inc += 0.3; // actually touched: signals are real
        }
        $seg = function (string $k, string $v) use (&$w, $inc): void {
            $v = trim((string)$v);
            if ($v !== '') {
                $w[$k][$v] = ($w[$k][$v] ?? 0) + $inc;
            }
        };
        $seg('sector', (string)($l['type'] ?? ''));
        $seg('city', (string)($l['city'] ?? ''));
        $src = trim((string)($l['src']['kind'] ?? $l['source'] ?? ''));
        $seg('source', $src !== '' ? $src : 'other');
        $seg('offering', (string)($l['offering'] ?? ''));
    }
    foreach ($w as &$rows) {
        $mx = $rows ? max($rows) : 0;
        foreach ($rows as $k => $v) {
            $rows[$k] = round(max(0.2, $mx > 0 ? 0.4 + 0.6 * ($v / $mx) : 1.0), 2);
        }
    }
    unset($rows);
    return $w;
}

/** Cached weights (data/outcome_weights.json, refreshed at most once a day per brand). */
function pm_outcome_weights_fresh(string $brand, int $maxAgeDays = 7): array
{
    $d = pm_load('outcome_weights', fn() => []);
    $day = (string)($d[$brand]['day'] ?? '');
    if ($day === date('Y-m-d') || ($day !== '' && strtotime($day) > time() - $maxAgeDays * 86400)) {
        return (array)($d[$brand]['weights'] ?? []);
    }
    $w = pm_outcome_weights($brand);
    pm_update('outcome_weights', function (array $d) use ($brand, $w) {
        $d[$brand] = ['day' => date('Y-m-d'), 'weights' => $w];
        return $d;
    }, fn() => []);
    return $w;
}

/** Daily job: refresh both brands' weights. Cheap (one leads.json pass each). */
function pm_jobg_outcome_weights(): string
{
    $out = [];
    foreach (pm_brand_ids() as $b) {
        $w = pm_outcome_weights_fresh($b);
        $top = $w['sector'] ?? [];
        arsort($top);
        $out[] = $b . ': ' . count($w['sector'] ?? []) . ' sectors, top ' . implode(', ', array_slice(array_keys($top), 0, 3));
    }
    return implode('; ', $out);
}

/** $n items from $items, weighted by $weights (key => weight), rotating with $offset so every item gets a turn. */
function pm_weighted_pick(array $items, array $weights, int $n, int $offset = 0): array
{
    $items = array_values($items);
    if (!$items) {
        return [];
    }
    $expand = [];
    foreach ($items as $it) {
        $times = max(1, (int)round((float)($weights[$it] ?? 1.0) / 0.25));
        $expand = array_merge($expand, array_fill(0, $times, $it));
    }
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $expand[($offset + $i) % count($expand)];
    }
    return $out;
}

/* ---------------- MG-A05 · email subject experiments ---------------- */

function pm_email_exp_arm(string $leadId, string $brand): string
{
    return (crc32($brand . '|' . $leadId) % 2 === 0) ? 'A' : 'B';
}

/** Tallies sent/replied per arm and the latest subject per arm from rows {arm, subject, replied}. Returns [counts, examples]. */
function pm_exp_tally(array $rows): array
{
    $n = ['A' => [0, 0], 'B' => [0, 0]];
    $ex = ['A' => '', 'B' => ''];
    foreach ($rows as $r) {
        $arm = (string)($r['arm'] ?? '');
        if (!isset($n[$arm]) || trim((string)($r['subject'] ?? '')) === '') {
            continue;
        }
        $n[$arm][0]++;
        $n[$arm][1] += !empty($r['replied']) ? 1 : 0;
        $ex[$arm] = (string)$r['subject'];
    }
    return [$n, $ex];
}

/**
 * Judges a subject experiment from its tallies. status: few (not enough sends), none (no clear winner) or win. A winner needs both arms, $min sends in all
 * and a real edge: two more replies than the other arm and a different reply rate.
 */
function pm_exp_judge(array $n, array $ex, int $min = 20): array
{
    if ($n['A'][0] + $n['B'][0] < $min || $n['A'][0] === 0 || $n['B'][0] === 0) {
        return ['status' => 'few', 'figures' => ['A' => $n['A'], 'B' => $n['B']]];
    }
    $ra = $n['A'][1] / $n['A'][0];
    $rb = $n['B'][1] / $n['B'][0];
    $winner = $rb > $ra ? 'B' : 'A';
    $other = $winner === 'A' ? 'B' : 'A';
    if ($n[$winner][1] >= $n[$other][1] + 2 && $ra !== $rb) {
        return ['status' => 'win', 'winner' => $winner, 'example' => mb_substr($ex[$winner], 0, 90), 'figures' => ['A' => $n['A'], 'B' => $n['B']],
            'text' => 'arm ' . $winner . ' wins (' . round(100 * $ra) . '% vs ' . round(100 * $rb) . '% replies)'];
    }
    return ['status' => 'none', 'figures' => ['A' => $n['A'], 'B' => $n['B']]];
}

/** Weekly job: compare reply rates by subject arm for sent first emails AND follow-ups; keep each winning style example for the Writer and the Follow-up agent. */
function pm_jobg_email_exp(): string
{
    $leads = pm_load('leads', fn() => []);
    $rows = [];
    foreach ($leads as $l) {
        if (!empty($l['email_arm']) && !empty($l['sent']) && !empty($l['drafts']['email_subject'])) {
            $rows[] = ['arm' => (string)$l['email_arm'], 'subject' => (string)$l['drafts']['email_subject'], 'replied' => pm_lead_replied($l)];
        }
    }
    $store = pm_load('email_exp', fn() => []);
    [$n, $ex] = pm_exp_tally($rows);
    $v = pm_exp_judge($n, $ex, 20);
    $msg = 'not enough sent emails for a subject verdict';
    if ($v['status'] === 'win') {
        $store['winner'] = $v['winner'];
        $store['example'] = $v['example'];
        $store['at'] = date('Y-m-d');
        $store['figures'] = $v['figures'];
        $msg = 'subject experiment: ' . $v['text'];
    } elseif ($v['status'] === 'none') {
        $msg = 'subject experiment: no clear winner yet';
    }
    // follow-ups: a smaller pool, so a smaller bar (12 sends), judged the same way
    [$fn, $fex] = pm_exp_tally(function_exists('pm_followup_exp_rows') ? pm_followup_exp_rows($leads) : []);
    $fv = pm_exp_judge($fn, $fex, 12);
    if ($fv['status'] === 'win') {
        $store['followup'] = ['winner' => $fv['winner'], 'example' => $fv['example'], 'at' => date('Y-m-d'), 'figures' => $fv['figures']];
        $msg .= '; follow-ups: ' . $fv['text'];
    } elseif ($fv['status'] === 'none') {
        $msg .= '; follow-ups: no clear winner yet';
    } elseif ($fn['A'][0] + $fn['B'][0] > 0) {
        $msg .= '; follow-ups: ' . ($fn['A'][0] + $fn['B'][0]) . ' sent so far, not enough for a verdict';
    }
    pm_save('email_exp', $store);
    return $msg;
}

/** The winning subject style, as one line the Writer should mirror; '' when nothing learned yet. */
function pm_email_exp_example(string $brand): string
{
    $d = pm_load('email_exp', fn() => []);
    return empty($d['example']) ? '' : 'Subjects that get replies in ' . (pm_brand_name($brand)) . ' look like this: "' . $d['example'] . '". Mirror its shape and length for the next emails.';
}

/* ---------------- MG-A02 · source-diverse scouts + watcher ---------------- */

const PM_SCOUT_SOURCES = ['web', 'directory', 'social'];

function pm_scout_source(int $day): string
{
    return PM_SCOUT_SOURCES[$day % count(PM_SCOUT_SOURCES)];
}

/** WATCHER: one search-grounded call looking for FRESH signals — new sites, hiring, reopenings, moving online. */
function pm_agent_watch(string $brand, array $knownNames): array
{
    $travel = $brand === 'travel';
    $who = $travel
        ? 'independent stays in Malawi that went online, reopened or started taking direct bookings in the last few weeks'
        : (pm_brand_is_custom($brand)
            ? 'Malawi organisations that fit what we offer and show a fresh signal: a new website, a hiring advert, a reopening, an expansion or a move online'
            : 'Malawi businesses with a fresh signal: a new website, a hiring advert for admin or IT, a reopening, or a shop moving its operations online');
    $system = pm_agents_company_brief('short') . "\nYou are the Watcher. Web-search for $who. Report only NEW signals a normal sweep may have missed; skip anything already known. "
        . "Each result needs a factual observation with its source URL. " . PM_AGENT_RULES;
    $user = "Skip these (already known): " . implode('; ', array_slice(array_map(fn($n) => preg_replace('/ \(.*$/', '', $n), $knownNames), -80)) . "\n"
        . 'JSON array of {"name","type":"kind of business in 2-3 words","city","website","phone","email","facebook","social_gaps":["what is missing or weak online"],'
        . '"evidence":["one factual observation + source URL"],"need_signals":["why they may need us"],"offering":"' . ($travel || pm_brand_is_custom($brand) ? 'the offering that fits, or empty' : 'build, source or support') . '"} — at most 4 items.';
    $out = pm_agent_list(pm_agent_json(pm_claude($system, $user, true, 2000)));
    return array_values(array_filter($out, fn($x) => is_array($x) && !empty($x['name'])));
}

/* ---------------- MG-A03 · contact re-verification ---------------- */

/** Stale-but-live leads whose published contacts deserve a re-check: at most $max, oldest first. */
function pm_reverify_due(array $leads, int $max = 3): array
{
    $cut = time() - 60 * 86400;
    $due = array_values(array_filter($leads, fn($l) => in_array($l['status'] ?? '', ['drafted', 'qualified', 'contacted', 'replied'], true)
        && empty($l['contact_checked_at']) && (strtotime((string)($l['created'] ?? '')) ?: 0) < $cut));
    usort($due, fn($a, $b) => strtotime((string)($a['created'] ?? '')) <=> strtotime((string)($b['created'] ?? '')));
    return array_slice($due, 0, $max);
}

/** One pass over stale leads: re-find published contacts. Returns [{id, email, phone, contact, contact_title, contact_source}]. */
function pm_agent_reverify(array $leads): array
{
    $out = [];
    foreach (array_slice($leads, 0, 3) as $l) {
        try {
            $d = pm_agent_contact($l);
        } catch (Throwable) {
            $d = [];
        }
        $d = is_array($d) ? $d : [];
        $out[] = ['id' => (string)($l['id'] ?? ''), 'email' => (string)($d['email'] ?? ''), 'phone' => (string)($d['phone'] ?? ''),
            'contact' => (string)($d['contact'] ?? ''), 'contact_title' => (string)($d['contact_title'] ?? ''), 'contact_source' => (string)($d['contact_source'] ?? $d['source'] ?? '')];
    }
    return $out;
}

/* ---------------- MG-A07 · win-back ---------------- */

/** Lost leads worth one fresh try: lost 60+ days ago, no win-back in 90 days, still have a live channel. */
function pm_winback_due(array $leads, int $max = 2): array
{
    $due = [];
    foreach ($leads as $l) {
        if (($l['status'] ?? '') !== 'lost' || ($l['brand'] ?? 'promanaged') !== pm_brand()) {
            continue;
        }
        $lostAt = strtotime((string)($l['lost_at'] ?? $l['updated'] ?? '')) ?: 0;
        $wb = strtotime((string)($l['winback_at'] ?? ''));
        if ($lostAt && $lostAt < time() - 60 * 86400 && (!$wb || $wb < time() - 90 * 86400)
            && (!empty($l['email']) || !empty($l['phone'])) && pm_lead_email_dead($l) !== true) {
            $due[] = $l;
        }
    }
    usort($due, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    return array_slice($due, 0, $max);
}

/** WIN-BACK: a genuinely new angle after a 'no'. Nothing is sent from here — drafts only. */
function pm_agent_winback(array $leads): array
{
    $s = pm_settings();
    $system = pm_agents_company_brief('tiny') . "\nYou are the Win-back agent for {$s['company_name']}. It has been a while since they said no. Write one short email (max 45 words) and one WhatsApp (max 25 words) that reopen the door with ONE genuinely new, true angle from the facts given. No guilt, no pressure, no prices, no urgency. Plain text, no sign-off. " . PM_AGENT_RULES;
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'why_lost' => mb_substr((string)($l['lost_reason'] ?? 'no reason recorded'), 0, 120),
        'original_angle' => mb_substr((string)($l['pain'] ?? ''), 0, 80), 'offering' => (string)($l['offering'] ?? '')], $leads);
    return pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"email_subject\",\"email_body\",\"whatsapp\"}", false, 1800)));
}

/* ---------------- MG-A08 · post-sign asks ---------------- */

/** Signed in the last 14 days and not yet asked: testimonial, review and referral. */
function pm_postsign_due(array $leads, int $max = 3): array
{
    $due = array_values(array_filter($leads, fn($l) => ($l['status'] ?? '') === 'won' && empty($l['postsign']) && empty($l['postsign_at'])
        && (strtotime((string)($l['won_at'] ?? $l['updated'] ?? '')) ?: 0) > time() - 14 * 86400));
    return array_slice($due, 0, $max);
}

/** POST-SIGN: three short asks after a signed deal. Drafts only — the owner reviews and sends. */
function pm_agent_postsign(array $leads): array
{
    $s = pm_settings();
    $reviewAsk = pm_google_review_link(pm_brand()) !== '' ? 'a polite Google review request; the review link is added under your text by the system, so do not write any link yourself'
        : 'a polite Google review request that says the owner will send the link separately';
    $system = pm_agents_company_brief('tiny') . "\nYou are the Post-sign agent for {$s['company_name']}. The client just signed. Write three SHORT messages, each its own paragraph, in plain text: "
        . "(1) \"testimonial\": ask if we may quote a line about what the system improved for them; (2) \"review\": $reviewAsk; "
        . "(3) \"referral\": ask whether another business they know could use the same help. Warm and light, no pressure, no prices, no discounts, no guilt. " . PM_AGENT_RULES;
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'contact' => (string)($l['contact'] ?? '')], $leads);
    return pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE)
        . "\nJSON array of {\"id\",\"testimonial\",\"review\",\"referral\"}", false, 1600, 'write')));
}

/* ---------------- MG-A10 · auto-research queue ---------------- */

/** Top leads heading for a follow-up or a proposal, with no research yet. */
function pm_research_queue(array $leads, int $n = 2): array
{
    $cfg = pm_agents_config();
    $due = [];
    foreach ($leads as $l) {
        $st = (string)($l['status'] ?? '');
        if (!in_array($st, ['contacted', 'replied', 'qualified', 'drafted'], true) || !empty($l['research']) || ($l['score'] ?? 0) < 60) {
            continue;
        }
        $followupSoon = $st === 'contacted' && ($t = strtotime((string)($l['last_contacted'] ?? ''))) && time() - $t >= ((int)($cfg['followup_days'] ?? 7) - 2) * 86400;
        $needsProposal = $st === 'replied' && !empty($l['want_proposal']) && empty($l['proposal_ai']);
        if ($followupSoon || $needsProposal) {
            $due[] = $l;
        }
    }
    usort($due, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    return array_slice($due, 0, $n);
}

/* ---------------- MG-A09 · unified inbox sync ---------------- */

/** Messenger rows of one thread that belong to a lead: psid matches the lead's FB messenger id, else the name matches. */
function pm_lead_fb_threads(array $lead, array $threads): array
{
    $out = [];
    $name = strtolower((string)($lead['name'] ?? ''));
    $psid = (string)($lead['psid'] ?? '');
    foreach ($threads as $t) {
        $hit = ($psid !== '' && ($t['psid'] ?? '') === $psid)
            || ($name !== '' && $name !== 'someone' && strtolower((string)($t['who'] ?? '')) === $name);
        if (!$hit) {
            continue;
        }
        foreach ((array)($t['messages'] ?? []) as $m) {
            $out[] = ['dir' => !empty($m['ours']) ? 'out' : 'in', 'at' => (string)($m['at'] ?? ''),
                'text' => mb_substr((string)($m['text'] ?? ''), 0, 2000), 'ch' => 'fb'];
        }
    }
    return $out;
}

/** Sync job: append new Messenger messages into each lead's thread (idempotent by message hash). No AI, no sending. */
function pm_jobg_thread_sync(): string
{
    $n = 0;
    foreach (pm_brand_ids() as $b) {
        if (!function_exists('pm_fb_inbox') || !pm_social_cfg($b)['ready']) {
            continue;
        }
        try {
            $inbox = pm_fb_inbox($b);
        } catch (Throwable) {
            continue;
        }
        if (($inbox['error'] ?? '') !== '' || !$inbox['threads']) {
            continue;
        }
        pm_update('leads', function (array $leads) use ($b, $inbox, &$n) {
            foreach ($leads as $id => &$l) {
                if (($l['brand'] ?? 'promanaged') !== $b) {
                    continue;
                }
                $seen = (array)($l['fb_seen'] ?? []);
                foreach (pm_lead_fb_threads($l, $inbox['threads']) as $r) {
                    $h = md5(($r['dir'] ?? '') . '|' . ($r['at'] ?? '') . '|' . ($r['text'] ?? ''));
                    if (isset($seen[$h])) {
                        continue;
                    }
                    $l['thread'][] = $r;
                    $seen[$h] = time();
                    $n++;
                }
                if ($seen) {
                    $l['fb_seen'] = array_slice($seen, -60, null, true);
                }
            }
            unset($l);
            return $leads;
        });
    }
    return $n ? "$n Messenger message(s) joined to lead threads" : 'no new Messenger messages';
}

/* ---------------- MG-G01 · deliverability self-check ---------------- */

/** Verdicts from a domain's TXT records: SPF, DMARC, DKIM. Pure — feed it dns_get_record output. */
function pm_deliv_verdict(array $txt): array
{
    $all = implode("\n", $txt);
    $has = fn(string $needle) => stripos($all, $needle) !== false;
    $spf = $has('v=spf1') ? ($has('~all') || $has('-all') ? 'pass' : 'fail') : 'missing';
    $dmarc = $has('v=dmarc1') ? ($has('p=reject') || $has('p=quarantine') || $has('p=none') ? 'pass' : 'fail') : 'missing';
    $dkim = $has('v=dkim1') || $has('p=') ? 'pass' : 'missing';
    return ['spf' => $spf, 'dmarc' => $dmarc, 'dkim' => $dkim, 'ok' => $spf === 'pass' && $dmarc === 'pass' && $dkim === 'pass'];
}

/** Weekly job: check our own sending domains and store the result for the Email health panel. */
function pm_jobg_deliverability(): string
{
    $domains = [];
    foreach (['SMTP_FROM', 'TM_SMTP_FROM'] as $fk) {
        $from = trim((string)(pm_env()[$fk] ?? ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $domains[] = substr(strrchr($from, '@'), 1);
        }
    }
    $domains = array_values(array_unique($domains));
    if (!$domains) {
        return 'no sending domain configured yet';
    }
    $rows = [];
    foreach ($domains as $d) {
        $txt = [];
        foreach ((array)(@dns_get_record($d, DNS_TXT) ?: []) as $r) {
            if (is_array($r) && isset($r['txt'])) {
                $txt[] = (string)$r['txt'];
            }
        }
        foreach ((array)(@dns_get_record('_dmarc.' . $d, DNS_TXT) ?: []) as $r) {
            if (is_array($r) && isset($r['txt'])) {
                $txt[] = (string)$r['txt'];
            }
        }
        foreach (['default', 'google', 'smtp', 'mail', 'protonmail'] as $sel) {
            foreach ((array)(@dns_get_record($sel . '._domainkey.' . $d, DNS_TXT) ?: []) as $r) {
                if (is_array($r) && isset($r['txt'])) {
                    $txt[] = (string)$r['txt'];
                }
            }
        }
        $rows[$d] = pm_deliv_verdict($txt) + ['day' => date('Y-m-d')];
    }
    pm_update('deliverability', fn() => $rows, fn() => []);
    $msg = [];
    foreach ($rows as $d => $v) {
        $msg[] = "$d: SPF {$v['spf']}, DKIM {$v['dkim']}, DMARC {$v['dmarc']}";
    }
    return implode('; ', $msg);
}

/** The stored self-check, or [] when never run. */
function pm_deliverability(): array
{
    return pm_load('deliverability', fn() => []);
}

/* ---------------- MG-G02 · adaptive send caps ---------------- */

/** Trailing 14-day bounce picture: [sends, bounces, rate]. */
function pm_bounce_rate7(array $leads, string $brand): array
{
    $sends = 0;
    $bounces = 0;
    $cut = time() - 14 * 86400;
    foreach ($leads as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        $bounced = !empty($l['bounced_at']) && strtotime((string)$l['bounced_at']) > $cut;
        foreach ((array)($l['sent'] ?? []) as $at) {
            if (strtotime((string)$at) > $cut) {
                $sends++;
            }
        }
        if ($bounced && !empty($l['sent'])) {
            $bounces++;
        }
    }
    return ['sends' => $sends, 'bounces' => $bounces, 'rate' => $sends > 0 ? $bounces / $sends : 0.0];
}

/**
 * The warm-up cap, nudged by the trailing bounce rate: healthy weeks drift +1/day (never above the base);
 * a bouncy week (3%+) drops -2/day (never below 1). The breaker still trips at 5%.
 */
function pm_adaptive_send_cap(int $base, string $brand): int
{
    $st = pm_ob_read('send_caps');
    $d = (int)($st[$brand]['day'] ?? 0);
    $v = (int)($st[$brand]['value'] ?? 0);
    if ($d !== (int)date('Ymd')) {
        $v = $base;
        $d = (int)date('Ymd');
    }
    $r = pm_bounce_rate7(pm_load('leads', fn() => []), $brand);
    if ($r['sends'] >= 5 && $r['rate'] >= 0.03) {
        $v = max(1, $v - 2);
    } elseif ($r['sends'] >= 5 && $r['rate'] <= 0.005) {
        $v = min($base, $v + 1);
    }
    pm_ob_update('send_caps', function ($s) use ($brand, $d, $v) {
        $s[$brand] = ['day' => $d, 'value' => $v];
        return $s;
    });
    return max(1, $v);
}

/* ---------------- MG-G03 · privacy: forget & export ---------------- */

/** Forget one lead everywhere: removes it and anonymises every History row and record that names it. Returns what was done. */
function pm_lead_forget(string $id): array
{
    $done = [];
    $leads = pm_leads();
    $l = $leads[$id] ?? null;
    $archived = function_exists('pm_leads_archive') ? (pm_leads_archive()[$id] ?? null) : null; // an archived lead can be forgotten too
    if (!$l && !$archived) {
        return ['ok' => false, 'msg' => 'No such lead.', 'done' => []];
    }
    $name = (string)(($l ?? $archived)['name'] ?? '');
    if ($l) {
        unset($leads[$id]);
        pm_leads_save($leads);
        $done[] = 'lead removed';
    }
    if ($archived) {
        pm_update('leads_archive', function (array $a) use ($id) {
            unset($a[$id]);
            return $a;
        }, fn() => []);
        $done[] = 'archived copy removed';
    }
    $norm = fn($n) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$n));
    pm_update('history', function (array $h) use ($norm, $name, &$done) {
        foreach ($h as &$row) {
            if (($row['lead_id'] ?? '') === '' && $norm($row['business'] ?? '') === $norm($name)) {
                $row['business'] = 'Former client (forgotten)';
                $done[] = 'history anonymised';
            }
        }
        unset($row);
        return $h;
    }, fn() => []);
    pm_update('inbox_unmatched', function (array $u) use ($norm, $name, &$done) {
        foreach ($u as $k => $r) {
            if ($norm($r['from'] ?? '') === $norm($name)) {
                unset($u[$k]);
                $done[] = 'unmatched inbox row removed';
            }
        }
        return $u;
    }, fn() => []);
    return ['ok' => true, 'msg' => 'Lead forgotten: ' . implode(', ', $done) . '.', 'done' => $done];
}

/** JSON export of one brand's leads (no mail server details, no secrets). */
function pm_data_export(string $brand = ''): string
{
    $leads = pm_load('leads', fn() => []);
    if ($brand !== '') {
        $leads = array_filter($leads, fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    }
    $settings = pm_settings();
    unset($settings['smtp']);
    if (isset($settings['travel']['smtp'])) {
        unset($settings['travel']['smtp']);
    }
    return json_encode(['exported' => date('c'), 'brand' => $brand ?: 'all', 'leads' => array_values($leads), 'settings' => $settings],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ---------------- MG-S09 · cross-brand shared learnings ---------------- */

/** Learnings both brands' planners read. Capped at 10, newest first. */
function pm_shared_learnings(): array
{
    return array_slice((array)(pm_load('social_learnings_shared', fn() => [])['rows'] ?? []), 0, 10);
}

/** Promote a learning from one brand's file to the shared store (deduped). */
function pm_shared_learnings_promote(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return false;
    }
    $ok = false;
    pm_update('social_learnings_shared', function (array $d) use ($text, &$ok) {
        $rows = (array)($d['rows'] ?? []);
        foreach ($rows as $r) {
            if ((string)$r === $text) {
                return $d;
            }
        }
        $ok = true;
        $rows[] = $text;
        $d['rows'] = array_slice($rows, -10);
        return $d;
    }, fn() => []);
    return $ok;
}

/** Daily job: when an experiment finished with a clear learning for one brand, share it with the other. */
function pm_jobg_learnings_share(): string
{
    $n = 0;
    foreach (pm_brand_ids() as $b) {
        foreach (pm_experiments($b) as $e) {
            if (($e['status'] ?? '') !== 'done' || ($e['verdict'] ?? '') !== 'keep' || empty($e['learning'])) {
                continue;
            }
            if (pm_shared_learnings_promote('[' . $b . '] ' . (string)$e['learning'])) {
                $n++;
            }
        }
    }
    return $n ? "$n learning(s) shared across brands" : 'nothing new to share';
}

/** One prompt line telling the Qualifier which business types and cities have actually replied or signed. */
function pm_learn_qualifier_line(string $brand): string
{
    $w = pm_outcome_weights_fresh($brand);
    $line = '';
    foreach (['sector' => 'business types', 'city' => 'cities', 'offering' => 'offerings'] as $k => $label) {
        $rows = (array)($w[$k] ?? []);
        if (!$rows) {
            continue;
        }
        arsort($rows);
        $top = array_slice(array_keys($rows), 0, 3);
        $line .= ' Past results: the best ' . $label . ' so far are ' . implode(', ', $top) . ' — weigh them slightly up when the evidence is equal.';
        break; // one line is enough for scoring; the rest is for scouting
    }
    return $line;
}




