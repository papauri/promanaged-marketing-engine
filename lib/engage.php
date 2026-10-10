<?php
/**
 * Engagement agents:
 *  - Polisher: tailors a proposal to one client, then a sceptical-buyer review pass tightens it ("pitch perfect").
 *  - Inbox + Reply agent: reads replies from businesses you have contacted, classifies them and drafts (or, if you allow it, sends) the answer.
 * Replies are only ever read from people you have already written to. Anything unclear goes to you.
 */
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/director.php';

/* ---------------- Polisher ---------------- */

/**
 * Returns ['cover_hook','intro','pain_points'=>[{pain,cost,fix}],'personal_note','email_subject','email_body'].
 * $line is the (already line-specific) template wording for this package; $lead may be null for a client typed in by hand.
 */
function pm_agent_polish(array $p, array $line, array $package, ?array $lead, bool $review = false): array
{
    $facts = [
        'business' => $p['business'], 'contact' => $p['contact'], 'location' => $p['address'],
        'evidence' => $lead ? array_slice($lead['evidence'] ?? [], 0, 5) : [],
        'research' => $lead ? pm_research_brief($lead) : [],
        'unverified_possible_needs_never_state_as_fact' => $lead ? array_slice($lead['need_signals'] ?? [], 0, 3) : [],
        'notes' => $lead ? array_map(fn($n) => $n['text'], array_slice($lead['notes'] ?? [], -4)) : [],
        'their_replies' => $lead ? array_values(array_map(fn($t) => mb_substr($t['text'], 0, 300), array_filter($lead['thread'] ?? [], fn($t) => $t['dir'] === 'in'))) : [],
        'what_they_asked_for' => $p['personal_note'] ?? '',
    ];
    $base = ['package' => $package['name'] ?? '', 'suited_to' => $package['suited'] ?? '',
        'pain_points' => array_map(fn($x) => ['pain' => $x['pain'], 'fix' => $x['fix']], array_slice($line['pain_points'] ?? [], 0, 8)),
        'benefits' => array_map(fn($b) => $b[0], array_slice($line['benefits'] ?? [], 0, 6))];
    $system = pm_agents_company_brief('tiny') . "\nYou are the Proposal Writer. Write a SHORT proposal that proves we understand this one business, then shows what it GAINS. A proposal that could go to any other business is a failure.\n"
        . "what_we_know: 2-4 short statements of what the business does, taken from 'evidence' only. "
        . "pain_points: exactly 3, each tied to one fact about THE BUSINESS quoted from 'evidence' (never a fact about us): fact, pain (under 12 words, specific), cost (one sentence under 25 words: what it costs THEM, plain words, no invented figures), fix (one sentence under 20 words: what we do). "
        . "gains: exactly 3 outcomes they will see, each under 14 words, concrete and in their terms (e.g. 'Every charge on the guest bill before check-out'). "
        . "cover_hook: one specific sentence about them. intro: one sentence. personal_note: two sentences showing we looked at their business, no flattery. "
        . "Never invent facts, numbers, prices, customers or guarantees; never state how they work today unless evidence says so (use 'when...' wording). Pick from the base pain points and rewrite for them. "
        . "email: " . PM_EMAIL_CRAFT_SHORT . " Placeholders you may use: {contact} {business} {sign_step} {signature}"
        . (($package['type'] ?? '') === 'onboarding' ? ' {fee_line}' : '') . ". If the contact name is empty open with \"Hello,\". {sign_step} is a full sentence on its own line. " . PM_AGENT_RULES;
    $user = "Facts:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE) . "\nBase wording:\n" . json_encode($base, JSON_UNESCAPED_UNICODE)
        . "\nJSON: {\"what_we_know\":[],\"cover_hook\",\"intro\",\"pain_points\":[{\"fact\",\"pain\",\"cost\",\"fix\"}],\"gains\":[],\"personal_note\",\"email_subject\",\"email_body\"}";
    $draft = pm_agent_json(pm_claude($system, $user, false, 2200, 'write'));
    if (!is_array($draft) || empty($draft['pain_points'])) {
        throw new RuntimeException('The AI did not return a usable proposal. Try again.');
    }
    if (!$review) {
        return $draft; // one pass is the default: a second review pass doubles the cost
    }
    $rev = "Review as the sceptical owner of {$p['business']}. Cut anything that could be sent to any other business, anything vague or unsupported by the facts, and padding. Keep placeholders. Return the SAME JSON.\nFacts:\n"
        . json_encode($facts, JSON_UNESCAPED_UNICODE) . "\nDraft:\n" . json_encode($draft, JSON_UNESCAPED_UNICODE);
    $final = pm_agent_json(pm_claude($system, $rev, false, 2200, 'write'));
    return (is_array($final) && !empty($final['pain_points'])) ? $final : $draft;
}

/* ---------------- Reply agent ---------------- */

const PM_REPLY_INTENTS = ['interested', 'question', 'meeting_request', 'not_now', 'not_interested', 'stop', 'out_of_office', 'bounce', 'other'];
/** Intents written by a real person (they set last_reply); bounce, out of office and stop do not. */
const PM_HUMAN_INTENTS = ['interested', 'question', 'meeting_request', 'not_now', 'not_interested'];

if (!function_exists('pm_lost_reasons')) {
    function pm_lost_reasons(): array
    {
        return ['price' => 'Price', 'timing' => 'Timing', 'competitor' => 'Chose a competitor', 'no_need' => 'No need', 'no_trust' => 'Did not trust us yet',
            'not_interested' => 'Not interested', 'no_response' => 'No response', 'other' => 'Other'];
    }
}

/** A YYYY-MM-DD between tomorrow and a year away, else today + $defDays (or '' when $defDays is 0). */
function pm_resume_date(string $d, int $defDays): string
{
    $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? strtotime($d) : false;
    if ($t && $t > strtotime('today') && $t <= strtotime('+365 days')) {
        return date('Y-m-d', $t);
    }
    return $defDays > 0 ? date('Y-m-d', strtotime("+$defDays days")) : '';
}

/** WhatsApp reply text: no links, at most 40 words. */
function pm_wa_reply_clean(string $t): string
{
    $t = trim(preg_replace('#https?://\S+|www\.\S+#i', '', $t));
    $w = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($w) <= 40) {
        return implode(' ', $w);
    }
    $cut = implode(' ', array_slice($w, 0, 40));
    return preg_match('/^(.{40,}[.!?])(?!.*[.!?])/us', $cut, $m) ? $m[1] : rtrim($cut, ',;: ') . '.';
}

