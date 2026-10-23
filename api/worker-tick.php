<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();
auth_release_session_lock();

$maxJobs = isset($_POST['max_jobs']) ? max(1, min(5, (int) $_POST['max_jobs'])) : 1;
$force = isset($_POST['force']) && (string) $_POST['force'] === '1';

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$reclaimed = sync_reclaim_stale_running_jobs($pdo);
$releasedLock = false;

if ($force) {
    $releasedLock = sync_worker_maybe_release_stale_daemon_lock();
    if (sync_worker_is_running() && sync_count_leased_running_jobs($pdo) === 0) {
        sync_worker_release_lock();
        $releasedLock = true;
    }
}

$workerRunning = sync_worker_is_running();
$leasedRunning = sync_count_leased_running_jobs($pdo);

if ($workerRunning && !$force) {
    json_response([
        'ok' => true,
        'processed' => 0,
        'mode' => 'background',
        'worker_running' => true,
        'reclaimed_stale_jobs' => $reclaimed,
        'released_worker_lock' => $releasedLock,
        'queue' => sync_queue_snapshot($pdo),
        'message' => 'Background worker is processing the queue.',
    ]);
}

if ($workerRunning && $force && $leasedRunning > 0 && !sync_has_parallel_claimable_job($pdo)) {
    sync_worker_try_spawn();

    json_response([
        'ok' => true,
        'processed' => 0,
        'mode' => 'background',
        'worker_running' => true,
        'reclaimed_stale_jobs' => $reclaimed,
        'released_worker_lock' => $releasedLock,
        'queue' => sync_queue_snapshot($pdo),
        'message' => 'A task is actively running in the background. Wait for progress or use Fix stuck queue.',
    ]);
}

@set_time_limit(600);
$processed = sync_worker_process_jobs($pdo, $maxJobs);
$queue = sync_queue_snapshot($pdo);

$spawn = null;
if ($queue['queued'] > 0 && ($queue['running'] === 0 || $reclaimed > 0 || $processed > 0)) {
    $spawn = sync_worker_try_spawn();
}

json_response([
    'ok' => true,
    'reclaimed_stale_jobs' => $reclaimed,
    'released_worker_lock' => $releasedLock,
    'processed' => $processed,
    'mode' => sync_worker_is_running() ? 'background' : 'browser',
    'worker_running' => sync_worker_is_running(),
    'spawn' => $spawn,
    'queue' => $queue,
    'message' => $processed > 0
        ? 'Processed ' . $processed . ' task(s) in this request.'
        : ($reclaimed > 0 ? 'Cleared stuck task(s). Press Run again if needed.' : 'No queued task was ready to run.'),
]);
