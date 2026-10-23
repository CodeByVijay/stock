<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once PRIVATE_ROOT . '/services/mfapi.php';
require_once PRIVATE_ROOT . '/services/upstox.php';
require_once PRIVATE_ROOT . '/services/upstox_instruments.php';

const SYNC_CATEGORY_JOB_TYPES = [
    'stocks' => 'sync_stocks',
    'etfs' => 'sync_etfs',
    'mutual_funds' => 'sync_mutual_funds',
];

/** @return array{inserted:int,updated:int,skipped:int,failed:int,discovered:int} */
function sync_bundle_empty_totals(): array
{
    return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'discovered' => 0];
}

/** @param array<string,mixed> $base @param array<string,mixed> $add */
function sync_bundle_merge_totals(array $base, array $add): array
{
    $keys = ['inserted', 'updated', 'skipped', 'failed', 'discovered'];
    $out = sync_bundle_empty_totals();
    foreach ($keys as $k) {
        $out[$k] = (int) ($base[$k] ?? 0) + (int) ($add[$k] ?? 0);
    }

    return $out;
}

/** @param array<string,mixed> $totals @param list<string> $notes */
function sync_bundle_build_result(array $totals, array $notes, string $category, ?string $phase = null): array
{
    $records = (int) $totals['inserted'] + (int) $totals['updated'];

    return array_merge($totals, [
        'records' => $records,
        'category' => $category,
        'phase' => $phase,
        'notes' => $notes,
    ]);
}

function sync_latest_market_data_days(): int
{
    $cfg = sync_config();

    return max(1, (int) ($cfg['latest_market_data_days'] ?? 14));
}

function sync_bundle_price_batch_size(): int
{
    $cfg = sync_config();

    return max(10, min(100, (int) ($cfg['equity_price_batch'] ?? 30)));
}

function sync_bundle_count_upstox_price_instruments(PDO $pdo, string $instrumentType): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM instruments
         WHERE instrument_type = :type AND is_active = 1
           AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''"
    );
    $stmt->execute(['type' => $instrumentType]);

    return (int) $stmt->fetchColumn();
}

/**
 * One batch of latest Upstox prices (resumable via offset).
 *
 * @return array{inserted:int,updated:int,skipped:int,failed:int,records:int,total:int,processed:int,next_offset:int,complete:bool,notes:list<string>}
 */
function sync_bundle_upstox_latest_batch(PDO $pdo, int $jobId, string $instrumentType, int $offset, int $limit): array
{
    $totals = sync_bundle_empty_totals();
    $notes = [];

    if (upstox_access_token() === '') {
        return array_merge($totals, [
            'records' => 0,
            'total' => 0,
            'processed' => 0,
            'next_offset' => $offset,
            'complete' => true,
            'notes' => ['Latest prices skipped: brokerage API is not connected.'],
        ]);
    }

    $auth = upstox_verify_authentication();
    if (!$auth['ok']) {
        return array_merge($totals, [
            'records' => 0,
            'total' => 0,
            'processed' => 0,
            'next_offset' => $offset,
            'complete' => true,
            'notes' => ['Latest prices skipped: ' . ($auth['error'] ?? 'broker auth failed')],
        ]);
    }

    $total = sync_bundle_count_upstox_price_instruments($pdo, $instrumentType);
    $offset = max(0, $offset);
    $limit = max(1, $limit);

    sync_update_progress($pdo, $jobId, min($offset, max(1, $total)), max(1, $total));

    if ($total === 0) {
        return array_merge($totals, [
            'records' => 0,
            'total' => 0,
            'processed' => 0,
            'next_offset' => 0,
            'complete' => true,
            'notes' => ['No instruments are linked for automatic price download yet.'],
        ]);
    }

    if ($offset >= $total) {
        return array_merge($totals, [
            'records' => 0,
            'total' => $total,
            'processed' => 0,
            'next_offset' => $total,
            'complete' => true,
            'notes' => [],
        ]);
    }

    $days = sync_latest_market_data_days();
    $toDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $fromDate = (new DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT id, symbol, name, exchange, provider_instrument_key
         FROM instruments
         WHERE instrument_type = :type AND is_active = 1
           AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''
         ORDER BY id ASC
         LIMIT :lim OFFSET :off"
    );
    $stmt->bindValue('type', $instrumentType);
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $processed = 0;
    foreach ($rows as $row) {
        $processed++;
        $done = $offset + $processed;
        sync_update_progress($pdo, $jobId, $done, $total);
        if ($processed % 10 === 0) {
            sync_extend_job_lease($pdo, $jobId);
        }
        $result = upstox_sync_daily_candles(
            $pdo,
            (string) $row['provider_instrument_key'],
            (string) $row['symbol'],
            (string) $row['name'],
            (string) ($row['exchange'] ?? 'NSE'),
            $instrumentType,
            $toDate,
            $fromDate
        );
        if (!$result['ok']) {
            $totals['failed']++;
            continue;
        }
        $stats = $result['stats'] ?? [];
        $totals['inserted'] += (int) ($stats['inserted'] ?? 0);
        $totals['updated'] += (int) ($stats['updated'] ?? 0);
        instrument_refresh_coverage($pdo, (int) $row['id']);
    }

    $nextOffset = $offset + $processed;
    $totals['records'] = $totals['inserted'] + $totals['updated'];

    return array_merge($totals, [
        'total' => $total,
        'processed' => $processed,
        'next_offset' => $nextOffset,
        'complete' => $nextOffset >= $total,
        'notes' => $notes,
    ]);
}

