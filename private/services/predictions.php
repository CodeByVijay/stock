<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/indicators.php';

const PREDICTION_MAX_RESULTS = 50;
const PREDICTION_MIN_EQUITY_BARS = 60;
const PREDICTION_MIN_MF_BARS = 30;
/** Target hit when daily high (equity) or NAV (MF) reaches target on or before horizon end. */
const PREDICTION_TARGET_HIT_MODE = 'daily_high_or_nav_close';

/** @return array<string,mixed> */
function prediction_defaults(): array
{
    return [
        'asset_type' => 'stock',
        'result_count' => 5,
        'target_percent' => 8.0,
        'horizon_value' => 30,
        'horizon_unit' => 'days',
        'universe_type' => 'all',
        'min_history_bars' => PREDICTION_MIN_EQUITY_BARS,
    ];
}

function prediction_horizon_end(DateTimeInterface $start, int $value, string $unit): DateTimeImmutable
{
    if ($start instanceof DateTimeImmutable) {
        $immutable = $start;
    } else {
        $immutable = new DateTimeImmutable($start->format('Y-m-d H:i:s.u'));
    }
    switch ($unit) {
        case 'months':
            return $immutable->modify('+' . $value . ' months');
        case 'years':
            return $immutable->modify('+' . $value . ' years');
        case 'days':
        default:
            return $immutable->modify('+' . $value . ' days');
    }
}

/** @param array<string,mixed> $input */
function prediction_validate_settings(array $input): array
{
    $d = prediction_defaults();
    $asset = (string) ($input['asset_type'] ?? $d['asset_type']);
    if (!in_array($asset, ['stock', 'etf', 'mutual_fund'], true)) {
        throw new InvalidArgumentException('Invalid asset category.');
    }

    $count = (int) ($input['result_count'] ?? $d['result_count']);
    if ($count < 1 || $count > PREDICTION_MAX_RESULTS) {
        throw new InvalidArgumentException('Result count must be between 1 and ' . PREDICTION_MAX_RESULTS . '.');
    }

    $target = (float) ($input['target_percent'] ?? $d['target_percent']);
    if ($target <= 0 || $target > 500) {
        throw new InvalidArgumentException('Target increase must be a positive percentage up to 500.');
    }

    $hVal = (int) ($input['horizon_value'] ?? $d['horizon_value']);
    if ($hVal < 1 || $hVal > 3650) {
        throw new InvalidArgumentException('Horizon value is out of allowed range.');
    }

    $hUnit = (string) ($input['horizon_unit'] ?? $d['horizon_unit']);
    if (!in_array($hUnit, ['days', 'months', 'years'], true)) {
        throw new InvalidArgumentException('Invalid horizon unit.');
    }

    $universe = (string) ($input['universe_type'] ?? $d['universe_type']);
    if (!in_array($universe, ['all', 'watchlist'], true)) {
        throw new InvalidArgumentException('Invalid universe selection.');
    }
    if ($asset === 'mutual_fund') {
        $universe = 'all';
    }

    $minBars = (int) ($input['min_history_bars'] ?? ($asset === 'mutual_fund' ? PREDICTION_MIN_MF_BARS : PREDICTION_MIN_EQUITY_BARS));
    $floor = $asset === 'mutual_fund' ? PREDICTION_MIN_MF_BARS : 30;
    if ($minBars < $floor || $minBars > 400) {
        throw new InvalidArgumentException('Minimum history filter is out of range.');
    }

    $predictionDate = new DateTimeImmutable('today');

    return [
        'asset_type' => $asset,
        'result_count' => $count,
        'target_percent' => $target,
        'horizon_value' => $hVal,
        'horizon_unit' => $hUnit,
        'universe_type' => $universe,
        'min_history_bars' => $minBars,
        'prediction_date' => $predictionDate->format('Y-m-d'),
        'horizon_end_date' => prediction_horizon_end($predictionDate, $hVal, $hUnit)->format('Y-m-d'),
        'horizon_convention' => 'Calendar days/months/years from prediction date. Equity/MF evaluation uses stored daily bars only.',
        'target_hit_convention' => 'Equity: daily high >= target within horizon. MF: published NAV >= target on NAV date within horizon.',
        'scoring_version' => '1.0-rule-based',
    ];
}

