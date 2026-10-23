<?php

declare(strict_types=1);

/**
 * Start Upstox access-token approval flow (user approves in Upstox app; token arrives at notifier webhook).
 *
 *   php private/cli/upstox-request-token.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/upstox.php';

$result = upstox_request_access_token();

if (!$result['ok']) {
    fwrite(STDERR, 'Token request failed: ' . ($result['error'] ?? 'Unknown') . ' (HTTP ' . $result['status'] . ')' . PHP_EOL);
    exit(1);
}

echo "Token request initiated. Approve the request in the Upstox app.\n";
echo "Paste the received access_token into private/config/upstox.php when your webhook delivers it.\n";
echo json_encode($result['data'], JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
