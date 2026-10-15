<?php
/**
 * Follow-ups with AI. A lead we wrote to that has gone quiet gets a follow-up written by the Follow-up agent (one new angle, never a repeat, no pressure), as a DRAFT
 * for the owner to read and send: nothing here sends anything. Besides the daily agent run, follow-ups can be drafted on demand (one lead, or all that are due, from the
 * Leads screen) and the scheduler drafts the due ones once each working morning, so a follow-up is waiting when the day starts.
 */

/**
 * Leads whose first email went quiet: contacted, under the follow-up limit, not snoozed, not left with only a dead email, and silent for the configured days.
 * $withDraft = false keeps only those with no follow-up drafted yet. $leads are one business's leads (any keys).
 */
function pm_followup_due(array $leads, array $cfg, bool $withDraft = true): array
{
    $out = [];
    foreach ($leads as $k => $l) {
        if (($l['status'] ?? '') !== 'contacted' || (int)($l['followups'] ?? 0) >= (int)($cfg['max_followups'] ?? 3) || pm_lead_snoozed($l) || pm_lead_email_dead($l) || !empty($l['listen'])) {
            continue;
        }
        $t = strtotime((string)($l['last_contacted'] ?? ''));
        if (!$t || time() - $t < (int)($cfg['followup_days'] ?? 4) * 86400 || (!$withDraft && !empty($l['followup_draft']))) {
            continue;
        }
        $out[$k] = $l;
    }
    return $out;
}

/* ---------------- prepared ahead by the Follow-up head ---------------- */

/** When we last wrote to them, in plain words, from the real date: "earlier today", "yesterday", "on Tuesday", "last week", "a few weeks ago". */
function pm_followup_when(array $l, ?int $now = null): string
{
    $t = strtotime((string)($l['last_contacted'] ?? '')) ?: strtotime((string)end($l['sent'])) ?: 0;
    if (!$t) {
        return 'recently';
    }
    $now = $now ?? (function_exists('pm_now') ? pm_now() : time());
    $days = (int)floor((strtotime(date('Y-m-d', $now)) - strtotime(date('Y-m-d', $t))) / 86400); // calendar days, not hours: "yesterday" means the day before today
    return match (true) {
        $days <= 0 => 'earlier today',
        $days === 1 => 'yesterday',
        $days < 7 => 'on ' . date('l', $t),
        $days < 14 => 'last week',
        $days < 45 => 'a few weeks ago',
        default => 'a while ago',
    };
}

/**
 * The Follow-up head's check before anything goes: a follow-up is written ahead, so how long ago we wrote is set from the real date at the moment it is shown or sent
 * ("I wrote to you a few days ago" the day after becomes "I wrote to you yesterday"). Only that phrase changes.
 */
function pm_followup_timefix(string $text, array $l, ?int $now = null): string
{
    $when = pm_followup_when($l, $now);
    return (string)preg_replace_callback('/\b(wrote|reached out|got in touch|emailed|sent you (?:an|a short|my) (?:email|note|message))((?: to you| you)?)\s+(?:(?:a few|a couple of|several|two|three|four|five|\d+)\s+days ago|a little while ago|last week|earlier this week|the other day|recently|yesterday|earlier today|on (?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)|a few weeks ago|a while ago)/iu',
        fn($m) => $m[1] . $m[2] . ' ' . $when, $text);
}

/** Which follow-up the next one is for this lead: 1 for the first, 2 for the second... */
function pm_followup_nth(array $l): int
{
    return (int)($l['followups'] ?? 0) + 1;
}

/** The follow-up the Follow-up head prepared for this lead's next follow-up, or null (none, or written for an earlier number). */
function pm_followup_ready(array $l): ?array
{
    $r = (array)($l['followup_ready'] ?? []);
    return trim((string)($r['email_body'] ?? '')) !== '' && (int)($r['n'] ?? 0) === pm_followup_nth($l) ? $r : null;
}

/** A lead we wrote to that has not answered and may still get a follow-up (whatever the day): contacted, has a first email, under the limit, a working email, not a social-post lead. */
function pm_followup_open(array $l, array $cfg): bool
{
    return ($l['status'] ?? '') === 'contacted' && !empty($l['sent']) && pm_followup_nth($l) <= max(1, (int)($cfg['max_followups'] ?? 3)) && !pm_lead_email_dead($l) && empty($l['listen'])
        && !(function_exists('pm_lead_replied') && pm_lead_replied($l));
}

/**
 * The follow-up that goes when the owner presses Follow up: the draft on the lead (written or edited), else the one the Follow-up head prepared for this number,
 * else the default follow-up for this number (no AI, so a press never waits). ['email_subject','email_body','whatsapp', 'source'].
 */
