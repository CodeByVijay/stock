<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/research.php';
require_once PRIVATE_ROOT . '/services/upstox.php';
require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once PRIVATE_ROOT . '/services/sync_jobs.php';

/**
 * @return array{ok:bool,error:?string,message:string,candles_received:int,stats:array,bars_stored:int,last_trade_date:?string,last_close:?float}
 */
function instrument_sync_prices_from_upstox(PDO $pdo, int $instrumentId, string $mode): array
{
    $instrument = research_get_instrument($pdo, $instrumentId);
    if (!$instrument) {
        return ['ok' => false, 'error' => 'Instrument not found.', 'message' => '', 'candles_received' => 0, 'stats' => [], 'bars_stored' => 0, 'last_trade_date' => null, 'last_close' => null];
    }

    $key = trim((string) ($instrument['provider_instrument_key'] ?? ''));
    if ($key === '') {
        return [
            'ok' => false,
            'error' => 'Missing broker feed key. Import share list from Download Data first.',
            'message' => '',
            'candles_received' => 0,
            'stats' => [],
            'bars_stored' => 0,
            'last_trade_date' => null,
            'last_close' => null,
        ];
    }

    if (upstox_access_token() === '') {
        return [
            'ok' => false,
            'error' => 'Broker not connected. Open Integrations and connect Upstox.',
            'message' => '',
            'candles_received' => 0,
            'stats' => [],
            'bars_stored' => 0,
            'last_trade_date' => null,
            'last_close' => null,
        ];
    }

    $auth = upstox_verify_authentication();
    if (!$auth['ok']) {
        return [
            'ok' => false,
            'error' => 'Broker API not reachable: ' . ($auth['error'] ?? 'check token'),
            'message' => '',
            'candles_received' => 0,
            'stats' => [],
            'bars_stored' => 0,
            'last_trade_date' => null,
            'last_close' => null,
        ];
    }

    $syncCfg = sync_config();
    $toDate = gmdate('Y-m-d');
    $fromDate = null;
    if ($mode === 'recent') {
        $days = max(1, (int) ($syncCfg['latest_market_data_days'] ?? 14));
        $fromTs = strtotime($toDate . ' UTC -' . $days . ' days');
        $fromDate = $fromTs !== false ? gmdate('Y-m-d', $fromTs) : null;
    }

    $type = (string) ($instrument['instrument_type'] ?? 'stock');
    if (!in_array($type, ['stock', 'etf', 'other'], true)) {
        $type = 'stock';
    }

    $result = upstox_sync_daily_candles(
        $pdo,
        $key,
        (string) $instrument['symbol'],
        (string) $instrument['name'],
        (string) ($instrument['exchange'] ?? 'NSE'),
        $type,
        $toDate,
        $fromDate
    );

    if (!$result['ok']) {
        return [
            'ok' => false,
            'error' => $result['error'] ?? 'Price sync failed.',
            'message' => '',
            'candles_received' => 0,
            'stats' => [],
            'bars_stored' => 0,
            'last_trade_date' => null,
            'last_close' => null,
        ];
    }

    instrument_refresh_coverage($pdo, $instrumentId);

    $stats = is_array($result['stats'] ?? null) ? $result['stats'] : [];
    $candleCount = (int) ($result['candle_count'] ?? 0);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM daily_prices WHERE instrument_id = :id');
    $countStmt->execute(['id' => $instrumentId]);
    $barsStored = (int) $countStmt->fetchColumn();

    $lastStmt = $pdo->prepare(
        'SELECT trade_date, close FROM daily_prices WHERE instrument_id = :id ORDER BY trade_date DESC LIMIT 1'
    );
    $lastStmt->execute(['id' => $instrumentId]);
    $last = $lastStmt->fetch() ?: null;

    return [
        'ok' => true,
        'error' => null,
        'candles_received' => $candleCount,
        'stats' => $stats,
        'bars_stored' => $barsStored,
        'last_trade_date' => $last['trade_date'] ?? null,
        'last_close' => $last !== null ? (float) $last['close'] : null,
        'message' => sprintf(
            'Updated from broker — %d bars received, %d total stored.',
            $candleCount,
            $barsStored
        ),
    ];
}

/** @param array<string,mixed> $payload */
function job_run_instrument_ohlcv_sync(PDO $pdo, int $jobId, array $payload): array
{
    @set_time_limit(0);
    $instrumentId = (int) ($payload['instrument_id'] ?? 0);
    $mode = (string) ($payload['mode'] ?? 'recent');
    if ($instrumentId <= 0) {
        throw new InvalidArgumentException('instrument_id missing in job payload.');
    }
    if (!in_array($mode, ['recent', 'full'], true)) {
        $mode = 'recent';
    }

    sync_update_progress($pdo, $jobId, 0, 1);
    $result = instrument_sync_prices_from_upstox($pdo, $instrumentId, $mode);
    sync_update_progress($pdo, $jobId, 1, 1);

    if (!$result['ok']) {
        throw new RuntimeException($result['error'] ?? 'Instrument sync failed.');
    }

    return [
        'records' => (int) ($result['stats']['inserted'] ?? 0) + (int) ($result['stats']['updated'] ?? 0),
        'instrument_id' => $instrumentId,
        'mode' => $mode,
        'candles_received' => $result['candles_received'],
        'bars_stored' => $result['bars_stored'],
        'message' => $result['message'],
    ];
}
