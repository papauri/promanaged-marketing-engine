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
        if (($l['status'] ?? '') !== 'contacted' || (int)($l['followups'] ?? 0) >= (int)($cfg['max_followups'] ?? 3) || pm_lead_snoozed($l) || pm_lead_email_dead($l)) {
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

/** The AI's follow-up for one lead stored on the lead: subject, email, WhatsApp, and the subject style it used (so the follow-up experiment can score it). */
function pm_followup_store(array &$lead, array $w, string $brand): void
{
    $lead['followup_draft'] = ['email_subject' => (string)($w['email_subject'] ?? ''), 'email_body' => (string)($w['email_body'] ?? ''), 'whatsapp' => (string)($w['whatsapp'] ?? '')];
    if (function_exists('pm_followup_arm')) {
        $lead['followup_arm'] = pm_followup_arm($lead, $brand);
    }
    pm_lead_note($lead, 'Follow-up drafted with AI');
}

/**
 * Drafts follow-ups with the AI now for the current business: the due ones with no draft yet (at most $max, six to a call), or only $ids when given.
 * A lead that was sent, replied or got a draft while this ran is left alone. Returns ['drafted' => n, 'failed' => n, 'due' => n].
 */
function pm_followup_draft_now(array $ids = [], int $max = 12): array
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
    foreach (array_chunk(array_slice($due, 0, $max, true), 6, true) as $chunk) {
        $batch = [];
        foreach ($chunk as $k => $l) {
            $batch[] = ['id' => (string)($l['id'] ?? $k)] + $l;
        }
        try {
            $out = pm_agent_followup($batch);
        } catch (Throwable) {
            $failed += count($batch);
            continue;
        }
        $by = [];
        foreach ($out as $w) {
            if (is_array($w) && isset($w['id']) && is_scalar($w['id']) && trim((string)($w['email_body'] ?? '')) !== '') {
                $by[(string)$w['id']] = $w;
            }
        }
        pm_update('leads', function (array $cur) use ($by, $brand, &$drafted) {
            foreach ($by as $id => $w) {
                if (!isset($cur[$id]) || ($cur[$id]['status'] ?? '') !== 'contacted' || !empty($cur[$id]['followup_draft'])) {
                    continue;
                }
                pm_followup_store($cur[$id], $w, $brand);
                $drafted++;
            }
            return $cur;
        }, fn() => []);
        $failed += count($batch) - count($by);
    }
    return ['drafted' => $drafted, 'failed' => $failed, 'due' => $total];
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
    if (!function_exists('pm_agents_ready') || (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB']))) { // (a test stub stands in for the AI key)
        return '';
    }
    $now = function_exists('pm_now') ? pm_now() : time();
    if ((int)date('N', $now) > 5 || date('H:i', $now) < '08:00' || date('H:i', $now) > '17:00') {
        return '';
    }
    $cfg = pm_agents_config($brand);
    $day = date('Y-m-d', $now);
    if (empty($cfg['enabled']['followup']) || (pm_load('followup_job', fn() => [])[$brand] ?? '') === $day || (pm_run_state()['state'] ?? '') === 'running') {
        return '';
    }
    $mine = array_filter(pm_leads(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand);
    $stamp = function () use ($brand, $day) {
        pm_update('followup_job', function (array $s) use ($brand, $day) {
            $s[$brand] = $day;
            return $s;
        }, fn() => []);
    };
    if (!pm_followup_due($mine, $cfg, false)) {
        $stamp();
        return '';
    }
    $r = pm_followup_draft_now();
    if ($r['drafted'] > 0 || $r['failed'] === 0) {
        $stamp(); // a day with every call failing is tried again at the next pass
    }
    return $r['drafted'] . ' follow-up draft' . ($r['drafted'] === 1 ? '' : 's') . ' written with AI' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be' : '');
}