/**
 * Classifies one incoming message and drafts the answer. $ch is 'email' or 'wa' (where they wrote).
 * Returns ['intent','summary','needs_human','reply_subject','reply_body','whatsapp','resume_on','next_step'].
 */
function pm_agent_reply(array $lead, string $incoming, string $ch = 'email'): array
{
    $s = pm_settings();
    $tpl = pm_template();
    $pk = $tpl['packages'][(int)($lead['package'] ?? 0)]['name'] ?? '';
    $thread = array_map(fn($t) => ($t['dir'] === 'in' ? 'THEM' : 'US') . ': ' . $t['text'], array_slice($lead['thread'] ?? [], -6));
    $system = pm_agents_company_brief() . "\n\nYou are the Reply agent for {$s['company_name']}. A business we contacted has written back. Classify the message and draft the reply.\n"
        . "Intents: interested (wants to know more or talk), question (asks something), meeting_request (proposes or asks for a call, demo or visit), not_now (later), not_interested, "
        . "stop (any request to stop contact, unsubscribe or remove them, in any wording), out_of_office (automatic reply), bounce (delivery failure), other.\n"
        . "Reply rules: answer only what you can answer from the facts above; never invent prices, dates, features, discounts or guarantees; if they ask for a price or a proposal say we will prepare a tailored proposal and propose a short call. "
        . "If they propose a time, do not commit: say we will confirm shortly or ask for two alternatives. Be warm, brief (under 120 words), plain text, no sign-off (the app adds it), no links. "
        . "Set needs_human true if they are angry, complaining, negotiating price or terms, raise legal or security questions, ask for something outside the facts above, or you are unsure. "
        . "For stop, not_interested, not_now, out_of_office and bounce, reply_body must be empty. "
        . "resume_on: only for not_now, the date (YYYY-MM-DD) they asked to be contacted again, else " . date('Y-m-d', strtotime('+30 days')) . " (today is " . date('Y-m-d') . "). "
        . "whatsapp: the same answer as a WhatsApp message, at most 40 words, no subject, no links; empty when reply_body is empty. " . PM_AGENT_RULES;
    $user = "Business: {$lead['name']} ({$lead['city']}), contact: " . ($lead['contact'] ?? '') . ", package we suggested: $pk, their likely need: " . ($lead['pain'] ?? '') . ".\n"
        . "Conversation so far:\n" . implode("\n", $thread) . "\n\nTheir new message" . ($ch === 'wa' ? ' (on WhatsApp)' : '') . ":\n" . mb_substr($incoming, 0, 4000)
        . "\n\nReturn JSON: {\"intent\",\"summary\":\"one sentence\",\"needs_human\":bool,\"reply_subject\",\"reply_body\",\"whatsapp\",\"resume_on\",\"next_step\":\"what the owner should do next\"}";
    $out = pm_agent_json(pm_claude($system, $user, false, 4000));
    if (!is_array($out)) {
        return pm_reply_fallback('Could not be classified.');
    }
    $out['intent'] = in_array($out['intent'] ?? '', PM_REPLY_INTENTS, true) ? $out['intent'] : 'other';
    $out['needs_human'] = !empty($out['needs_human']);
    $out['resume_on'] = $out['intent'] === 'not_now' ? pm_resume_date((string)($out['resume_on'] ?? ''), 30) : '';
    $out['whatsapp'] = pm_wa_reply_clean((string)($out['whatsapp'] ?? ''));
    return $out;
}

/** What to record when nothing could classify the message: it goes to the owner. */
function pm_reply_fallback(string $why): array
{
    return ['intent' => 'other', 'summary' => $why, 'needs_human' => true, 'reply_subject' => '', 'reply_body' => '', 'whatsapp' => '', 'resume_on' => '', 'next_step' => 'Read it and reply yourself.'];
}

/** A cheap local check that catches STOP requests even if the model were to miss one. */
function pm_looks_like_stop(string $text): bool
{
    return (bool)preg_match('/^\s*(stop|unsubscribe|remove me|opt[- ]?out)\b|\b(do not|don\'t|dont|please do not|stop)\s+(contact(ing)?|email(ing)?|message|messaging|write to|writing to|send(ing)?)\b|\bremove (me|us) from\b|\bunsubscribe\b/i', mb_substr($text, 0, 600));
}

/** Someone on the do-not-contact list explicitly asking to hear from us again. */
function pm_wants_resume(string $text): bool
{
    return (bool)preg_match('/\b(re-?subscribe|resume (contact|emails?|messages?)|(you (may|can)|please|feel free to) (contact|email|message|write to) me again|start (contacting|emailing|messaging) me again)\b/i', mb_substr($text, 0, 600));
}

/** A result that needs no model: STOP, a bounce notice, an automatic out-of-office, a do-not-contact lead writing again. Null = ask the model. */
function pm_reply_precheck(array $lead, string $text, array $m = []): ?array
{
    $mk = fn(string $intent, string $why, array $x = []) => $x + ['intent' => $intent, 'summary' => $why, 'needs_human' => false, 'reply_subject' => '', 'reply_body' => '', 'whatsapp' => '', 'resume_on' => '', 'next_step' => ''];
    if (pm_looks_like_stop($text)) {
        return $mk('stop', 'STOP request');
    }
    if (!empty($m['dsn'])) {
        return $mk('bounce', 'Delivery failure notice', ['failed' => (string)($m['failed'] ?? ''), 'hard' => ($m['dsn_kind'] ?? 'hard') !== 'soft']);
    }
    if (!empty($m['auto']) && preg_match('/out of office|auto.?reply|automatic reply|vacation|away from (the )?office/i', ($m['subject'] ?? '') . ' ' . $text)) {
        return $mk('out_of_office', 'Automatic out-of-office reply');
    }
    if (($lead['status'] ?? '') === 'optout' && !pm_wants_resume($text)) {
        return $mk('other', 'Message from a do-not-contact lead (logged only)');
    }
    return null;
}

