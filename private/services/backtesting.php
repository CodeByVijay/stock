<?php

declare(strict_types=1);

require_once PRIVATE_ROOT . '/services/predictions.php';

/** @param array<string,mixed> $input */
function backtest_validate_settings(array $input): array
{
    $asset = (string) ($input['asset_type'] ?? 'stock');
    if (!in_array($asset, ['stock', 'etf', 'mutual_fund'], true)) {
        throw new InvalidArgumentException('Invalid asset type.');
    }

    $start = (string) ($input['start_date'] ?? '');
    $end = (string) ($input['end_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        throw new InvalidArgumentException('Valid start and end dates are required.');
    }
    if ($start >= $end) {
        throw new InvalidArgumentException('Start date must be before end date.');
    }

    $target = (float) ($input['target_percent'] ?? 8);
    if ($target <= 0 || $target > 500) {
        throw new InvalidArgumentException('Invalid target percent.');
    }

    $hVal = (int) ($input['horizon_value'] ?? 30);
    $hUnit = (string) ($input['horizon_unit'] ?? 'days');
    if ($hVal < 1 || !in_array($hUnit, ['days', 'months', 'years'], true)) {
        throw new InvalidArgumentException('Invalid horizon.');
    }

    $fee = (float) ($input['fee_percent'] ?? 0);
    $slippage = (float) ($input['slippage_percent'] ?? 0);
    if ($fee < 0 || $fee > 5 || $slippage < 0 || $slippage > 5) {
        throw new InvalidArgumentException('Fees/slippage must be between 0 and 5%.');
    }

    return [
        'asset_type' => $asset,
        'start_date' => $start,
        'end_date' => $end,
        'target_percent' => $target,
        'horizon_value' => $hVal,
        'horizon_unit' => $hUnit,
        'fee_percent' => $fee,
        'slippage_percent' => $slippage,
        'step_days' => max(5, min(30, (int) ($input['step_days'] ?? 10))),
        'universe_type' => 'all',
        'min_history_bars' => $asset === 'mutual_fund' ? PREDICTION_MIN_MF_BARS : PREDICTION_MIN_EQUITY_BARS,
        'limitations' => 'Uses stored universe only (survivorship bias). One top-scored candidate per step. No guarantee of future results.',
    ];
}

/** @param array<string,mixed> $settings */
function backtest_create_run(PDO $pdo, array $settings): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO backtest_runs
         (strategy_name, asset_type, start_date, end_date, horizon_value, horizon_unit, settings_json, status)
         VALUES (:name, :asset, :start, :end, :hv, :hu, :settings, \'queued\')'
    );
    $stmt->execute([
        'name' => 'target_growth_v1',
        'asset' => $settings['asset_type'],
        'start' => $settings['start_date'],
        'end' => $settings['end_date'],
        'hv' => $settings['horizon_value'],
        'hu' => $settings['horizon_unit'],
        'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
    ]);

    return (int) $pdo->lastInsertId();
}

/** @param array<string,mixed> $settings */
function backtest_run(PDO $pdo, int $runId, ?int $syncJobId = null): int
{
    $stmt = $pdo->prepare('SELECT * FROM backtest_runs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $runId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Backtest run not found.');
    }

    $settings = json_decode((string) $row['settings_json'], true);
    if (!is_array($settings)) {
        throw new RuntimeException('Invalid backtest settings.');
    }

    $pdo->prepare("UPDATE backtest_runs SET status = 'running' WHERE id = :id")->execute(['id' => $runId]);

    $start = new DateTimeImmutable($settings['start_date']);
    $end = new DateTimeImmutable($settings['end_date']);
    $stepDays = (int) $settings['step_days'];
    $dates = [];
    for ($d = $start; $d <= $end; $d = $d->modify('+' . $stepDays . ' days')) {
        $dates[] = $d->format('Y-m-d');
    }
    $total = count($dates);
    if ($syncJobId) {
        sync_update_progress($pdo, $syncJobId, 0, max(1, $total));
    }

    $cases = 0;
    $hits = 0;
    $returns = [];
    $losses = 0;
    $maxDrawdowns = [];

    $done = 0;
    foreach ($dates as $predictionDate) {
        $done++;
        if ($syncJobId) {
            sync_update_progress($pdo, $syncJobId, $done, max(1, $total));
        }

        $settingsAtDate = $settings;
        $settingsAtDate['prediction_date'] = $predictionDate;
        $settingsAtDate['horizon_end_date'] = prediction_horizon_end(
            new DateTimeImmutable($predictionDate),
            (int) $settings['horizon_value'],
            (string) $settings['horizon_unit']
        )->format('Y-m-d');

        $candidates = backtest_candidates_as_of($pdo, $settingsAtDate, $predictionDate);
        if ($candidates === []) {
            continue;
        }

        usort($candidates, static function ($a, $b) {
            if ($b['score'] == $a['score']) {
                return 0;
            }
            return ($b['score'] < $a['score']) ? -1 : 1;
        });
        $pick = $candidates[0];
        $cases++;

        $evalRow = [
            'instrument_id' => $pick['instrument_id'],
            'scheme_id' => $pick['scheme_id'],
            'target_value' => $pick['target'],
            'base_value' => $pick['base'],
            'horizon_end_date' => $settingsAtDate['horizon_end_date'],
        ];
        $eval = prediction_evaluate_one($pdo, $evalRow);
        if ($eval['realized_return'] !== null) {
            $net = (float) $eval['realized_return'] - ((float) $settings['fee_percent'] + (float) $settings['slippage_percent']);
            $returns[] = $net;
            if ($net < 0) {
                $losses++;
            }
        }
        if ($eval['max_drawdown'] !== null) {
            $maxDrawdowns[] = (float) $eval['max_drawdown'];
        }
        if ($eval['status'] === 'target_reached') {
            $hits++;
        }
    }

    $summary = [
        'cases' => $cases,
        'target_hits' => $hits,
        'target_hit_rate' => $cases > 0 ? round(($hits / $cases) * 100, 2) : null,
        'average_return_percent' => $returns !== [] ? round(array_sum($returns) / count($returns), 4) : null,
        'negative_outcomes' => $losses,
        'worst_drawdown_percent' => $maxDrawdowns !== [] ? round(min($maxDrawdowns), 4) : null,
        'fees_slippage_assumed_percent' => (float) $settings['fee_percent'] + (float) $settings['slippage_percent'],
        'limitations' => $settings['limitations'],
        'historical_only' => true,
    ];

    $pdo->prepare(
        "UPDATE backtest_runs SET status = 'completed', summary_json = :summary WHERE id = :id"
    )->execute([
        'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
        'id' => $runId,
    ]);

    return $cases;
}

