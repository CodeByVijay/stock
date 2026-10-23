<?php

declare(strict_types=1);

/**
 * Import Upstox BOD instrument JSON (NSE/BSE) into instruments table.
 *
 *   php private/cli/import-upstox-instruments.php --exchange=NSE --type=stock
 *   php private/cli/import-upstox-instruments.php --exchange=NSE --type=etf
 */

require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_ROOT . '/services/upstox_instruments.php';

$options = getopt('', ['exchange:', 'type:', 'analyze']);
$exchange = strtoupper(trim((string) ($options['exchange'] ?? 'NSE')));
$type = strtolower(trim((string) ($options['type'] ?? 'stock')));

if (!in_array($exchange, ['NSE', 'BSE'], true)) {
    fwrite(STDERR, "exchange must be NSE or BSE\n");
    exit(1);
}
if (!in_array($type, ['stock', 'etf'], true)) {
    fwrite(STDERR, "type must be stock or etf\n");
    exit(1);
}

try {
    if (isset($options['analyze'])) {
        $loaded = upstox_instruments_load_exchange_file($exchange);
        if (!$loaded['ok']) {
            throw new RuntimeException($loaded['error'] ?? 'load failed');
        }
        $summary = upstox_instruments_summarize_file($loaded['rows']);
        $etfLike = 0;
        $importable = 0;
        foreach ($loaded['rows'] as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            if (upstox_row_looks_like_etf($rawRow)) {
                $etfLike++;
            }
            $mapped = upstox_instruments_map_row($rawRow, $type);
            if ($mapped['row'] !== null) {
                $importable++;
            }
        }
        echo json_encode([
            'ok' => true,
            'exchange' => $exchange,
            'asset_kind' => $type,
            'etf_like_rows' => $etfLike,
            'would_import_rows' => $importable,
            'summary' => $summary,
        ], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    $pdo = db();
    $stats = upstox_import_instruments($pdo, $exchange, $type, null);
    $withKeys = instrument_count_with_upstox_key($pdo, $type);
    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'instruments_with_upstox_key' => $withKeys,
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
