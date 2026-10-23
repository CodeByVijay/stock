<?php

declare(strict_types=1);

/** Client-facing labels for sync_jobs.job_type */
const MARKET_DATA_JOB_LABELS = [
    'nse_equity_master' => 'NSE master import (CSV)',
    'bse_equity_master' => 'BSE master import (CSV)',
    'upstox_nse_stocks' => 'NSE master import',
    'upstox_bse_stocks' => 'BSE master import',
    'upstox_nse_etfs' => 'NSE ETF master import',
    'etf_master' => 'ETF master import (CSV)',
    'mf_scheme_master' => 'MF scheme registry',
    'mf_nav_backfill' => 'NAV historical backfill',
    'mf_nav_daily' => 'NAV daily refresh',
    'equity_ohlcv_csv' => 'OHLCV file import',
    'equity_daily_upstox' => 'Equity price refresh',
    'etf_daily_upstox' => 'ETF price refresh',
    'history_backfill' => 'OHLCV gap fill',
    'intraday' => 'Intraday refresh',
    'recalculate_indicators' => 'Indicator recalculation',
    'mutual_funds' => 'Mutual fund sync (legacy)',
    'stocks' => 'Equity sync (legacy)',
    'etfs' => 'ETF sync (legacy)',
    'sync_all_available' => 'Full pipeline orchestration',
    'sync_stocks' => 'Equity dataset refresh',
    'sync_etfs' => 'ETF dataset refresh',
    'sync_mutual_funds' => 'Mutual fund dataset refresh',
    'instrument_ohlcv_sync' => 'Single instrument price sync',
    'run_screener' => 'Screener run',
    'run_backtest' => 'Backtest run',
];

function market_data_job_label(string $jobType): string
{
    return MARKET_DATA_JOB_LABELS[$jobType] ?? 'Data job';
}

/** Plain-language labels for non-technical users (Data Pipeline UI). */
function market_data_job_label_simple(string $jobType): string
{
    $map = [
        'sync_stocks' => 'Update company shares',
        'sync_etfs' => 'Update ETFs',
        'sync_mutual_funds' => 'Update mutual funds',
        'instrument_ohlcv_sync' => 'Update one share price',
        'mf_scheme_master' => 'Download fund names',
        'mf_nav_backfill' => 'Full NAV history download',
        'mf_nav_daily' => 'Latest NAV refresh',
        'upstox_nse_stocks' => 'Download NSE share list',
        'upstox_bse_stocks' => 'Download BSE share list',
        'upstox_nse_etfs' => 'Download NSE ETF list',
        'history_backfill' => 'Fill missing price history',
        'equity_daily_upstox' => 'Latest share prices',
        'etf_daily_upstox' => 'Latest ETF prices',
    ];

    return $map[$jobType] ?? market_data_job_label($jobType);
}

function market_data_status_label(string $status): string
{
    $map = [
        'queued' => 'Waiting',
        'running' => 'Working…',
        'completed' => 'Done',
        'failed' => 'Problem',
    ];

    return $map[$status] ?? $status;
}