/** Record a hard bounce: remember the bad address, clear it so nothing is sent to it, and let the plan ask for another contact. */
function pm_mark_bounced(array &$lead, string $addr, bool $hard = true): void
{
    $addr = strtolower(trim($addr)) ?: strtolower((string)($lead['email'] ?? ''));
    if (!$hard) {
        pm_lead_note($lead, "Temporary delivery problem for $addr (address kept)");
        return;
    }
    $lead['email_bad'] = $addr;
    $lead['bounced_at'] = date('Y-m-d H:i');
    $lead['email'] = '';
    unset($lead['followup_draft']);
    pm_lead_note($lead, "Email to $addr bounced; address cleared. Find another contact.");
}

/** Keep the last 20 Message-IDs we sent to a lead, so replies and bounces can be matched to it. */
function pm_lead_add_msgid(array &$lead, string $mid): void
{
    if ($mid !== '') {
        $lead['msg_ids'] = array_slice(array_values(array_unique(array_merge((array)($lead['msg_ids'] ?? []), [$mid]))), -20);
    }
}

/** A lead's stored Message-IDs plus one more (the lead found by id, else by email), for passing to pm_lead_mark which overwrites fields. */
function pm_lead_msgids_merged(string $leadId, string $email, string $mid): array
{
    $snap = $GLOBALS['PM_LEADS_SNAP'] ?? null; // reading must not disturb a caller's own load/save cycle
    $leads = pm_leads();
    if ($snap === null) {
        unset($GLOBALS['PM_LEADS_SNAP']);
    } else {
        $GLOBALS['PM_LEADS_SNAP'] = $snap;
    }
    $l = $leads[$leadId] ?? null;
    if ($l === null && $email !== '') {
        foreach ($leads as $x) {
            if (strcasecmp((string)($x['email'] ?? ''), $email) === 0) {
                $l = $x;
                break;
            }
        }
    }
    $tmp = ['msg_ids' => (array)($l['msg_ids'] ?? [])];
    pm_lead_add_msgid($tmp, $mid);
    return $tmp['msg_ids'];
}

/** We have just answered them (email, WhatsApp or auto): the ball is in their court again. */
function pm_reply_sent(array &$lead, string $text, string $ch = 'email', string $msgid = ''): void
{
    $now = date('Y-m-d H:i');
    $lead['thread'][] = ['dir' => 'out', 'at' => $now, 'text' => mb_substr($text, 0, 3000), 'ch' => $ch === 'wa' ? 'wa' : 'email'];
    $lead['last_out'] = $now;
    unset($lead['awaiting_reply_since']);
    $lead['reply_draft'] = null;
    if (($lead['status'] ?? '') === 'replied') {
        $lead['status'] = 'contacted';
        $lead['followups'] = 0;
        $lead['last_contacted'] = $now;
    }
    pm_lead_add_msgid($lead, $msgid);
}

/* ---------------- Funnel marks (proposal sent / opened / signed) ---------------- */

/** The lead a funnel event belongs to: by id, else email (or an attached address), else exact business name. Null if none. */
function pm_funnel_find(array $leads, string $leadId, string $email, string $business): ?string
{
    if (isset($leads[$leadId])) {
        return $leadId;
    }
    $email = strtolower(trim($email));
    if ($email !== '') {
        foreach ($leads as $k => $l) {
            if (strtolower((string)($l['email'] ?? '')) === $email || in_array($email, array_map('strtolower', (array)($l['alt_emails'] ?? [])), true)) {
                return (string)$k;
            }
        }
    }
    if (trim($business) !== '') {
        foreach ($leads as $k => $l) {
            if (strcasecmp(trim((string)$l['name']), trim($business)) === 0) {
                return (string)$k;
            }
        }
    }
    return null;
}

/**
 * Advance a lead through the funnel without ever moving it backwards. Uses P3's pm_lead_mark when it exists, else a local version.
 * $status '' = only merge $extra. A signature ('won') also reopens a lead that had been marked lost. Never throws. Returns whether a lead was found.
 */
function pm_funnel_mark(string $leadId, string $email, string $business, string $status, array $extra = []): bool
{
    $snap = $GLOBALS['PM_LEADS_SNAP'] ?? null; // so the caller's own load/save cycle is not disturbed
    $restore = function () use ($snap) {
        if ($snap === null) {
            unset($GLOBALS['PM_LEADS_SNAP']);
        } else {
            $GLOBALS['PM_LEADS_SNAP'] = $snap;
        }
    };
    try {
        $leads = pm_leads();
        $id = pm_funnel_find($leads, $leadId, $email, $business);
        if ($id !== null && $status === 'won' && ($leads[$id]['status'] ?? '') === 'lost') {
            $leads[$id]['status'] = 'proposal'; // they signed: a lost lead is live again
            unset($leads[$id]['lost_reason']);
            pm_lead_note($leads[$id], 'Reopened: they signed');
            pm_leads_save($leads);
        }
        if (function_exists('pm_lead_mark')) {
            $ok = (bool)pm_lead_mark($leadId, $email, $business, $status, $extra);
            $restore();
            return $ok;
        }
        if ($id === null) {
            $restore();
            return false;
        }
        $rank = ['new' => 0, 'qualified' => 0, 'drafted' => 0, 'contacted' => 1, 'replied' => 2, 'proposal' => 3, 'won' => 4];
        $cur = (string)($leads[$id]['status'] ?? 'new');
        if ($cur === 'optout') {
            $restore();
            return false; // never touched
        }
        if ($status !== '' && isset($rank[$status]) && $cur !== 'lost' && ($rank[$status] > ($rank[$cur] ?? 0))) {
            $leads[$id]['status'] = $status;
            pm_lead_note($leads[$id], 'Marked ' . strtolower(PM_LEAD_STATUSES[$status] ?? $status));
            if ($status === 'won' && empty($leads[$id]['won_at'])) {
                $leads[$id]['won_at'] = date('Y-m-d H:i');
            }
        }
        foreach ($extra as $k => $v) {
            if (!in_array($k, ['id', 'brand', 'status'], true)) {
                $leads[$id][$k] = $v;
            }
        }
        $leads[$id]['updated'] = date('Y-m-d H:i');
        pm_leads_save($leads);
        $restore();
        return true;
    } catch (Throwable $e) {
        $restore();
        return false;
    }
}

/* ---------------- Mail reading (IMAP) ---------------- */

