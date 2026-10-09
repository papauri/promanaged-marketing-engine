<?php
// Reads the inbox for replies and bounces and tells the owner by email. Run every 15 minutes by the scheduler: php lib/poll_run.php [--force]
// Uses no AI tokens unless a new reply from a known lead needs classifying (STOP, bounces and out-of-office replies never do).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/engage.php';
require __DIR__ . '/mail.php';

$force = in_array('--force', $argv ?? [], true);
$lock = @fopen(PM_DATA . '/poll.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("another poll is running\n");
}
$state = pm_load('poll_state', fn() => []);
if (!$force && time() - (int)($state['at'] ?? 0) < 600) {
    exit("skipped: polled less than 10 minutes ago\n");
}
pm_save('poll_state', ['at' => time()] + $state); // stamped first: a crash never causes a tight retry loop

$app = pm_app_url();
$link = fn(string $id) => $app !== '' ? "\n\nOpen it: $app/index.php?tab=agents&lead=$id" : '';
$notify = function (string $brand, string $subject, string $text) {
    pm_brand_set($brand); // the alert goes from, and to, that business's mailbox
    return pm_notify_owner($subject, $text);
};
$rep = ['replies' => [], 'bounces' => [], 'unmatched' => []];
pm_brand_set('promanaged');
if (pm_imap_ready()) {
    try {
        [$n, $msg] = pm_inbox_poll('pm_send_reply', $rep); // reads every brand's mailbox once; each lead is answered as its own brand
        echo "$msg\n";
    } catch (Throwable $e) {
        echo 'Inbox check failed: ' . $e->getMessage() . "\n";
        pm_agent_log('Reply', 'Scheduled inbox check failed: ' . $e->getMessage());
    }
} else {
    echo "Mail reading is not set up (IMAP_HOST, IMAP_USER, IMAP_PASS in .env)\n";
}

$sent = 0;
foreach ($rep['replies'] as $it) { // only replies that need a person
    if (in_array($it['intent'], ['interested', 'question', 'meeting_request', 'other'], true) || $it['needs_human'] || str_contains($it['what'], 'please read it')) {
        $sent += (int)$notify($it['brand'], 'Reply from ' . $it['name'] . ' (' . str_replace('_', ' ', $it['intent']) . ')',
            $it['name'] . ' wrote back.' . "\n\n" . $it['summary'] . "\n\nWhat the app did: " . $it['what'] . $link($it['id']));
    }
}
foreach ($rep['bounces'] as $it) {
    if (str_starts_with($it['what'], 'email bounced')) {
        $sent += (int)$notify($it['brand'], 'Email bounced: ' . $it['name'], 'An email to ' . $it['name'] . " could not be delivered. The address was cleared so nothing is sent to it again.\n\nFind another contact for them." . $link($it['id']));
    }
}
if ($rep['unmatched']) {
    $first = $rep['unmatched'][0];
    $lines = implode("\n", array_map(fn($u) => '- ' . $u['from'] . ': ' . $u['subject'], array_slice($rep['unmatched'], 0, 10)));
    $sent += (int)$notify('promanaged', 'Unmatched reply from ' . $first['from'] . (count($rep['unmatched']) > 1 ? ' (+' . (count($rep['unmatched']) - 1) . ' more)' : ''),
        "These messages reached the inbox but match no lead. Attach them to a lead in Agents > Unmatched replies, or dismiss them.\n\n$lines" . ($app !== '' ? "\n\nOpen: $app/index.php?tab=agents" : ''));
}
if (function_exists('pm_run_stale_check')) { // the daily agent run stopped or never started
    $stale = pm_run_stale_check();
    $stale = is_array($stale) ? implode('. ', $stale) : (string)$stale;
    if ($stale !== '') {
        echo "Warning: $stale\n";
        $sent += (int)$notify('promanaged', 'The marketing agents need attention', $stale . ".\n\nCheck the scheduled task (schedule_agents.bat) on the computer that runs the app.");
    }
}
echo count($rep['replies']) . ' repl' . (count($rep['replies']) === 1 ? 'y' : 'ies') . ', ' . count($rep['bounces']) . ' bounce(s), ' . count($rep['unmatched']) . " unmatched, $sent alert(s) sent\n";
