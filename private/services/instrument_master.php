<?php

declare(strict_types=1);

function providers_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = PRIVATE_ROOT . '/config/providers.php';
        if (!is_readable($path)) {
            throw new RuntimeException('Missing private/config/providers.php');
        }
        $config = require $path;
        $localPath = PRIVATE_ROOT . '/config/providers.local.php';
        if (is_readable($localPath)) {
            $local = require $localPath;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
    }

    return $config;
}

function instrument_normalize_status(?string $raw): string
{
    $s = strtolower(trim((string) $raw));
    if ($s === '') {
        return 'unknown';
    }
    if (strpos($s, 'suspend') !== false) {
        return 'suspended';
    }
    if (strpos($s, 'delist') !== false) {
        return 'delisted';
    }
    if (in_array($s, ['active', 'y', 'yes', '1', 'trading'], true)) {
        return 'active';
    }
    if (in_array($s, ['inactive', 'n', 'no', '0'], true)) {
        return 'inactive';
    }

    return 'unknown';
}

/** @param array<string,mixed> $row */
function instrument_upsert(PDO $pdo, array $row): int
{
    $exchange = strtoupper(trim((string) ($row['exchange'] ?? '')));
    $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
    if ($exchange === '' || $symbol === '') {
        throw new InvalidArgumentException('exchange and symbol required');
    }

    $type = (string) ($row['instrument_type'] ?? 'stock');
    if (!in_array($type, ['stock', 'etf', 'other'], true)) {
        $type = 'stock';
    }

    $status = instrument_normalize_status($row['listing_status'] ?? $row['status'] ?? null);
    $isActive = ($status === 'active' || $status === 'unknown') ? 1 : 0;

    $find = $pdo->prepare(
        'SELECT id FROM instruments WHERE exchange = :ex AND symbol = :sym LIMIT 1'
    );
    $find->execute(['ex' => $exchange, 'sym' => $symbol]);
    $existing = $find->fetch();

    if ($existing) {
        $upd = $pdo->prepare(
            'UPDATE instruments SET name = :name, instrument_type = :type, provider = :provider,
             provider_instrument_key = COALESCE(:pkey, provider_instrument_key),
             isin = COALESCE(:isin, isin), listing_status = :status, is_active = :active,
             security_category = COALESCE(:cat, security_category), master_source = :ms,
             master_synced_at = NOW() WHERE id = :id'
        );
        $upd->execute([
            'name' => (string) ($row['name'] ?? $symbol),
            'type' => $type,
            'provider' => $row['provider'] ?? null,
            'pkey' => $row['provider_instrument_key'] ?? null,
            'isin' => $row['isin'] ?? null,
            'status' => $status,
            'active' => $isActive,
            'cat' => $row['security_category'] ?? null,
            'ms' => $row['master_source'] ?? null,
            'id' => (int) $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO instruments (symbol, name, exchange, instrument_type, provider, provider_instrument_key,
         isin, listing_status, security_category, master_source, master_synced_at, is_active)
         VALUES (:sym, :name, :ex, :type, :provider, :pkey, :isin, :status, :cat, :ms, NOW(), :active)'
    );
    $ins->execute([
        'sym' => $symbol,
        'name' => (string) ($row['name'] ?? $symbol),
        'ex' => $exchange,
        'type' => $type,
        'provider' => $row['provider'] ?? null,
        'pkey' => $row['provider_instrument_key'] ?? null,
        'isin' => $row['isin'] ?? null,
        'status' => $status,
        'cat' => $row['security_category'] ?? null,
        'ms' => $row['master_source'] ?? null,
        'active' => $isActive,
    ]);

    return (int) $pdo->lastInsertId();
}

/** @return array{discovered:int,inserted:int,updated:int,skipped:int,errors:list<string>} */
function instrument_import_equity_csv(PDO $pdo, string $path, string $exchange, string $defaultType, string $sourceLabel): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('CSV not readable: ' . $path);
    }

    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Could not open CSV.');
    }

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        throw new RuntimeException('Empty CSV.');
    }

    $map = instrument_csv_header_map($header);
    $stats = ['discovered' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    while (($line = fgetcsv($fh)) !== false) {
        $stats['discovered']++;
        $row = instrument_csv_combine($map, $line);
        if ($row === null) {
            $stats['skipped']++;
            continue;
        }

        $type = strtolower((string) ($row['instrument_type'] ?? $defaultType));
        if ($type === 'etf' && $defaultType === 'stock') {
            $stats['skipped']++;
            continue;
        }
        if ($defaultType === 'etf' && $type !== 'etf') {
            $stats['skipped']++;
            continue;
        }

        try {
            $before = $pdo->prepare('SELECT id FROM instruments WHERE exchange = :ex AND symbol = :sym');
            $before->execute(['ex' => $exchange, 'sym' => strtoupper($row['symbol'])]);
            $existed = (bool) $before->fetch();

            instrument_upsert($pdo, [
                'exchange' => $exchange,
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'isin' => $row['isin'] ?? null,
                'instrument_type' => $type === 'etf' ? 'etf' : 'stock',
                'listing_status' => $row['status'] ?? null,
                'security_category' => $row['category'] ?? null,
                'master_source' => $sourceLabel,
                'provider' => 'csv',
            ]);

            if ($existed) {
                $stats['updated']++;
            } else {
                $stats['inserted']++;
            }
        } catch (Throwable $e) {
            $stats['skipped']++;
            if (count($stats['errors']) < 20) {
                $stats['errors'][] = $e->getMessage();
            }
        }
    }
    fclose($fh);

    return $stats;
}

