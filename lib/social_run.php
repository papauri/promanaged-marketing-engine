<?php
// Publishes scheduled social posts whose time has come, keeps the calendar topped up, runs the Page autopilot (about once an hour) and every module job
// (metrics, reports, token watchdog...). Run every 15 to 30 minutes by the scheduler: php lib/social_run.php   (on a website host: cron.php?key=CRON_KEY)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/social_modules.php';
if (is_file(__DIR__ . '/mail.php')) {
    require_once __DIR__ . '/mail.php'; // owner alerts (pm_notify_owner)
}
echo pm_social_cycle(0, 'cli');