/**
 * Candidates using only data available on or before $asOfDate (no look-ahead).
 *
 * @param array<string,mixed> $settings
 * @return list<array<string,mixed>>
 */
function backtest_candidates_as_of(PDO $pdo, array $settings, string $asOfDate): array
{
    $asset = $settings['asset_type'];
    $minBars = (int) $settings['min_history_bars'];
    $targetPct = (float) $settings['target_percent'];
    $out = [];

    if ($asset === 'mutual_fund') {
        $schemes = $pdo->query('SELECT id, scheme_name FROM mutual_fund_schemes WHERE is_active = 1')->fetchAll();
        foreach ($schemes as $scheme) {
            $stmt = $pdo->prepare(
                'SELECT nav, nav_date FROM mutual_fund_nav
                 WHERE scheme_id = :id AND nav_date <= :asof ORDER BY nav_date ASC'
            );
            $stmt->execute(['id' => (int) $scheme['id'], 'asof' => $asOfDate]);
            $rows = $stmt->fetchAll();
            if (count($rows) < $minBars) {
                continue;
            }
            $navs = array_map(static function ($r) {
                return (float) $r['nav'];
            }, $rows);
            $scored = prediction_score_nav($navs, $targetPct);
            if ($scored['score'] <= 0) {
                continue;
            }
            $out[] = [
                'instrument_id' => null,
                'scheme_id' => (int) $scheme['id'],
                'score' => $scored['score'],
                'base' => $scored['base'],
                'target' => $scored['target'] ?? ($scored['base'] * (1 + $targetPct / 100)),
            ];
        }

        return $out;
    }

    $type = $asset === 'etf' ? 'etf' : 'stock';
    $stmt = $pdo->prepare(
        "SELECT id FROM instruments WHERE instrument_type = :type AND is_active = 1"
    );
    $stmt->execute(['type' => $type]);

    while ($inst = $stmt->fetch()) {
        $pstmt = $pdo->prepare(
            'SELECT close FROM daily_prices
             WHERE instrument_id = :id AND trade_date <= :asof ORDER BY trade_date ASC'
        );
        $pstmt->execute(['id' => (int) $inst['id'], 'asof' => $asOfDate]);
        $rows = $pstmt->fetchAll();
        if (count($rows) < $minBars) {
            continue;
        }
        $closes = array_map(static function ($r) {
            return (float) $r['close'];
        }, $rows);
        $scored = prediction_score_equity($closes, $targetPct);
        if ($scored['score'] <= 0) {
            continue;
        }
        $out[] = [
            'instrument_id' => (int) $inst['id'],
            'scheme_id' => null,
            'score' => $scored['score'],
            'base' => $scored['base'],
            'target' => $scored['target'] ?? ($scored['base'] * (1 + $targetPct / 100)),
        ];
    }

    return $out;
}

function backtest_mark_failed(PDO $pdo, int $runId, string $message): void
{
    $pdo->prepare(
        "UPDATE backtest_runs SET status = 'failed', summary_json = :summary WHERE id = :id"
    )->execute([
        'summary' => json_encode(['error' => $message], JSON_THROW_ON_ERROR),
        'id' => $runId,
    ]);
}