/** Where to read replies for a brand: IMAP_* (or its SMTP login) for ProManaged IT, TM_IMAP_* (or TM_SMTP_*) for Travel Malawi. */
function pm_imap_settings(string $brand = 'promanaged'): array
{
    $e = pm_env();
    if (pm_brand_is_custom($brand)) { // an added business: its own .env keys, else the mailbox typed in its settings
        $p = pm_brand_env_prefix($brand);
        $sm = (array)pm_brand_setting($brand, 'smtp', []);
        $im = (array)pm_brand_setting($brand, 'imap', []);
        return [
            'host' => $e[$p . 'IMAP_HOST'] ?? $e[$p . 'SMTP_HOST'] ?? (trim((string)($im['host'] ?? '')) ?: (string)($sm['host'] ?? '')),
            'port' => (int)($e[$p . 'IMAP_PORT'] ?? ($im['port'] ?? 993)), 'secure' => strtolower($e[$p . 'IMAP_SECURE'] ?? ($im['secure'] ?? 'ssl')),
            'user' => $e[$p . 'IMAP_USER'] ?? $e[$p . 'SMTP_USER'] ?? (string)($sm['username'] ?? ''), 'pass' => $e[$p . 'IMAP_PASS'] ?? $e[$p . 'SMTP_PASS'] ?? (string)($sm['password'] ?? ''),
        ];
    }
    $p = $brand === 'travel' && pm_tm_smtp_from_env() ? 'TM_' : '';
    return [
        'host' => $e[$p . 'IMAP_HOST'] ?? $e[$p . 'SMTP_HOST'] ?? '', 'port' => (int)($e[$p . 'IMAP_PORT'] ?? 993), 'secure' => strtolower($e[$p . 'IMAP_SECURE'] ?? 'ssl'),
        'user' => $e[$p . 'IMAP_USER'] ?? $e[$p . 'SMTP_USER'] ?? '', 'pass' => $e[$p . 'IMAP_PASS'] ?? $e[$p . 'SMTP_PASS'] ?? '',
    ];
}

/** The distinct mailboxes to read: one per brand, and only once if both brands share the same account. */
function pm_imap_boxes(): array
{
    $boxes = [];
    foreach (pm_brand_ids() as $b) {
        $c = pm_imap_settings($b);
        if ($c['host'] !== '' && $c['user'] !== '' && $c['pass'] !== '') {
            $boxes[strtolower($c['host'] . '|' . $c['user'])] = $c;
        }
    }
    return array_values($boxes);
}

function pm_imap_ready(): bool
{
    return (bool)pm_imap_boxes();
}

/** Send one IMAP command and return its full response (handles {n} literals). */
function pm_imap_cmd($fp, string $tag, string $cmd): string
{
    fwrite($fp, "$tag $cmd\r\n");
    $resp = '';
    while (($line = fgets($fp, 65536)) !== false) {
        $resp .= $line;
        if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
            $need = (int)$m[1];
            while ($need > 0 && ($chunk = fread($fp, min(65536, $need))) !== false && $chunk !== '') {
                $resp .= $chunk;
                $need -= strlen($chunk);
            }
            continue;
        }
        if (str_starts_with($line, "$tag ")) {
            break;
        }
    }
    return $resp;
}

/**
 * Recent messages from the inbox: [{uid, message_id, from_email, from_name, subject, text, auto}].
 * Messages are only peeked, never marked read or deleted.
 */
function pm_imap_recent(array $c, int $days = 14, int $max = 60): array
{
    $scheme = $c['secure'] === 'none' ? 'tcp' : 'ssl';
    $verify = strtolower(pm_env()['IMAP_VERIFY'] ?? 'on') !== 'off'; // only turn off if PHP has no CA bundle and you accept the risk
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify]]);
    $fp = @stream_socket_client("$scheme://{$c['host']}:{$c['port']}", $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Could not reach the mail server {$c['host']}: $es" . (str_contains((string)$es, 'certificate') || str_contains((string)$es, 'SSL') ? ' (If PHP has no certificate bundle, set openssl.cafile in php.ini, or IMAP_VERIFY=off in .env to accept the risk.)' : ''));
    }
    stream_set_timeout($fp, 30);
    fgets($fp, 4096); // greeting
    $q = fn($v) => '"' . addcslashes($v, '"\\') . '"';
    if (!str_contains(pm_imap_cmd($fp, 'a1', 'LOGIN ' . $q($c['user']) . ' ' . $q($c['pass'])), 'a1 OK')) {
        fclose($fp);
        throw new RuntimeException('The mail server refused the login. Check IMAP_USER and IMAP_PASS (or SMTP_USER and SMTP_PASS) in .env.');
    }
    pm_imap_cmd($fp, 'a2', 'SELECT INBOX');
    $search = pm_imap_cmd($fp, 'a3', 'UID SEARCH SINCE ' . date('d-M-Y', strtotime("-$days days")));
    preg_match('/\* SEARCH([\d ]*)/', $search, $m);
    $uids = array_slice(array_filter(explode(' ', trim($m[1] ?? ''))), -$max);
    $out = [];
    foreach ($uids as $n => $uid) {
        $raw = pm_imap_cmd($fp, 'f' . $n, "UID FETCH $uid (BODY.PEEK[]<0.80000>)");
        $a = strpos($raw, "\n");
        $msg = substr($raw, $a === false ? 0 : $a + 1);
        $msg = preg_replace('/\r?\n\)\r?\nf' . $n . ' OK.*$/s', '', $msg);
        $out[] = ['uid' => (int)$uid] + pm_parse_mail($msg);
    }
    pm_imap_cmd($fp, 'z', 'LOGOUT');
    fclose($fp);
    return $out;
}

