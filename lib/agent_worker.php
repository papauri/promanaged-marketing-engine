<?php
// One agent, one job. Started by run_agents.php so many agents can work at the same time.
// Usage: php agent_worker.php <job.json>   (writes <job>.out.json)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/engage.php';
require_once __DIR__ . '/swarm.php';

$jobFile = $argv[1] ?? '';
$done = false;
// Whatever happens (bad job file, fatal error), the parent gets an answer instead of "worker crashed".
register_shutdown_function(function () use ($jobFile, &$done) {
    if (!$done && $jobFile !== '') {
        $e = error_get_last();
        @file_put_contents($jobFile . '.out.json', json_encode(['ok' => false, 'data' => null, 'error' => 'worker stopped: ' . ($e['message'] ?? 'unknown error')], JSON_UNESCAPED_UNICODE));
    }
});
$job = is_file($jobFile) ? json_decode((string)file_get_contents($jobFile), true) : null;
if (!is_array($job)) {
    $done = true;
    if ($jobFile !== '') {
        @file_put_contents($jobFile . '.out.json', json_encode(['ok' => false, 'data' => null, 'error' => 'bad job file']));
    }
    exit(2);
}
pm_brand_set((string)($job['brand'] ?? 'promanaged'));
$result = pm_agent_job($job); // the same job runner the swarm uses when it cannot start processes (lib/swarm.php)
$done = true;
file_put_contents($jobFile . '.out.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
