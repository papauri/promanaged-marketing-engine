<?php
/**
 * The swarm: many agents at once, each in its own PHP process (lib/agent_worker.php runs one job). Used by the daily run (lib/run_agents.php)
 * and by the social playbook's Researcher swarm (lib/social_growth.php). Jobs are ['agent' => scout|contact|qualify|write|followup|research|..., ...].
 */

/** Run jobs with at most $parallel worker processes alive. Returns results in job order. A worker that runs past the time limit is killed ('timeout'). */
function pm_swarm(array $jobs, int $parallel, string $tmp, ?callable $beat = null): array
{
    $results = [];
    $GLOBALS['PM_SWARM'] = [];
    $running = &$GLOBALS['PM_SWARM'];
    $queue = array_keys($jobs);
    $php = pm_php_binary();
    $limit = max(5, (int)(pm_agents_config('promanaged')['worker_timeout'] ?? 240));
    $parallel = max(1, $parallel);
    $lastBeat = 0;
    while ($queue || $running) {
        while ($queue && count($running) < $parallel) {
            $i = array_shift($queue);
            $file = "$tmp/job_" . getmypid() . "_$i.json";
            file_put_contents($file, json_encode($jobs[$i] + ['brand' => pm_brand()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
            // the worker's output goes to a file, never to a pipe nobody reads: a full pipe would freeze the worker until the time limit
            $p = proc_open([$php, __DIR__ . '/agent_worker.php', $file], [['pipe', 'r'], ['file', "$file.log", 'w'], ['file', "$file.log", 'a']], $pipes);
            if (is_resource($p)) {
                @fclose($pipes[0]);
                $pipes = [];
                $running[$i] = [$p, $pipes, $file, time()];
            } else {
                $results[$i] = ['ok' => false, 'data' => null, 'error' => 'could not start worker'];
                @unlink($file);
            }
        }
        foreach ($running as $i => [$p, $pipes, $file, $t0]) {
            $alive = proc_get_status($p)['running'];
            if ($alive && time() - $t0 <= $limit) {
                continue;
            }
            if ($alive) {
                proc_terminate($p);
                usleep(200000);
            }
            foreach ($pipes as $pp) {
                @fclose($pp);
            }
            proc_close($p);
            if ($alive) {
                $results[$i] = ['ok' => false, 'data' => null, 'error' => "timeout (agent stopped after {$limit}s)"];
            } else {
                $out = is_file("$file.out.json") ? json_decode((string)file_get_contents("$file.out.json"), true) : null;
                $log = is_file("$file.log") ? trim((string)preg_replace('/\s+/', ' ', (string)file_get_contents("$file.log", false, null, 0, 4000))) : '';
                $results[$i] = is_array($out) ? $out : ['ok' => false, 'data' => null, 'error' => 'worker crashed' . ($log !== '' ? ': ' . mb_substr($log, -300) : '')];
            }
            @unlink($file);
            @unlink("$file.out.json");
            @unlink("$file.log");
            unset($running[$i]);
        }
        if ($beat && time() - $lastBeat >= 10) {
            $beat();
            $lastBeat = time();
        }
        usleep(400000);
    }
    unset($running);
    $GLOBALS['PM_SWARM'] = [];
    ksort($results);
    return $results;
}
