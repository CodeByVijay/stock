-- Personal Stock Research Portal — Version 1 schema
-- Import once manually; the app does not auto-create tables on page load.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    pending_email VARCHAR(255) NULL,
    pending_email_token_hash VARCHAR(255) NULL,
    pending_email_expires_at DATETIME NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reset_user (admin_user_id),
    KEY idx_reset_expires (expires_at),
    CONSTRAINT fk_reset_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instruments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    symbol VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    exchange VARCHAR(32) NULL,
    instrument_type ENUM('stock', 'etf', 'other') NOT NULL DEFAULT 'stock',
    provider VARCHAR(64) NULL,
    provider_instrument_key VARCHAR(128) NULL,
    isin VARCHAR(20) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    listing_status ENUM('active','inactive','suspended','delisted','unknown') NOT NULL DEFAULT 'unknown',
    security_category VARCHAR(64) NULL,
    master_source VARCHAR(64) NULL,
    master_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_instruments_exchange_symbol (exchange, symbol),
    KEY idx_instruments_symbol (symbol),
    KEY idx_instruments_type_active (instrument_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS instrument_data_coverage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    earliest_trade_date DATE NULL,
    latest_trade_date DATE NULL,
    bar_count INT UNSIGNED NOT NULL DEFAULT 0,
    source VARCHAR(64) NULL,
    last_fetched_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_coverage_instrument (instrument_id),
    CONSTRAINT fk_coverage_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_prices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    open DECIMAL(18, 4) NULL,
    high DECIMAL(18, 4) NULL,
    low DECIMAL(18, 4) NULL,
    close DECIMAL(18, 4) NOT NULL,
    volume BIGINT UNSIGNED NULL,
    source VARCHAR(64) NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_daily_instrument_date (instrument_id, trade_date),
    KEY idx_daily_trade_date (trade_date),
    CONSTRAINT fk_daily_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intraday_prices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    interval_minutes SMALLINT UNSIGNED NOT NULL,
    candle_time DATETIME NOT NULL,
    open DECIMAL(18, 4) NULL,
    high DECIMAL(18, 4) NULL,
    low DECIMAL(18, 4) NULL,
    close DECIMAL(18, 4) NOT NULL,
    volume BIGINT UNSIGNED NULL,
    source VARCHAR(64) NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_intraday_candle (instrument_id, interval_minutes, candle_time),
    CONSTRAINT fk_intraday_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mutual_fund_schemes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scheme_code VARCHAR(32) NOT NULL,
    scheme_name VARCHAR(512) NOT NULL,
    fund_house VARCHAR(255) NULL,
    scheme_category VARCHAR(255) NULL,
    scheme_type VARCHAR(255) NULL,
    isin_growth VARCHAR(32) NULL,
    isin_div_reinvestment VARCHAR(32) NULL,
    meta_source VARCHAR(64) NULL,
    meta_synced_at DATETIME NULL,
    plan_option VARCHAR(128) NULL,
    master_source VARCHAR(64) NULL,
    master_synced_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mf_scheme_code (scheme_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mf_nav_coverage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scheme_id INT UNSIGNED NOT NULL,
    earliest_nav_date DATE NULL,
    latest_nav_date DATE NULL,
    nav_count INT UNSIGNED NOT NULL DEFAULT 0,
    source VARCHAR(64) NULL,
    last_fetched_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mf_coverage_scheme (scheme_id),
    CONSTRAINT fk_mf_coverage_scheme FOREIGN KEY (scheme_id) REFERENCES mutual_fund_schemes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mutual_fund_nav (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scheme_id INT UNSIGNED NOT NULL,
    nav_date DATE NOT NULL,
    nav DECIMAL(18, 6) NOT NULL,
    source VARCHAR(64) NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mf_nav_scheme_date (scheme_id, nav_date),
    CONSTRAINT fk_nav_scheme FOREIGN KEY (scheme_id) REFERENCES mutual_fund_schemes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watchlist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_watchlist_instrument (instrument_id),
    CONSTRAINT fk_watchlist_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS technical_indicators (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    timeframe VARCHAR(16) NOT NULL DEFAULT 'daily',
    calculated_at DATETIME NOT NULL,
    sma_20 DECIMAL(18, 6) NULL,
    sma_50 DECIMAL(18, 6) NULL,
    sma_200 DECIMAL(18, 6) NULL,
    ema_20 DECIMAL(18, 6) NULL,
    rsi_14 DECIMAL(10, 4) NULL,
    macd DECIMAL(18, 6) NULL,
    macd_signal DECIMAL(18, 6) NULL,
    volatility DECIMAL(18, 6) NULL,
    PRIMARY KEY (id),
    KEY idx_ti_instrument_time (instrument_id, calculated_at),
    CONSTRAINT fk_ti_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prediction_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_type ENUM('stock', 'etf', 'mutual_fund') NOT NULL,
    result_count INT UNSIGNED NOT NULL,
    target_percent DECIMAL(10, 4) NOT NULL,
    horizon_value INT UNSIGNED NOT NULL,
    horizon_unit ENUM('days', 'months', 'years') NOT NULL,
    universe_type ENUM('all', 'watchlist') NOT NULL DEFAULT 'all',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prediction_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    settings_snapshot_json JSON NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    error_message TEXT NULL,
    scan_stats_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_prediction_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prediction_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id INT UNSIGNED NOT NULL,
    instrument_id INT UNSIGNED NULL,
    scheme_id INT UNSIGNED NULL,
    rank_position INT UNSIGNED NOT NULL,
    base_value DECIMAL(18, 6) NOT NULL,
    target_value DECIMAL(18, 6) NOT NULL,
    technical_score DECIMAL(10, 4) NULL,
    risk_note TEXT NULL,
    prediction_date DATE NOT NULL,
    horizon_end_date DATE NOT NULL,
    data_timestamp DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_pr_run_rank (run_id, rank_position),
    CONSTRAINT fk_pr_run FOREIGN KEY (run_id) REFERENCES prediction_runs (id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_scheme FOREIGN KEY (scheme_id) REFERENCES mutual_fund_schemes (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prediction_outcomes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    prediction_result_id BIGINT UNSIGNED NOT NULL,
    evaluated_at DATETIME NOT NULL,
    outcome_status ENUM('target_reached', 'target_not_reached', 'not_evaluable') NOT NULL,
    realized_return_percent DECIMAL(10, 4) NULL,
    max_drawdown_percent DECIMAL(10, 4) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_po_result (prediction_result_id),
    CONSTRAINT fk_po_result FOREIGN KEY (prediction_result_id) REFERENCES prediction_results (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backtest_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    strategy_name VARCHAR(128) NOT NULL,
    asset_type ENUM('stock', 'etf', 'mutual_fund') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    horizon_value INT UNSIGNED NOT NULL,
    horizon_unit ENUM('days', 'months', 'years') NOT NULL,
    settings_json JSON NOT NULL,
    summary_json JSON NULL,
    status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_type VARCHAR(64) NOT NULL,
    payload_json JSON NULL,
    status ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    progress_total INT UNSIGNED NULL,
    progress_done INT UNSIGNED NULL,
    records_processed INT UNSIGNED NOT NULL DEFAULT 0,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    lock_until DATETIME NULL,
    error_message TEXT NULL,
    result_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_sync_jobs_status_type (status, job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    details_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_security_event_time (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