function pm_followup_for_send(array $l): array
{
    if (trim((string)($l['followup_draft']['email_body'] ?? '')) !== '') {
        return ['email_body' => pm_followup_timefix((string)$l['followup_draft']['email_body'], $l), 'whatsapp' => pm_followup_timefix((string)($l['followup_draft']['whatsapp'] ?? ''), $l)] + (array)$l['followup_draft'];
    }
    if ($r = pm_followup_ready($l)) {
        return ['email_subject' => (string)$r['email_subject'], 'email_body' => pm_followup_timefix((string)$r['email_body'], $l), 'whatsapp' => pm_followup_timefix((string)($r['whatsapp'] ?? ''), $l), 'source' => 'prepared'];
    }
    $d = pm_dm_as((string)($l['brand'] ?? pm_brand()), fn() => pm_dm_followup_render($l));
    return ['email_body' => pm_followup_timefix($d['email_body'], $l), 'whatsapp' => pm_followup_timefix($d['whatsapp'], $l), 'source' => 'default'] + $d;
}

/**
 * The Follow-up head writes the next follow-up ahead of time for every lead that has not answered, so pressing Follow up (one lead or many ticked) sends at once with
 * nothing to wait for. It knows which number it is and words it for that (a gentle first nudge, a graceful last note). The AI only for what is not prepared yet, six to a
 * call, at most $max a pass; with no AI key, or a business that drafts from its default message, the default follow-up for that number. Returns how many were prepared.
 */
function pm_followup_prepare(string $brand, int $max = 18): int
{
    $cfg = pm_agents_config($brand);
    $todo = [];
    foreach (pm_leads_read() as $id => $l) {
        if (($l['brand'] ?? 'promanaged') === $brand && pm_followup_open($l, $cfg) && trim((string)($l['followup_draft']['email_body'] ?? '')) === '' && !pm_followup_ready($l)) {
            $todo[(string)$id] = ['id' => (string)$id] + $l;
        }
    }
    if (!$todo) {
        return 0;
    }
    $todo = array_slice($todo, 0, $max, true);
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $ready = [];
        foreach (array_chunk($todo, 6, true) as $chunk) {
            $out = [];
            if (!pm_followup_by_default($brand)) {
                try {
                    $out = pm_agent_followup(array_values($chunk), true);
                } catch (Throwable) {
                    $out = []; // the AI failed: the default for that number instead
                }
            }
            $by = [];
            foreach ($out as $w) {
                if (is_array($w) && is_scalar($w['id'] ?? null) && trim((string)($w['email_body'] ?? '')) !== '') {
                    $by[(string)$w['id']] = $w;
                }
            }
            foreach ($chunk as $id => $l) {
                $w = $by[$id] ?? null;
                if ($w && pm_outreach_lint((string)($w['email_subject'] ?? ''), (string)$w['email_body'], false)) {
                    $w = null; // too long or against a wording rule: it would be refused at the send, so the default for that number instead
                }
                $ready[$id] = ($w ? ['email_subject' => (string)($w['email_subject'] ?? ''), 'email_body' => (string)$w['email_body'], 'whatsapp' => (string)($w['whatsapp'] ?? ''), 'by' => 'ai']
                    : pm_dm_followup_render($l) + ['by' => 'default']) + ['n' => pm_followup_nth($l), 'at' => date('Y-m-d H:i')];
            }
        }
    } finally {
        pm_brand_set($was);
    }
    $n = 0;
    pm_update('leads', function (array $cur) use ($ready, &$n) {
        foreach ($ready as $id => $r) {
            if (isset($cur[$id]) && ($cur[$id]['status'] ?? '') === 'contacted' && pm_followup_nth($cur[$id]) === $r['n']) { // a reply or a send meanwhile: not stored
                $cur[$id]['followup_ready'] = $r;
                $n++;
            }
        }
        return $cur;
    }, fn() => []);
    $n && function_exists('pm_tb_post') && pm_tb_post($brand, 'followup', 'owner', "Follow-ups prepared for $n " . ($n === 1 ? 'lead' : 'leads') . ' that have not answered: tick them on Leads and press Follow up.', 'fu_prepared');
    return $n;
}

/**
 * What the Follow-up agent decided for one lead, applied: 'send' stores the follow-up draft (ready to send from the list), 'wait' pauses the lead until the day it chose,
 * 'stop' closes it as no response (Move to puts it back any time). Returns 'send', 'wait' or 'stop'.
 */
