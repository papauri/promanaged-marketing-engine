<?php
/**
 * Lead folders: the owner's own groups of leads ("Lake lodges", "Call this week", "Hot"), made and filled straight from the Leads list. A lead can be in several folders.
 * The list of folders lives in data/lead_folders.json {brand: [name, ...]}; each lead carries its own 'folders' => [name, ...]. No AI.
 */

/** A folder name as stored: plain words, 1 to 40 characters. '' when unusable. */
function pm_folder_name(string $s): string
{
    $s = trim((string)preg_replace('/\s+/u', ' ', strip_tags($s)));
    return mb_substr($s, 0, 40);
}

/** The business's folders, in the order they were made, with how many leads are in each: [name => count]. */
function pm_folders(string $brand, ?array $leads = null): array
{
    $names = (array)(pm_load('lead_folders', fn() => [])[$brand] ?? []);
    $out = array_fill_keys($names, 0);
    foreach ($leads ?? pm_leads() as $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand) {
            continue;
        }
        foreach ((array)($l['folders'] ?? []) as $f) {
            $out[$f] = ($out[$f] ?? 0) + 1; // a folder a lead still names is shown even if the list lost it
        }
    }
    return $out;
}

function pm_folders_save_list(string $brand, callable $fn): void
{
    pm_update('lead_folders', function (array $all) use ($brand, $fn) {
        $all[$brand] = array_values(array_unique(array_filter(array_map('pm_folder_name', (array)$fn((array)($all[$brand] ?? []))))));
        return $all;
    }, fn() => []);
}

/** Puts leads in a folder (made if new). Returns how many were added. */
function pm_folder_add(array &$leads, array $ids, string $folder, string $brand): int
{
    $folder = pm_folder_name($folder);
    if ($folder === '') {
        return 0;
    }
    pm_folders_save_list($brand, fn(array $l) => array_merge($l, [$folder]));
    $n = 0;
    foreach ($ids as $id) {
        if (isset($leads[$id]) && ($leads[$id]['brand'] ?? 'promanaged') === $brand && !in_array($folder, (array)($leads[$id]['folders'] ?? []), true)) {
            $leads[$id]['folders'][] = $folder;
            $n++;
        }
    }
    return $n;
}

/** Takes leads out of a folder (the folder stays). Returns how many were taken out. */
function pm_folder_remove(array &$leads, array $ids, string $folder): int
{
    $n = 0;
    foreach ($ids as $id) {
        if (isset($leads[$id]) && in_array($folder, (array)($leads[$id]['folders'] ?? []), true)) {
            $leads[$id]['folders'] = array_values(array_diff((array)$leads[$id]['folders'], [$folder]));
            if (!$leads[$id]['folders']) {
                unset($leads[$id]['folders']);
            }
            $n++;
        }
    }
    return $n;
}

/** Renames a folder everywhere ($to '' deletes it: the leads stay, only the folder goes). */
function pm_folder_rename(array &$leads, string $brand, string $from, string $to): void
{
    $to = pm_folder_name($to);
    pm_folders_save_list($brand, fn(array $l) => array_map(fn($x) => $x === $from ? $to : $x, $l));
    foreach ($leads as $id => $l) {
        if (($l['brand'] ?? 'promanaged') === $brand && in_array($from, (array)($l['folders'] ?? []), true)) {
            $f = array_values(array_unique(array_filter(array_map(fn($x) => $x === $from ? $to : $x, (array)$l['folders']))));
            if ($f) {
                $leads[$id]['folders'] = $f;
            } else {
                unset($leads[$id]['folders']);
            }
        }
    }
}

/**
 * Moves a lead to another stage by hand (Active to Contacted, back to Draft ready, Won, Lost...). Returns '' when done, else why not.
 * Contacted by hand is recorded as a send (date, out of Active, never emailed a first time again) and filed in "Already contacted"; a lead that was emailed cannot go back to
 * Draft ready or Qualified, so its first email is never sent twice.
 */
