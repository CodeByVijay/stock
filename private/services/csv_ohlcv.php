<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/instrument_master.php';

/** @return array{discovered:int,inserted:int,updated:int,skipped:int,errors:list<string>} */
function csv_import_ohlcv(PDO $pdo, string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('OHLCV CSV not readable.');
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Could not open OHLCV CSV.');
    }

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        throw new RuntimeException('Empty OHLCV CSV.');
    }

    $map = ohlcv_csv_header_map($header);
    $stats = ['discovered' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $fetchedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $priceStmt = $pdo->prepare(
        'INSERT INTO daily_prices (instrument_id, trade_date, open, high, low, close, volume, source, fetched_at)
         VALUES (:iid, :dt, :o, :h, :l, :c, :v, :src, :fa)
         ON DUPLICATE KEY UPDATE open=VALUES(open), high=VALUES(high), low=VALUES(low), close=VALUES(close),
         volume=VALUES(volume), source=VALUES(source), fetched_at=VALUES(fetched_at)'
    );

    while (($line = fgetcsv($fh)) !== false) {
        $stats['discovered']++;
        try {
            $row = ohlcv_csv_row($map, $line);
            $instrumentId = instrument_upsert($pdo, [
                'exchange' => $row['exchange'],
                'symbol' => $row['symbol'],
                'name' => $row['name'] ?? $row['symbol'],
                'instrument_type' => $row['instrument_type'] ?? 'stock',
                'master_source' => 'csv_ohlcv',
                'provider' => 'csv',
            ]);

            $priceStmt->execute([
                'iid' => $instrumentId,
                'dt' => $row['trade_date'],
                'o' => $row['open'],
                'h' => $row['high'],
                'l' => $row['low'],
                'c' => $row['close'],
                'v' => $row['volume'],
                'src' => 'csv_import',
                'fa' => $fetchedAt,
            ]);

            if ($priceStmt->rowCount() === 1) {
                $stats['inserted']++;
            } elseif ($priceStmt->rowCount() === 2) {
                $stats['updated']++;
            }
            instrument_refresh_coverage($pdo, $instrumentId);
        } catch (Throwable $e) {
            $stats['skipped']++;
            if (count($stats['errors']) < 25) {
                $stats['errors'][] = $e->getMessage();
            }
        }
    }
    fclose($fh);

    return $stats;
}

/** @param list<string|null> $header @return array<string,int> */
function ohlcv_csv_header_map(array $header): array
{
    $map = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim(str_replace([' ', '-'], '_', (string) $col)));
        $aliases = [
            'symbol' => ['symbol', 'ticker'],
            'exchange' => ['exchange', 'exch'],
            'trade_date' => ['trade_date', 'date', 'dt'],
            'open' => ['open', 'o'],
            'high' => ['high', 'h'],
            'low' => ['low', 'l'],
            'close' => ['close', 'c'],
            'volume' => ['volume', 'vol', 'v'],
            'name' => ['name'],
            'instrument_type' => ['instrument_type', 'type'],
        ];
        foreach ($aliases as $canonical => $list) {
            if (in_array($key, $list, true)) {
                $map[$canonical] = (int) $i;
            }
        }
    }
    foreach (['symbol', 'exchange', 'trade_date', 'close'] as $req) {
        if (!isset($map[$req])) {
            throw new InvalidArgumentException('OHLCV CSV missing column: ' . $req);
        }
    }

    return $map;
}

/** @param array<string,int> $map @param list<string|null> $line @return array<string,mixed> */
function ohlcv_csv_row(array $map, array $line): array
{
    $dateRaw = trim((string) ($line[$map['trade_date']] ?? ''));
    $dt = DateTime::createFromFormat('Y-m-d', $dateRaw);
    if (!$dt) {
        $dt = DateTime::createFromFormat('d-m-Y', $dateRaw);
    }
    if (!$dt) {
        throw new InvalidArgumentException('Invalid trade_date: ' . $dateRaw);
    }

    return [
        'symbol' => trim((string) ($line[$map['symbol']] ?? '')),
        'exchange' => strtoupper(trim((string) ($line[$map['exchange']] ?? ''))),
        'trade_date' => $dt->format('Y-m-d'),
        'open' => isset($map['open']) ? (float) $line[$map['open']] : null,
        'high' => isset($map['high']) ? (float) $line[$map['high']] : null,
        'low' => isset($map['low']) ? (float) $line[$map['low']] : null,
        'close' => (float) $line[$map['close']],
        'volume' => isset($map['volume']) ? (int) $line[$map['volume']] : null,
        'name' => isset($map['name']) ? trim((string) $line[$map['name']]) : null,
        'instrument_type' => isset($map['instrument_type']) ? trim((string) $line[$map['instrument_type']]) : 'stock',
    ];
}
