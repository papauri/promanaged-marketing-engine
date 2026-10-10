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
    return pm_email_exp_arm((string)($lead['id'] ?? '') . '|f' . $n, $brand ?? (string)($lead['brand'] ?? pm_brand()));
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
    unset($lead['requalify_tries']);
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
    if (!isset($leads[$id]) || !in_array($which, ['testimonial', 'review', 'referral'], true)) {
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
            return $no('Could not send: ' . $why);
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
    if (!empty($l['postsign']) && empty($l['postsign_done']) && ($l['status'] ?? '') === 'won') {
        $o[] = 'postsign';
    }
    if (!empty($l['reverify']['what']) && empty($l['reverify']['seen'])) {
        $o[] = 'reverify';
    }
    return $o;
}