function pm_lead_set_status(array &$lead, string $st, string $lostReason = ''): string
{
    if (!array_key_exists($st, PM_LEAD_STATUSES)) {
        return 'That is not a stage.';
    }
    $was = (string)($lead['status'] ?? '');
    if (in_array($st, ['new', 'qualified', 'drafted'], true) && !empty($lead['sent']) && !empty($lead['sent_by_hand']) && count((array)$lead['sent']) === 1) {
        $lead['sent'] = []; // only marked contacted by hand (a mistake undone): no email went from the app, so the first email may go
        unset($lead['sent_by_hand'], $lead['last_contacted'], $lead['last_out']);
        $lead['folders'] = array_values(array_diff((array)($lead['folders'] ?? []), ['Already contacted']));
    }
    if (in_array($st, ['new', 'qualified', 'drafted'], true) && !empty($lead['sent'])) {
        return ($lead['name'] ?? 'This lead') . ' was already emailed on ' . (string)end($lead['sent']) . ', so it stays past the first email (it is never sent twice).';
    }
    $lead['status'] = $st;
    if ($st === 'lost') {
        $lead['lost_reason'] = isset(pm_lost_reasons()[$lostReason]) ? $lostReason : ($lead['lost_reason'] ?? 'other');
    } else {
        unset($lead['lost_reason']);
    }
    if ($st === 'won' && empty($lead['won_at'])) {
        $lead['won_at'] = date('Y-m-d H:i');
    }
    if ($st === 'optout' && $was !== 'optout') {
        $lead['prev_status'] = $was;
        $lead['optout_at'] = date('Y-m-d H:i');
    }
    if ($st === 'contacted') {
        if (empty($lead['sent'])) { // contacted outside the app: recorded like a send, so follow-ups count from now and no first email goes again
            $lead['sent'][] = date('Y-m-d H:i');
            $lead['sent_by_hand'] = true; // so a mistake can be undone
            $lead['last_contacted'] = $lead['last_out'] = date('Y-m-d H:i');
        }
        unset($lead['approved_at'], $lead['send_after']);
        if (!in_array('Already contacted', (array)($lead['folders'] ?? []), true)) {
            $lead['folders'][] = 'Already contacted';
        }
    }
    if (in_array($st, ['won', 'lost', 'optout', 'replied', 'proposal'], true)) {
        unset($lead['approved_at'], $lead['send_after']); // nothing cold goes to them from the queue
    }
    if ($st === 'lost' && function_exists('pm_tb_post')) {
        pm_tb_post((string)($lead['brand'] ?? 'promanaged'), 'owner', 'director', ($lead['name'] ?? 'A lead') . ' closed as lost (' . (pm_lost_reasons()[$lead['lost_reason']] ?? 'other') . ')', 'lost:' . ($lead['id'] ?? $lead['name'] ?? ''));
    }
    pm_lead_note($lead, 'Moved by hand from ' . (PM_LEAD_STATUSES[$was] ?? $was) . ' to ' . PM_LEAD_STATUSES[$st] . ($st === 'lost' ? ' (' . (pm_lost_reasons()[$lead['lost_reason']] ?? '') . ')' : ''));
    return '';
}

/**
 * The owner turns drafts down ("not like this") with a reason: each ticked lead's waiting first email or follow-up is taken away, the reason goes on the lead and on the
 * team board (so every Writer learns it), and the Writers write them again now, in batches (one cheap call for up to six). A rewrite that fails is done by the next run.
 * Returns [rewritten, turned down, message].
 */
