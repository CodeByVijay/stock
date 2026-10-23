<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';
require_once PRIVATE_ROOT . '/services/sync_bundle.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();
auth_release_session_lock();

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

if (isset($_POST['spawn_only']) && (string) $_POST['spawn_only'] === '1') {
    $spawn = sync_worker_try_spawn();
    json_response([
        'ok' => true,
        'message' => ($spawn['running'] ?? false)
            ? 'Background worker is running.'
            : (($spawn['error'] ?? '') !== '' ? 'Could not start worker: ' . $spawn['error'] : 'Worker start requested.'),
        'worker_running' => sync_worker_is_running(),
        'spawn' => $spawn,
        'queue' => sync_queue_snapshot($pdo),
        'active_categories' => sync_active_categories($pdo),
        'open_jobs' => sync_list_open_jobs($pdo),
    ]);
}

$reclaimed = sync_reclaim_stale_running_jobs($pdo);
$releasedLock = sync_worker_maybe_release_stale_daemon_lock();
if (sync_worker_is_running() && sync_count_leased_running_jobs($pdo) === 0) {
    sync_worker_release_lock();
    $releasedLock = true;
}

$spawn = sync_worker_try_spawn();
$processed = 0;
if (sync_count_leased_running_jobs($pdo) === 0 && !sync_worker_is_running()) {
    @set_time_limit(600);
    $processed = sync_worker_process_jobs($pdo, 1);
}
if ($processed === 0 && sync_queue_snapshot($pdo)['queued'] > 0) {
    $spawn = sync_worker_try_spawn();
}

$queue = sync_queue_snapshot($pdo);

$parts = [];
if ($reclaimed > 0) {
    $parts[] = 'Unstuck ' . $reclaimed . ' task(s)';
}
if ($releasedLock) {
    $parts[] = 'cleared old worker lock';
}
if ($processed > 0) {
    $parts[] = 'ran ' . $processed . ' task(s) immediately';
}
$message = $parts !== []
    ? implode(' · ', $parts) . '.'
    : 'Queue checked. Use Run next job now if nothing moves.';

json_response([
    'ok' => true,
    'reclaimed' => $reclaimed,
    'released_worker_lock' => $releasedLock,
    'processed' => $processed,
    'message' => $message,
    'worker_running' => sync_worker_is_running(),
    'spawn' => $spawn,
    'queue' => $queue,
    'active_categories' => sync_active_categories($pdo),
    'open_jobs' => sync_list_open_jobs($pdo),
]);
