<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once dirname(__DIR__) . '/includes/research.php';
require_once PRIVATE_ROOT . '/services/mfapi.php';
require_once PRIVATE_ROOT . '/services/mf_scheme_meta.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();

$schemeId = isset($_POST['scheme_id']) ? (int) $_POST['scheme_id'] : 0;
$mode = strtolower(trim((string) ($_POST['mode'] ?? 'full')));

if ($schemeId <= 0) {
    json_response(['ok' => false, 'error' => 'scheme_id is required.'], 422);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$scheme = research_get_scheme($pdo, $schemeId);
if (!$scheme) {
    json_response(['ok' => false, 'error' => 'Scheme not found.'], 404);
}

$schemeCode = (int) $scheme['scheme_code'];
if ($schemeCode <= 0) {
    json_response(['ok' => false, 'error' => 'Invalid scheme code on record.'], 422);
}

if ($mode === 'latest') {
    $result = mfapi_sync_scheme($pdo, $schemeCode, null, null, true);
} elseif ($mode === 'recent') {
    $syncCfg = sync_config();
    $days = max(1, (int) ($syncCfg['mf_history_days'] ?? 90));
    $to = (new DateTimeImmutable('today'))->format('Y-m-d');
    $from = (new DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');
    $result = mfapi_sync_scheme($pdo, $schemeCode, $from, $to, false, 'bounded');
} else {
    // full — entire history from MFapi (GET /mf/{scheme_code} without date filters)
    $result = mfapi_sync_scheme($pdo, $schemeCode, null, null, false, 'full');
}

if (!$result['ok']) {
    json_response([
        'ok' => false,
        'error' => $result['error'] ?? 'NAV sync failed.',
    ], 502);
}

$fresh = research_get_scheme($pdo, $schemeId);
$navStmt = $pdo->prepare(
    'SELECT nav, nav_date, fetched_at, source FROM mutual_fund_nav
     WHERE scheme_id = :id ORDER BY nav_date DESC LIMIT 1'
);
$navStmt->execute(['id' => $schemeId]);
$lastNav = $navStmt->fetch() ?: null;

$stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];

json_response([
    'ok' => true,
    'scheme_id' => $schemeId,
    'scheme_code' => $schemeCode,
    'mode' => $mode,
    'api_rows' => (int) ($result['api_rows'] ?? 0),
    'stats' => $stats,
    'coverage' => $result['coverage'] ?? research_get_scheme_nav_coverage($pdo, $schemeId),
    'message' => sprintf(
        'Received %d NAV rows from MFapi. Inserted %d, updated %d, skipped %d.',
        (int) ($result['api_rows'] ?? 0),
        (int) ($stats['inserted'] ?? 0),
        (int) ($stats['updated'] ?? 0),
        (int) ($stats['skipped'] ?? 0)
    ),
    'last_nav' => $lastNav ? (float) $lastNav['nav'] : null,
    'last_nav_date' => $lastNav['nav_date'] ?? null,
    'scheme_name' => $fresh['scheme_name'] ?? $scheme['scheme_name'],
]);