function pm_leads_turn_down(array $ids, string $reason): array
{
    $reason = mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags($reason))), 0, 200) ?: 'The owner did not like it';
    $brand = pm_brand();
    $first = [];
    $fups = [];
    pm_update('leads', function (array $all) use ($ids, $reason, $brand, &$first, &$fups) {
        foreach ($ids as $id) {
            $l = $all[$id] ?? null;
            if (!$l || ($l['brand'] ?? 'promanaged') !== $brand) {
                continue;
            }
            if (($l['status'] ?? '') === 'contacted' && !empty($l['followup_draft']['email_body'])) {
                unset($all[$id]['followup_draft'], $all[$id]['followup_approved'], $all[$id]['approved_at'], $all[$id]['send_after']);
                $all[$id]['rewrite_reason'] = $reason;
                pm_lead_note($all[$id], 'Follow-up turned down: ' . $reason);
                $fups[] = (string)$id;
            } elseif (empty($l['sent']) && !empty($l['drafts']['email_body']) && in_array($l['status'] ?? '', ['new', 'qualified', 'drafted'], true)) {
                unset($all[$id]['drafts'], $all[$id]['approved_at'], $all[$id]['send_after']);
                $all[$id]['status'] = 'qualified';
                $all[$id]['rewrite_reason'] = $reason;
                pm_lead_note($all[$id], 'First email turned down: ' . $reason);
                $first[] = (string)$id;
            }
        }
        return $all;
    }, fn() => []);
    $n = count($first) + count($fups);
    if (!$n) {
        return [0, 0, 'None of the ticked leads had a waiting email to turn down.'];
    }
    pm_tb_post($brand, 'owner', $first ? 'writers' : 'followup', 'The owner turned down ' . $n . ' email' . ($n === 1 ? '' : 's') . ': "' . $reason . '". Do not repeat that.', 'turned:' . md5($reason));
    if (!pm_agents_ready() && !isset($GLOBALS['PM_AI_STUB'])) {
        return [0, $n, "$n turned down. They are written again at the next agent run (the AI is not set up here)."];
    }
    @set_time_limit(300);
    $done = 0;
    $leads = pm_leads();
    foreach (array_chunk($first, 6) as $chunk) { // first emails: the Writer, with the owner's reason on each
        try {
            $rows = pm_agent_write(array_map(fn($id) => ['id' => $id] + $leads[$id], $chunk));
        } catch (Throwable) {
            continue;
        }
        pm_update('leads', function (array $all) use ($rows, &$done) {
            foreach ($rows as $w) {
                $id = (string)($w['id'] ?? '');
                if (isset($all[$id]) && !empty($w['email_body']) && empty($all[$id]['drafts']) && empty($all[$id]['sent'])) {
                    $all[$id]['drafts'] = ['email_subject' => (string)($w['email_subject'] ?? ''), 'email_body' => (string)$w['email_body'], 'whatsapp' => (string)($w['whatsapp'] ?? ''), 'source' => 'rewrite'];
                    $all[$id]['status'] = 'drafted';
                    pm_lead_note($all[$id], 'Rewritten after your feedback: ' . ($all[$id]['rewrite_reason'] ?? ''));
                    unset($all[$id]['rewrite_reason']);
                    $done++;
                }
            }
            return $all;
        }, fn() => []);
    }
    if ($fups) { // follow-ups: the Follow-up agent, with the reason; asked by the owner, so it writes
        foreach (array_chunk($fups, 6) as $chunk) {
            try {
                $rows = pm_agent_followup(array_map(fn($id) => ['id' => $id] + $leads[$id], $chunk));
            } catch (Throwable) {
                continue;
            }
            pm_update('leads', function (array $all) use ($rows, $brand, &$done) {
                foreach ($rows as $w) {
                    $id = (string)($w['id'] ?? '');
                    if (isset($all[$id]) && !empty($w['email_body']) && ($all[$id]['status'] ?? '') === 'contacted' && empty($all[$id]['followup_draft'])) {
                        pm_followup_store($all[$id], $w, $brand);
                        $done++;
                    }
                }
                return $all;
            }, fn() => []);
        }
    }
    return [$done, $n, "$n turned down, $done written again with your reason" . ($done < $n ? ' (the rest at the next agent run)' : '') . '. Every Writer has your reason on the team board.'];
}
