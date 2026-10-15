<?php
/**
 * Bounces: every "could not be delivered" notice is read, tied to the lead it was for, and the lead triaged, so nobody is emailed at an address that does not work.
 * The Contact Finder owns them: she reports them on the Overview and looks for another address for each.
 *   hard     the address or domain does not exist, or the mailbox is closed     -> the address is marked bad and cleared, the lead goes in the folder "Email bounced"
 *   blocked  their server refuses our mail (spam filter, policy)                -> the same: we cannot reach them by email at that address
 *   soft     the mailbox is full or the server is busy                           -> noted; the third within 14 days counts as hard
 *   auth     refused because OUR domain failed SPF/DKIM                          -> not the lead's fault: Operations is told (lib/outbound.php)
 * A bounced lead keeps its other ways in (WhatsApp, phone): it is told which; with none, the Contact Finder looks for a new email. Queued emails, waiting
 * follow-ups and prepared follow-ups to that address are taken away, so nothing more goes to it. No AI: the notice itself says what happened.
 */

const PM_BOUNCE_FOLDER = 'Email bounced';
const PM_BOUNCE_KINDS = ['hard' => 'Address does not exist', 'blocked' => 'Their server refuses our mail', 'soft' => 'Temporary problem', 'auth' => 'Our domain was not trusted'];

/** The lead a bounce was for, by the address that failed: the lead's address, an address it had before it bounced, a colleague's address, or the app's send log. */
function pm_bounce_find_lead(array $leads, array $failed, array $brands = []): ?string
{
    $failed = array_values(array_filter(array_map(fn($a) => strtolower(trim((string)$a)), $failed)));
    if (!$failed) {
        return null;
    }
    foreach ($leads as $id => $l) {
        if ($brands && !in_array((string)($l['brand'] ?? 'promanaged'), $brands, true)) {
            continue;
        }
        $mine = array_map('strtolower', array_merge([(string)($l['email'] ?? ''), (string)($l['email_bad'] ?? '')], array_map('strval', (array)($l['alt_emails'] ?? [])), array_map('strval', (array)($l['bad_emails'] ?? []))));
        if (array_intersect($failed, array_filter($mine))) {
            return (string)$id;
        }
    }
    foreach (function_exists('pm_ml_rows') ? pm_ml_rows() : [] as $r) { // sent by the app to an address the lead no longer shows
        if (in_array(strtolower((string)($r['to'] ?? '')), $failed, true) && ($lid = (string)($r['lead'] ?? '')) !== '' && isset($leads[$lid])
            && (!$brands || in_array((string)($leads[$lid]['brand'] ?? 'promanaged'), $brands, true))) {
            return $lid;
        }
    }
    return null;
}

/**
 * Triage one bounce on its lead. $kind: hard | blocked | soft | auth | ok. $why: the server's own words ("550 No such recipient here").
 * Returns what happened in one line, or '' when it was already known (the same bounce read twice changes nothing).
 */
function pm_bounce_triage(array &$lead, string $addr, string $kind, string $why = '', string $at = ''): string
{
    $addr = strtolower(trim($addr)) ?: strtolower((string)($lead['email'] ?? ''));
    $at = $at !== '' ? $at : date('Y-m-d H:i');
    $why = mb_substr(trim((string)preg_replace('/\s+/', ' ', $why)), 0, 200);
    $brand = (string)($lead['brand'] ?? pm_brand());
    if ($kind === 'ok' || $addr === '') {
        return '';
    }
    if ($kind === 'auth') {
        return function_exists('pm_auth_failed') ? pm_auth_failed($lead) : 'refused: our domain failed SPF/DKIM';
    }
    $known = in_array($addr, array_map('strtolower', array_merge([(string)($lead['email_bad'] ?? '')], (array)($lead['bad_emails'] ?? []))), true);
    if ($kind === 'soft') {
        $soft = array_values(array_filter((array)($lead['soft_bounces'] ?? []), fn($x) => ($x['addr'] ?? '') === $addr && strtotime((string)($x['at'] ?? '')) > strtotime('-14 days')));
        if (in_array($at, array_column($soft, 'at'), true)) {
            return '';
        }
        $soft[] = ['addr' => $addr, 'at' => $at, 'why' => $why];
        $lead['soft_bounces'] = array_slice($soft, -5);
        if (count($soft) < 3) {
            pm_lead_note($lead, "Temporary delivery problem for $addr" . ($why !== '' ? " ($why)" : '') . '; the address is kept for now (' . count($soft) . ' of 3 before it counts as bounced)');
            return 'temporary delivery problem (' . count($soft) . ' of 3)';
        }
        $kind = 'hard';
        $why = 'Three temporary failures in 14 days' . ($why !== '' ? ": $why" : '');
    }
    if ($known && in_array(PM_BOUNCE_FOLDER, (array)($lead['folders'] ?? []), true)) {
        return ''; // already triaged
    }
    $was = (string)($lead['email'] ?? '');
    $lead['email_bad'] = $addr;
    $lead['bad_emails'] = array_slice(array_values(array_unique(array_merge((array)($lead['bad_emails'] ?? []), [$addr]))), -6);
    $lead['bounced_at'] = $at;
    $lead['bounce'] = ['addr' => $addr, 'kind' => $kind, 'why' => $why, 'at' => $at];
    if (strcasecmp($was, $addr) === 0) {
        $lead['email'] = ''; // nothing more is sent to it
    }
    $lead['alt_emails'] = array_values(array_filter((array)($lead['alt_emails'] ?? []), fn($a) => strcasecmp((string)$a, $addr) !== 0));
    unset($lead['approved_at'], $lead['send_after'], $lead['followup_draft'], $lead['followup_ready'], $lead['followup_approved']);
    if (!in_array(PM_BOUNCE_FOLDER, (array)($lead['folders'] ?? []), true)) {
        $lead['folders'][] = PM_BOUNCE_FOLDER;
        function_exists('pm_folders_save_list') && pm_folders_save_list($brand, fn(array $l) => array_merge($l, [PM_BOUNCE_FOLDER]));
    }
    $other = filter_var($lead['email'] ?? '', FILTER_VALIDATE_EMAIL) ? 'another address on file (' . $lead['email'] . ')' : '';
    $wa = function_exists('pm_wa_best') ? pm_wa_best($lead)[0] : '';
    $next = $other !== '' ? "we still have $other" : ($wa !== '' ? 'reach them on WhatsApp or by phone instead' : (trim((string)($lead['phone'] ?? '')) !== '' ? 'call them instead' : 'the Contact Finder looks for another address'));
    pm_lead_note($lead, 'Email to ' . $addr . ' bounced (' . strtolower(PM_BOUNCE_KINDS[$kind] ?? $kind) . ($why !== '' ? ': ' . $why : '') . '). Moved to "' . PM_BOUNCE_FOLDER . '": no more emails to it; ' . $next . '.');
    if (function_exists('pm_tb_post') && $other === '' && $wa === '') {
        pm_tb_post($brand, 'replies', 'contact', 'Email to ' . ($lead['name'] ?? 'a lead') . ' bounced (' . ($why !== '' ? $why : PM_BOUNCE_KINDS[$kind]) . '): please find another address or number.', 'bounce:' . ($lead['id'] ?? $addr));
    }
    return 'email bounced (' . strtolower(PM_BOUNCE_KINDS[$kind] ?? $kind) . '); moved to ' . PM_BOUNCE_FOLDER . '; ' . $next;
}

