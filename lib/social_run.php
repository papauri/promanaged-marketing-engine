<?php
// Publishes scheduled social posts whose time has come, keeps the calendar topped up, then runs the Page autopilot (about once an hour). Run every 15 to 30 minutes by the scheduler: php lib/social_run.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/social_growth.php';
if (is_file(__DIR__ . '/mail.php')) {
    require_once __DIR__ . '/mail.php'; // owner alerts (pm_notify_owner)
}
try {
    echo pm_social_due(true) . " post(s) published\n";
} catch (Throwable $e) {
    echo 'publish: ' . $e->getMessage() . "\n";
}
foreach (['promanaged', 'travel'] as $b) { // independent of the Facebook token: drafts can be made before a Page is connected
    try {
        echo $b . ' calendar: ' . pm_social_replenish($b) . "\n";
    } catch (Throwable $e) {
        echo $b . ' calendar: ' . $e->getMessage() . "\n";
    }
}
try {
    if ($w = pm_social_notify_waiting()) {
        echo "$w draft(s) waiting for approval: owner alerted\n";
    }
} catch (Throwable $e) {
    echo 'alert: ' . $e->getMessage() . "\n";
}
foreach (['promanaged', 'travel'] as $b) {
    try {
        echo $b . ': ' . pm_social_autopilot($b) . "\n";
    } catch (Throwable $e) {
        echo $b . ': ' . $e->getMessage() . "\n";
    }
}
