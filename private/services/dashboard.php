<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/instrument_master.php';
require_once APP_ROOT . '/includes/market_data_job_labels.php';

/** @param array<string,mixed>|null $providers */
function dashboard_coverage_verified(?array $providers): bool
{
    return is_array($providers) && !empty($providers['coverage_claim_complete']);
}

function dashboard_cache_path(): string
{
    return PRIVATE_ROOT . '/logs/dashboard_payload_cache.json';
}

function dashboard_invalidate_cache(): void
{
    market_data_center_invalidate_cache();
    $path = dashboard_cache_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

/** @return array<string,mixed> */
function dashboard_api_payload(PDO $pdo): array
{
    $snapshot = market_data_center_snapshot_cached($pdo, 90);
    $stats = is_array($snapshot['stats'] ?? null) ? $snapshot['stats'] : market_data_dashboard_stats($pdo);
    $catCounts = is_array($snapshot['category_counts'] ?? null) ? $snapshot['category_counts'] : market_data_category_counts($pdo);

    $lastSyncRow = $pdo->query(
        "SELECT job_type, finished_at FROM sync_jobs WHERE status = 'completed' ORDER BY finished_at DESC LIMIT 1"
    )->fetch();

    $watchlistCount = (int) $pdo->query('SELECT COUNT(*) FROM watchlist')->fetchColumn();
    $providers = is_readable(PRIVATE_ROOT . '/config/providers.php')
        ? (require PRIVATE_ROOT . '/config/providers.php')
        : null;

    $distribution = [
        'stocks' => (int) ($catCounts['stocks_total'] ?? $stats['stocks'] ?? 0),
        'etfs' => (int) ($catCounts['etfs_total'] ?? $stats['etfs'] ?? 0),
        'mutual_funds' => (int) ($catCounts['mf_total'] ?? $stats['mf_schemes_active'] ?? 0),
    ];
    $distTotal = array_sum($distribution);

    $kpis = [
        'stocks_total' => (int) ($catCounts['stocks_total'] ?? 0),
        'stocks_with_prices' => (int) ($catCounts['stocks_with_prices'] ?? 0),
        'etfs_total' => (int) ($catCounts['etfs_total'] ?? 0),
        'etfs_with_prices' => (int) ($catCounts['etfs_with_prices'] ?? 0),
        'mf_total' => (int) ($catCounts['mf_total'] ?? 0),
        'mf_with_nav' => (int) ($catCounts['mf_with_nav'] ?? 0),
        'watchlist_count' => $watchlistCount,
    ];

    return [
        'kpis' => $kpis,
        'coverage' => [
            'stocks_without_history' => (int) ($stats['stocks_without_history'] ?? 0),
            'etfs_without_history' => (int) ($stats['etfs_without_history'] ?? 0),
            'mf_without_nav' => (int) ($stats['mf_schemes_without_nav'] ?? 0),
            'coverage_verified' => dashboard_coverage_verified(is_array($providers) ? $providers : null),
        ],
        'last_sync' => $lastSyncRow ? [
            'job_type' => (string) $lastSyncRow['job_type'],
            'label' => market_data_job_label((string) $lastSyncRow['job_type']),
            'finished_at' => (string) $lastSyncRow['finished_at'],
        ] : null,
        'distribution' => $distribution,
        'distribution_total' => $distTotal,
        'chart' => dashboard_chart_options($pdo),
        'recent_predictions' => dashboard_recent_predictions($pdo),
        'recent_sync' => dashboard_recent_sync_jobs($pdo),
        'watchlist' => dashboard_watchlist_summary($pdo),
        'movers' => dashboard_price_movers($pdo),
    ];
}

/** @return array<string,mixed> */
function dashboard_api_payload_cached(PDO $pdo, int $ttlSeconds = 90): array
{
    $path = dashboard_cache_path();
    if (is_readable($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $cached = json_decode($raw, true);
            if (is_array($cached) && isset($cached['expires_at'], $cached['data'])
                && (int) $cached['expires_at'] > time()) {
                return $cached['data'];
            }
        }
    }

    $data = dashboard_api_payload($pdo);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($path, json_encode([
        'expires_at' => time() + max(30, $ttlSeconds),
        'data' => $data,
    ]));

    return $data;
}

/** @return array{default_instrument_id:?int,instruments:list<array<string,mixed>>} */
function dashboard_chart_options(PDO $pdo): array
{
    $instruments = [];
    $stmt = $pdo->query(
        "SELECT i.id, i.symbol, i.name, i.exchange, COUNT(dp.id) AS bar_count,
                MAX(dp.trade_date) AS last_trade_date
         FROM instruments i
         INNER JOIN daily_prices dp ON dp.instrument_id = i.id
         WHERE i.instrument_type IN ('stock', 'etf') AND i.is_active = 1
         GROUP BY i.id, i.symbol, i.name, i.exchange
         HAVING bar_count >= 5
         ORDER BY bar_count DESC, i.symbol ASC
         LIMIT 40"
    );
    while ($row = $stmt->fetch()) {
        $instruments[] = [
            'id' => (int) $row['id'],
            'symbol' => (string) $row['symbol'],
            'name' => (string) $row['name'],
            'exchange' => (string) ($row['exchange'] ?? ''),
            'bar_count' => (int) $row['bar_count'],
            'last_trade_date' => (string) $row['last_trade_date'],
        ];
    }

    $defaultId = null;
    $wl = $pdo->query(
        'SELECT w.instrument_id FROM watchlist w
         INNER JOIN daily_prices dp ON dp.instrument_id = w.instrument_id
         ORDER BY w.created_at DESC LIMIT 1'
    )->fetch();
    if ($wl) {
        $defaultId = (int) $wl['instrument_id'];
    } elseif ($instruments !== []) {
        $defaultId = (int) $instruments[0]['id'];
    }

    return ['default_instrument_id' => $defaultId, 'instruments' => $instruments];
}

/** @return list<array<string,mixed>> */
function dashboard_recent_predictions(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT pr.rank_position, pr.technical_score, pr.target_value, pr.base_value,
                pr.prediction_date, pr.horizon_end_date,
                i.symbol, i.name AS instrument_name, s.scheme_name,
                po.outcome_status, run.id AS run_id
         FROM prediction_results pr
         INNER JOIN prediction_runs run ON run.id = pr.run_id
         LEFT JOIN instruments i ON i.id = pr.instrument_id
         LEFT JOIN mutual_fund_schemes s ON s.id = pr.scheme_id
         LEFT JOIN prediction_outcomes po ON po.prediction_result_id = pr.id
         ORDER BY pr.id DESC
         LIMIT 8'
    );

    return $stmt->fetchAll() ?: [];
}

