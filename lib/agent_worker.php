<?php
// One agent, one job. Started by run_agents.php so many agents can work at the same time.
// Usage: php agent_worker.php <job.json>   (writes <job>.out.json)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/engage.php';

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
$result = ['ok' => false, 'data' => null, 'error' => ''];
pm_brand_set((string)($job['brand'] ?? 'promanaged'));
try {
    $data = match ($job['agent'] ?? '') {
        'scout'    => pm_agent_scout($job['sector'], $job['city'], (int)$job['want'], $job['known'] ?? []),
        'contact'  => pm_agent_contact($job['lead']),
        'qualify'  => pm_agent_qualify($job['leads']),
        'write'    => pm_agent_write($job['leads']),
        'followup' => pm_agent_followup($job['leads']),
        'research' => pm_agent_research($job['lead']),
        'polish'   => pm_agent_polish($job['p'], $job['line'], $job['package'], $job['lead']),
        default    => throw new RuntimeException('unknown agent "' . (is_string($job['agent'] ?? null) ? $job['agent'] : '?') . '"'),
    };
    $result = ['ok' => true, 'data' => $data, 'error' => ''];
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}
$done = true;
file_put_contents($jobFile . '.out.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
