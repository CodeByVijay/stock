<?php

declare(strict_types=1);

/**
 * Verify Upstox access token via GET /v2/user/profile.
 *
 *   php private/cli/upstox-auth-check.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/upstox.php';

$result = upstox_verify_authentication();

if (!$result['ok']) {
    fwrite(STDERR, 'Authentication failed: ' . ($result['error'] ?? 'Unknown') . ' (HTTP ' . $result['status'] . ')' . PHP_EOL);
    exit(1);
}

$data = $result['data'];
echo "Authentication OK.\n";
if (is_array($data)) {
    echo 'User ID: ' . ($data['user_id'] ?? '—') . PHP_EOL;
    echo 'Broker: ' . ($data['broker'] ?? '—') . PHP_EOL;
    echo 'Active: ' . ((isset($data['is_active']) && $data['is_active']) ? 'yes' : 'no') . PHP_EOL;
}
exit(0);