/** @return array{inserted:int,updated:int,skipped:int,failed:int,discovered:int,records:int} */
function sync_bundle_upstox_latest(PDO $pdo, int $jobId, string $instrumentType): array
{
    $totals = sync_bundle_empty_totals();
    if (upstox_access_token() === '') {
        return array_merge($totals, [
            'records' => 0,
            'notes' => ['Latest prices skipped: brokerage API is not connected.'],
        ]);
    }

    $auth = upstox_verify_authentication();
    if (!$auth['ok']) {
        return array_merge($totals, [
            'records' => 0,
            'notes' => ['Latest prices skipped: ' . ($auth['error'] ?? 'broker auth failed')],
        ]);
    }

    $days = sync_latest_market_data_days();
    $toDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $fromDate = (new DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT id, symbol, name, exchange, provider_instrument_key
         FROM instruments
         WHERE instrument_type = :type AND is_active = 1
           AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''"
    );
    $stmt->execute(['type' => $instrumentType]);
    $rows = $stmt->fetchAll();

    $total = count($rows);
    sync_update_progress($pdo, $jobId, 0, max(1, $total));

    if ($total === 0) {
        sync_update_progress($pdo, $jobId, 0, 0);

        return array_merge($totals, [
            'records' => 0,
            'notes' => ['No instruments are linked for automatic price download yet.'],
        ]);
    }

    $done = 0;
    foreach ($rows as $row) {
        $done++;
        sync_update_progress($pdo, $jobId, $done, $total);
        if ($done % 25 === 0) {
            sync_extend_job_lease($pdo, $jobId);
        }
        $result = upstox_sync_daily_candles(
            $pdo,
            (string) $row['provider_instrument_key'],
            (string) $row['symbol'],
            (string) $row['name'],
            (string) ($row['exchange'] ?? 'NSE'),
            $instrumentType,
            $toDate,
            $fromDate
        );
        if (!$result['ok']) {
            $totals['failed']++;
            continue;
        }
        $stats = $result['stats'] ?? [];
        $totals['inserted'] += (int) ($stats['inserted'] ?? 0);
        $totals['updated'] += (int) ($stats['updated'] ?? 0);
        instrument_refresh_coverage($pdo, (int) $row['id']);
    }

    $totals['records'] = $totals['inserted'] + $totals['updated'];

    return $totals;
}

