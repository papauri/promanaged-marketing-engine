<?php
// Studies a business again in the background (lib/relearn.php): php lib/run_relearn.php <business id>
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/agents.php';
require_once __DIR__ . '/social_modules.php';
set_time_limit(0);
$b = (string)($argv[1] ?? '');
if (!pm_brand_valid($b)) {
    echo "Not a business in the app: $b\n";
    exit(1);
}
$st = pm_rl_run($b);
echo ($st['state'] ?? '') . ': ' . count((array)($st['proposals'] ?? [])) . " proposals\n";
