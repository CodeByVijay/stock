<?php

declare(strict_types=1);

/**
 * Sync one mutual fund scheme from MFapi.in into MySQL.
 *
 * Examples:
 *   php private/cli/sync-mf-scheme.php --scheme=125497 --latest
 *   php private/cli/sync-mf-scheme.php --scheme=125497 --from=2026-01-01 --to=2026-10-19
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/mfapi.php';

$options = getopt('', ['scheme:', 'from:', 'to:', 'latest', 'full']);

$scheme = isset($options['scheme']) ? (int) $options['scheme'] : 0;
if ($scheme <= 0) {
    fwrite(STDERR, "Usage: --scheme=SCHEME_CODE [--latest | --from=YYYY-MM-DD --to=YYYY-MM-DD]\n");
    exit(1);
}

$latestOnly = array_key_exists('latest', $options);
$fullHistory = array_key_exists('full', $options);
$from = isset($options['from']) ? trim((string) $options['from']) : null;
$to = isset($options['to']) ? trim((string) $options['to']) : null;

if (!$latestOnly && !$fullHistory && ($from === null || $to === null)) {
    fwrite(STDERR, "Provide --latest, --full, or both --from and --to.\n");
    exit(1);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($fullHistory) {
    $result = mfapi_sync_scheme($pdo, $scheme, null, null, false, 'full');
} else {
    $result = mfapi_sync_scheme($pdo, $scheme, $from, $to, $latestOnly, 'bounded');
}

if (!$result['ok']) {
    fwrite(STDERR, 'Sync failed: ' . ($result['error'] ?? 'Unknown error') . PHP_EOL);
    exit(1);
}

echo json_encode([
    'scheme_id' => $result['scheme_id'],
    'stats' => $result['stats'],
], JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