/** Parse a raw email into sender, subject, plain text (quoted history removed), threading ids, an automatic-reply flag and bounce (DSN) details. */
function pm_parse_mail(string $raw): array
{
    [$head, $body] = array_pad(preg_split('/\r?\n\r?\n/', $raw, 2), 2, '');
    $h = fn(string $k) => preg_match('/^' . preg_quote($k, '/') . ':\s*(.*(?:\r?\n[ \t].*)*)/mi', $head, $m) ? trim(preg_replace('/\r?\n[ \t]+/', ' ', $m[1])) : '';
    $from = mb_decode_mimeheader($h('From'));
    preg_match('/<([^>]+)>/', $from, $fm);
    $email = strtolower(trim($fm[1] ?? preg_replace('/[^\w.@+-]/', '', $from)));
    $text = pm_mime_text($head, $body);
    $text = preg_split('/\r?\n(?:On .{5,120} wrote:|-{2,}\s*Original Message|From:\s.+\r?\nSent:|_{5,})/is', $text)[0];
    $text = trim(implode("\n", array_filter(explode("\n", $text), fn($l) => !str_starts_with(ltrim($l), '>'))));
    $auto = preg_match('/^(auto-submitted:\s*(?!no)|x-autoreply|x-autorespond|precedence:\s*(bulk|auto_reply|junk))/mi', $head)
        || preg_match('/mailer-daemon|postmaster|no-?reply/i', $email);
    $subject = mb_decode_mimeheader($h('Subject'));
    preg_match_all('/<[^>\s]+>/', $h('In-Reply-To') . ' ' . $h('References'), $ids);
    return ['message_id' => $h('Message-ID') ?: md5($raw), 'from_email' => $email, 'from_name' => trim(preg_replace('/<.*>/', '', $from), " \"'"),
        'subject' => $subject, 'text' => $text, 'auto' => (bool)$auto,
        'bulk' => (bool)preg_match('/^(list-unsubscribe|list-id):/mi', $head), 'in_reply_to' => array_values(array_unique($ids[0]))]
        + pm_dsn_info($raw, $head, $from . ' ' . $email, $subject, $text);
}

/** Is this a delivery-status (bounce) notice? Returns [] or ['dsn'=>true,'dsn_kind'=>'hard'|'soft'|'ok','failed','failed_all','orig_ids']. */
function pm_dsn_info(string $raw, string $head, string $from, string $subject, string $text): array
{
    $report = (bool)preg_match('/^Content-Type:\s*multipart\/report/mi', $head);
    $daemon = (bool)preg_match('/mailer-daemon|postmaster/i', $from);
    $subj = (bool)preg_match('/undeliver|delivery status|delivery (has )?failed|delivery failure|failure notice|returned mail|could not be delivered|mail delivery/i', $subject);
    $evidence = (bool)preg_match('/^(Final-Recipient|X-Failed-Recipients|Diagnostic-Code):|^Status:\s*[245]\.\d/mi', $raw);
    if (!($report || $daemon || ($subj && $evidence))) {
        return [];
    }
    $rx = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';
    $c = [];
    if (preg_match_all('/^X-Failed-Recipients:\s*(.+)$/mi', $raw, $m)) {
        preg_match_all($rx, implode(' ', $m[1]), $mm);
        $c = array_merge($c, $mm[0]);
    }
    if (preg_match_all('/^(?:Final|Original)-Recipient:\s*(?:rfc822;)?\s*<?([^\s<>;]+@[^\s<>;]+)/mi', $raw, $m)) {
        $c = array_merge($c, $m[1]);
    }
    preg_match_all($rx, $text, $mm);
    $c = array_merge($c, $mm[0]);
    $c = array_values(array_unique(array_filter(array_map(fn($a) => strtolower(rtrim(trim($a), '.>')), $c), fn($a) => $a !== '' && !preg_match('/^(mailer-daemon|postmaster)@/', $a)
        && !str_contains($from, $a))));
    $kind = 'hard';
    if (preg_match('/^Status:\s*([245])\.\d+\.\d+/mi', $raw, $sm)) {
        $kind = ['2' => 'ok', '4' => 'soft', '5' => 'hard'][$sm[1]];
    } elseif (preg_match('/mailbox (is )?full|over quota|quota exceeded|temporar|try again later|deferred|greylist/i', mb_substr($raw, 0, 20000))) {
        $kind = 'soft';
    }
    preg_match_all('/^Message-ID:\s*(<[^>\s]+>)/mi', $raw, $oi);
    return ['dsn' => true, 'dsn_kind' => $kind, 'failed' => $c[0] ?? '', 'failed_all' => array_slice($c, 0, 8), 'orig_ids' => array_values(array_unique($oi[1]))];
}

function pm_mime_text(string $head, string $body): string
{
    $ct = preg_match('/^Content-Type:\s*([^;\r\n]+)(.*(?:\r?\n[ \t].*)*)/mi', $head, $m) ? strtolower(trim($m[1])) : 'text/plain';
    $params = $m[2] ?? '';
    if (str_starts_with($ct, 'multipart/') && preg_match('/boundary="?([^";\r\n]+)"?/i', $params, $b)) {
        $plain = $html = '';
        foreach (preg_split('/\r?\n--' . preg_quote($b[1], '/') . '(?:--)?/', $body) as $part) {
            [$ph, $pb] = array_pad(preg_split('/\r?\n\r?\n/', ltrim($part, "\r\n"), 2), 2, '');
            $t = pm_mime_text($ph, $pb);
            if (preg_match('/^Content-Type:\s*text\/html/mi', $ph)) {
                $html = $html ?: $t;
            } elseif (!preg_match('/^Content-Disposition:\s*attachment/mi', $ph) && $t !== '') {
                $plain = $plain ?: $t;
            }
        }
        return $plain !== '' ? $plain : $html;
    }
    if (!str_starts_with($ct, 'text/')) {
        return '';
    }
    $enc = preg_match('/^Content-Transfer-Encoding:\s*(\S+)/mi', $head, $e) ? strtolower($e[1]) : '7bit';
    $body = match ($enc) { 'base64' => (string)base64_decode($body), 'quoted-printable' => quoted_printable_decode($body), default => $body };
    $cs = preg_match('/charset="?([\w-]+)"?/i', $params, $c) ? $c[1] : 'UTF-8';
    if (strcasecmp($cs, 'utf-8') !== 0) {
        $body = @mb_convert_encoding($body, 'UTF-8', $cs) ?: $body;
    }
    if ($ct === 'text/html') {
        $body = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/tr)\b[^>]*>#i', "\n", preg_replace('#<(style|script).*?</\1>#is', '', $body))), ENT_QUOTES, 'UTF-8');
    }
    return trim($body);
}

/* ---------------- Processing replies ---------------- */

function pm_msgid_norm(string $s): string
{
    return strtolower(trim($s, " \t<>"));
}

