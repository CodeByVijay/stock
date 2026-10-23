<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';
require_once PRIVATE_ROOT . '/services/sync_bundle.php';
require_once dirname(__DIR__) . '/includes/market_data_job_labels.php';

require_login_api();

try {
    $pdo = db();
    $reclaimed = sync_reclaim_stale_running_jobs($pdo);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$latest = null;
$stmt = $pdo->query(
    "SELECT id, job_type, status, started_at, finished_at, progress_done, progress_total,
            records_processed, error_message, result_json
     FROM sync_jobs
     WHERE status IN ('running', 'queued')
     ORDER BY FIELD(status, 'running', 'queued'), id ASC
     LIMIT 1"
);
$row = $stmt->fetch();
if (!$row) {
    $stmt = $pdo->query(
        'SELECT id, job_type, status, started_at, finished_at, progress_done, progress_total,
                records_processed, error_message, result_json
         FROM sync_jobs ORDER BY id DESC LIMIT 1'
    );
    $row = $stmt->fetch();
}
if ($row) {
    $latest = sync_job_row_to_array($row);
    if (!empty($row['result_json'])) {
        $decoded = json_decode((string) $row['result_json'], true);
        $latest['result'] = is_array($decoded) ? $decoded : null;
    }
}

if ($reclaimed > 0 && sync_worker_is_running() === false) {
    sync_worker_try_spawn();
}

$openJobs = sync_list_open_jobs($pdo);
foreach ($openJobs as &$oj) {
    $oj['label_simple'] = market_data_job_label_simple((string) ($oj['job_type'] ?? ''));
}
unset($oj);

json_response([
    'ok' => true,
    'reclaimed_stale_jobs' => $reclaimed,
    'worker_running' => sync_worker_is_running(),
    'leased_running' => sync_count_leased_running_jobs($pdo),
    'queue' => sync_queue_snapshot($pdo),
    'latest_job' => $latest,
    'open_jobs' => $openJobs,
    'active_categories' => sync_active_categories($pdo),
]);
