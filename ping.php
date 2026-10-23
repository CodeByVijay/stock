<?php

declare(strict_types=1);

/**
 * Quick server check when the site shows HTTP 500. Delete or block after debugging.
 */
header('Content-Type: text/plain; charset=utf-8');

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}
if (!defined('PRIVATE_ROOT')) {
    define('PRIVATE_ROOT', APP_ROOT . '/private');
}

require_once APP_ROOT . '/includes/functions.php';

$lines = [];
$lines[] = 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')';

$checks = [
    'includes/functions.php' => APP_ROOT . '/includes/functions.php',
    'includes/auth.php' => APP_ROOT . '/includes/auth.php',
    'login.php' => APP_ROOT . '/login.php',
    'private/config/database.php' => PRIVATE_ROOT . '/config/database.php',
];

foreach ($checks as $label => $path) {
    $lines[] = $label . ': ' . (is_readable($path) ? 'readable' : 'MISSING');
}

try {
    start_secure_session();
    $lines[] = 'session: OK';
} catch (Throwable $e) {
    $lines[] = 'session: FAIL — ' . $e->getMessage();
}

try {
    db();
    $lines[] = 'database: OK';
} catch (Throwable $e) {
    $lines[] = 'database: FAIL — ' . $e->getMessage();
}

foreach ([
    PRIVATE_ROOT . '/services/sync_jobs.php',
    PRIVATE_ROOT . '/services/sync_bundle.php',
] as $svc) {
    $short = basename($svc);
    if (!is_readable($svc)) {
        $lines[] = $short . ': missing';
        continue;
    }
    exec('php -l ' . escapeshellarg($svc) . ' 2>&1', $out, $code);
    $lines[] = $short . ': ' . trim(implode(' ', $out));
}

$lines[] = 'done';
echo implode("\n", $lines) . "\n";
