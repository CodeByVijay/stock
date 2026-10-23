<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once dirname(__DIR__) . '/includes/research.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();
auth_release_session_lock();

$instrumentId = isset($_POST['instrument_id']) ? (int) $_POST['instrument_id'] : 0;
if ($instrumentId <= 0) {
    json_response(['ok' => false, 'error' => 'instrument_id is required.'], 422);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$instrument = research_get_instrument($pdo, $instrumentId);
if (!$instrument) {
    json_response(['ok' => false, 'error' => 'Instrument not found.'], 404);
}

if (research_watchlist_has($pdo, $instrumentId)) {
    research_watchlist_remove($pdo, $instrumentId);
    $on = false;
} else {
    research_watchlist_add($pdo, $instrumentId);
    $on = true;
}

json_response(['ok' => true, 'on_watchlist' => $on]);