/** @param array<string,mixed> $settings */
function prediction_create_run(PDO $pdo, array $settings): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO prediction_runs (settings_snapshot_json, status, started_at)
         VALUES (:settings, \'queued\', NULL)'
    );
    $stmt->execute([
        'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
    ]);

    return (int) $pdo->lastInsertId();
}

/** @return array{score:float,reason:string,risk:string,base:float,factors:array<string,?float>} */
function prediction_score_equity(array $closes, float $targetPercent): array
{
    $n = count($closes);
    $base = (float) $closes[$n - 1];
    $target = $base * (1 + $targetPercent / 100);

    $sma20 = indicators_sma($closes, 20);
    $sma50 = indicators_sma($closes, 50);
    $rsi = indicators_rsi($closes, 14);
    $macd = indicators_macd($closes);
    $vol = indicators_volatility($closes, 20);

    $factors = [
        'trend' => null,
        'momentum' => null,
        'rsi' => null,
        'macd' => null,
        'volatility' => null,
    ];
    $weights = ['trend' => 0.25, 'momentum' => 0.25, 'rsi' => 0.20, 'macd' => 0.20, 'volatility' => 0.10];
    $reasons = [];

    if ($sma20 !== null && $sma50 !== null) {
        $trendScore = 35.0;
        if ($base > $sma20) {
            $trendScore += 25;
        }
        if ($sma20 > $sma50) {
            $trendScore += 25;
        }
        if ($base > $sma50) {
            $trendScore += 15;
        }
        $factors['trend'] = min(100.0, $trendScore);
        $reasons[] = 'Trend vs SMA20/SMA50';
    } else {
        $reasons[] = 'Trend: insufficient SMA history';
    }

    if ($n >= 21) {
        $prev = (float) $closes[$n - 21];
        if ($prev > 0) {
            $ret20 = (($base - $prev) / $prev) * 100;
            $factors['momentum'] = max(0.0, min(100.0, 50 + $ret20 * 2));
            $reasons[] = '20-session momentum ' . round($ret20, 2) . '%';
        }
    }

    if ($rsi !== null) {
        if ($rsi >= 40 && $rsi <= 65) {
            $factors['rsi'] = 85.0;
        } elseif ($rsi > 65 && $rsi <= 75) {
            $factors['rsi'] = 55.0;
        } elseif ($rsi < 40) {
            $factors['rsi'] = 45.0;
        } else {
            $factors['rsi'] = 30.0;
        }
        $reasons[] = 'RSI(14) ' . round($rsi, 1);
    }

    if ($macd['macd'] !== null && $macd['signal'] !== null) {
        $factors['macd'] = $macd['macd'] >= $macd['signal'] ? 80.0 : 40.0;
        $reasons[] = 'MACD vs signal';
    }

    if ($vol !== null) {
        $factors['volatility'] = max(0.0, min(100.0, 100 - $vol * 3));
        $reasons[] = 'Volatility (20d) ' . round($vol, 2) . '%';
    }

    $weightSum = 0.0;
    $score = 0.0;
    foreach ($weights as $key => $w) {
        if ($factors[$key] === null) {
            continue;
        }
        $weightSum += $w;
        $score += $factors[$key] * $w;
    }
    if ($weightSum <= 0) {
        return [
            'score' => 0.0,
            'reason' => 'Insufficient indicator history.',
            'risk' => 'Not enough data for scoring.',
            'base' => $base,
            'factors' => $factors,
        ];
    }
    $score = ($score / $weightSum);

    $risk = [];
    if ($vol !== null && $vol > 4) {
        $risk[] = 'Elevated recent volatility.';
    }
    if ($rsi !== null && $rsi > 75) {
        $risk[] = 'RSI suggests overbought conditions.';
    }

    return [
        'score' => round($score, 2),
        'reason' => implode('; ', $reasons),
        'risk' => $risk === [] ? 'Standard data-quality checks applied.' : implode(' ', $risk),
        'base' => $base,
        'factors' => $factors,
        'target' => $target,
    ];
}

