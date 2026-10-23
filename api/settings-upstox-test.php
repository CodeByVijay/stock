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

try {
    $result = settings_upstox_test_connection();
    $public = settings_public_upstox(upstox_config());
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Upstox is not configured.'], 503);
}

json_response([
    'ok' => $result['ok'],
    'error' => $result['error'],
    'detail' => $result['detail'],
    'settings' => $public,
]);
