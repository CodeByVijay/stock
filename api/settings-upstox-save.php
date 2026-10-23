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
    $result = settings_upstox_save([
        'api_base' => $_POST['api_base'] ?? 'https://api.upstox.com',
        'client_id' => $_POST['client_id'] ?? '',
        'client_secret' => $_POST['client_secret'] ?? '',
        'access_token' => $_POST['access_token'] ?? '',
        'redirect_uri' => $_POST['redirect_uri'] ?? '',
        'default_instrument_key' => $_POST['default_instrument_key'] ?? '',
        'default_symbol' => $_POST['default_symbol'] ?? '',
        'default_name' => $_POST['default_name'] ?? '',
        'default_exchange' => $_POST['default_exchange'] ?? 'NSE',
        'default_instrument_type' => $_POST['default_instrument_type'] ?? 'stock',
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Could not load Upstox configuration.'], 500);
}

json_response([
    'ok' => $result['ok'],
    'error' => $result['error'],
    'settings' => $result['public'],
]);