/**
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_sync_stocks(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    $cfg = providers_config();
    $priceOffset = max(0, (int) ($payload['price_offset'] ?? 0));
    $importsDone = !empty($payload['imports_done']);
    $totals = is_array($payload['totals'] ?? null)
        ? sync_bundle_merge_totals(sync_bundle_empty_totals(), $payload['totals'])
        : sync_bundle_empty_totals();
    $notes = is_array($payload['notes'] ?? null) ? $payload['notes'] : [];

    if (!$importsDone && $priceOffset === 0) {
        $nsePath = trim((string) ($cfg['nse_equity_csv'] ?? ''));
        if ($nsePath !== '') {
            $stats = instrument_import_equity_csv($pdo, $nsePath, 'NSE', 'stock', 'nse_official_csv');
            $totals = sync_bundle_merge_totals($totals, $stats);
        } else {
            $notes[] = 'NSE stock list file is not configured; skipped NSE import.';
        }

        $bsePath = trim((string) ($cfg['bse_equity_csv'] ?? ''));
        if ($bsePath !== '') {
            $stats = instrument_import_equity_csv($pdo, $bsePath, 'BSE', 'stock', 'bse_official_csv');
            $totals = sync_bundle_merge_totals($totals, $stats);
        } else {
            $notes[] = 'BSE stock list file is not configured; skipped BSE import.';
        }
        $importsDone = true;
    }

    if (trim((string) ($cfg['nse_equity_csv'] ?? '')) === '' && trim((string) ($cfg['bse_equity_csv'] ?? '')) === '' && upstox_access_token() === '') {
        throw new RuntimeException(
            'Cannot sync stocks: configure NSE/BSE CSV paths, import via Upstox instrument master (Market Data Center), and/or connect the brokerage API.'
        );
    }

    $stockTotal = sync_bundle_active_instrument_count($pdo, 'stock');
    if ($stockTotal === 0) {
        throw new RuntimeException(
            'No stock instruments in the database. Import NSE/BSE lists first: use “Import NSE stocks (Upstox)” / “Import BSE stocks (Upstox)” or set nse_equity_csv / bse_equity_csv in providers.php.'
        );
    }

    $batchSize = sync_bundle_price_batch_size();
    $priceStats = sync_bundle_upstox_latest_batch($pdo, $jobId, 'stock', $priceOffset, $batchSize);
    if (!empty($priceStats['notes'])) {
        $notes = array_merge($notes, $priceStats['notes']);
    }
    $totals['inserted'] += (int) ($priceStats['inserted'] ?? 0);
    $totals['updated'] += (int) ($priceStats['updated'] ?? 0);
    $totals['failed'] += (int) ($priceStats['failed'] ?? 0);

    $totalInstruments = (int) ($priceStats['total'] ?? 0);
    $nextOffset = (int) ($priceStats['next_offset'] ?? $priceOffset);
    if (empty($priceStats['complete']) && $totalInstruments > $nextOffset) {
        $followups[] = [
            'type' => 'sync_stocks',
            'payload' => [
                'price_offset' => $nextOffset,
                'imports_done' => true,
                'totals' => $totals,
                'notes' => $notes,
            ],
        ];
        $notes[] = 'Share prices batch done (' . $nextOffset . '/' . $totalInstruments . ') — next batch queued automatically.';
    } elseif ($nextOffset >= $totalInstruments && $totalInstruments > 0) {
        $notes[] = 'All linked share prices updated for this run.';
    }

    $withKeys = instrument_count_with_upstox_key($pdo, 'stock');
    if ($withKeys === 0) {
        $notes[] = 'No stocks have Upstox instrument keys; latest price download cannot run until keys are imported (Upstox master or CSV with keys).';
    }

    return sync_bundle_build_result($totals, $notes, 'stocks');
}

/**
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_sync_etfs(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    $cfg = providers_config();
    $priceOffset = max(0, (int) ($payload['price_offset'] ?? 0));
    $importsDone = !empty($payload['imports_done']);
    $totals = is_array($payload['totals'] ?? null)
        ? sync_bundle_merge_totals(sync_bundle_empty_totals(), $payload['totals'])
        : sync_bundle_empty_totals();
    $notes = is_array($payload['notes'] ?? null) ? $payload['notes'] : [];
    $etfPath = trim((string) ($cfg['etf_master_csv'] ?? ''));

    if (!$importsDone && $priceOffset === 0) {
        if ($etfPath !== '') {
            $stats = instrument_import_equity_csv($pdo, $etfPath, 'NSE', 'etf', 'etf_official_csv');
            $totals = sync_bundle_merge_totals($totals, $stats);
        } else {
            $notes[] = 'ETF CSV file is not configured; skipped CSV import.';
        }

        if (sync_bundle_active_instrument_count($pdo, 'etf') === 0 && upstox_access_token() !== '') {
            try {
                $importStats = upstox_import_instruments($pdo, 'NSE', 'etf', $jobId);
                $totals = sync_bundle_merge_totals($totals, [
                    'inserted' => (int) ($importStats['inserted'] ?? 0),
                    'updated' => (int) ($importStats['updated'] ?? 0),
                    'skipped' => (int) ($importStats['skipped'] ?? 0),
                    'failed' => (int) ($importStats['failed'] ?? 0),
                    'discovered' => (int) ($importStats['discovered'] ?? 0),
                ]);
                $notes[] = 'Imported NSE ETF list from Upstox ('
                    . ((int) ($importStats['inserted'] ?? 0) + (int) ($importStats['updated'] ?? 0))
                    . ' rows).';
            } catch (Throwable $e) {
                $notes[] = 'Upstox NSE ETF import failed: ' . $e->getMessage();
            }
        }
        $importsDone = true;
    }

    if ($etfPath === '' && upstox_access_token() === '') {
        throw new RuntimeException(
            'Cannot sync ETFs: configure etf_master_csv, import via Upstox (Market Data Center), and/or connect the brokerage API.'
        );
    }

    $etfTotal = sync_bundle_active_instrument_count($pdo, 'etf');
    if ($etfTotal === 0) {
        throw new RuntimeException(
            'No ETF instruments in the database. Use “Download NSE ETF list” under One-time setup, or run Update ETFs again after Upstox is connected.'
        );
    }

    $batchSize = sync_bundle_price_batch_size();
    $priceStats = sync_bundle_upstox_latest_batch($pdo, $jobId, 'etf', $priceOffset, $batchSize);
    if (!empty($priceStats['notes'])) {
        $notes = array_merge($notes, $priceStats['notes']);
    }
    $totals['inserted'] += (int) ($priceStats['inserted'] ?? 0);
    $totals['updated'] += (int) ($priceStats['updated'] ?? 0);
    $totals['failed'] += (int) ($priceStats['failed'] ?? 0);

    $totalInstruments = (int) ($priceStats['total'] ?? 0);
    $nextOffset = (int) ($priceStats['next_offset'] ?? $priceOffset);
    if (empty($priceStats['complete']) && $totalInstruments > $nextOffset) {
        $followups[] = [
            'type' => 'sync_etfs',
            'payload' => [
                'price_offset' => $nextOffset,
                'imports_done' => true,
                'totals' => $totals,
                'notes' => $notes,
            ],
        ];
        $notes[] = 'ETF price batch done (' . $nextOffset . '/' . $totalInstruments . ') — next batch queued.';
    }

    $withKeys = instrument_count_with_upstox_key($pdo, 'etf');
    if ($withKeys === 0) {
        $notes[] = 'No ETFs have Upstox instrument keys; latest price download cannot run until keys are imported.';
    }

    return sync_bundle_build_result($totals, $notes, 'etfs');
}

function sync_bundle_active_instrument_count(PDO $pdo, string $instrumentType): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM instruments WHERE instrument_type = :type AND is_active = 1'
    );
    $stmt->execute(['type' => $instrumentType]);

    return (int) $stmt->fetchColumn();
}

/**
 * Resumable MF sync: scheme pages then latest NAV batches (not full history backfill).
 *
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_sync_mutual_funds(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    $cfg = providers_config();
    $pageSize = max(1, (int) ($cfg['mfapi_page_size'] ?? 100));
    $navBatch = max(1, (int) ($cfg['mf_nav_backfill_batch'] ?? 25));
    $navDays = sync_latest_market_data_days();

    $phase = (string) ($payload['phase'] ?? 'schemes');
    $schemeOffset = max(0, (int) ($payload['scheme_offset'] ?? 0));
    $navOffset = max(0, (int) ($payload['nav_offset'] ?? 0));
    $totals = is_array($payload['totals'] ?? null) ? $payload['totals'] : sync_bundle_empty_totals();
    $notes = is_array($payload['notes'] ?? null) ? $payload['notes'] : [];

    if ($phase === 'schemes') {
        sync_update_progress($pdo, $jobId, 0, 1);
        $page = mfapi_sync_scheme_master_page($pdo, $schemeOffset, $pageSize);
        $totals = sync_bundle_merge_totals($totals, $page);
        sync_update_progress($pdo, $jobId, 1, 1);

        $hasMoreSchemes = $page['discovered'] >= $pageSize;
        if ($hasMoreSchemes) {
            $followups[] = [
                'type' => 'sync_mutual_funds',
                'payload' => [
                    'phase' => 'schemes',
                    'scheme_offset' => $schemeOffset + $pageSize,
                    'nav_offset' => 0,
                    'totals' => $totals,
                    'notes' => $notes,
                ],
            ];

            return sync_bundle_build_result($totals, $notes, 'mutual_funds', 'schemes');
        }

        $followups[] = [
            'type' => 'sync_mutual_funds',
            'payload' => [
                'phase' => 'nav',
                'scheme_offset' => 0,
                'nav_offset' => 0,
                'totals' => $totals,
                'notes' => $notes,
            ],
        ];

        return sync_bundle_build_result($totals, $notes, 'mutual_funds', 'schemes');
    }

    $batchResult = mfapi_nav_backfill_batch($pdo, $navOffset, $navBatch, $navDays, $jobId);
    $totals['failed'] += (int) ($batchResult['failed'] ?? 0);
    $totals['inserted'] += (int) ($batchResult['processed'] ?? 0);
    $totals['updated'] += 0;

    if (!$batchResult['complete']) {
        $followups[] = [
            'type' => 'sync_mutual_funds',
            'payload' => [
                'phase' => 'nav',
                'scheme_offset' => 0,
                'nav_offset' => (int) $batchResult['offset'],
                'totals' => $totals,
                'notes' => $notes,
            ],
        ];
    }

    return sync_bundle_build_result($totals, $notes, 'mutual_funds', $batchResult['complete'] ? 'done' : 'nav');
}

/** @return array<string,bool> */
function sync_active_categories(PDO $pdo): array
{
    $types = array_values(SYNC_CATEGORY_JOB_TYPES);
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = $pdo->prepare(
        "SELECT job_type FROM sync_jobs
         WHERE job_type IN ($placeholders) AND status IN ('queued', 'running')
         LIMIT 10"
    );
    $stmt->execute($types);
    $active = ['stocks' => false, 'etfs' => false, 'mutual_funds' => false];
    while ($row = $stmt->fetch()) {
        $type = (string) $row['job_type'];
        if ($type === 'sync_stocks') {
            $active['stocks'] = true;
        } elseif ($type === 'sync_etfs') {
            $active['etfs'] = true;
        } elseif ($type === 'sync_mutual_funds') {
            $active['mutual_funds'] = true;
        }
    }

    return $active;
}