function pm_followup_apply(array &$lead, array $w, string $brand): string
{
    $d = strtolower(trim((string)($w['decision'] ?? 'send')));
    $why = mb_substr(trim(strip_tags((string)($w['why'] ?? ''))), 0, 200);
    if ($d === 'wait') {
        $days = max(2, min(21, (int)($w['wait_days'] ?? 5)));
        $lead['snooze_until'] = date('Y-m-d', strtotime("+$days days"));
        pm_lead_note($lead, 'Follow-up agent: wait until ' . $lead['snooze_until'] . ($why !== '' ? " ($why)" : ''));
        return 'wait';
    }
    if ($d === 'stop' && (int)($lead['followups'] ?? 0) >= 1 && function_exists('pm_lead_set_status')) {
        pm_lead_set_status($lead, 'lost', 'no_response');
        pm_lead_note($lead, 'Follow-up agent: stop following up' . ($why !== '' ? " ($why)" : '') . '. Move it back with Move to if you disagree.');
        return 'stop';
    }
    if (trim((string)($w['email_body'] ?? '')) === '') {
        return 'wait'; // nothing written: tried again at the next run
    }
    pm_followup_store($lead, $w, $brand);
    $why !== '' && pm_lead_note($lead, 'Follow-up agent: send now (' . $why . ')');
    return 'send';
}

/** The AI's follow-up for one lead stored on the lead: subject, email, WhatsApp, and the subject style it used (so the follow-up experiment can score it). */
function pm_followup_store(array &$lead, array $w, string $brand): void
{
    $lead['followup_draft'] = ['email_subject' => (string)($w['email_subject'] ?? ''), 'email_body' => (string)($w['email_body'] ?? ''), 'whatsapp' => (string)($w['whatsapp'] ?? '')];
    if (!empty($lead["rewrite_reason"])) { pm_lead_note($lead, "Follow-up rewritten after your feedback: " . $lead["rewrite_reason"]); unset($lead["rewrite_reason"]); }
    if (function_exists('pm_followup_arm')) {
        $lead['followup_arm'] = pm_followup_arm($lead, $brand);
    }
    pm_lead_note($lead, 'Follow-up drafted with AI');
}

/**
 * Drafts follow-ups with the AI now for the current business: the due ones with no draft yet (at most $max, six to a call), or only $ids when given.
 * A lead that was sent, replied or got a draft while this ran is left alone. Returns ['drafted' => n, 'failed' => n, 'due' => n].
 */
function pm_followup_draft_now(array $ids = [], int $max = 60): array
{
    $brand = pm_brand();
    $mine = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    $due = pm_followup_due($mine, pm_agents_config($brand), false);
    if ($ids) {
        $due = array_intersect_key($due, array_flip($ids));
    }
    $total = count($due);
    $drafted = 0;
    $failed = 0;
    $prep = array_filter($due, fn($l) => pm_followup_ready($l) !== null); // already written ahead by the Follow-up head: no AI asked again
    if ($prep) {
        pm_update('leads', function (array $cur) use ($prep, &$drafted) {
            foreach (array_keys($prep) as $id) {
                if (isset($cur[$id]) && empty($cur[$id]['followup_draft']) && ($r = pm_followup_ready($cur[$id]))) {
                    $cur[$id]['followup_draft'] = ['email_subject' => (string)$r['email_subject'], 'email_body' => (string)$r['email_body'], 'whatsapp' => (string)($r['whatsapp'] ?? ''), 'source' => 'prepared'];
                    unset($cur[$id]['followup_ready']);
                    pm_lead_note($cur[$id], 'Follow-up ' . (int)$r['n'] . ' ready (prepared ahead by the Follow-up head)');
                    $drafted++;
                }
            }
            return $cur;
        }, fn() => []);
        $due = array_diff_key($due, $prep);
    }
    $byDefault = pm_followup_by_default($brand);
    foreach (array_chunk(array_slice($due, 0, $max, true), 6, true) as $chunk) {
        $out = null;
        if (!$byDefault) {
            $batch = [];
            foreach ($chunk as $k => $l) {
                $batch[] = ['id' => (string)($l['id'] ?? $k)] + $l;
            }
            try {
                $out = pm_agent_followup($batch);
            } catch (Throwable) {
                $out = null; // the AI failed: the default follow-up instead, so nobody who has not replied is left without one
            }
        }
        if ($out === null) { // the default follow-up (Sales > Targeting and messages): no AI, ready at once
            pm_update('leads', function (array $cur) use ($chunk, &$drafted) {
                foreach (array_keys($chunk) as $id) {
                    if (isset($cur[$id]) && empty($cur[$id]['followup_draft']) && pm_dm_followup_apply($cur[$id])[0]) {
                        $drafted++;
                    }
                }
                return $cur;
            }, fn() => []);
            continue;
        }
        $by = [];
        foreach ($out as $w) {
            if (is_array($w) && isset($w['id']) && is_scalar($w['id']) && (trim((string)($w['email_body'] ?? '')) !== '' || in_array(strtolower((string)($w['decision'] ?? '')), ['wait', 'stop'], true))) {
                $by[(string)$w['id']] = $w;
            }
        }
        pm_update('leads', function (array $cur) use ($by, $brand, &$drafted) {
            foreach ($by as $id => $w) {
                if (!isset($cur[$id]) || ($cur[$id]['status'] ?? '') !== 'contacted' || !empty($cur[$id]['followup_draft'])) {
                    continue;
                }
                $drafted += pm_followup_apply($cur[$id], $w, $brand) === 'send' ? 1 : 0;
            }
            return $cur;
        }, fn() => []);
        $failed += count($batch) - count($by);
    }
    return ['drafted' => $drafted, 'failed' => $failed, 'due' => $total];
}

