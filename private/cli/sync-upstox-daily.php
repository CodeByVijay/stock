<?php

declare(strict_types=1);

/**
 * Fetch Upstox V3 daily historical candles for one instrument and store OHLCV rows.
 *
 *   php private/cli/sync-upstox-daily.php \
 *     --instrument-key='NSE_EQ|INE848E01016' --symbol=RELIANCE --name='Reliance Industries' \
 *     --to=2026-10-17 --from=2026-07-01
 *
 * Omit instrument fields to use defaults from private/config/upstox.php (if set).
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/upstox.php';

$options = getopt('', [
    'instrument-key:',
    'symbol:',
    'name:',
    'exchange:',
    'type:',
    'to:',
    'from:',
]);

$cfg = upstox_config();

$instrumentKey = trim((string) ($options['instrument-key'] ?? $cfg['default_instrument_key'] ?? ''));
$symbol = trim((string) ($options['symbol'] ?? $cfg['default_symbol'] ?? ''));
$name = trim((string) ($options['name'] ?? $cfg['default_name'] ?? ''));
$exchange = trim((string) ($options['exchange'] ?? $cfg['default_exchange'] ?? 'NSE'));
$type = trim((string) ($options['type'] ?? $cfg['default_instrument_type'] ?? 'stock'));
$toDate = trim((string) ($options['to'] ?? ''));
$fromDate = isset($options['from']) ? trim((string) $options['from']) : null;

if ($instrumentKey === '' || $symbol === '' || $name === '' || $toDate === '') {
    fwrite(STDERR, "Required: --instrument-key, --symbol, --name, --to (YYYY-MM-DD). Optional: --from, --exchange, --type\n");
    exit(1);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$result = upstox_sync_daily_candles($pdo, $instrumentKey, $symbol, $name, $exchange, $type, $toDate, $fromDate);

if (!$result['ok']) {
    fwrite(STDERR, 'Sync failed: ' . ($result['error'] ?? 'Unknown error') . PHP_EOL);
    exit(1);
}

echo json_encode([
    'instrument_id' => $result['instrument_id'],
    'candles_received' => $result['candle_count'],
    'stats' => $result['stats'],
], JSON_PRETTY_PRINT) . PHP_EOL;
exit(0);