/** @param list<string|null> $header @return array<string,int> */
function instrument_csv_header_map(array $header): array
{
    $map = [];
    foreach ($header as $i => $col) {
        $key = strtolower(trim((string) $col));
        $key = str_replace([' ', '-'], '_', $key);
        $aliases = [
            'symbol' => ['symbol', 'ticker', 'security_id', 'scrip_code', 'scripcode'],
            'name' => ['name', 'company_name', 'security_name', 'issuer_name'],
            'isin' => ['isin', 'isin_code'],
            'status' => ['status', 'listing_status', 'security_status'],
            'category' => ['category', 'security_category', 'segment', 'series'],
            'instrument_type' => ['instrument_type', 'type', 'security_type'],
        ];
        foreach ($aliases as $canonical => $list) {
            if (in_array($key, $list, true)) {
                $map[$canonical] = (int) $i;
            }
        }
    }
    if (!isset($map['symbol'])) {
        throw new InvalidArgumentException('CSV must include a symbol column.');
    }

    return $map;
}

/** @param array<string,int> $map @param list<string|null> $line @return array<string,string>|null */
function instrument_csv_combine(array $map, array $line): ?array
{
    $symbol = trim((string) ($line[$map['symbol']] ?? ''));
    if ($symbol === '') {
        return null;
    }
    $nameIdx = $map['name'] ?? null;

    return [
        'symbol' => $symbol,
        'name' => $nameIdx !== null ? trim((string) ($line[$nameIdx] ?? $symbol)) : $symbol,
        'isin' => isset($map['isin']) ? trim((string) ($line[$map['isin']] ?? '')) : null,
        'status' => isset($map['status']) ? trim((string) ($line[$map['status']] ?? '')) : null,
        'category' => isset($map['category']) ? trim((string) ($line[$map['category']] ?? '')) : null,
        'instrument_type' => isset($map['instrument_type']) ? trim((string) ($line[$map['instrument_type']] ?? '')) : 'stock',
    ];
}

