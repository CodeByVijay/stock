<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/sync_jobs.php';
require_once PRIVATE_ROOT . '/services/mfapi.php';
require_once PRIVATE_ROOT . '/services/upstox.php';
require_once PRIVATE_ROOT . '/services/sync_log.php';
require_once PRIVATE_ROOT . '/services/indicators.php';
require_once PRIVATE_ROOT . '/services/predictions.php';
require_once PRIVATE_ROOT . '/services/backtesting.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once PRIVATE_ROOT . '/services/csv_ohlcv.php';
require_once PRIVATE_ROOT . '/services/sync_bundle.php';
require_once PRIVATE_ROOT . '/services/upstox_instruments.php';
require_once PRIVATE_ROOT . '/services/instrument_sync.php';

/**
 * Execute one claimed sync_jobs row.
 *
 * @param array<string,mixed> $jobRow
 */
function job_runner_execute(PDO $pdo, array $jobRow): void
{
    $jobId = (int) $jobRow['id'];
    $jobType = (string) $jobRow['job_type'];
    $payload = [];
    if (!empty($jobRow['payload_json'])) {
        $decoded = json_decode((string) $jobRow['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $resultMeta = null;
    /** @var list<array{type:string,payload:array<string,mixed>}> */
    $followups = [];

    try {
        switch ($jobType) {
            case 'nse_equity_master':
                $resultMeta = job_run_equity_master_csv($pdo, $jobId, 'NSE', 'stock', 'nse_equity_csv', 'nse_official_csv');
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'bse_equity_master':
                $resultMeta = job_run_equity_master_csv($pdo, $jobId, 'BSE', 'stock', 'bse_equity_csv', 'bse_official_csv');
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'etf_master':
                $resultMeta = job_run_equity_master_csv($pdo, $jobId, 'NSE', 'etf', 'etf_master_csv', 'etf_official_csv');
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'upstox_nse_stocks':
                $resultMeta = job_run_upstox_instrument_import($pdo, $jobId, 'NSE', 'stock');
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'upstox_bse_stocks':
                $resultMeta = job_run_upstox_instrument_import($pdo, $jobId, 'BSE', 'stock');
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'upstox_nse_etfs':
                $resultMeta = job_run_upstox_instrument_import($pdo, $jobId, 'NSE', 'etf');
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'mf_scheme_master':
                $resultMeta = job_run_mf_scheme_master($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'mf_nav_backfill':
                $resultMeta = job_run_mf_nav_backfill($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'mf_nav_daily':
                $resultMeta = job_run_mf_nav_daily($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'equity_ohlcv_csv':
                $resultMeta = job_run_equity_ohlcv_csv($pdo, $jobId);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'equity_daily_upstox':
            case 'stocks':
                $records = job_run_upstox_instruments($pdo, $jobId, 'stock');
                break;
            case 'etf_daily_upstox':
            case 'etfs':
                $records = job_run_upstox_instruments($pdo, $jobId, 'etf');
                break;
            case 'history_backfill':
                $resultMeta = job_run_history_backfill($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'sync_all_available':
                $ids = sync_create_all_jobs($pdo);
                $records = count($ids);
                $resultMeta = ['queued_job_ids' => $ids];
                break;
            case 'sync_stocks':
                $resultMeta = job_run_sync_stocks($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'sync_etfs':
                $resultMeta = job_run_sync_etfs($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'sync_mutual_funds':
                $resultMeta = job_run_sync_mutual_funds($pdo, $jobId, $payload, $followups);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            case 'mutual_funds':
                $records = job_run_mutual_funds($pdo, $jobId);
                break;
            case 'intraday':
                $records = job_run_intraday($pdo, $jobId);
                break;
            case 'recalculate_indicators':
                $records = indicators_recalculate_all($pdo, $jobId);
                break;
            case 'run_screener':
                $records = job_run_screener($pdo, $jobId, $payload);
                break;
            case 'run_backtest':
                $records = job_run_backtest($pdo, $jobId, $payload);
                break;
            case 'instrument_ohlcv_sync':
                $resultMeta = job_run_instrument_ohlcv_sync($pdo, $jobId, $payload);
                $records = (int) ($resultMeta['records'] ?? 0);
                break;
            default:
                throw new RuntimeException('Unknown job type.');
        }

        sync_complete_job($pdo, $jobId, $records, $resultMeta);
        market_data_center_invalidate_cache();
        foreach ($followups as $fu) {
            sync_queue_followup($pdo, $fu['type'], $fu['payload']);
        }
        sync_log('worker', 'job_completed', ['job_id' => $jobId, 'job_type' => $jobType, 'records' => $records]);
    } catch (Throwable $e) {
        $retry = job_is_transient_error($e);
        sync_fail_job($pdo, $jobId, $e->getMessage(), $retry);
        sync_log('worker', 'job_failed', ['job_id' => $jobId, 'job_type' => $jobType, 'retry' => $retry, 'message' => $e->getMessage()]);
    }
}

function job_is_transient_error(Throwable $e): bool
{
    $msg = strtolower($e->getMessage());
    if (strpos($msg, 'rate limit') !== false || strpos($msg, 'http 429') !== false || strpos($msg, 'server error') !== false) {
        return true;
    }

    return $e instanceof PDOException;
}

function job_run_not_ready(PDO $pdo, int $jobId, string $message): int
{
    sync_update_progress($pdo, $jobId, 0, 0);
    throw new RuntimeException($message);
}

function job_run_intraday(PDO $pdo, int $jobId): int
{
    $cfg = sync_config();
    if (empty($cfg['intraday_enabled'])) {
        throw new RuntimeException('Intraday sync is disabled. Set intraday_enabled in private/config/sync.php when Upstox intraday access is confirmed.');
    }

    throw new RuntimeException('Intraday candle sync is not implemented yet.');
}

function job_run_mutual_funds(PDO $pdo, int $jobId): int
{
    $cfg = sync_config();
    $codes = $cfg['mf_scheme_codes'] ?? [];
    if (!is_array($codes) || $codes === []) {
        throw new RuntimeException('No mf_scheme_codes configured in private/config/sync.php.');
    }

    $days = max(1, (int) ($cfg['mf_history_days'] ?? 90));
    $end = new DateTimeImmutable('today');
    $start = $end->modify('-' . $days . ' days');

    $total = count($codes);
    sync_update_progress($pdo, $jobId, 0, $total);

    $records = 0;
    $done = 0;
    foreach ($codes as $code) {
        $schemeCode = (int) $code;
        if ($schemeCode <= 0) {
            $done++;
            sync_update_progress($pdo, $jobId, $done, $total);
            continue;
        }

        $result = mfapi_sync_scheme(
            $pdo,
            $schemeCode,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            false
        );

        if (!$result['ok']) {
            throw new RuntimeException('MF sync failed for scheme ' . $schemeCode . ': ' . ($result['error'] ?? 'Unknown'));
        }

        $stats = $result['stats'] ?? [];
        $records += (int) ($stats['inserted'] ?? 0) + (int) ($stats['updated'] ?? 0);
        $done++;
        sync_update_progress($pdo, $jobId, $done, $total);
    }

    return $records;
}

function job_run_upstox_instruments(PDO $pdo, int $jobId, string $instrumentType): int
{
    if (upstox_access_token() === '') {
        throw new RuntimeException('Upstox access token is not configured.');
    }

    $auth = upstox_verify_authentication();
    if (!$auth['ok']) {
        throw new RuntimeException('Upstox authentication failed: ' . ($auth['error'] ?? 'Unknown'));
    }

    $cfg = sync_config();
    $days = max(1, (int) ($cfg['upstox_history_days'] ?? 90));
    $toDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $fromDate = (new DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT id, symbol, name, exchange, provider_instrument_key
         FROM instruments
         WHERE instrument_type = :type AND provider = 'upstox' AND is_active = 1
           AND provider_instrument_key IS NOT NULL AND provider_instrument_key != ''"
    );
    $stmt->execute(['type' => $instrumentType]);
    $rows = $stmt->fetchAll();

    $total = count($rows);
    sync_update_progress($pdo, $jobId, 0, max(1, $total));

    if ($total === 0) {
        sync_update_progress($pdo, $jobId, 0, 0);
        return 0;
    }

    $records = 0;
    $done = 0;
    foreach ($rows as $row) {
        $key = (string) $row['provider_instrument_key'];
        $result = upstox_sync_daily_candles(
            $pdo,
            $key,
            (string) $row['symbol'],
            (string) $row['name'],
            (string) ($row['exchange'] ?? 'NSE'),
            $instrumentType,
            $toDate,
            $fromDate
        );

        if (!$result['ok']) {
            throw new RuntimeException(
                'Upstox sync failed for ' . $row['symbol'] . ': ' . ($result['error'] ?? 'Unknown')
            );
        }

        $stats = $result['stats'] ?? [];
        $records += (int) ($stats['inserted'] ?? 0) + (int) ($stats['updated'] ?? 0);
        $done++;
        sync_update_progress($pdo, $jobId, $done, $total);
    }

    return $records;
}

/** @param array<string,mixed> $payload */
function job_run_screener(PDO $pdo, int $jobId, array $payload): int
{
    $runId = (int) ($payload['prediction_run_id'] ?? 0);
    if ($runId <= 0) {
        throw new RuntimeException('Missing prediction_run_id. Start a run from the Predictions page.');
    }

    try {
        return prediction_run($pdo, $runId, $jobId);
    } catch (Throwable $e) {
        prediction_mark_run_failed($pdo, $runId, $e->getMessage());
        throw $e;
    }
}

/** @return array<string,mixed> */
function job_run_upstox_instrument_import(PDO $pdo, int $jobId, string $exchange, string $assetKind): array
{
    $stats = upstox_import_instruments($pdo, $exchange, $assetKind, $jobId);
    $deactivatedNonEquity = 0;
    if ($assetKind === 'stock') {
        $deactivatedNonEquity = instrument_deactivate_non_equity_cash_listings($pdo);
    }
    $withKeys = instrument_count_with_upstox_key($pdo, $assetKind);

    return [
        'records' => $stats['inserted'] + $stats['updated'],
        'discovered' => $stats['discovered'],
        'inserted' => $stats['inserted'],
        'updated' => $stats['updated'],
        'skipped' => $stats['skipped'],
        'failed' => $stats['failed'],
        'errors' => $stats['errors'],
        'exchange' => $stats['exchange'],
        'asset_kind' => $stats['asset_kind'],
        'instruments_with_upstox_key' => $withKeys,
        'notes' => array_values(array_filter([
            'Upstox BOD JSON import (' . $exchange . ' ' . $assetKind . ').',
            'File rows: ' . $stats['discovered'] . '; imported: ' . ($stats['inserted'] + $stats['updated']) . '.',
            'Skipped: ' . $stats['skipped'] . '.',
            'Active ' . $assetKind . ' instruments with Upstox keys: ' . $withKeys . '.',
            $deactivatedNonEquity > 0
                ? 'Removed ' . $deactivatedNonEquity . ' mutual-fund / bond rows from the equity list.'
                : null,
        ])),
        'skip_reasons' => $stats['skip_reasons'] ?? [],
        'file_summary' => $stats['file_summary'] ?? [],
    ];
}

/** @param array<string,mixed> $payload */
/** @return array<string,mixed> */
function job_run_equity_master_csv(
    PDO $pdo,
    int $jobId,
    string $exchange,
    string $defaultType,
    string $cfgKey,
    string $sourceLabel
): array {
    $cfg = providers_config();
    $path = trim((string) ($cfg[$cfgKey] ?? ''));
    if ($path === '') {
        throw new RuntimeException(
            'Set private/config/providers.php → ' . $cfgKey . ' to your downloaded CSV path. See private/docs/INSTRUMENT_SOURCES.md.'
        );
    }

    sync_update_progress($pdo, $jobId, 0, 1);
    $stats = instrument_import_equity_csv($pdo, $path, $exchange, $defaultType, $sourceLabel);
    sync_update_progress($pdo, $jobId, 1, 1);

    if (($stats['inserted'] + $stats['updated']) === 0) {
        throw new RuntimeException(
            'CSV import added/updated 0 instruments from ' . $path
            . ' (rows read: ' . ($stats['discovered'] ?? 0) . '). Check headers and file content.'
        );
    }

    return [
        'records' => $stats['inserted'] + $stats['updated'],
        'discovered' => $stats['discovered'],
        'inserted' => $stats['inserted'],
        'updated' => $stats['updated'],
        'skipped' => $stats['skipped'],
        'errors' => $stats['errors'],
        'source_path' => $path,
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_mf_scheme_master(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    $cfg = providers_config();
    $pageSize = max(1, (int) ($cfg['mfapi_page_size'] ?? 100));
    $offset = max(0, (int) ($payload['offset'] ?? 0));

    sync_update_progress($pdo, $jobId, 0, 1);
    $page = mfapi_sync_scheme_master_page($pdo, $offset, $pageSize);
    sync_update_progress($pdo, $jobId, 1, 1);

    $records = $page['inserted'] + $page['updated'];
    $hasMore = $page['discovered'] >= $pageSize;
    if ($hasMore) {
        $followups[] = ['type' => 'mf_scheme_master', 'payload' => ['offset' => $offset + $pageSize]];
    }

    return [
        'records' => $records,
        'offset' => $offset,
        'page_size' => $pageSize,
        'discovered' => $page['discovered'],
        'inserted' => $page['inserted'],
        'updated' => $page['updated'],
        'skipped' => $page['skipped'],
        'complete' => !$hasMore,
        'next_offset' => $hasMore ? $offset + $pageSize : null,
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_mf_nav_backfill(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    $cfg = providers_config();
    $batch = max(1, (int) ($cfg['mf_nav_backfill_batch'] ?? 25));
    $days = max(1, (int) ($cfg['mf_nav_backfill_days'] ?? 365));
    $offset = max(0, (int) ($payload['offset'] ?? 0));

    $batchResult = mfapi_nav_backfill_batch($pdo, $offset, $batch, $days, $jobId);
    if (!$batchResult['complete']) {
        $followups[] = ['type' => 'mf_nav_backfill', 'payload' => ['offset' => $batchResult['offset']]];
    }

    return [
        'records' => $batchResult['processed'],
        'failed' => $batchResult['failed'],
        'offset' => $offset,
        'next_offset' => $batchResult['complete'] ? null : $batchResult['offset'],
        'complete' => $batchResult['complete'],
        'history_days' => $days,
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_mf_nav_daily(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    $cfg = providers_config();
    $batch = max(1, (int) ($cfg['mf_nav_backfill_batch'] ?? 25));
    $days = max(1, (int) ($cfg['mf_nav_daily_days'] ?? 14));
    $offset = max(0, (int) ($payload['offset'] ?? 0));

    $batchResult = mfapi_nav_backfill_batch($pdo, $offset, $batch, $days, $jobId);
    if (!$batchResult['complete']) {
        $followups[] = ['type' => 'mf_nav_daily', 'payload' => ['offset' => $batchResult['offset']]];
    }

    return [
        'records' => $batchResult['processed'],
        'failed' => $batchResult['failed'],
        'offset' => $offset,
        'next_offset' => $batchResult['complete'] ? null : $batchResult['offset'],
        'complete' => $batchResult['complete'],
        'history_days' => $days,
    ];
}

/** @return array<string,mixed> */
function job_run_equity_ohlcv_csv(PDO $pdo, int $jobId): array
{
    $cfg = providers_config();
    $path = trim((string) ($cfg['equity_ohlcv_csv'] ?? ''));
    if ($path === '') {
        throw new RuntimeException('Set private/config/providers.php → equity_ohlcv_csv to import OHLCV from CSV.');
    }

    sync_update_progress($pdo, $jobId, 0, 1);
    $stats = csv_import_ohlcv($pdo, $path);
    sync_update_progress($pdo, $jobId, 1, 1);

    return [
        'records' => $stats['inserted'] + $stats['updated'],
        'discovered' => $stats['discovered'],
        'inserted' => $stats['inserted'],
        'updated' => $stats['updated'],
        'skipped' => $stats['skipped'],
        'errors' => $stats['errors'],
    ];
}

/**
 * @param array<string,mixed> $payload
 * @param list<array{type:string,payload:array<string,mixed>}> $followups
 * @return array<string,mixed>
 */
function job_run_history_backfill(PDO $pdo, int $jobId, array $payload, array &$followups): array
{
    if (upstox_access_token() === '') {
        throw new RuntimeException('Upstox access token is not configured — use equity_ohlcv_csv or configure Upstox.');
    }

    $cfg = providers_config();
    $batch = max(1, (int) ($cfg['equity_backfill_batch'] ?? 20));
    $offset = max(0, (int) ($payload['offset'] ?? 0));
    $syncCfg = sync_config();
    $days = max(1, (int) ($syncCfg['upstox_history_days'] ?? 90));
    $toDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $fromDate = (new DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT i.id, i.symbol, i.name, i.exchange, i.provider_instrument_key, i.instrument_type
         FROM instruments i
         LEFT JOIN instrument_data_coverage c ON c.instrument_id = i.id
         WHERE i.is_active = 1 AND i.instrument_type IN ('stock', 'etf')
           AND i.provider = 'upstox'
           AND i.provider_instrument_key IS NOT NULL AND i.provider_instrument_key != ''
           AND (c.instrument_id IS NULL OR c.bar_count < :minbars)
         ORDER BY i.id ASC
         LIMIT :lim OFFSET :off"
    );
    $minBars = max(5, (int) ($payload['min_bars'] ?? 30));
    $stmt->bindValue('minbars', $minBars, PDO::PARAM_INT);
    $stmt->bindValue('lim', $batch, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        sync_update_progress($pdo, $jobId, 0, 0);
        return ['records' => 0, 'complete' => true, 'offset' => $offset];
    }

    $records = 0;
    $failed = 0;
    $done = 0;
    $total = count($rows);
    sync_update_progress($pdo, $jobId, 0, $total);

    foreach ($rows as $row) {
        $done++;
        sync_update_progress($pdo, $jobId, $done, $total);
        $result = upstox_sync_daily_candles(
            $pdo,
            (string) $row['provider_instrument_key'],
            (string) $row['symbol'],
            (string) $row['name'],
            (string) ($row['exchange'] ?? 'NSE'),
            (string) $row['instrument_type'],
            $toDate,
            $fromDate
        );
        if (!$result['ok']) {
            $failed++;
            continue;
        }
        $stats = $result['stats'] ?? [];
        $records += (int) ($stats['inserted'] ?? 0) + (int) ($stats['updated'] ?? 0);
        instrument_refresh_coverage($pdo, (int) $row['id']);
    }

    $complete = count($rows) < $batch;
    if (!$complete) {
        $followups[] = [
            'type' => 'history_backfill',
            'payload' => ['offset' => $offset + $batch, 'min_bars' => $minBars],
        ];
    }

    return [
        'records' => $records,
        'failed' => $failed,
        'offset' => $offset,
        'next_offset' => $complete ? null : $offset + $batch,
        'complete' => $complete,
    ];
}

/** @param array<string,mixed> $payload */
function job_run_backtest(PDO $pdo, int $jobId, array $payload): int
{
    $runId = (int) ($payload['backtest_run_id'] ?? 0);
    if ($runId <= 0) {
        throw new RuntimeException('Missing backtest_run_id. Start a backtest from the Backtesting page.');
    }

    try {
        return backtest_run($pdo, $runId, $jobId);
    } catch (Throwable $e) {
        backtest_mark_failed($pdo, $runId, $e->getMessage());
        throw $e;
    }
}
