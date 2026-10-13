<?php
// Builds an added business up from nothing (lib/brand_launch.php), in the background: php lib/run_launch.php <business id> [missing]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/social_modules.php';
require_once __DIR__ . '/mail.php';
set_time_limit(0);
$b = (string)($argv[1] ?? '');
if (!pm_brand_is_custom($b)) {
    echo "Not a business added in the app: $b\n";
    exit(1);
}
$st = pm_launch_run($b, ($argv[2] ?? '') === 'missing');
foreach ($st['steps'] as $k => $s) {
    echo ($s['ok'] ? 'ok   ' : 'todo ') . $k . ': ' . $s['msg'] . "\n";
}