function instrument_refresh_coverage(PDO $pdo, int $instrumentId): void
{
    $stmt = $pdo->prepare(
        'SELECT MIN(trade_date) AS mn, MAX(trade_date) AS mx, COUNT(*) AS cnt,
                MAX(fetched_at) AS lf, MAX(source) AS src
         FROM daily_prices WHERE instrument_id = :id'
    );
    $stmt->execute(['id' => $instrumentId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['cnt'] === 0) {
        return;
    }

    $ups = $pdo->prepare(
        'INSERT INTO instrument_data_coverage
         (instrument_id, earliest_trade_date, latest_trade_date, bar_count, source, last_fetched_at)
         VALUES (:iid, :mn, :mx, :cnt, :src, :lf)
         ON DUPLICATE KEY UPDATE
            earliest_trade_date = VALUES(earliest_trade_date),
            latest_trade_date = VALUES(latest_trade_date),
            bar_count = VALUES(bar_count),
            source = VALUES(source),
            last_fetched_at = VALUES(last_fetched_at)'
    );
    $ups->execute([
        'iid' => $instrumentId,
        'mn' => $row['mn'],
        'mx' => $row['mx'],
        'cnt' => (int) $row['cnt'],
        'src' => $row['src'],
        'lf' => $row['lf'],
    ]);
}

function mf_refresh_nav_coverage(PDO $pdo, int $schemeId): void
{
    $stmt = $pdo->prepare(
        'SELECT MIN(nav_date) AS mn, MAX(nav_date) AS mx, COUNT(*) AS cnt,
                MAX(fetched_at) AS lf, MAX(source) AS src
         FROM mutual_fund_nav WHERE scheme_id = :id'
    );
    $stmt->execute(['id' => $schemeId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['cnt'] === 0) {
        return;
    }

    $ups = $pdo->prepare(
        'INSERT INTO mf_nav_coverage (scheme_id, earliest_nav_date, latest_nav_date, nav_count, source, last_fetched_at)
         VALUES (:sid, :mn, :mx, :cnt, :src, :lf)
         ON DUPLICATE KEY UPDATE
            earliest_nav_date = VALUES(earliest_nav_date),
            latest_nav_date = VALUES(latest_nav_date),
            nav_count = VALUES(nav_count),
            source = VALUES(source),
            last_fetched_at = VALUES(last_fetched_at)'
    );
    $ups->execute([
        'sid' => $schemeId,
        'mn' => $row['mn'],
        'mx' => $row['mx'],
        'cnt' => (int) $row['cnt'],
        'src' => $row['src'],
        'lf' => $row['lf'],
    ]);
}

/** @return array<string,int> */
function universe_summary_counts(PDO $pdo): array
{
    $out = [];
    $out['instruments_total'] = (int) $pdo->query('SELECT COUNT(*) FROM instruments')->fetchColumn();
    $out['instruments_active'] = (int) $pdo->query('SELECT COUNT(*) FROM instruments WHERE is_active = 1')->fetchColumn();
    $out['stocks'] = (int) $pdo->query("SELECT COUNT(*) FROM instruments WHERE instrument_type = 'stock'")->fetchColumn();
    $out['etfs'] = (int) $pdo->query("SELECT COUNT(*) FROM instruments WHERE instrument_type = 'etf'")->fetchColumn();
    $out['mf_schemes'] = (int) $pdo->query('SELECT COUNT(*) FROM mutual_fund_schemes')->fetchColumn();
    $out['with_daily_prices'] = (int) $pdo->query('SELECT COUNT(DISTINCT instrument_id) FROM daily_prices')->fetchColumn();
    $out['with_nav'] = (int) $pdo->query('SELECT COUNT(DISTINCT scheme_id) FROM mutual_fund_nav')->fetchColumn();

    return $out;
}

/**
 * Read-only stats for Market Data Center (honest coverage, no full-market claims).
 *
 * @return array<string,mixed>
 */
function market_data_dashboard_stats(PDO $pdo): array
{
    $base = universe_summary_counts($pdo);
    $mfActive = (int) $pdo->query(
        'SELECT COUNT(*) FROM mutual_fund_schemes WHERE is_active = 1'
    )->fetchColumn();

    $stocksNoHistory = (int) $pdo->query(
        "SELECT COUNT(*) FROM instruments i
         WHERE i.instrument_type = 'stock' AND i.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM daily_prices d WHERE d.instrument_id = i.id)"
    )->fetchColumn();

    $etfsNoHistory = (int) $pdo->query(
        "SELECT COUNT(*) FROM instruments i
         WHERE i.instrument_type = 'etf' AND i.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM daily_prices d WHERE d.instrument_id = i.id)"
    )->fetchColumn();

    $mfNoNav = (int) $pdo->query(
        'SELECT COUNT(*) FROM mutual_fund_schemes s
         WHERE s.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM mutual_fund_nav n WHERE n.scheme_id = s.id)'
    )->fetchColumn();

    $lastDataUpdate = $pdo->query(
        "SELECT MAX(finished_at) FROM sync_jobs
         WHERE status = 'completed'
           AND job_type IN (
             'mf_nav_backfill','mf_nav_daily','equity_ohlcv_csv',
             'equity_daily_upstox','etf_daily_upstox','history_backfill'
           )"
    )->fetchColumn();

    $lastInstrumentImport = $pdo->query(
        "SELECT MAX(finished_at) FROM sync_jobs
         WHERE status = 'completed'
           AND job_type IN ('nse_equity_master','bse_equity_master','etf_master','mf_scheme_master')"
    )->fetchColumn();

    return array_merge($base, [
        'mf_schemes_active' => $mfActive,
        'stocks_without_history' => $stocksNoHistory,
        'etfs_without_history' => $etfsNoHistory,
        'mf_schemes_without_nav' => $mfNoNav,
        'last_data_update_at' => $lastDataUpdate ?: null,
        'last_instrument_import_at' => $lastInstrumentImport ?: null,
        'recent_skipped_records' => 0,
        'recent_failed_records' => 0,
    ]);
}

