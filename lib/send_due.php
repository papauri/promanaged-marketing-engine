<?php
/**
 * Slow drip sender. Run every 15 minutes (schedule_agents.bat): sends AT MOST ONE owner-approved email per run,
 * only in the days and hours each business chose (default Mon-Fri 08:00-16:30), then waits about the business's own spacing (lib/sendschedule.php). Owner approval (approved_at) is the consent; no auto switch needed.
 * Stops at the warm-up daily cap and when the bounce breaker trips. Command line only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/agents.php';
if (!function_exists('pm_mail')) { // a test bootstrap may define its own
    require_once __DIR__ . '/mail.php';
}
require_once __DIR__ . '/outbound.php';

$now = pm_now();
if (!array_filter(pm_brand_ids(), fn($b) => pm_send_window($now, $b))) { // no business is open for sending (its days, hours or a pause)
    exit(0);
}
$lock = @fopen(PM_DATA . '/send_due.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another run is in progress
}
$state = pm_ob_read('send_state');
if ($now < (int)($state['next_send_at'] ?? 0)) {
    exit(0);
}
$leads = pm_leads();
$br = pm_send_breaker($leads);
if ($br['blocked']) {
    if (($state['paused_note'] ?? '') !== $br['why']) { // say it once, not every 15 minutes
        pm_agent_log('Sender', $br['why']);
        pm_ob_update('send_state', function ($s) use ($br) { $s['paused_note'] = $br['why']; return $s; });
    }
    exit(0);
}

$cands = [];
foreach (pm_brand_ids() as $b) {
    pm_brand_set($b);
    if (!pm_send_window($now, $b)) {
        continue; // this business is closed for sending now (its own days and hours, or paused)
    }
    if (pm_sent_today($leads) >= pm_effective_send_cap(pm_agents_config())) {
        continue; // today's (warm-up) limit for this brand is used up
    }
    foreach (pm_send_queue($leads, $b) as $id => $l) {
        if (pm_ss_due($l, $now)) { // an email told to wait for a time waits for it
            $cands[] = [$id, (int)($l['score'] ?? 0)];
        }
    }
}
usort($cands, fn($x, $y) => $y[1] <=> $x[1]); // best score first, across brands

$dirty = false;
$done = false;
foreach (array_slice($cands, 0, 15) as [$id]) {
    $l = $leads[$id];
    pm_brand_set((string)($l['brand'] ?? 'promanaged'));
    if (!empty($l['snooze_until']) && strtotime((string)$l['snooze_until']) > $now) {
        continue; // snoozed: stays approved
    }
    if (($l['send_fail_at'] ?? '') === date('Y-m-d')) {
        continue; // failed today: never retried more than once a day
    }
    $which = 'drafts';
    if (!empty($l['sent'])) {
        if (empty($l['followup_approved']) || empty($l['followup_draft'])) {
            unset($leads[$id]['approved_at']); // already emailed and no follow-up approved
            pm_lead_note($leads[$id], 'Approval cleared: first email already sent');
            $dirty = true;
            continue;
        }
        $which = 'followup_draft';
    }
    $r = pm_send_first($leads, $id, $which);
    pm_brand_set((string)($l['brand'] ?? 'promanaged'));
    if ($r['ok']) {
        pm_agent_log('Sender', ($which === 'drafts' ? 'Sent first email to ' : 'Sent follow-up to ') . $l['name'] . ' (score ' . (int)($l['score'] ?? 0) . ')');
        $dirty = false; // pm_send_first saved everything
        $done = true;
        $sentBrand = (string)($l['brand'] ?? 'promanaged');
        echo "sent:{$l['name']}\n";
        break;
    }
    if ($r['kind'] === 'breaker') {
        break;
    }
    if ($r['kind'] === 'cap') {
        continue; // approval stays for tomorrow
    }
    if ($r['kind'] === 'smtp') { // the mail server failed, not the lead: it keeps its approval and is tried again tomorrow
        $leads[$id]['send_fail_at'] = date('Y-m-d');
        pm_agent_log('Sender', 'Mail server problem, ' . $l['name'] . ' waits until tomorrow: ' . $r['msg'], true);
        $dirty = true;
        $done = true; // do not hammer a failing mail server
        break;
    }
    unset($leads[$id]['approved_at'], $leads[$id]['send_after']);
    pm_lead_note($leads[$id], 'Not sent: ' . $r['msg']);
    pm_agent_log('Sender', 'Did not send to ' . $l['name'] . ': ' . $r['msg']);
    $dirty = true;
}
if ($dirty) {
    pm_leads_save($leads);
}
if ($done) {
    $gap = pm_ss_next_gap($sentBrand ?? 'promanaged'); // about the business's own spacing
    pm_ob_update('send_state', function ($s) use ($now, $gap) {
        unset($s['paused_note']);
        $s['next_send_at'] = $now + $gap;
        $s['last_run'] = date('Y-m-d H:i:s', $now);
        return $s;
    });
} else {
    echo "nothing to send\n";
}
