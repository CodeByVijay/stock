<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
if ($jobId <= 0) {
    json_response(['ok' => false, 'error' => 'job_id is required.'], 422);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$job = sync_get_job($pdo, $jobId);
if ($job === null) {
    json_response(['ok' => false, 'error' => 'Job not found.'], 404);
}

json_response(['ok' => true, 'job' => $job]);