/** @param array<string,mixed> $settings */
function prediction_score_nav(array $navs, float $targetPercent): array
{
    $n = count($navs);
    $base = (float) $navs[$n - 1];
    $target = $base * (1 + $targetPercent / 100);

    $sma20 = indicators_sma($navs, 20);
    $sma50 = indicators_sma($navs, min(50, $n));
    $vol = indicators_volatility($navs, min(20, $n - 1));

    $factors = ['trend' => null, 'momentum' => null, 'volatility' => null];
    $weights = ['trend' => 0.45, 'momentum' => 0.35, 'volatility' => 0.20];
    $reasons = [];

    if ($sma20 !== null) {
        $trend = 40.0;
        if ($base > $sma20) {
            $trend += 30;
        }
        if ($sma50 !== null && $sma20 > $sma50) {
            $trend += 30;
        }
        $factors['trend'] = min(100.0, $trend);
        $reasons[] = 'NAV vs SMA20/SMA50';
    }

    if ($n >= 21) {
        $prev = (float) $navs[$n - 21];
        if ($prev > 0) {
            $ret = (($base - $prev) / $prev) * 100;
            $factors['momentum'] = max(0.0, min(100.0, 50 + $ret * 2.5));
            $reasons[] = '20-day NAV momentum ' . round($ret, 2) . '%';
        }
    }

    if ($vol !== null) {
        $factors['volatility'] = max(0.0, min(100.0, 100 - $vol * 4));
        $reasons[] = 'NAV volatility ' . round($vol, 2) . '%';
    }

    $weightSum = 0.0;
    $score = 0.0;
    foreach ($weights as $key => $w) {
        if ($factors[$key] === null) {
            continue;
        }
        $weightSum += $w;
        $score += $factors[$key] * $w;
    }
    if ($weightSum <= 0) {
        return [
            'score' => 0.0,
            'reason' => 'Insufficient NAV history.',
            'risk' => 'Not enough NAV data.',
            'base' => $base,
            'factors' => $factors,
        ];
    }

    return [
        'score' => round($score / $weightSum, 2),
        'reason' => implode('; ', $reasons),
        'risk' => 'Mutual fund NAV is daily; not intraday traded.',
        'base' => $base,
        'factors' => $factors,
        'target' => $target,
    ];
}

