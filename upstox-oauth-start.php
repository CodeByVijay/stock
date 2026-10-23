<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once PRIVATE_ROOT . '/services/upstox_oauth.php';

require_login();

$result = upstox_oauth_begin();
if (!$result['ok'] || empty($result['url'])) {
    flash_set('error', $result['error'] ?? 'Could not start Upstox authorization.');
    redirect('settings-upstox.php');
}

auth_release_session_lock();
header('Location: ' . $result['url']);
exit;