/** A subject without Re:/Fwd: prefixes, lower case, for comparing with the subjects we wrote. */
function pm_subject_key(string $s): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/^(\s*(re|fwd?|aw|sv)\s*:\s*)+/i', '', $s))));
}

/**
 * The lead a message belongs to. Order: our Message-IDs in In-Reply-To/References, exact email (also addresses attached by hand),
 * the failed recipient of a bounce notice, the same company domain (not free mail), then "Re:" plus a subject we drafted (only if unique).
 * $m is the parsed message (see pm_parse_mail); without it only the sender address is used.
 */
function pm_match_lead(array $leads, string $email, array $m = []): ?string
{
    $email = strtolower($email);
    $dsn = !empty($m['dsn']);
    $ids = array_filter(array_unique(array_map('pm_msgid_norm', array_merge((array)($m['in_reply_to'] ?? []), (array)($m['orig_ids'] ?? [])))));
    if ($ids) {
        foreach ($leads as $id => $l) {
            foreach ((array)($l['msg_ids'] ?? []) as $mid) {
                if (in_array(pm_msgid_norm((string)$mid), $ids, true)) {
                    return $id;
                }
            }
        }
    }
    $contacted = fn($l) => !empty($l['sent']) || !empty($l['last_contacted']) || !empty($l['wa_sent']);
    if ($dsn) { // the sender is the mail system: look at who it could not reach
        foreach ((array)($m['failed_all'] ?? []) as $cand) {
            foreach ($leads as $id => $l) {
                if (strtolower((string)($l['email'] ?? '')) === $cand || strtolower((string)($l['email_bad'] ?? '')) === $cand) {
                    return $id;
                }
            }
        }
        return null;
    }
    $d = pm_email_domain($email);
    $domainHit = null;
    $best = '';
    foreach ($leads as $id => $l) {
        if (!$contacted($l)) {
            continue; // only people we have written to
        }
        $le = strtolower((string)($l['email'] ?? ''));
        if ($email !== '' && ($le === $email || in_array($email, array_map('strtolower', (array)($l['alt_emails'] ?? [])), true))) {
            return $id;
        }
        if ($d !== '' && !in_array($d, PM_FREE_MAIL, true) && pm_email_domain($le) === $d && (string)($l['last_contacted'] ?? '') >= $best) {
            $domainHit = $id; // several people at one company: the one we wrote to most recently
            $best = (string)($l['last_contacted'] ?? '');
        }
    }
    if ($domainHit !== null) {
        return $domainHit;
    }
    $subj = (string)($m['subject'] ?? '');
    if (preg_match('/^\s*re\s*:/i', $subj)) {
        $key = pm_subject_key($subj);
        $hits = [];
        foreach ($leads as $id => $l) {
            if (!$contacted($l)) {
                continue;
            }
            foreach ([$l['drafts']['email_subject'] ?? '', $l['followup_draft']['email_subject'] ?? '', $l['reply_draft']['subject'] ?? ''] as $ds) {
                if ($key !== '' && pm_subject_key((string)$ds) === $key) {
                    $hits[$id] = 1;
                }
            }
        }
        if (count($hits) === 1) {
            return (string)array_key_first($hits);
        }
    }
    return null;
}

/**
 * Apply the Reply agent's decision to a lead. $send is a callback(array $lead, string $subject, string $body): array [ok, error, msgid?]
 * used only in auto mode. $ch is where they wrote ('email'|'wa'). Returns a one-line description of what happened.
 * States: STOP always wins (optout). An optout lead stays optout and gets no draft unless it explicitly asks to resume. won/proposal/lost keep
 * their status (a decline after a proposal moves it to lost) and are marked awaiting a reply. not_now/out-of-office only snooze.
 */
function pm_apply_reply(array &$lead, string $text, array $r, string $mode, ?callable $send, string $ch = 'email'): string
{
    $now = date('Y-m-d H:i');
    $ch = $ch === 'wa' ? 'wa' : 'email';
    $status = (string)($lead['status'] ?? '');
    $intent = pm_looks_like_stop($text) ? 'stop' : (string)($r['intent'] ?? 'other');
    if (!in_array($intent, PM_REPLY_INTENTS, true)) {
        $intent = 'other';
    }
    if ($intent !== 'bounce') {
        $lead['thread'][] = ['dir' => 'in', 'at' => $now, 'text' => mb_substr($text, 0, 3000), 'ch' => $ch];
        pm_lead_note($lead, 'Reply (' . str_replace('_', ' ', $intent) . '): ' . ($r['summary'] ?? ''));
    }
    $lead['reply_intent'] = $intent;
    if (in_array($intent, PM_HUMAN_INTENTS, true)) {
        $lead['last_reply'] = $now; // bounce, out of office and stop are not replies
    }
    unset($lead['followup_draft']);

    if ($intent === 'stop') {
        if ($status !== 'optout') {
            $lead['prev_status'] = $status;
        }
        $lead['status'] = 'optout';
        $lead['optout_at'] = $lead['optout_at'] ?? $now;
        $lead['reply_draft'] = null;
        unset($lead['awaiting_reply_since'], $lead['snooze_until'], $lead['want_proposal']);
        return 'asked us to stop; marked do not contact';
    }
    if ($status === 'optout') {
        if ($intent === 'bounce' || !pm_wants_resume($text)) {
            $lead['reply_draft'] = null;
            return 'do-not-contact lead wrote again; logged only';
        }
        $lead['status'] = $status = 'replied'; // they explicitly asked to hear from us again
        unset($lead['optout_at']);
        pm_lead_note($lead, 'Asked to be contacted again');
    }
    switch ($intent) {
        case 'bounce':
            pm_mark_bounced($lead, (string)($r['failed'] ?? ''), $r['hard'] ?? true);
            return !empty($lead['email_bad']) && ($r['hard'] ?? true) ? 'email bounced; address cleared' : 'temporary delivery problem';
        case 'out_of_office':
            $lead['snooze_until'] = date('Y-m-d', strtotime('+7 days'));
            return 'automatic reply; paused 7 days';
        case 'not_now':
            $lead['snooze_until'] = pm_resume_date((string)($r['resume_on'] ?? ''), 30); // cadence is not bumped: no followups / last_contacted change
            $lead['reply_draft'] = null;
            unset($lead['awaiting_reply_since']);
            return 'not now; will be picked up again on ' . $lead['snooze_until'];
        case 'not_interested':
            $lead['reply_draft'] = null;
            unset($lead['awaiting_reply_since'], $lead['want_proposal'], $lead['snooze_until']);
            if ($status === 'won') {
                $lead['awaiting_reply_since'] = $now; // a customer: the owner should read it
                return 'not interested (customer; status kept, please read it)';
            }
            $lead['status'] = 'lost';
            $lead['lost_reason'] = $status === 'lost' && !empty($lead['lost_reason']) ? $lead['lost_reason'] : 'not_interested';
            return 'not interested';
    }
    $keep = in_array($status, ['won', 'proposal', 'lost'], true);
    if (!$keep) {
        $lead['status'] = 'replied';
    }
    if (empty($lead['awaiting_reply_since'])) {
        $lead['awaiting_reply_since'] = $now;
    }
    unset($lead['snooze_until']); // they wrote: any pause is over
    if (in_array($intent, ['interested', 'question', 'meeting_request'], true)) {
        $lead['want_proposal'] = true;
    }
    $subject = trim((string)($r['reply_subject'] ?? '')) ?: 'Re: ' . ($lead['drafts']['email_subject'] ?? 'your reply');
    $lead['reply_draft'] = ['subject' => $subject, 'body' => trim((string)($r['reply_body'] ?? '')), 'whatsapp' => pm_wa_reply_clean((string)($r['whatsapp'] ?? '')),
        'needs_human' => !empty($r['needs_human']), 'next_step' => (string)($r['next_step'] ?? ''), 'ch' => $ch];
    // Auto mode answers only plain questions and interest by email. A call or meeting time is never committed without the owner.
    if ($mode === 'auto' && $send && $ch === 'email' && !$keep && empty($r['needs_human']) && in_array($intent, ['interested', 'question'], true) && $lead['reply_draft']['body'] !== ''
        && !pm_outreach_lint($subject, $lead['reply_draft']['body'], false) && filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $res = $send($lead, $subject, $lead['reply_draft']['body']);
        if (!empty($res[0])) {
            $body = $lead['reply_draft']['body'];
            pm_reply_sent($lead, $body, 'email', (string)($res[2] ?? ''));
            pm_lead_note($lead, 'Auto-replied');
            return "$intent; replied automatically";
        }
    }
    return "$intent; reply drafted for you";
}

