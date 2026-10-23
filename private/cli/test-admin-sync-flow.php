<?php

declare(strict_types=1);

/**
 * Simulates admin-panel sync start (queue + spawn + drain) without opening a browser.
 * Usage: php private/cli/test-admin-sync-flow.php
 */

require_once dirname(__DIR__) . '/cli/bootstrap.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';

$pdo = db();

if (sync_has_active_job($pdo, 'recalculate_indicators')) {
    echo "Skip: recalculate_indicators already active.\n";
}

$result = sync_create_job($pdo, 'recalculate_indicators');
if (!$result['ok']) {
    fwrite(STDERR, "Queue failed: " . ($result['error'] ?? '') . PHP_EOL);
    exit(1);
}

echo "Queued job #" . $result['job_id'] . PHP_EOL;

$worker = sync_worker_ensure_running($pdo);
echo 'Worker: ' . json_encode($worker, JSON_PRETTY_PRINT) . PHP_EOL;

if (!$worker['running']) {
    echo "Spawn did not stay running — processing one tick inline.\n";
    $n = sync_worker_process_jobs($pdo, 1);
    echo "Inline processed: {$n}\n";
    exit($n > 0 ? 0 : 2);
}

$deadline = time() + 120;
while (time() < $deadline) {
    $snap = sync_queue_snapshot($pdo);
    if ($snap['queued'] === 0 && $snap['running'] === 0) {
        echo "Queue empty.\n";
        exit(0);
    }
    usleep(500000);
}

fwrite(STDERR, "Timeout waiting for queue drain.\n");
exit(3);
