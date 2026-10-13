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

/**
 * $n items from $items, weighted by $weights (key => weight), rotating with $offset so every item gets a turn. The items are interleaved in proportion to their
 * weights (smooth weighted round robin over a cycle of about 20 slots), so a heavy type gets most searches without taking every search for weeks, and a type
 * with no weight given still gets its share. A weight of 0 leaves a type out, unless every weight is 0.
 */
function pm_weighted_pick(array $items, array $weights, int $n, int $offset = 0): array
{
    $items = array_values($items);
    if (!$items || $n <= 0) {
        return [];
    }
    $w = [];
    foreach ($items as $it) {
        $w[$it] = max(0.0, (float)($weights[$it] ?? 1.0));
    }
    if (array_sum($w) <= 0) {
        $w = array_fill_keys($items, 1.0);
    }
    $total = array_sum($w);
    $slots = [];
    foreach ($w as $it => $x) { // whole slots out of 20, at least one for any item with weight
        $slots[$it] = $x > 0 ? max(1, (int)round(20 * $x / $total)) : 0;
    }
    $cycle = [];
    $cur = array_fill_keys(array_keys($slots), 0);
    $sum = array_sum($slots);
    for ($k = 0; $k < $sum; $k++) {
        foreach ($slots as $it => $sl) {
            $cur[$it] += $sl;
        }
        arsort($cur);
        $best = array_key_first($cur);
        $cycle[] = (string)$best;
        $cur[$best] -= $sum;
    }
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = $cycle[($offset + $i) % count($cycle)];
    }
    return $out;
}

/* ---------------- MG-A05 · email subject experiments ---------------- */

/**
 * The subject experiment of one business (data/email_exp.json is {by: {brand: {...}}}). The file from before this split was one shared record:
 * it is read as ProManaged IT's, which sent nearly all of those emails, and every other business starts its own test from nothing.
 */
function pm_email_exp_store(string $brand): array
{
    $d = pm_load('email_exp', fn() => []);
    if (!isset($d['by'])) {
        return $brand === 'promanaged' ? $d : [];
    }
    return (array)($d['by'][$brand] ?? []);
}

function pm_email_exp_store_save(string $brand, array $store): void
{
    pm_update('email_exp', function (array $d) use ($brand, $store) {
        if (!isset($d['by'])) {
            $d = ['by' => $d ? ['promanaged' => $d] : []];
        }
        $d['by'][$brand] = $store;
        return $d;
    }, fn() => []);
}

function pm_email_exp_arm(string $leadId, string $brand, bool $useStop = true): string
{
    $stop = $useStop ? (array)(pm_email_exp_store($brand)['stopped'] ?? []) : []; // C3-A05: a style that lost clearly is not used until the pause ends
    if (in_array($stop['winner'] ?? '', ['A', 'B'], true) && strcmp((string)($stop['until'] ?? ''), date('Y-m-d')) >= 0) {
        return (string)$stop['winner'];
    }
    return (crc32($brand . '|' . $leadId) % 2 === 0) ? 'A' : 'B';
}

/** Two-sided Fisher exact p for [[a, b], [c, d]]: how likely a split at least this uneven is by luck alone. Right for small counts, where a z-test fools you. */
function pm_fisher_p(int $a, int $b, int $c, int $d): float
{
    $lf = function (int $n): float {
        $s = 0.0;
        for ($i = 2; $i <= $n; $i++) {
            $s += log($i);
        }
        return $s;
    };
    $r1 = $a + $b;
    $r2 = $c + $d;
    $c1 = $a + $c;
    $n = $r1 + $r2;
    $prob = fn(int $x) => exp($lf($r1) + $lf($r2) + $lf($c1) + $lf($n - $c1) - $lf($n) - $lf($x) - $lf($r1 - $x) - $lf($c1 - $x) - $lf($r2 - $c1 + $x));
    $obs = $prob($a);
    $p = 0.0;
    for ($x = max(0, $c1 - $r2); $x <= min($r1, $c1); $x++) {
        $px = $prob($x);
        $p += $px <= $obs * (1 + 1e-9) ? $px : 0.0;
    }
    return min(1.0, $p);
}

/**
 * C3-A05 · stop a clearly losing subject style early, with the same strictness as the social experiments: each style needs at least $minPerArm sends,
 * the better one needs 3 replies of its own, the worse one's reply rate must be at most half of it, and the gap must survive Fisher's exact test (p at most 0.05).
 * Anything less keeps waiting, because a handful of emails can be luck. Returns the verdict (status win, early true) or null.
 */
