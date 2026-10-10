<?php
/**
 * sx_harvest — MARKETING.md cycle 2, the HARVEST swarm: C2-A01 (win-back, post-sign and re-verify results reach the owner),
 * C2-A02 (re-qualify once research lands), C2-A03 (follow-up subject arms), C2-A04 (reply mode per channel), C2-A08 (Google review link).
 * Loaded from agents.php (so run_agents.php and the worker have it) and by lib/social_modules.php. Pure and rule-based where possible.
 * Nothing here sends by itself: every send is an owner action and keeps the same STOP / suppression / lint gates as the first email.
 */

/* ---------------- C2-A03 · subject arms that really differ ---------------- */

/** The two subject styles the experiment compares. The Writer and the Follow-up agent are told which one to use for each lead. */
const PM_SUBJECT_STYLES = [
    'A' => 'a plain statement that names one specific, true thing about their business',
    'B' => 'a short, honest question about one specific thing in their business',
];

function pm_subject_style(string $arm): string
{
    return PM_SUBJECT_STYLES[$arm === 'B' ? 'B' : 'A'];
}

/** The arm for a lead's NEXT follow-up email (each follow-up is its own draw, so a lead can see both styles over time). */
function pm_followup_arm(array $lead, ?string $brand = null): string
{
    $n = (int)($lead['followups'] ?? 0) + 1;
    return pm_email_exp_arm((string)($lead['id'] ?? '') . '|f' . $n, $brand ?? (string)($lead['brand'] ?? pm_brand()), false);
}

/** Called when a follow-up email really went out: remembers its arm and subject so the experiment can score it later. */
function pm_followup_log_sent(array &$lead, array $draft, string $now): void
{
    $arm = (string)($lead['followup_arm'] ?? '');
    if (!in_array($arm, ['A', 'B'], true) || trim((string)($draft['email_subject'] ?? '')) === '') {
        return;
    }
    $lead['followup_sent'] = array_slice(array_merge((array)($lead['followup_sent'] ?? []), [['at' => $now, 'arm' => $arm, 'subject' => mb_substr((string)$draft['email_subject'], 0, 120)]]), -6);
    unset($lead['followup_arm']);
}

/** Rows {arm, subject, replied} for every follow-up that went out with an arm. A reply counts when it came after the follow-up, within 14 days. */
function pm_followup_exp_rows(array $leads): array
{
    $rows = [];
    foreach ($leads as $l) {
        $reply = strtotime((string)($l['last_reply'] ?? '')) ?: 0;
        foreach ((array)($l['followup_sent'] ?? []) as $f) {
            $at = strtotime((string)($f['at'] ?? '')) ?: 0;
            if (!$at || !in_array($f['arm'] ?? '', ['A', 'B'], true)) {
                continue;
            }
            $rows[] = ['arm' => (string)$f['arm'], 'subject' => (string)($f['subject'] ?? ''), 'replied' => $reply > $at && $reply <= $at + 14 * 86400];
        }
    }
    return $rows;
}

/** One line for the Follow-up agent: the follow-up subject style that earned replies; '' while nothing is proven. */
function pm_email_exp_followup_example(string $brand): string
{
    $f = (array)(pm_load('email_exp', fn() => [])['followup'] ?? []);
    return empty($f['example']) ? '' : 'Follow-up subjects that get replies in ' . (pm_brand_name($brand)) . ' look like this: "' . $f['example'] . '". Mirror its shape.';
}

/* ---------------- C2-A02 · re-qualify once research lands ---------------- */

