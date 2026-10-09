<?php
/**
 * Test harness. Usage: require __DIR__ . '/boot.php'; $T = pm_test_boot(); ... pm_t_done();
 * Copies the top-level data/*.json (read-only) to a temp dir, points the app at it, stubs the network. Real data/ is never written.
 *   $GLOBALS['PM_GRAPH_STUB'] = fn(string $method, string $path, array $params, string $token, array $files): array  (pm_graph return shape: [ok, data|message])
 *   $GLOBALS['PM_AI_STUB']    = fn(string $system, string $user, string $tier, int $maxTokens): string
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['PM_T'] = ['pass' => 0, 'fail' => 0, 'tmp' => '', 'real' => '', 'sha' => []];

function pm_t_norm(string $p): string { return strtolower(rtrim(str_replace('\\', '/', $p), '/')); }

/** sha1 of every top-level real data/*.json, to prove the run left them alone. */
function pm_t_real_sums(string $real): array
{
    $o = [];
    foreach (glob($real . '/*.json') ?: [] as $f) {
        $o[basename($f)] = sha1_file($f);
    }
    return $o;
}

function pm_test_boot(): array
{
    $root = dirname(__DIR__);
    $real = realpath($root . '/data') ?: $root . '/data';
    if (defined('PM_DATA')) {
        fwrite(STDERR, "boot: store.php was loaded before pm_test_boot().\n");
        exit(2);
    }
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm_test_' . bin2hex(random_bytes(5));
    if (!mkdir($tmp . '/data', 0775, true) || !mkdir($tmp . '/out', 0775, true)) {
        fwrite(STDERR, "boot: cannot create $tmp\n");
        exit(2);
    }
    $GLOBALS['PM_T']['tmp'] = $tmp;
    $GLOBALS['PM_T']['real'] = $real;
    $GLOBALS['PM_T']['sha'] = pm_t_real_sums($real);
    foreach (glob($real . '/*.json') ?: [] as $f) { // read-only copy
        copy($f, $tmp . '/data/' . basename($f));
    }
    $sf = $tmp . '/data/settings.json';
    $s = is_file($sf) ? (json_decode((string)file_get_contents($sf), true) ?: []) : [];
    foreach (['smtp', 'imap'] as $k) { // mail can never go out
        $s[$k] = ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => ''];
        if (isset($s['travel']) && is_array($s['travel'])) {
            $s['travel'][$k] = $s[$k];
        }
    }
    file_put_contents($sf, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $env = $tmp . '/env.txt';
    file_put_contents($env, "APP_URL=\nAPP_PASSWORD=\nCRON_KEY=test-cron-key\n");
    putenv('PM_DATA_DIR=' . $tmp . '/data');
    putenv('PM_OUT_DIR=' . $tmp . '/out');
    putenv('PM_ENV_FILE=' . $env);
    putenv('PM_TEST=1');
    $GLOBALS['PM_NOSLEEP'] = true;
    require_once $root . '/lib/store.php';
    if (pm_t_norm(PM_DATA) === pm_t_norm($real) || pm_t_norm(PM_DATA) !== pm_t_norm($tmp . '/data')) {
        fwrite(STDERR, "boot: PM_DATA is not the temp dir (" . PM_DATA . "). Aborting.\n");
        exit(2);
    }
    require_once $root . '/lib/agents.php';
    require_once $root . '/lib/social_growth.php';
    require_once $root . '/lib/social_modules.php';
    return ['data' => $tmp . '/data', 'out' => $tmp . '/out', 'env' => $env, 'tmp' => $tmp, 'real' => $real];
}

function pm_t_assert(bool $ok, string $label): void
{
    $GLOBALS['PM_T'][$ok ? 'pass' : 'fail']++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
}

function pm_t_eq($a, $b, string $label): void
{
    $ok = $a === $b;
    pm_t_assert($ok, $label . ($ok ? '' : ' (got ' . json_encode($a) . ', want ' . json_encode($b) . ')'));
}

/** A valid draft post for $over's brand, saved to the (temp) posts file. */
function pm_t_fixture_post(array $over = []): array
{
    $p = $over + [
        'id' => bin2hex(random_bytes(10)), 'brand' => 'promanaged', 'status' => 'draft', 'when' => date('Y-m-d', strtotime('+1 day')) . ' 07:30',
        'format' => 'image', 'pillar' => 'Tip/How-to', 'cta' => 'comment', 'proof_id' => '',
        'caption' => 'Which part of your shop takes the most time at closing? Tell us below and we will share a simple routine.',
        'hashtags' => ['#Malawi'], 'headline' => 'Closing the shop', 'sub' => 'A calmer end to the day', 'script' => '', 'media' => '',
        'fb_id' => '', 'error' => '', 'ig' => '', 'tries' => 0, 'retry_at' => '', 'lint' => [], 'created' => date('Y-m-d H:i'), 'by' => '',
    ];
    pm_social_update(function (array $posts) use ($p) {
        $posts[] = $p;
        return $posts;
    });
    return $p;
}

/** Recursively removes a directory, but only one this harness created. */
function pm_t_rm(string $dir): void
{
    if (!str_starts_with(pm_t_norm($dir), pm_t_norm(sys_get_temp_dir()) . '/pm_test_') || !is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function pm_t_done(): never
{
    $T = &$GLOBALS['PM_T'];
    $now = pm_t_real_sums($T['real']);
    $bad = array_keys(array_diff_assoc($now, $T['sha']) + array_diff_assoc($T['sha'], $now));
    pm_t_assert(!$bad, 'real data/*.json unchanged' . ($bad ? ' (changed: ' . implode(', ', $bad) . ')' : ''));
    pm_t_rm((string)$T['tmp']);
    echo "\n" . $T['pass'] . ' passed, ' . $T['fail'] . " failed\n";
    exit($T['fail'] ? 1 : 0);
}
