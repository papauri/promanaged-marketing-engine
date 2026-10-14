<?php
/**
 * The approach plan for a lead: what we still miss before writing to them, the one next step, and how a good marketer would approach this business
 * (which channel first, when in the day, on which angle, and the follow-up rhythm). Rules only: no AI, no network, so every card shows it instantly.
 *   pm_approach($lead)          ['checks' => [...], 'ready' => 0..100, 'missing' => [labels], 'next' => [label, do, why] | null, 'plan' => [...]]
 *   pm_approach_html($lead, $post, $hid)   the "Approach" block at the top of the lead card
 */

/** When a kind of business is most likely to read and answer, Malawi working habits: [window, why]. */
function pm_approach_time(array $l): array
{
    $t = strtolower((string)($l['type'] ?? '') . ' ' . (string)($l['name'] ?? ''));
    return match (true) {
        (bool)preg_match('/restaurant|bar\b|cafe|café|grill|takeaway|bakery|pub|club/', $t) => ['14:30 to 16:30', 'between the lunch and dinner rush'],
        (bool)preg_match('/hotel|lodge|resort|guest|camp|cottage|b&b|hostel|backpack|inn\b|motel|suites|chalet|stay/', $t) => ['09:30 to 11:30', 'after check-out, before lunch service'],
        (bool)preg_match('/school|college|academy|universit|institute|nursery/', $t) => ['13:30 to 15:30', 'after morning classes'],
        (bool)preg_match('/clinic|hospital|pharmac|dental|medical|health|optic|lab/', $t) => ['12:30 to 14:00', 'the quieter middle of the day'],
        (bool)preg_match('/shop|store|supermarket|hardware|boutique|retail|wholesale|market|fashion|salon|spa|beauty|gym/', $t) => ['08:00 to 09:30', 'before the shop gets busy'],
        (bool)preg_match('/farm|agri|estate|factory|manufactur|mill|industr|transport|logistic/', $t) => ['07:30 to 09:00', 'early, before the day\'s work starts'],
        default => ['09:00 to 11:00', 'mid-morning, when owners clear their messages'],
    };
}

