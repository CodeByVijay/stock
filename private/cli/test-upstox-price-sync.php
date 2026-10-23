<?php

declare(strict_types=1);

/**
 * Sync recent daily candles for one stored instrument (smoke test).
 *
 *   php private/cli/test-upstox-price-sync.php --type=stock
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/upstox.php';

$options = getopt('', ['type:', 'limit:']);
$type = strtolower(trim((string) ($options['type'] ?? 'stock')));
$limit = max(1, min(3, (int) ($options['limit'] ?? 1)));

if (!in_array($type, ['stock', 'etf'], true)) {
    fwrite(STDERR, "type must be stock or etf\n");
    exit(1);
}

$auth = upstox_verify_authentication();
if (!$auth['ok']) {
    fwrite(STDERR, 'Upstox auth failed: ' . ($auth['error'] ?? 'unknown') . PHP_EOL);
    exit(1);
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT id, symbol, name, exchange, provider_instrument_key
     FROM instruments
     WHERE instrument_type = :type AND is_active = 1
       AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''
     ORDER BY id ASC
     LIMIT " . (int) $limit
);
$stmt->execute(['type' => $type]);
$rows = $stmt->fetchAll() ?: [];

if ($rows === []) {
    fwrite(STDERR, "No {$type} instruments with Upstox keys in the database.\n");
    exit(1);
}

$toDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$fromDate = (new DateTimeImmutable('today'))->modify('-7 days')->format('Y-m-d');
$results = [];

foreach ($rows as $row) {
    $result = upstox_sync_daily_candles(
        $pdo,
        (string) $row['provider_instrument_key'],
        (string) $row['symbol'],
        (string) $row['name'],
        (string) ($row['exchange'] ?? 'NSE'),
        $type,
        $toDate,
        $fromDate
    );
    $results[] = [
        'symbol' => $row['symbol'],
        'instrument_key' => $row['provider_instrument_key'],
        'ok' => $result['ok'],
        'error' => $result['error'] ?? null,
        'candle_count' => $result['candle_count'] ?? 0,
        'stats' => $result['stats'] ?? [],
    ];
}

echo json_encode(['ok' => true, 'from' => $fromDate, 'to' => $toDate, 'results' => $results], JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
