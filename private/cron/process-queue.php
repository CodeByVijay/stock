<?php

declare(strict_types=1);

/**
 * CLI queue worker — process queued sync_jobs in bounded batches.
 *
 * Single job (manual / cron safety net):
 *   php private/cron/process-queue.php --max-jobs=1
 *
 * Drain until empty (used by admin-panel background worker):
 *   php private/cron/process-queue.php --drain --max-jobs=1 --max-loops=2000
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/cli/bootstrap.php';
require_once PRIVATE_ROOT . '/services/job_runner.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';

$options = getopt('', ['max-jobs:', 'drain', 'max-loops:']);
$maxJobs = isset($options['max-jobs']) ? max(1, (int) $options['max-jobs']) : 1;
$drain = array_key_exists('drain', $options);
$maxLoops = isset($options['max-loops']) ? max(1, (int) $options['max-loops']) : 2000;

try {
    $pdo = db();
    $reclaimed = sync_reclaim_stale_running_jobs($pdo);
    if ($reclaimed > 0) {
        echo "Reclaimed {$reclaimed} stale running job(s).\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($drain) {
    if (!sync_worker_acquire_daemon_lock()) {
        echo "Another drain worker is already running.\n";
        exit(0);
    }
    register_shutdown_function(static function (): void {
        sync_worker_release_lock();
    });
}

$processed = 0;
$loops = 0;

do {
    $loops++;
    $batch = 0;
    while ($batch < $maxJobs) {
        $job = sync_claim_next_job($pdo);
        if ($job === null) {
            break 2;
        }
        job_runner_execute($pdo, $job);
        $processed++;
        $batch++;
    }
} while ($drain && $loops < $maxLoops);

echo "Processed {$processed} job(s)." . PHP_EOL;
exit(0);
