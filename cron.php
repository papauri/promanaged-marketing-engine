<?php
// Web cron for hosts without a command line scheduler (cPanel > Cron Jobs, every 15 minutes):
//   curl -s "https://YOUR-SITE/proposals/cron.php?key=THE_CRON_KEY" >/dev/null
// Put CRON_KEY=a-long-random-text in .env. Without a matching key this page does not exist (404). One pass is limited to about 25 seconds.
require __DIR__ . '/lib/store.php';
$key = (string)(pm_env()['CRON_KEY'] ?? '');
$given = (string)($_GET['key'] ?? '');
if ($key === '' || $given === '' || !hash_equals($key, $given)) {
    http_response_code(404);
    exit;
}
@set_time_limit(60);
ignore_user_abort(true);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/lib/social_modules.php';
if (is_file(__DIR__ . '/lib/mail.php')) {
    require_once __DIR__ . '/lib/mail.php';
}
header('Content-Type: text/plain; charset=utf-8');
echo pm_social_cycle(25, 'cron');
