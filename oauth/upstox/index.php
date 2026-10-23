<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once PRIVATE_ROOT . '/services/upstox_oauth.php';

require_login();

$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$state = isset($_GET['state']) ? (string) $_GET['state'] : '';

if ($code === '') {
    flash_set('oauth_error', 'Upstox did not return an authorization code.');
    redirect('settings-upstox.php?oauth=error');
}

$result = upstox_oauth_handle_callback($code, $state);
if (!$result['ok']) {
    flash_set('oauth_error', $result['error'] ?? 'Authorization failed.');
    redirect('settings-upstox.php?oauth=error');
}

flash_set('success', 'Upstox connected successfully.');
redirect('settings-upstox.php?oauth=success');
