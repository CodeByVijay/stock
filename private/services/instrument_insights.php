<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/indicators.php';
require_once PRIVATE_ROOT . '/services/predictions.php';

/**
 * @param list<array<string,mixed>> $rows Newest-last rows with high, low, close, trade_date
 * @return array<string,mixed>
 */
function instrument_build_insights(array $rows, float $screenerTargetPct = 8.0): array
{
    $n = count($rows);
    if ($n === 0) {
        return [
            'has_data' => false,
            'message' => 'No price history stored yet. Use Refresh prices or Download Data.',
        ];
    }

    $window = array_slice($rows, -min(252, $n));
    $high52 = null;
    $low52 = null;
    foreach ($window as $r) {
        $h = (float) $r['high'];
        $l = (float) $r['low'];
        $high52 = $high52 === null ? $h : max($high52, $h);
        $low52 = $low52 === null ? $l : min($low52, $l);
    }

    $last = $rows[$n - 1];
    $lastClose = (float) $last['close'];
    $lastHigh = (float) $last['high'];
    $lastLow = (float) $last['low'];
    $lastDate = (string) $last['trade_date'];

    $closes = array_map(static fn ($r) => (float) $r['close'], $rows);

    $pctFromHigh = ($high52 !== null && $high52 > 0)
        ? round((($lastClose - $high52) / $high52) * 100, 2) : null;
    $pctFromLow = ($low52 !== null && $low52 > 0)
        ? round((($lastClose - $low52) / $low52) * 100, 2) : null;

    $rangePct = ($high52 !== null && $low52 !== null && $high52 > $low52)
        ? round((($lastClose - $low52) / ($high52 - $low52)) * 100, 1) : null;

    $returns = instrument_period_returns($closes);
    $scorePack = prediction_score_equity($closes, $screenerTargetPct);
    $score = (float) ($scorePack['score'] ?? 0);
    $outlook = instrument_map_score_outlook($score);
    $tilt = instrument_map_short_tilt($closes, $scorePack);

    $ind = indicators_snapshot($closes);
    $signals = instrument_technical_signals($ind, $lastClose);

    return [
        'has_data' => true,
        'last_close' => round($lastClose, 4),
        'last_date' => $lastDate,
        'day_high' => round($lastHigh, 4),
        'day_low' => round($lastLow, 4),
        'week52_high' => $high52 !== null ? round($high52, 4) : null,
        'week52_low' => $low52 !== null ? round($low52, 4) : null,
        'pct_from_52w_high' => $pctFromHigh,
        'pct_from_52w_low' => $pctFromLow,
        'position_in_52w_range_pct' => $rangePct,
        'returns' => $returns,
        'screener_target_pct' => $screenerTargetPct,
        'research_score' => round($score, 1),
        'research_reason' => (string) ($scorePack['reason'] ?? ''),
        'research_risk' => (string) ($scorePack['risk'] ?? ''),
        'outlook' => $outlook,
        'short_tilt' => $tilt,
        'signals' => $signals,
        'disclaimer' => 'Rule-based research from stored prices only — not advice or a guaranteed forecast.',
    ];
}

/** @param list<float> $closes */
function instrument_period_returns(array $closes): array
{
    $n = count($closes);
    $last = (float) $closes[$n - 1];
    $periods = [
        '1d' => 1,
        '1w' => 5,
        '1m' => 21,
        '3m' => 63,
        '6m' => 126,
        '1y' => 252,
    ];
    $out = [];
    foreach ($periods as $label => $back) {
        if ($n <= $back) {
            $out[$label] = null;
            continue;
        }
        $prev = (float) $closes[$n - 1 - $back];
        $out[$label] = $prev > 0 ? round((($last - $prev) / $prev) * 100, 2) : null;
    }

    return $out;
}

/** @param array<string,mixed> $scorePack */
function instrument_map_short_tilt(array $closes, array $scorePack): array
{
    $n = count($closes);
    $momentum = null;
    if ($n >= 6) {
        $prev5 = (float) $closes[$n - 6];
        $last = (float) $closes[$n - 1];
        if ($prev5 > 0) {
            $momentum = (($last - $prev5) / $prev5) * 100;
        }
    }

    $score = (float) ($scorePack['score'] ?? 50);
    if ($score >= 62 || ($momentum !== null && $momentum > 1.5)) {
        return [
            'key' => 'up',
            'label' => 'Upside bias',
            'label_hi' => 'Badega bias (short-term)',
            'detail' => 'Trend and momentum lean positive on stored data.',
        ];
    }
    if ($score <= 42 || ($momentum !== null && $momentum < -1.5)) {
        return [
            'key' => 'down',
            'label' => 'Downside bias',
            'label_hi' => 'Ghatega bias (short-term)',
            'detail' => 'Trend and momentum lean negative on stored data.',
        ];
    }

    return [
        'key' => 'flat',
        'label' => 'Sideways / mixed',
        'label_hi' => 'Mixed — clear trend nahi',
        'detail' => 'Signals are balanced; wait for fresher prices or more history.',
    ];
}

/** @return array{key:string,label:string,detail:string,badge:string} */
function instrument_map_score_outlook(float $score): array
{
    if ($score >= 68) {
        return [
            'key' => 'buy_lean',
            'label' => 'Buy lean',
            'badge' => 'Bullish tilt',
            'detail' => 'Higher research score vs our rule set (still not a buy call).',
        ];
    }
    if ($score <= 38) {
        return [
            'key' => 'sell_lean',
            'label' => 'Sell lean',
            'badge' => 'Bearish tilt',
            'detail' => 'Lower research score vs our rule set (still not a sell call).',
        ];
    }

    return [
        'key' => 'hold',
        'label' => 'Hold / watch',
        'badge' => 'Neutral',
        'detail' => 'No strong edge in the model — monitor or use Screener for ranked lists.',
    ];
}

/** @param array<string,mixed> $ind */
function instrument_technical_signals(array $ind, float $lastClose): array
{
    $tags = [];
    $rsi = $ind['rsi_14'] ?? null;
    if ($rsi !== null) {
        if ($rsi >= 70) {
            $tags[] = 'RSI overbought zone';
        } elseif ($rsi <= 30) {
            $tags[] = 'RSI oversold zone';
        } else {
            $tags[] = 'RSI neutral';
        }
    }

    $sma20 = $ind['sma_20'] ?? null;
    $sma50 = $ind['sma_50'] ?? null;
    if ($sma20 !== null && $lastClose > $sma20) {
        $tags[] = 'Above SMA 20';
    } elseif ($sma20 !== null) {
        $tags[] = 'Below SMA 20';
    }
    if ($sma20 !== null && $sma50 !== null) {
        $tags[] = $sma20 >= $sma50 ? 'SMA 20 ≥ SMA 50' : 'SMA 20 < SMA 50';
    }

    $macd = $ind['macd'] ?? null;
    $sig = $ind['macd_signal'] ?? null;
    if ($macd !== null && $sig !== null) {
        $tags[] = $macd >= $sig ? 'MACD ≥ signal' : 'MACD < signal';
    }

    return $tags;
}