/**
 * Classify (no model for STOP, bounces, automatic out-of-office or a do-not-contact lead) and apply one incoming message.
 * Returns [what happened, result array]. A reached daily AI budget never loses the message: it is logged for the owner.
 */
function pm_handle_incoming(array &$lead, string $text, string $ch = 'email', array $m = [], string $mode = 'draft', ?callable $send = null, ?callable $classify = null): array
{
    $r = pm_reply_precheck($lead, $text, $m);
    if ($r === null) {
        try {
            $r = ($classify ?? 'pm_agent_reply')($lead, $text, $ch);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'budget') === false) {
                throw $e;
            }
            $r = pm_reply_fallback('Daily AI budget reached: read it and reply yourself.');
        }
    }
    return [pm_apply_reply($lead, $text, $r, $mode, $send, $ch), $r];
}

/** Every address that is ours (messages from these are our own copies, not replies). */
function pm_own_addresses(): array
{
    $own = [];
    $was = pm_brand();
    foreach (pm_brand_ids() as $b) {
        pm_brand_set($b);
        $s = pm_settings();
        foreach ([$s['smtp']['from_email'] ?? '', $s['smtp']['username'] ?? '', $s['email'] ?? ''] as $a) {
            $own[] = strtolower(trim((string)$a));
        }
    }
    pm_brand_set($was);
    $e = pm_env();
    $ownKeys = [];
    foreach (pm_brand_ids() as $ob) {
        foreach (['SMTP_USER', 'SMTP_FROM', 'IMAP_USER'] as $ok) {
            $ownKeys[] = pm_brand_env_prefix($ob) . $ok;
        }
    }
    foreach ($ownKeys as $k) {
        $own[] = strtolower(trim((string)($e[$k] ?? '')));
    }
    return array_values(array_filter(array_unique($own)));
}

/* ---- Replies we could not match to a lead: kept for the owner, never dropped ---- */

