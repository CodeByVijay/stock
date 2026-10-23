<?php

declare(strict_types=1);

/** @return array{nav:float,nav_date:string,source:?string,fetched_at:?string}|null */
function mf_nav_latest(PDO $pdo, int $schemeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT nav, nav_date, source, fetched_at
         FROM mutual_fund_nav
         WHERE scheme_id = :id AND nav > 0
         ORDER BY nav_date DESC
         LIMIT 1'
    );
    $stmt->execute(['id' => $schemeId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'nav' => (float) $row['nav'],
        'nav_date' => (string) $row['nav_date'],
        'source' => $row['source'] !== null ? (string) $row['source'] : null,
        'fetched_at' => $row['fetched_at'] !== null ? (string) $row['fetched_at'] : null,
    ];
}

/** @return array{nav:float,nav_date:string}|null */
function mf_nav_on_or_before(PDO $pdo, int $schemeId, string $onOrBeforeDate): ?array
{
    $stmt = $pdo->prepare(
        'SELECT nav, nav_date
         FROM mutual_fund_nav
         WHERE scheme_id = :id AND nav > 0 AND nav_date <= :d
         ORDER BY nav_date DESC
         LIMIT 1'
    );
    $stmt->execute(['id' => $schemeId, 'd' => $onOrBeforeDate]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return ['nav' => (float) $row['nav'], 'nav_date' => (string) $row['nav_date']];
}

/** @return array{nav:float,nav_date:string}|null */
function mf_nav_earliest(PDO $pdo, int $schemeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT nav, nav_date
         FROM mutual_fund_nav
         WHERE scheme_id = :id AND nav > 0
         ORDER BY nav_date ASC
         LIMIT 1'
    );
    $stmt->execute(['id' => $schemeId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return ['nav' => (float) $row['nav'], 'nav_date' => (string) $row['nav_date']];
}

function mf_simple_return_percent(float $startNav, float $endNav): ?float
{
    if ($startNav <= 0 || $endNav <= 0) {
        return null;
    }

    return round((($endNav / $startNav) - 1) * 100, 4);
}

/**
 * @return array<string,mixed>
 */
function mf_period_return(PDO $pdo, int $schemeId, array $latest, int $months, string $label, string $key): array
{
    $endNav = (float) $latest['nav'];
    $endDate = (string) $latest['nav_date'];
    $latestDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    if (!$latestDt) {
        return mf_return_unavailable($key, $label, 'Invalid latest NAV date.');
    }

    $targetDate = $latestDt->modify('-' . $months . ' months')->format('Y-m-d');
    $start = mf_nav_on_or_before($pdo, $schemeId, $targetDate);
    if ($start === null) {
        $earliest = mf_nav_earliest($pdo, $schemeId);
        if ($earliest !== null && $earliest['nav_date'] > $targetDate) {
            return mf_return_unavailable(
                $key,
                $label,
                'Stored history begins ' . $earliest['nav_date'] . '; need NAV on or before ' . $targetDate . '.'
            );
        }

        return mf_return_unavailable($key, $label, 'No NAV on or before ' . $targetDate . '.');
    }

    if ($start['nav_date'] === $endDate && $months > 0) {
        return mf_return_unavailable($key, $label, 'Insufficient history for this period.');
    }

    $pct = mf_simple_return_percent($start['nav'], $endNav);

    return [
        'key' => $key,
        'label' => $label,
        'available' => $pct !== null,
        'percent' => $pct,
        'start_nav' => $start['nav'],
        'start_date' => $start['nav_date'],
        'end_nav' => $endNav,
        'end_date' => $endDate,
        'lookback_target' => $targetDate,
        'note' => 'Cumulative simple return (not annualized).',
    ];
}

/** @return array<string,mixed> */
function mf_return_unavailable(string $key, string $label, string $reason): array
{
    return [
        'key' => $key,
        'label' => $label,
        'available' => false,
        'percent' => null,
        'start_nav' => null,
        'start_date' => null,
        'end_nav' => null,
        'end_date' => null,
        'lookback_target' => null,
        'note' => $reason,
    ];
}

/** @return array<string,mixed> */
function mf_lifetime_return(PDO $pdo, int $schemeId, array $latest): array
{
    $start = mf_nav_earliest($pdo, $schemeId);
    if ($start === null) {
        return mf_return_unavailable('lifetime', 'Lifetime', 'No NAV history stored.');
    }

    $endNav = (float) $latest['nav'];
    $endDate = (string) $latest['nav_date'];
    if ($start['nav_date'] === $endDate) {
        return mf_return_unavailable('lifetime', 'Lifetime', 'Only one NAV point on record.');
    }

    $pct = mf_simple_return_percent($start['nav'], $endNav);

    return [
        'key' => 'lifetime',
        'label' => 'Lifetime',
        'available' => $pct !== null,
        'percent' => $pct,
        'start_nav' => $start['nav'],
        'start_date' => $start['nav_date'],
        'end_nav' => $endNav,
        'end_date' => $endDate,
        'lookback_target' => null,
        'note' => 'Return since earliest available NAV in your database (not necessarily scheme inception).',
    ];
}

/**
 * @return array{latest:?array<string,mixed>,periods:list<array<string,mixed>>}
 */
function mf_scheme_performance_returns(PDO $pdo, int $schemeId): array
{
    $latest = mf_nav_latest($pdo, $schemeId);
    if ($latest === null) {
        return ['latest' => null, 'periods' => []];
    }

    $periodDefs = [
        ['key' => '1m', 'label' => '1 Month', 'months' => 1],
        ['key' => '3m', 'label' => '3 Months', 'months' => 3],
        ['key' => '6m', 'label' => '6 Months', 'months' => 6],
        ['key' => '1y', 'label' => '1 Year', 'months' => 12],
        ['key' => '3y', 'label' => '3 Years', 'months' => 36],
    ];

    $periods = [];
    foreach ($periodDefs as $def) {
        $periods[] = mf_period_return($pdo, $schemeId, $latest, $def['months'], $def['label'], $def['key']);
    }
    $periods[] = mf_lifetime_return($pdo, $schemeId, $latest);

    return ['latest' => $latest, 'periods' => $periods];
}

function mf_format_return_percent(?float $pct): string
{
    if ($pct === null) {
        return 'N/A';
    }
    $sign = $pct >= 0 ? '+' : '−';
    $abs = number_format(abs($pct), 2);

    return $sign . $abs . '%';
}

function mf_return_css_class(?float $pct, bool $available): string
{
    if (!$available || $pct === null) {
        return 'return-pct return-pct--na';
    }
    if ($pct > 0) {
        return 'return-pct return-pct--pos';
    }
    if ($pct < 0) {
        return 'return-pct return-pct--neg';
    }

    return 'return-pct return-pct--na';
}
