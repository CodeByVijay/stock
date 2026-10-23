<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';
require_once PRIVATE_ROOT . '/services/sync_bundle.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();
auth_release_session_lock();

$jobType = trim((string) ($_POST['job_type'] ?? ''));
$syncAll = isset($_POST['sync_all']) && (string) $_POST['sync_all'] === '1';

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

/** @return array<string,mixed> */
function start_job_worker_payload(PDO $pdo, bool $needWorker): array
{
    if (!$needWorker) {
        return [
            'worker' => [
                'ok' => true,
                'spawned' => false,
                'running' => false,
                'mode' => 'none',
                'error' => null,
            ],
            'queue' => sync_queue_snapshot($pdo),
        ];
    }

    try {
        $worker = sync_worker_ensure_running($pdo);
    } catch (Throwable $e) {
        $worker = [
            'ok' => false,
            'spawned' => false,
            'running' => false,
            'mode' => 'browser',
            'error' => 'Background worker could not start. Jobs will run in this browser tab.',
            'pid' => null,
        ];
    }
    if (!$worker['running']) {
        // Spawn failure is normal on shared hosting — browser worker-tick handles the queue.
        $worker['mode'] = 'browser';
        $worker['ok'] = true;

        return [
            'worker' => $worker,
            'queue' => sync_queue_snapshot($pdo),
            'info' => $worker['error'] ?? null,
        ];
    }

    return [
        'worker' => $worker,
        'queue' => sync_queue_snapshot($pdo),
    ];
}

if ($syncAll) {
    $ids = sync_create_all_jobs($pdo);
    $extra = start_job_worker_payload($pdo, $ids !== []);
    json_response(array_merge([
        'ok' => true,
        'job_ids' => $ids,
        'job_id' => $ids !== [] ? $ids[0] : null,
        'message' => $ids === [] ? 'No new jobs queued (types may already be active).' : 'Queued ' . count($ids) . ' job(s). Processing started.',
    ], $extra));
}

if ($jobType === '') {
    json_response(['ok' => false, 'error' => 'job_type is required.'], 422);
}

$result = sync_create_job($pdo, $jobType);
if (!$result['ok']) {
    json_response(['ok' => false, 'error' => $result['error'] ?? 'Could not queue job.'], 409);
}

$extra = start_job_worker_payload($pdo, true);
$category = sync_category_for_job_type($jobType);
$queueHint = '';
$snap = sync_queue_snapshot($pdo);
if ($snap['running'] > 0 && $snap['queued'] > 0) {
    $queueHint = ' Other tasks can run in parallel when they are a different type (e.g. ETFs while shares update). Use Run next job now if waiting.';
}
$payload = array_merge([
    'ok' => true,
    'job_id' => $result['job_id'],
    'job_type' => $jobType,
    'category' => $category,
    'message' => 'Job #' . $result['job_id'] . ' queued. Processing started.' . $queueHint,
], $extra);

if (isset($extra['warning'])) {
    $payload['warning'] = $extra['warning'];
}

json_response($payload);