/** True when this business's follow-ups come from its default follow-up, not the AI: it drafts from its default message, or there is no AI key. */
function pm_followup_by_default(string $brand): bool
{
    return pm_dm_use_default($brand) || !(pm_agents_ready() || isset($GLOBALS['PM_AI_STUB']));
}

/** One lead, on demand, whenever the owner asks (it need not be due yet). Replaces an earlier follow-up draft. Returns [ok, message]. */
function pm_followup_draft_one(string $id): array
{
    $leads = pm_leads();
    $l = $leads[$id] ?? null;
    if (!$l) {
        return [false, 'That lead was not found.'];
    }
    if (($l['status'] ?? '') !== 'contacted') {
        return [false, 'A follow-up is for a lead we have already written to and who has not replied.'];
    }
    if (pm_lead_email_dead($l)) {
        return [false, 'This lead has no working email address, so there is nothing to follow up by email.'];
    }
    $brand = (string)($l['brand'] ?? 'promanaged');
    $was = pm_brand();
    pm_brand_set($brand);
    try {
        $w = pm_agent_followup([['id' => $id] + $l])[0] ?? null;
    } catch (Throwable $e) {
        return [false, 'The follow-up agent failed: ' . $e->getMessage()];
    } finally {
        pm_brand_set($was);
    }
    if (!is_array($w) || trim((string)($w['email_body'] ?? '')) === '') {
        return [false, 'The agent did not return a draft. Try again.'];
    }
    $w['decision'] = 'send'; // asked for by hand: the owner decided, so it is written whatever the agent thought
    pm_update('leads', function (array $cur) use ($id, $w, $brand) {
        if (isset($cur[$id]) && ($cur[$id]['status'] ?? '') === 'contacted') {
            pm_followup_store($cur[$id], $w, $brand);
        }
        return $cur;
    }, fn() => []);
    return [true, 'Follow-up drafted for ' . ($l['name'] ?? 'the lead') . '. Read it before you send.'];
}

/**
 * Scheduler job (per business): once each working morning, draft the follow-ups that are due, so they are waiting when the day starts. Drafts only; at most 12 a day;
 * not while the daily agent run is going (it drafts them too); off with the Follow-up switch in the agent settings or without an AI key.
 */
function pm_job_followup_drafts(string $brand): string
{
    $now = function_exists('pm_now') ? pm_now() : time();
    if ((int)date('N', $now) > 5 || date('H:i', $now) < '08:00' || date('H:i', $now) > '17:00') {
        return '';
    }
    $cfg = pm_agents_config($brand);
    $day = date('Y-m-d', $now);
    if (empty($cfg['enabled']['followup']) || (pm_load('followup_job', fn() => [])[$brand] ?? '') === $day || (pm_run_state()['state'] ?? '') === 'running') {
        return '';
    }
    $mine = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    $stamp = function () use ($brand, $day) {
        pm_update('followup_job', function (array $s) use ($brand, $day) {
            $s[$brand] = $day;
            return $s;
        }, fn() => []);
    };
    $prepared = pm_followup_prepare($brand); // the next follow-up written ahead for everyone who has not answered, so Follow up sends at once
    if (!pm_followup_due($mine, $cfg, false)) {
        $stamp();
        return $prepared ? "$prepared follow-up" . ($prepared === 1 ? '' : 's') . ' prepared ahead' : '';
    }
    $r = pm_followup_draft_now();
    if ($r['drafted'] > 0 || $r['failed'] === 0) {
        $stamp(); // a day with every call failing is tried again at the next pass
    }
    return $r['drafted'] . ' follow-up draft' . ($r['drafted'] === 1 ? '' : 's') . (pm_followup_by_default($brand) ? ' made from the default follow-up' : ' written with AI') . ($r['failed'] ? ', ' . $r['failed'] . ' could not be' : '')
        . ($prepared ? ", $prepared prepared ahead" : '');
}