function pm_inbox_unmatched_update(callable $fn): mixed
{
    $lock = @fopen(PM_DATA . '/inbox_unmatched.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        $list = pm_load('inbox_unmatched', fn() => []);
        $res = $fn($list);
        pm_save('inbox_unmatched', array_slice(array_values($list), 0, 50));
        return $res;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** The saved unmatched messages, newest first (reads only; creates nothing). */
function pm_inbox_unmatched_list(): array
{
    $f = PM_DATA . '/inbox_unmatched.json';
    $l = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($l) ? array_values($l) : [];
}

function pm_inbox_unmatched_add(array $m): void
{
    $uid = md5((string)$m['message_id']);
    pm_inbox_unmatched_update(function (array &$list) use ($m, $uid) {
        foreach ($list as $it) {
            if (($it['uid'] ?? '') === $uid) {
                return;
            }
        }
        array_unshift($list, ['uid' => $uid, 'from' => (string)$m['from_email'], 'name' => (string)($m['from_name'] ?? ''), 'subject' => mb_substr((string)$m['subject'], 0, 200),
            'snippet' => mb_substr(preg_replace('/\s+/', ' ', (string)$m['text']), 0, 300), 'text' => mb_substr((string)$m['text'], 0, 3000), 'at' => date('Y-m-d H:i'), 'msgid' => (string)$m['message_id']]);
    });
}

/** Remove one unmatched message (by its uid) and return it, or null. */
function pm_inbox_unmatched_take(string $uid): ?array
{
    return pm_inbox_unmatched_update(function (array &$list) use ($uid) {
        foreach ($list as $i => $it) {
            if (($it['uid'] ?? '') === $uid) {
                unset($list[$i]);
                return $it;
            }
        }
        return null;
    });
}

/**
 * Handle already-fetched messages against $leads. $report collects what the owner may need to hear about.
 * Returns the number of replies applied.
 */
function pm_inbox_process(array $messages, array &$leads, array &$seen, string $mode, ?callable $send, array &$report, ?callable $classify = null): int
{
    $own = pm_own_addresses();
    $handled = 0;
    foreach ($messages as $m) {
        $mid = (string)$m['message_id'];
        if (in_array($mid, $seen, true)) {
            continue;
        }
        $seen[] = $mid;
        if (in_array($m['from_email'], $own, true)) {
            continue; // our own copy of something we sent
        }
        $dsn = !empty($m['dsn']);
        $id = pm_match_lead($leads, $m['from_email'], $m);
        if ($id === null) {
            if (!$dsn && empty($m['auto']) && empty($m['bulk']) && trim((string)$m['text']) !== '') {
                pm_inbox_unmatched_add($m);
                $report['unmatched'][] = ['from' => $m['from_email'], 'subject' => (string)$m['subject']];
                pm_agent_log('Reply', 'Unmatched reply from ' . $m['from_email'] . ' kept for you to attach to a lead');
            }
            continue;
        }
        if ($dsn && ($m['dsn_kind'] ?? '') === 'ok') {
            continue; // a delivery receipt, nothing to do
        }
        if (!$dsn && trim((string)$m['text']) === '') {
            continue;
        }
        if (!$dsn && !empty($m['auto']) && !preg_match('/out of office|auto.?reply|automatic reply|vacation|away from (the )?office/i', $m['subject'] . ' ' . $m['text'])) {
            pm_lead_note($leads[$id], 'Automatic message received (ignored)');
            continue;
        }
        pm_brand_set((string)($leads[$id]['brand'] ?? 'promanaged')); // answer as the brand the lead was approached by
        $prevIn = $leads[$id]['last_in_id'] ?? null;
        if (!$dsn && strlen($mid) < 200) {
            $leads[$id]['last_in_id'] = $mid; // an automatic answer threads under this message
        }
        try {
            [$what, $r] = pm_handle_incoming($leads[$id], $dsn ? 'Delivery failure notice' : (string)$m['text'], 'email', $m, $mode, $send, $classify);
        } catch (Throwable $e) {
            pm_agent_log('Reply', $leads[$id]['name'] . ': ' . $e->getMessage());
            array_pop($seen); // try again next time
            if ($prevIn === null) {
                unset($leads[$id]['last_in_id']);
            } else {
                $leads[$id]['last_in_id'] = $prevIn;
            }
            continue;
        }
        if (!$dsn && empty($m['auto']) && strtolower((string)($leads[$id]['email'] ?? '')) !== $m['from_email'] && $m['from_email'] !== '' && !pm_looks_like_stop((string)$m['text'])
            && !in_array($m['from_email'], array_map('strtolower', (array)($leads[$id]['alt_emails'] ?? [])), true)) {
            $leads[$id]['alt_emails'] = array_slice(array_merge((array)($leads[$id]['alt_emails'] ?? []), [$m['from_email']]), -5); // same company, another person: later mail matches
        }
        pm_agent_log('Reply', $leads[$id]['name'] . ': ' . $what);
        $handled++;
        $item = ['id' => $id, 'name' => (string)$leads[$id]['name'], 'brand' => (string)($leads[$id]['brand'] ?? 'promanaged'), 'intent' => (string)$r['intent'], 'summary' => (string)($r['summary'] ?? ''),
            'needs_human' => !empty($r['needs_human']), 'what' => $what, 'status' => (string)($leads[$id]['status'] ?? '')];
        $report[$r['intent'] === 'bounce' ? 'bounces' : 'replies'][] = $item;
    }
    return $handled;
}

/** Checks the inbox, classifies replies from known leads and updates them. Returns [count handled, message]. $report gets the details. */
function pm_inbox_poll(?callable $send = null, ?array &$report = null): array
{
    $report = ['replies' => [], 'bounces' => [], 'unmatched' => []];
    if (!pm_imap_ready()) {
        return [0, 'Mail reading is not set up (IMAP_HOST, IMAP_USER, IMAP_PASS in .env).'];
    }
    $seen = pm_load('inbox_seen', fn() => []);
    $leads = pm_leads();
    $mode = pm_agents_config('promanaged')['reply_mode'] ?? 'draft';
    $was = pm_brand();
    $messages = [];
    foreach (pm_imap_boxes() as $box) { // each brand's own inbox (read once when they share an account)
        try {
            $messages = array_merge($messages, pm_imap_recent($box));
        } catch (Throwable $e) {
            pm_agent_log('Reply', 'Inbox check failed for ' . $box['user'] . ': ' . $e->getMessage());
        }
    }
    $handled = pm_inbox_process($messages, $leads, $seen, $mode, $send, $report);
    pm_brand_set($was);
    pm_leads_save($leads);
    pm_save('inbox_seen', array_slice($seen, -600));
    $extra = count($report['unmatched']) ? ' ' . count($report['unmatched']) . ' could not be matched to a lead: see "Unmatched replies".' : '';
    return [$handled, ($handled ? "$handled repl" . ($handled === 1 ? 'y' : 'ies') . ' handled.' : 'No new replies.') . $extra];
}

/** Send an answer to a lead. Returns [ok, error, msgid]. Replies are not first contact, so they do not use the first-email limits. */
function pm_send_reply(array $lead, string $subject, string $body): array
{
    $s = pm_settings();
    if (($lead['status'] ?? '') === 'optout' || pm_suppressed(pm_leads(), (string)($lead['email'] ?? ''), (string)($lead['id'] ?? ''))) {
        return [false, 'That address asked not to be contacted.', ''];
    }
    $sig = array_filter([$s['signatory_name'] ?? '', ($s['signatory_name'] ?? '') !== '' ? ($s['signatory_title'] ?? '') : '', $s['company_name'], $s['phone'] ?? '', $s['website'] ?? '']);
    $from = $s['smtp']['from_email'] ?: ($s['email'] ?? '');
    $hdr = ['List-Unsubscribe' => '<mailto:' . $from . '?subject=STOP>'];
    if (!empty($lead['last_in_id'])) { // threads under their message
        $hdr['In-Reply-To'] = $hdr['References'] = (string)$lead['last_in_id'];
    }
    return pm_mail($s, ['to' => $lead['email'], 'subject' => $subject, 'body' => $body . "

Kind regards,
" . implode("
", $sig) . "

(Reply STOP at any time and we will stop contacting you.)",
        'headers' => $hdr]);
}