/** The checklist, the readiness score, the next step and the approach plan for one lead. */
function pm_approach(array $l): array
{
    $st = (string)($l['status'] ?? 'new');
    $r = (array)($l['research'] ?? []);
    $fresh = !empty($r['at']) && strtotime((string)$r['at']) > strtotime('-30 days');
    $mail = (bool)filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) && empty($l['email_bad']);
    $waOn = pm_wa_on((string)($l['brand'] ?? pm_brand())); // no WhatsApp set up: the approach is by email (and a call)
    $wa = $waOn && pm_wa_best($l)[0] !== '';
    $phone = trim((string)($l['phone'] ?? '') . (string)($l['whatsapp'] ?? '')) !== '';
    $named = trim((string)($l['contact'] ?? '')) !== '';
    $title = trim((string)($l['contact_title'] ?? '')) !== '';
    $online = preg_match('#^https?://#i', (string)($l['website'] ?? '')) || preg_match('#facebook\.com/#i', (string)($l['facebook'] ?? '')) || preg_match('#instagram\.com/#i', (string)($l['instagram'] ?? ''));
    $hooks = array_values(array_filter(array_merge([pm_lead_personal($l)], (array)($r['hooks'] ?? []), [(string)($l['pain'] ?? '')], (array)($l['social_gaps'] ?? []))));
    $first = in_array($st, ['new', 'qualified', 'drafted'], true);
    $draft = $st === 'contacted' ? !empty($l['followup_draft']['email_body']) || !empty($l['followup_draft']['whatsapp']) : !empty($l['drafts']['email_body']) || !empty($l['drafts']['whatsapp']);
    // weight = how much it matters to a good first approach
    $checks = [
        ['name', 'The decision maker\'s name', $named, 12, $named ? (string)$l['contact'] . ($title ? ' · ' . $l['contact_title'] : '') : (!empty($l['contact_hint']) ? 'Possible: ' . $l['contact_hint'] : 'A message to a person gets answered; one to "Hello" gets ignored'), $named ? null : ['find_contact', 'Find the name with AI']],
        ['whatsapp', 'A WhatsApp number', $wa, 14, $wa ? 'Can be reached directly' : ($phone ? 'Only a landline: call them instead' : 'Most owners in Malawi answer WhatsApp first'), $wa ? null : ['find_contact', 'Find their WhatsApp with AI']],
        ['email', 'An email address that works', $mail, 10, $mail ? (string)$l['email'] : (!empty($l['email_bad']) ? 'The last address bounced' : 'Needed for the proposal and the formal follow-up'), $mail ? null : ['find_contact', 'Find their email with AI']],
        ['online', 'Where they are online', (bool)$online, 8, $online ? implode(' · ', array_filter([preg_match('#^https?://#i', (string)($l['website'] ?? '')) ? 'website' : '', !empty($l['facebook']) ? 'Facebook' : '', !empty($l['instagram']) ? 'Instagram' : ''])) : 'Their website or Facebook Page shows what they care about', $online ? null : ['research', 'Research them']],
        ['research', 'Researched (last 30 days)', $fresh && ($r['sure'] ?? true), 14, $fresh ? count((array)($r['discoveries'] ?? [])) . ' facts found ' . substr((string)$r['at'], 0, 10) . (isset($r['sure']) && !$r['sure'] ? ', but not confirmed as this business' : '') : 'Knowing their business is what makes the first line land', $fresh ? null : ['research', empty($r) ? 'Research them' : 'Research again']],
        ['hook', 'A personal opening', (bool)$hooks, 14, $hooks ? '"' . mb_substr((string)$hooks[0], 0, 120) . '"' : 'One real thing about them to open with', $hooks ? null : ['research', 'Research them']],
        ['message', $st === 'contacted' ? 'The follow-up written' : 'The first message written', $draft, 14, $draft ? 'Ready to read' : 'Drafted from what we know, for you to read', $draft || !in_array($st, ['new', 'qualified', 'drafted', 'contacted'], true) ? null : [$st === 'contacted' ? 'followup_ai' : 'draft_outreach', $st === 'contacted' ? 'Draft the follow-up' : 'Draft the message']],
        ['approved', 'Approved to go out', !empty($l['approved_at']) || !$first || !$mail, 14, !empty($l['approved_at']) ? 'In the send queue' : ($first ? 'Read it, then tick it and press Send' : 'Sent'), null],
    ];
    if (!$waOn) {
        $checks = array_values(array_filter($checks, fn($c) => $c[0] !== 'whatsapp'));
    }
    $tot = array_sum(array_column($checks, 3));
    $got = array_sum(array_map(fn($c) => $c[2] ? $c[3] : 0, $checks));
    $missing = array_values(array_map(fn($c) => $c[1], array_filter($checks, fn($c) => !$c[2])));
    // the one next step: what unlocks the most, in order
    $next = null;
    foreach ($checks as $c) {
        if (!$c[2] && $c[5] !== null && !in_array($st, ['won', 'lost', 'optout'], true)) {
            $next = [$c[5][1], $c[5][0], $c[1]];
            break;
        }
    }
    // the approach: channel order, time, angle and rhythm
    $fb = (bool)preg_match('#facebook\.com/#i', (string)($l['facebook'] ?? ''));
    $ig = (bool)preg_match('#instagram\.com/#i', (string)($l['instagram'] ?? ''));
    $steps = [];
    if (in_array($st, ['replied', 'proposal'], true)) {
        $steps[] = ['Today', $wa ? 'WhatsApp' : ($phone ? 'Call' : 'Email'), $st === 'proposal' ? 'Ask if they had a look and offer to walk them through it' : 'Answer what they asked, then suggest a short call'];
        $steps[] = ['In 2 days', $phone ? 'Call' : 'Email', 'Book the meeting or the go-ahead'];
    } elseif (in_array($st, ['won', 'lost', 'optout'], true)) {
        $steps = [];
    } else {
        $warm = ($fb || $ig) && $first;
        if ($warm) {
            $steps[] = ['Day 1', $fb ? 'Comment on their Facebook Page' : 'Comment on their Instagram', 'A real compliment or a useful tip, no selling: they see our name before the message'];
        }
        $firstCh = $wa && $named ? 'WhatsApp' : ($mail ? 'Email' : ($wa ? 'WhatsApp' : ($phone ? 'Call' : '')));
        if ($firstCh !== '') {
            $steps[] = [$warm ? 'Day 2' : 'Day 1', $firstCh, $st === 'contacted' ? 'A light follow-up on our first message, with one new reason' : 'Open on ' . ($hooks ? 'what we found about them' : 'their kind of business') . ', end with one easy question'];
        }
        $second = $firstCh === 'WhatsApp' ? ($mail ? 'Email' : ($phone ? 'Call' : '')) : ($wa ? 'WhatsApp' : ($phone ? 'Call' : ''));
        if ($second !== '') {
            $steps[] = [$warm ? 'Day 5' : 'Day 4', $second, 'A different angle, still one question'];
        }
        if ($phone) {
            $steps[] = [$warm ? 'Day 9' : 'Day 8', 'Call', 'A short, friendly call to the ' . ($named ? trim(explode(' ', (string)$l['contact'])[0]) : 'owner or manager')];
        }
        if (!$steps) {
            $steps[] = ['First', 'Find a way in', 'No WhatsApp, email or phone yet: find their details first'];
        }
    }
    [$win, $why] = pm_approach_time($l);
    $used = array_column(pm_touches($l), 'ch');
    $key = fn(string $label) => str_starts_with($label, 'Comment on their Facebook') ? 'comment_on_their_page' : (str_starts_with($label, 'Comment on their Instagram') ? 'instagram_dm' : strtolower($label));
    foreach ($steps as $i => $st) { // a step any department already did is marked done
        $steps[$i][3] = in_array($key($st[1]), $used, true);
    }
    return ['checks' => $checks, 'ready' => $tot ? (int)round(100 * $got / $tot) : 0, 'missing' => $missing, 'next' => $next,
        'plan' => ['steps' => $steps, 'time' => $win, 'time_why' => $why, 'angle' => $hooks ? (string)$hooks[0] : '', 'touches' => array_slice(pm_touches($l), 0, 4)]];
}

