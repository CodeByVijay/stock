<?php

declare(strict_types=1);

/**
 * Technical indicators from stored daily close prices (no look-ahead).
 */

/** @return list<float> */
function indicators_load_closes(PDO $pdo, int $instrumentId, int $maxRows = 400): array
{
    $stmt = $pdo->prepare(
        'SELECT close FROM daily_prices
         WHERE instrument_id = :id
         ORDER BY trade_date ASC
         LIMIT :lim'
    );
    $stmt->bindValue('id', $instrumentId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $maxRows, PDO::PARAM_INT);
    $stmt->execute();
    $closes = [];
    while ($row = $stmt->fetch()) {
        $closes[] = (float) $row['close'];
    }

    return $closes;
}

function indicators_sma(array $closes, int $period): ?float
{
    if ($period < 1 || count($closes) < $period) {
        return null;
    }
    $slice = array_slice($closes, -$period);

    return array_sum($slice) / $period;
}

/** @return list<?float> aligned with $closes indexes */
function indicators_sma_series(array $closes, int $period): array
{
    $out = [];
    $n = count($closes);
    for ($i = 0; $i < $n; $i++) {
        if ($i + 1 < $period) {
            $out[] = null;
            continue;
        }
        $slice = array_slice($closes, $i + 1 - $period, $period);
        $out[] = array_sum($slice) / $period;
    }

    return $out;
}

function indicators_ema(array $closes, int $period): ?float
{
    if ($period < 1 || count($closes) < $period) {
        return null;
    }
    $series = indicators_ema_series($closes, $period);
    $last = end($series);

    return $last === false ? null : $last;
}

/** @return list<?float> */
function indicators_ema_series(array $closes, int $period): array
{
    $n = count($closes);
    $out = array_fill(0, $n, null);
    if ($n < $period) {
        return $out;
    }

    $k = 2 / ($period + 1);
    $sum = 0.0;
    for ($i = 0; $i < $period; $i++) {
        $sum += $closes[$i];
    }
    $ema = $sum / $period;
    $out[$period - 1] = $ema;

    for ($i = $period; $i < $n; $i++) {
        $ema = ($closes[$i] - $ema) * $k + $ema;
        $out[$i] = $ema;
    }

    return $out;
}

function indicators_rsi(array $closes, int $period = 14): ?float
{
    if (count($closes) <= $period) {
        return null;
    }

    $gains = 0.0;
    $losses = 0.0;
    for ($i = 1; $i <= $period; $i++) {
        $change = $closes[$i] - $closes[$i - 1];
        if ($change >= 0) {
            $gains += $change;
        } else {
            $losses += abs($change);
        }
    }

    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;

    for ($i = $period + 1; $i < count($closes); $i++) {
        $change = $closes[$i] - $closes[$i - 1];
        $gain = $change > 0 ? $change : 0.0;
        $loss = $change < 0 ? abs($change) : 0.0;
        $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
        $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
    }

    if ($avgLoss == 0.0) {
        return 100.0;
    }
    $rs = $avgGain / $avgLoss;

    return 100 - (100 / (1 + $rs));
}

/** @return array{macd:?float,signal:?float} */
function indicators_macd(array $closes, int $fast = 12, int $slow = 26, int $signalPeriod = 9): array
{
    if (count($closes) < $slow + $signalPeriod) {
        return ['macd' => null, 'signal' => null];
    }

    $emaFast = indicators_ema_series($closes, $fast);
    $emaSlow = indicators_ema_series($closes, $slow);
    $macdLine = [];
    $n = count($closes);
    for ($i = 0; $i < $n; $i++) {
        if ($emaFast[$i] === null || $emaSlow[$i] === null) {
            $macdLine[] = null;
        } else {
            $macdLine[] = $emaFast[$i] - $emaSlow[$i];
        }
    }

    $validMacd = [];
    foreach ($macdLine as $v) {
        if ($v !== null) {
            $validMacd[] = $v;
        }
    }
    if (count($validMacd) < $signalPeriod) {
        return ['macd' => null, 'signal' => null];
    }

    $signalSeries = indicators_ema_series($validMacd, $signalPeriod);
    $lastMacd = end($validMacd);
    $lastSignal = end($signalSeries);

    return [
        'macd' => $lastMacd === false ? null : (float) $lastMacd,
        'signal' => $lastSignal === false ? null : (float) $lastSignal,
    ];
}

function indicators_volatility(array $closes, int $period = 20): ?float
{
    if (count($closes) <= $period) {
        return null;
    }

    $returns = [];
    for ($i = count($closes) - $period; $i < count($closes); $i++) {
        if ($closes[$i - 1] == 0.0) {
            continue;
        }
        $returns[] = ($closes[$i] - $closes[$i - 1]) / $closes[$i - 1];
    }
    if ($returns === []) {
        return null;
    }

    $mean = array_sum($returns) / count($returns);
    $variance = 0.0;
    foreach ($returns as $r) {
        $variance += ($r - $mean) ** 2;
    }
    $variance /= count($returns);

    return sqrt($variance) * 100;
}

/** @return array<string,mixed> */
function indicators_snapshot(array $closes): array
{
    $macd = indicators_macd($closes);

    return [
        'sma_20' => indicators_sma($closes, 20),
        'sma_50' => indicators_sma($closes, 50),
        'sma_200' => indicators_sma($closes, 200),
        'ema_20' => indicators_ema($closes, 20),
        'rsi_14' => indicators_rsi($closes, 14),
        'macd' => $macd['macd'],
        'macd_signal' => $macd['signal'],
        'volatility' => indicators_volatility($closes, 20),
        'history_bars' => count($closes),
    ];
}

function indicators_persist(PDO $pdo, int $instrumentId, array $snapshot): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO technical_indicators
         (instrument_id, timeframe, calculated_at, sma_20, sma_50, sma_200, ema_20, rsi_14, macd, macd_signal, volatility)
         VALUES (:iid, \'daily\', NOW(), :sma20, :sma50, :sma200, :ema20, :rsi, :macd, :sig, :vol)'
    );
    $stmt->execute([
        'iid' => $instrumentId,
        'sma20' => $snapshot['sma_20'],
        'sma50' => $snapshot['sma_50'],
        'sma200' => $snapshot['sma_200'],
        'ema20' => $snapshot['ema_20'],
        'rsi' => $snapshot['rsi_14'],
        'macd' => $snapshot['macd'],
        'sig' => $snapshot['macd_signal'],
        'vol' => $snapshot['volatility'],
    ]);
}

function indicators_recalculate_all(PDO $pdo, int $jobId = 0): int
{
    $ids = $pdo->query(
        "SELECT DISTINCT instrument_id FROM daily_prices ORDER BY instrument_id"
    )->fetchAll(PDO::FETCH_COLUMN);

    $total = count($ids);
    if ($jobId > 0) {
        sync_update_progress($pdo, $jobId, 0, max(1, $total));
    }

    $done = 0;
    $saved = 0;
    foreach ($ids as $instrumentId) {
        $instrumentId = (int) $instrumentId;
        $closes = indicators_load_closes($pdo, $instrumentId, 400);
        if (count($closes) >= 20) {
            $snapshot = indicators_snapshot($closes);
            indicators_persist($pdo, $instrumentId, $snapshot);
            $saved++;
        }
        $done++;
        if ($jobId > 0) {
            sync_update_progress($pdo, $jobId, $done, max(1, $total));
        }
    }

    return $saved;
}