/** @return list<array<string,mixed>> */
function dashboard_recent_sync_jobs(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, job_type, status, finished_at, records_processed, error_message
         FROM sync_jobs
         ORDER BY id DESC
         LIMIT 8'
    );
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = [
            'id' => (int) $row['id'],
            'label' => market_data_job_label((string) $row['job_type']),
            'status' => market_data_status_label((string) $row['status']),
            'status_raw' => (string) $row['status'],
            'finished_at' => $row['finished_at'],
            'records' => (int) $row['records_processed'],
            'error' => $row['error_message'],
        ];
    }

    return $out;
}

/** @return array{count:int,items:list<array<string,mixed>>} */
function dashboard_watchlist_summary(PDO $pdo): array
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM watchlist')->fetchColumn();
    $stmt = $pdo->query(
        'SELECT i.id, i.symbol, i.name, i.exchange,
                dp.close AS last_close, dp.trade_date AS last_trade_date, dp.fetched_at
         FROM watchlist w
         INNER JOIN instruments i ON i.id = w.instrument_id
         LEFT JOIN daily_prices dp ON dp.id = (
             SELECT dp2.id FROM daily_prices dp2
             WHERE dp2.instrument_id = i.id ORDER BY dp2.trade_date DESC LIMIT 1
         )
         ORDER BY w.created_at DESC
         LIMIT 6'
    );

    return ['count' => $count, 'items' => $stmt->fetchAll() ?: []];
}

/** @return array{available:bool,gainers:list<array<string,mixed>>,losers:list<array<string,mixed>>} */
function dashboard_price_movers(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT i.id, i.symbol, i.name
         FROM instruments i
         WHERE i.instrument_type = 'stock' AND i.is_active = 1
           AND (SELECT COUNT(*) FROM daily_prices dp WHERE dp.instrument_id = i.id) >= 2
         LIMIT 120"
    );
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return ['available' => false, 'gainers' => [], 'losers' => []];
    }

    $priceStmt = $pdo->prepare(
        'SELECT close, trade_date FROM daily_prices
         WHERE instrument_id = :id ORDER BY trade_date DESC LIMIT 2'
    );
    $changes = [];
    foreach ($rows as $row) {
        $priceStmt->execute(['id' => (int) $row['id']]);
        $prices = $priceStmt->fetchAll();
        if (count($prices) < 2) {
            continue;
        }
        $last = (float) $prices[0]['close'];
        $prev = (float) $prices[1]['close'];
        if ($prev <= 0) {
            continue;
        }
        $lastDate = (string) $prices[0]['trade_date'];
        if ($lastDate < (new DateTimeImmutable('-30 days'))->format('Y-m-d')) {
            continue;
        }
        $changes[] = [
            'symbol' => (string) $row['symbol'],
            'name' => (string) $row['name'],
            'id' => (int) $row['id'],
            'change_pct' => round((($last - $prev) / $prev) * 100, 2),
            'last_close' => $last,
            'last_date' => $lastDate,
        ];
    }

    if ($changes === []) {
        return ['available' => false, 'gainers' => [], 'losers' => []];
    }

    usort($changes, static function ($a, $b) {
        return $b['change_pct'] <=> $a['change_pct'];
    });
    $gainers = array_slice($changes, 0, 5);
    $losers = array_slice(array_reverse($changes), 0, 5);

    return ['available' => true, 'gainers' => $gainers, 'losers' => $losers];
}