/** The Contact Finder's bounce numbers for one business: bounced in 7 and 30 days, of those how many can still be reached (WhatsApp, phone, another email), how many need a new address. */
function pm_bounce_counts(string $brand, ?array $leads = null): array
{
    $c = ['week' => 0, 'month' => 0, 'reachable' => 0, 'need' => 0, 'need_ids' => []];
    foreach ($leads ?? pm_leads_read() as $id => $l) {
        if (($l['brand'] ?? 'promanaged') !== $brand || empty($l['bounced_at']) || !in_array(PM_BOUNCE_FOLDER, (array)($l['folders'] ?? []), true)) {
            continue;
        }
        $t = strtotime((string)$l['bounced_at']) ?: 0;
        $c['week'] += $t > strtotime('-7 days') ? 1 : 0;
        $c['month'] += $t > strtotime('-30 days') ? 1 : 0;
        if (in_array($l['status'] ?? '', ['won', 'lost', 'optout'], true)) {
            continue;
        }
        $ok = filter_var($l['email'] ?? '', FILTER_VALIDATE_EMAIL) || (function_exists('pm_wa_best') && pm_wa_best($l)[0] !== '') || trim((string)($l['phone'] ?? '')) !== '';
        if ($ok) {
            $c['reachable']++;
        } else {
            $c['need']++;
            $c['need_ids'][] = (string)$id;
        }
    }
    return $c;
}

/** Leads that bounced before the folder existed (an address marked bad, no folder): put in it, once. Written only when one is found. Returns how many. */
function pm_bounce_backfill(string $brand): int
{
    $need = array_filter(pm_leads_read(), fn($l) => ($l['brand'] ?? 'promanaged') === $brand && !empty($l['email_bad']) && !in_array(PM_BOUNCE_FOLDER, (array)($l['folders'] ?? []), true));
    if (!$need) {
        return 0;
    }
    $n = 0;
    pm_update('leads', function (array $cur) use ($need, &$n) {
        foreach (array_keys($need) as $id) {
            if (isset($cur[$id]) && !in_array(PM_BOUNCE_FOLDER, (array)($cur[$id]['folders'] ?? []), true)) {
                $cur[$id]['folders'][] = PM_BOUNCE_FOLDER;
                $cur[$id]['bad_emails'] = array_values(array_unique(array_merge((array)($cur[$id]['bad_emails'] ?? []), [strtolower((string)$cur[$id]['email_bad'])])));
                $cur[$id]['bounced_at'] = (string)($cur[$id]['bounced_at'] ?? '') ?: date('Y-m-d H:i');
                pm_lead_note($cur[$id], 'Moved to "' . PM_BOUNCE_FOLDER . '": ' . $cur[$id]['email_bad'] . ' bounced earlier');
                $n++;
            }
        }
        return $cur;
    }, fn() => []);
    $n && pm_folders_save_list($brand, fn(array $l) => array_merge($l, [PM_BOUNCE_FOLDER]));
    return $n;
}