/** @return array<string,mixed> */
function market_data_center_snapshot_cached(PDO $pdo, int $ttlSeconds = 90): array
{
    $cacheFile = PRIVATE_ROOT . '/logs/market_data_center_snapshot.json';
    if (is_readable($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && isset($cached['expires_at'], $cached['data'])
                && (int) $cached['expires_at'] > time()) {
                return $cached['data'];
            }
        }
    }

    require_once PRIVATE_ROOT . '/services/sync_bundle.php';
    $data = [
        'stats' => market_data_dashboard_stats($pdo),
        'category_counts' => market_data_category_counts($pdo),
        'category_last_sync' => market_data_category_last_sync($pdo),
    ];

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($cacheFile, json_encode([
        'expires_at' => time() + max(30, $ttlSeconds),
        'data' => $data,
    ]));

    return $data;
}

function market_data_center_invalidate_cache(): void
{
    $cacheFile = PRIVATE_ROOT . '/logs/market_data_center_snapshot.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
    $dash = PRIVATE_ROOT . '/logs/dashboard_payload_cache.json';
    if (is_file($dash)) {
        @unlink($dash);
    }
}

/** Symbol shown in lists and headers (never blank when we have a broker key or name). */
function instrument_display_symbol(array $row): string
{
    $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
    if ($sym !== '') {
        return $sym;
    }

    $key = trim((string) ($row['provider_instrument_key'] ?? ''));
    if ($key !== '' && str_contains($key, '|')) {
        $parts = explode('|', $key);
        $tail = strtoupper(trim((string) end($parts)));
        if ($tail !== '' && strlen($tail) <= 24 && !str_starts_with($tail, 'INE')) {
            return $tail;
        }
    }

    $name = trim((string) ($row['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return '—';
}

/** Treasury bills / dated G-Sec style tickers on BSE (e.g. 182T040227, 091TB190919). */
function instrument_is_debt_market_code(string $symbol): bool
{
    $sym = strtoupper(trim($symbol));
    if ($sym === '') {
        return false;
    }
    if (preg_match('/^\d{2,3}TB?\d{6,8}$/', $sym)) {
        return true;
    }
    if (preg_match('/^\d+GOI\d+/i', $sym)) {
        return true;
    }
    if (preg_match('/^GSPL/i', $sym)) {
        return true;
    }
    if (preg_match('/\d+(\.\d+)?%\s*GOI/i', $sym)) {
        return true;
    }

    return false;
}

/**
 * BSE/NSE cash listings that are not operating companies (MF units, GOI bonds, T-Bills, etc.).
 */
function instrument_is_non_equity_cash_listing(string $name, string $symbol, ?string $securityCategory = null): bool
{
    $nameLower = strtolower(trim($name));
    $sym = strtoupper(trim($symbol));

    if (instrument_is_debt_market_code($sym) || instrument_is_debt_market_code($name)) {
        return true;
    }

    if ($nameLower !== '' && str_contains($nameLower, 'mutual fund')) {
        return true;
    }
    if ($nameLower !== '' && preg_match('/\b(liquid fund|income fund|debt fund|gilt fund|bond fund|money market fund|overnight fund|ultra short|corporate bond fund)\b/i', $name)) {
        return true;
    }
    if ($nameLower !== '' && preg_match('/\b(treasury bill|t-bill|g-sec|gsec|government security|state development loan|sdl)\b/i', $name)) {
        return true;
    }
    if ($nameLower !== '' && preg_match('/\d+(\.\d+)?%\s*goi\b/i', $name)) {
        return true;
    }
    if ($sym !== '' && preg_match('/\d+GOI\d+/i', $sym)) {
        return true;
    }
    if ($nameLower !== '' && str_contains($nameLower, 'goi') && str_contains($name, '%')) {
        return true;
    }

    // Upstox often has no company name for debt — symbol copied into name.
    if ($sym !== '' && strcasecmp($sym, trim($name)) === 0 && instrument_is_debt_market_code($sym)) {
        return true;
    }

    return false;
}

/** Extra WHERE fragment so equity lists skip MF units and gov securities mis-tagged as stock. */
function instrument_equity_list_sql_extra(string $instrumentType, string $alias = 'i'): string
{
    if ($instrumentType !== 'stock') {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'i';

    return " AND LOWER({$a}.name) NOT LIKE '%mutual fund%'
              AND LOWER({$a}.name) NOT LIKE '% liquid fund%'
              AND LOWER({$a}.name) NOT LIKE '% debt fund%'
              AND NOT (LOWER({$a}.name) LIKE '%goi%' AND {$a}.name LIKE '%\\%%')
              AND (COALESCE({$a}.symbol, '') = '' OR {$a}.symbol NOT REGEXP '[0-9]+GOI[0-9]')
              AND (COALESCE({$a}.symbol, '') = '' OR {$a}.symbol NOT REGEXP '^[0-9]{2,3}TB?[0-9]{6,8}$')";
}

/** Deactivate instruments that should not appear under Equities. */
function instrument_deactivate_non_equity_cash_listings(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT id, symbol, name, security_category FROM instruments
         WHERE instrument_type = 'stock' AND is_active = 1"
    );
    $deactivated = 0;
    $upd = $pdo->prepare('UPDATE instruments SET is_active = 0, listing_status = :status WHERE id = :id');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!instrument_is_non_equity_cash_listing(
            (string) ($row['name'] ?? ''),
            (string) ($row['symbol'] ?? ''),
            isset($row['security_category']) ? (string) $row['security_category'] : null
        )) {
            continue;
        }
        $upd->execute(['id' => (int) $row['id'], 'status' => 'inactive']);
        $deactivated++;
    }

    if ($deactivated > 0) {
        market_data_center_invalidate_cache();
    }

    return $deactivated;
}
