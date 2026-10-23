<?php

declare(strict_types=1);

/**
 * Test MFapi search (stdout JSON, no DB writes).
 *
 *   php private/cli/mfapi-search.php --q=HDFC
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/mfapi.php';

$options = getopt('', ['q:']);
$query = isset($options['q']) ? trim((string) $options['q']) : '';
if ($query === '') {
    fwrite(STDERR, "Usage: --q=search text\n");
    exit(1);
}

$result = mfapi_search($query);
if (!$result['ok']) {
    fwrite(STDERR, 'Search failed: ' . ($result['error'] ?? 'Unknown') . ' (HTTP ' . $result['status'] . ')' . PHP_EOL);
    exit(1);
}

echo json_encode($result['data'], JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
