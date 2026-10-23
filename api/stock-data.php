<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/json.php';
require_once dirname(__DIR__) . '/includes/research.php';
require_once PRIVATE_ROOT . '/services/indicators.php';
require_once PRIVATE_ROOT . '/services/instrument_insights.php';

require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$instrumentId = isset($_GET['instrument_id']) ? (int) $_GET['instrument_id'] : 0;
$days = isset($_GET['days']) ? min(730, max(7, (int) $_GET['days'])) : 365;

if ($instrumentId <= 0) {
    json_response(['ok' => false, 'error' => 'instrument_id is required.'], 422);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Database unavailable.'], 503);
}

$instrument = research_get_instrument($pdo, $instrumentId);
if (!$instrument) {
    json_response(['ok' => false, 'error' => 'Instrument not found.'], 404);
}

$stmt = $pdo->prepare(
    'SELECT trade_date, open, high, low, close, volume, source, fetched_at
     FROM daily_prices
     WHERE instrument_id = :id
     ORDER BY trade_date DESC
     LIMIT :lim'
);
$stmt->bindValue('id', $instrumentId, PDO::PARAM_INT);
$stmt->bindValue('lim', $days, PDO::PARAM_INT);
$stmt->execute();
$rows = array_reverse($stmt->fetchAll() ?: []);

$labels = [];
$closes = [];
$highs = [];
$lows = [];
$volumes = [];
$source = null;
$fetchedAt = null;
$lastTradeDate = null;
$rowSeries = [];

foreach ($rows as $row) {
    $labels[] = $row['trade_date'];
    $closes[] = (float) $row['close'];
    $highs[] = (float) $row['high'];
    $lows[] = (float) $row['low'];
    $volumes[] = $row['volume'] !== null ? (int) $row['volume'] : null;
    $source = $row['source'];
    $fetchedAt = $row['fetched_at'];
    $lastTradeDate = $row['trade_date'];
    $rowSeries[] = $row;
}

$sma20Series = indicators_sma_series($closes, 20);
$sma50Series = indicators_sma_series($closes, 50);
$indicators = count($closes) > 0 ? indicators_snapshot($closes) : [];
$insights = instrument_build_insights($rowSeries);

json_response([
    'ok' => true,
    'instrument' => [
        'id' => (int) $instrument['id'],
        'symbol' => $instrument['symbol'],
        'name' => $instrument['name'],
        'exchange' => $instrument['exchange'],
        'type' => $instrument['instrument_type'],
    ],
    'source' => $source,
    'fetched_at' => $fetchedAt,
    'last_trade_date' => $lastTradeDate,
    'stale_warning' => research_stale_message($fetchedAt, $lastTradeDate, false),
    'labels' => $labels,
    'close' => $closes,
    'high' => $highs,
    'low' => $lows,
    'volume' => $volumes,
    'sma_20' => $sma20Series,
    'sma_50' => $sma50Series,
    'indicators' => $indicators,
    'insights' => $insights,
]);