function pm_exp_early(array $n, array $ex, int $minPerArm = 6): ?array
{
    if ($n['A'][0] < $minPerArm || $n['B'][0] < $minPerArm) {
        return null;
    }
    $ra = $n['A'][1] / $n['A'][0];
    $rb = $n['B'][1] / $n['B'][0];
    $w = $rb > $ra ? 'B' : 'A';
    $l = $w === 'A' ? 'B' : 'A';
    $rw = max($ra, $rb);
    $rl = min($ra, $rb);
    if ($n[$w][1] < 3 || $rl > 0.5 * $rw || pm_fisher_p($n['A'][1], $n['A'][0] - $n['A'][1], $n['B'][1], $n['B'][0] - $n['B'][1]) > 0.05) {
        return null;
    }
    return ['status' => 'win', 'winner' => $w, 'loser' => $l, 'early' => true, 'example' => mb_substr($ex[$w], 0, 90), 'figures' => ['A' => $n['A'], 'B' => $n['B']],
        'text' => 'arm ' . $l . ' stopped early: ' . round(100 * $rl) . '% vs ' . round(100 * $rw) . '% replies (' . $n[$l][0] . ' and ' . $n[$w][0] . ' sends)'];
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
function pm_exp_judge(array $n, array $ex, int $min = 20, bool $allowEarly = false): array
{
    if ($n['A'][0] + $n['B'][0] < $min || $n['A'][0] === 0 || $n['B'][0] === 0) {
        if ($allowEarly && ($early = pm_exp_early($n, $ex)) !== null) { // C3-A05: a clear loser does not have to wait for the full sample
            return $early;
        }
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

/** Weekly job: compare reply rates by subject arm for sent first emails AND follow-ups, per business; keep each winning style example for its Writer and Follow-up agent. */
function pm_jobg_email_exp(): string
{
    $all = pm_load('leads', fn() => []);
    $out = [];
    foreach (pm_brand_ids() as $b) {
        $mine = array_filter($all, fn($l) => ($l['brand'] ?? 'promanaged') === $b);
        if ($mine) {
            $out[] = $b . ': ' . pm_email_exp_judge_brand($b, $mine);
        }
    }
    return implode('; ', $out);
}

/** One business's subject experiment, judged from its own leads. */
function pm_email_exp_judge_brand(string $brand, array $leads): string
{
    $rows = [];
    foreach ($leads as $l) {
        if (!empty($l['email_arm']) && !empty($l['sent']) && !empty($l['drafts']['email_subject'])) {
            $rows[] = ['arm' => (string)$l['email_arm'], 'subject' => (string)$l['drafts']['email_subject'], 'replied' => pm_lead_replied($l)];
        }
    }
    $store = pm_email_exp_store($brand);
    [$n, $ex] = pm_exp_tally($rows);
    $v = pm_exp_judge($n, $ex, 20, true);
    $msg = 'not enough sent emails for a subject verdict';
    if ($v['status'] === 'win') {
        $store['winner'] = $v['winner'];
        $store['example'] = $v['example'];
        $store['at'] = date('Y-m-d');
        $store['figures'] = $v['figures'];
        $msg = 'subject experiment: ' . $v['text'];
        if (!empty($v['early'])) { // the losing style is not handed out for 30 days; then the experiment starts again 50/50
            $store['stopped'] = ['winner' => $v['winner'], 'loser' => $v['loser'], 'at' => date('Y-m-d'), 'until' => date('Y-m-d', strtotime('+30 days'))];
        }
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
    pm_email_exp_store_save($brand, $store);
    return $msg;
}

/** The winning subject style, as one line the Writer should mirror; '' when nothing learned yet. */
function pm_email_exp_example(string $brand): string
{
    $d = pm_email_exp_store($brand);
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
        if (empty($l['sent']) && empty($l['wa_sent']) && empty($l['last_reply'])) {
            continue; // never contacted (the Qualifier set it aside): there is no "no" to win back
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
    $system = pm_agents_company_brief('tiny') . "\nYou are the Win-back agent for {$s['company_name']}. It has been a while since they said no. Write one short email (max 45 words) and one WhatsApp (max 25 words) that reopen the door with ONE genuinely new, true angle from the facts given. No guilt, no pressure, no prices, no urgency. Plain text, no sign-off. The email subject is written like a normal subject line: a capital letter first and a capital letter on every town, area, road, person and business name (for example 'Rooms near the Shire River barrage?'), every other word in ordinary lowercase, no full stop, no exclamation marks, no word in ALL CAPS except an acronym such as VAT. " . PM_HUMAN_RULES . ' ' . PM_AGENT_RULES;
    $slim = array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'why_lost' => mb_substr((string)($l['lost_reason'] ?? 'no reason recorded'), 0, 120),
        'original_angle' => mb_substr((string)($l['pain'] ?? ''), 0, 80), 'offering' => (string)($l['offering'] ?? '')], $leads);
    return pm_subjects_polish(pm_human_rows(pm_agent_list(pm_agent_json(pm_claude($system, json_encode($slim, JSON_UNESCAPED_UNICODE) . "\nJSON array of {\"id\",\"email_subject\",\"email_body\",\"whatsapp\"}", false, 1800))), ['email_body' => 'email', 'whatsapp' => 'whatsapp']), $leads);
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
    $did = pm_brand_is_custom(pm_brand()) ? 'what working with us improved for them' : 'what the system improved for them';
    $system = pm_agents_company_brief('tiny') . "\nYou are the Post-sign agent for {$s['company_name']}. The client just signed. Write three SHORT messages, each its own paragraph, in plain text: "
        . "(1) \"testimonial\": ask if we may quote a line about $did; (2) \"review\": $reviewAsk; "
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
 * The warm-up cap, nudged by the trailing bounce rate, once a day: healthy weeks drift +1/day (never above the base); a bouncy week (3%+) drops -2/day (never
 * below 1). The day's value is worked out on the first call of the day and then only read, and it never exceeds today's base, so lowering the limit in Targeting
 * takes effect at once and a limit of 0 sends nothing. The breaker still trips at 5%.
 */
function pm_adaptive_send_cap(int $base, string $brand): int
{
    if ($base <= 0) {
        return 0;
    }
    $today = (int)date('Ymd');
    $row = (array)(pm_ob_read('send_caps')[$brand] ?? []);
    if ((int)($row['day'] ?? 0) === $today) {
        return max(1, min($base, (int)($row['value'] ?? $base)));
    }
    $r = pm_bounce_rate7(pm_load('leads', fn() => []), $brand);
    $v = min($base, max(1, (int)($row['value'] ?? $base)));
    if ($r['sends'] < 5) {
        $v = $base; // too few sends to judge: the warm-up base
    } elseif ($r['rate'] >= 0.03) {
        $v = max(1, $v - 2);
    } elseif ($r['rate'] <= 0.005) {
        $v = min($base, $v + 1);
    }
    pm_ob_update('send_caps', function ($s) use ($brand, $today, $v) {
        $s[$brand] = ['day' => $today, 'value' => $v];
        return $s;
    });
    return max(1, min($base, $v));
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
    pm_suppress_remember($l ?? $archived); // a STOP outlives the lead, as a fingerprint only (nothing readable is kept)
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

/**
 * One prompt line telling the Qualifier which kinds of business and towns have actually answered or bought, and which rarely do (the advisor's verdicts,
 * which need enough leads and lean on our other businesses while there are few). Before the advisor has a verdict, the best kinds and towns by outcome weight.
 */
function pm_learn_qualifier_line(string $brand): string
{
    if (function_exists('pm_adv_qualifier_line') && ($l = pm_adv_qualifier_line($brand)) !== '') {
        return $l;
    }
    $w = pm_outcome_weights_fresh($brand);
    $parts = [];
    foreach (['sector' => 'business types', 'city' => 'cities'] as $k => $label) {
        $rows = (array)($w[$k] ?? []);
        if (count($rows) < 2) {
            continue;
        }
        arsort($rows);
        $parts[] = 'the best ' . $label . ' so far are ' . implode(', ', array_slice(array_keys($rows), 0, 3));
    }
    return $parts ? ' Past results: ' . implode('; ', $parts) . ' — weigh them slightly up when the evidence is equal.' : '';
}





/* ---------------- C3-A09 · directory scout: public member lists, once a week ---------------- */

/** Is this business's weekly directory pass due? (Once every 7 days; the state file keeps the last day per business.) */
function pm_directory_due(string $brand): bool
{
    $d = (string)(pm_load('directory_state', fn() => [])[$brand] ?? '');
    return $d === '' || strtotime($d) <= strtotime(date('Y-m-d') . ' -7 days');
}

function pm_directory_mark(string $brand): void
{
    pm_update('directory_state', function (array $s) use ($brand) {
        $s[$brand] = date('Y-m-d');
        return $s;
    }, fn() => []);
}

/**
 * DIRECTORY SCOUT: one search-grounded call that reads PUBLIC business directories, chambers of commerce and association member lists for one town.
 * It reports only members it actually saw on such a page, with the address (https) of that page; names and contacts the page itself publishes, nothing else.
 */
function pm_agent_directory(string $brand, string $city, array $sectors, array $knownNames): array
{
    $system = pm_agents_company_brief('short') . "\nYou are the Directory Scout. Web-search PUBLIC business directories, chamber of commerce pages and association member lists for organisations in {$city}, Malawi, that fit "
        . 'what we offer (' . implode(', ', array_slice($sectors, 0, 6)) . '). Report only organisations you actually saw listed on such a page, with the exact https address of the page that lists them, and only the contact details that page publishes. '
        . 'Never guess an address, a phone number or a person, and never use a private person\'s personal contact. Skip chains and anything outside Malawi. ' . PM_AGENT_RULES;
    $user = 'Skip these (already known): ' . implode('; ', array_slice(array_map(fn($n) => preg_replace('/ \(.*$/', '', $n), $knownNames), -80)) . "\n"
        . 'JSON array of {"name","type":"kind of organisation in 2-3 words","city","address","website","phone","email","source":"https address of the directory or member-list page that lists it","evidence":["what the listing says about it"]} - at most 6 items.';
    return pm_agent_list(pm_agent_json(pm_claude($system, $user, true, 2200)));
}

/** Keeps only usable directory rows: a name, and the https page that listed it. Contact fields that are not valid are blanked, never repaired or invented. Max 6. */
function pm_directory_clean(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $name = trim((string)($r['name'] ?? ''));
        $src = trim((string)($r['source'] ?? ''));
        if ($name === '' || !preg_match('#^https://[^\s<>"\']{6,250}$#i', $src)) {
            continue; // no name, or no listing page we can point to: not usable
        }
        $email = strtolower(trim((string)($r['email'] ?? '')));
        $out[] = ['name' => mb_substr($name, 0, 100), 'type' => mb_substr(trim((string)($r['type'] ?? '')), 0, 60), 'city' => mb_substr(trim((string)($r['city'] ?? '')), 0, 60),
            'address' => mb_substr(trim((string)($r['address'] ?? '')), 0, 160), 'website' => (string)(pm_clean_url((string)($r['website'] ?? ''))), 'phone' => mb_substr(trim((string)($r['phone'] ?? '')), 0, 40),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'source' => $src,
            'evidence' => array_slice(array_values(array_filter(array_map(fn($e) => mb_substr(trim((string)$e), 0, 200), (array)($r['evidence'] ?? [])))), 0, 3)];
    }
    return array_slice($out, 0, 6);
}

/**
 * Turns cleaned directory rows into new leads: not already known (same name or website, phone, email, archived ones included), not an existing client,
 * and no more than $room. Every lead says where it was listed. Returns [id => lead].
 */
function pm_directory_leads(array $rows, array $pool, string $brand, array $cfg, string $fallbackCity, int $room): array
{
    $new = [];
    foreach ($rows as $b) {
        if (count($new) >= $room) {
            break;
        }
        $c = $b['city'] !== '' ? $b['city'] : $fallbackCity;
        $cand = ['name' => $b['name'], 'city' => $c, 'brand' => $brand, 'type' => $b['type'], 'website' => $b['website'], 'phone' => $b['phone'], 'email' => $b['email']];
        $id = pm_lead_id($b['name'], $c, $brand);
        if (isset($pool[$id]) || isset($new[$id]) || (function_exists('pm_lead_find_dupe') && pm_lead_find_dupe($pool + $new, $cand, $brand) !== null)) {
            continue;
        }
        if ($brand !== 'travel' && array_filter((array)($cfg['existing_clients'] ?? []), fn($x) => $x !== '' && stripos($b['name'], (string)$x) !== false)) {
            continue;
        }
        $new[$id] = [
            'id' => $id, 'brand' => $brand, 'name' => $b['name'], 'type' => $b['type'], 'city' => $c, 'address' => $b['address'], 'website' => $b['website'], 'phone' => $b['phone'],
            'email' => $b['email'], 'whatsapp' => '', 'contact' => '', 'contact_title' => '', 'evidence' => array_merge(['Listed on a public directory: ' . $b['source']], $b['evidence']),
            'facebook' => '', 'instagram' => '', 'social_gaps' => [], 'need_signals' => [], 'offering' => '', 'score' => 0, 'status' => 'new', 'notes' => [], 'drafts' => [], 'sent' => [],
            'followups' => 0, 'created' => date('Y-m-d H:i'), 'updated' => date('Y-m-d H:i'), 'src' => ['kind' => 'directory', 'url' => $b['source'], 'run_date' => date('Y-m-d')],
        ];
    }
    return $new;
}
