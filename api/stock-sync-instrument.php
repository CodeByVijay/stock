<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once dirname(__DIR__) . '/includes/research.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/sync_worker.php';
require_once PRIVATE_ROOT . '/services/instrument_sync.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();
auth_release_session_lock();

$instrumentId = isset($_POST['instrument_id']) ? (int) $_POST['instrument_id'] : 0;
$mode = strtolower(trim((string) ($_POST['mode'] ?? 'full')));
$inline = isset($_POST['inline']) && (string) $_POST['inline'] === '1';

if ($instrumentId <= 0) {
    json_response(['ok' => false, 'error' => 'instrument_id is required.'], 422);
}

if (!in_array($mode, ['recent', 'full'], true)) {
    json_response(['ok' => false, 'error' => 'mode must be recent or full.'], 422);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

if (!research_get_instrument($pdo, $instrumentId)) {
    json_response(['ok' => false, 'error' => 'Instrument not found.'], 404);
}

// Full history can exceed web server timeout — queue by default.
if ($mode === 'full' && !$inline) {
    if (sync_has_active_job($pdo, 'instrument_ohlcv_sync')) {
        json_response([
            'ok' => false,
            'error' => 'Another price download is already running. Wait a moment and try again.',
        ], 409);
    }

    $created = sync_create_job($pdo, 'instrument_ohlcv_sync', [
        'instrument_id' => $instrumentId,
        'mode' => $mode,
    ]);
    if (!$created['ok'] || $created['job_id'] === null) {
        json_response(['ok' => false, 'error' => $created['error'] ?? 'Could not queue job.'], 500);
    }

    $worker = sync_worker_ensure_running($pdo);

    json_response([
        'ok' => true,
        'async' => true,
        'job_id' => $created['job_id'],
        'message' => 'Download started in background. This page will refresh when done.',
        'worker' => $worker,
    ]);
}

@set_time_limit(300);
$result = instrument_sync_prices_from_upstox($pdo, $instrumentId, $mode);

if (!$result['ok']) {
    json_response(['ok' => false, 'error' => $result['error'] ?? 'Sync failed.'], 502);
}

json_response([
    'ok' => true,
    'async' => false,
    'instrument_id' => $instrumentId,
    'mode' => $mode,
    'candles_received' => $result['candles_received'],
    'stats' => $result['stats'],
    'bars_stored' => $result['bars_stored'],
    'message' => $result['message'],
    'last_trade_date' => $result['last_trade_date'],
    'last_close' => $result['last_close'],
]);