function sync_category_for_job_type(string $jobType): ?string
{
    foreach (SYNC_CATEGORY_JOB_TYPES as $cat => $type) {
        if ($type === $jobType) {
            return $cat;
        }
    }

    return null;
}

/** @return array<string,?string> */
function market_data_category_last_sync(PDO $pdo): array
{
    $out = ['stocks' => null, 'etfs' => null, 'mutual_funds' => null];
    $stmt = $pdo->query(
        "SELECT job_type, MAX(finished_at) AS last_at
         FROM sync_jobs
         WHERE status = 'completed' AND job_type IN ('sync_stocks','sync_etfs','sync_mutual_funds')
         GROUP BY job_type"
    );
    while ($row = $stmt->fetch()) {
        $type = (string) $row['job_type'];
        $cat = sync_category_for_job_type($type);
        if ($cat !== null) {
            $out[$cat] = $row['last_at'] ? (string) $row['last_at'] : null;
        }
    }

    return $out;
}

/** @return array<string,int> */
function market_data_category_counts(PDO $pdo): array
{
    $stocksWith = (int) $pdo->query(
        "SELECT COUNT(DISTINCT d.instrument_id) FROM daily_prices d
         INNER JOIN instruments i ON i.id = d.instrument_id AND i.instrument_type = 'stock'"
    )->fetchColumn();
    $etfsWith = (int) $pdo->query(
        "SELECT COUNT(DISTINCT d.instrument_id) FROM daily_prices d
         INNER JOIN instruments i ON i.id = d.instrument_id AND i.instrument_type = 'etf'"
    )->fetchColumn();
    $mfWith = (int) $pdo->query('SELECT COUNT(DISTINCT scheme_id) FROM mutual_fund_nav')->fetchColumn();
    $mfTotal = (int) $pdo->query(
        'SELECT COUNT(*) FROM mutual_fund_schemes WHERE is_active = 1'
    )->fetchColumn();

    return [
        'stocks_total' => (int) $pdo->query("SELECT COUNT(*) FROM instruments WHERE instrument_type='stock' AND is_active=1")->fetchColumn(),
        'stocks_with_prices' => $stocksWith,
        'etfs_total' => (int) $pdo->query("SELECT COUNT(*) FROM instruments WHERE instrument_type='etf' AND is_active=1")->fetchColumn(),
        'etfs_with_prices' => $etfsWith,
        'mf_total' => $mfTotal,
        'mf_with_nav' => $mfWith,
    ];
}