/** Leads whose research is newer than their last scoring: at most $max, best score first. Terminal leads never qualify. */
function pm_requalify_due(array $leads, int $max = 3): array
{
    $due = [];
    foreach ($leads as $l) {
        $rat = (string)($l['research']['at'] ?? '');
        if ($rat === '' || !in_array((string)($l['status'] ?? ''), ['qualified', 'drafted', 'contacted', 'replied'], true)) {
            continue;
        }
        $done = (string)($l['requalified_at'] ?? '');
        if (($done !== '' && strcmp($done, $rat) >= 0) || (int)($l['requalify_tries'] ?? 0) >= 2) {
            continue;
        }
        $due[] = $l;
    }
    usort($due, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    return array_slice($due, 0, max(0, $max));
}

/** Applies a Qualifier row to a researched lead: a fresh score, package and angle. The status never changes here (the owner stays in charge). Returns the note. */
function pm_apply_requalify(array &$lead, array $q, string $brand): string
{
    $old = (int)($lead['score'] ?? 0);
    $new = max(0, min(100, (int)($q['score'] ?? $old)));
    $lead['score_before_research'] ??= $old;
    $lead['score'] = $new;
    $oldPkg = $lead['package'] ?? null;
    if ($brand === 'travel') {
        $lead['package'] = pm_onboarding_index();
    } elseif (!pm_brand_is_custom($brand) && isset($q['package']) && is_numeric($q['package'])) {
        $max = max(0, count((array)(pm_template()['packages'] ?? [])) - 1);
        $lead['package'] = max(0, min($max, (int)$q['package']));
    }
    foreach (['pain', 'reason'] as $k) {
        if (trim((string)($q[$k] ?? '')) !== '') {
            $lead[$k] = (string)$q[$k];
        }
    }
    if (($off = pm_clean_offering($q['offering'] ?? '')) !== '') {
        $lead['offering'] = $off;
    }
    $lead['requalified_at'] = date('Y-m-d H:i');
    unset($lead['requalify_tries'], $lead['engage_bonus'], $lead['engage_scored_at']); // C3-A04: the new score has no engagement points in it yet
    $msg = "Re-scored after research: $old to $new" . ($oldPkg !== null && ($lead['package'] ?? null) !== $oldPkg ? ', package changed' : '');
    if (!empty($q['skip'])) {
        $msg .= '. The Qualifier now doubts it is a fit; the status was left as it is for you to decide';
    }
    pm_lead_note($lead, $msg);
    return $msg;
}

/* ---------------- C2-G02 · the lead archive, as the swarm needs to see it ---------------- */

/** Leads moved out of the main list after 12 quiet months (see sx_ops.php): id => lead. The scouts count them as known, so they are not found as "new" again. */
function pm_leads_archive(): array
{
    return pm_load('leads_archive', fn() => []);
}

/* ---------------- C2-A04 · reply mode per channel ---------------- */

/**
 * 'draft' = every answer waits for the owner's approval; 'auto' = simple, safe answers go out by themselves (anything that needs a person still stops).
 * Email follows reply_mode (default draft). WhatsApp Business has its own switch, wa_reply_mode (default auto, as before).
 */
function pm_reply_mode(string $ch = 'email'): string
{
    $c = pm_agents_config('promanaged');
    $v = $ch === 'wa' ? (string)($c['wa_reply_mode'] ?? 'auto') : (string)($c['reply_mode'] ?? 'draft');
    return $v === 'draft' ? 'draft' : 'auto';
}

/* ---------------- C2-A08 · the Google review link in review asks ---------------- */

/** The brand's Google review link from Social > Channels, or '' when none (only a plain https link is accepted). */
function pm_google_review_link(string $brand): string
{
    $all = pm_load('social_channels', fn() => []);
    $u = trim((string)($all[pm_brand_norm($brand)]['google_review_url'] ?? ''));
    return preg_match('#^https://[^\s<>"\']{4,280}$#i', $u) ? $u : '';
}

/** Builds the stored post-sign asks from the agent's row: the review ask always carries the configured link (and no other link). */
function pm_postsign_finish(array $w, string $brand): array
{
    $review = trim((string)($w['review'] ?? ''));
    $link = pm_google_review_link($brand);
    if ($link !== '') {
        $review = trim((string)preg_replace('#https?://\S+#i', '', $review));
        $review = trim(rtrim($review) . "\n" . $link);
    }
    return ['testimonial' => (string)($w['testimonial'] ?? ''), 'review' => $review, 'referral' => (string)($w['referral'] ?? ''), 'review_link' => $link];
}

/* ---------------- C2-A01 · what the new agents found, in front of the owner ---------------- */

/** Records what a contact re-check changed (or that nothing did). $before = [email, phone, contact] taken before the result was applied. */
function pm_reverify_result(array &$lead, array $before): string
{
    $what = [];
    if (strcasecmp((string)($lead['email'] ?? ''), (string)$before[0]) !== 0 && ($lead['email'] ?? '') !== '') {
        $what[] = 'new email address ' . $lead['email'];
    }
    if (trim((string)($lead['phone'] ?? '')) !== '' && trim((string)$before[1]) === '') {
        $what[] = 'a phone number ' . $lead['phone'];
    }
    if (trim((string)($lead['contact'] ?? '')) !== '' && trim((string)$before[2]) === '') {
        $what[] = 'the contact is ' . $lead['contact'];
    }
    $txt = $what ? 'Found ' . implode(', ', $what) : 'Checked again: nothing has changed';
    $lead['reverify'] = ['at' => date('Y-m-d H:i'), 'what' => $txt, 'seen' => !$what];
    return $txt;
}

/** The subject lines for the three post-sign asks (plain, honest, no hype). */
function pm_postsign_subject(string $which, string $company): string
{
    return match ($which) {
        'testimonial' => 'May we quote you? ' . $company,
        'review' => 'A quick favour, if you were happy with us',
        'thanks' => 'Thank you',
        default => 'Do you know someone we could help too?',
    };
}

/**
 * Sends one post-sign ask by email to a client who signed. Same gates as outreach: STOP/suppression, a real address, a clean message, the bounce breaker.
 * It is not counted in the cold-send cap (the client asked for our work), and it never changes the lead's status. $mailer mimics pm_mail (tests).
 */
function pm_send_postsign(array &$leads, string $id, string $which, ?callable $mailer = null): array
{
    $no = fn(string $m) => ['ok' => false, 'msg' => $m];
    if (!isset($leads[$id]) || !in_array($which, ['testimonial', 'review', 'referral', 'thanks'], true)) {
        return $no('That ask was not found.');
    }
    $l = $leads[$id];
    $prev = pm_brand();
    pm_brand_set((string)($l['brand'] ?? 'promanaged'));
    try {
        $body = trim((string)($l['postsign'][$which] ?? ''));
        $email = trim((string)($l['email'] ?? ''));
        if (($l['status'] ?? '') === 'optout') {
            return $no(($l['name'] ?? 'This business') . ' asked not to be contacted.');
        }
        if ($body === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $no('This lead needs a valid email address and a written ask.');
        }
        if (!empty($l['email_bad']) && ($l['email_bad'] === true || strcasecmp((string)$l['email_bad'], $email) === 0)) {
            return $no('That address bounced before. Find a different email address first.');
        }
        if (function_exists('pm_suppressed') && pm_suppressed($leads, $email, $id)) {
            return $no('That address or company asked not to be contacted.');
        }
        if (function_exists('pm_send_breaker') && ($b = pm_send_breaker())['blocked']) {
            return $no($b['why']);
        }
        $settings = pm_settings();
        $subject = pm_postsign_subject($which, (string)$settings['company_name']);
        $lint = array_values(array_filter(pm_outreach_lint($subject, $body, false), fn($x) => !str_starts_with($x, 'The message is'))); // a short thank-you is fine
        if ($lint) {
            return $no('Not sent. ' . implode(' ', $lint) . ' Edit the message and try again.');
        }
        if ($mailer === null) {
            if (!function_exists('pm_mail') && is_file(__DIR__ . '/mail.php')) {
                require_once __DIR__ . '/mail.php';
            }
            $mailer = 'pm_mail';
        }
        $from = ($settings['smtp']['from_email'] ?? '') ?: ($settings['email'] ?? '');
        $res = $mailer($settings, ['to' => $email, 'subject' => $subject, 'body' => $body . pm_outreach_footer($settings),
            'headers' => ['List-Unsubscribe' => '<mailto:' . $from . '?subject=STOP>', 'Auto-Submitted' => 'no'], 'link' => null]);
        [$ok, $why, $mid] = array_pad((array)$res, 3, '');
        if (!$ok) {
            return $no('Could not send: ' . (function_exists('pm_mail_fail') ? pm_mail_fail($settings, (string)$why) : $why));
        }
        $now = date('Y-m-d H:i');
        $L = &$leads[$id];
        $L['thread'][] = ['dir' => 'out', 'at' => $now, 'text' => $body] + ($mid ? ['mid' => $mid] : []);
        if ($mid) {
            $L['msg_ids'] = array_slice(array_merge((array)($L['msg_ids'] ?? []), [(string)$mid]), -20);
        }
        $L['last_out'] = $now;
        $L['postsign_sent'][$which] = $now;
        pm_lead_note($L, 'Post-sign ' . $which . ' ask sent to ' . $email);
        unset($L);
        pm_leads_save($leads);
        return ['ok' => true, 'msg' => 'Sent the ' . $which . ' ask to ' . ($l['name'] ?? 'the client') . '.'];
    } finally {
        pm_brand_set($prev);
    }
}

/** True while a lead still has a win-back, post-sign or contact result the owner has not dealt with. Used for the tags on the lead card. */
function pm_lead_open_drafts(array $l): array
{
    $o = [];
    if (!empty($l['winback_draft']) && ($l['status'] ?? '') === 'lost') {
        $o[] = 'winback';
    }
    if (($l['status'] ?? '') === 'won' && ((!empty($l['postsign']) && empty($l['postsign_done']))
        || (!empty($l['review_posted_at']) && trim((string)($l['postsign']['thanks'] ?? '')) !== '' && empty($l['postsign_sent']['thanks'])))) { // C3-A07: a thank-you for a review is still waiting
        $o[] = 'postsign';
    }
    if (!empty($l['reverify']['what']) && empty($l['reverify']['seen'])) {
        $o[] = 'reverify';
    }
    return $o;
}

/* ---------------- C3-A01 · WhatsApp opt-in: who agreed to be messaged there, and how ---------------- */

/** How a person came to agree to WhatsApp messages (the label finishes the sentence "agreed to WhatsApp: ..."). */
const PM_WA_OPTIN_SOURCES = [
    'wrote_first' => 'they wrote to us first',
    'replied' => 'they replied on WhatsApp',
    'form' => 'they ticked the box on our form',
    'owner' => 'recorded by us (they said so)',
];

/**
 * The lead's WhatsApp opt-in: ['source','at','label'], or [] when none is on record. An explicit record counts, and so does any WhatsApp message
 * they sent us (older leads have no field, so it is worked out from the conversation). Taking it off (wa_optin_off_at) ends it until they write again.
 */
function pm_wa_optin(array $lead): array
{
    if (($lead['status'] ?? '') === 'optout') { // STOP, or a do-not-contact lead, is the opposite of an opt-in
        return [];
    }
    $off = (string)($lead['wa_optin_off_at'] ?? '');
    $o = (array)($lead['wa_optin'] ?? []);
    if (isset(PM_WA_OPTIN_SOURCES[(string)($o['source'] ?? '')]) && ($off === '' || strcmp((string)($o['at'] ?? ''), $off) > 0)) {
        return ['source' => (string)$o['source'], 'at' => (string)($o['at'] ?? ''), 'label' => PM_WA_OPTIN_SOURCES[$o['source']]];
    }
    $firstSent = '';
    foreach ((array)($lead['wa_sent'] ?? []) as $at) {
        $firstSent = ($firstSent === '' || strcmp((string)$at, $firstSent) < 0) ? (string)$at : $firstSent;
    }
    foreach ((array)($lead['thread'] ?? []) as $m) {
        if (($m['dir'] ?? '') === 'in' && ($m['ch'] ?? '') === 'wa' && ($off === '' || strcmp((string)($m['at'] ?? ''), $off) > 0)) {
            $src = $firstSent !== '' && strcmp($firstSent, (string)$m['at']) < 0 ? 'replied' : 'wrote_first';
            return ['source' => $src, 'at' => (string)$m['at'], 'label' => PM_WA_OPTIN_SOURCES[$src]];
        }
    }
    return [];
}

/** Writes the opt-in on a lead (the first record is kept). Returns true when it was newly recorded. */
function pm_wa_optin_record(array &$lead, string $source, ?string $at = null): bool
{
    if (!isset(PM_WA_OPTIN_SOURCES[$source]) || pm_wa_optin($lead)) {
        return false;
    }
    $lead['wa_optin'] = ['source' => $source, 'at' => $at ?? date('Y-m-d H:i')];
    unset($lead['wa_optin_off_at']);
    return true;
}

/** They said they do not want WhatsApp messages (but did not say STOP): the opt-in ends until they write again. */
function pm_wa_optin_clear(array &$lead): void
{
    unset($lead['wa_optin']);
    $lead['wa_optin_off_at'] = date('Y-m-d H:i');
}

/** One line for the lead card. */
function pm_wa_optin_line(array $lead): string
{
    $o = pm_wa_optin($lead);
    return $o ? 'Agreed to WhatsApp: ' . $o['label'] . ($o['at'] !== '' ? ' (' . date('j M Y', strtotime($o['at']) ?: time()) . ')' : '') : 'No WhatsApp opt-in on record: campaigns skip them by default.';
}

/* ---------------- C3-A03 · win-back and post-sign asks on WhatsApp Business ---------------- */

/** The text of a drafted ask by kind: 'winback' or one of the post-sign asks ('testimonial', 'review', 'referral', 'thanks'). */
function pm_wa_draft_text(array $lead, string $kind): string
{
    return $kind === 'winback' ? trim((string)($lead['winback_draft']['whatsapp'] ?? '')) : trim((string)($lead['postsign'][$kind] ?? ''));
}

/**
 * Sends one drafted ask on WhatsApp Business after the owner pressed the button. Same gates as every other message: STOP, a mobile number, the 24-hour window
 * (outside it WhatsApp only allows templates, so use "Open WhatsApp" instead), the daily WhatsApp limit and the honesty/spam lint on the finished text.
 * $leads is updated and saved on success. Returns ['ok','msg'].
 */
function pm_send_wa_draft(array &$leads, string $id, string $kind): array
{
    $no = fn(string $m) => ['ok' => false, 'msg' => $m];
    if (!isset($leads[$id]) || !in_array($kind, ['winback', 'testimonial', 'review', 'referral', 'thanks'], true)) {
        return $no('That ask was not found.');
    }
    $l = $leads[$id] + ['id' => $id];
    $prev = pm_brand();
    pm_brand_set((string)($l['brand'] ?? 'promanaged'));
    try {
        if (($l['status'] ?? '') === 'optout') {
            return $no(($l['name'] ?? 'This business') . ' asked not to be contacted.');
        }
        if (!function_exists('pm_wa_biz_cfg') || !pm_wa_biz_cfg((string)($l['brand'] ?? 'promanaged'))['ready']) {
            return $no('WhatsApp Business is not connected for ' . pm_brand_name((string)($l['brand'] ?? 'promanaged')) . ': use "Open WhatsApp" and send it from your phone.');
        }
        $text = pm_wa_draft_text($l, $kind);
        [$num] = pm_wa_best($l);
        if ($text === '' || $num === '') {
            return $no('This lead needs a mobile number and a written WhatsApp message.');
        }
        if (!pm_wa_biz_window_open($l)) {
            return $no('More than 24 hours have passed since they wrote on WhatsApp, so WhatsApp only allows an approved template. Use "Open WhatsApp" and send it from your phone.');
        }
        $cap = (int)(pm_agents_config()['wa_cap'] ?? 40);
        if (pm_wa_sent_today($leads) >= $cap) {
            return $no("You have reached today's limit of $cap WhatsApp messages.");
        }
        $full = pm_wa_text($l, $text);
        $bad = array_values(array_filter(pm_outreach_lint('WhatsApp message', $full, false), fn($x) => !str_starts_with($x, 'The message is')));
        if ($bad) {
            return $no('Not sent. ' . implode(' ', $bad) . ' Edit the message and try again.');
        }
        [$ok, $mid] = pm_wa_biz_send($num, $full, (string)($l['brand'] ?? 'promanaged'));
        if (!$ok) {
            return $no('Could not send: ' . $mid);
        }
        $now = date('Y-m-d H:i');
        $L = &$leads[$id];
        $L['wa_sent'][] = $now;
        $L['thread'][] = ['dir' => 'out', 'at' => $now, 'text' => mb_substr($text, 0, 1500), 'ch' => 'wa'];
        $L['last_out'] = $now;
        if ($kind === 'winback') {
            $L['last_contacted'] = $L['winback_sent_at'] = $now;
            $L['status'] = 'contacted';
            $L['followups'] = (int)(pm_agents_config()['max_followups'] ?? 3); // no automatic follow-ups after a win-back
            unset($L['winback_draft']);
            pm_lead_note($L, 'Win-back sent on WhatsApp Business');
        } else {
            $L['postsign_sent'][$kind] = $now;
            pm_lead_note($L, 'Post-sign ' . $kind . ' ask sent on WhatsApp Business');
        }
        unset($L);
        pm_leads_save($leads);
        return ['ok' => true, 'msg' => 'Sent on WhatsApp to ' . ($l['name'] ?? 'the lead') . '.'];
    } finally {
        pm_brand_set($prev);
    }
}

/* ---------------- C3-A04 · engagement raises the score ---------------- */

const PM_ENGAGE_OPENED_BUMP = 8;   // opened the proposal twice or more
const PM_ENGAGE_OPENED_MORE = 6;   // ... and four times or more
const PM_ENGAGE_REPLY_BUMP = 12;   // wrote back
const PM_ENGAGE_CAP = 25;          // the most a lead can gain from engagement
const PM_ENGAGE_MAX_SCORE = 95;    // never "certain": above 95 would hide a lead that still needs a human look

/** What a lead did that shows interest: ['kinds' => [...], 'at' => 'Y-m-d H:i' of the latest signal, 'bonus' => points]. Pure. */
function pm_engagement_signals(array $l): array
{
    $views = (int)($l['view_count'] ?? 0);
    $kinds = [];
    $bonus = 0;
    $latest = 0;
    if ($views >= 2) {
        $kinds[] = 'opened the proposal ' . $views . ' times';
        $bonus += PM_ENGAGE_OPENED_BUMP + ($views >= 4 ? PM_ENGAGE_OPENED_MORE : 0);
        $latest = max($latest, (int)strtotime((string)(($l['last_viewed_at'] ?? '') ?: ($l['viewed_at'] ?? ''))));
    }
    if (!empty($l['last_reply']) && ($l['status'] ?? '') !== 'optout') {
        $kinds[] = 'wrote back';
        $bonus += PM_ENGAGE_REPLY_BUMP;
        $latest = max($latest, (int)strtotime((string)$l['last_reply']));
    }
    return ['kinds' => $kinds, 'at' => $latest ? date('Y-m-d H:i', $latest) : '', 'bonus' => min(PM_ENGAGE_CAP, $bonus)];
}

/** Leads with a new interest signal since their score was last refreshed for engagement: at most $max, newest signal first. Won, lost and do-not-contact leads never. */
function pm_engagement_due(array $leads, int $max = 20): array
{
    $due = [];
    foreach ($leads as $l) {
        if (!in_array((string)($l['status'] ?? ''), ['qualified', 'drafted', 'contacted', 'replied', 'proposal'], true)) {
            continue;
        }
        $sig = pm_engagement_signals($l);
        if (!$sig['kinds']) {
            continue;
        }
        $done = (int)strtotime((string)($l['engage_scored_at'] ?? ''));
        if (!$done || ((int)strtotime($sig['at'])) > $done) {
            $due[] = [(int)strtotime($sig['at']), $l];
        }
    }
    usort($due, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($x) => $x[1], array_slice($due, 0, max(0, $max)));
}

/** Raises the score by the lead's engagement bonus (never twice for the same signals, never above 95). Returns the note, or '' when nothing changed. */
function pm_apply_engagement(array &$l): string
{
    $sig = pm_engagement_signals($l);
    $old = (int)($l['score'] ?? 0);
    $prev = (int)($l['engage_bonus'] ?? 0);
    $base = max(0, $old - $prev);
    $new = max($old, min(PM_ENGAGE_MAX_SCORE, $base + $sig['bonus']));
    $l['engage_bonus'] = $new - $base;
    $l['engage_scored_at'] = date('Y-m-d H:i');
    $l['engage_at'] = $sig['at'];
    $l['engage_why'] = implode(' and ', $sig['kinds']);
    $l['score'] = $new;
    if ($new === $old) {
        return '';
    }
    $msg = "Score raised from $old to $new: " . $l['engage_why'];
    pm_lead_note($l, $msg);
    return $msg;
}

/** Scheduler job (rule-based, no AI): refreshes engagement scores in the stored leads of this business. */
function pm_job_engagement_scores(string $brand): string
{
    $n = 0;
    pm_update('leads', function (array $all) use ($brand, &$n) {
        $mine = array_filter($all, fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
        foreach (pm_engagement_due($mine, 20) as $l) {
            $id = (string)($l['id'] ?? '');
            if (isset($all[$id]) && pm_apply_engagement($all[$id]) !== '') {
                $n++;
            }
        }
        return $all;
    }, fn() => []);
    return $n ? "$n lead score(s) raised by engagement" : '';
}

/* ---------------- C3-A07 · count the reviews we won, and thank the client ---------------- */

/** The short thank-you for someone who posted a review. Rule-based (no AI): plain, warm, no offer and no link. */
function pm_review_thanks_text(array $lead): string
{
    $nm = trim((string)($lead['contact'] ?? ''));
    $nm = preg_replace('/^(dr|mr|mrs|ms|miss|prof|eng|hon|rev|pastor)\.?\s+/i', '', $nm);
    $first = trim(explode(' ', (string)$nm)[0] ?? '');
    return ($first !== '' ? "Hello $first, " : 'Hello, ') . 'thank you for taking the time to review us. It means a lot to a small business like ours, and we are glad to be working with you.';
}

/** The owner saw that a client posted a review: it is counted, and a thank-you is drafted (not sent). Returns false when it was already counted. */
function pm_review_mark_posted(array &$lead): bool
{
    if (!empty($lead['review_posted_at'])) {
        return false;
    }
    $lead['review_posted_at'] = date('Y-m-d');
    if (trim((string)($lead['postsign']['thanks'] ?? '')) === '') {
        $lead['postsign']['thanks'] = pm_review_thanks_text($lead);
    }
    pm_lead_note($lead, 'Google review posted: counted');
    return true;
}

/** Reviews won per month for a business, oldest first: ['2026-05' => 2, ...] for the last $months months (months with none show 0). */
function pm_reviews_by_month(string $brand, int $months = 6, ?array $leads = null): array
{
    $leads ??= pm_load('leads', fn() => []);
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $out[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = 0;
    }
    foreach ($leads as $l) {
        $m = substr((string)($l['review_posted_at'] ?? ''), 0, 7);
        if (($l['brand'] ?? 'promanaged') === $brand && isset($out[$m])) {
            $out[$m]++;
        }
    }
    return $out;
}

/** Results-screen card: reviews won per month, and how many post-sign review asks are still waiting for a result. */
function pm_panel_results_reviews(string $vb, array $ctx = []): string
{
    $leads = pm_load('leads', fn() => []);
    $by = pm_reviews_by_month($vb, 6, $leads);
    $total = count(array_filter($leads, fn($l) => ($l['brand'] ?? 'promanaged') === $vb && !empty($l['review_posted_at'])));
    $asked = count(array_filter($leads, fn($l) => ($l['brand'] ?? 'promanaged') === $vb && !empty($l['postsign_sent']['review']) && empty($l['review_posted_at'])));
    $max = max(1, max($by));
    $h = '<div class="card"><h2>Google reviews won</h2>';
    if (!$total && !$asked) {
        return $h . '<p class="hint">Nothing counted yet. When a client posts a review after your ask, press "They posted the review" on their lead (Agents &gt; the thank-you asks) and it is counted here.</p></div>';
    }
    $h .= '<table class="sx-table"><tbody>';
    foreach ($by as $m => $n) {
        $h .= '<tr><td>' . pm_h(date('M Y', strtotime($m . '-01'))) . '</td><td class="num">' . (int)$n . '</td><td style="width:50%"><span class="sx-bar"><i style="width:' . (int)round(100 * $n / $max) . '%"></i></span></td></tr>';
    }
    return $h . '</tbody></table><p class="hint">' . (int)$total . ' in all' . ($asked ? ' · ' . $asked . ' ask' . ($asked === 1 ? '' : 's') . ' sent, no review counted yet' : '') . '. Only reviews you saw and ticked are counted: nothing is read from Google.</p></div>';
}