/** @param array<string,mixed> $settings */
function prediction_run(PDO $pdo, int $runId, ?int $syncJobId = null): int
{
    $stmt = $pdo->prepare('SELECT * FROM prediction_runs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $runId]);
    $run = $stmt->fetch();
    if (!$run) {
        throw new RuntimeException('Prediction run not found.');
    }

    $settings = json_decode((string) $run['settings_snapshot_json'], true);
    if (!is_array($settings)) {
        throw new RuntimeException('Invalid settings snapshot.');
    }

    $pdo->prepare(
        "UPDATE prediction_runs SET status = 'running', started_at = COALESCE(started_at, NOW()) WHERE id = :id"
    )->execute(['id' => $runId]);

    $scan = prediction_scan_universe($pdo, $settings);
    $candidates = $scan['candidates'];
    $pdo->prepare('UPDATE prediction_runs SET scan_stats_json = :json WHERE id = :id')->execute([
        'json' => json_encode($scan['stats'], JSON_THROW_ON_ERROR),
        'id' => $runId,
    ]);

    $total = count($candidates);
    if ($syncJobId) {
        sync_update_progress($pdo, $syncJobId, 0, max(1, $total));
    }

    $ranked = $candidates;
    $done = 0;
    foreach ($ranked as $_) {
        $done++;
        if ($syncJobId) {
            sync_update_progress($pdo, $syncJobId, $done, max(1, $total));
        }
    }

    usort($ranked, static function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $topN = (int) $settings['result_count'];
    $ranked = array_slice($ranked, 0, $topN);

    $pdo->prepare('DELETE FROM prediction_results WHERE run_id = :id')->execute(['id' => $runId]);

    $ins = $pdo->prepare(
        'INSERT INTO prediction_results
         (run_id, instrument_id, scheme_id, rank_position, base_value, target_value, technical_score,
          risk_note, prediction_date, horizon_end_date, data_timestamp)
         VALUES (:run, :iid, :sid, :rank, :base, :target, :score, :risk, :pdate, :hend, :dts)'
    );

    $rank = 1;
    foreach ($ranked as $row) {
        $ins->execute([
            'run' => $runId,
            'iid' => $row['instrument_id'],
            'sid' => $row['scheme_id'],
            'rank' => $rank,
            'base' => $row['base'],
            'target' => $row['target'],
            'score' => $row['score'],
            'risk' => $row['risk'] . ' | ' . $row['reason'],
            'pdate' => $settings['prediction_date'],
            'hend' => $settings['horizon_end_date'],
            'dts' => $row['data_timestamp'],
        ]);
        $rank++;
    }

    $pdo->prepare(
        "UPDATE prediction_runs SET status = 'completed', completed_at = NOW(), error_message = NULL WHERE id = :id"
    )->execute(['id' => $runId]);

    return count($ranked);
}

/** @param array<string,mixed> $settings @return list<array<string,mixed>> */
function prediction_collect_candidates(PDO $pdo, array $settings): array
{
    return prediction_scan_universe($pdo, $settings)['candidates'];
}

/**
 * @param array<string,mixed> $settings
 * @return array{candidates:list<array<string,mixed>>,stats:array<string,mixed>}
 */
function prediction_scan_universe(PDO $pdo, array $settings): array
{
    $asset = $settings['asset_type'];
    $minBars = (int) $settings['min_history_bars'];
    $targetPct = (float) $settings['target_percent'];
    $out = [];
    $stats = [
        'scanned' => 0,
        'excluded_insufficient_history' => 0,
        'excluded_low_score' => 0,
        'eligible_ranked' => 0,
        'asset_type' => $asset,
        'universe_type' => $settings['universe_type'] ?? 'all',
        'min_history_bars' => $minBars,
    ];

    if ($asset === 'mutual_fund') {
        $schemes = $pdo->query(
            'SELECT id, scheme_code, scheme_name FROM mutual_fund_schemes WHERE is_active = 1'
        )->fetchAll();
        foreach ($schemes as $scheme) {
            $stats['scanned']++;
            $stmt = $pdo->prepare(
                'SELECT nav_date, nav, fetched_at FROM mutual_fund_nav
                 WHERE scheme_id = :id ORDER BY nav_date ASC'
            );
            $stmt->execute(['id' => (int) $scheme['id']]);
            $rows = $stmt->fetchAll();
            if (count($rows) < $minBars) {
                $stats['excluded_insufficient_history']++;
                continue;
            }
            $navs = array_map(static function ($r) {
                return (float) $r['nav'];
            }, $rows);
            $last = $rows[count($rows) - 1];
            $scored = prediction_score_nav($navs, $targetPct);
            if ($scored['score'] <= 0) {
                $stats['excluded_low_score']++;
                continue;
            }
            $stats['eligible_ranked']++;
            $out[] = [
                'scheme_id' => (int) $scheme['id'],
                'instrument_id' => null,
                'label' => $scheme['scheme_name'],
                'score' => $scored['score'],
                'base' => $scored['base'],
                'target' => $scored['target'],
                'reason' => $scored['reason'],
                'risk' => $scored['risk'],
                'data_timestamp' => $last['fetched_at'],
            ];
        }

        return ['candidates' => $out, 'stats' => $stats];
    }

    $type = $asset === 'etf' ? 'etf' : 'stock';
    $sql = "SELECT i.id, i.symbol, i.name FROM instruments i
            WHERE i.instrument_type = :type AND i.is_active = 1";
    if ($settings['universe_type'] === 'watchlist') {
        $sql .= ' AND EXISTS (SELECT 1 FROM watchlist w WHERE w.instrument_id = i.id)';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['type' => $type]);

    while ($inst = $stmt->fetch()) {
        $stats['scanned']++;
        $pstmt = $pdo->prepare(
            'SELECT trade_date, high, close, fetched_at FROM daily_prices
             WHERE instrument_id = :id ORDER BY trade_date ASC'
        );
        $pstmt->execute(['id' => (int) $inst['id']]);
        $rows = $pstmt->fetchAll();
        if (count($rows) < $minBars) {
            $stats['excluded_insufficient_history']++;
            continue;
        }
        $closes = array_map(static function ($r) {
            return (float) $r['close'];
        }, $rows);
        $scored = prediction_score_equity($closes, $targetPct);
        if ($scored['score'] <= 0) {
            $stats['excluded_low_score']++;
            continue;
        }
        $stats['eligible_ranked']++;
        $last = $rows[count($rows) - 1];
        $out[] = [
            'instrument_id' => (int) $inst['id'],
            'scheme_id' => null,
            'label' => $inst['symbol'],
            'score' => $scored['score'],
            'base' => $scored['base'],
            'target' => $scored['target'] ?? ($scored['base'] * (1 + $targetPct / 100)),
            'reason' => $scored['reason'],
            'risk' => $scored['risk'],
            'data_timestamp' => $last['fetched_at'],
        ];
    }

    return ['candidates' => $out, 'stats' => $stats];
}

/**
 * Live snapshot: how far each pick has moved toward its goal as of today (does not require horizon end).
 *
 * @return array{checked_at:string,goal_percent:float,items:array<int,array<string,mixed>>}
 */
function prediction_run_live_progress(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare('SELECT settings_snapshot_json FROM prediction_runs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $runId]);
    $run = $stmt->fetch();
    if (!$run) {
        throw new InvalidArgumentException('Run not found.');
    }
    $snap = json_decode((string) $run['settings_snapshot_json'], true);
    $goalPercent = is_array($snap) ? (float) ($snap['target_percent'] ?? 0) : 0.0;

    $stmt = $pdo->prepare(
        'SELECT pr.* FROM prediction_results pr WHERE pr.run_id = :id ORDER BY pr.rank_position ASC'
    );
    $stmt->execute(['id' => $runId]);
    $rows = $stmt->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[(int) $row['id']] = prediction_result_live_progress($pdo, $row, $goalPercent);
    }

    return [
        'checked_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        'goal_percent' => $goalPercent,
        'items' => $items,
    ];
}

/** @param array<string,mixed> $resultRow */
function prediction_result_live_progress(PDO $pdo, array $resultRow, float $goalPercent): array
{
    $base = (float) $resultRow['base_value'];
    $target = (float) $resultRow['target_value'];
    $predDate = (string) ($resultRow['prediction_date'] ?? '');
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    if ($base <= 0 || $goalPercent <= 0) {
        return [
            'ok' => false,
            'message' => 'Invalid base or goal.',
            'return_percent' => null,
            'goal_reached_percent' => null,
            'latest_value' => null,
            'goal_touched' => false,
        ];
    }

    $latest = null;
    $goalTouched = false;

    if ((int) $resultRow['instrument_id'] > 0) {
        $stmt = $pdo->prepare(
            'SELECT trade_date, high, close FROM daily_prices
             WHERE instrument_id = :id AND trade_date >= :start AND trade_date <= :end
             ORDER BY trade_date ASC'
        );
        $stmt->execute([
            'id' => (int) $resultRow['instrument_id'],
            'start' => $predDate !== '' ? $predDate : $today,
            'end' => $today,
        ]);
        $bars = $stmt->fetchAll();
        if ($bars === []) {
            return [
                'ok' => false,
                'message' => 'No price data since this run.',
                'return_percent' => null,
                'goal_reached_percent' => null,
                'latest_value' => null,
                'goal_touched' => false,
            ];
        }
        foreach ($bars as $b) {
            if ((float) $b['high'] >= $target) {
                $goalTouched = true;
            }
        }
        $latest = (float) $bars[count($bars) - 1]['close'];
    } elseif ((int) $resultRow['scheme_id'] > 0) {
        $stmt = $pdo->prepare(
            'SELECT nav_date, nav FROM mutual_fund_nav
             WHERE scheme_id = :id AND nav_date >= :start AND nav_date <= :end
             ORDER BY nav_date ASC'
        );
        $stmt->execute([
            'id' => (int) $resultRow['scheme_id'],
            'start' => $predDate !== '' ? $predDate : $today,
            'end' => $today,
        ]);
        $navs = $stmt->fetchAll();
        if ($navs === []) {
            return [
                'ok' => false,
                'message' => 'No NAV data since this run.',
                'return_percent' => null,
                'goal_reached_percent' => null,
                'latest_value' => null,
                'goal_touched' => false,
            ];
        }
        foreach ($navs as $n) {
            if ((float) $n['nav'] >= $target) {
                $goalTouched = true;
            }
        }
        $latest = (float) $navs[count($navs) - 1]['nav'];
    } else {
        return [
            'ok' => false,
            'message' => 'Unknown pick type.',
            'return_percent' => null,
            'goal_reached_percent' => null,
            'latest_value' => null,
            'goal_touched' => false,
        ];
    }

    $returnPct = (($latest - $base) / $base) * 100;
    $goalReachedPct = ($returnPct / $goalPercent) * 100;

    return [
        'ok' => true,
        'message' => '',
        'return_percent' => round($returnPct, 2),
        'goal_reached_percent' => round($goalReachedPct, 1),
        'latest_value' => round($latest, 4),
        'goal_touched' => $goalTouched,
    ];
}

/** Final outcome check for one run only (horizon must have ended). */
function prediction_evaluate_run_due_outcomes(PDO $pdo, int $runId): int
{
    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.instrument_id, pr.scheme_id, pr.target_value, pr.horizon_end_date, pr.base_value
         FROM prediction_results pr
         LEFT JOIN prediction_outcomes po ON po.prediction_result_id = pr.id
         WHERE pr.run_id = :run AND po.id IS NULL AND pr.horizon_end_date < CURDATE()"
    );
    $stmt->execute(['run' => $runId]);
    $rows = $stmt->fetchAll();
    $count = 0;

    $ins = $pdo->prepare(
        'INSERT INTO prediction_outcomes
         (prediction_result_id, evaluated_at, outcome_status, realized_return_percent, max_drawdown_percent, notes)
         VALUES (:prid, NOW(), :status, :ret, :mdd, :notes)'
    );

    foreach ($rows as $row) {
        $eval = prediction_evaluate_one($pdo, $row);
        $ins->execute([
            'prid' => (int) $row['id'],
            'status' => $eval['status'],
            'ret' => $eval['realized_return'],
            'mdd' => $eval['max_drawdown'],
            'notes' => $eval['notes'],
        ]);
        $count++;
    }

    return $count;
}

function prediction_evaluate_due_outcomes(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT pr.id, pr.instrument_id, pr.scheme_id, pr.target_value, pr.horizon_end_date, pr.base_value
         FROM prediction_results pr
         LEFT JOIN prediction_outcomes po ON po.prediction_result_id = pr.id
         WHERE po.id IS NULL AND pr.horizon_end_date < CURDATE()"
    );
    $rows = $stmt->fetchAll();
    $count = 0;

    $ins = $pdo->prepare(
        'INSERT INTO prediction_outcomes
         (prediction_result_id, evaluated_at, outcome_status, realized_return_percent, max_drawdown_percent, notes)
         VALUES (:prid, NOW(), :status, :ret, :mdd, :notes)'
    );

    foreach ($rows as $row) {
        $eval = prediction_evaluate_one($pdo, $row);
        $ins->execute([
            'prid' => (int) $row['id'],
            'status' => $eval['status'],
            'ret' => $eval['realized_return'],
            'mdd' => $eval['max_drawdown'],
            'notes' => $eval['notes'],
        ]);
        $count++;
    }

    return $count;
}

