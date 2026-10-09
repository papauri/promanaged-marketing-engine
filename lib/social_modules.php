<?php
/** Loads the extension registry and every lib/sx_*.php module (sorted). Required by index.php, social_run.php, cron.php and tests/boot.php. */
require_once __DIR__ . '/social_growth.php';
require_once __DIR__ . '/social_nav.php';
require_once __DIR__ . '/social_jobs.php';
foreach (glob(__DIR__ . '/sx_*.php') ?: [] as $f) {
    require_once $f;
}
