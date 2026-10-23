<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once PRIVATE_ROOT . '/services/upstox_oauth.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();

$confirmed = isset($_POST['confirmed']) && (string) $_POST['confirmed'] === '1';
if (!$confirmed) {
    json_response(['ok' => false, 'error' => 'Confirmation required.'], 422);
}

$result = settings_upstox_clear_credentials();

try {
    $public = settings_public_upstox(upstox_config());
} catch (Throwable $e) {
    $public = settings_public_upstox([]);
}

json_response([
    'ok' => $result['ok'],
    'error' => $result['error'],
    'settings' => $public,
]);