/** @param array<string,mixed> $resultRow */
function prediction_evaluate_one(PDO $pdo, array $resultRow): array
{
    $target = (float) $resultRow['target_value'];
    $base = (float) $resultRow['base_value'];
    $end = (string) $resultRow['horizon_end_date'];

    if ((int) $resultRow['instrument_id'] > 0) {
        $stmt = $pdo->prepare(
            'SELECT trade_date, high, low, close FROM daily_prices
             WHERE instrument_id = :id AND trade_date <= :end ORDER BY trade_date ASC'
        );
        $stmt->execute(['id' => (int) $resultRow['instrument_id'], 'end' => $end]);
        $bars = $stmt->fetchAll();
        if ($bars === []) {
            return ['status' => 'not_evaluable', 'realized_return' => null, 'max_drawdown' => null, 'notes' => 'Missing price data.'];
        }
        $hit = false;
        $minLow = null;
        $lastClose = (float) $bars[count($bars) - 1]['close'];
        foreach ($bars as $b) {
            if ((float) $b['high'] >= $target) {
                $hit = true;
            }
        }
        foreach ($bars as $b) {
            $low = isset($b['low']) ? (float) $b['low'] : (float) $b['close'];
            if ($minLow === null || $low < $minLow) {
                $minLow = $low;
            }
        }
        $mdd = ($base > 0 && $minLow !== null) ? (($minLow - $base) / $base) * 100 : null;
        $ret = $base > 0 ? (($lastClose - $base) / $base) * 100 : null;

        return [
            'status' => $hit ? 'target_reached' : 'target_not_reached',
            'realized_return' => $ret !== null ? round($ret, 4) : null,
            'max_drawdown' => $mdd !== null ? round($mdd, 4) : null,
            'notes' => 'Target hit uses daily high >= target within horizon.',
        ];
    }

    if ((int) $resultRow['scheme_id'] > 0) {
        $stmt = $pdo->prepare(
            'SELECT nav_date, nav FROM mutual_fund_nav
             WHERE scheme_id = :id AND nav_date <= :end ORDER BY nav_date ASC'
        );
        $stmt->execute(['id' => (int) $resultRow['scheme_id'], 'end' => $end]);
        $navs = $stmt->fetchAll();
        if ($navs === []) {
            return ['status' => 'not_evaluable', 'realized_return' => null, 'max_drawdown' => null, 'notes' => 'Missing NAV data.'];
        }
        $hit = false;
        $minNav = null;
        $lastNav = (float) $navs[count($navs) - 1]['nav'];
        foreach ($navs as $n) {
            $v = (float) $n['nav'];
            if ($v >= $target) {
                $hit = true;
            }
            if ($minNav === null || $v < $minNav) {
                $minNav = $v;
            }
        }
        $mdd = ($base > 0 && $minNav !== null) ? (($minNav - $base) / $base) * 100 : null;
        $ret = $base > 0 ? (($lastNav - $base) / $base) * 100 : null;

        return [
            'status' => $hit ? 'target_reached' : 'target_not_reached',
            'realized_return' => $ret !== null ? round($ret, 4) : null,
            'max_drawdown' => $mdd !== null ? round($mdd, 4) : null,
            'notes' => 'MF target hit uses published NAV >= target.',
        ];
    }

    return ['status' => 'not_evaluable', 'realized_return' => null, 'max_drawdown' => null, 'notes' => 'Unknown asset link.'];
}

function prediction_mark_run_failed(PDO $pdo, int $runId, string $message): void
{
    $pdo->prepare(
        "UPDATE prediction_runs SET status = 'failed', completed_at = NOW(), error_message = :msg WHERE id = :id"
    )->execute(['msg' => mb_substr($message, 0, 2000), 'id' => $runId]);
}
