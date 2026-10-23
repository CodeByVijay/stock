<?php
/**
 * Data provider paths and batch limits (no API secrets — those stay in upstox.php / mail.php).
 *
 * NSE/BSE: download official equity/ETF lists from exchange portals, save as CSV, set paths below.
 * @see private/docs/INSTRUMENT_SOURCES.md
 */
return [
    'nse_equity_csv' => '',
    'bse_equity_csv' => '',
    'etf_master_csv' => '',
    'equity_ohlcv_csv' => '',

    /** Upstox BOD JSON.gz (public CDN). Override URL or set local path if CDN is blocked on your server. */
    'upstox_instruments_nse_url' => 'https://assets.upstox.com/market-quote/instruments/exchange/NSE.json.gz',
    'upstox_instruments_bse_url' => 'https://assets.upstox.com/market-quote/instruments/exchange/BSE.json.gz',
    'upstox_instruments_nse_gz_path' => '',
    'upstox_instruments_bse_gz_path' => '',

    'mfapi_page_size' => 100,
    'mf_nav_backfill_batch' => 25,
    'mf_nav_backfill_days' => 365,
    'mf_nav_daily_days' => 14,
    'equity_backfill_batch' => 20,

    'coverage_claim_complete' => false,
];
