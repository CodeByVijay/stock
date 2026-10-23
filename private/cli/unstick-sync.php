<?php

declare(strict_types=1);

/**
 * Production recovery when Download Data shows "in progress" for hours.
 *
 *   php private/cli/unstick-sync.php
 *   php private/cron/process-queue.php --drain --max-jobs=1 --max-loops=2000
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/cli/bootstrap.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';

$pdo = db();
$n = sync_reclaim_stale_running_jobs($pdo);
echo "Reclaimed {$n} stale running job(s).\n";

$spawn = sync_worker_try_spawn();
if ($spawn['running'] ?? false) {
    echo "Background worker is running (pid " . ($spawn['pid'] ?? '?') . ").\n";
} else {
    echo "Start worker manually: php private/cron/process-queue.php --drain\n";
    if (!empty($spawn['error'])) {
        echo 'Spawn note: ' . $spawn['error'] . "\n";
    }
}

exit(0);
