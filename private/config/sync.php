<?php
/**
 * Sync job defaults — scheme codes and lookback windows for background jobs.
 */
return [
    /** Mutual fund scheme codes (MFapi.in) to sync when "Sync mutual funds" runs */
    'mf_scheme_codes' => [125497],
    /** Calendar days of NAV history to fetch per scheme */
    'mf_history_days' => 90,
    /** Calendar days of daily OHLCV to fetch per Upstox instrument */
    'upstox_history_days' => 90,
    /** Recent calendar days fetched for one-click Sync Stocks/ETFs (not full backfill) */
    'latest_market_data_days' => 14,
    /** Share/ETF price rows per queue job (keeps each run under host time limits). */
    'equity_price_batch' => 30,
    /** Enable intraday sync job (requires Upstox intraday access; off by default) */
    'intraday_enabled' => false,

    /**
     * Admin-panel background worker (Sync Center). Uses PHP CLI via proc_open when allowed.
     * Falls back to browser-driven api/worker-tick.php if spawn is unavailable.
     */
    'worker' => [
        /** Leave empty to auto-detect CLI; override in private/config/sync.local.php (e.g. /usr/local/bin/php). */
        'php_cli_binary' => '',
        'spawn_enabled' => true,
        'drain_max_loops' => 2000,
        'jobs_per_loop' => 1,
        /** Job lease (seconds) — long stock price loops extend this automatically. */
        'job_lease_seconds' => 900,
        /** After lease expires, reclaim stuck "running" rows so the queue can continue. */
        'stale_job_grace_seconds' => 120,
    ],
];
