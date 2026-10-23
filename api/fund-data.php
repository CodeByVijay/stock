<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once dirname(__DIR__) . '/includes/research.php';
require_once PRIVATE_ROOT . '/services/mf_returns.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$schemeId = isset($_GET['scheme_id']) ? (int) $_GET['scheme_id'] : 0;
$days = isset($_GET['days']) ? min(1825, max(30, (int) $_GET['days'])) : 365;

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

$stmt = $pdo->prepare(
    'SELECT nav_date, nav, source, fetched_at
     FROM mutual_fund_nav
     WHERE scheme_id = :id
     ORDER BY nav_date DESC
     LIMIT :lim'
);
$stmt->bindValue('id', $schemeId, PDO::PARAM_INT);
$stmt->bindValue('lim', $days, PDO::PARAM_INT);
$stmt->execute();
$rows = array_reverse($stmt->fetchAll() ?: []);

$labels = [];
$navs = [];
$source = null;
$fetchedAt = null;
$lastNavDate = null;

foreach ($rows as $row) {
    $labels[] = $row['nav_date'];
    $navs[] = (float) $row['nav'];
    $source = $row['source'];
    $fetchedAt = $row['fetched_at'];
    $lastNavDate = $row['nav_date'];
}

$performance = mf_scheme_performance_returns($pdo, $schemeId);

json_response([
    'ok' => true,
    'scheme' => [
        'id' => (int) $scheme['id'],
        'scheme_code' => $scheme['scheme_code'],
        'scheme_name' => $scheme['scheme_name'],
        'fund_house' => $scheme['fund_house'],
        'scheme_category' => $scheme['scheme_category'],
    ],
    'source' => $source,
    'fetched_at' => $fetchedAt,
    'last_nav_date' => $lastNavDate,
    'stale_warning' => research_stale_message($fetchedAt, $lastNavDate, true),
    'labels' => $labels,
    'nav' => $navs,
    'performance' => $performance,
    'note' => 'NAV is published once per business day; it is not an intraday traded price.',
]);