/** The Approach block on a lead card: readiness, what is missing (with the button that fixes it), and the plan. */
function pm_approach_html(array $l, callable $post, callable $hid): string
{
    $a = pm_approach($l);
    $id = (string)$l['id'];
    $ai = pm_agents_ready();
    $h = '<div class="approach"><div class="ap-head"><b>Approach</b><span class="ap-meter" title="How ready this lead is for a good first approach"><i style="width:' . $a['ready'] . '%"></i></span><span class="muted t-sm">' . $a['ready'] . '% ready</span>';
    if ($a['next'] && ($ai || !in_array($a['next'][1], ['find_contact', 'research', 'draft_outreach', 'followup_ai'], true))) {
        $h .= '<form method="post" class="inline right">' . $post($id, $a['next'][1]) . '<button class="btn small primary" title="' . pm_h('Next: ' . $a['next'][2]) . '">' . pm_h($a['next'][0]) . '</button></form>';
    }
    $h .= '</div><div class="ap-cols"><ul class="ap-checks">';
    foreach ($a['checks'] as [$k, $label, $ok, , $note]) {
        $h .= '<li class="' . ($ok ? 'ok' : 'miss') . '"><i aria-hidden="true">' . ($ok ? '✓' : '·') . '</i><span><b>' . pm_h($label) . '</b><small>' . pm_h($note) . '</small>';
        if (!$ok && $k === 'email') {
            $h .= '<form method="post" class="inline">' . $post($id, 'save_contact') . '<input type="email" name="email" placeholder="Their email address" aria-label="Email address" autocomplete="off"><button class="btn small">Save</button></form>';
        } elseif (!$ok && $k === 'whatsapp') {
            $h .= '<form method="post" class="inline">' . $post($id, 'save_contact') . '<input type="text" name="whatsapp" placeholder="e.g. 0999 123 456" aria-label="WhatsApp number" inputmode="tel" autocomplete="off"><button class="btn small">Save</button></form>';
        } elseif (!$ok && $k === 'name') {
            $h .= (($hp = pm_contact_hint_parts($l)) && empty($hp['not_owner']) ? '<form method="post" class="inline">' . $post($id, 'use_hint') . '<button class="btn small">Use ' . pm_h($hp['name']) . '</button></form>' : '')
                . '<form method="post" class="inline">' . $post($id, 'save_contact') . '<input type="text" name="contact" placeholder="Owner or manager name" aria-label="Contact name" autocomplete="off"><button class="btn small">Save</button></form>';
        }
        $h .= '</span></li>';
    }
    $h .= '</ul><div class="ap-plan">';
    if ($a['plan']['steps']) {
        $h .= '<ol class="ap-steps">';
        foreach ($a['plan']['steps'] as $st) {
            [$when, $ch, $what] = $st;
            $h .= '<li' . (!empty($st[3]) ? ' class="done"' : '') . '><b>' . pm_h($when) . ' · ' . pm_h($ch) . (!empty($st[3]) ? ' ✓' : '') . '</b><small>' . pm_h($what) . '</small></li>';
        }
        $h .= '</ol>';
        if ($a['plan']['touches']) {
            $h .= '<p class="hint">So far: ' . pm_h(implode(' · ', array_map(fn($x) => date('j M', strtotime($x['at'])) . ' ' . pm_touch_label($x['ch']) . ' (' . $x['by'] . ')', $a['plan']['touches']))) . '</p>';
        }
        $h .= '<p class="hint">Best time: <b>' . pm_h($a['plan']['time']) . '</b>, ' . pm_h($a['plan']['time_why']) . '.</p>';
        if ($a['plan']['angle'] !== '') {
            $h .= '<p class="hint">Open on: “' . pm_h(mb_substr($a['plan']['angle'], 0, 160)) . '”</p>';
        }
    } else {
        $h .= '<p class="hint">No approach needed: this lead is ' . pm_h(strtolower((string)(PM_LEAD_STATUSES[$l['status']] ?? $l['status']))) . '.</p>';
    }
    return $h . '</div></div></div>';
}
